-- =============================================================================
-- MIGRACIÓN ADITIVA V2.6-M: IMPLEMENTACIÓN SCHEMA COMPARACIÓN PARALELA
-- Submódulo Adaptador de Comparación Paralela (Legacy vs Checador V2)
-- Business Terminology: comparación paralela sin afectar nómina
-- Spec Reference: docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md (FROZEN_V1.0)
-- Addendum Reference: docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_ADDENDUM_V1.1.md (FROZEN_V1.1)
-- Contract Reference: docs/v2/CHECADOR_V2_LEGACY_PAYROLL_PARALLEL_COMPARISON_ADAPTER_CONTRACT_V1.0.md (FROZEN_V1.0)
-- =============================================================================

-- 1. Tabla Encabezado de Comparaciones (Revisiones Append-Only)
CREATE TABLE IF NOT EXISTS `op_rh_v2_comparaciones_encabezado` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_evaluacion_administrativa` BIGINT UNSIGNED NOT NULL COMMENT 'FK to explicit immutable op_rh_v2_evaluaciones_administrativas revision',
  `id_personal` INT NOT NULL COMMENT 'Canonical Worker ID snapshot',
  `id_estacion` INT NOT NULL COMMENT 'Workplace Station ID snapshot',
  `fecha_jornada_local` DATE NOT NULL COMMENT 'Local work date snapshot (YYYY-MM-DD)',
  `revision_numero` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Monotonic comparison revision number per administrative evaluation revision',
  `categoria_resultado` VARCHAR(40) NOT NULL COMMENT 'Result category (MATCH, PUNCH_DIFFERENCE, SHIFT_DIFFERENCE, INCIDENT_DIFFERENCE, OVERTIME_DIFFERENCE, LEGACY_LIMITATION, REVIEW_REQUIRED, MISSING_LEGACY_RECORD, NOT_COMPARABLE)',
  `requiere_revision` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag indicating human review required (1=YES, 0=NO)',
  `estado_presencia_legacy` VARCHAR(30) NOT NULL DEFAULT 'OBSERVED' COMMENT 'Legacy presence status (OBSERVED, MISSING_RECORD, DATA_INCOMPLETE, AMBIGUOUS)',
  `version_algoritmo_comparacion` VARCHAR(20) NOT NULL DEFAULT 'V1.0' COMMENT 'Comparison algorithm version',
  `version_politica_comparacion` VARCHAR(20) NOT NULL DEFAULT 'V1.0' COMMENT 'Comparison policy version',
  `fingerprint_ejecucion` VARCHAR(64) NOT NULL COMMENT 'SHA256 fingerprint of id_evaluacion_administrativa + estado_presencia_legacy + legacy_attendance_snapshot_hash + sorted_legacy_incidence_hashes + algorithm_version + policy_version',
  `registrado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_v2_comp_enc_eval_admin` FOREIGN KEY (`id_evaluacion_administrativa`) REFERENCES `op_rh_v2_evaluaciones_administrativas` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `uk_v2_comp_eval_revision` UNIQUE KEY (`id_evaluacion_administrativa`, `revision_numero`),
  CONSTRAINT `uk_v2_comp_fingerprint` UNIQUE KEY (`fingerprint_ejecucion`),
  KEY `idx_v2_comp_enc_personal_fecha` (`id_personal`, `fecha_jornada_local`),
  KEY `idx_v2_comp_enc_categoria` (`categoria_resultado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla Capturas de Asistencia Legacy (Snapshot Inmutable de op_rh_personal_asistencia)
