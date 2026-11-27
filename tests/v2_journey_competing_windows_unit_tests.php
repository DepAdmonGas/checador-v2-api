<?php
declare(strict_types=1);
require_once __DIR__ . '/../modelo/v2/journey/JourneyV11OperationalContext.php';
$p = [['id'=>9, 'ocurrido_at_utc'=>'2026-11-26 10:00:00'], ['id'=>10, 'ocurrido_at_utc'=>'2026-11-26 12:00:00']];
$w = [['journey'=>'a', 'from_utc'=>'2026-11-26 09:00:00', 'to_utc'=>'2026-11-26 11:00:00'], ['journey'=>'b', 'from_utc'=>'2026-11-26 10:00:00', 'to_utc'=>'2026-11-26 12:30:00']];
$a = JourneyV11OperationalContext::conflictingPunchIds($p, $w);
$checks = ['overlap detected'=>$a === [9], 'order independent'=>JourneyV11OperationalContext::conflictingPunchIds(array_reverse($p), array_reverse($w)) === $a, 'single journey not conflict'=>JourneyV11OperationalContext::conflictingPunchIds($p, [$w[0]]) === []];
foreach ($checks as $label=>$ok) echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
