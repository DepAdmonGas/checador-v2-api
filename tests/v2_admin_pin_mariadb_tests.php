<?php

declare(strict_types=1);

/** Focused runtime regression for the real API redemption transaction/limiter. */
const ADMIN_PIN_TEST_DB = 'bd_admongas_v2_admin_pin_test_20261125';
const ADMIN_PIN_TEST_SECRET = 'local-disposable-admin-pin-test-key-2026';

function adminPinTestConnect(): mysqli
{
    $host = (string)(getenv('DB_HOST') ?: '');
    $name = (string)(getenv('DB_NAME') ?: '');
    if (!in_array($host, ['127.0.0.1', 'localhost'], true) || $name !== ADMIN_PIN_TEST_DB) {
        throw new RuntimeException('REFUSED_DATABASE_IDENTITY');
    }
    $db = new mysqli($host, (string)getenv('DB_USER'), (string)getenv('DB_PASS'), $name, (int)(getenv('DB_PORT') ?: 3306));
    $db->set_charset('utf8mb4');
    $identity = $db->query('SELECT DATABASE() AS db, CURRENT_USER() AS principal, @@hostname AS host, @@port AS port, VERSION() AS version')->fetch_assoc();
    if (($identity['db'] ?? '') !== ADMIN_PIN_TEST_DB || stripos((string)($identity['version'] ?? ''), 'MariaDB') === false) {
        throw new RuntimeException('REFUSED_DATABASE_IDENTITY');
    }
    return $db;
}

function adminPinCallRedeem(mysqli $db, int $deviceId, string $pin): array
{
    $controller = new AdminPinV2Controller();
    $method = new ReflectionMethod(AdminPinV2Controller::class, 'redeem');
    $method->setAccessible(true);
    return $method->invoke($controller, $db,
        ['device_id'=>$deviceId, 'station_id'=>21, 'credential_id'=>$deviceId],
        ADMIN_PIN_TEST_SECRET,
        ['pin'=>$pin, 'request_id'=>AdminPinV2Policy::uuidV4()]
    );
}

if (($argv[1] ?? '') === '--child') {
    require_once __DIR__ . '/../modelo/v2/AdminPinV2Controller.php';
    try {
        $db = adminPinTestConnect();
        $result = adminPinCallRedeem($db, (int)($argv[2] ?? 0), '999999');
        echo (int)($result['status_code'] ?? 0);
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e));
        exit(1);
    }
}

require_once __DIR__ . '/../modelo/v2/AdminPinV2Controller.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try { $db = adminPinTestConnect(); }
catch (Throwable) { fwrite(STDERR, 'Refusing: localhost disposable MariaDB ' . ADMIN_PIN_TEST_DB . " and working DB_USER/DB_PASS are required.\n"); exit(2); }
$identity = $db->query('SELECT DATABASE() AS db, CURRENT_USER() AS principal, @@hostname AS host, @@port AS port, VERSION() AS version')->fetch_assoc();
echo 'DATABASE_IDENTITY ' . json_encode($identity, JSON_UNESCAPED_SLASHES) . PHP_EOL;

$tables = ['op_rh_v2_admin_pin','op_rh_v2_admin_session','op_rh_v2_admin_pin_limit','op_rh_v2_device_biometric_grants','op_rh_v2_dispositivo_credenciales','op_rh_v2_dispositivos','op_rh_v2_m11_configuration_audit'];
foreach ($tables as $table) {
    $stmt = $db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->bind_param('s', $table); $stmt->execute(); $exists = (int)$stmt->get_result()->fetch_assoc()['n']; $stmt->close();
    if ($exists !== 1) { fwrite(STDERR, "Refusing: required test table missing: {$table}.\n"); exit(2); }
}

