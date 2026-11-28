-- Test-only DDL copied faithfully from Portal3 migration
-- WorkerWorkplaceAssignmentVersionsMigration::up(), reference
-- DepAdmonGas/Portal3@d72aaa88f737f2ddd7cea8906b3d54c5ed652c7a.
-- This file is not a product migration and must only be applied to an empty,
-- private, network-disabled MariaDB fixture after its parent tables exist.
CREATE TABLE `op_rh_personal_localidad_version` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `worker_id` INT NOT NULL,
  `workplace_id` INT NOT NULL,
  `valid_from_utc` DATETIME(6) NOT NULL,
  `valid_to_utc` DATETIME(6) NULL,
  `recorded_at_utc` DATETIME(6) NOT NULL,
  `recorded_by` INT NULL,
  `reason` VARCHAR(500) NULL,
  `supersedes_id` INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `op_rh_pwa_worker_workplace_effective_idx` (`worker_id`, `workplace_id`, `valid_from_utc`, `valid_to_utc`),
  KEY `op_rh_pwa_worker_recorded_idx` (`worker_id`, `recorded_at_utc`),
  KEY `op_rh_pwa_workplace_idx` (`workplace_id`),
  UNIQUE KEY `op_rh_pwa_worker_id_uq` (`worker_id`, `id`),
  UNIQUE KEY `op_rh_pwa_worker_supersedes_uq` (`worker_id`, `supersedes_id`),
  CONSTRAINT `op_rh_pwa_worker_fk` FOREIGN KEY (`worker_id`)
    REFERENCES `op_rh_personal` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `op_rh_pwa_workplace_fk` FOREIGN KEY (`workplace_id`)
    REFERENCES `op_rh_localidades` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `op_rh_pwa_supersedes_fk` FOREIGN KEY (`worker_id`, `supersedes_id`)
    REFERENCES `op_rh_personal_localidad_version` (`worker_id`, `id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
