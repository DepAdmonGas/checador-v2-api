-- Migracion aditiva V2.3-G1: Agregar columnas para Sello de Integridad HMAC-SHA256
-- Tabla: op_rh_v2_marcaciones_crudas
-- Las columnas son NULL por defecto para no contaminar registros legacy existentes.

ALTER TABLE `op_rh_v2_marcaciones_crudas`
  ADD COLUMN `integrity_version` VARCHAR(10) NULL DEFAULT NULL AFTER `metadata_json`,
  ADD COLUMN `integrity_key_id` VARCHAR(32) NULL DEFAULT NULL AFTER `integrity_version`,
  ADD COLUMN `integrity_seal` CHAR(64) NULL DEFAULT NULL AFTER `integrity_key_id`;
