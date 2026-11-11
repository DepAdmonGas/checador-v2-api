-- CHECADOR V2 Comodines coverage schema proposal, based on read-only bd_admongas inspection 2026-11-09.
-- APPROVED CONTRACT SHA-256: 0cd553e2dbb3eac43a9a221832bcdb5c51aa0a0d7f36b3af1f7e27eff10ef618
-- PROPOSAL ONLY. NOT A RUNNABLE MIGRATION ORDER. DO NOT EXECUTE without separate migration approval.
-- MariaDB 11.8.8 DDL is not atomic across these statements and may implicitly commit.
-- Run the read-only PREFLIGHT section separately. Continue only if every check says READY.
-- Keep CHECADOR_V2_WORKPLACE_COVERAGE_ENABLED unset/disabled and stop API writes during any future approved execution.

-- ============================= PREFLIGHT (READ ONLY) =============================
SELECT
  DATABASE() AS actual_database,
  VERSION() AS mariadb_version,
  CASE WHEN DATABASE() = 'bd_admongas' AND VERSION() LIKE '11.8.8-MariaDB%'
    THEN 'READY' ELSE 'STOP' END AS environment_check;

SELECT 'coverage_table_absent' AS check_name,
  CASE WHEN COUNT(*) = 0 THEN 'READY' ELSE 'STOP' END AS result
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'op_rh_v2_worker_workplace_coverage_versions'
UNION ALL
SELECT 'assignment_link_column_absent',
  CASE WHEN COUNT(*) = 0 THEN 'READY' ELSE 'STOP' END
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'op_rh_v2_turnos_asignaciones' AND COLUMN_NAME = 'id_cobertura_version'
UNION ALL
SELECT 'audit_enum_is_unextended',
  CASE WHEN COUNT(*) = 1 THEN 'READY' ELSE 'STOP' END
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'op_rh_v2_turnos_audit_log'
  AND COLUMN_NAME = 'entity_type' AND LOWER(COLUMN_TYPE) = 'enum(''template'',''assignment'')'
UNION ALL
SELECT 'membership_pk_unsigned_int',
  CASE WHEN COUNT(*) = 1 THEN 'READY' ELSE 'STOP' END
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'op_rh_personal_localidad_version'
  AND COLUMN_NAME = 'id' AND LOWER(COLUMN_TYPE) = 'int(10) unsigned' AND COLUMN_KEY = 'PRI'
UNION ALL
SELECT 'all_referenced_column_types_and_primary_keys_match',
  CASE WHEN COUNT(*) = 15 THEN 'READY' ELSE 'STOP' END
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND (
  (TABLE_NAME = 'op_rh_personal' AND COLUMN_NAME = 'id' AND COLUMN_TYPE = 'int(11)' AND COLUMN_KEY = 'PRI') OR
  (TABLE_NAME = 'op_rh_localidades' AND COLUMN_NAME = 'id' AND COLUMN_TYPE = 'int(11)' AND COLUMN_KEY = 'PRI') OR
  (TABLE_NAME = 'tb_estaciones' AND COLUMN_NAME = 'id' AND COLUMN_TYPE = 'int(11)' AND COLUMN_KEY = 'PRI') OR
  (TABLE_NAME = 'tb_usuarios' AND COLUMN_NAME = 'id' AND COLUMN_TYPE = 'int(11)' AND COLUMN_KEY = 'PRI') OR
  (TABLE_NAME = 'op_rh_personal_localidad_version' AND COLUMN_NAME = 'id' AND COLUMN_TYPE = 'int(10) unsigned' AND COLUMN_KEY = 'PRI') OR
  (TABLE_NAME = 'op_rh_personal_localidad_version' AND COLUMN_NAME IN ('worker_id','workplace_id') AND COLUMN_TYPE = 'int(11)') OR
  (TABLE_NAME = 'op_rh_v2_turnos_plantillas' AND COLUMN_NAME = 'id' AND COLUMN_TYPE = 'int(10) unsigned' AND COLUMN_KEY = 'PRI') OR
  (TABLE_NAME = 'op_rh_v2_turnos_asignaciones' AND COLUMN_NAME = 'id' AND COLUMN_TYPE = 'bigint(20) unsigned' AND COLUMN_KEY = 'PRI') OR
  (TABLE_NAME = 'op_rh_v2_turnos_asignaciones' AND COLUMN_NAME IN ('id_personal','id_estacion') AND COLUMN_TYPE = 'int(11)') OR
  (TABLE_NAME = 'op_rh_v2_turnos_asignaciones' AND COLUMN_NAME = 'id_plantilla' AND COLUMN_TYPE = 'int(10) unsigned') OR
  (TABLE_NAME = 'op_rh_v2_turnos_audit_log' AND COLUMN_NAME = 'id' AND COLUMN_TYPE = 'bigint(20) unsigned' AND COLUMN_KEY = 'PRI') OR
  (TABLE_NAME = 'op_rh_v2_turnos_audit_log' AND COLUMN_NAME IN ('entity_id') AND COLUMN_TYPE = 'bigint(20) unsigned') OR
  (TABLE_NAME = 'op_rh_v2_turnos_audit_log' AND COLUMN_NAME = 'id_usuario_actor' AND COLUMN_TYPE = 'int(11)')
)
UNION ALL
SELECT 'assignment_and_audit_tables_innodb',
  CASE WHEN COUNT(*) = 2 THEN 'READY' ELSE 'STOP' END
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND ENGINE = 'InnoDB'
  AND TABLE_NAME IN ('op_rh_v2_turnos_asignaciones','op_rh_v2_turnos_audit_log')
