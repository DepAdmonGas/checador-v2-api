<?php
include_once __DIR__ . "/../BD.php";

class DispositivoV2 {

    private function esIdNumericoCanonico(string $identificador): bool {
        return preg_match('/^[1-9][0-9]*$/', $identificador) === 1;
    }

    public function obtenerDispositivoPorUuid($conexion, $uuidDispositivo) {
        $identificador = trim((string)$uuidDispositivo);
        if ($identificador === '') {
            return null;
        }

        if ($this->esIdNumericoCanonico($identificador)) {
            $idDispositivo = (int)$identificador;
            $stmt = mysqli_prepare(
                $conexion,
                "SELECT id, uuid_dispositivo, id_estacion, nombre, timezone, estado
                 FROM op_rh_v2_dispositivos
                 WHERE id = ? AND estado = 1 LIMIT 1"
            );
            if (!$stmt) {
                return null;
            }
            mysqli_stmt_bind_param($stmt, 'i', $idDispositivo);
        } elseif (ctype_digit($identificador)) {
            return null;
        } else {
            $stmt = mysqli_prepare(
                $conexion,
                "SELECT id, uuid_dispositivo, id_estacion, nombre, timezone, estado
                 FROM op_rh_v2_dispositivos
                 WHERE uuid_dispositivo = ? AND estado = 1 LIMIT 1"
            );
            if (!$stmt) {
                return null;
            }
            mysqli_stmt_bind_param($stmt, 's', $identificador);
        }

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = ($result && mysqli_num_rows($result) > 0) ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        return $row ?: null;
    }

    public function autenticarCredencial($conexion, $uuidDispositivo, $credentialKey, $secret) {
        $identificador = trim((string)$uuidDispositivo);
        $credKey = trim((string)$credentialKey);
        $secretStr = (string)$secret;

        if ($identificador === '' || $credKey === '' || $secretStr === '') {
            return array('auth' => false, 'error' => 'CREDENTIAL_NOT_FOUND');
        }

        if ($this->esIdNumericoCanonico($identificador)) {
            $idDispositivo = (int)$identificador;
            $sql = "SELECT c.id AS credential_id, c.id_dispositivo, c.secret_hash, c.estado AS credential_status,
                           c.valido_desde, c.valido_hasta, c.revocado_at,
                           d.uuid_dispositivo, d.nombre AS device_name, d.id_estacion,
                           d.timezone AS device_timezone, d.estado AS device_status
                    FROM op_rh_v2_dispositivo_credenciales c
                    INNER JOIN op_rh_v2_dispositivos d ON c.id_dispositivo = d.id
                    WHERE d.id = ?
                      AND c.credential_key = ? LIMIT 1";
            $stmt = mysqli_prepare($conexion, $sql);
            if (!$stmt) {
                return array('auth' => false, 'error' => 'CREDENTIAL_NOT_FOUND');
            }
            mysqli_stmt_bind_param($stmt, 'is', $idDispositivo, $credKey);
        } elseif (ctype_digit($identificador)) {
            return array('auth' => false, 'error' => 'CREDENTIAL_NOT_FOUND');
        } else {
            $sql = "SELECT c.id AS credential_id, c.id_dispositivo, c.secret_hash, c.estado AS credential_status,
                           c.valido_desde, c.valido_hasta, c.revocado_at,
                           d.uuid_dispositivo, d.nombre AS device_name, d.id_estacion,
                           d.timezone AS device_timezone, d.estado AS device_status
                    FROM op_rh_v2_dispositivo_credenciales c
                    INNER JOIN op_rh_v2_dispositivos d ON c.id_dispositivo = d.id
                    WHERE d.uuid_dispositivo = ?
                      AND c.credential_key = ? LIMIT 1";
            $stmt = mysqli_prepare($conexion, $sql);
            if (!$stmt) {
                return array('auth' => false, 'error' => 'CREDENTIAL_NOT_FOUND');
            }
            mysqli_stmt_bind_param($stmt, 'ss', $identificador, $credKey);
        }

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if (!$result || mysqli_num_rows($result) === 0) {
            mysqli_stmt_close($stmt);
            return array('auth' => false, 'error' => 'CREDENTIAL_NOT_FOUND');
        }

        $row = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if ((int)$row['device_status'] !== 1) {
            return array('auth' => false, 'error' => 'DEVICE_DISABLED');
        }

        if ($row['credential_status'] !== 'ACTIVE' || !empty($row['revocado_at'])) {
            return array('auth' => false, 'error' => 'CREDENTIAL_REVOKED_OR_INACTIVE');
        }

        $now = gmdate('Y-m-d H:i:s');
        if ($row['valido_desde'] > $now) {
            return array('auth' => false, 'error' => 'CREDENTIAL_NOT_YET_VALID');
        }
        if (!empty($row['valido_hasta']) && $row['valido_hasta'] <= $now) {
            return array('auth' => false, 'error' => 'CREDENTIAL_EXPIRED');
        }

        if (!password_verify($secretStr, $row['secret_hash'])) {
            return array('auth' => false, 'error' => 'INVALID_SECRET');
        }

        return array(
            'auth' => true,
            'id_dispositivo' => (int)$row['id_dispositivo'],
            'uuid_dispositivo' => $row['uuid_dispositivo'],
            'device_name' => $row['device_name'] ?? '',
            'id_estacion' => (int)$row['id_estacion'],
            'device_timezone' => $row['device_timezone']
        );
    }
}
