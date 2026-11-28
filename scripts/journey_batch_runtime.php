<?php
declare(strict_types=1);

/** Preserve materialized schedule authority when an assignment is no longer present. */
function journeyBatchTarget(mysqli $db,int $worker,int $station,string $date): string {
    $rows=journeyBatchRows($db,'SELECT tipo_jornada FROM op_rh_v2_jornadas_programadas WHERE id_personal=? AND id_estacion=? AND fecha_jornada_local=?','iis',[$worker,$station,$date]);
    if ($rows) return $rows[0]['tipo_jornada'];
    return (new ScheduleResolutionService($db))->resolveSchedule($worker,$station,$date)['estado_jornada']??'UNKNOWN';
}

function journeyBatchRows(mysqli $db,string $sql,string $types,array $params): array {
    $stmt=$db->prepare($sql);
    if (!$stmt) throw new RuntimeException('BATCH_READ_FAILED');
    try {
        $stmt->bind_param($types,...$params);
        if (!$stmt->execute() || !($result=$stmt->get_result())) throw new RuntimeException('BATCH_READ_FAILED');
        return $result->fetch_all(MYSQLI_ASSOC);
    } finally { $stmt->close(); }
}

function journeyProcessorDryRun(mysqli $db,int $worker,int $station,string $date,?array $coverageAuthorizationContext=null): array {
    // A V1.1 dry-run uses the same plan as the gated J1 writer. No materialization.
    require_once __DIR__ . '/../modelo/v2/journey/JourneyV11OperationalContext.php';
    $context = new JourneyV11OperationalContext($db);
    if (!$db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !$db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')) throw new RuntimeException('J1_SNAPSHOT_UNAVAILABLE');
    try {
        $target=journeyBatchTarget($db,$worker,$station,$date);
        if (WorkplaceCoverageFeatureGate::isEnabled()) {
            if ($coverageAuthorizationContext === null) throw new RuntimeException('COVERAGE_AUTH_CONTEXT_REQUIRED');
            $effective=(new WorkplaceCoverageService($db))->resolveWorkerEffective($worker,$date,$coverageAuthorizationContext);
            $effectiveStation=(int)($effective['effective_workplace_id']??$effective['id_estacion']??0);
            if (($effective['estado_jornada']??'UNKNOWN')==='UNKNOWN' || $effectiveStation<1) throw new RuntimeException('J1_EFFECTIVE_WORKPLACE_UNAVAILABLE');
            if ($effectiveStation!==$station) return ['mode'=>'dry-run','operation'=>'SKIP','reason'=>'NOT_EFFECTIVE_WORKPLACE','requested_workplace'=>$station,'effective_workplace'=>$effectiveStation,'persistence_authorized'=>false,'read_only'=>true];
        }
        if ($context->policy($station,$date)!==null) {
            if ($target!=='SCHEDULED') return ['mode'=>'dry-run','operation'=>'READ_ONLY','schedule_status'=>$target,'persistence_authorized'=>false,'level2'=>journeyBatchLevel2ReadOnly($db,$worker,$station,$date),'reason'=>'J1_V11_NON_SCHEDULED_NOT_VERIFIED'];
            $plan=(new JourneyV11OperationalContext($db,$coverageAuthorizationContext))->preparePairingEvaluation($worker,$station,$date);
            $plan['active_revision_conflict_ids']=array_values(array_unique(array_map(static fn($r)=>(int)$r['punch_id'],$plan['active_revision_conflicts'])));
            unset($plan['active_revision_conflicts']);
            return ['mode'=>'dry-run','operation'=>'READ_ONLY','schedule_status'=>'SCHEDULED','persistence_authorized'=>false,'level2'=>['window_level2_status'=>$plan['level2_status']??'UNKNOWN','source'=>'EXISTING_READ_ONLY_EVIDENCE'],'plan'=>$plan];
        }
        // Historical V1.0 retains its previous diagnostic only. Do not assert
        // fingerprint or closure parity with the historical writer.
        $shift=journeyBatchRows($db,'SELECT id FROM op_rh_v2_jornadas_programadas WHERE id_personal=? AND id_estacion=? AND fecha_jornada_local=? LIMIT 1','iis',[$worker,$station,$date])[0]??null;
        $reason='SCHEDULE_NOT_EVALUABLE';
        if (in_array($target,['SCHEDULED','DAY_OFF'],true)) {
            if (!$shift) $reason='SHIFT_NOT_MATERIALIZED';
            else {
                $p1=journeyBatchRows($db,'SELECT p.id, EXISTS(SELECT 1 FROM op_rh_v2_calculos_jornada c WHERE c.id_pareo_jornada=p.id) AS has_p2 FROM op_rh_v2_pareos_jornada p WHERE p.id_jornada_programada=? ORDER BY p.revision_numero DESC LIMIT 1','i',[(int)$shift['id']])[0]??null;
                $reason=!$p1?'P1_MISSING':((int)$p1['has_p2']===0?'P2_MISSING':'INPUT_CHANGES_NOT_VERIFIED');
            }
        }
        return ['mode'=>'dry-run','worker'=>$worker,'station'=>$station,'date'=>$date,'schedule_status'=>$target,'reason'=>$reason,'operation'=>'NOT_EXECUTED','persistence_authorized'=>false,'level2'=>journeyBatchLevel2ReadOnly($db,$worker,$station,$date)];
    } finally { $db->rollback(); }
}

/** Resolve one journey's immutable UTC boundaries and physical station. */
function journeyBatchLevel2Scope(mysqli $db,int $worker,int $workplace,string $date): array {
    require_once __DIR__.'/../modelo/v2/WorkplacePhysicalStationResolver.php';
    $shift=journeyBatchRows($db,'SELECT id,tipo_jornada,scheduled_start_utc,scheduled_end_utc,payload_segmentos_json FROM op_rh_v2_jornadas_programadas WHERE id_personal=? AND id_estacion=? AND fecha_jornada_local=? ORDER BY id DESC LIMIT 1','iis',[$worker,$workplace,$date])[0]??null;
    if ($shift!==null) {
        if (($shift['tipo_jornada']??null)!=='SCHEDULED') return ['status'=>'UNKNOWN','reason'=>'NO_SCHEDULED_WINDOW'];
        $from=(string)($shift['scheduled_start_utc']??''); $to=(string)($shift['scheduled_end_utc']??'');
        $segments=json_decode((string)($shift['payload_segmentos_json']??''),true);
        if (!is_array($segments)||!$segments) throw new RuntimeException('BATCH_LEVEL2_WINDOW_UNKNOWN');
        $segmentWindow=JourneyBatchProcessor::level2Window($segments);
        if ($from!==$segmentWindow['from_utc']||$to!==$segmentWindow['to_utc']) throw new RuntimeException('BATCH_LEVEL2_WINDOW_UNKNOWN');
        $window=JourneyBatchProcessor::level2EvidenceWindow($segments);
        $from=$window['from_utc']; $to=$window['to_utc'];
    } else {
        $resolved=(new ScheduleResolutionService($db))->resolveSchedule($worker,$workplace,$date);
        if (($resolved['estado_jornada']??'UNKNOWN')!=='SCHEDULED') return ['status'=>'UNKNOWN','reason'=>'NO_SCHEDULED_WINDOW'];
        $segments=$resolved['horario_local']['segmentos']??null;
        if (!is_array($segments)||!$segments) throw new RuntimeException('BATCH_LEVEL2_WINDOW_UNKNOWN');
        $scheduled=JourneyBatchProcessor::level2Window($segments);
        $resolvedWindow=$resolved['horario_utc']??null;
        if (!is_array($resolvedWindow)||($resolvedWindow['start_utc']??null)!==$scheduled['from_utc']||($resolvedWindow['end_utc']??null)!==$scheduled['to_utc']) throw new RuntimeException('BATCH_LEVEL2_WINDOW_UNKNOWN');
        $window=JourneyBatchProcessor::level2EvidenceWindow($segments);
        $from=$window['from_utc']; $to=$window['to_utc'];
    }
    $physical=WorkplacePhysicalStationResolver::resolve($db,$workplace);
    if ($physical===null) throw new RuntimeException('BATCH_LEVEL2_STATION_UNRESOLVED');
    return ['status'=>'RESOLVED','physical_station'=>$physical,'from_utc'=>$from,'to_utc'=>$to,'shift_id'=>$shift===null?null:(int)$shift['id']];
}

/** Strictly read-only Level 2 view for batch-dry-run. Missing evidence is UNKNOWN. */
function journeyBatchLevel2ReadOnly(mysqli $db,int $worker,int $workplace,string $date): array {
    $scope=journeyBatchLevel2Scope($db,$worker,$workplace,$date);
    if (($scope['status']??'')!=='RESOLVED') return ['window_level2_status'=>'UNKNOWN','reason'=>$scope['reason']??'WINDOW_UNKNOWN','persisted'=>false];
    require_once __DIR__.'/../modelo/v2/journey/JourneyV11OperationalContext.php';
    $row=(new JourneyV11OperationalContext($db))->level2Evidence((int)$scope['physical_station'],(string)$scope['from_utc'],(string)$scope['to_utc']);
    if ($row===null) return ['window_level2_status'=>'UNKNOWN','reason'=>'EVIDENCE_ABSENT','persisted'=>false,'physical_station'=>$scope['physical_station'],'from_utc'=>$scope['from_utc'],'to_utc'=>$scope['to_utc']];
    return ['window_level2_status'=>JourneyBatchProcessor::level2Status($row),'delivery_completeness'=>$row['delivery_completeness']??'UNKNOWN','operational_coverage'=>$row['operational_coverage']??'UNKNOWN','time_window_attribution'=>$row['time_window_attribution']??'UNKNOWN','evaluation_ref'=>$row['evaluation_ref']??null,'physical_station'=>$scope['physical_station'],'from_utc'=>$scope['from_utc'],'to_utc'=>$scope['to_utc'],'persisted'=>true];
}

/** Called only inside the existing shared worker lock and the gated batch-execute path. */
function journeyBatchEvaluateLevel2(mysqli $db,int $worker,int $workplace,string $date): array {
    require_once __DIR__.'/../modelo/v2/journey/JourneyV11OperationalContext.php';
    // Do not retroactively run the V1.1 coverage path for a historical V1.0
    // journey. The existing V1.0 writer retains its own persisted-evidence rule.
    if ((new JourneyV11OperationalContext($db))->policy($workplace,$date)===null) {
        return ['algorithm_version'=>'V1.0','skipped'=>true,'reason'=>'HISTORICAL_POLICY'];
    }
    $scope=journeyBatchLevel2Scope($db,$worker,$workplace,$date);
    if (($scope['status']??'')!=='RESOLVED') throw new RuntimeException('BATCH_LEVEL2_WINDOW_UNKNOWN');
    $from=(string)$scope['from_utc']; $to=(string)$scope['to_utc']; $physical=(int)$scope['physical_station'];
    $utcNow=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    if (!JourneyBatchProcessor::level2WindowClosed($to,$utcNow)) return ['window_level2_status'=>'UNKNOWN','reason'=>'WINDOW_OPEN','provisional'=>true,'persisted'=>false,'physical_station'=>$physical,'from_utc'=>$from,'to_utc'=>$to];
    require_once __DIR__.'/../modelo/v2/Level2ServerService.php';
    $service=new Level2ServerService(); $policyRef='POLICY_V1.0_DEFAULT';
    // A previously persisted wider window can satisfy the journey interval.
    $latest=journeyBatchRows($db,'SELECT id_estacion,window_from_utc,window_to_utc,coverage_policy_ref,relevant_device_generations_json,delivery_completeness,operational_coverage,time_window_attribution,window_level2_status,evaluation_ref,evaluated_at_utc FROM op_rh_v2_level2_window_evaluations WHERE id_estacion=? AND window_from_utc<=? AND window_to_utc>=? AND coverage_policy_ref=? ORDER BY id DESC LIMIT 1','isss',[$physical,$from,$to,$policyRef])[0]??null;
    $evidenceFrom=$latest?(string)$latest['window_from_utc']:$from; $evidenceTo=$latest?(string)$latest['window_to_utc']:$to;
    $generations=$service->obtenerGeneracionesYDispositivosRelevantes($db,$physical,$evidenceFrom,$evidenceTo);
    $maxTelemetry=journeyBatchRows($db,'SELECT MAX(t.server_received_at_utc) AS received_at FROM op_rh_v2_dispositivo_telemetria t JOIN op_rh_v2_dispositivo_estacion_historial h ON h.id_dispositivo=t.id_dispositivo WHERE h.id_estacion=? AND h.valido_desde_utc<=? AND (h.valido_hasta_utc IS NULL OR h.valido_hasta_utc>?) AND t.observado_at_utc>=? AND t.observado_at_utc<=?','issss',[$physical,$evidenceTo,$evidenceFrom,$evidenceFrom,$evidenceTo])[0]['received_at']??null;
    $maxPunch=journeyBatchRows($db,'SELECT MAX(m.recibido_at_utc) AS received_at FROM op_rh_v2_marcaciones_crudas m JOIN op_rh_v2_marcaciones_nivel2_metadata n ON n.id_raw_punch=m.id JOIN op_rh_v2_dispositivo_estacion_historial h ON h.id_dispositivo=n.id_dispositivo WHERE h.id_estacion=? AND h.valido_desde_utc<=? AND (h.valido_hasta_utc IS NULL OR h.valido_hasta_utc>?) AND m.ocurrido_at_utc>=? AND m.ocurrido_at_utc<=? AND n.es_governado_nivel2=1','issss',[$physical,$evidenceTo,$evidenceFrom,$evidenceFrom,$evidenceTo])[0]['received_at']??null;
    $maxMetadata=journeyBatchRows($db,'SELECT MAX(n.creado_at_utc) AS received_at FROM op_rh_v2_marcaciones_crudas m JOIN op_rh_v2_marcaciones_nivel2_metadata n ON n.id_raw_punch=m.id JOIN op_rh_v2_dispositivo_estacion_historial h ON h.id_dispositivo=n.id_dispositivo WHERE h.id_estacion=? AND h.valido_desde_utc<=? AND (h.valido_hasta_utc IS NULL OR h.valido_hasta_utc>?) AND m.ocurrido_at_utc>=? AND m.ocurrido_at_utc<=? AND n.es_governado_nivel2=1','issss',[$physical,$evidenceTo,$evidenceFrom,$evidenceFrom,$evidenceTo])[0]['received_at']??null;
    $received=array_values(array_filter([$maxTelemetry,$maxPunch,$maxMetadata],static fn($v)=>is_string($v)&&$v!=='')); sort($received,SORT_STRING); $latestInput=$received?end($received):null;
    if (!JourneyBatchProcessor::shouldEvaluateLevel2($latest,$physical,$from,$to,$policyRef,$generations,$latestInput,$utcNow)) {
        return ['window_level2_status'=>JourneyBatchProcessor::level2Status($latest),'delivery_completeness'=>$latest['delivery_completeness'],'operational_coverage'=>$latest['operational_coverage'],'time_window_attribution'=>$latest['time_window_attribution'],'evaluation_ref'=>$latest['evaluation_ref'],'physical_station'=>$physical,'from_utc'=>$from,'to_utc'=>$to,'persisted'=>true,'reused'=>true];
    }
    $result=$service->evaluarVentanaNivel2($db,$physical,$from,$to,$policyRef);
    if (!is_array($result)||!in_array($result['window_level2_status']??null,['VERIFIED','UNKNOWN','INCOMPLETE'],true)) throw new RuntimeException('BATCH_LEVEL2_EVALUATION_FAILED');
    return $result+['physical_station'=>$physical,'from_utc'=>$from,'to_utc'=>$to,'persisted'=>isset($result['evaluation_ref']),'reused'=>false];
}

/** Scheduled and materialized identities are independent sources. No arbitrary raw-only journey is invented. */
function journeyBatchWorkers(mysqli $db,int $station,string $date,int $after,int $limit): array {
    $week=['LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO','DOMINGO'];
    $day=$week[(int)(new DateTimeImmutable($date,new DateTimeZone('UTC')))->format('N')-1];
    $sql="SELECT id_personal FROM (
        SELECT id_personal FROM op_rh_v2_turnos_asignaciones
        WHERE id_estacion=? AND dia_semana=? AND valido_desde<=? AND (valido_hasta IS NULL OR valido_hasta>?)
          AND (es_activo=1 OR (es_activo=0 AND valido_hasta IS NOT NULL AND valido_hasta>valido_desde)) AND id_personal>?
        UNION
        SELECT id_personal FROM op_rh_v2_jornadas_programadas WHERE id_estacion=? AND fecha_jornada_local=? AND id_personal>?
    ) candidates ORDER BY id_personal LIMIT ?";
    return array_map(static fn($row)=>(int)$row['id_personal'],journeyBatchRows($db,$sql,'isssiisii',[$station,$day,$date,$date,$after,$station,$date,$after,$limit]));
}