UNION ALL
SELECT 'canonical_fk_targets_innodb',
  CASE WHEN COUNT(*) = 5 THEN 'READY' ELSE 'STOP' END
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND ENGINE = 'InnoDB'
  AND TABLE_NAME IN ('op_rh_personal','op_rh_localidades','op_rh_personal_localidad_version','tb_estaciones','tb_usuarios')
UNION ALL
SELECT 'constraint_names_available',
  CASE WHEN COUNT(*) = 0 THEN 'READY' ELSE 'STOP' END
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME IN (
  'fk_coverage_supersedes','fk_coverage_worker','fk_coverage_home_workplace',
  'fk_coverage_home_membership','fk_coverage_target_workplace','fk_coverage_home_station',
  'fk_coverage_target_station','fk_coverage_request_actor','fk_coverage_approve_actor',
  'fk_coverage_transition_actor','fk_shift_assignment_coverage',
  'chk_coverage_valid_range','chk_coverage_distinct_workplaces'
)
UNION ALL
SELECT 'assignment_index_name_available',
  CASE WHEN COUNT(*) = 0 THEN 'READY' ELSE 'STOP' END
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'op_rh_v2_turnos_asignaciones'
  AND INDEX_NAME = 'idx_shift_assignment_coverage';

-- Verify signed/unsigned target types and primary keys before any DDL.
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND (
  (TABLE_NAME IN ('op_rh_personal','op_rh_localidades','tb_estaciones','tb_usuarios') AND COLUMN_NAME = 'id') OR
  (TABLE_NAME = 'op_rh_personal_localidad_version' AND COLUMN_NAME IN ('id','worker_id','workplace_id')) OR
  (TABLE_NAME = 'op_rh_v2_turnos_asignaciones' AND COLUMN_NAME IN ('id','id_personal','id_estacion','id_plantilla')) OR
  (TABLE_NAME = 'op_rh_v2_turnos_audit_log' AND COLUMN_NAME IN ('id','entity_type'))
)
ORDER BY TABLE_NAME, COLUMN_NAME;

