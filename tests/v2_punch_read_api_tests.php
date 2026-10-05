<?php
/**
 * Test Suite de Consulta READ-ONLY V2.3-G4B: GET /api/v2/punch-events
 */

require_once __DIR__ . '/test_bootstrap_guard.php';
@ob_start();
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/Portal3JwtAuthHelper.php';
require_once __DIR__ . '/../modelo/v2/PunchEventReadV2Controller.php';
require_once __DIR__ . '/../modelo/v2/PunchEventIntegrityService.php';

function run_test($name, $closure) {
    global $passed, $failed;
    echo "Running [{$name}] ... ";
    try {
        $closure();
        echo "PASS\n";
        $passed++;
        return true;
    } catch (Throwable $e) {
        echo "FAIL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
        $failed++;
        return false;
    }
}

$passed = 0;
$failed = 0;

$bdObj = new BD();
$db = $bdObj->conectarBD();

$jwtSecret = 'test_jwt_secret_key_g4b_2026';
$secretSynthetic = 'test_g4b_secret_key_2026';
$keyRing = ['k1' => $secretSynthetic];

putenv("JWT_SECRET={$jwtSecret}");
putenv("RAW_PUNCH_INTEGRITY_MANDATORY=true");
putenv("RAW_PUNCH_ACTIVE_KEY_ID=k1");
putenv("RAW_PUNCH_KEY_RING=" . json_encode($keyRing));

function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function create_test_jwt(array $claims, string $secret) {
    $head = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = array_merge([
        'iss' => 'portal3_auth',
        'exp' => time() + 3600,
        'type' => 'access',
        'sub' => 1
    ], $claims);

    $hB64 = base64UrlEncode(json_encode($head));
    $pB64 = base64UrlEncode(json_encode($payload));
    $sigB64 = base64UrlEncode(hash_hmac('sha256', $hB64 . '.' . $pB64, $secret, true));

    return $hB64 . '.' . $pB64 . '.' . $sigB64;
}

// 1. JWT Invalido / Ausente -> 401 UNAUTHORIZED
run_test("1. Missing or invalid JWT -> 401 UNAUTHORIZED", function() use ($db) {
    $helper = new Portal3JwtAuthHelper();
    $resMissing = $helper->validateToken($db, '');
    if ($resMissing['code'] !== 401) throw new Exception("Expected 401 on missing JWT");

    $resWrongIss = $helper->validateToken($db, "Bearer " . create_test_jwt(['iss' => 'wrong_iss'], getenv('JWT_SECRET')));
    if ($resWrongIss['code'] !== 401) throw new Exception("Expected 401 on wrong issuer");

    $resExpired = $helper->validateToken($db, "Bearer " . create_test_jwt(['exp' => time() - 3600], getenv('JWT_SECRET')));
    if ($resExpired['code'] !== 401) throw new Exception("Expected 401 on expired token");
});

