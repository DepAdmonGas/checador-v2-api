<?php
declare(strict_types=1);
require_once __DIR__.'/../scripts/JourneyEnvironmentProfile.php';
$pass=0; $fail=0;
$test=static function(bool $ok,string $label) use (&$pass,&$fail):void { echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL; $ok?$pass++:$fail++; };
class UtcResult { public function __construct(private string $value) {} public function fetch_assoc(): array { return ['session_timezone'=>$this->value]; } }
class UtcConnection {
    public array $queries=[]; public bool $closed=false;
    public function __construct(public bool $setOk=true,public string $zone='+00:00',public bool $readOk=true) {}
    public function query(string $sql): mixed { $this->queries[]=$sql; return str_starts_with($sql,'SET ')?$this->setOk:($this->readOk?new UtcResult($this->zone):false); }
    public function close(): void { $this->closed=true; }
}
$db=new UtcConnection(); JourneyEnvironmentProfile::requireUtcSession($db);
$test($db->queries === ["SET SESSION time_zone = '+00:00'",'SELECT @@session.time_zone AS session_timezone'] && !$db->closed,'sets and verifies effective timezone on same connection');
foreach ([new UtcConnection(false),new UtcConnection(true,'SYSTEM'),new UtcConnection(true,'+00:00',false)] as $bad) {
    try { JourneyEnvironmentProfile::requireUtcSession($bad); $blocked=false; } catch (RuntimeException $e) { $blocked=$e->getMessage()==='UTC_SESSION_UNAVAILABLE'; }
    $test($blocked && $bad->closed,'session failure denies and closes connection');
}
$expect=['op_rh_v2_pareos_jornada'=>2,'op_rh_v2_pareos_segmentos'=>1,'op_rh_v2_pareos_marcaciones_asociadas'=>3,'op_rh_v2_calculos_jornada'=>1,'op_rh_v2_calculos_segmentos'=>1];
$source=file_get_contents(__DIR__.'/../modelo/v2/journey/JourneyPairingService.php').file_get_contents(__DIR__.'/../modelo/v2/journey/JourneyCalculationService.php');
foreach ($expect as $table=>$count) {
    preg_match_all('/INSERT\s+INTO\s+'.preg_quote($table,'/').'\s*\(([^)]*)\)\s*VALUES\s*\((.*?)\)\s*"/is',$source,$matches,PREG_SET_ORDER);
    $ok=count($matches)===$count;
    foreach ($matches as $m) $ok=$ok && preg_match('/\bcreado_at_utc\b/i',$m[1])===1 && preg_match('/UTC_TIMESTAMP\s*\(\s*\)/i',$m[2].')')===1;
    $test($ok,"all $table inserts explicitly use UTC_TIMESTAMP ($count)");
}
$test(str_contains($source, "\$aStmt->bind_param('ii', \$headerId, \$punchId);"), 'non-scheduled unmatched insert binds its two placeholders');
foreach (['journey_processor.php','journey_batch_runtime.php'] as $file) {
    $src=file_get_contents(__DIR__.'/../scripts/'.$file);
    $test(str_contains($src,'JourneyEnvironmentProfile::requireUtcSession($db)'),"$file calls session guard");
}
echo "RESULT: $pass PASS / $fail FAIL\n"; exit($fail?1:0);
