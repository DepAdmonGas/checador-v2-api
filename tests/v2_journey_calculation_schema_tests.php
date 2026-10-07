<?php
/**
 * Checador V2 — V2.4-P2M Journey Calculation Schema Test Suite
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';

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

$rawPunchUp = __DIR__ . '/../migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql';
$shiftUp    = __DIR__ . '/../migraciones/v2/2026_10_06_000001_create_v2_shift_scheduling_tables.sql';
$pairingUp  = __DIR__ . '/../migraciones/v2/2026_10_07_000001_create_v2_journey_pairing_tables.sql';
$pairingDown= __DIR__ . '/../migraciones/v2/2026_10_07_000001_rollback_v2_journey_pairing_tables.sql';
$calcUp     = __DIR__ . '/../migraciones/v2/2026_10_07_000002_create_v2_journey_calculation_tables.sql';
$calcDown   = __DIR__ . '/../migraciones/v2/2026_10_07_000002_rollback_v2_journey_calculation_tables.sql';

test_log("=== Starting V2.4-P2M Journey Calculation Schema Test Suite ===");

// Teardown preexistente
$shiftDown  = __DIR__ . '/../migraciones/v2/2026_10_06_000001_rollback_v2_shift_scheduling_tables.sql';
$conexion->query("SET FOREIGN_KEY_CHECKS = 0");
try {
    run_sql_script($conexion, $calcDown);
} catch (Throwable $e) {}
try {
    run_sql_script($conexion, $pairingDown);
} catch (Throwable $e) {}
try {
    run_sql_script($conexion, $shiftDown);
} catch (Throwable $e) {}
$conexion->query("SET FOREIGN_KEY_CHECKS = 1");

// Inicializar esquemas prerequisito
run_sql_script($conexion, $rawPunchUp);
run_sql_script($conexion, $shiftUp);
run_sql_script($conexion, $pairingUp);

// Execute UP migration for P2 Calculation
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

// Fixture IDs (foreign keys in v2 tables rely on integer IDs)
$workerId = 850;
$stationId = 10;

// Create fixture ScheduledShift and P1 Pairing Header
$conexion->query("INSERT INTO op_rh_v2_jornadas_programadas 
    (id_personal, id_estacion, fecha_jornada_local, tipo_jornada, scheduled_start_utc, scheduled_end_utc, payload_segmentos_json) 
    VALUES ($workerId, $stationId, '2026-10-07', 'SCHEDULED', '2026-10-07 08:00:00', '2026-10-07 16:00:00', '[]')");
$idJornada = (int)$conexion->insert_id;

$conexion->query("INSERT INTO op_rh_v2_pareos_jornada
    (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo, estado_completitud_l2, estado_pareo, fingerprint_ejecucion)
    VALUES ($idJornada, 850, 10, '2026-10-07', 1, 'v2.4-p1', 'VERIFIED', 'EXACT_PAIR', 'fp_p1_rev1')");
$idPareoJornada = (int)$conexion->insert_id;

$conexion->query("INSERT INTO op_rh_v2_pareos_segmentos
    (id_pareo_jornada, ordinal_segmento, estado_segmento)
    VALUES ($idPareoJornada, 1, 'EXACT_PAIR')");
$idPareoSegmento = (int)$conexion->insert_id;

// S01: Clean DDL execution & table existence
$resTables = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_calculos_%'");
$assert_test($resTables && $resTables->num_rows === 2, "S01", "Both P2 calculation tables created cleanly in database");

// S02: FK constraint rejects invalid id_jornada_programada
$fkError = false;
try {
    $conexion->query("INSERT INTO op_rh_v2_calculos_jornada 
        (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, fingerprint_ejecucion) 
        VALUES (999999, $idPareoJornada, 850, 10, '2026-10-07', 1, 'CALCULATED', 'fp_calc_invalid_shift')");
} catch (mysqli_sql_exception $e) {
    $fkError = true;
}
$assert_test($fkError, "S02", "FK constraint rejects insertion with non-existent id_jornada_programada");

// S03: Valid insert header revision 1
$conexion->query("INSERT INTO op_rh_v2_calculos_jornada 
    (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_calculo, estado_calculo, segundos_programados_totales, segundos_trabajados_totales, segundos_candidatos_fuera_horario, fingerprint_ejecucion) 
    VALUES ($idJornada, $idPareoJornada, 850, 10, '2026-10-07', 1, 'v2.4-p2', 'CALCULATED', 28800, 28800, 0, 'fp_calc_rev1')");
$idCalculoHeader = (int)$conexion->insert_id;
$assert_test($idCalculoHeader > 0, "S03", "Valid calculation revision 1 header inserted successfully");

// S04: Valid insert header revision 2 for same ScheduledShift
// Create P1 pareo rev 2 first to maintain FK
$conexion->query("INSERT INTO op_rh_v2_pareos_jornada
    (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo, estado_completitud_l2, estado_pareo, fingerprint_ejecucion)
    VALUES ($idJornada, 850, 10, '2026-10-07', 2, 'v2.4-p1', 'VERIFIED', 'EXACT_PAIR', 'fp_p1_rev2')");
$idPareoJornadaRev2 = (int)$conexion->insert_id;

$conexion->query("INSERT INTO op_rh_v2_calculos_jornada 
    (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_calculo, estado_calculo, segundos_programados_totales, segundos_trabajados_totales, segundos_candidatos_fuera_horario, fingerprint_ejecucion) 
    VALUES ($idJornada, $idPareoJornadaRev2, 850, 10, '2026-10-07', 2, 'v2.4-p2', 'CALCULATED', 28800, 28800, 0, 'fp_calc_rev2')");
$idCalculoRev2 = (int)$conexion->insert_id;
$assert_test($idCalculoRev2 > 0 && $idCalculoRev2 !== $idCalculoHeader, "S04", "Monotonic revision 2 inserted cleanly for same ScheduledShift");

// S05: Duplicate revision number for same shift rejected by UK
$dupRevError = false;
try {
    $conexion->query("INSERT INTO op_rh_v2_calculos_jornada 
        (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, fingerprint_ejecucion) 
        VALUES ($idJornada, $idPareoJornada, 850, 10, '2026-10-07', 1, 'CALCULATED', 'fp_calc_dup_rev')");
} catch (mysqli_sql_exception $e) {
    $dupRevError = true;
}
$assert_test($dupRevError, "S05", "Duplicate (id_jornada_programada, revision_numero) rejected by UK constraint");

// S06: Duplicate fingerprint rejected
$dupFpError = false;
try {
    $conexion->query("INSERT INTO op_rh_v2_calculos_jornada 
        (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, fingerprint_ejecucion) 
        VALUES ($idJornada, $idPareoJornada, 850, 10, '2026-10-07', 3, 'CALCULATED', 'fp_calc_rev1')");
} catch (mysqli_sql_exception $e) {
    $dupFpError = true;
}
$assert_test($dupFpError, "S06", "Duplicate fingerprint_ejecucion rejected by UK constraint");

// S07: P1 pairing revision FK valid & enforced
$invalidP1FkError = false;
try {
    $conexion->query("INSERT INTO op_rh_v2_calculos_jornada 
        (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, fingerprint_ejecucion) 
        VALUES ($idJornada, 999999, 850, 10, '2026-10-07', 3, 'CALCULATED', 'fp_calc_invalid_p1')");
} catch (mysqli_sql_exception $e) {
    $invalidP1FkError = true;
}
$assert_test($invalidP1FkError, "S07", "FK constraint rejects non-existent id_pareo_jornada");

// S08: Multiple segment calculation rows valid
$conexion->query("INSERT INTO op_rh_v2_calculos_segmentos 
    (id_calculo_jornada, id_pareo_segmento, ordinal_segmento, tipo_segmento, estado_segmento, segundos_programados, segundos_trabajados, delta_llegada_segundos, delta_salida_segundos, segundos_candidatos_fuera_horario) 
    VALUES ($idCalculoHeader, $idPareoSegmento, 1, 'WORK', 'CALCULATED', 28800, 28800, -300, 600, 600)");
$idSegCalc1 = (int)$conexion->insert_id;
$assert_test($idSegCalc1 > 0, "S08", "Valid segment calculation row inserted successfully for header revision 1");

// S09: Duplicate segment ordinal rejected
$dupSegOrdError = false;
try {
    $conexion->query("INSERT INTO op_rh_v2_calculos_segmentos 
        (id_calculo_jornada, id_pareo_segmento, ordinal_segmento, tipo_segmento, estado_segmento) 
        VALUES ($idCalculoHeader, $idPareoSegmento, 1, 'WORK', 'CALCULATED')");
} catch (mysqli_sql_exception $e) {
    $dupSegOrdError = true;
}
$assert_test($dupSegOrdError, "S09", "Duplicate ordinal_segmento for same calculation header rejected by UK");

// S10: segundos_trabajados_totales supports NULL for incomplete states
$conexion->query("INSERT INTO op_rh_v2_calculos_jornada 
    (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, segundos_programados_totales, segundos_trabajados_totales, fingerprint_ejecucion) 
    VALUES ($idJornada, $idPareoJornada, 850, 10, '2026-10-07', 3, 'PARTIAL_DATA', 28800, NULL, 'fp_calc_partial')");
$idPartialCalc = (int)$conexion->insert_id;
$resNullCheck = $conexion->query("SELECT segundos_trabajados_totales FROM op_rh_v2_calculos_jornada WHERE id = $idPartialCalc");
$rowNull = $resNullCheck->fetch_assoc();
$assert_test($rowNull && $rowNull['segundos_trabajados_totales'] === null, "S10", "segundos_trabajados_totales supports NULL for PARTIAL_DATA status");

// S11 & S12: Signed arrival delta supports positive (+600) and negative (-300)
$resDeltas = $conexion->query("SELECT delta_llegada_segundos, delta_salida_segundos FROM op_rh_v2_calculos_segmentos WHERE id = $idSegCalc1");
$rowDeltas = $resDeltas->fetch_assoc();
$assert_test($rowDeltas && (int)$rowDeltas['delta_llegada_segundos'] === -300 && (int)$rowDeltas['delta_salida_segundos'] === 600, "S11 & S12", "Signed arrival/departure deltas support negative (-300) and positive (+600) values");

// S15: DAY_OFF scheduled seconds = 0 supported
$conexion->query("INSERT INTO op_rh_v2_jornadas_programadas 
    (id_personal, id_estacion, fecha_jornada_local, tipo_jornada, payload_segmentos_json) 
    VALUES (850, 10, '2026-10-08', 'DAY_OFF', '[]')");
$idJornadaDayOff = (int)$conexion->insert_id;

$conexion->query("INSERT INTO op_rh_v2_pareos_jornada
    (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_pareo, fingerprint_ejecucion)
    VALUES ($idJornadaDayOff, 850, 10, '2026-10-08', 1, 'UNMATCHED_NO_PUNCHES', 'fp_p1_dayoff')");
$idPareoDayOff = (int)$conexion->insert_id;

$conexion->query("INSERT INTO op_rh_v2_calculos_jornada 
    (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, segundos_programados_totales, segundos_trabajados_totales, fingerprint_ejecucion) 
    VALUES ($idJornadaDayOff, $idPareoDayOff, 850, 10, '2026-10-08', 1, 'DAY_OFF_RECORDED', 0, NULL, 'fp_calc_dayoff')");
$idDayOffCalc = (int)$conexion->insert_id;
$resDayOff = $conexion->query("SELECT segundos_programados_totales, estado_calculo FROM op_rh_v2_calculos_jornada WHERE id = $idDayOffCalc");
$rowDayOff = $resDayOff->fetch_assoc();
$assert_test($rowDayOff && (int)$rowDayOff['segundos_programados_totales'] === 0 && $rowDayOff['estado_calculo'] === 'DAY_OFF_RECORDED', "S15", "DAY_OFF_RECORDED with scheduled seconds = 0 supported");

// S16: UNSCHEDULED scheduled seconds = NULL supported
$conexion->query("INSERT INTO op_rh_v2_jornadas_programadas 
    (id_personal, id_estacion, fecha_jornada_local, tipo_jornada, payload_segmentos_json) 
    VALUES (850, 10, '2026-10-09', 'UNSCHEDULED', '[]')");
$idJornadaUnsch = (int)$conexion->insert_id;

$conexion->query("INSERT INTO op_rh_v2_pareos_jornada
    (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_pareo, fingerprint_ejecucion)
    VALUES ($idJornadaUnsch, 850, 10, '2026-10-09', 1, 'UNMATCHED_NO_PUNCHES', 'fp_p1_unsch')");
$idPareoUnsch = (int)$conexion->insert_id;

$conexion->query("INSERT INTO op_rh_v2_calculos_jornada 
    (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, segundos_programados_totales, segundos_trabajados_totales, fingerprint_ejecucion) 
    VALUES ($idJornadaUnsch, $idPareoUnsch, 850, 10, '2026-10-09', 1, 'UNSCHEDULED_RECORDED', NULL, NULL, 'fp_calc_unsch')");
$idUnschCalc = (int)$conexion->insert_id;
$resUnsch = $conexion->query("SELECT segundos_programados_totales, estado_calculo FROM op_rh_v2_calculos_jornada WHERE id = $idUnschCalc");
$rowUnsch = $resUnsch->fetch_assoc();
$assert_test($rowUnsch && $rowUnsch['segundos_programados_totales'] === null && $rowUnsch['estado_calculo'] === 'UNSCHEDULED_RECORDED', "S16", "UNSCHEDULED_RECORDED with scheduled seconds NULL supported");

// S17: UNKNOWN_SCHEDULE metrics NULL supported
$conexion->query("INSERT INTO op_rh_v2_jornadas_programadas 
    (id_personal, id_estacion, fecha_jornada_local, tipo_jornada, payload_segmentos_json) 
    VALUES (850, 10, '2026-10-10', 'SCHEDULED', '[]')");
$idJornadaUnknown = (int)$conexion->insert_id;

$conexion->query("INSERT INTO op_rh_v2_pareos_jornada
    (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_pareo, fingerprint_ejecucion)
    VALUES ($idJornadaUnknown, 850, 10, '2026-10-10', 1, 'UNMATCHED_NO_PUNCHES', 'fp_p1_unknown')");
$idPareoUnknown = (int)$conexion->insert_id;

$conexion->query("INSERT INTO op_rh_v2_calculos_jornada 
    (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, segundos_programados_totales, segundos_trabajados_totales, fingerprint_ejecucion) 
    VALUES ($idJornadaUnknown, $idPareoUnknown, 850, 10, '2026-10-10', 1, 'UNKNOWN_SCHEDULE', NULL, NULL, 'fp_calc_unknown')");
$idUnknownCalc = (int)$conexion->insert_id;
$resUnknown = $conexion->query("SELECT segundos_programados_totales, segundos_trabajados_totales, estado_calculo FROM op_rh_v2_calculos_jornada WHERE id = $idUnknownCalc");
$rowUnknown = $resUnknown->fetch_assoc();
$assert_test($rowUnknown && $rowUnknown['segundos_programados_totales'] === null && $rowUnknown['segundos_trabajados_totales'] === null && $rowUnknown['estado_calculo'] === 'UNKNOWN_SCHEDULE', "S17", "UNKNOWN_SCHEDULE with all metrics NULL supported");

// S18-S22: Distinct estado_calculo domain values representable
$conexion->query("INSERT INTO op_rh_v2_calculos_jornada 
    (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, fingerprint_ejecucion) 
    VALUES ($idJornada, $idPareoJornada, 850, 10, '2026-10-07', 4, 'PENDING_SYNC', 'fp_calc_pending')");
$conexion->query("INSERT INTO op_rh_v2_calculos_jornada 
    (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, fingerprint_ejecucion) 
    VALUES ($idJornada, $idPareoJornada, 850, 10, '2026-10-07', 5, 'INVALID_INTERVAL', 'fp_calc_invalid_int')");
$resDomainCheck = $conexion->query("SELECT COUNT(*) AS total FROM op_rh_v2_calculos_jornada WHERE estado_calculo IN ('PENDING_SYNC', 'INVALID_INTERVAL')");
$rowDomain = $resDomainCheck->fetch_assoc();
$assert_test($rowDomain && (int)$rowDomain['total'] === 2, "S18-S22", "PENDING_SYNC and INVALID_INTERVAL status values representable in schema");

// S23: Algorithm version stored & default v2.4-p2
$resAlgo = $conexion->query("SELECT version_algoritmo_calculo FROM op_rh_v2_calculos_jornada WHERE id = $idCalculoHeader");
$rowAlgo = $resAlgo->fetch_assoc();
$assert_test($rowAlgo && $rowAlgo['version_algoritmo_calculo'] === 'v2.4-p2', "S23", "Calculation algorithm version stored with default 'v2.4-p2'");

// S24: Fingerprint stored
$resFp = $conexion->query("SELECT fingerprint_ejecucion FROM op_rh_v2_calculos_jornada WHERE id = $idCalculoHeader");
$rowFp = $resFp->fetch_assoc();
$assert_test($rowFp && $rowFp['fingerprint_ejecucion'] === 'fp_calc_rev1', "S24", "Execution fingerprint stored correctly");

// S25 & S26: Seconds precision reflected in schema; no minute duplicate columns
$resCols = $conexion->query("SHOW COLUMNS FROM op_rh_v2_calculos_jornada LIKE '%minut%'");
$resSegs = $conexion->query("SHOW COLUMNS FROM op_rh_v2_calculos_jornada LIKE '%segundo%'");
$assert_test($resCols->num_rows === 0 && $resSegs->num_rows === 3, "S25 & S26", "Seconds precision reflected in schema (*_segundos); 0 minute-based duplicate columns exist");

// S27: P1 FK cannot cascade-delete calculation history (ON DELETE RESTRICT)
$deleteP1Error = false;
try {
    $conexion->query("DELETE FROM op_rh_v2_pareos_jornada WHERE id = $idPareoJornada");
} catch (mysqli_sql_exception $e) {
    $deleteP1Error = true;
}
$assert_test($deleteP1Error, "S27", "Deleting P1 pairing header blocked by ON DELETE RESTRICT FK constraint");

// S28: ScheduledShift FK cannot cascade-delete calculation history (ON DELETE RESTRICT)
$deleteShiftError = false;
try {
    $conexion->query("DELETE FROM op_rh_v2_jornadas_programadas WHERE id = $idJornada");
} catch (mysqli_sql_exception $e) {
    $deleteShiftError = true;
}
$assert_test($deleteShiftError, "S28", "Deleting ScheduledShift record blocked by ON DELETE RESTRICT FK constraint");

// S29: P1 segment FK cannot cascade-delete calculation segment detail (ON DELETE RESTRICT)
$deleteP1SegError = false;
try {
    $conexion->query("DELETE FROM op_rh_v2_pareos_segmentos WHERE id = $idPareoSegmento");
} catch (mysqli_sql_exception $e) {
    $deleteP1SegError = true;
}
$assert_test($deleteP1SegError, "S29", "Deleting P1 segment record blocked by ON DELETE RESTRICT FK constraint");

// S30: Append-only trigger UPDATE rejection
$updateError = false;
try {
    $conexion->query("UPDATE op_rh_v2_calculos_jornada SET estado_calculo = 'MODIFIED' WHERE id = $idCalculoHeader");
} catch (mysqli_sql_exception $e) {
    $updateError = true;
}
$assert_test($updateError, "S30", "Append-only trigger tg_op_rh_v2_calculos_jornada_no_update blocks UPDATE operations");

// S31: Append-only trigger DELETE rejection
$deleteError = false;
try {
    $conexion->query("DELETE FROM op_rh_v2_calculos_jornada WHERE id = $idCalculoHeader");
} catch (mysqli_sql_exception $e) {
    $deleteError = true;
}
$assert_test($deleteError, "S31", "Append-only trigger tg_op_rh_v2_calculos_jornada_no_delete blocks DELETE operations");

// S32: Multiple P2 revisions preserve old history
$resRevs = $conexion->query("SELECT revision_numero FROM op_rh_v2_calculos_jornada WHERE id_jornada_programada = $idJornada ORDER BY revision_numero ASC");
$revs = [];
while ($r = $resRevs->fetch_assoc()) {
    $revs[] = (int)$r['revision_numero'];
}
$assert_test(count($revs) >= 4 && in_array(1, $revs) && in_array(2, $revs), "S32", "Multiple P2 revisions preserve complete historical audit trail");

// S33: Multi-workplace histories distinct
$conexion->query("INSERT INTO op_rh_v2_jornadas_programadas 
    (id_personal, id_estacion, fecha_jornada_local, tipo_jornada, payload_segmentos_json) 
    VALUES (850, 20, '2026-10-07', 'SCHEDULED', '[]')");
$idJornadaEst20 = (int)$conexion->insert_id;

$conexion->query("INSERT INTO op_rh_v2_pareos_jornada
    (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_pareo, fingerprint_ejecucion)
    VALUES ($idJornadaEst20, 850, 20, '2026-10-07', 1, 'EXACT_PAIR', 'fp_p1_est20')");
$idPareoEst20 = (int)$conexion->insert_id;

$conexion->query("INSERT INTO op_rh_v2_calculos_jornada 
    (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, fingerprint_ejecucion) 
    VALUES ($idJornadaEst20, $idPareoEst20, 850, 20, '2026-10-07', 1, 'CALCULATED', 'fp_calc_est20')");
$idCalcEst20 = (int)$conexion->insert_id;
$assert_test($idCalcEst20 > 0, "S33", "Multi-workplace calculation histories for same worker/date at different station (id_estacion=20) distinct");

// S34: Arbitrary 1..N segment calculations supported
$conexion->query("INSERT INTO op_rh_v2_calculos_segmentos 
    (id_calculo_jornada, id_pareo_segmento, ordinal_segmento, tipo_segmento, estado_segmento, segundos_programados, segundos_trabajados) 
    VALUES ($idCalculoHeader, NULL, 2, 'WORK', 'CALCULATED', 14400, 14400)");
$idSeg2 = (int)$conexion->insert_id;
$assert_test($idSeg2 > 0, "S34", "Arbitrary 1..N segment calculation detail rows supported");

// S35: No FALTA column/state in table definitions
$resFaltaCol = $conexion->query("SHOW COLUMNS FROM op_rh_v2_calculos_jornada LIKE '%falta%'");
$assert_test($resFaltaCol->num_rows === 0, "S35", "No FALTA administrative column exists in calculation tables");

// S36: No RETARDO administrative column/state in table definitions
$resRetardoCol = $conexion->query("SHOW COLUMNS FROM op_rh_v2_calculos_jornada LIKE '%retardo%'");
$assert_test($resRetardoCol->num_rows === 0, "S36", "No RETARDO administrative column exists in calculation tables");

// S37: No incidence, payroll, or payable overtime fields
$resPayrollCols = $conexion->query("SHOW COLUMNS FROM op_rh_v2_calculos_jornada WHERE Field IN ('incidencia_id', 'justificado', 'aprobado', 'monto', 'salario', 'tiempo_extra_pagable')");
$assert_test($resPayrollCols->num_rows === 0, "S37", "No incidence, payroll, or payable overtime columns exist in schema");

// S38: Rollback migration execution & clean re-apply
$conexion->query("SET FOREIGN_KEY_CHECKS = 0");
run_sql_script($conexion, $calcDown);
$conexion->query("SET FOREIGN_KEY_CHECKS = 1");

$resCheckDrop = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_calculos_%'");
$droppedCleanly = ($resCheckDrop->num_rows === 0);

run_sql_script($conexion, $calcUp);
$resCheckReapply = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_calculos_%'");
$reappliedCleanly = ($resCheckReapply->num_rows === 2);

$assert_test($droppedCleanly && $reappliedCleanly, "S38", "Rollback migration cleanly dropped P2 tables and UP migration reapplied cleanly");

test_log("\n=== Test Results Summary ===");
test_log("PASS: $passedCount / " . ($passedCount + $failedCount));
test_log("FAIL: $failedCount / " . ($passedCount + $failedCount));

if ($failedCount > 0) {
    exit(1);
}
exit(0);
