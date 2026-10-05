-- Migracion aditiva V2.3-G3: Triggers para asegurar Inmutabilidad Append-Only a nivel BD
-- Tabla: op_rh_v2_marcaciones_crudas

DELIMITER $$

-- 1. Trigger BEFORE UPDATE: Bloquea cualquier modificacion sobre cualquier columna
DROP TRIGGER IF EXISTS `tg_op_rh_v2_marcaciones_crudas_no_update`$$
CREATE TRIGGER `tg_op_rh_v2_marcaciones_crudas_no_update`
BEFORE UPDATE ON `op_rh_v2_marcaciones_crudas`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. UPDATE operations are strictly prohibited on op_rh_v2_marcaciones_crudas.';
END$$

-- 2. Trigger BEFORE DELETE: Bloquea cualquier borrado fisico de registros
DROP TRIGGER IF EXISTS `tg_op_rh_v2_marcaciones_crudas_no_delete`$$
CREATE TRIGGER `tg_op_rh_v2_marcaciones_crudas_no_delete`
BEFORE DELETE ON `op_rh_v2_marcaciones_crudas`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. DELETE operations are strictly prohibited on op_rh_v2_marcaciones_crudas.';
END$$

DELIMITER ;
