# Checador V2 — M11 Attendance Policy Configuration Addendum V1.0

**Status:** Authorized for local development implementation under this task  
**Approved by:** User, explicit instruction `CHECADOR_V2_M11_M06_CONFIGURATION_IMPLEMENTATION_REMEDIATION_V2`  
**Date:** 2026-11-03  
**Applies to:** Portal3 and API V2, local Fedora development database `bd_admongas` only  
**Does not revise:** Architecture Freeze V1.0, Lector contracts, Journey/P2 classification rules, or the frozen P3G base contracts

## 1. Narrow authorization

This addendum records the user's explicit authorization for the M11 policy-configuration slice. It is a scoped development exception and does not change the status or contents of any frozen base contract.

Authorized operations are limited to:

- Read a locality's legacy reference values and its V2 policy versions.
- Create a future-dated V2 policy version containing `retardo_tolerance_minutes` and `incidence_deadline_days`.
- Read the persisted version back through the API and Portal3.
- Preserve the legacy configuration route as a compatibility entry point to the same policy reader/writer.

Only the verified local development environment and `bd_admongas` are in scope. No production, Lector, payroll, raw-punch, Journey-evaluation, or unrelated-module operation is authorized.

## 2. Canonical storage and version semantics

The V2 policy store is `op_rh_v2_attendance_policy_versions`, created additively in development. Its authorized logical fields are:

- `id` — generated row identifier;
- `workplace_id` — canonical `op_rh_localidades.id`;
- `policy_version` — monotonically increasing version per locality;
- `effective_from_local_date` — inclusive local civil date from which this version applies;
- `timezone_id` — timezone resolved for that locality at the effective date;
- `retardo_tolerance_minutes` and `incidence_deadline_days` — integer settings constrained to 0–120 minutes and 0–30 natural days respectively;
- `actor_portal_user_id` — authenticated Portal3 actor for administrator-created versions;
- `source` — `PORTAL3_ADMIN` for new versions;
- `idempotency_key` — request replay key;
- `created_at_utc` — server-recorded creation time.

The implementation must enforce uniqueness for `(workplace_id, policy_version)`, `(workplace_id, effective_from_local_date)`, and `(workplace_id, idempotency_key)`. New versions must be prospective: `effective_from_local_date` must be later than the locality's current civil date and later than any previously scheduled version. Historical dates without a V2 version remain explicitly `LEGACY_UNVERSIONED`; the implementation must not invent a historic effective date.

The existing `op_rh_localidades_retardo_incidencia` rows are read-only initial references. This addendum does not authorize changing or deleting them, bulk-importing them as historically effective V2 versions, or treating them as versioned policy. Portal3 must label legacy values as references until an authorized V2 version applies.

All policy writes go through the one API policy writer. Portal3's current configuration page and its legacy compatibility route call that same writer; they must not write the legacy table directly.

The legacy values remain the initial read-only reference. A saved V2 version must preserve them when they fall inside the authorized limits; no replacement defaults are introduced. The limits are both client- and server-enforced.

## 3. API boundary and gates

The authorized API operations are:

- `GET /api/v2/attendance-policies?workplace_id={id}&effective_date=YYYY-MM-DD` — read the policy effective on the requested local date, or return an explicit legacy/unavailable state.
- `POST /api/v2/attendance-policies` — create one prospective policy version. The actor comes only from the validated Portal3 JWT. Require `Idempotency-Key`, strict input validation, fresh ACL/scope checks, transactional uniqueness checks, and exact readback.

The Portal3 caller must also require the M11-specific development opt-in `CHECADOR_ATTENDANCE_POLICY_POSTS_ENABLED`. This flag is separate from `CHECADOR_API_REAL_POSTS_ENABLED`; it must not enable any other POST path. API authorization remains mandatory even when the flag is on.

## 4. Capabilities and locality scope

Use only these dedicated capabilities:

- `checador.configuracion.leer`;
- `checador.configuracion.retardo_incidencias.editar`.

The development grant authorized now is for position 13, Dirección de Operaciones: read and edit, limited to the active canonical localities derived by the API's existing authorized-station resolver. No `biometricos.editar`, reader/device-administration, schedule-edit, or global scope is granted.

Gerente is not mapped to an account position by this addendum. Do not equate Gerente with Encargado position 6 and do not grant a position or user until the institutional identity is verified through the existing ACL process. Read-only Gerente access remains pending that mapping.

## 5. Explicit non-authorizations

This addendum stores and serves policy values; it does not authorize their operational enforcement:

- P2/Journey must not emit an official `RETARDO` from the tolerance value. Runtime J2 and official late classification remain under their existing contract gates.
- API must apply `incidence_deadline_days` only to the `PUNCH_ATTENDANCE` check cases defined by `CHECADOR_V2_M06_PUNCH_INCIDENCE_DEADLINE_ADDENDUM_V1.0.md`. Other leave/permission categories remain separate. The policy value does not enable official Journey `RETARDO` calculation.
- This addendum does not authorize lifecycle transitions, authorization/rejection/revocation, administrative evaluation, payroll effects, legacy incidence writes, punch writes, or a global POST gate.

## 6. Validation boundary

Tests may use isolated or verified local-development data only. A real browser write requires a legitimate position-13 session, the dedicated API ACL grant, the M11-specific gate, and readback of the exact version. No mock is evidence of database persistence. The human remains responsible for visual approval.
