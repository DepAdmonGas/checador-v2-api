<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/modelo/v2/shift/ShiftAssignmentReadSnapshotService.php');
$controller = file_get_contents($root . '/modelo/v2/shift/ShiftSchedulingV2Controller.php');
$migration = file_get_contents($root . '/migraciones/v2/2026_11_05_000001_create_v2_shift_assignment_read_snapshots.sql');
$freeze = file_get_contents($root . '/docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md');
$addendum = file_get_contents($root . '/docs/v2/CHECADOR_V2_SHIFT_ASSIGNMENT_PAGINATION_COMPLETENESS_ADDENDUM_DRAFT_V1.0.md');
$assertions = [
    'Snapshot protocol stays on existing GET route' => str_contains($controller, 'pagination_mode') && str_contains($controller, 'handleListAssignments'),
    'Opaque token is accepted only as a server request header' => str_contains($controller, 'HTTP_X_READ_SNAPSHOT') && str_contains($service, "'X-Read-Snapshot'"),
    'API scopes snapshot to fresh actor and authorized localities' => str_contains($controller, "\$authz['user_id']") && str_contains($service, 'canonicalScope($authorizedStations)'),
    'Stable assignment order includes unique ID tie-breaker' => str_contains($service, 'ORDER BY valido_desde DESC, id DESC'),
    'Snapshot has an absolute five-minute TTL' => str_contains($service, 'TTL_SECONDS = 300') && str_contains($service, 'TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6))'),
    'Approved page and byte bounds are present' => str_contains($service, 'MAX_PAGES = 50') && str_contains($service, 'MAX_RECORDS = 10000') && str_contains($service, 'MAX_SNAPSHOT_BYTES = 4194304'),
    'ACL scope change and membership change fail with approved statuses' => str_contains($service, "'READ_SNAPSHOT_SCOPE_CHANGED', 403") && str_contains($service, "'READ_SNAPSHOT_MEMBERSHIP_CHANGED', 409"),
    'Only two dedicated additive InnoDB snapshot tables are created' => substr_count($migration, 'CREATE TABLE IF NOT EXISTS') === 2 && str_contains($migration, 'ENGINE=InnoDB'),
    'Frozen Architecture Freeze is not edited by the implementation' => str_contains($freeze, '**Implementation Authorized:** NO'),
    'Approved addendum source hash remains available as contract evidence' => hash('sha256', $addendum) === '50f67f0468f9dfabb38bf7ea3d363f6ad7e618ee6d3a68cc6ce82393ebcc9385',
];
$failed = 0;
foreach ($assertions as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
    $failed += $passed ? 0 : 1;
}
echo 'SHIFT_ASSIGNMENT_SNAPSHOT_CONTRACT: ' . count($assertions) . ' checks; ' . $failed . ' failures' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