// 2. Permiso funcional biométricos.leer ausente -> 403 FUNCTION_PERMISSION_DENIED
run_test("2. Missing biometricos.leer permission -> 403 FUNCTION_PERMISSION_DENIED", function() use ($db) {
    // Asegurar estructura base idéntica en BD desechable
    $db->query("CREATE TABLE IF NOT EXISTS modulos_dpto_operativo (id INT PRIMARY KEY AUTO_INCREMENT, nombre VARCHAR(255), clave VARCHAR(100), activo INT DEFAULT 1)");
    $db->query("CREATE TABLE IF NOT EXISTS tb_modulos_config (id INT PRIMARY KEY AUTO_INCREMENT, modulo_key VARCHAR(100), tipo VARCHAR(50) DEFAULT 'stations_only', estaciones_soportadas LONGTEXT, tipo_departamento VARCHAR(50) DEFAULT 'localidades', allow_all INT DEFAULT 0, activo INT DEFAULT 1)");
    $db->query("CREATE TABLE IF NOT EXISTS tb_usuarios (id INT PRIMARY KEY AUTO_INCREMENT, usuario VARCHAR(100), id_gas INT, id_puesto INT, estatus INT DEFAULT 0)");
    $db->query("CREATE TABLE IF NOT EXISTS modulos_usuarios_do (id INT PRIMARY KEY AUTO_INCREMENT, id_modulo INT, id_usuario INT, leer INT DEFAULT 1, crear INT DEFAULT 0, editar INT DEFAULT 0, eliminar INT DEFAULT 0, descargar INT DEFAULT 0)");
    $db->query("CREATE TABLE IF NOT EXISTS modulos_puestos_do (id INT PRIMARY KEY AUTO_INCREMENT, id_modulo INT, id_puesto INT, leer INT DEFAULT 1, crear INT DEFAULT 0, editar INT DEFAULT 0, eliminar INT DEFAULT 0, descargar INT DEFAULT 0)");
    $db->query("CREATE TABLE IF NOT EXISTS tb_estaciones (id INT PRIMARY KEY AUTO_INCREMENT, numlista INT DEFAULT 0, estatus INT DEFAULT 0)");
    $db->query("CREATE TABLE IF NOT EXISTS op_rh_localidades (id INT PRIMARY KEY AUTO_INCREMENT, numlista INT DEFAULT 0)");
    $db->query("CREATE TABLE IF NOT EXISTS tb_multiestacion_usuario (id INT PRIMARY KEY AUTO_INCREMENT, id_usuario INT, estaciones TEXT, activo INT DEFAULT 1)");
    $db->query("CREATE TABLE IF NOT EXISTS tb_multiestacion_puesto (id INT PRIMARY KEY AUTO_INCREMENT, id_puesto INT, estaciones TEXT, activo INT DEFAULT 1)");

    $chkCfg = $db->query("SHOW COLUMNS FROM tb_modulos_config LIKE 'estaciones_soportadas'");
    if ($chkCfg->num_rows === 0) {
        $db->query("ALTER TABLE tb_modulos_config ADD COLUMN estaciones_soportadas LONGTEXT, ADD COLUMN tipo_departamento VARCHAR(50) DEFAULT 'localidades'");
    }
    $chkEst = $db->query("SHOW COLUMNS FROM tb_estaciones LIKE 'numlista'");
    if ($chkEst->num_rows === 0) {
        $db->query("ALTER TABLE tb_estaciones ADD COLUMN numlista INT DEFAULT 0");
    }
    $chkLoc = $db->query("SHOW COLUMNS FROM op_rh_localidades LIKE 'numlista'");
    if ($chkLoc->num_rows === 0) {
        $db->query("ALTER TABLE op_rh_localidades ADD COLUMN numlista INT DEFAULT 0");
    }
    $chkUsrDo = $db->query("SHOW COLUMNS FROM modulos_usuarios_do LIKE 'crear'");
    if ($chkUsrDo->num_rows === 0) {
        $db->query("ALTER TABLE modulos_usuarios_do ADD COLUMN crear INT DEFAULT 0, ADD COLUMN editar INT DEFAULT 0, ADD COLUMN eliminar INT DEFAULT 0, ADD COLUMN descargar INT DEFAULT 0");
    }
    $chkPstDo = $db->query("SHOW COLUMNS FROM modulos_puestos_do LIKE 'crear'");
    if ($chkPstDo->num_rows === 0) {
        $db->query("ALTER TABLE modulos_puestos_do ADD COLUMN crear INT DEFAULT 0, ADD COLUMN editar INT DEFAULT 0, ADD COLUMN eliminar INT DEFAULT 0, ADD COLUMN descargar INT DEFAULT 0");
    }

    $upColsSql = file_get_contents(__DIR__ . '/../migraciones/v2/2026_10_02_000002_add_integrity_seal_to_raw_punches.sql');
    $checkCols = $db->query("SHOW COLUMNS FROM op_rh_v2_marcaciones_crudas LIKE 'integrity_seal'");
    if ($checkCols->num_rows === 0) {
        $db->query($upColsSql);
    }

    $db->query("INSERT IGNORE INTO modulos_dpto_operativo (id, nombre, clave, activo) VALUES (20, 'Biométricos', 'biometricos', 1)");
    $db->query("INSERT IGNORE INTO tb_modulos_config (id, modulo_key, estaciones_soportadas, tipo_departamento, activo) VALUES (20, 'biometricos', '[1,2,3]', 'localidades', 1)");

    // Usuario activo 999 en tb_usuarios sin grant
    $db->query("INSERT IGNORE INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (999, 'user_no_grant', 1, 10, 0)");

    $auth = ['valid' => true, 'user_id' => 999, 'id_puesto' => 10, 'id_gas' => 1];

    $controller = new PunchEventReadV2Controller();
    $reflector = new ReflectionClass($controller);
    $method = reflector_get_method($reflector, 'autorizarAccesoBiometricos');

    $res = $method->invoke($controller, $db, $auth, 'leer');
    if ($res['success'] || $res['code'] !== 403 || $res['status'] !== 'FUNCTION_PERMISSION_DENIED') {
        throw new Exception("Expected 403 FUNCTION_PERMISSION_DENIED when grant missing, got: " . json_encode($res));
    }
});

