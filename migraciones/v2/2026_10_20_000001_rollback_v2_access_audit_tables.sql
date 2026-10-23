-- =============================================================================
-- ROLLBACK V2: TABLA DE AUDITORÍA DE INTENTOS RECHAZADOS (GATEKEEPER V1.4 - API-02)
-- Proyecto: portal-api-v.1.0.1
-- Entorno: Preproducción exclusivamente (`bd_admongas_v2_preprod`)
-- ADVERTENCIA DE SEGURIDAD:
--   Este script es estrictamente para contingencias y referencia de recuperación.
--   NO debe ejecutarse automáticamente ni concatenarse con la migración principal.
-- =============================================================================

-- 1. Fijar contexto autoritativo de base de datos
USE `bd_admongas_v2_preprod`;

-- 2. Eliminación ordenada de objetos de auditoría (Completamente calificados)
DROP TRIGGER IF EXISTS `bd_admongas_v2_preprod`.`tg_op_rh_v2_marcaciones_intentos_auditoria_no_update`;
DROP TRIGGER IF EXISTS `bd_admongas_v2_preprod`.`tg_op_rh_v2_marcaciones_intentos_auditoria_no_delete`;
DROP TABLE IF EXISTS `bd_admongas_v2_preprod`.`op_rh_v2_marcaciones_intentos_auditoria`;
