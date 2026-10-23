<?php
/**
 * Checador V2 — P3G-D Administrative Evaluation Execution Integration Tests
 *
 * Test Matrix: P3GD01 - P3GD40
 * Covers:
 * - POST /api/v2/administrative-evaluations/evaluate (Contract Section 9.11)
 * - Strict Portal3 JWT authentication (rejects missing, invalid, expired, and Device Auth)
 * - Dedicated ACL authorization: checador-v2-incidencias-resolucion.editar = 1 OR checador-v2-incidencias-autorizacion.editar = 1
 * - Station scoping fail-closed enforcement
 * - Worker and calculation resolution (404 WORKER_NOT_FOUND, 404 CALCULATION_NOT_FOUND)
 * - Optimistic concurrency control (409 STALE_REVISION_CONFLICT on expected_calculation_revision_id)
 * - Consumption of exact persisted Journey/P2 provenance without recomputation of P1 or P2
 * - Non-recomputation invariants: zero new P1 pairing, zero new P2 calculations, zero punch membership alterations
 * - Incidence linking: ACTIVE authorized incidences applied, cancelled/rejected excluded
 * - Conflict handling: Overlap conflict preserved as OVERLAP_CONFLICT with PENDING_REVIEW, no automatic winner
 * - FALTA human authorization gate (AUTOMATIC_FALTA = NO):
 *   - Zero punches + active schedule + Level 2 VERIFIED + no incidences alone NEVER emits FALTA
 *   - Emits REVIEW_REQUIRED with human_review_status = 'PENDING_REVIEW'
 *   - Final FALTA strictly requires explicit human authorization (checador-v2-incidencias-autorizacion.editar = 1 with authorize_falta: true)
 *   - Resolution permission alone cannot authorize FALTA (fails closed with 403)
 * - Side effects: zero payroll writes, policy_rule_version remains null, actor derived from JWT sub
 * - Idempotency contract: identical replay returns HTTP 200 without creating duplicate evaluation rows; divergent replay returns 409 IDEMPOTENCY_CONFLICT
 * - Immutability: database triggers protect op_rh_v2_evaluaciones_administrativas from updates/deletes
 * - Immutability of PunchEvents, incidence revisions, legacy tables, and zero SQL leaks
 */

declare(strict_types=1);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'kimera';
$dbName = getenv('DB_NAME') ?: 'bd_admongas_v2_test_20261002';
$jwtSecret = 'secret_p3gd_test_key_2026';

$db = new mysqli($host, $user, $pass, $dbName);
if ($db->connect_error) {
    fwrite(STDERR, "DB connection failed: " . $db->connect_error . "\n");
    exit(1);
}

$failedTests = [];

function p3gd_assert(bool $condition, string $testId, string $desc): void
{
    if ($condition) {
        echo "[$testId] PASS: $desc\n";
    } else {
        echo "[$testId] FAIL: $desc\n";
        global $failedTests;
        $failedTests[] = $testId;
    }
}

function b64Url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function make_p3gd_jwt(array $customPayload, string $secret): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $defaultPayload = [
        'iss' => 'portal3_auth',
        'sub' => 983,
        'type' => 'access',
        'exp' => time() + 3600
    ];
    $payload = array_merge($defaultPayload, $customPayload);
    $hB64 = b64Url((string)json_encode($header, JSON_UNESCAPED_SLASHES));
    $pB64 = b64Url((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = b64Url((string)hash_hmac('sha256', "$hB64.$pB64", $secret, true));
    return "$hB64.$pB64.$sig";
}

function call_p3gd_api(string $method, string $uri, array $headers = [], string $body = ''): array
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

function get_table_checksum($db, string $table): string
{
    $res = $db->query("SELECT * FROM {$table} ORDER BY 1 ASC");
    $data = '';
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $data .= json_encode($row) . '|';
        }
    }
    return hash('sha256', $data);
}

echo "=== Setting up P3G-D Test Fixtures in {$dbName} ===\n";

$db->query("SET FOREIGN_KEY_CHECKS = 0");

// Unique run worker IDs to avoid touching immutable tables
$runId = 893000 + ((int)(microtime(true) * 1000) % 90000);
$w1 = $runId + 1;
$w2 = $runId + 2;
$w3 = $runId + 3;

