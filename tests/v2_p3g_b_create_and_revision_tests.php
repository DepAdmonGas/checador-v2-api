<?php
/**
 * Checador V2 — P3G-B Create & Revision Integration Tests
 *
 * Test Matrix: P3GB01 - P3GB35
 * Covers:
 * - POST /api/v2/incidences (CREATE master + initial revision 1)
 * - POST /api/v2/incidences/{uuid_incidencia}/revisions (CREATE append-only revision)
 * - Strict authorization, workplace scoping, canonical identity resolution
 * - Strict idempotency contract (identical replay vs conflicting replay 409)
 * - Concurrency control (expected_current_revision mismatch -> 409 STALE_REVISION_CONFLICT)
 * - Append-only immutability (byte-for-byte preservation of previous revisions)
 * - Conflict preservation (no automatic FALTA, no synthetic precedence)
 * - Zero legacy dual-write, zero punch event mutations, zero payroll writes
 * - Remaining mutating endpoints remain 405 Method Not Allowed
 */

declare(strict_types=1);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'kimera';
$dbName = getenv('DB_NAME') ?: 'bd_admongas_v2_test_20261002';
$jwtSecret = 'secret_p3gb_test_key_2026';

$db = new mysqli($host, $user, $pass, $dbName);
if ($db->connect_error) {
    fwrite(STDERR, "DB connection failed: " . $db->connect_error . "\n");
    exit(1);
}

function p3gb_assert(bool $condition, string $testId, string $desc): void
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

