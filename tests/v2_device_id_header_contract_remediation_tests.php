<?php
/**
 * Focused Test Suite: CHECADOR_V2_API_DEVICE_ID_HEADER_CONTRACT_REMEDIATION
 * Validates canonical numeric X-Device-Id authentication, UUID backwards compatibility,
 * cross-device credential isolation, lifecycle checks, and payload device_uuid mismatch
 * across:
 *   - GET  /api/v2/devices/context
 *   - POST /api/v2/devices/generations
 *   - POST /api/v2/punch-events
 *   - POST /api/v2/devices/telemetry
 */

declare(strict_types=1);

putenv("DB_NAME=bd_admongas_v2_test_20261002");
putenv("DB_HOST=127.0.0.1");
putenv("DB_USER=root");
putenv("DB_PASS=kimera");

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/DispositivoV2.php';

$bd = new BD();
$conn = $bd->conectarBD();

// Ensure required V2 migrations are applied in the disposable test DB
$migrationsToEnsure = [
    __DIR__ . '/../migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql',
    __DIR__ . '/../migraciones/v2/2026_10_05_000002_create_v2_level2_server_tables.sql',
];
foreach ($migrationsToEnsure as $migFile) {
    $sql = file_get_contents($migFile);
    if ($conn->multi_query($sql)) {
        do {
            if ($res = $conn->store_result()) {
                $res->free();
            }
        } while ($conn->more_results() && $conn->next_result());
    }
}

// Ensure integrity columns exist on op_rh_v2_marcaciones_crudas (idempotent check)
$colCheck = $conn->query("SHOW COLUMNS FROM op_rh_v2_marcaciones_crudas LIKE 'integrity_version'");
if ($colCheck && $colCheck->num_rows === 0) {
    $sqlSeal = file_get_contents(__DIR__ . '/../migraciones/v2/2026_10_02_000002_add_integrity_seal_to_raw_punches.sql');
    if ($conn->multi_query($sqlSeal)) {
        do {
            if ($res = $conn->store_result()) {
                $res->free();
            }
        } while ($conn->more_results() && $conn->next_result());
    }
}

// Test fixture IDs (isolated range 9601..9605)
$deviceIds = [9601, 9602, 9603, 9604, 9605];
$idList = implode(',', $deviceIds);

function cleanup_fixtures(mysqli $conn, string $idList): void {
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    $conn->query("DELETE FROM op_rh_v2_dispositivo_telemetria WHERE id_dispositivo IN ({$idList})");
    $conn->query("DELETE FROM op_rh_v2_marcaciones_nivel2_metadata WHERE id_dispositivo IN ({$idList})");
    $conn->query("DELETE FROM op_rh_v2_dispositivo_generaciones WHERE id_dispositivo IN ({$idList})");
    // Temporarily drop immutability trigger on op_rh_v2_marcaciones_crudas only if present for test cleanup
    $conn->query("DROP TRIGGER IF EXISTS tg_op_rh_v2_marcaciones_crudas_no_delete");
    $conn->query("DELETE FROM op_rh_v2_marcaciones_crudas WHERE id_dispositivo IN ({$idList})");
    $conn->query("DELETE FROM op_rh_v2_dispositivo_credenciales WHERE id_dispositivo IN ({$idList})");
    $conn->query("DELETE FROM op_rh_v2_dispositivos WHERE id IN ({$idList})");
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
}

cleanup_fixtures($conn, $idList);

$secretA = 'test-synth-secret-device-9601';
$secretB = 'test-synth-secret-device-9602';
$hashA = password_hash($secretA, PASSWORD_BCRYPT);
$hashB = password_hash($secretB, PASSWORD_BCRYPT);

