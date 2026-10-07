-- =============================================================================
-- MIGRACIÓN ADITIVA V2.4-P2M: IMPLEMENTACIÓN SCHEMA CALCULATION STORAGE
-- Submódulo Motor de Jornada V2: Tablas de Cálculo de Jornada y Detalles por Segmento
-- Spec Version: 1.0 (FROZEN_V1.0)
-- =============================================================================

-- 1. Tabla Header de Cálculo de Jornada (Calculation Revision Header)
CREATE TABLE IF NOT EXISTS `op_rh_v2_calculos_jornada` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_jornada_programada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_jornadas_programadas',
  `id_pareo_jornada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to explicit immutable op_rh_v2_pareos_jornada revision',
  `id_personal` INT NOT NULL COMMENT 'Worker ID snapshot',
  `id_estacion` INT NOT NULL COMMENT 'Workplace station ID snapshot',
  `fecha_jornada_local` DATE NOT NULL COMMENT 'Local work date snapshot (YYYY-MM-DD)',
  `revision_numero` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Monotonic revision number per scheduled shift',
  `version_algoritmo_calculo` VARCHAR(32) NOT NULL DEFAULT 'v2.4-p2' COMMENT 'Calculation algorithm version provenance',
  `estado_calculo` VARCHAR(32) NOT NULL COMMENT 'Calculation status (CALCULATED, PARTIAL_DATA, PENDING_SYNC, AMBIGUOUS, WORKPLACE_MISMATCH, UNMATCHED_NO_PUNCHES, INVALID_INTERVAL, DAY_OFF_RECORDED, UNSCHEDULED_RECORDED, UNKNOWN_SCHEDULE)',
  `segundos_programados_totales` INT UNSIGNED NULL COMMENT 'Total scheduled work seconds across all WORK segments (NULL for UNSCHEDULED/UNKNOWN)',
  `segundos_trabajados_totales` INT UNSIGNED NULL COMMENT 'Total actual worked seconds across complete WORK segments (NULL if incomplete/partial)',
  `segundos_candidatos_fuera_horario` INT UNSIGNED NULL COMMENT 'Net technical work seconds outside scheduled boundaries (Candidate, NOT approved overtime)',
  `fingerprint_ejecucion` VARCHAR(64) NOT NULL COMMENT 'SHA256 fingerprint of input schedule + P1 pairing parameters + algorithm version',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_calculo_jornada_revision` (`id_jornada_programada`, `revision_numero`),
  UNIQUE KEY `uk_v2_calculo_fingerprint` (`fingerprint_ejecucion`),
  KEY `idx_v2_calculo_pareo_jornada` (`id_pareo_jornada`),
  KEY `idx_v2_calculo_personal_fecha` (`id_personal`, `fecha_jornada_local`, `revision_numero`),
  KEY `idx_v2_calculo_estado` (`estado_calculo`),
  CONSTRAINT `fk_v2_calculo_jornada_programada` FOREIGN KEY (`id_jornada_programada`) REFERENCES `op_rh_v2_jornadas_programadas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_v2_calculo_pareo_jornada` FOREIGN KEY (`id_pareo_jornada`) REFERENCES `op_rh_v2_pareos_jornada` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla de Detalles por Segmento Calculado (Per-Segment Calculation Result Detail)
CREATE TABLE IF NOT EXISTS `op_rh_v2_calculos_segmentos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_calculo_jornada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_calculos_jornada header',
  `id_pareo_segmento` BIGINT UNSIGNED NULL COMMENT 'FK to op_rh_v2_pareos_segmentos (NULL for UNSCHEDULED)',
  `ordinal_segmento` TINYINT UNSIGNED NOT NULL COMMENT 'Segment position ordinal (1, 2, 3...)',
  `tipo_segmento` VARCHAR(16) NOT NULL DEFAULT 'WORK' COMMENT 'Segment type (WORK, BREAK)',
  `estado_segmento` VARCHAR(32) NOT NULL COMMENT 'Segment calculation state (CALCULATED, PARTIAL_DATA, UNMATCHED_NO_PUNCHES, INVALID_INTERVAL)',
  `segundos_programados` INT UNSIGNED NULL COMMENT 'Scheduled work seconds for this segment',
  `segundos_trabajados` INT UNSIGNED NULL COMMENT 'Actual worked seconds for this segment (NULL if partial/invalid)',
  `delta_llegada_segundos` INT NULL COMMENT 'Signed entry delta in seconds (+late, -early, NULL if no entry punch)',
  `delta_salida_segundos` INT NULL COMMENT 'Signed exit delta in seconds (+late, -early, NULL if no exit punch)',
  `segundos_candidatos_fuera_horario` INT UNSIGNED NULL COMMENT 'Technical outside-schedule seconds for this segment',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_calculo_seg_ordinal` (`id_calculo_jornada`, `ordinal_segmento`),
  KEY `idx_v2_calc_seg_pareo` (`id_pareo_segmento`),
  CONSTRAINT `fk_v2_calc_seg_header` FOREIGN KEY (`id_calculo_jornada`) REFERENCES `op_rh_v2_calculos_jornada` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_v2_calc_seg_pareo` FOREIGN KEY (`id_pareo_segmento`) REFERENCES `op_rh_v2_pareos_segmentos` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Triggers de Protección Append-Only sobre Headers de Cálculo
DELIMITER $$

DROP TRIGGER IF EXISTS `tg_op_rh_v2_calculos_jornada_no_update`$$
CREATE TRIGGER `tg_op_rh_v2_calculos_jornada_no_update`
BEFORE UPDATE ON `op_rh_v2_calculos_jornada`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. UPDATE operations are strictly prohibited on op_rh_v2_calculos_jornada.';
END$$

DROP TRIGGER IF EXISTS `tg_op_rh_v2_calculos_jornada_no_delete`$$
CREATE TRIGGER `tg_op_rh_v2_calculos_jornada_no_delete`
BEFORE DELETE ON `op_rh_v2_calculos_jornada`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. DELETE operations are strictly prohibited on op_rh_v2_calculos_jornada.';
END$$

DELIMITER ;
