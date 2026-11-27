<?php
declare(strict_types=1);

require_once __DIR__ . '/JourneyExecutionCoordinator.php';
require_once __DIR__ . '/JourneyMariaDbLock.php';
require_once __DIR__ . '/JourneyPairingService.php';
require_once __DIR__ . '/JourneyCalculationService.php';
require_once __DIR__ . '/../shift/ScheduledShiftMaterializationService.php';
require_once __DIR__ . '/../shift/WorkplaceCoverageFeatureGate.php';
require_once __DIR__ . '/../shift/WorkplaceCoverageService.php';

/** Domain orchestration; callers must independently authenticate and authorize. */
final class JourneySharedExecutionService
{
    public function __construct(private mysqli $db) {}

    public function evaluate(int $worker, int $station, string $date, bool $requireV11 = false, ?callable $beforeEvaluation = null, ?array $coverageAuthorizationContext = null): array
    {
        $lock = new JourneyMariaDbLock($this->db);
        $coordinator = new JourneyExecutionCoordinator([$lock, 'acquire'], [$lock, 'release']);
        $materializer = new ScheduledShiftMaterializationService($this->db);
        $pairing = new JourneyPairingService($this->db);
        $calculation = new \modelo\v2\journey\JourneyCalculationService($this->db);
        $stage = 'lock';
        try {
            return $coordinator->execute($worker, $station, $date,
                function () use (&$stage, $materializer, $worker, $station, $date, $requireV11, $beforeEvaluation, $coverageAuthorizationContext) {
                    if (WorkplaceCoverageFeatureGate::isEnabled()) {
                        $stage = 'coverage_authorization';
                        if ($coverageAuthorizationContext === null) throw new RuntimeException('COVERAGE_AUTH_CONTEXT_REQUIRED');
                        $effective = (new WorkplaceCoverageService($this->db))->resolveWorkerEffective($worker, $date, $coverageAuthorizationContext);
                        $effectiveWorkplace = (int)($effective['effective_workplace_id'] ?? $effective['id_estacion'] ?? 0);
                        if (($effective['estado_jornada'] ?? 'UNKNOWN') === 'UNKNOWN' || $effectiveWorkplace !== $station) {
                            throw new RuntimeException('EFFECTIVE_WORKPLACE_MISMATCH');
                        }
                    }
                    if ($beforeEvaluation !== null) {
                        $stage = 'level2';
                        $beforeEvaluation($worker, $station, $date);
                    }
                    $stage = 'policy_preflight';
                    require_once __DIR__ . '/JourneyV11OperationalContext.php';
                    $policy = (new JourneyV11OperationalContext($this->db, $coverageAuthorizationContext))->policy($station, $date);
                    if ($requireV11 && $policy === null) throw new RuntimeException('J1_BATCH_LEGACY_NOT_VERIFIED');
                    if ($policy !== null) {
                        if (!JourneyPairingService::v11PersistenceEnabled()) throw new RuntimeException('J1_V11_PERSISTENCE_DISABLED');
                        // Validate the very same plan before the first write (materialization).
                        // The writer independently prepares it again in its own snapshot.
                        if (!$this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !$this->db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')) throw new RuntimeException('J1_SNAPSHOT_UNAVAILABLE');
                        try {
                            $plan=(new JourneyV11OperationalContext($this->db, $coverageAuthorizationContext))->preparePairingEvaluation($worker,$station,$date);
                            if (!$plan['eligible_for_writer']) throw new RuntimeException('J1_V11_EVALUATION_BLOCKED');
                        } finally { $this->db->rollback(); }
                    }
                    $stage = 'materialize';
                    return $materializer->materializeSingleDayWithinLock($worker, $station, $date);
                },
                function (array $shift) use (&$stage, $pairing, $requireV11, $coverageAuthorizationContext) {
                    $stage = 'pair';
                    return $pairing->pairScheduledShift((int)$shift['id'], $requireV11, $coverageAuthorizationContext);
                },
                function (array $p1) use (&$stage, $calculation) {
                    $stage = 'calculate';
                    return $calculation->calculatePairingRevision((int)$p1['pairing_id']);
                });
        } catch (Throwable $e) {
            throw new RuntimeException('JOURNEY_EXECUTION_' . strtoupper($stage), 0, $e);
        }
    }
}
