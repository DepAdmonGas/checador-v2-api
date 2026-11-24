<?php

declare(strict_types=1);
const IDENTITY_TEST_DB = 'bd_admongas_v2_biometric_roster_test_20261124';
if (!in_array((string)getenv('DB_HOST'), ['127.0.0.1','localhost'], true) || getenv('DB_NAME') !== IDENTITY_TEST_DB) {
    fwrite(STDERR, "Refusing: exact disposable localhost database required.\n"); exit(2);
}
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/InitialDeviceIdentityProvisioner.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = (new BD())->conectarBD();
$identity = $db->query('SELECT DATABASE() AS db, CURRENT_USER() AS principal, @@hostname AS host, VERSION() AS version')->fetch_assoc();
if ($identity['db'] !== IDENTITY_TEST_DB) { fwrite(STDERR, "Database identity mismatch.\n"); exit(2); }
echo 'DATABASE_IDENTITY ' . json_encode($identity) . "\n";
$occupied = $db->query('SELECT (SELECT COUNT(*) FROM op_rh_v2_dispositivos WHERE id=2) + (SELECT COUNT(*) FROM op_rh_personal WHERE id_estacion=1 OR id BETWEEN 990100 AND 990104) + (SELECT COUNT(*) FROM op_rh_v2_dispositivo_identidad_personal WHERE id_dispositivo=2 OR id_personal BETWEEN 990100 AND 990104) AS n')->fetch_assoc();
if ((int)$occupied['n'] !== 0) { fwrite(STDERR, "Refusing: disposable fixture scope is not empty.\n"); exit(2); }
$cleanup = static function () use ($db): void {
    $db->query('DELETE FROM op_rh_v2_dispositivo_identidad_personal WHERE id_dispositivo=2 AND id_personal BETWEEN 990100 AND 990104');
    $db->query('DELETE FROM op_rh_personal WHERE id BETWEEN 990100 AND 990104');
    $db->query('DELETE FROM op_rh_v2_dispositivos WHERE id=2 AND uuid_dispositivo=\'synthetic-identity-test\'');
};
$pass = 0; $fail = 0;
$check = static function(bool $ok, string $label) use (&$pass, &$fail): void { echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if ($ok) $pass++; else $fail++; };
try {
    $db->query("INSERT INTO op_rh_v2_dispositivos (id,uuid_dispositivo,id_estacion,nombre,timezone,estado) VALUES (2,'synthetic-identity-test',1,'Synthetic','UTC',1)");
    $db->query("INSERT INTO op_rh_personal (id,id_estacion,no_colaborador,nombre_completo,puesto,estado) VALUES (990100,1,990100,'Synthetic Existing',1,1),(990101,1,990101,'Synthetic One',1,1),(990102,1,990102,'Synthetic Two',1,1)");
    $db->query("INSERT INTO op_rh_v2_dispositivo_identidad_personal (id_dispositivo,referencia_externa_personal,id_personal,valido_desde,valido_hasta,es_activo) VALUES (2,'synthetic-existing-ref',990100,'2020-01-01 00:00:00',NULL,1)");
    $service = new InitialDeviceIdentityProvisioner($db);
    $dry = $service->run(false);
    $check($dry['conservar'] === 1 && $dry['primera_identidad'] === 2 && $dry['insertadas'] === 0, 'dry run classifies and writes nothing');
    $command = [PHP_BINARY, __DIR__ . '/../scripts/provision_initial_device_identities.php', '--apply'];
    $start = static function() use ($command): array { $p = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes); if (!is_resource($p)) throw new RuntimeException('CHILD_START'); fclose($pipes[0]); return [$p,$pipes]; };
    $a = $start(); $b = $start(); $results = [];
    foreach ([$a,$b] as [$proc,$pipes]) { $out = stream_get_contents($pipes[1]); fclose($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[2]); $exit = proc_close($proc); $results[] = [$exit,json_decode($out,true),$err]; }
    $check($results[0][0] === 0 && $results[1][0] === 0 && (($results[0][1]['result']['insertadas'] ?? -1) + ($results[1][1]['result']['insertadas'] ?? -1)) === 2, 'concurrent commands serialize and insert each first identity once');
    $rows = $db->query('SELECT id_personal,referencia_externa_personal FROM op_rh_v2_dispositivo_identidad_personal WHERE id_dispositivo=2 ORDER BY id_personal')->fetch_all(MYSQLI_ASSOC);
    $check(count($rows) === 3 && $rows[0]['referencia_externa_personal'] === 'synthetic-existing-ref' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $rows[1]['referencia_externa_personal']) === 1 && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $rows[2]['referencia_externa_personal']) === 1, 'existing reference preserved and UUID v4 persisted');
    $again = $service->run(true);
    $check($again['insertadas'] === 0 && $again['conservar'] === 3, 'second application is idempotent');
    $db->query("INSERT INTO op_rh_personal (id,id_estacion,no_colaborador,nombre_completo,puesto,estado) VALUES (990103,1,990103,'Synthetic Rollback',1,1),(990104,1,990104,'Synthetic Conflict',1,1)");
    $db->begin_transaction();
    $registration = (new PunchEventV2())->registrarIdentidadPersonal($db, 2, 'synthetic-outer-transaction', 990103, '2020-01-01 00:00:00', null, true);
    $db->rollback();
    $outerCount = (int)$db->query('SELECT COUNT(*) AS n FROM op_rh_v2_dispositivo_identidad_personal WHERE id_personal=990103')->fetch_assoc()['n'];
    $check(!empty($registration['success']) && $outerCount === 0, 'canonical registration participates in caller transaction and rolls back');
    $db->query("INSERT INTO op_rh_v2_dispositivo_identidad_personal (id_dispositivo,referencia_externa_personal,id_personal,valido_desde,valido_hasta,es_activo) VALUES (2,'synthetic-conflict-a',990104,'2020-01-01 00:00:00',NULL,1),(2,'synthetic-conflict-b',990104,'2020-01-01 00:00:00',NULL,1)");
    $threw = false; try { $service->run(true); } catch (Throwable) { $threw = true; }
    $rollbackCount = (int)$db->query('SELECT COUNT(*) AS n FROM op_rh_v2_dispositivo_identidad_personal WHERE id_personal=990103')->fetch_assoc()['n'];
    $check($threw && $rollbackCount === 0, 'conflict rolls back earlier insert');
    echo "RESULT: {$pass} PASS / {$fail} FAIL\n";
    $cleanup(); exit($fail === 0 ? 0 : 1);
} catch (Throwable $e) {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'Test error: ' . get_class($e) . "\n"); exit(1);
}
