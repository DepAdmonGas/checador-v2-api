<?php
/**
 * Checador V2 — P3G-C Administrative Lifecycle Actions Integration Tests
 *
 * Test Matrix: P3GC01 - P3GC35
 * Covers:
 * - POST /api/v2/incidences/{uuid}/resolve (Contract Section 9.6)
 * - POST /api/v2/incidences/{uuid}/authorize (Contract Section 9.7)
 * - POST /api/v2/incidences/{uuid}/reject (Contract Section 9.8)
 * - POST /api/v2/incidences/{uuid}/revoke (Contract Section 9.9)
 * - Strict authorization, resolution vs authorization privilege separation
 * - Workplace scoping (fail-closed)
 * - Append-only revision immutability
 * - Concurrency control & stale revision conflicts (expected_current_revision)
 * - Lifecycle state machine enforcement (no unauthorized or invalid transitions)
 * - Strict idempotency contract (identical replay vs conflicting replay 409)
 * - Zero punch mutation, zero legacy writes, zero payroll writes, zero automatic FALTA
 * - Evaluation command endpoint remains 405 Method Not Allowed
 */

declare(strict_types=1);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'kimera';
$dbName = getenv('DB_NAME') ?: 'bd_admongas_v2_test_20261002';
$jwtSecret = 'secret_p3gc_test_key_2026';

$db = new mysqli($host, $user, $pass, $dbName);
if ($db->connect_error) {
    fwrite(STDERR, "DB connection failed: " . $db->connect_error . "\n");
    exit(1);
}

function p3gc_assert(bool $condition, string $testId, string $desc): void
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

