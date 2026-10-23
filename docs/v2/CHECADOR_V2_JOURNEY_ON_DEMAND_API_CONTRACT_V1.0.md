# CHECADOR V2 — JOURNEY ON_DEMAND EVALUATION API CONTRACT (V1.0)

## 1. CONTROL HEADER

- **Contract Name:** CHECADOR V2 — JOURNEY ON_DEMAND EVALUATION API CONTRACT
- **Document Version:** 1.0 (FROZEN_V1.0)
- **Status:** FROZEN_V1.0
- **Effective Scope:** Portal3 Server-to-Server On-Demand Journey Evaluation Boundary
- **Target Execution Date:** 2026-12-31
- **Authoritative Architecture:** `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`
- **Pairing Contract Reference:** `docs/v2/CHECADOR_V2_JOURNEY_PAIRING_CONTRACT_V1.0.md`
- **Calculation Contract Reference:** `docs/v2/CHECADOR_V2_JOURNEY_CALCULATION_CONTRACT_V1.0.md`
- **Shift Service & API Contract Reference:** `docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md`
- **Level 1 Snapshot Contract Reference:** `docs/v2/PUNCH_EVENTS_SNAPSHOT_READ_API_CONTRACT_V2.4_LEVEL1.md`
- **Workplace Timezone Contract Reference:** `docs/v2/WORKPLACE_TIMEZONE_READ_API_CONTRACT_V1.0.md`

---

## 2. PURPOSE & ARCHITECTURAL BOUNDARY

### 2.1 Primary Objective
This contract specifies the additive, server-to-server HTTP API boundary exposing authoritative, deterministic On-Demand Journey Evaluation (`P1 Pairing` and `P2 Calculation`) to the Portal3 backend for the `PORTAL3-P04D / P3-E` milestone.

### 2.2 Domain Authority vs. Consumer Decoupling
1. **API Domain Authority:** Checador V2 API exclusively owns orchestration, business validation, schedule resolution, ScheduledShift materialization, P1 pairing execution, P2 calculation execution, and append-only revision persistence.
2. **Portal3 Consumer Role:** Portal3 is strictly a presentation and human workflow consumer. Portal3 **MUST NOT** implement pairing algorithms, calculation algorithms, nor perform direct database writes to P1, P2, or raw punch tables.
3. **Browser Isolation:** Browsers never interact with this endpoint directly. All requests originate from the Portal3 backend server.
4. **Device Auth Prohibition:** Physical Lector devices never call this endpoint; Device Auth is invalid (HTTP 401).
5. **No Temporary Access Dependency:** The `acceso-temporal` permission is unrelated and conveys no authorization (HTTP 403).
6. **No Payroll or Administrative Conclusions:** This endpoint **MUST NOT** produce administrative absence (`FALTA`), definitive `SIN MARCACIÓN`, payroll deductions, overtime authorization, or monetary calculations.

---

## 3. HTTP ROUTE & METHOD RATIONALE

### 3.1 Endpoint Definition
- **Route:** `POST /api/v2/journeys/evaluate`
- **Protocol:** HTTPS / TLS
- **Content-Type:** `application/json; charset=utf-8`

### 3.2 Method RATIONALE
Evaluating a journey for a scheduled workday triggers deterministic orchestration that:
1. Materializes an immutable `ScheduledShift` record in `op_rh_v2_jornadas_programadas` if not previously materialized.
2. Allocates and persists append-only `P1 Pairing` revisions (`op_rh_v2_pareos_jornada`) with monotonic revision numbers and execution fingerprints when new data exists.
3. Allocates and persists append-only `P2 Calculation` revisions (`op_rh_v2_calculos_jornada`).

Because state is persisted and revisions are recorded, the operation is **NOT** a side-effect-free read. Therefore, modeling this endpoint as an HTTP `GET` is strictly prohibited by RFC 9110 and Checador V2 architecture. `POST` is contractually required.

---

## 4. AUTHENTICATION & AUTHORIZATION MODEL

### 4.1 Required Authentication
Requests must supply a valid Portal3 Bearer JWT in the `Authorization` header:
```http
Authorization: Bearer <Portal3_JWT>
```
The JWT must satisfy:
- Algorithm: `HS256` signed with `JWT_SECRET`.
- Issuer (`iss`): `portal3_auth`.
- Expiration (`exp`): Valid in current epoch timestamp.
- Token Type (`type`): `access`.
- Subject (`sub`): Integer referencing an active user (`tb_usuarios.id` where `estatus = 0`).