CREATE TABLE IF NOT EXISTS `op_rh_v2_comparaciones_legacy_capturas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_comparacion_encabezado` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_comparaciones_encabezado',
  `id_personal_asistencia_legacy` INT NULL COMMENT 'Nullable reference to source legacy row id (op_rh_personal_asistencia.id)',
  `id_estacion_legacy` INT NULL COMMENT 'Captured legacy station ID',
  `id_personal_legacy` INT NULL COMMENT 'Captured legacy personal ID',
  `fecha_legacy` DATE NULL COMMENT 'Captured legacy work date (YYYY-MM-DD)',
  `hora_entrada_legacy` TIME NULL COMMENT 'Captured legacy scheduled entry time',
  `hora_salida_legacy` TIME NULL COMMENT 'Captured legacy scheduled exit time',
  `hora_entrada_sensor_legacy` TIME NULL COMMENT 'Captured legacy sensor entry time',
  `hora_salida_sensor_legacy` TIME NULL COMMENT 'Captured legacy sensor exit time',
  `retardo_minutos_legacy` INT NULL COMMENT 'Captured legacy tardiness minutes',
  `incidencia_dias_legacy` INT NULL COMMENT 'Captured legacy incidence days',
  `incidencia_legacy` DOUBLE NULL COMMENT 'Captured legacy incidence value',
  `sd_legacy` DOUBLE NULL COMMENT 'Captured legacy salary parameter (raw reference value only, NOT used for payroll calculation)',
  `snapshot_hash` VARCHAR(64) NOT NULL COMMENT 'SHA256 hash of captured legacy scalar fields or missing record sentinel hash',
  `capturado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_v2_comp_snap_encabezado` FOREIGN KEY (`id_comparacion_encabezado`) REFERENCES `op_rh_v2_comparaciones_encabezado` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `uk_v2_comp_snap_encabezado` UNIQUE KEY (`id_comparacion_encabezado`),
  KEY `idx_v2_comp_snap_legacy_id` (`id_personal_asistencia_legacy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabla Capturas de Incidencias Legacy (Snapshot Inmutable Set de op_rh_personal_asistencia_incidencia)
CREATE TABLE IF NOT EXISTS `op_rh_v2_comparaciones_legacy_incidencias` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_comparacion_encabezado` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_comparaciones_encabezado',
  `id_asistencia_incidencia_legacy` INT NULL COMMENT 'Nullable reference to source legacy incidence row id (op_rh_personal_asistencia_incidencia.id)',
  `fecha_incidencia_legacy` DATETIME NULL COMMENT 'Captured legacy incidence timestamp',
  `incidencia_texto_legacy` TEXT NULL COMMENT 'Captured legacy incidence title/type text',
  `comentario_legacy` TEXT NULL COMMENT 'Captured legacy comment text',
  `documento_legacy` TEXT NULL COMMENT 'Captured legacy document reference text',
  `estado_legacy` INT NULL COMMENT 'Captured legacy status code',
  `orden_secuencia` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Sequence ordinal for child rows (1..N)',
  `row_hash` VARCHAR(64) NOT NULL COMMENT 'SHA256 hash of captured legacy incidence row scalar fields',
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_v2_comp_inc_encabezado` FOREIGN KEY (`id_comparacion_encabezado`) REFERENCES `op_rh_v2_comparaciones_encabezado` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `uk_v2_comp_inc_enc_seq` UNIQUE KEY (`id_comparacion_encabezado`, `orden_secuencia`),
  KEY `idx_v2_comp_inc_legacy_id` (`id_asistencia_incidencia_legacy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabla Detalle de Discrepancias (Registro de Diferencias de Campo)
CREATE TABLE IF NOT EXISTS `op_rh_v2_comparaciones_discrepancias` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_comparacion_encabezado` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_comparaciones_encabezado',
  `categoria_discrepancia` VARCHAR(40) NOT NULL COMMENT 'Discrepancy category',
  `campo_evaluado` VARCHAR(50) NOT NULL COMMENT 'Field evaluated (e.g., hora_entrada_sensor, estado_presencia)',
  `valor_v2` TEXT NULL COMMENT 'Observed value in Checador V2',
  `valor_legacy` TEXT NULL COMMENT 'Observed value in Legacy System',
  `descripcion_diferencia` TEXT NULL COMMENT 'Detailed explanation of difference',
  `orden_secuencia` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Sequence ordinal for detail rows (1..N)',
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_v2_comp_disc_encabezado` FOREIGN KEY (`id_comparacion_encabezado`) REFERENCES `op_rh_v2_comparaciones_encabezado` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `uk_v2_comp_disc_enc_seq` UNIQUE KEY (`id_comparacion_encabezado`, `orden_secuencia`),
  KEY `idx_v2_comp_disc_cat` (`categoria_discrepancia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Triggers de Protección Append-Only sobre Encabezado de Comparaciones
DELIMITER $$

DROP TRIGGER IF EXISTS `tg_op_rh_v2_comparaciones_encabezado_no_update`$$
CREATE TRIGGER `tg_op_rh_v2_comparaciones_encabezado_no_update`
BEFORE UPDATE ON `op_rh_v2_comparaciones_encabezado`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'V2.6 Error: Modifications to op_rh_v2_comparaciones_encabezado rows are prohibited. New comparisons must create append-only revision records.';
END$$

DROP TRIGGER IF EXISTS `tg_op_rh_v2_comparaciones_encabezado_no_delete`$$
CREATE TRIGGER `tg_op_rh_v2_comparaciones_encabezado_no_delete`
BEFORE DELETE ON `op_rh_v2_comparaciones_encabezado`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'V2.6 Error: Deletions from op_rh_v2_comparaciones_encabezado are prohibited. Comparison history is immutable.';
END$$

DELIMITER ;
