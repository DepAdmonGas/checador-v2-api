<?php
/**
 * Static contract checks for the five scheduling write routes.
 * Runtime mutation tests remain isolated in the disposable DB suites.
 */

declare(strict_types=1);

$controller = file_get_contents(__DIR__ . '/../modelo/v2/shift/ShiftSchedulingV2Controller.php');
$passed = 0;
$failed = 0;

function write_assert(bool $condition, string $id, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[$id] PASS: {$message}\n";
    } else {
        $failed++;
        echo "[$id] FAIL: {$message}\n";
    }
}

function write_method(string $source, string $method): string
{
    $pattern = '/public function ' . preg_quote($method, '/') . '\\b.*?(?=\\n    (?:public|private|protected) function |\\n})/s';
    return preg_match($pattern, $source, $matches) === 1 ? $matches[0] : '';
}

$routes = [
    'handleCreateTemplate',
    'handleCreateTemplateVersion',
    'handleCreateAssignment',
    'handleCloseAssignment',
    'handleMaterializeScheduledShifts',
];

write_assert($controller !== false, 'SWA01', 'controller source is readable');
$obsoleteCall = 'Portal3JwtAuthHelper::authorize' . 'User(';
write_assert(strpos($controller, $obsoleteCall) === false, 'SWA02', 'obsolete authorizeUser dependency is absent');
write_assert(substr_count($controller, '$this->authorizeScheduleWrite()') === 5, 'SWA03', 'all five write routes use the write authorization gate');
write_assert(strpos($controller, "Portal3JwtAuthHelper::validateToken(\$conexion, \$authHeader)") !== false, 'SWA04', 'write gate validates the canonical Portal3 JWT');
write_assert(strpos($controller, "Portal3JwtAuthHelper::authorizeBiometricos(\$conexion, \$auth, 'editar')") !== false, 'SWA05', 'write gate requires biometricos.editar');

foreach ($routes as $index => $method) {
    $body = write_method($controller, $method);
    write_assert($body !== '', 'SWA' . (6 + $index), $method . ' remains a concrete controller route');
    write_assert(strpos($body, '$this->authorizeScheduleWrite()') !== false, 'SWA' . (11 + $index), $method . ' authenticates before mutation');
}

$createAssignment = write_method($controller, 'handleCreateAssignment');
$closeAssignment = write_method($controller, 'handleCloseAssignment');
$materialize = write_method($controller, 'handleMaterializeScheduledShifts');
write_assert(strpos($createAssignment, "'authorized_stations'") !== false, 'SWA16', 'assignment creation preserves station scope enforcement');
write_assert(strpos($materialize, "'authorized_stations'") !== false, 'SWA17', 'materialization preserves station scope enforcement');
write_assert(strpos($closeAssignment, 'resolveAssignmentStation') !== false, 'SWA18', 'assignment close resolves target station before mutation');
write_assert(strpos($closeAssignment, "'UNAUTHORIZED_STATION_ACCESS'") !== false, 'SWA19', 'assignment close denies out-of-scope station');
write_assert(strpos($controller, 'HTTP_AUTHORIZATION') !== false, 'SWA20', 'write gate does not accept Device Auth as a substitute');
write_assert(strpos($controller, 'acceso-temporal') === false, 'SWA21', 'temporary-access grants do not authorize scheduling writes');
write_assert(strpos($controller, 'authorizeScheduleRead') !== false, 'SWA22', 'read-side authorization helper remains present');
write_assert(substr_count($controller, '$this->authorizeScheduleRead()') === 4, 'SWA23', 'all four read routes retain their read gate');
write_assert(strpos($controller, 'biometricos.editar') === false, 'SWA24', 'no new permission model or ad-hoc ACL key was introduced');

echo "SWA_SUITE: " . ($passed + $failed) . " PASS: {$passed} FAIL: {$failed}\n";
exit($failed === 0 ? 0 : 1);