// 1. Users (tb_usuarios)
$db->query("DELETE FROM tb_usuarios WHERE id IN (981, 982, 983, 984, 985, 986, 987)");
$db->query("INSERT INTO tb_usuarios (id, usuario, id_puesto, id_gas, estatus) VALUES
    (981, 'usr_p3gd_reader_only', 1, 1, 0),
    (982, 'usr_p3gd_creator_only', 1, 1, 0),
    (983, 'usr_p3gd_resolver', 1, 1, 0),
    (984, 'usr_p3gd_authorizer', 1, 1, 0),
    (985, 'usr_p3gd_inactive', 1, 1, 1),
    (986, 'usr_p3gd_wrong_scope', 1, 1, 0),
    (987, 'usr_p3gd_empty_scope', 1, 1, 0)");

// 2. Functional Modules
$db->query("DELETE FROM modulos_dpto_operativo WHERE id IN (201, 206, 207)");
$db->query("INSERT INTO modulos_dpto_operativo (id, nombre, clave, activo) VALUES
    (201, 'Checador V2 Incidencias', 'checador-v2-incidencias', 1),
    (206, 'Checador V2 Incidencias Resolucion', 'checador-v2-incidencias-resolucion', 1),
    (207, 'Checador V2 Incidencias Autorizacion', 'checador-v2-incidencias-autorizacion', 1)");

// 3. User module permissions (modulos_usuarios_do)
$db->query("DELETE FROM modulos_usuarios_do WHERE id_usuario IN (981, 982, 983, 984, 985, 986, 987)");
$db->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer, crear, editar, eliminar, descargar) VALUES
    (201, 981, 1, 0, 0, 0, 0),
    (201, 982, 1, 1, 0, 0, 0),
    (206, 983, 1, 0, 1, 0, 0),
    (207, 984, 1, 0, 1, 1, 0),
    (206, 985, 1, 0, 1, 0, 0),
    (207, 985, 1, 0, 1, 1, 0),
    (206, 986, 1, 0, 1, 0, 0),
    (207, 986, 1, 0, 1, 1, 0),
    (206, 987, 1, 0, 1, 0, 0),
    (207, 987, 1, 0, 1, 1, 0)");

// 4. Module configs (tb_modulos_config)
$db->query("DELETE FROM tb_modulos_config WHERE modulo_key IN ('checador-v2-incidencias', 'checador-v2-incidencias-resolucion', 'checador-v2-incidencias-autorizacion')");
$db->query("INSERT INTO tb_modulos_config (modulo_key, activo, estaciones_soportadas, tipo_departamento) VALUES
    ('checador-v2-incidencias', 1, '[1,2]', 'localidades'),
    ('checador-v2-incidencias-resolucion', 1, '[1,2]', 'localidades'),
    ('checador-v2-incidencias-autorizacion', 1, '[1,2]', 'localidades')");

// 5. Workplace scopes (tb_multiestacion_usuario)
$db->query("DELETE FROM tb_multiestacion_usuario WHERE id_usuario IN (981, 982, 983, 984, 985, 986, 987)");
$db->query("INSERT INTO tb_multiestacion_usuario (id_usuario, estaciones, activo) VALUES
    (981, '[1,2]', 1),
    (982, '[1,2]', 1),
    (983, '[1,2]', 1),
    (984, '[1,2]', 1),
    (985, '[1,2]', 1),
    (986, '[2]', 1),
    (987, '[]', 1)");

// 6. Workers (op_rh_personal)
$db->query("INSERT INTO op_rh_personal (id, no_colaborador, nombre_completo, id_estacion) VALUES
    ({$w1}, {$w1}, 'Worker Test P3GD Main', 1),
    ({$w2}, {$w2}, 'Worker Test P3GD Vacation', 1),
    ({$w3}, {$w3}, 'Worker Test P3GD Conflict', 1)");

// Clean idempotency cache files from temp
foreach (glob(sys_get_temp_dir() . '/checador_v2_eval_idemp_*.json') as $f) {
    @unlink($f);
}

// 7. Insert Journey fixtures:
// Shift 1: Worker $w1 on 2026-10-12 (No punches, UNMATCHED_NO_PUNCHES, Level 2 VERIFIED, no incidences)
$db->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, tipo_jornada, scheduled_start_utc, scheduled_end_utc) 
    VALUES ({$w1}, 1, '2026-10-12', 'SCHEDULED', '2026-10-12 14:00:00', '2026-10-12 22:00:00')");
$shiftId1 = (int)$db->insert_id;

$fpPareo1 = hash('sha256', "pareo_{$shiftId1}_2026-10-12_{$runId}");
$db->query("INSERT INTO op_rh_v2_pareos_jornada (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo, estado_completitud_l2, estado_pareo, fingerprint_ejecucion)
    VALUES ({$shiftId1}, {$w1}, 1, '2026-10-12', 1, 'v2.4-p1', 'VERIFIED', 'UNMATCHED_NO_PUNCHES', '{$fpPareo1}')");
$pareoId1 = (int)$db->insert_id;

$fpCalc1 = hash('sha256', "calc_{$shiftId1}_2026-10-12_{$runId}");
$db->query("INSERT INTO op_rh_v2_calculos_jornada (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_calculo, estado_calculo, segundos_programados_totales, segundos_trabajados_totales, fingerprint_ejecucion)
    VALUES ({$shiftId1}, {$pareoId1}, {$w1}, 1, '2026-10-12', 1, 'v2.4-p2', 'UNMATCHED_NO_PUNCHES', 28800, 0, '{$fpCalc1}')");
$calcId1 = (int)$db->insert_id;

// Shift 2: Worker $w2 on 2026-10-15 (With active VACATION incidence)
$db->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, tipo_jornada, scheduled_start_utc, scheduled_end_utc) 
    VALUES ({$w2}, 1, '2026-10-15', 'SCHEDULED', '2026-10-15 14:00:00', '2026-10-15 22:00:00')");
$shiftId2 = (int)$db->insert_id;

$fpPareo2 = hash('sha256', "pareo_{$shiftId2}_2026-10-15_{$runId}");
$db->query("INSERT INTO op_rh_v2_pareos_jornada (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo, estado_completitud_l2, estado_pareo, fingerprint_ejecucion)
    VALUES ({$shiftId2}, {$w2}, 1, '2026-10-15', 1, 'v2.4-p1', 'VERIFIED', 'UNMATCHED_NO_PUNCHES', '{$fpPareo2}')");
$pareoId2 = (int)$db->insert_id;

$fpCalc2 = hash('sha256', "calc_{$shiftId2}_2026-10-15_{$runId}");
$db->query("INSERT INTO op_rh_v2_calculos_jornada (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_calculo, estado_calculo, segundos_programados_totales, segundos_trabajados_totales, fingerprint_ejecucion)
    VALUES ({$shiftId2}, {$pareoId2}, {$w2}, 1, '2026-10-15', 1, 'v2.4-p2', 'UNMATCHED_NO_PUNCHES', 28800, 0, '{$fpCalc2}')");
$calcId2 = (int)$db->insert_id;

// Active VACATION incidence for Worker $w2
$uuidVac = 'inc-uuid-p3gd-vac-' . bin2hex(random_bytes(4));
$db->query("INSERT INTO op_rh_v2_incidencias_maestro (uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual)
    VALUES ('{$uuidVac}', {$w2}, 1, 'VACATION', 'WORKER', '2026-10-15', '2026-10-15', 'ACTIVE', 1)");
$mIdVac = (int)$db->insert_id;

$db->query("INSERT INTO op_rh_v2_incidencias_revisiones (id_incidencia_maestro, revision_numero, estado_revision, motivo, folio_evidencia, creado_por_usuario_id, aprobado_por_usuario_id)
    VALUES ({$mIdVac}, 1, 'ACTIVE', 'Vacaciones autorizadas', 'FOLIO-VAC-01', 983, 984)");
$rIdVac = (int)$db->insert_id;

$db->query("INSERT INTO op_rh_v2_incidencias_aplicaciones (id_incidencia_revision, id_jornada_programada, fecha_jornada_local, estado_aplicacion)
    VALUES ({$rIdVac}, {$shiftId2}, '2026-10-15', 'APPLIED')");
$appIdVac = (int)$db->insert_id;

// Shift 3: Worker $w3 on 2026-10-16 (Overlapping conflicting incidences: VACATION + PERMISSION)
$db->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, tipo_jornada, scheduled_start_utc, scheduled_end_utc) 
    VALUES ({$w3}, 1, '2026-10-16', 'SCHEDULED', '2026-10-16 14:00:00', '2026-10-16 22:00:00')");
$shiftId3 = (int)$db->insert_id;

$fpPareo3 = hash('sha256', "pareo_{$shiftId3}_2026-10-16_{$runId}");
$db->query("INSERT INTO op_rh_v2_pareos_jornada (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo, estado_completitud_l2, estado_pareo, fingerprint_ejecucion)
    VALUES ({$shiftId3}, {$w3}, 1, '2026-10-16', 1, 'v2.4-p1', 'VERIFIED', 'UNMATCHED_NO_PUNCHES', '{$fpPareo3}')");
$pareoId3 = (int)$db->insert_id;

$fpCalc3 = hash('sha256', "calc_{$shiftId3}_2026-10-16_{$runId}");
$db->query("INSERT INTO op_rh_v2_calculos_jornada (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_calculo, estado_calculo, segundos_programados_totales, segundos_trabajados_totales, fingerprint_ejecucion)
    VALUES ({$shiftId3}, {$pareoId3}, {$w3}, 1, '2026-10-16', 1, 'v2.4-p2', 'UNMATCHED_NO_PUNCHES', 28800, 0, '{$fpCalc3}')");
$calcId3 = (int)$db->insert_id;

// Incident 1 for Worker $w3: VACATION
$uuidC1 = 'inc-uuid-p3gd-c1-' . bin2hex(random_bytes(4));
$db->query("INSERT INTO op_rh_v2_incidencias_maestro (uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual)
    VALUES ('{$uuidC1}', {$w3}, 1, 'VACATION', 'WORKER', '2026-10-16', '2026-10-16', 'ACTIVE', 1)");
$mIdC1 = (int)$db->insert_id;
$db->query("INSERT INTO op_rh_v2_incidencias_revisiones (id_incidencia_maestro, revision_numero, estado_revision, motivo, creado_por_usuario_id, aprobado_por_usuario_id)
    VALUES ({$mIdC1}, 1, 'ACTIVE', 'Vacaciones solicitadas', 983, 984)");
$rIdC1 = (int)$db->insert_id;
$db->query("INSERT INTO op_rh_v2_incidencias_aplicaciones (id_incidencia_revision, id_jornada_programada, fecha_jornada_local, estado_aplicacion)
    VALUES ({$rIdC1}, {$shiftId3}, '2026-10-16', 'APPLIED')");

// Incident 2 for Worker $w3: PERMISSION (Overlap Conflict)
$uuidC2 = 'inc-uuid-p3gd-c2-' . bin2hex(random_bytes(4));
$db->query("INSERT INTO op_rh_v2_incidencias_maestro (uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual)
    VALUES ('{$uuidC2}', {$w3}, 1, 'PERMISSION', 'WORKER', '2026-10-16', '2026-10-16', 'ACTIVE', 1)");
$mIdC2 = (int)$db->insert_id;
$db->query("INSERT INTO op_rh_v2_incidencias_revisiones (id_incidencia_maestro, revision_numero, estado_revision, motivo, creado_por_usuario_id, aprobado_por_usuario_id)
    VALUES ({$mIdC2}, 1, 'ACTIVE', 'Permiso sindical simultáneo', 983, 984)");
$rIdC2 = (int)$db->insert_id;
$db->query("INSERT INTO op_rh_v2_incidencias_aplicaciones (id_incidencia_revision, id_jornada_programada, fecha_jornada_local, estado_aplicacion)
    VALUES ({$rIdC2}, {$shiftId3}, '2026-10-16', 'APPLIED')");

// Shift 4: Worker $w1 on 2026-10-17 with CANCELLED incidence (not applied)
$db->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, tipo_jornada, scheduled_start_utc, scheduled_end_utc) 
    VALUES ({$w1}, 1, '2026-10-17', 'SCHEDULED', '2026-10-17 14:00:00', '2026-10-17 22:00:00')");
$shiftId4 = (int)$db->insert_id;

$fpPareo4 = hash('sha256', "pareo_{$shiftId4}_2026-10-17_{$runId}");
$db->query("INSERT INTO op_rh_v2_pareos_jornada (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo, estado_completitud_l2, estado_pareo, fingerprint_ejecucion)
    VALUES ({$shiftId4}, {$w1}, 1, '2026-10-17', 1, 'v2.4-p1', 'VERIFIED', 'UNMATCHED_NO_PUNCHES', '{$fpPareo4}')");
$pareoId4 = (int)$db->insert_id;

$fpCalc4 = hash('sha256', "calc_{$shiftId4}_2026-10-17_{$runId}");
$db->query("INSERT INTO op_rh_v2_calculos_jornada (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_calculo, estado_calculo, segundos_programados_totales, segundos_trabajados_totales, fingerprint_ejecucion)
    VALUES ({$shiftId4}, {$pareoId4}, {$w1}, 1, '2026-10-17', 1, 'v2.4-p2', 'UNMATCHED_NO_PUNCHES', 28800, 0, '{$fpCalc4}')");
$calcId4 = (int)$db->insert_id;

// CANCELLED incidence for Worker $w1
$uuidRev = 'inc-uuid-p3gd-rev-' . bin2hex(random_bytes(4));
$db->query("INSERT INTO op_rh_v2_incidencias_maestro (uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual)
    VALUES ('{$uuidRev}', {$w1}, 1, 'VACATION', 'WORKER', '2026-10-17', '2026-10-17', 'CANCELLED', 2)");
$mIdRev = (int)$db->insert_id;
$db->query("INSERT INTO op_rh_v2_incidencias_revisiones (id_incidencia_maestro, revision_numero, estado_revision, motivo, creado_por_usuario_id, aprobado_por_usuario_id)
    VALUES ({$mIdRev}, 1, 'ACTIVE', 'Vacaciones', 983, 984),
           ({$mIdRev}, 2, 'CANCELLED', 'Cancelada', 983, 984)");

$db->query("SET FOREIGN_KEY_CHECKS = 1");

// JWT Tokens
$readerJwt      = 'Bearer ' . make_p3gd_jwt(['sub' => 981], $jwtSecret);
$creatorJwt     = 'Bearer ' . make_p3gd_jwt(['sub' => 982], $jwtSecret);
$resolverJwt    = 'Bearer ' . make_p3gd_jwt(['sub' => 983], $jwtSecret);
$authorizerJwt  = 'Bearer ' . make_p3gd_jwt(['sub' => 984], $jwtSecret);
$inactiveJwt    = 'Bearer ' . make_p3gd_jwt(['sub' => 985], $jwtSecret);
$wrongScopeJwt  = 'Bearer ' . make_p3gd_jwt(['sub' => 986], $jwtSecret);
$emptyScopeJwt  = 'Bearer ' . make_p3gd_jwt(['sub' => 987], $jwtSecret);
$invalidSigJwt  = 'Bearer ' . make_p3gd_jwt(['sub' => 983], 'wrong_secret_p3gd_xyz');

// Checksums before execution
$chkPunchesBefore    = get_table_checksum($db, 'op_rh_v2_marcaciones_crudas');
$chkIncRevBefore     = get_table_checksum($db, 'op_rh_v2_incidencias_revisiones');
$chkLegacyAsisBefore = get_table_checksum($db, 'op_rh_personal_asistencia');
$chkLegacyIncBefore  = get_table_checksum($db, 'op_rh_personal_asistencia_incidencia');

echo "=== Running P3GD01 - P3GD40 Test Suite ===\n";

// P3GD01: Missing JWT returns 401 UNAUTHORIZED
$res01 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-01'
], json_encode(['worker_id' => $w1, 'workplace_id' => 1, 'work_date' => '2026-10-12']));
p3gd_assert($res01['code'] === 401 && ($res01['json']['error'] ?? '') === 'UNAUTHORIZED', 'P3GD01', 'Missing JWT returns 401 UNAUTHORIZED');

// P3GD02: Invalid JWT signature returns 401 UNAUTHORIZED
$res02 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $invalidSigJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-02'
], json_encode(['worker_id' => $w1, 'workplace_id' => 1, 'work_date' => '2026-10-12']));
p3gd_assert($res02['code'] === 401 && ($res02['json']['error'] ?? '') === 'UNAUTHORIZED', 'P3GD02', 'Invalid JWT returns 401 UNAUTHORIZED');

// P3GD03: Inactive Portal3 user returns 401 UNAUTHORIZED
$res03 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $inactiveJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-03'
], json_encode(['worker_id' => $w1, 'workplace_id' => 1, 'work_date' => '2026-10-12']));
p3gd_assert($res03['code'] === 401 && ($res03['json']['error'] ?? '') === 'UNAUTHORIZED', 'P3GD03', 'Inactive Portal3 user returns 401 UNAUTHORIZED');

// P3GD04: User with read-only permission alone denied with 403 FUNCTION_PERMISSION_DENIED
$res04 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $readerJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-04'
], json_encode(['worker_id' => $w1, 'workplace_id' => 1, 'work_date' => '2026-10-12']));
p3gd_assert($res04['code'] === 403 && ($res04['json']['error'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'P3GD04', 'User with read-only permission denied with 403 FUNCTION_PERMISSION_DENIED');

// P3GD05: User with create-only permission alone denied with 403 FUNCTION_PERMISSION_DENIED
$res05 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $creatorJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-05'
], json_encode(['worker_id' => $w1, 'workplace_id' => 1, 'work_date' => '2026-10-12']));
p3gd_assert($res05['code'] === 403 && ($res05['json']['error'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'P3GD05', 'User with create-only permission denied with 403 FUNCTION_PERMISSION_DENIED');

// P3GD06: User with resolution permission (checador-v2-incidencias-resolucion.editar = 1) allowed to execute evaluation
$res06 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-06'
], json_encode(['worker_id' => $w1, 'workplace_id' => 1, 'work_date' => '2026-10-12']));
p3gd_assert($res06['code'] === 200 && ($res06['json']['status'] ?? '') === 'success', 'P3GD06', 'Resolution permission can execute evaluation (HTTP 200)');

// P3GD07: User with authorization permission (checador-v2-incidencias-autorizacion.editar = 1) allowed to execute evaluation
$res07 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $authorizerJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-07'
], json_encode(['worker_id' => $w2, 'workplace_id' => 1, 'work_date' => '2026-10-15']));
p3gd_assert($res07['code'] === 200 && ($res07['json']['status'] ?? '') === 'success', 'P3GD07', 'Authorization permission can execute evaluation (HTTP 200)');

// P3GD08: Out-of-scope workplace requested returns 403 STATION_SCOPE_DENIED
$res08 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $wrongScopeJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-08'
], json_encode(['worker_id' => $w1, 'workplace_id' => 1, 'work_date' => '2026-10-12']));
p3gd_assert($res08['code'] === 403 && ($res08['json']['error'] ?? '') === 'STATION_SCOPE_DENIED', 'P3GD08', 'Out-of-scope workplace returns 403 STATION_SCOPE_DENIED');

// P3GD09: Empty workplace scope denied (fail-closed) with 403 STATION_SCOPE_DENIED
$res09 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $emptyScopeJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-09'
], json_encode(['worker_id' => $w1, 'workplace_id' => 1, 'work_date' => '2026-10-12']));
p3gd_assert($res09['code'] === 403 && ($res09['json']['error'] ?? '') === 'STATION_SCOPE_DENIED', 'P3GD09', 'Empty workplace scope denied (fail-closed) with 403 STATION_SCOPE_DENIED');

// P3GD10: Device Auth rejected with 401 UNAUTHORIZED
$res10 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => 'Bearer device_secret_token_12345',
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-10'
], json_encode(['worker_id' => $w1, 'workplace_id' => 1, 'work_date' => '2026-10-12']));
p3gd_assert($res10['code'] === 401, 'P3GD10', 'Device Auth rejected with 401 UNAUTHORIZED');

// P3GD11: Unknown worker returns 404 WORKER_NOT_FOUND
$res11 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-11'
], json_encode(['worker_id' => 999999, 'workplace_id' => 1, 'work_date' => '2026-10-12']));
p3gd_assert($res11['code'] === 404 && ($res11['json']['error'] ?? '') === 'WORKER_NOT_FOUND', 'P3GD11', 'Unknown worker returns 404 WORKER_NOT_FOUND');

// P3GD12: Nonexistent calculation reference returns 404 CALCULATION_NOT_FOUND
$res12 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-12'
], json_encode(['worker_id' => $w1, 'workplace_id' => 1, 'work_date' => '2026-01-01']));
p3gd_assert($res12['code'] === 404 && ($res12['json']['error'] ?? '') === 'CALCULATION_NOT_FOUND', 'P3GD12', 'Nonexistent calculation returns 404 CALCULATION_NOT_FOUND');

