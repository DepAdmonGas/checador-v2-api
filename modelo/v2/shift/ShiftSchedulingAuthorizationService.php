<?php

declare(strict_types=1);

require_once __DIR__ . '/../Portal3JwtAuthHelper.php';

/** Narrow M03 capabilities, resolved independently from biometricos.* ACL. */
final class ShiftSchedulingAuthorizationService
{
    public const READ = 'checador.programacion.leer';
    public const EDIT = 'checador.programacion.editar';
    public const TEMPLATE_ADMIN = 'checador.plantillas.administrar';
    public const STATION_TEMPLATE_ADMIN = 'checador.turnos.estacion.administrar';
    private const PALO_SOLO_LOCALITY_ID = 2;
    private const AUTOLAVADO_LOCALITY_ID = 9;

    private const PERMISSIONS = [self::READ, self::EDIT, self::TEMPLATE_ADMIN, self::STATION_TEMPLATE_ADMIN];
    private const SCOPE_MODULE = 'biometricos';

    /**
     * @return array{success:bool,code:int,status:string,error:string,authorized_stations?:list<int>}
     */
    public static function authorize($connection, array $user, string $permission, bool $requireStationScope = true): array
    {
        if (!in_array($permission, self::PERMISSIONS, true)) {
            return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'Permiso de programación no disponible.');
        }
        $userId = (int)($user['user_id'] ?? 0);
        $positionId = (int)($user['id_puesto'] ?? 0);
        if ($userId < 1 || $positionId < 1) {
            return self::failure(401, 'UNAUTHORIZED', 'Identidad Portal3 inválida.');
        }
        if (!self::positionCanUseCapability($positionId, $permission)) {
            return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'El puesto no puede ejecutar esta operación de programación.');
        }

        $failedStage = 'shift_scheduling.user_permission_lookup';
        try {
            $userGrant = self::readGrant($connection, $permission, 'USER', $userId);
            $positionGrant = $userGrant === null
                ? self::readGrant($connection, $permission, 'POSITION', $positionId)
                : null;
            if (!self::effectiveGrantDecision($userGrant, $positionGrant)) {
                return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'Acceso denegado: permiso específico de programación insuficiente.');
            }

            if (!$requireStationScope) {
                if ($permission !== self::TEMPLATE_ADMIN) {
                    return self::failure(403, 'STATION_SCOPE_DENIED', 'Esta capacidad requiere alcance explícito de estación.');
                }
                return [
                    'success' => true,
                    'code' => 200,
                    'status' => 'AUTHORIZED',
                    'error' => '',
                    'authorized_stations' => [],
                ];
            }

            $failedStage = 'shift_scheduling.station_scope_resolution';
            $scope = self::resolveStationScope($connection, $user);
            if ($scope === []) {
                return self::failure(403, 'STATION_SCOPE_DENIED', 'No fue posible resolver estaciones canónicas autorizadas para programación.');
            }
            $authorizedWorkplaces = $scope;
            if (in_array($permission, [self::READ, self::EDIT], true)) {
                // M03 assignment scope may include only the individually authorized
                // Autolavado department. Other department IDs remain excluded.
                $failedStage = 'shift_scheduling.department_scope_resolution';
                $explicitDepartments = self::resolveAutolavadoDepartmentScope($connection, $user, $scope);
                $authorizedWorkplaces = array_values(array_unique(array_merge(
                    $scope,
                    self::extendScheduleScopeWithAutolavado((int)($user['id_puesto'] ?? 0), $scope, $explicitDepartments)
                )));
                sort($authorizedWorkplaces, SORT_NUMERIC);
            }
            return [
                'success' => true,
                'code' => 200,
                'status' => 'AUTHORIZED',
                'error' => '',
                'authorized_stations' => $authorizedWorkplaces,
            ];
        } catch (Throwable $exception) {
            Portal3JwtAuthHelper::logAuthDependencyFailure($failedStage, $exception);
            return self::failure(503, 'AUTH_DEPENDENCY_UNAVAILABLE', 'No fue posible resolver permisos o alcance de programación.');
        }
    }

    /** Catalog reads are allowed for a scoped scheduler or a global catalog administrator. */
    public static function authorizeTemplateCatalogRead($connection, array $user): array
    {
        $scheduledRead = self::authorize($connection, $user, self::READ, true);
        if (!$scheduledRead['success']) {
            $adminRead = self::authorize($connection, $user, self::TEMPLATE_ADMIN, false);
            if (!$adminRead['success']) return $scheduledRead;
            $scheduledRead = $adminRead;
        }
        $physical = array_values(array_map('intval', $scheduledRead['authorized_stations'] ?? []));
        $departments = self::resolveAutolavadoDepartmentScope($connection, $user, $physical);
        $scheduledRead['authorized_template_localities'] = array_values(array_unique(array_merge($physical, $departments)));
        $scheduledRead['authorized_template_departments'] = $departments;
        return $scheduledRead;
    }

    /** Specific M04 mutation scope. It expands only the template catalog, never assignment authorization. */
    public static function authorizeStationTemplateMutation($connection, array $user): array
    {
        $authz = self::authorize($connection, $user, self::STATION_TEMPLATE_ADMIN, true);
        if (!$authz['success']) return $authz;
        $physical = array_values(array_map('intval', $authz['authorized_stations'] ?? []));
        $departments = self::resolveAutolavadoDepartmentScope($connection, $user, $physical);
        $authz['authorized_template_localities'] = array_values(array_unique(array_merge($physical, $departments)));
        $authz['authorized_template_departments'] = $departments;
        return $authz;
    }

    /** Autolavado is the only supported department, and only through explicit Portal3 scope. */
    public static function resolveAutolavadoDepartmentScope($connection, array $user, array $physicalLocalityIds): array
    {
        if (!in_array((int)($user['id_puesto'] ?? 0), [Portal3JwtAuthHelper::ENCARGADO_PUESTO_ID, Portal3JwtAuthHelper::DIRECCION_OPERACIONES_PUESTO_ID], true)) return [];
        if ((int)($user['id_puesto'] ?? 0) === Portal3JwtAuthHelper::ENCARGADO_PUESTO_ID
            && !in_array(self::PALO_SOLO_LOCALITY_ID, $physicalLocalityIds, true)) return [];
        $stmt = $connection->prepare(
            'SELECT activo, estaciones_soportadas, tipo, tipo_departamento, departamentos_soportados, allow_all '
            . 'FROM tb_modulos_config WHERE modulo_key = ? LIMIT 1'
        );
        if (!$stmt) throw new RuntimeException('M04 department scope configuration unavailable.');
        $moduleKey = 'biometricos';
        $stmt->bind_param('s', $moduleKey);
        $stmt->execute();
        $config = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$config || (int)($config['activo'] ?? 0) !== 1 || (int)($config['allow_all'] ?? 1) !== 0
            || (string)($config['tipo'] ?? '') !== 'stations_and_departments'
            || (string)($config['tipo_departamento'] ?? '') !== 'localidades') return [];
        $supportedDepartments = self::decodeIdList($config['departamentos_soportados'] ?? null);
        if (!in_array(self::AUTOLAVADO_LOCALITY_ID, $supportedDepartments, true)) return [];
        $supportedStations = self::decodeIdList($config['estaciones_soportadas'] ?? null);
        if ($supportedStations === []) return [];
        $resolved = Portal3JwtAuthHelper::resolveStationScope($connection, $user, $supportedStations, $config);
        if (!in_array(self::AUTOLAVADO_LOCALITY_ID, $resolved, true)) return [];
        $stmt = $connection->prepare('SELECT numlista FROM op_rh_localidades WHERE id = ? LIMIT 1');
        if (!$stmt) throw new RuntimeException('Canonical Autolavado locality lookup unavailable.');
        $localityId = self::AUTOLAVADO_LOCALITY_ID;
        $stmt->bind_param('i', $localityId);
        $stmt->execute();
        $locality = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)($locality['numlista'] ?? 0) !== 10) return [];
        return [self::AUTOLAVADO_LOCALITY_ID];
    }

    /** Position 6 is bound to the home station; position 13 receives only the configured station allowlist. */
    public static function resolveStationScope($connection, array $user): array
    {
        $positionId = (int)($user['id_puesto'] ?? 0);
        if (!in_array($positionId, [Portal3JwtAuthHelper::ENCARGADO_PUESTO_ID, Portal3JwtAuthHelper::DIRECCION_OPERACIONES_PUESTO_ID], true)) {
            return [];
        }

        $stmtConfig = $connection->prepare(
            'SELECT activo, estaciones_soportadas, tipo, tipo_departamento, departamentos_soportados, allow_all '
            . 'FROM tb_modulos_config WHERE modulo_key = ? LIMIT 1'
        );
        if (!$stmtConfig) {
            throw new RuntimeException('Scheduling station configuration unavailable.');
        }
        $moduleKey = self::SCOPE_MODULE;
        $stmtConfig->bind_param('s', $moduleKey);
        $stmtConfig->execute();
        $config = $stmtConfig->get_result()->fetch_assoc();
        $stmtConfig->close();
        if (!$config || (int)($config['activo'] ?? 0) !== 1 || (int)($config['allow_all'] ?? 1) !== 0) {
            return [];
        }
        if (!in_array((string)($config['tipo'] ?? ''), ['stations_only', 'stations_and_departments'], true)
            || !in_array((string)($config['tipo_departamento'] ?? ''), ['puestos', 'localidades'], true)) {
            return [];
        }

        $supported = self::decodeIdList($config['estaciones_soportadas'] ?? null);
        if ($supported === []) {
            return [];
        }
        $candidateScope = Portal3JwtAuthHelper::resolveStationScope($connection, $user, $supported, $config);
        if ($candidateScope === []) {
            return [];
        }

        $activePhysicalIds = [];
        $physicalToCanonical = [];
        foreach ($supported as $physicalId) {
            $active = self::physicalStationIsActive($connection, $physicalId);
            if ($active === null) return [];
            if (!$active) continue;
            $localityId = self::mapActivePhysicalStation($connection, $physicalId);
            if ($localityId === null) return [];
            $activePhysicalIds[] = $physicalId;
            $physicalToCanonical[$physicalId] = $localityId;
        }

        return self::resolveCanonicalStationScope(
            $positionId,
            (int)($user['id_gas'] ?? 0),
            $supported,
            $activePhysicalIds,
            $physicalToCanonical,
            $candidateScope
        );
    }

    /** Pure least-privilege assignment-scope extension used only for the explicit Autolavado locality. */
    public static function extendScheduleScopeWithAutolavado(int $positionId, array $physicalLocalities, array $explicitDepartments): array
    {
        $physical = array_values(array_unique(array_map('intval', $physicalLocalities)));
        $explicit = array_values(array_unique(array_map('intval', $explicitDepartments)));
        if (!in_array($positionId, [Portal3JwtAuthHelper::ENCARGADO_PUESTO_ID, Portal3JwtAuthHelper::DIRECCION_OPERACIONES_PUESTO_ID], true)
            || !in_array(self::AUTOLAVADO_LOCALITY_ID, $explicit, true)) {
            return [];
        }
        if ($positionId === Portal3JwtAuthHelper::ENCARGADO_PUESTO_ID
            && !in_array(self::PALO_SOLO_LOCALITY_ID, $physical, true)) {
            return [];
        }
        return [self::AUTOLAVADO_LOCALITY_ID];
    }

    public static function positionCanUseCapability(int $positionId, string $permission): bool
    {
        return match ($permission) {
            self::READ => in_array($positionId, [6, 13], true),
            self::EDIT => $positionId === 6,
            self::TEMPLATE_ADMIN => $positionId > 0 && $positionId !== 6,
            self::STATION_TEMPLATE_ADMIN => $positionId === 6,
            default => false,
        };
    }

    /** Pure physical-station to canonical-locality scope restriction. */
    public static function resolveCanonicalStationScope(
        int $positionId,
        int $homeStationId,
        array $supportedPhysicalIds,
        array $activePhysicalIds,
        array $physicalToCanonical,
        array $candidateCanonicalScope
    ): array {
        if (!in_array($positionId, [6, 13], true)) return [];
        $physicalIds = $positionId === 6 ? [$homeStationId] : $supportedPhysicalIds;
        if ($positionId === 6 && ($homeStationId < 1 || !in_array($homeStationId, $supportedPhysicalIds, true))) return [];
        $resolved = [];
        foreach ($physicalIds as $physicalValue) {
            $physicalId = (int) $physicalValue;
            if ($physicalId < 1 || !in_array($physicalId, $supportedPhysicalIds, true)) return [];
            if (!in_array($physicalId, $activePhysicalIds, true)) {
                if ($positionId === 6) return [];
                continue;
            }
            $canonicalId = (int) ($physicalToCanonical[$physicalId] ?? 0);
            if ($canonicalId < 1 || !in_array($canonicalId, $candidateCanonicalScope, true)) return [];
            $resolved[] = $canonicalId;
        }
        $resolved = array_values(array_unique($resolved));
        sort($resolved, SORT_NUMERIC);
        return $resolved;
    }

    /** Pure grant precedence rule, also covered by isolated tests. */
    public static function effectiveGrantDecision(?array $userGrant, ?array $positionGrant): bool
    {
        $grant = $userGrant ?? $positionGrant;
        return $grant !== null
            && (int)($grant['active'] ?? 0) === 1
            && (int)($grant['granted'] ?? 0) === 1;
    }

    /** @return ?array{active:int,granted:int} */
    private static function readGrant($connection, string $permission, string $principalType, int $principalId): ?array
    {
        $stmt = $connection->prepare(
            'SELECT active, granted FROM op_rh_v2_permission_grants '
            . 'WHERE permission_key = ? AND principal_type = ? AND principal_id = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Specific scheduling permission store unavailable.');
        }
        $stmt->bind_param('ssi', $permission, $principalType, $principalId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return ['active' => (int)$row['active'], 'granted' => (int)$row['granted']];
    }

    private static function mapActivePhysicalStation($connection, int $physicalStationId): ?int
    {
        $stmt = $connection->prepare('SELECT numlista, estatus FROM tb_estaciones WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Physical station mapping unavailable.');
        }
        $stmt->bind_param('i', $physicalStationId);
        $stmt->execute();
        $station = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$station) {
            return null;
        }
        if ((int)($station['estatus'] ?? -1) !== 0) {
            return null;
        }
        $numlista = (int)($station['numlista'] ?? 0);
        if ($numlista < 1) {
            return null;
        }

        $stmt = $connection->prepare('SELECT COUNT(*) AS total FROM tb_estaciones WHERE numlista = ?');
        if (!$stmt) {
            throw new RuntimeException('Physical station uniqueness unavailable.');
        }
        $stmt->bind_param('i', $numlista);
        $stmt->execute();
        $stationCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        $stmt = $connection->prepare('SELECT COUNT(*) AS total FROM op_rh_localidades WHERE numlista = ?');
        if (!$stmt) {
            throw new RuntimeException('Canonical locality uniqueness unavailable.');
        }
        $stmt->bind_param('i', $numlista);
        $stmt->execute();
        $localityCount = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        if ($stationCount !== 1 || $localityCount !== 1) {
            throw new RuntimeException('Physical station locality mapping is ambiguous.');
        }

        $stmt = $connection->prepare('SELECT id FROM op_rh_localidades WHERE numlista = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Canonical locality lookup unavailable.');
        }
        $stmt->bind_param('i', $numlista);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $localityId = (int)($row['id'] ?? 0);
        return $localityId > 0 ? $localityId : null;
    }

    private static function physicalStationIsActive($connection, int $physicalStationId): ?bool
    {
        $stmt = $connection->prepare('SELECT estatus FROM tb_estaciones WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Physical station status unavailable.');
        }
        $stmt->bind_param('i', $physicalStationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        return (int)($row['estatus'] ?? -1) === 0;
    }

    /** @return list<int> */
    private static function decodeIdList($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }
        $ids = [];
        foreach ($decoded as $id) {
            if (!is_int($id) || $id < 1 || in_array($id, $ids, true)) {
                return [];
            }
            $ids[] = $id;
        }
        return $ids;
    }

    private static function failure(int $code, string $status, string $message): array
    {
        return ['success' => false, 'code' => $code, 'status' => $status, 'error' => $message];
    }
}
