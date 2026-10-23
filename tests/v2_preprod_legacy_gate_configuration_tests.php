<?php
declare(strict_types=1);

/**
 * Suite Focal de Pruebas: Legacy Route Fail-Closed Gate & Configuration Contract (V2.7)
 *
 * Valida los requerimientos E1 a E4 de CHECADOR_V2_PREPRODUCTION_LEGACY_GATE_FOCAL_IMPLEMENTATION:
 * - E1: Perfiles de entorno (preproduction, missing, empty, invalid, conflicting, production, dev, test)
 * - E2: Cobertura de las 6 rutas legacy (canónicas, prefijos históricos, 404, V2)
 * - E3: Cero ejecución SQL y cero conexiones BD en rutas bloqueadas
 * - E4: Seguridad e inviolabilidad desde cliente (headers, query strings, cookies, payloads)
 */

$testDbHost = getenv('DB_HOST') ?: '127.0.0.1';
$testDbUser = getenv('DB_USER') ?: 'root';
$testDbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'kimera';
$testDbName = getenv('DB_NAME') ?: 'bd_admongas_v2_test_20261002';

function run_request(string $method, string $uri, array $envOverrides = [], array $headers = [], string $body = ''): array {
    global $testDbHost, $testDbUser, $testDbPass, $testDbName;
    $script = realpath(__DIR__ . '/../index.php');

    $env = [
        'REDIRECT_STATUS' => '200',
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $uri,
        'SCRIPT_FILENAME' => $script,
        'SCRIPT_NAME' => '/index.php',
        'DB_HOST' => $testDbHost,
        'DB_USER' => $testDbUser,
        'DB_PASS' => $testDbPass,
        'DB_NAME' => $testDbName,
        'JWT_SECRET' => getenv('JWT_SECRET') ?: 'TestJwtSecretForGateTests_2026!',
    ];

    foreach ($envOverrides as $k => $v) {
        if ($v === null) {
            unset($env[$k]);
        } else {
            $env[$k] = (string)$v;
        }
    }

    if ($body !== '') {
        $env['CONTENT_LENGTH'] = (string)strlen($body);
    }

    foreach ($headers as $k => $v) {
        if ($k === 'Content-Type') {
            $env['CONTENT_TYPE'] = (string)$v;
        } else {
            $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
            $env[$headerKey] = (string)$v;
        }
    }

    $envPrefix = '';
    foreach ($env as $k => $v) {
        $envPrefix .= $k . '=' . escapeshellarg($v) . ' ';
    }

    $descriptorSpec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];

    $process = proc_open($envPrefix . 'php-cgi', $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        return ["code" => 500, "headers" => [], "body" => "Failed to proc_open php-cgi"];
    }

    if ($body !== '') {
        fwrite($pipes[0], $body);
    }
    fclose($pipes[0]);

    $rawOutput = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $parts = explode("\r\n\r\n", $rawOutput, 2);
    $headerBlock = $parts[0] ?? '';
    $bodyContent = $parts[1] ?? '';

    $code = 200;
    $headersParsed = [];
    foreach (explode("\r\n", $headerBlock) as $line) {
        if (stripos($line, 'Status:') === 0) {
            if (preg_match('/Status:\s+(\d+)/i', $line, $m)) {
                $code = (int)$m[1];
            }
        } elseif (strpos($line, ': ') !== false) {
            [$hk, $hv] = explode(': ', $line, 2);
            $headersParsed[strtolower($hk)] = $hv;
        }
    }

    $json = json_decode($bodyContent, true);

    return [
        'code' => $code,
        'headers' => $headersParsed,
        'body' => $bodyContent,
        'json' => is_array($json) ? $json : null
    ];
}

$passed = 0;
$failed = 0;

function assert_gate_test(string $id, string $description, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$id}: {$description}\n";
    } else {
        $failed++;
        echo "[FAIL] {$id}: {$description} - {$detail}\n";
    }
}

echo "=== INICIANDO SUITE FOCAL LEGACY GATE PREPRODUCCION ===\n\n";

