# Checador V2 — M11 Administrative Device Read Addendum V1.0

| Governance field | Value |
|---|---|
| Status | `CROSS_PROJECT_APPROVED_FOR_DEVELOPMENT_IMPLEMENTATION` |
| Functional scope | Read-only development implementation approved 2026-11-03 |
| Implementation authorized | `YES — DEVELOPMENT ONLY` |
| Database/schema changes / device writes / production | `NO`; dedicated read ACL grants in development are authorized |
| Applies to | Portal3 and Checador V2 API |
| Does not revise | Architecture Freeze V1.0, J1, J2, P3-G base contracts, Level 1/Level 2 contracts, or Lector contracts |

## 1. Purpose and authority

This approved addendum defines the smallest administrative read contract needed to present the existing V2 device registry in Portal3 M11. On 2026-11-03, the responsible authorized project owner expressly approved this exact scope for both Portal3 and API V2, including the development-only endpoint, Portal3 backend caller, and dedicated read ACL grant. This approval does not authorize device writes, schema changes, or production access.

This addendum is additive and does not alter any frozen document. The Architecture Freeze remains `Implementation Authorized: NO`. J1 and J2 retain their current scope and status. P3-G remains limited to its approved administrative-incidence boundary; its transport pattern is a precedent, not authority to extend its endpoint scope.

The cross-project owner approval and development implementation authorization are recorded in the governance record for this addendum. The approval applies only to the named read-only endpoint, delegated backend integration, and dedicated development read grant; all exclusions in Section 9 remain in force.

## 2. Trust boundary and request authorization

The only authorized path is:

```text
Browser → authenticated Portal3 session → Portal3 backend
        → API V2 using a delegated Portal3 user JWT → API-owned read model
```

- Browser code must never call the API directly or receive an API URL, signing key, JWT, device credential, or secret.
- API V2 independently validates JWT signature, issuer/type/expiry, active Portal3 user, the dedicated permission, and current station scope on every request.
- Device Auth (`X-Device-Id`, `X-Credential-Key`, device Bearer secret) is prohibited for this administrative read.
- Portal3 validates its active session, selected workplace, and local permission before making the server-to-server call. API authorization remains authoritative and is not replaced by Portal3 checks.

Authorized least-privilege capability:

```text
checador-v2-dispositivos.leer
```

This capability grants read only. It grants no register, assign, activate, deactivate, credential, maintenance, telemetry, group-edit, or other write action. The approved development entitlement is Encargado (position 6) for its own authorized station and Dirección de Operaciones (position 13) for only its currently authorized workplaces. The responsible project owner separately authorized applying these two development-only grants on 2026-11-03; no grant is authorized outside development.

The endpoint accepts an optional canonical `workplace_id` solely as a filter:

- Omitted: return only devices whose verified current workplace is in the API-derived authorized scope.
- Supplied and authorized: return only that workplace.
- Supplied but outside scope: HTTP 403 `STATION_SCOPE_DENIED`; do not reveal whether devices exist there.
- Empty or unresolved caller scope: HTTP 403; never interpret an empty scope as global access.

For Encargado, scope is restricted to the one station authorized to that user. For Dirección, scope is the set of active canonical localities granted by the existing institutional ACL. No position receives global access by name alone.

## 3. Authorized endpoint and bounded consistency

```http
GET /api/v2/administrative-devices[?workplace_id={canonical_locality_id}]
Authorization: Bearer <delegated Portal3 user JWT>
```

This route is authorized for development implementation. Until deployed in development, it is not available at runtime. It is separate from device-authenticated `/api/v2/devices/context`, Level 2 window evaluation, and P3-G incidence operations.

Version 1 returns one bounded response from a single consistent, read-only database snapshot, ordered by internal device ID. The proposed hard limit is **500 device records**. The API must not silently truncate: if the authorized result exceeds 500, return HTTP 413 `INVENTORY_LIMIT_EXCEEDED`, no device rows, and `completeness: PARTIAL`. A later paginated contract must define a stable snapshot/cursor before it is implemented; ordinary `LIMIT/OFFSET`, a short page, or an independent count is not proof of completeness.

Every successful response includes `read_at_utc` and one of:

- `COMPLETE`: all devices in the requested, authorized scope were read and every required identity/scope relationship was resolved.
- `PARTIAL`: safely scoped rows are available, but one or more authorized-scope rows/relationships could not be resolved. Include only non-sensitive reason codes and do not leak IDs or counts from out-of-scope or unresolved devices.
- `UNKNOWN`: the API could not establish a trustworthy read result. This is not an empty inventory.

