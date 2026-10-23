<?php
/**
 * Test Suite: Level 1 Snapshot Parity Tests (SP01 - SP10)
 *
 * Verifies that:
 * 1. Bounded Raw Read and bounded Snapshot return identical membership for the same authorized workplace/window.
 * 2. Rows with ocurrido_at_utc = NULL are strictly excluded from bounded snapshot.
 * 3. Out-of-window rows (before window) are excluded.
 * 4. Out-of-window rows (after window) are excluded.
 * 5. Boundary rows exactly at from_utc boundary are included.
 * 6. Boundary rows exactly at to_utc boundary are included.
 * 7. Valid in-window rows are included.
 * 8. Late-arriving events committed after snapshot creation do not alter existing snapshot membership.
 * 9. Snapshot raw-reference integrity is preserved (foreign keys to raw punches).
 * 10. Triggers prevent any raw punch mutation (UPDATE/DELETE blocked).
 */

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : 'kimera';
$jwtSecret = 'test_jwt_secret_sp_tests_999888';
putenv("JWT_SECRET={$jwtSecret}");

$dbName = 'checador_v2_tmp_sp_tests_' . getmypid() . '_' . time();

$admin = null;
$db = null;
$passedCount = 0;
$failedCount = 0;

function log_sp(string $msg): void { echo $msg . "\n"; }

function assert_sp(bool $condition, string $id, string $desc): void {
    global $passedCount, $failedCount;
    if ($condition) {
        $passedCount++;
        log_sp("[$id] PASS: $desc");
    } else {
        $failedCount++;
        log_sp("[$id] FAIL: $desc");
    }
}

