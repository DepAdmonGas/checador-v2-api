<?php

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
$level2ServiceFile = getenv('CHECADOR_LEVEL2_SERVICE_FILE') ?: __DIR__ . '/../modelo/v2/Level2ServerService.php';
require_once $level2ServiceFile;

function l2_append_assert(bool $condition, string $id, string $description, int &$pass, int &$fail): void {
    echo sprintf("[%s] %s: %s\n", $id, $condition ? 'PASS' : 'FAIL', $description);
    $condition ? $pass++ : $fail++;
}

function l2_append_fixture(mysqli $db, Level2ServerService $service, string $tag): array {
    $uuid = 'L2APP_' . strtoupper(bin2hex(random_bytes(8)));
    $generation = 'GEN_' . strtoupper(bin2hex(random_bytes(8)));
    $name = 'Synthetic append-only telemetry fixture';
    $stationId = random_int(1000000, 1900000000);
    $stmt = $db->prepare('INSERT INTO op_rh_v2_dispositivos (uuid_dispositivo, id_estacion, nombre, estado) VALUES (?, ?, ?, 1)');
    $stmt->bind_param('sis', $uuid, $stationId, $name);
    $stmt->execute();
    $deviceId = (int)$stmt->insert_id;
    $stmt->close();

    $generationResult = $service->registrarGeneracion($db, $deviceId, $uuid, $generation);
    if (empty($generationResult['success'])) {
        throw new RuntimeException('Synthetic generation registration failed.');
    }
    return ['device_id' => $deviceId, 'generation' => $generation, 'tag' => $tag];
}

function l2_append_submit(Level2ServerService $service, mysqli $db, array $fixture, int $sequence, string $timestamp, array $payload, ?string $receivedAt = null): array {
    return $service->registrarTelemetria(
        $db,
        $fixture['device_id'],
        $fixture['generation'],
        $sequence,
        $timestamp,
        98,
        'CONNECTED',
        'SYNCED',
        $payload,
        $receivedAt
    );
}

function l2_append_row(mysqli $db, array $fixture, int $sequence): ?array {
    $stmt = $db->prepare('SELECT id, observation_timestamp_raw, server_received_at_utc, battery_level, network_status, clock_status, payload_json FROM op_rh_v2_dispositivo_telemetria WHERE id_dispositivo = ? AND device_generation = ? AND telemetry_sequence = ? LIMIT 1');
    $stmt->bind_param('isi', $fixture['device_id'], $fixture['generation'], $sequence);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function l2_append_count(mysqli $db, array $fixture, int $sequence): int {
    $stmt = $db->prepare('SELECT COUNT(*) AS total FROM op_rh_v2_dispositivo_telemetria WHERE id_dispositivo = ? AND device_generation = ? AND telemetry_sequence = ?');
    $stmt->bind_param('isi', $fixture['device_id'], $fixture['generation'], $sequence);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $count;
}

function l2_append_connection(string $user, string $password): mysqli {
    $db = new mysqli((string)getenv('DB_HOST'), $user, $password, (string)getenv('DB_NAME'), (int)getenv('DB_PORT'));
    $db->set_charset('utf8');
    return $db;
}

function l2_append_http_request(string $url, int $deviceId, string $credentialKey, string $secret, int $sequence, array $payload): array {
    $body = json_encode([
        'device_generation' => 'GEN_HTTP_APPEND_ONLY',
        'telemetry_sequence' => $sequence,
        'observation_timestamp' => '2026-10-27T08:30:00.000Z',
        'battery_level' => 98,
        'network_status' => 'CONNECTED',
        'clock_status' => 'SYNCED',
        'payload' => $payload
    ], JSON_THROW_ON_ERROR);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
            'Content-Type: application/json',
            'X-Device-Id: ' . $deviceId,
            'X-Credential-Key: ' . $credentialKey,
            'Authorization: Bearer ' . $secret
        ]),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 10
    ]]);
    $responseBody = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s([0-9]{3})\s/', $statusLine, $matches);
    return [
        'http_code' => isset($matches[1]) ? (int)$matches[1] : 0,
        'json' => is_string($responseBody) ? json_decode($responseBody, true) : null,
        'body' => $responseBody
    ];
}

