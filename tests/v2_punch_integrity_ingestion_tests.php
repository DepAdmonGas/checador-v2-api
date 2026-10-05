<?php
/**
 * Test Suite de Integración V2.3-G2: HMAC Ingestion Integration & Idempotency Conflict Detection
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

$secretSynthetic = 'test_ingestion_secret_key_2026_g2';
$keyRing = ['k1' => $secretSynthetic];

putenv("RAW_PUNCH_INTEGRITY_MANDATORY=true");
putenv("RAW_PUNCH_ACTIVE_KEY_ID=k1");
putenv("RAW_PUNCH_KEY_RING=" . json_encode($keyRing));

$bdObj = new BD();
$db = $bdObj->conectarBD();

// Helper para crear payload valido de ingesta
function make_payload($uuidExtra = '') {
    return [
        'event_uuid' => 'uuid-g2-test-' . uniqid() . $uuidExtra,
        'id_dispositivo' => 1,
        'id_estacion_resuelta' => 1,
        'referencia_externa_personal' => 'EMP-G2-01',
        'id_personal_resuelto' => 10,
        'source_timestamp_raw' => '2026-10-02T15:00:00Z',
        'source_timezone_reported' => 'America/Mexico_City',
        'effective_timezone' => 'America/Mexico_City',
        'timezone_fallback_applied' => 0,
        'ocurrido_at_utc' => '2026-10-02 21:00:00',
        'recibido_at_utc' => '2026-10-02 21:00:01',
        'metodo_captura' => 'BIOMETRIC',
        'external_event_id' => 'EXT-G2-001',
        'secuencia_dispositivo' => 100,
        'processing_status' => 'RECEIVED',
        'validation_flags' => ['TIMEZONE_NORMALIZED'],
        'metadata' => ['firmware' => '2.0.1']
    ];
}

// 1. Mandatory ON + valid config -> 201 ACCEPTED con sello v1
run_test("1. Mandatory ON + valid config -> 201 ACCEPTED con sello v1", function() use ($db, $keyRing) {
    $service = new PunchEventV2();
    $datos = make_payload();

    $res = $service->registrarPunchEvent($db, $datos);
    if ($res['status_code'] !== 201 || $res['result'] !== 'ACCEPTED') {
        throw new Exception("Expected 201 ACCEPTED, got: " . json_encode($res));
    }

    $uuid = $datos['event_uuid'];
    $row = $db->query("SELECT * FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$uuid}'")->fetch_assoc();

    if ($row['integrity_version'] !== 'v1') throw new Exception("Expected integrity_version v1");
    if ($row['integrity_key_id'] !== 'k1') throw new Exception("Expected integrity_key_id k1");
    if (strlen($row['integrity_seal']) !== 64) throw new Exception("Expected 64 hex char seal");

    $v = PunchEventIntegrityService::verify($row, $keyRing);
    if ($v !== PunchEventIntegrityService::RESULT_VALID) throw new Exception("Inserted row verify failed: {$v}");
});

// 2. Missing Active Key / Missing KeyRing -> 500 FAIL CLOSED + No Insert
run_test("2. Missing active key -> 500 FAIL CLOSED + No Insert", function() use ($db) {
    putenv("RAW_PUNCH_ACTIVE_KEY_ID=k_non_existent");

    $service = new PunchEventV2();
    $datos = make_payload('-fail-key');

    $res = $service->registrarPunchEvent($db, $datos);
    if ($res['status_code'] !== 500) {
        throw new Exception("Expected 500 status on missing active key, got: " . json_encode($res));
    }

    $uuid = $datos['event_uuid'];
    $check = $db->query("SELECT id FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$uuid}'");
    if ($check->num_rows > 0) {
        throw new Exception("Failing seal inserted row despite fail-closed policy!");
    }

    // Restore env
    putenv("RAW_PUNCH_ACTIVE_KEY_ID=k1");
});

// 3. Same UUID + Same Payload -> 200 ALREADY_RECEIVED & Preserves original seal
run_test("3. Same UUID + Same Payload -> 200 ALREADY_RECEIVED (preserves seal)", function() use ($db, $keyRing) {
    $service = new PunchEventV2();
    $datos = make_payload('-same-payload');

    $res1 = $service->registrarPunchEvent($db, $datos);
    if ($res1['status_code'] !== 201) throw new Exception("First insert failed");

    $uuid = $datos['event_uuid'];
    $row1 = $db->query("SELECT integrity_seal, recibido_at_utc FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$uuid}'")->fetch_assoc();

    // Simular un reintento del cliente donde recibido_at_utc del servidor difiere
    $datosRetry = $datos;
    $datosRetry['recibido_at_utc'] = '2026-10-02 21:05:00'; // tiempo servidor cambia

    $res2 = $service->registrarPunchEvent($db, $datosRetry);
    if ($res2['status_code'] !== 200 || $res2['result'] !== 'ALREADY_RECEIVED') {
        throw new Exception("Expected 200 ALREADY_RECEIVED on same payload retry, got: " . json_encode($res2));
    }

    $row2 = $db->query("SELECT integrity_seal, recibido_at_utc FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$uuid}'")->fetch_assoc();
    if ($row1['integrity_seal'] !== $row2['integrity_seal']) {
        throw new Exception("Original seal was mutated on retry!");
    }
    if ($row1['recibido_at_utc'] !== $row2['recibido_at_utc']) {
        throw new Exception("recibido_at_utc was mutated on retry!");
    }
});

// 4. Same UUID + Divergent Client Payload (Worker, Timestamp, Timezone, Capture Method, Ext Event ID, Sequence, Metadata) -> 409 CONFLICT
run_test("4. Same UUID + Divergent Client Payload -> 409 CONFLICT", function() use ($db) {
    $service = new PunchEventV2();
    $basePayload = make_payload('-conflict-test');

    $res = $service->registrarPunchEvent($db, $basePayload);
    if ($res['status_code'] !== 201) throw new Exception("Base insert failed");

    $mutations = [
        'referencia_externa_personal' => 'EMP-DIVERGENT',
        'source_timestamp_raw' => '2026-10-02T15:01:00Z',
        'source_timezone_reported' => 'America/Monterrey',
        'metodo_captura' => 'PIN',
        'external_event_id' => 'EXT-DIVERGENT',
        'secuencia_dispositivo' => 999,
        'metadata' => ['firmware' => '9.9.9']
    ];

    foreach ($mutations as $field => $newVal) {
        $divergentPayload = $basePayload;
        $divergentPayload[$field] = $newVal;

        $resConflict = $service->registrarPunchEvent($db, $divergentPayload);
        if ($resConflict['status_code'] !== 409 || $resConflict['result'] !== 'IDEMPOTENCY_CONFLICT') {
            throw new Exception("Expected 409 IDEMPOTENCY_CONFLICT when mutating field [{$field}], got: " . json_encode($resConflict));
        }
    }
});

// 5. Semantic Metadata Equivalence -> 200 ALREADY_RECEIVED (Key order differences do NOT cause 409)
run_test("5. Semantic Metadata Equivalence -> 200 ALREADY_RECEIVED", function() use ($db) {
    $service = new PunchEventV2();
    $p1 = make_payload('-meta-order');
    $p1['metadata'] = ['z' => 10, 'a' => 1];

    $res1 = $service->registrarPunchEvent($db, $p1);
    if ($res1['status_code'] !== 201) throw new Exception("Insert failed");

    $p2 = $p1;
    $p2['metadata'] = ['a' => 1, 'z' => 10]; // Mismo contenido en otro orden de llaves

    $res2 = $service->registrarPunchEvent($db, $p2);
    if ($res2['status_code'] !== 200 || $res2['result'] !== 'ALREADY_RECEIVED') {
        throw new Exception("Semantic metadata key order difference should return 200 ALREADY_RECEIVED, got: " . json_encode($res2));
    }
});

// 6. 4 Punches & 6 Punches in same day -> 4 and 6 independent valid sealed rows
run_test("6. Four Punches and Six Punches same day -> Independent valid sealed rows", function() use ($db, $keyRing) {
    $service = new PunchEventV2();

    // 4 Marcaciones
    for ($i = 1; $i <= 4; $i++) {
        $p = make_payload("-4punch-{$i}");
        $res = $service->registrarPunchEvent($db, $p);
        if ($res['status_code'] !== 201) throw new Exception("4-punch #{$i} failed");

        $row = $db->query("SELECT * FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$p['event_uuid']}'")->fetch_assoc();
        if (PunchEventIntegrityService::verify($row, $keyRing) !== PunchEventIntegrityService::RESULT_VALID) {
            throw new Exception("4-punch #{$i} seal invalid");
        }
    }

    // 6 Marcaciones
    for ($i = 1; $i <= 6; $i++) {
        $p = make_payload("-6punch-{$i}");
        $res = $service->registrarPunchEvent($db, $p);
        if ($res['status_code'] !== 201) throw new Exception("6-punch #{$i} failed");

        $row = $db->query("SELECT * FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$p['event_uuid']}'")->fetch_assoc();
        if (PunchEventIntegrityService::verify($row, $keyRing) !== PunchEventIntegrityService::RESULT_VALID) {
            throw new Exception("6-punch #{$i} seal invalid");
        }
    }
});

// 7. Clock Drift Alert is generated and sealed
run_test("7. CLOCK_DRIFT_ALERT is included in seal calculation", function() use ($db, $keyRing) {
    $service = new PunchEventV2();
    $p = make_payload('-clock-drift');
    $p['validation_flags'] = ['CLOCK_DRIFT_ALERT'];

    $res = $service->registrarPunchEvent($db, $p);
    if ($res['status_code'] !== 201) throw new Exception("Drift insert failed");

    $row = $db->query("SELECT * FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$p['event_uuid']}'")->fetch_assoc();
    if (PunchEventIntegrityService::verify($row, $keyRing) !== PunchEventIntegrityService::RESULT_VALID) {
        throw new Exception("Drift row integrity verification failed");
    }
});

// 8. Legacy Unsealed Retry remains UNSEALED (No backfill)
run_test("8. Legacy Unsealed Retry remains LEGACY_UNSEALED (No Backfill)", function() use ($db) {
    $service = new PunchEventV2();
    $uuid = 'legacy-unsealed-uuid-' . uniqid();

    // Insertar fila legacy pura sin columnas de integridad
    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (
        event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
        id_personal_resuelto, source_timestamp_raw, source_timezone_reported,
        effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc,
        metodo_captura, external_event_id, secuencia_dispositivo, processing_status, validation_flags, metadata_json
    ) VALUES (
        '{$uuid}', 1, 1, 'EMP-G2-01', 10, '2026-10-02T15:00:00Z', 'America/Mexico_City',
        'America/Mexico_City', 0, '2026-10-02 21:00:00', '2026-10-02 21:00:01',
        'BIOMETRIC', 'EXT-G2-001', 100, 'RECEIVED', '[]', '{\"firmware\":\"2.0.1\"}'
    )");

    $p = make_payload();
    $p['event_uuid'] = $uuid;

    $res = $service->registrarPunchEvent($db, $p);
    if ($res['status_code'] !== 200 || $res['result'] !== 'ALREADY_RECEIVED') {
        throw new Exception("Expected 200 ALREADY_RECEIVED on legacy retry");
    }

    $row = $db->query("SELECT integrity_version, integrity_key_id, integrity_seal FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$uuid}'")->fetch_assoc();
    if ($row['integrity_version'] !== null || $row['integrity_key_id'] !== null || $row['integrity_seal'] !== null) {
        throw new Exception("Legacy row was retrospectively sealed during retry!");
    }
});

// 9. Corrupted existing sealed row fails closed on retry (returns 500)
run_test("9. Corrupted existing sealed row fails closed on retry", function() use ($db) {
    $service = new PunchEventV2();
    $p = make_payload('-corrupt-row');
    $badUuid = $p['event_uuid'];

    // Con triggers de inmutabilidad G3 activos, no es posible ejecutar UPDATE sobre filas existentes.
    // Insertamos directamente una fila con datos/sello alterados deliberadamente (Tampered).
    $fakeSeal = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';
    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (
        event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
        id_personal_resuelto, source_timestamp_raw, source_timezone_reported,
        effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc,
        metodo_captura, external_event_id, secuencia_dispositivo, processing_status, validation_flags, metadata_json,
        integrity_version, integrity_key_id, integrity_seal
    ) VALUES (
        '{$badUuid}', 1, 1, 'EMP-G2-01', 10, '2026-10-02T15:00:00Z', 'America/Mexico_City',
        'America/Mexico_City', 0, '2026-10-02 21:00:00', '2026-10-02 21:00:01',
        'BIOMETRIC', 'EXT-G2-001', 100, 'RECEIVED', '[]', '{\"firmware\":\"2.0.1\"}',
        'v1', 'k1', '{$fakeSeal}'
    )");

    $resRetry = $service->registrarPunchEvent($db, $p);
    if ($resRetry['status_code'] !== 500) {
        throw new Exception("Expected 500 FAIL CLOSED on corrupted existing row retry, got: " . json_encode($resRetry));
    }
});

echo "\nSummary: " . ($failed === 0 ? "ALL G2 INGESTION INTEGRATION TESTS PASSED ({$passed}/{$passed})" : "SOME TESTS FAILED ({$failed} failed)") . "\n";
exit($failed === 0 ? 0 : 1);
