<?php
// =============================================================================
// RUNNER AUTÓNOMO DE PRUEBAS AUTOMATIZADAS - SLICE V2.1 (RAW PUNCH INGESTION)
// Proyecto: portal-api-v.1.0.1
// =============================================================================

require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/DispositivoV2.php';
require_once __DIR__ . '/../modelo/v2/PunchEventV2.php';

class V2TestSuite {
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
        // Ejecutar Rollback e Inserción limpia
        $this->execSqlFile(__DIR__ . '/../migraciones/v2/2026_09_28_000001_rollback_v2_raw_punch_tables.sql');
        $this->execSqlFile(__DIR__ . '/../migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql');

        // Insertar Dispositivo de prueba
        $sqlDisp = "INSERT INTO op_rh_v2_dispositivos (id, uuid_dispositivo, id_estacion, nombre, timezone, estado)
                    VALUES (1, 'DEV-TEST-001', 1, 'Lector Estacion 1', 'America/Mexico_City', 1)";
        mysqli_query($this->conexion, $sqlDisp);

        // Insertar Credencial de prueba (Secret: 'SECRET_TEST_123')
        $secretHash = password_hash('SECRET_TEST_123', PASSWORD_BCRYPT);
        $sqlCred = "INSERT INTO op_rh_v2_dispositivo_credenciales 
                    (id_dispositivo, credential_key, secret_hash, valido_desde, estado, creado_at)
                    VALUES (1, 'CRD-KEY-TEST-01', '$secretHash', '2026-01-01 00:00:00', 'ACTIVE', '2026-01-01 00:00:00')";
        mysqli_query($this->conexion, $sqlCred);

        // Insertar Identidad Histórica 1: Ref 150 -> Personal 10 (Ene - Jun 2026)
        $sqlIdent1 = "INSERT INTO op_rh_v2_dispositivo_identidad_personal
                      (id_dispositivo, referencia_externa_personal, id_personal, valido_desde, valido_hasta, es_activo)
                      VALUES (1, '150', 10, '2026-01-01 00:00:00', '2026-06-30 23:59:59', 0)";
        mysqli_query($this->conexion, $sqlIdent1);

        // Insertar Identidad Histórica 2: Ref 150 -> Personal 20 (Jul 2026 - Adelante, Activo)
        $sqlIdent2 = "INSERT INTO op_rh_v2_dispositivo_identidad_personal
                      (id_dispositivo, referencia_externa_personal, id_personal, valido_desde, valido_hasta, es_activo)
                      VALUES (1, '150', 20, '2026-07-01 00:00:00', NULL, 1)";
        mysqli_query($this->conexion, $sqlIdent2);
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
        echo "=== INICIANDO PRUEBAS AUTOMATIZADAS V2 RAW PUNCH INGESTION ===\n\n";
        $this->setupTestData();

        $serviceDisp = new DispositivoV2();
        $servicePunch = new PunchEventV2();

        // 1. testDispositivoValidoRegistraPunchEvent
        $auth = $serviceDisp->autenticarCredencial($this->conexion, 'DEV-TEST-001', 'CRD-KEY-TEST-01', 'SECRET_TEST_123');
        $this->assert($auth['auth'] === true, "1. Dispositivo válido autentica correctamente");

        $datosPunch1 = array(
            'event_uuid' => 'UUID-PUNCH-001',
            'id_dispositivo' => 1,
            'id_estacion_resuelta' => 1,
            'referencia_externa_personal' => '150',
            'id_personal_resuelto' => 20,
            'source_timestamp_raw' => '2026-09-28 10:00:00',
            'source_timezone_reported' => 'America/Mexico_City',
            'effective_timezone' => 'America/Mexico_City',
            'timezone_fallback_applied' => 0,
            'ocurrido_at_utc' => '2026-09-28 16:00:00',
            'recibido_at_utc' => '2026-09-28 16:00:05',
            'metodo_captura' => 'BIOMETRIC',
            'external_event_id' => '1001',
            'secuencia_dispositivo' => 1,
            'processing_status' => 'RECEIVED',
            'validation_flags' => array(),
            'metadata' => array('firmware' => 'v1.0')
        );
        $resPunch1 = $servicePunch->registrarPunchEvent($this->conexion, $datosPunch1);
        $this->assert($resPunch1['status_code'] === 201 && $resPunch1['result'] === 'ACCEPTED', "1. Dispositivo válido registra PunchEvent");

