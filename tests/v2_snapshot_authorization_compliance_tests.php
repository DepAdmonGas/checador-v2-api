<?php
/**
 * Test Suite: Level 1 Snapshot Authorization Contract Compliance Tests
 * Tests SRA01 - SRA16
 *
 * Verifies that POST /api/v2/punch-events/read-snapshots and
 * GET /api/v2/punch-events/read-snapshots/{snapshot_token}/events
 * strictly enforce the biometricos.leer + workplace scope authorization model,
 * and that acceso-temporal does NOT grant snapshot access while retaining its
 * own isolated scope for temporary access endpoints.
 */

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : 'kimera';
$jwtSecret = 'test_jwt_secret_sra_tests_12345';
putenv("JWT_SECRET={$jwtSecret}");

$dbName = 'checador_v2_tmp_sra_tests_' . getmypid() . '_' . time();

$admin = null;
$db = null;
$passedCount = 0;
$failedCount = 0;

function log_sra(string $msg): void { echo $msg . "\n"; }

function assert_sra(bool $condition, string $id, string $desc): void {
    global $passedCount, $failedCount;
    if ($condition) {
        $passedCount++;
        log_sra("[$id] PASS: $desc");
    } else {
        $failedCount++;
        log_sra("[$id] FAIL: $desc");
    }
}

function b64Url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function make_sra_jwt(array $customPayload, string $secret): string {
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
            $parts = explode(' ', trim($hl), 3);
            if (isset($parts[1]) && is_numeric($parts[1])) {
                $code = (int)$parts[1];
            }
        }
    }

    return [
        "code" => $code,
        "headers" => $headerLines,
        "body" => $bodyStr,
        "json" => json_decode($bodyStr, true)
    ];
}

