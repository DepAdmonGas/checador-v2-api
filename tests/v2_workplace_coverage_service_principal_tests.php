<?php
declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/shift/WorkplaceCoverageAuthorizationService.php';
require_once __DIR__ . '/../modelo/v2/shift/WorkplaceCoverageService.php';

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $name) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$throws = static function (callable $fn): bool {
    try { $fn(); return false; } catch (InvalidArgumentException) { return true; }
};

$check(WorkplaceCoverageAuthorizationService::servicePrincipalId('journey-local') === 1, 'journey-local has a distinct SERVICE principal ID');
$check(WorkplaceCoverageAuthorizationService::servicePhysicalStationIdsApproved([1,2,3,4,5,6,7,8,14]), 'dedicated service config matches the approved nine physical stations');
$check(WorkplaceCoverageAuthorizationService::servicePhysicalStationIdsApproved([14,8,7,6,5,4,3,2,1]), 'physical station config comparison is order independent');
$check(!WorkplaceCoverageAuthorizationService::servicePhysicalStationIdsApproved([1,2,3,4,5,6,7,14]), 'service scope fails closed when any approved station is missing');
$check(!WorkplaceCoverageAuthorizationService::servicePhysicalStationIdsApproved([1,2,3,4,5,6,7,8,9,14]), 'service scope fails closed when an unapproved station is added');
$check(!WorkplaceCoverageAuthorizationService::servicePhysicalStationIdsApproved([1,2,3,4,5,6,7,8,8,14]), 'service scope fails closed on duplicate physical stations');
$check($throws(static fn() => WorkplaceCoverageAuthorizationService::servicePrincipalId('unknown-service')), 'unknown service identity is rejected');
$check(WorkplaceCoverageAuthorizationService::serviceScopeAllows([1, 2, 3], [1, 3], 1, 3), 'origin and destination both require institutional and CLI scope');
$check(!WorkplaceCoverageAuthorizationService::serviceScopeAllows([1, 2, 3], [1, 3], 2, 3), 'origin outside CLI scope is denied');
$check(!WorkplaceCoverageAuthorizationService::serviceScopeAllows([1, 2, 3], [1, 3], 1, 2), 'destination outside CLI scope is denied');
$check(!WorkplaceCoverageAuthorizationService::serviceScopeAllows([1], [1, 2, 3], 1, 3), 'CLI allowlist cannot grant institutional scope');

$current = ['active'=>1, 'granted'=>1, 'valid_from_utc'=>'2026-11-26 00:00:00.000000', 'valid_until_utc'=>'2026-11-27 00:00:00.000000'];
$check(WorkplaceCoverageAuthorizationService::serviceGrantIsCurrent($current, '2026-11-26 12:00:00.000000'), 'valid service grant is accepted');
$check(!WorkplaceCoverageAuthorizationService::serviceGrantIsCurrent($current, '2026-11-27 00:00:00.000000'), 'expired service grant is denied at exclusive end');
$check(!WorkplaceCoverageAuthorizationService::serviceGrantIsCurrent($current, '2026-11-25 23:59:59.999999'), 'not-yet-effective service grant is denied');
$revoked = $current; $revoked['active'] = 0;
$check(!WorkplaceCoverageAuthorizationService::serviceGrantIsCurrent($revoked, '2026-11-26 12:00:00.000000'), 'inactive service grant is denied');
$denied = $current; $denied['granted'] = 0;
$check(!WorkplaceCoverageAuthorizationService::serviceGrantIsCurrent($denied, '2026-11-26 12:00:00.000000'), 'explicit service denial is honored');
$noExpiry = $current; $noExpiry['valid_until_utc'] = null;
$check(!WorkplaceCoverageAuthorizationService::serviceGrantIsCurrent($noExpiry, '2026-11-26 12:00:00.000000'), 'non-expiring service grant is denied');

$root = dirname(__DIR__);
$auth = file_get_contents($root . '/modelo/v2/shift/WorkplaceCoverageAuthorizationService.php');
$coverage = file_get_contents($root . '/modelo/v2/shift/WorkplaceCoverageService.php');
$context = file_get_contents($root . '/modelo/v2/journey/JourneyV11OperationalContext.php');
$shared = file_get_contents($root . '/modelo/v2/journey/JourneySharedExecutionService.php');
$batch = file_get_contents($root . '/scripts/journey_batch_runtime.php');
$check(str_contains($auth, "principal_type = 'SERVICE'") && str_contains($auth, 'valid_until_utc > UTC_TIMESTAMP(6)'), 'DB authorization requires current SERVICE-only read grant');
$check(str_contains($auth, "WHERE modulo_key = ? LIMIT 2") && str_contains($auth, "JOURNEY_LOCAL_SCOPE_MODULE_KEY = 'checador_journey_service'"), 'service institutional scope uses its dedicated configuration key');
$check(str_contains($auth, "!== 'stations_only'") && str_contains($auth, "!== 'localidades'") && str_contains($auth, "JOURNEY_LOCAL_PHYSICAL_STATIONS = [1, 2, 3, 4, 5, 6, 7, 8, 14]"), 'service scope enforces approved type, department and physical stations');
$check(str_contains($auth, 'WorkplacePhysicalStationResolver::resolveCanonicalLocality($db, $physicalId)') && str_contains($auth, 'WorkplacePhysicalStationResolver::resolve($db, $workplaceId)'), 'institutional stations and requested origin/destination require unique active canonical mappings');
$check(!str_contains(substr($auth, strpos($auth, 'private static function resolveInstitutionalWorkplaceScope'), strpos($auth, 'private static function decodeStationIds') - strpos($auth, 'private static function resolveInstitutionalWorkplaceScope')), "'biometricos'"), 'service scope has no biometricos fallback');
$check(str_contains($coverage, 'authorizeService(') && str_contains($coverage, 'authorize('), 'service auth is isolated and human USER/POSITION path remains');
$check(str_contains($coverage, 'COVERAGE_COMPETING_MATERIALIZED_JOURNEY'), 'other materialized workplace blocks duplicate journey');
$check(str_contains($context, 'resolveWorkerEffective(') && str_contains($context, 'J1_EFFECTIVE_WORKPLACE_CONFLICT'), 'J1 candidate discovery resolves effective workplace and rejects competing persisted journey');
$check(str_contains($shared, 'coverageAuthorizationContext') && str_contains($shared, 'resolveWorkerEffective('), 'shared J1/J2 execution revalidates effective workplace inside worker lock');
$check(substr_count($shared, 'new JourneyV11OperationalContext($this->db, $coverageAuthorizationContext)') === 2, 'shared policy and pre-write J1 plan receive the same authenticated coverage context');
$check(str_contains($batch, 'journeyProcessorDryRun($db,$w,$s,$d,$coverageAuthorizationContext)') && str_contains($batch, "'service_id'=>\$config['service_id']"), 'batch dry-run uses service scope and output records the service identity');

echo "WORKPLACE_COVERAGE_SERVICE_PRINCIPAL: {$passed} PASS / {$failed} FAIL" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
