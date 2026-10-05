-- Migración Level 2 Server Prerequisites & Capabilities V2.4
-- Submódulo API Level 2: Registro de Generaciones, Historial de Asignaciones, Telemetría Operativa, Políticas de Cobertura y Evaluaciones Nivel 2

-- 1. Registro de Generaciones de Dispositivos (Device Generation Registry)
CREATE TABLE IF NOT EXISTS `op_rh_v2_dispositivo_generaciones` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_dispositivo` INT NOT NULL,
  `device_uuid` VARCHAR(64) NOT NULL,
  `device_generation` VARCHAR(64) NOT NULL,
  `estado` ENUM('ACTIVE', 'CLOSED') NOT NULL DEFAULT 'ACTIVE',
  `registrado_at_utc` DATETIME NOT NULL,
  `cerrado_at_utc` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_device_generation` (`device_uuid`, `device_generation`),
  KEY `idx_disp_gen_estado` (`id_dispositivo`, `estado`),
  CONSTRAINT `fk_disp_gen_dispositivo` FOREIGN KEY (`id_dispositivo`) REFERENCES `op_rh_v2_dispositivos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Historial de Asignación de Dispositivos a Estaciones/Localidades (Historical Device Assignment)
CREATE TABLE IF NOT EXISTS `op_rh_v2_dispositivo_estacion_historial` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_dispositivo` INT NOT NULL,
  `id_estacion` INT NOT NULL,
  `valido_desde_utc` DATETIME NOT NULL,
  `valido_hasta_utc` DATETIME NULL,
  `creado_at_utc` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_disp_estacion_intervalo` (`id_dispositivo`, `id_estacion`, `valido_desde_utc`, `valido_hasta_utc`),
  CONSTRAINT `fk_disp_estacion_dispositivo` FOREIGN KEY (`id_dispositivo`) REFERENCES `op_rh_v2_dispositivos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Sidecar de Secuencias y Metadatos Nivel 2 para Marcaciones (Sequence-Aware Ingestion Sidecar)
CREATE TABLE IF NOT EXISTS `op_rh_v2_marcaciones_nivel2_metadata` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_raw_punch` INT NOT NULL,
  `id_dispositivo` INT NOT NULL,
  `device_generation` VARCHAR(64) NOT NULL,
  `device_sequence` BIGINT UNSIGNED NOT NULL,
  `es_governado_nivel2` TINYINT(1) NOT NULL DEFAULT 1,
  `creado_at_utc` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_raw_punch_l2` (`id_raw_punch`),
  UNIQUE KEY `uk_gen_sequence` (`id_dispositivo`, `device_generation`, `device_sequence`),
  KEY `idx_disp_gen_seq` (`id_dispositivo`, `device_generation`, `device_sequence`),
  CONSTRAINT `fk_marcacion_l2_raw` FOREIGN KEY (`id_raw_punch`) REFERENCES `op_rh_v2_marcaciones_crudas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Telemetría Operativa del Dispositivo (Operational Telemetry History)
CREATE TABLE IF NOT EXISTS `op_rh_v2_dispositivo_telemetria` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_dispositivo` INT NOT NULL,
  `device_generation` VARCHAR(64) NOT NULL,
  `telemetry_sequence` BIGINT UNSIGNED NOT NULL,
  `observation_timestamp_raw` VARCHAR(64) NOT NULL,
  `observado_at_utc` DATETIME NULL,
  `server_received_at_utc` DATETIME NOT NULL,
  `battery_level` INT NULL,
  `network_status` VARCHAR(32) NULL,
  `clock_status` VARCHAR(32) NULL,
  `payload_json` JSON NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_disp_gen_telem_seq` (`id_dispositivo`, `device_generation`, `telemetry_sequence`),
  KEY `idx_disp_gen_time` (`id_dispositivo`, `device_generation`, `observado_at_utc`),
  CONSTRAINT `fk_telemetria_dispositivo` FOREIGN KEY (`id_dispositivo`) REFERENCES `op_rh_v2_dispositivos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Registro Inmutable de Políticas de Cobertura (Coverage Policy Registry)
CREATE TABLE IF NOT EXISTS `op_rh_v2_coverage_policies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coverage_policy_ref` VARCHAR(64) NOT NULL,
  `max_heartbeat_gap_seconds` INT NOT NULL DEFAULT 900,
  `max_clock_drift_seconds` INT NOT NULL DEFAULT 300,
  `require_telemetry_continuity` TINYINT(1) NOT NULL DEFAULT 1,
  `es_activo` TINYINT(1) NOT NULL DEFAULT 1,
  `creado_at_utc` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_policy_ref` (`coverage_policy_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar política por defecto immutable V1.0
INSERT IGNORE INTO `op_rh_v2_coverage_policies` (`coverage_policy_ref`, `max_heartbeat_gap_seconds`, `max_clock_drift_seconds`, `require_telemetry_continuity`, `es_activo`, `creado_at_utc`) 
VALUES ('POLICY_V1.0_DEFAULT', 900, 300, 1, 1, '2026-10-05 00:00:00');

-- 6. Registro de Evaluaciones de Ventana Nivel 2 (Level 2 Window Evaluations)
CREATE TABLE IF NOT EXISTS `op_rh_v2_level2_window_evaluations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `evaluation_ref` VARCHAR(64) NOT NULL,
  `id_estacion` INT NOT NULL,
  `window_from_utc` DATETIME NOT NULL,
  `window_to_utc` DATETIME NOT NULL,
  `coverage_policy_ref` VARCHAR(64) NOT NULL,
  `relevant_device_generations_json` JSON NOT NULL,
  `delivery_completeness` ENUM('VERIFIED', 'INCOMPLETE', 'UNKNOWN') NOT NULL,
  `operational_coverage` ENUM('VERIFIED', 'INCOMPLETE', 'UNKNOWN') NOT NULL,
  `time_window_attribution` ENUM('VERIFIED', 'DEGRADED', 'UNKNOWN') NOT NULL,
  `window_level2_status` ENUM('VERIFIED', 'INCOMPLETE', 'UNKNOWN') NOT NULL,
  `has_zero_governed_events` TINYINT(1) NOT NULL DEFAULT 0,
  `evaluated_at_utc` DATETIME NOT NULL,
  `manifest_digest` VARCHAR(64) NOT NULL,
  `canonical_evidence_json` LONGTEXT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_eval_ref` (`evaluation_ref`),
  KEY `idx_estacion_window` (`id_estacion`, `window_from_utc`, `window_to_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