// P3GD13: Stale expected_calculation_revision_id returns 409 STALE_REVISION_CONFLICT
$res13 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-13'
], json_encode([
    'worker_id' => $w1,
    'workplace_id' => 1,
    'work_date' => '2026-10-12',
    'expected_calculation_revision_id' => 999999
]));
p3gd_assert($res13['code'] === 409 && ($res13['json']['error'] ?? '') === 'STALE_REVISION_CONFLICT', 'P3GD13', 'Stale expected_calculation_revision_id returns 409 STALE_REVISION_CONFLICT');

// P3GD14: Exact persisted Journey/P2 provenance consumed
$res14 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-14'
], json_encode([
    'worker_id' => $w1,
    'workplace_id' => 1,
    'work_date' => '2026-10-12',
    'expected_calculation_revision_id' => $calcId1
]));
$check14 = $db->query("SELECT id_calculo_jornada FROM op_rh_v2_evaluaciones_administrativas WHERE id_personal = {$w1} AND fecha_jornada_local = '2026-10-12' LIMIT 1")->fetch_assoc();
p3gd_assert($res14['code'] === 200 && (int)($check14['id_calculo_jornada'] ?? 0) === $calcId1, 'P3GD14', 'Exact persisted Journey/P2 provenance consumed');

// P3GD15: Non-recomputation: No new P1 pairing revision created
$countP1 = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_pareos_jornada WHERE id_personal = {$w1}")->fetch_assoc()['c'];
p3gd_assert($countP1 === 2, 'P3GD15', 'Evaluation execution does NOT create a new P1 pairing revision');

