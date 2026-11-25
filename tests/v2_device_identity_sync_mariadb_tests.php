<?php

declare(strict_types=1);
const SYNC_TEST_DB = 'bd_admongas_v2_biometric_roster_test_20261124';
if (!in_array((string)getenv('DB_HOST'), ['127.0.0.1','localhost'], true) || getenv('DB_NAME') !== SYNC_TEST_DB) { fwrite(STDERR,"Refusing: disposable localhost database required.\n"); exit(2); }
if (($argv[1] ?? '') === '--race') {
    $port=(int)getenv('SYNC_TEST_PORT');
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>"X-Device-Id: 1\r\nX-Credential-Key: sync-key-1\r\nAuthorization: Bearer synthetic-sync-secret\r\nContent-Type: application/json\r\n",'content'=>'{"request_id":"66666666-6666-4666-8666-666666666666"}','ignore_errors'=>true,'timeout'=>15]]);
    $response=@file_get_contents('http://127.0.0.1:'.$port.'/api/v2/devices/station/identities/synchronize',false,$context);
    if ($response === false) exit(1); echo $response; exit(0);
}
if (!function_exists('posix_kill')) { fwrite(STDERR,"POSIX extension required for concurrent HTTP test.\n"); exit(2); }
require_once __DIR__ . '/../modelo/BD.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=(new BD())->conectarBD();
$identity=$db->query('SELECT DATABASE() AS db,CURRENT_USER() AS principal,@@hostname AS host,VERSION() AS version')->fetch_assoc();
if (($identity['db'] ?? '') !== SYNC_TEST_DB) { fwrite(STDERR,"Database identity mismatch.\n"); exit(2); }
echo 'DATABASE_IDENTITY '.json_encode($identity)."\n";
$ddl=[
 'tb_estaciones'=>'CREATE TABLE tb_estaciones (id INT PRIMARY KEY,numlista INT,nombre VARCHAR(100),estatus INT) ENGINE=InnoDB',
 'op_rh_localidades'=>'CREATE TABLE op_rh_localidades (id INT PRIMARY KEY,numlista INT) ENGINE=InnoDB',
 'op_rh_v2_dispositivos'=>'CREATE TABLE op_rh_v2_dispositivos (id INT PRIMARY KEY,uuid_dispositivo VARCHAR(64),id_estacion INT,nombre VARCHAR(100),timezone VARCHAR(64),estado INT) ENGINE=InnoDB',
 'op_rh_v2_dispositivo_credenciales'=>'CREATE TABLE op_rh_v2_dispositivo_credenciales (id INT PRIMARY KEY,id_dispositivo INT,credential_key VARCHAR(64),secret_hash VARCHAR(255),estado VARCHAR(20),valido_desde DATETIME,valido_hasta DATETIME NULL,revocado_at DATETIME NULL) ENGINE=InnoDB',
 'op_rh_v2_device_biometric_grants'=>'CREATE TABLE op_rh_v2_device_biometric_grants (id INT PRIMARY KEY,id_dispositivo INT,id_estacion INT,id_credencial INT,activo INT,valido_desde_utc DATETIME(6),valido_hasta_utc DATETIME(6) NULL,revocado_at_utc DATETIME(6) NULL) ENGINE=InnoDB',
 'op_rh_personal'=>'CREATE TABLE op_rh_personal (id INT PRIMARY KEY,id_estacion INT,no_colaborador INT,nombre_completo VARCHAR(100),puesto INT,estado INT) ENGINE=InnoDB',
 'op_rh_puestos'=>'CREATE TABLE op_rh_puestos (id INT PRIMARY KEY,puesto VARCHAR(100)) ENGINE=InnoDB',
 'op_rh_personal_acceso'=>'CREATE TABLE op_rh_personal_acceso (id INT AUTO_INCREMENT PRIMARY KEY,id_personal INT,huella TEXT NULL) ENGINE=InnoDB',
 'op_rh_v2_dispositivo_identidad_personal'=>'CREATE TABLE op_rh_v2_dispositivo_identidad_personal (id INT AUTO_INCREMENT PRIMARY KEY,id_dispositivo INT,referencia_externa_personal VARCHAR(64),id_personal INT,valido_desde DATETIME,valido_hasta DATETIME NULL,es_activo INT,UNIQUE KEY uk_ref (id_dispositivo,referencia_externa_personal)) ENGINE=InnoDB',
 'op_rh_v2_device_identity_sync_requests'=>'CREATE TABLE op_rh_v2_device_identity_sync_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,id_dispositivo INT,id_estacion INT,request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin,request_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,response_json LONGTEXT,created_at_utc DATETIME(6),UNIQUE KEY uk_request (id_dispositivo,request_id)) ENGINE=InnoDB',
];
foreach ($ddl as $name=>$sql) { $stmt=$db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $stmt->bind_param('s',$name); $stmt->execute(); if ((int)$stmt->get_result()->fetch_assoc()['n']!==0) { fwrite(STDERR,"Refusing: fixture tables already exist.\n"); exit(2); } }
$created=[]; $server=null; $pass=0; $fail=0;
$assert=static function(bool $ok,string $message) use (&$pass,&$fail): void { echo ($ok?'PASS ':'FAIL ').$message."\n"; if($ok)$pass++;else $fail++; };
try {
 foreach ($ddl as $name=>$sql) { $db->query($sql); $created[]=$name; }
 $db->query("INSERT INTO tb_estaciones VALUES (21,101,'Synthetic One',0),(22,102,'Synthetic Two',0)");
 $db->query('INSERT INTO op_rh_localidades VALUES (101,101),(102,102)');
 $db->query("INSERT INTO op_rh_v2_dispositivos VALUES (1,'SYNC-DEVICE-1',21,'Reader One','America/Mexico_City',1),(2,'SYNC-DEVICE-2',22,'Reader Two','America/Mexico_City',1)");
 $hash=password_hash('synthetic-sync-secret',PASSWORD_BCRYPT); $stmt=$db->prepare("INSERT INTO op_rh_v2_dispositivo_credenciales VALUES (1,1,'sync-key-1',?,'ACTIVE',UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL,NULL),(2,2,'sync-key-2',?,'ACTIVE',UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL,NULL)"); $stmt->bind_param('ss',$hash,$hash); $stmt->execute();
 $db->query('INSERT INTO op_rh_v2_device_biometric_grants VALUES (1,1,21,1,1,UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL,NULL),(2,2,22,2,1,UTC_TIMESTAMP()-INTERVAL 1 HOUR,NULL,NULL)');
 $db->query("INSERT INTO op_rh_puestos VALUES (1,'Synthetic Role')");
 $db->query("INSERT INTO op_rh_personal VALUES (101,21,101,'Existing Worker',1,1),(102,21,102,'Historical Worker',1,1),(103,21,103,'With Template',1,1),(104,21,104,'Without Template',1,1),(105,21,105,'Inactive Worker',1,0),(201,22,201,'Other Station',1,1)");
 $template=base64_encode('synthetic-template'); $stmt=$db->prepare('INSERT INTO op_rh_personal_acceso (id_personal,huella) VALUES (103,?)'); $stmt->bind_param('s',$template); $stmt->execute();
 $db->query("INSERT INTO op_rh_v2_dispositivo_identidad_personal (id_dispositivo,referencia_externa_personal,id_personal,valido_desde,valido_hasta,es_activo) VALUES (1,'existing-ref',101,'2020-01-01',NULL,1),(1,'historical-ref',102,'2020-01-01','2021-01-01',1)");
 $sock=stream_socket_server('tcp://127.0.0.1:0',$errno,$error); if(!$sock) throw new RuntimeException('SOCKET_FAILED'); $address=stream_socket_get_name($sock,false); fclose($sock); $port=(int)substr(strrchr($address,':'),1);
 putenv('CHECADOR_IDENTITY_SYNC_ENABLED=1'); putenv('CHECADOR_DEVICE_BIOMETRIC_GRANT_ENABLED=1'); putenv('PHP_CLI_SERVER_WORKERS=2'); putenv('SYNC_TEST_PORT='.$port);
 $server=proc_open(['setsid',PHP_BINARY,'-S','127.0.0.1:'.$port,'index.php'], [0=>['pipe','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes,dirname(__DIR__)); if(!is_resource($server)) throw new RuntimeException('SERVER_FAILED'); fclose($pipes[0]);
 $ready=false; for($i=0;$i<50;$i++){ $probe=@fsockopen('127.0.0.1',$port,$errno,$error,0.1); if($probe){fclose($probe);$ready=true;break;} usleep(100000); } if(!$ready) throw new RuntimeException('SERVER_UNAVAILABLE');
 $request=static function(int $device,string $method,string $path,?array $body=null) use ($port): array {
   $headers="X-Device-Id: $device\r\nX-Credential-Key: sync-key-$device\r\nAuthorization: Bearer synthetic-sync-secret\r\nContent-Type: application/json\r\n";
   $context=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$body===null?'':json_encode($body),'ignore_errors'=>true,'timeout'=>10]]);
   $response=@file_get_contents('http://127.0.0.1:'.$port.$path,false,$context); $code=0; foreach($http_response_header ?? [] as $line) if(preg_match('~^HTTP/\\S+ (\\d{3})~',$line,$m)) $code=(int)$m[1];
   return [$code,json_decode((string)$response,true)];
 };
 $path='/api/v2/devices/station/identities/synchronize'; $req=['request_id'=>'11111111-1111-4111-8111-111111111111'];
 [$code,$first]=$request(1,'POST',$path,$req);
 $assert($code===200 && ($first['created']??-1)===2 && ($first['preserved']??-1)===1 && ($first['conflicts']??-1)===1,'active workers with and without template synchronize; historical conflict preserved');
 [$code,$repeat]=$request(1,'POST',$path,$req); $assert($code===200 && $repeat===$first,'identical request replays persisted result');
 $db->query('UPDATE op_rh_v2_dispositivos SET id_estacion=22 WHERE id=1'); $db->query('UPDATE op_rh_v2_device_biometric_grants SET id_estacion=22 WHERE id=1');
 [$code,$conflict]=$request(1,'POST',$path,$req); $assert($code===409 && ($conflict['status']??'')==='IDEMPOTENCY_CONFLICT','reusing request after station change returns conflict');
 $db->query('UPDATE op_rh_v2_dispositivos SET id_estacion=21 WHERE id=1'); $db->query('UPDATE op_rh_v2_device_biometric_grants SET id_estacion=21 WHERE id=1');
 [$code,$second]=$request(2,'POST',$path,['request_id'=>'22222222-2222-4222-8222-222222222222']); $assert($code===200 && ($second['created']??-1)===1,'second device processes only its own station');
 [$code,$roster]=$request(1,'GET','/api/v2/devices/station/biometric-roster'); $assert($code===200 && ($roster['total_workers']??-1)===3 && ($roster['workers_with_biometrics']??-1)===1,'roster readback contains workers with and without template');
 $db->query("INSERT INTO op_rh_personal VALUES (107,21,107,'Concurrent Worker',1,1)");
 $start=static function(): array { $p=proc_open([PHP_BINARY,__FILE__,'--race'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); if(!is_resource($p))throw new RuntimeException('RACE_START_FAILED'); fclose($pipes[0]);return [$p,$pipes]; };
 $a=$start(); $b=$start(); $responses=[]; foreach([$a,$b] as [$p,$pp]) { $out=stream_get_contents($pp[1]); fclose($pp[1]); $err=stream_get_contents($pp[2]); fclose($pp[2]); $rc=proc_close($p); $responses[]=[$rc,json_decode($out,true)]; }
 $n=(int)$db->query('SELECT COUNT(*) AS n FROM op_rh_v2_dispositivo_identidad_personal WHERE id_personal=107')->fetch_assoc()['n'];
 $assert($responses[0][0]===0 && $responses[1][0]===0 && is_array($responses[0][1]) && $responses[0][1]===$responses[1][1] && ($responses[0][1]['created']??-1)===1 && $n===1,'concurrent same request returns one durable result and one identity');
 $db->query("UPDATE op_rh_v2_dispositivo_credenciales SET revocado_at=UTC_TIMESTAMP() WHERE id=1"); [$code]=$request(1,'POST',$path,['request_id'=>'33333333-3333-4333-8333-333333333333']); $assert($code===401,'revoked credential denied');
 $db->query('UPDATE op_rh_v2_dispositivo_credenciales SET revocado_at=NULL WHERE id=1'); $db->query('UPDATE op_rh_v2_device_biometric_grants SET activo=0 WHERE id=1'); [$code]=$request(1,'POST',$path,['request_id'=>'44444444-4444-4444-8444-444444444444']); $assert($code===403,'revoked grant denied'); $db->query('UPDATE op_rh_v2_device_biometric_grants SET activo=1 WHERE id=1');
 $db->query("INSERT INTO op_rh_personal VALUES (106,21,106,'Rollback Worker',1,1)"); $db->query("CREATE TRIGGER synthetic_sync_failure BEFORE INSERT ON op_rh_v2_device_identity_sync_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'");
 [$code]=$request(1,'POST',$path,['request_id'=>'55555555-5555-4555-8555-555555555555']); $n=(int)$db->query('SELECT COUNT(*) AS n FROM op_rh_v2_dispositivo_identidad_personal WHERE id_personal=106')->fetch_assoc()['n']; $assert($code===503 && $n===0,'SQL failure rolls back identity insertion'); $db->query('DROP TRIGGER synthetic_sync_failure');
 echo "RESULT: {$pass} PASS / {$fail} FAIL\n";
} catch(Throwable $e) { $fail++; fwrite(STDERR,'Test error: '.get_class($e)."\n"); }
if(is_resource($server)){ $serverPid=(int)(proc_get_status($server)['pid'] ?? 0); if($serverPid>0) @posix_kill(-$serverPid, 15); proc_terminate($server); proc_close($server); }
foreach(array_reverse($created) as $name) $db->query("DROP TABLE `$name`");
exit($fail?1:0);
