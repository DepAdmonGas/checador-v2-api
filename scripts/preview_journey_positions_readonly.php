<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 4 || !in_array(getenv('DB_HOST'), ['localhost','127.0.0.1','::1'], true) || getenv('DB_NAME') !== 'bd_admongas') {
    fwrite(STDERR, "Usage with local bd_admongas: php scripts/preview_journey_positions_readonly.php WORKER 'FROM_UTC' 'TO_UTC'\n"); exit(2);
}
date_default_timezone_set('UTC');
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/journey/JourneyV11OperationalContext.php';
$db = null;
try {
    $worker = filter_var($argv[1], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$worker) throw new InvalidArgumentException('J1_INVALID_PREVIEW_SCOPE');
    $db = (new BD())->conectarBD();
    $identity = $db->query('SELECT DATABASE() AS db, CURRENT_USER() AS usr')->fetch_assoc();
    if (($identity['db'] ?? '') !== 'bd_admongas') throw new RuntimeException('DATABASE_IDENTITY_MISMATCH');
    // This script cannot authorize or persist a pairing. MariaDB enforces the
    // read-only transaction in addition to the SELECT-only service path.
    if (!$db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')) throw new RuntimeException('READ_ONLY_TRANSACTION_FAILED');
    try {
        $result = (new JourneyV11OperationalContext($db))->previewCompetingJourneys($worker, $argv[2], $argv[3]);
        echo json_encode(['database'=>$identity['db'], 'sql_user'=>$identity['usr'], 'worker'=>$worker, 'result'=>$result], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR), PHP_EOL;
    } finally { $db->rollback(); }
} catch (Throwable $e) {
    $safe = ['J1_INVALID_PREVIEW_SCOPE','DATABASE_IDENTITY_MISMATCH','READ_ONLY_TRANSACTION_FAILED',
        'J1_INVALID_CANDIDATE_WINDOW','J1_ASSIGNMENT_READ_FAILED','J1_CANDIDATE_READ_FAILED',
        'J1_UNSUPPORTED_DAY_OFFSET','J1_MATERIALIZED_SEGMENTS_UNAVAILABLE','J1_SCHEDULE_UNRESOLVED',
        'J1_STATION_UNRESOLVED','J1_SEGMENT_UNRESOLVED','J1_POLICY_READ_FAILED','J1_POLICY_INVALID',
        'J1_POLICY_SCHEMA_UNAVAILABLE','J1_LEVEL2_READ_FAILED','J1_INVALID_LEVEL2_WINDOW',
        'J1_PREVIEW_PUNCH_READ_FAILED','J1_INVALID_PREVIEW_PUNCH'];
    $code = in_array($e->getMessage(),$safe,true) ? $e->getMessage() : 'DEPENDENCY_UNAVAILABLE';
    fwrite(STDERR,'PREVIEW_INCONCLUSIVE: '.$code.PHP_EOL); exit(1);
} finally { if ($db instanceof mysqli) $db->close(); }
