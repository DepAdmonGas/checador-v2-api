-- =============================================================================
-- MIGRACIÓN ADITIVA V2.6-M: ROLLBACK SCHEMA COMPARACIÓN PARALELA
-- Submódulo Adaptador de Comparación Paralela (Legacy vs Checador V2)
-- Business Terminology: comparación paralela sin afectar nómina
-- Spec Reference: docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md (FROZEN_V1.0)
-- =============================================================================

DROP TRIGGER IF EXISTS `tg_op_rh_v2_comparaciones_encabezado_no_delete`;
DROP TRIGGER IF EXISTS `tg_op_rh_v2_comparaciones_encabezado_no_update`;

DROP TABLE IF EXISTS `op_rh_v2_comparaciones_discrepancias`;
DROP TABLE IF EXISTS `op_rh_v2_comparaciones_legacy_incidencias`;
DROP TABLE IF EXISTS `op_rh_v2_comparaciones_legacy_capturas`;
DROP TABLE IF EXISTS `op_rh_v2_comparaciones_encabezado`;
