<?php

declare(strict_types=1);

require_once __DIR__ . '/../BD.php';
require_once __DIR__ . '/Portal3JwtAuthHelper.php';
require_once __DIR__ . '/WorkplacePhysicalStationResolver.php';
require_once __DIR__ . '/AdministrativeDeviceReadAuthorizationService.php';
require_once __DIR__ . '/AdministrativeDeviceReadProjection.php';

/** Read-only administrative inventory endpoint for M11. */
final class AdministrativeDeviceReadV2Controller
{
    private const LIMIT = 500;

    public function handle(): void
    {
        $this->headers();
        $environment = strtolower(trim((string) (getenv('APP_ENV') ?: '')));
        if (!in_array($environment, ['dev', 'development'], true)) {
            $this->error(404, 'NOT_FOUND', 'Recurso no disponible.');
        }
        $databaseHost = strtolower(trim((string) (getenv('DB_HOST') ?: '')));
        if (!in_array($databaseHost, ['localhost', '127.0.0.1', '::1'], true)) {
            $this->error(404, 'NOT_FOUND', 'Recurso no disponible.');
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            header('Allow: GET');
            $this->error(405, 'METHOD_NOT_ALLOWED', 'Método no permitido.');
        }

        $requestedRaw = $_GET['workplace_id'] ?? null;
        $requestedId = null;
        if ($requestedRaw !== null) {
            $raw = is_string($requestedRaw) || is_int($requestedRaw) ? (string) $requestedRaw : '';
            if (!preg_match('/\A[1-9][0-9]{0,8}\z/D', $raw)) {
                $this->error(400, 'INVALID_WORKPLACE_ID', 'El identificador de localidad no es válido.');
            }
            $requestedId = (int) $raw;
        }

        $bd = new BD();
        $db = $bd->conectarBD();
        if (!$db instanceof mysqli) {
            $this->error(503, 'DEVICE_INVENTORY_UNAVAILABLE', 'No fue posible consultar el inventario.');
        }

        $database = $db->query('SELECT DATABASE() AS database_name');
        $databaseRow = $database ? $database->fetch_assoc() : null;
        if ($database) $database->free();
        if (!is_array($databaseRow) || ($databaseRow['database_name'] ?? null) !== 'bd_admongas') {
            $bd->desconectarBD($db);
            $this->error(503, 'DEVICE_INVENTORY_UNAVAILABLE', 'El inventario de desarrollo no está disponible.');
        }

        $transactionOpen = false;
        try {
            if (!$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT)) {
                $this->error(503, 'DEVICE_INVENTORY_UNAVAILABLE', 'No fue posible iniciar una lectura consistente.');
            }
            $transactionOpen = true;

            $auth = Portal3JwtAuthHelper::validateToken($db, $_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if (empty($auth['valid'])) {
                $code = (int) ($auth['code'] ?? 401);
                $this->error(
                    in_array($code, [500, 503], true) ? $code : 401,
                    (string) ($auth['status'] ?? ($code === 503 ? 'AUTH_DEPENDENCY_UNAVAILABLE' : ($code === 500 ? 'INTERNAL_ERROR' : 'UNAUTHORIZED'))),
                    $code === 401 ? 'La sesión de usuario no es válida.' : 'No fue posible validar la sesión.'
                );
            }
            $authorization = AdministrativeDeviceReadAuthorizationService::authorize($db, $auth);
            if (empty($authorization['success'])) {
                $this->error((int) ($authorization['code'] ?? 403), (string) ($authorization['status'] ?? 'FUNCTION_PERMISSION_DENIED'), 'No tienes acceso al inventario solicitado.');
            }
            $workplaces = $authorization['authorized_stations'];
            if ($requestedId !== null && !in_array($requestedId, $workplaces, true)) {
                $this->error(403, 'STATION_SCOPE_DENIED', 'La localidad solicitada no está autorizada.');
            }
            if ($requestedId !== null) $workplaces = [$requestedId];

            $now = $this->readUtcNow($db);
            $stations = [];
            $partialReasons = [];
            foreach ($workplaces as $workplaceId) {
                $physicalId = WorkplacePhysicalStationResolver::resolve($db, (int) $workplaceId);
                if ($physicalId === null) {
                    if ($requestedId !== null) {
                        $this->error(409, 'WORKPLACE_MAPPING_UNRESOLVED', 'No se pudo validar la relación institucional de la localidad.');
                    }
                    $partialReasons['WORKPLACE_MAPPING_UNRESOLVED'] = true;
                    continue;
                }
                $stations[$physicalId] = $this->readStationMapping($db, (int) $workplaceId, $physicalId);
            }
            if ($stations === []) {
                $db->commit();
                $transactionOpen = false;
                $this->success([
                    'status' => 'OK', 'completeness' => 'UNKNOWN', 'read_at_utc' => $now,
                    'items' => [], 'partial_reasons' => ['WORKPLACE_MAPPING_UNRESOLVED'],
                ]);
            }

            $deviceRows = $this->readScopedDevices($db, array_keys($stations), $now);
            if (count($deviceRows) > self::LIMIT) {
                $this->error(413, 'INVENTORY_LIMIT_EXCEEDED', 'El inventario autorizado supera el límite de lectura.');
            }
            $deviceIds = array_map(static fn(array $row): int => (int) $row['id'], $deviceRows);
            $assignmentRows = $deviceIds === [] ? [] : $this->readCurrentAssignments($db, $deviceIds, $now);
            $telemetry = $deviceIds === [] ? [] : $this->readLatestTelemetry($db, $deviceIds);

            $projection = AdministrativeDeviceReadProjection::project(
                $deviceRows,
                $assignmentRows,
                $stations,
                $telemetry,
                array_keys($partialReasons)
            );
            $db->commit();
            $transactionOpen = false;
            $this->success([
                'status' => 'OK',
                'completeness' => $projection['completeness'],
                'read_at_utc' => $now,
                'items' => $projection['items'],
                'partial_reasons' => $projection['partial_reasons'],
            ]);
        } catch (Throwable) {
            if ($transactionOpen) @$db->rollback();
            $this->error(503, 'DEVICE_INVENTORY_UNAVAILABLE', 'No fue posible completar la lectura del inventario.');
        } finally {
            $bd->desconectarBD($db);
        }
    }

