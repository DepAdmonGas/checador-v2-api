-- =============================================================================
-- ROLLBACK MIGRACIÓN V2.5-MA: PROVENIENCIA Y REVISIONES DE AJUSTES (V1.1 ADDENDUM)
-- Spec Version: 1.1 (FROZEN_V1.1 Addendum)
-- =============================================================================

DROP TABLE IF EXISTS `op_rh_v2_evaluaciones_ajustes`;
DROP TABLE IF EXISTS `op_rh_v2_evaluaciones_incidencias`;

ALTER TABLE `op_rh_v2_ajustes_administrativos` DROP FOREIGN KEY `fk_v2_ajuste_anterior`;
ALTER TABLE `op_rh_v2_ajustes_administrativos` DROP INDEX `uk_v2_ajuste_logico_rev`;

ALTER TABLE `op_rh_v2_ajustes_administrativos`
  DROP COLUMN `id_ajuste_anterior`,
  DROP COLUMN `estado_ajuste`,
  DROP COLUMN `revision_numero`,
  DROP COLUMN `uuid_ajuste_logico`;
