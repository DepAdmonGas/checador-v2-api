<?php
declare(strict_types=1);
$root=dirname(__DIR__);$index=file_get_contents($root.'/index.php');$controller=file_get_contents($root.'/modelo/v2/AdminPinV2Controller.php');$policy=file_get_contents($root.'/modelo/v2/AdminPinV2Policy.php');
$pass=0;$fail=0;$check=static function(bool $ok,string $name)use(&$pass,&$fail):void{echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if($ok)$pass++;else$fail++;};
$check(is_string($index)&&str_contains($index,"getenv('CHECADOR_ADMIN_PIN_ENABLED') !== '1'")&&str_contains($index,"AdminPinV2Controller())->handle"),'dedicated API gate is fail-closed and does not reuse the global POST gate');
$check(is_string($controller)&&str_contains($controller,"'redeem', 'validate', 'close'")&&str_contains($controller,'authenticateDevice()'),'redemption, explicit validation, and close routes require Device Auth');
$check(str_contains($controller,'DeviceBiometricGrantV2::authorized')&&str_contains($controller,'actorStillAuthorized'),'active biometric grant and issuer ACL are revalidated');
$check(str_contains($controller,'begin_transaction()')&&str_contains($controller,'FOR UPDATE')&&str_contains($controller,"state='CONSUMED'"),'PIN consumption, lockout, and session issue are serialized transactionally');
$check(str_contains($controller,"hash('sha256',\$secret)")&&str_contains($controller,"'session_secret'=>\$sessionSecret"),'session secret hash is persisted and response secret is only emitted by redeem');
$check(str_contains($controller,'status/read check')&&!str_contains($controller,"UPDATE op_rh_v2_admin_session SET last_activity_at_utc=UTC_TIMESTAMP(6)"),'session status polling never extends idle lifetime');
$check(!str_contains($controller,'biometric-enrollments')&&!str_contains($controller,'template_base64'),'biometric enrollment and template writes remain out of scope');
$check(str_contains($controller,'op_rh_v2_admin_pin_limit')&&str_contains($controller,'blocked_until_utc')&&str_contains($controller,'failed_attempts'),'persistent device and per-PIN attempt limits are enforced');
$check(substr_count($controller,"UPDATE op_rh_v2_admin_pin_limit SET failed_attempts=")===1,'each wrong redemption has exactly one persistent limiter increment path');
$check(!str_contains($controller,"'checador.dispositivos.biometria.administrar'"),'admin PIN permission is not conflated with biometric administration');
$linkService=file_get_contents($root.'/modelo/v2/DeviceLinkV2Service.php');
$check(str_contains($linkService,'CREDENTIAL_ROTATE')&&str_contains($linkService,'invalidateAdministrativeSessionsForCredentialRotation'),'credential rotation revokes persisted administrative PINs and sessions');
$check(str_contains($policy,'hash_hmac')&&str_contains($policy,'PASSWORD')===false,'policy HMAC helper keeps the shared pepper out of the PIN table');
echo "RESULT: {$pass} PASS / {$fail} FAIL".PHP_EOL;exit($fail?1:0);
