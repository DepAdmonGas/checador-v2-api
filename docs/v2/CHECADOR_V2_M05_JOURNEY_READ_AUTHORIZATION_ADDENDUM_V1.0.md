# Checador V2 — M05 Journey Read Authorization Addendum V1.0

**Status:** Explicitly authorized for local development by the user under task `CHECADOR_V2_M05_STATION_JOURNEYS_READ_AUTHORIZED_V1`  
**Date:** 2026-11-02  
**Applies to:** Portal3 and API V2; read-only M05 observation workspace  
**Does not revise:** Architecture Freeze V1.0, Lector contracts, Journey evaluation contract, M03 scheduling permissions, API Block B, or J2

## 1. Purpose and capability

This addendum authorizes the independent functional capability `checador.jornadas.leer`. It permits only the GET reads listed below when the caller has a valid Portal3 JWT, an effective grant for this capability (or retains the existing `biometricos.leer` authorization path), and a non-empty server-resolved station scope.

The capability is separate from `biometricos.leer` and `biometricos.editar`. It does not create a general Biometrics module permission.

## 2. Permitted reads

- `GET /api/v2/punch-events`: raw observations, their worker/station references, integrity and capture metadata, and trace fields already returned by the endpoint. The API must revalidate caller permission and authorized station scope for every request. A worker filter is combined with, never substituted for, the server-derived station filter.
- `GET /api/v2/workplaces/{workplace_id}/timezone?date=YYYY-MM-DD`: the existing timezone metadata needed to interpret the selected local work date. The requested canonical locality must be in the server-derived station scope.
- `GET /api/v2/workers/{id}/effective-schedule` remains governed solely by the existing `checador.programacion.leer` capability and its station/worker checks. This addendum does not broaden that endpoint's authorization.
- No GET for persisted Journey evaluation results is added. The workspace must continue to say when evaluation data is unavailable or not evaluated.

Existing callers authorized through `biometricos.leer` retain their existing behavior. The new permission is admitted only by the two read handlers above; no other Biometrics route or operation may use it.

## 3. Eligible roles and development grant

- Position 6 (Encargado) may receive the new capability for M05 reads, scoped to its active home station.
- Position 13 (Dirección de Operaciones) retains its current authorized read/supervision path. This task does not add or expand its grant.
- The local development ACL grants `checador.jornadas.leer` to position 6 only. A direct-user rule, including an explicit denial/revocation, takes precedence over a position grant.
- The API resolves the active identity from the validated JWT and checks the current permission store on every request.

## 4. Station and worker isolation

The API obtains candidate physical stations from the existing active Biometrics station configuration and reuses the approved station-scope resolver to map them through the unique active `tb_estaciones.id → numlista → op_rh_localidades.id` relationship. Position 6 is restricted to its own active home station. Position 13 remains limited to its configured active station allowlist.

Empty, invalid, ambiguous, inactive, or unresolvable scope fails closed. A supplied station/locality outside the canonical authorized set returns 403. Omitting the station uses only the complete server-authorized station set. SQL remains bounded by the physical station IDs resolved from that canonical set.

## 5. Explicit exclusions

This capability does not authorize:

- any POST, PUT, PATCH, or DELETE operation;
- Journey evaluation or creation of evaluation results;
- snapshot creation or mutation;
- PunchEvent ingestion, mutation, or deletion;
- editing Biometrics, administering devices, global template administration, or administrative operations;
- M03 assignment creation/editing except through the separately authorized scheduling capability;
- M07 administrative adjustments, API Block B, runtime J2, or Lector changes;
- production ACL changes or deployment.

The existing GET-side-effect guard, global POST gate, source ownership, immutable PunchEvents, and all unrelated ACL rules remain unchanged.
