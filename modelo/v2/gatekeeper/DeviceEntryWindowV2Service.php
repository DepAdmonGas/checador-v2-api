<?php
/**
 * Checador V2 — Device Entry Window Gatekeeper Domain Service (API-01)
 *
 * Implements pure read-only evaluation for:
 * GET /api/v2/device/workers/{external_worker_reference}/entry-window
 *
 * Specification: docs/v2/CHECADOR_V2_DEVICE_ENTRY_WINDOW_API_CONTRACT_V1.0.md and V1.1 (M11)
 * Architecture Addendum: docs/architecture/CHECADOR_V2_EARLY_ENTRY_GATEKEEPER_ADDENDUM_V1.0.md (APPROVED_FROZEN_V1.4)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';
require_once __DIR__ . '/../PunchEventV2.php';
require_once __DIR__ . '/../shift/ScheduleResolutionService.php';
require_once __DIR__ . '/../shift/WorkplaceTimezoneAuthorityService.php';
require_once __DIR__ . '/../WorkplacePhysicalStationResolver.php';
require_once __DIR__ . '/DeviceEntryPolicyV2.php';

class DeviceEntryWindowV2Service
{
    private mysqli $db;
    private ScheduleResolutionService $scheduleResolutionService;
    private WorkplaceTimezoneAuthorityService $timezoneAuthorityService;
    private PunchEventV2 $punchEventService;

    public function __construct(
        ?mysqli $db = null,
        ?ScheduleResolutionService $scheduleResolutionService = null,
        ?WorkplaceTimezoneAuthorityService $timezoneAuthorityService = null,
        ?PunchEventV2 $punchEventService = null
    ) {
        if ($db !== null) {
            $this->db = $db;
        } else {
            $bdObj = new BD();
            $this->db = $bdObj->conectarBD();
        }

        $this->scheduleResolutionService = $scheduleResolutionService ?? new ScheduleResolutionService($this->db);
        $this->timezoneAuthorityService = $timezoneAuthorityService ?? new WorkplaceTimezoneAuthorityService($this->db);
        $this->punchEventService = $punchEventService ?? new PunchEventV2();
    }

    /**
     * Pure read-only evaluation of perimeter gatekeeper decision.
     * Strictly ZERO writes to database or punch event queue.
     */
    public function evaluateEntryWindow(
        int $idDispositivo,
        int $idEstacion,
        ?string $deviceDefaultTimezone,
        string $externalWorkerRef,
        ?string $currentTimestampLocal = null,
        int $pendingLocalPunchesCount = 0,
        ?string $lastLocalPendingTimestamp = null,
        ?string $lastLocalPendingUuid = null,
        ?DateTimeImmutable $mockServerNow = null
    ): array {
        $workerRefTrimmed = trim($externalWorkerRef);
        if ($workerRefTrimmed === '' || strlen($workerRefTrimmed) > 64) {
            return [
                'success' => false,
                'code' => 400,
                'status' => 'INVALID_PAYLOAD',
                'message' => 'external_worker_reference es obligatorio y no debe exceder 64 caracteres.'
            ];
        }

        $serverNowUtc = ($mockServerNow ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
        $nowUtcStr = $serverNowUtc->format('Y-m-d H:i:s');

        // 1. Resolver identidad laboral para este dispositivo específico (Aislamiento de estación)
        $idPersonal = $this->punchEventService->resolverTrabajadorHistorico(
            $this->db,
            $idDispositivo,
            $workerRefTrimmed,
            $nowUtcStr
        );

        if ($idPersonal === null) {
            return [
                'success' => false,
                'code' => 404,
                'status' => 'WORKER_NOT_FOUND',
                'message' => 'Trabajador no enrolado o no encontrado para este dispositivo.'
            ];
        }

        // 1.1 Verificar pertenencia y autorización del trabajador para la estación del dispositivo (Aislamiento seguro)
        if (!$this->verifyWorkerStationBelonging($idPersonal, $idEstacion)) {
            return [
                'success' => false,
                'code' => 404,
                'status' => 'WORKER_NOT_FOUND',
                'message' => 'Trabajador no enrolado o no encontrado para este dispositivo.'
            ];
        }

        // 2. Resolver zona horaria IANA canónica de la localidad / estación
        $targetDateUtc = $serverNowUtc->format('Y-m-d');
        $canonicalTimezone = null;

        try {
            $workplaceId = $this->resolveCanonicalWorkplace($idEstacion);
            $canonicalTimezone = $this->timezoneAuthorityService->resolve($workplaceId, $targetDateUtc);
            $localDate = $serverNowUtc->setTimezone(new DateTimeZone($canonicalTimezone))->format('Y-m-d');
            $canonicalTimezone = $this->timezoneAuthorityService->resolve($workplaceId, $localDate);
            if ($serverNowUtc->setTimezone(new DateTimeZone($canonicalTimezone))->format('Y-m-d') !== $localDate) {
                throw new RuntimeException('ENTRY_TIMEZONE_UNRESOLVED');
            }
        } catch (Throwable $e) {
            // Fail-closed si la zona horaria no puede resolverse
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'SCHEDULE_UNKNOWN',
                    'is_entry_allowed' => false,
                    'should_record_punch' => false,
                    'inferred_direction' => 'UNKNOWN',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $workplaceId ?? null,
                    'fecha_local' => $targetDateUtc,
                    'timezone' => null,
                    'estado_jornada' => 'SCHEDULE_UNKNOWN',
                    'desktop_ui_message' => [
                        'header' => 'REGISTRO PROVISIONAL',
                        'detail' => 'HORARIO NO VERIFICABLE. ZONA HORARIA NO RESUELTA.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'ALERT_ERROR'
                    ]
                ]
            ];
        }

        $tz = new DateTimeZone($canonicalTimezone);
        $nowLocal = $serverNowUtc->setTimezone($tz);
        $fechaLocal = $nowLocal->format('Y-m-d');
        $serverTimeLocal = $nowLocal->format('Y-m-d H:i:s');
        $serverTimeOnly = $nowLocal->format('H:i:s');

        // 3. Detección de deriva horaria con el reloj del cliente (TC-12 Fail-closed)
        if ($currentTimestampLocal !== null && trim($currentTimestampLocal) !== '') {
            $clientTimeTrimmed = trim($currentTimestampLocal);
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $clientTimeTrimmed)) {
                return [
                    'success' => false,
                    'code' => 400,
                    'status' => 'INVALID_PARAMETER',
                    'message' => 'current_timestamp_local debe tener el formato YYYY-MM-DD HH:MM:SS.'
                ];
            }

            $clientTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $clientTimeTrimmed, $tz);
            if (!$clientTime || $clientTime->format('Y-m-d H:i:s') !== $clientTimeTrimmed) {
                return ['success' => false, 'code' => 400, 'status' => 'INVALID_PARAMETER', 'message' => 'current_timestamp_local inválido.'];
            }
            $clientTs = $clientTime->getTimestamp();
            $serverTs = $nowLocal->getTimestamp();
            $driftSeconds = abs($serverTs - $clientTs);

            if ($driftSeconds > 300) {
                return [
                    'success' => true,
                    'code' => 200,
                    'data' => [
                        'gatekeeper_action' => 'SCHEDULE_UNKNOWN',
                        'is_entry_allowed' => false,
                        'should_record_punch' => false,
                        'inferred_direction' => 'UNKNOWN',
                        'external_worker_reference' => $workerRefTrimmed,
                        'workplace_id' => $workplaceId ?? null,
                        'fecha_local' => $fechaLocal,
                        'timezone' => $canonicalTimezone,
                        'estado_jornada' => 'SCHEDULE_UNKNOWN',
                        'desktop_ui_message' => [
                            'header' => 'RELOJ DESFASADO',
                            'detail' => 'Reloj desfasado. Notifique a supervisor.',
                            'card_color' => 'RED',
                            'sound_alert' => 'ALERT_ERROR'
                        ]
                    ]
                ];
            }
        }

        // 4. Consultar horario efectivo del trabajador
        $schedule = null;
        try {
            $schedule = $this->scheduleResolutionService->resolveSchedule($idPersonal, $workplaceId, $fechaLocal);
        } catch (Throwable $ex) {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'SCHEDULE_UNKNOWN',
                    'is_entry_allowed' => false,
                    'should_record_punch' => false,
                    'inferred_direction' => 'UNKNOWN',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $workplaceId ?? null,
                    'fecha_local' => $fechaLocal,
                    'timezone' => $canonicalTimezone,
                    'estado_jornada' => 'SCHEDULE_UNKNOWN',
                    'desktop_ui_message' => [
                        'header' => 'REGISTRO PROVISIONAL',
                        'detail' => 'HORARIO NO VERIFICABLE. CONSULTE AL SUPERVISOR.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'ALERT_ERROR'
                    ]
                ]
            ];
        }

        $estadoJornada = $schedule['estado_jornada'] ?? 'UNKNOWN';

        // Resolve an individual work segment and an earlier still-open exit before
        // applying the early-entry restriction or returning a neutral no-schedule decision.
        try {
            $selection = $this->selectEntrySegment($idPersonal, $workplaceId, $idEstacion, $fechaLocal, $nowLocal, $schedule);
        } catch (Throwable $e) {
            return $this->unknownWindow($workerRefTrimmed, $workplaceId, $fechaLocal, $canonicalTimezone);
        }
        if ($selection !== null) {
            $estadoJornada = 'SCHEDULED';
        }

        // 5. Caso UNSCHEDULED (Decisión C)
        if ($estadoJornada === 'UNSCHEDULED') {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'ALLOW_UNSCHEDULED',
                    'is_entry_allowed' => true,
                    'should_record_punch' => true,
                    'inferred_direction' => 'ENTRY',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $workplaceId ?? null,
                    'fecha_local' => $fechaLocal,
                    'timezone' => $canonicalTimezone,
                    'estado_jornada' => 'UNSCHEDULED',
                    'scheduled_start_local' => null,
                    'scheduled_end_local' => null,
                    'earliest_allowed_entry_local' => null,
                    'server_time_local' => $serverTimeLocal,
                    'metadata_payload' => [
                        'schedule_status' => 'UNSCHEDULED'
                    ],
                    'desktop_ui_message' => [
                        'header' => 'MARCACIÓN REGISTRADA',
                        'detail' => 'SIN HORARIO ASIGNADO. PENDIENTE DE REVISIÓN POR RH.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'CONFIRMATION_NEUTRAL'
                    ]
                ]
            ];
        }

        // 6. Caso DAY_OFF (Decisión I Aprobada)
        if ($estadoJornada === 'DAY_OFF') {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'ALLOW_DAY_OFF',
                    'is_entry_allowed' => true,
                    'should_record_punch' => true,
                    'inferred_direction' => 'ENTRY',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $workplaceId ?? null,
                    'fecha_local' => $fechaLocal,
                    'timezone' => $canonicalTimezone,
                    'estado_jornada' => 'DAY_OFF',
                    'scheduled_start_local' => null,
                    'scheduled_end_local' => null,
                    'earliest_allowed_entry_local' => null,
                    'server_time_local' => $serverTimeLocal,
                    'metadata_payload' => [
                        'schedule_status' => 'DAY_OFF'
                    ],
                    'desktop_ui_message' => [
                        'header' => 'MARCACIÓN REGISTRADA',
                        'detail' => 'DÍA DE DESCANSO PROGRAMADO. PENDIENTE DE REVISIÓN POR RH.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'CONFIRMATION_NEUTRAL'
                    ]
                ]
            ];
        }

        // 7. Caso SCHEDULE_UNKNOWN / UNKNOWN
        if ($estadoJornada !== 'SCHEDULED') {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'SCHEDULE_UNKNOWN',
                    'is_entry_allowed' => false,
                    'should_record_punch' => false,
                    'inferred_direction' => 'UNKNOWN',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $workplaceId ?? null,
                    'fecha_local' => $fechaLocal,
                    'timezone' => $canonicalTimezone,
                    'estado_jornada' => 'SCHEDULE_UNKNOWN',
                    'desktop_ui_message' => [
                        'header' => 'REGISTRO PROVISIONAL',
                        'detail' => 'HORARIO NO VERIFICABLE. CONSULTE AL SUPERVISOR.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'ALERT_ERROR'
                    ]
                ]
            ];
        }

        // 8. Use the selected segment and its effective local-date policy.
        if ($selection === null) {
            return $this->unknownWindow($workerRefTrimmed, $workplaceId, $fechaLocal, $canonicalTimezone);
        }
        $targetFechaLocal = $selection['date'];
        $dtStart = $selection['start'];
        $dtEnd = $selection['end'];
        $dtEarliest = $selection['earliest'];
        $policy = $selection['policy'];
        $canonicalTimezone = $dtStart->getTimezone()->getName();
        $punchContext = $selection['punch_context'];

        if ($punchContext['has_workplace_mismatch'] || $punchContext['has_ambiguity']) {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'AMBIGUOUS_DIRECTION',
                    'is_entry_allowed' => false,
                    'should_record_punch' => false,
                    'inferred_direction' => 'AMBIGUOUS',
                    'external_worker_reference' => $workerRefTrimmed,
                    'desktop_ui_message' => [
                        'header' => 'VERIFICACIÓN REQUERIDA',
                        'detail' => 'No se pudo determinar si es Entrada o Salida. Consulte al supervisor.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'ALERT_ERROR'
                    ]
                ]
            ];
        }

        // An invalid temporal context must not turn into an authorized entry or exit.
        // Raw evidence before the allowed window is retained; the gatekeeper does not erase it.
        $utc = new DateTimeZone('UTC');
        foreach ($punchContext['punches'] as $event) {
            $eventTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)($event['ocurrido_at_utc'] ?? ''), $utc);
            if (!$eventTime || $eventTime->format('Y-m-d H:i:s') !== ($event['ocurrido_at_utc'] ?? '') || $eventTime > $serverNowUtc || $eventTime < $dtEarliest) {
                return $this->ambiguousWindow($workerRefTrimmed);
            }
        }
        $serverPunchesCount = count($punchContext['punches']);
        $clientPendingCount = max(0, $pendingLocalPunchesCount);

        // Validación de coherencia de pendientes locales
        if ($clientPendingCount > 0 && empty($lastLocalPendingTimestamp)) return $this->ambiguousWindow($workerRefTrimmed);
        if ($clientPendingCount > 0 && !empty($lastLocalPendingTimestamp)) {
            $pendingTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $lastLocalPendingTimestamp, $tz);
            $lastLocalTs = $pendingTime && $pendingTime->format('Y-m-d H:i:s') === $lastLocalPendingTimestamp ? $pendingTime->getTimestamp() : false;
            if ($lastLocalTs === false || $lastLocalTs > ($nowLocal->getTimestamp() + 300) || $lastLocalTs < $dtEarliest->getTimestamp() || $lastLocalTs > $dtEnd->modify('+4 hours')->getTimestamp()) {
                // Marca pendiente local futura incoherente -> Dirección ambigua
                return [
                    'success' => true,
                    'code' => 200,
                    'data' => [
                        'gatekeeper_action' => 'AMBIGUOUS_DIRECTION',
                        'is_entry_allowed' => false,
                        'should_record_punch' => false,
                        'inferred_direction' => 'AMBIGUOUS',
                        'external_worker_reference' => $workerRefTrimmed,
                        'desktop_ui_message' => [
                            'header' => 'VERIFICACIÓN REQUERIDA',
                            'detail' => 'No se pudo determinar si es Entrada o Salida. Consulte al supervisor.',
                            'card_color' => 'AMBER',
                            'sound_alert' => 'ALERT_ERROR'
                        ]
                    ]
                ];
            }
        }

        $totalEffectivePunches = $serverPunchesCount + $clientPendingCount;

        // V1.1 preserves another physical capture without inferring its role.
        // Ambiguous station, invalid events and invalid pending evidence have
        // already failed closed above; historical policies retain V1.0.
        $lastKnownTimestamp = null;
        foreach ($punchContext['punches'] as $event) {
            $timestamp = (new DateTimeImmutable($event['ocurrido_at_utc'], new DateTimeZone('UTC')))->getTimestamp();
            $lastKnownTimestamp = max($lastKnownTimestamp ?? $timestamp, $timestamp);
        }
        if ($clientPendingCount > 0 && isset($lastLocalTs) && $lastLocalTs !== false) $lastKnownTimestamp = max($lastKnownTimestamp ?? $lastLocalTs, $lastLocalTs);
        $repeatWindow = $policy['duplicate_punch_window_minutes'] ?? null;
        $repeat = $repeatWindow !== null && $lastKnownTimestamp !== null && $serverNowUtc->getTimestamp() >= $lastKnownTimestamp && $serverNowUtc->getTimestamp() - $lastKnownTimestamp <= $repeatWindow * 60;
        if ($repeatWindow !== null && $targetFechaLocal === $fechaLocal && ($totalEffectivePunches >= 2 || $repeat) && $nowLocal >= $dtEarliest) {
            return ['success'=>true, 'code'=>200, 'data'=>[
                'gatekeeper_action'=>'ALLOW_PENDING_CLASSIFICATION', 'is_entry_allowed'=>null,
                'should_record_punch'=>true, 'inferred_direction'=>null,
                'external_worker_reference'=>$workerRefTrimmed,
                'workplace_id'=>$workplaceId, 'fecha_local'=>$targetFechaLocal,
                'timezone'=>$canonicalTimezone, 'policy_version'=>$policy['policy_version'],
                'desktop_ui_message'=>['header'=>'MARCACIÓN REGISTRADA',
                    'detail'=>'Marcación pendiente de clasificación.', 'card_color'=>'GREEN', 'sound_alert'=>'CONFIRMATION_SUCCESS']
            ]];
        }

        // 10. Si ya existen 2 o más marcaciones en este segmento de turno -> Ambigüedad
        if ($totalEffectivePunches >= 2) {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'AMBIGUOUS_DIRECTION',
                    'is_entry_allowed' => false,
                    'should_record_punch' => false,
                    'inferred_direction' => 'AMBIGUOUS',
                    'external_worker_reference' => $workerRefTrimmed,
                    'desktop_ui_message' => [
                        'header' => 'VERIFICACIÓN REQUERIDA',
                        'detail' => 'No se pudo determinar si es Entrada o Salida. Consulte al supervisor.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'ALERT_ERROR'
                    ]
                ]
            ];
        }

        // 11. Si ya existe exactamente 1 marcación (entrada abierta) -> Autorizar SALIDA (TC-08, TC-11)
        if ($totalEffectivePunches === 1) {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'ALLOW_EXIT',
                    'is_entry_allowed' => true,
                    'should_record_punch' => true,
                    'inferred_direction' => 'EXIT',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $workplaceId ?? null,
                    'fecha_local' => $targetFechaLocal,
                    'timezone' => $canonicalTimezone,
                    'estado_jornada' => 'SCHEDULED',
                    'policy_version' => $policy['policy_version'],
                    'early_entry_allowed_minutes' => $policy['early_entry_allowed_minutes'],
                    'scheduled_start_local' => $dtStart->format('H:i:s'),
                    'scheduled_end_local' => $dtEnd->format('H:i:s'),
                    'earliest_allowed_entry_local' => null,
                    'server_time_local' => $serverTimeLocal,
                    'desktop_ui_message' => [
                        'header' => 'SALIDA REGISTRADA',
                        'detail' => 'Hora: ' . $serverTimeOnly . '. ¡Buen descanso!',
                        'card_color' => 'BLUE',
                        'sound_alert' => 'CONFIRMATION_SUCCESS'
                    ]
                ]
            ];
        }

        // 12. No existen marcaciones previas ($totalEffectivePunches === 0) -> Corresponde a ENTRADA
        // Aplicar la regla perimetral M11 efectiva del segmento seleccionado
        if ($nowLocal < $dtEarliest) {
            // Fuera de ventana -> REJECT_EARLY_ENTRY (TC-01, TC-09, TC-13)
            $secondsUntilAllowed = $dtEarliest->getTimestamp() - $nowLocal->getTimestamp();
            $minutesUntilAllowed = (int)ceil($secondsUntilAllowed / 60);

            $startFormatted = substr($dtStart->format('H:i:s'), 0, 5);
            $earliestFormatted = substr($dtEarliest->format('H:i:s'), 0, 5);

            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'REJECT_EARLY_ENTRY',
                    'is_entry_allowed' => false,
                    'should_record_punch' => false,
                    'inferred_direction' => 'ENTRY',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $workplaceId ?? null,
                    'fecha_local' => $targetFechaLocal,
                    'timezone' => $canonicalTimezone,
                    'estado_jornada' => 'SCHEDULED',
                    'policy_version' => $policy['policy_version'],
                    'early_entry_allowed_minutes' => $policy['early_entry_allowed_minutes'],
                    'scheduled_start_local' => $dtStart->format('H:i:s'),
                    'scheduled_end_local' => $dtEnd->format('H:i:s'),
                    'earliest_allowed_entry_local' => $dtEarliest->format('H:i:s'),
                    'server_time_local' => $serverTimeLocal,
                    'seconds_until_allowed' => $secondsUntilAllowed,
                    'minutes_until_allowed' => $minutesUntilAllowed,
                    'desktop_ui_message' => [
                        'header' => 'ENTRADA ANTICIPADA NO PERMITIDA',
                        'detail' => "Tu turno comienza a las {$startFormatted}. Podrás registrar tu entrada a partir de las {$earliestFormatted}.",
                        'card_color' => 'RED',
                        'sound_alert' => 'ALERT_WARNING'
                    ]
                ]
            ];
        }

        // Dentro de ventana o posterior al inicio -> ALLOW_ENTRY (TC-02, TC-03, TC-04)
        return [
            'success' => true,
            'code' => 200,
            'data' => [
                'gatekeeper_action' => 'ALLOW_ENTRY',
                'is_entry_allowed' => true,
                'should_record_punch' => true,
                'inferred_direction' => 'ENTRY',
                'external_worker_reference' => $workerRefTrimmed,
                'workplace_id' => $workplaceId ?? null,
                'fecha_local' => $targetFechaLocal,
                'timezone' => $canonicalTimezone,
                'estado_jornada' => 'SCHEDULED',
                'policy_version' => $policy['policy_version'],
                'early_entry_allowed_minutes' => $policy['early_entry_allowed_minutes'],
                'scheduled_start_local' => $dtStart->format('H:i:s'),
                'scheduled_end_local' => $dtEnd->format('H:i:s'),
                'earliest_allowed_entry_local' => $dtEarliest->format('H:i:s'),
                'server_time_local' => $serverTimeLocal,
                'seconds_until_allowed' => 0,
                'desktop_ui_message' => [
                    'header' => 'ENTRADA REGISTRADA',
                    'detail' => 'Hora: ' . $serverTimeOnly . '. ¡Bienvenido!',
                    'card_color' => 'GREEN',
                    'sound_alert' => 'CONFIRMATION_SUCCESS'
                ]
            ]
        ];
    }

    /**
     * Helper read-only para consultar marcaciones crudas candidatas dentro de una ventana temporal.
     */
    protected function fetchCandidatePunchesForWindow(
        int $idPersonal,
        int $idEstacion,
        string $windowStartUtc,
        string $windowEndUtc
    ): array {
        $stmt = $this->db->prepare(
            "SELECT id, id_dispositivo, id_estacion_resuelta, source_timestamp_raw,
                    ocurrido_at_utc, validation_flags
             FROM op_rh_v2_marcaciones_crudas
             WHERE id_personal_resuelto = ?
               AND ocurrido_at_utc BETWEEN ? AND ?
             ORDER BY ocurrido_at_utc ASC"
        );

        if (!$stmt) {
            throw new RuntimeException('ENTRY_PUNCH_READ_FAILED');
        }

        try {
            if (!$stmt->bind_param('iss', $idPersonal, $windowStartUtc, $windowEndUtc) || !$stmt->execute() || !($res = $stmt->get_result())) {
                throw new RuntimeException('ENTRY_PUNCH_READ_FAILED');
            }

            $punches = [];
            $hasWorkplaceMismatch = false;
            $hasAmbiguity = false;

            while ($row = $res->fetch_assoc()) {
                $punches[] = $row;
                if ((int)($row['id_estacion_resuelta'] ?? 0) !== $idEstacion) {
                    $hasWorkplaceMismatch = true;
                }
                if (!empty($row['validation_flags'])) {
                    $flags = (string)$row['validation_flags'];
                    if (strpos($flags, 'INVALID') !== false || strpos($flags, 'UNVERIFIABLE') !== false) {
                        $hasAmbiguity = true;
                    }
                }
            }
        } finally {
            $stmt->close();
        }

        return [
            'punches' => $punches,
            'has_workplace_mismatch' => $hasWorkplaceMismatch,
            'has_ambiguity' => $hasAmbiguity
        ];
    }

    protected function resolveCanonicalWorkplace(int $physicalStation): int
    {
        $workplace = WorkplacePhysicalStationResolver::resolveCanonicalLocality($this->db, $physicalStation);
        if ($workplace === null) throw new RuntimeException('ENTRY_WORKPLACE_UNRESOLVED');
        return $workplace;
    }

    protected function resolveEntryPolicy(int $workplace, string $date): array
    {
        return (new DeviceEntryPolicyV2($this->db))->resolve($workplace, $date);
    }

    private function ambiguousWindow(string $reference): array
    {
        return ['success'=>true, 'code'=>200, 'data'=>[
            'gatekeeper_action'=>'AMBIGUOUS_DIRECTION', 'is_entry_allowed'=>false,
            'should_record_punch'=>false, 'inferred_direction'=>'AMBIGUOUS',
            'external_worker_reference'=>$reference,
            'desktop_ui_message'=>['header'=>'VERIFICACIÓN REQUERIDA',
                'detail'=>'No se pudo determinar si es Entrada o Salida. Consulte al supervisor.',
                'card_color'=>'AMBER', 'sound_alert'=>'ALERT_ERROR']
        ]];
    }

    private function unknownWindow(string $reference, int $workplace, string $date, string $timezone): array
    {
        return ['success'=>true, 'code'=>200, 'data'=>[
            'gatekeeper_action'=>'SCHEDULE_UNKNOWN', 'is_entry_allowed'=>false,
            'should_record_punch'=>false, 'inferred_direction'=>'UNKNOWN',
            'external_worker_reference'=>$reference, 'workplace_id'=>$workplace,
            'fecha_local'=>$date, 'timezone'=>$timezone, 'estado_jornada'=>'SCHEDULE_UNKNOWN',
            'desktop_ui_message'=>['header'=>'REGISTRO PROVISIONAL',
                'detail'=>'HORARIO O POLÍTICA NO VERIFICABLE. CONSULTE AL SUPERVISOR.',
                'card_color'=>'AMBER', 'sound_alert'=>'ALERT_ERROR']
        ]];
    }

    /** Convert only WORK segments, preserving the logical date and effective timezone. */
    private function workSegments(array $schedule, string $date): array
    {
        $status = $schedule['estado_jornada'] ?? 'UNKNOWN';
        if (in_array($status, ['DAY_OFF', 'UNSCHEDULED'], true)) return [];
        if ($status !== 'SCHEDULED') throw new RuntimeException('ENTRY_SCHEDULE_UNRESOLVED');
        if (!isset($schedule['horario_local']['timezone'])) throw new RuntimeException('ENTRY_TIMEZONE_UNRESOLVED');
        $tz = new DateTimeZone($schedule['horario_local']['timezone']);
        $segments = [];
        foreach ($schedule['horario_local']['segmentos'] ?? [] as $segment) {
            $type = $segment['tipo_segmento'] ?? $segment['tipo'] ?? null;
            if ($type === 'BREAK') continue;
            if ($type !== 'WORK') throw new RuntimeException('ENTRY_SEGMENT_UNRESOLVED');
            $times = [];
            foreach (['inicio', 'fin'] as $edge) {
                $offset = filter_var($segment['dia_offset_' . $edge] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
                $time = $segment['hora_' . $edge . '_local'] ?? '';
                if (!in_array($offset, [0,1], true) || !preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d\z/', $time)) throw new RuntimeException('ENTRY_SEGMENT_UNRESOLVED');
                $day = (new DateTimeImmutable($date, $tz))->modify("+$offset days")->format('Y-m-d');
                $dt = new DateTimeImmutable("$day $time", $tz);
                if ($dt->format('Y-m-d H:i:s') !== "$day $time") throw new RuntimeException('ENTRY_SEGMENT_UNRESOLVED');
                $utcKey = $edge === 'inicio' ? 'scheduled_start_utc' : 'scheduled_end_utc';
                if (isset($segment[$utcKey]) && $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') !== $segment[$utcKey]) throw new RuntimeException('ENTRY_SEGMENT_UNRESOLVED');
                $times[] = $dt;
            }
            if ($times[1] <= $times[0]) throw new RuntimeException('ENTRY_SEGMENT_UNRESOLVED');
            $segments[] = ['date'=>$date, 'start'=>$times[0], 'end'=>$times[1]];
        }
        if (!$segments) throw new RuntimeException('ENTRY_SEGMENT_UNRESOLVED');
        usort($segments, static fn($a,$b) => $a['start'] <=> $b['start']);
        for ($i=1; $i<count($segments); $i++) if ($segments[$i]['start'] < $segments[$i-1]['end']) throw new RuntimeException('ENTRY_SEGMENT_OVERLAP');
        return $segments;
    }

    /**
     * Perimeter decision, not an authoritative pairing. A prior possible exit
     * must be considered before rejecting entry. Ambiguous evidence never means
     * an empty set of punches. No materialization or engine execution here.
     */
    private function selectEntrySegment(int $worker, int $workplace, int $physical, string $date, DateTimeImmutable $now, array $today): ?array
    {
        $utc = new DateTimeZone('UTC');
        $fmt = static fn(DateTimeImmutable $d) => $d->setTimezone($utc)->format('Y-m-d H:i:s');
        $segments = $this->workSegments($today, $date);
        $target = null;
        foreach ($segments as $segment) {
            $target = $segment;
            if ($now <= $segment['end']) break;
        }
        // Previous logical dates: offsets of one day plus a 4-hour tail can
        // cross a second midnight. Do not swallow resolution errors.
        $prior = [];
        for ($days=2; $days>=1; $days--) {
            $day = $now->modify("-$days days")->format('Y-m-d');
            $schedule = $this->scheduleResolutionService->resolveSchedule($worker, $workplace, $day);
            foreach ($this->workSegments($schedule, $day) as $seg) {
                if ($seg['start'] <= $now && $seg['end']->modify('+4 hours') >= $now) $prior[] = $seg;
            }
        }
        // Consider the next logical date before midnight, including DAY_OFF today.
        if ($now->format('H:i:s') >= '23:00:00' && ($target === null || $now > $target['end'])) {
            $day = $now->modify('+1 day')->format('Y-m-d');
            $schedule = $this->scheduleResolutionService->resolveSchedule($worker, $workplace, $day);
            $next = $this->workSegments($schedule, $day);
            if ($next && $next[0]['start'] <= $now->modify('+2 hours')) {
                $target = $next[0];
            }
        }
        foreach ($segments as $seg) {
            if ($target !== null && $seg['start'] < $target['start']) $prior[] = $seg;
        }
        $cache = [];
        $withPolicy = function(array $seg) use ($workplace, &$cache): array {
            $seg['policy'] = $cache[$seg['date']] ??= $this->resolveEntryPolicy($workplace, $seg['date']);
            $seg['earliest'] = $seg['start']->modify('-' . $seg['policy']['early_entry_allowed_minutes'] . ' minutes');
            // A possible exit is not proof of an entry. Historical policies
            // retain the legacy midpoint; V1.1 uses its own exit priority.
            $priority = $seg['policy']['exit_priority_minutes'];
            $threshold = $priority === null
                ? $seg['start']->modify('+' . intdiv($seg['end']->getTimestamp() - $seg['start']->getTimestamp(), 2) . ' seconds')
                : $seg['end']->modify('-' . $priority . ' minutes');
            $seg['exit_threshold'] = $threshold > $seg['start'] ? $threshold : $seg['start'];
            return $seg;
        };
        if ($target !== null) $target = $withPolicy($target);
        $prior = array_map($withPolicy, $prior);
        usort($prior, static fn($a,$b) => $a['start'] <=> $b['start']);
        $open = [];
        $previousEnd = null;
        $ambiguous = false;
        foreach ($prior as $i => $seg) {
            $next = $prior[$i+1] ?? $target;
            // Never assign an event to two independent segment counts.
            // Events at/after the next authorized start remain in its context;
            // an open prior segment plus those events is ambiguous.
            $from = $seg['start']->modify('-4 hours');
            if ($previousEnd !== null && $previousEnd >= $from) $from = $previousEnd->modify('+1 second');
            $to = $seg['end']->modify('+4 hours');
            if ($next !== null && $next['earliest'] <= $to) $to = $next['earliest']->modify('-1 second');
            if ($to < $from) throw new RuntimeException('ENTRY_SEGMENT_OVERLAP');
            if ($seg['end']->modify('+4 hours') < $now && ($target === null || $to < $target['start']->modify('-4 hours'))) continue;
            $ctx = $this->fetchCandidatePunchesForWindow($worker, $physical, $fmt($from), $fmt($to));
            if ($ctx['has_workplace_mismatch'] || $ctx['has_ambiguity']) $ambiguous = true;
            $count = count($ctx['punches']);
            if ($count === 1 && $seg['end']->modify('+4 hours') >= $now) {
                $event = $ctx['punches'][0]['ocurrido_at_utc'] ?? '';
                // A lone near-end event is not reliable proof of an entry.
                if ($event >= $fmt($seg['earliest']) && $event <= $fmt($now) && $event < $fmt($seg['exit_threshold'])) {
                    $seg['punch_context'] = $ctx;
                    $open[] = $seg;
                } else $ambiguous = true;
            } elseif ($count > 0) {
                // Only a clear pair in distinct portions of the segment can
                // be excluded from the next segment's count. Other sequences
                // remain ambiguous (including repeated entry captures).
                $times = array_column($ctx['punches'], 'ocurrido_at_utc'); sort($times, SORT_STRING);
                if ($count !== 2 || count($times) !== 2 || $times[0] < $fmt($seg['earliest']) || $times[0] >= $fmt($seg['exit_threshold']) || $times[1] < $fmt($seg['exit_threshold']) || $times[1] > $fmt($now)) $ambiguous = true;
            }
            if ($previousEnd === null || $to > $previousEnd) $previousEnd = $to;
        }
        if ($target === null) {
            if ($ambiguous || count($open) > 1) throw new RuntimeException('ENTRY_PREVIOUS_DIRECTION_UNRESOLVED');
            return $open[0] ?? null;
        }
        $from = $target['start']->modify('-4 hours');
        if ($previousEnd !== null && $previousEnd >= $from) $from = $previousEnd->modify('+1 second');
        $to = $target['end']->modify('+4 hours');
        if ($from > $to) throw new RuntimeException('ENTRY_SEGMENT_OVERLAP');
        $ctx = $this->fetchCandidatePunchesForWindow($worker, $physical, $fmt($from), $fmt($to));
        if (count($open) === 1 && !$ambiguous && !$ctx['has_workplace_mismatch'] && !$ctx['has_ambiguity'] && count($ctx['punches']) === 0) return $open[0];
        if ($ambiguous || count($open) > 0) $ctx['has_ambiguity'] = true;
        $target['punch_context'] = $ctx;
        return $target;
    }

    /**
     * Valida la pertenencia y estado activo del trabajador respecto a la estación autorizada.
     */
    protected function verifyWorkerStationBelonging(int $idPersonal, int $idEstacion): bool
    {
        $stmt = $this->db->prepare("SELECT id, id_estacion, estado FROM op_rh_personal WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $idPersonal);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row || (int)$row['estado'] !== 1 || (int)$row['id_estacion'] !== $idEstacion) {
            return false;
        }
        return true;
    }
}
