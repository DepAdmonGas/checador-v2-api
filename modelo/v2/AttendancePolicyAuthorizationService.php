<?php

declare(strict_types=1);

require_once __DIR__ . '/shift/ShiftSchedulingAuthorizationService.php';

/** Dedicated M11 policy ACL plus the existing canonical station resolver. */
final class AttendancePolicyAuthorizationService
{
    public const READ = 'checador.configuracion.leer';
    public const EDIT = 'checador.configuracion.retardo_incidencias.editar';

    /** @return array{success:bool,code:int,status:string,error:string,authorized_workplaces?:list<int>} */
    public static function authorize($connection, array $user, string $permission): array
    {
        if (!in_array($permission, [self::READ, self::EDIT], true)
            || !self::supportsPosition((int) ($user['id_puesto'] ?? 0))
            || (int) ($user['user_id'] ?? 0) < 1) {
            return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'Permiso de configuración no disponible.');
        }

        try {
            $userGrant = self::readGrant($connection, $permission, 'USER', (int) $user['user_id']);
            $positionGrant = $userGrant === null
                ? self::readGrant($connection, $permission, 'POSITION', (int) $user['id_puesto'])
                : null;
            if (!self::effectiveGrantDecision($userGrant, $positionGrant)) {
                return self::failure(403, 'FUNCTION_PERMISSION_DENIED', 'Permiso específico de configuración insuficiente.');
            }

            $workplaces = ShiftSchedulingAuthorizationService::resolveStationScope($connection, $user);
            if ($workplaces === []) {
                return self::failure(403, 'STATION_SCOPE_DENIED', 'No fue posible resolver localidades canónicas autorizadas.');
            }
            $departments = ShiftSchedulingAuthorizationService::resolveAutolavadoDepartmentScope($connection, $user, $workplaces);
            if ($departments !== []) {
                $workplaces = array_values(array_unique(array_merge($workplaces, $departments)));
            }

            return [
                'success' => true,
                'code' => 200,
                'status' => 'AUTHORIZED',
                'error' => '',
                'authorized_workplaces' => $workplaces,
            ];
        } catch (Throwable) {
            return self::failure(503, 'AUTH_DEPENDENCY_UNAVAILABLE', 'No fue posible resolver el permiso o alcance de configuración.');
        }
    }

    public static function effectiveGrantDecision(?array $userGrant, ?array $positionGrant): bool
    {
        $grant = $userGrant ?? $positionGrant;
        return $grant !== null
            && (int) ($grant['active'] ?? 0) === 1
            && (int) ($grant['granted'] ?? 0) === 1;
    }

    public static function supportsPosition(int $positionId): bool
    {
        return in_array($positionId, [
            Portal3JwtAuthHelper::ENCARGADO_PUESTO_ID,
            Portal3JwtAuthHelper::DIRECCION_OPERACIONES_PUESTO_ID,
        ], true);
    }

    private static function readGrant($connection, string $permission, string $principalType, int $principalId): ?array
    {
        $stmt = $connection->prepare(
            'SELECT active, granted FROM op_rh_v2_permission_grants '
            . 'WHERE permission_key = ? AND principal_type = ? AND principal_id = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Attendance policy permission store unavailable.');
        }
        $stmt->bind_param('ssi', $permission, $principalType, $principalId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row === null ? null : [
            'active' => (int) ($row['active'] ?? 0),
            'granted' => (int) ($row['granted'] ?? 0),
        ];
    }

    private static function failure(int $code, string $status, string $message): array
    {
        return ['success' => false, 'code' => $code, 'status' => $status, 'error' => $message];
    }
}