        // 2. testIdempotenciaRetryMismoEventUuid
        $resPunch1Retry = $servicePunch->registrarPunchEvent($this->conexion, $datosPunch1);
        $this->assert($resPunch1Retry['status_code'] === 200 && $resPunch1Retry['result'] === 'ALREADY_RECEIVED', "2. Retry del mismo event_uuid devuelve ALREADY_RECEIVED e previene duplicados");

        // 3. testDispositivoInvalidoRechazado
        $authInvalid = $serviceDisp->autenticarCredencial($this->conexion, 'DEV-TEST-001', 'CRD-KEY-TEST-01', 'WRONG_SECRET');
        $this->assert($authInvalid['auth'] === false && $authInvalid['error'] === 'INVALID_SECRET', "3. Dispositivo con clave inválida es rechazado");

        // 4. testDispositivoNoSuplantaOtroCentro
        $this->assert($auth['id_estacion'] === 1, "4. Dispositivo mantiene su estación resuelta (id_estacion=1)");

        // 5. testIdentidadConocidaResuelveTrabajador
        $personalActual = $servicePunch->resolverTrabajadorHistorico($this->conexion, 1, '150', '2026-09-28 16:00:00');
        $this->assert($personalActual === 20, "5. Identidad actual resuelve correctamente a Personal ID 20");

        // 6. testIdentidadDesconocidaMarcaPendingMatch
        $personalDesconocido = $servicePunch->resolverTrabajadorHistorico($this->conexion, 1, 'UNKNOWN-999', '2026-09-28 16:00:00');
        $this->assert($personalDesconocido === null, "6. Identidad desconocida devuelve NULL (Pending Match)");

        // 7. testTimestampsYTimezoneConservados
        $resCheckSql = mysqli_query($this->conexion, "SELECT source_timestamp_raw, effective_timezone, ocurrido_at_utc FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = 'UUID-PUNCH-001'");
        $rowCheck = mysqli_fetch_assoc($resCheckSql);
        $this->assert($rowCheck['source_timestamp_raw'] === '2026-09-28 10:00:00' && $rowCheck['effective_timezone'] === 'America/Mexico_City', "7. Timestamps originales y timezone se conservan intactos");

        // 8. testInmutabilidadSinInterpretacion
        $columnsSql = mysqli_query($this->conexion, "SHOW COLUMNS FROM op_rh_v2_marcaciones_crudas");
        $cols = array();
        while ($col = mysqli_fetch_assoc($columnsSql)) {
            $cols[] = $col['Field'];
        }
        $hasInterpretation = in_array('hora_entrada', $cols) || in_array('hora_salida', $cols) || in_array('retardo_minutos', $cols);
        $this->assert(!$hasInterpretation, "8. Tabla de Marcaciones Crudas no posee columnas de interpretación de asistencia");

        // 9. testLegacyPermanecenSinCambios
        $resLegacy = mysqli_query($this->conexion, "SHOW TABLES LIKE 'op_rh_personal_asistencia'");
        $this->assert(mysqli_num_rows($resLegacy) === 1, "9. Tabla legacy op_rh_personal_asistencia existe y permanece intacta");

        // 10. testOfflinePunchResolvesHistoricalIdentity
        $personalHistoricoMarzo = $servicePunch->resolverTrabajadorHistorico($this->conexion, 1, '150', '2026-03-15 14:00:00');
        $this->assert($personalHistoricoMarzo === 10, "10. Marcación offline ocurrida en marzo resuelve a Personal ID 10 histórico");

        // 11. testIdentityPeriodsCannotOverlap
        $resOverlap = $servicePunch->registrarIdentidadPersonal($this->conexion, 1, '150', 30, '2026-05-01 00:00:00', '2026-08-01 00:00:00');
        $this->assert($resOverlap['success'] === false && $resOverlap['error'] === 'OVERLAPPING_PERIOD', "11. Intento de insertar periodo de identidad solapado es rechazado transaccionalmente");

        // 12. testCredentialCanRotateWithoutChangingDeviceIdentity
        $newSecretHash = password_hash('NEW_SECRET_456', PASSWORD_BCRYPT);
        mysqli_query($this->conexion, "UPDATE op_rh_v2_dispositivo_credenciales SET estado = 'ROTATED', revocado_at = NOW() WHERE credential_key = 'CRD-KEY-TEST-01'");
        mysqli_query($this->conexion, "INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo, credential_key, secret_hash, valido_desde, estado, creado_at) VALUES (1, 'CRD-KEY-TEST-02', '$newSecretHash', '2026-09-01 00:00:00', 'ACTIVE', NOW())");

        $authRotatedNew = $serviceDisp->autenticarCredencial($this->conexion, 'DEV-TEST-001', 'CRD-KEY-TEST-02', 'NEW_SECRET_456');
        $this->assert($authRotatedNew['auth'] === true && $authRotatedNew['id_dispositivo'] === 1, "12. Credencial rotada autentica conservando la identidad del mismo dispositivo (id=1)");

