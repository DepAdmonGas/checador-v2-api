<?php
/**
 * End-to-end HTTP integration tests for POST /api/v2/punch-events.
 * Requires the disposable MariaDB and local API started by run_v2_real_ingestion_integration.sh.
 */

require_once __DIR__ . '/../modelo/v2/PunchEventIntegrityService.php';
require_once __DIR__ . '/../modelo/v2/Level2ServerService.php';

/** @var list<string> $integrationFailures */
$integrationFailures = [];
$integrationCheckCount = 0;
$integrationPassCount = 0;

function reportReal(string $name, bool $passed, string $detail = ''): void {
    global $integrationFailures, $integrationCheckCount, $integrationPassCount;
    $integrationCheckCount++;
    $label = $passed ? 'PASS_REAL' : 'FAIL';
    echo "[$label] $name" . ($detail !== '' ? ": $detail" : '') . "\n";
    if (!$passed) {
        $integrationFailures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    } else {
        $integrationPassCount++;
    }
}

function assertReal(string $name, bool $condition, string $detail = ''): void {
    reportReal($name, $condition, $condition ? '' : $detail);
}

function postPunch(string $url, string $deviceUuid, string $credentialKey, string $secret, array $body): array {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json; charset=utf-8',
                'X-Device-Id: ' . $deviceUuid,
                'X-Credential-Key: ' . $credentialKey,
                'Authorization: Bearer ' . $secret,
            ]),
            'content' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $responseBody = file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    $contentType = null;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            $status = (int)$matches[1];
        } elseif (stripos($header, 'Content-Type:') === 0) {
            $contentType = trim(substr($header, strlen('Content-Type:')));
        }
    }
    $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;
    return ['http' => $status, 'content_type' => $contentType, 'body' => is_array($decoded) ? $decoded : null, 'raw' => $responseBody];
}

