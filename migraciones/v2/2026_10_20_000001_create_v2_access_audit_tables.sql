-- =============================================================================
-- MIGRACIÓN ADITIVA V2: TABLA DE AUDITORÍA DE INTENTOS RECHAZADOS (GATEKEEPER V1.4 - API-02)
-- Proyecto: portal-api-v.1.0.1
-- Entorno: Preproducción exclusivamente (`bd_admongas_v2_preprod`)
-- Autorización: Preparación y preflight controlado por el Project Owner.
-- ADVERTENCIA: Esta migración está condicionada a preflight exitoso.
--              NO autoriza ejecución automática ni despliegue en producción.
-- =============================================================================

-- 1. Fijar contexto autoritativo de base de datos
USE `bd_admongas_v2_preprod`;

-- 2. Creación aditiva de tabla de auditoría de accesos (Completamente calificada)
CREATE TABLE IF NOT EXISTS `bd_admongas_v2_preprod`.`op_rh_v2_marcaciones_intentos_auditoria` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `attempt_uuid` VARCHAR(64) NOT NULL,
  `id_dispositivo` INT NOT NULL,
  `id_estacion_resuelta` INT NOT NULL,
  `referencia_externa_personal` VARCHAR(64) NOT NULL,
  `id_personal_resuelto` INT NULL,
  `event_type` VARCHAR(50) NOT NULL,
  `rejection_reason` VARCHAR(50) NOT NULL,
  `inferred_direction` VARCHAR(20) NOT NULL,
  `attempt_timestamp_local` DATETIME NOT NULL,
  `scheduled_start_local` TIME NULL DEFAULT NULL,
  `earliest_allowed_entry_local` TIME NULL DEFAULT NULL,
  `device_clock_status` VARCHAR(30) NULL DEFAULT NULL,
  `clock_drift_seconds` INT NULL DEFAULT NULL,
  `client_version` VARCHAR(128) NULL DEFAULT NULL,
  `payload_sha256` CHAR(64) NOT NULL,
  `payload_json` TEXT NULL,
  `recibido_at_utc` DATETIME NOT NULL,
  UNIQUE KEY `idx_dispositivo_attempt_uuid` (`id_dispositivo`, `attempt_uuid`),
  KEY `idx_attempt_uuid` (`attempt_uuid`),
  KEY `idx_estacion_recibido` (`id_estacion_resuelta`, `recibido_at_utc`),
  KEY `idx_personal_recibido` (`id_personal_resuelto`, `recibido_at_utc`),
  KEY `idx_rejection_reason` (`rejection_reason`),
  KEY `idx_dispositivo_fecha` (`id_dispositivo`, `attempt_timestamp_local`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Inmutabilidad Append-Only a nivel BD (Protección de evidencia forense)
-- Nota de seguridad: Se omite DROP TRIGGER IF EXISTS; ante triggers preexistentes
-- la ejecución se detiene con error ER_TRG_ALREADY_EXISTS sin sobrescribir objetos.
DELIMITER $$

CREATE TRIGGER `bd_admongas_v2_preprod`.`tg_op_rh_v2_marcaciones_intentos_auditoria_no_update`
BEFORE UPDATE ON `bd_admongas_v2_preprod`.`op_rh_v2_marcaciones_intentos_auditoria`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. UPDATE operations are strictly prohibited on op_rh_v2_marcaciones_intentos_auditoria.';
END$$

CREATE TRIGGER `bd_admongas_v2_preprod`.`tg_op_rh_v2_marcaciones_intentos_auditoria_no_delete`
BEFORE DELETE ON `bd_admongas_v2_preprod`.`op_rh_v2_marcaciones_intentos_auditoria`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. DELETE operations are strictly prohibited on op_rh_v2_marcaciones_intentos_auditoria.';
END$$

DELIMITER ;
