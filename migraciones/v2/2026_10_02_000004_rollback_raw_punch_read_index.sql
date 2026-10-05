-- Rollback de migracion V2.3-G4B (Solo entornos de desarrollo / pruebas)
-- Tabla: op_rh_v2_marcaciones_crudas

ALTER TABLE `op_rh_v2_marcaciones_crudas`
  DROP INDEX `idx_estacion_ocurrido`;
