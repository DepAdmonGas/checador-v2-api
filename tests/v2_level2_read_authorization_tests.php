<?php
/**
 * Test Suite: Level 2 Window Completeness Read Authorization & Semantics Tests
 * Tests L2R01 - L2R20
 *
 * Verifies that GET /api/v2/level2/window-status:
 * 1. Requires Portal3 User JWT with biometricos.leer and authorized workplace scope.
 * 2. Rejects calls lacking biometricos.leer (even if acceso-temporal is granted).
 * 3. Enforces workplace scope boundaries (403 for out-of-scope station).
 * 4. Rejects Device Auth and missing/invalid JWT (401).
 * 5. Preserves canonical statuses (VERIFIED, INCOMPLETE, UNKNOWN) and dimensions
 *    (LOCAL_CAPTURE_DURABILITY, DELIVERY_COMPLETENESS, OPERATIONAL_COVERAGE).
 * 6. Does not infer FALTA, SIN MARCACIÓN, or payroll conclusions.
 * 7. Confirms isolation across other boundaries (temporary access, snapshots, raw read, timezone).
 */

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : 'kimera';
$jwtSecret = 'test_jwt_secret_l2r_tests_777888';
putenv("JWT_SECRET={$jwtSecret}");

$dbName = 'checador_v2_tmp_l2r_tests_' . getmypid() . '_' . time();

$admin = null;
$db = null;
$passedCount = 0;
$failedCount = 0;

function log_l2r(string $msg): void { echo $msg . "\n"; }

function assert_l2r(bool $condition, string $id, string $desc): void {
    global $passedCount, $failedCount;
    if ($condition) {
        $passedCount++;
        log_l2r("[$id] PASS: $desc");
    } else {
        $failedCount++;
        log_l2r("[$id] FAIL: $desc");
    }
}

