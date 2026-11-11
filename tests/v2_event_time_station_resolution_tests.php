<?php
/**
 * Focused read-only unit coverage for event-time device-to-station resolution.
 */

require_once __DIR__ . '/../modelo/v2/Level2ServerService.php';
require_once __DIR__ . '/../modelo/v2/PunchEventV2.php';
require_once __DIR__ . '/../modelo/v2/PunchEventIntegrityService.php';

final class StationResolutionFakeResult {
    public int $num_rows;
    private array $rows;

    public function __construct(array $rows) {
        $this->rows = array_values($rows);
        $this->num_rows = count($this->rows);
    }

    public function fetch_assoc(): ?array {
        return array_shift($this->rows) ?: null;
    }
}

final class StationResolutionFakeStatement {
    private StationResolutionFakeConnection $connection;
    private string $sql;
    private array $params = [];
    private ?StationResolutionFakeResult $result = null;

    public function __construct(StationResolutionFakeConnection $connection, string $sql) {
        $this->connection = $connection;
        $this->sql = $sql;
    }

    public function bind_param(string $types, &...$params): bool {
        $this->params = [];
        foreach ($params as &$param) {
            $this->params[] =& $param;
        }
        return true;
    }

    public function execute(): bool {
        $this->result = $this->connection->executeSelect($this->sql, $this->params);
        return $this->result !== null;
    }

    public function get_result(): ?StationResolutionFakeResult {
        return $this->result;
    }

    public function close(): void {}
}

final class StationResolutionFakeConnection {
    public array $history;
    public array $stations;
    public bool $failHistoryQuery = false;
    public bool $failStationQuery = false;

    public function __construct(array $history, array $stations) {
        $this->history = $history;
        $this->stations = $stations;
    }

    public function prepare(string $sql): ?StationResolutionFakeStatement {
        if (str_contains($sql, 'op_rh_v2_dispositivo_estacion_historial') && $this->failHistoryQuery) {
            return null;
        }
        if (str_contains($sql, 'tb_estaciones') && $this->failStationQuery) {
            return null;
        }
        return new StationResolutionFakeStatement($this, $sql);
    }

    public function executeSelect(string $sql, array $params): ?StationResolutionFakeResult {
        if (str_contains($sql, 'op_rh_v2_dispositivo_estacion_historial')) {
            [$deviceId, $atStart, $atEnd] = $params;
            $rows = array_values(array_filter($this->history, static function (array $row) use ($deviceId, $atStart, $atEnd): bool {
                return (int)$row['id_dispositivo'] === (int)$deviceId
                    && $row['valido_desde_utc'] <= $atStart
                    && ($row['valido_hasta_utc'] === null || $row['valido_hasta_utc'] > $atEnd);
            }));
            usort($rows, static fn(array $a, array $b): int => [$b['valido_desde_utc'], $b['id']] <=> [$a['valido_desde_utc'], $a['id']]);
            return new StationResolutionFakeResult(array_slice(array_map(
                static fn(array $row): array => ['id_estacion' => $row['id_estacion']],
                $rows
            ), 0, 2));
        }

        if (str_contains($sql, 'tb_estaciones')) {
            $id = (int)$params[0];
            return new StationResolutionFakeResult(in_array($id, $this->stations, true) ? [['id' => $id]] : []);
        }

        return null;
    }
}

