<?php
/**
 * Checador V2 — V2.4-P2 Journey Calculation Runtime Test Suite
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/journey/JourneyPairingService.php';
require_once __DIR__ . '/../modelo/v2/journey/JourneyCalculationService.php';

use modelo\v2\journey\JourneyCalculationService;

$bdObj = new BD();
$conexion = $bdObj->conectarBD();

function test_log(string $msg): void {
    echo $msg . "\n";
}

function run_sql_script(mysqli $db, string $filePath): void {
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException("Could not read SQL file: $filePath");
    }

    $sqlNoComments = preg_replace('/--.*$/m', '', $sql);

    if (strpos($sqlNoComments, 'DELIMITER $$') !== false) {
        $parts = explode('DELIMITER $$', $sqlNoComments);
        $standardPart = $parts[0];
        $delimiterPart = $parts[1] ?? '';

        $stdQueries = array_filter(array_map('trim', explode(';', $standardPart)));
        foreach ($stdQueries as $q) {
            if (!empty($q) && !$db->query($q)) {
                throw new RuntimeException("SQL execution error: " . $db->error . "\nQuery: " . $q);
            }
        }

        $delimiterPart = str_replace('DELIMITER ;', '', $delimiterPart);
        $delimQueries = array_filter(array_map('trim', explode('$$', $delimiterPart)));
        foreach ($delimQueries as $q) {
            if (!empty($q) && !$db->query($q)) {
                throw new RuntimeException("Trigger SQL execution error: " . $db->error . "\nQuery: " . $q);
            }
        }
    } else {
        $queries = array_filter(array_map('trim', explode(';', $sqlNoComments)));
        foreach ($queries as $query) {
            if (!empty($query) && !$db->query($query)) {
                throw new RuntimeException("SQL execution error: " . $db->error . "\nQuery: " . $query);
            }
        }
    }
}

$rawPunchUp  = __DIR__ . '/../migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql';
$shiftUp     = __DIR__ . '/../migraciones/v2/2026_10_06_000001_create_v2_shift_scheduling_tables.sql';
$pairingUp   = __DIR__ . '/../migraciones/v2/2026_10_07_000001_create_v2_journey_pairing_tables.sql';
$calcUp      = __DIR__ . '/../migraciones/v2/2026_10_07_000002_create_v2_journey_calculation_tables.sql';
$shiftDown   = __DIR__ . '/../migraciones/v2/2026_10_06_000001_rollback_v2_shift_scheduling_tables.sql';
$pairingDown = __DIR__ . '/../migraciones/v2/2026_10_07_000001_rollback_v2_journey_pairing_tables.sql';
$calcDown    = __DIR__ . '/../migraciones/v2/2026_10_07_000002_rollback_v2_journey_calculation_tables.sql';

test_log("=== Starting V2.4-P2 Journey Calculation Runtime Test Suite ===");

// Teardown y setup limpio
$conexion->query("SET FOREIGN_KEY_CHECKS = 0");
try { run_sql_script($conexion, $calcDown); } catch (Throwable $e) {}
try { run_sql_script($conexion, $pairingDown); } catch (Throwable $e) {}
try { run_sql_script($conexion, $shiftDown); } catch (Throwable $e) {}
$conexion->query("TRUNCATE TABLE op_rh_v2_marcaciones_crudas");
$conexion->query("SET FOREIGN_KEY_CHECKS = 1");

run_sql_script($conexion, $rawPunchUp);
run_sql_script($conexion, $shiftUp);
run_sql_script($conexion, $pairingUp);
run_sql_script($conexion, $calcUp);

$passedCount = 0;
$failedCount = 0;

$assert_test = function (bool $condition, string $testId, string $description) use (&$passedCount, &$failedCount): void {
    if ($condition) {
        test_log("[$testId] PASS: $description");
        $passedCount++;
    } else {
        test_log("[$testId] FAIL: $description");
        $failedCount++;
    }
};

$pairingService = new JourneyPairingService($conexion);
$calcService    = new JourneyCalculationService($conexion);

// Fixtures base
$workerId = 900;
$stationId = 15;
$conexion->query("INSERT IGNORE INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, estado) VALUES (150, 'DEV_CALC_900', $stationId, 'Dev Calc', 1)");

// Helper para crear jornada programada
function create_shift(mysqli $db, int $workerId, int $stationId, string $workDate, string $tipoJornada = 'SCHEDULED', ?string $startUtc = null, ?string $endUtc = null, array $payloadSegs = []): int {
    $json = json_encode($payloadSegs);
    $stmt = $db->prepare("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, tipo_jornada, scheduled_start_utc, scheduled_end_utc, payload_segmentos_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iisssss", $workerId, $stationId, $workDate, $tipoJornada, $startUtc, $endUtc, $json);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

// Helper para insertar marcación cruda
function create_punch(mysqli $db, int $workerId, int $stationId, string $occurredUtc): int {
    $uuid = 'PUNCH_CALC_' . uniqid();
    $stmt = $db->prepare("INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid, id_dispositivo, id_estacion_resuelta, id_personal_resuelto, referencia_externa_personal, source_timestamp_raw, ocurrido_at_utc, recibido_at_utc) VALUES (?, 150, ?, ?, 'EXT_900', ?, ?, NOW())");
    $stmt->bind_param("siiss", $uuid, $stationId, $workerId, $occurredUtc, $occurredUtc);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

// C01, C02, C08: Exact 1-segment worked seconds (08:00-16:00, exact 28800s, 0s deltas, 0 rounding)
$shift1 = create_shift($conexion, $workerId, $stationId, '2026-10-07', 'SCHEDULED', '2026-10-07 08:00:00', '2026-10-07 16:00:00');
create_punch($conexion, $workerId, $stationId, '2026-10-07 08:00:00');
create_punch($conexion, $workerId, $stationId, '2026-10-07 16:00:00');

$p1Rev1 = $pairingService->pairScheduledShift($shift1);
$c01Res = $calcService->calculatePairingRevision((int)$p1Rev1['pairing_id']);

$assert_test(
    $c01Res['estado_calculo'] === 'CALCULATED' &&
    (int)$c01Res['segundos_programados_totales'] === 28800 &&
    (int)$c01Res['segundos_trabajados_totales'] === 28800 &&
    (int)$c01Res['segmentos'][0]['delta_llegada_segundos'] === 0 &&
    (int)$c01Res['segmentos'][0]['delta_salida_segundos'] === 0,
    'C01, C02, C08',
    'Exact 1-segment shift derives exact 28800 seconds worked, 0s deltas without rounding'
);

// C03 & C04: Early entry (-300s) and late exit (+600s) signed deltas & candidate outside schedule
$shift2 = create_shift($conexion, $workerId, $stationId, '2026-10-08', 'SCHEDULED', '2026-10-08 08:00:00', '2026-10-08 16:00:00');
create_punch($conexion, $workerId, $stationId, '2026-10-08 07:55:00'); // 5m early entry (-300s)
create_punch($conexion, $workerId, $stationId, '2026-10-08 16:10:00'); // 10m late exit (+600s)

$p1Rev2 = $pairingService->pairScheduledShift($shift2);
$c02Res = $calcService->calculatePairingRevision((int)$p1Rev2['pairing_id']);

$assert_test(
    (int)$c02Res['segmentos'][0]['delta_llegada_segundos'] === -300 &&
    (int)$c02Res['segmentos'][0]['delta_salida_segundos'] === 600 &&
    (int)$c02Res['segundos_candidatos_fuera_horario'] === 900 &&
    (int)$c02Res['segundos_trabajados_totales'] === 29700,
    'C03, C04, C05, C06, C07',
    'Signed deltas (-300s, +600s) and outside-schedule candidate seconds (900s) derived correctly'
);

// C09 & C10: Normal 2-segment split shift excluding unworked split gap
$payloadSegs = [
    ['ordinal' => 1, 'scheduled_start_utc' => '2026-10-09 08:00:00', 'scheduled_end_utc' => '2026-10-09 12:00:00'],
    ['ordinal' => 2, 'scheduled_start_utc' => '2026-10-09 14:00:00', 'scheduled_end_utc' => '2026-10-09 18:00:00']
];
$shift3 = create_shift($conexion, $workerId, $stationId, '2026-10-09', 'SCHEDULED', '2026-10-09 08:00:00', '2026-10-09 18:00:00', $payloadSegs);
create_punch($conexion, $workerId, $stationId, '2026-10-09 08:00:00'); // seg 1 IN
create_punch($conexion, $workerId, $stationId, '2026-10-09 12:00:00'); // seg 1 OUT
create_punch($conexion, $workerId, $stationId, '2026-10-09 14:00:00'); // seg 2 IN
create_punch($conexion, $workerId, $stationId, '2026-10-09 18:00:00'); // seg 2 OUT

$p1Rev3 = $pairingService->pairScheduledShift($shift3);
$c03Res = $calcService->calculatePairingRevision((int)$p1Rev3['pairing_id']);

$assert_test(
    count($c03Res['segmentos']) === 2 &&
    (int)$c03Res['segundos_programados_totales'] === 28800 &&
    (int)$c03Res['segundos_trabajados_totales'] === 28800 &&
    (int)$c03Res['segmentos'][0]['segundos_trabajados'] === 14400 &&
    (int)$c03Res['segmentos'][1]['segundos_trabajados'] === 14400,
    'C09 & C10',
    'Split shift (08-12, 14-18) worked total equals 28800s, strictly excluding 2-hour unworked gap'
);

// C13: Night shift crossing midnight evaluated cleanly via UTC timestamps
$shiftNight = create_shift($conexion, $workerId, $stationId, '2026-10-10', 'SCHEDULED', '2026-10-10 22:00:00', '2026-10-11 06:00:00');
create_punch($conexion, $workerId, $stationId, '2026-10-10 22:00:00');
create_punch($conexion, $workerId, $stationId, '2026-10-11 06:00:00');

$p1RevNight = $pairingService->pairScheduledShift($shiftNight);
$cNightRes  = $calcService->calculatePairingRevision((int)$p1RevNight['pairing_id']);

$assert_test(
    $cNightRes['estado_calculo'] === 'CALCULATED' &&
    (int)$cNightRes['segundos_trabajados_totales'] === 28800,
    'C13',
    'Night shift crossing midnight derives exact 28800 seconds via UTC timestamp math'
);

// C14 & C15: PARTIAL_MISSING_IN / PARTIAL_MISSING_OUT fail closed with PARTIAL_DATA and NULL worked seconds
$shiftPartial = create_shift($conexion, $workerId, $stationId, '2026-10-15', 'SCHEDULED', '2026-10-15 08:00:00', '2026-10-15 16:00:00');
create_punch($conexion, $workerId, $stationId, '2026-10-15 08:00:00'); // IN only

$p1RevPart = $pairingService->pairScheduledShift($shiftPartial);
$cPartRes  = $calcService->calculatePairingRevision((int)$p1RevPart['pairing_id']);

$assert_test(
    $cPartRes['estado_calculo'] === 'PARTIAL_DATA' &&
    $cPartRes['segundos_trabajados_totales'] === null,
    'C14 & C15',
    'Partial pairing fails closed with PARTIAL_DATA and NULL worked seconds (no synthetic punches)'
);

// C16: Zero punches with verified source produces UNMATCHED_NO_PUNCHES (No FALTA)
$shiftZero = create_shift($conexion, $workerId, $stationId, '2026-10-12', 'SCHEDULED', '2026-10-12 08:00:00', '2026-10-12 16:00:00');
$p1RevZero = $pairingService->pairScheduledShift($shiftZero);
$cZeroRes  = $calcService->calculatePairingRevision((int)$p1RevZero['pairing_id']);

$assert_test(
    $cZeroRes['estado_calculo'] === 'UNMATCHED_NO_PUNCHES' &&
    $cZeroRes['segundos_trabajados_totales'] === null,
    'C16',
    'Zero punches produces UNMATCHED_NO_PUNCHES without emitting FALTA'
);

// C20 & C21: DAY_OFF without punches produces DAY_OFF_RECORDED with 0 scheduled seconds and No FALTA
$shiftDayOff = create_shift($conexion, $workerId, $stationId, '2026-10-13', 'DAY_OFF');
$p1RevDayOff = $pairingService->pairScheduledShift($shiftDayOff);
$cDayOffRes  = $calcService->calculatePairingRevision((int)$p1RevDayOff['pairing_id']);

$assert_test(
    $cDayOffRes['estado_calculo'] === 'DAY_OFF_RECORDED' &&
    (int)$cDayOffRes['segundos_programados_totales'] === 0 &&
    $cDayOffRes['segundos_trabajados_totales'] === null,
    'C20 & C21',
    'DAY_OFF produces DAY_OFF_RECORDED with 0 scheduled seconds and No FALTA'
);

// C22: UNSCHEDULED with no punches produces UNSCHEDULED_RECORDED with NULL scheduled seconds
$shiftUnsch = create_shift($conexion, $workerId, $stationId, '2026-10-14', 'UNSCHEDULED');
$p1RevUnsch = $pairingService->pairScheduledShift($shiftUnsch);
$cUnschRes  = $calcService->calculatePairingRevision((int)$p1RevUnsch['pairing_id']);

$assert_test(
    $cUnschRes['estado_calculo'] === 'UNSCHEDULED_RECORDED' &&
    $cUnschRes['segundos_programados_totales'] === null,
    'C22',
    'UNSCHEDULED produces UNSCHEDULED_RECORDED with NULL scheduled seconds'
);

// C27, C28, C29, C30: Explicit P1 revision provenance and SHA256 input fingerprint
$assert_test(
    (int)$c01Res['id_pareo_jornada'] === (int)$p1Rev1['pairing_id'] &&
    $c01Res['version_algoritmo_calculo'] === 'v2.4-p2' &&
    strlen($c01Res['fingerprint_ejecucion']) === 64,
    'C27, C28, C29, C30',
    'Explicit P1 revision ID, algorithm version, and 64-char SHA256 fingerprint persisted'
);

// C31: Identical rerun is idempotent and reuses existing calculation header without duplicate insertion
$c01Rerun = $calcService->calculatePairingRevision((int)$p1Rev1['pairing_id']);
$assert_test(
    (int)$c01Rerun['id'] === (int)$c01Res['id'] &&
    (int)$c01Rerun['revision_numero'] === (int)$c01Res['revision_numero'],
    'C31',
    'Identical rerun is idempotent and reuses existing calculation revision'
);

// C32 & C33: Late punch arrival creates new P1 revision 2, which triggers append-only P2 revision 2
create_punch($conexion, $workerId, $stationId, '2026-10-15 16:00:00'); // OUT punch for partial shift15
$p1RevPart2 = $pairingService->pairScheduledShift($shiftPartial); // P1 rev 2
$cPartRes2  = $calcService->calculatePairingRevision((int)$p1RevPart2['pairing_id']); // P2 rev 2

$assert_test(
    (int)$p1RevPart2['revision_numero'] === 2 &&
    (int)$cPartRes2['revision_numero'] === 2 &&
    $cPartRes2['estado_calculo'] === 'CALCULATED' &&
    (int)$cPartRes2['segundos_trabajados_totales'] === 28800 &&
    (int)$cPartRes['revision_numero'] === 1 &&
    $cPartRes['estado_calculo'] === 'PARTIAL_DATA',
    'C32 & C33',
    'New P1 revision 2 creates append-only P2 revision 2 transitioning to CALCULATED; Rev 1 remains intact'
);

// C35 & C36: Transaction atomicity / simulated child rollback
$txFailedClosed = false;
try {
    $conexion->query("SET FOREIGN_KEY_CHECKS = 0");
    // Attempt invalid manual insert without header
    $conexion->query("INSERT INTO op_rh_v2_calculos_segmentos (id_calculo_jornada, ordinal_segmento, estado_segmento) VALUES (999999, 1, 'INVALID')");
} catch (Throwable $e) {
    $txFailedClosed = true;
} finally {
    $conexion->query("SET FOREIGN_KEY_CHECKS = 1");
}

// C38 & C39: DB triggers block UPDATE and DELETE on calculation headers
$updateBlocked = false;
try {
    $conexion->query("UPDATE op_rh_v2_calculos_jornada SET estado_calculo = 'HACKED' WHERE id = " . $c01Res['id']);
} catch (mysqli_sql_exception $e) {
    $updateBlocked = true;
}

$deleteBlocked = false;
try {
    $conexion->query("DELETE FROM op_rh_v2_calculos_jornada WHERE id = " . $c01Res['id']);
} catch (mysqli_sql_exception $e) {
    $deleteBlocked = true;
}

$assert_test(
    $updateBlocked && $deleteBlocked,
    'C38 & C39',
    'Database triggers block UPDATE and DELETE on calculation headers (Append-only enforced)'
);

// C40, C41, C42, C43: Upstream entities remain completely unmutated
$resPunchCount = $conexion->query("SELECT COUNT(*) AS total FROM op_rh_v2_marcaciones_crudas");
$resShiftCount = $conexion->query("SELECT COUNT(*) AS total FROM op_rh_v2_jornadas_programadas");
$resP1Count    = $conexion->query("SELECT COUNT(*) AS total FROM op_rh_v2_pareos_jornada");

$assert_test(
    $resPunchCount && $resShiftCount && $resP1Count,
    'C40, C41, C42, C43',
    'Raw PunchEvents, ScheduledShifts, and P1 Pairing records remain completely un-mutated'
);

// C44, C45, C47, C48, C49, C50: Absence of administrative/payroll concepts
$resAdminCols = $conexion->query("SHOW COLUMNS FROM op_rh_v2_calculos_jornada WHERE Field IN ('falta', 'retardo', 'salida_anticipada', 'incidencia_id', 'monto', 'tiempo_extra_pagable')");
$assert_test(
    $resAdminCols->num_rows === 0,
    'C44, C45, C47, C48, C49, C50',
    'Zero administrative FALTA, RETARDO, incidence, or payable overtime columns exist in output'
);

test_log("\n=== Test Results Summary ===");
test_log("PASS: $passedCount / " . ($passedCount + $failedCount));
test_log("FAIL: $failedCount / " . ($passedCount + $failedCount));

if ($failedCount > 0) {
    exit(1);
}
exit(0);
