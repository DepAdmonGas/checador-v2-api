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
        $idDispositivo = (int)$datos['id_dispositivo'];
        $eventUuid = mysqli_real_escape_string($conexion, $datos['event_uuid']);

        $sqlCheck = "SELECT id, event_uuid, recibido_at_utc, processing_status 
                    FROM op_rh_v2_marcaciones_crudas
                    WHERE id_dispositivo = $idDispositivo AND event_uuid = '$eventUuid' LIMIT 1";

        $resCheck = mysqli_query($conexion, $sqlCheck);
        if ($resCheck && mysqli_num_rows($resCheck) > 0) {
            $existing = mysqli_fetch_assoc($resCheck);
            return array(
                'status_code' => 200,
                'result' => 'ALREADY_RECEIVED',
                'event_uuid' => $existing['event_uuid'],
                'recibido_at_utc' => $existing['recibido_at_utc'],
                'processing_status' => $existing['processing_status']
            );
        }

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
        $metadataJson = isset($datos['metadata']) ? mysqli_real_escape_string($conexion, json_encode($datos['metadata'])) : "{}";

        $sqlInsert = "INSERT INTO op_rh_v2_marcaciones_crudas
            (event_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal, id_personal_resuelto,
             source_timestamp_raw, source_timezone_reported, effective_timezone, timezone_fallback_applied,
             ocurrido_at_utc, recibido_at_utc, metodo_captura, external_event_id, secuencia_dispositivo,
             processing_status, validation_flags, metadata_json)
            VALUES
            ('$eventUuid', $idDispositivo, $idEstacionResuelta, '$refExternaEscaped', $idPersonalResuelto,
             '$sourceRawEscaped', $sourceReportedSql, $effectiveTimezoneSql, $fallbackApplied,
             $ocurridoUtcSql, '$recibidoUtcEscaped', '$metodoCapturaEscaped', $extEventIdSql, $secuenciaSql,
             '$processingStatus', '$validationFlagsJson', '$metadataJson')";

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
                return array(
                    'status_code' => 200,
                    'result' => 'ALREADY_RECEIVED',
                    'event_uuid' => $datos['event_uuid'],
                    'processing_status' => $datos['processing_status']
                );
            }
            return array(
                'status_code' => 500,
                'result' => 'ERROR_PERSISTENCIA',
                'error' => mysqli_error($conexion)
            );
        }
    }
}
