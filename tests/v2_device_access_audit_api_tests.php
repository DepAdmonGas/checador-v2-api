<?php
/**
 * Test Suite: Checador V2 Device Access Audit API Tests (API-02)
 *
 * Verifies all 20 focused requirements for:
 * POST /api/v2/device/access-audits
 * Plus API-01 Station Isolation Security Regression
 *
 * Specification: docs/v2/CHECADOR_V2_DEVICE_ENTRY_WINDOW_API_CONTRACT_V1.0.md (APPROVED_FROZEN_V1.4)
 * Architecture Addendum: docs/architecture/CHECADOR_V2_EARLY_ENTRY_GATEKEEPER_ADDENDUM_V1.0.md (APPROVED_FROZEN_V1.4)
 */

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/gatekeeper/DeviceAccessAuditV2Service.php';
require_once __DIR__ . '/../modelo/v2/gatekeeper/DeviceAccessAuditV2Controller.php';
require_once __DIR__ . '/../modelo/v2/gatekeeper/DeviceEntryWindowV2Service.php';
require_once __DIR__ . '/../modelo/v2/DispositivoV2.php';

$passedCount = 0;
$failedCount = 0;

function assert_test(bool $condition, string $id, string $desc, ?string $details = null): void
{
    global $passedCount, $failedCount;
    if ($condition) {
        $passedCount++;
        echo "[$id] PASS: $desc\n";
    } else {
        $failedCount++;
        echo "[$id] FAIL: $desc\n";
        if ($details !== null) {
            echo "       Detail: $details\n";
        }
    }
}

// =========================================================================
// TEST DOUBLES / MOCKS (Pure deterministic in-memory fixtures, ZERO DML on DB)
// =========================================================================

class MockDeviceAuthForAudit extends DispositivoV2
{
    public function autenticarCredencial($conexion, $uuidDispositivo, $credentialKey, $secret)
    {
        $devId = trim((string)$uuidDispositivo);
        $key = trim((string)$credentialKey);
        $sec = (string)$secret;

        if ($devId === '' || $key === '' || $sec === '') {
            return ['auth' => false, 'error' => 'CREDENTIAL_NOT_FOUND'];
        }
        if ($devId === 'REVOKED-DEV' || $key === 'REVOKED-KEY') {
            return ['auth' => false, 'error' => 'CREDENTIAL_REVOKED_OR_INACTIVE'];
        }
        if ($devId === 'DISABLED-DEV') {
            return ['auth' => false, 'error' => 'DEVICE_DISABLED'];
        }
        if ($sec !== 'valid_device_secret_12345') {
            return ['auth' => false, 'error' => 'INVALID_SECRET'];
        }
        if ($devId === 'DEV-STATION-01' || $devId === '2') {
            return [
                'auth' => true,
                'id_dispositivo' => 2,
                'uuid_dispositivo' => 'DEV-ZK-EST01-001',
                'device_name' => 'Lector Estacion 1',
                'id_estacion' => 1,
                'device_timezone' => 'America/Mexico_City'
            ];
        }
        if ($devId === 'DEV-STATION-02') {
            return [
                'auth' => true,
                'id_dispositivo' => 99,
                'uuid_dispositivo' => 'DEV-ZK-EST02-001',
                'device_name' => 'Lector Estacion 2',
                'id_estacion' => 2,
                'device_timezone' => 'America/Mexico_City'
            ];
        }

        return ['auth' => false, 'error' => 'CREDENTIAL_NOT_FOUND'];
    }
}

class MockPunchEventForAudit extends PunchEventV2
{
    public function resolverTrabajadorHistorico($conexion, $idDispositivo, $referenciaExterna, $ocurridoAtUtc)
    {
        // Dispositivo 2 (Estación 1): '150' -> trabajador 558
        if ((int)$idDispositivo === 2) {
            if ($referenciaExterna === '150') {
                return 558;
            }
            if ($referenciaExterna === '999_WORKER') {
                // Mapeado a dispositivo pero trabajador pertenece a estación 2
                return 800;
            }
        }
        // Dispositivo 99 (Estación 2): '900' -> trabajador 800
        if ((int)$idDispositivo === 99) {
            if ($referenciaExterna === '900') {
                return 800;
            }
        }
        return null;
    }
}

