# CHECADOR V2 — ON-DEMAND JOURNEY EVALUATION API CONTRACT V1.1

## 1. CONTROL HEADER

- **Contract Name:** CHECADOR V2 — ON-DEMAND JOURNEY EVALUATION API CONTRACT V1.1
- **Document Version:** 1.1 (FROZEN_V1.1)
- **Status:** FROZEN_V1.1
- **Effective Scope:** Checador V2 API Server-to-Server Boundary (Evaluation, Provenance & Audit)
- **Target Integration:** Portal3 P04D / P3-E & P3-F (Journey Provenance & Reproducibility)
- **Predecessor Contract:** `docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.0.md` (FROZEN_V1.0)
- **Relationship to V1.0:** Supersedes V1.0 additively for response provenance and public candidate set audit. Request endpoint, request parameters, authorization model, and underlying P1/P2 algorithms remain 100% unchanged.
- **Authoritative Architecture Reference:** `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`
- **Domain Service Specifications:**
  - `docs/v2/CHECADOR_V2_JOURNEY_PAIRING_CONTRACT_V1.0.md` (Phase 1 Pairing)
  - `docs/v2/CHECADOR_V2_JOURNEY_CALCULATION_CONTRACT_V1.0.md` (Phase 2 Calculation)
  - `docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md` (Shift Scheduling)
  - `docs/v2/WORKPLACE_TIMEZONE_READ_API_CONTRACT_V1.0.md` (Workplace Timezone Authority)
  - `docs/v2/LEVEL2_LECTOR_IMPLEMENTATION_HANDOFF_V1.0.md` (Level 2 Server Verification)

---

## 2. PURPOSE & ARCHITECTURAL BOUNDARY

### 2.1 Primary Objective
This contract specifies the additive evolution of the server-to-server evaluation boundary (`POST /api/v2/journeys/evaluate`). It extends the V1.0 response with the exact technical provenance and evaluated candidate event membership required by Portal3 P3-F to independently reproduce, audit, and explain any Journey evaluation revision.

### 2.2 Scope Boundaries & Invariants
- **Additive Evolution Only:** All existing V1.0 response fields and structures remain present with identical types and semantics.
- **Request Contract Unchanged:** Endpoint URL, HTTP method, headers, and JSON request schema are identical to V1.0.
- **No Algorithm Mutation:** P1 slot allocation, debouncing, surplus handling, and P2 duration/delta formulas are not modified.
- **No Schema Changes:** Evaluated membership and timestamps are derived deterministically from existing physical tables (`op_rh_v2_pareos_marcaciones_asociadas`, `op_rh_v2_pareos_jornada`, `op_rh_v2_calculos_jornada`, `op_rh_v2_marcaciones_crudas`). Zero new database tables or columns are required.
- **No Administrative Outcomes:** Journey evaluation remains strictly technical. No administrative conclusions (`FALTA`, `SIN MARCACIÓN`, payroll deductions, or overtime approvals) are emitted.

---

## 3. ENDPOINT DEFINITION (UNCHANGED)

- **Route:** `POST /api/v2/journeys/evaluate`
- **Protocol:** HTTP/1.1 or HTTP/2 over TLS
- **Content-Type:** `application/json; charset=utf-8`

### 3.1 Authentication & Authorization (UNCHANGED)
- **Header:** `Authorization: Bearer <Portal3_JWT>`
- **Token Claims Required:**
  - `iss`: `"portal3_auth"`
  - `type`: `"access"`
  - `sub`: Positive integer (`tb_usuarios.id` of an active human user)
  - `exp`: Timestamp strictly in the future
- **Functional ACL Required:**
  - Module: `biometricos`
  - Action: `leer` (`leer = 1`)
- **Workplace Scope:**
  - The requested station ID (`id_estacion`) must be contained in the caller's authorized station list (`op_rh_localidades.id` space).
  - Out-of-scope stations return HTTP `403 Forbidden` (`STATION_SCOPE_DENIED`).
- **Device Authentication Prohibition:**
  - Device Auth headers (`X-Device-Id`, `X-Credential-Key`) are rejected with HTTP `401 Unauthorized`.

---

## 4. REQUEST SPECIFICATION (UNCHANGED)

### 4.1 JSON Request Body
```json
{
  "id_personal": 1,
  "id_estacion": 1,
  "fecha_local": "2026-10-12",
  "snapshot_audit_ref": "snap_audit_20261012_01"
}
```