function journeyBatchBounds(mysqli $db,array $stations,?string $from): array {
    $tz=new WorkplaceTimezoneAuthorityService($db); $bounds=[];
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    foreach ($stations as $station) {
        // Determine the local date from effective authority, including a UTC date boundary.
        $matching=[];
        foreach ([-1,0,1] as $delta) {
            $date=$now->modify(sprintf('%+d days',$delta))->format('Y-m-d');
            try { $zone=$tz->resolve($station,$date); }
            catch (RuntimeException $e) { if ($e->getMessage()!=='WORKPLACE_TIMEZONE_UNRESOLVED') throw $e; continue; }
            if ($now->setTimezone(new DateTimeZone($zone))->format('Y-m-d')===$date) $matching[]=$date;
        }
        if (count($matching)!==1) throw new RuntimeException('BATCH_LOCAL_DATE_UNAVAILABLE');
        $to=$matching[0];
        $rows=journeyBatchRows($db,'SELECT MIN(d) AS first_date FROM (SELECT MIN(valido_desde) AS d FROM op_rh_v2_turnos_asignaciones WHERE id_estacion=? AND (es_activo=1 OR (es_activo=0 AND valido_hasta IS NOT NULL AND valido_hasta>valido_desde)) UNION ALL SELECT MIN(fecha_jornada_local) FROM op_rh_v2_jornadas_programadas WHERE id_estacion=?) dates','ii',[$station,$station]);
        $first=$rows[0]['first_date']??null;
        if ($first===null) continue;
        $start=max($first,JourneyBatchProcessor::FLOOR,$from??JourneyBatchProcessor::FLOOR);
        if ($start<=$to) $bounds[]=['station'=>$station,'from'=>$start,'to'=>$to];
    }
    return $bounds;
}


