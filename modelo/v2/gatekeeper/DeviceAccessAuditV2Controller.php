<?php
/**
 * Checador V2 — Device Access Audit HTTP Controller (API-02)
 *
 * Implements:
 * POST /api/v2/device/access-audits
 *
 * Specification: docs/v2/CHECADOR_V2_DEVICE_ENTRY_WINDOW_API_CONTRACT_V1.0.md (APPROVED_FROZEN_V1.4)
 * Architecture Addendum: docs/architecture/CHECADOR_V2_EARLY_ENTRY_GATEKEEPER_ADDENDUM_V1.0.md (APPROVED_FROZEN_V1.4)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';
require_once __DIR__ . '/../DispositivoV2.php';
require_once __DIR__ . '/DeviceAccessAuditV2Service.php';

class DeviceAccessAuditV2Controller
{
    private ?DeviceAccessAuditV2Service $service;

    public function __construct(?DeviceAccessAuditV2Service $service = null)
    {
        $this->service = $service;
    }

    /**
     * Dispatcher para POST /api/v2/device/access-audits
     */
    public function handlePostAccessAudit(): void
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

        // 3. Validar Content-Type
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') === false) {
            $classBD->desconectarBD($conexion);
            http_response_code(415);
            echo json_encode([
                "code" => 415,
                "status" => "UNSUPPORTED_MEDIA_TYPE",
                "mensaje" => "Content-Type debe ser application/json."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 4. Decodificar cuerpo de solicitud
        $rawInput = file_get_contents('php://input');
        $body = json_decode($rawInput, true);

        if (!is_array($body)) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode([
                "code" => 400,
                "status" => "INVALID_PAYLOAD",
                "mensaje" => "Cuerpo de solicitud JSON inválido o malformado."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 5. Invocar servicio de auditoría
        $auditService = $this->service ?? new DeviceAccessAuditV2Service($conexion);

        try {
            $result = $auditService->recordAccessAudit($idDispositivo, $idEstacion, $body);
        } catch (Throwable $e) {
            $classBD->desconectarBD($conexion);
            http_response_code(500);
            echo json_encode([
                "code" => 500,
                "status" => "INTERNAL_SERVER_ERROR",
                "mensaje" => "Error interno al procesar auditoría de acceso."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $classBD->desconectarBD($conexion);

        // 6. Emitir respuesta HTTP
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
                "mensaje" => $result['message'] ?? "Error al registrar intento de auditoría."
            ], JSON_UNESCAPED_UNICODE);
        }
    }
}
