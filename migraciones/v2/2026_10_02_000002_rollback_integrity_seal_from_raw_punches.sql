-- Rollback de migracion V2.3-G1 (Solo entornos de desarrollo / pruebas)
-- Tabla: op_rh_v2_marcaciones_crudas

ALTER TABLE `op_rh_v2_marcaciones_crudas`
  DROP COLUMN `integrity_seal`,
  DROP COLUMN `integrity_key_id`,
  DROP COLUMN `integrity_version`;