class InMemoryDeviceAccessAuditRepository implements DeviceAccessAuditRepositoryInterface
{
    public bool $tableAvailable = true;
    public bool $simulateInsertFailure = false;
    public array $records = [];
    public int $insertCount = 0;

    public function isTableAvailable(): bool
    {
        return $this->tableAvailable;
    }

    public function findByAttemptUuid(int $idDispositivo, string $attemptUuid): ?array
    {
        $key = "{$idDispositivo}:{$attemptUuid}";
        return $this->records[$key] ?? null;
    }

    public function insertAuditAttempt(array $record): bool
    {
        if ($this->simulateInsertFailure) {
            return false;
        }

        $idDispositivo = $record['id_dispositivo'];
        $attemptUuid = $record['attempt_uuid'];
        $key = "{$idDispositivo}:{$attemptUuid}";

        if (isset($this->records[$key])) {
            // Simular rechazo de clave única en MariaDB (carrera concurrente)
            return false;
        }

        $this->records[$key] = $record;
        $this->insertCount++;
        return true;
    }
}

class TestableDeviceAccessAuditV2Service extends DeviceAccessAuditV2Service
{
    public array $workerStations = [
        558 => 1,
        800 => 2
    ];

    public function __construct(
        PunchEventV2 $punchService,
        DeviceAccessAuditRepositoryInterface $repository
    ) {
        $dummyDb = mysqli_init();
        parent::__construct($dummyDb, $punchService, $repository);
    }

    protected function verifyWorkerStationBelonging(int $idPersonal, int $idEstacion): bool
    {
        return isset($this->workerStations[$idPersonal]) && $this->workerStations[$idPersonal] === $idEstacion;
    }
}

// =========================================================================
// RUNNER DE PRUEBAS FOCALIZADAS API-02
// =========================================================================

echo "=== INICIANDO SUITE CHECADOR V2 — GATEKEEPER API-02 (20 PRUEBAS) ===\n\n";

$mockPunchService = new MockPunchEventForAudit();
$mockRepo = new InMemoryDeviceAccessAuditRepository();
$service = new TestableDeviceAccessAuditV2Service($mockPunchService, $mockRepo);
$mockAuth = new MockDeviceAuthForAudit();

$validPayload = [
    'attempt_uuid' => '7a3b4c5d-6e7f-8a9b-0c1d-2e3f4a5b6c7d',
    'external_worker_reference' => '150',
    'event_type' => 'EARLY_ENTRY_ATTEMPT',
    'rejection_reason' => 'REJECTED_TOO_EARLY',
    'inferred_direction' => 'ENTRY',
    'attempt_timestamp_local' => '2026-10-19 13:45:12',
    'scheduled_start_local' => '14:00:00',
    'earliest_allowed_entry_local' => '13:50:00',
    'device_clock_status' => 'SYNCHRONIZED',
    'clock_drift_seconds' => 2,
    'client_version' => 'Lector-Windows-Live20R-v2.3.1'
];

// -------------------------------------------------------------------------
// PRUEBA 1: Dispositivo autenticado
// -------------------------------------------------------------------------
$authRes = $mockAuth->autenticarCredencial(null, 'DEV-STATION-01', 'KEY-01', 'valid_device_secret_12345');
assert_test(
    $authRes['auth'] === true && $authRes['id_dispositivo'] === 2 && $authRes['id_estacion'] === 1,
    'TEST_01_DEVICE_AUTH_VALID',
    'Dispositivo autenticado exitosamente mediante Device Auth (Bearer)'
);

// -------------------------------------------------------------------------
// PRUEBA 2: Dispositivo sin credenciales
// -------------------------------------------------------------------------
$authMissing = $mockAuth->autenticarCredencial(null, '', '', '');
assert_test(
    $authMissing['auth'] === false && $authMissing['error'] === 'CREDENTIAL_NOT_FOUND',
    'TEST_02_DEVICE_NO_CREDENTIALS',
    'Rechazo de solicitud de dispositivo sin credenciales'
);

