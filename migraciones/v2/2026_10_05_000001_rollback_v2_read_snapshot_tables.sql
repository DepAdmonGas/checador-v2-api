-- Rollback Level 1 Read Snapshot API V2.4
-- Drop tables de snapshots, miembros transitorios y manifiestos durables.

DROP TABLE IF EXISTS `op_rh_v2_read_snapshot_members`;
DROP TABLE IF EXISTS `op_rh_v2_durable_snapshot_manifests`;
DROP TABLE IF EXISTS `op_rh_v2_read_snapshots`;