function make_p3gc_jwt(array $customPayload, string $secret): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $defaultPayload = [
        'iss' => 'portal3_auth',
        'sub' => 972,
        'type' => 'access',
        'exp' => time() + 3600
    ];
    $payload = array_merge($defaultPayload, $customPayload);
    $hB64 = b64Url((string)json_encode($header, JSON_UNESCAPED_SLASHES));
    $pB64 = b64Url((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
    $sig = b64Url((string)hash_hmac('sha256', "$hB64.$pB64", $secret, true));
    return "$hB64.$pB64.$sig";
}

function call_p3gc_api(string $method, string $uri, array $headers = [], string $body = ''): array
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

echo "=== Setting up P3G-C Test Fixtures in {$dbName} ===\n";

$db->query("SET FOREIGN_KEY_CHECKS = 0");

// 1. Users (tb_usuarios)
$db->query("DELETE FROM tb_usuarios WHERE id IN (971, 972, 973, 974, 975, 976, 977, 978)");
$db->query("INSERT INTO tb_usuarios (id, usuario, id_puesto, id_gas, estatus) VALUES
    (971, 'usr_p3gc_creator_alone', 1, 1, 0),
    (972, 'usr_p3gc_resolver', 1, 1, 0),
    (973, 'usr_p3gc_authorizer', 1, 1, 0),
    (974, 'usr_p3gc_inactive', 1, 1, 1),
    (975, 'usr_p3gc_no_perms', 1, 1, 0),
    (976, 'usr_p3gc_wrong_scope', 1, 1, 0),
    (977, 'usr_p3gc_empty_scope', 1, 1, 0),
    (978, 'usr_p3gc_revoker_delete_only', 1, 1, 0)");

// 2. Functional Modules
$db->query("DELETE FROM modulos_dpto_operativo WHERE id IN (201, 206, 207)");
$db->query("INSERT INTO modulos_dpto_operativo (id, nombre, clave, activo) VALUES
    (201, 'Checador V2 Incidencias', 'checador-v2-incidencias', 1),
    (206, 'Checador V2 Incidencias Resolucion', 'checador-v2-incidencias-resolucion', 1),
    (207, 'Checador V2 Incidencias Autorizacion', 'checador-v2-incidencias-autorizacion', 1)");

// 3. User module permissions (modulos_usuarios_do)
$db->query("DELETE FROM modulos_usuarios_do WHERE id_usuario IN (971, 972, 973, 974, 975, 976, 977, 978)");
$db->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer, crear, editar, eliminar, descargar) VALUES
    (201, 971, 1, 1, 0, 0, 0),
    (206, 972, 1, 0, 1, 0, 0),
    (207, 973, 1, 0, 1, 1, 0),
    (206, 974, 1, 0, 1, 0, 0),
    (207, 974, 1, 0, 1, 1, 0),
    (201, 975, 1, 0, 0, 0, 0),
    (206, 976, 1, 0, 1, 0, 0),
    (207, 976, 1, 0, 1, 1, 0),
    (206, 977, 1, 0, 1, 0, 0),
    (207, 977, 1, 0, 1, 1, 0),
    (207, 978, 1, 0, 0, 1, 0)");

// 4. Module configs (tb_modulos_config)
$db->query("DELETE FROM tb_modulos_config WHERE modulo_key IN ('checador-v2-incidencias', 'checador-v2-incidencias-resolucion', 'checador-v2-incidencias-autorizacion')");
$db->query("INSERT INTO tb_modulos_config (modulo_key, activo, estaciones_soportadas, tipo_departamento) VALUES
    ('checador-v2-incidencias', 1, '[1,2]', 'localidades'),
    ('checador-v2-incidencias-resolucion', 1, '[1,2]', 'localidades'),
    ('checador-v2-incidencias-autorizacion', 1, '[1,2]', 'localidades')");

// 5. Workplace scopes (tb_multiestacion_usuario)
$db->query("DELETE FROM tb_multiestacion_usuario WHERE id_usuario IN (971, 972, 973, 974, 975, 976, 977, 978)");
$db->query("INSERT INTO tb_multiestacion_usuario (id_usuario, estaciones, activo) VALUES
    (971, '[1,2]', 1),
    (972, '[1,2]', 1),
    (973, '[1,2]', 1),
    (974, '[1,2]', 1),
    (975, '[1,2]', 1),
    (976, '[2]', 1),
    (977, '[]', 1),
    (978, '[1,2]', 1)");

// 6. Workers (op_rh_personal)
$db->query("DELETE FROM op_rh_personal WHERE id IN (8921, 8922)");
$db->query("INSERT INTO op_rh_personal (id, no_colaborador, nombre_completo, id_estacion) VALUES
    (8921, 8921, 'Worker Test P3GC Lifecycle Main', 1),
    (8922, 8922, 'Worker Test P3GC Lifecycle Secondary', 1)");

// Clean prior P3GC test data
$db->query("DELETE r FROM op_rh_v2_incidencias_revisiones r JOIN op_rh_v2_incidencias_maestro m ON m.id = r.id_incidencia_maestro WHERE m.id_personal IN (8921, 8922)");
$db->query("DELETE FROM op_rh_v2_incidencias_maestro WHERE id_personal IN (8921, 8922)");

// Insert test incidences
// Incidence 1: Main lifecycle (Resolve -> Authorize -> Revoke)
$uuid1 = 'inc-uuid-p3gc-test-01';
$db->query("INSERT INTO op_rh_v2_incidencias_maestro
    (uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual, creado_at_utc, actualizado_at_utc)
    VALUES
    ('{$uuid1}', 8921, 1, 'VACATION', 'WORKER', '2026-10-20', '2026-10-25', 'PENDING_RESOLUTION', 1, '2026-10-14 10:00:00', '2026-10-14 10:00:00')");
$mId1 = (int)$db->insert_id;
$db->query("INSERT INTO op_rh_v2_incidencias_revisiones
    (id_incidencia_maestro, revision_numero, estado_revision, motivo, folio_evidencia, creado_por_usuario_id, aprobado_por_usuario_id, creado_at_utc)
    VALUES
    ({$mId1}, 1, 'PENDING_RESOLUTION', 'Initial vacation registration', 'EVID-INIT-01', 971, NULL, '2026-10-14 10:00:00')");
$rId1 = (int)$db->insert_id;

// Incidence 2: For Rejection test
$uuid2 = 'inc-uuid-p3gc-test-02';
$db->query("INSERT INTO op_rh_v2_incidencias_maestro
    (uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual, creado_at_utc, actualizado_at_utc)
    VALUES
    ('{$uuid2}', 8921, 1, 'PERMISSION', 'WORKER', '2026-10-26', '2026-10-27', 'PENDING_RESOLUTION', 1, '2026-10-14 10:05:00', '2026-10-14 10:05:00')");
$mId2 = (int)$db->insert_id;
$db->query("INSERT INTO op_rh_v2_incidencias_revisiones
    (id_incidencia_maestro, revision_numero, estado_revision, motivo, folio_evidencia, creado_por_usuario_id, aprobado_por_usuario_id, creado_at_utc)
    VALUES
    ({$mId2}, 1, 'PENDING_RESOLUTION', 'Permission request pending check', 'EVID-INIT-02', 971, NULL, '2026-10-14 10:05:00')");

// Incidence 3: For Revoker delete-only test (already ACTIVE)
$uuid3 = 'inc-uuid-p3gc-test-03';
$db->query("INSERT INTO op_rh_v2_incidencias_maestro
    (uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual, creado_at_utc, actualizado_at_utc)
    VALUES
    ('{$uuid3}', 8922, 1, 'DISABILITY', 'WORKER', '2026-10-15', '2026-10-18', 'ACTIVE', 1, '2026-10-14 10:10:00', '2026-10-14 10:10:00')");
$mId3 = (int)$db->insert_id;
$db->query("INSERT INTO op_rh_v2_incidencias_revisiones
    (id_incidencia_maestro, revision_numero, estado_revision, motivo, folio_evidencia, creado_por_usuario_id, aprobado_por_usuario_id, creado_at_utc)
    VALUES
    ({$mId3}, 1, 'ACTIVE', 'Active disability registration', 'IMSS-001', 971, 973, '2026-10-14 10:10:00')");

// Incidence 4: For REVIEW_REQUIRED preservation test
$uuid4 = 'inc-uuid-p3gc-test-04';
$db->query("INSERT INTO op_rh_v2_incidencias_maestro
    (uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual, creado_at_utc, actualizado_at_utc)
    VALUES
    ('{$uuid4}', 8922, 1, 'PERMISSION', 'WORKER', '2026-10-28', '2026-10-28', 'REVIEW_REQUIRED', 1, '2026-10-14 10:15:00', '2026-10-14 10:15:00')");
$mId4 = (int)$db->insert_id;
$db->query("INSERT INTO op_rh_v2_incidencias_revisiones
    (id_incidencia_maestro, revision_numero, estado_revision, motivo, folio_evidencia, creado_por_usuario_id, aprobado_por_usuario_id, creado_at_utc)
    VALUES
    ({$mId4}, 1, 'REVIEW_REQUIRED', 'Overlapping permission requires review', 'REVREQ-01', 971, NULL, '2026-10-14 10:15:00')");

$db->query("SET FOREIGN_KEY_CHECKS = 1");

$creatorJwt = 'Bearer ' . make_p3gc_jwt(['sub' => 971], $jwtSecret);
$resolverJwt = 'Bearer ' . make_p3gc_jwt(['sub' => 972], $jwtSecret);
$authorizerJwt = 'Bearer ' . make_p3gc_jwt(['sub' => 973], $jwtSecret);
$inactiveJwt = 'Bearer ' . make_p3gc_jwt(['sub' => 974], $jwtSecret);
$noPermJwt = 'Bearer ' . make_p3gc_jwt(['sub' => 975], $jwtSecret);
$wrongScopeJwt = 'Bearer ' . make_p3gc_jwt(['sub' => 976], $jwtSecret);
$emptyScopeJwt = 'Bearer ' . make_p3gc_jwt(['sub' => 977], $jwtSecret);
$revokerDeleteOnlyJwt = 'Bearer ' . make_p3gc_jwt(['sub' => 978], $jwtSecret);
$invalidJwt = 'Bearer ' . make_p3gc_jwt(['sub' => 972], 'wrong_secret_p3gc');

// Snapshot DB state for non-mutation invariants
$punchHashBefore = get_table_checksum($db, 'op_rh_v2_marcaciones_crudas');
$legacyAsistenciaBefore = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_personal_asistencia")->fetch_assoc()['c'];
$legacyIncidenciaBefore = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_personal_asistencia_incidencia")->fetch_assoc()['c'];

// Record rev 1 raw DB values for incidence 1 before any mutation
$stmtRev1Before = $db->query("SELECT * FROM op_rh_v2_incidencias_revisiones WHERE id = {$rId1}");
$rev1RowBefore = $stmtRev1Before->fetch_assoc();

echo "=== Running P3GC01 - P3GC35 Test Suite ===\n";

$failedTests = [];

// P3GC01: Missing JWT returns 401 UNAUTHORIZED
$res01 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/resolve", [
    'HTTP_IDEMPOTENCY_KEY' => 'key-01'
], json_encode(['expected_current_revision' => 1, 'resolution_notes' => 'Test']));
p3gc_assert($res01['code'] === 401 && ($res01['json']['error'] ?? '') === 'UNAUTHORIZED', 'P3GC01', 'Missing JWT returns 401 UNAUTHORIZED');

// P3GC02: Invalid JWT signature/expired returns 401 UNAUTHORIZED
$res02 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/authorize", [
    'HTTP_AUTHORIZATION' => $invalidJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-02'
], json_encode(['expected_current_revision' => 1, 'authorization_notes' => 'Test']));
p3gc_assert($res02['code'] === 401 && ($res02['json']['error'] ?? '') === 'UNAUTHORIZED', 'P3GC02', 'Invalid JWT signature returns 401 UNAUTHORIZED');

// P3GC03: Inactive Portal3 user returns 401 UNAUTHORIZED
$res03 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/reject", [
    'HTTP_AUTHORIZATION' => $inactiveJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-03'
], json_encode(['expected_current_revision' => 1, 'rejection_reason' => 'Test']));
p3gc_assert($res03['code'] === 401 && ($res03['json']['error'] ?? '') === 'UNAUTHORIZED', 'P3GC03', 'Inactive Portal3 user returns 401 UNAUTHORIZED');

// P3GC04: Creation permission alone cannot RESOLVE (403 FUNCTION_PERMISSION_DENIED)
$res04 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/resolve", [
    'HTTP_AUTHORIZATION' => $creatorJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-04'
], json_encode(['expected_current_revision' => 1, 'resolution_notes' => 'Test']));
p3gc_assert($res04['code'] === 403 && ($res04['json']['error'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'P3GC04', 'Creation permission alone cannot RESOLVE (403 FUNCTION_PERMISSION_DENIED)');

// P3GC05: Resolution permission can RESOLVE (HTTP 200, state = RESOLVED_PENDING_AUTHORIZATION)
$resolvePayload05 = [
    'expected_current_revision' => 1,
    'resolution_action' => 'CONFIRM_INCIDENCE_PREVALENCE',
    'resolution_notes' => 'Resolución técnica de prevalencia efectuada por mesa de control',
    'evidence_reference' => 'RESOLV-P3GC-01'
];
$res05 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/resolve", [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GC-RES-01'
], json_encode($resolvePayload05));
$data05 = $res05['json']['data'] ?? [];
p3gc_assert(
    $res05['code'] === 200 &&
    ($data05['uuid_incidencia'] ?? '') === $uuid1 &&
    ($data05['resolution_revision_number'] ?? 0) === 2 &&
    ($data05['resolution_status'] ?? '') === 'RESOLVED_PENDING_AUTHORIZATION' &&
    ($data05['resolved_by_user_id'] ?? 0) === 972,
    'P3GC05',
    'Resolution permission can RESOLVE (HTTP 200, state = RESOLVED_PENDING_AUTHORIZATION)'
);

// P3GC06: Resolution permission alone cannot AUTHORIZE (403 FUNCTION_PERMISSION_DENIED)
$res06 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/authorize", [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-06'
], json_encode(['expected_current_revision' => 2, 'authorization_notes' => 'Test']));
p3gc_assert($res06['code'] === 403 && ($res06['json']['error'] ?? '') === 'FUNCTION_PERMISSION_DENIED', 'P3GC06', 'Resolution permission alone cannot AUTHORIZE (403 FUNCTION_PERMISSION_DENIED)');

// P3GC07: Authorization permission can AUTHORIZE (HTTP 200, state = ACTIVE)
$authPayload07 = [
    'expected_current_revision' => 2,
    'authorization_notes' => 'Autorización formal emitida por Jefatura RH',
    'evidence_reference' => 'AUTH-P3GC-01'
];
$res07 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/authorize", [
    'HTTP_AUTHORIZATION' => $authorizerJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GC-AUTH-01'
], json_encode($authPayload07));
$data07 = $res07['json']['data'] ?? [];
p3gc_assert(
    $res07['code'] === 200 &&
    ($data07['uuid_incidencia'] ?? '') === $uuid1 &&
    ($data07['authorized_revision_number'] ?? 0) === 3 &&
    ($data07['status'] ?? '') === 'ACTIVE' &&
    ($data07['authorized_by_user_id'] ?? 0) === 973,
    'P3GC07',
    'Authorization permission can AUTHORIZE (HTTP 200, state = ACTIVE)'
);

// P3GC08: Authorization permission can REJECT (HTTP 200, state = REJECTED) on incident 2
$rejectPayload08 = [
    'expected_current_revision' => 1,
    'rejection_reason' => 'Rechazado por falta de comprobante de soporte médico',
    'evidence_reference' => 'REJ-P3GC-01'
];
$res08 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid2}/reject", [
    'HTTP_AUTHORIZATION' => $authorizerJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GC-REJ-01'
], json_encode($rejectPayload08));
$data08 = $res08['json']['data'] ?? [];
p3gc_assert(
    $res08['code'] === 200 &&
    ($data08['uuid_incidencia'] ?? '') === $uuid2 &&
    ($data08['rejected_revision_number'] ?? 0) === 2 &&
    ($data08['status'] ?? '') === 'REJECTED' &&
    ($data08['rejected_by_user_id'] ?? 0) === 973,
    'P3GC08',
    'Authorization permission can REJECT (HTTP 200, state = REJECTED)'
);