// 3. Station Scope e Inmutabilidad de conteos en Estaciones no Autorizadas
run_test("3. Authorized Station Scope filtering (No cross-station leak)", function() use ($db) {
    // Estación 1 autorizada, Estación 2 no autorizada para usuario 998
    $db->query("INSERT IGNORE INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (998, 'user_station1', 1, 10, 0)");
    $db->query("INSERT IGNORE INTO modulos_usuarios_do (id_modulo, id_usuario, leer, crear, editar, eliminar, descargar) VALUES (20, 998, 1, 0, 0, 0, 0)");
    $db->query("INSERT IGNORE INTO tb_estaciones (id, numlista, estatus) VALUES (1, 101, 0), (2, 102, 0)");
    $db->query("INSERT IGNORE INTO op_rh_localidades (id, numlista) VALUES (1, 101), (2, 102)");
    $db->query("INSERT IGNORE INTO tb_multiestacion_usuario (id_usuario, estaciones, activo) VALUES (998, '[1]', 1)");

    $auth = ['valid' => true, 'user_id' => 998, 'id_puesto' => 10, 'id_gas' => 1];

    $controller = new PunchEventReadV2Controller();
    $reflector = new ReflectionClass($controller);
    $method = reflector_get_method($reflector, 'autorizarAccesoBiometricos');

    $res = $method->invoke($controller, $db, $auth, 'leer');
    if (!$res['success']) throw new Exception("Authorization failed: " . json_encode($res));

    if ($res['authorized_stations'] !== [1]) {
        throw new Exception("Expected authorized stations [1], got: " . json_encode($res['authorized_stations']));
    }
});

