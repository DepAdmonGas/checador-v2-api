<?php
include_once __DIR__ . "/../BD.php";

class PunchEventV2 {

    public function registrarIdentidadPersonal($conexion, $idDispositivo, $referenciaExterna, $idPersonal, $validoDesde, $validoHasta = null) {
        mysqli_begin_transaction($conexion);
        try {
            $idDispositivo = (int)$idDispositivo;
            $referenciaEscaped = mysqli_real_escape_string($conexion, $referenciaExterna);
            $idPersonal = (int)$idPersonal;
            $validoDesdeEscaped = mysqli_real_escape_string($conexion, $validoDesde);
            $validoHastaSql = $validoHasta !== null ? "'" . mysqli_real_escape_string($conexion, $validoHasta) . "'" : "NULL";
            $validoHastaCmp = $validoHasta !== null ? "'" . mysqli_real_escape_string($conexion, $validoHasta) . "'" : "'9999-12-31 23:59:59'";

            $sqlOverlap = "SELECT id FROM op_rh_v2_dispositivo_identidad_personal
                           WHERE id_dispositivo = $idDispositivo
                             AND referencia_externa_personal = '$referenciaEscaped'
                             AND valido_desde < $validoHastaCmp
                             AND COALESCE(valido_hasta, '9999-12-31 23:59:59') > '$validoDesdeEscaped'
                           FOR UPDATE";

            $resOverlap = mysqli_query($conexion, $sqlOverlap);
            if ($resOverlap && mysqli_num_rows($resOverlap) > 0) {
                mysqli_rollback($conexion);
                return array('success' => false, 'error' => 'OVERLAPPING_PERIOD');
            }

            $sqlInsert = "INSERT INTO op_rh_v2_dispositivo_identidad_personal
                          (id_dispositivo, referencia_externa_personal, id_personal, valido_desde, valido_hasta, es_activo)
                          VALUES ($idDispositivo, '$referenciaEscaped', $idPersonal, '$validoDesdeEscaped', $validoHastaSql, 1)";

            if (!mysqli_query($conexion, $sqlInsert)) {
                mysqli_rollback($conexion);
                return array('success' => false, 'error' => 'INSERT_FAILED: ' . mysqli_error($conexion));
            }

            mysqli_commit($conexion);
            return array('success' => true, 'id' => mysqli_insert_id($conexion));
        } catch (Exception $e) {
            mysqli_rollback($conexion);
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    public function resolverTrabajadorHistorico($conexion, $idDispositivo, $referenciaExterna, $ocurridoAtUtc) {
        if (empty($ocurridoAtUtc)) {
            return null;
        }

        $idDispositivo = (int)$idDispositivo;
        $referenciaEscaped = mysqli_real_escape_string($conexion, $referenciaExterna);
        $ocurridoEscaped = mysqli_real_escape_string($conexion, $ocurridoAtUtc);

        $sql = "SELECT id_personal FROM op_rh_v2_dispositivo_identidad_personal
                WHERE id_dispositivo = $idDispositivo
                  AND referencia_externa_personal = '$referenciaEscaped'
                  AND valido_desde <= '$ocurridoEscaped'
                  AND (valido_hasta IS NULL OR valido_hasta > '$ocurridoEscaped')
                ORDER BY valido_desde DESC LIMIT 1";

        $result = mysqli_query($conexion, $sql);
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            return (int)$row['id_personal'];
        }

        return null;
    }

    public function registrarPunchEvent($conexion, $datos) {
        require_once __DIR__ . "/PunchEventIntegrityService.php";

        $idDispositivo = (int)$datos['id_dispositivo'];
        $eventUuid = mysqli_real_escape_string($conexion, $datos['event_uuid']);

        // 1. Verificación de idempotencia previa por id_dispositivo + event_uuid
        $sqlCheck = "SELECT * FROM op_rh_v2_marcaciones_crudas
                    WHERE id_dispositivo = $idDispositivo AND event_uuid = '$eventUuid' LIMIT 1";

        $resCheck = mysqli_query($conexion, $sqlCheck);
        if ($resCheck && mysqli_num_rows($resCheck) > 0) {
            $existing = mysqli_fetch_assoc($resCheck);
            return $this->evaluarReintentoIdempotente($datos, $existing);
        }

        // 2. Resolver flag de obligatoriedad y configuración de claves
        $integrityMandatoryRaw = getenv('RAW_PUNCH_INTEGRITY_MANDATORY');
        $integrityMandatory = false;
        if ($integrityMandatoryRaw !== false && strtolower(trim((string)$integrityMandatoryRaw)) === 'true') {
            $integrityMandatory = true;
        }

        $activeKeyId = getenv('RAW_PUNCH_ACTIVE_KEY_ID') ?: null;
        $keyRingJson = getenv('RAW_PUNCH_KEY_RING') ?: null;
        $keyRing = null;
        if ($keyRingJson) {
            $decoded = json_decode($keyRingJson, true);
            if (is_array($decoded)) {
                $keyRing = $decoded;
            }
        }

        // 3. Preparación de sello antes de Insert
        $integrityVersion = null;
        $integrityKeyId = null;
        $integritySeal = null;

        if ($integrityMandatory || !empty($activeKeyId)) {
            try {
                $sealPayload = $datos;
                if (!isset($sealPayload['metadata_json']) && isset($sealPayload['metadata'])) {
                    $sealPayload['metadata_json'] = $sealPayload['metadata'];
                }
                $sealInfo = PunchEventIntegrityService::sealWithConfig($sealPayload, $activeKeyId, $keyRing);
                $integrityVersion = $sealInfo['integrity_version'];
                $integrityKeyId = $sealInfo['integrity_key_id'];
                $integritySeal = $sealInfo['integrity_seal'];
            } catch (Exception $e) {
                // FAIL-CLOSED: Si mandatory es true o falla la configuración, no se realiza el insert
                if ($integrityMandatory) {
                    return array(
                        'status_code' => 500,
                        'result' => 'INTERNAL_ERROR',
                        'event_uuid' => $datos['event_uuid'],
                        'error' => 'Integrity seal generation failed: fail-closed: ' . $e->getMessage()
                    );
                }
            }
        }

        // 4. Preparación de valores SQL para INSERT
        $idEstacionResuelta = isset($datos['id_estacion_resuelta']) && $datos['id_estacion_resuelta'] !== null 
            ? (int)$datos['id_estacion_resuelta'] : "NULL";
        
        $refExternaEscaped = mysqli_real_escape_string($conexion, $datos['referencia_externa_personal']);

        $idPersonalResuelto = isset($datos['id_personal_resuelto']) && $datos['id_personal_resuelto'] !== null 
            ? (int)$datos['id_personal_resuelto'] : "NULL";

        $sourceRawEscaped = mysqli_real_escape_string($conexion, $datos['source_timestamp_raw']);

        $sourceReportedSql = isset($datos['source_timezone_reported']) && $datos['source_timezone_reported'] !== null
            ? "'" . mysqli_real_escape_string($conexion, $datos['source_timezone_reported']) . "'" : "NULL";

        $effectiveTimezoneSql = isset($datos['effective_timezone']) && $datos['effective_timezone'] !== null
            ? "'" . mysqli_real_escape_string($conexion, $datos['effective_timezone']) . "'" : "NULL";

        $fallbackApplied = !empty($datos['timezone_fallback_applied']) ? 1 : 0;

        $ocurridoUtcSql = isset($datos['ocurrido_at_utc']) && $datos['ocurrido_at_utc'] !== null
            ? "'" . mysqli_real_escape_string($conexion, $datos['ocurrido_at_utc']) . "'" : "NULL";

        $recibidoUtcEscaped = mysqli_real_escape_string($conexion, $datos['recibido_at_utc']);

        $metodoCapturaEscaped = mysqli_real_escape_string($conexion, $datos['metodo_captura'] ?? 'BIOMETRIC');

        $extEventIdSql = isset($datos['external_event_id']) && $datos['external_event_id'] !== null
            ? "'" . mysqli_real_escape_string($conexion, $datos['external_event_id']) . "'" : "NULL";

        $secuenciaSql = isset($datos['secuencia_dispositivo']) && $datos['secuencia_dispositivo'] !== null
            ? (int)$datos['secuencia_dispositivo'] : "NULL";

        $processingStatus = mysqli_real_escape_string($conexion, $datos['processing_status']);

        $validationFlagsJson = isset($datos['validation_flags']) ? mysqli_real_escape_string($conexion, json_encode($datos['validation_flags'])) : "[]";

        $metadataArray = isset($datos['metadata']) && is_array($datos['metadata']) ? $datos['metadata'] : array();
        $metadataJson = mysqli_real_escape_string($conexion, json_encode($metadataArray));

        $integrityVersionSql = $integrityVersion !== null ? "'" . mysqli_real_escape_string($conexion, $integrityVersion) . "'" : "NULL";
        $integrityKeyIdSql = $integrityKeyId !== null ? "'" . mysqli_real_escape_string($conexion, $integrityKeyId) . "'" : "NULL";
        $integritySealSql = $integritySeal !== null ? "'" . mysqli_real_escape_string($conexion, $integritySeal) . "'" : "NULL";

        // 5. Mismo INSERT único con columnas de integridad directamente
        $sqlInsert = "INSERT INTO op_rh_v2_marcaciones_crudas
            (event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal, id_personal_resuelto,
             source_timestamp_raw, source_timezone_reported, effective_timezone, timezone_fallback_applied,
             ocurrido_at_utc, recibido_at_utc, metodo_captura, external_event_id, secuencia_dispositivo,
             processing_status, validation_flags, metadata_json,
             integrity_version, integrity_key_id, integrity_seal)
            VALUES
            ('$eventUuid', $idDispositivo, $idEstacionResuelta, '$refExternaEscaped', $idPersonalResuelto,
             '$sourceRawEscaped', $sourceReportedSql, $effectiveTimezoneSql, $fallbackApplied,
             $ocurridoUtcSql, '$recibidoUtcEscaped', '$metodoCapturaEscaped', $extEventIdSql, $secuenciaSql,
             '$processingStatus', '$validationFlagsJson', '$metadataJson',
             $integrityVersionSql, $integrityKeyIdSql, $integritySealSql)";

        if (mysqli_query($conexion, $sqlInsert)) {
            return array(
                'status_code' => 201,
                'result' => 'ACCEPTED',
                'event_uuid' => $datos['event_uuid'],
                'id_insertado' => mysqli_insert_id($conexion),
                'recibido_at_utc' => $datos['recibido_at_utc'],
                'processing_status' => $datos['processing_status']
            );
        } else {
            if (mysqli_errno($conexion) === 1062) {
                // Manejo seguro de carrera de duplicado
                $resCheckAfter = mysqli_query($conexion, $sqlCheck);
                if ($resCheckAfter && mysqli_num_rows($resCheckAfter) > 0) {
                    $existingAfter = mysqli_fetch_assoc($resCheckAfter);
                    return $this->evaluarReintentoIdempotente($datos, $existingAfter);
                }
                return array(
                    'status_code' => 200,
                    'result' => 'ALREADY_RECEIVED',
                    'event_uuid' => $datos['event_uuid'],
                    'processing_status' => $datos['processing_status']
                );
            }
            return array(
                'status_code' => 500,
                'result' => 'INTERNAL_ERROR',
                'error' => mysqli_error($conexion)
            );
        }
    }

    /**
     * Evalúa la idempotencia de una marcación existente comparando la equivalencia del payload del cliente.
     */
    private function evaluarReintentoIdempotente(array $incomingData, array $existingRow): array {
        $keyRingJson = getenv('RAW_PUNCH_KEY_RING') ?: null;
        $keyRing = null;
        if ($keyRingJson) {
            $decoded = json_decode($keyRingJson, true);
            if (is_array($decoded)) {
                $keyRing = $decoded;
            }
        }

        // Si la fila existente tiene sello de integridad, validar primero su estado de integridad
        if ($keyRing !== null) {
            $verifyStatus = PunchEventIntegrityService::verify($existingRow, $keyRing);
            if ($verifyStatus === PunchEventIntegrityService::RESULT_INVALID ||
                $verifyStatus === PunchEventIntegrityService::RESULT_UNVERIFIABLE) {
                // FAIL CLOSED: Si el registro existente está corrupto o es inverificable
                return array(
                    'status_code' => 500,
                    'result' => 'INTERNAL_ERROR',
                    'event_uuid' => $existingRow['event_uuid'],
                    'error' => 'Existing record integrity check failed.'
                );
            }
        }

        // Comparar campos de equivalencia del cliente:
        // event_uuid, id_dispositivo, referencia_externa_personal, source_timestamp_raw,
        // source_timezone_reported, metodo_captura, external_event_id, secuencia_dispositivo, metadata_json
        $sameUuid = ($incomingData['event_uuid'] === $existingRow['event_uuid']);
        $sameDevice = ((int)$incomingData['id_dispositivo'] === (int)$existingRow['id_dispositivo']);
        $sameRef = ((string)$incomingData['referencia_externa_personal'] === (string)$existingRow['referencia_externa_personal']);
        $sameTimestamp = ((string)$incomingData['source_timestamp_raw'] === (string)$existingRow['source_timestamp_raw']);
        $sameTimezone = ((string)($incomingData['source_timezone_reported'] ?? '') === (string)($existingRow['source_timezone_reported'] ?? ''));
        $sameMethod = ((string)($incomingData['metodo_captura'] ?? 'BIOMETRIC') === (string)($existingRow['metodo_captura'] ?? 'BIOMETRIC'));
        $sameExtId = ((string)($incomingData['external_event_id'] ?? '') === (string)($existingRow['external_event_id'] ?? ''));

        $incSeq = isset($incomingData['secuencia_dispositivo']) && $incomingData['secuencia_dispositivo'] !== null ? (int)$incomingData['secuencia_dispositivo'] : null;
        $existSeq = isset($existingRow['secuencia_dispositivo']) && $existingRow['secuencia_dispositivo'] !== null ? (int)$existingRow['secuencia_dispositivo'] : null;
        $sameSequence = ($incSeq === $existSeq);

        // Metadata semántica (usando canonicalización si ambas se pueden parsear)
        $incMeta = isset($incomingData['metadata']) && is_array($incomingData['metadata']) ? $incomingData['metadata'] : array();
        $existMetaRaw = $existingRow['metadata_json'] ?? '{}';
        $existMeta = json_decode($existMetaRaw, true);
        if (!is_array($existMeta)) {
            $existMeta = array();
        }

        $sameMetadata = false;
        try {
            $jsonInc = json_encode($incMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $jsonExist = json_encode($existMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // Decodificar y volver a serializar de forma canonicalizada usando PunchEventIntegrityService en dummy envelope
            $dummyInc = array_merge($incomingData, ['metadata_json' => $incMeta, 'validation_flags' => null]);
            $dummyExist = array_merge($incomingData, ['metadata_json' => $existMeta, 'validation_flags' => null]);

            $cInc = PunchEventIntegrityService::canonicalizeEnvelopeV1($dummyInc);
            $cExist = PunchEventIntegrityService::canonicalizeEnvelopeV1($dummyExist);

            $sameMetadata = ($cInc === $cExist);
        } catch (Exception $e) {
            $sameMetadata = ($incMeta == $existMeta);
        }

        $isPayloadEquivalent = $sameUuid && $sameDevice && $sameRef && $sameTimestamp &&
                                $sameTimezone && $sameMethod && $sameExtId && $sameSequence && $sameMetadata;

        if ($isPayloadEquivalent) {
            return array(
                'status_code' => 200,
                'result' => 'ALREADY_RECEIVED',
                'event_uuid' => $existingRow['event_uuid'],
                'recibido_at_utc' => $existingRow['recibido_at_utc'],
                'processing_status' => $existingRow['processing_status']
            );
        } else {
            return array(
                'status_code' => 409,
                'result' => 'IDEMPOTENCY_CONFLICT',
                'event_uuid' => $existingRow['event_uuid'],
                'error' => 'Same event_uuid submitted with divergent client payload.'
            );
        }
    }
}
