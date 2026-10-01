<?php
include_once __DIR__ . "/../BD.php";

class DispositivoV2 {

    public function obtenerDispositivoPorUuid($conexion, $uuidDispositivo) {
        $uuidEscaped = mysqli_real_escape_string($conexion, $uuidDispositivo);
        $sql = "SELECT id, uuid_dispositivo, id_estacion, nombre, timezone, estado 
                FROM op_rh_v2_dispositivos 
                WHERE uuid_dispositivo = '$uuidEscaped' AND estado = 1 LIMIT 1";
        $result = mysqli_query($conexion, $sql);
        if ($result && mysqli_num_rows($result) > 0) {
            return mysqli_fetch_assoc($result);
        }
        return null;
    }

    public function autenticarCredencial($conexion, $uuidDispositivo, $credentialKey, $secret) {
        $uuidEscaped = mysqli_real_escape_string($conexion, $uuidDispositivo);
        $credKeyEscaped = mysqli_real_escape_string($conexion, $credentialKey);

        $sql = "SELECT c.id AS credential_id, c.id_dispositivo, c.secret_hash, c.estado AS credential_status,
                       c.valido_desde, c.valido_hasta, d.id_estacion, d.timezone AS device_timezone, d.estado AS device_status
                FROM op_rh_v2_dispositivo_credenciales c
                INNER JOIN op_rh_v2_dispositivos d ON c.id_dispositivo = d.id
                WHERE d.uuid_dispositivo = '$uuidEscaped' 
                  AND c.credential_key = '$credKeyEscaped' LIMIT 1";

        $result = mysqli_query($conexion, $sql);
        if (!$result || mysqli_num_rows($result) === 0) {
            return array('auth' => false, 'error' => 'CREDENTIAL_NOT_FOUND');
        }

        $row = mysqli_fetch_assoc($result);

        if ((int)$row['device_status'] !== 1) {
            return array('auth' => false, 'error' => 'DEVICE_DISABLED');
        }

        if ($row['credential_status'] !== 'ACTIVE') {
            return array('auth' => false, 'error' => 'CREDENTIAL_REVOKED_OR_INACTIVE');
        }

        $now = gmdate('Y-m-d H:i:s');
        if ($row['valido_desde'] > $now) {
            return array('auth' => false, 'error' => 'CREDENTIAL_NOT_YET_VALID');
        }
        if (!empty($row['valido_hasta']) && $row['valido_hasta'] <= $now) {
            return array('auth' => false, 'error' => 'CREDENTIAL_EXPIRED');
        }

        if (!password_verify($secret, $row['secret_hash'])) {
            return array('auth' => false, 'error' => 'INVALID_SECRET');
        }

        return array(
            'auth' => true,
            'id_dispositivo' => (int)$row['id_dispositivo'],
            'id_estacion' => (int)$row['id_estacion'],
            'device_timezone' => $row['device_timezone']
        );
    }
}