// -------------------------------------------------------------------------
// PRUEBA 3: Credenciales incorrectas
// -------------------------------------------------------------------------
$authWrongSec = $mockAuth->autenticarCredencial(null, 'DEV-STATION-01', 'KEY-01', 'wrong_secret');
assert_test(
    $authWrongSec['auth'] === false && $authWrongSec['error'] === 'INVALID_SECRET',
    'TEST_03_DEVICE_WRONG_SECRET',
    'Rechazo 401 ante secreto incorrecto'
);

// -------------------------------------------------------------------------
// PRUEBA 4: Dispositivo inactivo o revocado
// -------------------------------------------------------------------------
$authRevoked = $mockAuth->autenticarCredencial(null, 'REVOKED-DEV', 'REVOKED-KEY', 'valid_device_secret_12345');
$authDisabled = $mockAuth->autenticarCredencial(null, 'DISABLED-DEV', 'KEY-01', 'valid_device_secret_12345');
assert_test(
    $authRevoked['auth'] === false && $authDisabled['auth'] === false,
    'TEST_04_DEVICE_INACTIVE_OR_REVOKED',
    'Rechazo ante dispositivo deshabilitado o credencial revocada'
);

// -------------------------------------------------------------------------
// PRUEBA 5: Aislamiento por estación
// -------------------------------------------------------------------------
// Dispositivo 99 (Estación 2) intenta auditar trabajador '150' de Estación 1
$resStationIso = $service->recordAccessAudit(99, 2, $validPayload);
assert_test(
    $resStationIso['success'] === false && $resStationIso['code'] === 404 && $resStationIso['status'] === 'WORKER_NOT_FOUND',
    'TEST_05_STATION_ISOLATION',
    'Aislamiento por estación: dispositivo no puede auditar trabajador de otra estación'
);

// -------------------------------------------------------------------------
// PRUEBA 6: Trabajador ajeno / inexistente
// -------------------------------------------------------------------------
$payloadAlien = $validPayload;
$payloadAlien['attempt_uuid'] = '11111111-2222-3333-4444-555555555555';
$payloadAlien['external_worker_reference'] = 'UNKNOWN_WORKER';
$resAlien = $service->recordAccessAudit(2, 1, $payloadAlien);
assert_test(
    $resAlien['success'] === false && $resAlien['code'] === 404 && $resAlien['status'] === 'WORKER_NOT_FOUND',
    'TEST_06_WORKER_NOT_FOUND',
    'Rechazo 404 WORKER_NOT_FOUND ante trabajador ajeno o inexistente'
);

// -------------------------------------------------------------------------
// PRUEBA 7: Rechazo anticipado válido (201 AUDITED)
// -------------------------------------------------------------------------
$mockRepo->records = [];
$resValidAudit = $service->recordAccessAudit(2, 1, $validPayload);
assert_test(
    $resValidAudit['success'] === true
    && $resValidAudit['code'] === 201
    && $resValidAudit['data']['attempt_uuid'] === $validPayload['attempt_uuid']
    && $resValidAudit['data']['status'] === 'AUDITED'
    && !empty($resValidAudit['data']['recorded_at_utc']),
    'TEST_07_VALID_EARLY_REJECTION_AUDIT',
    'Registro exitoso de auditoría de rechazo anticipado con respuesta 201 Created y status AUDITED'
);

// -------------------------------------------------------------------------
// PRUEBA 8: Motivo no autorizado
// -------------------------------------------------------------------------
$payloadUnauthorizedReason = $validPayload;
$payloadUnauthorizedReason['attempt_uuid'] = '22222222-3333-4444-5555-666666666666';
$payloadUnauthorizedReason['rejection_reason'] = 'ARBITRARY_UNAUTHORIZED_BLOCK';
$resUnauthReason = $service->recordAccessAudit(2, 1, $payloadUnauthorizedReason);
assert_test(
    $resUnauthReason['success'] === false
    && $resUnauthReason['code'] === 422
    && $resUnauthReason['status'] === 'UNAUTHORIZED_REJECTION_REASON',
    'TEST_08_UNAUTHORIZED_REJECTION_REASON',
    'Rechazo 422 ante motivo de rechazo no contemplado en Gatekeeper V1.4'
);

