<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/AdministrativeDeviceReadProjection.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $name) use (&$passed, &$failed): void {
    if ($condition) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}\n"; }
};
$stationMap = [
    7 => ['workplace_id' => 1, 'workplace_name' => 'Interlomas', 'physical_station_id' => 7, 'station_name' => 'Interlomas física', 'numlista' => 2],
    8 => ['workplace_id' => 2, 'workplace_name' => 'Otra localidad', 'physical_station_id' => 8, 'station_name' => 'Estación dos', 'numlista' => 3],
];

$single = AdministrativeDeviceReadProjection::project(
    [['id' => 1, 'uuid_dispositivo' => 'opaque-1', 'nombre' => 'Lector A', 'estado' => 1, 'id_estacion' => 7]],
    [['id_dispositivo' => 1, 'id_estacion' => 7, 'valido_desde_utc' => '2026-01-01 00:00:00', 'valido_hasta_utc' => null]],
    $stationMap,
    [1 => '2026-11-03 12:00:00']
);
$assert($single['completeness'] === 'COMPLETE' && count($single['items']) === 1, 'single verified assignment is complete');
$assert($single['items'][0]['assignment']['status'] === 'ASSIGNED', 'effective assignment is shown as assigned');
$assert($single['items'][0]['device']['persisted_status'] === 'ACTIVE' && $single['items'][0]['last_communication']['last_server_received_at_utc'] === '2026-11-03 12:00:00', 'persisted status and telemetry stay separate');
$assert(!isset($single['items'][0]['online']) && !isset($single['items'][0]['device']['credential_key']), 'no online inference or credential fields');
$assert(($single['items'][0]['group'] ?? null) === ['id' => null, 'name' => null, 'mapping_status' => 'UNAVAILABLE'], 'unverified station-to-group relation remains explicitly unavailable');

$withoutHistory = AdministrativeDeviceReadProjection::project(
    [['id' => 2, 'uuid_dispositivo' => 'opaque-2', 'nombre' => 'Lector B', 'estado' => 0, 'id_estacion' => 7]], [], $stationMap, []
);
$assert($withoutHistory['completeness'] === 'PARTIAL' && $withoutHistory['items'][0]['assignment']['status'] === 'UNKNOWN', 'missing history does not become unassigned');
$assert($withoutHistory['items'][0]['last_communication']['last_server_received_at_utc'] === null, 'missing telemetry remains null');

$ambiguousSameStation = AdministrativeDeviceReadProjection::project(
    [['id' => 3, 'uuid_dispositivo' => 'opaque-3', 'nombre' => 'Lector C', 'estado' => 1, 'id_estacion' => 7]],
    [
        ['id_dispositivo' => 3, 'id_estacion' => 7, 'valido_desde_utc' => '2026-01-01 00:00:00'],
        ['id_dispositivo' => 3, 'id_estacion' => 7, 'valido_desde_utc' => '2026-02-01 00:00:00'],
    ], $stationMap, []
);
$assert($ambiguousSameStation['completeness'] === 'PARTIAL' && $ambiguousSameStation['items'][0]['assignment']['status'] === 'UNKNOWN', 'overlapping assignments remain unknown');

$crossScopeAmbiguous = AdministrativeDeviceReadProjection::project(
    [['id' => 4, 'uuid_dispositivo' => 'secret-outside', 'nombre' => 'Hidden', 'estado' => 1, 'id_estacion' => 7]],
    [
        ['id_dispositivo' => 4, 'id_estacion' => 7, 'valido_desde_utc' => '2026-01-01 00:00:00'],
        ['id_dispositivo' => 4, 'id_estacion' => 999, 'valido_desde_utc' => '2026-02-01 00:00:00'],
    ], $stationMap, []
);
$serialized = json_encode($crossScopeAmbiguous, JSON_UNESCAPED_SLASHES);
$assert($crossScopeAmbiguous['items'] === [] && str_contains((string) $serialized, 'ASSIGNMENT_SCOPE_UNRESOLVED'), 'ambiguous cross-scope row is excluded with a generic reason');
$assert(!str_contains((string) $serialized, 'secret-outside') && !str_contains((string) $serialized, '999'), 'cross-scope device details are not leaked');

$noRows = AdministrativeDeviceReadProjection::project([], [], $stationMap, []);
$assert($noRows['completeness'] === 'COMPLETE' && $noRows['items'] === [], 'confirmed empty authorized scope is distinct from read failure');

echo "RESULT: {$passed} PASS / {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
