-- Rollback Migración V2.4-P1M: Tablas de Pareo de Jornada V2

DROP TRIGGER IF EXISTS `tg_op_rh_v2_pareos_jornada_no_delete`;
DROP TRIGGER IF EXISTS `tg_op_rh_v2_pareos_jornada_no_update`;

DROP TABLE IF EXISTS `op_rh_v2_pareos_marcaciones_asociadas`;
DROP TABLE IF EXISTS `op_rh_v2_pareos_segmentos`;
DROP TABLE IF EXISTS `op_rh_v2_pareos_jornada`;
