<?php
declare(strict_types=1);
require_once __DIR__.'/../modelo/v2/InitialDeviceIdentityProvisioner.php';
require_once __DIR__.'/../modelo/v2/BiometricRosterV2Projection.php';
$n=0;$fail=0;$test=function(bool $ok,string $name)use(&$n,&$fail){$n++;if(!$ok)$fail++;echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;};
$row=['id_personal'=>1,'external_worker_reference'=>'uuid-1','no_colaborador'=>1,'id_estacion_adscripcion'=>1,'identity_mapping_count'=>1,'nombre'=>'Worker','puesto'=>'','biometric_template'=>null];
$project=fn($rows)=>BiometricRosterV2Projection::project($rows,1,'Station','2026-01-01T00:00:00-06:00');
$empty=$project([$row]);$test($empty['total_workers']===1 && !$empty['workers'][0]['has_biometric_template'],'worker without fingerprint appears');
$row['biometric_template']=base64_encode('synthetic');$filled=$project([$row]);$test($filled['workers'][0]['has_biometric_template'],'worker with valid encoded template appears');
$history=[['id_dispositivo'=>1,'es_activo'=>1,'vigente'=>1,'referencia_externa_personal'=>'uuid-1']];$test(InitialDeviceIdentityProvisioner::classify($history,1,0)==='preserve','same identity preserved upon repeated synchronization');
$test(InitialDeviceIdentityProvisioner::classify($history,2,0)==='conflict','transfer to another device preserves history and reports conflict');
$test(InitialDeviceIdentityProvisioner::classify([],1,0)==='create','new worker receives first identity decision');
$test(InitialDeviceIdentityProvisioner::classify([['id_dispositivo'=>1,'es_activo'=>0,'vigente'=>0,'referencia_externa_personal'=>'old']],1,0)==='conflict','reactivation with only historical identity fails closed');
$test($project([])['total_workers']===0,'empty eligible rows give an empty roster');
echo "RESULT: $n checks / $fail failures\n";exit($fail?1:0);
