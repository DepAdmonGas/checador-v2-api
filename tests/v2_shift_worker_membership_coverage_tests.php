<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/shift/ShiftWorkerMembershipService.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $name) use (&$passed, &$failed): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . "\n";
    $condition ? $passed++ : $failed++;
};

$membership = static function (int $id, int $worker, int $workplace, string $from, ?string $to, ?int $supersedes = null): array {
    return [
        'id' => $id,
        'worker_id' => $worker,
        'workplace_id' => $workplace,
        'valid_from_utc' => $from,
        'valid_to_utc' => $to,
        'supersedes_id' => $supersedes,
    ];
};
$oneDayMembership = $membership(1, 1005, 1, '2026-11-02 06:00:00', '2026-11-03 06:00:00');
$oneDay = ShiftWorkerMembershipService::evaluateCoverage(
    1005, 1, '2026-11-02T06:00:00Z', '2026-11-03T06:00:00Z', [$oneDayMembership]
);
$assert(($oneDay['covered'] ?? false) === true, 'one-day assignment is accepted when the selected local date is fully covered');

$week = ShiftWorkerMembershipService::evaluateCoverage(
    1005, 1, '2026-11-02T06:00:00Z', '2026-11-09T06:00:00Z', [$oneDayMembership]
);
$assert(($week['covered'] ?? true) === false && ($week['status'] ?? '') === 'MEMBERSHIP_INTERVAL_MISMATCH',
    'a one-day membership cannot authorize a whole-week assignment');

$gap = ShiftWorkerMembershipService::evaluateCoverage(
    1005, 1, '2026-11-03T06:00:00Z', '2026-11-04T06:00:00Z', [$oneDayMembership]
);
$assert(($gap['covered'] ?? true) === false && ($gap['status'] ?? '') === 'RESOLVED_NONE',
    'membership ending at the selected date excludes the next local date');

$wrongWorker = ShiftWorkerMembershipService::evaluateCoverage(
    2000, 1, '2026-11-02T06:00:00Z', '2026-11-03T06:00:00Z', [$oneDayMembership]
);
$assert(($wrongWorker['covered'] ?? true) === false && ($wrongWorker['status'] ?? '') === 'DATA_INVALID',
    'a membership record for a different worker fails closed');

$wrongWorkplace = ShiftWorkerMembershipService::evaluateCoverage(
    1005, 2, '2026-11-02T06:00:00Z', '2026-11-03T06:00:00Z', [$oneDayMembership]
);
$assert(($wrongWorkplace['covered'] ?? true) === false && ($wrongWorkplace['status'] ?? '') === 'MEMBERSHIP_INTERVAL_MISMATCH',
    'membership at another locality cannot authorize the requested station');

$ambiguous = ShiftWorkerMembershipService::evaluateCoverage(
    1005,
    1,
    '2026-11-02T06:00:00Z',
    '2026-11-03T06:00:00Z',
    [$oneDayMembership, $membership(2, 1005, 2, '2026-11-02 06:00:00', '2026-11-03 06:00:00')]
);
$assert(($ambiguous['covered'] ?? true) === false && ($ambiguous['status'] ?? '') === 'RESOLVED_MULTIPLE',
    'overlapping locality memberships fail closed as ambiguous');

$chain = [
    $membership(10, 1005, 1, '2026-10-28 06:00:00', null),
    $membership(11, 1005, 1, '2026-10-28 06:00:00', '2026-11-02 06:00:00', 10),
    $membership(12, 1005, 1, '2026-11-02 06:00:00', '2026-11-09 06:00:00', 11),
];
$chainDecision = ShiftWorkerMembershipService::evaluateCoverage(
    1005, 1, '2026-11-02T06:00:00Z', '2026-11-09T06:00:00Z', $chain
);
$assert(($chainDecision['covered'] ?? false) === true && ($chainDecision['membership_id'] ?? 0) === 12,
    'append-only membership supersession resolves the current leaf without reviving its predecessor');

$invalidInterval = ShiftWorkerMembershipService::evaluateCoverage(
    1005, 1, '2026-11-03T06:00:00Z', '2026-11-02T06:00:00Z', [$oneDayMembership]
);
$assert(($invalidInterval['covered'] ?? true) === false && ($invalidInterval['status'] ?? '') === 'DATA_INVALID',
    'reversed assignment interval is rejected');

echo "SHIFT_WORKER_MEMBERSHIP_COVERAGE: " . ($passed + $failed) . " PASS: {$passed} FAIL: {$failed}\n";
exit($failed === 0 ? 0 : 1);
