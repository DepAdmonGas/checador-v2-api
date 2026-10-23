<?php

class Portal3JwtDependencyException extends RuntimeException {}

class Portal3JwtAuthHelper {

    public const ENCARGADO_PUESTO_ID = 6;
    public const DIRECCION_OPERACIONES_PUESTO_ID = 13;
    public const ENCARGADO_TURNO_PUESTO_ID = 3;
    public const PUESTO_14_ID = 14;
    public const PALO_SOLO_LOCALITY_ID = 2;
    public const AUTOLAVADO_LOCALITY_ID = 9;

    private const TEMPORARY_ACCESS_MODULE = 'acceso-temporal';

    private static array $columnCache = [];

    public static function clearCache(): void {
        self::$columnCache = [];
    }

    public static function hasColumn($conexion, string $table, string $column): bool {
        $key = "{$table}.{$column}";
        if (isset(self::$columnCache[$key])) {
            return self::$columnCache[$key];
        }
        try {
            $escapedTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
            $escapedCol = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
            $sql = "SELECT `{$escapedCol}` FROM `{$escapedTable}` WHERE 1 = 0";
            if (method_exists($conexion, 'query')) {
                $res = @$conexion->query($sql);
                $exists = ($res !== false);
                if ($res && is_object($res) && method_exists($res, 'free')) {
                    $res->free();
                }
                self::$columnCache[$key] = $exists;
                return $exists;
            }
            self::$columnCache[$key] = false;
            return false;
        } catch (Throwable $e) {
            self::$columnCache[$key] = false;
            return false;
        }
    }

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

    /** Resolve fresh function ACL plus station IDs in op_rh_localidades space for biometricos. */
    public static function authorizeBiometricos($conexion, array $user, string $requiredPermission = 'leer') {
        return self::authorizeModuleByKey($conexion, $user, 'biometricos', $requiredPermission);
    }

    /** Resolve fresh function ACL plus station IDs in op_rh_localidades space for checador-v2-incidencias. */
    public static function authorizeIncidences($conexion, array $user, string $requiredPermission = 'leer') {
        return self::authorizeModuleByKey($conexion, $user, 'checador-v2-incidencias', $requiredPermission);
    }

    /** Resolve fresh function ACL plus station IDs in op_rh_localidades space for incidence revisions (Contract 9.5). */
    public static function authorizeIncidenceRevision($conexion, array $user): array {
        // Contract Section 9.5: checador-v2-incidencias-resolucion.editar = 1 OR checador-v2-incidencias.crear = 1
        $res = self::authorizeModuleByKey($conexion, $user, 'checador-v2-incidencias-resolucion', 'editar');
        if ($res['success']) {
            return $res;
        }
        return self::authorizeModuleByKey($conexion, $user, 'checador-v2-incidencias', 'crear');
    }

    /** Resolve fresh function ACL plus station IDs for incidence conflict resolution (Contract 9.6). */
    public static function authorizeIncidenceResolution($conexion, array $user): array {
        return self::authorizeModuleByKey($conexion, $user, 'checador-v2-incidencias-resolucion', 'editar');
    }

    /** Resolve fresh function ACL plus station IDs for incidence authorization (Contract 9.7). */
    public static function authorizeIncidenceAuthorization($conexion, array $user): array {
        return self::authorizeModuleByKey($conexion, $user, 'checador-v2-incidencias-autorizacion', 'editar');
    }

    /** Resolve fresh function ACL plus station IDs for incidence rejection (Contract 9.8). */
    public static function authorizeIncidenceRejection($conexion, array $user): array {
        return self::authorizeModuleByKey($conexion, $user, 'checador-v2-incidencias-autorizacion', 'editar');
    }

