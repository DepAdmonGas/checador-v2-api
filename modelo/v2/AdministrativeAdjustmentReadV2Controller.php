<?php
declare(strict_types=1);

require_once __DIR__ . '/../BD.php';
require_once __DIR__ . '/Portal3JwtAuthHelper.php';
require_once __DIR__ . '/AdministrativeAdjustmentReadAuthorization.php';

/** Read-only V2 projection for persisted administrative adjustments. */
final class AdministrativeAdjustmentReadV2Controller
{
    private const TYPES = ['OVERRIDE_STATUS', 'INTERPRETED_ENTRY_TIME', 'INTERPRETED_EXIT_TIME', 'JUSTIFY_LATENESS'];
    private const STATUSES = ['ACTIVE', 'SUPERSEDED', 'CANCELLED'];

    public function handleList(): void
    {
        $this->headers();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            $this->error(405, 'METHOD_NOT_ALLOWED');
            return;
        }
        $db = $this->connect();
        if (!$db) return;
        $auth = Portal3JwtAuthHelper::validateToken($db, $_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!($auth['valid'] ?? false)) {
            $db->close();
            $this->error((int) ($auth['status'] ?? 401), (string) ($auth['code'] ?? 'UNAUTHORIZED'));
            return;
        }
        $authz = AdministrativeAdjustmentReadAuthorization::authorize($db, $auth);
        if (!($authz['success'] ?? false)) {
            $db->close();
            $this->error((int) ($authz['http_status'] ?? 403), (string) ($authz['code'] ?? 'FUNCTION_PERMISSION_DENIED'));
            return;
        }