### 4.2 Required Functional Permission & Workplace Scope
Authorization delegates strictly to `Portal3JwtAuthHelper::authorizeBiometricos($conexion, $auth, 'leer')`:
- **Functional Module:** `biometricos` in `modulos_dpto_operativo`.
- **Required Permission:** `leer = 1` (direct user grant or post fallback).
- **Workplace Scope:** The requested `id_estacion` must exist within the caller's authorized station scope (`authorized_stations`), resolved into `op_rh_localidades.id` space.
- Out-of-scope station requests fail closed with HTTP 403 `STATION_SCOPE_DENIED`.
- Missing or invalid permission fails closed with HTTP 403 `FUNCTION_PERMISSION_DENIED`.

---

## 5. REQUEST SPECIFICATION

### 5.1 JSON Payload Schema
```json
{
  "id_personal": 1,
  "id_estacion": 1,
  "fecha_local": "2026-10-12",
  "snapshot_audit_ref": "snap_audit_optional_reference"
}
```

### 5.2 Field Definitions
| Field | Type | Required | Description |
| :--- | :--- | :--- | :--- |
| `id_personal` | Integer | Yes | Canonical worker identity referencing `op_rh_personal.id`. |
| `id_estacion` | Integer | Yes | Canonical workplace identity referencing `op_rh_localidades.id`. |
| `fecha_local` | String | Yes | Workday date in `YYYY-MM-DD` format. |
| `snapshot_audit_ref` | String | No | Optional Level 1 Snapshot audit reference for provenance tracking. |

### 5.3 Prohibited Input Fallbacks
- `tb_usuarios.id` is **NOT** accepted as a worker identity.
- `no_colaborador` is **NOT** an identity fallback.
- `external_worker_reference` is **NOT** an identity fallback.
- Client-supplied timezones are strictly rejected; the canonical Workplace timezone authority is used.
- Client-supplied Level 2 statuses or schedule calculations are strictly rejected.

---

## 6. DOMAIN ORCHESTRATION RULES

### 6.1 Worker & Workplace Validation
1. Verify `id_personal` exists in `op_rh_personal`. Unknown workers return HTTP 404 `WORKER_NOT_FOUND`.
2. Verify `id_estacion` is in caller's `authorized_stations`. Non-scoped workplaces return HTTP 403 `STATION_SCOPE_DENIED`.

### 6.2 Authoritative Workplace Timezone
The canonical IANA timezone is resolved through `WorkplaceTimezoneAuthorityService::getWorkplaceTimezone($idEstacion, $fechaLocal)`. If unmapped, evaluation fails closed with HTTP 404 `WORKPLACE_TIMEZONE_UNRESOLVED`.

### 6.3 Effective Schedule Resolution
Schedule resolution calls `ScheduleResolutionService::resolveSchedule($idPersonal, $idEstacion, $fechaLocal)`.
- **`UNSCHEDULED` Behavior:**
  If the schedule is `UNSCHEDULED`, the engine:
  - **DOES NOT** materialize a fake `ScheduledShift`.
  - **DOES NOT** execute P1 pairing or create an artificial pairing.
  - **DOES NOT** convert `UNSCHEDULED` to `DAY_OFF`.
  - **DOES NOT** emit `SIN MARCACIÓN`.
  - **DOES NOT** emit `FALTA`.
  - Returns HTTP 200 with `evaluated: false`, `schedule.estado_jornada = "UNSCHEDULED"`, and `journey_status = "UNSCHEDULED_DAY"`.
- **`UNKNOWN` Schedule Behavior:**
  Returns HTTP 200 with `evaluated: false`, `schedule.estado_jornada = "UNKNOWN"`, and `journey_status = "UNKNOWN_SCHEDULE"`.

### 6.4 Materialization & P1 / P2 Execution
When `estado_jornada` is `SCHEDULED` or `DAY_OFF`:
1. **Materialize ScheduledShift:** Calls `ScheduledShiftMaterializationService::materializeSingleDay($idPersonal, $idEstacion, $fechaLocal)`. Existing shifts return idempotently without duplicate inserts.
2. **Execute P1 Pairing:** Calls `JourneyPairingService::pairScheduledShift($shiftId)`.
   - Reuses existing candidate punch queries.
   - Evaluates Level 2 completeness evidence.
   - Computes execution fingerprint; reuses existing identical execution if present (idempotent).
   - Preserves all punches, surplus punches, and multi-segment slot assignments without 4-slot truncation.
3. **Execute P2 Calculation:** Calls `JourneyCalculationService::calculatePairingRevision($pairingId)`.
   - Derives signed arrival/departure deltas and worked duration seconds.
   - Preserves monotonic revision numbering and input fingerprinting.
   - Reuses existing calculation if input fingerprint matches (idempotent).

### 6.5 Level 2 Completeness & Finality Semantics
- If aggregate Level 2 window status is `UNKNOWN` or `INCOMPLETE`, Journey finality is non-final (`completeness_l2 = "PENDING_SYNC_COMPLETION"`, calculation status `PENDING_SYNC`).
- Dimension-level `VERIFIED` never upgrades aggregate `UNKNOWN`.
- Definitive `SIN MARCACIÓN` is **NOT** emitted merely because event count is 0 or Level 2 is `VERIFIED`. Full absence resolution requires downstream administrative incidence gates (P3-G).

