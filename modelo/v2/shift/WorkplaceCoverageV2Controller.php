<?php

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';
require_once __DIR__ . '/../Portal3JwtAuthHelper.php';
require_once __DIR__ . '/WorkplaceCoveragePolicy.php';
require_once __DIR__ . '/WorkplaceCoverageAuthorizationService.php';
require_once __DIR__ . '/WorkplaceCoverageService.php';
require_once __DIR__ . '/ShiftSchedulingAuthorizationService.php';

/** HTTP boundary for the approved but disabled Workplace Coverage API. */
final class WorkplaceCoverageV2Controller
{
    private ?BD $bd = null;
    private ?mysqli $db = null;
    private array $user = [];

    public function handleCreate(): void
    {
        $this->authenticate();
        $this->respondError('COVERAGE_WRITER_PORTAL3_ONLY', 'Coverage mutations are handled by Portal3.', 410);
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($payload)) $this->respondError('INVALID_COVERAGE_REQUEST', 'JSON payload required.', 400);
        $home = filter_var($payload['id_home_workplace'] ?? null, FILTER_VALIDATE_INT);
        $coverage = filter_var($payload['id_coverage_workplace'] ?? null, FILTER_VALIDATE_INT);
        if ($home === false || $home < 1 || $coverage === false || $coverage < 1) {
            $this->respondError('INVALID_COVERAGE_REQUEST', 'Canonical home and coverage Workplaces are required.', 400);
        }
        $this->authorize(WorkplaceCoveragePolicy::REQUEST, (int)$home, (int)$coverage);
        try {
            $result = (new WorkplaceCoverageService($this->db))->createRequest(
                (int)$this->user['user_id'],
                $payload,
                (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')
            );
            $this->respondSuccess($result['coverage'], !empty($result['created']) ? 201 : 200);
        } catch (Throwable $e) {
            $this->respondCaught($e);
        }
    }

    public function handleList(): void
    {
        $this->authenticate();
        $this->authorizeActor(WorkplaceCoveragePolicy::READ);
        $limit = isset($_GET['limit']) ? filter_var($_GET['limit'], FILTER_VALIDATE_INT) : 20;
        $snapshot = isset($_GET['snapshot_id']) ? filter_var($_GET['snapshot_id'], FILTER_VALIDATE_INT) : null;
        $before = isset($_GET['before_id']) ? filter_var($_GET['before_id'], FILTER_VALIDATE_INT) : null;
        if ($limit === false || $snapshot === false || $before === false) {
            $this->respondError('INVALID_COVERAGE_PAGE', 'Invalid pagination values.', 400);
        }
        try {
            $result = (new WorkplaceCoverageService($this->db))->listVisible(
                (int)$this->user['user_id'],
                (int)$this->user['id_puesto'],
                $this->authorizedWorkplaces(),
                (int)$limit,
                $snapshot,
                $before
            );
            $this->respondSuccess($result);
        } catch (Throwable $e) {
            $this->respondCaught($e);
        }
    }

    public function handleDetail(string $coverageUuid): void
    {
        $this->authenticate();
        $this->authorizeActor(WorkplaceCoveragePolicy::READ);
        try {
            $result = (new WorkplaceCoverageService($this->db))->getVisible(
                $coverageUuid,
                (int)$this->user['user_id'],
                (int)$this->user['id_puesto'],
                $this->authorizedWorkplaces()
            );
            if ($result === null) $this->respondError('NOT_FOUND', 'Coverage not found.', 404);
            $this->respondSuccess($result);
        } catch (Throwable $e) {
            $this->respondCaught($e);
        }
    }

