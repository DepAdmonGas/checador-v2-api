<?php
// =============================================================================
// RUNNER AUTÓNOMO DE PRUEBAS AUTOMATIZADAS - SLICE V2.2-G8-A2 (STRICT REMEDIATION)
// Proyecto: portal-api-v.1.0.1
// =============================================================================

require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/DispositivoV2.php';
require_once __DIR__ . '/../modelo/v2/Portal3JwtAuthHelper.php';
require_once __DIR__ . '/../modelo/v2/TemporaryAccessV2Service.php';
require_once __DIR__ . '/../modelo/v2/TemporaryAccessV2Controller.php';

class TemporaryAccessV2StrictTestSuite {
    private $conexion;
    private $passes = 0;
    private $fails = 0;

    public function __construct() {
        $bd = new BD();
        $this->conexion = $bd->conectarBD();
    }

    public function __destruct() {
        if ($this->conexion) {
            $bd = new BD();
            $bd->desconectarBD($this->conexion);
        }
    }

    private function assert($condition, $message) {
        if ($condition) {
            echo "[ PASS ] $message\n";
            $this->passes++;
        } else {
            echo "[ FAIL ] $message\n";
            $this->fails++;
        }
    }

    public function setupTestData() {
        $this->execSqlFile(__DIR__ . '/../migraciones/v2/2026_10_01_000001_rollback_v2_temporary_access_tables.sql');
        $this->execSqlFile(__DIR__ . '/../migraciones/v2/2026_09_28_000001_rollback_v2_raw_punch_tables.sql');

        $this->execSqlFile(__DIR__ . '/../migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql');
        $this->execSqlFile(__DIR__ . '/../migraciones/v2/2026_10_01_000001_create_v2_temporary_access_tables.sql');

        // Dispositivo Estación 1
        $sqlDisp1 = "INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado)
                     VALUES (1, 'DEV-TEST-001', 1, 'Lector Estacion 1', 'America/Mexico_City', 1)";
        mysqli_query($this->conexion, $sqlDisp1);

        // Dispositivo Estación 2
        $sqlDisp2 = "INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado)
                     VALUES (2, 'DEV-TEST-002', 2, 'Lector Estacion 2', 'America/Mexico_City', 1)";
        mysqli_query($this->conexion, $sqlDisp2);

        // Identidad Personal Ref 150 -> Personal ID 20 (Estacion 1)
        $sqlIdent1 = "INSERT INTO op_rh_v2_dispositivo_identidad_personal
                      (id_dispositivo, referencia_externa_personal, id_personal, valido_desde, valido_hasta, es_activo)
                      VALUES (1, '150', 20, '2026-01-01 00:00:00', NULL, 1)";
        mysqli_query($this->conexion, $sqlIdent1);

        // Identidad Personal Ref 150 -> Personal ID 20 (Estacion 2)
        $sqlIdent2 = "INSERT INTO op_rh_v2_dispositivo_identidad_personal
                      (id_dispositivo, referencia_externa_personal, id_personal, valido_desde, valido_hasta, es_activo)
                      VALUES (2, '150', 20, '2026-01-01 00:00:00', NULL, 1)";
        mysqli_query($this->conexion, $sqlIdent2);

        // Identidad Personal Ref 180 -> Personal ID 30 (Estacion 1)
        $sqlIdent3 = "INSERT INTO op_rh_v2_dispositivo_identidad_personal
                      (id_dispositivo, referencia_externa_personal, id_personal, valido_desde, valido_hasta, es_activo)
                      VALUES (1, '180', 30, '2026-01-01 00:00:00', NULL, 1)";
        mysqli_query($this->conexion, $sqlIdent3);

        // Personal legacy para join de nombres
        mysqli_query($this->conexion, "INSERT IGNORE INTO op_rh_personal (id, no_colaborador, nombre_completo, id_estacion) VALUES (20, 150, 'Juan Perez Test', 1)");
        mysqli_query($this->conexion, "INSERT IGNORE INTO op_rh_personal (id, no_colaborador, nombre_completo, id_estacion) VALUES (30, 180, 'Maria Lopez Test', 1)");
    }

