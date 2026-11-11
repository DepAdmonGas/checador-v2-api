<?php
/**
 * Static contract checks for Shift Scheduling write/read routes.
 * The current addendum separates station scheduling, template administration,
 * and legacy scheduled-shift materialization capabilities.
 * Runtime mutation tests remain isolated in disposable DB suites.
 */

declare(strict_types=1);

$controllerPath = __DIR__ . '/../modelo/v2/shift/ShiftSchedulingV2Controller.php';
$authorizationPath = __DIR__ . '/../modelo/v2/shift/ShiftSchedulingAuthorizationService.php';
$addendumPath = __DIR__ . '/../docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_AUTHORIZATION_ADDENDUM_V1.0.md';
$controller = file_get_contents($controllerPath);
$authorization = file_get_contents($authorizationPath);
$addendum = file_get_contents($addendumPath);
$passed = 0;
$failed = 0;

function write_assert(bool $condition, string $id, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[{$id}] PASS: {$message}\n";
    } else {
        $failed++;
        echo "[{$id}] FAIL: {$message}\n";
    }
}

function write_method(string $source, string $method): string
{
    $pattern = '/(?:public|private|protected) function ' . preg_quote($method, '/') . '\\b.*?(?=\\n    (?:public|private|protected) function |\\n})/s';
    return preg_match($pattern, $source, $matches) === 1 ? $matches[0] : '';
}

function write_auth_before_mutation(string $source, string $method, string $authCall, string $mutationCall): bool
{
    $body = write_method($source, $method);
    $authPosition = strpos($body, $authCall);
    $mutationPosition = strpos($body, $mutationCall);
    return $body !== '' && $authPosition !== false && $mutationPosition !== false && $authPosition < $mutationPosition;
}

write_assert(is_string($controller) && is_string($authorization) && is_string($addendum), 'SWA01', 'controller, capability source and approved addendum are readable');
$obsoleteCall = 'Portal3JwtAuthHelper::authorize' . 'User(';
write_assert(strpos($controller, $obsoleteCall) === false, 'SWA02', 'obsolete authorizeUser dependency is absent');

// Frozen addendum §5: global template admin is separate from station schedule edit.
write_assert((static function () use ($controller): bool {
    $body = write_method($controller, 'handleCreateTemplate');
    $auth = strpos($body, '$this->authorizeTemplateMutation($payload)');
    $writes = array_filter([
        strpos($body, '$this->templateService->createStationCustomization('),
        strpos($body, '$this->templateService->createTemplate('),
    ], static fn($position): bool => $position !== false);
    $gate = write_method($controller, 'authorizeTemplateMutation');
    $adminStart = strpos($gate, '// Global template administrator');
    $adminBranch = $adminStart === false ? '' : substr($gate, $adminStart);
    return $auth !== false && count($writes) === 2 && $auth < min($writes)
        && str_contains($gate, 'Portal3JwtAuthHelper::validateToken')
        && str_contains($gate, 'authorizeStationTemplateMutation($conexion, $auth)')
        && str_contains($gate, "if (\$requestedScope === 'GLOBAL')")
        && str_contains($gate, 'authorizedTemplateLocalities')
        && str_contains($gate, 'STATION_SCOPE_DENIED')
        && str_contains($gate, 'ShiftSchedulingAuthorizationService::TEMPLATE_ADMIN')
        // TEMPLATE_ADMIN is global-only; it must not pass caller-selected station IDs to createTemplate.
        && str_contains($adminBranch, "if (\$requestedScope === 'STATION')")
        && str_contains($adminBranch, "sendJsonError('STATION_SCOPE_DENIED'");
})(), 'SWA03', 'template create authenticates, scopes station writes, and rejects unscoped station creation by global admins');
write_assert((static function () use ($controller): bool {
    $body = write_method($controller, 'handleCreateTemplateVersion');
    $auth = strpos($body, '$this->authorizeTemplateMutation($payload)');
    $mutation = strpos($body, '$this->templateService->createNewVersion(');
    $gate = write_method($controller, 'authorizeTemplateMutation');
    $adminStart = strpos($gate, '// Global template administrator');
    $adminBranch = $adminStart === false ? '' : substr($gate, $adminStart);
    return $auth !== false && $mutation !== false && $auth < $mutation
        && str_contains($gate, 'Portal3JwtAuthHelper::validateToken')
        && str_contains($gate, 'authorizeStationTemplateMutation($conexion, $auth)')
        && str_contains($gate, 'authorizedTemplateLocalities')
        && str_contains($gate, 'STATION_SCOPE_DENIED')
        && str_contains($gate, 'ShiftSchedulingAuthorizationService::TEMPLATE_ADMIN')
        // TEMPLATE_ADMIN cannot authorize a caller-selected local template/version.
        && str_contains($adminBranch, "if (\$requestedScope === 'STATION')")
        && str_contains($adminBranch, "sendJsonError('STATION_SCOPE_DENIED'");
})(), 'SWA04', 'template version authenticates, checks station scope, and keeps global-admin versions global-only');
write_assert(write_auth_before_mutation($controller, 'handleCreateAssignment', '$this->authorizeScheduleWrite()', '$this->assignmentService->createAssignment('), 'SWA05', 'assignment creation checks station-scheduling edit before mutation');
write_assert(write_auth_before_mutation($controller, 'handleCloseAssignment', '$this->authorizeScheduleWrite()', '$this->assignmentService->closeAssignment('), 'SWA06', 'assignment close checks station-scheduling edit before mutation');
write_assert(write_auth_before_mutation($controller, 'handleReplaceAssignment', '$this->authorizeScheduleWrite()', '$this->assignmentService->replaceAssignment('), 'SWA07', 'assignment replacement checks station-scheduling edit before mutation');
write_assert(write_auth_before_mutation($controller, 'handleMaterializeScheduledShifts', '$this->authorizeBiometricosWrite()', '$this->materializationService->materializeShifts('), 'SWA08', 'materialization retains its separately contracted biometricos edit gate');

