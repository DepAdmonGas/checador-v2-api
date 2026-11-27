<?php
declare(strict_types=1);

// Advisory locks only: no DDL, DML or reads of application records.
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/journey/JourneyExecutionCoordinator.php';
require_once __DIR__ . '/../modelo/v2/journey/JourneyMariaDbLock.php';
if (!in_array(getenv('DB_HOST'), ['localhost', '127.0.0.1', '::1'], true)
    || getenv('DB_NAME') !== 'bd_admongas') {
    fwrite(STDERR, "Refused: local bd_admongas required.\n"); exit(2);
}
$db1 = (new BD())->conectarBD();
$db2 = (new BD())->conectarBD();
$id1 = $db1->query('SELECT DATABASE() AS db, CURRENT_USER() AS usr')->fetch_assoc();
$id2 = $db2->query('SELECT DATABASE() AS db, CURRENT_USER() AS usr')->fetch_assoc();
if ($id1['db'] !== 'bd_admongas' || $id2['db'] !== $id1['db'] || $id1['usr'] !== $id2['usr']) {
    fwrite(STDERR, "Refused: connection identity mismatch.\n"); exit(2);
}
echo 'Database: ' . $id1['db'] . '; SQL user: ' . $id1['usr'] . PHP_EOL;
$first = new JourneyMariaDbLock($db1);
$second = new JourneyMariaDbLock($db2);
$worker = random_int(1500000000, 2000000000);
$station = random_int(1500000000, 2000000000);
$date = '2099-01-01';
$name = 'test-journey-' . bin2hex(random_bytes(16));
$checks = [];
try {
    $checks['first connection acquires'] = $first->acquire($name);
    $ownership = static function (mysqli $db, string $name): bool {
        $stmt = $db->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS owned');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['owned'] ?? 0) === 1;
    };
    $checks['owner verified'] = $ownership($db1, $name);
    $checks['non-owner rejected'] = !$ownership($db2, $name);
    $checks['second connection excluded'] = !$second->acquire($name);
    $first->release($name);
    $checks['second connection acquires after release'] = $second->acquire($name);
    $second->release($name);
    $coordinator = new JourneyExecutionCoordinator(fn(string $_): bool => $first->acquire($name), fn(string $_) => $first->release($name));
    try {
        $coordinator->execute($worker, $station, $date, fn() => throw new RuntimeException('synthetic failure'), fn($v) => $v, fn($v) => $v);
        $checks['exception propagated'] = false;
    } catch (RuntimeException $e) { $checks['exception propagated'] = $e->getMessage() === 'synthetic failure'; }
    $checks['released after exception'] = $second->acquire($name);
    if ($checks['released after exception']) $second->release($name);
} finally {
    $db1->close();
    $db2->close();
}
foreach ($checks as $label => $ok) echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
exit(count($checks) === 7 && !in_array(false, $checks, true) ? 0 : 1);
