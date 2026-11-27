<?php
declare(strict_types=1);

// The service configuration must explicitly opt in to writes; default is disabled.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc === 2 && $argv[1] === '--help') {
    echo "Usage: php scripts/journey_processor.php --dry-run|--execute --config=FILE --station=N --worker=N --date=YYYY-MM-DD\n       php scripts/journey_processor.php --batch-dry-run|--batch-execute --config=FILE\n";
    exit(0);
}
try {
    $opts = getopt('', ['dry-run', 'execute', 'batch-dry-run', 'batch-execute', 'config:', 'station:', 'worker:', 'date:']);
    if (isset($opts['batch-dry-run']) || isset($opts['batch-execute'])) {
        require_once __DIR__.'/JourneyBatchProcessor.php';
        require_once __DIR__.'/journey_batch_runtime.php';
        exit(journeyBatchMain($opts,$argc));
    }
    if (isset($opts['dry-run']) === isset($opts['execute']) || count($opts) !== 5 || $argc !== 6) throw new RuntimeException('MODE_REQUIRED');
    if (!function_exists('posix_geteuid')) throw new RuntimeException('POSIX_REQUIRED');
    $path = $opts['config'];
    if (!is_string($path) || !str_starts_with($path, '/') || is_link($path)) throw new RuntimeException('INVALID_CONFIG');
    $real = realpath($path);
    $repo = realpath(dirname(__DIR__));
    $stat = $real ? stat($real) : false;
    if (!$stat || !is_file($real) || str_starts_with($real, $repo . '/') || ($stat['mode'] & 0077) !== 0 || !in_array($stat['uid'], [0, posix_geteuid()], true)) throw new RuntimeException('INVALID_CONFIG_PERMISSIONS');
    $config = json_decode((string)file_get_contents($real), true, 32, JSON_THROW_ON_ERROR);
    $worker = filter_var($opts['worker'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $station = filter_var($opts['station'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $date = $opts['date'];
    if (!$worker || !$station || !is_string($date) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date)) throw new RuntimeException('INVALID_SCOPE');
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    if (!checkdate($m, $d, $y)) throw new RuntimeException('INVALID_SCOPE');
    if (!is_array($config)) throw new RuntimeException('SERVICE_SCOPE_DENIED');
    require_once __DIR__.'/JourneyEnvironmentProfile.php';
    JourneyEnvironmentProfile::validate($config, posix_geteuid(), $station);
    $coverageAuthorizationContext = ['principal_type'=>'SERVICE','service_id'=>$config['service_id'],'system_uid'=>posix_geteuid(),'stations'=>$config['stations']];
    if (isset($opts['execute']) && (($config['execution_enabled'] ?? false) !== true || getenv('CHECADOR_JOURNEY_CLI_EXECUTION_ENABLED') !== '1')) throw new RuntimeException('EXECUTION_DISABLED');
    $isPreprod = ($config['environment'] ?? 'development') === 'preprod';
    if (!$isPreprod && (!in_array(getenv('DB_HOST'), ['localhost','127.0.0.1','::1'], true) || getenv('DB_NAME') !== 'bd_admongas')) throw new RuntimeException('DATABASE_SCOPE_DENIED');
    require_once __DIR__ . '/../modelo/BD.php';
    require_once __DIR__ . '/../modelo/v2/shift/ScheduleResolutionService.php';
    if ($isPreprod) {
        [$db, $hostIdentity, $databaseIdentity] = JourneyEnvironmentProfile::connectPreprod($config, isset($opts['execute']) ? 'single_write' : 'dry_run');
        $coverageAuthorizationContext['preprod_verified'] = true;
    } else {
        $db = (new BD())->conectarBD();
        $databaseIdentity=$db->query('SELECT DATABASE() AS database_name')->fetch_assoc();
        if (($databaseIdentity['database_name']??null)!=='bd_admongas') throw new RuntimeException('DATABASE_SCOPE_DENIED');
    }
    if (isset($opts['execute'])) {
        $schedule = (new ScheduleResolutionService($db))->resolveSchedule($worker, $station, $date);
        if (!in_array($schedule['estado_jornada'] ?? '', ['SCHEDULED', 'DAY_OFF'], true)) throw new RuntimeException('SCHEDULE_NOT_EVALUABLE');
        require_once __DIR__.'/JourneyBatchProcessor.php';
        require_once __DIR__.'/journey_batch_runtime.php';
        require_once __DIR__ . '/../modelo/v2/journey/JourneySharedExecutionService.php';
        $level2=null;
        $result = (new JourneySharedExecutionService($db))->evaluate($worker, $station, $date, false,
            static function(int $idPersonal,int $idEstacion,string $fechaLocal)use($db,&$level2):void {
                $level2=JourneyBatchProcessor::validateLevel2BeforeExecution(journeyBatchEvaluateLevel2($db,$idPersonal,$idEstacion,$fechaLocal));
            },$coverageAuthorizationContext);
        echo json_encode(['mode'=>'execute','service_id'=>$config['service_id'],'worker'=>$worker,'station'=>$station,'date'=>$date,'shift_id'=>$result['shift']['id'],'pairing_id'=>$result['pairing']['pairing_id'],'j1_algorithm'=>$result['pairing']['algorithm_version']??$result['pairing']['algorithm']??null,'level2'=>($level2['skipped']??false)?null:JourneyBatchProcessor::level2Status($level2),'level2_reason'=>$level2['reason']??null,'level2_evaluation_ref'=>$level2['evaluation_ref']??null,'provisional'=>($level2['provisional']??false)||(($result['pairing']['pairing_status']??null)==='PENDING_SYNC_COMPLETION'),'p2_for_pairing_id'=>$result['calculation']['id_pareo_jornada']??null,'p2_status'=>$result['calculation']['estado_calculo']??null,'status'=>'EXECUTED'], JSON_THROW_ON_ERROR) . PHP_EOL;
        $db->close();
        exit(0);
    }
    require_once __DIR__.'/journey_batch_runtime.php';
    echo json_encode(journeyProcessorDryRun($db,$worker,$station,$date,$coverageAuthorizationContext), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    $db->close();
} catch (Throwable $e) {
    $safe = ['BATCH_EXECUTION_DISABLED','BATCH_ALREADY_RUNNING','BATCH_STATE_DIRECTORY_DENIED','BATCH_STATE_INVALID','BATCH_STATE_DENIED','BATCH_STATE_LOCK_DENIED','BATCH_STATE_WRITE_FAILED','BATCH_LOCAL_DATE_UNAVAILABLE','BATCH_READ_FAILED','BATCH_SNAPSHOT_UNAVAILABLE','DATABASE_SCOPE_DENIED','INVALID_CONFIG_PERMISSIONS','INVALID_BATCH_OPTIONS','J1_V11_TARGET_UNAVAILABLE','J1_SNAPSHOT_UNAVAILABLE','J1_EVALUATION_READ_FAILED','J1_POLICY_INVALID','J1_POLICY_SCHEMA_UNAVAILABLE','J1_ASSIGNMENT_READ_FAILED','J1_CANDIDATE_READ_FAILED','J1_LEVEL2_READ_FAILED','J1_PREVIEW_PUNCH_READ_FAILED','J1_SCHEDULE_UNRESOLVED','J1_SEGMENT_ORDER_UNVERIFIED','J1_NORMALIZATION_INVALID','J1_V11_PERSISTENCE_DISABLED'];
    $code=in_array($e->getMessage(),$safe,true)?$e->getMessage():'PROCESSOR_DENIED_OR_UNAVAILABLE';
    fwrite(STDERR,$code.PHP_EOL); exit(2);
}