function assertStationResolution(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$tests = 0;
$failures = 0;
$service = new Level2ServerService();
$at = '2026-10-20 12:00:00';
$rows = [
    ['id' => 1, 'id_dispositivo' => 7, 'id_estacion' => 10, 'valido_desde_utc' => '2026-10-01 00:00:00', 'valido_hasta_utc' => '2026-10-20 12:00:00'],
    ['id' => 2, 'id_dispositivo' => 7, 'id_estacion' => 11, 'valido_desde_utc' => '2026-10-20 12:00:00', 'valido_hasta_utc' => null],
];

$cases = [
    'exact interval start is inclusive' => [new StationResolutionFakeConnection($rows, [10, 11]), 7, $at, true, 'RESOLVED', 11],
    'interval end is exclusive' => [new StationResolutionFakeConnection($rows, [10, 11]), 7, '2026-10-20 11:59:59', true, 'RESOLVED', 10],
    'open interval resolves' => [new StationResolutionFakeConnection($rows, [10, 11]), 7, '2026-11-01 00:00:00', true, 'RESOLVED', 11],
    'before first interval fails closed' => [new StationResolutionFakeConnection($rows, [10, 11]), 7, '2026-09-30 23:59:59', false, 'HISTORY_NOT_FOUND', null],
    'after last closed interval fails closed' => [new StationResolutionFakeConnection([$rows[0]], [10]), 7, '2026-10-20 12:00:00', false, 'HISTORY_NOT_FOUND', null],
    'overlapping intervals fail closed' => [new StationResolutionFakeConnection([['id' => 1, 'id_dispositivo' => 7, 'id_estacion' => 10, 'valido_desde_utc' => '2026-10-01 00:00:00', 'valido_hasta_utc' => null], ['id' => 3, 'id_dispositivo' => 7, 'id_estacion' => 12, 'valido_desde_utc' => '2026-10-19 00:00:00', 'valido_hasta_utc' => null]], [10, 12]), 7, $at, false, 'HISTORY_AMBIGUOUS', null],
    'unknown station fails closed' => [new StationResolutionFakeConnection([$rows[1]], []), 7, $at, false, 'INVALID_STATION', null],
    'invalid device fails closed' => [new StationResolutionFakeConnection($rows, [10, 11]), 0, $at, false, 'INVALID_EVENT_TIME', null],
    'missing event time fails closed' => [new StationResolutionFakeConnection($rows, [10, 11]), 7, '', false, 'INVALID_EVENT_TIME', null],
];

foreach ($cases as $name => [$connection, $deviceId, $eventTime, $expectedSuccess, $expectedStatus, $expectedStation]) {
    try {
        $result = $service->resolverEstacionHistorica($connection, $deviceId, $eventTime);
        assertStationResolution(($result['success'] ?? false) === $expectedSuccess, "$name: success mismatch");
        assertStationResolution(($result['status'] ?? null) === $expectedStatus, "$name: status mismatch");
        assertStationResolution(($result['id_estacion'] ?? null) === $expectedStation, "$name: station mismatch");
        echo "[ PASS ] $name\n";
        $tests++;
    } catch (Throwable $e) {
        echo "[ FAIL ] $name: {$e->getMessage()}\n";
        $failures++;
    }
}

$failedHistory = new StationResolutionFakeConnection($rows, [10, 11]);
$failedHistory->failHistoryQuery = true;
$historyFailure = $service->resolverEstacionHistorica($failedHistory, 7, $at);
assertStationResolution(($historyFailure['status'] ?? null) === 'HISTORY_QUERY_FAILED', 'history query failure must fail closed');
echo "[ PASS ] history query failure fails closed\n";
$tests++;

$failedStation = new StationResolutionFakeConnection([$rows[1]], [11]);
$failedStation->failStationQuery = true;
$stationFailure = $service->resolverEstacionHistorica($failedStation, 7, $at);
assertStationResolution(($stationFailure['status'] ?? null) === 'STATION_QUERY_FAILED', 'station catalog query failure must fail closed');
echo "[ PASS ] station catalog query failure fails closed\n";
$tests++;

putenv('RAW_PUNCH_KEY_RING');
$retryIncoming = [
    'event_uuid' => 'station-replay-test',
    'id_dispositivo' => 7,
    'id_estacion_resuelta' => 11,
    'referencia_externa_personal' => 'worker-ref',
    'id_personal_resuelto' => null,
    'source_timestamp_raw' => '2026-10-20 12:00:00',
    'source_timezone_reported' => 'UTC',
    'effective_timezone' => 'UTC',
    'timezone_fallback_applied' => 0,
    'ocurrido_at_utc' => $at,
    'recibido_at_utc' => '2026-10-20 12:00:01',
    'metodo_captura' => 'BIOMETRIC',
    'external_event_id' => 'source-event-1',
    'secuencia_dispositivo' => null,
    'processing_status' => 'RECEIVED',
    'validation_flags' => [],
    'metadata' => ['firmware' => 'test'],
];
$existingRow = $retryIncoming;
$existingRow['id_estacion_resuelta'] = 10;
$existingRow['recibido_at_utc'] = '2026-10-20 12:00:01';
$existingRow['metadata_json'] = json_encode($retryIncoming['metadata']);
$retryMethod = new ReflectionMethod(PunchEventV2::class, 'evaluarReintentoIdempotente');
$retryMethod->setAccessible(true);
$retryResult = $retryMethod->invoke(new PunchEventV2(), $retryIncoming, $existingRow);
assertStationResolution(($retryResult['status_code'] ?? null) === 200 && ($retryResult['result'] ?? null) === 'ALREADY_RECEIVED', 'identical client retry remains idempotent after historical station reassignment');
echo "[ PASS ] identical retry remains idempotent after historical station reassignment\n";
$tests++;

$sealInput = $retryIncoming;
$sealInput['metadata_json'] = $retryIncoming['metadata'];
$sealed = PunchEventIntegrityService::seal($sealInput, 'test-key', 'local-test-secret');
$sealedRow = array_merge($retryIncoming, [
    'metadata_json' => json_encode($retryIncoming['metadata']),
    'integrity_version' => $sealed['integrity_version'],
    'integrity_key_id' => $sealed['integrity_key_id'],
    'integrity_seal' => $sealed['integrity_seal'],
]);
assertStationResolution(PunchEventIntegrityService::verify($sealedRow, ['test-key' => 'local-test-secret']) === PunchEventIntegrityService::RESULT_VALID, 'HMAC-SHA256 envelope remains verifiable with resolved station');
echo "[ PASS ] HMAC-SHA256 envelope verification preserved\n";
$tests++;

$controllerSource = file_get_contents(__DIR__ . '/../modelo/v2/IngestaPunchV2Controller.php');
$resolveAt = strpos($controllerSource, 'resolverEstacionHistorica($conexion, $idDispositivo, $ocurridoAtUtc)');
$persistAt = strpos($controllerSource, '$servicePunch->registrarPunchEvent($conexion, $datosIngesta)');
assertStationResolution(str_contains($controllerSource, '$idEstacionResuelta = null;'), 'ingestion must default to no station');
assertStationResolution(!str_contains($controllerSource, '$authResult[\'id_estacion\']'), 'ingestion must not fall back to the current device station');
assertStationResolution($resolveAt !== false && $persistAt !== false && $resolveAt < $persistAt, 'historical station resolution must precede persistence');
echo "[ PASS ] ingestion wires event-time resolution before persistence with no current-station fallback\n";
$tests++;

echo "Result: {$tests} PASS, {$failures} FAIL\n";
exit($failures === 0 ? 0 : 1);
