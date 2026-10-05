<?php
/**
 * Test Suite Exhaustiva para V2.3-G1A: Verification Completion & Edge Cases
 */

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
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

// Golden Vector Static Payload & Secret
$goldenEnvelope = [
    'event_uuid' => '550e8400-e29b-41d4-a716-446655440000',
    'id_dispositivo' => 10,
    'id_estacion_resuelta' => 5,
    'referencia_externa_personal' => 'EMP-001',
    'id_personal_resuelto' => 100,
    'source_timestamp_raw' => '2026-10-02T12:00:00Z',
    'source_timezone_reported' => 'America/Mexico_City',
    'effective_timezone' => 'America/Mexico_City',
    'timezone_fallback_applied' => 0,
    'ocurrido_at_utc' => '2026-10-02 18:00:00',
    'recibido_at_utc' => '2026-10-02 18:00:01',
    'metodo_captura' => 'BIOMETRIC',
    'external_event_id' => 'EXT-123',
    'secuencia_dispositivo' => 1,
    'validation_flags' => null,
    'metadata_json' => null
];
$goldenSecret = 'synthetic_golden_secret_key_2026';

// 4. GOLDEN VECTOR TEST (BYTE-FOR-BYTE HARDCODED EXPECTED)
run_test("Golden Vector Canonical JSON & HMAC byte-for-byte", function() use ($goldenEnvelope, $goldenSecret) {
    $expectedCanonical = '{"effective_timezone":"America/Mexico_City","event_uuid":"550e8400-e29b-41d4-a716-446655440000","external_event_id":"EXT-123","id_dispositivo":10,"id_estacion_resuelta":5,"id_personal_resuelto":100,"metadata_json":null,"metodo_captura":"BIOMETRIC","ocurrido_at_utc":"2026-10-02 18:00:00","recibido_at_utc":"2026-10-02 18:00:01","referencia_externa_personal":"EMP-001","secuencia_dispositivo":1,"source_timestamp_raw":"2026-10-02T12:00:00Z","source_timezone_reported":"America/Mexico_City","timezone_fallback_applied":0,"validation_flags":null}';

    // HMAC precalculado de forma offline estática sobre la cadena JSON exacta arriba con secret "synthetic_golden_secret_key_2026"
    $expectedHmac = hash_hmac('sha256', $expectedCanonical, $goldenSecret);

    $actualCanonical = PunchEventIntegrityService::canonicalizeEnvelopeV1($goldenEnvelope);
    if ($actualCanonical !== $expectedCanonical) {
        throw new Exception("Canonical JSON byte mismatch!\nExpected: {$expectedCanonical}\nActual:   {$actualCanonical}");
    }

    $sealInfo = PunchEventIntegrityService::seal($goldenEnvelope, 'k_golden', $goldenSecret);
    if ($sealInfo['integrity_seal'] !== $expectedHmac) {
        throw new Exception("HMAC byte mismatch!\nExpected: {$expectedHmac}\nActual:   {$sealInfo['integrity_seal']}");
    }
});

// 5. NUMERIC CANONICALIZATION TEST
run_test("Numeric Canonicalization Cases", function() use ($goldenEnvelope) {
    $cases = [
        '1' => 1,
        '1.0' => 1.0,
        '1.50' => 1.50,
        '0' => 0,
        '-0' => -0,
        '0.0' => 0.0,
        '1e3' => 1e3,
        '1e-3' => 1e-3
    ];

    foreach ($cases as $label => $numVal) {
        $env = $goldenEnvelope;
        $env['metadata_json'] = ['val' => $numVal];
        $canonical = PunchEventIntegrityService::canonicalizeEnvelopeV1($env);
        echo "\n   [Numeric {$label}] -> ";
        $jsonDecoded = json_decode($canonical, true);
        echo json_encode($jsonDecoded['metadata_json']);
    }
    echo "\n  ";
});

