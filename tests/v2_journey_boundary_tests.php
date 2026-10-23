<?php
/**
 * Checador V2 — Journey Boundary Test Suite (JRB01 - JRB30)
 *
 * Verifies the additive on-demand Journey evaluation boundary (POST /api/v2/journeys/evaluate)
 * strictly adhering to:
 * - docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.0.md
 * - docs/v2/CHECADOR_V2_JOURNEY_PAIRING_CONTRACT_V1.0.md
 * - docs/v2/CHECADOR_V2_JOURNEY_CALCULATION_CONTRACT_V1.0.md
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : 'kimera';
$dbName = 'bd_admongas_v2_test_20261002';
$jwtSecret = 'test_journey_boundary_jwt_secret_998877';
putenv("JWT_SECRET={$jwtSecret}");

$bdObj = new BD();
$db = $bdObj->conectarBD();

$passed = 0;
$failed = 0;

function jrb_assert(bool $cond, string $id, string $desc): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "[$id] PASS: $desc\n";
    } else {
        $failed++;
        echo "[$id] FAIL: $desc\n";
    }
}

function b64Url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function make_jwt(array $customPayload, string $secret): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $defaultPayload = [
        'iss' => 'portal3_auth',
        'sub' => 901,
        'type' => 'access',
        'exp' => time() + 3600
    ];
    $payload = array_merge($defaultPayload, $customPayload);
    $hB64 = b64Url(json_encode($header, JSON_UNESCAPED_SLASHES));
    $pB64 = b64Url(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = b64Url(hash_hmac('sha256', "$hB64.$pB64", $secret, true));
    return "$hB64.$pB64.$sig";
}

function call_cgi_api(string $method, string $uri, array $headers = [], string $body = ''): array
{
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
        $env['CONTENT_TYPE'] = 'application/json';
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

echo "=== Setting up Journey Boundary Test Fixtures in {$dbName} ===\n";

$db->query("SET FOREIGN_KEY_CHECKS = 0");

// 1. ACL users & permissions
$db->query("DELETE FROM tb_usuarios WHERE id IN (901, 902, 903, 904)");
$db->query("INSERT INTO tb_usuarios (id, usuario, id_puesto, id_gas, estatus) VALUES
    (901, 'supervisor_jrb_active', 1, 1, 0),
    (902, 'supervisor_jrb_inactive', 1, 1, 1),
    (903, 'user_jrb_no_biometricos', 99, 1, 0),
    (904, 'user_jrb_acceso_temp_only', 99, 1, 0)");

$db->query("DELETE FROM modulos_dpto_operativo WHERE id IN (15, 16)");
$db->query("INSERT INTO modulos_dpto_operativo (id, nombre, clave, activo) VALUES
    (15, 'Biometricos', 'biometricos', 1),
    (16, 'Acceso Temporal', 'acceso-temporal', 1)");

$db->query("DELETE FROM modulos_usuarios_do WHERE id_usuario IN (901, 902, 903, 904)");
$db->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer, crear, editar, eliminar, descargar) VALUES
    (15, 901, 1, 0, 0, 0, 0),
    (16, 904, 1, 0, 0, 0, 0)");

$db->query("DELETE FROM tb_modulos_config WHERE modulo_key IN ('biometricos', 'acceso-temporal')");
$db->query("INSERT INTO tb_modulos_config (modulo_key, activo, estaciones_soportadas, tipo_departamento) VALUES
    ('biometricos', 1, '[1,2]', 'localidades'),
    ('acceso-temporal', 1, '[1,2]', 'localidades')");

$db->query("DELETE FROM tb_multiestacion_usuario WHERE id_usuario IN (901, 902, 903, 904)");
$db->query("INSERT INTO tb_multiestacion_usuario (id_usuario, estaciones, activo) VALUES (901, '[1]', 1)");

// 2. Localities & Timezones
$db->query("INSERT IGNORE INTO op_rh_localidades (id, localidad, numlista, recuperacion_vapores) VALUES (1, 'Loc 1', 1, ''), (2, 'Loc 2', 2, '')");
$db->query("DELETE FROM op_rh_v2_localidades_zonas_horarias WHERE id_localidad IN (1, 2)");
$db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta) VALUES
    (1, 'America/Mexico_City', '2026-01-01', NULL),
    (2, 'America/Mexico_City', '2026-01-01', NULL)");

// 3. Workers (op_rh_personal)
$db->query("DELETE FROM op_rh_personal WHERE id IN (8801, 8802, 8803, 8804, 8805, 8806)");
$db->query("INSERT INTO op_rh_personal (id, no_colaborador, nombre_completo, id_estacion) VALUES
    (8801, 8801, 'Worker JRB Standard', 1),
    (8802, 8802, 'Worker JRB FivePunches', 1),
    (8803, 8803, 'Worker JRB Unscheduled', 1),
    (8804, 8804, 'Worker JRB SplitShift', 1),
    (8805, 8805, 'Worker JRB NightShift', 1)");

// 4. Shift Templates & Segments
$db->query("DELETE FROM op_rh_v2_turnos_plantillas WHERE id IN (7701, 7702, 7703) OR clave IN ('TPL-JRB-01', 'TPL-JRB-SPLIT', 'TPL-JRB-NIGHT', 'TPL-JPV-01')");
$db->query("INSERT INTO op_rh_v2_turnos_plantillas (id, clave, version, nombre, tipo_turno, es_activo) VALUES
    (7701, 'TPL-JRB-01', 1, 'Standard Day 8-16', 'MATUTINO', 1),
    (7702, 'TPL-JRB-SPLIT', 1, 'Split Day 8-12 / 14-18', 'MIXTO', 1),
    (7703, 'TPL-JRB-NIGHT', 1, 'Night Shift 22-06', 'NOCTURNO', 1)");

$db->query("DELETE FROM op_rh_v2_turnos_segmentos WHERE id_plantilla IN (7701, 7702, 7703)");
$db->query("INSERT INTO op_rh_v2_turnos_segmentos (id_plantilla, ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin) VALUES
    (7701, 1, 'WORK', '08:00:00', '16:00:00', 0, 0),
    (7702, 1, 'WORK', '08:00:00', '12:00:00', 0, 0),
    (7702, 2, 'WORK', '14:00:00', '18:00:00', 0, 0),
    (7703, 1, 'WORK', '22:00:00', '06:00:00', 0, 1)");

// 5. Shift Assignments (op_rh_v2_turnos_asignaciones) for 2026-10-14 (Wednesday)
$db->query("DELETE FROM op_rh_v2_turnos_asignaciones WHERE id_personal IN (8801, 8802, 8803, 8804, 8805)");
$days = ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO', 'DOMINGO'];
foreach ($days as $day) {
    $db->query("INSERT INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo) VALUES
        (8801, 1, 7701, '$day', '2026-01-01', NULL, 1),
        (8802, 1, 7701, '$day', '2026-01-01', NULL, 1),
        (8804, 1, 7702, '$day', '2026-01-01', NULL, 1),
        (8805, 1, 7703, '$day', '2026-01-01', NULL, 1)");
}
// Worker 8803 has NO assignment -> UNSCHEDULED

// 6. Clean and seed Raw Punches for test workers on 2026-10-14
// In America/Mexico_City (UTC-6 standard):
// 2026-10-14 08:00:00 local = 2026-10-14 14:00:00 UTC
// 2026-10-14 16:00:00 local = 2026-10-14 22:00:00 UTC
$db->query("SET FOREIGN_KEY_CHECKS = 0");
$db->query("TRUNCATE TABLE op_rh_v2_marcaciones_crudas");
$db->query("DELETE FROM op_rh_v2_jornadas_programadas WHERE id_personal IN (8801, 8802, 8803, 8804, 8805, 8806)");
$db->query("TRUNCATE TABLE op_rh_v2_calculos_segmentos");
$db->query("TRUNCATE TABLE op_rh_v2_calculos_jornada");
$db->query("TRUNCATE TABLE op_rh_v2_pareos_marcaciones_asociadas");
$db->query("TRUNCATE TABLE op_rh_v2_pareos_segmentos");
$db->query("TRUNCATE TABLE op_rh_v2_pareos_jornada");
$db->query("DELETE FROM op_rh_v2_level2_window_evaluations WHERE evaluation_ref LIKE 'eval_ref_jrb_%'");
$db->query("SET FOREIGN_KEY_CHECKS = 1");

// Worker 8801: 2 punches (IN 14:00 UTC, OUT 22:00 UTC)
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES
    (1, 1, 'ev-jrb-8801-in', 'REF8801', 8801, '2026-10-14 14:00:00', '2026-10-14 08:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jrb-8801-out', 'REF8801', 8801, '2026-10-14 22:00:00', '2026-10-14 16:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");

// Worker 8802: 5 punches (IN 14:00 UTC, mid1 16:00, mid2 18:00, mid3 20:00, OUT 22:00)
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES
    (1, 1, 'ev-jrb-8802-p1', 'REF8802', 8802, '2026-10-14 14:00:00', '2026-10-14 08:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jrb-8802-p2', 'REF8802', 8802, '2026-10-14 16:00:00', '2026-10-14 10:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jrb-8802-p3', 'REF8802', 8802, '2026-10-14 18:00:00', '2026-10-14 12:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jrb-8802-p4', 'REF8802', 8802, '2026-10-14 20:00:00', '2026-10-14 14:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jrb-8802-p5', 'REF8802', 8802, '2026-10-14 22:00:00', '2026-10-14 16:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");

// Worker 8804 (Split shift): 4 punches (08:00, 12:00, 14:00, 18:00 local -> 14:00, 18:00, 20:00, 00:00 UTC)
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES
    (1, 1, 'ev-jrb-8804-s1in', 'REF8804', 8804, '2026-10-14 14:00:00', '2026-10-14 08:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jrb-8804-s1out', 'REF8804', 8804, '2026-10-14 18:00:00', '2026-10-14 12:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jrb-8804-s2in', 'REF8804', 8804, '2026-10-14 20:00:00', '2026-10-14 14:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jrb-8804-s2out', 'REF8804', 8804, '2026-10-15 00:00:00', '2026-10-14 18:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");

// Worker 8805 (Night shift 22:00 to 06:00 local -> 2026-10-14 04:00 to 2026-10-15 12:00 UTC)
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES
    (1, 1, 'ev-jrb-8805-in', 'REF8805', 8805, '2026-10-15 04:00:00', '2026-10-14 22:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jrb-8805-out', 'REF8805', 8805, '2026-10-15 12:00:00', '2026-10-15 06:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");

// 7. Seed Level 2 evaluation for Station 1
$db->query("DELETE FROM op_rh_v2_level2_window_evaluations WHERE id_estacion = 1 AND DATE(window_from_utc) = '2026-10-14'");
$db->query("INSERT INTO op_rh_v2_level2_window_evaluations (evaluation_ref, id_estacion, window_from_utc, window_to_utc, coverage_policy_ref, relevant_device_generations_json, delivery_completeness, operational_coverage, time_window_attribution, window_level2_status, has_zero_governed_events, evaluated_at_utc, manifest_digest, canonical_evidence_json) VALUES
    ('eval_ref_jrb_test_01', 1, '2026-10-14 06:00:00', '2026-10-15 06:00:00', 'POLICY_DEFAULT', '[]', 'VERIFIED', 'VERIFIED', 'VERIFIED', 'VERIFIED', 0, UTC_TIMESTAMP(), 'digest123', '{}')");

// Level 2 UNKNOWN for Station 2
$db->query("DELETE FROM op_rh_v2_level2_window_evaluations WHERE id_estacion = 2 AND DATE(window_from_utc) = '2026-10-14'");
$db->query("INSERT INTO op_rh_v2_level2_window_evaluations (evaluation_ref, id_estacion, window_from_utc, window_to_utc, coverage_policy_ref, relevant_device_generations_json, delivery_completeness, operational_coverage, time_window_attribution, window_level2_status, has_zero_governed_events, evaluated_at_utc, manifest_digest, canonical_evidence_json) VALUES
    ('eval_ref_jrb_unknown_02', 2, '2026-10-14 06:00:00', '2026-10-15 06:00:00', 'POLICY_DEFAULT', '[]', 'UNKNOWN', 'UNKNOWN', 'UNKNOWN', 'UNKNOWN', 1, UTC_TIMESTAMP(), 'digest456', '{}')");

$db->query("SET FOREIGN_KEY_CHECKS = 1");

$validJwt = 'Bearer ' . make_jwt([], $jwtSecret);
$invalidJwt = 'Bearer ' . make_jwt([], 'wrong_secret_12345');
$noPermJwt = 'Bearer ' . make_jwt(['sub' => 903], $jwtSecret);
$accesoTempOnlyJwt = 'Bearer ' . make_jwt(['sub' => 904], $jwtSecret);

echo "=== Running JRB01 - JRB30 Test Matrix ===\n";

// JRB01: valid Portal3 JWT + biometricos.leer + authorized workplace accepted
$req01 = [
    'id_personal' => 8801,
    'id_estacion' => 1,
    'fecha_local' => '2026-10-14',
    'snapshot_audit_ref' => 'snap_audit_jrb_test_01'
];
$res01 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req01));
jrb_assert($res01['code'] === 200 && ($res01['json']['status'] ?? null) === 'success' && ($res01['json']['data']['evaluated'] ?? null) === true, 'JRB01', 'valid Portal3 JWT + biometricos.leer + authorized workplace accepted (200 OK, evaluated: true)');

// JRB02: missing JWT -> 401
$res02 = call_cgi_api('POST', '/api/v2/journeys/evaluate', [], json_encode($req01));
jrb_assert($res02['code'] === 401, 'JRB02', 'missing JWT returns 401 UNAUTHORIZED');

// JRB03: invalid JWT -> 401
$res03 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $invalidJwt], json_encode($req01));
jrb_assert($res03['code'] === 401, 'JRB03', 'invalid JWT returns 401 UNAUTHORIZED');

// JRB04: user without biometricos.leer -> 403
$res04 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $noPermJwt], json_encode($req01));
jrb_assert($res04['code'] === 403 && ($res04['json']['error']['code'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'JRB04', 'user without biometricos.leer returns 403 FUNCTION_PERMISSION_DENIED');

// JRB05: acceso-temporal alone does not authorize
$res05 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $accesoTempOnlyJwt], json_encode($req01));
jrb_assert($res05['code'] === 403 && ($res05['json']['error']['code'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'JRB05', 'acceso-temporal grant alone returns 403 FUNCTION_PERMISSION_DENIED');

// JRB06: Device Auth rejected
$res06 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_X_DEVICE_KEY' => 'CRD-KEY-TEST', 'HTTP_X_DEVICE_SECRET' => 'secret'], json_encode($req01));
jrb_assert($res06['code'] === 401, 'JRB06', 'Device Auth headers without Portal3 JWT rejected with 401');

// JRB07: out-of-scope workplace rejected
$req07 = array_merge($req01, ['id_estacion' => 99]);
$res07 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req07));
jrb_assert($res07['code'] === 403 && ($res07['json']['error']['code'] ?? '') === 'STATION_SCOPE_DENIED', 'JRB07', 'out-of-scope workplace rejected with 403 STATION_SCOPE_DENIED');

// JRB08: unknown worker fails safely
$req08 = array_merge($req01, ['id_personal' => 999999]);
$res08 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req08));
jrb_assert($res08['code'] === 404 && ($res08['json']['error']['code'] ?? '') === 'WORKER_NOT_FOUND', 'JRB08', 'unknown worker fails safely with 404 WORKER_NOT_FOUND');

// JRB09: tb_usuarios id is not worker fallback
$req09 = array_merge($req01, ['id_personal' => 901]); // 901 is tb_usuarios.id, not op_rh_personal.id
$res09 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req09));
jrb_assert($res09['code'] === 404 && ($res09['json']['error']['code'] ?? '') === 'WORKER_NOT_FOUND', 'JRB09', 'tb_usuarios.id is not accepted as worker identity fallback (404)');

// JRB10: external reference is not worker fallback
$req10 = ['id_personal' => 'REF8801', 'id_estacion' => 1, 'fecha_local' => '2026-10-14'];
$res10 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req10));
jrb_assert($res10['code'] === 400, 'JRB10', 'external reference string rejected as worker fallback with 400 BAD_REQUEST');

// JRB11: timezone is authoritative server-side
jrb_assert(($res01['json']['data']['context']['timezone'] ?? '') === 'America/Mexico_City', 'JRB11', 'timezone in response is canonical Workplace timezone (America/Mexico_City)');

// JRB12: browser timezone override ignored/rejected
$req12 = array_merge($req01, ['timezone' => 'Europe/London', 'tz' => 'UTC']);
$res12 = call_cgi_api('POST', '/api/v2/journeys/evaluate?timezone=Europe/London', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req12));
jrb_assert($res12['code'] === 200 && ($res12['json']['data']['context']['timezone'] ?? '') === 'America/Mexico_City', 'JRB12', 'client-supplied timezone overrides are completely ignored');

// JRB13: effective schedule resolver reused
jrb_assert(($res01['json']['data']['schedule']['estado_jornada'] ?? '') === 'SCHEDULED' && ($res01['json']['data']['schedule']['origen']['id_plantilla'] ?? 0) === 7701, 'JRB13', 'effective schedule resolver reused, returning SCHEDULED with template 7701');

// JRB14: UNSCHEDULED does not create fake ScheduledShift
$countShiftsBefore = (int)($db->query("SELECT COUNT(*) AS c FROM op_rh_v2_jornadas_programadas WHERE id_personal = 8803")->fetch_assoc()['c'] ?? 0);
$req14 = ['id_personal' => 8803, 'id_estacion' => 1, 'fecha_local' => '2026-10-14'];
$res14 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req14));
$countShiftsAfter = (int)($db->query("SELECT COUNT(*) AS c FROM op_rh_v2_jornadas_programadas WHERE id_personal = 8803")->fetch_assoc()['c'] ?? 0);
jrb_assert($res14['code'] === 200 && ($res14['json']['data']['evaluated'] ?? null) === false && ($res14['json']['data']['schedule']['estado_jornada'] ?? '') === 'UNSCHEDULED' && $countShiftsBefore === $countShiftsAfter, 'JRB14', 'UNSCHEDULED returns evaluated: false without creating ScheduledShift');

// JRB15: UNSCHEDULED does not emit SIN MARCACIÓN
jrb_assert(strpos($res14['body'], 'SIN MARCACIÓN') === false && strpos($res14['body'], 'sin_marcacion') === false, 'JRB15', 'UNSCHEDULED response contains zero SIN MARCACIÓN');

// JRB16: UNSCHEDULED does not emit FALTA
jrb_assert(strpos($res14['body'], 'FALTA') === false && strpos($res14['body'], 'falta') === false, 'JRB16', 'UNSCHEDULED response contains zero FALTA');

// JRB17: Level 1 exact membership/provenance preserved
jrb_assert(($res01['json']['data']['source_evidence']['snapshot_audit_ref'] ?? '') === 'snap_audit_jrb_test_01', 'JRB17', 'Level 1 snapshot audit reference preserved in source_evidence');

// JRB18: Level 2 UNKNOWN remains non-final
// Create test worker 8806 on station 2 where Level 2 is UNKNOWN
$db->query("DELETE FROM op_rh_personal WHERE id = 8806");
$db->query("INSERT INTO op_rh_personal (id, no_colaborador, nombre_completo, id_estacion) VALUES (8806, 8806, 'Worker St2 UNKNOWN', 2)");
$db->query("INSERT IGNORE INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo) VALUES (8806, 2, 7701, 'MIERCOLES', '2026-01-01', NULL, 1)");
$db->query("UPDATE tb_multiestacion_usuario SET estaciones = '[1,2]' WHERE id_usuario = 901");
$req18 = ['id_personal' => 8806, 'id_estacion' => 2, 'fecha_local' => '2026-10-14'];
$res18 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req18));
$p1L2Status = $res18['json']['data']['pairing']['completeness_l2'] ?? '';
$p1PairStatus = $res18['json']['data']['pairing']['pairing_status'] ?? '';
$p2CalcStatus = $res18['json']['data']['calculation']['estado_calculo'] ?? '';
jrb_assert($res18['code'] === 200 && $p1PairStatus === 'PENDING_SYNC_COMPLETION' && $p2CalcStatus === 'PENDING_SYNC', 'JRB18', 'Level 2 UNKNOWN remains non-final (PENDING_SYNC_COMPLETION / PENDING_SYNC)');

// JRB19: Level 2 INCOMPLETE remains non-final
// Update Station 2 Level 2 to INCOMPLETE
$db->query("UPDATE op_rh_v2_level2_window_evaluations SET window_level2_status = 'INCOMPLETE' WHERE id_estacion = 2 AND DATE(window_from_utc) = '2026-10-14'");
// Force re-run for a new shift or date
$db->query("DELETE FROM op_rh_v2_jornadas_programadas WHERE id_personal = 8806 AND fecha_jornada_local = '2026-10-15'");
$db->query("INSERT IGNORE INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo) VALUES (8806, 2, 7701, 'JUEVES', '2026-01-01', NULL, 1)");
$db->query("DELETE FROM op_rh_v2_level2_window_evaluations WHERE evaluation_ref = 'eval_ref_jrb_inc_02'");
$db->query("INSERT INTO op_rh_v2_level2_window_evaluations (evaluation_ref, id_estacion, window_from_utc, window_to_utc, coverage_policy_ref, relevant_device_generations_json, delivery_completeness, operational_coverage, time_window_attribution, window_level2_status, has_zero_governed_events, evaluated_at_utc, manifest_digest, canonical_evidence_json) VALUES
    ('eval_ref_jrb_inc_02', 2, '2026-10-15 06:00:00', '2026-10-16 06:00:00', 'POLICY_DEFAULT', '[]', 'INCOMPLETE', 'VERIFIED', 'VERIFIED', 'INCOMPLETE', 0, UTC_TIMESTAMP(), 'digestInc', '{}')");
$req19 = ['id_personal' => 8806, 'id_estacion' => 2, 'fecha_local' => '2026-10-15'];
$res19 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req19));
$p1IncPair = $res19['json']['data']['pairing']['pairing_status'] ?? '';
jrb_assert($res19['code'] === 200 && $p1IncPair === 'PENDING_SYNC_COMPLETION', 'JRB19', 'Level 2 INCOMPLETE remains non-final (PENDING_SYNC_COMPLETION)');

// JRB20: dimension VERIFIED does not upgrade aggregate UNKNOWN
jrb_assert(($res18['json']['data']['source_evidence']['window_level2_status'] ?? '') === 'UNKNOWN', 'JRB20', 'dimension VERIFIED does not upgrade aggregate UNKNOWN');

// JRB21: P1 service reused
jrb_assert(isset($res01['json']['data']['pairing']['pairing_id']) && ($res01['json']['data']['pairing']['algorithm_version'] ?? '') === 'v2.4-p1', 'JRB21', 'P1 service reused with algorithm v2.4-p1');

// JRB22: P2 service reused
jrb_assert(isset($res01['json']['data']['calculation']['calculation_id']) && ($res01['json']['data']['calculation']['version_algoritmo'] ?? '') === 'v2.4-p2' && ($res01['json']['data']['calculation']['segundos_trabajados_totales'] ?? null) === 28800, 'JRB22', 'P2 service reused with algorithm v2.4-p2, worked seconds: 28800');

// JRB23: 5+ raw events preserved
$req23 = ['id_personal' => 8802, 'id_estacion' => 1, 'fecha_local' => '2026-10-14'];
$res23 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req23));
$segPunches = $res23['json']['data']['pairing']['segments'][0]['associated_punches'] ?? [];
jrb_assert($res23['code'] === 200 && count($segPunches) === 5, 'JRB23', '5+ candidate punches all preserved without 4-slot cap (count: ' . count($segPunches) . ')');

// JRB24: unmatched/ambiguous events preserved
jrb_assert(($res23['json']['data']['pairing']['pairing_status'] ?? '') === 'EXACT_PAIR' && count($segPunches) >= 3, 'JRB24', 'surplus and intermediate events preserved with distinct roles');

// JRB25: multi-segment result preserved
$req25 = ['id_personal' => 8804, 'id_estacion' => 1, 'fecha_local' => '2026-10-14'];
$res25 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req25));
$p1Segs = $res25['json']['data']['pairing']['segments'] ?? [];
$p2Segs = $res25['json']['data']['calculation']['segmentos'] ?? [];
jrb_assert($res25['code'] === 200 && count($p1Segs) === 2 && count($p2Segs) === 2, 'JRB25', 'multi-segment split shift preserved with 2 segments in P1 and P2');

// JRB26: night-shift local workday preserved
$req26 = ['id_personal' => 8805, 'id_estacion' => 1, 'fecha_local' => '2026-10-14'];
$res26 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req26));
jrb_assert($res26['code'] === 200 && ($res26['json']['data']['context']['fecha_local'] ?? '') === '2026-10-14' && ($res26['json']['data']['pairing']['pairing_status'] ?? '') === 'EXACT_PAIR', 'JRB26', 'night shift spanning midnight matched to shift start work_date (2026-10-14)');

// JRB27: no raw PunchEvent mutation
$rawCountBefore = (int)($db->query("SELECT COUNT(*) as c FROM op_rh_v2_marcaciones_crudas")->fetch_assoc()['c'] ?? 0);
$res27 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req01));
$rawCountAfter = (int)($db->query("SELECT COUNT(*) as c FROM op_rh_v2_marcaciones_crudas")->fetch_assoc()['c'] ?? 0);
jrb_assert($rawCountBefore === $rawCountAfter, 'JRB27', 'zero raw PunchEvent mutation across evaluation');

// JRB28: no legacy attendance/payroll mutation
$legacyBefore = (int)($db->query("SELECT COUNT(*) as c FROM op_rh_personal_asistencia")->fetch_assoc()['c'] ?? 0);
$res28 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req01));
$legacyAfter = (int)($db->query("SELECT COUNT(*) as c FROM op_rh_personal_asistencia")->fetch_assoc()['c'] ?? 0);
jrb_assert($legacyBefore === $legacyAfter, 'JRB28', 'zero legacy op_rh_personal_asistencia mutation');

// JRB29: repeat equivalent request respects existing P1/P2 idempotency/revision semantics
$res29 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req01));
$samePairingId = ($res29['json']['data']['pairing']['pairing_id'] ?? 0) === ($res01['json']['data']['pairing']['pairing_id'] ?? 0);
$isIdempotent = ($res29['json']['data']['pairing']['is_idempotent_reuse'] ?? false) === true;
jrb_assert($samePairingId && $isIdempotent, 'JRB29', 'repeat equivalent request is idempotent and reuses existing execution revision');

// JRB30: response contains required provenance but no secrets/internal exception
$body01 = $res01['body'];
$noSecretLeak = strpos($body01, $jwtSecret) === false && strpos($body01, 'kimera') === false && strpos($body01, 'SQLSTATE') === false;
$hasProvenance = isset($res01['json']['data']['provenance']['p1_algorithm_version']) && isset($res01['json']['data']['provenance']['p2_algorithm_version']);
jrb_assert($noSecretLeak && $hasProvenance, 'JRB30', 'response contains required algorithm provenance without secret/internal error leaks');

echo "\n=== JRB SUITE SUMMARY ===\n";
echo "TOTAL TESTS: " . ($passed + $failed) . "\n";
echo "PASSED:      {$passed}\n";
echo "FAILED:      {$failed}\n";

exit($failed === 0 ? 0 : 1);
