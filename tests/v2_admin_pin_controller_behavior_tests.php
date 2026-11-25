<?php
declare(strict_types=1);
require_once __DIR__.'/../modelo/v2/AdminPinV2Controller.php';
$c=new AdminPinV2Controller();$db=(new ReflectionClass(mysqli::class))->newInstanceWithoutConstructor();$auth=['device_id'=>1,'station_id'=>1,'credential_id'=>1];$checks=0;$fail=0;
$assert=static function(bool $ok,string $name)use(&$checks,&$fail){$checks++;if(!$ok)$fail++;echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;};
$call=static function(string $name,array $args)use($c){return (new ReflectionMethod($c,$name))->invokeArgs($c,$args);};
$uuid=AdminPinV2Policy::uuidV4();$secret='test-key-with-at-least-thirty-two-bytes';
$r=$call('redeem',[$db,$auth,$secret,['pin'=>'12345','request_id'=>$uuid]]);$assert(($r['status_code']??0)===422,'redeem rejects invalid PIN before database');
$r=$call('redeem',[$db,$auth,$secret,['pin'=>'123456','request_id'=>'bad']]);$assert(($r['status_code']??0)===422,'redeem rejects invalid request UUID before database');
unset($_SERVER['HTTP_X_ADMINISTRATIVE_SESSION']);$r=$call('validateSession',[$db,$auth]);$assert(($r['status_code']??0)===401,'session validation denies missing secret before database');
$r=$call('closeSession',[$db,$auth,['request_id'=>'bad']]);$assert(($r['status_code']??0)===422,'close rejects invalid correlation UUID before database');
$r=$call('closeSession',[$db,$auth,['request_id'=>$uuid]]);$assert(($r['status_code']??0)===401,'close denies missing session secret before database');
echo "RESULT: $checks checks / $fail failures\n";exit($fail?1:0);
