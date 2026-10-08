<?php
/**
 * Checador V2 — V2.6-M Parallel Comparison Physical Schema Test Suite
 *
 * Validates physical database invariants for V2.6 Parallel Comparison Engine under:
 * - Spec V1.0 (docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md)
 * - Addendum V1.1 (docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_ADDENDUM_V1.1.md)
 * - Contract V1.0 (docs/v2/CHECADOR_V2_LEGACY_PAYROLL_PARALLEL_COMPARISON_ADAPTER_CONTRACT_V1.0.md)
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';

$bdObj = new BD();
$conexion = $bdObj->conectarBD();

function test_log(string $msg): void {
    echo $msg . "\n";
}

function run_sql_file(mysqli $db, string $filePath): void {
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException("Could not read SQL file: $filePath");
    }

    $sqlNoComments = preg_replace('/--.*$/m', '', $sql);

    $executeQuery = function(string $query) use ($db) {
        try {
            if (!$db->query($query)) {
                $err = $db->error;
                if (stripos($err, 'duplicate column') === false && stripos($err, 'already exists') === false && stripos($err, 'duplicate key name') === false) {
                    throw new RuntimeException("SQL execution error: " . $err . "\nQuery: " . $query);
                }
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'duplicate column') === false && stripos($msg, 'already exists') === false && stripos($msg, 'duplicate key name') === false) {
                throw new RuntimeException("SQL execution error: " . $msg . "\nQuery: " . $query, 0, $e);
            }
        }
    };

    if (strpos($sqlNoComments, 'DELIMITER $$') !== false) {
        $parts = explode('DELIMITER $$', $sqlNoComments);
        $standardPart = $parts[0];
        $delimiterPart = $parts[1] ?? '';

        $stdQueries = array_filter(array_map('trim', explode(';', $standardPart)));
        foreach ($stdQueries as $q) {
            if (!empty($q)) $executeQuery($q);
        }

        $delimiterPart = str_replace('DELIMITER ;', '', $delimiterPart);
        $delimQueries = array_filter(array_map('trim', explode('$$', $delimiterPart)));
        foreach ($delimQueries as $q) {
            if (!empty($q)) $executeQuery($q);
        }
    } else {
        $queries = array_filter(array_map('trim', explode(';', $sqlNoComments)));
        foreach ($queries as $q) {
            if (!empty($q)) $executeQuery($q);
        }
    }
}

function apply_prerequisite_migrations(mysqli $db): void {
    $db->query("SET FOREIGN_KEY_CHECKS = 0");
    $rollbacks = [
        __DIR__ . '/../migraciones/v2/2026_10_07_000004_rollback_v2_administrative_provenance_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_07_000003_rollback_v2_administrative_incidences_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_07_000002_rollback_v2_journey_calculation_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_07_000001_rollback_v2_journey_pairing_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_06_000001_rollback_v2_shift_scheduling_tables.sql'
    ];
    foreach ($rollbacks as $rb) {
        try { run_sql_file($db, $rb); } catch (Throwable $e) {}
    }
    $db->query("SET FOREIGN_KEY_CHECKS = 1");

    $migrations = [
        __DIR__ . '/../migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_01_000001_create_v2_temporary_access_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_02_000002_add_integrity_seal_to_raw_punches.sql',
        __DIR__ . '/../migraciones/v2/2026_10_02_000003_add_raw_punch_immutability_triggers.sql',
        __DIR__ . '/../migraciones/v2/2026_10_02_000004_add_raw_punch_read_index.sql',
        __DIR__ . '/../migraciones/v2/2026_10_05_000001_create_v2_read_snapshot_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_05_000002_create_v2_level2_server_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_06_000001_create_v2_shift_scheduling_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_07_000001_create_v2_journey_pairing_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_07_000002_create_v2_journey_calculation_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_07_000003_create_v2_administrative_incidences_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_07_000004_create_v2_administrative_provenance_tables.sql'
    ];
    foreach ($migrations as $m) {
        run_sql_file($db, $m);
    }
}

function assert_true(bool $condition, string $msg): void {
    if (!$condition) {
        throw new RuntimeException("ASSERTION FAILED: $msg");
    }
}

function compute_v1_1_fingerprint(
    int $idEvaluacionAdmin,
    string $legacyPresenceState,
    string $legacyAttendanceSnapshotHash,
    array $sortedLegacyIncidenceSnapshotHashes,
    string $algorithmVersion = 'V1.0',
    string $policyVersion = 'V1.0'
): string {
    $incidenceStr = empty($sortedLegacyIncidenceSnapshotHashes) ? 'NO_INCIDENCES' : implode('|', $sortedLegacyIncidenceSnapshotHashes);
    $input = implode(':', [
        (string)$idEvaluacionAdmin,
        $legacyPresenceState,
        $legacyAttendanceSnapshotHash,
        $incidenceStr,
        $algorithmVersion,
        $policyVersion
    ]);
    return hash('sha256', $input);
}

function helper_seed_evaluacion_admin(mysqli $db, ?int $shiftId = null, int $revision = 1): int {
    static $dayCounter = 0;
    $dayCounter++;
    $workDate = sprintf('2026-10-%02d', ($dayCounter % 28) + 1);

    if ($shiftId === null) {
        $db->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, tipo_jornada, scheduled_start_utc, scheduled_end_utc) VALUES ($dayCounter, 5, '$workDate', 'SCHEDULED', '$workDate 08:00:00', '$workDate 16:00:00')");
        $shiftId = (int)$db->insert_id;
    } else {
        $db->query("INSERT IGNORE INTO op_rh_v2_jornadas_programadas (id, id_personal, id_estacion, fecha_jornada_local, tipo_jornada, scheduled_start_utc, scheduled_end_utc) VALUES ($shiftId, $dayCounter, 5, '$workDate', 'SCHEDULED', '$workDate 08:00:00', '$workDate 16:00:00')");
    }

    $db->query("INSERT IGNORE INTO op_rh_v2_pareos_jornada (id, id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_pareo, fingerprint_ejecucion) VALUES ($shiftId, $shiftId, $dayCounter, 5, '$workDate', 1, 'UNMATCHED_NO_PUNCHES', 'FP_P1_$shiftId')");
    $db->query("INSERT IGNORE INTO op_rh_v2_calculos_jornada (id, id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, fingerprint_ejecucion) VALUES ($shiftId, $shiftId, $shiftId, $dayCounter, 5, '$workDate', 1, 'UNMATCHED_NO_PUNCHES', 'FP_P2_$shiftId')");

    $fp = hash('sha256', "eval_seed_{$shiftId}_{$revision}_" . microtime(true));
    $stmt = $db->prepare("INSERT INTO op_rh_v2_evaluaciones_administrativas (id_jornada_programada, id_calculo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_admin, version_politica, estado_evaluacion, estado_revision_humana, fingerprint_ejecucion) VALUES (?, ?, ?, 5, ?, ?, 'v2.5-d', 'POLICY_V1.0_DEFAULT', 'NORMAL_COMPLETED', 'NOT_REQUIRED', ?)");
    $stmt->bind_param('iiisis', $shiftId, $shiftId, $dayCounter, $workDate, $revision, $fp);
    $stmt->execute();
    return (int)$stmt->insert_id;
}

$testCount = 0;
$assertionCount = 0;

try {
    test_log("==================================================");
    test_log("CHECADOR V2 — V2.6-M PARALLEL COMPARISON SCHEMA SUITE");
    test_log("==================================================");

    // Ensure pristine clean test database state
    run_sql_file($conexion, __DIR__ . '/../migraciones/v2/2026_10_07_000005_rollback_v2_parallel_comparison_tables.sql');
    apply_prerequisite_migrations($conexion);

    // S36 / S01: Clean UP Install
    test_log("[TEST 01] Applying Migration 000005 UP script...");
    run_sql_file($conexion, __DIR__ . '/../migraciones/v2/2026_10_07_000005_create_v2_parallel_comparison_tables.sql');
    $testCount++;

    $tables = ['op_rh_v2_comparaciones_encabezado', 'op_rh_v2_comparaciones_legacy_capturas', 'op_rh_v2_comparaciones_legacy_incidencias', 'op_rh_v2_comparaciones_discrepancias'];
    foreach ($tables as $t) {
        $res = $conexion->query("SHOW TABLES LIKE '$t'");
        assert_true($res && $res->num_rows === 1, "Table $t must exist after UP migration");
        $assertionCount++;
    }
    test_log("  -> PASS: All 4 V2.6 tables created cleanly.");

    // S02 / S03: FK & Revision Monotonicity
    test_log("[TEST 02] Testing FK & Unique constraint (uk_v2_comp_eval_revision)...");
    $evalId1 = helper_seed_evaluacion_admin($conexion, 1, 1);

    $fp1 = compute_v1_1_fingerprint($evalId1, 'OBSERVED', 'snap_hash_1', []);
    $stmt = $conexion->prepare("INSERT INTO op_rh_v2_comparaciones_encabezado (id_evaluacion_administrativa, id_personal, id_estacion, fecha_jornada_local, revision_numero, categoria_resultado, requiere_revision, estado_presencia_legacy, fingerprint_ejecucion) VALUES (?, 101, 5, '2026-10-07', 1, 'MATCH', 0, 'OBSERVED', ?)");
    $stmt->bind_param('is', $evalId1, $fp1);
    $stmt->execute();
    $headerId1 = (int)$stmt->insert_id;
    assert_true($headerId1 > 0, "Header insert must succeed");
    $assertionCount++;

    // Duplicate revision check (S06)
    $fp1_dup = compute_v1_1_fingerprint($evalId1, 'OBSERVED', 'snap_hash_dup', []);
    $stmtDup = $conexion->prepare("INSERT INTO op_rh_v2_comparaciones_encabezado (id_evaluacion_administrativa, id_personal, id_estacion, fecha_jornada_local, revision_numero, categoria_resultado, requiere_revision, estado_presencia_legacy, fingerprint_ejecucion) VALUES (?, 101, 5, '2026-10-07', 1, 'MATCH', 0, 'OBSERVED', ?)");
    $stmtDup->bind_param('is', $evalId1, $fp1_dup);
    $dupFailed = false;
    try {
        $stmtDup->execute();
    } catch (Throwable $e) {
        $dupFailed = true;
    }
    assert_true($dupFailed, "Duplicate revision_numero for same evaluation must be rejected by uk_v2_comp_eval_revision");
    $assertionCount++;
    $testCount++;
    test_log("  -> PASS: Monotonic revision uniqueness enforced.");

    // S05 / S25: Revision 2 Coexistence
    test_log("[TEST 03] Testing Revision 2 coexistence for same evaluation aggregate...");
    $fp2 = compute_v1_1_fingerprint($evalId1, 'OBSERVED', 'snap_hash_2', []);
    $stmtRev2 = $conexion->prepare("INSERT INTO op_rh_v2_comparaciones_encabezado (id_evaluacion_administrativa, id_personal, id_estacion, fecha_jornada_local, revision_numero, categoria_resultado, requiere_revision, estado_presencia_legacy, fingerprint_ejecucion) VALUES (?, 101, 5, '2026-10-07', 2, 'PUNCH_DIFFERENCE', 1, 'OBSERVED', ?)");
    $stmtRev2->bind_param('is', $evalId1, $fp2);
    $stmtRev2->execute();
    $headerId2 = (int)$stmtRev2->insert_id;
    assert_true($headerId2 > $headerId1, "Revision 2 must be inserted as a distinct header");
    $assertionCount++;
    $testCount++;
    test_log("  -> PASS: Revision 1 & 2 coexist append-only.");

    // S08 - S16: All Frozen Result Categories Verification
    test_log("[TEST 04] Verifying all 9 frozen result categories insertion...");
    $categories = [
        'MATCH', 'PUNCH_DIFFERENCE', 'SHIFT_DIFFERENCE', 'INCIDENT_DIFFERENCE',
        'OVERTIME_DIFFERENCE', 'LEGACY_LIMITATION', 'REVIEW_REQUIRED',
        'MISSING_LEGACY_RECORD', 'NOT_COMPARABLE'
    ];
    foreach ($categories as $idx => $cat) {
        $eId = helper_seed_evaluacion_admin($conexion, null, 1);
        $fpCat = compute_v1_1_fingerprint($eId, 'OBSERVED', "snap_hash_$cat", []);
        $stmtCat = $conexion->prepare("INSERT INTO op_rh_v2_comparaciones_encabezado (id_evaluacion_administrativa, id_personal, id_estacion, fecha_jornada_local, revision_numero, categoria_resultado, requiere_revision, estado_presencia_legacy, fingerprint_ejecucion) VALUES (?, 101, 5, '2026-10-07', 1, ?, 0, 'OBSERVED', ?)");
        $stmtCat->bind_param('iss', $eId, $cat, $fpCat);
        $stmtCat->execute();
        assert_true($stmtCat->insert_id > 0, "Category $cat must be valid and insertable");
        $assertionCount++;
    }
    $testCount++;
    test_log("  -> PASS: All 9 frozen categories inserted successfully.");

    // S17 - S19: Attendance Snapshot Persistence & Value Isolation
    test_log("[TEST 05] Verifying attendance snapshot persistence...");
    $snapHash = hash('sha256', '123|5|101|2026-10-07|08:00:00|16:00:00|08:05:00|16:02:00|5|0|0|150.5');
    $stmtSnap = $conexion->prepare("INSERT INTO op_rh_v2_comparaciones_legacy_capturas (id_comparacion_encabezado, id_personal_asistencia_legacy, id_estacion_legacy, id_personal_legacy, fecha_legacy, hora_entrada_legacy, hora_salida_legacy, hora_entrada_sensor_legacy, hora_salida_sensor_legacy, retardo_minutos_legacy, incidencia_dias_legacy, incidencia_legacy, sd_legacy, snapshot_hash) VALUES (?, 123, 5, 101, '2026-10-07', '08:00:00', '16:00:00', '08:05:00', '16:02:00', 5, 0, 0, 150.5, ?)");
    $stmtSnap->bind_param('is', $headerId1, $snapHash);
    $stmtSnap->execute();
    assert_true($stmtSnap->insert_id > 0, "Attendance snapshot row inserted");
    $assertionCount++;
    $testCount++;
    test_log("  -> PASS: Attendance snapshot persisted.");

    // S20 - S23: Legacy Incidence Set Snapshots (0, 1, Multiple)
    test_log("[TEST 06] Verifying 0..N legacy incidence snapshots persistence...");
    $incHash1 = hash('sha256', '10|2026-10-07 08:00:00|VACATION|Notes|doc.pdf|1');
    $incHash2 = hash('sha256', '11|2026-10-07 08:00:00|PERMIT|Permit notes|doc2.pdf|1');

    $stmtInc1 = $conexion->prepare("INSERT INTO op_rh_v2_comparaciones_legacy_incidencias (id_comparacion_encabezado, id_asistencia_incidencia_legacy, fecha_incidencia_legacy, incidencia_texto_legacy, comentario_legacy, documento_legacy, estado_legacy, orden_secuencia, row_hash) VALUES (?, 10, '2026-10-07 08:00:00', 'VACATION', 'Notes', 'doc.pdf', 1, 1, ?)");
    $stmtInc1->bind_param('is', $headerId2, $incHash1);
    $stmtInc1->execute();

    $stmtInc2 = $conexion->prepare("INSERT INTO op_rh_v2_comparaciones_legacy_incidencias (id_comparacion_encabezado, id_asistencia_incidencia_legacy, fecha_incidencia_legacy, incidencia_texto_legacy, comentario_legacy, documento_legacy, estado_legacy, orden_secuencia, row_hash) VALUES (?, 11, '2026-10-07 08:00:00', 'PERMIT', 'Permit notes', 'doc2.pdf', 1, 2, ?)");
    $stmtInc2->bind_param('is', $headerId2, $incHash2);
    $stmtInc2->execute();

    $resInc = $conexion->query("SELECT COUNT(*) AS cnt FROM op_rh_v2_comparaciones_legacy_incidencias WHERE id_comparacion_encabezado = $headerId2");
    assert_true((int)$resInc->fetch_assoc()['cnt'] === 2, "Header 2 must have 2 child incidence rows");
    $assertionCount++;

    $resIncEmpty = $conexion->query("SELECT COUNT(*) AS cnt FROM op_rh_v2_comparaciones_legacy_incidencias WHERE id_comparacion_encabezado = $headerId1");
    assert_true((int)$resIncEmpty->fetch_assoc()['cnt'] === 0, "Header 1 must cleanly represent empty incidence set [] (0 rows)");
    $assertionCount++;
    $testCount++;
    test_log("  -> PASS: 0, 1, and N legacy incidence snapshots supported.");

    // S26: Discrepancy Detail Log Persistence
    test_log("[TEST 07] Verifying discrepancy detail log persistence...");
    $stmtDisc = $conexion->prepare("INSERT INTO op_rh_v2_comparaciones_discrepancias (id_comparacion_encabezado, categoria_discrepancia, campo_evaluado, valor_v2, valor_legacy, descripcion_diferencia, orden_secuencia) VALUES (?, 'PUNCH_DIFFERENCE', 'hora_entrada_sensor', '08:00:00', '08:05:00', '5 minutes entry difference', 1)");
    $stmtDisc->bind_param('i', $headerId2);
    $stmtDisc->execute();
    assert_true($stmtDisc->insert_id > 0, "Discrepancy detail row inserted");
    $assertionCount++;
    $testCount++;
    test_log("  -> PASS: Normalized discrepancy details persisted.");

    // S27 / S28: Hard Immutability Triggers Protection
    test_log("[TEST 08] Testing database triggers blocking UPDATE and DELETE on comparison header...");
    $updateBlocked = false;
    try {
        $conexion->query("UPDATE op_rh_v2_comparaciones_encabezado SET categoria_resultado = 'MATCH' WHERE id = $headerId1");
    } catch (Throwable $e) {
        $updateBlocked = true;
    }
    assert_true($updateBlocked, "UPDATE on op_rh_v2_comparaciones_encabezado must be blocked by trigger");
    $assertionCount++;

    $deleteBlocked = false;
    try {
        $conexion->query("DELETE FROM op_rh_v2_comparaciones_encabezado WHERE id = $headerId1");
    } catch (Throwable $e) {
        $deleteBlocked = true;
    }
    assert_true($deleteBlocked, "DELETE on op_rh_v2_comparaciones_encabezado must be blocked by trigger");
    $assertionCount++;
    $testCount++;
    test_log("  -> PASS: Header UPDATE and DELETE triggers hard-blocked.");

    // F01 - F05: Same Legacy ID Mutated Values & Fingerprint V1.1 Integration
    test_log("[TEST 09] Testing Addendum V1.1 same-ID mutation fingerprinting...");
    $evalIdMutated = helper_seed_evaluacion_admin($conexion, 99, 1);

    $snapHashA = hash('sha256', '123|5|101|2026-10-07|08:00:00|16:00:00|08:00:00|16:00:00|0|0|0|100.0');
    $fpA = compute_v1_1_fingerprint($evalIdMutated, 'OBSERVED', $snapHashA, []);

    $stmtC1 = $conexion->prepare("INSERT INTO op_rh_v2_comparaciones_encabezado (id_evaluacion_administrativa, id_personal, id_estacion, fecha_jornada_local, revision_numero, categoria_resultado, requiere_revision, estado_presencia_legacy, fingerprint_ejecucion) VALUES (?, 101, 5, '2026-10-07', 1, 'MATCH', 0, 'OBSERVED', ?)");
    $stmtC1->bind_param('is', $evalIdMutated, $fpA);
    $stmtC1->execute();
    $idC1 = (int)$stmtC1->insert_id;

    // Mutated legacy values for same legacy ID (123)
    $snapHashB = hash('sha256', '123|5|101|2026-10-07|08:00:00|16:00:00|08:20:00|16:00:00|20|0|0|100.0');
    $fpB = compute_v1_1_fingerprint($evalIdMutated, 'OBSERVED', $snapHashB, []);

    assert_true($fpA !== $fpB, "Mutated values A -> B MUST produce different fingerprints fpA and fpB");
    $assertionCount++;

    $stmtC2 = $conexion->prepare("INSERT INTO op_rh_v2_comparaciones_encabezado (id_evaluacion_administrativa, id_personal, id_estacion, fecha_jornada_local, revision_numero, categoria_resultado, requiere_revision, estado_presencia_legacy, fingerprint_ejecucion) VALUES (?, 101, 5, '2026-10-07', 2, 'PUNCH_DIFFERENCE', 1, 'OBSERVED', ?)");
    $stmtC2->bind_param('is', $evalIdMutated, $fpB);
    $stmtC2->execute();
    $idC2 = (int)$stmtC2->insert_id;

    assert_true($idC2 > $idC1, "C2 with mutated legacy values MUST be insertable as a new revision without unique key collision");
    $assertionCount++;
    $testCount++;
    test_log("  -> PASS: Same-ID mutated values produce distinct fingerprints and append-only revision coexistence.");

    // F06 / F07: Missing Record Sentinel & Distinguishability
    test_log("[TEST 10] Testing missing record sentinel & distinguishability...");
    $missingSnapHash = hash('sha256', 'MISSING_LEGACY_ATTENDANCE_RECORD');
    $evalIdMissing = helper_seed_evaluacion_admin($conexion, 100, 1);
    $fpMissing = compute_v1_1_fingerprint($evalIdMissing, 'MISSING_RECORD', $missingSnapHash, []);

    $stmtMiss = $conexion->prepare("INSERT INTO op_rh_v2_comparaciones_encabezado (id_evaluacion_administrativa, id_personal, id_estacion, fecha_jornada_local, revision_numero, categoria_resultado, requiere_revision, estado_presencia_legacy, fingerprint_ejecucion) VALUES (?, 101, 5, '2026-10-07', 1, 'MISSING_LEGACY_RECORD', 1, 'MISSING_RECORD', ?)");
    $stmtMiss->bind_param('is', $evalIdMissing, $fpMissing);
    $stmtMiss->execute();
    assert_true($stmtMiss->insert_id > 0, "Missing legacy record comparison header inserted");
    $assertionCount++;

    $blankLikeSnapHash = hash('sha256', 'NULL|NULL|NULL|NULL|NULL|NULL|NULL|NULL|NULL|NULL|NULL|NULL');
    $fpBlankLike = compute_v1_1_fingerprint($evalIdMissing, 'OBSERVED', $blankLikeSnapHash, []);
    assert_true($fpMissing !== $fpBlankLike, "Missing record sentinel fingerprint MUST differ from present blank-like row fingerprint");
    $assertionCount++;
    $testCount++;
    test_log("  -> PASS: Missing record sentinel deterministic and distinguishable from blank row.");

    // S28 - S34: Zero Monetary / Payroll / Overtime Approval Fields Audit
    test_log("[TEST 11] Auditing schema columns for zero monetary/payroll/approval fields...");
    $prohibitedSubstrings = ['salary', 'wage', 'rate', 'monto', 'sueldo', 'deduction', 'payroll', 'approved_overtime', 'payable_overtime', 'overtime_amount'];
    foreach ($tables as $tbl) {
        $resCols = $conexion->query("DESCRIBE $tbl");
        while ($cRow = $resCols->fetch_assoc()) {
            $colName = strtolower($cRow['Field']);
            foreach ($prohibitedSubstrings as $prohibited) {
                assert_true(strpos($colName, $prohibited) === false, "Table $tbl column $colName must not contain prohibited monetary/payroll substring '$prohibited'");
            }
        }
    }
    $assertionCount += 4;
    $testCount++;
    test_log("  -> PASS: Zero monetary, payroll, or overtime approval fields confirmed.");

    // S37 / S38: Rollback and Re-apply Validation
    test_log("[TEST 12] Testing Rollback and Re-apply...");
    run_sql_file($conexion, __DIR__ . '/../migraciones/v2/2026_10_07_000005_rollback_v2_parallel_comparison_tables.sql');
    foreach ($tables as $t) {
        $res = $conexion->query("SHOW TABLES LIKE '$t'");
        assert_true($res->num_rows === 0, "Table $t must be cleanly dropped after DOWN rollback");
        $assertionCount++;
    }

    run_sql_file($conexion, __DIR__ . '/../migraciones/v2/2026_10_07_000005_create_v2_parallel_comparison_tables.sql');
    foreach ($tables as $t) {
        $res = $conexion->query("SHOW TABLES LIKE '$t'");
        assert_true($res->num_rows === 1, "Table $t must exist after re-applying UP migration");
        $assertionCount++;
    }
    $testCount++;
    test_log("  -> PASS: Rollback and re-apply cycle completed cleanly.");

    test_log("==================================================");
    test_log("SUMMARY: $testCount / $testCount TEST BLOCKS PASSED ($assertionCount ASSERTIONS)");
    test_log("==================================================");

} catch (Throwable $e) {
    test_log("\nTEST SUITE FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    exit(1);
}
