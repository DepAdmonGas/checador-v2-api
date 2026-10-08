<?php
/**
 * Checador V2 — Parallel Comparison Service (V2.6 Domain Runtime Engine)
 *
 * Consumes explicit immutable V2.5 administrative evaluation revisions,
 * reads legacy attendance & incidence reference tables in read-only mode,
 * captures immutable legacy snapshots, performs deterministic parallel comparison
 * under strict "comparación paralela sin afectar nómina" rules, and persists
 * append-only comparison revisions and discrepancy detail evidence logs.
 *
 * Spec Reference:     docs/v2/CHECADOR_V2_LEGACY_PAYROLL_PARALLEL_COMPARISON_ADAPTER_CONTRACT_V1.0.md (FROZEN_V1.0)
 * Physical Model:     docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md (FROZEN_V1.0)
 * Addendum Spec:      docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_ADDENDUM_V1.1.md (FROZEN_V1.1)
 */

declare(strict_types=1);

namespace modelo\v2\journey;

use mysqli;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

class ParallelComparisonService {

    private mysqli $db;
    private string $algorithmVersion;
    private string $policyVersion;

    public function __construct(
        mysqli $db,
        string $algorithmVersion = 'V1.0',
        string $policyVersion = 'V1.0'
    ) {
        $this->db = $db;
        $this->algorithmVersion = $algorithmVersion;
        $this->policyVersion = $policyVersion;
    }

