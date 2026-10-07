# CHECADOR V2 — V2.5 ADMINISTRATIVE INCIDENCES & ADJUSTMENTS CONTRACT SPECIFICATION

**Document Version:** 1.0 (FROZEN_V1.0)  
**Status:** FROZEN_V1.0  
**Freeze Date:** 2026-10-07  
**Project:** Checador V2 API (`/home/silvinol/Proyectos/portal-api-v.1.0.1`)  
**Target Completion Date:** 2026-12-31  

---

## 1. PURPOSE AND MANDATORY GOVERNANCE

This document establishes the frozen domain specification for **Checador V2 Slice V2.5 — Administrative Incidences & Adjustments**.

V2.5 sits strictly AFTER:
1. `ScheduledShift` → immutable materialized schedule expectation (`op_rh_v2_jornadas_programadas`)
2. `PunchEvent` → immutable raw physical fact (`op_rh_v2_marcaciones_crudas`)
3. `P1 Pairing` → deterministic fact-to-schedule association revision (`op_rh_v2_pareos_jornada`)
4. `P2 Journey Calculation` → immutable technical metrics revision (`op_rh_v2_calculos_jornada`)

### Core Architectural Doctrine:
> **FACTS REMAIN FACTS.**  
> Incidences and administrative adjustments explain, justify, or modify administrative interpretation.  
> They DO NOT rewrite, delete, modify, or synthesize recorded physical reality.

---

## 2. NON-GOALS & MANDATORY BOUNDARIES

1. **NO Raw Punch Mutation:** V2.5 MUST NOT create synthetic raw punches, delete raw punches, alter raw punch timestamps, or modify `op_rh_v2_marcaciones_crudas`.
2. **NO Upstream Entity Mutation:** V2.5 MUST NOT modify `ScheduledShift`, `P1 Pairing` headers/segments, or `P2 Calculation` headers/segments.
3. **NO Payroll Calculation:** V2.5 MUST NOT calculate monetary amounts, deductions, net salary, or payable overtime totals (`PAYROLL_EFFECT_INCLUDED: NO`).
4. **NO Public API Exposure in V2.5:** V2.5 is an internal domain layer. No public REST API endpoints are created or authorized in this phase (`PUBLIC_V2_5_API_REQUIRED: NO`).
5. **Business Terminology:** All project documentation must use `"comparación paralela sin afectar nómina"` instead of "shadow mode".

---

## 3. DOMAIN LAYERING & IMMUTABILITY

```text
Layer 0: Raw Punch Events (op_rh_v2_marcaciones_crudas) [FACTS - Append-Only]
   ↓
Layer 1: Scheduled Shifts (op_rh_v2_jornadas_programadas) [EXPECTATIONS - Immutable]
   ↓
Layer 2 (P1): Journey Pairing (op_rh_v2_pareos_jornada) [ASSOCIATION - Append-Only Revisions]
   ↓
Layer 3 (P2): Journey Technical Calculation (op_rh_v2_calculos_jornada) [METRICS - Append-Only Revisions]
   ↓
Layer 4 (V2.5): Administrative Incidences & Adjustments [INTERPRETATION & JUSTIFICATION]
```

---

## 4. INCIDENCE VS ADMINISTRATIVE ADJUSTMENT

V2.5 distinguishes between two separate domain aggregates:

1. **Attendance Incident (`AttendanceIncident`):**
   - Represents an approved administrative condition or status (e.g., Vacation, Disability, Approved Permission, Official Mission, Authorized Lateness).
   - Can exist as a **master record with a date range** (e.g., Disability covering 2026-10-01 to 2026-10-15) or a single-shift condition.

2. **Administrative Adjustment (`AdministrativeAdjustment`):**
   - Represents an explicit administrative decision override applied to a specific shift calculation (e.g., manual administrative review override of a fail-closed status).
   - Captures previous value, new value, reason, actor, and approval timestamp without altering upstream P2 results.

---

## 5. INCIDENCE MASTER & RANGE SEMANTICS

- **Master Record:** Long-duration conditions (Vacation, Disability) are recorded as **one single master incidence record** containing:
  - `fecha_inicio_local` (inclusive start date, `YYYY-MM-DD`)
  - `fecha_fin_local` (inclusive end date, `YYYY-MM-DD`)
  - `id_personal` (canonical worker ID)
  - `tipo_incidencia` (catalog code)
  - `estado_incidencia` (`ACTIVE`, `CANCELLED`, `SUPERSEDED`)
- **Daily Application Model:** For each scheduled shift falling within the master date range, a daily incidence application evaluation is linked back to the master incidence record ID.
- **Worker Scope:** Range incidences apply worker-wide across the specified date interval.
- **Shift / Workplace Scope:** Shift-specific permissions or late-arrival authorizations are bound explicitly to `id_jornada_programada` and `id_estacion`.

---

## 6. PROVENANCE & REVISION LINKAGE

- **P2 Revision Linkage:** Every administrative calculation result MUST explicitly reference the immutable `id_calculo_jornada` (P2 calculation revision ID) and `id_pareo_jornada` (P1 pairing revision ID) from which it was derived.
- **Append-Only Derivation:** If P2 calculates a new revision (e.g., P2 rev 2 due to late punch arrival), a new administrative evaluation revision (e.g., V2.5 rev 2) is created. Prior administrative revisions remain intact and auditable.

