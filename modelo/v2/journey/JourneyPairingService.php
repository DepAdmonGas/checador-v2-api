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
require_once __DIR__ . '/../WorkplacePhysicalStationResolver.php';
require_once __DIR__ . '/JourneyMariaDbLock.php';

class JourneyPairingService
{
    /** Explicit version selection; absent historical parameters are V1.0. */
    public static function selectV11Policy(?array $row): ?array
    {
        if ($row === null) return null;
        $fields = ['early_entry_allowed_minutes', 'duplicate_punch_window_minutes', 'exit_priority_window_minutes'];
        foreach ($fields as $field) if (!array_key_exists($field, $row)) throw new InvalidArgumentException('J1_POLICY_SCHEMA_UNAVAILABLE');
        $values = array_map(static fn($field) => $row[$field], $fields);
        if ($values === [null, null, null]) return null;
        $limits = [[0, 10], [1, 10], [30, 120]];
        foreach ($values as $i => $value) {
            if ((!is_int($value) && !(is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value))) || (int)$value < $limits[$i][0] || (int)$value > $limits[$i][1]) throw new InvalidArgumentException('J1_POLICY_INVALID');
        }
        if (!isset($row['id'], $row['policy_version']) || (int)$row['id'] < 1 || (int)$row['policy_version'] < 1) throw new InvalidArgumentException('J1_POLICY_INVALID');
        return ['policy_id'=>(int)$row['id'], 'version'=>(int)$row['policy_version'], 'early_entry_minutes'=>(int)$values[0], 'repeat_minutes'=>(int)$values[1], 'exit_priority_minutes'=>(int)$values[2]];
    }

    /** A single verified evaluation must cover the whole UTC interval and station. */
    public static function v11Closure(DateTimeImmutable $now, DateTimeImmutable $start, DateTimeImmutable $end, int $station, ?array $evidence, bool $conflict): bool
    {
        if ($station < 1 || $end <= $start || $conflict || $now < $end || $evidence === null) return false;
        if (($evidence['window_level2_status'] ?? null) !== 'VERIFIED' || (int)($evidence['id_estacion'] ?? 0) !== $station) return false;
        $parse = static function ($value): ?DateTimeImmutable {
            if (!is_string($value)) return null;
            $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
            return $d && $d->format('Y-m-d H:i:s') === $value ? $d : null;
        };
        $from = $parse($evidence['window_from_utc'] ?? null); $to = $parse($evidence['window_to_utc'] ?? null);
        return $from !== null && $to !== null && $from <= $start && $to >= $end;
    }

    /** V1.1 deterministic segment classification for explicitly supplied policy.
     * The caller must establish cross-journey ownership; disputed events fail closed.
     */
    public static function classifyV11(array $segments, array $punches, array $policy, bool $closed, array $conflictingIds = []): array
    {
        foreach (['early_entry_minutes' => [0, 10], 'repeat_minutes' => [1, 10], 'exit_priority_minutes' => [30, 120]] as $key => [$minimum, $maximum]) {
            if (!isset($policy[$key]) || !is_int($policy[$key]) || $policy[$key] < $minimum || $policy[$key] > $maximum) throw new InvalidArgumentException('INVALID_J1_POLICY');
        }
        $time = static function (string $value): int {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
            if (!$dt || $dt->format('Y-m-d H:i:s') !== $value) throw new InvalidArgumentException('INVALID_J1_TIMESTAMP');
            return $dt->getTimestamp();
        };
        $slots = []; $used = [];
        foreach ($segments as $index => $segment) {
            $start = $time($segment['scheduled_start_utc']); $end = $time($segment['scheduled_end_utc']);
            if ($start >= $end) throw new InvalidArgumentException('INVALID_J1_SEGMENT');
            $lower = $start - $policy['early_entry_minutes'] * 60; $upper = $end + 14400;
            if (isset($segments[$index - 1])) $lower = max($lower, intdiv($time($segments[$index - 1]['scheduled_end_utc']) + $start, 2));
            if (isset($segments[$index + 1])) $upper = min($upper, intdiv($end + $time($segments[$index + 1]['scheduled_start_utc']), 2));
            $candidates = [];
            foreach ($punches as $p) {
                $id = (int)$p['id']; $t = $time($p['ocurrido_at_utc']);
                if (!isset($used[$id]) && $t >= $lower && $t <= $upper) $candidates[] = $p;
            }
            $groups = self::groupPossibleRepeats($candidates, $policy['repeat_minutes'] * 60);
            $entry = null; $exit = null; $ambiguous = false; $associated = [];
            foreach ($candidates as $p) if (in_array((int)$p['id'], $conflictingIds, true)) $ambiguous = true;
            if ($groups && !$ambiguous) {
                $first = $groups[0][0];
                if (count($groups) === 1 && $time($first['ocurrido_at_utc']) > $end) $exit = $groups[0][count($groups[0]) - 1];
                else {
                    $entry = $first;
                    $last = count($groups) - 1;
                    if ($last > 0) {
                        $candidate = $groups[$last][count($groups[$last]) - 1];
                        if ($time($candidate['ocurrido_at_utc']) >= $end - $policy['exit_priority_minutes'] * 60 || $closed) $exit = $candidate;
                    }
                }
            }
            if ($ambiguous) { $entry = null; $exit = null; }
            foreach ($candidates as $p) {
                $id = (int)$p['id']; $used[$id] = true;
                $role = $ambiguous ? 'AMBIGUOUS' : (($entry && $id === (int)$entry['id']) ? 'ENTRY' : (($exit && $id === (int)$exit['id']) ? 'EXIT' : 'SURPLUS'));
                $associated[] = ['punch_id'=>$id, 'role'=>$role];
            }
            $slots[] = ['ordinal'=>(int)($segment['ordinal'] ?? $index + 1), 'entry_punch_id'=>$entry['id'] ?? null, 'exit_punch_id'=>$exit['id'] ?? null, 'status'=>$ambiguous ? 'AMBIGUOUS_PAIRING' : ($entry && $exit ? 'EXACT_PAIR' : ($entry ? 'PARTIAL_MISSING_OUT' : ($exit ? 'PARTIAL_MISSING_IN' : 'UNMATCHED_NO_PUNCHES'))), 'associated_punches'=>$associated];
        }
        $unassigned = [];
        foreach ($punches as $p) if (!isset($used[(int)$p['id']])) $unassigned[] = ['punch_id'=>(int)$p['id'], 'role'=>'UNMATCHED'];
        return ['segments'=>$slots, 'unassigned'=>$unassigned];
    }

    /** Group chronologically ordered physical events from each group's first timestamp. */
    public static function groupPossibleRepeats(array $punches, int $windowSeconds = 300): array
    {
        if ($windowSeconds < 0) throw new InvalidArgumentException('INVALID_REPEAT_WINDOW');
        $groups = [];
        $firstTimestamp = null;
        $previousTimestamp = null;
        foreach ($punches as $punch) {
            $value = $punch['ocurrido_at_utc'] ?? null;
            $instant = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC')) : false;
            if ($instant === false || $instant->format('Y-m-d H:i:s') !== $value) throw new InvalidArgumentException('INVALID_PUNCH_TIMESTAMP');
            $timestamp = $instant->getTimestamp();
            if ($previousTimestamp !== null && $timestamp < $previousTimestamp) {
                throw new InvalidArgumentException('INVALID_PUNCH_ORDER');
            }
            if ($firstTimestamp === null || $timestamp - $firstTimestamp > $windowSeconds) {
                $groups[] = [];
                $firstTimestamp = $timestamp;
            }
            $groups[count($groups) - 1][] = $punch;
            $previousTimestamp = $timestamp;
        }
        return $groups;
    }

    /** Read-only diagnostic using the existing classifiers. A singleton probe
     * establishes possible ownership, not a final assignment. Never persists. */
    public function previewCandidatePositions(array $segments, array $punches, ?array $policy, bool $closed, int $physicalStation, int $worker): array
    {
        if ($physicalStation < 1 || $worker < 1 || !$segments) throw new InvalidArgumentException('J1_INVALID_PREVIEW_SCOPE');
        usort($segments, static fn($a, $b) => ((int)$a['ordinal']) <=> ((int)$b['ordinal']));
        usort($punches, static fn($a, $b) => strcmp((string)$a['ocurrido_at_utc'], (string)$b['ocurrido_at_utc']) ?: ((int)$a['id'] <=> (int)$b['id']));
        $eligible = []; $excluded = []; $invalid = [];
        $classify = function (array $events) use ($segments, $policy, $closed, $physicalStation): array {
            if ($policy === null) {
                $result = $this->allocateSegmentSlots($segments, $events, $physicalStation);
                return ['segments'=>$result['segment_results'], 'unassigned'=>$result['unassigned_punches']];
            }
            return self::classifyV11($segments, $events, $policy, $closed);
        };
        $possible = [];
        foreach ($punches as $p) {
            if (!isset($p['id'], $p['ocurrido_at_utc'], $p['id_personal_resuelto']) || !array_key_exists('id_estacion_resuelta', $p) || (int)$p['id_personal_resuelto'] !== $worker || (int)$p['id'] < 1) throw new RuntimeException('J1_INVALID_PREVIEW_PUNCH');
            $id = (int)$p['id'];
            // Probe the existing algorithm with one event solely to check whether
            // it could belong to this journey. The full classification below
            // always receives the complete set of valid events, not a subset
            // chosen to resolve competition.
            $probe = $classify([$p]);
            $inWindow = false;
            foreach ($probe['segments'] as $slot) {
                if ((int)($slot['entry_punch_id'] ?? 0) === $id || (int)($slot['exit_punch_id'] ?? 0) === $id) { $inWindow = true; break; }
            }
            if (!$inWindow) { $excluded[$id] = 'OUTSIDE_CLASSIFIER_WINDOW'; continue; }
            if ((int)($p['id_estacion_resuelta'] ?? 0) < 1) { $invalid[$id] = 'STATION_UNKNOWN'; continue; }
            if ((int)$p['id_estacion_resuelta'] !== $physicalStation) { $excluded[$id] = 'OTHER_PHYSICAL_STATION'; continue; }
            $flags = (string)($p['validation_flags'] ?? '');
            if (strpos($flags, 'INVALID') !== false || strpos($flags, 'UNVERIFIABLE') !== false) { $invalid[$id] = 'VALIDATION_FLAGS'; continue; }
            if (array_key_exists('processing_status', $p) && $p['processing_status'] !== 'RECEIVED') { $invalid[$id] = 'PROCESSING_STATUS_UNVERIFIED'; continue; }
            $eligible[] = $p; $possible[$id] = true;
        }
        $full = $classify($eligible);
        $primary = [];
        foreach ($full['segments'] as $slot) {
            foreach (['ENTRY'=>'entry_punch_id', 'EXIT'=>'exit_punch_id'] as $role=>$field) {
                if ($slot[$field] !== null) $primary[(int)$slot[$field]] = ['ordinal'=>(int)$slot['ordinal'], 'role'=>$role];
            }
        }
        $possibleIds = array_keys($possible); sort($possibleIds, SORT_NUMERIC);
        ksort($primary, SORT_NUMERIC); ksort($excluded, SORT_NUMERIC); ksort($invalid, SORT_NUMERIC);
        return ['algorithm'=>$policy === null ? 'V1.0' : 'V1.1', 'policy'=>$policy, 'closed_without_competition_check'=>$closed,
            'possible_punch_ids'=>$possibleIds, 'primary_positions'=>$primary, 'excluded'=>$excluded, 'invalid'=>$invalid,
            'classification'=>$full, 'diagnostic_only'=>true];
    }

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
    public function pairScheduledShift(int $scheduledShiftId, bool $requireV11 = false, ?array $coverageAuthorizationContext = null): array
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
        (new JourneyMariaDbLock($this->db))->assertOwned($workerId, $stationId, (string)$shift['fecha_jornada_local']);
        require_once __DIR__ . '/JourneyV11OperationalContext.php';
        if ((new JourneyV11OperationalContext($this->db))->policy($stationId, (string)$shift['fecha_jornada_local']) !== null) {
            return $this->pairV11ScheduledShift($shift, $coverageAuthorizationContext);
        }
        if ($requireV11) throw new RuntimeException('J1_BATCH_LEGACY_NOT_VERIFIED');
        // ScheduledShift stores the canonical locality ID. Raw PunchEvents
        // store the physical tb_estaciones ID resolved at capture time.
        $physicalStationId = WorkplacePhysicalStationResolver::resolve($this->db, $stationId);
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
        $l2Status = $this->evaluateLevel2Completeness($physicalStationId, $workDate);

        // 3. Handle non-scheduled day states (DAY_OFF, UNSCHEDULED, UNKNOWN)
        if ($tipoJornada !== 'SCHEDULED' || empty($segments)) {
            return $this->pairNonScheduledShift($shift, $segments, $l2Status, $physicalStationId);
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
        $fingerprint = $this->calculateFingerprint($scheduledShiftId, $physicalStationId, $punches, $l2Status);

        // 7. Allocate using historical rules, but do not take events held
        // by an active V1.1 revision of another journey.
        $pairingResults = $this->allocateSegmentSlots($segments, $punches, $physicalStationId ?? 0);
        $claimed=[];
        foreach ($pairingResults['segment_results'] as $segment) foreach ($segment['associated_punches'] as $a) if (in_array($a['role'],['ENTRY','EXIT','SURPLUS'],true)) $claimed[]=(int)$a['punch_id'];
        if ($claimed) {
            $context=new JourneyV11OperationalContext($this->db);
            $claims=JourneyV11EvaluationPlan::conflictingClaims($context->activeClaims($workerId,$windowStart,$windowEnd),$stationId,$workDate,$claimed);
            foreach ($claims as $claim) if ($claim['version_algoritmo']==='v2.5-p1-v11') throw new RuntimeException('J1_ACTIVE_V11_REVISION_COMPETITION');
        }
        $existingHeader = $this->findExistingExecutionByFingerprint($scheduledShiftId, $fingerprint);
        if ($existingHeader !== null) return $existingHeader;

        $overallStatus = $pairingResults['overall_status'];
        if ($l2Status !== 'VERIFIED' && $overallStatus !== 'WORKPLACE_MISMATCH' && $overallStatus !== 'AMBIGUOUS_PAIRING') {
            $overallStatus = 'PENDING_SYNC_COMPLETION';
        }

        // 9. Atomic DB Transaction to persist execution revision
        $this->db->begin_transaction();
        try {
            return $this->persistScheduledResult($shift, $pairingResults, $overallStatus, $l2Status, $fingerprint, $this->algorithmVersion);
        } catch (Throwable $ex) {
            $this->db->rollback();
            throw $ex;
        }
    }

    /** Deliberately disabled by default. Do not enable before integrated
     * MariaDB validation and approval of the remaining ambiguous cases. */
    public static function v11PersistenceEnabled(): bool
    {
        return PHP_SAPI === 'cli'
            && getenv('CHECADOR_J1_V11_PERSISTENCE_ENABLED') === '1'
            && getenv('CHECADOR_JOURNEY_CLI_EXECUTION_ENABLED') === '1';
    }

    private function pairV11ScheduledShift(array $shift, ?array $coverageAuthorizationContext = null): array
    {
        if (!self::v11PersistenceEnabled()) throw new RuntimeException('J1_V11_PERSISTENCE_DISABLED');
        $worker=(int)$shift['id_personal']; $station=(int)$shift['id_estacion']; $date=(string)$shift['fecha_jornada_local'];
        (new JourneyMariaDbLock($this->db))->assertOwned($worker,$station,$date);
        if (!$this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') || !$this->db->begin_transaction(MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT)) throw new RuntimeException('J1_SNAPSHOT_UNAVAILABLE');
        try {
            $plan=(new JourneyV11OperationalContext($this->db, $coverageAuthorizationContext))->preparePairingEvaluation($worker,$station,$date);
            if (!$plan['eligible_for_writer']) throw new RuntimeException('J1_V11_EVALUATION_BLOCKED');
            if (in_array($plan['reason'],['UP_TO_DATE','P2_MISSING_OR_STALE'],true)) {
                $existing=$this->findExistingExecutionByFingerprint((int)$shift['id'],$plan['fingerprint'],true);
                if (!$existing || (int)$existing['pairing_id']!==(int)$plan['latest_pairing']['id']) throw new RuntimeException('J1_ACTIVE_REVISION_CHANGED');
                if (!$this->db->commit()) throw new RuntimeException('J1_PERSISTENCE_FAILED');
                return $existing;
            }
            return $this->persistScheduledResult($shift,$plan['pairing_results'],$plan['pairing_results']['overall_status'],$plan['level2_status'],$plan['fingerprint'],$plan['algorithm_version']);
        } catch (Throwable $ex) {
            $this->db->rollback();
            throw $ex;
        }
    }

    /** Single existing writer for both algorithms. Caller owns transaction and worker lock. */
    private function persistScheduledResult(array $shift, array $pairingResults, string $overallStatus, string $l2Status, string $fingerprint, string $algorithmVersion): array
    {
        $scheduledShiftId=(int)$shift['id']; $workerId=(int)$shift['id_personal'];
        $stationId=(int)$shift['id_estacion']; $workDate=(string)$shift['fecha_jornada_local'];
        (new JourneyMariaDbLock($this->db))->assertOwned($workerId,$stationId,$workDate);
        // Get next revision number
        $revStmt = $this->db->prepare("SELECT COALESCE(MAX(revision_numero), 0) + 1 AS next_rev FROM op_rh_v2_pareos_jornada WHERE id_jornada_programada = ? FOR UPDATE");
        $revStmt->bind_param('i', $scheduledShiftId);
        if (!$revStmt->execute()) throw new RuntimeException("J1_PERSISTENCE_FAILED");
        $revRes = $revStmt->get_result();
        $nextRevision = (int)($revRes->fetch_assoc()['next_rev'] ?? 1);

        // Insert Header
        $hStmt = $this->db->prepare(
            "INSERT INTO op_rh_v2_pareos_jornada
                (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo, estado_completitud_l2, estado_pareo, fingerprint_ejecucion, creado_at_utc)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        $hStmt->bind_param(
            'iiisissss',
            $scheduledShiftId,
            $workerId,
            $stationId,
            $workDate,
            $nextRevision,
            $algorithmVersion,
            $l2Status,
            $overallStatus,
            $fingerprint
        );
        if (!$hStmt->execute()) throw new RuntimeException("J1_PERSISTENCE_FAILED");
        $headerId = (int)$this->db->insert_id;

        // Insert Segment Results and Punch Associations
        foreach ($pairingResults['segment_results'] as $segRes) {
            $ordinal = (int)$segRes['ordinal'];
            $entryPunchId = $segRes['entry_punch_id'];
            $exitPunchId = $segRes['exit_punch_id'];
            $segStatus = $segRes['status'];

            $sStmt = $this->db->prepare(
                "INSERT INTO op_rh_v2_pareos_segmentos
                    (id_pareo_jornada, ordinal_segmento, id_marcacion_entrada, id_marcacion_salida, estado_segmento, creado_at_utc)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
            );
            $sStmt->bind_param('iiiis', $headerId, $ordinal, $entryPunchId, $exitPunchId, $segStatus);
            if (!$sStmt->execute()) throw new RuntimeException("J1_PERSISTENCE_FAILED");
            $segmentId = (int)$this->db->insert_id;

            // Punch Associations
            foreach ($segRes['associated_punches'] as $assoc) {
                $punchId = (int)$assoc['punch_id'];
                $role = $assoc['role'];

                $aStmt = $this->db->prepare(
                    "INSERT INTO op_rh_v2_pareos_marcaciones_asociadas
                        (id_pareo_jornada, id_pareo_segmento, id_marcacion_cruda, rol_marcacion, creado_at_utc)
                     VALUES (?, ?, ?, ?, UTC_TIMESTAMP())"
                );
                $aStmt->bind_param('iiis', $headerId, $segmentId, $punchId, $role);
                if (!$aStmt->execute()) throw new RuntimeException("J1_PERSISTENCE_FAILED");
            }
        }

        // Unassigned/Surplus Punches outside segments
        foreach ($pairingResults['unassigned_punches'] as $unassigned) {
            $punchId = (int)$unassigned['punch_id'];
            $role = $unassigned['role'];

            $aStmt = $this->db->prepare(
                "INSERT INTO op_rh_v2_pareos_marcaciones_asociadas
                    (id_pareo_jornada, id_pareo_segmento, id_marcacion_cruda, rol_marcacion, creado_at_utc)
                 VALUES (?, NULL, ?, ?, UTC_TIMESTAMP())"
            );
            $aStmt->bind_param('iis', $headerId, $punchId, $role);
            if (!$aStmt->execute()) throw new RuntimeException("J1_PERSISTENCE_FAILED");
        }

        if (!$this->db->commit()) throw new RuntimeException("J1_PERSISTENCE_FAILED");

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
            'algorithm_version' => $algorithmVersion,
            'fingerprint' => $fingerprint,
            'segments' => $pairingResults['segment_results'],
            'creado_at_utc' => $creadoAtUtc,
            'evaluated_candidates' => $evaluatedCandidates
        ];

    }

    /**
     * Evaluate Level 2 Completeness state for a station and work date.
     */
    private function evaluateLevel2Completeness(?int $physicalStationId, string $workDate): string
    {
        if ($physicalStationId === null || $physicalStationId < 1) {
            return 'PENDING_SYNC_COMPLETION';
        }

        $stmt = $this->db->prepare(
            "SELECT window_level2_status FROM op_rh_v2_level2_window_evaluations
             WHERE id_estacion = ? AND DATE(window_from_utc) <= ? AND DATE(window_to_utc) >= ?
             ORDER BY id DESC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('iss', $physicalStationId, $workDate, $workDate);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($row !== null) {
                return $row['window_level2_status'] === 'VERIFIED'
                    ? 'VERIFIED'
                    : 'PENDING_SYNC_COMPLETION';
            }
        }

        // A missing Level 2 evaluation is insufficient source evidence. The
        // frozen journey contract keeps UNKNOWN/INCOMPLETE non-final; absence
        // of the authoritative row must therefore fail closed as pending.
        return 'PENDING_SYNC_COMPLETION';
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
    private function calculateFingerprint(int $shiftId, ?int $physicalStationId, array $punches, string $l2Status): string
    {
        $punchTokens = [];
        foreach ($punches as $p) {
            $punchTokens[] = $p['id'] . ':' . $p['ocurrido_at_utc'];
        }
        $physicalStationToken = $physicalStationId === null ? 'unresolved' : (string) $physicalStationId;
        $rawString = "shift:$shiftId:physical_station:$physicalStationToken:ver:{$this->algorithmVersion}:l2:$l2Status:punches:" . implode(',', $punchTokens);
        return hash('sha256', $rawString);
    }

    /**
     * Find existing execution header by fingerprint for idempotency.
     */
    private function findExistingExecutionByFingerprint(int $shiftId, string $fingerprint, bool $latestOnly = false): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, revision_numero, id_personal, id_estacion, fecha_jornada_local, estado_pareo, estado_completitud_l2, version_algoritmo, fingerprint_ejecucion, creado_at_utc
             FROM op_rh_v2_pareos_jornada
             WHERE id_jornada_programada = ? AND fingerprint_ejecucion = ?" . ($latestOnly ? " AND NOT EXISTS (SELECT 1 FROM op_rh_v2_pareos_jornada newer WHERE newer.id_jornada_programada=op_rh_v2_pareos_jornada.id_jornada_programada AND newer.revision_numero>op_rh_v2_pareos_jornada.revision_numero)" : "")
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
    private function pairNonScheduledShift(array $shift, array $segments, string $l2Status, ?int $physicalStationId): array
    {
        $shiftId = (int)$shift['id'];
        $workerId = (int)$shift['id_personal'];
        $stationId = (int)$shift['id_estacion'];
        $workDate = $shift['fecha_jornada_local'];

        $dayStart = "$workDate 00:00:00";
        $dayEnd = "$workDate 23:59:59";

        $punches = $this->fetchCandidatePunches($workerId, $dayStart, $dayEnd);
        $fingerprint = $this->calculateFingerprint($shiftId, $physicalStationId, $punches, $l2Status);

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
                    (id_jornada_programada, id_personal, id_estacion, fecha_jornada_local, revision_numero, version_algoritmo, estado_completitud_l2, estado_pareo, fingerprint_ejecucion, creado_at_utc)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
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
                        (id_pareo_jornada, id_pareo_segmento, id_marcacion_cruda, rol_marcacion, creado_at_utc)
                     VALUES (?, NULL, ?, 'UNMATCHED', UTC_TIMESTAMP())"
                );
                $aStmt->bind_param('ii', $headerId, $punchId);
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
