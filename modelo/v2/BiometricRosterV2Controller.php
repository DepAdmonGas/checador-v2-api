<?php

declare(strict_types=1);

require_once __DIR__ . '/../BD.php';
require_once __DIR__ . '/DispositivoV2.php';
require_once __DIR__ . '/Portal3JwtAuthHelper.php';
require_once __DIR__ . '/WorkplacePhysicalStationResolver.php';
require_once __DIR__ . '/BiometricRosterV2Projection.php';

/** Owner-approved preproduction read-only boundary; runtime deployment remains gated. */
final class BiometricRosterV2Controller
{
    private ?mysqli $db = null;

    public function handle(): void
    {
        $this->headers();
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            header('Allow: GET');
            $this->respondError(405, 'METHOD_NOT_ALLOWED');
        }

        $deviceId = trim((string) ($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));
        $credentialKey = trim((string) ($_SERVER['HTTP_X_CREDENTIAL_KEY'] ?? ''));
        $supervisorToken = trim((string) ($_SERVER['HTTP_X_SUPERVISOR_TOKEN'] ?? ''));
        $secret = $this->bearerToken((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($deviceId === '' || $credentialKey === '' || $supervisorToken === '' || $secret === '') {
            $this->respondError(400, 'MISSING_HEADERS');
        }

        $bd = new BD();
        try {
            $this->db = $bd->conectarBD();
            if (!$this->db instanceof mysqli) $this->respondError(503, 'ROSTER_UNAVAILABLE');

            if (!$this->db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT)) {
                $this->respondError(503, 'ROSTER_UNAVAILABLE');
            }

            $device = (new DispositivoV2())->autenticarCredencial($this->db, $deviceId, $credentialKey, $secret);
            if (empty($device['auth'])) $this->respondError(401, 'DEVICE_UNAUTHORIZED');

            // Portal3JwtAuthHelper validates the same JWT contract using its required Bearer form.
            $human = Portal3JwtAuthHelper::validateToken($this->db, 'Bearer ' . $supervisorToken);
            if (empty($human['valid'])) $this->respondError(401, 'SUPERVISOR_UNAUTHORIZED');
            $acl = Portal3JwtAuthHelper::authorizeBiometricos($this->db, $human, 'leer');
            if (empty($acl['success'])) {
                $status = (int) ($acl['code'] ?? 403);
                $this->respondError(in_array($status, [500, 503], true) ? $status : 403,
                    in_array($status, [500, 503], true) ? 'AUTH_DEPENDENCY_UNAVAILABLE' : 'FUNCTION_PERMISSION_DENIED');
            }

            $physicalStationId = (int) ($device['id_estacion'] ?? 0);
            $canonicalLocalityId = WorkplacePhysicalStationResolver::resolveCanonicalLocality($this->db, $physicalStationId);
            if ($canonicalLocalityId === null) $this->respondError(404, 'STATION_NOT_FOUND');
            $authorizedLocalities = array_map('intval', $acl['authorized_stations'] ?? []);
            if (!in_array($canonicalLocalityId, $authorizedLocalities, true)) {
                $this->respondError(403, 'STATION_JURISDICTION_MISMATCH');
            }

            [$stationName, $rows] = $this->readNativeWorkers($physicalStationId, (int) $device['id_dispositivo']);
            $timestamp = $this->timestampForDevice((string) ($device['device_timezone'] ?? ''));
            $response = BiometricRosterV2Projection::project($rows, $physicalStationId, $stationName, $timestamp);
            $this->db->commit();
            $this->auditSuccessfulRead((int) $device['id_dispositivo'], $physicalStationId, (int) $human['user_id'], $response);
            $this->respond(200, $response);
        } catch (Throwable) {
            if ($this->db instanceof mysqli) @$this->db->rollback();
            $this->respondError(503, 'ROSTER_UNAVAILABLE');
        } finally {
            if ($this->db instanceof mysqli) $bd->desconectarBD($this->db);
        }
    }