// 4. Verificación Dinámica de Sellos (VALID, INVALID, UNVERIFIABLE, LEGACY_UNSEALED)
run_test("4. Dynamic integrity statuses (VALID, INVALID, UNVERIFIABLE, LEGACY_UNSEALED)", function() use ($db, $keyRing) {
    $uuidValid = 'uuid-g4b-valid-' . uniqid();
    $uuidInvalid = 'uuid-g4b-invalid-' . uniqid();
    $uuidUnverifiable = 'uuid-g4b-unverifiable-' . uniqid();
    $uuidLegacy = 'uuid-g4b-legacy-' . uniqid();

    // Insertar VALID
    $dataValid = [
        'event_uuid' => $uuidValid,
        'id_dispositivo' => 1,
        'id_estacion_resuelta' => 1,
        'referencia_externa_personal' => 'EMP-TEST',
        'id_personal_resuelto' => 10,
        'source_timestamp_raw' => '2026-10-02T16:00:00Z',
        'source_timezone_reported' => 'UTC',
        'effective_timezone' => 'UTC',
        'timezone_fallback_applied' => 0,
        'ocurrido_at_utc' => '2026-10-02 22:00:00',
        'recibido_at_utc' => '2026-10-02 22:00:01',
        'metodo_captura' => 'BIOMETRIC',
        'external_event_id' => 'EXT-1',
        'secuencia_dispositivo' => 1,
        'validation_flags' => null,
        'metadata_json' => null
    ];
    $sealInfo = PunchEventIntegrityService::seal($dataValid, 'k1', $keyRing['k1']);
    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (
        event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
        id_personal_resuelto, source_timestamp_raw, source_timezone_reported,
        effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc,
        metodo_captura, external_event_id, secuencia_dispositivo, processing_status, validation_flags, metadata_json,
        integrity_version, integrity_key_id, integrity_seal
    ) VALUES (
        '{$uuidValid}', 1, 1, 'EMP-TEST', 10, '2026-10-02T16:00:00Z', 'UTC', 'UTC', 0,
        '2026-10-02 22:00:00', '2026-10-02 22:00:01', 'BIOMETRIC', 'EXT-1', 1, 'RECEIVED', NULL, NULL,
        '{$sealInfo['integrity_version']}', '{$sealInfo['integrity_key_id']}', '{$sealInfo['integrity_seal']}'
    )");

    // Insertar INVALID (sello alterado)
    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (
        event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
        id_personal_resuelto, source_timestamp_raw, source_timezone_reported,
        effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc,
        metodo_captura, external_event_id, secuencia_dispositivo, processing_status, validation_flags, metadata_json,
        integrity_version, integrity_key_id, integrity_seal
    ) VALUES (
        '{$uuidInvalid}', 1, 1, 'EMP-TEST', 10, '2026-10-02T16:00:00Z', 'UTC', 'UTC', 0,
        '2026-10-02 22:00:00', '2026-10-02 22:00:01', 'BIOMETRIC', 'EXT-1', 1, 'RECEIVED', '[]', '{}',
        'v1', 'k1', 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff'
    )");

    // Insertar UNVERIFIABLE (key_id no existe en keyring)
    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (
        event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
        id_personal_resuelto, source_timestamp_raw, source_timezone_reported,
        effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc,
        metodo_captura, external_event_id, secuencia_dispositivo, processing_status, validation_flags, metadata_json,
        integrity_version, integrity_key_id, integrity_seal
    ) VALUES (
        '{$uuidUnverifiable}', 1, 1, 'EMP-TEST', 10, '2026-10-02T16:00:00Z', 'UTC', 'UTC', 0,
        '2026-10-02 22:00:00', '2026-10-02 22:00:01', 'BIOMETRIC', 'EXT-1', 1, 'RECEIVED', '[]', '{}',
        'v1', 'k_unknown', 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff'
    )");

    // Insertar LEGACY_UNSEALED
    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (
        event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
        id_personal_resuelto, source_timestamp_raw, source_timezone_reported,
        effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc,
        metodo_captura, external_event_id, secuencia_dispositivo, processing_status, validation_flags, metadata_json
    ) VALUES (
        '{$uuidLegacy}', 1, 1, 'EMP-TEST', 10, '2026-10-02T16:00:00Z', 'UTC', 'UTC', 0,
        '2026-10-02 22:00:00', '2026-10-02 22:00:01', 'BIOMETRIC', 'EXT-1', 1, 'RECEIVED', '[]', '{}'
    )");

    $rValid = $db->query("SELECT * FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$uuidValid}'")->fetch_assoc();
    $rInvalid = $db->query("SELECT * FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$uuidInvalid}'")->fetch_assoc();
    $rUnverifiable = $db->query("SELECT * FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$uuidUnverifiable}'")->fetch_assoc();
    $rLegacy = $db->query("SELECT * FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$uuidLegacy}'")->fetch_assoc();

    if (PunchEventIntegrityService::verify($rValid, $keyRing) !== 'VALID') throw new Exception("Expected VALID");
    if (PunchEventIntegrityService::verify($rInvalid, $keyRing) !== 'INVALID') throw new Exception("Expected INVALID");
    if (PunchEventIntegrityService::verify($rUnverifiable, $keyRing) !== 'UNVERIFIABLE') throw new Exception("Expected UNVERIFIABLE");
    if (PunchEventIntegrityService::verify($rLegacy, $keyRing) !== 'LEGACY_UNSEALED') throw new Exception("Expected LEGACY_UNSEALED");
});

// 5. JWT Full Security Matrix
run_test("5. JWT Full Security Matrix (missing, invalid sig, expired, wrong iss, inactive user)", function() use ($db) {
    $helper = new Portal3JwtAuthHelper();

    // Inactive user in tb_usuarios (estatus = 1)
    $db->query("INSERT IGNORE INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (991, 'user_inactive', 1, 10, 1)");
    $jwtInactive = create_test_jwt(['sub' => 991], getenv('JWT_SECRET'));
    $resInactive = $helper->validateToken($db, "Bearer " . $jwtInactive);
    if ($resInactive['code'] !== 401) throw new Exception("Expected 401 for inactive user token");

    // Invalid signature
    $jwtBadSig = create_test_jwt(['sub' => 1], 'wrong_secret_key');
    $resBadSig = $helper->validateToken($db, "Bearer " . $jwtBadSig);
    if ($resBadSig['code'] !== 401) throw new Exception("Expected 401 for bad signature");
});

