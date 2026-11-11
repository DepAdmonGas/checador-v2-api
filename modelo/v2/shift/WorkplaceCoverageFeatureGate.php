<?php

declare(strict_types=1);

require_once __DIR__ . '/WorkplaceCoveragePolicy.php';

/** Fail-closed activation and schema guard for the approved but not activated feature. */
final class WorkplaceCoverageFeatureGate
{
    public const FLAG = 'CHECADOR_V2_WORKPLACE_COVERAGE_ENABLED';

    public static function isEnabled(?string $value = null): bool
    {
        if ($value === null) {
            $env = getenv(self::FLAG);
            $value = $env === false ? null : $env;
        }
        return WorkplaceCoveragePolicy::featureFlagEnabled($value);
    }

    public static function assertReady(mysqli $db): void
    {
        if (!self::isEnabled()) throw new RuntimeException('WORKPLACE_COVERAGE_FEATURE_DISABLED');

        $requiredColumns = [
            'op_rh_v2_worker_workplace_coverage_versions' => [
                'id', 'coverage_uuid', 'revision_no', 'supersedes_id', 'id_personal', 'id_home_workplace',
                'id_home_membership_version', 'id_coverage_workplace', 'id_home_physical_station',
                'id_coverage_physical_station', 'fecha_desde_local', 'fecha_hasta_local_exclusiva',
                'timezone_iana', 'proposed_schedule_json', 'status', 'requested_by_usuario_id',
                'requested_at_utc', 'approved_by_usuario_id', 'approved_at_utc',
                'transition_actor_usuario_id', 'transition_action', 'motivo_solicitud',
                'motivo_transicion', 'idempotency_key_hash', 'payload_fingerprint', 'recorded_at_utc',
            ],
            'op_rh_v2_turnos_asignaciones' => [
                'id', 'id_personal', 'id_estacion', 'id_plantilla', 'tipo_asignacion', 'dia_semana',
                'valido_desde', 'valido_hasta', 'es_activo', 'registrado_at_utc',
                'creado_por_usuario_id', 'actualizado_por_usuario_id', 'motivo', 'id_cobertura_version',
            ],
            'op_rh_v2_turnos_audit_log' => [
                'id', 'entity_type', 'entity_id', 'operacion', 'id_usuario_actor', 'id_estacion',
                'id_personal', 'tipo_asignacion', 'valido_desde', 'valido_hasta', 'id_plantilla',
                'version_plantilla', 'detalle_json', 'recorded_at_utc',
            ],
            // Every persisted Journey/incidence record is FK-linked to a scheduled shift; this is
            // the authoritative guard against changing a date whose downstream evidence exists.
            'op_rh_v2_jornadas_programadas' => ['id', 'id_personal', 'fecha_jornada_local'],
            'op_rh_personal' => ['id', 'estado'],
            'op_rh_localidades' => ['id'],
            'op_rh_personal_localidad_version' => [
                'id', 'worker_id', 'workplace_id', 'valid_from_utc', 'valid_to_utc', 'recorded_at_utc', 'supersedes_id',
            ],
            'op_rh_v2_localidades_zonas_horarias' => ['id_localidad', 'timezone_iana', 'valido_desde', 'valido_hasta'],
            'op_rh_v2_turnos_plantillas' => ['id', 'version', 'scope', 'id_estacion', 'es_activo'],
            'op_rh_v2_turnos_segmentos' => [
                'id_plantilla', 'ordinal', 'tipo_segmento', 'hora_inicio_local', 'hora_fin_local',
                'dia_offset_inicio', 'dia_offset_fin',
            ],
            'op_rh_v2_permission_grants' => ['permission_key', 'principal_type', 'principal_id', 'active', 'granted'],
            'tb_usuarios' => ['id'],
        ];
        foreach ($requiredColumns as $table => $columns) {
            $available = self::columns($db, $table);
            foreach ($columns as $column) {
                if (!isset($available[$column])) throw new RuntimeException('WORKPLACE_COVERAGE_SCHEMA_UNAVAILABLE');
            }
        }

        foreach ([
            'op_rh_v2_worker_workplace_coverage_versions',
            'op_rh_v2_turnos_asignaciones',
            'op_rh_v2_turnos_audit_log',
            'op_rh_v2_jornadas_programadas',
            'op_rh_personal',
            'op_rh_localidades',
            'op_rh_personal_localidad_version',
            'op_rh_v2_localidades_zonas_horarias',
            'op_rh_v2_turnos_plantillas',
            'op_rh_v2_turnos_segmentos',
            'op_rh_v2_permission_grants',
            'tb_usuarios',
        ] as $table) {
            if (self::engine($db, $table) !== 'INNODB') throw new RuntimeException('WORKPLACE_COVERAGE_SCHEMA_UNAVAILABLE');
        }

        $coverage = self::columns($db, 'op_rh_v2_worker_workplace_coverage_versions');
        if (strtoupper((string)$coverage['status']['IS_NULLABLE']) !== 'NO'
            || !self::enumContains($coverage['status']['COLUMN_TYPE'], ['REQUESTED', 'AUTHORIZED', 'REJECTED', 'CANCELLED'])
            || !self::enumContains($coverage['transition_action']['COLUMN_TYPE'], ['REQUEST', 'AUTHORIZE', 'REJECT', 'CANCEL', 'REVISE'])
            || !self::unsignedIntegerColumnTypeMatches((string)$coverage['id_home_membership_version']['COLUMN_TYPE'], 'int')
            || strtoupper((string)$coverage['id_home_membership_version']['IS_NULLABLE']) !== 'NO'
            || strtoupper((string)$coverage['supersedes_id']['IS_NULLABLE']) !== 'YES'
            || strtoupper((string)$coverage['approved_by_usuario_id']['IS_NULLABLE']) !== 'YES'
            || strtoupper((string)$coverage['approved_at_utc']['IS_NULLABLE']) !== 'YES') {
            throw new RuntimeException('WORKPLACE_COVERAGE_SCHEMA_UNAVAILABLE');
        }

        $assignments = self::columns($db, 'op_rh_v2_turnos_asignaciones');
        if (!self::unsignedIntegerColumnTypeMatches((string)$assignments['id_cobertura_version']['COLUMN_TYPE'], 'bigint')
            || strtoupper((string)$assignments['id_cobertura_version']['IS_NULLABLE']) !== 'YES'
            || !self::unsignedIntegerColumnTypeMatches((string)$assignments['id_plantilla']['COLUMN_TYPE'], 'int')
            || strtoupper((string)$assignments['id_plantilla']['IS_NULLABLE']) !== 'YES'
            || !self::enumContains($assignments['tipo_asignacion']['COLUMN_TYPE'], ['TEMPORAL'])
            || !self::enumContains($assignments['dia_semana']['COLUMN_TYPE'], [
                'LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO', 'DOMINGO',
            ])) {
            throw new RuntimeException('WORKPLACE_COVERAGE_SCHEMA_UNAVAILABLE');
        }

        $audit = self::columns($db, 'op_rh_v2_turnos_audit_log');
        if (!self::enumContains($audit['entity_type']['COLUMN_TYPE'], ['WORKPLACE_COVERAGE'])) {
            throw new RuntimeException('WORKPLACE_COVERAGE_SCHEMA_UNAVAILABLE');
        }

        if (!self::hasUniqueIndex($db, 'op_rh_v2_worker_workplace_coverage_versions', ['id'])
            || !self::hasUniqueIndex($db, 'op_rh_v2_worker_workplace_coverage_versions', ['coverage_uuid', 'revision_no'])
            || !self::hasUniqueIndex($db, 'op_rh_v2_worker_workplace_coverage_versions', ['idempotency_key_hash'])
            || !self::hasForeignKey($db, 'op_rh_v2_worker_workplace_coverage_versions', 'supersedes_id', 'op_rh_v2_worker_workplace_coverage_versions')
            || !self::hasForeignKey($db, 'op_rh_v2_worker_workplace_coverage_versions', 'id_personal', 'op_rh_personal')
            || !self::hasForeignKey($db, 'op_rh_v2_worker_workplace_coverage_versions', 'id_home_workplace', 'op_rh_localidades')
            || !self::hasForeignKey($db, 'op_rh_v2_worker_workplace_coverage_versions', 'id_home_membership_version', 'op_rh_personal_localidad_version')
            || !self::hasForeignKey($db, 'op_rh_v2_worker_workplace_coverage_versions', 'id_coverage_workplace', 'op_rh_localidades')
            || !self::hasForeignKey($db, 'op_rh_v2_worker_workplace_coverage_versions', 'requested_by_usuario_id', 'tb_usuarios')
            || !self::hasForeignKey($db, 'op_rh_v2_worker_workplace_coverage_versions', 'transition_actor_usuario_id', 'tb_usuarios')
            || !self::hasForeignKey($db, 'op_rh_v2_worker_workplace_coverage_versions', 'approved_by_usuario_id', 'tb_usuarios')
            || !self::hasForeignKey($db, 'op_rh_v2_turnos_asignaciones', 'id_cobertura_version', 'op_rh_v2_worker_workplace_coverage_versions')
            || !self::hasIndex($db, 'op_rh_v2_turnos_asignaciones', ['id_cobertura_version'])) {
            throw new RuntimeException('WORKPLACE_COVERAGE_SCHEMA_UNAVAILABLE');
        }
    }

