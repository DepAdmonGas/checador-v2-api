<?php

declare(strict_types=1);

require_once __DIR__ . '/../Portal3JwtAuthHelper.php';
require_once __DIR__ . '/ShiftSchedulingAuthorizationService.php';
require_once __DIR__ . '/WorkplaceCoveragePolicy.php';

/** Dedicated coverage ACL; never broadens M03/biometricos grants. */
final class WorkplaceCoverageAuthorizationService
{
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
