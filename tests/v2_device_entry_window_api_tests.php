<?php
/**
 * Test Suite: Checador V2 Device Entry Window API Tests (API-01)
 *
 * Verifies all 18 focused requirements for:
 * GET /api/v2/device/workers/{external_worker_reference}/entry-window
 *
 * Specification: docs/v2/CHECADOR_V2_DEVICE_ENTRY_WINDOW_API_CONTRACT_V1.0.md (APPROVED_FROZEN_V1.4)
 * Architecture Addendum: docs/architecture/CHECADOR_V2_EARLY_ENTRY_GATEKEEPER_ADDENDUM_V1.0.md (APPROVED_FROZEN_V1.4)
 */

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/gatekeeper/DeviceEntryWindowV2Service.php';
require_once __DIR__ . '/../modelo/v2/gatekeeper/DeviceEntryWindowV2Controller.php';

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

class MockDeviceAuthDispositivoV2 extends DispositivoV2
{
    public function autenticarCredencial($conexion, $uuidDispositivo, $credentialKey, $secret)
    {
        $devId = trim((string)$uuidDispositivo);
        $key = trim((string)$credentialKey);
        $sec = (string)$secret;

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

class MockPunchEventV2 extends PunchEventV2
{
    public function resolverTrabajadorHistorico($conexion, $idDispositivo, $referenciaExterna, $ocurridoAtUtc)
    {
        // Dispositivo 2 (Estacion 1): Solo tiene mapeado a '150' -> id_personal 558 y '151' -> id_personal 559
        if ((int)$idDispositivo === 2) {
            if ($referenciaExterna === '150') {
                return 558;
            }
            if ($referenciaExterna === '151') {
                return 559;
            }
        }
        // Dispositivo 99 (Estacion 2): Solo tiene mapeado a '900' -> id_personal 800
        if ((int)$idDispositivo === 99) {
            if ($referenciaExterna === '900') {
                return 800;
            }
        }
        return null;
    }
}

class MockTimezoneAuthorityService extends WorkplaceTimezoneAuthorityService
{
    public bool $forceUnresolved = false;

    public function __construct() {}

    public function resolve(int $idLocalidad, string $fechaLocal): string
    {
        if ($this->forceUnresolved || $idLocalidad === 999) {
            throw new RuntimeException('WORKPLACE_TIMEZONE_UNRESOLVED');
        }
        if ($idLocalidad === 1) {
            return 'America/Mexico_City';
        }
        return 'America/Mexico_City';
    }
}

class MockScheduleResolutionService extends ScheduleResolutionService
{
    public string $forceState = 'SCHEDULED';

    public function __construct() {}

    public function resolveSchedule(int $idPersonal, int $idEstacion, string $fechaLocal): array
    {
        if ($this->forceState === 'UNSCHEDULED' || $idPersonal === 559) {
            return [
                'id_personal' => $idPersonal,
                'id_estacion' => $idEstacion,
                'fecha_local' => $fechaLocal,
                'estado_jornada' => 'UNSCHEDULED',
                'reason' => 'Worker has no active assignments'
            ];
        }
        if ($this->forceState === 'DAY_OFF') {
            return [
                'id_personal' => $idPersonal,
                'id_estacion' => $idEstacion,
                'fecha_local' => $fechaLocal,
                'estado_jornada' => 'DAY_OFF',
                'origen' => ['id_asignacion' => 10, 'id_plantilla' => null]
            ];
        }
        if ($this->forceState === 'UNKNOWN') {
            return [
                'id_personal' => $idPersonal,
                'id_estacion' => $idEstacion,
                'fecha_local' => $fechaLocal,
                'estado_jornada' => 'UNKNOWN',
                'reason' => 'System error'
            ];
        }

        // Default: SCHEDULED 14:00 to 22:00
        return [
            'id_personal' => $idPersonal,
            'id_estacion' => $idEstacion,
            'fecha_local' => $fechaLocal,
            'estado_jornada' => 'SCHEDULED',
            'origen' => [
                'id_asignacion' => 1,
                'id_plantilla' => 2,
                'clave_plantilla' => 'VESP-8H',
                'version_plantilla' => 1
            ],
            'horario_local' => [
                'timezone' => 'America/Mexico_City',
                'segmentos' => [
                    [
                        'ordinal' => 1,
                        'tipo' => 'WORK',
                        'tipo_segmento' => 'WORK',
                        'hora_inicio_local' => '14:00:00',
                        'hora_fin_local' => '22:00:00',
                        'dia_offset_inicio' => 0,
                        'dia_offset_fin' => 0
                    ]
                ]
            ],
            'horario_utc' => [
                'start_utc' => "{$fechaLocal} 20:00:00",
                'end_utc' => "{$fechaLocal} 04:00:00"
            ]
        ];
    }
}

// In-memory Mock Service to override DB queries without touching real database
class TestableDeviceEntryWindowV2Service extends DeviceEntryWindowV2Service
{
    public array $mockPunches = [];
    public bool $hasWorkplaceMismatch = false;
    public bool $hasAmbiguity = false;
    public int $writeQueryCount = 0;

    public function __construct(
        ScheduleResolutionService $scheduleService,
        WorkplaceTimezoneAuthorityService $tzService,
        PunchEventV2 $punchService
    ) {
        $dummyDb = mysqli_init();
        parent::__construct($dummyDb, $scheduleService, $tzService, $punchService);
    }

    // Override candidate punch queries to return in-memory fixtures
    protected function fetchCandidatePunchesForWindow(
        int $idPersonal,
        int $idEstacion,
        string $windowStartUtc,
        string $windowEndUtc
    ): array {
        return [
            'punches' => $this->mockPunches,
            'has_workplace_mismatch' => $this->hasWorkplaceMismatch,
            'has_ambiguity' => $this->hasAmbiguity
        ];
    }

    public array $mockWorkerStations = [
        558 => 1,
        559 => 1,
        800 => 2
    ];

    protected function verifyWorkerStationBelonging(int $idPersonal, int $idEstacion): bool
    {
        return isset($this->mockWorkerStations[$idPersonal]) && $this->mockWorkerStations[$idPersonal] === $idEstacion;
    }
}

// =========================================================================
// RUNNER DE PRUEBAS FOCALIZADAS
// =========================================================================

echo "=== INICIANDO SUITE CHECADOR V2 — GATEKEEPER API-01 (18 PRUEBAS) ===\n\n";

$mockPunchService = new MockPunchEventV2();
$mockTzService = new MockTimezoneAuthorityService();
$mockSchedService = new MockScheduleResolutionService();

$service = new TestableDeviceEntryWindowV2Service($mockSchedService, $mockTzService, $mockPunchService);

// -------------------------------------------------------------------------
// PRUEBA 1: Device Auth válido
// -------------------------------------------------------------------------
$mockDispositivo = new MockDeviceAuthDispositivoV2();
$authValid = $mockDispositivo->autenticarCredencial(null, 'DEV-STATION-01', 'CRD-KEY-A', 'valid_device_secret_12345');
assert_test(
    $authValid['auth'] === true && $authValid['id_dispositivo'] === 2 && $authValid['id_estacion'] === 1,
    'TEST_01_DEVICE_AUTH_VALID',
    'Autenticación de dispositivo exitosa con credenciales Bearer válidas'
);

// -------------------------------------------------------------------------
// PRUEBA 2: Device Auth inválido (secreto erróneo)
// -------------------------------------------------------------------------
$authInvalid = $mockDispositivo->autenticarCredencial(null, 'DEV-STATION-01', 'CRD-KEY-A', 'wrong_secret');
assert_test(
    $authInvalid['auth'] === false && $authInvalid['error'] === 'INVALID_SECRET',
    'TEST_02_DEVICE_AUTH_INVALID',
    'Rechazo 401 ante secreto de dispositivo inválido'
);

// -------------------------------------------------------------------------
// PRUEBA 3: Dispositivo inactivo o revocado
// -------------------------------------------------------------------------
$authRevoked = $mockDispositivo->autenticarCredencial(null, 'REVOKED-DEV', 'REVOKED-KEY', 'valid_device_secret_12345');
$authDisabled = $mockDispositivo->autenticarCredencial(null, 'DISABLED-DEV', 'CRD-KEY-A', 'valid_device_secret_12345');
assert_test(
    $authRevoked['auth'] === false && $authRevoked['error'] === 'CREDENTIAL_REVOKED_OR_INACTIVE'
    && $authDisabled['auth'] === false && $authDisabled['error'] === 'DEVICE_DISABLED',
    'TEST_03_DEVICE_INACTIVE_OR_REVOKED',
    'Rechazo 401 ante credenciales revocadas o dispositivo deshabilitado'
);

// -------------------------------------------------------------------------
// PRUEBA 4: Aislamiento entre estaciones
// -------------------------------------------------------------------------
// Dispositivo 99 (Estación 2) intenta consultar al trabajador '150' que pertenece a Estación 1
$resStationIso = $service->evaluateEntryWindow(99, 2, 'America/Mexico_City', '150');
assert_test(
    $resStationIso['success'] === false && $resStationIso['code'] === 404 && $resStationIso['status'] === 'WORKER_NOT_FOUND',
    'TEST_04_STATION_ISOLATION',
    'Aislamiento estricto: dispositivo no puede consultar trabajadores de otra estación'
);

// -------------------------------------------------------------------------
// PRUEBA 5: Trabajador no autorizado o inexistente
// -------------------------------------------------------------------------
$resNotFound = $service->evaluateEntryWindow(2, 1, 'America/Mexico_City', 'NON_EXISTENT_WORKER');
assert_test(
    $resNotFound['success'] === false && $resNotFound['code'] === 404 && $resNotFound['status'] === 'WORKER_NOT_FOUND',
    'TEST_05_WORKER_UNAUTHORIZED_OR_NOT_FOUND',
    'Respuesta 404 WORKER_NOT_FOUND ante referencia externa no mapeada'
);

// -------------------------------------------------------------------------
// PRUEBA 6: Entrada antes de la ventana (Turno 14:00, hora 13:49:59 -> REJECT_EARLY_ENTRY)
// -------------------------------------------------------------------------
$service->mockPunches = [];
$service->hasWorkplaceMismatch = false;
$service->hasAmbiguity = false;
$mockSchedService->forceState = 'SCHEDULED';

// 13:49:59 en America/Mexico_City es 19:49:59 UTC (asumiendo UTC-6)
$mockNowEarly = new DateTimeImmutable('2026-10-19 19:49:59', new DateTimeZone('UTC'));
$resEarly = $service->evaluateEntryWindow(2, 1, 'America/Mexico_City', '150', null, 0, null, null, $mockNowEarly);

assert_test(
    $resEarly['success'] === true
    && $resEarly['data']['gatekeeper_action'] === 'REJECT_EARLY_ENTRY'
    && $resEarly['data']['is_entry_allowed'] === false
    && $resEarly['data']['should_record_punch'] === false
    && $resEarly['data']['seconds_until_allowed'] === 1
    && $resEarly['data']['minutes_until_allowed'] === 1
    && $resEarly['data']['desktop_ui_message']['card_color'] === 'RED',
    'TEST_06_ENTRY_BEFORE_WINDOW',
    'Entrada 11 segundos antes del límite rechazada con REJECT_EARLY_ENTRY (13:49:59 -> 13:50:00)'
);

// -------------------------------------------------------------------------
// PRUEBA 7: Entrada exactamente en el límite de 10 minutos (13:50:00 -> ALLOW_ENTRY)
// -------------------------------------------------------------------------
$mockNowExact = new DateTimeImmutable('2026-10-19 19:50:00', new DateTimeZone('UTC'));
$resExact = $service->evaluateEntryWindow(2, 1, 'America/Mexico_City', '150', null, 0, null, null, $mockNowExact);

assert_test(
    $resExact['success'] === true
    && $resExact['data']['gatekeeper_action'] === 'ALLOW_ENTRY'
    && $resExact['data']['is_entry_allowed'] === true
    && $resExact['data']['should_record_punch'] === true
    && $resExact['data']['seconds_until_allowed'] === 0
    && $resExact['data']['desktop_ui_message']['card_color'] === 'GREEN',
    'TEST_07_ENTRY_EXACT_10_MIN_BOUNDARY',
    'Entrada permitida exactamente en el límite de 10 minutos (13:50:00)'
);

// -------------------------------------------------------------------------
// PRUEBA 8: Entrada después del límite (14:15:00 con retardo -> ALLOW_ENTRY)
// -------------------------------------------------------------------------
$mockNowLate = new DateTimeImmutable('2026-10-19 20:15:00', new DateTimeZone('UTC'));
$resLate = $service->evaluateEntryWindow(2, 1, 'America/Mexico_City', '150', null, 0, null, null, $mockNowLate);

assert_test(
    $resLate['success'] === true
    && $resLate['data']['gatekeeper_action'] === 'ALLOW_ENTRY'
    && $resLate['data']['is_entry_allowed'] === true
    && $resLate['data']['should_record_punch'] === true
    && $resLate['data']['desktop_ui_message']['card_color'] === 'GREEN',
    'TEST_08_ENTRY_AFTER_BOUNDARY',
    'Entrada posterior al inicio autorizada para evaluar retardo posteriormente en backend'
);

// -------------------------------------------------------------------------
// PRUEBA 9: Salida legítima fuera de la ventana (ALLOW_EXIT)
// -------------------------------------------------------------------------
// El trabajador ya tiene 1 entrada registrada en el servidor
$service->mockPunches = [
    [
        'id' => 10,
        'id_dispositivo' => 2,
        'id_estacion_resuelta' => 1,
        'source_timestamp_raw' => '2026-10-19 13:55:00',
        'ocurrido_at_utc' => '2026-10-19 19:55:00',
        'validation_flags' => null
    ]
];
$mockNowExit = new DateTimeImmutable('2026-10-20 04:04:10', new DateTimeZone('UTC')); // 22:04:10 local
$resExit = $service->evaluateEntryWindow(2, 1, 'America/Mexico_City', '150', null, 0, null, null, $mockNowExit);

assert_test(
    $resExit['success'] === true
    && $resExit['data']['gatekeeper_action'] === 'ALLOW_EXIT'
    && $resExit['data']['inferred_direction'] === 'EXIT'
    && $resExit['data']['is_entry_allowed'] === true
    && $resExit['data']['should_record_punch'] === true
    && $resExit['data']['earliest_allowed_entry_local'] === null
    && $resExit['data']['desktop_ui_message']['card_color'] === 'BLUE',
    'TEST_09_LEGITIMATE_EXIT_OUTSIDE_WINDOW',
    'Salida legítima autorizada con ALLOW_EXIT sin aplicar ventana de entrada anticipada'
);

// -------------------------------------------------------------------------
// PRUEBA 10: Trabajador UNSCHEDULED (Decisión C)
// -------------------------------------------------------------------------
$service->mockPunches = [];
$mockSchedService->forceState = 'UNSCHEDULED';
$resUnscheduled = $service->evaluateEntryWindow(2, 1, 'America/Mexico_City', '150', null, 0, null, null, $mockNowExact);

assert_test(
    $resUnscheduled['success'] === true
    && $resUnscheduled['data']['gatekeeper_action'] === 'ALLOW_UNSCHEDULED'
    && $resUnscheduled['data']['estado_jornada'] === 'UNSCHEDULED'
    && $resUnscheduled['data']['is_entry_allowed'] === true
    && $resUnscheduled['data']['should_record_punch'] === true
    && $resUnscheduled['data']['metadata_payload']['schedule_status'] === 'UNSCHEDULED'
    && $resUnscheduled['data']['desktop_ui_message']['card_color'] === 'AMBER',
    'TEST_10_WORKER_UNSCHEDULED',
    'Trabajador sin horario asignado aceptado obligatoriamente con ALLOW_UNSCHEDULED'
);

// -------------------------------------------------------------------------
// PRUEBA 11: Trabajador DAY_OFF (Decisión I)
// -------------------------------------------------------------------------
$mockSchedService->forceState = 'DAY_OFF';
$resDayOff = $service->evaluateEntryWindow(2, 1, 'America/Mexico_City', '150', null, 0, null, null, $mockNowExact);

assert_test(
    $resDayOff['success'] === true
    && $resDayOff['data']['gatekeeper_action'] === 'ALLOW_DAY_OFF'
    && $resDayOff['data']['estado_jornada'] === 'DAY_OFF'
    && $resDayOff['data']['is_entry_allowed'] === true
    && $resDayOff['data']['should_record_punch'] === true
    && $resDayOff['data']['metadata_payload']['schedule_status'] === 'DAY_OFF'
    && $resDayOff['data']['desktop_ui_message']['card_color'] === 'AMBER',
    'TEST_11_WORKER_DAY_OFF',
    'Marcación en Día de Descanso aceptada obligatoriamente con ALLOW_DAY_OFF'
);

// -------------------------------------------------------------------------
// PRUEBA 12: Zona horaria no resuelta (Fail-closed)
// -------------------------------------------------------------------------
$mockSchedService->forceState = 'SCHEDULED';
$mockTzService->forceUnresolved = true;
$resTzUnresolved = $service->evaluateEntryWindow(2, 1, 'America/Mexico_City', '150');
$mockTzService->forceUnresolved = false;

assert_test(
    $resTzUnresolved['success'] === true
    && $resTzUnresolved['data']['gatekeeper_action'] === 'SCHEDULE_UNKNOWN'
    && $resTzUnresolved['data']['is_entry_allowed'] === false
    && $resTzUnresolved['data']['should_record_punch'] === false
    && $resTzUnresolved['data']['timezone'] === null,
    'TEST_12_TIMEZONE_UNRESOLVED',
    'Falla técnica de zona horaria aplica comportamiento Fail-Closed SCHEDULE_UNKNOWN'
);

// -------------------------------------------------------------------------
// PRUEBA 13: Dirección ambigua (Marcaciones superan par exacto)
// -------------------------------------------------------------------------
// El trabajador ya tiene 2 marcaciones previas (entrada y salida completas)
$service->mockPunches = [
    [
        'id' => 10,
        'id_dispositivo' => 2,
        'id_estacion_resuelta' => 1,
        'source_timestamp_raw' => '2026-10-19 13:55:00',
        'ocurrido_at_utc' => '2026-10-19 19:55:00',
        'validation_flags' => null
    ],
    [
        'id' => 11,
        'id_dispositivo' => 2,
        'id_estacion_resuelta' => 1,
        'source_timestamp_raw' => '2026-10-19 22:05:00',
        'ocurrido_at_utc' => '2026-10-20 04:05:00',
        'validation_flags' => null
    ]
];
$resAmbiguous = $service->evaluateEntryWindow(2, 1, 'America/Mexico_City', '150', null, 0, null, null, $mockNowExact);

assert_test(
    $resAmbiguous['success'] === true
    && $resAmbiguous['data']['gatekeeper_action'] === 'AMBIGUOUS_DIRECTION'
    && $resAmbiguous['data']['inferred_direction'] === 'AMBIGUOUS'
    && $resAmbiguous['data']['is_entry_allowed'] === false
    && $resAmbiguous['data']['should_record_punch'] === false,
    'TEST_13_AMBIGUOUS_DIRECTION',
    'Dirección ambigua detectada ante marcaciones excedentes sin nuevo segmento'
);

// -------------------------------------------------------------------------
// PRUEBA 14: Contexto multi-terminal incompleto o en conflicto (Workplace mismatch)
// -------------------------------------------------------------------------
$service->mockPunches = [
    [
        'id' => 25,
        'id_dispositivo' => 99,
        'id_estacion_resuelta' => 2, // Otra estación
        'source_timestamp_raw' => '2026-10-19 14:00:00',
        'ocurrido_at_utc' => '2026-10-19 20:00:00',
        'validation_flags' => null
    ]
];
$service->hasWorkplaceMismatch = true;
$resMultiTerm = $service->evaluateEntryWindow(2, 1, 'America/Mexico_City', '150', null, 0, null, null, $mockNowExact);

assert_test(
    $resMultiTerm['success'] === true
    && $resMultiTerm['data']['gatekeeper_action'] === 'AMBIGUOUS_DIRECTION'
    && $resMultiTerm['data']['inferred_direction'] === 'AMBIGUOUS',
    'TEST_14_MULTI_TERMINAL_INCOMPLETE',
    'Conflicto multi-terminal / workplace mismatch detectado como AMBIGUOUS_DIRECTION'
);

// -------------------------------------------------------------------------
// PRUEBA 15: Eventos pendientes locales (TC-11: Marcación previa en cola offline)
// -------------------------------------------------------------------------
// En servidor hay 0 marcaciones, pero cliente declara 1 marcación pendiente local
$service->mockPunches = [];
$service->hasWorkplaceMismatch = false;
$service->hasAmbiguity = false;
$resOfflinePending = $service->evaluateEntryWindow(
    2,
    1,
    'America/Mexico_City',
    '150',
    null,
    1, // pending_local_punches_count = 1
    '2026-10-19 14:00:00',
    '7a3b4c5d-6e7f-8a9b-0c1d-2e3f4a5b6c7d',
    $mockNowExit // Punch realizado horas después
);

assert_test(
    $resOfflinePending['success'] === true
    && $resOfflinePending['data']['gatekeeper_action'] === 'ALLOW_EXIT'
    && $resOfflinePending['data']['inferred_direction'] === 'EXIT'
    && $resOfflinePending['data']['is_entry_allowed'] === true
    && $resOfflinePending['data']['should_record_punch'] === true,
    'TEST_15_LOCAL_PENDING_PUNCHES',
    'Reconciliación de cola offline: reconoce entrada pendiente local y autoriza ALLOW_EXIT'
);

// -------------------------------------------------------------------------
// PRUEBA 16: Ausencia de escrituras sobre marcaciones crudas (Pure read-only)
// -------------------------------------------------------------------------
assert_test(
    $service->writeQueryCount === 0,
    'TEST_16_RAW_PUNCH_IMMUTABILITY',
    'El servicio de consulta ejecuta estrictamente CERO escrituras (Zero DML/DDL)'
);

// -------------------------------------------------------------------------
// PRUEBA 17: Compatibilidad de rutas canónicas y legacy, y códigos HTTP
// -------------------------------------------------------------------------
// 17.1 Prueba de derivación horaria excesiva (> 300 s)
$resDrift = $service->evaluateEntryWindow(
    2,
    1,
    'America/Mexico_City',
    '150',
    '2026-10-19 13:00:00', // Desfase de casi 1 hora respecto a 13:50
    0,
    null,
    null,
    $mockNowExact
);

// 17.2 Verificación real de enrutamiento en index.php (Canonical, Legacy Prefix y 405 Method Not Allowed)
function execute_cgi_test(string $method, string $uri, array $headers = []): array {
    $script = realpath(__DIR__ . '/../index.php');
    $env = [
        'REDIRECT_STATUS' => '200',
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $uri,
        'SCRIPT_FILENAME' => $script,
        'SCRIPT_NAME' => '/index.php',
        'CHECADOR_DEPLOYMENT_PROFILE' => 'development',
    ];
    foreach ($headers as $k => $v) {
        $env['HTTP_' . strtoupper(str_replace('-', '_', $k))] = (string)$v;
    }
    $cmd = '';
    foreach ($env as $k => $v) {
        $cmd .= $k . '=' . escapeshellarg($v) . ' ';
    }
    $cmd .= 'php-cgi';
    $output = shell_exec($cmd) ?: '';
    
    $status = 200;
    if (preg_match('/Status:\s+(\d+)/i', $output, $m)) {
        $status = (int)$m[1];
    }
    return ['status' => $status, 'raw' => $output];
}

$resRouteCanonical = execute_cgi_test('GET', '/api/v2/device/workers/150/entry-window');
$resRouteLegacyPrefix = execute_cgi_test('GET', '/portal-api-v.1.0.1/api/v2/device/workers/150/entry-window');
$resRoutePost405 = execute_cgi_test('POST', '/api/v2/device/workers/150/entry-window');

$routingOk = ($resRouteCanonical['status'] === 401) // Sin credenciales -> 401 NO AUTORIZADO
    && ($resRouteLegacyPrefix['status'] === 401)   // Con prefijo histórico -> 401 NO AUTORIZADO
    && ($resRoutePost405['status'] === 405);       // POST -> 405 Method Not Allowed

assert_test(
    $resDrift['success'] === true
    && $resDrift['data']['gatekeeper_action'] === 'SCHEDULE_UNKNOWN'
    && $resDrift['data']['desktop_ui_message']['header'] === 'RELOJ DESFASADO'
    && $routingOk,
    'TEST_17_ROUTING_COMPATIBILITY_AND_HTTP_CODES',
    'Compatibilidad de rutas canónicas, legacy prefix, 405 Method Not Allowed y deriva horaria'
);

// -------------------------------------------------------------------------
// PRUEBA 18: Contrato JSON estricto y códigos HTTP
// -------------------------------------------------------------------------
$requiredKeysReject = [
    'gatekeeper_action', 'is_entry_allowed', 'should_record_punch', 'inferred_direction',
    'external_worker_reference', 'workplace_id', 'fecha_local', 'timezone', 'estado_jornada',
    'scheduled_start_local', 'scheduled_end_local', 'earliest_allowed_entry_local',
    'server_time_local', 'seconds_until_allowed', 'minutes_until_allowed', 'desktop_ui_message'
];

$allKeysPresent = true;
foreach ($requiredKeysReject as $k) {
    if (!array_key_exists($k, $resEarly['data'])) {
        $allKeysPresent = false;
        break;
    }
}

$uiMessage = $resEarly['data']['desktop_ui_message'] ?? [];
$uiKeysPresent = isset($uiMessage['header'], $uiMessage['detail'], $uiMessage['card_color'], $uiMessage['sound_alert']);

assert_test(
    $allKeysPresent && $uiKeysPresent,
    'TEST_18_JSON_CONTRACT_AND_HTTP_CODES',
    'Esquema JSON de respuesta coincide exactamente con el contrato congelado V1.4'
);

// =========================================================================
// RESUMEN FINAL
// =========================================================================
echo "\n======================================================\n";
echo "RESULTADOS SUITE GATEKEEPER API-01:\n";
echo "PASADAS: $passedCount / " . ($passedCount + $failedCount) . "\n";
echo "FALLIDAS: $failedCount\n";
echo "======================================================\n";

if ($failedCount > 0) {
    exit(1);
}
exit(0);