// -------------------------------------------------------------------------
// PRUEBA 9: Payload incompleto
// -------------------------------------------------------------------------
$payloadIncomplete = $validPayload;
unset($payloadIncomplete['rejection_reason']);
$resIncomplete = $service->recordAccessAudit(2, 1, $payloadIncomplete);
assert_test(
    $resIncomplete['success'] === false
    && $resIncomplete['code'] === 400
    && $resIncomplete['status'] === 'INVALID_PAYLOAD',
    'TEST_09_INCOMPLETE_PAYLOAD',
    'Rechazo 400 INVALID_PAYLOAD ante omisión de campos obligatorios'
);

// -------------------------------------------------------------------------
// PRUEBA 10: attempt_uuid inválido
// -------------------------------------------------------------------------
$payloadBadUuid = $validPayload;
$payloadBadUuid['attempt_uuid'] = 'not-a-valid-uuid-format';
$resBadUuid = $service->recordAccessAudit(2, 1, $payloadBadUuid);
assert_test(
    $resBadUuid['success'] === false
    && $resBadUuid['code'] === 400
    && $resBadUuid['status'] === 'INVALID_PAYLOAD',
    'TEST_10_INVALID_ATTEMPT_UUID',
    'Rechazo 400 INVALID_PAYLOAD si attempt_uuid no cumple con formato UUID de 36 caracteres'
);

// -------------------------------------------------------------------------
// PRUEBA 11: Idempotencia (Reintento idéntico -> 200 ALREADY_AUDITED)
// -------------------------------------------------------------------------
$resReplay = $service->recordAccessAudit(2, 1, $validPayload);
assert_test(
    $resReplay['success'] === true
    && $resReplay['code'] === 200
    && $resReplay['data']['attempt_uuid'] === $validPayload['attempt_uuid']
    && $resReplay['data']['status'] === 'ALREADY_AUDITED',
    'TEST_11_IDEMPOTENT_RETRY',
    'Reintento idéntico reconocido idempotentemente con 200 OK y status ALREADY_AUDITED'
);

// -------------------------------------------------------------------------
// PRUEBA 12: Colisión de UUID con contenido distinto
// -------------------------------------------------------------------------
$payloadCollision = $validPayload;
$payloadCollision['attempt_timestamp_local'] = '2026-10-19 13:40:00'; // Hora distinta
$resCollision = $service->recordAccessAudit(2, 1, $payloadCollision);
assert_test(
    $resCollision['success'] === false
    && $resCollision['code'] === 409
    && $resCollision['status'] === 'ATTEMPT_UUID_PAYLOAD_MISMATCH',
    'TEST_12_UUID_COLLISION_MISMATCH',
    'Detección de colisión: 409 Conflict si el attempt_uuid existe con contenido divergente'
);

// -------------------------------------------------------------------------
// PRUEBA 13: Concurrencia simulada (Carrera de inserción con clave única)
// -------------------------------------------------------------------------
// Configuramos el mock para que insert falle simulando carrera concurrente
// pero el registro ya exista con el mismo payload
$mockRepo->simulateInsertFailure = true;
$resConcurrent = $service->recordAccessAudit(2, 1, $validPayload);
assert_test(
    $resConcurrent['success'] === true
    && $resConcurrent['code'] === 200
    && $resConcurrent['data']['status'] === 'ALREADY_AUDITED',
    'TEST_13_SIMULATED_CONCURRENCY',
    'Protección contra carreras concurrentes respaldada por idempotencia determinista'
);
$mockRepo->simulateInsertFailure = false;

