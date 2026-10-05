-- Migración Level 1 Read Snapshot API V2.4
-- Creación de tablas para metadatos de snapshot, miembros de acceso transitorios y manifiestos de auditoría durables.

CREATE TABLE IF NOT EXISTS `op_rh_v2_read_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_token` VARCHAR(64) NOT NULL,
  `snapshot_audit_ref` VARCHAR(64) NOT NULL,
  `id_usuario_creador` INT NOT NULL,
  `creation_request_uuid` VARCHAR(64) NULL,
  `estado` ENUM('CREATING', 'READY', 'FAILED', 'EXPIRED') NOT NULL DEFAULT 'CREATING',
  `scope_estaciones_json` JSON NOT NULL,
  `filtros_json` JSON NOT NULL,
  `total_records` INT NOT NULL DEFAULT 0,
  `creado_at_utc` DATETIME NOT NULL,
  `expira_at_utc` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_snapshot_token` (`snapshot_token`),
  UNIQUE KEY `uk_snapshot_audit_ref` (`snapshot_audit_ref`),
  UNIQUE KEY `uk_creation_request_uuid` (`creation_request_uuid`),
  KEY `idx_id_usuario_creador` (`id_usuario_creador`),
  KEY `idx_estado_expira` (`estado`, `expira_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `op_rh_v2_read_snapshot_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_snapshot` BIGINT UNSIGNED NOT NULL,
  `member_order` INT NOT NULL,
  `id_raw_punch` BIGINT UNSIGNED NOT NULL,
  `id_dispositivo` INT NOT NULL,
  `event_uuid` VARCHAR(36) NOT NULL,
  `ocurrido_at_utc` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_snapshot_member_order` (`id_snapshot`, `member_order`),
  KEY `idx_snapshot_cursor_order` (`id_snapshot`, `ocurrido_at_utc`, `id_dispositivo`, `event_uuid`, `id`),
  CONSTRAINT `fk_snapshot_members_snapshot` FOREIGN KEY (`id_snapshot`) REFERENCES `op_rh_v2_read_snapshots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `op_rh_v2_durable_snapshot_manifests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_audit_ref` VARCHAR(64) NOT NULL,
  `contract_version` VARCHAR(16) NOT NULL DEFAULT 'V2.4_LEVEL1',
  `creado_at_utc` DATETIME NOT NULL,
  `id_usuario_creador` INT NOT NULL,
  `scope_estaciones_hash` VARCHAR(64) NOT NULL,
  `filtros_hash` VARCHAR(64) NOT NULL,
  `member_count` INT NOT NULL,
  `manifest_digest` VARCHAR(64) NOT NULL,
  `canonical_manifest_json` LONGTEXT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_manifest_audit_ref` (`snapshot_audit_ref`),
  KEY `idx_manifest_creado` (`creado_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
