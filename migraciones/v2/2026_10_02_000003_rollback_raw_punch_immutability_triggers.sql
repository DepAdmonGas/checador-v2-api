-- Rollback de triggers V2.3-G3 (Solo entornos de desarrollo / pruebas)
-- Tabla: op_rh_v2_marcaciones_crudas

DROP TRIGGER IF EXISTS `tg_op_rh_v2_marcaciones_crudas_no_update`;
DROP TRIGGER IF EXISTS `tg_op_rh_v2_marcaciones_crudas_no_delete`;
