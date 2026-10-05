<?php
// Guard de seguridad obligatorio para runners de prueba autonomos

function verifyTestDatabaseEnvironment() {
    $expectedDb = 'bd_admongas_v2_test_20261002';

    putenv("DB_NAME={$expectedDb}");

    require_once __DIR__ . '/../modelo/BD.php';
    $bd = new BD();
    $con = $bd->conectarBD();

    $res = mysqli_query($con, "SELECT DATABASE() AS current_db");
    $row = mysqli_fetch_assoc($res);
    $currentDb = $row['current_db'] ?? '';

    $bd->desconectarBD($con);

    if ($currentDb !== $expectedDb || $currentDb === 'bd_admongas') {
        echo "[ FATAL ERROR ] SAFETY GUARD TRIGGERED: Database connection is directed to '{$currentDb}'. Must be strictly '{$expectedDb}'. Aborting execution to protect demo/prod data.\n";
        exit(1);
    }
}

verifyTestDatabaseEnvironment();