/** One candidate uses the same production path in the CLI and integration tests. */
function journeyBatchProcessScope(mysqli $db, array $scope, bool $execute, ?array $coverageAuthorizationContext): array {
    ['worker'=>$w,'station'=>$s,'date'=>$d]=$scope;
    if (!$execute) {
        $preview=journeyProcessorDryRun($db,$w,$s,$d,$coverageAuthorizationContext);
        $decision=JourneyBatchProcessor::decision($preview);
        return $decision+['level2'=>JourneyBatchProcessor::level2Status($preview['level2']??$preview['plan']['level2_status']??null),'read_only'=>true];
    }
    require_once __DIR__.'/../modelo/v2/journey/JourneySharedExecutionService.php';
    // Level 2 evaluation and J1/J2 execute under the same existing
    // worker lock. The shared service revalidates its plan before writes.
    $level2=null;
    $result=(new JourneySharedExecutionService($db))->evaluate($w,$s,$d,true,
        static function(int $worker,int $workplace,string $date)use($db,&$level2):void {
            $level2=JourneyBatchProcessor::validateLevel2BeforeExecution(journeyBatchEvaluateLevel2($db,$worker,$workplace,$date));
        },$coverageAuthorizationContext,
        static function(int $worker,int $workplace,string $date)use($db,$coverageAuthorizationContext):void {
            JourneyBatchProcessor::requireExecutablePreview(journeyProcessorDryRun($db,$worker,$workplace,$date,$coverageAuthorizationContext));
        });
    return ['status'=>'EXECUTED','reason'=>'SHARED_JOURNEY_EXECUTION','j1_algorithm'=>$result['pairing']['algorithm_version']??$result['pairing']['algorithm']??null,'level2'=>($level2['skipped']??false)?null:JourneyBatchProcessor::level2Status($level2),'level2_reason'=>$level2['reason']??null,'level2_evaluation_ref'=>$level2['evaluation_ref']??null,'level2_reused'=>$level2['reused']??false,'provisional'=>($level2['provisional']??false)||(($result['pairing']['pairing_status']??null)==='PENDING_SYNC_COMPLETION'),'pairing_id'=>$result['pairing']['pairing_id'],'shift_id'=>$result['shift']['id']];
}


