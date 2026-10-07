-- =============================================================================
-- MIGRACIÓN ADITIVA V2.4-P1M: IMPLEMENTACIÓN SCHEMA PAIRING STORAGE
-- Submódulo Motor de Jornada V2: Tablas de Pareo de Jornada, Segmentos y Marcaciones Asociadas
-- Spec Version: 1.0 (FROZEN_V1.0)
-- =============================================================================

-- 1. Tabla Header de Pareo de Jornada (Pairing Revision Header)
CREATE TABLE IF NOT EXISTS `op_rh_v2_pareos_jornada` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_jornada_programada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_jornadas_programadas',
  `id_personal` INT NOT NULL COMMENT 'Worker ID snapshot',
  `id_estacion` INT NOT NULL COMMENT 'Workplace station ID snapshot',
  `fecha_jornada_local` DATE NOT NULL COMMENT 'Local work date snapshot (YYYY-MM-DD)',
  `revision_numero` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Monotonic revision number per scheduled shift',
  `version_algoritmo` VARCHAR(32) NOT NULL DEFAULT 'v2.4-p1' COMMENT 'Algorithm version provenance',
  `estado_completitud_l2` VARCHAR(32) NOT NULL DEFAULT 'VERIFIED' COMMENT 'Level 2 completeness state (VERIFIED, PENDING_SYNC_COMPLETION, INCOMPLETE)',
  `estado_pareo` VARCHAR(32) NOT NULL COMMENT 'Overall status (EXACT_PAIR, PARTIAL_MISSING_IN, PARTIAL_MISSING_OUT, UNMATCHED_NO_PUNCHES, AMBIGUOUS_PAIRING, WORKPLACE_MISMATCH, PENDING_SYNC_COMPLETION)',
  `fingerprint_ejecucion` VARCHAR(64) NULL COMMENT 'SHA256 fingerprint of input events & schedule parameters',
  `supercedido_por_id` BIGINT UNSIGNED NULL COMMENT 'Optional pointer to superseding revision ID',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_pareo_jornada_revision` (`id_jornada_programada`, `revision_numero`),
  KEY `idx_v2_pareo_personal_fecha` (`id_personal`, `fecha_jornada_local`, `revision_numero`),
  KEY `idx_v2_pareo_estacion_completitud` (`id_estacion`, `estado_completitud_l2`),
  KEY `idx_v2_pareo_fingerprint` (`fingerprint_ejecucion`),
  CONSTRAINT `fk_v2_pareo_jornada_programada` FOREIGN KEY (`id_jornada_programada`) REFERENCES `op_rh_v2_jornadas_programadas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla de Resultados por Segmento (Pairing Segment Result)
CREATE TABLE IF NOT EXISTS `op_rh_v2_pareos_segmentos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_pareo_jornada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_pareos_jornada header',
  `ordinal_segmento` TINYINT UNSIGNED NOT NULL COMMENT 'Segment position ordinal (1, 2, 3...)',
  `id_marcacion_entrada` INT NULL COMMENT 'FK to op_rh_v2_marcaciones_crudas for primary IN punch',
  `id_marcacion_salida` INT NULL COMMENT 'FK to op_rh_v2_marcaciones_crudas for primary OUT punch',
  `estado_segmento` VARCHAR(32) NOT NULL COMMENT 'Segment pairing status (EXACT_PAIR, PARTIAL_MISSING_IN, PARTIAL_MISSING_OUT, UNMATCHED_NO_PUNCHES, AMBIGUOUS_PAIRING, WORKPLACE_MISMATCH)',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_pareo_segmento_ordinal` (`id_pareo_jornada`, `ordinal_segmento`),
  KEY `idx_v2_pareo_seg_entrada` (`id_marcacion_entrada`),
  KEY `idx_v2_pareo_seg_salida` (`id_marcacion_salida`),
  CONSTRAINT `fk_v2_pareo_seg_header` FOREIGN KEY (`id_pareo_jornada`) REFERENCES `op_rh_v2_pareos_jornada` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_v2_pareo_seg_entrada` FOREIGN KEY (`id_marcacion_entrada`) REFERENCES `op_rh_v2_marcaciones_crudas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_v2_pareo_seg_salida` FOREIGN KEY (`id_marcacion_salida`) REFERENCES `op_rh_v2_marcaciones_crudas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabla de Marcaciones Asociadas y Excedentes (Punch Link & Surplus Detail)
CREATE TABLE IF NOT EXISTS `op_rh_v2_pareos_marcaciones_asociadas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_pareo_jornada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_pareos_jornada header',
  `id_pareo_segmento` BIGINT UNSIGNED NULL COMMENT 'FK to op_rh_v2_pareos_segmentos (NULL if unassigned/day-off)',
  `id_marcacion_cruda` INT NOT NULL COMMENT 'FK to op_rh_v2_marcaciones_crudas',
  `rol_marcacion` VARCHAR(32) NOT NULL COMMENT 'Punch role (ENTRY, EXIT, SURPLUS, UNMATCHED, AMBIGUOUS)',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_pareo_revision_marcacion` (`id_pareo_jornada`, `id_marcacion_cruda`),
  KEY `idx_v2_pareo_marc_cruda` (`id_marcacion_cruda`),
  KEY `idx_v2_pareo_marc_segmento` (`id_pareo_segmento`),
  CONSTRAINT `fk_v2_pareo_marc_header` FOREIGN KEY (`id_pareo_jornada`) REFERENCES `op_rh_v2_pareos_jornada` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_v2_pareo_marc_segmento` FOREIGN KEY (`id_pareo_segmento`) REFERENCES `op_rh_v2_pareos_segmentos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_v2_pareo_marc_cruda` FOREIGN KEY (`id_marcacion_cruda`) REFERENCES `op_rh_v2_marcaciones_crudas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Triggers de Protección Append-Only sobre Headers de Pareo
DELIMITER $$

DROP TRIGGER IF EXISTS `tg_op_rh_v2_pareos_jornada_no_update`$$
CREATE TRIGGER `tg_op_rh_v2_pareos_jornada_no_update`
BEFORE UPDATE ON `op_rh_v2_pareos_jornada`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. UPDATE operations are strictly prohibited on op_rh_v2_pareos_jornada.';
END$$

DROP TRIGGER IF EXISTS `tg_op_rh_v2_pareos_jornada_no_delete`$$
CREATE TRIGGER `tg_op_rh_v2_pareos_jornada_no_delete`
BEFORE DELETE ON `op_rh_v2_pareos_jornada`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. DELETE operations are strictly prohibited on op_rh_v2_pareos_jornada.';
END$$

DELIMITER ;