        $workplaceId = self::positive($_GET['workplace_id'] ?? null);
        $dateFrom = (string) ($_GET['date_from'] ?? '');
        $dateTo = (string) ($_GET['date_to'] ?? '');
        $workerId = array_key_exists('worker_id', $_GET) && $_GET['worker_id'] !== '' ? self::positive($_GET['worker_id']) : null;
        $type = trim((string) ($_GET['adjustment_type'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $page = array_key_exists('page', $_GET) ? self::positive($_GET['page']) : 1;
        $perPage = array_key_exists('per_page', $_GET) ? self::positive($_GET['per_page']) : 25;
        if ($workplaceId === null || !self::civilDate($dateFrom) || !self::civilDate($dateTo)
            || (array_key_exists('worker_id', $_GET) && $_GET['worker_id'] !== '' && $workerId === null)
            || $page === null || $perPage === null || $perPage > 100) {
            $db->close(); $this->error(400, 'INVALID_PAYLOAD'); return;
        }
        if ($dateTo < $dateFrom) { $db->close(); $this->error(422, 'INVALID_DATE_RANGE'); return; }
        $from = new DateTimeImmutable($dateFrom);
        $to = new DateTimeImmutable($dateTo);
        if ($to->diff($from)->days > 30) { $db->close(); $this->error(422, 'DATE_RANGE_TOO_LARGE'); return; }
        if (($type !== '' && !in_array($type, self::TYPES, true)) || ($status !== '' && !in_array($status, self::STATUSES, true))) {
            $db->close(); $this->error(422, 'INVALID_FILTER'); return;
        }
        if (!in_array($workplaceId, $authz['authorized_workplaces'], true)) {
            $db->close(); $this->error(403, 'STATION_SCOPE_DENIED'); return;
        }
        if ($workerId !== null && !$this->workerInScope($db, $workerId, $workplaceId, $dateFrom, $dateTo)) {
            $db->close(); $this->error(404, 'WORKER_NOT_FOUND_OR_OUT_OF_SCOPE'); return;
        }

        try {
            if (!$this->integrityPasses($db, $workplaceId, $dateFrom, $dateTo, $workerId, $type, $status)) {
                $db->close(); $this->error(500, 'DATA_INTEGRITY_CONFLICT'); return;
            }
            [$where, $types, $params] = $this->where($workplaceId, $dateFrom, $dateTo, $workerId, $type, $status);
            $total = $this->scalar(
                $db,
                'SELECT COUNT(*) AS total FROM op_rh_v2_ajustes_administrativos a '
                . 'JOIN op_rh_v2_jornadas_programadas j ON j.id = a.id_jornada_programada '
                . 'JOIN op_rh_v2_calculos_jornada c ON c.id = a.id_calculo_jornada '
                . $where,
                $types,
                $params
            );
            if ($page > intdiv(PHP_INT_MAX, $perPage) + 1) {
            $db->close(); $this->error(400, 'INVALID_PAYLOAD'); return;
        }
        $offset = ($page - 1) * $perPage;
            $sql = 'SELECT a.uuid_ajuste, a.uuid_ajuste_logico, a.revision_numero, a.estado_ajuste, a.tipo_ajuste, '
                . 'j.id_personal, j.id_estacion, j.fecha_jornada_local, j.timezone_local, a.motivo_ajuste, '
                . 'a.hora_interpretada_entrada_utc, a.hora_interpretada_salida_utc, a.creado_at_utc, '
                . 'a.creado_por_usuario_id, a.aprobado_por_usuario_id, '
                . '(SELECT COUNT(*) FROM op_rh_v2_evaluaciones_ajustes ea WHERE ea.id_ajuste_administrativo = a.id) AS linked_evaluation_count '
                . 'FROM op_rh_v2_ajustes_administrativos a '
                . 'JOIN op_rh_v2_jornadas_programadas j ON j.id = a.id_jornada_programada '
                . 'JOIN op_rh_v2_calculos_jornada c ON c.id = a.id_calculo_jornada '
                . $where . ' ORDER BY a.id ASC LIMIT ? OFFSET ?';
            $params[] = $perPage; $types .= 'i';
            $params[] = $offset; $types .= 'i';
            $rows = $this->rows($db, $sql, $types, $params);
            $asOf = $this->scalarString($db, 'SELECT DATE_FORMAT(UTC_TIMESTAMP(), \'%Y-%m-%dT%H:%i:%sZ\') AS value');
            $db->close();
            $this->json(200, ['status' => 'success', 'data' => [
                'workplace_id' => $workplaceId,
                'query' => [
                    'date_from' => $dateFrom, 'date_to' => $dateTo, 'worker_id' => $workerId,
                    'adjustment_type' => $type === '' ? null : $type, 'status' => $status === '' ? null : $status,
                ],
                'as_of_utc' => $asOf,
                'total_records' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
                'adjustments' => array_map([$this, 'serializeRevision'], $rows),
            ]]);
        } catch (Throwable) {
            $db->close();
            $this->error(503, 'SOURCE_UNAVAILABLE');
        }
    }

    public function handleDetail(string $uuid): void
    {
        $this->headers();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET'); $this->error(405, 'METHOD_NOT_ALLOWED'); return;
        }
        if (!self::validUuidReference($uuid)) { $this->error(400, 'INVALID_PAYLOAD'); return; }
        $db = $this->connect();
        if (!$db) return;
        $auth = Portal3JwtAuthHelper::validateToken($db, $_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!($auth['valid'] ?? false)) {
            $db->close(); $this->error((int) ($auth['status'] ?? 401), (string) ($auth['code'] ?? 'UNAUTHORIZED')); return;
        }
        $authz = AdministrativeAdjustmentReadAuthorization::authorize($db, $auth);
        if (!($authz['success'] ?? false)) {
            $db->close(); $this->error((int) ($authz['http_status'] ?? 403), (string) ($authz['code'] ?? 'FUNCTION_PERMISSION_DENIED')); return;
        }
        try {
            $selected = $this->revisionByUuid($db, $uuid);
            if ($selected === null || !in_array((int) $selected['workplace_id'], $authz['authorized_workplaces'], true)) {
                $db->close(); $this->error(404, 'ADJUSTMENT_NOT_FOUND'); return;
            }
            $historyRows = $this->rows(
                $db,
                'SELECT a.uuid_ajuste, a.uuid_ajuste_logico, a.revision_numero, a.estado_ajuste, a.tipo_ajuste, '
                . 'j.id_personal, j.id_estacion, j.fecha_jornada_local, j.timezone_local, a.motivo_ajuste, '
                . 'a.hora_interpretada_entrada_utc, a.hora_interpretada_salida_utc, a.creado_at_utc, '
                . 'a.creado_por_usuario_id, a.aprobado_por_usuario_id, prev.uuid_ajuste AS previous_revision_uuid, '
                . 'j.id AS jornada_id, c.id_jornada_programada AS calculation_journey_id, c.id_personal AS calculation_worker_id, '
                . 'c.id_estacion AS calculation_workplace_id, c.fecha_jornada_local AS calculation_work_date '
                . 'FROM op_rh_v2_ajustes_administrativos a '
                . 'JOIN op_rh_v2_jornadas_programadas j ON j.id = a.id_jornada_programada '
                . 'JOIN op_rh_v2_calculos_jornada c ON c.id = a.id_calculo_jornada '
                . 'LEFT JOIN op_rh_v2_ajustes_administrativos prev ON prev.id = a.id_ajuste_anterior '
                . 'WHERE a.uuid_ajuste_logico = ? ORDER BY a.revision_numero ASC',
                's',
                [(string) $selected['uuid_ajuste_logico']]
            );
            foreach ($historyRows as $row) {
                if ((int) $row['id_personal'] !== (int) $selected['worker_id']
                    || (int) $row['id_estacion'] !== (int) $selected['workplace_id']
                    || (int) $row['calculation_worker_id'] !== (int) $row['id_personal']
                    || (int) $row['calculation_workplace_id'] !== (int) $row['id_estacion']
                    || (string) $row['calculation_work_date'] !== (string) $row['fecha_jornada_local']
                    || (int) $row['calculation_journey_id'] !== (int) $row['jornada_id']) {
                    $db->close(); $this->error(500, 'DATA_INTEGRITY_CONFLICT'); return;
                }
            }
            $serialized = array_map([$this, 'serializeRevision'], $historyRows);
            $selectedSerialized = null;
            foreach ($serialized as $revision) {
                if ($revision['uuid_ajuste'] === $uuid) $selectedSerialized = $revision;
            }
            if ($selectedSerialized === null) { $db->close(); $this->error(404, 'ADJUSTMENT_NOT_FOUND'); return; }
            $evaluations = $this->rows(
                $db,
                'SELECT e.id_personal AS worker_id, e.id_estacion AS workplace_id, e.fecha_jornada_local AS work_date_local, '
                . 'e.revision_numero, e.estado_evaluacion, e.creado_at_utc '
                . 'FROM op_rh_v2_evaluaciones_ajustes ea '
                . 'JOIN op_rh_v2_evaluaciones_administrativas e ON e.id = ea.id_evaluacion_administrativa '
                . 'JOIN op_rh_v2_ajustes_administrativos a ON a.id = ea.id_ajuste_administrativo '
                . 'WHERE a.uuid_ajuste = ? ORDER BY e.fecha_jornada_local, e.revision_numero',
                's', [$uuid]
            );
            foreach ($evaluations as $evaluation) {
                if ((int) $evaluation['worker_id'] !== (int) $selected['worker_id']
                    || (int) $evaluation['workplace_id'] !== (int) $selected['workplace_id']) {
                    $db->close(); $this->error(500, 'DATA_INTEGRITY_CONFLICT'); return;
                }
            }
            $db->close();
            $this->json(200, ['status' => 'success', 'data' => [
                'adjustment' => $selectedSerialized,
                'revision_history' => $serialized,
                'linked_evaluations' => array_map(static fn(array $row): array => [
                    'worker_id' => (int) $row['worker_id'],
                    'workplace_id' => (int) $row['workplace_id'],
                    'work_date_local' => (string) $row['work_date_local'],
                    'revision_number' => (int) $row['revision_numero'],
                    'status' => (string) $row['estado_evaluacion'],
                    'created_at_utc' => self::utc($row['creado_at_utc']),
                ], $evaluations),
            ]]);
        } catch (DomainException) {
            $db->close(); $this->error(500, 'DATA_INTEGRITY_CONFLICT');
        } catch (Throwable) {
            $db->close(); $this->error(503, 'SOURCE_UNAVAILABLE');
        }
    }

    private function connect(): ?mysqli
    {
        try {
            $connection = (new BD())->conectarBD();
            if (!$connection instanceof mysqli) { $this->error(503, 'SOURCE_UNAVAILABLE'); return null; }
            return $connection;
        } catch (Throwable) {
            $this->error(503, 'SOURCE_UNAVAILABLE'); return null;
        }
    }

    private function workerInScope(mysqli $db, int $worker, int $workplace, string $from, string $to): bool
    {
        return $this->scalar($db, 'SELECT COUNT(*) AS total FROM op_rh_v2_jornadas_programadas WHERE id_personal = ? AND id_estacion = ? AND fecha_jornada_local BETWEEN ? AND ?', 'iiss', [$worker, $workplace, $from, $to]) > 0;
    }

    private function integrityPasses(mysqli $db, int $workplace, string $from, string $to, ?int $worker, string $type, string $status): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM op_rh_v2_ajustes_administrativos a '
            . 'JOIN op_rh_v2_jornadas_programadas j ON j.id = a.id_jornada_programada '
            . 'LEFT JOIN op_rh_v2_calculos_jornada c ON c.id = a.id_calculo_jornada '
            . 'WHERE j.id_estacion = ? AND j.fecha_jornada_local BETWEEN ? AND ? '
            . 'AND (c.id IS NULL OR c.id_jornada_programada <> j.id OR c.id_personal <> j.id_personal '
            . 'OR c.id_estacion <> j.id_estacion OR c.fecha_jornada_local <> j.fecha_jornada_local)';
        $params = [$workplace, $from, $to]; $types = 'iss';
        if ($worker !== null) { $sql .= ' AND j.id_personal = ?'; $params[] = $worker; $types .= 'i'; }
        if ($type !== '') { $sql .= ' AND a.tipo_ajuste = ?'; $params[] = $type; $types .= 's'; }
        if ($status !== '') { $sql .= ' AND a.estado_ajuste = ?'; $params[] = $status; $types .= 's'; }
        return $this->scalar($db, $sql, $types, $params) === 0;
    }