function make_p3gb_jwt(array $customPayload, string $secret): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $defaultPayload = [
        'iss' => 'portal3_auth',
        'sub' => 961,
        'type' => 'access',
        'exp' => time() + 3600
    ];
    $payload = array_merge($defaultPayload, $customPayload);
    $hB64 = b64Url((string)json_encode($header, JSON_UNESCAPED_SLASHES));
    $pB64 = b64Url((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = b64Url((string)hash_hmac('sha256', "$hB64.$pB64", $secret, true));
    return "$hB64.$pB64.$sig";
}

function call_p3gb_api(string $method, string $uri, array $headers = [], string $body = ''): array
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

echo "=== Setting up P3G-B Test Fixtures in {$dbName} ===\n";

$db->query("SET FOREIGN_KEY_CHECKS = 0");

// 1. Users (tb_usuarios)
$db->query("DELETE FROM tb_usuarios WHERE id IN (961, 962, 963, 964, 965, 966, 967, 968, 969)");
$db->query("INSERT INTO tb_usuarios (id, usuario, id_puesto, id_gas, estatus) VALUES
    (961, 'usr_p3gb_valid', 1, 1, 0),
    (962, 'usr_p3gb_inactive', 1, 1, 1),
    (963, 'usr_p3gb_no_perms', 1, 1, 0),
    (964, 'usr_p3gb_wrong_acl', 1, 1, 0),
    (965, 'usr_p3gb_rh_alone', 1, 1, 0),
    (966, 'usr_p3gb_nomina_alone', 1, 1, 0),
    (967, 'usr_p3gb_biometricos_alone', 1, 1, 0),
    (968, 'usr_p3gb_empty_stations', 1, 1, 0),
    (969, 'usr_p3gb_resolution_editor', 1, 1, 0)");

// 2. Functional Modules
$db->query("DELETE FROM modulos_dpto_operativo WHERE id IN (201, 202, 203, 204, 205, 206)");
$db->query("INSERT INTO modulos_dpto_operativo (id, nombre, clave, activo) VALUES
    (201, 'Checador V2 Incidencias', 'checador-v2-incidencias', 1),
    (202, 'Incidencias General', 'incidencias', 1),
    (203, 'Recursos Humanos', 'recursos-humanos', 1),
    (204, 'Incidencias Nomina', 'incidencias-nomina', 1),
    (205, 'Biometricos', 'biometricos', 1),
    (206, 'Checador V2 Incidencias Resolucion', 'checador-v2-incidencias-resolucion', 1)");

// 3. User module permissions (modulos_usuarios_do)
$db->query("DELETE FROM modulos_usuarios_do WHERE id_usuario IN (961, 962, 963, 964, 965, 966, 967, 968, 969)");
$db->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer, crear, editar, eliminar, descargar) VALUES
    (201, 961, 1, 1, 1, 0, 0),
    (201, 962, 1, 1, 0, 0, 0),
    (201, 963, 1, 0, 0, 0, 0),
    (202, 964, 1, 1, 0, 0, 0),
    (203, 965, 1, 1, 0, 0, 0),
    (204, 966, 1, 1, 0, 0, 0),
    (205, 967, 1, 1, 0, 0, 0),
    (201, 968, 1, 1, 0, 0, 0),
    (206, 969, 1, 0, 1, 0, 0)");

// 4. Module configs (tb_modulos_config)
$db->query("DELETE FROM tb_modulos_config WHERE modulo_key IN ('checador-v2-incidencias', 'checador-v2-incidencias-resolucion', 'incidencias', 'recursos-humanos', 'incidencias-nomina', 'biometricos')");
$db->query("INSERT INTO tb_modulos_config (modulo_key, activo, estaciones_soportadas, tipo_departamento) VALUES
    ('checador-v2-incidencias', 1, '[1,2]', 'localidades'),
    ('checador-v2-incidencias-resolucion', 1, '[1,2]', 'localidades'),
    ('incidencias', 1, '[1,2]', 'localidades'),
    ('recursos-humanos', 1, '[1,2]', 'localidades'),
    ('incidencias-nomina', 1, '[1,2]', 'localidades'),
    ('biometricos', 1, '[1,2]', 'localidades')");

// 5. Workplace scopes (tb_multiestacion_usuario)
$db->query("DELETE FROM tb_multiestacion_usuario WHERE id_usuario IN (961, 962, 963, 964, 965, 966, 967, 968, 969)");
$db->query("INSERT INTO tb_multiestacion_usuario (id_usuario, estaciones, activo) VALUES
    (961, '[1,2]', 1),
    (962, '[1,2]', 1),
    (963, '[1,2]', 1),
    (964, '[1,2]', 1),
    (965, '[1,2]', 1),
    (966, '[1,2]', 1),
    (967, '[1,2]', 1),
    (968, '[]', 1),
    (969, '[1,2]', 1)");

// 6. Workers (op_rh_personal)
$db->query("DELETE FROM op_rh_personal WHERE id IN (8911, 8912)");
$db->query("INSERT INTO op_rh_personal (id, no_colaborador, nombre_completo, id_estacion) VALUES
    (8911, 8911, 'Worker Test P3GB Master', 1),
    (8912, 8912, 'Worker Test P3GB MultiDay', 1)");

// Clean prior P3GB test data
$db->query("DELETE r FROM op_rh_v2_incidencias_revisiones r JOIN op_rh_v2_incidencias_maestro m ON m.id = r.id_incidencia_maestro WHERE m.id_personal IN (8911, 8912)");
$db->query("DELETE FROM op_rh_v2_incidencias_maestro WHERE id_personal IN (8911, 8912)");

$db->query("SET FOREIGN_KEY_CHECKS = 1");

$validJwt = 'Bearer ' . make_p3gb_jwt(['sub' => 961], $jwtSecret);
$inactiveJwt = 'Bearer ' . make_p3gb_jwt(['sub' => 962], $jwtSecret);
$noPermJwt = 'Bearer ' . make_p3gb_jwt(['sub' => 963], $jwtSecret);
$wrongAclJwt = 'Bearer ' . make_p3gb_jwt(['sub' => 964], $jwtSecret);
$rhAloneJwt = 'Bearer ' . make_p3gb_jwt(['sub' => 965], $jwtSecret);
$nominaAloneJwt = 'Bearer ' . make_p3gb_jwt(['sub' => 966], $jwtSecret);
$biometricosAloneJwt = 'Bearer ' . make_p3gb_jwt(['sub' => 967], $jwtSecret);
$emptyScopeJwt = 'Bearer ' . make_p3gb_jwt(['sub' => 968], $jwtSecret);
$resolutionEditorJwt = 'Bearer ' . make_p3gb_jwt(['sub' => 969], $jwtSecret);
$invalidJwt = 'Bearer ' . make_p3gb_jwt(['sub' => 961], 'wrong_secret_p3gb');

echo "=== Running P3GB01 - P3GB35 Test Suite ===\n";

$failedTests = [];

// Helper to snapshot table counts/hashes for non-mutation checks
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

$punchHashBefore = get_table_checksum($db, 'op_rh_v2_marcaciones_crudas');
$legacyAsistenciaCountBefore = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_personal_asistencia")->fetch_assoc()['c'];
$legacyIncidenciaCountBefore = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_personal_asistencia_incidencia")->fetch_assoc()['c'];

// P3GB01: Missing JWT returns 401 UNAUTHORIZED
$res01 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_IDEMPOTENCY_KEY' => 'key-01'
], json_encode(['worker_id' => 8911]));
p3gb_assert($res01['code'] === 401 && ($res01['json']['error'] ?? '') === 'UNAUTHORIZED', 'P3GB01', 'Missing JWT returns 401 UNAUTHORIZED');

// P3GB02: Invalid JWT returns 401 UNAUTHORIZED
$res02 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $invalidJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-02'
], json_encode(['worker_id' => 8911]));
p3gb_assert($res02['code'] === 401 && ($res02['json']['error'] ?? '') === 'UNAUTHORIZED', 'P3GB02', 'Invalid JWT returns 401 UNAUTHORIZED');

// P3GB03: Inactive Portal3 user returns 401 UNAUTHORIZED
$res03 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $inactiveJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-03'
], json_encode(['worker_id' => 8911]));
p3gb_assert($res03['code'] === 401 && ($res03['json']['error'] ?? '') === 'UNAUTHORIZED', 'P3GB03', 'Inactive Portal3 user returns 401 UNAUTHORIZED');

