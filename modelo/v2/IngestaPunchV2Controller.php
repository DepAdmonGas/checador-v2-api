<?php
include_once __DIR__ . "/../BD.php";
include_once __DIR__ . "/DispositivoV2.php";
include_once __DIR__ . "/PunchEventV2.php";
include_once __DIR__ . "/EventUuidLengthValidator.php";

class IngestaPunchV2Controller {

    public function procesarIngesta() {
        header('Content-Type: application/json; charset=utf-8');

        $recibidoAtUtc = gmdate('Y-m-d H:i:s');

        $deviceIdHeader = $_SERVER['HTTP_X_DEVICE_ID'] ?? '';
        $credentialKeyHeader = $_SERVER['HTTP_X_CREDENTIAL_KEY'] ?? '';
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        $secret = '';
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $secret = trim($matches[1]);
        }

        if (trim((string)$deviceIdHeader) === '' || trim((string)$credentialKeyHeader) === '' || $secret === '') {
            http_response_code(401);
            echo json_encode(array(
                "code" => 401,
                "status" => "NO AUTORIZADO",
                "mensaje" => "Credenciales de dispositivo ausentes o incompletas en headers HTTP."
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        $serviceDispositivo = new DispositivoV2();
        $authResult = $serviceDispositivo->autenticarCredencial($conexion, $deviceIdHeader, $credentialKeyHeader, $secret);

        if (!$authResult['auth']) {
            $classBD->desconectarBD($conexion);
            http_response_code(401);
            echo json_encode(array(
                "code" => 401,
                "status" => "NO AUTORIZADO",
                "mensaje" => "Autenticación de dispositivo fallida.",
                "error_detail" => $authResult['error']
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        $idDispositivo = (int)$authResult['id_dispositivo'];
        $idEstacionResuelta = $authResult['id_estacion'];
        $deviceDefaultTimezone = $authResult['device_timezone'];

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') === false) {
            $classBD->desconectarBD($conexion);
            http_response_code(415);
            echo json_encode(array(
                "code" => 415,
                "status" => "UNSUPPORTED_MEDIA_TYPE",
                "mensaje" => "Content-Type debe ser application/json."
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        $inputRaw = file_get_contents('php://input');
        $body = json_decode($inputRaw, true);

        if (!$body || !is_array($body)) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array(
                "code" => 400,
                "status" => "SOLICITUD INCORRECTA",
                "mensaje" => "Cuerpo de solicitud JSON inválido o malformado."
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        if (array_key_exists('device_uuid', $body) && $body['device_uuid'] !== null) {
            $bodyDeviceUuid = is_string($body['device_uuid']) ? trim($body['device_uuid']) : '';
            if ($bodyDeviceUuid === '' || $bodyDeviceUuid !== ($authResult['uuid_dispositivo'] ?? '')) {
                $classBD->desconectarBD($conexion);
                http_response_code(403);
                echo json_encode(array(
                    "code" => 403,
                    "status" => "DEVICE_MISMATCH",
                    "mensaje" => "El dispositivo autenticado no coincide con el UUID proporcionado."
                ), JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        if (array_key_exists('id_dispositivo', $body) && $body['id_dispositivo'] !== null) {
            $rawBodyDevId = $body['id_dispositivo'];
            $bodyDevId = is_int($rawBodyDevId)
                ? $rawBodyDevId
                : (is_string($rawBodyDevId) && preg_match('/^[1-9][0-9]*$/', trim($rawBodyDevId)) === 1 ? (int)trim($rawBodyDevId) : -1);
            if ($bodyDevId !== $idDispositivo) {
                $classBD->desconectarBD($conexion);
                http_response_code(403);
                echo json_encode(array(
                    "code" => 403,
                    "status" => "DEVICE_MISMATCH",
                    "mensaje" => "El dispositivo autenticado no coincide con el id_dispositivo proporcionado."
                ), JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        $eventUuid = EventUuidLengthValidator::normalize($body['event_uuid'] ?? '');
        $externalWorkerRef = trim((string)($body['external_worker_reference'] ?? ''));
        $sourceTimestampRaw = trim($body['source_timestamp'] ?? '');
        $sourceTimezoneReported = isset($body['source_timezone']) ? trim($body['source_timezone']) : null;
        $captureMethod = trim($body['capture_method'] ?? 'BIOMETRIC');
        $externalEventId = isset($body['external_event_id']) ? trim((string)$body['external_event_id']) : null;
        $hasDeviceGeneration = array_key_exists('device_generation', $body) && $body['device_generation'] !== null;
        $hasDeviceSequence = array_key_exists('device_sequence', $body) && $body['device_sequence'] !== null;
        $deviceGeneration = $hasDeviceGeneration ? trim((string)$body['device_generation']) : null;
        $deviceSequence = $hasDeviceSequence ? (int)$body['device_sequence'] : null;
        $metadata = isset($body['metadata']) && is_array($body['metadata']) ? $body['metadata'] : array();

        if (empty($eventUuid) || empty($externalWorkerRef) || empty($sourceTimestampRaw)) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array(
                "code" => 400,
                "status" => "SOLICITUD INCORRECTA",
                "mensaje" => "Campos obligatorios ausentes: event_uuid, external_worker_reference, source_timestamp."
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        if (EventUuidLengthValidator::exceedsMaximum($eventUuid)) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array(
                "code" => 400,
                "status" => "SOLICITUD INCORRECTA",
                "mensaje" => "event_uuid excede el máximo permitido de 64 caracteres."
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        if (strlen($externalWorkerRef) > 64) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array(
                "code" => 400,
                "status" => "SOLICITUD INCORRECTA",
                "mensaje" => "external_worker_reference excede el máximo permitido de 64 caracteres."
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($hasDeviceGeneration && strlen($deviceGeneration) > 64) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array(
                "code" => 400,
                "status" => "BAD_REQUEST",
                "mensaje" => "device_generation excede el máximo permitido de 64 caracteres."
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($hasDeviceGeneration !== $hasDeviceSequence) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array(
                "code" => 400,
                "status" => "BAD_REQUEST",
                "mensaje" => "device_generation y device_sequence deben enviarse juntos para eventos Nivel 2."
            ), JSON_UNESCAPED_UNICODE);
            return;
        }

        $validationFlags = array();

        $effectiveTimezone = null;
        $timezoneFallbackApplied = 0;
        $ocurridoAtUtc = null;

        $reportedZoneValid = false;
        if (!empty($sourceTimezoneReported) && in_array($sourceTimezoneReported, DateTimeZone::listIdentifiers(), true)) {
            $reportedZoneValid = true;
            $effectiveTimezone = $sourceTimezoneReported;
            $timezoneFallbackApplied = 0;
        } else {
            if (!empty($sourceTimezoneReported)) {
                $validationFlags[] = 'INVALID_TIMEZONE_REPORTED';
            }
            if (!empty($deviceDefaultTimezone) && in_array($deviceDefaultTimezone, DateTimeZone::listIdentifiers(), true)) {
                $effectiveTimezone = $deviceDefaultTimezone;
                $timezoneFallbackApplied = 1;
            }
        }

        if ($effectiveTimezone === null) {
            $validationFlags[] = 'INVALID_DEVICE_TIMEZONE';
            $ocurridoAtUtc = null;
        } else {
            try {
                $dt = new DateTime($sourceTimestampRaw, new DateTimeZone($effectiveTimezone));
                $dt->setTimezone(new DateTimeZone('UTC'));
                $ocurridoAtUtc = $dt->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                $validationFlags[] = 'TIMESTAMP_PARSE_ERROR';
                $ocurridoAtUtc = null;
            }
        }

        if ($ocurridoAtUtc !== null) {
            $tsOcurrido = strtotime($ocurridoAtUtc);
            $tsRecibido = strtotime($recibidoAtUtc);
            if (abs($tsRecibido - $tsOcurrido) > 900) {
                $validationFlags[] = 'CLOCK_DRIFT_ALERT';
            }
        }

        $servicePunch = new PunchEventV2();

        $idPersonalResuelto = null;
        if ($ocurridoAtUtc !== null) {
            $idPersonalResuelto = $servicePunch->resolverTrabajadorHistorico($conexion, $idDispositivo, $externalWorkerRef, $ocurridoAtUtc);
        }

        $processingStatus = ($idPersonalResuelto !== null) ? 'RECEIVED' : 'PENDING_MATCH';

        $datosIngesta = array(
            'event_uuid' => $eventUuid,
            'id_dispositivo' => $idDispositivo,
            'id_estacion_resuelta' => $idEstacionResuelta,
            'referencia_externa_personal' => $externalWorkerRef,
            'id_personal_resuelto' => $idPersonalResuelto,
            'source_timestamp_raw' => $sourceTimestampRaw,
            'source_timezone_reported' => $sourceTimezoneReported,
            'effective_timezone' => $effectiveTimezone,
            'timezone_fallback_applied' => $timezoneFallbackApplied,
            'ocurrido_at_utc' => $ocurridoAtUtc,
            'recibido_at_utc' => $recibidoAtUtc,
            'metodo_captura' => $captureMethod,
            'external_event_id' => $externalEventId,
            'secuencia_dispositivo' => $deviceSequence,
            'processing_status' => $processingStatus,
            'validation_flags' => $validationFlags,
            'metadata' => $metadata
        );

        $resultado = $servicePunch->registrarPunchEvent($conexion, $datosIngesta);

        // Los eventos con tupla Nivel 2 sólo pueden confirmar entrega cuando el sidecar quedó vinculado.
        if (($resultado['status_code'] === 201 || $resultado['status_code'] === 200) && $hasDeviceGeneration && $hasDeviceSequence) {
            $idRawPunch = (int)($resultado['id_raw_punch'] ?? $resultado['id'] ?? 0);
            if ($idRawPunch <= 0) {
                // Consultar id_raw_punch si no fue retornado directamente
                $stmtGetId = $conexion->prepare('SELECT id FROM op_rh_v2_marcaciones_crudas WHERE id_dispositivo = ? AND event_uuid = ? LIMIT 1');
                $stmtGetId->bind_param('is', $idDispositivo, $eventUuid);
                $stmtGetId->execute();
                $idRawPunch = (int)($stmtGetId->get_result()->fetch_assoc()['id'] ?? 0);
                $stmtGetId->close();
            }

            if ($idRawPunch <= 0) {
                $classBD->desconectarBD($conexion);
                http_response_code(500);
                echo json_encode(array(
                    "code" => 500,
                    "status" => "INTERNAL_ERROR",
                    "mensaje" => "No fue posible recuperar el identificador de la marcación para completar Nivel 2."
                ), JSON_UNESCAPED_UNICODE);
                return;
            }

            include_once __DIR__ . "/Level2ServerService.php";
            $l2Service = new Level2ServerService();
            $l2Res = $l2Service->procesarSecuenciaNivel2($conexion, $idRawPunch, $idDispositivo, $deviceGeneration, $deviceSequence);
            if (empty($l2Res['success'])) {
                $l2Code = (int)($l2Res['code'] ?? 500);
                if (!in_array($l2Code, array(400, 409, 500), true)) {
                    $l2Code = 500;
                }
                $l2Status = (string)($l2Res['status'] ?? 'INTERNAL_ERROR');
                $l2Message = (string)($l2Res['error'] ?? 'Error interno al completar el vínculo Nivel 2.');
                $classBD->desconectarBD($conexion);
                http_response_code($l2Code);
                echo json_encode(array(
                    "code" => $l2Code,
                    "status" => $l2Status,
                    "mensaje" => $l2Message
                ), JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        $classBD->desconectarBD($conexion);

        http_response_code($resultado['status_code']);
        echo json_encode(array(
            "code" => $resultado['status_code'],
            "status" => $resultado['result'],
            "event_uuid" => $resultado['event_uuid'],
            "recibido_at_utc" => $recibidoAtUtc,
            "processing_status" => $resultado['processing_status'],
            "id_personal_resuelto" => $idPersonalResuelto,
            "validation_flags" => $validationFlags
        ), JSON_UNESCAPED_UNICODE);
    }
}
