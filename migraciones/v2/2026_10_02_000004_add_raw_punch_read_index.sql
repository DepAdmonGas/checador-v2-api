-- Migracion aditiva V2.3-G4B: Agregar indice idx_estacion_ocurrido
-- Tabla: op_rh_v2_marcaciones_crudas
-- Optimiza consultas READ-ONLY filtradas por estación resuelta y rango de ocurrencia UTC

ALTER TABLE `op_rh_v2_marcaciones_crudas`
  ADD INDEX `idx_estacion_ocurrido` (`id_estacion_resuelta`, `ocurrido_at_utc`);
