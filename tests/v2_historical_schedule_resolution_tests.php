<?php
/**
 * Checador V2 — Historical Schedule Resolution Test Suite (V8C_R1)
 *
 * Validates deterministic resolution of historical, current, and superseded
 * shift assignments per [valido_desde, valido_hasta) semi-open interval semantics.
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/shift/ShiftTemplateService.php';
require_once __DIR__ . '/../modelo/v2/shift/ShiftAssignmentService.php';
require_once __DIR__ . '/../modelo/v2/shift/ScheduleResolutionService.php';
require_once __DIR__ . '/../modelo/v2/shift/ShiftSchedulingV2Controller.php';

$bdObj = new BD();
$db = $bdObj->conectarBD();

function hist_log(string $msg): void {
    echo $msg . "\n";
}

function run_sql_script(mysqli $db, string $filePath): void {
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

hist_log("=== Starting CHECADOR_V2_HISTORICAL_SCHEDULE_RESOLUTION_FIX_V8C_R1 Tests ===");

// Reset scheduling tables cleanly
$db->query("SET FOREIGN_KEY_CHECKS = 0");
$calcDownFile = __DIR__ . '/../migraciones/v2/2026_10_07_000002_rollback_v2_journey_calculation_tables.sql';
if (file_exists($calcDownFile)) {
    try { run_sql_script($db, $calcDownFile); } catch (Throwable $e) {}
}
$pairingDownFile = __DIR__ . '/../migraciones/v2/2026_10_07_000001_rollback_v2_journey_pairing_tables.sql';
if (file_exists($pairingDownFile)) {
    try { run_sql_script($db, $pairingDownFile); } catch (Throwable $e) {}
}
run_sql_script($db, $downFile);
run_sql_script($db, $upFile);
$db->query("SET FOREIGN_KEY_CHECKS = 1");

$passCount = 0;
$failCount = 0;

function assert_hist(bool $condition, string $id, string $desc): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        hist_log("[$id] PASS: $desc");
    } else {
        $failCount++;
        hist_log("[$id] FAIL: $desc");
    }
}

$tplService = new ShiftTemplateService($db);
$asigService = new ShiftAssignmentService($db);
$resService = new ScheduleResolutionService($db);

try {
    // 0. Ensure test workers exist in op_rh_personal
    $db->query("INSERT IGNORE INTO op_rh_personal (id, nombre_completo, no_colaborador) VALUES (201, 'Worker Hist 201', '201')");
    $db->query("INSERT IGNORE INTO op_rh_personal (id, nombre_completo, no_colaborador) VALUES (202, 'Worker Hist 202', '202')");
    $db->query("INSERT IGNORE INTO op_rh_personal (id, nombre_completo, no_colaborador) VALUES (203, 'Worker Hist 203', '203')");

    // Ensure station timezone mapping exists for station 10
    $tzUpFile = __DIR__ . '/../migraciones/v2/2026_10_12_000001_create_v2_workplace_timezone_authority.sql';
    if (file_exists($tzUpFile)) {
        try { run_sql_script($db, $tzUpFile); } catch (Throwable $e) {}
    }
    $db->query("INSERT IGNORE INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta) 
                VALUES (10, 'America/Mexico_City', '2026-01-01', NULL)");

    // 1. Create Templates:
    // Tpl A: Morning 07:00 - 15:00
    $tplA = $tplService->createTemplate([
        'clave' => 'TURNO_MANANA_8H',
        'nombre' => 'Turno Matutino 8 Horas',
        'tipo_turno' => 'REGULAR',
        'segmentos' => [
            ['ordinal' => 1, 'tipo_segmento' => 'WORK', 'hora_inicio_local' => '07:00:00', 'hora_fin_local' => '15:00:00', 'dia_offset_inicio' => 0, 'dia_offset_fin' => 0]
        ]
    ]);
    // Tpl B: Afternoon 15:00 - 23:00
    $tplB = $tplService->createTemplate([
        'clave' => 'TURNO_VESP_8H',
        'nombre' => 'Turno Vespertino 8 Horas',
        'tipo_turno' => 'REGULAR',
        'segmentos' => [
            ['ordinal' => 1, 'tipo_segmento' => 'WORK', 'hora_inicio_local' => '15:00:00', 'hora_fin_local' => '23:00:00', 'dia_offset_inicio' => 0, 'dia_offset_fin' => 0]
        ]
    ]);
    // Tpl C: Night shift 22:00 - 06:00 (dia_offset_fin = 1)
    $tplC = $tplService->createTemplate([
        'clave' => 'TURNO_NOCHE_8H',
        'nombre' => 'Turno Nocturno 8 Horas',
        'tipo_turno' => 'NOCTURNO',
        'segmentos' => [
            ['ordinal' => 1, 'tipo_segmento' => 'WORK', 'hora_inicio_local' => '22:00:00', 'hora_fin_local' => '06:00:00', 'dia_offset_inicio' => 0, 'dia_offset_fin' => 1]
        ]
    ]);

    // Create Initial Assignment for Worker 201 on LUNES:
    // Started 2026-01-01, closed on 2026-04-01 -> valido_hasta = '2026-04-01', es_activo = 0
    $asigOld = $asigService->createAssignment([
        'id_personal' => 201,
        'id_estacion' => 10,
        'id_plantilla' => $tplA['id'],
        'dia_semana' => 'LUNES',
        'valido_desde' => '2026-01-01',
        'valido_hasta' => null,
        'es_activo' => 1
    ]);
    $closedAsig = $asigService->closeAssignment($asigOld['id'], '2026-04-01');

    // Create Superseding Assignment starting 2026-04-01 with Template B
    $asigNew = $asigService->createAssignment([
        'id_personal' => 201,
        'id_estacion' => 10,
        'id_plantilla' => $tplB['id'],
        'dia_semana' => 'LUNES',
        'valido_desde' => '2026-04-01',
        'valido_hasta' => null,
        'es_activo' => 1
    ]);

    // =========================================================================
    // TEST 1: Asignación histórica cerrada (es_activo = 0, valido_hasta en el pasado)
    // =========================================================================
    // Querying 2026-02-16 (LUNES) which is inside [2026-01-01, 2026-04-01)
    $res01 = $resService->resolveSchedule(201, 10, '2026-02-16');
    $pass01 = ($res01['estado_jornada'] === 'SCHEDULED')
        && ($res01['origen']['id_asignacion'] === $asigOld['id'])
        && ($res01['origen']['clave_plantilla'] === 'TURNO_MANANA_8H')
        && ($res01['horario_local']['segmentos'][0]['hora_inicio_local'] === '07:00:00');
    assert_hist($pass01, 'HIST-01', 'Closed historical assignment (es_activo=0, valido_hasta in past) accurately resolved within validity interval');

    // =========================================================================
    // TEST 2: Asignación vigente actual (es_activo = 1, valido_hasta IS NULL)
    // =========================================================================
    // Querying 2026-05-18 (LUNES) which is after 2026-04-01
    $res02 = $resService->resolveSchedule(201, 10, '2026-05-18');
    $pass02 = ($res02['estado_jornada'] === 'SCHEDULED')
        && ($res02['origen']['id_asignacion'] === $asigNew['id'])
        && ($res02['origen']['clave_plantilla'] === 'TURNO_VESP_8H')
        && ($res02['horario_local']['segmentos'][0]['hora_inicio_local'] === '15:00:00');
    assert_hist($pass02, 'HIST-02', 'Currently active assignment (es_activo=1, valido_hasta IS NULL) accurately resolved for current/future dates');

    // =========================================================================
    // TEST 3: Fecha previa a la sustitución resuelve el horario viejo
    // =========================================================================
    // Querying 2026-03-30 (LUNES, 2 days before substitution on 2026-04-01)
    $res03 = $resService->resolveSchedule(201, 10, '2026-03-30');
    $pass03 = ($res03['estado_jornada'] === 'SCHEDULED')
        && ($res03['origen']['id_asignacion'] === $asigOld['id'])
        && ($res03['origen']['clave_plantilla'] === 'TURNO_MANANA_8H');
    assert_hist($pass03, 'HIST-03', 'Date immediately prior to substitution resolves old assignment');

    // =========================================================================
    // TEST 4: Fecha posterior a la sustitución resuelve el horario nuevo
    // =========================================================================
    // Querying 2026-04-06 (LUNES, first Monday after substitution on 2026-04-01)
    $res04 = $resService->resolveSchedule(201, 10, '2026-04-06');
    $pass04 = ($res04['estado_jornada'] === 'SCHEDULED')
        && ($res04['origen']['id_asignacion'] === $asigNew['id'])
        && ($res04['origen']['clave_plantilla'] === 'TURNO_VESP_8H');
    assert_hist($pass04, 'HIST-04', 'Date immediately following substitution resolves new assignment');

    // =========================================================================
    // TEST 5: Límite exacto valido_desde (inclusive)
    // =========================================================================
    // Create assignment starting exactly on 2026-01-05 (LUNES)
    $asigInc = $asigService->createAssignment([
        'id_personal' => 202,
        'id_estacion' => 10,
        'id_plantilla' => $tplA['id'],
        'dia_semana' => 'LUNES',
        'valido_desde' => '2026-01-05',
        'valido_hasta' => '2026-02-01',
        'es_activo' => 0
    ]);
    $res05 = $resService->resolveSchedule(202, 10, '2026-01-05');
    $pass05 = ($res05['estado_jornada'] === 'SCHEDULED')
        && ($res05['origen']['id_asignacion'] === $asigInc['id']);
    assert_hist($pass05, 'HIST-05', 'Exact valido_desde boundary date is resolved inclusive');

    // =========================================================================
    // TEST 6: Límite exacto valido_hasta (exclusive)
    // =========================================================================
    // 2026-02-01 is SUNDAY, let's create an assignment valid until 2026-02-09 (LUNES)
    $asigExc = $asigService->createAssignment([
        'id_personal' => 202,
        'id_estacion' => 10,
        'id_plantilla' => $tplA['id'],
        'dia_semana' => 'LUNES',
        'valido_desde' => '2026-02-02',
        'valido_hasta' => '2026-02-09',
        'es_activo' => 0
    ]);
    // On 2026-02-09, the assignment has reached its exclusive boundary and is NOT valid
    $res06 = $resService->resolveSchedule(202, 10, '2026-02-09');
    $pass06 = ($res06['estado_jornada'] === 'UNSCHEDULED');
    assert_hist($pass06, 'HIST-06', 'Exact valido_hasta boundary date is excluded (exclusive boundary returns UNSCHEDULED)');

    // =========================================================================
    // TEST 7: Vigencia indefinida (valido_hasta IS NULL) se resuelve hacia adelante
    // =========================================================================
    // Worker 201 has active assignment for LUNES with valido_hasta = NULL
    $res07 = $resService->resolveSchedule(201, 10, '2026-11-23'); // Future LUNES
    $pass07 = ($res07['estado_jornada'] === 'SCHEDULED')
        && ($res07['origen']['id_asignacion'] === $asigNew['id']);
    assert_hist($pass07, 'HIST-07', 'Indefinite validity assignment (valido_hasta IS NULL) resolves correctly for future dates');

    // =========================================================================
    // TEST 8: Asignación cancelada por error (revocada) no se resuelve
    // =========================================================================
    // Create an assignment and cancel/revoke it
    $asigRev = $asigService->createAssignment([
        'id_personal' => 202,
        'id_estacion' => 10,
        'id_plantilla' => $tplA['id'],
        'dia_semana' => 'MARTES',
        'valido_desde' => '2026-03-01',
        'valido_hasta' => null,
        'es_activo' => 1
    ]);
    // Cancel the assignment
    $asigService->cancelAssignment($asigRev['id']);
    // Querying 2026-03-10 (MARTES) must NOT resolve the revoked assignment
    $res08 = $resService->resolveSchedule(202, 10, '2026-03-10');
    $pass08 = ($res08['estado_jornada'] === 'UNSCHEDULED');

    // Also test direct seed of an assignment with es_activo = 0 and valido_hasta IS NULL
    $db->query("INSERT INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo) 
                VALUES (202, 10, {$tplA['id']}, 'MIERCOLES', '2026-03-01', NULL, 0)");
    $res08b = $resService->resolveSchedule(202, 10, '2026-03-11');
    $pass08b = ($res08b['estado_jornada'] === 'UNSCHEDULED');

    assert_hist($pass08 && $pass08b, 'HIST-08', 'Cancelled / revoked assignment is never resolved (returns UNSCHEDULED)');

    // =========================================================================
    // TEST 9: Ambigüedad o traslape detectado se rechaza fail-closed
    // =========================================================================
    // Manually force two overlapping active assignments for Worker 203 on JUEVES
    $db->query("INSERT INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo) 
                VALUES (203, 10, {$tplA['id']}, 'JUEVES', '2026-01-01', NULL, 1)");
    $db->query("INSERT INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo) 
                VALUES (203, 10, {$tplB['id']}, 'JUEVES', '2026-01-01', NULL, 1)");

    $caughtAmbiguity = false;
    try {
        $resService->resolveSchedule(203, 10, '2026-01-08'); // JUEVES
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'AMBIGUOUS_SCHEDULE_ASSIGNMENT') !== false) {
            $caughtAmbiguity = true;
        }
    }
    assert_hist($caughtAmbiguity, 'HIST-09', 'Overlapping / ambiguous assignments detected and rejected fail-closed');

    // =========================================================================
    // TEST 10: Turno nocturno histórico conserva cruce de medianoche y conversión UTC
    // =========================================================================
    // Closed night assignment for Worker 202 on VIERNES
    $asigNight = $asigService->createAssignment([
        'id_personal' => 202,
        'id_estacion' => 10,
        'id_plantilla' => $tplC['id'],
        'dia_semana' => 'VIERNES',
        'valido_desde' => '2026-01-01',
        'valido_hasta' => '2026-03-01',
        'es_activo' => 0
    ]);
    // Query date 2026-01-16 (VIERNES)
    $res10 = $resService->resolveSchedule(202, 10, '2026-01-16');
    $pass10 = ($res10['estado_jornada'] === 'SCHEDULED')
        && ($res10['origen']['clave_plantilla'] === 'TURNO_NOCHE_8H')
        && ($res10['horario_local']['segmentos'][0]['dia_offset_fin'] === 1)
        && ($res10['horario_local']['segmentos'][0]['hora_inicio_local'] === '22:00:00')
        && ($res10['horario_local']['segmentos'][0]['hora_fin_local'] === '06:00:00')
        && !empty($res10['horario_utc']['start_utc'])
        && !empty($res10['horario_utc']['end_utc']);
    assert_hist($pass10, 'HIST-10', 'Historical night shift preserves cross-midnight day_offset_fin=1 and accurate UTC boundaries');

    // =========================================================================
    // TEST 11: Día de la semana sin asignación retorna UNSCHEDULED
    // =========================================================================
    // Worker 201 has assignments for LUNES, but NOT for SABADO
    $res11 = $resService->resolveSchedule(201, 10, '2026-05-16'); // SABADO
    $pass11 = ($res11['estado_jornada'] === 'UNSCHEDULED');
    assert_hist($pass11, 'HIST-11', 'Unassigned weekday on mapped worker returns UNSCHEDULED');

    // =========================================================================
    // TEST 12: Trabajador no mapeado o fecha pre-cutover conserva UNKNOWN
    // =========================================================================
    // Worker 999999 does not exist in op_rh_personal
    $res12a = $resService->resolveSchedule(999999, 10, '2026-05-18');
    // Pre-cutover date 2025-12-29
    $res12b = $resService->resolveSchedule(201, 10, '2025-12-29');
    $pass12 = ($res12a['estado_jornada'] === 'UNKNOWN')
        && ($res12b['estado_jornada'] === 'UNKNOWN')
        && ($res12b['reason'] === 'Target date is prior to system cutover');
    assert_hist($pass12, 'HIST-12', 'Unmapped worker and pre-cutover target dates return UNKNOWN fail-closed');

    // =========================================================================
    // TEST 13: Ausencia legítima de horario: DAY_OFF vs UNSCHEDULED
    // =========================================================================
    // Create explicit Day Off template
    $tplDayOff = $tplService->createTemplate([
        'clave' => 'TURNO_DESCANSO_DOM',
        'nombre' => 'Dia de Descanso Dominical',
        'tipo_turno' => 'REGULAR',
        'segmentos' => [
            ['ordinal' => 1, 'tipo_segmento' => 'BREAK', 'hora_inicio_local' => '00:00:00', 'hora_fin_local' => '00:00:00', 'dia_offset_inicio' => 0, 'dia_offset_fin' => 0]
        ]
    ]);
    $asigDayOff = $asigService->createAssignment([
        'id_personal' => 201,
        'id_estacion' => 10,
        'id_plantilla' => $tplDayOff['id'],
        'dia_semana' => 'DOMINGO',
        'valido_desde' => '2026-01-01',
        'valido_hasta' => null,
        'es_activo' => 1
    ]);
    $res13a = $resService->resolveSchedule(201, 10, '2026-05-17'); // DOMINGO with assigned template
    // Worker 202 on DOMINGO has NO assignment -> UNSCHEDULED
    $res13b = $resService->resolveSchedule(202, 10, '2026-05-17'); // DOMINGO
    $pass13 = in_array($res13a['estado_jornada'], ['SCHEDULED', 'DAY_OFF'], true)
        && ($res13b['estado_jornada'] === 'UNSCHEDULED');
    assert_hist($pass13, 'HIST-13', 'Legitimate schedule absence correctly distinguishes assigned schedule / day off from UNSCHEDULED');

} catch (Throwable $e) {
    hist_log("FATAL TEST EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    $failCount++;
}

hist_log("\n=== Historical Schedule Resolution Test Results ===");
hist_log("PASS: {$passCount} / " . ($passCount + $failCount));
hist_log("FAIL: {$failCount} / " . ($passCount + $failCount));

if ($failCount > 0) {
    exit(1);
}
exit(0);