### 4.2 Parameter Validations
- `id_personal` (integer, required): Canonical worker identifier (`op_rh_personal.id`). Must resolve to an existing worker (returns 404 if not found). Non-numeric or legacy usernames rejected.
- `id_estacion` (integer, required): Target station identifier (`op_rh_localidades.id`).
- `fecha_local` (string, required): Workday date in `YYYY-MM-DD` format. Must be a valid civil calendar date.
- `snapshot_audit_ref` (string, optional): Level 1 Snapshot reference used for provenance and audit tracking.

---

## 5. PROVENANCE & EVALUATED MEMBERSHIP SPECIFICATION (V1.1 ADDITIONS)

### 5.1 Evaluated Raw-Event Membership
The evaluated raw-event membership represents the **complete, exact candidate set** of raw `PunchEvent` records actually considered by the P1 pairing execution for the target ScheduledShift revision.

1. **Inclusiveness:** The collection is NOT restricted to paired entry/exit punches. It contains:
   - Paired entry punches (`ENTRY`);
   - Paired exit punches (`EXIT`);
   - Intermediate and debounce surplus punches (`SURPLUS`);
   - Unassigned/unmatched candidate punches within window $W$ (`UNMATCHED`);
   - Conflicting or invalid punches within window $W$ (`AMBIGUOUS`).
2. **Deterministic Ordering:** Elements are ordered strictly by occurrence timestamp ascending (`ocurrido_at_utc ASC`), with tie-breaker by raw identifier / UUID ascending (`id ASC` / `event_uuid ASC`).
3. **No Duplicates:** Each physical raw event appears at most once in the evaluated collection.
4. **5+ Punch Cases Supported:** Evaluation handles arbitrary punch counts without 4-slot capping.
5. **No Exposure of Raw Primary Keys:** The internal database PK (`op_rh_v2_marcaciones_crudas.id`, `entry_punch_id`, `exit_punch_id`, etc.) is **never** used as a public audit identifier.
6. **Public Raw Event Identity Schema:**
   - `event_uuid` (string, required): Canonical immutable event UUID.
   - `id_dispositivo` (integer|null, required): Originating device ID. If null, serialized as JSON `null` (never converted to 0).
   - `ocurrido_at_utc` (string, required): Canonical UTC timestamp (`YYYY-MM-DD HH:MM:SS`).
   - `metodo_captura` (string, required): Capture method (e.g., `"BIOMETRIC"`).

### 5.2 Evaluated Event Count
The response provides `evaluated_raw_event_count`:
$$\text{evaluated\_raw\_event\_count} = \text{count}(\text{evaluated\_raw\_events})$$
When `evaluated = true`, `evaluated_raw_event_count` is an integer $\ge 0$.
When `evaluated = false`, `evaluated_raw_event_count` is `null`.

### 5.3 Authoritative Timestamps
1. `p1_created_at_utc` (string|null): Persisted creation timestamp of the authoritative P1 pairing revision (`op_rh_v2_pareos_jornada.creado_at_utc`).
2. `p2_created_at_utc` (string|null): Persisted creation timestamp of the authoritative P2 calculation revision (`op_rh_v2_calculos_jornada.creado_at_utc`).
3. `response_generated_at_utc` (string): RFC-3339 / ISO-8601 UTC timestamp generated by the API server at response creation.
When `evaluated = false`, `p1_created_at_utc` and `p2_created_at_utc` are strictly `null`.

### 5.4 Authoritative Version Set & Policy Rule Version
To guarantee exact reproducibility, the response reports all authoritative version components:
1. `p1_algorithm_version` (string): P1 pairing algorithm provenance (e.g., `"v2.4-p1"`).
2. `p2_algorithm_version` (string): P2 calculation algorithm provenance (e.g., `"v2.4-p2"`).
3. `shift_template_version` (integer|null): Effective shift template version (from `op_rh_v2_turnos_plantillas.version`), or `null` if unscheduled.
4. `source_completeness_contract_version` (string): Source completeness contract version (e.g., `"v2.4"`).
5. `level2_policy_ref` (string|null): Level 2 coverage policy reference (e.g., `"POLICY_DEFAULT"`), or `null`.
6. `policy_rule_version` (null): **Explicitly `null`**. No independent HR policy/rule version exists for technical Journey P1/P2 evaluation. The contract explicitly forbids synthesizing fake version strings (`POLICY_V1`, `DEFAULT`, `V1.0`).

### 5.5 P1 Pairing Linkage in Segments
Inside `data.pairing.segments`, each segment includes stable public event links:
- `entry_event_uuid` (string|null): Public `event_uuid` of the paired entry punch, or `null`.
- `exit_event_uuid` (string|null): Public `event_uuid` of the paired exit punch, or `null`.
Existing internal linkage fields (`entry_punch_id`, `exit_punch_id`, `associated_punches`) remain present for backward compatibility.

