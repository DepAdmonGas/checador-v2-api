-- M04 global-template station customization lineage (additive, development only).
-- The record identifies the immutable global source version and local logical template.
-- It deliberately does not alter templates, assignments, or global catalog rows.
CREATE TABLE IF NOT EXISTS `op_rh_v2_shift_template_station_lineage` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_estacion` INT NOT NULL,
  `source_global_clave` VARCHAR(100) NOT NULL,
  `source_global_version` INT UNSIGNED NOT NULL,
  `source_global_template_id` INT UNSIGNED NOT NULL,
  `local_clave` VARCHAR(100) NOT NULL,
  `local_template_id` INT UNSIGNED NOT NULL,
  `created_by_user_id` INT NOT NULL,
  `created_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_v2_shift_lineage_station_source` (`id_estacion`, `source_global_clave`),
  UNIQUE KEY `uq_v2_shift_lineage_station_local` (`id_estacion`, `local_clave`),
  UNIQUE KEY `uq_v2_shift_lineage_local_template` (`local_template_id`),
  KEY `idx_v2_shift_lineage_source_version` (`source_global_template_id`, `source_global_version`),
  CONSTRAINT `fk_v2_shift_lineage_source_template`
    FOREIGN KEY (`source_global_template_id`) REFERENCES `op_rh_v2_turnos_plantillas` (`id`),
  CONSTRAINT `fk_v2_shift_lineage_local_template`
    FOREIGN KEY (`local_template_id`) REFERENCES `op_rh_v2_turnos_plantillas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
