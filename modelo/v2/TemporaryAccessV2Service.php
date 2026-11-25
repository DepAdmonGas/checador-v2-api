<?php

require_once __DIR__ . '/TemporaryAccessRequestScopePrepared.php';

class TemporaryAccessV2Service {

    const REQUEST_TTL_MINUTES = 30;
    const PIN_TTL_MINUTES = 15;
    const PIN_LENGTH = 6;
    const PIN_MAX_ATTEMPTS = 3;

    /**
     * POST /api/v2/temporary-access/requests (Lector Device Auth)
     */
    public function createRequest($conexion, $idDispositivo, $idEstacionResuelta, $requestUuid, $refExternaPersonal, $reason = 'FINGERPRINT_NOT_RECOGNIZED', $idCredencial = null) {
        if (empty($requestUuid)) {
            return array('success' => false, 'code' => 400, 'status' => 'INVALID_PAYLOAD', 'message' => 'El campo request_uuid es requerido.');
        }

        if (empty($refExternaPersonal)) {
            return array('success' => false, 'code' => 400, 'status' => 'INVALID_PAYLOAD', 'message' => 'El campo external_worker_reference es requerido.');
        }

        // Resolver id_personal_resuelto
        $idPersonalResuelto = $this->resolverPersonalId($conexion, $idDispositivo, $refExternaPersonal);
        if (!$idPersonalResuelto) {
            return array(
                'success' => false,
                'code' => 404,
                'status' => 'WORKER_NOT_FOUND',
                'message' => 'Trabajador no enrolado o no encontrado para esta estación/dispositivo.'
            );
        }

        mysqli_begin_transaction($conexion);

        try {
            // Keep the shared device-first lock order; expire only this worker/device's rows.
            $idDispositivo = (int)$idDispositivo;
            $idEstacionResuelta = (int)$idEstacionResuelta;
            $idPersonalResuelto = (int)$idPersonalResuelto;
            $refExternaPersonal = (string)$refExternaPersonal;
            $stmtDevice = $conexion->prepare('SELECT id, id_estacion, estado FROM op_rh_v2_dispositivos WHERE id = ? FOR UPDATE');
            $stmtDevice->bind_param('i', $idDispositivo);
            $stmtDevice->execute();
            $device = $stmtDevice->get_result()->fetch_assoc();
            $stmtDevice->close();
            if (!$device || (int)$device['estado'] !== 1 || (int)$device['id_estacion'] !== $idEstacionResuelta) {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 401, 'status' => 'UNAUTHORIZED', 'message' => 'Dispositivo o estación no autorizados.');
            }
            if ($idCredencial !== null && !$this->credentialIsActiveForDevice($conexion, (int)$idCredencial, $idDispositivo)) {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 401, 'status' => 'UNAUTHORIZED', 'message' => 'La credencial del dispositivo ya no está vigente.');
            }
            $scopeRequest = array(
                'id_dispositivo' => $idDispositivo,
                'id_estacion' => $idEstacionResuelta,
                'id_personal_resuelto' => $idPersonalResuelto,
                'referencia_externa_personal' => $refExternaPersonal
            );
            if (!TemporaryAccessRequestScopePrepared::verify($conexion, $scopeRequest, array($idEstacionResuelta))) {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 403, 'status' => 'STATION_FORBIDDEN', 'message' => 'Trabajador o dispositivo fuera del alcance vigente.');
            }
            $this->expireRequestsForIdentity($conexion, $idDispositivo, $idPersonalResuelto);

            // 1. Idempotencia directa por request_uuid del Lector
            $stmtCheckUuid = $conexion->prepare(
                "SELECT request_uuid, status FROM op_rh_v2_solicitudes_acceso_temporal WHERE request_uuid = ? LIMIT 1"
            );
            $stmtCheckUuid->bind_param("s", $requestUuid);
            $stmtCheckUuid->execute();
            $resCheck = $stmtCheckUuid->get_result();
            if ($existingRow = $resCheck->fetch_assoc()) {
                $stmtCheckUuid->close();
                mysqli_commit($conexion);
                return array(
                    'success' => true,
                    'code' => 201,
                    'data' => array(
                        'code' => 201,
                        'status' => 'REQUEST_ACCEPTED',
                        'request_uuid' => $existingRow['request_uuid'],
                        'request_status' => $existingRow['status'],
                        'mensaje' => 'Solicitud de acceso temporal previa encontrada (Idempotencia request_uuid).'
                    )
                );
            }
            $stmtCheckUuid->close();

            // 2. Serialización estricta por bloqueo FOR UPDATE sobre la fila parent del Dispositivo
            // 3. Bloquear y revocar/cancelar solicitudes activas previas (PENDING o AUTHORIZED)
            $stmtLockActive = $conexion->prepare(
                "SELECT id FROM op_rh_v2_solicitudes_acceso_temporal 
                 WHERE id_dispositivo = ? AND id_personal_resuelto = ? AND status IN ('PENDING', 'AUTHORIZED') 
                 FOR UPDATE"
            );
            $stmtLockActive->bind_param("ii", $idDispositivo, $idPersonalResuelto);
            $stmtLockActive->execute();
            $resActive = $stmtLockActive->get_result();

            $nowUtcStr = gmdate('Y-m-d H:i:s');

            while ($rowAct = $resActive->fetch_assoc()) {
                $stmtCancel = $conexion->prepare(
                    "UPDATE op_rh_v2_solicitudes_acceso_temporal 
                     SET status = 'CANCELLED', cancelled_at_utc = ?, cancelled_by_user_id = NULL 
                     WHERE id = ?"
                );
                $stmtCancel->bind_param("si", $nowUtcStr, $rowAct['id']);
                $stmtCancel->execute();
                $stmtCancel->close();
            }
            $stmtLockActive->close();

            // 4. Insertar nueva solicitud PENDING
            $nowTime = time();
            $expiresTime = $nowTime + (self::REQUEST_TTL_MINUTES * 60);

            $requestedAtUtc = gmdate('Y-m-d H:i:s', $nowTime);
            $requestExpiresAtUtc = gmdate('Y-m-d H:i:s', $expiresTime);
            $officialReason = !empty($reason) ? trim($reason) : 'FINGERPRINT_NOT_RECOGNIZED';

            $stmtIns = $conexion->prepare(
                "INSERT INTO op_rh_v2_solicitudes_acceso_temporal 
                 (request_uuid, id_dispositivo, id_estacion, referencia_externa_personal, id_personal_resuelto, reason, status, requested_at_utc, request_expires_at_utc) 
                 VALUES (?, ?, ?, ?, ?, ?, 'PENDING', ?, ?)"
            );
            $stmtIns->bind_param("siisisss", $requestUuid, $idDispositivo, $idEstacionResuelta, $refExternaPersonal, $idPersonalResuelto, $officialReason, $requestedAtUtc, $requestExpiresAtUtc);
            $stmtIns->execute();
            $stmtIns->close();

            mysqli_commit($conexion);

            return array(
                'success' => true,
                'code' => 201,
                'data' => array(
                    'code' => 201,
                    'status' => 'REQUEST_ACCEPTED',
                    'request_uuid' => $requestUuid,
                    'request_status' => 'PENDING',
                    'mensaje' => 'Solicitud de acceso temporal registrada exitosamente.'
                )
            );
        } catch (Exception $e) {
            mysqli_rollback($conexion);
            return array(
                'success' => false,
                'code' => 500,
                'status' => 'INTERNAL_ERROR',
                'message' => 'Error transaccional al registrar solicitud.'
            );
        }
    }

    /**
     * GET /api/v2/temporary-access/requests (Portal3 Supervisor Auth)
     */
    public function getPendingRequestsForPortal3($conexion, array $authorizedStationIds, $filterStationId = null) {
        $this->lazyExpireRequests($conexion);

        if (empty($authorizedStationIds)) {
            return array('success' => true, 'code' => 200, 'data' => array());
        }

        $targetStations = $authorizedStationIds;
        if ($filterStationId !== null && intval($filterStationId) > 0) {
            $fId = intval($filterStationId);
            if (!in_array($fId, $authorizedStationIds, true)) {
                return array(
                    'success' => false,
                    'code' => 403,
                    'status' => 'STATION_FORBIDDEN',
                    'message' => 'No tiene permisos para consultar solicitudes de la estación solicitada.'
                );
            }
            $targetStations = array($fId);
        }

        $inClause = implode(',', array_map('intval', $targetStations));
        $query = "SELECT r.request_uuid, r.id_personal_resuelto, p.nombre_completo AS nombre_trabajador, 
                         r.id_estacion, d.nombre AS dispositivo_nombre, r.reason, r.requested_at_utc, r.request_expires_at_utc
                  FROM op_rh_v2_solicitudes_acceso_temporal r
                  INNER JOIN op_rh_v2_dispositivos d ON r.id_dispositivo = d.id
                  LEFT JOIN op_rh_personal p ON r.id_personal_resuelto = p.id
                  WHERE r.status = 'PENDING' AND r.id_estacion IN ($inClause)
                  ORDER BY r.requested_at_utc ASC";

        $res = mysqli_query($conexion, $query);
        $requests = array();
        while ($row = mysqli_fetch_assoc($res)) {
            $requests[] = array(
                'request_uuid' => $row['request_uuid'],
                'id_personal_resuelto' => intval($row['id_personal_resuelto']),
                'nombre_trabajador' => $row['nombre_trabajador'] ?: 'Trabajador ID ' . $row['id_personal_resuelto'],
                'id_estacion' => intval($row['id_estacion']),
                'dispositivo_nombre' => $row['dispositivo_nombre'],
                'reason' => $row['reason'],
                'requested_at_utc' => $row['requested_at_utc'],
                'request_expires_at_utc' => $row['request_expires_at_utc']
            );
        }

        return array('success' => true, 'code' => 200, 'data' => $requests);
    }

    /**
     * POST /api/v2/temporary-access/requests/{request_uuid}/authorize (Portal3 Supervisor Auth)
     */
    public function authorizeRequest($conexion, array $authorizedStationIds, $requestUuid, $userId) {
        mysqli_begin_transaction($conexion);

        try {
            $this->lazyExpireRequests($conexion);

            $stmtLock = $conexion->prepare(
                "SELECT * FROM op_rh_v2_solicitudes_acceso_temporal WHERE request_uuid = ? FOR UPDATE"
            );
            $stmtLock->bind_param("s", $requestUuid);
            $stmtLock->execute();
            $row = $stmtLock->get_result()->fetch_assoc();
            $stmtLock->close();

            if (!$row) {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 404, 'status' => 'REQUEST_NOT_FOUND', 'message' => 'Solicitud no encontrada.');
            }

            if (!in_array(intval($row['id_estacion']), $authorizedStationIds, true)) {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 403, 'status' => 'STATION_FORBIDDEN', 'message' => 'No tiene autorización en la estación de la solicitud.');
            }

            if ($row['status'] === 'EXPIRED') {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 409, 'status' => 'REQUEST_EXPIRED', 'message' => 'La solicitud ha expirado.');
            }

            if ($row['status'] !== 'PENDING') {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 409, 'status' => 'INVALID_STATE', 'message' => 'La solicitud no está en estado PENDING (estado actual: ' . $row['status'] . ').');
            }

            $nowTime = time();
            $nowUtcStr = gmdate('Y-m-d H:i:s', $nowTime);
            $pinExpiresUtcStr = gmdate('Y-m-d H:i:s', $nowTime + (self::PIN_TTL_MINUTES * 60));

            $plainPin = str_pad(strval(random_int(0, 999999)), self::PIN_LENGTH, '0', STR_PAD_LEFT);
            $pinHash = password_hash($plainPin, PASSWORD_BCRYPT);

            $stmtUpd = $conexion->prepare(
                "UPDATE op_rh_v2_solicitudes_acceso_temporal 
                 SET status = 'AUTHORIZED', pin_hash = ?, pin_failed_attempts = 0, authorized_by_user_id = ?, authorized_at_utc = ?, pin_expires_at_utc = ? 
                 WHERE id = ?"
            );
            $stmtUpd->bind_param("sissi", $pinHash, $userId, $nowUtcStr, $pinExpiresUtcStr, $row['id']);
            $stmtUpd->execute();
            $stmtUpd->close();

            mysqli_commit($conexion);

            return array(
                'success' => true,
                'code' => 200,
                'data' => array(
                    'code' => 200,
                    'status' => 'AUTHORIZED',
                    'request_uuid' => $requestUuid,
                    'temporary_pin' => $plainPin,
                    'pin_expires_at_utc' => $pinExpiresUtcStr,
                    'mensaje' => 'Código temporal generado. Proporcione este código al trabajador (Se muestra una sola vez).'
                )
            );
        } catch (Exception $e) {
            mysqli_rollback($conexion);
            return array('success' => false, 'code' => 500, 'status' => 'INTERNAL_ERROR', 'message' => 'Error transaccional al autorizar la solicitud.');
        }
    }

    /**
     * POST /api/v2/temporary-access/requests/{request_uuid}/reject (Portal3 Supervisor Auth)
     */
    public function rejectRequest($conexion, array $authorizedStationIds, $requestUuid, $userId) {
        mysqli_begin_transaction($conexion);

        try {
            $this->lazyExpireRequests($conexion);

            $stmtLock = $conexion->prepare(
                "SELECT * FROM op_rh_v2_solicitudes_acceso_temporal WHERE request_uuid = ? FOR UPDATE"
            );
            $stmtLock->bind_param("s", $requestUuid);
            $stmtLock->execute();
            $row = $stmtLock->get_result()->fetch_assoc();
            $stmtLock->close();

            if (!$row) {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 404, 'status' => 'REQUEST_NOT_FOUND', 'message' => 'Solicitud no encontrada.');
            }

            if (!in_array(intval($row['id_estacion']), $authorizedStationIds, true)) {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 403, 'status' => 'STATION_FORBIDDEN', 'message' => 'No tiene autorización en la estación de la solicitud.');
            }

            if ($row['status'] === 'EXPIRED') {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 409, 'status' => 'REQUEST_EXPIRED', 'message' => 'La solicitud ha expirado.');
            }

            if ($row['status'] !== 'PENDING') {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 409, 'status' => 'INVALID_STATE', 'message' => 'La solicitud no está en estado PENDING.');
            }

            $nowUtcStr = gmdate('Y-m-d H:i:s');

            $stmtUpd = $conexion->prepare(
                "UPDATE op_rh_v2_solicitudes_acceso_temporal 
                 SET status = 'REJECTED', rejected_by_user_id = ?, rejected_at_utc = ? 
                 WHERE id = ?"
            );
            $stmtUpd->bind_param("isi", $userId, $nowUtcStr, $row['id']);
            $stmtUpd->execute();
            $stmtUpd->close();

            mysqli_commit($conexion);

            return array(
                'success' => true,
                'code' => 200,
                'data' => array(
                    'code' => 200,
                    'status' => 'REJECTED',
                    'request_uuid' => $requestUuid,
                    'mensaje' => 'Solicitud rechazada exitosamente.'
                )
            );
        } catch (Exception $e) {
            mysqli_rollback($conexion);
            return array('success' => false, 'code' => 500, 'status' => 'INTERNAL_ERROR', 'message' => 'Error transaccional al rechazar la solicitud.');
        }
    }

    /**
     * POST /api/v2/temporary-access/requests/{request_uuid}/cancel (Portal3 Supervisor Auth)
     */
    public function cancelRequest($conexion, array $authorizedStationIds, $requestUuid, $userId) {
        mysqli_begin_transaction($conexion);

        try {
            $this->lazyExpireRequests($conexion);

            $stmtLock = $conexion->prepare(
                "SELECT * FROM op_rh_v2_solicitudes_acceso_temporal WHERE request_uuid = ? FOR UPDATE"
            );
            $stmtLock->bind_param("s", $requestUuid);
            $stmtLock->execute();
            $row = $stmtLock->get_result()->fetch_assoc();
            $stmtLock->close();

            if (!$row) {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 404, 'status' => 'REQUEST_NOT_FOUND', 'message' => 'Solicitud no encontrada.');
            }

            if (!in_array(intval($row['id_estacion']), $authorizedStationIds, true)) {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 403, 'status' => 'STATION_FORBIDDEN', 'message' => 'No tiene autorización en la estación de la solicitud.');
            }

            if (in_array($row['status'], array('USED', 'REJECTED', 'CANCELLED', 'EXPIRED', 'LOCKED'), true)) {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 409, 'status' => 'INVALID_STATE', 'message' => 'La solicitud ya se encuentra en estado terminal (' . $row['status'] . ').');
            }

            $nowUtcStr = gmdate('Y-m-d H:i:s');

            $stmtUpd = $conexion->prepare(
                "UPDATE op_rh_v2_solicitudes_acceso_temporal 
                 SET status = 'CANCELLED', cancelled_by_user_id = ?, cancelled_at_utc = ? 
                 WHERE id = ?"
            );
            $stmtUpd->bind_param("isi", $userId, $nowUtcStr, $row['id']);
            $stmtUpd->execute();
            $stmtUpd->close();

            mysqli_commit($conexion);

            return array(
                'success' => true,
                'code' => 200,
                'data' => array(
                    'code' => 200,
                    'status' => 'CANCELLED',
                    'request_uuid' => $requestUuid,
                    'mensaje' => 'Solicitud cancelada exitosamente.'
                )
            );
        } catch (Exception $e) {
            mysqli_rollback($conexion);
            return array('success' => false, 'code' => 500, 'status' => 'INTERNAL_ERROR', 'message' => 'Error transaccional al cancelar la solicitud.');
        }
    }

    /**
     * POST /api/v2/temporary-access/validate (Lector Device Auth)
     */
    public function validatePin($conexion, $idDispositivo, $idEstacionResuelta, $refExternaPersonal, $temporaryPin, $validationUuid, $idCredencial = null) {
        if (!is_string($refExternaPersonal) || $refExternaPersonal === ''
            || !is_string($temporaryPin) || preg_match('/\A[0-9]{6}\z/D', $temporaryPin) !== 1
            || !is_string($validationUuid) || $validationUuid === '') {
            return array('success' => false, 'code' => 400, 'status' => 'INVALID_PAYLOAD', 'message' => 'Campos external_worker_reference, temporary_pin y validation_uuid son requeridos.');
        }

        // Resolver id_personal_resuelto
        $idPersonalResuelto = $this->resolverPersonalId($conexion, $idDispositivo, $refExternaPersonal);
        if (!$idPersonalResuelto) {
            return array('success' => false, 'code' => 404, 'status' => 'WORKER_NOT_FOUND', 'message' => 'Trabajador no enrolado o no encontrado.');
        }

        $idDispositivo = (int)$idDispositivo;
        $idEstacionResuelta = (int)$idEstacionResuelta;
        $idPersonalResuelto = (int)$idPersonalResuelto;
        $refExternaPersonal = (string)$refExternaPersonal;
        $temporaryPin = (string)$temporaryPin;
        $validationUuid = (string)$validationUuid;

        mysqli_begin_transaction($conexion);

        try {
            // Serialize with request creation and canonical identity writers: device -> worker -> identity -> request.
            $stmtDevice = $conexion->prepare('SELECT id, id_estacion, estado FROM op_rh_v2_dispositivos WHERE id = ? FOR UPDATE');
            $stmtDevice->bind_param('i', $idDispositivo);
            $stmtDevice->execute();
            $device = $stmtDevice->get_result()->fetch_assoc();
            $stmtDevice->close();
            if (!$device || (int)$device['estado'] !== 1 || (int)$device['id_estacion'] !== $idEstacionResuelta) {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 401, 'status' => 'UNAUTHORIZED', 'mensaje' => 'Dispositivo o estación no autorizados.');
            }

            // Device Auth ran before this transaction; recheck its exact credential under the device lock.
            if ($idCredencial !== null) {
                $idCredencial = (int)$idCredencial;
                if (!$this->credentialIsActiveForDevice($conexion, $idCredencial, $idDispositivo)) {
                    mysqli_rollback($conexion);
                    return array('success' => false, 'code' => 401, 'status' => 'UNAUTHORIZED', 'mensaje' => 'La credencial del dispositivo ya no está vigente.');
                }
            }

            // Recheck worker status, physical station, and the one current device identity in this transaction.
            $scopeRequest = array(
                'id_dispositivo' => $idDispositivo,
                'id_estacion' => $idEstacionResuelta,
                'id_personal_resuelto' => $idPersonalResuelto,
                'referencia_externa_personal' => $refExternaPersonal
            );
            if (!TemporaryAccessRequestScopePrepared::verify($conexion, $scopeRequest, array($idEstacionResuelta))) {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 403, 'status' => 'STATION_FORBIDDEN', 'mensaje' => 'Trabajador o dispositivo fuera del alcance vigente.');
            }

            // Lost ACK is idempotent only for the original device, station, worker, external identity, and PIN.
            $stmtAck = $conexion->prepare(
                "SELECT request_uuid, id_dispositivo, id_estacion, referencia_externa_personal,
                        id_personal_resuelto, pin_hash
                 FROM op_rh_v2_solicitudes_acceso_temporal
                 WHERE successful_validation_uuid = ? AND status = 'USED' LIMIT 1 FOR UPDATE"
            );
            $stmtAck->bind_param("s", $validationUuid);
            $stmtAck->execute();
            $resAck = $stmtAck->get_result();
            if ($rowAck = $resAck->fetch_assoc()) {
                $stmtAck->close();
                $sameContext = (int)$rowAck['id_dispositivo'] === $idDispositivo
                    && (int)$rowAck['id_estacion'] === $idEstacionResuelta
                    && (int)$rowAck['id_personal_resuelto'] === $idPersonalResuelto
                    && hash_equals((string)$rowAck['referencia_externa_personal'], $refExternaPersonal);
                if (!$sameContext || !password_verify($temporaryPin, (string)$rowAck['pin_hash'])) {
                    mysqli_rollback($conexion);
                    return array('success' => false, 'code' => 409, 'status' => 'ALREADY_USED', 'mensaje' => 'La validación ya fue consumida para otra solicitud o contexto.');
                }
                mysqli_commit($conexion);
                return array(
                    'success' => true,
                    'code' => 200,
                    'data' => array(
                        'code' => 200,
                        'status' => 'VALID',
                        'request_uuid' => $rowAck['request_uuid'],
                        'validation_uuid' => $validationUuid,
                        'id_personal_resuelto' => intval($rowAck['id_personal_resuelto']),
                        'mensaje' => 'Código temporal validado exitosamente (Reintento por Lost ACK). Registre el PunchEvent.'
                    )
                );
            }
            $stmtAck->close();

            // Select current authorization before consulting consumption history, so old USED rows
            // cannot shadow a later PIN for the same worker.
            $stmtCandidate = $conexion->prepare(
                "SELECT id, request_uuid, id_dispositivo, id_estacion, referencia_externa_personal,
                        id_personal_resuelto, pin_hash, pin_failed_attempts, pin_expires_at_utc,
                        (pin_expires_at_utc <= UTC_TIMESTAMP(6)) AS pin_expired
                 FROM op_rh_v2_solicitudes_acceso_temporal
                 WHERE id_dispositivo = ? AND id_estacion = ? AND id_personal_resuelto = ?
                   AND referencia_externa_personal = ? AND status = 'AUTHORIZED'
                 ORDER BY id DESC FOR UPDATE"
            );
            $stmtCandidate->bind_param("iiis", $idDispositivo, $idEstacionResuelta, $idPersonalResuelto, $refExternaPersonal);
            $stmtCandidate->execute();
            $candidateRows = $stmtCandidate->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtCandidate->close();

            $rowCandidate = null;
            foreach ($candidateRows as $candidate) {
                if ((int)$candidate['pin_expired'] === 1) {
                    $stmtExpire = $conexion->prepare("UPDATE op_rh_v2_solicitudes_acceso_temporal SET status = 'EXPIRED' WHERE id = ? AND status = 'AUTHORIZED'");
                    $stmtExpire->bind_param('i', $candidate['id']);
                    $stmtExpire->execute();
                    $stmtExpire->close();
                    continue;
                }
                if ($rowCandidate !== null) {
                    mysqli_rollback($conexion);
                    return array('success' => false, 'code' => 409, 'status' => 'ALREADY_USED', 'mensaje' => 'Existe más de una autorización vigente para el trabajador.');
                }
                $rowCandidate = $candidate;
            }

            if ($rowCandidate && password_verify($temporaryPin, (string)$rowCandidate['pin_hash'])) {
                // Conditional transition is the final single-use guard in addition to row/device locks.
                $stmtUse = $conexion->prepare(
                    "UPDATE op_rh_v2_solicitudes_acceso_temporal
                     SET status = 'USED', successful_validation_uuid = ?, used_at_utc = UTC_TIMESTAMP(6)
                     WHERE id = ? AND status = 'AUTHORIZED' AND successful_validation_uuid IS NULL
                       AND pin_expires_at_utc > UTC_TIMESTAMP(6)"
                );
                $stmtUse->bind_param('si', $validationUuid, $rowCandidate['id']);
                $stmtUse->execute();
                $consumed = $stmtUse->affected_rows === 1;
                $stmtUse->close();
                if (!$consumed) {
                    $stmtState = $conexion->prepare(
                        "SELECT status, (pin_expires_at_utc <= UTC_TIMESTAMP(6)) AS pin_expired
                         FROM op_rh_v2_solicitudes_acceso_temporal WHERE id = ? FOR UPDATE"
                    );
                    $stmtState->bind_param('i', $rowCandidate['id']);
                    $stmtState->execute();
                    $state = $stmtState->get_result()->fetch_assoc();
                    $stmtState->close();
                    if ($state && $state['status'] === 'AUTHORIZED' && (int)$state['pin_expired'] === 1) {
                        $stmtExpire = $conexion->prepare("UPDATE op_rh_v2_solicitudes_acceso_temporal SET status = 'EXPIRED' WHERE id = ? AND status = 'AUTHORIZED'");
                        $stmtExpire->bind_param('i', $rowCandidate['id']);
                        $stmtExpire->execute();
                        $stmtExpire->close();
                        mysqli_commit($conexion);
                        return array('success' => false, 'code' => 400, 'status' => 'EXPIRED', 'mensaje' => 'El código temporal ha expirado.');
                    }
                    mysqli_rollback($conexion);
                    return array('success' => false, 'code' => 409, 'status' => 'ALREADY_USED', 'mensaje' => 'El código temporal ya fue utilizado o expiró.');
                }

                mysqli_commit($conexion);
                return array(
                    'success' => true,
                    'code' => 200,
                    'data' => array(
                        'code' => 200,
                        'status' => 'VALID',
                        'request_uuid' => $rowCandidate['request_uuid'],
                        'validation_uuid' => $validationUuid,
                        'id_personal_resuelto' => (int)$rowCandidate['id_personal_resuelto'],
                        'mensaje' => 'Código temporal validado exitosamente. Registre el PunchEvent.'
                    )
                );
            }

            // A consumed PIN is recognized only by verifying its hash inside the same device/worker/station/reference scope.
            if ($this->matchesRecentUsedPin($conexion, $idDispositivo, $idEstacionResuelta, $idPersonalResuelto, $refExternaPersonal, $temporaryPin, $validationUuid)) {
                mysqli_commit($conexion);
                return array('success' => false, 'code' => 409, 'status' => 'ALREADY_USED', 'mensaje' => 'El código temporal ya fue utilizado.');
            }

            if (!$rowCandidate) {
                mysqli_commit($conexion);
                return array(
                    'success' => false,
                    'code' => 400,
                    'status' => 'EXPIRED',
                    'mensaje' => 'No existe una solicitud autorizada activa o el código temporal ha expirado.'
                );
            }

            // 4. Wrong PIN increments only the currently selected authorization, under its row lock.
            $failed = (int)$rowCandidate['pin_failed_attempts'] + 1;
            $remaining = max(0, self::PIN_MAX_ATTEMPTS - $failed);
            $nextStatus = $failed >= self::PIN_MAX_ATTEMPTS ? 'LOCKED' : 'AUTHORIZED';
            $stmtAtt = $conexion->prepare(
                "UPDATE op_rh_v2_solicitudes_acceso_temporal SET pin_failed_attempts = ?, status = ?
                 WHERE id = ? AND status = 'AUTHORIZED'"
            );
            $stmtAtt->bind_param('isi', $failed, $nextStatus, $rowCandidate['id']);
            $stmtAtt->execute();
            $updated = $stmtAtt->affected_rows === 1;
            $stmtAtt->close();
            if (!$updated) {
                mysqli_rollback($conexion);
                return array('success' => false, 'code' => 409, 'status' => 'ALREADY_USED', 'mensaje' => 'La autorización ya no está disponible.');
            }
            mysqli_commit($conexion);
            if ($failed >= self::PIN_MAX_ATTEMPTS) return array('success' => false, 'code' => 403, 'status' => 'LOCKED', 'mensaje' => 'Código bloqueado por superar el límite de intentos fallidos.');
            return array('success' => false, 'code' => 400, 'status' => 'INVALID_PIN', 'attempts_remaining' => $remaining, 'mensaje' => 'PIN temporal incorrecto.');
        } catch (Throwable $e) {
            mysqli_rollback($conexion);
            if ((int)$e->getCode() === 1062) {
                return array('success' => false, 'code' => 409, 'status' => 'ALREADY_USED', 'mensaje' => 'La validación ya fue consumida para otra solicitud o contexto.');
            }
            return array('success' => false, 'code' => 500, 'status' => 'INTERNAL_ERROR', 'message' => 'Error transaccional al validar PIN temporal.');
        }
    }

    private function matchesRecentUsedPin($conexion, int $idDispositivo, int $idEstacion, int $idPersonal, string $referenciaExterna, string $pin, string $validationUuid): bool {
        $stmt = $conexion->prepare(
            "SELECT pin_hash FROM op_rh_v2_solicitudes_acceso_temporal
             WHERE id_dispositivo = ? AND id_estacion = ? AND id_personal_resuelto = ?
               AND referencia_externa_personal = ? AND status = 'USED'
               AND successful_validation_uuid IS NOT NULL AND successful_validation_uuid <> ?
             ORDER BY used_at_utc DESC"
        );
        $stmt->bind_param('iiiss', $idDispositivo, $idEstacion, $idPersonal, $referenciaExterna, $validationUuid);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (password_verify($pin, (string)$row['pin_hash'])) {
                $stmt->close();
                return true;
            }
        }
        $stmt->close();
        return false;
    }

    private function credentialIsActiveForDevice($conexion, int $idCredencial, int $idDispositivo): bool {
        $stmt = $conexion->prepare(
            "SELECT id FROM op_rh_v2_dispositivo_credenciales
             WHERE id = ? AND id_dispositivo = ? AND estado = 'ACTIVE' AND revocado_at IS NULL
               AND valido_desde <= UTC_TIMESTAMP(6) AND (valido_hasta IS NULL OR valido_hasta > UTC_TIMESTAMP(6))
             FOR UPDATE"
        );
        $stmt->bind_param('ii', $idCredencial, $idDispositivo);
        $stmt->execute();
        $active = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $active;
    }

    private function expireRequestsForIdentity($conexion, int $idDispositivo, int $idPersonalResuelto): void {
        $stmt = $conexion->prepare(
            "UPDATE op_rh_v2_solicitudes_acceso_temporal SET status = 'EXPIRED'
             WHERE id_dispositivo = ? AND id_personal_resuelto = ?
               AND ((status = 'PENDING' AND request_expires_at_utc <= UTC_TIMESTAMP(6))
                 OR (status = 'AUTHORIZED' AND pin_expires_at_utc <= UTC_TIMESTAMP(6)))"
        );
        $stmt->bind_param('ii', $idDispositivo, $idPersonalResuelto);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Lazy Expiration (Evaluación Perezosa Transaccional)
     */
    public function lazyExpireRequests($conexion) {
        $nowUtcStr = gmdate('Y-m-d H:i:s');

        // Expirar PENDING vencidas
        $conexion->query(
            "UPDATE op_rh_v2_solicitudes_acceso_temporal 
             SET status = 'EXPIRED' 
             WHERE status = 'PENDING' AND request_expires_at_utc <= '$nowUtcStr'"
        );

        // Expirar AUTHORIZED con PIN vencido
        $conexion->query(
            "UPDATE op_rh_v2_solicitudes_acceso_temporal 
             SET status = 'EXPIRED' 
             WHERE status = 'AUTHORIZED' AND pin_expires_at_utc <= '$nowUtcStr'"
        );
    }

    private function resolverPersonalId($conexion, $idDispositivo, $refExternaPersonal) {
        // Identity validity is a UTC half-open interval [valido_desde, valido_hasta),
        // matching PunchEventV2 historical resolution.
        $nowStr = gmdate('Y-m-d H:i:s');

        $stmt = $conexion->prepare(
            "SELECT id_personal FROM op_rh_v2_dispositivo_identidad_personal 
             WHERE id_dispositivo = ? AND referencia_externa_personal = ? AND es_activo = 1 
               AND valido_desde <= ? AND (valido_hasta IS NULL OR valido_hasta > ?)
             ORDER BY valido_desde DESC LIMIT 1"
        );
        $stmt->bind_param("isss", $idDispositivo, $refExternaPersonal, $nowStr, $nowStr);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        return $row ? intval($row['id_personal']) : null;
    }
}
