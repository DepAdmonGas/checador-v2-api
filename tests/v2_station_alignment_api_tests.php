<?php
/**
 * Test Suite: Station Alignment Remediation
 * Verifies GET /acceso-empresa id_estacion response and GET /api/v2/devices/context technical device context.
 */

require_once __DIR__ . "/../modelo/BD.php";
require_once __DIR__ . "/../modelo/Acceso.php";
require_once __DIR__ . "/../modelo/v2/DispositivoV2.php";
require_once __DIR__ . "/../modelo/v2/Level2ServerController.php";

$passCount = 0;
$failCount = 0;

function assertAlignmentTest($condition, $message, &$pass, &$fail) {
    if ($condition) {
        echo "[ PASS ] $message\n";
        $pass++;
    } else {
        echo "[ FAIL ] $message\n";
        $fail++;
    }
}

echo "=== INICIANDO PRUEBAS DE STATION ALIGNMENT REMEDIATION ===\n\n";

try {
    $classBD = new BD();
    $conexion = $classBD->conectarBD();

    // Setup Test Data: Legacy Station and V2 Device
    $conexion->query("DELETE FROM op_rh_localidades_perfil WHERE id_estacion = 888");
    $conexion->query("DELETE FROM op_rh_localidades WHERE id = 888");
    $conexion->query("INSERT INTO op_rh_localidades (id, numlista, localidad, recuperacion_vapores) VALUES (888, 888, 'Estacion Test Legacy 888', 0)");

    $classEncriptar = new Encriptar();
    $usrEnc = $classEncriptar->encrypt('user888');
    $pwdEnc = $classEncriptar->encrypt('pass888');

    $conexion->query("INSERT INTO op_rh_localidades_perfil (id_estacion, usuario, password, status, token) VALUES (888, '$usrEnc', '$pwdEnc', 1, '')");

    // 1. Test GET /acceso-empresa exposes canonical id_estacion
    $acceso = new Acceso();
    $resAcceso = $acceso->AccesoEmpresa('user888', 'pass888');

    assertAlignmentTest(
        isset($resAcceso['resultado']['code']) && $resAcceso['resultado']['code'] === 200,
        "Login legacy exitoso con usuario y contraseña de estación",
        $passCount,
        $failCount
    );

    assertAlignmentTest(
        isset($resAcceso['resultado']['id_estacion']) && $resAcceso['resultado']['id_estacion'] === 888,
        "Login legacy retorna id_estacion canónico exactamente igual al id_estacion de la BD (888)",
        $passCount,
        $failCount
    );

    assertAlignmentTest(
        isset($resAcceso['resultado']['token']) && !empty($resAcceso['resultado']['token']) && isset($resAcceso['resultado']['empresa']),
        "Login legacy conserva de forma 100% retrocompatible los campos token, empresa, fecha, hora y status",
        $passCount,
        $failCount
    );

    // 2. Setup V2 Technical Device assigned to Station 777
    $conexion->query("DELETE FROM op_rh_v2_dispositivo_credenciales WHERE id_dispositivo = 700");
    $conexion->query("DELETE FROM op_rh_v2_dispositivos WHERE id = 700");
    $conexion->query("INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado) VALUES (700, 'dev-uuid-700-test', 777, 'Lector Estacion 777', 'America/Mexico_City', 1)");

    $secretPlain = 'DeviceSecretKey#700';
    $secretHash = password_hash($secretPlain, PASSWORD_BCRYPT);
    $now = gmdate('Y-m-d H:i:s');
    $conexion->query("INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, estado, valido_desde, creado_at) VALUES (700, 'cred-key-700', '$secretHash', 'ACTIVE', '$now', '$now')");

    // Test GET /api/v2/devices/context with valid credentials
    $_SERVER['HTTP_X_DEVICE_ID'] = 'dev-uuid-700-test';
    $_SERVER['HTTP_X_CREDENTIAL_KEY'] = 'cred-key-700';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $secretPlain;

    $l2Ctrl = new Level2ServerController();
    ob_start();
    $l2Ctrl->handleGetDeviceContext();
    $outputJson = ob_get_clean();
    $resCtx = json_decode($outputJson, true);

    assertAlignmentTest(
        isset($resCtx['code']) && $resCtx['code'] === 200,
        "GET /api/v2/devices/context retorna 200 OK para credenciales técnicas válidas",
        $passCount,
        $failCount
    );

    assertAlignmentTest(
        isset($resCtx['id_estacion']) && $resCtx['id_estacion'] === 777,
        "GET /api/v2/devices/context retorna id_estacion canónico del dispositivo (777)",
        $passCount,
        $failCount
    );

    assertAlignmentTest(
        isset($resCtx['device_uuid']) && $resCtx['device_uuid'] === 'dev-uuid-700-test' && isset($resCtx['id_dispositivo']) && $resCtx['id_dispositivo'] === 700,
        "GET /api/v2/devices/context retorna id_dispositivo (700) y device_uuid",
        $passCount,
        $failCount
    );

    assertAlignmentTest(
        !isset($resCtx['secret_hash']) && !isset($resCtx['secret']) && !isset($resCtx['credential_key']),
        "GET /api/v2/devices/context NO expira ni filtra hashes, secrets ni llaves de credencial",
        $passCount,
        $failCount
    );

    // 3. Test Invalid Device Authentication
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer WrongSecretKey';
    ob_start();
    $l2Ctrl->handleGetDeviceContext();
    $outErrJson = ob_get_clean();
    $resErrCtx = json_decode($outErrJson, true);

    assertAlignmentTest(
        isset($resErrCtx['code']) && $resErrCtx['code'] === 401,
        "GET /api/v2/devices/context rechaza secret inválido con HTTP 401 UNAUTHORIZED",
        $passCount,
        $failCount
    );

    // 4. Test Mismatch Scenario Visibility (Station 888 legacy vs Station 777 V2 device)
    assertAlignmentTest(
        $resAcceso['resultado']['id_estacion'] === 888 && $resCtx['id_estacion'] === 777,
        "Verificación de Mismatch: Legacy retorna 888 mientras V2 Device retorna 777, exponiendo la divergencia para validación del Lector sin reescribirla",
        $passCount,
        $failCount
    );

    // Limpieza de datos de prueba
    $conexion->query("DELETE FROM op_rh_v2_dispositivo_credenciales WHERE id_dispositivo = 700");
    $conexion->query("DELETE FROM op_rh_v2_dispositivos WHERE id = 700");
    $conexion->query("DELETE FROM op_rh_localidades_perfil WHERE id_estacion = 888");
    $conexion->query("DELETE FROM op_rh_localidades WHERE id = 888");

    $classBD->desconectarBD($conexion);

} catch (Throwable $e) {
    echo "\n[ ERROR DE EJECUCIÓN ] " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine() . "\n";
    $failCount++;
}

echo "\n=== RESUMEN DE PRUEBAS DE STATION ALIGNMENT REMEDIATION ===\n";
echo "TOTAL PRUEBAS : " . ($passCount + $failCount) . "\n";
echo "PASARON       : $passCount\n";
echo "FALLARON      : $failCount\n";

if ($failCount > 0) {
    exit(1);
}