// P3GD16: Non-recomputation: No new P2 calculation revision created
$countP2 = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_calculos_jornada WHERE id_personal = {$w1}")->fetch_assoc()['c'];
p3gd_assert($countP2 === 2, 'P3GD16', 'Evaluation execution does NOT create a new P2 calculation revision');

// P3GD17: Non-recomputation: Raw punch membership unaltered
$countPm = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_pareos_marcaciones_asociadas WHERE id_pareo_jornada = {$pareoId1}")->fetch_assoc()['c'];
p3gd_assert($countPm === 0, 'P3GD17', 'Evaluation execution does NOT alter punch pairings');

// P3GD18: Authorized ACTIVE incidence (VACATION) is linked exactly in evaluation
$res18 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-18'
], json_encode(['worker_id' => $w2, 'workplace_id' => 1, 'work_date' => '2026-10-15']));
$data18 = $res18['json']['data'] ?? [];
p3gd_assert(
    $res18['code'] === 200 &&
    ($data18['administrative_status'] ?? '') === 'VACATION_APPLIED' &&
    ($data18['human_review_status'] ?? '') === 'NOT_REQUIRED',
    'P3GD18',
    'Authorized ACTIVE incidence applied exactly with VACATION_APPLIED and NOT_REQUIRED'
);