    /** @return array<string,array{COLUMN_TYPE:string,IS_NULLABLE:string}> */
    private static function columns(mysqli $db, string $table): array
    {
        $stmt = $db->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        if (!$stmt) throw new RuntimeException('WORKPLACE_COVERAGE_SCHEMA_UNAVAILABLE');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $result = [];
        foreach ($rows as $row) $result[(string)$row['COLUMN_NAME']] = $row;
        if ($result === []) throw new RuntimeException('WORKPLACE_COVERAGE_SCHEMA_UNAVAILABLE');
        return $result;
    }

    private static function engine(mysqli $db, string $table): string
    {
        $stmt = $db->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        if (!$stmt) throw new RuntimeException('WORKPLACE_COVERAGE_SCHEMA_UNAVAILABLE');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return strtoupper((string)($row['ENGINE'] ?? ''));
    }

    /** MariaDB information_schema.COLUMN_TYPE includes display width (e.g. int(10) unsigned). */
    public static function unsignedIntegerColumnTypeMatches(string $actual, string $expectedBaseType): bool
    {
        $expectedBaseType = strtolower(trim($expectedBaseType));
        if (!in_array($expectedBaseType, ['int', 'bigint'], true)) return false;
        return preg_match('/\A' . preg_quote($expectedBaseType, '/') . '(?:\([0-9]+\))?\s+unsigned\z/i', trim($actual)) === 1;
    }

