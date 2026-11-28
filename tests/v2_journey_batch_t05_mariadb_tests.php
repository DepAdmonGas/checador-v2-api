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
$db->query("INSERT INTO op_rh_v2_attendance_policy_versions(workplace_id,policy_version,effective_from_local_date,timezone_id,retardo_tolerance_minutes,incidence_deadline_days,actor_portal_user_id,source,idempotency_key,early_entry_allowed_minutes,duplicate_punch_window_minutes,exit_priority_window_minutes) VALUES($station,1,'2026-01-01','UTC',5,1,1,'TEST','synthetic-t01',NULL,NULL,NULL)");
$db->query("INSERT INTO op_rh_v2_turnos_plantillas(clave,version,nombre,tipo_turno) VALUES('T01',1,'Synthetic T01','REGULAR')");$template=(int)$db->insert_id;
$db->query("INSERT INTO op_rh_v2_turnos_segmentos(id_plantilla,ordinal,tipo_segmento,hora_inicio_local,hora_fin_local,dia_offset_inicio,dia_offset_fin) VALUES($template,1,'WORK','08:00:00','09:00:00',0,0)");
$db->query("INSERT INTO op_rh_v2_turnos_asignaciones(id_personal,id_estacion,id_plantilla,tipo_asignacion,dia_semana,valido_desde,es_activo) VALUES(990001,$station,$template,'HABITUAL','$weekday','$date',1)");
$scope=['worker'=>990001,'station'=>$station,'date'=>$date];
$before=$db->query('SELECT (SELECT COUNT(*) FROM op_rh_v2_jornadas_programadas) shifts,(SELECT COUNT(*) FROM op_rh_v2_pareos_jornada) j1,(SELECT COUNT(*) FROM op_rh_v2_calculos_jornada) j2')->fetch_assoc();


$preview=journeyProcessorDryRun($db,990001,$station,$date);$policy=$db->query("SELECT early_entry_allowed_minutes,duplicate_punch_window_minutes,exit_priority_window_minutes FROM op_rh_v2_attendance_policy_versions WHERE workplace_id=$station")->fetch_assoc();
$tables=['op_rh_v2_jornadas_programadas','op_rh_v2_pareos_jornada','op_rh_v2_calculos_jornada','op_rh_v2_level2_window_evaluations'];$snap=static function()use($db,$tables){$r=[];foreach($tables as $t)$r[$t]=$db->query("SELECT * FROM `$t` ORDER BY id")->fetch_all(MYSQLI_ASSOC);return $r;};$before=$snap();
putenv('CHECADOR_J1_V11_PERSISTENCE_ENABLED=1');putenv('CHECADOR_JOURNEY_CLI_EXECUTION_ENABLED=1');$cp=new JourneyBatchCheckpoint($root,hash('sha256','isolated-t05'));ob_start();$rc=journeyBatchRunCycle($db,['service_id'=>'journey-local'],[$station],$date,true,false,$cp);$out=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);$after=$snap();$cp=new JourneyBatchCheckpoint($root,hash('sha256','isolated-t05'));$state=$cp->load();$cp->close();$lockName=substr(JourneyExecutionCoordinator::lockName(990001,$station,$date),0,64);$q=$db->prepare('SELECT IS_FREE_LOCK(?) n');$q->bind_param('s',$lockName);$q->execute();$free=(int)$q->get_result()->fetch_assoc()['n'];
$ok=($preview['schedule_status']??null)==='SCHEDULED'&&($preview['reason']??null)==='SHIFT_NOT_MATERIALIZED'&&array_values($policy)===[null,null,null]&&$before===$after&&$free===1&&($out['results'][0]['reason']??null)==='JOURNEY_EXECUTION_BATCH_PREFLIGHT'&&($state['blocked']??0)===1;
echo 'T05_READBACK '.json_encode(['policy'=>$policy,'preview'=>$preview,'batch'=>$out,'counts'=>array_map('count',$after),'checkpoint'=>$state,'lock_free'=>$free]).PHP_EOL;echo $ok?"T05 PASS\n":"T05 FAIL\n";exit($ok?0:1);
