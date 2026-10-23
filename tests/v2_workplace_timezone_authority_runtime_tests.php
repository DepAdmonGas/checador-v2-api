<?php
/**
 * Checador V2 — Workplace timezone authority isolated runtime tests.
 * Creates and removes a uniquely named disposable database; no business
 * locality or timezone mapping is written to a persistent environment.
 */

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../modelo/v2/shift/WorkplaceTimezoneAuthorityService.php';
require_once __DIR__ . '/../modelo/v2/shift/ShiftTemplateService.php';
require_once __DIR__ . '/../modelo/v2/shift/ShiftAssignmentService.php';
require_once __DIR__ . '/../modelo/v2/shift/ScheduleResolutionService.php';
require_once __DIR__ . '/../modelo/v2/shift/ScheduledShiftMaterializationService.php';

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : 'kimera';
$dbName = 'checador_v2_tmp_timezone_authority_20261012_' . getmypid();
$admin = null;
$db = null;
$passCount = 0;
$failCount = 0;
$removed = false;

function tz_log(string $message): void { echo $message . "\n"; }
function tz_assert(bool $condition, string $id, string $message): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        tz_log("[$id] PASS: $message");
    } else {
        $failCount++;
        tz_log("[$id] FAIL: $message");
    }
}
function executeStatements(mysqli $db, string $sql): void {
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $lines = array_filter(explode("\n", $statement), static function (string $line): bool {
            return trim($line) !== '' && strpos(ltrim($line), '--') !== 0;
        });
        $statement = trim(implode("\n", $lines));
        if ($statement !== '') { $db->query($statement); }
    }
}
function runSqlFile(mysqli $db, string $path): void { executeStatements($db, (string)file_get_contents($path)); }
function expectUnresolved(callable $callback): bool {
    try { $callback(); } catch (Throwable $e) { return $e->getMessage() === 'WORKPLACE_TIMEZONE_UNRESOLVED'; }
    return false;
}

