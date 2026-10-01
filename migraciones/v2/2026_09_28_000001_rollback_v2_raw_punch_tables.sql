-- =============================================================================
-- ROLLBACK MIGRACIÓN V2: ELIMINACIÓN DE ESTRUCTURAS V2 (SLICE V2.1)
-- Proyecto: portal-api-v.1.0.1
-- Entorno: Local / Desarrollo únicamente
-- NOTA: NO TOCA NI MODIFICA NINGUNA TABLA O DATO LEGACY.
-- =============================================================================

DROP TABLE IF EXISTS `op_rh_v2_marcaciones_crudas`;
DROP TABLE IF EXISTS `op_rh_v2_dispositivo_identidad_personal`;
DROP TABLE IF EXISTS `op_rh_v2_dispositivo_credenciales`;
DROP TABLE IF EXISTS `op_rh_v2_dispositivos`;