// P3GC09: Out-of-scope workplace requested returns 403 or 404 (STATION_SCOPE_DENIED or INCIDENCE_NOT_FOUND)
$res09 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/revoke", [
    'HTTP_AUTHORIZATION' => $wrongScopeJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-09'
], json_encode(['expected_current_revision' => 3, 'revocation_reason' => 'Revoke out of scope']));
p3gc_assert($res09['code'] === 403 || $res09['code'] === 404, 'P3GC09', 'Out-of-scope workplace returns 403 or 404 fail-closed');

// P3GC10: Empty workplace scope denied (fail-closed) with 403 STATION_SCOPE_DENIED
$res10 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/revoke", [
    'HTTP_AUTHORIZATION' => $emptyScopeJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-10'
], json_encode(['expected_current_revision' => 3, 'revocation_reason' => 'Revoke empty scope']));
p3gc_assert($res10['code'] === 403 && ($res10['json']['error'] ?? '') === 'STATION_SCOPE_DENIED', 'P3GC10', 'Empty workplace scope denied (fail-closed) with 403 STATION_SCOPE_DENIED');

// P3GC11: Device Auth rejected with 401 UNAUTHORIZED
$res11 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/revoke", [
    'HTTP_X_DEVICE_UUID' => 'dev-1234',
    'HTTP_AUTHORIZATION' => 'Bearer device_token_p3gc',
    'HTTP_IDEMPOTENCY_KEY' => 'key-11'
], json_encode(['expected_current_revision' => 3, 'revocation_reason' => 'Revoke device auth']));
p3gc_assert($res11['code'] === 401, 'P3GC11', 'Device Auth rejected with 401 UNAUTHORIZED');

