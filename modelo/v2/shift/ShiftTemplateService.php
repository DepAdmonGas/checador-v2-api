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

    /**
     * Create version 1 template with atomic 1..N segment set.
     */
    public function createTemplate(array $payload): array
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

        $this->db->begin_transaction();
        try {
            // Check if clave version 1 already exists
            $stmt = $this->db->prepare("SELECT id FROM op_rh_v2_turnos_plantillas WHERE clave = ? AND version = 1 LIMIT 1 FOR UPDATE");
            $stmt->bind_param("s", $clave);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                throw new RuntimeException("DUPLICATE_TEMPLATE_VERSION: Template {$clave} version 1 already exists.");
            }

            $version = 1;
            $esActivo = 1;
            $stmtIns = $this->db->prepare("INSERT INTO op_rh_v2_turnos_plantillas (clave, version, nombre, tipo_turno, es_activo) VALUES (?, ?, ?, ?, ?)");
            $stmtIns->bind_param("sissi", $clave, $version, $nombre, $tipoTurno, $esActivo);
            if (!$stmtIns->execute()) {
                throw new RuntimeException("DB_ERROR: Could not insert template.");
            }
            $templateId = (int)$this->db->insert_id;

            $insertedSegments = $this->insertSegments($templateId, $segmentos);

            $this->db->commit();

            return [
                'id' => $templateId,
                'clave' => $clave,
                'version' => 1,
                'nombre' => $nombre,
                'tipo_turno' => $tipoTurno,
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
    public function createNewVersion(string $clave, array $payload): array
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

        $this->db->begin_transaction();
        try {
            // Lock all existing versions of this clave to calculate max(version) atomically
            $stmt = $this->db->prepare("SELECT MAX(version) as max_v FROM op_rh_v2_turnos_plantillas WHERE clave = ? FOR UPDATE");
            $stmt->bind_param("s", $clave);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            if ($res['max_v'] === null) {
                throw new InvalidArgumentException("INVALID_TEMPLATE_SPECIFICATION: Template clave '{$clave}' does not exist.");
            }

            $newVersion = ((int)$res['max_v']) + 1;
            $esActivo = 1;

            $stmtIns = $this->db->prepare("INSERT INTO op_rh_v2_turnos_plantillas (clave, version, nombre, tipo_turno, es_activo) VALUES (?, ?, ?, ?, ?)");
            $stmtIns->bind_param("sissi", $clave, $newVersion, $nombre, $tipoTurno, $esActivo);
            if (!$stmtIns->execute()) {
                throw new RuntimeException("DB_ERROR: Could not insert template version.");
            }
            $templateId = (int)$this->db->insert_id;

            $insertedSegments = $this->insertSegments($templateId, $segmentos);

            $this->db->commit();

            return [
                'id' => $templateId,
                'clave' => $clave,
                'version' => $newVersion,
                'nombre' => $nombre,
                'tipo_turno' => $tipoTurno,
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
    public function listTemplates(int $page = 1, int $perPage = 50, bool $includeAllVersions = false): array
    {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        if ($includeAllVersions) {
            $sql = "SELECT id, clave, version, nombre, tipo_turno, es_activo, creado_at FROM op_rh_v2_turnos_plantillas ORDER BY clave ASC, version DESC LIMIT ? OFFSET ?";
        } else {
            $sql = "SELECT t1.id, t1.clave, t1.version, t1.nombre, t1.tipo_turno, t1.es_activo, t1.creado_at 
                    FROM op_rh_v2_turnos_plantillas t1
                    INNER JOIN (
                        SELECT clave, MAX(version) as max_v FROM op_rh_v2_turnos_plantillas GROUP BY clave
                    ) t2 ON t1.clave = t2.clave AND t1.version = t2.max_v
                    ORDER BY t1.clave ASC LIMIT ? OFFSET ?";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $perPage, $offset);
        $stmt->execute();
        $res = $stmt->get_result();

        $items = [];
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['version'] = (int)$row['version'];
            $row['es_activo'] = (int)$row['es_activo'];
            $items[] = $row;
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
    public function getTemplateVersion(string $clave, int $version): ?array
    {
        $stmt = $this->db->prepare("SELECT id, clave, version, nombre, tipo_turno, es_activo, creado_at FROM op_rh_v2_turnos_plantillas WHERE clave = ? AND version = ? LIMIT 1");
        $stmt->bind_param("si", $clave, $version);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows === 0) {
            return null;
        }
        $tpl = $res->fetch_assoc();
        $tpl['id'] = (int)$tpl['id'];
        $tpl['version'] = (int)$tpl['version'];
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
