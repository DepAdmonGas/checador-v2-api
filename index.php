<?php

include_once "modelo/Acceso.php";
include_once "modelo/Personal.php";
include_once "modelo/Empresa.php";
include_once "modelo/Encriptar.php";
$ClassAcceso = new Acceso();
$ClassEmpresa = new Empresa();
$ClassPersonal = new Personal();


if (!function_exists('obtenerAuthorizationHeader')) {
    function obtenerAuthorizationHeader()
    {
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                return $headers['Authorization'];
            } elseif (isset($headers['authorization'])) {
                return $headers['authorization']; // por si viene en minúsculas
            }
        }
        return null;
    }
}

if (!function_exists('JsonCovert')) {
    function JsonCovert($Result)
    {
        header('Content-Type: application/json');
        echo json_encode($Result);
    }
}

if (!function_exists('checador_is_legacy_route_allowed')) {
    function checador_is_legacy_route_allowed(): bool
    {
        $rawProfile = getenv('CHECADOR_DEPLOYMENT_PROFILE');
        $rawAppEnv = getenv('APP_ENV');
        $rawLegacyEnabled = getenv('LEGACY_ROUTES_ENABLED');

        // REGLA 1 & 2: CHECADOR_DEPLOYMENT_PROFILE es la autoridad explícita y obligatoria.
        // Si no existe, está vacío o solo contiene espacios -> FAIL-CLOSED inmediato (bloqueado)
        // independientemente de APP_ENV o LEGACY_ROUTES_ENABLED.
        if ($rawProfile === false || trim((string)$rawProfile) === '') {
            return false;
        }

        $profile = strtolower(trim((string)$rawProfile));
        $appEnv = ($rawAppEnv !== false && trim((string)$rawAppEnv) !== '') ? strtolower(trim((string)$rawAppEnv)) : null;

        $preprodProfiles = ['preproduction', 'v2_preproduction_isolated'];
        $prodProfiles = ['historical_production_dual', 'production'];
        $devProfiles = ['development', 'dev'];
        $testProfiles = ['test', 'testing'];

        $resolveCategory = static function(?string $val) use ($preprodProfiles, $prodProfiles, $devProfiles, $testProfiles): ?string {
            if ($val === null) {
                return null;
            }
            if (in_array($val, $preprodProfiles, true)) {
                return 'preprod';
            }
            if (in_array($val, $prodProfiles, true)) {
                return 'prod';
            }
            if (in_array($val, $devProfiles, true)) {
                return 'dev';
            }
            if (in_array($val, $testProfiles, true)) {
                return 'test';
            }
            return 'unknown';
        };

        $catProfile = $resolveCategory($profile);

        // REGLA 3: Perfil inválido/desconocido -> FAIL-CLOSED
        if ($catProfile === 'unknown') {
            return false;
        }

        // REGLA 4: Preproducción -> estrictamente bloqueado (cero overrides)
        if ($catProfile === 'preprod') {
            return false;
        }

        // REGLA 5: Evaluación de APP_ENV como señal complementaria para detectar contradicciones
        if ($appEnv !== null) {
            $catAppEnv = $resolveCategory($appEnv);
            if ($catAppEnv === 'unknown' || $catAppEnv === 'preprod' || $catAppEnv !== $catProfile) {
                return false;
            }
        }

        // REGLA 6 & 7: Base permitida exclusivamente por perfil primario explícito y válido
        $baseAllowed = false;
        if ($catProfile === 'prod' || $catProfile === 'dev' || $catProfile === 'test') {
            $baseAllowed = true;
        } else {
            return false;
        }

        // REGLA 8: Variable secundaria LEGACY_ROUTES_ENABLED (solo restrictiva, no habilitadora)
        if ($rawLegacyEnabled !== false && trim((string)$rawLegacyEnabled) !== '') {
            $val = strtolower(trim((string)$rawLegacyEnabled));
            if (in_array($val, ['false', '0', 'no', 'off'], true)) {
                return false;
            } elseif (in_array($val, ['true', '1', 'yes', 'on'], true)) {
                // Base permitida permanece
            } else {
                // Valor inválido de variable secundaria -> FAIL-CLOSED
                return false;
            }
        }

        return $baseAllowed;
    }
}

