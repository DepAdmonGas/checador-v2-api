<?php
declare(strict_types=1);
require_once __DIR__.'/../scripts/JourneyBatchProcessor.php';

$checks=0;
function l2Check(bool $ok,string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL '.$name); echo 'PASS '.$name.PHP_EOL; $checks++; }
$segments=[
    ['ordinal'=>1,'scheduled_start_utc'=>'2026-11-27 06:00:00','scheduled_end_utc'=>'2026-11-27 10:00:00'],
    ['ordinal'=>2,'scheduled_start_utc'=>'2026-11-27 12:00:00','scheduled_end_utc'=>'2026-11-27 16:00:00'],
];
$window=JourneyBatchProcessor::level2Window($segments);
l2Check($window===['from_utc'=>'2026-11-27 06:00:00','to_utc'=>'2026-11-27 16:00:00'],'split journey uses complete UTC window');
$evidenceWindow=JourneyBatchProcessor::level2EvidenceWindow($segments);
l2Check($evidenceWindow===['from_utc'=>'2026-11-27 02:00:00','to_utc'=>'2026-11-27 20:00:00'],'Level 2 window matches J1 four-hour attribution margins');
$night=JourneyBatchProcessor::level2Window([['ordinal'=>1,'scheduled_start_utc'=>'2026-11-27 23:00:00','scheduled_end_utc'=>'2026-11-28 07:00:00']]);
l2Check($night['from_utc']==='2026-11-27 23:00:00'&&$night['to_utc']==='2026-11-28 07:00:00','overnight boundary remains UTC across local midnight');
try { JourneyBatchProcessor::level2Window([['ordinal'=>1,'scheduled_start_utc'=>'bad','scheduled_end_utc'=>'2026-11-28 07:00:00']]); l2Check(false,'invalid UTC fails closed'); } catch (RuntimeException $e) { l2Check($e->getMessage()==='BATCH_LEVEL2_WINDOW_UNKNOWN','invalid UTC fails closed'); }
$base=['id_estacion'=>4,'window_from_utc'=>$evidenceWindow['from_utc'],'window_to_utc'=>$evidenceWindow['to_utc'],'coverage_policy_ref'=>'POLICY_V1.0_DEFAULT','relevant_device_generations_json'=>'[{"id_dispositivo":7,"device_generation":"g1","estado":"ACTIVE"}]','window_level2_status'=>'VERIFIED','delivery_completeness'=>'VERIFIED','operational_coverage'=>'VERIFIED','time_window_attribution'=>'VERIFIED','evaluated_at_utc'=>'2026-11-27 21:00:00'];
$gen=[['id_dispositivo'=>7,'device_generation'=>'g1','estado'=>'ACTIVE']];
$l2From=$evidenceWindow['from_utc']; $l2To=$evidenceWindow['to_utc'];
l2Check(!JourneyBatchProcessor::shouldEvaluateLevel2($base,4,$l2From,$l2To,'POLICY_V1.0_DEFAULT',$gen,'2026-11-27 20:59:59','2026-11-27 22:00:00'),'stable verified evidence is reused idempotently');
$broader=array_replace($base,['window_from_utc'=>'2026-11-27 00:00:00','window_to_utc'=>'2026-11-28 00:00:00']);
l2Check(!JourneyBatchProcessor::shouldEvaluateLevel2($broader,4,$l2From,$l2To,'POLICY_V1.0_DEFAULT',$gen,'2026-11-27 20:59:59','2026-11-27 22:00:00'),'existing complete wider window is reused without another persisted evaluation');
l2Check(JourneyBatchProcessor::shouldEvaluateLevel2(null,4,$l2From,$l2To,'POLICY_V1.0_DEFAULT',$gen,null,'2026-11-27 22:00:00'),'absent evaluation is eligible for first evaluation');
l2Check(JourneyBatchProcessor::shouldEvaluateLevel2($base,5,$l2From,$l2To,'POLICY_V1.0_DEFAULT',$gen,null,'2026-11-27 22:00:00'),'station isolation rejects reuse from another physical station');
l2Check(JourneyBatchProcessor::shouldEvaluateLevel2($base,4,$l2From,$l2To,'POLICY_V1.0_DEFAULT',[['id_dispositivo'=>8,'device_generation'=>'g2','estado'=>'ACTIVE']],null,'2026-11-27 22:00:00'),'changed relevant generation triggers reevaluation');
l2Check(JourneyBatchProcessor::shouldEvaluateLevel2($base,4,$l2From,$l2To,'POLICY_V1.0_DEFAULT',$gen,'2026-11-27 21:30:00','2026-11-27 22:00:00'),'late-arriving telemetry or punch triggers reevaluation');
l2Check(!JourneyBatchProcessor::shouldEvaluateLevel2($base,4,$l2From,$l2To,'POLICY_V1.0_DEFAULT',$gen,'2026-11-27 21:00:00','2026-11-27 21:00:00'),'same-second source is deferred until a later cycle');
l2Check(JourneyBatchProcessor::shouldEvaluateLevel2($base,4,$l2From,$l2To,'OTHER_POLICY',$gen,null,'2026-11-27 22:00:00'),'coverage policy change invalidates prior result');
l2Check(JourneyBatchProcessor::level2Status(['window_level2_status'=>'VERIFIED'])==='VERIFIED','complete coverage keeps VERIFIED');
l2Check(JourneyBatchProcessor::level2Status(['window_level2_status'=>'INCOMPLETE'])==='INCOMPLETE','known coverage gap keeps INCOMPLETE');
l2Check(JourneyBatchProcessor::level2Status(null)==='UNKNOWN'&&JourneyBatchProcessor::level2Status([])==='UNKNOWN','missing or malformed evidence remains UNKNOWN');
l2Check(!JourneyBatchProcessor::level2WindowClosed('2026-11-27 18:00:00','2026-11-27 17:59:59'),'open journey window is never evaluated as closed');
try { JourneyBatchProcessor::requirePersistedLevel2(['window_level2_status'=>'UNKNOWN','persisted'=>false]); l2Check(false,'ephemeral unknown cannot fall through to stale J1 evidence'); }
catch (RuntimeException $e) { l2Check($e->getMessage()==='BATCH_LEVEL2_DECISION_NOT_PERSISTED','ephemeral unknown cannot fall through to stale J1 evidence'); }
l2Check(JourneyBatchProcessor::requirePersistedLevel2(['window_level2_status'=>'UNKNOWN','persisted'=>true,'evaluation_ref'=>'eval-1'])['window_level2_status']==='UNKNOWN','persisted UNKNOWN remains explicit and usable by J1');
l2Check(JourneyBatchProcessor::validateLevel2BeforeExecution(['algorithm_version'=>'V1.0','skipped'=>true,'reason'=>'HISTORICAL_POLICY'])['algorithm_version']==='V1.0','historical V1.0 bypasses automatic V1.1 Level 2 refresh');
l2Check(JourneyBatchProcessor::validateLevel2BeforeExecution(['window_level2_status'=>'UNKNOWN','reason'=>'WINDOW_OPEN','provisional'=>true,'persisted'=>false])['provisional']===true,'open V1.1 journey may persist provisional J1/J2');
try { JourneyBatchProcessor::validateLevel2BeforeExecution(['window_level2_status'=>'UNKNOWN','reason'=>'EVIDENCE_ABSENT','persisted'=>false]); l2Check(false,'closed unresolved Level 2 fails before J1'); }
catch (RuntimeException $e) { l2Check($e->getMessage()==='BATCH_LEVEL2_DECISION_NOT_PERSISTED','closed unresolved Level 2 fails before J1'); }

$runtime=file_get_contents(__DIR__.'/../scripts/journey_batch_runtime.php');
$cli=file_get_contents(__DIR__.'/../scripts/journey_processor.php');
$shared=file_get_contents(__DIR__.'/../modelo/v2/journey/JourneySharedExecutionService.php');
$plan=file_get_contents(__DIR__.'/../modelo/v2/journey/JourneyV11EvaluationPlan.php');
l2Check(str_contains($runtime,'$service->evaluarVentanaNivel2($db,$physical,$from,$to,$policyRef)'),'batch execute reuses the canonical Level2 evaluator');
l2Check(str_contains($runtime,'JourneySharedExecutionService($db))->evaluate($w,$s,$d,true,')&&str_contains($shared,'$beforeEvaluation($worker, $station, $date)')&&strpos($shared,'$beforeWrites($worker, $station, $date)')<strpos($shared,'$beforeEvaluation($worker, $station, $date)'),'batch preflight precedes Level2 inside worker lock');
l2Check(str_contains($runtime,'if (!$execute)')&&str_contains($runtime,'journeyProcessorDryRun($db,$w,$s,$d,$coverageAuthorizationContext)'),'batch dry-run follows the read-only preview branch with the service scope context');
l2Check(str_contains($runtime,'m.ocurrido_at_utc>=? AND m.ocurrido_at_utc<=?')&&str_contains($runtime,'JourneyBatchProcessor::validateLevel2BeforeExecution(journeyBatchEvaluateLevel2'),'late punch input is scoped to the evaluated window and non-provisional ephemeral results fail closed');
l2Check(str_contains($runtime,'$checkpoint=$execute?new JourneyBatchCheckpoint')&&str_contains($runtime,'if ($checkpoint!==null) $checkpoint->save($state)'),'dry-run does not create or persist checkpoint state');
l2Check(str_contains($plan,"'previews'=>")&&str_contains($plan,'$fingerprintPreviews')&&str_contains($plan,'level2_evidence'),'persisted Level2 coverage state is included in the existing J1 fingerprint');
l2Check(str_contains(file_get_contents(__DIR__.'/../modelo/v2/journey/JourneyV11OperationalContext.php'),"->modify('-4 hours')")&&str_contains(file_get_contents(__DIR__.'/../modelo/v2/journey/JourneyV11OperationalContext.php'),"->modify('+4 hours')"),'J1 consumes the same four-hour evidence interval as batch evaluation');
l2Check(str_contains($runtime,'->policy($workplace,$date)===null')&&str_contains($runtime,"'reason'=>'HISTORICAL_POLICY'")&&str_contains($runtime,'evaluate($w,$s,$d,true,'),'batch requires verified V1.1 and does not reinterpret historical policy');
l2Check(str_contains(file_get_contents(__DIR__.'/../modelo/v2/journey/JourneySharedExecutionService.php'),'calculatePairingRevision((int)$p1[\'pairing_id\'])'),'J2 receives the exact P1 revision returned by the shared writer');
l2Check(str_contains($cli,"getenv('DB_NAME') !== 'bd_admongas'")&&str_contains($cli,'$databaseIdentity[\'database_name\']'),'single-scope CLI execution is confined to the existing local database');
echo "$checks PASS (Level2 batch behavior and wiring; controlled dependencies/source, no MariaDB writes)\n";