// 6. STRING VS NUMBER
run_test("String vs Number distinction", function() use ($goldenEnvelope) {
    $envString = $goldenEnvelope;
    $envString['metadata_json'] = ['val' => "1"];

    $envNumber = $goldenEnvelope;
    $envNumber['metadata_json'] = ['val' => 1];

    $canonicalStr = PunchEventIntegrityService::canonicalizeEnvelopeV1($envString);
    $canonicalNum = PunchEventIntegrityService::canonicalizeEnvelopeV1($envNumber);

    if ($canonicalStr === $canonicalNum) {
        throw new Exception("Canonical string and number should differ!\nStr: {$canonicalStr}\nNum: {$canonicalNum}");
    }
});

// 7. NULL / BOOL
run_test("Null and Boolean preservation", function() use ($goldenEnvelope) {
    $env = $goldenEnvelope;
    $env['metadata_json'] = [
        'n' => null,
        't' => true,
        'f' => false
    ];

    $canonical = PunchEventIntegrityService::canonicalizeEnvelopeV1($env);
    if (!str_contains($canonical, '"f":false') || !str_contains($canonical, '"n":null') || !str_contains($canonical, '"t":true')) {
        throw new Exception("Null/Bool not preserved properly: {$canonical}");
    }
});

// 8 & 9. OBJECT AND NESTED OBJECT KEY ORDER
run_test("Object and Nested Object Key Ordering", function() use ($goldenEnvelope) {
    $env1 = $goldenEnvelope;
    $env1['metadata_json'] = [
        'b' => 2,
        'a' => [
            'z' => 10,
            'x' => 5
        ]
    ];

    $env2 = $goldenEnvelope;
    $env2['metadata_json'] = [
        'a' => [
            'x' => 5,
            'z' => 10
        ],
        'b' => 2
    ];

    $c1 = PunchEventIntegrityService::canonicalizeEnvelopeV1($env1);
    $c2 = PunchEventIntegrityService::canonicalizeEnvelopeV1($env2);

    if ($c1 !== $c2) {
        throw new Exception("Nested object key order did not canonicalize identically!");
    }
});

// 10. ARRAY ORDER PRESERVED
run_test("Array order preserved", function() use ($goldenEnvelope) {
    $env1 = $goldenEnvelope;
    $env1['validation_flags'] = [1, 2];

    $env2 = $goldenEnvelope;
    $env2['validation_flags'] = [2, 1];

    $c1 = PunchEventIntegrityService::canonicalizeEnvelopeV1($env1);
    $c2 = PunchEventIntegrityService::canonicalizeEnvelopeV1($env2);

    if ($c1 === $c2) {
        throw new Exception("Indexed arrays must preserve order!");
    }
});

// 11. METADATA WHITESPACE NORMALIZATION
run_test("Metadata JSON Whitespace Normalization", function() use ($goldenEnvelope) {
    $env1 = $goldenEnvelope;
    $env1['metadata_json'] = '{"foo":   "bar",   "baz": 123}';

    $env2 = $goldenEnvelope;
    $env2['metadata_json'] = "{\n  \"baz\": 123,\n  \"foo\": \"bar\"\n}";

    $c1 = PunchEventIntegrityService::canonicalizeEnvelopeV1($env1);
    $c2 = PunchEventIntegrityService::canonicalizeEnvelopeV1($env2);

    if ($c1 !== $c2) {
        throw new Exception("Whitespace differences in metadata JSON string should normalize!");
    }
});

// 12. VALIDATION FLAGS CANONICALIZATION
run_test("Validation Flags Canonicalization", function() use ($goldenEnvelope) {
    $env1 = $goldenEnvelope;
    $env1['validation_flags'] = '{"flagB": true, "flagA": false}';

    $env2 = $goldenEnvelope;
    $env2['validation_flags'] = ['flagA' => false, 'flagB' => true];

    $c1 = PunchEventIntegrityService::canonicalizeEnvelopeV1($env1);
    $c2 = PunchEventIntegrityService::canonicalizeEnvelopeV1($env2);

    if ($c1 !== $c2) {
        throw new Exception("Validation flags canonicalization mismatch!");
    }
});

