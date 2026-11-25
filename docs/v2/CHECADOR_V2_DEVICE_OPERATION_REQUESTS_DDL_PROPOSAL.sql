-- PROPUESTA V1.1 — no ejecutar sin revisión de INFORMATION_SCHEMA y autorización operativa.
-- Sustituye op_rh_v2_device_identity_sync_requests; no crear ambas tablas.
-- Los INT deben coincidir exactamente (incluido UNSIGNED) con las PK referenciadas.
CREATE TABLE op_rh_v2_device_operation_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_dispositivo INT NOT NULL,
  id_estacion INT NOT NULL,
  id_credencial INT NOT NULL,
  request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  operation_kind ENUM('IDENTITY_SYNC','BIOMETRIC_CREATE','BIOMETRIC_REPLACE') NOT NULL,
  fingerprint_key_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  id_personal INT NULL,
  actor_user_id INT NULL,
  biometric_revision BIGINT UNSIGNED NULL,
  response_status SMALLINT UNSIGNED NOT NULL,
  response_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  created_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_device_operation_request (id_dispositivo, request_id),
  UNIQUE KEY uk_worker_biometric_revision (id_personal, biometric_revision),
  KEY idx_device_operation_created (id_dispositivo, created_at_utc),
  CONSTRAINT chk_device_operation_uuid_v4 CHECK (
    request_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
  ),
  CONSTRAINT chk_device_operation_fingerprint CHECK (request_fingerprint REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_device_operation_response_json CHECK (JSON_VALID(response_json)),
  CONSTRAINT chk_device_operation_status CHECK (
    (operation_kind='IDENTITY_SYNC' AND response_status=200)
    OR (operation_kind='BIOMETRIC_CREATE' AND response_status IN (201,409))
    OR (operation_kind='BIOMETRIC_REPLACE' AND response_status IN (200,409))
  ),
  CONSTRAINT chk_device_operation_revision_value CHECK (biometric_revision IS NULL OR biometric_revision > 0),
  CONSTRAINT chk_device_operation_revision CHECK (
    (operation_kind IN ('BIOMETRIC_CREATE','BIOMETRIC_REPLACE') AND id_personal IS NOT NULL AND actor_user_id IS NOT NULL)
    OR (operation_kind='IDENTITY_SYNC' AND id_personal IS NULL AND actor_user_id IS NULL AND biometric_revision IS NULL)
  ),
  CONSTRAINT fk_device_operation_device FOREIGN KEY (id_dispositivo)
    REFERENCES op_rh_v2_dispositivos (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_device_operation_station FOREIGN KEY (id_estacion)
    REFERENCES tb_estaciones (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_device_operation_credential FOREIGN KEY (id_credencial)
    REFERENCES op_rh_v2_dispositivo_credenciales (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_device_operation_worker FOREIGN KEY (id_personal)
    REFERENCES op_rh_personal (id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_device_operation_actor FOREIGN KEY (actor_user_id)
    REFERENCES tb_usuarios (id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB;

-- API runtime additions. Bind these to the actual API principal/database after read-only preflight.
-- GRANT SELECT, INSERT ON <db>.op_rh_v2_device_operation_requests TO '<api_user>'@'<host>';
-- GRANT SELECT (id, id_personal, huella), UPDATE (huella)
--   ON <db>.op_rh_personal_acceso TO '<api_user>'@'<host>';
-- GRANT SELECT, INSERT ON <db>.op_rh_v2_dispositivo_identidad_personal TO '<api_user>'@'<host>';
-- GRANT SELECT, UPDATE (last_activity_at_utc)
--   ON <db>.op_rh_v2_admin_session TO '<api_user>'@'<host>';
-- Existing read-only grants must cover device, credential, grant, station, locality, personnel,
-- canonical membership, and the effective permission tables. Portal3 receives no grant here.
