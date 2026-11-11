# Checador V2 — M11 Device Read Grant Seed Exception Addendum V1.0

**Status:** `APPROVED FOR DEVELOPMENT — LIMITED SCOPE`  
**Cross-project approval:** `RECORDED — API V2 / Portal3 — 2026-11-11`  
**Applies to:** Portal3 and Checador V2 API  
**Companion to:** `CHECADOR_V2_M11_DEVICE_ADMIN_READ_AUTHORIZATION_ADDENDUM_V1.0.md`  
**Current companion SHA-256:** `a2f0ad3980032bd992e9aade2aea5f51c9cf141027e7cde64a3d130506f36eb6`

**Approved source draft SHA-256:** `bc3b03aa6fdd222671e44ec1a358b9f03cd18c43d6dbd61adf7ae2401d3487cb`  
**Approval record:** `CHECADOR_V2_M11_DEVICE_GRANT_SEED_CROSS_PROJECT_APPROVAL_RECORD_V1.0.md`

## 1. Purpose and limited exception

This draft proposes a narrowly scoped exception to §9 of the companion M11 addendum. It would authorize only a versioned, data-only seed and its companion rollback for the already authorized development grants listed below. It would not amend or replace the M11 read contract, the Architecture Freeze, J1, or J2.

The exception is limited to the existing table `op_rh_v2_permission_grants`. It does not authorize schema changes, new tables, columns, indexes, runtime authorization paths, or other migration contents.

## 2. Exact grant scope

| Field | Authorized value |
|---|---|
| Permission | `checador-v2-dispositivos.leer` |
| Principal type | `POSITION` only |
| Principal IDs | `6` — Encargado; `13` — Dirección |
| Environment | Explicitly verified development environment only |

No other permission, principal type, position, locality, device operation, or production environment is included. Locality scope continues to be derived and enforced by the existing API authorization path; the seed does not encode or expand locality scope.

## 3. Provisioning invariants

If approved, the versioned seed must:

1. Use the existing table and verify its expected unique key `(permission_key, principal_type, principal_id)` before any insert.
2. Insert only a missing position decision. Any existing grant, denial, or revocation for a requested key is preserved and reported; it is not repaired or reactivated by the seed.
3. Never insert or modify `USER` decisions. Existing direct-user decisions retain precedence over position grants.
4. Use a new, seed-specific `source_task` that is distinct from `CHECADOR_V2_M03_STATION_SCHEDULING_AUTHORIZATION_V1`.
5. Use conditional insertion and explicit conflict handling. A uniqueness race must stop and be reported; it must not be silently ignored.
6. Read back the decisions after the operation and record which rows were newly created versus pre-existing.

## 4. Rollback boundary

The rollback may act only on rows proven to have been created by this seed. Its execution record must identify each inserted row and preserve the original key and values needed for comparison. A row whose identity or original values no longer match is left untouched; any mismatch stops automatic rollback for the operation. Grants that predate this seed, including previously applied development rows with unknown provenance, are never rollback targets.

## 5. Environment and production boundary

The seed and rollback are limited to an explicitly verified development target. Production execution, deployment, gate activation, device writes, schema changes, and permission expansion are prohibited. The M11 endpoint's existing position checks, direct-user precedence, and locality scope remain authoritative.

## 6. Approval and implementation boundary

The user expressly approved the exact source draft SHA-256 listed above for the limited development scope in both API V2 and Portal3 on 2026-11-11. The approved copy is preserved byte-identically in both projects; its SHA-256 and the approval scope are recorded in the companion approval record.

This approval authorizes preparation and review of the versioned seed, rollback mechanism, and focused tests for development. It does not authorize executing SQL, modifying any database, activating gates, publishing commits, deploying, or performing production operations. Those actions require separate authorization.

**Current status:** Cross-project approval recorded for development preparation only; SQL execution and publication are not authorized by this document.
