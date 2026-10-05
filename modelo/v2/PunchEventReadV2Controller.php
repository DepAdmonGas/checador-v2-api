<?php
include_once __DIR__ . "/../BD.php";
include_once __DIR__ . "/Portal3JwtAuthHelper.php";
include_once __DIR__ . "/PunchEventIntegrityService.php";

class PunchEventReadV2Controller {
    private const BIOMETRICOS_MODULE = 'biometricos';
    private const MAX_PAGE_SIZE = 200;
    private const DEFAULT_PAGE_SIZE = 50;
    private const MAX_DATE_RANGE_SECONDS = 2678400; // 31 días exactos (31 * 86400)

    public function consultarMarcacionesCrudas() {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        // 1. Autenticar Token JWT de Portal3
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            http_response_code($auth['code']);
            echo json_encode(array(
                "code" => $auth['code'],
                "status" => $auth['status'],
                "mensaje" => $auth['error']
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        // 2. Autorizar permiso funcional 'leer' sobre el módulo 'biometricos' y resolver scope de estaciones
        $authz = $this->autorizarAccesoBiometricos($conexion, $auth, 'leer');
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            http_response_code($authz['code']);
            echo json_encode(array(
                "code" => $authz['code'],
                "status" => $authz['status'],
                "mensaje" => $authz['error']
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        $authorizedStationIds = $authz['authorized_stations']; // Array de IDs en op_rh_localidades

        // 3. Validar Parámetros de Consulta (from, to, station_id, external_worker_reference, device_id, capture_method, page, per_page)
        $stationIdParam = isset($_GET['station_id']) && ctype_digit((string)$_GET['station_id']) ? (int)$_GET['station_id'] : null;
        if ($stationIdParam !== null) {
            if (!in_array($stationIdParam, $authorizedStationIds, true)) {
                $classBD->desconectarBD($conexion);
                http_response_code(403);
                echo json_encode(array(
                    "code" => 403,
                    "status" => "STATION_SCOPE_DENIED",
                    "mensaje" => "Acceso denegado: la estación solicitada no está autorizada para su perfil."
                ), JSON_UNESCAPED_UNICODE);
                return;
            }
            $effectiveStations = [$stationIdParam];
        } else {
            $effectiveStations = $authorizedStationIds;
        }

        // Rango de fechas por omisión (últimos 30 días si ausentes)
        $nowUtc = gmdate('Y-m-d H:i:s');
        $defaultFrom = gmdate('Y-m-d H:i:s', strtotime('-30 days'));

        $fromParam = isset($_GET['from']) ? trim($_GET['from']) : $defaultFrom;
        $toParam = isset($_GET['to']) ? trim($_GET['to']) : $nowUtc;

        $fromUtc = $this->parseAndFormatIsoUtc($fromParam);
        $toUtc = $this->parseAndFormatIsoUtc($toParam);

        if ($fromUtc === null || $toUtc === null || strtotime($fromUtc) > strtotime($toUtc)) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array(
                "code" => 400,
                "status" => "BAD_REQUEST",
                "mensaje" => "Rango de fechas inválido (formato ISO-8601 o Y-m-d H:i:s UTC requerido, from <= to)."
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        $durationSeconds = strtotime($toUtc) - strtotime($fromUtc);
        if ($durationSeconds > self::MAX_DATE_RANGE_SECONDS) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array(
                "code" => 400,
                "status" => "DATE_RANGE_TOO_LARGE",
                "mensaje" => "El rango de consulta excede la ventana máxima permitida de 31 días (2678400 segundos)."
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        $workerRef = isset($_GET['external_worker_reference']) ? trim($_GET['external_worker_reference']) : null;
        $deviceId = isset($_GET['device_id']) && ctype_digit((string)$_GET['device_id']) ? (int)$_GET['device_id'] : null;
        $captureMethod = isset($_GET['capture_method']) ? trim($_GET['capture_method']) : null;

        $page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? (int)$_GET['page'] : 1;
        $perPage = isset($_GET['per_page']) && ctype_digit((string)$_GET['per_page']) ? (int)$_GET['per_page'] : self::DEFAULT_PAGE_SIZE;

        if ($page < 1 || $perPage < 1 || $perPage > self::MAX_PAGE_SIZE) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array(
                "code" => 400,
                "status" => "BAD_REQUEST",
                "mensaje" => "Paginación inválida (page >= 1, 1 <= per_page <= 200)."
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        // 4. Construcción segura de consulta WHERE con Prepared Statements
        $whereClause = "WHERE id_estacion_resuelta IN (" . implode(',', array_fill(0, count($effectiveStations), '?')) . ")";
        $bindTypes = str_repeat('i', count($effectiveStations));
        $bindParams = $effectiveStations;

        $whereClause .= " AND ocurrido_at_utc >= ? AND ocurrido_at_utc <= ?";
        $bindTypes .= "ss";
        $bindParams[] = $fromUtc;
        $bindParams[] = $toUtc;

        if ($workerRef !== null && $workerRef !== '') {
            $whereClause .= " AND referencia_externa_personal = ?";
            $bindTypes .= "s";
            $bindParams[] = $workerRef;
        }

        if ($deviceId !== null) {
            $whereClause .= " AND id_dispositivo = ?";
            $bindTypes .= "i";
            $bindParams[] = $deviceId;
        }

        if ($captureMethod !== null && $captureMethod !== '') {
            $whereClause .= " AND metodo_captura = ?";
            $bindTypes .= "s";
            $bindParams[] = $captureMethod;
        }

        // 5. Conteo Total (COUNT)
        $sqlCount = "SELECT COUNT(*) as total FROM op_rh_v2_marcaciones_crudas $whereClause";
        $stmtCount = $conexion->prepare($sqlCount);
        if (!$stmtCount) {
            $classBD->desconectarBD($conexion);
            http_response_code(500);
            echo json_encode(array("code" => 500, "status" => "INTERNAL_ERROR", "mensaje" => "Error al preparar conteo."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $bindArgsCount = [$bindTypes];
        foreach ($bindParams as &$paramRef) {
            $bindArgsCount[] = &$paramRef;
        }
        unset($paramRef);

        call_user_func_array([$stmtCount, 'bind_param'], $bindArgsCount);
        $stmtCount->execute();
        $resCount = $stmtCount->get_result()->fetch_assoc();
        $stmtCount->close();

        $totalRecords = (int)($resCount['total'] ?? 0);
        $totalPages = $totalRecords > 0 ? (int)ceil($totalRecords / $perPage) : 0;

        // 6. Consulta de Registros (SELECT) con Paginación y Ordenamiento Determinista
        $offset = ($page - 1) * $perPage;
        $sqlData = "SELECT * FROM op_rh_v2_marcaciones_crudas $whereClause ORDER BY ocurrido_at_utc ASC, id ASC LIMIT ? OFFSET ?";

        $bindTypesData = $bindTypes . "ii";
        $bindParamsData = array_merge($bindParams, [$perPage, $offset]);

        $stmtData = $conexion->prepare($sqlData);
        if (!$stmtData) {
            $classBD->desconectarBD($conexion);
            http_response_code(500);
            echo json_encode(array("code" => 500, "status" => "INTERNAL_ERROR", "mensaje" => "Error al preparar consulta de datos."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $bindArgsData = [$bindTypesData];
        foreach ($bindParamsData as &$paramRef) {
            $bindArgsData[] = &$paramRef;
        }
        unset($paramRef);

        call_user_func_array([$stmtData, 'bind_param'], $bindArgsData);
        $stmtData->execute();
        $result = $stmtData->get_result();

        // 7. Cargar KeyRing para Verificación Dinámica de Integridad
        $keyRingJson = getenv('RAW_PUNCH_KEY_RING') ?: null;
        $keyRing = [];
        if ($keyRingJson) {
            $decoded = json_decode($keyRingJson, true);
            if (is_array($decoded)) {
                $keyRing = $decoded;
            }
        }

        $dataRows = [];
        while ($row = $result->fetch_assoc()) {
            $integrityStatus = PunchEventIntegrityService::verify($row, $keyRing);

            $valFlags = json_decode($row['validation_flags'] ?? '[]', true);
            if (!is_array($valFlags)) $valFlags = [];

            $meta = json_decode($row['metadata_json'] ?? '{}', true);
            if (!is_array($meta)) $meta = [];

            $dataRows[] = array(
                "event_uuid" => $row['event_uuid'],
                "id_dispositivo" => (int)$row['id_dispositivo'],
                "id_estacion_resuelta" => $row['id_estacion_resuelta'] !== null ? (int)$row['id_estacion_resuelta'] : null,
                "referencia_externa_personal" => $row['referencia_externa_personal'],
                "id_personal_resuelto" => $row['id_personal_resuelto'] !== null ? (int)$row['id_personal_resuelto'] : null,
                "source_timestamp_raw" => $row['source_timestamp_raw'],
                "source_timezone_reported" => $row['source_timezone_reported'],
                "effective_timezone" => $row['effective_timezone'],
                "timezone_fallback_applied" => (int)$row['timezone_fallback_applied'],
                "ocurrido_at_utc" => $row['ocurrido_at_utc'],
                "recibido_at_utc" => $row['recibido_at_utc'],
                "metodo_captura" => $row['metodo_captura'],
                "external_event_id" => $row['external_event_id'],
                "secuencia_dispositivo" => $row['secuencia_dispositivo'] !== null ? (int)$row['secuencia_dispositivo'] : null,
                "processing_status" => $row['processing_status'],
                "validation_flags" => $valFlags,
                "metadata" => $meta,
                "integrity" => array(
                    "version" => $row['integrity_version'],
                    "key_id" => $row['integrity_key_id'],
                    "status" => $integrityStatus
                )
            );
        }
        $stmtData->close();
        $classBD->desconectarBD($conexion);

        // 8. Enviar respuesta final
        http_response_code(200);
        echo json_encode(array(
            "code" => 200,
            "status" => "OK",
            "data" => $dataRows,
            "pagination" => array(
                "current_page" => $page,
                "per_page" => $perPage,
                "total_records" => $totalRecords,
                "total_pages" => $totalPages
            )
        ), JSON_UNESCAPED_UNICODE);
    }

    private function autorizarAccesoBiometricos($conexion, array $auth, string $requiredPermission): array {
        try {
            $stmtMod = $conexion->prepare('SELECT id, activo FROM modulos_dpto_operativo WHERE clave = ? LIMIT 1');
            $modKey = self::BIOMETRICOS_MODULE;
            $stmtMod->bind_param('s', $modKey);
            $stmtMod->execute();
            $module = $stmtMod->get_result()->fetch_assoc();
            $stmtMod->close();

            if (!$module || (int)($module['activo'] ?? 0) !== 1) {
                return ['success' => false, 'code' => 403, 'status' => 'FUNCTION_PERMISSION_DENIED', 'error' => 'Módulo funcional biométricos no activo o no disponible.'];
            }

            $userId = (int)($auth['user_id'] ?? 0);
            if ($userId <= 0) {
                return ['success' => false, 'code' => 401, 'status' => 'UNAUTHORIZED', 'error' => 'Identidad Portal3 inválida.'];
            }

            // Precedencia ACL Global
            $stmtDirect = $conexion->prepare('SELECT id FROM modulos_usuarios_do WHERE id_usuario = ? LIMIT 1');
            $stmtDirect->bind_param('i', $userId);
            $stmtDirect->execute();
            $hasDirect = $stmtDirect->get_result()->fetch_assoc() !== null;
            $stmtDirect->close();

            if ($hasDirect) {
                $stmtGrant = $conexion->prepare('SELECT leer, crear, editar, eliminar, descargar FROM modulos_usuarios_do WHERE id_modulo = ? AND id_usuario = ? LIMIT 1');
                $modId = (int)$module['id'];
                $stmtGrant->bind_param('ii', $modId, $userId);
                $stmtGrant->execute();
                $grant = $stmtGrant->get_result()->fetch_assoc();
                $stmtGrant->close();
            } else {
                $puestoId = (int)($auth['id_puesto'] ?? 0);
                if ($puestoId > 0) {
                    $stmtGrant = $conexion->prepare('SELECT leer, crear, editar, eliminar, descargar FROM modulos_puestos_do WHERE id_modulo = ? AND id_puesto = ? LIMIT 1');
                    $modId = (int)$module['id'];
                    $stmtGrant->bind_param('ii', $modId, $puestoId);
                    $stmtGrant->execute();
                    $grant = $stmtGrant->get_result()->fetch_assoc();
                    $stmtGrant->close();
                } else {
                    $grant = null;
                }
            }

            if (!$grant || (int)($grant[$requiredPermission] ?? 0) !== 1) {
                return ['success' => false, 'code' => 403, 'status' => 'FUNCTION_PERMISSION_DENIED', 'error' => 'Acceso denegado: permiso funcional insuficiente.'];
            }

            // Resolver configuración de estación del módulo biométricos
            $stmtCfg = $conexion->prepare('SELECT activo, estaciones_soportadas, tipo_departamento FROM tb_modulos_config WHERE modulo_key = ? LIMIT 1');
            $stmtCfg->bind_param('s', $modKey);
            $stmtCfg->execute();
            $moduleConfig = $stmtCfg->get_result()->fetch_assoc();
            $stmtCfg->close();

            if (!$moduleConfig || (int)($moduleConfig['activo'] ?? 0) !== 1 || ($moduleConfig['tipo_departamento'] ?? null) !== 'localidades') {
                return ['success' => false, 'code' => 403, 'status' => 'STATION_SCOPE_DENIED', 'error' => 'Configuración de alcance de estación no disponible para el módulo biométricos.'];
            }

            $supportedStations = json_decode($moduleConfig['estaciones_soportadas'] ?? '[]', true);
            if (!is_array($supportedStations) || empty($supportedStations)) {
                return ['success' => false, 'code' => 403, 'status' => 'STATION_SCOPE_DENIED', 'error' => 'Sin estaciones soportadas en la configuración del módulo.'];
            }

            // Reutilizar la resolución de station scope de Portal3JwtAuthHelper
            $reflector = new ReflectionClass('Portal3JwtAuthHelper');
            $method = reflector_get_method($reflector, 'resolveStationScope');
            $authorizedStations = $method->invoke(null, $conexion, $auth, $supportedStations);

            if (empty($authorizedStations)) {
                return ['success' => false, 'code' => 403, 'status' => 'STATION_SCOPE_DENIED', 'error' => 'Sin estaciones autorizadas para su perfil en este módulo.'];
            }

            return ['success' => true, 'code' => 200, 'status' => 'AUTHORIZED', 'authorized_stations' => $authorizedStations];
        } catch (Throwable $e) {
            return ['success' => false, 'code' => 500, 'status' => 'INTERNAL_ERROR', 'error' => 'Error interno al autorizar módulo biométricos: ' . $e->getMessage()];
        }
    }

    private function parseAndFormatIsoUtc(string $val): ?string {
        try {
            $dt = new DateTime($val, new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
}

function reflector_get_method(ReflectionClass $reflector, string $methodName): ReflectionMethod {
    $method = $reflector->getMethod($methodName);
    $method->setAccessible(true);
    return $method;
}
