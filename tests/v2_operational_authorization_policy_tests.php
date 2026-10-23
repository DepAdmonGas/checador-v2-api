<?php
/**
 * Test Suite: Checador V2 Operational Authorization Institutional Policy Tests (V7C13)
 *
 * Implements strict focal tests for the Checador V2 institutional authorization policy:
 * 1. Puesto 13 con permiso: estaciones autorizadas.
 * 2. Puesto 13 sin permiso: denegación funcional (403 FUNCTION_PERMISSION_DENIED).
 * 3. Puesto 6: estación propia.
 * 4. Puesto 6: otra estación denegada.
 * 5. Puesto 6 con multiestación general: no obtiene estaciones adicionales.
 * 6. Encargado Palo Solo: localidad 2.
 * 7. Encargado Palo Solo con autorización individual: localidad 9.
 * 8. Encargado Palo Solo sin autorización individual: localidad 9 denegada.
 * 9. Otros encargados: Autolavado denegado.
 * 10. Puestos 3 y 14: sin alcance global automático.
 * 11. Usuario inactivo: denegado (401 UNAUTHORIZED).
 * 12. Mapeo duplicado: denegado fail-closed.
 * 13. Manipulación de workplace_id: denegada (403 WORKPLACE_SCOPE_DENIED).
 * 14. Operaciones administrativas sin permiso dedicado: denegadas.
 * 15. Ausencia de columnas departamentales: comportamiento fail-closed.
 */

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : 'kimera';
$jwtSecret = 'test_jwt_secret_v7c13_policy_tests_48372';

$dbName = 'checador_v2_tmp_auth_policy_' . getmypid() . '_' . time();

$admin = null;
$db = null;
$passedCount = 0;
$failedCount = 0;

function log_pol(string $msg): void { echo $msg . "\n"; }

function assert_pol(bool $condition, string $id, string $desc): void {
    global $passedCount, $failedCount;
    if ($condition) {
        $passedCount++;
        log_pol("[$id] PASS: $desc");
    } else {
        $failedCount++;
        log_pol("[$id] FAIL: $desc");
    }
}

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function create_jwt(array $customPayload, string $secret): string {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $defaultPayload = [
        'iss' => 'portal3_auth',
        'sub' => 1300,
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
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $phpCgi = trim((string)shell_exec('which php-cgi 2>/dev/null'));
    if ($phpCgi === '') {
        $phpCgi = '/usr/bin/php-cgi';
    }

    $cmd = $envPrefix . escapeshellcmd($phpCgi);
    $process = proc_open($cmd, $descriptorSpec, $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException("Failed to spawn php-cgi process for $uri");
    }

    if ($body !== '') {
        fwrite($pipes[0], $body);
    }
    fclose($pipes[0]);

    $rawOutput = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $rawError = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    proc_close($process);

    $parts = explode("\r\n\r\n", $rawOutput, 2);
    if (count($parts) < 2) {
        $parts = explode("\n\n", $rawOutput, 2);
    }
    $rawHeaders = $parts[0] ?? '';
    $rawBody = $parts[1] ?? '';

    $statusLine = '';
    $headersMap = [];
    foreach (explode("\n", $rawHeaders) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, 'Status:') === 0) {
            $statusLine = $line;
        } elseif (strpos($line, ':') !== false) {
            [$hk, $hv] = explode(':', $line, 2);
            $headersMap[strtolower(trim($hk))] = trim($hv);
        }
    }

    $statusCode = 200;
    if ($statusLine !== '') {
        if (preg_match('/Status:\s*(\d{3})/i', $statusLine, $m)) {
            $statusCode = (int)$m[1];
        }
    }

    $json = json_decode($rawBody, true);

    return [
        'code' => $statusCode,
        'headers' => $headersMap,
        'body' => $rawBody,
        'json' => $json,
        'stderr' => $rawError
    ];
}

