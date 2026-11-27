<?php

declare(strict_types=1);

require_once __DIR__ . '/../Portal3JwtAuthHelper.php';
require_once __DIR__ . '/ShiftSchedulingAuthorizationService.php';
require_once __DIR__ . '/WorkplaceCoveragePolicy.php';
require_once __DIR__ . '/../WorkplacePhysicalStationResolver.php';

/** Dedicated coverage ACL; never broadens M03/biometricos grants. */
final class WorkplaceCoverageAuthorizationService
{
    public const JOURNEY_LOCAL_SERVICE_ID = 'journey-local';
    /** SERVICE principals have their own namespace; this is not a USER id. */
    public const JOURNEY_LOCAL_PRINCIPAL_ID = 1;
    public const JOURNEY_LOCAL_SCOPE_MODULE_KEY = 'checador_journey_service';
    /** Approved physical tb_estaciones.id allowlist for this development service. */
    public const JOURNEY_LOCAL_PHYSICAL_STATIONS = [1, 2, 3, 4, 5, 6, 7, 8, 14];

    public static function authorize(
        mysqli $db,
        array $authenticatedUser,
        string $capability,
        ?int $homeWorkplaceId = null,
        ?int $coverageWorkplaceId = null
    ): array {
        $actor = self::authorizeActor($db, $authenticatedUser, $capability);
        if (empty($actor['success'])) return $actor;
        $scope = $actor['authorized_workplaces'];
        $positionId = (int)$actor['position_id'];
        if (!WorkplaceCoveragePolicy::scopeAllows($positionId, $capability, $scope, $homeWorkplaceId, $coverageWorkplaceId)) {
            return self::denied(403, 'STATION_SCOPE_DENIED');
        }
        return $actor;
    }

    /** Check actor, role ceiling, and dedicated grant before a resource's scope is known. */
    public static function authorizeActor(mysqli $db, array $authenticatedUser, string $capability): array
    {
        $userId = (int)($authenticatedUser['user_id'] ?? 0);
        $positionId = (int)($authenticatedUser['id_puesto'] ?? 0);
        if ($userId < 1 || $positionId < 1) return self::denied(401, 'UNAUTHORIZED');
        if (!WorkplaceCoveragePolicy::positionMayUse($positionId, $capability)) return self::denied(403, 'FUNCTION_PERMISSION_DENIED');

        try {
            $direct = self::readGrant($db, $capability, 'USER', $userId);
            $position = $direct === null ? self::readGrant($db, $capability, 'POSITION', $positionId) : null;
            $scope = ShiftSchedulingAuthorizationService::resolveStationScope($db, $authenticatedUser);
            if (!WorkplaceCoveragePolicy::grantAllows($direct, $position)) return self::denied(403, 'FUNCTION_PERMISSION_DENIED');
            if ($scope === []) return self::denied(403, 'STATION_SCOPE_DENIED');
            return [
                'success' => true,
                'status' => 'AUTHORIZED',
                'user_id' => $userId,
                'position_id' => $positionId,
                'authorized_workplaces' => $scope,
            ];
        } catch (Throwable $exception) {
            return self::denied(503, 'AUTH_DEPENDENCY_UNAVAILABLE');
        }
    }