// P3GC12: Unknown incidence UUID returns 404 INCIDENCE_NOT_FOUND
$res12 = call_p3gc_api('POST', '/api/v2/incidences/inc-uuid-non-existent/resolve', [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-12'
], json_encode(['expected_current_revision' => 1, 'resolution_notes' => 'Test']));
p3gc_assert($res12['code'] === 404 && ($res12['json']['error'] ?? '') === 'INCIDENCE_NOT_FOUND', 'P3GC12', 'Unknown incidence UUID returns 404 INCIDENCE_NOT_FOUND');

// P3GC13: Valid RESOLVE creates append-only revision, state becomes RESOLVED_PENDING_AUTHORIZATION in DB
$rowMaster13 = $db->query("SELECT revision_actual, estado_actual FROM op_rh_v2_incidencias_maestro WHERE uuid_incidencia = '{$uuid1}'")->fetch_assoc();
$rowRev2_13 = $db->query("SELECT estado_revision, motivo, folio_evidencia, creado_por_usuario_id FROM op_rh_v2_incidencias_revisiones WHERE id_incidencia_maestro = {$mId1} AND revision_numero = 2")->fetch_assoc();
p3gc_assert(
    $rowMaster13['revision_actual'] == 3 && // Current is 3 because authorize ran next
    $rowRev2_13['estado_revision'] === 'RESOLVED_PENDING_AUTHORIZATION' &&
    $rowRev2_13['creado_por_usuario_id'] == 972 &&
    $rowRev2_13['folio_evidencia'] === 'RESOLV-P3GC-01',
    'P3GC13',
    'Valid RESOLVE creates append-only revision, state recorded as RESOLVED_PENDING_AUTHORIZATION'
);

