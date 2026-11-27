<?php
declare(strict_types=1);

/** Pure adapter from the existing diagnostic classifier to the existing J1 writer.
 * No database access. Ambiguities not proved resolvable remain blocked. */
final class JourneyV11EvaluationPlan
{
    /** Latest P1 and latest visible P2 claims from other journeys only. */
    public static function conflictingClaims(array $claims, int $station, string $date, array $ids): array
    {
        $out = [];
        $wanted = array_fill_keys(array_map('intval', $ids), true);
        foreach ($claims as $row) {
            if ((int)$row['id_estacion'] === $station && $row['fecha_jornada_local'] === $date) continue;
            if (!isset($wanted[(int)$row['punch_id']])) continue;
            $out[] = $row;
        }
        usort($out, static fn($a, $b) => strcmp(self::encode($a), self::encode($b)));
        return $out;
    }

    /** Guard the shared boundary of adjacent segments. Use the SAME clipped
     * bounds as classifyV11, not unrestricted overlapping technical windows. */
    public static function segmentAlternatives(array $segments, array $events, array $policy, array $possible): array
    {
        usort($segments, static fn($a,$b) => (int)$a['ordinal'] <=> (int)$b['ordinal']);
        $bounds = []; $previousEnd = null;
        $time = static fn($s) => (new DateTimeImmutable($s, new DateTimeZone('UTC')))->getTimestamp();
        foreach ($segments as $i => $s) {
            $start = $time($s['scheduled_start_utc']); $end = $time($s['scheduled_end_utc']);
            if ($end <= $start || ($previousEnd !== null && $start < $previousEnd)) throw new RuntimeException('J1_SEGMENT_ORDER_UNVERIFIED');
            $lo = $start - $policy['early_entry_minutes'] * 60; $hi = $end + 14400;
            if ($previousEnd !== null) $lo = max($lo, intdiv($previousEnd + $start, 2));
            if (isset($segments[$i+1])) $hi = min($hi, intdiv($end + $time($segments[$i+1]['scheduled_start_utc']), 2));
            $bounds[] = [$lo,$hi]; $previousEnd = $end;
        }
        $out = [];
        foreach ($events as $event) {
            $id = (int)$event['id']; if (!in_array($id,$possible,true)) continue;
            $at = $time($event['ocurrido_at_utc']); $count = 0;
            foreach ($bounds as [$lo,$hi]) if ($at >= $lo && $at <= $hi) ++$count;
            if ($count > 1) $out[] = $id;
        }
        sort($out,SORT_NUMERIC); return $out;
    }