$created = [];
$pass = 0;
$fail = 0;
$assert = static function(bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
try {
    // Use only pre-existing disposable tables. The service commits its own transactions;
    // fixture writes therefore cannot be protected by an outer rollback.
    if (getenv('ADMIN_PIN_TEST_WRITES') !== 'I_AUTHORIZE_DISPOSABLE_WRITES') {
        throw new RuntimeException('DISPOSABLE_WRITE_OPT_IN_REQUIRED');
    }
    foreach ([1,2,3,4] as $fixtureId) {
        $check = $db->query("SELECT id FROM op_rh_v2_dispositivos WHERE id=" . $fixtureId);
        if ($check->num_rows !== 0) throw new RuntimeException('FIXTURE_ID_ALREADY_PRESENT');
    }
    foreach ([1,2,3,4] as $deviceId) {
        $db->query("INSERT INTO op_rh_v2_dispositivos VALUES ({$deviceId},21,1)");
        $hash = password_hash('synthetic-device-secret', PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO op_rh_v2_dispositivo_credenciales VALUES (?,?,'ACTIVE',NULL,UTC_TIMESTAMP(6),NULL)");
        $stmt->bind_param('ii', $deviceId, $deviceId); $stmt->execute(); $stmt->close();
        $stmt = $db->prepare("INSERT INTO op_rh_v2_device_biometric_grants (id_dispositivo,id_estacion,id_credencial,activo,valido_desde_utc) VALUES (?,21,?,1,UTC_TIMESTAMP(6))");
        $stmt->bind_param('ii', $deviceId, $deviceId); $stmt->execute(); $stmt->close();
    }
    $issuePending = static function(int $deviceId) use ($db): void {
        $pin = '123456'; $verifier = AdminPinV2Policy::verifier($pin, ADMIN_PIN_TEST_SECRET); $hash = password_hash($verifier, PASSWORD_DEFAULT); $uuid = AdminPinV2Policy::uuidV4();
        $stmt = $db->prepare("INSERT INTO op_rh_v2_admin_pin (authorization_uuid,device_id,credential_id,station_id,locality_id,actor_user_id,pin_hash,state,failed_attempts,issued_at_utc,expires_at_utc) VALUES (?,?,?,21,1001,77,?,'PENDING',0,UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 5 MINUTE))");
        $stmt->bind_param('siis', $uuid, $deviceId, $deviceId, $hash); $stmt->execute(); $stmt->close();
    };
    $issuePending(1);
    $a = adminPinCallRedeem($db, 1, '999999');
    $limiter = $db->query('SELECT failed_attempts FROM op_rh_v2_admin_pin_limit WHERE device_id=1')->fetch_assoc();
    $perPin = $db->query('SELECT failed_attempts FROM op_rh_v2_admin_pin WHERE device_id=1')->fetch_assoc();
    $assert(($a['status_code'] ?? 0) === 401 && (int)$limiter['failed_attempts'] === 1 && (int)$perPin['failed_attempts'] === 1, 'one incorrect redemption increments device and authorization counters exactly once');

    $b = adminPinCallRedeem($db, 1, '999999');
    $limit2 = $db->query('SELECT failed_attempts,blocked_until_utc,window_started_at_utc FROM op_rh_v2_admin_pin_limit WHERE device_id=1')->fetch_assoc();
    $c = adminPinCallRedeem($db, 1, '999999');
    $limit3 = $db->query('SELECT failed_attempts,blocked_until_utc,window_started_at_utc FROM op_rh_v2_admin_pin_limit WHERE device_id=1')->fetch_assoc();
    $delta = strtotime((string)$limit3['blocked_until_utc']) - time();
    $assert(($b['status_code'] ?? 0) === 401 && (int)$limit2['failed_attempts'] === 2, 'second incorrect redemption adds exactly one persistent device attempt');
    $assert(($c['status_code'] ?? 0) === 429 && (int)$limit3['failed_attempts'] === 3 && $delta >= 899 && $delta <= 900, 'third failure blocks exactly for fifteen minutes');

    $stmt = $db->prepare("UPDATE op_rh_v2_admin_pin SET state='REVOKED',revoked_at_utc=UTC_TIMESTAMP(6) WHERE device_id=1 AND state='PENDING'"); $stmt->execute(); $stmt->close();
    $issuePending(1);
    $reissue = adminPinCallRedeem($db, 1, '999999');
    $afterReissue = $db->query('SELECT failed_attempts,blocked_until_utc FROM op_rh_v2_admin_pin_limit WHERE device_id=1')->fetch_assoc();
    $assert(($reissue['status_code'] ?? 0) === 429 && (int)$afterReissue['failed_attempts'] === 3 && $afterReissue['blocked_until_utc'] !== null, 'reissued authorization does not clear accumulated device lockout');

    $start = static function(int $deviceId): array {
        $proc = proc_open([PHP_BINARY, __FILE__, '--child', (string)$deviceId], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('CHILD_START_FAILED');
        fclose($pipes[0]); return [$proc,$pipes];
    };
    $issuePending(2); $db->begin_transaction();
    $db->query("INSERT INTO op_rh_v2_admin_pin_limit VALUES (2,UTC_TIMESTAMP(6),0,NULL)"); $db->commit();
    $one=$start(2); $two=$start(2); $codes=[];
    foreach ([$one,$two] as [$proc,$pipes]) { $codes[]=trim(stream_get_contents($pipes[1])); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]); if(proc_close($proc)!==0)$codes[]='CHILD_ERROR'; }
    sort($codes);
    $concurrent=(int)$db->query('SELECT failed_attempts FROM op_rh_v2_admin_pin_limit WHERE device_id=2')->fetch_assoc()['failed_attempts'];
    $assert($codes===['401','401'] && $concurrent===2, 'concurrent incorrect redemptions serialize and each count once');

    $issuePending(3);
    $expired = $db->query("UPDATE op_rh_v2_admin_pin SET issued_at_utc=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE),expires_at_utc=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 5 MINUTE) WHERE device_id=3");
    $expiredResult=adminPinCallRedeem($db,3,'123456');
    $expiredCount=(int)$db->query('SELECT failed_attempts FROM op_rh_v2_admin_pin_limit WHERE device_id=3')->fetch_assoc()['failed_attempts'];
    $assert(($expiredResult['status_code'] ?? 0)===401 && $expiredCount===1, 'expired PIN denial records one device attempt');

    $issuePending(4);
    $db->query('INSERT INTO op_rh_v2_admin_pin_limit VALUES (4,DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 16 MINUTE),2,NULL)');
    $resetResult=adminPinCallRedeem($db,4,'999999');
    $reset=$db->query('SELECT failed_attempts,blocked_until_utc FROM op_rh_v2_admin_pin_limit WHERE device_id=4')->fetch_assoc();
    $assert(($resetResult['status_code'] ?? 0)===401 && (int)$reset['failed_attempts']===1 && $reset['blocked_until_utc']===null, 'expired limiter window resets prior count before the next single attempt');
    echo "RESULT: {$pass} PASS / {$fail} FAIL" . PHP_EOL;
} catch (Throwable $e) {
    $fail++; fwrite(STDERR, 'TEST_ERROR ' . get_class($e) . PHP_EOL);
} finally {
    // Fixtures remain in the explicitly authorized disposable database. No DDL or cleanup is performed.
}
exit($fail ? 1 : 0);
