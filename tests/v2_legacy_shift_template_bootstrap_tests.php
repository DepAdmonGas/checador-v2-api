<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/bootstrap_legacy_shift_templates.php';

$tests = 0;
$failures = [];
$assert = static function (bool $condition, string $name) use (&$tests, &$failures): void {
    $tests++;
    if (!$condition) $failures[] = $name;
};
$throws = static function (callable $callback): bool {
    try { $callback(); return false; } catch (Throwable) { return true; }
};

$assert(checadorM03BootstrapOptions(['bootstrap.php', '--database=bd_admongas']) === ['apply'=>false,'database'=>'bd_admongas','help'=>false],
    'bootstrap defaults to non-writing preview and requires the exact development database');
$assert(checadorM03BootstrapOptions(['bootstrap.php', '--database=bd_admongas', '--apply']) === ['apply'=>true,'database'=>'bd_admongas','help'=>false],
    'writing requires the explicit apply switch');
$assert($throws(static fn() => checadorM03BootstrapOptions(['bootstrap.php', '--database=production']))
    && $throws(static fn() => checadorM03BootstrapOptions(['bootstrap.php'])),
    'wrong or omitted database targets are rejected');

$rows = [
    ['id'=>10,'id_estacion'=>1,'titulo'=>'TURNO 1','hora_entrada'=>'06:00:00','hora_salida'=>'14:00:00'],
    ['id'=>11,'id_estacion'=>1,'titulo'=>'TURNO 2','hora_entrada'=>'14:00:00','hora_salida'=>'20:00:00'],
    ['id'=>12,'id_estacion'=>1,'titulo'=>'TURNO 3','hora_entrada'=>'20:00:00','hora_salida'=>'06:00:00'],
    ['id'=>13,'id_estacion'=>1,'titulo'=>'ENCARGADO 1','hora_entrada'=>'06:00:00','hora_salida'=>'14:00:00'],
    ['id'=>14,'id_estacion'=>1,'titulo'=>'ENCARGADO 2','hora_entrada'=>'14:00:00','hora_salida'=>'22:00:00'],
    ['id'=>15,'id_estacion'=>1,'titulo'=>'ENCARGADO 3','hora_entrada'=>'22:00:00','hora_salida'=>'06:00:00'],
    ['id'=>16,'id_estacion'=>1,'titulo'=>'ASISTENTE','hora_entrada'=>'09:00:00','hora_salida'=>'18:00:00'],
    ['id'=>17,'id_estacion'=>1,'titulo'=>'ASISTENTE SABADO','hora_entrada'=>'09:00:00','hora_salida'=>'15:00:00'],
    ['id'=>18,'id_estacion'=>1,'titulo'=>'INTENDENCIA','hora_entrada'=>'09:00:00','hora_salida'=>'18:00:00'],
    ['id'=>26,'id_estacion'=>1,'titulo'=>'FACTURISTA 1','hora_entrada'=>'07:50:00','hora_salida'=>'14:00:00'],
    ['id'=>27,'id_estacion'=>1,'titulo'=>'FACTURISTA 2','hora_entrada'=>'13:50:00','hora_salida'=>'20:00:00'],
    ['id'=>61,'id_estacion'=>1,'titulo'=>'BANDERERO FULL','hora_entrada'=>'08:00:00','hora_salida'=>'19:00:00'],
    ['id'=>84,'id_estacion'=>1,'titulo'=>'INTENDENCIA SABADO','hora_entrada'=>'09:00:00','hora_salida'=>'15:30:00'],
    ['id'=>98,'id_estacion'=>1,'titulo'=>'VACACIONES','hora_entrada'=>'00:00:00','hora_salida'=>'00:00:00'],
    ['id'=>99,'id_estacion'=>1,'titulo'=>'INCAPACIDAD','hora_entrada'=>'00:00:00','hora_salida'=>'00:00:00'],
    ['id'=>116,'id_estacion'=>1,'titulo'=>'BANDERERO DE APOYO 1','hora_entrada'=>'08:00:00','hora_salida'=>'14:00:00'],
    ['id'=>117,'id_estacion'=>1,'titulo'=>'BANDERERO DE APOYO 2','hora_entrada'=>'14:00:00','hora_salida'=>'20:00:00'],
];

