<?php
include_once __DIR__ . "/../BD.php";
include_once __DIR__ . "/Portal3JwtAuthHelper.php";

class Level2ServerService {

    // 1. Registro y Consulta de Generaciones de Dispositivo
    public function registrarGeneracion($conexion, int $idDispositivo, string $deviceUuid, string $deviceGeneration): array {
        $idDispositivo = (int)$idDispositivo;
        $deviceUuid = trim($deviceUuid);
        $deviceGeneration = trim($deviceGeneration);

        if ($idDispositivo <= 0 || empty($deviceUuid) || empty($deviceGeneration)) {
            return ['success' => false, 'code' => 400, 'status' => 'BAD_REQUEST', 'error' => 'Identificadores de dispositivo o generación inválidos.'];
        }

        // Verificar que idDispositivo coincida con deviceUuid
        $stmtCheck = $conexion->prepare('SELECT id, uuid_dispositivo FROM op_rh_v2_dispositivos WHERE id = ? LIMIT 1');
        $stmtCheck->bind_param('i', $idDispositivo);
        $stmtCheck->execute();
        $disp = $stmtCheck->get_result()->fetch_assoc();
        $stmtCheck->close();

        if (!$disp || $disp['uuid_dispositivo'] !== $deviceUuid) {
            return ['success' => false, 'code' => 403, 'status' => 'DEVICE_MISMATCH', 'error' => 'El dispositivo autenticado no coincide con el UUID proporcionado.'];
        }

        // Buscar si ya existe la generación
        $stmtGen = $conexion->prepare('SELECT id, estado FROM op_rh_v2_dispositivo_generaciones WHERE device_uuid = ? AND device_generation = ? LIMIT 1');
        $stmtGen->bind_param('ss', $deviceUuid, $deviceGeneration);
        $stmtGen->execute();
        $existing = $stmtGen->get_result()->fetch_assoc();
        $stmtGen->close();

        if ($existing) {
            return ['success' => true, 'code' => 200, 'status' => 'ALREADY_REGISTERED', 'id' => (int)$existing['id'], 'estado' => $existing['estado']];
        }

        // Marcar generaciones anteriores como CLOSED para este dispositivo
        $stmtClose = $conexion->prepare('UPDATE op_rh_v2_dispositivo_generaciones SET estado = "CLOSED", cerrado_at_utc = UTC_TIMESTAMP() WHERE id_dispositivo = ? AND estado = "ACTIVE"');
        $stmtClose->bind_param('i', $idDispositivo);
        $stmtClose->execute();
        $stmtClose->close();

        // Insertar nueva generación activa
        $nowUtc = gmdate('Y-m-d H:i:s');
        $stmtIns = $conexion->prepare('INSERT INTO op_rh_v2_dispositivo_generaciones (id_dispositivo, device_uuid, device_generation, estado, registrado_at_utc) VALUES (?, ?, ?, "ACTIVE", ?)');
        $stmtIns->bind_param('isss', $idDispositivo, $deviceUuid, $deviceGeneration, $nowUtc);
        
        if ($stmtIns->execute()) {
            $genId = (int)$stmtIns->insert_id;
            $stmtIns->close();
            return ['success' => true, 'code' => 201, 'status' => 'REGISTERED', 'id' => $genId, 'estado' => 'ACTIVE'];
        } else {
            $stmtIns->close();
            return ['success' => false, 'code' => 500, 'status' => 'INTERNAL_ERROR', 'error' => 'Error al registrar generación de dispositivo.'];
        }
    }

    public function validarGeneracionDispositivo($conexion, int $idDispositivo, string $deviceGeneration): bool {
        $stmt = $conexion->prepare('SELECT id FROM op_rh_v2_dispositivo_generaciones WHERE id_dispositivo = ? AND device_generation = ? LIMIT 1');
        $stmt->bind_param('is', $idDispositivo, $deviceGeneration);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $res !== null;
    }

