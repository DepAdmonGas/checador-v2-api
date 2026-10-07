# CHECADOR V2 — PUNCHEVENT / SCHEDULEDSHIFT PAIRING CONTRACT

## 1. CONTROL HEADER

- **Contract Name:** CHECADOR V2 — PUNCHEVENT / SCHEDULEDSHIFT PAIRING CONTRACT
- **Document Version:** 1.0 (FROZEN_V1.0)
- **Status:** FROZEN_V1.0
- **Effective Scope:** Technical Motor de Jornada V2 — Phase 1 (Determinism & Pairing)
- **Target Execution Date:** 2026-12-31
- **Authoritative Architecture:** `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`
- **Physical Model Reference:** `docs/architecture/CHECADOR_V2_SHIFT_SCHEDULING_PHYSICAL_MODEL_V1.0.md`
- **Service API Contract Reference:** `docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md`

---

## 2. PURPOSE & TECHNICAL SCOPE BOUNDARY

### 2.1 Primary Objective
This contract specifies the exact, deterministic domain rules for pairing immutable raw biometric punch events (`v2_punch_events`) with materialized scheduled worker shifts (`v2_scheduled_shifts` and `v2_scheduled_shift_segments`).

Phase 1 (P1) pairing creates a clean, deterministic mapping between physical events and expected work windows without interpreting business or payroll outcomes.

### 2.2 Scope Exclusions (Explicit non-goals for Phase 1)
Phase 1 pairing **MUST NOT**:
- Calculate lateness, early departures, or overtime.
- Calculate total worked hours or payroll deductions.
- Assign attendance incidences (e.g., `FALTA`, `JUSTIFICANTE`, `PERMISO`).
- Mutate, rewrite, or normalize raw `v2_punch_events`.
- Alter `v2_scheduled_shifts` or `v2_scheduled_shift_segments`.
- Perform legacy database updates on `op_rh_personal_asistencia`.

---

## 3. CORE ARCHITECTURAL DOCTRINES

### 3.1 Raw Punch Immutability
`v2_punch_events` represent original, unalterable physical facts ingested from biometric devices or stations. Pairing logic reads punch events strictly in a read-only manner.

### 3.2 Materialized ScheduledShift Primacy
Scheduled shifts are materialized into `v2_scheduled_shifts` and `v2_scheduled_shift_segments` prior to pairing execution. Pairing operates exclusively against materialized shifts for a specified `work_date`.

### 3.3 Fail-Closed Ambiguity Resolution
When punch events cannot be unambiguously mapped to a specific shift segment (e.g., overlapping windows across multiple shifts, missing workplace alignment, or contradictory punch sequences), the engine MUST assign a fail-closed status (`AMBIGUOUS_PAIRING` or `WORKPLACE_MISMATCH`) rather than making heuristics-based guesses.

### 3.4 Idempotency & Re-run Determinism
Given an identical set of `v2_punch_events` and `v2_scheduled_shifts`, running the pairing algorithm $N$ times MUST yield 100% identical pairing results.

---

## 4. INPUT DATA SPECS & PRE-CONDITIONS

### 4.1 Input Entities
1. **`v2_punch_events`**:
   - `id`: Unique punch event ID.
   - `worker_id`: Resolved worker identifier.
   - `device_id`: Originating device ID.
   - `event_type`: Physical button/event type (e.g., `CHECK_IN`, `CHECK_OUT`, `AUTO`).
   - `event_timestamp`: Canonical UTC timestamp.
   - `timezone`: Local timezone of the device/station.
2. **`v2_scheduled_shifts` & `v2_scheduled_shift_segments`**:
   - `id`: Scheduled shift ID.
   - `worker_id`: Assigned worker identifier.
   - `estacion_id`: Primary station/workplace ID.
   - `work_date`: Logical shift date (YYYY-MM-DD).
   - `start_timestamp` & `end_timestamp`: Expected shift boundary timestamps.
   - `segment_sequence`: Order sequence for multi-segment shifts.

### 4.2 Candidate Time Window Construction
For a given `v2_scheduled_shifts` segment with bounds $[S_{\text{start}}, S_{\text{end}}]$, the candidate search window $W$ is defined as:
$$W = [S_{\text{start}} - \Delta_{\text{pre}}, S_{\text{end}} + \Delta_{\text{post}}]$$
where $\Delta_{\text{pre}}$ (default: 4 hours) and $\Delta_{\text{post}}$ (default: 4 hours) define the maximum pre-shift entry and post-shift exit tolerance buffers.

---

## 5. PAIRING ALGORITHM SPECIFICATION

### 5.1 Worker Identity & Workplace Alignment
1. `worker_id` on `v2_punch_events` MUST exactly match `worker_id` on `v2_scheduled_shifts`.
2. If `v2_scheduled_shifts.estacion_id` is specified, the device station (`v2_devices.estacion_id`) MUST align with the expected workplace, unless cross-station floating permissions apply. Unaligned workplace punches are flagged `WORKPLACE_MISMATCH`.

