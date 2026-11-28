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

$physical=(int)$db->query("SELECT id FROM tb_estaciones WHERE numlista=991001")->fetch_assoc()['id'];$fixture=evidence($service,$db,$physical,$from,$to,'T14');
putenv('CHECADOR_J1_V11_PERSISTENCE_ENABLED=1');putenv('CHECADOR_JOURNEY_CLI_EXECUTION_ENABLED=1');$db->close();unset($db,$service);
class T14Db extends mysqli { public ?Closure $onLock=null; public function prepare(string $query): mysqli_stmt|false {return new T14Stmt($this,$query);} }
class T14Stmt extends mysqli_stmt {private T14Db $db;private bool $lockQuery;public function __construct(T14Db $db,string $query){$this->db=$db;$this->lockQuery=str_contains($query,'GET_LOCK(?, 0)');parent::__construct($db,$query);}public function execute(?array $params=null):bool{$ok=parent::execute($params);if($ok&&$this->lockQuery&&$this->db->onLock!==null){$f=$this->db->onLock;$this->db->onLock=null;$f();}return $ok;}}
mkdir($root.'/shared',0700);$key=hash('sha256','t14-scope');$ipc=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);if($ipc===false)throw new RuntimeException('IPC_FAILED');$pid=pcntl_fork();if($pid<0)throw new RuntimeException('FORK_FAILED');
if($pid===0){fclose($ipc[0]);$a=new T14Db();$a->real_connect('localhost','root','','checador_batch_isolated_v1',0,$socket);$a->query("SET SESSION time_zone='+00:00'");$aId=(int)$a->query('SELECT CONNECTION_ID() n')->fetch_assoc()['n'];$name=substr(JourneyExecutionCoordinator::lockName(990001,$station,$date),0,64);$observer=new mysqli('localhost','root','','checador_batch_isolated_v1',0,$socket);$a->onLock=function()use($observer,$ipc,$aId,$name){$q=$observer->prepare('SELECT IS_USED_LOCK(?) n');$q->bind_param('s',$name);$q->execute();if((int)$q->get_result()->fetch_assoc()['n']!==$aId)throw new RuntimeException('A_NOT_OWNER');fwrite($ipc[1],json_encode(['pid'=>getmypid(),'connection'=>$aId,'lock'=>$name])."\n");if(trim((string)fgets($ipc[1]))!=='RESUME')throw new RuntimeException('IPC_CANCELLED');};$cp=new JourneyBatchCheckpoint($root.'/shared',$key);ob_start();$rc=journeyBatchRunCycle($a,['service_id'=>'journey-local'],[$station],$date,true,false,$cp);$out=json_decode(ob_get_clean(),true);fwrite($ipc[1],json_encode(['exit'=>$rc,'batch'=>$out])."\n");fclose($ipc[1]);exit($rc);}
fclose($ipc[1]);$aInfo=json_decode((string)fgets($ipc[0]),true,512,JSON_THROW_ON_ERROR);$b=new mysqli('localhost','root','','checador_batch_isolated_v1',0,$socket);$b->query("SET SESSION time_zone='+00:00'");$bid=(int)$b->query('SELECT CONNECTION_ID() n')->fetch_assoc()['n'];$q=$b->prepare('SELECT IS_USED_LOCK(?) n');$q->bind_param('s',$aInfo['lock']);$q->execute();$owner=(int)$q->get_result()->fetch_assoc()['n'];if($owner!==$aInfo['connection']||$bid===$owner)throw new RuntimeException('LOCK_OWNER_INVALID');
$blocked=false;$reason='';try{$cp=new JourneyBatchCheckpoint($root.'/shared',$key);}catch(RuntimeException $e){$reason=$e->getMessage();$blocked=$reason==='BATCH_ALREADY_RUNNING';}$during=$b->query('SELECT (SELECT COUNT(*) FROM op_rh_v2_jornadas_programadas) shifts,(SELECT COUNT(*) FROM op_rh_v2_pareos_jornada) j1,(SELECT COUNT(*) FROM op_rh_v2_calculos_jornada) j2')->fetch_assoc();$bout=['checkpoint_rejection'=>$reason];if(!$blocked||array_values($during)!==['0','0','0'])throw new RuntimeException('CHECKPOINT_EXCLUSION_FAILED');fwrite($ipc[0],"RESUME\n");$aResult=json_decode((string)fgets($ipc[0]),true,512,JSON_THROW_ON_ERROR);pcntl_waitpid($pid,$status);if(($aResult['exit']??1)!==0)throw new RuntimeException('A_EXECUTION_FAILED');
$cp=new JourneyBatchCheckpoint($root.'/shared',$key);ob_start();$rrc=journeyBatchRunCycle($b,['service_id'=>'journey-local'],[$station],$date,true,false,$cp);$retry=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);$counts=$b->query('SELECT (SELECT COUNT(*) FROM op_rh_v2_jornadas_programadas) shifts,(SELECT COUNT(*) FROM op_rh_v2_pareos_jornada) j1,(SELECT COUNT(*) FROM op_rh_v2_calculos_jornada) j2')->fetch_assoc();$link=$b->query('SELECT p.id j1,p.revision_numero r1,c.id j2,c.id_pareo_jornada,c.revision_numero r2 FROM op_rh_v2_pareos_jornada p JOIN op_rh_v2_calculos_jornada c ON c.id_pareo_jornada=p.id')->fetch_assoc();$q=$b->prepare('SELECT IS_FREE_LOCK(?) n');$q->bind_param('s',$aInfo['lock']);$q->execute();$free=(int)$q->get_result()->fetch_assoc()['n'];$cpa=new JourneyBatchCheckpoint($root.'/shared',$key);$astate=$cpa->load();$cpa->close();$cpb=new JourneyBatchCheckpoint($root.'/shared',$key);$bstate=$cpb->load();$cpb->close();
echo 'T14A_READBACK '.json_encode(['process_a'=>$aInfo,'pid_b'=>getmypid(),'connection_b'=>$bid,'owner'=>$owner,'batch_b'=>$bout,'during'=>$during,'batch_a'=>$aResult,'retry_b'=>$retry,'counts'=>$counts,'link'=>$link,'checkpoint_a'=>$astate,'checkpoint_b'=>$bstate,'lock_free'=>$free]).PHP_EOL;
$ok=$rrc===0&&array_values($counts)===['1','1','1']&&$free===1&&(int)$link['r1']===1&&(int)$link['r2']===1&&(int)$link['j1']===(int)$link['id_pareo_jornada'];echo $ok?"T14A PASS\n":"T14A FAIL\n";exit($ok?0:1);