    /**
     * Authorize the local journey processor for coverage reads only.
     * The caller must pass the identity built after the CLI config owner/mode/UID
     * checks. Its canonical workplace list is a ceiling, never a permission grant.
     */
    public static function authorizeService(
        mysqli $db,
        array $serviceIdentity,
        string $capability,
        ?int $homeWorkplaceId,
        ?int $coverageWorkplaceId
    ): array {
        $requestedService = $serviceIdentity['service_id'] ?? null;
        $serviceId = in_array($requestedService, [self::JOURNEY_LOCAL_SERVICE_ID, 'journey-preprod'], true)
            ? $requestedService : 'UNKNOWN_SERVICE';
        $home = $homeWorkplaceId ?? 0;
        $coverage = $coverageWorkplaceId ?? 0;
        $decision = 'DENIED';
        try {
            if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid')
                || ($serviceIdentity['principal_type'] ?? null) !== 'SERVICE'
                || $serviceId === 'UNKNOWN_SERVICE'
                || ($serviceIdentity['system_uid'] ?? null) !== posix_geteuid()
                || $capability !== WorkplaceCoveragePolicy::READ
                || !self::validCanonicalWorkplaceList($serviceIdentity['stations'] ?? null)) {
                return self::serviceDenied($serviceId, $capability, $home, $coverage, 'SERVICE_IDENTITY_DENIED');
            }

            if ($serviceId === 'journey-preprod') {
                if (($serviceIdentity['preprod_verified'] ?? false) !== true || posix_geteuid() !== 10000) return self::serviceDenied($serviceId, $capability, $home, $coverage, 'SERVICE_IDENTITY_DENIED');
                require_once __DIR__.'/../../../scripts/JourneyEnvironmentProfile.php';
                $result = $db->query('SELECT DATABASE() AS db, CURRENT_USER() AS account, @@hostname AS hostname, @@socket AS socket, @@datadir AS datadir, @@server_id AS server_id, @@port AS port');
                $identity = $result ? $result->fetch_assoc() : null;
                if (!is_array($identity) || !JourneyEnvironmentProfile::preprodIdentityMatches($db->host_info, $identity)) return self::serviceDenied($serviceId, $capability, $home, $coverage, 'SERVICE_IDENTITY_DENIED');
            } else {
                $result = $db->query('SELECT DATABASE() AS db');
                $identity = $result ? $result->fetch_assoc() : null;
                if (($identity['db'] ?? null) !== 'bd_admongas') return self::serviceDenied($serviceId, $capability, $home, $coverage, 'SERVICE_IDENTITY_DENIED');
            }

            $servicePrincipalId = self::servicePrincipalId($serviceId);
            $stmt = $db->prepare(
                "SELECT id FROM op_rh_v2_permission_grants
                 WHERE permission_key = ? AND principal_type = 'SERVICE' AND principal_id = ?
                   AND active = 1 AND granted = 1
                   AND valid_from_utc <= UTC_TIMESTAMP(6) AND valid_until_utc > UTC_TIMESTAMP(6)
                 LIMIT 2"
            );
            if (!$stmt) throw new RuntimeException('SERVICE_GRANT_SCHEMA_UNAVAILABLE');
            $permission = WorkplaceCoveragePolicy::READ;
            $stmt->bind_param('si', $permission, $servicePrincipalId);
            if (!$stmt->execute() || !($result = $stmt->get_result())) throw new RuntimeException('SERVICE_GRANT_READ_FAILED');
            $grants = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            if (count($grants) !== 1) {
                return self::serviceDenied($serviceId, $capability, $home, $coverage, 'SERVICE_GRANT_DENIED');
            }

            $institutional = self::resolveInstitutionalWorkplaceScope($db);
            $configured = array_fill_keys(array_map('intval', $serviceIdentity['stations']), true);
            if (!self::serviceScopeAllows(array_keys($institutional), array_keys($configured), $home, $coverage)) {
                return self::serviceDenied($serviceId, $capability, $home, $coverage, 'STATION_SCOPE_DENIED');
            }

            // Recheck both canonical->physical mappings. The physical station
            // list in CLI config cannot expand this institutional scope.
            foreach ([$home, $coverage] as $workplaceId) {
                $physical = WorkplacePhysicalStationResolver::resolve($db, $workplaceId);
                if ($physical === null || $physical !== $institutional[$workplaceId]) {
                    return self::serviceDenied($serviceId, $capability, $home, $coverage, 'STATION_MAPPING_UNAVAILABLE');
                }
            }
            $decision = 'AUTHORIZED';
            self::auditServiceDecision($serviceId, $capability, $home, $coverage, $decision);
            return [
                'success' => true,
                'status' => 'AUTHORIZED',
                'principal_type' => 'SERVICE',
                'service_id' => $serviceId,
                'authorized_workplaces' => array_values(array_intersect(array_keys($institutional), array_keys($configured))),
            ];
        } catch (Throwable $exception) {
            return self::serviceDenied($serviceId, $capability, $home, $coverage, 'AUTH_DEPENDENCY_UNAVAILABLE');
        }
    }

    /** Fixed development identity mapping; never interpreted in the USER namespace. */
    public static function servicePrincipalId(string $serviceId): int
    {
        if (!in_array($serviceId, [self::JOURNEY_LOCAL_SERVICE_ID, 'journey-preprod'], true)) throw new InvalidArgumentException('UNKNOWN_SERVICE_PRINCIPAL');
        return self::JOURNEY_LOCAL_PRINCIPAL_ID;
    }

    /** Pure predicate used by the DB-backed authorizer and controlled tests. */
    public static function serviceScopeAllows(array $institutionalWorkplaces, array $configuredWorkplaces, int $home, int $coverage): bool
    {
        $institutional = array_fill_keys(array_map('intval', $institutionalWorkplaces), true);
        $configured = array_fill_keys(array_map('intval', $configuredWorkplaces), true);
        return $home > 0 && $coverage > 0
            && isset($institutional[$home], $institutional[$coverage])
            && isset($configured[$home], $configured[$coverage]);
    }

    /** Permission validity is half-open in UTC; an absent end date is not a valid service grant. */
    public static function serviceGrantIsCurrent(array $grant, string $nowUtc): bool
    {
        $from = $grant['valid_from_utc'] ?? null;
        $until = $grant['valid_until_utc'] ?? null;
        return (int)($grant['active'] ?? 0) === 1 && (int)($grant['granted'] ?? 0) === 1
            && is_string($from) && is_string($until) && $from !== '' && $until !== ''
            && $from <= $nowUtc && $nowUtc < $until;
    }

    private static function validCanonicalWorkplaceList(mixed $stations): bool
    {
        if (!is_array($stations) || $stations === [] || !array_is_list($stations)) return false;
        foreach ($stations as $station) if (!is_int($station) || $station < 1) return false;
        return count(array_unique($stations)) === count($stations);
    }

    /** Active dedicated processor scope, mapped physical->canonical. */
    private static function resolveInstitutionalWorkplaceScope(mysqli $db): array
    {
        $stmt = $db->prepare(
            'SELECT activo, allow_all, tipo, tipo_departamento, estaciones_soportadas '
            . 'FROM tb_modulos_config WHERE modulo_key = ? LIMIT 2'
        );
        if (!$stmt) throw new RuntimeException('SERVICE_SCOPE_SCHEMA_UNAVAILABLE');
        $module = self::JOURNEY_LOCAL_SCOPE_MODULE_KEY;
        $stmt->bind_param('s', $module);
        if (!$stmt->execute() || !($result = $stmt->get_result())) throw new RuntimeException('SERVICE_SCOPE_READ_FAILED');
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($rows) !== 1) throw new RuntimeException('SERVICE_SCOPE_AMBIGUOUS');
        $config = $rows[0];
        if ((int)($config['activo'] ?? 0) !== 1 || (int)($config['allow_all'] ?? 1) !== 0
            || (string)($config['tipo'] ?? '') !== 'stations_only'
            || (string)($config['tipo_departamento'] ?? '') !== 'localidades') {
            throw new RuntimeException('SERVICE_SCOPE_DENIED');
        }
        $physicalIds = self::decodeStationIds($config['estaciones_soportadas'] ?? null);
        if (!self::servicePhysicalStationIdsApproved($physicalIds)) throw new RuntimeException('SERVICE_SCOPE_DENIED');
        $scope = [];
        foreach ($physicalIds as $physicalId) {
            $canonical = WorkplacePhysicalStationResolver::resolveCanonicalLocality($db, $physicalId);
            if ($canonical === null || isset($scope[$canonical])) throw new RuntimeException('SERVICE_SCOPE_MAPPING_AMBIGUOUS');
            $scope[$canonical] = $physicalId;
        }
        return $scope;
    }

    /** The service config must match the owner-approved physical station set exactly. */
    public static function servicePhysicalStationIdsApproved(array $physicalIds): bool
    {
        foreach ($physicalIds as $stationId) {
            if (!is_int($stationId) || $stationId < 1) return false;
        }
        $actual = array_values(array_unique($physicalIds));
        if (count($actual) !== count($physicalIds)) return false;
        $expected = self::JOURNEY_LOCAL_PHYSICAL_STATIONS;
        sort($actual, SORT_NUMERIC);
        sort($expected, SORT_NUMERIC);
        return $actual === $expected;
    }

    private static function decodeStationIds(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded) || !array_is_list($decoded) || $decoded === []) return [];
        $ids = [];
        foreach ($decoded as $id) {
            $parsed = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($parsed === false) return [];
            $ids[] = (int)$parsed;
        }
        return count(array_unique($ids)) === count($ids) ? $ids : [];
    }

    private static function serviceDenied(string $serviceId, string $capability, int $home, int $coverage, string $reason): array
    {
        self::auditServiceDecision($serviceId, $capability, $home, $coverage, $reason);
        return ['success' => false, 'code' => 403, 'status' => $reason];
    }

    private static function auditServiceDecision(string $serviceId, string $capability, int $home, int $coverage, string $decision): void
    {
        error_log((string)json_encode([
            'event' => 'CHECADOR_V2_SERVICE_COVERAGE_AUTH',
            'service_id' => $serviceId,
            'capability' => $capability,
            'home_workplace_id' => $home > 0 ? $home : null,
            'coverage_workplace_id' => $coverage > 0 ? $coverage : null,
            'decision' => $decision,
            'recorded_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES));
    }

    private static function readGrant(mysqli $db, string $capability, string $principalType, int $principalId): ?array
    {
        $stmt = $db->prepare(
            'SELECT active, granted FROM op_rh_v2_permission_grants '
            . 'WHERE permission_key = ? AND principal_type = ? AND principal_id = ? LIMIT 1'
        );
        if (!$stmt) throw new RuntimeException('COVERAGE_ACL_UNAVAILABLE');
        $stmt->bind_param('ssi', $capability, $principalType, $principalId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row === null ? null : ['active' => (int)$row['active'], 'granted' => (int)$row['granted']];
    }

    private static function denied(int $code, string $status): array
    {
        return ['success' => false, 'code' => $code, 'status' => $status];
    }
}