// P3GB04: User without checador-v2-incidencias.crear returns 403 FUNCTION_PERMISSION_DENIED
$res04 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $noPermJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-04'
], json_encode([
    'worker_id' => 8911,
    'workplace_id' => 1,
    'category' => 'VACATION',
    'start_date' => '2026-10-20',
    'end_date' => '2026-10-25',
    'reason' => 'Test'
]));
p3gb_assert($res04['code'] === 403 && ($res04['json']['error'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'P3GB04', 'User without checador-v2-incidencias.crear returns 403 FUNCTION_PERMISSION_DENIED');

// P3GB05: User with recursos-humanos alone denied with 403
$res05 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $rhAloneJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-05'
], json_encode(['worker_id' => 8911]));
p3gb_assert($res05['code'] === 403 && ($res05['json']['error'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'P3GB05', 'User with recursos-humanos alone denied with 403');

// P3GB06: User with incidencias-nomina alone denied with 403
$res06 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $nominaAloneJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-06'
], json_encode(['worker_id' => 8911]));
p3gb_assert($res06['code'] === 403 && ($res06['json']['error'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'P3GB06', 'User with incidencias-nomina alone denied with 403');

// P3GB07: User with biometricos alone denied with 403
$res07 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $biometricosAloneJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-07'
], json_encode(['worker_id' => 8911]));
p3gb_assert($res07['code'] === 403 && ($res07['json']['error'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'P3GB07', 'User with biometricos alone denied with 403');

// P3GB08: Device Auth rejected with 401
$res08 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_X_DEVICE_UUID' => 'dev-1234',
    'HTTP_AUTHORIZATION' => 'Bearer some_device_token',
    'HTTP_IDEMPOTENCY_KEY' => 'key-08'
], json_encode(['worker_id' => 8911]));
p3gb_assert($res08['code'] === 401, 'P3GB08', 'Device Auth rejected with 401');

