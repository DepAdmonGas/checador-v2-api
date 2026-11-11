<?php
/**
 * Checador V2 — JourneyEvaluationV2Controller
 * Server-to-server controller for On-Demand Journey Evaluation (P1/P2).
 *
 * SPECIFICATION: docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.0.md
 */

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';
require_once __DIR__ . '/../Portal3JwtAuthHelper.php';
require_once __DIR__ . '/../Level2ServerService.php';
require_once __DIR__ . '/../WorkplacePhysicalStationResolver.php';
require_once __DIR__ . '/../shift/ScheduleResolutionService.php';
require_once __DIR__ . '/../shift/WorkplaceCoverageService.php';
require_once __DIR__ . '/../shift/WorkplaceCoverageFeatureGate.php';
require_once __DIR__ . '/../shift/ScheduledShiftMaterializationService.php';
require_once __DIR__ . '/../shift/WorkplaceTimezoneAuthorityService.php';
require_once __DIR__ . '/JourneyPairingService.php';
require_once __DIR__ . '/JourneyCalculationService.php';

use modelo\v2\journey\JourneyCalculationService;

class JourneyEvaluationV2Controller
{
    /**
     * POST /api/v2/journeys/evaluate
     */
    public function handleEvaluateJourney(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        // 1. Authenticate Portal3 JWT
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($auth['status'], $auth['error'], $auth['code']);
            return;
        }

