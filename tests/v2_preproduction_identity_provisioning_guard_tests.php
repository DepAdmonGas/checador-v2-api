<?php

declare(strict_types=1);
require_once __DIR__ . '/../modelo/v2/InitialDeviceIdentityProvisioner.php';
require_once __DIR__ . '/../modelo/v2/BiometricRosterV2Projection.php';

$source = file_get_contents(__DIR__ . '/../modelo/v2/InitialDeviceIdentityProvisioner.php');
$script = file_get_contents(__DIR__ . '/../scripts/provision_initial_device_identities.php');
$start = strpos($source, 'public function runSelectedPreproductionWorker(');
$end = strpos($source, 'private static function requireInnoDb(', $start === false ? 0 : $start);
$strict = ($start !== false && $end !== false) ? substr($source, $start, $end - $start) : '';
$passed=0; $failed=0;
$test=static function(bool $ok,string $name) use (&$passed,&$failed): void { echo ($ok?'PASS ':'FAIL ').$name."\n"; if($ok)$passed++;else $failed++; };

$template = base64_encode(str_repeat("\x01", 256));
$test(InitialDeviceIdentityProvisioner::hasCompatibleBiometricTemplate([$template]), 'single base64 template within roster byte bound is accepted');
$uuid = '7d4b13cb-b3a9-4e3d-8f80-7f3b3d6e4391';
$roster = BiometricRosterV2Projection::project([[
    'id_personal'=>7001, 'external_worker_reference'=>$uuid, 'no_colaborador'=>7001,
    'id_estacion_adscripcion'=>1, 'identity_mapping_count'=>1, 'nombre'=>'Synthetic',
    'puesto'=>'', 'biometric_template'=>$template,
]], 1, 'Station', '2026-11-25T12:00:00Z');
$test(($roster['workers'][0]['external_worker_reference'] ?? null) === $uuid
    && ($roster['workers'][0]['has_biometric_template'] ?? false) === true,
    'UUID v4 identity and bounded template fit the existing roster DTO');
$test(!InitialDeviceIdentityProvisioner::hasCompatibleBiometricTemplate([]), 'worker without template is rejected for first biometric provisioning');
$test(!InitialDeviceIdentityProvisioner::hasCompatibleBiometricTemplate([$template, $template]), 'ambiguous multiple template rows are rejected');
$test(!InitialDeviceIdentityProvisioner::hasCompatibleBiometricTemplate(['not-base64']), 'invalid roster encoding is rejected');
$test(!InitialDeviceIdentityProvisioner::hasCompatibleBiometricTemplate([base64_encode(str_repeat('x', 2049))]), 'template over roster size bound is rejected');
$test(str_contains($strict, "'bd_admongas_v2_preprod'") && str_contains($strict, 'DATABASE_IDENTITY_MISMATCH') && str_contains($strict, '$confirmation'), 'strict apply is bound to the exact preproduction database identity');
$test(str_contains($strict, '$deviceId !== 2 || $stationId !== 1') && str_contains($strict, 'PREPRODUCTION_APPLY_NOT_CONFIRMED'), 'service independently enforces the fixed device/station and explicit apply confirmation');
$test(str_contains($strict, 'WHERE id=? FOR UPDATE') && str_contains($strict, 'WHERE id=? AND estado=1 AND id_estacion=? FOR UPDATE'), 'one selected device and one selected active worker are locked');
$test(str_contains($strict, 'op_rh_v2_dispositivo_credenciales') && str_contains($strict, 'ACTIVE_DEVICE_CREDENTIAL_REQUIRED'), 'one current active device credential is required');
$test(str_contains($strict, 'op_rh_v2_device_biometric_grants') && str_contains($strict, 'ACTIVE_BIOMETRIC_GRANT_REQUIRED'), 'current station and credential-bound biometric grant are required');
$test(str_contains($strict, 'WorkplacePhysicalStationResolver::resolveCanonicalLocality') && str_contains($strict, 'STATION_LOCALITY_MAPPING_AMBIGUOUS'), 'physical station must resolve to one canonical locality');
$test(str_contains($strict, 'op_rh_personal_localidad_version') && str_contains($strict, 'hasCurrentCanonicalMembership'), 'current bitemporal canonical worker membership is mandatory');
$test(str_contains($strict, 'op_rh_personal_acceso') && !str_contains($strict, 'SELECT pin') && str_contains($strict, 'hasCompatibleBiometricTemplate'), 'only template compatibility is checked and no PIN is selected');
$test(str_contains($strict, 'registrarIdentidadPersonal') && str_contains($strict, 'IDENTITY_READBACK_FAILED'), 'new UUID identity uses canonical writer and transactional readback');
$test(str_contains($strict, '$db->rollback()') && str_contains($strict, 'if ($apply) $db->commit(); else $db->rollback();'), 'dry-run rolls back and errors roll back writes');
$test(str_contains($script, "getenv('CHECADOR_ENABLE_IDENTITY_PROVISIONING_PREPROD')") && str_contains($script, '--confirm-preprod='), 'preproduction apply requires explicit gate and scope confirmation');
$test(str_contains($script, 'if ($deviceId !== 2 || $stationId !== 1)') && str_contains($script, "PREPROD_DB . ':' . \$deviceId . ':' . \$stationId . ':' . \$workerId"), 'CLI apply is limited to device 2, station 1 and one confirmed worker');
$test(str_contains($script, 'if ($dbName !== TEST_DB') && str_contains($script, 'Unscoped apply is permitted only'), 'unscoped legacy CLI apply remains disposable-local-only');
$test(str_contains($script, 'ReflectionMethod($provisioner, \'run\')') && str_contains($script, '$provisioner->run(true)'), 'disposable integration CLI remains compatible with the baseline provisioner signature');

echo "RESULT: {$passed} PASS / {$failed} FAIL\n";
exit($failed ? 1 : 0);