    private function readUtcNow(mysqli $db): string
    {
        $result = $db->query('SELECT UTC_TIMESTAMP() AS read_at_utc');
        $row = $result ? $result->fetch_assoc() : null;
        if ($result) $result->free();
        $value = is_array($row) ? (string) ($row['read_at_utc'] ?? '') : '';
        if ($value === '') throw new RuntimeException('Database clock unavailable.');
        return $value;
    }

    /** @return array{workplace_id:int,workplace_name:string,physical_station_id:int,station_name:string,numlista:int} */
    private function readStationMapping(mysqli $db, int $workplaceId, int $physicalId): array
    {
        $stmt = $db->prepare(
            'SELECT l.id AS workplace_id, l.localidad AS workplace_name, l.numlista, '
            . 'e.id AS physical_station_id, e.nombre AS station_name '
            . 'FROM op_rh_localidades l INNER JOIN tb_estaciones e ON e.numlista = l.numlista '
            . 'WHERE l.id = ? AND e.id = ? AND e.estatus = 0 LIMIT 2'
        );
        if (!$stmt) throw new RuntimeException('Station mapping unavailable.');
        $stmt->bind_param('ii', $workplaceId, $physicalId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Station mapping unavailable.');
        }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        if (count($rows) !== 1) throw new RuntimeException('Station mapping is ambiguous.');
        $row = $rows[0];
        return [
            'workplace_id' => (int) $row['workplace_id'],
            'workplace_name' => (string) $row['workplace_name'],
            'physical_station_id' => (int) $row['physical_station_id'],
            'station_name' => (string) $row['station_name'],
            'numlista' => (int) $row['numlista'],
        ];
    }

