-- Approved additive lifecycle marker for the dedicated ephemeral M03 snapshot header.
ALTER TABLE op_rh_v2_shift_assignment_read_snapshots
    ADD COLUMN state VARCHAR(16) NOT NULL DEFAULT 'READY' AFTER membership_fingerprint;
