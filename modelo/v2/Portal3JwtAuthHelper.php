<?php

class Portal3JwtDependencyException extends RuntimeException {}

class Portal3JwtAuthHelper {

    private const TEMPORARY_ACCESS_MODULE = 'acceso-temporal';

    /** Validate the canonical Portal3 token and resolve its active human identity. */
    public static function validateToken($conexion, $authHeader) {
        if (empty($authHeader)) return self::failure(401, 'UNAUTHORIZED', 'Header Authorization ausente.');
        if (!preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $matches)) return self::failure(401, 'UNAUTHORIZED', 'Formato Bearer token inválido.');
        $parts = explode('.', trim($matches[1]));
        if (count($parts) !== 3) return self::failure(401, 'UNAUTHORIZED', 'Estructura JWT malformada.');
        [$headB64, $payloadB64, $sigB64] = $parts;
        $head = json_decode(self::base64UrlDecode($headB64), true);
        if (!is_array($head) || ($head['alg'] ?? null) !== 'HS256') return self::failure(401, 'UNAUTHORIZED', 'Algoritmo JWT no permitido o malformado.');

        $secret = getenv('JWT_SECRET');
        if (!$secret || trim($secret) === '') return self::failure(500, 'INTERNAL_ERROR', 'Configuración de seguridad del servidor no disponible.');
        $expectedSig = self::base64UrlEncode(hash_hmac('sha256', $headB64 . '.' . $payloadB64, $secret, true));
        if (!hash_equals($expectedSig, $sigB64)) return self::failure(401, 'UNAUTHORIZED', 'Firma JWT inválida.');

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) return self::failure(401, 'UNAUTHORIZED', 'Payload JWT corrupto.');
        if (!isset($payload['iss']) || $payload['iss'] !== 'portal3_auth') return self::failure(401, 'UNAUTHORIZED', 'Issuer JWT ausente o incorrecto.');
        if (!isset($payload['exp']) || !self::isIntegerClaim($payload['exp']) || (int) $payload['exp'] <= time()) return self::failure(401, 'UNAUTHORIZED', 'Token JWT expirado o sin claim exp válido.');
        if (($payload['type'] ?? null) !== 'access') return self::failure(401, 'UNAUTHORIZED', 'Tipo de token JWT no es access.');
        if (!isset($payload['sub']) || !self::isIntegerClaim($payload['sub']) || (int) $payload['sub'] <= 0) return self::failure(401, 'UNAUTHORIZED', 'Claim sub de usuario inválido en JWT.');

        try {
            $user = self::selectOne($conexion, 'SELECT id, usuario, id_gas, id_puesto FROM tb_usuarios WHERE id = ? AND estatus = 0 LIMIT 1', 'i', [(int) $payload['sub']]);
        } catch (Portal3JwtDependencyException $exception) {
            return self::failure(503, 'AUTH_DEPENDENCY_UNAVAILABLE', 'No fue posible validar el usuario Portal3.');
        } catch (Throwable $exception) {
            return self::failure(500, 'INTERNAL_ERROR', 'Error interno al validar el usuario Portal3.');
        }
        if (!$user) return self::failure(401, 'UNAUTHORIZED', 'Usuario autenticado inactivo o inexistente.');

        return ['valid' => true, 'success' => true, 'code' => 200,
            'user_id' => (int) $user['id'], 'username' => (string) ($user['usuario'] ?? ''),
            'id_gas' => isset($user['id_gas']) ? (int) $user['id_gas'] : null,
            'id_puesto' => isset($user['id_puesto']) ? (int) $user['id_puesto'] : null, 'error' => null];
    }

    /** Resolve fresh function ACL plus station IDs in op_rh_localidades space. */
    public static function authorizeTemporaryAccess($conexion, array $user, string $requiredPermission) {
        if (!in_array($requiredPermission, ['leer', 'editar'], true)) return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'Permiso funcional no disponible.');
        try {
            $module = self::selectOne($conexion, 'SELECT id, activo FROM modulos_dpto_operativo WHERE clave = ? LIMIT 1', 's', [self::TEMPORARY_ACCESS_MODULE]);
            if (!$module || (int) ($module['activo'] ?? 0) !== 1) return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'Acceso no autorizado.');

            $userId = (int) ($user['user_id'] ?? 0);
            if ($userId <= 0) return self::failure(401, 'UNAUTHORIZED', 'Identidad Portal3 inválida.');
            $hasDirect = self::selectOne($conexion, 'SELECT id FROM modulos_usuarios_do WHERE id_usuario = ? LIMIT 1', 'i', [$userId]) !== null;
            if ($hasDirect) {
                $grant = self::selectOne($conexion, 'SELECT leer, crear, editar, eliminar, descargar FROM modulos_usuarios_do WHERE id_modulo = ? AND id_usuario = ? LIMIT 1', 'ii', [(int) $module['id'], $userId]);
            } else {
                $puestoId = (int) ($user['id_puesto'] ?? 0);
                $grant = $puestoId > 0 ? self::selectOne($conexion, 'SELECT leer, crear, editar, eliminar, descargar FROM modulos_puestos_do WHERE id_modulo = ? AND id_puesto = ? LIMIT 1', 'ii', [(int) $module['id'], $puestoId]) : null;
            }
            if (!$grant || (int) ($grant[$requiredPermission] ?? 0) !== 1) return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'Acceso no autorizado.');

            $moduleConfig = self::selectOne($conexion, 'SELECT activo, estaciones_soportadas, tipo_departamento FROM tb_modulos_config WHERE modulo_key = ? LIMIT 1', 's', [self::TEMPORARY_ACCESS_MODULE]);
            if (!$moduleConfig || (int) ($moduleConfig['activo'] ?? 0) !== 1 || ($moduleConfig['tipo_departamento'] ?? null) !== 'localidades') return self::failure(403, 'STATION_SCOPE_DENIED', 'Alcance de estación no disponible.');
            $supportedStations = self::decodeIdList($moduleConfig['estaciones_soportadas'] ?? null);
            if (!$supportedStations) return self::failure(403, 'STATION_SCOPE_DENIED', 'Alcance de estación no disponible.');
            $authorizedStations = self::resolveStationScope($conexion, $user, $supportedStations);
            if (!$authorizedStations) return self::failure(403, 'STATION_SCOPE_DENIED', 'Alcance de estación no disponible.');
            return ['success' => true, 'code' => 200, 'status' => 'AUTHORIZED', 'authorized_stations' => $authorizedStations];
        } catch (Portal3JwtDependencyException $exception) {
            return self::failure(503, 'AUTH_DEPENDENCY_UNAVAILABLE', 'No fue posible resolver permisos o alcance de estación.');
        } catch (Throwable $exception) {
            return self::failure(500, 'INTERNAL_ERROR', 'Error interno al resolver autorización.');
        }
    }

    private static function resolveStationScope($conexion, array $user, array $supportedStations): array {
        $userId = (int) ($user['user_id'] ?? 0);
        $puestoId = (int) ($user['id_puesto'] ?? 0);
        $userConfig = self::selectOne($conexion, 'SELECT estaciones FROM tb_multiestacion_usuario WHERE id_usuario = ? AND activo = 1 LIMIT 1', 'i', [$userId]);
        $puestoConfig = $puestoId > 0 ? self::selectOne($conexion, 'SELECT estaciones FROM tb_multiestacion_puesto WHERE id_puesto = ? AND activo = 1 LIMIT 1', 'i', [$puestoId]) : null;
        if ($userConfig) {
            $userStations = $userConfig['estaciones'] ?? null;
            if ($userStations === null || (is_string($userStations) && strtolower(trim($userStations)) === 'null')) {
                $stationIds = $puestoConfig ? self::decodeIdList($puestoConfig['estaciones'] ?? null) : [];
            } else {
                $stationIds = self::decodeIdList($userStations);
            }
        } elseif ($puestoConfig) {
            $stationIds = self::decodeIdList($puestoConfig['estaciones'] ?? null);
        } else {
            $homeStationId = (int) ($user['id_gas'] ?? 0);
            $stationIds = $homeStationId > 0 ? [$homeStationId] : [];
        }
        $stationIds = array_values(array_intersect($stationIds, $supportedStations));
        if (!$stationIds) return [];

        $localityIds = [];
        foreach ($stationIds as $stationId) {
            $station = self::selectOne($conexion, 'SELECT id, numlista FROM tb_estaciones WHERE id = ? AND estatus = 0 LIMIT 1', 'i', [$stationId]);
            if (!$station || (int) ($station['numlista'] ?? 0) <= 0) continue;
            $locality = self::selectOne($conexion, 'SELECT id FROM op_rh_localidades WHERE numlista = ? LIMIT 1', 'i', [(int) $station['numlista']]);
            if ($locality && (int) ($locality['id'] ?? 0) > 0) $localityIds[] = (int) $locality['id'];
        }
        return array_values(array_unique($localityIds));
    }

    private static function decodeIdList($value): array {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || $value === '*' || strtolower($value) === 'null') return [];
            $value = json_decode($value, true);
        }
        if (!is_array($value)) return [];
        $ids = [];
        foreach ($value as $candidate) {
            if ((is_int($candidate) || (is_string($candidate) && ctype_digit($candidate))) && (int) $candidate > 0) $ids[] = (int) $candidate;
        }
        return array_values(array_unique($ids));
    }

    private static function isIntegerClaim($value): bool {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    private static function selectOne($conexion, string $sql, string $types = '', array $params = []): ?array {
        return self::selectRows($conexion, $sql, $types, $params)[0] ?? null;
    }

    private static function selectRows($conexion, string $sql, string $types = '', array $params = []): array {
        $stmt = $conexion->prepare($sql);
        if (!$stmt) throw new Portal3JwtDependencyException('Database prepare failed.');
        try {
            if ($params) {
                $values = array_values($params);
                $bindArgs = [$types];
                foreach ($values as &$value) $bindArgs[] = &$value;
                unset($value);
                if (!call_user_func_array([$stmt, 'bind_param'], $bindArgs)) throw new Portal3JwtDependencyException('Database parameter binding failed.');
            }
            if (!$stmt->execute()) throw new Portal3JwtDependencyException('Database query failed.');
            $result = $stmt->get_result();
            if (!$result) throw new Portal3JwtDependencyException('Database result unavailable.');
            $rows = [];
            while ($row = $result->fetch_assoc()) $rows[] = $row;
            return $rows;
        } catch (mysqli_sql_exception $exception) {
            throw new Portal3JwtDependencyException('Database dependency unavailable.', 0, $exception);
        } finally {
            $stmt->close();
        }
    }

    private static function failure(int $code, string $status, string $message): array {
        return ['valid' => false, 'success' => false, 'code' => $code, 'status' => $status, 'error' => $message, 'message' => $message];
    }

    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