    /** @param list<int> $physicalIds @return list<array<string,mixed>> */
    private function readScopedDevices(mysqli $db, array $physicalIds, string $now): array
    {
        $inHistory = implode(',', array_fill(0, count($physicalIds), '?'));
        $inRegistry = implode(',', array_fill(0, count($physicalIds), '?'));
        $sql = 'SELECT d.id, d.uuid_dispositivo, d.nombre, d.estado, d.id_estacion '
            . 'FROM op_rh_v2_dispositivos d '
            . 'WHERE EXISTS (SELECT 1 FROM op_rh_v2_dispositivo_estacion_historial h '
            . "WHERE h.id_dispositivo = d.id AND h.valido_desde_utc <= ? AND (h.valido_hasta_utc IS NULL OR h.valido_hasta_utc > ?) AND h.id_estacion IN ({$inHistory})) "
            . "OR (d.id_estacion IN ({$inRegistry}) AND NOT EXISTS (SELECT 1 FROM op_rh_v2_dispositivo_estacion_historial h2 "
            . 'WHERE h2.id_dispositivo = d.id AND h2.valido_desde_utc <= ? AND (h2.valido_hasta_utc IS NULL OR h2.valido_hasta_utc > ?))) '
            . 'ORDER BY d.id LIMIT 501';
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new RuntimeException('Device inventory unavailable.');
        $values = array_merge([$now, $now], $physicalIds, $physicalIds, [$now, $now]);
        self::bind($stmt, str_repeat('s', 2) . str_repeat('i', count($physicalIds) * 2) . 'ss', $values);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Device inventory unavailable.');
        }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    /** @param list<int> $deviceIds @return list<array<string,mixed>> */
    private function readCurrentAssignments(mysqli $db, array $deviceIds, string $now): array
    {
        $in = implode(',', array_fill(0, count($deviceIds), '?'));
        $stmt = $db->prepare(
            "SELECT id_dispositivo, id_estacion, valido_desde_utc, valido_hasta_utc FROM op_rh_v2_dispositivo_estacion_historial "
            . "WHERE id_dispositivo IN ({$in}) AND valido_desde_utc <= ? AND (valido_hasta_utc IS NULL OR valido_hasta_utc > ?) "
            . 'ORDER BY id_dispositivo, valido_desde_utc, id'
        );
        if (!$stmt) throw new RuntimeException('Device assignment history unavailable.');
        $values = array_merge($deviceIds, [$now, $now]);
        self::bind($stmt, str_repeat('i', count($deviceIds)) . 'ss', $values);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Device assignment history unavailable.');
        }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }

    /** @param list<int> $deviceIds @return array<int,?string> */
    private function readLatestTelemetry(mysqli $db, array $deviceIds): array
    {
        $in = implode(',', array_fill(0, count($deviceIds), '?'));
        $stmt = $db->prepare(
            "SELECT id_dispositivo, MAX(server_received_at_utc) AS last_server_received_at_utc "
            . "FROM op_rh_v2_dispositivo_telemetria WHERE id_dispositivo IN ({$in}) GROUP BY id_dispositivo"
        );
        if (!$stmt) throw new RuntimeException('Device telemetry read unavailable.');
        self::bind($stmt, str_repeat('i', count($deviceIds)), $deviceIds);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Device telemetry read unavailable.');
        }
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        $map = [];
        foreach ($rows as $row) $map[(int) $row['id_dispositivo']] = (string) $row['last_server_received_at_utc'];
        return $map;
    }

    /** @param list<int|string> $values */
    private static function bind(mysqli_stmt $stmt, string $types, array $values): void
    {
        $params = [$types];
        foreach ($values as $index => $value) $params[] = &$values[$index];
        if (!call_user_func_array([$stmt, 'bind_param'], $params)) throw new RuntimeException('SQL parameters unavailable.');
    }

    private function headers(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('Pragma: no-cache');
        header('Vary: Authorization');
        header('X-Content-Type-Options: nosniff');
    }

    private function error(int $httpCode, string $code, string $message): never
    {
        http_response_code($httpCode);
        $payload = ['status' => 'ERROR', 'code' => $code, 'message' => $message];
        if ($httpCode === 413) {
            $payload['completeness'] = 'PARTIAL';
            $payload['items'] = [];
            $payload['partial_reasons'] = ['INVENTORY_LIMIT_EXCEEDED'];
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function success(array $payload): never
    {
        http_response_code(200);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
