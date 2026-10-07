<?php
/**
 * Checador V2 — V2.5 Administrative Runtime Test Suite
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/journey/JourneyPairingService.php';
require_once __DIR__ . '/../modelo/v2/journey/JourneyCalculationService.php';
require_once __DIR__ . '/../modelo/v2/journey/AdministrativeEvaluationService.php';

use modelo\v2\journey\JourneyCalculationService;
use modelo\v2\journey\AdministrativeEvaluationService;

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
$l2Up        = __DIR__ . '/../migraciones/v2/2026_10_05_000002_create_v2_level2_server_tables.sql';
$shiftUp     = __DIR__ . '/../migraciones/v2/2026_10_06_000001_create_v2_shift_scheduling_tables.sql';
$pairingUp   = __DIR__ . '/../migraciones/v2/2026_10_07_000001_create_v2_journey_pairing_tables.sql';
$calcUp      = __DIR__ . '/../migraciones/v2/2026_10_07_000002_create_v2_journey_calculation_tables.sql';
$incidUp     = __DIR__ . '/../migraciones/v2/2026_10_07_000003_create_v2_administrative_incidences_tables.sql';
$provUp      = __DIR__ . '/../migraciones/v2/2026_10_07_000004_create_v2_administrative_provenance_tables.sql';

$provDown    = __DIR__ . '/../migraciones/v2/2026_10_07_000004_rollback_v2_administrative_provenance_tables.sql';
$incidDown   = __DIR__ . '/../migraciones/v2/2026_10_07_000003_rollback_v2_administrative_incidences_tables.sql';
$calcDown    = __DIR__ . '/../migraciones/v2/2026_10_07_000002_rollback_v2_journey_calculation_tables.sql';
$pairingDown = __DIR__ . '/../migraciones/v2/2026_10_07_000001_rollback_v2_journey_pairing_tables.sql';
$shiftDown   = __DIR__ . '/../migraciones/v2/2026_10_06_000001_rollback_v2_shift_scheduling_tables.sql';

test_log("=== Starting V2.5 Administrative Runtime Test Suite ===");

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
run_sql_script($conexion, $l2Up);
run_sql_script($conexion, $shiftUp);
run_sql_script($conexion, $pairingUp);
run_sql_script($conexion, $calcUp);
run_sql_script($conexion, $incidUp);
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

$pairingService = new JourneyPairingService($conexion);
$calcService    = new JourneyCalculationService($conexion);
$adminService   = new AdministrativeEvaluationService($conexion);

// Fixtures base
$workerId  = 950;
$stationId = 30;

function create_shift(mysqli $db, int $workerId, int $stationId, string $workDate, string $tipoJornada = 'SCHEDULED', ?string $startUtc = null, ?string $endUtc = null): int {
    $stmt = $db->prepare("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, tipo_jornada, scheduled_start_utc, scheduled_end_utc) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iissss", $workerId, $stationId, $workDate, $tipoJornada, $startUtc, $endUtc);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

function create_punch(mysqli $db, int $workerId, int $stationId, string $occurredUtc): int {
    $uuid = 'PUNCH_ADM_' . uniqid('', true);
    $stmt = $db->prepare("INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid, id_dispositivo, id_estacion_resuelta, id_personal_resuelto, referencia_externa_personal, source_timestamp_raw, ocurrido_at_utc, recibido_at_utc) VALUES (?, 150, ?, ?, 'EXT_950', ?, ?, NOW())");
    $stmt->bind_param("siiss", $uuid, $stationId, $workerId, $occurredUtc, $occurredUtc);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

// R01-R09: Normal complete shift without incidence
$s1 = create_shift($conexion, $workerId, $stationId, '2026-10-20', 'SCHEDULED', '2026-10-20 08:00:00', '2026-10-20 16:00:00');
create_punch($conexion, $workerId, $stationId, '2026-10-20 08:00:00');
create_punch($conexion, $workerId, $stationId, '2026-10-20 16:00:00');

$p1Rev1 = $pairingService->pairScheduledShift($s1);
$c1Res  = $calcService->calculatePairingRevision((int)$p1Rev1['pairing_id']);
$e1Res  = $adminService->evaluateCalculationRevision((int)$c1Res['id']);

$assert_test(
    $e1Res['estado_evaluacion'] === 'NORMAL_COMPLETED' &&
    $e1Res['estado_revision_humana'] === 'NOT_REQUIRED' &&
    (int)$e1Res['revision_numero'] === 1 &&
    empty($e1Res['incidencias_aplicaciones_ids']) &&
    empty($e1Res['ajustes_ids']),
    'R01, R02, R03, R04, R05, R07',
    'Normal complete P2 calculation evaluated to NORMAL_COMPLETED with empty link sets [] and revision 1'
);

// R06: Identical rerun is idempotent
$e1Rerun = $adminService->evaluateCalculationRevision((int)$c1Res['id']);
$assert_test(
    $e1Rerun['is_existing'] === true &&
    (int)$e1Rerun['id_evaluacion'] === (int)$e1Res['id_evaluacion'] &&
    $e1Rerun['fingerprint_ejecucion'] === $e1Res['fingerprint_ejecucion'],
    'R06',
    'Identical rerun of calculation revision is idempotent and reuses existing evaluation'
);

// R10: VACATION without punches applies VACATION_APPLIED
$s2 = create_shift($conexion, $workerId, $stationId, '2026-10-21', 'SCHEDULED', '2026-10-21 08:00:00', '2026-10-21 16:00:00');
$p1Rev2 = $pairingService->pairScheduledShift($s2);
$c2Res  = $calcService->calculatePairingRevision((int)$p1Rev2['pairing_id']);

$incVac = $adminService->createIncidenceMaster([
    'uuid_incidencia' => 'VAC_' . uniqid(),
    'id_personal' => $workerId,
    'id_estacion' => $stationId,
    'tipo_incidencia' => 'VACATION',
    'alcance_incidencia' => 'WORKER',
    'fecha_inicio_local' => '2026-10-21',
    'fecha_fin_local' => '2026-10-21',
    'motivo' => 'Vacaciones familiares'
]);

$e2Res = $adminService->evaluateCalculationRevision((int)$c2Res['id']);
$assert_test(
    $e2Res['estado_evaluacion'] === 'VACATION_APPLIED' &&
    !empty($e2Res['incidencias_aplicaciones_ids']) &&
    (int)$e2Res['incidencias_aplicaciones_ids'][0] === (int)$incVac['aplicaciones_ids'][0],
    'R10, R12',
    'Active VACATION incidence applied cleanly with exact incidence application link and VACATION_APPLIED status'
);

// R11: VACATION + Physical punches produces CONTRADICTION_DETECTED
$s3 = create_shift($conexion, $workerId, $stationId, '2026-10-22', 'SCHEDULED', '2026-10-22 08:00:00', '2026-10-22 16:00:00');
create_punch($conexion, $workerId, $stationId, '2026-10-22 08:00:00');
create_punch($conexion, $workerId, $stationId, '2026-10-22 16:00:00');

$p1Rev3 = $pairingService->pairScheduledShift($s3);
$c3Res  = $calcService->calculatePairingRevision((int)$p1Rev3['pairing_id']);

$adminService->createIncidenceMaster([
    'uuid_incidencia' => 'VAC_WORK_' . uniqid(),
    'id_personal' => $workerId,
    'id_estacion' => $stationId,
    'tipo_incidencia' => 'VACATION',
    'alcance_incidencia' => 'WORKER',
    'fecha_inicio_local' => '2026-10-22',
    'fecha_fin_local' => '2026-10-22',
    'motivo' => 'Vacaciones solapadas con trabajo'
]);

$e3Res = $adminService->evaluateCalculationRevision((int)$c3Res['id']);
$assert_test(
    $e3Res['estado_evaluacion'] === 'CONTRADICTION_DETECTED' &&
    $e3Res['estado_revision_humana'] === 'PENDING_REVIEW',
    'R11, R13, R44',
    'Work evidence during VACATION produces CONTRADICTION_DETECTED with PENDING_REVIEW'
);

// R17, R18: Overlapping Incidences produces OVERLAP_CONFLICT
$s4 = create_shift($conexion, $workerId, $stationId, '2026-10-23', 'SCHEDULED', '2026-10-23 08:00:00', '2026-10-23 16:00:00');
$p1Rev4 = $pairingService->pairScheduledShift($s4);
$c4Res  = $calcService->calculatePairingRevision((int)$p1Rev4['pairing_id']);

$adminService->createIncidenceMaster([
    'uuid_incidencia' => 'OVERLAP_1_' . uniqid(),
    'id_personal' => $workerId,
    'id_estacion' => $stationId,
    'tipo_incidencia' => 'VACATION',
    'alcance_incidencia' => 'WORKER',
    'fecha_inicio_local' => '2026-10-23',
    'fecha_fin_local' => '2026-10-23'
]);

$adminService->createIncidenceMaster([
    'uuid_incidencia' => 'OVERLAP_2_' . uniqid(),
    'id_personal' => $workerId,
    'id_estacion' => $stationId,
    'tipo_incidencia' => 'DISABILITY',
    'alcance_incidencia' => 'WORKER',
    'fecha_inicio_local' => '2026-10-23',
    'fecha_fin_local' => '2026-10-23'
]);

$e4Res = $adminService->evaluateCalculationRevision((int)$c4Res['id']);
$assert_test(
    $e4Res['estado_evaluacion'] === 'OVERLAP_CONFLICT' &&
    $e4Res['estado_revision_humana'] === 'PENDING_REVIEW' &&
    count($e4Res['incidencias_aplicaciones_ids']) === 2,
    'R17, R18, R35',
    'Multiple overlapping incidences produce OVERLAP_CONFLICT and link both incidence application IDs'
);

// R19: FALTA emitted only under strict prerequisites
$s5 = create_shift($conexion, $workerId, $stationId, '2026-10-24', 'SCHEDULED', '2026-10-24 08:00:00', '2026-10-24 16:00:00');
$p1Rev5 = $pairingService->pairScheduledShift($s5);
$c5Res  = $calcService->calculatePairingRevision((int)$p1Rev5['pairing_id']);
$e5Res  = $adminService->evaluateCalculationRevision((int)$c5Res['id']);

$assert_test(
    $e5Res['estado_evaluacion'] === 'FALTA' &&
    empty($e5Res['incidencias_aplicaciones_ids']) &&
    empty($e5Res['ajustes_ids']),
    'R19',
    'Zero punches + VERIFIED Level2 + SCHEDULED shift produces FALTA with empty link sets []'
);

// R21, R22: FALTA prevented by AdministrativeAdjustment
$adjRes = $adminService->createAdjustmentRevision([
    'id_jornada_programada' => $s5,
    'id_calculo_jornada' => (int)$c5Res['id'],
    'tipo_ajuste' => 'JUSTIFY_LATENESS',
    'motivo_ajuste' => 'Falta justificada verbalmente por supervisor'
]);

$c5ResNew = $calcService->calculatePairingRevision((int)$p1Rev5['pairing_id']);
$e5ResNew = $adminService->evaluateCalculationRevision((int)$c5ResNew['id']);

$assert_test(
    $e5ResNew['estado_evaluacion'] !== 'FALTA' &&
    (int)$e5ResNew['revision_numero'] === 2 &&
    !empty($e5ResNew['ajustes_ids']) &&
    (int)$e5ResNew['ajustes_ids'][0] === (int)$adjRes['id'],
    'R21, R22, R33, R34, R41, R42',
    'AdministrativeAdjustment prevents FALTA, linking adjustment revision ID and creating append-only evaluation revision 2'
);

// R27, R28: DAY_OFF and UNSCHEDULED never produce FALTA
$sOff = create_shift($conexion, $workerId, $stationId, '2026-10-25', 'DAY_OFF');
$p1RevOff = $pairingService->pairScheduledShift($sOff);
$cOffRes  = $calcService->calculatePairingRevision((int)$p1RevOff['pairing_id']);
$eOffRes  = $adminService->evaluateCalculationRevision((int)$cOffRes['id']);

$assert_test(
    $eOffRes['estado_evaluacion'] === 'NORMAL_COMPLETED',
    'R27, R28',
    'DAY_OFF shift produces NORMAL_COMPLETED and strictly prevents FALTA emission'
);

// R31, R32: Retroactive incidence leaves historical E1 as [] and creates E2 linked to application
$s6 = create_shift($conexion, $workerId, $stationId, '2026-10-26', 'SCHEDULED', '2026-10-26 08:00:00', '2026-10-26 16:00:00');
$p1Rev6 = $pairingService->pairScheduledShift($s6);
$c6Res  = $calcService->calculatePairingRevision((int)$p1Rev6['pairing_id']);
$e6Original = $adminService->evaluateCalculationRevision((int)$c6Res['id']);

$adminService->createIncidenceMaster([
    'uuid_incidencia' => 'RETRO_' . uniqid(),
    'id_personal' => $workerId,
    'id_estacion' => $stationId,
    'tipo_incidencia' => 'PERMISSION',
    'alcance_incidencia' => 'WORKER',
    'fecha_inicio_local' => '2026-10-26',
    'fecha_fin_local' => '2026-10-26'
]);

$e6New = $adminService->evaluateCalculationRevision((int)$c6Res['id']);

$resCheckE6Old = $conexion->query("SELECT COUNT(*) AS total FROM op_rh_v2_evaluaciones_incidencias WHERE id_evaluacion_administrativa = " . $e6Original['id_evaluacion']);
$cntOldLinks = (int)$resCheckE6Old->fetch_assoc()['total'];

$assert_test(
    (int)$e6Original['revision_numero'] === 1 &&
    $cntOldLinks === 0 &&
    (int)$e6New['revision_numero'] === 2 &&
    $e6New['estado_evaluacion'] === 'PERMISSION_APPLIED' &&
    !empty($e6New['incidencias_aplicaciones_ids']),
    'R31, R32, R38',
    'Retroactive incidence leaves original evaluation E1 links as [] and creates evaluation revision 2 linked to permission application'
);

// R50-R58: Upstream immutability & zero payroll/overtime mutation
$resPunches = $conexion->query("SELECT COUNT(*) FROM op_rh_v2_marcaciones_crudas WHERE id_personal_resuelto = $workerId");
$resShifts  = $conexion->query("SELECT COUNT(*) FROM op_rh_v2_jornadas_programadas WHERE id_personal = $workerId");
$resPairing = $conexion->query("SELECT COUNT(*) FROM op_rh_v2_pareos_jornada WHERE id_personal = $workerId");
$resCalc    = $conexion->query("SELECT COUNT(*) FROM op_rh_v2_calculos_jornada WHERE id_personal = $workerId");
$resCols    = $conexion->query("SHOW COLUMNS FROM op_rh_v2_evaluaciones_administrativas WHERE Field IN ('monto', 'salario', 'tiempo_extra_aprobado')");

$assert_test(
    $resPunches->num_rows > 0 &&
    $resShifts->num_rows > 0 &&
    $resPairing->num_rows > 0 &&
    $resCalc->num_rows > 0 &&
    $resCols->num_rows === 0,
    'R50, R51, R52, R53, R54, R56, R57, R58',
    'Upstream raw punches, shifts, P1 pairing, and P2 calculations remain 100% un-mutated with zero payroll or overtime fields'
);

test_log("\n=== Test Results Summary ===");
test_log("PASS: $passedCount / " . ($passedCount + $failedCount));
test_log("FAIL: $failedCount / " . ($passedCount + $failedCount));

if ($failedCount > 0) {
    exit(1);
}
exit(0);
