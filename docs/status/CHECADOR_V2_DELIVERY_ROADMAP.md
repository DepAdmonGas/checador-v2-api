# CHECADOR V2 — DELIVERY ROADMAP & MILESTONE TRACKER

**Document Status:** CANONICAL
**Last Updated At:** 2026-10-06
**Target Completion Date:** 2026-12-31

---

## 1. COMPLETED MILESTONES

| Milestone | Target Scope | Completion Date | Key Evidence / Commit |
| :--- | :--- | :--- | :--- |
| **V2.0 Architecture Freeze** | Freeze multi-workplace domain, PunchEvent raw model, identity resolution authority | 2026-09-28 | Freeze V1.0 (`87bf3e714f73...`) |
| **V2.1 API Raw Ingestion** | Device Auth, Bearer + Bcrypt, Idempotency 201/200, historical identity resolution | 2026-10-01 | 18 / 18 Tests PASS (`tests/run_v2_tests.php`) |
| **V2.2 Lector Durable Queue** | SQLite local queue, retry/backoff, offline-first capture, ACK confirmation | 2026-10-02 | 22 / 22 Tests PASS |
| **V2.3 Punch Integrity & Read API** | HMAC-SHA256 seal, MySQL trigger append-only hardening, `GET /punch-events` | 2026-10-05 | Commit `78076de01b33...` |
| **V2 Level 2 API Capabilities** | Device generations V1/V2, sequence gap tracking, telemetry, window status evaluation | 2026-10-05 | Commit `553f56afc926...` |
| **AUTH-3 Lector Level 2 Queue & Telemetry** | Lector L2 generation persistence, gap recovery, telemetry reporting | 2026-10-05 | AUTH-3 Handoff (`a351f852d00...`) |
| **AUTH-4 E2E Cross-Project Validation** | 20 E2E scenarios across API & Lector with physical Live20R fingerprint capture | 2026-10-06 | Evidence Report (`117a6c3d72...`) |
| **Lector Baseline (AUTH-1 to AUTH-4)** | Complete Lector acquisition, queueing, sync, telemetry, and L2 integration | 2026-10-06 | CLOSED / VERIFIED / FROZEN |
| **API Station Context Alignment (F-03 API)** | `GET /acceso-empresa` exposing `id_estacion` & `GET /api/v2/devices/context` | 2026-10-06 | Commit `61e942f55ef5...` |
| **V2.3-A01 Legacy Schedule Audit** | Read-only audit of `op_rh_personal_horario` vs Freeze V1.0 Section 14 requirements | 2026-10-06 | A01 Audit Findings |
| **V2.3-A02 Shift Model Physical Design** | Physical DDL specification created in `docs/architecture/CHECADOR_V2_SHIFT_SCHEDULING_PHYSICAL_MODEL_V1.0.md` | 2026-10-06 | Spec V1.0 (`docs/architecture/CHECADOR_V2_...`) |
| **V2.3-A02F Physical Model Freeze Review** | Pre-freeze review & revision for multi-workplace scoping, versioning, and auditability | 2026-10-06 | Revised Spec V1.0 |
| **V2.3-A02G Shift Model Final Freeze** | Formal freeze of physical specification `CHECADOR_V2_SHIFT_SCHEDULING_PHYSICAL_MODEL_V1.0.md` | 2026-10-06 | FROZEN V1.0 (`7fa934b7e12e...`) |
| **V2.3-A03 Physical Model Implementation** | Create SQL migration files & 20-scenario schema test suite for V2 Shift Scheduling tables | 2026-10-06 | 20 / 20 Schema Tests PASS |
| **V2.3-A04 Shift Service & API Design** | Design domain services, REST API specification, validation, resolution & materialization | 2026-10-06 | Contract Spec V1.0 (`docs/v2/CHECADOR_V2_...`) |
| **V2.3-A04F Service & API Contract Freeze** | Pre-freeze validation & formal freeze of `CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md` | 2026-10-06 | FROZEN V1.0 (`0a3ebc362471...`) |
| **V2.3-A05 Shift Scheduling V2 Runtime** | Implement PHP domain services, REST routes, controller & 30/30 runtime test suite | 2026-10-06 | 30 / 30 Runtime Tests PASS |
| **V2.4-P1D Journey Pairing Contract** | Design & freeze `CHECADOR_V2_JOURNEY_PAIRING_CONTRACT_V1.0.md` (FROZEN_V1.0) | 2026-10-06 | FROZEN V1.0 (`7cacdc6ca33c...`) |
| **V2.4-P1S Journey Pairing Physical Model** | Design & freeze `CHECADOR_V2_JOURNEY_PAIRING_PHYSICAL_MODEL_V1.0.md` (FROZEN_V1.0) | 2026-10-07 | FROZEN V1.0 (`b5865e22ce5e...`) |
| **V2.4-P1M Journey Pairing Schema Implementation** | Create SQL migration files & 25-scenario schema test suite for V2 Journey Pairing tables | 2026-10-07 | 25 / 25 Schema Tests PASS |
| **V2.4-P1 Motor de Jornada P1 Pairing Runtime** | Implement `JourneyPairingService` domain service & 16-scenario runtime test suite | 2026-10-07 | 16 / 16 Runtime Tests PASS |
| **V2.4-P2D Journey Calculation Contract** | Design & freeze `CHECADOR_V2_JOURNEY_CALCULATION_CONTRACT_V1.0.md` (FROZEN_V1.0) | 2026-10-07 | FROZEN V1.0 (`9e976213010f...`) |
| **V2.4-P2S Journey Calculation Physical Model** | Design & freeze `CHECADOR_V2_JOURNEY_CALCULATION_PHYSICAL_MODEL_V1.0.md` (FROZEN_V1.0) | 2026-10-07 | FROZEN V1.0 (`784d22fb9f24...`) |
| **V2.4-P2M Journey Calculation Schema Implementation** | Create SQL migration files & 30-scenario schema test suite for V2 Journey Calculation tables | 2026-10-07 | 30 / 30 Schema Tests PASS |
| **V2.4-P2 Motor de Jornada Calculation Runtime** | Implement `JourneyCalculationService` domain service & 14-scenario runtime test suite | 2026-10-07 | 14 / 14 Runtime Tests PASS |
| **V2.5-D Administrative Incidences Contract** | Design & freeze `CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_CONTRACT_V1.0.md` (FROZEN_V1.0) | 2026-10-07 | FROZEN V1.0 (`94f91abf6d0d...`) |

