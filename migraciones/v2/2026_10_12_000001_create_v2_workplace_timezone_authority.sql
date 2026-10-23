-- Checador V2 Workplace Timezone Authority V1.0
-- Additive, effective-dated authority. No business mappings are inserted here.

CREATE TABLE IF NOT EXISTS `op_rh_v2_localidades_zonas_horarias` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_localidad` INT(11) NOT NULL,
  `timezone_iana` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `valido_desde` DATE NOT NULL,
  `valido_hasta` DATE NULL DEFAULT NULL,
  `registrado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_localidad_timezone_desde` (`id_localidad`, `valido_desde`),
  CONSTRAINT `fk_v2_localidad_timezone_localidad`
    FOREIGN KEY (`id_localidad`) REFERENCES `op_rh_localidades` (`id`)
    ON DELETE RESTRICT,
  CONSTRAINT `chk_v2_localidad_timezone_interval`
    CHECK (`valido_hasta` IS NULL OR `valido_hasta` > `valido_desde`),
  CONSTRAINT `chk_v2_localidad_timezone_nonempty`
    CHECK (CHAR_LENGTH(`timezone_iana`) BETWEEN 1 AND 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
