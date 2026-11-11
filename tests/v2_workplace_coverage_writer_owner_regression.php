<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/modelo/v2/shift/WorkplaceCoverageService.php');
$controller = file_get_contents($root . '/modelo/v2/shift/WorkplaceCoverageV2Controller.php');
$routes = file_get_contents($root . '/index.php');
$passes = 0;
$fails = 0;
$assert = static function (bool $condition, string $label) use (&$passes, &$fails): void {
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
    $condition ? $passes++ : $fails++;
};
$method = static function (string $source, string $signature): string {
    $start = strpos($source, $signature);
    if ($start === false) return '';
    $open = strpos($source, '{', $start);
    if ($open === false) return '';
    $depth = 0;
    for ($i = $open; $i < strlen($source); $i++) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}' && --$depth === 0) return substr($source, $open, $i - $open + 1);
    }
    return '';
};
$create = $method($service, 'public function createRequest(');
$transition = $method($service, 'public function transition(');
$httpCreate = $method($controller, 'public function handleCreate(');
$httpTransition = $method($controller, 'public function handleTransition(');
$assert($create !== '' && str_contains($create, 'COVERAGE_WRITER_PORTAL3_ONLY')
    && strpos($create, 'COVERAGE_WRITER_PORTAL3_ONLY') < strpos($create, 'normalizeRequest('),
    'direct API service create mutation is retired before feature-gate or persistence work');
$assert($transition !== '' && str_contains($transition, 'COVERAGE_WRITER_PORTAL3_ONLY')
    && strpos($transition, 'COVERAGE_WRITER_PORTAL3_ONLY') < strpos($transition, 'begin_transaction'),
    'direct API service transitions are retired before feature-gate or persistence work');
$assert(strpos($httpCreate, '$this->authenticate()') < strpos($httpCreate, 'COVERAGE_WRITER_PORTAL3_ONLY')
    && str_contains($httpCreate, 'respondError('),
    'API create route preserves JWT authentication then returns stable writer-retired denial');
$assert(strpos($httpTransition, '$this->authenticate()') < strpos($httpTransition, 'COVERAGE_WRITER_PORTAL3_ONLY')
    && str_contains($httpTransition, 'respondError('),
    'API transition route preserves JWT authentication then returns stable writer-retired denial');
$assert(str_contains($controller, 'public function handleList()') && str_contains($controller, 'public function handleDetail(')
    && str_contains($controller, 'public function handleWorkerEffectiveSchedule(')
    && str_contains($service, 'public function listVisible(') && str_contains($service, 'public function getVisible(')
    && str_contains($service, 'public function resolveWorkerEffective('),
    'API read/list/detail and WORKER_EFFECTIVE methods remain present');
$assert(str_contains($routes, 'handleCreate()') && str_contains($routes, 'handleTransition(')
    && str_contains($routes, 'handleList()') && str_contains($routes, 'handleDetail('),
    'existing API routes dispatch mutations only to retired handlers and preserve read routes');
printf("WORKPLACE_COVERAGE_WRITER_OWNER: %d PASS / %d FAIL\n", $passes, $fails);
exit($fails === 0 ? 0 : 1);