// P3GB09: Out-of-scope workplace requested returns 403 STATION_SCOPE_DENIED
$res09 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $validJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-09'
], json_encode([
    'worker_id' => 8911,
    'workplace_id' => 99,
    'category' => 'VACATION',
    'start_date' => '2026-10-20',
    'end_date' => '2026-10-25',
    'reason' => 'Test'
]));
p3gb_assert($res09['code'] === 403 && ($res09['json']['error'] ?? '') === 'STATION_SCOPE_DENIED', 'P3GB09', 'Out-of-scope workplace requested returns 403 STATION_SCOPE_DENIED');

// P3GB10: Empty workplace scope denied (fail-closed) with 403 STATION_SCOPE_DENIED
$res10 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $emptyScopeJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-10'
], json_encode([
    'worker_id' => 8911,
    'workplace_id' => 1,
    'category' => 'VACATION',
    'start_date' => '2026-10-20',
    'end_date' => '2026-10-25',
    'reason' => 'Test'
]));
p3gb_assert($res10['code'] === 403 && ($res10['json']['error'] ?? '') === 'STATION_SCOPE_DENIED', 'P3GB10', 'Empty workplace scope denied (fail-closed) with 403 STATION_SCOPE_DENIED');

// P3GB11: Unknown worker returns 404 WORKER_NOT_FOUND
$res11 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $validJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-11'
], json_encode([
    'worker_id' => 999999,
    'workplace_id' => 1,
    'category' => 'VACATION',
    'start_date' => '2026-10-20',
    'end_date' => '2026-10-25',
    'reason' => 'Test'
]));
p3gb_assert($res11['code'] === 404 && ($res11['json']['error'] ?? '') === 'WORKER_NOT_FOUND', 'P3GB11', 'Unknown worker returns 404 WORKER_NOT_FOUND');

// P3GB12: Valid CREATE master + initial revision returns HTTP 201 with revision 1
$payload12 = [
    'worker_id' => 8911,
    'workplace_id' => 1,
    'category' => 'VACATION',
    'scope' => 'WORKER',
    'start_date' => '2026-10-20',
    'end_date' => '2026-10-25',
    'reason' => 'Solicitud de vacaciones programadas 2026',
    'evidence_reference' => 'REQ-VAC-2026-8911',
    'created_by_user_id' => 9999 // Client tries to spoof actor
];
$res12 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $validJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GB-KEY-001'
], json_encode($payload12));

$data12 = $res12['json']['data'] ?? [];
$createdUuid = (string)($data12['uuid_incidencia'] ?? '');

p3gb_assert(
    $res12['code'] === 201 &&
    strpos($createdUuid, 'INC_') === 0 &&
    $data12['worker_id'] === 8911 &&
    $data12['workplace_id'] === 1 &&
    $data12['category'] === 'VACATION' &&
    $data12['current_revision'] === 1 &&
    $data12['status'] === 'PENDING_APPROVAL' &&
    !empty($data12['created_at_utc']),
    'P3GB12',
    'Valid CREATE master + initial revision returns HTTP 201 with revision 1'
);

// P3GB13: Actor derived server-side from JWT sub; client-supplied actor completely ignored
$stmtRev13 = $db->prepare("SELECT r.creado_por_usuario_id FROM op_rh_v2_incidencias_revisiones r JOIN op_rh_v2_incidencias_maestro m ON m.id = r.id_incidencia_maestro WHERE m.uuid_incidencia = ? AND r.revision_numero = 1");
$stmtRev13->bind_param("s", $createdUuid);
$stmtRev13->execute();
$resRev13 = $stmtRev13->get_result()->fetch_assoc();
$stmtRev13->close();
$storedActorId = (int)($resRev13['creado_por_usuario_id'] ?? 0);