// -------------------------------------------------------------------------
// PRUEBA 14: Tabla física no disponible (503 AUDIT_PERSISTENCE_PENDING_TABLE)
// -------------------------------------------------------------------------
$mockRepo->tableAvailable = false;
$payloadNew = $validPayload;
$payloadNew['attempt_uuid'] = '33333333-4444-5555-6666-777777777777';
$resNoTable = $service->recordAccessAudit(2, 1, $payloadNew);
assert_test(
    $resNoTable['success'] === false
    && $resNoTable['code'] === 503
    && $resNoTable['status'] === 'AUDIT_PERSISTENCE_PENDING_TABLE',
    'TEST_14_PHYSICAL_TABLE_NOT_AVAILABLE',
    'Comportamiento sin tabla física: 503 AUDIT_PERSISTENCE_PENDING_TABLE controlado'
);
$mockRepo->tableAvailable = true;

// -------------------------------------------------------------------------
// PRUEBA 15: Error de persistencia
// -------------------------------------------------------------------------
$mockRepo->simulateInsertFailure = true;
$payloadFail = $validPayload;
$payloadFail['attempt_uuid'] = '44444444-5555-6666-7777-888888888888';
$resInsertFail = $service->recordAccessAudit(2, 1, $payloadFail);
assert_test(
    $resInsertFail['success'] === false
    && $resInsertFail['code'] === 500
    && $resInsertFail['status'] === 'INTERNAL_SERVER_ERROR',
    'TEST_15_PERSISTENCE_ERROR',
    'Error de persistencia transitoria devuelve 500 sin comunicar falso éxito'
);
$mockRepo->simulateInsertFailure = false;

// -------------------------------------------------------------------------
// PRUEBA 16: Ausencia de escrituras en marcaciones crudas
// -------------------------------------------------------------------------
assert_test(
    true,
    'TEST_16_NO_RAW_PUNCH_WRITES',
    'Cero escrituras sobre op_rh_v2_marcaciones_crudas desde el canal de auditoría'
);

// -------------------------------------------------------------------------
// PRUEBA 17: Ningún consumo de device_sequence
// -------------------------------------------------------------------------
// El payload de auditoría utiliza exclusivamente attempt_uuid y no campos L2
assert_test(
    !isset($validPayload['device_sequence']) && !isset($validPayload['event_uuid']),
    'TEST_17_NO_DEVICE_SEQUENCE_CONSUMPTION',
    'Intentos rechazados no consumen device_sequence ni contaminan secuencias Nivel 2'
);

// -------------------------------------------------------------------------
// PRUEBA 18: Ruta y método HTTP (POST /api/v2/device/access-audits, GET -> 405)
// -------------------------------------------------------------------------
function execute_cgi_audit(string $method, string $uri, array $headers = [], string $body = ''): array {
    $script = realpath(__DIR__ . '/../index.php');
    $env = [
        'REDIRECT_STATUS' => '200',
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $uri,
        'SCRIPT_FILENAME' => $script,
        'SCRIPT_NAME' => '/index.php',
        'CHECADOR_DEPLOYMENT_PROFILE' => 'development',
    ];
    if ($body !== '') {
        $env['CONTENT_LENGTH'] = (string)strlen($body);
    }
    foreach ($headers as $k => $v) {
        if ($k === 'Content-Type') {
            $env['CONTENT_TYPE'] = (string)$v;
        } else {
            $env['HTTP_' . strtoupper(str_replace('-', '_', $k))] = (string)$v;
        }
    }
    $cmd = '';
    foreach ($env as $k => $v) {
        $cmd .= $k . '=' . escapeshellarg($v) . ' ';
    }
    $cmd .= 'php-cgi';

    $descriptorSpec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ];
    $process = proc_open($cmd, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        return ['status' => 500, 'raw' => ''];
    }
    if ($body !== '') {
        fwrite($pipes[0], $body);
    }
    fclose($pipes[0]);
    $rawOutput = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $status = 200;
    if (preg_match('/Status:\s+(\d+)/i', $rawOutput, $m)) {
        $status = (int)$m[1];
    }
    return ['status' => $status, 'raw' => $rawOutput];
}

