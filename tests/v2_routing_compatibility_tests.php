<?php
declare(strict_types=1);

/**
 * Suite de Pruebas de Compatibilidad de Enrutamiento de Base Root (V2.7)
 *
 * Valida los requerimientos R01 a R15 para asegurar que index.php resuelva correctamente
 * tanto las rutas canónicas directas en la raíz del subdominio (/api/v2/..., /acceso-empresa)
 * como los prefijos históricos opcionales (/portal-api-v.1.0.1/..., /portal-api-v.2.0/...)
 * sin descartar arbitrariamente segmentos desconocidos.
 */

$testDbHost = getenv('DB_HOST') ?: '127.0.0.1';
$testDbUser = getenv('DB_USER') ?: 'root';
$testDbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'kimera';
$testDbName = getenv('DB_NAME') ?: 'bd_admongas_v2_test_20261002';

function execute_request(string $method, string $uri, array $headers = [], string $body = ''): array {
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
        'JWT_SECRET' => getenv('JWT_SECRET') ?: 'TestJwtSecretForRoutingCompatibility_2026!',
        'CHECADOR_DEPLOYMENT_PROFILE' => getenv('CHECADOR_DEPLOYMENT_PROFILE') ?: 'development',
    ];

    if ($body !== '') {
        $env['CONTENT_LENGTH'] = (string)strlen($body);
    }

    foreach ($headers as $k => $v) {
        $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
        if ($k === 'Content-Type') {
            $env['CONTENT_TYPE'] = (string)$v;
        } else {
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

    return [
        'code' => $code,
        'headers' => $headersParsed,
        'body' => $bodyContent
    ];
}

$passed = 0;
$failed = 0;

function assert_test(string $id, string $description, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$id}: {$description}\n";
    } else {
        $failed++;
        echo "[FAIL] {$id}: {$description} - {$detail}\n";
    }
}

echo "=== INICIANDO SUITE DE ENRUTAMIENTO BASE ROOT (R01-R15) ===\n";

// R01: Root /api/v2/punch-events reaches V2 router
$resR01 = execute_request('GET', '/api/v2/punch-events');
assert_test('R01', 'Root /api/v2/punch-events alcanza el router V2',
    $resR01['code'] !== 404 && in_array($resR01['code'], [401, 405], true),
    "Código recibido: {$resR01['code']}"
);

// R02: Prefixed /portal-api-v.1.0.1/api/v2/punch-events still routes
$resR02 = execute_request('GET', '/portal-api-v.1.0.1/api/v2/punch-events');
assert_test('R02', 'Ruta con prefijo histórico /portal-api-v.1.0.1 sigue funcionando',
    $resR02['code'] === $resR01['code'],
    "Código R02 ({$resR02['code']}) no coincide con R01 ({$resR01['code']})"
);

// R03: Prefixed /portal-api-v.2.0/api/v2/punch-events still routes
$resR03 = execute_request('GET', '/portal-api-v.2.0/api/v2/punch-events');
assert_test('R03', 'Ruta con prefijo histórico /portal-api-v.2.0 sigue funcionando',
    $resR03['code'] === $resR01['code'],
    "Código R03 ({$resR03['code']}) no coincide con R01 ({$resR01['code']})"
);

// R04: Root /api/v2/devices/context routes
$resR04 = execute_request('GET', '/api/v2/devices/context');
assert_test('R04', 'Root /api/v2/devices/context enruta hacia el controlador de dispositivos',
    $resR04['code'] === 401,
    "Código recibido: {$resR04['code']} (esperado 401 por falta de credenciales de dispositivo, no 404)"
);

// R05: Root /api/v2/journeys/evaluate routes
$resR05 = execute_request('GET', '/api/v2/journeys/evaluate');
assert_test('R05', 'Root /api/v2/journeys/evaluate enruta hacia el controlador de jornadas',
    $resR05['code'] === 405 && isset($resR05['headers']['allow']) && stripos($resR05['headers']['allow'], 'POST') !== false,
    "Código: {$resR05['code']}, Allow: " . ($resR05['headers']['allow'] ?? 'none')
);

// R06: Root /api/v2/administrative-evaluations/evaluate routes
$resR06 = execute_request('GET', '/api/v2/administrative-evaluations/evaluate');
assert_test('R06', 'Root /api/v2/administrative-evaluations/evaluate enruta correctamente',
    $resR06['code'] === 405 && isset($resR06['headers']['allow']) && stripos($resR06['headers']['allow'], 'POST') !== false,
    "Código: {$resR06['code']}, Allow: " . ($resR06['headers']['allow'] ?? 'none')
);

