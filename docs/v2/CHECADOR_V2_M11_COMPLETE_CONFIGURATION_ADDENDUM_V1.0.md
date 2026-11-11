# Checador V2 — M11 complete configuration addendum V1.0

**Status:** Authorized by the user in task `CHECADOR_V2_M11_COMPLETE_CONFIGURATION_V1` for local Fedora development only  
**Environment:** Portal3 `portal3.test`, API V2 loopback, MariaDB `bd_admongas`  
**Does not revise:** Architecture Freeze V1.0, frozen API contracts, P3G base contracts, Lector contracts, or M05/Journey rules

## 1. Authorized scope

This addendum records the narrowly scoped M11 capabilities authorized by the task:

| Capability | Encargado (position 6) | Dirección de Operaciones (position 13) | Scope |
|---|---|---|---|
| Attendance tolerance and punch-incidence deadline | Read and edit | Read and edit | Active, canonical station localities authorized for the user; prospective versioning only |
| Reader application profile | Read and edit/rotate | Read only | One selected, authorized station; no password retrieval or response containing a password/token |
| Institutional position catalog (`op_rh_puestos`) | No access | Read, create and edit | Shared institutional catalog; no deletion/deactivation in this scope |
| V2 shift-template catalog | Read/selection through M04 scheduling capability | Read/supervision through existing capability | Global catalog remains governed by `checador.plantillas.administrar`; this addendum does not grant it |

Gerente remains unmapped and receives no grant. No capability in this addendum implies `biometricos.editar`, another Biométricos permission, or a general module privilege.

## 2. Reader profile handling

The existing reader-profile store is `op_rh_localidades_perfil`. Portal3 resolves canonical locality to exactly one active physical station using the institutional `numlista` mapping, then constrains every list or mutation to that physical station and an active profile ID. Numeric equality between locality and physical station IDs is never assumed.

The authorized projection may return the reader username and profile identifier only. Passwords, stored tokens, ciphertext, and password-presence comparisons are excluded from HTML, JSON, logs, session feedback, and audit records. A blank rotation field means no password change; a requested rotation is write-only. The existing reader storage encoding is retained for compatibility, so this addendum does not authorize a cryptographic migration or changing the Lector/API authentication protocol. That legacy storage risk remains separately tracked.

The append-only M11 configuration audit records actor, specific permission, locality where applicable, entity type/id, action and UTC timestamp. It must never contain credential values or submitted payloads.

## 3. Policy and catalog writes

Attendance-policy writes continue through the single existing API V2 writer and its separate M11 policy gate. Values remain integers in the approved ranges (0–120 minutes and 0–30 natural days), and versions remain prospective and append-only. Existing policy versions and legacy reference values are preserved.

Position CRUD operates on the existing `op_rh_puestos` catalog through its existing Portal3 service/persistence path, with M11-specific authorization and audit. The implementation may prevent duplicate names but must not create a second positions catalog or delete existing positions.

Shift-template listing and selection reuse the M04/API readers. Creation/versioning remains exclusively behind the existing `checador.plantillas.administrar` permission and M04-specific write gate. No position or user is granted template administration by this addendum.

## 4. Local gates and data boundary

Every write remains limited to the verified local development environment and `bd_admongas`. Only the M11-specific Portal3 gate may be enabled for M11 functions. The global API POST gate and P3G-B gate remain disabled. API authorization, session authentication, CSRF, fresh user-position lookup, and locality checks remain mandatory.

The API permission table receives only the dedicated M11 grants listed above. An existing direct-user grant or revocation continues to take precedence over a position grant. No production configuration or data is in scope.

## 5. Explicit exclusions

This addendum does not authorize:

- Password readback, password export, or secret logging;
- Changes to legacy cipher format, Lector protocols, API authentication, or device credentials;
- Template catalog administration for Encargado or Dirección without the existing template-admin grant;
- Position deletion/deactivation;
- Legacy or canonical incidence creation, Journey evaluation, snapshots, punch writes, payroll effects, ACL broadening, or M07 administrative operations;
- Changes to any frozen base contract or Architecture Freeze.