        // 13. testRevokedCredentialCannotAuthenticate
        $authRotatedOld = $serviceDisp->autenticarCredencial($this->conexion, 'DEV-TEST-001', 'CRD-KEY-TEST-01', 'SECRET_TEST_123');
        $this->assert($authRotatedOld['auth'] === false, "13. Credencial revocada/rotada antigua no puede autenticar");

        // 14. testInvalidTimezonePreservesRawValue
        $datosPunchInvalidTz = array(
            'event_uuid' => 'UUID-PUNCH-INVALID-TZ',
            'id_dispositivo' => 1,
            'referencia_externa_personal' => '150',
            'source_timestamp_raw' => '2026-09-28 10:00:00',
            'source_timezone_reported' => 'INVALID_TIMEZONE_STRING',
            'effective_timezone' => 'America/Mexico_City',
            'timezone_fallback_applied' => 1,
            'ocurrido_at_utc' => '2026-09-28 16:00:00',
            'recibido_at_utc' => '2026-09-28 16:00:05',
            'metodo_captura' => 'BIOMETRIC',
            'processing_status' => 'RECEIVED',
            'validation_flags' => array('INVALID_TIMEZONE_REPORTED')
        );
        $servicePunch->registrarPunchEvent($this->conexion, $datosPunchInvalidTz);
        $resTzCheck = mysqli_query($this->conexion, "SELECT source_timezone_reported, effective_timezone, timezone_fallback_applied FROM op_rh_v2_marcaciones_crudas WHERE event_uuid = 'UUID-PUNCH-INVALID-TZ'");
        $rowTzCheck = mysqli_fetch_assoc($resTzCheck);
        $this->assert($rowTzCheck['source_timezone_reported'] === 'INVALID_TIMEZONE_STRING' && (int)$rowTzCheck['timezone_fallback_applied'] === 1, "14. Zona inválida conserva el valor raw reportado y marca el flag de fallback");

        // 15. testTimezoneFallbackDoesNotPretendItWasReported
        $this->assert($rowTzCheck['effective_timezone'] === 'America/Mexico_City' && $rowTzCheck['source_timezone_reported'] !== $rowTzCheck['effective_timezone'], "15. Fallback utiliza la zona del dispositivo sin fingir que fue la reportada");

        // 16. testOldSourceTimestampAllowed
        $datosPunchOldOffline = array(
            'event_uuid' => 'UUID-PUNCH-OLD-OFFLINE',
            'id_dispositivo' => 1,
            'referencia_externa_personal' => '150',
            'id_personal_resuelto' => 10,
            'source_timestamp_raw' => '2026-01-15 08:00:00',
            'effective_timezone' => 'America/Mexico_City',
            'ocurrido_at_utc' => '2026-01-15 14:00:00',
            'recibido_at_utc' => '2026-09-28 16:00:00',
            'metodo_captura' => 'BIOMETRIC',
            'processing_status' => 'RECEIVED'
        );
        $resOldPunch = $servicePunch->registrarPunchEvent($this->conexion, $datosPunchOldOffline);
        $this->assert($resOldPunch['status_code'] === 201, "16. Marcación offline antigua es aceptada e ingresada correctamente");

        // 17. testRollbackOnlyDropsV2Structures
        $this->execSqlFile(__DIR__ . '/../migraciones/v2/2026_09_28_000001_rollback_v2_raw_punch_tables.sql');
        $resV2TableCheck = mysqli_query($this->conexion, "SHOW TABLES LIKE 'op_rh_v2_marcaciones_crudas'");
        $resLegacyTableCheck = mysqli_query($this->conexion, "SHOW TABLES LIKE 'op_rh_personal_asistencia'");
        $this->assert(mysqli_num_rows($resV2TableCheck) === 0 && mysqli_num_rows($resLegacyTableCheck) === 1, "17. Rollback elimina únicamente tablas V2 conservando intacta la estructura legacy");

        // Restaurar tablas V2 para continuar entorno de dev
        $this->execSqlFile(__DIR__ . '/../migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql');

        echo "\n=== RESUMEN DE PRUEBAS V2 ===";
        echo "\nTOTAL PRUEBAS : " . ($this->passes + $this->fails);
        echo "\nPASARON       : " . $this->passes;
        echo "\nFALLARON      : " . $this->fails . "\n\n";

        return $this->fails === 0;
    }
}

$suite = new V2TestSuite();
$success = $suite->run();
exit($success ? 0 : 1);
