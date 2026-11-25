<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/TemporaryAccessRequestScopePrepared.php';

$service = file_get_contents(__DIR__ . '/../modelo/v2/TemporaryAccessV2Service.php');
$controller = file_get_contents(__DIR__ . '/../modelo/v2/TemporaryAccessV2Controller.php');
$scope = file_get_contents(__DIR__ . '/../modelo/v2/TemporaryAccessRequestScopePrepared.php');
$assertions = 0;
$failures = 0;
$assert = static function (bool $ok, string $name) use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) $failures++;
};

$start = strpos($service, 'public function validatePin(');
$end = strpos($service, 'private function matchesRecentUsedPin(', $start === false ? 0 : $start);
$validate = ($start !== false && $end !== false) ? substr($service, $start, $end - $start) : '';
$createStart = strpos($service, 'public function createRequest(');
$createEnd = strpos($service, 'public function getPendingRequestsForPortal3(', $createStart === false ? 0 : $createStart);
$create = ($createStart !== false && $createEnd !== false) ? substr($service, $createStart, $createEnd - $createStart) : '';
$ack = strpos($validate, 'Lost ACK is idempotent only');
$candidate = strpos($validate, 'Select current authorization before consulting consumption history');
$used = strpos($validate, 'matchesRecentUsedPin(');
$consume = strpos($validate, "SET status = 'USED'");
$wrongPin = strpos($validate, 'Wrong PIN increments only the currently selected authorization');

$assert($validate !== '', 'validation method is inspectable');
$assert(str_contains($validate, 'successful_validation_uuid = ? AND status = \'USED\'')
    && str_contains($validate, 'id_dispositivo, id_estacion, referencia_externa_personal')
    && str_contains($validate, 'id_personal_resuelto, pin_hash'), 'Lost ACK lookup loads the full original context and stored verifier');
$assert($ack !== false && str_contains(substr($validate, $ack), 'password_verify($temporaryPin')
    && str_contains(substr($validate, $ack), 'sameContext'), 'Lost ACK requires the same context and same PIN');
$assert($candidate !== false && $used !== false && $candidate < $used, 'current authorization is selected before any historical USED check');
$assert(str_contains($service, "AND status = 'USED'")
    && !str_contains(substr($service, strpos($service, 'private function matchesRecentUsedPin(')), 'pin_expires_at_utc > UTC_TIMESTAMP(6)')
    && str_contains($service, 'password_verify($pin'), 'consumed PIN remains single-use within its device/station/worker/reference scope');
$assert(str_contains($validate, "WHERE id = ? AND status = 'AUTHORIZED' AND successful_validation_uuid IS NULL")
    && str_contains($validate, 'pin_expires_at_utc > UTC_TIMESTAMP(6)'), 'single-use consumption is conditional, serialized, and expiry guarded');
$assert($wrongPin !== false && str_contains(substr($validate, $wrongPin), "SET pin_failed_attempts = ?, status = ?")
    && str_contains($validate, "'LOCKED'"), 'failed attempts update only the locked current authorization');
$assert(str_contains($validate, 'TemporaryAccessRequestScopePrepared::verify')
    && str_contains($validate, "WHERE id = ? FOR UPDATE")
    && str_contains($validate, '$this->credentialIsActiveForDevice')
    && str_contains($service, "estado = 'ACTIVE' AND revocado_at IS NULL")
    && str_contains($scope, 'op_rh_personal WHERE id=? FOR UPDATE')
    && str_contains($scope, 'workerBelongsToStation($worker, $workerId, $stationId)')
    && !str_contains($scope, 'op_rh_personal_localidad_version')
    && str_contains($scope, 'WorkplacePhysicalStationResolver::resolveCanonicalLocality')
    && str_contains($scope, 'tb_estaciones WHERE id=? FOR UPDATE')
    && str_contains($scope, 'es_activo=1')
    && str_contains($scope, 'valido_desde<=UTC_TIMESTAMP(6)'), 'current device, credential, canonical worker membership, station, and identity scope are revalidated transactionally');
$assert(str_contains($controller, "'id_credencial' => \$authResult['credential_id']")
    && str_contains($controller, '$validationUuid, $idCredencial'), 'authenticated credential identity reaches transactional validation');
$assert(!str_contains($validate, '$this->lazyExpireRequests($conexion)'), 'validation avoids global lazy-expiry writes and lock-order inversion');
$assert(strpos($create, 'WHERE id = ? FOR UPDATE') < strpos($create, '$this->expireRequestsForIdentity')
    && !str_contains($create, '$this->lazyExpireRequests($conexion)'), 'request creation follows device-first scoped-expiration lock order');
$assert(str_contains($service, "'status' => 'VALID'") && str_contains($service, "'status' => 'ALREADY_USED'")
    && str_contains($service, "'status' => 'INVALID_PIN'") && str_contains($service, "'status' => 'LOCKED'")
    && str_contains($service, "'status' => 'EXPIRED'"), 'contractual validation response statuses remain available');

$worker = ['id' => 77, 'id_estacion' => 4, 'estado' => 1];
$assert(TemporaryAccessRequestScopePrepared::workerBelongsToStation($worker, 77, 4), 'active worker assigned to authorized physical station is accepted');
$assert(!TemporaryAccessRequestScopePrepared::workerBelongsToStation($worker, 77, 5), 'worker assigned to another physical station is denied');
$worker['estado'] = 0;
$assert(!TemporaryAccessRequestScopePrepared::workerBelongsToStation($worker, 77, 4), 'inactive worker is denied');

echo "RESULT: {$assertions} checks / {$failures} failures" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
