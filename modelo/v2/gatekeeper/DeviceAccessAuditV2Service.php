<?php
/**
 * Checador V2 — Device Access Audit Domain Service (API-02)
 *
 * Implements:
 * POST /api/v2/device/access-audits
 *
 * Specification: docs/v2/CHECADOR_V2_DEVICE_ENTRY_WINDOW_API_CONTRACT_V1.0.md (APPROVED_FROZEN_V1.4)
 * Architecture Addendum: docs/architecture/CHECADOR_V2_EARLY_ENTRY_GATEKEEPER_ADDENDUM_V1.0.md (APPROVED_FROZEN_V1.4)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';
require_once __DIR__ . '/../PunchEventV2.php';

interface DeviceAccessAuditRepositoryInterface
{
    public function isTableAvailable(): bool;
    public function findByAttemptUuid(int $idDispositivo, string $attemptUuid): ?array;
    public function insertAuditAttempt(array $record): bool;
}

class MySqlDeviceAccessAuditRepository implements DeviceAccessAuditRepositoryInterface
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function isTableAvailable(): bool
    {
        $res = $this->db->query("SHOW TABLES LIKE 'op_rh_v2_marcaciones_intentos_auditoria'");
        return ($res && $res->num_rows > 0);
    }

    public function findByAttemptUuid(int $idDispositivo, string $attemptUuid): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, attempt_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
                    id_personal_resuelto, event_type, rejection_reason, inferred_direction,
                    attempt_timestamp_local, scheduled_start_local, earliest_allowed_entry_local,
                    device_clock_status, clock_drift_seconds, client_version, payload_sha256,
                    recibido_at_utc
             FROM op_rh_v2_marcaciones_intentos_auditoria
             WHERE id_dispositivo = ? AND attempt_uuid = ? LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('is', $idDispositivo, $attemptUuid);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    public function insertAuditAttempt(array $record): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO op_rh_v2_marcaciones_intentos_auditoria
                (attempt_uuid, id_dispositivo, id_estacion_resuelta, referencia_externa_personal,
                 id_personal_resuelto, event_type, rejection_reason, inferred_direction,
                 attempt_timestamp_local, scheduled_start_local, earliest_allowed_entry_local,
                 device_clock_status, clock_drift_seconds, client_version, payload_sha256,
                 payload_json, recibido_at_utc)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'siisisssssssissss',
            $record['attempt_uuid'],
            $record['id_dispositivo'],
            $record['id_estacion_resuelta'],
            $record['referencia_externa_personal'],
            $record['id_personal_resuelto'],
            $record['event_type'],
            $record['rejection_reason'],
            $record['inferred_direction'],
            $record['attempt_timestamp_local'],
            $record['scheduled_start_local'],
            $record['earliest_allowed_entry_local'],
            $record['device_clock_status'],
            $record['clock_drift_seconds'],
            $record['client_version'],
            $record['payload_sha256'],
            $record['payload_json'],
            $record['recibido_at_utc']
        );

        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

class DeviceAccessAuditV2Service
{
    private mysqli $db;
    private PunchEventV2 $punchEventService;
    private DeviceAccessAuditRepositoryInterface $repository;

    public const ALLOWED_REJECTION_REASONS = [
        'REJECTED_TOO_EARLY',
        'AMBIGUOUS_DIRECTION',
        'DEBOUNCE_DISCARD',
        'CLOCK_DRIFT_EXCESSIVE',
        'SCHEDULE_UNVERIFIABLE'
    ];

    public const ALLOWED_EVENT_TYPES = [
        'EARLY_ENTRY_ATTEMPT',
        'AMBIGUOUS_DIRECTION_ATTEMPT',
        'DEBOUNCE_DISCARD_ATTEMPT',
        'CLOCK_DRIFT_ATTEMPT',
        'CONTINGENCY_RECORD_ATTEMPT'
    ];

    public function __construct(
        ?mysqli $db = null,
        ?PunchEventV2 $punchEventService = null,
        ?DeviceAccessAuditRepositoryInterface $repository = null
    ) {
        if ($db !== null) {
            $this->db = $db;
        } else {
            $bdObj = new BD();
            $this->db = $bdObj->conectarBD();
        }

        $this->punchEventService = $punchEventService ?? new PunchEventV2();
        $this->repository = $repository ?? new MySqlDeviceAccessAuditRepository($this->db);
    }

    /**
     * Procesa y valida el registro de auditoría de intentos rechazados.
     */
    public function recordAccessAudit(
        int $idDispositivo,
        int $idEstacion,
        array $body,
        ?DateTimeImmutable $mockServerNow = null
    ): array {
        $serverNowUtc = $mockServerNow ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $recibidoAtUtc = $serverNowUtc->format('Y-m-d H:i:s');

        // 1. Validar campos obligatorios en el payload
        $attemptUuid = isset($body['attempt_uuid']) ? trim((string)$body['attempt_uuid']) : '';
        if ($attemptUuid === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $attemptUuid)) {
            return [
                'success' => false,
                'code' => 400,
                'status' => 'INVALID_PAYLOAD',
                'message' => 'attempt_uuid es obligatorio y debe ser un UUID válido de 36 caracteres.'
            ];
        }

        $workerRef = isset($body['external_worker_reference']) ? trim((string)$body['external_worker_reference']) : '';
        if ($workerRef === '' || strlen($workerRef) > 64) {
            return [
                'success' => false,
                'code' => 400,
                'status' => 'INVALID_PAYLOAD',
                'message' => 'external_worker_reference es obligatorio y no debe exceder 64 caracteres.'
            ];
        }

        $eventType = isset($body['event_type']) ? trim((string)$body['event_type']) : '';
        if ($eventType === '' || strlen($eventType) > 50) {
            return [
                'success' => false,
                'code' => 400,
                'status' => 'INVALID_PAYLOAD',
                'message' => 'event_type es obligatorio y no debe exceder 50 caracteres.'
            ];
        }

        $rejectionReason = isset($body['rejection_reason']) ? trim((string)$body['rejection_reason']) : '';
        if ($rejectionReason === '' || strlen($rejectionReason) > 50) {
            return [
                'success' => false,
                'code' => 400,
                'status' => 'INVALID_PAYLOAD',
                'message' => 'rejection_reason es obligatorio y no debe exceder 50 caracteres.'
            ];
        }

        // 2. Validar motivo de rechazo autorizado (Regla de integridad Gatekeeper)
        if (!in_array($rejectionReason, self::ALLOWED_REJECTION_REASONS, true)) {
            return [
                'success' => false,
                'code' => 422,
                'status' => 'UNAUTHORIZED_REJECTION_REASON',
                'message' => "El motivo de rechazo '{$rejectionReason}' no está autorizado por la política Gatekeeper V1.4."
            ];
        }

        $attemptTimestampLocal = isset($body['attempt_timestamp_local']) ? trim((string)$body['attempt_timestamp_local']) : '';
        if ($attemptTimestampLocal === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $attemptTimestampLocal)) {
            return [
                'success' => false,
                'code' => 400,
                'status' => 'INVALID_PAYLOAD',
                'message' => 'attempt_timestamp_local debe tener el formato YYYY-MM-DD HH:MM:SS.'
            ];
        }

        // 3. Sanitizar campos opcionales
        $inferredDirection = isset($body['inferred_direction']) ? strtoupper(trim((string)$body['inferred_direction'])) : 'ENTRY';
        if (!in_array($inferredDirection, ['ENTRY', 'EXIT', 'AMBIGUOUS', 'UNKNOWN'], true)) {
            $inferredDirection = 'ENTRY';
        }

        $scheduledStartLocal = isset($body['scheduled_start_local']) && trim((string)$body['scheduled_start_local']) !== ''
            ? trim((string)$body['scheduled_start_local'])
            : null;

        $earliestAllowedEntryLocal = isset($body['earliest_allowed_entry_local']) && trim((string)$body['earliest_allowed_entry_local']) !== ''
            ? trim((string)$body['earliest_allowed_entry_local'])
            : null;

        $deviceClockStatus = isset($body['device_clock_status']) ? trim((string)$body['device_clock_status']) : 'SYNCHRONIZED';
        $clockDriftSeconds = isset($body['clock_drift_seconds']) && is_numeric($body['clock_drift_seconds'])
            ? (int)$body['clock_drift_seconds']
            : 0;

        $clientVersion = isset($body['client_version']) ? trim((string)$body['client_version']) : null;
        if ($clientVersion !== null && strlen($clientVersion) > 128) {
            $clientVersion = substr($clientVersion, 0, 128);
        }

        // 4. Resolver identidad laboral para este dispositivo específico (Aislamiento de estación)
        $idPersonal = $this->punchEventService->resolverTrabajadorHistorico(
            $this->db,
            $idDispositivo,
            $workerRef,
            $recibidoAtUtc
        );

        if ($idPersonal === null) {
            return [
                'success' => false,
                'code' => 404,
                'status' => 'WORKER_NOT_FOUND',
                'message' => 'Trabajador no enrolado o no encontrado para este dispositivo.'
            ];
        }

        // 5. Validar pertenencia del trabajador a la estación autorizada del dispositivo
        if (!$this->verifyWorkerStationBelonging($idPersonal, $idEstacion)) {
            return [
                'success' => false,
                'code' => 404,
                'status' => 'WORKER_NOT_FOUND',
                'message' => 'Trabajador no pertenece a la estación autorizada del dispositivo.'
            ];
        }

        // 6. Verificar si la tabla de persistencia física está disponible
        if (!$this->repository->isTableAvailable()) {
            return [
                'success' => false,
                'code' => 503,
                'status' => 'AUDIT_PERSISTENCE_PENDING_TABLE',
                'message' => 'La persistencia de auditoría de intentos no está disponible. Tabla física op_rh_v2_marcaciones_intentos_auditoria pendiente de autorización DDL.'
            ];
        }

        // 7. Calcular fingerprint SHA-256 para validación de idempotencia determinista
        $normalizedPayload = [
            'id_dispositivo' => $idDispositivo,
            'attempt_uuid' => $attemptUuid,
            'referencia_externa_personal' => $workerRef,
            'event_type' => $eventType,
            'rejection_reason' => $rejectionReason,
            'attempt_timestamp_local' => $attemptTimestampLocal,
            'scheduled_start_local' => $scheduledStartLocal,
            'earliest_allowed_entry_local' => $earliestAllowedEntryLocal
        ];
        $payloadSha256 = hash('sha256', json_encode($normalizedPayload, JSON_UNESCAPED_UNICODE));

        // 8. Idempotencia y detección de colisión
        $existing = $this->repository->findByAttemptUuid($idDispositivo, $attemptUuid);
        if ($existing !== null) {
            // Comprobar si el payload es idéntico
            if ($existing['payload_sha256'] === $payloadSha256) {
                // Reintento idéntico exitoso -> 200 OK
                return [
                    'success' => true,
                    'code' => 200,
                    'data' => [
                        'attempt_uuid' => $attemptUuid,
                        'recorded_at_utc' => $existing['recibido_at_utc'],
                        'status' => 'ALREADY_AUDITED'
                    ]
                ];
            } else {
                // Mismo attempt_uuid con contenido divergente -> 409 Conflict
                return [
                    'success' => false,
                    'code' => 409,
                    'status' => 'ATTEMPT_UUID_PAYLOAD_MISMATCH',
                    'message' => 'El attempt_uuid ya existe con una carga útil diferente.'
                ];
            }
        }

        // 9. Registrar nuevo intento de auditoría
        $record = [
            'attempt_uuid' => $attemptUuid,
            'id_dispositivo' => $idDispositivo,
            'id_estacion_resuelta' => $idEstacion,
            'referencia_externa_personal' => $workerRef,
            'id_personal_resuelto' => $idPersonal,
            'event_type' => $eventType,
            'rejection_reason' => $rejectionReason,
            'inferred_direction' => $inferredDirection,
            'attempt_timestamp_local' => $attemptTimestampLocal,
            'scheduled_start_local' => $scheduledStartLocal,
            'earliest_allowed_entry_local' => $earliestAllowedEntryLocal,
            'device_clock_status' => $deviceClockStatus,
            'clock_drift_seconds' => $clockDriftSeconds,
            'client_version' => $clientVersion,
            'payload_sha256' => $payloadSha256,
            'payload_json' => json_encode($body, JSON_UNESCAPED_UNICODE),
            'recibido_at_utc' => $recibidoAtUtc
        ];

        $inserted = $this->repository->insertAuditAttempt($record);
        if (!$inserted) {
            // Manejar carrera concurrente
            $concurrentExisting = $this->repository->findByAttemptUuid($idDispositivo, $attemptUuid);
            if ($concurrentExisting !== null) {
                if ($concurrentExisting['payload_sha256'] === $payloadSha256) {
                    return [
                        'success' => true,
                        'code' => 200,
                        'data' => [
                            'attempt_uuid' => $attemptUuid,
                            'recorded_at_utc' => $concurrentExisting['recibido_at_utc'],
                            'status' => 'ALREADY_AUDITED'
                        ]
                    ];
                } else {
                    return [
                        'success' => false,
                        'code' => 409,
                        'status' => 'ATTEMPT_UUID_PAYLOAD_MISMATCH',
                        'message' => 'El attempt_uuid ya existe con una carga útil diferente.'
                    ];
                }
            }

            return [
                'success' => false,
                'code' => 500,
                'status' => 'INTERNAL_SERVER_ERROR',
                'message' => 'Error al persistir el registro de auditoría.'
            ];
        }

        return [
            'success' => true,
            'code' => 201,
            'data' => [
                'attempt_uuid' => $attemptUuid,
                'recorded_at_utc' => $recibidoAtUtc,
                'status' => 'AUDITED'
            ]
        ];
    }

    /**
     * Valida la pertenencia y estado activo del trabajador respecto a la estación autorizada.
     */
    protected function verifyWorkerStationBelonging(int $idPersonal, int $idEstacion): bool
    {
        $stmt = $this->db->prepare("SELECT id, id_estacion, estado FROM op_rh_personal WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $idPersonal);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row || (int)$row['estado'] !== 1 || (int)$row['id_estacion'] !== $idEstacion) {
            return false;
        }
        return true;
    }
}
