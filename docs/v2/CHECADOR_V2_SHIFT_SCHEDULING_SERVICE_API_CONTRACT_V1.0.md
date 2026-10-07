# CHECADOR V2 — SHIFT SCHEDULING SERVICE & API CONTRACT V1.0

**Document Status:** FROZEN_V1.0
**Governance:** Aligned with `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md` and `docs/architecture/CHECADOR_V2_SHIFT_SCHEDULING_PHYSICAL_MODEL_V1.0.md` (FROZEN_V1.0 SHA256: `7fa934b7e12ed7a8cca74e52420de0373494ed7cd47b233635288fe5abea52a6`).

---

## 1. PURPOSE AND SCOPE

This document specifies the backend domain service boundaries, HTTP REST API contracts, input validation rules, effective-dated historical resolution logic, materialization semantics, and test matrix for **Checador V2 Shift Scheduling**.

### Scope:
- Reusable immutable versioned Shift Templates (`op_rh_v2_turnos_plantillas`) and normalized 1..N Shift Segments (`op_rh_v2_turnos_segmentos`).
- Worker Shift Assignments (`op_rh_v2_turnos_asignaciones`) with effective-dated validity intervals (`valido_desde` inclusive, `valido_hasta` exclusive/NULL) and multi-workplace support (`id_estacion`).
- Effective schedule resolution service for arbitrary local dates (`SCHEDULED`, `DAY_OFF`, `UNSCHEDULED`, `UNKNOWN`).
- Materialization boundary for daily scheduled shift facts (`op_rh_v2_jornadas_programadas`).

### Explicit Non-Goals:
- **No Runtime Implementation:** This document is a specification candidate for V2.3-A04. No PHP source code, routes, database migrations, or UI code are created in this slice.
- **No Raw Punch Event Modification:** `op_rh_v2_marcaciones_crudas` remains append-only and untouched.
- **No Journey Engine Logic:** Workday pairing algorithms, late arrival calculations, overtime evaluation, night-shift premium computation, and payroll projections belong strictly to downstream milestones (V2.4+).
- **No Legacy Schedule Overwrite:** `op_rh_personal_horario` is not mutated or overwritten.
- **No Portal3 / Lector Client Runtime Modifications:** Lector remains baseline FROZEN (`ACTIVE_DEVELOPMENT = NO`). Portal3 is an external administrative consumer; no Portal3 UI changes are in scope.

---

## 2. PROPOSED SERVICE BOUNDARIES

To maintain decoupling, high cohesion, and strict testability, the Shift Scheduling backend domain is organized into four core service interfaces under the `modelo/v2/shift/` namespace:

1. `ShiftTemplateService`
   - Manages versioned shift templates and atomic segment sets.
   - Enforces immutable template revisioning (creates new `version` upon modification rather than updating existing active rows).
   - Manages template activation/deactivation (`es_activo`).

2. `ShiftAssignmentService`
   - Manages effective-dated worker shift assignments.
   - Enforces multi-workplace interval overlap validation (`id_personal`, `id_estacion`, `dia_semana`, `valido_desde`, `valido_hasta`).
   - Handles assignment closure, supersession, and retroactive historical corrections without destructive updates to past effective records.

3. `ScheduleResolutionService`
   - Deterministic read-only engine resolving the effective schedule for a specific worker (`id_personal`) at a specific workplace (`id_estacion`) on a given local calendar date (`fecha_local`).
   - Evaluates active assignments, checks day-of-week rules, and yields `SCHEDULED`, `DAY_OFF`, `UNSCHEDULED`, or `UNKNOWN` status without hidden database write side-effects.

4. `ScheduledShiftMaterializationService`
   - Materializes concrete daily scheduled shift records into `op_rh_v2_jornadas_programadas`.
   - Converts local segment time intervals to exact UTC timestamps (`scheduled_start_utc`, `scheduled_end_utc`) using canonical workplace timezone authority.
   - Preserves immutable segment payload JSON snapshots (`payload_segmentos_json`) and origin provenance (`id_plantilla_origen`, `id_asignacion_origen`).

---

## 3. AUTHORIZATION MODEL & ACL VOCABULARY

