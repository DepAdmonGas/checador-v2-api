<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/shift/ShiftAssignmentWriteCutover.php';

if (($argv[1] ?? '') === 'probe') {
    putenv(ShiftAssignmentWriteCutover::FLAG . '=0');
    $_ENV[ShiftAssignmentWriteCutover::FLAG] = '0';
    $_SERVER[ShiftAssignmentWriteCutover::FLAG] = '0';
    register_shutdown_function(static function (): void {
        fwrite(STDERR, "__HTTP_STATUS__=" . (string) http_response_code());
    });
    require_once __DIR__ . '/../modelo/v2/shift/ShiftSchedulingV2Controller.php';
    $controller = (new ReflectionClass(ShiftSchedulingV2Controller::class))->newInstanceWithoutConstructor();
    match ($argv[2] ?? '') {
        'create' => $controller->handleCreateAssignment(),
        'close' => $controller->handleCloseAssignment(1),
        'replace' => $controller->handleReplaceAssignment(1),
        default => exit(2),
    };
    exit(3);
}

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/modelo/v2/shift/ShiftSchedulingV2Controller.php') ?: '';
$router = file_get_contents($root . '/index.php') ?: '';
$passed = 0;
$failed = 0;
$assert = static function (bool $ok, string $name) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$assert(!ShiftAssignmentWriteCutover::enabled('') && !ShiftAssignmentWriteCutover::enabled('0')
    && !ShiftAssignmentWriteCutover::enabled('false'), 'API assignment writes fail closed with an absent or false flag');
$assert(ShiftAssignmentWriteCutover::enabled('1') && ShiftAssignmentWriteCutover::enabled('true')
    && !ShiftAssignmentWriteCutover::enabled('unexpected'), 'API assignment writes require a strict explicit enable value');
foreach ([
    'handleCreateAssignment' => '$this->assignmentService->createAssignment(',
    'handleCloseAssignment' => '$this->assignmentService->closeAssignment(',
    'handleReplaceAssignment' => '$this->assignmentService->replaceAssignment(',
] as $method => $mutation) {
    $start = strpos($controller, 'public function ' . $method . '(');
    $end = strpos($controller, 'public function ', $start === false ? 0 : $start + 1);
    $body = $start === false ? '' : substr($controller, $start, $end === false ? null : $end - $start);
    $gate = strpos($body, 'ShiftAssignmentWriteCutover::enabled()');
    $auth = strpos($body, '$this->authorizeScheduleWrite()');
    $write = strpos($body, $mutation);
    $assert($gate !== false && $auth !== false && $write !== false && $gate < $auth && $auth < $write
        && str_contains($body, "'M03_ASSIGNMENT_WRITES_DISABLED'")
        && str_contains($body, '503'), $method . ' rejects with 503 before authorization-backed mutation');
}
foreach (['create', 'close', 'replace'] as $operation) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'probe', $operation], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $assert(false, $operation . ' endpoint returns explicit HTTP 503 before any database access');
        continue;
    }
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $diagnostic = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $payload = json_decode((string) $body, true);
    $assert($exitCode === 0 && ($payload['error']['code'] ?? '') === ShiftAssignmentWriteCutover::ERROR_CODE
        && str_contains((string) $diagnostic, '__HTTP_STATUS__=' . ShiftAssignmentWriteCutover::HTTP_STATUS),
        $operation . ' endpoint returns explicit HTTP 503 before any database access');
}
$assert(str_contains($router, "if (\$server_method === 'GET')")
    && str_contains($router, '$shiftCtrl->handleListAssignments();'), 'assignment GET route remains outside the mutation gate');
$assert(str_contains($router, '$shiftCtrl->handleGetEffectiveSchedule($idPersonal);')
    && str_contains($router, '$shiftCtrl->handleMaterializeScheduledShifts();'), 'effective schedule and materialization endpoints remain untouched');
echo "M03_API_ASSIGNMENT_CUTOVER: {$passed} PASS / {$failed} FAIL" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