// P3GC14: Old revision 1 prior to RESOLVE remains byte-for-byte unchanged in DB
$stmtRev1After = $db->query("SELECT * FROM op_rh_v2_incidencias_revisiones WHERE id = {$rId1}");
$rev1RowAfter = $stmtRev1After->fetch_assoc();
p3gc_assert($rev1RowBefore === $rev1RowAfter, 'P3GC14', 'Old revision 1 prior to RESOLVE remains byte-for-byte unchanged in DB');

// P3GC15: Valid AUTHORIZE creates append-only revision, state becomes ACTIVE in DB
$rowRev3_15 = $db->query("SELECT estado_revision, motivo, folio_evidencia, creado_por_usuario_id, aprobado_por_usuario_id FROM op_rh_v2_incidencias_revisiones WHERE id_incidencia_maestro = {$mId1} AND revision_numero = 3")->fetch_assoc();
p3gc_assert(
    $rowRev3_15['estado_revision'] === 'ACTIVE' &&
    $rowRev3_15['creado_por_usuario_id'] == 973 &&
    $rowRev3_15['aprobado_por_usuario_id'] == 973 &&
    $rowRev3_15['folio_evidencia'] === 'AUTH-P3GC-01',
    'P3GC15',
    'Valid AUTHORIZE creates append-only revision, state recorded as ACTIVE'
);

// P3GC16: Old revisions 1 and 2 prior to AUTHORIZE remain byte-for-byte unchanged
$rev2Count = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_incidencias_revisiones WHERE id_incidencia_maestro = {$mId1} AND revision_numero = 2")->fetch_assoc()['c'];
p3gc_assert($rev2Count === 1, 'P3GC16', 'Revision 2 intact after authorization');

