<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$controller = file_get_contents($root . '/modelo/v2/shift/WorkplaceCoverageV2Controller.php');
$service = file_get_contents($root . '/modelo/v2/shift/WorkplaceCoverageService.php');
$gate = file_get_contents($root . '/modelo/v2/shift/WorkplaceCoverageFeatureGate.php');
$policy = file_get_contents($root . '/modelo/v2/shift/WorkplaceCoveragePolicy.php');

$checks = [
    'coverage GET and POST route dispatched' => str_contains($index, "\$server_method === 'GET'") && str_contains($index, "\$coverageCtrl->handleList()") && str_contains($index, "\$coverageCtrl->handleCreate()"),
    'coverage detail GET route dispatched' => str_contains($index, "\$coverageCtrl->handleDetail((string)\$partes_ruta[4])"),
    'coverage authorize/reject/cancel POST route dispatched' => str_contains($index, "['authorize', 'reject', 'cancel']") && str_contains($index, 'handleTransition('),
    'worker effective route opt-in preserves ordinary handler' => str_contains($index, 'resolution_context') && str_contains($index, 'WORKER_EFFECTIVE') && str_contains($index, 'handleGetEffectiveSchedule($idPersonal);'),
    'controller authenticates delegated JWT' => str_contains($controller, 'Portal3JwtAuthHelper::validateToken'),
    'mutations require Idempotency-Key' => str_contains($controller, 'HTTP_IDEMPOTENCY_KEY'),
    'worker effective rejects caller-selected locality' => str_contains($controller, 'id_estacion') && str_contains($controller, 'workplace_id'),
    'feature gate is default-off exact opt-in' => str_contains($gate, 'CHECADOR_V2_WORKPLACE_COVERAGE_ENABLED') && str_contains($policy, "trim(\$value) === '1'"),
    'writer guards requests and transitions before DB write path' => str_contains($service, 'public function createRequest') && str_contains($service, 'public function transition(') && str_contains($service, '$this->assertReady();'),
    'coverage writes are transactional and rollback on failure' => substr_count($service, '$this->db->begin_transaction();') >= 2 && substr_count($service, '$this->db->rollback();') >= 2,
    'legacy comodines tables are not used as schedule source' => !str_contains($service, 'op_rh_rol_comodines') && !str_contains($service, 'op_rh_comodines_dia'),
    'detail includes immutable revision and audit history' => str_contains($service, "'revision_history'") && str_contains($service, "'audit_events'"),
];

$failed = 0;
foreach ($checks as $name => $passed) {
    echo '[' . $name . '] ' . ($passed ? 'PASS' : 'FAIL') . "\n";
    if (!$passed) $failed++;
}
echo 'WORKPLACE_COVERAGE_ROUTE_STATIC: ' . (count($checks) - $failed) . ' PASS: ' . (count($checks) - $failed) . ' FAIL: ' . $failed . "\n";
exit($failed === 0 ? 0 : 1);
