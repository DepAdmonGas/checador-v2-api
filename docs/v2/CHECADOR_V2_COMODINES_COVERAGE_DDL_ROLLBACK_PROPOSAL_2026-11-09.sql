-- CHECADOR V2 Comodines coverage schema rollback proposal.
-- PROPOSAL ONLY. DO NOT EXECUTE without separate rollback authorization.
-- MariaDB DDL below commits statement by statement; this rollback is not atomic.
-- Confirm feature disabled and API traffic quiesced before checking eligibility.

-- ============================= ROLLBACK PREFLIGHT (READ ONLY) =============================
SELECT DATABASE() AS actual_database, VERSION() AS mariadb_version,
  CASE WHEN DATABASE() = 'bd_admongas' AND VERSION() LIKE '11.8.8-MariaDB%'
    THEN 'LOCAL_TARGET' ELSE 'STOP' END AS environment_check;
SELECT
  (SELECT COUNT(*) FROM op_rh_v2_worker_workplace_coverage_versions) AS coverage_rows,
  (SELECT COUNT(*) FROM op_rh_v2_turnos_asignaciones WHERE id_cobertura_version IS NOT NULL) AS linked_assignments,
  (SELECT COUNT(*) FROM op_rh_v2_turnos_audit_log WHERE entity_type = 'WORKPLACE_COVERAGE') AS coverage_audit_events,
  (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
    WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
      AND REFERENCED_TABLE_NAME = 'op_rh_v2_worker_workplace_coverage_versions'
      AND NOT (TABLE_NAME = 'op_rh_v2_turnos_asignaciones' AND COLUMN_NAME = 'id_cobertura_version')) AS other_foreign_key_dependents,
  CASE WHEN
    (SELECT COUNT(*) FROM op_rh_v2_worker_workplace_coverage_versions) = 0 AND
    (SELECT COUNT(*) FROM op_rh_v2_turnos_asignaciones WHERE id_cobertura_version IS NOT NULL) = 0 AND
    (SELECT COUNT(*) FROM op_rh_v2_turnos_audit_log WHERE entity_type = 'WORKPLACE_COVERAGE') = 0 AND
    (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
      WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
        AND REFERENCED_TABLE_NAME = 'op_rh_v2_worker_workplace_coverage_versions'
        AND NOT (TABLE_NAME = 'op_rh_v2_turnos_asignaciones' AND COLUMN_NAME = 'id_cobertura_version')) = 0
  THEN 'ROLLBACK_SCHEMA_ONLY_ALLOWED_FOR_REVIEW' ELSE 'STOP_PRESERVE_DATA' END AS rollback_gate;

-- ============================= DDL (NOT EXECUTED) =============================
-- Continue only if preflight reports the exact local target and rollback gate allows it.
ALTER TABLE op_rh_v2_turnos_asignaciones
  DROP FOREIGN KEY fk_shift_assignment_coverage,
  DROP INDEX idx_shift_assignment_coverage,
  DROP COLUMN id_cobertura_version;

ALTER TABLE op_rh_v2_turnos_audit_log
  MODIFY COLUMN entity_type ENUM('TEMPLATE','ASSIGNMENT') NOT NULL;

DROP TABLE op_rh_v2_worker_workplace_coverage_versions;

-- ============================= POSTFLIGHT (READ ONLY) =============================
SELECT
  (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'op_rh_v2_worker_workplace_coverage_versions') AS coverage_table_remains,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'op_rh_v2_turnos_asignaciones'
      AND COLUMN_NAME = 'id_cobertura_version') AS assignment_link_column_remains,
  (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'op_rh_v2_turnos_audit_log'
      AND COLUMN_NAME = 'entity_type') AS audit_type_after_rollback;

-- Partial rollback failure: stop; inspect exact DDL state. Do not repeat blindly. Never remove
-- data-bearing coverage revisions or linked assignments; export and retain audit evidence.