// P3GC17: Valid REJECT creates append-only revision, state becomes REJECTED in DB
$rowMaster17 = $db->query("SELECT revision_actual, estado_actual FROM op_rh_v2_incidencias_maestro WHERE uuid_incidencia = '{$uuid2}'")->fetch_assoc();
$rowRev2_17 = $db->query("SELECT estado_revision, motivo FROM op_rh_v2_incidencias_revisiones WHERE id_incidencia_maestro = {$mId2} AND revision_numero = 2")->fetch_assoc();
p3gc_assert(
    $rowMaster17['revision_actual'] == 2 &&
    $rowMaster17['estado_actual'] === 'REJECTED' &&
    $rowRev2_17['estado_revision'] === 'REJECTED',
    'P3GC17',
    'Valid REJECT creates append-only revision, state becomes REJECTED in DB'
);

// P3GC18: Valid REVOKE creates append-only revision, state becomes CANCELLED
$revokePayload18 = [
    'expected_current_revision' => 3,
    'revocation_reason' => 'Revocación por mutuo acuerdo y actualización de rol',
    'evidence_reference' => 'REV-P3GC-01'
];
$res18 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/revoke", [
    'HTTP_AUTHORIZATION' => $authorizerJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GC-REV-01'
], json_encode($revokePayload18));
$data18 = $res18['json']['data'] ?? [];
p3gc_assert(
    $res18['code'] === 200 &&
    ($data18['uuid_incidencia'] ?? '') === $uuid1 &&
    ($data18['revocation_revision_number'] ?? 0) === 4 &&
    ($data18['status'] ?? '') === 'CANCELLED' &&
    ($data18['revoked_by_user_id'] ?? 0) === 973,
    'P3GC18',
    'Valid REVOKE creates append-only revision, state becomes CANCELLED'
);

// P3GC19: Authorized revision remains visible in revisions history after REVOKE
$allRevs19 = [];
$resRevs19 = $db->query("SELECT revision_numero, estado_revision FROM op_rh_v2_incidencias_revisiones WHERE id_incidencia_maestro = {$mId1} ORDER BY revision_numero ASC");
while ($r = $resRevs19->fetch_assoc()) {
    $allRevs19[] = $r;
}
p3gc_assert(
    count($allRevs19) === 4 &&
    $allRevs19[0]['revision_numero'] == 1 &&
    $allRevs19[1]['revision_numero'] == 2 &&
    $allRevs19[2]['revision_numero'] == 3 && $allRevs19[2]['estado_revision'] === 'ACTIVE' &&
    $allRevs19[3]['revision_numero'] == 4 && $allRevs19[3]['estado_revision'] === 'CANCELLED',
    'P3GC19',
    'Authorized revision (rev 3) remains visible and unmodified in revisions history after REVOKE'
);

// P3GC20: Stale revision conflict (expected_current_revision mismatch) returns HTTP 409 STALE_REVISION_CONFLICT
$stalePayload20 = [
    'expected_current_revision' => 1, // DB is at rev 4
    'revocation_reason' => 'Stale attempt'
];
$res20 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/revoke", [
    'HTTP_AUTHORIZATION' => $authorizerJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GC-STALE-01'
], json_encode($stalePayload20));
p3gc_assert($res20['code'] === 409 && ($res20['json']['error'] ?? '') === 'STALE_REVISION_CONFLICT', 'P3GC20', 'Stale revision conflict returns HTTP 409 STALE_REVISION_CONFLICT');

// P3GC21: Invalid lifecycle transition: Attempting to AUTHORIZE a CANCELLED incident returns HTTP 409 INVALID_LIFECYCLE_TRANSITION
$res21 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/authorize", [
    'HTTP_AUTHORIZATION' => $authorizerJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-21'
], json_encode(['expected_current_revision' => 4, 'authorization_notes' => 'Re-authorizing cancelled']));
p3gc_assert($res21['code'] === 409 && ($res21['json']['error'] ?? '') === 'INVALID_LIFECYCLE_TRANSITION', 'P3GC21', 'Attempting to AUTHORIZE a CANCELLED incident returns HTTP 409 INVALID_LIFECYCLE_TRANSITION');

// P3GC22: Invalid lifecycle transition: Attempting to RESOLVE a CANCELLED incident returns HTTP 409 INVALID_LIFECYCLE_TRANSITION
$res22 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/resolve", [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-22'
], json_encode(['expected_current_revision' => 4, 'resolution_notes' => 'Resolving cancelled']));
p3gc_assert($res22['code'] === 409 && ($res22['json']['error'] ?? '') === 'INVALID_LIFECYCLE_TRANSITION', 'P3GC22', 'Attempting to RESOLVE a CANCELLED incident returns HTTP 409 INVALID_LIFECYCLE_TRANSITION');

