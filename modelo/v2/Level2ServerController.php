<?php
include_once __DIR__ . "/../BD.php";
include_once __DIR__ . "/DispositivoV2.php";
include_once __DIR__ . "/Level2ServerService.php";
include_once __DIR__ . "/Portal3JwtAuthHelper.php";
include_once __DIR__ . "/WorkplacePhysicalStationResolver.php";

class Level2ServerController {

    // POST /api/v2/devices/generations
    public function handleRegisterGeneration() {
        header('Content-Type: application/json; charset=utf-8');

        $deviceIdHeader = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
        $credentialKeyHeader = $_SERVER['HTTP_X_CREDENTIAL_KEY'] ?? '';
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        $secret = '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $secret = trim($matches[1]);
        }

        if (trim((string)$deviceIdHeader) === '' || trim((string)$credentialKeyHeader) === '' || $secret === '') {
            http_response_code(401);
            echo json_encode(array("code" => 401, "status" => "UNAUTHORIZED", "mensaje" => "Credenciales de dispositivo ausentes."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        $serviceDispositivo = new DispositivoV2();
        $authResult = $serviceDispositivo->autenticarCredencial($conexion, $deviceIdHeader, $credentialKeyHeader, $secret);

        if (!$authResult['auth']) {
            $classBD->desconectarBD($conexion);
            http_response_code(401);
            echo json_encode(array("code" => 401, "status" => "UNAUTHORIZED", "mensaje" => "Autenticación de dispositivo fallida.", "error_detail" => $authResult['error']), JSON_UNESCAPED_UNICODE);
            return;
        }

        $idDispositivo = (int)$authResult['id_dispositivo'];

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') === false) {
            $classBD->desconectarBD($conexion);
            http_response_code(415);
            echo json_encode(array("code" => 415, "status" => "UNSUPPORTED_MEDIA_TYPE", "mensaje" => "Content-Type debe ser application/json."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $inputRaw = file_get_contents('php://input');
        $body = json_decode($inputRaw, true);
        if (!is_array($body)) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "BAD_REQUEST", "mensaje" => "Cuerpo de solicitud JSON inválido o malformado."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $deviceUuid = trim($body['device_uuid'] ?? '');
        $deviceGeneration = trim($body['device_generation'] ?? '');

        if (empty($deviceUuid) || empty($deviceGeneration)) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "BAD_REQUEST", "mensaje" => "device_uuid y device_generation son obligatorios."), JSON_UNESCAPED_UNICODE);
            return;
        }

        if (strlen($deviceUuid) > 64) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "BAD_REQUEST", "mensaje" => "device_uuid excede el máximo permitido de 64 caracteres."), JSON_UNESCAPED_UNICODE);
            return;
        }

        if (strlen($deviceGeneration) > 64) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "BAD_REQUEST", "mensaje" => "device_generation excede el máximo permitido de 64 caracteres."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $l2Service = new Level2ServerService();
        $res = $l2Service->registrarGeneracion($conexion, $idDispositivo, $deviceUuid, $deviceGeneration);

        $classBD->desconectarBD($conexion);
        http_response_code($res['code']);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
    }

    // POST /api/v2/devices/telemetry
    public function handleIngestTelemetry() {
        header('Content-Type: application/json; charset=utf-8');

        $deviceIdHeader = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
        $credentialKeyHeader = $_SERVER['HTTP_X_CREDENTIAL_KEY'] ?? '';
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        $secret = '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $secret = trim($matches[1]);
        }

        if (trim((string)$deviceIdHeader) === '' || trim((string)$credentialKeyHeader) === '' || $secret === '') {
            http_response_code(401);
            echo json_encode(array("code" => 401, "status" => "UNAUTHORIZED", "mensaje" => "Credenciales de dispositivo ausentes."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        $serviceDispositivo = new DispositivoV2();
        $authResult = $serviceDispositivo->autenticarCredencial($conexion, $deviceIdHeader, $credentialKeyHeader, $secret);

        if (!$authResult['auth']) {
            $classBD->desconectarBD($conexion);
            http_response_code(401);
            echo json_encode(array("code" => 401, "status" => "UNAUTHORIZED", "mensaje" => "Autenticación de dispositivo fallida.", "error_detail" => $authResult['error']), JSON_UNESCAPED_UNICODE);
            return;
        }

        $idDispositivo = (int)$authResult['id_dispositivo'];

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') === false) {
            $classBD->desconectarBD($conexion);
            http_response_code(415);
            echo json_encode(array("code" => 415, "status" => "UNSUPPORTED_MEDIA_TYPE", "mensaje" => "Content-Type debe ser application/json."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $inputRaw = file_get_contents('php://input');
        $body = json_decode($inputRaw, true);
        if (!is_array($body)) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "BAD_REQUEST", "mensaje" => "Cuerpo de solicitud JSON inválido o malformado."), JSON_UNESCAPED_UNICODE);
            return;
        }

        if (array_key_exists('device_uuid', $body) && $body['device_uuid'] !== null) {
            $bodyDeviceUuid = is_string($body['device_uuid']) ? trim($body['device_uuid']) : '';
            if ($bodyDeviceUuid === '' || $bodyDeviceUuid !== ($authResult['uuid_dispositivo'] ?? '')) {
                $classBD->desconectarBD($conexion);
                http_response_code(403);
                echo json_encode(array("code" => 403, "status" => "DEVICE_MISMATCH", "mensaje" => "El dispositivo autenticado no coincide con el UUID proporcionado."), JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        $deviceGeneration = is_string($body['device_generation'] ?? null) ? trim($body['device_generation']) : '';
        $sequenceValue = $body['telemetry_sequence'] ?? null;
        $telemetrySequence = is_int($sequenceValue) ? $sequenceValue : null;
        $observationRaw = is_string($body['observation_timestamp'] ?? null) ? trim($body['observation_timestamp']) : '';
        $batteryLevel = isset($body['battery_level']) ? (int)$body['battery_level'] : null;
        $networkStatus = isset($body['network_status']) ? trim($body['network_status']) : null;
        $clockStatus = isset($body['clock_status']) ? trim($body['clock_status']) : null;
        $payloadJson = isset($body['payload']) && is_array($body['payload']) ? $body['payload'] : [];

        if (empty($deviceGeneration) || $telemetrySequence === null || $telemetrySequence < 1 || empty($observationRaw)) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "BAD_REQUEST", "mensaje" => "device_generation, telemetry_sequence entero desde 1 y observation_timestamp son obligatorios."), JSON_UNESCAPED_UNICODE);
            return;
        }

        if (strlen($deviceGeneration) > 64) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "BAD_REQUEST", "mensaje" => "device_generation excede el máximo permitido de 64 caracteres."), JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($networkStatus !== null && strlen($networkStatus) > 32) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "BAD_REQUEST", "mensaje" => "network_status excede el máximo permitido de 32 caracteres."), JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($clockStatus !== null && strlen($clockStatus) > 32) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "BAD_REQUEST", "mensaje" => "clock_status excede el máximo permitido de 32 caracteres."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $l2Service = new Level2ServerService();
        $res = $l2Service->registrarTelemetria($conexion, $idDispositivo, $deviceGeneration, $telemetrySequence, $observationRaw, $batteryLevel, $networkStatus, $clockStatus, $payloadJson);

        $classBD->desconectarBD($conexion);
        http_response_code($res['code']);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
    }

    // GET /api/v2/devices/context
    public function handleGetDeviceContext() {
        header('Content-Type: application/json; charset=utf-8');

        $deviceIdHeader = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
        $credentialKeyHeader = $_SERVER['HTTP_X_CREDENTIAL_KEY'] ?? '';
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        $secret = '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $secret = trim($matches[1]);
        }

        if (trim((string)$deviceIdHeader) === '' || trim((string)$credentialKeyHeader) === '' || $secret === '') {
            http_response_code(401);
            echo json_encode(array("code" => 401, "status" => "UNAUTHORIZED", "mensaje" => "Credenciales de dispositivo ausentes."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        $serviceDispositivo = new DispositivoV2();
        $authResult = $serviceDispositivo->autenticarCredencial($conexion, $deviceIdHeader, $credentialKeyHeader, $secret);

        if (!$authResult['auth']) {
            $classBD->desconectarBD($conexion);
            http_response_code(401);
            echo json_encode(array("code" => 401, "status" => "UNAUTHORIZED", "mensaje" => "Autenticación de dispositivo fallida.", "error_detail" => $authResult['error']), JSON_UNESCAPED_UNICODE);
            return;
        }

        $device = $serviceDispositivo->obtenerDispositivoPorUuid($conexion, $deviceIdHeader);
        $classBD->desconectarBD($conexion);

        if (!$device) {
            http_response_code(404);
            echo json_encode(array("code" => 404, "status" => "NOT_FOUND", "mensaje" => "Dispositivo no encontrado."), JSON_UNESCAPED_UNICODE);
            return;
        }

        http_response_code(200);
        echo json_encode(array(
            "code" => 200,
            "status" => "OK",
            "id_dispositivo" => (int)$authResult['id_dispositivo'],
            "device_uuid" => $device['uuid_dispositivo'],
            "id_estacion" => (int)$authResult['id_estacion'],
            "device_name" => $device['nombre'] ?? '',
            "device_timezone" => $authResult['device_timezone'],
            "estado" => (int)($device['estado'] ?? 1)
        ), JSON_UNESCAPED_UNICODE);
    }

    // GET /api/v2/level2/window-status
    public function handleGetWindowStatus() {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        // 1. Validar JWT de Portal3
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            http_response_code($auth['code']);
            echo json_encode(array("code" => $auth['code'], "status" => $auth['status'], "mensaje" => $auth['error']), JSON_UNESCAPED_UNICODE);
            return;
        }

        // 2. Autorizar permiso funcional 'leer' en módulo 'biometricos' y scope de estación
        $authz = Portal3JwtAuthHelper::authorizeBiometricos($conexion, $auth, 'leer');
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            http_response_code($authz['code']);
            echo json_encode(array("code" => $authz['code'], "status" => $authz['status'], "mensaje" => $authz['error']), JSON_UNESCAPED_UNICODE);
            return;
        }

        $authorizedStations = $authz['authorized_stations'];

        $stationIdParam = isset($_GET['station_id']) && ctype_digit((string)$_GET['station_id']) ? (int)$_GET['station_id'] : null;
        $fromParam = isset($_GET['from']) ? trim((string)$_GET['from']) : null;
        $toParam = isset($_GET['to']) ? trim((string)$_GET['to']) : null;

        if ($stationIdParam === null || empty($fromParam) || empty($toParam)) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "BAD_REQUEST", "mensaje" => "Parámetros obligatorios ausentes: station_id, from, to."), JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!in_array($stationIdParam, $authorizedStations, true)) {
            $classBD->desconectarBD($conexion);
            http_response_code(403);
            echo json_encode(array("code" => 403, "status" => "STATION_SCOPE_DENIED", "mensaje" => "Acceso denegado: la estación solicitada no está autorizada."), JSON_UNESCAPED_UNICODE);
            return;
        }

        // Portal3 authorizes canonical locality IDs, while Level 2 device
        // history and evaluations are keyed by physical tb_estaciones IDs.
        // Resolve only after ACL validation and fail closed if the mapping is
        // missing or ambiguous.
        $physicalStationId = WorkplacePhysicalStationResolver::resolve($conexion, $stationIdParam);
        if ($physicalStationId === null) {
            $res = [
                'window_level2_status' => 'UNKNOWN',
                'delivery_completeness' => 'UNKNOWN',
                'operational_coverage' => 'UNKNOWN',
                'time_window_attribution' => 'UNKNOWN',
                'has_zero_governed_events' => false,
                'governed_event_count' => 0,
                'evaluation_ref' => null,
                'manifest_digest' => null,
                'evaluated_at_utc' => null,
            ];
        } else {
            $l2Service = new Level2ServerService();
            $res = $l2Service->evaluarVentanaNivel2($conexion, $physicalStationId, $fromParam, $toParam);
        }

        $classBD->desconectarBD($conexion);
        http_response_code(200);

        $responseBody = [
            "status" => "success",
            "data" => [
                "window_level2_status" => $res['window_level2_status'],
                "delivery_completeness" => $res['delivery_completeness'],
                "operational_coverage" => $res['operational_coverage'],
                "time_window_attribution" => $res['time_window_attribution'],
                "has_zero_governed_events" => $res['has_zero_governed_events'],
                "governed_event_count" => $res['governed_event_count'] ?? 0,
                "evaluation_ref" => $res['evaluation_ref'] ?? null,
                "manifest_digest" => $res['manifest_digest'] ?? null,
                "evaluated_at_utc" => $res['evaluated_at_utc'] ?? null
            ]
        ];

        if ($res['window_level2_status'] === 'VERIFIED' && $res['has_zero_governed_events']) {
            $responseBody['data']['statement'] = 'No governed captured PunchEvent exists within the verified Level 2 source contract for the specified source window';
        }

        echo json_encode($responseBody, JSON_UNESCAPED_UNICODE);
    }
}