---

## 2. CURRENT ACTIVE MILESTONE

### Milestone: V2.5-S_ADMINISTRATIVE_INCIDENCES_PHYSICAL_SCHEMA_DESIGN
- **Goal:** Design and freeze physical data persistence model for Checador V2.5 Administrative Incidences & Adjustments.
- **Prerequisite Status:** V2.5-D Contract Spec FROZEN_V1.0.
- **Target Completion:** 2026-10-07




---

## 3. NEXT PLANNED MILESTONES (SCHEDULED FOR 2026-12-31 TARGET)

1. **V2.4-P1 — Motor de Jornada (PunchEvent / ScheduledShift Pairing):**
   - Implement deterministic pairing algorithm associating raw `PunchEvent` records with `ScheduledShift` expected work intervals.
2. **V2.4-P2 — Motor de Jornada (Attendance, Overtime & Night Shifts):**
   - Calculate worked hours, night-shift premiums, tardiness, and overtime in Shadow Mode.
3. **V2.5 — Administrative Incidences & Adjustments:**
   - Immutable justification/incidence log explaining derived journey anomalies without modifying raw facts.
4. **V2.6 — Payroll Adapter & Shadow Mode Parity Validation:**
   - Statistical shadow mode comparison between legacy attendance conclusions and V2 Motor results prior to payroll cutover.

---

## 4. PRE-PRODUCTION & KNOWN RISKS LEDGER

- **F-01 (Legacy Offline Queue):** DEFERRED / KNOWN LEGACY LIMITATION. Legacy attendance flow lacks local durable queue. Not a blocker for V2 shadow mode.
- **F-02 (Cold-Start Offline Login):** PREPRODUCTION RESILIENCE. Lector cold-start while offline cannot call `GET /acceso-empresa`. Deferred to pre-production resilience review.
- **F-03 (Station Consistency):** KNOWN_PREPRODUCTION_OPERATIONAL_RISK. API side support AVAILABLE (`61e942f55ef5...`). Lector side remediation NOT authorized / DEFERRED_PREPRODUCTION_REVIEW.
- **F-04 (Legacy Login URL Parameters):** PREPRODUCTION SECURITY. Legacy `GET /acceso-empresa` passes user/password in query parameters. Deferred to pre-production security review.
- [ ] Infrastructure TLS certificate verification
- [ ] Database trigger installation on staging/production MySQL

