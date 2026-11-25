<?php

declare(strict_types=1);

// Read-only inventory confined to local bd_admongas; it never emits credentials or row data.
$host=(string)(getenv('DB_HOST')?:'');$name=(string)(getenv('DB_NAME')?:'');
if(!in_array($host,['127.0.0.1','localhost','::1'],true)||$name!=='bd_admongas') { fwrite(STDERR,"Refusing: only loopback bd_admongas is allowed.\n");exit(2); }
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli($host,(string)getenv('DB_USER'),(string)getenv('DB_PASS'),$name,(int)(getenv('DB_PORT')?:3306));
$db->set_charset('utf8mb4');
$identity=$db->query('SELECT DATABASE() AS database_name,CURRENT_USER() AS principal,@@hostname AS server_host,VERSION() AS server_version')->fetch_assoc();
// Refuse local TCP forwards or containers unless the MariaDB server identifies
// itself as this host; an owner can run the same SELECT directly to confirm it.
$localHost=strtolower((string)gethostname());
if (strtolower((string)($identity['server_host']??''))!==$localHost) { fwrite(STDERR,"Refusing: MariaDB @@hostname does not match this host.\n");exit(2); }
echo 'RUNTIME_IDENTITY '.json_encode($identity,JSON_UNESCAPED_SLASHES).PHP_EOL;
$tables=['op_rh_v2_admin_pin','op_rh_v2_admin_session','op_rh_v2_admin_pin_limit','op_rh_v2_dispositivos','op_rh_v2_dispositivo_credenciales','tb_estaciones','op_rh_localidades','tb_usuarios','op_rh_v2_m11_configuration_audit'];
$found=[];
foreach($tables as $table){
 $stmt=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$stmt->bind_param('s',$table);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
 $found[$table]=$row['ENGINE']??null;
 echo 'TABLE '.json_encode(['name'=>$table,'engine'=>$found[$table]],JSON_UNESCAPED_SLASHES).PHP_EOL;
 if($found[$table]===null)continue;
 $stmt=$db->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,EXTRA,GENERATION_EXPRESSION,DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');$stmt->bind_param('s',$table);$stmt->execute();$rows=$stmt->get_result();
 while($column=$rows->fetch_assoc()) echo 'COLUMN '.json_encode(['table'=>$table]+$column,JSON_UNESCAPED_SLASHES).PHP_EOL;
 $stmt->close();
 $hasIndexExpression=(int)$db->query("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='information_schema' AND TABLE_NAME='STATISTICS' AND COLUMN_NAME='EXPRESSION'")->fetch_assoc()['n']>0;
 $indexSql=$hasIndexExpression
  ? 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX'
  : 'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX';
 $stmt=$db->prepare($indexSql);$stmt->bind_param('s',$table);$stmt->execute();$rows=$stmt->get_result();
 while($index=$rows->fetch_assoc()) echo 'INDEX '.json_encode(['table'=>$table]+$index,JSON_UNESCAPED_SLASHES).PHP_EOL;
 $stmt->close();
}
$refs=[
 'op_rh_v2_admin_session.pin_id'=>'op_rh_v2_admin_pin.id',
 'op_rh_v2_admin_pin.device_id'=>'op_rh_v2_dispositivos.id',
 'op_rh_v2_admin_pin.credential_id'=>'op_rh_v2_dispositivo_credenciales.id',
 'op_rh_v2_admin_pin.station_id'=>'tb_estaciones.id',
 'op_rh_v2_admin_pin.locality_id'=>'op_rh_localidades.id',
 'op_rh_v2_admin_pin.actor_user_id'=>'tb_usuarios.id',
 'op_rh_v2_admin_session.credential_id'=>'op_rh_v2_dispositivo_credenciales.id',
 'op_rh_v2_admin_session.device_id'=>'op_rh_v2_dispositivos.id',
 'op_rh_v2_admin_session.station_id'=>'tb_estaciones.id',
 'op_rh_v2_admin_session.locality_id'=>'op_rh_localidades.id',
 'op_rh_v2_admin_session.actor_user_id'=>'tb_usuarios.id',
 'op_rh_v2_admin_pin_limit.device_id'=>'op_rh_v2_dispositivos.id',
];
foreach($refs as $from=>$to){[$ft,$fc]=explode('.',$from);[$tt,$tc]=explode('.',$to);$stmt=$db->prepare('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME=? AND COLUMN_NAME=?) OR (TABLE_NAME=? AND COLUMN_NAME=?))');$stmt->bind_param('ssss',$ft,$fc,$tt,$tc);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();$types=[];foreach($rows as $r)$types[$r['TABLE_NAME'].'.'.$r['COLUMN_NAME']]=$r['COLUMN_TYPE'];$same=isset($types[$from],$types[$to])&&$types[$from]===$types[$to];echo 'REFERENCE '.json_encode(['columns'=>[$from=>$types[$from]??null,$to=>$types[$to]??null],'compatible_type'=>$same],JSON_UNESCAPED_SLASHES).PHP_EOL;}
$constraints=$db->query("SELECT TABLE_NAME,COLUMN_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('op_rh_v2_admin_pin','op_rh_v2_admin_session','op_rh_v2_admin_pin_limit') AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION");
while($constraint=$constraints->fetch_assoc())echo 'FOREIGN_KEY '.json_encode($constraint,JSON_UNESCAPED_SLASHES).PHP_EOL;
echo 'RUNTIME_PRIVILEGES_REQUIRED '.json_encode([
 'portal'=>['SELECT','INSERT','UPDATE on admin PIN/session/limit and configuration audit; SELECT on device, credential, grant, user, station, locality, and authorization tables'],
 'api'=>['SELECT','INSERT','UPDATE on admin PIN/session/limit; INSERT on configuration audit; SELECT on device, credential, and biometric grant tables'],
 'migration'=>['CREATE, ALTER, INDEX on the target schema; not required by runtime accounts'],
],JSON_UNESCAPED_SLASHES).PHP_EOL;
foreach($db->query('SHOW GRANTS FOR CURRENT_USER()') as $grant){
 $text=(string)array_values($grant)[0];
 $text=preg_replace("/ IDENTIFIED BY PASSWORD '[^']*'/i"," IDENTIFIED BY PASSWORD '[REDACTED]'",$text)??$text;
 $text=preg_replace("/ IDENTIFIED BY '[^']*'/i"," IDENTIFIED BY '[REDACTED]'",$text)??$text;
 echo 'GRANT '.json_encode($text,JSON_UNESCAPED_SLASHES).PHP_EOL;
}
$db->close();
