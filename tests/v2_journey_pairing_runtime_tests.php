<?php
/**
 * Checador V2 — V2.4-P1 Journey Pairing Runtime Test Suite
 */

declare(strict_types=1);

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/journey/JourneyPairingService.php';

$bdObj = new BD();
$conexion = $bdObj->conectarBD();

function test_log(string $msg): void {
    echo $msg . "\n";
}

function run_sql_script(mysqli $db, string $filePath): void {
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException("Could not read SQL file: $filePath");
    }

    $sqlNoComments = preg_replace('/--.*$/m', '', $sql);

    if (strpos($sqlNoComments, 'DELIMITER $$') !== false) {
        $parts = explode('DELIMITER $$', $sqlNoComments);
        $standardPart = $parts[0];
        $delimiterPart = $parts[1] ?? '';

        $stdQueries = array_filter(array_map('trim', explode(';', $standardPart)));
        foreach ($stdQueries as $q) {
            if (!empty($q) && !$db->query($q)) {
                throw new RuntimeException("SQL execution error: " . $db->error . "\nQuery: " . $q);
            }
        }

        $delimiterPart = str_replace('DELIMITER ;', '', $delimiterPart);
        $delimQueries = array_filter(array_map('trim', explode('$$', $delimiterPart)));
        foreach ($delimQueries as $q) {
            if (!empty($q) && !$db->query($q)) {
                throw new RuntimeException("Trigger SQL execution error: " . $db->error . "\nQuery: " . $q);
            }
        }
    } else {
        $queries = array_filter(array_map('trim', explode(';', $sqlNoComments)));
        foreach ($queries as $query) {
            if (!empty($query) && !$db->query($query)) {
                throw new RuntimeException("SQL execution error: " . $db->error . "\nQuery: " . $query);
            }
        }
    }
}

// Reset tables to clean test state
$rawPunchUp = __DIR__ . '/../migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql';
$l2Up = __DIR__ . '/../migraciones/v2/2026_10_05_000002_create_v2_level2_server_tables.sql';
$shiftUp = __DIR__ . '/../migraciones/v2/2026_10_06_000001_create_v2_shift_scheduling_tables.sql';
$pairingUp = __DIR__ . '/../migraciones/v2/2026_10_07_000001_create_v2_journey_pairing_tables.sql';
$pairingDown = __DIR__ . '/../migraciones/v2/2026_10_07_000001_rollback_v2_journey_pairing_tables.sql';

test_log("=== Starting V2.4-P1 Journey Pairing Runtime Test Suite ===");

$conexion->query("SET FOREIGN_KEY_CHECKS = 0");
try { run_sql_script($conexion, $pairingDown); } catch (Throwable $e) {}
run_sql_script($conexion, $rawPunchUp);
run_sql_script($conexion, $l2Up);
run_sql_script($conexion, $shiftUp);
run_sql_script($conexion, $pairingUp);
$conexion->query("TRUNCATE TABLE op_rh_v2_marcaciones_crudas");
$conexion->query("SET FOREIGN_KEY_CHECKS = 1");

$service = new JourneyPairingService($conexion);

$passCount = 0;
$failCount = 0;

function assert_test(bool $condition, string $testName, string $failureDetails = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        test_log("[PASS] $testName");
    } else {
        $failCount++;
        test_log("[FAIL] $testName" . ($failureDetails ? " -> $failureDetails" : ''));
    }
}

