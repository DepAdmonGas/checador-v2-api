<?php

// Focused HTTP/controller integration test. Run only on a fresh disposable DB.
if (PHP_SAPI === 'cli-server') {
    require_once __DIR__ . '/test_bootstrap_guard.php';
    require_once __DIR__ . '/../modelo/v2/IngestaPunchV2Controller.php';
    (new IngestaPunchV2Controller())->procesarIngesta();
    return;
}

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/Level2ServerService.php';
require_once __DIR__ . '/../modelo/v2/PunchEventV2.php';

mysqli_report(MYSQLI_REPORT_OFF);
putenv('RAW_PUNCH_INTEGRITY_MANDATORY=true');
putenv('RAW_PUNCH_ACTIVE_KEY_ID=l2-test-key');
putenv('RAW_PUNCH_KEY_RING=' . json_encode(['l2-test-key' => 'disposable-l2-test-secret']));

$dbName = 'bd_admongas_v2_test_20261002';
$bd = new BD();
$db = $bd->conectarBD();
$identity = $db->query('SELECT DATABASE() AS db_name')->fetch_assoc()['db_name'] ?? '';
if ($identity !== $dbName || $identity === 'bd_admongas') {
    throw new RuntimeException("Refusing DDL/DML: unexpected database identity '{$identity}'.");
}

function l2_test_assert(bool $condition, string $label): void {
    global $passed, $failed;
    if ($condition) {
        echo "PASS {$label}\n";
        $passed++;
    } else {
        echo "FAIL {$label}\n";
        $failed++;
    }
}

function l2_apply_migration(mysqli $db, string $path): void {
    $sql = file_get_contents($path);
    if (!is_string($sql) || !$db->multi_query($sql)) {
        throw new RuntimeException('Could not apply disposable migration: ' . basename($path));
    }
    do {
        if ($result = $db->store_result()) $result->free();
    } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException('Migration failed: ' . basename($path) . ': ' . $db->error);
}

