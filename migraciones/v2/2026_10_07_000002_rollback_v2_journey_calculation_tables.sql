-- =============================================================================
-- MIGRACIÓN ADITIVA V2.4-P2M: ROLLBACK SCHEMA CALCULATION STORAGE
-- Submódulo Motor de Jornada V2: Eliminación segura de Tablas y Triggers de Cálculo
-- Spec Version: 1.0 (FROZEN_V1.0)
-- =============================================================================

-- 1. Eliminar Triggers de Protección Append-Only
DROP TRIGGER IF EXISTS `tg_op_rh_v2_calculos_jornada_no_update`;
DROP TRIGGER IF EXISTS `tg_op_rh_v2_calculos_jornada_no_delete`;

-- 2. Eliminar Tablas en orden inverso de dependencia (Hijo -> Padre)
DROP TABLE IF EXISTS `op_rh_v2_calculos_segmentos`;
DROP TABLE IF EXISTS `op_rh_v2_calculos_jornada`;