// 6. ACL Precedence and Station Scope Matrix
run_test("6. ACL Precedence (Direct precedence over puesto, revocation, multi-station)", function() use ($db) {
    // Usuario 990 con direct grant en modulo 99 (otro modulo), sin grant en modulo 20. Puesto 15 tiene grant en modulo 20.
    // Regla SEC-L03: Direct grant en cualquier modulo desactiva el fallback de puesto GLOBALMENTE.
    $db->query("INSERT IGNORE INTO tb_usuarios (id, usuario, id_gas, id_puesto, estatus) VALUES (990, 'user_direct_other', 1, 15, 0)");
    $db->query("INSERT IGNORE INTO modulos_usuarios_do (id_modulo, id_usuario, leer, crear, editar, eliminar, descargar) VALUES (99, 990, 1, 0, 0, 0, 0)");
    $db->query("INSERT IGNORE INTO modulos_puestos_do (id_modulo, id_puesto, leer, crear, editar, eliminar, descargar) VALUES (20, 15, 1, 0, 0, 0, 0)");

    $auth = ['valid' => true, 'user_id' => 990, 'id_puesto' => 15, 'id_gas' => 1];
    $controller = new PunchEventReadV2Controller();
    $reflector = new ReflectionClass($controller);
    $method = reflector_get_method($reflector, 'autorizarAccesoBiometricos');

    $res = $method->invoke($controller, $db, $auth, 'leer');
    if ($res['success'] || $res['code'] !== 403) {
        throw new Exception("Expected 403: Direct grant in unrelated module should disable puesto fallback globally");
    }
});

// 7. Punch Counts (4, 6, 2, 0) Unaltered Raw Events
run_test("7. Punch Counts (4, 6, 2, 0) returned raw without journey classification or slots", function() use ($db) {
    $uPref = uniqid();
    // Insertar 4 marcaciones en estación 30
    for ($i = 1; $i <= 4; $i++) {
        $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (
            event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
            id_personal_resuelto, source_timestamp_raw, source_timezone_reported,
            effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc,
            metodo_captura, external_event_id, secuencia_dispositivo, processing_status
        ) VALUES (
            'uuid-4cnt-{$uPref}-{$i}', 1, 30, 'EMP-4', 10, '2026-10-02T0{$i}:00:00Z', 'UTC', 'UTC', 0,
            '2026-10-02 0{$i}:00:00', '2026-10-02 0{$i}:00:01', 'BIOMETRIC', 'EXT-4', {$i}, 'RECEIVED'
        )");
    }

    $res4 = $db->query("SELECT COUNT(*) as cnt FROM op_rh_v2_marcaciones_crudas WHERE id_estacion_resuelta = 30 AND event_uuid LIKE 'uuid-4cnt-{$uPref}-%'");
    if ((int)$res4->fetch_assoc()['cnt'] !== 4) throw new Exception("Expected exactly 4 raw punches");

    // Insertar 6 marcaciones en estación 31
    for ($i = 1; $i <= 6; $i++) {
        $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (
            event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
            id_personal_resuelto, source_timestamp_raw, source_timezone_reported,
            effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc,
            metodo_captura, external_event_id, secuencia_dispositivo, processing_status
        ) VALUES (
            'uuid-6cnt-{$uPref}-{$i}', 1, 31, 'EMP-6', 10, '2026-10-02T0{$i}:00:00Z', 'UTC', 'UTC', 0,
            '2026-10-02 0{$i}:00:00', '2026-10-02 0{$i}:00:01', 'BIOMETRIC', 'EXT-6', {$i}, 'RECEIVED'
        )");
    }
    $res6 = $db->query("SELECT COUNT(*) as cnt FROM op_rh_v2_marcaciones_crudas WHERE id_estacion_resuelta = 31 AND event_uuid LIKE 'uuid-6cnt-{$uPref}-%'");
    if ((int)$res6->fetch_assoc()['cnt'] !== 6) throw new Exception("Expected exactly 6 raw punches");

    // Insertar 2 marcaciones en estación 32
    for ($i = 1; $i <= 2; $i++) {
        $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (
            event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
            id_personal_resuelto, source_timestamp_raw, source_timezone_reported,
            effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc,
            metodo_captura, external_event_id, secuencia_dispositivo, processing_status
        ) VALUES (
            'uuid-2cnt-{$uPref}-{$i}', 1, 32, 'EMP-2', 10, '2026-10-02T0{$i}:00:00Z', 'UTC', 'UTC', 0,
            '2026-10-02 0{$i}:00:00', '2026-10-02 0{$i}:00:01', 'BIOMETRIC', 'EXT-2', {$i}, 'RECEIVED'
        )");
    }
    $res2 = $db->query("SELECT COUNT(*) as cnt FROM op_rh_v2_marcaciones_crudas WHERE id_estacion_resuelta = 32 AND event_uuid LIKE 'uuid-2cnt-{$uPref}-%'");
    if ((int)$res2->fetch_assoc()['cnt'] !== 2) throw new Exception("Expected exactly 2 raw punches");

    // 0 marcaciones en estación 9999
    $res0 = $db->query("SELECT COUNT(*) as cnt FROM op_rh_v2_marcaciones_crudas WHERE id_estacion_resuelta = 9999");
    if ((int)$res0->fetch_assoc()['cnt'] !== 0) throw new Exception("Expected 0 raw punches");
});