try {
    tz_log('TEST_DATABASE: ' . $dbName);
    $admin = new mysqli($host, $user, $pass);
    $identity = $admin->query('SELECT VERSION() AS version, DATABASE() AS db, @@hostname AS host, @@port AS port')->fetch_assoc();
    tz_assert(($identity['db'] ?? null) === null, 'DB01', 'Admin connection has no selected persistent database');
    $admin->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($host, $user, $pass, $dbName);
    $current = $db->query('SELECT DATABASE() AS current_db')->fetch_assoc()['current_db'] ?? '';
    tz_assert($current === $dbName, 'DB02', 'Disposable database identity is exact before DDL/DML');

    $db->query("CREATE TABLE op_rh_localidades (id INT(11) NOT NULL AUTO_INCREMENT, numlista INT NULL, localidad VARCHAR(128) NULL, PRIMARY KEY (id)) ENGINE=InnoDB");
    $db->query("CREATE TABLE op_rh_personal (id INT(11) NOT NULL, nombre_completo VARCHAR(128) NULL, PRIMARY KEY (id)) ENGINE=InnoDB");
    $db->query("CREATE TABLE op_rh_v2_dispositivos (id INT(11) NOT NULL, id_estacion INT(11) NOT NULL, timezone VARCHAR(50) NULL, PRIMARY KEY (id)) ENGINE=InnoDB");
    for ($id = 201; $id <= 209; $id++) {
        $db->query("INSERT INTO op_rh_localidades (id, numlista, localidad) VALUES ({$id}, {$id}, 'Synthetic {$id}')");
    }
    runSqlFile($db, __DIR__ . '/../migraciones/v2/2026_10_06_000001_create_v2_shift_scheduling_tables.sql');
    runSqlFile($db, __DIR__ . '/../migraciones/v2/2026_10_12_000001_create_v2_workplace_timezone_authority.sql');
    runSqlFile($db, __DIR__ . '/../migraciones/v2/2026_10_12_000001_create_v2_workplace_timezone_authority.sql');

    $table = $db->query("SHOW TABLE STATUS LIKE 'op_rh_v2_localidades_zonas_horarias'")->fetch_assoc();
    $columns = [];
    $columnResult = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = 'op_rh_v2_localidades_zonas_horarias'");
    while ($row = $columnResult->fetch_assoc()) { $columns[$row['COLUMN_NAME']] = $row; }
    $indexes = [];
    $indexResult = $db->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '{$dbName}' AND TABLE_NAME = 'op_rh_v2_localidades_zonas_horarias'");
    while ($row = $indexResult->fetch_assoc()) { $indexes[] = $row['INDEX_NAME']; }
    $fk = $db->query("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '{$dbName}' AND CONSTRAINT_NAME = 'fk_v2_localidad_timezone_localidad'")->fetch_assoc();
    tz_assert(($table['Engine'] ?? '') === 'InnoDB', 'SCHEMA01', 'Authority table uses InnoDB');
    tz_assert(($columns['id_localidad']['COLUMN_TYPE'] ?? '') === 'int(11)' && ($columns['id_localidad']['IS_NULLABLE'] ?? '') === 'NO', 'SCHEMA02', 'id_localidad is signed INT(11) NOT NULL');
    tz_assert(($columns['timezone_iana']['CHARACTER_SET_NAME'] ?? '') === 'ascii' && ($columns['timezone_iana']['COLLATION_NAME'] ?? '') === 'ascii_bin', 'SCHEMA03', 'timezone_iana uses ascii_bin');
    tz_assert(($columns['valido_desde']['COLUMN_TYPE'] ?? '') === 'date' && ($columns['valido_hasta']['COLUMN_TYPE'] ?? '') === 'date', 'SCHEMA04', 'Effective interval columns are DATE');
    tz_assert(in_array('uk_v2_localidad_timezone_desde', $indexes, true) && count($indexes) === 2, 'SCHEMA05', 'Primary key and required unique key exist without redundant index');
    tz_assert(($fk['DELETE_RULE'] ?? '') === 'RESTRICT', 'SCHEMA06', 'Locality foreign key is ON DELETE RESTRICT');

    $authority = new WorkplaceTimezoneAuthorityService($db);
    $authority->createMapping(201, 'America/New_York', '2026-01-01');
    tz_assert($authority->resolve(201, '2026-02-01') === 'America/New_York', 'TZ01', 'Valid authority interval returns exact IANA timezone');
    tz_assert(expectUnresolved(fn() => $authority->resolve(202, '2026-02-01')), 'TZ02', 'No mapping fails closed');
    $db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde) VALUES (203, 'Invalid/Zone', '2026-01-01')");
    tz_assert(expectUnresolved(fn() => $authority->resolve(203, '2026-02-01')), 'TZ03', 'Invalid IANA value fails closed');
    $db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias (id_localidad, timezone_iana, valido_desde, valido_hasta) VALUES (204, 'UTC', '2026-01-01', '2026-12-01'), (204, 'America/Chicago', '2026-06-01', NULL)");
    tz_assert(expectUnresolved(fn() => $authority->resolve(204, '2026-07-01')), 'TZ04', 'Overlapping applicable rows fail closed');
    $authority->createMapping(205, 'America/New_York', '2026-01-01', '2026-06-01');
    $authority->createMapping(205, 'America/Chicago', '2026-06-01');
    $writerOverlapRejected = false;
    try { $authority->createMapping(205, 'UTC', '2026-05-01'); } catch (RuntimeException $e) { $writerOverlapRejected = $e->getMessage() === 'WORKPLACE_TIMEZONE_OVERLAP_CONFLICT'; }
    tz_assert($writerOverlapRejected, 'SCHEMA08', 'Writer locks locality and rejects overlapping authority intervals');
    tz_assert($authority->resolve(205, '2026-05-31') === 'America/New_York', 'TZ05', 'Historical date uses interval A');
    tz_assert($authority->resolve(205, '2026-06-01') === 'America/Chicago', 'TZ06', 'valido_desde is inclusive for interval B');
    $authority->createMapping(209, 'America/New_York', '2026-01-01', '2026-06-01');
    tz_assert(expectUnresolved(fn() => $authority->resolve(209, '2026-06-01')), 'TZ07', 'valido_hasta is exclusive');
    tz_assert($authority->resolve(201, '2099-01-01') === 'America/New_York', 'TZ08', 'Open-ended interval applies to later dates');

    $db->query("INSERT INTO op_rh_v2_dispositivos (id, id_estacion, timezone) VALUES (9001, 201, 'UTC'), (9002, 201, 'Asia/Tokyo')");
    $resolution = new ScheduleResolutionService($db);
    tz_assert($resolution->getWorkplaceTimezone(201, '2026-02-01') === 'America/New_York', 'TZ09', 'Workplace authority wins over differing device timezone');
    tz_assert($resolution->getWorkplaceTimezone(201, '2026-02-01') === 'America/New_York', 'TZ10', 'Multiple device timezones do not affect deterministic authority');

    for ($id = 700; $id <= 704; $id++) { $db->query("INSERT INTO op_rh_personal (id, nombre_completo) VALUES ({$id}, 'Synthetic {$id}')"); }
    $templates = new ShiftTemplateService($db);
    $assignments = new ShiftAssignmentService($db);
    $day = $templates->createTemplate(['clave' => 'TZ_DAY_' . getmypid(), 'nombre' => 'Timezone day', 'segmentos' => [['ordinal' => 1, 'tipo_segmento' => 'WORK', 'hora_inicio_local' => '08:00:00', 'hora_fin_local' => '16:00:00', 'dia_offset_inicio' => 0, 'dia_offset_fin' => 0]]]);
    $night = $templates->createTemplate(['clave' => 'TZ_NIGHT_' . getmypid(), 'nombre' => 'Timezone night', 'tipo_turno' => 'NOCTURNO', 'segmentos' => [['ordinal' => 1, 'tipo_segmento' => 'WORK', 'hora_inicio_local' => '22:00:00', 'hora_fin_local' => '06:00:00', 'dia_offset_inicio' => 0, 'dia_offset_fin' => 1]]]);
    $assignments->createAssignment(['id_personal' => 700, 'id_estacion' => 201, 'id_plantilla' => $day['id'], 'dia_semana' => 'LUNES', 'valido_desde' => '2026-01-01', 'es_activo' => 1]);
    $assignments->createAssignment(['id_personal' => 701, 'id_estacion' => 201, 'id_plantilla' => $night['id'], 'dia_semana' => 'MARTES', 'valido_desde' => '2026-01-01', 'es_activo' => 1]);
    $nightResult = $resolution->resolveSchedule(701, 201, '2026-12-08');
    tz_assert($nightResult['horario_utc']['start_utc'] === '2026-12-09 03:00:00' && $nightResult['horario_utc']['end_utc'] === '2026-12-09 11:00:00', 'TZ11', 'Overnight UTC boundaries use canonical Workplace timezone');

    $assignments->createAssignment(['id_personal' => 702, 'id_estacion' => 206, 'id_plantilla' => $day['id'], 'dia_semana' => 'LUNES', 'valido_desde' => '2026-01-01', 'es_activo' => 1]);
    $materializer = new ScheduledShiftMaterializationService($db);
    $tz12 = expectUnresolved(fn() => $materializer->materializeSingleDay(702, 206, '2026-12-07'));
    $count12 = (int)$db->query("SELECT COUNT(*) AS c FROM op_rh_v2_jornadas_programadas WHERE id_personal = 702")->fetch_assoc()['c'];
    tz_assert($tz12 && $count12 === 0, 'TZ12', 'Missing authority prevents ScheduledShift materialization');

    $authority->createMapping(207, 'America/New_York', '2026-01-01');
    $assignments->createAssignment(['id_personal' => 703, 'id_estacion' => 207, 'id_plantilla' => $day['id'], 'dia_semana' => 'LUNES', 'valido_desde' => '2026-01-01', 'es_activo' => 1]);
    $materializer->materializeSingleDay(703, 207, '2026-12-07');
    $before = $db->query("SELECT timezone_local FROM op_rh_v2_jornadas_programadas WHERE id_personal = 703")->fetch_assoc()['timezone_local'];
    $db->query("UPDATE op_rh_v2_localidades_zonas_horarias SET timezone_iana = 'America/Chicago' WHERE id_localidad = 207");
    $materializer->materializeSingleDay(703, 207, '2026-12-07');
    $after = $db->query("SELECT timezone_local FROM op_rh_v2_jornadas_programadas WHERE id_personal = 703")->fetch_assoc()['timezone_local'];
    tz_assert($before === 'America/New_York' && $after === $before, 'TZ13', 'Materialized timezone snapshot remains immutable after authority change');
    tz_assert(($db->query("SELECT timezone FROM op_rh_v2_dispositivos WHERE id = 9001")->fetch_assoc()['timezone'] ?? '') === 'UTC', 'TZ14', 'Unrelated device timezone metadata remains unchanged');

    $assignments->createAssignment(['id_personal' => 704, 'id_estacion' => 208, 'id_plantilla' => $day['id'], 'dia_semana' => 'LUNES', 'valido_desde' => '2026-01-01', 'es_activo' => 1]);
    tz_assert(expectUnresolved(fn() => $resolution->resolveSchedule(704, 208, '2026-12-07')), 'TZ15', 'Scheduling never falls back to America/Mexico_City');

    $rollbackText = (string)file_get_contents(__DIR__ . '/../migraciones/v2/2026_10_12_000001_rollback_v2_workplace_timezone_authority.sql');
    tz_assert(str_contains($rollbackText, 'SIGNAL SQLSTATE') && !str_contains($rollbackText, 'DROP TABLE'), 'SCHEMA07', 'Rollback is explicitly fail-closed and non-destructive');
} catch (Throwable $e) {
    $failCount++;
    tz_log('UNHANDLED EXCEPTION: ' . $e->getMessage());
} finally {
    if ($db instanceof mysqli) { $db->close(); }
    if ($admin instanceof mysqli) {
        try { $admin->query("DROP DATABASE IF EXISTS `{$dbName}`"); $removed = true; } catch (Throwable $e) { tz_log('CLEANUP ERROR: ' . $e->getMessage()); }
        $admin->close();
    }
}

tz_log("PASS: {$passCount}");
tz_log("FAIL: {$failCount}");
tz_log('DISPOSABLE_DB: ' . ($removed ? 'REMOVED' : 'NOT_REMOVED'));
exit($failCount > 0 || !$removed ? 1 : 0);
