<?php
include_once __DIR__ . "/../BD.php";
include_once __DIR__ . "/Portal3JwtAuthHelper.php";
include_once __DIR__ . "/PunchEventIntegrityService.php";

class SnapshotReadV2Service {
    private const BIOMETRICOS_MODULE = 'biometricos';
    private const MAX_PAGE_SIZE = 200;
    private const DEFAULT_PAGE_SIZE = 50;
    private const MAX_DATE_RANGE_SECONDS = 2678400; // 31 días exactos
    private const DEFAULT_SNAPSHOT_TTL_SECONDS = 86400; // 24 horas

    public function handleCreateSnapshot() {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? null);

        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        // 1. Autenticación JWT de Portal3
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            http_response_code($auth['code']);
            echo json_encode(array("code" => $auth['code'], "status" => $auth['status'], "mensaje" => $auth['error']), JSON_UNESCAPED_UNICODE);
            return;
        }

        // 2. Autorizar módulo biométricos y resolver scope de estaciones
        $authz = $this->autorizarAccesoBiometricos($conexion, $auth, 'leer');
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            http_response_code($authz['code']);
            echo json_encode(array("code" => $authz['code'], "status" => $authz['status'], "mensaje" => $authz['error']), JSON_UNESCAPED_UNICODE);
            return;
        }

        $authorizedStationIds = $authz['authorized_stations'];
        $creatorUserId = (int)$auth['user_id'];

        // Leer payload JSON del body
        $rawInput = file_get_contents('php://input');
        $bodyData = json_decode($rawInput, true);
        if (!is_array($bodyData)) {
            $bodyData = [];
        }

        if (!$idempotencyKey && isset($bodyData['creation_request_uuid'])) {
            $idempotencyKey = trim((string)$bodyData['creation_request_uuid']);
        }
        if ($idempotencyKey) {
            $idempotencyKey = trim((string)$idempotencyKey);
            if (strlen($idempotencyKey) > 64) {
                $idempotencyKey = substr($idempotencyKey, 0, 64);
            }
        }

        // Idempotencia check
        if ($idempotencyKey) {
            $stmtIdem = $conexion->prepare('SELECT snapshot_token, snapshot_audit_ref, estado, total_records, creado_at_utc, expira_at_utc FROM op_rh_v2_read_snapshots WHERE creation_request_uuid = ? AND id_usuario_creador = ? LIMIT 1');
            $stmtIdem->bind_param('si', $idempotencyKey, $creatorUserId);
            $stmtIdem->execute();
            $existingSnap = $stmtIdem->get_result()->fetch_assoc();
            $stmtIdem->close();

            if ($existingSnap) {
                if ($existingSnap['estado'] === 'READY') {
                    $classBD->desconectarBD($conexion);
                    http_response_code(200);
                    echo json_encode(array(
                        "status" => "success",
                        "data" => array(
                            "snapshot" => array(
                                "snapshot_token" => $existingSnap['snapshot_token'],
                                "snapshot_audit_ref" => $existingSnap['snapshot_audit_ref'],
                                "status" => $existingSnap['estado'],
                                "created_at" => gmdate('Y-m-d\TH:i:s\Z', strtotime($existingSnap['creado_at_utc'])),
                                "expires_at" => gmdate('Y-m-d\TH:i:s\Z', strtotime($existingSnap['expira_at_utc'])),
                                "total_records" => (int)$existingSnap['total_records']
                            ),
                            "completeness" => array(
                                "level_1_api_snapshot" => true,
                                "level_2_ingestion_completeness" => false,
                                "status" => "LEVEL_1_COMPLETE_ONLY"
                            )
                        )
                    ), JSON_UNESCAPED_UNICODE);
                    return;
                } else if ($existingSnap['estado'] === 'CREATING') {
                    $classBD->desconectarBD($conexion);
                    http_response_code(400);
                    echo json_encode(array("code" => 400, "status" => "SNAPSHOT_NOT_READY", "mensaje" => "La creación del snapshot con esta clave de idempotencia aún está en proceso."), JSON_UNESCAPED_UNICODE);
                    return;
                }
            }
        }

        // 3. Validar Parámetros
        $stationIdParam = isset($bodyData['station_id']) && ctype_digit((string)$bodyData['station_id']) ? (int)$bodyData['station_id'] : (isset($_GET['station_id']) && ctype_digit((string)$_GET['station_id']) ? (int)$_GET['station_id'] : null);
        if ($stationIdParam !== null) {
            if (!in_array($stationIdParam, $authorizedStationIds, true)) {
                $classBD->desconectarBD($conexion);
                http_response_code(403);
                echo json_encode(array("code" => 403, "status" => "STATION_SCOPE_DENIED", "mensaje" => "Acceso denegado: la estación solicitada no está autorizada."), JSON_UNESCAPED_UNICODE);
                return;
            }
            $effectiveStations = [$stationIdParam];
        } else {
            $effectiveStations = $authorizedStationIds;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $defaultFrom = gmdate('Y-m-d H:i:s', strtotime('-30 days'));

        $fromParam = isset($bodyData['from']) ? trim((string)$bodyData['from']) : (isset($_GET['from']) ? trim((string)$_GET['from']) : $defaultFrom);
        $toParam = isset($bodyData['to']) ? trim((string)$bodyData['to']) : (isset($_GET['to']) ? trim((string)$_GET['to']) : $nowUtc);

        $fromUtc = $this->parseAndFormatIsoUtc($fromParam);
        $toUtc = $this->parseAndFormatIsoUtc($toParam);

        if ($fromUtc === null || $toUtc === null || strtotime($fromUtc) > strtotime($toUtc)) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "BAD_REQUEST", "mensaje" => "Rango de fechas inválido (ISO-8601 o Y-m-d H:i:s UTC requerido, from <= to)."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $durationSeconds = strtotime($toUtc) - strtotime($fromUtc);
        if ($durationSeconds > self::MAX_DATE_RANGE_SECONDS) {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "DATE_RANGE_TOO_LARGE", "mensaje" => "El rango de consulta excede la ventana máxima permitida de 31 días."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $workerRef = isset($bodyData['external_worker_reference']) ? trim((string)$bodyData['external_worker_reference']) : (isset($_GET['external_worker_reference']) ? trim((string)$_GET['external_worker_reference']) : null);
        $deviceId = isset($bodyData['device_id']) && ctype_digit((string)$bodyData['device_id']) ? (int)$bodyData['device_id'] : (isset($_GET['device_id']) && ctype_digit((string)$_GET['device_id']) ? (int)$_GET['device_id'] : null);
        $captureMethod = isset($bodyData['capture_method']) ? trim((string)$bodyData['capture_method']) : (isset($_GET['capture_method']) ? trim((string)$_GET['capture_method']) : null);

        $frozenFilters = [
            'from_utc' => $fromUtc,
            'to_utc' => $toUtc,
            'station_id' => $stationIdParam,
            'external_worker_reference' => $workerRef,
            'device_id' => $deviceId,
            'capture_method' => $captureMethod
        ];

        sort($authorizedStationIds, SORT_NUMERIC);
        $frozenScope = [
            'authorized_stations' => $authorizedStationIds,
            'effective_stations' => $effectiveStations
        ];

        $snapshotToken = 'snap_tok_' . bin2hex(random_bytes(24));
        $snapshotAuditRef = 'audit_ref_' . bin2hex(random_bytes(24));
        $createdTime = time();
        $createdAtUtc = gmdate('Y-m-d H:i:s', $createdTime);
        $expiresAtUtc = gmdate('Y-m-d H:i:s', $createdTime + self::DEFAULT_SNAPSHOT_TTL_SECONDS);

        // 4. Crear registro en estado CREATING
        $stmtIns = $conexion->prepare('INSERT INTO op_rh_v2_read_snapshots (snapshot_token, snapshot_audit_ref, id_usuario_creador, creation_request_uuid, estado, scope_estaciones_json, filtros_json, total_records, creado_at_utc, expira_at_utc) VALUES (?, ?, ?, ?, "CREATING", ?, ?, 0, ?, ?)');
        $scopeJson = json_encode($frozenScope);
        $filtrosJson = json_encode($frozenFilters);
        $stmtIns->bind_param('ssisssss', $snapshotToken, $snapshotAuditRef, $creatorUserId, $idempotencyKey, $scopeJson, $filtrosJson, $createdAtUtc, $expiresAtUtc);
        
        if (!$stmtIns->execute()) {
            $classBD->desconectarBD($conexion);
            http_response_code(500);
            echo json_encode(array("code" => 500, "status" => "INTERNAL_ERROR", "mensaje" => "Error al registrar metadatos de snapshot."), JSON_UNESCAPED_UNICODE);
            return;
        }
        $snapshotId = (int)$stmtIns->insert_id;
        $stmtIns->close();

        // 5. MVCC Read Isolation & ID Collection
        try {
            $conexion->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);

            $whereClause = "WHERE id_estacion_resuelta IN (" . implode(',', array_fill(0, count($effectiveStations), '?')) . ")";
            $bindTypes = str_repeat('i', count($effectiveStations));
            $bindParams = $effectiveStations;

            $whereClause .= " AND (ocurrido_at_utc >= ? AND ocurrido_at_utc <= ? OR ocurrido_at_utc IS NULL)";
            $bindTypes .= "ss";
            $bindParams[] = $fromUtc;
            $bindParams[] = $toUtc;

            if ($workerRef !== null && $workerRef !== '') {
                $whereClause .= " AND referencia_externa_personal = ?";
                $bindTypes .= "s";
                $bindParams[] = $workerRef;
            }
            if ($deviceId !== null) {
                $whereClause .= " AND id_dispositivo = ?";
                $bindTypes .= "i";
                $bindParams[] = $deviceId;
            }
            if ($captureMethod !== null && $captureMethod !== '') {
                $whereClause .= " AND metodo_captura = ?";
                $bindTypes .= "s";
                $bindParams[] = $captureMethod;
            }

            $sqlSelect = "SELECT id, id_dispositivo, event_uuid, ocurrido_at_utc FROM op_rh_v2_marcaciones_crudas $whereClause ORDER BY ocurrido_at_utc ASC, id_dispositivo ASC, event_uuid ASC, id ASC";
            $stmtSelect = $conexion->prepare($sqlSelect);

            $bindArgs = [$bindTypes];
            foreach ($bindParams as &$pRef) {
                $bindArgs[] = &$pRef;
            }
            unset($pRef);

            call_user_func_array([$stmtSelect, 'bind_param'], $bindArgs);
            $stmtSelect->execute();
            $resSelect = $stmtSelect->get_result();

            $membersToInsert = [];
            $canonicalMemberTuples = [];
            $orderIndex = 1;

            while ($row = $resSelect->fetch_assoc()) {
                $rawId = (int)$row['id'];
                $devId = (int)$row['id_dispositivo'];
                $evUuid = $row['event_uuid'];
                $ocUtc = $row['ocurrido_at_utc'];

                $membersToInsert[] = [
                    'order' => $orderIndex,
                    'id_raw_punch' => $rawId,
                    'id_dispositivo' => $devId,
                    'event_uuid' => $evUuid,
                    'ocurrido_at_utc' => $ocUtc
                ];

                $canonicalMemberTuples[] = [
                    'id_dispositivo' => $devId,
                    'event_uuid' => $evUuid,
                    'ocurrido_at_utc' => $ocUtc
                ];

                $orderIndex++;
            }
            $stmtSelect->close();
            $conexion->commit(); // Finalizar transacción de lectura MVCC

            $totalCount = count($membersToInsert);

            // 6. Persistencia de Miembros y Manifest Durable
            $conexion->begin_transaction();

            if ($totalCount > 0) {
                $stmtInsMember = $conexion->prepare('INSERT INTO op_rh_v2_read_snapshot_members (id_snapshot, member_order, id_raw_punch, id_dispositivo, event_uuid, ocurrido_at_utc) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($membersToInsert as $m) {
                    $stmtInsMember->bind_param('iiiiss', $snapshotId, $m['order'], $m['id_raw_punch'], $m['id_dispositivo'], $m['event_uuid'], $m['ocurrido_at_utc']);
                    $stmtInsMember->execute();
                }
                $stmtInsMember->close();
            }

            // Materializar Durable Manifest Audit
            $scopeHash = hash('sha256', json_encode($frozenScope));
            $filtrosHash = hash('sha256', json_encode($frozenFilters));

            $canonicalManifestData = [
                'contract_version' => 'V2.4_LEVEL1',
                'snapshot_audit_ref' => $snapshotAuditRef,
                'created_at_utc' => $createdAtUtc,
                'creator_user_id' => $creatorUserId,
                'frozen_scope' => $frozenScope,
                'frozen_filters' => $frozenFilters,
                'member_count' => $totalCount,
                'canonical_members' => $canonicalMemberTuples
            ];

            $canonicalJson = json_encode($canonicalManifestData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $manifestDigest = hash('sha256', $canonicalJson);

            $stmtManifest = $conexion->prepare('INSERT INTO op_rh_v2_durable_snapshot_manifests (snapshot_audit_ref, contract_version, creado_at_utc, id_usuario_creador, scope_estaciones_hash, filtros_hash, member_count, manifest_digest, canonical_manifest_json) VALUES (?, "V2.4_LEVEL1", ?, ?, ?, ?, ?, ?, ?)');
            $stmtManifest->bind_param('ssississ', $snapshotAuditRef, $createdAtUtc, $creatorUserId, $scopeHash, $filtrosHash, $totalCount, $manifestDigest, $canonicalJson);
            $stmtManifest->execute();
            $stmtManifest->close();

            // Actualizar estado a READY
            $stmtReady = $conexion->prepare('UPDATE op_rh_v2_read_snapshots SET estado = "READY", total_records = ? WHERE id = ?');
            $stmtReady->bind_param('ii', $totalCount, $snapshotId);
            $stmtReady->execute();
            $stmtReady->close();

            $conexion->commit();
            $classBD->desconectarBD($conexion);

            // Responder 201 Created
            http_response_code(201);
            echo json_encode(array(
                "status" => "success",
                "data" => array(
                    "snapshot" => array(
                        "snapshot_token" => $snapshotToken,
                        "snapshot_audit_ref" => $snapshotAuditRef,
                        "status" => "READY",
                        "created_at" => gmdate('Y-m-d\TH:i:s\Z', strtotime($createdAtUtc)),
                        "expires_at" => gmdate('Y-m-d\TH:i:s\Z', strtotime($expiresAtUtc)),
                        "total_records" => $totalCount
                    ),
                    "completeness" => array(
                        "level_1_api_snapshot" => true,
                        "level_2_ingestion_completeness" => false,
                        "status" => "LEVEL_1_COMPLETE_ONLY"
                    )
                )
            ), JSON_UNESCAPED_UNICODE);
            return;

        } catch (Throwable $e) {
            $conexion->rollback();
            // Marcar FAILED y limpiar
            $stmtFail = $conexion->prepare('UPDATE op_rh_v2_read_snapshots SET estado = "FAILED" WHERE id = ?');
            $stmtFail->bind_param('i', $snapshotId);
            $stmtFail->execute();
            $stmtFail->close();

            $classBD->desconectarBD($conexion);
            http_response_code(500);
            echo json_encode(array("code" => 500, "status" => "INTERNAL_ERROR", "mensaje" => "Error al materializar membresía del snapshot: " . $e->getMessage()), JSON_UNESCAPED_UNICODE);
            return;
        }
    }

    public function handlePageSnapshot(string $snapshotToken) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $classBD = new BD();
        $conexion = $classBD->conectarBD();

        // 1. Autenticación JWT de Portal3
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            http_response_code($auth['code']);
            echo json_encode(array("code" => $auth['code'], "status" => $auth['status'], "mensaje" => $auth['error']), JSON_UNESCAPED_UNICODE);
            return;
        }

        $userId = (int)$auth['user_id'];

        // 2. Buscar Snapshot por Token
        $stmtSnap = $conexion->prepare('SELECT id, id_usuario_creador, estado, scope_estaciones_json, filtros_json, total_records, expira_at_utc FROM op_rh_v2_read_snapshots WHERE snapshot_token = ? LIMIT 1');
        $stmtSnap->bind_param('s', $snapshotToken);
        $stmtSnap->execute();
        $snap = $stmtSnap->get_result()->fetch_assoc();
        $stmtSnap->close();

        if (!$snap) {
            $classBD->desconectarBD($conexion);
            http_response_code(404);
            echo json_encode(array("code" => 404, "status" => "SNAPSHOT_EXPIRED", "mensaje" => "El snapshot_token no existe o ha sido purgado."), JSON_UNESCAPED_UNICODE);
            return;
        }

        // Verificar vinculación de creador
        if ((int)$snap['id_usuario_creador'] !== $userId) {
            $classBD->desconectarBD($conexion);
            http_response_code(403);
            echo json_encode(array("code" => 403, "status" => "SNAPSHOT_ACCESS_DENIED", "mensaje" => "Acceso denegado: el snapshot únicamente puede ser consumido por su usuario creador."), JSON_UNESCAPED_UNICODE);
            return;
        }

        // Verificar estado y expiración
        $nowUtc = gmdate('Y-m-d H:i:s');
        if ($snap['expira_at_utc'] < $nowUtc) {
            $classBD->desconectarBD($conexion);
            http_response_code(404);
            echo json_encode(array("code" => 404, "status" => "SNAPSHOT_EXPIRED", "mensaje" => "El snapshot ha superado su tiempo de vida (Access TTL)."), JSON_UNESCAPED_UNICODE);
            return;
        }

        if ($snap['estado'] !== 'READY') {
            $classBD->desconectarBD($conexion);
            http_response_code(400);
            echo json_encode(array("code" => 400, "status" => "SNAPSHOT_NOT_READY", "mensaje" => "El snapshot no está disponible para lectura (estado: " . $snap['estado'] . ")."), JSON_UNESCAPED_UNICODE);
            return;
        }

        // 3. Re-evaluar ACL actual del usuario (Fail Closed ante reducción)
        $authz = $this->autorizarAccesoBiometricos($conexion, $auth, 'leer');
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            http_response_code(403);
            echo json_encode(array("code" => 403, "status" => "SNAPSHOT_SCOPE_CHANGED", "mensaje" => "Acceso denegado: permisos insuficientes para el módulo."), JSON_UNESCAPED_UNICODE);
            return;
        }

        $currentAuthorizedStations = $authz['authorized_stations'];
        $frozenScope = json_decode($snap['scope_estaciones_json'], true);
        $originalAuthorizedStations = $frozenScope['authorized_stations'] ?? [];

        // Validar que currentAuthorizedStations contenga la totalidad de originalAuthorizedStations
        foreach ($originalAuthorizedStations as $origStId) {
            if (!in_array((int)$origStId, $currentAuthorizedStations, true)) {
                $classBD->desconectarBD($conexion);
                http_response_code(403);
                echo json_encode(array("code" => 403, "status" => "SNAPSHOT_SCOPE_CHANGED", "mensaje" => "Acceso denegado: el alcance de estaciones autorizadas de su perfil cambió y ya no cubre el alcance del snapshot."), JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        // 4. Decodificar cursor
        $cursorParam = $_GET['cursor'] ?? null;
        $limitParam = isset($_GET['limit']) && ctype_digit((string)$_GET['limit']) ? (int)$_GET['limit'] : self::DEFAULT_PAGE_SIZE;
        if ($limitParam < 1 || $limitParam > self::MAX_PAGE_SIZE) {
            $limitParam = self::DEFAULT_PAGE_SIZE;
        }

        $lastMemberOrder = 0;
        if ($cursorParam !== null && trim($cursorParam) !== '') {
            $decodedCursor = json_decode(base64_decode(trim($cursorParam)), true);
            if (!is_array($decodedCursor) || !isset($decodedCursor['snapshot_token']) || $decodedCursor['snapshot_token'] !== $snapshotToken || !isset($decodedCursor['member_order'])) {
                $classBD->desconectarBD($conexion);
                http_response_code(422);
                echo json_encode(array("code" => 422, "status" => "INVALID_CURSOR", "mensaje" => "El cursor proporcionado es inválido o no pertenece a este snapshot."), JSON_UNESCAPED_UNICODE);
                return;
            }
            $lastMemberOrder = (int)$decodedCursor['member_order'];
        }

        // 5. Consultar Página de Miembros mediante JOIN con Marcaciones Crudas
        $snapshotId = (int)$snap['id'];
        $sqlPage = "SELECT m.member_order, r.* FROM op_rh_v2_read_snapshot_members m INNER JOIN op_rh_v2_marcaciones_crudas r ON m.id_raw_punch = r.id WHERE m.id_snapshot = ? AND m.member_order > ? ORDER BY m.member_order ASC LIMIT ?";
        $stmtPage = $conexion->prepare($sqlPage);
        $fetchLimit = $limitParam + 1; // Traer +1 para detectar has_more
        $stmtPage->bind_param('iii', $snapshotId, $lastMemberOrder, $fetchLimit);
        $stmtPage->execute();
        $resPage = $stmtPage->get_result();

        // KeyRing para integridad de sellos
        $keyRingJson = getenv('RAW_PUNCH_KEY_RING') ?: null;
        $keyRing = [];
        if ($keyRingJson) {
            $decoded = json_decode($keyRingJson, true);
            if (is_array($decoded)) $keyRing = $decoded;
        }

        $items = [];
        $lastOrderInBatch = $lastMemberOrder;

        while ($row = $resPage->fetch_assoc()) {
            if (count($items) < $limitParam) {
                $integrityStatus = PunchEventIntegrityService::verify($row, $keyRing);
                $valFlags = json_decode($row['validation_flags'] ?? '[]', true);
                if (!is_array($valFlags)) $valFlags = [];
                $meta = json_decode($row['metadata_json'] ?? '{}', true);
                if (!is_array($meta)) $meta = [];

                $items[] = array(
                    "event_uuid" => $row['event_uuid'],
                    "id_dispositivo" => (int)$row['id_dispositivo'],
                    "id_estacion_resuelta" => $row['id_estacion_resuelta'] !== null ? (int)$row['id_estacion_resuelta'] : null,
                    "referencia_externa_personal" => $row['referencia_externa_personal'],
                    "id_personal_resuelto" => $row['id_personal_resuelto'] !== null ? (int)$row['id_personal_resuelto'] : null,
                    "source_timestamp_raw" => $row['source_timestamp_raw'],
                    "source_timezone_reported" => $row['source_timezone_reported'],
                    "effective_timezone" => $row['effective_timezone'],
                    "timezone_fallback_applied" => (int)$row['timezone_fallback_applied'],
                    "ocurrido_at_utc" => $row['ocurrido_at_utc'],
                    "recibido_at_utc" => $row['recibido_at_utc'],
                    "metodo_captura" => $row['metodo_captura'],
                    "external_event_id" => $row['external_event_id'],
                    "secuencia_dispositivo" => $row['secuencia_dispositivo'] !== null ? (int)$row['secuencia_dispositivo'] : null,
                    "processing_status" => $row['processing_status'],
                    "validation_flags" => $valFlags,
                    "metadata" => $meta,
                    "integrity" => array(
                        "version" => $row['integrity_version'] ?? 'V1',
                        "key_id" => $row['integrity_key_id'] ?? null,
                        "status" => $integrityStatus
                    )
                );
                $lastOrderInBatch = (int)$row['member_order'];
            }
        }
        $stmtPage->close();

        $hasMore = $resPage->num_rows > $limitParam;
        $nextCursor = null;
        if ($hasMore) {
            $nextCursor = base64_encode(json_encode([
                'snapshot_token' => $snapshotToken,
                'member_order' => $lastOrderInBatch
            ]));
        }

        $classBD->desconectarBD($conexion);

        http_response_code(200);
        echo json_encode(array(
            "status" => "success",
            "data" => array(
                "items" => $items,
                "pagination" => array(
                    "next_cursor" => $nextCursor,
                    "has_more" => $hasMore,
                    "page_size" => count($items)
                )
            )
        ), JSON_UNESCAPED_UNICODE);
    }

    private function autorizarAccesoBiometricos($conexion, array $auth, string $requiredPermission): array {
        return Portal3JwtAuthHelper::authorizeTemporaryAccess($conexion, $auth, $requiredPermission);
    }

    private function parseAndFormatIsoUtc(string $val): ?string {
        try {
            $dt = new DateTime($val, new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
}
