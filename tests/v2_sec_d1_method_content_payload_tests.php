<?php
/**
 * Test Suite: SEC-D1 HTTP Method, Content-Type, and Payload Validation Hardening
 * Tests D101 through D120.
 */

declare(strict_types=1);

putenv("DB_NAME=bd_admongas_v2_test_20261002");
putenv("DB_HOST=127.0.0.1");
putenv("DB_USER=root");
putenv("DB_PASS=kimera");

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/Encriptar.php';

// Seed test device DEV-ZK-EST01-001 and credential CRD-KEY-2026-A if missing
$bd = new BD();
$conn = $bd->conectarBD();
$checkDev = $conn->query("SELECT id FROM op_rh_v2_dispositivos WHERE uuid_dispositivo = 'DEV-ZK-EST01-001' LIMIT 1");
if ($checkDev && $checkDev->num_rows === 0) {
    $conn->query("INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado, creado_at) VALUES (9502, 'DEV-ZK-EST01-001', 1, 'Lector Test SEC-D1', 'America/Mexico_City', 1, NOW())");
    $hash = password_hash('SEC_SUPER_SECRET_98765', PASSWORD_BCRYPT);
    $conn->query("INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, estado, valido_desde, valido_hasta, creado_at) VALUES (9502, 'CRD-KEY-2026-A', '{$hash}', 'ACTIVE', '2026-01-01 00:00:00', '2099-01-01 00:00:00', NOW())");
} else {
    // Ensure credential exists and is ACTIVE
    $devRow = $checkDev->fetch_assoc();
    $devId = (int)$devRow['id'];
    $conn->query("DELETE FROM op_rh_v2_dispositivo_credenciales WHERE id_dispositivo = {$devId} AND credential_key = 'CRD-KEY-2026-A'");
    $hash = password_hash('SEC_SUPER_SECRET_98765', PASSWORD_BCRYPT);
    $conn->query("INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, estado, valido_desde, valido_hasta, creado_at) VALUES ({$devId}, 'CRD-KEY-2026-A', '{$hash}', 'ACTIVE', '2026-01-01 00:00:00', '2099-01-01 00:00:00', NOW())");
}
$bd->desconectarBD($conn);

$testCount = 0;
$passCount = 0;
$failCount = 0;