// Device 9601: Active device with active valid credential
$conn->query("INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado, creado_at)
              VALUES (9601, 'test-pilot-uuid-9601-abcd', 1, 'Lector Piloto Test 9601', 'America/Mexico_City', 1, UTC_TIMESTAMP())");
$conn->query("INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, valido_desde, valido_hasta, estado, creado_at, revocado_at)
              VALUES (9601, 'cred-key-9601-active', '{$hashA}', '2025-01-01 00:00:00', '2099-12-31 23:59:59', 'ACTIVE', UTC_TIMESTAMP(), NULL)");

// Device 9602: Second active device with its own active credential (for cross-device isolation test)
$conn->query("INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado, creado_at)
              VALUES (9602, 'test-pilot-uuid-9602-efgh', 2, 'Lector Secundario Test 9602', 'America/Mexico_City', 1, UTC_TIMESTAMP())");
$conn->query("INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, valido_desde, valido_hasta, estado, creado_at, revocado_at)
              VALUES (9602, 'cred-key-9602-active', '{$hashB}', '2025-01-01 00:00:00', '2099-12-31 23:59:59', 'ACTIVE', UTC_TIMESTAMP(), NULL)");

// Device 9603: Active device with EXPIRED credential and NOT_YET_VALID credential
$conn->query("INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado, creado_at)
              VALUES (9603, 'test-pilot-uuid-9603-exp', 1, 'Lector Expirado Test 9603', 'America/Mexico_City', 1, UTC_TIMESTAMP())");
$conn->query("INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, valido_desde, valido_hasta, estado, creado_at, revocado_at)
              VALUES (9603, 'cred-key-9603-expired', '{$hashA}', '2020-01-01 00:00:00', '2021-01-01 00:00:00', 'ACTIVE', UTC_TIMESTAMP(), NULL)");
$conn->query("INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, valido_desde, valido_hasta, estado, creado_at, revocado_at)
              VALUES (9603, 'cred-key-9603-future', '{$hashA}', '2099-01-01 00:00:00', '2099-12-31 23:59:59', 'INACTIVE', UTC_TIMESTAMP(), NULL)");

// Device 9604: Active device with REVOKED credentials (both estado='REVOKED' and revocado_at timestamp set)
$conn->query("INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado, creado_at)
              VALUES (9604, 'test-pilot-uuid-9604-rev', 1, 'Lector Revocado Test 9604', 'America/Mexico_City', 1, UTC_TIMESTAMP())");
$conn->query("INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, valido_desde, valido_hasta, estado, creado_at, revocado_at)
              VALUES (9604, 'cred-key-9604-revoked-state', '{$hashA}', '2025-01-01 00:00:00', '2099-12-31 23:59:59', 'REVOKED', UTC_TIMESTAMP(), '2026-01-01 00:00:00')");
$conn->query("INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, valido_desde, valido_hasta, estado, creado_at, revocado_at)
              VALUES (9604, 'cred-key-9604-revoked-ts', '{$hashA}', '2025-01-01 00:00:00', '2099-12-31 23:59:59', 'ACTIVE', UTC_TIMESTAMP(), '2026-01-01 00:00:00')");

// Device 9605: INACTIVE device (estado = 0) with otherwise active credential
$conn->query("INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado, creado_at)
              VALUES (9605, 'test-pilot-uuid-9605-disabled', 1, 'Lector Inactivo Test 9605', 'America/Mexico_City', 0, UTC_TIMESTAMP())");
$conn->query("INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, valido_desde, valido_hasta, estado, creado_at, revocado_at)
              VALUES (9605, 'cred-key-9605-active', '{$hashA}', '2025-01-01 00:00:00', '2099-12-31 23:59:59', 'ACTIVE', UTC_TIMESTAMP(), NULL)");

$total = 0;
$passed = 0;
$failed = 0;

function assert_remediation(string $id, string $desc, bool $cond, string $detail = ''): void {
    global $total, $passed, $failed;
    $total++;
    if ($cond) {
        $passed++;
        echo "[{$id}] PASS: {$desc}\n";
    } else {
        $failed++;
        echo "[{$id}] FAIL: {$desc}" . ($detail !== '' ? " — {$detail}" : "") . "\n";
    }
}

function call_v2_endpoint(string $method, string $uri, array $headers = [], ?array $jsonBody = null): array {
    $script = realpath(__DIR__ . '/../index.php');
    $rawBody = $jsonBody !== null ? json_encode($jsonBody) : '';

    $env = [
        'REDIRECT_STATUS' => '200',
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $uri,
        'SCRIPT_FILENAME' => $script,
        'SCRIPT_NAME' => '/index.php',
        'DB_HOST' => '127.0.0.1',
        'DB_USER' => 'root',
        'DB_PASS' => 'kimera',
        'DB_NAME' => 'bd_admongas_v2_test_20261002',
        'CHECADOR_DEPLOYMENT_PROFILE' => 'preproduction',
        'RAW_PUNCH_INTEGRITY_MANDATORY' => 'false',
    ];

    if ($jsonBody !== null) {
        $env['CONTENT_TYPE'] = 'application/json; charset=utf-8';
        $env['CONTENT_LENGTH'] = (string)strlen($rawBody);
    }

    foreach ($headers as $k => $v) {
        $env[$k] = (string)$v;
    }

    $envPrefix = '';
    foreach ($env as $k => $v) {
        $envPrefix .= $k . '=' . escapeshellarg($v) . ' ';
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open($envPrefix . 'php-cgi', $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['code' => 500, 'raw' => '', 'json' => null];
    }

    if ($rawBody !== '') {
        fwrite($pipes[0], $rawBody);
    }
    fclose($pipes[0]);

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $sep = strpos($output, "\r\n\r\n");
    $delim = 4;
    if ($sep === false) {
        $sep = strpos($output, "\n\n");
        $delim = 2;
    }

    $headerBlock = $sep !== false ? substr($output, 0, $sep) : '';
    $bodyBlock = $sep !== false ? substr($output, $sep + $delim) : $output;

    $statusCode = 200;
    if (preg_match('/Status:\s*(\d{3})/i', $headerBlock, $m)) {
        $statusCode = (int)$m[1];
    }

    return [
        'code' => $statusCode,
        'raw' => $bodyBlock,
        'json' => json_decode($bodyBlock, true),
    ];
}

echo "=== CHECADOR V2: PRUEBAS DE REMEDIACIÓN DE X-Device-Id NUMÉRICO Y UUID ===\n\n";

$dispService = new DispositivoV2();

// 1. ID numérico válido con credencial correcta (Servicio + 4 endpoints)
$authNum = $dispService->autenticarCredencial($conn, '9601', 'cred-key-9601-active', $secretA);
assert_remediation(
    'T01-A',
    'DispositivoV2::autenticarCredencial acepta X-Device-Id numérico canónico ("9601")',
    $authNum['auth'] === true && $authNum['id_dispositivo'] === 9601 && $authNum['uuid_dispositivo'] === 'test-pilot-uuid-9601-abcd',
    json_encode($authNum)
);

$ctxNum = call_v2_endpoint('GET', '/api/v2/devices/context', [
    'HTTP_X_DEVICE_ID' => '9601',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
]);
assert_remediation(
    'T01-B',
    'GET /api/v2/devices/context con X-Device-Id numérico ("9601") retorna 200 OK y contexto completo',
    $ctxNum['code'] === 200
        && ($ctxNum['json']['id_dispositivo'] ?? null) === 9601
        && ($ctxNum['json']['device_uuid'] ?? null) === 'test-pilot-uuid-9601-abcd',
    $ctxNum['raw']
);

$genNum = call_v2_endpoint('POST', '/api/v2/devices/generations', [
    'HTTP_X_DEVICE_ID' => '9601',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
], [
    'device_uuid' => 'test-pilot-uuid-9601-abcd',
    'device_generation' => 'gen-9601-numeric-v1',
]);
assert_remediation(
    'T01-C',
    'POST /api/v2/devices/generations con X-Device-Id numérico ("9601") registra generación (201 REGISTERED)',
    $genNum['code'] === 201 && ($genNum['json']['status'] ?? '') === 'REGISTERED',
    $genNum['raw']
);

$punchNum = call_v2_endpoint('POST', '/api/v2/punch-events', [
    'HTTP_X_DEVICE_ID' => '9601',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
], [
    'event_uuid' => 'ev-rem-num-9601-0001',
    'external_worker_reference' => 'EMP-9601',
    'source_timestamp' => '2026-10-19 08:00:00',
    'source_timezone' => 'America/Mexico_City',
    'capture_method' => 'BIOMETRIC',
    'device_generation' => 'gen-9601-numeric-v1',
    'device_sequence' => 1,
]);
assert_remediation(
    'T01-D',
    'POST /api/v2/punch-events con X-Device-Id numérico ("9601") ingiere evento Nivel 2 (201 ACCEPTED)',
    $punchNum['code'] === 201 && ($punchNum['json']['status'] ?? '') === 'ACCEPTED',
    $punchNum['raw']
);

$telNum = call_v2_endpoint('POST', '/api/v2/devices/telemetry', [
    'HTTP_X_DEVICE_ID' => '9601',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
], [
    'device_generation' => 'gen-9601-numeric-v1',
    'telemetry_sequence' => 1,
    'observation_timestamp' => '2026-10-19T14:00:00Z',
    'battery_level' => 99,
    'network_status' => 'CONNECTED',
    'clock_status' => 'SYNCED',
]);
assert_remediation(
    'T01-E',
    'POST /api/v2/devices/telemetry con X-Device-Id numérico ("9601") registra telemetría (201 TELEMETRY_RECORDED)',
    $telNum['code'] === 201 && ($telNum['json']['status'] ?? '') === 'TELEMETRY_RECORDED',
    $telNum['raw']
);

// 2. UUID válido con credencial correcta (Compatibilidad retroactiva preservada)
$ctxUuid = call_v2_endpoint('GET', '/api/v2/devices/context', [
    'HTTP_X_DEVICE_ID' => 'test-pilot-uuid-9601-abcd',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
]);
assert_remediation(
    'T02-A',
    'GET /api/v2/devices/context con X-Device-Id UUID ("test-pilot-uuid-9601-abcd") retorna 200 OK e id_dispositivo=9601',
    $ctxUuid['code'] === 200
        && ($ctxUuid['json']['id_dispositivo'] ?? null) === 9601
        && ($ctxUuid['json']['device_uuid'] ?? null) === 'test-pilot-uuid-9601-abcd',
    $ctxUuid['raw']
);

$genUuid = call_v2_endpoint('POST', '/api/v2/devices/generations', [
    'HTTP_X_DEVICE_ID' => 'test-pilot-uuid-9601-abcd',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
], [
    'device_uuid' => 'test-pilot-uuid-9601-abcd',
    'device_generation' => 'gen-9601-numeric-v1',
]);
assert_remediation(
    'T02-B',
    'POST /api/v2/devices/generations con X-Device-Id UUID reconoce idempotentemente la generación (200 ALREADY_REGISTERED)',
    $genUuid['code'] === 200 && ($genUuid['json']['status'] ?? '') === 'ALREADY_REGISTERED',
    $genUuid['raw']
);

$punchUuid = call_v2_endpoint('POST', '/api/v2/punch-events', [
    'HTTP_X_DEVICE_ID' => 'test-pilot-uuid-9601-abcd',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
], [
    'event_uuid' => 'ev-rem-num-9601-0001',
    'external_worker_reference' => 'EMP-9601',
    'source_timestamp' => '2026-10-19 08:00:00',
    'source_timezone' => 'America/Mexico_City',
    'capture_method' => 'BIOMETRIC',
    'device_generation' => 'gen-9601-numeric-v1',
    'device_sequence' => 1,
]);
assert_remediation(
    'T02-C',
    'POST /api/v2/punch-events con X-Device-Id UUID comparte idempotencia exacta con el ID numérico (200 ALREADY_RECEIVED)',
    $punchUuid['code'] === 200 && ($punchUuid['json']['status'] ?? '') === 'ALREADY_RECEIVED',
    $punchUuid['raw']
);

$telUuid = call_v2_endpoint('POST', '/api/v2/devices/telemetry', [
    'HTTP_X_DEVICE_ID' => 'test-pilot-uuid-9601-abcd',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
], [
    'device_generation' => 'gen-9601-numeric-v1',
    'telemetry_sequence' => 2,
    'observation_timestamp' => '2026-10-19T14:05:00Z',
]);
assert_remediation(
    'T02-D',
    'POST /api/v2/devices/telemetry con X-Device-Id UUID registra telemetría (201 TELEMETRY_RECORDED)',
    $telUuid['code'] === 201 && ($telUuid['json']['status'] ?? '') === 'TELEMETRY_RECORDED',
    $telUuid['raw']
);

// 3. ID numérico inexistente y formatos numéricos no canónicos ("0", "09601")
$nonExistentNum = call_v2_endpoint('GET', '/api/v2/devices/context', [
    'HTTP_X_DEVICE_ID' => '999999',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
]);
assert_remediation(
    'T03-A',
    'ID numérico inexistente ("999999") falla cerrado con 401 CREDENTIAL_NOT_FOUND',
    $nonExistentNum['code'] === 401 && ($nonExistentNum['json']['error_detail'] ?? '') === 'CREDENTIAL_NOT_FOUND',
    $nonExistentNum['raw']
);

$zeroId = call_v2_endpoint('GET', '/api/v2/devices/context', [
    'HTTP_X_DEVICE_ID' => '0',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
]);
assert_remediation(
    'T03-B',
    'ID numérico "0" falla cerrado con 401 CREDENTIAL_NOT_FOUND',
    $zeroId['code'] === 401 && ($zeroId['json']['error_detail'] ?? '') === 'CREDENTIAL_NOT_FOUND',
    $zeroId['raw']
);

$leadingZeroId = call_v2_endpoint('GET', '/api/v2/devices/context', [
    'HTTP_X_DEVICE_ID' => '09601',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
]);
assert_remediation(
    'T03-C',
    'ID numérico con cero a la izquierda ("09601") falla cerrado con 401 CREDENTIAL_NOT_FOUND',
    $leadingZeroId['code'] === 401 && ($leadingZeroId['json']['error_detail'] ?? '') === 'CREDENTIAL_NOT_FOUND',
    $leadingZeroId['raw']
);

// 4. Credencial asociada a otro dispositivo (Cross-device isolation)
$crossNum = call_v2_endpoint('GET', '/api/v2/devices/context', [
    'HTTP_X_DEVICE_ID' => '9601',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9602-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretB,
]);
assert_remediation(
    'T04-A',
    'X-Device-Id numérico 9601 con credencial válida del dispositivo 9602 es rechazado con 401 CREDENTIAL_NOT_FOUND',
    $crossNum['code'] === 401 && ($crossNum['json']['error_detail'] ?? '') === 'CREDENTIAL_NOT_FOUND',
    $crossNum['raw']
);

$crossUuid = call_v2_endpoint('POST', '/api/v2/punch-events', [
    'HTTP_X_DEVICE_ID' => 'test-pilot-uuid-9601-abcd',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9602-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretB,
], [
    'event_uuid' => 'ev-cross-should-fail',
    'external_worker_reference' => 'EMP-9601',
    'source_timestamp' => '2026-10-19 08:10:00',
]);
assert_remediation(
    'T04-B',
    'X-Device-Id UUID de 9601 con credencial válida del dispositivo 9602 es rechazado en punch-events con 401 CREDENTIAL_NOT_FOUND',
    $crossUuid['code'] === 401 && ($crossUuid['json']['error_detail'] ?? '') === 'CREDENTIAL_NOT_FOUND',
    $crossUuid['raw']
);

// 5. Secreto incorrecto
$badSecret = call_v2_endpoint('POST', '/api/v2/devices/generations', [
    'HTTP_X_DEVICE_ID' => '9601',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer wrong-secret-value',
], [
    'device_uuid' => 'test-pilot-uuid-9601-abcd',
    'device_generation' => 'gen-should-not-register',
]);
assert_remediation(
    'T05',
    'Secreto incorrecto con ID numérico válido es rechazado con 401 INVALID_SECRET',
    $badSecret['code'] === 401 && ($badSecret['json']['error_detail'] ?? '') === 'INVALID_SECRET',
    $badSecret['raw']
);

// 6. Credencial vencida y credencial aún no vigente
$expiredCred = call_v2_endpoint('GET', '/api/v2/devices/context', [
    'HTTP_X_DEVICE_ID' => '9603',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9603-expired',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
]);
assert_remediation(
    'T06-A',
    'Credencial vencida (valido_hasta en el pasado) es rechazada con 401 CREDENTIAL_EXPIRED',
    $expiredCred['code'] === 401 && ($expiredCred['json']['error_detail'] ?? '') === 'CREDENTIAL_EXPIRED',
    $expiredCred['raw']
);

$conn->query("UPDATE op_rh_v2_dispositivo_credenciales SET estado = 'EXPIRED' WHERE credential_key = 'cred-key-9603-expired'");
$conn->query("UPDATE op_rh_v2_dispositivo_credenciales SET estado = 'ACTIVE' WHERE credential_key = 'cred-key-9603-future'");
$futureCred = call_v2_endpoint('GET', '/api/v2/devices/context', [
    'HTTP_X_DEVICE_ID' => '9603',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9603-future',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
]);
assert_remediation(
    'T06-B',
    'Credencial con valido_desde en el futuro es rechazada con 401 CREDENTIAL_NOT_YET_VALID',
    $futureCred['code'] === 401 && ($futureCred['json']['error_detail'] ?? '') === 'CREDENTIAL_NOT_YET_VALID',
    $futureCred['raw']
);

// 7. Credencial revocada (por estado = REVOKED y por revocado_at != NULL)
$revokedState = call_v2_endpoint('GET', '/api/v2/devices/context', [
    'HTTP_X_DEVICE_ID' => '9604',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9604-revoked-state',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
]);
assert_remediation(
    'T07-A',
    'Credencial con estado=REVOKED es rechazada con 401 CREDENTIAL_REVOKED_OR_INACTIVE',
    $revokedState['code'] === 401 && ($revokedState['json']['error_detail'] ?? '') === 'CREDENTIAL_REVOKED_OR_INACTIVE',
    $revokedState['raw']
);

$revokedTs = call_v2_endpoint('GET', '/api/v2/devices/context', [
    'HTTP_X_DEVICE_ID' => '9604',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9604-revoked-ts',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
]);
assert_remediation(
    'T07-B',
    'Credencial con revocado_at poblado (aun si estado=ACTIVE) es rechazada con 401 CREDENTIAL_REVOKED_OR_INACTIVE',
    $revokedTs['code'] === 401 && ($revokedTs['json']['error_detail'] ?? '') === 'CREDENTIAL_REVOKED_OR_INACTIVE',
    $revokedTs['raw']
);

// 8. Dispositivo inactivo (estado = 0)
$disabledNum = call_v2_endpoint('POST', '/api/v2/devices/telemetry', [
    'HTTP_X_DEVICE_ID' => '9605',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9605-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
], [
    'device_generation' => 'gen-9605',
    'telemetry_sequence' => 1,
    'observation_timestamp' => '2026-10-19T14:00:00Z',
]);
assert_remediation(
    'T08',
    'Dispositivo inactivo (estado=0) con ID numérico es rechazado con 401 DEVICE_DISABLED',
    $disabledNum['code'] === 401 && ($disabledNum['json']['error_detail'] ?? '') === 'DEVICE_DISABLED',
    $disabledNum['raw']
);

// 9. Headers ausentes
$missingHeaders1 = call_v2_endpoint('GET', '/api/v2/devices/context', []);
$missingHeaders2 = call_v2_endpoint('POST', '/api/v2/devices/generations', ['HTTP_X_DEVICE_ID' => '9601'], ['device_uuid' => 'test-pilot-uuid-9601-abcd', 'device_generation' => 'g1']);
$missingHeaders3 = call_v2_endpoint('POST', '/api/v2/punch-events', ['HTTP_X_DEVICE_ID' => '   ', 'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active', 'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA], []);
$missingHeaders4 = call_v2_endpoint('POST', '/api/v2/devices/telemetry', ['HTTP_X_DEVICE_ID' => '9601', 'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active'], []);
assert_remediation(
    'T09',
    'Headers ausentes o en blanco en context, generations, punch-events y telemetry fallan cerrados con 401',
    $missingHeaders1['code'] === 401
        && $missingHeaders2['code'] === 401
        && $missingHeaders3['code'] === 401
        && $missingHeaders4['code'] === 401,
    "codes={$missingHeaders1['code']},{$missingHeaders2['code']},{$missingHeaders3['code']},{$missingHeaders4['code']}"
);

// 10. device_uuid (o id_dispositivo) del JSON diferente al dispositivo autenticado
$mismatchGen = call_v2_endpoint('POST', '/api/v2/devices/generations', [
    'HTTP_X_DEVICE_ID' => '9601',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
], [
    'device_uuid' => 'test-pilot-uuid-9602-efgh',
    'device_generation' => 'gen-spoof-attempt',
]);
assert_remediation(
    'T10-A',
    'POST /api/v2/devices/generations con device_uuid de otro dispositivo retorna 403 DEVICE_MISMATCH',
    $mismatchGen['code'] === 403 && ($mismatchGen['json']['status'] ?? '') === 'DEVICE_MISMATCH',
    $mismatchGen['raw']
);

$mismatchPunchUuid = call_v2_endpoint('POST', '/api/v2/punch-events', [
    'HTTP_X_DEVICE_ID' => '9601',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
], [
    'event_uuid' => 'ev-spoof-uuid-0001',
    'device_uuid' => 'test-pilot-uuid-9602-efgh',
    'external_worker_reference' => 'EMP-9601',
    'source_timestamp' => '2026-10-19 08:30:00',
]);
assert_remediation(
    'T10-B',
    'POST /api/v2/punch-events con device_uuid divergente en el JSON retorna 403 DEVICE_MISMATCH',
    $mismatchPunchUuid['code'] === 403 && ($mismatchPunchUuid['json']['status'] ?? '') === 'DEVICE_MISMATCH',
    $mismatchPunchUuid['raw']
);

$mismatchPunchId = call_v2_endpoint('POST', '/api/v2/punch-events', [
    'HTTP_X_DEVICE_ID' => '9601',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
], [
    'event_uuid' => 'ev-spoof-id-0001',
    'id_dispositivo' => 9602,
    'external_worker_reference' => 'EMP-9601',
    'source_timestamp' => '2026-10-19 08:31:00',
]);
assert_remediation(
    'T10-C',
    'POST /api/v2/punch-events con id_dispositivo divergente en el JSON retorna 403 DEVICE_MISMATCH',
    $mismatchPunchId['code'] === 403 && ($mismatchPunchId['json']['status'] ?? '') === 'DEVICE_MISMATCH',
    $mismatchPunchId['raw']
);

$mismatchTelUuid = call_v2_endpoint('POST', '/api/v2/devices/telemetry', [
    'HTTP_X_DEVICE_ID' => '9601',
    'HTTP_X_CREDENTIAL_KEY' => 'cred-key-9601-active',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $secretA,
], [
    'device_uuid' => 'test-pilot-uuid-9602-efgh',
    'device_generation' => 'gen-9601-numeric-v1',
    'telemetry_sequence' => 99,
    'observation_timestamp' => '2026-10-19T14:30:00Z',
]);
assert_remediation(
    'T10-D',
    'POST /api/v2/devices/telemetry con device_uuid divergente en el JSON retorna 403 DEVICE_MISMATCH',
    $mismatchTelUuid['code'] === 403 && ($mismatchTelUuid['json']['status'] ?? '') === 'DEVICE_MISMATCH',
    $mismatchTelUuid['raw']
);

cleanup_fixtures($conn, $idList);

// Restore immutability trigger on op_rh_v2_marcaciones_crudas
$sqlTriggers = file_get_contents(__DIR__ . '/../migraciones/v2/2026_10_02_000003_add_raw_punch_immutability_triggers.sql');
$sqlTriggersClean = preg_replace('/DELIMITER\s+\S+/i', '', $sqlTriggers);
$sqlTriggersClean = str_replace('$$', ';', $sqlTriggersClean);
if ($conn->multi_query($sqlTriggersClean)) {
    do {
        if ($res = $conn->store_result()) {
            $res->free();
        }
    } while ($conn->more_results() && $conn->next_result());
}

$bd->desconectarBD($conn);

echo "\n=== RESUMEN DE PRUEBAS DE REMEDIACIÓN X-Device-Id ===\n";
echo "Total:   {$total}\n";
echo "Pasaron: {$passed}\n";
echo "Fallaron:{$failed}\n";

exit($failed === 0 ? 0 : 1);
