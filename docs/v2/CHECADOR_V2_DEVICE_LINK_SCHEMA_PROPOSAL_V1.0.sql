-- PROPOSAL ONLY. Do not apply without schema review and explicit authorization.
-- Confirm types, FK targets, InnoDB and privileges against the effective schema first.
CREATE TABLE op_rh_v2_device_link_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  id_dispositivo INT NOT NULL,
  id_estacion INT NOT NULL,
  emitido_por_usuario_id INT NOT NULL,
  emitido_at_utc DATETIME(6) NOT NULL,
  expira_at_utc DATETIME(6) NOT NULL,
  consumido_at_utc DATETIME(6) NULL,
  revocado_at_utc DATETIME(6) NULL,
  UNIQUE KEY uk_device_link_hash (token_sha256),
  KEY idx_device_link_device (id_dispositivo, expira_at_utc)
) ENGINE=InnoDB;

CREATE TABLE op_rh_v2_device_biometric_grants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_dispositivo INT NOT NULL,
  id_estacion INT NOT NULL,
  id_credencial INT NOT NULL,
  autorizado_por_usuario_id INT NOT NULL,
  valido_desde_utc DATETIME(6) NOT NULL,
  valido_hasta_utc DATETIME(6) NULL,
  activo TINYINT NOT NULL DEFAULT 1,
  revocado_at_utc DATETIME(6) NULL,
  active_device_id INT GENERATED ALWAYS AS (IF(activo=1 AND revocado_at_utc IS NULL,id_dispositivo,NULL)) STORED,
  UNIQUE KEY uk_one_active_device_grant (active_device_id),
  KEY idx_device_biometric_scope (id_dispositivo, id_estacion, id_credencial, activo)
) ENGINE=InnoDB;
