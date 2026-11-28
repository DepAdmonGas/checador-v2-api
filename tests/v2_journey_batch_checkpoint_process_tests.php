<?php
declare(strict_types=1);
require_once __DIR__.'/../scripts/JourneyBatchProcessor.php';
if(!function_exists('pcntl_fork'))throw new RuntimeException('PCNTL_REQUIRED');
$dir=sys_get_temp_dir().'/journey-checkpoint-process-'.bin2hex(random_bytes(8));if(!mkdir($dir,0700))throw new RuntimeException('TMP_FAILED');
$key=hash('sha256','synthetic-checkpoint-process');$s=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,0);if($s===false)throw new RuntimeException('IPC_FAILED');$pid=pcntl_fork();
if($pid===0){fclose($s[0]);try{$cp=new JourneyBatchCheckpoint($dir,$key);fwrite($s[1],"LOCKED\n");fgets($s[1]);$cp->close();fwrite($s[1],"RELEASED\n");exit(0);}catch(Throwable $e){fwrite($s[1],"ERROR\n");exit(1);}}
fclose($s[1]);try {if(trim((string)fgets($s[0]))!=='LOCKED')throw new RuntimeException('CHILD_NOT_READY');$blocked=false;try{$cp=new JourneyBatchCheckpoint($dir,$key);$cp->close();}catch(RuntimeException $e){$blocked=$e->getMessage()==='BATCH_ALREADY_RUNNING';}if(!$blocked)throw new RuntimeException('EXCLUSION_FAILED');fwrite($s[0],"RELEASE\n");if(trim((string)fgets($s[0]))!=='RELEASED')throw new RuntimeException('CHILD_RELEASE_FAILED');pcntl_waitpid($pid,$status);if(pcntl_wexitstatus($status)!==0)throw new RuntimeException('CHILD_FAILED');$cp=new JourneyBatchCheckpoint($dir,$key);$cp->close();echo 'CHECKPOINT_PROCESS_PASS '.json_encode(['parent_pid'=>getmypid(),'child_pid'=>$pid,'second_process_excluded'=>true,'reacquired'=>true]).PHP_EOL;}finally{fclose($s[0]);foreach(glob($dir.'/*')?:[] as $f)unlink($f);rmdir($dir);}
