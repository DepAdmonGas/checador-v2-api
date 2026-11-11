<?php
/**
 * Focused Level 2 telemetry coverage regressions on the guarded test database.
 * Uses the real Level2ServerService and real server receipt timestamps.
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
$level2ServiceFile = getenv('CHECADOR_LEVEL2_SERVICE_FILE') ?: __DIR__ . '/../modelo/v2/Level2ServerService.php';
require_once $level2ServiceFile;

function l2_telemetry_apply_sql(mysqli $db, string $path): void {
    $sql = file_get_contents($path);
    if ($sql === false || !$db->multi_query($sql)) {
        throw new RuntimeException('Could not apply test prerequisite SQL: ' . $path);
    }
    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
}

function l2_telemetry_assert(bool $condition, string $id, string $description, int &$pass, int &$fail): void {
    if ($condition) {
        echo "[$id] PASS: $description\n";
        $pass++;
    } else {
        echo "[$id] FAIL: $description\n";
        $fail++;
    }
}

function l2_telemetry_fixture(Level2ServerService $service, mysqli $db, int $stationId): array {
    $suffix = bin2hex(random_bytes(8));
    $uuid = 'L2_TELEMETRY_TEST_' . $suffix;
    $generation = 'GEN_' . $suffix;
    $stmt = $db->prepare('INSERT INTO op_rh_v2_dispositivos (uuid_dispositivo, id_estacion, nombre, estado) VALUES (?, ?, ?, 1)');
    $name = 'Isolated Level 2 telemetry test device';
    $stmt->bind_param('sis', $uuid, $stationId, $name);
    $stmt->execute();
    $deviceId = (int)$stmt->insert_id;
    $stmt->close();

    $registered = $service->registrarGeneracion($db, $deviceId, $uuid, $generation);
    if (empty($registered['success'])) {
        throw new RuntimeException('Could not register isolated telemetry test generation.');
    }
    $assigned = $service->registrarAsignacionEstacion($db, $deviceId, $stationId, '2020-01-01 00:00:00');
    if (empty($assigned['success'])) {
        throw new RuntimeException('Could not assign isolated telemetry test device.');
    }
    return ['device_id' => $deviceId, 'generation' => $generation];
}

function l2_telemetry_submit(Level2ServerService $service, mysqli $db, array $fixture, int $sequence, string $observedAt, array $payload, ?string $clockStatus = 'SYNCED', ?int $battery = 100): array {
    return $service->registrarTelemetria(
        $db,
        $fixture['device_id'],
        $fixture['generation'],
        $sequence,
        $observedAt,
        $battery,
        'CONNECTED',
        $clockStatus,
        $payload
    );
}

function l2_telemetry_evaluate_health_case(Level2ServerService $service, mysqli $db, array $times, array $payloads, array $clockStatuses = ['SYNCED', 'SYNCED', 'SYNCED']): array {
    $stationId = random_int(1000000, 1900000000);
    $fixture = l2_telemetry_fixture($service, $db, $stationId);
    foreach (array_values($payloads) as $index => $payload) {
        l2_telemetry_submit(
            $service,
            $db,
            $fixture,
            $index + 1,
            $times[$index],
            $payload,
            $clockStatuses[$index] ?? null
        );
    }
    return $service->evaluarVentanaNivel2($db, $stationId, $times[0], $times[count($times) - 1]);
}

$pass = 0;
$fail = 0;
$bd = new BD();
$db = $bd->conectarBD();

try {
    l2_telemetry_apply_sql($db, __DIR__ . '/../migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql');
    l2_telemetry_apply_sql($db, __DIR__ . '/../migraciones/v2/2026_10_05_000002_create_v2_level2_server_tables.sql');
    $service = new Level2ServerService();

    $now = time();
    $windowFrom = gmdate('Y-m-d H:i:s', $now - 60);
    $middle = gmdate('Y-m-d H:i:s', $now - 30);
    $windowTo = gmdate('Y-m-d H:i:s', $now - 3);
    $healthy = [
        'application_running' => true,
        'sdk_initialized' => true,
        'sensor_connected' => true,
        'capture_thread_alive' => true,
        'queue_writable' => true
    ];

    $positiveStation = random_int(1000000, 1900000000);
    $positive = l2_telemetry_fixture($service, $db, $positiveStation);
    l2_telemetry_submit($service, $db, $positive, 1, $windowFrom, $healthy);
    l2_telemetry_submit($service, $db, $positive, 2, $middle, $healthy);
    l2_telemetry_submit($service, $db, $positive, 3, $windowTo, $healthy);
    $positiveResult = $service->evaluarVentanaNivel2($db, $positiveStation, $windowFrom, $windowTo);
    $persisted = $db->prepare('SELECT window_level2_status FROM op_rh_v2_level2_window_evaluations WHERE evaluation_ref = ? LIMIT 1');
    $persisted->bind_param('s', $positiveResult['evaluation_ref']);
    $persisted->execute();
    $persistedStatus = $persisted->get_result()->fetch_assoc()['window_level2_status'] ?? null;
    $persisted->close();
    l2_telemetry_assert(
        $positiveResult['window_level2_status'] === 'VERIFIED'
            && $positiveResult['operational_coverage'] === 'VERIFIED'
            && $positiveResult['delivery_completeness'] === 'VERIFIED'
            && $positiveResult['time_window_attribution'] === 'VERIFIED'
            && $persistedStatus === 'VERIFIED',
        'L2T01',
        'Healthy closed interval with contiguous telemetry is evaluated and persisted as VERIFIED',
        $pass,
        $fail
    );

    $duplicateBefore = $db->prepare('SELECT server_received_at_utc FROM op_rh_v2_dispositivo_telemetria WHERE id_dispositivo = ? AND device_generation = ? AND telemetry_sequence = 2 LIMIT 1');
    $duplicateBefore->bind_param('is', $positive['device_id'], $positive['generation']);
    $duplicateBefore->execute();
    $originalReceipt = $duplicateBefore->get_result()->fetch_assoc()['server_received_at_utc'] ?? null;
    $duplicateBefore->close();
    $duplicateResult = l2_telemetry_submit($service, $db, $positive, 2, $middle, $healthy);
    $collisionResult = l2_telemetry_submit($service, $db, $positive, 2, $middle, $healthy, 'SYNCED', 99);
    $duplicateAfter = $db->prepare('SELECT COUNT(*) AS total, MAX(server_received_at_utc) AS received_at FROM op_rh_v2_dispositivo_telemetria WHERE id_dispositivo = ? AND device_generation = ? AND telemetry_sequence = 2');
    $duplicateAfter->bind_param('is', $positive['device_id'], $positive['generation']);
    $duplicateAfter->execute();
    $duplicateRow = $duplicateAfter->get_result()->fetch_assoc();
    $duplicateAfter->close();
    l2_telemetry_assert(
        $duplicateResult['code'] === 200
            && $duplicateResult['status'] === 'ALREADY_RECEIVED'
            && $collisionResult['code'] === 409
            && $collisionResult['status'] === 'SEQUENCE_COLLISION'
            && (int)$duplicateRow['total'] === 1
            && $duplicateRow['received_at'] === $originalReceipt,
        'L2T02',
        'Exact retries preserve the original sample; conflicting reuse of a sequence is rejected',
        $pass,
        $fail
    );

    $missingStation = random_int(1000000, 1900000000);
    $missing = l2_telemetry_fixture($service, $db, $missingStation);
    $missingResult = $service->evaluarVentanaNivel2($db, $missingStation, $windowFrom, $windowTo);
    l2_telemetry_assert(
        $missingResult['window_level2_status'] === 'UNKNOWN'
            && $missingResult['operational_coverage'] === 'UNKNOWN'
            && $missingResult['time_window_attribution'] === 'UNKNOWN',
        'L2T03',
        'Missing telemetry remains UNKNOWN',
        $pass,
        $fail
    );

    $healthMissingStation = random_int(1000000, 1900000000);
    $healthMissing = l2_telemetry_fixture($service, $db, $healthMissingStation);
    l2_telemetry_submit($service, $db, $healthMissing, 1, $windowFrom, []);
    l2_telemetry_submit($service, $db, $healthMissing, 2, $windowTo, []);
    $healthMissingResult = $service->evaluarVentanaNivel2($db, $healthMissingStation, $windowFrom, $windowTo);
    l2_telemetry_assert(
        $healthMissingResult['window_level2_status'] === 'UNKNOWN'
            && $healthMissingResult['operational_coverage'] === 'UNKNOWN',
        'L2T04',
        'Telemetry without all five frozen health dimensions cannot verify operational coverage',
        $pass,
        $fail
    );

    $unhealthyStation = random_int(1000000, 1900000000);
    $unhealthy = l2_telemetry_fixture($service, $db, $unhealthyStation);
    $unhealthyPayload = $healthy;
    $unhealthyPayload['sensor_connected'] = false;
    l2_telemetry_submit($service, $db, $unhealthy, 1, $windowFrom, $healthy);
    l2_telemetry_submit($service, $db, $unhealthy, 2, $middle, $unhealthyPayload);
    l2_telemetry_submit($service, $db, $unhealthy, 3, $windowTo, $healthy);
    $unhealthyResult = $service->evaluarVentanaNivel2($db, $unhealthyStation, $windowFrom, $windowTo);
    l2_telemetry_assert(
        $unhealthyResult['window_level2_status'] === 'INCOMPLETE'
            && $unhealthyResult['operational_coverage'] === 'INCOMPLETE',
        'L2T05',
        'A known unhealthy sensor sample makes operational coverage INCOMPLETE',
        $pass,
        $fail
    );

    $sequenceGapStation = random_int(1000000, 1900000000);
    $sequenceGap = l2_telemetry_fixture($service, $db, $sequenceGapStation);
    l2_telemetry_submit($service, $db, $sequenceGap, 1, $windowFrom, $healthy);
    l2_telemetry_submit($service, $db, $sequenceGap, 3, $windowTo, $healthy);
    $sequenceGapResult = $service->evaluarVentanaNivel2($db, $sequenceGapStation, $windowFrom, $windowTo);
    l2_telemetry_assert(
        $sequenceGapResult['window_level2_status'] === 'INCOMPLETE'
            && $sequenceGapResult['operational_coverage'] === 'INCOMPLETE',
        'L2T06',
        'A missing telemetry sequence makes operational coverage INCOMPLETE',
        $pass,
        $fail
    );

    $clockUnknownStation = random_int(1000000, 1900000000);
    $clockUnknown = l2_telemetry_fixture($service, $db, $clockUnknownStation);
    l2_telemetry_submit($service, $db, $clockUnknown, 1, $windowFrom, $healthy, null);
    l2_telemetry_submit($service, $db, $clockUnknown, 2, $windowTo, $healthy, null);
    $clockUnknownResult = $service->evaluarVentanaNivel2($db, $clockUnknownStation, $windowFrom, $windowTo);
    l2_telemetry_assert(
        $clockUnknownResult['window_level2_status'] === 'UNKNOWN'
            && $clockUnknownResult['time_window_attribution'] === 'UNKNOWN',
        'L2T07',
        'Missing client clock quality cannot produce VERIFIED time attribution',
        $pass,
        $fail
    );

    $invalidSequence = $service->registrarTelemetria($db, $positive['device_id'], $positive['generation'], 0, $windowTo, 100, 'CONNECTED', 'SYNCED', $healthy);
    $invalidGeneration = $service->registrarTelemetria($db, $positive['device_id'], 'UNKNOWN_GENERATION', 4, $windowTo, 100, 'CONNECTED', 'SYNCED', $healthy);
    l2_telemetry_assert(
        $invalidSequence['code'] === 400 && $invalidSequence['status'] === 'BAD_REQUEST'
            && $invalidGeneration['code'] === 400 && $invalidGeneration['status'] === 'INVALID_GENERATION',
        'L2T08',
        'Zero sequence and unknown device generation are rejected',
        $pass,
        $fail
    );

    $openResult = $service->evaluarVentanaNivel2(
        $db,
        $positiveStation,
        $windowFrom,
        gmdate('Y-m-d H:i:s', time() + 60)
    );
    l2_telemetry_assert(
        $openResult['window_level2_status'] === 'UNKNOWN'
            && ($openResult['reason'] ?? '') === 'VENTANA_AUN_ABIERTA',
        'L2T09',
        'A future window is not credited before it closes',
        $pass,
        $fail
    );

    $outsideStation = random_int(1000000, 1900000000);
    $outside = l2_telemetry_fixture($service, $db, $outsideStation);
    l2_telemetry_submit($service, $db, $outside, 1, $windowFrom, $healthy);
    l2_telemetry_submit($service, $db, $outside, 2, $windowTo, $healthy);
    $outsideFrom = gmdate('Y-m-d H:i:s', $now - 20);
    $outsideTo = gmdate('Y-m-d H:i:s', $now - 10);
    $outsideResult = $service->evaluarVentanaNivel2($db, $outsideStation, $outsideFrom, $outsideTo);
    l2_telemetry_assert(
        $outsideResult['window_level2_status'] === 'UNKNOWN'
            && $outsideResult['operational_coverage'] === 'UNKNOWN',
        'L2T10',
        'Samples outside the requested interval do not establish its coverage',
        $pass,
        $fail
    );

    $driftStation = random_int(1000000, 1900000000);
    $drift = l2_telemetry_fixture($service, $db, $driftStation);
    $driftFrom = gmdate('Y-m-d H:i:s', $now - 600);
    l2_telemetry_submit($service, $db, $drift, 1, $driftFrom, $healthy);
    l2_telemetry_submit($service, $db, $drift, 2, $windowTo, $healthy);
    $driftResult = $service->evaluarVentanaNivel2($db, $driftStation, $driftFrom, $windowTo);
    l2_telemetry_assert(
        $driftResult['window_level2_status'] === 'UNKNOWN'
            && $driftResult['time_window_attribution'] === 'DEGRADED',
        'L2T11',
        'Client/server time difference above the active policy cannot verify attribution',
        $pass,
        $fail
    );

    $healthSignalNames = [
        'application_running',
        'sdk_initialized',
        'sensor_connected',
        'capture_thread_alive',
        'queue_writable'
    ];
    $allSignalsFailClosed = true;
    foreach ($healthSignalNames as $signalName) {
        $unhealthyPayload = $healthy;
        $unhealthyPayload[$signalName] = false;
        $unhealthyResult = l2_telemetry_evaluate_health_case(
            $service,
            $db,
            [$windowFrom, $middle, $windowTo],
            [$healthy, $unhealthyPayload, $healthy]
        );
        $allSignalsFailClosed = $allSignalsFailClosed
            && $unhealthyResult['window_level2_status'] === 'INCOMPLETE'
            && $unhealthyResult['operational_coverage'] === 'INCOMPLETE';
    }
    l2_telemetry_assert(
        $allSignalsFailClosed,
        'L2T12',
        'Each of the five canonical health signals independently fails closed when persisted false',
        $pass,
        $fail
    );

    $sensorAndThreadDown = $healthy;
    $sensorAndThreadDown['sensor_connected'] = false;
    $sensorAndThreadDown['capture_thread_alive'] = false;
    $sensorThreadResult = l2_telemetry_evaluate_health_case(
        $service,
        $db,
        [$windowFrom, $middle, $windowTo],
        [$healthy, $sensorAndThreadDown, $healthy]
    );
    l2_telemetry_assert(
        $sensorThreadResult['window_level2_status'] === 'INCOMPLETE'
            && $sensorThreadResult['operational_coverage'] === 'INCOMPLETE',
        'L2T13',
        'A disconnected sensor and inactive capture thread cannot produce VERIFIED coverage',
        $pass,
        $fail
    );

    $wireAliasOnly = $healthy;
    unset($wireAliasOnly['capture_thread_alive']);
    $wireAliasOnly['capture_worker_alive'] = true;
    $aliasResult = l2_telemetry_evaluate_health_case(
        $service,
        $db,
        [$windowFrom, $middle, $windowTo],
        [$wireAliasOnly, $wireAliasOnly, $wireAliasOnly]
    );
    l2_telemetry_assert(
        $aliasResult['window_level2_status'] === 'UNKNOWN'
            && $aliasResult['operational_coverage'] === 'UNKNOWN',
        'L2T14',
        'Legacy capture_worker_alive does not substitute for frozen V1.1 capture_thread_alive',
        $pass,
        $fail
    );

    $invalidBoolean = $healthy;
    $invalidBoolean['capture_thread_alive'] = 1;
    $invalidBooleanResult = l2_telemetry_evaluate_health_case(
        $service,
        $db,
        [$windowFrom, $middle, $windowTo],
        [$healthy, $invalidBoolean, $healthy]
    );
    l2_telemetry_assert(
        $invalidBooleanResult['window_level2_status'] === 'UNKNOWN'
            && $invalidBooleanResult['operational_coverage'] === 'UNKNOWN',
        'L2T15',
        'Non-boolean health evidence is insufficient and cannot be coerced to healthy',
        $pass,
        $fail
    );

    $unsyncedResult = l2_telemetry_evaluate_health_case(
        $service,
        $db,
        [$windowFrom, $middle, $windowTo],
        [$healthy, $healthy, $healthy],
        ['SYNCED', 'UNSYNCED', 'SYNCED']
    );
    l2_telemetry_assert(
        $unsyncedResult['window_level2_status'] === 'UNKNOWN'
            && $unsyncedResult['time_window_attribution'] === 'DEGRADED',
        'L2T16',
        'An explicitly unsynchronized clock remains degraded even when timestamps are close',
        $pass,
        $fail
    );

    $invalidWindow = $service->evaluarVentanaNivel2($db, $positiveStation, 'not-a-date', $windowTo);
    $reversedWindow = $service->evaluarVentanaNivel2($db, $positiveStation, $windowTo, $windowFrom);
    l2_telemetry_assert(
        $invalidWindow['window_level2_status'] === 'UNKNOWN'
            && ($invalidWindow['reason'] ?? '') === 'VENTANA_UTC_INVALIDA'
            && $reversedWindow['window_level2_status'] === 'UNKNOWN'
            && ($reversedWindow['reason'] ?? '') === 'VENTANA_UTC_INVALIDA',
        'L2T17',
        'Malformed and reversed windows fail closed; only closed valid windows can be evaluated',
        $pass,
        $fail
    );

    $heartbeatGapStation = random_int(1000000, 1900000000);
    $heartbeatGap = l2_telemetry_fixture($service, $db, $heartbeatGapStation);
    $gapFrom = gmdate('Y-m-d H:i:s', time() - 2000);
    $gapMiddle = gmdate('Y-m-d H:i:s', time() - 1000);
    $gapTo = gmdate('Y-m-d H:i:s', time() - 200);
    l2_telemetry_submit($service, $db, $heartbeatGap, 1, $gapFrom, $healthy);
    l2_telemetry_submit($service, $db, $heartbeatGap, 2, $gapMiddle, $healthy);
    l2_telemetry_submit($service, $db, $heartbeatGap, 3, $gapTo, $healthy);
    $heartbeatGapResult = $service->evaluarVentanaNivel2($db, $heartbeatGapStation, $gapFrom, $gapTo);
    l2_telemetry_assert(
        $heartbeatGapResult['window_level2_status'] === 'INCOMPLETE'
            && $heartbeatGapResult['operational_coverage'] === 'INCOMPLETE',
        'L2T18',
        'A persisted heartbeat interval exceeding the active max gap policy is INCOMPLETE',
        $pass,
        $fail
    );
} catch (Throwable $e) {
    echo '[ ERROR ] ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    if (isset($db) && $db instanceof mysqli) {
        $bd->desconectarBD($db);
    }
}

echo "PASS: $pass / " . ($pass + $fail) . "\n";
echo "FAIL: $fail / " . ($pass + $fail) . "\n";
exit($fail > 0 ? 1 : 0);