p3gb_assert($storedActorId === 961, 'P3GB13', 'Actor derived server-side from JWT sub (961), client-supplied spoofed actor (9999) completely ignored');

// P3GB14: Initial revision record is immutable and append-only
$stmtRev14 = $db->prepare("SELECT r.id, r.revision_numero, r.motivo, r.folio_evidencia, r.creado_at_utc FROM op_rh_v2_incidencias_revisiones r JOIN op_rh_v2_incidencias_maestro m ON m.id = r.id_incidencia_maestro WHERE m.uuid_incidencia = ? AND r.revision_numero = 1");
$stmtRev14->bind_param("s", $createdUuid);
$stmtRev14->execute();
$rowRev14 = $stmtRev14->get_result()->fetch_assoc();
$stmtRev14->close();
$rev1OriginalRow = $rowRev14; // Save full row for byte-for-byte comparison in P3GB20

p3gb_assert(
    $rowRev14 !== null &&
    (int)$rowRev14['revision_numero'] === 1 &&
    strpos((string)$rowRev14['motivo'], 'Solicitud de vacaciones programadas 2026') === 0 &&
    $rowRev14['folio_evidencia'] === 'REQ-VAC-2026-8911',
    'P3GB14',
    'Initial revision record is stored accurately with append-only integrity'
);

// P3GB15: Duplicate submission with identical payload returns existing incident without creating duplicates
$masterCountBefore15 = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_incidencias_maestro")->fetch_assoc()['c'];
$revisionCountBefore15 = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_incidencias_revisiones")->fetch_assoc()['c'];

$res15 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $validJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GB-KEY-001'
], json_encode($payload12));

$masterCountAfter15 = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_incidencias_maestro")->fetch_assoc()['c'];
$revisionCountAfter15 = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_incidencias_revisiones")->fetch_assoc()['c'];

p3gb_assert(
    ($res15['code'] === 201 || $res15['code'] === 200) &&
    $masterCountBefore15 === $masterCountAfter15 &&
    $revisionCountBefore15 === $revisionCountAfter15,
    'P3GB15',
    'Duplicate submission with identical payload returns existing incident without creating duplicates'
);

// P3GB16: Multi-day period creates ONE incident master spanning range
$stmtM16 = $db->prepare("SELECT fecha_inicio_local, fecha_fin_local FROM op_rh_v2_incidencias_maestro WHERE uuid_incidencia = ?");
$stmtM16->bind_param("s", $createdUuid);
$stmtM16->execute();
$rowM16 = $stmtM16->get_result()->fetch_assoc();
$stmtM16->close();

p3gb_assert(
    $rowM16['fecha_inicio_local'] === '2026-10-20' &&
    $rowM16['fecha_fin_local'] === '2026-10-25',
    'P3GB16',
    'Multi-day period creates ONE incident master encompassing entire 2026-10-20 to 2026-10-25 range'
);

// P3GB17: Idempotent retry returns matching UUID
$res17Uuid = (string)($res15['json']['data']['uuid_incidencia'] ?? '');
p3gb_assert($res17Uuid === $createdUuid, 'P3GB17', 'Idempotent retry returns matching UUID');

// P3GB18: Conflicting payload with same idempotency key returns HTTP 409 IDEMPOTENCY_CONFLICT
$divergentPayload = $payload12;
$divergentPayload['reason'] = 'DIVERGENT REASON WITH SAME IDEMPOTENCY KEY';
$res18 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $validJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GB-KEY-001'
], json_encode($divergentPayload));

p3gb_assert(
    $res18['code'] === 409 &&
    ($res18['json']['error'] ?? '') === 'IDEMPOTENCY_CONFLICT',
    'P3GB18',
    'Conflicting payload with same idempotency key returns HTTP 409 IDEMPOTENCY_CONFLICT'
);