    private function where(int $workplace, string $from, string $to, ?int $worker, string $type, string $status): array
    {
        $where = 'WHERE j.id_estacion = ? AND j.fecha_jornada_local BETWEEN ? AND ?';
        $params = [$workplace, $from, $to]; $types = 'iss';
        if ($worker !== null) { $where .= ' AND j.id_personal = ?'; $params[] = $worker; $types .= 'i'; }
        if ($type !== '') { $where .= ' AND a.tipo_ajuste = ?'; $params[] = $type; $types .= 's'; }
        if ($status !== '') { $where .= ' AND a.estado_ajuste = ?'; $params[] = $status; $types .= 's'; }
        return [$where, $types, $params];
    }

    private function revisionByUuid(mysqli $db, string $uuid): ?array
    {
        $rows = $this->rows(
            $db,
            'SELECT a.uuid_ajuste, a.uuid_ajuste_logico, a.revision_numero, a.estado_ajuste, a.tipo_ajuste, '
            . 'j.id_personal AS worker_id, j.id_estacion AS workplace_id, j.fecha_jornada_local AS work_date_local, '
            . 'j.timezone_local AS workplace_timezone, a.motivo_ajuste AS motive, '
            . 'a.hora_interpretada_entrada_utc AS interpreted_entry_at_utc, '
            . 'a.hora_interpretada_salida_utc AS interpreted_exit_at_utc, a.creado_at_utc AS created_at_utc, '
            . 'a.creado_por_usuario_id AS created_by_user_id, a.aprobado_por_usuario_id AS approved_by_user_id, '
            . 'prev.uuid_ajuste AS previous_revision_uuid, j.id AS jornada_id, c.id_jornada_programada AS calculation_journey_id, '
            . 'c.id_personal AS calculation_worker_id, c.id_estacion AS calculation_workplace_id, c.fecha_jornada_local AS calculation_work_date '
            . 'FROM op_rh_v2_ajustes_administrativos a '
            . 'JOIN op_rh_v2_jornadas_programadas j ON j.id = a.id_jornada_programada '
            . 'JOIN op_rh_v2_calculos_jornada c ON c.id = a.id_calculo_jornada '
            . 'LEFT JOIN op_rh_v2_ajustes_administrativos prev ON prev.id = a.id_ajuste_anterior '
            . 'WHERE a.uuid_ajuste = ? LIMIT 1',
            's', [$uuid]
        );
        if (!$rows) return null;
        $row = $rows[0];
        if ((int) $row['calculation_journey_id'] !== (int) $row['jornada_id']
            || (int) $row['calculation_worker_id'] !== (int) $row['worker_id']
            || (int) $row['calculation_workplace_id'] !== (int) $row['workplace_id']
            || (string) $row['calculation_work_date'] !== (string) $row['work_date_local']) {
            throw new DomainException('DATA_INTEGRITY_CONFLICT');
        }
        return $this->serializeRevision($row);
    }

