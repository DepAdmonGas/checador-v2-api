<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/test_mariadb_isolation_guard.php';
$socket = TestMariaDbIsolationGuard::requireExplicitAuthorization();
$db = new mysqli('localhost','root','',TestMariaDbIsolationGuard::SCHEMA,0,$socket);
$identity = TestMariaDbIsolationGuard::assertConnection($db,$socket);
$base = $db->query('SELECT DATABASE() db,@@datadir datadir,@@socket socket,@@port port,@@pid_file pid_file,@@server_id server_id,@@hostname hostname')->fetch_assoc();
$passed = 0;
$expect = static function (string $name, callable $operation, string $expected) use (&$passed): void {
    try { $operation(); } catch (RuntimeException $e) {
        if ($e->getMessage() !== $expected) throw $e;
        ++$passed;
        echo "PASS {$name}\n";
        return;
    }
    throw new RuntimeException('EXPECTED_GUARD_REJECTION_MISSING:'.$name);
};

$bad = $base; $bad['datadir']='/var/lib/mysql/';
$expect('foreign datadir rejected',static fn()=>TestMariaDbIsolationGuard::assertServerIdentity($bad,$socket),'TEST_GUARD_INSTANCE_IDENTITY_INVALID');
$bad = $base; $bad['hostname']='';
$expect('incompatible server identity rejected',static fn()=>TestMariaDbIsolationGuard::assertServerIdentity($bad,$socket),'TEST_GUARD_INSTANCE_IDENTITY_INVALID');
$bad = $base; $bad['db']='checador_r3b_unapproved_fixture';
$expect('unapproved schema identity rejected',static fn()=>TestMariaDbIsolationGuard::assertServerIdentity($bad,$socket),'TEST_GUARD_INSTANCE_IDENTITY_INVALID');
$bad = $base; $bad['port']='3306';
$expect('TCP listener identity rejected',static fn()=>TestMariaDbIsolationGuard::assertServerIdentity($bad,$socket),'TEST_GUARD_INSTANCE_IDENTITY_INVALID');

// A connection to another synthetic schema is an inert decoy: the dangerous KILL path must reject it.
$other = new mysqli('localhost','root','','bd_admongas_v2_test_20261002',0,$socket);
$foreignId = (int)$other->query('SELECT CONNECTION_ID() id')->fetch_assoc()['id'];
$expect('foreign-fixture connection rejected before kill',static fn()=>TestMariaDbIsolationGuard::killVerifiedConnection($db,$other,$socket),'TEST_GUARD_INSTANCE_IDENTITY_INVALID');
$checkAlive = $other->query('SELECT CONNECTION_ID() id')->fetch_assoc();
if ((int)$checkAlive['id'] !== $foreignId || !$other->ping()) throw new RuntimeException('FOREIGN_CONNECTION_WAS_AFFECTED');
++ $passed; echo "PASS foreign-fixture connection remains alive\n";

// This PHP child is created here as a harmless process decoy. The guard must reject it and never signal it.
$child = proc_open([PHP_BINARY,'-r','usleep(8000000);'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
if (!is_resource($child)) throw new RuntimeException('SENTINEL_PROCESS_START_FAILED');
try {
    $status = proc_get_status($child); $pid = (int)$status['pid'];
    if ($pid < 2 || !$status['running']) throw new RuntimeException('SENTINEL_PROCESS_NOT_RUNNING');
    $expect('foreign PHP pid rejected before signal',static fn()=>TestMariaDbIsolationGuard::assertOwnedPhpProcess($pid,__FILE__,$socket),'TEST_GUARD_PROCESS_IDENTITY_INVALID');
    if (!proc_get_status($child)['running']) throw new RuntimeException('FOREIGN_PROCESS_WAS_AFFECTED');
    ++$passed; echo "PASS foreign PHP sentinel remains alive\n";
} finally {
    @proc_terminate($child,15);
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    @proc_close($child);
}

echo "R3B_NEGATIVE_INTEGRATION_ASSERTIONS={$passed} PASS\n";