function l2_seed_raw(mysqli $db, string $uuid, int $sequence, string $workerRef = 'EMP-L2-TEST', ?array $integrity = null): int {
    $now = gmdate('Y-m-d H:i:s');
    $zone = 'America/Mexico_City';
    $source = '2026-10-09 08:00:00';
    if ($integrity === null) {
        $service = new PunchEventV2();
        $result = $service->registrarPunchEvent($db, [
            'event_uuid' => $uuid,
            'id_dispositivo' => 9501,
            'id_estacion_resuelta' => 1,
            'referencia_externa_personal' => $workerRef,
            'id_personal_resuelto' => null,
            'source_timestamp_raw' => $source,
            'source_timezone_reported' => $zone,
            'effective_timezone' => $zone,
            'timezone_fallback_applied' => 0,
            'ocurrido_at_utc' => '2026-10-09 14:00:00',
            'recibido_at_utc' => $now,
            'metodo_captura' => 'BIOMETRIC',
            'external_event_id' => null,
            'secuencia_dispositivo' => $sequence,
            'processing_status' => 'PENDING_MATCH',
            'validation_flags' => [],
            'metadata' => []
        ]);
        if (($result['status_code'] ?? null) !== 201) throw new RuntimeException('Raw fixture insert failed: ' . json_encode($result));
        return (int)$result['id_insertado'];
    }

    $flags = '[]';
    $meta = '{}';
    $method = 'BIOMETRIC';
    $version = $integrity['version'];
    $keyId = $integrity['key_id'];
    $seal = $integrity['seal'];
    $stmt = $db->prepare('INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid,id_dispositivo,id_estacion_resuelta,referencia_externa_personal,id_personal_resuelto,source_timestamp_raw,source_timezone_reported,effective_timezone,timezone_fallback_applied,ocurrido_at_utc,recibido_at_utc,metodo_captura,external_event_id,secuencia_dispositivo,processing_status,validation_flags,metadata_json,integrity_version,integrity_key_id,integrity_seal) VALUES (?,9501,1,?,NULL,?,?,?,0,\'2026-10-09 14:00:00\',?,?,NULL,?,\'PENDING_MATCH\',?,?,?,?,?)');
    $stmt->bind_param('sssssssisssss', $uuid, $workerRef, $source, $zone, $zone, $now, $method, $sequence, $flags, $meta, $version, $keyId, $seal);
    if (!$stmt->execute()) throw new RuntimeException('Invalid-integrity raw fixture insert failed: ' . $stmt->error);
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

function l2_register_generation(mysqli $db, string $generation): void {
    $service = new Level2ServerService();
    $result = $service->registrarGeneracion($db, 9501, 'device-l2-test', $generation);
    if (empty($result['success'])) throw new RuntimeException('Generation registration failed: ' . json_encode($result));
}

function l2_count(mysqli $db, string $table, string $where, string $value): int {
    $stmt = $db->prepare("SELECT COUNT(*) AS n FROM {$table} WHERE {$where} = ?");
    $stmt->bind_param('s', $value);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n;
}

function l2_body(string $uuid, int $sequence, ?string $generation = null, string $workerRef = 'EMP-L2-TEST'): array {
    $body = [
        'event_uuid' => $uuid,
        'external_worker_reference' => $workerRef,
        'source_timestamp' => '2026-10-09 08:00:00',
        'source_timezone' => 'America/Mexico_City',
        'capture_method' => 'BIOMETRIC',
        'device_sequence' => $sequence,
        'metadata' => []
    ];
    if ($generation !== null) $body['device_generation'] = $generation;
    return $body;
}

function l2_http(string $url, array $body): array {
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Device-Id: device-l2-test',
            'X-Credential-Key: l2-test-credential',
            'Authorization: Bearer l2-test-secret'
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 20
    ]);
    $payload = curl_exec($handle);
    $code = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($payload === false) throw new RuntimeException('HTTP test request failed: ' . $error);
    return ['code' => $code, 'body' => json_decode($payload, true) ?: []];
}

function l2_assert_response(array $response, int $code, string $status, string $label): void {
    $matches = $response['code'] === $code && ($response['body']['status'] ?? null) === $status;
    l2_test_assert($matches, $matches ? $label : $label . ' — got ' . json_encode($response));
}

$passed = 0;
$failed = 0;
$server = null;
$triggerCreated = false;
$serverLog = tmpfile();

