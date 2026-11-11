-- M11 additive development authorization and secret-free configuration audit.
-- Run only after confirming the target is local bd_admongas development.
CREATE TABLE IF NOT EXISTS `op_rh_v2_m11_configuration_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_portal_user_id` INT UNSIGNED NOT NULL,
  `permission_key` VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `workplace_id` INT UNSIGNED NULL,
  `entity_type` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `entity_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_m11_configuration_audit_scope` (`workplace_id`, `created_at_utc`),
  KEY `idx_m11_configuration_audit_entity` (`entity_type`, `entity_id`, `created_at_utc`),
  KEY `idx_m11_configuration_audit_actor` (`actor_portal_user_id`, `created_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dedicated M11 grants. INSERT IGNORE preserves existing direct/user decisions.
INSERT IGNORE INTO `op_rh_v2_permission_grants`
  (`permission_key`, `principal_type`, `principal_id`, `granted`, `active`, `reason`, `source_task`)
VALUES
  ('checador.configuracion.leer', 'POSITION', 6, 1, 1,
   'M11: lectura y edición de reglas limitadas a estaciones autorizadas; autorización funcional del usuario.',
   'CHECADOR_V2_M11_COMPLETE_CONFIGURATION_V1'),
  ('checador.configuracion.retardo_incidencias.editar', 'POSITION', 6, 1, 1,
   'M11: edición prospectiva de reglas de retardo/incidencias en su localidad autorizada.',
   'CHECADOR_V2_M11_COMPLETE_CONFIGURATION_V1'),
  ('checador.configuracion.lectores.leer', 'POSITION', 6, 1, 1,
   'M11: lectura de la credencial de lector de su localidad autorizada; sin exposición de contraseñas.',
   'CHECADOR_V2_M11_COMPLETE_CONFIGURATION_V1'),
  ('checador.configuracion.lectores.editar', 'POSITION', 6, 1, 1,
   'M11: actualización/rotación de credencial de lector únicamente en su localidad autorizada.',
   'CHECADOR_V2_M11_COMPLETE_CONFIGURATION_V1'),
  ('checador.configuracion.lectores.leer', 'POSITION', 13, 1, 1,
   'M11: lectura de credenciales de lector en localidades autorizadas; sin edición ni exposición de contraseñas.',
   'CHECADOR_V2_M11_COMPLETE_CONFIGURATION_V1'),
  ('checador.configuracion.puestos.leer', 'POSITION', 13, 1, 1,
   'M11: consulta del catálogo institucional de puestos.',
   'CHECADOR_V2_M11_COMPLETE_CONFIGURATION_V1'),
  ('checador.configuracion.puestos.editar', 'POSITION', 13, 1, 1,
   'M11: alta y edición del catálogo institucional de puestos.',
   'CHECADOR_V2_M11_COMPLETE_CONFIGURATION_V1');
