<?php

declare(strict_types=1);

require_once __DIR__ . '/../BD.php';
require_once __DIR__ . '/Portal3JwtAuthHelper.php';
require_once __DIR__ . '/shift/WorkplaceTimezoneAuthorityService.php';

/** Paginated, read-only, canonical workplace incidence projection for C02. */
final class IncidenceCollectionReadV2Controller
{
    private const MAX_RANGE_DAYS = 93;
    private const DEFAULT_PAGE_SIZE = 50;
    private const MAX_PAGE_SIZE = 200;
    private const CATEGORIES = ['VACATION','PERMISSION','DISABILITY','AUTHORIZED_LATENESS','AUTHORIZED_EARLY_DEPARTURE','OFFICIAL_MISSION','PUNCH_ATTENDANCE','JUSTIFICATION'];
    private const STATUSES = ['ACTIVE','PENDING_APPROVAL','PENDING_REVIEW','REVIEW_REQUIRED','AUTHORIZED','RESOLVED','SUPERSEDED','CANCELLED','REJECTED','REVOKED'];

    public function handleList(string $workplaceRaw): void
    {
        $this->headers();
        $bd = new BD();
        $db = $bd->conectarBD();
        try {
            $auth = Portal3JwtAuthHelper::validateToken($db, $_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if (!($auth['valid'] ?? false)) { $this->error((int)($auth['code'] ?? 401), (string)($auth['status'] ?? 'UNAUTHORIZED')); return; }
            $authz = Portal3JwtAuthHelper::authorizeIncidences($db, $auth, 'leer');
            if (!($authz['success'] ?? false)) { $this->error((int)($authz['code'] ?? 403), (string)($authz['status'] ?? 'FUNCTION_PERMISSION_DENIED')); return; }
            $workplaceId = $this->positiveInt($workplaceRaw);
            $authorized = array_map('intval', $authz['authorized_stations'] ?? []);
            if ($workplaceId === null) { $this->error(400, 'INVALID_QUERY'); return; }
            if (!in_array($workplaceId, $authorized, true)) { $this->error(403, 'STATION_SCOPE_DENIED'); return; }

            $from = trim((string)($_GET['from'] ?? ''));
            $to = trim((string)($_GET['to'] ?? ''));
            $workerRaw = $_GET['worker_id'] ?? null;
            $workerProvided = $workerRaw !== null && !(is_string($workerRaw) && trim($workerRaw) === '');
            $workerId = $workerProvided ? $this->positiveInt($workerRaw) : null;
            $category = isset($_GET['category']) && trim((string)$_GET['category']) !== '' ? strtoupper(trim((string)$_GET['category'])) : null;
            $status = isset($_GET['status']) && trim((string)$_GET['status']) !== '' ? strtoupper(trim((string)$_GET['status'])) : null;
            $page = $this->positiveInt($_GET['page'] ?? 1);
            $perPage = $this->positiveInt($_GET['per_page'] ?? self::DEFAULT_PAGE_SIZE);
            if (!$this->validDate($from) || !$this->validDate($to) || $to < $from
                || (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days >= self::MAX_RANGE_DAYS
                || ($workerProvided && $workerId === null) || $page === null || $perPage === null || $perPage > self::MAX_PAGE_SIZE
                || ($category !== null && !in_array($category, self::CATEGORIES, true))
                || ($status !== null && !in_array($status, self::STATUSES, true))) {
                $this->error(400, 'INVALID_QUERY'); return;
            }
            if ($workerId !== null && !$this->one($db, 'SELECT id FROM op_rh_personal WHERE id = ? LIMIT 1', 'i', [$workerId])) {
                $this->error(404, 'WORKER_NOT_FOUND'); return;
            }

            $where = 'm.id_estacion = ? AND m.fecha_inicio_local <= ? AND m.fecha_fin_local >= ?';
            $params = [$workplaceId, $to, $from]; $types = 'iss';
            if ($workerId !== null) { $where .= ' AND m.id_personal = ?'; $params[] = $workerId; $types .= 'i'; }
            if ($category !== null) { $where .= ' AND m.tipo_incidencia = ?'; $params[] = $category; $types .= 's'; }
            if ($status !== null) { $where .= ' AND m.estado_actual = ?'; $params[] = $status; $types .= 's'; }

            $count = $this->one($db, "SELECT COUNT(*) total FROM op_rh_v2_incidencias_maestro m WHERE $where", $types, $params);
            if ($count === null) { $this->error(503, 'SOURCE_UNAVAILABLE'); return; }
            $total = (int)$count['total']; $offset = ($page - 1) * $perPage;
            $sql = "SELECT m.uuid_incidencia, m.id_personal worker_id,
                    m.id_estacion workplace_id, m.tipo_incidencia category, m.alcance_incidencia scope,
                    m.fecha_inicio_local start_date, m.fecha_fin_local end_date, m.estado_actual current_status,
                    m.revision_actual current_revision, m.creado_at_utc created_at_utc, m.actualizado_at_utc updated_at_utc,
                    p.nombre_completo worker_name, p.no_colaborador employee_number, l.localidad workplace_name,
                    r.id internal_revision_id, r.motivo reason, r.folio_evidencia evidence_reference,
                    r.creado_por_usuario_id created_by_user_id, r.aprobado_por_usuario_id approved_by_user_id,
                    r.creado_at_utc revision_created_at_utc
                FROM op_rh_v2_incidencias_maestro m
                LEFT JOIN op_rh_personal p ON p.id = m.id_personal
                LEFT JOIN op_rh_localidades l ON l.id = m.id_estacion
                LEFT JOIN op_rh_v2_incidencias_revisiones r ON r.id_incidencia_maestro = m.id AND r.revision_numero = m.revision_actual
                WHERE $where ORDER BY m.fecha_inicio_local DESC, p.nombre_completo ASC, m.uuid_incidencia ASC LIMIT ? OFFSET ?";
            $rows = $this->all($db, $sql, $types . 'ii', [...$params, $perPage, $offset]);
            if ($rows === null) { $this->error(503, 'SOURCE_UNAVAILABLE'); return; }

            $items = []; $partial = false;
            foreach ($rows as $row) {
                $item = $this->projectOne($db, $row, $workplaceId);
                if ($item === null) { $partial = true; continue; }
                if (($item['projection_state'] ?? '') !== 'COMPLETE') $partial = true;
                $items[] = $item;
            }
            $totalPages = $total === 0 ? 0 : (int)ceil($total / $perPage);
            $hasMore = $page < $totalPages;
            $this->success([
                'query' => ['workplace_id' => $workplaceId, 'from' => $from, 'to' => $to,
                    'worker_id' => $workerId, 'category' => $category, 'status' => $status,
                    'page' => $page, 'per_page' => $perPage],
                'pagination' => ['page' => $page, 'per_page' => $perPage, 'total_records' => $total,
                    'total_pages' => $totalPages, 'has_more' => $hasMore, 'complete' => !$hasMore && !$partial,
                    'consistency' => 'PER_PAGE_READ'],
                'projection_status' => $total === 0 ? 'EMPTY' : ($partial ? 'PARTIAL' : 'COMPLETE'),
                'incidences' => $items,
            ]);
        } catch (Throwable) {
            $this->error(503, 'SOURCE_UNAVAILABLE');
        } finally {
            $bd->desconectarBD($db);
        }
    }

    private function projectOne(mysqli $db, array $row, int $workplaceId): ?array
    {
        $currentRevisionId = (int)($row['internal_revision_id'] ?? 0);

        $applications = $currentRevisionId > 0 ? $this->all($db, 'SELECT a.id internal_application_id,
            a.id_jornada_programada internal_schedule_id, a.fecha_jornada_local work_date, a.estado_aplicacion application_status,
            jp.tipo_jornada schedule_type, jp.scheduled_start_utc, jp.scheduled_end_utc,
            tp.nombre shift_name FROM op_rh_v2_incidencias_aplicaciones a
            LEFT JOIN op_rh_v2_jornadas_programadas jp ON jp.id = a.id_jornada_programada
            LEFT JOIN op_rh_v2_turnos_plantillas tp ON tp.id = jp.id_plantilla_origen
            WHERE a.id_incidencia_revision = ? ORDER BY a.fecha_jornada_local ASC', 'i', [$currentRevisionId]) : [];
        if ($applications === null) return null;

        $applicationIds = array_map(static fn(array $r): int => (int)$r['internal_application_id'], $applications);
        $evaluations = [];
        if ($applicationIds !== []) {
            $marks = implode(',', array_fill(0, count($applicationIds), '?'));
            $evalRows = $this->all($db, "SELECT ei.id_incidencia_aplicacion, ea.revision_numero,
                ea.version_algoritmo_admin, ea.version_politica, ea.estado_evaluacion, ea.estado_revision_humana,
                ea.fingerprint_ejecucion, ea.creado_at_utc FROM op_rh_v2_evaluaciones_incidencias ei
                JOIN op_rh_v2_evaluaciones_administrativas ea ON ea.id = ei.id_evaluacion_administrativa
                WHERE ei.id_incidencia_aplicacion IN ($marks) ORDER BY ea.revision_numero DESC", str_repeat('i', count($applicationIds)), $applicationIds);
            if ($evalRows === null) return null;
            foreach ($evalRows as $ev) {
                $appId = (int)$ev['id_incidencia_aplicacion'];
                $evaluations[$appId][] = [
                    'revision_number' => (int)$ev['revision_numero'], 'algorithm_version' => (string)$ev['version_algoritmo_admin'],
                    'policy_version' => (string)$ev['version_politica'], 'evaluation_status' => (string)$ev['estado_evaluacion'],
                    'human_review_status' => (string)$ev['estado_revision_humana'], 'fingerprint' => (string)$ev['fingerprint_ejecucion'],
                    'created_at_utc' => $this->isoUtc($ev['creado_at_utc'] ?? null),
                ];
            }
        }

        $timezone = null;
        try { $timezone = (new WorkplaceTimezoneAuthorityService($db))->resolve($workplaceId, (string)$row['start_date']); }
        catch (Throwable) { }
        $shiftName = 'Sin turno vinculado'; $shiftHours = '--';
        $applicationItems = [];
        $partial = $currentRevisionId < 1 || $timezone === null;
        foreach ($applications as $app) {
            $appTz = null;
            try { $appTz = (new WorkplaceTimezoneAuthorityService($db))->resolve($workplaceId, (string)$app['work_date']); }
            catch (Throwable) { $partial = true; }
            $hours = $this->time12h($app['scheduled_start_utc'] ?? null, $appTz) . ' – ' . $this->time12h($app['scheduled_end_utc'] ?? null, $appTz);
            if ($hours === '-- – --') $hours = '--';
            $name = trim((string)($app['shift_name'] ?? ''));
            if ($name !== '' || $hours !== '--') {
                $shiftHours = $hours;
                $shiftName = $name !== '' ? ($hours !== '--' ? $name . ' (' . $hours . ')' : $name) : $hours;
            }
            $appId = (int)$app['internal_application_id'];
            $applicationEvaluations = $evaluations[$appId] ?? [];
            $applicationItems[] = [
                'work_date' => (string)$app['work_date'], 'application_status' => (string)$app['application_status'],
                'schedule_type' => (string)($app['schedule_type'] ?? ''), 'shift_name' => $name,
                'scheduled_start_utc' => $this->isoUtc($app['scheduled_start_utc'] ?? null),
                'scheduled_end_utc' => $this->isoUtc($app['scheduled_end_utc'] ?? null),
                'timezone' => $appTz,
                'evaluation_state' => $applicationEvaluations === [] ? 'NOT_EVALUATED' : 'EVALUATED',
                'evaluations' => $applicationEvaluations,
            ];
        }

        $createdUtc = $this->isoUtc($row['created_at_utc'] ?? null);
        $reason = $this->cleanReason($row['reason'] ?? null);
        $category = (string)$row['category'];
        if ($category === 'PUNCH_ATTENDANCE' && $currentRevisionId < 1) $partial = true;
        $state = $partial ? 'PARTIAL' : 'COMPLETE';
        $base = [
            'uuid' => (string)$row['uuid_incidencia'], 'worker_id' => (int)$row['worker_id'],
            'worker_name' => (string)($row['worker_name'] ?? ''), 'employee_number' => $row['employee_number'] !== null ? (string)$row['employee_number'] : null,
            'workplace_id' => $workplaceId, 'workplace_name' => (string)($row['workplace_name'] ?? ''),
            'category' => $category, 'scope' => (string)$row['scope'],
            'date_range' => ['start_date' => (string)$row['start_date'], 'end_date' => (string)$row['end_date']],
            'current_status' => (string)$row['current_status'], 'current_revision' => (int)$row['current_revision'],
            'reason' => $reason, 'evidence_reference' => $row['evidence_reference'] !== null ? (string)$row['evidence_reference'] : null,
            'created_by_user_id' => $row['created_by_user_id'] !== null ? (int)$row['created_by_user_id'] : null,
            'approved_by_user_id' => $row['approved_by_user_id'] !== null ? (int)$row['approved_by_user_id'] : null,
            'created_at_utc' => $createdUtc, 'updated_at_utc' => $this->isoUtc($row['updated_at_utc'] ?? null),
            'source_classification' => in_array($category, ['VACATION','PERMISSION'], true) ? 'READ_ONLY_ADAPTER' : 'V2_CANONICAL',
            'detail_source' => 'P3G_INCIDENCE_DETAIL_ENDPOINT',
            'punch_source' => 'M05_PUNCH_EVENTS_ENDPOINT',
            'applications' => $applicationItems,
            'shift_name' => $shiftName, 'shift_hours' => $shiftHours,
            'evaluations' => array_merge(...array_values($evaluations ?: [[]])),
            'timezone' => $timezone,
            'projection_state' => $state,
        ];
        return $base;
    }

    private function one(mysqli $db, string $sql, string $types, array $params): ?array
    {
        $rows = $this->all($db, $sql, $types, $params, 1);
        return $rows === null ? null : ($rows[0] ?? null);
    }

    private function all(mysqli $db, string $sql, string $types, array $params, ?int $limit = null): ?array
    {
        $stmt = $db->prepare($sql); if (!$stmt) return null;
        $bind = [$types]; foreach ($params as $i => &$value) $bind[] = &$value; unset($value);
        if ($types !== '') call_user_func_array([$stmt, 'bind_param'], $bind);
        if (!$stmt->execute()) { $stmt->close(); return null; }
        $result = $stmt->get_result(); if (!$result) { $stmt->close(); return null; }
        $rows = []; while (($row = $result->fetch_assoc()) !== null) { $rows[] = $row; if ($limit !== null && count($rows) >= $limit) break; }
        $stmt->close(); return $rows;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (!(is_int($value) || (is_string($value) && ctype_digit($value)))) return null;
        $n = (int)$value; return $n > 0 ? $n : null;
    }
    private function validDate(string $v): bool { $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v); return $d !== false && $d->format('Y-m-d') === $v; }
    private function cleanReason(mixed $v): ?string { return $v === null ? null : preg_replace('/\n<!-- IDEMP:.*? -->$/s', '', (string)$v); }
    private function isoUtc(mixed $v): ?string
    {
        if (!is_string($v) || trim($v) === '') return null;
        try { return (new DateTimeImmutable($v, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'); }
        catch (Throwable) { return null; }
    }
    private function time12h(mixed $v, ?string $tz): string
    {
        if (!is_string($v) || $v === '' || $tz === null) return '--';
        try { return strtolower((new DateTimeImmutable($v, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($tz))->format('h:i a')); }
        catch (Throwable) { return '--'; }
    }
    private function headers(): void { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache'); }
    private function error(int $code, string $kind): void { http_response_code($code); echo json_encode(['status'=>'error','error'=>['code'=>$kind]], JSON_UNESCAPED_SLASHES); }
    private function success(array $data): void { http_response_code(200); echo json_encode(['status'=>'success','data'=>$data], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
}
