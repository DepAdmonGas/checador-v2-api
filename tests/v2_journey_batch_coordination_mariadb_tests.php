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
$db->afterLevel2Insert=function()use($other,$name,$aid,&$observed){$stmt=$other->prepare('SELECT IS_USED_LOCK(?) n');$stmt->bind_param('s',$name);$stmt->execute();$owner=(int)$stmt->get_result()->fetch_assoc()['n'];if($owner!==$aid)throw new RuntimeException('TEST_LOCK_NOT_OWNED');$row=$other->query('SELECT * FROM op_rh_v2_level2_window_evaluations ORDER BY id DESC LIMIT 1')->fetch_assoc();if(!$row||$row['window_level2_status']!=='VERIFIED')throw new RuntimeException('TEST_COMMITTED_LEVEL2_NOT_VISIBLE');$other->begin_transaction();$other->query('UPDATE op_rh_personal SET estado=0 WHERE id=990001 AND estado=1');if($other->affected_rows!==1)throw new RuntimeException('TEST_DEACTIVATION_FAILED');$other->commit();$observed=$row;};
putenv('CHECADOR_J1_V11_PERSISTENCE_ENABLED=1');putenv('CHECADOR_JOURNEY_CLI_EXECUTION_ENABLED=1');$key=hash('sha256','coordination-t11');$cp=new JourneyBatchCheckpoint($root,$key);ob_start();$code=journeyBatchRunCycle($db,['service_id'=>'journey-local'],[$station],$date,true,false,$cp);$out=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);$cp=new JourneyBatchCheckpoint($root,$key);$state=$cp->load();$cp->close();
$after=$db->query('SELECT * FROM op_rh_v2_level2_window_evaluations ORDER BY id DESC LIMIT 1')->fetch_assoc();$counts=$db->query('SELECT (SELECT COUNT(*) FROM op_rh_v2_jornadas_programadas) shifts,(SELECT COUNT(*) FROM op_rh_v2_pareos_jornada) j1,(SELECT COUNT(*) FROM op_rh_v2_calculos_jornada) j2,(SELECT COUNT(*) FROM op_rh_v2_level2_window_evaluations) l2,(SELECT estado FROM op_rh_personal WHERE id=990001) worker_state')->fetch_assoc();$q=$db->prepare('SELECT IS_FREE_LOCK(?) n');$q->bind_param('s',$name);$q->execute();$free=(int)$q->get_result()->fetch_assoc()['n'];
$ok=$observed!==null&&$observed===$after&&array_values($counts)===['0','0','0','1','0']&&$free===1&&$code===2&&($out['results'][0]['reason']??null)==='JOURNEY_EXECUTION_POLICY_PREFLIGHT'&&($state['blocked']??0)===1;
echo 'HARNESS_READBACK '.json_encode(['connections'=>[$aid,$bid],'preview'=>$preview,'fixture'=>$fixture,'batch'=>$out,'evidence'=>$after,'evidence_unchanged'=>$observed===$after,'counts'=>$counts,'checkpoint'=>$state,'lock_free'=>$free]).PHP_EOL;echo $ok?"T10_T11_BATCH PASS\n":"T10_T11_BATCH FAIL\n";exit($ok?0:1);