    /** Resolve fresh function ACL plus station IDs for incidence revocation (Contract 9.9). */
    public static function authorizeIncidenceRevocation($conexion, array $user): array {
        $res = self::authorizeModuleByKey($conexion, $user, 'checador-v2-incidencias-autorizacion', 'eliminar');
        if ($res['success']) {
            return $res;
        }
        return self::authorizeModuleByKey($conexion, $user, 'checador-v2-incidencias-autorizacion', 'editar');
    }

    /** Resolve fresh function ACL plus station IDs for administrative evaluation execution (Contract 9.11). */
    public static function authorizeAdministrativeEvaluationExecution($conexion, array $user): array {
        $res = self::authorizeModuleByKey($conexion, $user, 'checador-v2-incidencias-resolucion', 'editar');
        if ($res['success']) {
            return $res;
        }
        return self::authorizeModuleByKey($conexion, $user, 'checador-v2-incidencias-autorizacion', 'editar');
    }

    /** Resolve fresh function ACL plus station IDs in op_rh_localidades space for any dedicated module key. */
    public static function authorizeModuleByKey($conexion, array $user, string $moduleKey, string $requiredPermission = 'leer') {
        if (!in_array($requiredPermission, ['leer', 'editar', 'crear', 'eliminar', 'descargar'], true)) {
            return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'Permiso funcional no disponible.');
        }
        try {
            $module = self::selectOne($conexion, 'SELECT id, activo FROM modulos_dpto_operativo WHERE clave = ? LIMIT 1', 's', [$moduleKey]);
            if (!$module || (int) ($module['activo'] ?? 0) !== 1) return self::failure(403, 'FUNCTION_PERMISSION_DENIED', "Módulo funcional {$moduleKey} no activo o no disponible.");

            $userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
            if ($userId <= 0) return self::failure(401, 'UNAUTHORIZED', 'Identidad Portal3 inválida.');
            $hasDirect = self::selectOne($conexion, 'SELECT id FROM modulos_usuarios_do WHERE id_usuario = ? LIMIT 1', 'i', [$userId]) !== null;
            if ($hasDirect) {
                $grant = self::selectOne($conexion, 'SELECT leer, crear, editar, eliminar, descargar FROM modulos_usuarios_do WHERE id_modulo = ? AND id_usuario = ? LIMIT 1', 'ii', [(int) $module['id'], $userId]);
            } else {
                $puestoId = (int) ($user['id_puesto'] ?? 0);
                $grant = $puestoId > 0 ? self::selectOne($conexion, 'SELECT leer, crear, editar, eliminar, descargar FROM modulos_puestos_do WHERE id_modulo = ? AND id_puesto = ? LIMIT 1', 'ii', [(int) $module['id'], $puestoId]) : null;
            }
            if (!$grant || (int) ($grant[$requiredPermission] ?? 0) !== 1) return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'Acceso denegado: permiso funcional insuficiente.');

            $cols = ['activo', 'estaciones_soportadas', 'tipo_departamento'];
            if (self::hasColumn($conexion, 'tb_modulos_config', 'tipo')) {
                $cols[] = 'tipo';
            }
            if (self::hasColumn($conexion, 'tb_modulos_config', 'allow_all')) {
                $cols[] = 'allow_all';
            }
            if (self::hasColumn($conexion, 'tb_modulos_config', 'departamentos_soportados')) {
                $cols[] = 'departamentos_soportados';
            }
            $colList = implode(', ', $cols);
            $moduleConfig = self::selectOne($conexion, "SELECT {$colList} FROM tb_modulos_config WHERE modulo_key = ? LIMIT 1", 's', [$moduleKey]);
            if (!$moduleConfig || (int) ($moduleConfig['activo'] ?? 0) !== 1) {
                return self::failure(403, 'STATION_SCOPE_DENIED', "Configuración de alcance de estación no disponible para el módulo {$moduleKey}.");
            }

            $configType = (string) ($moduleConfig['tipo'] ?? 'stations_only');
            $departmentType = (string) ($moduleConfig['tipo_departamento'] ?? '');
            $supportsLocalityDepartments = ($configType === 'stations_and_departments' && $departmentType === 'localidades');
            $supportsStationsOnly = ($configType === 'stations_only' && in_array($departmentType, ['localidades', 'puestos'], true));

