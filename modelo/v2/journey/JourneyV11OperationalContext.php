<?php
declare(strict_types=1);

require_once __DIR__ . '/JourneyPairingService.php';
require_once __DIR__ . '/JourneyV11EvaluationPlan.php';
require_once __DIR__ . '/../shift/ScheduleResolutionService.php';
require_once __DIR__ . '/../shift/ShiftWorkerMembershipService.php';
require_once __DIR__ . '/../shift/WorkplaceCoverageFeatureGate.php';
require_once __DIR__ . '/../shift/WorkplaceCoverageService.php';
require_once __DIR__ . '/../WorkplacePhysicalStationResolver.php';

/** Read-only V1.1 evidence. Failure to query is an error, never an empty result. */
final class JourneyV11OperationalContext
{
    public function __construct(private mysqli $db, private ?array $coverageAuthorizationContext = null) {}

    /** The authoritative writer path remains blocked until global ownership,
     * active revision checks and snapshot consistency are integrated. */
    public function discoverCompetingJourneys(int $worker, string $fromUtc, string $toUtc, array $punches): array
    {
        throw new RuntimeException('J1_POSITION_ELIGIBILITY_NOT_VERIFIED');
    }

    /** Read-only diagnostic; never authorizes a write. */
    public function previewCompetingJourneys(int $worker, string $fromUtc, string $toUtc): array
    {
        $utc = new DateTimeZone('UTC');
        $from = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fromUtc, $utc);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $toUtc, $utc);
        if ($worker < 1 || !$from || !$to || $from->format('Y-m-d H:i:s') !== $fromUtc || $to->format('Y-m-d H:i:s') !== $toUtc || $from >= $to) throw new InvalidArgumentException('J1_INVALID_CANDIDATE_WINDOW');
        $start = $from->modify('-4 hours')->modify('-2 days')->format('Y-m-d');
        $end = $to->modify('+4 hours')->modify('+1 day')->format('Y-m-d');
        $stmt = $this->db->prepare('SELECT a.id_estacion, a.dia_semana, a.valido_desde, a.valido_hasta, a.es_activo, s.dia_offset_inicio, s.dia_offset_fin FROM op_rh_v2_turnos_asignaciones a LEFT JOIN op_rh_v2_turnos_segmentos s ON s.id_plantilla = a.id_plantilla WHERE a.id_personal = ? AND a.valido_desde <= ? AND (a.valido_hasta IS NULL OR a.valido_hasta > ?) ORDER BY a.id_estacion, a.id, s.ordinal');
        if (!$stmt) throw new RuntimeException('J1_ASSIGNMENT_READ_FAILED');
        try {
            $stmt->bind_param('iss', $worker, $end, $start);
            if (!$stmt->execute() || !($rows = $stmt->get_result())) throw new RuntimeException('J1_ASSIGNMENT_READ_FAILED');
            $assignments = [];
            while ($row = $rows->fetch_assoc()) {
                foreach (['dia_offset_inicio', 'dia_offset_fin'] as $key) if ($row[$key] !== null && !in_array((int)$row[$key], [0, 1], true)) throw new RuntimeException('J1_UNSUPPORTED_DAY_OFFSET');
                $assignments[] = $row;
            }
        } finally { $stmt->close(); }
        $days = ['LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO','DOMINGO'];
        $keys = [];
        for ($d = new DateTimeImmutable($start, $utc); $d->format('Y-m-d') <= $end; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d'); $weekday = $days[(int)$d->format('N') - 1];
            foreach ($assignments as $a) {
                if ($a['dia_semana'] !== $weekday || $a['valido_desde'] > $date || ($a['valido_hasta'] !== null && $a['valido_hasta'] <= $date) || ((int)$a['es_activo'] !== 1 && ($a['valido_hasta'] === null || $a['valido_hasta'] <= $a['valido_desde']))) continue;
                $keys[(int)$a['id_estacion'] . ':' . $date] = [(int)$a['id_estacion'], $date];
            }
        }
        // Materialized facts have independent authority even if their source
        // assignment was later removed or is no longer returned by the reader.
        $materializedFrom = $from->modify('-4 hours')->format('Y-m-d H:i:s');
        $materializedTo = $to->modify('+4 hours')->format('Y-m-d H:i:s');
        foreach ($this->materializedCandidates($worker, $materializedFrom, $materializedTo) as $row) {
            $station = (int)$row['id_estacion']; $date = (string)$row['fecha_jornada_local'];
            $keys[$station . ':' . $date] = [$station, $date];
        }
        ksort($keys, SORT_STRING);
        $resolver = new ScheduleResolutionService($this->db); $membership = new ShiftWorkerMembershipService($this->db);
        $candidates = []; $windows = []; $previews = [];
        $pairing = new JourneyPairingService($this->db);
        $now = new DateTimeImmutable('now', $utc);
        foreach ($keys as [$station, $date]) {
            $q = $this->db->prepare('SELECT id, tipo_jornada, scheduled_start_utc, scheduled_end_utc, payload_segmentos_json FROM op_rh_v2_jornadas_programadas WHERE id_personal = ? AND id_estacion = ? AND fecha_jornada_local = ?');
            if (!$q) throw new RuntimeException('J1_CANDIDATE_READ_FAILED');
            try { $q->bind_param('iis', $worker, $station, $date); if (!$q->execute() || !($result = $q->get_result())) throw new RuntimeException('J1_CANDIDATE_READ_FAILED'); $materialized = $result->fetch_assoc(); } finally { $q->close(); }
            if ($materialized) {
                if ($materialized['tipo_jornada'] !== 'SCHEDULED') continue;
                $segments = json_decode((string)$materialized['payload_segmentos_json'], true);
                if (!is_array($segments) || !$segments) throw new RuntimeException('J1_MATERIALIZED_SEGMENTS_UNAVAILABLE');
            } else {
                $schedule = $resolver->resolveSchedule($worker, $station, $date);
                $status = $schedule['estado_jornada'] ?? 'UNKNOWN';
                if (in_array($status, ['UNSCHEDULED', 'DAY_OFF'], true)) continue;
                if ($status !== 'SCHEDULED') throw new RuntimeException('J1_SCHEDULE_UNRESOLVED');
                $segments = $schedule['horario_local']['segmentos'] ?? null;
                if (!is_array($segments) || !$segments) throw new RuntimeException('J1_SCHEDULE_UNRESOLVED');
            }
            if ($this->coverageAuthorizationContext !== null && WorkplaceCoverageFeatureGate::isEnabled()) {
                // Resolve each candidate through the existing effective-schedule
                // service. This proves the authorized target and prevents the
                // same worker's home and coverage schedules becoming two candidates.
                $effective = (new WorkplaceCoverageService($this->db))->resolveWorkerEffective(
                    $worker,
                    $date,
                    $this->coverageAuthorizationContext
                );
                $effectiveStatus = (string)($effective['estado_jornada'] ?? 'UNKNOWN');
                $effectiveWorkplace = (int)($effective['effective_workplace_id'] ?? $effective['id_estacion'] ?? 0);
                if ($effectiveStatus === 'UNKNOWN' || $effectiveWorkplace < 1) {
                    throw new RuntimeException('J1_EFFECTIVE_WORKPLACE_UNAVAILABLE');
                }
                if ($effectiveWorkplace !== $station) {
                    if ($materialized) throw new RuntimeException('J1_EFFECTIVE_WORKPLACE_CONFLICT');
                    continue;
                }
                // The resolver validates home membership and the exact linked,
                // approved coverage assignment. Do not assert home membership
                // at a temporary coverage Workplace.
            } else {
                $membership->assertLocalDateMembership($worker, $station, $date);
            }
            $physical = WorkplacePhysicalStationResolver::resolve($this->db, $station);
            if ($physical === null) throw new RuntimeException('J1_STATION_UNRESOLVED');
            $policy = $this->policy($station, $date);
            // Keep ALL segments of a candidate journey, even if only one
            // overlaps the requested interval. The classifiers need the adjacent
            // segment boundaries and all events in the whole journey window.
            $journey = "$worker:$station:$date";
            $journeyWindows = []; $journeyCandidates = []; $overlaps = false;
            foreach ($segments as $segment) {
                if (!isset($segment['ordinal'], $segment['scheduled_start_utc'], $segment['scheduled_end_utc'])) throw new RuntimeException('J1_SEGMENT_UNRESOLVED');
                $s = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $segment['scheduled_start_utc'], $utc);
                $e = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $segment['scheduled_end_utc'], $utc);
                if (!$s || !$e || $s->format('Y-m-d H:i:s') !== $segment['scheduled_start_utc'] || $e->format('Y-m-d H:i:s') !== $segment['scheduled_end_utc'] || $e <= $s) throw new RuntimeException('J1_SEGMENT_UNRESOLVED');
                $ws = $s->modify('-4 hours')->format('Y-m-d H:i:s'); $we = $e->modify('+4 hours')->format('Y-m-d H:i:s');
                if ($we >= $fromUtc && $ws <= $toUtc) $overlaps = true;
                $key = $journey . ':' . (int)$segment['ordinal'];
                if (isset($journeyCandidates[$key])) throw new RuntimeException('J1_SEGMENT_UNRESOLVED');
                $journeyCandidates[$key] = ['worker'=>$worker, 'station'=>$station, 'physical_station'=>$physical, 'date'=>$date, 'segment'=>$segment, 'policy'=>$policy, 'materialized'=>(bool)$materialized];
                $journeyWindows[$key] = ['journey'=>$journey, 'from_utc'=>$ws, 'to_utc'=>$we];
            }
            if (!$overlaps) continue;
            $candidates += $journeyCandidates; $windows += $journeyWindows;
            $windowStart = min(array_column($journeyWindows, 'from_utc'));
            $windowEnd = max(array_column($journeyWindows, 'to_utc'));
            $evidence = $this->level2Evidence($physical, $windowStart, $windowEnd);
            $closed = JourneyPairingService::v11Closure($now, new DateTimeImmutable($windowStart, $utc), new DateTimeImmutable($windowEnd, $utc), $physical, $evidence, false);
            $events = $this->previewPunches($worker, $windowStart, $windowEnd);
            $previews[$journey] = $pairing->previewCandidatePositions($segments, $events, $policy, $closed, $physical, $worker);
            $previews[$journey]['raw_punches'] = array_map(static fn($event) => [
                'id'=>(int)$event['id'], 'event_uuid'=>$event['event_uuid'], 'id_dispositivo'=>(int)$event['id_dispositivo'], 'metodo_captura'=>$event['metodo_captura'], 'ocurrido_at_utc'=>$event['ocurrido_at_utc'],
                'id_estacion_resuelta'=>$event['id_estacion_resuelta'],
                'processing_status'=>$event['processing_status'], 'validation_flags'=>$event['validation_flags']
            ], $events);
            $previews[$journey]['level2_evidence'] = $evidence;
            $previews[$journey]['window_started'] = $now >= new DateTimeImmutable($windowStart, $utc);
            $previews[$journey]['window_from_utc'] = $windowStart;
            $previews[$journey]['window_to_utc'] = $windowEnd;
        }
        ksort($candidates, SORT_STRING); ksort($windows, SORT_STRING);
        // Diagnostic only: no authorization to persist. Singleton probes are
        // deliberately conservative about alternative assignments. An overlap
        // in possible ownership is not declared an actual position conflict.
        ksort($previews, SORT_STRING);
        $summary = self::summarizePositionPreviews($previews);
        return ['diagnostic_only'=>true, 'persistence_authorized'=>false,
            'candidate_segments'=>$candidates, 'windows'=>$windows,
            'journey_previews'=>$previews, 'position_summary'=>$summary,
            'operation'=>'READ_ONLY'];
    }

    /** Called inside a consistent snapshot, owned by the CLI or the writer. */
    public function preparePairingEvaluation(int $worker, int $station, string $date): array
    {
        $utc = new DateTimeZone('UTC');
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $utc);
        if ($worker<1 || $station<1 || !$day || $day->format('Y-m-d')!==$date) throw new InvalidArgumentException('J1_INVALID_PREVIEW_SCOPE');
        $shift = $this->readRows('SELECT id, tipo_jornada, scheduled_start_utc, scheduled_end_utc FROM op_rh_v2_jornadas_programadas WHERE id_personal=? AND id_estacion=? AND fecha_jornada_local=?','iis',[$worker,$station,$date])[0]??null;
        if ($shift) {
            if ($shift['tipo_jornada']!=='SCHEDULED') throw new RuntimeException('J1_V11_TARGET_UNAVAILABLE');
            $start=$shift['scheduled_start_utc']; $end=$shift['scheduled_end_utc'];
        } else {
            $resolved=(new ScheduleResolutionService($this->db))->resolveSchedule($worker,$station,$date);
            if (($resolved['estado_jornada']??null)!=='SCHEDULED') throw new RuntimeException('J1_V11_TARGET_UNAVAILABLE');
            $start=$resolved['horario_utc']['start_utc']??null; $end=$resolved['horario_utc']['end_utc']??null;
        }
        if (!$start || !$end || $start>=$end) throw new RuntimeException('J1_V11_TARGET_UNAVAILABLE');
        $from=(new DateTimeImmutable($start,$utc))->modify('-4 hours')->format('Y-m-d H:i:s');
        $to=(new DateTimeImmutable($end,$utc))->modify('+4 hours')->format('Y-m-d H:i:s');
        // V1.0 neighbor classification parses UTC strings with strtotime.
        $originalTimezone=date_default_timezone_get();
        date_default_timezone_set('UTC');
        try { $diagnostic=$this->previewCompetingJourneys($worker,$from,$to); }
        finally { date_default_timezone_set($originalTimezone); }
        $target=$diagnostic['journey_previews']["$worker:$station:$date"]??null;
        if (!$target) throw new RuntimeException('J1_V11_TARGET_UNAVAILABLE');
        $claims=$this->activeClaims($worker,$target['window_from_utc'],$target['window_to_utc']);
        $latest=null; $latestP2=null; $p2ForLatest=null;
        if ($shift) {
            $latest=$this->readRows('SELECT id, revision_numero, version_algoritmo, fingerprint_ejecucion FROM op_rh_v2_pareos_jornada WHERE id_jornada_programada=? ORDER BY revision_numero DESC LIMIT 1','i',[(int)$shift['id']])[0]??null;
            $latestP2=$this->readRows('SELECT id, id_pareo_jornada, revision_numero FROM op_rh_v2_calculos_jornada WHERE id_jornada_programada=? ORDER BY id DESC LIMIT 1','i',[(int)$shift['id']])[0]??null;
            if ($latest) $p2ForLatest=$this->readRows('SELECT id, id_pareo_jornada FROM op_rh_v2_calculos_jornada WHERE id_pareo_jornada=? ORDER BY id DESC LIMIT 1','i',[(int)$latest['id']])[0]??null;
        }
        return JourneyV11EvaluationPlan::build($diagnostic,$worker,$station,$date,$claims,$latest,$latestP2,$p2ForLatest);
    }

    /** Read latest J1 and the J1 referenced by latest visible J2. Include actual
     * slot columns as well as association rows, without relying on schedule discovery.
     * Portal3 can still display an older J1 while a new J2 is pending. */
    public function activeClaims(int $worker, string $from, string $to): array
    {
        // Scope each UNION branch by the indexed raw worker/time window,
        // instead of collecting all associations across the database.
        $sql = "SELECT DISTINCT p.id AS pairing_id, p.id_estacion, p.fecha_jornada_local, p.version_algoritmo, a.punch_id, a.claim_role
          FROM (
            SELECT s.id_pareo_jornada, m.id AS punch_id, 'ENTRY' AS claim_role FROM op_rh_v2_marcaciones_crudas m JOIN op_rh_v2_pareos_segmentos s ON s.id_marcacion_entrada=m.id WHERE m.id_personal_resuelto=? AND m.ocurrido_at_utc BETWEEN ? AND ?
            UNION ALL SELECT s.id_pareo_jornada, m.id, 'EXIT' FROM op_rh_v2_marcaciones_crudas m JOIN op_rh_v2_pareos_segmentos s ON s.id_marcacion_salida=m.id WHERE m.id_personal_resuelto=? AND m.ocurrido_at_utc BETWEEN ? AND ?
            UNION ALL SELECT a.id_pareo_jornada, m.id, a.rol_marcacion FROM op_rh_v2_marcaciones_crudas m JOIN op_rh_v2_pareos_marcaciones_asociadas a ON a.id_marcacion_cruda=m.id WHERE m.id_personal_resuelto=? AND m.ocurrido_at_utc BETWEEN ? AND ? AND a.rol_marcacion IN ('ENTRY','EXIT','SURPLUS')
          ) a JOIN op_rh_v2_pareos_jornada p ON p.id=a.id_pareo_jornada
          WHERE (
            NOT EXISTS (SELECT 1 FROM op_rh_v2_pareos_jornada newer WHERE newer.id_jornada_programada=p.id_jornada_programada AND newer.revision_numero>p.revision_numero)
            OR EXISTS (SELECT 1 FROM op_rh_v2_calculos_jornada c WHERE c.id_pareo_jornada=p.id AND NOT EXISTS (SELECT 1 FROM op_rh_v2_calculos_jornada c2 WHERE c2.id_jornada_programada=c.id_jornada_programada AND c2.id>c.id))
          ) ORDER BY p.id, a.punch_id, a.claim_role";
        return $this->readRows($sql,'issississ',[$worker,$from,$to,$worker,$from,$to,$worker,$from,$to]);
    }

    private function readRows(string $sql, string $types, array $params): array
    {
        $stmt=$this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('J1_EVALUATION_READ_FAILED');
        try {
            $stmt->bind_param($types,...$params);
            if (!$stmt->execute() || !($res=$stmt->get_result())) throw new RuntimeException('J1_EVALUATION_READ_FAILED');
            $rows=[]; while ($row=$res->fetch_assoc()) $rows[]=$row; return $rows;
        } finally { $stmt->close(); }
    }

    /** Full raw input for each candidate, not only the target journey. */
    private function previewPunches(int $worker, string $fromUtc, string $toUtc): array
    {
        $stmt = $this->db->prepare('SELECT id, event_uuid, id_dispositivo, id_estacion_resuelta, id_personal_resuelto, ocurrido_at_utc, metodo_captura, processing_status, validation_flags FROM op_rh_v2_marcaciones_crudas WHERE id_personal_resuelto = ? AND ocurrido_at_utc BETWEEN ? AND ? ORDER BY ocurrido_at_utc, id');
        if (!$stmt) throw new RuntimeException('J1_PREVIEW_PUNCH_READ_FAILED');
        try {
            $stmt->bind_param('iss', $worker, $fromUtc, $toUtc);
            if (!$stmt->execute() || !($result = $stmt->get_result())) throw new RuntimeException('J1_PREVIEW_PUNCH_READ_FAILED');
            $rows = []; while ($row = $result->fetch_assoc()) $rows[] = $row;
            return $rows;
        } finally { $stmt->close(); }
    }

    /** Do not turn possible alternatives into definite conflicts. */
    public static function summarizePositionPreviews(array $previews): array
    {
        $possible = []; $primary = []; $invalid = [];
        foreach ($previews as $journey => $preview) {
            foreach ($preview['possible_punch_ids'] as $id) $possible[(int)$id][(string)$journey] = true;
            foreach ($preview['primary_positions'] as $id => $position) $primary[(int)$id][(string)$journey] = $position;
            foreach ($preview['invalid'] as $id => $reason) $invalid[(int)$id][(string)$journey] = $reason;
        }
        $potential = []; $actual = [];
        foreach ($possible as $id => $owners) if (count($owners) > 1) $potential[] = $id;
        foreach ($primary as $id => $owners) if (count($owners) > 1) $actual[] = $id;
        sort($potential, SORT_NUMERIC); sort($actual, SORT_NUMERIC); ksort($invalid, SORT_NUMERIC);
        foreach ($invalid as &$owners) ksort($owners, SORT_STRING); unset($owners);
        return ['status'=>!$previews ? 'NO_CANDIDATES' : ($invalid || $potential ? 'INCONCLUSIVE_POSITION_COMPETITION_OR_INVALID_EVENT' : 'NO_COMPETITION_OBSERVED_IN_PREVIEW'),
            'primary_collision_ids'=>$actual, 'possible_competition_ids'=>$potential,
            'invalid_events'=>$invalid, 'active_revision_conflicts_checked'=>false, 'cross_segment_alternatives_checked'=>false];
    }

    /** Conservative overlap detector; callers must supply every eligible window. */
    public static function conflictingPunchIds(array $punches, array $windows): array
    {
        $ids = [];
        foreach ($punches as $punch) {
            if (!isset($punch['id'], $punch['ocurrido_at_utc'])) throw new InvalidArgumentException('J1_INVALID_PUNCH');
            $owners = [];
            foreach ($windows as $window) {
                if (!isset($window['journey'], $window['from_utc'], $window['to_utc']) || $window['from_utc'] > $window['to_utc']) throw new InvalidArgumentException('J1_INVALID_CANDIDATE_WINDOW');
                if ($punch['ocurrido_at_utc'] >= $window['from_utc'] && $punch['ocurrido_at_utc'] <= $window['to_utc']) $owners[(string)$window['journey']] = true;
            }
            if (count($owners) > 1) $ids[(int)$punch['id']] = true;
        }
        $result = array_keys($ids); sort($result, SORT_NUMERIC); return $result;
    }

    public function policy(int $station, string $date): ?array
    {
        $stmt = $this->db->prepare('SELECT id, policy_version, early_entry_allowed_minutes, duplicate_punch_window_minutes, exit_priority_window_minutes FROM op_rh_v2_attendance_policy_versions WHERE workplace_id = ? AND effective_from_local_date <= ? ORDER BY effective_from_local_date DESC, policy_version DESC LIMIT 1');
        if (!$stmt) throw new RuntimeException('J1_POLICY_READ_FAILED');
        try {
            $stmt->bind_param('is', $station, $date);
            if (!$stmt->execute()) throw new RuntimeException('J1_POLICY_READ_FAILED');
            $result = $stmt->get_result();
            if (!$result) throw new RuntimeException('J1_POLICY_READ_FAILED');
            return JourneyPairingService::selectV11Policy($result->fetch_assoc() ?: null);
        } finally { $stmt->close(); }
    }

    public function level2Evidence(int $physicalStation, string $fromUtc, string $toUtc): ?array
    {
        if ($physicalStation < 1 || $fromUtc >= $toUtc) throw new InvalidArgumentException('J1_INVALID_LEVEL2_WINDOW');
        $stmt = $this->db->prepare('SELECT id_estacion, window_from_utc, window_to_utc, window_level2_status, evaluation_ref, coverage_policy_ref, delivery_completeness, operational_coverage, time_window_attribution, relevant_device_generations_json, has_zero_governed_events FROM op_rh_v2_level2_window_evaluations WHERE id_estacion = ? AND window_from_utc <= ? AND window_to_utc >= ? ORDER BY id DESC LIMIT 1');
        if (!$stmt) throw new RuntimeException('J1_LEVEL2_READ_FAILED');
        try {
            $stmt->bind_param('iss', $physicalStation, $fromUtc, $toUtc);
            if (!$stmt->execute()) throw new RuntimeException('J1_LEVEL2_READ_FAILED');
            $result = $stmt->get_result();
            if (!$result) throw new RuntimeException('J1_LEVEL2_READ_FAILED');
            return $result->fetch_assoc() ?: null;
        } finally { $stmt->close(); }
    }

    /** Immutable materialized scheduled shifts overlapping an explicit UTC interval. */
    public function materializedCandidates(int $worker, string $fromUtc, string $toUtc): array
    {
        if ($worker < 1 || $fromUtc >= $toUtc) throw new InvalidArgumentException('J1_INVALID_CANDIDATE_WINDOW');
        $stmt = $this->db->prepare("SELECT id, id_estacion, fecha_jornada_local, tipo_jornada, scheduled_start_utc, scheduled_end_utc, timezone_local, payload_segmentos_json FROM op_rh_v2_jornadas_programadas WHERE id_personal = ? AND tipo_jornada = 'SCHEDULED' AND scheduled_start_utc <= ? AND scheduled_end_utc >= ? ORDER BY fecha_jornada_local, id_estacion, id");
        if (!$stmt) throw new RuntimeException('J1_CANDIDATE_READ_FAILED');
        try {
            $stmt->bind_param('iss', $worker, $toUtc, $fromUtc);
            if (!$stmt->execute()) throw new RuntimeException('J1_CANDIDATE_READ_FAILED');
            $result = $stmt->get_result();
            if (!$result) throw new RuntimeException('J1_CANDIDATE_READ_FAILED');
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $row['effective_policy'] = $this->policy((int)$row['id_estacion'], (string)$row['fecha_jornada_local']);
                $rows[] = $row;
            }
            return $rows;
        } finally { $stmt->close(); }
    }
}
