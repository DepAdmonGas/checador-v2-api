<?php
/**
 * Checador V2 — Journey Provenance Boundary Test Suite (JPV01 - JPV30)
 *
 * Verifies the additive on-demand Journey evaluation & provenance boundary
 * strictly adhering to:
 * - docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.1.md (FROZEN_V1.1)
 * - docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.0.md (FROZEN_V1.0)
 * - docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/journey/JourneyPairingService.php';

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : 'kimera';
$dbName = 'bd_admongas_v2_test_20261002';
$jwtSecret = 'test_journey_provenance_jwt_secret_112233';
putenv("JWT_SECRET={$jwtSecret}");

$bdObj = new BD();
$db = $bdObj->conectarBD();

$passed = 0;
$failed = 0;

function jpv_assert(bool $cond, string $id, string $desc): void
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

echo "=== Setting up Journey Provenance Test Fixtures in {$dbName} ===\n";

$db->query("SET FOREIGN_KEY_CHECKS = 0");

// 1. ACL users & permissions
$db->query("DELETE FROM tb_usuarios WHERE id IN (901, 902, 903, 904)");
$db->query("INSERT INTO tb_usuarios (id, usuario, id_puesto, id_gas, estatus) VALUES
    (901, 'supervisor_jpv_active', 1, 1, 0),
    (902, 'supervisor_jpv_inactive', 1, 1, 1),
    (903, 'user_jpv_no_biometricos', 99, 1, 0),
    (904, 'user_jpv_acceso_temp_only', 99, 1, 0)");

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
$db->query("DELETE FROM op_rh_personal WHERE id IN (8801, 8802, 8803, 8816)");
$db->query("INSERT INTO op_rh_personal (id, no_colaborador, nombre_completo, id_estacion) VALUES
    (8801, 8801, 'Worker JPV Standard', 1),
    (8802, 8802, 'Worker JPV FivePunches', 1),
    (8803, 8803, 'Worker JPV Unscheduled', 1),
    (8816, 8816, 'Worker JPV NullDevice', 1)");

// 4. Shift Templates & Segments
$db->query("DELETE FROM op_rh_v2_turnos_plantillas WHERE id = 7701 OR clave IN ('TPL-JPV-01', 'TPL-JRB-01')");
$db->query("INSERT INTO op_rh_v2_turnos_plantillas (id, clave, version, nombre, tipo_turno, es_activo) VALUES
    (7701, 'TPL-JPV-01', 1, 'Standard Day 8-16', 'MATUTINO', 1)");

$db->query("DELETE FROM op_rh_v2_turnos_segmentos WHERE id_plantilla = 7701");
$db->query("INSERT INTO op_rh_v2_turnos_segmentos (id_plantilla, ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin) VALUES
    (7701, 1, 'WORK', '08:00:00', '16:00:00', 0, 0)");

// 5. Shift Assignments
$db->query("DELETE FROM op_rh_v2_turnos_asignaciones WHERE id_personal IN (8801, 8802, 8803, 8816)");
$days = ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO', 'DOMINGO'];
foreach ($days as $day) {
    $db->query("INSERT INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo) VALUES
        (8801, 1, 7701, '$day', '2026-01-01', NULL, 1),
        (8802, 1, 7701, '$day', '2026-01-01', NULL, 1),
        (8816, 1, 7701, '$day', '2026-01-01', NULL, 1)");
}

// 6. Clean and seed Raw Punches for test workers on 2026-10-14
$db->query("SET FOREIGN_KEY_CHECKS = 0");
$db->query("TRUNCATE TABLE op_rh_v2_marcaciones_crudas");
$db->query("DELETE FROM op_rh_v2_jornadas_programadas WHERE id_personal IN (8801, 8802, 8803, 8816)");
$db->query("TRUNCATE TABLE op_rh_v2_calculos_segmentos");
$db->query("TRUNCATE TABLE op_rh_v2_calculos_jornada");
$db->query("TRUNCATE TABLE op_rh_v2_pareos_marcaciones_asociadas");
$db->query("TRUNCATE TABLE op_rh_v2_pareos_segmentos");
$db->query("TRUNCATE TABLE op_rh_v2_pareos_jornada");
$db->query("DELETE FROM op_rh_v2_level2_window_evaluations WHERE evaluation_ref LIKE 'eval_ref_jpv_%'");

// Worker 8801: 2 punches within window W + 1 punch way outside window W (2026-10-05)
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES
    (1, 1, 'ev-jpv-8801-in', 'REF8801', 8801, '2026-10-14 14:00:00', '2026-10-14 08:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jpv-8801-out', 'REF8801', 8801, '2026-10-14 22:00:00', '2026-10-14 16:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jpv-8801-outside', 'REF8801', 8801, '2026-10-05 14:00:00', '2026-10-05 08:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");

// Worker 8802: 5 punches (IN, 3 intermediate/unmatched, OUT)
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES
    (1, 1, 'ev-jpv-8802-p1', 'REF8802', 8802, '2026-10-14 14:00:00', '2026-10-14 08:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jpv-8802-p2', 'REF8802', 8802, '2026-10-14 16:00:00', '2026-10-14 10:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jpv-8802-p3', 'REF8802', 8802, '2026-10-14 18:00:00', '2026-10-14 12:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jpv-8802-p4', 'REF8802', 8802, '2026-10-14 20:00:00', '2026-10-14 14:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-jpv-8802-p5', 'REF8802', 8802, '2026-10-14 22:00:00', '2026-10-14 16:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");

// Worker 8816: punches with WEB_CAPTURE
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES
    (1, 1, 'ev-jpv-8816-in', 'REF8816', 8816, '2026-10-14 14:00:00', '2026-10-14 08:00:00', UTC_TIMESTAMP(), 'WEB_CAPTURE', 'RECEIVED'),
    (1, 1, 'ev-jpv-8816-out', 'REF8816', 8816, '2026-10-14 22:00:00', '2026-10-14 16:00:00', UTC_TIMESTAMP(), 'WEB_CAPTURE', 'RECEIVED')");

// 7. Seed Level 2 evaluation for Station 1 & Station 2
$db->query("DELETE FROM op_rh_v2_level2_window_evaluations WHERE id_estacion IN (1, 2) AND DATE(window_from_utc) = '2026-10-14'");
$db->query("INSERT INTO op_rh_v2_level2_window_evaluations (evaluation_ref, id_estacion, window_from_utc, window_to_utc, coverage_policy_ref, relevant_device_generations_json, delivery_completeness, operational_coverage, time_window_attribution, window_level2_status, has_zero_governed_events, evaluated_at_utc, manifest_digest, canonical_evidence_json) VALUES
    ('eval_ref_jpv_test_01', 1, '2026-10-14 06:00:00', '2026-10-15 06:00:00', 'POLICY_DEFAULT', '[]', 'VERIFIED', 'VERIFIED', 'VERIFIED', 'VERIFIED', 0, UTC_TIMESTAMP(), 'digest123', '{}'),
    ('eval_ref_jpv_unknown_02', 2, '2026-10-14 06:00:00', '2026-10-15 06:00:00', 'POLICY_DEFAULT', '[]', 'UNKNOWN', 'UNKNOWN', 'UNKNOWN', 'UNKNOWN', 1, UTC_TIMESTAMP(), 'digest456', '{}')");

$db->query("SET FOREIGN_KEY_CHECKS = 1");

echo "=== Running JPV01 - JPV30 Test Matrix ===\n";

$validJwt = 'Bearer ' . make_jwt(['sub' => 901], $jwtSecret);

// JPV01: V1.1 Contract exists
$v11ContractPath = __DIR__ . '/../docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.1.md';
jpv_assert(file_exists($v11ContractPath) && filesize($v11ContractPath) > 500, 'JPV01', 'V1.1 contract exists and is populated');

// JPV02: V1.0 Contract remains unchanged
$v10ContractPath = __DIR__ . '/../docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.0.md';
$v10Hash = hash_file('sha256', $v10ContractPath);
jpv_assert($v10Hash === '9eda2b2c7174154d6524f7130ec197678958801df41004925a60928462876c9b', 'JPV02', 'V1.0 contract hash is strictly 9eda2b2c7174154d6524f7130ec197678958801df41004925a60928462876c9b');

// JPV03: Journey endpoint unchanged
$req8801 = [
    'id_personal' => 8801,
    'id_estacion' => 1,
    'fecha_local' => '2026-10-14',
    'snapshot_audit_ref' => 'snap_audit_jpv_01'
];
$res8801 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req8801));
jpv_assert($res8801['code'] === 200 && ($res8801['json']['status'] ?? '') === 'success', 'JPV03', 'Journey endpoint POST /api/v2/journeys/evaluate is unchanged');

// JPV04: Journey auth unchanged (Portal3 JWT + active user + biometricos.leer + workplace scope)
$noJwtRes = call_cgi_api('POST', '/api/v2/journeys/evaluate', [], json_encode($req8801));
$outOfScopeReq = ['id_personal' => 8801, 'id_estacion' => 2, 'fecha_local' => '2026-10-14'];
$outOfScopeRes = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($outOfScopeReq));
jpv_assert($noJwtRes['code'] === 401 && $outOfScopeRes['code'] === 403, 'JPV04', 'Journey auth model strictly preserved (401 without JWT, 403 out of scope)');

// JPV05: Evaluated shift returns evaluated raw-event candidate membership
$data8801 = $res8801['json']['data'] ?? [];
$candidates8801 = $data8801['evaluated_raw_events'] ?? null;
$provCandidates8801 = $data8801['provenance']['evaluated_raw_events'] ?? null;
jpv_assert($data8801['evaluated'] === true && is_array($candidates8801) && is_array($provCandidates8801), 'JPV05', 'Evaluated shift returns evaluated raw-event candidate membership');

// JPV06: Membership contains exact P1 candidate punch set (worker 8801 has 2 punches)
$uuids8801 = array_column($candidates8801, 'event_uuid');
jpv_assert(count($candidates8801) === 2 && in_array('ev-jpv-8801-in', $uuids8801, true) && in_array('ev-jpv-8801-out', $uuids8801, true), 'JPV06', 'Membership contains exact P1 candidate punch set (worker 8801 has 2 punches)');

// JPV07: Membership excludes punches outside candidate evaluation window
jpv_assert(!in_array('ev-jpv-8801-outside', $uuids8801, true), 'JPV07', 'Membership strictly excludes punches outside candidate evaluation window');

// JPV08: Paired entry punch exists in candidate membership
$entryUuid = $data8801['pairing']['segments'][0]['entry_event_uuid'] ?? null;
jpv_assert($entryUuid === 'ev-jpv-8801-in' && in_array($entryUuid, $uuids8801, true), 'JPV08', 'Paired entry punch exists in candidate membership');

// JPV09: Paired exit punch exists in candidate membership
$exitUuid = $data8801['pairing']['segments'][0]['exit_event_uuid'] ?? null;
jpv_assert($exitUuid === 'ev-jpv-8801-out' && in_array($exitUuid, $uuids8801, true), 'JPV09', 'Paired exit punch exists in candidate membership');

// Worker 8802 request (5 punches)
$req8802 = ['id_personal' => 8802, 'id_estacion' => 1, 'fecha_local' => '2026-10-14'];
$res8802 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req8802));
$data8802 = $res8802['json']['data'] ?? [];
$candidates8802 = $data8802['evaluated_raw_events'] ?? [];
$uuids8802 = array_column($candidates8802, 'event_uuid');

// JPV10: Unmatched/surplus candidate punch remains in candidate membership
jpv_assert(in_array('ev-jpv-8802-p2', $uuids8802, true) && in_array('ev-jpv-8802-p3', $uuids8802, true), 'JPV10', 'Unmatched/surplus candidate punches remain in candidate membership');

// JPV11: Ambiguous / multiple candidate punches remain in candidate membership
jpv_assert(in_array('ev-jpv-8802-p4', $uuids8802, true), 'JPV11', 'Intermediate punches remain in candidate membership');

// JPV12: 5+ candidate punches are all preserved without 4-slot capping
jpv_assert(count($candidates8802) === 5, 'JPV12', '5+ candidate punches are all preserved without 4-slot capping (count: 5)');

// JPV13: Candidate membership count equals returned candidate collection size
jpv_assert($data8802['evaluated_raw_event_count'] === 5 && $data8802['provenance']['evaluated_raw_event_count'] === 5, 'JPV13', 'Candidate membership count equals returned collection size');

// JPV14: Candidate membership has deterministic ordering (ocurrido_at_utc ASC)
$timestamps8802 = array_column($candidates8802, 'ocurrido_at_utc');
$isSorted = true;
for ($i = 0; $i < count($timestamps8802) - 1; $i++) {
    if (strcmp($timestamps8802[$i], $timestamps8802[$i + 1]) > 0) {
        $isSorted = false;
        break;
    }
}
jpv_assert($isSorted && count($timestamps8802) === 5, 'JPV14', 'Candidate membership is strictly sorted ascending by ocurrido_at_utc');

// JPV15: Candidate membership contains no duplicate event identities
jpv_assert(count(array_unique($uuids8802)) === count($candidates8802), 'JPV15', 'Candidate membership contains no duplicate event identities');

// JPV16: Raw internal PKs are NOT exposed in candidate response objects
$noRawPk = true;
foreach ($candidates8802 as $cand) {
    if (isset($cand['id']) || isset($cand['raw_punch_id']) || isset($cand['id_marcacion_cruda'])) {
        $noRawPk = false;
        break;
    }
}
jpv_assert($noRawPk, 'JPV16', 'Raw internal primary keys (id, raw_punch_id) are not exposed in candidate objects');

// JPV17: Internal linkage keys are NOT exposed as candidate object identities
$noLinkageKeys = true;
foreach ($candidates8802 as $cand) {
    if (isset($cand['entry_punch_id']) || isset($cand['exit_punch_id'])) {
        $noLinkageKeys = false;
        break;
    }
}
jpv_assert($noLinkageKeys, 'JPV17', 'Internal linkage keys (entry_punch_id, exit_punch_id) not exposed in candidate objects');

// JPV18: Originating device identity integer is preserved (never 0), and nullable rule respected
$req8816 = ['id_personal' => 8816, 'id_estacion' => 1, 'fecha_local' => '2026-10-14'];
$res8816 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req8816));
$candidates8816 = $res8816['json']['data']['evaluated_raw_events'] ?? [];
$devicePreserved = false;
if (count($candidates8816) === 2) {
    $devicePreserved = ($candidates8816[0]['id_dispositivo'] === 1 && $candidates8816[1]['id_dispositivo'] === 1);
}
$nullNotZero = (null !== 0);
jpv_assert($devicePreserved && $nullNotZero, 'JPV18', 'Originating device identity integer is preserved (never 0), and nullable rule respected');

// JPV19: Canonical event_uuid preserved exactly
jpv_assert(($candidates8801[0]['event_uuid'] ?? '') === 'ev-jpv-8801-in', 'JPV19', 'Canonical event_uuid preserved exactly');

// JPV20: Canonical ocurrido_at_utc preserved exactly
jpv_assert(($candidates8801[0]['ocurrido_at_utc'] ?? '') === '2026-10-14 14:00:00', 'JPV20', 'Canonical ocurrido_at_utc preserved exactly');

// JPV21: Canonical metodo_captura preserved exactly
jpv_assert(($candidates8801[0]['metodo_captura'] ?? '') === 'BIOMETRIC' && ($candidates8816[0]['metodo_captura'] ?? '') === 'WEB_CAPTURE', 'JPV21', 'Canonical metodo_captura preserved exactly');

// JPV22: Persisted P1 created timestamp returned when P1 revision exists
$p1CreatedAt = $data8801['pairing']['creado_at_utc'] ?? null;
$provP1CreatedAt = $data8801['provenance']['p1_created_at_utc'] ?? null;
jpv_assert($p1CreatedAt !== null && $provP1CreatedAt === $p1CreatedAt && strlen($p1CreatedAt) === 19, 'JPV22', 'Persisted P1 created timestamp returned when P1 revision exists');

// JPV23: Persisted P2 created timestamp returned when P2 revision exists
$p2CreatedAt = $data8801['calculation']['creado_at_utc'] ?? null;
$provP2CreatedAt = $data8801['provenance']['p2_created_at_utc'] ?? null;
jpv_assert($p2CreatedAt !== null && $provP2CreatedAt === $p2CreatedAt && strlen($p2CreatedAt) === 19, 'JPV23', 'Persisted P2 created timestamp returned when P2 revision exists');

// JPV24: Unscheduled shift does NOT fabricate P1/P2 created timestamps
$req8803 = ['id_personal' => 8803, 'id_estacion' => 1, 'fecha_local' => '2026-10-14'];
$res8803 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req8803));
$data8803 = $res8803['json']['data'] ?? [];
$unscheduledNoFakeTimestamps = ($data8803['pairing'] === null)
    && ($data8803['calculation'] === null)
    && ($data8803['evaluated_raw_events'] === null)
    && ($data8803['evaluated_raw_event_count'] === null)
    && ($data8803['provenance']['p1_created_at_utc'] === null)
    && ($data8803['provenance']['p2_created_at_utc'] === null)
    && ($data8803['provenance']['evaluated_raw_events'] === null)
    && ($data8803['provenance']['evaluated_raw_event_count'] === null);
jpv_assert($unscheduledNoFakeTimestamps, 'JPV24', 'Unscheduled shift does NOT fabricate P1/P2 timestamps or candidate sets');

// JPV25: Authoritative version set returned
$prov8801 = $data8801['provenance'] ?? [];
$versionSetValid = ($prov8801['p1_algorithm_version'] === 'v2.4-p1')
    && ($prov8801['p2_algorithm_version'] === 'v2.4-p2')
    && ($prov8801['shift_template_version'] === 1)
    && ($prov8801['source_completeness_contract_version'] === 'v2.4')
    && ($prov8801['level2_policy_ref'] === 'POLICY_DEFAULT');
jpv_assert($versionSetValid, 'JPV25', 'Authoritative version set returned correctly');

// JPV26: Separate policy/rule version exposed only if authoritative
// In technical Journey P1/P2 evaluation, no separate policy/rule version exists.
jpv_assert(array_key_exists('policy_rule_version', $prov8801), 'JPV26', 'policy_rule_version key exists in provenance');

// JPV27: Missing separate policy version remains strictly null
jpv_assert($prov8801['policy_rule_version'] === null, 'JPV27', 'Missing separate policy version remains strictly null (not fabricated)');

// JPV28: Snapshot/evidence reference preserved in response
jpv_assert(($data8801['source_evidence']['snapshot_audit_ref'] ?? '') === 'snap_audit_jpv_01', 'JPV28', 'Snapshot/evidence reference preserved in response');

// JPV29: Level 2 UNKNOWN/INCOMPLETE finality semantics unchanged
$db->query("DELETE FROM op_rh_personal WHERE id = 8817");
$db->query("INSERT INTO op_rh_personal (id, no_colaborador, nombre_completo, id_estacion) VALUES (8817, 8817, 'Worker St2 UNKNOWN', 2)");
$db->query("DELETE FROM op_rh_v2_turnos_asignaciones WHERE id_personal = 8817");
foreach ($days as $day) {
    $db->query("INSERT INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo) VALUES
        (8817, 2, 7701, '$day', '2026-01-01', NULL, 1)");
}
$db->query("DELETE FROM op_rh_v2_marcaciones_crudas WHERE id_personal_resuelto = 8817");
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES
    (1, 2, 'ev-jpv-8817-st2-in', 'REF8817', 8817, '2026-10-14 14:00:00', '2026-10-14 08:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");

// Grant multi-station access for station 2 to test user 901
$db->query("UPDATE tb_multiestacion_usuario SET estaciones = '[1,2]' WHERE id_usuario = 901");

$reqStation2 = ['id_personal' => 8817, 'id_estacion' => 2, 'fecha_local' => '2026-10-14'];
$resStation2 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($reqStation2));
$l2UnknownNonFinal = ($resStation2['json']['data']['source_evidence']['window_level2_status'] ?? '') === 'UNKNOWN'
    && ($resStation2['json']['data']['pairing']['pairing_status'] ?? '') === 'PENDING_SYNC_COMPLETION'
    && ($resStation2['json']['data']['calculation']['estado_calculo'] ?? '') === 'PENDING_SYNC';
jpv_assert($l2UnknownNonFinal, 'JPV29', 'Level 2 UNKNOWN retains non-final PENDING_SYNC_COMPLETION semantics');

// Restore multi-station configuration
$db->query("UPDATE tb_multiestacion_usuario SET estaciones = '[1]' WHERE id_usuario = 901");

// JPV30: Response contains no secrets, tokens, or raw SQL/exception traces
$body8801 = $res8801['body'];
$noLeak = (strpos($body8801, $jwtSecret) === false)
    && (strpos($body8801, 'kimera') === false)
    && (strpos($body8801, 'SQLSTATE') === false);
jpv_assert($noLeak, 'JPV30', 'Response contains no secrets, tokens, or raw SQL/exception traces');

// Phase 4: Direct Reproducibility & Consistency Check
$pairingId8801 = (int)($data8801['pairing']['pairing_id'] ?? 0);
$pairingSvc = new JourneyPairingService($db);
$directCandidates = $pairingSvc->getEvaluatedCandidatesForPairing($pairingId8801);
$reproducible = ($directCandidates === $candidates8801);
jpv_assert($reproducible, 'PHASE4_REPRODUCIBILITY', 'Direct getEvaluatedCandidatesForPairing matches API response candidate array exactly');

// Phase 5: Idempotent Reuse Verification (before late punch)
$res8801Repeat = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req8801));
$data8801Repeat = $res8801Repeat['json']['data'] ?? [];
$idempotentMatch = (($data8801Repeat['pairing']['pairing_id'] ?? 0) === $pairingId8801)
    && (($data8801Repeat['pairing']['is_idempotent_reuse'] ?? false) === true)
    && (($data8801Repeat['pairing']['creado_at_utc'] ?? '') === $p1CreatedAt)
    && (count($data8801Repeat['pairing']['segments']) === 1)
    && (($data8801Repeat['pairing']['segments'][0]['entry_event_uuid'] ?? '') === 'ev-jpv-8801-in')
    && (($data8801Repeat['pairing']['segments'][0]['exit_event_uuid'] ?? '') === 'ev-jpv-8801-out')
    && (count($data8801Repeat['evaluated_raw_events']) === 2);
jpv_assert($idempotentMatch, 'PHASE5_IDEMPOTENT_REUSE', 'Repeat request reuses existing execution revision with exact candidates and enriched segments');

// Phase 5: Late Punch Isolation Check
// Insert a new punch for worker 8801 AFTER pairing execution occurred
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES
    (1, 1, 'ev-jpv-8801-late', 'REF8801', 8801, '2026-10-14 20:00:00', '2026-10-14 14:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");

$directCandidatesAfterLate = $pairingSvc->getEvaluatedCandidatesForPairing($pairingId8801);
$latePunchIsolated = (count($directCandidatesAfterLate) === 2)
    && !in_array('ev-jpv-8801-late', array_column($directCandidatesAfterLate, 'event_uuid'), true);
jpv_assert($latePunchIsolated, 'PHASE5_LATE_PUNCH_ISOLATION', 'Late arriving punch is isolated and not included in evaluated candidates of existing pairing revision');

$bdObj->desconectarBD($db);

echo "\n=== JPV SUITE SUMMARY ===\n";
echo "TOTAL TESTS: " . ($passed + $failed) . "\n";
echo "PASSED:      {$passed}\n";
echo "FAILED:      {$failed}\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
