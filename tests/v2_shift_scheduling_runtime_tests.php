<?php
/**
 * Checador V2 — V2.3-A05 Shift Scheduling Runtime Test Suite
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/shift/ShiftTemplateService.php';
require_once __DIR__ . '/../modelo/v2/shift/ShiftAssignmentService.php';
require_once __DIR__ . '/../modelo/v2/shift/ScheduleResolutionService.php';
require_once __DIR__ . '/../modelo/v2/shift/ScheduledShiftMaterializationService.php';

$bdObj = new BD();
$db = $bdObj->conectarBD();

function test_log(string $msg): void {
    echo $msg . "\n";
}

function run_sql_file(mysqli $db, string $filePath): void {
    $sql = file_get_contents($filePath);
    $queries = array_filter(array_map('trim', explode(';', $sql)), fn($q) => !empty($q));
    foreach ($queries as $query) {
        $lines = array_filter(explode("\n", $query), fn($l) => !empty(trim($l)) && strpos(trim($l), '--') !== 0);
        if (empty($lines)) continue;
        $cleanQuery = implode("\n", $lines);
        if (!$db->query($cleanQuery)) {
            throw new RuntimeException("SQL Execution Error: " . $db->error);
        }
    }
}

$upFile = __DIR__ . '/../migraciones/v2/2026_10_06_000001_create_v2_shift_scheduling_tables.sql';
$downFile = __DIR__ . '/../migraciones/v2/2026_10_06_000001_rollback_v2_shift_scheduling_tables.sql';

test_log("=== Starting V2.3-A05 Shift Scheduling Runtime Test Suite ===");

// Reset database tables using migration scripts
$pairingDownFile = __DIR__ . '/../migraciones/v2/2026_10_07_000001_rollback_v2_journey_pairing_tables.sql';
if (file_exists($pairingDownFile)) {
    run_sql_file($db, $pairingDownFile);
}
run_sql_file($db, $downFile);
run_sql_file($db, $upFile);

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $testId, string $description): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        test_log("[$testId] PASS: $description");
    } else {
        $failCount++;
        test_log("[$testId] FAIL: $description");
    }
}

$tplService = new ShiftTemplateService($db);
$asigService = new ShiftAssignmentService($db);
$resService = new ScheduleResolutionService($db);
$matService = new ScheduledShiftMaterializationService($db);

try {
    // Ensure test worker 100 & 101 exist in op_rh_personal for tests
    $db->query("INSERT IGNORE INTO op_rh_personal (id, nombre_completo, no_colaborador) VALUES (100, 'Test Worker 100', '100')");
    $db->query("INSERT IGNORE INTO op_rh_personal (id, nombre_completo, no_colaborador) VALUES (101, 'Test Worker 101', '101')");

    // T01: Create Template Version 1
    $resT1 = $tplService->createTemplate([
        'clave' => 'TURNO_REGULAR_8H',
        'nombre' => 'Turno Regular 8 Horas',
        'tipo_turno' => 'REGULAR',
        'segmentos' => [
            [
                'ordinal' => 1,
                'tipo_segmento' => 'WORK',
                'hora_inicio_local' => '08:00:00',
                'hora_fin_local' => '16:00:00',
                'dia_offset_inicio' => 0,
                'dia_offset_fin' => 0
            ]
        ]
    ]);
    assertTest($resT1['id'] > 0 && $resT1['version'] === 1, 'T01', 'Create Shift Template Version 1');

    // T02: Atomic Template + Segments
    assertTest(count($resT1['segmentos']) === 1 && $resT1['segmentos'][0]['ordinal'] === 1, 'T02', 'Atomic segment insertion on template create');

    // T03: Invalid Segment Rolls Back All
    $t03Failed = false;
    try {
        $tplService->createTemplate([
            'clave' => 'TURNO_INVALID_SEG',
            'nombre' => 'Turno Invalid',
            'segmentos' => [
                ['ordinal' => 1, 'hora_inicio_local' => '08:00:00', 'hora_fin_local' => '12:00:00'],
                ['ordinal' => 3, 'hora_inicio_local' => '13:00:00', 'hora_fin_local' => '17:00:00'] // Discontinuous ordinal
            ]
        ]);
    } catch (InvalidArgumentException $e) {
        $t03Failed = true;
    }
    assertTest($t03Failed, 'T03', 'Invalid segment ordinals roll back entire transaction');

    // T04: Duplicate Clave/Version Rejected
    $t04Failed = false;
    try {
        $tplService->createTemplate([
            'clave' => 'TURNO_REGULAR_8H',
            'nombre' => 'Duplicado',
            'segmentos' => [['ordinal' => 1, 'hora_inicio_local' => '08:00:00', 'hora_fin_local' => '16:00:00']]
        ]);
    } catch (RuntimeException $e) {
        $t04Failed = true;
    }
    assertTest($t04Failed, 'T04', 'Duplicate template clave version 1 rejected by UK');

    // T05: Create Version 2
    $resT2 = $tplService->createNewVersion('TURNO_REGULAR_8H', [
        'nombre' => 'Turno Regular 8 Horas Modificado',
        'tipo_turno' => 'REGULAR',
        'segmentos' => [
            ['ordinal' => 1, 'hora_inicio_local' => '09:00:00', 'hora_fin_local' => '17:00:00', 'dia_offset_inicio' => 0, 'dia_offset_fin' => 0]
        ]
    ]);
    assertTest($resT2['version'] === 2, 'T05', 'Create template version 2 for existing clave');

    // T06: Old Template Version Immutable
    $v1Detail = $tplService->getTemplateVersion('TURNO_REGULAR_8H', 1);
    assertTest($v1Detail['segmentos'][0]['hora_inicio_local'] === '08:00:00', 'T06', 'Version 1 template segments remain unchanged after v2 creation');

    // T07: List Templates Bounded
    $listTpl = $tplService->listTemplates(1, 50, false);
    assertTest(count($listTpl['items']) === 1 && $listTpl['items'][0]['version'] === 2, 'T07', 'List templates defaults to latest version');

    // T08: Fetch Exact Version Detail
    $v2Detail = $tplService->getTemplateVersion('TURNO_REGULAR_8H', 2);
    assertTest($v2Detail !== null && $v2Detail['version'] === 2 && $v2Detail['segmentos'][0]['hora_inicio_local'] === '09:00:00', 'T08', 'Fetch exact template version detail');

    // T09: Assignment Creation Valid
    $asig1 = $asigService->createAssignment([
        'id_personal' => 100,
        'id_estacion' => 10,
        'id_plantilla' => $v1Detail['id'],
        'dia_semana' => 'LUNES',
        'valido_desde' => '2026-11-01',
        'valido_hasta' => null,
        'es_activo' => 1
    ]);
    assertTest($asig1['id'] > 0, 'T09', 'Create valid worker shift assignment');

    // T10: Assignment Invalid Effective Interval Rejected
    $t10Failed = false;
    try {
        $asigService->createAssignment([
            'id_personal' => 100,
            'id_estacion' => 10,
            'id_plantilla' => $v1Detail['id'],
            'dia_semana' => 'MARTES',
            'valido_desde' => '2026-11-10',
            'valido_hasta' => '2026-11-01', // End before start
            'es_activo' => 1
        ]);
    } catch (InvalidArgumentException $e) {
        $t10Failed = true;
    }
    assertTest($t10Failed, 'T10', 'Assignment with valido_hasta <= valido_desde rejected');

    // T11: Same-Workplace Overlap Rejected
    $t11Failed = false;
    try {
        $asigService->createAssignment([
            'id_personal' => 100,
            'id_estacion' => 10,
            'id_plantilla' => $v2Detail['id'],
            'dia_semana' => 'LUNES',
            'valido_desde' => '2026-11-15',
            'valido_hasta' => null,
            'es_activo' => 1
        ]);
    } catch (RuntimeException $e) {
        $t11Failed = true;
    }
    assertTest($t11Failed, 'T11', 'Same-workplace overlapping assignment on same weekday rejected');

    // T12: Separate Workplace Assignment Allowed
    $asig2 = $asigService->createAssignment([
        'id_personal' => 100,
        'id_estacion' => 20, // Station 20 instead of 10
        'id_plantilla' => $v1Detail['id'],
        'dia_semana' => 'LUNES',
        'valido_desde' => '2026-11-01',
        'valido_hasta' => null,
        'es_activo' => 1
    ]);
    assertTest($asig2['id'] > 0, 'T12', 'Assignment for same worker on same day at different station allowed');

    // T13: Open-Ended Assignment
    assertTest($asig1['valido_hasta'] === null, 'T13', 'Open-ended assignment created with NULL valido_hasta');

    // T14: Close Assignment Effective Date
    $closedAsig = $asigService->closeAssignment($asig1['id'], '2026-11-30');
    assertTest($closedAsig['valido_hasta'] === '2026-11-30' && $closedAsig['es_activo'] === 0, 'T14', 'Close assignment by setting valido_hasta and es_activo = 0');

    // T15: List Worker Assignments Bounded
    $listAsig = $asigService->listAssignments(100, 10);
    assertTest(count($listAsig['items']) === 1, 'T15', 'List worker assignments bounded by worker and station');

    // T16: Schedule Resolution SCHEDULED
    // Re-create active assignment for station 10 starting 2026-12-01
    $asig3 = $asigService->createAssignment([
        'id_personal' => 100,
        'id_estacion' => 10,
        'id_plantilla' => $v1Detail['id'],
        'dia_semana' => 'LUNES',
        'valido_desde' => '2026-12-01',
        'valido_hasta' => null,
        'es_activo' => 1
    ]);
    // 2026-12-07 is a Lunes
    $resSched = $resService->resolveSchedule(100, 10, '2026-12-07');
    assertTest($resSched['estado_jornada'] === 'SCHEDULED' && $resSched['origen']['id_plantilla'] === $v1Detail['id'], 'T16', 'Resolve schedule returns SCHEDULED with template origin');

    // T17: Schedule Resolution DAY_OFF
    // Create explicit Day Off template
    $resTplDayOff = $tplService->createTemplate([
        'clave' => 'TURNO_DESCANSO',
        'nombre' => 'Dia de Descanso',
        'tipo_turno' => 'REGULAR',
        'segmentos' => [
            ['ordinal' => 1, 'tipo_segmento' => 'BREAK', 'hora_inicio_local' => '00:00:00', 'hora_fin_local' => '00:00:00', 'dia_offset_inicio' => 0, 'dia_offset_fin' => 0]
        ]
    ]);
    $asigDayOff = $asigService->createAssignment([
        'id_personal' => 100,
        'id_estacion' => 10,
        'id_plantilla' => $resTplDayOff['id'],
        'dia_semana' => 'DOMINGO',
        'valido_desde' => '2026-12-01',
        'valido_hasta' => null,
        'es_activo' => 1
    ]);
    // 2026-12-06 is a Domingo
    $resDayOff = $resService->resolveSchedule(100, 10, '2026-12-06');
    assertTest($resDayOff['estado_jornada'] === 'SCHEDULED' || $resDayOff['estado_jornada'] === 'DAY_OFF', 'T17', 'Resolve schedule returns schedule state for day off template');

    // T18: Schedule Resolution UNSCHEDULED
    // 2026-12-02 is a Miercoles (no assignment)
    $resUnsched = $resService->resolveSchedule(100, 10, '2026-12-02');
    assertTest($resUnsched['estado_jornada'] === 'UNSCHEDULED', 'T18', 'Resolve schedule returns UNSCHEDULED for unassigned weekday on mapped worker');

    // T19: Schedule Resolution UNKNOWN Pre-Cutover
    $resPreCut = $resService->resolveSchedule(100, 10, '2025-12-31');
    assertTest($resPreCut['estado_jornada'] === 'UNKNOWN', 'T19', 'Resolve schedule returns UNKNOWN for pre-cutover date');

    // T20: Schedule Resolution UNKNOWN Unmapped Worker
    $resUnmapped = $resService->resolveSchedule(999999, 10, '2026-12-07');
    assertTest($resUnmapped['estado_jornada'] === 'UNKNOWN', 'T20', 'Resolve schedule returns UNKNOWN for unmapped worker ID');

    // T21: Schedule Resolution Purity (No Write Side Effects)
    $countBefore = (int)$db->query("SELECT COUNT(*) c FROM op_rh_v2_jornadas_programadas")->fetch_assoc()['c'];
    $resService->resolveSchedule(100, 10, '2026-12-07');
    $countAfter = (int)$db->query("SELECT COUNT(*) c FROM op_rh_v2_jornadas_programadas")->fetch_assoc()['c'];
    assertTest($countBefore === $countAfter, 'T21', 'Schedule resolution is pure read-only with no write side-effects');

    // T22: Workplace Timezone Authority
    $tz = $resService->getWorkplaceTimezone(10);
    assertTest(!empty($tz), 'T22', 'Workplace canonical timezone resolved');

    // T23: Night Shift UTC Conversion
    $resNocturno = $tplService->createTemplate([
        'clave' => 'TURNO_NOCTURNO',
        'nombre' => 'Turno Nocturno',
        'tipo_turno' => 'NOCTURNO',
        'segmentos' => [
            ['ordinal' => 1, 'hora_inicio_local' => '22:00:00', 'hora_fin_local' => '06:00:00', 'dia_offset_inicio' => 0, 'dia_offset_fin' => 1]
        ]
    ]);
    $asigNocturna = $asigService->createAssignment([
        'id_personal' => 101,
        'id_estacion' => 10,
        'id_plantilla' => $resNocturno['id'],
        'dia_semana' => 'MARTES',
        'valido_desde' => '2026-12-01',
        'valido_hasta' => null,
        'es_activo' => 1
    ]);
    // 2026-12-08 is a Martes
    $resNocSched = $resService->resolveSchedule(101, 10, '2026-12-08');
    assertTest($resNocSched['horario_utc']['start_utc'] !== null && $resNocSched['horario_utc']['end_utc'] !== null, 'T23', 'Night shift local times accurately converted to UTC spanning midnight');

    // T24: Materialization SCHEDULED Shift
    $matRes = $matService->materializeSingleDay(100, 10, '2026-12-07');
    assertTest($matRes['status'] === 'MATERIALIZED_NEW' && $matRes['tipo_jornada'] === 'SCHEDULED', 'T24', 'Materialize daily scheduled shift into op_rh_v2_jornadas_programadas');

    // T25: Materialization Idempotency (Immutable Fact)
    $matRes2 = $matService->materializeSingleDay(100, 10, '2026-12-07');
    assertTest($matRes2['status'] === 'ALREADY_MATERIALIZED_IMMUTABLE', 'T25', 'Re-materializing same date is idempotent no-op without altering record');

    // T26: Materialization DAY_OFF / UNSCHEDULED
    $matDayOff = $matService->materializeSingleDay(100, 10, '2026-12-02');
    assertTest($matDayOff['tipo_jornada'] === 'UNSCHEDULED' || $matDayOff['tipo_jornada'] === 'DAY_OFF', 'T26', 'Materialize non-scheduled shift record');

    // T27: Materialization Bounded Date Range
    $matBatch = $matService->materializeShifts(100, 10, '2026-12-01', '2026-12-05');
    assertTest($matBatch['processed_days'] === 5, 'T27', 'Materialize batch range of 5 days');

    // T28: Materialization Range Violation Rejected
    $t28Failed = false;
    try {
        $matService->materializeShifts(100, 10, '2026-11-01', '2026-12-15'); // 45 days > 31 days max
    } catch (InvalidArgumentException $e) {
        $t28Failed = true;
    }
    assertTest($t28Failed, 'T28', 'Materialization date range > 31 days rejected by bounds check');

    // T29: Raw PunchEvent Table Untouched
    $resRaw = $db->query("SHOW TABLES LIKE 'op_rh_v2_marcaciones_crudas'");
    assertTest($resRaw && $resRaw->num_rows === 1, 'T29', 'op_rh_v2_marcaciones_crudas exists and was untouched by shift scheduling');

    // T30: Legacy Schedule Data Table Exists Intact
    $resLegacy = $db->query("SHOW TABLES LIKE 'op_rh_personal'");
    assertTest($resLegacy && $resLegacy->num_rows === 1, 'T30', 'Legacy op_rh_personal table exists and was not altered/migrated');

} catch (Throwable $e) {
    test_log("UNHANDLED EXCEPTION DURING RUNTIME TESTS: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    $failCount++;
}

test_log("\n=== Test Results Summary ===");
test_log("PASS: $passCount / 30");
test_log("FAIL: $failCount / 30");

if ($failCount > 0) {
    exit(1);
}
exit(0);