### 5.2 Night Shifts & Midnight Crossing Resolution
- Night shifts (e.g., shift starting at `2026-10-06 22:00:00` and ending at `2026-10-07 06:00:00`) belong strictly to the `work_date` of the shift start (`2026-10-06`).
- Punches occurring after midnight UTC/local (e.g., `2026-10-07 06:05:00`) whose timestamps fall within window $W$ of the `2026-10-06` night shift are deterministically paired to that night shift, NOT to `2026-10-07`.

### 5.3 Multi-Segment Split Shift Allocation
- For shifts with multiple segments (e.g., Morning: 08:00-12:00, Afternoon: 14:00-18:00), each segment $k$ has its own target bounds $[S_{k,\text{start}}, S_{k,\text{end}}]$.
- Candidate punches in window $W_k$ are assigned to segment $k$ sequentially based on chronological order.

### 5.4 Punch Slot Classification & Pair Selection
For each shift segment:
1. **Entry Punch (`entry_punch_event_id`)**: The earliest valid candidate punch in $[S_{\text{start}} - \Delta_{\text{pre}}, S_{\text{start}} + \Delta_{\text{mid}}]$.
2. **Exit Punch (`exit_punch_event_id`)**: The latest valid candidate punch in $[S_{\text{end}} - \Delta_{\text{mid}}, S_{\text{end}} + \Delta_{\text{post}}]$.
3. **Surplus Punches**: Any additional punches occurring between Entry and Exit within the segment window are marked as `SURPLUS_PUNCH` and associated with the pairing record without breaking the primary Entry/Exit slot.

---

## 6. PAIRING RESULT DATA CONTRACT

The output of the pairing algorithm is represented by the canonical `v2_journey_pairing_result` structure:

```json
{
  "pairing_id": "018f3e2b-7c1a-7000-8000-000000000001",
  "scheduled_shift_id": 1001,
  "segment_id": 2001,
  "worker_id": 450,
  "work_date": "2026-10-06",
  "segment_sequence": 1,
  "entry_punch_event_id": 85001,
  "exit_punch_event_id": 85099,
  "surplus_punch_event_ids": [85045],
  "pairing_status": "EXACT_PAIR",
  "algorithm_version": "v2.4-p1",
  "paired_at": "2026-10-06T20:00:00Z"
}
```

### 6.1 Status Enum Definitions
- `EXACT_PAIR`: Both Entry and Exit punches successfully paired to the segment.
- `PARTIAL_MISSING_IN`: Exit punch paired, but Entry punch is missing.
- `PARTIAL_MISSING_OUT`: Entry punch paired, but Exit punch is missing.
- `UNMATCHED_NO_PUNCHES`: Scheduled segment has no matching candidate punches in window $W$.
- `AMBIGUOUS_PAIRING`: Multiple overlapping candidate shifts compete for the same punch events without clear resolution.
- `WORKPLACE_MISMATCH`: Punch occurred at an unauthorized station for the scheduled shift.

---

## 7. LEVEL 2 COMPLETENESS INTEGRATION

Pairing execution checks Level 2 ingestion completeness for the target station and date range:
- If station events for the target `work_date` are pending sync (`sync_status != 'COMPLETED'`), pairing sets `pairing_status = 'PENDING_SYNC_COMPLETION'`.
- Re-running pairing after sync completion transitions the record to its final deterministic state.

---

## 8. TEST MATRIX & ACCEPTANCE CRITERIA

| Test ID | Scenario | Expected Pairing Status |
| :--- | :--- | :--- |
| `TC-P1-01` | Single segment standard day shift with clean IN/OUT | `EXACT_PAIR` |
| `TC-P1-02` | Night shift spanning across midnight boundary | `EXACT_PAIR` assigned to start `work_date` |
| `TC-P1-03` | Multi-segment split shift with distinct IN/OUT per segment | 2x `EXACT_PAIR` records |
| `TC-P1-04` | Single IN punch with no OUT punch | `PARTIAL_MISSING_OUT` |
| `TC-P1-05` | Single OUT punch with no IN punch | `PARTIAL_MISSING_IN` |
| `TC-P1-06` | Multiple rapid button presses (debounce surplus) | `EXACT_PAIR` with `surplus_punch_event_ids` populated |
| `TC-P1-07` | Station/Workplace mismatch | `WORKPLACE_MISMATCH` |
| `TC-P1-08` | Re-execution on identical data | 100% identical `pairing_id` and mappings (Idempotent) |

---

## 9. FORMAL FREEZE DECLARATION

This contract is formally **FROZEN** as Version 1.0. Any future modifications require a formal revision proposal and explicit user approval.

- **Freeze Date:** 2026-10-06
- **Status:** `FROZEN_V1.0`