    // 2. Historial de Asignaciones Dispositivo <-> Estación
    public function registrarAsignacionEstacion($conexion, int $idDispositivo, int $idEstacion, string $validoDesdeUtc, ?string $validoHastaUtc = null): array {
        $idDispositivo = (int)$idDispositivo;
        $idEstacion = (int)$idEstacion;
        $nowUtc = gmdate('Y-m-d H:i:s');

        // Cerrar asignaciones vigentes anteriores si las hay
        $stmtClose = $conexion->prepare('UPDATE op_rh_v2_dispositivo_estacion_historial SET valido_hasta_utc = ? WHERE id_dispositivo = ? AND (valido_hasta_utc IS NULL OR valido_hasta_utc > ?)');
        $stmtClose->bind_param('sis', $validoDesdeUtc, $idDispositivo, $validoDesdeUtc);
        $stmtClose->execute();
        $stmtClose->close();

        // Insertar nueva asignación
        $stmtIns = $conexion->prepare('INSERT INTO op_rh_v2_dispositivo_estacion_historial (id_dispositivo, id_estacion, valido_desde_utc, valido_hasta_utc, creado_at_utc) VALUES (?, ?, ?, ?, ?)');
        $stmtIns->bind_param('iisss', $idDispositivo, $idEstacion, $validoDesdeUtc, $validoHastaUtc, $nowUtc);
        
        if ($stmtIns->execute()) {
            $id = (int)$stmtIns->insert_id;
            $stmtIns->close();
            return ['success' => true, 'id' => $id];
        } else {
            $stmtIns->close();
            return ['success' => false, 'error' => 'Error al registrar historial de asignación de estación.'];
        }
    }

    public function obtenerGeneracionesYDispositivosRelevantes($conexion, int $idEstacion, string $fromUtc, string $toUtc): array {
        // Consultar dispositivos asignados a la estación en el intervalo [fromUtc, toUtc]
        $stmt = $conexion->prepare('SELECT DISTINCT h.id_dispositivo, g.device_generation, g.estado FROM op_rh_v2_dispositivo_estacion_historial h INNER JOIN op_rh_v2_dispositivo_generaciones g ON h.id_dispositivo = g.id_dispositivo WHERE h.id_estacion = ? AND h.valido_desde_utc <= ? AND (h.valido_hasta_utc IS NULL OR h.valido_hasta_utc > ?)');
        $stmt->bind_param('iss', $idEstacion, $toUtc, $fromUtc);
        $stmt->execute();
        $res = $stmt->get_result();

        $relevantes = [];
        while ($row = $res->fetch_assoc()) {
            $relevantes[] = [
                'id_dispositivo' => (int)$row['id_dispositivo'],
                'device_generation' => $row['device_generation'],
                'estado' => $row['estado']
            ];
        }
        $stmt->close();
        return $relevantes;
    }

