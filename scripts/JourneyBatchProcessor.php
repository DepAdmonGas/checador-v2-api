<?php
declare(strict_types=1);

/** Bounded, repeating reconciliation of scheduled journey identities. No domain writes here. */
final class JourneyBatchProcessor
{
    public const FLOOR = '2026-01-01'; // ScheduleResolutionService historical boundary.

    public static function dateValid(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) return false;
        [$y,$m,$d]=array_map('intval',explode('-',$value));
        return checkdate($m,$d,$y) && $value >= self::FLOOR;
    }

    /** A cycle freezes its station/date bounds; new or changed facts are revisited in subsequent cycles. */
    public static function start(array $bounds): array
    {
        if (!$bounds) return ['bounds'=>[], 'date'=>null, 'index'=>0, 'worker'=>0];
        return ['bounds'=>array_values($bounds), 'date'=>max(array_column($bounds,'to')), 'index'=>0, 'worker'=>0];
    }

    /** fetch(station,date,afterWorker,limit) returns strictly ascending distinct worker IDs. */
    public static function page(array $cursor, callable $fetch, int $limit=100, int $maxPartitions=64): array
    {
        if ($limit<1 || $maxPartitions<1) throw new InvalidArgumentException('BATCH_INVALID_LIMIT');
        $bounds=$cursor['bounds']; $items=[]; $partitions=0;
        if (!$bounds) return ['items'=>[], 'next'=>null];
        $floor=min(array_column($bounds,'from'));
        while ($cursor['date'] >= $floor && count($items)<$limit && $partitions<$maxPartitions) {
            $b=$bounds[$cursor['index']]; $date=$cursor['date']; $partitions++;
            if ($date >= $b['from'] && $date <= $b['to']) {
                $remaining=$limit-count($items);
                $ids=$fetch($b['station'],$date,$cursor['worker'],$remaining+1);
                if (!is_array($ids) || count($ids)>$remaining+1) throw new RuntimeException('BATCH_INVALID_DISCOVERY_PAGE');
                $last=$cursor['worker'];
                foreach ($ids as $id) {
                    if (!is_int($id) || $id<=$last) throw new RuntimeException('BATCH_INVALID_DISCOVERY_ORDER');
                    $last=$id;
                }
                foreach (array_slice($ids,0,$remaining) as $id) {
                    $items[]=['worker'=>$id,'station'=>$b['station'],'date'=>$date];
                    $cursor['worker']=$id;
                }
                if (count($ids)>$remaining) break;
            }
            $cursor['worker']=0;
            if (++$cursor['index']===count($bounds)) {
                $cursor['index']=0;
                $cursor['date']=(new DateTimeImmutable($date,new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
            }
        }
        return ['items'=>$items,'next'=>$cursor['date']<$floor?null:$cursor];
    }

    /** A failed item is reported and revisited by the next full cycle, not skipped permanently. */
    public static function process(array $items, callable $evaluate): array
    {
        $results=[];
        foreach ($items as $scope) {
            try {
                $result=$evaluate($scope);
                if (!is_array($result) || !isset($result['status'])) throw new RuntimeException('BATCH_INVALID_RESULT');
            } catch (Throwable $e) {
                // Return only bounded domain codes, never SQL text or credentials.
                $code=$e->getMessage();
                $safe=preg_match('/\A(?:J1_[A-Z0-9_]+|JOURNEY_EXECUTION_[A-Z0-9_]+|BATCH_[A-Z0-9_]+|WORKPLACE_TIMEZONE_UNRESOLVED)\z/D',$code);
                $result=['status'=>'RETRY_NEXT_CYCLE','reason'=>$safe?$code:'EVALUATION_UNAVAILABLE'];
            }
            $results[]=['scope'=>$scope]+$result;
        }
        return $results;
    }

    public static function decision(array $preview): array
    {
        $p=$preview['plan']??null;
        if (!$p) return ['status'=>'BLOCKED','reason'=>$preview['reason']??'LEGACY_INPUT_CHANGES_NOT_VERIFIED','blockers'=>[($preview['reason']??null)==='J1_V11_NON_SCHEDULED_NOT_VERIFIED'?'J1_V11_NON_SCHEDULED_NOT_VERIFIED':'LEGACY_INPUT_CHANGES_NOT_VERIFIED']];
        if (($p['eligible_for_writer']??false)!==true) return ['status'=>'BLOCKED','reason'=>$p['reason']??'BLOCKED','blockers'=>$p['blockers']??[]];
        if (($p['reason']??null)==='UP_TO_DATE') return ['status'=>'UP_TO_DATE','fingerprint'=>$p['fingerprint']];
        if (!in_array($p['reason']??null,['P1_MISSING','P1_INPUTS_CHANGED','P2_MISSING_OR_STALE'],true)) return ['status'=>'BLOCKED','reason'=>'UNKNOWN_PLAN_REASON'];
        return ['status'=>'READY','reason'=>$p['reason'],'fingerprint'=>$p['fingerprint']];
    }

    /** Called while the shared worker lock is held, before any Level 2 or J1 write. */
    public static function requireExecutablePreview(array $preview): array
    {
        $decision = self::decision($preview);
        if (!in_array($decision['status'], ['READY', 'UP_TO_DATE'], true)) {
            throw new RuntimeException('BATCH_PREFLIGHT_BLOCKED');
        }
        return $decision;
    }

    /** Return the complete scheduled journey window in strict UTC. Split and
     * overnight segments use their persisted/resolved UTC boundaries. */
    public static function level2Window(array $segments): array
    {
        if ($segments === []) throw new RuntimeException('BATCH_LEVEL2_WINDOW_UNKNOWN');
        $starts=[]; $ends=[];
        foreach ($segments as $segment) {
            $from=$segment['scheduled_start_utc']??null; $to=$segment['scheduled_end_utc']??null;
            if (!is_string($from)||!is_string($to)) throw new RuntimeException('BATCH_LEVEL2_WINDOW_UNKNOWN');
            $a=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$from,new DateTimeZone('UTC'));
            $b=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$to,new DateTimeZone('UTC'));
            if (!$a||!$b||$a->format('Y-m-d H:i:s')!==$from||$b->format('Y-m-d H:i:s')!==$to||$b<=$a) throw new RuntimeException('BATCH_LEVEL2_WINDOW_UNKNOWN');
            $starts[]=$from; $ends[]=$to;
        }
        sort($starts,SORT_STRING); sort($ends,SORT_STRING);
        return ['from_utc'=>$starts[0],'to_utc'=>$ends[count($ends)-1]];
    }

    /** J1 V1.1 closes and reads Level 2 over the whole scheduled interval plus
     * its four-hour technical attribution margins. Keep batch coverage on that
     * exact established interval so J1 cannot miss a newly evaluated row. */
    public static function level2EvidenceWindow(array $segments): array
    {
        $scheduled=self::level2Window($segments);
        $from=(new DateTimeImmutable($scheduled['from_utc'],new DateTimeZone('UTC')))->modify('-4 hours');
        $to=(new DateTimeImmutable($scheduled['to_utc'],new DateTimeZone('UTC')))->modify('+4 hours');
        return ['from_utc'=>$from->format('Y-m-d H:i:s'),'to_utc'=>$to->format('Y-m-d H:i:s')];
    }

    /** Stored L2 results are reusable only for the same physical station,
     * exact UTC interval, coverage policy and relevant device generations,
     * with no later persisted source evidence. */
    public static function shouldEvaluateLevel2(?array $latest, int $physicalStation, string $fromUtc, string $toUtc, string $policyRef, array $currentGenerations, ?string $latestInputReceivedUtc, string $nowUtc): bool
    {
        if ($latest===null) return true;
        foreach (['VERIFIED','UNKNOWN','INCOMPLETE'] as $valid) {
            if (($latest['window_level2_status']??null)===$valid) { $statusValid=true; break; }
        }
        if (empty($statusValid) || (int)($latest['id_estacion']??0)!==$physicalStation
            || (string)($latest['window_from_utc']??'')>$fromUtc || (string)($latest['window_to_utc']??'')<$toUtc
            || (string)($latest['coverage_policy_ref']??'')!==$policyRef) return true;
        $stored=json_decode((string)($latest['relevant_device_generations_json']??''),true);
        $storedSet=is_array($stored)?self::canonicalGenerationSet($stored):'';
        $currentSet=self::canonicalGenerationSet($currentGenerations);
        if ($storedSet===''||$currentSet===''||$storedSet!==$currentSet) return true;
        $evaluated=(string)($latest['evaluated_at_utc']??'');
        if (!preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/D',$evaluated)) return true;
        if ($latestInputReceivedUtc===null) return false;
        if (strcmp($latestInputReceivedUtc,$evaluated)>0) return true;
        // DATETIME(0) rows can share a second. Re-evaluate an equal-second input
        // once a later cycle proves it arrived at or after the prior evaluation.
        return strcmp($latestInputReceivedUtc,$evaluated)===0 && strcmp($nowUtc,$evaluated)>0;
    }

    public static function level2Status(mixed $evidence): string
    {
        $status=is_array($evidence)?($evidence['window_level2_status']??'UNKNOWN'):'UNKNOWN';
        return in_array($status,['VERIFIED','UNKNOWN','INCOMPLETE'],true)?$status:'UNKNOWN';
    }

    /** An execution may proceed to J1 only after Level 2 produced a persisted
     * decision for the complete journey window. An ephemeral UNKNOWN must not
     * allow an older covering row to be selected by the J1 reader. */
    public static function requirePersistedLevel2(array $result): array
    {
        if (($result['persisted'] ?? false) !== true
            || !is_string($result['evaluation_ref'] ?? null)
            || $result['evaluation_ref'] === ''
            || !in_array($result['window_level2_status'] ?? null, ['VERIFIED','UNKNOWN','INCOMPLETE'], true)) {
            throw new RuntimeException('BATCH_LEVEL2_DECISION_NOT_PERSISTED');
        }
        return $result;
    }

    /** Historical V1.0 keeps its existing path. An open V1.1 window may write
     * only provisional P1/P2; a closed window requires durable Level 2 evidence. */
    public static function validateLevel2BeforeExecution(array $result): array
    {
        if (($result['algorithm_version'] ?? null) === 'V1.0'
            && ($result['skipped'] ?? false) === true
            && ($result['reason'] ?? null) === 'HISTORICAL_POLICY') return $result;
        if (($result['provisional'] ?? false) === true
            && ($result['reason'] ?? null) === 'WINDOW_OPEN'
            && ($result['window_level2_status'] ?? null) === 'UNKNOWN'
            && ($result['persisted'] ?? true) === false) return $result;
        return self::requirePersistedLevel2($result);
    }

    public static function level2WindowClosed(string $toUtc,string $nowUtc): bool
    {
        $to=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$toUtc,new DateTimeZone('UTC'));
        $now=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$nowUtc,new DateTimeZone('UTC'));
        return $to!==false&&$now!==false&&$to->format('Y-m-d H:i:s')===$toUtc&&$now->format('Y-m-d H:i:s')===$nowUtc&&$to<=$now;
    }

    private static function canonicalGenerationSet(array $rows): string
    {
        $normalized=[];
        foreach ($rows as $row) {
            if (!is_array($row)||!isset($row['id_dispositivo'],$row['device_generation'],$row['estado'])) return '';
            $normalized[]=['id_dispositivo'=>(int)$row['id_dispositivo'],'device_generation'=>(string)$row['device_generation'],'estado'=>(string)$row['estado']];
        }
        usort($normalized,static fn($a,$b)=>[$a['id_dispositivo'],$a['device_generation'],$a['estado']]<=>[$b['id_dispositivo'],$b['device_generation'],$b['estado']]);
        return json_encode($normalized,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }
}

