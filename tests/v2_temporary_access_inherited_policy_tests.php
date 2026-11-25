<?php

declare(strict_types=1);
require_once __DIR__ . '/../modelo/v2/TemporaryAccessInheritedPolicy.php';
$pass=0; $fail=0;
$check=static function(bool $ok,string $label) use (&$pass,&$fail):void { echo ($ok?'PASS ':'FAIL ').$label."\n"; if($ok)$pass++;else $fail++; };
$allow=static fn(int $p,string $a,bool $active=true,bool $scope=true,bool $sub=true,array $parent=['leer'=>1,'editar'=>1],array $specific=[]):bool => TemporaryAccessInheritedPolicy::allows($p,$a,$active,$scope,$sub,$parent,$specific);
$check($allow(6,'editar'),'Encargado with institutional permissions and no specific grant');
$check(!$allow(6,'editar',true,true,true,['leer'=>1,'editar'=>1],[['leer'=>1,'editar'=>0]]),'explicit individual edit denial takes precedence');
$check(!$allow(6,'leer',true,true,true,['leer'=>1,'editar'=>1],[['leer'=>0,'editar'=>1]]),'explicit individual read denial takes precedence');
$check(!$allow(6,'editar',true,true,true,['leer'=>1,'editar'=>0]),'parent edit permission required');
$check(!$allow(6,'editar',true,false),'out of scope denied');
$check($allow(13,'leer'),'Direccion may read');
$check(!$allow(13,'editar'),'Direccion may not write');
$check(!$allow(3,'leer'),'other roles denied');
$check(!$allow(6,'leer',false),'inactive user denied');
$check(!$allow(6,'leer',true,true,false),'missing submodule denied');
$check(!$allow(6,'leer',true,true,true,['leer'=>1,'editar'=>1],[['leer'=>1],['leer'=>1]]),'duplicate individual grants fail closed');
$check(!$allow(6,'editar',true,true,true,['leer'=>0,'editar'=>1]),'read permission required for writes');
$portalPolicy = '/home/silvinol/Proyectos/Portal3/app/Services/ChecadorTemporaryAccessInheritedPolicy.php';
if (is_file($portalPolicy)) {
    require_once $portalPolicy;
    $equivalent = true;
    foreach ([3,6,13] as $position) foreach (['leer','editar'] as $action)
      foreach ([false,true] as $active) foreach ([false,true] as $scope) foreach ([false,true] as $submodule)
      foreach ([['leer'=>0,'editar'=>0],['leer'=>1,'editar'=>0],['leer'=>1,'editar'=>1]] as $parent)
      foreach ([[],[['leer'=>0,'editar'=>0]],[['leer'=>1,'editar'=>0]],[['leer'=>1,'editar'=>1]],[['leer'=>1],['leer'=>1]]] as $specific) {
        $args=[$position,$action,$active,$scope,$submodule,$parent,$specific];
        if (TemporaryAccessInheritedPolicy::allows(...$args) !== \App\Services\ChecadorTemporaryAccessInheritedPolicy::allows(...$args)) $equivalent=false;
      }
    $check($equivalent,'Portal3 and API pure policy agree across 720 scenarios');
} else { echo "SKIP Portal3 policy file unavailable\n"; }
echo "RESULT: {$pass} PASS / {$fail} FAIL\n"; exit($fail?1:0);