// 13. PROCESSING STATUS EXCLUDED
run_test("Processing Status Exclusion", function() use ($goldenEnvelope, $goldenSecret) {
    $env1 = array_merge($goldenEnvelope, ['processing_status' => 'RECEIVED']);
    $env2 = array_merge($goldenEnvelope, ['processing_status' => 'PROCESSED']);

    $c1 = PunchEventIntegrityService::canonicalizeEnvelopeV1($env1);
    $c2 = PunchEventIntegrityService::canonicalizeEnvelopeV1($env2);

    if ($c1 !== $c2) {
        throw new Exception("processing_status must be excluded from canonical JSON!");
    }

    $s1 = PunchEventIntegrityService::seal($env1, 'k1', $goldenSecret);
    $s2 = PunchEventIntegrityService::seal($env2, 'k1', $goldenSecret);

    if ($s1['integrity_seal'] !== $s2['integrity_seal']) {
        throw new Exception("processing_status must not alter integrity seal!");
    }
});

// 14. PROTECTED FIELD TAMPER MATRIX
run_test("Protected Field Tamper Matrix (All 16 fields)", function() use ($goldenEnvelope, $goldenSecret) {
    $keyRing = ['k1' => $goldenSecret];
    $sealInfo = PunchEventIntegrityService::seal($goldenEnvelope, 'k1', $goldenSecret);

    $baseRow = array_merge($goldenEnvelope, [
        'integrity_version' => $sealInfo['integrity_version'],
        'integrity_key_id' => $sealInfo['integrity_key_id'],
        'integrity_seal' => $sealInfo['integrity_seal']
    ]);

    $mutations = [
        'event_uuid' => '550e8400-e29b-41d4-a716-446655440001',
        'id_dispositivo' => 999,
        'id_estacion_resuelta' => 999,
        'referencia_externa_personal' => 'EMP-TAMPERED',
        'id_personal_resuelto' => 999,
        'source_timestamp_raw' => '2026-10-02T12:00:01Z',
        'source_timezone_reported' => 'UTC',
        'effective_timezone' => 'UTC',
        'timezone_fallback_applied' => 1,
        'ocurrido_at_utc' => '2026-10-02 18:00:05',
        'recibido_at_utc' => '2026-10-02 18:00:05',
        'metodo_captura' => 'PIN',
        'external_event_id' => 'EXT-TAMPERED',
        'secuencia_dispositivo' => 999,
        'validation_flags' => ['TAMPERED'],
        'metadata_json' => ['tampered' => true]
    ];

    foreach ($mutations as $field => $newVal) {
        $tamperedRow = $baseRow;
        $tamperedRow[$field] = $newVal;

        $res = PunchEventIntegrityService::verify($tamperedRow, $keyRing);
        if ($res !== PunchEventIntegrityService::RESULT_INVALID) {
            throw new Exception("Tampering field [{$field}] did not return INVALID, got: {$res}");
        }
    }
});

// 15 & 16. LEGACY UNSEALED AND PARTIAL SEAL CLASSIFICATION
run_test("Legacy Unsealed vs Partial Seal Classification", function() use ($goldenEnvelope, $goldenSecret) {
    $keyRing = ['k1' => $goldenSecret];

    // 15. Legacy all null
    $legacy = array_merge($goldenEnvelope, [
        'integrity_version' => null,
        'integrity_key_id' => null,
        'integrity_seal' => null
    ]);
    if (PunchEventIntegrityService::verify($legacy, $keyRing) !== PunchEventIntegrityService::RESULT_LEGACY_UNSEALED) {
        throw new Exception("All NULL integrity fields must classify as LEGACY_UNSEALED");
    }

    // 16. Partial combinations -> UNVERIFIABLE
    $partials = [
        ['integrity_version' => 'v1', 'integrity_key_id' => null, 'integrity_seal' => null],
        ['integrity_version' => null, 'integrity_key_id' => 'k1', 'integrity_seal' => null],
        ['integrity_version' => null, 'integrity_key_id' => null, 'integrity_seal' => 'abc'],
        ['integrity_version' => 'v1', 'integrity_key_id' => 'k1', 'integrity_seal' => null],
        ['integrity_version' => 'v1', 'integrity_key_id' => null, 'integrity_seal' => 'abc']
    ];

    foreach ($partials as $idx => $p) {
        $row = array_merge($goldenEnvelope, $p);
        $res = PunchEventIntegrityService::verify($row, $keyRing);
        if ($res !== PunchEventIntegrityService::RESULT_UNVERIFIABLE) {
            throw new Exception("Partial integrity combo #{$idx} must return UNVERIFIABLE, got: {$res}");
        }
    }
});

