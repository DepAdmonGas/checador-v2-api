<?php
declare(strict_types=1);

// Real CLI entry points, synthetic private config, no database connection.
$root = dirname(__DIR__);
$file = tempnam(sys_get_temp_dir(), 'journey-config-');
if ($file === false) throw new RuntimeException('temp config unavailable');
chmod($file, 0600);
$base = ['service_id'=>'journey-local','system_uid'=>posix_geteuid(),'stations'=>[1],
    'execution_enabled'=>false,'batch_execution_enabled'=>false];
$passed=0; $failed=0;
$check = static function(bool $ok,string $message) use (&$passed,&$failed): void {
    echo ($ok?'PASS ':'FAIL ') . $message . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$run = static function(array $config, array $options, array $environment) use ($root,$file): array {
    file_put_contents($file,json_encode($config, JSON_THROW_ON_ERROR));
    $env = array_merge(['PATH'=>(string)getenv('PATH'),'DB_HOST'=>'localhost','DB_NAME'=>'bd_admongas'], $environment);
    $cmd = array_merge([PHP_BINARY,$root.'/scripts/journey_processor.php'], $options, ['--config='.$file]);
    $p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,$env);
    if (!is_resource($p)) throw new RuntimeException('process unavailable');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err=stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($p),$out,$err];
};
try {
    $single=['--dry-run','--station=1','--worker=1','--date=2026-11-27'];
    foreach (['--dry-run','--batch-dry-run'] as $mode) {
        $opts=$mode==='--dry-run'?$single:[$mode];
        $invalidConfigs=[['environment'=>'preprod'],['environment'=>'production'],['service_id'=>'other'],['system_uid'=>posix_geteuid()+1]];
        if ($mode === '--dry-run') $invalidConfigs[]=['stations'=>[2]];
        foreach ($invalidConfigs as $invalid) {
            // A bad database sentinel would return DATABASE_SCOPE_DENIED if the identity guard were bypassed.
            [$code,$out,$err]=$run(array_replace($base,$invalid),$opts,['DB_NAME'=>'not_authorized']);
            $check($code===2 && str_contains($err,'PROCESSOR_DENIED_OR_UNAVAILABLE'), "$mode rejects ".json_encode($invalid));
        }
        foreach ([['DB_HOST'=>'db.example'],['DB_NAME'=>'bd_admongas_v2_preprod'],['DB_NAME'=>'production']] as $invalid) {
            [$code,$out,$err]=$run($base,$opts,$invalid);
            $check($code===2 && str_contains($err,'DATABASE_SCOPE_DENIED'), "$mode rejects ".json_encode($invalid));
        }
    }
    foreach ([['--execute','--station=1','--worker=1','--date=2026-11-27'],['--batch-execute']] as $opts) {
        [$code,$out,$err]=$run($base,$opts,[]);
        $check($code===2 && (str_contains($err,'EXECUTION_DISABLED') || str_contains($err,'PROCESSOR_DENIED_OR_UNAVAILABLE')), $opts[0].' disabled before DB');
    }
    // A valid Fedora identity progresses past the scope guard to the database boundary.
    [$code,$out,$err]=$run($base,$single,['DB_NAME'=>'not_authorized']);
    $check($code===2 && str_contains($err,'DATABASE_SCOPE_DENIED'), 'valid Fedora identity reaches database boundary');
} finally { unlink($file); }
echo "RESULT: $passed PASS / $failed FAIL\n";
exit($failed?1:0);
