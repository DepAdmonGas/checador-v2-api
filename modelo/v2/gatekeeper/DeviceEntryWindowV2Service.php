<?php
/**
 * Checador V2 — Device Entry Window Gatekeeper Domain Service (API-01)
 *
 * Implements pure read-only evaluation for:
 * GET /api/v2/device/workers/{external_worker_reference}/entry-window
 *
 * Specification: docs/v2/CHECADOR_V2_DEVICE_ENTRY_WINDOW_API_CONTRACT_V1.0.md (APPROVED_FROZEN_V1.4)
 * Architecture Addendum: docs/architecture/CHECADOR_V2_EARLY_ENTRY_GATEKEEPER_ADDENDUM_V1.0.md (APPROVED_FROZEN_V1.4)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';
require_once __DIR__ . '/../PunchEventV2.php';
require_once __DIR__ . '/../shift/ScheduleResolutionService.php';
require_once __DIR__ . '/../shift/WorkplaceTimezoneAuthorityService.php';

class DeviceEntryWindowV2Service
{
    private mysqli $db;
    private ScheduleResolutionService $scheduleResolutionService;
    private WorkplaceTimezoneAuthorityService $timezoneAuthorityService;
    private PunchEventV2 $punchEventService;

    public function __construct(
        ?mysqli $db = null,
        ?ScheduleResolutionService $scheduleResolutionService = null,
        ?WorkplaceTimezoneAuthorityService $timezoneAuthorityService = null,
        ?PunchEventV2 $punchEventService = null
    ) {
        if ($db !== null) {
            $this->db = $db;
        } else {
            $bdObj = new BD();
            $this->db = $bdObj->conectarBD();
        }

        $this->scheduleResolutionService = $scheduleResolutionService ?? new ScheduleResolutionService($this->db);
        $this->timezoneAuthorityService = $timezoneAuthorityService ?? new WorkplaceTimezoneAuthorityService($this->db);
        $this->punchEventService = $punchEventService ?? new PunchEventV2();
    }

    /**
     * Pure read-only evaluation of perimeter gatekeeper decision.
     * Strictly ZERO writes to database or punch event queue.
     */
    public function evaluateEntryWindow(
        int $idDispositivo,
        int $idEstacion,
        ?string $deviceDefaultTimezone,
        string $externalWorkerRef,
        ?string $currentTimestampLocal = null,
        int $pendingLocalPunchesCount = 0,
        ?string $lastLocalPendingTimestamp = null,
        ?string $lastLocalPendingUuid = null,
        ?DateTimeImmutable $mockServerNow = null
    ): array {
        $workerRefTrimmed = trim($externalWorkerRef);
        if ($workerRefTrimmed === '' || strlen($workerRefTrimmed) > 64) {
            return [
                'success' => false,
                'code' => 400,
                'status' => 'INVALID_PAYLOAD',
                'message' => 'external_worker_reference es obligatorio y no debe exceder 64 caracteres.'
            ];
        }

        $serverNowUtc = $mockServerNow ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowUtcStr = $serverNowUtc->format('Y-m-d H:i:s');

        // 1. Resolver identidad laboral para este dispositivo específico (Aislamiento de estación)
        $idPersonal = $this->punchEventService->resolverTrabajadorHistorico(
            $this->db,
            $idDispositivo,
            $workerRefTrimmed,
            $nowUtcStr
        );

        if ($idPersonal === null) {
            return [
                'success' => false,
                'code' => 404,
                'status' => 'WORKER_NOT_FOUND',
                'message' => 'Trabajador no enrolado o no encontrado para este dispositivo.'
            ];
        }

        // 1.1 Verificar pertenencia y autorización del trabajador para la estación del dispositivo (Aislamiento seguro)
        if (!$this->verifyWorkerStationBelonging($idPersonal, $idEstacion)) {
            return [
                'success' => false,
                'code' => 404,
                'status' => 'WORKER_NOT_FOUND',
                'message' => 'Trabajador no enrolado o no encontrado para este dispositivo.'
            ];
        }

        // 2. Resolver zona horaria IANA canónica de la localidad / estación
        $targetDateUtc = $serverNowUtc->format('Y-m-d');
        $canonicalTimezone = null;

        try {
            $canonicalTimezone = $this->timezoneAuthorityService->resolve($idEstacion, $targetDateUtc);
        } catch (Throwable $e) {
            // Fail-closed si la zona horaria no puede resolverse
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'SCHEDULE_UNKNOWN',
                    'is_entry_allowed' => false,
                    'should_record_punch' => false,
                    'inferred_direction' => 'UNKNOWN',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $idEstacion,
                    'fecha_local' => $targetDateUtc,
                    'timezone' => null,
                    'estado_jornada' => 'SCHEDULE_UNKNOWN',
                    'desktop_ui_message' => [
                        'header' => 'REGISTRO PROVISIONAL',
                        'detail' => 'HORARIO NO VERIFICABLE. ZONA HORARIA NO RESUELTA.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'ALERT_ERROR'
                    ]
                ]
            ];
        }

        $tz = new DateTimeZone($canonicalTimezone);
        $nowLocal = $serverNowUtc->setTimezone($tz);
        $fechaLocal = $nowLocal->format('Y-m-d');
        $serverTimeLocal = $nowLocal->format('Y-m-d H:i:s');
        $serverTimeOnly = $nowLocal->format('H:i:s');

        // 3. Detección de deriva horaria con el reloj del cliente (TC-12 Fail-closed)
        if ($currentTimestampLocal !== null && trim($currentTimestampLocal) !== '') {
            $clientTimeTrimmed = trim($currentTimestampLocal);
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $clientTimeTrimmed)) {
                return [
                    'success' => false,
                    'code' => 400,
                    'status' => 'INVALID_PARAMETER',
                    'message' => 'current_timestamp_local debe tener el formato YYYY-MM-DD HH:MM:SS.'
                ];
            }

            $clientTs = strtotime($clientTimeTrimmed);
            $serverTs = $nowLocal->getTimestamp();
            $driftSeconds = abs($serverTs - $clientTs);

            if ($driftSeconds > 300) {
                return [
                    'success' => true,
                    'code' => 200,
                    'data' => [
                        'gatekeeper_action' => 'SCHEDULE_UNKNOWN',
                        'is_entry_allowed' => false,
                        'should_record_punch' => false,
                        'inferred_direction' => 'UNKNOWN',
                        'external_worker_reference' => $workerRefTrimmed,
                        'workplace_id' => $idEstacion,
                        'fecha_local' => $fechaLocal,
                        'timezone' => $canonicalTimezone,
                        'estado_jornada' => 'SCHEDULE_UNKNOWN',
                        'desktop_ui_message' => [
                            'header' => 'RELOJ DESFASADO',
                            'detail' => 'Reloj desfasado. Notifique a supervisor.',
                            'card_color' => 'RED',
                            'sound_alert' => 'ALERT_ERROR'
                        ]
                    ]
                ];
            }
        }

        // 4. Consultar horario efectivo del trabajador
        $schedule = null;
        try {
            $schedule = $this->scheduleResolutionService->resolveSchedule($idPersonal, $idEstacion, $fechaLocal);
        } catch (Throwable $ex) {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'SCHEDULE_UNKNOWN',
                    'is_entry_allowed' => false,
                    'should_record_punch' => false,
                    'inferred_direction' => 'UNKNOWN',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $idEstacion,
                    'fecha_local' => $fechaLocal,
                    'timezone' => $canonicalTimezone,
                    'estado_jornada' => 'SCHEDULE_UNKNOWN',
                    'desktop_ui_message' => [
                        'header' => 'REGISTRO PROVISIONAL',
                        'detail' => 'HORARIO NO VERIFICABLE. CONSULTE AL SUPERVISOR.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'ALERT_ERROR'
                    ]
                ]
            ];
        }

        $estadoJornada = $schedule['estado_jornada'] ?? 'UNKNOWN';

        // 5. Caso UNSCHEDULED (Decisión C)
        if ($estadoJornada === 'UNSCHEDULED') {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'ALLOW_UNSCHEDULED',
                    'is_entry_allowed' => true,
                    'should_record_punch' => true,
                    'inferred_direction' => 'ENTRY',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $idEstacion,
                    'fecha_local' => $fechaLocal,
                    'timezone' => $canonicalTimezone,
                    'estado_jornada' => 'UNSCHEDULED',
                    'scheduled_start_local' => null,
                    'scheduled_end_local' => null,
                    'earliest_allowed_entry_local' => null,
                    'server_time_local' => $serverTimeLocal,
                    'metadata_payload' => [
                        'schedule_status' => 'UNSCHEDULED'
                    ],
                    'desktop_ui_message' => [
                        'header' => 'MARCACIÓN REGISTRADA',
                        'detail' => 'SIN HORARIO ASIGNADO. PENDIENTE DE REVISIÓN POR RH.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'CONFIRMATION_NEUTRAL'
                    ]
                ]
            ];
        }

        // 6. Caso DAY_OFF (Decisión I Aprobada)
        if ($estadoJornada === 'DAY_OFF') {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'ALLOW_DAY_OFF',
                    'is_entry_allowed' => true,
                    'should_record_punch' => true,
                    'inferred_direction' => 'ENTRY',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $idEstacion,
                    'fecha_local' => $fechaLocal,
                    'timezone' => $canonicalTimezone,
                    'estado_jornada' => 'DAY_OFF',
                    'scheduled_start_local' => null,
                    'scheduled_end_local' => null,
                    'earliest_allowed_entry_local' => null,
                    'server_time_local' => $serverTimeLocal,
                    'metadata_payload' => [
                        'schedule_status' => 'DAY_OFF'
                    ],
                    'desktop_ui_message' => [
                        'header' => 'MARCACIÓN REGISTRADA',
                        'detail' => 'DÍA DE DESCANSO PROGRAMADO. PENDIENTE DE REVISIÓN POR RH.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'CONFIRMATION_NEUTRAL'
                    ]
                ]
            ];
        }

        // 7. Caso SCHEDULE_UNKNOWN / UNKNOWN
        if ($estadoJornada !== 'SCHEDULED') {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'SCHEDULE_UNKNOWN',
                    'is_entry_allowed' => false,
                    'should_record_punch' => false,
                    'inferred_direction' => 'UNKNOWN',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $idEstacion,
                    'fecha_local' => $fechaLocal,
                    'timezone' => $canonicalTimezone,
                    'estado_jornada' => 'SCHEDULE_UNKNOWN',
                    'desktop_ui_message' => [
                        'header' => 'REGISTRO PROVISIONAL',
                        'detail' => 'HORARIO NO VERIFICABLE. CONSULTE AL SUPERVISOR.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'ALERT_ERROR'
                    ]
                ]
            ];
        }

        // 8. Caso SCHEDULED: Análisis de Turno y Ventana de 10 minutos
        $segmentos = $schedule['horario_local']['segmentos'] ?? [];
        if (empty($segmentos)) {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'SCHEDULE_UNKNOWN',
                    'is_entry_allowed' => false,
                    'should_record_punch' => false,
                    'inferred_direction' => 'UNKNOWN',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $idEstacion,
                    'fecha_local' => $fechaLocal,
                    'timezone' => $canonicalTimezone,
                    'estado_jornada' => 'SCHEDULE_UNKNOWN',
                    'desktop_ui_message' => [
                        'header' => 'REGISTRO PROVISIONAL',
                        'detail' => 'HORARIO NO VERIFICABLE. SEGMENTOS NO ENCONTRADOS.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'ALERT_ERROR'
                    ]
                ]
            ];
        }

        $targetFechaLocal = $fechaLocal;
        $segStartLocal = $segmentos[0]['hora_inicio_local'];
        $segEndLocal = $segmentos[count($segmentos) - 1]['hora_fin_local'];
        $offStart = (int)($segmentos[0]['dia_offset_inicio'] ?? 0);
        $offEnd = (int)($segmentos[count($segmentos) - 1]['dia_offset_fin'] ?? 0);

        // Comprobación de turno nocturno contiguo hacia el día siguiente (TC-13)
        // Si la hora actual es >= 23:00 y mañana hay un turno a las 00:00
        if ($nowLocal->format('H:i:s') >= '23:00:00') {
            $fechaManana = date('Y-m-d', strtotime("{$fechaLocal} +1 day"));
            try {
                $schedManana = $this->scheduleResolutionService->resolveSchedule($idPersonal, $idEstacion, $fechaManana);
                if (($schedManana['estado_jornada'] ?? '') === 'SCHEDULED' && !empty($schedManana['horario_local']['segmentos'])) {
                    $mSeg = $schedManana['horario_local']['segmentos'];
                    $mStart = $mSeg[0]['hora_inicio_local'];
                    if ($mStart <= '01:00:00') {
                        $targetFechaLocal = $fechaManana;
                        $segStartLocal = $mStart;
                        $segEndLocal = $mSeg[count($mSeg) - 1]['hora_fin_local'];
                        $offStart = (int)($mSeg[0]['dia_offset_inicio'] ?? 0);
                        $offEnd = (int)($mSeg[count($mSeg) - 1]['dia_offset_fin'] ?? 0);
                    }
                }
            } catch (Throwable $t) {
                // Mantener evaluación de hoy
            }
        }

        // Comprobación de salida de turno nocturno iniciado ayer (TC-08)
        if ($nowLocal->format('H:i:s') <= '08:00:00') {
            $fechaAyer = date('Y-m-d', strtotime("{$fechaLocal} -1 day"));
            try {
                $schedAyer = $this->scheduleResolutionService->resolveSchedule($idPersonal, $idEstacion, $fechaAyer);
                if (($schedAyer['estado_jornada'] ?? '') === 'SCHEDULED' && !empty($schedAyer['horario_local']['segmentos'])) {
                    $ySeg = $schedAyer['horario_local']['segmentos'];
                    $yEnd = $ySeg[count($ySeg) - 1]['hora_fin_local'];
                    $yOffEnd = (int)($ySeg[count($ySeg) - 1]['dia_offset_fin'] ?? 0);
                    // Si el turno de ayer termina hoy
                    if ($yOffEnd >= 1 || $yEnd <= '08:00:00') {
                        // Verificar si existe una entrada abierta de ayer
                        $hasYesterdayOpenEntry = $this->checkOpenEntryForShift(
                            $idPersonal,
                            $idEstacion,
                            $fechaAyer,
                            $ySeg[0]['hora_inicio_local'],
                            $yEnd,
                            (int)($ySeg[0]['dia_offset_inicio'] ?? 0),
                            $yOffEnd,
                            $tz
                        );
                        if ($hasYesterdayOpenEntry) {
                            $targetFechaLocal = $fechaAyer;
                            $segStartLocal = $ySeg[0]['hora_inicio_local'];
                            $segEndLocal = $yEnd;
                            $offStart = (int)($ySeg[0]['dia_offset_inicio'] ?? 0);
                            $offEnd = $yOffEnd;
                        }
                    }
                }
            } catch (Throwable $t) {
                // Mantener evaluación de hoy
            }
        }

        $dtStart = new DateTimeImmutable("{$targetFechaLocal} {$segStartLocal}", $tz);
        if ($offStart !== 0) {
            $dtStart = $dtStart->modify("{$offStart} days");
        }

        $dtEnd = new DateTimeImmutable("{$targetFechaLocal} {$segEndLocal}", $tz);
        if ($offEnd !== 0) {
            $dtEnd = $dtEnd->modify("{$offEnd} days");
        }

        $dtEarliest = $dtStart->modify('-10 minutes');

        // 9. Consultar contexto de marcaciones previas (Servidor + Cola Local Pendiente)
        $wStartUtc = $dtEarliest->modify('-4 hours')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $wEndUtc = $dtEnd->modify('+4 hours')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $punchContext = $this->fetchCandidatePunchesForWindow(
            $idPersonal,
            $idEstacion,
            $wStartUtc,
            $wEndUtc
        );

        if ($punchContext['has_workplace_mismatch'] || $punchContext['has_ambiguity']) {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'AMBIGUOUS_DIRECTION',
                    'is_entry_allowed' => false,
                    'should_record_punch' => false,
                    'inferred_direction' => 'AMBIGUOUS',
                    'external_worker_reference' => $workerRefTrimmed,
                    'desktop_ui_message' => [
                        'header' => 'VERIFICACIÓN REQUERIDA',
                        'detail' => 'No se pudo determinar si es Entrada o Salida. Consulte al supervisor.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'ALERT_ERROR'
                    ]
                ]
            ];
        }

        $serverPunchesCount = count($punchContext['punches']);
        $clientPendingCount = max(0, $pendingLocalPunchesCount);

        // Validación de coherencia de pendientes locales
        if ($clientPendingCount > 0 && !empty($lastLocalPendingTimestamp)) {
            $lastLocalTs = strtotime($lastLocalPendingTimestamp);
            if ($lastLocalTs !== false && $lastLocalTs > ($nowLocal->getTimestamp() + 300)) {
                // Marca pendiente local futura incoherente -> Dirección ambigua
                return [
                    'success' => true,
                    'code' => 200,
                    'data' => [
                        'gatekeeper_action' => 'AMBIGUOUS_DIRECTION',
                        'is_entry_allowed' => false,
                        'should_record_punch' => false,
                        'inferred_direction' => 'AMBIGUOUS',
                        'external_worker_reference' => $workerRefTrimmed,
                        'desktop_ui_message' => [
                            'header' => 'VERIFICACIÓN REQUERIDA',
                            'detail' => 'No se pudo determinar si es Entrada o Salida. Consulte al supervisor.',
                            'card_color' => 'AMBER',
                            'sound_alert' => 'ALERT_ERROR'
                        ]
                    ]
                ];
            }
        }

        $totalEffectivePunches = $serverPunchesCount + $clientPendingCount;

        // 10. Si ya existen 2 o más marcaciones en este segmento de turno -> Ambigüedad
        if ($totalEffectivePunches >= 2) {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'AMBIGUOUS_DIRECTION',
                    'is_entry_allowed' => false,
                    'should_record_punch' => false,
                    'inferred_direction' => 'AMBIGUOUS',
                    'external_worker_reference' => $workerRefTrimmed,
                    'desktop_ui_message' => [
                        'header' => 'VERIFICACIÓN REQUERIDA',
                        'detail' => 'No se pudo determinar si es Entrada o Salida. Consulte al supervisor.',
                        'card_color' => 'AMBER',
                        'sound_alert' => 'ALERT_ERROR'
                    ]
                ]
            ];
        }

        // 11. Si ya existe exactamente 1 marcación (entrada abierta) -> Autorizar SALIDA (TC-08, TC-11)
        if ($totalEffectivePunches === 1) {
            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'ALLOW_EXIT',
                    'is_entry_allowed' => true,
                    'should_record_punch' => true,
                    'inferred_direction' => 'EXIT',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $idEstacion,
                    'fecha_local' => $targetFechaLocal,
                    'timezone' => $canonicalTimezone,
                    'estado_jornada' => 'SCHEDULED',
                    'scheduled_start_local' => $dtStart->format('H:i:s'),
                    'scheduled_end_local' => $dtEnd->format('H:i:s'),
                    'earliest_allowed_entry_local' => null,
                    'server_time_local' => $serverTimeLocal,
                    'desktop_ui_message' => [
                        'header' => 'SALIDA REGISTRADA',
                        'detail' => 'Hora: ' . $serverTimeOnly . '. ¡Buen descanso!',
                        'card_color' => 'BLUE',
                        'sound_alert' => 'CONFIRMATION_SUCCESS'
                    ]
                ]
            ];
        }

        // 12. No existen marcaciones previas ($totalEffectivePunches === 0) -> Corresponde a ENTRADA
        // Aplicar la regla perimetral de los 10 minutos
        if ($nowLocal < $dtEarliest) {
            // Fuera de ventana -> REJECT_EARLY_ENTRY (TC-01, TC-09, TC-13)
            $secondsUntilAllowed = $dtEarliest->getTimestamp() - $nowLocal->getTimestamp();
            $minutesUntilAllowed = (int)ceil($secondsUntilAllowed / 60);

            $startFormatted = substr($dtStart->format('H:i:s'), 0, 5);
            $earliestFormatted = substr($dtEarliest->format('H:i:s'), 0, 5);

            return [
                'success' => true,
                'code' => 200,
                'data' => [
                    'gatekeeper_action' => 'REJECT_EARLY_ENTRY',
                    'is_entry_allowed' => false,
                    'should_record_punch' => false,
                    'inferred_direction' => 'ENTRY',
                    'external_worker_reference' => $workerRefTrimmed,
                    'workplace_id' => $idEstacion,
                    'fecha_local' => $targetFechaLocal,
                    'timezone' => $canonicalTimezone,
                    'estado_jornada' => 'SCHEDULED',
                    'scheduled_start_local' => $dtStart->format('H:i:s'),
                    'scheduled_end_local' => $dtEnd->format('H:i:s'),
                    'earliest_allowed_entry_local' => $dtEarliest->format('H:i:s'),
                    'server_time_local' => $serverTimeLocal,
                    'seconds_until_allowed' => $secondsUntilAllowed,
                    'minutes_until_allowed' => $minutesUntilAllowed,
                    'desktop_ui_message' => [
                        'header' => 'ENTRADA ANTICIPADA NO PERMITIDA',
                        'detail' => "Tu turno comienza a las {$startFormatted}. Podrás registrar tu entrada a partir de las {$earliestFormatted}.",
                        'card_color' => 'RED',
                        'sound_alert' => 'ALERT_WARNING'
                    ]
                ]
            ];
        }

        // Dentro de ventana o posterior al inicio -> ALLOW_ENTRY (TC-02, TC-03, TC-04)
        return [
            'success' => true,
            'code' => 200,
            'data' => [
                'gatekeeper_action' => 'ALLOW_ENTRY',
                'is_entry_allowed' => true,
                'should_record_punch' => true,
                'inferred_direction' => 'ENTRY',
                'external_worker_reference' => $workerRefTrimmed,
                'workplace_id' => $idEstacion,
                'fecha_local' => $targetFechaLocal,
                'timezone' => $canonicalTimezone,
                'estado_jornada' => 'SCHEDULED',
                'scheduled_start_local' => $dtStart->format('H:i:s'),
                'scheduled_end_local' => $dtEnd->format('H:i:s'),
                'earliest_allowed_entry_local' => $dtEarliest->format('H:i:s'),
                'server_time_local' => $serverTimeLocal,
                'seconds_until_allowed' => 0,
                'desktop_ui_message' => [
                    'header' => 'ENTRADA REGISTRADA',
                    'detail' => 'Hora: ' . $serverTimeOnly . '. ¡Bienvenido!',
                    'card_color' => 'GREEN',
                    'sound_alert' => 'CONFIRMATION_SUCCESS'
                ]
            ]
        ];
    }

    /**
     * Helper read-only para consultar marcaciones crudas candidatas dentro de una ventana temporal.
     */
    protected function fetchCandidatePunchesForWindow(
        int $idPersonal,
        int $idEstacion,
        string $windowStartUtc,
        string $windowEndUtc
    ): array {
        $stmt = $this->db->prepare(
            "SELECT id, id_dispositivo, id_estacion_resuelta, source_timestamp_raw,
                    ocurrido_at_utc, validation_flags
             FROM op_rh_v2_marcaciones_crudas
             WHERE id_personal_resuelto = ?
               AND ocurrido_at_utc BETWEEN ? AND ?
             ORDER BY ocurrido_at_utc ASC"
        );

        if (!$stmt) {
            return [
                'punches' => [],
                'has_workplace_mismatch' => false,
                'has_ambiguity' => false
            ];
        }

        $stmt->bind_param('iss', $idPersonal, $windowStartUtc, $windowEndUtc);
        $stmt->execute();
        $res = $stmt->get_result();

        $punches = [];
        $hasWorkplaceMismatch = false;
        $hasAmbiguity = false;

        while ($row = $res->fetch_assoc()) {
            $punches[] = $row;
            if ($row['id_estacion_resuelta'] !== null && (int)$row['id_estacion_resuelta'] !== $idEstacion) {
                $hasWorkplaceMismatch = true;
            }
            if (!empty($row['validation_flags'])) {
                $flags = (string)$row['validation_flags'];
                if (strpos($flags, 'INVALID') !== false || strpos($flags, 'UNVERIFIABLE') !== false) {
                    $hasAmbiguity = true;
                }
            }
        }
        $stmt->close();

        return [
            'punches' => $punches,
            'has_workplace_mismatch' => $hasWorkplaceMismatch,
            'has_ambiguity' => $hasAmbiguity
        ];
    }

    /**
     * Verifica si existe una entrada abierta previa para un turno determinado.
     */
    private function checkOpenEntryForShift(
        int $idPersonal,
        int $idEstacion,
        string $shiftDateLocal,
        string $startLocal,
        string $endLocal,
        int $offStart,
        int $offEnd,
        DateTimeZone $tz
    ): bool {
        $dtStart = new DateTimeImmutable("{$shiftDateLocal} {$startLocal}", $tz);
        if ($offStart !== 0) {
            $dtStart = $dtStart->modify("{$offStart} days");
        }
        $dtEnd = new DateTimeImmutable("{$shiftDateLocal} {$endLocal}", $tz);
        if ($offEnd !== 0) {
            $dtEnd = $dtEnd->modify("{$offEnd} days");
        }

        $wStartUtc = $dtStart->modify('-10 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $wEndUtc = $dtEnd->modify('+4 hours')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $candidateInfo = $this->fetchCandidatePunchesForWindow($idPersonal, $idEstacion, $wStartUtc, $wEndUtc);
        // Si hay exactamente 1 marcación en la ventana, es una entrada abierta
        return count($candidateInfo['punches']) === 1 && !$candidateInfo['has_workplace_mismatch'];
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
