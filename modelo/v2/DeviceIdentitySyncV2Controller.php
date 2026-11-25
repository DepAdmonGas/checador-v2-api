<?php

declare(strict_types=1);
require_once __DIR__ . '/../BD.php';
require_once __DIR__ . '/DispositivoV2.php';
require_once __DIR__ . '/DeviceBiometricGrantV2.php';
require_once __DIR__ . '/WorkplacePhysicalStationResolver.php';
require_once __DIR__ . '/InitialDeviceIdentityProvisioner.php';

final class DeviceIdentitySyncV2Controller
{
    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); $this->error(405, 'METHOD_NOT_ALLOWED'); }
        if (preg_match('~^application/json(?:\s*;|$)~i', (string)($_SERVER['CONTENT_TYPE'] ?? '')) !== 1) $this->error(415, 'UNSUPPORTED_MEDIA_TYPE');
        $raw = file_get_contents('php://input', false, null, 0, 1025);
        $body = is_string($raw) && strlen($raw) <= 1024 ? json_decode($raw, true) : null;
        $requestId = is_array($body) ? ($body['request_id'] ?? null) : null;
        if (!is_array($body) || count($body) !== 1 || !is_string($requestId) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $requestId) !== 1) $this->error(400, 'BAD_REQUEST');
        $deviceHeader = trim((string)($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));
        $key = trim((string)($_SERVER['HTTP_X_CREDENTIAL_KEY'] ?? ''));
        $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($deviceHeader === '' || $key === '' || preg_match('/\ABearer\s+(\S+)\z/i', $header, $m) !== 1) $this->error(401, 'DEVICE_UNAUTHORIZED');
        $db = null;
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $db = (new BD())->conectarBD();
            $db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $db->begin_transaction();
            foreach (['op_rh_v2_dispositivo_credenciales', 'op_rh_v2_device_biometric_grants', 'op_rh_v2_device_identity_sync_requests', 'tb_estaciones', 'op_rh_localidades'] as $table) {
                $engine = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                $engine->bind_param('s', $table); $engine->execute(); $info = $engine->get_result()->fetch_assoc(); $engine->close();
                if (strtoupper((string)($info['ENGINE'] ?? '')) !== 'INNODB') throw new RuntimeException('TRANSACTIONAL_TABLE_REQUIRED');
            }
            $auth = new DispositivoV2();
            $device = $auth->autenticarCredencial($db, $deviceHeader, $key, $m[1]);
            if (empty($device['auth'])) { $db->rollback(); $this->error(401, 'DEVICE_UNAUTHORIZED'); }
            $deviceId = (int)$device['id_dispositivo']; $stationId = (int)$device['id_estacion']; $credentialId = (int)$device['credential_id'];
            $lock = $db->prepare('SELECT id, id_estacion, estado FROM op_rh_v2_dispositivos WHERE id=? FOR UPDATE');
            $lock->bind_param('i', $deviceId); $lock->execute(); $locked = $lock->get_result()->fetch_assoc(); $lock->close();
            if (!$locked || (int)$locked['estado'] !== 1 || (int)$locked['id_estacion'] !== $stationId) { $db->rollback(); $this->error(401, 'DEVICE_UNAUTHORIZED'); }
            $lock = $db->prepare('SELECT id FROM op_rh_v2_dispositivo_credenciales WHERE id=? AND id_dispositivo=? FOR UPDATE');
            $lock->bind_param('ii', $credentialId, $deviceId); $lock->execute(); $exists = $lock->get_result()->num_rows === 1; $lock->close();
            $again = $auth->autenticarCredencial($db, $deviceHeader, $key, $m[1]);
            if (!$exists || empty($again['auth']) || (int)$again['credential_id'] !== $credentialId) { $db->rollback(); $this->error(401, 'DEVICE_UNAUTHORIZED'); }
            $lock = $db->prepare('SELECT id FROM tb_estaciones WHERE id=? AND estatus=0 FOR UPDATE');
            $lock->bind_param('i', $stationId); $lock->execute(); $stationExists = $lock->get_result()->num_rows === 1; $lock->close();
            $lock = $db->prepare('SELECT id FROM op_rh_v2_device_biometric_grants WHERE id_dispositivo=? AND id_estacion=? AND id_credencial=? AND activo=1 AND revocado_at_utc IS NULL AND valido_desde_utc<=UTC_TIMESTAMP(6) AND (valido_hasta_utc IS NULL OR UTC_TIMESTAMP(6)<valido_hasta_utc) FOR UPDATE');
            $lock->bind_param('iii', $deviceId, $stationId, $credentialId); $lock->execute(); $grantCount = $lock->get_result()->num_rows; $lock->close();
            $localityId = $stationExists ? WorkplacePhysicalStationResolver::resolveCanonicalLocality($db, $stationId) : null;
            if ($grantCount !== 1 || $localityId === null) { $db->rollback(); $this->error(403, 'DEVICE_SCOPE_DENIED'); }
            $lock = $db->prepare('SELECT id FROM op_rh_localidades WHERE id=? FOR UPDATE');
            $lock->bind_param('i', $localityId); $lock->execute(); $localityExists = $lock->get_result()->num_rows === 1; $lock->close();
            if (!$localityExists || WorkplacePhysicalStationResolver::resolveCanonicalLocality($db, $stationId) !== $localityId) { $db->rollback(); $this->error(403, 'DEVICE_SCOPE_DENIED'); }
            $fingerprint = hash('sha256', 'identity-sync-v1:' . $stationId);
            $stmt = $db->prepare('SELECT request_fingerprint, response_json FROM op_rh_v2_device_identity_sync_requests WHERE id_dispositivo=? AND request_id=? FOR UPDATE');
            $stmt->bind_param('is', $deviceId, $requestId); $stmt->execute(); $prior = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($prior) {
                if (!hash_equals((string)$prior['request_fingerprint'], $fingerprint)) { $db->rollback(); $this->error(409, 'IDEMPOTENCY_CONFLICT'); }
                $result = json_decode((string)$prior['response_json'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($result) || ($result['request_id'] ?? null) !== $requestId
                    || !is_int($result['created'] ?? null) || $result['created'] < 0
                    || !is_int($result['preserved'] ?? null) || $result['preserved'] < 0
                    || !is_int($result['conflicts'] ?? null) || $result['conflicts'] < 0) throw new RuntimeException('INVALID_RECORDED_RESPONSE');
            } else {
                $counts = (new InitialDeviceIdentityProvisioner($db))->run(true, $deviceId, $stationId, true, true);
                $result = ['request_id'=>$requestId, 'created'=>$counts['insertadas'], 'preserved'=>$counts['conservar'], 'conflicts'=>$counts['rechazar']];
                $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $stmt = $db->prepare('INSERT INTO op_rh_v2_device_identity_sync_requests (id_dispositivo,id_estacion,request_id,request_fingerprint,response_json,created_at_utc) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))');
                $stmt->bind_param('iisss', $deviceId, $stationId, $requestId, $fingerprint, $json); $stmt->execute(); $stmt->close();
            }
            $db->commit();
            echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); $db->close(); exit;
        } catch (Throwable) {
            if ($db instanceof mysqli) { try { $db->rollback(); } catch (Throwable) {} }
            $this->error(503, 'SYNC_UNAVAILABLE');
        }
    }
    private function error(int $code, string $status): never { http_response_code($code); echo json_encode(['code'=>$code,'status'=>$status]); exit; }
}