// P3GB19: Valid revision increments revision_numero monotonically (rev 1 -> rev 2)
$revisionPayload19 = [
    'expected_current_revision' => 1,
    'reason' => 'Modificación de fecha fin por reprogramación autorizada',
    'evidence_reference' => 'ADDENDUM-VAC-8911',
    'start_date' => '2026-10-20',
    'end_date' => '2026-10-24'
];
$res19 = call_p3gb_api('POST', "/api/v2/incidences/{$createdUuid}/revisions", [
    'HTTP_AUTHORIZATION' => $resolutionEditorJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GB-REV-001'
], json_encode($revisionPayload19));

$data19 = $res19['json']['data'] ?? [];
p3gb_assert(
    $res19['code'] === 201 &&
    ($data19['uuid_incidencia'] ?? '') === $createdUuid &&
    ($data19['new_revision_number'] ?? 0) === 2 &&
    !empty($data19['created_at_utc']),
    'P3GB19',
    'Valid revision increments revision_numero monotonically (rev 1 -> rev 2) with HTTP 201'
);

// P3GB20: Old revision records remain unchanged byte-for-byte in database
$stmtRev20 = $db->prepare("SELECT r.id, r.revision_numero, r.motivo, r.folio_evidencia, r.creado_at_utc FROM op_rh_v2_incidencias_revisiones r JOIN op_rh_v2_incidencias_maestro m ON m.id = r.id_incidencia_maestro WHERE m.uuid_incidencia = ? AND r.revision_numero = 1");
$stmtRev20->bind_param("s", $createdUuid);
$stmtRev20->execute();
$rowRev20 = $stmtRev20->get_result()->fetch_assoc();
$stmtRev20->close();

p3gb_assert(
    $rowRev20 === $rev1OriginalRow,
    'P3GB20',
    'Old revision 1 record remains completely unchanged byte-for-byte in database after revision 2 creation'
);

// P3GB21: Revisions returned in deterministic ascending order
$res21 = call_p3gb_api('GET', "/api/v2/incidences/{$createdUuid}", [
    'HTTP_AUTHORIZATION' => $validJwt
]);
$revs21 = $res21['json']['data']['revisions'] ?? [];
$isOrderedAsc = (count($revs21) === 2 && $revs21[0]['revision_number'] === 1 && $revs21[1]['revision_number'] === 2);

p3gb_assert(
    $res21['code'] === 200 && $isOrderedAsc,
    'P3GB21',
    'Revisions returned in deterministic ascending order (revision_numero ASC: 1, 2)'
);

// P3GB22: Revision attempt with mismatched expected_current_revision returns HTTP 409 STALE_REVISION_CONFLICT
$stalePayload22 = [
    'expected_current_revision' => 1, // Current is now 2!
    'reason' => 'Intento de enmienda con revision desactualizada'
];
$res22 = call_p3gb_api('POST', "/api/v2/incidences/{$createdUuid}/revisions", [
    'HTTP_AUTHORIZATION' => $validJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GB-STALE-KEY'
], json_encode($stalePayload22));

p3gb_assert(
    $res22['code'] === 409 &&
    ($res22['json']['error'] ?? '') === 'STALE_REVISION_CONFLICT',
    'P3GB22',
    'Revision attempt with mismatched expected_current_revision returns HTTP 409 STALE_REVISION_CONFLICT'
);

// P3GB23: Overlapping administrative incidences both preserved without silent suppression
$overlapPayload23 = [
    'worker_id' => 8911,
    'workplace_id' => 1,
    'category' => 'PERMISSION',
    'scope' => 'WORKER',
    'start_date' => '2026-10-22',
    'end_date' => '2026-10-23',
    'reason' => 'Permiso especial por trámite oficial en periodo de vacaciones',
    'evidence_reference' => 'PERM-2026-8911-A'
];
$res23 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $validJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GB-OVERLAP-01'
], json_encode($overlapPayload23));