---

## 7. RESPONSE SPECIFICATION

### 7.1 Successful Scheduled Journey Response (HTTP 200)
```json
{
  "status": "success",
  "data": {
    "evaluated": true,
    "journey_status": "EVALUATED",
    "context": {
      "id_personal": 1,
      "id_estacion": 1,
      "fecha_local": "2026-10-12",
      "timezone": "America/Mexico_City"
    },
    "schedule": {
      "estado_jornada": "SCHEDULED",
      "scheduled_shift_id": 105,
      "origen": {
        "id_asignacion": 12,
        "id_plantilla": 3,
        "clave_plantilla": "MAT-01"
      },
      "horario_local": {
        "timezone": "America/Mexico_City",
        "segmentos": [
          {
            "ordinal": 1,
            "tipo_segmento": "WORK",
            "hora_inicio_local": "08:00:00",
            "hora_fin_local": "16:00:00",
            "dia_offset_inicio": 0,
            "dia_offset_fin": 0
          }
        ]
      }
    },
    "source_evidence": {
      "snapshot_audit_ref": "snap_audit_20261012_01",
      "window_level2_status": "VERIFIED",
      "level2_evaluation_ref": "eval_ref_abcdef123456"
    },
    "pairing": {
      "pairing_id": 42,
      "revision_numero": 1,
      "pairing_status": "EXACT_PAIR",
      "completeness_l2": "VERIFIED",
      "algorithm_version": "v2.4-p1",
      "fingerprint": "a1b2c3d4...",
      "segments": [
        {
          "ordinal": 1,
          "status": "EXACT_PAIR",
          "entry_punch_id": 1001,
          "exit_punch_id": 1002,
          "associated_punches": []
        }
      ]
    },
    "calculation": {
      "calculation_id": 38,
      "revision_numero": 1,
      "estado_calculo": "CALCULATED",
      "version_algoritmo": "v2.4-p2",
      "segundos_programados_totales": 28800,
      "segundos_trabajados_totales": 28800,
      "segundos_candidatos_fuera_horario": 0,
      "segmentos": [
        {
          "ordinal_segmento": 1,
          "estado_segmento": "CALCULATED",
          "segundos_programados": 28800,
          "segundos_trabajados": 28800,
          "delta_llegada_segundos": 0,
          "delta_salida_segundos": 0,
          "segundos_candidatos_fuera_horario": 0
        }
      ]
    },
    "provenance": {
      "p1_algorithm_version": "v2.4-p1",
      "p2_algorithm_version": "v2.4-p2"
    }
  }
}
```

### 7.2 Successful Unscheduled Response (HTTP 200)
```json
{
  "status": "success",
  "data": {
    "evaluated": false,
    "journey_status": "UNSCHEDULED_DAY",
    "context": {
      "id_personal": 1,
      "id_estacion": 1,
      "fecha_local": "2026-10-12",
      "timezone": "America/Mexico_City"
    },
    "schedule": {
      "estado_jornada": "UNSCHEDULED",
      "scheduled_shift_id": null,
      "reason": "Worker has active system profile but no shift assigned for target station and weekday"
    },
    "source_evidence": {
      "snapshot_audit_ref": null,
      "window_level2_status": "UNKNOWN",
      "level2_evaluation_ref": null
    },
    "pairing": null,
    "calculation": null,
    "provenance": {
      "p1_algorithm_version": "v2.4-p1",
      "p2_algorithm_version": "v2.4-p2"
    }
  }
}
```

### 7.3 Error Status Codes
- `400 BAD_REQUEST`: Missing/invalid JSON, non-numeric IDs, or invalid date format.
- `401 UNAUTHORIZED`: Missing, expired, or malformed Portal3 JWT. Device Auth rejected.
- `403 FORBIDDEN`: Missing `biometricos.leer` permission (`FUNCTION_PERMISSION_DENIED`) or station outside authorized ACL scope (`STATION_SCOPE_DENIED`).
- `404 NOT_FOUND`: Worker not found or workplace timezone unresolved.
- `405 METHOD_NOT_ALLOWED`: Any HTTP method other than `POST`.
- `415 UNSUPPORTED_MEDIA_TYPE`: Non-JSON Content-Type.
- `500 SERVER_ERROR`: Internal server exception (sanitized; zero stack trace/SQL leak).

---

## 8. FORMAL FREEZE DECLARATION

This contract is formally **FROZEN** as Version 1.0. Any future modifications require formal governance approval.

- **Freeze Date:** 2026-10-13
- **Status:** `FROZEN_V1.0`
