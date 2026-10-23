-- =============================================================================
-- CHECADOR V2 — PREPRODUCTION BASELINE LEGACY SCHEMA (OPCIÓN C)
-- =============================================================================
-- Archivo: migraciones/bootstrap/preprod_baseline_legacy_schema.sql
-- Propósito: Definición DDL estricta de las 10 tablas legacy mínimas autorizadas
--            para el bootstrap inicial de bd_admongas_v2_preprod.
-- Invariantes de Seguridad:
--   1. Diseñado exclusivamente para una base de datos nueva, vacía y autorizada.
--   2. Prohibido DROP TABLE, DROP DATABASE, TRUNCATE, DELETE, ALTER, GRANT, REVOKE.
--   3. Prohibido SET FOREIGN_KEY_CHECKS = 0 (integridad referencial obligatoria).
--   4. No utiliza CREATE TABLE IF NOT EXISTS para evitar ocultar inconsistencias;
--      fallará de forma segura si alguna tabla ya existe con estructura dispar.
--   5. Compatible al 100% con las 15 migraciones V2 autorizadas (InnoDB, utf8mb4).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. TABLA: op_rh_localidades
-- -----------------------------------------------------------------------------
-- Propósito: Autoridad canónica de localidades físicas / centros de trabajo.
-- Dependencia funcional:
--   - Requerida por migración V2: 2026_10_12_000001_create_v2_workplace_timezone_authority.sql
--     mediante clave foránea física: fk_v2_localidad_timezone_localidad (id_localidad -> op_rh_localidades.id).
--   - Requerida por Portal3JwtAuthHelper::resolveStationScope() para mapear numlista a id.
-- Fuente de evidencia:
--   - DDL de migración V2 (INT(11) NOT NULL firmado).
--   - Consultas en Portal3JwtAuthHelper.php (línea 190).
-- Relación con V2: Destino de clave foránea física desde op_rh_v2_localidades_zonas_horarias.
-- Relación legacy: Mapeada desde tb_estaciones.numlista = op_rh_localidades.numlista.
CREATE TABLE `op_rh_localidades` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `numlista` INT(11) NOT NULL DEFAULT 0,
  `localidad` VARCHAR(128) NOT NULL DEFAULT '',
  `recuperacion_vapores` VARCHAR(10) NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_op_rh_localidades_numlista` (`numlista`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. TABLA: op_rh_personal
-- -----------------------------------------------------------------------------
-- Propósito: Autoridad canónica de identidad y registro de trabajadores.
-- Dependencia funcional:
--   - Requerida por ScheduleResolutionService (SELECT id FROM op_rh_personal WHERE id = ? LIMIT 1).
--   - Requerida por JourneyEvaluationV2Controller (SELECT id FROM op_rh_personal WHERE id = ? LIMIT 1).
--   - Requerida por AdministrativeIncidenceV2Controller (validación canónica de personal).
--   - Requerida por TemporaryAccessV2Service (LEFT JOIN op_rh_personal p ON r.id_personal_resuelto = p.id).
-- Fuente de evidencia:
--   - Consultas en ScheduleResolutionService.php (línea 39).
--   - Consultas en JourneyEvaluationV2Controller.php (línea 97).
--   - Consultas en AdministrativeIncidenceV2Controller.php (líneas 96, 413, 621, 2016).
-- Relación con V2: Autoridad de existencia del trabajador para turnos, jornadas e incidencias.
-- Relación legacy: Agrupa trabajadores por id_estacion y estado administrativo.
CREATE TABLE `op_rh_personal` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_estacion` INT(11) NOT NULL DEFAULT 0,
  `nombre_completo` VARCHAR(255) NOT NULL DEFAULT '',
  `estado` INT(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_op_rh_personal_estacion` (`id_estacion`),
  KEY `idx_op_rh_personal_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. TABLA: tb_usuarios
-- -----------------------------------------------------------------------------
-- Propósito: Representa los usuarios de Portal3 autorizados para emitir JWTs.
-- Dependencia funcional:
--   - Requerida por Portal3JwtAuthHelper::authenticatePortal3Jwt() para validar que el usuario
--     existe en el sistema y se encuentra activo.
--   - Invariante de estado: tb_usuarios.estatus = 0 representa estado ACTIVO en Portal3.
-- Fuente de evidencia:
--   - Consulta en Portal3JwtAuthHelper.php (línea 118: SELECT id, id_gas, id_puesto, estatus FROM tb_usuarios WHERE id = ? LIMIT 1).
--   - Verificación de estatus en Portal3JwtAuthHelper.php (línea 120: estatus !== 0 => Usuario inactivo).
-- Relación con V2: Identidad del usuario autenticado que realiza operaciones administrativas.
-- Relación legacy: Asocia al usuario con su estación base (id_gas) y su puesto (id_puesto).
CREATE TABLE `tb_usuarios` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `usuario` VARCHAR(100) NOT NULL DEFAULT '',
  `id_gas` INT(11) NOT NULL DEFAULT 0,
  `id_puesto` INT(11) NOT NULL DEFAULT 0,
  `estatus` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tb_usuarios_usuario` (`usuario`),
  KEY `idx_tb_usuarios_estatus` (`estatus`),
  KEY `idx_tb_usuarios_id_gas` (`id_gas`),
  KEY `idx_tb_usuarios_id_puesto` (`id_puesto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. TABLA: tb_estaciones
-- -----------------------------------------------------------------------------
-- Propósito: Catálogo de estaciones de servicio del ecosistema Portal3.
-- Dependencia funcional:
--   - Requerida por Portal3JwtAuthHelper::resolveStationScope() para verificar que la estación
--     está registrada y activa (estatus = 0) y obtener su numlista de mapeo a op_rh_localidades.
-- Fuente de evidencia:
--   - Consulta en Portal3JwtAuthHelper.php (línea 188: SELECT id, numlista FROM tb_estaciones WHERE id = ? AND estatus = 0 LIMIT 1).
-- Relación con V2: Puente entre los IDs de estación consumidos por Portal3 y las localidades V2.
-- Relación legacy: Registro primario de estaciones de servicio.
CREATE TABLE `tb_estaciones` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `numlista` INT(11) NOT NULL DEFAULT 0,
  `nombre` VARCHAR(150) NOT NULL DEFAULT '',
  `estatus` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_tb_estaciones_numlista` (`numlista`),
  KEY `idx_tb_estaciones_estatus` (`estatus`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. TABLA: tb_modulos_config
-- -----------------------------------------------------------------------------
-- Propósito: Configuración de alcance geográfico y activación por módulo en Portal3.
-- Dependencia funcional:
--   - Requerida por Portal3JwtAuthHelper::authorizeModuleByKey() y authorizeTemporaryAccess().
--   - Valida activo = 1, tipo_departamento = 'localidades' y extrae el arreglo JSON en estaciones_soportadas.
-- Fuente de evidencia:
--   - Consulta en Portal3JwtAuthHelper.php (línea 119: SELECT activo, estaciones_soportadas, tipo_departamento FROM tb_modulos_config WHERE modulo_key = ? LIMIT 1).
-- Relación con V2: Perímetro de estaciones habilitadas para cada módulo funcional V2.
CREATE TABLE `tb_modulos_config` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `modulo_key` VARCHAR(100) NOT NULL,
  `tipo` VARCHAR(50) NOT NULL DEFAULT 'stations_only',
  `estaciones_soportadas` LONGTEXT NULL,
  `tipo_departamento` VARCHAR(50) NOT NULL DEFAULT 'localidades',
  `allow_all` INT(11) NOT NULL DEFAULT 0,
  `activo` INT(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tb_modulos_config_modulo_key` (`modulo_key`),
  KEY `idx_tb_modulos_config_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. TABLA: modulos_dpto_operativo
-- -----------------------------------------------------------------------------
-- Propósito: Catálogo de módulos funcionales del departamento operativo en Portal3.
-- Dependencia funcional:
--   - Requerida por Portal3JwtAuthHelper::authorizeModuleByKey() para resolver el id interno
--     del módulo a partir de su clave canónica (ej. 'biometricos', 'acceso-temporal', etc.).
-- Fuente de evidencia:
--   - Consulta en Portal3JwtAuthHelper.php (línea 105: SELECT id, activo FROM modulos_dpto_operativo WHERE clave = ? LIMIT 1).
-- Relación con V2: Identificador numérico del módulo necesario para consultar modulos_puestos_do y modulos_usuarios_do.
CREATE TABLE `modulos_dpto_operativo` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(255) NOT NULL DEFAULT '',
  `clave` VARCHAR(100) NOT NULL,
  `activo` INT(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_modulos_dpto_operativo_clave` (`clave`),
  KEY `idx_modulos_dpto_operativo_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. TABLA: modulos_puestos_do
-- -----------------------------------------------------------------------------
-- Propósito: Asignación de permisos funcionales (RBAC) asociados a un puesto.
-- Dependencia funcional:
--   - Requerida por Portal3JwtAuthHelper::authorizeModuleByKey() para verificar permisos
--     cuando el usuario no tiene una regla directa individual en modulos_usuarios_do.
-- Fuente de evidencia:
--   - Consulta en Portal3JwtAuthHelper.php (línea 115: SELECT leer, crear, editar, eliminar, descargar FROM modulos_puestos_do WHERE id_modulo = ? AND id_puesto = ? LIMIT 1).
-- Relación con V2: Control de acceso para operaciones sobre turnos, incidencias y lectura biométrica.
CREATE TABLE `modulos_puestos_do` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_modulo` INT(11) NOT NULL,
  `id_puesto` INT(11) NOT NULL,
  `leer` INT(11) NOT NULL DEFAULT 0,
  `crear` INT(11) NOT NULL DEFAULT 0,
  `editar` INT(11) NOT NULL DEFAULT 0,
  `eliminar` INT(11) NOT NULL DEFAULT 0,
  `descargar` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_modulos_puestos_modulo_puesto` (`id_modulo`, `id_puesto`),
  KEY `idx_modulos_puestos_puesto` (`id_puesto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. TABLA: modulos_usuarios_do
-- -----------------------------------------------------------------------------
-- Propósito: Asignación de permisos funcionales directos por usuario individual.
-- Dependencia funcional:
--   - Requerida por Portal3JwtAuthHelper::authorizeModuleByKey() con precedencia absoluta
--     sobre los permisos configurados para el puesto.
-- Fuente de evidencia:
--   - Consulta en Portal3JwtAuthHelper.php (líneas 110-112).
-- Relación con V2: Permite sobreescribir permisos específicos para un usuario determinado en la API V2.
CREATE TABLE `modulos_usuarios_do` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_modulo` INT(11) NOT NULL,
  `id_usuario` INT(11) NOT NULL,
  `leer` INT(11) NOT NULL DEFAULT 0,
  `crear` INT(11) NOT NULL DEFAULT 0,
  `editar` INT(11) NOT NULL DEFAULT 0,
  `eliminar` INT(11) NOT NULL DEFAULT 0,
  `descargar` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_modulos_usuarios_modulo_usuario` (`id_modulo`, `id_usuario`),
  KEY `idx_modulos_usuarios_usuario` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. TABLA: tb_multiestacion_puesto
-- -----------------------------------------------------------------------------
-- Propósito: Alcance geográfico de estaciones asignado a nivel de puesto.
-- Dependencia funcional:
--   - Requerida por Portal3JwtAuthHelper::resolveStationScope() para puestos con cobertura multi-estación.
-- Fuente de evidencia:
--   - Consulta en Portal3JwtAuthHelper.php (línea 169: SELECT estaciones FROM tb_multiestacion_puesto WHERE id_puesto = ? AND activo = 1 LIMIT 1).
-- Relación con V2: Determina el conjunto de estaciones sobre las cuales un puesto puede consultar o evaluar jornadas.
CREATE TABLE `tb_multiestacion_puesto` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_puesto` INT(11) NOT NULL,
  `estaciones` TEXT NULL,
  `activo` INT(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_multiestacion_puesto_puesto_activo` (`id_puesto`, `activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. TABLA: tb_multiestacion_usuario
-- -----------------------------------------------------------------------------
-- Propósito: Alcance geográfico de estaciones asignado directamente a un usuario.
-- Dependencia funcional:
--   - Requerida por Portal3JwtAuthHelper::resolveStationScope() con máxima precedencia
--     para determinar el alcance multi-estación individual.
-- Fuente de evidencia:
--   - Consulta en Portal3JwtAuthHelper.php (línea 168: SELECT estaciones FROM tb_multiestacion_usuario WHERE id_usuario = ? AND activo = 1 LIMIT 1).
-- Relación con V2: Control perimetral de acceso multi-estación para usuarios administradores.
CREATE TABLE `tb_multiestacion_usuario` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` INT(11) NOT NULL,
  `estaciones` TEXT NULL,
  `activo` INT(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_multiestacion_usuario_usuario_activo` (`id_usuario`, `activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