    private function serializeRevision(array $row): array
    {
        return [
            'uuid_ajuste' => (string) $row['uuid_ajuste'],
            'uuid_ajuste_logico' => (string) $row['uuid_ajuste_logico'],
            'revision_number' => (int) $row['revision_numero'],
            'status' => (string) $row['estado_ajuste'],
            'adjustment_type' => (string) $row['tipo_ajuste'],
            'worker_id' => (int) ($row['worker_id'] ?? $row['id_personal']),
            'workplace_id' => (int) ($row['workplace_id'] ?? $row['id_estacion']),
            'work_date_local' => (string) ($row['work_date_local'] ?? $row['fecha_jornada_local']),
            'workplace_timezone' => (string) ($row['workplace_timezone'] ?? $row['timezone_local']),
            'motive' => (string) ($row['motive'] ?? $row['motivo_ajuste']),
            'interpreted_entry_at_utc' => self::nullableUtc($row['interpreted_entry_at_utc'] ?? $row['hora_interpretada_entrada_utc']),
            'interpreted_exit_at_utc' => self::nullableUtc($row['interpreted_exit_at_utc'] ?? $row['hora_interpretada_salida_utc']),
            'created_at_utc' => self::utc($row['created_at_utc'] ?? $row['creado_at_utc']),
            'created_by_user_id' => (int) ($row['created_by_user_id'] ?? $row['creado_por_usuario_id']),
            'approved_by_user_id' => (int) ($row['approved_by_user_id'] ?? $row['aprobado_por_usuario_id']),
            'previous_revision_uuid' => $row['previous_revision_uuid'] ?? null,
        ];
    }

