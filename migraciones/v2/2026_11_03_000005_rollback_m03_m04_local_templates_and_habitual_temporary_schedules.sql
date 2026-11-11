-- Rollback for 2026_11_03_000005_m03_m04_local_templates_and_habitual_temporary_schedules.sql

DELETE FROM `op_rh_v2_permission_grants`
WHERE `permission_key` = 'checador.turnos.estacion.administrar' AND `principal_type` = 'POSITION' AND `principal_id` = 6;

DROP TABLE IF EXISTS `op_rh_v2_turnos_audit_log`;

ALTER TABLE `op_rh_v2_turnos_asignaciones`
  DROP KEY IF EXISTS `idx_v2_asig_personal_tipo`,
  DROP KEY IF EXISTS `idx_v2_asig_tipo`,
  DROP COLUMN IF EXISTS `motivo`,
  DROP COLUMN IF EXISTS `actualizado_por_usuario_id`,
  DROP COLUMN IF EXISTS `creado_por_usuario_id`,
  DROP COLUMN IF EXISTS `tipo_asignacion`;

ALTER TABLE `op_rh_v2_turnos_plantillas`
  DROP KEY IF EXISTS `idx_v2_turnos_plantillas_scope_estacion`,
  DROP KEY IF EXISTS `uk_v2_turnos_plantillas_scope_clave_version`,
  ADD UNIQUE KEY `uk_v2_turnos_plantillas_clave_version` (`clave`, `version`),
  DROP COLUMN IF EXISTS `scope_id`,
  DROP COLUMN IF EXISTS `creado_por_usuario_id`,
  DROP COLUMN IF EXISTS `id_estacion`,
  DROP COLUMN IF EXISTS `scope`;
