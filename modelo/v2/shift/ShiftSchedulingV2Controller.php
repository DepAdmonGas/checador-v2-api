<?php
/**
 * Checador V2 — ShiftSchedulingV2Controller
 * Controller for handling Shift Scheduling HTTP REST API endpoints.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Portal3JwtAuthHelper.php';
require_once __DIR__ . '/ShiftTemplateService.php';
require_once __DIR__ . '/ShiftAssignmentService.php';
require_once __DIR__ . '/ScheduleResolutionService.php';
require_once __DIR__ . '/ScheduledShiftMaterializationService.php';

class ShiftSchedulingV2Controller
{
    private ShiftTemplateService $templateService;
    private ShiftAssignmentService $assignmentService;
    private ScheduleResolutionService $resolutionService;
    private ScheduledShiftMaterializationService $materializationService;

    public function __construct()
    {
        $this->templateService = new ShiftTemplateService();
        $this->assignmentService = new ShiftAssignmentService();
        $this->resolutionService = new ScheduleResolutionService();
        $this->materializationService = new ScheduledShiftMaterializationService();
    }

    /**
     * POST /api/v2/shift-templates
     */
    public function handleCreateTemplate(): void
    {
        $authz = Portal3JwtAuthHelper::authorizeUser($_SERVER, 'biometricos', 'editar');
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->sendJsonError('INVALID_TEMPLATE_SPECIFICATION', 'JSON payload required', 400);
            return;
        }

        try {
            $result = $this->templateService->createTemplate($payload);
            $this->sendJsonSuccess($result, 201);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonError('INVALID_TEMPLATE_SPECIFICATION', $e->getMessage(), 400);
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'DUPLICATE_TEMPLATE_VERSION') !== false) {
                $this->sendJsonError('DUPLICATE_TEMPLATE_VERSION', $e->getMessage(), 409);
            } else {
                $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
            }
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v2/shift-templates/{clave}/versions
     */
    public function handleCreateTemplateVersion(string $clave): void
    {
        $authz = Portal3JwtAuthHelper::authorizeUser($_SERVER, 'biometricos', 'editar');
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->sendJsonError('INVALID_TEMPLATE_SPECIFICATION', 'JSON payload required', 400);
            return;
        }

        try {
            $result = $this->templateService->createNewVersion($clave, $payload);
            $this->sendJsonSuccess($result, 201);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonError('INVALID_TEMPLATE_SPECIFICATION', $e->getMessage(), 400);
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'DUPLICATE_TEMPLATE_VERSION') !== false) {
                $this->sendJsonError('DUPLICATE_TEMPLATE_VERSION', $e->getMessage(), 409);
            } else {
                $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
            }
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v2/shift-templates
     */
    public function handleListTemplates(): void
    {
        $authz = Portal3JwtAuthHelper::authorizeUser($_SERVER, 'biometricos', 'leer');
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
        $includeAll = isset($_GET['include_all_versions']) && ($_GET['include_all_versions'] === 'true' || $_GET['include_all_versions'] === '1');

        try {
            $result = $this->templateService->listTemplates($page, $perPage, $includeAll);
            $this->sendJsonSuccess($result, 200);
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v2/shift-templates/{clave}/versions/{version}
     */
    public function handleGetTemplateVersion(string $clave, int $version): void
    {
        $authz = Portal3JwtAuthHelper::authorizeUser($_SERVER, 'biometricos', 'leer');
        try {
            $result = $this->templateService->getTemplateVersion($clave, $version);
            if ($result === null) {
                $this->sendJsonError('NOT_FOUND', "Template version not found", 404);
                return;
            }
            $this->sendJsonSuccess($result, 200);
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v2/shift-assignments
     */
    public function handleCreateAssignment(): void
    {
        $authz = Portal3JwtAuthHelper::authorizeUser($_SERVER, 'biometricos', 'editar');
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->sendJsonError('INVALID_ASSIGNMENT_SPECIFICATION', 'JSON payload required', 400);
            return;
        }

        $idEstacion = (int)($payload['id_estacion'] ?? 0);
        if (!in_array($idEstacion, $authz['authorized_stations'], true)) {
            $this->sendJsonError('UNAUTHORIZED_STATION_ACCESS', "Station ID {$idEstacion} is outside authorized ACL scope.", 403);
            return;
        }

        try {
            $result = $this->assignmentService->createAssignment($payload);
            $this->sendJsonSuccess($result, 201);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonError('INVALID_ASSIGNMENT_SPECIFICATION', $e->getMessage(), 400);
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'ASSIGNMENT_OVERLAP_CONFLICT') !== false) {
                $this->sendJsonError('ASSIGNMENT_OVERLAP_CONFLICT', $e->getMessage(), 409);
            } else {
                $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
            }
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v2/shift-assignments/{id}/close
     */
    public function handleCloseAssignment(int $assignmentId): void
    {
        $authz = Portal3JwtAuthHelper::authorizeUser($_SERVER, 'biometricos', 'editar');
        $payload = json_decode(file_get_contents('php://input'), true);
        $closeDate = is_array($payload) && isset($payload['effective_close_date']) ? $payload['effective_close_date'] : null;

        try {
            $result = $this->assignmentService->closeAssignment($assignmentId, $closeDate);
            $this->sendJsonSuccess($result, 200);
        } catch (InvalidArgumentException $e) {
            if (strpos($e->getMessage(), 'NOT_FOUND') !== false) {
                $this->sendJsonError('NOT_FOUND', $e->getMessage(), 404);
            } else {
                $this->sendJsonError('INVALID_EFFECTIVE_INTERVAL', $e->getMessage(), 400);
            }
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v2/shift-assignments
     */
    public function handleListAssignments(): void
    {
        $authz = Portal3JwtAuthHelper::authorizeUser($_SERVER, 'biometricos', 'leer');
        $idPersonal = isset($_GET['id_personal']) ? (int)$_GET['id_personal'] : 0;
        $idEstacion = isset($_GET['id_estacion']) ? (int)$_GET['id_estacion'] : null;
        $fechaRef = isset($_GET['fecha_referencia']) ? trim($_GET['fecha_referencia']) : null;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;

        if ($idPersonal <= 0) {
            $this->sendJsonError('INVALID_ASSIGNMENT_SPECIFICATION', 'id_personal is required.', 400);
            return;
        }

        if ($idEstacion !== null && !in_array($idEstacion, $authz['authorized_stations'], true)) {
            $this->sendJsonError('UNAUTHORIZED_STATION_ACCESS', "Station ID {$idEstacion} is outside authorized ACL scope.", 403);
            return;
        }

        try {
            $result = $this->assignmentService->listAssignments($idPersonal, $idEstacion, $fechaRef, $page, $perPage);
            $this->sendJsonSuccess($result, 200);
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v2/workers/{id_personal}/effective-schedule
     */
    public function handleGetEffectiveSchedule(int $idPersonal): void
    {
        $authz = Portal3JwtAuthHelper::authorizeUser($_SERVER, 'biometricos', 'leer');
        $idEstacion = isset($_GET['id_estacion']) ? (int)$_GET['id_estacion'] : 0;
        $fechaLocal = isset($_GET['fecha_local']) ? trim($_GET['fecha_local']) : '';

        if ($idEstacion <= 0 || empty($fechaLocal)) {
            $this->sendJsonError('INVALID_RESOLUTION_SPECIFICATION', 'id_estacion and fecha_local are required.', 400);
            return;
        }

        if (!in_array($idEstacion, $authz['authorized_stations'], true)) {
            $this->sendJsonError('UNAUTHORIZED_STATION_ACCESS', "Station ID {$idEstacion} is outside authorized ACL scope.", 403);
            return;
        }

        try {
            $result = $this->resolutionService->resolveSchedule($idPersonal, $idEstacion, $fechaLocal);
            $this->sendJsonSuccess($result, 200);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonError('INVALID_RESOLUTION_SPECIFICATION', $e->getMessage(), 400);
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v2/scheduled-shifts/materialize
     */
    public function handleMaterializeScheduledShifts(): void
    {
        $authz = Portal3JwtAuthHelper::authorizeUser($_SERVER, 'biometricos', 'editar');
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->sendJsonError('INVALID_MATERIALIZATION_SPECIFICATION', 'JSON payload required', 400);
            return;
        }

        $idPersonal = (int)($payload['id_personal'] ?? 0);
        $idEstacion = (int)($payload['id_estacion'] ?? 0);
        $fechaDesde = trim($payload['fecha_desde'] ?? '');
        $fechaHasta = trim($payload['fecha_hasta'] ?? '');

        if (!in_array($idEstacion, $authz['authorized_stations'], true)) {
            $this->sendJsonError('UNAUTHORIZED_STATION_ACCESS', "Station ID {$idEstacion} is outside authorized ACL scope.", 403);
            return;
        }

        try {
            $result = $this->materializationService->materializeShifts($idPersonal, $idEstacion, $fechaDesde, $fechaHasta);
            $this->sendJsonSuccess($result, 200);
        } catch (InvalidArgumentException $e) {
            if (strpos($e->getMessage(), 'BOUNDED_QUERY_VIOLATION') !== false) {
                $this->sendJsonError('BOUNDED_QUERY_VIOLATION', $e->getMessage(), 400);
            } else {
                $this->sendJsonError('INVALID_MATERIALIZATION_SPECIFICATION', $e->getMessage(), 400);
            }
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', $e->getMessage(), 500);
        }
    }

    private function sendJsonSuccess(array $data, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'success',
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function sendJsonError(string $code, string $message, int $httpCode): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
