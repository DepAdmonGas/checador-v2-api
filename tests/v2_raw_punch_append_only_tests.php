<?php
/**
 * Test Suite de Inmutabilidad y Hardening V2.3-G3: DB Triggers & Append-Only Model
 */

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/PunchEventV2.php';
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

function aplicar_triggers_g3($db) {
    $db->query("DROP TRIGGER IF EXISTS `tg_op_rh_v2_marcaciones_crudas_no_update`");
    $db->query("CREATE TRIGGER `tg_op_rh_v2_marcaciones_crudas_no_update`
        BEFORE UPDATE ON `op_rh_v2_marcaciones_crudas`
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. UPDATE operations are strictly prohibited on op_rh_v2_marcaciones_crudas.';
        END");

    $db->query("DROP TRIGGER IF EXISTS `tg_op_rh_v2_marcaciones_crudas_no_delete`");
    $db->query("CREATE TRIGGER `tg_op_rh_v2_marcaciones_crudas_no_delete`
        BEFORE DELETE ON `op_rh_v2_marcaciones_crudas`
        FOR EACH ROW
        BEGIN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. DELETE operations are strictly prohibited on op_rh_v2_marcaciones_crudas.';
        END");
}

function remover_triggers_g3($db) {
    $db->query("DROP TRIGGER IF EXISTS `tg_op_rh_v2_marcaciones_crudas_no_update`");
    $db->query("DROP TRIGGER IF EXISTS `tg_op_rh_v2_marcaciones_crudas_no_delete`");
}

// Asegurar que la migración aditiva de columnas esté presente en la BD de pruebas
$upColsSql = file_get_contents(__DIR__ . '/../migraciones/v2/2026_10_02_000002_add_integrity_seal_to_raw_punches.sql');
$checkCols = $db->query("SHOW COLUMNS FROM op_rh_v2_marcaciones_crudas LIKE 'integrity_seal'");
if ($checkCols->num_rows === 0) {
    $db->query($upColsSql);
}

// Asegurar que los triggers estén aplicados en la BD de pruebas
$checkTriggers = $db->query("SHOW TRIGGERS LIKE 'op_rh_v2_marcaciones_crudas'");
if ($checkTriggers->num_rows === 0) {
    aplicar_triggers_g3($db);
}

$secretSynthetic = 'test_g3_secret_key_2026';
$keyRing = ['k1' => $secretSynthetic];

putenv("RAW_PUNCH_INTEGRITY_MANDATORY=true");
putenv("RAW_PUNCH_ACTIVE_KEY_ID=k1");
putenv("RAW_PUNCH_KEY_RING=" . json_encode($keyRing));

// Helper payload
function make_g3_payload($extra = '') {
    return [
        'event_uuid' => 'uuid-g3-' . uniqid() . $extra,
        'id_dispositivo' => 1,
        'id_estacion_resuelta' => 1,
        'referencia_externa_personal' => 'EMP-G3-01',
        'id_personal_resuelto' => 10,
        'source_timestamp_raw' => '2026-10-02T16:00:00Z',
        'source_timezone_reported' => 'America/Mexico_City',
        'effective_timezone' => 'America/Mexico_City',
        'timezone_fallback_applied' => 0,
        'ocurrido_at_utc' => '2026-10-02 22:00:00',
        'recibido_at_utc' => '2026-10-02 22:00:01',
        'metodo_captura' => 'BIOMETRIC',
        'external_event_id' => 'EXT-G3-001',
        'secuencia_dispositivo' => 500,
        'processing_status' => 'RECEIVED',
        'validation_flags' => [],
        'metadata' => ['g3' => true]
    ];
}

// 1. UPDATE protected field (source_timestamp_raw) is BLOCKED by trigger
run_test("1. UPDATE protected field is BLOCKED by trigger", function() use ($db) {
    $service = new PunchEventV2();
    $payload = make_g3_payload('-upd-prot');
    $service->registrarPunchEvent($db, $payload);
    $uuid = $payload['event_uuid'];

    try {
        $db->query("UPDATE op_rh_v2_marcaciones_crudas SET source_timestamp_raw = '2026-10-02T99:99:99Z' WHERE event_uuid = '{$uuid}'");
        throw new Exception("UPDATE should have been blocked by trigger!");
    } catch (mysqli_sql_exception $e) {
        if (!str_contains($e->getMessage(), 'Hard immutability enforced')) {
            throw new Exception("Unexpected exception message: " . $e->getMessage());
        }
    }
});

// 2. UPDATE non-protected field (processing_status) is BLOCKED by trigger
run_test("2. UPDATE non-protected field (processing_status) is BLOCKED by trigger", function() use ($db) {
    $service = new PunchEventV2();
    $payload = make_g3_payload('-upd-status');
    $service->registrarPunchEvent($db, $payload);
    $uuid = $payload['event_uuid'];

    try {
        $db->query("UPDATE op_rh_v2_marcaciones_crudas SET processing_status = 'PROCESSED' WHERE event_uuid = '{$uuid}'");
        throw new Exception("UPDATE processing_status should have been blocked by trigger!");
    } catch (mysqli_sql_exception $e) {
        if (!str_contains($e->getMessage(), 'Hard immutability enforced')) {
            throw new Exception("Unexpected exception message: " . $e->getMessage());
        }
    }
});

// 3. UPDATE integrity_seal is BLOCKED by trigger
run_test("3. UPDATE integrity_seal is BLOCKED by trigger", function() use ($db) {
    $service = new PunchEventV2();
    $payload = make_g3_payload('-upd-seal');
    $service->registrarPunchEvent($db, $payload);
    $uuid = $payload['event_uuid'];

    try {
        $db->query("UPDATE op_rh_v2_marcaciones_crudas SET integrity_seal = '0000000000000000000000000000000000000000000000000000000000000000' WHERE event_uuid = '{$uuid}'");
        throw new Exception("UPDATE integrity_seal should have been blocked by trigger!");
    } catch (mysqli_sql_exception $e) {
        if (!str_contains($e->getMessage(), 'Hard immutability enforced')) {
            throw new Exception("Unexpected exception message: " . $e->getMessage());
        }
    }
});

// 4. DELETE is BLOCKED by trigger
run_test("4. DELETE operation is BLOCKED by trigger", function() use ($db) {
    $service = new PunchEventV2();
    $payload = make_g3_payload('-del-block');
    $service->registrarPunchEvent($db, $payload);
    $uuid = $payload['event_uuid'];

    try {
        $db->query("DELETE FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$uuid}'");
        throw new Exception("DELETE operation should have been blocked by trigger!");
    } catch (mysqli_sql_exception $e) {
        if (!str_contains($e->getMessage(), 'Hard immutability enforced')) {
            throw new Exception("Unexpected exception message: " . $e->getMessage());
        }
    }

    $row = $db->query("SELECT id FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$uuid}'");
    if ($row->num_rows === 0) {
        throw new Exception("Row was deleted despite trigger!");
    }
});

// 5. INSERT & SELECT allowed
run_test("5. INSERT and SELECT operations function correctly", function() use ($db, $keyRing) {
    $service = new PunchEventV2();
    $payload = make_g3_payload('-ins-sel');
    $res = $service->registrarPunchEvent($db, $payload);
    if ($res['status_code'] !== 201) throw new Exception("Insert failed");

    $uuid = $payload['event_uuid'];
    $row = $db->query("SELECT * FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$uuid}'")->fetch_assoc();
    if (!$row) throw new Exception("Select failed");

    $verify = PunchEventIntegrityService::verify($row, $keyRing);
    if ($verify !== PunchEventIntegrityService::RESULT_VALID) throw new Exception("Sealed row is not VALID");
});

// 6. Legacy row UPDATE and DELETE are BLOCKED by trigger
run_test("6. Legacy row UPDATE and DELETE are BLOCKED by trigger", function() use ($db) {
    $legacyUuid = 'legacy-g3-uuid-' . uniqid();
    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (
        event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
        id_personal_resuelto, source_timestamp_raw, source_timezone_reported,
        effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc,
        metodo_captura, external_event_id, secuencia_dispositivo, processing_status, validation_flags, metadata_json
    ) VALUES (
        '{$legacyUuid}', 1, 1, 'EMP-G3-LEGACY', 10, '2026-10-02T16:00:00Z', 'UTC', 'UTC', 0,
        '2026-10-02 22:00:00', '2026-10-02 22:00:01', 'BIOMETRIC', 'EXT-LEGACY', 1, 'RECEIVED', '[]', '{}'
    )");

    try {
        $db->query("UPDATE op_rh_v2_marcaciones_crudas SET referencia_externa_personal = 'MODIFIED' WHERE event_uuid = '{$legacyUuid}'");
        throw new Exception("Legacy row UPDATE should be blocked!");
    } catch (mysqli_sql_exception $e) {}

    try {
        $db->query("DELETE FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$legacyUuid}'");
        throw new Exception("Legacy row DELETE should be blocked!");
    } catch (mysqli_sql_exception $e) {}
});

// 7. Sealed row remains VALID after failed UPDATE attempt
run_test("7. Sealed row remains VALID after failed UPDATE attempt", function() use ($db, $keyRing) {
    $service = new PunchEventV2();
    $payload = make_g3_payload('-valid-after-fail');
    $service->registrarPunchEvent($db, $payload);
    $uuid = $payload['event_uuid'];

    try {
        $db->query("UPDATE op_rh_v2_marcaciones_crudas SET source_timestamp_raw = 'INVALID' WHERE event_uuid = '{$uuid}'");
    } catch (Exception $e) {}

    $row = $db->query("SELECT * FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$uuid}'")->fetch_assoc();
    $verify = PunchEventIntegrityService::verify($row, $keyRing);
    if ($verify !== PunchEventIntegrityService::RESULT_VALID) {
        throw new Exception("Row integrity changed after failed UPDATE attempt!");
    }
});

// 8. Same UUID same payload still 200 & different payload still 409
run_test("8. Idempotency 200 ALREADY_RECEIVED and 409 CONFLICT work with triggers active", function() use ($db) {
    $service = new PunchEventV2();
    $p = make_g3_payload('-idemp');

    $res1 = $service->registrarPunchEvent($db, $p);
    if ($res1['status_code'] !== 201) throw new Exception("Insert failed");

    $res2 = $service->registrarPunchEvent($db, $p);
    if ($res2['status_code'] !== 200 || $res2['result'] !== 'ALREADY_RECEIVED') {
        throw new Exception("Expected 200 ALREADY_RECEIVED");
    }

    $pDivergent = $p;
    $pDivergent['referencia_externa_personal'] = 'EMP-MUTATED';
    $res3 = $service->registrarPunchEvent($db, $pDivergent);
    if ($res3['status_code'] !== 409 || $res3['result'] !== 'IDEMPOTENCY_CONFLICT') {
        throw new Exception("Expected 409 IDEMPOTENCY_CONFLICT");
    }
});

// 9. 4-punch & 6-punch still work with triggers active
run_test("9. Four-punch and Six-punch work with triggers active", function() use ($db, $keyRing) {
    $service = new PunchEventV2();
    for ($i = 1; $i <= 4; $i++) {
        $p = make_g3_payload("-4p-{$i}");
        $r = $service->registrarPunchEvent($db, $p);
        if ($r['status_code'] !== 201) throw new Exception("4-punch failed");
    }
    for ($i = 1; $i <= 6; $i++) {
        $p = make_g3_payload("-6p-{$i}");
        $r = $service->registrarPunchEvent($db, $p);
        if ($r['status_code'] !== 201) throw new Exception("6-punch failed");
    }
});

// 10. Deliberately mismatched inserted seal verifies INVALID without trigger bypass
run_test("10. Deliberately mismatched inserted seal verifies INVALID without trigger bypass", function() use ($db, $keyRing) {
    $badUuid = 'bad-seal-uuid-' . uniqid();
    $fakeSeal = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (
        event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
        id_personal_resuelto, source_timestamp_raw, source_timezone_reported,
        effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc,
        metodo_captura, external_event_id, secuencia_dispositivo, processing_status, validation_flags, metadata_json,
        integrity_version, integrity_key_id, integrity_seal
    ) VALUES (
        '{$badUuid}', 1, 1, 'EMP-G3-BAD', 10, '2026-10-02T16:00:00Z', 'UTC', 'UTC', 0,
        '2026-10-02 22:00:00', '2026-10-02 22:00:01', 'BIOMETRIC', 'EXT-BAD', 1, 'RECEIVED', '[]', '{}',
        'v1', 'k1', '{$fakeSeal}'
    )");

    $row = $db->query("SELECT * FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$badUuid}'")->fetch_assoc();
    $verify = PunchEventIntegrityService::verify($row, $keyRing);
    if ($verify !== PunchEventIntegrityService::RESULT_INVALID) {
        throw new Exception("Expected INVALID verify result for mismatched seal, got: {$verify}");
    }
});

// 11. Trigger DOWN & REAPPLY UP test
run_test("11. Trigger DOWN and REAPPLY UP test", function() use ($db) {
    // Revertir triggers (DOWN)
    remover_triggers_g3($db);

    $checkDown = $db->query("SHOW TRIGGERS LIKE 'op_rh_v2_marcaciones_crudas'");
    if ($checkDown->num_rows !== 0) {
        throw new Exception("Triggers still exist after DOWN migration!");
    }

    // Reaplicar triggers (UP)
    aplicar_triggers_g3($db);

    $checkUp = $db->query("SHOW TRIGGERS LIKE 'op_rh_v2_marcaciones_crudas'");
    if ($checkUp->num_rows !== 2) {
        throw new Exception("Triggers were not properly reapplied during UP migration!");
    }
});

echo "\nSummary: " . ($failed === 0 ? "ALL G3 APPEND-ONLY HARDENING TESTS PASSED ({$passed}/{$passed})" : "SOME TESTS FAILED ({$failed} failed)") . "\n";
exit($failed === 0 ? 0 : 1);