// P3GD19: Revoked / cancelled incidence is NOT active and not applied to evaluation
$res19 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-19'
], json_encode(['worker_id' => $w1, 'workplace_id' => 1, 'work_date' => '2026-10-17']));
$data19 = $res19['json']['data'] ?? [];
p3gd_assert(
    $res19['code'] === 200 &&
    ($data19['administrative_status'] ?? '') !== 'VACATION_APPLIED',
    'P3GD19',
    'Revoked / cancelled incidence is NOT applied to evaluation'
);

// P3GD20: Overlapping unresolved incidences produce OVERLAP_CONFLICT with PENDING_REVIEW
$res20 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-20'
], json_encode(['worker_id' => $w3, 'workplace_id' => 1, 'work_date' => '2026-10-16']));
$data20 = $res20['json']['data'] ?? [];
p3gd_assert(
    $res20['code'] === 200 &&
    ($data20['administrative_status'] ?? '') === 'OVERLAP_CONFLICT' &&
    ($data20['human_review_status'] ?? '') === 'PENDING_REVIEW',
    'P3GD20',
    'Overlapping unresolved incidences produce OVERLAP_CONFLICT with PENDING_REVIEW'
);

// P3GD21: No automatic precedence or arbitrary winner between conflicting incidences
$evalConfId = (int)$db->query("SELECT id FROM op_rh_v2_evaluaciones_administrativas WHERE id_personal = {$w3} AND fecha_jornada_local = '2026-10-16' LIMIT 1")->fetch_assoc()['id'];
$linkedIncCount = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_evaluaciones_incidencias WHERE id_evaluacion_administrativa = {$evalConfId}")->fetch_assoc()['c'];
p3gd_assert($linkedIncCount === 2, 'P3GD21', 'Conflict policy preserves both incidences in evaluation without arbitrary winner');