/** Private per-mode checkpoint. Lock is held across discovery, evaluation and commit of a page. */
final class JourneyBatchCheckpoint
{
    private $lock;
    private string $file;
    public function __construct(string $dir, string $key)
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D',$key) || !is_dir($dir) || is_link($dir) || fileowner($dir)!==posix_geteuid() || (fileperms($dir)&0022)!==0) throw new RuntimeException('BATCH_STATE_DIRECTORY_DENIED');
        $this->file=$dir.'/journey-batch-'.$key.'.json';
        $path=$this->file.'.lock';
        if (is_link($path) || (file_exists($path) && (!is_file($path) || fileowner($path)!==posix_geteuid() || (fileperms($path)&0077)!==0))) throw new RuntimeException('BATCH_STATE_LOCK_DENIED');
        $old=umask(0077);
        try { $this->lock=fopen($path,'c+b'); } finally { umask($old); }
        if (!$this->lock || !flock($this->lock,LOCK_EX|LOCK_NB)) { if (is_resource($this->lock)) fclose($this->lock); throw new RuntimeException('BATCH_ALREADY_RUNNING'); }
    }
    public function load(): ?array
    {
        if (is_link($this->file)) throw new RuntimeException('BATCH_STATE_DENIED');
        if (!file_exists($this->file)) return null;
        if (!is_file($this->file) || fileowner($this->file)!==posix_geteuid() || (fileperms($this->file)&0077)!==0 || filesize($this->file)>1048576) throw new RuntimeException('BATCH_STATE_DENIED');
        $state=json_decode((string)file_get_contents($this->file),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($state)) throw new RuntimeException('BATCH_STATE_INVALID');
        return $state;
    }
    public function save(array $state): void
    {
        $tmp=tempnam(dirname($this->file),'.journey-batch-');
        if ($tmp===false) throw new RuntimeException('BATCH_STATE_WRITE_FAILED');
        try {
            if (!chmod($tmp,0600)) throw new RuntimeException('BATCH_STATE_WRITE_FAILED');
            $f=fopen($tmp,'wb');
            if (!$f) throw new RuntimeException('BATCH_STATE_WRITE_FAILED');
            try {
                $json=json_encode($state,JSON_THROW_ON_ERROR);
                if (fwrite($f,$json)!==strlen($json) || !fflush($f) || (function_exists('fsync')&&!fsync($f))) throw new RuntimeException('BATCH_STATE_WRITE_FAILED');
            } finally { fclose($f); }
            if (!rename($tmp,$this->file)) throw new RuntimeException('BATCH_STATE_WRITE_FAILED');
        } finally { if (is_file($tmp)) unlink($tmp); }
    }
    public function close(): void { if (is_resource($this->lock)) { flock($this->lock,LOCK_UN); fclose($this->lock); } }
    public function __destruct() { $this->close(); }
}
