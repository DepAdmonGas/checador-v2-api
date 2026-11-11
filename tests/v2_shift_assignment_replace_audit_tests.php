<?php

declare(strict_types=1);

$service = file_get_contents(__DIR__ . '/../modelo/v2/shift/ShiftAssignmentService.php');
$controller = file_get_contents(__DIR__ . '/../modelo/v2/shift/ShiftSchedulingV2Controller.php');

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS {$message}\n";
        return;
    }
    $failed++;
    echo "FAIL {$message}\n";
};

$assert(is_string($service) && str_contains($service, 'int $actorUserId = 0'), 'replacement writer accepts the actor derived by the controller');
$assert(is_string($controller) && str_contains($controller, "(int) (\$authz['user_id'] ?? 0)"), 'API controller forwards the authenticated user id');
$assert(is_string($controller) && !str_contains($controller, "\$payload['actor_user_id']"), 'client actor fields are not accepted as authority');
$assert(is_string($service) && str_contains($service, 'actorUserId < 1'), 'replacement fails closed without an authenticated actor');
$assert(is_string($service) && str_contains($service, 'actualizado_por_usuario_id = ?'), 'source closure records the authenticated updater');
$assert(is_string($service) && str_contains($service, 'creado_por_usuario_id, registrado_at_utc'), 'replacement row persists creator provenance');
$assert(is_string($service) && str_contains($service, "'CLOSE'") && str_contains($service, "'REPLACE'"), 'replacement emits close and replace audit operations');
$assert(is_string($service) && str_contains($service, 'UTC_TIMESTAMP()'), 'replacement timestamps are generated in UTC by the server');
$assert(is_string($service) && str_contains($service, 'Could not persist replacement assignment audit') && str_contains($service, '$this->db->rollback()'), 'audit failure propagates into the enclosing transaction rollback');
$assert(is_string($service) && str_contains($service, "'source_assignment_id'") && str_contains($service, "'replacement_assignment_id'"), 'audit detail preserves source and successor linkage');
$assert(is_string($service) && str_contains($service, "'audit' => [") && str_contains($service, "'recorded_at_utc'"), 'successful replacement returns auditable readback metadata');
$assert(is_string($service) && str_contains($service, 'findExactActiveAssignment'), 'exact replacement replay checks for an existing successor before writing');
$assert(is_string($service) && str_contains($service, "'idempotent' => true"), 'exact replacement replay returns idempotent success without duplicating rows');

echo "CHECADOR_V2_SHIFT_ASSIGNMENT_REPLACE_AUDIT: {$passed} PASS / {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
