<?php

declare(strict_types=1);

require_once __DIR__ . '/ShiftSchedulingAuthorizationService.php';

/** Narrow M05 read permission; independent of all biometricos write capabilities. */
final class JourneyReadAuthorizationService
{
    public const READ = 'checador.jornadas.leer';

    /** The caller must pass the fresh active identity returned by validateToken(). */
    public static function authorize($connection, array $user): array
    {
        $userId = (int) ($user['user_id'] ?? 0);
        $positionId = (int) ($user['id_puesto'] ?? 0);
        if ($userId < 1 || $positionId < 1) {
            return self::failure(401, 'UNAUTHORIZED', 'Identidad Portal3 inválida.');
        }
        if (!self::positionCanRead($positionId)) {
            return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'El puesto no puede consultar observaciones de jornada.');
        }

        $failedStage = 'journey_read.user_permission_lookup';
        try {
            $userGrant = self::readGrant($connection, 'USER', $userId);
            $positionGrant = $userGrant === null ? self::readGrant($connection, 'POSITION', $positionId) : null;
            if (!self::effectiveGrantDecision($userGrant, $positionGrant)) {
                return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'Acceso denegado: permiso específico de lectura de jornadas insuficiente.');
            }

            // Reuse the active physical-station → canonical-locality resolver.
            // This returns only stations; department-only exceptions are excluded.
            $failedStage = 'journey_read.station_scope_resolution';
            $stations = ShiftSchedulingAuthorizationService::resolveStationScope($connection, $user);
            if ($stations === []) {
                return self::failure(403, 'STATION_SCOPE_DENIED', 'No fue posible resolver localidades de estación autorizadas para jornadas.');
            }

            return [
                'success' => true,
                'code' => 200,
                'status' => 'AUTHORIZED',
                'error' => '',
                'authorized_stations' => $stations,
            ];
        } catch (Throwable $exception) {
            Portal3JwtAuthHelper::logAuthDependencyFailure($failedStage, $exception);
            return self::failure(503, 'AUTH_DEPENDENCY_UNAVAILABLE', 'No fue posible resolver permiso o alcance de jornadas.');
        }
    }

    public static function positionCanRead(int $positionId): bool
    {
        return in_array($positionId, [6, 13], true);
    }

    /** A direct user rule, including an explicit denial, overrides the position grant. */
    public static function effectiveGrantDecision(?array $userGrant, ?array $positionGrant): bool
    {
        $grant = $userGrant ?? $positionGrant;
        return $grant !== null
            && (int) ($grant['active'] ?? 0) === 1
            && (int) ($grant['granted'] ?? 0) === 1;
    }

    /** Restrict an API station list to one requested locality; null means fail closed. */
    public static function restrictStationScope(array $authorizedStations, ?int $requestedStation): ?array
    {
        $stations = [];
        foreach ($authorizedStations as $station) {
            if (!is_int($station) && !(is_string($station) && ctype_digit($station))) return null;
            $stationId = (int) $station;
            if ($stationId < 1) return null;
            $stations[$stationId] = $stationId;
        }
        if ($stations === []) return null;
        if ($requestedStation === null) return array_values($stations);
        if ($requestedStation < 1 || !isset($stations[$requestedStation])) return null;
        return [$requestedStation];
    }

    private static function readGrant($connection, string $principalType, int $principalId): ?array
    {
        $permission = self::READ;
        $stmt = $connection->prepare(
            'SELECT active, granted FROM op_rh_v2_permission_grants '
            . 'WHERE permission_key = ? AND principal_type = ? AND principal_id = ? LIMIT 1'
        );
        if (!$stmt) throw new RuntimeException('Journey read permission store unavailable.');
        $stmt->bind_param('ssi', $permission, $principalType, $principalId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        return ['active' => (int) $row['active'], 'granted' => (int) $row['granted']];
    }

    private static function failure(int $code, string $status, string $error): array
    {
        return ['success' => false, 'code' => $code, 'status' => $status, 'error' => $error];
    }
}