    public static function normalize(array $preview): array
    {
        $segments = $preview['classification']['segments'];
        $unassigned = $preview['classification']['unassigned'];
        $seen = []; $ordinals = []; $states = [];
        foreach ($segments as &$s) {
            $ordinal = (int)$s['ordinal'];
            if ($ordinal < 1 || isset($ordinals[$ordinal])) throw new RuntimeException('J1_NORMALIZATION_INVALID');
            $ordinals[$ordinal] = true;
            foreach (['entry_punch_id','exit_punch_id'] as $field) $s[$field] = $s[$field] === null ? null : (int)$s[$field];
            $entry = $s['entry_punch_id']; $exit = $s['exit_punch_id'];
            $roles = [];
            foreach ($s['associated_punches'] as &$a) {
                $id = (int)$a['punch_id']; $a['punch_id'] = $id;
                if ($id < 1 || isset($seen[$id]) || !in_array($a['role'],['ENTRY','EXIT','SURPLUS','AMBIGUOUS'],true)) throw new RuntimeException('J1_NORMALIZATION_INVALID');
                $seen[$id] = true; $roles[$id] = $a['role'];
                if (($a['role']==='ENTRY' && $entry!==$id) || ($a['role']==='EXIT' && $exit!==$id)) throw new RuntimeException('J1_NORMALIZATION_INVALID');
            }
            unset($a);
            if (($entry!==null && ($roles[$entry]??null)!=='ENTRY') || ($exit!==null && ($roles[$exit]??null)!=='EXIT')) throw new RuntimeException('J1_NORMALIZATION_INVALID');
            $expected = $entry!==null ? ($exit!==null?'EXACT_PAIR':'PARTIAL_MISSING_OUT') : ($exit!==null?'PARTIAL_MISSING_IN':'UNMATCHED_NO_PUNCHES');
            if ($s['status'] !== $expected && !($s['status']==='AMBIGUOUS_PAIRING' && $entry===null && $exit===null)) throw new RuntimeException('J1_NORMALIZATION_INVALID');
            $states[] = $s['status'];
        }
        unset($s);
        foreach ($unassigned as &$a) {
            $id=(int)$a['punch_id']; $a['punch_id']=$id;
            if ($id<1 || isset($seen[$id]) || $a['role']!=='UNMATCHED') throw new RuntimeException('J1_NORMALIZATION_INVALID');
            $seen[$id]=true;
        }
        unset($a);
        // The preview filters invalid and out-of-window events before the
        // classifier. The writer must still preserve their audit associations.
        $raw = [];
        foreach ($preview['raw_punches'] as $p) {
            $id=(int)$p['id']; if ($id<1 || isset($raw[$id])) throw new RuntimeException('J1_NORMALIZATION_INVALID');
            $raw[$id]=true;
            if (!isset($seen[$id])) { $unassigned[]=['punch_id'=>$id,'role'=>isset($preview['invalid'][$id])?'AMBIGUOUS':'UNMATCHED']; $seen[$id]=true; }
        }
        if (!$states || array_diff_key($seen,$raw)) throw new RuntimeException('J1_NORMALIZATION_INVALID');
        usort($unassigned, static fn($a,$b) => $a['punch_id'] <=> $b['punch_id']);
        // Same aggregation rules as the V1.0 writer; no invented status.
        $unique = array_unique($states);
        if (count($unique)===1) $overall=$unique[0];
        elseif (in_array('AMBIGUOUS_PAIRING',$states,true) || (in_array('PARTIAL_MISSING_IN',$states,true)&&in_array('PARTIAL_MISSING_OUT',$states,true))) $overall='AMBIGUOUS_PAIRING';
        elseif (in_array('PARTIAL_MISSING_IN',$states,true)) $overall='PARTIAL_MISSING_IN';
        elseif (in_array('PARTIAL_MISSING_OUT',$states,true)) $overall='PARTIAL_MISSING_OUT';
        else $overall='AMBIGUOUS_PAIRING';
        return ['overall_status'=>$overall,'segment_results'=>$segments,'unassigned_punches'=>$unassigned];
    }

