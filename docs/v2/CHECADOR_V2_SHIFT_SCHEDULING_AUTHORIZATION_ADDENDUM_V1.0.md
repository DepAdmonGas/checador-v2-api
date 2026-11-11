# Checador V2 — Shift Scheduling Authorization Addendum V1.0

**Status:** Authorized for local development implementation  
**Approved by:** User, explicit instruction `CHECADOR_V2_M03_STATION_SCHEDULING_AUTHORIZATION_V1`  
**Date:** 2026-11-02  
**Applies to:** Portal3 and API V2 Shift Scheduling endpoints  
**Does not revise:** Architecture Freeze V1.0, Lector contracts, Block B, or J2

## 1. Purpose

This addendum separates station scheduling from the broad `biometricos.leer` and `biometricos.editar` capabilities. Existing Biométricos clients and grants retain their current semantics. A schedule request must not gain another Biométricos operation as a side effect of receiving a scheduling capability.

## 2. Capabilities

| Permission key | Effect |
| --- | --- |
| `checador.programacion.leer` | Read the authorized station's schedule, effective schedule, history, timezone needed by the schedule workspace, and the global template catalog for selection. |
| `checador.programacion.editar` | Create, close, or atomically replace assignments for verified workers within the caller's station scope. |
| `checador.plantillas.administrar` | Create/version the global template catalog. This permission does not grant assignment or Biométricos access. |

An active direct-user rule takes precedence over a position rule, including an explicit deny or revocation. Otherwise the active position grant applies. Missing, invalid, or unavailable ACL data denies access. Grants are managed in `op_rh_v2_permission_grants` and can be revoked by setting `active=0` or denied by setting `granted=0`; revocation does not delete historical assignment data.

## 3. Approved development grants

- Position 6, Encargado: `programacion.leer` and `programacion.editar`.
- Position 13, Dirección de Operaciones: `programacion.leer` only.
- No position or user is granted `plantillas.administrar` until a specific RH/administrator identity or role is established through the existing ACL process.

These grants are not user-ID grants. The API resolves the active identity and position from the validated Portal3 token on every request.

## 4. Locality and worker scope

The API resolves scheduling scope from the current `biometricos` station allowlist and the existing physical-station-to-canonical-locality resolver:

- Position 6 is limited to its active home physical station (`tb_usuarios.id_gas`) when that station is in the configured allowlist.
- Position 13 is limited to the active physical stations in that allowlist.
- Department-only locality exceptions do not become schedule stations.
- A requested locality, assignment row, and worker must remain inside the resolved scope.
- Assignment creation/replacement requires a unique, current, canonical membership interval covering the complete effective period. Missing, ambiguous, malformed, overlapping, or unverified history fails closed. Current `id_estacion` is not a historical fallback.

Portal3 repeats capability and locality checks before invoking the API. The API remains the final authorization boundary.

## 5. Endpoint policy

- Template list/version GET: `programacion.leer` or `plantillas.administrar`.
- Assignment list, effective schedule, and schedule timezone GET: `programacion.leer` plus station scope.
- Assignment create, close, and replace POST: `programacion.editar` plus station and worker-membership validation.
- Template create/version POST: `plantillas.administrar` only.
- Scheduled-shift materialization remains under the existing `biometricos.editar` authorization and is not granted to Encargado by this addendum.

The existing API paths are reused except for an atomic assignment-replacement operation added at `POST /api/v2/shift-assignments/{id}/replace`. The replacement locks the worker and source assignment, validates the expected source state, closes it, validates the replacement interval and template, and creates the replacement in one transaction. Exact retries return the existing matching result and do not create duplicates.

## 6. Explicit rest days and physical schema reconciliation

Shift Scheduling Service API Contract V1.0 represents an explicit day off with `id_plantilla = NULL`. The deployed physical table was created with `id_plantilla NOT NULL`, which contradicts that existing contract. The development migration for this task makes the column nullable to realize the already-defined API representation. No contract or Architecture Freeze text is changed. API validation continues to accept `NULL` only as an explicit day-off assignment.

## 7. Exclusions and compatibility; development-only CLI bootstrap exception

For the specifically authorized task `CHECADOR_V2_M03_AUTHORIZED_TEMPLATE_BOOTSTRAP_EXECUTION_V1`, one narrowly scoped initial catalog bootstrap and its idempotent verification reruns are authorized through a maintenance command invoked directly by CLI in the local development environment only. The command must require `APP_ENV=dev`, the exact database `bd_admongas`, and a verified local MariaDB server identity before it can apply changes. It may derive version-1 templates and their work segments only from the approved legacy Interlomas schedule definitions, retaining verifiable source provenance, deterministic keys, a preview-first explicit `--apply`, transaction handling, a concurrency lock, exact readback, and fail-without-overwrite behavior for conflicts.

This CLI exception does not require or impersonate a JWT identity and is not reachable through HTTP. It does not change `POST /api/v2/shift-templates`, its JWT/permission authorization, or any existing user or position grant. It does not grant Encargado, Dirección de Operaciones, or another role ordinary/global template administration. Subsequent template creation or versioning remains subject to the normal `checador.plantillas.administrar` authorization.

The bootstrap may not create worker assignments, infer workdays or rest days, create retroactive memberships, materialize scheduled shifts, modify legacy rows, or alter PunchEvents. It does not authorize production ACL changes, Journey evaluation, API Block B pagination, J2, M07, Lector changes, or changes to existing `biometricos.*` grants. All other exclusions and compatibility requirements in this addendum remain in force.
