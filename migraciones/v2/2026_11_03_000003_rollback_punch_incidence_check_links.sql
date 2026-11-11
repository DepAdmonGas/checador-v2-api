-- Preserve append-only incident/check links and policy provenance. Disable
-- the scoped M06 writer gate for rollback; do not drop this table or its rows.
SELECT 'Punch incidence/check audit links retained; no destructive rollback applied.' AS rollback_status;
