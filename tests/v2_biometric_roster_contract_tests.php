<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/BiometricRosterV2Projection.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $name) use (&$passed, &$failed): void {
    if ($condition) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}\n"; }
};
$worker = static fn(string $reference, int $id, ?string $template, int $mappingCount = 1): array => [
    'external_worker_reference' => $reference,
    'id_personal' => $id,
    'no_colaborador' => 1000 + $id,
    'nombre' => 'Synthetic Worker ' . $id,
    'puesto' => 'Synthetic Position',
    'id_estacion_adscripcion' => 7,
    'identity_mapping_count' => $mappingCount,
    'biometric_template' => $template,
];

$payload = BiometricRosterV2Projection::project(
    [$worker('reader-ref-17', 17, 'U1lOVEhFVElD'), $worker('reader-ref-18', 18, null)],
    7,
    'Synthetic Station',
    '2026-11-24T12:00:00-06:00'
);
$assert($payload['code'] === 200 && $payload['status'] === 'OK', 'success envelope uses expected scalar types');
$assert($payload['id_estacion'] === 7 && is_int($payload['id_estacion']), 'station id is physical tb_estaciones.id integer');
$assert($payload['total_workers'] === 2 && $payload['workers_with_biometrics'] === 1, 'counts include workers with and without template');
$assert($payload['workers'][0]['external_worker_reference'] === 'reader-ref-17', 'device-specific external identity is preserved as string');
$assert($payload['workers'][1]['biometric_template'] === null && $payload['workers'][1]['has_biometric_template'] === false, 'worker without template remains in catalog');
$assert($payload['workers'][0]['es_comodin'] === false, 'unverified legacy wildcard is never inferred');
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$assert(!str_contains($json, 'pin') && !str_contains($json, 'password'), 'serialized response excludes PIN and password fields');

$throws = static function (callable $callback): bool {
    try { $callback(); return false; } catch (Throwable) { return true; }
};
$assert($throws(static fn() => BiometricRosterV2Projection::project([$worker('ref', 1, null, 2)], 7, 'Station', 'timestamp')), 'ambiguous identity mapping fails closed');
$assert($throws(static fn() => BiometricRosterV2Projection::project([$worker('', 1, null)], 7, 'Station', 'timestamp')), 'missing device identity is rejected');
$assert($throws(static fn() => BiometricRosterV2Projection::project([$worker('ref', 1, null), $worker('other-ref', 1, null)], 7, 'Station', 'timestamp')), 'duplicate worker identities are rejected');
$assert($throws(static fn() => BiometricRosterV2Projection::project([$worker('same-ref', 1, null), $worker('same-ref', 2, null)], 7, 'Station', 'timestamp')), 'duplicate external references are rejected');
$assert($throws(static fn() => BiometricRosterV2Projection::project([$worker('ref', 1, 'not-base64!')], 7, 'Station', 'timestamp')), 'malformed biometric template is rejected');
$assert($throws(static fn() => BiometricRosterV2Projection::project([$worker('ref', 1, null)], 0, 'Station', 'timestamp')), 'invalid physical station is rejected');

$controller = file_get_contents(__DIR__ . '/../modelo/v2/BiometricRosterV2Controller.php');
$temporaryAccess = file_get_contents(__DIR__ . '/../modelo/v2/TemporaryAccessV2Service.php');
$punchEvents = file_get_contents(__DIR__ . '/../modelo/v2/PunchEventV2.php');
$router = file_get_contents(__DIR__ . '/../index.php');
$assert(is_string($controller) && str_contains($controller, 'autenticarCredencial') && str_contains($controller, 'validateToken') && str_contains($controller, "authorizeBiometricos(\$this->db, \$human, 'leer')"), 'controller composes Device Auth, JWT, and biometric ACL');
$assert(is_string($controller) && str_contains($controller, 'resolveCanonicalLocality') && str_contains($controller, 'STATION_JURISDICTION_MISMATCH'), 'controller maps physical device station to authorized canonical locality');
$assert(is_string($controller) && str_contains($controller, 'MISSING_HEADERS') && str_contains($controller, "respondError(401, 'DEVICE_UNAUTHORIZED')") && str_contains($controller, "respondError(401, 'SUPERVISOR_UNAUTHORIZED')"), 'missing or invalid authentication fails closed');
$assert(is_string($controller) && str_contains($controller, 'FUNCTION_PERMISSION_DENIED') && str_contains($controller, "respondError(403, 'STATION_JURISDICTION_MISMATCH')"), 'ACL denial and out-of-scope station fail closed');
$assert(is_string($controller) && !preg_match('/SELECT[^;]*\bpin\b/is', $controller), 'controller SQL does not select legacy PIN');
$assert(is_string($controller) && str_contains($controller, 'CHECADOR_V2_BIOMETRIC_ROSTER_READ') && str_contains($controller, 'workers_count') && !str_contains($controller, "'biometric_template' =>"), 'audit records counts and metadata without templates');
$assert(is_string($temporaryAccess) && str_contains($temporaryAccess, "\$nowStr = gmdate('Y-m-d H:i:s');") && str_contains($temporaryAccess, 'valido_hasta IS NULL OR valido_hasta > ?'), 'temporary access identity resolver uses UTC half-open validity');
$assert(is_string($punchEvents) && str_contains($punchEvents, 'valido_hasta IS NULL OR valido_hasta >'), 'punch identity resolver uses the same exclusive validity end');
$assert(is_string($controller) && str_contains($controller, 'valido_hasta IS NULL OR valido_hasta > ?'), 'roster identity selection matches both V2 identity consumers');
$assert(is_string($controller) && !str_contains($controller, 'op_rh_comodines_dia'), 'legacy wildcard rows cannot broaden the roster');
$assert(is_string($router) && str_contains($router, "\$partes_ruta[4] === 'station' && \$partes_ruta[5] === 'biometric-roster'") && str_contains($router, 'BiometricRosterV2Controller'), 'approved GET route is registered without changing legacy routes');

echo "RESULT: {$passed} PASS / {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
