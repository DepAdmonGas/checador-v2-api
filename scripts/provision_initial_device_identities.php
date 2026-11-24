<?php

declare(strict_types=1);
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/InitialDeviceIdentityProvisioner.php';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['--dry-run', '--apply'], true) || $argc !== 2) {
    fwrite(STDERR, "Usage: php scripts/provision_initial_device_identities.php --dry-run|--apply\n"); exit(2);
}
$name = (string)getenv('DB_NAME');
if (!in_array($name, ['bd_admongas_v2_preprod', 'bd_admongas_v2_biometric_roster_test_20261124'], true)) {
    fwrite(STDERR, "Database name not allowed.\n"); exit(2);
}
if ($mode === '--apply' && ($name !== 'bd_admongas_v2_biometric_roster_test_20261124' || !in_array((string)getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true))) {
    fwrite(STDERR, "Apply is disabled outside the local disposable database until validation and authorization.\n"); exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = (new BD())->conectarBD();
    $actual = $db->query('SELECT DATABASE() AS name, CURRENT_USER() AS principal')->fetch_assoc();
    if (($actual['name'] ?? '') !== $name) throw new RuntimeException('DATABASE_IDENTITY_MISMATCH');
    $result = (new InitialDeviceIdentityProvisioner($db))->run($mode === '--apply');
    echo json_encode(['database' => $actual['name'], 'principal' => $actual['principal'], 'mode' => $mode, 'result' => $result], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Provisioning failed: ' . get_class($e) . "\n"); exit(1);
}
