-- Additive development-authorized storage for immutable M03 assignment read snapshots.
-- Contains no assignment, membership, Journey, or PunchEvent source data mutations.
CREATE TABLE IF NOT EXISTS op_rh_v2_shift_assignment_read_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id INT UNSIGNED NOT NULL,
    scope_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    filter_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    canonical_filters_json LONGTEXT NOT NULL,
    membership_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    per_page SMALLINT UNSIGNED NOT NULL,
    total_records INT UNSIGNED NOT NULL,
    total_pages SMALLINT UNSIGNED NOT NULL,
    payload_bytes BIGINT UNSIGNED NOT NULL,
    last_page_read SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at_utc DATETIME(6) NOT NULL,
    expires_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shift_read_snapshot_token (token_sha256),
    KEY ix_shift_read_snapshot_actor_expiry (actor_user_id, expires_at_utc),
    KEY ix_shift_read_snapshot_expiry (expires_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS op_rh_v2_shift_assignment_read_snapshot_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    snapshot_id BIGINT UNSIGNED NOT NULL,
    member_order INT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NOT NULL,
    payload_json LONGTEXT NOT NULL,
    payload_bytes INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shift_read_snapshot_order (snapshot_id, member_order),
    UNIQUE KEY uq_shift_read_snapshot_assignment (snapshot_id, assignment_id),
    KEY ix_shift_read_snapshot_member_purge (snapshot_id, id),
    CONSTRAINT fk_shift_read_snapshot_members_header
        FOREIGN KEY (snapshot_id) REFERENCES op_rh_v2_shift_assignment_read_snapshots (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
