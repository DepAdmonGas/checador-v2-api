# Checador V2 — M11 Device Grant Seed Technical Proposal V1.0

**Status:** `APPROVED FOR DEVELOPMENT PREPARATION — LIMITED SCOPE`  
**Related exception:** `CHECADOR_V2_M11_DEVICE_GRANT_SEED_EXCEPTION_ADDENDUM_V1.0.md`  
**Permission:** `checador-v2-dispositivos.leer`  
**Principals:** `POSITION 6`, `POSITION 13`

**Approved source draft SHA-256:** `9da751f36a1208d8c1ec9a17983a0b8b546ffdd4a1156aaad07f205206fcbdd4`  
**Cross-project approval:** `RECORDED — API V2 / Portal3 — 2026-11-11`  
**Approval record:** `CHECADOR_V2_M11_DEVICE_GRANT_SEED_CROSS_PROJECT_APPROVAL_RECORD_V1.0.md`

This approved design authorizes preparation of review artifacts for development. The accompanying SQL proposal files remain unexecuted; no database operation is authorized here.

## 1. Existing dependency

The proposed seed depends on the existing `op_rh_v2_permission_grants` table established by the already versioned Shift Scheduling base migration. The expected unique key is `uk_v2_permission_principal(permission_key, principal_type, principal_id)`. The schema contains `source_task VARCHAR(96)`, but `source_task` itself is not unique.

Before implementation, a read-only preflight must confirm the database is an approved development target and the table and unique key match this contract. The seed must not create or alter schema. The previous development grants recorded in the ledger have unknown `source_task` provenance and must be treated as pre-existing, never as rows created by this proposed seed.

## 2. Proposed seed behavior

The future versioned artifact would be limited to these two keys:

| `permission_key` | `principal_type` | `principal_id` |
|---|---|---:|
| `checador-v2-dispositivos.leer` | `POSITION` | 6 |
| `checador-v2-dispositivos.leer` | `POSITION` | 13 |

For each key, the implementation would:

1. Verify development identity and expected table structure; otherwise stop before writing.
2. Read the current decision. If any row exists for the key—granted, denied, active, or revoked—preserve it and report it as pre-existing. Do not update `granted`, `active`, `reason`, `source_task`, or timestamps.
3. For a missing key only, perform a conditional insert with a seed-specific `source_task`, for example `CHECADOR_V2_M11_DEVICE_ADMIN_READ_GRANT_SEED_V1`. This value must not be reused by the base Shift Scheduling migration or another seed.
4. Use a transaction for the two-key operation. The unique key arbitrates a concurrent insert; a duplicate-key conflict aborts the operation and is reported. Do not use `INSERT IGNORE`, `REPLACE`, or an upsert that changes an existing row.
5. Read back both keys and record for each whether it was newly created or pre-existing. No `USER` row is inserted or changed. The existing API's direct-user decision precedence and canonical locality scope remain in force.

If one key is pre-existing and the other is absent, only the absent key may be inserted. If a pre-existing denial blocks the intended entitlement, the seed reports the conflict for separate ACL-owner resolution; it does not override the denial.

## 3. Provenance and rollback design

`source_task` identifies the seed but is not sufficient by itself to authorize rollback. At successful application, the approved execution record must capture the identity and original values of each row actually inserted: row ID, permission key, principal type and ID, `granted`, `active`, reason, `source_task`, and creation/update timestamps. Pre-existing rows are excluded from that record.

The future rollback would be a reviewed, transactional soft revocation of only those recorded IDs, and only while every recorded key and original value still matches. It must verify the affected-row count and read back the result. If any row is missing, modified, revoked, or differs from its recorded original values, the rollback stops without changing the mismatched row; the operation requires manual review. It must never target a row solely because its `source_task` matches, and must never target prior M11 grants with undocumented provenance.

## 4. Planned isolated validation

After cross-project approval and artifact review, tests should run only in a separately authorized disposable development/test database:

1. First application inserts only the missing position rows and records their identities.
2. Reapplication creates no duplicates and leaves row values and timestamps unchanged.
3. Existing allowed, denied, and inactive position decisions are preserved and reported.
4. Direct `USER` grant/denial rows are unchanged and retain precedence in authorization.
5. Positions other than 6 and 13 receive no grant.
6. Read-back confirms only the exact permission/principals and no locality expansion.
7. A concurrent duplicate-key conflict aborts visibly without overwriting the winner.
8. Rollback affects only recorded, unchanged seed-created rows; pre-existing and modified rows are untouched.
9. Non-development identity is rejected before any write.

These tests are planned only; none ran for this draft. No SQL was executed and no database was accessed.

## 5. Prepared review artifacts and gate

The prepared, non-migration review files are:

- `docs/v2/proposals/CHECADOR_V2_M11_DEVICE_GRANT_SEED_REVIEW_DRAFT_V1.0.sql`
- `docs/v2/proposals/CHECADOR_V2_M11_DEVICE_GRANT_SEED_ROLLBACK_REVIEW_DRAFT_V1.0.sql`
- `docs/v2/CHECADOR_V2_M11_DEVICE_GRANT_SEED_TEST_PLAN_V1.0.md`

The SQL files are review templates outside `migraciones/v2`; they have not been executed. The rollback template requires an operator-controlled identity/value comparison and must not target rows by `source_task` alone. Focused test cases are planned but have not been run. This proposal does not change the M11 base addendum, Architecture Freeze V1.0, J1/J2, the endpoint, schema, or authorization behavior. No commit, push, deployment, gate activation, or production operation is authorized by this preparation approval.
