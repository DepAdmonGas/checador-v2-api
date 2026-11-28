<?php
// Guard de seguridad obligatorio para runners de prueba autonomos

function verifyTestDatabaseEnvironment(): void
{
    require_once __DIR__.'/test_mariadb_isolation_guard.php';
    $socket=TestMariaDbIsolationGuard::requireExplicitAuthorization();
    $expectedDb='bd_admongas_v2_test_20261002';

    // BD.php is used by legacy J1/J2 runtime tests. Pin its host/database/socket
    // before it can connect; a caller-supplied remote host is never honored.
    if (ini_set('mysqli.default_socket',$socket)===false) throw new RuntimeException('TEST_GUARD_SOCKET_CONFIGURATION_FAILED');
    putenv('DB_HOST=localhost');
    putenv('DB_PORT=3306'); // localhost uses the explicitly pinned Unix socket.
    putenv('DB_NAME='.$expectedDb);

    require_once __DIR__.'/../modelo/BD.php';
    $bd=new BD();
    $con=$bd->conectarBD();
    try { TestMariaDbIsolationGuard::assertConnection($con,$socket,$expectedDb); }
    finally { $bd->desconectarBD($con); }
}

verifyTestDatabaseEnvironment();
