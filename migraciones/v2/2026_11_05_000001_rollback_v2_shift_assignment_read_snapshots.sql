-- Rollback removes only ephemeral M03 read snapshots; it never touches schedule data.
DROP TABLE IF EXISTS op_rh_v2_shift_assignment_read_snapshot_members;
DROP TABLE IF EXISTS op_rh_v2_shift_assignment_read_snapshots;
