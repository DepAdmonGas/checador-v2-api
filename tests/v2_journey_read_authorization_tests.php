<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/shift/JourneyReadAuthorizationService.php';

$passes = 0;
$fails = 0;
$assert = static function (bool $condition, string $label) use (&$passes, &$fails): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $condition ? $passes++ : $fails++;
};

$assert(JourneyReadAuthorizationService::positionCanRead(6), 'Encargado is eligible for the narrow M05 read permission');
$assert(JourneyReadAuthorizationService::positionCanRead(13), 'Dirección retains the supported supervisory read role');
$assert(!JourneyReadAuthorizationService::positionCanRead(3)
    && !JourneyReadAuthorizationService::positionCanRead(14)
    && !JourneyReadAuthorizationService::positionCanRead(0), 'unsupported positions fail closed');
$assert(JourneyReadAuthorizationService::effectiveGrantDecision(null, ['active' => 1, 'granted' => 1]), 'active position grant allows read');
$assert(!JourneyReadAuthorizationService::effectiveGrantDecision(['active' => 1, 'granted' => 0], ['active' => 1, 'granted' => 1]), 'direct user denial overrides a position grant');
$assert(!JourneyReadAuthorizationService::effectiveGrantDecision(['active' => 0, 'granted' => 1], ['active' => 1, 'granted' => 1]), 'direct user revocation overrides a position grant');
$assert(JourneyReadAuthorizationService::restrictStationScope([1], null) === [1], 'omitted station uses the complete authorized scope');
$assert(JourneyReadAuthorizationService::restrictStationScope([1, 2], 2) === [2], 'explicit authorized station narrows the scope');
$assert(JourneyReadAuthorizationService::restrictStationScope([1], 2) === null, 'foreign station is denied');
$assert(JourneyReadAuthorizationService::restrictStationScope([], null) === null, 'empty station scope is denied');
$assert(JourneyReadAuthorizationService::restrictStationScope([1, 0], null) === null, 'malformed station scope is denied');

$punch = file_get_contents(__DIR__ . '/../modelo/v2/PunchEventReadV2Controller.php');
$timezone = file_get_contents(__DIR__ . '/../modelo/v2/WorkplaceTimezoneReadV2Controller.php');
$snapshot = file_get_contents(__DIR__ . '/../modelo/v2/SnapshotReadV2Service.php');
$journeyEvaluation = file_get_contents(__DIR__ . '/../modelo/v2/journey/JourneyEvaluationV2Controller.php');
$assert(is_string($punch)
    && strpos($punch, 'Portal3JwtAuthHelper::validateToken') < strpos($punch, 'JourneyReadAuthorizationService::authorize')
    && str_contains($punch, "(\$authz['status'] ?? '') === 'FUNCTION_PERMISSION_DENIED'"), 'raw observations validate JWT before the narrow permission fallback and do not bypass other denials');
$assert(is_string($punch)
    && str_contains($punch, 'restrictStationScope($authorizedStationIds, $stationIdParam)')
    && str_contains($punch, 'WHERE id_estacion_resuelta IN')
    && str_contains($punch, 'if ($journeyReadPermissionUsed')
    && str_contains($punch, "array_key_exists('station_id', \$_GET)"), 'M05 raw SQL is constrained by server scope and rejects malformed explicit station filters');
$assert(is_string($timezone)
    && str_contains($timezone, 'JourneyReadAuthorizationService::authorize')
    && str_contains($timezone, 'in_array($workplaceId, $authorizedStations, true)'), 'timezone reads require the new permission and explicit station scope');
$assert(is_string($snapshot) && !str_contains($snapshot, 'JourneyReadAuthorizationService::authorize'), 'snapshot creation/read ownership does not inherit the M05 grant');
$assert(is_string($journeyEvaluation) && !str_contains($journeyEvaluation, 'JourneyReadAuthorizationService::authorize'), 'Journey evaluation does not inherit the M05 grant');

echo "RESULT: {$passes} PASS / {$fails} FAIL" . PHP_EOL;
exit($fails === 0 ? 0 : 1);
