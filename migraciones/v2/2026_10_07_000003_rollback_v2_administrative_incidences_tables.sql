-- =============================================================================
-- MIGRACIÓN ADITIVA V2.5-M: ROLLBACK SCHEMA INCIDENCIAS Y AJUSTES
-- Submódulo Motor de Jornada V2: Tablas de Incidencias, Ajustes y Evaluaciones
-- Spec Version: 1.0 (FROZEN_V1.0)
-- =============================================================================

DROP TRIGGER IF EXISTS `tg_op_rh_v2_evaluaciones_admin_no_delete`;
DROP TRIGGER IF EXISTS `tg_op_rh_v2_evaluaciones_admin_no_update`;

DROP TABLE IF EXISTS `op_rh_v2_evaluaciones_administrativas`;
DROP TABLE IF EXISTS `op_rh_v2_ajustes_administrativos`;
DROP TABLE IF EXISTS `op_rh_v2_incidencias_aplicaciones`;
DROP TABLE IF EXISTS `op_rh_v2_incidencias_revisiones`;
DROP TABLE IF EXISTS `op_rh_v2_incidencias_maestro`;
