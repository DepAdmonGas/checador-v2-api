-- Deliberately preserve the append-only policy history. Revoke the M11 grants
-- with 2026_11_03_000002_rollback_attendance_policy_grants.sql; dropping this
-- table would destroy policy versions and audit provenance.
SELECT 'Attendance policy history retained; no destructive rollback applied.' AS rollback_status;
