<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!in_array(getenv('DB_HOST'), ['localhost', '127.0.0.1', '::1'], true) || getenv('DB_NAME') !== 'bd_admongas' || $argc !== 4) {
    fwrite(STDERR, "Usage with local bd_admongas: php scripts/check_journey_candidates_readonly.php WORKER 'FROM_UTC' 'TO_UTC'\n"); exit(2);
}
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/journey/JourneyV11OperationalContext.php';
try {
    $db = (new BD())->conectarBD();
    $identity = $db->query('SELECT DATABASE() AS db, CURRENT_USER() AS usr')->fetch_assoc();
    if (($identity['db'] ?? '') !== 'bd_admongas') throw new RuntimeException('DATABASE_IDENTITY_MISMATCH');
    $worker = filter_var($argv[1], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$worker) throw new InvalidArgumentException('J1_INVALID_CANDIDATE_WINDOW');
    $stmt = $db->prepare('SELECT id, event_uuid, id_estacion_resuelta, id_personal_resuelto, ocurrido_at_utc, processing_status, validation_flags FROM op_rh_v2_marcaciones_crudas WHERE id_personal_resuelto = ? AND ocurrido_at_utc BETWEEN ? AND ? ORDER BY ocurrido_at_utc, id');
    if (!$stmt) throw new RuntimeException('J1_PUNCH_READ_FAILED');
    try {
        $from = $argv[2]; $to = $argv[3]; $stmt->bind_param('iss', $worker, $from, $to);
        if (!$stmt->execute() || !($rows = $stmt->get_result())) throw new RuntimeException('J1_PUNCH_READ_FAILED');
        $punches = []; while ($row = $rows->fetch_assoc()) $punches[] = $row;
    } finally { $stmt->close(); }
    $result = (new JourneyV11OperationalContext($db))->discoverCompetingJourneys($worker, $argv[2], $argv[3], $punches);
    echo json_encode(['database'=>$identity['db'], 'result'=>$result, 'punch_read'=>'EXECUTED', 'punch_count'=>count($punches)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
    $db->close();
} catch (Throwable $e) {
    // Never emit SQL text, connection details or personal data from exceptions.
    $safe = [
        'J1_POSITION_ELIGIBILITY_NOT_VERIFIED', 'J1_ASSIGNMENT_READ_FAILED', 'J1_PUNCH_READ_FAILED', 'J1_INVALID_CANDIDATE_WINDOW',
        'J1_CANDIDATE_READ_FAILED', 'J1_UNSUPPORTED_DAY_OFFSET',
        'J1_MATERIALIZED_SEGMENTS_UNAVAILABLE', 'J1_SCHEDULE_UNRESOLVED',
        'J1_STATION_UNRESOLVED', 'J1_SEGMENT_UNRESOLVED',
        'J1_POLICY_READ_FAILED', 'J1_POLICY_INVALID',
        'J1_POLICY_SCHEMA_UNAVAILABLE', 'DATABASE_IDENTITY_MISMATCH'
    ];
    $code = in_array($e->getMessage(), $safe, true) ? $e->getMessage() : 'DEPENDENCY_UNAVAILABLE';
    fwrite(STDERR, 'DISCOVERY_INCONCLUSIVE: ' . $code . PHP_EOL); exit(1);
}
