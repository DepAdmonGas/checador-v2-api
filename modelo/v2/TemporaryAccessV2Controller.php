<?php

include_once __DIR__ . "/../BD.php";
include_once __DIR__ . "/DispositivoV2.php";
include_once __DIR__ . "/Portal3JwtAuthHelper.php";
include_once __DIR__ . "/TemporaryAccessV2Service.php";

class TemporaryAccessV2Controller {

    private $service;

    public function __construct() {
        $this->service = new TemporaryAccessV2Service();
    }

    /**
     * 12.1 POST /api/v2/temporary-access/requests (Lector Device Auth)
     */
    public function handleCreateRequest() {
        header('Content-Type: application/json; charset=utf-8');

        $deviceAuth = $this->autenticarDispositivo();
        if (!$deviceAuth['success']) {
            $this->sendJsonResponse($deviceAuth['code'], array('code' => $deviceAuth['code'], 'status' => 'UNAUTHORIZED', 'message' => $deviceAuth['message']));
            return;
        }

        $conexion = $deviceAuth['conexion'];
        $idDispositivo = $deviceAuth['id_dispositivo'];
        $idEstacionResuelta = $deviceAuth['id_estacion'];

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') === false) {
            $this->closeConexion($conexion);
            $this->sendJsonResponse(415, array('code' => 415, 'status' => 'UNSUPPORTED_MEDIA_TYPE', 'message' => 'Content-Type debe ser application/json.'));
            return;
        }

        $inputRaw = file_get_contents('php://input');
        $body = json_decode($inputRaw, true);
        if (!is_array($body)) {
            $this->closeConexion($conexion);
            $this->sendJsonResponse(400, array('code' => 400, 'status' => 'BAD_REQUEST', 'message' => 'Cuerpo de solicitud JSON inválido o malformado.'));
            return;
        }

        $requestUuid = $body['request_uuid'] ?? '';
        $refExternaPersonal = $body['external_worker_reference'] ?? ($body['referencia_externa_personal'] ?? '');
        $reason = $body['reason'] ?? 'FINGERPRINT_NOT_RECOGNIZED';

        if (strlen((string)$refExternaPersonal) > 64) {
            $this->closeConexion($conexion);
            $this->sendJsonResponse(400, array('code' => 400, 'status' => 'BAD_REQUEST', 'message' => 'external_worker_reference excede el máximo permitido de 64 caracteres.'));
            return;
        }

        if (strlen((string)$reason) > 100) {
            $this->closeConexion($conexion);
            $this->sendJsonResponse(400, array('code' => 400, 'status' => 'BAD_REQUEST', 'message' => 'reason excede el máximo permitido de 100 caracteres.'));
            return;
        }

        if (!$this->validateUuidV4($requestUuid)) {
            $this->closeConexion($conexion);
            $this->sendJsonResponse(400, array('code' => 400, 'status' => 'INVALID_UUID', 'message' => 'El campo request_uuid debe ser un UUIDv4 válido.'));
            return;
        }

        $result = $this->service->createRequest($conexion, $idDispositivo, $idEstacionResuelta, $requestUuid, $refExternaPersonal, $reason);

