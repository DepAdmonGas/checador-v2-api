<?php
declare(strict_types=1);
require_once __DIR__.'/test_mariadb_isolation_guard.php';

$passed=0;
$expect=static function(string $name,callable $f,string $expected)use(&$passed):void{
    try{$f();throw new RuntimeException("ASSERTION_FAILED:$name");}
    catch(RuntimeException $e){if($e->getMessage()!==$expected)throw $e;echo "PASS $name\n";++$passed;}
};
$oldAuth=getenv('CHECADOR_DESTRUCTIVE_TESTS');$oldSocket=getenv('JOURNEY_BATCH_TEST_SOCKET');
try{
    putenv('CHECADOR_DESTRUCTIVE_TESTS');putenv('JOURNEY_BATCH_TEST_SOCKET');
    $expect('destructive authorization absent',static fn()=>TestMariaDbIsolationGuard::requireExplicitAuthorization(),'TEST_GUARD_EXPLICIT_AUTH_REQUIRED');
    putenv('CHECADOR_DESTRUCTIVE_TESTS=1');
    $expect('fixture socket absent',static fn()=>TestMariaDbIsolationGuard::requireExplicitAuthorization(),'TEST_GUARD_SOCKET_REQUIRED');
    putenv('JOURNEY_BATCH_TEST_SOCKET=/tmp/mysql.sock');
    $expect('socket outside fixture rejected',static fn()=>TestMariaDbIsolationGuard::requireExplicitAuthorization(),'TEST_GUARD_SOCKET_REQUIRED');
    putenv('JOURNEY_BATCH_TEST_SOCKET=/tmp/checador-batch-validation-invalid/mysql.sock');
    $expect('foreign instance root rejected',static fn()=>TestMariaDbIsolationGuard::requireExplicitAuthorization(),'TEST_GUARD_ROOT_INVALID');
} finally {
    $oldAuth===false?putenv('CHECADOR_DESTRUCTIVE_TESTS'):putenv('CHECADOR_DESTRUCTIVE_TESTS='.$oldAuth);
    $oldSocket===false?putenv('JOURNEY_BATCH_TEST_SOCKET'):putenv('JOURNEY_BATCH_TEST_SOCKET='.$oldSocket);
}
echo "ISOLATION_GUARD_NEGATIVE_TESTS=$passed/4\n";
