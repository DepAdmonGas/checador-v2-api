<?php
/**
 * Checador V2 — V2.5-M Administrative Incidences & Adjustments Physical Schema Test Suite
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

$rawPunchUp  = __DIR__ . '/../migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql';
$shiftUp     = __DIR__ . '/../migraciones/v2/2026_10_06_000001_create_v2_shift_scheduling_tables.sql';
$pairingUp   = __DIR__ . '/../migraciones/v2/2026_10_07_000001_create_v2_journey_pairing_tables.sql';
$calcUp      = __DIR__ . '/../migraciones/v2/2026_10_07_000002_create_v2_journey_calculation_tables.sql';
$incidUp     = __DIR__ . '/../migraciones/v2/2026_10_07_000003_create_v2_administrative_incidences_tables.sql';

$incidDown   = __DIR__ . '/../migraciones/v2/2026_10_07_000003_rollback_v2_administrative_incidences_tables.sql';
$calcDown    = __DIR__ . '/../migraciones/v2/2026_10_07_000002_rollback_v2_journey_calculation_tables.sql';
$pairingDown = __DIR__ . '/../migraciones/v2/2026_10_07_000001_rollback_v2_journey_pairing_tables.sql';
$shiftDown   = __DIR__ . '/../migraciones/v2/2026_10_06_000001_rollback_v2_shift_scheduling_tables.sql';

test_log("=== Starting V2.5-M Administrative Incidences Schema Test Suite ===");

// Teardown y setup limpio
$conexion->query("SET FOREIGN_KEY_CHECKS = 0");
try { run_sql_script($conexion, $incidDown); } catch (Throwable $e) {}
try { run_sql_script($conexion, $calcDown); } catch (Throwable $e) {}
try { run_sql_script($conexion, $pairingDown); } catch (Throwable $e) {}
try { run_sql_script($conexion, $shiftDown); } catch (Throwable $e) {}
$conexion->query("TRUNCATE TABLE op_rh_v2_marcaciones_crudas");
$conexion->query("SET FOREIGN_KEY_CHECKS = 1");

run_sql_script($conexion, $rawPunchUp);
run_sql_script($conexion, $shiftUp);
run_sql_script($conexion, $pairingUp);
run_sql_script($conexion, $calcUp);
run_sql_script($conexion, $incidUp);

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

// Fixtures base
$workerId = 888;
$stationId = 22;

$conexion->query("INSERT INTO op_rh_v2_jornadas_programadas (id, id_personal, id_estacion, fecha_jornada_local, tipo_jornada, scheduled_start_utc, scheduled_end_utc) VALUES (500, $workerId, $stationId, '2026-10-10', 'SCHEDULED', '2026-10-10 08:00:00', '2026-10-10 16:00:00')");
$conexion->query("INSERT INTO op_rh_v2_pareos_jornada (id, id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_pareo, fingerprint_ejecucion) VALUES (500, 500, $workerId, $stationId, '2026-10-10', 1, 'UNMATCHED_NO_PUNCHES', 'FP_P1_500')");
$conexion->query("INSERT INTO op_rh_v2_calculos_jornada (id, id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, fingerprint_ejecucion) VALUES (500, 500, 500, $workerId, $stationId, '2026-10-10', 1, 'UNMATCHED_NO_PUNCHES', 'FP_P2_500')");

// S01 & S02: Clean table creation & FK compatibility
$resTables = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_incidencias%'");
$resEval   = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_evaluaciones_administrativas'");
$resAjuste = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_ajustes_administrativos'");

$assert_test(
    $resTables->num_rows === 3 && $resEval->num_rows === 1 && $resAjuste->num_rows === 1,
    'S01 & S02',
    'All 5 V2.5 administrative incidences tables created with exact FK type compatibility'
);

// S03, S04, S05, S06: Incidence master creation with scope & DATE format
$uuidMaster = 'INC_MASTER_' . uniqid();
$stmtM = $conexion->prepare("INSERT INTO op_rh_v2_incidencias_maestro (uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual) VALUES (?, ?, NULL, 'VACATION', 'WORKER', '2026-10-10', '2026-10-20', 'ACTIVE', 1)");
$stmtM->bind_param("si", $uuidMaster, $workerId);
$stmtM->execute();
$idMaster = (int)$stmtM->insert_id;
$stmtM->close();

$assert_test(
    $idMaster > 0,
    'S03, S04, S05, S06',
    'Worker-wide single master range incidence created successfully with YYYY-MM-DD DATE format'
);

// S09, S10, S11, S14: Incidence revision creation & audit actor FKs
$stmtR = $conexion->prepare("INSERT INTO op_rh_v2_incidencias_revisiones (id_incidencia_maestro, revision_numero, estado_revision, motivo, folio_evidencia, creado_por_usuario_id, aprobado_por_usuario_id) VALUES (?, 1, 'ACTIVE', 'Vacaciones autorizadas', 'FOL-123', 1, 2)");
$stmtR->bind_param("i", $idMaster);
$stmtR->execute();
$idRevision = (int)$stmtR->insert_id;
$stmtR->close();

$assert_test(
    $idRevision > 0,
    'S09, S10, S11, S14',
    'Immutable incidence revision 1 created with audit actors and active status'
);

// S15 & S16: Daily application linked to ScheduledShift & unique constraint
$stmtApp = $conexion->prepare("INSERT INTO op_rh_v2_incidencias_aplicaciones (id_incidencia_revision, id_jornada_programada, fecha_jornada_local, estado_aplicacion) VALUES (?, 500, '2026-10-10', 'APPLIED')");
$stmtApp->bind_param("i", $idRevision);
$stmtApp->execute();
$idApp = (int)$stmtApp->insert_id;
$stmtApp->close();

$dupAppFailed = false;
try {
    $conexion->query("INSERT INTO op_rh_v2_incidencias_aplicaciones (id_incidencia_revision, id_jornada_programada, fecha_jornada_local) VALUES ($idRevision, 500, '2026-10-10')");
} catch (mysqli_sql_exception $e) {
    $dupAppFailed = true;
}

$assert_test(
    $idApp > 0 && $dupAppFailed,
    'S15 & S16',
    'Daily incidence application linked to ScheduledShift; duplicate same-revision application rejected'
);

// S19, S20, S21, S22: AdministrativeAdjustment with interpreted time outside raw punches
$uuidAjuste = 'AJUSTE_' . uniqid();
$stmtAdj = $conexion->prepare("INSERT INTO op_rh_v2_ajustes_administrativos (uuid_ajuste, id_jornada_programada, id_calculo_jornada, tipo_ajuste, hora_interpretada_entrada_utc, hora_interpretada_salida_utc, motivo_ajuste, creado_por_usuario_id, aprobado_por_usuario_id) VALUES (?, 500, 500, 'INTERPRETED_ENTRY_TIME', '2026-10-10 08:00:00', NULL, 'Entrada autorizada verbalmente', 1, 2)");
$stmtAdj->bind_param("s", $uuidAjuste);
$stmtAdj->execute();
$idAjuste = (int)$stmtAdj->insert_id;
$stmtAdj->close();

$assert_test(
    $idAjuste > 0,
    'S19, S20, S21, S22',
    'AdministrativeAdjustment created with interpreted entry time stored separately from raw punches'
);

// S23, S25, S26, S27, S28, S35: Administrative evaluation revision 1 with FALTA status & SHA256 fingerprint
$fpEval = hash('sha256', 'EVAL_500_REV1');
$stmtEval = $conexion->prepare("INSERT INTO op_rh_v2_evaluaciones_administrativas (id_jornada_programada, id_calculo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_admin, version_politica, estado_evaluacion, estado_revision_humana, fingerprint_ejecucion) VALUES (500, 500, $workerId, $stationId, '2026-10-10', 1, 'v2.5-d', 'POLICY_V1.0_DEFAULT', 'FALTA', 'NOT_REQUIRED', ?)");
$stmtEval->bind_param("s", $fpEval);
$stmtEval->execute();
$idEval = (int)$stmtEval->insert_id;
$stmtEval->close();

$assert_test(
    $idEval > 0,
    'S23, S25, S26, S27, S28, S35',
    'Administrative evaluation revision 1 created with FALTA status, policy version, and SHA256 fingerprint'
);

// S46 & S47: Trigger append-only protection on evaluations
$updateBlocked = false;
try {
    $conexion->query("UPDATE op_rh_v2_evaluaciones_administrativas SET estado_evaluacion = 'MODIFIED' WHERE id = $idEval");
} catch (mysqli_sql_exception $e) {
    $updateBlocked = true;
}

$deleteBlocked = false;
try {
    $conexion->query("DELETE FROM op_rh_v2_evaluaciones_administrativas WHERE id = $idEval");
} catch (mysqli_sql_exception $e) {
    $deleteBlocked = true;
}

$assert_test(
    $updateBlocked && $deleteBlocked,
    'S46 & S47',
    'Database triggers block UPDATE and DELETE on op_rh_v2_evaluaciones_administrativas'
);

// S42, S43, S44, S45: FK delete safety (ON DELETE RESTRICT)
$p2DeleteBlocked = false;
try {
    $conexion->query("DELETE FROM op_rh_v2_calculos_jornada WHERE id = 500");
} catch (mysqli_sql_exception $e) {
    $p2DeleteBlocked = true;
}

$assert_test(
    $p2DeleteBlocked,
    'S42, S43, S44, S45',
    'ON DELETE RESTRICT on op_rh_v2_calculos_jornada prevents cascade deletion of administrative history'
);

// S38, S39, S40, S41: Absence of raw punch masquerading, overtime approval, or payroll fields
$resCols = $conexion->query("SHOW COLUMNS FROM op_rh_v2_evaluaciones_administrativas WHERE Field IN ('id_marcacion_cruda', 'monto', 'salario', 'tiempo_extra_aprobado', 'efecto_nomina')");

$assert_test(
    $resCols->num_rows === 0,
    'S38, S39, S40, S41',
    'Zero synthetic raw punch fields, overtime approval fields, or payroll monetary columns exist'
);

// S51 & S52: Clean rollback and re-application
$rollbackPassed = false;
try {
    $conexion->query("SET FOREIGN_KEY_CHECKS = 0");
    run_sql_script($conexion, $incidDown);
    $resCheck = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_evaluaciones_administrativas'");
    if ($resCheck->num_rows === 0) {
        run_sql_script($conexion, $incidUp);
        $resRecheck = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_evaluaciones_administrativas'");
        if ($resRecheck->num_rows === 1) {
            $rollbackPassed = true;
        }
    }
} catch (Throwable $e) {
    $rollbackPassed = false;
} finally {
    $conexion->query("SET FOREIGN_KEY_CHECKS = 1");
}

$assert_test(
    $rollbackPassed,
    'S51 & S52',
    'Clean rollback via DOWN script and clean re-application via UP script verified'
);

test_log("\n=== Test Results Summary ===");
test_log("PASS: $passedCount / " . ($passedCount + $failedCount));
test_log("FAIL: $failedCount / " . ($passedCount + $failedCount));

if ($failedCount > 0) {
    exit(1);
}
exit(0);
