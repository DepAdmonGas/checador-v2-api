<?php
/**
 * Checador V2 — Journey Pairing Domain Service (Phase 1)
 *
 * Responsibility: Deterministically associate immutable raw PunchEvents
 * with materialized ScheduledShifts and store append-only pairing revisions.
 *
 * SPECIFICATION: docs/v2/CHECADOR_V2_JOURNEY_PAIRING_CONTRACT_V1.0.md
 * PHYSICAL MODEL: docs/architecture/CHECADOR_V2_JOURNEY_PAIRING_PHYSICAL_MODEL_V1.0.md
 */

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';

class JourneyPairingService
{
    private mysqli $db;
    private string $algorithmVersion;
    private int $defaultToleranceSeconds;

    public function __construct(?mysqli $db = null, string $algorithmVersion = 'v2.4-p1', int $defaultToleranceSeconds = 14400)
    {
        if ($db !== null) {
            $this->db = $db;
        } else {
            $bdObj = new BD();
            $this->db = $bdObj->conectarBD();
        }
        $this->algorithmVersion = $algorithmVersion;
        $this->defaultToleranceSeconds = $defaultToleranceSeconds; // 4 hours
    }

    /**
     * Pair raw PunchEvents against a materialized ScheduledShift.
     *
     * @param int $scheduledShiftId
     * @return array Pairing execution result structure
     */
    public function pairScheduledShift(int $scheduledShiftId): array
    {
        // 1. Fetch ScheduledShift
        $stmt = $this->db->prepare(
            "SELECT id, id_personal, id_estacion, fecha_jornada_local, tipo_jornada,
                    scheduled_start_utc, scheduled_end_utc, timezone_local, payload_segmentos_json
             FROM op_rh_v2_jornadas_programadas
             WHERE id = ?"
        );
        $stmt->bind_param('i', $scheduledShiftId);
        $stmt->execute();
        $res = $stmt->get_result();
        $shift = $res ? $res->fetch_assoc() : null;

        if (!$shift) {
            throw new InvalidArgumentException("ScheduledShift ID $scheduledShiftId not found.");
        }

        $workerId = (int)$shift['id_personal'];
        $stationId = (int)$shift['id_estacion'];
        $workDate = $shift['fecha_jornada_local'];
        $tipoJornada = $shift['tipo_jornada'];
        $scheduledStartUtc = $shift['scheduled_start_utc'];
        $scheduledEndUtc = $shift['scheduled_end_utc'];
        $segmentsJson = $shift['payload_segmentos_json'];

        // Decode segments payload or build single segment from shift bounds
        $segments = [];
        if (!empty($segmentsJson)) {
            $decoded = json_decode($segmentsJson, true);
            if (is_array($decoded)) {
                $segments = $decoded;
            }
        }

        if (empty($segments) && $scheduledStartUtc !== null && $scheduledEndUtc !== null) {
            $segments = [[
                'ordinal' => 1,
                'tipo_segmento' => 'WORK',
                'scheduled_start_utc' => $scheduledStartUtc,
                'scheduled_end_utc' => $scheduledEndUtc
            ]];
        } else {
            foreach ($segments as $idx => &$seg) {
                if (empty($seg['scheduled_start_utc']) && count($segments) === 1 && $scheduledStartUtc !== null) {
                    $seg['scheduled_start_utc'] = $scheduledStartUtc;
                }
                if (empty($seg['scheduled_end_utc']) && count($segments) === 1 && $scheduledEndUtc !== null) {
                    $seg['scheduled_end_utc'] = $scheduledEndUtc;
                }
                if (empty($seg['tipo_segmento']) && !empty($seg['tipo'])) {
                    $seg['tipo_segmento'] = $seg['tipo'];
                }
            }
            unset($seg);
        }

        // 2. Evaluate Level 2 Completeness
        $l2Status = $this->evaluateLevel2Completeness($stationId, $workDate);

        // 3. Handle non-scheduled day states (DAY_OFF, UNSCHEDULED, UNKNOWN)
        if ($tipoJornada !== 'SCHEDULED' || empty($segments)) {
            return $this->pairNonScheduledShift($shift, $segments, $l2Status);
        }

        // 4. Calculate search window W = [S_start - 4h, S_end + 4h]
        $firstStart = $scheduledStartUtc;
        $lastEnd = $scheduledEndUtc;
        foreach ($segments as $seg) {
            if (!empty($seg['scheduled_start_utc']) && $seg['scheduled_start_utc'] < $firstStart) {
                $firstStart = $seg['scheduled_start_utc'];
            }
            if (!empty($seg['scheduled_end_utc']) && $seg['scheduled_end_utc'] > $lastEnd) {
                $lastEnd = $seg['scheduled_end_utc'];
            }
        }

        $windowStart = date('Y-m-d H:i:s', strtotime($firstStart) - $this->defaultToleranceSeconds);
        $windowEnd = date('Y-m-d H:i:s', strtotime($lastEnd) + $this->defaultToleranceSeconds);

        // 5. Query candidate raw PunchEvents
        $punches = $this->fetchCandidatePunches($workerId, $windowStart, $windowEnd);

        // 6. Calculate execution fingerprint
        $fingerprint = $this->calculateFingerprint($scheduledShiftId, $punches, $l2Status);

        // 7. Check if identical execution already exists (Idempotency)
        $existingHeader = $this->findExistingExecutionByFingerprint($scheduledShiftId, $fingerprint);
        if ($existingHeader !== null) {
            return $existingHeader;
        }

        // 8. Execute deterministic slot allocation per segment
        $pairingResults = $this->allocateSegmentSlots($segments, $punches, $stationId);

        $overallStatus = $pairingResults['overall_status'];
        if ($l2Status !== 'VERIFIED' && $overallStatus !== 'WORKPLACE_MISMATCH' && $overallStatus !== 'AMBIGUOUS_PAIRING') {
            $overallStatus = 'PENDING_SYNC_COMPLETION';
        }

        // 9. Atomic DB Transaction to persist execution revision
        $this->db->begin_transaction();
        try {
            // Get next revision number
            $revStmt = $this->db->prepare("SELECT COALESCE(MAX(revision_numero), 0) + 1 AS next_rev FROM op_rh_v2_pareos_jornada WHERE id_jornada_programada = ? FOR UPDATE");
            $revStmt->bind_param('i', $scheduledShiftId);
            $revStmt->execute();
            $revRes = $revStmt->get_result();
            $nextRevision = (int)($revRes->fetch_assoc()['next_rev'] ?? 1);

            // Insert Header
            $hStmt = $this->db->prepare(
                "INSERT INTO op_rh_v2_pareos_jornada
                    (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo, estado_completitud_l2, estado_pareo, fingerprint_ejecucion)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $hStmt->bind_param(
                'iiisissss',
                $scheduledShiftId,
                $workerId,
                $stationId,
                $workDate,
                $nextRevision,
                $this->algorithmVersion,
                $l2Status,
                $overallStatus,
                $fingerprint
            );
            $hStmt->execute();
            $headerId = (int)$this->db->insert_id;

            // Insert Segment Results and Punch Associations
            foreach ($pairingResults['segment_results'] as $segRes) {
                $ordinal = (int)$segRes['ordinal'];
                $entryPunchId = $segRes['entry_punch_id'];
                $exitPunchId = $segRes['exit_punch_id'];
                $segStatus = $segRes['status'];

                $sStmt = $this->db->prepare(
                    "INSERT INTO op_rh_v2_pareos_segmentos
                        (id_pareo_jornada, ordinal_segmento, id_marcacion_entrada, id_marcacion_salida, estado_segmento)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $sStmt->bind_param('iiiis', $headerId, $ordinal, $entryPunchId, $exitPunchId, $segStatus);
                $sStmt->execute();
                $segmentId = (int)$this->db->insert_id;

                // Punch Associations
                foreach ($segRes['associated_punches'] as $assoc) {
                    $punchId = (int)$assoc['punch_id'];
                    $role = $assoc['role'];

                    $aStmt = $this->db->prepare(
                        "INSERT INTO op_rh_v2_pareos_marcaciones_asociadas
                            (id_pareo_jornada, id_pareo_segmento, id_marcacion_cruda, rol_marcacion)
                         VALUES (?, ?, ?, ?)"
                    );
                    $aStmt->bind_param('iiis', $headerId, $segmentId, $punchId, $role);
                    $aStmt->execute();
                }
            }

            // Unassigned/Surplus Punches outside segments
            foreach ($pairingResults['unassigned_punches'] as $unassigned) {
                $punchId = (int)$unassigned['punch_id'];
                $role = $unassigned['role'];

                $aStmt = $this->db->prepare(
                    "INSERT INTO op_rh_v2_pareos_marcaciones_asociadas
                        (id_pareo_jornada, id_pareo_segmento, id_marcacion_cruda, rol_marcacion)
                     VALUES (?, NULL, ?, ?)"
                );
                $aStmt->bind_param('iis', $headerId, $punchId, $role);
                $aStmt->execute();
            }

            $this->db->commit();

            $creadoStmt = $this->db->prepare("SELECT creado_at_utc FROM op_rh_v2_pareos_jornada WHERE id = ?");
            $creadoStmt->bind_param('i', $headerId);
            $creadoStmt->execute();
            $creadoRow = $creadoStmt->get_result()->fetch_assoc();
            $creadoAtUtc = $creadoRow['creado_at_utc'] ?? gmdate('Y-m-d H:i:s');
            $creadoStmt->close();

            $evaluatedCandidates = $this->getEvaluatedCandidatesForPairing($headerId);

            return [
                'pairing_id' => $headerId,
                'scheduled_shift_id' => $scheduledShiftId,
                'revision_numero' => $nextRevision,
                'worker_id' => $workerId,
                'station_id' => $stationId,
                'work_date' => $workDate,
                'pairing_status' => $overallStatus,
                'completeness_l2' => $l2Status,
                'algorithm_version' => $this->algorithmVersion,
                'fingerprint' => $fingerprint,
                'segments' => $pairingResults['segment_results'],
                'creado_at_utc' => $creadoAtUtc,
                'evaluated_candidates' => $evaluatedCandidates
            ];

        } catch (Throwable $ex) {
            $this->db->rollback();
            throw $ex;
        }
    }

    /**
     * Evaluate Level 2 Completeness state for a station and work date.
     */
    private function evaluateLevel2Completeness(int $stationId, string $workDate): string
    {
        $stmt = $this->db->prepare(
            "SELECT window_level2_status FROM op_rh_v2_level2_window_evaluations
             WHERE id_estacion = ? AND DATE(window_from_utc) <= ? AND DATE(window_to_utc) >= ?
             ORDER BY id DESC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('iss', $stationId, $workDate, $workDate);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $row = $res->fetch_assoc()) {
                if ($row['window_level2_status'] === 'VERIFIED') {
                    return 'VERIFIED';
                }
                return 'PENDING_SYNC_COMPLETION';
            }
        }
        return 'VERIFIED';
    }

    /**
     * Fetch candidate raw PunchEvents for a worker within window.
     */
    private function fetchCandidatePunches(int $workerId, string $windowStart, string $windowEnd): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, event_uuid, id_dispositivo, id_estacion_resuelta, id_personal_resuelto, ocurrido_at_utc, metodo_captura, processing_status, validation_flags
             FROM op_rh_v2_marcaciones_crudas
             WHERE id_personal_resuelto = ?
               AND ocurrido_at_utc BETWEEN ? AND ?
             ORDER BY ocurrido_at_utc ASC, id ASC"
        );
        $stmt->bind_param('iss', $workerId, $windowStart, $windowEnd);
        $stmt->execute();
        $res = $stmt->get_result();

