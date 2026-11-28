<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/test_mariadb_isolation_guard.php';
TestMariaDbIsolationGuard::requireExplicitAuthorization();
$socket=getenv('JOURNEY_BATCH_TEST_SOCKET');
if(!is_string($socket)||!preg_match('~^/tmp/checador-batch-validation-[A-Za-z0-9]+/mysql\.sock$~D',$socket)||is_link($socket))throw new RuntimeException('ISOLATED_SOCKET_REQUIRED');
$root=realpath(dirname($socket)); if($root!==dirname($socket))throw new RuntimeException('ISOLATED_ROOT_REQUIRED');
$db=mysqli_init();$db->real_connect('localhost','root','','checador_batch_isolated_v1',0,$socket);
$id=$db->query('SELECT DATABASE() db,@@datadir datadir,@@socket socket,@@port port')->fetch_assoc();
if($id['db']!=='checador_batch_isolated_v1'||realpath($id['datadir'])!==$root.'/data'||$id['socket']!==$socket||(int)$id['port']!==0||!str_contains($db->host_info,'socket'))throw new RuntimeException('ISOLATION_VERIFICATION_FAILED');
TestMariaDbIsolationGuard::assertConnection($db, $socket);
foreach(['op_rh_localidades','tb_estaciones','op_rh_v2_jornadas_programadas','op_rh_v2_attendance_policy_versions','op_rh_personal'] as $table)if((int)$db->query("SELECT COUNT(*) n FROM `$table`")->fetch_assoc()['n']!==0)throw new RuntimeException('BASELINE_MUST_BE_EMPTY');
require_once __DIR__.'/../scripts/JourneyBatchProcessor.php';
require_once __DIR__.'/../scripts/journey_batch_runtime.php';
require_once __DIR__.'/../modelo/v2/shift/ScheduleResolutionService.php';
require_once __DIR__.'/../modelo/v2/shift/WorkplaceTimezoneAuthorityService.php';
require_once __DIR__.'/../modelo/v2/shift/WorkplaceCoverageFeatureGate.php';
require_once __DIR__.'/../modelo/v2/journey/JourneyExecutionCoordinator.php';
$db->query("SET SESSION time_zone='+00:00'"); date_default_timezone_set('UTC');
$date=gmdate('Y-m-d',time()-86400);$weekday=['LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO','DOMINGO'][(int)gmdate('N',time()-86400)-1];
$db->query("INSERT INTO tb_estaciones(numlista,nombre,estatus) VALUES(991001,'Synthetic T01',0)");
$db->query("INSERT INTO op_rh_localidades(numlista,localidad,recuperacion_vapores) VALUES(991001,'Synthetic T01','')");$station=(int)$db->insert_id;
$db->query("INSERT INTO op_rh_personal(id,id_estacion,nombre_completo,estado) VALUES(990001,$station,'Synthetic worker',1)");
$db->query("INSERT INTO op_rh_personal_localidad_version(worker_id,workplace_id,valid_from_utc,recorded_at_utc) VALUES(990001,$station,'2026-01-01 00:00:00','2026-01-01 00:00:00')");
$db->query("INSERT INTO op_rh_v2_localidades_zonas_horarias(id_localidad,timezone_iana,valido_desde) VALUES($station,'UTC','2026-01-01')");
$db->query("INSERT INTO op_rh_v2_attendance_policy_versions(workplace_id,policy_version,effective_from_local_date,timezone_id,retardo_tolerance_minutes,incidence_deadline_days,actor_portal_user_id,source,idempotency_key,early_entry_allowed_minutes,duplicate_punch_window_minutes,exit_priority_window_minutes) VALUES($station,1,'2026-01-01','UTC',5,1,1,'TEST','synthetic-t01',10,5,60)");
$db->query("INSERT INTO op_rh_v2_turnos_plantillas(clave,version,nombre,tipo_turno) VALUES('T01',1,'Synthetic T01','REGULAR')");$template=(int)$db->insert_id;
$db->query("INSERT INTO op_rh_v2_turnos_segmentos(id_plantilla,ordinal,tipo_segmento,hora_inicio_local,hora_fin_local,dia_offset_inicio,dia_offset_fin) VALUES($template,1,'WORK','08:00:00','09:00:00',0,0)");
$db->query("INSERT INTO op_rh_v2_turnos_asignaciones(id_personal,id_estacion,id_plantilla,tipo_asignacion,dia_semana,valido_desde,es_activo) VALUES(990001,$station,$template,'HABITUAL','$weekday','$date',1)");
$scope=['worker'=>990001,'station'=>$station,'date'=>$date];
$before=$db->query('SELECT (SELECT COUNT(*) FROM op_rh_v2_jornadas_programadas) shifts,(SELECT COUNT(*) FROM op_rh_v2_pareos_jornada) j1,(SELECT COUNT(*) FROM op_rh_v2_calculos_jornada) j2')->fetch_assoc();