    /** @return array{0:string,1:list<array<string,mixed>>} */
    private function readNativeWorkers(int $physicalStationId, int $deviceId): array
    {
        $station = $this->db->prepare('SELECT nombre FROM tb_estaciones WHERE id = ? AND estatus = 0 LIMIT 2');
        if (!$station) throw new RuntimeException('STATION_READ_FAILED');
        $station->bind_param('i', $physicalStationId);
        if (!$station->execute()) { $station->close(); throw new RuntimeException('STATION_READ_FAILED'); }
        $stationRows = $station->get_result()->fetch_all(MYSQLI_ASSOC);
        $station->close();
        if (count($stationRows) !== 1 || trim((string) ($stationRows[0]['nombre'] ?? '')) === '') {
            throw new RuntimeException('STATION_MAPPING_UNRESOLVED');
        }

        $now = $this->db->query('SELECT UTC_TIMESTAMP() AS now_utc');
        $nowRow = $now ? $now->fetch_assoc() : null;
        if ($now) $now->free();
        if (!is_array($nowRow) || empty($nowRow['now_utc'])) throw new RuntimeException('DATABASE_CLOCK_UNAVAILABLE');
        $nowUtc = (string) $nowRow['now_utc'];

        // Only the biometric template column is read. Legacy PIN is intentionally absent.
        // A worker is eligible only with exactly one current identity mapping for this device.
        $sql = 'SELECT p.id AS id_personal, p.no_colaborador, p.nombre_completo AS nombre, '
            . 'COALESCE(pu.puesto, \'\') AS puesto, p.id_estacion AS id_estacion_adscripcion, '
            . 'identity_map.referencia_externa_personal AS external_worker_reference, '
            . 'identity_map.identity_mapping_count, access_template.biometric_template '
            . 'FROM op_rh_personal p '
            . 'INNER JOIN (SELECT id_personal, MIN(referencia_externa_personal) AS referencia_externa_personal, COUNT(*) AS identity_mapping_count '
            . 'FROM op_rh_v2_dispositivo_identidad_personal WHERE id_dispositivo = ? AND es_activo = 1 '
            . 'AND valido_desde <= ? AND (valido_hasta IS NULL OR valido_hasta > ?) GROUP BY id_personal HAVING COUNT(*) = 1) identity_map '
            . 'ON identity_map.id_personal = p.id '
            . 'LEFT JOIN op_rh_puestos pu ON pu.id = p.puesto '
            . 'LEFT JOIN (SELECT id_personal, CASE WHEN COUNT(*) = 1 THEN MAX(huella) ELSE NULL END AS biometric_template '
            . 'FROM op_rh_personal_acceso GROUP BY id_personal) access_template ON access_template.id_personal = p.id '
            . 'WHERE p.estado = 1 AND p.id_estacion = ? ORDER BY p.nombre_completo, p.id';
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('ROSTER_QUERY_UNAVAILABLE');
        $stmt->bind_param('issi', $deviceId, $nowUtc, $nowUtc, $physicalStationId);
        if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('ROSTER_QUERY_UNAVAILABLE'); }
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return [(string) $stationRows[0]['nombre'], $rows];
    }

    private function timestampForDevice(string $timezone): string
    {
        try {
            $zone = new DateTimeZone($timezone);
            return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone($zone)->format(DateTimeInterface::ATOM);
        } catch (Throwable) {
            throw new RuntimeException('DEVICE_TIMEZONE_UNAVAILABLE');
        }
    }

    /** Logs request metadata only; never log templates, identity references, JWTs, or device secrets. */
    private function auditSuccessfulRead(int $deviceId, int $physicalStationId, int $supervisorId, array $response): void
    {
        $entry = [
            'event' => 'CHECADOR_V2_BIOMETRIC_ROSTER_READ',
            'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'id_dispositivo' => $deviceId,
            'id_estacion' => $physicalStationId,
            'supervisor_id' => $supervisorId,
            'workers_count' => (int) ($response['total_workers'] ?? 0),
            'biometrics_count' => (int) ($response['workers_with_biometrics'] ?? 0),
            'client_ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        ];
        error_log((string) json_encode($entry, JSON_UNESCAPED_SLASHES));
    }

    private function bearerToken(string $header): string
    {
        return preg_match('/\ABearer\s+(.+)\z/i', trim($header), $match) === 1 ? trim($match[1]) : '';
    }

    private function headers(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }

    /** @param array<string,mixed> $payload */
    private function respond(int $httpCode, array $payload): never
    {
        http_response_code($httpCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    private function respondError(int $httpCode, string $status): never
    {
        $this->respond($httpCode, ['code' => $httpCode, 'status' => $status]);
    }
}