$plan = checadorM03BootstrapBuildPlan($rows);
$assert($plan['counts'] === ['source_rows'=>17,'valid_intervals'=>15,'excluded_rows'=>2,'unique_definitions'=>12,'overnight_definitions'=>2], 'approved source counts and unique intervals');
$assert(count(array_unique(array_column($plan['definitions'], 'clave'))) === 12, 'deterministic unique template keys');
$assert(count(array_filter($plan['definitions'], static fn(array $item): bool => $item['tipo_turno'] === 'NOCTURNO' && $item['dia_offset_fin'] === 1)) === 2, 'overnight offsets and type');
$assert(count($plan['excluded_rows']) === 2 && count(array_filter($plan['definitions'], static fn(array $item): bool => str_contains($item['nombre'], 'VACACIONES') || str_contains($item['nombre'], 'INCAPACIDAD'))) === 0, 'administrative zero-duration rows excluded');
$reversePlan = checadorM03BootstrapBuildPlan(array_reverse($rows));
$assert(array_column($plan['definitions'], 'clave') === array_column($reversePlan['definitions'], 'clave'), 'definition ordering deterministic');
$assert(array_column($plan['definitions'], 'source_hashes') === array_column($reversePlan['definitions'], 'source_hashes'), 'provenance hashes deterministic');
$assert($throws(static fn() => checadorM03BootstrapBuildPlan($rows, ['station_id'=>1,'numlista'=>2,'locality_id'=>9,'locality_name'=>'Other'])), 'wrong canonical locality rejected');
$invalidRows = $rows;
$invalidRows[2]['hora_salida'] = '20:00:00';
$assert($throws(static fn() => checadorM03BootstrapBuildPlan($invalidRows)), 'unclassified zero-duration legacy interval rejected');
$badTimeRows = $rows;
$badTimeRows[0]['hora_entrada'] = '25:00:00';
$assert($throws(static fn() => checadorM03BootstrapBuildPlan($badTimeRows)), 'invalid time rejected');

$template = ['id'=>55,'clave'=>'LEGACY_IL1_060000_140000','version'=>1,'nombre'=>'Legacy Interlomas — TURNO 1 / ENCARGADO 1 — 06:00–14:00','tipo_turno'=>'REGULAR','es_activo'=>1];
$expected = [
    'clave'=>$template['clave'],'nombre'=>$template['nombre'],'version'=>1,'tipo_turno'=>'REGULAR',
    'segmentos'=>[['ordinal'=>1,'tipo_segmento'=>'WORK','hora_inicio_local'=>'06:00:00','hora_fin_local'=>'14:00:00','dia_offset_inicio'=>0,'dia_offset_fin'=>0]],
];
$exact = ['status'=>'present','template'=>$template,'segments'=>[['id'=>77,'ordinal'=>1,'tipo_segmento'=>'WORK','hora_inicio_local'=>'06:00:00','hora_fin_local'=>'14:00:00','dia_offset_inicio'=>0,'dia_offset_fin'=>0]]];
$assert(checadorM03BootstrapDefinitionMatches($exact, $expected), 'exact existing definition is idempotent match');
$exact['segments'][0]['hora_fin_local'] = '13:59:00';
$assert(!checadorM03BootstrapDefinitionMatches($exact, $expected), 'modified existing definition is a conflict');

echo 'PASS=' . ($tests - count($failures)) . ' FAIL=' . count($failures) . PHP_EOL;
foreach ($failures as $failure) echo 'FAIL: ' . $failure . PHP_EOL;
exit($failures === [] ? 0 : 1);
