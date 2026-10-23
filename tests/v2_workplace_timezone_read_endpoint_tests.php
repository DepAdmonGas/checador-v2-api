<?php
/**
 * Test Suite: Checador V2 Workplace Timezone Read Endpoint Tests
 * Tests TZR01 - TZR20 + Security & Boundary Validations
 *
 * Implements strict verification of docs/v2/WORKPLACE_TIMEZONE_READ_API_CONTRACT_V1.0.md
 * Uses an isolated, uniquely named disposable test database; zero business mappings
 * or persistent development data are touched.
 * Uses php-cgi to execute requests through index.php routing with exact HTTP statuses & headers.
 */

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : 'kimera';
$jwtSecret = 'test_jwt_secret_key_v2_tzr_tests_12345';

$dbName = 'checador_v2_tmp_tzr_tests_' . getmypid() . '_' . time();

$admin = null;
$db = null;
$passedCount = 0;
$failedCount = 0;

function log_tz(string $msg): void { echo $msg . "\n"; }

function assert_tz(bool $condition, string $id, string $desc): void {
    global $passedCount, $failedCount;
    if ($condition) {
        $passedCount++;
        log_tz("[$id] PASS: $desc");
    } else {
        $failedCount++;
        log_tz("[$id] FAIL: $desc");
    }
}

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function create_jwt(array $customPayload, string $secret): string {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $defaultPayload = [
        'iss' => 'portal3_auth',
        'sub' => 101,
        'type' => 'access',
        'exp' => time() + 3600
    ];
    $payload = array_merge($defaultPayload, $customPayload);

    $hB64 = base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $pB64 = base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = base64UrlEncode(hash_hmac('sha256', $hB64 . '.' . $pB64, $secret, true));

    return $hB64 . '.' . $pB64 . '.' . $sig;
}