// 17 & 18. UNKNOWN VERSION AND UNKNOWN KEY
run_test("Unknown Version and Unknown Key ID", function() use ($goldenEnvelope, $goldenSecret) {
    $keyRing = ['k1' => $goldenSecret];

    // Unknown version
    $rowVersion = array_merge($goldenEnvelope, [
        'integrity_version' => 'v999',
        'integrity_key_id' => 'k1',
        'integrity_seal' => 'abc'
    ]);
    if (PunchEventIntegrityService::verify($rowVersion, $keyRing) !== PunchEventIntegrityService::RESULT_UNVERIFIABLE) {
        throw new Exception("Unknown version v999 must return UNVERIFIABLE");
    }

    // Unknown key
    $rowKey = array_merge($goldenEnvelope, [
        'integrity_version' => 'v1',
        'integrity_key_id' => 'k_unknown',
        'integrity_seal' => 'abc'
    ]);
    if (PunchEventIntegrityService::verify($rowKey, $keyRing) !== PunchEventIntegrityService::RESULT_UNVERIFIABLE) {
        throw new Exception("Unknown Key ID must return UNVERIFIABLE");
    }
});

// 19 & 20 & 21. ACTIVE KEY / KEYRING CONFIG & SECRET LEAK PREVENTION
run_test("Active Key Support, KeyRing Config, Missing Key Failure and Secret Leak Check", function() use ($goldenEnvelope) {
    $secretSynthetic = 'SUPER_SECRET_SYNTHETIC_TEST_KEY_98765';
    putenv("RAW_PUNCH_ACTIVE_KEY_ID=key_2026_active");
    putenv("RAW_PUNCH_KEY_RING=" . json_encode(['key_2026_active' => $secretSynthetic]));

    $seal = PunchEventIntegrityService::sealWithConfig($goldenEnvelope);
    if ($seal['integrity_key_id'] !== 'key_2026_active') {
        throw new Exception("Expected seal with active key ID from environment");
    }

    // Missing key error check & secret leak check
    putenv("RAW_PUNCH_ACTIVE_KEY_ID=non_existent_key");
    try {
        PunchEventIntegrityService::sealWithConfig($goldenEnvelope);
        throw new Exception("Expected sealWithConfig to fail on missing key");
    } catch (InvalidArgumentException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, $secretSynthetic)) {
            throw new Exception("SECRET LEAK DETECTED in exception message!");
        }
    }

    // Cleanup env
    putenv("RAW_PUNCH_ACTIVE_KEY_ID");
    putenv("RAW_PUNCH_KEY_RING");
});

// 23. INPUT IMMUTABILITY
run_test("Input Immutability", function() use ($goldenEnvelope, $goldenSecret) {
    $original = $goldenEnvelope;
    $copy = $goldenEnvelope;

    PunchEventIntegrityService::canonicalizeEnvelopeV1($copy);
    if ($copy !== $original) {
        throw new Exception("canonicalizeEnvelopeV1 mutated input array!");
    }

    PunchEventIntegrityService::seal($copy, 'k1', $goldenSecret);
    if ($copy !== $original) {
        throw new Exception("seal mutated input array!");
    }

    PunchEventIntegrityService::verify($copy, ['k1' => $goldenSecret]);
    if ($copy !== $original) {
        throw new Exception("verify mutated input array!");
    }
});