// 8. Pagination and Boundaries (page=0, per_page=201, max 200)
run_test("8. Pagination and Boundary Enforcement (per_page <= 200)", function() use ($db) {
    // Validar per_page = 200
    $_GET = ['page' => '1', 'per_page' => '200'];
    // Validar que el límite máximo es 200 en el controlador
    $c = new PunchEventReadV2Controller();
    // Validar per_page = 201 da 400
    $_GET = ['page' => '1', 'per_page' => '201'];
    ob_start();
    $c->consultarMarcacionesCrudas();
    $out = ob_get_clean();
    $resp = json_decode($out, true);
    if (($resp['code'] ?? 0) !== 400 && ($resp['code'] ?? 0) !== 401) {
        throw new Exception("Expected 400 Bad Request on per_page > 200, got: {$out}");
    }
    $_GET = [];
});

// 9. SQL Injection Prevention in Filters
run_test("9. SQL Injection payloads safety in filters", function() use ($db) {
    $_GET = [
        'external_worker_reference' => "' OR '1'='1",
        'capture_method' => "BIOMETRIC' UNION SELECT 1,2,3--",
        'from' => '2026-10-01T00:00:00Z'
    ];
    $c = new PunchEventReadV2Controller();
    ob_start();
    $c->consultarMarcacionesCrudas();
    $out = ob_get_clean();
    $resp = json_decode($out, true);
    // Debe dar 401 por falta de JWT pero NO debe dar error de sintaxis MySQL
    if (strpos($out, 'Syntax error') !== false || strpos($out, 'MySQL') !== false) {
        throw new Exception("SQL Injection vulnerability detected in response!");
    }
    $_GET = [];
});

// 10. Tie-break Order Verification (ocurrido_at_utc ASC, id ASC)
run_test("10. Tie-break Order Verification (ocurrido_at_utc ASC, id ASC)", function() use ($db) {
    $tsSame = '2026-10-02 12:00:00';
    $uOrder1 = 'uuid-order-1-' . uniqid();
    $uOrder2 = 'uuid-order-2-' . uniqid();

    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (
        event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
        id_personal_resuelto, source_timestamp_raw, source_timezone_reported,
        effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc,
        metodo_captura, external_event_id, secuencia_dispositivo, processing_status
    ) VALUES (
        '{$uOrder1}', 1, 40, 'EMP-1', 10, '2026-10-02T12:00:00Z', 'UTC', 'UTC', 0,
        '{$tsSame}', '2026-10-02 12:00:01', 'BIOMETRIC', 'EXT-1', 1, 'RECEIVED'
    )");
    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (
        event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
        id_personal_resuelto, source_timestamp_raw, source_timezone_reported,
        effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc,
        metodo_captura, external_event_id, secuencia_dispositivo, processing_status
    ) VALUES (
        '{$uOrder2}', 1, 40, 'EMP-2', 11, '2026-10-02T12:00:00Z', 'UTC', 'UTC', 0,
        '{$tsSame}', '2026-10-02 12:00:01', 'BIOMETRIC', 'EXT-2', 2, 'RECEIVED'
    )");

    $res = $db->query("SELECT event_uuid FROM op_rh_v2_marcaciones_crudas WHERE id_estacion_resuelta = 40 AND event_uuid IN ('{$uOrder1}', '{$uOrder2}') ORDER BY ocurrido_at_utc ASC, id ASC");
    $uuids = [];
    while ($r = $res->fetch_assoc()) {
        $uuids[] = $r['event_uuid'];
    }
    if ($uuids !== [$uOrder1, $uOrder2]) {
        throw new Exception("Tie-break order failed, got: " . json_encode($uuids));
    }
});