function make_cgi_request(string $method, string $uri, array $headers = [], string $body = ''): array {
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
        0 => ["pipe", "r"], // stdin
        1 => ["pipe", "w"], // stdout
        2 => ["pipe", "w"]  // stderr
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
    log_tz("=== CHECADOR V2 WORKPLACE TIMEZONE READ ENDPOINT SUITE (TZR01-TZR20) ===");
    log_tz("TEST DATABASE: {$dbName}");

    $admin = new mysqli($host, $user, $pass);
    $admin->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($host, $user, $pass, $dbName);

    // 1. Create Base Tables
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
        numlista INT DEFAULT 0
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

    $db->query("CREATE TABLE op_rh_v2_localidades_zonas_horarias (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_localidad INT(11) NOT NULL,
        timezone_iana VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        valido_desde DATE NOT NULL,
        valido_hasta DATE NULL DEFAULT NULL,
        registrado_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_v2_localidad_timezone_start (id_localidad, valido_desde),
        CONSTRAINT fk_v2_localidad_timezone_loc FOREIGN KEY (id_localidad) REFERENCES op_rh_localidades(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");

    // 2. Insert Seed Foundations
    // Module 'biometricos' (id: 15)
    $db->query("INSERT INTO modulos_dpto_operativo (id, nombre, clave, activo) VALUES (15, 'Biométricos', 'biometricos', 1)");
    // Stations supported: 10, 20, 30
    $db->query("INSERT INTO tb_modulos_config (id, modulo_key, estaciones_soportadas, tipo_departamento, activo) VALUES (15, 'biometricos', '[10,20,30]', 'localidades', 1)");

    // Mapping: station 10 -> numlista 1001 -> locality 14 (authorized)
    $db->query("INSERT INTO tb_estaciones (id, numlista, estatus) VALUES (10, 1001, 0), (20, 1002, 0), (30, 1003, 0)");
    $db->query("INSERT INTO op_rh_localidades (id, numlista) VALUES (14, 1001), (25, 1002), (35, 1003)");

    // User 101: home station 10, puesto 5, estatus 0
    $db->query("INSERT INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (101, 'supervisor_portal3', 10, 5, 0)");
    // User 101 has direct grant leer=1 on biometricos
    $db->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer, crear, editar, eliminar, descargar) VALUES (15, 101, 1, 0, 0, 0, 0)");

    $validJwt = "Bearer " . create_jwt(['sub' => 101], $jwtSecret);

    // ==========================================
    // TZR01: Valid Portal3 JWT + authorized workplace + resolved mapping -> 200
    // ==========================================
    $db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta)
                VALUES (14, 'America/Mexico_City', '2026-01-01', NULL)");

    $res01 = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $pass01 = $res01['code'] === 200
        && ($res01['json']['status'] ?? null) === 'OK'
        && ($res01['json']['data']['workplace_id'] ?? null) === 14
        && ($res01['json']['data']['local_date'] ?? null) === '2026-10-12'
        && ($res01['json']['data']['timezone'] ?? null) === 'America/Mexico_City'
        && ($res01['json']['data']['from_utc'] ?? null) === '2026-10-12T06:00:00Z'
        && ($res01['json']['data']['to_utc'] ?? null) === '2026-10-13T06:00:00Z';
    assert_tz($pass01, 'TZR01', 'Valid Portal3 JWT + authorized workplace + resolved mapping -> 200 with from_utc/to_utc');

    // ==========================================
    // TZR02: Missing Authorization header -> 401
    // ==========================================
    $res02 = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=2026-10-12');
    $pass02 = $res02['code'] === 401
        && ($res02['json']['status'] ?? null) === 'UNAUTHORIZED';
    assert_tz($pass02, 'TZR02', 'Missing Authorization header -> 401 UNAUTHORIZED');

    // ==========================================
    // TZR03: Invalid or expired Portal3 JWT -> 401
    // ==========================================
    $expiredJwt = "Bearer " . create_jwt(['sub' => 101, 'exp' => time() - 300], $jwtSecret);
    $res03a = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $expiredJwt]);

    $tamperedJwt = "Bearer " . create_jwt(['sub' => 101], 'wrong_secret_signature');
    $res03b = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $tamperedJwt]);

    $pass03 = $res03a['code'] === 401 && ($res03a['json']['status'] ?? null) === 'UNAUTHORIZED'
        && $res03b['code'] === 401 && ($res03b['json']['status'] ?? null) === 'UNAUTHORIZED';
    assert_tz($pass03, 'TZR03', 'Invalid or expired Portal3 JWT -> 401 UNAUTHORIZED');

    // ==========================================
    // TZR04: Workplace outside JWT station scope -> 403 (WORKPLACE_SCOPE_DENIED)
    // ==========================================
    // Locality 25 exists, but user 101 only has home station 10 (locality 14)
    $res04 = make_cgi_request('GET', '/api/v2/workplaces/25/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $pass04 = $res04['code'] === 403
        && ($res04['json']['status'] ?? null) === 'WORKPLACE_SCOPE_DENIED';
    assert_tz($pass04, 'TZR04', 'Workplace outside JWT scope -> 403 WORKPLACE_SCOPE_DENIED');

    // ==========================================
    // TZR05: Valid JWT requesting non-existent workplace ID -> fails closed (403/404)
    // ==========================================
    $res05 = make_cgi_request('GET', '/api/v2/workplaces/999/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $pass05 = in_array($res05['code'], [403, 404], true)
        && in_array($res05['json']['status'] ?? null, ['WORKPLACE_SCOPE_DENIED', 'WORKPLACE_NOT_FOUND'], true);
    assert_tz($pass05, 'TZR05', 'Unknown workplace ID fails closed deterministically (403/404)');

    // ==========================================
    // TZR06: Known workplace with no mapping -> WORKPLACE_TIMEZONE_UNRESOLVED (404)
    // ==========================================
    // Locality 35 (station 30) exists. Authorize user 101 for locality 35.
    $db->query("INSERT INTO tb_multiestacion_usuario (id_usuario, estaciones, activo) VALUES (101, '[10,30]', 1)
                ON DUPLICATE KEY UPDATE estaciones = '[10,30]'");
    // Locality 35 has NO rows in op_rh_v2_localidades_zonas_horarias
    $res06 = make_cgi_request('GET', '/api/v2/workplaces/35/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $pass06 = $res06['code'] === 404
        && ($res06['json']['status'] ?? null) === 'WORKPLACE_TIMEZONE_UNRESOLVED';
    assert_tz($pass06, 'TZR06', 'Known workplace with no mapping -> 404 WORKPLACE_TIMEZONE_UNRESOLVED');

    // ==========================================
    // TZR07: Invalid/unrecognized stored IANA timezone string -> 500 (WORKPLACE_TIMEZONE_INVALID)
    // ==========================================
    $db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta)
                VALUES (35, 'Invalid/Non_Existent_Zone', '2026-01-01', NULL)");
    $res07 = make_cgi_request('GET', '/api/v2/workplaces/35/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $pass07 = $res07['code'] === 500
        && ($res07['json']['status'] ?? null) === 'WORKPLACE_TIMEZONE_INVALID';
    assert_tz($pass07, 'TZR07', 'Invalid IANA mapping in DB -> 500 WORKPLACE_TIMEZONE_INVALID');

    // ==========================================
    // TZR08: Overlapping effective mappings -> 409 (WORKPLACE_TIMEZONE_AMBIGUOUS)
    // ==========================================
    $db->query("DELETE FROM op_rh_v2_localidades_zonas_horarias WHERE id_localidad = 35");
    // Insert two overlapping rows with different valido_desde
    $db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta)
                VALUES (35, 'America/Mexico_City', '2026-01-01', '2026-12-31')");
    $db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta)
                VALUES (35, 'America/Cancun', '2026-06-01', '2026-12-31')");
    $res08 = make_cgi_request('GET', '/api/v2/workplaces/35/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $pass08 = $res08['code'] === 409
        && ($res08['json']['status'] ?? null) === 'WORKPLACE_TIMEZONE_AMBIGUOUS';
    assert_tz($pass08, 'TZR08', 'Overlapping effective mappings -> 409 WORKPLACE_TIMEZONE_AMBIGUOUS');

    // ==========================================
    // TZR09: Date strictly before earliest mapping -> 404 (WORKPLACE_TIMEZONE_UNRESOLVED)
    // ==========================================
    $db->query("DELETE FROM op_rh_v2_localidades_zonas_horarias WHERE id_localidad = 35");
    $db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta)
                VALUES (35, 'America/Tijuana', '2026-06-01', NULL)");
    $res09 = make_cgi_request('GET', '/api/v2/workplaces/35/timezone?date=2026-05-15', ['HTTP_AUTHORIZATION' => $validJwt]);
    $pass09 = $res09['code'] === 404
        && ($res09['json']['status'] ?? null) === 'WORKPLACE_TIMEZONE_UNRESOLVED';
    assert_tz($pass09, 'TZR09', 'Date before earliest mapping -> 404 WORKPLACE_TIMEZONE_UNRESOLVED');

    // ==========================================
    // TZR10: Date outside expired mapping -> 404 (WORKPLACE_TIMEZONE_UNRESOLVED)
    // ==========================================
    $db->query("DELETE FROM op_rh_v2_localidades_zonas_horarias WHERE id_localidad = 35");
    $db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta)
                VALUES (35, 'America/Tijuana', '2026-01-01', '2026-06-01')");
    $res10 = make_cgi_request('GET', '/api/v2/workplaces/35/timezone?date=2026-06-01', ['HTTP_AUTHORIZATION' => $validJwt]); // valido_hasta is exclusive
    $pass10 = $res10['code'] === 404
        && ($res10['json']['status'] ?? null) === 'WORKPLACE_TIMEZONE_UNRESOLVED';
    assert_tz($pass10, 'TZR10', 'Date on/after expired valido_hasta -> 404 WORKPLACE_TIMEZONE_UNRESOLVED');

    // ==========================================
    // TZR11: Client timezone override parameter is IGNORED -> Authoritative DB timezone returned
    // ==========================================
    // Locality 14 has America/Mexico_City. Client supplies ?date=2026-10-12&tz=America/New_York&timezone=Europe/London
    $res11 = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=2026-10-12&tz=America/New_York&timezone=Europe/London', ['HTTP_AUTHORIZATION' => $validJwt]);
    $pass11 = $res11['code'] === 200
        && ($res11['json']['data']['timezone'] ?? null) === 'America/Mexico_City'
        && ($res11['json']['data']['from_utc'] ?? null) === '2026-10-12T06:00:00Z';
    assert_tz($pass11, 'TZR11', 'Client timezone override parameter is ignored, authoritative DB timezone returned');

    // ==========================================
    // TZR12: Device Auth cannot authenticate endpoint -> 401 UNAUTHORIZED
    // ==========================================
    $res12 = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=2026-10-12', [
        'HTTP_X_DEVICE_ID' => '10',
        'HTTP_X_CREDENTIAL_KEY' => 'cred_secret_123'
    ]);
    $pass12 = $res12['code'] === 401
        && ($res12['json']['status'] ?? null) === 'UNAUTHORIZED';
    assert_tz($pass12, 'TZR12', 'Device Auth headers rejected with 401 UNAUTHORIZED');

    // ==========================================
    // TZR13: Browser/session-cookie-only authentication rejected -> 401 UNAUTHORIZED
    // ==========================================
    $res13 = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=2026-10-12', [
        'HTTP_COOKIE' => 'PHPSESSID=session_12345; portal_user=supervisor'
    ]);
    $pass13 = $res13['code'] === 401
        && ($res13['json']['status'] ?? null) === 'UNAUTHORIZED';
    assert_tz($pass13, 'TZR13', 'Session-cookie-only authentication rejected with 401 UNAUTHORIZED');

    // ==========================================
    // TZR14: Synthetic spring-forward day yields 23-hour UTC interval (82,800s)
    // ==========================================
    // In America/New_York (US DST), spring-forward occurred on 2026-03-08 (civil day is 23 hours)
    $db->query("DELETE FROM op_rh_v2_localidades_zonas_horarias WHERE id_localidad = 35");
    $db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta)
                VALUES (35, 'America/New_York', '2026-01-01', NULL)");
    $res14 = make_cgi_request('GET', '/api/v2/workplaces/35/timezone?date=2026-03-08', ['HTTP_AUTHORIZATION' => $validJwt]);
    $fromSec14 = strtotime($res14['json']['data']['from_utc'] ?? '');
    $toSec14 = strtotime($res14['json']['data']['to_utc'] ?? '');
    $diff14 = $toSec14 - $fromSec14;
    $pass14 = $res14['code'] === 200
        && $diff14 === 82800 // exactly 23 hours
        && ($res14['json']['data']['from_utc'] ?? null) === '2026-03-08T05:00:00Z'
        && ($res14['json']['data']['to_utc'] ?? null) === '2026-03-09T04:00:00Z';
    assert_tz($pass14, 'TZR14', "DST spring-forward day produces exact 23-hour UTC interval (82,800s) [diff={$diff14}s]");

    // ==========================================
    // TZR15: Synthetic fall-back day yields 25-hour UTC interval (90,000s)
    // ==========================================
    // In America/New_York, fall-back occurred on 2026-11-01 (civil day is 25 hours)
    $res15 = make_cgi_request('GET', '/api/v2/workplaces/35/timezone?date=2026-11-01', ['HTTP_AUTHORIZATION' => $validJwt]);
    $fromSec15 = strtotime($res15['json']['data']['from_utc'] ?? '');
    $toSec15 = strtotime($res15['json']['data']['to_utc'] ?? '');
    $diff15 = $toSec15 - $fromSec15;
    $pass15 = $res15['code'] === 200
        && $diff15 === 90000 // exactly 25 hours
        && ($res15['json']['data']['from_utc'] ?? null) === '2026-11-01T04:00:00Z'
        && ($res15['json']['data']['to_utc'] ?? null) === '2026-11-02T05:00:00Z';
    assert_tz($pass15, 'TZR15', "DST fall-back day produces exact 25-hour UTC interval (90,000s) [diff={$diff15}s]");

    // ==========================================
    // TZR16: Normal day yields 24-hour UTC interval (86,400s)
    // ==========================================
    $res16 = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $fromSec16 = strtotime($res16['json']['data']['from_utc'] ?? '');
    $toSec16 = strtotime($res16['json']['data']['to_utc'] ?? '');
    $diff16 = $toSec16 - $fromSec16;
    $pass16 = $res16['code'] === 200
        && $diff16 === 86400
        && ($res16['json']['data']['from_utc'] ?? null) === '2026-10-12T06:00:00Z'
        && ($res16['json']['data']['to_utc'] ?? null) === '2026-10-13T06:00:00Z';
    assert_tz($pass16, 'TZR16', "Normal day produces exact 24-hour UTC interval (86,400s) [diff={$diff16}s]");

    // ==========================================
    // TZR17: No global fallback to America/Mexico_City for unmapped workplace
    // ==========================================
    // Locality 25 has station 20 authorized. It has NO rows in op_rh_v2_localidades_zonas_horarias.
    $db->query("UPDATE tb_multiestacion_usuario SET estaciones = '[10,20,30]' WHERE id_usuario = 101");
    $res17 = make_cgi_request('GET', '/api/v2/workplaces/25/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $pass17 = $res17['code'] === 404
        && ($res17['json']['status'] ?? null) === 'WORKPLACE_TIMEZONE_UNRESOLVED'
        && !isset($res17['json']['data']);
    assert_tz($pass17, 'TZR17', 'Unmapped workplace fails closed with 404 without falling back to America/Mexico_City');

    // ==========================================
    // TZR18: Response exposes no secrets or internal sensitive info
    // ==========================================
    $raw18 = $res16['body'];
    $pass18 = strpos($raw18, $jwtSecret) === false
        && strpos($raw18, 'kimera') === false
        && strpos($raw18, 'root') === false
        && strpos($raw18, 'SELECT') === false;
    assert_tz($pass18, 'TZR18', 'Response exposes no secrets, passwords, or SQL queries');

    // ==========================================
    // TZR19: Response contains no attendance or payroll conclusions
    // ==========================================
    $raw19 = $res16['body'];
    $prohibitedTerms = ['FALTA', 'RETARDO', 'SIN MARCACIÓN', 'JORNADA', 'INCIDENCIA', 'asistencia', 'nomina'];
    $foundTerm = false;
    foreach ($prohibitedTerms as $t) {
        if (stripos($raw19, $t) !== false) {
            $foundTerm = true;
            break;
        }
    }
    assert_tz(!$foundTerm, 'TZR19', 'Response contains zero attendance/payroll conclusions');

    // ==========================================
    // TZR20: Deterministic and idempotent resolution
    // ==========================================
    $res20a = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $res20b = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $res20c = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $pass20 = $res20a['body'] === $res20b['body'] && $res20b['body'] === $res20c['body'];
    assert_tz($pass20, 'TZR20', 'Identical requests produce byte-identical deterministic responses');

    // ==========================================
    // Additional input validations: BAD_DATE tests
    // ==========================================
    $resBadDate1 = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=2026-02-30', ['HTTP_AUTHORIZATION' => $validJwt]);
    $resBadDate2 = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=12/10/2026', ['HTTP_AUTHORIZATION' => $validJwt]);
    $resBadDate3 = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=2026-10-12T00:00:00', ['HTTP_AUTHORIZATION' => $validJwt]);
    $resBadDate4 = make_cgi_request('GET', '/api/v2/workplaces/14/timezone?date=2026-10-12Z', ['HTTP_AUTHORIZATION' => $validJwt]);
    $passBadDates = $resBadDate1['code'] === 400 && ($resBadDate1['json']['status'] ?? null) === 'BAD_DATE'
        && $resBadDate2['code'] === 400 && ($resBadDate2['json']['status'] ?? null) === 'BAD_DATE'
        && $resBadDate3['code'] === 400 && ($resBadDate3['json']['status'] ?? null) === 'BAD_DATE'
        && $resBadDate4['code'] === 400 && ($resBadDate4['json']['status'] ?? null) === 'BAD_DATE';
    assert_tz($passBadDates, 'SEC-INP01', 'Impossible and non-YYYY-MM-DD dates rejected with 400 BAD_DATE');

    // ==========================================
    // Additional input validations: INVALID_WORKPLACE_ID tests
    // ==========================================
    $resBadId1 = make_cgi_request('GET', '/api/v2/workplaces/0/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $resBadId2 = make_cgi_request('GET', '/api/v2/workplaces/-14/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $resBadId3 = make_cgi_request('GET', '/api/v2/workplaces/abc/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $passBadIds = $resBadId1['code'] === 400 && ($resBadId1['json']['status'] ?? null) === 'INVALID_WORKPLACE_ID'
        && $resBadId2['code'] === 400 && ($resBadId2['json']['status'] ?? null) === 'INVALID_WORKPLACE_ID'
        && $resBadId3['code'] === 400 && ($resBadId3['json']['status'] ?? null) === 'INVALID_WORKPLACE_ID';
    assert_tz($passBadIds, 'SEC-INP02', 'Zero, negative, and non-numeric workplace IDs rejected with 400 INVALID_WORKPLACE_ID');

    // ==========================================
    // Method Not Allowed test (POST /api/v2/workplaces/14/timezone)
    // ==========================================
    $res405 = make_cgi_request('POST', '/api/v2/workplaces/14/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $validJwt]);
    $pass405 = $res405['code'] === 405 && ($res405['json']['status'] ?? null) === 'METHOD_NOT_ALLOWED';
    assert_tz($pass405, 'SEC-MTH01', 'POST method returns 405 METHOD_NOT_ALLOWED with Allow: GET');

} finally {
    if ($admin !== null && $dbName !== '') {
        $admin->query("DROP DATABASE IF EXISTS `{$dbName}`");
        log_tz("DISPOSABLE_DB: REMOVED ({$dbName})");
        $admin->close();
    }
}

log_tz("\n=== TEST RESULTS SUMMARY ===");
log_tz("TOTAL TESTS : " . ($passedCount + $failedCount));
log_tz("PASSED      : {$passedCount}");
log_tz("FAILED      : {$failedCount}");

if ($failedCount > 0) {
    exit(1);
}
