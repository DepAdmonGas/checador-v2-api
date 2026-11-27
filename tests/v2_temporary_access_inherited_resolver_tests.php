<?php

declare(strict_types=1);
require_once __DIR__ . '/../modelo/v2/Portal3JwtAuthHelper.php';
final class InheritedRows { private int $i=0; public function __construct(private array $rows) {} public function fetch_assoc(): ?array { return $this->rows[$this->i++] ?? null; } }
final class InheritedStatement {
 private array $params=[];
 public function __construct(private InheritedDb $db,private string $sql) {}
 public function bind_param(string $types,&...$params): bool { foreach($params as &$v)$this->params[]=&$v; return true; }
 public function execute(): bool { return true; }
 public function close(): void {}
 public function get_result(): InheritedRows { return new InheritedRows($this->db->select($this->sql,$this->params)); }
}
final class InheritedDb {
 public array $data=['active'=>true,'position'=>6,'home'=>21,'direct'=>false,'parent'=>['leer'=>1,'editar'=>1],'sub'=>true,'specific'=>[],'config'=>'[21,22]','allow_all'=>0,'mapping'=>true,'temporaryModule'=>false];
 public bool $broken=false;
 public function prepare(string $sql): InheritedStatement|false { return $this->broken ? false : new InheritedStatement($this,$sql); }
 public function select(string $sql,array $p): array {
  $d=$this->data;
  if(str_contains($sql,'FROM tb_usuarios WHERE')) return $d['active'] ? [['id'=>10,'id_puesto'=>$d['position'],'id_gas'=>$d['home']]] : [];
  if(str_contains($sql,'FROM modulos_dpto_operativo WHERE clave=? AND activo=1')) return [['id'=>1]];
  if(str_contains($sql,'FROM modulos_dpto_operativo WHERE clave=?')) return $d['temporaryModule'] ? [['id'=>2,'activo'=>0]] : [];
  if(str_contains($sql,'FROM modulos_usuarios_do u INNER JOIN')) return $d['direct'] ? [['id'=>1]] : [];
  if(str_contains($sql,'SELECT leer, editar FROM modulos_usuarios_do')) return $p[0]===2 ? $d['specific'] : [$d['parent']];
  if(str_contains($sql,'SELECT leer, editar FROM modulos_puestos_do')) return [$d['parent']];
  if(str_contains($sql,'FROM modulos_sub_dpto_operativo')) return [['id'=>3]];
  if(str_contains($sql,'FROM modulos_sub_usuarios_do') || str_contains($sql,'FROM modulos_sub_puestos_do')) return $d['sub'] ? [['id'=>1]] : [];
  if(str_contains($sql,'FROM tb_modulos_config')) return [['tipo'=>'stations_only','tipo_departamento'=>'puestos','allow_all'=>$d['allow_all'],'estaciones_soportadas'=>$d['config']]];
  if(str_contains($sql,'SELECT id, numlista, estatus FROM tb_estaciones')) return [['id'=>(int)$p[0], 'numlista'=>(int)$p[0]+100, 'estatus'=>0]];
  if(str_contains($sql,'SELECT COUNT(*) as cnt FROM tb_estaciones')) return [['cnt'=>1]];
  if(str_contains($sql,'SELECT COUNT(*) as cnt FROM op_rh_localidades')) return [['cnt'=>$d['mapping']?1:2]];
  if(str_contains($sql,'SELECT id FROM op_rh_localidades')) return [['id'=>(int)$p[0]+1000]];
  throw new RuntimeException('UNEXPECTED_QUERY');
 }
}
$pass=0;$fail=0;$test=static function(bool $ok,string $name) use (&$pass,&$fail):void { echo ($ok?'PASS ':'FAIL ').$name."\n"; if($ok)$pass++;else $fail++; };
$db=new InheritedDb();$user=['user_id'=>10];
$run=static fn(string $action):array=>Portal3JwtAuthHelper::authorizeTemporaryAccessInheritedPrepared($db,$user,$action);
$r=$run('editar');$test(($r['code']??0)===200 && ($r['authorized_physical_station_ids']??[])===[21] && ($r['authorized_stations']??[])===[1121],'Encargado allowed with no acceso-temporal module, grants, or locality config');
$db->data['temporaryModule']=true;$db->data['direct']=true;$db->data['specific']=[['leer'=>0,'editar'=>1]];$test(($run('leer')['code']??0)===403 && ($run('editar')['code']??0)===403,'individual explicit read denial blocks queue and writes');
$db->data['specific']=[['leer'=>1,'editar'=>0]];$test(($run('editar')['code']??0)===403,'individual explicit edit denial');
$db->data['specific']=[];$db->data['parent']=['leer'=>1,'editar'=>0];$test(($run('crear')['code']??0)===403,'institutional edit required');
$db->data['parent']=['leer'=>1,'editar'=>1];$db->data['home']=99;$test(($run('leer')['status']??'')==='STATION_SCOPE_DENIED','foreign station denied');
$db->data['position']=13;$test(($run('leer')['code']??0)===200 && ($run('editar')['code']??0)===403,'Direccion reads and cannot write');
$db->data['position']=3;$test(($run('leer')['code']??0)===403,'other role denied');
$db->data['active']=false;$test(($run('leer')['code']??0)===401,'inactive user denied');
$db->data['active']=true;$db->data['position']=6;$db->data['home']=21;$db->data['mapping']=false;$test(($run('leer')['status']??'')==='STATION_SCOPE_DENIED','ambiguous station mapping denied');
$db->data['mapping']=true;$db->data['allow_all']=1;$test(($run('leer')['status']??'')==='STATION_SCOPE_DENIED','allow_all denied');
$db->broken=true;$test(($run('leer')['code']??0)===503,'dependency failure distinguished from authorization denial');
$controllerSource=file_get_contents(__DIR__.'/../modelo/v2/TemporaryAccessV2Controller.php') ?: '';
$test(str_contains($controllerSource, 'authorizeTemporaryAccessInheritedPrepared($conexion, $tokenVal, $requiredPermission)')
    && !str_contains($controllerSource, 'authorizeTemporaryAccess($conexion, $tokenVal, $requiredPermission)'), 'temporary access supervisor routes use the approved inherited resolver');
$adminPinSource=file_get_contents(__DIR__.'/../modelo/v2/AdminPinV2Controller.php') ?: '';
$test(str_contains($adminPinSource, 'authorizeAdminChecador(')
    && !str_contains($adminPinSource, 'authorizeTemporaryAccessInheritedPrepared('), 'administrative configuration PIN keeps its independent authorization path');
echo "RESULT: {$pass} PASS / {$fail} FAIL\n";exit($fail?1:0);
