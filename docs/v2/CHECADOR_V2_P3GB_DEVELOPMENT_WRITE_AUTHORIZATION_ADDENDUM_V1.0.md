# Checador V2 — P3G-B Development Write Authorization Addendum V1.0

**Status:** Authorized for local development implementation and QA persistence under this task  
**Approved by:** User, explicit instruction `CHECADOR_V2_M11_M06_CONFIGURATION_IMPLEMENTATION_REMEDIATION_V2` (confirming prior P3G-B approval)  
**Date:** 2026-11-03  
**Applies to:** Portal3 and API V2, local Fedora development database `bd_admongas` only  
**Does not revise:** Architecture Freeze V1.0, frozen P3G base contracts, Lector, P3G-C/D lifecycle, Journey/J2, or payroll

## 1. Authorized P3G-B operations

This is a narrowly scoped development exception for the already-defined P3G-B create/revision boundary. It authorizes local use of:

- `POST /api/v2/incidences` to create the canonical V2 incidence master and initial revision;
- `POST /api/v2/incidences/{uuid_incidencia}/revisions` to append a revision under the existing optimistic-concurrency and idempotency contract;
- API readback of the created incidence and full revision history.

These operations may write only the existing canonical P3G tables identified by the frozen API contract. No schema change is authorized by this P3G-B addendum. Existing JWT, dedicated ACL, canonical worker/locality scope, input validation, idempotency, append-only history, and readback remain mandatory. No new ACL grant is authorized here.

The separately authorized M06 deadline addendum permits one additional append-only association table solely for a `PUNCH_ATTENDANCE` incidence's declared check reference and the exact attendance-policy version. It does not alter any existing P3G table or grant a new permission.

## 2. Development gate

Portal3 must require the specific opt-in `CHECADOR_P3G_B_POSTS_ENABLED` and `APP_ENV` in `dev`, `development`, or `local` before sending either authorized P3G-B POST. This opt-in is independent of `CHECADOR_API_REAL_POSTS_ENABLED` and must not enable Journey, snapshots, temporary access, lifecycle transitions, administrative evaluation, or any other POST.

The API still validates JWT, the existing P3G-B function permission, and current canonical locality scope on every request. The Portal3 gate does not replace API authorization. No production setting or production write is authorized.

## 3. QA persistence evidence

A single QA persistence case may be created only in the verified local development database, using a unique idempotency key, a reason that begins `[QA DEV]`, and an `evidence_reference` beginning `QA-M06-`. It must be read back through the canonical API and Portal3. The stored record remains a pending QA incidence; it must never be described as an authorized labor finding or official Journey evaluation. Existing QA and canonical records must not be changed or deleted.

## 4. Explicit exclusions and deadline gate

This addendum does not authorize P3G-C/D lifecycle transitions, final authorization/rejection/revocation, administrative evaluation, `FALTA` generation, Journey evaluation, J2 runtime, payroll effects, raw PunchEvent writes, or legacy incidence dual-write.

The configured `incidence_deadline_days` is enforced only for the M06 `PUNCH_ATTENDANCE` cases defined by `CHECADOR_V2_M06_PUNCH_INCIDENCE_DEADLINE_ADDENDUM_V1.0.md`. It does not apply to vacation, disability, leave/permission, or official mission cases. That policy link and its scoped additive association table do not authorize P3G lifecycle or evaluation operations.

## 5. Verification

Focused P3G-B tests and one authorized local QA write/readback are permitted after the dedicated gate and API ACL are verified. No broad quality gate that can invoke unrelated writes may be used. All results must distinguish isolated tests from actual MariaDB persistence and authenticated browser evidence.
