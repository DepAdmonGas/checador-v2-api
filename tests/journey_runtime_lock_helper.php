<?php
declare(strict_types=1);
require_once __DIR__.'/../modelo/v2/journey/JourneyExecutionCoordinator.php';
require_once __DIR__.'/../modelo/v2/journey/JourneyMariaDbLock.php';

function journeyTestLocked(mysqli $db, int $shiftId, callable $run): array {
    $stmt=$db->prepare('SELECT id_personal,id_estacion,fecha_jornada_local FROM op_rh_v2_jornadas_programadas WHERE id=?');
    $stmt->bind_param('i',$shiftId); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) throw new RuntimeException('TEST_SHIFT_MISSING');
    $name=substr(JourneyExecutionCoordinator::lockName((int)$row['id_personal'],(int)$row['id_estacion'],(string)$row['fecha_jornada_local']),0,64);
    $lock=new JourneyMariaDbLock($db);
    if (!$lock->acquire($name)) throw new RuntimeException('TEST_LOCK_BUSY');
    try { return $run(); } finally { $lock->release($name); }
}
function journeyTestPair(mysqli $db, JourneyPairingService $service, int $shiftId): array {
    return journeyTestLocked($db,$shiftId,fn()=>$service->pairScheduledShift($shiftId));
}
function journeyTestCalculate(mysqli $db, \modelo\v2\journey\JourneyCalculationService $service, int $pairId): array {
    $stmt=$db->prepare('SELECT id_jornada_programada FROM op_rh_v2_pareos_jornada WHERE id=?');
    $stmt->bind_param('i',$pairId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$row) throw new RuntimeException('TEST_PAIR_MISSING');
    return journeyTestLocked($db,(int)$row['id_jornada_programada'],fn()=>$service->calculatePairingRevision($pairId));
}
