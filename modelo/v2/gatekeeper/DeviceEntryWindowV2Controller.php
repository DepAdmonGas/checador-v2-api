<?php
/**
 * Checador V2 — Device Entry Window Gatekeeper HTTP Controller (API-01)
 *
 * Implements:
 * GET /api/v2/device/workers/{external_worker_reference}/entry-window
 *
 * Specification: docs/v2/CHECADOR_V2_DEVICE_ENTRY_WINDOW_API_CONTRACT_V1.0.md (APPROVED_FROZEN_V1.4)
 * Architecture Addendum: docs/architecture/CHECADOR_V2_EARLY_ENTRY_GATEKEEPER_ADDENDUM_V1.0.md (APPROVED_FROZEN_V1.4)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';
require_once __DIR__ . '/../DispositivoV2.php';
require_once __DIR__ . '/DeviceEntryWindowV2Service.php';

class DeviceEntryWindowV2Controller
{
    private ?DeviceEntryWindowV2Service $service;

    public function __construct(?DeviceEntryWindowV2Service $service = null)
    {
        $this->service = $service;
    }

    /**
     * Dispatcher para GET /api/v2/device/workers/{external_ref}/entry-window
     */
    public function handleGetEntryWindow(string $externalWorkerRef): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // 1. Extraer credenciales de dispositivo desde headers HTTP
        $deviceIdHeader = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
        $credentialKeyHeader = $_SERVER['HTTP_X_CREDENTIAL_KEY'] ?? '';
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (empty($authHeader) && function_exists('obtenerAuthorizationHeader')) {
            $authHeader = (string)obtenerAuthorizationHeader();
        }

        $secret = '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $secret = trim($matches[1]);
        }

        if (trim((string)$deviceIdHeader) === '' || trim((string)$credentialKeyHeader) === '' || $secret === '') {
            http_response_code(401);
            echo json_encode([
                "code" => 401,
                "status" => "NO AUTORIZADO",
                "mensaje" => "Credenciales de dispositivo ausentes o incompletas en headers HTTP."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 2. Autenticar credencial mediante DispositivoV2
        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        $serviceDispositivo = new DispositivoV2();
        $authResult = $serviceDispositivo->autenticarCredencial(
            $conexion,
            $deviceIdHeader,
            $credentialKeyHeader,
            $secret
        );

        if (!$authResult['auth']) {
            $classBD->desconectarBD($conexion);
            http_response_code(401);
            echo json_encode([
                "code" => 401,
                "status" => "NO AUTORIZADO",
                "mensaje" => "Autenticación de dispositivo fallida.",
                "error_detail" => $authResult['error']
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $idDispositivo = (int)$authResult['id_dispositivo'];
        $idEstacion = (int)$authResult['id_estacion'];
        $deviceDefaultTimezone = $authResult['device_timezone'] ?? null;

        // 3. Extraer parámetros query
        $currentTimestampLocal = isset($_GET['current_timestamp_local']) ? trim((string)$_GET['current_timestamp_local']) : null;
        if ($currentTimestampLocal === '') {
            $currentTimestampLocal = null;
        }

        $pendingCountRaw = $_GET['pending_local_punches_count'] ?? 0;
        $pendingLocalPunchesCount = is_numeric($pendingCountRaw) ? (int)$pendingCountRaw : 0;

        $lastLocalPendingTimestamp = isset($_GET['last_local_pending_timestamp']) ? trim((string)$_GET['last_local_pending_timestamp']) : null;
        if ($lastLocalPendingTimestamp === '') {
            $lastLocalPendingTimestamp = null;
        }

        $lastLocalPendingUuid = isset($_GET['last_local_pending_uuid']) ? trim((string)$_GET['last_local_pending_uuid']) : null;
        if ($lastLocalPendingUuid === '') {
            $lastLocalPendingUuid = null;
        }

        // 4. Instanciar o reutilizar servicio de dominio
        $entryWindowService = $this->service ?? new DeviceEntryWindowV2Service($conexion);

        try {
            $result = $entryWindowService->evaluateEntryWindow(
                $idDispositivo,
                $idEstacion,
                $deviceDefaultTimezone,
                $externalWorkerRef,
                $currentTimestampLocal,
                $pendingLocalPunchesCount,
                $lastLocalPendingTimestamp,
                $lastLocalPendingUuid
            );
        } catch (Throwable $e) {
            $classBD->desconectarBD($conexion);
            http_response_code(500);
            echo json_encode([
                "code" => 500,
                "status" => "INTERNAL_SERVER_ERROR",
                "mensaje" => "Error interno al evaluar ventana de entrada."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $classBD->desconectarBD($conexion);

        // 5. Emitir respuesta HTTP conforme a contrato
        $httpCode = $result['code'] ?? 200;
        http_response_code($httpCode);

        if (!empty($result['success']) && $result['success'] === true) {
            echo json_encode([
                "status" => "success",
                "data" => $result['data']
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                "code" => $httpCode,
                "status" => $result['status'] ?? "ERROR",
                "mensaje" => $result['message'] ?? "Error en la consulta."
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}