try {
    log_pol("=== CHECADOR V2 OPERATIONAL AUTHORIZATION POLICY SUITE (POL01-POL15) ===");

    $admin = new mysqli($host, $user, $pass);
    $admin->query("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($host, $user, $pass, $dbName);
    log_pol("TEST DATABASE: $dbName");

    // 1. Schema Baseline
    $db->query("CREATE TABLE tb_puestos (id INT PRIMARY KEY)");
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
        tipo VARCHAR(50) NOT NULL DEFAULT 'stations_only',
        estaciones_soportadas TEXT,
        departamentos_soportados TEXT NULL,
        tipo_departamento VARCHAR(50) DEFAULT 'localidades',
        allow_all INT DEFAULT 0,
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
        leer INT DEFAULT 0,
        crear INT DEFAULT 0,
        editar INT DEFAULT 0,
        eliminar INT DEFAULT 0,
        descargar INT DEFAULT 0
    )");
    $db->query("CREATE TABLE tb_estaciones (
        id INT PRIMARY KEY AUTO_INCREMENT,
        numlista INT DEFAULT 0,
        nombre VARCHAR(100) DEFAULT '',
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
        departamentos_localidades TEXT NULL,
        activo INT DEFAULT 1
    )");
    $db->query("CREATE TABLE tb_multiestacion_puesto (
        id INT PRIMARY KEY AUTO_INCREMENT,
        id_puesto INT NOT NULL,
        estaciones TEXT,
        departamentos_localidades TEXT NULL,
        activo INT DEFAULT 1
    )");
    $db->query("CREATE TABLE op_rh_v2_localidades_zonas_horarias (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_localidad INT(11) NOT NULL,
        timezone_iana VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        valido_desde DATE NOT NULL,
        valido_hasta DATE NULL DEFAULT NULL,
        registrado_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // 2. Base Catalog Seeds
    // Puestos: 3 (Encargado Turno), 6 (Encargado), 13 (Dirección), 14 (Puesto 14), 99 (Otro)
    $db->query("INSERT INTO tb_puestos (id) VALUES (3), (6), (13), (14), (99)");

    // Módulos funcionales
    $db->query("INSERT INTO modulos_dpto_operativo (id, nombre, clave, activo) VALUES
        (1, 'Biométricos y Asistencia', 'biometricos', 1),
        (2, 'Incidencias Checador V2', 'checador-v2-incidencias', 1)");

    // Configuración de módulos: estaciones soportadas = [101, 102, 104, 111], departamentos_soportados = [9]
    $db->query("INSERT INTO tb_modulos_config (id, modulo_key, tipo, estaciones_soportadas, departamentos_soportados, tipo_departamento, allow_all, activo) VALUES
        (1, 'biometricos', 'stations_and_departments', '[101,102,104,111]', '[9]', 'localidades', 0, 1),
        (2, 'checador-v2-incidencias', 'stations_and_departments', '[101,102,104,111]', '[9]', 'localidades', 0, 1)");

    // Estaciones físicas (tb_estaciones):
    // 101 -> numlista 1 -> localidad 201
    // 102 -> numlista 2 -> localidad 202
    // 103 -> numlista 3 (INACTIVA estatus=1) -> localidad 203
    // 104 -> numlista 4 -> localidad 204
    // 111 -> numlista 7 -> localidad 2 (Palo Solo)
    // 106 -> numlista 6 -> localidad 206 (No soportada por Checador)
    $db->query("INSERT INTO tb_estaciones (id, numlista, nombre, estatus) VALUES
        (101, 1, 'Estacion Uno', 0),
        (102, 2, 'Estacion Dos', 0),
        (103, 3, 'Estacion Inactiva', 1),
        (104, 4, 'Estacion Cuatro', 0),
        (111, 7, 'Estacion Palo Solo', 0),
        (106, 6, 'Estacion Fuera', 0)");

    // Localidades canónicas (op_rh_localidades):
    $db->query("INSERT INTO op_rh_localidades (id, numlista, localidad) VALUES
        (201, 1, 'Localidad Uno'),
        (202, 2, 'Localidad Dos'),
        (203, 3, 'Localidad Tres Inactiva'),
        (204, 4, 'Localidad Cuatro'),
        (2, 7, 'Palo Solo'),
        (206, 6, 'Localidad Fuera'),
        (9, NULL, 'Autolavado')");

    // Zonas horarias en localidades autorizadas
    $db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta) VALUES
        (201, 'America/Mexico_City', '2026-01-01', NULL),
        (202, 'America/Mexico_City', '2026-01-01', NULL),
        (204, 'America/Mexico_City', '2026-01-01', NULL),
        (2, 'America/Mexico_City', '2026-01-01', NULL),
        (9, 'America/Mexico_City', '2026-01-01', NULL)");

    // Usuarios de prueba
    $db->query("INSERT INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES
        (1300, 'direccion_op', 101, 13, 0),
        (1301, 'direccion_sin_permiso', 101, 13, 0),
        (600, 'encargado_estacion1', 101, 6, 0),
        (470, 'encargado_estacion4', 104, 6, 0),
        (650, 'encargado_palosolo_sin_auto', 111, 6, 0),
        (651, 'encargado_palosolo_con_auto', 111, 6, 0),
        (300, 'puesto3_turno', 101, 3, 0),
        (1400, 'puesto14_usr', 101, 14, 0),
        (900, 'usuario_inactivo', 101, 6, 1)");

    // Permisos por puesto:
    // Puesto 13 (Dirección) tiene biometricos.leer = 1 por puesto
    $db->query("INSERT INTO modulos_puestos_do (id_modulo, id_puesto, leer, crear, editar, eliminar, descargar) VALUES
        (1, 13, 1, 0, 0, 0, 0),
        (1, 6, 1, 0, 0, 0, 0),
        (1, 3, 1, 0, 0, 0, 0),
        (1, 14, 1, 0, 0, 0, 0)");

    // Override para Dirección sin permiso (id=1301): direct grant leer=0
    $db->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer) VALUES (1, 1301, 0)");

    // Puesto 13 departamentos_localidades en puesto
    $db->query("INSERT INTO tb_multiestacion_puesto (id_puesto, estaciones, departamentos_localidades, activo) VALUES
        (13, '[101,102]', '[9]', 1),
        (6, '[101,102,104]', '[9]', 1),
        (3, '[101,102,104]', NULL, 1),
        (14, '[101,102,104]', NULL, 1)");

    // Encargado 470 tiene multiestación general con [101,102,103] y departamentos_localidades = [9]
    $db->query("INSERT INTO tb_multiestacion_usuario (id_usuario, estaciones, departamentos_localidades, activo) VALUES
        (470, '[101,102,103]', '[9]', 1)");

    // Encargado Palo Solo 651 tiene regla individual para Autolavado [9]
    $db->query("INSERT INTO tb_multiestacion_usuario (id_usuario, estaciones, departamentos_localidades, activo) VALUES
        (651, '[101,104]', '[9]', 1)");

    // Encargado Palo Solo 650 NO tiene regla individual para Autolavado

    require_once __DIR__ . '/../modelo/v2/Portal3JwtAuthHelper.php';
    Portal3JwtAuthHelper::clearCache();

    // -------------------------------------------------------------------------
    // POL01: Puesto 13 con permiso -> Todas las estaciones autorizadas (201, 202, 204, 2, 9)
    // -------------------------------------------------------------------------
    $jwt1300 = "Bearer " . create_jwt(['sub' => 1300], $jwtSecret);
    $res1300 = make_cgi_request('GET', '/api/v2/workplaces/201/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt1300]);
    $res1300b = make_cgi_request('GET', '/api/v2/workplaces/202/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt1300]);
    $res1300c = make_cgi_request('GET', '/api/v2/workplaces/204/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt1300]);
    $res1300d = make_cgi_request('GET', '/api/v2/workplaces/9/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt1300]);
    $pass01 = $res1300['code'] === 200 && $res1300b['code'] === 200 && $res1300c['code'] === 200 && $res1300d['code'] === 200;
    assert_pol($pass01, 'POL01', 'Puesto 13 con permiso obtiene estaciones soportadas activas y Autolavado explícito');

    // -------------------------------------------------------------------------
    // POL02: Puesto 13 sin permiso -> 403 FUNCTION_PERMISSION_DENIED
    // -------------------------------------------------------------------------
    $jwt1301 = "Bearer " . create_jwt(['sub' => 1301], $jwtSecret);
    $res1301 = make_cgi_request('GET', '/api/v2/workplaces/201/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt1301]);
    $pass02 = $res1301['code'] === 403 && ($res1301['json']['status'] ?? '') === 'FUNCTION_PERMISSION_DENIED';
    assert_pol($pass02, 'POL02', 'Puesto 13 sin permiso funcional efectivo es denegado con 403 FUNCTION_PERMISSION_DENIED');

    // -------------------------------------------------------------------------
    // POL03: Puesto 6 -> Estación propia autorizada
    // -------------------------------------------------------------------------
    $jwt600 = "Bearer " . create_jwt(['sub' => 600], $jwtSecret);
    $res600 = make_cgi_request('GET', '/api/v2/workplaces/201/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt600]);
    $pass03 = $res600['code'] === 200 && ($res600['json']['status'] ?? '') === 'OK';
    assert_pol($pass03, 'POL03', 'Puesto 6 accede exitosamente a su estación física propia mapeada a localidad canónica 201');

    // -------------------------------------------------------------------------
    // POL04: Puesto 6 -> Otra estación denegada
    // -------------------------------------------------------------------------
    $res600_other = make_cgi_request('GET', '/api/v2/workplaces/202/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt600]);
    $pass04 = $res600_other['code'] === 403 && ($res600_other['json']['status'] ?? '') === 'WORKPLACE_SCOPE_DENIED';
    assert_pol($pass04, 'POL04', 'Puesto 6 solicitando otra estación autorizada por el módulo es denegado con 403 WORKPLACE_SCOPE_DENIED');

    // -------------------------------------------------------------------------
    // POL05: Puesto 6 con multiestación general -> NO obtiene estaciones adicionales
    // -------------------------------------------------------------------------
    $jwt470 = "Bearer " . create_jwt(['sub' => 470], $jwtSecret);
    // User 470 home station 104 -> locality 204. Has multiestacion '[101,102,103]'.
    $res470_own = make_cgi_request('GET', '/api/v2/workplaces/204/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt470]);
    $res470_cross = make_cgi_request('GET', '/api/v2/workplaces/201/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt470]);
    $pass05 = $res470_own['code'] === 200 && $res470_cross['code'] === 403 && ($res470_cross['json']['status'] ?? '') === 'WORKPLACE_SCOPE_DENIED';
    assert_pol($pass05, 'POL05', 'Puesto 6 con multiestación general no expande su alcance; accede solo a su estación propia 204');

    // -------------------------------------------------------------------------
    // POL06: Encargado Palo Solo -> Localidad 2 autorizada
    // -------------------------------------------------------------------------
    $jwt650 = "Bearer " . create_jwt(['sub' => 650], $jwtSecret);
    $res650_own = make_cgi_request('GET', '/api/v2/workplaces/2/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt650]);
    $pass06 = $res650_own['code'] === 200 && ($res650_own['json']['data']['workplace_id'] ?? 0) === 2;
    assert_pol($pass06, 'POL06', 'Encargado Palo Solo accede a localidad canónica 2 correspondiente a su estación 111');

    // -------------------------------------------------------------------------
    // POL07: Encargado Palo Solo con autorización individual -> Localidad 9 (Autolavado) autorizada
    // -------------------------------------------------------------------------
    $jwt651 = "Bearer " . create_jwt(['sub' => 651], $jwtSecret);
    $res651_auto = make_cgi_request('GET', '/api/v2/workplaces/9/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt651]);
    $pass07 = $res651_auto['code'] === 200 && ($res651_auto['json']['data']['workplace_id'] ?? 0) === 9;
    assert_pol($pass07, 'POL07', 'Encargado Palo Solo con autorización departamental individual accede a localidad 9 (Autolavado)');

    // -------------------------------------------------------------------------
    // POL08: Encargado Palo Solo sin autorización individual -> Localidad 9 denegada
    // -------------------------------------------------------------------------
    $res650_auto = make_cgi_request('GET', '/api/v2/workplaces/9/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt650]);
    $pass08 = $res650_auto['code'] === 403 && ($res650_auto['json']['status'] ?? '') === 'WORKPLACE_SCOPE_DENIED';
    assert_pol($pass08, 'POL08', 'Encargado Palo Solo sin autorización individual es denegado en localidad 9 (no hereda del puesto)');

    // -------------------------------------------------------------------------
    // POL09: Otros encargados (no Palo Solo) -> Autolavado denegado aun con regla individual
    // -------------------------------------------------------------------------
    // User 470 has departamentos_localidades = '[9]' in tb_multiestacion_usuario, but is station 104 (not Palo Solo)
    $res470_auto = make_cgi_request('GET', '/api/v2/workplaces/9/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt470]);
    $pass09 = $res470_auto['code'] === 403 && ($res470_auto['json']['status'] ?? '') === 'WORKPLACE_SCOPE_DENIED';
    assert_pol($pass09, 'POL09', 'Encargado que no es de Palo Solo tiene denegado Autolavado aunque tenga regla individual');

    // -------------------------------------------------------------------------
    // POL10: Puestos 3 y 14 -> Sin alcance global automático
    // -------------------------------------------------------------------------
    $jwt300 = "Bearer " . create_jwt(['sub' => 300], $jwtSecret);
    $jwt1400 = "Bearer " . create_jwt(['sub' => 1400], $jwtSecret);
    $res300_own = make_cgi_request('GET', '/api/v2/workplaces/201/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt300]);
    $res300_cross = make_cgi_request('GET', '/api/v2/workplaces/202/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt300]);
    $res1400_own = make_cgi_request('GET', '/api/v2/workplaces/201/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt1400]);
    $res1400_cross = make_cgi_request('GET', '/api/v2/workplaces/202/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt1400]);
    $pass10 = $res300_own['code'] === 200 && $res300_cross['code'] === 403
        && $res1400_own['code'] === 200 && $res1400_cross['code'] === 403;
    assert_pol($pass10, 'POL10', 'Puestos 3 y 14 no heredan multiestación global; limitados estrictamente a su propia estación');

    // -------------------------------------------------------------------------
    // POL11: Usuario inactivo (estatus = 1) -> 401 UNAUTHORIZED
    // -------------------------------------------------------------------------
    $jwt900 = "Bearer " . create_jwt(['sub' => 900], $jwtSecret);
    $res900 = make_cgi_request('GET', '/api/v2/workplaces/201/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt900]);
    $pass11 = $res900['code'] === 401 && ($res900['json']['status'] ?? '') === 'UNAUTHORIZED';
    assert_pol($pass11, 'POL11', 'Usuario con estatus=1 es denegado en la capa de autenticación JWT (401 UNAUTHORIZED)');

    // -------------------------------------------------------------------------
    // POL12: Mapeo duplicado -> Denegado fail-closed
    // -------------------------------------------------------------------------
    // Insert duplicate numlista in tb_estaciones for station 199 with numlista 1 (already on station 101)
    $db->query("INSERT INTO tb_estaciones (id, numlista, nombre, estatus) VALUES (199, 1, 'Estacion Duplicada Numlista', 0)");
    $resDup = make_cgi_request('GET', '/api/v2/workplaces/201/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt600]);
    $pass12 = $resDup['code'] === 403;
    assert_pol($pass12, 'POL12', 'Mapeo con numlista duplicada falla cerrado (403 WORKPLACE_SCOPE_DENIED)');
    $db->query("DELETE FROM tb_estaciones WHERE id = 199");

    // -------------------------------------------------------------------------
    // POL13: Manipulación de workplace_id fuera de alcance -> 403 WORKPLACE_SCOPE_DENIED
    // -------------------------------------------------------------------------
    $resTamper = make_cgi_request('GET', '/api/v2/workplaces/206/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt600]);
    $pass13 = $resTamper['code'] === 403 && ($resTamper['json']['status'] ?? '') === 'WORKPLACE_SCOPE_DENIED';
    assert_pol($pass13, 'POL13', 'Manipulación de parámetro workplace_id a localidad no autorizada retorna 403 WORKPLACE_SCOPE_DENIED');

    // -------------------------------------------------------------------------
    // POL14: Operaciones administrativas sin permiso dedicado -> 403 FUNCTION_PERMISSION_DENIED
    // -------------------------------------------------------------------------
    // Puesto 13 has biometricos.leer, but NOT checador-v2-incidencias.crear
    $incidencePayload = json_encode([
        'id_personal' => 558,
        'fecha' => '2026-10-22',
        'tipo' => 'RETARDO',
        'motivo' => 'Prueba administrativa'
    ]);
    $resAdmin = make_cgi_request('POST', '/api/v2/incidences', [
        'HTTP_AUTHORIZATION' => $jwt1300,
        'CONTENT_TYPE' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => 'idem-pol14-test-key'
    ], $incidencePayload);
    $pass14 = $resAdmin['code'] === 403 && (($resAdmin['json']['error'] ?? '') === 'FUNCTION_PERMISSION_DENIED' || ($resAdmin['json']['status'] ?? '') === 'FUNCTION_PERMISSION_DENIED');
    assert_pol($pass14, 'POL14', 'Operaciones administrativas de incidencias requieren permiso dedicado; lectura de Checador no confiere crear');

    // -------------------------------------------------------------------------
    // POL15: Ausencia de columnas departamentales -> Comportamiento fail-closed
    // -------------------------------------------------------------------------
    // Drop departmental columns and verify no SQL fatal error, failing closed on department scope
    $db->query("ALTER TABLE tb_multiestacion_usuario DROP COLUMN departamentos_localidades");
    $db->query("ALTER TABLE tb_multiestacion_puesto DROP COLUMN departamentos_localidades");
    $db->query("ALTER TABLE tb_modulos_config DROP COLUMN departamentos_soportados");
    Portal3JwtAuthHelper::clearCache();

    $resMissingColsDept = make_cgi_request('GET', '/api/v2/workplaces/9/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt1300]);
    $resMissingColsStation = make_cgi_request('GET', '/api/v2/workplaces/201/timezone?date=2026-10-12', ['HTTP_AUTHORIZATION' => $jwt1300]);
    $pass15 = $resMissingColsDept['code'] === 403
        && ($resMissingColsDept['json']['status'] ?? '') === 'WORKPLACE_SCOPE_DENIED'
        && $resMissingColsStation['code'] === 200;
    assert_pol($pass15, 'POL15', 'Ausencia de columnas departamentales opera en modo fail-closed para departamentos sin afectar estaciones');

} finally {
    if ($admin !== null && $dbName !== '') {
        $admin->query("DROP DATABASE IF EXISTS `$dbName`");
        log_pol("DISPOSABLE_DB: REMOVED ($dbName)");
    }
}

log_pol("\n=== TEST RESULTS SUMMARY ===");
log_pol("TOTAL TESTS : " . ($passedCount + $failedCount));
log_pol("PASSED      : " . $passedCount);
log_pol("FAILED      : " . $failedCount);

exit($failedCount === 0 ? 0 : 1);