function run_sec_d1_test(string $testId, string $description, callable $fn) {
    global $testCount, $passCount, $failCount;
    $testCount++;
    try {
        $result = $fn();
        if ($result === true) {
            $passCount++;
            echo "[$testId] PASS: $description\n";
        } else {
            $failCount++;
            echo "[$testId] FAIL: $description - Returned: " . var_export($result, true) . "\n";
        }
    } catch (\Throwable $e) {
        $failCount++;
        echo "[$testId] FAIL (Exception): $description - " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

// Helper to invoke index.php via php-cgi to test routing, headers, status codes and bodies cleanly
function make_request(string $method, string $uri, array $headers = [], string $body = ''): array {
    $script = realpath(__DIR__ . '/../index.php');
    if (strpos($uri, '/portal-api-v.1.0.1') !== 0) {
        $uri = '/portal-api-v.1.0.1' . $uri;
    }

    $env = [
        'REDIRECT_STATUS' => '200',
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $uri,
        'SCRIPT_FILENAME' => $script,
        'SCRIPT_NAME' => '/portal-api-v.1.0.1/index.php',
        'DB_HOST' => '127.0.0.1',
        'DB_USER' => 'root',
        'DB_PASS' => 'kimera',
        'DB_NAME' => 'bd_admongas_v2_test_20261002',
    ];

    if ($body !== '') {
        $env['CONTENT_LENGTH'] = (string)strlen($body);
    }

    foreach ($headers as $k => $v) {
        $env[$k] = (string)$v;
    }

    $envPrefix = '';
    foreach ($env as $k => $v) {
        $envPrefix .= $k . '=' . escapeshellarg($v) . ' ';
    }

    $descriptorSpec = [
        0 => ["pipe", "r"], // stdin
        1 => ["pipe", "w"], // stdout
        2 => ["pipe", "w"]  // stderr
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

    // Split headers and body (separated by \r\n\r\n or \n\n)
    $headerEndPos = strpos($rawOutput, "\r\n\r\n");
    $delimLen = 4;
    if ($headerEndPos === false) {
        $headerEndPos = strpos($rawOutput, "\n\n");
        $delimLen = 2;
    }

    if ($headerEndPos === false) {
        return ["code" => 200, "headers" => [], "body" => $rawOutput];
    }

    $headerStr = substr($rawOutput, 0, $headerEndPos);
    $bodyStr = substr($rawOutput, $headerEndPos + $delimLen);

    $headerLines = explode("\n", str_replace("\r", "", $headerStr));
    $code = 200;
    foreach ($headerLines as $hl) {
        if (stripos($hl, 'Status:') === 0) {
            $parts = explode(' ', trim($hl), 3);
            if (isset($parts[1]) && is_numeric($parts[1])) {
                $code = (int)$parts[1];
            }
        }
    }

    return [
        "code" => $code,
        "headers" => $headerLines,
        "body" => $bodyStr
    ];
}

function get_header_value(array $headers, string $headerName): ?string {
    $headerNameLower = strtolower($headerName);
    foreach ($headers as $h) {
        $parts = explode(':', $h, 2);
        if (strtolower(trim($parts[0])) === $headerNameLower) {
            return trim($parts[1] ?? '');
        }
    }
    return null;
}

echo "=== SEC-D1 TEST SUITE STARTING ===\n\n";

// --- D101 - D105: HTTP Method Enforcement (405 + Allow Header) ---

// D101: PUT /api/v2/punch-events returns 405 with Allow: POST, GET
run_sec_d1_test('D101', 'PUT /api/v2/punch-events fails closed with 405 and Allow: POST, GET', function() {
    $res = make_request('PUT', '/api/v2/punch-events');
    $allow = get_header_value($res['headers'], 'Allow');
    return $res['code'] === 405 && $allow !== null && strpos($allow, 'POST') !== false && strpos($allow, 'GET') !== false;
});

// D102: DELETE /api/v2/punch-events returns 405 with Allow: POST, GET
run_sec_d1_test('D102', 'DELETE /api/v2/punch-events fails closed with 405 and Allow: POST, GET', function() {
    $res = make_request('DELETE', '/api/v2/punch-events');
    $allow = get_header_value($res['headers'], 'Allow');
    return $res['code'] === 405 && $allow !== null && strpos($allow, 'POST') !== false;
});

// D103: GET /api/v2/devices/generations returns 405 with Allow: POST
run_sec_d1_test('D103', 'GET /api/v2/devices/generations returns 405 with Allow: POST', function() {
    $res = make_request('GET', '/api/v2/devices/generations');
    $allow = get_header_value($res['headers'], 'Allow');
    return $res['code'] === 405 && $allow === 'POST';
});

// D104: GET /api/v2/devices/telemetry returns 405 with Allow: POST
run_sec_d1_test('D104', 'GET /api/v2/devices/telemetry returns 405 with Allow: POST', function() {
    $res = make_request('GET', '/api/v2/devices/telemetry');
    $allow = get_header_value($res['headers'], 'Allow');
    return $res['code'] === 405 && $allow === 'POST';
});

// D105: POST /api/v2/devices/context returns 405 with Allow: GET
run_sec_d1_test('D105', 'POST /api/v2/devices/context returns 405 with Allow: GET', function() {
    $res = make_request('POST', '/api/v2/devices/context');
    $allow = get_header_value($res['headers'], 'Allow');
    return $res['code'] === 405 && $allow === 'GET';
});

// --- D106 - D110: Additional 405 Method Guards ---

// D106: POST /api/v2/level2/window-status returns 405 with Allow: GET
run_sec_d1_test('D106', 'POST /api/v2/level2/window-status returns 405 with Allow: GET', function() {
    $res = make_request('POST', '/api/v2/level2/window-status');
    $allow = get_header_value($res['headers'], 'Allow');
    return $res['code'] === 405 && $allow === 'GET';
});

// D107: PUT /api/v2/temporary-access/requests returns 405 with Allow: POST, GET
run_sec_d1_test('D107', 'PUT /api/v2/temporary-access/requests returns 405 with Allow: POST, GET', function() {
    $res = make_request('PUT', '/api/v2/temporary-access/requests');
    $allow = get_header_value($res['headers'], 'Allow');
    return $res['code'] === 405 && $allow !== null && strpos($allow, 'POST') !== false && strpos($allow, 'GET') !== false;
});

// D108: GET /api/v2/temporary-access/validate returns 405 with Allow: POST
run_sec_d1_test('D108', 'GET /api/v2/temporary-access/validate returns 405 with Allow: POST', function() {
    $res = make_request('GET', '/api/v2/temporary-access/validate');
    $allow = get_header_value($res['headers'], 'Allow');
    return $res['code'] === 405 && $allow === 'POST';
});

// D109: DELETE /api/v2/temporary-access/requests/{uuid}/authorize returns 405 with Allow: POST
run_sec_d1_test('D109', 'DELETE /api/v2/temporary-access/requests/any-uuid/authorize returns 405 with Allow: POST', function() {
    $res = make_request('DELETE', '/api/v2/temporary-access/requests/any-uuid/authorize');
    $allow = get_header_value($res['headers'], 'Allow');
    return $res['code'] === 405 && $allow === 'POST';
});

// D110: GET /api/v2/punch-events/read-snapshots returns 405 with Allow: POST
run_sec_d1_test('D110', 'GET /api/v2/punch-events/read-snapshots returns 405 with Allow: POST', function() {
    $res = make_request('GET', '/api/v2/punch-events/read-snapshots');
    $allow = get_header_value($res['headers'], 'Allow');
    return $res['code'] === 405 && $allow === 'POST';
});

// --- D111 - D115: Content-Type Validation ---

// D111: POST /api/v2/punch-events without Content-Type application/json fails closed with 415
run_sec_d1_test('D111', 'POST /api/v2/punch-events with text/plain fails closed with 415', function() {
    // Need valid auth headers so it reaches content-type check
    $headers = [
        'HTTP_X_DEVICE_ID' => 'DEV-ZK-EST01-001',
        'HTTP_X_CREDENTIAL_KEY' => 'CRD-KEY-2026-A',
        'HTTP_AUTHORIZATION' => 'Bearer SEC_SUPER_SECRET_98765',
        'CONTENT_TYPE' => 'text/plain'
    ];
    $res = make_request('POST', '/api/v2/punch-events', $headers, '{"event_uuid":"test"}');
    return $res['code'] === 415;
});

// D112: POST /api/v2/devices/generations with text/plain returns 415
run_sec_d1_test('D112', 'POST /api/v2/devices/generations with text/plain returns 415', function() {
    $headers = [
        'HTTP_X_DEVICE_ID' => 'DEV-ZK-EST01-001',
        'HTTP_X_CREDENTIAL_KEY' => 'CRD-KEY-2026-A',
        'HTTP_AUTHORIZATION' => 'Bearer SEC_SUPER_SECRET_98765',
        'CONTENT_TYPE' => 'text/plain'
    ];
    $res = make_request('POST', '/api/v2/devices/generations', $headers, '{"device_uuid":"dev"}');
    return $res['code'] === 415;
});

// D113: POST /api/v2/devices/telemetry with text/html returns 415
run_sec_d1_test('D113', 'POST /api/v2/devices/telemetry with text/html returns 415', function() {
    $headers = [
        'HTTP_X_DEVICE_ID' => 'DEV-ZK-EST01-001',
        'HTTP_X_CREDENTIAL_KEY' => 'CRD-KEY-2026-A',
        'HTTP_AUTHORIZATION' => 'Bearer SEC_SUPER_SECRET_98765',
        'CONTENT_TYPE' => 'text/html'
    ];
    $res = make_request('POST', '/api/v2/devices/telemetry', $headers, '{"device_generation":"gen"}');
    return $res['code'] === 415;
});

// D114: POST /api/v2/temporary-access/requests with text/xml returns 415
run_sec_d1_test('D114', 'POST /api/v2/temporary-access/requests with text/xml returns 415', function() {
    $headers = [
        'HTTP_X_DEVICE_ID' => 'DEV-ZK-EST01-001',
        'HTTP_X_CREDENTIAL_KEY' => 'CRD-KEY-2026-A',
        'HTTP_AUTHORIZATION' => 'Bearer SEC_SUPER_SECRET_98765',
        'CONTENT_TYPE' => 'text/xml'
    ];
    $res = make_request('POST', '/api/v2/temporary-access/requests', $headers, '<xml/>');
    return $res['code'] === 415;
});

// D115: Dual-stack /acceso-empresa continues to accept both application/json and application/x-www-form-urlencoded
run_sec_d1_test('D115', 'POST /acceso-empresa accepts application/json and application/x-www-form-urlencoded', function() {
    // 1. application/json
    $resJson = make_request('POST', '/acceso-empresa', ['CONTENT_TYPE' => 'application/json'], json_encode(['usuario' => 'nonexistent', 'password' => 'wrong']));
    // 2. application/x-www-form-urlencoded
    $resForm = make_request('POST', '/acceso-empresa', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'usuario=nonexistent&password=wrong');
    // Neither should return 415
    return $resJson['code'] !== 415 && $resForm['code'] !== 415;
});

// --- D116 - D120: Payload Contract Length Validation ---

// D116: event_uuid > 64 chars in /api/v2/punch-events is rejected with 400
run_sec_d1_test('D116', 'event_uuid > 64 characters is rejected with 400', function() {
    $headers = [
        'HTTP_X_DEVICE_ID' => 'DEV-ZK-EST01-001',
        'HTTP_X_CREDENTIAL_KEY' => 'CRD-KEY-2026-A',
        'HTTP_AUTHORIZATION' => 'Bearer SEC_SUPER_SECRET_98765',
        'CONTENT_TYPE' => 'application/json'
    ];
    $oversizedUuid = str_repeat('a', 65);
    $payload = json_encode([
        'event_uuid' => $oversizedUuid,
        'external_worker_reference' => '101',
        'source_timestamp' => '2026-10-12 12:00:00'
    ]);
    $res = make_request('POST', '/api/v2/punch-events', $headers, $payload);
    return $res['code'] === 400;
});

// D117: external_worker_reference > 64 chars in /api/v2/punch-events is rejected with 400
run_sec_d1_test('D117', 'external_worker_reference > 64 characters is rejected with 400', function() {
    $headers = [
        'HTTP_X_DEVICE_ID' => 'DEV-ZK-EST01-001',
        'HTTP_X_CREDENTIAL_KEY' => 'CRD-KEY-2026-A',
        'HTTP_AUTHORIZATION' => 'Bearer SEC_SUPER_SECRET_98765',
        'CONTENT_TYPE' => 'application/json'
    ];
    $oversizedRef = str_repeat('b', 65);
    $payload = json_encode([
        'event_uuid' => '550e8400-e29b-41d4-a716-446655440001',
        'external_worker_reference' => $oversizedRef,
        'source_timestamp' => '2026-10-12 12:00:00'
    ]);
    $res = make_request('POST', '/api/v2/punch-events', $headers, $payload);
    return $res['code'] === 400;
});

// D118: device_generation > 64 chars in /api/v2/devices/generations is rejected with 400
run_sec_d1_test('D118', 'device_generation > 64 characters in generations is rejected with 400', function() {
    $headers = [
        'HTTP_X_DEVICE_ID' => 'DEV-ZK-EST01-001',
        'HTTP_X_CREDENTIAL_KEY' => 'CRD-KEY-2026-A',
        'HTTP_AUTHORIZATION' => 'Bearer SEC_SUPER_SECRET_98765',
        'CONTENT_TYPE' => 'application/json'
    ];
    $oversizedGen = str_repeat('g', 65);
    $payload = json_encode([
        'device_uuid' => 'DEV-ZK-EST01-001',
        'device_generation' => $oversizedGen
    ]);
    $res = make_request('POST', '/api/v2/devices/generations', $headers, $payload);
    return $res['code'] === 400;
});

// D119: network_status > 32 chars in /api/v2/devices/telemetry is rejected with 400
run_sec_d1_test('D119', 'network_status > 32 characters in telemetry is rejected with 400', function() {
    $headers = [
        'HTTP_X_DEVICE_ID' => 'DEV-ZK-EST01-001',
        'HTTP_X_CREDENTIAL_KEY' => 'CRD-KEY-2026-A',
        'HTTP_AUTHORIZATION' => 'Bearer SEC_SUPER_SECRET_98765',
        'CONTENT_TYPE' => 'application/json'
    ];
    $oversizedNet = str_repeat('n', 33);
    $payload = json_encode([
        'device_generation' => 'GEN-1',
        'telemetry_sequence' => 1,
        'observation_timestamp' => '2026-10-12 12:00:00',
        'network_status' => $oversizedNet
    ]);
    $res = make_request('POST', '/api/v2/devices/telemetry', $headers, $payload);
    return $res['code'] === 400;
});

// D120: legacy routes return 405 with Allow header (e.g. POST /personal returns 405 Allow: GET)
run_sec_d1_test('D120', 'POST /personal fails closed with 405 and Allow: GET', function() {
    $res = make_request('POST', '/personal');
    $allow = get_header_value($res['headers'], 'Allow');
    return $res['code'] === 405 && $allow === 'GET';
});

echo "\n=== SEC-D1 TEST SUITE SUMMARY ===\n";
echo "Total Tests: $testCount\n";
echo "Passed: $passCount\n";
echo "Failed: $failCount\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
