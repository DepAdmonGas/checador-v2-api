-- M06 append-only link from a P3G-B punch-attendance incidence to the
-- declared local check and the exact versioned attendance policy.
CREATE TABLE IF NOT EXISTS `op_rh_v2_incidencia_chequeos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_incidencia_maestro` BIGINT UNSIGNED NOT NULL,
  `policy_version_id` BIGINT UNSIGNED NOT NULL,
  `fecha_chequeo_local` DATE NOT NULL,
  `timezone_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `limite_registro_local` DATETIME NOT NULL,
  `tipo_chequeo` ENUM('ENTRY','LUNCH_EXIT','LUNCH_RETURN','FINAL_EXIT') NOT NULL,
  `naturaleza` ENUM('LATENESS','OMISSION') NOT NULL,
  `source_event_device_id` INT NULL,
  `source_event_uuid` VARCHAR(64) NULL,
  `created_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_punch_check_incidence` (`id_incidencia_maestro`),
  KEY `idx_punch_check_policy_date` (`policy_version_id`, `fecha_chequeo_local`),
  KEY `idx_punch_check_event` (`source_event_device_id`, `source_event_uuid`),
  CONSTRAINT `fk_punch_check_incidence` FOREIGN KEY (`id_incidencia_maestro`)
    REFERENCES `op_rh_v2_incidencias_maestro` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_punch_check_policy` FOREIGN KEY (`policy_version_id`)
    REFERENCES `op_rh_v2_attendance_policy_versions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_punch_check_event` FOREIGN KEY (`source_event_device_id`, `source_event_uuid`)
    REFERENCES `op_rh_v2_marcaciones_crudas` (`id_dispositivo`, `event_uuid`) ON DELETE RESTRICT,
  CONSTRAINT `chk_punch_check_event_pair` CHECK (
    (`source_event_device_id` IS NULL AND `source_event_uuid` IS NULL)
    OR (`source_event_device_id` IS NOT NULL AND `source_event_uuid` IS NOT NULL)
  ),
  CONSTRAINT `chk_punch_check_nature_source` CHECK (
    (`naturaleza` = 'LATENESS' AND `source_event_device_id` IS NOT NULL)
    OR (`naturaleza` = 'OMISSION' AND `source_event_device_id` IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
