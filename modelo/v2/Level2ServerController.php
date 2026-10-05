<?php
include_once __DIR__ . "/../BD.php";
include_once __DIR__ . "/DispositivoV2.php";
include_once __DIR__ . "/Level2ServerService.php";
include_once __DIR__ . "/Portal3JwtAuthHelper.php";

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

        if (empty($deviceIdHeader) || empty($credentialKeyHeader) || empty($secret)) {
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
            echo json_encode(array("code" => 401, "status" => "UNAUTHORIZED", "mensaje" => "Autenticación de dispositivo fallida."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $idDispositivo = (int)$authResult['id_dispositivo'];

        $inputRaw = file_get_contents('php://input');
        $body = json_decode($inputRaw, true);
        $deviceUuid = trim($body['device_uuid'] ?? '');
        $deviceGeneration = trim($body['device_generation'] ?? '');

        if (empty($deviceUuid) || empty($deviceGeneration)) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "BAD_REQUEST", "mensaje" => "device_uuid y device_generation son obligatorios."), JSON_UNESCAPED_UNICODE);
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

        if (empty($deviceIdHeader) || empty($credentialKeyHeader) || empty($secret)) {
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
            echo json_encode(array("code" => 401, "status" => "UNAUTHORIZED", "mensaje" => "Autenticación de dispositivo fallida."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $idDispositivo = (int)$authResult['id_dispositivo'];

        $inputRaw = file_get_contents('php://input');
        $body = json_decode($inputRaw, true);

        $deviceGeneration = trim($body['device_generation'] ?? '');
        $telemetrySequence = isset($body['telemetry_sequence']) ? (int)$body['telemetry_sequence'] : null;
        $observationRaw = trim($body['observation_timestamp'] ?? '');
        $batteryLevel = isset($body['battery_level']) ? (int)$body['battery_level'] : null;
        $networkStatus = isset($body['network_status']) ? trim($body['network_status']) : null;
        $clockStatus = isset($body['clock_status']) ? trim($body['clock_status']) : null;
        $payloadJson = isset($body['payload']) && is_array($body['payload']) ? $body['payload'] : [];

        if (empty($deviceGeneration) || $telemetrySequence === null || empty($observationRaw)) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "BAD_REQUEST", "mensaje" => "device_generation, telemetry_sequence y observation_timestamp son obligatorios."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $l2Service = new Level2ServerService();
        $res = $l2Service->registrarTelemetria($conexion, $idDispositivo, $deviceGeneration, $telemetrySequence, $observationRaw, $batteryLevel, $networkStatus, $clockStatus, $payloadJson);

        $classBD->desconectarBD($conexion);
        http_response_code($res['code']);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
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
        $authz = Portal3JwtAuthHelper::authorizeTemporaryAccess($conexion, $auth, 'leer');
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

        $l2Service = new Level2ServerService();
        $res = $l2Service->evaluarVentanaNivel2($conexion, $stationIdParam, $fromParam, $toParam);

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
