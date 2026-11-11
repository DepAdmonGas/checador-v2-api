<?php
declare(strict_types=1);

require_once __DIR__ . '/Portal3JwtAuthHelper.php';

/** Read-only, module-scoped authorization for the new administrative-adjustment projection. */
final class AdministrativeAdjustmentReadAuthorization
{
    public const MODULE_KEY = 'checador-v2-ajustes';

    public static function decidePermission(?array $directSameModule, ?array $positionGrant): bool
    {
        $grant = $directSameModule ?? $positionGrant;
        return $grant !== null && (int) ($grant['leer'] ?? 0) === 1;
    }

    public static function authorize(mysqli $db, array $user): array
    {
        try {
            $module = self::one($db, 'SELECT id, activo FROM modulos_dpto_operativo WHERE clave = ? LIMIT 1', 's', [self::MODULE_KEY]);
            if (!$module || (int) ($module['activo'] ?? 0) !== 1) {
                return self::denied('FUNCTION_PERMISSION_DENIED', 403);
            }
            $userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
            $positionId = (int) ($user['id_puesto'] ?? 0);
            if ($userId < 1) return self::denied('UNAUTHORIZED', 401);

            $directRows = self::rows($db, 'SELECT leer FROM modulos_usuarios_do WHERE id_modulo = ? AND id_usuario = ?', 'ii', [(int) $module['id'], $userId]);
            if (count($directRows) > 1) return self::denied('FUNCTION_PERMISSION_DENIED', 403);
            $direct = $directRows[0] ?? null;
            $positionRows = [];
            if ($direct === null && $positionId > 0) {
                $positionRows = self::rows($db, 'SELECT leer FROM modulos_puestos_do WHERE id_modulo = ? AND id_puesto = ?', 'ii', [(int) $module['id'], $positionId]);
                if (count($positionRows) > 1) return self::denied('FUNCTION_PERMISSION_DENIED', 403);
            }
            $position = $positionRows[0] ?? null;
            if (!self::decidePermission($direct, $position)) return self::denied('FUNCTION_PERMISSION_DENIED', 403);

            $columns = ['activo', 'estaciones_soportadas', 'tipo_departamento'];
            foreach (['tipo', 'allow_all', 'departamentos_soportados'] as $optional) {
                if (Portal3JwtAuthHelper::hasColumn($db, 'tb_modulos_config', $optional)) $columns[] = $optional;
            }
            $config = self::one($db, 'SELECT ' . implode(', ', $columns) . ' FROM tb_modulos_config WHERE modulo_key = ? LIMIT 1', 's', [self::MODULE_KEY]);
            if (!$config || (int) ($config['activo'] ?? 0) !== 1 || (int) ($config['allow_all'] ?? 0) === 1) {
                return self::denied('STATION_SCOPE_DENIED', 403);
            }
            $kind = (string) ($config['tipo'] ?? 'stations_only');
            $departmentType = (string) ($config['tipo_departamento'] ?? '');
            $departmentMode = $kind === 'stations_and_departments' && $departmentType === 'localidades';
            $stationMode = $kind === 'stations_only' && in_array($departmentType, ['localidades', 'puestos'], true);
            if (!$departmentMode && !$stationMode) return self::denied('STATION_SCOPE_DENIED', 403);
            $supported = self::decodeIds($config['estaciones_soportadas'] ?? null);
            if (!$supported && !$departmentMode) return self::denied('STATION_SCOPE_DENIED', 403);
            $authorized = Portal3JwtAuthHelper::resolveStationScope($db, $user, $supported, $config);
            if (!$authorized) return self::denied('STATION_SCOPE_DENIED', 403);
            return ['success' => true, 'authorized_workplaces' => array_values(array_unique(array_map('intval', $authorized)))];
        } catch (Throwable $error) {
            return self::denied('AUTH_DEPENDENCY_UNAVAILABLE', 503);
        }
    }

    private static function decodeIds(mixed $value): array
    {
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

    private static function one(mysqli $db, string $sql, string $types, array $params): ?array
    {
        return self::rows($db, $sql, $types, $params)[0] ?? null;
    }

    private static function rows(mysqli $db, string $sql, string $types, array $params): array
    {
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new RuntimeException('Authorization query unavailable');
        if ($params !== []) {
            $values = array_values($params);
            $refs = [$types];
            foreach ($values as &$value) $refs[] = &$value;
            unset($value);
            if (!call_user_func_array([$stmt, 'bind_param'], $refs)) {
                $stmt->close();
                throw new RuntimeException('Authorization bind unavailable');
            }
        }
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Authorization execution unavailable');
        }
        $result = $stmt->get_result();
        if (!$result) {
            $stmt->close();
            throw new RuntimeException('Authorization result unavailable');
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private static function denied(string $code, int $status): array
    {
        return ['success' => false, 'code' => $code, 'http_status' => $status];
    }
}
