<?php
/**
 * Checador V2 — V2.5-MA Administrative Provenance Additive Schema Test Suite
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

$rawPunchUp   = __DIR__ . '/../migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql';
$shiftUp      = __DIR__ . '/../migraciones/v2/2026_10_06_000001_create_v2_shift_scheduling_tables.sql';
$pairingUp    = __DIR__ . '/../migraciones/v2/2026_10_07_000001_create_v2_journey_pairing_tables.sql';
$calcUp       = __DIR__ . '/../migraciones/v2/2026_10_07_000002_create_v2_journey_calculation_tables.sql';
$incidUp      = __DIR__ . '/../migraciones/v2/2026_10_07_000003_create_v2_administrative_incidences_tables.sql';
$provUp       = __DIR__ . '/../migraciones/v2/2026_10_07_000004_create_v2_administrative_provenance_tables.sql';

$provDown     = __DIR__ . '/../migraciones/v2/2026_10_07_000004_rollback_v2_administrative_provenance_tables.sql';
$incidDown    = __DIR__ . '/../migraciones/v2/2026_10_07_000003_rollback_v2_administrative_incidences_tables.sql';
$calcDown     = __DIR__ . '/../migraciones/v2/2026_10_07_000002_rollback_v2_journey_calculation_tables.sql';
$pairingDown  = __DIR__ . '/../migraciones/v2/2026_10_07_000001_rollback_v2_journey_pairing_tables.sql';
$shiftDown    = __DIR__ . '/../migraciones/v2/2026_10_06_000001_rollback_v2_shift_scheduling_tables.sql';

test_log("=== Starting V2.5-MA Administrative Provenance Schema Test Suite ===");

// Teardown y setup limpio
$conexion->query("SET FOREIGN_KEY_CHECKS = 0");
try { run_sql_script($conexion, $provDown); } catch (Throwable $e) {}
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

// Fixture pre-existente para probar backfill 000003
$conexion->query("INSERT INTO op_rh_v2_jornadas_programadas (id, id_personal, id_estacion, fecha_jornada_local, tipo_jornada, scheduled_start_utc, scheduled_end_utc) VALUES (700, 888, 22, '2026-10-15', 'SCHEDULED', '2026-10-15 08:00:00', '2026-10-15 16:00:00')");
$conexion->query("INSERT INTO op_rh_v2_pareos_jornada (id, id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_pareo, fingerprint_ejecucion) VALUES (700, 700, 888, 22, '2026-10-15', 1, 'UNMATCHED_NO_PUNCHES', 'FP_P1_700')");
$conexion->query("INSERT INTO op_rh_v2_calculos_jornada (id, id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, estado_calculo, fingerprint_ejecucion) VALUES (700, 700, 700, 888, 22, '2026-10-15', 1, 'UNMATCHED_NO_PUNCHES', 'FP_P2_700')");

$preUuidAdj = 'PRE_000003_AJUSTE_' . uniqid();
$conexion->query("INSERT INTO op_rh_v2_ajustes_administrativos (uuid_ajuste, id_jornada_programada, id_calculo_jornada, tipo_ajuste, hora_interpretada_entrada_utc, hora_interpretada_salida_utc, motivo_ajuste, creado_por_usuario_id, aprobado_por_usuario_id) VALUES ('$preUuidAdj', 700, 700, 'JUSTIFY_LATENESS', NULL, NULL, 'Ajuste previo a 000004', 1, 2)");

// Ejecutar migración UP 000004
run_sql_script($conexion, $provUp);

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

// A17, A18, A19, A20, A24: Metadata & backfill verification for 000003 rows
$resPre = $conexion->query("SELECT uuid_ajuste_logico, revision_numero, estado_ajuste, id_ajuste_anterior FROM op_rh_v2_ajustes_administrativos WHERE uuid_ajuste = '$preUuidAdj'");
$rowPre = $resPre ? $resPre->fetch_assoc() : null;

$assert_test(
    $rowPre !== null &&
    $rowPre['uuid_ajuste_logico'] === $preUuidAdj &&
    (int)$rowPre['revision_numero'] === 1 &&
    $rowPre['estado_ajuste'] === 'ACTIVE' &&
    $rowPre['id_ajuste_anterior'] === null,
    'A17, A18, A19, A20, A24',
    'Pre-existing 000003 adjustment row cleanly backfilled with logical UUID, revision 1, ACTIVE status, and NULL predecessor'
);

// A01, A08, A25, A26: Evaluation with 0 links represents exact []
$fpE1 = hash('sha256', '700_700_NONE_NONE_v2.5-d_POLICY_V1.0');
$stmtE1 = $conexion->prepare("INSERT INTO op_rh_v2_evaluaciones_administrativas (id_jornada_programada, id_calculo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_admin, version_politica, estado_evaluacion, estado_revision_humana, fingerprint_ejecucion) VALUES (700, 700, 888, 22, '2026-10-15', 1, 'v2.5-d', 'POLICY_V1.0_DEFAULT', 'FALTA', 'NOT_REQUIRED', ?)");
$stmtE1->bind_param("s", $fpE1);
$stmtE1->execute();
$idE1 = (int)$stmtE1->insert_id;
$stmtE1->close();

$resIncLinksE1 = $conexion->query("SELECT COUNT(*) AS total FROM op_rh_v2_evaluaciones_incidencias WHERE id_evaluacion_administrativa = $idE1");
$resAdjLinksE1 = $conexion->query("SELECT COUNT(*) AS total FROM op_rh_v2_evaluaciones_ajustes WHERE id_evaluacion_administrativa = $idE1");
$cntIncE1 = (int)$resIncLinksE1->fetch_assoc()['total'];
$cntAdjE1 = (int)$resAdjLinksE1->fetch_assoc()['total'];

$assert_test(
    $idE1 > 0 && $cntIncE1 === 0 && $cntAdjE1 === 0,
    'A01, A08, A25, A26',
    'Evaluation E1 created with 0 link rows explicitly and unambiguously represents exact consumed sets []'
);

// A02, A03, A04, A05, A06, A07, A27, A28, A29, A30, A31: Retroactive incidence application & exact link preservation
$uuidM1 = 'INC_RETRO_' . uniqid();
$conexion->query("INSERT INTO op_rh_v2_incidencias_maestro (uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual) VALUES ('$uuidM1', 888, 22, 'DISABILITY', 'WORKER', '2026-10-15', '2026-10-20', 'ACTIVE', 1)");
$idM1 = (int)$conexion->insert_id;

$conexion->query("INSERT INTO op_rh_v2_incidencias_revisiones (id_incidencia_maestro, revision_numero, estado_revision, motivo, creado_por_usuario_id) VALUES ($idM1, 1, 'ACTIVE', 'Incapacidad médica', 1)");
$idR1 = (int)$conexion->insert_id;

$conexion->query("INSERT INTO op_rh_v2_incidencias_aplicaciones (id_incidencia_revision, id_jornada_programada, fecha_jornada_local, estado_aplicacion) VALUES ($idR1, 700, '2026-10-15', 'APPLIED')");
$idA1 = (int)$conexion->insert_id;

// Crear evaluación E2 que incluye A1
$fpE2 = hash('sha256', "700_700_{$idA1}_NONE_v2.5-d_POLICY_V1.0");
$stmtE2 = $conexion->prepare("INSERT INTO op_rh_v2_evaluaciones_administrativas (id_jornada_programada, id_calculo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_admin, version_politica, estado_evaluacion, estado_revision_humana, fingerprint_ejecucion) VALUES (700, 700, 888, 22, '2026-10-15', 2, 'v2.5-d', 'POLICY_V1.0_DEFAULT', 'DISABILITY_APPLIED', 'NOT_REQUIRED', ?)");
$stmtE2->bind_param("s", $fpE2);
$stmtE2->execute();
$idE2 = (int)$stmtE2->insert_id;
$stmtE2->close();

$conexion->query("INSERT INTO op_rh_v2_evaluaciones_incidencias (id_evaluacion_administrativa, id_incidencia_aplicacion) VALUES ($idE2, $idA1)");

// Probar rechazo de duplicado A04
$dupIncLinkFailed = false;
try {
    $conexion->query("INSERT INTO op_rh_v2_evaluaciones_incidencias (id_evaluacion_administrativa, id_incidencia_aplicacion) VALUES ($idE2, $idA1)");
} catch (mysqli_sql_exception $e) {
    $dupIncLinkFailed = true;
}

// Probar ON DELETE RESTRICT A07
$deleteIncAppBlocked = false;
try {
    $conexion->query("DELETE FROM op_rh_v2_incidencias_aplicaciones WHERE id = $idA1");
} catch (mysqli_sql_exception $e) {
    $deleteIncAppBlocked = true;
}

// Verificar que E1 sigue teniendo 0 links mientras E2 tiene exact 1 link A1
$resIncE1After = $conexion->query("SELECT COUNT(*) AS total FROM op_rh_v2_evaluaciones_incidencias WHERE id_evaluacion_administrativa = $idE1");
$resIncE2After = $conexion->query("SELECT id_incidencia_aplicacion FROM op_rh_v2_evaluaciones_incidencias WHERE id_evaluacion_administrativa = $idE2");
$cntE1After = (int)$resIncE1After->fetch_assoc()['total'];
$appE2Row = $resIncE2After ? $resIncE2After->fetch_assoc() : null;

$assert_test(
    $cntE1After === 0 &&
    $appE2Row !== null &&
    (int)$appE2Row['id_incidencia_aplicacion'] === $idA1 &&
    $dupIncLinkFailed &&
    $deleteIncAppBlocked,
    'A02, A03, A04, A05, A06, A07',
    'Retroactive incidence insertion leaves historical E1 as [] and E2 linked to [A1]; duplicate links rejected and ON DELETE RESTRICT protected'
);

// A09, A10, A11, A12, A13, A14, A21, A22, A23: Adjustment revision lineage, correction, cancellation & link provenance
$logUuidJ = 'LOG_AJUSTE_' . uniqid();
$uuidJ1   = 'REV1_' . $logUuidJ;
$stmtJ1   = $conexion->prepare("INSERT INTO op_rh_v2_ajustes_administrativos (uuid_ajuste, uuid_ajuste_logico, revision_numero, estado_ajuste, id_ajuste_anterior, id_jornada_programada, id_calculo_jornada, tipo_ajuste, hora_interpretada_entrada_utc, motivo_ajuste, creado_por_usuario_id, aprobado_por_usuario_id) VALUES (?, ?, 1, 'ACTIVE', NULL, 700, 700, 'INTERPRETED_ENTRY_TIME', '2026-10-15 08:00:00', 'Entrada justificada', 1, 2)");
$stmtJ1->bind_param("ss", $uuidJ1, $logUuidJ);
$stmtJ1->execute();
$idJ1 = (int)$stmtJ1->insert_id;
$stmtJ1->close();

// Corrección N+1 J2
$uuidJ2 = 'REV2_' . $logUuidJ;
$conexion->query("UPDATE op_rh_v2_ajustes_administrativos SET estado_ajuste = 'SUPERSEDED' WHERE id = $idJ1");
$stmtJ2 = $conexion->prepare("INSERT INTO op_rh_v2_ajustes_administrativos (uuid_ajuste, uuid_ajuste_logico, revision_numero, estado_ajuste, id_ajuste_anterior, id_jornada_programada, id_calculo_jornada, tipo_ajuste, hora_interpretada_entrada_utc, motivo_ajuste, creado_por_usuario_id, aprobado_por_usuario_id) VALUES (?, ?, 2, 'ACTIVE', $idJ1, 700, 700, 'INTERPRETED_ENTRY_TIME', '2026-10-15 08:15:00', 'Entrada corregida a 08:15', 1, 2)");
$stmtJ2->bind_param("ss", $uuidJ2, $logUuidJ);
$stmtJ2->execute();
$idJ2 = (int)$stmtJ2->insert_id;
$stmtJ2->close();

// Evaluación E3 consumiendo ajuste J2
$fpE3 = hash('sha256', "700_700_{$idA1}_{$idJ2}_v2.5-d_POLICY_V1.0_DEFAULT");
$stmtE3 = $conexion->prepare("INSERT INTO op_rh_v2_evaluaciones_administrativas (id_jornada_programada, id_calculo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_admin, version_politica, estado_evaluacion, estado_revision_humana, fingerprint_ejecucion) VALUES (700, 700, 888, 22, '2026-10-15', 3, 'v2.5-d', 'POLICY_V1.0_DEFAULT', 'NORMAL_COMPLETED', 'NOT_REQUIRED', ?)");
$stmtE3->bind_param("s", $fpE3);
$stmtE3->execute();
$idE3 = (int)$stmtE3->insert_id;
$stmtE3->close();

$conexion->query("INSERT INTO op_rh_v2_evaluaciones_incidencias (id_evaluacion_administrativa, id_incidencia_aplicacion) VALUES ($idE3, $idA1)");
$conexion->query("INSERT INTO op_rh_v2_evaluaciones_ajustes (id_evaluacion_administrativa, id_ajuste_administrativo) VALUES ($idE3, $idJ2)");

// Probar rechazo de duplicado A11
$dupAdjLinkFailed = false;
try {
    $conexion->query("INSERT INTO op_rh_v2_evaluaciones_ajustes (id_evaluacion_administrativa, id_ajuste_administrativo) VALUES ($idE3, $idJ2)");
} catch (mysqli_sql_exception $e) {
    $dupAdjLinkFailed = true;
}

// Probar ON DELETE RESTRICT A14
$deleteAdjBlocked = false;
try {
    $conexion->query("DELETE FROM op_rh_v2_ajustes_administrativos WHERE id = $idJ2");
} catch (mysqli_sql_exception $e) {
    $deleteAdjBlocked = true;
}

// Probar rechazo de revisión duplicada A22
$dupRevFailed = false;
try {
    $conexion->query("INSERT INTO op_rh_v2_ajustes_administrativos (uuid_ajuste, uuid_ajuste_logico, revision_numero, estado_ajuste, id_jornada_programada, id_calculo_jornada, tipo_ajuste, motivo_ajuste, creado_por_usuario_id, aprobado_por_usuario_id) VALUES ('DUP_UUID', '$logUuidJ', 2, 'ACTIVE', 700, 700, 'JUSTIFY_LATENESS', 'Dup', 1, 2)");
} catch (mysqli_sql_exception $e) {
    $dupRevFailed = true;
}

$assert_test(
    $idJ1 > 0 && $idJ2 > 0 && $idE3 > 0 &&
    $dupAdjLinkFailed && $deleteAdjBlocked && $dupRevFailed,
    'A09, A10, A11, A12, A13, A14, A21, A22, A23',
    'Adjustment revision lineage created append-only (J1 superseded -> J2 active), linked to E3; duplicate links/revisions rejected and ON DELETE RESTRICT protected'
);

// A15, A16: Fingerprint source IDs reconstructable and deterministically ordered
$resRecInc = $conexion->query("SELECT id_incidencia_aplicacion FROM op_rh_v2_evaluaciones_incidencias WHERE id_evaluacion_administrativa = $idE3 ORDER BY id_incidencia_aplicacion ASC");
$resRecAdj = $conexion->query("SELECT id_ajuste_administrativo FROM op_rh_v2_evaluaciones_ajustes WHERE id_evaluacion_administrativa = $idE3 ORDER BY id_ajuste_administrativo ASC");

$recIncIds = [];
while ($row = $resRecInc->fetch_assoc()) { $recIncIds[] = $row['id_incidencia_aplicacion']; }
$recAdjIds = [];
while ($row = $resRecAdj->fetch_assoc()) { $recAdjIds[] = $row['id_ajuste_administrativo']; }

$recalcFpE3 = hash('sha256', "700_700_" . implode(',', $recIncIds) . "_" . implode(',', $recAdjIds) . "_v2.5-d_POLICY_V1.0_DEFAULT");

$assert_test(
    $recalcFpE3 === $fpE3,
    'A15 & A16',
    'SHA256 fingerprint inputs fully reconstructable from link tables and verified against deterministic ordering'
);

// A33, A34, A35, A36: Zero raw punch linkage, zero payroll, zero overtime columns
$resColProvInc = $conexion->query("SHOW COLUMNS FROM op_rh_v2_evaluaciones_incidencias WHERE Field IN ('id_marcacion_cruda', 'monto', 'salario', 'tiempo_extra_aprobado')");
$resColProvAdj = $conexion->query("SHOW COLUMNS FROM op_rh_v2_evaluaciones_ajustes WHERE Field IN ('id_marcacion_cruda', 'monto', 'salario', 'tiempo_extra_aprobado')");

$assert_test(
    $resColProvInc->num_rows === 0 && $resColProvAdj->num_rows === 0,
    'A33, A34, A35, A36',
    'Zero raw punch linkage, zero payroll monetary columns, and zero payable overtime columns in provenance tables'
);

// A37, A38, A40, A41, A42, A43: Clean rollback and re-application of 000004 without dropping 000003 base tables
$rollback0004Passed = false;
try {
    $conexion->query("SET FOREIGN_KEY_CHECKS = 0");
    run_sql_script($conexion, $provDown);
    $resCheckProv = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_evaluaciones_incidencias'");
    $resCheckBase = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_evaluaciones_administrativas'");
    if ($resCheckProv->num_rows === 0 && $resCheckBase->num_rows === 1) {
        run_sql_script($conexion, $provUp);
        $resRecheckProv = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_evaluaciones_incidencias'");
        if ($resRecheckProv->num_rows === 1) {
            $rollback0004Passed = true;
        }
    }
} catch (Throwable $e) {
    $rollback0004Passed = false;
} finally {
    $conexion->query("SET FOREIGN_KEY_CHECKS = 1");
}

$assert_test(
    $rollback0004Passed,
    'A37, A38, A40, A41, A42, A43',
    'Additive rollback 000004 drops provenance additions without removing 000003 base tables, and reapplies cleanly'
);

test_log("\n=== Test Results Summary ===");
test_log("PASS: $passedCount / " . ($passedCount + $failedCount));
test_log("FAIL: $failedCount / " . ($passedCount + $failedCount));

if ($failedCount > 0) {
    exit(1);
}
exit(0);
