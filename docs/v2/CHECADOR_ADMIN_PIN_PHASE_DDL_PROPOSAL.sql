-- HISTORICAL PROPOSAL: owner reports these three tables already created in Fedora and Plesk.
-- Do not execute this file; compare effective schema, types, InnoDB, privileges, and FK targets read-only.
-- This phase excludes biometric enrollment and its admin_operation table.
CREATE TABLE op_rh_v2_admin_pin (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 authorization_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 device_id INT NOT NULL, credential_id INT NOT NULL, station_id INT NOT NULL, locality_id INT NOT NULL, actor_user_id INT NOT NULL,
 pin_hash VARCHAR(255) NOT NULL, state VARCHAR(16) NOT NULL, failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 issued_at_utc DATETIME(6) NOT NULL, expires_at_utc DATETIME(6) NOT NULL, consumed_at_utc DATETIME(6) NULL, revoked_at_utc DATETIME(6) NULL,
 pending_device_id INT GENERATED ALWAYS AS (IF(state='PENDING',device_id,NULL)) STORED,
 UNIQUE KEY uk_admin_pin_uuid (authorization_uuid), UNIQUE KEY uk_admin_pin_pending (pending_device_id), KEY idx_admin_pin_scope (device_id, station_id, state)
) ENGINE=InnoDB;
CREATE TABLE op_rh_v2_admin_session (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 session_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 pin_id BIGINT UNSIGNED NOT NULL, device_id INT NOT NULL, credential_id INT NOT NULL, station_id INT NOT NULL, locality_id INT NOT NULL, actor_user_id INT NOT NULL,
 session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, state VARCHAR(16) NOT NULL,
 created_at_utc DATETIME(6) NOT NULL, expires_at_utc DATETIME(6) NOT NULL, last_activity_at_utc DATETIME(6) NOT NULL, ended_at_utc DATETIME(6) NULL,
 active_device_id INT GENERATED ALWAYS AS (IF(state='ACTIVE',device_id,NULL)) STORED,
 UNIQUE KEY uk_admin_session_uuid (session_uuid), UNIQUE KEY uk_admin_session_hash (session_hash), UNIQUE KEY uk_admin_session_pin (pin_id), UNIQUE KEY uk_admin_session_device (active_device_id),
 CONSTRAINT fk_admin_session_pin FOREIGN KEY (pin_id) REFERENCES op_rh_v2_admin_pin(id)
) ENGINE=InnoDB;
CREATE TABLE op_rh_v2_admin_pin_limit (
 device_id INT NOT NULL PRIMARY KEY, window_started_at_utc DATETIME(6) NOT NULL,
 failed_attempts INT UNSIGNED NOT NULL DEFAULT 0, blocked_until_utc DATETIME(6) NULL
) ENGINE=InnoDB;
