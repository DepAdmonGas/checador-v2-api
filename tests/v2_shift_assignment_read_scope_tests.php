<?php
/**
 * Isolated Block A security regression for GET shift assignments.
 * Uses only an in-memory SQLite fixture; no API, MariaDB or operational data.
 */

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/shift/ShiftAssignmentService.php';

$passed = 0;
$failed = 0;

function scope_assert(bool $condition, string $id, string $description): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[$id] PASS: {$description}\n";
    } else {
        $failed++;
        echo "[$id] FAIL: {$description}\n";
    }
}

function scope_plan(int $workerId, array $authorized, ?int $requested = null, ?string $date = null, int $page = 1, int $perPage = 50): array
{
    $builder = new ReflectionMethod(ShiftAssignmentService::class, 'buildListAssignmentsQueryPlan');
    return $builder->invoke(null, $workerId, $authorized, $requested, $date, $page, $perPage);
}

function expect_scope_exception(callable $callback, string $class): bool
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception instanceof $class;
    }
    return false;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE op_rh_v2_turnos_asignaciones (
    id INTEGER PRIMARY KEY,
    id_personal INTEGER NOT NULL,
    id_estacion INTEGER NOT NULL,
    id_plantilla INTEGER,
    tipo_asignacion TEXT NOT NULL DEFAULT \'HABITUAL\',
    dia_semana TEXT NOT NULL,
    valido_desde TEXT NOT NULL,
    valido_hasta TEXT,
    es_activo INTEGER NOT NULL,
    registrado_at_utc TEXT NOT NULL
)');

$insert = $pdo->prepare('INSERT INTO op_rh_v2_turnos_asignaciones
    (id, id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo, registrado_at_utc)
    VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?)');
foreach ([
    [1, 100, 10, 'LUNES', '2026-09-01', null, 1],
    [2, 100, 20, 'MARTES', '2026-09-02', null, 1],
    [3, 100, 30, 'MIERCOLES', '2026-09-03', null, 1],
    [4, 101, 10, 'JUEVES', '2026-09-04', null, 1],
    [5, 100, 10, 'VIERNES', '2026-09-05', '2026-10-15', 0],
] as [$id, $worker, $station, $day, $from, $until, $active]) {
    $insert->execute([$id, $worker, $station, $day, $from, $until, $active, '2026-10-01 00:00:00']);
}

$runPlan = static function (array $plan) use ($pdo): array {
    $statement = $pdo->prepare($plan['sql']);
    $statement->execute($plan['params']);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};

// Encargado: one authorized locality; omitted locality remains strictly scoped.
$singleLocality = scope_plan(100, [10]);
$singleRows = $runPlan($singleLocality);
scope_assert(array_column($singleRows, 'id') === [5, 1], 'BA01', 'Encargado sees only assignment history in the single authorized locality');
scope_assert(str_contains($singleLocality['sql'], 'id_personal = ? AND id_estacion IN (?)'), 'BA02', 'omitted locality still produces a parameterized locality predicate');
scope_assert($singleLocality['params'] === [100, 10, 50, 0], 'BA03', 'query binds worker and only the authorized locality');

// Dirección: multiple authorized localities; cross-locality assignments remain visible only inside that set.
$multiLocality = scope_plan(100, [10, 20]);
$multiRows = $runPlan($multiLocality);
scope_assert(array_column($multiRows, 'id') === [5, 2, 1], 'BA04', 'multi-locality reader retains assignments across all and only authorized localities');
scope_assert(str_contains($multiLocality['sql'], 'id_estacion IN (?, ?)'), 'BA05', 'multi-locality SQL includes the complete authorized set');

$explicitAllowed = scope_plan(100, [10, 20], 20);
scope_assert(array_column($runPlan($explicitAllowed), 'id') === [2], 'BA06', 'explicit authorized locality narrows the query to that locality');
scope_assert(expect_scope_exception(static fn() => scope_plan(100, [10, 20], 30), DomainException::class), 'BA07', 'explicit unauthorized locality is rejected before query execution');

$outsideWorkerScope = scope_plan(100, [10]);
scope_assert(array_column($runPlan($outsideWorkerScope), 'id') === [5, 1], 'BA08', 'worker assignments in an out-of-scope locality are excluded');
$noMatchingWorker = scope_plan(102, [10]);
scope_assert($runPlan($noMatchingWorker) === [], 'BA09', 'worker filter prevents returning another worker assignments');

scope_assert(expect_scope_exception(static fn() => scope_plan(100, []), DomainException::class), 'BA10', 'empty authorization scope fails closed before SQL execution');
scope_assert(expect_scope_exception(static fn() => scope_plan(100, [10, 10]), DomainException::class), 'BA11', 'duplicate/ambiguous authorization scope fails closed');
scope_assert(expect_scope_exception(static fn() => scope_plan(100, ['10']), DomainException::class), 'BA12', 'non-canonical authorization scope values fail closed');

