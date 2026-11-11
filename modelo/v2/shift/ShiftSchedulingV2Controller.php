<?php
/**
 * Checador V2 — ShiftSchedulingV2Controller
 * Controller for handling Shift Scheduling HTTP REST API endpoints.
 */

declare(strict_types=1);

require_once __DIR__ . '/../Portal3JwtAuthHelper.php';
require_once __DIR__ . '/ShiftTemplateService.php';
require_once __DIR__ . '/ShiftAssignmentService.php';
require_once __DIR__ . '/ShiftAssignmentReadSnapshotService.php';
require_once __DIR__ . '/ShiftSchedulingAuthorizationService.php';
require_once __DIR__ . '/ShiftWorkerMembershipService.php';
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
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->sendJsonError('INVALID_TEMPLATE_SPECIFICATION', 'JSON payload required', 400);
            return;
        }

        $authInfo = $this->authorizeTemplateMutation($payload);
        $payload['scope'] = $authInfo['scope'];
        if ($authInfo['scope'] === 'STATION') {
            $payload['id_estacion'] = $authInfo['station_id'];
        }

        try {
            if (isset($payload['derived_from_global'])) {
                if ((int) ($authInfo['station_id'] ?? 0) === Portal3JwtAuthHelper::AUTOLAVADO_LOCALITY_ID
                    || $authInfo['scope'] !== 'STATION' || (int) ($authInfo['station_id'] ?? 0) < 1) {
                    $this->sendJsonError('FUNCTION_PERMISSION_DENIED', 'La personalización local requiere alcance de estación.', 403);
                    return;
                }
                $result = $this->templateService->createStationCustomization(
                    $payload,
                    $authInfo['user_id'],
                    (int) $authInfo['station_id']
                );
                $this->sendJsonSuccess($result['template'], !empty($result['created']) ? 201 : 200);
                return;
            }
            $result = $this->templateService->createTemplate($payload, $authInfo['user_id'], $authInfo['station_id']);
            $this->sendJsonSuccess($result, 201);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonError('INVALID_TEMPLATE_SPECIFICATION', $e->getMessage(), 400);
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'DUPLICATE_TEMPLATE_VERSION') !== false) {
                $this->sendJsonError('DUPLICATE_TEMPLATE_VERSION', $e->getMessage(), 409);
            } elseif (strpos($e->getMessage(), 'STATION_CUSTOMIZATION_SOURCE_CONFLICT') !== false) {
                $this->sendJsonError('STATION_CUSTOMIZATION_SOURCE_CONFLICT', 'La personalización existente conserva otra versión de origen.', 409);
            } elseif (strpos($e->getMessage(), 'STATION_CUSTOMIZATION_SOURCE_UNAVAILABLE') !== false) {
                $this->sendJsonError('STATION_CUSTOMIZATION_SOURCE_UNAVAILABLE', 'El turno de origen ya no está disponible para esta estación.', 404);
            } elseif (strpos($e->getMessage(), 'STATION_CUSTOMIZATION_SCHEMA_UNAVAILABLE') !== false) {
                $this->sendJsonError('STATION_CUSTOMIZATION_SCHEMA_UNAVAILABLE', 'La personalización local no está habilitada en este entorno.', 503);
            } elseif (strpos($e->getMessage(), 'CANNOT_SHADOW_GLOBAL_TEMPLATE') !== false) {
                $this->sendJsonError('CANNOT_SHADOW_GLOBAL_TEMPLATE', $e->getMessage(), 409);
            } else {
                $this->sendJsonError('SERVER_ERROR', 'Error interno al procesar plantilla de turno.', 500);
            }
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', 'Error interno al procesar plantilla de turno.', 500);
        }
    }

    /**
     * POST /api/v2/shift-templates/{clave}/versions
     */
    public function handleCreateTemplateVersion(string $clave): void
    {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->sendJsonError('INVALID_TEMPLATE_SPECIFICATION', 'JSON payload required', 400);
            return;
        }

        $authInfo = $this->authorizeTemplateMutation($payload);

        try {
            $result = $this->templateService->createNewVersion($clave, $payload, $authInfo['user_id'], $authInfo['station_id']);
            $this->sendJsonSuccess($result, 201);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonError('INVALID_TEMPLATE_SPECIFICATION', $e->getMessage(), 400);
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'DUPLICATE_TEMPLATE_VERSION') !== false) {
                $this->sendJsonError('DUPLICATE_TEMPLATE_VERSION', $e->getMessage(), 409);
            } else {
                $this->sendJsonError('SERVER_ERROR', 'Error interno al procesar versión de plantilla de turno.', 500);
            }
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', 'Error interno al procesar versión de plantilla de turno.', 500);
        }
    }

    /**
     * GET /api/v2/shift-templates
     */
    public function handleListTemplates(): void
    {
        $authz = $this->authorizeTemplateCatalogRead();
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
        $includeAll = isset($_GET['include_all_versions']) && ($_GET['include_all_versions'] === 'true' || $_GET['include_all_versions'] === '1');

        $templateLocalities = array_values(array_map('intval', $authz['authorized_template_localities'] ?? $authz['authorized_stations'] ?? []));
        $templateDepartments = array_values(array_map('intval', $authz['authorized_template_departments'] ?? []));
        $filterStationId = null;
        if (array_key_exists('station_id', $_GET)) {
            if (!is_numeric($_GET['station_id'])) { $this->sendJsonError('INVALID_STATION_ID', 'station_id must be a canonical locality ID.', 400); return; }
            $requested = (int) $_GET['station_id'];
            if ($requested < 1 || !in_array($requested, $templateLocalities, true)) {
                $this->sendJsonError('UNAUTHORIZED_STATION_ACCESS', 'Template catalog locality is outside authorized scope.', 403); return;
            }
            $filterStationId = $requested;
        } elseif (count($authz['authorized_stations'] ?? []) === 1) {
            $filterStationId = (int) $authz['authorized_stations'][0];
        }
        $localOnly = $filterStationId !== null && in_array($filterStationId, $templateDepartments, true);

        try {
            $result = $this->templateService->listTemplates($page, $perPage, $includeAll, $filterStationId, $localOnly);
            $this->sendJsonSuccess($result, 200);
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar plantillas de turno.', 500);
        }
    }

    /**
     * GET /api/v2/shift-templates/{clave}/versions/{version}
     */
    public function handleGetTemplateVersion(string $clave, int $version): void
    {
        $authz = $this->authorizeTemplateCatalogRead();
        try {
            $requestedStation = isset($_GET['station_id']) && is_numeric($_GET['station_id']) ? (int) $_GET['station_id'] : null;
            $allowedLocalities = array_values(array_map('intval', $authz['authorized_template_localities'] ?? $authz['authorized_stations'] ?? []));
            $allowedDepartments = array_values(array_map('intval', $authz['authorized_template_departments'] ?? []));
            if ($requestedStation !== null && !in_array($requestedStation, $allowedLocalities, true)) {
                $this->sendJsonError('UNAUTHORIZED_STATION_ACCESS', 'Template locality is outside authorized ACL scope.', 403);
                return;
            }
            if ($requestedStation === null && count($allowedLocalities) > 1
                && count($authz['authorized_stations'] ?? []) !== 1) {
                $this->sendJsonError('STATION_ID_REQUIRED', 'station_id is required for a multi-locality template read.', 400);
                return;
            }
            $filterStationId = $requestedStation ?? (count($authz['authorized_stations'] ?? []) === 1 ? (int) $authz['authorized_stations'][0] : null);
            $localOnly = $filterStationId !== null && in_array($filterStationId, $allowedDepartments, true);
            $result = $this->templateService->getTemplateVersion($clave, $version, $filterStationId, $localOnly);
            if ($result === null) {
                $this->sendJsonError('NOT_FOUND', "Template version not found", 404);
                return;
            }
            $this->sendJsonSuccess($result, 200);
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar versión de plantilla de turno.', 500);
        }
    }

    /**
     * POST /api/v2/shift-assignments
     */
    public function handleCreateAssignment(): void
    {
        $authz = $this->authorizeScheduleWrite();
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
            $actorUserId = (int)($authz['user_id'] ?? 0);
            $result = $this->assignmentService->createAssignment($payload, $actorUserId);
            $this->sendJsonSuccess($result, 201);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonError('INVALID_ASSIGNMENT_SPECIFICATION', $e->getMessage(), 400);
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'ASSIGNMENT_OVERLAP_CONFLICT') !== false) {
                $this->sendJsonError('ASSIGNMENT_OVERLAP_CONFLICT', $e->getMessage(), 409);
            } else {
                $this->sendJsonError('SERVER_ERROR', 'Error interno al crear asignación de turno.', 500);
            }
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', 'Error interno al crear asignación de turno.', 500);
        }
    }

    /**
     * POST /api/v2/shift-assignments/{id}/close
     */
    public function handleCloseAssignment(int $assignmentId): void
    {
        $authz = $this->authorizeScheduleWrite();
        $assignmentStation = $this->resolveAssignmentStation($assignmentId);
        if ($assignmentStation === null) {
            $this->sendJsonError('NOT_FOUND', "Assignment ID {$assignmentId} not found.", 404);
            return;
        }
        if (!in_array($assignmentStation, $authz['authorized_stations'], true)) {
            $this->sendJsonError('UNAUTHORIZED_STATION_ACCESS', "Assignment ID {$assignmentId} is outside authorized ACL scope.", 403);
            return;
        }
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->sendJsonError('INVALID_EFFECTIVE_INTERVAL', 'JSON payload required.', 400);
            return;
        }
        $closeDate = isset($payload['effective_close_date']) && is_string($payload['effective_close_date'])
            ? trim($payload['effective_close_date']) : null;
        $expected = null;
        if (array_key_exists('expected_valido_desde', $payload) && array_key_exists('expected_valido_hasta', $payload)) {
            $expected = [
                'valido_desde' => $payload['expected_valido_desde'],
                'valido_hasta' => $payload['expected_valido_hasta'],
            ];
        }

        try {
            $result = $this->assignmentService->closeAssignment($assignmentId, $closeDate, $expected);
            $this->sendJsonSuccess($result, 200);
        } catch (InvalidArgumentException $e) {
            if (strpos($e->getMessage(), 'NOT_FOUND') !== false) {
                $this->sendJsonError('NOT_FOUND', $e->getMessage(), 404);
            } else {
                $this->sendJsonError('INVALID_EFFECTIVE_INTERVAL', $e->getMessage(), 400);
            }
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'ASSIGNMENT_VERSION_CONFLICT') !== false) {
                $this->sendJsonError('ASSIGNMENT_VERSION_CONFLICT', 'La asignación cambió desde que se abrió; vuelve a consultar el historial.', 409);
            } else {
                $this->sendJsonError('SERVER_ERROR', 'Error interno al cerrar asignación de turno.', 500);
            }
        }
    }

    /** POST /api/v2/shift-assignments/{id}/replace: close and add successor atomically. */
    public function handleReplaceAssignment(int $assignmentId): void
    {
        $authz = $this->authorizeScheduleWrite();
        $identity = $this->resolveAssignmentIdentity($assignmentId);
        if ($identity === null) {
            $this->sendJsonError('NOT_FOUND', 'Assignment not found.', 404);
            return;
        }
        if (!in_array((int)$identity['id_estacion'], $authz['authorized_stations'], true)) {
            $this->sendJsonError('UNAUTHORIZED_STATION_ACCESS', 'La asignación está fuera del alcance autorizado.', 403);
            return;
        }
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)
            || !array_key_exists('expected_valido_desde', $payload)
            || !array_key_exists('expected_valido_hasta', $payload)
            || !isset($payload['effective_close_date']) || !is_string($payload['effective_close_date'])
            || !array_key_exists('id_plantilla', $payload)
            || !array_key_exists('valido_hasta', $payload)) {
            $this->sendJsonError('INVALID_ASSIGNMENT_SPECIFICATION', 'La solicitud de reemplazo no contiene la versión esperada ni la vigencia completa.', 400);
            return;
        }
        $templateId = $payload['id_plantilla'] === null ? null : filter_var($payload['id_plantilla'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($payload['id_plantilla'] !== null && $templateId === false) {
            $this->sendJsonError('INVALID_ASSIGNMENT_SPECIFICATION', 'La plantilla solicitada no es válida.', 400);
            return;
        }
        $replacementTo = $payload['valido_hasta'];
        if ($replacementTo !== null && !is_string($replacementTo)) {
            $this->sendJsonError('INVALID_ASSIGNMENT_SPECIFICATION', 'La vigencia final no es válida.', 400);
            return;
        }
        try {
            $result = $this->assignmentService->replaceAssignment(
                $assignmentId,
                trim($payload['effective_close_date']),
                ['id_plantilla' => $templateId === false ? null : ($templateId === null ? null : (int)$templateId), 'valido_hasta' => $replacementTo],
                ['valido_desde' => $payload['expected_valido_desde'], 'valido_hasta' => $payload['expected_valido_hasta']],
                (int) ($authz['user_id'] ?? 0)
            );
            $this->sendJsonSuccess($result, 200);
        } catch (InvalidArgumentException $e) {
            if (strpos($e->getMessage(), 'NOT_FOUND') !== false) {
                $this->sendJsonError('NOT_FOUND', 'Assignment not found.', 404);
            } elseif (strpos($e->getMessage(), 'WORKER_MEMBERSHIP_UNVERIFIED') !== false) {
                $this->sendJsonError('WORKER_MEMBERSHIP_UNVERIFIED', 'No se pudo acreditar la pertenencia durante la vigencia solicitada.', 403);
            } else {
                $this->sendJsonError('INVALID_EFFECTIVE_INTERVAL', $e->getMessage(), 400);
            }
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'ASSIGNMENT_OVERLAP_CONFLICT') !== false
                || strpos($e->getMessage(), 'ASSIGNMENT_VERSION_CONFLICT') !== false) {
                $code = strpos($e->getMessage(), 'ASSIGNMENT_OVERLAP_CONFLICT') !== false
                    ? 'ASSIGNMENT_OVERLAP_CONFLICT' : 'ASSIGNMENT_VERSION_CONFLICT';
                $this->sendJsonError($code, 'La programación cambió o entra en conflicto con otra asignación vigente.', 409);
            } elseif (strpos($e->getMessage(), 'WORKER_MEMBERSHIP_UNVERIFIED') !== false) {
                $this->sendJsonError('WORKER_MEMBERSHIP_UNVERIFIED', 'No se pudo acreditar la pertenencia durante la vigencia solicitada.', 403);
            } else {
                $this->sendJsonError('SERVER_ERROR', 'Error interno al sustituir la asignación de turno.', 500);
            }
        }
    }

    /**
     * GET /api/v2/shift-assignments
     */
    public function handleListAssignments(): void
    {
        $authz = $this->authorizeScheduleRead();
        $authorizedStations = $authz['authorized_stations'] ?? null;
        if (!is_array($authorizedStations) || $authorizedStations === [] || !array_is_list($authorizedStations)) {
            $this->sendJsonError('STATION_SCOPE_DENIED', 'No fue posible resolver localidades autorizadas.', 403);
            return;
        }
        $seenStations = [];
        foreach ($authorizedStations as $stationId) {
            if (!is_int($stationId) || $stationId <= 0 || isset($seenStations[$stationId])) {
                $this->sendJsonError('STATION_SCOPE_DENIED', 'El alcance de localidades autorizado es inválido o ambiguo.', 403);
                return;
            }
            $seenStations[$stationId] = true;
        }

        $workerValue = array_key_exists('id_personal', $_GET)
            ? $_GET['id_personal']
            : ($_GET['worker_id'] ?? null);
        $idPersonal = is_scalar($workerValue)
            ? filter_var((string)$workerValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        $stationValue = array_key_exists('id_estacion', $_GET)
            ? $_GET['id_estacion']
            : ($_GET['workplace_id'] ?? null);
        $idEstacion = null;
        if ($stationValue !== null) {
            $parsedStation = is_scalar($stationValue)
                ? filter_var((string)$stationValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            if ($parsedStation === false || !in_array((int)$parsedStation, $authorizedStations, true)) {
                $this->sendJsonError('UNAUTHORIZED_STATION_ACCESS', 'La localidad solicitada está fuera del alcance autorizado.', 403);
                return;
            }
            $idEstacion = (int)$parsedStation;
        }

        $dateValue = null;
        foreach (['fecha_referencia', 'date', 'reference_date'] as $dateKey) {
            if (array_key_exists($dateKey, $_GET)) {
                $dateValue = $_GET[$dateKey];
                break;
            }
        }
        $fechaRef = null;
        if ($dateValue !== null) {
            if (!is_scalar($dateValue) || !ShiftAssignmentService::isValidCivilDate(trim((string)$dateValue))) {
                $this->sendJsonError('INVALID_REFERENCE_DATE', 'fecha_referencia must be a valid YYYY-MM-DD civil date.', 400);
                return;
            }
            $fechaRef = trim((string)$dateValue);
        }
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;

        if ($idPersonal === false) {
            $this->sendJsonError('INVALID_ASSIGNMENT_SPECIFICATION', 'id_personal is required.', 400);
            return;
        }

        $paginationMode = $_GET['pagination_mode'] ?? null;
        $snapshotToken = trim((string) ($_SERVER['HTTP_X_READ_SNAPSHOT'] ?? ''));
        if ($paginationMode !== null || $snapshotToken !== '') {
            header('Cache-Control: private, no-store');
            header('Pragma: no-cache');
            if ($paginationMode !== 'snapshot' && $snapshotToken !== '') {
                $this->sendJsonError('READ_SNAPSHOT_INVALID_OR_EXPIRED', 'La lectura completa debe reiniciarse desde la primera página.', 409);
                return;
            }
            $allowedSnapshotKeys = ['id_personal', 'id_estacion', 'fecha_referencia', 'page', 'per_page', 'pagination_mode'];
            if ($paginationMode !== 'snapshot'
                || array_diff(array_keys($_GET), $allowedSnapshotKeys) !== []
                || array_key_exists('worker_id', $_GET)
                || array_key_exists('workplace_id', $_GET)
                || array_key_exists('date', $_GET)
                || array_key_exists('reference_date', $_GET)) {
                $this->sendJsonError('INVALID_ASSIGNMENT_QUERY', 'La consulta snapshot contiene filtros inválidos.', 400);
                return;
            }
            $pageRaw = $_GET['page'] ?? '1';
            $perPageRaw = $_GET['per_page'] ?? '50';
            $strictPage = is_scalar($pageRaw) ? filter_var((string) $pageRaw, FILTER_VALIDATE_INT) : false;
            $strictPerPage = is_scalar($perPageRaw) ? filter_var((string) $perPageRaw, FILTER_VALIDATE_INT) : false;
            if ($strictPage === false || $strictPerPage === false
                || $strictPage < 1 || $strictPage > 50 || $strictPerPage < 1 || $strictPerPage > 200) {
                $this->sendJsonError('INVALID_ASSIGNMENT_QUERY', 'page/per_page fuera de los límites permitidos.', 400);
                return;
            }
            $page = (int) $strictPage;
            $perPage = (int) $strictPerPage;
            try {
                $classBD = new BD();
                $snapshotDb = $classBD->conectarBD();
                $snapshotService = new ShiftAssignmentReadSnapshotService($snapshotDb);
                if ($snapshotToken === '') {
                    if ($page !== 1) {
                        throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_INVALID_OR_EXPIRED', 409);
                    }
                    $membershipStations = $this->resolveMembershipReadWorkplaces((int) $idPersonal, $authorizedStations, $idEstacion, $fechaRef);
                    $queryStations = $idEstacion !== null ? [$idEstacion] : $membershipStations;
                    if ($queryStations === []) {
                        throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_INVALID_OR_EXPIRED', 409);
                    }
                    $result = $snapshotService->start(
                        (int) ($authz['user_id'] ?? 0),
                        $authorizedStations,
                        (int) $idPersonal,
                        $idEstacion,
                        $fechaRef,
                        $queryStations,
                        $page,
                        $perPage
                    );
                } else {
                    $result = $snapshotService->continue(
                        $snapshotToken,
                        (int) ($authz['user_id'] ?? 0),
                        $authorizedStations,
                        (int) $idPersonal,
                        $idEstacion,
                        $fechaRef,
                        $page,
                        $perPage
                    );
                }
                $classBD->desconectarBD($snapshotDb);
                $this->sendJsonSuccess($result, 200);
            } catch (ShiftAssignmentReadSnapshotException $exception) {
                if (isset($snapshotDb) && $snapshotDb instanceof mysqli) {
                    (new BD())->desconectarBD($snapshotDb);
                }
                if ($exception->apiCode === 'READ_SNAPSHOT_LIMIT_REACHED' && $exception->retryAfterSeconds !== null) {
                    header('Retry-After: ' . $exception->retryAfterSeconds);
                }
                $this->sendJsonError($exception->apiCode, 'La lectura completa de asignaciones no pudo continuar; inicia una consulta nueva.', $exception->httpStatus);
            } catch (InvalidArgumentException $exception) {
                if (isset($snapshotDb) && $snapshotDb instanceof mysqli) {
                    (new BD())->desconectarBD($snapshotDb);
                }
                $this->sendJsonError('WORKER_MEMBERSHIP_UNVERIFIED', 'No se pudo acreditar la pertenencia del trabajador durante el periodo solicitado.', 403);
            } catch (Throwable $exception) {
                if (isset($snapshotDb) && $snapshotDb instanceof mysqli) {
                    (new BD())->desconectarBD($snapshotDb);
                }
                $this->sendJsonError('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 'La lectura completa de asignaciones no está disponible.', 503);
            }
            return;
        }

        try {
            $membershipStations = $this->resolveMembershipReadWorkplaces((int)$idPersonal, $authorizedStations, $idEstacion, $fechaRef);
            $queryStations = $idEstacion !== null ? [$idEstacion] : $membershipStations;
            if ($queryStations === []) {
                $this->sendJsonError('WORKER_MEMBERSHIP_UNVERIFIED', 'No existe una membresía canónica verificable del trabajador en el alcance solicitado.', 403);
                return;
            }
            $result = $this->assignmentService->listAssignments((int)$idPersonal, $queryStations, $idEstacion, $fechaRef, $page, $perPage);
            $membership = $this->membershipService();
            foreach ($result['items'] as $assignment) {
                if ($fechaRef !== null) {
                    $membership->assertLocalDateMembership((int)$idPersonal, (int)$assignment['id_estacion'], $fechaRef);
                } elseif ((int)($assignment['es_activo'] ?? 0) === 0
                    && ($assignment['valido_hasta'] ?? null) !== null
                    && (string)$assignment['valido_hasta'] === (string)$assignment['valido_desde']) {
                    // Explicitly cancelled empty intervals carry no schedule
                    // effect; retain them in history only when the worker has
                    // some verified history in that authorized workplace.
                    $membership->assertHistoryWithinScope((int)$idPersonal, (int)$assignment['id_estacion']);
                } else {
                    $membership->assertHistoryIntervalWithinScope(
                        (int)$idPersonal,
                        (int)$assignment['id_estacion'],
                        (string)$assignment['valido_desde'],
                        $assignment['valido_hasta'] !== null ? (string)$assignment['valido_hasta'] : null
                    );
                }
            }
            $this->sendJsonSuccess($result, 200);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonError('WORKER_MEMBERSHIP_UNVERIFIED', 'No se pudo acreditar la pertenencia del trabajador durante el periodo solicitado.', 403);
        } catch (RuntimeException $e) {
            $this->sendJsonError('MEMBERSHIP_DEPENDENCY_UNAVAILABLE', 'No se pudo verificar la vigencia laboral requerida para consultar horarios.', 503);
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', 'Error interno al consultar asignaciones de turno.', 500);
        }
    }

    /**
     * GET /api/v2/workers/{id_personal}/effective-schedule
     */
    public function handleGetEffectiveSchedule(int $idPersonal): void
    {
        $authz = $this->authorizeScheduleRead();
        $idEstacion = isset($_GET['id_estacion']) ? (int)$_GET['id_estacion'] : (isset($_GET['workplace_id']) ? (int)$_GET['workplace_id'] : 0);
        $fechaLocal = isset($_GET['fecha_local']) ? trim($_GET['fecha_local']) : (isset($_GET['date']) ? trim($_GET['date']) : '');

        if ($idEstacion <= 0 || empty($fechaLocal)) {
            $this->sendJsonError('INVALID_RESOLUTION_SPECIFICATION', 'id_estacion and fecha_local are required.', 400);
            return;
        }

        if (!in_array($idEstacion, $authz['authorized_stations'], true)) {
            $this->sendJsonError('UNAUTHORIZED_STATION_ACCESS', "Station ID {$idEstacion} is outside authorized ACL scope.", 403);
            return;
        }

        try {
            $this->membershipService()->assertLocalDateMembership($idPersonal, $idEstacion, $fechaLocal);
            $result = $this->resolutionService->resolveSchedule($idPersonal, $idEstacion, $fechaLocal);
            $this->sendJsonSuccess($result, 200);
        } catch (InvalidArgumentException $e) {
            if (strpos($e->getMessage(), 'WORKER_MEMBERSHIP_UNVERIFIED') !== false) {
                $this->sendJsonError('WORKER_MEMBERSHIP_UNVERIFIED', 'No se pudo acreditar la pertenencia del trabajador para la fecha solicitada.', 403);
            } else {
                $this->sendJsonError('INVALID_RESOLUTION_SPECIFICATION', $e->getMessage(), 400);
            }
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), 'AMBIGUOUS_SCHEDULE_ASSIGNMENT') !== false) {
                $this->sendJsonError('AMBIGUOUS_SCHEDULE_ASSIGNMENT', $e->getMessage(), 409);
            } else {
                $this->sendJsonError('SERVER_ERROR', 'Error interno al resolver horario efectivo.', 500);
            }
        } catch (Throwable $e) {
            $this->sendJsonError('SERVER_ERROR', 'Error interno al resolver horario efectivo.', 500);
        }
    }

    /**
     * POST /api/v2/scheduled-shifts/materialize
     */
    public function handleMaterializeScheduledShifts(): void
    {
        $authz = $this->authorizeBiometricosWrite();
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
            $this->sendJsonError('SERVER_ERROR', 'Error interno al materializar jornadas programadas.', 500);
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

    /** Authenticate and authorize M03 reading using its narrow scheduling capability. */
    private function authorizeScheduleRead(): array
    {
        return $this->authorizeSpecificCapability(ShiftSchedulingAuthorizationService::READ, true);
    }

    private function authorizeTemplateCatalogRead(): array
    {
        $classBD = new BD();
        $conexion = $classBD->conectarBD();
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($auth['status'], $auth['error'], $auth['code']);
        }

        $authz = ShiftSchedulingAuthorizationService::authorizeTemplateCatalogRead($conexion, $auth);
        $classBD->desconectarBD($conexion);
        if (!$authz['success']) {
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
        }

        return $authz;
    }

    /** Authenticate and authorize schedule assignment mutations only. */
    private function authorizeScheduleWrite(): array
    {
        return $this->authorizeSpecificCapability(ShiftSchedulingAuthorizationService::EDIT, true);
    }

    /** Global templates remain a separate RH/admin capability. */
    private function authorizeTemplateAdministration(): array
    {
        return $this->authorizeSpecificCapability(ShiftSchedulingAuthorizationService::TEMPLATE_ADMIN, false);
    }

    /** Materialization is a separate legacy operation and retains biometricos.editar. */
    private function authorizeBiometricosWrite(): array
    {
        $classBD = new BD();
        $conexion = $classBD->conectarBD();
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($auth['status'], $auth['error'], $auth['code']);
        }

        $authz = Portal3JwtAuthHelper::authorizeBiometricos($conexion, $auth, 'editar');
        $classBD->desconectarBD($conexion);
        if (!$authz['success']) {
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
        }

        return $authz;
    }

    /** Authorize template creation/versioning: Encargado is scoped strictly to home station; Global requires TEMPLATE_ADMIN. */
    private function authorizeTemplateMutation(array $payload): array
    {
        $classBD = new BD();
        $conexion = $classBD->conectarBD();
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($auth['status'], $auth['error'], $auth['code']);
            return [];
        }

        $positionId = (int)($auth['id_puesto'] ?? 0);
        $userId = (int)($auth['user_id'] ?? 0);
        $requestedScope = strtoupper(trim((string)($payload['scope'] ?? '')));
        if (!in_array($requestedScope, ['', 'GLOBAL', 'STATION'], true)) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('INVALID_TEMPLATE_SPECIFICATION', 'scope must be GLOBAL or STATION.', 400);
            return [];
        }

        $requestedLocality = null;
        if (array_key_exists('id_estacion', $payload)) {
            $localityId = filter_var($payload['id_estacion'], FILTER_VALIDATE_INT);
            if ($localityId === false || $localityId < 1) {
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('INVALID_TEMPLATE_SPECIFICATION', 'id_estacion must be a positive canonical locality ID.', 400);
                return [];
            }
            $requestedLocality = $localityId;
        }

        if ($positionId === Portal3JwtAuthHelper::DIRECCION_OPERACIONES_PUESTO_ID
            && $requestedScope === 'STATION'
            && $requestedLocality === Portal3JwtAuthHelper::AUTOLAVADO_LOCALITY_ID) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('FUNCTION_PERMISSION_DENIED', 'Dirección conserva acceso de consulta a Autolavado.', 403);
            return [];
        }

        // Global template administrator path: explicit station writes use the separate station capability.
        $stationWriteRequested = false;
        if ($requestedScope === 'STATION') {
            $stationWriteRequested = true;
        }

        if ($positionId === Portal3JwtAuthHelper::ENCARGADO_PUESTO_ID) {
            if ($requestedScope === 'GLOBAL') {
                $classBD->desconectarBD($conexion);
                $this->sendJsonError('FUNCTION_PERMISSION_DENIED', 'Encargado no tiene permiso para administrar plantillas globales.', 403);
                return [];
            }
            // Preserve the existing station-local default for Encargado when scope is omitted.
            $stationWriteRequested = true;
        }

        if ($stationWriteRequested) {
            $authz = ShiftSchedulingAuthorizationService::authorizeStationTemplateMutation($conexion, $auth);
            $classBD->desconectarBD($conexion);
            if (!$authz['success']) {
                $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
                return [];
            }

            $authorizedTemplateLocalities = array_values(array_map(
                'intval',
                $authz['authorized_template_localities'] ?? $authz['authorized_stations'] ?? []
            ));
            $authorizedDepartments = array_values(array_map(
                'intval',
                $authz['authorized_template_departments'] ?? []
            ));
            $physicalStations = array_values(array_map(
                'intval',
                $authz['authorized_stations'] ?? []
            ));
            $stationId = $requestedLocality
                ?? (count($physicalStations) === 1 ? $physicalStations[0] : null);

            if ($stationId === null
                || !in_array($stationId, $authorizedTemplateLocalities, true)
                || ($stationId === Portal3JwtAuthHelper::AUTOLAVADO_LOCALITY_ID
                    && !in_array($stationId, $authorizedDepartments, true))) {
                $this->sendJsonError('STATION_SCOPE_DENIED', 'La localidad solicitada no está autorizada para administrar plantillas.', 403);
                return [];
            }

            return [
                'user_id' => $userId,
                'position_id' => $positionId,
                'scope' => 'STATION',
                'station_id' => $stationId,
            ];
        }

        // Only the GLOBAL catalog is authorized by checador.plantillas.administrar.
        $authz = ShiftSchedulingAuthorizationService::authorize(
            $conexion,
            $auth,
            ShiftSchedulingAuthorizationService::TEMPLATE_ADMIN,
            false
        );
        $classBD->desconectarBD($conexion);
        if (!$authz['success']) {
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
            return [];
        }

        return [
            'user_id' => $userId,
            'position_id' => $positionId,
            'scope' => 'GLOBAL',
            'station_id' => null,
        ];
    }

    private function authorizeSpecificCapability(string $permission, bool $requireStationScope): array
    {
        $classBD = new BD();
        $conexion = $classBD->conectarBD();
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError($auth['status'], $auth['error'], $auth['code']);
        }
        $authz = ShiftSchedulingAuthorizationService::authorize($conexion, $auth, $permission, $requireStationScope);
        $classBD->desconectarBD($conexion);
        if (!$authz['success']) {
            $this->sendJsonError($authz['status'], $authz['error'], $authz['code']);
        }
        $authz['user_id'] = (int)($auth['user_id'] ?? 0);
        return $authz;
    }

    /** Resolve the assignment workplace before a close mutation is allowed. */
    private function resolveAssignmentStation(int $assignmentId): ?int
    {
        $identity = $this->resolveAssignmentIdentity($assignmentId);
        return $identity === null ? null : (int)$identity['id_estacion'];
    }

    private function resolveAssignmentIdentity(int $assignmentId): ?array
    {
        $classBD = new BD();
        $conexion = $classBD->conectarBD();
        $stmt = $conexion->prepare('SELECT id_estacion, id_personal, dia_semana FROM op_rh_v2_turnos_asignaciones WHERE id = ? LIMIT 1');
        if (!$stmt) {
            $classBD->desconectarBD($conexion);
            $this->sendJsonError('SERVER_ERROR', 'Error interno al resolver alcance de asignación.', 500);
        }
        $stmt->bind_param('i', $assignmentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $classBD->desconectarBD($conexion);
        return $row ? [
            'id_estacion' => (int)$row['id_estacion'],
            'id_personal' => (int)$row['id_personal'],
            'dia_semana' => (string)$row['dia_semana'],
        ] : null;
    }

    /** @return list<int> */
    private function resolveMembershipReadWorkplaces(int $workerId, array $authorizedStations, ?int $requestedStation, ?string $referenceDate): array
    {
        if ($requestedStation !== null && !in_array($requestedStation, $authorizedStations, true)) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: Requested station is outside schedule scope.');
        }
        $membership = $this->membershipService();
        if ($referenceDate === null) {
            $verified = array_values(array_intersect($membership->verifiedWorkplaceHistory($workerId), $authorizedStations));
            if ($requestedStation !== null) {
                $verified = in_array($requestedStation, $verified, true) ? [$requestedStation] : [];
            }
            if ($verified === []) {
                throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: No verified history in the authorized workplace scope.');
            }
            return $verified;
        }

        $candidates = $requestedStation !== null ? [$requestedStation] : $authorizedStations;
        $verified = [];
        foreach ($candidates as $candidate) {
            try {
                $membership->assertLocalDateMembership($workerId, $candidate, $referenceDate);
                $verified[] = $candidate;
            } catch (InvalidArgumentException) {
                // A missing worker membership at one of Direction's authorized
                // stations does not invalidate a different verified station.
            }
        }
        return array_values(array_unique($verified));
    }

    private function membershipService(): ShiftWorkerMembershipService
    {
        $classBD = new BD();
        $connection = $classBD->conectarBD();
        return new ShiftWorkerMembershipService($connection);
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