try {
    log_sra("=== CHECADOR V2 SNAPSHOT READ AUTHORIZATION SUITE (SRA01-SRA16) ===");
    log_sra("TEST DATABASE: {$dbName}");

    $admin = new mysqli($host, $user, $pass);
    $admin->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($host, $user, $pass, $dbName);

    // 1. Base Tables
    $db->query("CREATE TABLE modulos_dpto_operativo (
        id INT PRIMARY KEY AUTO_INCREMENT,
        nombre VARCHAR(255) NOT NULL,
        clave VARCHAR(100) NOT NULL,
        activo INT DEFAULT 1
    )");

    $db->query("CREATE TABLE tb_modulos_config (
        id INT PRIMARY KEY AUTO_INCREMENT,
        modulo_key VARCHAR(100) NOT NULL,
        tipo VARCHAR(50) DEFAULT 'stations_only',
        estaciones_soportadas LONGTEXT,
        tipo_departamento VARCHAR(50) DEFAULT 'localidades',
        allow_all INT DEFAULT 0,
        activo INT DEFAULT 1
    )");

    $db->query("CREATE TABLE tb_usuarios (
        id INT PRIMARY KEY AUTO_INCREMENT,
        usuario VARCHAR(100) NOT NULL,
        id_gas INT,
        id_puesto INT,
        estatus INT DEFAULT 0
    )");

    $db->query("CREATE TABLE modulos_usuarios_do (
        id INT PRIMARY KEY AUTO_INCREMENT,
        id_modulo INT NOT NULL,
        id_usuario INT NOT NULL,
        leer INT DEFAULT 1,
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

    // Personnel table
    $db->query("CREATE TABLE IF NOT EXISTS op_rh_personal (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre_completo VARCHAR(255) DEFAULT '',
        id_estacion INT DEFAULT 0,
        estatus INT DEFAULT 1
    )");

    // Raw punch tables migration (creates dispositivos, credenciales, identidades, marcaciones_crudas)
    $sqlRawTables = file_get_contents(__DIR__ . '/../migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql');
    foreach (array_filter(array_map('trim', explode(';', $sqlRawTables))) as $stmt) {
        if ($stmt !== '') $db->query($stmt);
    }

    // Snapshot tables
    $sqlSnapTables = file_get_contents(__DIR__ . '/../migraciones/v2/2026_10_05_000001_create_v2_read_snapshot_tables.sql');
    foreach (array_filter(array_map('trim', explode(';', $sqlSnapTables))) as $stmt) {
        if ($stmt !== '') $db->query($stmt);
    }

    // Timezone authority table
    $sqlTzTable = file_get_contents(__DIR__ . '/../migraciones/v2/2026_10_12_000001_create_v2_workplace_timezone_authority.sql');
    foreach (array_filter(array_map('trim', explode(';', $sqlTzTable))) as $stmt) {
        if ($stmt !== '') $db->query($stmt);
    }

    // Temporary access table
    $sqlTempTables = file_get_contents(__DIR__ . '/../migraciones/v2/2026_10_01_000001_create_v2_temporary_access_tables.sql');
    foreach (array_filter(array_map('trim', explode(';', $sqlTempTables))) as $stmt) {
        if ($stmt !== '') $db->query($stmt);
    }

    // 2. Seed Modules
    // Modulo 15 = biometricos, Modulo 20 = acceso-temporal
    $db->query("INSERT INTO modulos_dpto_operativo (id, nombre, clave, activo) VALUES 
        (15, 'Biométricos', 'biometricos', 1),
        (20, 'Acceso Temporal', 'acceso-temporal', 1)");

    $db->query("INSERT INTO tb_modulos_config (id, modulo_key, estaciones_soportadas, tipo_departamento, activo) VALUES 
        (15, 'biometricos', '[1,2]', 'localidades', 1),
        (20, 'acceso-temporal', '[1,2]', 'localidades', 1)");

    // Stations: 1 (numlista 2 -> locality 1), 2 (numlista 1 -> locality 2)
    $db->query("INSERT INTO tb_estaciones (id, numlista, estatus) VALUES (1, 2, 0), (2, 1, 0)");
    $db->query("INSERT INTO op_rh_localidades (id, numlista, localidad, recuperacion_vapores) VALUES 
        (1, 2, 'Interlomas', 'GREEN MACHINE'),
        (2, 1, 'Palo Solo', 'OPW')");

    // Timezone mapping for locality 1
    $db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta)
                VALUES (1, 'America/Mexico_City', '2026-10-12', NULL)");

    // User 101: home station 1 (maps to locality 1), puesto 5
    // Has DIRECT grant on biometricos (leer=1), but NO grant on acceso-temporal
    $db->query("INSERT INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (101, 'supervisor_bio', 1, 5, 0)");
    $db->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer) VALUES (15, 101, 1)");

    // User 102: has DIRECT grant on acceso-temporal (leer=1, editar=1), but NO grant on biometricos
    $db->query("INSERT INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (102, 'user_temp_only', 1, 5, 0)");
    $db->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer, editar) VALUES (20, 102, 1, 1)");

    // User 103: has NO grants anywhere
    $db->query("INSERT INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (103, 'user_no_grants', 1, 5, 0)");

    $jwt101 = "Bearer " . make_sra_jwt(['sub' => 101], $jwtSecret);
    $jwt102 = "Bearer " . make_sra_jwt(['sub' => 102], $jwtSecret);
    $jwt103 = "Bearer " . make_sra_jwt(['sub' => 103], $jwtSecret);

    $createPayload = json_encode([
        'from' => '2026-10-12T06:00:00Z',
        'to' => '2026-10-13T06:00:00Z',
        'station_id' => 1
    ]);

    // ==========================================
    // SRA01: Portal3 JWT + biometricos.leer + authorized workplace -> snapshot creation 201 Created
    // ==========================================
    $res01 = call_cgi_api('POST', '/api/v2/punch-events/read-snapshots', [
        'HTTP_AUTHORIZATION' => $jwt101,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => 'idem-sra-01'
    ], $createPayload);
    $snapshotToken = $res01['json']['data']['snapshot']['snapshot_token'] ?? null;
    $pass01 = $res01['code'] === 201
        && ($res01['json']['status'] ?? null) === 'success'
        && $snapshotToken !== null;
    assert_sra($pass01, 'SRA01', 'Portal3 JWT + biometricos.leer + authorized workplace -> snapshot created (201)');

    // ==========================================
    // SRA02: Portal3 JWT without biometricos.leer -> 403 FUNCTION_PERMISSION_DENIED
    // ==========================================
    $res02 = call_cgi_api('POST', '/api/v2/punch-events/read-snapshots', [
        'HTTP_AUTHORIZATION' => $jwt103,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => 'idem-sra-02'
    ], $createPayload);
    $pass02 = $res02['code'] === 403
        && ($res02['json']['status'] ?? null) === 'FUNCTION_PERMISSION_DENIED';
    assert_sra($pass02, 'SRA02', 'Portal3 JWT without biometricos.leer -> 403 FUNCTION_PERMISSION_DENIED');

    // ==========================================
    // SRA03: Portal3 JWT with acceso-temporal but WITHOUT biometricos.leer -> snapshot creation denied (403)
    // ==========================================
    $res03 = call_cgi_api('POST', '/api/v2/punch-events/read-snapshots', [
        'HTTP_AUTHORIZATION' => $jwt102,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => 'idem-sra-03'
    ], $createPayload);
    $pass03 = $res03['code'] === 403
        && ($res03['json']['status'] ?? null) === 'FUNCTION_PERMISSION_DENIED';
    assert_sra($pass03, 'SRA03', 'acceso-temporal grant alone does NOT grant snapshot creation (denied 403)');

    // ==========================================
    // SRA04: Portal3 JWT with biometricos.leer but workplace outside scope -> 403 scope denied
    // ==========================================
    // Locality 2 is NOT in user 101 scope (home station 1 -> locality 1)
    $payloadOutScope = json_encode([
        'from' => '2026-10-12T06:00:00Z',
        'to' => '2026-10-13T06:00:00Z',
        'station_id' => 2
    ]);
    $res04 = call_cgi_api('POST', '/api/v2/punch-events/read-snapshots', [
        'HTTP_AUTHORIZATION' => $jwt101,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => 'idem-sra-04'
    ], $payloadOutScope);
    $pass04 = $res04['code'] === 403
        && in_array($res04['json']['status'] ?? null, ['STATION_SCOPE_DENIED', 'WORKPLACE_SCOPE_DENIED'], true);
    assert_sra($pass04, 'SRA04', 'Workplace outside user scope is denied (403)');

    // ==========================================
    // SRA05: Missing JWT -> 401
    // ==========================================
    $res05 = call_cgi_api('POST', '/api/v2/punch-events/read-snapshots', [
        'CONTENT_TYPE' => 'application/json'
    ], $createPayload);
    $pass05 = $res05['code'] === 401
        && ($res05['json']['status'] ?? null) === 'UNAUTHORIZED';
    assert_sra($pass05, 'SRA05', 'Missing JWT -> 401 UNAUTHORIZED');

    // ==========================================
    // SRA06: Invalid JWT -> 401
    // ==========================================
    $badJwt = "Bearer " . make_sra_jwt(['sub' => 101], 'wrong_secret_signature');
    $res06 = call_cgi_api('POST', '/api/v2/punch-events/read-snapshots', [
        'HTTP_AUTHORIZATION' => $badJwt,
        'CONTENT_TYPE' => 'application/json'
    ], $createPayload);
    $pass06 = $res06['code'] === 401
        && ($res06['json']['status'] ?? null) === 'UNAUTHORIZED';
    assert_sra($pass06, 'SRA06', 'Invalid signature JWT -> 401 UNAUTHORIZED');

    // ==========================================
    // SRA07: Device Auth cannot authenticate snapshot creation -> 401
    // ==========================================
    $res07 = call_cgi_api('POST', '/api/v2/punch-events/read-snapshots', [
        'HTTP_X_DEVICE_ID' => 'DEV-001',
        'HTTP_X_CREDENTIAL_KEY' => 'KEY-001',
        'CONTENT_TYPE' => 'application/json'
    ], $createPayload);
    $pass07 = $res07['code'] === 401
        && ($res07['json']['status'] ?? null) === 'UNAUTHORIZED';
    assert_sra($pass07, 'SRA07', 'Device Auth headers rejected on snapshot creation (401)');

    // ==========================================
    // SRA08: Valid snapshot caller can read snapshot page -> 200 OK
    // ==========================================
    $res08 = call_cgi_api('GET', "/api/v2/punch-events/read-snapshots/{$snapshotToken}/events", [
        'HTTP_AUTHORIZATION' => $jwt101
    ]);
    $pass08 = $res08['code'] === 200
        && ($res08['json']['status'] ?? null) === 'success';
    assert_sra($pass08, 'SRA08', 'Creator with biometricos.leer reads snapshot page (200 OK)');

    // ==========================================
    // SRA09: Snapshot page caller without current biometricos.leer denied (403)
    // ==========================================
    // Revoke biometricos grant from user 101
    $db->query("UPDATE modulos_usuarios_do SET leer = 0 WHERE id_modulo = 15 AND id_usuario = 101");
    $res09 = call_cgi_api('GET', "/api/v2/punch-events/read-snapshots/{$snapshotToken}/events", [
        'HTTP_AUTHORIZATION' => $jwt101
    ]);
    $pass09 = $res09['code'] === 403;
    assert_sra($pass09, 'SRA09', 'Snapshot page caller without current biometricos.leer is denied (403)');
    // Restore grant for subsequent tests
    $db->query("UPDATE modulos_usuarios_do SET leer = 1 WHERE id_modulo = 15 AND id_usuario = 101");

    // ==========================================
    // SRA10: Snapshot page caller outside current workplace scope denied (403)
    // ==========================================
    // Change user 101 home station to 2 (locality 2) so original snapshot scope (locality 1) is no longer covered
    $db->query("UPDATE tb_usuarios SET id_gas = 2 WHERE id = 101");
    $res10 = call_cgi_api('GET', "/api/v2/punch-events/read-snapshots/{$snapshotToken}/events", [
        'HTTP_AUTHORIZATION' => $jwt101
    ]);
    $pass10 = $res10['code'] === 403
        && ($res10['json']['status'] ?? null) === 'SNAPSHOT_SCOPE_CHANGED';
    assert_sra($pass10, 'SRA10', 'Snapshot page caller with reduced workplace scope is denied (403 SNAPSHOT_SCOPE_CHANGED)');
    // Restore home station
    $db->query("UPDATE tb_usuarios SET id_gas = 1 WHERE id = 101");

    // ==========================================
    // SRA11: Snapshot token cannot broaden workplace scope for other callers
    // ==========================================
    // User 102 tries to read user 101 snapshot token
    $res11 = call_cgi_api('GET', "/api/v2/punch-events/read-snapshots/{$snapshotToken}/events", [
        'HTTP_AUTHORIZATION' => $jwt102
    ]);
    $pass11 = $res11['code'] === 403
        && in_array($res11['json']['status'] ?? null, ['SNAPSHOT_ACCESS_DENIED', 'FUNCTION_PERMISSION_DENIED'], true);
    assert_sra($pass11, 'SRA11', 'Non-creator cannot consume snapshot token (403)');

    // ==========================================
    // SRA12: Temporary access endpoints still require acceso-temporal
    // ==========================================
    // User 102 (has acceso-temporal) calling GET /api/v2/temporary-access/requests
    $res12 = call_cgi_api('GET', '/api/v2/temporary-access/requests', [
        'HTTP_AUTHORIZATION' => $jwt102
    ]);
    $pass12 = $res12['code'] === 200
        && ($res12['json']['status'] ?? null) === 'OK';
    assert_sra($pass12, 'SRA12', 'Temporary access endpoint succeeds for user with acceso-temporal (200 OK)');

    // ==========================================
    // SRA13: biometricos.leer alone does NOT grant temporary-access authorization
    // ==========================================
    // User 101 (biometricos only) calling GET /api/v2/temporary-access/requests
    $res13 = call_cgi_api('GET', '/api/v2/temporary-access/requests', [
        'HTTP_AUTHORIZATION' => $jwt101
    ]);
    $pass13 = $res13['code'] === 403
        && ($res13['json']['status'] ?? null) === 'FUNCTION_PERMISSION_DENIED';
    assert_sra($pass13, 'SRA13', 'biometricos.leer alone does NOT grant temporary-access authorization (403)');

    // ==========================================
    // SRA14: Direct-user-over-puesto precedence unchanged
    // ==========================================
    // Give puesto 5 biometricos.leer = 1, but user 101 direct biometricos.leer = 0
    $db->query("INSERT INTO modulos_puestos_do (id_modulo, id_puesto, leer) VALUES (15, 5, 1)
                ON DUPLICATE KEY UPDATE leer = 1");
    $db->query("UPDATE modulos_usuarios_do SET leer = 0 WHERE id_modulo = 15 AND id_usuario = 101");
    $res14 = call_cgi_api('POST', '/api/v2/punch-events/read-snapshots', [
        'HTTP_AUTHORIZATION' => $jwt101,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => 'idem-sra-14'
    ], $createPayload);
    $pass14 = $res14['code'] === 403
        && ($res14['json']['status'] ?? null) === 'FUNCTION_PERMISSION_DENIED';
    assert_sra($pass14, 'SRA14', 'Direct user deny overrides puesto grant (precedence intact)');
    // Restore direct grant
    $db->query("UPDATE modulos_usuarios_do SET leer = 1 WHERE id_modulo = 15 AND id_usuario = 101");

    // ==========================================
    // SRA15: Raw PunchEvent GET authorization remains unchanged
    // ==========================================
    $res15 = call_cgi_api('GET', '/api/v2/punch-events?station_id=1', [
        'HTTP_AUTHORIZATION' => $jwt101
    ]);
    $pass15 = $res15['code'] === 200
        && ($res15['json']['status'] ?? null) === 'OK';
    assert_sra($pass15, 'SRA15', 'GET /api/v2/punch-events succeeds with biometricos.leer (200 OK)');

    // ==========================================
    // SRA16: Timezone read authorization remains unchanged
    // ==========================================
    $res16 = call_cgi_api('GET', '/api/v2/workplaces/1/timezone?date=2026-10-12', [
        'HTTP_AUTHORIZATION' => $jwt101
    ]);
    $pass16 = $res16['code'] === 200
        && ($res16['json']['status'] ?? null) === 'OK'
        && ($res16['json']['data']['timezone'] ?? null) === 'America/Mexico_City';
    assert_sra($pass16, 'SRA16', 'GET /api/v2/workplaces/1/timezone succeeds with biometricos.leer (200 OK)');

} finally {
    if ($admin !== null && $dbName !== '') {
        $admin->query("DROP DATABASE IF EXISTS `{$dbName}`");
        log_sra("DISPOSABLE_DB: REMOVED ({$dbName})");
        $admin->close();
    }
}

log_sra("\n=== TEST RESULTS SUMMARY ===");
log_sra("TOTAL TESTS : " . ($passedCount + $failedCount));
log_sra("PASSED      : {$passedCount}");
log_sra("FAILED      : {$failedCount}");

if ($failedCount > 0) {
    exit(1);
}
