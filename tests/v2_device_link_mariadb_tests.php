<?php

declare(strict_types=1);
const LINK_TEST_DB = 'bd_admongas_v2_biometric_roster_test_20261124';
if (!in_array((string)getenv('DB_HOST'), ['127.0.0.1','localhost'], true) || getenv('DB_NAME') !== LINK_TEST_DB) { fwrite(STDERR,"Refusing: disposable localhost database required.\n"); exit(2); }
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/DeviceLinkV2Service.php';
require_once __DIR__ . '/../modelo/v2/DispositivoV2.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = (new BD())->conectarBD();
$identity = $db->query('SELECT DATABASE() AS db, CURRENT_USER() AS principal, @@hostname AS host, VERSION() AS version')->fetch_assoc();
if (($identity['db'] ?? '') !== LINK_TEST_DB) { fwrite(STDERR,"Identity mismatch.\n"); exit(2); }
if (($argv[1] ?? '') === '--child') {
    try { echo (new DeviceLinkV2Service())->consume($db, (string)getenv('LINK_TEST_TOKEN')) === null ? 'DENIED' : 'SUCCESS'; exit(0); }
    catch (Throwable) { fwrite(STDERR,"CHILD_ERROR"); exit(1); }
}
echo 'DATABASE_IDENTITY ' . json_encode($identity) . "\n";
$tables = ['op_rh_v2_m11_configuration_audit','op_rh_v2_device_link_tokens','op_rh_v2_device_biometric_grants','op_rh_v2_dispositivo_credenciales','op_rh_v2_dispositivos','op_rh_localidades','tb_estaciones'];
foreach ($tables as $table) {
    $stmt=$db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $stmt->bind_param('s',$table); $stmt->execute();
    if ((int)$stmt->get_result()->fetch_assoc()['n'] !== 0) { fwrite(STDERR,"Refusing: test tables already exist.\n"); exit(2); }
}
$created=[]; $pass=0; $fail=0;
$assert=static function(bool $ok,string $label) use (&$pass,&$fail): void { echo ($ok?'PASS ':'FAIL ').$label."\n"; if($ok)$pass++;else $fail++; };
try {
    $ddl = [
      'CREATE TABLE tb_estaciones (id INT PRIMARY KEY,numlista INT,nombre VARCHAR(100),estatus INT) ENGINE=InnoDB',
      'CREATE TABLE op_rh_localidades (id INT PRIMARY KEY,numlista INT) ENGINE=InnoDB',
      'CREATE TABLE op_rh_v2_dispositivos (id INT PRIMARY KEY,uuid_dispositivo VARCHAR(64),id_estacion INT,nombre VARCHAR(100),timezone VARCHAR(64),estado INT) ENGINE=InnoDB',
      "CREATE TABLE op_rh_v2_dispositivo_credenciales (id INT AUTO_INCREMENT PRIMARY KEY,id_dispositivo INT,credential_key VARCHAR(64) UNIQUE,secret_hash VARCHAR(255),valido_desde DATETIME,valido_hasta DATETIME NULL,estado VARCHAR(20),creado_at DATETIME,revocado_at DATETIME NULL,active_key VARCHAR(10) GENERATED ALWAYS AS (IF(estado='ACTIVE','ACTIVE',NULL)) VIRTUAL, UNIQUE KEY uk_active (id_dispositivo,active_key)) ENGINE=InnoDB",
      'CREATE TABLE op_rh_v2_device_link_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,token_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,id_dispositivo INT,id_estacion INT,emitido_por_usuario_id INT,emitido_at_utc DATETIME(6),expira_at_utc DATETIME(6),consumido_at_utc DATETIME(6) NULL,revocado_at_utc DATETIME(6) NULL) ENGINE=InnoDB',
      'CREATE TABLE op_rh_v2_device_biometric_grants (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,id_dispositivo INT,id_estacion INT,id_credencial INT,autorizado_por_usuario_id INT,valido_desde_utc DATETIME(6),valido_hasta_utc DATETIME(6) NULL,activo TINYINT,revocado_at_utc DATETIME(6) NULL,active_device_id INT GENERATED ALWAYS AS (IF(activo=1 AND revocado_at_utc IS NULL,id_dispositivo,NULL)) STORED,UNIQUE KEY uk_one_active_device_grant (active_device_id)) ENGINE=InnoDB',
      'CREATE TABLE op_rh_v2_m11_configuration_audit (id INT AUTO_INCREMENT PRIMARY KEY,actor_portal_user_id INT,permission_key VARCHAR(80),workplace_id INT,entity_type VARCHAR(80),entity_id INT,action VARCHAR(80),created_at_utc DATETIME(6)) ENGINE=InnoDB'
    ];
    $creationOrder = ['tb_estaciones','op_rh_localidades','op_rh_v2_dispositivos','op_rh_v2_dispositivo_credenciales','op_rh_v2_device_link_tokens','op_rh_v2_device_biometric_grants','op_rh_v2_m11_configuration_audit'];
    foreach ($ddl as $i=>$sql) { $db->query($sql); $created[] = $creationOrder[$i]; }
    $db->query("INSERT INTO tb_estaciones VALUES (21,101,'Synthetic Station',0),(22,202,'Other Station',0)");
    $db->query('INSERT INTO op_rh_localidades VALUES (1001,101),(1002,202)');
    $db->query("INSERT INTO op_rh_v2_dispositivos VALUES (1,'DEVICE-SYNTHETIC',21,'Synthetic Reader','America/Mexico_City',1)");
    $issue=static function(int $station=21,int $offset=0,bool $revoked=false) use ($db): string {
        $token=bin2hex(random_bytes(32)); $hash=hash('sha256',$token);
        $stmt=$db->prepare('INSERT INTO op_rh_v2_device_link_tokens (token_sha256,id_dispositivo,id_estacion,emitido_por_usuario_id,emitido_at_utc,expira_at_utc,revocado_at_utc) VALUES (?,1,?,1,UTC_TIMESTAMP(6)+INTERVAL ? SECOND,UTC_TIMESTAMP(6)+INTERVAL ? SECOND,IF(?=1,UTC_TIMESTAMP(6),NULL))');
        $end=$offset+900; $rev=(int)$revoked; $stmt->bind_param('siiii',$hash,$station,$offset,$end,$rev); $stmt->execute(); $stmt->close(); return $token;
    };
    $service=new DeviceLinkV2Service();
    $first=$issue(); $result=$service->consume($db,$first);
    $assert(is_array($result) && $result['id_estacion']===21 && password_verify($result['device_secret'],(string)$db->query("SELECT secret_hash FROM op_rh_v2_dispositivo_credenciales WHERE estado='ACTIVE'")->fetch_assoc()['secret_hash']), 'valid token issues bcrypt device credential');
    $credentialId=(int)$db->query("SELECT id FROM op_rh_v2_dispositivo_credenciales WHERE estado='ACTIVE'")->fetch_assoc()['id'];
    $assert(DeviceBiometricGrantV2::authorized($db,1,21,$credentialId), 'grant is created only for the new credential');
    $portalRepo = (string)(getenv('PORTAL3_REPO') ?: '/home/silvinol/Proyectos/Portal3');
    $portalBridge = $portalRepo . '/tests/checador_device_link_mariadb_bridge.php';
    if (!is_file($portalBridge)) throw new RuntimeException('PORTAL_BRIDGE_MISSING');
    $proc = proc_open([PHP_BINARY,$portalBridge], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $portalPipes, $portalRepo);
    if (!is_resource($proc)) throw new RuntimeException('PORTAL_BRIDGE_START_FAILED');
    fclose($portalPipes[0]); $portalToken=stream_get_contents($portalPipes[1]); fclose($portalPipes[1]);
    $portalError=stream_get_contents($portalPipes[2]); fclose($portalPipes[2]); $portalExit=proc_close($proc);
    $stillActive = (int)$db->query("SELECT id FROM op_rh_v2_dispositivo_credenciales WHERE estado='ACTIVE'")->fetch_assoc()['id'];
    $assert($portalExit===0 && DeviceLinkV2Service::validToken($portalToken) && $stillActive===$credentialId && DeviceBiometricGrantV2::authorized($db,1,21,$credentialId), 'Portal3 issues renewal without changing old credential or grant');
    $bridgeResult=$service->consume($db,$portalToken);
    $bridgeId=(int)$db->query("SELECT id FROM op_rh_v2_dispositivo_credenciales WHERE estado='ACTIVE'")->fetch_assoc()['id'];
    $assert(is_array($bridgeResult) && $bridgeId!==$credentialId && !DeviceBiometricGrantV2::authorized($db,1,21,$credentialId) && DeviceBiometricGrantV2::authorized($db,1,21,$bridgeId), 'API consumes Portal3 token and grants only its new credential');
    $credentialId=$bridgeId;
    $assert($service->consume($db,$first) === null, 'consumed token cannot be reused');
    $assert($service->consume($db,$issue(21,-901)) === null, 'expired token denied');
    $assert($service->consume($db,$issue(21,0,true)) === null, 'revoked token denied');
    $assert($service->consume($db,$issue(22)) === null, 'wrong station denied');
    $assert(!DeviceBiometricGrantV2::authorized($db,1,22,$credentialId), 'biometric permission does not extend to another station');
    $db->query('UPDATE op_rh_v2_device_biometric_grants SET revocado_at_utc=UTC_TIMESTAMP(6)');
    $assert(!DeviceBiometricGrantV2::authorized($db,1,21,$credentialId), 'revoked biometric grant denies roster authorization');
    $db->query('UPDATE op_rh_v2_dispositivos SET estado=0 WHERE id=1');
    $assert($service->consume($db,$issue()) === null, 'inactive device denied');
    $db->query('UPDATE op_rh_v2_dispositivos SET estado=1 WHERE id=1');
    $token=$issue(); putenv('LINK_TEST_TOKEN='.$token);
    $start=static function(): array { $p=proc_open([PHP_BINARY,__FILE__,'--child'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); if(!is_resource($p))throw new RuntimeException('CHILD_START_FAILED'); fclose($pipes[0]);return [$p,$pipes]; };
    $a=$start(); $b=$start(); $out=[];
    foreach ([$a,$b] as [$p,$pipes]) { $stdout=stream_get_contents($pipes[1]); fclose($pipes[1]); $stderr=stream_get_contents($pipes[2]); fclose($pipes[2]); $rc=proc_close($p); $out[]=[$rc,$stdout,$stderr]; }
    $values=[$out[0][1],$out[1][1]]; sort($values);
    $assert($out[0][0]===0 && $out[1][0]===0 && $values===['DENIED','SUCCESS'], 'concurrent use has exactly one winner');
    $counts=$db->query("SELECT SUM(estado='ACTIVE') AS active, SUM(estado='REVOKED') AS revoked FROM op_rh_v2_dispositivo_credenciales")->fetch_assoc();
    $assert((int)$counts['active']===1 && (int)$counts['revoked']>=1, 'rotation retains exactly one active credential');
    $lost = $service->consume($db, $issue());
    $lostCredentialId = (int)$db->query("SELECT id FROM op_rh_v2_dispositivo_credenciales WHERE estado='ACTIVE'")->fetch_assoc()['id'];
    $replacement = $service->consume($db, $issue());
    $auth = new DispositivoV2();
    $oldAuth = $auth->autenticarCredencial($db, '1', $lost['credential_key'], $lost['device_secret']);
    $newAuth = $auth->autenticarCredencial($db, '1', $replacement['credential_key'], $replacement['device_secret']);
    $assert(empty($oldAuth['auth']) && !empty($newAuth['auth']), 'new token recovers a lost response and revokes its credential');
    $assert(!DeviceBiometricGrantV2::authorized($db,1,21,$lostCredentialId) && DeviceBiometricGrantV2::authorized($db,1,21,(int)($newAuth['credential_id'] ?? 0)), 'old credential cannot inherit the replacement biometric grant');
    $rollbackToken = $issue();
    $db->query("CREATE TRIGGER synthetic_link_grant_failure BEFORE INSERT ON op_rh_v2_device_biometric_grants FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'");
    $failed = false; try { $service->consume($db, $rollbackToken); } catch (Throwable) { $failed = true; }
    $rollbackAuth = $auth->autenticarCredencial($db, '1', $replacement['credential_key'], $replacement['device_secret']);
    $rollbackHash = hash('sha256', $rollbackToken);
    $stmt = $db->prepare('SELECT consumido_at_utc FROM op_rh_v2_device_link_tokens WHERE token_sha256=?');
    $stmt->bind_param('s', $rollbackHash); $stmt->execute(); $rollbackRow = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $assert($failed && !empty($rollbackAuth['auth']) && ($rollbackRow['consumido_at_utc'] ?? null) === null, 'failure rolls back token consumption and credential rotation');
    $db->query('DROP TRIGGER synthetic_link_grant_failure');
    echo "RESULT: {$pass} PASS / {$fail} FAIL\n";
} catch (Throwable $e) { $fail++; fwrite(STDERR,'Test error: '.get_class($e)."\n"); }
foreach (array_reverse($created) as $table) $db->query("DROP TABLE `$table`");
exit($fail ? 1 : 0);
