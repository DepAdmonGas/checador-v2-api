# CHECADOR V2 — JOURNEY CALCULATION CONTRACT (P2)

## 1. CONTROL HEADER

- **Contract Name:** CHECADOR V2 — JOURNEY CALCULATION CONTRACT
- **Document Version:** 1.0 (FROZEN_V1.0)
- **Status:** FROZEN_V1.0
- **Effective Scope:** Technical Motor de Jornada V2 — Phase 2 (Attendance Calculation & Work Metrics)
- **Target Execution Date:** 2026-12-31
- **Authoritative Architecture:** `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`
- **Shift Model Physical Spec:** `docs/architecture/CHECADOR_V2_SHIFT_SCHEDULING_PHYSICAL_MODEL_V1.0.md`
- **Service API Contract Reference:** `docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md`
- **Authoritative Pairing Contract:** `docs/v2/CHECADOR_V2_JOURNEY_PAIRING_CONTRACT_V1.0.md`
- **Pairing Physical Storage Reference:** `docs/architecture/CHECADOR_V2_JOURNEY_PAIRING_PHYSICAL_MODEL_V1.0.md`

---

## 2. PURPOSE & TECHNICAL SCOPE BOUNDARY

### 2.1 Primary Objective
This contract specifies the exact, deterministic domain calculation rules for Phase 2 (P2) Journey Calculation in Checador V2.
P2 consumes immutable materialized scheduled worker shifts (`op_rh_v2_jornadas_programadas`) and immutable persisted Phase 1 pairing revisions (`op_rh_v2_pareos_jornada`, `op_rh_v2_pareos_segmentos`, `op_rh_v2_pareos_marcaciones_asociadas`) to derive technical attendance metrics, duration calculations, and arrival/departure deltas.

### 2.2 Explicit Scope Exclusions & Hard Invariants (NON-GOALS)
Phase 2 Journey Calculation explicitly **MUST NOT**:
- Mutate, delete, or alter raw punch events (`op_rh_v2_marcaciones_crudas`).
- Mutate, delete, or alter scheduled shifts (`op_rh_v2_jornadas_programadas`).
- Mutate or alter historical pairing records (`op_rh_v2_pareos_jornada` et al.).
- Synthesize or invent missing biometric timestamps.
- Emit administrative `FALTA` (absence) conclusions.
- Emit administrative `SIN MARCACIÓN` conclusions beyond reporting structural pairing completeness.
- Apply administrative incidences (vacations, medical leave, permissions, authorized lateness).
- Approve payable overtime or evaluate monetary payroll consequences.
- Write, modify, or affect payroll tables (`op_rh_personal_asistencia`, legacy payroll, etc.).
- Rewrite historical facts.

### 2.3 Official Business Wording
All comparative evaluation against legacy outputs or secondary review MUST be documented as:
> **“comparación paralela sin afectar nómina”**

The term “shadow mode” MUST NOT be used in project or business documentation.

---

## 3. CORE ARCHITECTURAL DOCTRINES & DECOUPLING

### 3.1 Derived Calculation Layer
Phase 2 operates strictly as a deterministic derivation layer over immutable inputs:
$$\text{P2 Output} = f(\text{ScheduledShift}, \text{PairingRevision}, \text{WorkplaceTimezone}, \text{CalculationVersion})$$

### 3.2 Categorical Distinction of Results
P2 maintains strict separation across three logical tiers:
1. **FACTUAL_DERIVED_METRIC:** Pure elapsed durations and signed timestamp deltas (e.g., `actual_worked_seconds`, `arrival_delta_seconds`). Authorized in P2.
2. **ADMINISTRATIVE_CLASSIFICATION:** HR policy evaluations (e.g., `RETARDO_JUSTIFICADO`, `FALTA_INJUSTIFICADA`, `PERMISO`). **EXCLUDED from P2**.
3. **PAYROLL_EFFECT:** Financial calculations (e.g., overtime multipliers, absence deductions). **EXCLUDED from P2**.

### 3.3 Explicit Input Provenance & Versioning
Every P2 calculation MUST explicitly record:
- `id_jornada_programada`
- `id_pareo_jornada` (the exact immutable P1 pairing revision consumed)
- `version_algoritmo_calculo` (e.g., `v2.4-p2`)
- `fingerprint_entrada` (SHA256 hash of immutable schedule + pairing inputs)

P2 calculation MUST NOT consume "latest pairing" without recording the exact revision ID.

---

## 4. PERSISTENCE & STORAGE STRATEGY

### 4.1 Persistence Decision
- **P2 Result Persistence Mode:** **PERSISTED (Append-Only Revisions)**
- **Auditability Rationale:** To ensure historical auditability, reproducible comparison, and fast query performance for administrative reporting, P2 calculation outputs MUST be persisted in an append-only revision structure (`op_rh_v2_calculos_jornada`).
- **Physical Schema Status:** P2 requires a dedicated physical database schema design (`V2.4-P2S` / `V2.4-P2M`) before runtime calculation implementation (`V2.4-P2`).