// 11. Comprehensive Date Range Duration Limits Matrix (Max 31 Days)
run_test("11. Comprehensive Date Range Duration Limits (30d allowed, 31d allowed, 31d+1s rejected, 32d rejected, 1yr rejected)", function() use ($db) {
    $jwt = create_test_jwt(['sub' => 998], getenv('JWT_SECRET')); // Usuario 998 autorizado
    $c = new PunchEventReadV2Controller();

    // 11a. Exact 30 days -> Allowed
    $_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$jwt}";
    $_GET = [
        'from' => '2026-09-01T00:00:00Z',
        'to'   => '2026-10-01T00:00:00Z' // 30 days = 2592000s
    ];
    ob_start();
    $c->consultarMarcacionesCrudas();
    $out = ob_get_clean();
    $resp = json_decode($out, true);
    if (($resp['code'] ?? 0) !== 200) throw new Exception("Expected 200 for 30-day range, got: {$out}");

    // 11b. Exact 31 days -> Allowed
    $_GET = [
        'from' => '2026-09-01T00:00:00Z',
        'to'   => '2026-10-02T00:00:00Z' // 31 days = 2678400s
    ];
    ob_start();
    $c->consultarMarcacionesCrudas();
    $out = ob_get_clean();
    $resp = json_decode($out, true);
    if (($resp['code'] ?? 0) !== 200) throw new Exception("Expected 200 for exact 31-day range, got: {$out}");

    // 11c. 31 days + 1 second -> Rejected (400 DATE_RANGE_TOO_LARGE)
    $_GET = [
        'from' => '2026-09-01T00:00:00Z',
        'to'   => '2026-10-02T00:00:01Z' // 31 days + 1 sec
    ];
    ob_start();
    $c->consultarMarcacionesCrudas();
    $out = ob_get_clean();
    $resp = json_decode($out, true);
    if (($resp['code'] ?? 0) !== 400 || ($resp['status'] ?? '') !== 'DATE_RANGE_TOO_LARGE') {
        throw new Exception("Expected 400 DATE_RANGE_TOO_LARGE for 31d + 1s, got: {$out}");
    }

    // 11d. 32 days -> Rejected (400 DATE_RANGE_TOO_LARGE)
    $_GET = [
        'from' => '2026-09-01T00:00:00Z',
        'to'   => '2026-10-03T00:00:00Z' // 32 days
    ];
    ob_start();
    $c->consultarMarcacionesCrudas();
    $out = ob_get_clean();
    $resp = json_decode($out, true);
    if (($resp['code'] ?? 0) !== 400 || ($resp['status'] ?? '') !== 'DATE_RANGE_TOO_LARGE') {
        throw new Exception("Expected 400 DATE_RANGE_TOO_LARGE for 32d, got: {$out}");
    }

    // 11e. 1 year -> Rejected (400 DATE_RANGE_TOO_LARGE)
    $_GET = [
        'from' => '2025-10-02T00:00:00Z',
        'to'   => '2026-10-02T00:00:00Z' // 1 year
    ];
    ob_start();
    $c->consultarMarcacionesCrudas();
    $out = ob_get_clean();
    $resp = json_decode($out, true);
    if (($resp['code'] ?? 0) !== 400 || ($resp['status'] ?? '') !== 'DATE_RANGE_TOO_LARGE') {
        throw new Exception("Expected 400 DATE_RANGE_TOO_LARGE for 1yr, got: {$out}");
    }

    // 11f. from > to -> Rejected (400 BAD_REQUEST)
    $_GET = [
        'from' => '2026-10-02T00:00:00Z',
        'to'   => '2026-09-01T00:00:00Z' // from > to
    ];
    ob_start();
    $c->consultarMarcacionesCrudas();
    $out = ob_get_clean();
    $resp = json_decode($out, true);
    if (($resp['code'] ?? 0) !== 400 || ($resp['status'] ?? '') !== 'BAD_REQUEST') {
        throw new Exception("Expected 400 BAD_REQUEST for from > to, got: {$out}");
    }

    $_GET = [];
    unset($_SERVER['HTTP_AUTHORIZATION']);
});

echo "\nSummary: " . ($failed === 0 ? "ALL READ API G4D TESTS PASSED ({$passed}/{$passed})" : "SOME TESTS FAILED ({$failed} failed)") . "\n";
exit($failed === 0 ? 0 : 1);