// Seed separate canonical locality and physical station identities, as production does.
function seed_workplace_identity(mysqli $db): array {
    static $localityPaddingInserted = false;
    if (!$localityPaddingInserted) {
        $paddingNumlista = random_int(900000, 1900000000);
        $padding = $db->prepare('INSERT INTO op_rh_localidades (numlista, localidad, recuperacion_vapores) VALUES (?, ?, ?)');
        $paddingLabel = 'Synthetic unrelated locality ID-space padding';
        $paddingEmpty = '';
        $padding->bind_param('iss', $paddingNumlista, $paddingLabel, $paddingEmpty);
        $padding->execute();
        $localityPaddingInserted = true;
    }

    $numlista = random_int(900000, 1900000000);
    $db->query("INSERT INTO tb_estaciones
        (numlista,nombre,es,permisocre,razonsocial,rfc,direccioncompleta,di_estado,di_municipio,
         apoderado_legal,firma,politica,mision,vision,franquicia,producto_uno,producto_dos,
         producto_tres,sasisopa,fecha_autorizacion,organigrama,volumetrico,noregistro_generador,
         categoria,latitud,longitud,distmax,ubicacion,estatus)
        VALUES ({$numlista},'Synthetic P1 station','','','','','','','',
         '','','','','','','','','','','2000-01-01','','','','',0,0,0,0,0)");
    $physicalStationId = (int) $db->insert_id;
    $locality = $db->prepare('INSERT INTO op_rh_localidades (numlista, localidad, recuperacion_vapores) VALUES (?, ?, ?)');
    $label = 'Synthetic P1 locality';
    $empty = '';
    $locality->bind_param('iss', $numlista, $label, $empty);
    $locality->execute();
    return ['locality_id' => (int) $db->insert_id, 'physical_station_id' => $physicalStationId];
}

// Helper to seed a test shift and punches
function seed_shift_with_punches(mysqli $db, string $tipoJornada = 'SCHEDULED', array $punchOffsets = [], bool $isNightShift = false, bool $isMultiSegment = false, bool $includeVerifiedLevel2Fixture = true): array {
    $workerId = rand(2000, 9999);
    $workplace = seed_workplace_identity($db);
    $stationId = $workplace['locality_id'];
    $physicalStationId = $workplace['physical_station_id'];
    $workDate = '2026-10-07';

    $startUtc = $isNightShift ? "$workDate 22:00:00" : "$workDate 08:00:00";
    $endUtc = $isNightShift ? "2026-10-08 06:00:00" : "$workDate 17:00:00";

    $segmentsJson = null;
    if ($isMultiSegment) {
        $segmentsJson = json_encode([
            ['ordinal' => 1, 'tipo_segmento' => 'WORK', 'scheduled_start_utc' => "$workDate 08:00:00", 'scheduled_end_utc' => "$workDate 12:00:00"],
            ['ordinal' => 2, 'tipo_segmento' => 'WORK', 'scheduled_start_utc' => "$workDate 13:00:00", 'scheduled_end_utc' => "$workDate 17:00:00"]
        ]);
    }

    $db->query("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, tipo_jornada, scheduled_start_utc, scheduled_end_utc, payload_segmentos_json) VALUES ($workerId, $stationId, '$workDate', '$tipoJornada', '$startUtc', '$endUtc', " . ($segmentsJson ? "'$segmentsJson'" : "NULL") . ")");
    $shiftId = (int)$db->insert_id;

    // P1 algorithm cases use an explicit synthetic VERIFIED evaluation fixture.
    // The dedicated missing-evaluation case below opts out to verify fail-closed behavior.
    if ($includeVerifiedLevel2Fixture) {
        $evalRef = 'EVAL_TEST_VERIFIED_' . uniqid();
        $evalStart = "$workDate 00:00:00";
        $evalEnd = "$workDate 23:59:59";
        $stmt = $db->prepare("INSERT INTO op_rh_v2_level2_window_evaluations
            (evaluation_ref, id_estacion, window_from_utc, window_to_utc, coverage_policy_ref,
             relevant_device_generations_json, delivery_completeness, operational_coverage,
             time_window_attribution, window_level2_status, evaluated_at_utc, manifest_digest,
             canonical_evidence_json)
            VALUES (?, ?, ?, ?, 'POLICY_V1.0_DEFAULT', '[]', 'VERIFIED', 'VERIFIED',
                    'VERIFIED', 'VERIFIED', NOW(), 'TEST_FIXTURE_DIGEST', '{}')");
        $stmt->bind_param('siss', $evalRef, $physicalStationId, $evalStart, $evalEnd);
        $stmt->execute();
        $stmt->close();
    }

    $punchIds = [];
    foreach ($punchOffsets as $idx => $offsetInfo) {
        $offsetSeconds = $offsetInfo['offset'] ?? 0;
        $punchStation = $offsetInfo['station_id'] ?? $physicalStationId;
        $flags = $offsetInfo['flags'] ?? null;
        $baseTs = $isNightShift && $offsetSeconds > 36000 ? strtotime("$workDate 22:00:00") : strtotime($startUtc);
        $pTime = date('Y-m-d H:i:s', $baseTs + $offsetSeconds);
        $uuid = 'PUNCH_' . $shiftId . '_' . $idx . '_' . uniqid();

        $flagsSql = $flags ? "'$flags'" : "NULL";
        $db->query("INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid, id_dispositivo, id_estacion_resuelta, id_personal_resuelto, referencia_externa_personal, source_timestamp_raw, ocurrido_at_utc, recibido_at_utc, validation_flags) VALUES ('$uuid', 1, $punchStation, $workerId, 'EXT_$workerId', '$pTime', '$pTime', NOW(), $flagsSql)");
        $punchIds[] = (int)$db->insert_id;
    }

    return [
        'shiftId' => $shiftId,
        'workerId' => $workerId,
        'stationId' => $stationId,
        'physicalStationId' => $physicalStationId,
        'workDate' => $workDate,
        'punchIds' => $punchIds
    ];
}

// P01: Normal single-segment exact pair
$s1 = seed_shift_with_punches($conexion, 'SCHEDULED', [['offset' => 0], ['offset' => 32400]]); // IN at 08:00, OUT at 17:00
$res1 = $service->pairScheduledShift($s1['shiftId']);
assert_test($s1['stationId'] !== $s1['physicalStationId'] && $res1['pairing_status'] === 'EXACT_PAIR' && $res1['completeness_l2'] === 'VERIFIED' && count($res1['segments']) === 1, "P01: Explicit verified Level 2 evaluation permits EXACT_PAIR at a canonical locality");

// P01-A: Missing authoritative Level 2 evaluation is not proof of completeness.
$s1a = seed_shift_with_punches($conexion, 'SCHEDULED', [['offset' => 0], ['offset' => 32400]], false, false, false);
$res1a = $service->pairScheduledShift($s1a['shiftId']);
assert_test($res1a['completeness_l2'] === 'PENDING_SYNC_COMPLETION' && $res1a['pairing_status'] === 'PENDING_SYNC_COMPLETION' && count($res1a['segments'][0]['associated_punches']) === 2, "P01-A: Missing Level 2 evaluation preserves pair observations but remains non-final");

// P02: Missing entry (PARTIAL_MISSING_IN)
$s2 = seed_shift_with_punches($conexion, 'SCHEDULED', [['offset' => 32400]]); // Only OUT punch
$res2 = $service->pairScheduledShift($s2['shiftId']);
assert_test($res2['pairing_status'] === 'PARTIAL_MISSING_IN', "P02: Missing entry punch produces PARTIAL_MISSING_IN");

// P03: Missing exit (PARTIAL_MISSING_OUT)
$s3 = seed_shift_with_punches($conexion, 'SCHEDULED', [['offset' => 0]]); // Only IN punch
$res3 = $service->pairScheduledShift($s3['shiftId']);
assert_test($res3['pairing_status'] === 'PARTIAL_MISSING_OUT', "P03: Missing exit punch produces PARTIAL_MISSING_OUT");

// P04: No punches + complete source (UNMATCHED_NO_PUNCHES)
$s4 = seed_shift_with_punches($conexion, 'SCHEDULED', []); // No punches
$res4 = $service->pairScheduledShift($s4['shiftId']);
assert_test($res4['pairing_status'] === 'UNMATCHED_NO_PUNCHES', "P04: No candidate punches with verified source produces UNMATCHED_NO_PUNCHES");

// P05: No punches + Level2 incomplete (PENDING_SYNC_COMPLETION)
$s5 = seed_shift_with_punches($conexion, 'SCHEDULED', []);
$evalRef5 = 'EVAL_TEST_' . uniqid();
$conexion->query("INSERT INTO op_rh_v2_level2_window_evaluations (evaluation_ref, id_estacion, window_from_utc, window_to_utc, coverage_policy_ref, relevant_device_generations_json, delivery_completeness, operational_coverage, time_window_attribution, window_level2_status, evaluated_at_utc, manifest_digest, canonical_evidence_json) VALUES ('$evalRef5', {$s5['physicalStationId']}, '{$s5['workDate']} 00:00:00', '{$s5['workDate']} 23:59:59', 'POLICY_V1.0_DEFAULT', '[]', 'INCOMPLETE', 'INCOMPLETE', 'DEGRADED', 'INCOMPLETE', NOW(), 'DIGEST_1', '{}')");
$res5 = $service->pairScheduledShift($s5['shiftId']);
assert_test($res5['pairing_status'] === 'PENDING_SYNC_COMPLETION', "P05: Un-synced Level 2 station window produces PENDING_SYNC_COMPLETION");

// P06: UNKNOWN aggregate Level 2 evidence is non-final under the frozen API contract.
$s6 = seed_shift_with_punches($conexion, 'SCHEDULED', [['offset' => 0], ['offset' => 32400]]);
$evalRef6 = 'EVAL_TEST_UNKNOWN_' . uniqid();
$conexion->query("INSERT INTO op_rh_v2_level2_window_evaluations (evaluation_ref, id_estacion, window_from_utc, window_to_utc, coverage_policy_ref, relevant_device_generations_json, delivery_completeness, operational_coverage, time_window_attribution, window_level2_status, evaluated_at_utc, manifest_digest, canonical_evidence_json) VALUES ('$evalRef6', {$s6['physicalStationId']}, '{$s6['workDate']} 00:00:00', '{$s6['workDate']} 23:59:59', 'POLICY_V1.0_DEFAULT', '[]', 'VERIFIED', 'UNKNOWN', 'VERIFIED', 'UNKNOWN', NOW(), 'DIGEST_UNKNOWN', '{}')");
$res6 = $service->pairScheduledShift($s6['shiftId']);
assert_test($res6['completeness_l2'] === 'PENDING_SYNC_COMPLETION' && $res6['pairing_status'] === 'PENDING_SYNC_COMPLETION', "P06: Aggregate UNKNOWN remains non-final even when another Level 2 dimension is VERIFIED");

// P07: 3 punches -> surplus preserved
$s7 = seed_shift_with_punches($conexion, 'SCHEDULED', [['offset' => 0], ['offset' => 14400], ['offset' => 32400]]); // 08:00, 12:00, 17:00
$res7 = $service->pairScheduledShift($s7['shiftId']);
assert_test($res7['pairing_status'] === 'EXACT_PAIR' && count($res7['segments'][0]['associated_punches']) === 3, "P07: Intermediate 3rd punch preserved cleanly as SURPLUS");

// P08: 5+ punches preserved without arbitrary slot cap
$s8 = seed_shift_with_punches($conexion, 'SCHEDULED', [['offset' => 0], ['offset' => 3600], ['offset' => 7200], ['offset' => 10800], ['offset' => 32400]]);
$res8 = $service->pairScheduledShift($s8['shiftId']);
assert_test(count($res8['segments'][0]['associated_punches']) === 5, "P08: 5+ candidate punches preserved without 4-slot cap");

// P09: Multi-segment normal pairing
$s9 = seed_shift_with_punches($conexion, 'SCHEDULED', [['offset' => 0], ['offset' => 14400], ['offset' => 18000], ['offset' => 32400]], false, true);
$res9 = $service->pairScheduledShift($s9['shiftId']);
assert_test(count($res9['segments']) === 2 && $res9['pairing_status'] === 'EXACT_PAIR', "P09: Multi-segment shift paired cleanly into 2 exact segment pairs");

// P12: Night shift across midnight boundary
$s12 = seed_shift_with_punches($conexion, 'SCHEDULED', [['offset' => 0], ['offset' => 28800]], true); // 22:00 and 06:00 next day
$res12 = $service->pairScheduledShift($s12['shiftId']);
assert_test($res12['pairing_status'] === 'EXACT_PAIR' && $res12['work_date'] === '2026-10-07', "P12: Night shift post-midnight exit punch matched to start work_date");

// P15 & P28: Identical re-run returns existing execution (Idempotent reuse)
$res28a = $service->pairScheduledShift($s1['shiftId']);
$res28b = $service->pairScheduledShift($s1['shiftId']);
assert_test($res28a['pairing_id'] === $res28b['pairing_id'] && ($res28b['is_idempotent_reuse'] ?? false) === true, "P15 & P28: Identical re-run is idempotent and reuses existing execution record without duplicate revision");

// P23: Workplace mismatch fail-closed
$s23 = seed_shift_with_punches($conexion, 'SCHEDULED', [['offset' => 0, 'station_id' => 99999], ['offset' => 32400]]);
$res23 = $service->pairScheduledShift($s23['shiftId']);
assert_test($res23['pairing_status'] === 'WORKPLACE_MISMATCH', "P23: Punch event at unauthorized station produces WORKPLACE_MISMATCH");

// P24: INVALID validation flag fail-closed
$s24 = seed_shift_with_punches($conexion, 'SCHEDULED', [['offset' => 0, 'flags' => 'INVALID_HMAC'], ['offset' => 32400]]);
$res24 = $service->pairScheduledShift($s24['shiftId']);
assert_test($res24['pairing_status'] === 'AMBIGUOUS_PAIRING', "P24: Punch event with INVALID validation flag produces fail-closed AMBIGUOUS_PAIRING");

// P29: Late event delivery creates append-only revision N+1
$latePunchUuid = 'LATE_' . uniqid();
$conexion->query("INSERT INTO op_rh_v2_marcaciones_crudas (event_uuid, id_dispositivo, id_estacion_resuelta, id_personal_resuelto, referencia_externa_personal, source_timestamp_raw, ocurrido_at_utc, recibido_at_utc) VALUES ('$latePunchUuid', 1, {$s3['physicalStationId']}, {$s3['workerId']}, 'EXT_{$s3['workerId']}', '2026-10-07 17:02:00', '2026-10-07 17:02:00', NOW())");
$res29 = $service->pairScheduledShift($s3['shiftId']);
assert_test($res29['revision_numero'] === 2 && $res29['pairing_status'] === 'EXACT_PAIR', "P29: Late punch arrival creates new append-only revision 2 transitioning status to EXACT_PAIR");

// P31: Old revision remains queryable and unchanged
$res31 = $conexion->query("SELECT estado_pareo FROM op_rh_v2_pareos_jornada WHERE id_jornada_programada = {$s3['shiftId']} AND revision_numero = 1");
$row31 = $res31 ? $res31->fetch_assoc() : null;
assert_test($row31 && $row31['estado_pareo'] === 'PARTIAL_MISSING_OUT', "P31: Historical revision 1 remains 100% unchanged after revision 2 creation");

// P44 & P45: Raw PunchEvent and ScheduledShift unchanged
$res44 = $conexion->query("SELECT processing_status FROM op_rh_v2_marcaciones_crudas WHERE id = {$s1['punchIds'][0]}");
$row44 = $res44 ? $res44->fetch_assoc() : null;
assert_test($row44 && $row44['processing_status'] === 'RECEIVED', "P44 & P45: Raw PunchEvent and ScheduledShift records remain completely un-mutated");

// P47 & P48: No FALTA or worked minutes calculation in output
assert_test(!isset($res1['falta']) && !isset($res1['worked_minutes']), "P47 & P48: Pairing runtime emits NO FALTA or worked-time payroll calculations");

test_log("=== V2.4-P1 Journey Pairing Runtime Test Suite Completed ===");
test_log("TOTAL PASSED: $passCount");
test_log("TOTAL FAILED: $failCount");

if ($failCount > 0) {
    exit(1);
}