The API may return HTTP 200 with `PARTIAL` when it can safely return a subset. A database/API read failure returns an error, never HTTP 200 with `items: []` as if no devices existed.

## 4. Response contract

Approved response schema for implementation:

```json
{
  "status": "OK",
  "completeness": "COMPLETE",
  "read_at_utc": "2026-11-03T18:00:00Z",
  "items": [
    {
      "device": {
        "id": 123,
        "uuid": "opaque-device-uuid",
        "name": "Lector de estación",
        "persisted_status": "ACTIVE"
      },
      "physical_station": {
        "id": 7,
        "name": "Interlomas",
        "numlista": 2
      },
      "workplace": {
        "id": 1,
        "name": "Interlomas",
        "mapping_status": "VERIFIED"
      },
      "assignment": {
        "status": "ASSIGNED",
        "valid_from_utc": "2026-10-01T00:00:00Z",
        "valid_to_utc": null,
        "source": "DEVICE_STATION_HISTORY"
      },
      "group": {
        "id": null,
        "name": null,
        "mapping_status": "UNAVAILABLE"
      },
      "last_communication": {
        "last_server_received_at_utc": "2026-11-03T17:58:00Z",
        "source": "ACCEPTED_DEVICE_TELEMETRY"
      }
    }
  ],
  "partial_reasons": []
}
```

### Field semantics

- `device.persisted_status` reflects only the persisted registry state (`ACTIVE` or `INACTIVE`). It is not an online/healthy result.
- `physical_station.id` is `tb_estaciones.id`. `workplace.id` is `op_rh_localidades.id`. They are different identifier domains and must never be equated numerically.
- `workplace` is returned only after the existing unique, valid mapping `tb_estaciones.id → numlista → op_rh_localidades.id` succeeds. Missing, inactive, duplicate, or ambiguous mapping cannot be guessed.
- `assignment` is evaluated at `read_at_utc` from the API-owned assignment history, using half-open UTC intervals `[valid_from_utc, valid_to_utc)`. Exactly one matching row is `ASSIGNED`; overlapping rows are `UNKNOWN`. No matching row is `UNASSIGNED` only if the API can prove that the covered history is complete; otherwise it is `UNKNOWN`.
- `group` is optional and remains `null` with an explicit unavailable mapping status until an authoritative institutional station-to-group relation is identified and approved. ACL group membership is not a substitute for a device assignment.
- `last_server_received_at_utc` is the maximum server receipt timestamp from accepted telemetry for that device. It is an observation of the latest persisted receipt, not a live connectivity guarantee. With no telemetry it is `null` and has no inferred online/offline state.
- No `online`, `offline`, `healthy`, or equivalent state may be derived until a separate operational policy defines thresholds, freshness, missing telemetry, and transition semantics.

The response must exclude `credential_key`, `secret_hash`, bearer secrets, tokens, password/profile values, telemetry `payload_json`, and any raw credential or payload fields. The API must not return data from an unauthorized workplace to compute completeness or diagnostics visible to the caller.

## 5. Reusable data and service boundary

Under this approved read contract, the API reuses existing API-owned sources and logic; no duplicate registry or writer is authorized:

| Existing source | Reuse | Constraint |
|---|---|---|
| `op_rh_v2_dispositivos` | Device ID, UUID, registered name, persisted `estado`, physical station ID | Never return credential material. |
| `op_rh_v2_dispositivo_estacion_historial` | Effective station assignment history | API must determine its completeness and current-interval semantics; ambiguous intervals fail closed. |
| `op_rh_v2_dispositivo_telemetria` | Server receipt timestamp for latest accepted telemetry | Do not expose `payload_json` or derive operational state. |
| `WorkplacePhysicalStationResolver` | Canonical workplace ↔ unique active physical station mapping | Reuse API-side; keep identifier domains distinct. |
| `Level2ServerService::resolverEstacionHistorica` | Existing interval resolution behavior where applicable | Do not invoke Level 2 evaluation or persist a window evaluation for this inventory GET. |

The `op_rh_localidades_perfil` store and M11 reader-profile service remain a separate application-profile feature. They do not establish device identity or device assignment. `tb_grupos` has no verified station/device relation in the inspected project model; group output is therefore optional and blocked pending an authoritative join/source.

Portal3 may reuse its existing module selector, `ChecadorAccessService`, the unique physical-station mapping, and the M11 table/DataTables visual pattern. It must not query API-owned device tables directly. The API remains the only reader/writer boundary for its device domain.

