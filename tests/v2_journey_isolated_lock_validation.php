<?php
declare(strict_types=1);
require_once __DIR__.'/../modelo/v2/journey/JourneyExecutionCoordinator.php';
require_once __DIR__.'/../modelo/v2/journey/JourneyMariaDbLock.php';
$socket=getenv('JOURNEY_ISOLATED_SOCKET'); $data=getenv('JOURNEY_ISOLATED_DATADIR');
if (!is_string($socket) || !is_string($data) || !str_starts_with($socket,'/tmp/checador-j1-validation-') || !str_starts_with($data,'/tmp/checador-j1-validation-')) throw new RuntimeException('ISOLATED_IDENTITY_REQUIRED');
$connections=[];
try {
    for($i=0;$i<2;$i++) {
        $db=mysqli_init(); $db->real_connect('localhost','root','','bd_admongas_v2_test_20261002',0,$socket);
        $identity=$db->query('SELECT DATABASE() db, @@datadir data_dir, @@socket sock, @@port port')->fetch_assoc();
        if ($identity['db']!=='bd_admongas_v2_test_20261002' || $identity['data_dir']!==$data || $identity['sock']!==$socket || (int)$identity['port']!==0) throw new RuntimeException('ISOLATED_IDENTITY_MISMATCH');
        $connections[]=$db;
    }
    $a=new JourneyMariaDbLock($connections[0]); $b=new JourneyMariaDbLock($connections[1]); $name='isolated-j1-'.bin2hex(random_bytes(12));
    $checks=[]; $checks['first lock acquired']=$a->acquire($name); $checks['second excluded']=!$b->acquire($name);
    $a->release($name); $checks['second acquires after release']=$b->acquire($name); $b->release($name);
    $c=new JourneyExecutionCoordinator(fn($n)=>$a->acquire($name),fn($n)=>$a->release($name));
    try {$c->execute(1,1,'2026-11-27',fn()=>throw new RuntimeException('synthetic'),fn($x)=>$x,fn($x)=>$x);}catch(RuntimeException $e){}
    $checks['released after exception']=$b->acquire($name); if($checks['released after exception']) $b->release($name);
    foreach($checks as $label=>$ok) echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;
    echo 'RESULT '.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
    if(in_array(false,$checks,true)) exit(1);
} finally {foreach($connections as $db)$db->close();}