// R07: Root /acceso-empresa routes
$resR07 = execute_request('POST', '/acceso-empresa', ['Content-Type' => 'application/json'], '{}');
assert_test('R07', 'Root /acceso-empresa enruta hacia AccesoEmpresa',
    $resR07['code'] === 200,
    "Código recibido: {$resR07['code']} (esperado 200 con JSON de respuesta, no 404)"
);

// R08: Unknown first segment is NOT stripped
$resR08 = execute_request('GET', '/random/api/v2/punch-events');
assert_test('R08', 'Segmento desconocido inicial no se descarta y produce 404',
    $resR08['code'] === 404 || strpos($resR08['body'], '404.') !== false,
    "Código recibido: {$resR08['code']}"
);

// R09: Query string does not alter path matching
$resR09 = execute_request('GET', '/api/v2/punch-events?limite=10&orden=desc');
assert_test('R09', 'Query string no altera la resolución del path',
    $resR09['code'] === $resR01['code'],
    "Código con query string: {$resR09['code']} vs sin query string: {$resR01['code']}"
);

// R10: Trailing slash handling is deterministic
$resR10 = execute_request('GET', '/api/v2/punch-events/');
assert_test('R10', 'Trailing slash resuelve determinísticamente idéntico a la ruta sin slash',
    $resR10['code'] === $resR01['code'],
    "Código con trailing slash: {$resR10['code']} vs sin trailing: {$resR01['code']}"
);

// R11: Duplicate slash handling is safe/deterministic
$resR11 = execute_request('GET', '//api//v2///punch-events');
assert_test('R11', 'Slashes duplicados se normalizan de forma segura e idéntica',
    $resR11['code'] === $resR01['code'],
    "Código con slashes duplicados: {$resR11['code']} vs sin duplicados: {$resR01['code']}"
);

// R12: Unsupported HTTP methods preserve current 405 behavior
$resR12 = execute_request('DELETE', '/api/v2/punch-events');
assert_test('R12', 'Métodos HTTP no soportados preservan 405 Method Not Allowed con Allow header',
    $resR12['code'] === 405 && isset($resR12['headers']['allow']),
    "Código: {$resR12['code']}, Allow header: " . ($resR12['headers']['allow'] ?? 'none')
);

// R13: Authorization headers/auth mechanisms remain unaffected
$resR13 = execute_request('GET', '/api/v2/punch-events', ['Authorization' => 'Bearer fake-invalid-token']);
assert_test('R13', 'Headers de Authorization son procesados por el helper JWT sin alteración',
    $resR13['code'] === 401,
    "Código recibido: {$resR13['code']}"
);

// R14: Root and prefixed representations reach equivalent controllers
$resR14Root = execute_request('POST', '/acceso-empresa', ['Content-Type' => 'application/json'], '{"usuario":"test","password":"bad"}');
$resR14Prefixed = execute_request('POST', '/portal-api-v.1.0.1/acceso-empresa', ['Content-Type' => 'application/json'], '{"usuario":"test","password":"bad"}');
$jsonRoot = json_decode($resR14Root['body'], true);
$jsonPrefixed = json_decode($resR14Prefixed['body'], true);
$equivalent = $resR14Root['code'] === $resR14Prefixed['code']
    && ($jsonRoot['resultado']['code'] ?? null) === ($jsonPrefixed['resultado']['code'] ?? null)
    && ($jsonRoot['resultado']['status'] ?? null) === ($jsonPrefixed['resultado']['status'] ?? null);
assert_test('R14', 'Rutas root y con prefijo alcanzan controladores equivalentes',
    $equivalent,
    "Root body: {$resR14Root['body']} vs Prefixed body: {$resR14Prefixed['body']}"
);

// R15: No canonical response exposes old deployment prefix as required
$resR15 = execute_request('GET', '/api/v2/punch-events');
$prefixExposed = (strpos($resR15['body'], 'portal-api-v.1.0.1') !== false) || (strpos(json_encode($resR15['headers']), 'portal-api-v.1.0.1') !== false);
assert_test('R15', 'Ninguna respuesta canónica expone el prefijo de despliegue antiguo',
    !$prefixExposed,
    "Se detectó el prefijo portal-api-v.1.0.1 en la respuesta"
);

echo "=== RESULTADOS: {$passed} PASS, {$failed} FAIL ===\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
