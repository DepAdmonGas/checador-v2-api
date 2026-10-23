-- Level 1 snapshot member raw-reference integrity hardening.
-- Preflight validation of range, NULLs, orphans, and duplicate pairs is an
-- operational gate; this migration intentionally performs no data rewrite.

ALTER TABLE `op_rh_v2_read_snapshot_members`
  MODIFY COLUMN `id_raw_punch` INT(11) NOT NULL;

ALTER TABLE `op_rh_v2_read_snapshot_members`
  ADD INDEX `idx_snapshot_member_raw_punch` (`id_raw_punch`);

ALTER TABLE `op_rh_v2_read_snapshot_members`
  ADD CONSTRAINT `fk_snapshot_member_raw_punch`
  FOREIGN KEY (`id_raw_punch`)
  REFERENCES `op_rh_v2_marcaciones_crudas` (`id`)
  ON DELETE RESTRICT;

ALTER TABLE `op_rh_v2_read_snapshot_members`
  ADD UNIQUE KEY `uk_snapshot_member_raw_punch` (`id_snapshot`, `id_raw_punch`);
