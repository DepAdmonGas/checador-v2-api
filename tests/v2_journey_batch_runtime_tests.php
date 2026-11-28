<?php
declare(strict_types=1);
/** Integration test: ONLY a network-disabled, explicitly isolated /tmp MariaDB. */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/test_mariadb_isolation_guard.php';
TestMariaDbIsolationGuard::requireExplicitAuthorization();
$socket = getenv('JOURNEY_BATCH_TEST_SOCKET');
if (!is_string($socket) || !preg_match('~^/tmp/checador-batch-validation-[A-Za-z0-9]+/mysql\.sock$~D', $socket) || is_link($socket)) throw new RuntimeException('ISOLATED_SOCKET_REQUIRED');
$root = realpath(dirname($socket));
if ($root !== dirname($socket)) throw new RuntimeException('ISOLATED_ROOT_REQUIRED');
$db = mysqli_init();
$db->real_connect('localhost', 'root', '', 'checador_batch_isolated_v1', 0, $socket);
$identity = $db->query('SELECT DATABASE() AS db, @@datadir AS datadir, @@socket AS socket, @@port AS port')->fetch_assoc();
if ($identity['db'] !== 'checador_batch_isolated_v1' || realpath($identity['datadir']) !== $root.'/data' || $identity['socket'] !== $socket || (int)$identity['port'] !== 0 || !str_contains($db->host_info, 'socket')) throw new RuntimeException('ISOLATION_VERIFICATION_FAILED');
TestMariaDbIsolationGuard::assertConnection($db, $socket);
foreach (['op_rh_localidades','tb_estaciones','op_rh_v2_jornadas_programadas','op_rh_v2_attendance_policy_versions'] as $table) {
    if ((int)$db->query("SELECT COUNT(*) AS n FROM `$table`")->fetch_assoc()['n'] !== 0) throw new RuntimeException('BASELINE_MUST_BE_EMPTY');
}
require_once __DIR__.'/../scripts/JourneyBatchProcessor.php';
require_once __DIR__.'/../scripts/journey_batch_runtime.php';
require_once __DIR__.'/../modelo/v2/shift/ScheduleResolutionService.php';
require_once __DIR__.'/../modelo/v2/shift/WorkplaceCoverageFeatureGate.php';
$db->query("INSERT INTO tb_estaciones (numlista,nombre,estatus) VALUES (991001,'Synthetic batch station',0)");
$db->query("INSERT INTO op_rh_localidades (numlista,localidad,recuperacion_vapores) VALUES (991001,'Synthetic batch locality','')");
$station=(int)$db->insert_id;
$db->query("INSERT INTO op_rh_v2_attendance_policy_versions (workplace_id,policy_version,effective_from_local_date,timezone_id,retardo_tolerance_minutes,incidence_deadline_days,actor_portal_user_id,source,idempotency_key,early_entry_allowed_minutes,duplicate_punch_window_minutes,exit_priority_window_minutes) VALUES ($station,1,'2026-11-01','UTC',5,1,1,'TEST','synthetic-policy',10,5,60)");
$db->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal,id_estacion,fecha_jornada_local,tipo_jornada) VALUES (990001,$station,'2026-11-27','DAY_OFF'),(990002,$station,'2026-10-01','DAY_OFF')");
$tables=['op_rh_v2_jornadas_programadas','op_rh_v2_pareos_jornada','op_rh_v2_pareos_segmentos','op_rh_v2_pareos_marcaciones_asociadas','op_rh_v2_calculos_jornada','op_rh_v2_calculos_segmentos','op_rh_v2_level2_window_evaluations'];
$counts=static function()use($db,$tables):array { $c=[];foreach($tables as $t)$c[$t]=(int)$db->query("SELECT COUNT(*) AS n FROM `$t`")->fetch_assoc()['n'];return $c;};
$cases=[['T04',990001,'2026-11-27','J1_V11_NON_SCHEDULED_NOT_VERIFIED'],['T05',990002,'2026-10-01',null]];
$fail=0;
foreach($cases as [$name,$worker,$date,$reason]) {
    $scope=['worker'=>$worker,'station'=>$station,'date'=>$date]; $before=$counts();
    try {
        $dry=JourneyBatchProcessor::process([$scope],fn($s)=>journeyBatchProcessScope($db,$s,false,null))[0];
        $run=JourneyBatchProcessor::process([$scope],fn($s)=>journeyBatchProcessScope($db,$s,true,null))[0];
        $after=$counts();
        $ok=$dry['status']==='BLOCKED' && ($reason===null || $dry['reason']===$reason) && $run['status']==='RETRY_NEXT_CYCLE' && $run['reason']==='JOURNEY_EXECUTION_BATCH_PREFLIGHT' && $before===$after;
        echo ($ok?'PASS ':'FAIL ').$name.' '.json_encode(['dry'=>$dry,'execute'=>$run,'before'=>$before,'after'=>$after],JSON_UNESCAPED_SLASHES).PHP_EOL;
        if(!$ok)$fail++;
    }catch(Throwable $e){$fail++;echo 'FAIL '.$name.' '.get_class($e).' '.$e->getMessage().PHP_EOL;}
}
echo "RESULT: ".(2-$fail)." PASS / $fail FAIL; remaining scenarios NOT EXECUTED\n";
exit($fail?1:0);