$raw_uri = $_SERVER["REQUEST_URI"] ?? '';
if ($raw_uri === '') {
    return;
}

// Normalize leading slashes so parse_url always resolves path cleanly
$normalized_uri = '/' . ltrim($raw_uri, '/');
$componentes_url = parse_url($normalized_uri);
$server_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$ruta = $componentes_url['path'] ?? '/';
$raw_segments = array_values(array_filter(explode("/", $ruta), static fn($part) => $part !== ''));
$ruta_elegida = 'modelo/404.php';

$historical_prefixes = ['portal-api-v.1.0.1', 'portal-api-v.2.0'];
$has_historical_prefix = !empty($raw_segments) && in_array($raw_segments[0], $historical_prefixes, true);

if ($has_historical_prefix) {
    array_shift($raw_segments);
}

// Canonical normalized route segments: index 0 is internal sentinel preserving downstream offset semantics
$partes_ruta = array_merge(['portal-api-canonical'], $raw_segments);

$valid_first_segments = ['api', 'acceso-empresa', 'personal', 'editar-pin', 'editar-huella', 'acceso-personal', 'personal-asistencia'];
$is_valid_route = $has_historical_prefix || (!empty($raw_segments) && in_array($raw_segments[0], $valid_first_segments, true));

if ($is_valid_route) {

    if (count($partes_ruta) == 1) {
        include_once $ruta_elegida;
    } else if (count($partes_ruta) == 2) {

        $legacy_protected_routes = [
            'acceso-empresa',
            'personal',
            'editar-pin',
            'editar-huella',
            'acceso-personal',
            'personal-asistencia'
        ];

        if (in_array($partes_ruta[1], $legacy_protected_routes, true) && !checador_is_legacy_route_allowed()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo json_encode([
                "code" => 503,
                "status" => "SERVICE_UNAVAILABLE",
                "mensaje" => "Servicio no disponible en este entorno."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($partes_ruta[1] == "acceso-empresa") {
            if ($server_method == 'POST') {
                $rawBody = file_get_contents('php://input');
                $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
                $Eusuario = '';
                $Epassword = '';

                if (stripos($contentType, 'application/json') !== false || (empty($contentType) && !empty($rawBody) && ($rawBody[0] === '{' || $rawBody[0] === '['))) {
                    $jsonData = json_decode($rawBody, true);
                    if (is_array($jsonData)) {
                        $Eusuario = isset($jsonData['usuario']) && is_string($jsonData['usuario']) ? trim($jsonData['usuario']) : '';
                        $Epassword = isset($jsonData['password']) && is_string($jsonData['password']) ? $jsonData['password'] : '';
                    }
                    // Malformed JSON fails closed (remains empty strings -> 204 SIN CONTENIDO)
                } else if (!empty($_POST)) {
                    $Eusuario = isset($_POST['usuario']) && is_string($_POST['usuario']) ? trim($_POST['usuario']) : '';
                    $Epassword = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
                } else if (!empty($rawBody)) {
                    $parsed = [];
                    parse_str($rawBody, $parsed);
                    $Eusuario = isset($parsed['usuario']) && is_string($parsed['usuario']) ? trim($parsed['usuario']) : '';
                    $Epassword = isset($parsed['password']) && is_string($parsed['password']) ? $parsed['password'] : '';
                }

                // Strict invariant: POST MUST NOT fall back to $_GET
                $Result = $ClassAcceso->AccesoEmpresa($Eusuario, $Epassword);
                JsonCovert($Result);
            } else if ($server_method == 'GET') {
                $legacyGetConfig = getenv('LEGACY_ACCESO_EMPRESA_GET_ENABLED');
                $legacyGetEnabled = ($legacyGetConfig === '1' || strtolower((string)$legacyGetConfig) === 'true');

                if ($legacyGetEnabled) {
                    $Eusuario = isset($_GET['usuario']) && is_string($_GET['usuario']) ? trim($_GET['usuario']) : '';
                    $Epassword = isset($_GET['password']) && is_string($_GET['password']) ? $_GET['password'] : '';
                    $Result = $ClassAcceso->AccesoEmpresa($Eusuario, $Epassword);
                    JsonCovert($Result);
                } else {
                    http_response_code(405);
                    header('Allow: POST');
                    date_default_timezone_set('America/Mexico_City');
                    JsonCovert([
                        "resultado" => [
                            "fecha" => date("Y-m-d"),
                            "hora" => date("H:i:s"),
                            "code" => 405,
                            "status" => "METODO NO PERMITIDO"
                        ]
                    ]);
                }
            } else {
                http_response_code(405);
                header('Allow: POST');
                date_default_timezone_set('America/Mexico_City');
                JsonCovert([
                    "resultado" => [
                        "fecha" => date("Y-m-d"),
                        "hora" => date("H:i:s"),
                        "code" => 405,
                        "status" => "METODO NO PERMITIDO"
                    ]
                ]);
            }
        } else if ($partes_ruta[1] == "personal") {
            if ($server_method == 'GET') {
                $headers = apache_request_headers();
                $Authorization = obtenerAuthorizationHeader();
                $Result = $ClassPersonal->listaPersonal($Authorization);
                JsonCovert($Result);
            } else {
                http_response_code(405);
                header('Allow: GET');
                date_default_timezone_set('America/Mexico_City');
                JsonCovert([
                    "resultado" => [
                        "fecha" => date("Y-m-d"),
                        "hora" => date("H:i:s"),
                        "code" => 405,
                        "status" => "METODO NO PERMITIDO"
                    ]
                ]);
            }
        } else if ($partes_ruta[1] == "editar-pin") {
            if ($server_method == 'PUT') {
                $headers = apache_request_headers();
                $Authorization = obtenerAuthorizationHeader();
                $idpersonal = $_GET['idpersonal'];
                $pinpersonal = $_GET['pinpersonal'];
                $Result = $ClassPersonal->EditarPIN($Authorization, $idpersonal, $pinpersonal);
                JsonCovert($Result);
            } else {
                http_response_code(405);
                header('Allow: PUT');
                date_default_timezone_set('America/Mexico_City');
                JsonCovert([
                    "resultado" => [
                        "fecha" => date("Y-m-d"),
                        "hora" => date("H:i:s"),
                        "code" => 405,
                        "status" => "METODO NO PERMITIDO"
                    ]
                ]);
            }
        } else if ($partes_ruta[1] == "editar-huella") {
            if ($server_method == 'PUT') {
                $headers = apache_request_headers();
                $Authorization = obtenerAuthorizationHeader();
                $idpersonal = $_GET['idpersonal'];
                $huellapersonal = str_replace(" ", "+", $_GET['huellapersonal']);
                $Result = $ClassPersonal->EditarHUELLA($Authorization, $idpersonal, $huellapersonal);
                JsonCovert($Result);
            } else {
                http_response_code(405);
                header('Allow: PUT');
                date_default_timezone_set('America/Mexico_City');
                JsonCovert([
                    "resultado" => [
                        "fecha" => date("Y-m-d"),
                        "hora" => date("H:i:s"),
                        "code" => 405,
                        "status" => "METODO NO PERMITIDO"
                    ]
                ]);
            }
        } else if ($partes_ruta[1] == "acceso-personal") {
            if ($server_method == 'POST') {
                $headers = apache_request_headers();
                $Authorization = obtenerAuthorizationHeader();
                $idpersonal = $_POST['idpersonal'];
                $fecha = $_POST['fecha'];
                $hora = $_POST['hora'];
                $Result = $ClassAcceso->AccesoPersonal($Authorization, $idpersonal, $fecha, $hora);
                JsonCovert($Result);
            } else {
                http_response_code(405);
                header('Allow: POST');
                date_default_timezone_set('America/Mexico_City');
                JsonCovert([
                    "resultado" => [
                        "fecha" => date("Y-m-d"),
                        "hora" => date("H:i:s"),
                        "code" => 405,
                        "status" => "METODO NO PERMITIDO"
                    ]
                ]);
            }
        } else if ($partes_ruta[1] == "personal-asistencia") {
            if ($server_method == 'GET') {
                $headers = apache_request_headers();
                $Authorization = obtenerAuthorizationHeader();
                $Result = $ClassPersonal->listaPersonalAsistencia($Authorization);
                JsonCovert($Result);
            } else {
                http_response_code(405);
                header('Allow: GET');
                date_default_timezone_set('America/Mexico_City');
                JsonCovert([
                    "resultado" => [
                        "fecha" => date("Y-m-d"),
                        "hora" => date("H:i:s"),
                        "code" => 405,
                        "status" => "METODO NO PERMITIDO"
                    ]
                ]);
            }
        } else {
            include_once $ruta_elegida;
        }
    } else if (count($partes_ruta) == 4 && $partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'punch-events') {
        if ($server_method == 'POST') {
            include_once "modelo/v2/IngestaPunchV2Controller.php";
            $controllerV2 = new IngestaPunchV2Controller();
            $controllerV2->procesarIngesta();
            exit;
        } else if ($server_method == 'GET') {
            include_once "modelo/v2/PunchEventReadV2Controller.php";
            $controllerReadV2 = new PunchEventReadV2Controller();
            $controllerReadV2->consultarMarcacionesCrudas();
            exit;
        } else {
            http_response_code(405);
            header('Allow: POST, GET');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else if (count($partes_ruta) >= 4 && $partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'punch-events') {
        if (count($partes_ruta) == 5 && $partes_ruta[4] === 'read-snapshots') {
            if ($server_method === 'POST') {
                include_once "modelo/v2/SnapshotReadV2Service.php";
                $snapshotService = new SnapshotReadV2Service();
                $snapshotService->handleCreateSnapshot();
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else if (count($partes_ruta) == 7 && $partes_ruta[4] === 'read-snapshots' && $partes_ruta[6] === 'events') {
            if ($server_method === 'GET') {
                $snapshotToken = $partes_ruta[5];
                include_once "modelo/v2/SnapshotReadV2Service.php";
                $snapshotService = new SnapshotReadV2Service();
                $snapshotService->handlePageSnapshot($snapshotToken);
                exit;
            } else {
                http_response_code(405);
                header('Allow: GET');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            include_once $ruta_elegida;
        }
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'temporary-access') {
        include_once "modelo/v2/TemporaryAccessV2Controller.php";
        $controllerTemp = new TemporaryAccessV2Controller();
        $count = count($partes_ruta);

        if ($count == 5 && $partes_ruta[4] === 'requests') {
            if ($server_method === 'POST') {
                // 1. POST /api/v2/temporary-access/requests
                $controllerTemp->handleCreateRequest();
                exit;
            } else if ($server_method === 'GET') {
                // 2. GET /api/v2/temporary-access/requests
                $controllerTemp->handleListPendingRequests();
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST, GET');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else if ($count == 6 && $partes_ruta[4] === 'requests' && $server_method === 'POST') {
            // Path parameters: /requests/{request_uuid}/{authorize|reject|cancel}
            $requestUuid = $partes_ruta[5];
            include_once $ruta_elegida;
        } else if ($count == 7 && $partes_ruta[4] === 'requests') {
            $requestUuid = $partes_ruta[5];
            $subAction = $partes_ruta[6];

            if ($subAction === 'authorize' || $subAction === 'reject' || $subAction === 'cancel') {
                if ($server_method === 'POST') {
                    if ($subAction === 'authorize') {
                        // 3. POST /api/v2/temporary-access/requests/{request_uuid}/authorize
                        $controllerTemp->handleAuthorizeRequest($requestUuid);
                        exit;
                    } else if ($subAction === 'reject') {
                        // 4. POST /api/v2/temporary-access/requests/{request_uuid}/reject
                        $controllerTemp->handleRejectRequest($requestUuid);
                        exit;
                    } else if ($subAction === 'cancel') {
                        // 5. POST /api/v2/temporary-access/requests/{request_uuid}/cancel
                        $controllerTemp->handleCancelRequest($requestUuid);
                        exit;
                    }
                } else {
                    http_response_code(405);
                    header('Allow: POST');
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            } else {
                include_once $ruta_elegida;
            }
        } else if ($count == 5 && $partes_ruta[4] === 'validate') {
            if ($server_method === 'POST') {
                // 6. POST /api/v2/temporary-access/validate
                $controllerTemp->handleValidatePin();
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            include_once $ruta_elegida;
        }
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && ($partes_ruta[3] == 'device' || $partes_ruta[3] == 'devices') && isset($partes_ruta[4]) && $partes_ruta[4] === 'workers') {
        $count = count($partes_ruta);
        if ($count == 7 && $partes_ruta[6] === 'entry-window') {
            $externalRef = $partes_ruta[5];
            if ($server_method === 'GET') {
                include_once "modelo/v2/gatekeeper/DeviceEntryWindowV2Controller.php";
                $entryWindowCtrl = new DeviceEntryWindowV2Controller();
                $entryWindowCtrl->handleGetEntryWindow((string)$externalRef);
                exit;
            } else {
                http_response_code(405);
                header('Allow: GET');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            include_once $ruta_elegida;
        }
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && ($partes_ruta[3] == 'device' || $partes_ruta[3] == 'devices') && isset($partes_ruta[4]) && $partes_ruta[4] === 'access-audits') {
        $count = count($partes_ruta);
        if ($count == 5) {
            if ($server_method === 'POST') {
                include_once "modelo/v2/gatekeeper/DeviceAccessAuditV2Controller.php";
                $auditCtrl = new DeviceAccessAuditV2Controller();
                $auditCtrl->handlePostAccessAudit();
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            include_once $ruta_elegida;
        }
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'devices') {
        include_once "modelo/v2/Level2ServerController.php";
        $l2Ctrl = new Level2ServerController();
        $count = count($partes_ruta);
        if ($count == 5 && $partes_ruta[4] === 'context') {
            if ($server_method === 'GET') {
                $l2Ctrl->handleGetDeviceContext();
                exit;
            } else {
                http_response_code(405);
                header('Allow: GET');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else if ($count == 5 && $partes_ruta[4] === 'generations') {
            if ($server_method === 'POST') {
                $l2Ctrl->handleRegisterGeneration();
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else if ($count == 5 && $partes_ruta[4] === 'telemetry') {
            if ($server_method === 'POST') {
                $l2Ctrl->handleIngestTelemetry();
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            include_once $ruta_elegida;
        }
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'level2') {
        include_once "modelo/v2/Level2ServerController.php";
        $l2Ctrl = new Level2ServerController();
        $count = count($partes_ruta);
        if ($count == 5 && $partes_ruta[4] === 'window-status') {
            if ($server_method === 'GET') {
                $l2Ctrl->handleGetWindowStatus();
                exit;
            } else {
                http_response_code(405);
                header('Allow: GET');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            include_once $ruta_elegida;
        }
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'shift-templates') {
        include_once "modelo/v2/shift/ShiftSchedulingV2Controller.php";
        $shiftCtrl = new ShiftSchedulingV2Controller();
        $count = count($partes_ruta);
        if ($count == 4) {
            if ($server_method === 'POST') {
                // POST /api/v2/shift-templates
                $shiftCtrl->handleCreateTemplate();
                exit;
            } else if ($server_method === 'GET') {
                // GET /api/v2/shift-templates
                $shiftCtrl->handleListTemplates();
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST, GET');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else if ($count == 6 && $partes_ruta[5] === 'versions') {
            if ($server_method === 'POST') {
                // POST /api/v2/shift-templates/{clave}/versions
                $clave = $partes_ruta[4];
                $shiftCtrl->handleCreateTemplateVersion($clave);
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else if ($count == 7 && $partes_ruta[5] === 'versions') {
            if ($server_method === 'GET') {
                // GET /api/v2/shift-templates/{clave}/versions/{version}
                $clave = $partes_ruta[4];
                $version = (int)$partes_ruta[6];
                $shiftCtrl->handleGetTemplateVersion($clave, $version);
                exit;
            } else {
                http_response_code(405);
                header('Allow: GET');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            include_once $ruta_elegida;
        }
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'shift-assignments') {
        include_once "modelo/v2/shift/ShiftSchedulingV2Controller.php";
        $shiftCtrl = new ShiftSchedulingV2Controller();
        $count = count($partes_ruta);
        if ($count == 4) {
            if ($server_method === 'POST') {
                // POST /api/v2/shift-assignments
                $shiftCtrl->handleCreateAssignment();
                exit;
            } else if ($server_method === 'GET') {
                // GET /api/v2/shift-assignments
                $shiftCtrl->handleListAssignments();
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST, GET');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else if ($count == 6 && $partes_ruta[5] === 'close') {
            if ($server_method === 'POST') {
                // POST /api/v2/shift-assignments/{id}/close
                $asigId = (int)$partes_ruta[4];
                $shiftCtrl->handleCloseAssignment($asigId);
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            include_once $ruta_elegida;
        }
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'workers') {
        include_once "modelo/v2/shift/ShiftSchedulingV2Controller.php";
        $shiftCtrl = new ShiftSchedulingV2Controller();
        $count = count($partes_ruta);
        if ($count == 6 && $partes_ruta[5] === 'effective-schedule') {
            if ($server_method === 'GET') {
                // GET /api/v2/workers/{id_personal}/effective-schedule
                $idPersonal = (int)$partes_ruta[4];
                if (($_GET['resolution_context'] ?? '') === 'WORKER_EFFECTIVE') {
                    include_once "modelo/v2/shift/WorkplaceCoverageV2Controller.php";
                    (new WorkplaceCoverageV2Controller())->handleWorkerEffectiveSchedule($idPersonal);
                    exit;
                }
                $shiftCtrl->handleGetEffectiveSchedule($idPersonal);
                exit;
            } else {
                http_response_code(405);
                header('Allow: GET');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            include_once $ruta_elegida;
        }
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'workplace-coverages') {
        include_once "modelo/v2/shift/WorkplaceCoverageV2Controller.php";
        $coverageCtrl = new WorkplaceCoverageV2Controller();
        $count = count($partes_ruta);
        if ($count === 4) {
            if ($server_method === 'GET') {
                $coverageCtrl->handleList();
                exit;
            }
            if ($server_method === 'POST') {
                $coverageCtrl->handleCreate();
                exit;
            }
            http_response_code(405);
            header('Allow: GET, POST');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['code' => 405, 'status' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($count === 5 && $server_method === 'GET') {
            $coverageCtrl->handleDetail((string)$partes_ruta[4]);
            exit;
        }
        if ($count === 6 && $server_method === 'POST' && in_array($partes_ruta[5], ['authorize', 'reject', 'cancel'], true)) {
            $coverageCtrl->handleTransition((string)$partes_ruta[4], strtoupper((string)$partes_ruta[5]));
            exit;
        }
        http_response_code($count === 5 || $count === 6 ? 405 : 404);
        if ($count === 5) header('Allow: GET');
        if ($count === 6) header('Allow: POST');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['code' => $count === 5 || $count === 6 ? 405 : 404, 'status' => $count === 5 || $count === 6 ? 'METHOD_NOT_ALLOWED' : 'NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        exit;
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'scheduled-shifts') {
        include_once "modelo/v2/shift/ShiftSchedulingV2Controller.php";
        $shiftCtrl = new ShiftSchedulingV2Controller();
        $count = count($partes_ruta);
        if ($count == 5 && $partes_ruta[4] === 'materialize') {
            if ($server_method === 'POST') {
                // POST /api/v2/scheduled-shifts/materialize
                $shiftCtrl->handleMaterializeScheduledShifts();
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            include_once $ruta_elegida;
        }
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'workplaces') {
        $count = count($partes_ruta);
        if ($count == 6 && $partes_ruta[5] === 'timezone') {
            if ($server_method === 'GET') {
                // GET /api/v2/workplaces/{workplace_id}/timezone?date=YYYY-MM-DD
                include_once "modelo/v2/WorkplaceTimezoneReadV2Controller.php";
                $tzController = new WorkplaceTimezoneReadV2Controller();
                $tzController->consultarHusoHorario((string)$partes_ruta[4]);
                exit;
            } else {
                http_response_code(405);
                header('Allow: GET');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            include_once $ruta_elegida;
        }
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'journeys') {
        $count = count($partes_ruta);
        if ($count == 5 && $partes_ruta[4] === 'evaluate') {
            if ($server_method === 'POST') {
                // POST /api/v2/journeys/evaluate
                include_once "modelo/v2/journey/JourneyEvaluationV2Controller.php";
                $journeyCtrl = new JourneyEvaluationV2Controller();
                $journeyCtrl->handleEvaluateJourney();
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            include_once $ruta_elegida;
        }
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'incidences') {
        include_once "modelo/v2/AdministrativeIncidenceV2Controller.php";
        $incCtrl = new AdministrativeIncidenceV2Controller();
        $count = count($partes_ruta);
        if ($count == 4) {
            if ($server_method === 'GET') {
                $incCtrl->handleReadApplicableIncidences();
                exit;
            } else if ($server_method === 'POST') {
                $incCtrl->handleCreateIncident();
                exit;
            } else {
                http_response_code(405);
                header('Allow: GET, POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else if ($count == 5) {
            if ($server_method === 'GET') {
                $incCtrl->handleReadSingleIncident((string)$partes_ruta[4]);
                exit;
            } else {
                http_response_code(405);
                header('Allow: GET');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else if ($count == 6 && $partes_ruta[5] === 'revisions') {
            if ($server_method === 'POST') {
                $incCtrl->handleCreateIncidentRevision((string)$partes_ruta[4]);
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else if ($count == 6 && $partes_ruta[5] === 'resolve') {
            if ($server_method === 'POST') {
                $incCtrl->handleResolveIncident((string)$partes_ruta[4]);
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else if ($count == 6 && $partes_ruta[5] === 'authorize') {
            if ($server_method === 'POST') {
                $incCtrl->handleAuthorizeIncident((string)$partes_ruta[4]);
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else if ($count == 6 && $partes_ruta[5] === 'reject') {
            if ($server_method === 'POST') {
                $incCtrl->handleRejectIncident((string)$partes_ruta[4]);
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else if ($count == 6 && $partes_ruta[5] === 'revoke') {
            if ($server_method === 'POST') {
                $incCtrl->handleRevokeIncident((string)$partes_ruta[4]);
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            include_once $ruta_elegida;
        }
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'administrative-evaluations') {
        include_once "modelo/v2/AdministrativeIncidenceV2Controller.php";
        $evalCtrl = new AdministrativeIncidenceV2Controller();
        $count = count($partes_ruta);
        if ($count == 4) {
            if ($server_method === 'GET') {
                $evalCtrl->handleReadAdministrativeEvaluation();
                exit;
            } else {
                http_response_code(405);
                header('Allow: GET');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido. P3G-A read-only boundary."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else if ($count == 5 && $partes_ruta[4] === 'evaluate') {
            if ($server_method === 'POST') {
                $evalCtrl->handleExecuteAdministrativeEvaluation();
                exit;
            } else {
                http_response_code(405);
                header('Allow: POST');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(["code" => 405, "status" => "METHOD_NOT_ALLOWED", "mensaje" => "Método no permitido. Endpoint de ejecución de evaluación administrativa solo acepta POST."], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            include_once $ruta_elegida;
        }
    } else {
        include_once $ruta_elegida;
    }
} else {
    include_once $ruta_elegida;
}

/*
POST-----Crear
GET-----Obtener
PUT-----Actualizar
DELETE-----Eliminar
*/
