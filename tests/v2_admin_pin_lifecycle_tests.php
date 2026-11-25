<?php
declare(strict_types=1);
require_once __DIR__.'/../modelo/v2/AdminPinV2Controller.php';
class LifecycleDb extends mysqli {
 public array $state=[]; public array $backup=[]; public function __construct(){} public function begin_transaction(int $flags=0,?string $name=null):bool{$this->backup=$this->state;return true;} public function commit(int $flags=0,?string $name=null):bool{return true;} public function rollback(int $flags=0,?string $name=null):bool{$this->state=$this->backup;return true;} public function query(string $query,int $result_mode=MYSQLI_STORE_RESULT):mysqli_result|bool{return true;}
}
class LifecycleController extends AdminPinV2Controller {
 public int $time=1700000000;public bool $grant=true;public bool $actor=true;public bool $fail=false;
 public function run(string $name,array $args):array{return $this->$name(...$args);} protected function now(mysqli $db):string{return gmdate('Y-m-d H:i:s',$this->time).'.000000';} protected function insertId(mysqli $db):int{return 17;} protected function grantAuthorized(mysqli $db,int $deviceId,int $stationId,int $credentialId):bool{return $this->grant;} protected function actorStillAuthorized(mysqli $db,int $actorId,int $localityId,int $stationId):bool{return $this->actor;}
 protected function one(mysqli $db,string $sql,string $types,array $params):?array {
  if(str_contains($sql,'FROM op_rh_v2_dispositivos'))return ['id'=>1,'id_estacion'=>1,'estado'=>1];
  if(str_contains($sql,'FROM op_rh_v2_dispositivo_credenciales'))return ['id'=>1];
  if(str_contains($sql,'FROM op_rh_v2_admin_pin_limit'))return ['device_id'=>1,'window_started_at_utc'=>gmdate('Y-m-d H:i:s',$this->time),'failed_attempts'=>0,'blocked_until_utc'=>null];
  if(str_contains($sql,'FROM op_rh_v2_admin_pin'))return ($db->state['pin']['state']??'')==='PENDING'?$db->state['pin']:null;
  if(str_contains($sql,'FROM op_rh_v2_admin_session')) { $s=$db->state['session']??null;if(str_contains($sql,"state='ACTIVE'") && ($s['state']??'')!=='ACTIVE')return null;return $s; }return null;
 }
 protected function exec(mysqli $db,string $sql,string $types,array $params):void {
  if($this->fail && str_contains($sql,'INSERT INTO op_rh_v2_admin_session'))throw new RuntimeException('injected');
  if(str_contains($sql,"SET state='CONSUMED'"))$db->state['pin']['state']='CONSUMED';
  if(str_contains($sql,'INSERT INTO op_rh_v2_admin_session'))$db->state['session']=['id'=>17,'session_uuid'=>$params[0],'pin_id'=>$params[1],'device_id'=>$params[2],'credential_id'=>$params[3],'station_id'=>$params[4],'locality_id'=>$params[5],'actor_user_id'=>$params[6],'session_hash'=>$params[7],'state'=>'ACTIVE','created_at_utc'=>$this->now($db),'expires_at_utc'=>$params[8],'last_activity_at_utc'=>$this->now($db)];
  if(str_contains($sql,"SET state='CLOSED'"))$db->state['session']['state']='CLOSED';
  if(str_contains($sql,"SET state='REVOKED'"))$db->state['pin']['state']='REVOKED';
 }
}
$c=new LifecycleController();$db=new LifecycleDb();$auth=['device_id'=>1,'station_id'=>1,'credential_id'=>1];$secret='test-secret-with-at-least-32-bytes';$uuid=AdminPinV2Policy::uuidV4();$issue=function()use($db,$c,$secret){$db->state=['pin'=>['id'=>2,'state'=>'PENDING','failed_attempts'=>0,'actor_user_id'=>4,'locality_id'=>5,'pin_hash'=>password_hash(AdminPinV2Policy::verifier('123456',$secret),PASSWORD_DEFAULT),'issued_at_utc'=>gmdate('Y-m-d H:i:s',$c->time),'expires_at_utc'=>gmdate('Y-m-d H:i:s',$c->time+300)]];};$pass=0;$fail=0;$check=function($ok,$name)use(&$pass,&$fail){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;$ok?$pass++:$fail++;};
$issue();$r=$c->run('redeem',[$db,$auth,$secret,['pin'=>'123456','request_id'=>$uuid]]);$check(isset($r['session_secret'])&&$db->state['pin']['state']==='CONSUMED'&&$db->state['session']['state']==='ACTIVE','valid redemption consumes PIN and creates session');
$r2=$c->run('redeem',[$db,$auth,$secret,['pin'=>'123456','request_id'=>$uuid]]);$check(($r2['status_code']??0)===401,'consumed PIN cannot be redeemed again');
$_SERVER['HTTP_X_ADMINISTRATIVE_SESSION']=$r['session_secret'];$v=$c->run('validateSession',[$db,$auth]);$last=$db->state['session']['last_activity_at_utc'];$check(($v['state']??'')==='ACTIVE','session validates while active');$c->time+=119;$c->run('validateSession',[$db,$auth]);$check($db->state['session']['last_activity_at_utc']===$last,'polling does not extend inactivity');$c->time++;$check(($c->run('validateSession',[$db,$auth])['status_code']??0)===401,'session expires after two minutes idle');
$c->time-=120;$db->state['session']['created_at_utc']=gmdate('Y-m-d H:i:s',$c->time-300);$db->state['session']['last_activity_at_utc']=gmdate('Y-m-d H:i:s',$c->time);$db->state['session']['expires_at_utc']=gmdate('Y-m-d H:i:s',$c->time);$check(($c->run('validateSession',[$db,$auth])['status_code']??0)===401,'session expires at five minutes');
$db->state['session']['created_at_utc']=gmdate('Y-m-d H:i:s',$c->time);$db->state['session']['expires_at_utc']=gmdate('Y-m-d H:i:s',$c->time+300);$c->grant=false;try{$c->run('validateSession',[$db,$auth]);$denied=false;}catch(RuntimeException $e){$denied=true;}$check($denied,'revoked biometric grant blocks validation');$c->grant=true;$c->actor=false;$check(($c->run('validateSession',[$db,$auth])['status_code']??0)===401,'revoked actor authorization blocks validation');$c->actor=true;
$x=$c->run('closeSession',[$db,$auth,['request_id'=>$uuid]]);$y=$c->run('closeSession',[$db,$auth,['request_id'=>$uuid]]);$check(($x['state']??'')==='CLOSED'&&($y['state']??'')==='CLOSED','session close is idempotent');
$issue();$c->fail=true;try{$c->run('redeem',[$db,$auth,$secret,['pin'=>'123456','request_id'=>$uuid]]);$ok=false;}catch(RuntimeException $e){$ok=$db->state['pin']['state']==='PENDING'&&!isset($db->state['session']);}$check($ok,'persistence failure rolls back PIN consumption');
echo "RESULT: $pass PASS / $fail FAIL\n";exit($fail?1:0);
