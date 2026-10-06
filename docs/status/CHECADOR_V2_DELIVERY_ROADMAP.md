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

---

## 2. CURRENT ACTIVE MILESTONE

### Milestone: PENDING_CANONICAL_RESELECTION
- **Goal:** Reselect the next unfinished development milestone toward the 2026-12-31 target date from the canonical architecture and schedule dependencies.
- **Status:** Lector functional baseline is **COMPLETE & FROZEN**. Lector is **NOT** an active development track.
- **Next Step:** Perform a dedicated milestone reselection audit to confirm the next authorized development slice.

---

## 3. NEXT PLANNED MILESTONES (SCHEDULED FOR 2026-12-31 TARGET)

1. **Shift Scheduling & Motor de Jornada V2 (V2.4+ / Motor):**
   - Implement `ScheduledShift` assignment models, daily expected window calculation, shift templates, and break segments.
2. **Incidents & Infractions Engine:**
   - Server-side evaluation of tardiness, unexcused absences, missing punches, and extra time against sealed raw events.
3. **Payroll Adapter & Shadow Mode Parity Validation:**
   - Statistical shadow mode comparison between legacy attendance conclusions and V2 Motor results prior to payroll cutover.

---

## 4. PRE-PRODUCTION & KNOWN RISKS LEDGER

- **F-01 (Legacy Offline Queue):** DEFERRED / KNOWN LEGACY LIMITATION. Legacy attendance flow lacks local durable queue. Not a blocker for V2 shadow mode.
- **F-02 (Cold-Start Offline Login):** PREPRODUCTION RESILIENCE. Lector cold-start while offline cannot call `GET /acceso-empresa`. Deferred to pre-production resilience review.
- **F-03 (Station Consistency):** KNOWN_PREPRODUCTION_OPERATIONAL_RISK. API side support AVAILABLE (`61e942f55ef59a22a47bb22ba3cd79dceeac7444`). Lector side remediation NOT authorized / DEFERRED_PREPRODUCTION_REVIEW.
- **F-04 (Legacy Login URL Parameters):** PREPRODUCTION SECURITY. Legacy `GET /acceso-empresa` passes user/password in query parameters. Deferred to pre-production security review.
- [ ] Infrastructure TLS certificate verification
- [ ] Database trigger installation on staging/production MySQL
- [ ] Technical device secret provision & rotation procedures
- [ ] Lector automated updater / deployment packaging
- [ ] Full regression execution against production-replica database
- [ ] Final executive code freeze (Target: 2026-12-18)
- [ ] Final production deployment (Target: 2026-12-31)
