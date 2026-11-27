<?php
declare(strict_types=1);
require_once __DIR__ . '/../modelo/v2/gatekeeper/DeviceEntryWindowV2Service.php';

final class EntryM11TestSchedule extends ScheduleResolutionService {
    public array $days = [];
    public array $calls = [];
    public function __construct() {}
    public function resolveSchedule(int $idPersonal, int $idEstacion, string $fechaLocal): array {
        $this->calls[] = [$idPersonal,$idEstacion,$fechaLocal];
        $day = $this->days[$fechaLocal] ?? ['estado_jornada'=>'UNSCHEDULED'];
        if ($day instanceof Throwable) throw $day;
        return $day;
    }
}
final class EntryM11TestTimezone extends WorkplaceTimezoneAuthorityService {
    public function __construct() {}
    public function resolve(int $idLocalidad, string $fechaLocal): string { return 'America/Mexico_City'; }
}
final class EntryM11TestIdentity extends PunchEventV2 {
    public function __construct() {}
    public function resolverTrabajadorHistorico($conexion, $idDispositivo, $referenciaExterna, $ocurridoAtUtc) {
        return $idDispositivo === 2 && $referenciaExterna === '150' ? 558 : null;
    }
}
final class EntryM11TestService extends DeviceEntryWindowV2Service {
    public array $policies = [];
    public array $policyCalls = [];
    public array $raw = [];
    public bool $failRaw = false;
    public bool $stationAllowed = true;
    public bool $failMapping = false;
    public function __construct(EntryM11TestSchedule $schedule) {
        parent::__construct(mysqli_init(), $schedule, new EntryM11TestTimezone(), new EntryM11TestIdentity());
    }
    protected function resolveCanonicalWorkplace(int $physicalStation): int {
        if ($this->failMapping) throw new RuntimeException('mapping missing');
        if ($physicalStation !== 8) throw new RuntimeException('wrong physical station');
        return 1;
    }
    protected function verifyWorkerStationBelonging(int $idPersonal, int $idEstacion): bool {
        return $this->stationAllowed && $idPersonal === 558 && $idEstacion === 8;
    }
    protected function resolveEntryPolicy(int $workplace, string $date): array {
        $this->policyCalls[] = [$workplace,$date];
        $row = $this->policies[$date] ?? null;
        if ($row instanceof Throwable) throw $row;
        return DeviceEntryPolicyV2::normalize($row);
    }
    protected function fetchCandidatePunchesForWindow(int $idPersonal, int $idEstacion, string $windowStartUtc, string $windowEndUtc): array {
        if ($this->failRaw) throw new RuntimeException('raw read error');
        $rows = array_values(array_filter($this->raw, static fn($p) => $p['ocurrido_at_utc'] >= $windowStartUtc && $p['ocurrido_at_utc'] <= $windowEndUtc));
        $mismatch = false; $invalid = false;
        foreach ($rows as $p) {
            $mismatch = $mismatch || (int)($p['id_estacion_resuelta'] ?? 0) !== $idEstacion;
            $invalid = $invalid || str_contains($p['validation_flags'] ?? '', 'INVALID') || str_contains($p['validation_flags'] ?? '', 'UNVERIFIABLE');
        }
        return ['punches'=>$rows,'has_workplace_mismatch'=>$mismatch,'has_ambiguity'=>$invalid];
    }
}
function m11Row(mixed $minutes, int $version=6, mixed $repeat=5, mixed $priority=60): array {
    return ['id'=>9,'policy_version'=>$version,'early_entry_allowed_minutes'=>$minutes,'duplicate_punch_window_minutes'=>$repeat,'exit_priority_window_minutes'=>$priority];
}
function m11Segment(string $start,string $end,int $offStart=0,int $offEnd=0,string $type='WORK'): array {
    return ['tipo_segmento'=>$type,'hora_inicio_local'=>$start,'hora_fin_local'=>$end,'dia_offset_inicio'=>$offStart,'dia_offset_fin'=>$offEnd];
}
function m11Schedule(array $segments, string $kind='HABITUAL'): array {
    return ['estado_jornada'=>'SCHEDULED','origen'=>['tipo_asignacion'=>$kind],'horario_local'=>['timezone'=>'America/Mexico_City','segmentos'=>$segments]];
}
function m11Punch(string $local, ?int $station=8, string $flags='[]'): array {
    return ['id_estacion_resuelta'=>$station,'ocurrido_at_utc'=>(new DateTimeImmutable($local,new DateTimeZone('America/Mexico_City')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'validation_flags'=>$flags];
}
function m11Evaluate(EntryM11TestService $service,string $local,int $pending=0,?string $last=null,?string $client=null): array {
    return $service->evaluateEntryWindow(2,8,'America/Mexico_City','150',$client,$pending,$last,null,new DateTimeImmutable($local,new DateTimeZone('America/Mexico_City')));
}
$pass=0;
function m11Assert(bool $ok,string $name): void {
    global $pass;
    if (!$ok) throw new RuntimeException('FAIL '.$name);
    echo 'PASS '.$name.PHP_EOL; $pass++;
}
function m11Action(array $r): string { return $r['data']['gatekeeper_action'] ?? ($r['status'] ?? 'MISSING'); }
$date='2026-11-27'; $prev='2026-11-26';
$schedule=new EntryM11TestSchedule(); $service=new EntryM11TestService($schedule);
$schedule->days[$date]=m11Schedule([m11Segment('07:00:00','15:00:00')]);
foreach ([0=>['06:59:59','07:00:00'],5=>['06:54:59','06:55:00'],10=>['06:49:59','06:50:00']] as $minutes=>[$before,$boundary]) {
    $service->policies[$date]=m11Row((string)$minutes);
    $r=m11Evaluate($service,"$date $before");
    m11Assert(m11Action($r)==='REJECT_EARLY_ENTRY' && $r['data']['seconds_until_allowed']===1 && $r['data']['early_entry_allowed_minutes']===$minutes && $r['data']['policy_version']===6,"$minutes minutes: exact second before boundary");
    $r=m11Evaluate($service,"$date $boundary");
    m11Assert(m11Action($r)==='ALLOW_ENTRY' && $r['data']['earliest_allowed_entry_local']===$boundary && $r['data']['workplace_id']===1,"$minutes minutes: exact boundary and canonical mapping");
}
m11Assert(m11Action(m11Evaluate($service,"$date 07:30:00"))==='ALLOW_ENTRY','late entry independent from tolerance');
$service->policies[$date]=m11Row(null,5,null,null);
$r=m11Evaluate($service,"$date 06:50:00");
m11Assert(m11Action($r)==='ALLOW_ENTRY' && $r['data']['policy_version']===5 && $r['data']['early_entry_allowed_minutes']===10,'historical valid row retains 10 minutes');
foreach ([null,m11Row(11),m11Row(-1),m11Row('5.0'),m11Row(5,6,null,60),new RuntimeException('policy SQL failed')] as $i=>$bad) {
    $service->policies[$date]=$bad;
    $r=m11Evaluate($service,"$date 06:50:00");
    m11Assert(m11Action($r)==='SCHEDULE_UNKNOWN' && !$r['data']['should_record_punch'] && !isset($r['data']['early_entry_allowed_minutes']),"unknown/invalid policy case $i");
}
$service->policies[$date]=m11Row(5);
$service->failRaw=true;
m11Assert(m11Action(m11Evaluate($service,"$date 06:54:59"))==='SCHEDULE_UNKNOWN','failed punch lookup not interpreted as empty');
$service->failRaw=false;
$service->failMapping=true;
m11Assert(m11Action(m11Evaluate($service,"$date 06:54:59"))==='SCHEDULE_UNKNOWN','unresolved physical/canonical mapping');
$service->failMapping=false;
$service->stationAllowed=false;
m11Assert(m11Action(m11Evaluate($service,"$date 06:54:59"))==='WORKER_NOT_FOUND','station gate retained');
$service->stationAllowed=true;
$schedule->days[$prev]=m11Schedule([m11Segment('22:00:00','07:00:00',0,1)]);
$service->policies[$prev]=m11Row(10,5);
$service->raw=[m11Punch("$prev 21:55:00")];
$r=m11Evaluate($service,"$date 06:40:00");
m11Assert(m11Action($r)==='ALLOW_EXIT' && $r['data']['fecha_local']===$prev && $r['data']['policy_version']===5,'previous overnight exit before next entry boundary');
$schedule->days[$date]=['estado_jornada'=>'DAY_OFF'];
m11Assert(m11Action(m11Evaluate($service,"$date 09:00:00"))==='ALLOW_EXIT','previous night exit after 08 on day off');
$service->raw=[];
m11Assert(m11Action(m11Evaluate($service,"$date 09:00:00"))==='ALLOW_DAY_OFF','confirmed day off without prior open entry');
$schedule->days[$prev]=['estado_jornada'=>'UNSCHEDULED'];
$schedule->days[$date]=['estado_jornada'=>'UNSCHEDULED'];
m11Assert(m11Action(m11Evaluate($service,"$date 10:00:00"))==='ALLOW_UNSCHEDULED','confirmed unscheduled retains neutral capture');
$schedule->days[$date]=m11Schedule([m11Segment('07:00:00','11:00:00'),m11Segment('11:00:00','13:00:00',0,0,'BREAK'),m11Segment('13:00:00','17:00:00')],'TEMPORAL');
$service->raw=[m11Punch("$date 07:00:00"),m11Punch("$date 11:00:00")];
$r=m11Evaluate($service,"$date 12:54:59");
m11Assert(m11Action($r)==='REJECT_EARLY_ENTRY' && $r['data']['scheduled_start_local']==='13:00:00' && $r['data']['seconds_until_allowed']===1,'split second segment before boundary, not whole shift');
$r=m11Evaluate($service,"$date 12:55:00");
m11Assert(m11Action($r)==='ALLOW_ENTRY' && $r['data']['scheduled_end_local']==='17:00:00','split second segment exact boundary, resolved temporal schedule');
$service->raw=[m11Punch("$date 07:00:00")];
$r=m11Evaluate($service,"$date 12:40:00");
m11Assert(m11Action($r)==='ALLOW_EXIT' && $r['data']['scheduled_end_local']==='11:00:00','open first segment exit protected before second');
$service->raw=[m11Punch("$date 07:00:00"),m11Punch("$date 12:55:00")];
m11Assert(m11Action(m11Evaluate($service,"$date 13:00:00"))==='AMBIGUOUS_DIRECTION','competing earlier open segment not classified silently');
$schedule->days[$date]=m11Schedule([m11Segment('07:00:00','15:00:00')]);
$service->raw=[m11Punch("$date 06:58:00",99)];
m11Assert(m11Action(m11Evaluate($service,"$date 07:00:00"))==='AMBIGUOUS_DIRECTION','different physical station');
$service->raw=[m11Punch("$date 06:58:00",null)];
m11Assert(m11Action(m11Evaluate($service,"$date 07:00:00"))==='AMBIGUOUS_DIRECTION','unmapped event');
$service->raw=[m11Punch("$date 06:58:00",8,'["INVALID"]')];
m11Assert(m11Action(m11Evaluate($service,"$date 07:00:00"))==='AMBIGUOUS_DIRECTION','invalid event');
$service->raw=[];
m11Assert(m11Action(m11Evaluate($service,"$date 08:00:00",1,"$date 07:00:00"))==='ALLOW_EXIT','existing local pending evidence compatible');
m11Assert(m11Action(m11Evaluate($service,"$date 08:00:00",1,null))==='AMBIGUOUS_DIRECTION','pending count alone cannot authorize exit');
m11Assert(m11Action(m11Evaluate($service,"$date 06:55:00",0,null,"$date 06:55:00"))==='ALLOW_ENTRY','client civil time parsed in canonical timezone');
$schedule->days[$prev]=new RuntimeException('schedule SQL failed');
m11Assert(m11Action(m11Evaluate($service,"$date 06:54:59"))==='SCHEDULE_UNKNOWN','previous resolution failure never proves no prior exit');
$schedule->days[$prev]=['estado_jornada'=>'UNSCHEDULED'];
$schedule->days['2026-11-28']=m11Schedule([m11Segment('00:00:00','08:00:00')]);
$service->policies['2026-11-28']=m11Row(0,7);
$r=m11Evaluate($service,"$date 23:59:59");
m11Assert(m11Action($r)==='REJECT_EARLY_ENTRY' && $r['data']['fecha_local']==='2026-11-28' && $r['data']['policy_version']===7 && $r['data']['seconds_until_allowed']===1,'midnight uses next logical date policy');
m11Assert(count(array_filter($service->policyCalls, static fn($call) => $call[0] !== 1))===0,'all policy queries use canonical locality');
$schedule->days['2026-11-28']=['estado_jornada'=>'UNSCHEDULED'];
$schedule->days[$date]=m11Schedule([m11Segment('07:00:00','15:00:00')]);
$service->policies[$date]=m11Row(10);
$service->raw=[m11Punch("$date 06:55:00")];
$repeat=m11Evaluate($service,"$date 06:57:00");
m11Assert(m11Action($repeat)==='ALLOW_PENDING_CLASSIFICATION' && $repeat['data']['is_entry_allowed']===null && $repeat['data']['inferred_direction']===null && $repeat['data']['should_record_punch']===true,'physical repeat retained without direction');
$service->raw=[m11Punch("$date 06:55:00"),m11Punch("$date 06:57:00"),m11Punch("$date 15:00:00")];
m11Assert(m11Action(m11Evaluate($service,"$date 15:02:00"))==='ALLOW_PENDING_CLASSIFICATION','additional exit-window capture retained');
$service->raw=[];
m11Assert(m11Action(m11Evaluate($service,"$date 06:49:59"))==='REJECT_EARLY_ENTRY','early restriction retained for empty history');
// These are domain tests using controlled dependencies, not an HTTP or DB E2E.
echo "$pass PASS (controlled dependencies; zero real database writes)\n";