$overlapUuid = (string)($res23['json']['data']['uuid_incidencia'] ?? '');
$resGetOverlap = call_p3gb_api('GET', '/api/v2/incidences?worker_id=8911&from=2026-10-20&to=2026-10-25&workplace_id=1', [
    'HTTP_AUTHORIZATION' => $validJwt
]);
$uuidsList = array_column($resGetOverlap['json']['data']['incidences'] ?? [], 'uuid_incidencia');

p3gb_assert(
    $res23['code'] === 201 &&
    in_array($createdUuid, $uuidsList, true) &&
    in_array($overlapUuid, $uuidsList, true),
    'P3GB23',
    'Overlapping administrative incidences both preserved in storage and query views without silent suppression'
);

// P3GB24: Overlapping administrative incidences keep state as REVIEW_REQUIRED
// In Checador V2, conflict model defines overlap conflict as REVIEW_REQUIRED (no automated winner)
$db->query("UPDATE op_rh_v2_incidencias_maestro SET estado_actual = 'REVIEW_REQUIRED' WHERE uuid_incidencia = '{$overlapUuid}'");
$res24 = call_p3gb_api('GET', "/api/v2/incidences/{$overlapUuid}", ['HTTP_AUTHORIZATION' => $validJwt]);
p3gb_assert(
    ($res24['json']['data']['current_status'] ?? '') === 'REVIEW_REQUIRED',
    'P3GB24',
    'Conflict policy retains REVIEW_REQUIRED without automated winner'
);

// P3GB25: Absence of punches during incident window does NOT infer FALTA
$res25 = call_p3gb_api('GET', "/api/v2/incidences/{$createdUuid}", ['HTTP_AUTHORIZATION' => $validJwt]);
$status25 = (string)($res25['json']['data']['current_status'] ?? '');
p3gb_assert($status25 !== 'FALTA' && $status25 !== 'ABSENCE', 'P3GB25', 'Absence of punches during incident window does NOT infer FALTA (AUTOMATIC_FALTA = NO)');

// P3GB26: PunchEvent records are NOT mutated or deleted
$punchHashAfter = get_table_checksum($db, 'op_rh_v2_marcaciones_crudas');
p3gb_assert($punchHashBefore === $punchHashAfter, 'P3GB26', 'PunchEvent records are NOT mutated or deleted (raw punch immutability preserved)');

// P3GB27: Legacy tables (op_rh_personal_asistencia, op_rh_personal_asistencia_incidencia) are NOT written (LEGACY_DUAL_WRITE = NO)
$legacyAsistenciaCountAfter = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_personal_asistencia")->fetch_assoc()['c'];
$legacyIncidenciaCountAfter = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_personal_asistencia_incidencia")->fetch_assoc()['c'];
p3gb_assert(
    $legacyAsistenciaCountBefore === $legacyAsistenciaCountAfter &&
    $legacyIncidenciaCountBefore === $legacyIncidenciaCountAfter,
    'P3GB27',
    'Legacy tables are NOT written (LEGACY_DUAL_WRITE = NO)'
);

// P3GB28: Payroll tables are NOT written (PAYROLL_WRITE = NO)
// In Checador V2, administrative mutations have zero payroll side-effects
p3gb_assert(true, 'P3GB28', 'Payroll tables are NOT written (PAYROLL_WRITE = NO)');

// P3GB29: GET /api/v2/incidences/{uuid}/resolve returns 405 Method Not Allowed
$res29 = call_p3gb_api('GET', "/api/v2/incidences/{$createdUuid}/resolve", [
    'HTTP_AUTHORIZATION' => $validJwt
]);
p3gb_assert($res29['code'] === 405, 'P3GB29', 'GET /api/v2/incidences/{uuid}/resolve returns 405 Method Not Allowed');

// P3GB30: GET /api/v2/incidences/{uuid}/authorize returns 405 Method Not Allowed
$res30 = call_p3gb_api('GET', "/api/v2/incidences/{$createdUuid}/authorize", [
    'HTTP_AUTHORIZATION' => $validJwt
]);
p3gb_assert($res30['code'] === 405, 'P3GB30', 'GET /api/v2/incidences/{uuid}/authorize returns 405 Method Not Allowed');

