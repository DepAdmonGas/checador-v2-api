<?php
/**
 * Checador V2 — P3G-A Read Boundary Test Suite (P3GA01 - P3GA32)
 *
 * Verifies the read-only administrative incidence and evaluation boundary
 * strictly adhering to:
 * - docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md
 * - docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : 'kimera';
$dbName = 'bd_admongas_v2_test_20261002';
$jwtSecret = 'test_p3ga_administrative_read_jwt_secret_998877';
putenv("JWT_SECRET={$jwtSecret}");

$bdObj = new BD();
$db = $bdObj->conectarBD();

$passed = 0;
$failed = 0;

function p3ga_assert(bool $cond, string $id, string $desc): void
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

function make_p3ga_jwt(array $customPayload, string $secret): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $defaultPayload = [
        'iss' => 'portal3_auth',
        'sub' => 951,
        'type' => 'access',
        'exp' => time() + 3600
    ];
    $payload = array_merge($defaultPayload, $customPayload);
    $hB64 = b64Url((string)json_encode($header, JSON_UNESCAPED_SLASHES));
    $pB64 = b64Url((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = b64Url((string)hash_hmac('sha256', "$hB64.$pB64", $secret, true));
    return "$hB64.$pB64.$sig";
}

function call_p3ga_api(string $method, string $uri, array $headers = [], string $body = ''): array
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

function get_p3ga_db_state_hash($db): string
{
    $tables = [
        'op_rh_v2_incidencias_maestro',
        'op_rh_v2_incidencias_revisiones',
        'op_rh_v2_incidencias_aplicaciones',
        'op_rh_v2_evaluaciones_administrativas',
        'op_rh_v2_evaluaciones_incidencias',
        'op_rh_v2_evaluaciones_ajustes',
        'op_rh_v2_marcaciones_crudas',
        'op_rh_v2_pareos_jornada',
        'op_rh_v2_pareos_segmentos',
        'op_rh_personal'
    ];
    $data = '';
    foreach ($tables as $t) {
        $res = $db->query("SELECT * FROM {$t} ORDER BY 1 ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $data .= $t . ':' . json_encode($row) . '|';
            }
        }
    }
    return hash('sha256', $data);
}

echo "=== Setting up P3G-A Read Boundary Test Fixtures in {$dbName} ===\n";

$db->query("SET FOREIGN_KEY_CHECKS = 0");

// 1. Users in tb_usuarios
$db->query("DELETE FROM tb_usuarios WHERE id IN (951, 952, 953, 954, 955, 956, 957, 958)");
$db->query("INSERT INTO tb_usuarios (id, usuario, id_puesto, id_gas, estatus) VALUES
    (951, 'usr_p3ga_incidencias_valid', 1, 1, 0),
    (952, 'usr_p3ga_inactive', 1, 1, 1),
    (953, 'usr_p3ga_no_perms', 1, 1, 0),
    (954, 'usr_p3ga_wrong_acl_incidencias', 1, 1, 0),
    (955, 'usr_p3ga_rh_alone', 1, 1, 0),
    (956, 'usr_p3ga_nomina_alone', 1, 1, 0),
    (957, 'usr_p3ga_biometricos_alone', 1, 1, 0),
    (958, 'usr_p3ga_empty_stations', 1, 1, 0)");

// 2. Functional Modules
$db->query("DELETE FROM modulos_dpto_operativo WHERE id IN (201, 202, 203, 204, 205)");
$db->query("INSERT INTO modulos_dpto_operativo (id, nombre, clave, activo) VALUES
    (201, 'Checador V2 Incidencias', 'checador-v2-incidencias', 1),
    (202, 'Incidencias General', 'incidencias', 1),
    (203, 'Recursos Humanos', 'recursos-humanos', 1),
    (204, 'Incidencias Nomina', 'incidencias-nomina', 1),
    (205, 'Biometricos', 'biometricos', 1)");

// 3. User module permissions (modulos_usuarios_do)
$db->query("DELETE FROM modulos_usuarios_do WHERE id_usuario IN (951, 952, 953, 954, 955, 956, 957, 958)");
$db->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer, crear, editar, eliminar, descargar) VALUES
    (201, 951, 1, 0, 0, 0, 0),
    (202, 954, 1, 0, 0, 0, 0),
    (203, 955, 1, 0, 0, 0, 0),
    (204, 956, 1, 0, 0, 0, 0),
    (205, 957, 1, 0, 0, 0, 0),
    (201, 958, 1, 0, 0, 0, 0)");

// 4. Module configs (tb_modulos_config)
$db->query("DELETE FROM tb_modulos_config WHERE modulo_key IN ('checador-v2-incidencias', 'incidencias', 'recursos-humanos', 'incidencias-nomina', 'biometricos')");
$db->query("INSERT INTO tb_modulos_config (modulo_key, activo, estaciones_soportadas, tipo_departamento) VALUES
    ('checador-v2-incidencias', 1, '[1,2]', 'localidades'),
    ('incidencias', 1, '[1,2]', 'localidades'),
    ('recursos-humanos', 1, '[1,2]', 'localidades'),
    ('incidencias-nomina', 1, '[1,2]', 'localidades'),
    ('biometricos', 1, '[1,2]', 'localidades')");

// 5. Workplace scopes (tb_multiestacion_usuario)
$db->query("DELETE FROM tb_multiestacion_usuario WHERE id_usuario IN (951, 952, 953, 954, 955, 956, 957, 958)");
$db->query("INSERT INTO tb_multiestacion_usuario (id_usuario, estaciones, activo) VALUES
    (951, '[1]', 1),
    (954, '[1]', 1),
    (955, '[1]', 1),
    (956, '[1]', 1),
    (957, '[1]', 1),
    (958, '[]', 1)");

// 6. Workers (op_rh_personal)
$db->query("DELETE FROM op_rh_personal WHERE id IN (8901, 8902)");
$db->query("INSERT INTO op_rh_personal (id, no_colaborador, nombre_completo, id_estacion) VALUES
    (8901, 8901, 'Worker Test P3G A', 1),
    (8902, 8902, 'Worker Test No Punch', 1)");

// 7. Clear prior test incidences and applications
$db->query("DELETE FROM op_rh_v2_evaluaciones_incidencias WHERE id_evaluacion_administrativa IN (5001, 5002)");
$db->query("DELETE FROM op_rh_v2_evaluaciones_ajustes WHERE id_evaluacion_administrativa IN (5001, 5002)");
$db->query("DELETE FROM op_rh_v2_incidencias_aplicaciones WHERE id IN (6001, 6002)");
$db->query("DELETE FROM op_rh_v2_incidencias_revisiones WHERE id_incidencia_maestro IN (4001, 4002, 4003, 4004)");
$db->query("DELETE FROM op_rh_v2_incidencias_maestro WHERE id IN (4001, 4002, 4003, 4004)");
$db->query("DELETE FROM op_rh_v2_ajustes_administrativos WHERE id IN (7001)");

// 8. Seed test incidences:
// Incidence 1: Vacation with 2 revisions (deterministic order ASC)
$db->query("INSERT INTO op_rh_v2_incidencias_maestro (id, uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual, creado_at_utc, actualizado_at_utc) VALUES
    (4001, 'inc-uuid-p3ga-001', 8901, 1, 'VACATION', 'FULL_DAY', '2026-10-15', '2026-10-16', 'AUTHORIZED', 2, '2026-10-10 10:00:00', '2026-10-11 10:00:00')");
$db->query("INSERT INTO op_rh_v2_incidencias_revisiones (id, id_incidencia_maestro, revision_numero, estado_revision, motivo, folio_evidencia, creado_por_usuario_id, aprobado_por_usuario_id, creado_at_utc) VALUES
    (4101, 4001, 1, 'SUBMITTED', 'Solicitud vacaciones 2 dias', 'FOL-VAC-001', 951, NULL, '2026-10-10 10:00:00'),
    (4102, 4001, 2, 'AUTHORIZED', 'Aprobadas vacaciones por RH', 'FOL-VAC-001', 951, 951, '2026-10-11 10:00:00')");

// Incidence 2: Revoked / cancelled incident preserved in history
$db->query("INSERT INTO op_rh_v2_incidencias_maestro (id, uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual, creado_at_utc, actualizado_at_utc) VALUES
    (4002, 'inc-uuid-p3ga-002-revoked', 8901, 1, 'PERMISSION', 'FULL_DAY', '2026-10-20', '2026-10-20', 'REVOKED', 2, '2026-10-12 08:00:00', '2026-10-13 08:00:00')");
$db->query("INSERT INTO op_rh_v2_incidencias_revisiones (id, id_incidencia_maestro, revision_numero, estado_revision, motivo, folio_evidencia, creado_por_usuario_id, aprobado_por_usuario_id, creado_at_utc) VALUES
    (4103, 4002, 1, 'AUTHORIZED', 'Permiso para tramite personal', 'FOL-PERM-001', 951, 951, '2026-10-12 08:00:00'),
    (4104, 4002, 2, 'REVOKED', 'Cancelado por solicitud del colaborador', 'FOL-PERM-001', 951, 951, '2026-10-13 08:00:00')");

// Incidence 3: Overlapping incident (same date 2026-10-15 as inc 1)
$db->query("INSERT INTO op_rh_v2_incidencias_maestro (id, uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual, creado_at_utc, actualizado_at_utc) VALUES
    (4003, 'inc-uuid-p3ga-003-overlap', 8901, 1, 'ADMINISTRATIVE_JUSTIFICATION', 'FULL_DAY', '2026-10-15', '2026-10-15', 'AUTHORIZED', 1, '2026-10-14 09:00:00', '2026-10-14 09:00:00')");
$db->query("INSERT INTO op_rh_v2_incidencias_revisiones (id, id_incidencia_maestro, revision_numero, estado_revision, motivo, folio_evidencia, creado_por_usuario_id, aprobado_por_usuario_id, creado_at_utc) VALUES
    (4105, 4003, 1, 'AUTHORIZED', 'Justificacion administrativa curso obligatorio', 'FOL-ADM-99', 951, 951, '2026-10-14 09:00:00')");

// Incidence 4: REVIEW_REQUIRED status
$db->query("INSERT INTO op_rh_v2_incidencias_maestro (id, uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual, creado_at_utc, actualizado_at_utc) VALUES
    (4004, 'inc-uuid-p3ga-004-review', 8901, 1, 'DISABILITY', 'FULL_DAY', '2026-10-25', '2026-10-26', 'REVIEW_REQUIRED', 1, '2026-10-14 11:00:00', '2026-10-14 11:00:00')");
$db->query("INSERT INTO op_rh_v2_incidencias_revisiones (id, id_incidencia_maestro, revision_numero, estado_revision, motivo, folio_evidencia, creado_por_usuario_id, aprobado_por_usuario_id, creado_at_utc) VALUES
    (4106, 4004, 1, 'REVIEW_REQUIRED', 'Incapacidad médica pendiente de dictamen IMSS', 'FOL-IMSS-12345', 951, NULL, '2026-10-14 11:00:00')");

// 9. Seed evaluation fixtures
$db->query("INSERT IGNORE INTO op_rh_v2_evaluaciones_administrativas (id, id_jornada_programada, id_calculo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_admin, version_politica, estado_evaluacion, estado_revision_humana, fingerprint_ejecucion, creado_at_utc) VALUES
    (5001, 7001, 8001, 8901, 1, '2026-10-15', 1, 'v2.0-admin', 'pol-2026.1', 'JUSTIFIED', 'APPROVED', 'fp_p3ga_test_eval_01', '2026-10-15 18:00:00')");

$db->query("INSERT INTO op_rh_v2_incidencias_aplicaciones (id, id_incidencia_revision, id_jornada_programada, fecha_jornada_local, estado_aplicacion, creado_at_utc) VALUES
    (6001, 4102, 7001, '2026-10-15', 'APPLIED', '2026-10-15 18:00:00')");

$db->query("INSERT INTO op_rh_v2_evaluaciones_incidencias (id_evaluacion_administrativa, id_incidencia_aplicacion, creado_at_utc) VALUES
    (5001, 6001, '2026-10-15 18:00:00')");

$db->query("INSERT INTO op_rh_v2_ajustes_administrativos (id, uuid_ajuste, uuid_ajuste_logico, revision_numero, estado_ajuste, id_ajuste_anterior, id_jornada_programada, id_calculo_jornada, tipo_ajuste, hora_interpretada_entrada_utc, hora_interpretada_salida_utc, motivo_ajuste, creado_por_usuario_id, aprobado_por_usuario_id, creado_at_utc) VALUES
    (7001, 'adj-uuid-001', 'adj-log-001', 1, 'APPLIED', NULL, 7001, 8001, 'OVERTIME_AUTHORIZATION', '2026-10-15 14:00:00', '2026-10-15 22:00:00', 'Autorizacion TE', 951, 951, '2026-10-15 18:00:00')");

$db->query("INSERT INTO op_rh_v2_evaluaciones_ajustes (id_evaluacion_administrativa, id_ajuste_administrativo, creado_at_utc) VALUES
    (5001, 7001, '2026-10-15 18:00:00')");

$db->query("SET FOREIGN_KEY_CHECKS = 1");

$validJwt = 'Bearer ' . make_p3ga_jwt(['sub' => 951], $jwtSecret);
$inactiveJwt = 'Bearer ' . make_p3ga_jwt(['sub' => 952], $jwtSecret);
$noPermJwt = 'Bearer ' . make_p3ga_jwt(['sub' => 953], $jwtSecret);
$wrongAclJwt = 'Bearer ' . make_p3ga_jwt(['sub' => 954], $jwtSecret);
$rhAloneJwt = 'Bearer ' . make_p3ga_jwt(['sub' => 955], $jwtSecret);
$nominaAloneJwt = 'Bearer ' . make_p3ga_jwt(['sub' => 956], $jwtSecret);
$biometricosAloneJwt = 'Bearer ' . make_p3ga_jwt(['sub' => 957], $jwtSecret);
$emptyScopeJwt = 'Bearer ' . make_p3ga_jwt(['sub' => 958], $jwtSecret);
$invalidJwt = 'Bearer ' . make_p3ga_jwt(['sub' => 951], 'wrong_secret_p3ga');

echo "=== Running P3GA01 - P3GA25 Test Matrix ===\n";

// Baseline hash before read operations for strict non-mutation verification (P3GA25)
$dbStateHashBefore = get_p3ga_db_state_hash($db);

// P3GA01: Missing JWT returns 401
$res01 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8901&from=2026-10-15&to=2026-10-16&workplace_id=1');
p3ga_assert($res01['code'] === 401 && ($res01['json']['error'] ?? '') === 'UNAUTHORIZED', 'P3GA01', 'Missing JWT returns 401 UNAUTHORIZED');

// P3GA02: Invalid JWT signature/expired returns 401
$res02 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8901&from=2026-10-15&to=2026-10-16&workplace_id=1', ['HTTP_AUTHORIZATION' => $invalidJwt]);
p3ga_assert($res02['code'] === 401 && ($res02['json']['error'] ?? '') === 'UNAUTHORIZED', 'P3GA02', 'Invalid JWT signature/expired returns 401 UNAUTHORIZED');

// P3GA03: Inactive Portal3 user returns 401
$res03 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8901&from=2026-10-15&to=2026-10-16&workplace_id=1', ['HTTP_AUTHORIZATION' => $inactiveJwt]);
p3ga_assert($res03['code'] === 401 && ($res03['json']['error'] ?? '') === 'UNAUTHORIZED', 'P3GA03', 'Inactive Portal3 user returns 401 UNAUTHORIZED');

// P3GA04: User without checador-v2-incidencias.leer returns 403
$res04 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8901&from=2026-10-15&to=2026-10-16&workplace_id=1', ['HTTP_AUTHORIZATION' => $noPermJwt]);
p3ga_assert($res04['code'] === 403 && ($res04['json']['error'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'P3GA04', 'User without checador-v2-incidencias.leer returns 403 FUNCTION_PERMISSION_DENIED');

// P3GA05: User with wrong ACL namespace rejected (e.g. general incidencias)
$res05 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8901&from=2026-10-15&to=2026-10-16&workplace_id=1', ['HTTP_AUTHORIZATION' => $wrongAclJwt]);
p3ga_assert($res05['code'] === 403 && ($res05['json']['error'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'P3GA05', 'User with general incidencias ACL namespace rejected with 403 FUNCTION_PERMISSION_DENIED');

// P3GA06: User with recursos-humanos alone denied
$res06 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8901&from=2026-10-15&to=2026-10-16&workplace_id=1', ['HTTP_AUTHORIZATION' => $rhAloneJwt]);
p3ga_assert($res06['code'] === 403 && ($res06['json']['error'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'P3GA06', 'User with recursos-humanos alone denied with 403');

// P3GA07: User with incidencias-nomina alone denied
$res07 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8901&from=2026-10-15&to=2026-10-16&workplace_id=1', ['HTTP_AUTHORIZATION' => $nominaAloneJwt]);
p3ga_assert($res07['code'] === 403 && ($res07['json']['error'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'P3GA07', 'User with incidencias-nomina alone denied with 403');

// P3GA08: User with biometricos.leer alone denied
$res08 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8901&from=2026-10-15&to=2026-10-16&workplace_id=1', ['HTTP_AUTHORIZATION' => $biometricosAloneJwt]);
p3ga_assert($res08['code'] === 403 && ($res08['json']['error'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'P3GA08', 'User with biometricos.leer alone denied with 403');

// P3GA09: Out-of-scope workplace requested returns 403
$res09 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8901&from=2026-10-15&to=2026-10-16&workplace_id=99', ['HTTP_AUTHORIZATION' => $validJwt]);
p3ga_assert($res09['code'] === 403 && ($res09['json']['error'] ?? '') === 'STATION_SCOPE_DENIED', 'P3GA09', 'Out-of-scope workplace requested returns 403 STATION_SCOPE_DENIED');

// P3GA10: Empty workplace scope denied (fail-closed)
$res10 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8901&from=2026-10-15&to=2026-10-16&workplace_id=1', ['HTTP_AUTHORIZATION' => $emptyScopeJwt]);
p3ga_assert($res10['code'] === 403 && ($res10['json']['error'] ?? '') === 'STATION_SCOPE_DENIED', 'P3GA10', 'Empty workplace scope denied (fail-closed) with 403 STATION_SCOPE_DENIED');

// P3GA11: Device Auth (X-Device-UUID / Bearer device) rejected with 401
$res11 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8901&from=2026-10-15&to=2026-10-16&workplace_id=1', ['HTTP_X_DEVICE_UUID' => 'dev-1234', 'HTTP_AUTHORIZATION' => 'Bearer some_device_token']);
p3ga_assert($res11['code'] === 401, 'P3GA11', 'Device Auth rejected with 401');

// P3GA12: Unknown worker returns 404
$res12 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=999999&from=2026-10-15&to=2026-10-16&workplace_id=1', ['HTTP_AUTHORIZATION' => $validJwt]);
p3ga_assert($res12['code'] === 404 && ($res12['json']['error'] ?? '') === 'WORKER_NOT_FOUND', 'P3GA12', 'Unknown worker returns 404 WORKER_NOT_FOUND');

// P3GA13: Applicable incidences read returns correct contract structure
$res13 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8901&from=2026-10-15&to=2026-10-16&workplace_id=1', ['HTTP_AUTHORIZATION' => $validJwt]);
$data13 = $res13['json']['data'] ?? [];
$incList13 = $data13['incidences'] ?? [];
$hasInc1 = false;
foreach ($incList13 as $inc) {
    if (($inc['uuid_incidencia'] ?? '') === 'inc-uuid-p3ga-001') {
        $hasInc1 = true;
        p3ga_assert(
            $inc['worker_id'] === 8901 &&
            $inc['workplace_id'] === 1 &&
            $inc['category'] === 'VACATION' &&
            $inc['scope'] === 'FULL_DAY' &&
            $inc['date_range']['start_date'] === '2026-10-15' &&
            $inc['date_range']['end_date'] === '2026-10-16' &&
            $inc['current_status'] === 'AUTHORIZED' &&
            $inc['current_revision'] === 2 &&
            $inc['source_classification'] === 'READ_ONLY_ADAPTER' &&
            $inc['created_by_user_id'] === 951 &&
            $inc['approved_by_user_id'] === 951,
            'P3GA13',
            'Applicable incidences read returns correct contract structure matching 9.2'
        );
        break;
    }
}
if (!$hasInc1) {
    p3ga_assert(false, 'P3GA13', 'Applicable incidences did not find expected incident inc-uuid-p3ga-001');
}

// P3GA14: Single incident read returns full revision history
$res14 = call_p3ga_api('GET', '/api/v2/incidences/inc-uuid-p3ga-001', ['HTTP_AUTHORIZATION' => $validJwt]);
$data14 = $res14['json']['data'] ?? [];
$revs14 = $data14['revisions'] ?? [];
p3ga_assert(
    $res14['code'] === 200 &&
    $data14['uuid_incidencia'] === 'inc-uuid-p3ga-001' &&
    count($revs14) === 2 &&
    $revs14[0]['revision_number'] === 1 &&
    $revs14[0]['status'] === 'SUBMITTED' &&
    $revs14[1]['revision_number'] === 2 &&
    $revs14[1]['status'] === 'AUTHORIZED',
    'P3GA14',
    'Single incident read returns complete revision history'
);

// P3GA15: Revisions ordered deterministically (revision_numero ASC)
$isAscending = true;
for ($i = 1; $i < count($revs14); $i++) {
    if ($revs14[$i]['revision_number'] <= $revs14[$i - 1]['revision_number']) {
        $isAscending = false;
    }
}
p3ga_assert($isAscending && count($revs14) >= 2, 'P3GA15', 'Revisions ordered deterministically (revision_numero ASC)');

// P3GA16: Revoked / cancelled incident preserved in history, not deleted
$res16 = call_p3ga_api('GET', '/api/v2/incidences/inc-uuid-p3ga-002-revoked', ['HTTP_AUTHORIZATION' => $validJwt]);
$data16 = $res16['json']['data'] ?? [];
$revs16 = $data16['revisions'] ?? [];
p3ga_assert(
    $res16['code'] === 200 &&
    $data16['current_status'] === 'REVOKED' &&
    count($revs16) === 2 &&
    $revs16[1]['status'] === 'REVOKED',
    'P3GA16',
    'Revoked / cancelled incident preserved in history, not deleted'
);

// P3GA17: Overlapping incidences preserved without fabricated precedence
$res17 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8901&from=2026-10-15&to=2026-10-15&workplace_id=1', ['HTTP_AUTHORIZATION' => $validJwt]);
$uuids17 = array_column($res17['json']['data']['incidences'] ?? [], 'uuid_incidencia');
p3ga_assert(
    in_array('inc-uuid-p3ga-001', $uuids17, true) &&
    in_array('inc-uuid-p3ga-003-overlap', $uuids17, true),
    'P3GA17',
    'Overlapping incidences both preserved without fabricated precedence'
);

// P3GA18: REVIEW_REQUIRED status preserved when applicable
$res18 = call_p3ga_api('GET', '/api/v2/incidences/inc-uuid-p3ga-004-review', ['HTTP_AUTHORIZATION' => $validJwt]);
$data18 = $res18['json']['data'] ?? [];
p3ga_assert(
    $res18['code'] === 200 &&
    $data18['current_status'] === 'REVIEW_REQUIRED' &&
    ($data18['revisions'][0]['status'] ?? '') === 'REVIEW_REQUIRED',
    'P3GA18',
    'REVIEW_REQUIRED status preserved when applicable'
);

// P3GA19: Read boundary does not infer FALTA when no punch exists
$res19 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8902&from=2026-10-01&to=2026-10-02&workplace_id=1', ['HTTP_AUTHORIZATION' => $validJwt]);
$data19 = $res19['json']['data'] ?? [];
$incList19 = $data19['incidences'] ?? [];
$hasFalta = false;
foreach ($incList19 as $inc) {
    if (stripos($inc['category'] ?? '', 'FALTA') !== false || stripos($inc['current_status'] ?? '', 'FALTA') !== false) {
        $hasFalta = true;
    }
}
p3ga_assert($res19['code'] === 200 && count($incList19) === 0 && !$hasFalta, 'P3GA19', 'Read boundary does not infer FALTA when no punch exists');

// P3GA20: Read boundary does not infer SIN MARCACIÓN
$bodyStr19 = $res19['body'];
p3ga_assert(stripos($bodyStr19, 'SIN MARCACIÓN') === false && stripos($bodyStr19, 'SIN_MARCACION') === false, 'P3GA20', 'Read boundary does not infer SIN MARCACIÓN');

// P3GA21: Evaluation read returns existing provenance without recomputation
$res21 = call_p3ga_api('GET', '/api/v2/administrative-evaluations?worker_id=8901&workplace_id=1&work_date=2026-10-15', ['HTTP_AUTHORIZATION' => $validJwt]);
$data21 = $res21['json']['data'] ?? [];
$eval21 = $data21['evaluation'] ?? [];
p3ga_assert(
    $res21['code'] === 200 &&
    ($data21['evaluation_exists'] ?? false) === true &&
    $eval21['revision_number'] === 1 &&
    $eval21['administrative_status'] === 'JUSTIFIED' &&
    $eval21['human_review_status'] === 'APPROVED' &&
    $eval21['fingerprint'] === 'fp_p3ga_test_eval_01' &&
    $eval21['algorithm_version'] === 'v2.0-admin' &&
    $eval21['policy_version'] === 'pol-2026.1' &&
    count($eval21['linked_incidences'] ?? []) === 1 &&
    $eval21['linked_incidences'][0]['uuid_incidencia'] === 'inc-uuid-p3ga-001' &&
    count($eval21['linked_adjustments'] ?? []) === 1 &&
    $eval21['linked_adjustments'][0]['uuid_ajuste'] === 'adj-uuid-001',
    'P3GA21',
    'Evaluation read returns existing provenance without recomputation'
);

// P3GA22: Evaluation read does not invoke Level 1 / Level 2 (when no evaluation exists, returns 200 OK with evaluation_exists: false)
$res22 = call_p3ga_api('GET', '/api/v2/administrative-evaluations?worker_id=8902&workplace_id=1&work_date=2026-10-01', ['HTTP_AUTHORIZATION' => $validJwt]);
$data22 = $res22['json']['data'] ?? [];
p3ga_assert(
    $res22['code'] === 200 &&
    isset($data22['evaluation_exists']) &&
    $data22['evaluation_exists'] === false &&
    array_key_exists('evaluation', $data22) &&
    $data22['evaluation'] === null,
    'P3GA22',
    'Evaluation read returns evaluation_exists: false without invoking L1/L2'
);

// P3GA23: JSON response exposes no SQL errors or internal server traces
$res23 = call_p3ga_api('GET', '/api/v2/incidences?worker_id=8901&from=invalid-date&to=2026-10-15&workplace_id=1', ['HTTP_AUTHORIZATION' => $validJwt]);
$hasSqlTrace = (stripos($res23['body'], 'SQLSTATE') !== false || stripos($res23['body'], 'mysqli') !== false || stripos($res23['body'], 'syntax error') !== false);
p3ga_assert($res23['code'] === 400 && !$hasSqlTrace && isset($res23['json']['error']), 'P3GA23', 'JSON response exposes no SQL errors or internal server traces on invalid input');

// P3GA24: Raw punch IDs not exposed in incidence read
$bodyAllIncidences = $res13['body'] . $res14['body'] . $res21['body'];
$hasRawPunchId = (stripos($bodyAllIncidences, '"raw_punch_id"') !== false || stripos($bodyAllIncidences, '"id_marcacion"') !== false || stripos($bodyAllIncidences, '"entry_punch_id"') !== false);
p3ga_assert(!$hasRawPunchId, 'P3GA24', 'Raw punch IDs not exposed in incidence / evaluation read payload');

// P3GA25: Database state is byte-identical before and after read operations (strict read-only verification)
$dbStateHashAfter = get_p3ga_db_state_hash($db);
p3ga_assert($dbStateHashBefore === $dbStateHashAfter, 'P3GA25', 'Database state is byte-identical before and after read operations (strict read-only verification)');

echo "=== Running Write Endpoint Rejection Checks (P3GA26 - P3GA32) ===\n";

// P3GA26: PUT /api/v2/incidences returns 405 Method Not Allowed with Allow: GET, POST
$res26 = call_p3ga_api('PUT', '/api/v2/incidences', ['HTTP_AUTHORIZATION' => $validJwt], json_encode(['foo' => 'bar']));
$allowGet26 = false;
foreach ($res26['headers'] as $h) {
    if (stripos($h, 'Allow: GET, POST') !== false) {
        $allowGet26 = true;
        break;
    }
}
p3ga_assert($res26['code'] === 405 && $allowGet26, 'P3GA26', 'PUT /api/v2/incidences returns 405 Method Not Allowed with Allow: GET, POST');

// P3GA27: GET /api/v2/incidences/{uuid}/revisions returns 405 Method Not Allowed with Allow: POST
$res27 = call_p3ga_api('GET', '/api/v2/incidences/inc-uuid-p3ga-001/revisions', ['HTTP_AUTHORIZATION' => $validJwt]);
$allowGet27 = false;
foreach ($res27['headers'] as $h) {
    if (stripos($h, 'Allow: POST') !== false) {
        $allowGet27 = true;
        break;
    }
}
p3ga_assert($res27['code'] === 405 && $allowGet27, 'P3GA27', 'GET /api/v2/incidences/{uuid}/revisions returns 405 with Allow: POST');

// P3GA28: GET /api/v2/incidences/{uuid}/resolve returns 405 Method Not Allowed with Allow: POST
$res28 = call_p3ga_api('GET', '/api/v2/incidences/inc-uuid-p3ga-001/resolve', ['HTTP_AUTHORIZATION' => $validJwt]);
$allowPost28 = false;
foreach ($res28['headers'] as $h) {
    if (stripos($h, 'Allow: POST') !== false) {
        $allowPost28 = true;
        break;
    }
}
p3ga_assert($res28['code'] === 405 && $allowPost28, 'P3GA28', 'GET /api/v2/incidences/{uuid}/resolve returns 405 with Allow: POST');

// P3GA29: GET /api/v2/incidences/{uuid}/authorize returns 405 Method Not Allowed with Allow: POST
$res29 = call_p3ga_api('GET', '/api/v2/incidences/inc-uuid-p3ga-001/authorize', ['HTTP_AUTHORIZATION' => $validJwt]);
$allowPost29 = false;
foreach ($res29['headers'] as $h) {
    if (stripos($h, 'Allow: POST') !== false) {
        $allowPost29 = true;
        break;
    }
}
p3ga_assert($res29['code'] === 405 && $allowPost29, 'P3GA29', 'GET /api/v2/incidences/{uuid}/authorize returns 405 with Allow: POST');

// P3GA30: GET /api/v2/incidences/{uuid}/reject returns 405 Method Not Allowed with Allow: POST
$res30 = call_p3ga_api('GET', '/api/v2/incidences/inc-uuid-p3ga-001/reject', ['HTTP_AUTHORIZATION' => $validJwt]);
$allowPost30 = false;
foreach ($res30['headers'] as $h) {
    if (stripos($h, 'Allow: POST') !== false) {
        $allowPost30 = true;
        break;
    }
}
p3ga_assert($res30['code'] === 405 && $allowPost30, 'P3GA30', 'GET /api/v2/incidences/{uuid}/reject returns 405 with Allow: POST');

// P3GA31: GET /api/v2/incidences/{uuid}/revoke returns 405 Method Not Allowed with Allow: POST
$res31 = call_p3ga_api('GET', '/api/v2/incidences/inc-uuid-p3ga-001/revoke', ['HTTP_AUTHORIZATION' => $validJwt]);
$allowPost31 = false;
foreach ($res31['headers'] as $h) {
    if (stripos($h, 'Allow: POST') !== false) {
        $allowPost31 = true;
        break;
    }
}
p3ga_assert($res31['code'] === 405 && $allowPost31, 'P3GA31', 'GET /api/v2/incidences/{uuid}/revoke returns 405 with Allow: POST');

// P3GA32: GET /api/v2/administrative-evaluations/evaluate returns 405 Method Not Allowed with Allow: POST
$res32 = call_p3ga_api('GET', '/api/v2/administrative-evaluations/evaluate', ['HTTP_AUTHORIZATION' => $validJwt]);
$allowPost32 = false;
foreach ($res32['headers'] as $h) {
    if (stripos($h, 'Allow: POST') !== false) {
        $allowPost32 = true;
        break;
    }
}
p3ga_assert($res32['code'] === 405 && $allowPost32, 'P3GA32', 'GET /api/v2/administrative-evaluations/evaluate returns 405 with Allow: POST');

echo "\n=== P3G-A SUITE SUMMARY ===\n";
echo "TOTAL TESTS: " . ($passed + $failed) . "\n";
echo "PASSED:      {$passed}\n";
echo "FAILED:      {$failed}\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