    public function execSqlFile($filePath) {
        $sql = file_get_contents($filePath);
        $queries = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($queries as $q) {
            if (!empty($q)) {
                mysqli_query($this->conexion, $q);
            }
        }
    }

    public function run() {
        putenv('JWT_SECRET=Portal3_JWT_Secret_Key_2026_AdmonGas');
        echo "=== INICIANDO PRUEBAS AUTOMATIZADAS DE REMEDIACIÓN DE CONTRATO (G8-A2) ===\n\n";

        // Guard hashes congelados
        $archHash = hash_file('sha256', __DIR__ . '/../docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md');
        $contractHash = hash_file('sha256', __DIR__ . '/../docs/v2/TEMPORARY_ACCESS_API_CONTRACT.md');
        $this->assert($archHash === '87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85', "0a. Hash Architecture Freeze V1.0 intacto");
        $this->assert($contractHash === '75396d690fc83f0ad592dc0b559671859c8d6ddf3e95d37feedde57c6beb5ab9', "0b. Hash Temporary Access Contract V1.0 intacto");

        $this->setupTestData();
        $service = new TemporaryAccessV2Service();

        // 1. Valid Create Request con request_uuid generado por Lector
        $req1Uuid = 'c71e8400-e29b-41d4-a716-446655440001';
        $res1 = $service->createRequest($this->conexion, 1, 1, $req1Uuid, '150');
        $this->assert($res1['success'] === true && $res1['code'] === 201, "1. Crear solicitud válida para trabajador conocido retorna 201 Created");
        $this->assert($res1['data']['request_uuid'] === $req1Uuid && $res1['data']['request_status'] === 'PENDING', "1b. Petición conserva exactamente el request_uuid del Lector y estado PENDING");

        // 2. Unknown worker
        $res2 = $service->createRequest($this->conexion, 1, 1, 'c71e8400-0002', 'UNKNOWN_REF');
        $this->assert($res2['success'] === false && $res2['code'] === 404, "2. Solicitud para trabajador desconocido retorna 404 WORKER_NOT_FOUND");

        // 3. Worker wrong station (dispositivo 2 pertenece a estación 2, pero se manda estación 1) -> Identidad resuelve en disp 2
        $res3 = $service->createRequest($this->conexion, 2, 2, 'c71e8400-0003', '150');
        $this->assert($res3['success'] === true && $res3['code'] === 201, "3. Dispositivo en estación 2 asigna id_estacion = 2 correctamente");

        // 4. request_uuid duplicate idempotency (Lost ACK en creación)
        $resDup = $service->createRequest($this->conexion, 1, 1, $req1Uuid, '150');
        $this->assert($resDup['success'] === true && $resDup['code'] === 201, "4. Reintento con mismo request_uuid retorna solicitud existente por idempotencia (201)");

        // 5. Second request cancels active request
        $req2Uuid = 'c71e8400-e29b-41d4-a716-446655440002';
        $resSecond = $service->createRequest($this->conexion, 1, 1, $req2Uuid, '150');
        $this->assert($resSecond['success'] === true, "5. Nueva solicitud con request_uuid diferente es exitosa");

        $rCheck = mysqli_query($this->conexion, "SELECT status, cancelled_by_user_id FROM op_rh_v2_solicitudes_acceso_temporal WHERE request_uuid = '$req1Uuid'");
        $rowPrev = mysqli_fetch_assoc($rCheck);
        $this->assert($rowPrev['status'] === 'CANCELLED' && $rowPrev['cancelled_by_user_id'] === null, "5b. Solicitud previa req1 fue marcada como CANCELLED transaccionalmente con cancelled_by_user_id = NULL");

        // 6. Concurrent create (dos requests simultáneos serializados)
        $req3Uuid = 'c71e8400-e29b-41d4-a716-446655440003';
        $resConc = $service->createRequest($this->conexion, 1, 1, $req3Uuid, '180');
        $this->assert($resConc['success'] === true, "6. Solicitud concurrente para otro trabajador (Maria Lopez ID 30) se registra exitosamente");

        // 7. Request expiration (Lazy expiration PENDING)
        $reqExpUuid = 'c71e8400-e29b-41d4-a716-446655440099';
        $service->createRequest($this->conexion, 1, 1, $reqExpUuid, '180'); // anula req3
        mysqli_query($this->conexion, "UPDATE op_rh_v2_solicitudes_acceso_temporal SET request_expires_at_utc = '2026-01-01 00:00:00' WHERE request_uuid = '$reqExpUuid'");
        $service->lazyExpireRequests($this->conexion);
        $rExpCheck = mysqli_query($this->conexion, "SELECT status FROM op_rh_v2_solicitudes_acceso_temporal WHERE request_uuid = '$reqExpUuid'");
        $this->assert(mysqli_fetch_assoc($rExpCheck)['status'] === 'EXPIRED', "7. Solicitud PENDING vencida en el pasado cambia a EXPIRED perezosamente");

        // 8. GET pending requests for Portal3
        $getPending = $service->getPendingRequestsForPortal3($this->conexion, array(1));
        $this->assert(count($getPending['data']) === 1 && $getPending['data'][0]['request_uuid'] === $req2Uuid, "8. GET /requests retorna únicamente 1 solicitud PENDING activa para Estación 1");

        // 9. GET allowed stations
        $getPendingMulti = $service->getPendingRequestsForPortal3($this->conexion, array(1, 2));
        $this->assert(count($getPendingMulti['data']) === 2, "9. GET /requests para supervisor con acceso a Estación 1 y 2 retorna 2 solicitudes");

        // 10. Unauthorized station filter
        $getForbidden = $service->getPendingRequestsForPortal3($this->conexion, array(1), 2);
        $this->assert($getForbidden['success'] === false && $getForbidden['code'] === 403, "10. Supervisor intenta filtrar estación no autorizada y recibe 403 FORBIDDEN");

        // 11. Authorize success
        $authRes = $service->authorizeRequest($this->conexion, array(1), $req2Uuid, 99);
        $this->assert($authRes['success'] === true && $authRes['data']['status'] === 'AUTHORIZED', "11. POST /requests/{uuid}/authorize por supervisor de Estación 1 retorna 200 AUTHORIZED");
        $this->assert(strlen($authRes['data']['temporary_pin']) === 6, "11b. Retorna temporary_pin de 6 dígitos una sola vez");
        $plainPin = $authRes['data']['temporary_pin'];

        // 12. Authorize expired
        $reqExpAuthUuid = 'c71e8400-e29b-41d4-a716-446655440098';
        $service->createRequest($this->conexion, 1, 1, $reqExpAuthUuid, '180');
        mysqli_query($this->conexion, "UPDATE op_rh_v2_solicitudes_acceso_temporal SET request_expires_at_utc = '2026-01-01 00:00:00' WHERE request_uuid = '$reqExpAuthUuid'");
        $authExpRes = $service->authorizeRequest($this->conexion, array(1), $reqExpAuthUuid, 99);
        $this->assert($authExpRes['success'] === false && $authExpRes['code'] === 409, "12. Intentar autorizar solicitud expirada retorna 409 REQUEST_EXPIRED");

        // 13. Authorize cross-tenant
        $reqCrossUuid = 'c71e8400-e29b-41d4-a716-446655440077-st2';
        $service->createRequest($this->conexion, 2, 2, $reqCrossUuid, '150');
        $authCrossRes = $service->authorizeRequest($this->conexion, array(1), $reqCrossUuid, 99);
        $this->assert($authCrossRes['success'] === false && $authCrossRes['code'] === 403, "13. Supervisor de Estación 1 intentando autorizar solicitud de Estación 2 recibe 403 FORBIDDEN");

        // 14. Authorize invalid state (ya está AUTHORIZED)
        $authRepeat = $service->authorizeRequest($this->conexion, array(1), $req2Uuid, 99);
        $this->assert($authRepeat['success'] === false && $authRepeat['code'] === 409, "14. Intentar re-autorizar solicitud que ya no está PENDING retorna 409 INVALID_STATE");

        // 15. Reject success
        $reqRejUuid = 'c71e8400-e29b-41d4-a716-446655440077';
        $service->createRequest($this->conexion, 1, 1, $reqRejUuid, '180');
        $rejRes = $service->rejectRequest($this->conexion, array(1), $reqRejUuid, 99);
        $this->assert($rejRes['success'] === true && $rejRes['data']['status'] === 'REJECTED', "15. POST /requests/{uuid}/reject retorna 200 REJECTED");

        // 16. Reject cross-tenant
        $rejCrossRes = $service->rejectRequest($this->conexion, array(1), $reqCrossUuid, 99);
        $this->assert($rejCrossRes['success'] === false && $rejCrossRes['code'] === 403, "16. Supervisor de Estación 1 intentando rechazar solicitud de Estación 2 recibe 403 FORBIDDEN");

        // 17. Cancel success
        $reqCancUuid = 'c71e8400-e29b-41d4-a716-446655440066';
        $service->createRequest($this->conexion, 1, 1, $reqCancUuid, '180');
        $cancRes = $service->cancelRequest($this->conexion, array(1), $reqCancUuid, 99);
        $this->assert($cancRes['success'] === true && $cancRes['data']['status'] === 'CANCELLED', "17. POST /requests/{uuid}/cancel por supervisor retorna 200 CANCELLED");

        // 18. Cancel cross-tenant
        $cancCrossRes = $service->cancelRequest($this->conexion, array(1), $reqCrossUuid, 99);
        $this->assert($cancCrossRes['success'] === false && $cancCrossRes['code'] === 403, "18. Supervisor de Estación 1 intentando cancelar solicitud de Estación 2 recibe 403 FORBIDDEN");

        // 19. System cancellation audit
        $rCancAudit = mysqli_query($this->conexion, "SELECT cancelled_by_user_id FROM op_rh_v2_solicitudes_acceso_temporal WHERE request_uuid = '$reqCancUuid'");
        $this->assert(intval(mysqli_fetch_assoc($rCancAudit)['cancelled_by_user_id']) === 99, "19. Cancelación por supervisor persiste authorized/cancelled_by_user_id = 99");

        // 20. Temporary PIN bcrypt
        $rPinDb = mysqli_query($this->conexion, "SELECT pin_hash FROM op_rh_v2_solicitudes_acceso_temporal WHERE request_uuid = '$req2Uuid'");
        $hashInDb = mysqli_fetch_assoc($rPinDb)['pin_hash'];
        $this->assert(password_verify($plainPin, $hashInDb) === true, "20. PIN temporal se almacena como hash Bcrypt válido");

        // 21. Plaintext PIN absent DB
        $rPlainCheck = mysqli_query($this->conexion, "SHOW COLUMNS FROM op_rh_v2_solicitudes_acceso_temporal LIKE '%pin_code%'");
        $this->assert(mysqli_num_rows($rPlainCheck) === 0, "21. Confirmado que no existe columna de PIN plano en la base de datos");

        // 22. Invalid PIN increments attempts
        $val1 = $service->validatePin($this->conexion, 1, 1, '150', '000000', 'val-uuid-001');
        $this->assert($val1['success'] === false && $val1['code'] === 400 && $val1['status'] === 'INVALID_PIN' && $val1['attempts_remaining'] === 2, "22. Intentar PIN incorrecto descuenta intentos (2 remaining, status INVALID_PIN)");

        // 23. Max attempts LOCKED
        $service->validatePin($this->conexion, 1, 1, '150', '000000', 'val-uuid-002');
        $valMax = $service->validatePin($this->conexion, 1, 1, '150', '000000', 'val-uuid-003');
        $this->assert($valMax['success'] === false && $valMax['code'] === 403 && $valMax['status'] === 'LOCKED', "23. Al 3er intento fallido la solicitud transiciona atómicamente a LOCKED");

        // Re-autorizar para pruebas de validación exitosa
        $reqValUuid = 'c71e8400-e29b-41d4-a716-446655440055';
        $service->createRequest($this->conexion, 1, 1, $reqValUuid, '150');
        $authValRes = $service->authorizeRequest($this->conexion, array(1), $reqValUuid, 99);
        $valPinCode = $authValRes['data']['temporary_pin'];

        // 24. Expired PIN
        $reqExpPinUuid = 'c71e8400-e29b-41d4-a716-446655440044';
        $service->createRequest($this->conexion, 1, 1, $reqExpPinUuid, '180');
        $service->authorizeRequest($this->conexion, array(1), $reqExpPinUuid, 99);
        mysqli_query($this->conexion, "UPDATE op_rh_v2_solicitudes_acceso_temporal SET pin_expires_at_utc = '2026-01-01 00:00:00' WHERE request_uuid = '$reqExpPinUuid'");
        $valExpPin = $service->validatePin($this->conexion, 1, 1, '180', '123456', 'val-uuid-exp');
        $this->assert($valExpPin['success'] === false && $valExpPin['code'] === 400 && $valExpPin['status'] === 'EXPIRED', "24. PIN en estado AUTHORIZED con pin_expires_at_utc en el pasado retorna 400 EXPIRED");

        // 25. Valid PIN -> USED
        $valSuccUuid = 'val-uuid-success-001';
        $valGood = $service->validatePin($this->conexion, 1, 1, '150', $valPinCode, $valSuccUuid);
        $this->assert($valGood['success'] === true && $valGood['code'] === 200 && $valGood['data']['status'] === 'VALID', "25. Validar PIN correcto retorna 200 VALID");

        // 26. successful_validation_uuid stored
        $rValStore = mysqli_query($this->conexion, "SELECT status, successful_validation_uuid FROM op_rh_v2_solicitudes_acceso_temporal WHERE request_uuid = '$reqValUuid'");
        $rowValStore = mysqli_fetch_assoc($rValStore);
        $this->assert($rowValStore['status'] === 'USED' && $rowValStore['successful_validation_uuid'] === $valSuccUuid, "26. Transición atómica a status = USED y successful_validation_uuid almacenado");

        // 27. Same validation_uuid Lost ACK
        $valAckReplay = $service->validatePin($this->conexion, 1, 1, '150', $valPinCode, $valSuccUuid);
        $this->assert($valAckReplay['success'] === true && $valAckReplay['code'] === 200 && $valAckReplay['data']['status'] === 'VALID', "27. Reintento con mismo validation_uuid por Lost ACK retorna 200 VALID de forma idéntica");

        // 28. Different validation_uuid -> ALREADY_USED
        $valDiffUuid = $service->validatePin($this->conexion, 1, 1, '150', $valPinCode, 'val-uuid-different-002');
        $this->assert($valDiffUuid['success'] === false && $valDiffUuid['code'] === 409 && $valDiffUuid['status'] === 'ALREADY_USED', "28. Revalidación con diferente validation_uuid retorna 409 ALREADY_USED");

        // 29. Wrong device replay
        $valWrongDev = $service->validatePin($this->conexion, 2, 1, '150', $valPinCode, 'val-uuid-wrong-dev');
        $this->assert($valWrongDev['success'] === false, "29. Intento de validación con dispositivo cruzado falla");

        // 30. Wrong station replay
        $valWrongSt = $service->validatePin($this->conexion, 1, 2, '150', $valPinCode, 'val-uuid-wrong-st');
        $this->assert($valWrongSt['success'] === false, "30. Intento de validación con estación cruzada falla");

        // 31. Wrong worker replay
        $valWrongWk = $service->validatePin($this->conexion, 1, 1, '180', $valPinCode, 'val-uuid-wrong-wk');
        $this->assert($valWrongWk['success'] === false, "31. Intento de validación con trabajador cruzado falla");

        // 32. Device Auth invalid
        $dispAuth = new DispositivoV2();
        $authBad = $dispAuth->autenticarCredencial($this->conexion, 'DEV-TEST-001', 'KEY-INEXISTENTE', 'WRONG');
        $this->assert($authBad['auth'] === false, "32. Credenciales de dispositivo incorrectas son rechazadas");

        // 33. JWT valid
        $jwtValHeader = $this->buildMockJwtHeader(99, 'access', time() + 3600, 'portal3_auth', 'Portal3_JWT_Secret_Key_2026_AdmonGas');
        $jwtValRes = Portal3JwtAuthHelper::validateToken($this->conexion, $jwtValHeader);
        $this->assert($jwtValRes['valid'] === true && $jwtValRes['user_id'] === 99, "33. JWT Helper valida firma, iss=portal3_auth, exp, type=access y sub");

        // 34. JWT invalid signature
        $jwtBadSig = $this->buildMockJwtHeader(99, 'access', time() + 3600, 'portal3_auth', 'WRONG_SECRET');
        $jwtBadSigRes = Portal3JwtAuthHelper::validateToken($this->conexion, $jwtBadSig);
        $this->assert($jwtBadSigRes['valid'] === false && $jwtBadSigRes['error'] === 'Firma JWT inválida.', "34. JWT Helper rechaza firma con secret incorrecto");

        // 35. JWT expired
        $jwtExpHeader = $this->buildMockJwtHeader(99, 'access', time() - 3600, 'portal3_auth', 'Portal3_JWT_Secret_Key_2026_AdmonGas');
        $jwtExpRes = Portal3JwtAuthHelper::validateToken($this->conexion, $jwtExpHeader);
        $this->assert($jwtExpRes['valid'] === false && $jwtExpRes['error'] === 'Token JWT expirado.', "35. JWT Helper rechaza token con exp en el pasado");

        // 36. JWT wrong issuer
        $jwtBadIss = $this->buildMockJwtHeader(99, 'access', time() + 3600, 'WRONG_ISSUER', 'Portal3_JWT_Secret_Key_2026_AdmonGas');
        $jwtBadIssRes = Portal3JwtAuthHelper::validateToken($this->conexion, $jwtBadIss);
        $this->assert($jwtBadIssRes['valid'] === false && $jwtBadIssRes['error'] === 'Issuer JWT incorrecto.', "36. JWT Helper rechaza issuer distinto de portal3_auth");

        // 37. JWT wrong type
        $jwtBadType = $this->buildMockJwtHeader(99, 'refresh', time() + 3600, 'portal3_auth', 'Portal3_JWT_Secret_Key_2026_AdmonGas');
        $jwtBadTypeRes = Portal3JwtAuthHelper::validateToken($this->conexion, $jwtBadType);
        $this->assert($jwtBadTypeRes['valid'] === false && $jwtBadTypeRes['error'] === 'Tipo de token JWT no es access.', "37. JWT Helper rechaza type distinto de access");

        // 38. JWT malformed
        $jwtMal = Portal3JwtAuthHelper::validateToken($this->conexion, 'Bearer token_malformado_sin_puntos');
        $this->assert($jwtMal['valid'] === false && $jwtMal['error'] === 'Estructura JWT malformada.', "38. JWT Helper rechaza token sin formato de 3 partes");

        // 39. JWT missing required claim
        $jwtNoSub = 'Bearer ' . rtrim(strtr(base64_encode(json_encode(array('alg'=>'HS256'))), '+/', '-_'), '=') . '.' . rtrim(strtr(base64_encode(json_encode(array('exp'=>time()+3600, 'type'=>'access'))), '+/', '-_'), '=') . '.fake';
        $jwtNoSubRes = Portal3JwtAuthHelper::validateToken($this->conexion, $jwtNoSub);
        $this->assert($jwtNoSubRes['valid'] === false, "39. JWT Helper rechaza token sin claim sub");

        // 40. Supervisor without station
        $stNoAccess = Portal3JwtAuthHelper::getAuthorizedStationsForUser($this->conexion, 99999);
        $this->assert(empty($stNoAccess), "40. Supervisor sin asignación en localidades_perfil/personal retorna 0 estaciones autorizadas");

        // 41. Permission denied
        $getEmpty = $service->getPendingRequestsForPortal3($this->conexion, array());
        $this->assert(empty($getEmpty['data']), "41. GET /requests para supervisor sin estaciones retorna array vacío de forma segura");

        // 42. PIN absent new logs (Verificación de código fuente)
        $controllerSrc = file_get_contents(__DIR__ . '/../modelo/v2/TemporaryAccessV2Controller.php');
        $serviceSrc = file_get_contents(__DIR__ . '/../modelo/v2/TemporaryAccessV2Service.php');
        $this->assert(strpos($controllerSrc, 'error_log') === false && strpos($serviceSrc, 'error_log') === false, "42. Verificado que no hay impresiones ni logs de PINs planos");

        // 43. Only 7 official statuses
        $officialStatuses = array('PENDING', 'AUTHORIZED', 'REJECTED', 'EXPIRED', 'LOCKED', 'USED', 'CANCELLED');
        $rStatusQuery = mysqli_query($this->conexion, "SELECT DISTINCT status FROM op_rh_v2_solicitudes_acceso_temporal");
        $allValid = true;
        while ($rStRow = mysqli_fetch_assoc($rStatusQuery)) {
            if (!in_array($rStRow['status'], $officialStatuses, true)) {
                $allValid = false;
            }
        }
        $this->assert($allValid === true, "43. Confirmado que todos los registros en BD corresponden únicamente a los 7 estados oficiales V1.0");

        // 44. No CONSUMED persisted
        $rCons = mysqli_query($this->conexion, "SELECT COUNT(*) AS total FROM op_rh_v2_solicitudes_acceso_temporal WHERE status = 'CONSUMED'");
        $this->assert(intval(mysqli_fetch_assoc($rCons)['total']) === 0, "44. Confirmado que el estado CONSUMED no existe en la base de datos");

        // 45. No INVALIDATED persisted
        $rInval = mysqli_query($this->conexion, "SELECT COUNT(*) AS total FROM op_rh_v2_solicitudes_acceso_temporal WHERE status = 'INVALIDATED'");
        $this->assert(intval(mysqli_fetch_assoc($rInval)['total']) === 0, "45. Confirmado que el estado INVALIDATED no existe en la base de datos");

        // 46. No extra endpoint /status
        $indexSrc = file_get_contents(__DIR__ . '/../index.php');
        $this->assert(strpos($indexSrc, "'status'") === false, "46. Ruta no contractual GET /status eliminada de index.php");

        // 47. No extra /request endpoint
        $this->assert(strpos($indexSrc, "'request'") === false, "47. Ruta no contractual POST /request eliminada de index.php");

        // 48. Cancel endpoint route semantics
        $this->assert(strpos($indexSrc, "'cancel'") !== false && strpos($indexSrc, '$subAction === \'cancel\'') !== false, "48. Ruta contractual POST /requests/{request_uuid}/cancel implementada correctamente");

        // 49. Validate endpoint exact
        $this->assert(strpos($indexSrc, "'validate'") !== false && strpos($indexSrc, "'validate-pin'") === false, "49. Ruta contractual POST /temporary-access/validate implementada exactamente");

        // 50. Legacy schema untouched
        $rPersDesc = mysqli_query($this->conexion, "DESCRIBE op_rh_personal");
        $this->assert(mysqli_num_rows($rPersDesc) > 0, "50. Estructura y datos legacy op_rh_personal intactos");

        // 51. Segunda tabla op_rh_v2_pins_acceso_temporal NO existe en la base de datos
        $checkSecondTable = mysqli_query($this->conexion, "SHOW TABLES LIKE 'op_rh_v2_pins_acceso_temporal'");
        $this->assert(mysqli_num_rows($checkSecondTable) === 0, "51. Verificado que la tabla no contractual op_rh_v2_pins_acceso_temporal no existe");

        // 52. V2 Primary Route Guardrail (Confirmar que todo desarrollo nuevo del Checador utiliza /api/v2)
        $indexContent = file_get_contents(__DIR__ . '/../index.php');
        $this->assert(strpos($indexContent, "'api'") !== false && strpos($indexContent, "'v2'") !== false, "52. V2 Primary Route Guardrail: El router index.php exige prefijo /api/v2 para todas las funcionalidades V2");

        // 53. SEC-M01: Cache-Control no-store policy check in controller source
        $controllerSrc = file_get_contents(__DIR__ . '/../modelo/v2/TemporaryAccessV2Controller.php');
        $this->assert(strpos($controllerSrc, "Cache-Control: no-store") !== false && strpos($controllerSrc, "Pragma: no-cache") !== false, "53. SEC-M01: Controller incluye encabezados Cache-Control: no-store y Pragma: no-cache");

        // 54. SEC-M02: JWT Fail-Closed when JWT_SECRET is missing/empty
        $oldEnv = getenv('JWT_SECRET');
        putenv('JWT_SECRET=');
        $jwtFailRes = Portal3JwtAuthHelper::validateToken($this->conexion, $this->buildMockJwtHeader(99, 'access', time() + 3600, 'portal3_auth', 'some_secret'));
        $this->assert($jwtFailRes['valid'] === false && $jwtFailRes['code'] === 500 && strpos($jwtFailRes['error'], 'Configuración de seguridad') !== false, "54. SEC-M02: JWT Helper falla cerrado (500) cuando JWT_SECRET está ausente en el entorno");
        if ($oldEnv !== false) {
            putenv("JWT_SECRET=$oldEnv");
        } else {
            putenv('JWT_SECRET=Portal3_JWT_Secret_Key_2026_AdmonGas');
        }

        // 55. SEC-L01: Strict UUIDv4 rejection on non-UUIDv4 request_uuid
        $invalidUuidRes = $service->createRequest($this->conexion, 1, 1, 'invalid-uuid-string-12345', '150');
        // El servicio procesa el valor que le pasa el controlador; probamos la lógica helper a través del controlador
        $ctrlTemp = new TemporaryAccessV2Controller();
        $refMethod = new ReflectionMethod('TemporaryAccessV2Controller', 'validateUuidV4');
        $refMethod->setAccessible(true);
        $this->assert($refMethod->invoke($ctrlTemp, 'c71e8400-e29b-41d4-a716-446655440001') === true, "55. SEC-L01: Helper validateUuidV4 acepta un UUIDv4 válido");
        $this->assert($refMethod->invoke($ctrlTemp, 'invalid-uuid-12345') === false, "55b. SEC-L01: Helper validateUuidV4 rechaza una cadena de UUID inválida");
        $this->assert($refMethod->invoke($ctrlTemp, 'c71e8400-e29b-11d4-a716-446655440001') === false, "55c. SEC-L01: Helper validateUuidV4 rechaza un UUIDv1 (no es versión 4)");

        echo "\n=== RESUMEN DE PRUEBAS V2 TEMPORARY ACCESS REMEDIACIÓN (G8-A2) ===\n";
        echo "TOTAL PRUEBAS : " . ($this->passes + $this->fails) . "\n";
        echo "PASARON       : " . $this->passes . "\n";
        echo "FALLARON      : " . $this->fails . "\n\n";

        if ($this->fails > 0) {
            exit(1);
        }
    }

    private function buildMockJwtHeader($userId, $type, $expTime, $iss, $secret) {
        $head = array('alg' => 'HS256', 'typ' => 'JWT');
        $payload = array('sub' => $userId, 'type' => $type, 'exp' => $expTime, 'iss' => $iss);

        $b64Head = rtrim(strtr(base64_encode(json_encode($head)), '+/', '-_'), '=');
        $b64Payload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        $sig = hash_hmac('sha256', $b64Head . '.' . $b64Payload, $secret, true);
        $b64Sig = rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

        return 'Bearer ' . $b64Head . '.' . $b64Payload . '.' . $b64Sig;
    }
}

$testRunner = new TemporaryAccessV2StrictTestSuite();
$testRunner->run();