function l2_append_concurrent_pair(array $fixture, int $sequence, string $timestamp, array $payloads, string $barrierDir, string $dbUser, string $dbPassword): array {
    $children = [];
    $resultPaths = [];
    foreach ($payloads as $index => $payload) {
        $readyPath = $barrierDir . '/ready-' . $index;
        $goPath = $barrierDir . '/go';
        $resultPath = $barrierDir . '/result-' . $index . '.json';
        $resultPaths[] = $resultPath;
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not fork telemetry race worker.');
        }
        if ($pid === 0) {
            try {
                touch($readyPath);
                $deadline = microtime(true) + 15;
                while (!is_file($goPath) && microtime(true) < $deadline) {
                    usleep(1000);
                }
                if (!is_file($goPath)) {
                    throw new RuntimeException('Concurrent worker barrier timed out.');
                }
                $workerDb = l2_append_connection($dbUser, $dbPassword);
                $service = new Level2ServerService();
                $result = l2_append_submit($service, $workerDb, $fixture, $sequence, $timestamp, $payload);
                $workerDb->close();
                file_put_contents($resultPath, json_encode(['result' => $result, 'payload' => $payload], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (Throwable $e) {
                file_put_contents($resultPath, json_encode(['exception' => $e->getMessage(), 'code' => $e->getCode()]));
                exit(1);
            }
        }
        $children[] = $pid;
    }

    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        $allReady = true;
        foreach (array_keys($payloads) as $index) {
            $allReady = $allReady && is_file($barrierDir . '/ready-' . $index);
        }
        if ($allReady) {
            break;
        }
        usleep(1000);
    }
    file_put_contents($barrierDir . '/go', 'go');

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }
    $results = [];
    foreach ($resultPaths as $path) {
        $decoded = json_decode((string)@file_get_contents($path), true);
        $results[] = is_array($decoded) ? $decoded : ['exception' => 'Concurrent worker returned no result.'];
    }
    return $results;
}

$pass = 0;
$fail = 0;
$root = null;
$db = null;
$workDir = (string)getenv('CHECADOR_L2_APPEND_TEST_DIR');
$runtimeUser = 'l2append_' . bin2hex(random_bytes(4));
$runtimePassword = bin2hex(random_bytes(20));
$readOnlyUser = 'l2readonly_' . bin2hex(random_bytes(4));
$readOnlyPassword = bin2hex(random_bytes(20));
$dbName = (string)getenv('DB_NAME');
$dbNameQuoted = '`' . str_replace('`', '``', $dbName) . '`';

