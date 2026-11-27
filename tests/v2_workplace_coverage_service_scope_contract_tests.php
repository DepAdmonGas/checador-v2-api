<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sql = file_get_contents($root . '/docs/v2/CHECADOR_V2_COMODINES_SERVICE_SCOPE_SQL_V1.0.sql');
$contract = file_get_contents($root . '/docs/v2/CHECADOR_V2_COMODINES_SERVICE_PRINCIPAL_ADDENDUM_V1.0.md');
$sqlStatements = preg_replace('/^\s*--.*$/m', '', $sql);
$checks = [
    'uses dedicated processor scope key' => str_contains($sql, "'checador_journey_service'") && str_contains($contract, "modulo_key='checador_journey_service'"),
    'does not change biometricos configuration' => !preg_match('/(?:UPDATE|DELETE\s+FROM|INSERT\s+INTO)\s+tb_modulos_config[^;]*biometricos/is', $sql),
    'does not repeat schema migration or create tables' => !preg_match('/^\s*(?:ALTER|CREATE|DROP|TRUNCATE)\s+(?:TABLE|DATABASE|INDEX)\b/im', $sqlStatements),
    'configuration is explicit and fail-closed' => str_contains($sql, "'stations_only'") && str_contains($sql, "'[1,2,3,4,5,6,7,8,14]'") && str_contains($sql, "'localidades'") && preg_match('/\n\s+0,\n\s+1\nFROM DUAL/s', $sql) === 1,
    'service grant is read-only and principal SERVICE 1' => str_contains($sql, "'checador.comodines.leer'") && str_contains($sql, "'SERVICE'") && preg_match('/\n\s+1,\n\s+1,\n\s+1,\n\s+\'Read-only/s', $sql) === 1,
    'grant has requested exclusive UTC expiry' => str_contains($sql, "'2026-12-19 06:00:00.000000'"),
    'grant insertion does not overwrite existing grant or denial' => str_contains($sql, 'AND NOT EXISTS (') && str_contains($sql, "principal_type = 'SERVICE'\n      AND principal_id = 1"),
    'configuration insertion does not overwrite a pre-existing row' => str_contains($sql, "SELECT 1 FROM tb_modulos_config WHERE modulo_key = 'checador_journey_service'"),
    'does not register a human module or human ACL' => !preg_match('/INSERT\s+INTO\s+(?:modulos_dpto_operativo|modulos_usuarios_do|modulos_puestos_do)/i', $sql),
    'both origin and destination remain intersected with CLI and institution scopes' => str_contains($contract, 'origen y destino') && str_contains($contract, 'allowlist canónico `stations`'),
    'contract records owner approval and separates cross-project approval' => str_contains($contract, 'APROBADO POR EL PROPIETARIO') && str_contains($contract, 'no se declara aprobada'),
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}
printf("SERVICE_SCOPE_PROPOSAL: %d PASS / %d FAIL\n", count($checks) - $failed, $failed);
exit($failed === 0 ? 0 : 1);
