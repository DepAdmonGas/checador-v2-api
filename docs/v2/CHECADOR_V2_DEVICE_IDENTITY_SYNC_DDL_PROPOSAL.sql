-- Proposal only: verify target types and privileges; do not execute on preproduction without authorization.
CREATE TABLE op_rh_v2_device_identity_sync_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_dispositivo INT NOT NULL,
  id_estacion INT NOT NULL,
  request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  response_json LONGTEXT NOT NULL,
  created_at_utc DATETIME(6) NOT NULL,
  UNIQUE KEY uk_identity_sync_request (id_dispositivo,request_id)
) ENGINE=InnoDB;