        $punches = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $punches[] = $row;
            }
        }
        return $punches;
    }

    /**
     * Retrieve the exact evaluated candidate PunchEvent set for an explicit pairing revision.
     * Guaranteed deterministic ordering (ocurrido_at_utc ASC, id ASC).
     * Internal raw PK (id) is omitted; public audit identity is preserved.
     */
    public function getEvaluatedCandidatesForPairing(int $pairingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.event_uuid, m.id_dispositivo, m.ocurrido_at_utc, m.metodo_captura,
                    a.rol_marcacion, s.ordinal_segmento
             FROM op_rh_v2_pareos_marcaciones_asociadas a
             INNER JOIN op_rh_v2_marcaciones_crudas m ON a.id_marcacion_cruda = m.id
             LEFT JOIN op_rh_v2_pareos_segmentos s ON a.id_pareo_segmento = s.id
             WHERE a.id_pareo_jornada = ?
             ORDER BY m.ocurrido_at_utc ASC, m.id ASC"
        );
        $stmt->bind_param('i', $pairingId);
        $stmt->execute();
        $res = $stmt->get_result();
        $candidates = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $candidates[] = [
                    'event_uuid' => (string)$row['event_uuid'],
                    'id_dispositivo' => $row['id_dispositivo'] !== null ? (int)$row['id_dispositivo'] : null,
                    'ocurrido_at_utc' => (string)$row['ocurrido_at_utc'],
                    'metodo_captura' => (string)($row['metodo_captura'] ?? ''),
                    'pairing_role' => isset($row['rol_marcacion']) && $row['rol_marcacion'] !== null ? (string)$row['rol_marcacion'] : null,
                    'segment_ordinal' => isset($row['ordinal_segmento']) && $row['ordinal_segmento'] !== null ? (int)$row['ordinal_segmento'] : null
                ];
            }
        }
        $stmt->close();
        return $candidates;
    }

    /**
     * Calculate SHA256 execution fingerprint.
     */
    private function calculateFingerprint(int $shiftId, array $punches, string $l2Status): string
    {
        $punchTokens = [];
        foreach ($punches as $p) {
            $punchTokens[] = $p['id'] . ':' . $p['ocurrido_at_utc'];
        }
        $rawString = "shift:$shiftId:ver:{$this->algorithmVersion}:l2:$l2Status:punches:" . implode(',', $punchTokens);
        return hash('sha256', $rawString);
    }

    /**
     * Find existing execution header by fingerprint for idempotency.
     */
    private function findExistingExecutionByFingerprint(int $shiftId, string $fingerprint): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, revision_numero, id_personal, id_estacion, fecha_jornada_local, estado_pareo, estado_completitud_l2, version_algoritmo, fingerprint_ejecucion, creado_at_utc
             FROM op_rh_v2_pareos_jornada
             WHERE id_jornada_programada = ? AND fingerprint_ejecucion = ?"
        );
        $stmt->bind_param('is', $shiftId, $fingerprint);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $headerId = (int)$row['id'];
            $evaluatedCandidates = $this->getEvaluatedCandidatesForPairing($headerId);

            // Reconstruct segments for idempotent reuse
            $segStmt = $this->db->prepare(
                "SELECT id, ordinal_segmento, id_marcacion_entrada, id_marcacion_salida, estado_segmento
                 FROM op_rh_v2_pareos_segmentos
                 WHERE id_pareo_jornada = ?
                 ORDER BY ordinal_segmento ASC"
            );
            $segStmt->bind_param('i', $headerId);
            $segStmt->execute();
            $sRes = $segStmt->get_result();
            $segments = [];
            if ($sRes) {
                while ($sRow = $sRes->fetch_assoc()) {
                    $segId = (int)$sRow['id'];
                    $aStmt = $this->db->prepare(
                        "SELECT id_marcacion_cruda, rol_marcacion
                         FROM op_rh_v2_pareos_marcaciones_asociadas
                         WHERE id_pareo_segmento = ?"
                    );
                    $aStmt->bind_param('i', $segId);
                    $aStmt->execute();
                    $aRes = $aStmt->get_result();
                    $associated = [];
                    if ($aRes) {
                        while ($aRow = $aRes->fetch_assoc()) {
                            $associated[] = [
                                'punch_id' => (int)$aRow['id_marcacion_cruda'],
                                'role' => $aRow['rol_marcacion']
                            ];
                        }
                    }
                    $aStmt->close();
                    $segments[] = [
                        'ordinal' => (int)$sRow['ordinal_segmento'],
                        'status' => $sRow['estado_segmento'],
                        'entry_punch_id' => $sRow['id_marcacion_entrada'] !== null ? (int)$sRow['id_marcacion_entrada'] : null,
                        'exit_punch_id' => $sRow['id_marcacion_salida'] !== null ? (int)$sRow['id_marcacion_salida'] : null,
                        'associated_punches' => $associated
                    ];
                }
            }
            $segStmt->close();

            return [
                'pairing_id' => $headerId,
                'scheduled_shift_id' => $shiftId,
                'revision_numero' => (int)$row['revision_numero'],
                'worker_id' => (int)$row['id_personal'],
                'station_id' => (int)$row['id_estacion'],
                'work_date' => $row['fecha_jornada_local'],
                'pairing_status' => $row['estado_pareo'],
                'completeness_l2' => $row['estado_completitud_l2'],
                'algorithm_version' => $row['version_algoritmo'],
                'fingerprint' => $row['fingerprint_ejecucion'],
                'segments' => $segments,
                'creado_at_utc' => $row['creado_at_utc'],
                'evaluated_candidates' => $evaluatedCandidates,
                'is_idempotent_reuse' => true
            ];
        }
        return null;
    }

    /**
     * Allocate punches to segment slots deterministically.
     */
    private function allocateSegmentSlots(array $segments, array $punches, int $expectedStationId): array
    {
        $segmentResults = [];
        $usedPunchIds = [];
        $hasAmbiguity = false;
        $hasWorkplaceMismatch = false;

        $numSegments = count($segments);

        foreach ($segments as $idx => $seg) {
            $ordinal = (int)($seg['ordinal'] ?? ($idx + 1));
            $segStart = $seg['scheduled_start_utc'] ?? null;
            $segEnd = $seg['scheduled_end_utc'] ?? null;

            if (!$segStart || !$segEnd) {
                continue;
            }

            $segStartTs = strtotime($segStart);
            $segEndTs = strtotime($segEnd);
            $midTs = $segStartTs + (int)(($segEndTs - $segStartTs) / 2);

            // Bounded window per segment to prevent cross-segment overlap
            $segPre = $segStartTs - $this->defaultToleranceSeconds;
            $segPost = $segEndTs + $this->defaultToleranceSeconds;

            if ($idx > 0 && isset($segments[$idx - 1]['scheduled_end_utc'])) {
                $prevEndTs = strtotime($segments[$idx - 1]['scheduled_end_utc']);
                $segPre = max($segPre, (int)($prevEndTs + ($segStartTs - $prevEndTs) / 2));
            }
            if ($idx < $numSegments - 1 && isset($segments[$idx + 1]['scheduled_start_utc'])) {
                $nextStartTs = strtotime($segments[$idx + 1]['scheduled_start_utc']);
                $segPost = min($segPost, (int)($segEndTs + ($nextStartTs - $segEndTs) / 2));
            }

            // Find candidate punches for segment that are not yet consumed
            $candidates = [];
            foreach ($punches as $p) {
                $pId = (int)$p['id'];
                if (isset($usedPunchIds[$pId])) {
                    continue;
                }
                $pTs = strtotime($p['ocurrido_at_utc']);
                if ($pTs >= $segPre && $pTs <= $segPost) {
                    $candidates[] = $p;
                }
            }

            // Check workplace alignment and validation flags
            foreach ($candidates as $c) {
                if ($c['id_estacion_resuelta'] !== null && (int)$c['id_estacion_resuelta'] !== $expectedStationId) {
                    $hasWorkplaceMismatch = true;
                }
                if (!empty($c['validation_flags']) && (strpos($c['validation_flags'], 'INVALID') !== false || strpos($c['validation_flags'], 'UNVERIFIABLE') !== false)) {
                    $hasAmbiguity = true;
                }
            }

            // Entry candidates: pre..mid
            $entryCandidates = [];
            // Exit candidates: mid..post
            $exitCandidates = [];

            foreach ($candidates as $c) {
                $pTs = strtotime($c['ocurrido_at_utc']);
                if ($pTs <= $midTs) {
                    $entryCandidates[] = $c;
                } else {
                    $exitCandidates[] = $c;
                }
            }

            $entryPunch = !empty($entryCandidates) ? $entryCandidates[0] : null;
            $exitPunch = !empty($exitCandidates) ? $exitCandidates[count($exitCandidates) - 1] : null;

            $associated = [];
            $entryPunchId = null;
            $exitPunchId = null;

            if ($entryPunch !== null) {
                $entryPunchId = (int)$entryPunch['id'];
                $usedPunchIds[$entryPunchId] = true;
                $associated[] = ['punch_id' => $entryPunchId, 'role' => 'ENTRY'];
            }

            if ($exitPunch !== null && ($entryPunch === null || $exitPunch['id'] !== $entryPunch['id'])) {
                $exitPunchId = (int)$exitPunch['id'];
                $usedPunchIds[$exitPunchId] = true;
                $associated[] = ['punch_id' => $exitPunchId, 'role' => 'EXIT'];
            }

            // Intermediate surplus punches strictly between Entry and Exit candidates for segment
            foreach ($candidates as $c) {
                $cId = (int)$c['id'];
                if ($cId !== $entryPunchId && $cId !== $exitPunchId && !isset($usedPunchIds[$cId])) {
                    $cTs = strtotime($c['ocurrido_at_utc']);
                    if ($entryPunchId !== null && $exitPunchId !== null) {
                        $eStartTs = strtotime($entryPunch['ocurrido_at_utc']);
                        $eEndTs = strtotime($exitPunch['ocurrido_at_utc']);
                        if ($cTs >= $eStartTs && $cTs <= $eEndTs) {
                            $usedPunchIds[$cId] = true;
                            $associated[] = ['punch_id' => $cId, 'role' => 'SURPLUS'];
                        }
                    }
                }
            }

            // Determine segment status
            if ($entryPunchId !== null && $exitPunchId !== null) {
                $segStatus = 'EXACT_PAIR';
            } elseif ($entryPunchId !== null) {
                $segStatus = 'PARTIAL_MISSING_OUT';
            } elseif ($exitPunchId !== null) {
                $segStatus = 'PARTIAL_MISSING_IN';
            } else {
                $segStatus = 'UNMATCHED_NO_PUNCHES';
            }

            if ($hasWorkplaceMismatch) {
                $segStatus = 'WORKPLACE_MISMATCH';
            } elseif ($hasAmbiguity) {
                $segStatus = 'AMBIGUOUS_PAIRING';
            }

            $segmentResults[] = [
                'ordinal' => $ordinal,
                'entry_punch_id' => $entryPunchId,
                'exit_punch_id' => $exitPunchId,
                'status' => $segStatus,
                'associated_punches' => $associated
            ];
        }

        // Collect unassigned punches outside segments
        $unassigned = [];
        foreach ($punches as $p) {
            $pId = (int)$p['id'];
            if (!isset($usedPunchIds[$pId])) {
                $unassigned[] = ['punch_id' => $pId, 'role' => 'UNMATCHED'];
            }
        }

        // Determine overall status
        $overallStatus = 'EXACT_PAIR';
        if ($hasWorkplaceMismatch) {
            $overallStatus = 'WORKPLACE_MISMATCH';
        } elseif ($hasAmbiguity) {
            $overallStatus = 'AMBIGUOUS_PAIRING';
        } else {
            $allExact = true;
            $anyMissingIn = false;
            $anyMissingOut = false;
            $allUnmatched = true;

            foreach ($segmentResults as $sr) {
                if ($sr['status'] !== 'EXACT_PAIR') {
                    $allExact = false;
                }
                if ($sr['status'] !== 'UNMATCHED_NO_PUNCHES') {
                    $allUnmatched = false;
                }
                if ($sr['status'] === 'PARTIAL_MISSING_IN') {
                    $anyMissingIn = true;
                }
                if ($sr['status'] === 'PARTIAL_MISSING_OUT') {
                    $anyMissingOut = true;
                }
            }

            if ($allUnmatched) {
                $overallStatus = 'UNMATCHED_NO_PUNCHES';
            } elseif ($anyMissingIn && $anyMissingOut) {
                $overallStatus = 'AMBIGUOUS_PAIRING';
            } elseif ($anyMissingIn) {
                $overallStatus = 'PARTIAL_MISSING_IN';
            } elseif ($anyMissingOut) {
                $overallStatus = 'PARTIAL_MISSING_OUT';
            } elseif (!$allExact) {
                $overallStatus = 'AMBIGUOUS_PAIRING';
            }
        }

        return [
            'overall_status' => $overallStatus,
            'segment_results' => $segmentResults,
            'unassigned_punches' => $unassigned
        ];
    }

    /**
     * Non-scheduled shift pairing (DAY_OFF, UNSCHEDULED, UNKNOWN).
     */
    private function pairNonScheduledShift(array $shift, array $segments, string $l2Status): array
    {
        $shiftId = (int)$shift['id'];
        $workerId = (int)$shift['id_personal'];
        $stationId = (int)$shift['id_estacion'];
        $workDate = $shift['fecha_jornada_local'];

        $dayStart = "$workDate 00:00:00";
        $dayEnd = "$workDate 23:59:59";

        $punches = $this->fetchCandidatePunches($workerId, $dayStart, $dayEnd);
        $fingerprint = $this->calculateFingerprint($shiftId, $punches, $l2Status);

        $existingHeader = $this->findExistingExecutionByFingerprint($shiftId, $fingerprint);
        if ($existingHeader !== null) {
            return $existingHeader;
        }

        $overallStatus = empty($punches) ? 'UNMATCHED_NO_PUNCHES' : 'UNMATCHED_NO_PUNCHES';
        if ($l2Status !== 'VERIFIED') {
            $overallStatus = 'PENDING_SYNC_COMPLETION';
        }

        $this->db->begin_transaction();
        try {
            $revStmt = $this->db->prepare("SELECT COALESCE(MAX(revision_numero), 0) + 1 AS next_rev FROM op_rh_v2_pareos_jornada WHERE id_jornada_programada = ? FOR UPDATE");
            $revStmt->bind_param('i', $shiftId);
            $revStmt->execute();
            $revRes = $revStmt->get_result();
            $nextRevision = (int)($revRes->fetch_assoc()['next_rev'] ?? 1);

            $hStmt = $this->db->prepare(
                "INSERT INTO op_rh_v2_pareos_jornada
                    (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo, estado_completitud_l2, estado_pareo, fingerprint_ejecucion)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $hStmt->bind_param(
                'iiisissss',
                $shiftId,
                $workerId,
                $stationId,
                $workDate,
                $nextRevision,
                $this->algorithmVersion,
                $l2Status,
                $overallStatus,
                $fingerprint
            );
            $hStmt->execute();
            $headerId = (int)$this->db->insert_id;

            foreach ($punches as $p) {
                $punchId = (int)$p['id'];
                $aStmt = $this->db->prepare(
                    "INSERT INTO op_rh_v2_pareos_marcaciones_asociadas
                        (id_pareo_jornada, id_pareo_segmento, id_marcacion_cruda, rol_marcacion)
                     VALUES (?, NULL, ?, 'UNMATCHED')"
                );
                $aStmt->bind_param('iis', $headerId, $punchId);
                $aStmt->execute();
            }

            $this->db->commit();

            $creadoStmt = $this->db->prepare("SELECT creado_at_utc FROM op_rh_v2_pareos_jornada WHERE id = ?");
            $creadoStmt->bind_param('i', $headerId);
            $creadoStmt->execute();
            $creadoRow = $creadoStmt->get_result()->fetch_assoc();
            $creadoAtUtc = $creadoRow['creado_at_utc'] ?? gmdate('Y-m-d H:i:s');
            $creadoStmt->close();

            $evaluatedCandidates = $this->getEvaluatedCandidatesForPairing($headerId);

            return [
                'pairing_id' => $headerId,
                'scheduled_shift_id' => $shiftId,
                'revision_numero' => $nextRevision,
                'worker_id' => $workerId,
                'station_id' => $stationId,
                'work_date' => $workDate,
                'pairing_status' => $overallStatus,
                'completeness_l2' => $l2Status,
                'algorithm_version' => $this->algorithmVersion,
                'fingerprint' => $fingerprint,
                'segments' => [],
                'creado_at_utc' => $creadoAtUtc,
                'evaluated_candidates' => $evaluatedCandidates
            ];

        } catch (Throwable $ex) {
            $this->db->rollback();
            throw $ex;
        }
    }
}
