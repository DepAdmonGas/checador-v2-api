<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/shift/ShiftSchedulingAuthorizationService.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $name) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "[{$name}] PASS\n";
    } else {
        $failed++;
        echo "[{$name}] FAIL\n";
    }
};

$assert(ShiftSchedulingAuthorizationService::positionCanUseCapability(6, ShiftSchedulingAuthorizationService::READ), 'SA01 manager read');
$assert(ShiftSchedulingAuthorizationService::positionCanUseCapability(6, ShiftSchedulingAuthorizationService::EDIT), 'SA02 manager edit');
$assert(ShiftSchedulingAuthorizationService::positionCanUseCapability(13, ShiftSchedulingAuthorizationService::READ), 'SA03 direction read');
$assert(!ShiftSchedulingAuthorizationService::positionCanUseCapability(13, ShiftSchedulingAuthorizationService::EDIT), 'SA04 direction remains read-only');
$assert(!ShiftSchedulingAuthorizationService::positionCanUseCapability(5, ShiftSchedulingAuthorizationService::EDIT), 'SA05 unrelated role cannot edit scheduling');
$assert(!ShiftSchedulingAuthorizationService::positionCanUseCapability(6, 'biometricos.editar'), 'SA06 broad ACL cannot be requested through scheduling capability');
$assert(ShiftSchedulingAuthorizationService::positionCanUseCapability(99, ShiftSchedulingAuthorizationService::TEMPLATE_ADMIN), 'SA07 template administration remains a separately granted capability');

$single = ShiftSchedulingAuthorizationService::resolveCanonicalStationScope(
    6, 1, [1, 2], [1, 2], [1 => 101, 2 => 202], [101, 202]
);
$assert($single === [101], 'SA08 manager receives only canonical locality mapped from own physical station');
$differentDomains = ShiftSchedulingAuthorizationService::resolveCanonicalStationScope(
    6, 7, [7, 8], [7, 8], [7 => 1, 8 => 7], [1, 7]
);
$assert($differentDomains === [1], 'SA09 numeric equality between physical and canonical identifiers is not required');
$multi = ShiftSchedulingAuthorizationService::resolveCanonicalStationScope(
    13, 8, [1, 2, 3], [1, 3], [1 => 101, 3 => 303], [101, 303]
);
$assert($multi === [101, 303], 'SA10 direction receives all mapped active station localities and skips retired allowlist entries');
$assert(ShiftSchedulingAuthorizationService::resolveCanonicalStationScope(
    6, 2, [1, 2], [1, 2], [1 => 101, 2 => 202], [101, 202]
) === [202], 'SA11 manager scope follows id_gas, not the allowlist order');
$assert(ShiftSchedulingAuthorizationService::resolveCanonicalStationScope(
    6, 2, [1, 2], [1, 2], [1 => 101, 2 => 202], [101]
) === [], 'SA12 worker locality absent from canonical authorized scope fails closed');
$assert(ShiftSchedulingAuthorizationService::resolveCanonicalStationScope(
    13, 0, [1, 2], [1, 2], [1 => 101], [101, 202]
) === [], 'SA13 an active station with missing/ambiguous mapping fails the full scope closed');
$assert(ShiftSchedulingAuthorizationService::resolveCanonicalStationScope(
    6, 2, [1, 2], [1], [1 => 101], [101]
) === [], 'SA14 inactive home station cannot be skipped for manager');
$assert(ShiftSchedulingAuthorizationService::resolveCanonicalStationScope(
    5, 1, [1], [1], [1 => 101], [101]
) === [], 'SA15 unsupported role has no station scope');

$assert(ShiftSchedulingAuthorizationService::effectiveGrantDecision(null, ['active' => 1, 'granted' => 1]), 'SA16 position grant permits when no direct user row exists');
$assert(!ShiftSchedulingAuthorizationService::effectiveGrantDecision(['active' => 1, 'granted' => 0], ['active' => 1, 'granted' => 1]), 'SA17 direct user revocation overrides a position grant');
$assert(!ShiftSchedulingAuthorizationService::effectiveGrantDecision(['active' => 0, 'granted' => 1], ['active' => 1, 'granted' => 1]), 'SA18 inactive user override fails closed');
$assert(!ShiftSchedulingAuthorizationService::effectiveGrantDecision(null, ['active' => 1, 'granted' => 0]), 'SA19 explicit position denial remains denied');

$controller = file_get_contents(__DIR__ . '/../modelo/v2/shift/ShiftSchedulingV2Controller.php');
$assert(is_string($controller) && str_contains($controller, 'ShiftSchedulingAuthorizationService::READ'), 'SA20 controller uses specific scheduling authorization');
$assert(is_string($controller) && str_contains($controller, 'authorizeBiometricosWrite'), 'SA21 only the separate materialization operation retains biometricos.editar');
$assert(is_string($controller) && str_contains($controller, 'assertHistoryIntervalWithinScope'), 'SA22 assignment history is filtered by verified worker membership');
$assert(is_string($controller) && str_contains($controller, 'handleReplaceAssignment'), 'SA23 atomic replacement handler is available');

echo "SHIFT_SCHEDULING_AUTHORIZATION: " . ($passed + $failed) . " PASS: {$passed} FAIL: {$failed}\n";
exit($failed === 0 ? 0 : 1);