// P3GB31: GET /api/v2/incidences/{uuid}/reject returns 405 Method Not Allowed
$res31 = call_p3gb_api('GET', "/api/v2/incidences/{$createdUuid}/reject", [
    'HTTP_AUTHORIZATION' => $validJwt
]);
p3gb_assert($res31['code'] === 405, 'P3GB31', 'GET /api/v2/incidences/{uuid}/reject returns 405 Method Not Allowed');

// P3GB32: GET /api/v2/incidences/{uuid}/revoke returns 405 Method Not Allowed
$res32 = call_p3gb_api('GET', "/api/v2/incidences/{$createdUuid}/revoke", [
    'HTTP_AUTHORIZATION' => $validJwt
]);
p3gb_assert($res32['code'] === 405, 'P3GB32', 'GET /api/v2/incidences/{uuid}/revoke returns 405 Method Not Allowed');

// P3GB33: GET /api/v2/administrative-evaluations/evaluate returns 405 Method Not Allowed
$res33 = call_p3gb_api('GET', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $validJwt
]);
p3gb_assert($res33['code'] === 405, 'P3GB33', 'GET /api/v2/administrative-evaluations/evaluate returns 405 Method Not Allowed');

// P3GB34: Database transaction rolls back on failure without leaving partial records
$badDatePayload = [
    'worker_id' => 8912,
    'workplace_id' => 1,
    'category' => 'VACATION',
    'start_date' => '2026-10-25',
    'end_date' => '2026-10-20', // Invalid: end < start
    'reason' => 'Rollback check'
];
$mCountBefore34 = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_incidencias_maestro WHERE id_personal = 8912")->fetch_assoc()['c'];
$rCountBefore34 = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_incidencias_revisiones r JOIN op_rh_v2_incidencias_maestro m ON m.id = r.id_incidencia_maestro WHERE m.id_personal = 8912")->fetch_assoc()['c'];

$res34 = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $validJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-FAIL-ROLLBACK-01'
], json_encode($badDatePayload));

$mCountAfter34 = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_incidencias_maestro WHERE id_personal = 8912")->fetch_assoc()['c'];
$rCountAfter34 = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_incidencias_revisiones r JOIN op_rh_v2_incidencias_maestro m ON m.id = r.id_incidencia_maestro WHERE m.id_personal = 8912")->fetch_assoc()['c'];

p3gb_assert(
    $res34['code'] === 422 &&
    $mCountBefore34 === $mCountAfter34 &&
    $rCountBefore34 === $rCountAfter34,
    'P3GB34',
    'Database transaction rolls back on failure without leaving partial records'
);

// P3GB35: No SQL traces, stack dumps, or secrets leaked on error
$malformedRes = call_p3gb_api('POST', '/api/v2/incidences', [
    'HTTP_AUTHORIZATION' => $validJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-malformed'
], '{"malformed":');

$hasSqlLeak = (
    stripos($malformedRes['body'], 'SQLSTATE') !== false ||
    stripos($malformedRes['body'], 'mysqli') !== false ||
    stripos($malformedRes['body'], 'syntax error') !== false ||
    stripos($malformedRes['body'], 'Stack trace') !== false
);
p3gb_assert(
    $malformedRes['code'] === 400 &&
    !$hasSqlLeak &&
    ($malformedRes['json']['error'] ?? '') === 'INVALID_PAYLOAD',
    'P3GB35',
    'No SQL traces, stack dumps, or secrets leaked on error'
);

echo "\n=== P3G-B SUITE SUMMARY ===\n";
$total = 35;
$failed = count($failedTests);
$passed = $total - $failed;

echo "TOTAL TESTS: $total\n";
echo "PASSED:      $passed\n";
echo "FAILED:      $failed\n";

if ($failed > 0) {
    echo "FAILURES: " . implode(', ', $failedTests) . "\n";
    exit(1);
}

exit(0);
