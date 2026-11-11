-- Align the deployed assignment column with Shift Scheduling Service API
-- Contract V1.0, which defines an explicit DAY_OFF assignment as
-- id_plantilla = NULL. Existing rows and foreign keys are preserved.
ALTER TABLE `op_rh_v2_turnos_asignaciones`
  MODIFY COLUMN `id_plantilla` INT UNSIGNED NULL COMMENT 'NULL = explicit day off; otherwise FK to op_rh_v2_turnos_plantillas';