require_once __DIR__.'/../modelo/v2/Level2ServerService.php';
$service=new Level2ServerService();
$from="$date 04:00:00";$to="$date 13:00:00";
function evidence(Level2ServerService $service,mysqli $db,int $physical,string $from,string $to,string $suffix): array {
 $uuid='SYNTH_'.$suffix;$generation='GEN_'.$suffix;$q=$db->prepare('INSERT INTO op_rh_v2_dispositivos(uuid_dispositivo,id_estacion,nombre,estado) VALUES(?,?,?,1)');$name='Synthetic T07';$q->bind_param('sis',$uuid,$physical,$name);$q->execute();$device=(int)$q->insert_id;$q->close();
 if(empty($service->registrarGeneracion($db,$device,$uuid,$generation)['success'])||empty($service->registrarAsignacionEstacion($db,$device,$physical,'2026-01-01 00:00:00')['success']))throw new RuntimeException('T07_DEVICE_FIXTURE_FAILED');
 $policy=$db->query("SELECT max_heartbeat_gap_seconds FROM op_rh_v2_coverage_policies WHERE coverage_policy_ref='POLICY_V1.0_DEFAULT' AND es_activo=1")->fetch_assoc();if(!$policy||(int)$policy['max_heartbeat_gap_seconds']<1)throw new RuntimeException('T07_POLICY_MISSING');
 $step=(int)$policy['max_heartbeat_gap_seconds'];$start=strtotime($from.' UTC');$end=strtotime($to.' UTC');$payload=['application_running'=>true,'sdk_initialized'=>true,'sensor_connected'=>true,'capture_thread_alive'=>true,'queue_writable'=>true];$seq=1;
 for($t=$start;$t<=$end;$t+=$step){$time=gmdate('Y-m-d H:i:s',$t);$r=$service->registrarTelemetria($db,$device,$generation,$seq++,$time,100,'CONNECTED','SYNCED',$payload,$time);if(empty($r['success']))throw new RuntimeException('T07_TELEMETRY_FAILED '.json_encode($r));}
 if(($end-$start)%$step!==0){$time=gmdate('Y-m-d H:i:s',$end);$r=$service->registrarTelemetria($db,$device,$generation,$seq++,$time,100,'CONNECTED','SYNCED',$payload,$time);if(empty($r['success']))throw new RuntimeException('T07_TELEMETRY_FAILED');}
 return ['device'=>$device,'generation'=>$generation,'samples'=>$seq-1,'max_gap'=>$step];
}

