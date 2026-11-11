<?php
/**
 * Checador V2 — ShiftTemplateService
 * Implements shift template versioning and atomic 1..N segment management.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';

class ShiftTemplateService
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        if ($db !== null) {
            $this->db = $db;
        } else {
            $bdObj = new BD();
            $this->db = $bdObj->conectarBD();
        }
    }

    /** Create an immutable station copy of the exact visible GLOBAL version. */
    public function createStationCustomization(array $payload, int $actorUserId, int $stationId): array
    {
        $source = $payload['derived_from_global'] ?? null;
        $sourceKey = is_array($source) ? trim((string) ($source['clave'] ?? '')) : '';
        $sourceVersion = is_array($source) ? filter_var($source['version'] ?? null, FILTER_VALIDATE_INT) : false;
        $name = trim((string) ($payload['nombre'] ?? ''));
        $type = strtoupper(trim((string) ($payload['tipo_turno'] ?? 'REGULAR')));
        $segments = $payload['segmentos'] ?? null;
        if ($stationId < 1 || $actorUserId < 1 || preg_match('/\\A[A-Za-z0-9_-]{1,100}\\z/', $sourceKey) !== 1
            || $sourceVersion === false || $sourceVersion < 1 || $name === '' || mb_strlen($name) > 128
            || !in_array($type, ['REGULAR', 'NOCTURNO', 'MIXTO', 'ROTATIVO'], true)
            || !is_array($segments) || $segments === [] || count($segments) > 255) {
            throw new InvalidArgumentException('INVALID_TEMPLATE_SPECIFICATION: invalid station customization.');
        }
        $this->validateSegments($segments);

        $this->db->begin_transaction();
        try {
            $schema = $this->db->query("SHOW TABLES LIKE 'op_rh_v2_shift_template_station_lineage'");
            if (!$schema || $schema->num_rows !== 1) {
                throw new RuntimeException('STATION_CUSTOMIZATION_SCHEMA_UNAVAILABLE');
            }
            $sourceStmt = $this->db->prepare("SELECT id, clave, version, nombre, tipo_turno, scope, id_estacion, es_activo FROM op_rh_v2_turnos_plantillas WHERE clave = ? AND version = ? AND scope = 'GLOBAL' AND id_estacion IS NULL AND es_activo = 1 AND version = (SELECT MAX(source_latest.version) FROM op_rh_v2_turnos_plantillas source_latest WHERE source_latest.clave = ? AND source_latest.scope = 'GLOBAL') LIMIT 1 FOR UPDATE");
            $sourceVersion = (int) $sourceVersion;
            $sourceStmt->bind_param('sis', $sourceKey, $sourceVersion, $sourceKey);
            $sourceStmt->execute();
            $sourceRow = $sourceStmt->get_result()->fetch_assoc();
            $sourceStmt->close();
            if (!$sourceRow) throw new RuntimeException('STATION_CUSTOMIZATION_SOURCE_UNAVAILABLE');

            $existingStmt = $this->db->prepare("SELECT l.local_clave, l.source_global_version, l.local_template_id FROM op_rh_v2_shift_template_station_lineage l WHERE l.id_estacion = ? AND l.source_global_clave = ? LIMIT 1 FOR UPDATE");
            $existingStmt->bind_param('is', $stationId, $sourceKey);
            $existingStmt->execute();
            $existing = $existingStmt->get_result()->fetch_assoc();
            $existingStmt->close();
            if ($existing) {
                if ((int) $existing['source_global_version'] !== $sourceVersion) {
                    throw new RuntimeException('STATION_CUSTOMIZATION_SOURCE_CONFLICT');
                }
                $currentStmt = $this->db->prepare("SELECT id, clave, version, nombre, tipo_turno, scope, id_estacion, es_activo FROM op_rh_v2_turnos_plantillas WHERE clave = ? AND scope = 'STATION' AND id_estacion = ? ORDER BY version DESC LIMIT 1");
                $localKey = (string) $existing['local_clave'];
                $currentStmt->bind_param('si', $localKey, $stationId);
                $currentStmt->execute();
                $current = $currentStmt->get_result()->fetch_assoc();
                $currentStmt->close();
                if (!$current) throw new RuntimeException('STATION_CUSTOMIZATION_SOURCE_CONFLICT');
                $current['id'] = (int) $current['id']; $current['version'] = (int) $current['version'];
                $current['id_estacion'] = (int) $current['id_estacion']; $current['es_activo'] = (int) $current['es_activo'];
                $current['scope'] = 'STATION';
                $current['segmentos'] = $this->loadSegments((int) $current['id']);
                $current['derived_from_global'] = ['clave' => $sourceKey, 'version' => $sourceVersion];
                $this->db->commit();
                return ['created' => false, 'template' => $current];
            }

            $generatedKey = 'ST' . $stationId . '_C' . bin2hex(random_bytes(16));
            $scope = 'STATION'; $version = 1; $active = 1;
            $stmt = $this->db->prepare("INSERT INTO op_rh_v2_turnos_plantillas (clave, version, nombre, tipo_turno, scope, id_estacion, es_activo, creado_por_usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sisssiii', $generatedKey, $version, $name, $type, $scope, $stationId, $active, $actorUserId);
            $stmt->execute(); $localId = (int) $this->db->insert_id; $stmt->close();
            $inserted = $this->insertSegments($localId, $segments);

            $sourceId = (int) $sourceRow['id'];
            $lineage = $this->db->prepare("INSERT INTO op_rh_v2_shift_template_station_lineage (id_estacion, source_global_clave, source_global_version, source_global_template_id, local_clave, local_template_id, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $lineage->bind_param('isiisii', $stationId, $sourceKey, $sourceVersion, $sourceId, $generatedKey, $localId, $actorUserId);
            $lineage->execute(); $lineage->close();

            $audit = $this->db->prepare("INSERT INTO op_rh_v2_turnos_audit_log (entity_type, entity_id, operacion, id_usuario_actor, id_estacion, id_plantilla, version_plantilla, detalle_json, recorded_at_utc) VALUES ('TEMPLATE', ?, 'CUSTOMIZE_GLOBAL', ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
            if ($audit) {
                $auditJson = json_encode(['clave' => $generatedKey, 'nombre' => $name, 'derived_from_global' => ['clave' => $sourceKey, 'version' => $sourceVersion]]);
                $audit->bind_param('iiiiis', $localId, $actorUserId, $stationId, $localId, $version, $auditJson);
                $audit->execute(); $audit->close();
            }
            $this->db->commit();
            return ['created' => true, 'template' => ['id' => $localId, 'clave' => $generatedKey, 'version' => 1,
                'nombre' => $name, 'tipo_turno' => $type, 'scope' => 'STATION', 'id_estacion' => $stationId,
                'es_activo' => 1, 'segmentos' => $inserted, 'derived_from_global' => ['clave' => $sourceKey, 'version' => $sourceVersion]]];
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof mysqli_sql_exception && (int) $e->getCode() === 1146) {
                throw new RuntimeException('STATION_CUSTOMIZATION_SCHEMA_UNAVAILABLE');
            }
            throw $e;
        }
    }

    private function loadSegments(int $templateId): array
    {
        $stmt = $this->db->prepare("SELECT id, ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin FROM op_rh_v2_turnos_segmentos WHERE id_plantilla = ? ORDER BY ordinal ASC");
        $stmt->bind_param('i', $templateId); $stmt->execute(); $result = $stmt->get_result(); $segments = [];
        while ($segment = $result->fetch_assoc()) {
            foreach (['id', 'ordinal', 'dia_offset_inicio', 'dia_offset_fin'] as $field) $segment[$field] = (int) $segment[$field];
            $segments[] = $segment;
        }
        $stmt->close(); return $segments;
    }

    /**
     * Create version 1 template with atomic 1..N segment set.
     */
    public function createTemplate(array $payload, int $actorUserId = 0, ?int $callerStationId = null): array
    {
        $clave = trim($payload['clave'] ?? '');
        $nombre = trim($payload['nombre'] ?? '');
        $tipoTurno = trim($payload['tipo_turno'] ?? 'REGULAR');
        $segmentos = $payload['segmentos'] ?? [];

        if (empty($clave) || empty($nombre)) {
            throw new InvalidArgumentException("INVALID_TEMPLATE_SPECIFICATION: clave and nombre are required.");
        }
        if (!is_array($segmentos) || empty($segmentos)) {
            throw new InvalidArgumentException("INVALID_TEMPLATE_SPECIFICATION: At least one segment is required.");
        }

        $this->validateSegments($segmentos);

        $requestedScope = strtoupper(trim((string)($payload['scope'] ?? '')));
        if ($callerStationId !== null && $callerStationId > 0) {
            $scope = ($requestedScope === 'GLOBAL' ? 'GLOBAL' : 'STATION');
            $idEstacion = ($scope === 'STATION' ? $callerStationId : null);
        } else {
            $scope = ($requestedScope === 'STATION' && !empty($payload['id_estacion']) ? 'STATION' : 'GLOBAL');
            $idEstacion = ($scope === 'STATION' ? (int)$payload['id_estacion'] : null);
        }
        $scopeId = ($scope === 'STATION' ? $idEstacion : 0);

        $this->db->begin_transaction();
        try {
            // A station template cannot shadow an existing global template key
            if ($scope === 'STATION') {
                $stmtGlobal = $this->db->prepare("SELECT id FROM op_rh_v2_turnos_plantillas WHERE clave = ? AND scope = 'GLOBAL' LIMIT 1");
                $stmtGlobal->bind_param("s", $clave);
                $stmtGlobal->execute();
                if ($stmtGlobal->get_result()->num_rows > 0) {
                    $stmtGlobal->close();
                    throw new RuntimeException("CANNOT_SHADOW_GLOBAL_TEMPLATE: Station template clave cannot reuse or shadow global template {$clave}.");
                }
                $stmtGlobal->close();
            }

            // Check if clave version 1 already exists in the same scope
            $stmt = $this->db->prepare("SELECT id FROM op_rh_v2_turnos_plantillas WHERE clave = ? AND version = 1 AND scope_id = ? LIMIT 1 FOR UPDATE");
            $stmt->bind_param("si", $clave, $scopeId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $stmt->close();
                throw new RuntimeException("DUPLICATE_TEMPLATE_VERSION: Template {$clave} version 1 already exists in scope.");
            }
            $stmt->close();

            $version = 1;
            $esActivo = 1;
            $actorIdNullable = $actorUserId > 0 ? $actorUserId : null;
            $stmtIns = $this->db->prepare("INSERT INTO op_rh_v2_turnos_plantillas (clave, version, nombre, tipo_turno, scope, id_estacion, es_activo, creado_por_usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->bind_param("sisssiii", $clave, $version, $nombre, $tipoTurno, $scope, $idEstacion, $esActivo, $actorIdNullable);
            if (!$stmtIns->execute()) {
                throw new RuntimeException("DB_ERROR: Could not insert template.");
            }
            $templateId = (int)$this->db->insert_id;
            $stmtIns->close();

            $insertedSegments = $this->insertSegments($templateId, $segmentos);

            // Audit log
            $stmtAudit = $this->db->prepare("INSERT INTO op_rh_v2_turnos_audit_log (entity_type, entity_id, operacion, id_usuario_actor, id_estacion, id_plantilla, version_plantilla, detalle_json, recorded_at_utc) VALUES ('TEMPLATE', ?, 'CREATE', ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
            if ($stmtAudit) {
                $auditStation = $idEstacion ?? 0;
                $auditJson = json_encode(['clave' => $clave, 'nombre' => $nombre, 'tipo_turno' => $tipoTurno, 'scope' => $scope, 'segmentos' => $insertedSegments]);
                $stmtAudit->bind_param("iiiiis", $templateId, $actorUserId, $auditStation, $templateId, $version, $auditJson);
                $stmtAudit->execute();
                $stmtAudit->close();
            }

            $this->db->commit();

            return [
                'id' => $templateId,
                'clave' => $clave,
                'version' => 1,
                'nombre' => $nombre,
                'tipo_turno' => $tipoTurno,
                'scope' => $scope,
                'id_estacion' => $idEstacion,
                'es_activo' => 1,
                'segmentos' => $insertedSegments
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Create a new version for an existing template key (clave).
     */
    public function createNewVersion(string $clave, array $payload, int $actorUserId = 0, ?int $callerStationId = null): array
    {
        $clave = trim($clave);
        $nombre = trim($payload['nombre'] ?? '');
        $tipoTurno = trim($payload['tipo_turno'] ?? 'REGULAR');
        $segmentos = $payload['segmentos'] ?? [];

        if (empty($clave)) {
            throw new InvalidArgumentException("INVALID_TEMPLATE_SPECIFICATION: clave is required.");
        }
        if (empty($nombre)) {
            throw new InvalidArgumentException("INVALID_TEMPLATE_SPECIFICATION: nombre is required.");
        }
        if (!is_array($segmentos) || empty($segmentos)) {
            throw new InvalidArgumentException("INVALID_TEMPLATE_SPECIFICATION: At least one segment is required.");
        }

        $this->validateSegments($segmentos);

        $scopeId = ($callerStationId !== null && $callerStationId > 0) ? $callerStationId : 0;

        $this->db->begin_transaction();
        try {
            // Lock all existing versions of this clave in scope to calculate max(version) atomically
            $stmt = $this->db->prepare("SELECT MAX(version) as max_v, scope, id_estacion FROM op_rh_v2_turnos_plantillas WHERE clave = ? AND scope_id = ? FOR UPDATE");
            $stmt->bind_param("si", $clave, $scopeId);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($res === null || $res['max_v'] === null) {
                throw new InvalidArgumentException("INVALID_TEMPLATE_SPECIFICATION: Template clave '{$clave}' does not exist in scope.");
            }

            $scope = (string)$res['scope'];
            $idEstacion = $res['id_estacion'] !== null ? (int)$res['id_estacion'] : null;
            $newVersion = ((int)$res['max_v']) + 1;
            $esActivo = 1;
            $actorIdNullable = $actorUserId > 0 ? $actorUserId : null;

            $stmtIns = $this->db->prepare("INSERT INTO op_rh_v2_turnos_plantillas (clave, version, nombre, tipo_turno, scope, id_estacion, es_activo, creado_por_usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->bind_param("sisssiii", $clave, $newVersion, $nombre, $tipoTurno, $scope, $idEstacion, $esActivo, $actorIdNullable);
            if (!$stmtIns->execute()) {
                throw new RuntimeException("DB_ERROR: Could not insert template version.");
            }
            $templateId = (int)$this->db->insert_id;
            $stmtIns->close();

            $insertedSegments = $this->insertSegments($templateId, $segmentos);

            // Audit log
            $stmtAudit = $this->db->prepare("INSERT INTO op_rh_v2_turnos_audit_log (entity_type, entity_id, operacion, id_usuario_actor, id_estacion, id_plantilla, version_plantilla, detalle_json, recorded_at_utc) VALUES ('TEMPLATE', ?, 'VERSION', ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
            if ($stmtAudit) {
                $auditStation = $idEstacion ?? 0;
                $auditJson = json_encode(['clave' => $clave, 'nombre' => $nombre, 'tipo_turno' => $tipoTurno, 'scope' => $scope, 'version' => $newVersion, 'segmentos' => $insertedSegments]);
                $stmtAudit->bind_param("iiiiis", $templateId, $actorUserId, $auditStation, $templateId, $newVersion, $auditJson);
                $stmtAudit->execute();
                $stmtAudit->close();
            }

            $this->db->commit();

            return [
                'id' => $templateId,
                'clave' => $clave,
                'version' => $newVersion,
                'nombre' => $nombre,
                'tipo_turno' => $tipoTurno,
                'scope' => $scope,
                'id_estacion' => $idEstacion,
                'es_activo' => 1,
                'segmentos' => $insertedSegments
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * List shift templates (bounded pagination).
     */
    public function listTemplates(int $page = 1, int $perPage = 50, bool $includeAllVersions = false, ?int $filterStationId = null, bool $localOnly = false): array
    {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $hasStation = ($filterStationId !== null && $filterStationId > 0);
        if ($localOnly && !$hasStation) throw new InvalidArgumentException('Local-only template reads require a locality scope.');
        $whereScope = $localOnly
            ? "(scope = 'STATION' AND id_estacion = ?)"
            : ($hasStation ? "(scope = 'GLOBAL' OR (scope = 'STATION' AND id_estacion = ?))" : "scope = 'GLOBAL'");

        if ($includeAllVersions) {
            $sql = "SELECT id, clave, version, nombre, tipo_turno, scope, id_estacion, es_activo, creado_at FROM op_rh_v2_turnos_plantillas WHERE {$whereScope} ORDER BY clave ASC, version DESC LIMIT ? OFFSET ?";
        } else {
            $sql = "SELECT t1.id, t1.clave, t1.version, t1.nombre, t1.tipo_turno, t1.scope, t1.id_estacion, t1.es_activo, t1.creado_at
                    FROM op_rh_v2_turnos_plantillas t1
                    INNER JOIN (
                        SELECT clave, scope_id, MAX(version) as max_v FROM op_rh_v2_turnos_plantillas WHERE {$whereScope} GROUP BY clave, scope_id
                    ) t2 ON t1.clave = t2.clave AND t1.scope_id = t2.scope_id AND t1.version = t2.max_v
                    WHERE {$whereScope}
                    ORDER BY t1.clave ASC LIMIT ? OFFSET ?";
        }

        $stmt = $this->db->prepare($sql);
        if ($includeAllVersions) {
            if ($hasStation) {
                $stmt->bind_param("iii", $filterStationId, $perPage, $offset);
            } else {
                $stmt->bind_param("ii", $perPage, $offset);
            }
        } else {
            if ($hasStation) {
                $stmt->bind_param("iiii", $filterStationId, $filterStationId, $perPage, $offset);
            } else {
                $stmt->bind_param("ii", $perPage, $offset);
            }
        }
        $stmt->execute();
        $res = $stmt->get_result();

        $items = [];
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['version'] = (int)$row['version'];
            $row['scope'] = (string)$row['scope'];
            $row['id_estacion'] = $row['id_estacion'] !== null ? (int)$row['id_estacion'] : null;
            $row['es_activo'] = (int)$row['es_activo'];
            $row['derived_from_global'] = null;
            if ($hasStation && $row['scope'] === 'STATION') {
                $lineage = $this->db->prepare("SELECT source_global_clave, source_global_version FROM op_rh_v2_shift_template_station_lineage WHERE id_estacion = ? AND local_clave = ? LIMIT 1");
                if ($lineage) {
                    $lineage->bind_param('is', $filterStationId, $row['clave']); $lineage->execute();
                    $lineageRow = $lineage->get_result()->fetch_assoc(); $lineage->close();
                    if ($lineageRow) $row['derived_from_global'] = ['clave' => (string) $lineageRow['source_global_clave'], 'version' => (int) $lineageRow['source_global_version']];
                }
            }
            $items[] = $row;
        }

        if ($hasStation) {
            $stmtLineage = $this->db->prepare("SELECT source_global_clave, source_global_version, local_clave FROM op_rh_v2_shift_template_station_lineage WHERE id_estacion = ?");
            if ($stmtLineage) {
                $stmtLineage->bind_param('i', $filterStationId); $stmtLineage->execute(); $lineageResult = $stmtLineage->get_result(); $map = [];
                while ($lineageRow = $lineageResult->fetch_assoc()) $map[(string) $lineageRow['source_global_clave']] = $lineageRow;
                $stmtLineage->close();
                foreach ($items as &$item) if ($item['scope'] === 'GLOBAL') {
                    $entry = $map[$item['clave']] ?? null;
                    $item['station_customization'] = $entry ? ['clave' => (string) $entry['local_clave'], 'source_version' => (int) $entry['source_global_version']] : null;
                }
                unset($item);
            }
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
            'items' => $items
        ];
    }

    /**
     * Get specific template version detail with segments.
     */
    public function getTemplateVersion(string $clave, int $version, ?int $filterStationId = null, bool $localOnly = false): ?array
    {
        $hasStation = ($filterStationId !== null && $filterStationId > 0);
        if ($localOnly && !$hasStation) throw new InvalidArgumentException('Local-only template detail requires a locality scope.');
        if ($hasStation) {
            $scopeClause = $localOnly ? "(scope = 'STATION' AND id_estacion = ?)" : "(scope = 'GLOBAL' OR (scope = 'STATION' AND id_estacion = ?))";
            $stmt = $this->db->prepare("SELECT id, clave, version, nombre, tipo_turno, scope, id_estacion, es_activo, creado_at FROM op_rh_v2_turnos_plantillas WHERE clave = ? AND version = ? AND {$scopeClause} LIMIT 1");
            $stmt->bind_param("sii", $clave, $version, $filterStationId);
        } else {
            $stmt = $this->db->prepare("SELECT id, clave, version, nombre, tipo_turno, scope, id_estacion, es_activo, creado_at FROM op_rh_v2_turnos_plantillas WHERE clave = ? AND version = ? LIMIT 1");
            $stmt->bind_param("si", $clave, $version);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 0) {
            return null;
        }
        $tpl = $res->fetch_assoc();
        $tpl['id'] = (int)$tpl['id'];
        $tpl['version'] = (int)$tpl['version'];
        $tpl['scope'] = (string)$tpl['scope'];
        $tpl['id_estacion'] = $tpl['id_estacion'] !== null ? (int)$tpl['id_estacion'] : null;
        $tpl['es_activo'] = (int)$tpl['es_activo'];

        // Get segments
        $stmtSeg = $this->db->prepare("SELECT id, ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin FROM op_rh_v2_turnos_segmentos WHERE id_plantilla = ? ORDER BY ordinal ASC");
        $stmtSeg->bind_param("i", $tpl['id']);
        $stmtSeg->execute();
        $resSeg = $stmtSeg->get_result();

        $segmentos = [];
        while ($s = $resSeg->fetch_assoc()) {
            $s['id'] = (int)$s['id'];
            $s['ordinal'] = (int)$s['ordinal'];
            $s['dia_offset_inicio'] = (int)$s['dia_offset_inicio'];
            $s['dia_offset_fin'] = (int)$s['dia_offset_fin'];
            $segmentos[] = $s;
        }
        $tpl['segmentos'] = $segmentos;
        $tpl['derived_from_global'] = null;
        if ($tpl['scope'] === 'STATION') {
            $lineage = $this->db->prepare("SELECT source_global_clave, source_global_version FROM op_rh_v2_shift_template_station_lineage WHERE id_estacion = ? AND local_clave = ? LIMIT 1");
            if ($lineage) {
                $station = (int) ($tpl['id_estacion'] ?? 0); $localKey = (string) $tpl['clave'];
                $lineage->bind_param('is', $station, $localKey); $lineage->execute(); $lineageRow = $lineage->get_result()->fetch_assoc(); $lineage->close();
                if ($lineageRow) $tpl['derived_from_global'] = ['clave' => (string) $lineageRow['source_global_clave'], 'version' => (int) $lineageRow['source_global_version']];
            }
        }

        return $tpl;
    }

    private function validateSegments(array $segmentos): void
    {
        $ordinals = [];
        foreach ($segmentos as $s) {
            $ord = $s['ordinal'] ?? null;
            if ($ord === null || !is_int($ord) || $ord < 1) {
                throw new InvalidArgumentException("INVALID_TEMPLATE_SPECIFICATION: Segment ordinal must be a positive integer.");
            }
            if (in_array($ord, $ordinals, true)) {
                throw new InvalidArgumentException("INVALID_TEMPLATE_SPECIFICATION: Duplicate segment ordinal {$ord}.");
            }
            $ordinals[] = $ord;

            $hIni = $s['hora_inicio_local'] ?? '';
            $hFin = $s['hora_fin_local'] ?? '';
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $hIni) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $hFin)) {
                throw new InvalidArgumentException("INVALID_TEMPLATE_SPECIFICATION: Invalid time format for segment ordinal {$ord}.");
            }
        }
        sort($ordinals);
        for ($i = 0; $i < count($ordinals); $i++) {
            if ($ordinals[$i] !== ($i + 1)) {
                throw new InvalidArgumentException("INVALID_TEMPLATE_SPECIFICATION: Segment ordinals must be contiguous starting from 1.");
            }
        }
    }

    private function insertSegments(int $templateId, array $segmentos): array
    {
        $inserted = [];
        $stmt = $this->db->prepare("INSERT INTO op_rh_v2_turnos_segmentos (id_plantilla, ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin) VALUES (?, ?, ?, ?, ?, ?, ?)");

        foreach ($segmentos as $s) {
            $ord = (int)$s['ordinal'];
            $tipo = trim($s['tipo_segmento'] ?? 'WORK');
            $hIni = strlen($s['hora_inicio_local']) === 5 ? $s['hora_inicio_local'] . ':00' : $s['hora_inicio_local'];
            $hFin = strlen($s['hora_fin_local']) === 5 ? $s['hora_fin_local'] . ':00' : $s['hora_fin_local'];
            $offIni = (int)($s['dia_offset_inicio'] ?? 0);
            $offFin = (int)($s['dia_offset_fin'] ?? 0);

            $stmt->bind_param("iisssii", $templateId, $ord, $tipo, $hIni, $hFin, $offIni, $offFin);
            if (!$stmt->execute()) {
                throw new RuntimeException("DB_ERROR: Could not insert segment ordinal {$ord}.");
            }
            $segId = (int)$this->db->insert_id;

            $inserted[] = [
                'id' => $segId,
                'ordinal' => $ord,
                'tipo_segmento' => $tipo,
                'hora_inicio_local' => $hIni,
                'hora_fin_local' => $hFin,
                'dia_offset_inicio' => $offIni,
                'dia_offset_fin' => $offFin
            ];
        }

        return $inserted;
    }
}