function b64Url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function make_sp_jwt(array $customPayload, string $secret): string {
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

    // 1. Auxiliary Portal3 schema
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

    // Apply baseline migrations
    $migrations = [
        __DIR__ . '/../migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_02_000002_add_integrity_seal_to_raw_punches.sql',
        __DIR__ . '/../migraciones/v2/2026_10_05_000001_create_v2_read_snapshot_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_09_000001_widen_snapshot_event_uuid.sql',
        __DIR__ . '/../migraciones/v2/2026_10_09_000002_add_snapshot_member_raw_reference_integrity.sql',
        __DIR__ . '/../migraciones/v2/2026_10_12_000001_create_v2_workplace_timezone_authority.sql'
    ];

    foreach ($migrations as $migFile) {
        $sql = file_get_contents($migFile);
        $db->multi_query($sql);
        while ($db->more_results() && $db->next_result()) { /* flush */ }
    }

    // Apply immutability triggers on op_rh_v2_marcaciones_crudas
    $db->query("DROP TRIGGER IF EXISTS `tg_op_rh_v2_marcaciones_crudas_no_update`");
    $db->query("CREATE TRIGGER `tg_op_rh_v2_marcaciones_crudas_no_update` BEFORE UPDATE ON `op_rh_v2_marcaciones_crudas` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. UPDATE operations are strictly prohibited on op_rh_v2_marcaciones_crudas.'; END");
    $db->query("DROP TRIGGER IF EXISTS `tg_op_rh_v2_marcaciones_crudas_no_delete`");
    $db->query("CREATE TRIGGER `tg_op_rh_v2_marcaciones_crudas_no_delete` BEFORE DELETE ON `op_rh_v2_marcaciones_crudas` FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. DELETE operations are strictly prohibited on op_rh_v2_marcaciones_crudas.'; END");

    // 2. Seed Modules: Modulo 15 = biometricos
    $db->query("INSERT INTO modulos_dpto_operativo (id, nombre, clave, activo) VALUES (15, 'Biométricos', 'biometricos', 1)");
    $db->query("INSERT INTO tb_modulos_config (id, modulo_key, estaciones_soportadas, tipo_departamento, activo) VALUES (15, 'biometricos', '[1,2]', 'localidades', 1)");

    // Stations: Station 1 -> numlista 2 -> Locality 1
    $db->query("INSERT INTO tb_estaciones (id, numlista, estatus) VALUES (1, 2, 0), (2, 1, 0)");
    $db->query("INSERT INTO op_rh_localidades (id, numlista, localidad, recuperacion_vapores) VALUES 
        (1, 2, 'Estacion Matriz', 'GREEN MACHINE'),
        (2, 1, 'Sucursal Norte', 'OPW')");

    // Timezone mapping for locality 1
    $db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta) VALUES (1, 'America/Mexico_City', '2026-10-12', NULL)");

    // Seed user 101 with biometricos.leer on home station 1 (maps to locality 1)
    $db->query("INSERT INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (101, 'usuario_demo_e2e', 1, 1, 0)");
    $db->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer) VALUES (15, 101, 1)");

    // Seed device 100 on station 1
    $db->query("INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado) VALUES (100, 'dev-matriz-01', 1, 'Lector Matriz', 'America/Mexico_City', 1)");

    // Seed events into op_rh_v2_marcaciones_crudas:
    // Window is 2026-10-12 06:00:00 UTC to 2026-10-13 06:00:00 UTC
    // 1. ev-null-1: ocurrido_at_utc = NULL (historical)
    // 2. ev-null-2: ocurrido_at_utc = NULL (historical)
    // 3. ev-before: ocurrido_at_utc = '2026-10-12 05:59:59' (before window)
    // 4. ev-boundary-from: ocurrido_at_utc = '2026-10-12 06:00:00' (exact from boundary)
    // 5. ev-in-1: ocurrido_at_utc = '2026-10-12 08:30:00' (in window)
    // 6. ev-in-2: ocurrido_at_utc = '2026-10-12 14:00:00' (in window)
    // 7. ev-boundary-to: ocurrido_at_utc = '2026-10-13 06:00:00' (exact to boundary)
    // 8. ev-after: ocurrido_at_utc = '2026-10-13 06:00:01' (after window)
    // 9. ev-st2-in: ocurrido_at_utc = '2026-10-12 10:00:00' on station 2 (other station)

    $eventsToSeed = [
        ['uuid' => 'ev-null-1', 'time' => null, 'source' => '2026-02-01 08:00:00', 'st' => 1],
        ['uuid' => 'ev-null-2', 'time' => null, 'source' => '2026-08-01 08:00:00', 'st' => 1],
        ['uuid' => 'ev-before', 'time' => '2026-10-12 05:59:59', 'source' => '2026-10-11 23:59:59', 'st' => 1],
        ['uuid' => 'ev-boundary-from', 'time' => '2026-10-12 06:00:00', 'source' => '2026-10-12 00:00:00', 'st' => 1],
        ['uuid' => 'ev-in-1', 'time' => '2026-10-12 08:30:00', 'source' => '2026-10-12 02:30:00', 'st' => 1],
        ['uuid' => 'ev-in-2', 'time' => '2026-10-12 14:00:00', 'source' => '2026-10-12 08:00:00', 'st' => 1],
        ['uuid' => 'ev-boundary-to', 'time' => '2026-10-13 06:00:00', 'source' => '2026-10-13 00:00:00', 'st' => 1],
        ['uuid' => 'ev-after', 'time' => '2026-10-13 06:00:01', 'source' => '2026-10-13 00:00:01', 'st' => 1],
        ['uuid' => 'ev-st2-in', 'time' => '2026-10-12 10:00:00', 'source' => '2026-10-12 04:00:00', 'st' => 2],
    ];

    $stmt = $db->prepare("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES (100, ?, ?, 'EMP-01', ?, ?, UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");
    foreach ($eventsToSeed as $e) {
        $stmt->bind_param('isss', $e['st'], $e['uuid'], $e['time'], $e['source']);
        $stmt->execute();
    }
    $stmt->close();

    $userJwt = "Bearer " . make_sp_jwt(['sub' => 101], $jwtSecret);
    $authHeader = ['HTTP_AUTHORIZATION' => $userJwt];

    $fromUtc = '2026-10-12T06:00:00Z';
    $toUtc = '2026-10-13T06:00:00Z';

    // 1. Raw Read for station 1
    $rawRes = call_cgi_api('GET', "/api/v2/punch-events?station_id=1&from=" . urlencode($fromUtc) . "&to=" . urlencode($toUtc), $authHeader);
    assert_sp($rawRes['code'] === 200, 'SP01_RAW_STATUS', "Raw read returns 200 OK");
    $rawUuids = [];
    if (isset($rawRes['json']['data']) && is_array($rawRes['json']['data'])) {
        foreach ($rawRes['json']['data'] as $p) {
            if (isset($p['event_uuid'])) {
                $rawUuids[] = $p['event_uuid'];
            }
        }
    }

    // 2. Snapshot creation with identical filters
    $snapCreatePayload = json_encode([
        'from' => $fromUtc,
        'to' => $toUtc,
        'station_id' => 1
    ]);
    $snapRes = call_cgi_api('POST', '/api/v2/punch-events/read-snapshots', array_merge($authHeader, [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => 'idem-sp-01'
    ]), $snapCreatePayload);
    assert_sp($snapRes['code'] === 201, 'SP01_SNAP_STATUS', "Snapshot create returns 201 Created");

    $snapToken = $snapRes['json']['data']['snapshot']['snapshot_token'] ?? '';
    assert_sp(!empty($snapToken), 'SP01_SNAP_TOKEN', "Snapshot token is present");

    // Read snapshot page
    $pageRes = call_cgi_api('GET', "/api/v2/punch-events/read-snapshots/{$snapToken}/events?limit=200", $authHeader);
    assert_sp($pageRes['code'] === 200, 'SP01_PAGE_STATUS', "Snapshot page returns 200 OK");

    $snapUuids = [];
    if (isset($pageRes['json']['data']['items'])) {
        foreach ($pageRes['json']['data']['items'] as $ev) {
            $snapUuids[] = $ev['event_uuid'];
        }
    }

    log_sp("RAW UUIDS: " . json_encode($rawUuids));
    log_sp("SNAP UUIDS: " . json_encode($snapUuids));

    // SP01: Bounded Raw Read and bounded Snapshot return identical membership
    $membershipEqual = ($rawUuids === $snapUuids);
    assert_sp($membershipEqual && count($rawUuids) === 4, 'SP01', "Raw and Snapshot membership are identical (4 events, exact match)");

    // SP02: Raw rows with ocurrido_at_utc = NULL are excluded
    $nullExcluded = !in_array('ev-null-1', $snapUuids, true) && !in_array('ev-null-2', $snapUuids, true);
    assert_sp($nullExcluded, 'SP02', "Raw rows with ocurrido_at_utc = NULL are strictly excluded from bounded snapshot");

    // SP03: Raw row with occurred timestamp before window is excluded
    $beforeExcluded = !in_array('ev-before', $snapUuids, true);
    assert_sp($beforeExcluded, 'SP03', "Raw row before window (2026-10-12 05:59:59Z) is excluded");

    // SP04: Raw row with occurred timestamp after window is excluded
    $afterExcluded = !in_array('ev-after', $snapUuids, true);
    assert_sp($afterExcluded, 'SP04', "Raw row after window (2026-10-13 06:00:01Z) is excluded");

    // SP05: Raw row exactly at from boundary follows frozen inclusive contract
    $fromIncluded = in_array('ev-boundary-from', $snapUuids, true);
    assert_sp($fromIncluded, 'SP05', "Raw row exactly at from boundary (06:00:00Z) is included");

    // SP06: Raw row exactly at to boundary follows frozen inclusive contract
    $toIncluded = in_array('ev-boundary-to', $snapUuids, true);
    assert_sp($toIncluded, 'SP06', "Raw row exactly at to boundary (06:00:00Z) is included");

    // SP07: Valid in-window rows are included
    $inWindowIncluded = in_array('ev-in-1', $snapUuids, true) && in_array('ev-in-2', $snapUuids, true);
    assert_sp($inWindowIncluded, 'SP07', "Valid in-window rows (ev-in-1, ev-in-2) are included");

    // SP08: Snapshot stable membership/paging remains unchanged after creation (late-arriving punch)
    $latePunchStmt = $db->prepare("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES (100, 1, 'ev-late-arrival', 'EMP-01', '2026-10-12 12:00:00', '2026-10-12 06:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");
    $latePunchStmt->execute();
    $latePunchStmt->close();

    $reReadPage = call_cgi_api('GET', "/api/v2/punch-events/read-snapshots/{$snapToken}/events?limit=200", $authHeader);
    $reReadUuids = [];
    if (isset($reReadPage['json']['data']['items'])) {
        foreach ($reReadPage['json']['data']['items'] as $ev) {
            $reReadUuids[] = $ev['event_uuid'];
        }
    }
    $stableMembership = ($reReadUuids === $snapUuids) && !in_array('ev-late-arrival', $reReadUuids, true);
    assert_sp($stableMembership, 'SP08', "Snapshot membership remains immutable after late-arriving punch insertion");

    // SP09: Snapshot raw-reference integrity remains valid
    $refRes = $db->query("SELECT m.id_raw_punch, r.id AS raw_exists FROM op_rh_v2_read_snapshot_members m LEFT JOIN op_rh_v2_marcaciones_crudas r ON m.id_raw_punch = r.id");
    $allRefsValid = true;
    $refCount = 0;
    while ($row = $refRes->fetch_assoc()) {
        $refCount++;
        if ($row['raw_exists'] === null) {
            $allRefsValid = false;
        }
    }
    assert_sp($allRefsValid && $refCount === 4, 'SP09', "Snapshot raw-reference integrity valid ($refCount members point to valid raw records)");

    // SP10: No raw PunchEvent mutation occurs (triggers block UPDATE/DELETE)
    $updateBlocked = false;
    try {
        $db->query("UPDATE op_rh_v2_marcaciones_crudas SET event_uuid = 'tampered' WHERE event_uuid = 'ev-in-1'");
    } catch (mysqli_sql_exception $e) {
        $updateBlocked = true;
    }
    $deleteBlocked = false;
    try {
        $db->query("DELETE FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = 'ev-in-1'");
    } catch (mysqli_sql_exception $e) {
        $deleteBlocked = true;
    }
    assert_sp($updateBlocked && $deleteBlocked, 'SP10', "Raw PunchEvents are protected by triggers against UPDATE and DELETE");

} catch (Throwable $e) {
    log_sp("FATAL EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    $failedCount++;
} finally {
    if ($admin !== null && $dbName !== null) {
        try {
            $admin->query("DROP DATABASE IF EXISTS `{$dbName}`");
            log_sp("DISPOSABLE_DB: REMOVED ({$dbName})");
        } catch (Throwable $ignore) {}
        $admin->close();
    }
}

echo "\n=== TEST RESULTS SUMMARY ===\n";
echo "TOTAL TESTS : " . ($passedCount + $failedCount) . "\n";
echo "PASSED      : {$passedCount}\n";
echo "FAILED      : {$failedCount}\n";

exit($failedCount === 0 ? 0 : 1);