    private function rows(mysqli $db, string $sql, string $types = '', array $params = []): array
    {
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new RuntimeException('Read query unavailable');
        if ($params !== []) {
            $refs = [$types];
            foreach ($params as &$param) $refs[] = &$param;
            unset($param);
            if (!call_user_func_array([$stmt, 'bind_param'], $refs)) { $stmt->close(); throw new RuntimeException('Read bind failed'); }
        }
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Read query failed'); }
        $result = $stmt->get_result();
        if (!$result) { $stmt->close(); throw new RuntimeException('Read result unavailable'); }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function scalar(mysqli $db, string $sql, string $types = '', array $params = []): int
    {
        $row = $this->rows($db, $sql, $types, $params)[0] ?? [];
        return (int) ($row['total'] ?? 0);
    }

    private function scalarString(mysqli $db, string $sql): string
    {
        $row = $this->rows($db, $sql)[0] ?? [];
        return (string) ($row['value'] ?? '');
    }

    private static function positive(mixed $value): ?int
    {
        if (!(is_int($value) || (is_string($value) && ctype_digit($value)))) return null;
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $parsed === false ? null : $parsed;
    }

    private static function civilDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function validUuidReference(string $value): bool
    {
        return $value !== '' && strlen($value) <= 64 && preg_match('/\A[A-Za-z0-9._:-]+\z/D', $value) === 1;
    }

    private static function utc(mixed $value): string
    {
        $raw = (string) $value;
        if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/D', $raw) !== 1) return $raw;
        return str_replace(' ', 'T', $raw) . 'Z';
    }

    private static function nullableUtc(mixed $value): ?string
    {
        return $value === null ? null : self::utc($value);
    }

    private function headers(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    private function json(int $status, array $data): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function error(int $status, string $code): void
    {
        $messages = [
            'INVALID_PAYLOAD' => 'Parámetros no válidos.',
            'INVALID_DATE_RANGE' => 'El rango de fechas no es válido.',
            'DATE_RANGE_TOO_LARGE' => 'El periodo no puede exceder 31 fechas locales.',
            'INVALID_FILTER' => 'Un filtro no está admitido.',
            'UNAUTHORIZED' => 'Autenticación no válida.',
            'FUNCTION_PERMISSION_DENIED' => 'Permiso de lectura de ajustes denegado.',
            'STATION_SCOPE_DENIED' => 'Localidad fuera del alcance autorizado.',
            'ADJUSTMENT_NOT_FOUND' => 'El ajuste no existe o no está disponible.',
            'WORKER_NOT_FOUND_OR_OUT_OF_SCOPE' => 'Colaborador no disponible para esta consulta.',
            'DATA_INTEGRITY_CONFLICT' => 'La fuente administrativa contiene referencias inconsistentes.',
            'SOURCE_UNAVAILABLE' => 'La fuente administrativa no está disponible.',
            'METHOD_NOT_ALLOWED' => 'Método no permitido.',
        ];
        $this->json($status, ['status' => 'error', 'code' => $status, 'error' => ['code' => $code, 'message' => $messages[$code] ?? 'Error de consulta.']]);
    }
}
