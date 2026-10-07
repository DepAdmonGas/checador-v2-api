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
SHIFT_MODEL_SPEC_SHA256: 7fa934b7e12ed7a8cca74e52420de0373494ed7cd47b233635288fe5abea52a6
SHIFT_CONTRACT_SPEC_SHA256: 0a3ebc362471ec952d49d2c8debd504656f7fc842f0701d4b1c8db82d0ffa30f
CURRENT_PHASE: V2_3_SHIFT_SCHEDULING_RUNTIME_IMPLEMENTED
LAST_COMPLETED_ACTIVITY: V2_3_A05_SHIFT_SCHEDULING_V2_RUNTIME_IMPLEMENTATION
CURRENT_ACTIVE_MILESTONE: V2.4-P1_MOTOR_DE_JORNADA_PAIRING
A05_RUNTIME_STATE: COMPLETED
LECTOR_IMPLEMENTATION_STATUS: COMPLETE
LECTOR_ACTIVE_DEVELOPMENT: NO
TARGET_DATE: 2026-12-31
TASK_CONSISTENT_WITH_CURRENT_STATUS: YES / NO
```

If `DOCUMENTATION_CONTEXT_LOADED` is not `YES` or `TASK_CONSISTENT_WITH_CURRENT_STATUS` is not `YES`, **STOP IMMEDIATELY**. No code modifications are authorized.

If `LECTOR_ACTIVE_DEVELOPMENT` is `NO`, the agent MUST NOT modify Lector source code without explicit project-owner authorization to reopen Lector development.

---

## 2. CURRENT PHASE & ARCHITECTURE SUMMARY

- **Current Phase:** V2_3_SHIFT_SCHEDULING_RUNTIME_IMPLEMENTED
- **Target Project Completion Date:** 2026-12-31
- **Architecture Freeze V1.0 Status:** FROZEN (SHA256: `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`)
- **Shift Model Physical Spec V1.0 Status:** FROZEN (SHA256: `7fa934b7e12ed7a8cca74e52420de0373494ed7cd47b233635288fe5abea52a6`)
- **Shift Service & API Contract Spec V1.0 Status:** FROZEN_V1.0 (SHA256: `0a3ebc362471ec952d49d2c8debd504656f7fc842f0701d4b1c8db82d0ffa30f`)

### Operational Architecture Principles:
1. **Lector Autonomy:** Portal3 is NOT required for physical Lector runtime operations. Lector has its own operational login (`GET /acceso-empresa`), maintains its own active station session, and operates independently.
2. **Lector Baseline Status:** Lector implementation is **COMPLETE**, **FINAL/FROZEN**, and **NOT an active development track**. Lector durable queue uses canonical `%AppData%\AdmonGasLector\v2_punch_queue.json` (Atomic JSON File). AUTH-3 is CLOSED and AUTH-4 is CLOSED/VERIFIED.
3. **Authentication Decoupling:**
   - **Legacy REST Endpoints:** Authenticate via station operational session token (`op_rh_localidades_perfil.token`) passed in HTTP `Authorization` header.
   - **V2 Ingestion & L2 Endpoints:** Authenticate via technical device credentials (`X-Device-Id`, `X-Credential-Key`, `Authorization: Bearer <device secret>`).
   - **Administrative Read & Shift Scheduling Endpoints:** Authenticate via Portal3 JWT using module `biometricos` ACL.
4. **Database Write Authority:** The API is the sole write authority for MySQL database storage (`op_rh_v2_marcaciones_crudas`, `op_rh_v2_dispositivos`, `op_rh_v2_dispositivo_identidad_personal`, etc.). The Lector communicates strictly via HTTP API endpoints and requires no direct MySQL connection.
5. **Raw Punch Event Immutability:** Raw events captured in `op_rh_v2_marcaciones_crudas` are append-only facts sealed via HMAC-SHA256 and protected by database triggers. Administrative shift scheduling models dictate expected work intervals for derived journey evaluation without altering raw punches.

---

## 3. REPOSITORY & BRANCH BASELINE

- **Project Path:** `/home/silvinol/Proyectos/portal-api-v.1.0.1`
- **Branch:** `feature/api-level2-server`
- **Current HEAD SHA:** `cf84587c69df441c9f456bd84a7541e21b72a6c1` (API side)
- **Git Local:** `YES`
- **Git Remote Configured:** `NO` (Local commits only; push not performed)
- **Worktree Status:** `MODIFIED_FILES_PENDING_COMMIT`

---

## 4. LAST COMPLETED ACTIVITY

- **Activity ID:** `V2_3_A05_SHIFT_SCHEDULING_V2_RUNTIME_IMPLEMENTATION`
- **Type:** RUNTIME_IMPLEMENTATION
- **Summary:** Implemented PHP domain services (`ShiftTemplateService`, `ShiftAssignmentService`, `ScheduleResolutionService`, `ScheduledShiftMaterializationService`), controller (`ShiftSchedulingV2Controller`), and API routes in `index.php` for V2 Shift Scheduling. Validated via a 30-scenario focused runtime test suite (`tests/v2_shift_scheduling_runtime_tests.php`). Verified template versioning, effective assignment overlap rules per workplace, pure read-only schedule resolution without side-effects, workplace timezone authority, bounded batch materialization, and raw punch immutability.

---

## 5. COMPONENT STATE SUMMARY

| Component | State | Detail |
| :--- | :--- | :--- |
| **API Legacy Login** | OPERATIONAL | Returns `id_estacion` and operational session token. |
| **API V2 Punch Ingestion** | OPERATIONAL | Server-side device auth, identity resolution, HMAC seal, idempotency (201/200/409). |
| **API Level 1 Snapshot Read** | OPERATIONAL | Cursor pagination, durable manifest digest (SHA256), `acceso-temporal` ACL. |
| **API Level 2 Windowing & Status** | OPERATIONAL | Generation registration, sequence gap detection, telemetry, `acceso-temporal` ACL. |
| **API Station Context Alignment (F-03 API)** | AVAILABLE | `GET /acceso-empresa` and `GET /api/v2/devices/context` expose canonical `id_estacion`. |
| **Shift Scheduling V2 Physical Schema (A03)** | IMPLEMENTED | SQL migrations created and validated via 20 schema tests (`op_rh_v2_turnos_plantillas`, `op_rh_v2_turnos_segmentos`, `op_rh_v2_turnos_asignaciones`, `op_rh_v2_jornadas_programadas`). |
| **Shift Scheduling V2 Service & API Contract (A04F)** | FROZEN V1.0 | Formally frozen backend service interfaces, REST API contracts, validation, resolution, and materialization specification (`docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md`). |
| **Shift Scheduling V2 Runtime Services & Endpoints (A05)** | OPERATIONAL | Runtime domain services, controller, REST routes, and 30/30 runtime tests PASS (`tests/v2_shift_scheduling_runtime_tests.php`). |
| **Lector Baseline** | COMPLETE / FROZEN | AUTH-3 CLOSED, AUTH-4 CLOSED/VERIFIED. Lector is NOT active development track. |
| **Portal3 Client Integration** | SUSPENDED | Portal3 is not a runtime consumer required for Lector operation. |
| **AUTH-4 Cross-Project E2E** | CLOSED / VERIFIED | All 20 E2E scenarios validated. Evidence report consolidated. |

---

## 6. OPERATIONAL DEBT & PREPRODUCTION RISKS LEDGER

- **F-01 (Legacy Offline Queue):** DEFERRED / KNOWN LEGACY LIMITATION. Legacy attendance flow lacks local durable queue. Not a blocker for V2 shadow mode.
- **F-02 (Cold-Start Offline Login):** PREPRODUCTION RESILIENCE. Lector cold-start while offline cannot call `GET /acceso-empresa`. Deferred to pre-production resilience review.
- **F-03 (Station Consistency):** KNOWN_PREPRODUCTION_OPERATIONAL_RISK. API side support AVAILABLE. Lector side remediation NOT authorized / DEFERRED_PREPRODUCTION_REVIEW.
- **F-04 (Legacy Login URL Parameters):** PREPRODUCTION SECURITY. Legacy `GET /acceso-empresa` passes user/password in query parameters. Deferred to pre-production security review.

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
- **Shift Model Physical Spec V1.0:** `7fa934b7e12ed7a8cca74e52420de0373494ed7cd47b233635288fe5abea52a6`
- **Shift Service & API Contract Spec V1.0:** `0a3ebc362471ec952d49d2c8debd504656f7fc842f0701d4b1c8db82d0ffa30f`
- **Level 1 Contract:** `65e989f2611661fd47c80844ca427fafc146eece8f37025cab3c0917c78f7c3d`
- **Level 2 Contract:** `7176c45aceb2bb59dba5470cd3b74f729a9bf5e3c797860e1dd752be3fb51a1c`
- **AUTH-4 Evidence Report:** `117a6c3d72d610882c987ad5b030ae1e374714af164d04f5cc7323583f647924`