$scheduleRead = write_method($controller, 'authorizeScheduleRead');
$scheduleWrite = write_method($controller, 'authorizeScheduleWrite');
$templateAdmin = write_method($controller, 'authorizeTemplateAdministration');
$templateCatalogRead = write_method($controller, 'authorizeTemplateCatalogRead');
$legacyMaterializationGate = write_method($controller, 'authorizeBiometricosWrite');
write_assert(str_contains($scheduleRead, 'ShiftSchedulingAuthorizationService::READ') && str_contains($scheduleRead, 'true'), 'SWA09', 'schedule read helper requires the narrow read capability and station scope');
write_assert(str_contains($scheduleWrite, 'ShiftSchedulingAuthorizationService::EDIT') && str_contains($scheduleWrite, 'true'), 'SWA10', 'schedule write helper requires the narrow edit capability and station scope');
write_assert(str_contains($templateAdmin, 'ShiftSchedulingAuthorizationService::TEMPLATE_ADMIN') && str_contains($templateAdmin, 'false'), 'SWA11', 'template administration is a separate global capability');
write_assert(str_contains($legacyMaterializationGate, "authorizeBiometricos(\$conexion, \$auth, 'editar')"), 'SWA12', 'only legacy materialization keeps biometricos.editar');
write_assert(str_contains($controller, 'Portal3JwtAuthHelper::validateToken($conexion, $authHeader)'), 'SWA13', 'narrow capability helper revalidates the canonical Portal3 JWT');
write_assert(str_contains($authorization, "public const READ = 'checador.programacion.leer'") && str_contains($authorization, "public const EDIT = 'checador.programacion.editar'"), 'SWA14', 'dedicated read/edit capability keys match the approved addendum');
write_assert(str_contains($authorization, "public const TEMPLATE_ADMIN = 'checador.plantillas.administrar'"), 'SWA15', 'template-admin capability remains distinct');
write_assert(str_contains($addendum, '- Assignment create, close, and replace POST: `programacion.editar` plus station and worker-membership validation.'), 'SWA29', 'approved addendum assigns station and worker validation to assignment writes');
write_assert(str_contains($addendum, '- Template create/version POST: `plantillas.administrar` only.'), 'SWA30', 'approved addendum requires distinct global template administration');
write_assert(str_contains($addendum, '- Scheduled-shift materialization remains under the existing `biometricos.editar` authorization'), 'SWA31', 'approved addendum preserves the legacy materialization gate');

$reads = [
    'handleListTemplates' => 'authorizeTemplateCatalogRead',
    'handleGetTemplateVersion' => 'authorizeTemplateCatalogRead',
    'handleListAssignments' => 'authorizeScheduleRead',
    'handleGetEffectiveSchedule' => 'authorizeScheduleRead',
];
$readAssertionId = 16;
foreach ($reads as $method => $authCall) {
    $body = write_method($controller, $method);
    write_assert($body !== '' && str_contains($body, '$this->' . $authCall . '()'), 'SWA' . $readAssertionId++, $method . ' uses its contracted catalog/schedule read gate');
}

$assignmentCreate = write_method($controller, 'handleCreateAssignment');
$assignmentClose = write_method($controller, 'handleCloseAssignment');
$assignmentReplace = write_method($controller, 'handleReplaceAssignment');
$materialize = write_method($controller, 'handleMaterializeScheduledShifts');
write_assert(str_contains($assignmentCreate, "'authorized_stations'") && str_contains($assignmentCreate, 'UNAUTHORIZED_STATION_ACCESS'), 'SWA20', 'assignment create enforces authorized station scope');
write_assert(str_contains($assignmentClose, 'resolveAssignmentStation') && str_contains($assignmentClose, 'UNAUTHORIZED_STATION_ACCESS'), 'SWA21', 'assignment close resolves and enforces target station scope');
write_assert(str_contains($assignmentReplace, 'resolveAssignmentIdentity') && str_contains($assignmentReplace, 'UNAUTHORIZED_STATION_ACCESS'), 'SWA22', 'assignment replacement resolves and enforces target station scope');
write_assert(str_contains($materialize, "'authorized_stations'") && str_contains($materialize, 'UNAUTHORIZED_STATION_ACCESS'), 'SWA23', 'legacy materialization retains its own station-scope check');
write_assert(str_contains($controller, 'HTTP_AUTHORIZATION') && str_contains($controller, "authorizeBiometricos(\$conexion, \$auth, 'editar')"), 'SWA24', 'write handlers require an authenticated user JWT and never substitute Device Auth');
write_assert(strpos($assignmentCreate, 'authorizeBiometricos') === false && strpos($assignmentClose, 'authorizeBiometricos') === false && strpos($assignmentReplace, 'authorizeBiometricos') === false, 'SWA25', 'assignment lifecycle does not depend on broad biometricos.edit');
write_assert(strpos($controller, 'acceso-temporal') === false, 'SWA26', 'temporary-access grants do not authorize scheduling writes');
write_assert(str_contains($templateCatalogRead, 'ShiftSchedulingAuthorizationService::authorizeTemplateCatalogRead'), 'SWA27', 'template catalog reads use the read-or-admin policy from the addendum');
write_assert(str_contains($authorization, 'positionCanUseCapability') && str_contains($authorization, 'resolveStationScope'), 'SWA28', 'position capability and canonical station scope are enforced in the shared authorization service');

echo "SWA_SUITE: " . ($passed + $failed) . " PASS: {$passed} FAIL: {$failed}\n";
exit($failed === 0 ? 0 : 1);