### Authentication:
- **Header:** `Authorization: Bearer <Portal3_JWT>`
- **Validation Helper:** `Portal3JwtAuthHelper::validateToken()`
- **Issuer:** Must strictly be `portal3_auth`.
- **Subject:** `payload.sub` must resolve to an active administrative user in `tb_usuarios` (`estatus = 0`).

### Module & Permission ACL:
- **Module Key:** `biometricos` (existing functional module key in `modulos_dpto_operativo` & `tb_modulos_config`).
- **Required Permissions:**
  - Read Endpoints (GET): `biometricos.leer = 1`
  - Mutation Endpoints (POST): `biometricos.editar = 1`
- **Station Scope (Server-Side Filter):**
  - Resolved fresh via user/puesto multi-station configuration (`tb_multiestacion_usuario` / `tb_multiestacion_puesto`) or home station (`tb_usuarios.id_gas`).
  - Intersected with active supported stations in `tb_modulos_config` for `biometricos`.
  - Mapped to `op_rh_localidades.id` space. All station-scoped queries/mutations validate `id_estacion` against the caller's server-side authorized stations list.
- **Authorization Result:**
  - `EXISTING_AUTHZ_REUSABLE`: YES (`biometricos` module key reuse).
  - `AUTHZ_DESIGN_REQUIRES_EXPLICIT_APPROVAL`: NO (utilizes existing Portal3 JWT + `biometricos` ACL vocabulary).

---

## 4. ENDPOINT INVENTORY & API CONTRACTS

### 4.1. Template & Segment Management

#### 1. `POST /api/v2/shift-templates`
- **Summary:** Create a new shift template (version 1) with atomic 1..N segments.
- **Auth:** Portal3 JWT (`biometricos.editar = 1`).
- **Request Body:**
  ```json
  {
    "clave": "TURNO_MATUTINO_8H",
    "nombre": "Turno Matutino 8 Horas",
    "tipo_turno": "REGULAR",
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
  ```
- **Idempotency:** Optional `X-Idempotency-Key` header.
- **Response (201 Created):**
  ```json
  {
    "status": "success",
    "data": {
      "id": 10,
      "clave": "TURNO_MATUTINO_8H",
      "version": 1,
      "nombre": "Turno Matutino 8 Horas",
      "tipo_turno": "REGULAR",
      "es_activo": 1,
      "segmentos": [
        {
          "id": 25,
          "ordinal": 1,
          "tipo_segmento": "WORK",
          "hora_inicio_local": "08:00:00",
          "hora_fin_local": "16:00:00",
          "dia_offset_inicio": 0,
          "dia_offset_fin": 0
        }
      ]
    }
  }
  ```
- **Error Responses:** 400 Bad Request (validation failure), 409 Conflict (`clave` + `version` duplicate).

#### 2. `POST /api/v2/shift-templates/{clave}/versions`
- **Summary:** Create a new version for an existing template code (`clave`). Automatically increments version number.
- **Auth:** Portal3 JWT (`biometricos.editar = 1`).
- **Behavior:** Reads highest existing version for `clave`, inserts new row with `version = max_version + 1`, and attaches new segment set atomically within a single MySQL transaction. Concurrent version creation attempts locking the same `clave` execute within transaction and resolve serialization race conditions cleanly. Previous versions remain immutable in DB.

#### 3. `GET /api/v2/shift-templates`
- **Summary:** List shift templates (latest active versions by default, or all versions via `include_all_versions=true`).
- **Auth:** Portal3 JWT (`biometricos.leer = 1`).
- **Query Params:** `page` (default 1, min 1), `per_page` (default 50, max 200), `tipo_turno`, `es_activo`.

#### 4. `GET /api/v2/shift-templates/{clave}/versions/{version}`
- **Summary:** Fetch specific shift template version and its segment set.
- **Auth:** Portal3 JWT (`biometricos.leer = 1`).

---

### 4.2. Shift Assignment Management

#### 5. `POST /api/v2/shift-assignments`
- **Summary:** Create a worker shift assignment for a workplace and weekday pattern.
- **Auth:** Portal3 JWT (`biometricos.editar = 1`). Server-side validation ensures `id_estacion` is in user's authorized scope.
- **Request Body:**
  ```json
  {
    "id_personal": 105,
    "id_estacion": 12,
    "id_plantilla": 10,
    "dia_semana": "LUNES",
    "valido_desde": "2026-11-01",
    "valido_hasta": null,
    "es_activo": 1
  }
  ```