    // 3. Ingesta y Validación de Secuencia Nivel 2
    public function procesarSecuenciaNivel2($conexion, int $idRawPunch, int $idDispositivo, string $deviceGeneration, int $deviceSequence): array {
        // Verificar que la generación esté registrada para el dispositivo
        if (!$this->validarGeneracionDispositivo($conexion, $idDispositivo, $deviceGeneration)) {
            return ['success' => false, 'code' => 400, 'status' => 'INVALID_GENERATION', 'error' => 'La generación enviada no está registrada para este dispositivo.'];
        }

        // Verificar si el evento raw ya tiene metadatos Nivel 2 vinculados
        $stmtRawCheck = $conexion->prepare('SELECT id_dispositivo, device_generation, device_sequence, es_governado_nivel2 FROM op_rh_v2_marcaciones_nivel2_metadata WHERE id_raw_punch = ? LIMIT 1');
        $stmtRawCheck->bind_param('i', $idRawPunch);
        $stmtRawCheck->execute();
        $existingRaw = $stmtRawCheck->get_result()->fetch_assoc();
        $stmtRawCheck->close();

        if ($existingRaw) {
            if ((int)$existingRaw['id_dispositivo'] === $idDispositivo && $existingRaw['device_generation'] === $deviceGeneration && (int)$existingRaw['device_sequence'] === $deviceSequence && (int)$existingRaw['es_governado_nivel2'] === 1) {
                return ['success' => true, 'code' => 200, 'status' => 'DUPLICATE_SEQUENCE_SAME_EVENT'];
            } else {
                return ['success' => false, 'code' => 409, 'status' => 'SEQUENCE_MISMATCH', 'error' => 'El evento ya tiene metadatos Nivel 2 vinculados con diferente secuencia o generación.'];
            }
        }

        // Verificar si la secuencia ya fue usada para esta generación
        $stmtSeqCheck = $conexion->prepare('SELECT id_raw_punch, es_governado_nivel2 FROM op_rh_v2_marcaciones_nivel2_metadata WHERE id_dispositivo = ? AND device_generation = ? AND device_sequence = ? LIMIT 1');
        $stmtSeqCheck->bind_param('isi', $idDispositivo, $deviceGeneration, $deviceSequence);
        $stmtSeqCheck->execute();
        $existingSeq = $stmtSeqCheck->get_result()->fetch_assoc();
        $stmtSeqCheck->close();

        if ($existingSeq) {
            if ((int)$existingSeq['id_raw_punch'] === $idRawPunch && (int)$existingSeq['es_governado_nivel2'] === 1) {
                return ['success' => true, 'code' => 200, 'status' => 'DUPLICATE_SEQUENCE_SAME_EVENT'];
            } else {
                return ['success' => false, 'code' => 409, 'status' => 'SEQUENCE_COLLISION', 'error' => 'La secuencia ya fue asignada a otro evento diferente dentro de la misma generación.'];
            }
        }

        // Insertar metadatos Nivel 2 en la tabla sidecar
        $nowUtc = gmdate('Y-m-d H:i:s');
        $stmtIns = $conexion->prepare('INSERT INTO op_rh_v2_marcaciones_nivel2_metadata (id_raw_punch, id_dispositivo, device_generation, device_sequence, es_governado_nivel2, creado_at_utc) VALUES (?, ?, ?, ?, 1, ?)');
        $stmtIns->bind_param('iisis', $idRawPunch, $idDispositivo, $deviceGeneration, $deviceSequence, $nowUtc);
        
        $insertErrorCode = 0;
        try {
            $inserted = $stmtIns->execute();
            $insertErrorCode = (int)$stmtIns->errno;
        } catch (Throwable $exception) {
            $inserted = false;
            $insertErrorCode = (int)$stmtIns->errno;
            if ($insertErrorCode === 0) {
                $insertErrorCode = (int)$exception->getCode();
            }
        }

        if ($inserted) {
            $stmtIns->close();
            return ['success' => true, 'code' => 201, 'status' => 'LEVEL2_METADATA_BOUND'];
        }

        $stmtIns->close();
        if ($insertErrorCode === 1062) {
            $duplicateResult = $this->resolverDuplicadoSidecar($conexion, $idRawPunch, $idDispositivo, $deviceGeneration, $deviceSequence);
            if ($duplicateResult !== null) {
                return $duplicateResult;
            }
        }

        return ['success' => false, 'code' => 500, 'status' => 'INTERNAL_ERROR', 'error' => 'Error al registrar metadatos Nivel 2.'];
    }

    private function resolverDuplicadoSidecar($conexion, int $idRawPunch, int $idDispositivo, string $deviceGeneration, int $deviceSequence): ?array {
        $stmtRaw = $conexion->prepare('SELECT id_dispositivo, device_generation, device_sequence, es_governado_nivel2 FROM op_rh_v2_marcaciones_nivel2_metadata WHERE id_raw_punch = ? LIMIT 1');
        $stmtRaw->bind_param('i', $idRawPunch);
        $stmtRaw->execute();
        $existingRaw = $stmtRaw->get_result()->fetch_assoc();
        $stmtRaw->close();

        if ($existingRaw) {
            if ((int)$existingRaw['id_dispositivo'] === $idDispositivo && $existingRaw['device_generation'] === $deviceGeneration && (int)$existingRaw['device_sequence'] === $deviceSequence && (int)$existingRaw['es_governado_nivel2'] === 1) {
                return ['success' => true, 'code' => 200, 'status' => 'DUPLICATE_SEQUENCE_SAME_EVENT'];
            }
            return ['success' => false, 'code' => 409, 'status' => 'SEQUENCE_MISMATCH', 'error' => 'El evento ya tiene metadatos Nivel 2 vinculados con diferente secuencia o generación.'];
        }

        $stmtSequence = $conexion->prepare('SELECT id_raw_punch, es_governado_nivel2 FROM op_rh_v2_marcaciones_nivel2_metadata WHERE id_dispositivo = ? AND device_generation = ? AND device_sequence = ? LIMIT 1');
        $stmtSequence->bind_param('isi', $idDispositivo, $deviceGeneration, $deviceSequence);
        $stmtSequence->execute();
        $existingSequence = $stmtSequence->get_result()->fetch_assoc();
        $stmtSequence->close();

        if ($existingSequence) {
            if ((int)$existingSequence['id_raw_punch'] === $idRawPunch && (int)$existingSequence['es_governado_nivel2'] === 1) {
                return ['success' => true, 'code' => 200, 'status' => 'DUPLICATE_SEQUENCE_SAME_EVENT'];
            }
            return ['success' => false, 'code' => 409, 'status' => 'SEQUENCE_COLLISION', 'error' => 'La secuencia ya fue asignada a otro evento diferente dentro de la misma generación.'];
        }

        return null;
    }

