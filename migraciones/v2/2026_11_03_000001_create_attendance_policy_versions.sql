-- M11 additive, append-only attendance policy history. Development only.
CREATE TABLE IF NOT EXISTS `op_rh_v2_attendance_policy_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `workplace_id` INT UNSIGNED NOT NULL,
  `policy_version` INT UNSIGNED NOT NULL,
  `effective_from_local_date` DATE NOT NULL,
  `timezone_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `retardo_tolerance_minutes` INT UNSIGNED NOT NULL,
  `incidence_deadline_days` INT UNSIGNED NOT NULL,
  `actor_portal_user_id` INT UNSIGNED NOT NULL,
  `source` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `idempotency_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_attendance_policy_workplace_version` (`workplace_id`, `policy_version`),
  UNIQUE KEY `uk_attendance_policy_effective_date` (`workplace_id`, `effective_from_local_date`),
  UNIQUE KEY `uk_attendance_policy_idempotency` (`workplace_id`, `idempotency_key`),
  KEY `idx_attendance_policy_effective_lookup` (`workplace_id`, `effective_from_local_date`, `policy_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
