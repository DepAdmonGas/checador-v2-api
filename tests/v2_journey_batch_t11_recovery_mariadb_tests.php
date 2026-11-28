<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/test_mariadb_isolation_guard.php';
TestMariaDbIsolationGuard::requireExplicitAuthorization();
$socket=getenv('JOURNEY_BATCH_TEST_SOCKET');
if(!is_string($socket)||!preg_match('~^/tmp/checador-batch-validation-[A-Za-z0-9]+/mysql\.sock$~D',$socket)||is_link($socket))throw new RuntimeException('ISOLATED_SOCKET_REQUIRED');
$root=realpath(dirname($socket)); if($root!==dirname($socket))throw new RuntimeException('ISOLATED_ROOT_REQUIRED');
require_once __DIR__.'/journey_batch_coordination_harness.php';
$db=new JourneyHarnessMysqli();$db->real_connect('localhost','root','','checador_batch_isolated_v1',0,$socket);
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

require_once __DIR__.'/../modelo/v2/journey/JourneyMariaDbLock.php';
$physical=(int)$db->query("SELECT id FROM tb_estaciones WHERE numlista=991001")->fetch_assoc()['id'];$fixture=evidence($service,$db,$physical,$from,$to,'HARNESS');
$other=new mysqli('localhost','root','','checador_batch_isolated_v1',0,$socket);$aid=(int)$db->query('SELECT CONNECTION_ID() n')->fetch_assoc()['n'];$bid=(int)$other->query('SELECT CONNECTION_ID() n')->fetch_assoc()['n'];if($aid===$bid)throw new RuntimeException('CONNECTIONS_NOT_DISTINCT');
$preview=JourneyBatchProcessor::decision(journeyProcessorDryRun($db,990001,$station,$date));if(($preview['status']??null)!=='READY')throw new RuntimeException('TEST_NOT_READY');
$name=substr(JourneyExecutionCoordinator::lockName(990001,$station,$date),0,64);$observed=null;

$observed=null;
$db->afterLevel2Insert=function()use($other,$name,$aid,&$observed,$db,$socket){$q=$other->prepare('SELECT IS_USED_LOCK(?) n');$q->bind_param('s',$name);$q->execute();if((int)$q->get_result()->fetch_assoc()['n']!==$aid)throw new RuntimeException('TEST_LOCK_NOT_OWNED');$observed=$other->query('SELECT * FROM op_rh_v2_level2_window_evaluations ORDER BY id DESC LIMIT 1')->fetch_assoc();if(!$observed||$observed['window_level2_status']!=='VERIFIED')throw new RuntimeException('TEST_COMMITTED_LEVEL2_NOT_VISIBLE');TestMariaDbIsolationGuard::killVerifiedConnection($other,$db,$socket);};
putenv('CHECADOR_J1_V11_PERSISTENCE_ENABLED=1');putenv('CHECADOR_JOURNEY_CLI_EXECUTION_ENABLED=1');$key=hash('sha256','coordination-recovery');$cp=new JourneyBatchCheckpoint($root,$key);ob_start();$error=null;try{$rc=journeyBatchRunCycle($db,['service_id'=>'journey-local'],[$station],$date,true,false,$cp);}catch(Throwable $e){$error=$e->getMessage();$rc=-1;}$first=json_decode(ob_get_clean(),true);$cp=new JourneyBatchCheckpoint($root,$key);$state1=$cp->load();$cp->close();
$name=substr(JourneyExecutionCoordinator::lockName(990001,$station,$date),0,64);$q=$other->prepare('SELECT IS_FREE_LOCK(?) n');$q->bind_param('s',$name);$q->execute();$free=(int)$q->get_result()->fetch_assoc()['n'];$counts=$other->query('SELECT (SELECT COUNT(*) FROM op_rh_v2_jornadas_programadas) shifts,(SELECT COUNT(*) FROM op_rh_v2_pareos_jornada) j1,(SELECT COUNT(*) FROM op_rh_v2_calculos_jornada) j2,(SELECT COUNT(*) FROM op_rh_v2_level2_window_evaluations) l2')->fetch_assoc();$current=$other->query('SELECT * FROM op_rh_v2_level2_window_evaluations ORDER BY id LIMIT 1')->fetch_assoc();echo 'FIRST_READBACK '.json_encode(['connection_killed'=>$aid,'first'=>$first,'error'=>$error,'checkpoint'=>$state1,'counts'=>$counts,'level2_unchanged'=>$observed===$current,'lock_free'=>$free]).PHP_EOL;
if(!$observed||$observed!==$current||array_values($counts)!==['0','0','0','1']||$free!==1)throw new RuntimeException('T11_FAILURE_PRECONDITION_FAILED');
$next=new JourneyHarnessMysqli();$next->real_connect('localhost','root','','checador_batch_isolated_v1',0,$socket);$next->query("SET SESSION time_zone='+00:00'");$cp=new JourneyBatchCheckpoint($root,$key);ob_start();$rc2=journeyBatchRunCycle($next,['service_id'=>'journey-local'],[$station],$date,true,false,$cp);$second=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);$cp=new JourneyBatchCheckpoint($root,$key);$state2=$cp->load();$cp->close();
$cp=new JourneyBatchCheckpoint($root,$key);ob_start();$rc3=journeyBatchRunCycle($next,['service_id'=>'journey-local'],[$station],$date,true,false,$cp);$third=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);$cp=new JourneyBatchCheckpoint($root,$key);$state3=$cp->load();$cp->close();
$after=$next->query('SELECT (SELECT COUNT(*) FROM op_rh_v2_jornadas_programadas) shifts,(SELECT COUNT(*) FROM op_rh_v2_pareos_jornada) j1,(SELECT COUNT(*) FROM op_rh_v2_calculos_jornada) j2,(SELECT COUNT(*) FROM op_rh_v2_level2_window_evaluations) l2')->fetch_assoc();$old=$next->query('SELECT * FROM op_rh_v2_level2_window_evaluations ORDER BY id LIMIT 1')->fetch_assoc();$q=$next->prepare('SELECT IS_FREE_LOCK(?) n');$q->bind_param('s',$name);$q->execute();$free2=(int)$q->get_result()->fetch_assoc()['n'];$link=$next->query('SELECT p.id j1,p.revision_numero j1rev,c.id j2,c.id_pareo_jornada,c.revision_numero j2rev FROM op_rh_v2_pareos_jornada p JOIN op_rh_v2_calculos_jornada c ON c.id_pareo_jornada=p.id')->fetch_assoc();echo 'RECOVERY_READBACK '.json_encode(['second'=>$second,'third'=>$third,'checkpoints'=>[$state1,$state2,$state3],'counts'=>$after,'history_unchanged'=>$old===$observed,'lock_free'=>$free2,'link'=>$link]).PHP_EOL;
$ok=$rc2===0&&$rc3===0&&array_slice(array_values($after),0,3)===['1','1','1']&&$old===$observed&&$free2===1&&(int)$link['j1rev']===1&&(int)$link['j2rev']===1&&(int)$link['j1']===(int)$link['id_pareo_jornada'];echo $ok?"T11 RECOVERY PASS\n":"T11 RECOVERY FAIL\n";exit($ok?0:1);
