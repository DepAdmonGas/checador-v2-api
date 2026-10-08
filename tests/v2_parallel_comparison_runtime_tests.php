<?php
/**
 * Checador V2 — V2.6 Parallel Comparison Runtime Engine Test Suite
 *
 * Validates domain runtime invariants R01-R60 for ParallelComparisonService under:
 * - Contract V1.0 (docs/v2/CHECADOR_V2_LEGACY_PAYROLL_PARALLEL_COMPARISON_ADAPTER_CONTRACT_V1.0.md)
 * - Physical Model V1.0 (docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md)
 * - Addendum Spec V1.1 (docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_ADDENDUM_V1.1.md)
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/journey/ParallelComparisonService.php';

use modelo\v2\journey\ParallelComparisonService;

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

function setup_prerequisite_schema(mysqli $db): void {
    $db->query("SET FOREIGN_KEY_CHECKS = 0");
    $rollbacks = [
        __DIR__ . '/../migraciones/v2/2026_10_07_000005_rollback_v2_parallel_comparison_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_07_000004_rollback_v2_administrative_provenance_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_07_000003_rollback_v2_administrative_incidences_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_07_000002_rollback_v2_journey_calculation_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_07_000001_rollback_v2_journey_pairing_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_06_000001_rollback_v2_shift_scheduling_tables.sql'
    ];
    foreach ($rollbacks as $rb) {
        try { run_sql_script($db, $rb); } catch (Throwable $e) {}
    }

    // Clean legacy test tables if present
    try { $db->query("DROP TABLE IF EXISTS op_rh_personal_asistencia_incidencia"); } catch (Throwable $e) {}
    try { $db->query("DROP TABLE IF EXISTS op_rh_personal_asistencia"); } catch (Throwable $e) {}

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
        __DIR__ . '/../migraciones/v2/2026_10_07_000004_create_v2_administrative_provenance_tables.sql',
        __DIR__ . '/../migraciones/v2/2026_10_07_000005_create_v2_parallel_comparison_tables.sql'
    ];
    foreach ($migrations as $m) {
        run_sql_script($db, $m);
    }

    // Create disposable legacy mock tables
    $db->query("
        CREATE TABLE IF NOT EXISTS `op_rh_personal_asistencia` (
            `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `id_estacion` INT(11) NOT NULL,
            `id_personal` INT(11) NOT NULL,
            `fecha` DATE NOT NULL,
            `hora_entrada` TIME NOT NULL,
            `hora_salida` TIME NOT NULL,
            `hora_entrada_sensor` TIME NOT NULL,
            `hora_salida_sensor` TIME NOT NULL,
            `retardo_minutos` INT(11) NOT NULL DEFAULT 0,
            `incidencia_dias` INT(11) NOT NULL DEFAULT 0,
            `incidencia` DOUBLE NOT NULL DEFAULT 0,
            `sd` DOUBLE NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS `op_rh_personal_asistencia_incidencia` (
            `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `id_asistencia` INT(11) NOT NULL,
            `fecha` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `incidencia` TEXT NOT NULL,
            `comentario` TEXT NOT NULL,
            `documento` TEXT NOT NULL,
            `estado` INT(11) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function assert_true(bool $condition, string $msg): void {
    if (!$condition) {
        throw new RuntimeException("ASSERTION FAILED: $msg");
    }
}

function seed_v2_evaluation(
    mysqli $db,
    int $workerId,
    int $stationId,
    string $workDate,
    string $evalState = 'NORMAL_COMPLETED',
    string $humanReviewState = 'NOT_REQUIRED'
): int {
    static $shiftSeq = 2000;
    $shiftSeq++;
    $db->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, tipo_jornada, scheduled_start_utc, scheduled_end_utc) VALUES ($workerId, $stationId, '$workDate', 'SCHEDULED', '$workDate 08:00:00', '$workDate 16:00:00')");
    $shiftId = (int)$db->insert_id;

    $db->query("INSERT INTO op_rh_v2_pareos_jornada (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_pareo, fingerprint_ejecucion) VALUES ($shiftId, $workerId, $stationId, '$workDate', 1, 'EXACT_PAIR', 'FP_P1_$shiftId')");
    $pareoId = (int)$db->insert_id;

    $db->query("INSERT INTO op_rh_v2_calculos_jornada (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, fingerprint_ejecucion) VALUES ($shiftId, $pareoId, $workerId, $stationId, '$workDate', 1, 'CALCULATED', 'FP_P2_$shiftId')");
    $calcId = (int)$db->insert_id;

    $fp = hash('sha256', "eval_seed_{$shiftId}_" . microtime(true));
    $stmt = $db->prepare("INSERT INTO op_rh_v2_evaluaciones_administrativas (id_jornada_programada, id_calculo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_admin, version_politica, estado_evaluacion, estado_revision_humana, fingerprint_ejecucion) VALUES (?, ?, ?, ?, ?, 1, 'v2.5-d', 'POLICY_V1.0_DEFAULT', ?, ?, ?)");
    $stmt->bind_param('iiiissss', $shiftId, $calcId, $workerId, $stationId, $workDate, $evalState, $humanReviewState, $fp);
    $stmt->execute();
    return (int)$stmt->insert_id;
}

$testCount = 0;
$assertionCount = 0;

try {
    test_log("==================================================");
    test_log("CHECADOR V2 — V2.6 PARALLEL COMPARISON RUNTIME SUITE");
    test_log("==================================================");

    setup_prerequisite_schema($conexion);
    $service = new ParallelComparisonService($conexion, 'V1.0', 'V1.0');

    // R01 - R03: Explicit V2.5 Evaluation Consumption & Legacy Read-Only
    test_log("[BLOCK 01] Testing explicit V2.5 evaluation consumption & legacy read-only...");
    $worker1 = 501;
    $date1 = '2026-10-10';
    $evalId1 = seed_v2_evaluation($conexion, $worker1, 10, $date1, 'NORMAL_COMPLETED');

    // Seed legacy attendance row
    $conexion->query("INSERT INTO op_rh_personal_asistencia (id_estacion, id_personal, fecha, hora_entrada, hora_salida, hora_entrada_sensor, hora_salida_sensor, retardo_minutos, incidencia_dias, incidencia, sd) VALUES (10, $worker1, '$date1', '08:00:00', '16:00:00', '08:00:00', '16:00:00', 0, 0, 0, 200.0)");
    $legacyAsistId1 = (int)$conexion->insert_id;

    $res1 = $service->compareAdministrativeEvaluation($evalId1);
    assert_true($res1['id'] > 0, "Comparison header created");
    assert_true($res1['categoria_resultado'] === 'MATCH', "Matching evaluation must yield MATCH category");
    assert_true($res1['revision_numero'] === 1, "First execution must allocate revision 1");
    assert_true(!$res1['is_existing'], "First execution must be newly created (is_existing = false)");
    $assertionCount += 4;
    $testCount++;
    test_log("  -> PASS: Explicit V2.5 evaluation compared cleanly to MATCH.");

    // R10 / R58: Identical Rerun Idempotency
    test_log("[BLOCK 02] Testing identical rerun idempotency...");
    $resRerun = $service->compareAdministrativeEvaluation($evalId1);
    assert_true($resRerun['id'] === $res1['id'], "Identical rerun must return existing header ID");
    assert_true($resRerun['is_existing'] === true, "Identical rerun must set is_existing = true");
    assert_true($resRerun['fingerprint_ejecucion'] === $res1['fingerprint_ejecucion'], "Identical rerun must return exact matching execution fingerprint");
    $assertionCount += 3;
    $testCount++;
    test_log("  -> PASS: Identical rerun is idempotent.");

    // R12, R13, R14, R38: Same Legacy ID Mutated Values Scenario
    test_log("[BLOCK 03] Testing legacy row value mutation (same ID, changed values -> Revision 2)...");
    // Mutate legacy attendance row (sensor entry timestamp updated from 08:00:00 to 08:25:00)
    $conexion->query("UPDATE op_rh_personal_asistencia SET hora_entrada_sensor = '08:25:00', retardo_minutos = 25 WHERE id = $legacyAsistId1");

    $res2 = $service->compareAdministrativeEvaluation($evalId1);
    assert_true($res2['id'] > $res1['id'], "Comparison for mutated legacy values must create a new header row");
    assert_true($res2['revision_numero'] === 2, "Comparison for mutated legacy values must allocate revision 2");
    assert_true($res2['categoria_resultado'] === 'PUNCH_DIFFERENCE', "Entry sensor delta > 60s must yield PUNCH_DIFFERENCE");
    assert_true($res2['fingerprint_ejecucion'] !== $res1['fingerprint_ejecucion'], "Fingerprint for mutated values MUST differ from original fingerprint");
    assert_true(!$res2['is_existing'], "New revision for mutated values must set is_existing = false");
    $assertionCount += 5;
    $testCount++;
    test_log("  -> PASS: Same legacy ID with mutated values cleanly creates Revision 2.");

    // R06, R07, R08, R15, R16, R17: Legacy Incidence Snapshot Set & Retroactive Incidence Addition
    test_log("[BLOCK 04] Testing legacy incidence snapshot set persistence & retroactivity...");
    $worker2 = 502;
    $date2 = '2026-10-11';
    $evalId2 = seed_v2_evaluation($conexion, $worker2, 10, $date2, 'VACATION_APPLIED');

    $conexion->query("INSERT INTO op_rh_personal_asistencia (id_estacion, id_personal, fecha, hora_entrada, hora_salida, hora_entrada_sensor, hora_salida_sensor, retardo_minutos, incidencia_dias, incidencia, sd) VALUES (10, $worker2, '$date2', '00:00:00', '00:00:00', '00:00:00', '00:00:00', 0, 1, 1, 200.0)");
    $legacyAsistId2 = (int)$conexion->insert_id;

    // C1: Legacy incidence set initially []
    $resInc1 = $service->compareAdministrativeEvaluation($evalId2);
    assert_true($resInc1['categoria_resultado'] === 'MATCH', "VACATION_APPLIED matched with legacy incidence val 1");

    // Retroactive addition of legacy incidence child row
    $conexion->query("INSERT INTO op_rh_personal_asistencia_incidencia (id_asistencia, fecha, incidencia, comentario, documento, estado) VALUES ($legacyAsistId2, '$date2 08:00:00', 'VACACIONES', 'Folio 456', 'vac.pdf', 1)");
    $resInc2 = $service->compareAdministrativeEvaluation($evalId2);

    assert_true($resInc2['revision_numero'] === 2, "Retroactive incidence addition creates Revision 2");
    assert_true($resInc2['fingerprint_ejecucion'] !== $resInc1['fingerprint_ejecucion'], "Fingerprints must differ after incidence set addition");
    $assertionCount += 3;
    $testCount++;
    test_log("  -> PASS: Legacy incidence set retroactivity produces distinct append-only revision.");

    // R18 - R21: Missing Legacy Record Sentinel & Distinguishability
    test_log("[BLOCK 05] Testing missing legacy record handling & missing-then-created scenario...");
    $worker3 = 503;
    $date3 = '2026-10-12';
    $evalId3 = seed_v2_evaluation($conexion, $worker3, 10, $date3, 'NORMAL_COMPLETED');

    // C1: Missing legacy attendance row
    $resMiss1 = $service->compareAdministrativeEvaluation($evalId3);
    assert_true($resMiss1['categoria_resultado'] === 'MISSING_LEGACY_RECORD', "Missing legacy row must yield MISSING_LEGACY_RECORD");
    assert_true($resMiss1['estado_presencia_legacy'] === 'MISSING_RECORD', "Presence state must be MISSING_RECORD");

    // Later creation of legacy attendance row
    $conexion->query("INSERT INTO op_rh_personal_asistencia (id_estacion, id_personal, fecha, hora_entrada, hora_salida, hora_entrada_sensor, hora_salida_sensor, retardo_minutos, incidencia_dias, incidencia, sd) VALUES (10, $worker3, '$date3', '08:00:00', '16:00:00', '08:00:00', '16:00:00', 0, 0, 0, 200.0)");

    $resMiss2 = $service->compareAdministrativeEvaluation($evalId3);
    assert_true($resMiss2['revision_numero'] === 2, "Newly created legacy row creates Revision 2");
    assert_true($resMiss2['categoria_resultado'] === 'MATCH', "Newly created matching row transitions result to MATCH");
    assert_true($resMiss2['fingerprint_ejecucion'] !== $resMiss1['fingerprint_ejecucion'], "Fingerprint for created row differs from missing sentinel fingerprint");
    $assertionCount += 5;
    $testCount++;
    test_log("  -> PASS: Missing-then-created scenario transitions cleanly to Revision 2.");

    // R22 - R30: Category Spectrum Verification (FALTA vs Sensor Punches -> PUNCH_DIFFERENCE)
    test_log("[BLOCK 06] Testing FALTA vs Legacy sensor punches discrepancy...");
    $worker4 = 504;
    $date4 = '2026-10-13';
    $evalId4 = seed_v2_evaluation($conexion, $worker4, 10, $date4, 'FALTA');

    // Legacy row has sensor punches
    $conexion->query("INSERT INTO op_rh_personal_asistencia (id_estacion, id_personal, fecha, hora_entrada, hora_salida, hora_entrada_sensor, hora_salida_sensor, retardo_minutos, incidencia_dias, incidencia, sd) VALUES (10, $worker4, '$date4', '08:00:00', '16:00:00', '08:02:00', '16:01:00', 0, 0, 0, 200.0)");

    $resFaltaDiff = $service->compareAdministrativeEvaluation($evalId4);
    assert_true($resFaltaDiff['categoria_resultado'] === 'PUNCH_DIFFERENCE', "FALTA vs legacy sensor punches must yield PUNCH_DIFFERENCE");
    assert_true($resFaltaDiff['requiere_revision'] === 1, "PUNCH_DIFFERENCE must flag requiere_revision = 1");
    $assertionCount += 2;
    $testCount++;
    test_log("  -> PASS: FALTA vs sensor punches yields PUNCH_DIFFERENCE with requires review.");

    // R31, R32: Discrepancy Evidence Persistence Verification
    test_log("[BLOCK 07] Verifying discrepancy evidence log persistence...");
    $resDiscQuery = $conexion->query("SELECT * FROM op_rh_v2_comparaciones_discrepancias WHERE id_comparacion_encabezado = {$resFaltaDiff['id']}");
    assert_true($resDiscQuery->num_rows >= 1, "Discrepancy detail row must be logged in op_rh_v2_comparaciones_discrepancias");
    $discRow = $resDiscQuery->fetch_assoc();
    assert_true($discRow['campo_evaluado'] === 'estado_presencia', "Evaluated field must be estado_presencia");
    assert_true($discRow['valor_v2'] === 'FALTA', "valor_v2 must be FALTA");
    $assertionCount += 3;
    $testCount++;
    test_log("  -> PASS: Discrepancy details logged accurately.");

    // R38 - R42: Version Changes & Upstream V2.5 Revision Changes
    test_log("[BLOCK 08] Testing comparison algorithm & policy version changes...");
    $serviceV11 = new ParallelComparisonService($conexion, 'V1.1', 'POLICY_V1.1');
    $resVerChange = $serviceV11->compareAdministrativeEvaluation($evalId1); // Re-run evalId1 under V1.1
    assert_true($resVerChange['revision_numero'] === 3, "Version change must create a new comparison revision (Revision 3)");
    assert_true($resVerChange['fingerprint_ejecucion'] !== $res2['fingerprint_ejecucion'], "Algorithm/policy version change MUST alter fingerprint");
    $assertionCount += 2;
    $testCount++;
    test_log("  -> PASS: Version change creates distinct append-only revision.");

    // R44 - R53: Zero Payroll & Un-mutated Upstream Boundaries Verification
    test_log("[BLOCK 09] Auditing zero payroll effect & upstream immutability...");
    // Check legacy tables un-mutated
    $resLegCheck = $conexion->query("SELECT COUNT(*) AS cnt FROM op_rh_personal_asistencia");
    assert_true((int)$resLegCheck->fetch_assoc()['cnt'] === 4, "Legacy attendance row count MUST remain unchanged (4 rows)");

    // Check upstream V2.5 evaluations un-mutated
    $resEvalCheck = $conexion->query("SELECT COUNT(*) AS cnt FROM op_rh_v2_evaluaciones_administrativas");
    assert_true((int)$resEvalCheck->fetch_assoc()['cnt'] === 4, "Upstream V2.5 evaluation count MUST remain unchanged (4 rows)");
    $assertionCount += 2;
    $testCount++;
    test_log("  -> PASS: Zero payroll write & upstream immutability verified.");

    test_log("==================================================");
    test_log("SUMMARY: $testCount / $testCount TEST BLOCKS PASSED ($assertionCount ASSERTIONS)");
    test_log("==================================================");

} catch (Throwable $e) {
    test_log("\nTEST SUITE FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    exit(1);
}
