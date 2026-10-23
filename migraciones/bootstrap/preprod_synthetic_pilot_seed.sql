-- =============================================================================
-- CHECADOR V2 — PREPRODUCTION SYNTHETIC PILOT SEED (OPCIÓN C)
-- =============================================================================
-- Archivo: migraciones/bootstrap/preprod_synthetic_pilot_seed.sql
-- Propósito: Población controlada con datos maestros estrictamente sintéticos
--            para las 10 tablas legacy del baseline de bd_admongas_v2_preprod.
-- Invariantes de Seguridad y Calidad:
--   1. DATOS 100% SINTÉTICOS: Cero datos personales reales, cero trabajadores reales,
--      cero números de teléfono, RFC, CURP o correos reales.
--   2. CERO SECRETOS: No almacena contraseñas, PINs, tokens JWT, llaves HMAC ni secretos.
--   3. NO DESTRUCTIVO: Prohibido DROP, TRUNCATE, DELETE, UPDATE, REPLACE INTO.
--   4. SIN FORZAR INTEGRIDAD: Prohibido SET FOREIGN_KEY_CHECKS = 0.
--   5. INTEGRIDAD REFERENCIAL TOPOLÓGICA: Inserts ordenados estrictamente para
--      satisfacer dependencias lógicas y claves foráneas.
--   6. AISLAMIENTO: Diseñado exclusivamente para la base de datos de preproducción
--      (bd_admongas_v2_preprod) autorizada por el Project Owner.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. TABLA: op_rh_localidades
-- Catálogo sintético de localidades físicas para mapeo de centros de trabajo.
-- Destino de clave foránea de op_rh_v2_localidades_zonas_horarias (id_localidad).
-- -----------------------------------------------------------------------------
INSERT INTO `op_rh_localidades` (`id`, `numlista`, `localidad`, `recuperacion_vapores`) VALUES
  (1, 1, 'LOCALIDAD PILOTO 01 - NORTE', '0'),
  (2, 2, 'LOCALIDAD PILOTO 02 - SUR', '0');

-- -----------------------------------------------------------------------------
-- 2. TABLA: tb_estaciones
-- Catálogo sintético de estaciones de servicio activas (estatus = 0).
-- Mapea numlista a las localidades 1 y 2.
-- -----------------------------------------------------------------------------
INSERT INTO `tb_estaciones` (`id`, `numlista`, `nombre`, `estatus`) VALUES
  (1, 1, 'ESTACION PILOTO 01 - NORTE', 0),
  (2, 2, 'ESTACION PILOTO 02 - SUR', 0);

-- -----------------------------------------------------------------------------
-- 3. TABLA: modulos_dpto_operativo
-- Catálogo de módulos funcionales requeridos por Portal3JwtAuthHelper y V2.
-- Claves canónicas verificadas desde código consumidor.
-- -----------------------------------------------------------------------------
INSERT INTO `modulos_dpto_operativo` (`id`, `nombre`, `clave`, `activo`) VALUES
  (1, 'Control Biometrico y Asistencia Piloto', 'biometricos', 1),
  (2, 'Acceso Temporal Piloto', 'acceso-temporal', 1),
  (3, 'Checador V2 Incidencias Lectura Piloto', 'checador-v2-incidencias-lectura', 1),
  (4, 'Checador V2 Incidencias Creacion Piloto', 'checador-v2-incidencias-creacion', 1),
  (5, 'Checador V2 Incidencias Resolucion Piloto', 'checador-v2-incidencias-resolucion', 1),
  (6, 'Checador V2 Incidencias Autorizacion Piloto', 'checador-v2-incidencias-autorizacion', 1);

-- -----------------------------------------------------------------------------
-- 4. TABLA: tb_modulos_config
-- Configuración de alcance geográfico por módulo para estaciones piloto (1, 2).
-- Requiere activo = 1 y tipo_departamento = 'localidades'.
-- -----------------------------------------------------------------------------
INSERT INTO `tb_modulos_config` (`id`, `modulo_key`, `tipo`, `estaciones_soportadas`, `tipo_departamento`, `allow_all`, `activo`) VALUES
  (1, 'biometricos', 'stations_only', '[1, 2]', 'localidades', 0, 1),
  (2, 'acceso-temporal', 'stations_only', '[1, 2]', 'localidades', 0, 1),
  (3, 'checador-v2-incidencias-lectura', 'stations_only', '[1, 2]', 'localidades', 0, 1),
  (4, 'checador-v2-incidencias-creacion', 'stations_only', '[1, 2]', 'localidades', 0, 1),
  (5, 'checador-v2-incidencias-resolucion', 'stations_only', '[1, 2]', 'localidades', 0, 1),
  (6, 'checador-v2-incidencias-autorizacion', 'stations_only', '[1, 2]', 'localidades', 0, 1);

