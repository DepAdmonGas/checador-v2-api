<?php
/**
 * Checador V2 — ScheduledShiftMaterializationService
 * Materializes daily scheduled shifts into op_rh_v2_jornadas_programadas.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';
require_once __DIR__ . '/ScheduleResolutionService.php';

class ScheduledShiftMaterializationService
{
    private mysqli $db;
    private ScheduleResolutionService $resolutionService;

    public function __construct(?mysqli $db = null)
    {
        if ($db !== null) {
            $this->db = $db;
        } else {
            $bdObj = new BD();
            $this->db = $bdObj->conectarBD();
        }
        $this->resolutionService = new ScheduleResolutionService($this->db);
    }

    /**
     * Materialize daily scheduled shifts for (id_personal, id_estacion, fecha_desde, fecha_hasta).
     * Bounded date range (max 31 days). Idempotent INSERTs; existing materialized facts are immutable.
     */
    public function materializeShifts(int $idPersonal, int $idEstacion, string $fechaDesde, string $fechaHasta): array
    {
        if ($idPersonal <= 0 || $idEstacion <= 0) {
            throw new InvalidArgumentException("INVALID_MATERIALIZATION_SPECIFICATION: id_personal and id_estacion are required.");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
            throw new InvalidArgumentException("INVALID_MATERIALIZATION_SPECIFICATION: Valid YYYY-MM-DD dates required.");
        }
        if ($fechaHasta < $fechaDesde) {
            throw new InvalidArgumentException("INVALID_MATERIALIZATION_SPECIFICATION: fecha_hasta cannot be before fecha_desde.");
        }

        $dtStart = new DateTime($fechaDesde);
        $dtEnd = new DateTime($fechaHasta);
        $daysDiff = $dtStart->diff($dtEnd)->days + 1;
        if ($daysDiff > 31) {
            throw new InvalidArgumentException("BOUNDED_QUERY_VIOLATION: Maximum materialization range is 31 days.");
        }

        $results = [];
        $current = clone $dtStart;

        while ($current <= $dtEnd) {
            $fechaLocal = $current->format('Y-m-d');
            $materialized = $this->materializeSingleDay($idPersonal, $idEstacion, $fechaLocal);
            $results[] = $materialized;
            $current->modify('+1 day');
        }

        return [
            'id_personal' => $idPersonal,
            'id_estacion' => $idEstacion,
            'fecha_desde' => $fechaDesde,
            'fecha_hasta' => $fechaHasta,
            'processed_days' => count($results),
            'materialized_shifts' => $results
        ];
    }

    /**
     * Materialize a single local date for worker/station.
     */
    public function materializeSingleDay(int $idPersonal, int $idEstacion, string $fechaLocal): array
    {
        $this->db->begin_transaction();
        try {
            // Check if already materialized
            $stmtCheck = $this->db->prepare("SELECT id, tipo_jornada, scheduled_start_utc, scheduled_end_utc, timezone_local FROM op_rh_v2_jornadas_programadas WHERE id_personal = ? AND id_estacion = ? AND fecha_jornada_local = ? LIMIT 1 FOR UPDATE");
            $stmtCheck->bind_param("iis", $idPersonal, $idEstacion, $fechaLocal);
            $stmtCheck->execute();
            $resCheck = $stmtCheck->get_result();

            if ($resCheck->num_rows > 0) {
                // Existing materialized shift is an IMMUTABLE historical fact; return 200 OK idempotent no-op
                $existing = $resCheck->fetch_assoc();
                $this->db->commit();
                return [
                    'id' => (int)$existing['id'],
                    'fecha_jornada_local' => $fechaLocal,
                    'tipo_jornada' => $existing['tipo_jornada'],
                    'status' => 'ALREADY_MATERIALIZED_IMMUTABLE'
                ];
            }

            // Execute deterministic pure resolution
            $res = $this->resolutionService->resolveSchedule($idPersonal, $idEstacion, $fechaLocal);
            $estadoJornada = $res['estado_jornada'];

            if ($estadoJornada === 'SCHEDULED') {
                $asigId = $res['origen']['id_asignacion'];
                $tplId = $res['origen']['id_plantilla'];
                $tzLocal = $res['horario_local']['timezone'];
                $startUtc = $res['horario_utc']['start_utc'];
                $endUtc = $res['horario_utc']['end_utc'];
                $payloadJson = json_encode($res['horario_local']['segmentos'], JSON_UNESCAPED_UNICODE);
                $tipoDb = 'SCHEDULED';
            } else if ($estadoJornada === 'DAY_OFF') {
                $asigId = $res['origen']['id_asignacion'] ?? null;
                $tplId = null;
                $tzLocal = $this->resolutionService->getWorkplaceTimezone($idEstacion);
                $startUtc = null;
                $endUtc = null;
                $payloadJson = null;
                $tipoDb = 'DAY_OFF';
            } else { // UNSCHEDULED or UNKNOWN
                $asigId = null;
                $tplId = null;
                $tzLocal = $this->resolutionService->getWorkplaceTimezone($idEstacion);
                $startUtc = null;
                $endUtc = null;
                $payloadJson = null;
                $tipoDb = 'UNSCHEDULED';
            }

            $stmtIns = $this->db->prepare("INSERT INTO op_rh_v2_jornadas_programadas (id_personal, id_estacion, fecha_jornada_local, id_asignacion_origen, id_plantilla_origen, tipo_jornada, scheduled_start_utc, scheduled_end_utc, timezone_local, payload_segmentos_json, creado_at_utc, actualizado_at_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            
            // Types: id_personal(i), id_estacion(i), fecha_jornada_local(s), id_asignacion_origen(i), id_plantilla_origen(i), tipo_jornada(s), scheduled_start_utc(s), scheduled_end_utc(s), timezone_local(s), payload_segmentos_json(s)
            $types = "iisiisssss";
            $stmtIns->bind_param($types, $idPersonal, $idEstacion, $fechaLocal, $asigId, $tplId, $tipoDb, $startUtc, $endUtc, $tzLocal, $payloadJson);

            if (!$stmtIns->execute()) {
                throw new RuntimeException("DB_ERROR: Could not materialize scheduled shift for {$fechaLocal}.");
            }
            $matId = (int)$this->db->insert_id;

            $this->db->commit();

            return [
                'id' => $matId,
                'fecha_jornada_local' => $fechaLocal,
                'tipo_jornada' => $tipoDb,
                'status' => 'MATERIALIZED_NEW'
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
