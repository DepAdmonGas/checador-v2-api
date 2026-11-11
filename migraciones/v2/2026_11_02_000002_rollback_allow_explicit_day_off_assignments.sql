-- Safe rollback only when no explicit day-off assignments exist. This ALTER
-- intentionally fails if NULL rows are present; do not delete operational
-- schedule records to make rollback pass.
ALTER TABLE `op_rh_v2_turnos_asignaciones`
  MODIFY COLUMN `id_plantilla` INT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_turnos_plantillas';
