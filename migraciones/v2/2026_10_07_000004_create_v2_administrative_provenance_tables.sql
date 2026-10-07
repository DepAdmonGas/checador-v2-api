-- =============================================================================
-- MIGRACIÓN ADITIVA V2.5-MA: PROVENIENCIA Y REVISIONES DE AJUSTES (V1.1 ADDENDUM)
-- Submódulo Motor de Jornada V2: Tablas de Asociación e Historial de Ajustes
-- Spec Version: 1.1 (FROZEN_V1.1 Addendum)
-- =============================================================================

-- 1. Alteración aditiva de op_rh_v2_ajustes_administrativos para linaje de revisiones
ALTER TABLE `op_rh_v2_ajustes_administrativos`
  ADD COLUMN `uuid_ajuste_logico` VARCHAR(64) NULL COMMENT 'Canonical logical UUID for adjustment lineage' AFTER `uuid_ajuste`,
  ADD COLUMN `revision_numero` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Monotonic adjustment revision number' AFTER `uuid_ajuste_logico`,
  ADD COLUMN `estado_ajuste` VARCHAR(32) NOT NULL DEFAULT 'ACTIVE' COMMENT 'Status (ACTIVE, SUPERSEDED, CANCELLED)' AFTER `revision_numero`,
  ADD COLUMN `id_ajuste_anterior` BIGINT UNSIGNED NULL COMMENT 'FK to prior superseded/cancelled adjustment revision' AFTER `estado_ajuste`;

-- Backfill determinista para filas existentes de 000003
UPDATE `op_rh_v2_ajustes_administrativos`
SET `uuid_ajuste_logico` = `uuid_ajuste`
WHERE `uuid_ajuste_logico` IS NULL;

ALTER TABLE `op_rh_v2_ajustes_administrativos`
  MODIFY COLUMN `uuid_ajuste_logico` VARCHAR(64) NOT NULL COMMENT 'Canonical logical UUID for adjustment lineage',
  ADD UNIQUE KEY `uk_v2_ajuste_logico_rev` (`uuid_ajuste_logico`, `revision_numero`),
  ADD CONSTRAINT `fk_v2_ajuste_anterior` FOREIGN KEY (`id_ajuste_anterior`) REFERENCES `op_rh_v2_ajustes_administrativos` (`id`) ON DELETE RESTRICT;

-- 2. Tabla de Asociación Inmutable Evaluaciones -> Aplicaciones de Incidencias
CREATE TABLE IF NOT EXISTS `op_rh_v2_evaluaciones_incidencias` (
  `id_evaluacion_administrativa` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_evaluaciones_administrativas',
  `id_incidencia_aplicacion` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_incidencias_aplicaciones',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_evaluacion_administrativa`, `id_incidencia_aplicacion`),
  KEY `idx_v2_eval_incid_app` (`id_incidencia_aplicacion`),
  CONSTRAINT `fk_v2_eval_incid_eval` FOREIGN KEY (`id_evaluacion_administrativa`) REFERENCES `op_rh_v2_evaluaciones_administrativas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_v2_eval_incid_app` FOREIGN KEY (`id_incidencia_aplicacion`) REFERENCES `op_rh_v2_incidencias_aplicaciones` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabla de Asociación Inmutable Evaluaciones -> Ajustes Administrativos
CREATE TABLE IF NOT EXISTS `op_rh_v2_evaluaciones_ajustes` (
  `id_evaluacion_administrativa` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_evaluaciones_administrativas',
  `id_ajuste_administrativo` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_ajustes_administrativos',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_evaluacion_administrativa`, `id_ajuste_administrativo`),
  KEY `idx_v2_eval_ajuste_adj` (`id_ajuste_administrativo`),
  CONSTRAINT `fk_v2_eval_ajuste_eval` FOREIGN KEY (`id_evaluacion_administrativa`) REFERENCES `op_rh_v2_evaluaciones_administrativas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_v2_eval_ajuste_adj` FOREIGN KEY (`id_ajuste_administrativo`) REFERENCES `op_rh_v2_ajustes_administrativos` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
