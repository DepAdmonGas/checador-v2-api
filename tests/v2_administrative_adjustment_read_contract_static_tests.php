<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/modelo/v2/AdministrativeAdjustmentReadV2Controller.php');
$authorization = file_get_contents($root . '/modelo/v2/AdministrativeAdjustmentReadAuthorization.php');
$routes = file_get_contents($root . '/index.php');
$stripComments = static fn(string $source): string => (string) preg_replace('~/\*.*?\*/|//[^\n]*|#[^\n]*~s', '', $source);
$code = $stripComments($controller . $authorization);
$checks = [
    str_contains($routes, "'administrative-adjustments'") && str_contains($routes, 'handleList()') && str_contains($routes, 'handleDetail('),
    str_contains($controller, 'REQUEST_METHOD') && str_contains($controller, "'GET'") && str_contains($routes, "header('Allow: GET')"),
    str_contains($authorization, "'checador-v2-ajustes'") && str_contains($authorization, 'SELECT leer FROM modulos_usuarios_do WHERE id_modulo = ? AND id_usuario = ?'),
    str_contains($authorization, 'decidePermission($direct, $position)') && str_contains($authorization, 'resolveStationScope'),
    str_contains($controller, "ORDER BY a.id ASC LIMIT ? OFFSET ?") && str_contains($controller, 'DATE_RANGE_TOO_LARGE'),
    str_contains($controller, 'linked_evaluation_count') && str_contains($controller, 'op_rh_v2_evaluaciones_ajustes'),
    str_contains($controller, "WHERE a.uuid_ajuste = ? ORDER BY e.fecha_jornada_local") && str_contains($controller, 'revision_history'),
    !preg_match('/(?:INSERT\s+INTO|UPDATE\s+|DELETE\s+FROM|CREATE\s+TABLE|ALTER\s+TABLE)/i', $code),
    !str_contains($controller, 'administrative-evaluations') && !str_contains($authorization, 'authorizeModuleByKey('),
];
$failed = count(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo sprintf("ADMINISTRATIVE_ADJUSTMENT_READ_CONTRACT_STATIC_RESULT: %d/%d PASS\n", count($checks) - $failed, count($checks));
exit($failed === 0 ? 0 : 1);
