<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/test_mariadb_isolation_guard.php';
$socket = TestMariaDbIsolationGuard::requireExplicitAuthorization();
$db = new mysqli('localhost', 'root', '', TestMariaDbIsolationGuard::SCHEMA, 0, $socket);
TestMariaDbIsolationGuard::assertConnection($db, $socket);
$passed = 0;
$check = static function (bool $ok, string $name) use (&$passed): void {
    if (!$ok) throw new RuntimeException('SCHEMA_ASSERTION_FAILED:'.$name);
    ++$passed;
    echo "PASS {$name}\n";
};

$table = $db->query("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='op_rh_personal_localidad_version'")->fetch_assoc();
$check($table === ['ENGINE'=>'InnoDB','TABLE_COLLATION'=>'utf8mb4_unicode_ci'], 'table_engine_charset_collation');

$expectedColumns = [
    'id'=>['int(10) unsigned','NO','auto_increment'],
    'worker_id'=>['int(11)','NO',''],
    'workplace_id'=>['int(11)','NO',''],
    'valid_from_utc'=>['datetime(6)','NO',''],
    'valid_to_utc'=>['datetime(6)','YES',''],
    'recorded_at_utc'=>['datetime(6)','NO',''],
    'recorded_by'=>['int(11)','YES',''],
    'reason'=>['varchar(500)','YES',''],
    'supersedes_id'=>['int(10) unsigned','YES',''],
];
$rows = $db->query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='op_rh_personal_localidad_version' ORDER BY ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC);
$actualColumns = [];
foreach ($rows as $r) $actualColumns[$r['COLUMN_NAME']] = [strtolower($r['COLUMN_TYPE']),$r['IS_NULLABLE'],$r['EXTRA']];
$check($actualColumns === $expectedColumns, 'all_columns_types_nullability_precision');

$expectedIndexes = [
    'PRIMARY'=>['id'],
    'op_rh_pwa_worker_workplace_effective_idx'=>['worker_id','workplace_id','valid_from_utc','valid_to_utc'],
    'op_rh_pwa_worker_recorded_idx'=>['worker_id','recorded_at_utc'],
    'op_rh_pwa_workplace_idx'=>['workplace_id'],
    'op_rh_pwa_worker_id_uq'=>['worker_id','id'],
    'op_rh_pwa_worker_supersedes_uq'=>['worker_id','supersedes_id'],
];
$indexRows = $db->query("SELECT INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='op_rh_personal_localidad_version' ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetch_all(MYSQLI_ASSOC);
$actualIndexes = [];
foreach ($indexRows as $r) $actualIndexes[$r['INDEX_NAME']][] = $r['COLUMN_NAME'];
ksort($actualIndexes);
ksort($expectedIndexes);
$check($actualIndexes === $expectedIndexes, 'named_indexes_and_column_order');
foreach ($indexRows as $r) {
    $expectUnique = in_array($r['INDEX_NAME'], ['PRIMARY','op_rh_pwa_worker_id_uq','op_rh_pwa_worker_supersedes_uq'], true);
    $check((int)$r['NON_UNIQUE'] === ($expectUnique ? 0 : 1), 'index_uniqueness_'.$r['INDEX_NAME']);
}

$expectedFks = [
    'op_rh_pwa_supersedes_fk'=>['worker_id'=>'op_rh_personal_localidad_version.worker_id','supersedes_id'=>'op_rh_personal_localidad_version.id'],
    'op_rh_pwa_worker_fk'=>['worker_id'=>'op_rh_personal.id'],
    'op_rh_pwa_workplace_fk'=>['workplace_id'=>'op_rh_localidades.id'],
];
$fkRows = $db->query("SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='op_rh_personal_localidad_version' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC);
$actualFks = [];
foreach ($fkRows as $r) $actualFks[$r['CONSTRAINT_NAME']][$r['COLUMN_NAME']] = $r['REFERENCED_TABLE_NAME'].'.'.$r['REFERENCED_COLUMN_NAME'];
ksort($actualFks);
ksort($expectedFks);
$check($actualFks === $expectedFks, 'foreign_keys_and_supersedes_lineage');

$parents = $db->query("SELECT c.TABLE_NAME,c.COLUMN_TYPE,c.IS_NULLABLE,c.COLUMN_KEY,t.ENGINE FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND ((c.TABLE_NAME='op_rh_personal' AND c.COLUMN_NAME='id') OR (c.TABLE_NAME='op_rh_localidades' AND c.COLUMN_NAME='id')) ORDER BY c.TABLE_NAME")->fetch_all(MYSQLI_ASSOC);
$check(count($parents)===2 && $parents[0]['TABLE_NAME']==='op_rh_localidades' && $parents[1]['TABLE_NAME']==='op_rh_personal', 'both_parent_keys_present');
foreach ($parents as $r) $check(str_starts_with(strtolower($r['COLUMN_TYPE']),'int(') && $r['IS_NULLABLE']==='NO' && $r['COLUMN_KEY']==='PRI' && $r['ENGINE']==='InnoDB', 'parent_signed_int_primary_innodb_'.$r['TABLE_NAME']);

$count = (int)$db->query('SELECT COUNT(*) n FROM op_rh_personal_localidad_version')->fetch_assoc()['n'];
$check($count===0, 'fixture_schema_starts_empty');
echo "R3B_SCHEMA_ASSERTIONS={$passed} PASS\n";
