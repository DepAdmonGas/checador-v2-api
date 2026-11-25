<?php

declare(strict_types=1);
require_once __DIR__ . '/../BD.php';
require_once __DIR__ . '/DispositivoV2.php';
require_once __DIR__ . '/DeviceBiometricGrantV2.php';
require_once __DIR__ . '/DeviceOperationRequestV2.php';
require_once __DIR__ . '/AdminPinV2Policy.php';
require_once __DIR__ . '/WorkplacePhysicalStationResolver.php';
require_once __DIR__ . '/InitialDeviceIdentityProvisioner.php';

final class DeviceIdentitySyncV2Controller
{
    public function handle(): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        $db=null;
        try {
            if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { header('Allow: POST'); $this->error(405,'METHOD_NOT_ALLOWED'); }
            if (preg_match('~^application/json(?:\s*;|$)~i',(string)($_SERVER['CONTENT_TYPE']??''))!==1) $this->error(415,'UNSUPPORTED_MEDIA_TYPE');
            $raw=file_get_contents('php://input',false,null,0,1025);$body=is_string($raw)&&strlen($raw)<=1024?json_decode($raw,true):null;
            $requestId=is_array($body)?($body['request_id']??null):null;
            if(!is_array($body)||count($body)!==1||!AdminPinV2Policy::validUuid($requestId)||strtolower((string)$requestId)!==$requestId) $this->error(400,'BAD_REQUEST');
            $deviceHeader=trim((string)($_SERVER['HTTP_X_DEVICE_ID']??''));$key=trim((string)($_SERVER['HTTP_X_CREDENTIAL_KEY']??''));$header=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
            if($deviceHeader===''||$key===''||preg_match('/\ABearer\s+(\S+)\z/i',$header,$m)!==1) $this->error(401,'DEVICE_UNAUTHORIZED');
            $activeKey=DeviceOperationRequestV2::activeKey();
            mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=(new BD())->conectarBD();
            $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->begin_transaction();
            $this->requireInnoDb($db);
            $deviceAuth=(new DispositivoV2())->autenticarCredencial($db,$deviceHeader,$key,$m[1]);
            if(empty($deviceAuth['auth'])) throw new SyncHttpException(401,'DEVICE_UNAUTHORIZED');
            $deviceId=(int)$deviceAuth['id_dispositivo'];$stationId=(int)$deviceAuth['id_estacion'];$credentialId=(int)$deviceAuth['credential_id'];
            $device=$this->one($db,'SELECT id,id_estacion,estado FROM op_rh_v2_dispositivos WHERE id=? FOR UPDATE','i',[$deviceId]);
            if(!$device||(int)$device['estado']!==1||(int)$device['id_estacion']!==$stationId) throw new SyncHttpException(401,'DEVICE_UNAUTHORIZED');
            $credential=$this->one($db,"SELECT id FROM op_rh_v2_dispositivo_credenciales WHERE id=? AND id_dispositivo=? AND estado='ACTIVE' AND revocado_at IS NULL AND valido_desde<=UTC_TIMESTAMP(6) AND (valido_hasta IS NULL OR valido_hasta>UTC_TIMESTAMP(6)) FOR UPDATE",'ii',[$credentialId,$deviceId]);
            $again=(new DispositivoV2())->autenticarCredencial($db,$deviceHeader,$key,$m[1]);
            if(!$credential||empty($again['auth'])||(int)$again['credential_id']!==$credentialId) throw new SyncHttpException(401,'DEVICE_UNAUTHORIZED');
            $grants=$this->rows($db,'SELECT id FROM op_rh_v2_device_biometric_grants WHERE id_dispositivo=? AND id_estacion=? AND id_credencial=? AND activo=1 AND revocado_at_utc IS NULL AND valido_desde_utc<=UTC_TIMESTAMP(6) AND (valido_hasta_utc IS NULL OR UTC_TIMESTAMP(6)<valido_hasta_utc) FOR UPDATE','iii',[$deviceId,$stationId,$credentialId]);
            $locality=WorkplacePhysicalStationResolver::resolveCanonicalLocality($db,$stationId);
            if(count($grants)!==1||$locality===null) throw new SyncHttpException(403,'DEVICE_SCOPE_DENIED');
            $canonical=['operation_kind'=>'IDENTITY_SYNC','device_id'=>$deviceId,'station_id'=>$stationId,'credential_id'=>$credentialId,'request_id'=>$requestId];
            $fingerprint=DeviceOperationRequestV2::fingerprint($activeKey['key'],$canonical);
            $prior=$this->one($db,'SELECT operation_kind,fingerprint_key_id,request_fingerprint,response_status,response_json FROM op_rh_v2_device_operation_requests WHERE id_dispositivo=? AND request_id=? FOR UPDATE','is',[$deviceId,$requestId]);
            if($prior){
                $oldFingerprint=DeviceOperationRequestV2::fingerprint(DeviceOperationRequestV2::key((string)$prior['fingerprint_key_id']),$canonical);
                if($prior['operation_kind']!=='IDENTITY_SYNC'||!hash_equals((string)$prior['request_fingerprint'],$oldFingerprint)) throw new SyncHttpException(409,'IDEMPOTENCY_CONFLICT');
                $result=json_decode((string)$prior['response_json'],true,512,JSON_THROW_ON_ERROR);
                if(!is_array($result)) throw new RuntimeException('INVALID_RECORDED_RESPONSE');
            } else {
                $counts=(new InitialDeviceIdentityProvisioner($db))->run(true,$deviceId,$stationId,true,true);
                $result=['request_id'=>$requestId,'created'=>(int)$counts['insertadas'],'preserved'=>(int)$counts['conservar'],'conflicts'=>(int)$counts['rechazar']];
                $this->saveRequest($db,$deviceId,$stationId,$credentialId,$requestId,$activeKey,$fingerprint,$result);
            }
            $db->commit();$db->close();$this->success(200,'OK',$result);
        } catch(SyncHttpException $e) {
            if($db instanceof mysqli){try{$db->rollback();$db->close();}catch(Throwable){}}
            $this->error($e->httpStatus,$e->publicStatus);
        } catch(Throwable $e) {
            if($db instanceof mysqli){try{$db->rollback();$db->close();}catch(Throwable){}}
            error_log((string)json_encode(['event'=>'CHECADOR_IDENTITY_SYNC_FAILURE','exception_class'=>get_class($e)],JSON_UNESCAPED_SLASHES));
            $this->error(503,'SYNC_UNAVAILABLE');
        }
    }

    private function requireInnoDb(mysqli $db):void
    {
        foreach(['op_rh_v2_dispositivos','op_rh_v2_dispositivo_credenciales','op_rh_v2_device_biometric_grants','op_rh_v2_device_operation_requests','tb_estaciones','op_rh_localidades','op_rh_personal','op_rh_personal_localidad_version','op_rh_v2_dispositivo_identidad_personal'] as $table){
            $row=$this->one($db,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?','s',[$table]);if(strtoupper((string)($row['ENGINE']??''))!=='INNODB')throw new RuntimeException('TRANSACTIONAL_TABLE_REQUIRED');
        }
    }

    private function saveRequest(mysqli $db,int $deviceId,int $stationId,int $credentialId,string $requestId,array $key,string $fingerprint,array $result):void
    {
        $json=json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $stmt=$db->prepare("INSERT INTO op_rh_v2_device_operation_requests (id_dispositivo,id_estacion,id_credencial,request_id,operation_kind,fingerprint_key_id,request_fingerprint,id_personal,actor_user_id,biometric_revision,response_status,response_json,created_at_utc) VALUES (?,?,?,?,'IDENTITY_SYNC',?,?,NULL,NULL,NULL,200,?,UTC_TIMESTAMP(6))");
        $stmt->bind_param('iiissss',$deviceId,$stationId,$credentialId,$requestId,$key['key_id'],$fingerprint,$json);$stmt->execute();$stmt->close();
    }
    private function rows(mysqli $db,string $sql,string $types,array $params):array{$stmt=$db->prepare($sql);if($types!=='')$this->bind($stmt,$types,$params);$stmt->execute();$res=$stmt->get_result();$rows=$res?$res->fetch_all(MYSQLI_ASSOC):[];$stmt->close();return $rows;}
    private function one(mysqli $db,string $sql,string $types,array $params):?array{return $this->rows($db,$sql,$types,$params)[0]??null;}
    private function bind(mysqli_stmt $stmt,string $types,array &$params):void{$args=[$types];foreach($params as &$v)$args[]=&$v;unset($v);if(!call_user_func_array([$stmt,'bind_param'],$args))throw new RuntimeException('DATABASE_BIND_FAILED');}
    private function success(int $code,string $status,array $data):never{http_response_code($code);echo json_encode(['code'=>$code,'status'=>$status,'data'=>$data],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
    private function error(int $code,string $status):never{http_response_code($code);echo json_encode(['code'=>$code,'status'=>$status],JSON_UNESCAPED_SLASHES);exit;}
}

final class SyncHttpException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus,public readonly string $publicStatus){parent::__construct($publicStatus);}
}
