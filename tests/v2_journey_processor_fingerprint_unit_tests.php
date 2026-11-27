<?php
declare(strict_types=1);

// Exercises the existing P1 fingerprint without a database or operational writes.
require_once __DIR__ . '/../modelo/v2/journey/JourneyPairingService.php';
$class = new ReflectionClass(JourneyPairingService::class);
$service = $class->newInstanceWithoutConstructor();
$class->getProperty('algorithmVersion')->setValue($service, 'v2.4-p1');
$fingerprint = $class->getMethod('calculateFingerprint');
$hash = static fn(array $events, string $level2 = 'VERIFIED'): string =>
    $fingerprint->invoke($service, 7, 1, $events, $level2);
$events = [['id' => 9, 'ocurrido_at_utc' => '2026-11-26 16:49:39']];
$late = [['id' => 8, 'ocurrido_at_utc' => '2026-11-26 16:30:00'], ...$events];
$checks = [
    'same inputs preserve fingerprint' => $hash($events) === $hash($events),
    'late event changes fingerprint' => $hash($events) !== $hash($late),
    'Level 2 status changes fingerprint' => $hash($events) !== $hash($events, 'PENDING_SYNC_COMPLETION'),
    'station changes fingerprint' => $hash($events) !== $fingerprint->invoke($service, 7, 2, $events, 'VERIFIED'),
];
foreach ($checks as $name => $pass) {
    echo ($pass ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