    public function handleTransition(string $coverageUuid, string $action): void
    {
        $this->authenticate();
        $this->respondError('COVERAGE_WRITER_PORTAL3_ONLY', 'Coverage mutations are handled by Portal3.', 410);
        $capability = match (strtoupper($action)) {
            'AUTHORIZE', 'REJECT' => WorkplaceCoveragePolicy::APPROVE,
            'CANCEL' => WorkplaceCoveragePolicy::CANCEL,
            default => '',
        };
        if ($capability === '') $this->respondError('NOT_FOUND', 'Route not found.', 404);
        $actorAuth = WorkplaceCoverageAuthorizationService::authorizeActor($this->db, $this->user, $capability);
        if (empty($actorAuth['success'])) $this->respondError($actorAuth['status'], 'Operation is not authorized.', (int)$actorAuth['code']);
        try {
            $service = new WorkplaceCoverageService($this->db);
            $scope = $service->transitionScope($coverageUuid);
            if ($scope === null) $this->respondError('NOT_FOUND', 'Coverage not found.', 404);
            $this->authorize($capability, (int)$scope['id_home_workplace'], (int)$scope['id_coverage_workplace']);
            $payload = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($payload)) $this->respondError('INVALID_COVERAGE_TRANSITION', 'JSON payload required.', 400);
            $result = $service->transition(
                $coverageUuid,
                strtoupper($action),
                (int)$this->user['user_id'],
                (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''),
                (string)($payload['motivo'] ?? '')
            );
            $this->respondSuccess($result['coverage'], !empty($result['created']) ? 201 : 200);
        } catch (Throwable $e) {
            $this->respondCaught($e);
        }
    }

    /** Opt-in extension; the ordinary effective-schedule route remains untouched. */
    public function handleWorkerEffectiveSchedule(int $workerId): void
    {
        $this->authenticate();
        $authz = ShiftSchedulingAuthorizationService::authorize(
            $this->db,
            $this->user,
            ShiftSchedulingAuthorizationService::READ,
            true
        );
        if (empty($authz['success'])) $this->respondError($authz['status'], $authz['error'], (int)$authz['code']);
        $date = trim((string)($_GET['fecha_local'] ?? ''));
        if ($date === '' || isset($_GET['id_estacion']) || isset($_GET['workplace_id'])) {
            $this->respondError('INVALID_RESOLUTION_SPECIFICATION', 'WORKER_EFFECTIVE requires fecha_local and does not accept an explicit station.', 400);
        }
        try {
            $result = (new WorkplaceCoverageService($this->db))->resolveWorkerEffective($workerId, $date, $this->user);
            $home = (int)($result['home_workplace_id'] ?? $result['id_estacion'] ?? 0);
            if ($home < 1 || !in_array($home, array_map('intval', $authz['authorized_stations'] ?? []), true)) {
                $this->respondError('STATION_SCOPE_DENIED', 'Worker is outside authorized scope.', 403);
            }
            $effective = (int)($result['effective_workplace_id'] ?? $home);
            if ($effective !== $home && !in_array($effective, array_map('intval', $authz['authorized_stations'] ?? []), true)) {
                $this->respondError('STATION_SCOPE_DENIED', 'Effective Workplace is outside authorized scope.', 403);
            }
            $this->respondSuccess($result);
        } catch (Throwable $e) {
            $this->respondCaught($e);
        }
    }

    private function authenticate(): void
    {
        $this->bd = new BD();
        $this->db = $this->bd->conectarBD();
        if (!$this->db instanceof mysqli) $this->respondError('AUTH_DEPENDENCY_UNAVAILABLE', 'API authentication dependency unavailable.', 503);
        $auth = Portal3JwtAuthHelper::validateToken($this->db, (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (empty($auth['valid'])) $this->respondError((string)($auth['status'] ?? 'UNAUTHORIZED'), (string)($auth['error'] ?? 'Authentication required.'), (int)($auth['code'] ?? 401));
        $this->user = $auth;
    }

    private function authorize(string $capability, ?int $home, ?int $coverage): void
    {
        $result = WorkplaceCoverageAuthorizationService::authorize($this->db, $this->user, $capability, $home, $coverage);
        if (empty($result['success'])) $this->respondError((string)$result['status'], 'Operation is not authorized for this Workplace scope.', (int)$result['code']);
    }

    private function authorizeActor(string $capability): void
    {
        $result = WorkplaceCoverageAuthorizationService::authorizeActor($this->db, $this->user, $capability);
        if (empty($result['success'])) $this->respondError((string)$result['status'], 'Operation is not authorized.', (int)$result['code']);
    }

    private function authorizedWorkplaces(): array
    {
        $result = ShiftSchedulingAuthorizationService::resolveStationScope($this->db, $this->user);
        return array_values(array_unique(array_map('intval', $result)));
    }

    private function respondCaught(Throwable $e): void
    {
        $message = $e->getMessage();
        if (str_starts_with($message, 'WORKER_MEMBERSHIP_UNVERIFIED')) $message = 'WORKER_MEMBERSHIP_UNVERIFIED';
        if (str_starts_with($message, 'WORKER_MEMBERSHIP_DEPENDENCY_UNAVAILABLE')) $message = 'WORKPLACE_COVERAGE_SCHEMA_UNAVAILABLE';
        $map = [
            'FEATURE_PERMISSION_DENIED' => [403, 'FUNCTION_PERMISSION_DENIED'],
            'FUNCTION_PERMISSION_DENIED' => [403, 'FUNCTION_PERMISSION_DENIED'],
            'STATION_SCOPE_DENIED' => [403, 'STATION_SCOPE_DENIED'],
            'COVERAGE_SCOPE_DENIED' => [403, 'STATION_SCOPE_DENIED'],
            'WORKER_MEMBERSHIP_UNVERIFIED' => [403, 'WORKER_MEMBERSHIP_UNVERIFIED'],
            'WORKPLACE_MEMBERSHIP_UNVERIFIED' => [403, 'WORKER_MEMBERSHIP_UNVERIFIED'],
            'WORKER_MEMBERSHIP_VERSION_MISMATCH' => [409, 'WORKER_MEMBERSHIP_VERSION_MISMATCH'],
            'WORKER_NOT_ACTIVE' => [404, 'WORKER_NOT_ACTIVE'],
            'WORKPLACE_NOT_FOUND' => [404, 'WORKPLACE_NOT_FOUND'],
            'COVERAGE_NOT_FOUND' => [404, 'NOT_FOUND'],
            'INVALID_COVERAGE_UUID' => [400, 'INVALID_COVERAGE_UUID'],
            'INVALID_COVERAGE_REQUEST' => [400, 'INVALID_COVERAGE_REQUEST'],
            'INVALID_COVERAGE_SCHEDULE' => [400, 'INVALID_COVERAGE_SCHEDULE'],
            'INVALID_COVERAGE_TRANSITION' => [400, 'INVALID_COVERAGE_TRANSITION'],
            'INVALID_COVERAGE_PAGE' => [400, 'INVALID_COVERAGE_PAGE'],
            'IDEMPOTENCY_KEY_REQUIRED' => [400, 'IDEMPOTENCY_KEY_REQUIRED'],
            'COVERAGE_REASON_REQUIRED' => [422, 'COVERAGE_REASON_REQUIRED'],
            'COVERAGE_NOT_FUTURE' => [422, 'COVERAGE_NOT_FUTURE'],
            'WORKPLACE_TIMEZONE_UNRESOLVED' => [503, 'WORKPLACE_TIMEZONE_UNRESOLVED'],
            'COVERAGE_TEMPLATE_UNAVAILABLE' => [422, 'COVERAGE_TEMPLATE_UNAVAILABLE'],
            'COVERAGE_CONSOLIDATED_DATE_CONFLICT' => [409, 'COVERAGE_CONSOLIDATED_DATE_CONFLICT'],
            'COVERAGE_NO_FUTURE_SEGMENT' => [409, 'COVERAGE_NO_FUTURE_SEGMENT'],
            'COVERAGE_ASSIGNMENT_CONFLICT' => [409, 'COVERAGE_ASSIGNMENT_CONFLICT'],
            'COVERAGE_OVERLAP_CONFLICT' => [409, 'COVERAGE_OVERLAP_CONFLICT'],
            'COVERAGE_STATE_CONFLICT' => [409, 'COVERAGE_STATE_CONFLICT'],
            'IDEMPOTENCY_KEY_CONFLICT' => [409, 'IDEMPOTENCY_KEY_CONFLICT'],
            'COVERAGE_CONFLICT' => [409, 'COVERAGE_CONFLICT'],
            'COVERAGE_WRITER_PORTAL3_ONLY' => [410, 'COVERAGE_WRITER_PORTAL3_ONLY'],
            'WORKPLACE_COVERAGE_FEATURE_DISABLED' => [503, 'FEATURE_DISABLED'],
            'WORKPLACE_COVERAGE_SCHEMA_UNAVAILABLE' => [503, 'SCHEMA_UNAVAILABLE'],
            'AUTH_DEPENDENCY_UNAVAILABLE' => [503, 'AUTH_DEPENDENCY_UNAVAILABLE'],
        ];
        $key = strtoupper(trim($message));
        [$status, $code] = $map[$key] ?? [500, 'SERVER_ERROR'];
        $public = in_array($status, [400, 403, 404, 409, 422, 503], true)
            ? 'The coverage operation could not be completed.'
            : 'Internal API error.';
        $this->respondError($code, $public, $status);
    }

    private function respondSuccess(array $data, int $httpStatus = 200): never
    {
        $this->respond(['status' => 'success', 'data' => $data], $httpStatus);
    }

    private function respondError(string $code, string $message, int $httpStatus): never
    {
        $this->respond(['code' => $code, 'status' => 'error', 'mensaje' => $message], $httpStatus);
    }

    private function respond(array $payload, int $httpStatus): never
    {
        if ($this->bd !== null && $this->db instanceof mysqli) $this->bd->desconectarBD($this->db);
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