        $this->closeConexion($conexion);
        if (!$result['success']) {
            $this->sendJsonResponse($result['code'], array('code' => $result['code'], 'status' => $result['status'], 'message' => $result['message']));
        } else {
            $this->sendJsonResponse($result['code'], $result['data']);
        }
    }

    /**
     * 12.2 GET /api/v2/temporary-access/requests (Portal3 Supervisor Auth)
     */
    public function handleListPendingRequests() {
        header('Content-Type: application/json; charset=utf-8');

        $userAuth = $this->autenticarPortal3User('leer');
        if (!$userAuth['success']) {
            $this->sendJsonResponse($userAuth['code'], array('code' => $userAuth['code'], 'status' => $userAuth['status'] ?? 'UNAUTHORIZED', 'message' => $userAuth['message']));
            return;
        }

        $conexion = $userAuth['conexion'];
        $authorizedStationIds = $userAuth['authorized_stations'];
        $filterStationId = $_GET['id_estacion'] ?? null;

        $result = $this->service->getPendingRequestsForPortal3($conexion, $authorizedStationIds, $filterStationId);
        $this->closeConexion($conexion);

        if (!$result['success']) {
            $this->sendJsonResponse($result['code'], array('code' => $result['code'], 'status' => $result['status'], 'message' => $result['message']));
        } else {
            $this->sendJsonResponse(200, array('code' => 200, 'status' => 'OK', 'data' => $result['data']));
        }
    }

    /**
     * 12.3 POST /api/v2/temporary-access/requests/{request_uuid}/authorize (Portal3 Supervisor Auth)
     */
    public function handleAuthorizeRequest($requestUuid) {
        header('Content-Type: application/json; charset=utf-8');

        if (empty($requestUuid)) {
            $this->sendJsonResponse(400, array('code' => 400, 'status' => 'INVALID_PARAMETER', 'message' => 'El request_uuid en path es requerido.'));
            return;
        }

        $userAuth = $this->autenticarPortal3User('editar');
        if (!$userAuth['success']) {
            $this->sendJsonResponse($userAuth['code'], array('code' => $userAuth['code'], 'status' => $userAuth['status'] ?? 'UNAUTHORIZED', 'message' => $userAuth['message']));
            return;
        }

        $conexion = $userAuth['conexion'];
        $authorizedStationIds = $userAuth['authorized_stations'];
        $userId = $userAuth['user_id'];

        $result = $this->service->authorizeRequest($conexion, $authorizedStationIds, $requestUuid, $userId);
        $this->closeConexion($conexion);

        if (!$result['success']) {
            $this->sendJsonResponse($result['code'], array('code' => $result['code'], 'status' => $result['status'], 'message' => $result['message']));
        } else {
            $this->sendJsonResponse($result['code'], $result['data']);
        }
    }

    /**
     * 12.4 POST /api/v2/temporary-access/requests/{request_uuid}/reject (Portal3 Supervisor Auth)
     */
    public function handleRejectRequest($requestUuid) {
        header('Content-Type: application/json; charset=utf-8');

        if (empty($requestUuid)) {
            $this->sendJsonResponse(400, array('code' => 400, 'status' => 'INVALID_PARAMETER', 'message' => 'El request_uuid en path es requerido.'));
            return;
        }

        $userAuth = $this->autenticarPortal3User('editar');
        if (!$userAuth['success']) {
            $this->sendJsonResponse($userAuth['code'], array('code' => $userAuth['code'], 'status' => $userAuth['status'] ?? 'UNAUTHORIZED', 'message' => $userAuth['message']));
            return;
        }

        $conexion = $userAuth['conexion'];
        $authorizedStationIds = $userAuth['authorized_stations'];
        $userId = $userAuth['user_id'];

        $result = $this->service->rejectRequest($conexion, $authorizedStationIds, $requestUuid, $userId);
        $this->closeConexion($conexion);

        if (!$result['success']) {
            $this->sendJsonResponse($result['code'], array('code' => $result['code'], 'status' => $result['status'], 'message' => $result['message']));
        } else {
            $this->sendJsonResponse($result['code'], $result['data']);
        }
    }

    /**
     * 12.5 POST /api/v2/temporary-access/requests/{request_uuid}/cancel (Portal3 Supervisor Auth)
     */
    public function handleCancelRequest($requestUuid) {
        header('Content-Type: application/json; charset=utf-8');

        if (empty($requestUuid)) {
            $this->sendJsonResponse(400, array('code' => 400, 'status' => 'INVALID_PARAMETER', 'message' => 'El request_uuid en path es requerido.'));
            return;
        }

        $userAuth = $this->autenticarPortal3User('editar');
        if (!$userAuth['success']) {
            $this->sendJsonResponse($userAuth['code'], array('code' => $userAuth['code'], 'status' => $userAuth['status'] ?? 'UNAUTHORIZED', 'message' => $userAuth['message']));
            return;
        }

        $conexion = $userAuth['conexion'];
        $authorizedStationIds = $userAuth['authorized_stations'];
        $userId = $userAuth['user_id'];

        $result = $this->service->cancelRequest($conexion, $authorizedStationIds, $requestUuid, $userId);
        $this->closeConexion($conexion);

        if (!$result['success']) {
            $this->sendJsonResponse($result['code'], array('code' => $result['code'], 'status' => $result['status'], 'message' => $result['message']));
        } else {
            $this->sendJsonResponse($result['code'], $result['data']);
        }
    }

    /**
     * 12.6 POST /api/v2/temporary-access/validate (Lector Device Auth)
     */
    public function handleValidatePin() {
        header('Content-Type: application/json; charset=utf-8');

        $deviceAuth = $this->autenticarDispositivo();
        if (!$deviceAuth['success']) {
            $this->sendJsonResponse($deviceAuth['code'], array('code' => $deviceAuth['code'], 'status' => 'UNAUTHORIZED', 'message' => $deviceAuth['message']));
            return;
        }

        $conexion = $deviceAuth['conexion'];
        $idDispositivo = $deviceAuth['id_dispositivo'];
        $idEstacionResuelta = $deviceAuth['id_estacion'];

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') === false) {
            $this->closeConexion($conexion);
            $this->sendJsonResponse(415, array('code' => 415, 'status' => 'UNSUPPORTED_MEDIA_TYPE', 'message' => 'Content-Type debe ser application/json.'));
            return;
        }

        $inputRaw = file_get_contents('php://input');
        $body = json_decode($inputRaw, true);
        if (!is_array($body)) {
            $this->closeConexion($conexion);
            $this->sendJsonResponse(400, array('code' => 400, 'status' => 'BAD_REQUEST', 'message' => 'Cuerpo de solicitud JSON inválido o malformado.'));
            return;
        }

        $refExternaPersonal = $body['external_worker_reference'] ?? ($body['referencia_externa_personal'] ?? '');
        $temporaryPin = $body['temporary_pin'] ?? ($body['pin_code'] ?? '');
        $validationUuid = $body['validation_uuid'] ?? '';

        if (strlen((string)$refExternaPersonal) > 64) {
            $this->closeConexion($conexion);
            $this->sendJsonResponse(400, array('code' => 400, 'status' => 'BAD_REQUEST', 'message' => 'external_worker_reference excede el máximo permitido de 64 caracteres.'));
            return;
        }

        if (!$this->validateUuidV4($validationUuid)) {
            $this->closeConexion($conexion);
            $this->sendJsonResponse(400, array('code' => 400, 'status' => 'INVALID_UUID', 'message' => 'El campo validation_uuid debe ser un UUIDv4 válido.'));
            return;
        }

        $result = $this->service->validatePin($conexion, $idDispositivo, $idEstacionResuelta, $refExternaPersonal, $temporaryPin, $validationUuid);
        $this->closeConexion($conexion);

        if (!$result['success']) {
            $resp = array('code' => $result['code'], 'status' => $result['status']);
            if (isset($result['attempts_remaining'])) {
                $resp['attempts_remaining'] = $result['attempts_remaining'];
            }
            if (isset($result['mensaje'])) {
                $resp['mensaje'] = $result['mensaje'];
            } else if (isset($result['message'])) {
                $resp['mensaje'] = $result['message'];
            }
            $this->sendJsonResponse($result['code'], $resp);
        } else {
            $this->sendJsonResponse($result['code'], $result['data']);
        }
    }

    private function autenticarDispositivo() {
        $deviceIdHeader = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
        $credentialKeyHeader = $_SERVER['HTTP_X_CREDENTIAL_KEY'] ?? '';
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        $secret = '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $secret = trim($matches[1]);
        }

        if (empty($deviceIdHeader) || empty($credentialKeyHeader) || empty($secret)) {
            return array('success' => false, 'code' => 401, 'message' => 'Credenciales de dispositivo incompletas.');
        }

        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        $serviceDispositivo = new DispositivoV2();
        $authResult = $serviceDispositivo->autenticarCredencial($conexion, $deviceIdHeader, $credentialKeyHeader, $secret);

        if (!$authResult['auth']) {
            $classBD->desconectarBD($conexion);
            return array('success' => false, 'code' => 401, 'message' => 'Autenticación de dispositivo fallida: ' . $authResult['error']);
        }

        return array(
            'success' => true,
            'conexion' => $conexion,
            'id_dispositivo' => $authResult['id_dispositivo'],
            'id_estacion' => $authResult['id_estacion']
        );
    }

    private function autenticarPortal3User(string $requiredPermission) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (empty($authHeader)) {
            return array('success' => false, 'code' => 401, 'status' => 'UNAUTHORIZED', 'message' => 'Header Authorization ausente.');
        }

        $classBD = new BD();
        $conexion = $classBD->conectarBD();
        if (!$conexion) {
            return array('success' => false, 'code' => 503, 'status' => 'AUTH_DEPENDENCY_UNAVAILABLE', 'message' => 'Servicio de autorización no disponible.');
        }

        $tokenVal = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (empty($tokenVal['valid'])) {
            $classBD->desconectarBD($conexion);
            return array('success' => false, 'code' => $tokenVal['code'] ?? 401, 'status' => $tokenVal['status'] ?? 'UNAUTHORIZED', 'message' => $tokenVal['message'] ?? $tokenVal['error'] ?? 'Autenticación inválida.');
        }

        $authorization = Portal3JwtAuthHelper::authorizeTemporaryAccess($conexion, $tokenVal, $requiredPermission);
        if (empty($authorization['success'])) {
            $classBD->desconectarBD($conexion);
            return $authorization;
        }

        return array(
            'success' => true,
            'conexion' => $conexion,
            'user_id' => $tokenVal['user_id'],
            'username' => $tokenVal['username'],
            'authorized_stations' => $authorization['authorized_stations']
        );
    }

    private function validateUuidV4($uuid) {
        if (empty($uuid) || !is_string($uuid)) {
            return false;
        }
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', trim($uuid));
    }

    private function sendJsonResponse($code, $data) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function closeConexion($conexion) {
        if ($conexion) {
            $classBD = new BD();
            $classBD->desconectarBD($conexion);
        }
    }
}