            if ((!$supportsLocalityDepartments && !$supportsStationsOnly) || (bool) ($moduleConfig['allow_all'] ?? false)) {
                return self::failure(403, 'STATION_SCOPE_DENIED', "Configuración de alcance de estación no disponible para el módulo {$moduleKey}.");
            }

            $supportedStations = self::decodeIdList($moduleConfig['estaciones_soportadas'] ?? null);
            if (!$supportedStations && !$supportsLocalityDepartments) {
                return self::failure(403, 'STATION_SCOPE_DENIED', 'Sin estaciones soportadas en la configuración del módulo.');
            }
            $supportedStations = $supportedStations ?? [];

            $authorizedStations = self::resolveStationScope($conexion, $user, $supportedStations, $moduleConfig);
            if (!$authorizedStations) {
                return self::failure(403, 'STATION_SCOPE_DENIED', 'Sin estaciones autorizadas para su perfil en este módulo.');
            }
            return ['success' => true, 'code' => 200, 'status' => 'AUTHORIZED', 'authorized_stations' => $authorizedStations];
        } catch (Portal3JwtDependencyException $exception) {
            return self::failure(503, 'AUTH_DEPENDENCY_UNAVAILABLE', 'No fue posible resolver permisos o alcance de estación.');
        } catch (Throwable $exception) {
            return self::failure(500, 'INTERNAL_ERROR', 'Error interno al resolver autorización.');
        }
    }

    /** Resolve fresh function ACL plus station IDs in op_rh_localidades space. */
    public static function authorizeTemporaryAccess($conexion, array $user, string $requiredPermission) {
        if (!in_array($requiredPermission, ['leer', 'editar'], true)) return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'Permiso funcional no disponible.');
        try {
            $module = self::selectOne($conexion, 'SELECT id, activo FROM modulos_dpto_operativo WHERE clave = ? LIMIT 1', 's', [self::TEMPORARY_ACCESS_MODULE]);
            if (!$module || (int) ($module['activo'] ?? 0) !== 1) return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'Acceso no autorizado.');

            $userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
            if ($userId <= 0) return self::failure(401, 'UNAUTHORIZED', 'Identidad Portal3 inválida.');
            $hasDirect = self::selectOne($conexion, 'SELECT id FROM modulos_usuarios_do WHERE id_usuario = ? LIMIT 1', 'i', [$userId]) !== null;
            if ($hasDirect) {
                $grant = self::selectOne($conexion, 'SELECT leer, crear, editar, eliminar, descargar FROM modulos_usuarios_do WHERE id_modulo = ? AND id_usuario = ? LIMIT 1', 'ii', [(int) $module['id'], $userId]);
            } else {
                $puestoId = (int) ($user['id_puesto'] ?? 0);
                $grant = $puestoId > 0 ? self::selectOne($conexion, 'SELECT leer, crear, editar, eliminar, descargar FROM modulos_puestos_do WHERE id_modulo = ? AND id_puesto = ? LIMIT 1', 'ii', [(int) $module['id'], $puestoId]) : null;
            }
            if (!$grant || (int) ($grant[$requiredPermission] ?? 0) !== 1) return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'Acceso no autorizado.');

            $cols = ['activo', 'estaciones_soportadas', 'tipo_departamento'];
            if (self::hasColumn($conexion, 'tb_modulos_config', 'tipo')) {
                $cols[] = 'tipo';
            }
            if (self::hasColumn($conexion, 'tb_modulos_config', 'allow_all')) {
                $cols[] = 'allow_all';
            }
            if (self::hasColumn($conexion, 'tb_modulos_config', 'departamentos_soportados')) {
                $cols[] = 'departamentos_soportados';
            }
            $colList = implode(', ', $cols);
            $moduleConfig = self::selectOne($conexion, "SELECT {$colList} FROM tb_modulos_config WHERE modulo_key = ? LIMIT 1", 's', [self::TEMPORARY_ACCESS_MODULE]);
            if (!$moduleConfig || (int) ($moduleConfig['activo'] ?? 0) !== 1 || ($moduleConfig['tipo_departamento'] ?? null) !== 'localidades') return self::failure(403, 'STATION_SCOPE_DENIED', 'Alcance de estación no disponible.');
            $supportedStations = self::decodeIdList($moduleConfig['estaciones_soportadas'] ?? null);
            if (!$supportedStations) return self::failure(403, 'STATION_SCOPE_DENIED', 'Alcance de estación no disponible.');
            $authorizedStations = self::resolveStationScope($conexion, $user, $supportedStations, $moduleConfig);
            if (!$authorizedStations) return self::failure(403, 'STATION_SCOPE_DENIED', 'Alcance de estación no disponible.');
            return ['success' => true, 'code' => 200, 'status' => 'AUTHORIZED', 'authorized_stations' => $authorizedStations];
        } catch (Portal3JwtDependencyException $exception) {
            return self::failure(503, 'AUTH_DEPENDENCY_UNAVAILABLE', 'No fue posible resolver permisos o alcance de estación.');
        } catch (Throwable $exception) {
            return self::failure(500, 'INTERNAL_ERROR', 'Error interno al resolver autorización.');
        }
    }

    public static function resolveStationScope($conexion, array $user, array $supportedStations, ?array $moduleConfig = null): array {
        $userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
        $puestoId = (int) ($user['id_puesto'] ?? 0);
        $homeStationId = (int) ($user['id_gas'] ?? 0);

        // 1. Resolve source station IDs in tb_estaciones space
        if ($puestoId === self::DIRECCION_OPERACIONES_PUESTO_ID) {
            // Puesto 13: Dirección receives all validated supported stations
            $sourceStationIds = $supportedStations;
        } elseif ($puestoId === self::ENCARGADO_PUESTO_ID) {
            // Puesto 6: Encargado is bound exclusively to own home station (id_gas).
            // Any general multi-station configuration is strictly ignored.
            $sourceStationIds = ($homeStationId > 0 && in_array($homeStationId, $supportedStations, true))
                ? [$homeStationId]
                : [];
        } elseif ($puestoId === self::ENCARGADO_TURNO_PUESTO_ID || $puestoId === self::PUESTO_14_ID) {
            // Puestos 3 and 14: No automatic global access. Bound strictly to home station.
            $sourceStationIds = ($homeStationId > 0 && in_array($homeStationId, $supportedStations, true))
                ? [$homeStationId]
                : [];
        } else {
            // Other roles: Check user multi-station, then puesto multi-station, then home station
            $userConfig = self::selectOne($conexion, 'SELECT estaciones FROM tb_multiestacion_usuario WHERE id_usuario = ? AND activo = 1 LIMIT 1', 'i', [$userId]);
            $puestoConfig = $puestoId > 0 ? self::selectOne($conexion, 'SELECT estaciones FROM tb_multiestacion_puesto WHERE id_puesto = ? AND activo = 1 LIMIT 1', 'i', [$puestoId]) : null;
            if ($userConfig && $userConfig['estaciones'] !== null && strtolower(trim((string)$userConfig['estaciones'])) !== 'null') {
                $stationIds = self::decodeIdList($userConfig['estaciones']);
            } elseif ($puestoConfig && $puestoConfig['estaciones'] !== null && strtolower(trim((string)$puestoConfig['estaciones'])) !== 'null') {
                $stationIds = self::decodeIdList($puestoConfig['estaciones']);
            } else {
                $stationIds = ($homeStationId > 0) ? [$homeStationId] : [];
            }
            $sourceStationIds = array_values(array_intersect($stationIds, $supportedStations));
        }

        // 2. Map station IDs to canonical op_rh_localidades with strict 1:1 bijection (FASE 3)
        $stationLocalityIds = [];
        foreach ($sourceStationIds as $sourceId) {
            $sourceId = (int) $sourceId;
            if ($sourceId < 1) {
                return [];
            }

            $station = self::selectOne($conexion, 'SELECT id, numlista, estatus FROM tb_estaciones WHERE id = ?', 'i', [$sourceId]);
            if (!$station) {
                return [];
            }
            if ((int) ($station['estatus'] ?? -1) !== 0) {
                // Inactive station catalog rows are not part of active scope
                continue;
            }

            $numlista = (int) ($station['numlista'] ?? 0);
            if ($numlista < 1) {
                return [];
            }

            // Uniqueness validation: must exist exactly once in active tb_estaciones and op_rh_localidades
            $stationCount = (int) (self::selectOne($conexion, 'SELECT COUNT(*) as cnt FROM tb_estaciones WHERE numlista = ?', 'i', [$numlista])['cnt'] ?? 0);
            $localityCount = (int) (self::selectOne($conexion, 'SELECT COUNT(*) as cnt FROM op_rh_localidades WHERE numlista = ?', 'i', [$numlista])['cnt'] ?? 0);

            if ($stationCount !== 1 || $localityCount !== 1) {
                return []; // Ambiguous or duplicate mapping -> fail closed
            }

            $locality = self::selectOne($conexion, 'SELECT id FROM op_rh_localidades WHERE numlista = ?', 'i', [$numlista]);
            if (!$locality || (int) ($locality['id'] ?? 0) <= 0) {
                return [];
            }
            $stationLocalityIds[] = (int) $locality['id'];
        }
        $stationLocalityIds = array_values(array_unique($stationLocalityIds));

        // 3. Resolve explicit department localities (FASE 4 - Autolavado / Palo Solo exception)
        $departmentLocalityIds = self::resolveExplicitDepartmentLocalities($conexion, $user, $stationLocalityIds, $moduleConfig);

        return array_values(array_unique(array_merge($stationLocalityIds, $departmentLocalityIds)));
    }

    private static function resolveExplicitDepartmentLocalities(
        $conexion,
        array $user,
        array $stationLocalityIds,
        ?array $moduleConfig
    ): array {
        if ($moduleConfig === null) {
            if (!self::hasColumn($conexion, 'tb_modulos_config', 'departamentos_soportados')) {
                return [];
            }
            $cols = ['activo', 'estaciones_soportadas', 'tipo_departamento', 'departamentos_soportados'];
            if (self::hasColumn($conexion, 'tb_modulos_config', 'tipo')) {
                $cols[] = 'tipo';
            }
            if (self::hasColumn($conexion, 'tb_modulos_config', 'allow_all')) {
                $cols[] = 'allow_all';
            }
            $colList = implode(', ', $cols);
            $moduleConfig = self::selectOne(
                $conexion,
                "SELECT {$colList} FROM tb_modulos_config WHERE modulo_key IN (?, ?) AND activo = 1 ORDER BY id ASC LIMIT 1",
                'ss',
                ['biometricos', 'checador']
            );
        }
        if (!$moduleConfig) {
            return [];
        }

        $configType = (string) ($moduleConfig['tipo'] ?? '');
        $departmentType = (string) ($moduleConfig['tipo_departamento'] ?? '');
        if ($configType !== 'stations_and_departments' || $departmentType !== 'localidades') {
            return [];
        }

        if (!isset($moduleConfig['departamentos_soportados'])) {
            return [];
        }
        $supportedDeptIds = self::decodeIdList($moduleConfig['departamentos_soportados']);
        if (empty($supportedDeptIds)) {
            return [];
        }

        $explicitIds = self::departmentScopeForApprovedProfile($conexion, $user, $stationLocalityIds);
        if ($explicitIds === null || empty($explicitIds)) {
            return [];
        }

        $requestedIds = array_values(array_intersect($supportedDeptIds, $explicitIds));
        $puestoId = (int) ($user['id_puesto'] ?? 0);

        if ($puestoId === self::ENCARGADO_PUESTO_ID) {
            $requestedIds = array_values(array_intersect($requestedIds, [self::AUTOLAVADO_LOCALITY_ID]));
        } elseif ($puestoId !== self::DIRECCION_OPERACIONES_PUESTO_ID) {
            return [];
        }

        if (empty($requestedIds)) {
            return [];
        }

        // Exclude any department ID already in station workplaces
        $stationLocIds = array_map('intval', $stationLocalityIds);
        $requestedIds = array_values(array_diff($requestedIds, $stationLocIds));
        if (empty($requestedIds)) {
            return [];
        }

        // Validate each department locality in op_rh_localidades
        $validLocalityIds = [];
        foreach ($requestedIds as $deptLocId) {
            $loc = self::selectOne($conexion, 'SELECT id FROM op_rh_localidades WHERE id = ?', 'i', [(int) $deptLocId]);
            if ($loc && (int) ($loc['id'] ?? 0) > 0) {
                $validLocalityIds[] = (int) $loc['id'];
            }
        }
        return $validLocalityIds;
    }

    private static function departmentScopeForApprovedProfile(
        $conexion,
        array $user,
        array $stationLocalityIds
    ): ?array {
        $userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
        $puestoId = (int) ($user['id_puesto'] ?? 0);

        if ($puestoId === self::DIRECCION_OPERACIONES_PUESTO_ID) {
            $hasUserCol = self::hasColumn($conexion, 'tb_multiestacion_usuario', 'departamentos_localidades');
            $hasPuestoCol = self::hasColumn($conexion, 'tb_multiestacion_puesto', 'departamentos_localidades');

            if (!$hasUserCol && !$hasPuestoCol) {
                return null;
            }

            if ($hasUserCol) {
                $userConfig = self::selectOne($conexion, 'SELECT departamentos_localidades FROM tb_multiestacion_usuario WHERE id_usuario = ? AND activo = 1 LIMIT 1', 'i', [$userId]);
                if ($userConfig && $userConfig['departamentos_localidades'] !== null) {
                    $list = self::decodeIdList($userConfig['departamentos_localidades']);
                    if (!empty($list)) {
                        return $list;
                    }
                }
            }

            if ($hasPuestoCol) {
                $puestoConfig = self::selectOne($conexion, 'SELECT departamentos_localidades FROM tb_multiestacion_puesto WHERE id_puesto = ? AND activo = 1 LIMIT 1', 'i', [$puestoId]);
                if ($puestoConfig && $puestoConfig['departamentos_localidades'] !== null) {
                    return self::decodeIdList($puestoConfig['departamentos_localidades']);
                }
            }

            return null;
        }

        // Encargado: must be Puesto 6, exactly 1 station workplace, and that station workplace MUST BE PALO SOLO (locality 2)
        if ($puestoId !== self::ENCARGADO_PUESTO_ID
            || count($stationLocalityIds) !== 1
            || (int) $stationLocalityIds[0] !== self::PALO_SOLO_LOCALITY_ID) {
            return null;
        }

        // Palo Solo exception: must be individual ONLY!
        // Do NOT inherit from position (tb_multiestacion_puesto).
        if (!self::hasColumn($conexion, 'tb_multiestacion_usuario', 'departamentos_localidades')) {
            return null;
        }

        $userScopes = self::selectRows($conexion, 'SELECT id, departamentos_localidades FROM tb_multiestacion_usuario WHERE id_usuario = ? AND activo = 1', 'i', [$userId]);
        if (count($userScopes) !== 1) {
            return null;
        }

        return self::decodeIdList($userScopes[0]['departamentos_localidades'] ?? null);
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
