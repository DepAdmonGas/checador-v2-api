<?php

include_once "modelo/Acceso.php";
include_once "modelo/Personal.php";
include_once "modelo/Empresa.php";
include_once "modelo/Encriptar.php";
$ClassAcceso = new Acceso();
$ClassEmpresa = new Empresa();
$ClassPersonal = new Personal();


$componentes_url = parse_url($_SERVER["REQUEST_URI"]);
$server_method = $_SERVER['REQUEST_METHOD'];

$ruta = $componentes_url['path'];
$partes_ruta = explode("/", $ruta);
$partes_ruta = array_filter($partes_ruta);
$partes_ruta = array_slice($partes_ruta, 0);
$ruta_elegida = 'modelo/404.php';

if ($partes_ruta[0] == 'portal-api-v.1.0.1' || $partes_ruta[0] == 'portal-api-v.2.0') {

    if (count($partes_ruta) == 1) {
        include_once $ruta_elegida;
    } else if (count($partes_ruta) == 2) {

        if ($partes_ruta[1] == "acceso-empresa") {
            if ($server_method == 'GET') {
                $Eusuario = $_GET['usuario'];
                $Epassword = $_GET['password'];
                $Result = $ClassAcceso->AccesoEmpresa($Eusuario, $Epassword);
                JsonCovert($Result);
            } else {
                include_once $ruta_elegida;
            }
        } else if ($partes_ruta[1] == "personal") {
            if ($server_method == 'GET') {
                $headers = apache_request_headers();
                $Authorization = obtenerAuthorizationHeader();
                $Result = $ClassPersonal->listaPersonal($Authorization);
                JsonCovert($Result);
            } else {
                include_once $ruta_elegida;
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
                include_once $ruta_elegida;
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
                include_once $ruta_elegida;
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
                include_once $ruta_elegida;
            }
        } else if ($partes_ruta[1] == "personal-asistencia") {
            if ($server_method == 'GET') {
                $headers = apache_request_headers();
                $Authorization = obtenerAuthorizationHeader();
                $Result = $ClassPersonal->listaPersonalAsistencia($Authorization);
                JsonCovert($Result);
            } else {
                include_once $ruta_elegida;
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
            include_once $ruta_elegida;
        }
    } else if ($partes_ruta[1] == 'api' && $partes_ruta[2] == 'v2' && $partes_ruta[3] == 'temporary-access') {
        include_once "modelo/v2/TemporaryAccessV2Controller.php";
        $controllerTemp = new TemporaryAccessV2Controller();
        $count = count($partes_ruta);

        if ($count == 5 && $partes_ruta[4] === 'requests' && $server_method === 'POST') {
            // 1. POST /api/v2/temporary-access/requests
            $controllerTemp->handleCreateRequest();
            exit;
        } else if ($count == 5 && $partes_ruta[4] === 'requests' && $server_method === 'GET') {
            // 2. GET /api/v2/temporary-access/requests
            $controllerTemp->handleListPendingRequests();
            exit;
        } else if ($count == 6 && $partes_ruta[4] === 'requests' && $server_method === 'POST') {
            // Path parameters: /requests/{request_uuid}/{authorize|reject|cancel}
            $requestUuid = $partes_ruta[5];
            include_once $ruta_elegida;
        } else if ($count == 7 && $partes_ruta[4] === 'requests' && $server_method === 'POST') {
            $requestUuid = $partes_ruta[5];
            $subAction = $partes_ruta[6];

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
            } else {
                include_once $ruta_elegida;
            }
        } else if ($count == 5 && $partes_ruta[4] === 'validate' && $server_method === 'POST') {
            // 6. POST /api/v2/temporary-access/validate
            $controllerTemp->handleValidatePin();
            exit;
        } else {
            include_once $ruta_elegida;
        }
    }
}

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

function JsonCovert($Result)
{
    header('Content-Type: application/json');
    echo json_encode($Result);
}
/*
POST-----Crear
GET-----Obtener
PUT-----Actualizar
DELETE-----Eliminar
*/