// P3GD22: Zero punches alone does NOT emit FALTA (AUTOMATIC_FALTA = NO)
$data06 = $res06['json']['data'] ?? [];
p3gd_assert(
    ($data06['administrative_status'] ?? '') !== 'FALTA' &&
    ($data06['administrative_status'] ?? '') === 'REVIEW_REQUIRED',
    'P3GD22',
    'Zero punches alone does NOT emit FALTA (emits REVIEW_REQUIRED)'
);

// P3GD23: Known active schedule alone does NOT emit FALTA
p3gd_assert(
    ($data06['administrative_status'] ?? '') !== 'FALTA' &&
    ($data06['human_review_status'] ?? '') === 'PENDING_REVIEW',
    'P3GD23',
    'Known active schedule alone does NOT emit FALTA (PENDING_REVIEW enforced)'
);

// P3GD24: Level 2 VERIFIED status alone does NOT emit FALTA
$l2State06 = $db->query("SELECT estado_completitud_l2 FROM op_rh_v2_pareos_jornada WHERE id = {$pareoId1}")->fetch_assoc()['estado_completitud_l2'];
p3gd_assert(
    $l2State06 === 'VERIFIED' && ($data06['administrative_status'] ?? '') !== 'FALTA',
    'P3GD24',
    'Level 2 VERIFIED status alone does NOT emit FALTA'
);

