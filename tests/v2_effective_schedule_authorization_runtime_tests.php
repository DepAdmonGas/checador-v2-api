<?php
/**
 * Focused, non-mutating regression suite for schedule read authorization.
 * ESA01-ESA20 intentionally inspect the runtime contract and source paths;
 * no database connection or fixture mutation is performed here.
 */

declare(strict_types=1);

$controllerPath = __DIR__ . '/../modelo/v2/shift/ShiftSchedulingV2Controller.php';
$resolverPath = __DIR__ . '/../modelo/v2/shift/ScheduleResolutionService.php';
$helperPath = __DIR__ . '/../modelo/v2/Portal3JwtAuthHelper.php';
$controller = file_get_contents($controllerPath);
$resolver = file_get_contents($resolverPath);
$helper = file_get_contents($helperPath);

$passed = 0;
$failed = 0;

function esa(string $id, string $description, callable $assertion): void
{
    global $passed, $failed;
    try {
        if ($assertion() === true) {
            echo "[$id] PASS: $description\n";
            $passed++;
        } else {
            echo "[$id] FAIL: $description\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "[$id] FAIL: $description — {$e->getMessage()}\n";
        $failed++;
    }
}

function methodBody(string $source, string $method): string
{
    $pattern = '/(?:public|private|protected) function ' . preg_quote($method, '/') . '\\b.*?(?=(?:\n    (?:public|private|protected) function )|\\n})/s';
    if (preg_match($pattern, $source, $matches) !== 1) {
        return '';
    }
    return $matches[0];
}

$readMethods = [
    'handleListTemplates',
    'handleGetTemplateVersion',
    'handleListAssignments',
    'handleGetEffectiveSchedule',
];

esa('ESA01', 'authorized Portal3 read path uses the schedule authorization gate', function () use ($controller, $readMethods): bool {
    foreach ($readMethods as $method) {
        if (strpos(methodBody($controller, $method), '$this->authorizeScheduleRead()') === false) return false;
    }
    return true;
});

esa('ESA02', 'effective schedule preserves a defined success response path', function () use ($controller): bool {
    $body = methodBody($controller, 'handleGetEffectiveSchedule');
    return strpos($body, '$this->sendJsonSuccess($result, 200)') !== false
        && strpos($body, 'estado_jornada') === false;
});

esa('ESA03', 'missing JWT is handled by validateToken', function () use ($controller): bool {
    return strpos($controller, 'Portal3JwtAuthHelper::validateToken($conexion, $authHeader)') !== false
        && strpos($controller, "'UNAUTHORIZED'") === false;
});

esa('ESA04', 'invalid JWT is propagated before schedule resolution', function () use ($controller): bool {
    return strpos($controller, 'if (!$auth[\'valid\'])') !== false
        && strpos($controller, '$this->sendJsonError($auth[\'status\'], $auth[\'error\'], $auth[\'code\'])') !== false;
});

esa('ESA05', 'biometricos.leer is the required functional permission', function () use ($controller): bool {
    return substr_count($controller, 'authorizeBiometricos($conexion, $auth, \'leer\')') === 1;
});

esa('ESA06', 'temporary-access permission is not used for schedule reads', function () use ($controller): bool {
    return strpos($controller, 'authorizeTemporaryAccess') === false;
});

esa('ESA07', 'Device Auth is not used by the schedule controller', function () use ($controller): bool {
    return strpos($controller, 'authenticateDevice') === false
        && strpos($controller, 'DeviceAuth') === false;
});

esa('ESA08', 'effective schedule enforces canonical authorized workplace scope', function () use ($controller): bool {
    $body = methodBody($controller, 'handleGetEffectiveSchedule');
    return strpos($body, '$authz[\'authorized_stations\']') !== false
        && strpos($body, 'UNAUTHORIZED_STATION_ACCESS') !== false;
});

esa('ESA09', 'unknown workers resolve contractually rather than throwing', function () use ($resolver): bool {
    return strpos($resolver, "'estado_jornada' => 'UNKNOWN'") !== false
        && strpos($resolver, 'Worker is not mapped in system') !== false;
});

esa('ESA10', 'inactive users are rejected by the shared active-user validator', function () use ($helper): bool {
    return strpos($helper, 'estatus = 0') !== false
        && strpos($helper, 'Usuario autenticado inactivo o inexistente') !== false;
});

esa('ESA11', 'canonical worker identity is op_rh_personal.id', function () use ($resolver): bool {
    return strpos($resolver, 'SELECT id FROM op_rh_personal WHERE id = ?') !== false;
});

esa('ESA12', 'no_colaborador is not an authorization fallback', function () use ($controller, $resolver, $helper): bool {
    return strpos($controller, 'no_colaborador') === false
        && strpos($resolver, 'no_colaborador') === false
        && strpos($helper, 'no_colaborador') === false;
});

esa('ESA13', 'external worker references are not authorization fallbacks', function () use ($controller, $resolver): bool {
    return strpos($controller, 'external_worker_reference') === false
        && strpos($resolver, 'referencia_externa_personal') === false;
});

esa('ESA14', 'DAY_OFF and UNSCHEDULED remain distinct outcomes', function () use ($resolver): bool {
    return strpos($resolver, "'estado_jornada' => 'DAY_OFF'") !== false
        && strpos($resolver, "'estado_jornada' => 'UNSCHEDULED'") !== false;
});

esa('ESA15', 'night shift day offsets are preserved', function () use ($resolver): bool {
    return strpos($resolver, 'dia_offset_inicio') !== false
        && strpos($resolver, 'dia_offset_fin') !== false
        && strpos($resolver, '+{$offFin} days') !== false;
});

esa('ESA16', 'all returned template segments are preserved', function () use ($resolver): bool {
    return strpos($resolver, 'while ($s = $resSeg->fetch_assoc())') !== false
        && strpos($resolver, "'segmentos' => \$segmentos") !== false;
});

esa('ESA17', 'no station fallback is introduced by the remediation', function () use ($controller, $resolver): bool {
    return strpos($controller, 'station_fallback') === false
        && strpos($resolver, 'fallback') === false;
});

esa('ESA18', 'resolver does not infer SIN MARCACIÓN', function () use ($resolver): bool {
    return stripos($resolver, 'SIN MARCACIÓN') === false;
});

esa('ESA19', 'resolver does not infer FALTA or payroll outcomes', function () use ($resolver): bool {
    return stripos($resolver, 'FALTA') === false
        && stripos($resolver, 'payroll') === false
        && stripos($resolver, 'nómina') === false;
});

esa('ESA20', 'temporary-access authorization remains outside this controller', function () use ($controller): bool {
    return strpos($controller, 'acceso-temporal') === false
        && strpos($controller, 'authorizeUser($_SERVER') === false
        && substr_count($controller, '$this->authorizeScheduleWrite()') === 5
        && substr_count($controller, "authorizeBiometricos(\$conexion, \$auth, 'editar')") === 1;
});

echo "ESA_SUITE: " . ($passed + $failed) . " PASS: $passed FAIL: $failed\n";
exit($failed === 0 ? 0 : 1);
