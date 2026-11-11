<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/modelo/v2/shift/ShiftAssignmentService.php') ?: '';
$controller = file_get_contents($root . '/modelo/v2/shift/ShiftSchedulingV2Controller.php') ?: '';
$contract = file_get_contents($root . '/docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md') ?: '';
$runtime = file_get_contents(__DIR__ . '/v2_shift_scheduling_runtime_tests.php') ?: '';

$checks = [
    'frozen assignment contract requires both server audit timestamps' =>
        str_contains($contract, 'Returns assignment detail with server audit timestamps (`creado_at`, `registrado_at_utc`).'),
    'create handler returns successful assignment detail with HTTP 201' =>
        str_contains($controller, '$this->sendJsonSuccess($result, 201);'),
    'locked exact-repeat comparison loads persisted audit timestamps' =>
        str_contains($service, 'SELECT id, id_plantilla, tipo_asignacion, valido_desde, valido_hasta, creado_at, registrado_at_utc FROM op_rh_v2_turnos_asignaciones'),
    'exact-repeat branch returns persisted audit timestamps' =>
        str_contains($service, "'creado_at' => (string) \$row['creado_at']")
        && str_contains($service, "'registrado_at_utc' => (string) \$row['registrado_at_utc']"),
    'new insert reads both audit timestamps from the persisted row before commit' =>
        str_contains($service, 'SELECT creado_at, registrado_at_utc FROM op_rh_v2_turnos_asignaciones WHERE id = ? LIMIT 1')
        && str_contains($service, "'creado_at' => (string) \$auditRow['creado_at']")
        && str_contains($service, "'registrado_at_utc' => (string) \$auditRow['registrado_at_utc']"),
    'runtime regression covers persisted timestamps, exact replay, duplicate count, and HTTP 201' =>
        str_contains($runtime, "'T09A'")
        && str_contains($runtime, "'T09B'")
        && str_contains($runtime, "'T09C'"),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) $failed++;
}

echo sprintf("RESULT: %d PASS / %d FAIL (source/contract only; no database or API request)\n", count($checks) - $failed, $failed);
exit($failed === 0 ? 0 : 1);