function readEvent(PDO $pdo, string $eventUuid): ?array {
    $stmt = $pdo->prepare('SELECT * FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = ? LIMIT 1');
    $stmt->execute([$eventUuid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function makeBody(string $uuid, string $timestamp, ?int $sequence = null, array $metadata = ['fixture' => 'synthetic']): array {
    $body = [
        'event_uuid' => $uuid,
        'external_worker_reference' => 'synthetic-worker-1',
        'source_timestamp' => $timestamp,
        'source_timezone' => 'UTC',
        'capture_method' => 'BIOMETRIC',
        'external_event_id' => 'synthetic-' . $uuid,
        'metadata' => $metadata,
    ];
    if ($sequence !== null) {
        $body['device_generation'] = 'synthetic-generation-1';
        $body['device_sequence'] = $sequence;
    }
    return $body;
}

function isoUtc(DateTimeImmutable $date): string {
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

$requiredEnv = ['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASS', 'DB_NAME', 'CHECADOR_TEST_DB_EXPECTED_HOSTNAME', 'CHECADOR_TEST_API_URL'];
foreach ($requiredEnv as $name) {
    if (getenv($name) === false || getenv($name) === '') {
        fwrite(STDERR, "BLOCKED: missing test-only environment variable $name\n");
        exit(2);
    }
}

$pdo = new PDO(
    'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
    getenv('DB_USER'),
    getenv('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$serverIdentity = $pdo->query('SELECT @@hostname AS hostname, @@port AS port, DATABASE() AS database_name, VERSION() AS version')->fetch();
$expectedHost = getenv('CHECADOR_TEST_DB_EXPECTED_HOSTNAME');
assertReal(
    'isolated MariaDB identity',
    $serverIdentity['database_name'] === 'bd_admongas_v2_test_20261002'
        && str_starts_with((string)$serverIdentity['hostname'], $expectedHost)
        && (int)$serverIdentity['port'] === 3306
        && stripos($serverIdentity['version'], 'MariaDB') !== false,
    json_encode($serverIdentity, JSON_UNESCAPED_SLASHES)
);
assertReal('test schema is not bd_admongas', $serverIdentity['database_name'] !== 'bd_admongas');
if ($serverIdentity['database_name'] !== 'bd_admongas_v2_test_20261002'
    || !str_starts_with((string)$serverIdentity['hostname'], $expectedHost)
    || (int)$serverIdentity['port'] !== 3306
    || stripos($serverIdentity['version'], 'MariaDB') === false) {
    fwrite(STDERR, "BLOCKED: refusing fixture writes because database isolation identity did not match.\n");
    exit(2);
}

$deviceSecret = 'synthetic-device-secret-only-for-disposable-test';
$devices = [];
for ($i = 1; $i <= 4; $i++) {
    $uuid = sprintf('SYNTH-DEVICE-%03d', $i);
    $credential = sprintf('SYNTH-CRED-%03d', $i);
    $stmt = $pdo->prepare('INSERT INTO op_rh_v2_dispositivos (uuid_dispositivo, id_estacion, nombre, timezone, estado) VALUES (?, 502, ?, ?, 1)');
    $stmt->execute([$uuid, 'Synthetic Device ' . $i, 'UTC']);
    $deviceId = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, valido_desde, valido_hasta, estado, creado_at) VALUES (?, ?, ?, ?, NULL, "ACTIVE", ?)');
    $nowSql = gmdate('Y-m-d H:i:s');
    $stmt->execute([$deviceId, $credential, password_hash($deviceSecret, PASSWORD_BCRYPT), '2020-01-01 00:00:00', $nowSql]);
    $stmt = $pdo->prepare('INSERT INTO op_rh_v2_dispositivo_identidad_personal (id_dispositivo, referencia_externa_personal, id_personal, valido_desde, valido_hasta, es_activo) VALUES (?, ?, 9001, "2020-01-01 00:00:00", NULL, 1)');
    $stmt->execute([$deviceId, 'synthetic-worker-1']);
    $devices[$i] = ['id' => $deviceId, 'uuid' => $uuid, 'credential' => $credential];
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$switch = $now->modify('-4 minutes')->setTime((int)$now->modify('-4 minutes')->format('H'), (int)$now->modify('-4 minutes')->format('i'), 0);
$switchSql = $switch->format('Y-m-d H:i:s');
$oldStart = '2020-01-01 00:00:00';
$stmt = $pdo->prepare('INSERT INTO op_rh_v2_dispositivo_generaciones (id_dispositivo, device_uuid, device_generation, estado, registrado_at_utc) VALUES (?, ?, "synthetic-generation-1", "ACTIVE", ?)');
$stmt->execute([$devices[1]['id'], $devices[1]['uuid'], gmdate('Y-m-d H:i:s')]);

$stmt = $pdo->prepare('INSERT INTO op_rh_v2_dispositivo_estacion_historial (id_dispositivo, id_estacion, valido_desde_utc, valido_hasta_utc, creado_at_utc) VALUES (?, 501, ?, ?, ?)');
$stmt->execute([$devices[1]['id'], $oldStart, $switchSql, gmdate('Y-m-d H:i:s')]);
$stmt = $pdo->prepare('INSERT INTO op_rh_v2_dispositivo_estacion_historial (id_dispositivo, id_estacion, valido_desde_utc, valido_hasta_utc, creado_at_utc) VALUES (?, 502, ?, NULL, ?)');
$stmt->execute([$devices[1]['id'], $switchSql, gmdate('Y-m-d H:i:s')]);
$stmt = $pdo->prepare('INSERT INTO op_rh_v2_dispositivo_estacion_historial (id_dispositivo, id_estacion, valido_desde_utc, valido_hasta_utc, creado_at_utc) VALUES (?, ?, ?, NULL, ?)');
$stmt->execute([$devices[3]['id'], 501, $oldStart, gmdate('Y-m-d H:i:s')]);
$stmt = $pdo->prepare('INSERT INTO op_rh_v2_dispositivo_estacion_historial (id_dispositivo, id_estacion, valido_desde_utc, valido_hasta_utc, creado_at_utc) VALUES (?, ?, ?, NULL, ?)');
$stmt->execute([$devices[3]['id'], 502, $oldStart, gmdate('Y-m-d H:i:s')]);
$stmt = $pdo->prepare('INSERT INTO op_rh_v2_dispositivo_estacion_historial (id_dispositivo, id_estacion, valido_desde_utc, valido_hasta_utc, creado_at_utc) VALUES (?, 9999, ?, NULL, ?)');
$stmt->execute([$devices[4]['id'], $oldStart, gmdate('Y-m-d H:i:s')]);

$url = getenv('CHECADOR_TEST_API_URL');
$keyRing = ['test-key-v1' => 'synthetic-local-hmac-secret-for-integration-only'];

// A. Valid current assignment, authenticated HTTP request, real persistence and server-side seal.
$validUuid = 'syn-valid-' . bin2hex(random_bytes(8));
$validBody = makeBody($validUuid, isoUtc($switch->modify('+1 second')), 1);
$validResponse = postPunch($url, $devices[1]['uuid'], $devices[1]['credential'], $deviceSecret, $validBody);
$validRow = readEvent($pdo, $validUuid);
assertReal('valid HTTP ingestion accepted', $validResponse['http'] === 201 && ($validResponse['body']['status'] ?? null) === 'ACCEPTED', json_encode($validResponse['body']));
assertReal('valid event persisted exactly once', (int)$pdo->query("SELECT COUNT(*) FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = " . $pdo->quote($validUuid))->fetchColumn() === 1);
assertReal('valid event resolves current historical station B', $validRow !== null && (int)$validRow['id_estacion_resuelta'] === 502);
assertReal('valid event HMAC verifies from persisted row', $validRow !== null && PunchEventIntegrityService::verify($validRow, $keyRing) === PunchEventIntegrityService::RESULT_VALID);

// B. Delayed event from before the device moved; current device station remains B.
$delayedUuid = 'syn-delayed-' . bin2hex(random_bytes(8));
$delayedBody = makeBody($delayedUuid, isoUtc($switch->modify('-2 days')), 2);
$delayedResponse = postPunch($url, $devices[1]['uuid'], $devices[1]['credential'], $deviceSecret, $delayedBody);
$delayedRow = readEvent($pdo, $delayedUuid);
assertReal('delayed HTTP event accepted', $delayedResponse['http'] === 201);
assertReal('delayed event resolves historical station A instead of current B', $delayedRow !== null && (int)$delayedRow['id_estacion_resuelta'] === 501);

// C. Both sides of the half-open station-change boundary.
$beforeUuid = 'syn-before-' . bin2hex(random_bytes(8));
$beforeBody = makeBody($beforeUuid, isoUtc($switch->modify('-1 second')), 3);
$beforeResponse = postPunch($url, $devices[1]['uuid'], $devices[1]['credential'], $deviceSecret, $beforeBody);
$beforeRow = readEvent($pdo, $beforeUuid);
$boundaryUuid = 'syn-boundary-' . bin2hex(random_bytes(8));
$boundaryBody = makeBody($boundaryUuid, isoUtc($switch), 4);
$boundaryResponse = postPunch($url, $devices[1]['uuid'], $devices[1]['credential'], $deviceSecret, $boundaryBody);
$boundaryRow = readEvent($pdo, $boundaryUuid);
assertReal('event immediately before boundary resolves A', $beforeResponse['http'] === 201 && $beforeRow !== null && (int)$beforeRow['id_estacion_resuelta'] === 501);
assertReal('event exactly at inclusive start resolves B', $boundaryResponse['http'] === 201 && $boundaryRow !== null && (int)$boundaryRow['id_estacion_resuelta'] === 502);

// D. Missing station history, E. overlapping history, F. invalid station catalog reference.
$missingUuid = 'syn-no-history-' . bin2hex(random_bytes(8));
$missingResponse = postPunch($url, $devices[2]['uuid'], $devices[2]['credential'], $deviceSecret, makeBody($missingUuid, isoUtc($now)));
$missingRow = readEvent($pdo, $missingUuid);
assertReal('missing history persists without invented station', $missingResponse['http'] === 201 && $missingRow !== null && $missingRow['id_estacion_resuelta'] === null);

$ambiguousUuid = 'syn-ambiguous-' . bin2hex(random_bytes(8));
$ambiguousResponse = postPunch($url, $devices[3]['uuid'], $devices[3]['credential'], $deviceSecret, makeBody($ambiguousUuid, isoUtc($now)));
$ambiguousRow = readEvent($pdo, $ambiguousUuid);
assertReal('overlapping history persists without arbitrary station', $ambiguousResponse['http'] === 201 && $ambiguousRow !== null && $ambiguousRow['id_estacion_resuelta'] === null);

$invalidStationUuid = 'syn-invalid-station-' . bin2hex(random_bytes(8));
$invalidStationResponse = postPunch($url, $devices[4]['uuid'], $devices[4]['credential'], $deviceSecret, makeBody($invalidStationUuid, isoUtc($now)));
$invalidStationRow = readEvent($pdo, $invalidStationUuid);
assertReal('unlisted station fails closed without current-station fallback', $invalidStationResponse['http'] === 201 && $invalidStationRow !== null && $invalidStationRow['id_estacion_resuelta'] === null);

// G. Same event retry and divergent payload conflict.
$retryUuid = 'syn-retry-' . bin2hex(random_bytes(8));
$retryBody = makeBody($retryUuid, isoUtc($switch->modify('+2 seconds')), 5);
$retryFirst = postPunch($url, $devices[1]['uuid'], $devices[1]['credential'], $deviceSecret, $retryBody);
$retryStoredBefore = readEvent($pdo, $retryUuid);
$retrySecond = postPunch($url, $devices[1]['uuid'], $devices[1]['credential'], $deviceSecret, $retryBody);
$retryStoredAfter = readEvent($pdo, $retryUuid);
assertReal('identical retry returns contract ALREADY_RECEIVED', $retryFirst['http'] === 201 && $retrySecond['http'] === 200 && ($retrySecond['body']['code'] ?? null) === 200 && ($retrySecond['body']['status'] ?? null) === 'ALREADY_RECEIVED' && ($retrySecond['body']['event_uuid'] ?? null) === $retryUuid && $retrySecond['body'] !== null && is_string($retrySecond['content_type']) && stripos($retrySecond['content_type'], 'application/json') === 0);
assertReal('identical retry persists one unchanged row',
    (int)$pdo->query("SELECT COUNT(*) FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = " . $pdo->quote($retryUuid))->fetchColumn() === 1
        && $retryStoredBefore !== null && $retryStoredAfter !== null
        && $retryStoredBefore['integrity_seal'] === $retryStoredAfter['integrity_seal']
        && $retryStoredBefore['id_estacion_resuelta'] === $retryStoredAfter['id_estacion_resuelta']);
$conflictBody = $retryBody;
$conflictBody['source_timestamp'] = isoUtc($switch->modify('+3 seconds'));
$conflictResponse = postPunch($url, $devices[1]['uuid'], $devices[1]['credential'], $deviceSecret, $conflictBody);
$conflictStoredAfter = readEvent($pdo, $retryUuid);
$expectedConflictBody = [
    'status_code' => 409,
    'result' => 'IDEMPOTENCY_CONFLICT',
    'event_uuid' => $retryUuid,
    'error' => 'Same event_uuid submitted with divergent client payload.',
];
assertReal('payload tampering is rejected with HTTP 409', $conflictResponse['http'] === 409);
assertReal('409 Content-Type and JSON payload are valid', is_string($conflictResponse['content_type']) && stripos($conflictResponse['content_type'], 'application/json') === 0 && $conflictResponse['body'] !== null);
$actualKeys = is_array($conflictResponse['body']) ? array_keys($conflictResponse['body']) : [];
$expectedKeys = array_keys($expectedConflictBody);
sort($actualKeys);
sort($expectedKeys);
assertReal('409 JSON exactly matches the contract fields and values', $conflictResponse['body'] === $expectedConflictBody && $actualKeys === $expectedKeys, json_encode(['http' => $conflictResponse['http'], 'content_type' => $conflictResponse['content_type'], 'body' => $conflictResponse['body']], JSON_UNESCAPED_SLASHES));
assertReal('409 leaves one original event and its HMAC unchanged',
    (int)$pdo->query("SELECT COUNT(*) FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = " . $pdo->quote($retryUuid))->fetchColumn() === 1
        && $retryStoredAfter !== null && $conflictStoredAfter !== null
        && $retryStoredAfter['integrity_seal'] === $conflictStoredAfter['integrity_seal']
        && $retryStoredAfter['source_timestamp_raw'] === $conflictStoredAfter['source_timestamp_raw']
        && $retryStoredAfter['id_estacion_resuelta'] === $conflictStoredAfter['id_estacion_resuelta']);

// H. Server-side HMAC validation; contract does not accept a client-provided HMAC.
$badSealUuid = 'syn-corrupt-seal-' . bin2hex(random_bytes(8));
$badSealBody = makeBody($badSealUuid, isoUtc($switch->modify('+5 seconds')), null, ['fixture' => 'corrupted-seal']);
$badSealOccurred = $switch->modify('+5 seconds')->format('Y-m-d H:i:s');
$stmt = $pdo->prepare('INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid,id_dispositivo,id_estacion_resuelta,referencia_externa_personal,id_personal_resuelto,source_timestamp_raw,source_timezone_reported,effective_timezone,timezone_fallback_applied,ocurrido_at_utc,recibido_at_utc,metodo_captura,external_event_id,secuencia_dispositivo,processing_status,validation_flags,metadata_json,integrity_version,integrity_key_id,integrity_seal) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?)');
$stmt->execute([
    $badSealUuid, $devices[1]['id'], 502, 'synthetic-worker-1', 9001,
    $badSealBody['source_timestamp'], 'UTC', 'UTC', 0, $badSealOccurred, gmdate('Y-m-d H:i:s'),
    'BIOMETRIC', $badSealBody['external_event_id'], null, 'RECEIVED', '[]', json_encode($badSealBody['metadata']),
    'v1', 'test-key-v1', str_repeat('f', 64),
]);
$badSealResponse = postPunch($url, $devices[1]['uuid'], $devices[1]['credential'], $deviceSecret, $badSealBody);
assertReal('corrupted persisted HMAC is rejected on retry', $badSealResponse['http'] === 500 && ($badSealResponse['body']['status'] ?? null) === 'INTERNAL_ERROR');

// Authentication error remains unchanged.
$badAuth = postPunch($url, $devices[1]['uuid'], $devices[1]['credential'], 'wrong-synthetic-secret', makeBody('syn-auth-' . bin2hex(random_bytes(8)), isoUtc($now)));
assertReal('invalid device bearer is rejected with 401', $badAuth['http'] === 401 && ($badAuth['body']['code'] ?? null) === 401);

// J. Append-only triggers reject updates/deletes and preserve the synthetic event.
$protectedUuid = $validUuid;
$beforeImmutability = readEvent($pdo, $protectedUuid);
$updateRejected = false;
$deleteRejected = false;
try {
    $stmt = $pdo->prepare('UPDATE op_rh_v2_marcaciones_crudas SET id_estacion_resuelta = 501 WHERE event_uuid = ?');
    $stmt->execute([$protectedUuid]);
} catch (PDOException $e) {
    $updateRejected = true;
}
try {
    $stmt = $pdo->prepare('DELETE FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = ?');
    $stmt->execute([$protectedUuid]);
} catch (PDOException $e) {
    $deleteRejected = true;
}
$afterImmutability = readEvent($pdo, $protectedUuid);
assertReal('raw event UPDATE rejected by trigger', $updateRejected);
assertReal('raw event DELETE rejected by trigger', $deleteRejected);
assertReal('immutability attempts leave persisted event and seal unchanged', $afterImmutability !== null && $beforeImmutability['integrity_seal'] === $afterImmutability['integrity_seal'] && $beforeImmutability['id_estacion_resuelta'] === $afterImmutability['id_estacion_resuelta']);

// I. Real Level 2 sidecar continuity for the five sequence-governed events.
$mysqli = new mysqli(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME'), (int)getenv('DB_PORT'));
if ($mysqli->connect_errno) {
    throw new RuntimeException('Could not connect to isolated MariaDB for Level 2 verification.');
}
$l2 = new Level2ServerService();
$acceptedThrough = $l2->calcularAcceptedThrough($mysqli, $devices[1]['id'], 'synthetic-generation-1');
$sidecarCount = (int)$pdo->query('SELECT COUNT(*) FROM op_rh_v2_marcaciones_nivel2_metadata WHERE id_dispositivo = ' . $devices[1]['id'] . ' AND device_generation = "synthetic-generation-1"')->fetchColumn();
$mysqli->close();
assertReal('Level 2 sequences persisted and accepted-through is contiguous', $sidecarCount === 5 && $acceptedThrough === 5, "sidecars=$sidecarCount accepted_through=$acceptedThrough");

echo "HTTP_INTEGRATION_TESTS: {$integrationPassCount}/{$integrationCheckCount} PASS_REAL\n";
echo 'DATABASE_INTEGRATION_TESTS: ' . ($integrationFailures === [] ? 'PASS_REAL' : 'FAIL') . "\n";
echo "HMAC_CLIENT_SIGNATURE: NOT_APPLICABLE — contract uses device Bearer auth and server-side HMAC seal\n";
if ($integrationFailures !== []) {
    echo 'INTEGRATION_FAILURES: ' . json_encode($integrationFailures, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}
