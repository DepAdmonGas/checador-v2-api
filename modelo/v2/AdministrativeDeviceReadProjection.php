<?php

declare(strict_types=1);

/** Safe projection of bounded, authorized device rows; never derives connectivity. */
final class AdministrativeDeviceReadProjection
{
    /**
     * @param list<array<string,mixed>> $devices
     * @param list<array<string,mixed>> $activeAssignments
     * @param array<int,array{workplace_id:int,workplace_name:string,physical_station_id:int,station_name:string,numlista:int}> $stations
     * @param array<int,?string> $telemetry
     * @param list<string> $initialReasons
     * @return array{items:list<array<string,mixed>>,completeness:string,partial_reasons:list<string>}
     */
    public static function project(array $devices, array $activeAssignments, array $stations, array $telemetry, array $initialReasons = []): array
    {
        $assignmentsByDevice = [];
        foreach ($activeAssignments as $assignment) {
            $deviceId = (int) ($assignment['id_dispositivo'] ?? 0);
            if ($deviceId > 0) $assignmentsByDevice[$deviceId][] = $assignment;
        }

        $reasons = array_fill_keys($initialReasons, true);
        $items = [];
        foreach ($devices as $device) {
            $deviceId = (int) ($device['id'] ?? 0);
            if ($deviceId < 1) {
                $reasons['DEVICE_REGISTRY_UNRESOLVED'] = true;
                continue;
            }
            $active = $assignmentsByDevice[$deviceId] ?? [];
            $physicalId = 0;
            $assignmentStatus = 'UNKNOWN';
            $validFrom = null;
            $validTo = null;

            if (count($active) === 1) {
                $physicalId = (int) ($active[0]['id_estacion'] ?? 0);
                if (!isset($stations[$physicalId])) {
                    // This candidate has no safely resolvable authorized assignment.
                    $reasons['ASSIGNMENT_SCOPE_UNRESOLVED'] = true;
                    continue;
                }
                $assignmentStatus = 'ASSIGNED';
                $validFrom = (string) ($active[0]['valido_desde_utc'] ?? '');
                $validTo = ($active[0]['valido_hasta_utc'] ?? null) === null
                    ? null : (string) $active[0]['valido_hasta_utc'];
                if ($validFrom === '') {
                    $assignmentStatus = 'UNKNOWN';
                    $validFrom = null;
                    $reasons['ASSIGNMENT_HISTORY_UNRESOLVED'] = true;
                }
            } elseif (count($active) > 1) {
                $stationIds = array_values(array_unique(array_map(static fn(array $row): int => (int) ($row['id_estacion'] ?? 0), $active)));
                foreach ($stationIds as $stationId) {
                    if (!isset($stations[$stationId])) {
                        $reasons['ASSIGNMENT_SCOPE_UNRESOLVED'] = true;
                        continue 2;
                    }
                }
                if (count($stationIds) === 1) {
                    $physicalId = $stationIds[0];
                } else {
                    $physicalId = (int) ($device['id_estacion'] ?? 0);
                    if (!isset($stations[$physicalId])) $physicalId = 0;
                }
                $reasons['ASSIGNMENT_HISTORY_AMBIGUOUS'] = true;
            } else {
                // No completeness marker proves that absence of an active history row means UNASSIGNED.
                $physicalId = (int) ($device['id_estacion'] ?? 0);
                if (!isset($stations[$physicalId])) {
                    $reasons['DEVICE_REGISTRY_UNMAPPED'] = true;
                    continue;
                }
                $reasons['ASSIGNMENT_HISTORY_UNRESOLVED'] = true;
            }

            $station = $stations[$physicalId] ?? null;
            $statusValue = $device['estado'] ?? null;
            $persistedStatus = match ((string) $statusValue) {
                '1' => 'ACTIVE',
                '0' => 'INACTIVE',
                default => 'UNKNOWN',
            };
            if ($persistedStatus === 'UNKNOWN') $reasons['DEVICE_STATUS_UNRESOLVED'] = true;

            $items[] = [
                'device' => [
                    'id' => $deviceId,
                    'uuid' => (string) ($device['uuid_dispositivo'] ?? ''),
                    'name' => (string) ($device['nombre'] ?? ''),
                    'persisted_status' => $persistedStatus,
                ],
                'physical_station' => $station === null ? [
                    'id' => null, 'name' => null, 'numlista' => null,
                ] : [
                    'id' => $physicalId,
                    'name' => $station['station_name'],
                    'numlista' => $station['numlista'],
                ],
                'workplace' => $station === null ? [
                    'id' => null, 'name' => null, 'mapping_status' => 'AMBIGUOUS',
                ] : [
                    'id' => $station['workplace_id'],
                    'name' => $station['workplace_name'],
                    'mapping_status' => 'VERIFIED',
                ],
                'assignment' => [
                    'status' => $assignmentStatus,
                    'valid_from_utc' => $validFrom,
                    'valid_to_utc' => $validTo,
                    'source' => count($active) > 0 ? 'DEVICE_STATION_HISTORY' : 'DEVICE_REGISTRY_WITHOUT_CURRENT_HISTORY',
                ],
                'group' => [
                    'id' => null,
                    'name' => null,
                    'mapping_status' => 'UNAVAILABLE',
                ],
                'last_communication' => [
                    'last_server_received_at_utc' => $telemetry[$deviceId] ?? null,
                    'source' => 'ACCEPTED_DEVICE_TELEMETRY',
                ],
            ];
        }

        $reasonList = array_keys($reasons);
        sort($reasonList, SORT_STRING);
        usort($items, static fn(array $a, array $b): int => ($a['device']['id'] ?? 0) <=> ($b['device']['id'] ?? 0));
        return [
            'items' => $items,
            'completeness' => $reasonList === [] ? 'COMPLETE' : 'PARTIAL',
            'partial_reasons' => $reasonList,
        ];
    }
}