-- ============================= DDL (NOT EXECUTED) =============================
-- Execute only after independent review and a separate explicit authorization.
-- DDL below intentionally has no START TRANSACTION: MariaDB DDL implicitly commits.
CREATE TABLE op_rh_v2_worker_workplace_coverage_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    coverage_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    supersedes_id BIGINT UNSIGNED NULL,
    id_personal INT NOT NULL,
    id_home_workplace INT NOT NULL,
    id_home_membership_version INT UNSIGNED NOT NULL,
    id_coverage_workplace INT NOT NULL,
    id_home_physical_station INT NULL,
    id_coverage_physical_station INT NULL,
    fecha_desde_local DATE NOT NULL,
    fecha_hasta_local_exclusiva DATE NOT NULL,
    timezone_iana VARCHAR(64) CHARACTER SET ascii NOT NULL,
    proposed_schedule_json JSON NOT NULL,
    status ENUM('REQUESTED','AUTHORIZED','REJECTED','CANCELLED') NOT NULL,
    requested_by_usuario_id INT NOT NULL,
    requested_at_utc DATETIME(6) NOT NULL,
    approved_by_usuario_id INT NULL,
    approved_at_utc DATETIME(6) NULL,
    transition_actor_usuario_id INT NOT NULL,
    transition_action ENUM('REQUEST','AUTHORIZE','REJECT','CANCEL','REVISE') NOT NULL,
    motivo_solicitud VARCHAR(500) NOT NULL,
    motivo_transicion VARCHAR(500) NULL,
    idempotency_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recorded_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_coverage_uuid_revision (coverage_uuid, revision_no),
    UNIQUE KEY uk_coverage_idempotency (idempotency_key_hash),
    KEY idx_coverage_worker_window (id_personal, status, fecha_desde_local, fecha_hasta_local_exclusiva),
    KEY idx_coverage_home_target (id_home_workplace, id_coverage_workplace, status),
    KEY idx_coverage_target_scope (id_coverage_workplace, status, fecha_desde_local, fecha_hasta_local_exclusiva),
    KEY idx_coverage_supersedes (supersedes_id),
    KEY idx_coverage_home_membership (id_home_membership_version),
    KEY idx_coverage_home_physical_station (id_home_physical_station),
    KEY idx_coverage_target_physical_station (id_coverage_physical_station),
    KEY idx_coverage_requested_by (requested_by_usuario_id),
    KEY idx_coverage_approved_by (approved_by_usuario_id),
    KEY idx_coverage_transition_actor (transition_actor_usuario_id),
    KEY idx_coverage_recorded (recorded_at_utc),
    CONSTRAINT chk_coverage_valid_range CHECK (fecha_hasta_local_exclusiva > fecha_desde_local),
    CONSTRAINT chk_coverage_distinct_workplaces CHECK (id_home_workplace <> id_coverage_workplace),
    CONSTRAINT fk_coverage_supersedes FOREIGN KEY (supersedes_id)
      REFERENCES op_rh_v2_worker_workplace_coverage_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_worker FOREIGN KEY (id_personal)
      REFERENCES op_rh_personal (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_home_workplace FOREIGN KEY (id_home_workplace)
      REFERENCES op_rh_localidades (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_home_membership FOREIGN KEY (id_home_membership_version)
      REFERENCES op_rh_personal_localidad_version (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_target_workplace FOREIGN KEY (id_coverage_workplace)
      REFERENCES op_rh_localidades (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_home_station FOREIGN KEY (id_home_physical_station)
      REFERENCES tb_estaciones (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_target_station FOREIGN KEY (id_coverage_physical_station)
      REFERENCES tb_estaciones (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_request_actor FOREIGN KEY (requested_by_usuario_id)
      REFERENCES tb_usuarios (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_approve_actor FOREIGN KEY (approved_by_usuario_id)
      REFERENCES tb_usuarios (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_transition_actor FOREIGN KEY (transition_actor_usuario_id)
      REFERENCES tb_usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE op_rh_v2_turnos_asignaciones
    ADD COLUMN id_cobertura_version BIGINT UNSIGNED NULL,
    ADD KEY idx_shift_assignment_coverage (id_cobertura_version),
    ADD CONSTRAINT fk_shift_assignment_coverage FOREIGN KEY (id_cobertura_version)
      REFERENCES op_rh_v2_worker_workplace_coverage_versions (id) ON DELETE RESTRICT;

ALTER TABLE op_rh_v2_turnos_audit_log
    MODIFY COLUMN entity_type ENUM('TEMPLATE','ASSIGNMENT','WORKPLACE_COVERAGE') NOT NULL;

-- ============================= POSTFLIGHT (READ ONLY) =============================
SELECT DATABASE() AS actual_database, VERSION() AS mariadb_version;
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'op_rh_v2_worker_workplace_coverage_versions';
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND (
  (TABLE_NAME = 'op_rh_v2_turnos_asignaciones' AND COLUMN_NAME = 'id_cobertura_version') OR
  (TABLE_NAME = 'op_rh_v2_turnos_audit_log' AND COLUMN_NAME = 'entity_type')
);
SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, CONSTRAINT_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
  AND ((TABLE_NAME = 'op_rh_v2_worker_workplace_coverage_versions') OR
       (TABLE_NAME = 'op_rh_v2_turnos_asignaciones' AND COLUMN_NAME = 'id_cobertura_version'))
ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION;
SELECT COUNT(*) AS coverage_rows_after_schema_only FROM op_rh_v2_worker_workplace_coverage_versions;

-- ============================= PARTIAL-FAILURE RECOVERY =============================
-- MariaDB DDL is not one atomic transaction. If any DDL statement fails, stop immediately;
-- do not rerun the file and do not run the rollback blindly. Inspect information_schema to
-- determine which exact statements completed. Resume only missing statements after comparing
-- all existing object definitions with this approved proposal. If definitions differ, stop
-- and prepare a reviewed corrective migration. The feature stays disabled during recovery.
-- Rollback is allowed only for a schema-only installation with zero coverage rows, zero linked
-- shift assignments, zero WORKPLACE_COVERAGE audit events, and no downstream references.
-- See the companion *_rollback.sql. Do not drop or reinterpret operational rows.
