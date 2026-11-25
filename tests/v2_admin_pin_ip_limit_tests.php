<?php
declare(strict_types=1);
require_once __DIR__.'/../modelo/v2/AdminPinIpRateLimit.php';
if (($argv[1] ?? '') === 'worker') { $r=AdminPinIpRateLimit::attempt($argv[2],$argv[3],fn()=>['status_code'=>401],10000); echo $r['status_code']; exit; }
$dir=sys_get_temp_dir().'/admin-limit-'.bin2hex(random_bytes(8)); mkdir($dir,0700); $n=0;$fail=0;
$test=function($ok,$name)use(&$n,&$fail){$n++;if(!$ok)$fail++;echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;};
try {
 $bad=fn()=>['status_code'=>401]; $ip='192.0.2.1';
 for($i=0;$i<59;$i++) $r=AdminPinIpRateLimit::attempt($dir,$ip,$bad,10000);
 $test($r['status_code']===401,'59 failures allowed');
 $test(AdminPinIpRateLimit::attempt($dir,$ip,$bad,10000)['status_code']===429,'60th failure blocks');
 $test(AdminPinIpRateLimit::attempt($dir,$ip,$bad,10899)['status_code']===429 && AdminPinIpRateLimit::attempt($dir,$ip,$bad,10900)['status_code']===401,'window expires at 15 minutes');
 $test(AdminPinIpRateLimit::attempt($dir,'192.0.2.2',$bad,10000)['status_code']===401,'addresses independent');
 $cmd=[PHP_BINARY,__FILE__,'worker',$dir,'192.0.2.3']; $pipes=[];$p=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes); $out=stream_get_contents($pipes[1]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);
 $path=$dir.'/admin-pin-'.hash('sha256','192.0.2.3').'.limit'; $test($out==='401' && count(json_decode(file_get_contents($path),true))===1,'persistence between processes');
 $ps=[];for($i=0;$i<70;$i++){ $pp=[];$pr=proc_open([PHP_BINARY,__FILE__,'worker',$dir,'192.0.2.4'],[1=>['pipe','w'],2=>['pipe','w']],$pp);$ps[]=[$pr,$pp]; } $codes=[];foreach($ps as [$pr,$pp]){$codes[]=stream_get_contents($pp[1]);fclose($pp[1]);fclose($pp[2]);proc_close($pr);} $test(count(array_filter($codes,fn($c)=>$c==='401'))===59 && count(array_filter($codes,fn($c)=>$c==='429'))===11,'concurrent processes serialize at limit');
 $path=$dir.'/admin-pin-'.hash('sha256','192.0.2.5').'.limit';file_put_contents($path,'invalid');try{AdminPinIpRateLimit::attempt($dir,'192.0.2.5',$bad,10000);$ok=false;}catch(RuntimeException $e){$ok=true;}$test($ok,'corrupt storage fails closed');
 try{AdminPinIpRateLimit::attempt($dir.'/missing','192.0.2.6',$bad,10000);$ok=false;}catch(RuntimeException $e){$ok=true;}$test($ok,'unavailable storage fails closed');
 try{AdminPinIpRateLimit::attempt($dir,'192.0.2.7',function(){throw new RuntimeException('simulated');},10000);}catch(RuntimeException $e){} $path=$dir.'/admin-pin-'.hash('sha256','192.0.2.7').'.limit';$test(count(json_decode(file_get_contents($path),true))===1,'interrupted operation preserves reservation');
 $contents=implode('',array_map('file_get_contents',glob($dir.'/*')));$test(!str_contains($contents,'secret')&&!str_contains($contents,'401'),'storage contains only timestamps');
} finally {foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);} echo "RESULT: $n checks / $fail failures\n";exit($fail?1:0);
