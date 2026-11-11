<?php

declare(strict_types=1);

/** Resolves canonical op_rh_localidades IDs to their unique active physical station IDs. */
final class WorkplacePhysicalStationResolver
{
    public static function resolve(mysqli $db, int $localityId): ?int
    {
        if ($localityId < 1) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT l.numlista,
                    (SELECT COUNT(*) FROM op_rh_localidades l2 WHERE l2.numlista = l.numlista) AS locality_count,
                    (SELECT COUNT(*) FROM tb_estaciones e WHERE e.numlista = l.numlista) AS station_count,
                    (SELECT MIN(e.id) FROM tb_estaciones e WHERE e.numlista = l.numlista AND e.estatus = 0) AS active_station_id
             FROM op_rh_localidades l
             WHERE l.id = ?
             LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $localityId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $mapping = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$mapping
            || (int) ($mapping['numlista'] ?? 0) < 1
            || (int) ($mapping['locality_count'] ?? 0) !== 1
            || (int) ($mapping['station_count'] ?? 0) !== 1) {
            return null;
        }

        $physicalStationId = (int) ($mapping['active_station_id'] ?? 0);
        return $physicalStationId > 0 ? $physicalStationId : null;
    }

    /** Resolve a physical tb_estaciones.id to one canonical locality through numlista. */
    public static function resolveCanonicalLocality(mysqli $db, int $physicalStationId): ?int
    {
        if ($physicalStationId < 1) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT e.numlista, e.estatus,
                    (SELECT COUNT(*) FROM tb_estaciones e2 WHERE e2.numlista = e.numlista) AS station_count,
                    (SELECT COUNT(*) FROM op_rh_localidades l WHERE l.numlista = e.numlista) AS locality_count,
                    (SELECT MIN(l.id) FROM op_rh_localidades l WHERE l.numlista = e.numlista) AS locality_id
             FROM tb_estaciones e
             WHERE e.id = ?
             LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $physicalStationId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $mapping = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$mapping || (int) ($mapping['numlista'] ?? 0) < 1
            || (int) ($mapping['estatus'] ?? 1) !== 0
            || (int) ($mapping['station_count'] ?? 0) !== 1
            || (int) ($mapping['locality_count'] ?? 0) !== 1) {
            return null;
        }
        $localityId = (int) ($mapping['locality_id'] ?? 0);
        return $localityId > 0 ? $localityId : null;
    }
}
