<?php

declare(strict_types=1);

require_once __DIR__ . '/shift/ShiftSchedulingAuthorizationService.php';

/** Dedicated M11 read capability, independently scoped from device credentials. */
final class AdministrativeDeviceReadAuthorizationService
{
    public const READ = 'checador-v2-dispositivos.leer';

    /** @return array{success:bool,code:int,status:string,authorized_stations?:list<int>} */
    public static function authorize(mysqli $db, array $user): array
    {
        $userId = (int) ($user['user_id'] ?? 0);
        $positionId = (int) ($user['id_puesto'] ?? 0);
        if ($userId < 1 || $positionId < 1) {
            return self::failure(401, 'UNAUTHORIZED');
        }
        if (!in_array($positionId, [6, 13], true)) {
            return self::failure(403, 'FUNCTION_PERMISSION_DENIED');
        }

        try {
            $userGrant = self::readGrant($db, 'USER', $userId);
            $grant = $userGrant ?? self::readGrant($db, 'POSITION', $positionId);
            if (!$grant || (int) ($grant['active'] ?? 0) !== 1 || (int) ($grant['granted'] ?? 0) !== 1) {
                return self::failure(403, 'FUNCTION_PERMISSION_DENIED');
            }

            $scope = ShiftSchedulingAuthorizationService::resolveStationScope($db, $user);
            if ($scope === [] || ($positionId === 6 && count($scope) !== 1)) {
                return self::failure(403, 'STATION_SCOPE_DENIED');
            }
            $scope = array_values(array_unique(array_filter(array_map('intval', $scope), static fn(int $id): bool => $id > 0)));
            sort($scope, SORT_NUMERIC);
            if ($scope === []) return self::failure(403, 'STATION_SCOPE_DENIED');

            return ['success' => true, 'code' => 200, 'status' => 'AUTHORIZED', 'authorized_stations' => $scope];
        } catch (Throwable) {
            return self::failure(503, 'AUTH_DEPENDENCY_UNAVAILABLE');
        }
    }

    /** A direct USER decision, including an inactive/revoked row, overrides POSITION. */
    private static function readGrant(mysqli $db, string $principalType, int $principalId): ?array
    {
        $stmt = $db->prepare(
            'SELECT active, granted FROM op_rh_v2_permission_grants '
            . 'WHERE permission_key = ? AND principal_type = ? AND principal_id = ? LIMIT 1'
        );
        if (!$stmt) throw new RuntimeException('M11 device permission lookup unavailable.');
        $key = self::READ;
        $stmt->bind_param('ssi', $key, $principalType, $principalId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('M11 device permission lookup unavailable.');
        }
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? ['active' => (int) $row['active'], 'granted' => (int) $row['granted']] : null;
    }

    private static function failure(int $code, string $status): array
    {
        return ['success' => false, 'code' => $code, 'status' => $status];
    }
}