    // 4. Cálculo de Accepted-Through y Detección de Gaps de Secuencia
    public function calcularAcceptedThrough($conexion, int $idDispositivo, string $deviceGeneration): int {
        $stmt = $conexion->prepare('SELECT device_sequence FROM op_rh_v2_marcaciones_nivel2_metadata WHERE id_dispositivo = ? AND device_generation = ? AND es_governado_nivel2 = 1 ORDER BY device_sequence ASC');
        $stmt->bind_param('is', $idDispositivo, $deviceGeneration);
        $stmt->execute();
        $res = $stmt->get_result();

        $expected = 1;
        $acceptedThrough = 0;

        while ($row = $res->fetch_assoc()) {
            $seq = (int)$row['device_sequence'];
            if ($seq === $expected) {
                $acceptedThrough = $seq;
                $expected++;
            } else if ($seq > $expected) {
                // Gap detectado, la contigüidad se detiene
                break;
            }
        }
        $stmt->close();
        return $acceptedThrough;
    }

    // 5. Ingesta y Consulta de Telemetría Operativa
    public function registrarTelemetria($conexion, int $idDispositivo, string $deviceGeneration, int $telemetrySequence, string $observationRaw, ?int $batteryLevel, ?string $networkStatus, ?string $clockStatus, array $payloadJson, ?string $serverReceivedAtUtc = null): array {
        if (!$this->validarGeneracionDispositivo($conexion, $idDispositivo, $deviceGeneration)) {
            return ['success' => false, 'code' => 400, 'status' => 'INVALID_GENERATION', 'error' => 'Generación no registrada para el dispositivo.'];
        }

        $serverReceivedAt = !empty($serverReceivedAtUtc) ? $serverReceivedAtUtc : gmdate('Y-m-d H:i:s');
        $observadoAtUtc = null;
        try {
            $dt = new DateTime($observationRaw, new DateTimeZone('UTC'));
            $observadoAtUtc = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $observadoAtUtc = null;
        }

        $payloadStr = json_encode($payloadJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $conexion->prepare('INSERT INTO op_rh_v2_dispositivo_telemetria (id_dispositivo, device_generation, telemetry_sequence, observation_timestamp_raw, observado_at_utc, server_received_at_utc, battery_level, network_status, clock_status, payload_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE server_received_at_utc=VALUES(server_received_at_utc)');
        $stmt->bind_param('isisssisss', $idDispositivo, $deviceGeneration, $telemetrySequence, $observationRaw, $observadoAtUtc, $serverReceivedAt, $batteryLevel, $networkStatus, $clockStatus, $payloadStr);
        
        if ($stmt->execute()) {
            $id = (int)$stmt->insert_id;
            $stmt->close();
            return ['success' => true, 'code' => 201, 'status' => 'TELEMETRY_RECORDED', 'id' => $id];
        } else {
            $stmt->close();
            return ['success' => false, 'code' => 500, 'status' => 'INTERNAL_ERROR', 'error' => 'Error al registrar telemetría.'];
        }
    }

    // 6. Evaluación de Ventana Nivel 2 y Generación de Manifiesto
    public function evaluarVentanaNivel2($conexion, int $idEstacion, string $fromUtc, string $toUtc, string $coveragePolicyRef = 'POLICY_V1.0_DEFAULT'): array {
        try {
            $dtFrom = new DateTime($fromUtc, new DateTimeZone('UTC'));
            $fromUtc = $dtFrom->format('Y-m-d H:i:s');
        } catch (Throwable $e) {}
        try {
            $dtTo = new DateTime($toUtc, new DateTimeZone('UTC'));
            $toUtc = $dtTo->format('Y-m-d H:i:s');
        } catch (Throwable $e) {}

        // A. Consultar Política de Cobertura
        $stmtPol = $conexion->prepare('SELECT max_heartbeat_gap_seconds, max_clock_drift_seconds, require_telemetry_continuity FROM op_rh_v2_coverage_policies WHERE coverage_policy_ref = ? AND es_activo = 1 LIMIT 1');
        $stmtPol->bind_param('s', $coveragePolicyRef);
        $stmtPol->execute();
        $policy = $stmtPol->get_result()->fetch_assoc();
        $stmtPol->close();

        if (!$policy) {
            return [
                'window_level2_status' => 'UNKNOWN',
                'delivery_completeness' => 'UNKNOWN',
                'operational_coverage' => 'UNKNOWN',
                'time_window_attribution' => 'UNKNOWN',
                'has_zero_governed_events' => false,
                'reason' => 'POLITICA_DE_COBERTURA_NO_ENCONTRADA'
            ];
        }

        // B. Obtener dispositivos y generaciones relevantes para la estación e intervalo
        $relevantes = $this->obtenerGeneracionesYDispositivosRelevantes($conexion, $idEstacion, $fromUtc, $toUtc);
        if (empty($relevantes)) {
            return [
                'window_level2_status' => 'UNKNOWN',
                'delivery_completeness' => 'UNKNOWN',
                'operational_coverage' => 'UNKNOWN',
                'time_window_attribution' => 'UNKNOWN',
                'has_zero_governed_events' => false,
                'reason' => 'DISPOSITIVOS_RELEVANTES_DESCONOCIDOS'
            ];
        }

        $deliveryCompleteness = 'VERIFIED';
        $operationalCoverage = 'VERIFIED';
        $timeWindowAttribution = 'VERIFIED';

        // C. Evaluar Delivery Completeness & Telemetry para cada dispositivo relevante
        foreach ($relevantes as $rel) {
            $dispId = $rel['id_dispositivo'];
            $gen = $rel['device_generation'];

            // Check Gaps / Accepted-Through en Secuencias de Marcación
            $stmtMaxSeq = $conexion->prepare('SELECT MAX(device_sequence) as max_seq, COUNT(*) as total_count FROM op_rh_v2_marcaciones_nivel2_metadata WHERE id_dispositivo = ? AND device_generation = ? AND es_governado_nivel2 = 1');
            $stmtMaxSeq->bind_param('is', $dispId, $gen);
            $stmtMaxSeq->execute();
            $seqRow = $stmtMaxSeq->get_result()->fetch_assoc();
            $stmtMaxSeq->close();

            $maxSeq = (int)($seqRow['max_seq'] ?? 0);
            $totalCount = (int)($seqRow['total_count'] ?? 0);
            $acceptedThrough = $this->calcularAcceptedThrough($conexion, $dispId, $gen);

            if ($maxSeq > 0 && ($acceptedThrough < $maxSeq || $totalCount !== $maxSeq)) {
                $deliveryCompleteness = 'INCOMPLETE';
            }

            // Check Telemetría Operativa en el intervalo
            $stmtTelem = $conexion->prepare('SELECT observado_at_utc, server_received_at_utc FROM op_rh_v2_dispositivo_telemetria WHERE id_dispositivo = ? AND device_generation = ? AND observado_at_utc >= ? AND observado_at_utc <= ? ORDER BY observado_at_utc ASC');
            $stmtTelem->bind_param('isss', $dispId, $gen, $fromUtc, $toUtc);
            $stmtTelem->execute();
            $resTelem = $stmtTelem->get_result();

            $telemetrias = [];
            while ($tRow = $resTelem->fetch_assoc()) {
                $telemetrias[] = $tRow;
            }
            $stmtTelem->close();

            if (empty($telemetrias)) {
                $operationalCoverage = 'UNKNOWN';
            } else {
                $maxGapAllowed = (int)$policy['max_heartbeat_gap_seconds'];
                $prevTime = strtotime($fromUtc);

                foreach ($telemetrias as $t) {
                    $currTime = strtotime($t['observado_at_utc']);
                    if (($currTime - $prevTime) > $maxGapAllowed) {
                        $operationalCoverage = 'INCOMPLETE';
                    }
                    $prevTime = $currTime;

                    // Calidad del tiempo de origen
                    $recTime = strtotime($t['server_received_at_utc']);
                    if (abs($recTime - $currTime) > (int)$policy['max_clock_drift_seconds']) {
                        $timeWindowAttribution = 'DEGRADED';
                    }
                }
                if ((strtotime($toUtc) - $prevTime) > $maxGapAllowed) {
                    $operationalCoverage = 'INCOMPLETE';
                }
            }
        }

        // D. Determinar Estado Consolidado Nivel 2
        $overallStatus = 'UNKNOWN';
        if ($deliveryCompleteness === 'VERIFIED' && $operationalCoverage === 'VERIFIED' && $timeWindowAttribution === 'VERIFIED') {
            $overallStatus = 'VERIFIED';
        } else if ($deliveryCompleteness === 'INCOMPLETE' || $operationalCoverage === 'INCOMPLETE') {
            $overallStatus = 'INCOMPLETE';
        }

        // E. Contar Marcaciones Gobernadas en la ventana
        $stmtCountEvents = $conexion->prepare('SELECT COUNT(*) as count FROM op_rh_v2_marcaciones_crudas m INNER JOIN op_rh_v2_marcaciones_nivel2_metadata l2 ON m.id = l2.id_raw_punch WHERE m.id_estacion_resuelta = ? AND m.ocurrido_at_utc >= ? AND m.ocurrido_at_utc <= ? AND l2.es_governado_nivel2 = 1');
        $stmtCountEvents->bind_param('iss', $idEstacion, $fromUtc, $toUtc);
        $stmtCountEvents->execute();
        $eventCount = (int)($stmtCountEvents->get_result()->fetch_assoc()['count'] ?? 0);
        $stmtCountEvents->close();

        $hasZeroGovernedEvents = ($eventCount === 0);

        // F. Generar Manifiesto de Evidencia Reproducible
        $evaluationRef = 'eval_ref_' . bin2hex(random_bytes(24));
        $evaluatedAtUtc = gmdate('Y-m-d H:i:s');

        $evidenceData = [
            'contract_version' => 'V1.1',
            'evaluation_ref' => $evaluationRef,
            'id_estacion' => $idEstacion,
            'window_from_utc' => $fromUtc,
            'window_to_utc' => $toUtc,
            'coverage_policy_ref' => $coveragePolicyRef,
            'relevant_device_generations' => $relevantes,
            'delivery_completeness' => $deliveryCompleteness,
            'operational_coverage' => $operationalCoverage,
            'time_window_attribution' => $timeWindowAttribution,
            'window_level2_status' => $overallStatus,
            'has_zero_governed_events' => $hasZeroGovernedEvents,
            'governed_event_count' => $eventCount,
            'evaluated_at_utc' => $evaluatedAtUtc
        ];

        $canonicalEvidenceJson = json_encode($evidenceData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $manifestDigest = hash('sha256', $canonicalEvidenceJson);

        $relevantesJson = json_encode($relevantes);

        $stmtInsEval = $conexion->prepare('INSERT INTO op_rh_v2_level2_window_evaluations (evaluation_ref, id_estacion, window_from_utc, window_to_utc, coverage_policy_ref, relevant_device_generations_json, delivery_completeness, operational_coverage, time_window_attribution, window_level2_status, has_zero_governed_events, evaluated_at_utc, manifest_digest, canonical_evidence_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmtInsEval->bind_param('sissssssssisss', $evaluationRef, $idEstacion, $fromUtc, $toUtc, $coveragePolicyRef, $relevantesJson, $deliveryCompleteness, $operationalCoverage, $timeWindowAttribution, $overallStatus, $hasZeroGovernedEvents, $evaluatedAtUtc, $manifestDigest, $canonicalEvidenceJson);
        $stmtInsEval->execute();
        $stmtInsEval->close();

        return [
            'evaluation_ref' => $evaluationRef,
            'window_level2_status' => $overallStatus,
            'delivery_completeness' => $deliveryCompleteness,
            'operational_coverage' => $operationalCoverage,
            'time_window_attribution' => $timeWindowAttribution,
            'has_zero_governed_events' => $hasZeroGovernedEvents,
            'governed_event_count' => $eventCount,
            'manifest_digest' => $manifestDigest,
            'evaluated_at_utc' => $evaluatedAtUtc
        ];
    }
}