require_once __DIR__.'/../modelo/v2/journey/JourneySharedExecutionService.php';
require_once __DIR__.'/journey_runtime_lock_helper.php';
$physical=(int)$db->query("SELECT id FROM tb_estaciones WHERE numlista=991001")->fetch_assoc()['id'];$fixture=evidence($service,$db,$physical,$from,$to,'T11');
$other=new mysqli('localhost','root','','checador_batch_isolated_v1',0,$socket);$a=(int)$db->query('SELECT CONNECTION_ID() n')->fetch_assoc()['n'];$b=(int)$other->query('SELECT CONNECTION_ID() n')->fetch_assoc()['n'];if($a===$b)throw new RuntimeException('CONNECTIONS_NOT_INDEPENDENT');
putenv('CHECADOR_J1_V11_PERSISTENCE_ENABLED=1');putenv('CHECADOR_JOURNEY_CLI_EXECUTION_ENABLED=1');$initial=null;$eval=null;$snapshot=null;$changed=false;
$preflight=function(int $w,int $s,string $d)use($db,&$initial){(new JourneyMariaDbLock($db))->assertOwned($w,$s,$d);$initial=JourneyBatchProcessor::decision(journeyProcessorDryRun($db,$w,$s,$d));if($initial['status']!=='READY')throw new RuntimeException('BATCH_PREFLIGHT_BLOCKED');};
$evaluateAndChange=function(int $w,int $s,string $d)use($db,$other,&$eval,&$snapshot,&$changed){(new JourneyMariaDbLock($db))->assertOwned($w,$s,$d);$eval=journeyBatchEvaluateLevel2($db,$w,$s,$d);if(($eval['window_level2_status']??null)!=='VERIFIED'||empty($eval['evaluation_ref']))throw new RuntimeException('TEST_LEVEL2_NOT_VERIFIED');$stmt=$db->prepare('SELECT * FROM op_rh_v2_level2_window_evaluations WHERE evaluation_ref=?');$stmt->bind_param('s',$eval['evaluation_ref']);$stmt->execute();$snapshot=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$snapshot)throw new RuntimeException('TEST_EVIDENCE_MISSING');$other->begin_transaction();$q=$other->prepare('UPDATE op_rh_personal SET estado=0 WHERE id=? AND estado=1');$q->bind_param('i',$w);$q->execute();if($q->affected_rows!==1)throw new RuntimeException('TEST_DEACTIVATION_FAILED');$other->commit();$changed=true;};
$error=null;try{(new JourneySharedExecutionService($db))->evaluate(990001,$station,$date,true,$evaluateAndChange,null,$preflight);}catch(Throwable $e){$error=['message'=>$e->getMessage(),'cause'=>$e->getPrevious()?->getMessage()];}
$stmt=$db->prepare('SELECT * FROM op_rh_v2_level2_window_evaluations WHERE evaluation_ref=?');$stmt->bind_param('s',$eval['evaluation_ref']);$stmt->execute();$after=$stmt->get_result()->fetch_assoc();$counts=$db->query('SELECT (SELECT COUNT(*) FROM op_rh_v2_jornadas_programadas) shifts,(SELECT COUNT(*) FROM op_rh_v2_pareos_jornada) j1,(SELECT COUNT(*) FROM op_rh_v2_calculos_jornada) j2,(SELECT COUNT(*) FROM op_rh_v2_level2_window_evaluations) l2')->fetch_assoc();$name=substr(JourneyExecutionCoordinator::lockName(990001,$station,$date),0,64);$q=$db->prepare('SELECT IS_FREE_LOCK(?) n');$q->bind_param('s',$name);$q->execute();$free=(int)$q->get_result()->fetch_assoc()['n'];
echo 'T11_READBACK '.json_encode(['connections'=>[$a,$b],'initial'=>$initial,'evaluation'=>$eval,'evidence_unchanged'=>$snapshot===$after,'worker_changed'=>$changed,'service_error'=>$error,'counts'=>$counts,'lock_free'=>$free]).PHP_EOL;
$ok=$changed&&($initial['status']??null)==='READY'&&($eval['window_level2_status']??null)==='VERIFIED'&&$snapshot===$after&&($error['message']??null)==='JOURNEY_EXECUTION_POLICY_PREFLIGHT'&&str_starts_with($error['cause']??'','WORKER_MEMBERSHIP_UNVERIFIED')&&array_values($counts)===['0','0','0','1']&&$free===1;echo $ok?"T11 SERVICE PASS (batch/checkpoint not exercised)\n":"T11 FAIL\n";exit($ok?0:1);
