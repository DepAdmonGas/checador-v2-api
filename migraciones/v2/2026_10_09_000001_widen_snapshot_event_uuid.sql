-- Checador V2 Event UUID Domain Addendum V1.0
-- Widen only the Level 1 snapshot member event_uuid representation.
-- Preserves the raw utf8mb4_unicode_ci identity semantics.

ALTER TABLE `op_rh_v2_read_snapshot_members`
  MODIFY COLUMN `event_uuid` VARCHAR(64)
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci
    NOT NULL;

