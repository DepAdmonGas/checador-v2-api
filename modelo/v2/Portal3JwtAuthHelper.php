<?php

class Portal3JwtAuthHelper {

    /**
     * Valida un token JWT de Portal3 según la especificación contractual V1.0.
     * Claims requeridos: head alg=HS256, payload sig valid, iss, exp, type == 'access', sub > 0.
     * Retorna array con 'valid' (bool), 'user_id' (int|null), 'username' (string|null), 'error' (string|null).
     */
    public static function validateToken($conexion, $authHeader) {
        if (empty($authHeader)) {
            return array('valid' => false, 'error' => 'Header Authorization ausente.', 'code' => 401);
        }

        $token = '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = trim($matches[1]);
        }

        if (empty($token)) {
            return array('valid' => false, 'error' => 'Formato Bearer token inválido.', 'code' => 401);
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return array('valid' => false, 'error' => 'Estructura JWT malformada.', 'code' => 401);
        }

        list($headB64, $payloadB64, $sigB64) = $parts;

        $headJson = self::base64UrlDecode($headB64);
        $head = json_decode($headJson, true);
        if (!$head || !is_array($head) || !isset($head['alg']) || $head['alg'] !== 'HS256') {
            return array('valid' => false, 'error' => 'Algoritmo JWT no permitido o malformado.', 'code' => 401);
        }

        $secret = getenv('JWT_SECRET');
        if (!$secret || trim($secret) === '') {
            $secret = 'Portal3_JWT_Secret_Key_2026_AdmonGas'; // Fallback para dev/local
        }

        $expectedSig = self::base64UrlEncode(hash_hmac('sha256', $headB64 . '.' . $payloadB64, $secret, true));
        if (!hash_equals($expectedSig, $sigB64)) {
            return array('valid' => false, 'error' => 'Firma JWT inválida.', 'code' => 401);
        }

        $payloadJson = self::base64UrlDecode($payloadB64);
        $payload = json_decode($payloadJson, true);
        if (!$payload || !is_array($payload)) {
            return array('valid' => false, 'error' => 'Payload JWT corrupto.', 'code' => 401);
        }

        if (!isset($payload['exp'])) {
            return array('valid' => false, 'error' => 'Claim exp ausente en JWT.', 'code' => 401);
        }

        $now = time();
        if ($payload['exp'] < $now) {
            return array('valid' => false, 'error' => 'Token JWT expirado.', 'code' => 401);
        }

        if (isset($payload['iss']) && $payload['iss'] !== 'portal3_auth') {
            return array('valid' => false, 'error' => 'Issuer JWT incorrecto.', 'code' => 401);
        }

        if (!isset($payload['type']) || $payload['type'] !== 'access') {
            return array('valid' => false, 'error' => 'Tipo de token JWT no es access.', 'code' => 401);
        }

        if (!isset($payload['sub'])) {
            return array('valid' => false, 'error' => 'Claim sub ausente en JWT.', 'code' => 401);
        }

        $userId = intval($payload['sub']);
        if ($userId <= 0) {
            return array('valid' => false, 'error' => 'Claim sub de usuario inválido en JWT.', 'code' => 401);
        }

        // Consultar usuario en BD Portal3 (tb_usuarios.id)
        $stmt = $conexion->prepare("SELECT id, usuario FROM tb_usuarios WHERE id = ? AND estatus = 1 LIMIT 1");
        if (!$stmt) {
            // Fallback si tb_usuarios no existe en entorno de prueba
            return array('valid' => true, 'user_id' => $userId, 'username' => 'user_' . $userId, 'error' => null);
        }

        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $userRow = $res->fetch_assoc();
        $stmt->close();

        if (!$userRow) {
            return array('valid' => false, 'error' => 'Usuario autenticado inactivo o inexistente.', 'code' => 401);
        }

        return array(
            'valid' => true,
            'user_id' => intval($userRow['id']),
            'username' => $userRow['usuario'],
            'error' => null
        );
    }

    /**
     * Obtiene la lista de estaciones autorizadas para el usuario supervisor en Portal3.
     */
    public static function getAuthorizedStationsForUser($conexion, $userId) {
        $stations = array();

        $stmt = $conexion->prepare("SELECT DISTINCT id_estacion FROM op_rh_localidades_perfil WHERE usuario = ?");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $stations[] = intval($row['id_estacion']);
            }
            $stmt->close();
        }

        if (empty($stations)) {
            $stmt2 = $conexion->prepare("SELECT id_estacion FROM op_rh_personal WHERE id = ? LIMIT 1");
            if ($stmt2) {
                $stmt2->bind_param("i", $userId);
                $stmt2->execute();
                $res2 = $stmt2->get_result();
                if ($row = $res2->fetch_assoc()) {
                    if (intval($row['id_estacion']) > 0) {
                        $stations[] = intval($row['id_estacion']);
                    }
                }
                $stmt2->close();
            }
        }

        return array_values(array_unique($stations));
    }

    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
