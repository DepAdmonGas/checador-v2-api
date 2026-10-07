<?php
/**
 * Checador V2 — Journey Calculation Service (P2 Domain Runtime)
 *
 * Consumes explicit immutable P1 pairing revisions and materialized ScheduledShifts
 * to calculate technical attendance duration metrics and signed timestamp deltas.
 *
 * Spec Reference: docs/v2/CHECADOR_V2_JOURNEY_CALCULATION_CONTRACT_V1.0.md
 * Physical Spec:  docs/architecture/CHECADOR_V2_JOURNEY_CALCULATION_PHYSICAL_MODEL_V1.0.md
 */

declare(strict_types=1);

namespace modelo\v2\journey;

use mysqli;
use RuntimeException;
use InvalidArgumentException;
use Throwable;

class JourneyCalculationService {

    private mysqli $db;
    private string $algorithmVersion;

    public function __construct(mysqli $db, string $algorithmVersion = 'v2.4-p2') {
        $this->db = $db;
        $this->algorithmVersion = $algorithmVersion;
    }

    /**
     * Calculates technical attendance metrics for an explicit immutable P1 pairing revision.
     *
     * @param int $pairingHeaderId Primary key ID of op_rh_v2_pareos_jornada revision.
     * @return array Persisted calculation result array.
     */
    public function calculatePairingRevision(int $pairingHeaderId): array {
        if ($pairingHeaderId <= 0) {
            throw new InvalidArgumentException("pairingHeaderId must be a positive integer.");
        }

        // 1. Fetch explicit P1 Pairing Header
        $stmtP1 = $this->db->prepare("SELECT 
            id, id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero,
            version_algoritmo, estado_completitud_l2, estado_pareo, fingerprint_ejecucion
            FROM op_rh_v2_pareos_jornada
            WHERE id = ?");
        $stmtP1->bind_param("i", $pairingHeaderId);
        $stmtP1->execute();
        $resP1 = $stmtP1->get_result();
        $p1Header = $resP1->fetch_assoc();
        $stmtP1->close();

        if (!$p1Header) {
            throw new RuntimeException("P1 pairing revision ID $pairingHeaderId not found.");
        }

        $jornadaId = (int)$p1Header['id_jornada_programada'];

        // 2. Fetch ScheduledShift
        $stmtShift = $this->db->prepare("SELECT 
            id, id_personal, id_estacion, fecha_jornada_local, tipo_jornada,
            scheduled_start_utc, scheduled_end_utc, timezone_local, payload_segmentos_json
            FROM op_rh_v2_jornadas_programadas
            WHERE id = ?");
        $stmtShift->bind_param("i", $jornadaId);
        $stmtShift->execute();
        $resShift = $stmtShift->get_result();
        $shiftData = $resShift->fetch_assoc();
        $stmtShift->close();

        if (!$shiftData) {
            throw new RuntimeException("ScheduledShift ID $jornadaId not found.");
        }

        // 3. Fetch P1 pairing segments
        $stmtP1Segs = $this->db->prepare("SELECT 
            ps.id, ps.id_pareo_jornada, ps.ordinal_segmento, ps.id_marcacion_entrada, ps.id_marcacion_salida, ps.estado_segmento,
            mc_in.ocurrido_at_utc AS entry_utc, mc_out.ocurrido_at_utc AS exit_utc
            FROM op_rh_v2_pareos_segmentos ps
            LEFT JOIN op_rh_v2_marcaciones_crudas mc_in ON ps.id_marcacion_entrada = mc_in.id
            LEFT JOIN op_rh_v2_marcaciones_crudas mc_out ON ps.id_marcacion_salida = mc_out.id
            WHERE ps.id_pareo_jornada = ?
            ORDER BY ps.ordinal_segmento ASC");
        $stmtP1Segs->bind_param("i", $pairingHeaderId);
        $stmtP1Segs->execute();
        $resP1Segs = $stmtP1Segs->get_result();
        $p1Segments = [];
        while ($row = $resP1Segs->fetch_assoc()) {
            $p1Segments[] = $row;
        }
        $stmtP1Segs->close();

        // 4. Compute fingerprint and check existing idempotent execution
        $inputPayload = [
            'id_jornada_programada' => $jornadaId,
            'id_pareo_jornada'      => $pairingHeaderId,
            'p1_revision_numero'    => (int)$p1Header['revision_numero'],
            'p1_estado_pareo'       => $p1Header['estado_pareo'],
            'p1_fingerprint'        => $p1Header['fingerprint_ejecucion'],
            'version_algoritmo'     => $this->algorithmVersion
        ];
        $fingerprint = hash('sha256', json_encode($inputPayload));

        $stmtFp = $this->db->prepare("SELECT id FROM op_rh_v2_calculos_jornada WHERE fingerprint_ejecucion = ?");
        $stmtFp->bind_param("s", $fingerprint);
        $stmtFp->execute();
        $resFp = $stmtFp->get_result();
        if ($rowFp = $resFp->fetch_assoc()) {
            $existingId = (int)$rowFp['id'];
            $stmtFp->close();
            return $this->getCalculationResult($existingId);
        }
        $stmtFp->close();

        // 5. Parse ScheduledShift segments payload if present
        $payloadSegs = json_decode($shiftData['payload_segmentos_json'] ?? '[]', true) ?: [];

        // 6. Determine P2 estado_calculo and calculate metrics
        $tipoJornada = $shiftData['tipo_jornada'];
        $p1Estado = $p1Header['estado_pareo'];
        $p1Completeness = $p1Header['estado_completitud_l2'];

        $estadoCalculo = 'CALCULATED';
        $segundosProgramadosTotales = null;
        $segundosTrabajadosTotales = null;
        $segundosFueraHorarioCandidatosTotales = 0;

        if ($p1Completeness === 'PENDING_SYNC_COMPLETION' || $p1Estado === 'PENDING_SYNC_COMPLETION') {
            $estadoCalculo = 'PENDING_SYNC';
        } elseif ($p1Estado === 'AMBIGUOUS_PAIRING') {
            $estadoCalculo = 'AMBIGUOUS';
        } elseif ($p1Estado === 'WORKPLACE_MISMATCH') {
            $estadoCalculo = 'WORKPLACE_MISMATCH';
        } elseif ($tipoJornada === 'DAY_OFF') {
            $estadoCalculo = 'DAY_OFF_RECORDED';
            $segundosProgramadosTotales = 0;
        } elseif ($tipoJornada === 'UNSCHEDULED') {
            $estadoCalculo = 'UNSCHEDULED_RECORDED';
            $segundosProgramadosTotales = null;
        } elseif ($tipoJornada === 'UNKNOWN') {
            $estadoCalculo = 'UNKNOWN_SCHEDULE';
            $segundosProgramadosTotales = null;
        } elseif ($p1Estado === 'UNMATCHED_NO_PUNCHES') {
            $estadoCalculo = 'UNMATCHED_NO_PUNCHES';
        } elseif (in_array($p1Estado, ['PARTIAL_MISSING_IN', 'PARTIAL_MISSING_OUT'])) {
            $estadoCalculo = 'PARTIAL_DATA';
        }

        // Evaluate segment-level calculations
        $segmentResults = [];
        $hasInvalidInterval = false;
        $hasPartialSegment = false;
        $sumWorkedSeconds = 0;
        $sumScheduledSeconds = 0;
        $hasCalculableWork = false;

        if (!empty($p1Segments)) {
            foreach ($p1Segments as $index => $p1Seg) {
                $ordinal = (int)$p1Seg['ordinal_segmento'];
                $segP1Estado = $p1Seg['estado_segmento'];

                // Scheduled bounds from payload if available
                $schedSeg = $payloadSegs[$index] ?? null;
                $segSchedStartUtc = $schedSeg['scheduled_start_utc'] ?? ($index === 0 ? $shiftData['scheduled_start_utc'] : null);
                $segSchedEndUtc = $schedSeg['scheduled_end_utc'] ?? ($index === 0 ? $shiftData['scheduled_end_utc'] : null);

                $segSchedSeconds = null;
                if ($segSchedStartUtc && $segSchedEndUtc) {
                    $dtSchedStart = strtotime($segSchedStartUtc);
                    $dtSchedEnd   = strtotime($segSchedEndUtc);
                    if ($dtSchedEnd >= $dtSchedStart) {
                        $segSchedSeconds = $dtSchedEnd - $dtSchedStart;
                        $sumScheduledSeconds += $segSchedSeconds;
                    }
                }

                $segWorkedSeconds = null;
                $deltaLlegada = null;
                $deltaSalida = null;
                $segFueraHorario = 0;
                $segEstado = 'CALCULATED';

                $entryUtc = $p1Seg['entry_utc'];
                $exitUtc  = $p1Seg['exit_utc'];

                if ($segP1Estado === 'EXACT_PAIR' && !empty($entryUtc) && !empty($exitUtc)) {
                    $dtEntry = strtotime((string)$entryUtc);
                    $dtExit  = strtotime((string)$exitUtc);

                    if ($dtExit < $dtEntry) {
                        $segEstado = 'INVALID_INTERVAL';
                        $hasInvalidInterval = true;
                        $segWorkedSeconds = null;
                    } else {
                        $segWorkedSeconds = $dtExit - $dtEntry;
                        $sumWorkedSeconds += $segWorkedSeconds;
                        $hasCalculableWork = true;

                        // Deltas against schedule bounds if present
                        if ($segSchedStartUtc) {
                            $dtSchedStart = strtotime((string)$segSchedStartUtc);
                            $deltaLlegada = $dtEntry - $dtSchedStart;
                            if ($deltaLlegada < 0) {
                                $segFueraHorario += abs($deltaLlegada);
                            }
                        }
                        if ($segSchedEndUtc) {
                            $dtSchedEnd = strtotime((string)$segSchedEndUtc);
                            $deltaSalida = $dtExit - $dtSchedEnd;
                            if ($deltaSalida > 0) {
                                $segFueraHorario += $deltaSalida;
                            }
                        }
                    }
                } elseif ($segP1Estado === 'PARTIAL_MISSING_IN' || $segP1Estado === 'PARTIAL_MISSING_OUT') {
                    $segEstado = 'PARTIAL_DATA';
                    $hasPartialSegment = true;
                    $segWorkedSeconds = null;

                    if (!empty($entryUtc) && !empty($segSchedStartUtc)) {
                        $deltaLlegada = strtotime((string)$entryUtc) - strtotime((string)$segSchedStartUtc);
                    }
                    if (!empty($exitUtc) && !empty($segSchedEndUtc)) {
                        $deltaSalida = strtotime((string)$exitUtc) - strtotime((string)$segSchedEndUtc);
                    }
                } elseif ($segP1Estado === 'UNMATCHED_NO_PUNCHES') {
                    $segEstado = 'UNMATCHED_NO_PUNCHES';
                }

                $segundosFueraHorarioCandidatosTotales += $segFueraHorario;

                $segmentResults[] = [
                    'id_pareo_segmento' => (int)$p1Seg['id'],
                    'ordinal_segmento'  => $ordinal,
                    'tipo_segmento'     => 'WORK',
                    'estado_segmento'   => $segEstado,
                    'segundos_programados' => $segSchedSeconds,
                    'segundos_trabajados' => $segWorkedSeconds,
                    'delta_llegada_segundos' => $deltaLlegada,
                    'delta_salida_segundos' => $deltaSalida,
                    'segundos_candidatos_fuera_horario' => $segFueraHorario
                ];
            }
        }

        // Final overall status & totals resolution
        if ($hasInvalidInterval) {
            $estadoCalculo = 'INVALID_INTERVAL';
            $segundosTrabajadosTotales = null;
        } elseif ($hasPartialSegment || in_array($p1Estado, ['PARTIAL_MISSING_IN', 'PARTIAL_MISSING_OUT'])) {
            if (!in_array($estadoCalculo, ['PENDING_SYNC', 'AMBIGUOUS', 'WORKPLACE_MISMATCH'])) {
                $estadoCalculo = 'PARTIAL_DATA';
            }
            $segundosTrabajadosTotales = null;
            if ($tipoJornada === 'SCHEDULED') {
                $segundosProgramadosTotales = $sumScheduledSeconds;
            }
        } else {
            if ($tipoJornada === 'SCHEDULED' && $p1Estado !== 'UNMATCHED_NO_PUNCHES') {
                $segundosProgramadosTotales = $sumScheduledSeconds;
            }
            $segundosTrabajadosTotales = $hasCalculableWork ? $sumWorkedSeconds : null;
        }

        // 7. Atomic persistence in database
        $this->db->begin_transaction();
        try {
            // Determine monotonic revision_numero for this ScheduledShift
            $stmtRev = $this->db->prepare("SELECT COALESCE(MAX(revision_numero), 0) + 1 AS next_rev 
                FROM op_rh_v2_calculos_jornada WHERE id_jornada_programada = ? FOR UPDATE");
            $stmtRev->bind_param("i", $jornadaId);
            $stmtRev->execute();
            $resRev = $stmtRev->get_result();
            $nextRev = (int)$resRev->fetch_assoc()['next_rev'];
            $stmtRev->close();

            // Insert Header
            $workerId  = (int)$p1Header['id_personal'];
            $stationId = (int)$p1Header['id_estacion'];
            $workDate  = $p1Header['fecha_jornada_local'];

            $stmtInsHeader = $this->db->prepare("INSERT INTO op_rh_v2_calculos_jornada 
                (id_jornada_programada, id_pareo_jornada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo_calculo, estado_calculo, segundos_programados_totales, segundos_trabajados_totales, segundos_candidatos_fuera_horario, fingerprint_ejecucion)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmtInsHeader->bind_param(
                "iiiisisssiis",
                $jornadaId,
                $pairingHeaderId,
                $workerId,
                $stationId,
                $workDate,
                $nextRev,
                $this->algorithmVersion,
                $estadoCalculo,
                $segundosProgramadosTotales,
                $segundosTrabajadosTotales,
                $segundosFueraHorarioCandidatosTotales,
                $fingerprint
            );
            $stmtInsHeader->execute();
            $calculoHeaderId = (int)$stmtInsHeader->insert_id;
            $stmtInsHeader->close();

            // Insert Child Segments
            if (!empty($segmentResults)) {
                $stmtInsSeg = $this->db->prepare("INSERT INTO op_rh_v2_calculos_segmentos
                    (id_calculo_jornada, id_pareo_segmento, ordinal_segmento, tipo_segmento, estado_segmento, segundos_programados, segundos_trabajados, delta_llegada_segundos, delta_salida_segundos, segundos_candidatos_fuera_horario)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                foreach ($segmentResults as $segRes) {
                    $idP2Seg = $segRes['id_pareo_segmento'];
                    $ord = $segRes['ordinal_segmento'];
                    $tipo = $segRes['tipo_segmento'];
                    $estSeg = $segRes['estado_segmento'];
                    $sProg = $segRes['segundos_programados'];
                    $sTrab = $segRes['segundos_trabajados'];
                    $dLleg = $segRes['delta_llegada_segundos'];
                    $dSal  = $segRes['delta_salida_segundos'];
                    $sFuera = $segRes['segundos_candidatos_fuera_horario'];

                    $stmtInsSeg->bind_param(
                        "iiissiiiii",
                        $calculoHeaderId,
                        $idP2Seg,
                        $ord,
                        $tipo,
                        $estSeg,
                        $sProg,
                        $sTrab,
                        $dLleg,
                        $dSal,
                        $sFuera
                    );
                    $stmtInsSeg->execute();
                }
                $stmtInsSeg->close();
            }

            $this->db->commit();
            return $this->getCalculationResult($calculoHeaderId);
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Retrieves full calculation revision payload by header ID.
     */
    public function getCalculationResult(int $calculoHeaderId): array {
        $stmt = $this->db->prepare("SELECT * FROM op_rh_v2_calculos_jornada WHERE id = ?");
        $stmt->bind_param("i", $calculoHeaderId);
        $stmt->execute();
        $header = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$header) {
            throw new RuntimeException("Calculation header ID $calculoHeaderId not found.");
        }

        $stmtSegs = $this->db->prepare("SELECT * FROM op_rh_v2_calculos_segmentos WHERE id_calculo_jornada = ? ORDER BY ordinal_segmento ASC");
        $stmtSegs->bind_param("i", $calculoHeaderId);
        $stmtSegs->execute();
        $resSegs = $stmtSegs->get_result();
        $segmentos = [];
        while ($row = $resSegs->fetch_assoc()) {
            $segmentos[] = $row;
        }
        $stmtSegs->close();

        $header['segmentos'] = $segmentos;
        return $header;
    }
}