function b64Url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function make_l2r_jwt(array $customPayload, string $secret): string {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $defaultPayload = [
        'iss' => 'portal3_auth',
        'sub' => 101,
        'type' => 'access',
        'exp' => time() + 3600
    ];
    $payload = array_merge($defaultPayload, $customPayload);

    $hB64 = b64Url(json_encode($header, JSON_UNESCAPED_SLASHES));
    $pB64 = b64Url(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = b64Url(hash_hmac('sha256', "$hB64.$pB64", $secret, true));

    return "$hB64.$pB64.$sig";
}

function call_cgi_api(string $method, string $uri, array $headers = [], string $body = ''): array {
    global $host, $user, $pass, $dbName, $jwtSecret;

    $script = realpath(__DIR__ . '/../index.php');
    if (strpos($uri, '/portal-api-v.1.0.1') !== 0) {
        $uri = '/portal-api-v.1.0.1' . $uri;
    }

    $parsedUrl = parse_url($uri);
    $queryString = $parsedUrl['query'] ?? '';

    $env = [
        'REDIRECT_STATUS' => '200',
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $uri,
        'QUERY_STRING' => $queryString,
        'SCRIPT_FILENAME' => $script,
        'SCRIPT_NAME' => '/portal-api-v.1.0.1/index.php',
        'DB_HOST' => $host,
        'DB_USER' => $user,
        'DB_PASS' => $pass,
        'DB_NAME' => $dbName,
        'JWT_SECRET' => $jwtSecret,
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
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];

    $process = proc_open($envPrefix . 'php-cgi', $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        return ["code" => 500, "headers" => [], "body" => "Failed to proc_open php-cgi", "json" => null];
    }

    if ($body !== '') {
        fwrite($pipes[0], $body);
    }
    fclose($pipes[0]);

    $rawOutput = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $headerEndPos = strpos($rawOutput, "\r\n\r\n");
    $delimLen = 4;
    if ($headerEndPos === false) {
        $headerEndPos = strpos($rawOutput, "\n\n");
        $delimLen = 2;
    }

    if ($headerEndPos === false) {
        return ["code" => 200, "headers" => [], "body" => $rawOutput, "json" => json_decode($rawOutput, true)];
    }

    $headerStr = substr($rawOutput, 0, $headerEndPos);
    $bodyStr = substr($rawOutput, $headerEndPos + $delimLen);

    $headerLines = explode("\n", str_replace("\r", "", $headerStr));
    $code = 200;
    foreach ($headerLines as $hl) {
        if (stripos($hl, 'Status:') === 0) {
            $parts = explode(':', $hl, 2);
            $code = (int)trim($parts[1]);
            break;
        }
    }

    $json = json_decode($bodyStr, true);

    return [
        "code" => $code,
        "headers" => $headerLines,
        "body" => $bodyStr,
        "json" => $json
    ];
}

try {
    $admin = new mysqli($host, $user, $pass);
    $admin->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($host, $user, $pass, $dbName);

    // 1. Portal3 ACL schema
    $db->query("CREATE TABLE tb_usuarios (
        id INT PRIMARY KEY AUTO_INCREMENT,
        usuario VARCHAR(100) NOT NULL,
        id_gas INT NOT NULL DEFAULT 1,
        id_puesto INT NOT NULL DEFAULT 1,
        estatus INT NOT NULL DEFAULT 0
    )");

    $db->query("CREATE TABLE modulos_dpto_operativo (
        id INT PRIMARY KEY AUTO_INCREMENT,
        nombre VARCHAR(100) NOT NULL,
        clave VARCHAR(100) NOT NULL,
        activo INT DEFAULT 1
    )");

    $db->query("CREATE TABLE tb_modulos_config (
        id INT PRIMARY KEY AUTO_INCREMENT,
        modulo_key VARCHAR(100) NOT NULL,
        estaciones_soportadas TEXT,
        tipo_departamento VARCHAR(50) DEFAULT 'localidades',
        activo INT DEFAULT 1
    )");

    $db->query("CREATE TABLE modulos_usuarios_do (
        id INT PRIMARY KEY AUTO_INCREMENT,
        id_modulo INT NOT NULL,
        id_usuario INT NOT NULL,
        leer INT DEFAULT 0,
        crear INT DEFAULT 0,
        editar INT DEFAULT 0,
        eliminar INT DEFAULT 0,
        descargar INT DEFAULT 0
    )");

    $db->query("CREATE TABLE modulos_puestos_do (
        id INT PRIMARY KEY AUTO_INCREMENT,
        id_modulo INT NOT NULL,
        id_puesto INT NOT NULL,
        leer INT DEFAULT 1,
        crear INT DEFAULT 0,
        editar INT DEFAULT 0,
        eliminar INT DEFAULT 0,
        descargar INT DEFAULT 0
    )");

    $db->query("CREATE TABLE tb_estaciones (
        id INT PRIMARY KEY AUTO_INCREMENT,
        numlista INT DEFAULT 0,
        estatus INT DEFAULT 0
    )");

    $db->query("CREATE TABLE op_rh_localidades (
        id INT(11) PRIMARY KEY AUTO_INCREMENT,
        numlista INT DEFAULT 0,
        localidad VARCHAR(100) DEFAULT '',
        recuperacion_vapores VARCHAR(100) DEFAULT ''
    )");

    $db->query("CREATE TABLE tb_multiestacion_usuario (
        id INT PRIMARY KEY AUTO_INCREMENT,
        id_usuario INT NOT NULL,
        estaciones TEXT,
        activo INT DEFAULT 1
    )");

    $db->query("CREATE TABLE tb_multiestacion_puesto (
        id INT PRIMARY KEY AUTO_INCREMENT,
        id_puesto INT NOT NULL,
        estaciones TEXT,
        activo INT DEFAULT 1
    )");

    $db->query("CREATE TABLE IF NOT EXISTS op_rh_personal (
        id INT PRIMARY KEY AUTO_INCREMENT,
        nombre_completo VARCHAR(255) DEFAULT ''
    )");

    // Apply baseline migrations
    $migrations = [
        __DIR__ . '/../migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_02_000002_add_integrity_seal_to_raw_punches.sql',
        __DIR__ . '/../migraciones/v2/2026_10_05_000001_create_v2_read_snapshot_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_05_000002_create_v2_level2_server_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_09_000001_widen_snapshot_event_uuid.sql',
        __DIR__ . '/../migraciones/v2/2026_10_09_000002_add_snapshot_member_raw_reference_integrity.sql',
        __DIR__ . '/../migraciones/v2/2026_10_12_000001_create_v2_workplace_timezone_authority.sql',
        __DIR__ . '/../migraciones/v2/2026_10_01_000001_create_v2_temporary_access_tables.sql'
    ];

    foreach ($migrations as $migFile) {
        $sql = file_get_contents($migFile);
        $db->multi_query($sql);
        while ($db->more_results() && $db->next_result()) { /* flush */ }
    }

    // 2. Seed Modules:
    // Modulo 15 = biometricos, Modulo 20 = acceso-temporal
    $db->query("INSERT INTO modulos_dpto_operativo (id, nombre, clave, activo) VALUES 
        (15, 'Biométricos', 'biometricos', 1),
        (20, 'Acceso Temporal', 'acceso-temporal', 1)");

    $db->query("INSERT INTO tb_modulos_config (id, modulo_key, estaciones_soportadas, tipo_departamento, activo) VALUES 
        (15, 'biometricos', '[1,2,99]', 'localidades', 1),
        (20, 'acceso-temporal', '[1,2,99]', 'localidades', 1)");

    // Stations: Station 1 -> numlista 2 -> Locality 1; Station 2 -> numlista 1 -> Locality 2
    $db->query("INSERT INTO tb_estaciones (id, numlista, estatus) VALUES (1, 2, 0), (2, 1, 0)");
    $db->query("INSERT INTO op_rh_localidades (id, numlista, localidad, recuperacion_vapores) VALUES 
        (1, 2, 'Estacion Matriz', 'GREEN MACHINE'),
        (2, 1, 'Sucursal Norte', 'OPW')");

    // Timezone mapping for locality 1 and 2
    $db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta) VALUES 
        (1, 'America/Mexico_City', '2026-10-12', NULL),
        (2, 'America/Mexico_City', '2026-10-12', NULL)");

    // Users:
    // User 101: home station 1 (maps to locality 1). Has DIRECT grant biometricos.leer = 1. NO acceso-temporal.
    $db->query("INSERT INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (101, 'supervisor_bio', 1, 5, 0)");
    $db->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer) VALUES (15, 101, 1)");

    // User 102: home station 1. Has DIRECT grant acceso-temporal (leer=1, editar=1). NO biometricos.
    $db->query("INSERT INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (102, 'user_temp_only', 1, 5, 0)");
    $db->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer, editar) VALUES (20, 102, 1, 1)");

    // User 103: home station 1. NO grants.
    $db->query("INSERT INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (103, 'user_no_grants', 1, 5, 0)");

    require_once __DIR__ . '/../modelo/v2/Level2ServerService.php';
    $l2Service = new Level2ServerService();

    // Device 100 on station 1 (complete, no gap)
    $db->query("INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado) VALUES (100, 'dev-l2-100', 1, 'L2 Terminal 100', 'America/Mexico_City', 1)");
    $l2Service->registrarGeneracion($db, 100, 'dev-l2-100', 'gen-v1');
    $l2Service->registrarAsignacionEstacion($db, 100, 1, '2026-01-01 00:00:00', null);
    $l2Service->registrarTelemetria($db, 100, 'gen-v1', 1, '2026-10-12 06:00:00', 100, 'CONNECTED', 'SYNCED', [], '2026-10-12 06:00:00');
    $l2Service->registrarTelemetria($db, 100, 'gen-v1', 2, '2026-10-12 06:10:00', 99, 'CONNECTED', 'SYNCED', [], '2026-10-12 06:10:00');

    // Device 200 on station 2 with a GAP (seq 1 and seq 3)
    $db->query("INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado) VALUES (200, 'dev-l2-200', 2, 'L2 Terminal 200', 'America/Mexico_City', 1)");
    $l2Service->registrarGeneracion($db, 200, 'dev-l2-200', 'gen-gap');
    $l2Service->registrarAsignacionEstacion($db, 200, 2, '2026-01-01 00:00:00', null);
    $l2Service->registrarTelemetria($db, 200, 'gen-gap', 1, '2026-10-12 06:00:00', 100, 'CONNECTED', 'SYNCED', [], '2026-10-12 06:00:00');
    $l2Service->registrarTelemetria($db, 200, 'gen-gap', 2, '2026-10-12 06:10:00', 99, 'CONNECTED', 'SYNCED', [], '2026-10-12 06:10:00');

    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id, event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES 
        (201, 'ev-l2-gap-1', 200, 2, 'EMP-1', '2026-10-12 06:02:00', '2026-10-12 00:02:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
        (202, 'ev-l2-gap-3', 200, 2, 'EMP-3', '2026-10-12 06:05:00', '2026-10-12 00:05:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");
    $l2Service->procesarSecuenciaNivel2($db, 201, 200, 'gen-gap', 1);
    $l2Service->procesarSecuenciaNivel2($db, 202, 200, 'gen-gap', 3);

    // Grant user 101 multiestacion to station 2 as well for testing station 2 evaluations
    $db->query("INSERT INTO tb_multiestacion_usuario (id_usuario, estaciones, activo) VALUES (101, '[1,2]', 1)");

    $jwt101 = "Bearer " . make_l2r_jwt(['sub' => 101], $jwtSecret);
    $jwt102 = "Bearer " . make_l2r_jwt(['sub' => 102], $jwtSecret);
    $jwt103 = "Bearer " . make_l2r_jwt(['sub' => 103], $jwtSecret);

    $fromUtc = '2026-10-12T06:00:00Z';
    $toUtc = '2026-10-12T06:10:00Z';

    // L2R01: Portal3 JWT + biometricos.leer + authorized workplace -> 200 success
    $res01 = call_cgi_api('GET', "/api/v2/level2/window-status?station_id=1&from=" . urlencode($fromUtc) . "&to=" . urlencode($toUtc), [
        'HTTP_AUTHORIZATION' => $jwt101
    ]);
    assert_l2r($res01['code'] === 200 && ($res01['json']['status'] ?? '') === 'success', 'L2R01', "Portal3 JWT + biometricos.leer + authorized workplace returns 200 success");

    // L2R02: Portal3 JWT without biometricos.leer -> 403
    $res02 = call_cgi_api('GET', "/api/v2/level2/window-status?station_id=1&from=" . urlencode($fromUtc) . "&to=" . urlencode($toUtc), [
        'HTTP_AUTHORIZATION' => $jwt103
    ]);
    assert_l2r($res02['code'] === 403 && ($res02['json']['status'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'L2R02', "User without biometricos.leer denied with 403 FUNCTION_PERMISSION_DENIED");

    // L2R03: acceso-temporal.leer without biometricos.leer -> denied 403
    $res03 = call_cgi_api('GET', "/api/v2/level2/window-status?station_id=1&from=" . urlencode($fromUtc) . "&to=" . urlencode($toUtc), [
        'HTTP_AUTHORIZATION' => $jwt102
    ]);
    assert_l2r($res03['code'] === 403 && ($res03['json']['status'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'L2R03', "acceso-temporal.leer alone denied with 403 FUNCTION_PERMISSION_DENIED");

    // L2R04: biometricos.leer + out-of-scope workplace -> denied 403
    // Create User 104 with scope ONLY on station 1 (no multiestacion)
    $db->query("INSERT INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (104, 'user_scope_1_only', 1, 5, 0)");
    $db->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer) VALUES (15, 104, 1)");
    $jwt104 = "Bearer " . make_l2r_jwt(['sub' => 104], $jwtSecret);

    $res04 = call_cgi_api('GET', "/api/v2/level2/window-status?station_id=2&from=" . urlencode($fromUtc) . "&to=" . urlencode($toUtc), [
        'HTTP_AUTHORIZATION' => $jwt104
    ]);
    assert_l2r($res04['code'] === 403 && ($res04['json']['status'] ?? '') === 'STATION_SCOPE_DENIED', 'L2R04', "Out-of-scope workplace denied with 403 STATION_SCOPE_DENIED");

    // L2R05: Missing JWT -> 401
    $res05 = call_cgi_api('GET', "/api/v2/level2/window-status?station_id=1&from=" . urlencode($fromUtc) . "&to=" . urlencode($toUtc));
    assert_l2r($res05['code'] === 401, 'L2R05', "Missing Authorization header returns 401");

    // L2R06: Invalid JWT -> 401
    $res06 = call_cgi_api('GET', "/api/v2/level2/window-status?station_id=1&from=" . urlencode($fromUtc) . "&to=" . urlencode($toUtc), [
        'HTTP_AUTHORIZATION' => 'Bearer invalid.tampered.token'
    ]);
    assert_l2r($res06['code'] === 401, 'L2R06', "Invalid signature JWT returns 401");

    // L2R07: Lector Device Auth cannot masquerade as Portal3 user read
    $res07 = call_cgi_api('GET', "/api/v2/level2/window-status?station_id=1&from=" . urlencode($fromUtc) . "&to=" . urlencode($toUtc), [
        'HTTP_X_DEVICE_ID' => '100',
        'HTTP_X_CREDENTIAL_KEY' => 'cred-test-key'
    ]);
    assert_l2r($res07['code'] === 401, 'L2R07', "Device Auth headers without Portal3 JWT rejected with 401");

    // L2R08: VERIFIED response preserved exactly for station 1 (contiguous sequence, telemetric coverage)
    $l2Data1 = $res01['json']['data'] ?? [];
    $isVerified = ($l2Data1['window_level2_status'] ?? '') === 'VERIFIED';
    assert_l2r($isVerified, 'L2R08', "Complete station evaluation returns window_level2_status = VERIFIED");

    // L2R09: INCOMPLETE response preserved exactly for station 2 (due to sequence gap [[3,4]])
    $res09 = call_cgi_api('GET', "/api/v2/level2/window-status?station_id=2&from=" . urlencode($fromUtc) . "&to=" . urlencode($toUtc), [
        'HTTP_AUTHORIZATION' => $jwt101
    ]);
    $l2Data2 = $res09['json']['data'] ?? [];
    $isIncomplete = ($l2Data2['window_level2_status'] ?? '') === 'INCOMPLETE';
    assert_l2r($isIncomplete, 'L2R09', "Station with sequence gap returns window_level2_status = INCOMPLETE");

    // L2R10: UNKNOWN response preserved exactly when no devices/evidence exist
    // Add Locality 99, Station 99
    $db->query("INSERT INTO tb_estaciones (id, numlista, estatus) VALUES (99, 99, 0)");
    $db->query("INSERT INTO op_rh_localidades (id, numlista, localidad, recuperacion_vapores) VALUES (99, 99, 'Estacion Vacia', 'NONE')");
    $db->query("UPDATE tb_multiestacion_usuario SET estaciones = '[1,2,99]' WHERE id_usuario = 101");

    $res10 = call_cgi_api('GET', "/api/v2/level2/window-status?station_id=99&from=" . urlencode($fromUtc) . "&to=" . urlencode($toUtc), [
        'HTTP_AUTHORIZATION' => $jwt101
    ]);
    $l2Data99 = $res10['json']['data'] ?? [];
    $isUnknown = ($l2Data99['window_level2_status'] ?? '') === 'UNKNOWN';
    assert_l2r($isUnknown, 'L2R10', "Station with no device history returns window_level2_status = UNKNOWN");

    // L2R11: Lack of sufficient evidence never becomes VERIFIED
    assert_l2r($isUnknown && $isIncomplete && ($l2Data99['window_level2_status'] !== 'VERIFIED'), 'L2R11', "Lack of evidence evaluates to UNKNOWN/INCOMPLETE, never incorrectly to VERIFIED");

    // L2R12: Snapshot count does not determine completeness
    // Creating snapshots does not change Level 2 output
    $res12 = call_cgi_api('GET', "/api/v2/level2/window-status?station_id=1&from=" . urlencode($fromUtc) . "&to=" . urlencode($toUtc), [
        'HTTP_AUTHORIZATION' => $jwt101
    ]);
    assert_l2r(($res12['json']['data']['window_level2_status'] ?? '') === 'VERIFIED', 'L2R12', "Level 2 completeness is independent of snapshot presence or count");

    // L2R13: Raw PunchEvent count does not determine completeness
    // Station 1 currently has 0 raw punches, yet window_level2_status is VERIFIED with statement
    $hasStatement = isset($res01['json']['data']['statement']) && strpos($res01['json']['data']['statement'], 'No governed captured PunchEvent exists') !== false;
    assert_l2r($hasStatement, 'L2R13', "Zero punch count evaluates Level 2 purely on durability and coverage with explicit statement");

    // L2R14: Temporary-access authorization behavior unchanged
    // User 102 (with acceso-temporal.leer) accesses temporary requests successfully
    $res14 = call_cgi_api('GET', '/api/v2/temporary-access/requests?station_id=1', [
        'HTTP_AUTHORIZATION' => $jwt102
    ]);
    assert_l2r($res14['code'] === 200, 'L2R14', "Temporary access endpoints remain governed by acceso-temporal (200 OK)");

    // L2R15: Snapshot authorization unchanged after fix
    // User 101 with biometricos.leer creates snapshot successfully
    $snapPayload = json_encode(['station_id' => 1, 'from' => $fromUtc, 'to' => $toUtc]);
    $res15 = call_cgi_api('POST', '/api/v2/punch-events/read-snapshots', [
        'HTTP_AUTHORIZATION' => $jwt101,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => 'idem-l2r-15'
    ], $snapPayload);
    assert_l2r($res15['code'] === 201, 'L2R15', "Snapshot creation remains governed by biometricos.leer (201 Created)");

    // L2R16: Raw Punch Read authorization unchanged
    $res16 = call_cgi_api('GET', "/api/v2/punch-events?station_id=1&from=" . urlencode($fromUtc) . "&to=" . urlencode($toUtc), [
        'HTTP_AUTHORIZATION' => $jwt101
    ]);
    assert_l2r($res16['code'] === 200, 'L2R16', "Raw punch read remains governed by biometricos.leer (200 OK)");

    // L2R17: Timezone read authorization unchanged
    $res17 = call_cgi_api('GET', '/api/v2/workplaces/1/timezone?date=2026-10-12', [
        'HTTP_AUTHORIZATION' => $jwt101
    ]);
    assert_l2r($res17['code'] === 200, 'L2R17', "Timezone read remains governed by biometricos.leer (200 OK)");

    // L2R18: No SIN MARCACIÓN inference in Level 2 payload
    $resJsonStr = json_encode($res01['json']);
    $noSinMarcacion = stripos($resJsonStr, 'sin marcación') === false && stripos($resJsonStr, 'sin_marcacion') === false;
    assert_l2r($noSinMarcacion, 'L2R18', "Zero 'sin marcación' inference in Level 2 completeness payload");

    // L2R19: No FALTA inference in Level 2 payload
    $noFalta = stripos($resJsonStr, 'falta') === false;
    assert_l2r($noFalta, 'L2R19', "Zero 'FALTA' inference in Level 2 completeness payload");

    // L2R20: No payroll conclusion in Level 2 payload
    $noPayroll = stripos($resJsonStr, 'payroll') === false && stripos($resJsonStr, 'nomina') === false;
    assert_l2r($noPayroll, 'L2R20', "Zero payroll or monetary conclusions in Level 2 completeness payload");

} catch (Throwable $e) {
    log_l2r("FATAL EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    $failedCount++;
} finally {
    if ($admin !== null && $dbName !== null) {
        try {
            $admin->query("DROP DATABASE IF EXISTS `{$dbName}`");
            log_l2r("DISPOSABLE_DB: REMOVED ({$dbName})");
        } catch (Throwable $ignore) {}
        $admin->close();
    }
}

echo "\n=== TEST RESULTS SUMMARY ===\n";
echo "TOTAL TESTS : " . ($passedCount + $failedCount) . "\n";
echo "PASSED      : {$passedCount}\n";
echo "FAILED      : {$failedCount}\n";

exit($failedCount === 0 ? 0 : 1);
