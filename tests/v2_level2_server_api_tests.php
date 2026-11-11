<?php
// Test Suite Completo para API Level 2 Server Prerequisites & Capabilities
require_once __DIR__ . "/test_bootstrap_guard.php";
@ob_start();
require_once __DIR__ . "/../modelo/BD.php";
require_once __DIR__ . "/../modelo/v2/DispositivoV2.php";
require_once __DIR__ . "/../modelo/v2/IngestaPunchV2Controller.php";
require_once __DIR__ . "/../modelo/v2/Level2ServerService.php";
require_once __DIR__ . "/../modelo/v2/Level2ServerController.php";

echo "=== INICIANDO PRUEBAS DE API LEVEL 2 SERVER CAPABILITIES ===\n\n";

$testJwtSecret = 'desarrollo_portal3_jwt_secret_key_2026_admongas';
putenv("JWT_SECRET=$testJwtSecret");

$classBD = new BD();
$conexion = $classBD->conectarBD();

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $description, int &$passCount, int &$failCount): void {
    if ($condition) {
        echo "[ PASS ] " . $description . "\n";
        $passCount++;
    } else {
        echo "[ FAIL ] " . $description . "\n";
        $failCount++;
    }
}

try {
    // 1. Ejecutar Migración UP Level 2
    $sqlUpL2 = file_get_contents(__DIR__ . "/../migraciones/v2/2026_10_05_000002_create_v2_level2_server_tables.sql");
    $conexion->multi_query($sqlUpL2);
    while ($conexion->more_results() && $conexion->next_result()) { ; }
    assertTest(true, "Migración UP de tablas Level 2 ejecutada correctamente", $passCount, $failCount);

    // Limpiar tablas de pruebas
    $conexion->query("DELETE FROM op_rh_v2_level2_window_evaluations");
    $conexion->query("DELETE FROM op_rh_v2_dispositivo_telemetria");
    $conexion->query("DELETE FROM op_rh_v2_marcaciones_nivel2_metadata");
    $conexion->query("DELETE FROM op_rh_v2_dispositivo_estacion_historial");
    $conexion->query("DELETE FROM op_rh_v2_dispositivo_generaciones");
    $conexion->query("DELETE FROM op_rh_v2_marcaciones_crudas WHERE event_uuid LIKE 'ev-l2-%' OR event_uuid LIKE 'ev-legacy-%'");
    $conexion->query("DELETE FROM op_rh_v2_dispositivo_credenciales WHERE id_dispositivo IN (100, 101)");
    $conexion->query("DELETE FROM op_rh_v2_dispositivos WHERE id IN (100, 101)");

    $l2Service = new Level2ServerService();
    $healthyTelemetryPayload = [
        'application_running' => true,
        'sdk_initialized' => true,
        'sensor_connected' => true,
        'capture_thread_alive' => true,
        'queue_writable' => true
    ];

    // Setup Dispositivos y Credenciales
    $secretHash = password_hash('secret123', PASSWORD_BCRYPT);
    $conexion->query("INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado) VALUES (100, 'dev-uuid-100', 1, 'Biometrico 100', 'America/Mexico_City', 1)");
    $conexion->query("INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, valido_desde, estado, creado_at) VALUES (100, 'key-100', '$secretHash', NOW(), 'ACTIVE', NOW())");

    $conexion->query("INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado) VALUES (101, 'dev-uuid-101', 1, 'Biometrico 101', 'America/Mexico_City', 1)");
    $conexion->query("INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, valido_desde, estado, creado_at) VALUES (101, 'key-101', '$secretHash', NOW(), 'ACTIVE', NOW())");

    $l2Service->registrarGeneracion($conexion, 101, 'dev-uuid-101', 'gen-101-v1');

    // 2. Test Registro de Generaciones (AUTH2-B)
    $resGen1 = $l2Service->registrarGeneracion($conexion, 100, 'dev-uuid-100', 'gen-100-v1');
    assertTest($resGen1['success'] && $resGen1['status'] === 'REGISTERED', "Registro de generación V1 para dispositivo 100 exitoso", $passCount, $failCount);

    $resGen1Dup = $l2Service->registrarGeneracion($conexion, 100, 'dev-uuid-100', 'gen-100-v1');
    assertTest($resGen1Dup['success'] && $resGen1Dup['status'] === 'ALREADY_REGISTERED', "Registro idéntico de generación devuelve ALREADY_REGISTERED", $passCount, $failCount);

    $resGenSpoof = $l2Service->registrarGeneracion($conexion, 100, 'dev-uuid-OTHER', 'gen-100-v1');
    assertTest(!$resGenSpoof['success'] && $resGenSpoof['code'] === 403, "Intento de registrar generación con UUID cruzado es rechazado con 403 DEVICE_MISMATCH", $passCount, $failCount);

    // 3. Test Historial de Asignaciones (AUTH2-C & Multi-device & Handover)
    $l2Service->registrarAsignacionEstacion($conexion, 100, 1, '2026-01-01 00:00:00', '2026-06-30 23:59:59');
    $l2Service->registrarAsignacionEstacion($conexion, 101, 1, '2026-01-01 00:00:00', '2026-12-31 23:59:59');

    $relEst1Feb = $l2Service->obtenerGeneracionesYDispositivosRelevantes($conexion, 1, '2026-02-01 00:00:00', '2026-02-05 00:00:00');
    assertTest(count($relEst1Feb) === 2, "Estación 1 en febrero resuelve 2 dispositivos (100 y 101) asignados", $passCount, $failCount);

    // Reasignación de 100 a Estación 2 a partir de Julio
    $l2Service->registrarAsignacionEstacion($conexion, 100, 2, '2026-07-01 00:00:00', null);

    $relEst1Ago = $l2Service->obtenerGeneracionesYDispositivosRelevantes($conexion, 1, '2026-08-01 00:00:00', '2026-08-05 00:00:00');
    assertTest(count($relEst1Ago) === 1 && $relEst1Ago[0]['id_dispositivo'] === 101, "Estación 1 en agosto solo tiene al dispositivo 101 tras reasignación de 100", $passCount, $failCount);

    // 4. Test Ingesta con Secuencia Nivel 2, Gaps y Accepted-Through (AUTH2-D & AUTH2-E)
    $conexion->query("INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal, source_timestamp_raw, recibido_at_utc) VALUES 
    ('ev-l2-001', 100, 1, 'EMP-1', '2026-02-01 08:00:00', '2026-02-01 08:00:05'),
    ('ev-l2-002', 100, 1, 'EMP-2', '2026-02-01 08:05:00', '2026-02-01 08:05:05'),
    ('ev-l2-003', 100, 1, 'EMP-3', '2026-02-01 08:10:00', '2026-02-01 08:10:05') ON DUPLICATE KEY UPDATE event_uuid=event_uuid");

    $idEv1 = (int)$conexion->query("SELECT id FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = 'ev-l2-001'")->fetch_assoc()['id'];
    $idEv2 = (int)$conexion->query("SELECT id FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = 'ev-l2-002'")->fetch_assoc()['id'];
    $idEv3 = (int)$conexion->query("SELECT id FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = 'ev-l2-003'")->fetch_assoc()['id'];

    $resSeq1 = $l2Service->procesarSecuenciaNivel2($conexion, $idEv1, 100, 'gen-100-v1', 1);
    $resSeq3 = $l2Service->procesarSecuenciaNivel2($conexion, $idEv3, 100, 'gen-100-v1', 3); // Omitimos la 2 intencionalmente para crear Gap

    $accThroughGap = $l2Service->calcularAcceptedThrough($conexion, 100, 'gen-100-v1');
    assertTest($accThroughGap === 1, "Detección de Gap (1, 3): accepted_through se detiene en 1", $passCount, $failCount);

    // Insertar la secuencia 2 faltante
    $resSeq2 = $l2Service->procesarSecuenciaNivel2($conexion, $idEv2, 100, 'gen-100-v1', 2);
    $accThroughFilled = $l2Service->calcularAcceptedThrough($conexion, 100, 'gen-100-v1');
    assertTest($accThroughFilled === 3, "Llenar el Gap (insertando secuencia 2) avanza accepted_through contiguo a 3", $passCount, $failCount);

    // Test Sequence Collision
    $resCollision = $l2Service->procesarSecuenciaNivel2($conexion, $idEv1, 100, 'gen-100-v1', 2); // Intentar asignar la secuencia 2 a evento 1 (que ya tiene secuencia 1)
    assertTest(!$resCollision['success'] && $resCollision['code'] === 409, "Conflicto de secuencia asignada a otro evento es rechazado con 409 SEQUENCE_COLLISION", $passCount, $failCount);

    // 5. Test Telemetría Operativa e Historial (AUTH2-F)
    $resTelem1 = $l2Service->registrarTelemetria($conexion, 100, 'gen-100-v1', 1, '2026-02-01 07:55:00', 95, 'CONNECTED', 'SYNCED', $healthyTelemetryPayload + ['temp' => 36.5]);
    $resTelem2 = $l2Service->registrarTelemetria($conexion, 100, 'gen-100-v1', 2, '2026-02-01 08:15:00', 94, 'CONNECTED', 'SYNCED', $healthyTelemetryPayload + ['temp' => 36.6]);
    assertTest($resTelem1['success'] && $resTelem2['success'], "Registros de telemetría operativa almacenados con éxito", $passCount, $failCount);

    $countTelem = $conexion->query("SELECT COUNT(*) as c FROM op_rh_v2_dispositivo_telemetria WHERE id_dispositivo = 100 AND device_generation = 'gen-100-v1'")->fetch_assoc()['c'];
    assertTest((int)$countTelem === 2, "Historial completo de telemetría duraderamente conservado", $passCount, $failCount);

    // 6. Test Evaluación de Ventana Nivel 2 y Manifiesto Canónico (AUTH2-G, AUTH2-H, AUTH2-I)
    // Para Estación 1 en Agosto sólo el dispositivo 101 está asignado, registramos telemetría y secuencia para 101 en Agosto
    $conexion->query("INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal, source_timestamp_raw, recibido_at_utc) VALUES 
    ('ev-l2-004', 101, 1, 'EMP-4', '2026-08-01 08:00:00', '2026-08-01 08:00:05') ON DUPLICATE KEY UPDATE event_uuid=event_uuid");
    $idEv4 = (int)$conexion->query("SELECT id FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = 'ev-l2-004'")->fetch_assoc()['id'];
    $l2Service->procesarSecuenciaNivel2($conexion, $idEv4, 101, 'gen-101-v1', 1);

    $l2Service->registrarTelemetria($conexion, 101, 'gen-101-v1', 1, '2026-08-01 07:55:00', 100, 'CONNECTED', 'SYNCED', $healthyTelemetryPayload, '2026-08-01 07:55:05');
    $l2Service->registrarTelemetria($conexion, 101, 'gen-101-v1', 2, '2026-08-01 08:05:00', 99, 'CONNECTED', 'SYNCED', $healthyTelemetryPayload, '2026-08-01 08:05:05');

    $evalResult = $l2Service->evaluarVentanaNivel2($conexion, 1, '2026-08-01 07:55:00', '2026-08-01 08:05:00');
    assertTest($evalResult['window_level2_status'] === 'VERIFIED', "Evaluación de ventana completa retorna Nivel 2 VERIFIED para todos los dispositivos de la estación", $passCount, $failCount);
    assertTest(isset($evalResult['manifest_digest']) && strlen($evalResult['manifest_digest']) === 64, "Manifiesto canónico reproducible registrado con digest SHA256", $passCount, $failCount);

    // 7. Test Backward Compatibility: Marcaciones pre-cutover sin generación/secuencia (AUTH2-J)
    $conexion->query("INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal, source_timestamp_raw, recibido_at_utc) VALUES 
    ('ev-legacy-005', 100, 1, 'EMP-LEGACY', '2026-01-01 08:00:00', '2026-01-01 08:00:05') ON DUPLICATE KEY UPDATE event_uuid=event_uuid");
    $idEv5 = (int)$conexion->query("SELECT id FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = 'ev-legacy-005'")->fetch_assoc()['id'];
    
    $checkPreCutover = $conexion->query("SELECT * FROM op_rh_v2_marcaciones_nivel2_metadata WHERE id_raw_punch = $idEv5")->fetch_assoc();
    assertTest($checkPreCutover === null, "Marcación legacy pre-cutover permanece unsequenced sin metadatos Nivel 2 ni alteración en tabla cruda", $passCount, $failCount);

    // 8. Test Rollback DOWN Migración Level 2
    $sqlDownL2 = file_get_contents(__DIR__ . "/../migraciones/v2/2026_10_05_000002_rollback_v2_level2_server_tables.sql");
    $conexion->multi_query($sqlDownL2);
    while ($conexion->more_results() && $conexion->next_result()) { ; }

    $resTablesRollbackL2 = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_dispositivo_generaciones'");
    assertTest($resTablesRollbackL2 && $resTablesRollbackL2->num_rows === 0, "Rollback DOWN de migración Level 2 eliminó las tablas Level 2 correctamente", $passCount, $failCount);

    $resRawCheck = $conexion->query("SELECT id FROM op_rh_v2_marcaciones_crudas WHERE id = $idEv5");
    assertTest($resRawCheck && $resRawCheck->num_rows === 1, "Rollback DOWN NO modificó ni eliminó registros de op_rh_v2_marcaciones_crudas", $passCount, $failCount);

    // Limpieza final de datos de prueba
    $conexion->query("DELETE FROM op_rh_v2_marcaciones_crudas WHERE event_uuid LIKE 'ev-l2-%' OR event_uuid LIKE 'ev-legacy-%'");
    $conexion->query("DELETE FROM op_rh_v2_dispositivo_credenciales WHERE id_dispositivo IN (100, 101)");
    $conexion->query("DELETE FROM op_rh_v2_dispositivos WHERE id IN (100, 101)");

    $classBD->desconectarBD($conexion);

} catch (Throwable $e) {
    echo "\n[ ERROR DE EJECUCIÓN ] " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine() . "\n";
    $failCount++;
}

echo "\n=== RESUMEN DE PRUEBAS API LEVEL 2 SERVER CAPABILITIES ===\n";
echo "TOTAL PRUEBAS : " . ($passCount + $failCount) . "\n";
echo "PASARON       : $passCount\n";
echo "FALLARON      : $failCount\n";

if ($failCount > 0) {
    exit(1);
}
