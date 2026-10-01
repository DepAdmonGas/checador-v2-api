-- =============================================================================
-- MIGRACIÓN ADITIVA V2 STRICT CONTRACT: ESTRUCTURAS ACCESO TEMPORAL (SLICE V2.2-G8-A2)
-- Proyecto: portal-api-v.1.0.1
-- Entorno: Local / Desarrollo únicamente (PRODUCCIÓN: NO)
-- =============================================================================

-- Tabla Única de Solicitudes de Acceso Temporal (op_rh_v2_solicitudes_acceso_temporal)
CREATE TABLE IF NOT EXISTS `op_rh_v2_solicitudes_acceso_temporal` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `request_uuid` VARCHAR(64) NOT NULL,
  `successful_validation_uuid` VARCHAR(64) NULL,
  `id_dispositivo` INT NOT NULL,
  `id_estacion` INT NOT NULL,
  `referencia_externa_personal` VARCHAR(64) NOT NULL,
  `id_personal_resuelto` INT NOT NULL,
  `reason` VARCHAR(100) NOT NULL DEFAULT 'FINGERPRINT_NOT_RECOGNIZED',
  `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  `pin_hash` VARCHAR(255) NULL,
  `pin_failed_attempts` INT NOT NULL DEFAULT 0,
  `requested_at_utc` DATETIME NOT NULL,
  `request_expires_at_utc` DATETIME NOT NULL,
  `authorized_by_user_id` INT NULL,
  `authorized_at_utc` DATETIME NULL,
  `rejected_by_user_id` INT NULL,
  `rejected_at_utc` DATETIME NULL,
  `cancelled_by_user_id` INT NULL,
  `cancelled_at_utc` DATETIME NULL,
  `pin_expires_at_utc` DATETIME NULL,
  `used_at_utc` DATETIME NULL,
  UNIQUE KEY `idx_request_uuid` (`request_uuid`),
  UNIQUE KEY `idx_successful_validation` (`successful_validation_uuid`),
  KEY `idx_estacion_status` (`id_estacion`, `status`),
  KEY `idx_disp_ref_status` (`id_dispositivo`, `referencia_externa_personal`, `status`),
  KEY `idx_request_expires` (`request_expires_at_utc`),
  KEY `idx_pin_expires` (`pin_expires_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
