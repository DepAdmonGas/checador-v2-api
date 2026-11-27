<?php
declare(strict_types=1);
require_once __DIR__.'/../scripts/JourneyEnvironmentProfile.php';
$cfg=json_decode(file_get_contents(__DIR__.'/../docs/v2/CHECADOR_JOURNEY_PREPROD_PRIVATE_CONFIGURATION_TEMPLATE.json'),true,32,JSON_THROW_ON_ERROR);
$pass=0; $fail=0;
$test=static function(bool $ok,string $name) use (&$pass,&$fail):void { echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL; $ok?$pass++:$fail++; };
$test(JourneyEnvironmentProfile::isPreparedPreprod($cfg,10000),'recognizes proposed profile');
foreach (['environment'=>'production','service_id'=>'journey-local','principal_id'=>2,'system_uid'=>1,'socket'=>'/tmp/mysql.sock','database'=>'bd_admongas','sql_account'=>'root@localhost','stations'=>[1]] as $k=>$v) {
    $bad=$cfg; $bad[$k]=$v; $test(!JourneyEnvironmentProfile::isPreparedPreprod($bad,10000),"rejects incorrect $k");
}
$test(!JourneyEnvironmentProfile::isPreparedPreprod($cfg,10001),'rejects wrong process UID');
JourneyEnvironmentProfile::validate($cfg,10000,1);
try { JourneyEnvironmentProfile::preprodPolicy('dry_run'); $blocked=false; } catch (Throwable $e) { $blocked=true; }
$test($blocked,'prepared profile requires root authorization before connecting');
$id=['environment'=>'preprod','server_identity'=>'synthetic-a','database'=>'bd_admongas_v2_preprod','service_id'=>'journey-preprod','sql_account'=>'checador_motor_preprod@localhost','scope'=>'1,2'];
$key=JourneyEnvironmentProfile::proposedPreprodCheckpointKey($id);
foreach (['server_identity'=>'synthetic-b','scope'=>'1,3'] as $k=>$v) { $other=$id; $other[$k]=$v; $test($key!==JourneyEnvironmentProfile::proposedPreprodCheckpointKey($other),"checkpoint isolates $k"); }
foreach (['database'=>'bd_admongas','sql_account'=>'root@localhost','environment'=>'production','service_id'=>'journey-local'] as $k=>$v) { $other=$id; $other[$k]=$v; try { JourneyEnvironmentProfile::proposedPreprodCheckpointKey($other); $blocked=false; } catch (InvalidArgumentException $e) { $blocked=true; } $test($blocked,"checkpoint rejects $k"); }
$test($cfg['execution_enabled']===false && $cfg['batch_execution_enabled']===false,'template gates disabled');
echo "RESULT: $pass PASS / $fail FAIL\n"; exit($fail?1:0);