---

## 6. RESPONSE SCHEMA SPECIFICATION

### 6.1 Evaluated Scheduled Shift Response (HTTP 200)
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
        "clave_plantilla": "MAT-01",
        "version_plantilla": 1
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
      },
      "horario_utc": {
        "start_utc": "2026-10-12 14:00:00",
        "end_utc": "2026-10-12 22:00:00"
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
      "creado_at_utc": "2026-10-12 14:05:00",
      "pairing_status": "EXACT_PAIR",
      "completeness_l2": "VERIFIED",
      "algorithm_version": "v2.4-p1",
      "fingerprint": "a1b2c3d4e5f6...",
      "segments": [
        {
          "ordinal": 1,
          "status": "EXACT_PAIR",
          "entry_punch_id": 1001,
          "exit_punch_id": 1002,
          "entry_event_uuid": "ev-punch-001",
          "exit_event_uuid": "ev-punch-002",
          "associated_punches": [
            {"punch_id": 1001, "role": "ENTRY"},
            {"punch_id": 1002, "role": "EXIT"}
          ]
        }
      ],
      "is_idempotent_reuse": false
    },
    "calculation": {
      "calculation_id": 38,
      "revision_numero": 1,
      "creado_at_utc": "2026-10-12 14:05:02",
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
    "evaluated_raw_event_count": 2,
    "evaluated_raw_events": [
      {
        "event_uuid": "ev-punch-001",
        "id_dispositivo": 1,
        "ocurrido_at_utc": "2026-10-12 14:00:00",
        "metodo_captura": "BIOMETRIC"
      },
      {
        "event_uuid": "ev-punch-002",
        "id_dispositivo": 1,
        "ocurrido_at_utc": "2026-10-12 22:00:00",
        "metodo_captura": "BIOMETRIC"
      }
    ],
    "provenance": {
      "response_generated_at_utc": "2026-10-13T17:50:00Z",
      "p1_created_at_utc": "2026-10-12 14:05:00",
      "p2_created_at_utc": "2026-10-12 14:05:02",
      "p1_algorithm_version": "v2.4-p1",
      "p2_algorithm_version": "v2.4-p2",
      "shift_template_version": 1,
      "source_completeness_contract_version": "v2.4",
      "level2_policy_ref": "POLICY_DEFAULT",
      "policy_rule_version": null,
      "evaluated_raw_event_count": 2,
      "evaluated_raw_events": [
        {
          "event_uuid": "ev-punch-001",
          "id_dispositivo": 1,
          "ocurrido_at_utc": "2026-10-12 14:00:00",
          "metodo_captura": "BIOMETRIC"
        },
        {
          "event_uuid": "ev-punch-002",
          "id_dispositivo": 1,
          "ocurrido_at_utc": "2026-10-12 22:00:00",
          "metodo_captura": "BIOMETRIC"
        }
      ]
    }
  }
}
```

### 6.2 Unscheduled Shift Response (HTTP 200, evaluated: false)
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
      "snapshot_audit_ref": "snap_audit_20261012_01",
      "window_level2_status": "UNKNOWN",
      "level2_evaluation_ref": null
    },
    "pairing": null,
    "calculation": null,
    "evaluated_raw_event_count": null,
    "evaluated_raw_events": null,
    "provenance": {
      "response_generated_at_utc": "2026-10-13T17:50:00Z",
      "p1_created_at_utc": null,
      "p2_created_at_utc": null,
      "p1_algorithm_version": "v2.4-p1",
      "p2_algorithm_version": "v2.4-p2",
      "shift_template_version": null,
      "source_completeness_contract_version": "v2.4",
      "level2_policy_ref": null,
      "policy_rule_version": null,
      "evaluated_raw_event_count": null,
      "evaluated_raw_events": null
    }
  }
}
```

---

## 7. BACKWARD COMPATIBILITY ASSURANCE

1. All clients consuming V1.0 structure continue to receive all V1.0 fields unchanged.
2. New fields in `data.provenance`, `data.pairing.segments`, and `data` are strictly additive.
3. No field renames, deletions, or type alterations.
4. Existing test suites (JRB01–JRB30) remain 100% compliant and passing.

---

## 8. FORMAL FREEZE DECLARATION

This contract is formally **FROZEN** as Version 1.1. Any modification requires an additive successor (V1.2+) and explicit project authorization.

- **Freeze Date:** 2026-10-13
- **Status:** `FROZEN_V1.1`