    private static function enumContains(string $columnType, array $values): bool
    {
        foreach ($values as $value) {
            if (!str_contains($columnType, "'{$value}'")) return false;
        }
        return true;
    }

    private static function hasUniqueIndex(mysqli $db, string $table, array $columns): bool
    {
        $stmt = $db->prepare(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_order '
            . 'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            . 'AND NON_UNIQUE = 0 GROUP BY INDEX_NAME'
        );
        if (!$stmt) throw new RuntimeException('WORKPLACE_COVERAGE_SCHEMA_UNAVAILABLE');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $expected = implode(',', $columns);
        foreach ($rows as $row) if ((string)$row['columns_in_order'] === $expected) return true;
        return false;
    }

    private static function hasIndex(mysqli $db, string $table, array $columns): bool
    {
        $stmt = $db->prepare(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_order '
            . 'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            . 'GROUP BY INDEX_NAME'
        );
        if (!$stmt) throw new RuntimeException('WORKPLACE_COVERAGE_SCHEMA_UNAVAILABLE');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $expected = implode(',', $columns);
        foreach ($rows as $row) if ((string)$row['columns_in_order'] === $expected) return true;
        return false;
    }

    private static function hasForeignKey(mysqli $db, string $table, string $column, string $referencedTable, string $referencedColumn = 'id'): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ? '
            . 'AND REFERENCED_COLUMN_NAME = ? LIMIT 1'
        );
        if (!$stmt) throw new RuntimeException('WORKPLACE_COVERAGE_SCHEMA_UNAVAILABLE');
        $stmt->bind_param('ssss', $table, $column, $referencedTable, $referencedColumn);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        return $exists;
    }
}