## 6. Errors and fail-closed behavior

Proposed error envelope:

```json
{"status":"ERROR","code":"STATION_SCOPE_DENIED","message":"La estación solicitada no está autorizada."}
```

| HTTP | Code | Behavior |
|---|---|---|
| 400 | `INVALID_WORKPLACE_ID` | Malformed or non-positive filter; no query outside scope. |
| 401 | `UNAUTHORIZED` | Missing, invalid, expired, or inactive delegated user identity. |
| 403 | `FUNCTION_PERMISSION_DENIED` | Missing `checador-v2-dispositivos.leer`. |
| 403 | `STATION_SCOPE_DENIED` | Empty scope or explicitly requested unauthorized locality; no existence disclosure. |
| 409 | `WORKPLACE_MAPPING_UNRESOLVED` | Explicitly requested authorized locality cannot be mapped uniquely to an active physical station. |
| 413 | `INVENTORY_LIMIT_EXCEEDED` | More than 500 authorized rows; no silent truncation and no claim of completeness. |
| 503 | `DEVICE_INVENTORY_UNAVAILABLE` | Required source unavailable; response is not interpreted as an empty list. |
| 500 | `INTERNAL_ERROR` | Generic error; no SQL, token, credential, or payload detail. |

For safely identifiable rows with missing or ambiguous device assignment, the API may return `completeness: PARTIAL` and an `UNKNOWN` assignment state. A row whose workplace scope cannot be safely determined must not be returned. If completeness of the entire authorized scope cannot be established, the response must remain `PARTIAL` or `UNKNOWN`.

## 7. Portal3 presentation

After the authorized endpoint and grant are implemented, M11 may render a DataTable with device identifier/UUID, physical station, verified canonical locality, current assignment, persisted device state, and last server receipt. The table must show `COMPLETE`, `PARTIAL`, or `UNKNOWN` explicitly; missing telemetry must not appear as “offline,” and an empty/error result must not be presented as a confirmed empty inventory.

The current M11 selector supplies a candidate locality only. Portal3 and API both revalidate it. Encargado sees only its authorized station; Dirección sees only authorized localities. Group appears only when the approved institutional relationship is returned by API.

## 8. Required verification before implementation closure

Once separately authorized, tests must cover:

1. Encargado reads own station, and an explicitly requested foreign station returns 403 with no existence disclosure.
2. Dirección reads all and only authorized localities, including an explicit out-of-scope denial.
3. Empty/expired JWT, inactive user, missing permission, empty ACL scope, and permission revocation fail closed.
4. Physical station/locality numeric IDs differ; unique `numlista` maps correctly; missing, inactive, duplicate, or ambiguous mappings do not leak rows.
5. Exactly one active assignment, no assignment with complete versus incomplete history, and overlapping assignments.
6. Device registry `ACTIVE` remains separate from telemetry freshness; absent/stale telemetry never implies `OFFLINE` or `ONLINE`.
7. Multiple telemetry rows choose the latest `server_received_at_utc`; device observation timestamp is not substituted.
8. Result at exactly 500 rows is complete if otherwise verified; above 500 returns 413 and no truncated rows.
9. API/database failure returns an error/unknown state, never an empty successful inventory.
10. No Level 2 window evaluation, snapshot, write, or credential material is produced or exposed by the GET.
11. Portal3 authenticated Chrome for both roles, server-side isolation, responsive DataTables, and no direct browser-to-API request.

## 9. Authorization boundaries

This approved addendum does **not** authorize any of the following:

- Device registration or assignment writes, activation/deactivation, credential changes, or telemetry mutation;
- Changes to the Architecture Freeze, J1/J2, P3-G, Level 1/2, or Lector contracts;
- New schema, tables, or migrations;
- Enabling any POST gate, evaluating Level 2 windows, or making production changes.

The authorized implementation is limited to `GET /api/v2/administrative-devices`, its backend-only Portal3 M11 read integration, and the dedicated development read grants for position 6 and position 13. The approving responsible owner is authorized for both projects as expressly stated in the approval record; no personal name is asserted here. The institutional station-to-group relationship remains optional and must not be inferred. Assignment history that cannot be established as complete must be shown only as `UNKNOWN`/`PARTIAL`, never as a confirmed unassigned state.

Approval transition: the responsible authorized owner approved the proposal content at source SHA-256 `2308c7f39378699822bab2d583193bdfac0246a97f1c1948ec5771458546a639`; the addendum status was transitioned to approved; its read contract scope was not expanded. The final approved addendum must remain byte-identical in Portal3 and API V2.
