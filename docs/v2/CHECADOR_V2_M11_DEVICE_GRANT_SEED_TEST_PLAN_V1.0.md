# Checador V2 — M11 Device Grant Seed Test Plan V1.0

**Status:** `PLANNED — NOT EXECUTED`  
**Scope:** Development-only, isolated disposable database after separate execution approval.  
**Seed task:** `CHECADOR_V2_M11_DEVICE_ADMIN_READ_GRANT_SEED_V1`

## Preconditions

- Use only an explicitly authorized disposable development/test database; verify target identity before any write.
- Verify the existing `op_rh_v2_permission_grants` schema and unique key `(permission_key, principal_type, principal_id)`; create no schema objects.
- Start from a captured before-state for the exact M11 permission and POSITION IDs 6 and 13, plus USER decisions used in authorization tests.
- Do not run tests against `bd_admongas`, production, or an unverified environment.

## Planned cases

1. **First application:** only missing POSITION 6/13 rows for `checador-v2-dispositivos.leer` are inserted; capture IDs and every inserted column value.
2. **Idempotent reapplication:** no duplicate rows and no changes to decisions or timestamps.
3. **Existing allow:** preserve and report an existing active grant without rewriting provenance or timestamps.
4. **Existing deny/revocation:** preserve and report denied or inactive decisions; do not reactivate or overwrite them.
5. **Exact scope:** no grants for another permission, principal type, position, or locality; no USER insert/update.
6. **Authorization precedence:** existing direct USER allow and deny decisions remain unchanged and override POSITION results, including inactive/revoked USER rows.
7. **Locality scope:** M11 continues to use the existing API authorization service and scope resolver; the seed does not widen scope. Test Encargado's authorized station and Dirección's authorized localities in the isolated environment.
8. **Concurrent unique-key conflict:** a competing insert produces a visible error; the seed transaction is rolled back and the competing row is preserved.
9. **Provenance:** distinguish pre-existing versus newly inserted rows using captured before/after state and affected-row result; verify `source_task` is seed-specific but not treated as sole row identity.
10. **Rollback exact match:** only recorded seed-created row IDs whose complete original values still match are soft-revoked; verify exact readback.
11. **Rollback mismatch:** change one recorded row in a disposable fixture, then verify the operator gate aborts the entire rollback without partial mutation.
12. **Environment guard:** reject an unverified or non-development target before writes.

## Evidence and status

Capture test environment identity, schema version, before/after query results, affected-row counts, row IDs, and test outcomes without secrets. These cases are a plan only; no test, SQL, migration, or database access was performed while preparing it.