### 4.2 Calculation Revision Model
- If a new P1 pairing revision is generated (e.g., due to late punch arrival or Level 2 sync completion), a new append-only P2 calculation revision is generated with `revision_numero = revision_numero + 1`.
- Previous P2 calculation revisions remain 100% immutable and queryable for historical audit.

---

## 5. TECHNICAL METRICS & WORKED TIME DEFINITION

### 5.1 Segment-Level Technical Worked Duration
For a complete paired `WORK` segment where:
- `actual_entry` = timestamp of `ENTRY` punch
- `actual_exit` = timestamp of `EXIT` punch

The candidate segment worked duration is:
$$\text{segment\_worked\_seconds} = \text{actual\_exit}_{\text{utc}} - \text{actual\_entry}_{\text{utc}}$$

If $\text{actual\_exit}_{\text{utc}} < \text{actual\_entry}_{\text{utc}}$, the interval is invalid; P2 MUST fail closed with status `INVALID_INTERVAL`.

### 5.2 Multi-Segment Aggregation
For multi-segment split shifts (e.g., 08:00–12:00 and 14:00–18:00):
$$\text{actual\_worked\_seconds} = \sum_{k=1}^{N} \text{segment\_worked\_seconds}_k$$
Unworked gaps between segments (e.g., 12:00–14:00) MUST NOT be included in worked duration.

### 5.3 Scheduled Work Duration
$$\text{scheduled\_work\_seconds} = \sum_{k=1}^{N} (\text{scheduled\_end}_{\text{utc}, k} - \text{scheduled\_start}_{\text{utc}, k})$$
Derived strictly from materialized `op_rh_v2_jornadas_programadas` `WORK` segments.

---

## 6. DELTA CALCULATION & POLICIES

### 6.1 Arrival Delta (`arrival_delta_seconds`)
For segment 1 (or primary entry):
$$\text{arrival\_delta\_seconds} = \text{actual\_entry}_{\text{utc}} - \text{scheduled\_start}_{\text{utc}}$$
- **Sign Convention:**
  - Positive ($+N$): Worker arrived $N$ seconds AFTER scheduled start.
  - Negative ($-N$): Worker arrived $N$ seconds BEFORE scheduled start.
- **Administrative Boundary:** This signed delta is a neutral factual metric. P2 DOES NOT evaluate tolerance or emit administrative `RETARDO`.

### 6.2 Departure Delta (`departure_delta_seconds`)
For the final segment (or primary exit):
$$\text{departure\_delta\_seconds} = \text{actual\_exit}_{\text{utc}} - \text{scheduled\_end}_{\text{utc}}$$
- **Sign Convention:**
  - Positive ($+N$): Worker departed $N$ seconds AFTER scheduled end.
  - Negative ($-N$): Worker departed $N$ seconds BEFORE scheduled end.
- **Administrative Boundary:** Negative delta is NOT automatically equated with disciplinary early departure. Positive delta is NOT automatically approved overtime.

### 6.3 Outside-Schedule Minutes & Overtime Semantic Boundary
- **`outside_schedule_candidate_seconds`**: Sum of elapsed time worked prior to `scheduled_start` and after `scheduled_end`.
- **`overtime_candidate_seconds`**: Net excess work seconds beyond `scheduled_work_seconds` when total actual worked seconds exceeds scheduled work seconds.
- **Hard Invariants:**
  - `P2_APPROVES_OVERTIME`: **NO**
  - `P2_CALCULATES_PAYABLE_OVERTIME`: **NO**
Overtime approval belongs strictly to downstream HR authorization modules (V2.5+).

---

## 7. DOMAIN CALCULATION STATES & EDGE CASE HANDLING

### 7.1 Calculation State Enum (`estado_calculo`)
1. **`CALCULATED`**: Complete shift pairing (`EXACT_PAIR` across all segments); metrics derived cleanly.
2. **`PARTIAL_DATA`**: P1 status is `PARTIAL_MISSING_IN` or `PARTIAL_MISSING_OUT`. Worked duration for incomplete segments is `NULL` (uncalculable).
3. **`PENDING_SYNC`**: P1 status is `PENDING_SYNC_COMPLETION`. Calculations marked non-final until station sync completes.
4. **`AMBIGUOUS`**: P1 status is `AMBIGUOUS_PAIRING`. Fails closed; duration and deltas are `NULL`.
5. **`WORKPLACE_MISMATCH`**: P1 status is `WORKPLACE_MISMATCH`. Fails closed against normative schedule; evidence preserved.
6. **`UNMATCHED_NO_PUNCHES`**: P1 status is `UNMATCHED_NO_PUNCHES`. Fails closed; no worked duration derived. Does NOT emit `FALTA`.
7. **`DAY_OFF_RECORDED`**: Shift type is `DAY_OFF`. No scheduled minutes. If punches exist, actual interval evidence is preserved without normative schedule comparison.
8. **`UNSCHEDULED_RECORDED`**: Shift type is `UNSCHEDULED`. Actual worked intervals derived if complete pairs exist, but schedule deltas are `NULL`.
9. **`UNKNOWN_SCHEDULE`**: Shift type is `UNKNOWN`. Fails closed; all metrics `NULL`.