- **Validation Rules:**
  - `valido_desde` must be a valid `YYYY-MM-DD` date.
  - `valido_hasta` must be `NULL` or a date strictly greater than `valido_desde`.
  - Application-level overlap check (`SELECT ... FOR UPDATE`) verifies no active assignment (`es_activo = 1`) exists for the same (`id_personal`, `id_estacion`, `dia_semana`) with overlapping date intervals `[valido_desde, valido_hasta)`.
- **Response (201 Created):** Returns assignment detail with server audit timestamps (`creado_at`, `registrado_at_utc`).

#### 6. `POST /api/v2/shift-assignments/{id}/close`
- **Summary:** Close an open-ended assignment effective on a given date by creating a closed revision record or updating `valido_hasta` while preserving as-recorded auditability.
- **Auth:** Portal3 JWT (`biometricos.editar = 1`).
- **Behavior:** Does NOT mutate past effective dates prior to closing date. Sets `valido_hasta` to effective closing date with audit timestamp (`registrado_at_utc`).

#### 7. `GET /api/v2/shift-assignments`
- **Summary:** Query worker assignment history or current active assignments.
- **Auth:** Portal3 JWT (`biometricos.leer = 1`).
- **Query Params:** `id_personal` (required), `id_estacion` (optional, must be authorized), `fecha_referencia` (optional `YYYY-MM-DD`), `page` (default 1), `per_page` (default 50, max 200).

---

### 4.3. Schedule Resolution & Materialization

#### 8. `GET /api/v2/workers/{id_personal}/effective-schedule`
- **Summary:** Resolve effective shift schedule for a worker at a workplace for a given local date.
- **Auth:** Portal3 JWT (`biometricos.leer = 1`).
- **Query Params:** `id_estacion` (required), `fecha_local` (required `YYYY-MM-DD`).
- **Behavior:** Pure read-only resolution. Performs NO database writes or implicit materialization.
- **Response (200 OK):**
  ```json
  {
    "status": "success",
    "data": {
      "id_personal": 105,
      "id_estacion": 12,
      "fecha_local": "2026-11-02",
      "estado_jornada": "SCHEDULED",
      "origen": {
        "id_asignacion": 42,
        "id_plantilla": 10,
        "clave_plantilla": "TURNO_MATUTINO_8H",
        "version_plantilla": 1
      },
      "horario_local": {
        "timezone": "America/Mexico_City",
        "segmentos": [
          {
            "ordinal": 1,
            "tipo": "WORK",
            "hora_inicio_local": "08:00:00",
            "hora_fin_local": "16:00:00"
          }
        ]
      },
      "horario_utc": {
        "start_utc": "2026-11-02T14:00:00Z",
        "end_utc": "2026-11-02T22:00:00Z"
      }
    }
  }
  ```

#### 9. `POST /api/v2/scheduled-shifts/materialize`
- **Summary:** Materialize daily scheduled shifts for a worker/workplace/date range into `op_rh_v2_jornadas_programadas`.
- **Auth:** Portal3 JWT (`biometricos.editar = 1`).
- **Request Body:**
  ```json
  {
    "id_personal": 105,
    "id_estacion": 12,
    "fecha_desde": "2026-11-01",
    "fecha_hasta": "2026-11-07"
  }
  ```
- **Behavior:** Bounded date range (max 31 days). Executes resolution, converts local times to UTC using workplace timezone, formats segment JSON snapshot, and performs idempotent `INSERT INTO op_rh_v2_jornadas_programadas`. Existing materialized shifts for `(id_personal, id_estacion, fecha_jornada_local)` are immutable historical facts; duplicate materialization requests for already materialized dates return 200 OK (idempotent no-op) without overwriting existing records.

---

## 5. ERROR CONTRACT & ENVELOPE

All API responses strictly adhere to the established Checador V2 JSON error envelope:

```json
{
  "status": "error",
  "error": {
    "code": "ASSIGNMENT_OVERLAP_CONFLICT",
    "message": "Worker 105 already has an active shift assignment for station 12 on LUNES overlapping with interval [2026-11-01, 2026-12-01).",
    "details": {
      "conflicting_assignment_id": 38,
      "id_personal": 105,
      "id_estacion": 12,
      "dia_semana": "LUNES"
    }
  }
}
```