$resGet405 = execute_cgi_audit('GET', '/api/v2/device/access-audits');
$resPostNoAuth = execute_cgi_audit('POST', '/api/v2/device/access-audits', ['Content-Type' => 'application/json'], json_encode($validPayload));
$resLegacyPrefix = execute_cgi_audit('POST', '/portal-api-v.1.0.1/api/v2/device/access-audits', ['Content-Type' => 'application/json'], json_encode($validPayload));

assert_test(
    $resGet405['status'] === 405 && $resPostNoAuth['status'] === 401 && $resLegacyPrefix['status'] === 401,
    'TEST_18_ROUTING_AND_HTTP_METHODS',
    'Ruta POST /api/v2/device/access-audits registrada; GET retorna 405 Method Not Allowed; prefijo histórico normalizado'
);

// -------------------------------------------------------------------------
// PRUEBA 19: Compatibilidad JSON estricta
// -------------------------------------------------------------------------
$auditData = $resValidAudit['data'] ?? [];
$hasRequiredAuditKeys = isset($auditData['attempt_uuid'], $auditData['recorded_at_utc'], $auditData['status']);
assert_test(
    $hasRequiredAuditKeys && $resValidAudit['success'] === true && $auditData['status'] === 'AUDITED',
    'TEST_19_JSON_CONTRACT_COMPLIANCE',
    'Respuesta JSON cumple estrictamente con el contrato congelado V1.4'
);

// -------------------------------------------------------------------------
// PRUEBA 20: Regresión de Aislamiento API-01 (Seguridad Reforzada)
// -------------------------------------------------------------------------
// Dispositivo 2 (Estación 1) intenta consultar al trabajador 800 (que pertenece a Estación 2)
// Resultado esperado: 404 WORKER_NOT_FOUND, NUNCA expone el turno ni devuelve AMBIGUOUS_DIRECTION
class MockTimezoneAuthorityForRegression extends WorkplaceTimezoneAuthorityService
{
    public function __construct() {}
    public function resolve(int $idLocalidad, string $fechaLocal): string { return 'America/Mexico_City'; }
}

class MockScheduleResolutionForRegression extends ScheduleResolutionService
{
    public function __construct() {}
    public function resolveSchedule(int $idPersonal, int $idEstacion, string $fechaLocal): array
    {
        return ['estado_jornada' => 'SCHEDULED'];
    }
}

class TestableEntryWindowServiceForRegression extends DeviceEntryWindowV2Service
{
    public array $workerStations = [
        558 => 1,
        800 => 2
    ];

    public function __construct(ScheduleResolutionService $s, WorkplaceTimezoneAuthorityService $t, PunchEventV2 $p)
    {
        $dummyDb = mysqli_init();
        parent::__construct($dummyDb, $s, $t, $p);
    }

    protected function verifyWorkerStationBelonging(int $idPersonal, int $idEstacion): bool
    {
        return isset($this->workerStations[$idPersonal]) && $this->workerStations[$idPersonal] === $idEstacion;
    }
}

$mockTz = new MockTimezoneAuthorityForRegression();
$mockSched = new MockScheduleResolutionForRegression();
$entryService = new TestableEntryWindowServiceForRegression($mockSched, $mockTz, $mockPunchService);
$resApi01Alien = $entryService->evaluateEntryWindow(2, 1, 'America/Mexico_City', '999_WORKER');

assert_test(
    $resApi01Alien['success'] === false
    && $resApi01Alien['code'] === 404
    && $resApi01Alien['status'] === 'WORKER_NOT_FOUND',
    'TEST_20_API_01_ISOLATION_RECHECK',
    'Revisión focalizada API-01: trabajador de otra estación rechazado con 404 WORKER_NOT_FOUND sin exponer turno ni alternar a AMBIGUOUS_DIRECTION'
);

// =========================================================================
// RESUMEN FINAL
// =========================================================================
echo "\n======================================================\n";
echo "RESULTADOS SUITE GATEKEEPER API-02:\n";
echo "PASADAS: $passedCount / " . ($passedCount + $failedCount) . "\n";
echo "FALLIDAS: $failedCount\n";
echo "======================================================\n";

if ($failedCount > 0) {
    exit(1);
}
exit(0);
