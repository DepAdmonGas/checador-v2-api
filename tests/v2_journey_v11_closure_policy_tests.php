<?php
declare(strict_types=1);
require_once __DIR__ . '/../modelo/v2/journey/JourneyPairingService.php';
$old = ['id'=>5,'policy_version'=>5,'early_entry_allowed_minutes'=>null,'duplicate_punch_window_minutes'=>null,'exit_priority_window_minutes'=>null];
$new = ['id'=>6,'policy_version'=>6,'early_entry_allowed_minutes'=>'10','duplicate_punch_window_minutes'=>'5','exit_priority_window_minutes'=>'60'];
$checks = ['historical policy stays V1.0'=>JourneyPairingService::selectV11Policy($old) === null, 'complete policy selects V1.1'=>JourneyPairingService::selectV11Policy($new)['version'] === 6];
try { JourneyPairingService::selectV11Policy(array_replace($new,['duplicate_punch_window_minutes'=>null])); $checks['partial policy rejected']=false; } catch (InvalidArgumentException $e) { $checks['partial policy rejected']=true; }
$utc = new DateTimeZone('UTC'); $start = new DateTimeImmutable('2026-11-26 12:00:00', $utc); $end = new DateTimeImmutable('2026-11-27 00:00:00', $utc);
$ev = ['id_estacion'=>1,'window_level2_status'=>'VERIFIED','window_from_utc'=>'2026-11-26 12:00:00','window_to_utc'=>'2026-11-27 00:00:00'];
$checks['full verified window closes'] = JourneyPairingService::v11Closure($end,$start,$end,1,$ev,false);
$checks['before end remains open'] = !JourneyPairingService::v11Closure($end->modify('-1 second'),$start,$end,1,$ev,false);
$checks['partial evidence rejected'] = !JourneyPairingService::v11Closure($end,$start,$end,1,array_replace($ev,['window_to_utc'=>'2026-11-26 23:59:59']),false);
$checks['unknown rejected'] = !JourneyPairingService::v11Closure($end,$start,$end,1,array_replace($ev,['window_level2_status'=>'UNKNOWN']),false);
$checks['other station rejected'] = !JourneyPairingService::v11Closure($end,$start,$end,2,$ev,false);
$checks['conflict prevents closure'] = !JourneyPairingService::v11Closure($end,$start,$end,1,$ev,true);
foreach ($checks as $name=>$pass) echo ($pass?'PASS ':'FAIL ').$name.PHP_EOL;
exit(in_array(false,$checks,true)?1:0);