// =============================================================================
// SECCIÓN E1: MATRIZ DE PERFILES Y REGLAS DE CONFIGURACIÓN
// =============================================================================
echo "--- E1: Perfiles de Entorno y Reglas R1-R7 ---\n";

// E1-01: CHECADOR_DEPLOYMENT_PROFILE=preproduction bloquea legacy con HTTP 503
$res = run_request('POST', '/acceso-empresa', ['CHECADOR_DEPLOYMENT_PROFILE' => 'preproduction'], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-01', 'CHECADOR_DEPLOYMENT_PROFILE=preproduction bloquea /acceso-empresa con 503',
    $res['code'] === 503 && ($res['json']['status'] ?? '') === 'SERVICE_UNAVAILABLE',
    "Código: {$res['code']}, Body: {$res['body']}"
);

// E1-02: APP_ENV=preproduction bloquea legacy con HTTP 503
$res = run_request('GET', '/personal', ['APP_ENV' => 'preproduction', 'CHECADOR_DEPLOYMENT_PROFILE' => null]);
assert_gate_test('E1-02', 'APP_ENV=preproduction bloquea /personal con 503',
    $res['code'] === 503 && ($res['json']['code'] ?? 0) === 503,
    "Código: {$res['code']}"
);

// E1-03: v2_preproduction_isolated bloquea legacy con HTTP 503
$res = run_request('POST', '/acceso-personal', ['CHECADOR_DEPLOYMENT_PROFILE' => 'v2_preproduction_isolated']);
assert_gate_test('E1-03', 'Perfil v2_preproduction_isolated bloquea con 503',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E1-04: Preproducción ignora override de LEGACY_ROUTES_ENABLED=true (R1 + R7)
$res = run_request('POST', '/acceso-empresa', [
    'CHECADOR_DEPLOYMENT_PROFILE' => 'preproduction',
    'LEGACY_ROUTES_ENABLED' => 'true'
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-04', 'Preproducción con LEGACY_ROUTES_ENABLED=true permanece bloqueada (anti-override)',
    $res['code'] === 503 && ($res['json']['status'] ?? '') === 'SERVICE_UNAVAILABLE',
    "Código: {$res['code']}"
);

// E1-05: Preproducción con LEGACY_ROUTES_ENABLED=1 permanece bloqueada (R1 + R7)
$res = run_request('GET', '/personal-asistencia', [
    'APP_ENV' => 'preproduction',
    'LEGACY_ROUTES_ENABLED' => '1',
    'CHECADOR_DEPLOYMENT_PROFILE' => null
]);
assert_gate_test('E1-05', 'Preproducción con LEGACY_ROUTES_ENABLED=1 permanece bloqueada',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E1-06: Perfil ausente (missing) bloquea legacy fail-closed (R2)
$res = run_request('POST', '/acceso-empresa', [
    'CHECADOR_DEPLOYMENT_PROFILE' => null,
    'APP_ENV' => null
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-06', 'Perfil ausente (missing) bloquea legacy fail-closed (R2)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E1-07: Perfil vacío ("") bloquea legacy fail-closed (R2)
$res = run_request('GET', '/personal', [
    'CHECADOR_DEPLOYMENT_PROFILE' => '',
    'APP_ENV' => ''
]);
assert_gate_test('E1-07', 'Perfil vacío ("") bloquea legacy fail-closed (R2)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E1-08: Perfil solo espacios ("   ") bloquea legacy fail-closed (R2)
$res = run_request('GET', '/personal', [
    'CHECADOR_DEPLOYMENT_PROFILE' => '   ',
    'APP_ENV' => '   '
]);
assert_gate_test('E1-08', 'Perfil solo espacios bloquea legacy fail-closed (R2)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E1-09: Perfil inválido/desconocido ("staging") bloquea legacy fail-closed (R3)
$res = run_request('POST', '/acceso-empresa', [
    'CHECADOR_DEPLOYMENT_PROFILE' => 'staging',
    'APP_ENV' => null
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-09', 'Perfil inválido (staging) bloquea legacy fail-closed (R3)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E1-10: Configuración contradictoria (preprod vs prod) bloquea legacy fail-closed (R4)
$res = run_request('POST', '/acceso-empresa', [
    'APP_ENV' => 'preproduction',
    'CHECADOR_DEPLOYMENT_PROFILE' => 'historical_production_dual'
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-10', 'Configuración contradictoria (preprod vs prod) bloquea fail-closed (R4)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E1-11: Configuración contradictoria (dev vs preprod) bloquea legacy fail-closed (R4)
$res = run_request('GET', '/personal', [
    'APP_ENV' => 'development',
    'CHECADOR_DEPLOYMENT_PROFILE' => 'preproduction'
]);
assert_gate_test('E1-11', 'Configuración contradictoria (dev vs preprod) bloquea fail-closed (R4)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E1-12: Producción histórica explícita (historical_production_dual) permite legacy (R5)
$res = run_request('POST', '/acceso-empresa', [
    'CHECADOR_DEPLOYMENT_PROFILE' => 'historical_production_dual',
    'APP_ENV' => null
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-12', 'Producción histórica explícita (historical_production_dual) permite legacy (R5)',
    $res['code'] === 200,
    "Código: {$res['code']}"
);

// E1-13a: CASO 2: CHECADOR_DEPLOYMENT_PROFILE ausente con APP_ENV=production -> FAIL-CLOSED (HTTP 503)
$res = run_request('POST', '/acceso-empresa', [
    'APP_ENV' => 'production',
    'CHECADOR_DEPLOYMENT_PROFILE' => null
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-13a', 'CASO 2: CHECADOR_DEPLOYMENT_PROFILE ausente con APP_ENV=production bloquea con 503',
    $res['code'] === 503 && ($res['json']['status'] ?? '') === 'SERVICE_UNAVAILABLE',
    "Código: {$res['code']}"
);

// E1-13b: CASO 3: CHECADOR_DEPLOYMENT_PROFILE ausente con APP_ENV=production y LEGACY_ROUTES_ENABLED=true -> FAIL-CLOSED (HTTP 503)
$res = run_request('POST', '/acceso-empresa', [
    'APP_ENV' => 'production',
    'CHECADOR_DEPLOYMENT_PROFILE' => null,
    'LEGACY_ROUTES_ENABLED' => 'true'
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-13b', 'CASO 3: CHECADOR_DEPLOYMENT_PROFILE ausente con APP_ENV=production y LEGACY_ROUTES_ENABLED=true bloquea con 503',
    $res['code'] === 503 && ($res['json']['status'] ?? '') === 'SERVICE_UNAVAILABLE',
    "Código: {$res['code']}"
);

// E1-13c: CHECADOR_DEPLOYMENT_PROFILE ausente con APP_ENV=development -> FAIL-CLOSED (HTTP 503)
$res = run_request('POST', '/acceso-empresa', [
    'APP_ENV' => 'development',
    'CHECADOR_DEPLOYMENT_PROFILE' => null
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-13c', 'CHECADOR_DEPLOYMENT_PROFILE ausente con APP_ENV=development bloquea con 503',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E1-13d: CASO 7: CHECADOR_DEPLOYMENT_PROFILE=production explícito con APP_ENV ausente -> PERMITIDO (200)
$res = run_request('POST', '/acceso-empresa', [
    'CHECADOR_DEPLOYMENT_PROFILE' => 'production',
    'APP_ENV' => null
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-13d', 'CASO 7: CHECADOR_DEPLOYMENT_PROFILE=production explícito con APP_ENV ausente permite legacy (200)',
    $res['code'] === 200,
    "Código: {$res['code']}"
);

// E1-13e: CASO 8: CHECADOR_DEPLOYMENT_PROFILE=production explícito con APP_ENV=production -> PERMITIDO (200)
$res = run_request('POST', '/acceso-empresa', [
    'CHECADOR_DEPLOYMENT_PROFILE' => 'production',
    'APP_ENV' => 'production'
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-13e', 'CASO 8: CHECADOR_DEPLOYMENT_PROFILE=production explícito con APP_ENV=production permite legacy (200)',
    $res['code'] === 200,
    "Código: {$res['code']}"
);

// E1-13f: CASO 5: CHECADOR_DEPLOYMENT_PROFILE=preproduction con APP_ENV=production -> FAIL-CLOSED (503)
$res = run_request('POST', '/acceso-empresa', [
    'CHECADOR_DEPLOYMENT_PROFILE' => 'preproduction',
    'APP_ENV' => 'production'
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-13f', 'CASO 5: CHECADOR_DEPLOYMENT_PROFILE=preproduction con APP_ENV=production bloquea con 503',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E1-14: Producción histórica con LEGACY_ROUTES_ENABLED=false restringe legacy (R7)
$res = run_request('POST', '/acceso-empresa', [
    'CHECADOR_DEPLOYMENT_PROFILE' => 'historical_production_dual',
    'LEGACY_ROUTES_ENABLED' => 'false'
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-14', 'Producción histórica con LEGACY_ROUTES_ENABLED=false se bloquea (R7)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E1-15: Producción histórica con LEGACY_ROUTES_ENABLED=invalid_val bloquea legacy (R7)
$res = run_request('POST', '/acceso-empresa', [
    'CHECADOR_DEPLOYMENT_PROFILE' => 'historical_production_dual',
    'LEGACY_ROUTES_ENABLED' => 'invalid_token'
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-15', 'Producción histórica con valor inválido secundario bloquea legacy (R7)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E1-16: Perfil de desarrollo explícito (development) permite legacy (R6)
$res = run_request('POST', '/acceso-empresa', [
    'CHECADOR_DEPLOYMENT_PROFILE' => 'development',
    'APP_ENV' => null
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-16', 'Perfil de desarrollo explícito (development) permite legacy (R6)',
    $res['code'] === 200,
    "Código: {$res['code']}"
);

// E1-17: Perfil de test explícito (test) permite legacy en runner (R6)
$res = run_request('POST', '/acceso-empresa', [
    'CHECADOR_DEPLOYMENT_PROFILE' => 'test',
    'APP_ENV' => null
], ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E1-17', 'Perfil de test explícito (test) permite legacy (R6)',
    $res['code'] === 200,
    "Código: {$res['code']}"
);

echo "\n--- E2: Cobertura de las 6 Rutas Legacy y Prefijos ---\n";

$preprodEnv = ['CHECADOR_DEPLOYMENT_PROFILE' => 'preproduction', 'APP_ENV' => null];

// E2-01: /acceso-empresa canónica bloqueada
$res = run_request('POST', '/acceso-empresa', $preprodEnv, ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E2-01', 'Ruta 1/6: /acceso-empresa bloqueada con 503',
    $res['code'] === 503 && ($res['json']['status'] ?? '') === 'SERVICE_UNAVAILABLE',
    "Código: {$res['code']}"
);

// E2-02: /personal canónica bloqueada
$res = run_request('GET', '/personal', $preprodEnv);
assert_gate_test('E2-02', 'Ruta 2/6: /personal bloqueada con 503',
    $res['code'] === 503 && ($res['json']['status'] ?? '') === 'SERVICE_UNAVAILABLE',
    "Código: {$res['code']}"
);

// E2-03: /editar-pin canónica bloqueada
$res = run_request('PUT', '/editar-pin', $preprodEnv);
assert_gate_test('E2-03', 'Ruta 3/6: /editar-pin bloqueada con 503',
    $res['code'] === 503 && ($res['json']['status'] ?? '') === 'SERVICE_UNAVAILABLE',
    "Código: {$res['code']}"
);

// E2-04: /editar-huella canónica bloqueada
$res = run_request('PUT', '/editar-huella', $preprodEnv);
assert_gate_test('E2-04', 'Ruta 4/6: /editar-huella bloqueada con 503',
    $res['code'] === 503 && ($res['json']['status'] ?? '') === 'SERVICE_UNAVAILABLE',
    "Código: {$res['code']}"
);

// E2-05: /acceso-personal canónica bloqueada
$res = run_request('POST', '/acceso-personal', $preprodEnv);
assert_gate_test('E2-05', 'Ruta 5/6: /acceso-personal bloqueada con 503',
    $res['code'] === 503 && ($res['json']['status'] ?? '') === 'SERVICE_UNAVAILABLE',
    "Código: {$res['code']}"
);

// E2-06: /personal-asistencia canónica bloqueada
$res = run_request('GET', '/personal-asistencia', $preprodEnv);
assert_gate_test('E2-06', 'Ruta 6/6: /personal-asistencia bloqueada con 503',
    $res['code'] === 503 && ($res['json']['status'] ?? '') === 'SERVICE_UNAVAILABLE',
    "Código: {$res['code']}"
);

// E2-07: Prefijo histórico /portal-api-v.1.0.1/acceso-empresa bloqueado (anti-bypass)
$res = run_request('POST', '/portal-api-v.1.0.1/acceso-empresa', $preprodEnv, ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E2-07', 'Prefijo /portal-api-v.1.0.1/acceso-empresa bloqueado con 503 (anti-bypass)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E2-08: Prefijo histórico /portal-api-v.2.0/personal bloqueado (anti-bypass)
$res = run_request('GET', '/portal-api-v.2.0/personal', $preprodEnv);
assert_gate_test('E2-08', 'Prefijo /portal-api-v.2.0/personal bloqueado con 503 (anti-bypass)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E2-09: Slashes duplicados //acceso-empresa normalizados y bloqueados
$res = run_request('POST', '///acceso-empresa', $preprodEnv, ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E2-09', 'Slashes duplicados ///acceso-empresa bloqueados con 503',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E2-10: Query string /personal?foo=bar bloqueado
$res = run_request('GET', '/personal?foo=bar&baz=1', $preprodEnv);
assert_gate_test('E2-10', 'Query string /personal?foo=bar bloqueado con 503',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E2-11: Ruta V2 válida NO es afectada por el gate (preservada)
$res = run_request('GET', '/api/v2/punch-events', $preprodEnv);
assert_gate_test('E2-11', 'Ruta V2 /api/v2/punch-events no es bloqueada con 503 (retiene 401 V2)',
    $res['code'] === 401 && ($res['json']['status'] ?? '') === 'UNAUTHORIZED',
    "Código: {$res['code']}, Body: {$res['body']}"
);

// E2-12: Ruta desconocida retorna 404 (no 503)
$res = run_request('GET', '/ruta-completamente-inexistente', $preprodEnv);
assert_gate_test('E2-12', 'Ruta inexistente retorna HTTP 404 (no 503)',
    $res['code'] === 404,
    "Código: {$res['code']}"
);

// E2-12b: CASO 15: Ruta desconocida bajo /api/v2 retorna JSON 404 canónico
$res = run_request('GET', '/api/v2/ruta-inexistente', $preprodEnv);
assert_gate_test('E2-12b', 'CASO 15: Ruta desconocida bajo /api/v2 retorna JSON 404 canónico',
    $res['code'] === 404 && ($res['json']['status'] ?? '') === 'NOT_FOUND',
    "Código: {$res['code']}"
);

// E2-13: Subruta no registrada de ruta legacy (/personal/otra-cosa) retorna 404
$res = run_request('GET', '/personal/otra-cosa', $preprodEnv);
assert_gate_test('E2-13', 'Subruta desconocida /personal/otra-cosa retorna 404 (no 503)',
    $res['code'] === 404,
    "Código: {$res['code']}"
);

// E2-14: Prefijo desconocido (/random/acceso-empresa) retorna 404
$res = run_request('POST', '/random/acceso-empresa', $preprodEnv, ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E2-14', 'Prefijo desconocido /random/acceso-empresa retorna 404 (no bypass)',
    $res['code'] === 404,
    "Código: {$res['code']}"
);

echo "\n--- E3: Cero Conexión SQL y Cero Consultas en Rutas Bloqueadas ---\n";

// E3-01: Probar todas las 6 rutas con credenciales DB completamente inválidas.
// Si el gate fallara e intentara conectar a MySQL, se dispararía una excepción o 500.
// Si el gate funciona, responde 503 inmediatamente sin conectar a MySQL.
$invalidDbEnv = array_merge($preprodEnv, [
    'DB_HOST' => '127.0.0.1',
    'DB_USER' => 'usuario_totalmente_invalido_que_no_existe',
    'DB_PASS' => 'contrasena_invalida_totalmente_falsa',
    'DB_NAME' => 'bd_inexistente_que_fallaria_si_conecta'
]);

$legacyRoutes = [
    ['POST', '/acceso-empresa'],
    ['GET', '/personal'],
    ['PUT', '/editar-pin'],
    ['PUT', '/editar-huella'],
    ['POST', '/acceso-personal'],
    ['GET', '/personal-asistencia']
];

$allZeroSql = true;
foreach ($legacyRoutes as [$m, $r]) {
    $res = run_request($m, $r, $invalidDbEnv);
    if ($res['code'] !== 503) {
        $allZeroSql = false;
        echo "[FAIL] Ruta {$r} intentó conexión DB y retornó {$res['code']} en vez de 503\n";
    }
}
assert_gate_test('E3-01', 'Las 6 rutas legacy responden 503 ante BD inválida (demuestra cero conexión SQL)',
    $allZeroSql,
    "Una o más rutas intentaron conectar a la base de datos"
);

// E3-02: Verificación de contrato HTTP 503 payload estricto
$res = run_request('POST', '/acceso-empresa', $preprodEnv);
$jsonExpected = [
    "code" => 503,
    "status" => "SERVICE_UNAVAILABLE",
    "mensaje" => "Servicio no disponible en este entorno."
];
$headers = $res['headers'];
$isPayloadStrict = ($res['json'] === $jsonExpected);
$isContentTypeJson = isset($headers['content-type']) && stripos($headers['content-type'], 'application/json') !== false;
$isCacheControlNoStore = isset($headers['cache-control']) && stripos($headers['cache-control'], 'no-store') !== false;

assert_gate_test('E3-02', 'Contrato HTTP 503: Headers Content-Type, Cache-Control y JSON estricto sin fuga',
    $isPayloadStrict && $isContentTypeJson && $isCacheControlNoStore,
    "Payload o headers no cumplen especificación: " . json_encode($res)
);

echo "\n--- E4: Inviolabilidad de Seguridad desde el Cliente ---\n";

// E4-01: Header X-Environment no altera el gate
$res = run_request('POST', '/acceso-empresa', $preprodEnv, ['X-Environment' => 'production'], '{}');
assert_gate_test('E4-01', 'Header X-Environment: production no puede evadir el gate (503)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E4-02: Header X-Forwarded-Host no altera el gate
$res = run_request('POST', '/acceso-empresa', $preprodEnv, ['X-Forwarded-Host' => 'portal3.admongas.com.mx'], '{}');
assert_gate_test('E4-02', 'Header X-Forwarded-Host no puede evadir el gate (503)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E4-03: Header Host no altera el gate
$res = run_request('POST', '/acceso-empresa', $preprodEnv, ['Host' => 'portal3.admongas.com.mx'], '{}');
assert_gate_test('E4-03', 'Header Host manipulado no puede evadir el gate (503)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E4-04: Parámetro en Query string no altera el gate
$res = run_request('POST', '/acceso-empresa?APP_ENV=production&profile=historical', $preprodEnv, ['Content-Type' => 'application/json'], '{}');
assert_gate_test('E4-04', 'Query string con variables de entorno simuladas no puede evadir el gate (503)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E4-05: Payload JSON manipulado no altera el gate
$res = run_request('POST', '/acceso-empresa', $preprodEnv, ['Content-Type' => 'application/json'], '{"APP_ENV":"production","CHECADOR_DEPLOYMENT_PROFILE":"historical_production_dual"}');
assert_gate_test('E4-05', 'Payload con campos de entorno simulados no puede evadir el gate (503)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

// E4-06: Cookie manipulada no altera el gate
$res = run_request('POST', '/acceso-empresa', $preprodEnv, ['Cookie' => 'APP_ENV=production; CHECADOR_DEPLOYMENT_PROFILE=historical_production_dual'], '{}');
assert_gate_test('E4-06', 'Cookie manipulada no puede evadir el gate (503)',
    $res['code'] === 503,
    "Código: {$res['code']}"
);

echo "\n=======================================================\n";
echo "RESULTADOS TOTALES: {$passed} PASS, {$failed} FAIL\n";
echo "=======================================================\n";

if ($failed > 0) {
    exit(1);
}
