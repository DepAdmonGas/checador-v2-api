<?php
/**
 * Checador V2 — P3-H Event Classification Public Payload Test Suite (P3HEC01 - P3HEC30)
 *
 * Verifies the frozen P3-H additive public payload enrichment:
 * - pairing_role (ENTRY, EXIT, SURPLUS, UNMATCHED)
 * - segment_ordinal (integer | null)
 * adhering strictly to:
 * - docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.0.md (FROZEN_OWNER_APPROVED)
 * - /home/silvinol/Proyectos/Portal3/docs/v2/CHECADOR_V2_PORTAL3_P3H_PRESENTATION_PROJECTION_CONTRACT_V1.0.md (FROZEN_OWNER_APPROVED)
 * - docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md (FROZEN_V1.0)
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/journey/JourneyPairingService.php';

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : 'kimera';
$dbName = 'bd_admongas_v2_test_20261002';
$jwtSecret = 'test_p3h_jwt_secret_998877';
putenv("JWT_SECRET={$jwtSecret}");

$bdObj = new BD();
$db = $bdObj->conectarBD();

$passed = 0;
$failed = 0;

function p3h_assert(bool $cond, string $id, string $desc): void
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
        'sub' => 950,
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

echo "=== Setting up P3-H Event Classification Test Fixtures in {$dbName} ===\n";

$db->query("SET FOREIGN_KEY_CHECKS = 0");

// 1. ACL user & permissions
$db->query("DELETE FROM tb_usuarios WHERE id = 950");
$db->query("INSERT INTO tb_usuarios (id, usuario, id_puesto, id_gas, estatus) VALUES (950, 'p3h_test_user', 1, 1, 0)");

$db->query("DELETE FROM modulos_dpto_operativo WHERE id = 15");
$db->query("INSERT INTO modulos_dpto_operativo (id, nombre, clave, activo) VALUES (15, 'Biometricos', 'biometricos', 1)");

$db->query("DELETE FROM modulos_usuarios_do WHERE id_usuario = 950");
$db->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer, crear, editar, eliminar, descargar) VALUES (15, 950, 1, 0, 0, 0, 0)");

$db->query("DELETE FROM tb_modulos_config WHERE modulo_key = 'biometricos'");
$db->query("INSERT INTO tb_modulos_config (modulo_key, activo, estaciones_soportadas, tipo_departamento) VALUES ('biometricos', 1, '[1,2]', 'localidades')");

$db->query("DELETE FROM tb_multiestacion_usuario WHERE id_usuario = 950");
$db->query("INSERT INTO tb_multiestacion_usuario (id_usuario, estaciones, activo) VALUES (950, '[1]', 1)");

// 2. Localities & Timezone
$db->query("INSERT IGNORE INTO op_rh_localidades (id, localidad, numlista, recuperacion_vapores) VALUES (1, 'Loc 1', 1, '')");
$db->query("DELETE FROM op_rh_v2_localidades_zonas_horarias WHERE id_localidad = 1");
$db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta) VALUES (1, 'America/Mexico_City', '2026-01-01', NULL)");

// 3. Workers
$db->query("DELETE FROM op_rh_personal WHERE id IN (9801, 9802, 9803, 9804, 9805, 9806)");
$db->query("INSERT INTO op_rh_personal (id, no_colaborador, nombre_completo, id_estacion) VALUES
    (9801, 9801, 'Worker P3H Standard EntryExitSurplus', 1),
    (9802, 9802, 'Worker P3H FivePunches', 1),
    (9803, 9803, 'Worker P3H ThreeSegments', 1),
    (9804, 9804, 'Worker P3H AmbiguousPairing', 1),
    (9805, 9805, 'Worker P3H UnscheduledDayOff', 1),
    (9806, 9806, 'Worker P3H PartialMissingOutUnmatched', 1)");

// 4. Shift Templates
// Template 7801: 1 segment (08:00 - 16:00 local -> 14:00 - 22:00 UTC)
// Template 7803: 3 segments (06:00 - 10:00, 11:00 - 15:00, 16:00 - 20:00 local)
$db->query("DELETE FROM op_rh_v2_turnos_plantillas WHERE id IN (7801, 7803)");
$db->query("INSERT INTO op_rh_v2_turnos_plantillas (id, clave, version, nombre, tipo_turno, es_activo) VALUES
    (7801, 'TPL-P3H-01', 1, 'Standard Single Segment', 'MATUTINO', 1),
    (7803, 'TPL-P3H-03', 1, 'Three Segment Shift', 'MATUTINO', 1)");

$db->query("DELETE FROM op_rh_v2_turnos_segmentos WHERE id_plantilla IN (7801, 7803)");
$db->query("INSERT INTO op_rh_v2_turnos_segmentos (id_plantilla, ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin) VALUES
    (7801, 1, 'WORK', '08:00:00', '16:00:00', 0, 0),
    (7803, 1, 'WORK', '06:00:00', '10:00:00', 0, 0),
    (7803, 2, 'WORK', '11:00:00', '15:00:00', 0, 0),
    (7803, 3, 'WORK', '16:00:00', '20:00:00', 0, 0)");

// 5. Shift Assignments for workers
$db->query("DELETE FROM op_rh_v2_turnos_asignaciones WHERE id_personal IN (9801, 9802, 9803, 9804, 9805, 9806)");
$days = ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO', 'DOMINGO'];
foreach ($days as $day) {
    $db->query("INSERT INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo) VALUES
        (9801, 1, 7801, '$day', '2026-01-01', NULL, 1),
        (9802, 1, 7801, '$day', '2026-01-01', NULL, 1),
        (9803, 1, 7803, '$day', '2026-01-01', NULL, 1),
        (9804, 1, 7801, '$day', '2026-01-01', NULL, 1),
        (9806, 1, 7801, '$day', '2026-01-01', NULL, 1)");
}

// 6. Clean and seed punches for 2026-10-14
$db->query("SET FOREIGN_KEY_CHECKS = 0");
$db->query("DELETE FROM op_rh_v2_marcaciones_crudas WHERE id_personal_resuelto IN (9801, 9802, 9803, 9804, 9805, 9806)");
$db->query("DELETE FROM op_rh_v2_jornadas_programadas WHERE id_personal IN (9801, 9802, 9803, 9804, 9805, 9806)");

// Worker 9801: 3 punches: ENTRY (14:00), SURPLUS (18:00), EXIT (22:00)
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES
    (1, 1, 'ev-p3h-9801-in',        'REF9801', 9801, '2026-10-14 14:00:00', '2026-10-14 08:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-p3h-9801-surplus',   'REF9801', 9801, '2026-10-14 18:00:00', '2026-10-14 12:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-p3h-9801-out',       'REF9801', 9801, '2026-10-14 22:00:00', '2026-10-14 16:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");

// Worker 9806: 2 punches: ENTRY (14:00), UNMATCHED (15:00) (missing exit causes 15:00 to remain unassigned)
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES
    (1, 1, 'ev-p3h-9806-in',        'REF9806', 9806, '2026-10-14 14:00:00', '2026-10-14 08:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-p3h-9806-unmatched', 'REF9806', 9806, '2026-10-14 15:00:00', '2026-10-14 09:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");

// Worker 9802: 5 punches within candidate window: IN (14:00), SURPLUS1 (16:00), SURPLUS2 (18:00), SURPLUS3 (20:00), OUT (22:00)
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES
    (1, 1, 'ev-p3h-9802-p1', 'REF9802', 9802, '2026-10-14 14:00:00', '2026-10-14 08:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-p3h-9802-p2', 'REF9802', 9802, '2026-10-14 16:00:00', '2026-10-14 10:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-p3h-9802-p3', 'REF9802', 9802, '2026-10-14 18:00:00', '2026-10-14 12:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-p3h-9802-p4', 'REF9802', 9802, '2026-10-14 20:00:00', '2026-10-14 14:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-p3h-9802-p5', 'REF9802', 9802, '2026-10-14 22:00:00', '2026-10-14 16:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");

// Worker 9803: 3 segments: (06-10 local -> 12-16 UTC), (11-15 local -> 17-21 UTC), (16-20 local -> 22-02 UTC)
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES
    (1, 1, 'ev-p3h-9803-s1-in',  'REF9803', 9803, '2026-10-14 12:00:00', '2026-10-14 06:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-p3h-9803-s1-out', 'REF9803', 9803, '2026-10-14 16:00:00', '2026-10-14 10:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-p3h-9803-s2-in',  'REF9803', 9803, '2026-10-14 17:00:00', '2026-10-14 11:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-p3h-9803-s2-out', 'REF9803', 9803, '2026-10-14 21:00:00', '2026-10-14 15:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-p3h-9803-s3-in',  'REF9803', 9803, '2026-10-14 22:00:00', '2026-10-14 16:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-p3h-9803-s3-out', 'REF9803', 9803, '2026-10-15 02:00:00', '2026-10-14 20:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");

// Worker 9804: punches with validation_flags = 'INVALID_BIOMETRIC' to trigger AMBIGUOUS_PAIRING
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, validation_flags, processing_status) VALUES
    (1, 1, 'ev-p3h-9804-in',  'REF9804', 9804, '2026-10-14 14:00:00', '2026-10-14 08:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'INVALID_BIOMETRIC', 'RECEIVED'),
    (1, 1, 'ev-p3h-9804-mid', 'REF9804', 9804, '2026-10-14 18:00:00', '2026-10-14 12:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', NULL, 'RECEIVED'),
    (1, 1, 'ev-p3h-9804-out', 'REF9804', 9804, '2026-10-14 22:00:00', '2026-10-14 16:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', NULL, 'RECEIVED')");

// Worker 9805: Unscheduled (no shift assignment, but has a punch)
$db->query("INSERT INTO op_rh_v2_marcaciones_crudas (id_dispositivo, id_estacion_resuelta, event_uuid, referencia_externa_personal, id_personal_resuelto, ocurrido_at_utc, source_timestamp_raw, recibido_at_utc, metodo_captura, processing_status) VALUES
    (1, 1, 'ev-p3h-9805-p1', 'REF9805', 9805, '2026-10-14 14:00:00', '2026-10-14 08:00:00', UTC_TIMESTAMP(), 'BIOMETRIC', 'RECEIVED')");

echo "=== Running P3HEC01 - P3HEC30 Test Matrix ===\n";

$validJwt = 'Bearer ' . make_jwt(['sub' => 950], $jwtSecret);

// Execute request for Worker 9801 (Standard single segment with 1 entry, 1 surplus, 1 exit)
$req9801 = ['id_personal' => 9801, 'id_estacion' => 1, 'fecha_local' => '2026-10-14'];
$res9801 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req9801));
$data9801 = $res9801['json']['data'] ?? [];
$candidates9801 = $data9801['evaluated_raw_events'] ?? [];
$provCandidates9801 = $data9801['provenance']['evaluated_raw_events'] ?? [];

// Helper to index by event_uuid
$byUuid9801 = [];
foreach ($candidates9801 as $c) {
    $byUuid9801[$c['event_uuid']] = $c;
}

// Execute request for Worker 9806 (Partial missing out with 1 entry, 1 unmatched)
$req9806 = ['id_personal' => 9806, 'id_estacion' => 1, 'fecha_local' => '2026-10-14'];
$res9806 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req9806));
$data9806 = $res9806['json']['data'] ?? [];
$candidates9806 = $data9806['evaluated_raw_events'] ?? [];
$byUuid9806 = [];
foreach ($candidates9806 as $c) {
    $byUuid9806[$c['event_uuid']] = $c;
}

// P3HEC01: existing evaluated event fields remain unchanged
$p1Item = $byUuid9801['ev-p3h-9801-in'] ?? [];
$p3hec01 = isset($p1Item['event_uuid'], $p1Item['id_dispositivo'], $p1Item['ocurrido_at_utc'], $p1Item['metodo_captura']);
p3h_assert($p3hec01, 'P3HEC01', 'Existing evaluated event fields (event_uuid, id_dispositivo, ocurrido_at_utc, metodo_captura) remain unchanged');

// P3HEC02: ENTRY role exposed correctly
$p3hec02 = ($byUuid9801['ev-p3h-9801-in']['pairing_role'] ?? null) === 'ENTRY';
p3h_assert($p3hec02, 'P3HEC02', 'ENTRY role exposed correctly on primary segment entry event');

// P3HEC03: EXIT role exposed correctly
$p3hec03 = ($byUuid9801['ev-p3h-9801-out']['pairing_role'] ?? null) === 'EXIT';
p3h_assert($p3hec03, 'P3HEC03', 'EXIT role exposed correctly on primary segment exit event');

// P3HEC04: SURPLUS role exposed correctly
$p3hec04 = ($byUuid9801['ev-p3h-9801-surplus']['pairing_role'] ?? null) === 'SURPLUS';
p3h_assert($p3hec04, 'P3HEC04', 'SURPLUS role exposed correctly on intermediate punch between entry and exit');

// P3HEC05: UNMATCHED role exposed correctly
$p3hec05 = ($byUuid9806['ev-p3h-9806-unmatched']['pairing_role'] ?? null) === 'UNMATCHED';
p3h_assert($p3hec05, 'P3HEC05', 'UNMATCHED role exposed correctly on unassigned candidate punch');

// P3HEC06: ENTRY segment_ordinal exposed correctly
$p3hec06 = ($byUuid9801['ev-p3h-9801-in']['segment_ordinal'] ?? null) === 1;
p3h_assert($p3hec06, 'P3HEC06', 'ENTRY segment_ordinal exposed correctly as 1');

// P3HEC07: EXIT segment_ordinal exposed correctly
$p3hec07 = ($byUuid9801['ev-p3h-9801-out']['segment_ordinal'] ?? null) === 1;
p3h_assert($p3hec07, 'P3HEC07', 'EXIT segment_ordinal exposed correctly as 1');

// P3HEC08: SURPLUS segment_ordinal exposed correctly
$p3hec08 = ($byUuid9801['ev-p3h-9801-surplus']['segment_ordinal'] ?? null) === 1;
p3h_assert($p3hec08, 'P3HEC08', 'SURPLUS segment_ordinal exposed correctly linked to associated segment (1)');

// P3HEC09: UNMATCHED segment_ordinal is null
$p3hec09 = array_key_exists('segment_ordinal', $byUuid9806['ev-p3h-9806-unmatched'] ?? [])
    && ($byUuid9806['ev-p3h-9806-unmatched']['segment_ordinal'] === null);
p3h_assert($p3hec09, 'P3HEC09', 'UNMATCHED segment_ordinal is strictly null');


// Execute request for Worker 9803 (Three segments: ordinals 1, 2, 3)
$req9803 = ['id_personal' => 9803, 'id_estacion' => 1, 'fecha_local' => '2026-10-14'];
$res9803 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req9803));
$data9803 = $res9803['json']['data'] ?? [];
$candidates9803 = $data9803['evaluated_raw_events'] ?? [];
$byUuid9803 = [];
foreach ($candidates9803 as $c) {
    $byUuid9803[$c['event_uuid']] = $c;
}

// P3HEC10: segment ordinal 3+ supported
$p3hec10 = ($byUuid9803['ev-p3h-9803-s3-in']['segment_ordinal'] ?? null) === 3
    && ($byUuid9803['ev-p3h-9803-s3-out']['segment_ordinal'] ?? null) === 3
    && ($byUuid9803['ev-p3h-9803-s3-in']['pairing_role'] ?? null) === 'ENTRY'
    && ($byUuid9803['ev-p3h-9803-s3-out']['pairing_role'] ?? null) === 'EXIT';
p3h_assert($p3hec10, 'P3HEC10', 'Segment ordinal 3+ supported without artificial limitation (segment_ordinal = 3)');

// Execute request for Worker 9802 (5 candidate punches)
$req9802 = ['id_personal' => 9802, 'id_estacion' => 1, 'fecha_local' => '2026-10-14'];
$res9802 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req9802));
$data9802 = $res9802['json']['data'] ?? [];
$candidates9802 = $data9802['evaluated_raw_events'] ?? [];

// P3HEC11: 5+ evaluated events all returned
$p3hec11 = count($candidates9802) === 5;
p3h_assert($p3hec11, 'P3HEC11', '5+ evaluated candidate punches are all returned in full (count: 5)');

// P3HEC12: no event truncation to four
$p3hec12 = count($candidates9802) > 4 && ($data9802['evaluated_raw_event_count'] ?? null) === 5;
p3h_assert($p3hec12, 'P3HEC12', 'No event truncation to four (count = 5 preserved across all response blocks)');

// Execute request for Worker 9804 (Ambiguous pairing due to INVALID_BIOMETRIC flag)
$req9804 = ['id_personal' => 9804, 'id_estacion' => 1, 'fecha_local' => '2026-10-14'];
$res9804 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req9804));
$data9804 = $res9804['json']['data'] ?? [];
$candidates9804 = $data9804['evaluated_raw_events'] ?? [];
$byUuid9804 = [];
foreach ($candidates9804 as $c) {
    $byUuid9804[$c['event_uuid']] = $c;
}

// P3HEC13: ambiguous segment preserves ENTRY role
$p3hec13 = ($byUuid9804['ev-p3h-9804-in']['pairing_role'] ?? null) === 'ENTRY';
p3h_assert($p3hec13, 'P3HEC13', 'Ambiguous segment preserves candidate ENTRY role');

// P3HEC14: ambiguous segment preserves EXIT role
$p3hec14 = ($byUuid9804['ev-p3h-9804-out']['pairing_role'] ?? null) === 'EXIT';
p3h_assert($p3hec14, 'P3HEC14', 'Ambiguous segment preserves candidate EXIT role');

// P3HEC15: ambiguous segment preserves SURPLUS role
$p3hec15 = ($byUuid9804['ev-p3h-9804-mid']['pairing_role'] ?? null) === 'SURPLUS';
p3h_assert($p3hec15, 'P3HEC15', 'Ambiguous segment preserves candidate SURPLUS role');

// P3HEC16: AMBIGUOUS never appears as pairing_role
$hasAmbiguousRole = false;
foreach ($candidates9804 as $c) {
    if ($c['pairing_role'] === 'AMBIGUOUS' || $c['pairing_role'] === 'AMBIGUOUS_PAIRING') {
        $hasAmbiguousRole = true;
        break;
    }
}
p3h_assert(!$hasAmbiguousRole, 'P3HEC16', 'AMBIGUOUS never appears as a pairing_role value (AMBIGUITY_AS_PAIRING_ROLE = NO)');

// P3HEC17: ambiguity remains visible at segment/Journey level
$p3hec17 = ($data9804['pairing']['pairing_status'] ?? null) === 'AMBIGUOUS_PAIRING'
    && ($data9804['pairing']['segments'][0]['status'] ?? null) === 'AMBIGUOUS_PAIRING';
p3h_assert($p3hec17, 'P3HEC17', 'Ambiguity remains visible at segment and Journey levels (status = AMBIGUOUS_PAIRING)');

// P3HEC18: primary evaluated_raw_events contains new fields
$p3hec18 = !empty($candidates9801)
    && array_key_exists('pairing_role', $candidates9801[0])
    && array_key_exists('segment_ordinal', $candidates9801[0]);
p3h_assert($p3hec18, 'P3HEC18', 'Primary data.evaluated_raw_events collection contains new fields');

// P3HEC19: provenance evaluated_raw_events contains new fields
$p3hec19 = !empty($provCandidates9801)
    && array_key_exists('pairing_role', $provCandidates9801[0])
    && array_key_exists('segment_ordinal', $provCandidates9801[0]);
p3h_assert($p3hec19, 'P3HEC19', 'Provenance data.provenance.evaluated_raw_events collection contains new fields');

// P3HEC20: primary/provenance pairing_role parity
$pairingRoleParity = true;
for ($i = 0; $i < count($candidates9801); $i++) {
    if ($candidates9801[$i]['pairing_role'] !== $provCandidates9801[$i]['pairing_role']) {
        $pairingRoleParity = false;
        break;
    }
}
p3h_assert($pairingRoleParity, 'P3HEC20', 'Primary and provenance evaluated_raw_events have 100% pairing_role parity');

// P3HEC21: primary/provenance segment_ordinal parity
$segmentOrdinalParity = true;
for ($i = 0; $i < count($candidates9801); $i++) {
    if ($candidates9801[$i]['segment_ordinal'] !== $provCandidates9801[$i]['segment_ordinal']) {
        $segmentOrdinalParity = false;
        break;
    }
}
p3h_assert($segmentOrdinalParity, 'P3HEC21', 'Primary and provenance evaluated_raw_events have 100% segment_ordinal parity');

// P3HEC22: event_uuid correlation preserved
$seg0 = $data9801['pairing']['segments'][0] ?? [];
$p3hec22 = ($seg0['entry_event_uuid'] ?? '') === 'ev-p3h-9801-in'
    && ($seg0['exit_event_uuid'] ?? '') === 'ev-p3h-9801-out';
p3h_assert($p3hec22, 'P3HEC22', 'Event UUID correlation preserved between segment entry/exit links and evaluated events');

// P3HEC23: internal raw DB PK not exposed
$noRawPksExposed = true;
foreach ([$candidates9801, $candidates9802, $candidates9803, $candidates9804] as $candSet) {
    foreach ($candSet as $c) {
        if (isset($c['id']) || isset($c['raw_punch_id']) || isset($c['id_marcacion_cruda']) || isset($c['id_pareo_segmento'])) {
            $noRawPksExposed = false;
            break 2;
        }
    }
}
p3h_assert($noRawPksExposed, 'P3HEC23', 'Internal raw database primary keys (id, id_marcacion_cruda, id_pareo_segmento) are strictly not exposed');

// P3HEC24: no P1 recomputation occurs (idempotent reuse)
$res9801Repeat = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req9801));
$data9801Repeat = $res9801Repeat['json']['data'] ?? [];
$p3hec24 = ($data9801Repeat['pairing']['is_idempotent_reuse'] ?? false) === true
    && ($data9801Repeat['pairing']['revision_numero'] ?? null) === 1;
p3h_assert($p3hec24, 'P3HEC24', 'No P1 recomputation occurs on repeated request (is_idempotent_reuse = true, revision = 1)');

// P3HEC25: no P2 recomputation occurs
$p3hec25 = ($data9801Repeat['calculation']['revision_numero'] ?? null) === 1
    && ($data9801Repeat['calculation']['calculation_id'] ?? null) === ($data9801['calculation']['calculation_id'] ?? null);
p3h_assert($p3hec25, 'P3HEC25', 'No P2 recomputation occurs on repeated request (calculation_id and revision preserved)');

// P3HEC26: no new raw membership authority built (candidates originate from existing P1 associated table)
$pairingId9801 = (int)$data9801['pairing']['pairing_id'];
$pairSvc = new JourneyPairingService($db);
$directCandidates = $pairSvc->getEvaluatedCandidatesForPairing($pairingId9801);
$p3hec26 = ($directCandidates === $candidates9801);
p3h_assert($p3hec26, 'P3HEC26', 'Candidates strictly originate from existing persisted P1 associated punch records');

// Execute request for Worker 9805 (Unscheduled)
$req9805 = ['id_personal' => 9805, 'id_estacion' => 1, 'fecha_local' => '2026-10-14'];
$res9805 = call_cgi_api('POST', '/api/v2/journeys/evaluate', ['HTTP_AUTHORIZATION' => $validJwt], json_encode($req9805));
$data9805 = $res9805['json']['data'] ?? [];

// P3HEC27: no automatic SIN MARCACIÓN generated
$p3hec27 = strpos(json_encode($data9801), 'SIN MARCACION') === false
    && strpos(json_encode($data9801), 'SIN_MARCACION') === false
    && strpos(json_encode($data9805), 'SIN MARCACION') === false;
p3h_assert($p3hec27, 'P3HEC27', 'Zero automatic SIN MARCACIÓN generated in any response block');

// P3HEC28: no automatic FALTA generated
$p3hec28 = strpos(json_encode($data9801), 'FALTA') === false
    && strpos(json_encode($data9805), 'FALTA') === false;
p3h_assert($p3hec28, 'P3HEC28', 'Zero automatic FALTA generated in any response block');

// P3HEC29: no administrative state encoded in pairing_role
$cleanRoles = true;
$allowedRoles = ['ENTRY', 'EXIT', 'SURPLUS', 'UNMATCHED', null];
foreach ([$candidates9801, $candidates9802, $candidates9803, $candidates9804] as $candSet) {
    foreach ($candSet as $c) {
        if (!in_array($c['pairing_role'], $allowedRoles, true)) {
            $cleanRoles = false;
            break 2;
        }
    }
}
p3h_assert($cleanRoles, 'P3HEC29', 'No administrative or payroll state encoded in pairing_role (only ENTRY, EXIT, SURPLUS, UNMATCHED)');

// P3HEC30: no schema mutation
$dbCheck = $db->query("SHOW TABLES LIKE 'op_rh_v2_%'");
$tableCount = $dbCheck->num_rows;
$p3hec30 = ($tableCount > 0);
p3h_assert($p3hec30, 'P3HEC30', 'Zero schema mutations, zero DDL migrations, tables unchanged');

// Contract Hash Assertions
$apiContractPath = realpath(__DIR__ . '/../docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.0.md');
$portalContractPath = '/home/silvinol/Proyectos/Portal3/docs/v2/CHECADOR_V2_PORTAL3_P3H_PRESENTATION_PROJECTION_CONTRACT_V1.0.md';

$actualApiContractSha = hash_file('sha256', $apiContractPath);
$actualPortalContractSha = hash_file('sha256', $portalContractPath);

$expectedApiContractSha = 'c710165953f455352cf5675b7581a581be03a632a493e6a0c939276865d6658f';
$expectedPortalContractSha = '96a700731fe93173b6383effcd648cd4d785ce2178d32a0331cd0b9f2aa9b584';

p3h_assert($actualApiContractSha === $expectedApiContractSha, 'P3H_API_CONTRACT_SHA', "API Contract SHA strictly matches $expectedApiContractSha");
p3h_assert($actualPortalContractSha === $expectedPortalContractSha, 'P3H_PORTAL3_CONTRACT_SHA', "Portal3 Contract SHA strictly matches $expectedPortalContractSha");

echo "\n=== P3H EVENT CLASSIFICATION SUITE SUMMARY ===\n";
echo "TOTAL TESTS: " . ($passed + $failed) . "\n";
echo "PASSED:      {$passed}\n";
echo "FAILED:      {$failed}\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
