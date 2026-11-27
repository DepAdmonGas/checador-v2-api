<?php
declare(strict_types=1);
require_once __DIR__ . '/../modelo/v2/journey/JourneyExecutionCoordinator.php';

$held = [];
$events = [];
$coordinator = new JourneyExecutionCoordinator(
    static function (string $name) use (&$held): bool {
        if (isset($held[$name])) return false;
        $held[$name] = true;
        return true;
    },
    static function (string $name) use (&$held): void { unset($held[$name]); }
);
$result = $coordinator->execute(565, 1, '2026-11-26',
    static function () use (&$events) { $events[] = 'shift'; return 7; },
    static function ($shift) use (&$events) { $events[] = 'p1'; return $shift + 1; },
    static function ($p1) use (&$events) { $events[] = 'p2'; return $p1 + 1; });
$checks = ['ordered execution' => $events === ['shift', 'p1', 'p2'] && $result['calculation'] === 9,
    'lock released after success' => $held === []];
$busy = false;
$coordinator->execute(565, 1, '2026-11-26', static function () use ($coordinator, &$busy) {
    try { $coordinator->execute(565, 1, '2026-11-26', fn() => 0, fn($x) => $x, fn($x) => $x); }
    catch (RuntimeException $e) { $busy = $e->getMessage() === 'JOURNEY_LOCK_UNAVAILABLE'; }
    return 1;
}, fn($x) => $x, fn($x) => $x);
$checks['same journey excluded'] = $busy;
try { $coordinator->execute(565, 1, '2026-11-26', fn() => 1, fn($x) => $x, static function () { throw new RuntimeException('failure'); }); }
catch (RuntimeException $e) {}
$checks['lock released after P2 error'] = $held === [];
$retry = $coordinator->execute(565, 1, '2026-11-26', fn() => 1, fn($x) => $x, fn($x) => $x);
$checks['retry allowed after failure'] = $retry['calculation'] === 1;
$checks['same worker across dates and stations uses same lock'] = JourneyExecutionCoordinator::lockName(565, 1, '2026-11-25') === JourneyExecutionCoordinator::lockName(565, 2, '2026-11-26');
$checks['different workers use different locks'] = JourneyExecutionCoordinator::lockName(565, 1, '2026-11-25') !== JourneyExecutionCoordinator::lockName(566, 1, '2026-11-25');
try { JourneyExecutionCoordinator::lockName(565, 1, '2026-02-30'); $checks['invalid date rejected'] = false; }
catch (InvalidArgumentException $e) { $checks['invalid date rejected'] = true; }
foreach ($checks as $name => $pass) echo ($pass ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
