<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/PunchIncidenceDeadlineService.php';
require_once __DIR__ . '/../modelo/v2/AttendancePolicyV2Controller.php';
require_once __DIR__ . '/../modelo/v2/AttendancePolicyAuthorizationService.php';

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $name) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        fwrite(STDOUT, "PASS $name\n");
    } else {
        $failed++;
        fwrite(STDOUT, "FAIL $name\n");
    }
};

$at = static fn(string $instant, string $zone): DateTimeImmutable => new DateTimeImmutable($instant, new DateTimeZone($zone));
$zone = 'America/Mexico_City';

$zeroDayCutoff = PunchIncidenceDeadlineService::evaluate('2026-11-02', 0, $zone, $at('2026-11-02 23:59:59.999999', $zone));
$assert($zeroDayCutoff['cutoff_local'] === '2026-11-02 23:59:59' && $zeroDayCutoff['expired'] === false, 'zero-day deadline includes the complete local 23:59:59 second');
$assert(PunchIncidenceDeadlineService::evaluate('2026-11-02', 0, $zone, $at('2026-11-03 00:00:00', $zone))['expired'] === true, 'zero-day deadline expires at the next local second');

$sixDay = PunchIncidenceDeadlineService::evaluate('2026-11-02', 6, $zone, $at('2026-11-08 23:59:59.999999', $zone));
$assert($sixDay['cutoff_local'] === '2026-11-08 23:59:59' && $sixDay['expired'] === false, 'six natural days exclude check day and end on the sixth following day');
$assert(PunchIncidenceDeadlineService::evaluate('2026-11-02', 6, $zone, $at('2026-11-09 00:00:00', $zone))['expired'] === true, 'six-day deadline rejects from the following local day');

$dstCalendarDay = PunchIncidenceDeadlineService::evaluate(
    '2026-03-07',
    1,
    'America/New_York',
    $at('2026-03-08 23:59:59', 'America/New_York')
);
$assert($dstCalendarDay['cutoff_local'] === '2026-03-08 23:59:59' && $dstCalendarDay['expired'] === false, 'deadline uses local calendar days across a daylight-saving transition');

$throws = static function (callable $operation): bool {
    try { $operation(); return false; } catch (InvalidArgumentException) { return true; }
};
$assert($throws(static fn() => PunchIncidenceDeadlineService::evaluate('2026-02-30', 1, $zone)), 'invalid civil date is rejected');
$assert($throws(static fn() => PunchIncidenceDeadlineService::evaluate('2026-11-02', -1, $zone)), 'negative policy deadline is rejected');
$assert($throws(static fn() => PunchIncidenceDeadlineService::evaluate('2026-11-02', 31, $zone)), 'deadline above the authorized maximum is rejected');
$assert($throws(static fn() => PunchIncidenceDeadlineService::evaluate('2026-11-02', 1, 'Not/A_Zone')), 'unrecognized station timezone is rejected');

$integerValidator = new ReflectionMethod(AttendancePolicyV2Controller::class, 'nonNegativeInt');
$integerValidator->setAccessible(true);
$assert($integerValidator->invoke(null, 0) === 0 && $integerValidator->invoke(null, '120') === 120, 'API policy integer validator accepts inclusive zero and upper tolerance bound');
$assert($integerValidator->invoke(null, '30') === 30, 'API policy integer validator accepts upper deadline bound');
$assert($integerValidator->invoke(null, -1) === null && $integerValidator->invoke(null, '30.0') === null && $integerValidator->invoke(null, 1.5) === null, 'API policy integer validator rejects negative and non-integer values');

$grant = static fn(int $active, int $allowed): array => ['active' => $active, 'granted' => $allowed];
$assert(AttendancePolicyAuthorizationService::effectiveGrantDecision(null, $grant(1, 1)), 'active position grant authorizes when no user override exists');
$assert(!AttendancePolicyAuthorizationService::effectiveGrantDecision($grant(1, 0), $grant(1, 1)), 'explicit user denial overrides a position grant');
$assert(!AttendancePolicyAuthorizationService::effectiveGrantDecision($grant(0, 1), $grant(1, 1)), 'inactive user override fails closed instead of inheriting position grant');
$assert(!AttendancePolicyAuthorizationService::effectiveGrantDecision(null, $grant(1, 0)), 'position denial is preserved');
$assert(AttendancePolicyAuthorizationService::supportsPosition(Portal3JwtAuthHelper::ENCARGADO_PUESTO_ID), 'M11 API policy authorization recognizes Encargado only with a dedicated grant and station scope');
$assert(AttendancePolicyAuthorizationService::supportsPosition(Portal3JwtAuthHelper::DIRECCION_OPERACIONES_PUESTO_ID), 'M11 API policy authorization preserves Dirección');
$assert(!AttendancePolicyAuthorizationService::supportsPosition(Portal3JwtAuthHelper::ENCARGADO_TURNO_PUESTO_ID)
    && !AttendancePolicyAuthorizationService::supportsPosition(Portal3JwtAuthHelper::PUESTO_14_ID), 'M11 policy authorization does not infer grants for unrelated positions');

$incidenceControllerSource = file_get_contents(__DIR__ . '/../modelo/v2/AdministrativeIncidenceV2Controller.php');
$assert(str_contains($incidenceControllerSource, '$requestReceivedAtUtc = new DateTimeImmutable')
    && preg_match('/validatePunchAttendanceCheck\([\s\S]*?\$requestReceivedAtUtc\s*\);/', $incidenceControllerSource) === 1
    && str_contains($incidenceControllerSource, '$now = $requestReceivedAtUtc->setTimezone($timezone);'),
    'M06 eligibility uses the server request receipt instant through the station timezone');

fwrite(STDOUT, "RESULT {$passed} PASS / {$failed} FAIL\n");
exit($failed === 0 ? 0 : 1);
