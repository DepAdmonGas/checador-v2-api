# CHECADOR V2 — CANONICAL PROJECT STATUS

**Document Status:** CANONICAL
**Last Updated At:** 2026-10-06
**Target Date:** 2026-12-31

---

## 1. MANDATORY CONTEXT GATE FOR ALL FUTURE TASKS

Before EVERY future Checador task (coding, audit, documentation, or planning), the AI/CLI agent MUST read this file, compute the required hashes, and output the following verification gate:

```text
DOCUMENTATION_CONTEXT_LOADED: YES
CURRENT_STATUS_SHA256: <sha256 of docs/status/CHECADOR_V2_CURRENT_STATUS.md>
ACTIVITY_LEDGER_SHA256: <sha256 of docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md>
DELIVERY_ROADMAP_SHA256: <sha256 of docs/status/CHECADOR_V2_DELIVERY_ROADMAP.md>
ARCHITECTURE_FREEZE_SHA256: 87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85
CURRENT_PHASE: V2_CROSS_PROJECT_ALIGNMENT_AND_REMEDIATION
LAST_COMPLETED_ACTIVITY: CHECADOR_V2_API_STATION_ALIGNMENT_REMEDIATION
CURRENT_ACTIVE_MILESTONE: LECTOR_STATION_ALIGNMENT_ENFORCEMENT
NEXT_RECOMMENDED_ACTIVITY: Wait for Lector F-03 enforcement result
TARGET_DATE: 2026-12-31
TASK_CONSISTENT_WITH_CURRENT_STATUS: YES / NO
```

If `DOCUMENTATION_CONTEXT_LOADED` is not `YES` or `TASK_CONSISTENT_WITH_CURRENT_STATUS` is not `YES`, **STOP IMMEDIATELY**. No code modifications are authorized.

---

## 2. CURRENT PHASE & ARCHITECTURE SUMMARY

- **Current Phase:** V2 Cross-Project Station Alignment & Operational Governance
- **Target Project Completion Date:** 2026-12-31
- **Architecture Freeze V1.0 Status:** FROZEN (SHA256: `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`)

### Operational Architecture Principles:
1. **Lector Autonomy:** Portal3 is NOT required for physical Lector runtime operations. Lector has its own operational login (`GET /acceso-empresa`), maintains its own active station session, and operates independently.
2. **Authentication Decoupling:**
   - **Legacy REST Endpoints:** Authenticate via station operational session token (`op_rh_localidades_perfil.token`) passed in HTTP `Authorization` header.
   - **V2 Ingestion & L2 Endpoints:** Authenticate via technical device credentials (`X-Device-Id`, `X-Credential-Key`, `Authorization: Bearer <device secret>`).
3. **Database Write Authority:** The API is the sole write authority for MySQL database storage (`op_rh_v2_marcaciones_crudas`, `op_rh_v2_dispositivos`, `op_rh_v2_dispositivo_identidad_personal`, etc.). The Lector communicates strictly via HTTP API endpoints and requires no direct MySQL connection.
4. **Raw Punch Event Immutability:** Raw events captured in `op_rh_v2_marcaciones_crudas` are append-only facts sealed via HMAC-SHA256 and protected by database triggers. Administrative interpretations do not alter original raw punches.

---

## 3. REPOSITORY & BRANCH BASELINE

- **Project Path:** `/home/silvinol/Proyectos/portal-api-v.1.0.1`
- **Branch:** `feature/api-level2-server`
- **Current HEAD SHA:** `61e942f55ef59a22a47bb22ba3cd79dceeac7444`
- **Git Local:** `YES`
- **Git Remote Configured:** `NO` (Local commits only; push not performed)
- **Worktree Status:** `CLEAN`

---

## 4. LAST COMPLETED ACTIVITY

- **Activity ID:** `CHECADOR_V2_API_STATION_ALIGNMENT_REMEDIATION`
- **Commit SHA:** `61e942f55ef59a22a47bb22ba3cd79dceeac7444`
- **Message:** `fix(checador): expose canonical station context for lector`
- **Summary:** Exposed server-authoritative `id_estacion` in legacy login (`GET /acceso-empresa` -> `resultado.id_estacion`) and created technical device context endpoint (`GET /api/v2/devices/context` -> `id_estacion`) using device credentials. This provides the data required for Lector to enforce station equality between legacy operational sessions and V2 technical device profiles.

---

## 5. COMPONENT STATE SUMMARY

| Component | State | Detail |
| :--- | :--- | :--- |
| **API Legacy Login** | OPERATIONAL | Returns `id_estacion` and operational session token. |
| **API V2 Punch Ingestion** | OPERATIONAL | Server-side device auth, identity resolution, HMAC seal, idempotency (201/200/409). |
| **API Level 1 Snapshot Read** | OPERATIONAL | Cursor pagination, durable manifest digest (SHA256). |
| **API Level 2 Windowing & Status** | OPERATIONAL | Generation registration, sequence gap detection, telemetry, window status evaluation. |
| **API Station Context Alignment (F-03 API)** | CLOSED | `GET /acceso-empresa` and `GET /api/v2/devices/context` expose canonical `id_estacion`. |
| **Lector Station Alignment (F-03 Lector)** | PENDING | Lector must verify `legacy_session.id_estacion == v2_device_context.id_estacion` before V2 capture. |
| **Portal3 Client Integration** | SUSPENDED | Portal3 is not a runtime consumer required for Lector operation. |
| **AUTH-4 Cross-Project E2E** | CLOSED / VERIFIED | All 20 E2E scenarios validated. Evidence report consolidated. |

---

## 6. OPERATIONAL DEBT & FINDINGS LEDGER

- **F-01 (Legacy Offline Queue):** DEFERRED. Legacy attendance flow lacks local durable queue. Not a blocker for V2 shadow mode.
- **F-02 (Cold-Start Offline Login):** PREPRODUCTION RESILIENCE. Lector cold-start while offline cannot call `GET /acceso-empresa`. To be addressed in pre-production resilience slice.
- **F-03 (Station Consistency):** API Side CLOSED (`61e942f55ef59a22a47bb22ba3cd79dceeac7444`). Lector side PENDING enforcement.
- **F-04 (Legacy Login URL Parameters):** PREPRODUCTION SECURITY. Legacy `GET /acceso-empresa` passes user/password in query parameters. To be remediated prior to production cutover.

---

## 7. DEFINITION OF DONE FOR FUTURE ACTIVITIES

A task is considered **FULLY CLOSED** only when all of the following criteria are met:
1. Targeted code changes pass syntax linting and all unit/regression test suites.
2. No frozen governance documents or contracts are altered without explicit prior authorization.
3. `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md` is appended with the activity execution entry.
4. `docs/status/CHECADOR_V2_CURRENT_STATUS.md` is updated to reflect new HEAD, status, and milestone progression.
5. If milestone sequence or dates change, `docs/status/CHECADOR_V2_DELIVERY_ROADMAP.md` is updated.

---

## 8. CANONICAL HASH REFERENCE

- **Architecture Freeze V1.0:** `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`
- **Level 1 Contract:** `65e989f2611661fd47c80844ca427fafc146eece8f37025cab3c0917c78f7c3d`
- **Level 2 Contract:** `7176c45aceb2bb59dba5470cd3b74f729a9bf5e3c797860e1dd752be3fb51a1c`
- **AUTH-4 Evidence Report:** `117a6c3d72d610882c987ad5b030ae1e374714af164d04f5cc7323583f647924`