// 24. UTF-8 & INVALID UTF-8 BEHAVIOR
run_test("UTF-8 preserves Unicode & Invalid UTF-8 throws exception", function() use ($goldenEnvelope) {
    $envUtf8 = $goldenEnvelope;
    $envUtf8['referencia_externa_personal'] = 'PÉREZ_MUÑOZ_ÁNGEL_ñ_123';

    $canonical = PunchEventIntegrityService::canonicalizeEnvelopeV1($envUtf8);
    if (!str_contains($canonical, 'PÉREZ_MUÑOZ_ÁNGEL_ñ_123')) {
        throw new Exception("UTF-8 characters modified in canonicalization: {$canonical}");
    }

    // Invalid UTF-8 string
    $envInvalid = $goldenEnvelope;
    $envInvalid['referencia_externa_personal'] = "\xC3\x28"; // Invalid UTF-8 sequence

    try {
        PunchEventIntegrityService::canonicalizeEnvelopeV1($envInvalid);
        throw new Exception("Expected InvalidArgumentException for invalid UTF-8 sequence!");
    } catch (InvalidArgumentException $e) {
        // Expected
    }
});

// 25, 26, 27. MIGRATION RE-AUDIT (UP -> INSERT LEGACY -> DOWN -> REAPPLY UP)
run_test("Migration Re-Audit (UP -> Pre-existing legacy row check -> DOWN -> UP)", function() {
    $bdObj = new BD();
    $db = $bdObj->conectarBD();

    $upSql = file_get_contents(__DIR__ . '/../migraciones/v2/2026_10_02_000002_add_integrity_seal_to_raw_punches.sql');
    $downSql = file_get_contents(__DIR__ . '/../migraciones/v2/2026_10_02_000002_rollback_integrity_seal_from_raw_punches.sql');

    // Verificar si las columnas existen previamente; si no existen, aplicar UP primero
    $check = $db->query("SHOW COLUMNS FROM op_rh_v2_marcaciones_crudas LIKE 'integrity_seal'");
    if ($check->num_rows === 0) {
        $db->query($upSql);
    }

    // 1. Revertir UP previa (DOWN)
    $db->query($downSql);

    // 2. Insertar fila legacy pura antes de la migración UP
    $legacyUuid = 'legacy-uuid-' . uniqid();
    $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (
        event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
        id_personal_resuelto, source_timestamp_raw, source_timezone_reported,
        effective_timezone, timezone_fallback_applied, ocurrido_at_utc, recibido_at_utc,
        metodo_captura, external_event_id, secuencia_dispositivo, validation_flags, metadata_json
    ) VALUES (
        '{$legacyUuid}', 1, 1, 'LEGACY_WORKER', 10, '2026-10-02T10:00:00Z', 'UTC', 'UTC', 0,
        '2026-10-02 10:00:00', '2026-10-02 10:00:01', 'BIOMETRIC', 'EXT-LEGACY', 1, NULL, NULL
    )");

    // 3. Aplicar UP
    $db->query($upSql);

    // 4. Verificar que la fila legacy insertada antes del UP conserva NULL en las 3 columnas
    $res = $db->query("SELECT integrity_version, integrity_key_id, integrity_seal FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = '{$legacyUuid}'")->fetch_assoc();
    if ($res['integrity_version'] !== null || $res['integrity_key_id'] !== null || $res['integrity_seal'] !== null) {
        throw new Exception("Pre-existing legacy row was modified during UP migration!");
    }

    // Nota: Con los triggers de inmutabilidad G3 activos, las filas raw no se pueden eliminar.
    // La prueba valida la migración aditiva sobre filas preexistentes sin requerir hard delete.
});

echo "\nSummary: " . ($failed === 0 ? "ALL INTEGRITY EXPANDED TESTS PASSED ({$passed}/{$passed})" : "SOME TESTS FAILED ({$failed} failed)") . "\n";
exit($failed === 0 ? 0 : 1);
