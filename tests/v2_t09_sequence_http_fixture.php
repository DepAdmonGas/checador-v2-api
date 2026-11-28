<?php
declare(strict_types=1);
require_once __DIR__.'/../modelo/v2/Level2ServerService.php';
require_once __DIR__.'/../modelo/v2/PunchEventIntegrityService.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$socket=getenv('T09_TEST_SOCKET');$url=getenv('T09_TEST_URL');
if(!is_string($socket)||!preg_match('~^/tmp/checador-sequence-[A-Za-z0-9]+/mysql\.sock$~D',$socket)||is_link($socket)||$url!=='http://127.0.0.1:18091/api/v2/punch-events')throw new RuntimeException('ISOLATION_GUARD');
$root=realpath(dirname($socket));$db=new mysqli('localhost','root','','checador_t09_isolated',0,$socket);$id=$db->query('SELECT DATABASE() d,@@datadir p,@@socket s,@@port n')->fetch_assoc();
if($id['d']!=='checador_t09_isolated'||realpath($id['p'])!==$root.'/data'||$id['s']!==$socket||(int)$id['n']!==0)throw new RuntimeException('ISOLATION_GUARD');
if((int)$db->query('SELECT COUNT(*) n FROM op_rh_v2_dispositivos')->fetch_assoc()['n']!==0)throw new RuntimeException('EMPTY_BASELINE_REQUIRED');
$uuid='T09_SYNTH_DEVICE';$key='T09_SYNTH_KEY';$secret=bin2hex(random_bytes(24));$now=gmdate('Y-m-d H:i:s');
$db->query("INSERT INTO tb_estaciones(id,numlista,estatus) VALUES(501,501,0)");$db->query("INSERT INTO op_rh_localidades(id,numlista,localidad,recuperacion_vapores) VALUES(701,501,'Synthetic',0)");$db->query("INSERT INTO op_rh_personal(id,no_colaborador,nombre_completo,id_estacion) VALUES(9001,999,'Synthetic',701)");
$q=$db->prepare("INSERT INTO op_rh_v2_dispositivos(uuid_dispositivo,id_estacion,nombre,timezone,estado) VALUES(?,501,'Synthetic','UTC',1)");$q->bind_param('s',$uuid);$q->execute();$device=(int)$q->insert_id;$hash=password_hash($secret,PASSWORD_BCRYPT);
$q=$db->prepare("INSERT INTO op_rh_v2_dispositivo_credenciales(id_dispositivo,credential_key,secret_hash,valido_desde,valido_hasta,estado,creado_at) VALUES(?,?,?,'2020-01-01',NULL,'ACTIVE',?)");$q->bind_param('isss',$device,$key,$hash,$now);$q->execute();
$db->query("INSERT INTO op_rh_v2_dispositivo_identidad_personal(id_dispositivo,referencia_externa_personal,id_personal,valido_desde,valido_hasta,es_activo) VALUES($device,'synthetic-worker-1',9001,'2020-01-01',NULL,1)");
$l2=new Level2ServerService();$gen='synthetic-generation-1';if(empty($l2->registrarGeneracion($db,$device,$uuid,$gen)['success'])||empty($l2->registrarAsignacionEstacion($db,$device,501,'2020-01-01 00:00:00')['success']))throw new RuntimeException('DEVICE_FIXTURE_FAILED');
$original=[];foreach([1,3,2] as $seq){
 $event='T09-EVENT-'.$seq;$body=['event_uuid'=>$event,'external_worker_reference'=>'synthetic-worker-1','source_timestamp'=>gmdate('Y-m-d\TH:i:s\Z',time()-60+$seq),'source_timezone'=>'UTC','capture_method'=>'BIOMETRIC','device_generation'=>$gen,'device_sequence'=>$seq,'metadata'=>['fixture'=>'synthetic']];
 $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-Device-Id: $uuid\r\nX-Credential-Key: $key\r\nAuthorization: Bearer $secret\r\n",'content'=>json_encode($body),'ignore_errors'=>true,'timeout'=>10]]);$out=file_get_contents($url,false,$ctx);$headers=$http_response_header??[];$status=0;foreach($headers as $h)if(preg_match('~^HTTP/\\S+ (\\d+)~',$h,$m))$status=(int)$m[1];$res=json_decode((string)$out,true);if($status!==201||($res['status']??'')!=='ACCEPTED')throw new RuntimeException('HTTP_INGESTION_FAILED seq='.$seq.' http='.$status.' body='.json_encode($res));
 $wm=$l2->calcularAcceptedThrough($db,$device,$gen);$expected=$seq===2?3:1;if($wm!==$expected)throw new RuntimeException('WATERMARK_MISMATCH');
 $rows=$db->query("SELECT event_uuid,integrity_version,integrity_key_id,integrity_seal,id_dispositivo,id_estacion_resuelta,referencia_externa_personal,id_personal_resuelto,source_timestamp_raw,source_timezone_reported,effective_timezone,timezone_fallback_applied,ocurrido_at_utc,recibido_at_utc,metodo_captura,external_event_id,secuencia_dispositivo,validation_flags,metadata_json FROM op_rh_v2_marcaciones_crudas ORDER BY id")->fetch_all(MYSQLI_ASSOC);foreach($original as $i=>$old)if($rows[$i]!==$old)throw new RuntimeException('HISTORICAL_ROW_CHANGED');$original=$rows;
 foreach($rows as $row)if(PunchEventIntegrityService::verify($row,['test-key-v1'=>'synthetic-local-hmac-secret-for-integration-only'])!==PunchEventIntegrityService::RESULT_VALID)throw new RuntimeException('INTEGRITY_INVALID');
 echo 'HTTP_SEQUENCE '.json_encode(['sequence'=>$seq,'http'=>$status,'accepted_through'=>$wm,'raw_count'=>count($rows)]).PHP_EOL;
 if($seq===3&&getenv('T09_PAUSE_AFTER_GAP')==='1'){echo "GAP_READY: press Enter to deliver sequence 2\n";if(fgets(STDIN)===false)throw new RuntimeException('GAP_RESUME_CANCELLED');}
}
$meta=$db->query("SELECT m.device_generation,m.device_sequence,r.event_uuid,r.ocurrido_at_utc,r.recibido_at_utc FROM op_rh_v2_marcaciones_nivel2_metadata m JOIN op_rh_v2_marcaciones_crudas r ON r.id=m.id_raw_punch WHERE m.id_dispositivo=$device ORDER BY m.device_sequence")->fetch_all(MYSQLI_ASSOC);echo 'SQL_READBACK '.json_encode($meta).PHP_EOL;if(count($meta)!==3||array_column($meta,'device_sequence')!==['1','2','3'])throw new RuntimeException('METADATA_MISMATCH');echo "T09_A PASS\n";
