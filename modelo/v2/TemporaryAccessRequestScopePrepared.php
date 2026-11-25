<?php

declare(strict_types=1);

require_once __DIR__ . '/WorkplacePhysicalStationResolver.php';

/** Revalidates the current device, physical station, worker assignment, and device identity inside a transaction. */
final class TemporaryAccessRequestScopePrepared
{
    /** @param list<int> $allowedPhysicalStations */
    public static function verify(mysqli $db, array $request, array $allowedPhysicalStations): bool
    {
        $transaction = $db->query('SELECT @@in_transaction AS active')->fetch_assoc();
        if ((int)($transaction['active'] ?? 0) !== 1) {
            throw new RuntimeException('TRANSACTION_REQUIRED');
        }

        $deviceId = (int)($request['id_dispositivo'] ?? 0);
        $stationId = (int)($request['id_estacion'] ?? 0);
        $workerId = (int)($request['id_personal_resuelto'] ?? 0);
        $reference = $request['referencia_externa_personal'] ?? null;
        if ($deviceId < 1 || $stationId < 1 || $workerId < 1
            || !is_string($reference) || $reference === ''
            || !in_array($stationId, $allowedPhysicalStations, true)) {
            return false;
        }

        // Match identity writers' lock order: device, worker, then identity.
        $stmt = $db->prepare('SELECT id FROM op_rh_v2_dispositivos WHERE id=? AND id_estacion=? AND estado=1 FOR UPDATE');
        $stmt->bind_param('ii', $deviceId, $stationId);
        $stmt->execute();
        $deviceMatches = $stmt->get_result()->num_rows === 1;
        $stmt->close();

        $stmt = $db->prepare('SELECT id, estatus FROM tb_estaciones WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $stationId);
        $stmt->execute();
        $station = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $canonicalLocalityId = WorkplacePhysicalStationResolver::resolveCanonicalLocality($db, $stationId);
        if (!$deviceMatches || !$station || (int)$station['estatus'] !== 0 || $canonicalLocalityId === null) {
            return false;
        }

        $stmt = $db->prepare('SELECT id, id_estacion, estado FROM op_rh_personal WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $workerId);
        $stmt->execute();
        $worker = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!self::workerBelongsToStation($worker, $workerId, $stationId)) {
            return false;
        }

        $stmt = $db->prepare(
            'SELECT id_personal FROM op_rh_v2_dispositivo_identidad_personal
             WHERE id_dispositivo=? AND referencia_externa_personal=? AND es_activo=1
               AND valido_desde<=UTC_TIMESTAMP(6) AND (valido_hasta IS NULL OR valido_hasta>UTC_TIMESTAMP(6))
             FOR UPDATE'
        );
        $stmt->bind_param('is', $deviceId, $reference);
        $stmt->execute();
        $identities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return count($identities) === 1 && (int)$identities[0]['id_personal'] === $workerId;
    }

    /** Pure predicate for the current active physical-station assignment row. */
    public static function workerBelongsToStation(?array $worker, int $workerId, int $stationId): bool
    {
        return $worker !== null && $workerId > 0 && $stationId > 0
            && (int)($worker['id'] ?? 0) === $workerId
            && (int)($worker['estado'] ?? 0) === 1
            && (int)($worker['id_estacion'] ?? 0) === $stationId;
    }
}