### Stable Error Vocabulary:
- `INVALID_TEMPLATE_SPECIFICATION`: Segment ordinals discontinuous, times invalid, or missing required fields (400).
- `DUPLICATE_TEMPLATE_VERSION`: Combination of `clave` + `version` already exists (409).
- `ASSIGNMENT_OVERLAP_CONFLICT`: Effective date range overlaps existing assignment for worker/station/weekday (409).
- `INVALID_EFFECTIVE_INTERVAL`: `valido_hasta` is on or before `valido_desde` (400).
- `UNAUTHORIZED_STATION_ACCESS`: Requested `id_estacion` is outside caller's ACL scope (403).
- `HISTORICAL_MUTATION_PROHIBITED`: Attempt to perform destructive in-place update on past active template, assignment, or materialized shift (422).
- `WORKPLACE_TIMEZONE_UNRESOLVED`: Workplace has no configured canonical timezone in DB (500).

---

## 6. BUSINESS & ARCHITECTURAL SEMANTICS

### 6.1. Template Versioning & Mutation Model
- In-place `UPDATE` of shift segments on existing template versions is **PROHIBITED** once assigned or materialized.
- Modifying a template creates a new `version` (e.g. `version = 2`). Existing historical `op_rh_v2_jornadas_programadas` records retain their origin `id_plantilla_origen` FK pointing to the exact version active when materialized.

### 6.2. Effective-Dated Assignment & Correction Model
- Assignments define validity via `[valido_desde, valido_hasta)`.
- **Retroactive Correction Rule:** To correct an assignment, administrators close the assignment by setting `valido_hasta` and insert a new superseding assignment with audit timestamps (`registrado_at_utc`). Destructive SQL `DELETE` or silent past date range overwrites are forbidden.

### 6.3. Assignment Overlap & Multi-Workplace Scoping
- Overlap validation checks:
  $$\text{Overlap} \iff (A.\text{valido\_desde} < B.\text{valido\_hasta} \lor B.\text{valido\_hasta IS NULL}) \land (B.\text{valido\_desde} < A.\text{valido\_hasta} \lor A.\text{valido\_hasta IS NULL})$$
- Overlap is evaluated **per workplace (`id_estacion`)**. A worker **MAY** hold assignments at different workplaces (`id_estacion = 10` and `id_estacion = 20`) on the same day. Physical daily shift window collision across workplaces is validated during schedule resolution.

### 6.4. Concurrency & Transaction Control
- All assignment creations and materialization routines execute within MySQL InnoDB transactions using strict row locking:
  ```sql
  SELECT id FROM op_rh_v2_turnos_asignaciones
  WHERE id_personal = ? AND id_estacion = ? AND dia_semana = ? AND es_activo = 1
  FOR UPDATE;
  ```
- Ensures concurrent administrative requests cannot create race-condition assignment overlaps.

### 6.5. Schedule Resolution Logic
For a target `(id_personal, id_estacion, fecha_local)`:
1. Query active assignment in `op_rh_v2_turnos_asignaciones` matching weekday of `fecha_local` where `valido_desde <= fecha_local` and (`valido_hasta > fecha_local` or `valido_hasta IS NULL`).
2. If matching active assignment exists with assigned template: return `SCHEDULED` with segments.
3. If matching active assignment exists marked explicitly as day off (`id_plantilla = NULL` or `DAY_OFF` assignment): return `DAY_OFF`.
4. If no assignment exists for worker at workplace, but system has explicit worker registration: return `UNSCHEDULED`.
5. If target date is prior to system cutover or worker is unmapped: return `UNKNOWN` (preserves pre-cutover ambiguity without assuming `DAY_OFF`).

### 6.6. Workplace Timezone Authority & Gap Audit
- Workplace timezone is resolved server-side from station configuration (`op_rh_localidades_perfil` / `op_rh_v2_dispositivos.timezone` associated with `op_rh_localidades.id`, defaulting to `America/Mexico_City`).
- Technical device timezones (`op_rh_v2_dispositivos.timezone`) serve as hardware fallback metadata and do **NOT** override workplace timezone authority.
- `WORKPLACE_TIMEZONE_RUNTIME_GAP`: `NO` (Workplace timezone is derived from station configuration and explicitly stored in `op_rh_v2_jornadas_programadas.timezone_local`).

