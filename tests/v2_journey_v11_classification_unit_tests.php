<?php
declare(strict_types=1);
require_once __DIR__ . '/../modelo/v2/journey/JourneyPairingService.php';
$policy = ['early_entry_minutes'=>10, 'repeat_minutes'=>5, 'exit_priority_minutes'=>60];
$checks = [];
$run = static function (array $segments, array $times, bool $closed = true) use ($policy): array {
    $events = []; foreach ($times as $i => $time) $events[] = ['id'=>$i + 1, 'ocurrido_at_utc'=>'2026-11-26 '.$time];
    return JourneyPairingService::classifyV11($segments, $events, $policy, $closed);
};
$segment = static fn(string $start, string $end): array => ['scheduled_start_utc'=>'2026-11-26 '.$start, 'scheduled_end_utc'=>'2026-11-26 '.$end];
$a = [$segment('07:00:00','15:00:00')];
$r = $run($a, ['06:49:00']); $checks['early event preserved'] = count($r['unassigned']) === 1 && $r['segments'][0]['entry_punch_id'] === null;
$r = $run($a, ['06:50:00','15:00:00']); $checks['ten-minute boundary'] = $r['segments'][0]['entry_punch_id'] === 1 && $r['segments'][0]['exit_punch_id'] === 2;
$r = $run($a, ['07:00:00','15:00:00','15:02:00']); $checks['last exit repeat is selected'] = $r['segments'][0]['entry_punch_id'] === 1 && $r['segments'][0]['exit_punch_id'] === 3 && count($r['segments'][0]['associated_punches']) === 3;
$r = $run($a, ['15:00:01','15:02:00']); $checks['last exit repeat without entry'] = $r['segments'][0]['entry_punch_id'] === null && $r['segments'][0]['exit_punch_id'] === 2 && count($r['segments'][0]['associated_punches']) === 2;
$b = [$segment('06:00:00','14:00:00')];
$r = $run($b, ['10:49:39','10:51:59']); $checks['late entry and repeat'] = $r['segments'][0]['entry_punch_id'] === 1 && $r['segments'][0]['exit_punch_id'] === null;
$r = $run($b, ['10:49:39','10:51:59','14:05:00']); $checks['late entry and exit'] = $r['segments'][0]['exit_punch_id'] === 3;
$r = $run($b, ['06:00:00','11:30:00']); $checks['early exit after closure'] = $r['segments'][0]['exit_punch_id'] === 2;
$r = $run($b, ['06:00:00','11:30:00'], false); $checks['early exit remains provisional'] = $r['segments'][0]['exit_punch_id'] === null;
$r = $run($b, ['14:05:00']); $checks['exit without entry'] = $r['segments'][0]['entry_punch_id'] === null && $r['segments'][0]['exit_punch_id'] === 1;
$r = $run($b, ['06:00:00','10:30:00','12:00:00','14:05:00']); $checks['intermediate evidence retained'] = count($r['segments'][0]['associated_punches']) === 4 && $r['segments'][0]['exit_punch_id'] === 4;
$r = $run([$segment('08:00:00','12:00:00'),$segment('14:00:00','18:00:00')], ['07:55:00','12:02:00','13:58:00','18:05:00']); $checks['split shift'] = $r['segments'][0]['exit_punch_id'] === 2 && $r['segments'][1]['entry_punch_id'] === 3 && $r['segments'][1]['exit_punch_id'] === 4;
$validPolicy = ['early_entry_minutes'=>10,'repeat_minutes'=>5,'exit_priority_minutes'=>60];
foreach (['early_entry_minutes'=>[0,10,-1,11], 'repeat_minutes'=>[1,10,0,11], 'exit_priority_minutes'=>[30,120,29,121]] as $key => $bounds) {
    foreach ($bounds as $index => $value) {
        $candidate = $validPolicy; $candidate[$key] = $value;
        try { JourneyPairingService::classifyV11([], [], $candidate, false); $accepted = true; }
        catch (InvalidArgumentException) { $accepted = false; }
        $checks["policy {$key} {$value}"] = $accepted === ($index < 2);
    }
}
foreach ([null, 1.5, '5'] as $invalid) {
    $candidate = $validPolicy; $candidate['repeat_minutes'] = $invalid;
    try { JourneyPairingService::classifyV11([], [], $candidate, false); $accepted = true; }
    catch (InvalidArgumentException) { $accepted = false; }
    $checks['reject invalid repeat ' . var_export($invalid, true)] = !$accepted;
}
foreach ($checks as $name => $ok) echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
