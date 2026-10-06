<?php
/**
 * Checador V2 — V2.3-A03 Shift Scheduling Schema Test Suite
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
    
    $queries = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($q) => !empty($q)
    );

    foreach ($queries as $query) {
        $lines = array_filter(explode("\n", $query), function($line) {
            $trimmed = trim($line);
            return !empty($trimmed) && strpos($trimmed, '--') !== 0;
        });
        if (empty($lines)) {
            continue;
        }
        $cleanQuery = implode("\n", $lines);
        if (!$db->query($cleanQuery)) {
            throw new RuntimeException("SQL execution error: " . $db->error . "\nQuery: " . $cleanQuery);
        }
    }
}

$upFile = __DIR__ . '/../migraciones/v2/2026_10_06_000001_create_v2_shift_scheduling_tables.sql';
$downFile = __DIR__ . '/../migraciones/v2/2026_10_06_000001_rollback_v2_shift_scheduling_tables.sql';

test_log("=== Starting V2.3-A03 Schema Validation Test Suite ===");

// 1. Rollback setup to ensure clean state
test_log("Executing DOWN rollback migration to clear existing tables if any...");
run_sql_script($conexion, $downFile);

// 2. Execute UP migration
test_log("Executing UP migration...");
run_sql_script($conexion, $upFile);
test_log("UP migration completed successfully.");

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

try {
    // T01: Table creation verification
    $tables = [
        'op_rh_v2_turnos_plantillas',
        'op_rh_v2_turnos_segmentos',
        'op_rh_v2_turnos_asignaciones',
        'op_rh_v2_jornadas_programadas'
    ];
    $tablesExist = true;
    foreach ($tables as $tbl) {
        $res = $conexion->query("SHOW TABLES LIKE '$tbl'");
        if (!$res || $res->num_rows === 0) {
            $tablesExist = false;
            break;
        }
    }
    assertTest($tablesExist, 'T01', 'All 4 schema tables created in database');

    // T02: Foreign Key Constraint op_rh_v2_turnos_segmentos -> op_rh_v2_turnos_plantillas
    $fkRejected = false;
    try {
        $conexion->query("INSERT INTO op_rh_v2_turnos_segmentos (id_plantilla, ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin) VALUES (999999, 1, 'WORK', '08:00:00', '16:00:00', 0, 0)");
    } catch (mysqli_sql_exception $e) {
        $fkRejected = true;
    }
    assertTest($fkRejected, 'T02', 'FK constraint rejects segment insertion with non-existent plantilla_id');

    // T03: Template insertion (Normal day shift)
    $resTemplate = $conexion->query("INSERT INTO op_rh_v2_turnos_plantillas (clave, version, nombre, tipo_turno, es_activo) VALUES ('TURNO_DIURNO_8H', 1, 'Turno Diurno 8 Horas', 'REGULAR', 1)");
    $idPlantilla1 = (int)$conexion->insert_id;
    assertTest($resTemplate && $idPlantilla1 > 0, 'T03', 'Insert valid shift template');

    // T04: Unique key rejection for (clave, version)
    $ukRejected = false;
    try {
        $conexion->query("INSERT INTO op_rh_v2_turnos_plantillas (clave, version, nombre, tipo_turno, es_activo) VALUES ('TURNO_DIURNO_8H', 1, 'Turno Duplicado', 'REGULAR', 1)");
    } catch (mysqli_sql_exception $e) {
        $ukRejected = true;
    }
    assertTest($ukRejected, 'T04', 'Duplicate (clave, version) rejected by UK constraint');

    // T05: Valid Segment insertion
    $resSeg = $conexion->query("INSERT INTO op_rh_v2_turnos_segmentos (id_plantilla, ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin) VALUES ($idPlantilla1, 1, 'WORK', '08:00:00', '16:00:00', 0, 0)");
    assertTest($resSeg === true, 'T05', 'Insert valid shift segment');

    // T06: Duplicate segment ordinal rejection
    $dupOrdinalRejected = false;
    try {
        $conexion->query("INSERT INTO op_rh_v2_turnos_segmentos (id_plantilla, ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin) VALUES ($idPlantilla1, 1, 'WORK', '08:00:00', '16:00:00', 0, 0)");
    } catch (mysqli_sql_exception $e) {
        $dupOrdinalRejected = true;
    }
    assertTest($dupOrdinalRejected, 'T06', 'Duplicate segment ordinal for same template rejected by UK');

    // T07: Template insertion (Overnight shift)
    $resTemplateNocturno = $conexion->query("INSERT INTO op_rh_v2_turnos_plantillas (clave, version, nombre, tipo_turno, es_activo) VALUES ('TURNO_NOCTURNO_8H', 1, 'Turno Nocturno 8 Horas', 'NOCTURNO', 1)");
    $idPlantillaNocturna = (int)$conexion->insert_id;
    assertTest($resTemplateNocturno && $idPlantillaNocturna > 0, 'T07', 'Insert valid overnight shift template');

    // T08: Valid overnight segment insertion (dia_offset_fin = 1)
    $resSegNocturno = $conexion->query("INSERT INTO op_rh_v2_turnos_segmentos (id_plantilla, ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin) VALUES ($idPlantillaNocturna, 1, 'WORK', '22:00:00', '06:00:00', 0, 1)");
    assertTest($resSegNocturno === true, 'T08', 'Insert overnight segment with dia_offset_fin = 1');

    // T09: Multi-segment template setup (Split shift)
    $resTemplateMixto = $conexion->query("INSERT INTO op_rh_v2_turnos_plantillas (clave, version, nombre, tipo_turno, es_activo) VALUES ('TURNO_PARTIDO', 1, 'Turno Partido', 'MIXTO', 1)");
    $idPlantillaMixta = (int)$conexion->insert_id;
    $s1 = $conexion->query("INSERT INTO op_rh_v2_turnos_segmentos (id_plantilla, ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin) VALUES ($idPlantillaMixta, 1, 'WORK', '08:00:00', '12:00:00', 0, 0)");
    $s2 = $conexion->query("INSERT INTO op_rh_v2_turnos_segmentos (id_plantilla, ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin) VALUES ($idPlantillaMixta, 2, 'WORK', '14:00:00', '18:00:00', 0, 0)");
    assertTest($resTemplateMixto && $s1 && $s2, 'T09', 'Multi-segment split shift template created with ordinals 1 and 2');

    // T10: Shift Assignment Insertion (Valid worker + workplace)
    $resAsig = $conexion->query("INSERT INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo) VALUES (1, 10, $idPlantilla1, 'LUNES', '2026-10-01', '2026-10-31', 1)");
    $idAsignacion = (int)$conexion->insert_id;
    assertTest($resAsig && $idAsignacion > 0, 'T10', 'Insert valid shift assignment');

    // T11: Assignment with NULL valido_hasta (Indefinite assignment)
    $resAsigIndef = $conexion->query("INSERT INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo) VALUES (2, 10, $idPlantilla1, 'MARTES', '2026-10-01', NULL, 1)");
    assertTest($resAsigIndef === true, 'T11', 'Insert valid assignment with NULL valido_hasta (indefinite)');

    // T12: FK constraint op_rh_v2_turnos_asignaciones -> op_rh_v2_turnos_plantillas
    $asigFkRejected = false;
    try {
        $conexion->query("INSERT INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo) VALUES (1, 10, 999999, 'MIERCOLES', '2026-10-01', NULL, 1)");
    } catch (mysqli_sql_exception $e) {
        $asigFkRejected = true;
    }
    assertTest($asigFkRejected, 'T12', 'Assignment FK constraint rejects non-existent plantilla_id');

    // T13: ScheduledShift Materialization Insertion (Normal Day)
    $resJornada = $conexion->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, id_plantilla_origen, id_asignacion_origen, tipo_jornada, scheduled_start_utc, scheduled_end_utc, timezone_local, payload_segmentos_json) VALUES (1, 10, '2026-10-06', $idPlantilla1, $idAsignacion, 'SCHEDULED', '2026-10-06 14:00:00', '2026-10-06 22:00:00', 'America/Mexico_City', '[{\"ordinal\": 1, \"tipo\": \"WORK\"}]')");
    $idJornada1 = (int)$conexion->insert_id;
    assertTest($resJornada && $idJornada1 > 0, 'T13', 'Insert valid materialized ScheduledShift for regular day');

    // T14: Duplicate ScheduledShift rejection (uk_v2_jornada_personal_estacion_fecha)
    $dupJornadaRejected = false;
    try {
        $conexion->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, id_plantilla_origen, id_asignacion_origen, tipo_jornada, scheduled_start_utc, scheduled_end_utc, timezone_local) VALUES (1, 10, '2026-10-06', $idPlantilla1, $idAsignacion, 'SCHEDULED', '2026-10-06 14:00:00', '2026-10-06 22:00:00', 'America/Mexico_City')");
    } catch (mysqli_sql_exception $e) {
        $dupJornadaRejected = true;
    }
    assertTest($dupJornadaRejected, 'T14', 'Duplicate ScheduledShift for (id_personal, id_estacion, fecha_jornada_local) rejected by UK');

    // T15: Multi-workplace ScheduledShift insertion for same worker on same local date
    $resJornadaEst2 = $conexion->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, id_plantilla_origen, id_asignacion_origen, tipo_jornada, scheduled_start_utc, scheduled_end_utc, timezone_local) VALUES (1, 20, '2026-10-06', $idPlantilla1, $idAsignacion, 'SCHEDULED', '2026-10-06 23:00:00', '2026-10-07 03:00:00', 'America/Mexico_City')");
    assertTest($resJornadaEst2 === true, 'T15', 'Allow multi-workplace ScheduledShift for same worker on same date at different station (id_estacion=20)');

    // T16: Overnight ScheduledShift Materialization (Spans midnight UTC/local representation)
    $resJornadaNocturna = $conexion->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, id_plantilla_origen, id_asignacion_origen, tipo_jornada, scheduled_start_utc, scheduled_end_utc, timezone_local) VALUES (2, 10, '2026-10-06', $idPlantillaNocturna, NULL, 'SCHEDULED', '2026-10-07 04:00:00', '2026-10-07 12:00:00', 'America/Mexico_City')");
    assertTest($resJornadaNocturna === true, 'T16', 'Insert overnight ScheduledShift with start/end UTC spanning midnight');

    // T17: ScheduledShift Day Off Materialization (tipo_jornada = DAY_OFF, start/end NULL)
    $resDescanso = $conexion->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, id_plantilla_origen, id_asignacion_origen, tipo_jornada, scheduled_start_utc, scheduled_end_utc, timezone_local) VALUES (3, 10, '2026-10-06', NULL, NULL, 'DAY_OFF', NULL, NULL, 'America/Mexico_City')");
    assertTest($resDescanso === true, 'T17', 'Insert ScheduledShift for Day Off (tipo_jornada=DAY_OFF, start/end=NULL)');

    // T18: ScheduledShift Null Origin Traceability (Manual / override shift)
    $resManual = $conexion->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, id_plantilla_origen, id_asignacion_origen, tipo_jornada, scheduled_start_utc, scheduled_end_utc, timezone_local) VALUES (4, 10, '2026-10-06', NULL, NULL, 'SCHEDULED', '2026-10-06 15:00:00', '2026-10-06 23:00:00', 'America/Mexico_City')");
    assertTest($resManual === true, 'T18', 'Insert manual ScheduledShift with NULL plantilla/asignacion origin');

    // T19: Down Rollback Migration Execution
    test_log("Executing DOWN rollback migration to verify drop sequence...");
    run_sql_script($conexion, $downFile);
    $tablesDropped = true;
    foreach ($tables as $tbl) {
        $res = $conexion->query("SHOW TABLES LIKE '$tbl'");
        if ($res && $res->num_rows > 0) {
            $tablesDropped = false;
            break;
        }
    }
    assertTest($tablesDropped, 'T19', 'DOWN rollback migration cleanly dropped all 4 tables without FK errors');

    // T20: UP Re-execution after DOWN rollback (Idempotency / clean re-install check)
    test_log("Re-executing UP migration after rollback to verify clean re-install...");
    run_sql_script($conexion, $upFile);
    $tablesRecreated = true;
    foreach ($tables as $tbl) {
        $res = $conexion->query("SHOW TABLES LIKE '$tbl'");
        if (!$res || $res->num_rows === 0) {
            $tablesRecreated = false;
            break;
        }
    }
    assertTest($tablesRecreated, 'T20', 'UP migration re-executed cleanly after rollback');

} catch (Throwable $e) {
    test_log("UNHANDLED EXCEPTION DURING TESTS: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    $failCount++;
}

test_log("\n=== Test Results Summary ===");
test_log("PASS: $passCount / 20");
test_log("FAIL: $failCount / 20");

if ($failCount > 0) {
    exit(1);
}
exit(0);