### 6.7. Materialization Boundary & Trigger
- **Materialization Trigger:** `EXPLICIT_BATCH_OR_ON_DEMAND`
  - Materialization into `op_rh_v2_jornadas_programadas` occurs via explicit batch API (`POST /api/v2/scheduled-shifts/materialize`) or scheduled cron job.
- **Idempotency & Immutability:** Unique key `uk_v2_jornada_personal_estacion_fecha` (`id_personal`, `id_estacion`, `fecha_jornada_local`) prevents duplicate rows. Once materialized, `op_rh_v2_jornadas_programadas` records are immutable historical facts. Re-materialization attempts for already materialized dates are idempotent no-ops.

### 6.8. Arbitrary Segment Model (No Four-Slot Cap)
- Shift Scheduling supports 1..N segments (`WORK`, `BREAK`, etc.) per template.
- Legacy concepts of "Entrada, Salida a Comer, Regreso, Salida" are presentation-layer projections only. The physical schema and API support arbitrary segment counts and cross-midnight offsets (`dia_offset_inicio`, `dia_offset_fin`).

---

## 7. TARGET TEST MATRIX (FOR FUTURE A05/A07 SLICE)

| Test ID | Test Category | Description / Assertion | Expected Result |
| :--- | :--- | :--- | :--- |
| **ST-01** | Template Creation | Create template with 1 valid work segment. | 201 Created, version = 1 |
| **ST-02** | Template Duplicate | Insert template with duplicate `clave` + `version`. | 409 Conflict |
| **ST-03** | Template Versioning | Create new version for existing `clave`. | 201 Created, version = 2, v1 unchanged |
| **ST-04** | Multi-Segment | Create split-shift template with 2 work segments (ordinals 1, 2). | 201 Created, ordinals persisted |
| **ST-05** | Overnight Segment | Create overnight shift segment (`22:00:00` to `06:00:00`, `dia_offset_fin = 1`). | 201 Created, offset recorded |
| **SA-01** | Assignment Create | Assign valid template to worker 1 at station 10 for LUNES. | 201 Created |
| **SA-02** | Overlap Conflict | Create overlapping assignment for same worker/station/weekday. | 409 Conflict (`ASSIGNMENT_OVERLAP_CONFLICT`) |
| **SA-03** | Multi-Workplace | Assign worker 1 to station 20 on same weekday with non-overlapping hours. | 201 Created (Allowed per workplace) |
| **SA-04** | Indefinite Validity | Create assignment with `valido_hasta = NULL`. | 201 Created |
| **SR-01** | Resolve Scheduled | Resolve schedule for date with active template assignment. | Returns `SCHEDULED` + UTC interval |
| **SR-02** | Resolve Day Off | Resolve schedule for date with explicit day off assignment. | Returns `DAY_OFF` |
| **SR-03** | Resolve Unscheduled| Resolve schedule for date with no worker assignment. | Returns `UNSCHEDULED` |
| **SR-04** | Resolve Unknown | Resolve schedule for pre-cutover date without evidence. | Returns `UNKNOWN` |
| **SM-01** | Materialize Shift | Materialize daily shift converting local segment times to UTC. | Row inserted into `op_rh_v2_jornadas_programadas` |
| **SM-02** | Materialize Idem | Re-materialize same date/worker/station without force flag. | 200 OK (Idempotent no-op) |
| **SEC-01**| ACL Authorization | Call endpoints with valid JWT lacking `biometricos` scope. | 403 Forbidden |
| **SEC-02**| Station Scope ACL | Attempt assignment creation for unauthorized station ID. | 403 Forbidden |

---

## 8. ARCHITECTURE SUFFICIENCY GATE

- `SERVICE_API_ARCHITECTURE_READY`: **YES**
- All required domain contracts, endpoint definitions, authorization rules, timezone semantics, overlap formulas, versioning rules, and test matrices are fully derived from the frozen Checador V2 architecture without ambiguity or unresolved blockers.
