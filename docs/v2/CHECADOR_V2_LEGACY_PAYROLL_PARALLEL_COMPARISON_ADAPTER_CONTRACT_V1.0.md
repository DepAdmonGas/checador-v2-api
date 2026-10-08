# CHECADOR V2 — LEGACY & PAYROLL PARALLEL COMPARISON ADAPTER CONTRACT V1.0

**Status:** FROZEN_V1.0
**Version:** 1.0
**Date:** 2026-10-07
**Module:** Checador V2 API — Parallel Comparison Engine (`V2.6`)
**Canonical Milestone:** `V2.6 — Legacy & Payroll Parallel Comparison Adapter`
**Canonical Identifier:** `V2.6_LEGACY_AND_PAYROLL_PARALLEL_COMPARISON_ADAPTER`
**Canonical Business Terminology:** `comparación paralela sin afectar nómina`
**Implementation Authorized:** NO

---

## 1. SOURCE OF TRUTH & ARCHITECTURAL FOUNDATION

This specification governs the contract and domain boundary for **Checador V2.6 Legacy & Payroll Parallel Comparison Adapter**.

It derives directly from:
1. `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md` (SHA256: `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`), specifically Sections 2, 41, 42, 43, and 44.
2. `docs/v2/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_CONTRACT_V1.0.md` (SHA256: `94f91abf6d0ddeee641c736159b922debbadad5fb60db91da1d26827dfac6b7d`).
3. `docs/architecture/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_V1.0.md` (SHA256: `9bbffb34e736fea1cd9dfb433bb1cc1168051af410f34d041fa4bc8ddf6ec29a`).
4. `docs/architecture/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_ADDENDUM_V1.1.md` (SHA256: `13c624213b9fdd2cad733d802f823e31e8dc287142173f4b80935802dcf670c1`).

---

## 2. STRICT BUSINESS & PAYROLL SAFETY BOUNDARIES

1. **Non-Mutation of Payroll (`comparación paralela sin afectar nómina`):**
   - V2.6 operates strictly as a parallel evaluation and discrepancy detection engine.
   - V2.6 MUST NOT write, modify, or update legacy payroll tables or monetary fields.
   - V2.6 MUST NOT calculate monetary wages, salary rates, deductions, or monetary compensation amounts.
   - V2.6 MUST NOT automatically approve overtime pay or monetary payments.
2. **Upstream V2 Immutability:**
   - V2.6 MUST NOT modify raw `PunchEvent` records (`op_rh_v2_marcaciones_crudas`).
   - V2.6 MUST NOT modify `ScheduledShift` records (`op_rh_v2_jornadas_programadas`).
   - V2.6 MUST NOT modify P1 pairing revisions (`op_rh_v2_pareos_jornada`).
   - V2.6 MUST NOT modify P2 calculation revisions (`op_rh_v2_calculos_jornada`).
   - V2.6 MUST NOT modify V2.5 administrative evaluation revisions (`op_rh_v2_evaluaciones_administrativas`).
3. **Legacy Source Immutability:**
   - Legacy tables (`op_rh_personal_asistencia`, `op_rh_personal_asistencia_incidencia`) are strictly READ-ONLY reference sources for parallel comparison.
   - V2.6 MUST NOT alter or rewrite legacy rows to force artificial agreement with V2.

---

## 3. PRIMARY INPUT BOUNDARIES

### A. Primary V2 Input:
- **`op_rh_v2_evaluaciones_administrativas` (V2.5 Administrative Evaluation Revision):**
  - The direct, authoritative V2 input consumed by V2.6 is an explicit, immutable V2.5 administrative evaluation revision ID (`id_evaluacion_administrativa`).
  - Through `id_evaluacion_administrativa`, V2.6 deterministically inherits its associated `id_jornada_programada`, `id_calculo_jornada` (P2 revision), P1 pairing revision, exact incidence application link set, exact administrative adjustment revision link set, and policy/algorithm provenance.

### B. Legacy Reference Source:
- **Legacy Attendance Tables (`op_rh_personal_asistencia` & `op_rh_personal_asistencia_incidencia`):**
  - V2.6 reads the legacy attendance record for the same worker (`id_personal`) and local workplace date (`fecha_jornada_local`).
  - If a legacy attendance row does not exist for the worker/date, V2.6 flags the reference as `MISSING_LEGACY_RECORD`.

---

## 4. COMPARISON UNITS & CATEGORIZATION MODEL

V2.6 compares V2.5 administrative outcomes against legacy attendance conclusions across the following technical dimensions:

1. **Presence & Absence Classification:**
   - `FALTA` (V2.5 administrative absence) vs Legacy Attendance Status.
2. **Incidence & Justification Parity:**
   - V2.5 Administrative Incidences (`VACATION`, `DISABILITY`, `PERMISSION`, etc.) vs Legacy Incidences (`op_rh_personal_asistencia_incidencia`).
3. **Punctuality & Arrival/Departure Deltas:**
   - V2.5 interpreted entry/exit times vs Legacy entry/exit times.
4. **Physical Evidence Contradiction:**
   - V2.5 `CONTRADICTION_DETECTED` status vs Legacy override conclusions.

