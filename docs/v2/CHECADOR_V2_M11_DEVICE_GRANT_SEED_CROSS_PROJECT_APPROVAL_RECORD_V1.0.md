# Checador V2 — M11 Device Grant Seed Cross-Project Approval Record V1.0

**Approval date:** 2026-11-11  
**Approval status:** `RECORDED — APPROVED FOR DEVELOPMENT, LIMITED SCOPE`  
**Projects:** Checador V2 API and Portal3  
**Approval evidence:** Express user decision `CHECADOR_V2_M11_CROSS_PROJECT_APPROVAL_V1` in the task record. No approver name is inferred.

## Approved source drafts

| Document | Approved source SHA-256 |
|---|---|
| `CHECADOR_V2_M11_DEVICE_GRANT_SEED_EXCEPTION_ADDENDUM_DRAFT_V1.0.md` | `bc3b03aa6fdd222671e44ec1a358b9f03cd18c43d6dbd61adf7ae2401d3487cb` |
| `CHECADOR_V2_M11_DEVICE_GRANT_SEED_TECHNICAL_PROPOSAL_DRAFT_V1.0.md` | `9da751f36a1208d8c1ec9a17983a0b8b546ffdd4a1156aaad07f205206fcbdd4` |

The source drafts remain preserved byte-for-byte. Their approval is represented by the definitive copies below; the DRAFT files themselves retain their original status and hashes.

## Definitive approved copies

| Document | SHA-256 |
|---|---|
| `CHECADOR_V2_M11_DEVICE_GRANT_SEED_EXCEPTION_ADDENDUM_V1.0.md` | `db649b1427195f7c4c3bc9a15bb9072dbdc5c8ad3e6a514c71f5a13f0edfb55a` |
| `CHECADOR_V2_M11_DEVICE_GRANT_SEED_TECHNICAL_PROPOSAL_V1.0.md` | `9885825451d8f0f1173ed839c1cd6d9e7d4d1e6ea1a6f836769e3238c10a03c9` |

The definitive files are required to be byte-identical between the API V2 repository and the isolated Portal3 integration worktree. This record has the same requirement.

## Exact authorization scope

- Permission: `checador-v2-dispositivos.leer`.
- Principals: `POSITION 6` (Encargado) and `POSITION 13` (Dirección).
- Environment: development only, in both projects.
- Existing object only: `op_rh_v2_permission_grants`; no tables, columns, or indexes may be created or changed.
- No pre-existing decisions may be overwritten; no USER decisions may be inserted or modified; direct-user precedence and existing locality scope remain unchanged.
- A seed-specific `source_task` is required and must not be treated by itself as proof of row identity.
- Preparation of seed, rollback, and tests is authorized for review. SQL execution, database writes, gates, production, deployment, commits, push, and merge are not authorized by this record.
- Architecture Freeze V1.0, J1/J2, and the original M11 addendum remain unchanged.

## Prepared review artifacts

| Artifact | SHA-256 | Status |
|---|---|---|
| `proposals/CHECADOR_V2_M11_DEVICE_GRANT_SEED_REVIEW_DRAFT_V1.0.sql` | `78b5e4edb4e2c733d353647580ce64657cc6eb0acb363e0d2ec9e9e4c2635f72` | Review template; not executed |
| `proposals/CHECADOR_V2_M11_DEVICE_GRANT_SEED_ROLLBACK_REVIEW_DRAFT_V1.0.sql` | `3015328dc5f08cdd923651a83057b021e3be12f507ee14b9576df06460b3739a` | Operator-gated template; not executed |
| `CHECADOR_V2_M11_DEVICE_GRANT_SEED_TEST_PLAN_V1.0.md` | `8daf01c80dbcf09c8dbb78ea715a129306b000853eb5674ed4a40a9255d85c63` | Planned only; tests not run |

## Compatibility evidence

- The original M11 device-read addendum remains at SHA-256 `a2f0ad3980032bd992e9aade2aea5f51c9cf141027e7cde64a3d130506f36eb6`.
- The M11 authorization service uses the exact permission key, allowlists positions 6 and 13, checks a direct `USER` decision before falling back to `POSITION`, and delegates locality scope to the existing authorization service.
- The existing grant table migration defines unique key `(permission_key, principal_type, principal_id)` and `source_task VARCHAR(96)`; `source_task` is not unique.
- Architecture Freeze V1.0 remains unchanged at SHA-256 `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`.

**Current execution state:** No SQL was executed, no database was accessed or modified, no gates were activated, and no production operation occurred. No commit, push, merge, or deployment was performed.
