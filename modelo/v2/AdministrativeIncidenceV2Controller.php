<?php
/**
 * Checador V2 — AdministrativeIncidenceV2Controller
 * Server-to-server controller for P3-G Administrative Incidences & Evaluation Read Boundary.
 *
 * SPECIFICATION: docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md
 * ARCHITECTURE: Option B (Canonical V2 domain; read-only legacy adapters; zero legacy dual-write; no automatic FALTA)
 */

declare(strict_types=1);

require_once __DIR__ . '/../BD.php';
require_once __DIR__ . '/Portal3JwtAuthHelper.php';
require_once __DIR__ . '/journey/AdministrativeEvaluationService.php';
require_once __DIR__ . '/WorkplacePhysicalStationResolver.php';
require_once __DIR__ . '/shift/ShiftWorkerMembershipService.php';
require_once __DIR__ . '/shift/WorkplaceTimezoneAuthorityService.php';
require_once __DIR__ . '/PunchIncidenceDeadlineService.php';

class AdministrativeIncidenceV2Controller
{
    /**
     * Strips internal metadata (such as idempotency tokens) from stored reason text.
     */
    public static function extractCleanReason(?string $rawMotivo): ?string
    {
        if ($rawMotivo === null) {
            return null;
        }
        return preg_replace('/\n<!-- IDEMP:.*? -->$/s', '', $rawMotivo);
    }