try {
    $migrationDir = __DIR__ . '/../migraciones/v2/';
    foreach ([
        '2026_09_28_000001_create_v2_raw_punch_tables.sql',
        '2026_10_02_000002_add_integrity_seal_to_raw_punches.sql',
        '2026_10_05_000002_create_v2_level2_server_tables.sql'
    ] as $migration) l2_apply_migration($db, $migrationDir . $migration);

    $hash = password_hash('l2-test-secret', PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO op_rh_v2_dispositivos (id,uuid_dispositivo,id_estacion,nombre,timezone,estado) VALUES (9501,\'device-l2-test\',1,\'L2 disposable test\',\'America/Mexico_City\',1)');
    if (!$stmt->execute()) throw new RuntimeException('Device fixture insert failed: ' . $stmt->error);
    $stmt->close();
    $stmt = $db->prepare('INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo,credential_key,secret_hash,valido_desde,estado,creado_at) VALUES (9501,\'l2-test-credential\',?,UTC_TIMESTAMP(),\'ACTIVE\',UTC_TIMESTAMP())');
    $stmt->bind_param('s', $hash);
    if (!$stmt->execute()) throw new RuntimeException('Credential fixture insert failed: ' . $stmt->error);
    $stmt->close();

    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$listener) throw new RuntimeException('Could not reserve local test port: ' . $errstr);
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $port = (int)substr(strrchr($address, ':'), 1);
    $environment = array_merge(getenv(), [
        'PHP_CLI_SERVER_WORKERS' => '4',
        'DB_HOST' => (string)getenv('DB_HOST'),
        'DB_USER' => (string)getenv('DB_USER'),
        'DB_PASS' => (string)getenv('DB_PASS'),
        'DB_NAME' => $dbName,
        'RAW_PUNCH_INTEGRITY_MANDATORY' => 'true',
        'RAW_PUNCH_ACTIVE_KEY_ID' => 'l2-test-key',
        'RAW_PUNCH_KEY_RING' => json_encode(['l2-test-key' => 'disposable-l2-test-secret'])
    ]);
    $server = proc_open([PHP_BINARY, '-d', 'mysqli.default_socket=' . (string)ini_get('mysqli.default_socket'), '-S', "127.0.0.1:{$port}", __FILE__], [0 => ['pipe', 'r'], 1 => $serverLog, 2 => $serverLog], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($server)) throw new RuntimeException('Could not launch local PHP HTTP test server.');
    $url = "http://127.0.0.1:{$port}/api/v2/punch-events";
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @fsockopen('127.0.0.1', $port, $probeErrno, $probeError, 0.2);
        if (is_resource($probe)) { fclose($probe); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) throw new RuntimeException('Local PHP HTTP test server did not become ready.');

    l2_register_generation($db, 'gen-main');
    $r01 = l2_http($url, l2_body('l2-r01-success', 1, 'gen-main'));
    l2_assert_response($r01, 201, 'ACCEPTED', 'R01 new raw plus sidecar returns 201 ACCEPTED');
    l2_test_assert(l2_count($db, 'op_rh_v2_marcaciones_crudas', 'event_uuid', 'l2-r01-success') === 1 && l2_count($db, 'op_rh_v2_marcaciones_nivel2_metadata', 'id_dispositivo', '9501') === 1, 'R01 raw and sidecar each exist once');

    $r02body = l2_body('l2-r02-refresh', 2, 'gen-not-yet-registered');
    $r02 = l2_http($url, $r02body);
    l2_assert_response($r02, 400, 'INVALID_GENERATION', 'R02 invalid generation propagates 400');
    l2_test_assert(l2_count($db, 'op_rh_v2_marcaciones_crudas', 'event_uuid', 'l2-r02-refresh') === 1, 'R02 raw remains exactly once after L2 400');
    l2_register_generation($db, 'gen-not-yet-registered');
    $r06 = l2_http($url, $r02body);
    l2_assert_response($r06, 200, 'ALREADY_RECEIVED', 'R06 exact retry re-enters L2 and succeeds with 200');
    l2_test_assert(l2_count($db, 'op_rh_v2_marcaciones_crudas', 'event_uuid', 'l2-r02-refresh') === 1 && l2_count($db, 'op_rh_v2_marcaciones_nivel2_metadata', 'id_raw_punch', (string)l2_raw_id($db, 'l2-r02-refresh')) === 1, 'R06 recovery creates one sidecar without second raw');

    $r07body = l2_body('l2-r07-still-fails', 3, 'gen-still-missing');
    $r07 = l2_http($url, $r07body);
    l2_assert_response($r07, 400, 'INVALID_GENERATION', 'R07 repeated exact retry propagates L2 failure, not 200');

    l2_register_generation($db, 'gen-collision');
    $competitorId = l2_seed_raw($db, 'l2-r03-competitor', 5);
    $l2Service = new Level2ServerService();
    $bound = $l2Service->procesarSecuenciaNivel2($db, $competitorId, 9501, 'gen-collision', 5);
    if (empty($bound['success'])) throw new RuntimeException('Could not seed collision sidecar.');
    $r03 = l2_http($url, l2_body('l2-r03-collision', 5, 'gen-collision'));
    l2_assert_response($r03, 409, 'SEQUENCE_COLLISION', 'R03 collision propagates exact 409 status');
    l2_test_assert(l2_count($db, 'op_rh_v2_marcaciones_crudas', 'event_uuid', 'l2-r03-collision') === 1, 'R03 conflicting raw remains exactly once');

    l2_register_generation($db, 'gen-mismatch');
    l2_register_generation($db, 'gen-mismatch-other');
    $mismatchId = l2_seed_raw($db, 'l2-r04-mismatch', 6);
    $stmt = $db->prepare('INSERT INTO op_rh_v2_marcaciones_nivel2_metadata (id_raw_punch,id_dispositivo,device_generation,device_sequence,es_governado_nivel2,creado_at_utc) VALUES (?,9501,\'gen-mismatch-other\',9,1,UTC_TIMESTAMP())');
    $stmt->bind_param('i', $mismatchId);
    if (!$stmt->execute()) throw new RuntimeException('Could not seed mismatch sidecar.');
    $stmt->close();
    $r04 = l2_http($url, l2_body('l2-r04-mismatch', 6, 'gen-mismatch'));
    l2_assert_response($r04, 409, 'SEQUENCE_MISMATCH', 'R04 mismatch remains distinct from collision');

    l2_register_generation($db, 'gen-failure');
    $db->query("CREATE TRIGGER tr_l2_test_forced_failure BEFORE INSERT ON op_rh_v2_marcaciones_nivel2_metadata FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='controlled disposable test failure'");
    $triggerCreated = true;
    $r05body = l2_body('l2-r05-recover', 20, 'gen-failure');
    $r05 = l2_http($url, $r05body);
    l2_assert_response($r05, 500, 'INTERNAL_ERROR', 'R05 L2 server failure propagates 500');
    l2_test_assert(l2_count($db, 'op_rh_v2_marcaciones_crudas', 'event_uuid', 'l2-r05-recover') === 1, 'R05 raw remains exactly once after L2 500');
    $db->query('DROP TRIGGER tr_l2_test_forced_failure');
    $triggerCreated = false;
    $r06b = l2_http($url, $r05body);
    l2_assert_response($r06b, 200, 'ALREADY_RECEIVED', 'R06 transient failure recovers via exact retry');

    l2_register_generation($db, 'gen-idempotency');
    $r08body = l2_body('l2-r08-conflict', 30, 'gen-idempotency');
    l2_assert_response(l2_http($url, $r08body), 201, 'ACCEPTED', 'R08 setup event accepted');
    $r08changed = l2_http($url, l2_body('l2-r08-conflict', 30, 'gen-idempotency', 'OTHER-WORKER'));
    l2_assert_response($r08changed, 409, 'IDEMPOTENCY_CONFLICT', 'R08 raw idempotency conflict prevents L2 success');

    l2_register_generation($db, 'gen-integrity');
    $integrityId = l2_seed_raw($db, 'l2-r09-invalid-integrity', 40, 'EMP-L2-TEST', ['version' => 'v1', 'key_id' => 'l2-test-key', 'seal' => str_repeat('0', 64)]);
    $r09 = l2_http($url, l2_body('l2-r09-invalid-integrity', 40, 'gen-integrity'));
    l2_test_assert($r09['code'] === 500 && ($r09['body']['status'] ?? null) !== 'ALREADY_RECEIVED' && l2_count($db, 'op_rh_v2_marcaciones_nivel2_metadata', 'id_raw_punch', (string)$integrityId) === 0, 'R09 integrity failure remains fail-closed and skips L2');

    $r10body = l2_body('l2-r10-generation-only', 50);
    unset($r10body['device_sequence']);
    $r10body['device_generation'] = 'gen-main';
    l2_test_assert(l2_http($url, $r10body)['code'] === 400 && l2_count($db, 'op_rh_v2_marcaciones_crudas', 'event_uuid', 'l2-r10-generation-only') === 0, 'R10 generation-only tuple rejected before raw persistence');
    $r11body = l2_body('l2-r11-sequence-only', 51);
    unset($r11body['device_generation']);
    l2_test_assert(l2_http($url, $r11body)['code'] === 400 && l2_count($db, 'op_rh_v2_marcaciones_crudas', 'event_uuid', 'l2-r11-sequence-only') === 0, 'R11 sequence-only tuple rejected before raw persistence');

    $r12 = l2_http($url, ['event_uuid' => 'l2-r12-level1', 'external_worker_reference' => 'EMP-L2-TEST', 'source_timestamp' => '2026-10-09 08:00:00', 'source_timezone' => 'America/Mexico_City']);
    l2_assert_response($r12, 201, 'ACCEPTED', 'R12 Level 1 request remains accepted');

    l2_register_generation($db, 'gen-race');
    $raceBody = l2_body('l2-r13-race', 1, 'gen-race');
    $db->query('CREATE TRIGGER tr_l2_test_race_delay BEFORE INSERT ON op_rh_v2_marcaciones_nivel2_metadata FOR EACH ROW DO SLEEP(1)');
    $triggerCreated = true;
    $multi = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < 2; $i++) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Device-Id: device-l2-test', 'X-Credential-Key: l2-test-credential', 'Authorization: Bearer l2-test-secret'], CURLOPT_POSTFIELDS => json_encode($raceBody), CURLOPT_TIMEOUT => 20]);
        curl_multi_add_handle($multi, $handle);
        $handles[] = $handle;
    }
    do { $multiStatus = curl_multi_exec($multi, $active); if ($active) curl_multi_select($multi, 1.0); } while ($active && $multiStatus === CURLM_OK);
    $raceCodes = [];
    foreach ($handles as $handle) { $raceCodes[] = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_multi_remove_handle($multi, $handle); curl_close($handle); }
    curl_multi_close($multi);
    $db->query('DROP TRIGGER tr_l2_test_race_delay');
    $triggerCreated = false;
    $raceSidecars = l2_count($db, 'op_rh_v2_marcaciones_nivel2_metadata', 'id_raw_punch', (string)l2_raw_id($db, 'l2-r13-race'));
    $acceptedThrough = $l2Service->calcularAcceptedThrough($db, 9501, 'gen-race');
    l2_test_assert(count(array_intersect($raceCodes, [200, 201])) === 2 && $raceSidecars === 1, 'R13 concurrent exact retries resolve without duplicate sidecar or false 500');
    l2_test_assert($acceptedThrough === 1, 'R13 acceptedThrough remains contiguous and advances once');

    l2_register_generation($db, 'gen-r14');
    l2_assert_response(l2_http($url, l2_body('l2-r14-first', 1, 'gen-r14')), 201, 'ACCEPTED', 'R14 first sequence claim accepted');
    $r14 = l2_http($url, l2_body('l2-r14-second', 1, 'gen-r14'));
    l2_assert_response($r14, 409, 'SEQUENCE_COLLISION', 'R14 different raw claiming same sequence remains a conflict');

    echo "\nL2_FALSE_SUCCESS_CONTROLLER: {$passed} PASS / {$failed} FAIL\n";
} finally {
    if ($triggerCreated) $db->query('DROP TRIGGER IF EXISTS tr_l2_test_forced_failure');
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($failed > 0 && is_resource($serverLog)) { rewind($serverLog); $log = stream_get_contents($serverLog); if ($log !== '') echo "\nHTTP_TEST_SERVER_LOG:\n{$log}\n"; }
    if (is_resource($serverLog)) fclose($serverLog);
    $bd->desconectarBD($db);
}

exit($failed === 0 ? 0 : 1);

function l2_raw_id(mysqli $db, string $uuid): int {
    $stmt = $db->prepare('SELECT id FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = ? LIMIT 1');
    $stmt->bind_param('s', $uuid);
    $stmt->execute();
    $id = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();
    return $id;
}