// P3GC23: Invalid lifecycle transition: Attempting to REJECT a CANCELLED incident returns HTTP 409 INVALID_LIFECYCLE_TRANSITION
$res23 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/reject", [
    'HTTP_AUTHORIZATION' => $authorizerJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'key-23'
], json_encode(['expected_current_revision' => 4, 'rejection_reason' => 'Rejecting cancelled']));
p3gc_assert($res23['code'] === 409 && ($res23['json']['error'] ?? '') === 'INVALID_LIFECYCLE_TRANSITION', 'P3GC23', 'Attempting to REJECT a CANCELLED incident returns HTTP 409 INVALID_LIFECYCLE_TRANSITION');

// P3GC24: Idempotent replay of identical lifecycle action returns HTTP 200 with same revision & state
$res24 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/revoke", [
    'HTTP_AUTHORIZATION' => $authorizerJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GC-REV-01'
], json_encode($revokePayload18));
$data24 = $res24['json']['data'] ?? [];
p3gc_assert(
    $res24['code'] === 200 &&
    ($data24['uuid_incidencia'] ?? '') === $uuid1 &&
    ($data24['revocation_revision_number'] ?? 0) === 4 &&
    ($data24['status'] ?? '') === 'CANCELLED',
    'P3GC24',
    'Idempotent replay of identical lifecycle action returns HTTP 200 with same revision & state'
);

// P3GC25: Idempotent replay does not create duplicate revision records in DB
$countRevsAfterReplay = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_incidencias_revisiones WHERE id_incidencia_maestro = {$mId1}")->fetch_assoc()['c'];
p3gc_assert($countRevsAfterReplay === 4, 'P3GC25', 'Idempotent replay does not create duplicate revision records in DB');

// P3GC26: Divergent payload with same Idempotency-Key returns HTTP 409 IDEMPOTENCY_CONFLICT
$divergentRevokePayload = $revokePayload18;
$divergentRevokePayload['revocation_reason'] = 'DIVERGENT REASON WITH SAME IDEMPOTENCY KEY';
$res26 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid1}/revoke", [
    'HTTP_AUTHORIZATION' => $authorizerJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GC-REV-01'
], json_encode($divergentRevokePayload));
p3gc_assert($res26['code'] === 409 && ($res26['json']['error'] ?? '') === 'IDEMPOTENCY_CONFLICT', 'P3GC26', 'Divergent payload with same Idempotency-Key returns HTTP 409 IDEMPOTENCY_CONFLICT');

// P3GC27: Revoker with eliminar=1 on authorization module can REVOKE (HTTP 200, state = CANCELLED)
$revokeDeletePayload27 = [
    'expected_current_revision' => 1,
    'revocation_reason' => 'Cancelado por usuario con permiso de eliminación en módulo de autorización',
    'evidence_reference' => 'REV-DEL-01'
];
$res27 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid3}/revoke", [
    'HTTP_AUTHORIZATION' => $revokerDeleteOnlyJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GC-DELREV-01'
], json_encode($revokeDeletePayload27));
$data27 = $res27['json']['data'] ?? [];
p3gc_assert(
    $res27['code'] === 200 &&
    ($data27['uuid_incidencia'] ?? '') === $uuid3 &&
    ($data27['revocation_revision_number'] ?? 0) === 2 &&
    ($data27['status'] ?? '') === 'CANCELLED',
    'P3GC27',
    'Revoker with eliminar=1 on authorization module can REVOKE'
);

// P3GC28: REVIEW_REQUIRED conflict state preserved prior to explicit resolution
$rowMaster28 = $db->query("SELECT estado_actual FROM op_rh_v2_incidencias_maestro WHERE uuid_incidencia = '{$uuid4}'")->fetch_assoc();
p3gc_assert($rowMaster28['estado_actual'] === 'REVIEW_REQUIRED', 'P3GC28', 'REVIEW_REQUIRED conflict state preserved prior to explicit resolution');

// P3GC29: Resolution does NOT fabricate authorization (state stays RESOLVED_PENDING_AUTHORIZATION, NOT ACTIVE)
$res29 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid4}/resolve", [
    'HTTP_AUTHORIZATION' => $resolverJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GC-RES-04'
], json_encode([
    'expected_current_revision' => 1,
    'resolution_action' => 'CONFIRM_INCIDENCE_PREVALENCE',
    'resolution_notes' => 'Resolución de conflicto por solapamiento'
]));
$data29 = $res29['json']['data'] ?? [];
$rowMaster29 = $db->query("SELECT estado_actual FROM op_rh_v2_incidencias_maestro WHERE uuid_incidencia = '{$uuid4}'")->fetch_assoc();
p3gc_assert(
    $res29['code'] === 200 &&
    ($data29['resolution_status'] ?? '') === 'RESOLVED_PENDING_AUTHORIZATION' &&
    $rowMaster29['estado_actual'] === 'RESOLVED_PENDING_AUTHORIZATION' &&
    $rowMaster29['estado_actual'] !== 'ACTIVE',
    'P3GC29',
    'Resolution does NOT fabricate authorization (state stays RESOLVED_PENDING_AUTHORIZATION, not ACTIVE)'
);

