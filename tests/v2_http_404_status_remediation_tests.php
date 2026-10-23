<?php
declare(strict_types=1);

/**
 * Suite de Pruebas Enfocadas de Corrección HTTP 404 (H404-01 a H404-10)
 *
 * Valida que index.php y modelo/404.php retornen el código HTTP 404 real en rutas inexistentes,
 * preservando la página HTML para rutas raíz legacy y formato JSON para rutas /api/v2,
 * sin alterar los method guards (405) ni romper rutas válidas.
 */

$testDbHost = getenv('DB_HOST') ?: '127.0.0.1';
$testDbUser = getenv('DB_USER') ?: 'root';
$testDbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'kimera';
$testDbName = getenv('DB_NAME') ?: 'bd_admongas_v2_test_20261002';

function execute_request_404(string $method, string $uri, array $headers = [], string $body = ''): array {
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

function assert_h404(string $id, string $description, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$id}: {$description}\n";
    } else {
        $failed++;
        echo "[FAIL] {$id}: {$description} - {$detail}\n";
    }
}

echo "=== INICIANDO SUITE DE REMEDIACION HTTP 404 (H404-01 a H404-10) ===\n";

// H404-01: unknown root route returns HTTP 404
$res01 = execute_request_404('GET', '/ruta-que-no-existe');
assert_h404('H404-01', 'Ruta raíz desconocida retorna HTTP 404',
    $res01['code'] === 404,
    "Código recibido: {$res01['code']}"
);

// H404-02: unknown /api/v2 route returns HTTP 404
$res02 = execute_request_404('GET', '/api/v2/ruta-que-no-existe');
$json02 = json_decode($res02['body'], true);
assert_h404('H404-02', 'Ruta desconocida /api/v2 retorna HTTP 404 y JSON canónico',
    $res02['code'] === 404 && is_array($json02) && ($json02['code'] ?? null) === 404 && ($json02['status'] ?? '') === 'NOT_FOUND',
    "Código: {$res02['code']}, Body: {$res02['body']}"
);

// H404-03: existing HTML 404 body may remain if applicable
assert_h404('H404-03', 'Ruta raíz desconocida preserva cuerpo HTML 404 establecido',
    strpos($res01['body'], 'Error 404') !== false && strpos($res01['body'], '404.') !== false,
    "Body recibido: {$res01['body']}"
);

// H404-04: journey GET remains 405
$res04 = execute_request_404('GET', '/api/v2/journeys/evaluate');
assert_h404('H404-04', 'GET /api/v2/journeys/evaluate preserva 405 Method Not Allowed con Allow: POST',
    $res04['code'] === 405 && isset($res04['headers']['allow']) && stripos($res04['headers']['allow'], 'POST') !== false,
    "Código: {$res04['code']}, Allow: " . ($res04['headers']['allow'] ?? 'none')
);

// H404-05: administrative evaluation GET remains 405
$res05 = execute_request_404('GET', '/api/v2/administrative-evaluations/evaluate');
assert_h404('H404-05', 'GET /api/v2/administrative-evaluations/evaluate preserva 405 con Allow: POST',
    $res05['code'] === 405 && isset($res05['headers']['allow']) && stripos($res05['headers']['allow'], 'POST') !== false,
    "Código: {$res05['code']}, Allow: " . ($res05['headers']['allow'] ?? 'none')
);

// H404-06: known POST route dispatch behavior unchanged
$res06 = execute_request_404('POST', '/acceso-empresa', ['Content-Type' => 'application/json'], '{}');
assert_h404('H404-06', 'Despacho de rutas POST conocidas (/acceso-empresa) permanece inalterado (no 404)',
    $res06['code'] === 200,
    "Código: {$res06['code']}"
);

// H404-07: unknown historical-prefix route returns 404
$res07v1 = execute_request_404('GET', '/portal-api-v.1.0.1/ruta-que-no-existe');
$res07v2 = execute_request_404('GET', '/portal-api-v.2.0/ruta-que-no-existe');
assert_h404('H404-07', 'Ruta desconocida bajo prefijo histórico (/portal-api-v.1.0.1 y v.2.0) retorna 404',
    $res07v1['code'] === 404 && $res07v2['code'] === 404,
    "v1 código: {$res07v1['code']}, v2 código: {$res07v2['code']}"
);

// H404-08: query string on unknown path does not change 404
$res08 = execute_request_404('GET', '/ruta-que-no-existe?modulo=rh&fecha=2026-10-15');
assert_h404('H404-08', 'Query string en ruta desconocida no altera retorno de HTTP 404',
    $res08['code'] === 404,
    "Código: {$res08['code']}"
);

// H404-09: trailing slash unknown path remains 404
$res09 = execute_request_404('GET', '/ruta-que-no-existe/');
assert_h404('H404-09', 'Trailing slash en ruta desconocida preserva retorno de HTTP 404',
    $res09['code'] === 404,
    "Código: {$res09['code']}"
);

// H404-10: no valid route returns 404 due to remediation
$res10a = execute_request_404('GET', '/api/v2/punch-events');
$res10b = execute_request_404('GET', '/api/v2/devices/context');
assert_h404('H404-10', 'Ninguna ruta válida retorna 404 debido a la remediación',
    $res10a['code'] !== 404 && $res10b['code'] !== 404 && in_array($res10a['code'], [401, 405], true) && $res10b['code'] === 401,
    "punch-events código: {$res10a['code']}, devices/context código: {$res10b['code']}"
);

echo "=== RESULTADOS: {$passed} PASS, {$failed} FAIL ===\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
