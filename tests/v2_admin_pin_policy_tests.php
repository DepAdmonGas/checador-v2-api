<?php

declare(strict_types=1);
require_once __DIR__ . '/../modelo/v2/AdminPinV2Policy.php';
$pass=0;$fail=0;
$check=static function(bool $ok,string $name) use (&$pass,&$fail):void { echo ($ok?'PASS ':'FAIL ').$name."\n"; if($ok)$pass++;else $fail++; };
$check(AdminPinV2Policy::validPin('000001') && !AdminPinV2Policy::validPin(1) && !AdminPinV2Policy::validPin('abc123'),'PIN must be six ASCII digits as a string');
$check(AdminPinV2Policy::validPin(AdminPinV2Policy::generatePin()),'generated PIN has six digits');
$pepper=str_repeat('K',48); $verifier=AdminPinV2Policy::verifier('000001',$pepper); $slowHash=password_hash($verifier,PASSWORD_DEFAULT);
$check(password_verify(AdminPinV2Policy::verifier('000001',$pepper),$slowHash) && !password_verify(AdminPinV2Policy::verifier('000002',$pepper),$slowHash),'PIN is HMAC-keyed before the slow password hash and is not stored recoverably');
$check(AdminPinV2Policy::verifier('000001',$pepper)!==AdminPinV2Policy::verifier('000001',str_repeat('Z',48)),'shared-secret rotation invalidates pending PIN verifiers');
$check(AdminPinV2Policy::validUuid(AdminPinV2Policy::uuidV4()) && !AdminPinV2Policy::validUuid('not-a-uuid'),'session and authorization IDs are UUIDv4');
$check(AdminPinV2Policy::pinValidAt(1000,1300,1000) && !AdminPinV2Policy::pinValidAt(1000,1300,1300),'PIN expires at exclusive five-minute boundary');
$check(!AdminPinV2Policy::pinValidAt(1000,1301,1100),'incorrect PIN lifetime denied');
$check(AdminPinV2Policy::sessionValidAt(1000,1100,1300,1219) && !AdminPinV2Policy::sessionValidAt(1000,1100,1300,1220),'session idle boundary is exclusive');
$check(!AdminPinV2Policy::sessionValidAt(1000,1290,1300,1300),'session absolute five-minute expiration');
$x=AdminPinV2Policy::recordFailure(1000,0,null,1000); $x=AdminPinV2Policy::recordFailure($x['window_start'],$x['failures'],$x['blocked_until'],1001); $x=AdminPinV2Policy::recordFailure($x['window_start'],$x['failures'],$x['blocked_until'],1002);
$check($x['failures']===3 && $x['blocked_until']===1902,'three accumulated failures block for fifteen minutes');
$y=AdminPinV2Policy::recordFailure($x['window_start'],$x['failures'],$x['blocked_until'],1003); $check($y===$x,'failure while blocked does not reset the limit');
$z=AdminPinV2Policy::recordFailure($x['window_start'],$x['failures'],$x['blocked_until'],1902); $check($z['failures']===1 && $z['blocked_until']===null,'new window begins after block and window expire');
echo "RESULT: $pass PASS / $fail FAIL\n"; exit($fail?1:0);
