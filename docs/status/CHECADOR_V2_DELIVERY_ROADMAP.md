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
| **AUTH-4 E2E Validation** | 20 E2E scenarios across API & Lector with physical Live20R fingerprint capture | 2026-10-06 | Evidence Report (`117a6c3d72...`) |
| **API Station Context Alignment** | `GET /acceso-empresa` exposing `id_estacion` & `GET /api/v2/devices/context` | 2026-10-06 | Commit `61e942f55ef5...` |

---

## 2. CURRENT ACTIVE MILESTONE

### Milestone: LECTOR_STATION_ALIGNMENT_ENFORCEMENT (F-03 Lector Side)
- **Goal:** Update Lector operational login flow to call `GET /api/v2/devices/context` upon successful `GET /acceso-empresa` login, verify that `legacy_session.id_estacion == v2_device_context.id_estacion`, and abort V2 capture/sync with a clear configuration alert if stations diverge.
- **Prerequisite Status:** API Side COMPLETE (`61e942f55ef59a22a47bb22ba3cd79dceeac7444`).
- **Target Completion:** Immediate Next Step in Lector Repository.

---

## 3. NEXT PLANNED MILESTONES (AFTER F-03 CLOSURE)

1. **Pre-Production Operational Resilience & Security:**
   - **F-02 Cold-Start Offline Login:** Implement local cached session credentials/fallback for Lector cold-start without connectivity.
   - **F-04 Legacy Login Security:** Remediate `GET /acceso-empresa` credentials in query string to use HTTP POST body.
2. **Shift Scheduling & Motor de Jornada V2 (V2.4+):**
   - Implement `ScheduledShift` assignment models, daily expected window calculation, shift templates, and break segments.
3. **Incidents & Infractions Engine:**
   - Server-side evaluation of tardiness, unexcused absences, missing punches, and extra time against sealed raw events.
4. **Payroll Cutover Gate & Shadow Mode Parity Validation:**
   - Statistical shadow mode comparison between legacy attendance conclusions and V2 Motor results prior to payroll cutover.

---

## 4. PRE-PRODUCTION & DEPLOYMENT GATES

- [ ] Infrastructure TLS certificate verification
- [ ] Database trigger installation on staging/production MySQL
- [ ] Technical device secret provision & rotation procedures
- [ ] Lector automated updater / deployment packaging
- [ ] Full regression execution against production-replica database
- [ ] Final executive code freeze (Target: 2026-12-18)
- [ ] Final production deployment (Target: 2026-12-31)
