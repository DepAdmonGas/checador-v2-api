-- =============================================================================
-- MIGRACIÓN ADITIVA V2.5-M: IMPLEMENTACIÓN SCHEMA INCIDENCIAS Y AJUSTES
-- Submódulo Motor de Jornada V2: Tablas de Incidencias, Ajustes y Evaluaciones
-- Spec Version: 1.0 (FROZEN_V1.0)
-- =============================================================================

-- 1. Tabla Maestro de Incidencias Administrativas
CREATE TABLE IF NOT EXISTS `op_rh_v2_incidencias_maestro` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid_incidencia` VARCHAR(64) NOT NULL COMMENT 'Canonical external UUID for incidence aggregate',
  `id_personal` INT NOT NULL COMMENT 'Canonical Worker ID (FK to op_rh_personal)',
  `id_estacion` INT NULL COMMENT 'Optional Workplace Station ID constraint (NULL if worker-wide)',
  `tipo_incidencia` VARCHAR(32) NOT NULL COMMENT 'Type code (VACATION, DISABILITY, PERMISSION, AUTHORIZED_LATENESS, AUTHORIZED_EARLY_DEPARTURE, OFFICIAL_MISSION)',
  `alcance_incidencia` VARCHAR(32) NOT NULL DEFAULT 'WORKER' COMMENT 'Scope (WORKER, WORKPLACE, SCHEDULED_SHIFT)',
  `fecha_inicio_local` DATE NOT NULL COMMENT 'Inclusive start local date (YYYY-MM-DD)',
  `fecha_fin_local` DATE NOT NULL COMMENT 'Inclusive end local date (YYYY-MM-DD)',
  `estado_actual` VARCHAR(32) NOT NULL DEFAULT 'ACTIVE' COMMENT 'Current status (ACTIVE, CANCELLED, SUPERSEDED)',
  `revision_actual` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Current active revision number',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_incidencia_uuid` (`uuid_incidencia`),
  KEY `idx_v2_incid_personal_fechas` (`id_personal`, `fecha_inicio_local`, `fecha_fin_local`),
  KEY `idx_v2_incid_estacion_tipo` (`id_estacion`, `tipo_incidencia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla de Revisiones de Incidencias (Audit Trail Inmutable)
CREATE TABLE IF NOT EXISTS `op_rh_v2_incidencias_revisiones` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_incidencia_maestro` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_incidencias_maestro',
  `revision_numero` INT UNSIGNED NOT NULL COMMENT 'Monotonic revision number',
  `estado_revision` VARCHAR(32) NOT NULL COMMENT 'Revision status (ACTIVE, CANCELLED, SUPERSEDED)',
  `motivo` TEXT NULL COMMENT 'Administrative explanation / note',
  `folio_evidencia` VARCHAR(100) NULL COMMENT 'External medical/official document folio reference',
  `creado_por_usuario_id` INT NOT NULL COMMENT 'Actor user ID creating revision (tb_usuarios.id)',
  `aprobado_por_usuario_id` INT NULL COMMENT 'Actor user ID authorizing revision (tb_usuarios.id)',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_incid_rev_numero` (`id_incidencia_maestro`, `revision_numero`),
  CONSTRAINT `fk_v2_incid_rev_maestro` FOREIGN KEY (`id_incidencia_maestro`) REFERENCES `op_rh_v2_incidencias_maestro` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabla de Aplicaciones Diarias de Incidencia a Jornadas Programadas
CREATE TABLE IF NOT EXISTS `op_rh_v2_incidencias_aplicaciones` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_incidencia_revision` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_incidencias_revisiones',
  `id_jornada_programada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_jornadas_programadas',
  `fecha_jornada_local` DATE NOT NULL COMMENT 'Local work date (YYYY-MM-DD)',
  `estado_aplicacion` VARCHAR(32) NOT NULL DEFAULT 'APPLIED' COMMENT 'Application state (APPLIED, CONTRADICTION, OVERLAP_CONFLICT)',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_incid_app_shift_rev` (`id_jornada_programada`, `id_incidencia_revision`),
  KEY `idx_v2_incid_app_rev` (`id_incidencia_revision`),
  CONSTRAINT `fk_v2_incid_app_rev` FOREIGN KEY (`id_incidencia_revision`) REFERENCES `op_rh_v2_incidencias_revisiones` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_v2_incid_app_shift` FOREIGN KEY (`id_jornada_programada`) REFERENCES `op_rh_v2_jornadas_programadas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabla de Ajustes Administrativos Manuales
CREATE TABLE IF NOT EXISTS `op_rh_v2_ajustes_administrativos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid_ajuste` VARCHAR(64) NOT NULL COMMENT 'Canonical UUID for adjustment',
  `id_jornada_programada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_jornadas_programadas',
  `id_calculo_jornada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to explicit op_rh_v2_calculos_jornada P2 revision',
  `tipo_ajuste` VARCHAR(32) NOT NULL COMMENT 'Adjustment type (OVERRIDE_STATUS, INTERPRETED_ENTRY_TIME, INTERPRETED_EXIT_TIME, JUSTIFY_LATENESS)',
  `hora_interpretada_entrada_utc` DATETIME NULL COMMENT 'Interpreted administrative entry time (NOT a raw PunchEvent)',
  `hora_interpretada_salida_utc` DATETIME NULL COMMENT 'Interpreted administrative exit time (NOT a raw PunchEvent)',
  `motivo_ajuste` TEXT NOT NULL COMMENT 'Mandatory administrative justification note',
  `creado_por_usuario_id` INT NOT NULL COMMENT 'Actor user ID creating adjustment (tb_usuarios.id)',
  `aprobado_por_usuario_id` INT NOT NULL COMMENT 'Actor user ID approving adjustment (tb_usuarios.id)',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_ajuste_uuid` (`uuid_ajuste`),
  KEY `idx_v2_ajuste_shift_calc` (`id_jornada_programada`, `id_calculo_jornada`),
  CONSTRAINT `fk_v2_ajuste_shift` FOREIGN KEY (`id_jornada_programada`) REFERENCES `op_rh_v2_jornadas_programadas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_v2_ajuste_calculo` FOREIGN KEY (`id_calculo_jornada`) REFERENCES `op_rh_v2_calculos_jornada` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabla Header de Evaluaciones Administrativas (Revisiones Append-Only)
CREATE TABLE IF NOT EXISTS `op_rh_v2_evaluaciones_administrativas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_jornada_programada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_jornadas_programadas',
  `id_calculo_jornada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to explicit immutable op_rh_v2_calculos_jornada revision',
  `id_personal` INT NOT NULL COMMENT 'Worker ID snapshot',
  `id_estacion` INT NOT NULL COMMENT 'Workplace station ID snapshot',
  `fecha_jornada_local` DATE NOT NULL COMMENT 'Local work date snapshot (YYYY-MM-DD)',
  `revision_numero` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Monotonic evaluation revision number per shift',
  `version_algoritmo_admin` VARCHAR(32) NOT NULL DEFAULT 'v2.5-d' COMMENT 'Administrative evaluation algorithm version',
  `version_politica` VARCHAR(32) NOT NULL DEFAULT 'POLICY_V1.0_DEFAULT' COMMENT 'Policy version provenance',
  `estado_evaluacion` VARCHAR(32) NOT NULL COMMENT 'Administrative status (NORMAL_COMPLETED, VACATION_APPLIED, DISABILITY_APPLIED, PERMISSION_APPLIED, ADMINISTRATIVE_SIN_MARCACION, FALTA, CONTRADICTION_DETECTED, OVERLAP_CONFLICT, DATA_INCOMPLETE, REVIEW_REQUIRED)',
  `estado_revision_humana` VARCHAR(32) NOT NULL DEFAULT 'NOT_REQUIRED' COMMENT 'Human review status (NOT_REQUIRED, PENDING_REVIEW, REVIEWED_APPROVED, REVIEWED_REJECTED)',
  `fingerprint_ejecucion` VARCHAR(64) NOT NULL COMMENT 'SHA256 fingerprint of P2 revision + applicable incidence revisions + adjustment IDs + policy version',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_eval_shift_revision` (`id_jornada_programada`, `revision_numero`),
  UNIQUE KEY `uk_v2_eval_fingerprint` (`fingerprint_ejecucion`),
  KEY `idx_v2_eval_calculo_jornada` (`id_calculo_jornada`),
  KEY `idx_v2_eval_personal_fecha` (`id_personal`, `fecha_jornada_local`, `revision_numero`),
  KEY `idx_v2_eval_estado` (`estado_evaluacion`),
  CONSTRAINT `fk_v2_eval_shift` FOREIGN KEY (`id_jornada_programada`) REFERENCES `op_rh_v2_jornadas_programadas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_v2_eval_calculo` FOREIGN KEY (`id_calculo_jornada`) REFERENCES `op_rh_v2_calculos_jornada` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Triggers de Protección Append-Only sobre Evaluaciones Administrativas
DELIMITER $$

DROP TRIGGER IF EXISTS `tg_op_rh_v2_evaluaciones_admin_no_update`$$
CREATE TRIGGER `tg_op_rh_v2_evaluaciones_admin_no_update`
BEFORE UPDATE ON `op_rh_v2_evaluaciones_administrativas`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. UPDATE operations are strictly prohibited on op_rh_v2_evaluaciones_administrativas.';
END$$

DROP TRIGGER IF EXISTS `tg_op_rh_v2_evaluaciones_admin_no_delete`$$
CREATE TRIGGER `tg_op_rh_v2_evaluaciones_admin_no_delete`
BEFORE DELETE ON `op_rh_v2_evaluaciones_administrativas`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. DELETE operations are strictly prohibited on op_rh_v2_evaluaciones_administrativas.';
END$$

DELIMITER ;
