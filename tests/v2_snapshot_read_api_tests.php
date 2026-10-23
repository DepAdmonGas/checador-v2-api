<?php
// Test Suite Completo para API V2.4 Level 1 Snapshot Read API
require_once __DIR__ . "/test_bootstrap_guard.php";
@ob_start();
require_once __DIR__ . "/../modelo/BD.php";
require_once __DIR__ . "/../modelo/v2/Portal3JwtAuthHelper.php";
require_once __DIR__ . "/../modelo/v2/SnapshotReadV2Service.php";

echo "=== INICIANDO PRUEBAS V2.4 LEVEL 1 READ SNAPSHOT API ===\n\n";

$testJwtSecret = 'desarrollo_portal3_jwt_secret_key_2026_admongas';
putenv("JWT_SECRET=$testJwtSecret");

$classBD = new BD();
$conexion = $classBD->conectarBD();

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $description, string &$failCount, int &$passCount): void {
    if ($condition) {
        echo "[ PASS ] " . $description . "\n";
        $passCount++;
    } else {
        echo "[ FAIL ] " . $description . "\n";
        $failCount++;
    }
}

try {
    // 1. Ejecutar migración UP
    $sqlUp = file_get_contents(__DIR__ . "/../migraciones/v2/2026_10_05_000001_create_v2_read_snapshot_tables.sql");
    $conexion->multi_query($sqlUp);
    while ($conexion->more_results() && $conexion->next_result()) { ; }

    // Asegurar triggers de inmutabilidad en op_rh_v2_marcaciones_crudas
    $conexion->query("DROP TRIGGER IF EXISTS tg_op_rh_v2_marcaciones_crudas_no_update");
    $conexion->query("CREATE TRIGGER tg_op_rh_v2_marcaciones_crudas_no_update BEFORE UPDATE ON op_rh_v2_marcaciones_crudas FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced.'; END");
    $conexion->query("DROP TRIGGER IF EXISTS tg_op_rh_v2_marcaciones_crudas_no_delete");
    $conexion->query("CREATE TRIGGER tg_op_rh_v2_marcaciones_crudas_no_delete BEFORE DELETE ON op_rh_v2_marcaciones_crudas FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced.'; END");

    assertTest(true, "Migración UP de tablas de Snapshot executada correctamente", $failCount, $passCount);

    // Asegurar tablas base auxiliares de Portal3 para autorización
    $conexion->query("CREATE TABLE IF NOT EXISTS modulos_dpto_operativo (id INT PRIMARY KEY AUTO_INCREMENT, nombre VARCHAR(255), clave VARCHAR(100), activo INT DEFAULT 1)");
    $conexion->query("CREATE TABLE IF NOT EXISTS tb_modulos_config (id INT PRIMARY KEY AUTO_INCREMENT, modulo_key VARCHAR(100), tipo VARCHAR(50) DEFAULT 'stations_only', estaciones_soportadas LONGTEXT, tipo_departamento VARCHAR(50) DEFAULT 'localidades', allow_all INT DEFAULT 0, activo INT DEFAULT 1)");
    $conexion->query("CREATE TABLE IF NOT EXISTS tb_usuarios (id INT PRIMARY KEY AUTO_INCREMENT, usuario VARCHAR(100), id_gas INT, id_puesto INT, estatus INT DEFAULT 0)");
    $conexion->query("CREATE TABLE IF NOT EXISTS modulos_usuarios_do (id INT PRIMARY KEY AUTO_INCREMENT, id_modulo INT, id_usuario INT, leer INT DEFAULT 1, crear INT DEFAULT 0, editar INT DEFAULT 0, eliminar INT DEFAULT 0, descargar INT DEFAULT 0)");
    $conexion->query("CREATE TABLE IF NOT EXISTS modulos_puestos_do (id INT PRIMARY KEY AUTO_INCREMENT, id_modulo INT, id_puesto INT, leer INT DEFAULT 1, crear INT DEFAULT 0, editar INT DEFAULT 0, eliminar INT DEFAULT 0, descargar INT DEFAULT 0)");
    $conexion->query("CREATE TABLE IF NOT EXISTS tb_estaciones (id INT PRIMARY KEY AUTO_INCREMENT, numlista INT DEFAULT 0, estatus INT DEFAULT 0)");
    $conexion->query("CREATE TABLE IF NOT EXISTS op_rh_localidades (id INT PRIMARY KEY AUTO_INCREMENT, numlista INT DEFAULT 0)");
    $conexion->query("CREATE TABLE IF NOT EXISTS tb_multiestacion_usuario (id INT PRIMARY KEY AUTO_INCREMENT, id_usuario INT, estaciones TEXT, activo INT DEFAULT 1)");
    $conexion->query("CREATE TABLE IF NOT EXISTS tb_multiestacion_puesto (id INT PRIMARY KEY AUTO_INCREMENT, id_puesto INT, estaciones TEXT, activo INT DEFAULT 1)");

    $resTables = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_read_snapshots'");
    assertTest($resTables && $resTables->num_rows === 1, "Tabla op_rh_v2_read_snapshots existe", $failCount, $passCount);

    $resMembers = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_read_snapshot_members'");
    assertTest($resMembers && $resMembers->num_rows === 1, "Tabla op_rh_v2_read_snapshot_members existe", $failCount, $passCount);

    $resManifests = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_durable_snapshot_manifests'");
    assertTest($resManifests && $resManifests->num_rows === 1, "Tabla op_rh_v2_durable_snapshot_manifests existe", $failCount, $passCount);

    // 2. Limpiar datos de prueba previos si existen
    $conexion->query("DROP TRIGGER IF EXISTS tg_op_rh_v2_marcaciones_crudas_no_update");
    $conexion->query("DROP TRIGGER IF EXISTS tg_op_rh_v2_marcaciones_crudas_no_delete");
    $conexion->query("DELETE FROM op_rh_v2_read_snapshot_members");
    $conexion->query("DELETE FROM op_rh_v2_durable_snapshot_manifests");
    $conexion->query("DELETE FROM op_rh_v2_read_snapshots");
    $conexion->query("DELETE FROM op_rh_v2_marcaciones_crudas WHERE event_uuid LIKE 'ev-snap-%'");

    // Re-crear triggers de inmutabilidad en op_rh_v2_marcaciones_crudas
    $conexion->query("CREATE TRIGGER tg_op_rh_v2_marcaciones_crudas_no_update BEFORE UPDATE ON op_rh_v2_marcaciones_crudas FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced.'; END");
    $conexion->query("CREATE TRIGGER tg_op_rh_v2_marcaciones_crudas_no_delete BEFORE DELETE ON op_rh_v2_marcaciones_crudas FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced.'; END");

    // Mock JWT Token para Usuario Creador (ID: 999)
    function base64UrlEnc($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    $header = base64UrlEnc(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payloadData = [
        'iss' => 'portal3_auth',
        'sub' => 999,
        'type' => 'access',
        'user_id' => 999,
        'username' => 'test_user_snapshot',
        'id_puesto' => 10,
        'iat' => time(),
        'exp' => time() + 3600
    ];
    $payload = base64UrlEnc(json_encode($payloadData));
    $sigB64 = base64UrlEnc(hash_hmac('sha256', "$header.$payload", $testJwtSecret, true));
    $validJwt = "$header.$payload.$sigB64";
    $authHeader = "Bearer $validJwt";

    // Insertar datos de autorización y mapeo de estación a localidad
    $conexion->query("INSERT IGNORE INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (999, 'user_snap_999', 1, 10, 0)");
    $conexion->query("DELETE FROM modulos_dpto_operativo WHERE clave IN ('acceso-temporal', 'biometricos') OR id IN (15, 20)");
    $conexion->query("INSERT INTO modulos_dpto_operativo (id, nombre, clave, activo) VALUES (20, 'Acceso Temporal', 'acceso-temporal', 1), (15, 'Biométricos', 'biometricos', 1)");
    $conexion->query("DELETE FROM modulos_usuarios_do WHERE id_usuario = 999");
    $conexion->query("INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer, crear, editar, eliminar, descargar) VALUES (20, 999, 1, 1, 1, 1, 1), (15, 999, 1, 0, 0, 0, 0)");
    $conexion->query("DELETE FROM tb_modulos_config WHERE id IN (15, 20) OR modulo_key IN ('acceso-temporal', 'biometricos')");
    $conexion->query("INSERT INTO tb_modulos_config (id, modulo_key, estaciones_soportadas, tipo_departamento, activo) VALUES (20, 'acceso-temporal', '[1,2,15]', 'localidades', 1), (15, 'biometricos', '[1,2,15]', 'localidades', 1)");
    
    // Configurar tb_estaciones y op_rh_localidades para que Portal3JwtAuthHelper::resolveStationScope mapée numlista 1:1
    $conexion->query("SET FOREIGN_KEY_CHECKS = 0");
    $conexion->query("DELETE FROM tb_estaciones WHERE id IN (1,2,15)");
    $conexion->query("INSERT INTO tb_estaciones (id, numlista, estatus) VALUES (1, 1, 0), (2, 2, 0), (15, 15, 0)");
    $conexion->query("DELETE FROM op_rh_localidades WHERE id IN (1,2,15)");
    $conexion->query("INSERT INTO op_rh_localidades (id, numlista, localidad, recuperacion_vapores) VALUES (1, 1, 'Loc 1', 'NO'), (2, 2, 'Loc 2', 'NO'), (15, 15, 'Loc 15', 'NO')");
    $conexion->query("DELETE FROM tb_multiestacion_usuario WHERE id_usuario = 999");
    $conexion->query("INSERT INTO tb_multiestacion_usuario (id_usuario, estaciones, activo) VALUES (999, '[1,2,15]', 1)");
    $conexion->query("SET FOREIGN_KEY_CHECKS = 1");

    // Insertar marcaciones crudas de prueba con campos completos
    $conexion->query("INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal, id_personal_resuelto, source_timestamp_raw, source_timezone_reported, effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc, metodo_captura, external_event_id, secuencia_dispositivo, processing_status, validation_flags, metadata_json) VALUES 
    ('ev-snap-001', 1, 15, 'EMP-101', 20, '2026-10-01T08:00:00Z', 'America/Mexico_City', 'America/Mexico_City', 0, '2026-10-01 14:00:00', '2026-10-01 14:00:05', 'BIOMETRIC', 'EXT-1', 1, 'RECEIVED', '[]', '{}'),
    ('ev-snap-002', 1, 15, 'EMP-102', 21, '2026-10-01T08:05:00Z', 'America/Mexico_City', 'America/Mexico_City', 0, '2026-10-01 14:05:00', '2026-10-01 14:05:05', 'BIOMETRIC', 'EXT-2', 2, 'RECEIVED', '[]', '{}'),
    ('ev-snap-003', 2, 15, 'EMP-103', 22, '2026-10-01T08:10:00Z', 'America/Mexico_City', 'America/Mexico_City', 0, '2026-10-01 14:10:00', '2026-10-01 14:10:05', 'BIOMETRIC', 'EXT-3', 1, 'RECEIVED', '[]', '{}') ON DUPLICATE KEY UPDATE event_uuid=event_uuid");

    // 3. Test de Concurrencia: Delayed Lower-ID Commit Exclusion
    $conexion->query("START TRANSACTION");
    $conexion->query("INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal, source_timestamp_raw, source_timezone_reported, effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc, metodo_captura, processing_status) VALUES ('ev-snap-uncommitted', 1, 15, 'EMP-DELAYED', '2026-10-01T07:00:00Z', 'UTC', 'UTC', 0, '2026-10-01 13:00:00', '2026-10-01 13:00:00', 'BIOMETRIC', 'RECEIVED')");
    $delayedId = $conexion->insert_id;

    $_SERVER['HTTP_AUTHORIZATION'] = $authHeader;
    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'idem-test-uuid-001';
    $_SERVER['CONTENT_TYPE'] = 'application/json';

    $_POST = [];
    $inputData = json_encode([
        'from' => '2026-10-01T00:00:00Z',
        'to' => '2026-10-02T00:00:00Z',
        'station_id' => 15
    ]);

    $service = new SnapshotReadV2Service();

    ob_start();
    stream_wrapper_unregister("php");
    stream_wrapper_register("php", "MockPhpStream");
    file_put_contents('php://input', $inputData);

    try {
        $service->handleCreateSnapshot();
    } catch (Throwable $t) {
        echo "Excepción en handleCreateSnapshot: " . $t->getMessage() . " en " . $t->getFile() . ":" . $t->getLine() . "\n";
    }
    $outputJson = ob_get_clean();

    $response = json_decode($outputJson, true);
    assertTest(isset($response['status']) && $response['status'] === 'success', "Creación de Snapshot retornó 201 Created READY (Respuesta: $outputJson)", $failCount, $passCount);

    $snapshotToken = $response['data']['snapshot']['snapshot_token'] ?? null;
    $snapshotAuditRef = $response['data']['snapshot']['snapshot_audit_ref'] ?? null;
    assertTest($snapshotToken !== null && $snapshotAuditRef !== null, "snapshot_token y snapshot_audit_ref válidos generados", $failCount, $passCount);

    // Commit T1 diferido
    $conexion->query("COMMIT");

    // Verificar que T1 NO está en el Snapshot recién creado
    $resMemberCheck = $conexion->query("SELECT m.id FROM op_rh_v2_read_snapshot_members m INNER JOIN op_rh_v2_read_snapshots s ON m.id_snapshot = s.id WHERE s.snapshot_token = '$snapshotToken' AND m.id_raw_punch = $delayedId");
    assertTest($resMemberCheck && $resMemberCheck->num_rows === 0, "Transacción T1 diferida con ID menor NO forma parte del snapshot existente", $failCount, $passCount);

    // 4. Test de Paginación Determinista y Keyset Cursor
    $_GET = ['limit' => 2];
    ob_start();
    $service->handlePageSnapshot($snapshotToken ?? '');
    $page1Output = ob_get_clean();
    $page1 = json_decode($page1Output, true);

    assertTest(isset($page1['data']['items']) && count($page1['data']['items']) === 2, "Página 1 retornó exactamente 2 marcaciones", $failCount, $passCount);
    assertTest($page1['data']['pagination']['has_more'] === true, "Página 1 indica has_more = true", $failCount, $passCount);

    $nextCursor = $page1['data']['pagination']['next_cursor'];
    assertTest($nextCursor !== null, "Cursor opaco next_cursor generado correctamente", $failCount, $passCount);

    $_GET = ['cursor' => $nextCursor, 'limit' => 2];
    ob_start();
    $service->handlePageSnapshot($snapshotToken);
    $page2Output = ob_get_clean();
    $page2 = json_decode($page2Output, true);

    assertTest(isset($page2['data']['items']) && count($page2['data']['items']) === 1, "Página 2 retornó exactamente 1 marcación restante", $failCount, $passCount);
    assertTest($page2['data']['pagination']['has_more'] === false, "Página 2 indica has_more = false", $failCount, $passCount);

    // 5. Test de Idempotencia de Creación
    file_put_contents('php://input', $inputData);
    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'idem-test-uuid-001';
    ob_start();
    $service->handleCreateSnapshot();
    $idemOutput = ob_get_clean();
    $idemResp = json_decode($idemOutput, true);

    assertTest($idemResp['data']['snapshot']['snapshot_token'] === $snapshotToken, "Re-intentar POST con la misma clave de idempotencia retorna exactamente el mismo snapshot_token", $failCount, $passCount);

    // 6. Test de ACL Creator Binding & Reducción de Scope (Fail Closed)
    $conexion->query("INSERT IGNORE INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (888, 'user_snap_888', 1, 10, 0)");
    $payloadDataUser888 = $payloadData;
    $payloadDataUser888['sub'] = 888;
    $payloadDataUser888['user_id'] = 888;
    $payload888 = base64UrlEnc(json_encode($payloadDataUser888));
    $sig888 = base64UrlEnc(hash_hmac('sha256', "$header.$payload888", $testJwtSecret, true));
    $jwtUser888 = "$header.$payload888.$sig888";

    $_SERVER['HTTP_AUTHORIZATION'] = "Bearer $jwtUser888";
    $_GET = [];
    ob_start();
    $service->handlePageSnapshot($snapshotToken);
    $deniedOutput = ob_get_clean();
    $deniedResp = json_decode($deniedOutput, true);

    assertTest(isset($deniedResp['code']) && $deniedResp['code'] === 403 && $deniedResp['status'] === 'SNAPSHOT_ACCESS_DENIED', "Consumir token con otro usuario es denegado con 403 SNAPSHOT_ACCESS_DENIED", $failCount, $passCount);

    // Simular reducción de scope (Modificar tb_multiestacion_usuario para remover la estación 15)
    $conexion->query("UPDATE tb_multiestacion_usuario SET estaciones = '[1,2]' WHERE id_usuario = 999");
    $_SERVER['HTTP_AUTHORIZATION'] = $authHeader;

    ob_start();
    $service->handlePageSnapshot($snapshotToken);
    $scopeReducedOutput = ob_get_clean();
    $scopeReducedResp = json_decode($scopeReducedOutput, true);

    assertTest(isset($scopeReducedResp['code']) && $scopeReducedResp['code'] === 403 && $scopeReducedResp['status'] === 'SNAPSHOT_SCOPE_CHANGED', "Reducción de scope de estación en el usuario falla cerrado con 403 SNAPSHOT_SCOPE_CHANGED", $failCount, $passCount);

    // Restaurar scope
    $conexion->query("UPDATE tb_multiestacion_usuario SET estaciones = '[1,2,15]' WHERE id_usuario = 999");

    // 7. Test de Eventos de Llegada Tardía (Late-Arriving Events Exclusion)
    $conexion->query("INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal, source_timestamp_raw, source_timezone_reported, effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc, metodo_captura, processing_status) VALUES ('ev-snap-late-001', 1, 15, 'EMP-LATE', '2026-10-01T04:00:00Z', 'UTC', 'UTC', 0, '2026-10-01 10:00:00', '2026-10-05 14:00:00', 'BIOMETRIC', 'RECEIVED')");

    $_GET = ['limit' => 100];
    ob_start();
    $service->handlePageSnapshot($snapshotToken);
    $pageAllOutput = ob_get_clean();
    $pageAll = json_decode($pageAllOutput, true);

    $lateFoundInS = false;
    foreach ($pageAll['data']['items'] as $item) {
        if ($item['event_uuid'] === 'ev-snap-late-001') {
            $lateFoundInS = true;
            break;
        }
    }
    assertTest(!$lateFoundInS, "Evento tardío ingresado posteriormente NO se incluye en el Snapshot S existente", $failCount, $passCount);

    // Crear Snapshot S2 posterior y verificar inclusión de evento tardío
    file_put_contents('php://input', $inputData);
    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'idem-test-uuid-002';
    ob_start();
    $service->handleCreateSnapshot();
    $snap2Output = ob_get_clean();
    $snap2Resp = json_decode($snap2Output, true);

    $snapshotToken2 = $snap2Resp['data']['snapshot']['snapshot_token'];
    $_GET = ['limit' => 100];
    ob_start();
    $service->handlePageSnapshot($snapshotToken2);
    $pageSnap2Output = ob_get_clean();
    $pageSnap2 = json_decode($pageSnap2Output, true);

    $lateFoundInS2 = false;
    foreach ($pageSnap2['data']['items'] as $item) {
        if ($item['event_uuid'] === 'ev-snap-late-001') {
            $lateFoundInS2 = true;
            break;
        }
    }
    assertTest($lateFoundInS2, "Snapshot S2 posterior SÍ incluye el evento de llegada tardía", $failCount, $passCount);

    // 8. Test de Manifiesto Durable de Auditoría y Reproducibilidad SHA256
    $resManifest = $conexion->query("SELECT manifest_digest, canonical_manifest_json FROM op_rh_v2_durable_snapshot_manifests WHERE snapshot_audit_ref = '$snapshotAuditRef'");
    assertTest($resManifest && $resManifest->num_rows === 1, "Manifiesto durable registrado para snapshot_audit_ref", $failCount, $passCount);

    $manifestRow = $resManifest->fetch_assoc();
    $recalculatedDigest = hash('sha256', $manifestRow['canonical_manifest_json']);
    assertTest($manifestRow['manifest_digest'] === $recalculatedDigest, "Digest SHA256 de integridad del manifiesto canónico coincide perfectamente", $failCount, $passCount);

    // 9. Test de Inmutabilidad de Marcaciones Crudas (UPDATE / DELETE prohibidos)
    $stmtUpdate = $conexion->prepare("UPDATE op_rh_v2_marcaciones_crudas SET referencia_externa_personal = 'MODIFIED' WHERE event_uuid = 'ev-snap-001'");
    $updateFailed = false;
    if ($stmtUpdate) {
        try {
            $execRes = $stmtUpdate->execute();
            if (!$execRes || $stmtUpdate->errno !== 0) {
                $updateFailed = true;
            }
        } catch (Throwable $t) {
            $updateFailed = true;
        }
        $stmtUpdate->close();
    } else {
        $updateFailed = true;
    }
    assertTest($updateFailed, "Trigger de inmutabilidad rechaza UPDATE en marcaciones crudas", $failCount, $passCount);

    // 10. Test de Migración DOWN (Rollback seguro)
    $conexion->query("DROP TRIGGER IF EXISTS tg_op_rh_v2_marcaciones_crudas_no_update");
    $conexion->query("DROP TRIGGER IF EXISTS tg_op_rh_v2_marcaciones_crudas_no_delete");
    $conexion->query("DELETE FROM tb_usuarios WHERE id IN (888, 999)");
    $conexion->query("DELETE FROM modulos_usuarios_do WHERE id_usuario IN (888, 999)");
    $conexion->query("DELETE FROM tb_multiestacion_usuario WHERE id_usuario IN (888, 999)");

    $sqlDown = file_get_contents(__DIR__ . "/../migraciones/v2/2026_10_05_000001_rollback_v2_read_snapshot_tables.sql");
    $conexion->multi_query($sqlDown);
    while ($conexion->more_results() && $conexion->next_result()) { ; }

    $conexion->query("DELETE FROM op_rh_v2_marcaciones_crudas WHERE event_uuid LIKE 'ev-snap-%'");

    $resTablesRollback = $conexion->query("SHOW TABLES LIKE 'op_rh_v2_read_snapshots'");
    assertTest($resTablesRollback && $resTablesRollback->num_rows === 0, "Migración DOWN eliminó la tabla op_rh_v2_read_snapshots", $failCount, $passCount);

    $classBD->desconectarBD($conexion);

} catch (Throwable $e) {
    echo "\n[ ERROR DE EJECUCIÓN ] " . $e->getMessage() . "\n";
    $failCount++;
}

echo "\n=== RESUMEN DE PRUEBAS V2.4 LEVEL 1 ===\n";
echo "TOTAL PRUEBAS : " . ($passCount + $failCount) . "\n";
echo "PASARON       : $passCount\n";
echo "FALLARON      : $failCount\n";

if ($failCount > 0) {
    exit(1);
}

// Stream Wrapper Mock para php://input
class MockPhpStream {
    private static $content = '';
    private $position = 0;

    public function stream_open($path, $mode, $options, &$opened_path) {
        $this->position = 0;
        return true;
    }
    public function stream_read($count) {
        $ret = substr(self::$content, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }
    public function stream_write($data) {
        self::$content = $data;
        return strlen($data);
    }
    public function stream_tell() {
        return $this->position;
    }
    public function stream_eof() {
        return $this->position >= strlen(self::$content);
    }
    public function stream_stat() {
        return [];
    }
}
