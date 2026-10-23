<?php
/**
 * Checador V2 — Administrative Incidence & Evaluation Service (V2.5 Domain Runtime)
 *
 * Consumes explicit immutable P2 journey calculation revisions, materialized ScheduledShifts,
 * active administrative incidence applications, and administrative adjustments to compute
 * append-only administrative evaluation revisions.
 *
 * Spec Reference: docs/v2/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_CONTRACT_V1.0.md
 * Physical Spec:  docs/architecture/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_V1.0.md
 * Addendum Spec:  docs/architecture/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_ADDENDUM_V1.1.md
 */

declare(strict_types=1);

namespace modelo\v2\journey;

use mysqli;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

class AdministrativeEvaluationService {

    private mysqli $db;
    private string $algorithmVersion;
    private string $policyVersion;

    public function __construct(
        mysqli $db,
        string $algorithmVersion = 'v2.5-d',
        string $policyVersion = 'POLICY_V1.0_DEFAULT'
    ) {
        $this->db = $db;
        $this->algorithmVersion = $algorithmVersion;
        $this->policyVersion = $policyVersion;
    }

    /**
     * Creates an administrative incidence master and its initial revision 1.
     */
    public function createIncidenceMaster(array $params): array {
        $uuidIncid  = $params['uuid_incidencia'] ?? ('INC_' . uniqid('', true));
        $workerId   = (int)($params['id_personal'] ?? 0);
        $stationId  = isset($params['id_estacion']) && $params['id_estacion'] !== null ? (int)$params['id_estacion'] : null;
        $tipo       = (string)($params['tipo_incidencia'] ?? '');
        $alcance    = (string)($params['alcance_incidencia'] ?? 'WORKER');
        $fechaStart = (string)($params['fecha_inicio_local'] ?? '');
        $fechaEnd   = (string)($params['fecha_fin_local'] ?? '');
        $motivo     = $params['motivo'] ?? null;
        $folio      = $params['folio_evidencia'] ?? null;
        $creadoPor  = (int)($params['creado_por_usuario_id'] ?? 1);
        $aprobadoPor = isset($params['aprobado_por_usuario_id']) ? (int)$params['aprobado_por_usuario_id'] : null;

        if ($workerId <= 0 || empty($tipo) || empty($fechaStart) || empty($fechaEnd)) {
            throw new InvalidArgumentException("Missing required fields for incidence master creation.");
        }

        $validTypes = ['VACATION', 'DISABILITY', 'PERMISSION', 'AUTHORIZED_LATENESS', 'AUTHORIZED_EARLY_DEPARTURE', 'OFFICIAL_MISSION'];
        if (!in_array($tipo, $validTypes, true)) {
            throw new InvalidArgumentException("Invalid incidence type: $tipo");
        }

        $estadoActual   = (string)($params['estado_actual'] ?? 'ACTIVE');
        $estadoRevision = (string)($params['estado_revision'] ?? $estadoActual);

        $this->db->begin_transaction();
        try {
            $stmtM = $this->db->prepare("INSERT INTO op_rh_v2_incidencias_maestro 
                (uuid_incidencia, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, estado_actual, revision_actual) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmtM->bind_param("siisssss", $uuidIncid, $workerId, $stationId, $tipo, $alcance, $fechaStart, $fechaEnd, $estadoActual);
            $stmtM->execute();
            $masterId = (int)$stmtM->insert_id;
            $stmtM->close();

            $stmtR = $this->db->prepare("INSERT INTO op_rh_v2_incidencias_revisiones 
                (id_incidencia_maestro, revision_numero, estado_revision, motivo, folio_evidencia, creado_por_usuario_id, aprobado_por_usuario_id) 
                VALUES (?, 1, ?, ?, ?, ?, ?)");
            $stmtR->bind_param("isssii", $masterId, $estadoRevision, $motivo, $folio, $creadoPor, $aprobadoPor);
            $stmtR->execute();
            $revisionId = (int)$stmtR->insert_id;
            $stmtR->close();

            // Materialize daily applications for matching ScheduledShifts within inclusive local date range
            $appIds = [];
            if ($estadoRevision === 'ACTIVE') {
                $appIds = $this->applyIncidenceRevisionToShifts($masterId, $revisionId, $workerId, $stationId, $alcance, $fechaStart, $fechaEnd);
            }

            $this->db->commit();
            return [
                'id_incidencia_maestro' => $masterId,
                'id_incidencia_revision' => $revisionId,
                'uuid_incidencia' => $uuidIncid,
                'aplicaciones_ids' => $appIds
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Appends a new revision N+1 for an existing incidence master.
     */
    public function createIncidenceRevision(int $masterId, array $params): array {
        $expectedCurrentRev = isset($params['expected_current_revision']) ? (int)$params['expected_current_revision'] : null;
        $estadoRevision = (string)($params['estado_revision'] ?? 'ACTIVE');
        $estadoActual   = (string)($params['estado_actual'] ?? $estadoRevision);
        $motivo         = $params['motivo'] ?? null;
        $folio          = $params['folio_evidencia'] ?? null;
        $creadoPor      = (int)($params['creado_por_usuario_id'] ?? 1);
        $aprobadoPor    = isset($params['aprobado_por_usuario_id']) ? (int)$params['aprobado_por_usuario_id'] : null;

        $this->db->begin_transaction();
        try {
            $stmtM = $this->db->prepare("SELECT id, id_personal, id_estacion, tipo_incidencia, alcance_incidencia, fecha_inicio_local, fecha_fin_local, revision_actual FROM op_rh_v2_incidencias_maestro WHERE id = ? FOR UPDATE");
            $stmtM->bind_param("i", $masterId);
            $stmtM->execute();
            $master = $stmtM->get_result()->fetch_assoc();
            $stmtM->close();

            if (!$master) {
                throw new RuntimeException("Incidence master ID $masterId not found.");
            }

            if ($expectedCurrentRev !== null && $expectedCurrentRev !== (int)$master['revision_actual']) {
                throw new RuntimeException("STALE_REVISION_CONFLICT: Expected revision $expectedCurrentRev but current revision is " . $master['revision_actual']);
            }

            $newRevNum = (int)$master['revision_actual'] + 1;

            $stmtR = $this->db->prepare("INSERT INTO op_rh_v2_incidencias_revisiones 
                (id_incidencia_maestro, revision_numero, estado_revision, motivo, folio_evidencia, creado_por_usuario_id, aprobado_por_usuario_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtR->bind_param("iisssii", $masterId, $newRevNum, $estadoRevision, $motivo, $folio, $creadoPor, $aprobadoPor);
            $stmtR->execute();
            $revisionId = (int)$stmtR->insert_id;
            $stmtR->close();

            $fechaStart = !empty($params['fecha_inicio_local']) ? (string)$params['fecha_inicio_local'] : (string)$master['fecha_inicio_local'];
            $fechaEnd   = !empty($params['fecha_fin_local']) ? (string)$params['fecha_fin_local'] : (string)$master['fecha_fin_local'];

            $stmtUpM = $this->db->prepare("UPDATE op_rh_v2_incidencias_maestro SET revision_actual = ?, estado_actual = ?, fecha_inicio_local = ?, fecha_fin_local = ? WHERE id = ?");
            $stmtUpM->bind_param("isssi", $newRevNum, $estadoActual, $fechaStart, $fechaEnd, $masterId);
            $stmtUpM->execute();
            $stmtUpM->close();

            $appIds = [];
            if ($estadoRevision === 'ACTIVE') {
                $appIds = $this->applyIncidenceRevisionToShifts(
                    $masterId, $revisionId, (int)$master['id_personal'],
                    $master['id_estacion'] !== null ? (int)$master['id_estacion'] : null,
                    $master['alcance_incidencia'], $fechaStart, $fechaEnd
                );
            }

            $this->db->commit();
            return [
                'id_incidencia_maestro' => $masterId,
                'id_incidencia_revision' => $revisionId,
                'revision_numero' => $newRevNum,
                'estado_revision' => $estadoRevision,
                'aplicaciones_ids' => $appIds
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Creates or updates an administrative adjustment revision (append-only lineage).
     */
    public function createAdjustmentRevision(array $params): array {
        $jornadaId    = (int)($params['id_jornada_programada'] ?? 0);
        $calculoId    = (int)($params['id_calculo_jornada'] ?? 0);
        $tipo         = (string)($params['tipo_ajuste'] ?? '');
        $horaInUtc    = $params['hora_interpretada_entrada_utc'] ?? null;
        $horaOutUtc   = $params['hora_interpretada_salida_utc'] ?? null;
        $motivo       = (string)($params['motivo_ajuste'] ?? '');
        $creadoPor    = (int)($params['creado_por_usuario_id'] ?? 1);
        $aprobadoPor  = (int)($params['aprobado_por_usuario_id'] ?? 1);
        $priorId      = isset($params['id_ajuste_anterior']) ? (int)$params['id_ajuste_anterior'] : null;
        $estadoAjuste = (string)($params['estado_ajuste'] ?? 'ACTIVE');

        if ($jornadaId <= 0 || $calculoId <= 0 || empty($tipo) || empty($motivo)) {
            throw new InvalidArgumentException("Missing required parameter for administrative adjustment creation.");
        }

        $this->db->begin_transaction();
        try {
            $logUuid = null;
            $revNum = 1;

            if ($priorId !== null && $priorId > 0) {
                $stmtP = $this->db->prepare("SELECT uuid_ajuste_logico, revision_numero FROM op_rh_v2_ajustes_administrativos WHERE id = ? FOR UPDATE");
                $stmtP->bind_param("i", $priorId);
                $stmtP->execute();
                $priorRow = $stmtP->get_result()->fetch_assoc();
                $stmtP->close();

                if (!$priorRow) {
                    throw new RuntimeException("Prior adjustment ID $priorId not found.");
                }

                $logUuid = $priorRow['uuid_ajuste_logico'];
                $revNum  = (int)$priorRow['revision_numero'] + 1;

                // Transition prior revision status to SUPERSEDED
                $stmtSup = $this->db->prepare("UPDATE op_rh_v2_ajustes_administrativos SET estado_ajuste = 'SUPERSEDED' WHERE id = ?");
                $stmtSup->bind_param("i", $priorId);
                $stmtSup->execute();
                $stmtSup->close();
            } else {
                $logUuid = 'LOG_AJUSTE_' . uniqid('', true);
            }

            $physUuid = 'AJUSTE_' . uniqid('', true);

            $stmtIns = $this->db->prepare("INSERT INTO op_rh_v2_ajustes_administrativos 
                (uuid_ajuste, uuid_ajuste_logico, revision_numero, estado_ajuste, id_ajuste_anterior, id_jornada_programada, id_calculo_jornada, tipo_ajuste, hora_interpretada_entrada_utc, hora_interpretada_salida_utc, motivo_ajuste, creado_por_usuario_id, aprobado_por_usuario_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtIns->bind_param("ssisiisssssii", $physUuid, $logUuid, $revNum, $estadoAjuste, $priorId, $jornadaId, $calculoId, $tipo, $horaInUtc, $horaOutUtc, $motivo, $creadoPor, $aprobadoPor);
            $stmtIns->execute();
            $adjId = (int)$stmtIns->insert_id;
            $stmtIns->close();

            $this->db->commit();
            return [
                'id' => $adjId,
                'uuid_ajuste' => $physUuid,
                'uuid_ajuste_logico' => $logUuid,
                'revision_numero' => $revNum,
                'estado_ajuste' => $estadoAjuste,
                'id_ajuste_anterior' => $priorId
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Evaluates an explicit P2 Journey Calculation revision to derive an append-only Administrative Evaluation revision.
     *
     * @param int $calculationHeaderId Primary key ID of op_rh_v2_calculos_jornada.
     * @return array Persisted evaluation evaluation header + link arrays.
     */
    public function evaluateCalculationRevision(int $calculationHeaderId, array $options = []): array {
        if ($calculationHeaderId <= 0) {
            throw new InvalidArgumentException("calculationHeaderId must be a positive integer.");
        }

        $this->db->begin_transaction();
        try {
            // 1. Fetch explicit P2 Calculation Header
            $stmtC = $this->db->prepare("SELECT 
                id, id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local,
                revision_numero, version_algoritmo_calculo, estado_calculo, segundos_programados_totales, segundos_trabajados_totales,
                fingerprint_ejecucion
                FROM op_rh_v2_calculos_jornada
                WHERE id = ? FOR UPDATE");
            $stmtC->bind_param("i", $calculationHeaderId);
            $stmtC->execute();
            $p2 = $stmtC->get_result()->fetch_assoc();
            $stmtC->close();

            if (!$p2) {
                throw new RuntimeException("P2 calculation revision ID $calculationHeaderId not found.");
            }

            $jornadaId = (int)$p2['id_jornada_programada'];
            $p1Id      = (int)$p2['id_pareo_jornada'];
            $workerId  = (int)$p2['id_personal'];
            $stationId = (int)$p2['id_estacion'];
            $workDate  = (string)$p2['fecha_jornada_local'];

            // 2. Fetch P1 Pairing Header to verify Level 2 completeness & P1 status
            $stmtP1 = $this->db->prepare("SELECT estado_pareo, estado_completitud_l2 FROM op_rh_v2_pareos_jornada WHERE id = ?");
            $stmtP1->bind_param("i", $p1Id);
            $stmtP1->execute();
            $p1 = $stmtP1->get_result()->fetch_assoc();
            $stmtP1->close();

            // 3. Fetch ScheduledShift
            $stmtS = $this->db->prepare("SELECT id_estacion, tipo_jornada, scheduled_start_utc, scheduled_end_utc FROM op_rh_v2_jornadas_programadas WHERE id = ?");
            $stmtS->bind_param("i", $jornadaId);
            $stmtS->execute();
            $shift = $stmtS->get_result()->fetch_assoc();
            $stmtS->close();

            // 4. Resolve active applicable incidence applications for this shift
            $stmtApps = $this->db->prepare("SELECT 
                app.id AS id_aplicacion, rev.id_incidencia_maestro, m.tipo_incidencia
                FROM op_rh_v2_incidencias_aplicaciones app
                JOIN op_rh_v2_incidencias_revisiones rev ON app.id_incidencia_revision = rev.id
                JOIN op_rh_v2_incidencias_maestro m ON rev.id_incidencia_maestro = m.id
                WHERE app.id_jornada_programada = ? 
                  AND rev.estado_revision = 'ACTIVE' 
                  AND m.estado_actual = 'ACTIVE'
                ORDER BY app.id ASC");
            $stmtApps->bind_param("i", $jornadaId);
            $stmtApps->execute();
            $resApps = $stmtApps->get_result();
            $applicableApps = [];
            while ($row = $resApps->fetch_assoc()) {
                $applicableApps[] = $row;
            }
            $stmtApps->close();

            // 5. Resolve active applicable adjustments for this shift & P2 revision
            $stmtAdj = $this->db->prepare("SELECT id, tipo_ajuste, hora_interpretada_entrada_utc, hora_interpretada_salida_utc 
                FROM op_rh_v2_ajustes_administrativos 
                WHERE id_jornada_programada = ? AND id_calculo_jornada = ? AND estado_ajuste = 'ACTIVE'
                ORDER BY id ASC");
            $stmtAdj->bind_param("ii", $jornadaId, $calculationHeaderId);
            $stmtAdj->execute();
            $resAdj = $stmtAdj->get_result();
            $applicableAdjs = [];
            while ($row = $resAdj->fetch_assoc()) {
                $applicableAdjs[] = $row;
            }
            $stmtAdj->close();

            // 6. Compute canonical sorted ID sets
            $incAppIds = array_map(fn($item) => (int)$item['id_aplicacion'], $applicableApps);
            sort($incAppIds, SORT_NUMERIC);
            $adjIds = array_map(fn($item) => (int)$item['id'], $applicableAdjs);
            sort($adjIds, SORT_NUMERIC);

            $strInc = !empty($incAppIds) ? implode(',', $incAppIds) : 'NONE';
            $strAdj = !empty($adjIds) ? implode(',', $adjIds) : 'NONE';

            // SHA256 Fingerprint calculation
            $humanAuthorizedFalta = !empty($options['human_authorized_falta']);
            $fpSuffix = $humanAuthorizedFalta ? '_FALTA_AUTH' : '';
            $fpRaw = "{$jornadaId}_{$calculationHeaderId}_{$strInc}_{$strAdj}_{$this->algorithmVersion}_{$this->policyVersion}{$fpSuffix}";
            $fingerprint = hash('sha256', $fpRaw);

            // 7. Check idempotency: Return existing evaluation revision if fingerprint matches
            $stmtExist = $this->db->prepare("SELECT id, revision_numero, estado_evaluacion, estado_revision_humana FROM op_rh_v2_evaluaciones_administrativas WHERE fingerprint_ejecucion = ?");
            $stmtExist->bind_param("s", $fingerprint);
            $stmtExist->execute();
            $existing = $stmtExist->get_result()->fetch_assoc();
            $stmtExist->close();

            if ($existing) {
                $this->db->commit();
                return [
                    'id_evaluacion' => (int)$existing['id'],
                    'id_jornada_programada' => $jornadaId,
                    'id_calculo_jornada' => $calculationHeaderId,
                    'revision_numero' => (int)$existing['revision_numero'],
                    'estado_evaluacion' => $existing['estado_evaluacion'],
                    'estado_revision_humana' => $existing['estado_revision_humana'],
                    'fingerprint_ejecucion' => $fingerprint,
                    'is_existing' => true,
                    'incidencias_aplicaciones_ids' => $incAppIds,
                    'ajustes_ids' => $adjIds
                ];
            }

            // 8. Determine next monotonic revision number per ScheduledShift
            $stmtRev = $this->db->prepare("SELECT COALESCE(MAX(revision_numero), 0) + 1 AS next_rev FROM op_rh_v2_evaluaciones_administrativas WHERE id_jornada_programada = ?");
            $stmtRev->bind_param("i", $jornadaId);
            $stmtRev->execute();
            $nextRev = (int)$stmtRev->get_result()->fetch_assoc()['next_rev'];
            $stmtRev->close();

            // 9. Administrative Decision Rules Engine
            $evalState = 'NORMAL_COMPLETED';
            $humanReviewState = 'NOT_REQUIRED';

            $l2Status = $p1['estado_completitud_l2'] ?? 'VERIFIED';
            $p1Status = $p1['estado_pareo'] ?? '';
            $p2Status = $p2['estado_calculo'] ?? '';
            $shiftType = $shift['tipo_jornada'] ?? 'SCHEDULED';

            // Priority 1: Multi-incidence Overlap Conflict
            if (count($applicableApps) > 1) {
                $evalState = 'OVERLAP_CONFLICT';
                $humanReviewState = 'PENDING_REVIEW';
            }
            // Priority 2: Single Incidence Application Decision
            else if (count($applicableApps) === 1) {
                $incType = $applicableApps[0]['tipo_incidencia'];
                $workedSecs = $p2['segundos_trabajados_totales'];

                if (in_array($incType, ['VACATION', 'DISABILITY', 'PERMISSION'], true)) {
                    if ($workedSecs !== null && (int)$workedSecs > 0) {
                        // Work evidence present during approved absence -> Contradiction
                        $evalState = 'CONTRADICTION_DETECTED';
                        $humanReviewState = 'PENDING_REVIEW';
                    } else {
                        $evalState = $incType . '_APPLIED';
                    }
                } else if ($incType === 'AUTHORIZED_LATENESS' || $incType === 'AUTHORIZED_EARLY_DEPARTURE' || $incType === 'OFFICIAL_MISSION') {
                    $evalState = 'NORMAL_COMPLETED';
                }
            }
            // Priority 3: No Incidences -> Check boundary & non-scheduled cases
            else if (count($applicableApps) === 0) {
                if ($shiftType === 'DAY_OFF') {
                    $evalState = 'NORMAL_COMPLETED';
                } else if ($shiftType === 'UNSCHEDULED') {
                    $evalState = 'NORMAL_COMPLETED';
                } else if ($shiftType === 'UNKNOWN') {
                    $evalState = 'DATA_INCOMPLETE';
                    $humanReviewState = 'PENDING_REVIEW';
                } else if ($l2Status === 'PENDING_SYNC' || $p1Status === 'PENDING_SYNC_COMPLETION' || $p2Status === 'PENDING_SYNC') {
                    $evalState = 'REVIEW_REQUIRED';
                    $humanReviewState = 'PENDING_REVIEW';
                } else if ($p1Status === 'AMBIGUOUS_PAIRING' || $p1Status === 'WORKPLACE_MISMATCH' || $p2Status === 'PARTIAL_DATA') {
                    $evalState = 'REVIEW_REQUIRED';
                    $humanReviewState = 'PENDING_REVIEW';
                } else if ($p1Status === 'UNMATCHED_NO_PUNCHES' && $p2Status === 'UNMATCHED_NO_PUNCHES') {
                    $enforceP3gGate = !empty($options['enforce_p3g_human_falta_gate']);
                    if ($l2Status === 'VERIFIED' && empty($applicableAdjs) && (!$enforceP3gGate || $humanAuthorizedFalta)) {
                        // Strict FALTA Prerequisites Satisfied & explicitly authorized by human if gate enforced
                        $evalState = 'FALTA';
                        $humanReviewState = 'NOT_REQUIRED';
                    } else {
                        $evalState = $enforceP3gGate ? 'REVIEW_REQUIRED' : 'ADMINISTRATIVE_SIN_MARCACION';
                        $humanReviewState = 'PENDING_REVIEW';
                    }
                }
            }

            // 10. Persist Evaluation Header
            $stmtInsH = $this->db->prepare("INSERT INTO op_rh_v2_evaluaciones_administrativas 
                (id_jornada_programada, id_calculo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_admin, version_politica, estado_evaluacion, estado_revision_humana, fingerprint_ejecucion) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtInsH->bind_param("iiiisssssss", $jornadaId, $calculationHeaderId, $workerId, $stationId, $workDate, $nextRev, $this->algorithmVersion, $this->policyVersion, $evalState, $humanReviewState, $fingerprint);
            $stmtInsH->execute();
            $evalId = (int)$stmtInsH->insert_id;
            $stmtInsH->close();

            // 11. Persist Exact Incidence Link Rows
            if (!empty($incAppIds)) {
                $stmtLinkInc = $this->db->prepare("INSERT INTO op_rh_v2_evaluaciones_incidencias (id_evaluacion_administrativa, id_incidencia_aplicacion) VALUES (?, ?)");
                foreach ($incAppIds as $appId) {
                    $stmtLinkInc->bind_param("ii", $evalId, $appId);
                    $stmtLinkInc->execute();
                }
                $stmtLinkInc->close();
            }

            // 12. Persist Exact Adjustment Link Rows
            if (!empty($adjIds)) {
                $stmtLinkAdj = $this->db->prepare("INSERT INTO op_rh_v2_evaluaciones_ajustes (id_evaluacion_administrativa, id_ajuste_administrativo) VALUES (?, ?)");
                foreach ($adjIds as $adjId) {
                    $stmtLinkAdj->bind_param("ii", $evalId, $adjId);
                    $stmtLinkAdj->execute();
                }
                $stmtLinkAdj->close();
            }

            $this->db->commit();
            return [
                'id_evaluacion' => $evalId,
                'id_jornada_programada' => $jornadaId,
                'id_calculo_jornada' => $calculationHeaderId,
                'revision_numero' => $nextRev,
                'estado_evaluacion' => $evalState,
                'estado_revision_humana' => $humanReviewState,
                'fingerprint_ejecucion' => $fingerprint,
                'is_existing' => false,
                'incidencias_aplicaciones_ids' => $incAppIds,
                'ajustes_ids' => $adjIds
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Helper to apply an incidence revision to matching ScheduledShifts in date range.
     */
    private function applyIncidenceRevisionToShifts(
        int $masterId, int $revisionId, int $workerId, ?int $stationId,
        string $alcance, string $fechaStart, string $fechaEnd
    ): array {
        $sql = "SELECT id, fecha_jornada_local FROM op_rh_v2_jornadas_programadas 
                WHERE id_personal = ? 
                  AND fecha_jornada_local >= ? 
                  AND fecha_jornada_local <= ?";
        $types = "iss";
        $binds = [$workerId, $fechaStart, $fechaEnd];

        if ($alcance === 'WORKPLACE' && $stationId !== null) {
            $sql .= " AND id_estacion = ?";
            $types .= "i";
            $binds[] = $stationId;
        }

        $stmtS = $this->db->prepare($sql);
        $stmtS->bind_param($types, ...$binds);
        $stmtS->execute();
        $res = $stmtS->get_result();

        $appIds = [];
        $stmtApp = $this->db->prepare("INSERT INTO op_rh_v2_incidencias_aplicaciones (id_incidencia_revision, id_jornada_programada, fecha_jornada_local, estado_aplicacion) VALUES (?, ?, ?, 'APPLIED')");
        while ($shift = $res->fetch_assoc()) {
            $sId = (int)$shift['id'];
            $fLocal = (string)$shift['fecha_jornada_local'];
            $stmtApp->bind_param("iis", $revisionId, $sId, $fLocal);
            $stmtApp->execute();
            $appIds[] = (int)$stmtApp->insert_id;
        }
        $stmtApp->close();
        $stmtS->close();

        return $appIds;
    }

    /**
     * Resolves the authoritative P2 calculation revision for a given worker, workplace, and workday,
     * verifies revision concurrency constraints, and executes administrative evaluation.
     *
     * @param int $workerId Canonical op_rh_personal.id
     * @param int $workplaceId Canonical op_rh_localidades.id
     * @param string $workDate Calendar date YYYY-MM-DD
     * @param array $options Execution options:
     *                       - 'expected_calculation_revision_id' (optional int)
     *                       - 'calculation_revision_id' (optional int)
     *                       - 'human_authorized_falta' (optional bool)
     *                       - 'enforce_p3g_human_falta_gate' (optional bool)
     * @return array Evaluation details matching Contract Section 9.11
     * @throws InvalidArgumentException On invalid inputs
     * @throws RuntimeException On calculation missing (404) or stale revision conflict (409)
     */
    public function evaluateShiftAdministration(int $workerId, int $workplaceId, string $workDate, array $options = []): array {
        if ($workerId <= 0 || $workplaceId <= 0 || empty($workDate)) {
            throw new InvalidArgumentException("Missing or invalid worker_id, workplace_id, or work_date.");
        }

        // Query active/latest P2 calculation revision for worker + workplace + work_date
        $stmtC = $this->db->prepare("SELECT id, id_jornada_programada, revision_numero
            FROM op_rh_v2_calculos_jornada
            WHERE id_personal = ? AND id_estacion = ? AND fecha_jornada_local = ?
            ORDER BY revision_numero DESC, id DESC LIMIT 1");
        if (!$stmtC) {
            throw new RuntimeException("Database error preparing calculation lookup.");
        }
        $stmtC->bind_param("iis", $workerId, $workplaceId, $workDate);
        $stmtC->execute();
        $calc = $stmtC->get_result()->fetch_assoc();
        $stmtC->close();

        if (!$calc) {
            throw new RuntimeException("CALCULATION_NOT_FOUND: No calculation revision found for worker {$workerId}, workplace {$workplaceId}, date {$workDate}.");
        }

        $latestCalcId = (int)$calc['id'];
        $expectedCalcId = isset($options['expected_calculation_revision_id'])
            ? (int)$options['expected_calculation_revision_id']
            : (isset($options['calculation_revision_id']) ? (int)$options['calculation_revision_id'] : null);

        if ($expectedCalcId !== null && $expectedCalcId !== $latestCalcId) {
            throw new RuntimeException("STALE_REVISION_CONFLICT: Expected calculation revision ID {$expectedCalcId} does not match active calculation revision ID {$latestCalcId}.");
        }

        return $this->evaluateCalculationRevision($latestCalcId, $options);
    }
}