try {
    if ($workDir === '' || !is_dir($workDir) || !function_exists('pcntl_fork')) {
        throw new RuntimeException('Test runner directory or pcntl support is unavailable.');
    }

    $bd = new BD();
    $root = $bd->conectarBD();
    $db = $root;
    $uniqueIndexes = [];
    $indexRows = $root->query('SHOW INDEX FROM op_rh_v2_dispositivo_telemetria');
    while ($index = $indexRows->fetch_assoc()) {
        if ((int)$index['Non_unique'] === 0) {
            $uniqueIndexes[$index['Key_name']][(int)$index['Seq_in_index']] = $index['Column_name'];
        }
    }
    foreach ($uniqueIndexes as &$columns) {
        ksort($columns);
        $columns = array_values($columns);
    }
    unset($columns);
    l2_append_assert(
        ($uniqueIndexes['uk_disp_gen_telem_seq'] ?? []) === ['id_dispositivo', 'device_generation', 'telemetry_sequence']
            && array_diff(array_keys($uniqueIndexes), ['PRIMARY', 'uk_disp_gen_telem_seq']) === [],
        'S1',
        'Physical unique indexes contain only the primary key and canonical device/generation/sequence identity',
        $pass,
        $fail
    );
    $service = new Level2ServerService();
    $fixture = l2_append_fixture($root, $service, 'primary');
    $basePayload = ['application_running' => true, 'metrics' => ['temperature' => 36.5, 'labels' => ['sdk', 'sensor']]];

    $root->query("CREATE USER '{$runtimeUser}'@'%' IDENTIFIED BY '{$runtimePassword}'");
    $root->query("GRANT SELECT ON {$dbNameQuoted}.op_rh_v2_dispositivo_generaciones TO '{$runtimeUser}'@'%'");
    $root->query("GRANT SELECT, INSERT ON {$dbNameQuoted}.op_rh_v2_dispositivo_telemetria TO '{$runtimeUser}'@'%'");
    $root->query("CREATE USER '{$readOnlyUser}'@'%' IDENTIFIED BY '{$readOnlyPassword}'");
    $root->query("GRANT SELECT ON {$dbNameQuoted}.op_rh_v2_dispositivo_generaciones TO '{$readOnlyUser}'@'%'");
    $root->query("GRANT SELECT ON {$dbNameQuoted}.op_rh_v2_dispositivo_telemetria TO '{$readOnlyUser}'@'%'");

    $runtime = l2_append_connection($runtimeUser, $runtimePassword);
    $first = l2_append_submit($service, $runtime, $fixture, 1, '2026-10-27T08:00:00.000Z', $basePayload, '2026-10-27 08:00:01');
    l2_append_assert($first['code'] === 201 && $first['status'] === 'TELEMETRY_RECORDED', 'T1', 'First valid sample is inserted', $pass, $fail);

    $original = l2_append_row($runtime, $fixture, 1);
    $retry = l2_append_submit($service, $runtime, $fixture, 1, '2026-10-27T08:00:00.000Z', ['metrics' => ['labels' => ['sdk', 'sensor'], 'temperature' => 36.5], 'application_running' => true], '2026-10-27 08:10:00');
    $afterRetry = l2_append_row($runtime, $fixture, 1);
    l2_append_assert(
        $retry['code'] === 200 && $retry['status'] === 'ALREADY_RECEIVED'
            && l2_append_count($runtime, $fixture, 1) === 1
            && $original === $afterRetry,
        'T2',
        'Identical retry is idempotent, ignores object-key order and preserves every stored field including server receipt time',
        $pass,
        $fail
    );

    $payloadConflict = $basePayload;
    $payloadConflict['metrics']['temperature'] = '36.5';
    $payloadConflictResult = l2_append_submit($service, $runtime, $fixture, 1, '2026-10-27T08:00:00.000Z', $payloadConflict);
    l2_append_assert($payloadConflictResult['code'] === 409 && $payloadConflictResult['status'] === 'SEQUENCE_COLLISION', 'T3', 'Same sequence with a divergent, differently typed payload is rejected', $pass, $fail);

    $timestampConflict = l2_append_submit($service, $runtime, $fixture, 1, '2026-10-27T08:00:01.000Z', $basePayload);
    l2_append_assert($timestampConflict['code'] === 409 && $timestampConflict['status'] === 'SEQUENCE_COLLISION', 'T4', 'Same sequence with a divergent observation timestamp is rejected', $pass, $fail);

    $next = l2_append_submit($service, $runtime, $fixture, 2, '2026-10-27T08:01:00.000Z', $basePayload);
    l2_append_assert($next['code'] === 201 && l2_append_count($runtime, $fixture, 2) === 1, 'T5', 'Next consecutive sequence inserts successfully', $pass, $fail);

    // Fork only after closing parent connections; child process shutdown must not
    // invalidate inherited MySQL sockets in the parent process.
    $runtime->close();
    $root->close();
    $root = null;
    $db = null;

    $duplicateRace = l2_append_concurrent_pair(
        $fixture,
        10,
        '2026-10-27T08:10:00.000Z',
        [$basePayload, $basePayload],
        $workDir . '/same',
        $runtimeUser,
        $runtimePassword
    );
    $root = new mysqli((string)getenv('DB_HOST'), (string)getenv('DB_USER'), (string)getenv('DB_PASS'), $dbName, (int)getenv('DB_PORT'));
    $root->set_charset('utf8');
    $db = $root;
    $runtime = l2_append_connection($runtimeUser, $runtimePassword);
    $duplicateStatuses = array_map(static fn(array $item): string => (string)($item['result']['status'] ?? ''), $duplicateRace);
    sort($duplicateStatuses);
    l2_append_assert(
        $duplicateStatuses === ['ALREADY_RECEIVED', 'TELEMETRY_RECORDED']
            && l2_append_count($runtime, $fixture, 10) === 1,
        'T6',
        'Concurrent duplicate submissions result in exactly one stored row and one idempotent acknowledgement',
        $pass,
        $fail
    );
    $runtime->close();
    $root->close();
    $runtime = null;
    $root = null;
    $db = null;

    $variantA = ['capture' => ['healthy' => true, 'counter' => 1]];
    $variantB = ['capture' => ['healthy' => false, 'counter' => 2]];
    $conflictRace = l2_append_concurrent_pair(
        $fixture,
        11,
        '2026-10-27T08:11:00.000Z',
        [$variantA, $variantB],
        $workDir . '/conflict',
        $runtimeUser,
        $runtimePassword
    );
    $root = new mysqli((string)getenv('DB_HOST'), (string)getenv('DB_USER'), (string)getenv('DB_PASS'), $dbName, (int)getenv('DB_PORT'));
    $root->set_charset('utf8');
    $db = $root;
    $runtime = l2_append_connection($runtimeUser, $runtimePassword);
    $conflictStatuses = array_map(static fn(array $item): string => (string)($item['result']['status'] ?? ''), $conflictRace);
    sort($conflictStatuses);
    $storedConflict = l2_append_row($runtime, $fixture, 11);
    $storedPayload = json_decode((string)($storedConflict['payload_json'] ?? ''), true);
    $acceptedPayload = null;
    foreach ($conflictRace as $item) {
        if (($item['result']['code'] ?? null) === 201) {
            $acceptedPayload = $item['payload'] ?? null;
        }
    }
    l2_append_assert(
        $conflictStatuses === ['SEQUENCE_COLLISION', 'TELEMETRY_RECORDED']
            && l2_append_count($runtime, $fixture, 11) === 1
            && $storedPayload === $acceptedPayload,
        'T7',
        'Concurrent divergent content preserves the winning sample and rejects the competing sample',
        $pass,
        $fail
    );

    $receiptBefore = (string)($original['server_received_at_utc'] ?? '');
    $retryAgain = l2_append_submit($service, $runtime, $fixture, 1, '2026-10-27T08:00:00.000Z', $basePayload, '2026-10-27 08:20:00');
    $receiptAfter = (string)(l2_append_row($runtime, $fixture, 1)['server_received_at_utc'] ?? '');
    l2_append_assert($retryAgain['code'] === 200 && $receiptBefore === $receiptAfter, 'T8', 'server_received_at_utc remains unchanged after retry', $pass, $fail);

    $grants = $runtime->query('SHOW GRANTS FOR CURRENT_USER()')->fetch_all(MYSQLI_NUM);
    $grantText = implode("\n", array_map(static fn(array $row): string => implode(' ', $row), $grants));
    $restrictedInsert = l2_append_submit($service, $runtime, $fixture, 12, '2026-10-27T08:12:00.000Z', $basePayload);
    l2_append_assert(
        stripos($grantText, 'INSERT') !== false
            && stripos($grantText, 'SELECT') !== false
            && stripos($grantText, 'UPDATE') === false
            && $restrictedInsert['code'] === 201,
        'T9',
        'Runtime account with SELECT and INSERT but no UPDATE can ingest telemetry',
        $pass,
        $fail
    );

    $readOnly = l2_append_connection($readOnlyUser, $readOnlyPassword);
    $otherSqlErrorWasNotDuplicate = false;
    try {
        l2_append_submit($service, $readOnly, $fixture, 13, '2026-10-27T08:13:00.000Z', $basePayload);
    } catch (mysqli_sql_exception $e) {
        $otherSqlErrorWasNotDuplicate = (int)$e->getCode() !== 1062;
    }
    l2_append_assert(
        $otherSqlErrorWasNotDuplicate && l2_append_count($runtime, $fixture, 13) === 0,
        'T10',
        'Non-duplicate INSERT privilege error is not misclassified as an idempotent retry',
        $pass,
        $fail
    );

    $httpUrl = (string)getenv('CHECADOR_TEST_TELEMETRY_URL');
    if ($httpUrl !== '') {
        $httpFixture = l2_append_fixture($root, $service, 'http');
        $httpGeneration = $httpFixture['generation'];
        // The endpoint fixture uses a fixed synthetic generation named by the HTTP payload.
        $httpUuidStmt = $root->prepare('SELECT uuid_dispositivo FROM op_rh_v2_dispositivos WHERE id = ? LIMIT 1');
        $httpUuidStmt->bind_param('i', $httpFixture['device_id']);
        $httpUuidStmt->execute();
        $httpUuid = (string)$httpUuidStmt->get_result()->fetch_assoc()['uuid_dispositivo'];
        $httpUuidStmt->close();
        $httpGenerationResult = $service->registrarGeneracion($root, $httpFixture['device_id'], $httpUuid, 'GEN_HTTP_APPEND_ONLY');
        $httpSecret = 'synthetic-level2-http-secret';
        $httpCredentialKey = 'l2-http-' . bin2hex(random_bytes(4));
        $httpSecretHash = password_hash($httpSecret, PASSWORD_BCRYPT);
        $credentialStmt = $root->prepare('INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, valido_desde, estado, creado_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), "ACTIVE", UTC_TIMESTAMP())');
        $credentialStmt->bind_param('iss', $httpFixture['device_id'], $httpCredentialKey, $httpSecretHash);
        $credentialStmt->execute();
        $credentialStmt->close();
        $httpPayload = ['http_smoke' => true, 'sequence' => 100];
        $httpFirst = l2_append_http_request($httpUrl, $httpFixture['device_id'], $httpCredentialKey, $httpSecret, 100, $httpPayload);
        $httpRetry = l2_append_http_request($httpUrl, $httpFixture['device_id'], $httpCredentialKey, $httpSecret, 100, $httpPayload);
        $httpConflict = l2_append_http_request($httpUrl, $httpFixture['device_id'], $httpCredentialKey, $httpSecret, 100, ['http_smoke' => true, 'sequence' => 101]);
        l2_append_assert(
            !empty($httpGenerationResult['success'])
                && $httpFirst['http_code'] === 201 && ($httpFirst['json']['status'] ?? '') === 'TELEMETRY_RECORDED'
                && $httpRetry['http_code'] === 200 && ($httpRetry['json']['status'] ?? '') === 'ALREADY_RECEIVED'
                && $httpConflict['http_code'] === 409 && ($httpConflict['json']['status'] ?? '') === 'SEQUENCE_COLLISION',
            'HTTP1',
            'Controller keeps the documented HTTP 201, 200, and 409 telemetry responses',
            $pass,
            $fail
        );
    } else {
        l2_append_assert(false, 'HTTP1', 'Loopback telemetry URL is required to verify controller responses', $pass, $fail);
    }

    $readOnly->close();
    $runtime->close();
    echo "PASS: {$pass} / " . ($pass + $fail) . "\nFAIL: {$fail} / " . ($pass + $fail) . "\n";
} catch (Throwable $e) {
    echo '[ ERROR ] ' . $e->getMessage() . "\n";
    $fail++;
    echo "PASS: {$pass} / " . ($pass + $fail) . "\nFAIL: {$fail} / " . ($pass + $fail) . "\n";
} finally {
    if ($db instanceof mysqli) {
        $db->query("DROP USER IF EXISTS '{$runtimeUser}'@'%'");
        $db->query("DROP USER IF EXISTS '{$readOnlyUser}'@'%'");
        $db->close();
    }
}

exit($fail > 0 ? 1 : 0);
