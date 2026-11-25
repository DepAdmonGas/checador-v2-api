<?php

declare(strict_types=1);

final class DeviceBiometricGrantV2
{
    public static function authorized(mysqli $db, int $deviceId, int $stationId, int $credentialId): bool
    {
        $stmt = $db->prepare('SELECT id FROM op_rh_v2_device_biometric_grants WHERE id_dispositivo=? AND id_estacion=? AND id_credencial=? AND activo=1 AND revocado_at_utc IS NULL AND valido_desde_utc <= UTC_TIMESTAMP(6) AND (valido_hasta_utc IS NULL OR UTC_TIMESTAMP(6) < valido_hasta_utc)');
        if (!$stmt) throw new RuntimeException('DEVICE_BIOMETRIC_GRANT_QUERY_UNAVAILABLE', $db->errno);
        $stmt->bind_param('iii', $deviceId, $stationId, $credentialId);
        if (!$stmt->execute()) {
            $code = $stmt->errno;
            $stmt->close();
            throw new RuntimeException('DEVICE_BIOMETRIC_GRANT_QUERY_UNAVAILABLE', $code);
        }
        $count = $stmt->get_result()->num_rows;
        $stmt->close();
        return $count === 1;
    }
}