### Canonical Discrepancy Classifications:
When comparing V2.5 against Legacy, V2.6 yields one of the following deterministic classifications:

- `MATCH`: V2.5 administrative evaluation and legacy record reach identical attendance conclusions.
- `PUNCH_DIFFERENCE`: Entry/exit timestamps differ beyond acceptable technical threshold (e.g. > 60 seconds).
- `SHIFT_DIFFERENCE`: V2.5 ScheduledShift template/interval differs from legacy schedule definition.
- `INCIDENT_DIFFERENCE`: V2.5 incidence application differs from legacy incidence classification.
- `OVERTIME_DIFFERENCE`: Technical worked duration calculation differs from legacy worked time aggregation.
- `LEGACY_LIMITATION`: Discrepancy caused by known legacy structural limitation (e.g., lack of multi-segment support, cross-midnight shift truncation, or effective dating absence).
- `REVIEW_REQUIRED`: V2.5 evaluation requires human review (`CONTRADICTION_DETECTED` or `OVERLAP_CONFLICT`).
- `MISSING_LEGACY_RECORD`: No legacy attendance record exists for the given worker and local workplace date.
- `NOT_COMPARABLE`: Inputs are incomplete or invalid for direct comparison.

---

## 5. EXACT PROVENANCE & HISTORICAL TRACEABILITY

1. **Explicit Revision Linkage:**
   - Every parallel comparison result MUST explicitly reference:
     - `id_evaluacion_administrativa` (Immutable V2.5 Administrative Evaluation Revision FK).
     - `id_personal_asistencia_legacy` (Nullable Legacy Reference Record ID).
2. **SHA256 Comparison Execution Fingerprint:**
   - Each comparison execution generates a deterministic SHA256 fingerprint computed as:
     `hash('sha256', "{$idEvaluacionAdmin}_{$idLegacyAsistencia}_{$algorithmVersion}_{$policyVersion}")`
   - Re-evaluating the identical inputs produces an identical fingerprint and returns the existing comparison result idempotently (`is_existing = true`).
3. **Append-Only Comparison Revisioning:**
   - Any new V2.5 evaluation revision ($N+1$) or legacy record update produces a new append-only comparison revision. Historical comparison revisions are never updated or deleted in-place.

---

## 6. FAIL-CLOSED COMPARISON SEMANTICS

V2.6 enforces fail-closed handling for non-standard or incomplete states:
- **`PENDING_SYNC` (Level 2 Incomplete):** If Level 2 ingestion is unverified, comparison outcome is `NOT_COMPARABLE` with reason `LEVEL2_INCOMPLETE`.
- **`AMBIGUOUS` (P1 Pairing Ambiguous):** Comparison outcome is `NOT_COMPARABLE` with reason `PAIRING_AMBIGUOUS`.
- **`WORKPLACE_MISMATCH`:** If worker station alignment differs from scheduled workplace, comparison is flagged as `REVIEW_REQUIRED`.
- **`DAY_OFF` & `UNSCHEDULED`:** Compared strictly against legacy rest-day / non-scheduled records without ever emitting synthetic `FALTA`.

---

## 7. SYSTEM & COMPONENT BOUNDARIES

1. **Database Schema Requirement:**
   - V2.6 requires append-only persistence to record historical comparison revisions and discrepancy logs.
   - **`NEW_SCHEMA_REQUIRED: YES`**: A physical data model design (`CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md`) MUST be created and frozen prior to SQL migration implementation.
2. **REST API Boundary:**
   - `PUBLIC_V2_6_API_REQUIRED: NO` (V2.6 operates as an internal domain engine; no public REST endpoints are authorized for this slice).
3. **Lector & Portal3 Boundary:**
   - `LECTOR_CHANGE_REQUIRED_BY_CONTRACT: NO`.
   - `PORTAL3_CHANGE_REQUIRED_BY_CONTRACT: NO`.

---

## 8. SUMMARY OF CONTRACT DECISIONS

```text
PURPOSE: Compare V2.5 Administrative Evaluation results against legacy attendance records in parallel without affecting payroll.
V2_5_PRIMARY_INPUT: op_rh_v2_evaluaciones_administrativas (id_evaluacion_administrativa)
LEGACY_REFERENCE_SOURCE: op_rh_personal_asistencia & op_rh_personal_asistencia_incidencia
LEGACY_SOURCE_READ_ONLY: YES
PAYROLL_WRITE_ALLOWED: NO
MONETARY_CALCULATION_ALLOWED: NO
COMPARISON_OUTPUT_MODEL: PERSISTED_APPEND_ONLY_REVISIONS
HISTORICAL_TRACEABILITY_MODEL: Explicit V2.5 Evaluation FK + Legacy Asistencia FK + SHA256 Fingerprint
NEW_SCHEMA_REQUIRED: YES
PUBLIC_V2_6_API_REQUIRED: NO
LECTOR_CHANGE_REQUIRED_BY_CONTRACT: NO
PORTAL3_CHANGE_REQUIRED_BY_CONTRACT: NO
PROHIBITED_SHADOW_TERMINOLOGY_PRESENT: NO
V2_6_CONTRACT_READY: YES
```
