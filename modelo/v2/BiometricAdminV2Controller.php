<?php

declare(strict_types=1);
require_once __DIR__ . '/../BD.php';
require_once __DIR__ . '/DispositivoV2.php';
require_once __DIR__ . '/DeviceBiometricGrantV2.php';
require_once __DIR__ . '/DeviceOperationRequestV2.php';
require_once __DIR__ . '/BiometricAdminV2Policy.php';
require_once __DIR__ . '/AdminPinV2Policy.php';
require_once __DIR__ . '/WorkplacePhysicalStationResolver.php';
require_once __DIR__ . '/Portal3JwtAuthHelper.php';
require_once __DIR__ . '/shift/ShiftWorkerMembershipService.php';
require_once __DIR__ . '/PunchEventV2.php';

final class BiometricAdminV2HttpException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, public readonly string $publicStatus)
    { parent::__construct($publicStatus); }
}

/** Feature-gated administrative roster and transactional biometric writes. */
final class BiometricAdminV2Controller
{
    public static function enabled(): bool
    {
        return getenv('CHECADOR_ADMIN_PIN_ENABLED') === '1'
            && getenv('CHECADOR_BIOMETRIC_ADMIN_ENABLED') === '1';
    }

    /** @param list<string> $path */
    public function handle(array $path, string $method): never
    {
        $db = null;
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');
        if (!self::enabled()) $this->respond(503, 'BIOMETRIC_ADMIN_UNAVAILABLE');
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $db = (new BD())->conectarBD();
            $this->requireInnoDb($db);
            if ($path === [] && $method === 'GET') {
                $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
                $offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);
                $limit = $limit === null || $limit === false ? 100 : $limit;
                $offset = $offset === null || $offset === false ? 0 : $offset;
                if ($limit < 1 || $limit > 200 || $offset < 0) $this->respond(400, 'BAD_REQUEST');
                $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $db->begin_transaction();
                $auth = $this->authorizeDeviceAndAdmin($db);
                $workers = $this->listWorkers($db, $auth, $limit, $offset);
                $db->commit(); $db->close();
                $this->respond(200, 'OK', ['workers'=>$workers,'limit'=>$limit,'offset'=>$offset]);
            }
            if (count($path) === 2 && $method === 'GET' && $path[1] === 'biometric-status') {
                $reference = rawurldecode($path[0]);
                if (!BiometricAdminV2Policy::validateReference($reference)) $this->respond(400, 'BAD_REQUEST');
                $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $db->begin_transaction();
                $auth = $this->authorizeDeviceAndAdmin($db);
                $worker = $this->resolveWorker($db, $auth, $reference, false);
                $result = $this->readStatus($db, $auth, $worker);
                $db->commit(); $db->close();
                $this->respond(200, 'OK', $result);
            }
            if (count($path) === 2 && $method === 'POST' && $path[1] === 'biometric-enrollments') {
                $reference = rawurldecode($path[0]);
                if (!BiometricAdminV2Policy::validateReference($reference)) $this->respond(400, 'BAD_REQUEST');
                if (preg_match('~^application/json(?:\s*;|$)~i',(string)($_SERVER['CONTENT_TYPE']??''))!==1) $this->respond(415,'UNSUPPORTED_MEDIA_TYPE');
                $raw = file_get_contents('php://input', false, null, 0, 4097);
                if (!is_string($raw) || strlen($raw) > 4096) $this->respond(413, 'PAYLOAD_TOO_LARGE');
                $body = json_decode($raw, true);
                if (!is_array($body)) $this->respond(400, 'BAD_REQUEST');
                $request = BiometricAdminV2Policy::validatePayload($body);
                $activeKey = DeviceOperationRequestV2::activeKey();
                $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $db->begin_transaction();
                $auth = $this->authorizeDeviceAndAdmin($db);
                $worker = $this->resolveWorker($db, $auth, $reference, true);
                $result = $this->mutateBiometric($db, $auth, $worker, $request, $activeKey);
                $db->commit(); $db->close();
                header('Idempotent-Replay: ' . ($result['replayed'] ? 'true' : 'false'));
                $status = $result['http_status']; unset($result['http_status'],$result['replayed']);
                $this->respond($status, $status === 201 ? 'CREATED' : ($status === 200 ? 'UPDATED' : 'REVISION_CONFLICT'), $result);
            }
            if ($method !== 'GET' && $method !== 'POST') { header('Allow: GET, POST'); $this->respond(405, 'METHOD_NOT_ALLOWED'); }
            $this->respond(404, 'NOT_FOUND');
        } catch (BiometricAdminV2HttpException $e) {
            if ($db instanceof mysqli) { try { $db->rollback(); $db->close(); } catch (Throwable) {} }
            $this->respond($e->httpStatus, $e->publicStatus);
        } catch (InvalidArgumentException) {
            if ($db instanceof mysqli) { try { $db->rollback(); $db->close(); } catch (Throwable) {} }
            $this->respond(400, 'BAD_REQUEST');
        } catch (DomainException) {
            if ($db instanceof mysqli) { try { $db->rollback(); $db->close(); } catch (Throwable) {} }
            $this->respond(422, 'INVALID_BIOMETRIC_TEMPLATE');
        } catch (BiometricAdminV2StoredTemplateException) {
            if ($db instanceof mysqli) { try { $db->rollback(); $db->close(); } catch (Throwable) {} }
            $this->respond(409, 'ACCESS_ROW_CONFLICT');
        } catch (Throwable $e) {
            if ($db instanceof mysqli) { try { $db->rollback(); $db->close(); } catch (Throwable) {} }
            // Fixed event and exception class only; never log request, SQL, template or key material.
            error_log((string)json_encode(['event'=>'CHECADOR_BIOMETRIC_ADMIN_FAILURE','exception_class'=>get_class($e)], JSON_UNESCAPED_SLASHES));
            $this->respond(503, 'BIOMETRIC_ADMIN_UNAVAILABLE');
        }
    }

    /** Device → credential → grant → administrative session lock order. */
    private function authorizeDeviceAndAdmin(mysqli $db): array
    {
        $deviceHeader=trim((string)($_SERVER['HTTP_X_DEVICE_ID']??''));
        $credentialKey=trim((string)($_SERVER['HTTP_X_CREDENTIAL_KEY']??''));
        $authorization=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));
        $sessionSecret=(string)($_SERVER['HTTP_X_ADMINISTRATIVE_SESSION']??'');
        if ($deviceHeader===''||$credentialKey===''||preg_match('/\ABearer\s+(\S+)\z/i',$authorization,$m)!==1
            ||preg_match('/\A[0-9a-f]{64}\z/i',$sessionSecret)!==1) throw new BiometricAdminV2HttpException(401,'ADMIN_SESSION_INVALID');
        $deviceAuth=(new DispositivoV2())->autenticarCredencial($db,$deviceHeader,$credentialKey,$m[1]);
        if (empty($deviceAuth['auth'])) throw new BiometricAdminV2HttpException(401,'DEVICE_UNAUTHORIZED');
        $deviceId=(int)$deviceAuth['id_dispositivo']; $stationId=(int)$deviceAuth['id_estacion']; $credentialId=(int)$deviceAuth['credential_id'];
        $device=$this->one($db,'SELECT id,id_estacion,estado FROM op_rh_v2_dispositivos WHERE id=? FOR UPDATE','i',[$deviceId]);
        if(!$device||(int)$device['estado']!==1||(int)$device['id_estacion']!==$stationId) throw new BiometricAdminV2HttpException(401,'DEVICE_UNAUTHORIZED');
        $credential=$this->one($db,"SELECT id FROM op_rh_v2_dispositivo_credenciales WHERE id=? AND id_dispositivo=? AND estado='ACTIVE' AND revocado_at IS NULL AND valido_desde<=UTC_TIMESTAMP(6) AND (valido_hasta IS NULL OR valido_hasta>UTC_TIMESTAMP(6)) FOR UPDATE",'ii',[$credentialId,$deviceId]);
        if(!$credential) throw new BiometricAdminV2HttpException(401,'DEVICE_UNAUTHORIZED');
        $grants=$this->rows($db,'SELECT id FROM op_rh_v2_device_biometric_grants WHERE id_dispositivo=? AND id_estacion=? AND id_credencial=? AND activo=1 AND revocado_at_utc IS NULL AND valido_desde_utc<=UTC_TIMESTAMP(6) AND (valido_hasta_utc IS NULL OR UTC_TIMESTAMP(6)<valido_hasta_utc) FOR UPDATE','iii',[$deviceId,$stationId,$credentialId]);
        if(count($grants)!==1) throw new BiometricAdminV2HttpException(403,'DEVICE_SCOPE_DENIED');
        $locality=WorkplacePhysicalStationResolver::resolveCanonicalLocality($db,$stationId);
        if($locality===null) throw new BiometricAdminV2HttpException(403,'DEVICE_SCOPE_DENIED');
        $secretHash=hash('sha256',$sessionSecret);
        $session=$this->one($db,"SELECT id,device_id,credential_id,station_id,locality_id,actor_user_id,session_hash,state,created_at_utc,expires_at_utc,last_activity_at_utc FROM op_rh_v2_admin_session WHERE session_hash=? AND device_id=? FOR UPDATE",'si',[$secretHash,$deviceId]);
        $now=$this->now($db);
        if(!$session||$session['state']!=='ACTIVE'||(int)$session['credential_id']!==$credentialId||(int)$session['station_id']!==$stationId
            ||(int)$session['locality_id']!==$locality||!hash_equals((string)$session['session_hash'],$secretHash)
            ||!AdminPinV2Policy::sessionValidAt(self::epoch((string)$session['created_at_utc']),self::epoch((string)$session['last_activity_at_utc']),self::epoch((string)$session['expires_at_utc']),self::epoch($now))) {
            throw new BiometricAdminV2HttpException(401,'ADMIN_SESSION_INVALID');
        }
        $this->lockCanonicalStationMapping($db,$stationId,$locality);
        $actor=$this->one($db,'SELECT id,id_puesto,id_gas FROM tb_usuarios WHERE id=? AND estatus=0 FOR UPDATE','i',[(int)$session['actor_user_id']]);
        if(!$actor||(int)$actor['id_puesto']!==6||!$this->permissionGranted($db,(int)$actor['id'])) throw new BiometricAdminV2HttpException(403,'ADMIN_PERMISSION_DENIED');
        $this->assertActorStationScope($db,$actor,$stationId,$locality);
        return ['device_id'=>$deviceId,'station_id'=>$stationId,'credential_id'=>$credentialId,'locality_id'=>$locality,
            'session_id'=>(int)$session['id'],'actor_user_id'=>(int)$actor['id'],'session_secret_hash'=>$secretHash];
    }

    private function permissionGranted(mysqli $db,int $actorId): bool
    {
        $individual=$this->rows($db,"SELECT active,granted FROM op_rh_v2_permission_grants WHERE permission_key='checador.dispositivos.biometria.administrar' AND principal_type='USER' AND principal_id=? FOR UPDATE",'i',[$actorId]);
        if(count($individual)>1) return false;
        if(count($individual)===1) return (int)$individual[0]['active']===1&&(int)$individual[0]['granted']===1;
        $position=$this->rows($db,"SELECT active,granted FROM op_rh_v2_permission_grants WHERE permission_key='checador.dispositivos.biometria.administrar' AND principal_type='POSITION' AND principal_id=6 FOR UPDATE",'',[]);
        return count($position)===1&&(int)$position[0]['active']===1&&(int)$position[0]['granted']===1;
    }

    private function assertActorStationScope(mysqli $db,array $actor,int $stationId,int $localityId): void
    {
        $config=$this->one($db,'SELECT activo,estaciones_soportadas,tipo_departamento FROM tb_modulos_config WHERE modulo_key=? LIMIT 1 FOR UPDATE','s',['checador']);
        if(!$config||(int)$config['activo']!==1||($config['tipo_departamento']??null)!=='localidades') throw new BiometricAdminV2HttpException(403,'ADMIN_PERMISSION_DENIED');
        if(Portal3JwtAuthHelper::hasColumn($db,'tb_modulos_config','allow_all')) {
            $allow=$this->one($db,'SELECT allow_all FROM tb_modulos_config WHERE modulo_key=? LIMIT 1 FOR UPDATE','s',['checador']);
            if(!$allow||(int)$allow['allow_all']!==0) throw new BiometricAdminV2HttpException(403,'ADMIN_PERMISSION_DENIED');
        }
        $supported=json_decode((string)$config['estaciones_soportadas'],true);
        if(!is_array($supported)||!$supported) throw new BiometricAdminV2HttpException(403,'ADMIN_PERMISSION_DENIED');
        foreach($supported as $id) if(!is_int($id)&&!(is_string($id)&&ctype_digit($id))) throw new BiometricAdminV2HttpException(403,'ADMIN_PERMISSION_DENIED');
        $physical=[];
        $localities=Portal3JwtAuthHelper::resolveStationScope($db,['user_id'=>(int)$actor['id'],'id_puesto'=>(int)$actor['id_puesto'],'id_gas'=>(int)$actor['id_gas']],array_values(array_unique(array_map('intval',$supported))),$config,$physical);
        if(!in_array($stationId,$physical,true)||!in_array($localityId,$localities,true)) throw new BiometricAdminV2HttpException(403,'ADMIN_PERMISSION_DENIED');
    }

    private function lockCanonicalStationMapping(mysqli $db,int $stationId,int $localityId): void
    {
        $station=$this->one($db,'SELECT id,numlista,estatus FROM tb_estaciones WHERE id=? FOR UPDATE','i',[$stationId]);
        if(!$station||(int)$station['estatus']!==0||(int)$station['numlista']<1) throw new BiometricAdminV2HttpException(403,'DEVICE_SCOPE_DENIED');
        $numlista=(int)$station['numlista'];
        $stations=$this->rows($db,'SELECT id FROM tb_estaciones WHERE numlista=? ORDER BY id FOR UPDATE','i',[$numlista]);
        $localities=$this->rows($db,'SELECT id FROM op_rh_localidades WHERE numlista=? ORDER BY id FOR UPDATE','i',[$numlista]);
        if(count($stations)!==1||(int)$stations[0]['id']!==$stationId||count($localities)!==1||(int)$localities[0]['id']!==$localityId) throw new BiometricAdminV2HttpException(403,'DEVICE_SCOPE_DENIED');
    }

    private function listWorkers(mysqli $db,array $auth,int $limit,int $offset): array
    {
        $rows=$this->rows($db,"SELECT p.id,p.nombre,i.referencia_externa_personal FROM op_rh_personal p JOIN op_rh_v2_dispositivo_identidad_personal i ON i.id_personal=p.id WHERE p.estado=1 AND p.id_estacion=? AND i.id_dispositivo=? AND i.es_activo=1 AND i.valido_desde<=UTC_TIMESTAMP(6) AND (i.valido_hasta IS NULL OR UTC_TIMESTAMP(6)<i.valido_hasta) ORDER BY p.id,i.id",'ii',[$auth['station_id'],$auth['device_id']]);
        $group=[]; foreach($rows as $row) $group[(int)$row['id']][]=$row;
        $output=[]; $seenRefs=[];
        foreach($group as $workerRows) {
            if(count($workerRows)!==1) continue;
            $row=$workerRows[0]; $reference=(string)$row['referencia_externa_personal'];
            if(!BiometricAdminV2Policy::validateReference($reference)||isset($seenRefs[$reference])) continue;
            try { $worker=$this->resolveWorker($db,$auth,$reference,false); $status=$this->readStatus($db,$auth,$worker); }
            catch(BiometricAdminV2HttpException) { continue; }
            $seenRefs[$reference]=true;
            $output[]=['external_worker_reference'=>$reference,'nombre'=>(string)$row['nombre'],'biometric_status'=>$status['biometric_status'],'revision'=>$status['revision']];
        }
        return array_slice($output,$offset,$limit);
    }

    private function resolveWorker(mysqli $db,array $auth,string $reference,bool $forUpdate): array
    {
        $lock=$forUpdate?' FOR UPDATE':'';
        $identities=$this->rows($db,'SELECT id,id_personal,referencia_externa_personal FROM op_rh_v2_dispositivo_identidad_personal WHERE id_dispositivo=? AND referencia_externa_personal=? AND es_activo=1 AND valido_desde<=UTC_TIMESTAMP(6) AND (valido_hasta IS NULL OR UTC_TIMESTAMP(6)<valido_hasta) ORDER BY id','is',[$auth['device_id'],$reference]);
        if(count($identities)!==1) throw new BiometricAdminV2HttpException(404,'WORKER_NOT_FOUND');
        $workerId=(int)$identities[0]['id_personal'];
        $worker=$this->one($db,'SELECT id,nombre,estado,id_estacion FROM op_rh_personal WHERE id=?'.$lock,'i',[$workerId]);
        if(!$worker||(int)$worker['estado']!==1||(int)$worker['id_estacion']!==$auth['station_id']) throw new BiometricAdminV2HttpException(404,'WORKER_NOT_FOUND');
        if($forUpdate) {
            $lockedIdentity=$this->rows($db,'SELECT id,id_personal FROM op_rh_v2_dispositivo_identidad_personal WHERE id_dispositivo=? AND referencia_externa_personal=? AND es_activo=1 AND valido_desde<=UTC_TIMESTAMP(6) AND (valido_hasta IS NULL OR UTC_TIMESTAMP(6)<valido_hasta) ORDER BY id FOR UPDATE','is',[$auth['device_id'],$reference]);
            if(count($lockedIdentity)!==1||(int)$lockedIdentity[0]['id']!==(int)$identities[0]['id']||(int)$lockedIdentity[0]['id_personal']!==$workerId) throw new BiometricAdminV2HttpException(409,'IDENTITY_CONFLICT');
        }
        $this->assertCurrentMembership($db,$workerId,$auth['locality_id'],$forUpdate);
        $byWorker=$this->rows($db,'SELECT id FROM op_rh_v2_dispositivo_identidad_personal WHERE id_personal=? AND id_dispositivo=? AND es_activo=1 AND valido_desde<=UTC_TIMESTAMP(6) AND (valido_hasta IS NULL OR UTC_TIMESTAMP(6)<valido_hasta) ORDER BY id','ii',[$workerId,$auth['device_id']]);
        if(count($byWorker)!==1) throw new BiometricAdminV2HttpException(409,'IDENTITY_CONFLICT');
        return ['id'=>(int)$worker['id'],'name'=>(string)$worker['nombre'],'reference'=>$reference];
    }

    private function assertCurrentMembership(mysqli $db,int $workerId,int $localityId,bool $forUpdate): void
    {
        $sql='SELECT id,worker_id,workplace_id,valid_from_utc,valid_to_utc,supersedes_id FROM op_rh_personal_localidad_version WHERE worker_id=? AND recorded_at_utc<=UTC_TIMESTAMP(6) ORDER BY id'.($forUpdate?' FOR UPDATE':'');
        $rows=$this->rows($db,$sql,'i',[$workerId]);
        $history=ShiftWorkerMembershipService::validateMembershipHistory($workerId,$rows);
        if(empty($history['valid'])) throw new BiometricAdminV2HttpException(409,'WORKER_MEMBERSHIP_CONFLICT');
        $now=(new DateTimeImmutable($this->now($db),new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.u');
        $current=[];
        foreach($history['leaves'] as $row) if($row['valid_from_utc']<=$now&&($row['valid_to_utc']===null||$now<$row['valid_to_utc'])) $current[]=$row;
        if(count($current)!==1||(int)$current[0]['workplace_id']!==$localityId) throw new BiometricAdminV2HttpException(404,'WORKER_NOT_FOUND');
    }

    private function readStatus(mysqli $db,array $auth,array $worker): array
    {
        $access=$this->rows($db,'SELECT id,huella FROM op_rh_personal_acceso WHERE id_personal=? ORDER BY id','i',[$worker['id']]);
        if(count($access)!==1) throw new BiometricAdminV2HttpException(409,'ACCESS_ROW_CONFLICT');
        $base64=$access[0]['huella']===null?'':(string)$access[0]['huella'];
        $bytes=BiometricAdminV2Policy::decodeStoredTemplate($base64);
        $sequence=$this->latestBiometricSequence($db,(int)$worker['id']);
        $revision=DeviceOperationRequestV2::revision($auth['device_id'],(int)$worker['id'],$sequence,$bytes);
        return ['external_worker_reference'=>$worker['reference'],'biometric_status'=>$bytes===''?'NOT_ENROLLED':'ENROLLED','revision'=>$revision];
    }

    private function mutateBiometric(mysqli $db,array $auth,array $worker,array $request,array $activeKey): array
    {
        $accessRows=$this->rows($db,'SELECT id,huella FROM op_rh_personal_acceso WHERE id_personal=? ORDER BY id FOR UPDATE','i',[$worker['id']]);
        if(count($accessRows)!==1) throw new BiometricAdminV2HttpException(409,'ACCESS_ROW_CONFLICT');
        $storedBase64=$accessRows[0]['huella']===null?'':(string)$accessRows[0]['huella'];
        $storedBytes=BiometricAdminV2Policy::decodeStoredTemplate($storedBase64);
        $sequence=$this->latestBiometricSequence($db,(int)$worker['id']);
        $canonical=['operation_kind'=>'BIOMETRIC_'.$request['operation'],'device_id'=>$auth['device_id'],'station_id'=>$auth['station_id'],
            'credential_id'=>$auth['credential_id'],'worker_reference'=>$worker['reference'],'request_id'=>$request['request_id'],
            'actor_user_id'=>$auth['actor_user_id'],
            'expected_revision'=>$request['expected_revision'],'template_format'=>BiometricAdminV2Policy::TEMPLATE_FORMAT,'template_bytes_base64'=>$request['template_base64']];
        $fingerprint=DeviceOperationRequestV2::fingerprint($activeKey['key'],$canonical);
        $prior=$this->one($db,'SELECT operation_kind,fingerprint_key_id,request_fingerprint,actor_user_id,response_status,response_json FROM op_rh_v2_device_operation_requests WHERE id_dispositivo=? AND request_id=? FOR UPDATE','is',[$auth['device_id'],$request['request_id']]);
        if($prior) {
            $oldFingerprint=DeviceOperationRequestV2::fingerprint(DeviceOperationRequestV2::key((string)$prior['fingerprint_key_id']),$canonical);
            $decision=BiometricAdminV2Policy::replayDecision($prior,(string)$canonical['operation_kind'],$oldFingerprint,(int)$auth['actor_user_id']);
            if($decision!=='REPLAY') throw new BiometricAdminV2HttpException(409,'IDEMPOTENCY_CONFLICT');
            $response=json_decode((string)$prior['response_json'],true,512,JSON_THROW_ON_ERROR);
            if(!is_array($response)) throw new RuntimeException('INVALID_RECORDED_RESPONSE');
            $this->touchSession($db,$auth);
            return $response+['http_status'=>(int)$prior['response_status'],'replayed'=>true];
        }
        $currentRevision=DeviceOperationRequestV2::revision($auth['device_id'],(int)$worker['id'],$sequence,$storedBytes);
        $matches=DeviceOperationRequestV2::revisionMatches($request['expected_revision'],$auth['device_id'],(int)$worker['id'],$sequence,$storedBytes);
        $validState=($request['operation']==='CREATE'&&$storedBytes==='')||($request['operation']==='REPLACE'&&$storedBytes!=='');
        if(!$validState||!$matches) {
            $body=['request_id'=>$request['request_id'],'external_worker_reference'=>$worker['reference'],'status'=>'REVISION_CONFLICT'];
            $this->saveOperation($db,$auth,$worker,$request,$activeKey,$fingerprint,409,$body,null);
            return $body+['http_status'=>409,'replayed'=>false];
        }
        $newSequence=$sequence+1;
        $newRevision=DeviceOperationRequestV2::revision($auth['device_id'],(int)$worker['id'],$newSequence,$request['template_bytes']);
        $write=$db->prepare('UPDATE op_rh_personal_acceso SET huella=? WHERE id=? AND id_personal=? AND huella <=> ?');
        $id=(int)$accessRows[0]['id']; $workerId=(int)$worker['id']; $old=$accessRows[0]['huella']; $newBase64=$request['template_base64'];
        $write->bind_param('siis',$newBase64,$id,$workerId,$old); $write->execute(); $write->close();
        $readbackRows=$this->rows($db,'SELECT id,id_personal,huella FROM op_rh_personal_acceso WHERE id_personal=? ORDER BY id FOR UPDATE','i',[$workerId]);
        $readback=$readbackRows[0]??null;
        if(count($readbackRows)!==1||!$readback||(int)$readback['id']!==$id||(int)$readback['id_personal']!==$workerId
            ||!is_string($readback['huella'])||!hash_equals($newBase64,$readback['huella'])) throw new RuntimeException('BIOMETRIC_READBACK_FAILED');
        $this->touchSession($db,$auth);
        $status=$request['operation']==='CREATE'?201:200;
        $body=['request_id'=>$request['request_id'],'external_worker_reference'=>$worker['reference'],'biometric_status'=>'ENROLLED','revision'=>$newRevision,'persisted'=>true];
        $this->saveOperation($db,$auth,$worker,$request,$activeKey,$fingerprint,$status,$body,$newSequence);
        return $body+['http_status'=>$status,'replayed'=>false];
    }

    private function latestBiometricSequence(mysqli $db,int $workerId): int
    {
        $row=$this->one($db,"SELECT MAX(biometric_revision) AS current_revision FROM op_rh_v2_device_operation_requests WHERE id_personal=? AND operation_kind IN ('BIOMETRIC_CREATE','BIOMETRIC_REPLACE')",'i',[$workerId]);
        return max(0,(int)($row['current_revision']??0));
    }

    private function saveOperation(mysqli $db,array $auth,array $worker,array $request,array $key,string $fingerprint,int $status,array $body,?int $revision): void
    {
        $operation='BIOMETRIC_'.$request['operation'];
        $json=json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $stmt=$db->prepare('INSERT INTO op_rh_v2_device_operation_requests (id_dispositivo,id_estacion,id_credencial,request_id,operation_kind,fingerprint_key_id,request_fingerprint,id_personal,actor_user_id,biometric_revision,response_status,response_json,created_at_utc) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $device=$auth['device_id'];$station=$auth['station_id'];$credential=$auth['credential_id'];$requestId=$request['request_id'];$workerId=(int)$worker['id'];$actor=(int)$auth['actor_user_id'];$revisionValue=$revision===null?null:(string)$revision;$http=$status;$response=$json;$keyId=$key['key_id'];
        $types='iiissssiisis';
        $stmt->bind_param($types,$device,$station,$credential,$requestId,$operation,$keyId,$fingerprint,$workerId,$actor,$revisionValue,$http,$response);
        $stmt->execute();$stmt->close();
    }

    private function touchSession(mysqli $db,array $auth): void
    {
        $session=$this->one($db,"SELECT created_at_utc,expires_at_utc,last_activity_at_utc FROM op_rh_v2_admin_session WHERE id=? AND state='ACTIVE' AND credential_id=? AND device_id=? FOR UPDATE",'iii',[$auth['session_id'],$auth['credential_id'],$auth['device_id']]);
        $now=$this->now($db);
        if(!$session||!AdminPinV2Policy::sessionValidAt(self::epoch((string)$session['created_at_utc']),self::epoch((string)$session['last_activity_at_utc']),self::epoch((string)$session['expires_at_utc']),self::epoch($now))) throw new BiometricAdminV2HttpException(401,'ADMIN_SESSION_INVALID');
        $credential=$this->one($db,"SELECT id FROM op_rh_v2_dispositivo_credenciales WHERE id=? AND id_dispositivo=? AND estado='ACTIVE' AND revocado_at IS NULL AND valido_desde<=UTC_TIMESTAMP(6) AND (valido_hasta IS NULL OR valido_hasta>UTC_TIMESTAMP(6)) FOR UPDATE",'ii',[$auth['credential_id'],$auth['device_id']]);
        $grant=$this->rows($db,'SELECT id FROM op_rh_v2_device_biometric_grants WHERE id_dispositivo=? AND id_estacion=? AND id_credencial=? AND activo=1 AND revocado_at_utc IS NULL AND valido_desde_utc<=UTC_TIMESTAMP(6) AND (valido_hasta_utc IS NULL OR UTC_TIMESTAMP(6)<valido_hasta_utc) FOR UPDATE','iii',[$auth['device_id'],$auth['station_id'],$auth['credential_id']]);
        if(!$credential||count($grant)!==1) throw new BiometricAdminV2HttpException(403,'DEVICE_SCOPE_DENIED');
        $this->exec($db,"UPDATE op_rh_v2_admin_session SET last_activity_at_utc=UTC_TIMESTAMP(6) WHERE id=? AND state='ACTIVE' AND credential_id=? AND device_id=?",'iii',[$auth['session_id'],$auth['credential_id'],$auth['device_id']]);
    }

    private function requireInnoDb(mysqli $db): void
    {
        foreach(['op_rh_v2_device_operation_requests','op_rh_v2_dispositivos','op_rh_v2_dispositivo_credenciales','op_rh_v2_device_biometric_grants','op_rh_v2_admin_session','op_rh_personal','op_rh_personal_localidad_version','op_rh_v2_dispositivo_identidad_personal','op_rh_personal_acceso'] as $table) {
            $row=$this->one($db,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?','s',[$table]);
            if(strtoupper((string)($row['ENGINE']??''))!=='INNODB') throw new RuntimeException('TRANSACTIONAL_TABLE_REQUIRED');
        }
    }

    private function rows(mysqli $db,string $sql,string $types,array $params): array
    {
        $stmt=$db->prepare($sql);if($types!=='')$this->bind($stmt,$types,$params);$stmt->execute();$result=$stmt->get_result();$rows=$result?$result->fetch_all(MYSQLI_ASSOC):[];$stmt->close();return $rows;
    }
    private function one(mysqli $db,string $sql,string $types,array $params):?array { $rows=$this->rows($db,$sql,$types,$params);return $rows[0]??null; }
    private function exec(mysqli $db,string $sql,string $types,array $params):void { $stmt=$db->prepare($sql);if($types!=='')$this->bind($stmt,$types,$params);$stmt->execute();$stmt->close(); }
    private function bind(mysqli_stmt $stmt,string $types,array &$params):void { $args=[$types];foreach($params as &$value)$args[]=&$value;unset($value);if(!call_user_func_array([$stmt,'bind_param'],$args))throw new RuntimeException('DATABASE_BIND_FAILED'); }
    private function now(mysqli $db):string { return (string)$db->query('SELECT UTC_TIMESTAMP(6) AS now_utc')->fetch_assoc()['now_utc']; }
    private static function epoch(string $utc):int { return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->getTimestamp(); }
    private function respond(int $code,string $status,?array $data=null):never { http_response_code($code);$body=['code'=>$code,'status'=>$status];if($data!==null)$body['data']=$data;echo json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit; }
}