    /**
     * Extracts an HTTP header value case-insensitively across SAPI environments.
     */
    private function getHeaderValue(string $headerName): string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        if (!empty($_SERVER[$serverKey])) {
            return trim((string)$_SERVER[$serverKey]);
        }
        $fn = function_exists('getallheaders') ? 'getallheaders' : (function_exists('apache_request_headers') ? 'apache_request_headers' : null);
        if ($fn !== null) {
            $headers = $fn();
            if (is_array($headers)) {
                foreach ($headers as $k => $v) {
                    if (strcasecmp($k, $headerName) === 0) {
                        return trim((string)$v);
                    }
                }
            }
        }
        return '';
    }

    private static function isCivilDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
    /**
     * GET /api/v2/incidences
     * Read applicable V2 administrative incidences for worker + workplace + local date range.
     */
    public function handleReadApplicableIncidences(): void
    {
        $this->setSecurityHeaders();

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

        // 2. Authorize dedicated ACL: checador-v2-incidencias.leer
        $authz = Portal3JwtAuthHelper::authorizeIncidences($conexion, $auth, 'leer');
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
            return;
        }

        $authorizedStations = $authz['authorized_stations'] ?? [];
        if (empty($authorizedStations)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STATION_SCOPE_DENIED', 'Sin estaciones autorizadas para su perfil en este módulo.', 403);
            return;
        }

        // 3. Validate query parameters
        $workerIdRaw = $_GET['worker_id'] ?? null;
        if ($workerIdRaw === null || !ctype_digit((string)$workerIdRaw) || (int)$workerIdRaw <= 0) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Parámetro 'worker_id' es requerido y debe ser un entero positivo.", 400);
            return;
        }
        $workerId = (int)$workerIdRaw;

        // Canonical worker resolution against op_rh_personal
        $stmtWorker = $conexion->prepare("SELECT id FROM op_rh_personal WHERE id = ? LIMIT 1");
        if (!$stmtWorker) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar trabajador.', 500);
            return;
        }
        $stmtWorker->bind_param("i", $workerId);
        $stmtWorker->execute();
        $resWorker = $stmtWorker->get_result();
        $workerExists = ($resWorker && $resWorker->num_rows > 0);
        $stmtWorker->close();

        if (!$workerExists) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('WORKER_NOT_FOUND', "Trabajador con ID {$workerId} no encontrado en el sistema canónico.", 404);
            return;
        }

        $from = trim((string)($_GET['from'] ?? ''));
        $to = trim((string)($_GET['to'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Parámetro 'from' es requerido en formato YYYY-MM-DD.", 400);
            return;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Parámetro 'to' es requerido en formato YYYY-MM-DD.", 400);
            return;
        }
        if ($to < $from) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_DATE_RANGE', "Rango de fechas inválido: 'to' ({$to}) no puede ser anterior a 'from' ({$from}).", 422);
            return;
        }

        $workplaceIdRaw = $_GET['workplace_id'] ?? ($_GET['station_id'] ?? null);
        $workplaceId = null;
        if ($workplaceIdRaw !== null && trim((string)$workplaceIdRaw) !== '') {
            if (!ctype_digit((string)$workplaceIdRaw) || (int)$workplaceIdRaw <= 0) {
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('INVALID_PAYLOAD', "Parámetro 'workplace_id'/'station_id' debe ser un entero positivo.", 400);
                return;
            }
            $workplaceId = (int)$workplaceIdRaw;
            if (!in_array($workplaceId, $authorizedStations, true)) {
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('STATION_SCOPE_DENIED', "La estación solicitada ({$workplaceId}) no está dentro del alcance autorizado del usuario.", 403);
                return;
            }
        }

        $category = isset($_GET['category']) ? trim((string)$_GET['category']) : null;
        $status = isset($_GET['status']) ? trim((string)$_GET['status']) : null;

        // 4. Query canonical V2 incidences from op_rh_v2_incidencias_maestro
        $where = "WHERE m.id_personal = ? AND m.fecha_inicio_local <= ? AND m.fecha_fin_local >= ?";
        $params = [$workerId, $to, $from];
        $types = "iss";

        if ($workplaceId !== null) {
            $where .= " AND (m.id_estacion = ? OR m.id_estacion IS NULL)";
            $params[] = $workplaceId;
            $types .= "i";
        } else {
            $placeholders = implode(',', array_fill(0, count($authorizedStations), '?'));
            $where .= " AND (m.id_estacion IN ({$placeholders}) OR m.id_estacion IS NULL)";
            foreach ($authorizedStations as $stId) {
                $params[] = (int)$stId;
                $types .= "i";
            }
        }

        if ($category !== null && $category !== '') {
            $where .= " AND m.tipo_incidencia = ?";
            $params[] = $category;
            $types .= "s";
        }

        if ($status !== null && $status !== '') {
            $where .= " AND m.estado_actual = ?";
            $params[] = $status;
            $types .= "s";
        }

        $sql = "SELECT m.id AS master_id, m.uuid_incidencia, m.id_personal, m.id_estacion, m.tipo_incidencia,
                       m.alcance_incidencia, m.fecha_inicio_local, m.fecha_fin_local,
                       m.estado_actual, m.revision_actual,
                       r.motivo, r.folio_evidencia, r.creado_por_usuario_id, r.aprobado_por_usuario_id,
                       r.creado_at_utc
                FROM op_rh_v2_incidencias_maestro m
                LEFT JOIN op_rh_v2_incidencias_revisiones r
                  ON r.id_incidencia_maestro = m.id AND r.revision_numero = m.revision_actual
                {$where}
                ORDER BY m.fecha_inicio_local ASC, m.id ASC";

        $stmt = $conexion->prepare($sql);
        if (!$stmt) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar incidencias.', 500);
            return;
        }

        $bindArgs = [$types];
        foreach ($params as &$val) {
            $bindArgs[] = &$val;
        }
        unset($val);
        call_user_func_array([$stmt, 'bind_param'], $bindArgs);
        $stmt->execute();
        $result = $stmt->get_result();

        $incidencesList = [];
        while ($row = $result->fetch_assoc()) {
            $cat = (string)$row['tipo_incidencia'];
            $sourceClass = in_array($cat, ['VACATION', 'PERMISSION'], true) ? 'READ_ONLY_ADAPTER' : 'V2_CANONICAL';

            $incidencesList[] = [
                'uuid_incidencia' => (string)$row['uuid_incidencia'],
                'worker_id' => (int)$row['id_personal'],
                'workplace_id' => $row['id_estacion'] !== null ? (int)$row['id_estacion'] : null,
                'category' => $cat,
                'scope' => (string)$row['alcance_incidencia'],
                'date_range' => [
                    'start_date' => (string)$row['fecha_inicio_local'],
                    'end_date' => (string)$row['fecha_fin_local']
                ],
                'time_interval' => null,
                'current_status' => (string)$row['estado_actual'],
                'current_revision' => (int)$row['revision_actual'],
                'reason' => self::extractCleanReason($row['motivo']),
                'evidence_reference' => $row['folio_evidencia'] !== null ? (string)$row['folio_evidencia'] : null,
                'source_classification' => $sourceClass,
                'created_at_utc' => $row['creado_at_utc'] !== null ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$row['creado_at_utc'])) : null,
                'created_by_user_id' => (int)($row['creado_por_usuario_id'] ?? 0),
                'approved_by_user_id' => $row['aprobado_por_usuario_id'] !== null ? (int)$row['aprobado_por_usuario_id'] : null,
                'check_reference' => $this->readPunchCheckReference($conexion, (int)$row['master_id'])
            ];
        }
        $stmt->close();
        $classBD->desconectarBD($conexion);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'worker_id' => $workerId,
                'workplace_id' => $workplaceId,
                'query_range' => [
                    'from' => $from,
                    'to' => $to
                ],
                'total_records' => count($incidencesList),
                'incidences' => $incidencesList
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /api/v2/incidences/{uuid_incidencia}
     * Read one incident and its complete append-only revision history.
     */
    public function handleReadSingleIncident(string $uuidIncidencia): void
    {
        $this->setSecurityHeaders();

        $uuidIncidencia = trim($uuidIncidencia);
        if ($uuidIncidencia === '') {
            $this->sendJsonError('INVALID_PAYLOAD', 'Identificador de incidencia ausente.', 400);
            return;
        }

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

        // 2. Authorize dedicated ACL: checador-v2-incidencias.leer
        $authz = Portal3JwtAuthHelper::authorizeIncidences($conexion, $auth, 'leer');
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
            return;
        }

        $authorizedStations = $authz['authorized_stations'] ?? [];
        if (empty($authorizedStations)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STATION_SCOPE_DENIED', 'Sin estaciones autorizadas para su perfil en este módulo.', 403);
            return;
        }

        // 3. Query incident master
        $stmtMaster = $conexion->prepare("SELECT id, uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual FROM op_rh_v2_incidencias_maestro WHERE uuid_incidencia = ? LIMIT 1");
        if (!$stmtMaster) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar incidencia.', 500);
            return;
        }
        $stmtMaster->bind_param("s", $uuidIncidencia);
        $stmtMaster->execute();
        $resMaster = $stmtMaster->get_result();
        $master = $resMaster ? $resMaster->fetch_assoc() : null;
        $stmtMaster->close();

        if (!$master) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INCIDENCE_NOT_FOUND', "Incidencia con UUID '{$uuidIncidencia}' no encontrada dentro del alcance autorizado.", 404);
            return;
        }

        // Workplace scope verification
        $stationId = $master['id_estacion'] !== null ? (int)$master['id_estacion'] : null;
        if ($stationId !== null && !in_array($stationId, $authorizedStations, true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INCIDENCE_NOT_FOUND', "Incidencia no encontrada dentro del alcance autorizado del usuario.", 404);
            return;
        }

        // 4. Query all append-only revisions in deterministic order
        $masterId = (int)$master['id'];
        $stmtRev = $conexion->prepare("SELECT revision_numero, estado_revision, motivo, folio_evidencia, creado_por_usuario_id, aprobado_por_usuario_id, creado_at_utc FROM op_rh_v2_incidencias_revisiones WHERE id_incidencia_maestro = ? ORDER BY revision_numero ASC");
        if (!$stmtRev) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar revisiones.', 500);
            return;
        }
        $stmtRev->bind_param("i", $masterId);
        $stmtRev->execute();
        $resRev = $stmtRev->get_result();

        $revisionsList = [];
        while ($rowRev = $resRev->fetch_assoc()) {
            $revisionsList[] = [
                'revision_number' => (int)$rowRev['revision_numero'],
                'status' => (string)$rowRev['estado_revision'],
                'reason' => self::extractCleanReason($rowRev['motivo']),
                'evidence_reference' => $rowRev['folio_evidencia'] !== null ? (string)$rowRev['folio_evidencia'] : null,
                'created_by_user_id' => (int)$rowRev['creado_por_usuario_id'],
                'approved_by_user_id' => $rowRev['aprobado_por_usuario_id'] !== null ? (int)$rowRev['aprobado_por_usuario_id'] : null,
                'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$rowRev['creado_at_utc']))
            ];
        }
        $stmtRev->close();
        $checkReference = $this->readPunchCheckReference($conexion, $masterId);
        $classBD->desconectarBD($conexion);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'uuid_incidencia' => (string)$master['uuid_incidencia'],
                'worker_id' => (int)$master['id_personal'],
                'workplace_id' => $stationId,
                'category' => (string)$master['tipo_incidencia'],
                'scope' => (string)$master['alcance_incidencia'],
                'date_range' => [
                    'start_date' => (string)$master['fecha_inicio_local'],
                    'end_date' => (string)$master['fecha_fin_local']
                ],
                'time_interval' => null,
                'current_status' => (string)$master['estado_actual'],
                'current_revision' => (int)$master['revision_actual'],
                'check_reference' => $checkReference,
                'revisions' => $revisionsList
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /api/v2/administrative-evaluations
     * Read the existing administrative evaluation associated with worker + workplace + local workday.
     */
    public function handleReadAdministrativeEvaluation(): void
    {
        $this->setSecurityHeaders();

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

        // 2. Authorize dedicated ACL: checador-v2-incidencias.leer
        $authz = Portal3JwtAuthHelper::authorizeIncidences($conexion, $auth, 'leer');
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
            return;
        }

        $authorizedStations = $authz['authorized_stations'] ?? [];
        if (empty($authorizedStations)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STATION_SCOPE_DENIED', 'Sin estaciones autorizadas para su perfil en este módulo.', 403);
            return;
        }

        // 3. Validate query parameters
        $workerIdRaw = $_GET['worker_id'] ?? null;
        if ($workerIdRaw === null || !ctype_digit((string)$workerIdRaw) || (int)$workerIdRaw <= 0) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Parámetro 'worker_id' es requerido y debe ser un entero positivo.", 400);
            return;
        }
        $workerId = (int)$workerIdRaw;

        // Canonical worker resolution
        $stmtWorker = $conexion->prepare("SELECT id FROM op_rh_personal WHERE id = ? LIMIT 1");
        if (!$stmtWorker) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar trabajador.', 500);
            return;
        }
        $stmtWorker->bind_param("i", $workerId);
        $stmtWorker->execute();
        $resWorker = $stmtWorker->get_result();
        $workerExists = ($resWorker && $resWorker->num_rows > 0);
        $stmtWorker->close();

        if (!$workerExists) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('WORKER_NOT_FOUND', "Trabajador con ID {$workerId} no encontrado en el sistema canónico.", 404);
            return;
        }

        $workplaceIdRaw = $_GET['workplace_id'] ?? ($_GET['station_id'] ?? null);
        if ($workplaceIdRaw === null || !ctype_digit((string)$workplaceIdRaw) || (int)$workplaceIdRaw <= 0) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Parámetro 'workplace_id'/'station_id' es requerido y debe ser un entero positivo.", 400);
            return;
        }
        $workplaceId = (int)$workplaceIdRaw;

        if (!in_array($workplaceId, $authorizedStations, true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STATION_SCOPE_DENIED', "La estación solicitada ({$workplaceId}) no está dentro del alcance autorizado del usuario.", 403);
            return;
        }

        $workDate = trim((string)($_GET['work_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Parámetro 'work_date' es requerido en formato YYYY-MM-DD.", 400);
            return;
        }

        // 4. Query existing evaluation (latest revision) - PURE READ ONLY
        $stmtEval = $conexion->prepare("SELECT id, id_jornada_programada, id_calculo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_admin, version_politica, estado_evaluacion, estado_revision_humana, fingerprint_ejecucion, creado_at_utc FROM op_rh_v2_evaluaciones_administrativas WHERE id_personal = ? AND id_estacion = ? AND fecha_jornada_local = ? ORDER BY revision_numero DESC LIMIT 1");
        if (!$stmtEval) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar evaluación administrativa.', 500);
            return;
        }
        $stmtEval->bind_param("iis", $workerId, $workplaceId, $workDate);
        $stmtEval->execute();
        $resEval = $stmtEval->get_result();
        $eval = $resEval ? $resEval->fetch_assoc() : null;
        $stmtEval->close();

        if (!$eval) {
            $classBD->desconectarBD($conexion);
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'worker_id' => $workerId,
                    'workplace_id' => $workplaceId,
                    'work_date' => $workDate,
                    'evaluation_exists' => false,
                    'evaluation' => null
                ]
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $evalId = (int)$eval['id'];

        // 5. Query linked incidence application references
        $sqlIncid = "SELECT m.uuid_incidencia, m.tipo_incidencia, r.revision_numero, a.estado_aplicacion
                     FROM op_rh_v2_evaluaciones_incidencias ei
                     JOIN op_rh_v2_incidencias_aplicaciones a ON a.id = ei.id_incidencia_aplicacion
                     JOIN op_rh_v2_incidencias_revisiones r ON r.id = a.id_incidencia_revision
                     JOIN op_rh_v2_incidencias_maestro m ON m.id = r.id_incidencia_maestro
                     WHERE ei.id_evaluacion_administrativa = ?
                     ORDER BY m.id ASC, r.revision_numero ASC";
        $stmtIncid = $conexion->prepare($sqlIncid);
        $linkedIncidences = [];
        if ($stmtIncid) {
            $stmtIncid->bind_param("i", $evalId);
            $stmtIncid->execute();
            $resIncid = $stmtIncid->get_result();
            while ($rowIncid = $resIncid->fetch_assoc()) {
                $linkedIncidences[] = [
                    'uuid_incidencia' => (string)$rowIncid['uuid_incidencia'],
                    'category' => (string)$rowIncid['tipo_incidencia'],
                    'revision_number' => (int)$rowIncid['revision_numero'],
                    'application_status' => (string)$rowIncid['estado_aplicacion']
                ];
            }
            $stmtIncid->close();
        }

        // 6. Query linked adjustments
        $sqlAdj = "SELECT adj.uuid_ajuste, adj.uuid_ajuste_logico, adj.tipo_ajuste, adj.revision_numero, adj.estado_ajuste
                   FROM op_rh_v2_evaluaciones_ajustes ea
                   JOIN op_rh_v2_ajustes_administrativos adj ON adj.id = ea.id_ajuste_administrativo
                   WHERE ea.id_evaluacion_administrativa = ?
                   ORDER BY adj.id ASC";
        $stmtAdj = $conexion->prepare($sqlAdj);
        $linkedAdjustments = [];
        if ($stmtAdj) {
            $stmtAdj->bind_param("i", $evalId);
            $stmtAdj->execute();
            $resAdj = $stmtAdj->get_result();
            while ($rowAdj = $resAdj->fetch_assoc()) {
                $linkedAdjustments[] = [
                    'uuid_ajuste' => (string)$rowAdj['uuid_ajuste'],
                    'uuid_ajuste_logico' => (string)$rowAdj['uuid_ajuste_logico'],
                    'adjustment_type' => (string)$rowAdj['tipo_ajuste'],
                    'revision_number' => (int)$rowAdj['revision_numero'],
                    'adjustment_status' => (string)$rowAdj['estado_ajuste']
                ];
            }
            $stmtAdj->close();
        }

        $classBD->desconectarBD($conexion);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'worker_id' => $workerId,
                'workplace_id' => $workplaceId,
                'work_date' => $workDate,
                'evaluation_exists' => true,
                'evaluation' => [
                    'revision_number' => (int)$eval['revision_numero'],
                    'administrative_status' => (string)$eval['estado_evaluacion'],
                    'human_review_status' => (string)$eval['estado_revision_humana'],
                    'fingerprint' => (string)$eval['fingerprint_ejecucion'],
                    'algorithm_version' => (string)$eval['version_algoritmo_admin'],
                    'policy_version' => (string)$eval['version_politica'],
                    'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$eval['creado_at_utc'])),
                    'associated_calculation_revision_id' => (int)$eval['id_calculo_jornada'],
                    'linked_incidences' => $linkedIncidences,
                    'linked_adjustments' => $linkedAdjustments
                ]
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /api/v2/incidences
     * Create new V2 administrative incidence master and initial revision 1.
     * Specification: Section 9.4
     */
    public function handleCreateIncident(): void
    {
        $this->setSecurityHeaders();
        // Deadline eligibility is based on the server receipt instant, not on
        // how long authorization and source validation take to finish.
        $requestReceivedAtUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // 1. Mandatory Idempotency-Key header
        $idempotencyKey = $this->getHeaderValue('Idempotency-Key');
        if ($idempotencyKey === '') {
            $this->sendJsonError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' es requerido.", 400);
            return;
        }
        if (strlen($idempotencyKey) > 64) {
            $this->sendJsonError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' no puede exceder 64 caracteres.", 400);
            return;
        }

        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        // 2. Authenticate Portal3 JWT
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($auth['status'], $auth['error'], $auth['code']);
            return;
        }

        // 3. Authorize dedicated ACL: checador-v2-incidencias.crear = 1
        $authz = Portal3JwtAuthHelper::authorizeIncidences($conexion, $auth, 'crear');
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
            return;
        }

        $authorizedStations = $authz['authorized_stations'] ?? [];
        if (empty($authorizedStations)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STATION_SCOPE_DENIED', 'Sin estaciones autorizadas para su perfil en este módulo.', 403);
            return;
        }

        // 4. Parse JSON payload
        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true);
        if (!is_array($body)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', 'Cuerpo JSON inválido o malformado.', 400);
            return;
        }

        // 5. Validate payload
        $workerId = isset($body['worker_id']) && is_numeric($body['worker_id']) ? (int)$body['worker_id'] : 0;
        if ($workerId <= 0) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'worker_id' es requerido y debe ser un entero positivo.", 400);
            return;
        }

        // Canonical worker resolution
        $stmtWorker = $conexion->prepare("SELECT id FROM op_rh_personal WHERE id = ? LIMIT 1");
        if (!$stmtWorker) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar trabajador.', 500);
            return;
        }
        $stmtWorker->bind_param("i", $workerId);
        $stmtWorker->execute();
        $resWorker = $stmtWorker->get_result();
        $workerExists = ($resWorker && $resWorker->num_rows > 0);
        $stmtWorker->close();

        if (!$workerExists) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('WORKER_NOT_FOUND', "Trabajador con ID {$workerId} no encontrado en el sistema canónico.", 404);
            return;
        }

        $workplaceId = isset($body['workplace_id']) && is_numeric($body['workplace_id']) ? (int)$body['workplace_id'] : (isset($body['station_id']) && is_numeric($body['station_id']) ? (int)$body['station_id'] : null);
        if ($workplaceId === null || $workplaceId <= 0) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'workplace_id' es requerido y debe ser un entero positivo.", 400);
            return;
        }

        if (!in_array($workplaceId, $authorizedStations, true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STATION_SCOPE_DENIED', "Estación solicitada '{$workplaceId}' fuera del alcance asignado.", 403);
            return;
        }

        $category = trim((string)($body['category'] ?? ''));
        $validCategories = ['VACATION', 'PERMISSION', 'DISABILITY', 'AUTHORIZED_LATENESS', 'AUTHORIZED_EARLY_DEPARTURE', 'OFFICIAL_MISSION', 'PUNCH_ATTENDANCE'];
        if (!in_array($category, $validCategories, true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Categoría de incidencia '{$category}' inválida. Categorías válidas: " . implode(', ', $validCategories), 400);
            return;
        }

        $scope = trim((string)($body['scope'] ?? 'WORKER'));
        if (!in_array($scope, ['WORKER', 'FULL_DAY', 'PARTIAL_DAY', 'WORKPLACE'], true)) {
            $scope = 'WORKER';
        }

        $startDate = trim((string)($body['start_date'] ?? ''));
        $endDate = trim((string)($body['end_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campos 'start_date' y 'end_date' son requeridos en formato YYYY-MM-DD.", 400);
            return;
        }
        if ($endDate < $startDate) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_DATE_RANGE', "Rango de fechas inválido: 'end_date' ({$endDate}) no puede ser anterior a 'start_date' ({$startDate}).", 422);
            return;
        }

        $reason = trim((string)($body['reason'] ?? ''));
        if ($reason === '') {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'reason' es requerido y no puede estar vacío.", 400);
            return;
        }

        $evidenceRef = isset($body['evidence_reference']) ? trim((string)$body['evidence_reference']) : null;
        if ($evidenceRef === '') {
            $evidenceRef = null;
        }

        $punchCheckReference = null;
        if ($category === 'PUNCH_ATTENDANCE') {
            $environment = strtolower(trim((string)(getenv('APP_ENV') ?: '')));
            if (!in_array($environment, ['dev', 'development', 'local'], true)
                || !str_starts_with($reason, '[QA DEV]')
                || !is_string($evidenceRef)
                || !str_starts_with($evidenceRef, 'QA-M06-')) {
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('QA_DEVELOPMENT_SCOPE_REQUIRED', 'Este registro M06 requiere entorno local de desarrollo y referencias QA autorizadas.', 403);
                return;
            }
            $checkResult = $this->validatePunchAttendanceCheck(
                $conexion,
                $body,
                $workerId,
                $workplaceId,
                $scope,
                $startDate,
                $endDate,
                $requestReceivedAtUtc
            );
            if (!($checkResult['ok'] ?? false)) {
                $classBD->desconectarBD($conexion);
                $errorCode = (string) ($checkResult['error'] ?? 'INVALID_PUNCH_CHECK');
                $httpCode = match ($errorCode) {
                    'CHECK_DATE_IN_FUTURE' => 422,
                    'INCIDENCE_DEADLINE_EXPIRED' => 422,
                    'ATTENDANCE_POLICY_NOT_VERSIONED' => 422,
                    'WORKER_MEMBERSHIP_UNVERIFIED' => 422,
                    'SOURCE_EVENT_NOT_MATCHED' => 422,
                    'WORKPLACE_TIMEZONE_UNRESOLVED' => 503,
                    default => 400,
                };
                $this->sendJsonError($errorCode, $this->punchCheckErrorMessage($errorCode), $httpCode);
                return;
            }
            $punchCheckReference = $checkResult['data'];
        } elseif (array_key_exists('check_date', $body) || array_key_exists('check_kind', $body)
            || array_key_exists('nature', $body) || array_key_exists('source_event_device_id', $body)
            || array_key_exists('source_event_uuid', $body)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PUNCH_CHECK', 'Los datos de chequeo solo aplican a PUNCH_ATTENDANCE.', 400);
            return;
        }

        // Actor strictly from authenticated JWT sub
        $actorUserId = (int)$auth['user_id'];

        // 6. Idempotency verification
        $semanticPayload = [
            'op' => 'create_incident',
            'worker_id' => $workerId,
            'workplace_id' => $workplaceId,
            'category' => $category,
            'scope' => $scope,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => $reason,
            'evidence_reference' => $evidenceRef,
            'punch_check' => $punchCheckReference === null ? null : [
                'check_date' => $punchCheckReference['check_date'],
                'check_kind' => $punchCheckReference['check_kind'],
                'nature' => $punchCheckReference['nature'],
                'source_event_device_id' => $punchCheckReference['source_event_device_id'],
                'source_event_uuid' => $punchCheckReference['source_event_uuid'],
                'policy_version_id' => $punchCheckReference['policy_version_id'],
                'timezone_id' => $punchCheckReference['timezone_id'],
                'cutoff_local' => $punchCheckReference['cutoff_local'],
            ],
        ];
        $semanticHash = hash('sha256', json_encode($semanticPayload));

        $searchPattern = '%"key":"' . $conexion->real_escape_string($idempotencyKey) . '"%';
        $stmtIdemp = $conexion->prepare("SELECT r.id, r.id_incidencia_maestro, r.revision_numero, r.estado_revision, r.motivo, r.creado_at_utc,
                                                m.uuid_incidencia, m.id_personal, m.id_estacion, m.tipo_incidencia, m.estado_actual, m.revision_actual
                                         FROM op_rh_v2_incidencias_revisiones r
                                         JOIN op_rh_v2_incidencias_maestro m ON m.id = r.id_incidencia_maestro
                                         WHERE r.motivo LIKE ?
                                         LIMIT 1");
        if ($stmtIdemp) {
            $stmtIdemp->bind_param("s", $searchPattern);
            $stmtIdemp->execute();
            $resIdemp = $stmtIdemp->get_result();
            $existingIdemp = $resIdemp ? $resIdemp->fetch_assoc() : null;
            $stmtIdemp->close();

            if ($existingIdemp) {
                if (preg_match('/<!-- IDEMP:(\{.*?\}) -->/s', (string)$existingIdemp['motivo'], $mIdemp)) {
                    $meta = json_decode($mIdemp[1], true);
                    $storedHash = $meta['hash'] ?? '';
                    if ($storedHash === $semanticHash) {
                        // Identical replay -> return existing incident
                        $checkReference = $this->readPunchCheckReference($conexion, (int)$existingIdemp['id_incidencia_maestro']);
                        $classBD->desconectarBD($conexion);
                        http_response_code(201);
                        echo json_encode([
                            'status' => 'success',
                            'data' => [
                                'uuid_incidencia' => (string)$existingIdemp['uuid_incidencia'],
                                'worker_id' => (int)$existingIdemp['id_personal'],
                                'workplace_id' => (int)$existingIdemp['id_estacion'],
                                'category' => (string)$existingIdemp['tipo_incidencia'],
                                'current_revision' => (int)$existingIdemp['revision_actual'],
                                'status' => (string)$existingIdemp['estado_actual'],
                                'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$existingIdemp['creado_at_utc'])),
                                'check_reference' => $checkReference
                            ]
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        return;
                    }
                }
                // Divergent payload with same idempotency key
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('IDEMPOTENCY_CONFLICT', "La clave de idempotencia '{$idempotencyKey}' ya fue utilizada previamente con un payload diferente.", 409);
                return;
            }
        }

        // An identical retry of an incidence already accepted within its
        // deadline remains idempotent after the cutoff. New rows still fail
        // closed at the cutoff below.
        if ($category === 'PUNCH_ATTENDANCE' && !empty($punchCheckReference['deadline_expired'])) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INCIDENCE_DEADLINE_EXPIRED', $this->punchCheckErrorMessage('INCIDENCE_DEADLINE_EXPIRED'), 422);
            return;
        }
        if (is_array($punchCheckReference)) {
            unset($punchCheckReference['deadline_expired'], $punchCheckReference['deadline_days']);
        }

        // 7. Persist new incident master + revision 1
        $uuidIncidencia = 'INC_' . bin2hex(random_bytes(8));
        $initialStatus = 'PENDING_APPROVAL';

        $storedMotivo = $reason . "\n<!-- IDEMP:" . json_encode(['key' => $idempotencyKey, 'hash' => $semanticHash], JSON_UNESCAPED_SLASHES) . " -->";

        $service = new \modelo\v2\journey\AdministrativeEvaluationService($conexion);
        try {
            $created = $service->createIncidenceMaster([
                'uuid_incidencia' => $uuidIncidencia,
                'id_personal' => $workerId,
                'id_estacion' => $workplaceId,
                'tipo_incidencia' => $category,
                'alcance_incidencia' => $scope,
                'fecha_inicio_local' => $startDate,
                'fecha_fin_local' => $endDate,
                'motivo' => $storedMotivo,
                'folio_evidencia' => $evidenceRef,
                'creado_por_usuario_id' => $actorUserId,
                'estado_actual' => $initialStatus,
                'estado_revision' => $initialStatus,
                'punch_check_link' => $punchCheckReference
            ]);
        } catch (\Throwable $e) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error al registrar incidencia: ' . $e->getMessage(), 500);
            return;
        }

        // 8. Fetch created_at_utc
        $revId = (int)$created['id_incidencia_revision'];
        $stmtTs = $conexion->prepare("SELECT creado_at_utc FROM op_rh_v2_incidencias_revisiones WHERE id = ? LIMIT 1");
        $stmtTs->bind_param("i", $revId);
        $stmtTs->execute();
        $resTs = $stmtTs->get_result()->fetch_assoc();
        $stmtTs->close();

        $createdAtUtc = $resTs ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$resTs['creado_at_utc'])) : gmdate('Y-m-d\TH:i:s\Z');

        $classBD->desconectarBD($conexion);

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'data' => [
                'uuid_incidencia' => $uuidIncidencia,
                'worker_id' => $workerId,
                'workplace_id' => $workplaceId,
                'category' => $category,
                'current_revision' => 1,
                'status' => $initialStatus,
                'created_at_utc' => $createdAtUtc,
                'check_reference' => $punchCheckReference
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /api/v2/incidences/{uuid_incidencia}/revisions
     * Create append-only incidence revision (amendment).
     * Specification: Section 9.5
     */
    public function handleCreateIncidentRevision(string $uuidIncidencia): void
    {
        $this->setSecurityHeaders();

        $uuidIncidencia = trim($uuidIncidencia);
        if ($uuidIncidencia === '') {
            $this->sendJsonError('INVALID_PAYLOAD', 'Identificador de incidencia ausente.', 400);
            return;
        }

        // 1. Mandatory Idempotency-Key header
        $idempotencyKey = $this->getHeaderValue('Idempotency-Key');
        if ($idempotencyKey === '') {
            $this->sendJsonError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' es requerido.", 400);
            return;
        }
        if (strlen($idempotencyKey) > 64) {
            $this->sendJsonError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' no puede exceder 64 caracteres.", 400);
            return;
        }

        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        // 2. Authenticate Portal3 JWT
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($auth['status'], $auth['error'], $auth['code']);
            return;
        }

        // 3. Authorize dedicated ACL: checador-v2-incidencias-resolucion.editar = 1 OR checador-v2-incidencias.crear = 1
        $authz = Portal3JwtAuthHelper::authorizeIncidenceRevision($conexion, $auth);
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
            return;
        }

        $authorizedStations = $authz['authorized_stations'] ?? [];
        if (empty($authorizedStations)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STATION_SCOPE_DENIED', 'Sin estaciones autorizadas para su perfil en este módulo.', 403);
            return;
        }

        // 4. Parse JSON payload
        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true);
        if (!is_array($body)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', 'Cuerpo JSON inválido o malformado.', 400);
            return;
        }

        // 5. Concurrency check field
        if (!isset($body['expected_current_revision']) || !is_numeric($body['expected_current_revision'])) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'expected_current_revision' es requerido y debe ser entero.", 400);
            return;
        }
        $expectedCurrentRev = (int)$body['expected_current_revision'];

        // 6. Prohibit unauthorized lifecycle transitions via revision endpoint
        $forbiddenLifecycle = ['RESOLVED', 'AUTHORIZED', 'REJECTED', 'REVOKED', 'CANCELLED', 'CONFIRM_INCIDENCE_PREVALENCE', 'REJECT_INCIDENCE_PREVALENCE'];
        $checkStatus = strtoupper(trim((string)($body['status'] ?? ($body['action'] ?? ($body['estado_revision'] ?? '')))));
        foreach ($forbiddenLifecycle as $fb) {
            if ($checkStatus !== '' && strpos($checkStatus, $fb) !== false) {
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('LIFECYCLE_TRANSITION_FORBIDDEN', "Transición de ciclo de vida '{$checkStatus}' no permitida en creación de revisiones generales.", 422);
                return;
            }
        }

        // 7. Validate reason and optional date amendments
        $reason = trim((string)($body['reason'] ?? ''));
        if ($reason === '') {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'reason' es requerido y no puede estar vacío.", 400);
            return;
        }

        $evidenceRef = isset($body['evidence_reference']) ? trim((string)$body['evidence_reference']) : null;
        if ($evidenceRef === '') {
            $evidenceRef = null;
        }

        $startDate = isset($body['start_date']) ? trim((string)$body['start_date']) : null;
        $endDate = isset($body['end_date']) ? trim((string)$body['end_date']) : null;
        if ($startDate !== null && $startDate !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('INVALID_PAYLOAD', "Campo 'start_date' debe ser formato YYYY-MM-DD.", 400);
                return;
            }
        } else {
            $startDate = null;
        }
        if ($endDate !== null && $endDate !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('INVALID_PAYLOAD', "Campo 'end_date' debe ser formato YYYY-MM-DD.", 400);
                return;
            }
        } else {
            $endDate = null;
        }

        // 8. Fetch master record
        $stmtMaster = $conexion->prepare("SELECT id, uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia,
                                                 fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual
                                          FROM op_rh_v2_incidencias_maestro
                                          WHERE uuid_incidencia = ?
                                          LIMIT 1");
        if (!$stmtMaster) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar incidencia.', 500);
            return;
        }
        $stmtMaster->bind_param("s", $uuidIncidencia);
        $stmtMaster->execute();
        $resMaster = $stmtMaster->get_result();
        $master = $resMaster ? $resMaster->fetch_assoc() : null;
        $stmtMaster->close();

        if (!$master) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INCIDENCE_NOT_FOUND', "Incidencia con UUID '{$uuidIncidencia}' no encontrada dentro del alcance autorizado.", 404);
            return;
        }

        $stationId = $master['id_estacion'] !== null ? (int)$master['id_estacion'] : null;
        if ($stationId !== null && !in_array($stationId, $authorizedStations, true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INCIDENCE_NOT_FOUND', "Incidencia no encontrada dentro del alcance autorizado del usuario.", 404);
            return;
        }

        if (in_array((string)$master['estado_actual'], ['CANCELLED', 'REVOKED', 'REJECTED'], true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_LIFECYCLE_TRANSITION', "No es posible crear revisiones sobre una incidencia en estado '{$master['estado_actual']}'.", 409);
            return;
        }

        $effectiveStart = $startDate ?? (string)$master['fecha_inicio_local'];
        $effectiveEnd = $endDate ?? (string)$master['fecha_fin_local'];
        if ($effectiveEnd < $effectiveStart) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_DATE_RANGE', "Rango de fechas inválido: 'end_date' ({$effectiveEnd}) no puede ser anterior a 'start_date' ({$effectiveStart}).", 422);
            return;
        }

        // 9. Idempotency verification for revisions
        $semanticPayload = [
            'op' => 'create_revision',
            'uuid_incidencia' => $uuidIncidencia,
            'expected_current_revision' => $expectedCurrentRev,
            'reason' => $reason,
            'evidence_reference' => $evidenceRef,
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        $semanticHash = hash('sha256', json_encode($semanticPayload));

        $searchPattern = '%"key":"' . $conexion->real_escape_string($idempotencyKey) . '"%';
        $stmtIdemp = $conexion->prepare("SELECT r.id, r.id_incidencia_maestro, r.revision_numero, r.estado_revision, r.motivo, r.creado_at_utc,
                                                m.uuid_incidencia
                                         FROM op_rh_v2_incidencias_revisiones r
                                         JOIN op_rh_v2_incidencias_maestro m ON m.id = r.id_incidencia_maestro
                                         WHERE r.motivo LIKE ?
                                         LIMIT 1");
        if ($stmtIdemp) {
            $stmtIdemp->bind_param("s", $searchPattern);
            $stmtIdemp->execute();
            $resIdemp = $stmtIdemp->get_result();
            $existingIdemp = $resIdemp ? $resIdemp->fetch_assoc() : null;
            $stmtIdemp->close();

            if ($existingIdemp) {
                if (preg_match('/<!-- IDEMP:(\{.*?\}) -->/s', (string)$existingIdemp['motivo'], $mIdemp)) {
                    $meta = json_decode($mIdemp[1], true);
                    $storedHash = $meta['hash'] ?? '';
                    if ($storedHash === $semanticHash && (string)$existingIdemp['uuid_incidencia'] === $uuidIncidencia) {
                        // Identical replay -> return existing revision
                        $classBD->desconectarBD($conexion);
                        http_response_code(201);
                        echo json_encode([
                            'status' => 'success',
                            'data' => [
                                'uuid_incidencia' => (string)$existingIdemp['uuid_incidencia'],
                                'new_revision_number' => (int)$existingIdemp['revision_numero'],
                                'status' => (string)$existingIdemp['estado_revision'],
                                'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$existingIdemp['creado_at_utc']))
                            ]
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        return;
                    }
                }
                // Divergent payload with same idempotency key
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('IDEMPOTENCY_CONFLICT', "La clave de idempotencia '{$idempotencyKey}' ya fue utilizada previamente con un payload diferente.", 409);
                return;
            }
        }

        // 10. Concurrency check
        if ($expectedCurrentRev !== (int)$master['revision_actual']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STALE_REVISION_CONFLICT', "Revisión esperada ({$expectedCurrentRev}) no coincide con la revisión actual ({$master['revision_actual']}).", 409);
            return;
        }

        // 11. Persist revision via AdministrativeEvaluationService
        $storedMotivo = $reason . "\n<!-- IDEMP:" . json_encode(['key' => $idempotencyKey, 'hash' => $semanticHash], JSON_UNESCAPED_SLASHES) . " -->";
        $actorUserId = (int)$auth['user_id'];
        $revStatus = (string)$master['estado_actual'];

        $service = new \modelo\v2\journey\AdministrativeEvaluationService($conexion);
        try {
            $revResult = $service->createIncidenceRevision((int)$master['id'], [
                'expected_current_revision' => $expectedCurrentRev,
                'estado_revision' => $revStatus,
                'motivo' => $storedMotivo,
                'folio_evidencia' => $evidenceRef,
                'creado_por_usuario_id' => $actorUserId,
                'fecha_inicio_local' => $startDate,
                'fecha_fin_local' => $endDate
            ]);
        } catch (\RuntimeException $e) {
            $classBD->desconectarBD($conexion);
            if (strpos($e->getMessage(), 'STALE_REVISION_CONFLICT') !== false) {
                $this->sendJsonError('STALE_REVISION_CONFLICT', $e->getMessage(), 409);
            } else {
                $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
            }
            return;
        } catch (\Throwable $e) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error al registrar revisión de incidencia: ' . $e->getMessage(), 500);
            return;
        }

        // 12. Fetch created_at_utc
        $newRevId = (int)$revResult['id_incidencia_revision'];
        $stmtTs = $conexion->prepare("SELECT creado_at_utc FROM op_rh_v2_incidencias_revisiones WHERE id = ? LIMIT 1");
        $stmtTs->bind_param("i", $newRevId);
        $stmtTs->execute();
        $resTs = $stmtTs->get_result()->fetch_assoc();
        $stmtTs->close();

        $createdAtUtc = $resTs ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$resTs['creado_at_utc'])) : gmdate('Y-m-d\TH:i:s\Z');

        $classBD->desconectarBD($conexion);

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'data' => [
                'uuid_incidencia' => $uuidIncidencia,
                'new_revision_number' => (int)$revResult['revision_numero'],
                'status' => (string)$revResult['estado_revision'],
                'created_at_utc' => $createdAtUtc
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /api/v2/incidences/{uuid_incidencia}/resolve
     * Resolve conflict / administrative review (Contract Section 9.6).
     */
    public function handleResolveIncident(string $uuidIncidencia): void
    {
        $this->setSecurityHeaders();

        $uuidIncidencia = trim($uuidIncidencia);
        if ($uuidIncidencia === '') {
            $this->sendJsonError('INVALID_PAYLOAD', 'Identificador de incidencia ausente.', 400);
            return;
        }

        $idempotencyKey = $this->getHeaderValue('Idempotency-Key');
        if ($idempotencyKey === '') {
            $this->sendJsonError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' es requerido.", 400);
            return;
        }
        if (strlen($idempotencyKey) > 64) {
            $this->sendJsonError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' no puede exceder 64 caracteres.", 400);
            return;
        }

        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($auth['status'], $auth['error'], $auth['code']);
            return;
        }

        $authz = Portal3JwtAuthHelper::authorizeIncidenceResolution($conexion, $auth);
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
            return;
        }

        $authorizedStations = $authz['authorized_stations'] ?? [];
        if (empty($authorizedStations)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STATION_SCOPE_DENIED', 'Sin estaciones autorizadas para su perfil en este módulo.', 403);
            return;
        }

        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true);
        if (!is_array($body)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', 'Cuerpo JSON inválido o malformado.', 400);
            return;
        }

        if (!isset($body['expected_current_revision']) || !is_numeric($body['expected_current_revision'])) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'expected_current_revision' es requerido y debe ser entero.", 400);
            return;
        }
        $expectedCurrentRev = (int)$body['expected_current_revision'];

        $resolutionAction = trim((string)($body['resolution_action'] ?? 'CONFIRM_INCIDENCE_PREVALENCE'));
        $resolutionNotes = trim((string)($body['resolution_notes'] ?? ($body['reason'] ?? '')));
        if ($resolutionNotes === '') {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'resolution_notes' es requerido y no puede estar vacío.", 400);
            return;
        }

        $evidenceRef = isset($body['evidence_reference']) ? trim((string)$body['evidence_reference']) : null;
        if ($evidenceRef === '') {
            $evidenceRef = null;
        }

        $stmtMaster = $conexion->prepare("SELECT id, uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia,
                                                 fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual
                                          FROM op_rh_v2_incidencias_maestro
                                          WHERE uuid_incidencia = ?
                                          LIMIT 1");
        if (!$stmtMaster) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar incidencia.', 500);
            return;
        }
        $stmtMaster->bind_param("s", $uuidIncidencia);
        $stmtMaster->execute();
        $resMaster = $stmtMaster->get_result();
        $master = $resMaster ? $resMaster->fetch_assoc() : null;
        $stmtMaster->close();

        if (!$master) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INCIDENCE_NOT_FOUND', "Incidencia con UUID '{$uuidIncidencia}' no encontrada dentro del alcance autorizado.", 404);
            return;
        }

        $stationId = $master['id_estacion'] !== null ? (int)$master['id_estacion'] : null;
        if ($stationId !== null && !in_array($stationId, $authorizedStations, true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INCIDENCE_NOT_FOUND', "Incidencia no encontrada dentro del alcance autorizado del usuario.", 404);
            return;
        }

        $semanticPayload = [
            'op' => 'resolve',
            'uuid_incidencia' => $uuidIncidencia,
            'expected_current_revision' => $expectedCurrentRev,
            'resolution_action' => $resolutionAction,
            'resolution_notes' => $resolutionNotes,
            'evidence_reference' => $evidenceRef
        ];
        $semanticHash = hash('sha256', json_encode($semanticPayload));

        $searchPattern = '%"key":"' . $conexion->real_escape_string($idempotencyKey) . '"%';
        $stmtIdemp = $conexion->prepare("SELECT r.id, r.id_incidencia_maestro, r.revision_numero, r.estado_revision, r.motivo, r.creado_at_utc,
                                                r.creado_por_usuario_id, m.uuid_incidencia
                                         FROM op_rh_v2_incidencias_revisiones r
                                         JOIN op_rh_v2_incidencias_maestro m ON m.id = r.id_incidencia_maestro
                                         WHERE r.motivo LIKE ?
                                         LIMIT 1");
        if ($stmtIdemp) {
            $stmtIdemp->bind_param("s", $searchPattern);
            $stmtIdemp->execute();
            $resIdemp = $stmtIdemp->get_result();
            $existingIdemp = $resIdemp ? $resIdemp->fetch_assoc() : null;
            $stmtIdemp->close();

            if ($existingIdemp) {
                if (preg_match('/<!-- IDEMP:(\{.*?\}) -->/s', (string)$existingIdemp['motivo'], $mIdemp)) {
                    $meta = json_decode($mIdemp[1], true);
                    $storedHash = $meta['hash'] ?? '';
                    if ($storedHash === $semanticHash && (string)$existingIdemp['uuid_incidencia'] === $uuidIncidencia) {
                        $classBD->desconectarBD($conexion);
                        http_response_code(200);
                        echo json_encode([
                            'status' => 'success',
                            'data' => [
                                'uuid_incidencia' => (string)$existingIdemp['uuid_incidencia'],
                                'resolution_revision_number' => (int)$existingIdemp['revision_numero'],
                                'resolution_status' => (string)$existingIdemp['estado_revision'],
                                'resolved_by_user_id' => (int)$existingIdemp['creado_por_usuario_id'],
                                'resolved_at_utc' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$existingIdemp['creado_at_utc']))
                            ]
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        return;
                    }
                }
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('IDEMPOTENCY_CONFLICT', "La clave de idempotencia '{$idempotencyKey}' ya fue utilizada previamente con un payload diferente.", 409);
                return;
            }
        }

        if ($expectedCurrentRev !== (int)$master['revision_actual']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STALE_REVISION_CONFLICT', "Revisión esperada ({$expectedCurrentRev}) no coincide con la revisión actual ({$master['revision_actual']}).", 409);
            return;
        }

        if (in_array((string)$master['estado_actual'], ['CANCELLED', 'REVOKED'], true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_LIFECYCLE_TRANSITION', "No es posible resolver una incidencia en estado '{$master['estado_actual']}'.", 409);
            return;
        }

        $storedMotivo = $resolutionNotes . "\n<!-- IDEMP:" . json_encode(['key' => $idempotencyKey, 'hash' => $semanticHash], JSON_UNESCAPED_SLASHES) . " -->";
        $actorUserId = (int)$auth['user_id'];
        $resStatus = 'RESOLVED_PENDING_AUTHORIZATION';

        $service = new \modelo\v2\journey\AdministrativeEvaluationService($conexion);
        try {
            $revResult = $service->createIncidenceRevision((int)$master['id'], [
                'expected_current_revision' => $expectedCurrentRev,
                'estado_revision' => $resStatus,
                'estado_actual' => $resStatus,
                'motivo' => $storedMotivo,
                'folio_evidencia' => $evidenceRef,
                'creado_por_usuario_id' => $actorUserId,
                'aprobado_por_usuario_id' => null
            ]);
        } catch (\RuntimeException $e) {
            $classBD->desconectarBD($conexion);
            if (strpos($e->getMessage(), 'STALE_REVISION_CONFLICT') !== false) {
                $this->sendJsonError('STALE_REVISION_CONFLICT', $e->getMessage(), 409);
            } else {
                $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
            }
            return;
        } catch (\Throwable $e) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error al registrar resolución de incidencia: ' . $e->getMessage(), 500);
            return;
        }

        $newRevId = (int)$revResult['id_incidencia_revision'];
        $stmtTs = $conexion->prepare("SELECT creado_at_utc FROM op_rh_v2_incidencias_revisiones WHERE id = ? LIMIT 1");
        $stmtTs->bind_param("i", $newRevId);
        $stmtTs->execute();
        $resTs = $stmtTs->get_result()->fetch_assoc();
        $stmtTs->close();

        $createdAtUtc = $resTs ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$resTs['creado_at_utc'])) : gmdate('Y-m-d\TH:i:s\Z');

        $classBD->desconectarBD($conexion);

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'data' => [
                'uuid_incidencia' => $uuidIncidencia,
                'resolution_revision_number' => (int)$revResult['revision_numero'],
                'resolution_status' => $resStatus,
                'resolved_by_user_id' => $actorUserId,
                'resolved_at_utc' => $createdAtUtc
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /api/v2/incidences/{uuid_incidencia}/authorize
     * Authorize incident revision (Contract Section 9.7).
     */
    public function handleAuthorizeIncident(string $uuidIncidencia): void
    {
        $this->setSecurityHeaders();

        $uuidIncidencia = trim($uuidIncidencia);
        if ($uuidIncidencia === '') {
            $this->sendJsonError('INVALID_PAYLOAD', 'Identificador de incidencia ausente.', 400);
            return;
        }

        $idempotencyKey = $this->getHeaderValue('Idempotency-Key');
        if ($idempotencyKey === '') {
            $this->sendJsonError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' es requerido.", 400);
            return;
        }
        if (strlen($idempotencyKey) > 64) {
            $this->sendJsonError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' no puede exceder 64 caracteres.", 400);
            return;
        }

        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($auth['status'], $auth['error'], $auth['code']);
            return;
        }

        $authz = Portal3JwtAuthHelper::authorizeIncidenceAuthorization($conexion, $auth);
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
            return;
        }

        $authorizedStations = $authz['authorized_stations'] ?? [];
        if (empty($authorizedStations)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STATION_SCOPE_DENIED', 'Sin estaciones autorizadas para su perfil en este módulo.', 403);
            return;
        }

        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true);
        if (!is_array($body)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', 'Cuerpo JSON inválido o malformado.', 400);
            return;
        }

        if (!isset($body['expected_current_revision']) || !is_numeric($body['expected_current_revision'])) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'expected_current_revision' es requerido y debe ser entero.", 400);
            return;
        }
        $expectedCurrentRev = (int)$body['expected_current_revision'];

        $authNotes = trim((string)($body['authorization_notes'] ?? ($body['reason'] ?? 'Autorizado formalmente por Jefatura de Recursos Humanos')));
        $evidenceRef = isset($body['evidence_reference']) ? trim((string)$body['evidence_reference']) : null;
        if ($evidenceRef === '') {
            $evidenceRef = null;
        }

        $stmtMaster = $conexion->prepare("SELECT id, uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia,
                                                 fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual
                                          FROM op_rh_v2_incidencias_maestro
                                          WHERE uuid_incidencia = ?
                                          LIMIT 1");
        if (!$stmtMaster) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar incidencia.', 500);
            return;
        }
        $stmtMaster->bind_param("s", $uuidIncidencia);
        $stmtMaster->execute();
        $resMaster = $stmtMaster->get_result();
        $master = $resMaster ? $resMaster->fetch_assoc() : null;
        $stmtMaster->close();

        if (!$master) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INCIDENCE_NOT_FOUND', "Incidencia con UUID '{$uuidIncidencia}' no encontrada dentro del alcance autorizado.", 404);
            return;
        }

        $stationId = $master['id_estacion'] !== null ? (int)$master['id_estacion'] : null;
        if ($stationId !== null && !in_array($stationId, $authorizedStations, true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INCIDENCE_NOT_FOUND', "Incidencia no encontrada dentro del alcance autorizado del usuario.", 404);
            return;
        }

        $semanticPayload = [
            'op' => 'authorize',
            'uuid_incidencia' => $uuidIncidencia,
            'expected_current_revision' => $expectedCurrentRev,
            'authorization_notes' => $authNotes,
            'evidence_reference' => $evidenceRef
        ];
        $semanticHash = hash('sha256', json_encode($semanticPayload));

        $searchPattern = '%"key":"' . $conexion->real_escape_string($idempotencyKey) . '"%';
        $stmtIdemp = $conexion->prepare("SELECT r.id, r.id_incidencia_maestro, r.revision_numero, r.estado_revision, r.motivo, r.creado_at_utc,
                                                r.aprobado_por_usuario_id, m.uuid_incidencia
                                         FROM op_rh_v2_incidencias_revisiones r
                                         JOIN op_rh_v2_incidencias_maestro m ON m.id = r.id_incidencia_maestro
                                         WHERE r.motivo LIKE ?
                                         LIMIT 1");
        if ($stmtIdemp) {
            $stmtIdemp->bind_param("s", $searchPattern);
            $stmtIdemp->execute();
            $resIdemp = $stmtIdemp->get_result();
            $existingIdemp = $resIdemp ? $resIdemp->fetch_assoc() : null;
            $stmtIdemp->close();

            if ($existingIdemp) {
                if (preg_match('/<!-- IDEMP:(\{.*?\}) -->/s', (string)$existingIdemp['motivo'], $mIdemp)) {
                    $meta = json_decode($mIdemp[1], true);
                    $storedHash = $meta['hash'] ?? '';
                    if ($storedHash === $semanticHash && (string)$existingIdemp['uuid_incidencia'] === $uuidIncidencia) {
                        $classBD->desconectarBD($conexion);
                        http_response_code(200);
                        echo json_encode([
                            'status' => 'success',
                            'data' => [
                                'uuid_incidencia' => (string)$existingIdemp['uuid_incidencia'],
                                'authorized_revision_number' => (int)$existingIdemp['revision_numero'],
                                'status' => (string)$existingIdemp['estado_revision'],
                                'authorized_by_user_id' => (int)$existingIdemp['aprobado_por_usuario_id'],
                                'authorized_at_utc' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$existingIdemp['creado_at_utc']))
                            ]
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        return;
                    }
                }
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('IDEMPOTENCY_CONFLICT', "La clave de idempotencia '{$idempotencyKey}' ya fue utilizada previamente con un payload diferente.", 409);
                return;
            }
        }

        if ($expectedCurrentRev !== (int)$master['revision_actual']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STALE_REVISION_CONFLICT', "Revisión esperada ({$expectedCurrentRev}) no coincide con la revisión actual ({$master['revision_actual']}).", 409);
            return;
        }

        if (in_array((string)$master['estado_actual'], ['CANCELLED', 'REVOKED', 'REJECTED'], true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_LIFECYCLE_TRANSITION', "No es posible autorizar una incidencia en estado '{$master['estado_actual']}'.", 409);
            return;
        }

        $storedMotivo = $authNotes . "\n<!-- IDEMP:" . json_encode(['key' => $idempotencyKey, 'hash' => $semanticHash], JSON_UNESCAPED_SLASHES) . " -->";
        $actorUserId = (int)$auth['user_id'];
        $newStatus = 'ACTIVE';

        $service = new \modelo\v2\journey\AdministrativeEvaluationService($conexion);
        try {
            $revResult = $service->createIncidenceRevision((int)$master['id'], [
                'expected_current_revision' => $expectedCurrentRev,
                'estado_revision' => $newStatus,
                'estado_actual' => $newStatus,
                'motivo' => $storedMotivo,
                'folio_evidencia' => $evidenceRef,
                'creado_por_usuario_id' => $actorUserId,
                'aprobado_por_usuario_id' => $actorUserId
            ]);
        } catch (\RuntimeException $e) {
            $classBD->desconectarBD($conexion);
            if (strpos($e->getMessage(), 'STALE_REVISION_CONFLICT') !== false) {
                $this->sendJsonError('STALE_REVISION_CONFLICT', $e->getMessage(), 409);
            } else {
                $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
            }
            return;
        } catch (\Throwable $e) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error al autorizar incidencia: ' . $e->getMessage(), 500);
            return;
        }

        $newRevId = (int)$revResult['id_incidencia_revision'];
        $stmtTs = $conexion->prepare("SELECT creado_at_utc FROM op_rh_v2_incidencias_revisiones WHERE id = ? LIMIT 1");
        $stmtTs->bind_param("i", $newRevId);
        $stmtTs->execute();
        $resTs = $stmtTs->get_result()->fetch_assoc();
        $stmtTs->close();

        $createdAtUtc = $resTs ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$resTs['creado_at_utc'])) : gmdate('Y-m-d\TH:i:s\Z');

        $classBD->desconectarBD($conexion);

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'data' => [
                'uuid_incidencia' => $uuidIncidencia,
                'authorized_revision_number' => (int)$revResult['revision_numero'],
                'status' => $newStatus,
                'authorized_by_user_id' => $actorUserId,
                'authorized_at_utc' => $createdAtUtc
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /api/v2/incidences/{uuid_incidencia}/reject
     * Reject incident revision (Contract Section 9.8).
     */
    public function handleRejectIncident(string $uuidIncidencia): void
    {
        $this->setSecurityHeaders();

        $uuidIncidencia = trim($uuidIncidencia);
        if ($uuidIncidencia === '') {
            $this->sendJsonError('INVALID_PAYLOAD', 'Identificador de incidencia ausente.', 400);
            return;
        }

        $idempotencyKey = $this->getHeaderValue('Idempotency-Key');
        if ($idempotencyKey === '') {
            $this->sendJsonError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' es requerido.", 400);
            return;
        }
        if (strlen($idempotencyKey) > 64) {
            $this->sendJsonError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' no puede exceder 64 caracteres.", 400);
            return;
        }

        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($auth['status'], $auth['error'], $auth['code']);
            return;
        }

        $authz = Portal3JwtAuthHelper::authorizeIncidenceRejection($conexion, $auth);
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
            return;
        }

        $authorizedStations = $authz['authorized_stations'] ?? [];
        if (empty($authorizedStations)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STATION_SCOPE_DENIED', 'Sin estaciones autorizadas para su perfil en este módulo.', 403);
            return;
        }

        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true);
        if (!is_array($body)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', 'Cuerpo JSON inválido o malformado.', 400);
            return;
        }

        if (!isset($body['expected_current_revision']) || !is_numeric($body['expected_current_revision'])) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'expected_current_revision' es requerido y debe ser entero.", 400);
            return;
        }
        $expectedCurrentRev = (int)$body['expected_current_revision'];

        $rejectionReason = trim((string)($body['rejection_reason'] ?? ($body['reason'] ?? '')));
        if ($rejectionReason === '') {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'rejection_reason' es requerido y no puede estar vacío.", 400);
            return;
        }

        $evidenceRef = isset($body['evidence_reference']) ? trim((string)$body['evidence_reference']) : null;
        if ($evidenceRef === '') {
            $evidenceRef = null;
        }

        $stmtMaster = $conexion->prepare("SELECT id, uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia,
                                                 fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual
                                          FROM op_rh_v2_incidencias_maestro
                                          WHERE uuid_incidencia = ?
                                          LIMIT 1");
        if (!$stmtMaster) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar incidencia.', 500);
            return;
        }
        $stmtMaster->bind_param("s", $uuidIncidencia);
        $stmtMaster->execute();
        $resMaster = $stmtMaster->get_result();
        $master = $resMaster ? $resMaster->fetch_assoc() : null;
        $stmtMaster->close();

        if (!$master) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INCIDENCE_NOT_FOUND', "Incidencia con UUID '{$uuidIncidencia}' no encontrada dentro del alcance autorizado.", 404);
            return;
        }

        $stationId = $master['id_estacion'] !== null ? (int)$master['id_estacion'] : null;
        if ($stationId !== null && !in_array($stationId, $authorizedStations, true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INCIDENCE_NOT_FOUND', "Incidencia no encontrada dentro del alcance autorizado del usuario.", 404);
            return;
        }

        $semanticPayload = [
            'op' => 'reject',
            'uuid_incidencia' => $uuidIncidencia,
            'expected_current_revision' => $expectedCurrentRev,
            'rejection_reason' => $rejectionReason,
            'evidence_reference' => $evidenceRef
        ];
        $semanticHash = hash('sha256', json_encode($semanticPayload));

        $searchPattern = '%"key":"' . $conexion->real_escape_string($idempotencyKey) . '"%';
        $stmtIdemp = $conexion->prepare("SELECT r.id, r.id_incidencia_maestro, r.revision_numero, r.estado_revision, r.motivo, r.creado_at_utc,
                                                r.aprobado_por_usuario_id, m.uuid_incidencia
                                         FROM op_rh_v2_incidencias_revisiones r
                                         JOIN op_rh_v2_incidencias_maestro m ON m.id = r.id_incidencia_maestro
                                         WHERE r.motivo LIKE ?
                                         LIMIT 1");
        if ($stmtIdemp) {
            $stmtIdemp->bind_param("s", $searchPattern);
            $stmtIdemp->execute();
            $resIdemp = $stmtIdemp->get_result();
            $existingIdemp = $resIdemp ? $resIdemp->fetch_assoc() : null;
            $stmtIdemp->close();

            if ($existingIdemp) {
                if (preg_match('/<!-- IDEMP:(\{.*?\}) -->/s', (string)$existingIdemp['motivo'], $mIdemp)) {
                    $meta = json_decode($mIdemp[1], true);
                    $storedHash = $meta['hash'] ?? '';
                    if ($storedHash === $semanticHash && (string)$existingIdemp['uuid_incidencia'] === $uuidIncidencia) {
                        $classBD->desconectarBD($conexion);
                        http_response_code(200);
                        echo json_encode([
                            'status' => 'success',
                            'data' => [
                                'uuid_incidencia' => (string)$existingIdemp['uuid_incidencia'],
                                'rejected_revision_number' => (int)$existingIdemp['revision_numero'],
                                'status' => (string)$existingIdemp['estado_revision'],
                                'rejected_by_user_id' => (int)$existingIdemp['aprobado_por_usuario_id'],
                                'rejected_at_utc' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$existingIdemp['creado_at_utc']))
                            ]
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        return;
                    }
                }
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('IDEMPOTENCY_CONFLICT', "La clave de idempotencia '{$idempotencyKey}' ya fue utilizada previamente con un payload diferente.", 409);
                return;
            }
        }

        if ($expectedCurrentRev !== (int)$master['revision_actual']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STALE_REVISION_CONFLICT', "Revisión esperada ({$expectedCurrentRev}) no coincide con la revisión actual ({$master['revision_actual']}).", 409);
            return;
        }

        if (in_array((string)$master['estado_actual'], ['CANCELLED', 'REVOKED', 'REJECTED'], true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_LIFECYCLE_TRANSITION', "No es posible rechazar una incidencia en estado '{$master['estado_actual']}'.", 409);
            return;
        }

        $storedMotivo = $rejectionReason . "\n<!-- IDEMP:" . json_encode(['key' => $idempotencyKey, 'hash' => $semanticHash], JSON_UNESCAPED_SLASHES) . " -->";
        $actorUserId = (int)$auth['user_id'];
        $newStatus = 'REJECTED';

        $service = new \modelo\v2\journey\AdministrativeEvaluationService($conexion);
        try {
            $revResult = $service->createIncidenceRevision((int)$master['id'], [
                'expected_current_revision' => $expectedCurrentRev,
                'estado_revision' => $newStatus,
                'estado_actual' => $newStatus,
                'motivo' => $storedMotivo,
                'folio_evidencia' => $evidenceRef,
                'creado_por_usuario_id' => $actorUserId,
                'aprobado_por_usuario_id' => $actorUserId
            ]);
        } catch (\RuntimeException $e) {
            $classBD->desconectarBD($conexion);
            if (strpos($e->getMessage(), 'STALE_REVISION_CONFLICT') !== false) {
                $this->sendJsonError('STALE_REVISION_CONFLICT', $e->getMessage(), 409);
            } else {
                $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
            }
            return;
        } catch (\Throwable $e) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error al rechazar incidencia: ' . $e->getMessage(), 500);
            return;
        }

        $newRevId = (int)$revResult['id_incidencia_revision'];
        $stmtTs = $conexion->prepare("SELECT creado_at_utc FROM op_rh_v2_incidencias_revisiones WHERE id = ? LIMIT 1");
        $stmtTs->bind_param("i", $newRevId);
        $stmtTs->execute();
        $resTs = $stmtTs->get_result()->fetch_assoc();
        $stmtTs->close();

        $createdAtUtc = $resTs ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$resTs['creado_at_utc'])) : gmdate('Y-m-d\TH:i:s\Z');

        $classBD->desconectarBD($conexion);

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'data' => [
                'uuid_incidencia' => $uuidIncidencia,
                'rejected_revision_number' => (int)$revResult['revision_numero'],
                'status' => $newStatus,
                'rejected_by_user_id' => $actorUserId,
                'rejected_at_utc' => $createdAtUtc
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /api/v2/incidences/{uuid_incidencia}/revoke
     * Revoke / Cancel incident (Contract Section 9.9).
     */
    public function handleRevokeIncident(string $uuidIncidencia): void
    {
        $this->setSecurityHeaders();

        $uuidIncidencia = trim($uuidIncidencia);
        if ($uuidIncidencia === '') {
            $this->sendJsonError('INVALID_PAYLOAD', 'Identificador de incidencia ausente.', 400);
            return;
        }

        $idempotencyKey = $this->getHeaderValue('Idempotency-Key');
        if ($idempotencyKey === '') {
            $this->sendJsonError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' es requerido.", 400);
            return;
        }
        if (strlen($idempotencyKey) > 64) {
            $this->sendJsonError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' no puede exceder 64 caracteres.", 400);
            return;
        }

        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($auth['status'], $auth['error'], $auth['code']);
            return;
        }

        $authz = Portal3JwtAuthHelper::authorizeIncidenceRevocation($conexion, $auth);
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
            return;
        }

        $authorizedStations = $authz['authorized_stations'] ?? [];
        if (empty($authorizedStations)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STATION_SCOPE_DENIED', 'Sin estaciones autorizadas para su perfil en este módulo.', 403);
            return;
        }

        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true);
        if (!is_array($body)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', 'Cuerpo JSON inválido o malformado.', 400);
            return;
        }

        if (!isset($body['expected_current_revision']) || !is_numeric($body['expected_current_revision'])) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'expected_current_revision' es requerido y debe ser entero.", 400);
            return;
        }
        $expectedCurrentRev = (int)$body['expected_current_revision'];

        $revocationReason = trim((string)($body['revocation_reason'] ?? ($body['reason'] ?? '')));
        if ($revocationReason === '') {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'revocation_reason' es requerido y no puede estar vacío.", 400);
            return;
        }

        $evidenceRef = isset($body['evidence_reference']) ? trim((string)$body['evidence_reference']) : null;
        if ($evidenceRef === '') {
            $evidenceRef = null;
        }

        $stmtMaster = $conexion->prepare("SELECT id, uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia,
                                                 fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual
                                          FROM op_rh_v2_incidencias_maestro
                                          WHERE uuid_incidencia = ?
                                          LIMIT 1");
        if (!$stmtMaster) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar incidencia.', 500);
            return;
        }
        $stmtMaster->bind_param("s", $uuidIncidencia);
        $stmtMaster->execute();
        $resMaster = $stmtMaster->get_result();
        $master = $resMaster ? $resMaster->fetch_assoc() : null;
        $stmtMaster->close();

        if (!$master) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INCIDENCE_NOT_FOUND', "Incidencia con UUID '{$uuidIncidencia}' no encontrada dentro del alcance autorizado.", 404);
            return;
        }

        $stationId = $master['id_estacion'] !== null ? (int)$master['id_estacion'] : null;
        if ($stationId !== null && !in_array($stationId, $authorizedStations, true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INCIDENCE_NOT_FOUND', "Incidencia no encontrada dentro del alcance autorizado del usuario.", 404);
            return;
        }

        $semanticPayload = [
            'op' => 'revoke',
            'uuid_incidencia' => $uuidIncidencia,
            'expected_current_revision' => $expectedCurrentRev,
            'revocation_reason' => $revocationReason,
            'evidence_reference' => $evidenceRef
        ];
        $semanticHash = hash('sha256', json_encode($semanticPayload));

        $searchPattern = '%"key":"' . $conexion->real_escape_string($idempotencyKey) . '"%';
        $stmtIdemp = $conexion->prepare("SELECT r.id, r.id_incidencia_maestro, r.revision_numero, r.estado_revision, r.motivo, r.creado_at_utc,
                                                r.aprobado_por_usuario_id, m.uuid_incidencia
                                         FROM op_rh_v2_incidencias_revisiones r
                                         JOIN op_rh_v2_incidencias_maestro m ON m.id = r.id_incidencia_maestro
                                         WHERE r.motivo LIKE ?
                                         LIMIT 1");
        if ($stmtIdemp) {
            $stmtIdemp->bind_param("s", $searchPattern);
            $stmtIdemp->execute();
            $resIdemp = $stmtIdemp->get_result();
            $existingIdemp = $resIdemp ? $resIdemp->fetch_assoc() : null;
            $stmtIdemp->close();

            if ($existingIdemp) {
                if (preg_match('/<!-- IDEMP:(\{.*?\}) -->/s', (string)$existingIdemp['motivo'], $mIdemp)) {
                    $meta = json_decode($mIdemp[1], true);
                    $storedHash = $meta['hash'] ?? '';
                    if ($storedHash === $semanticHash && (string)$existingIdemp['uuid_incidencia'] === $uuidIncidencia) {
                        $classBD->desconectarBD($conexion);
                        http_response_code(200);
                        echo json_encode([
                            'status' => 'success',
                            'data' => [
                                'uuid_incidencia' => (string)$existingIdemp['uuid_incidencia'],
                                'revocation_revision_number' => (int)$existingIdemp['revision_numero'],
                                'status' => (string)$existingIdemp['estado_revision'],
                                'revoked_by_user_id' => (int)$existingIdemp['aprobado_por_usuario_id'],
                                'revoked_at_utc' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$existingIdemp['creado_at_utc']))
                            ]
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        return;
                    }
                }
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('IDEMPOTENCY_CONFLICT', "La clave de idempotencia '{$idempotencyKey}' ya fue utilizada previamente con un payload diferente.", 409);
                return;
            }
        }

        if ($expectedCurrentRev !== (int)$master['revision_actual']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STALE_REVISION_CONFLICT', "Revisión esperada ({$expectedCurrentRev}) no coincide con la revisión actual ({$master['revision_actual']}).", 409);
            return;
        }

        if (in_array((string)$master['estado_actual'], ['CANCELLED', 'REVOKED'], true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_LIFECYCLE_TRANSITION', "No es posible revocar una incidencia en estado '{$master['estado_actual']}'.", 409);
            return;
        }

        $storedMotivo = $revocationReason . "\n<!-- IDEMP:" . json_encode(['key' => $idempotencyKey, 'hash' => $semanticHash], JSON_UNESCAPED_SLASHES) . " -->";
        $actorUserId = (int)$auth['user_id'];
        $newStatus = 'CANCELLED';

        $service = new \modelo\v2\journey\AdministrativeEvaluationService($conexion);
        try {
            $revResult = $service->createIncidenceRevision((int)$master['id'], [
                'expected_current_revision' => $expectedCurrentRev,
                'estado_revision' => $newStatus,
                'estado_actual' => $newStatus,
                'motivo' => $storedMotivo,
                'folio_evidencia' => $evidenceRef,
                'creado_por_usuario_id' => $actorUserId,
                'aprobado_por_usuario_id' => $actorUserId
            ]);
        } catch (\RuntimeException $e) {
            $classBD->desconectarBD($conexion);
            if (strpos($e->getMessage(), 'STALE_REVISION_CONFLICT') !== false) {
                $this->sendJsonError('STALE_REVISION_CONFLICT', $e->getMessage(), 409);
            } else {
                $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
            }
            return;
        } catch (\Throwable $e) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error al revocar incidencia: ' . $e->getMessage(), 500);
            return;
        }

        $newRevId = (int)$revResult['id_incidencia_revision'];
        $stmtTs = $conexion->prepare("SELECT creado_at_utc FROM op_rh_v2_incidencias_revisiones WHERE id = ? LIMIT 1");
        $stmtTs->bind_param("i", $newRevId);
        $stmtTs->execute();
        $resTs = $stmtTs->get_result()->fetch_assoc();
        $stmtTs->close();

        $createdAtUtc = $resTs ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$resTs['creado_at_utc'])) : gmdate('Y-m-d\TH:i:s\Z');

        $classBD->desconectarBD($conexion);

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'data' => [
                'uuid_incidencia' => $uuidIncidencia,
                'revocation_revision_number' => (int)$revResult['revision_numero'],
                'status' => $newStatus,
                'revoked_by_user_id' => $actorUserId,
                'revoked_at_utc' => $createdAtUtc
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /api/v2/administrative-evaluations/evaluate
     * Execute administrative evaluation for a worker + workplace + local workday.
     * Specification: Section 9.11
     */
    public function handleExecuteAdministrativeEvaluation(): void
    {
        $this->setSecurityHeaders();

        // 1. Mandatory Idempotency-Key header
        $idempotencyKey = $this->getHeaderValue('Idempotency-Key');
        if ($idempotencyKey === '') {
            $this->sendJsonError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' es requerido.", 400);
            return;
        }
        if (strlen($idempotencyKey) > 64) {
            $this->sendJsonError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' no puede exceder 64 caracteres.", 400);
            return;
        }

        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        // 2. Authenticate Portal3 JWT
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($auth['status'], $auth['error'], $auth['code']);
            return;
        }

        // 3. Authorize dedicated ACL: checador-v2-incidencias-resolucion.editar = 1 OR checador-v2-incidencias-autorizacion.editar = 1
        $authz = Portal3JwtAuthHelper::authorizeAdministrativeEvaluationExecution($conexion, $auth);
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
            return;
        }

        $authorizedStations = $authz['authorized_stations'] ?? [];
        if (empty($authorizedStations)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STATION_SCOPE_DENIED', 'Sin estaciones autorizadas para su perfil en este módulo.', 403);
            return;
        }

        // 4. Parse JSON payload
        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true);
        if (!is_array($body)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', 'Cuerpo JSON inválido o malformado.', 400);
            return;
        }

        // 5. Validate parameters
        $workerId = isset($body['worker_id']) && is_numeric($body['worker_id']) ? (int)$body['worker_id'] : 0;
        if ($workerId <= 0) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'worker_id' es requerido y debe ser un entero positivo.", 400);
            return;
        }

        // Canonical worker resolution against op_rh_personal
        $stmtWorker = $conexion->prepare("SELECT id FROM op_rh_personal WHERE id = ? LIMIT 1");
        if (!$stmtWorker) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar trabajador.', 500);
            return;
        }
        $stmtWorker->bind_param("i", $workerId);
        $stmtWorker->execute();
        $resWorker = $stmtWorker->get_result();
        $workerExists = ($resWorker && $resWorker->num_rows > 0);
        $stmtWorker->close();

        if (!$workerExists) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('WORKER_NOT_FOUND', "Trabajador con ID {$workerId} no encontrado en el sistema canónico.", 404);
            return;
        }

        $workplaceId = isset($body['workplace_id']) && is_numeric($body['workplace_id']) ? (int)$body['workplace_id'] : (isset($body['station_id']) && is_numeric($body['station_id']) ? (int)$body['station_id'] : null);
        if ($workplaceId === null || $workplaceId <= 0) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'workplace_id' es requerido y debe ser un entero positivo.", 400);
            return;
        }

        if (!in_array($workplaceId, $authorizedStations, true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('STATION_SCOPE_DENIED', "Estación solicitada '{$workplaceId}' fuera del alcance asignado.", 403);
            return;
        }

        $workDate = trim((string)($body['work_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_PAYLOAD', "Campo 'work_date' es requerido en formato YYYY-MM-DD.", 400);
            return;
        }

        // Optional concurrency control
        $expectedCalcRevId = isset($body['expected_calculation_revision_id']) && is_numeric($body['expected_calculation_revision_id'])
            ? (int)$body['expected_calculation_revision_id']
            : (isset($body['calculation_revision_id']) && is_numeric($body['calculation_revision_id']) ? (int)$body['calculation_revision_id'] : null);

        // Authorize FALTA requirement check (AUTOMATIC_FALTA = NO)
        $reqAuthorizeFalta = !empty($body['authorize_falta']);
        $canAuthorizeFalta = false;
        if ($reqAuthorizeFalta) {
            // Check if user has checador-v2-incidencias-autorizacion.editar = 1
            $faltaAuthz = Portal3JwtAuthHelper::authorizeModuleByKey($conexion, $auth, 'checador-v2-incidencias-autorizacion', 'editar');
            if (!$faltaAuthz['success']) {
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('FUNCTION_PERMISSION_DENIED', 'Se requiere permiso checador-v2-incidencias-autorizacion.editar para autorizar FALTA.', 403);
                return;
            }
            $canAuthorizeFalta = true;
        }

        // 6. Idempotency check
        $semanticPayload = [
            'worker_id' => $workerId,
            'workplace_id' => $workplaceId,
            'work_date' => $workDate,
            'expected_calculation_revision_id' => $expectedCalcRevId,
            'authorize_falta' => $canAuthorizeFalta
        ];
        $semanticHash = hash('sha256', json_encode($semanticPayload));

        $cacheFile = sys_get_temp_dir() . '/checador_v2_eval_idemp_' . hash('sha256', $idempotencyKey) . '.json';
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['hash'])) {
                if ($cached['hash'] === $semanticHash) {
                    $classBD->desconectarBD($conexion);
                    http_response_code(200);
                    echo json_encode($cached['response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    return;
                } else {
                    $classBD->desconectarBD($conexion);
                    $this->sendJsonError('IDEMPOTENCY_CONFLICT', "La clave de idempotencia '{$idempotencyKey}' ya fue utilizada con un payload divergente.", 409);
                    return;
                }
            }
        }

        // 7. Execute evaluation using AdministrativeEvaluationService
        $service = new \modelo\v2\journey\AdministrativeEvaluationService($conexion);
        try {
            $evalResult = $service->evaluateShiftAdministration($workerId, $workplaceId, $workDate, [
                'expected_calculation_revision_id' => $expectedCalcRevId,
                'calculation_revision_id' => $expectedCalcRevId,
                'human_authorized_falta' => $canAuthorizeFalta,
                'enforce_p3g_human_falta_gate' => true
            ]);
        } catch (RuntimeException $e) {
            $classBD->desconectarBD($conexion);
            $msg = $e->getMessage();
            if (strpos($msg, 'CALCULATION_NOT_FOUND') !== false) {
                $this->sendJsonError('CALCULATION_NOT_FOUND', "No se encontró cálculo de jornada previo para trabajador {$workerId}, estación {$workplaceId}, fecha {$workDate}.", 404);
                return;
            }
            if (strpos($msg, 'STALE_REVISION_CONFLICT') !== false) {
                $this->sendJsonError('STALE_REVISION_CONFLICT', "La revisión de cálculo esperada no coincide con la revisión activa.", 409);
                return;
            }
            $this->sendJsonError('SERVER_ERROR', 'Error interno al ejecutar la evaluación administrativa.', 500);
            return;
        } catch (Throwable $e) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al ejecutar la evaluación administrativa.', 500);
            return;
        }

        // 8. Fetch authoritative timestamp and record details
        $evalId = (int)$evalResult['id_evaluacion'];
        $stmtFetch = $conexion->prepare("SELECT revision_numero, estado_evaluacion, estado_revision_humana, fingerprint_ejecucion, creado_at_utc FROM op_rh_v2_evaluaciones_administrativas WHERE id = ? LIMIT 1");
        if (!$stmtFetch) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error al consultar la evaluación generada.', 500);
            return;
        }
        $stmtFetch->bind_param("i", $evalId);
        $stmtFetch->execute();
        $resFetch = $stmtFetch->get_result();
        $rowFetch = $resFetch ? $resFetch->fetch_assoc() : null;
        $stmtFetch->close();
        $classBD->desconectarBD($conexion);

        if (!$rowFetch) {
            $this->sendJsonError('SERVER_ERROR', 'No fue posible recuperar la evaluación generada.', 500);
            return;
        }

        $createdAtUtc = gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$rowFetch['creado_at_utc']));

        $responseData = [
            'status' => 'success',
            'data' => [
                'worker_id' => $workerId,
                'workplace_id' => $workplaceId,
                'work_date' => $workDate,
                'revision_number' => (int)$rowFetch['revision_numero'],
                'administrative_status' => (string)$rowFetch['estado_evaluacion'],
                'human_review_status' => (string)$rowFetch['estado_revision_humana'],
                'fingerprint' => (string)$rowFetch['fingerprint_ejecucion'],
                'policy_rule_version' => null,
                'created_at_utc' => $createdAtUtc
            ]
        ];

        // Cache response for idempotent replay
        file_put_contents($cacheFile, json_encode([
            'key' => $idempotencyKey,
            'hash' => $semanticHash,
            'response' => $responseData
        ], JSON_UNESCAPED_SLASHES));

        http_response_code(200);
        echo json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Validate and freeze the policy/check provenance before the incidence transaction begins. */
    private function validatePunchAttendanceCheck(
        mysqli $db,
        array $body,
        int $workerId,
        int $workplaceId,
        string $scope,
        string $startDate,
        string $endDate,
        DateTimeImmutable $requestReceivedAtUtc
    ): array {
        $checkDate = is_string($body['check_date'] ?? null) ? trim($body['check_date']) : '';
        $checkKind = is_string($body['check_kind'] ?? null) ? trim($body['check_kind']) : '';
        $nature = is_string($body['nature'] ?? null) ? trim($body['nature']) : '';
        $validKinds = ['ENTRY', 'LUNCH_EXIT', 'LUNCH_RETURN', 'FINAL_EXIT'];
        if (!PunchIncidenceDeadlineService::isCivilDate($checkDate)
            || !in_array($checkKind, $validKinds, true)
            || !in_array($nature, ['LATENESS', 'OMISSION'], true)
            || $scope !== 'WORKER' || $startDate !== $checkDate || $endDate !== $checkDate) {
            return ['ok' => false, 'error' => 'INVALID_PUNCH_CHECK'];
        }

        try {
            (new ShiftWorkerMembershipService($db))->assertLocalDateMembership($workerId, $workplaceId, $checkDate);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'WORKER_MEMBERSHIP_UNVERIFIED'];
        }

        try {
            $timezoneId = (new WorkplaceTimezoneAuthorityService($db))->resolve($workplaceId, $checkDate);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'WORKPLACE_TIMEZONE_UNRESOLVED'];
        }
        $timezone = new DateTimeZone($timezoneId);
        $now = $requestReceivedAtUtc->setTimezone($timezone);
        if ($checkDate > $now->format('Y-m-d')) {
            return ['ok' => false, 'error' => 'CHECK_DATE_IN_FUTURE'];
        }

        $stmtPolicy = $db->prepare(
            'SELECT id, policy_version, effective_from_local_date, timezone_id, '
            . 'retardo_tolerance_minutes, incidence_deadline_days '
            . 'FROM op_rh_v2_attendance_policy_versions '
            . 'WHERE workplace_id = ? AND effective_from_local_date <= ? '
            . 'ORDER BY effective_from_local_date DESC, policy_version DESC LIMIT 1'
        );
        if (!$stmtPolicy) {
            return ['ok' => false, 'error' => 'ATTENDANCE_POLICY_UNAVAILABLE'];
        }
        $stmtPolicy->bind_param('is', $workplaceId, $checkDate);
        if (!$stmtPolicy->execute()) {
            $stmtPolicy->close();
            return ['ok' => false, 'error' => 'ATTENDANCE_POLICY_UNAVAILABLE'];
        }
        $policy = $stmtPolicy->get_result()->fetch_assoc();
        $stmtPolicy->close();
        if ($policy === null) {
            return ['ok' => false, 'error' => 'ATTENDANCE_POLICY_NOT_VERSIONED'];
        }
        $policyTimezone = is_string($policy['timezone_id'] ?? null) ? trim((string)$policy['timezone_id']) : '';
        if ($policyTimezone === '' || $policyTimezone !== $timezoneId
            || !in_array($policyTimezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            return ['ok' => false, 'error' => 'ATTENDANCE_POLICY_INVALID'];
        }
        $deadlineDays = (int)$policy['incidence_deadline_days'];
        if ($deadlineDays < 0 || $deadlineDays > 30
            || (int)$policy['retardo_tolerance_minutes'] < 0
            || (int)$policy['retardo_tolerance_minutes'] > 120) {
            return ['ok' => false, 'error' => 'ATTENDANCE_POLICY_INVALID'];
        }

        try {
            $deadline = PunchIncidenceDeadlineService::evaluate($checkDate, $deadlineDays, $timezoneId, $now);
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'ATTENDANCE_POLICY_INVALID'];
        }
        $sourceDeviceId = null;
        $sourceEventUuid = null;
        if ($nature === 'LATENESS') {
            $rawDeviceId = $body['source_event_device_id'] ?? null;
            $sourceDeviceId = (is_int($rawDeviceId) || (is_string($rawDeviceId) && ctype_digit($rawDeviceId)))
                ? (int)$rawDeviceId : 0;
            $sourceEventUuid = is_string($body['source_event_uuid'] ?? null)
                ? trim($body['source_event_uuid']) : '';
            if ($sourceDeviceId < 1 || $sourceEventUuid === '' || strlen($sourceEventUuid) > 64) {
                return ['ok' => false, 'error' => 'INVALID_PUNCH_CHECK'];
            }
            $stmtEvent = $db->prepare(
                'SELECT id_personal_resuelto, id_estacion_resuelta, ocurrido_at_utc '
                . 'FROM op_rh_v2_marcaciones_crudas WHERE id_dispositivo = ? AND event_uuid = ? LIMIT 1'
            );
            if (!$stmtEvent) {
                return ['ok' => false, 'error' => 'SOURCE_EVENT_NOT_MATCHED'];
            }
            $stmtEvent->bind_param('is', $sourceDeviceId, $sourceEventUuid);
            $stmtEvent->execute();
            $event = $stmtEvent->get_result()->fetch_assoc();
            $stmtEvent->close();
            if ($event === null || (int)($event['id_personal_resuelto'] ?? 0) !== $workerId
                || (int)($event['id_estacion_resuelta'] ?? 0) < 1
                || !is_string($event['ocurrido_at_utc'] ?? null)
                || trim((string)$event['ocurrido_at_utc']) === '') {
                return ['ok' => false, 'error' => 'SOURCE_EVENT_NOT_MATCHED'];
            }
            $eventLocalityId = WorkplacePhysicalStationResolver::resolveCanonicalLocality(
                $db,
                (int)$event['id_estacion_resuelta']
            );
            if ($eventLocalityId !== $workplaceId) {
                return ['ok' => false, 'error' => 'SOURCE_EVENT_NOT_MATCHED'];
            }
            try {
                $eventLocalDate = (new DateTimeImmutable((string)$event['ocurrido_at_utc'], new DateTimeZone('UTC')))
                    ->setTimezone($timezone)->format('Y-m-d');
            } catch (Throwable) {
                return ['ok' => false, 'error' => 'SOURCE_EVENT_NOT_MATCHED'];
            }
            if ($eventLocalDate !== $checkDate) {
                return ['ok' => false, 'error' => 'SOURCE_EVENT_NOT_MATCHED'];
            }
        } elseif (array_key_exists('source_event_device_id', $body) || array_key_exists('source_event_uuid', $body)) {
            return ['ok' => false, 'error' => 'INVALID_PUNCH_CHECK'];
        }

        return ['ok' => true, 'data' => [
            'check_date' => $checkDate,
            'check_kind' => $checkKind,
            'nature' => $nature,
            'policy_version_id' => (int)$policy['id'],
            'policy_version' => (int)$policy['policy_version'],
            'deadline_days' => $deadlineDays,
            'deadline_expired' => $deadline['expired'],
            'source_event_device_id' => $sourceDeviceId,
            'source_event_uuid' => $sourceEventUuid,
            'timezone_id' => $timezoneId,
            'cutoff_local' => $deadline['cutoff_local'],
        ]];
    }

    /** @return array<string,mixed>|null */
    private function readPunchCheckReference(mysqli $db, int $masterId): ?array
    {
        $stmt = $db->prepare(
            'SELECT c.fecha_chequeo_local, c.tipo_chequeo, c.naturaleza, c.source_event_device_id, '
            . 'c.source_event_uuid, c.policy_version_id, p.policy_version, c.timezone_id, c.limite_registro_local '
            . 'FROM op_rh_v2_incidencia_chequeos c '
            . 'JOIN op_rh_v2_attendance_policy_versions p ON p.id = c.policy_version_id '
            . 'WHERE c.id_incidencia_maestro = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $masterId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return null;
        }
        return [
            'check_date' => (string)$row['fecha_chequeo_local'],
            'check_kind' => (string)$row['tipo_chequeo'],
            'nature' => (string)$row['naturaleza'],
            'source_event_device_id' => $row['source_event_device_id'] === null ? null : (int)$row['source_event_device_id'],
            'source_event_uuid' => $row['source_event_uuid'] === null ? null : (string)$row['source_event_uuid'],
            'policy_version_id' => (int)$row['policy_version_id'],
            'policy_version' => (int)$row['policy_version'],
            'timezone_id' => (string)$row['timezone_id'],
            'cutoff_local' => (string)$row['limite_registro_local'],
        ];
    }

    private function punchCheckErrorMessage(string $code): string
    {
        return match ($code) {
            'CHECK_DATE_IN_FUTURE' => 'La fecha del chequeo no puede estar en el futuro.',
            'INCIDENCE_DEADLINE_EXPIRED' => 'El plazo local para registrar esta incidencia ya venció.',
            'ATTENDANCE_POLICY_NOT_VERSIONED' => 'No existe una política V2 versionada para la fecha del chequeo.',
            'WORKER_MEMBERSHIP_UNVERIFIED' => 'No se pudo confirmar la adscripción del trabajador para esa fecha y localidad.',
            'SOURCE_EVENT_NOT_MATCHED' => 'La marcación indicada no coincide con el trabajador, la localidad y la fecha del chequeo.',
            'WORKPLACE_TIMEZONE_UNRESOLVED' => 'No se pudo confirmar la zona horaria vigente de la localidad.',
            'ATTENDANCE_POLICY_UNAVAILABLE' => 'No se pudo consultar la política versionada de la localidad.',
            'ATTENDANCE_POLICY_INVALID' => 'La política versionada contiene valores fuera de los límites autorizados.',
            default => 'Los datos del chequeo de marcación no son válidos.',
        };
    }

    private function setSecurityHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    private function sendJsonError(string $status, string $message, int $statusCode): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'code' => $statusCode,
            'error' => $status,
            'message' => $message
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