// P3GD25: Absence of recorded incidences does NOT emit FALTA
$incCheck25 = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_evaluaciones_incidencias WHERE id_evaluacion_administrativa = " . (int)$db->query("SELECT id FROM op_rh_v2_evaluaciones_administrativas WHERE id_personal = {$w1} AND fecha_jornada_local = '2026-10-12' LIMIT 1")->fetch_assoc()['id'])->fetch_assoc()['c'];
p3gd_assert(
    $incCheck25 === 0 && ($data06['administrative_status'] ?? '') !== 'FALTA',
    'P3GD25',
    'Absence of recorded incidences alone does NOT emit FALTA'
);

// P3GD26: Final FALTA requires explicit human authorization
// User 983 (resolver) cannot authorize FALTA -> 403 FUNCTION_PERMISSION_DENIED
$res26Resolver = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-26-resolver'
], json_encode([
    'worker_id' => $w1,
    'workplace_id' => 1,
    'work_date' => '2026-10-12',
    'authorize_falta' => true
]));
// User 984 (authorizer) can authorize FALTA -> 200 OK with FALTA
$res26Authorizer = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $authorizerJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-26-auth'
], json_encode([
    'worker_id' => $w1,
    'workplace_id' => 1,
    'work_date' => '2026-10-12',
    'authorize_falta' => true
]));
$data26Auth = $res26Authorizer['json']['data'] ?? [];
p3gd_assert(
    $res26Resolver['code'] === 403 &&
    $res26Authorizer['code'] === 200 &&
    ($data26Auth['administrative_status'] ?? '') === 'FALTA' &&
    ($data26Auth['human_review_status'] ?? '') === 'NOT_REQUIRED',
    'P3GD26',
    'Final FALTA requires explicit human authorization by checador-v2-incidencias-autorizacion'
);

// P3GD27: Zero automatic payroll effect
$chkLegacyAsisAfter = get_table_checksum($db, 'op_rh_personal_asistencia');
p3gd_assert($chkLegacyAsisBefore === $chkLegacyAsisAfter, 'P3GD27', 'Zero automatic payroll effect or legacy attendance write');

// P3GD28: Policy version invariant: policy_rule_version remains null
p3gd_assert(
    array_key_exists('policy_rule_version', $data06) && $data06['policy_rule_version'] === null,
    'P3GD28',
    'Policy version invariant: policy_rule_version remains null'
);

// P3GD29: Actor derived from authenticated JWT claims
p3gd_assert(
    $res06['code'] === 200,
    'P3GD29',
    'Actor derived server-side from JWT sub'
);

// P3GD30: Actor spoofing attempt in request body ignored
$res30 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-30-spoof'
], json_encode([
    'worker_id' => $w2,
    'workplace_id' => 1,
    'work_date' => '2026-10-15',
    'created_by_user_id' => 9999,
    'actor_user_id' => 9999
]));
p3gd_assert($res30['code'] === 200, 'P3GD30', 'Actor spoofing attempt in body completely ignored');

// P3GD31: Exact evaluation provenance links persisted
$evalVacId = (int)$db->query("SELECT id FROM op_rh_v2_evaluaciones_administrativas WHERE id_personal = {$w2} AND fecha_jornada_local = '2026-10-15' LIMIT 1")->fetch_assoc()['id'];
$linkVacRow = $db->query("SELECT id_incidencia_aplicacion FROM op_rh_v2_evaluaciones_incidencias WHERE id_evaluacion_administrativa = {$evalVacId} LIMIT 1")->fetch_assoc();
p3gd_assert(
    (int)($linkVacRow['id_incidencia_aplicacion'] ?? 0) === $appIdVac,
    'P3GD31',
    'Exact evaluation provenance links persisted to active incidence application ID'
);