    /**
     * Executes a parallel comparison for an explicit V2.5 Administrative Evaluation revision ID.
     *
     * @param int $evaluationId Explicit PK of op_rh_v2_evaluaciones_administrativas
     * @return array Output payload containing comparison header ID, revision number, category, idempotency status, etc.
     */
    public function compareAdministrativeEvaluation(int $evaluationId): array {
        if ($evaluationId <= 0) {
            throw new InvalidArgumentException("Invalid evaluation ID: $evaluationId");
        }

        // 1. Fetch target V2.5 Administrative Evaluation revision
        $eval = $this->fetchAdministrativeEvaluation($evaluationId);
        if ($eval === null) {
            throw new RuntimeException("Administrative evaluation ID $evaluationId not found.");
        }

        $workerId  = (int)$eval['id_personal'];
        $stationId = (int)$eval['id_estacion'];
        $workDate  = (string)$eval['fecha_jornada_local'];
        $evalState = (string)$eval['estado_evaluacion'];

        // 2. Read legacy attendance source record (op_rh_personal_asistencia) in READ-ONLY mode
        $legacyRow = $this->readLegacyAttendanceRow($workerId, $workDate);

        // 3. Compute legacy attendance snapshot hash & presence state
        if ($legacyRow !== null) {
            $legacyPresenceState = 'OBSERVED';
            $legacyAttendanceSnapshotHash = hash('sha256', implode('|', [
                (string)$legacyRow['id'],
                (string)$legacyRow['id_estacion'],
                (string)$legacyRow['id_personal'],
                (string)$legacyRow['fecha'],
                (string)$legacyRow['hora_entrada'],
                (string)$legacyRow['hora_salida'],
                (string)$legacyRow['hora_entrada_sensor'],
                (string)$legacyRow['hora_salida_sensor'],
                (string)$legacyRow['retardo_minutos'],
                (string)$legacyRow['incidencia_dias'],
                (string)$legacyRow['incidencia'],
                (string)$legacyRow['sd']
            ]));

            // Read legacy incidence set rows (op_rh_personal_asistencia_incidencia)
            $legacyIncidenceRows = $this->readLegacyIncidenceRows((int)$legacyRow['id']);
        } else {
            $legacyPresenceState = 'MISSING_RECORD';
            $legacyAttendanceSnapshotHash = hash('sha256', 'MISSING_LEGACY_ATTENDANCE_RECORD');
            $legacyIncidenceRows = [];
        }

        // 4. Compute legacy incidence snapshot hashes & sort alphabetically ascending
        $sortedLegacyIncidenceSnapshotHashes = [];
        $preparedIncidenceSnapshots = [];
        foreach ($legacyIncidenceRows as $idx => $incRow) {
            $rowHash = hash('sha256', implode('|', [
                (string)$incRow['id'],
                (string)$incRow['fecha'],
                (string)$incRow['incidencia'],
                (string)$incRow['comentario'],
                (string)$incRow['documento'],
                (string)$incRow['estado']
            ]));
            $sortedLegacyIncidenceSnapshotHashes[] = $rowHash;
            $preparedIncidenceSnapshots[] = [
                'id_legacy' => (int)$incRow['id'],
                'fecha'     => (string)$incRow['fecha'],
                'texto'     => (string)$incRow['incidencia'],
                'comentario'=> (string)$incRow['comentario'],
                'documento' => (string)$incRow['documento'],
                'estado'    => (int)$incRow['estado'],
                'row_hash'  => $rowHash
            ];
        }
        sort($sortedLegacyIncidenceSnapshotHashes, SORT_STRING);

        // 5. Compute deterministic V1.1 execution fingerprint
        $incidenceHashString = empty($sortedLegacyIncidenceSnapshotHashes)
            ? 'NO_INCIDENCES'
            : implode('|', $sortedLegacyIncidenceSnapshotHashes);

        $fingerprintInput = implode(':', [
            (string)$evaluationId,
            $legacyPresenceState,
            $legacyAttendanceSnapshotHash,
            $incidenceHashString,
            $this->algorithmVersion,
            $this->policyVersion
        ]);

        $fingerprintEjecucion = hash('sha256', $fingerprintInput);

        // 6. Check idempotency: Return existing comparison if fingerprint matches
        $existingHeader = $this->fetchHeaderByFingerprint($fingerprintEjecucion);
        if ($existingHeader !== null) {
            return [
                'id'                           => (int)$existingHeader['id'],
                'id_evaluacion_administrativa' => (int)$existingHeader['id_evaluacion_administrativa'],
                'revision_numero'              => (int)$existingHeader['revision_numero'],
                'categoria_resultado'          => (string)$existingHeader['categoria_resultado'],
                'requiere_revision'            => (int)$existingHeader['requiere_revision'],
                'estado_presencia_legacy'      => (string)$existingHeader['estado_presencia_legacy'],
                'fingerprint_ejecucion'        => (string)$existingHeader['fingerprint_ejecucion'],
                'is_existing'                  => true
            ];
        }

        // 7. Perform deterministic comparison evaluation logic
        $comparisonResult = $this->evaluateComparison(
            $eval,
            $legacyRow,
            $legacyIncidenceRows,
            $legacyPresenceState
        );

        $category         = $comparisonResult['categoria_resultado'];
        $requiresReview   = $comparisonResult['requiere_revision'];
        $discrepancyRows  = $comparisonResult['discrepancias'];

        // 8. Atomic database transaction to persist comparison header & child snapshots
        $this->db->begin_transaction();
        try {
            // Allocate monotonic revision_numero per evaluationId
            $nextRevision = $this->getNextRevisionNumber($evaluationId);

            // Insert Header
            $stmtH = $this->db->prepare("
                INSERT INTO op_rh_v2_comparaciones_encabezado (
                    id_evaluacion_administrativa,
                    id_personal,
                    id_estacion,
                    fecha_jornada_local,
                    revision_numero,
                    categoria_resultado,
                    requiere_revision,
                    estado_presencia_legacy,
                    version_algoritmo_comparacion,
                    version_politica_comparacion,
                    fingerprint_ejecucion
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtH->bind_param(
                'iiisiisssss',
                $evaluationId,
                $workerId,
                $stationId,
                $workDate,
                $nextRevision,
                $category,
                $requiresReview,
                $legacyPresenceState,
                $this->algorithmVersion,
                $this->policyVersion,
                $fingerprintEjecucion
            );
            $stmtH->execute();
            $headerId = (int)$stmtH->insert_id;

            // Insert Legacy Attendance Snapshot Row
            $stmtSnap = $this->db->prepare("
                INSERT INTO op_rh_v2_comparaciones_legacy_capturas (
                    id_comparacion_encabezado,
                    id_personal_asistencia_legacy,
                    id_estacion_legacy,
                    id_personal_legacy,
                    fecha_legacy,
                    hora_entrada_legacy,
                    hora_salida_legacy,
                    hora_entrada_sensor_legacy,
                    hora_salida_sensor_legacy,
                    retardo_minutos_legacy,
                    incidencia_dias_legacy,
                    incidencia_legacy,
                    sd_legacy,
                    snapshot_hash
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if ($legacyRow !== null) {
                $legId      = (int)$legacyRow['id'];
                $legEst     = (int)$legacyRow['id_estacion'];
                $legPers    = (int)$legacyRow['id_personal'];
                $legFecha   = (string)$legacyRow['fecha'];
                $legEnt     = (string)$legacyRow['hora_entrada'];
                $legSal     = (string)$legacyRow['hora_salida'];
                $legEntSens = (string)$legacyRow['hora_entrada_sensor'];
                $legSalSens = (string)$legacyRow['hora_salida_sensor'];
                $legRet     = (int)$legacyRow['retardo_minutos'];
                $legIncDias = (int)$legacyRow['incidencia_dias'];
                $legIncVal  = (float)$legacyRow['incidencia'];
                $legSd      = (float)$legacyRow['sd'];

                $stmtSnap->bind_param(
                    'iiiisssssiidds',
                    $headerId,
                    $legId,
                    $legEst,
                    $legPers,
                    $legFecha,
                    $legEnt,
                    $legSal,
                    $legEntSens,
                    $legSalSens,
                    $legRet,
                    $legIncDias,
                    $legIncVal,
                    $legSd,
                    $legacyAttendanceSnapshotHash
                );
            } else {
                $nullInt   = null;
                $nullStr   = null;
                $nullFloat = null;

                $stmtSnap->bind_param(
                    'iiiisssssiidds',
                    $headerId,
                    $nullInt,
                    $nullInt,
                    $nullInt,
                    $nullStr,
                    $nullStr,
                    $nullStr,
                    $nullStr,
                    $nullStr,
                    $nullInt,
                    $nullInt,
                    $nullFloat,
                    $nullFloat,
                    $legacyAttendanceSnapshotHash
                );
            }
            $stmtSnap->execute();

            // Insert Legacy Incidence Snapshot Set
            if (!empty($preparedIncidenceSnapshots)) {
                $stmtInc = $this->db->prepare("
                    INSERT INTO op_rh_v2_comparaciones_legacy_incidencias (
                        id_comparacion_encabezado,
                        id_asistencia_incidencia_legacy,
                        fecha_incidencia_legacy,
                        incidencia_texto_legacy,
                        comentario_legacy,
                        documento_legacy,
                        estado_legacy,
                        orden_secuencia,
                        row_hash
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($preparedIncidenceSnapshots as $seqIdx => $pInc) {
                    $seqNo = $seqIdx + 1;
                    $stmtInc->bind_param(
                        'iissssiis',
                        $headerId,
                        $pInc['id_legacy'],
                        $pInc['fecha'],
                        $pInc['texto'],
                        $pInc['comentario'],
                        $pInc['documento'],
                        $pInc['estado'],
                        $seqNo,
                        $pInc['row_hash']
                    );
                    $stmtInc->execute();
                }
            }

            // Insert Discrepancy Details Log
            if (!empty($discrepancyRows)) {
                $stmtDisc = $this->db->prepare("
                    INSERT INTO op_rh_v2_comparaciones_discrepancias (
                        id_comparacion_encabezado,
                        categoria_discrepancia,
                        campo_evaluado,
                        valor_v2,
                        valor_legacy,
                        descripcion_diferencia,
                        orden_secuencia
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($discrepancyRows as $seqIdx => $disc) {
                    $seqNo = $seqIdx + 1;
                    $cat   = (string)$disc['categoria'];
                    $campo = (string)$disc['campo'];
                    $v2Val = $disc['valor_v2'] !== null ? (string)$disc['valor_v2'] : null;
                    $legVal= $disc['valor_legacy'] !== null ? (string)$disc['valor_legacy'] : null;
                    $desc  = $disc['descripcion'] !== null ? (string)$disc['descripcion'] : null;

                    $stmtDisc->bind_param(
                        'isssssi',
                        $headerId,
                        $cat,
                        $campo,
                        $v2Val,
                        $legVal,
                        $desc,
                        $seqNo
                    );
                    $stmtDisc->execute();
                }
            }

            $this->db->commit();

            return [
                'id'                           => $headerId,
                'id_evaluacion_administrativa' => $evaluationId,
                'revision_numero'              => $nextRevision,
                'categoria_resultado'          => $category,
                'requiere_revision'            => $requiresReview,
                'estado_presencia_legacy'      => $legacyPresenceState,
                'fingerprint_ejecucion'        => $fingerprintEjecucion,
                'is_existing'                  => false
            ];

        } catch (Throwable $e) {
            $this->db->rollback();
            throw new RuntimeException("Parallel comparison persistence failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Evaluates V2.5 evaluation against legacy attendance record deterministically.
     */
    private function evaluateComparison(
        array $eval,
        ?array $legacyRow,
        array $legacyIncidences,
        string $legacyPresenceState
    ): array {
        $evalState = (string)$eval['estado_evaluacion'];
        $discrepancies = [];

        // 1. Fail-closed handling for invalid/incomplete upstream states
        if ($evalState === 'DATA_INCOMPLETE') {
            return [
                'categoria_resultado' => 'NOT_COMPARABLE',
                'requiere_revision'   => 1,
                'discrepancias'       => [[
                    'categoria'  => 'NOT_COMPARABLE',
                    'campo'      => 'estado_evaluacion',
                    'valor_v2'   => 'DATA_INCOMPLETE',
                    'valor_legacy' => $legacyRow !== null ? 'OBSERVED' : 'MISSING',
                    'descripcion'=> 'Level 2 ingestion or windowing is incomplete (LEVEL2_INCOMPLETE)'
                ]]
            ];
        }

        if ($evalState === 'CONTRADICTION_DETECTED' || $evalState === 'OVERLAP_CONFLICT' || $eval['estado_revision_humana'] === 'PENDING_REVIEW') {
            return [
                'categoria_resultado' => 'REVIEW_REQUIRED',
                'requiere_revision'   => 1,
                'discrepancias'       => [[
                    'categoria'  => 'REVIEW_REQUIRED',
                    'campo'      => 'estado_evaluacion',
                    'valor_v2'   => $evalState,
                    'valor_legacy' => $legacyRow !== null ? 'OBSERVED' : 'MISSING',
                    'descripcion'=> 'Human review required on upstream V2.5 evaluation'
                ]]
            ];
        }

        // 2. Absence of Legacy Record
        if ($legacyPresenceState === 'MISSING_RECORD') {
            return [
                'categoria_resultado' => 'MISSING_LEGACY_RECORD',
                'requiere_revision'   => 1,
                'discrepancias'       => [[
                    'categoria'  => 'MISSING_LEGACY_RECORD',
                    'campo'      => 'estado_presencia_legacy',
                    'valor_v2'   => $evalState,
                    'valor_legacy' => 'MISSING_RECORD',
                    'descripcion'=> 'No legacy attendance record exists for worker and workplace date'
                ]]
            ];
        }

        // 3. Absence / FALTA Comparison
        if ($evalState === 'FALTA') {
            $legacyIncidenceVal = (float)($legacyRow['incidencia'] ?? 0);
            $legacyIncidenceDays = (int)($legacyRow['incidencia_dias'] ?? 0);
            $legacySensorIn = (string)($legacyRow['hora_entrada_sensor'] ?? '');
            $legacySensorOut = (string)($legacyRow['hora_salida_sensor'] ?? '');

            $hasLegacyPunches = (!empty($legacySensorIn) && $legacySensorIn !== '00:00:00') || (!empty($legacySensorOut) && $legacySensorOut !== '00:00:00');
            $hasLegacyIncidence = ($legacyIncidenceVal > 0 || $legacyIncidenceDays > 0 || !empty($legacyIncidences));

            if ($hasLegacyIncidence) {
                $discrepancies[] = [
                    'categoria'   => 'INCIDENT_DIFFERENCE',
                    'campo'       => 'estado_presencia',
                    'valor_v2'    => 'FALTA',
                    'valor_legacy'=> 'INCIDENCIA_REGISTRADA',
                    'descripcion' => 'V2.5 evaluates FALTA while legacy records an incidence/justification'
                ];
                return [
                    'categoria_resultado' => 'INCIDENT_DIFFERENCE',
                    'requiere_revision'   => 1,
                    'discrepancias'       => $discrepancies
                ];
            }

            if ($hasLegacyPunches) {
                $discrepancies[] = [
                    'categoria'   => 'PUNCH_DIFFERENCE',
                    'campo'       => 'estado_presencia',
                    'valor_v2'    => 'FALTA',
                    'valor_legacy'=> "ENTRADA:{$legacySensorIn}|SALIDA:{$legacySensorOut}",
                    'descripcion' => 'V2.5 evaluates FALTA while legacy contains sensor punch timestamps'
                ];
                return [
                    'categoria_resultado' => 'PUNCH_DIFFERENCE',
                    'requiere_revision'   => 1,
                    'discrepancias'       => $discrepancies
                ];
            }

            // Both V2.5 and Legacy conclude absence without punches
            return [
                'categoria_resultado' => 'MATCH',
                'requiere_revision'   => 0,
                'discrepancias'       => []
            ];
        }

        // 4. Approved Incidences Comparison (VACATION, DISABILITY, PERMISSION)
        if (strpos($evalState, '_APPLIED') !== false) {
            if (empty($legacyIncidences) && (float)$legacyRow['incidencia'] == 0 && (int)$legacyRow['incidencia_dias'] == 0) {
                $discrepancies[] = [
                    'categoria'   => 'INCIDENT_DIFFERENCE',
                    'campo'       => 'incidencia',
                    'valor_v2'    => $evalState,
                    'valor_legacy'=> 'SIN_INCIDENCIA',
                    'descripcion' => "V2.5 evaluates $evalState while legacy records no incidence"
                ];
                return [
                    'categoria_resultado' => 'INCIDENT_DIFFERENCE',
                    'requiere_revision'   => 1,
                    'discrepancias'       => $discrepancies
                ];
            }

            return [
                'categoria_resultado' => 'MATCH',
                'requiere_revision'   => 0,
                'discrepancias'       => []
            ];
        }

        // 5. Normal Worked Shift & Sensor Time Deltas
        if ($evalState === 'NORMAL_COMPLETED') {
            $legacySensorIn  = (string)($legacyRow['hora_entrada_sensor'] ?? '');
            $legacySensorOut = (string)($legacyRow['hora_salida_sensor'] ?? '');
            $legacySchedIn   = (string)($legacyRow['hora_entrada'] ?? '');
            $legacySchedOut  = (string)($legacyRow['hora_salida'] ?? '');

            // Fetch P2 calculation sensor entry/exit if available
            $p2Calc = $this->fetchP2Calculation((int)$eval['id_calculo_jornada']);
            $v2Ent  = $p2Calc['hora_entrada_sensor'] ?? $legacySchedIn;
            $v2Sal  = $p2Calc['hora_salida_sensor'] ?? $legacySchedOut;

            if ($v2Ent !== null && !empty($legacySensorIn) && $legacySensorIn !== '00:00:00') {
                $deltaSec = abs(strtotime("1970-01-01 $v2Ent") - strtotime("1970-01-01 $legacySensorIn"));
                if ($deltaSec > 60) {
                    $discrepancies[] = [
                        'categoria'   => 'PUNCH_DIFFERENCE',
                        'campo'       => 'hora_entrada_sensor',
                        'valor_v2'    => $v2Ent,
                        'valor_legacy'=> $legacySensorIn,
                        'descripcion' => "Entry sensor delta exceeds 60s threshold ({$deltaSec}s)"
                    ];
                }
            }

            if ($v2Sal !== null && !empty($legacySensorOut) && $legacySensorOut !== '00:00:00') {
                $deltaSec = abs(strtotime("1970-01-01 $v2Sal") - strtotime("1970-01-01 $legacySensorOut"));
                if ($deltaSec > 60) {
                    $discrepancies[] = [
                        'categoria'   => 'PUNCH_DIFFERENCE',
                        'campo'       => 'hora_salida_sensor',
                        'valor_v2'    => $v2Sal,
                        'valor_legacy'=> $legacySensorOut,
                        'descripcion' => "Exit sensor delta exceeds 60s threshold ({$deltaSec}s)"
                    ];
                }
            }

            if (!empty($discrepancies)) {
                return [
                    'categoria_resultado' => 'PUNCH_DIFFERENCE',
                    'requiere_revision'   => 1,
                    'discrepancias'       => $discrepancies
                ];
            }

            return [
                'categoria_resultado' => 'MATCH',
                'requiere_revision'   => 0,
                'discrepancias'       => []
            ];
        }

        // Default fallback for ambiguous or unmapped states
        return [
            'categoria_resultado' => 'NOT_COMPARABLE',
            'requiere_revision'   => 1,
            'discrepancias'       => [[
                'categoria'  => 'NOT_COMPARABLE',
                'campo'      => 'estado_evaluacion',
                'valor_v2'   => $evalState,
                'valor_legacy' => $legacyRow !== null ? 'OBSERVED' : 'MISSING',
                'descripcion'=> 'Evaluation state not comparable under standard rules'
            ]]
        ];
    }

    /**
     * Reads legacy attendance row from op_rh_personal_asistencia in READ-ONLY mode.
     */
    private function readLegacyAttendanceRow(int $workerId, string $workDate): ?array {
        $stmt = $this->db->prepare("
            SELECT id, id_estacion, id_personal, fecha, hora_entrada, hora_salida,
                   hora_entrada_sensor, hora_salida_sensor, retardo_minutos, incidencia_dias,
                   incidencia, sd
            FROM op_rh_personal_asistencia
            WHERE id_personal = ? AND fecha = ?
            LIMIT 1
        ");
        $stmt->bind_param('is', $workerId, $workDate);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res ? $res->fetch_assoc() : null;
    }

    /**
     * Reads legacy incidence rows from op_rh_personal_asistencia_incidencia in READ-ONLY mode.
     */
    private function readLegacyIncidenceRows(int $legacyAsistenciaId): array {
        $stmt = $this->db->prepare("
            SELECT id, id_asistencia, fecha, incidencia, comentario, documento, estado
            FROM op_rh_personal_asistencia_incidencia
            WHERE id_asistencia = ?
            ORDER BY id ASC
        ");
        $stmt->bind_param('i', $legacyAsistenciaId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Fetches V2.5 Administrative Evaluation revision.
     */
    private function fetchAdministrativeEvaluation(int $evalId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, id_jornada_programada, id_calculo_jornada, id_personal, id_estacion,
                   fecha_jornada_local, revision_numero, version_algoritmo_admin, version_politica,
                   estado_evaluacion, estado_revision_humana, fingerprint_ejecucion
            FROM op_rh_v2_evaluaciones_administrativas
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $evalId);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res ? $res->fetch_assoc() : null;
    }

    /**
     * Fetches P2 Calculation revision detail.
     */
    private function fetchP2Calculation(int $calcId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, id_jornada_programada, id_pareo_jornada, id_personal, id_estacion,
                   fecha_jornada_local, estado_calculo
            FROM op_rh_v2_calculos_jornada
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $calcId);
        $stmt->execute();
        $res = $stmt->get_result();
        $calc = $res ? $res->fetch_assoc() : null;
        if ($calc === null) {
            return null;
        }

        // Check if segment punches exist in P1 pareos
        $stmtP1 = $this->db->prepare("
            SELECT m_in.ocurrido_at_utc AS in_time, m_out.ocurrido_at_utc AS out_time
            FROM op_rh_v2_pareos_segmentos ps
            LEFT JOIN op_rh_v2_marcaciones_crudas m_in ON ps.id_marcacion_entrada = m_in.id
            LEFT JOIN op_rh_v2_marcaciones_crudas m_out ON ps.id_marcacion_salida = m_out.id
            WHERE ps.id_pareo_jornada = ?
            ORDER BY ps.ordinal_segmento ASC
            LIMIT 1
        ");
        $stmtP1->bind_param('i', $calc['id_pareo_jornada']);
        $stmtP1->execute();
        $resP1 = $stmtP1->get_result();
        if ($rowP1 = $resP1->fetch_assoc()) {
            $calc['hora_entrada_sensor'] = isset($rowP1['in_time']) ? substr((string)$rowP1['in_time'], 11, 8) : null;
            $calc['hora_salida_sensor']  = isset($rowP1['out_time']) ? substr((string)$rowP1['out_time'], 11, 8) : null;
        }

        return $calc;
    }

    /**
     * Fetches existing header by fingerprint for idempotency check.
     */
    private function fetchHeaderByFingerprint(string $fingerprint): ?array {
        $stmt = $this->db->prepare("
            SELECT id, id_evaluacion_administrativa, id_personal, id_estacion, fecha_jornada_local,
                   revision_numero, categoria_resultado, requiere_revision, estado_presencia_legacy,
                   fingerprint_ejecucion
            FROM op_rh_v2_comparaciones_encabezado
            WHERE fingerprint_ejecucion = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $fingerprint);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res ? $res->fetch_assoc() : null;
    }

    /**
     * Gets the next monotonic revision number for a given V2.5 administrative evaluation ID.
     */
    private function getNextRevisionNumber(int $evaluationId): int {
        $stmt = $this->db->prepare("
            SELECT MAX(revision_numero) AS max_rev
            FROM op_rh_v2_comparaciones_encabezado
            WHERE id_evaluacion_administrativa = ?
        ");
        $stmt->bind_param('i', $evaluationId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $maxRev = $row['max_rev'] !== null ? (int)$row['max_rev'] : 0;
        return $maxRev + 1;
    }
}
