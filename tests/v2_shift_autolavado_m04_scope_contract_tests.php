<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/shift/ShiftSchedulingAuthorizationService.php';
$auth = file_get_contents(__DIR__ . '/../modelo/v2/shift/ShiftSchedulingAuthorizationService.php');
$controller = file_get_contents(__DIR__ . '/../modelo/v2/shift/ShiftSchedulingV2Controller.php');
$templates = file_get_contents(__DIR__ . '/../modelo/v2/shift/ShiftTemplateService.php');
$checks = [
    'fixed canonical locality identifiers are explicit' => str_contains($auth, 'PALO_SOLO_LOCALITY_ID = 2') && str_contains($auth, 'AUTOLAVADO_LOCALITY_ID = 9'),
    'department scope is only Autolavado and requires its institutional explicit scope' => str_contains($auth, 'resolveAutolavadoDepartmentScope') && str_contains($auth, "'biometricos'") && str_contains($auth, 'Portal3JwtAuthHelper::resolveStationScope'),
    'manager department scope requires Palo Solo physical scope' => str_contains($auth, 'in_array(self::PALO_SOLO_LOCALITY_ID, $physicalLocalityIds, true)'),
    'canonical locality 9 is confirmed by numlista 10 and not tb_estaciones id' => str_contains($auth, "SELECT numlista FROM op_rh_localidades WHERE id = ? LIMIT 1") && str_contains($auth, "!== 10"),
    'only template catalog read and template mutation receive expanded localities' => str_contains($auth, 'authorizeTemplateCatalogRead') && str_contains($auth, 'authorizeStationTemplateMutation') && str_contains($auth, 'authorized_template_localities'),
    'catalog request rejects a locality outside the effective template scope' => str_contains($controller, 'Template catalog locality is outside authorized scope.'),
    'Autolavado catalog and details are local-only' => str_contains($controller, '$localOnly = $filterStationId !== null && in_array($filterStationId, $templateDepartments, true)') && str_contains($controller, '$localOnly = $filterStationId !== null && in_array($filterStationId, $allowedDepartments, true)'),
    'global customization is denied for Autolavado' => str_contains($controller, "=== Portal3JwtAuthHelper::AUTOLAVADO_LOCALITY_ID") && str_contains($controller, 'Dirección conserva acceso de consulta a Autolavado.'),
    'Direction is denied department template writes' => str_contains($controller, 'DIRECCION_OPERACIONES_PUESTO_ID') && str_contains($controller, "'FUNCTION_PERMISSION_DENIED'"),
    'local-only listing excludes GLOBAL rows in SQL scope' => str_contains($templates, "\$whereScope = \$localOnly") && str_contains($templates, "scope = 'STATION' AND id_estacion = ?"),
    'local-only detail excludes GLOBAL rows in SQL scope' => str_contains($templates, '$scopeClause = $localOnly') && str_contains($templates, "scope = 'STATION' AND id_estacion = ?"),
    'M03 extension admits only the explicit Autolavado scope and preserves station scope' => str_contains($auth, 'extendScheduleScopeWithAutolavado') && str_contains($auth, 'array_merge(') && str_contains($controller, 'public function handleCreateAssignment(): void') && str_contains($controller, '$this->authorizeScheduleWrite()') && str_contains($controller, '$authz[' . "'authorized_stations']"),
    'Autolavado assignment writer requires a station-local template owned by locality 9' => str_contains(file_get_contents(__DIR__ . '/../modelo/v2/shift/ShiftAssignmentService.php'), "scope = 'STATION' AND id_estacion = ?") && str_contains(file_get_contents(__DIR__ . '/../modelo/v2/shift/ShiftAssignmentService.php'), 'Autolavado assignments require an active template belonging to locality 9.'),
];
$scopeTests = [
    'authorized Encargado with Palo Solo may receive locality 9 only when explicitly granted' => ShiftSchedulingAuthorizationService::extendScheduleScopeWithAutolavado(6, [2], [9]) === [9],
    'Encargado without Palo Solo home scope receives no Autolavado scheduling scope' => ShiftSchedulingAuthorizationService::extendScheduleScopeWithAutolavado(6, [3], [9]) === [],
    'Encargado without explicit department grant receives no Autolavado scheduling scope' => ShiftSchedulingAuthorizationService::extendScheduleScopeWithAutolavado(6, [2], []) === [],
    'Dirección receives only explicitly granted Autolavado scope' => ShiftSchedulingAuthorizationService::extendScheduleScopeWithAutolavado(13, [2], [9]) === [9],
    'other positions receive no Autolavado scheduling scope' => ShiftSchedulingAuthorizationService::extendScheduleScopeWithAutolavado(7, [2], [9]) === [],
    'other department IDs are not expanded' => ShiftSchedulingAuthorizationService::extendScheduleScopeWithAutolavado(6, [2], [8]) === [],
];
$checks = array_merge($checks, $scopeTests);
$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}
echo 'SHIFT_AUTOLAVADO_M04_SCOPE_CONTRACT: ' . (count($checks) - $failed) . '/' . count($checks) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
