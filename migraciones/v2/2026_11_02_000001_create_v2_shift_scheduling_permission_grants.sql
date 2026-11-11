-- M03 authorization addendum, development migration.
-- This ACL is independent of biometricos.* and is not a navigable module.
-- Re-running CREATE TABLE / INSERT IGNORE is safe and does not reactivate a
-- grant that an administrator later revoked.
CREATE TABLE IF NOT EXISTS `op_rh_v2_permission_grants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `permission_key` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `principal_type` ENUM('POSITION', 'USER') NOT NULL,
  `principal_id` INT UNSIGNED NOT NULL,
  `granted` TINYINT(1) NOT NULL DEFAULT 1,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `reason` VARCHAR(255) NOT NULL,
  `source_task` VARCHAR(96) NOT NULL,
  `updated_by_user_id` INT UNSIGNED NULL,
  `created_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_permission_principal` (`permission_key`, `principal_type`, `principal_id`),
  KEY `idx_v2_permission_active` (`permission_key`, `active`, `granted`),
  CONSTRAINT `chk_v2_permission_principal_positive` CHECK (`principal_id` > 0),
  CONSTRAINT `chk_v2_permission_boolean_values` CHECK (`granted` IN (0, 1) AND `active` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `op_rh_v2_permission_grants`
  (`permission_key`, `principal_type`, `principal_id`, `granted`, `active`, `reason`, `source_task`)
VALUES
  ('checador.programacion.leer', 'POSITION', 6, 1, 1, 'Consulta de programación limitada al centro físico propio y su localidad canónica.', 'CHECADOR_V2_M03_STATION_SCHEDULING_AUTHORIZATION_V1'),
  ('checador.programacion.editar', 'POSITION', 6, 1, 1, 'Captura de programación limitada al centro físico propio, trabajador y vigencia laboral verificados.', 'CHECADOR_V2_M03_STATION_SCHEDULING_AUTHORIZATION_V1'),
  ('checador.programacion.leer', 'POSITION', 13, 1, 1, 'Supervisión de programación en las estaciones incluidas en el alcance autorizado.', 'CHECADOR_V2_M03_STATION_SCHEDULING_AUTHORIZATION_V1');
