<?php
declare(strict_types=1);
require_once __DIR__ . '/../modelo/v2/journey/JourneyPairingService.php';
$rows = [
    ['id'=>9, 'ocurrido_at_utc'=>'2026-11-26 16:49:39'],
    ['id'=>10, 'ocurrido_at_utc'=>'2026-11-26 16:51:59'],
    ['id'=>11, 'ocurrido_at_utc'=>'2026-11-26 16:54:39'],
    ['id'=>12, 'ocurrido_at_utc'=>'2026-11-26 16:54:40'],
];
$grouped = JourneyPairingService::groupPossibleRepeats($rows);
$checks = [
    'five-minute boundary included' => count($grouped[0] ?? []) === 3,
    'window measured from first event' => count($grouped) === 2 && ($grouped[1][0]['id'] ?? null) === 12,
    'all original events retained' => array_merge(...$grouped) === $rows,
    'empty input' => JourneyPairingService::groupPossibleRepeats([]) === [],
];
try { JourneyPairingService::groupPossibleRepeats(array_reverse($rows)); $checks['out-of-order input rejected'] = false; }
catch (InvalidArgumentException $e) { $checks['out-of-order input rejected'] = true; }
foreach ($checks as $name => $ok) echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
