<?php

declare(strict_types=1);
require_once __DIR__ . '/WorkplacePhysicalStationResolver.php';
require_once __DIR__ . '/DeviceBiometricGrantV2.php';

final class DeviceLinkV2Service
{
    public static function validToken($value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }

    public function consume(mysqli $db, string $token): ?array
    {
        if (!self::validToken($token)) throw new InvalidArgumentException('INVALID_REQUEST');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $hash = hash('sha256', $token);
        $db->begin_transaction();
        try {
            $stmt = $db->prepare('SELECT id, id_dispositivo, id_estacion, emitido_por_usuario_id, emitido_at_utc, expira_at_utc, consumido_at_utc, revocado_at_utc, (emitido_at_utc <= UTC_TIMESTAMP(6) AND UTC_TIMESTAMP(6) < expira_at_utc AND TIMESTAMPDIFF(MICROSECOND, emitido_at_utc, expira_at_utc) = 900000000) AS vigente FROM op_rh_v2_device_link_tokens WHERE token_sha256=? FOR UPDATE');
            $stmt->bind_param('s', $hash); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$row || (int)$row['vigente'] !== 1 || (int)$row['emitido_por_usuario_id'] < 1 || $row['consumido_at_utc'] !== null || $row['revocado_at_utc'] !== null) { $db->rollback(); return null; }
            $deviceId = (int)$row['id_dispositivo']; $stationId = (int)$row['id_estacion'];
            $stmt = $db->prepare('SELECT id, uuid_dispositivo, id_estacion, nombre, timezone, estado FROM op_rh_v2_dispositivos WHERE id=? FOR UPDATE');
            $stmt->bind_param('i', $deviceId); $stmt->execute(); $device = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$device || (int)$device['estado'] !== 1 || (int)$device['id_estacion'] !== $stationId) { $db->rollback(); return null; }
            $stmt = $db->prepare('SELECT nombre FROM tb_estaciones WHERE id=? AND estatus=0 FOR UPDATE');
            $stmt->bind_param('i', $stationId); $stmt->execute(); $station = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$station || trim((string)$station['nombre']) === '' || WorkplacePhysicalStationResolver::resolveCanonicalLocality($db, $stationId) === null) { $db->rollback(); return null; }
            new DateTimeZone((string)$device['timezone']);
            $key = bin2hex(random_bytes(16)); $secret = bin2hex(random_bytes(32));
            $secretHash = password_hash($secret, PASSWORD_BCRYPT);
            if (!is_string($secretHash)) throw new RuntimeException('CREDENTIAL_HASH_FAILED');
            $stmt = $db->prepare("UPDATE op_rh_v2_dispositivo_credenciales SET estado='REVOKED', revocado_at=UTC_TIMESTAMP() WHERE id_dispositivo=? AND estado='ACTIVE'");
            $stmt->bind_param('i', $deviceId); $stmt->execute(); $stmt->close();
            $stmt = $db->prepare("INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, valido_desde, valido_hasta, estado, creado_at, revocado_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), NULL, 'ACTIVE', UTC_TIMESTAMP(), NULL)");
            $stmt->bind_param('iss', $deviceId, $key, $secretHash); $stmt->execute(); $stmt->close();
            $credentialId = (int)$db->insert_id;
            $stmt = $db->prepare('UPDATE op_rh_v2_device_biometric_grants SET activo=0, revocado_at_utc=UTC_TIMESTAMP(6) WHERE id_dispositivo=? AND activo=1 AND revocado_at_utc IS NULL');
            $stmt->bind_param('i', $deviceId); $stmt->execute(); $stmt->close();
            $actorId = (int)$row['emitido_por_usuario_id'];
            $stmt = $db->prepare('INSERT INTO op_rh_v2_device_biometric_grants (id_dispositivo,id_estacion,id_credencial,autorizado_por_usuario_id,valido_desde_utc,valido_hasta_utc,activo,revocado_at_utc) VALUES (?,?,?,?,UTC_TIMESTAMP(6),NULL,1,NULL)');
            $stmt->bind_param('iiii', $deviceId, $stationId, $credentialId, $actorId); $stmt->execute(); $stmt->close();
            $this->invalidateAdministrativeSessionsForCredentialRotation($db, $deviceId, $stationId);
            $tokenId = (int)$row['id'];
            $stmt = $db->prepare('UPDATE op_rh_v2_device_link_tokens SET consumido_at_utc=UTC_TIMESTAMP(6) WHERE id=? AND consumido_at_utc IS NULL AND revocado_at_utc IS NULL');
            $stmt->bind_param('i', $tokenId); $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new RuntimeException('TOKEN_CONSUME_FAILED');
            $stmt->close(); $db->commit();
            error_log(json_encode(['event'=>'CHECADOR_V2_DEVICE_LINK','device_id'=>$deviceId,'station_id'=>$stationId,'timestamp_utc'=>gmdate('c')]));
            return ['device_id'=>$deviceId, 'device_uuid'=>(string)$device['uuid_dispositivo'], 'credential_key'=>$key, 'device_secret'=>$secret, 'id_estacion'=>$stationId, 'station_name'=>(string)$station['nombre'], 'timezone'=>(string)$device['timezone']];
        } catch (Throwable $e) { $db->rollback(); throw $e; }
    }

    /** A credential/grant rotation invalidates every outstanding admin PIN and session atomically. */
    private function invalidateAdministrativeSessionsForCredentialRotation(mysqli $db, int $deviceId, int $stationId): void
    {
        $present = [];
        foreach (['op_rh_v2_admin_pin', 'op_rh_v2_admin_session'] as $table) {
            $stmt = $db->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
            $stmt->bind_param('s', $table); $stmt->execute(); $exists = $stmt->get_result()->fetch_assoc() !== null; $stmt->close();
            $present[] = $exists;
        }
        if (!$present[0] && !$present[1]) {
            if (getenv('CHECADOR_ADMIN_PIN_ENABLED') === '1') throw new RuntimeException('ADMIN_PIN_SCHEMA_UNAVAILABLE');
            return;
        }
        if (!$present[0] || !$present[1]) throw new RuntimeException('ADMIN_PIN_SCHEMA_UNAVAILABLE');
        $stmt = $db->prepare("SELECT id,locality_id,actor_user_id FROM op_rh_v2_admin_pin WHERE device_id=? AND station_id=? AND state='PENDING' FOR UPDATE");
        $stmt->bind_param('ii', $deviceId, $stationId); $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        foreach ($rows as $row) {
            $id=(int)$row['id']; $localityId=(int)$row['locality_id']; $actorId=(int)$row['actor_user_id'];
            $stmt=$db->prepare("UPDATE op_rh_v2_admin_pin SET state='REVOKED',revoked_at_utc=UTC_TIMESTAMP(6) WHERE id=? AND state='PENDING'");
            $stmt->bind_param('i',$id);$stmt->execute();$stmt->close();
            $this->auditAdministrativeInvalidation($db,$actorId,$localityId,'ADMIN_PIN',$id);
        }
        $stmt = $db->prepare("SELECT id,locality_id,actor_user_id FROM op_rh_v2_admin_session WHERE device_id=? AND station_id=? AND state='ACTIVE' FOR UPDATE");
        $stmt->bind_param('ii', $deviceId, $stationId); $stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        foreach ($rows as $row) {
            $id=(int)$row['id']; $localityId=(int)$row['locality_id']; $actorId=(int)$row['actor_user_id'];
            $stmt=$db->prepare("UPDATE op_rh_v2_admin_session SET state='REVOKED',ended_at_utc=UTC_TIMESTAMP(6) WHERE id=? AND state='ACTIVE'");
            $stmt->bind_param('i',$id);$stmt->execute();$stmt->close();
            $this->auditAdministrativeInvalidation($db,$actorId,$localityId,'ADMIN_SESSION',$id);
        }
    }

    private function auditAdministrativeInvalidation(mysqli $db,int $actorId,int $localityId,string $entity,int $entityId):void
    {
        $stmt=$db->prepare('INSERT INTO op_rh_v2_m11_configuration_audit (actor_portal_user_id,permission_key,workplace_id,entity_type,entity_id,action,created_at_utc) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $permission='checador.admin_pin.revocar';$action='CREDENTIAL_ROTATE';
        $stmt->bind_param('isisss',$actorId,$permission,$localityId,$entity,$entityId,$action);$stmt->execute();$stmt->close();
    }
}
