<?php

declare(strict_types=1);
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/InitialDeviceIdentityProvisioner.php';

const PREPROD_DB = 'bd_admongas_v2_preprod';
const TEST_DB = 'bd_admongas_v2_biometric_roster_test_20261124';

$usage = "Usage:\n"
    . "  php scripts/provision_initial_device_identities.php --dry-run --database=" . PREPROD_DB . " --device-id=2 --station-id=1 --worker-id=ID\n"
    . "  CHECADOR_ENABLE_IDENTITY_PROVISIONING_PREPROD=1 php scripts/provision_initial_device_identities.php --apply --database=" . PREPROD_DB . " --device-id=2 --station-id=1 --worker-id=ID --confirm-preprod=" . PREPROD_DB . ":2:1:ID\n";

$args = array_slice($argv, 1);
if ($args === ['--apply']) {
    // Retain the previous disposable-only integration-test entry point.
    $dbName = (string)getenv('DB_NAME'); $dbHost = (string)getenv('DB_HOST');
    if ($dbName !== TEST_DB || !in_array($dbHost, ['127.0.0.1', 'localhost'], true)) {
        fwrite(STDERR, "Unscoped apply is permitted only against the guarded disposable localhost database.\n"); exit(2);
    }
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = (new BD())->conectarBD();
        $identity = $db->query('SELECT DATABASE() AS database_name,CURRENT_USER() AS principal')->fetch_assoc();
        if (($identity['database_name'] ?? '') !== TEST_DB) throw new RuntimeException('DATABASE_IDENTITY_MISMATCH');
        $provisioner = new InitialDeviceIdentityProvisioner($db);
        $runMethod = new ReflectionMethod($provisioner, 'run');
        $result = $runMethod->getNumberOfRequiredParameters() >= 3
            ? $provisioner->run(true, 2, 1)
            : $provisioner->run(true);
        echo json_encode(['database'=>$identity['database_name'],'principal'=>$identity['principal'],'mode'=>'disposable-test-apply','result'=>$result], JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    } catch (Throwable $e) { fwrite(STDERR, 'Provisioning failed: ' . get_class($e) . "\n"); exit(1); }
}

$options = [];
foreach ($args as $arg) {
    if ($arg === '--dry-run' || $arg === '--apply') {
        if (isset($options['mode'])) { fwrite(STDERR, $usage); exit(2); }
        $options['mode'] = substr($arg, 2);
        continue;
    }
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) { fwrite(STDERR, $usage); exit(2); }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    if ($value === '' || isset($options[$key])) { fwrite(STDERR, $usage); exit(2); }
    $options[$key] = $value;
}
$required = ['mode','database','device-id','station-id','worker-id'];
foreach ($required as $key) if (!isset($options[$key])) { fwrite(STDERR, $usage); exit(2); }
if (array_diff(array_keys($options), [...$required, 'confirm-preprod'])) { fwrite(STDERR, $usage); exit(2); }
foreach (['device-id','station-id','worker-id'] as $key) {
    if (!ctype_digit((string)$options[$key]) || (int)$options[$key] < 1) { fwrite(STDERR, $usage); exit(2); }
}
if ($options['database'] !== PREPROD_DB || (string)getenv('DB_NAME') !== PREPROD_DB) {
    fwrite(STDERR, "Preproduction mode requires the exact configured database bd_admongas_v2_preprod.\n"); exit(2);
}
$deviceId=(int)$options['device-id']; $stationId=(int)$options['station-id']; $workerId=(int)$options['worker-id'];
if ($deviceId !== 2 || $stationId !== 1) { fwrite(STDERR, "This approved initial operation is limited to device 2 and physical station 1.\n"); exit(2); }
if ($options['mode'] === 'apply') {
    $expected = PREPROD_DB . ':' . $deviceId . ':' . $stationId . ':' . $workerId;
    if ((string)getenv('CHECADOR_ENABLE_IDENTITY_PROVISIONING_PREPROD') !== '1' || ($options['confirm-preprod'] ?? '') !== $expected) {
        fwrite(STDERR, "Preproduction apply requires CHECADOR_ENABLE_IDENTITY_PROVISIONING_PREPROD=1 and exact --confirm-preprod={$expected}.\n"); exit(2);
    }
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = (new BD())->conectarBD();
    $identity = $db->query('SELECT DATABASE() AS database_name,CURRENT_USER() AS principal')->fetch_assoc();
    if (($identity['database_name'] ?? '') !== PREPROD_DB) throw new RuntimeException('DATABASE_IDENTITY_MISMATCH');
    $result = (new InitialDeviceIdentityProvisioner($db))->runSelectedPreproductionWorker($options['mode'] === 'apply', $deviceId, $stationId, $workerId, (string)$identity['database_name'], (string)($options['confirm-preprod'] ?? ''));
    echo json_encode(['database'=>$identity['database_name'],'principal'=>$identity['principal'],'mode'=>$options['mode'],'result'=>$result], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    // Do not emit query text, identity references, biometric data, secrets or database errors.
    $detail = $e instanceof RuntimeException && preg_match('/\\A[A-Z0-9_]+\\z/D', $e->getMessage()) === 1
        ? $e->getMessage() : 'DEPENDENCY_OR_SQL_FAILURE';
    fwrite(STDERR, 'Provisioning failed: ' . $detail . "\n"); exit(1);
}