    public static function build(array $diagnostic, int $worker, int $station, string $date, array $claims, ?array $latest, ?array $latestP2, ?array $p2ForLatest = null): array
    {
        $key="$worker:$station:$date";
        $target=$diagnostic['journey_previews'][$key]??null;
        if (!$target || $target['algorithm']!=='V1.1' || $target['policy']===null) throw new RuntimeException('J1_V11_TARGET_UNAVAILABLE');
        $segments=[]; $candidateFacts=[];
        foreach ($diagnostic['candidate_segments'] as $id=>$row) {
            if ($row['worker']===$worker && $row['station']===$station && $row['date']===$date) $segments[]=$row['segment'];
            // Materialization itself must not change identity of identical facts.
            unset($row['materialized']); $candidateFacts[$id]=$row;
        }
        if (!$segments) throw new RuntimeException('J1_V11_TARGET_UNAVAILABLE');
        $normalized=self::normalize($target);
        $summary=$diagnostic['position_summary'];
        $ids=$target['possible_punch_ids'];
        $active=self::conflictingClaims($claims,$station,$date,$ids);
        $alternatives=self::segmentAlternatives($segments,$target['raw_punches'],$target['policy'],$ids);
        $possible=array_values(array_intersect($ids,$summary['possible_competition_ids'])); sort($possible,SORT_NUMERIC);
        $blockers=[];
        if (($target['window_started']??false)!==true) $blockers[]='J1_WINDOW_NOT_OPEN';
        if ($possible) $blockers[]='J1_POSSIBLE_CROSS_JOURNEY_COMPETITION';
        if ($active) $blockers[]='J1_ACTIVE_REVISION_COMPETITION';
        if ($alternatives) $blockers[]='J1_CROSS_SEGMENT_ALTERNATIVES';
        if ($target['invalid']) $blockers[]='J1_INVALID_CANDIDATE_EVENTS';
        // Historical writers can claim events even while reporting station or
        // validation mismatch. Do not assert cross-version ownership from the
        // filtered diagnostic alone. This is a block, not a definitive conflict.
        foreach ($diagnostic['journey_previews'] as $otherKey=>$other) {
            if ($otherKey===$key || $other['algorithm']!=='V1.0') continue;
            if (array_intersect($ids,array_map(static fn($r)=>(int)$r['id'],$other['raw_punches']))) { $blockers[]='J1_MIXED_VERSION_OWNERSHIP_UNVERIFIED'; break; }
        }
        foreach ($target['excluded'] as $id=>$reason) {
            if ($reason!=='OTHER_PHYSICAL_STATION') continue;
            $elsewhere=false;
            foreach ($diagnostic['journey_previews'] as $otherKey=>$other) if ($otherKey!==$key && in_array((int)$id,$other['possible_punch_ids'],true)) { $elsewhere=true; break; }
            if (!$elsewhere) { $blockers[]='J1_WORKPLACE_MISMATCH_UNRESOLVED'; break; }
        }
        // Other candidate journeys with invalid evidence may hide competition.
        if ($summary['invalid_events']) $blockers[]='J1_COMPETING_EVENT_EVIDENCE_UNVERIFIED';
        $closed=$target['closed_without_competition_check'] && !$blockers;
        $l2=($target['level2_evidence']['window_level2_status']??null)==='VERIFIED' && $target['closed_without_competition_check'] ? 'VERIFIED':'PENDING_SYNC_COMPLETION';
        if ($l2!=='VERIFIED' && !in_array($normalized['overall_status'],['AMBIGUOUS_PAIRING','WORKPLACE_MISMATCH'],true)) $normalized['overall_status']='PENDING_SYNC_COMPLETION';
        $fingerprintPreviews=$diagnostic['journey_previews'];
        foreach ($fingerprintPreviews as &$p) {
            // Level2ServerService creates a random evaluation_ref every time.
            // Its state, coverage policy and covered interval affect pairing;
            // a new reference for otherwise identical evidence does not.
            if (is_array($p['level2_evidence'])) unset($p['level2_evidence']['evaluation_ref']);
        }
        unset($p);
        $fingerprint=hash('sha256',self::encode(['version'=>'v2.5-p1-v11','target'=>$key,'candidates'=>$candidateFacts,'previews'=>$fingerprintPreviews,'active_other_claims'=>$active,'alternatives'=>$alternatives,'possible'=>$possible,'blockers'=>$blockers,'closed'=>$closed,'normalized'=>$normalized]));
        $same=$latest!==null && ($latest['fingerprint_ejecucion']??null)===$fingerprint && ($latest['version_algoritmo']??null)==='v2.5-p1-v11';
        if ($same && $p2ForLatest && (int)($latestP2['id_pareo_jornada']??0)!==(int)$latest['id']) $blockers[]='J1_P2_HISTORICAL_REUSE_UNVERIFIED';
        $reason=$blockers?'BLOCKED':(!$latest?'P1_MISSING':(!$same?'P1_INPUTS_CHANGED':((int)($latestP2['id_pareo_jornada']??0)!==(int)$latest['id']?'P2_MISSING_OR_STALE':'UP_TO_DATE')));
        return ['algorithm_version'=>'v2.5-p1-v11','policy'=>$target['policy'],'worker'=>$worker,'station'=>$station,'date'=>$date,'level2_status'=>$l2,'closed'=>$closed && !$blockers,'possible_competition_ids'=>$possible,'active_revision_conflicts'=>$active,'cross_segment_alternative_ids'=>$alternatives,'blockers'=>$blockers,'eligible_for_writer'=>!$blockers,'pairing_results'=>$normalized,'fingerprint'=>$fingerprint,'latest_pairing'=>$latest,'latest_calculation'=>$latestP2,'reason'=>$reason,'raw_punches'=>$target['raw_punches']];
    }

    private static function encode(array $value): string
    {
        $canonical=static function ($v) use (&$canonical) {
            if (!is_array($v)) return $v;
            if (!array_is_list($v)) ksort($v,SORT_STRING);
            foreach ($v as &$x) $x=$canonical($x); unset($x);
            return $v;
        };
        return json_encode($canonical($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
}
