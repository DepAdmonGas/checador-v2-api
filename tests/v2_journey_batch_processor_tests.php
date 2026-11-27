<?php
declare(strict_types=1);
require_once __DIR__.'/../scripts/JourneyBatchProcessor.php';
$n=0;
function check(bool $ok,string $name): void { global $n; if (!$ok) throw new RuntimeException('FAIL '.$name); echo 'PASS '.$name.PHP_EOL; $n++; }
$bounds=[['station'=>1,'from'=>'2026-10-28','to'=>'2026-11-27'],['station'=>2,'from'=>'2026-11-26','to'=>'2026-11-27']];
$source=['1:2026-11-27'=>range(1,205),'2:2026-11-27'=>[9,10],'1:2026-11-26'=>[5],'1:2026-10-28'=>[5,99]];
$fetch=static fn($station,$date,$after,$limit)=>array_slice(array_values(array_filter($source["$station:$date"]??[],static fn($w)=>$w>$after)),0,$limit);
$cursor=JourneyBatchProcessor::start($bounds);
$page=JourneyBatchProcessor::page($cursor,$fetch); check(count($page['items'])===100&&$page['next']['worker']===100,'first page bounded to 100');
$all=$page['items']; $cursor=$page['next']; $pages=1;
while ($cursor!==null) { $page=JourneyBatchProcessor::page($cursor,$fetch); $all=array_merge($all,$page['items']); $cursor=$page['next']; if (++$pages>100) throw new RuntimeException('LOOP'); }
$keys=array_map(static fn($s)=>implode(':',[$s['station'],$s['date'],$s['worker']]),$all);
check(count($all)===210&&count(array_unique($keys))===210,'pagination above 100 without omission or duplicate');
check(in_array('1:2026-10-28:99',$keys,true),'historical materialized identity revisited');
check(in_array('2:2026-11-27:9',$keys,true),'second authorized locality included');
check(!in_array('3:2026-11-27:9',$keys,true),'no unauthorized locality');
$first=JourneyBatchProcessor::page(JourneyBatchProcessor::start($bounds),$fetch);
check($first['items']===array_slice($all,0,100),'cycle repeats same stable keys for new evidence');
$empty=JourneyBatchProcessor::page(JourneyBatchProcessor::start([]),$fetch); check($empty===['items'=>[],'next'=>null],'empty authorized history completes without invention');
$gap=JourneyBatchProcessor::page(JourneyBatchProcessor::start($bounds),static fn()=>[],100,1); check($gap['items']===[]&&$gap['next']!==null,'bounded empty partitions advance cursor');
try { JourneyBatchProcessor::page(JourneyBatchProcessor::start($bounds),static fn()=>[2,1]); check(false,'unordered source'); } catch (RuntimeException $e) { check($e->getMessage()==='BATCH_INVALID_DISCOVERY_ORDER','unordered source rejected'); }
try { JourneyBatchProcessor::page(JourneyBatchProcessor::start($bounds),static function(){throw new RuntimeException('QUERY_ERROR');}); check(false,'query failure'); } catch (RuntimeException $e) { check($e->getMessage()==='QUERY_ERROR','query failure does not return empty success'); }
$preview=static fn($reason,$eligible=true,$hash='a')=>['plan'=>['reason'=>$reason,'eligible_for_writer'=>$eligible,'fingerprint'=>str_repeat($hash,64),'blockers'=>$eligible?[]:['J1_WINDOW_NOT_OPEN']]];
foreach (['P1_MISSING','P1_INPUTS_CHANGED','P2_MISSING_OR_STALE'] as $reason) check(JourneyBatchProcessor::decision($preview($reason))['status']==='READY','plan routes '.$reason);
check(JourneyBatchProcessor::decision($preview('UP_TO_DATE'))['status']==='UP_TO_DATE','unchanged plan skips writer');
check(JourneyBatchProcessor::decision($preview('BLOCKED',false))['status']==='BLOCKED','future or conflict plan does not write');
check(JourneyBatchProcessor::decision(['reason'=>'INPUT_CHANGES_NOT_VERIFIED'])['status']==='BLOCKED','legacy remains fail-closed');
$scopes=array_slice($all,0,3); $processed=JourneyBatchProcessor::process($scopes,static function($s){if($s['worker']===2)throw new RuntimeException('FAILURE');return ['status'=>'UP_TO_DATE'];});
check(array_column($processed,'status')===['UP_TO_DATE','RETRY_NEXT_CYCLE','UP_TO_DATE'],'failed item does not stop following items');
// Local checkpoint only. No SQL, no additional database.
$dir=sys_get_temp_dir().'/journey-batch-test-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
$key=str_repeat('a',64); $state=['cursor'=>$first['next'],'cycle'=>1,'blocked'=>0,'ready'=>0];
try {
    $c=new JourneyBatchCheckpoint($dir,$key); check($c->load()===null,'new checkpoint empty'); $c->save($state);
    try { new JourneyBatchCheckpoint($dir,$key); check(false,'concurrent'); } catch(RuntimeException $e) { check($e->getMessage()==='BATCH_ALREADY_RUNNING','exclusive checkpoint lock'); }
    $c->close(); $c=new JourneyBatchCheckpoint($dir,$key); check($c->load()===$state,'durable cursor survives reopening');
    $p=JourneyBatchProcessor::page($c->load()['cursor'],$fetch); check($p['items'][0]['worker']===101,'resume at next unprocessed worker');
    // Simulate interruption before next checkpoint save.
    $c->close(); $c=new JourneyBatchCheckpoint($dir,$key); $replay=JourneyBatchProcessor::page($c->load()['cursor'],$fetch);
    check($replay===$p,'interruption replays uncommitted page'); $c->close();
    $other=new JourneyBatchCheckpoint($dir,str_repeat('b',64)); check($other->load()===null,'different mode/scope has independent checkpoint'); $other->close();
} finally { foreach(glob($dir.'/*') as $file) unlink($file); rmdir($dir); }
echo "$n PASS (bounded batch, controlled discovery and local checkpoint; no MariaDB writes)\n";
