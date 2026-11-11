<?php
declare(strict_types=1);

/**
 * Focused, read-only checks for UTC shift audit timestamps.
 *
 * Usage:
 *   php tests/v2_shift_audit_utc_timestamp_tests.php --source-only
 *   php tests/v2_shift_audit_utc_timestamp_tests.php --live
 *
 * Live mode requires DB_HOST, DB_USER, DB_PASS, DB_NAME=bd_admongas and
 * optionally DB_PORT. It performs SELECTs and session-only timezone changes.
 */

function assertCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function runSourceChecks(string $root): void
{
    $writers = [
        'modelo/v2/shift/ShiftAssignmentService.php',
        'modelo/v2/shift/ShiftTemplateService.php',
    ];
    $insertCount = 0;
    foreach ($writers as $relativePath) {
        $source = file_get_contents($root . '/' . $relativePath);
        assertCheck(is_string($source), "Could not read {$relativePath}");
        preg_match_all(
            '/INSERT\s+INTO\s+op_rh_v2_turnos_audit_log\s*\(([^)]*)\)\s*VALUES\s*\(([^)]*)\)/is',
            $source,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            $insertCount++;
            assertCheck(
                preg_match('/(?:^|,)\s*recorded_at_utc\s*(?:,|$)/i', $match[1]) === 1,
                "{$relativePath} audit insert does not explicitly name recorded_at_utc"
            );
            assertCheck(
                preg_match('/,\s*UTC_TIMESTAMP\s*\(\s*\)\s*$/i', trim($match[2]) . ')') === 1,
                "{$relativePath} audit insert does not explicitly persist UTC_TIMESTAMP()"
            );
        }
    }
    assertCheck($insertCount === 5, "Expected five canonical shift audit inserts; found {$insertCount}");

    $assignmentSource = file_get_contents($root . '/modelo/v2/shift/ShiftAssignmentService.php');
    assertCheck(
        is_string($assignmentSource)
        && preg_match('/registrado_at_utc\)\s*VALUES\s*\([^;]*UTC_TIMESTAMP\(\)/is', $assignmentSource) === 1,
        'Canonical assignment timestamp must continue to use UTC_TIMESTAMP()'
    );
    echo "PASS: five shift audit writers and assignment UTC source are explicit.\n";
}

function runLiveChecks(): void
{
    foreach (['DB_HOST', 'DB_USER', 'DB_NAME'] as $key) {
        assertCheck(trim((string) getenv($key)) !== '', "{$key} is required for --live");
    }
    assertCheck(getenv('DB_NAME') === 'bd_admongas', 'Live checks are restricted to bd_admongas');
    $host = (string) getenv('DB_HOST');
    assertCheck(
        in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true),
        'Live checks are restricted to a loopback database host'
    );

    $port = (int) (getenv('DB_PORT') ?: 3306);
    $db = new mysqli($host, (string) getenv('DB_USER'), (string) (getenv('DB_PASS') ?: ''), 'bd_admongas', $port);
    assertCheck(!$db->connect_errno, 'Could not connect to the authorized local development database');
    $db->set_charset('utf8mb4');

    $initial = $db->query('SELECT DATABASE() AS db_name, @@global.time_zone AS global_tz, @@session.time_zone AS session_tz, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS offset_seconds, VERSION() AS server_version')->fetch_assoc();
    assertCheck(is_array($initial) && $initial['db_name'] === 'bd_admongas', 'Connected database identity is not bd_admongas');
    assertCheck(stripos((string) $initial['server_version'], 'MariaDB') !== false, 'Development DB is not confirmed as MariaDB');
    $originalZone = (string) $initial['session_tz'];

    $types = [];
    $defaults = [];
    $columnResult = $db->query(
        "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_DEFAULT FROM information_schema.COLUMNS "
        . "WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'op_rh_v2_turnos_audit_log' AND COLUMN_NAME = 'recorded_at_utc') "
        . "OR (TABLE_NAME = 'op_rh_v2_turnos_asignaciones' AND COLUMN_NAME = 'registrado_at_utc'))"
    );
    while ($column = $columnResult->fetch_assoc()) {
        $key = $column['TABLE_NAME'] . '.' . $column['COLUMN_NAME'];
        $types[$key] = strtolower((string) $column['DATA_TYPE']);
        $defaults[$key] = strtoupper((string) $column['COLUMN_DEFAULT']);
    }
    assertCheck(($types['op_rh_v2_turnos_audit_log.recorded_at_utc'] ?? null) === 'datetime', 'Audit timestamp column is not DATETIME');
    assertCheck(($types['op_rh_v2_turnos_asignaciones.registrado_at_utc'] ?? null) === 'datetime', 'Assignment timestamp column is not DATETIME');
    assertCheck(str_contains($defaults['op_rh_v2_turnos_audit_log.recorded_at_utc'] ?? '', 'CURRENT_TIMESTAMP'), 'Audit column default is not confirmed as CURRENT_TIMESTAMP');

    try {
        foreach (['+00:00' => 0, '+05:30' => 19800, '-06:00' => -21600] as $zone => $expectedOffset) {
            $quotedZone = $db->real_escape_string($zone);
            assertCheck($db->query("SET SESSION time_zone = '{$quotedZone}'") === true, "Could not set test session timezone {$zone}");
            $row = $db->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS offset_seconds, UTC_TIMESTAMP() AS utc_now')->fetch_assoc();
            assertCheck(is_array($row), "No timestamp result at session timezone {$zone}");
            assertCheck((int) $row['offset_seconds'] === $expectedOffset, "NOW() did not follow session timezone {$zone}");
            $dbUtc = new DateTimeImmutable((string) $row['utc_now'], new DateTimeZone('UTC'));
            $phpUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            assertCheck(abs($dbUtc->getTimestamp() - $phpUtc->getTimestamp()) <= 3, "UTC_TIMESTAMP() differs from PHP UTC by over three seconds at {$zone}");
        }
    } finally {
        $restore = $db->real_escape_string($originalZone);
        $db->query("SET SESSION time_zone = '{$restore}'");
    }

    echo 'PASS: local MariaDB read-only checks; DB=' . $initial['db_name']
        . ', global_tz=' . $initial['global_tz'] . ', original_session_tz=' . $originalZone
        . ', initial NOW()-UTC=' . $initial['offset_seconds'] . ' seconds'
        . ', PHP=' . date_default_timezone_get() . ', columns=DATETIME/default=CURRENT_TIMESTAMP, session offsets=0/+05:30/-06:00.' . PHP_EOL;
    $db->close();
}

$root = dirname(__DIR__);
$mode = $argv[1] ?? '';
assertCheck(in_array($mode, ['--source-only', '--live'], true), 'Choose --source-only or --live');
runSourceChecks($root);
if ($mode === '--live') {
    runLiveChecks();
}
