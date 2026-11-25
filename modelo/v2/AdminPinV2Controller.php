<?php

declare(strict_types=1);
require_once __DIR__ . '/../BD.php';
require_once __DIR__ . '/DispositivoV2.php';
require_once __DIR__ . '/DeviceBiometricGrantV2.php';
require_once __DIR__ . '/Portal3JwtAuthHelper.php';
require_once __DIR__ . '/AdminPinV2Policy.php';
require_once __DIR__ . '/AdminPinIpRateLimit.php';

/** Device-authenticated redemption and lifecycle for the administrative configuration PIN. */
class AdminPinV2Controller
{
    public function handle(array $path, string $method): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');
        if ($method !== 'POST') { header('Allow: POST'); $this->respond(405, 'METHOD_NOT_ALLOWED'); }
        if (!AdminPinV2Policy::enabled()) $this->respond(503, 'ADMIN_UNAVAILABLE');
        if (count($path) !== 1 || !in_array($path[0], ['redeem', 'validate', 'close'], true)) $this->respond(404, 'NOT_FOUND');
        if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) $this->respond(415, 'UNSUPPORTED_MEDIA_TYPE');
        $raw = file_get_contents('php://input', false, null, 0, 2049);
        if (!is_string($raw) || strlen($raw) > 2048) $this->respond(400, 'BAD_REQUEST');
        $body = json_decode($raw, true);
        if (!is_array($body)) $this->respond(400, 'BAD_REQUEST');
        $auth = $this->authenticateDevice();
        if ($auth === null) $this->respond(401, 'ADMIN_DENIED');
        try {
            $db = (new BD())->conectarBD();
            $secret = AdminPinV2Policy::sharedSecret();
            if ($secret === null) { $db->close(); $this->respond(503, 'ADMIN_UNAVAILABLE'); }
            $result = match ($path[0]) {
                'redeem' => AdminPinIpRateLimit::attempt((string)getenv('CHECADOR_ADMIN_PIN_RATE_DIR'), (string)($_SERVER['REMOTE_ADDR'] ?? ''), fn() => $this->redeem($db, $auth, $secret, $body)),
                'validate' => $this->validateSession($db, $auth),
                'close' => $this->closeSession($db, $auth, $body),
            };
            $db->close();
            if (isset($result['status_code'])) {
                $status = (int)$result['status_code']; unset($result['status_code']);
                http_response_code($status); echo json_encode(['code'=>$status] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
            }
            $status = $path[0] === 'redeem' ? 201 : 200;
            http_response_code($status); echo json_encode(['code'=>$status,'status'=>'OK','data'=>$result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit;
        } catch (Throwable $exception) {
            Portal3JwtAuthHelper::logAuthDependencyFailure('admin_pin.' . $path[0], $exception);
            $this->respond(503, 'ADMIN_UNAVAILABLE');
        }
    }

    /** @return array{device_id:int,station_id:int,credential_id:int}|null */
    private function authenticateDevice(): ?array
    {
        $deviceId = trim((string)($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));
        $key = trim((string)($_SERVER['HTTP_X_CREDENTIAL_KEY'] ?? ''));
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($deviceId === '' || $key === '' || !preg_match('/\ABearer\s+(.+)\z/i', trim($header), $m)) return null;
        try {
            $db = (new BD())->conectarBD();
            $result = (new DispositivoV2())->autenticarCredencial($db, $deviceId, $key, trim($m[1]));
            $db->close();
            if (empty($result['auth'])) return null;
            $grantDb = (new BD())->conectarBD();
            $allowed = DeviceBiometricGrantV2::authorized($grantDb, (int)$result['id_dispositivo'], (int)$result['id_estacion'], (int)$result['credential_id']);
            $grantDb->close();
            if (!$allowed) return null;
            return ['device_id'=>(int)$result['id_dispositivo'],'station_id'=>(int)$result['id_estacion'],'credential_id'=>(int)$result['credential_id']];
        } catch (Throwable) { return null; }
    }

    protected function redeem(mysqli $db, array $auth, string $secret, array $body): array
    {
        $pin = $body['pin'] ?? null;
        if (!AdminPinV2Policy::validPin($pin) || !self::validUuid($body['request_id'] ?? null)) return ['status_code'=>422,'status'=>'INVALID_REQUEST'];
        $pinValue = (string)$pin;
        $pinId = null;
        $wrong = false;
        $db->begin_transaction();
        try {
            $deviceId=$auth['device_id']; $stationId=$auth['station_id']; $credentialId=$auth['credential_id'];
            $device=$this->one($db,'SELECT id, id_estacion, estado FROM op_rh_v2_dispositivos WHERE id=? FOR UPDATE','i',[$deviceId]);
            if (!$device || (int)$device['estado']!==1 || (int)$device['id_estacion']!==$stationId) throw new RuntimeException('device_scope');
            $credential=$this->one($db,"SELECT id FROM op_rh_v2_dispositivo_credenciales WHERE id=? AND id_dispositivo=? AND estado='ACTIVE' AND revocado_at IS NULL AND valido_desde<=UTC_TIMESTAMP(6) AND (valido_hasta IS NULL OR valido_hasta>UTC_TIMESTAMP(6)) FOR UPDATE",'ii',[$credentialId,$deviceId]);
            if (!$credential || ! $this->grantAuthorized($db,$deviceId,$stationId,$credentialId)) throw new RuntimeException('device_grant');
            $db->query("INSERT IGNORE INTO op_rh_v2_admin_pin_limit (device_id,window_started_at_utc,failed_attempts,blocked_until_utc) VALUES (".$deviceId.",UTC_TIMESTAMP(6),0,NULL)");
            $limit=$this->one($db,'SELECT device_id, window_started_at_utc, failed_attempts, blocked_until_utc FROM op_rh_v2_admin_pin_limit WHERE device_id=? FOR UPDATE','i',[$deviceId]);
            if (!$limit) throw new RuntimeException('limit_row');
            // Read time after acquiring the serialized limiter lock so a
            // waiter cannot use a stale timestamp at a lockout boundary.
            $now = $this->now($db);
            if (!empty($limit['blocked_until_utc']) && (string)$limit['blocked_until_utc']>$now) {
                $db->commit(); return ['status_code'=>429,'status'=>'RATE_LIMITED'];
            }
            if ((string)$limit['window_started_at_utc'] <= self::minusSeconds($now,AdminPinV2Policy::LIMIT_WINDOW_SECONDS)) {
                $db->query('UPDATE op_rh_v2_admin_pin_limit SET window_started_at_utc=UTC_TIMESTAMP(6), failed_attempts=0, blocked_until_utc=NULL WHERE device_id='.(int)$deviceId);
                // The locked row still contains the pre-reset values in PHP. Keep
                // subsequent arithmetic in sync with the durable row.
                $limit['window_started_at_utc']=$now;
                $limit['failed_attempts']=0;
                $limit['blocked_until_utc']=null;
            }
            $row=$this->one($db,"SELECT id, authorization_uuid, credential_id, station_id, locality_id, actor_user_id, pin_hash, state, failed_attempts, issued_at_utc, expires_at_utc FROM op_rh_v2_admin_pin WHERE device_id=? AND station_id=? AND credential_id=? AND state='PENDING' ORDER BY id DESC LIMIT 1 FOR UPDATE",'iii',[$deviceId,$stationId,$credentialId]);
            if (!$row || (string)$row['expires_at_utc']<=$now) {
                if ($row) $this->exec($db,"UPDATE op_rh_v2_admin_pin SET state='EXPIRED' WHERE id=? AND state='PENDING",'i',[(int)$row['id']]);
                $wrong=true;
            } else {
                $pinId=(int)$row['id'];
                if ((int)$row['failed_attempts']>=AdminPinV2Policy::MAX_FAILURES || !password_verify(AdminPinV2Policy::verifier($pinValue,$secret),(string)$row['pin_hash'])) {
                    $wrong=true;
                    $failed=min(AdminPinV2Policy::MAX_FAILURES,(int)$row['failed_attempts']+1);
                    $this->exec($db,"UPDATE op_rh_v2_admin_pin SET failed_attempts=?, state=? WHERE id=? AND state='PENDING'",'isi',[$failed,$failed>=AdminPinV2Policy::MAX_FAILURES?'LOCKED':'PENDING',$pinId]);
                } else {
                    if (!AdminPinV2Policy::pinValidAt(self::epoch((string)$row['issued_at_utc']),self::epoch((string)$row['expires_at_utc']),self::epoch($now))) {
                        $this->exec($db,"UPDATE op_rh_v2_admin_pin SET state='EXPIRED' WHERE id=? AND state='PENDING",'i',[$pinId]); $wrong=true;
                    } elseif (!$this->actorStillAuthorized($db,(int)$row['actor_user_id'],(int)$row['locality_id'],$stationId)) {
                        $this->exec($db,"UPDATE op_rh_v2_admin_pin SET state='REVOKED', revoked_at_utc=UTC_TIMESTAMP(6) WHERE id=? AND state='PENDING'",'i',[$pinId]); $wrong=true;
                    } else {
                        $active=$this->one($db,"SELECT id, created_at_utc, expires_at_utc, last_activity_at_utc FROM op_rh_v2_admin_session WHERE device_id=? AND state='ACTIVE' FOR UPDATE",'i',[$deviceId]);
                        if ($active) {
                            if ((string)$active['expires_at_utc']>$now && (string)$active['last_activity_at_utc']>self::minusSeconds($now,AdminPinV2Policy::SESSION_IDLE_SECONDS)) {
                                $db->commit(); return ['status_code'=>409,'status'=>'SESSION_ACTIVE'];
                            }
                            $this->exec($db,"UPDATE op_rh_v2_admin_session SET state='EXPIRED', ended_at_utc=UTC_TIMESTAMP(6) WHERE id=? AND state='ACTIVE'",'i',[(int)$active['id']]);
                        }
                        $sessionSecret=bin2hex(random_bytes(32)); $sessionHash=hash('sha256',$sessionSecret); $sessionUuid=AdminPinV2Policy::uuidV4();
                        $expires=self::plusSeconds($now,AdminPinV2Policy::SESSION_TTL_SECONDS);
                        $this->exec($db,"UPDATE op_rh_v2_admin_pin SET state='CONSUMED', consumed_at_utc=UTC_TIMESTAMP(6) WHERE id=? AND state='PENDING'",'i',[$pinId]);
                        $this->exec($db,"INSERT INTO op_rh_v2_admin_session (session_uuid,pin_id,device_id,credential_id,station_id,locality_id,actor_user_id,session_hash,state,created_at_utc,expires_at_utc,last_activity_at_utc,ended_at_utc) VALUES (?,?,?,?,?,?,?,?,'ACTIVE',UTC_TIMESTAMP(6),?,UTC_TIMESTAMP(6),NULL)",'siiiiiiss',[$sessionUuid,$pinId,$deviceId,$credentialId,$stationId,(int)$row['locality_id'],(int)$row['actor_user_id'],$sessionHash,$expires]);
                        $this->audit($db,(int)$row['actor_user_id'],(int)$row['locality_id'],'ADMIN_SESSION',$this->insertId($db),'REDEEM');
                        $db->commit();
                        return ['session_id'=>$sessionUuid,'session_secret'=>$sessionSecret,'expires_at_utc'=>$expires,'idle_timeout_seconds'=>AdminPinV2Policy::SESSION_IDLE_SECONDS];
                    }
                }
            }
            if ($wrong) {
                // All wrong redemption outcomes pass this single point. The
                // device limiter is locked above and updated exactly once here;
                // reissued PINs therefore share the same durable counter.
                $limitState=AdminPinV2Policy::recordFailure(
                    self::epoch((string)$limit['window_started_at_utc']),
                    (int)$limit['failed_attempts'],
                    !empty($limit['blocked_until_utc']) ? self::epoch((string)$limit['blocked_until_utc']) : null,
                    self::epoch($now)
                );
                $newDeviceFailures=min(AdminPinV2Policy::MAX_FAILURES,(int)$limitState['failures']);
                $blocked=$limitState['blocked_until']!==null;
                $this->exec($db,'UPDATE op_rh_v2_admin_pin_limit SET failed_attempts=?, blocked_until_utc=IF(?=1,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 15 MINUTE),NULL) WHERE device_id=?','iii',[$newDeviceFailures,$blocked?1:0,$deviceId]);
                $db->commit();
                return ['status_code'=>$blocked?429:401,'status'=>$blocked?'RATE_LIMITED':'ADMIN_DENIED'];
            }
            $db->commit(); return ['status_code'=>401,'status'=>'ADMIN_DENIED'];
        } catch (Throwable $exception) { $db->rollback(); throw $exception; }
    }

    protected function validateSession(mysqli $db,array $auth):array
    {
        $secret=(string)($_SERVER['HTTP_X_ADMINISTRATIVE_SESSION']??'');
        if (!preg_match('/\A[0-9a-f]{64}\z/i',$secret)) return ['status_code'=>401,'status'=>'ADMIN_DENIED'];
        $db->begin_transaction();
        try {
            $this->lockAuthenticatedDevice($db,$auth);
            $session=$this->one($db,"SELECT id,session_uuid,pin_id,device_id,credential_id,station_id,locality_id,actor_user_id,session_hash,state,created_at_utc,expires_at_utc,last_activity_at_utc FROM op_rh_v2_admin_session WHERE session_hash=? AND device_id=? FOR UPDATE",'si',[hash('sha256',$secret),$auth['device_id']]);
            if (!$this->sessionValid($db,$session,$auth)) { $db->commit(); return ['status_code'=>401,'status'=>'ADMIN_DENIED']; }
            // This endpoint is a status/read check. Polling it must not keep an
            // administrative session alive; only a future authorized admin
            // operation may refresh the idle timestamp.
            $db->commit(); return ['session_id'=>(string)$session['session_uuid'],'state'=>'ACTIVE','expires_at_utc'=>(string)$session['expires_at_utc'],'idle_timeout_seconds'=>AdminPinV2Policy::SESSION_IDLE_SECONDS];
        } catch(Throwable $e) { $db->rollback(); throw $e; }
    }

    protected function closeSession(mysqli $db,array $auth,array $body):array
    {
        if (!self::validUuid($body['request_id'] ?? null)) return ['status_code'=>422,'status'=>'INVALID_REQUEST'];
        $secret=(string)($_SERVER['HTTP_X_ADMINISTRATIVE_SESSION']??'');
        if (!preg_match('/\A[0-9a-f]{64}\z/i',$secret)) return ['status_code'=>401,'status'=>'ADMIN_DENIED'];
        $db->begin_transaction();
        try {
            $this->lockAuthenticatedDevice($db,$auth);
            $session=$this->one($db,"SELECT id,session_uuid,device_id,credential_id,station_id,locality_id,actor_user_id,session_hash,state FROM op_rh_v2_admin_session WHERE session_hash=? AND device_id=? FOR UPDATE",'si',[hash('sha256',$secret),$auth['device_id']]);
            if (!$session || !in_array((string)$session['state'], ['ACTIVE','CLOSED'], true) || (int)$session['credential_id']!==$auth['credential_id'] || (int)$session['station_id']!==$auth['station_id'] || ! $this->grantAuthorized($db,$auth['device_id'],$auth['station_id'],$auth['credential_id'])) {
                $db->commit(); return ['status_code'=>401,'status'=>'ADMIN_DENIED'];
            }
            if ((string)$session['state']==='CLOSED') { $db->commit(); return ['state'=>'CLOSED']; }
            $this->exec($db,"UPDATE op_rh_v2_admin_session SET state='CLOSED',ended_at_utc=UTC_TIMESTAMP(6) WHERE id=? AND state='ACTIVE'",'i',[(int)$session['id']]);
            $this->audit($db,(int)$session['actor_user_id'],(int)$session['locality_id'],'ADMIN_SESSION',(int)$session['id'],'CLOSE');
            $db->commit(); return ['state'=>'CLOSED'];
        } catch(Throwable $e) { $db->rollback(); throw $e; }
    }

    private function sessionValid(mysqli $db,?array $s,array $auth):bool
    {
        if (!$s || $s['state']!=='ACTIVE' || (int)$s['device_id']!==$auth['device_id'] || (int)$s['credential_id']!==$auth['credential_id'] || (int)$s['station_id']!==$auth['station_id']) return false;
        $now=$this->now($db);
        if (!hash_equals((string)$s['session_hash'],hash('sha256',(string)($_SERVER['HTTP_X_ADMINISTRATIVE_SESSION']??'')))) return false;
        if (!AdminPinV2Policy::sessionValidAt(self::epoch((string)$s['created_at_utc']),self::epoch((string)$s['last_activity_at_utc']),self::epoch((string)$s['expires_at_utc']),self::epoch($now))) return false;
        if (! $this->grantAuthorized($db,$auth['device_id'],$auth['station_id'],$auth['credential_id'])) return false;
        return $this->actorStillAuthorized($db,(int)$s['actor_user_id'],(int)$s['locality_id'],(int)$s['station_id']);
    }

    private function lockAuthenticatedDevice(mysqli $db,array $auth):void
    {
        $device=$this->one($db,'SELECT id,id_estacion,estado FROM op_rh_v2_dispositivos WHERE id=? FOR UPDATE','i',[$auth['device_id']]);
        if(!$device||(int)$device['estado']!==1||(int)$device['id_estacion']!==$auth['station_id'])throw new RuntimeException('device_scope');
        $credential=$this->one($db,"SELECT id FROM op_rh_v2_dispositivo_credenciales WHERE id=? AND id_dispositivo=? AND estado='ACTIVE' AND revocado_at IS NULL AND valido_desde<=UTC_TIMESTAMP(6) AND (valido_hasta IS NULL OR valido_hasta>UTC_TIMESTAMP(6)) FOR UPDATE",'ii',[$auth['credential_id'],$auth['device_id']]);
        if(!$credential||! $this->grantAuthorized($db,$auth['device_id'],$auth['station_id'],$auth['credential_id']))throw new RuntimeException('device_grant');
    }

    protected function actorStillAuthorized(mysqli $db,int $actorId,int $localityId,int $stationId):bool
    {
        $actor=$this->one($db,'SELECT id,id_puesto,id_gas FROM tb_usuarios WHERE id=? AND estatus=0 LIMIT 1','i',[$actorId]);
        if (!$actor || (int)$actor['id_puesto']!==6) return false;
        if (!$this->permissionGranted($db,'checador.admin_pin.emitir',$actorId,6)) return false;
        $scope=Portal3JwtAuthHelper::authorizeTemporaryAccess($db,['user_id'=>$actorId,'id_puesto'=>(int)$actor['id_puesto'],'id_gas'=>(int)$actor['id_gas']],'editar');
        if (empty($scope['success'])) return false;
        $localityIds=array_map('intval',$scope['authorized_stations']??[]);
        $physicalIds=array_map('intval',$scope['authorized_physical_station_ids']??[]);
        return in_array($localityId,$localityIds,true) && in_array($stationId,$physicalIds,true);
    }

    protected function insertId(mysqli $db): int { return (int)$db->insert_id; }

    protected function grantAuthorized(mysqli $db, int $deviceId, int $stationId, int $credentialId): bool
    {
        return DeviceBiometricGrantV2::authorized($db, $deviceId, $stationId, $credentialId);
    }

    private function permissionGranted(mysqli $db,string $key,int $userId,int $positionId):bool
    {
        $user=$this->one($db,'SELECT active,granted FROM op_rh_v2_permission_grants WHERE permission_key=? AND principal_type=\'USER\' AND principal_id=? LIMIT 1','si',[$key,$userId]);
        if ($user) return (int)$user['active']===1 && (int)$user['granted']===1;
        $position=$this->one($db,'SELECT active,granted FROM op_rh_v2_permission_grants WHERE permission_key=? AND principal_type=\'POSITION\' AND principal_id=? LIMIT 1','si',[$key,$positionId]);
        return $position && (int)$position['active']===1 && (int)$position['granted']===1;
    }

    private function audit(mysqli $db,int $actorId,int $localityId,string $entity,int $entityId,string $action):void
    {
        $permission=$action==='REDEEM'||$action==='CLOSE'?'checador.admin_pin.emitir':'checador.admin_pin.revocar';
        $this->exec($db,'INSERT INTO op_rh_v2_m11_configuration_audit (actor_portal_user_id,permission_key,workplace_id,entity_type,entity_id,action,created_at_utc) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))','isisss',[$actorId,$permission,$localityId,$entity,$entityId,$action]);
    }

    protected function one(mysqli $db,string $sql,string $types,array $params):?array
    {
        $stmt=$db->prepare($sql); if(!$stmt)throw new RuntimeException('prepare_failed');
        if($types!==''){$args=[$types];foreach($params as &$value)$args[]=&$value;unset($value);if(!call_user_func_array([$stmt,'bind_param'],$args))throw new RuntimeException('bind_failed');} $stmt->execute(); $res=$stmt->get_result(); $row=$res?$res->fetch_assoc():null; $stmt->close(); return $row?:null;
    }
    protected function exec(mysqli $db,string $sql,string $types,array $params):void
    {
        $stmt=$db->prepare($sql); if(!$stmt)throw new RuntimeException('prepare_failed');
        if($types!==''){$args=[$types];foreach($params as &$value)$args[]=&$value;unset($value);if(!call_user_func_array([$stmt,'bind_param'],$args))throw new RuntimeException('bind_failed');} $stmt->execute(); $stmt->close();
    }
    protected function now(mysqli $db):string{$r=$db->query('SELECT UTC_TIMESTAMP(6) AS now_utc')->fetch_assoc();return (string)$r['now_utc'];}
    private static function minusSeconds(string $date,int $seconds):string{return (new DateTimeImmutable($date,new DateTimeZone('UTC')))->modify('-'.$seconds.' seconds')->format('Y-m-d H:i:s.u');}
    private static function plusSeconds(string $date,int $seconds):string{return (new DateTimeImmutable($date,new DateTimeZone('UTC')))->modify('+'.$seconds.' seconds')->format('Y-m-d H:i:s.u');}
    private static function epoch(string $date):int{return (new DateTimeImmutable($date,new DateTimeZone('UTC')))->getTimestamp();}
    private static function validUuid($v):bool{return is_string($v)&&preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',$v)===1;}
    private function respond(int $code,string $status):never{http_response_code($code);echo json_encode(['code'=>$code,'status'=>$status],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
}