        // 2. Authorize biometricos.leer
        $authz = Portal3JwtAuthHelper::authorizeBiometricos($conexion, $auth, 'leer');
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
            return;
        }

        // 3. Content-Type and payload validation
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $rawInput = file_get_contents('php://input');

        if (!empty($rawInput) && stripos($contentType, 'application/json') === false) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('UNSUPPORTED_MEDIA_TYPE', 'Content-Type debe ser application/json.', 415);
            return;
        }

        $payload = json_decode($rawInput, true);
        if (!is_array($payload)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_EVALUATION_SPECIFICATION', 'JSON payload required.', 400);
            return;
        }

        $idPersonal = isset($payload['id_personal']) && is_numeric($payload['id_personal']) ? (int)$payload['id_personal'] : 0;
        $idEstacion = isset($payload['id_estacion']) && is_numeric($payload['id_estacion']) ? (int)$payload['id_estacion'] : 0;
        $fechaLocal = isset($payload['fecha_local']) && is_string($payload['fecha_local']) ? trim($payload['fecha_local']) : '';
        $snapshotAuditRef = isset($payload['snapshot_audit_ref']) && is_string($payload['snapshot_audit_ref']) ? trim($payload['snapshot_audit_ref']) : null;

        if ($idPersonal <= 0 || $idEstacion <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaLocal)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_EVALUATION_SPECIFICATION', 'id_personal, id_estacion, and valid YYYY-MM-DD fecha_local are required.', 400);
            return;
        }

        // Validate civil calendar date validity
        [$year, $month, $day] = explode('-', $fechaLocal);
        if (!checkdate((int)$month, (int)$day, (int)$year)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_EVALUATION_SPECIFICATION', 'Invalid calendar date.', 400);
            return;
        }

        // 4. Station scope validation
        if (!in_array($idEstacion, $authz['authorized_stations'], true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STATION_SCOPE_DENIED', "Station ID {$idEstacion} is outside authorized ACL scope.", 403);
            return;
        }

        // 5. Worker existence validation
        $stmtWorker = $conexion->prepare("SELECT id FROM op_rh_personal WHERE id = ? LIMIT 1");
        $stmtWorker->bind_param("i", $idPersonal);
        $stmtWorker->execute();
        $workerRes = $stmtWorker->get_result();
        $workerExists = ($workerRes && $workerRes->num_rows > 0);
        $stmtWorker->close();

        if (!$workerExists) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('WORKER_NOT_FOUND', "Worker ID {$idPersonal} not found.", 404);
            return;
        }

        // 6. Resolve the approved coverage-aware schedule only behind the opt-in gate.
        // The ordinary Journey contract remains unchanged while coverage is disabled.
        // When enabled, API V2 validates home membership, coverage approval, actor scope,
        // and that the caller's station is the worker's effective Workplace for the date.
        $scheduleService = new ScheduleResolutionService($conexion);
        if (WorkplaceCoverageFeatureGate::isEnabled()) {
            try {
                $scheduleResult = (new WorkplaceCoverageService($conexion))->resolveWorkerEffective(
                    $idPersonal,
                    $fechaLocal,
                    $auth
                );
            } catch (Throwable $e) {
                $code = $e->getMessage();
                $classBD->desconectarBD($conexion);
                if (in_array($code, ['FUNCTION_PERMISSION_DENIED', 'STATION_SCOPE_DENIED'], true)) {
                    $this->sendJsonError($code, 'Coverage schedule is outside authorized scope.', 403);
                }
                $this->sendJsonError('EFFECTIVE_SCHEDULE_UNAVAILABLE', 'Unable to resolve the authorized effective schedule.', 503);
                return;
            }
            $effectiveStatus = (string)($scheduleResult['estado_jornada'] ?? 'UNKNOWN');
            $effectiveWorkplaceId = (int)($scheduleResult['effective_workplace_id'] ?? $scheduleResult['id_estacion'] ?? 0);
            if ($effectiveStatus !== 'UNKNOWN' && $effectiveWorkplaceId < 1) {
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('EFFECTIVE_SCHEDULE_UNAVAILABLE', 'The effective Workplace could not be verified.', 503);
                return;
            }
            if ($effectiveWorkplaceId > 0 && $effectiveWorkplaceId !== $idEstacion) {
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('EFFECTIVE_WORKPLACE_MISMATCH', 'Requested station does not match the worker effective Workplace for this date.', 409);
                return;
            }
        } else {
            try {
                $scheduleResult = $scheduleService->resolveSchedule($idPersonal, $idEstacion, $fechaLocal);
            } catch (Throwable $e) {
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar horario efectivo.', 500);
                return;
            }
        }

        // 7. Workplace Timezone resolution
        $tzAuthority = new WorkplaceTimezoneAuthorityService($conexion);
        try {
            $workplaceTz = $tzAuthority->resolve($idEstacion, $fechaLocal);
        } catch (Throwable $e) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('WORKPLACE_TIMEZONE_UNRESOLVED', "Timezone mapping unresolved for workplace {$idEstacion} on {$fechaLocal}.", 404);
            return;
        }

        // Compute local workday UTC boundaries
        try {
            $tzObj = new DateTimeZone($workplaceTz);
            $startLocal = new DateTimeImmutable("{$fechaLocal} 00:00:00", $tzObj);
            $endLocal = new DateTimeImmutable("{$fechaLocal} 00:00:00 +1 day", $tzObj);
            $utcTz = new DateTimeZone('UTC');
            $fromUtc = $startLocal->setTimezone($utcTz)->format('Y-m-d H:i:s');
            $toUtc = $endLocal->setTimezone($utcTz)->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al resolver huso horario del centro de trabajo.', 500);
            return;
        }

        $estadoJornada = $scheduleResult['estado_jornada'] ?? 'UNKNOWN';

        // 8. Handle non-evaluable schedule states (UNSCHEDULED / UNKNOWN)
        if ($estadoJornada === 'UNSCHEDULED') {
            $classBD->desconectarBD($conexion);
            $this->sendJsonSuccess([
                'evaluated' => false,
                'journey_status' => 'UNSCHEDULED_DAY',
                'context' => [
                    'id_personal' => $idPersonal,
                    'id_estacion' => $idEstacion,
                    'fecha_local' => $fechaLocal,
                    'timezone' => $workplaceTz
                ],
                'schedule' => [
                    'estado_jornada' => 'UNSCHEDULED',
                    'scheduled_shift_id' => null,
                    'reason' => $scheduleResult['reason'] ?? 'Worker has no active assignments'
                ],
                'source_evidence' => [
                    'snapshot_audit_ref' => $snapshotAuditRef,
                    'window_level2_status' => 'UNKNOWN',
                    'level2_evaluation_ref' => null
                ],
                'pairing' => null,
                'calculation' => null,
                'evaluated_raw_event_count' => null,
                'evaluated_raw_events' => null,
                'provenance' => [
                    'response_generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                    'p1_created_at_utc' => null,
                    'p2_created_at_utc' => null,
                    'p1_algorithm_version' => 'v2.4-p1',
                    'p2_algorithm_version' => 'v2.4-p2',
                    'shift_template_version' => null,
                    'source_completeness_contract_version' => 'v2.4',
                    'level2_policy_ref' => null,
                    'policy_rule_version' => null,
                    'evaluated_raw_event_count' => null,
                    'evaluated_raw_events' => null
                ]
            ], 200);
            return;
        }

        if ($estadoJornada === 'UNKNOWN') {
            $classBD->desconectarBD($conexion);
            $this->sendJsonSuccess([
                'evaluated' => false,
                'journey_status' => 'UNKNOWN_SCHEDULE',
                'context' => [
                    'id_personal' => $idPersonal,
                    'id_estacion' => $idEstacion,
                    'fecha_local' => $fechaLocal,
                    'timezone' => $workplaceTz
                ],
                'schedule' => [
                    'estado_jornada' => 'UNKNOWN',
                    'scheduled_shift_id' => null,
                    'reason' => $scheduleResult['reason'] ?? 'Unknown schedule state'
                ],
                'source_evidence' => [
                    'snapshot_audit_ref' => $snapshotAuditRef,
                    'window_level2_status' => 'UNKNOWN',
                    'level2_evaluation_ref' => null
                ],
                'pairing' => null,
                'calculation' => null,
                'evaluated_raw_event_count' => null,
                'evaluated_raw_events' => null,
                'provenance' => [
                    'response_generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                    'p1_created_at_utc' => null,
                    'p2_created_at_utc' => null,
                    'p1_algorithm_version' => 'v2.4-p1',
                    'p2_algorithm_version' => 'v2.4-p2',
                    'shift_template_version' => null,
                    'source_completeness_contract_version' => 'v2.4',
                    'level2_policy_ref' => null,
                    'policy_rule_version' => null,
                    'evaluated_raw_event_count' => null,
                    'evaluated_raw_events' => null
                ]
            ], 200);
            return;
        }

        // 9. Evaluate / retrieve Level 2 Window Completeness
        // API requests and schedules use canonical locality IDs, while the
        // Level 2 device/evidence tables use physical tb_estaciones IDs.
        $physicalStationId = WorkplacePhysicalStationResolver::resolve($conexion, $idEstacion);
        $l2Service = new Level2ServerService();
        try {
            $l2Result = $physicalStationId === null
                ? ['window_level2_status' => 'UNKNOWN', 'evaluation_ref' => null]
                : $l2Service->evaluarVentanaNivel2($conexion, $physicalStationId, $fromUtc, $toUtc);
        } catch (Throwable $e) {
            $l2Result = [
                'window_level2_status' => 'UNKNOWN',
                'evaluation_ref' => null
            ];
        }

        // 10. Materialize ScheduledShift for SCHEDULED or DAY_OFF
        $matService = new ScheduledShiftMaterializationService($conexion);
        try {
            $matResult = $matService->materializeSingleDay($idPersonal, $idEstacion, $fechaLocal);
            $shiftId = (int)$matResult['id'];
        } catch (Throwable $e) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al materializar jornada programada.', 500);
            return;
        }

        // 11. Execute P1 Pairing
        $pairingService = new JourneyPairingService($conexion);
        try {
            $pairingResult = $pairingService->pairScheduledShift($shiftId);
        } catch (Throwable $e) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al ejecutar pareo de jornada (P1).', 500);
            return;
        }

        $pairingId = (int)$pairingResult['pairing_id'];

        // 12. Execute P2 Calculation
        $calcService = new JourneyCalculationService($conexion);
        try {
            $calcResult = $calcService->calculatePairingRevision($pairingId);
        } catch (Throwable $e) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al calcular jornada (P2).', 500);
            return;
        }

        // Fetch UUID map for segments enrichment
        $uuidMap = [];
        $mapStmt = $conexion->prepare(
            "SELECT m.id, m.event_uuid
             FROM op_rh_v2_pareos_marcaciones_asociadas a
             INNER JOIN op_rh_v2_marcaciones_crudas m ON a.id_marcacion_cruda = m.id
             WHERE a.id_pareo_jornada = ?"
        );
        if ($mapStmt) {
            $mapStmt->bind_param('i', $pairingId);
            $mapStmt->execute();
            $mapRes = $mapStmt->get_result();
            if ($mapRes) {
                while ($mRow = $mapRes->fetch_assoc()) {
                    $uuidMap[(int)$mRow['id']] = (string)$mRow['event_uuid'];
                }
            }
            $mapStmt->close();
        }

        // Fetch evaluated candidates if not already present in pairingResult
        $evaluatedCandidates = $pairingResult['evaluated_candidates'] ?? $pairingService->getEvaluatedCandidatesForPairing($pairingId);

        $classBD->desconectarBD($conexion);

        // Enrich segments with public UUIDs
        $enrichedSegments = [];
        foreach ($pairingResult['segments'] ?? [] as $seg) {
            $entryPunchId = $seg['entry_punch_id'] ?? null;
            $exitPunchId = $seg['exit_punch_id'] ?? null;
            $seg['entry_event_uuid'] = ($entryPunchId !== null && isset($uuidMap[$entryPunchId])) ? $uuidMap[$entryPunchId] : null;
            $seg['exit_event_uuid'] = ($exitPunchId !== null && isset($uuidMap[$exitPunchId])) ? $uuidMap[$exitPunchId] : null;
            $enrichedSegments[] = $seg;
        }

        $pairingCreatedAt = $pairingResult['creado_at_utc'] ?? null;
        $calcCreatedAt = $calcResult['creado_at_utc'] ?? null;
        $evalCount = count($evaluatedCandidates);

        // 13. Assemble and return canonical DTO
        $responsePayload = [
            'evaluated' => true,
            'journey_status' => 'EVALUATED',
            'context' => [
                'id_personal' => $idPersonal,
                'id_estacion' => $idEstacion,
                'fecha_local' => $fechaLocal,
                'timezone' => $workplaceTz
            ],
            'schedule' => [
                'estado_jornada' => $estadoJornada,
                'scheduled_shift_id' => $shiftId,
                'origen' => $scheduleResult['origen'] ?? null,
                'horario_local' => $scheduleResult['horario_local'] ?? null,
                'horario_utc' => $scheduleResult['horario_utc'] ?? null
            ],
            'source_evidence' => [
                'snapshot_audit_ref' => $snapshotAuditRef,
                'window_level2_status' => $l2Result['window_level2_status'] ?? 'UNKNOWN',
                'level2_evaluation_ref' => $l2Result['evaluation_ref'] ?? null
            ],
            'pairing' => [
                'pairing_id' => $pairingResult['pairing_id'],
                'revision_numero' => (int)$pairingResult['revision_numero'],
                'creado_at_utc' => $pairingCreatedAt,
                'pairing_status' => $pairingResult['pairing_status'],
                'completeness_l2' => $pairingResult['completeness_l2'],
                'algorithm_version' => $pairingResult['algorithm_version'],
                'fingerprint' => $pairingResult['fingerprint'],
                'segments' => $enrichedSegments,
                'is_idempotent_reuse' => $pairingResult['is_idempotent_reuse'] ?? false
            ],
            'calculation' => [
                'calculation_id' => (int)$calcResult['id'],
                'revision_numero' => (int)$calcResult['revision_numero'],
                'creado_at_utc' => $calcCreatedAt,
                'estado_calculo' => $calcResult['estado_calculo'],
                'version_algoritmo' => $calcResult['version_algoritmo_calculo'],
                'segundos_programados_totales' => $calcResult['segundos_programados_totales'] !== null ? (int)$calcResult['segundos_programados_totales'] : null,
                'segundos_trabajados_totales' => $calcResult['segundos_trabajados_totales'] !== null ? (int)$calcResult['segundos_trabajados_totales'] : null,
                'segundos_candidatos_fuera_horario' => (int)$calcResult['segundos_candidatos_fuera_horario'],
                'segmentos' => $calcResult['segmentos'] ?? []
            ],
            'evaluated_raw_event_count' => $evalCount,
            'evaluated_raw_events' => $evaluatedCandidates,
            'provenance' => [
                'response_generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'p1_created_at_utc' => $pairingCreatedAt,
                'p2_created_at_utc' => $calcCreatedAt,
                'p1_algorithm_version' => $pairingResult['algorithm_version'] ?? 'v2.4-p1',
                'p2_algorithm_version' => $calcResult['version_algoritmo_calculo'] ?? 'v2.4-p2',
                'shift_template_version' => isset($scheduleResult['origen']['version_plantilla']) ? (int)$scheduleResult['origen']['version_plantilla'] : null,
                'source_completeness_contract_version' => 'v2.4',
                'level2_policy_ref' => $l2Result['coverage_policy_ref'] ?? 'POLICY_DEFAULT',
                'policy_rule_version' => null,
                'evaluated_raw_event_count' => $evalCount,
                'evaluated_raw_events' => $evaluatedCandidates
            ]
        ];

        $this->sendJsonSuccess($responsePayload, 200);
    }

    private function sendJsonSuccess(array $data, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        echo json_encode([
            'status' => 'success',
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function sendJsonError(string $code, string $message, int $httpCode): void
    {
        http_response_code($httpCode);
        echo json_encode([
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
