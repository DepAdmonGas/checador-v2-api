<?php
declare(strict_types=1);
require_once __DIR__.'/../scripts/JourneyEnvironmentProfile.php';
$passed=0; $failed=0;
$check=static function(bool $ok,string $name)use(&$passed,&$failed):void {echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL; $ok?$passed++:$failed++;};
$row=['db'=>'bd_admongas_v2_preprod','account'=>'checador_motor_preprod@localhost','hostname'=>'82e9a5d.online-server.cloud','socket'=>'/run/mysqld/mysqld.sock','datadir'=>'/var/lib/mysql/','server_id'=>1,'port'=>3306];
class TestIdentityResult { public function __construct(private array $row) {} public function fetch_assoc(): array { return $this->row; } }
class TestIdentityConnection { public string $host_info='Localhost via UNIX socket'; public int $queries=0; public bool $charset=false; public function __construct(public array $row) {} public function query(string $sql): TestIdentityResult { $this->queries++; return new TestIdentityResult($this->row); } public function set_charset(string $name): bool { $this->charset=$name==='utf8'; return $this->charset; } }
$db=new TestIdentityConnection($row);
$check(JourneyEnvironmentProfile::verifyPreprodConnection($db)===$row && $db->queries===1 && $db->charset,'controlled connection runs identity read before use');
$badDb=new TestIdentityConnection(array_replace($row,['account'=>'root@localhost']));
try { JourneyEnvironmentProfile::verifyPreprodConnection($badDb); $denied=false; } catch (RuntimeException $e) { $denied=true; }
$check($denied && !$badDb->charset,'controlled connection rejects wrong principal before use');
$check(JourneyEnvironmentProfile::preprodIdentityMatches('Localhost via UNIX socket',$row),'expected connection identity accepted');
foreach (['db'=>'bd_admongas','account'=>'root@localhost','hostname'=>'wrong','socket'=>'/tmp/mysql.sock','datadir'=>'/tmp/','server_id'=>2,'port'=>3307] as $k=>$v) { $bad=$row; $bad[$k]=$v; $check(!JourneyEnvironmentProfile::preprodIdentityMatches('Localhost via UNIX socket',$bad),"rejects incorrect $k"); }
$check(!JourneyEnvironmentProfile::preprodIdentityMatches('127.0.0.1 via TCP/IP',$row),'rejects TCP transport');
$config=json_decode(file_get_contents(__DIR__.'/../docs/v2/CHECADOR_JOURNEY_PREPROD_PRIVATE_CONFIGURATION_TEMPLATE.json'),true,32,JSON_THROW_ON_ERROR);
try { JourneyEnvironmentProfile::preprodPolicy('dry_run'); $allowed=true; } catch (Throwable $e) { $allowed=false; }
$check(!$allowed,'absent root policy denies authorization');
$profile=file_get_contents(__DIR__.'/../scripts/JourneyEnvironmentProfile.php');
$batch=file_get_contents(__DIR__.'/../scripts/journey_batch_runtime.php');
$single=file_get_contents(__DIR__.'/../scripts/journey_processor.php');
$acl=file_get_contents(__DIR__.'/../modelo/v2/shift/WorkplaceCoverageAuthorizationService.php');
$check(str_contains($profile,"real_connect('localhost', 'checador_motor_preprod'") && str_contains($profile,"'/run/mysqld/mysqld.sock'") && str_contains($profile,'$db->close()'),'socket-only connection and close on identity error wired');
$check(str_contains($batch,'JourneyEnvironmentProfile::connectPreprod') && str_contains($single,'JourneyEnvironmentProfile::connectPreprod'),'both CLI modes use connection guard');
$check(str_contains($batch,'JourneyEnvironmentProfile::proposedPreprodCheckpointKey') && str_contains($batch,"'server_identity'=>\$hostIdentity"),'batch uses preprod checkpoint identity');
$check(str_contains($acl,"'journey-preprod'") && str_contains($acl,'JourneyEnvironmentProfile::preprodIdentityMatches'),'coverage validates same connection');
$check($config['execution_enabled']===false && $config['batch_execution_enabled']===false,'template disables write gates');
echo "RESULT: $passed PASS / $failed FAIL\n"; exit($failed?1:0);
