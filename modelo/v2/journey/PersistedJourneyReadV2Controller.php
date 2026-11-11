<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/BD.php';
require_once __DIR__ . '/../Portal3JwtAuthHelper.php';
require_once __DIR__ . '/../shift/JourneyReadAuthorizationService.php';
require_once __DIR__ . '/../shift/WorkplaceTimezoneAuthorityService.php';

/** Read-only API boundary for already-persisted Journey calculations. */
final class PersistedJourneyReadV2Controller
{
    private const MAX_RANGE_DAYS = 62;
    private const DEFAULT_PAGE_SIZE = 50;
    private const MAX_PAGE_SIZE = 200;

    public function handleList(): void
    {
        $this->headers();
        $bd = new BD();
        $db = $bd->conectarBD();
        try {
            $auth = Portal3JwtAuthHelper::validateToken($db, $_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if (!($auth['valid'] ?? false)) {
                $this->error((int)($auth['code'] ?? 401), (string)($auth['status'] ?? 'UNAUTHORIZED'));
                return;
            }
            $authorization = JourneyReadAuthorizationService::authorize($db, $auth);
            if (!($authorization['success'] ?? false)) {
                $this->error((int)($authorization['code'] ?? 403), (string)($authorization['status'] ?? 'FUNCTION_PERMISSION_DENIED'));
                return;
            }

            $workplaceId = $this->positiveInt($_GET['workplace_id'] ?? null);
            $workerRaw = $_GET['worker_id'] ?? null;
            $workerProvided = $workerRaw !== null && !(is_string($workerRaw) && trim($workerRaw) === '');
            $workerId = $workerProvided ? $this->positiveInt($workerRaw) : null;
            $from = trim((string)($_GET['from'] ?? ''));
            $to = trim((string)($_GET['to'] ?? ''));
            $status = isset($_GET['status']) && trim((string)$_GET['status']) !== ''
                ? strtoupper(trim((string)$_GET['status'])) : null;
            $page = $this->positiveInt($_GET['page'] ?? 1);
            $perPage = $this->positiveInt($_GET['per_page'] ?? self::DEFAULT_PAGE_SIZE);
            $allowedStatuses = ['CALCULATED', 'PARTIAL_DATA', 'PENDING_SYNC', 'AMBIGUOUS', 'WORKPLACE_MISMATCH',
                'UNMATCHED_NO_PUNCHES', 'INVALID_INTERVAL', 'DAY_OFF_RECORDED', 'UNSCHEDULED_RECORDED', 'UNKNOWN_SCHEDULE'];
            if ($workplaceId === null || ($workerProvided && $workerId === null) || $page === null || $perPage === null
                || $perPage > self::MAX_PAGE_SIZE || !$this->validDate($from) || !$this->validDate($to) || $to < $from
                || (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days >= self::MAX_RANGE_DAYS
                || ($status !== null && !in_array($status, $allowedStatuses, true))) {
                $this->error(400, 'INVALID_QUERY');
                return;
            }
            $stations = JourneyReadAuthorizationService::restrictStationScope(
                $authorization['authorized_stations'] ?? [], $workplaceId
            );
            if ($stations === null) {
                $this->error(403, 'STATION_SCOPE_DENIED');
                return;
            }
            if ($workerId !== null) {
                $worker = $this->one($db, 'SELECT id FROM op_rh_personal WHERE id = ? LIMIT 1', 'i', [$workerId]);
                if ($worker === null) {
                    $this->error(404, 'WORKER_NOT_FOUND');
                    return;
                }
            }

            $where = 'c.id_estacion = ? AND c.fecha_jornada_local BETWEEN ? AND ?';
            $params = [$workplaceId, $from, $to];
            $types = 'iss';
            if ($workerId !== null) { $where .= ' AND c.id_personal = ?'; $params[] = $workerId; $types .= 'i'; }
            if ($status !== null) { $where .= ' AND c.estado_calculo = ?'; $params[] = $status; $types .= 's'; }
            $latest = 'SELECT MAX(id) latest_id FROM op_rh_v2_calculos_jornada WHERE id_estacion = ? AND fecha_jornada_local BETWEEN ? AND ? GROUP BY id_jornada_programada';
            $countSql = "SELECT COUNT(*) total FROM op_rh_v2_calculos_jornada c JOIN ($latest) latest ON latest.latest_id = c.id WHERE $where";
            $countParams = [$workplaceId, $from, $to, ...$params];
            $countTypes = 'iss' . $types;
            $count = $this->one($db, $countSql, $countTypes, $countParams);
            if ($count === null) { $this->error(503, 'SOURCE_UNAVAILABLE'); return; }
            $total = (int)$count['total'];

            // Coverage is measured against API-owned scheduled journeys, not inferred from
            // the absence of result rows. The optional status filter applies only to the
            // returned evaluation collection, not to these coverage counts.
            $scheduleWhere = 'id_estacion = ? AND fecha_jornada_local BETWEEN ? AND ?';
            $scheduleParams = [$workplaceId, $from, $to];
            $scheduleTypes = 'iss';
            if ($workerId !== null) { $scheduleWhere .= ' AND id_personal = ?'; $scheduleParams[] = $workerId; $scheduleTypes .= 'i'; }
            $scheduledCount = $this->one($db, "SELECT COUNT(*) total FROM op_rh_v2_jornadas_programadas WHERE $scheduleWhere", $scheduleTypes, $scheduleParams);
            $coverageLatestWhere = 'id_estacion = ? AND fecha_jornada_local BETWEEN ? AND ?';
            $coverageParams = [$workplaceId, $from, $to];
            $coverageTypes = 'iss';
            if ($workerId !== null) { $coverageLatestWhere .= ' AND id_personal = ?'; $coverageParams[] = $workerId; $coverageTypes .= 'i'; }
            $coverageLatest = "SELECT MAX(id) latest_id FROM op_rh_v2_calculos_jornada WHERE $coverageLatestWhere GROUP BY id_jornada_programada";
            $evaluatedCount = $this->one($db, "SELECT COUNT(*) total FROM ($coverageLatest) evaluated", $coverageTypes, $coverageParams);
            if ($scheduledCount === null || $evaluatedCount === null) { $this->error(503, 'SOURCE_UNAVAILABLE'); return; }
            $scheduledTotal = (int)$scheduledCount['total'];
            $evaluatedTotal = (int)$evaluatedCount['total'];
            $coverageConsistent = $evaluatedTotal <= $scheduledTotal;
            $coverageStatus = !$coverageConsistent ? 'UNDETERMINED'
                : ($scheduledTotal === 0 ? 'NOT_SCHEDULED'
                    : ($evaluatedTotal === 0 ? 'NOT_EVALUATED'
                        : ($evaluatedTotal < $scheduledTotal ? 'PARTIALLY_EVALUATED' : 'EVALUATED')));
            $notEvaluatedTotal = $coverageConsistent ? $scheduledTotal - $evaluatedTotal : null;
            $offset = ($page - 1) * $perPage;

            $sql = "SELECT c.id AS internal_calc_id, c.id_jornada_programada, c.id_pareo_jornada,
                    c.id_personal, p.nombre_completo worker_name, p.no_colaborador employee_number,
                    c.id_estacion workplace_id, l.localidad workplace_name, c.fecha_jornada_local work_date,
                    c.estado_calculo status, c.revision_numero revision_number,
                    c.version_algoritmo_calculo algorithm_version, c.segundos_programados_totales scheduled_seconds,
                    c.segundos_trabajados_totales worked_seconds, c.segundos_candidatos_fuera_horario outside_seconds,
                    c.fingerprint_ejecucion evaluation_ref, c.creado_at_utc calculated_at_utc,
                    jp.tipo_jornada schedule_type, jp.scheduled_start_utc, jp.scheduled_end_utc,
                    jp.timezone_local timezone, tp.nombre shift_name,
                    ps.id_marcacion_entrada entry_id, ps.id_marcacion_salida exit_id,
                    me.ocurrido_at_utc entry_utc, me.source_timestamp_raw entry_raw,
                    me.metodo_captura entry_method, me.id_dispositivo entry_device_id, me.event_uuid entry_uuid,
                    ms.ocurrido_at_utc exit_utc, ms.source_timestamp_raw exit_raw,
                    ms.metodo_captura exit_method, ms.id_dispositivo exit_device_id, ms.event_uuid exit_uuid
                FROM op_rh_v2_calculos_jornada c
                JOIN ($latest) latest ON latest.latest_id = c.id
                LEFT JOIN op_rh_v2_jornadas_programadas jp ON jp.id = c.id_jornada_programada
                LEFT JOIN op_rh_personal p ON p.id = c.id_personal
                LEFT JOIN op_rh_localidades l ON l.id = c.id_estacion
                LEFT JOIN op_rh_v2_turnos_plantillas tp ON tp.id = jp.id_plantilla_origen
                LEFT JOIN op_rh_v2_pareos_segmentos ps ON ps.id_pareo_jornada = c.id_pareo_jornada AND ps.ordinal_segmento = 1
                LEFT JOIN op_rh_v2_marcaciones_crudas me ON me.id = ps.id_marcacion_entrada
                LEFT JOIN op_rh_v2_marcaciones_crudas ms ON ms.id = ps.id_marcacion_salida
                WHERE $where ORDER BY c.fecha_jornada_local DESC, p.nombre_completo ASC, c.fingerprint_ejecucion ASC LIMIT ? OFFSET ?";
            $rowsParams = [$workplaceId, $from, $to, ...$params, $perPage, $offset];
            $rowsTypes = 'iss' . $types . 'ii';
            $rows = $this->all($db, $sql, $rowsTypes, $rowsParams);
            if ($rows === null) { $this->error(503, 'SOURCE_UNAVAILABLE'); return; }

            $journeys = [];
            foreach ($rows as $row) {
                try { $timezone = (new WorkplaceTimezoneAuthorityService($db))->resolve($workplaceId, (string)$row['work_date']); }
                catch (Throwable) { $timezone = null; }
                $calcId = (int)$row['internal_calc_id'];
                $pareoId = (int)($row['id_pareo_jornada'] ?? 0);
                $programadaId = (int)($row['id_jornada_programada'] ?? 0);
                $segments = $this->segments($db, $calcId, $timezone);
                $punches = $this->punches($db, $pareoId);
                $incidences = $this->appliedIncidences($db, $programadaId);
                if ($segments === null || $punches === null || $incidences === null) {
                    $this->error(503, 'SOURCE_UNAVAILABLE'); return;
                }
                $isPartial = $timezone === null;
                $journeys[] = [
                    'evaluation_ref' => (string)$row['evaluation_ref'],
                    'worker_id' => (int)$row['id_personal'],
                    'worker_name' => (string)($row['worker_name'] ?? ''),
                    'employee_number' => $row['employee_number'] !== null ? (string)$row['employee_number'] : null,
                    'workplace_id' => (int)$row['workplace_id'], 'workplace_name' => (string)($row['workplace_name'] ?? ''),
                    'work_date' => (string)$row['work_date'], 'status' => (string)$row['status'],
                    'revision_number' => (int)$row['revision_number'], 'algorithm_version' => (string)$row['algorithm_version'],
                    'scheduled_seconds' => $row['scheduled_seconds'] !== null ? (int)$row['scheduled_seconds'] : null,
                    'worked_seconds' => $row['worked_seconds'] !== null ? (int)$row['worked_seconds'] : null,
                    'outside_seconds' => $row['outside_seconds'] !== null ? (int)$row['outside_seconds'] : null,
                    'calculated_at_utc' => (string)$row['calculated_at_utc'], 'timezone' => $timezone,
                    'schedule_type' => (string)($row['schedule_type'] ?? ''),
                    'scheduled_start_utc' => $row['scheduled_start_utc'] ?? null,
                    'scheduled_end_utc' => $row['scheduled_end_utc'] ?? null,
                    'shift_name' => (string)($row['shift_name'] ?? ''),
                    'entry_utc' => $row['entry_utc'] ?? null, 'entry_raw' => $row['entry_raw'] ?? null,
                    'entry_method' => $row['entry_method'] ?? null, 'entry_device_id' => $row['entry_device_id'] !== null ? (int)$row['entry_device_id'] : null,
                    'entry_event_uuid' => $row['entry_uuid'] ?? null,
                    'exit_utc' => $row['exit_utc'] ?? null, 'exit_raw' => $row['exit_raw'] ?? null,
                    'exit_method' => $row['exit_method'] ?? null, 'exit_device_id' => $row['exit_device_id'] !== null ? (int)$row['exit_device_id'] : null,
                    'exit_event_uuid' => $row['exit_uuid'] ?? null,
                    'segments' => $segments, 'raw_punches' => $punches, 'incidences' => $incidences,
                    'projection_state' => $isPartial ? 'PARTIAL' : 'COMPLETE',
                ];
            }
            $totalPages = $total === 0 ? 0 : (int)ceil($total / $perPage);
            $pagePartial = count(array_filter($journeys, static fn(array $item): bool => ($item['projection_state'] ?? '') !== 'COMPLETE')) > 0;
            $pagePartial = $pagePartial || !$coverageConsistent;
            $this->success([
                'query' => ['workplace_id' => $workplaceId, 'from' => $from, 'to' => $to, 'worker_id' => $workerId, 'status' => $status,
                    'page' => $page, 'per_page' => $perPage],
                'pagination' => ['page' => $page, 'per_page' => $perPage, 'total_records' => $total,
                    'total_pages' => $totalPages, 'has_more' => $page < $totalPages,
                    'complete' => ($total === 0 || $page >= $totalPages) && !$pagePartial, 'consistency' => 'PER_PAGE_READ'],
                'projection_status' => !$coverageConsistent ? 'PARTIAL' : ($total === 0 ? 'EMPTY' : ($pagePartial ? 'PARTIAL' : 'COMPLETE')),
                'evaluation_coverage' => [
                    'scheduled_journeys' => $scheduledTotal,
                    'evaluated_journeys' => $evaluatedTotal,
                    'not_evaluated_journeys' => $notEvaluatedTotal,
                    'status' => $coverageStatus,
                    'evidence' => 'CANONICAL_SCHEDULE_AND_LATEST_PERSISTED_CALCULATION_COUNTS',
                ],
                'journeys' => $journeys,
            ]);
        } catch (Throwable) {
            $this->error(503, 'SOURCE_UNAVAILABLE');
        } finally {
            $bd->desconectarBD($db);
        }
    }

    private function segments(mysqli $db, int $calcId, ?string $timezone): ?array
    {
        $rows = $this->all($db, 'SELECT cs.ordinal_segmento, cs.tipo_segmento, cs.estado_segmento,
            cs.segundos_programados, cs.segundos_trabajados, cs.delta_llegada_segundos, cs.delta_salida_segundos,
            ps.estado_segmento pareo_estado_segmento, me.ocurrido_at_utc seg_entry_utc, me.source_timestamp_raw seg_entry_raw,
            me.event_uuid seg_entry_uuid, ms.ocurrido_at_utc seg_exit_utc, ms.source_timestamp_raw seg_exit_raw, ms.event_uuid seg_exit_uuid
            FROM op_rh_v2_calculos_segmentos cs LEFT JOIN op_rh_v2_pareos_segmentos ps ON ps.id = cs.id_pareo_segmento
            LEFT JOIN op_rh_v2_marcaciones_crudas me ON me.id = ps.id_marcacion_entrada
            LEFT JOIN op_rh_v2_marcaciones_crudas ms ON ms.id = ps.id_marcacion_salida
            WHERE cs.id_calculo_jornada = ? ORDER BY cs.ordinal_segmento ASC', 'i', [$calcId]);
        if ($rows === null) return null;
        return array_map(static function (array $r): array {
            foreach (['ordinal_segmento','segundos_programados','segundos_trabajados','delta_llegada_segundos','delta_salida_segundos'] as $key) {
                if ($r[$key] !== null) $r[$key] = (int)$r[$key];
            }
            return $r;
        }, $rows);
    }

    private function punches(mysqli $db, int $pareoId): ?array
    {
        if ($pareoId < 1) return [];
        return $this->all($db, 'SELECT pma.rol_marcacion, mc.event_uuid, mc.ocurrido_at_utc, mc.source_timestamp_raw,
            mc.id_dispositivo, mc.metodo_captura, mc.processing_status FROM op_rh_v2_pareos_marcaciones_asociadas pma
            JOIN op_rh_v2_marcaciones_crudas mc ON mc.id = pma.id_marcacion_cruda
            WHERE pma.id_pareo_jornada = ? ORDER BY mc.ocurrido_at_utc ASC, mc.event_uuid ASC', 'i', [$pareoId]);
    }

    private function appliedIncidences(mysqli $db, int $programadaId): ?array
    {
        if ($programadaId < 1) return [];
        return $this->all($db, 'SELECT ia.fecha_jornada_local, ia.estado_aplicacion, im.uuid_incidencia,
            im.tipo_incidencia, im.estado_actual FROM op_rh_v2_incidencias_aplicaciones ia
            LEFT JOIN op_rh_v2_incidencias_revisiones ir ON ir.id = ia.id_incidencia_revision
            LEFT JOIN op_rh_v2_incidencias_maestro im ON im.id = ir.id_incidencia_maestro
            WHERE ia.id_jornada_programada = ? ORDER BY ia.fecha_jornada_local ASC, im.uuid_incidencia ASC', 'i', [$programadaId]);
    }

    private function one(mysqli $db, string $sql, string $types, array $params): ?array
    {
        $rows = $this->all($db, $sql, $types, $params, 1);
        return $rows === null ? null : ($rows[0] ?? null);
    }

    private function all(mysqli $db, string $sql, string $types, array $params, ?int $limit = null): ?array
    {
        $stmt = $db->prepare($sql);
        if (!$stmt) return null;
        if ($types !== '') {
            $bind = [$types];
            foreach ($params as $i => &$value) $bind[] = &$value;
            unset($value);
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $result = $stmt->get_result();
        if (!$result) { $stmt->close(); return null; }
        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
            if ($limit !== null && count($rows) >= $limit) break;
        }
        $stmt->close();
        return $rows;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (!(is_int($value) || (is_string($value) && ctype_digit($value)))) return null;
        $n = (int)$value;
        return $n > 0 ? $n : null;
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function headers(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    private function error(int $code, string $error): void
    {
        http_response_code($code);
        echo json_encode(['status' => 'error', 'error' => ['code' => $error]], JSON_UNESCAPED_SLASHES);
    }

    private function success(array $data): void
    {
        http_response_code(200);
        echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