/** Execute a batch page after the caller has authenticated the runtime and database. */
function journeyBatchRunCycle(mysqli $db, array $config, array $stations, ?string $from, bool $execute, bool $isPreprod, ?JourneyBatchCheckpoint $checkpoint): int {
    try {
        $state=$checkpoint?->load();
        if (!$db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !$db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')) throw new RuntimeException('BATCH_SNAPSHOT_UNAVAILABLE');
        try {
            if ($state===null) $state=['cursor'=>JourneyBatchProcessor::start(journeyBatchBounds($db,$stations,$from)),'cycle'=>1,'blocked'=>0,'ready'=>0];
            elseif (($state['cursor']??null)===null) $state=['cursor'=>JourneyBatchProcessor::start(journeyBatchBounds($db,$stations,$from)),'cycle'=>(int)$state['cycle']+1,'blocked'=>0,'ready'=>0];
            // Checkpoint is local to this authorized scope. Validate every cursor row before SQL.
            $cursor=$state['cursor'];
            if (!is_array($cursor['bounds']??null)||!is_int($cursor['index']??null)||!is_int($cursor['worker']??null)||$cursor['worker']<0||($cursor['bounds']&&(!isset($cursor['bounds'][$cursor['index']])||!JourneyBatchProcessor::dateValid($cursor['date']??null)))) throw new RuntimeException('BATCH_STATE_INVALID');
            foreach ($cursor['bounds'] as $b) if (!in_array($b['station']??null,$stations,true)||!JourneyBatchProcessor::dateValid($b['from']??null)||!JourneyBatchProcessor::dateValid($b['to']??null)||$b['from']>$b['to']||($from!==null&&$b['from']<$from)) throw new RuntimeException('BATCH_STATE_INVALID');
            $page=JourneyBatchProcessor::page($cursor,fn($s,$d,$w,$n)=>journeyBatchWorkers($db,$s,$d,$w,$n));
        } finally { $db->rollback(); }
        $coverageAuthorizationContext=['principal_type'=>'SERVICE','service_id'=>(string)$config['service_id'],'system_uid'=>posix_geteuid(),'stations'=>$stations,'preprod_verified'=>$isPreprod];
        $results=JourneyBatchProcessor::process($page['items'],fn($scope)=>journeyBatchProcessScope($db,$scope,$execute,$coverageAuthorizationContext));
        $blocked=count(array_filter($results,static fn($r)=>in_array($r['status'],['BLOCKED','RETRY_NEXT_CYCLE'],true)));
        $retry=count(array_filter($results,static fn($r)=>$r['status']==='RETRY_NEXT_CYCLE'));
        $state['cursor']=$page['next']; $state['blocked']=(int)$state['blocked']+$blocked;
        $state['ready']=(int)($state['ready']??0)+count(array_filter($results,static fn($r)=>$r['status']==='READY'));
        // Save only after the page was attempted. Crash before save replays the page;
        // failed identities are revisited on the next recurring full reconciliation.
        if ($checkpoint!==null) $checkpoint->save($state);
        echo json_encode(['mode'=>$execute?'batch-execute':'batch-dry-run','service_id'=>$config['service_id'],'operation'=>$execute?'EXECUTION':'READ_ONLY','database_writes_enabled'=>$execute,'cycle'=>$state['cycle'],'scan_cycle_complete'=>$page['next']===null,'complete'=>$page['next']===null&&$state['blocked']===0&&$state['ready']===0,'processed'=>count($results),'blocked_in_cycle'=>$state['blocked'],'ready_in_cycle'=>$state['ready'],'retry_next_cycle'=>$retry,'local_checkpoint_saved'=>$checkpoint!==null,'next_cursor'=>$page['next']===null?null:array_intersect_key($page['next'],array_flip(['date','index','worker'])),'limitations'=>['PERIODIC_RECONCILIATION_NOT_IMMEDIATE_CHANGE_FEED','V1_0_AND_NON_SCHEDULED_AUTOMATIC_WRITES_NOT_VERIFIED'],'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
        return $retry>0 || ($execute&&$blocked>0) ? 2 : 0;
    } finally { if ($checkpoint!==null) $checkpoint->close(); }
}

function journeyBatchMain(array $opts,int $argc): int {
    if ($argc!==3 || count($opts)!==2 || !isset($opts['config']) || (isset($opts['batch-dry-run'])===isset($opts['batch-execute'])) || !function_exists('posix_geteuid')) throw new RuntimeException('INVALID_BATCH_OPTIONS');
    $execute=isset($opts['batch-execute']);
    $path=$opts['config']; $real=is_string($path)&&str_starts_with($path,'/')&&!is_link($path)?realpath($path):false;
    $repo=realpath(dirname(__DIR__)); $stat=$real?stat($real):false;
    if (!$stat || !is_file($real) || str_starts_with($real,$repo.'/') || ($stat['mode']&0077)!==0 || !in_array($stat['uid'],[0,posix_geteuid()],true)) throw new RuntimeException('INVALID_CONFIG_PERMISSIONS');
    $config=json_decode((string)file_get_contents($real),true,32,JSON_THROW_ON_ERROR);
    if (!is_array($config)) throw new RuntimeException('SERVICE_SCOPE_DENIED');
    require_once __DIR__.'/JourneyEnvironmentProfile.php';
    JourneyEnvironmentProfile::validate($config,posix_geteuid());
    $stations=$config['stations']; foreach ($stations as $station) if (!is_int($station)||$station<1) throw new RuntimeException('SERVICE_SCOPE_DENIED');
    $stations=array_values(array_unique($stations)); sort($stations,SORT_NUMERIC);
    $from=$config['batch_from_local_date']??null;
    if ($from!==null&&!JourneyBatchProcessor::dateValid($from)) throw new RuntimeException('INVALID_BATCH_START_DATE');
    // No deployment or write authorization implied by presence of the command.
    if ($execute && (($config['execution_enabled']??false)!==true || ($config['batch_execution_enabled']??false)!==true || $from===null || getenv('CHECADOR_JOURNEY_CLI_EXECUTION_ENABLED')!=='1' || getenv('CHECADOR_J1_V11_PERSISTENCE_ENABLED')!=='1' || getenv('CHECADOR_JOURNEY_BATCH_EXECUTION_ENABLED')!=='1')) throw new RuntimeException('BATCH_EXECUTION_DISABLED');
    // Retain the existing local database boundary until deployment is authorized.
    $isPreprod=($config['environment']??'development')==='preprod';
    if (!$isPreprod && (!in_array(getenv('DB_HOST'),['localhost','127.0.0.1','::1'],true)||getenv('DB_NAME')!=='bd_admongas')) throw new RuntimeException('DATABASE_SCOPE_DENIED');
    require_once __DIR__.'/../modelo/BD.php';
    require_once __DIR__.'/../modelo/v2/shift/ScheduleResolutionService.php';
    require_once __DIR__.'/../modelo/v2/shift/WorkplaceTimezoneAuthorityService.php';
    if ($isPreprod) {
        [$db,$hostIdentity,$identity]=JourneyEnvironmentProfile::connectPreprod($config,$execute?'batch_write':'dry_run');
        $key=JourneyEnvironmentProfile::proposedPreprodCheckpointKey(['environment'=>'preprod','server_identity'=>$hostIdentity,'database'=>$identity['db'],'service_id'=>$config['service_id'],'sql_account'=>$identity['account'],'scope'=>json_encode(['stations'=>$stations,'from'=>$from,'mode'=>$execute?'execute':'dry-run'],JSON_THROW_ON_ERROR)]);
    } else {
        $db=(new BD())->conectarBD();
        $identity=$db->query('SELECT DATABASE() AS db')->fetch_assoc();
        if (($identity['db']??null)!=='bd_admongas') throw new RuntimeException('DATABASE_SCOPE_DENIED');
        $key=hash('sha256',json_encode(['version'=>1,'config'=>$real,'service'=>$config['service_id'],'stations'=>$stations,'from'=>$from,'database'=>$identity['db'],'mode'=>$execute?'execute':'dry-run'],JSON_THROW_ON_ERROR));
    }
    JourneyEnvironmentProfile::requireUtcSession($db);
    // Dry-run must not create or update local checkpoint/lock files. It is a
    // read-only snapshot and returns its next cursor for explicit inspection.
    $checkpoint=$execute?new JourneyBatchCheckpoint(dirname($real),$key):null;
    try { return journeyBatchRunCycle($db,$config,$stations,$from,$execute,$isPreprod,$checkpoint); }
    finally { $db->close(); }
}
