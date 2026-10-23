<?php
/**
 * Checador V2 — ScheduleResolutionService
 * Pure deterministic read-only engine resolving effective schedule for worker/workplace/date.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';
require_once __DIR__ . '/WorkplaceTimezoneAuthorityService.php';

class ScheduleResolutionService
{
    private mysqli $db;
    private WorkplaceTimezoneAuthorityService $timezoneAuthority;

    public function __construct(?mysqli $db = null)
    {
        if ($db !== null) {
            $this->db = $db;
        } else {
            $bdObj = new BD();
            $this->db = $bdObj->conectarBD();
        }
        $this->timezoneAuthority = new WorkplaceTimezoneAuthorityService($this->db);
    }

    /**
     * Resolve effective schedule for (id_personal, id_estacion, fecha_local).
     * Strictly pure read-only with NO database write side-effects.
     */
    public function resolveSchedule(int $idPersonal, int $idEstacion, string $fechaLocal): array
    {
        if ($idPersonal <= 0 || $idEstacion <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaLocal)) {
            throw new InvalidArgumentException("INVALID_RESOLUTION_SPECIFICATION: Valid id_personal, id_estacion, and YYYY-MM-DD fecha_local are required.");
        }

        // 1. Check if worker exists in system (op_rh_personal)
        $stmtW = $this->db->prepare("SELECT id FROM op_rh_personal WHERE id = ? LIMIT 1");
        $stmtW->bind_param("i", $idPersonal);
        $stmtW->execute();
        $workerExists = ($stmtW->get_result()->num_rows > 0);

        if (!$workerExists) {
            return [
                'id_personal' => $idPersonal,
                'id_estacion' => $idEstacion,
                'fecha_local' => $fechaLocal,
                'estado_jornada' => 'UNKNOWN',
                'reason' => 'Worker is not mapped in system'
            ];
        }

        // 2. Check if date is prior to cutover or unsupported historical boundary
        if ($fechaLocal < '2026-01-01') {
            return [
                'id_personal' => $idPersonal,
                'id_estacion' => $idEstacion,
                'fecha_local' => $fechaLocal,
                'estado_jornada' => 'UNKNOWN',
                'reason' => 'Target date is prior to system cutover'
            ];
        }

        // Determine ISO weekday string in SPANISH as stored in dia_semana column
        $timestamp = strtotime($fechaLocal);
        $isoDay = (int)date('N', $timestamp); // 1 = Lunes, 7 = Domingo
        $daysMap = [
            1 => 'LUNES',
            2 => 'MARTES',
            3 => 'MIERCOLES',
            4 => 'JUEVES',
            5 => 'VIERNES',
            6 => 'SABADO',
            7 => 'DOMINGO'
        ];
        $diaSemana = $daysMap[$isoDay];

        // 3. Query effective assignment in op_rh_v2_turnos_asignaciones for matching weekday and date interval
        // Supports both current active assignments and closed historical assignments [valido_desde, valido_hasta).
        // Excludes cancelled/revoked assignments (es_activo = 0 with NULL or non-positive interval).
        $sqlAsig = "SELECT id, id_plantilla, valido_desde, valido_hasta, es_activo
                    FROM op_rh_v2_turnos_asignaciones
                    WHERE id_personal = ? AND id_estacion = ? AND dia_semana = ?
                      AND valido_desde <= ?
                      AND (
                        (es_activo = 1 AND (valido_hasta > ? OR valido_hasta IS NULL))
                        OR
                        (es_activo = 0 AND valido_hasta IS NOT NULL AND valido_hasta > valido_desde AND valido_hasta > ?)
                      )
                    ORDER BY id ASC";
        $stmtAsig = $this->db->prepare($sqlAsig);
        $stmtAsig->bind_param("iissss", $idPersonal, $idEstacion, $diaSemana, $fechaLocal, $fechaLocal, $fechaLocal);
        $stmtAsig->execute();
        $resAsig = $stmtAsig->get_result();

        $matchingAssignments = [];
        while ($row = $resAsig->fetch_assoc()) {
            $matchingAssignments[] = $row;
        }

        if (count($matchingAssignments) === 0) {
            // Check if worker has ANY assignment in system to distinguish UNSCHEDULED from UNKNOWN
            $stmtAny = $this->db->prepare("SELECT id FROM op_rh_v2_turnos_asignaciones WHERE id_personal = ? LIMIT 1");
            $stmtAny->bind_param("i", $idPersonal);
            $stmtAny->execute();
            if ($stmtAny->get_result()->num_rows > 0) {
                return [
                    'id_personal' => $idPersonal,
                    'id_estacion' => $idEstacion,
                    'fecha_local' => $fechaLocal,
                    'estado_jornada' => 'UNSCHEDULED',
                    'reason' => 'Worker has active system profile but no shift assigned for target station and weekday'
                ];
            } else {
                return [
                    'id_personal' => $idPersonal,
                    'id_estacion' => $idEstacion,
                    'fecha_local' => $fechaLocal,
                    'estado_jornada' => 'UNSCHEDULED',
                    'reason' => 'Worker has no active assignments'
                ];
            }
        }

        if (count($matchingAssignments) > 1) {
            throw new RuntimeException("AMBIGUOUS_SCHEDULE_ASSIGNMENT: Multiple conflicting shift assignments found for worker {$idPersonal} at station {$idEstacion} on {$fechaLocal} ({$diaSemana}).");
        }

        $asigRow = $matchingAssignments[0];
        $asigId = (int)$asigRow['id'];
        $plantillaId = $asigRow['id_plantilla'] !== null ? (int)$asigRow['id_plantilla'] : null;

        // Explicit Day Off assignment (id_plantilla = NULL)
        if ($plantillaId === null) {
            return [
                'id_personal' => $idPersonal,
                'id_estacion' => $idEstacion,
                'fecha_local' => $fechaLocal,
                'estado_jornada' => 'DAY_OFF',
                'origen' => [
                    'id_asignacion' => $asigId,
                    'id_plantilla' => null
                ],
                'horario_local' => null,
                'horario_utc' => null
            ];
        }

        // Get Plantilla details
        $stmtTpl = $this->db->prepare("SELECT clave, version, nombre, tipo_turno FROM op_rh_v2_turnos_plantillas WHERE id = ? LIMIT 1");
        $stmtTpl->bind_param("i", $plantillaId);
        $stmtTpl->execute();
        $resTpl = $stmtTpl->get_result();
        if ($resTpl->num_rows === 0) {
            throw new RuntimeException("DB_INTEGRITY_ERROR: Assigned template ID {$plantillaId} not found.");
        }
        $tplRow = $resTpl->fetch_assoc();

        // Get Segments
        $stmtSeg = $this->db->prepare("SELECT ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin FROM op_rh_v2_turnos_segmentos WHERE id_plantilla = ? ORDER BY ordinal ASC");
        $stmtSeg->bind_param("i", $plantillaId);
        $stmtSeg->execute();
        $resSeg = $stmtSeg->get_result();

        $segmentos = [];
        $firstStartUtc = null;
        $lastEndUtc = null;

        $workplaceTz = $this->getWorkplaceTimezone($idEstacion, $fechaLocal);

        while ($s = $resSeg->fetch_assoc()) {
            $ord = (int)$s['ordinal'];
            $tipo = $s['tipo_segmento'];
            $hIni = $s['hora_inicio_local'];
            $hFin = $s['hora_fin_local'];
            $offIni = (int)$s['dia_offset_inicio'];
            $offFin = (int)$s['dia_offset_fin'];

            // Convert to UTC timestamps using workplace timezone authority
            $dtStartStr = date('Y-m-d', strtotime("{$fechaLocal} +{$offIni} days")) . ' ' . $hIni;
            $dtEndStr = date('Y-m-d', strtotime("{$fechaLocal} +{$offFin} days")) . ' ' . $hFin;

            $tz = new DateTimeZone($workplaceTz);
            $dtStart = new DateTime($dtStartStr, $tz);
            $dtStart->setTimezone(new DateTimeZone('UTC'));
            $startUtc = $dtStart->format('Y-m-d H:i:s');

            $dtEnd = new DateTime($dtEndStr, $tz);
            $dtEnd->setTimezone(new DateTimeZone('UTC'));
            $endUtc = $dtEnd->format('Y-m-d H:i:s');

            $segmentos[] = [
                'ordinal' => $ord,
                'tipo' => $tipo,
                'tipo_segmento' => $tipo,
                'hora_inicio_local' => $hIni,
                'hora_fin_local' => $hFin,
                'dia_offset_inicio' => $offIni,
                'dia_offset_fin' => $offFin,
                'scheduled_start_utc' => $startUtc,
                'scheduled_end_utc' => $endUtc
            ];

            if ($firstStartUtc === null || $startUtc < $firstStartUtc) {
                $firstStartUtc = $startUtc;
            }
            if ($lastEndUtc === null || $endUtc > $lastEndUtc) {
                $lastEndUtc = $endUtc;
            }
        }

        return [
            'id_personal' => $idPersonal,
            'id_estacion' => $idEstacion,
            'fecha_local' => $fechaLocal,
            'estado_jornada' => 'SCHEDULED',
            'origen' => [
                'id_asignacion' => $asigId,
                'id_plantilla' => $plantillaId,
                'clave_plantilla' => $tplRow['clave'],
                'version_plantilla' => (int)$tplRow['version']
            ],
            'horario_local' => [
                'timezone' => $workplaceTz,
                'segmentos' => $segmentos
            ],
            'horario_utc' => [
                'start_utc' => $firstStartUtc,
                'end_utc' => $lastEndUtc
            ]
        ];
    }

    /** Resolve the canonical effective-dated Workplace timezone. */
    public function getWorkplaceTimezone(int $idEstacion, string $fechaLocal): string
    {
        return $this->timezoneAuthority->resolve($idEstacion, $fechaLocal);
    }
}