---

## 7. CONTRADICTION & FAIL-CLOSED HANDLING

1. **Punches During Approved Absence (Contradiction):**
   - If raw punches or P2 worked seconds exist during an approved Vacation or Disability, V2.5 MUST NOT hide or erase the punches.
   - V2.5 produces a status of `CONTRADICTION_DETECTED` with `REVIEW_REQUIRED`, retaining both the physical worked metrics and the incidence record for administrative review.
2. **Incomplete Upstream Source (Fail-Closed):**
   - For shifts with P2 status `PENDING_SYNC`, `UNKNOWN_SCHEDULE`, `AMBIGUOUS`, or `WORKPLACE_MISMATCH`, V2.5 MUST NOT emit administrative absence (`FALTA`). The administrative evaluation status remains fail-closed (`DATA_INCOMPLETE` / `PENDING_REVIEW`).

---

## 8. ADMINISTRATIVE ABSENCE (`FALTA`) & `SIN MARCACIÓN` PREREQUISITES

- **V2.5 Emits Administrative FALTA:** `YES` (under strict deterministic prerequisites).
- **Prerequisites for FALTA:**
  1. `tipo_jornada` = `SCHEDULED`.
  2. Level 2 completeness status = `VERIFIED`.
  3. P1 pairing status = `UNMATCHED_NO_PUNCHES`.
  4. P2 calculation status = `UNMATCHED_NO_PUNCHES`.
  5. **NO active or approved incidence** covers the worker and work date.
  6. **NO authorized adjustment** overrides the absence.

If any prerequisite is missing, the shift CANNOT be classified as `FALTA`.

---

## 9. ADMINISTRATIVE TIME ADJUSTMENTS

- Administrative time adjustments (e.g., authorized manual shift time) represent **interpreted administrative values only**.
- They MUST NEVER be inserted into `op_rh_v2_marcaciones_crudas` or masquerade as raw physical punches (`ADMIN_CAN_CREATE_SYNTHETIC_RAW_PUNCH: NO`).

---

## 10. TEST MATRIX SPECIFICATION (I01 – I38)

V2.5 implementation must satisfy a 38-scenario automated test matrix:

- `I01`: No incidence + complete P2 → `NORMAL_COMPLETED`
- `I02`: Full-day vacation + zero punches → `VACATION_APPLIED`
- `I03`: Full-day vacation + punches present → `CONTRADICTION_DETECTED`
- `I04`: Multi-day master incidence applies across date range
- `I05`: Disability range (15 days) recorded as 1 master record
- `I06`: Incidence outside date range ignored
- `I07`: Range boundary start date inclusive check
- `I08`: Range boundary end date inclusive check
- `I09`: Worker mismatch fails matching
- `I10`: Workplace-specific incidence isolation
- `I11`: Worker-wide incidence applies across multiple stations
- `I12`: Shift-specific incidence linkage
- `I13`: Partial shift permission handling
- `I14`: Retroactive incidence creates new admin evaluation revision
- `I15`: Cancelled incidence creates append-only cancellation revision
- `I16`: Corrected incidence preserves audit trail
- `I17`: Overlapping incidences trigger `OVERLAP_CONFLICT`
- `I18`: Zero punches + `VERIFIED` + no incidence → `FALTA`
- `I19`: Zero punches + `INCOMPLETE` L2 → `DATA_INCOMPLETE` (No `FALTA`)
- `I20`: `PENDING_SYNC` → `PENDING_REVIEW` (No `FALTA`)
- `I21`: `AMBIGUOUS` P1 → `REVIEW_REQUIRED` (No `FALTA`)
- `I22`: `WORKPLACE_MISMATCH` → `REVIEW_REQUIRED` (No `FALTA`)
- `I23`: `UNKNOWN_SCHEDULE` → `UNKNOWN_ADMIN_STATE` (No `FALTA`)
- `I24`: `PunchEvent` records remain 100% un-mutated
- `I25`: `ScheduledShift` records remain 100% un-mutated
- `I26`: P1 pairing records remain 100% un-mutated
- `I27`: P2 calculation records remain 100% un-mutated
- `I28`: No synthetic `PunchEvent` created
- `I29`: Administrative interpreted time stored separately from raw punches
- `I30`: Vacation + punch contradiction visible
- `I31`: Disability + punch contradiction visible
- `I32`: Re-run with identical inputs is idempotent
- `I33`: New P2 revision triggers new admin evaluation revision
- `I34`: Algorithm version and policy provenance persisted
- `I35`: Multi-workplace shift isolation enforced
- `I36`: Zero payroll/monetary calculations produced
- `I37`: Zero salary/deduction columns present
- `I38`: Physical seconds precision preserved without rounding

---

## 11. PHYSICAL MODEL REQUIREMENT

V2.5 requires dedicated append-only persistence tables for incidences, adjustments, and daily evaluation results.

Therefore:
- **`V2_5_REQUIRES_NEW_SCHEMA: YES`**
- **`STORAGE_READINESS: V2_5_REQUIRES_PHYSICAL_SCHEMA_DESIGN_FIRST`**

Next milestone: **V2.5-S — Administrative Incidences Physical Schema Design & Conditional Freeze**.