-- -----------------------------------------------------------------------------
-- 5. TABLA: tb_usuarios
-- Usuarios sintéticos de prueba para emisión y verificación de JWTs Portal3.
-- Semántica verificada: estatus = 0 indica usuario ACTIVO.
-- Nota de seguridad: No contiene campos de contraseña ni credenciales reales.
-- Convención de IDs: Rango 100+ para usuarios sintéticos administrativos.
-- -----------------------------------------------------------------------------
INSERT INTO `tb_usuarios` (`id`, `usuario`, `id_gas`, `id_puesto`, `estatus`) VALUES
  (101, 'usuario.piloto.admin', 1, 10, 0),
  (102, 'usuario.piloto.operador', 2, 20, 0);

-- -----------------------------------------------------------------------------
-- 6. TABLA: modulos_puestos_do
-- Permisos RBAC sintéticos a nivel de puesto.
-- Puesto 10: Perfil Administrador Piloto (acceso amplio a módulos 1..6).
-- Puesto 20: Perfil Operador Piloto (acceso de solo lectura a módulos 1..3).
-- -----------------------------------------------------------------------------
INSERT INTO `modulos_puestos_do` (`id`, `id_modulo`, `id_puesto`, `leer`, `crear`, `editar`, `eliminar`, `descargar`) VALUES
  (1, 1, 10, 1, 1, 1, 0, 1),
  (2, 2, 10, 1, 1, 1, 0, 1),
  (3, 3, 10, 1, 1, 1, 0, 1),
  (4, 4, 10, 1, 1, 1, 0, 1),
  (5, 5, 10, 1, 1, 1, 0, 1),
  (6, 6, 10, 1, 1, 1, 0, 1),
  (7, 1, 20, 1, 0, 0, 0, 0),
  (8, 2, 20, 1, 0, 0, 0, 0),
  (9, 3, 20, 1, 0, 0, 0, 0),
  (10, 4, 20, 0, 0, 0, 0, 0),
  (11, 5, 20, 0, 0, 0, 0, 0),
  (12, 6, 20, 0, 0, 0, 0, 0);

-- -----------------------------------------------------------------------------
-- 7. TABLA: modulos_usuarios_do
-- Sobrescritura sintética de permisos por usuario individual.
-- Usuario 101 cuenta con regla explícita directa sobre módulo 1 (biométricos).
-- -----------------------------------------------------------------------------
INSERT INTO `modulos_usuarios_do` (`id`, `id_modulo`, `id_usuario`, `leer`, `crear`, `editar`, `eliminar`, `descargar`) VALUES
  (1, 1, 101, 1, 1, 1, 0, 1);

-- -----------------------------------------------------------------------------
-- 8. TABLA: tb_multiestacion_puesto
-- Asignación sintética de alcance multi-estación por puesto.
-- Puesto 10 tiene alcance sobre estaciones [1, 2].
-- Puesto 20 tiene alcance sobre estación [2].
-- -----------------------------------------------------------------------------
INSERT INTO `tb_multiestacion_puesto` (`id`, `id_puesto`, `estaciones`, `activo`) VALUES
  (1, 10, '[1, 2]', 1),
  (2, 20, '[2]', 1);

-- -----------------------------------------------------------------------------
-- 9. TABLA: tb_multiestacion_usuario
-- Asignación sintética de alcance multi-estación individual por usuario.
-- Usuario 101 tiene alcance sobre estaciones [1, 2].
-- Usuario 102 tiene alcance sobre estación [2].
-- -----------------------------------------------------------------------------
INSERT INTO `tb_multiestacion_usuario` (`id`, `id_usuario`, `estaciones`, `activo`) VALUES
  (1, 101, '[1, 2]', 1),
  (2, 102, '[2]', 1);

-- -----------------------------------------------------------------------------
-- 10. TABLA: op_rh_personal
-- Catálogo sintético de trabajadores piloto para pruebas de jornada, turnos e incidencias.
-- Semántica verificada: estado = 1 indica trabajador ACTIVO.
-- Convención de IDs: Rango 1000+ para trabajadores piloto.
-- -----------------------------------------------------------------------------
INSERT INTO `op_rh_personal` (`id`, `id_estacion`, `nombre_completo`, `estado`) VALUES
  (1001, 1, 'TRABAJADOR SINTETICO ALFA', 1),
  (1002, 1, 'TRABAJADOR SINTETICO BETA', 1),
  (1003, 2, 'TRABAJADOR SINTETICO GAMMA', 1);
