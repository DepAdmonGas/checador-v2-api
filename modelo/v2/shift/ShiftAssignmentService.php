<?php
/**
 * Checador V2 — ShiftAssignmentService
 * Manages effective-dated worker shift assignments and multi-workplace interval overlap validation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';

class ShiftAssignmentService
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
     * Create worker shift assignment with overlap validation.
     */
    public function createAssignment(array $payload): array
    {
        $idPersonal = (int)($payload['id_personal'] ?? 0);
        $idEstacion = (int)($payload['id_estacion'] ?? 0);
        $idPlantilla = isset($payload['id_plantilla']) && $payload['id_plantilla'] !== null ? (int)$payload['id_plantilla'] : null;
        $diaSemana = strtoupper(trim($payload['dia_semana'] ?? ''));
        $validoDesde = trim($payload['valido_desde'] ?? '');
        $validoHasta = isset($payload['valido_hasta']) && !empty($payload['valido_hasta']) ? trim($payload['valido_hasta']) : null;
        $esActivo = (int)($payload['es_activo'] ?? 1);

        $validDays = ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO', 'DOMINGO'];
        if ($idPersonal <= 0 || $idEstacion <= 0 || !in_array($diaSemana, $validDays, true)) {
            throw new InvalidArgumentException("INVALID_ASSIGNMENT_SPECIFICATION: id_personal, id_estacion, and valid dia_semana are required.");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validoDesde)) {
            throw new InvalidArgumentException("INVALID_EFFECTIVE_INTERVAL: valido_desde must be a valid YYYY-MM-DD date.");
        }
        if ($validoHasta !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validoHasta)) {
                throw new InvalidArgumentException("INVALID_EFFECTIVE_INTERVAL: valido_hasta must be a valid YYYY-MM-DD date.");
            }
            if ($validoHasta <= $validoDesde) {
                throw new InvalidArgumentException("INVALID_EFFECTIVE_INTERVAL: valido_hasta must be strictly after valido_desde.");
            }
        }

        $this->db->begin_transaction();
        try {
            // Verify plantilla exists if provided
            if ($idPlantilla !== null) {
                $stmtP = $this->db->prepare("SELECT id FROM op_rh_v2_turnos_plantillas WHERE id = ? LIMIT 1");
                $stmtP->bind_param("i", $idPlantilla);
                $stmtP->execute();
                if ($stmtP->get_result()->num_rows === 0) {
                    throw new InvalidArgumentException("INVALID_TEMPLATE_SPECIFICATION: id_plantilla {$idPlantilla} does not exist.");
                }
            }

            // Lock existing active assignments for (id_personal, id_estacion, dia_semana) to evaluate overlap
            $stmtLock = $this->db->prepare("SELECT id, valido_desde, valido_hasta FROM op_rh_v2_turnos_asignaciones WHERE id_personal = ? AND id_estacion = ? AND dia_semana = ? AND es_activo = 1 FOR UPDATE");
            $stmtLock->bind_param("iis", $idPersonal, $idEstacion, $diaSemana);
            $stmtLock->execute();
            $resLock = $stmtLock->get_result();

            while ($row = $resLock->fetch_assoc()) {
                $exDesde = $row['valido_desde'];
                $exHasta = $row['valido_hasta'];

                // Check overlap formula: (A.valido_desde < B.valido_hasta OR B.valido_hasta IS NULL) AND (B.valido_desde < A.valido_hasta OR A.valido_hasta IS NULL)
                $overlapNewStartsBeforeExistingEnds = ($exHasta === null || $validoDesde < $exHasta);
                $overlapExistingStartsBeforeNewEnds = ($validoHasta === null || $exDesde < $validoHasta);

                if ($overlapNewStartsBeforeExistingEnds && $overlapExistingStartsBeforeNewEnds) {
                    throw new RuntimeException("ASSIGNMENT_OVERLAP_CONFLICT: Worker {$idPersonal} already has an active assignment for station {$idEstacion} on {$diaSemana} overlapping interval.");
                }
            }

            $stmtIns = $this->db->prepare("INSERT INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo, registrado_at_utc) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
            $stmtIns->bind_param("iiisssi", $idPersonal, $idEstacion, $idPlantilla, $diaSemana, $validoDesde, $validoHasta, $esActivo);
            if (!$stmtIns->execute()) {
                throw new RuntimeException("DB_ERROR: Could not insert shift assignment.");
            }
            $asigId = (int)$this->db->insert_id;

            $this->db->commit();

            return [
                'id' => $asigId,
                'id_personal' => $idPersonal,
                'id_estacion' => $idEstacion,
                'id_plantilla' => $idPlantilla,
                'dia_semana' => $diaSemana,
                'valido_desde' => $validoDesde,
                'valido_hasta' => $validoHasta,
                'es_activo' => $esActivo
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Close an active assignment effective on a given date.
     */
    public function closeAssignment(int $assignmentId, ?string $effectiveCloseDate = null): array
    {
        $closeDate = $effectiveCloseDate !== null ? trim($effectiveCloseDate) : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $closeDate)) {
            throw new InvalidArgumentException("INVALID_EFFECTIVE_INTERVAL: effectiveCloseDate must be YYYY-MM-DD.");
        }

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("SELECT id, id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo FROM op_rh_v2_turnos_asignaciones WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $assignmentId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->num_rows === 0) {
                throw new InvalidArgumentException("NOT_FOUND: Assignment ID {$assignmentId} not found.");
            }
            $asig = $res->fetch_assoc();

            if ($closeDate <= $asig['valido_desde']) {
                throw new InvalidArgumentException("INVALID_EFFECTIVE_INTERVAL: Closing date must be strictly after assignment valido_desde ({$asig['valido_desde']}).");
            }

            $stmtUpd = $this->db->prepare("UPDATE op_rh_v2_turnos_asignaciones SET valido_hasta = ?, es_activo = 0 WHERE id = ?");
            $stmtUpd->bind_param("si", $closeDate, $assignmentId);
            $stmtUpd->execute();

            $this->db->commit();

            $asig['id'] = (int)$asig['id'];
            $asig['valido_hasta'] = $closeDate;
            $asig['es_activo'] = 0;
            return $asig;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Cancel / revoke an assignment that was created in error.
     * Sets es_activo = 0 and acotes valido_hasta = valido_desde so it never resolves for any date.
     */
    public function cancelAssignment(int $assignmentId): array
    {
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("SELECT id, id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo FROM op_rh_v2_turnos_asignaciones WHERE id = ? FOR UPDATE");
            $stmt->bind_param("i", $assignmentId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->num_rows === 0) {
                throw new InvalidArgumentException("NOT_FOUND: Assignment ID {$assignmentId} not found.");
            }
            $asig = $res->fetch_assoc();

            $stmtUpd = $this->db->prepare("UPDATE op_rh_v2_turnos_asignaciones SET valido_hasta = valido_desde, es_activo = 0 WHERE id = ?");
            $stmtUpd->bind_param("i", $assignmentId);
            $stmtUpd->execute();

            $this->db->commit();

            $asig['id'] = (int)$asig['id'];
            $asig['valido_hasta'] = $asig['valido_desde'];
            $asig['es_activo'] = 0;
            return $asig;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * List worker assignments with bounded pagination.
     */
    public function listAssignments(int $idPersonal, ?int $idEstacion = null, ?string $fechaRef = null, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $params = [$idPersonal];
        $types = "i";
        $where = "WHERE id_personal = ?";

        if ($idEstacion !== null && $idEstacion > 0) {
            $where .= " AND id_estacion = ?";
            $types .= "i";
            $params[] = $idEstacion;
        }

        if ($fechaRef !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaRef)) {
            $where .= " AND valido_desde <= ? AND (
                (es_activo = 1 AND (valido_hasta > ? OR valido_hasta IS NULL))
                OR
                (es_activo = 0 AND valido_hasta IS NOT NULL AND valido_hasta > valido_desde AND valido_hasta > ?)
            )";
            $types .= "sss";
            $params[] = $fechaRef;
            $params[] = $fechaRef;
            $params[] = $fechaRef;
        }

        $sql = "SELECT id, id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo, registrado_at_utc 
                FROM op_rh_v2_turnos_asignaciones {$where} ORDER BY valido_desde DESC LIMIT ? OFFSET ?";

        $types .= "ii";
        $params[] = $perPage;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        $items = [];
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['id_personal'] = (int)$row['id_personal'];
            $row['id_estacion'] = (int)$row['id_estacion'];
            $row['id_plantilla'] = $row['id_plantilla'] !== null ? (int)$row['id_plantilla'] : null;
            $row['es_activo'] = (int)$row['es_activo'];
            $items[] = $row;
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
            'items' => $items
        ];
    }
}
