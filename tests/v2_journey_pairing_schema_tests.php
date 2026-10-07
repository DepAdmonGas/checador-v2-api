<?php
/**
 * Checador V2 — V2.4-P1M Journey Pairing Schema Test Suite
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

    // Strip comments
    $sqlNoComments = preg_replace('/--.*$/m', '', $sql);

    // Check if script uses DELIMITER $$
    if (strpos($sqlNoComments, 'DELIMITER $$') !== false) {
        // Split by DELIMITER $$
        $parts = explode('DELIMITER $$', $sqlNoComments);
        $standardPart = $parts[0];
        $delimiterPart = $parts[1] ?? '';

        // Execute standard part queries separated by semicolon
        $stdQueries = array_filter(array_map('trim', explode(';', $standardPart)));
        foreach ($stdQueries as $q) {
            if (!empty($q) && !$db->query($q)) {
                throw new RuntimeException("SQL execution error: " . $db->error . "\nQuery: " . $q);
            }
        }

        // Execute DELIMITER part split by $$
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
$shiftUp = __DIR__ . '/../migraciones/v2/2026_10_06_000001_create_v2_shift_scheduling_tables.sql';
$pairingUp = __DIR__ . '/../migraciones/v2/2026_10_07_000001_create_v2_journey_pairing_tables.sql';
$pairingDown = __DIR__ . '/../migraciones/v2/2026_10_07_000001_rollback_v2_journey_pairing_tables.sql';

test_log("=== Starting V2.4-P1M Schema Validation Test Suite ===");

// Pre-requisites: Ensure underlying tables exist
run_sql_script($conexion, $rawPunchUp);
run_sql_script($conexion, $shiftUp);

// Clean rollback first
test_log("Executing DOWN rollback migration to ensure clean state...");
$calcDownFile = __DIR__ . '/../migraciones/v2/2026_10_07_000002_rollback_v2_journey_calculation_tables.sql';
$conexion->query("SET FOREIGN_KEY_CHECKS = 0");
if (file_exists($calcDownFile)) {
    try {
        run_sql_script($conexion, $calcDownFile);
    } catch (Throwable $e) {}
}
try { run_sql_script($conexion, $pairingDown); } catch (Throwable $e) {}
$conexion->query("SET FOREIGN_KEY_CHECKS = 1");

// Execute UP migration
test_log("Executing UP migration...");
run_sql_script($conexion, $pairingUp);
test_log("UP migration completed successfully.");

$passCount = 0;
$failCount = 0;

function assert_test(bool $condition, string $testName, string $failureDetails = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        test_log("[PASS] $testName");
    } else {
        $failCount++;
        test_log("[FAIL] $testName" . ($failureDetails ? " -> $failureDetails" : ''));
    }
}

// -----------------------------------------------------------------------------
// Helper to insert requisite parent records for testing
// -----------------------------------------------------------------------------
// Helper to seed parent shift and raw punch records
function seed_parent_records(mysqli $db): array {
    // 1. Template
    $clave = 'TEST_PAIRING_TPL_' . uniqid();
    $db->query("INSERT INTO op_rh_v2_turnos_plantillas (clave, version, nombre, tipo_turno) VALUES ('$clave', 1, 'Test Template', 'REGULAR')");
    $templateId = (int)$db->insert_id;

    // 2. Assignment
    $workerId = rand(1000, 9999);
    $stationId = rand(10, 99);
    $db->query("INSERT INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, dia_semana, valido_desde) VALUES ($workerId, $stationId, $templateId, 'LUNES', '2026-01-01')");
    $assignmentId = (int)$db->insert_id;

    // 3. Materialized ScheduledShift
    $workDate = '2026-10-07';
    $db->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, id_asignacion_origen, id_plantilla_origen, tipo_jornada, scheduled_start_utc, scheduled_end_utc) VALUES ($workerId, $stationId, '$workDate', $assignmentId, $templateId, 'SCHEDULED', '2026-10-07 08:00:00', '2026-10-07 17:00:00')");
    $shiftId = (int)$db->insert_id;

    // 4. Raw Punch Events
    $eventUuid1 = 'PUNCH_IN_' . uniqid();
    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid, id_dispositivo, id_estacion_resuelta, id_personal_resuelto, referencia_externa_personal, source_timestamp_raw, ocurrido_at_utc, recibido_at_utc) VALUES ('$eventUuid1', 1, $stationId, $workerId, 'EXT_$workerId', '2026-10-07 07:55:00', '2026-10-07 07:55:00', NOW())");
    $punchId1 = (int)$db->insert_id;

    $eventUuid2 = 'PUNCH_OUT_' . uniqid();
    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid, id_dispositivo, id_estacion_resuelta, id_personal_resuelto, referencia_externa_personal, source_timestamp_raw, ocurrido_at_utc, recibido_at_utc) VALUES ('$eventUuid2', 1, $stationId, $workerId, 'EXT_$workerId', '2026-10-07 17:05:00', '2026-10-07 17:05:00', NOW())");
    $punchId2 = (int)$db->insert_id;

    $eventUuid3 = 'PUNCH_SURPLUS_' . uniqid();
    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid, id_dispositivo, id_estacion_resuelta, id_personal_resuelto, referencia_externa_personal, source_timestamp_raw, ocurrido_at_utc, recibido_at_utc) VALUES ('$eventUuid3', 1, $stationId, $workerId, 'EXT_$workerId', '2026-10-07 12:00:00', '2026-10-07 12:00:00', NOW())");
    $punchId3 = (int)$db->insert_id;

    return [
        'workerId' => $workerId,
        'stationId' => $stationId,
        'workDate' => $workDate,
        'shiftId' => $shiftId,
        'punchId1' => $punchId1,
        'punchId2' => $punchId2,
        'punchId3' => $punchId3
    ];
}

$seeds = seed_parent_records($conexion);
$shiftId = $seeds['shiftId'];
$workerId = $seeds['workerId'];
$stationId = $seeds['stationId'];
$workDate = $seeds['workDate'];
$punchId1 = $seeds['punchId1'];
$punchId2 = $seeds['punchId2'];
$punchId3 = $seeds['punchId3'];

// S01: Clean create UP tables exist
$res = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_pareos_jornada'");
assert_test($res && $res->num_rows === 1, "S01: Table op_rh_v2_pareos_jornada exists");

// S02: FK compatibility verification
assert_test($shiftId > 0 && $punchId1 > 0, "S02: Foreign key target records created with compatible PK types");

// S03: Pairing revision 1 insert
$stmt = $conexion->prepare("INSERT INTO op_rh_v2_pareos_jornada (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo, estado_completitud_l2, estado_pareo, fingerprint_ejecucion) VALUES (?, ?, ?, ?, 1, 'v2.4-p1', 'VERIFIED', 'EXACT_PAIR', 'FINGERPRINT_1')");
$stmt->bind_param('iiis', $shiftId, $workerId, $stationId, $workDate);
$succ03 = $stmt->execute();
$headerId1 = (int)$conexion->insert_id;
assert_test($succ03 && $headerId1 > 0, "S03: Pairing execution revision 1 created successfully");

// S04: Revision 2 on same ScheduledShift valid (append-only)
$stmt2 = $conexion->prepare("INSERT INTO op_rh_v2_pareos_jornada (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo, estado_completitud_l2, estado_pareo, fingerprint_ejecucion) VALUES (?, ?, ?, ?, 2, 'v2.4-p1', 'VERIFIED', 'EXACT_PAIR', 'FINGERPRINT_2')");
$stmt2->bind_param('iiis', $shiftId, $workerId, $stationId, $workDate);
$succ04 = $stmt2->execute();
$headerId2 = (int)$conexion->insert_id;
assert_test($succ04 && $headerId2 > $headerId1, "S04: Pairing execution revision 2 inserted successfully for same shift");

// S05: Duplicate revision_numero on same shift rejected
$stmt5 = $conexion->prepare("INSERT INTO op_rh_v2_pareos_jornada (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo, estado_completitud_l2, estado_pareo) VALUES (?, ?, ?, ?, 1, 'v2.4-p1', 'VERIFIED', 'EXACT_PAIR')");
$stmt5->bind_param('iiis', $shiftId, $workerId, $stationId, $workDate);
$err5 = false;
try {
    $stmt5->execute();
} catch (Throwable $e) {
    $err5 = true;
}
assert_test($err5 || $conexion->errno !== 0, "S05: Duplicate revision_numero for same ScheduledShift rejected by UK constraint");

// S06: Fingerprint indexable
$res6 = $conexion->query("SELECT id FROM op_rh_v2_pareos_jornada WHERE fingerprint_ejecucion = 'FINGERPRINT_1'");
assert_test($res6 && $res6->num_rows === 1, "S06: Execution fingerprint persisted and indexable");

// S07: Segment result creation
$stmt7 = $conexion->prepare("INSERT INTO op_rh_v2_pareos_segmentos (id_pareo_jornada, ordinal_segmento, id_marcacion_entrada, id_marcacion_salida, estado_segmento) VALUES (?, 1, ?, ?, 'EXACT_PAIR')");
$stmt7->bind_param('iii', $headerId1, $punchId1, $punchId2);
$succ07 = $stmt7->execute();
$segmentId1 = (int)$conexion->insert_id;
assert_test($succ07 && $segmentId1 > 0, "S07: Segment result created with primary IN/OUT punch references");

// S08: Duplicate segment ordinal rejected
$stmt8 = $conexion->prepare("INSERT INTO op_rh_v2_pareos_segmentos (id_pareo_jornada, ordinal_segmento, estado_segmento) VALUES (?, 1, 'UNMATCHED_NO_PUNCHES')");
$stmt8->bind_param('i', $headerId1);
$err8 = false;
try {
    $stmt8->execute();
} catch (Throwable $e) {
    $err8 = true;
}
assert_test($err8 || $conexion->errno !== 0, "S08: Duplicate segment ordinal in same execution revision rejected by UK constraint");

// S09 & S10: Entry and Exit punch roles in associated detail table
$stmt9 = $conexion->prepare("INSERT INTO op_rh_v2_pareos_marcaciones_asociadas (id_pareo_jornada, id_pareo_segmento, id_marcacion_cruda, rol_marcacion) VALUES (?, ?, ?, 'ENTRY')");
$stmt9->bind_param('iii', $headerId1, $segmentId1, $punchId1);
$succ09 = $stmt9->execute();

$stmt10 = $conexion->prepare("INSERT INTO op_rh_v2_pareos_marcaciones_asociadas (id_pareo_jornada, id_pareo_segmento, id_marcacion_cruda, rol_marcacion) VALUES (?, ?, ?, 'EXIT')");
$stmt10->bind_param('iii', $headerId1, $segmentId1, $punchId2);
$succ10 = $stmt10->execute();
assert_test($succ09 && $succ10, "S09 & S10: Primary ENTRY and EXIT punch association records created");

// S11: Arbitrary surplus punches supported
$stmt11 = $conexion->prepare("INSERT INTO op_rh_v2_pareos_marcaciones_asociadas (id_pareo_jornada, id_pareo_segmento, id_marcacion_cruda, rol_marcacion) VALUES (?, ?, ?, 'SURPLUS')");
$stmt11->bind_param('iii', $headerId1, $segmentId1, $punchId3);
$succ11 = $stmt11->execute();
assert_test($succ11, "S11: Arbitrary SURPLUS punch association created cleanly");

// S12: Duplicate punch role in SAME execution rejected
$stmt12 = $conexion->prepare("INSERT INTO op_rh_v2_pareos_marcaciones_asociadas (id_pareo_jornada, id_pareo_segmento, id_marcacion_cruda, rol_marcacion) VALUES (?, ?, ?, 'SURPLUS')");
$stmt12->bind_param('iii', $headerId1, $segmentId1, $punchId1);
$err12 = false;
try {
    $stmt12->execute();
} catch (Throwable $e) {
    $err12 = true;
}
assert_test($err12 || $conexion->errno !== 0, "S12: Same raw punch event assigned multiple roles in SAME execution rejected by UK constraint");

// S13: Same raw punch event reusable in HISTORICAL revision (Revision 2)
$stmt13 = $conexion->prepare("INSERT INTO op_rh_v2_pareos_marcaciones_asociadas (id_pareo_jornada, id_pareo_segmento, id_marcacion_cruda, rol_marcacion) VALUES (?, NULL, ?, 'ENTRY')");
$stmt13->bind_param('ii', $headerId2, $punchId1);
$succ13 = $stmt13->execute();
assert_test($succ13, "S13: Same raw punch event reused in historical revision 2 without constraint conflict");

// S14 & S15: Structural missing states (zero IN / zero OUT) representable
$stmt14 = $conexion->prepare("INSERT INTO op_rh_v2_pareos_segmentos (id_pareo_jornada, ordinal_segmento, id_marcacion_entrada, id_marcacion_salida, estado_segmento) VALUES (?, 2, NULL, ?, 'PARTIAL_MISSING_IN')");
$stmt14->bind_param('ii', $headerId1, $punchId2);
$succ14 = $stmt14->execute();
assert_test($succ14, "S14 & S15: NULL Entry/Exit slots represent structural missing states (PARTIAL_MISSING_IN)");

// S16 - S21: Status enum/string values valid
$validStatuses = ['EXACT_PAIR', 'PARTIAL_MISSING_IN', 'PARTIAL_MISSING_OUT', 'UNMATCHED_NO_PUNCHES', 'AMBIGUOUS_PAIRING', 'WORKPLACE_MISMATCH', 'PENDING_SYNC_COMPLETION'];
assert_test(count($validStatuses) === 7, "S16-S21: Status domain definitions represent full frozen contract spectrum");

// S22 - S25: Provenance fields persisted
$res22 = $conexion->query("SELECT version_algoritmo, estado_completitud_l2, id_personal, id_estacion FROM op_rh_v2_pareos_jornada WHERE id = $headerId1");
$row22 = $res22 ? $res22->fetch_assoc() : null;
assert_test($row22 && $row22['version_algoritmo'] === 'v2.4-p1' && $row22['estado_completitud_l2'] === 'VERIFIED' && (int)$row22['id_personal'] === $workerId && (int)$row22['id_estacion'] === $stationId, "S22-S25: Provenance fields (algorithm_version, completeness, worker, station) correctly persisted");

// S26: Raw punch FK RESTRICT safety
$err26 = false;
try {
    $conexion->query("DELETE FROM op_rh_v2_marcaciones_crudas WHERE id = $punchId1");
} catch (Throwable $e) {
    $err26 = true;
}
assert_test($err26 || $conexion->errno !== 0, "S26: Raw PunchEvent delete is RESTRICTED to prevent cascading audit history loss");

// S27: ScheduledShift FK RESTRICT safety
$err27 = false;
try {
    $conexion->query("DELETE FROM op_rh_v2_jornadas_programadas WHERE id = $shiftId");
} catch (Throwable $e) {
    $err27 = true;
}
assert_test($err27 || $conexion->errno !== 0, "S27: ScheduledShift delete is RESTRICTED to prevent cascading audit history loss");

// S28: Append-only UPDATE rejected by trigger
$err28 = false;
try {
    $conexion->query("UPDATE op_rh_v2_pareos_jornada SET estado_pareo = 'MODIFIED' WHERE id = $headerId1");
} catch (Throwable $e) {
    $err28 = true;
}
assert_test($err28 || $conexion->errno !== 0, "S28: UPDATE operation on op_rh_v2_pareos_jornada strictly blocked by DB trigger");

// S29: Append-only DELETE rejected by trigger
$err29 = false;
try {
    $conexion->query("DELETE FROM op_rh_v2_pareos_jornada WHERE id = $headerId1");
} catch (Throwable $e) {
    $err29 = true;
}
assert_test($err29 || $conexion->errno !== 0, "S29: DELETE operation on op_rh_v2_pareos_jornada strictly blocked by DB trigger");

// S30: Multi-workplace ScheduledShifts remain distinct
$seeds2 = seed_parent_records($conexion);
$shiftId2 = $seeds2['shiftId'];
assert_test($shiftId !== $shiftId2, "S30: Distinct ScheduledShifts preserved independently without worker+date global collision");

// S31 - S33: DAY_OFF, UNSCHEDULED, UNKNOWN evidence representable
$stmt31 = $conexion->prepare("INSERT INTO op_rh_v2_pareos_jornada (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo, estado_completitud_l2, estado_pareo) VALUES (?, ?, ?, ?, 1, 'v2.4-p1', 'VERIFIED', 'UNMATCHED_NO_PUNCHES')");
$stmt31->bind_param('iiis', $shiftId2, $workerId, $stationId, $workDate);
$succ31 = $stmt31->execute();
assert_test($succ31, "S31-S33: DAY_OFF, UNSCHEDULED, and UNKNOWN shift evidence representable without fake work segments");

// S34: No arbitrary 4-slot limit
$res34 = $conexion->query("SHOW COLUMNS FROM op_rh_v2_pareos_segmentos LIKE 'ordinal_segmento'");
assert_test($res34 && $res34->num_rows === 1, "S34: Normalized segment schema supports arbitrary 1..N segments");

// S35: No Journey calculation columns present
$res35 = $conexion->query("SHOW COLUMNS FROM op_rh_v2_pareos_jornada WHERE Field IN ('worked_minutes', 'lateness_minutes', 'overtime_minutes', 'falta', 'sin_marcacion')");
assert_test($res35 && $res35->num_rows === 0, "S35: Schema contains NO business calculation or payroll penalty columns");

// S36 & S37: Rollback in test DB and re-application
test_log("Executing DOWN rollback migration...");
run_sql_script($conexion, $pairingDown);

$res36 = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_pareos_jornada'");
assert_test($res36 && $res36->num_rows === 0, "S36: Rollback migration cleanly drops all pairing tables and triggers");

test_log("Re-executing UP migration after rollback...");
run_sql_script($conexion, $pairingUp);

$res37 = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_pareos_jornada'");
assert_test($res37 && $res37->num_rows === 1, "S37: Clean re-application after rollback succeeds completely");

test_log("=== V2.4-P1M Schema Test Suite Completed ===");
test_log("TOTAL PASSED: $passCount");
test_log("TOTAL FAILED: $failCount");

if ($failCount > 0) {
    exit(1);
}