scope_assert(ShiftAssignmentService::isValidCivilDate('2024-02-29'), 'BA13', 'valid leap-day civil date is accepted');
scope_assert(!ShiftAssignmentService::isValidCivilDate('2026-02-29'), 'BA14', 'nonexistent calendar date is rejected');
scope_assert(!ShiftAssignmentService::isValidCivilDate('2026-2-09'), 'BA15', 'non-canonical date shape is rejected');
scope_assert(expect_scope_exception(static fn() => scope_plan(100, [10], null, '2026-02-30'), InvalidArgumentException::class), 'BA16', 'nonexistent reference date is rejected rather than dropping the filter');
scope_assert(expect_scope_exception(static fn() => scope_plan(100, [10], null, 'not-a-date'), InvalidArgumentException::class), 'BA17', 'malformed reference date is rejected rather than broadening the query');

$datedPlan = scope_plan(100, [10, 20], null, '2026-10-28');
scope_assert(array_column($runPlan($datedPlan), 'id') === [2, 1], 'BA18', 'valid date applies effective interval filtering while preserving locality scope');
$historyPlan = scope_plan(100, [10], null, null);
scope_assert(strpos($historyPlan['sql'], 'valido_desde <=') === false, 'BA19', 'omitted date preserves historical-list semantics under authorized locality scope');

// Controller source checks ensure every HTTP request re-runs JWT/ACL and consumes the fresh server scope.
$controller = file_get_contents(__DIR__ . '/../modelo/v2/shift/ShiftSchedulingV2Controller.php');
$helper = file_get_contents(__DIR__ . '/../modelo/v2/Portal3JwtAuthHelper.php');
$handlerStart = strpos($controller, 'public function handleListAssignments(): void');
$handlerEnd = strpos($controller, 'public function handleGetEffectiveSchedule', $handlerStart ?: 0);
$handler = $handlerStart !== false && $handlerEnd !== false ? substr($controller, $handlerStart, $handlerEnd - $handlerStart) : '';
$authGate = strpos($handler, '$this->authorizeScheduleRead()');
$scopeRead = strpos($handler, "\$authz['authorized_stations']");
$queryCall = strpos($handler, 'listAssignments((int)$idPersonal, $queryStations');
scope_assert($handler !== '' && $authGate !== false && $scopeRead !== false && $queryCall !== false && $authGate < $scopeRead && $scopeRead < $queryCall, 'BA20', 'each request resolves fresh authorization before passing its scope to the assignment query');
scope_assert(strpos($controller, 'private function authorizeScheduleRead(): array') !== false
    && strpos($controller, 'return $this->authorizeSpecificCapability(ShiftSchedulingAuthorizationService::READ, true);') !== false,
    'BA21', 'read gate uses the approved schedule-specific capability rather than broad biometricos.leer');
scope_assert(strpos($controller, 'private function authorizeSpecificCapability(string $permission, bool $requireStationScope)') !== false
    && strpos($controller, 'Portal3JwtAuthHelper::validateToken($conexion, $authHeader)') !== false
    && strpos($controller, 'ShiftSchedulingAuthorizationService::authorize($conexion, $auth, $permission, $requireStationScope)') !== false,
    'BA25', 'specific capability gate revalidates JWT, permission and station scope on each request');
scope_assert(strpos($helper, "self::authorizeModuleByKey(\$conexion, \$user, 'biometricos', \$requiredPermission)") !== false, 'BA22', 'biometricos ACL delegates to the shared fresh locality resolver');
scope_assert(strpos($handler, "'INVALID_REFERENCE_DATE'") !== false && strpos($handler, 'ShiftAssignmentService::isValidCivilDate') !== false, 'BA23', 'HTTP handler rejects invalid civil dates with the request validation path');
scope_assert(strpos($handler, "'pagination_mode'") !== false
    && strpos($handler, 'ShiftAssignmentReadSnapshotService') !== false
    && strpos(file_get_contents(__DIR__ . '/../modelo/v2/shift/ShiftAssignmentReadSnapshotService.php'), 'READ_SNAPSHOT_MEMBERSHIP_CHANGED') !== false,
    'BA24', 'opt-in assignment snapshot reads expose immutable continuation with membership revalidation');

echo "BLOCK_A_SHIFT_ASSIGNMENT_SCOPE: " . ($passed + $failed) . " PASS: {$passed} FAIL: {$failed}\n";
exit($failed === 0 ? 0 : 1);
