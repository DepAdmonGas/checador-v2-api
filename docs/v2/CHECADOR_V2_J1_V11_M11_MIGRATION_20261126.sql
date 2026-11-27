-- Versioned migration for future deployments. DO NOT execute against an already upgraded schema.
-- Owner reports the three columns already exist in Fedora and Plesk PREPROD.
-- REFERENCE ONLY for these environments; this file is not authorization to execute.
-- Before execution inspect information_schema.columns: apply only when all three
-- columns are absent; stop for a partial or incompatible schema and reconcile manually.
-- Existing local bd_admongas already contains these columns. No backfill.
ALTER TABLE op_rh_v2_attendance_policy_versions
  ADD COLUMN early_entry_allowed_minutes SMALLINT UNSIGNED NULL,
  ADD COLUMN duplicate_punch_window_minutes SMALLINT UNSIGNED NULL,
  ADD COLUMN exit_priority_window_minutes SMALLINT UNSIGNED NULL;
