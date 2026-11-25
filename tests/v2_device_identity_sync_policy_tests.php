<?php

declare(strict_types=1);
require_once __DIR__ . '/../modelo/v2/InitialDeviceIdentityProvisioner.php';
$passed=0; $failed=0;
$test=static function(bool $ok,string $name) use (&$passed,&$failed): void { echo ($ok?'PASS ':'FAIL ').$name."\n"; if($ok)$passed++;else $failed++; };
$row = static fn(int $device,string $ref,int $active=1,int $current=1): array => ['id_dispositivo'=>$device,'referencia_externa_personal'=>$ref,'es_activo'=>$active,'vigente'=>$current];
$test(InitialDeviceIdentityProvisioner::classify([],2,0)==='create','new worker eligible without biometric dependency');
$test(InitialDeviceIdentityProvisioner::classify([$row(2,'existing')],2,0)==='preserve','current identity preserved');
$test(InitialDeviceIdentityProvisioner::classify([$row(2,'old',1,0)],2,0)==='conflict','expired history rejected');
$test(InitialDeviceIdentityProvisioner::classify([$row(3,'other')],2,0)==='conflict','history on another device not rewritten');
$test(InitialDeviceIdentityProvisioner::classify([$row(2,'one'),$row(2,'two')],2,0)==='conflict','multiple current identities rejected');
$test(InitialDeviceIdentityProvisioner::classify([$row(2,'existing')],2,1)==='conflict','overlapping external reference rejected');
$test(InitialDeviceIdentityProvisioner::classify([$row(7,'existing')],7,0)==='preserve','device scope is parameterized');
$test(InitialDeviceIdentityProvisioner::classify([$row(2,' bad ')],2,0)==='conflict','malformed reference rejected');
$source=file_get_contents(__DIR__.'/../modelo/v2/DeviceIdentitySyncV2Controller.php');
$test(str_contains($source,'autenticarCredencial') && str_contains($source,'id_credencial=?') && str_contains($source,'FOR UPDATE'), 'device credential and grant are locked and revalidated');
$test(str_contains($source,'request_fingerprint') && str_contains($source,'response_json') && str_contains($source,'IDEMPOTENCY_CONFLICT'), 'durable response replay and conflict boundary present');
$test(str_contains($source,'$db->rollback()') && str_contains($source,'$db->commit()'), 'transaction boundaries present');
$router=file_get_contents(__DIR__.'/../index.php');
$test(str_contains($router,"getenv('CHECADOR_IDENTITY_SYNC_ENABLED') === '1'"), 'sync route defaults off');
echo "RESULT: {$passed} PASS / {$failed} FAIL\n";
exit($failed ? 1 : 0);
