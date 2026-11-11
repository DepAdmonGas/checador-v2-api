-- Checador V2 — M03/M04 Local Templates and Habitual/Temporary Schedule Addendum V1.0
-- Additive migration for local station templates, habitual/temporary schedule assignments, and audit logging.

-- 1. Add scope, canonical station, and creator to templates
ALTER TABLE `op_rh_v2_turnos_plantillas`
  ADD COLUMN `scope` ENUM('GLOBAL', 'STATION') NOT NULL DEFAULT 'GLOBAL' COMMENT 'Scope of template: GLOBAL or STATION' AFTER `tipo_turno`,
  ADD COLUMN `id_estacion` INT NULL DEFAULT NULL COMMENT 'Canonical station ID for STATION scope; NULL for GLOBAL' AFTER `scope`,
  ADD COLUMN `creado_por_usuario_id` INT NULL DEFAULT NULL COMMENT 'Actor derived from validated Portal3 JWT user_id' AFTER `es_activo`,
  ADD COLUMN `scope_id` INT GENERATED ALWAYS AS (COALESCE(`id_estacion`, 0)) STORED AFTER `id_estacion`;

-- Update uniqueness: drop old key and add composite key with scope_id
ALTER TABLE `op_rh_v2_turnos_plantillas`
  DROP INDEX `uk_v2_turnos_plantillas_clave_version`,
  ADD UNIQUE KEY `uk_v2_turnos_plantillas_scope_clave_version` (`scope_id`, `clave`, `version`),
  ADD KEY `idx_v2_turnos_plantillas_scope_estacion` (`scope`, `id_estacion`);

-- 2. Add assignment kind, actor provenance, and notes to shift assignments
ALTER TABLE `op_rh_v2_turnos_asignaciones`
  ADD COLUMN `tipo_asignacion` ENUM('HABITUAL', 'TEMPORAL') NOT NULL DEFAULT 'HABITUAL' COMMENT 'Assignment kind: HABITUAL or TEMPORAL' AFTER `id_plantilla`,
  ADD COLUMN `creado_por_usuario_id` INT NULL DEFAULT NULL COMMENT 'Actor derived from validated Portal3 JWT user_id' AFTER `registrado_at_utc`,
  ADD COLUMN `actualizado_por_usuario_id` INT NULL DEFAULT NULL COMMENT 'Actor updating or closing assignment' AFTER `creado_por_usuario_id`,
  ADD COLUMN `motivo` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Optional reason for assignment or temporary exception' AFTER `actualizado_por_usuario_id`,
  ADD KEY `idx_v2_asig_tipo` (`tipo_asignacion`),
  ADD KEY `idx_v2_asig_personal_tipo` (`id_personal`, `tipo_asignacion`, `es_activo`);

-- 3. Audit log table for immutable as-recorded provenance
CREATE TABLE IF NOT EXISTS `op_rh_v2_turnos_audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type` ENUM('TEMPLATE', 'ASSIGNMENT') NOT NULL,
  `entity_id` BIGINT UNSIGNED NOT NULL,
  `operacion` VARCHAR(32) NOT NULL COMMENT 'CREATE, VERSION, CLOSE, REPLACE, CORRECTION',
  `id_usuario_actor` INT NOT NULL COMMENT 'Actor user ID derived strictly from JWT',
  `id_estacion` INT NOT NULL COMMENT 'Canonical station',
  `id_personal` INT NULL COMMENT 'Worker ID if assignment',
  `tipo_asignacion` ENUM('HABITUAL', 'TEMPORAL') NULL,
  `valido_desde` DATE NULL,
  `valido_hasta` DATE NULL,
  `id_plantilla` INT UNSIGNED NULL,
  `version_plantilla` INT UNSIGNED NULL,
  `detalle_json` JSON NULL,
  `recorded_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_v2_turnos_audit_entity` (`entity_type`, `entity_id`),
  KEY `idx_v2_turnos_audit_worker` (`id_personal`, `recorded_at_utc`),
  KEY `idx_v2_turnos_audit_station` (`id_estacion`, `recorded_at_utc`),
  KEY `idx_v2_turnos_audit_actor` (`id_usuario_actor`, `recorded_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Permission grant: checador.turnos.estacion.administrar for Encargado (position 6)
INSERT INTO `op_rh_v2_permission_grants`
  (`permission_key`, `principal_type`, `principal_id`, `granted`, `active`, `reason`, `source_task`, `created_at_utc`, `updated_at_utc`)
VALUES
  ('checador.turnos.estacion.administrar', 'POSITION', 6, 1, 1,
   'M03/M04: administración de plantillas de turno locales limitada a su estación autorizada.',
   'CHECADOR_V2_M03_M04_CONTRACT_APPROVAL_AND_IMPLEMENTATION_V1', UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE `granted` = 1, `active` = 1, `updated_at_utc` = UTC_TIMESTAMP();