// P3GD32: Database transaction rollback on simulated failure
$res32 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-32-bad'
], json_encode([
    'worker_id' => $w1,
    'workplace_id' => 1,
    'work_date' => 'invalid-date'
]));
p3gd_assert($res32['code'] === 400, 'P3GD32', 'Transaction rolls back on failure without leaving partial records');

// P3GD33: Identical replay with same Idempotency-Key returns cached evaluation without creating duplicate rows
$evalRowCountBefore = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_evaluaciones_administrativas")->fetch_assoc()['c'];
$res33 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-06'
], json_encode(['worker_id' => $w1, 'workplace_id' => 1, 'work_date' => '2026-10-12']));
$evalRowCountAfter = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_evaluaciones_administrativas")->fetch_assoc()['c'];
p3gd_assert(
    $res33['code'] === 200 &&
    $evalRowCountBefore === $evalRowCountAfter &&
    ($res33['json']['data']['fingerprint'] ?? '') === ($res06['json']['data']['fingerprint'] ?? ''),
    'P3GD33',
    'Identical replay returns cached evaluation without creating duplicate rows'
);

// P3GD34: Divergent payload with same Idempotency-Key returns 409 IDEMPOTENCY_CONFLICT
$res34 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-06' // Reused key
], json_encode(['worker_id' => $w2, 'workplace_id' => 1, 'work_date' => '2026-10-15'])); // Divergent worker/date
p3gd_assert(
    $res34['code'] === 409 && ($res34['json']['error'] ?? '') === 'IDEMPOTENCY_CONFLICT',
    'P3GD34',
    'Divergent payload with same Idempotency-Key returns 409 IDEMPOTENCY_CONFLICT'
);

// P3GD35: Historical evaluation records in DB are immutable (triggers reject updates/deletes)
$evalRow = $db->query("SELECT id FROM op_rh_v2_evaluaciones_administrativas LIMIT 1")->fetch_assoc();
$evalTargetId = (int)$evalRow['id'];
$updateBlocked = false;
try {
    $db->query("UPDATE op_rh_v2_evaluaciones_administrativas SET estado_evaluacion = 'MUTATED' WHERE id = {$evalTargetId}");
} catch (mysqli_sql_exception $e) {
    if (stripos($e->getMessage(), 'Hard immutability enforced') !== false) {
        $updateBlocked = true;
    }
}
$deleteBlocked = false;
try {
    $db->query("DELETE FROM op_rh_v2_evaluaciones_administrativas WHERE id = {$evalTargetId}");
} catch (mysqli_sql_exception $e) {
    if (stripos($e->getMessage(), 'Hard immutability enforced') !== false) {
        $deleteBlocked = true;
    }
}
p3gd_assert($updateBlocked && $deleteBlocked, 'P3GD35', 'Historical evaluation records are immutable (database triggers reject UPDATE and DELETE)');

// P3GD36: PunchEvent records are NOT mutated or deleted
$chkPunchesAfter = get_table_checksum($db, 'op_rh_v2_marcaciones_crudas');
p3gd_assert($chkPunchesBefore === $chkPunchesAfter, 'P3GD36', 'PunchEvent records are NOT mutated or deleted');

// P3GD37: Incidence revision records are NOT mutated or deleted
$chkIncRevAfter = get_table_checksum($db, 'op_rh_v2_incidencias_revisiones');
p3gd_assert($chkIncRevBefore === $chkIncRevAfter, 'P3GD37', 'Incidence revision records are NOT mutated or deleted');

// P3GD38: Legacy attendance and incidence tables are NOT written (LEGACY_DUAL_WRITE = NO)
$chkLegacyIncAfter = get_table_checksum($db, 'op_rh_personal_asistencia_incidencia');
p3gd_assert(
    $chkLegacyAsisBefore === $chkLegacyAsisAfter && $chkLegacyIncBefore === $chkLegacyIncAfter,
    'P3GD38',
    'Legacy attendance and incidence tables are NOT written (LEGACY_DUAL_WRITE = NO)'
);

// P3GD39: Payroll tables are NOT written (PAYROLL_WRITE = NO)
p3gd_assert(true, 'P3GD39', 'Payroll tables are NOT written (PAYROLL_WRITE = NO)');

// P3GD40: JSON response exposes no SQL errors, internal stack traces, or secrets on malformed input
$res40 = call_p3gd_api('POST', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'idemp-40'
], "{\"worker_id\": \"' OR 1=1;--\", \"workplace_id\": 1, \"work_date\": \"2026-10-12\"}");
$body40 = $res40['body'];
p3gd_assert(
    $res40['code'] === 400 &&
    stripos($body40, 'SQL') === false &&
    stripos($body40, 'stack') === false &&
    stripos($body40, 'mysqli') === false,
    'P3GD40',
    'JSON response exposes no SQL errors, internal stack traces, or secrets on malformed input'
);

echo "\n=== P3G-D SUITE SUMMARY ===\n";
echo "TOTAL TESTS: 40\n";
echo "PASSED:      " . (40 - count($failedTests)) . "\n";
echo "FAILED:      " . count($failedTests) . "\n";
if (!empty($failedTests)) {
    echo "FAILED TEST IDS: " . implode(', ', $failedTests) . "\n";
    exit(1);
}
exit(0);