### 7.2 Partial Pairing Behavior
When a segment is missing an entry or exit punch (`PARTIAL_MISSING_IN`, `PARTIAL_MISSING_OUT`), P2 MUST NOT invent a timestamp (e.g., assuming scheduled time). The segment worked duration MUST remain `NULL` and `estado_calculo` set to `PARTIAL_DATA`.

### 7.3 Night Shifts Crossing Midnight
Duration calculations MUST use exact UTC timestamp differences ($\text{end}_{\text{utc}} - \text{start}_{\text{utc}}$). Workday attribution remains pinned to `op_rh_v2_jornadas_programadas.fecha_jornada_local`. Naïve string math on local `HH:MM` is strictly prohibited.

---

## 8. PRECISION & ROUNDING POLICY

- **Duration Precision:** Derived metrics MUST be computed and stored at **seconds precision** (`INT`).
- **Rounding Policy:** **`NONE`**. Technical duration MUST NOT be rounded to 5, 10, or 15-minute intervals in P2. Business rounding rules belong strictly to downstream payroll adapters.

---

## 9. PUBLIC API & AUTHORIZATION BOUNDARY

- **`PUBLIC_P2_API_REQUIRED`:** **NO**.
- Phase 2 calculation logic is an internal domain service consumed by background processes and internal API aggregation endpoints. No public external endpoints or new ACL permissions are introduced in P2.

---

## 10. TEST MATRIX FOR P2 RUNTIME

| Test ID | Scenario Description | Expected `estado_calculo` | Key Derived Metrics |
| :--- | :--- | :--- | :--- |
| `C01` | Exact 1-segment shift (08:00-16:00, punches 08:00, 16:00) | `CALCULATED` | `worked_seconds`: 28800, `arrival_delta`: 0, `departure_delta`: 0 |
| `C02` | Early arrival (07:55 entry, 16:00 exit) | `CALCULATED` | `arrival_delta`: -300 |
| `C03` | Late arrival (08:10 entry, 16:00 exit) | `CALCULATED` | `arrival_delta`: +600 |
| `C04` | Early departure (08:00 entry, 15:45 exit) | `CALCULATED` | `departure_delta`: -900 |
| `C05` | Late exit (08:00 entry, 16:30 exit) | `CALCULATED` | `departure_delta`: +1800, `overtime_candidate_seconds`: 1800 |
| `C06` | Multi-segment split shift (08-12, 14-18) | `CALCULATED` | `worked_seconds`: 28800 (14400 + 14400) |
| `C07` | Night shift crossing midnight (22:00-06:00) | `CALCULATED` | UTC duration: 28800 seconds |
| `C08` | Missing entry punch (`PARTIAL_MISSING_IN`) | `PARTIAL_DATA` | `worked_seconds`: NULL |
| `C09` | Missing exit punch (`PARTIAL_MISSING_OUT`) | `PARTIAL_DATA` | `worked_seconds`: NULL |
| `C10` | Zero punches with Level 2 VERIFIED | `UNMATCHED_NO_PUNCHES` | `worked_seconds`: NULL, No FALTA |
| `C11` | Zero punches with Level 2 PENDING | `PENDING_SYNC` | Non-final calculation state |
| `C12` | Ambiguous pairing status | `AMBIGUOUS` | Fail-closed, metrics NULL |
| `C13` | Workplace mismatch | `WORKPLACE_MISMATCH` | Fail-closed against schedule |
| `C14` | Day Off without punches | `DAY_OFF_RECORDED` | `scheduled_seconds`: 0, No FALTA |
| `C15` | Day Off with punches | `DAY_OFF_RECORDED` | Worked interval evidence recorded |
| `C16` | Invalid negative interval (exit < entry) | `INVALID_INTERVAL` | Fail-closed error state |
| `C17` | Append-only revision sequence | `CALCULATED` | Revision 2 generated, Revision 1 intact |
| `C18` | Invariance Check | — | 0 writes to punch, shift, or legacy tables |

---

## 11. PREREQUISITES & NEXT MILESTONE GATE

- **Storage Readiness:** **`P2_REQUIRES_PHYSICAL_SCHEMA_DESIGN_FIRST`**
- Because P2 outputs require audit persistence and no physical schema yet exists for `op_rh_v2_calculos_jornada`, the next authorized milestone MUST be physical database schema design (`V2.4-P2S` / `V2.4-P2M`).
