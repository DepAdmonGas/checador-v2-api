<?php
/**
 * Checador V2 — ShiftAssignmentService
 * Manages effective-dated worker shift assignments and multi-workplace interval overlap validation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';
require_once __DIR__ . '/ShiftWorkerMembershipService.php';

class ShiftAssignmentService
{
    private mysqli $db;
    private ShiftWorkerMembershipService $membershipService;

    public function __construct(?mysqli $db = null)
    {
        if ($db !== null) {
            $this->db = $db;
        } else {
            $bdObj = new BD();
            $this->db = $bdObj->conectarBD();
        }
        $this->membershipService = new ShiftWorkerMembershipService($this->db);
    }

    /**
     * Create worker shift assignment with overlap validation.
     */
    public function createAssignment(array $payload, int $actorUserId = 0): array
    {
        $idPersonal = (int)($payload['id_personal'] ?? 0);
        $idEstacion = (int)($payload['id_estacion'] ?? 0);
        $idPlantilla = isset($payload['id_plantilla']) && $payload['id_plantilla'] !== null ? (int)$payload['id_plantilla'] : null;
        $diaSemana = strtoupper(trim($payload['dia_semana'] ?? ''));
        $validoDesde = trim($payload['valido_desde'] ?? '');
        $validoHasta = isset($payload['valido_hasta']) && !empty($payload['valido_hasta']) ? trim($payload['valido_hasta']) : null;
        $esActivo = (int)($payload['es_activo'] ?? 1);
        $tipoAsignacion = strtoupper(trim((string)($payload['tipo_asignacion'] ?? 'HABITUAL')));
        if ($tipoAsignacion === '') $tipoAsignacion = 'HABITUAL';

        $validDays = ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO', 'DOMINGO'];
        if ($idPersonal <= 0 || $idEstacion <= 0 || !in_array($diaSemana, $validDays, true)) {
            throw new InvalidArgumentException("INVALID_ASSIGNMENT_SPECIFICATION: id_personal, id_estacion, and valid dia_semana are required.");
        }
        if (!in_array($tipoAsignacion, ['HABITUAL', 'TEMPORAL'], true)) {
            throw new InvalidArgumentException("INVALID_ASSIGNMENT_SPECIFICATION: tipo_asignacion must be HABITUAL or TEMPORAL.");
        }
        if (!self::isValidCivilDate($validoDesde)) {
            throw new InvalidArgumentException("INVALID_EFFECTIVE_INTERVAL: valido_desde must be a valid YYYY-MM-DD date.");
        }
        if ($tipoAsignacion === 'TEMPORAL' && $validoHasta === null) {
            throw new InvalidArgumentException("INVALID_EFFECTIVE_INTERVAL: TEMPORAL assignments require an explicit valido_hasta date.");
        }
        if ($validoHasta !== null) {
            if (!self::isValidCivilDate($validoHasta)) {
                throw new InvalidArgumentException("INVALID_EFFECTIVE_INTERVAL: valido_hasta must be a valid YYYY-MM-DD date.");
            }
            if ($validoHasta <= $validoDesde) {
                throw new InvalidArgumentException("INVALID_EFFECTIVE_INTERVAL: valido_hasta must be strictly after valido_desde.");
            }
        }
        if ($esActivo !== 1) {
            throw new InvalidArgumentException('INVALID_ASSIGNMENT_SPECIFICATION: New schedule assignments must be active.');
        }

        $this->db->begin_transaction();
        try {
            $this->membershipService->lockAndAssertCoverage($idPersonal, $idEstacion, $validoDesde, $validoHasta);
            // Verify plantilla exists if provided
            if ($idPlantilla !== null) {
                $this->assertTemplateAllowedForWorkplace($idPlantilla, $idEstacion);
            }

            // Lock existing active assignments for (id_personal, id_estacion, dia_semana) to evaluate overlap
            $stmtLock = $this->db->prepare("SELECT id, id_plantilla, tipo_asignacion, valido_desde, valido_hasta, creado_at, registrado_at_utc FROM op_rh_v2_turnos_asignaciones WHERE id_personal = ? AND id_estacion = ? AND dia_semana = ? AND es_activo = 1 FOR UPDATE");
            $stmtLock->bind_param("iis", $idPersonal, $idEstacion, $diaSemana);
            $stmtLock->execute();
            $resLock = $stmtLock->get_result();

            while ($row = $resLock->fetch_assoc()) {
                $exTipo = (string)($row['tipo_asignacion'] ?? 'HABITUAL');
                // Only evaluate overlap against assignments of the same kind (HABITUAL vs HABITUAL, TEMPORAL vs TEMPORAL)
                if ($exTipo !== $tipoAsignacion) {
                    continue;
                }

                $exDesde = $row['valido_desde'];
                $exHasta = $row['valido_hasta'];

                // Check overlap formula: (A.valido_desde < B.valido_hasta OR B.valido_hasta IS NULL) AND (B.valido_desde < A.valido_hasta OR A.valido_hasta IS NULL)
                $overlapNewStartsBeforeExistingEnds = ($exHasta === null || $validoDesde < $exHasta);
                $overlapExistingStartsBeforeNewEnds = ($validoHasta === null || $exDesde < $validoHasta);

                if ($overlapNewStartsBeforeExistingEnds && $overlapExistingStartsBeforeNewEnds) {
                    $existingTemplateId = $row['id_plantilla'] === null ? null : (int) $row['id_plantilla'];
                    if ($existingTemplateId === $idPlantilla
                        && (string) $exDesde === $validoDesde
                        && $exHasta === $validoHasta) {
                        $existingId = (int) $row['id'];
                        $stmtLock->close();
                        $this->db->commit();
                        return [
                            'id' => $existingId, 'id_personal' => $idPersonal, 'id_estacion' => $idEstacion,
                            'id_plantilla' => $idPlantilla, 'tipo_asignacion' => $tipoAsignacion, 'dia_semana' => $diaSemana,
                            'valido_desde' => $validoDesde, 'valido_hasta' => $validoHasta,
                            'es_activo' => 1,
                            'creado_at' => (string) $row['creado_at'],
                            'registrado_at_utc' => (string) $row['registrado_at_utc'],
                            'idempotent' => true,
                        ];
                    }
                    throw new RuntimeException("ASSIGNMENT_OVERLAP_CONFLICT: Worker {$idPersonal} already has an active {$tipoAsignacion} assignment for station {$idEstacion} on {$diaSemana} overlapping interval.");
                }
            }
            $stmtLock->close();

            $actorIdNullable = $actorUserId > 0 ? $actorUserId : null;
            $stmtIns = $this->db->prepare("INSERT INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, tipo_asignacion, dia_semana, valido_desde, valido_hasta, es_activo, creado_por_usuario_id, registrado_at_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
            $stmtIns->bind_param("iiissssii", $idPersonal, $idEstacion, $idPlantilla, $tipoAsignacion, $diaSemana, $validoDesde, $validoHasta, $esActivo, $actorIdNullable);
            if (!$stmtIns->execute()) {
                throw new RuntimeException("DB_ERROR: Could not insert shift assignment.");
            }
            $asigId = (int)$this->db->insert_id;

            // Return only timestamps read back from the committed row's transaction.
            $stmtAuditRead = $this->db->prepare("SELECT creado_at, registrado_at_utc FROM op_rh_v2_turnos_asignaciones WHERE id = ? LIMIT 1");
            $stmtAuditRead->bind_param("i", $asigId);
            $stmtAuditRead->execute();
            $auditResult = $stmtAuditRead->get_result();
            $auditRow = $auditResult->fetch_assoc();
            $stmtAuditRead->close();
            if (!is_array($auditRow)
                || !isset($auditRow['creado_at'], $auditRow['registrado_at_utc'])
                || trim((string) $auditRow['creado_at']) === ''
                || trim((string) $auditRow['registrado_at_utc']) === '') {
                throw new RuntimeException('DB_ERROR: Persisted assignment audit timestamps could not be read back.');
            }

            // Audit log table
            $stmtAuditLog = $this->db->prepare("INSERT INTO op_rh_v2_turnos_audit_log (entity_type, entity_id, operacion, id_usuario_actor, id_estacion, id_personal, tipo_asignacion, valido_desde, valido_hasta, id_plantilla, detalle_json, recorded_at_utc) VALUES ('ASSIGNMENT', ?, 'CREATE', ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
            if ($stmtAuditLog) {
                $detJson = json_encode(['id_personal' => $idPersonal, 'id_estacion' => $idEstacion, 'id_plantilla' => $idPlantilla, 'tipo_asignacion' => $tipoAsignacion, 'dia_semana' => $diaSemana, 'valido_desde' => $validoDesde, 'valido_hasta' => $validoHasta]);
                $stmtAuditLog->bind_param("iiiisssis", $asigId, $actorUserId, $idEstacion, $idPersonal, $tipoAsignacion, $validoDesde, $validoHasta, $idPlantilla, $detJson);
                $stmtAuditLog->execute();
                $stmtAuditLog->close();
            }

            $this->db->commit();

            return [
                'id' => $asigId,
                'id_personal' => $idPersonal,
                'id_estacion' => $idEstacion,
                'id_plantilla' => $idPlantilla,
                'tipo_asignacion' => $tipoAsignacion,
                'dia_semana' => $diaSemana,
                'valido_desde' => $validoDesde,
                'valido_hasta' => $validoHasta,
                'es_activo' => $esActivo,
                'creado_at' => (string) $auditRow['creado_at'],
                'registrado_at_utc' => (string) $auditRow['registrado_at_utc'],
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Close an active assignment effective on a given date.
     */
    public function closeAssignment(int $assignmentId, ?string $effectiveCloseDate = null, ?array $expectedState = null): array
    {
        $closeDate = $effectiveCloseDate !== null ? trim($effectiveCloseDate) : date('Y-m-d');
        if (!self::isValidCivilDate($closeDate)) {
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

            if ((int) $asig['es_activo'] !== 1) {
                if ((string) ($asig['valido_hasta'] ?? '') === $closeDate
                    && ($expectedState === null
                        || ((string) ($asig['valido_desde'] ?? '') === (string) ($expectedState['valido_desde'] ?? '')))) {
                    $this->db->commit();
                    return self::normalizeAssignment($asig) + ['idempotent' => true];
                }
                throw new RuntimeException('ASSIGNMENT_VERSION_CONFLICT: The assignment is already closed at a different date.');
            }
            if ($expectedState !== null && !self::matchesExpectedState($asig, $expectedState)) {
                throw new RuntimeException('ASSIGNMENT_VERSION_CONFLICT: The assignment changed after it was displayed.');
            }

            if ($closeDate <= $asig['valido_desde']) {
                throw new InvalidArgumentException("INVALID_EFFECTIVE_INTERVAL: Closing date must be strictly after assignment valido_desde ({$asig['valido_desde']}).");
            }
            if ($asig['valido_hasta'] !== null && $closeDate > $asig['valido_hasta']) {
                throw new InvalidArgumentException('INVALID_EFFECTIVE_INTERVAL: Closing date extends beyond the assignment validity.');
            }

            $this->membershipService->lockAndAssertCoverage(
                (int) $asig['id_personal'],
                (int) $asig['id_estacion'],
                (string) $asig['valido_desde'],
                $closeDate
            );

            $stmtUpd = $this->db->prepare("UPDATE op_rh_v2_turnos_asignaciones SET valido_hasta = ?, es_activo = 0 WHERE id = ? AND es_activo = 1");
            $stmtUpd->bind_param("si", $closeDate, $assignmentId);
            if (!$stmtUpd->execute() || $stmtUpd->affected_rows !== 1) {
                $stmtUpd->close();
                throw new RuntimeException('ASSIGNMENT_VERSION_CONFLICT: Assignment changed during close.');
            }
            $stmtUpd->close();

            $this->db->commit();

            $asig['valido_hasta'] = $closeDate;
            $asig['es_activo'] = 0;
            return self::normalizeAssignment($asig) + ['idempotent' => false];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /** Atomically close an immutable assignment and add its authorized successor. */
    public function replaceAssignment(
        int $assignmentId,
        string $closeDate,
        array $replacement,
        array $expectedState,
        int $actorUserId = 0
    ): array
    {
        $closeDate = trim($closeDate);
        $templateId = ($replacement['id_plantilla'] ?? null) === null ? null : (int) $replacement['id_plantilla'];
        $replacementTo = isset($replacement['valido_hasta']) && $replacement['valido_hasta'] !== ''
            ? trim((string) $replacement['valido_hasta']) : null;
        if ($assignmentId < 1 || !self::isValidCivilDate($closeDate)
            || ($replacementTo !== null && !self::isValidCivilDate($replacementTo))
            || ($replacementTo !== null && $replacementTo <= $closeDate)
            || !array_key_exists('valido_desde', $expectedState)
            || !array_key_exists('valido_hasta', $expectedState)
            || !self::isValidCivilDate((string) $expectedState['valido_desde'])
            || ($expectedState['valido_hasta'] !== null && !self::isValidCivilDate((string) $expectedState['valido_hasta']))
            || $actorUserId < 1) {
            throw new InvalidArgumentException('INVALID_EFFECTIVE_INTERVAL: Replacement interval or expected source version is invalid.');
        }

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('SELECT id, id_personal, id_estacion, id_plantilla, tipo_asignacion, dia_semana, valido_desde, valido_hasta, es_activo, creado_por_usuario_id FROM op_rh_v2_turnos_asignaciones WHERE id = ? FOR UPDATE');
            if (!$stmt) throw new RuntimeException('DB_ERROR: Could not lock source assignment.');
            $stmt->bind_param('i', $assignmentId);
            $stmt->execute();
            $source = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$source) throw new InvalidArgumentException('NOT_FOUND: Assignment not found.');

            $workerId = (int) $source['id_personal'];
            $workplaceId = (int) $source['id_estacion'];
            $weekday = (string) $source['dia_semana'];
            $replacementFrom = $closeDate;

            if ((int) $source['es_activo'] !== 1) {
                if ((string) ($source['valido_hasta'] ?? '') === $closeDate
                    && (string) $source['valido_desde'] === (string) $expectedState['valido_desde']) {
                    $existing = $this->findExactActiveAssignment(
                        $workerId, $workplaceId, $templateId, $weekday, $replacementFrom, $replacementTo, $assignmentId
                    );
                    if ($existing !== null) {
                        $this->db->commit();
                        return [
                            'closed' => self::normalizeAssignment($source),
                            'replacement' => $existing,
                            'idempotent' => true,
                        ];
                    }
                }
                throw new RuntimeException('ASSIGNMENT_VERSION_CONFLICT: Source assignment is no longer active.');
            }
            if (!self::matchesExpectedState($source, $expectedState)) {
                throw new RuntimeException('ASSIGNMENT_VERSION_CONFLICT: The assignment changed after it was displayed.');
            }
            if ($closeDate <= (string) $source['valido_desde']
                || ($source['valido_hasta'] !== null && $closeDate >= (string) $source['valido_hasta'])) {
                throw new InvalidArgumentException('INVALID_EFFECTIVE_INTERVAL: Replacement must begin inside the current assignment interval and after its start.');
            }
            if ($replacementTo !== null && $replacementTo <= $closeDate) {
                throw new InvalidArgumentException('INVALID_EFFECTIVE_INTERVAL: Replacement interval must have positive duration.');
            }
            if ($templateId !== null) {
                $this->assertTemplateAllowedForWorkplace($templateId, $workplaceId);
            }

            $this->membershipService->lockAndAssertCoverage($workerId, $workplaceId, (string) $source['valido_desde'], $closeDate);
            $this->membershipService->lockAndAssertCoverage($workerId, $workplaceId, $replacementFrom, $replacementTo);

            $close = $this->db->prepare('UPDATE op_rh_v2_turnos_asignaciones SET valido_hasta = ?, es_activo = 0, actualizado_por_usuario_id = ? WHERE id = ? AND es_activo = 1 AND valido_desde = ?');
            if (!$close) throw new RuntimeException('DB_ERROR: Could not close source assignment.');
            $originalFrom = (string) $source['valido_desde'];
            $close->bind_param('siis', $closeDate, $actorUserId, $assignmentId, $originalFrom);
            if (!$close->execute() || $close->affected_rows !== 1) {
                $close->close();
                throw new RuntimeException('ASSIGNMENT_VERSION_CONFLICT: Assignment changed during replacement.');
            }
            $close->close();

            $overlap = $this->db->prepare('SELECT id FROM op_rh_v2_turnos_asignaciones WHERE id_personal = ? AND id_estacion = ? AND dia_semana = ? AND es_activo = 1 AND (valido_hasta IS NULL OR ? < valido_hasta) AND (? IS NULL OR valido_desde < ?) LIMIT 1 FOR UPDATE');
            if (!$overlap) throw new RuntimeException('DB_ERROR: Could not verify replacement overlap.');
            $overlap->bind_param('iissss', $workerId, $workplaceId, $weekday, $replacementFrom, $replacementTo, $replacementTo);
            $overlap->execute();
            $conflict = $overlap->get_result()->fetch_assoc();
            $overlap->close();
            if ($conflict) throw new RuntimeException('ASSIGNMENT_OVERLAP_CONFLICT: Replacement overlaps another active assignment.');

            $assignmentType = (string) ($source['tipo_asignacion'] ?? 'HABITUAL');
            $replacementId = $this->insertAssignmentRecord($workerId, $workplaceId, $templateId, $assignmentType, $weekday, $replacementFrom, $replacementTo, $actorUserId);
            $sourceAuditDetail = $this->encodeAuditDetail([
                'operation' => 'REPLACE',
                'source_assignment_id' => $assignmentId,
                'replacement_assignment_id' => $replacementId,
                'state' => 'CLOSED',
                'previous' => [
                    'id_plantilla' => $source['id_plantilla'] === null ? null : (int) $source['id_plantilla'],
                    'tipo_asignacion' => $assignmentType,
                    'dia_semana' => $weekday,
                    'valido_desde' => (string) $source['valido_desde'],
                    'valido_hasta' => $closeDate,
                ],
                'actor_user_id' => $actorUserId,
            ]);
            $closeAudit = $this->insertAssignmentAudit(
                $assignmentId,
                'CLOSE',
                $actorUserId,
                $workplaceId,
                $workerId,
                $assignmentType,
                (string) $source['valido_desde'],
                $closeDate,
                $source['id_plantilla'] === null ? null : (int) $source['id_plantilla'],
                $sourceAuditDetail
            );
            $replacementAuditDetail = $this->encodeAuditDetail([
                'operation' => 'REPLACE',
                'source_assignment_id' => $assignmentId,
                'replacement_assignment_id' => $replacementId,
                'state' => 'ACTIVE',
                'new' => [
                    'id_plantilla' => $templateId,
                    'tipo_asignacion' => $assignmentType,
                    'dia_semana' => $weekday,
                    'valido_desde' => $replacementFrom,
                    'valido_hasta' => $replacementTo,
                    'creado_por_usuario_id' => $actorUserId,
                ],
                'actor_user_id' => $actorUserId,
            ]);
            $replacementAudit = $this->insertAssignmentAudit(
                $replacementId,
                'REPLACE',
                $actorUserId,
                $workplaceId,
                $workerId,
                $assignmentType,
                $replacementFrom,
                $replacementTo,
                $templateId,
                $replacementAuditDetail
            );
            $this->db->commit();
            $source['valido_hasta'] = $closeDate;
            $source['es_activo'] = 0;
            return [
                'closed' => self::normalizeAssignment($source),
                'replacement' => [
                    'id' => $replacementId, 'id_personal' => $workerId, 'id_estacion' => $workplaceId,
                    'id_plantilla' => $templateId, 'tipo_asignacion' => $assignmentType, 'dia_semana' => $weekday,
                    'valido_desde' => $replacementFrom, 'valido_hasta' => $replacementTo, 'es_activo' => 1,
                    'creado_por_usuario_id' => $actorUserId,
                ],
                'audit' => [
                    'close' => $closeAudit,
                    'replace' => $replacementAudit,
                ],
                'idempotent' => false,
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function assertTemplateAllowedForWorkplace(int $templateId, int $workplaceId): void
    {
        if ($workplaceId === 9) {
            $stmt = $this->db->prepare("SELECT id FROM op_rh_v2_turnos_plantillas WHERE id = ? AND es_activo = 1 AND scope = 'STATION' AND id_estacion = ? LIMIT 1");
            if (!$stmt) throw new RuntimeException('DB_ERROR: Could not verify Autolavado-local template.');
            $stmt->bind_param('ii', $templateId, $workplaceId);
        } else {
            $stmt = $this->db->prepare("SELECT id FROM op_rh_v2_turnos_plantillas WHERE id = ? AND es_activo = 1 LIMIT 1");
            if (!$stmt) throw new RuntimeException('DB_ERROR: Could not verify active template.');
            $stmt->bind_param('i', $templateId);
        }
        $stmt->execute();
        $found = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        if (!$found) throw new InvalidArgumentException(
            $workplaceId === 9
                ? 'INVALID_TEMPLATE_SPECIFICATION: Autolavado assignments require an active template belonging to locality 9.'
                : 'INVALID_TEMPLATE_SPECIFICATION: Active template not found.'
        );
    }

    private function insertAssignmentRecord(int $workerId, int $workplaceId, ?int $templateId, string $assignmentType, string $weekday, string $from, ?string $to, int $actorUserId): int
    {
        $active = 1;
        $stmt = $this->db->prepare('INSERT INTO op_rh_v2_turnos_asignaciones (id_personal, id_estacion, id_plantilla, tipo_asignacion, dia_semana, valido_desde, valido_hasta, es_activo, creado_por_usuario_id, registrado_at_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        if (!$stmt) throw new RuntimeException('DB_ERROR: Could not create replacement assignment.');
        $stmt->bind_param('iiissssii', $workerId, $workplaceId, $templateId, $assignmentType, $weekday, $from, $to, $active, $actorUserId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('DB_ERROR: Could not create replacement assignment.');
        }
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    private function insertAssignmentAudit(
        int $entityId,
        string $operation,
        int $actorUserId,
        int $workplaceId,
        int $workerId,
        string $assignmentType,
        string $validFrom,
        ?string $validTo,
        ?int $templateId,
        string $detailJson
    ): array {
        $stmt = $this->db->prepare("INSERT INTO op_rh_v2_turnos_audit_log (entity_type, entity_id, operacion, id_usuario_actor, id_estacion, id_personal, tipo_asignacion, valido_desde, valido_hasta, id_plantilla, detalle_json, recorded_at_utc) VALUES ('ASSIGNMENT', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
        if (!$stmt) throw new RuntimeException('DB_ERROR: Could not prepare replacement assignment audit.');
        $stmt->bind_param('isiiisssis', $entityId, $operation, $actorUserId, $workplaceId, $workerId, $assignmentType, $validFrom, $validTo, $templateId, $detailJson);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('DB_ERROR: Could not persist replacement assignment audit.');
        }
        $auditId = (int) $this->db->insert_id;
        $stmt->close();
        $read = $this->db->prepare('SELECT id, recorded_at_utc FROM op_rh_v2_turnos_audit_log WHERE id = ? LIMIT 1');
        if (!$read) throw new RuntimeException('DB_ERROR: Could not read replacement assignment audit.');
        $read->bind_param('i', $auditId);
        $read->execute();
        $row = $read->get_result()->fetch_assoc();
        $read->close();
        if (!is_array($row) || (int) ($row['id'] ?? 0) !== $auditId || trim((string) ($row['recorded_at_utc'] ?? '')) === '') {
            throw new RuntimeException('DB_ERROR: Replacement assignment audit readback failed.');
        }
        return ['id' => $auditId, 'operation' => $operation, 'actor_user_id' => $actorUserId, 'recorded_at_utc' => (string) $row['recorded_at_utc']];
    }

    private function encodeAuditDetail(array $detail): string
    {
        try {
            return json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException('DB_ERROR: Could not encode replacement assignment audit.');
        }
    }

    private function findExactActiveAssignment(int $workerId, int $workplaceId, ?int $templateId, string $weekday, string $from, ?string $to, int $excludeId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, id_personal, id_estacion, id_plantilla, dia_semana, valido_desde, valido_hasta, es_activo FROM op_rh_v2_turnos_asignaciones WHERE id_personal = ? AND id_estacion = ? AND id_plantilla <=> ? AND dia_semana = ? AND valido_desde = ? AND valido_hasta <=> ? AND es_activo = 1 AND id <> ? LIMIT 1');
        if (!$stmt) throw new RuntimeException('DB_ERROR: Could not verify replacement idempotency.');
        $stmt->bind_param('iiisssi', $workerId, $workplaceId, $templateId, $weekday, $from, $to, $excludeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? self::normalizeAssignment($row) : null;
    }

    private static function matchesExpectedState(array $row, array $expected): bool
    {
        return array_key_exists('valido_desde', $expected)
            && array_key_exists('valido_hasta', $expected)
            && (string) $row['valido_desde'] === (string) $expected['valido_desde']
            && (($row['valido_hasta'] ?? null) === ($expected['valido_hasta'] ?? null));
    }

    private static function normalizeAssignment(array $row): array
    {
        foreach (['id', 'id_personal', 'id_estacion', 'id_plantilla'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) $row[$key] = (int) $row[$key];
        }
        if (array_key_exists('es_activo', $row)) $row['es_activo'] = (int) $row['es_activo'];
        if (array_key_exists('tipo_asignacion', $row)) $row['tipo_asignacion'] = (string) $row['tipo_asignacion'];
        return $row;
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
    public function listAssignments(int $idPersonal, array $authorizedStations, ?int $idEstacion = null, ?string $fechaRef = null, int $page = 1, int $perPage = 50): array
    {
        $query = self::buildListAssignmentsQueryPlan($idPersonal, $authorizedStations, $idEstacion, $fechaRef, $page, $perPage);
        $stmt = $this->db->prepare($query['sql']);
        if (!$stmt) {
            throw new RuntimeException('DB_ERROR: Could not prepare assignment read query.');
        }
        $stmt->bind_param($query['types'], ...$query['params']);
        $stmt->execute();
        $res = $stmt->get_result();

        $items = [];
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['id_personal'] = (int)$row['id_personal'];
            $row['id_estacion'] = (int)$row['id_estacion'];
            $row['id_plantilla'] = $row['id_plantilla'] !== null ? (int)$row['id_plantilla'] : null;
            $row['tipo_asignacion'] = (string)($row['tipo_asignacion'] ?? 'HABITUAL');
            $row['es_activo'] = (int)$row['es_activo'];
            $items[] = $row;
        }

        return [
            'page' => $query['page'],
            'per_page' => $query['per_page'],
            'items' => $items
        ];
    }

    /** Validate a civil YYYY-MM-DD date without accepting normalized dates. */
    public static function isValidCivilDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
            return false;
        }

        return checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]);
    }

    /**
     * Build a bounded, parameterized assignment read query for a verified scope.
     * This pure plan is also exercised with isolated in-memory data by security tests.
     *
     * @internal
     */
    private static function buildListAssignmentsQueryPlan(
        int $idPersonal,
        array $authorizedStations,
        ?int $idEstacion,
        ?string $fechaRef,
        int $page,
        int $perPage
    ): array {
        if ($idPersonal <= 0) {
            throw new InvalidArgumentException('INVALID_ASSIGNMENT_SPECIFICATION: id_personal is required.');
        }
        if ($authorizedStations === [] || !array_is_list($authorizedStations)) {
            throw new DomainException('STATION_SCOPE_DENIED: Empty or malformed authorized locality scope.');
        }

        $seenStations = [];
        foreach ($authorizedStations as $stationId) {
            if (!is_int($stationId) || $stationId <= 0 || isset($seenStations[$stationId])) {
                throw new DomainException('STATION_SCOPE_DENIED: Invalid or ambiguous authorized locality scope.');
            }
            $seenStations[$stationId] = true;
        }

        if ($idEstacion !== null) {
            if ($idEstacion <= 0 || !isset($seenStations[$idEstacion])) {
                throw new DomainException('UNAUTHORIZED_STATION_ACCESS: Requested locality is outside the authorized scope.');
            }
            $queryStations = [$idEstacion];
        } else {
            $queryStations = $authorizedStations;
        }

        if ($fechaRef !== null && !self::isValidCivilDate($fechaRef)) {
            throw new InvalidArgumentException('INVALID_REFERENCE_DATE: fecha_referencia must be a valid YYYY-MM-DD civil date.');
        }

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $placeholders = implode(', ', array_fill(0, count($queryStations), '?'));

        $params = array_merge([$idPersonal], $queryStations);
        $types = 'i' . str_repeat('i', count($queryStations));
        $where = "WHERE id_personal = ? AND id_estacion IN ({$placeholders})";

        if ($fechaRef !== null) {
            $where .= " AND valido_desde <= ? AND (
                (es_activo = 1 AND (valido_hasta > ? OR valido_hasta IS NULL))
                OR
                (es_activo = 0 AND valido_hasta IS NOT NULL AND valido_hasta > valido_desde AND valido_hasta > ?)
            )";
            $types .= 'sss';
            $params[] = $fechaRef;
            $params[] = $fechaRef;
            $params[] = $fechaRef;
        }

        $sql = "SELECT id, id_personal, id_estacion, id_plantilla, tipo_asignacion, dia_semana, valido_desde, valido_hasta, es_activo, registrado_at_utc
                FROM op_rh_v2_turnos_asignaciones {$where} ORDER BY valido_desde DESC LIMIT ? OFFSET ?";

        $types .= 'ii';
        $params[] = $perPage;
        $params[] = $offset;

        return [
            'sql' => $sql,
            'types' => $types,
            'params' => $params,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
