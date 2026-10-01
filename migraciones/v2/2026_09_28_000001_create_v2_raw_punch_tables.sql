-- =============================================================================
-- MIGRACIÓN ADITIVA V2: CREACIÓN DE ESTRUCTURAS RAW PUNCH INGESTION (SLICE V2.1)
-- Proyecto: portal-api-v.1.0.1
-- Entorno: Local / Desarrollo únicamente (PRODUCCIÓN: NO)
-- =============================================================================

-- 1. Tabla de Dispositivos (Device)
CREATE TABLE IF NOT EXISTS `op_rh_v2_dispositivos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `uuid_dispositivo` VARCHAR(64) NOT NULL,
  `id_estacion` INT NOT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  `timezone` VARCHAR(50) DEFAULT 'America/Mexico_City',
  `estado` TINYINT(1) DEFAULT 1,
  `creado_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_uuid_dispositivo` (`uuid_dispositivo`),
  KEY `idx_estacion` (`id_estacion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla de Credenciales de Dispositivo con Historial (DeviceCredential)
CREATE TABLE IF NOT EXISTS `op_rh_v2_dispositivo_credenciales` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_dispositivo` INT NOT NULL,
  `credential_key` VARCHAR(64) NOT NULL,
  `secret_hash` VARCHAR(255) NOT NULL,
  `valido_desde` DATETIME NOT NULL,
  `valido_hasta` DATETIME NULL,
  `estado` VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  `creado_at` DATETIME NOT NULL,
  `revocado_at` DATETIME NULL,
  `active_key` VARCHAR(10) GENERATED ALWAYS AS (IF(`estado` = 'ACTIVE', 'ACTIVE', NULL)) VIRTUAL,
  UNIQUE KEY `idx_credential_key` (`credential_key`),
  UNIQUE KEY `idx_dispositivo_credencial_activa` (`id_dispositivo`, `active_key`),
  KEY `idx_id_dispositivo` (`id_dispositivo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabla de Identidades Externas de Personal (DeviceWorkerIdentity)
CREATE TABLE IF NOT EXISTS `op_rh_v2_dispositivo_identidad_personal` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_dispositivo` INT NOT NULL,
  `referencia_externa_personal` VARCHAR(64) NOT NULL,
  `id_personal` INT NOT NULL,
  `valido_desde` DATETIME NOT NULL,
  `valido_hasta` DATETIME NULL,
  `es_activo` TINYINT(1) DEFAULT 1,
  `creado_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `identidad_activa_key` VARCHAR(10) GENERATED ALWAYS AS (IF(`es_activo` = 1, 'ACTIVE', NULL)) VIRTUAL,
  UNIQUE KEY `idx_identidad_activa` (`id_dispositivo`, `referencia_externa_personal`, `identidad_activa_key`),
  KEY `idx_disp_ref_busqueda` (`id_dispositivo`, `referencia_externa_personal`, `valido_desde`, `valido_hasta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabla de Marcaciones Crudas (PunchEvent)
CREATE TABLE IF NOT EXISTS `op_rh_v2_marcaciones_crudas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `event_uuid` VARCHAR(64) NOT NULL,
  `id_dispositivo` INT NOT NULL,
  `id_estacion_resuelta` INT NULL,
  `referencia_externa_personal` VARCHAR(64) NOT NULL,
  `id_personal_resuelto` INT NULL,
  `source_timestamp_raw` VARCHAR(64) NOT NULL,
  `source_timezone_reported` VARCHAR(50) NULL,
  `effective_timezone` VARCHAR(50) NULL,
  `timezone_fallback_applied` TINYINT(1) NOT NULL DEFAULT 0,
  `ocurrido_at_utc` DATETIME NULL,
  `recibido_at_utc` DATETIME NOT NULL,
  `metodo_captura` VARCHAR(32) NOT NULL DEFAULT 'BIOMETRIC',
  `external_event_id` VARCHAR(64) NULL,
  `secuencia_dispositivo` INT NULL,
  `processing_status` VARCHAR(32) NOT NULL DEFAULT 'RECEIVED',
  `validation_flags` TEXT NULL,
  `metadata_json` TEXT NULL,
  UNIQUE KEY `idx_idempotencia_dispositivo_event` (`id_dispositivo`, `event_uuid`),
  KEY `idx_dispositivo_timestamp` (`id_dispositivo`, `ocurrido_at_utc`),
  KEY `idx_personal_timestamp` (`id_personal_resuelto`, `ocurrido_at_utc`),
  KEY `idx_estado_procesamiento` (`processing_status`),
  KEY `idx_referencia_externa` (`id_dispositivo`, `referencia_externa_personal`),
  KEY `idx_external_event_id` (`id_dispositivo`, `external_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
