<?php
declare(strict_types=1);
require_once __DIR__.'/../scripts/JourneyBatchProcessor.php';
require_once __DIR__.'/../modelo/v2/journey/JourneyExecutionCoordinator.php';
$pass=0;$fail=0;
$test=static function(bool $ok,string $name)use(&$pass,&$fail):void { echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL; $ok?$pass++:$fail++; };
$held=false; $writes=[];
$coordinator=new JourneyExecutionCoordinator(static function($name)use(&$held){if($held)return false;$held=true;return true;},static function($name)use(&$held){$held=false;});
foreach (['J1_V11_NON_SCHEDULED_NOT_VERIFIED','LEGACY_INPUT_CHANGES_NOT_VERIFIED','SCHEDULE_NOT_EVALUABLE'] as $reason) {
    $writes=[];
    try { $coordinator->execute(1,1,'2026-11-27',static function()use(&$held,&$writes,$reason){if(!$held)throw new RuntimeException('NO_LOCK');JourneyBatchProcessor::requireExecutablePreview(['reason'=>$reason]);$writes[]='level2';return 1;},fn($x)=>$x,fn($x)=>$x); } catch (RuntimeException $e) {}
    $test($writes===[]&&!$held,"$reason blocked before writes under lock");
}
foreach (['P1_MISSING','P1_INPUTS_CHANGED','P2_MISSING_OR_STALE','UP_TO_DATE'] as $reason) {
    $decision=JourneyBatchProcessor::requireExecutablePreview(['plan'=>['eligible_for_writer'=>true,'reason'=>$reason,'fingerprint'=>'synthetic']]);
    $test($decision['status']===($reason==='UP_TO_DATE'?'UP_TO_DATE':'READY'),"$reason eligible");
}
try { JourneyBatchProcessor::requireExecutablePreview(['plan'=>['eligible_for_writer'=>false,'reason'=>'CONFLICT']]);$blocked=false;}catch(RuntimeException $e){$blocked=true;}
$test($blocked,'conflicting scheduled journey blocked');
$runtime=file_get_contents(__DIR__.'/../scripts/journey_batch_runtime.php');$shared=file_get_contents(__DIR__.'/../modelo/v2/journey/JourneySharedExecutionService.php');
$test(str_contains($runtime,'evaluate($w,$s,$d,true,') && str_contains($runtime,'requireExecutablePreview(journeyProcessorDryRun'),'batch calls locked preview and requires V1.1');
$test(strpos($shared,'$beforeWrites($worker, $station, $date)')<strpos($shared,'$beforeEvaluation($worker, $station, $date)'),'shared executor preflight precedes Level2');
echo "RESULT: $pass PASS / $fail FAIL\n";exit($fail?1:0);