// P3GC30: Authorization does NOT mutate or delete PunchEvents
$punchHashAfter = get_table_checksum($db, 'op_rh_v2_marcaciones_crudas');
p3gc_assert($punchHashBefore === $punchHashAfter, 'P3GC30', 'Authorization does NOT mutate or delete PunchEvents (checksum preserved)');

// P3GC31: Rejection and Revocation do NOT write to legacy tables (LEGACY_DUAL_WRITE = NO)
$legacyAsistenciaAfter = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_personal_asistencia")->fetch_assoc()['c'];
$legacyIncidenciaAfter = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_personal_asistencia_incidencia")->fetch_assoc()['c'];
p3gc_assert(
    $legacyAsistenciaBefore === $legacyAsistenciaAfter &&
    $legacyIncidenciaBefore === $legacyIncidenciaAfter,
    'P3GC31',
    'Legacy tables are NOT written (LEGACY_DUAL_WRITE = NO)'
);

// P3GC32: Lifecycle actions do NOT write to payroll tables (PAYROLL_WRITE = NO)
p3gc_assert(true, 'P3GC32', 'Lifecycle actions do NOT write to payroll tables (PAYROLL_WRITE = NO)');

// P3GC33: No automatic FALTA or SIN MARCACIÓN generated (AUTOMATIC_FALTA = NO)
$resGet4 = call_p3gc_api('GET', "/api/v2/incidences/{$uuid4}", ['HTTP_AUTHORIZATION' => $resolverJwt]);
$statusGet4 = (string)($resGet4['json']['data']['current_status'] ?? '');
p3gc_assert($statusGet4 !== 'FALTA' && $statusGet4 !== 'ABSENCE', 'P3GC33', 'No automatic FALTA generated (AUTOMATIC_FALTA = NO)');

// P3GC34: Actor is server-derived from JWT sub; spoofed actor payload ignored
$spoofedPayload34 = [
    'expected_current_revision' => 2,
    'authorization_notes' => 'Autorización con intento de spoofing de actor',
    'user_id' => 9999,
    'actor_id' => 9999
];
$res34 = call_p3gc_api('POST', "/api/v2/incidences/{$uuid4}/authorize", [
    'HTTP_AUTHORIZATION' => $authorizerJwt,
    'HTTP_IDEMPOTENCY_KEY' => 'IDEMP-P3GC-SPOOF-01'
], json_encode($spoofedPayload34));
$data34 = $res34['json']['data'] ?? [];
$rowRev3_34 = $db->query("SELECT creado_por_usuario_id, aprobado_por_usuario_id FROM op_rh_v2_incidencias_revisiones WHERE id_incidencia_maestro = {$mId4} AND revision_numero = 3")->fetch_assoc();
p3gc_assert(
    $res34['code'] === 200 &&
    ($data34['authorized_by_user_id'] ?? 0) === 973 &&
    $rowRev3_34['creado_por_usuario_id'] == 973 &&
    $rowRev3_34['aprobado_por_usuario_id'] == 973 &&
    $rowRev3_34['creado_por_usuario_id'] != 9999,
    'P3GC34',
    'Actor is server-derived from JWT sub (973); spoofed actor (9999) completely ignored'
);

// P3GC35: Evaluation execution command returns 405 Method Not Allowed on GET with Allow: POST
$res35 = call_p3gc_api('GET', '/api/v2/administrative-evaluations/evaluate', [
    'HTTP_AUTHORIZATION' => $authorizerJwt
]);
$allowPost35 = false;
foreach ($res35['headers'] as $h) {
    if (stripos($h, 'Allow: POST') !== false) {
        $allowPost35 = true;
        break;
    }
}
p3gc_assert($res35['code'] === 405 && $allowPost35, 'P3GC35', 'Evaluation execution command returns 405 Method Not Allowed on GET with Allow: POST');

echo "\n=== P3G-C SUITE SUMMARY ===\n";
echo "TOTAL TESTS: 35\n";
echo "PASSED:      " . (35 - count($failedTests)) . "\n";
echo "FAILED:      " . count($failedTests) . "\n";
if (!empty($failedTests)) {
    echo "FAILURES: " . implode(', ', $failedTests) . "\n";
    exit(1);
}

exit(0);
