# CHECADOR V2 — CANONICAL PROJECT STATUS

**Document Status:** CANONICAL
**Last Updated At:** 2026-11-03
**Target Date:** 2026-12-31

---

## 1. MANDATORY CONTEXT GATE FOR ALL FUTURE TASKS

Before EVERY future Checador task (coding, audit, documentation, or planning), the AI/CLI agent MUST read this file, compute the required hashes, and output the following verification gate:

```text
DOCUMENTATION_CONTEXT_LOADED: YES
CURRENT_STATUS_SHA256: <sha256 of docs/status/CHECADOR_V2_CURRENT_STATUS.md>
ACTIVITY_LEDGER_SHA256: 110f7aa0f24d75bec205520e9befdfaeb8338217015d38c928775d2e2602a0d7
DELIVERY_ROADMAP_SHA256: 06e2d6080952dd74235885a4b3b80451ae9ac64e66bebd4906f55dd8668d5955
ARCHITECTURE_FREEZE_SHA256: 87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85
SHIFT_MODEL_SPEC_SHA256: 7fa934b7e12ed7a8cca74e52420de0373494ed7cd47b233635288fe5abea52a6
SHIFT_CONTRACT_SPEC_SHA256: 0a3ebc362471ec952d49d2c8debd504656f7fc842f0701d4b1c8db82d0ffa30f
CURRENT_PHASE: POST_OCT12_STABILIZATION_AND_LEVEL2_READ_CONTINUATION
LAST_COMPLETED_ACTIVITY: CHECADOR_V2_M11_CROSS_APPROVAL_AND_IMPLEMENTATION_V1
CURRENT_ACTIVE_MILESTONE: V2.7_PREPRODUCTION_HARDENING_AND_CUTOVER_READINESS
A05_RUNTIME_STATE: COMPLETED
LECTOR_IMPLEMENTATION_STATUS: COMPLETE
LECTOR_ACTIVE_DEVELOPMENT: NO
TARGET_DATE: 2026-12-31
TASK_CONSISTENT_WITH_CURRENT_STATUS: YES
```

If `DOCUMENTATION_CONTEXT_LOADED` is not `YES` or `TASK_CONSISTENT_WITH_CURRENT_STATUS` is not `YES`, **STOP IMMEDIATELY**. No code modifications are authorized.

If `LECTOR_ACTIVE_DEVELOPMENT` is `NO`, the agent MUST NOT modify Lector source code without explicit project-owner authorization to reopen Lector development.

---

## 2. CURRENT PHASE & ARCHITECTURE SUMMARY

- **Current Phase:** LEVEL1_NON_TELEGRAM_DEVELOPMENT_COMPLETE; next active milestone is V2.7 Pre-production Hardening & Cutover Readiness.
- **Target Project Completion Date:** 2026-12-31
- **Architecture Freeze V1.0 Status:** FROZEN (SHA256: `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`)
- **Shift Model Physical Spec V1.0 Status:** FROZEN (SHA256: `7fa934b7e12ed7a8cca74e52420de0373494ed7cd47b233635288fe5abea52a6`)
- **Shift Service & API Contract Spec V1.0 Status:** FROZEN_V1.0 (SHA256: `0a3ebc362471ec952d49d2c8debd504656f7fc842f0701d4b1c8db82d0ffa30f`)
- **Journey Pairing Contract Spec V1.0 Status:** FROZEN_V1.0 (SHA256: `7cacdc6ca33cc478b0eb65103002784d95d03865f4cf76f99177dcf2cc4a31d5`)
- **Journey Pairing Physical Model V1.0 Status:** FROZEN_V1.0 (SHA256: `b5865e22ce5e2ddfaa505968ec981e735ee82667d58b7ceeec99b81fe4054d6a`)
- **Journey Calculation Contract Spec V1.0 Status:** FROZEN_V1.0 (SHA256: `9e976213010f6787c05a43b5ebc7d6d2da313c104dda9a2d635e69e88f4d1f96`)
- **Journey Calculation Physical Model V1.0 Status:** FROZEN_V1.0 (SHA256: `784d22fb9f2450315a841379190c6906bca9c9e88975daa98587356649af313a`)
- **Journey On-Demand API Contract Spec V1.0 Status:** FROZEN_V1.0 (SHA256: `9eda2b2c7174154d6524f7130ec197678958801df41004925a60928462876c9b`)
- **Journey On-Demand API Contract Spec V1.1 Status:** FROZEN_V1.1 (SHA256: `7f71372d9deceb6820e6e71e1be38dadd12e87cd5bb4a5f69bcd250ee85c6f3f`)
- **Administrative Incidences Contract Spec V1.0 Status:** FROZEN_V1.0 (SHA256: `94f91abf6d0ddeee641c736159b922debbadad5fb60db91da1d26827dfac6b7d`)
- **P3-G Administrative API Boundary Contract Spec V1.0 Status:** FROZEN_V1.0 (SHA256: `e418687501fbdfdf89bcc6f11aab60e49226f265411c4590a39a5fa7799c374a`)
- **P3-H Event Classification Public Payload Contract Spec V1.0 Status:** HISTORICAL_FROZEN_EVIDENCE (SHA256: `c710165953f455352cf5675b7581a581be03a632a493e6a0c939276865d6658f`)
- **P3-H Event Classification Public Payload Contract Spec V1.1 Status:** FROZEN_OWNER_APPROVED (SHA256: `df6b0bec7c6977cb0c12bf5409c8243528af145036474aa3adcf237b21f4711c`)
- **Administrative Incidences Physical Model V1.0 Status:** FROZEN_V1.0 (SHA256: `9bbffb34e736fea1cd9dfb433bb1cc1168051af410f34d041fa4bc8ddf6ec29a`)
- **Administrative Incidences Physical Model V1.1 Addendum Status:** FROZEN_V1.1 (SHA256: `13c624213b9fdd2cad733d802f823e31e8dc287142173f4b80935802dcf670c1`)
- **Parallel Comparison Contract Spec V1.0 Status:** FROZEN_V1.0 (SHA256: `d21a59d79de7fbcca12fe1c43b1b398f9e3bbae9b54e8ef972f0fceacf50bacb`)
- **Parallel Comparison Physical Model V1.0 Status:** FROZEN_V1.0 (SHA256: `cd4e1aee46d9a0f5aa8c5bd175b8ff41aae731e08ed77de144d21e4b013b763f`)
- **Parallel Comparison Physical Model V1.1 Addendum Status:** FROZEN_V1.1 (SHA256: `451179ed195d6e8f7970f8cf5cb01c6a2a432e07b90f245d338e63197cfa6f66`)

---

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
- **Current local HEAD SHA:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **Git Local:** `YES`
- **Git Remote Configured:** `NO` (Local commits only; push not performed)
- **Worktree Status:** Pre-existing modified/untracked files are present; preserved during the 2026-10-09 documentation sync.

---

## 4. LAST COMPLETED ACTIVITY

- **Activity ID:** `CHECADOR_V2_M03_AUTHORIZED_TEMPLATE_BOOTSTRAP_EXECUTION_V1`
- **Type:** DEVELOPMENT_ONLY_SHIFT_TEMPLATE_BOOTSTRAP
- **Summary:** Following explicit owner authorization, amended only §7 of the identical Portal3/API Shift Scheduling Authorization Addendum V1.0 copies (SHA-256 `09ec77ee0ff76279df48f3efb04579cbd15f9f625230de5bc7c99a7884717f40`) to permit a CLI-only initial bootstrap in local development without changing HTTP authorization or normal template-admin permissions. The CLI created 12 unique V2 templates and 12 work segments from 15 valid legacy intervals, excluding VACACIONES and INCAPACIDAD and creating no assignments, rests, memberships, or work dates. Local `bd_admongas` readback verified 12 templates, 12 segments, 0 assignments, and 89/89 raw punch UUIDs. Two idempotency re-runs created zero duplicates. Portal3 authenticated read-only Chrome tests passed for Encargado ID 33 and Dirección ID 19; the catalog and segment times were visible and selectable only in the authorized assignment form. The Architecture Freeze, Lector, schema, ACL, production, and canonical events remain unchanged; work is uncommitted and undeployed.

---

## 5. COMPONENT STATE SUMMARY

| Component | State | Detail |
| :--- | :--- | :--- |
| **API P3-H Event Classification Payload Enrichment** | IMPLEMENTED_VERIFIED (DEVELOPMENT) / CONTRACT FROZEN V1.1 | Additive enrichment of `data.evaluated_raw_events` and provenance mirror with `pairing_role` and `segment_ordinal` on canonical `POST /api/v2/journeys/evaluate`; Contract V1.1 FROZEN_OWNER_APPROVED (SHA256: `df6b0bec7c6977cb0c12bf5409c8243528af145036474aa3adcf237b21f4711c`); zero recomputations, zero schema changes. |
| **API Legacy Login** | OPERATIONAL | Returns `id_estacion` and operational session token. |
| **API V2 Punch Ingestion** | OPERATIONAL | Server-side device auth, identity resolution, HMAC seal, idempotency (201/200/409). |
| **API Level 1 Snapshot Read** | OPERATIONAL (DEVELOPMENT) | Cursor pagination, durable manifest digest (SHA256), `biometricos.leer` + authorized workplace scope ACL (SRA01-SRA16 16/16 PASS; P04/P3-A CLOSED_VERIFIED_DEVELOPMENT). |
| **Workplace Timezone Authority & Read API** | CLOSED_VERIFIED (DEVELOPMENT) | Table `op_rh_v2_localidades_zonas_horarias`, `GET /api/v2/workplaces/{workplace_id}/timezone?date=YYYY-MM-DD`, `biometricos.leer` ACL; 8 development mappings configured (2026-10-12); production NOT_AUTHORIZED. |
| **Level 1 Non-Telegram DEVELOPMENT Schema Closure** | COMPLETE (DEVELOPMENT) | Event UUID `VARCHAR(64)` and raw-reference integrity migrations 000001/000002 applied and verified; production not authorized. |
| **Event UUID Domain / Device Ingestion Validation** | FROZEN V1.0 / COMPLETE FOR CURRENT DEVICE INGESTION | Opaque identifier, max 64 post-trim; focused validation 13/13 PASS; no RFC UUID requirement or case normalization. |
| **API Level 2 Windowing & Status** | OPERATIONAL | Generation registration, sequence gap detection, telemetry, window status evaluation; contract explicitly designates human User JWT (`biometricos.leer`). |
| **API Station Context Alignment (F-03 API)** | AVAILABLE | `GET /acceso-empresa` and `GET /api/v2/devices/context` expose canonical `id_estacion`. |
| **Shift Scheduling V2 Physical Schema (A03)** | IMPLEMENTED | SQL migrations created and validated via 20 schema tests (`op_rh_v2_turnos_plantillas`, `op_rh_v2_turnos_segmentos`, `op_rh_v2_turnos_asignaciones`, `op_rh_v2_jornadas_programadas`). |
| **Shift Scheduling V2 Service & API Contract (A04F)** | FROZEN V1.0 | Formally frozen backend service interfaces, REST API contracts, validation, resolution, and materialization specification (`docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md`). |
| **Shift Scheduling V2 Runtime Services & Endpoints (A05)** | OPERATIONAL (DEVELOPMENT; authorization addendum V1.0 implemented) | Specific schedule read/edit and separate template-admin capabilities; position 6 is scoped to its mapped home station, position 13 reads its authorized active stations. Assignment close/replacement and explicit day-off support implemented. Addendum SHA256: `09ec77ee0ff76279df48f3efb04579cbd15f9f625230de5bc7c99a7884717f40`. The authorized CLI bootstrap created 12 templates/12 segments from approved legacy definitions; readback has 0 assignments and preserves 89/89 raw punch UUIDs. Normal HTTP template administration still requires `checador.plantillas.administrar`; no such grant was added. |
| **PunchEvent / ScheduledShift Pairing Contract (P1D)** | FROZEN V1.0 | Formally frozen deterministic pairing specification (`docs/v2/CHECADOR_V2_JOURNEY_PAIRING_CONTRACT_V1.0.md`). |
| **PunchEvent / ScheduledShift Pairing Physical Model (P1S)** | FROZEN V1.0 | Formally frozen physical database model specification (`docs/architecture/CHECADOR_V2_JOURNEY_PAIRING_PHYSICAL_MODEL_V1.0.md`). |
| **PunchEvent / ScheduledShift Pairing Physical Schema (P1M)** | IMPLEMENTED | SQL migrations created and validated via 25 schema tests (`op_rh_v2_pareos_jornada`, `op_rh_v2_pareos_segmentos`, `op_rh_v2_pareos_marcaciones_asociadas`). |
| **Motor de Jornada P1 Pairing Runtime (P1)** | OPERATIONAL | Domain service `JourneyPairingService` and 16/16 runtime tests PASS (`tests/v2_journey_pairing_runtime_tests.php`). |
| **Journey Calculation Contract (P2D)** | FROZEN V1.0 | Formally frozen attendance calculation specification (`docs/v2/CHECADOR_V2_JOURNEY_CALCULATION_CONTRACT_V1.0.md`). |
| **Journey Calculation Physical Model (P2S)** | FROZEN V1.0 | Formally frozen calculation physical model (`docs/architecture/CHECADOR_V2_JOURNEY_CALCULATION_PHYSICAL_MODEL_V1.0.md`). |
| **Journey Calculation Schema (P2M)** | IMPLEMENTED | SQL migrations created and validated via 30 schema tests (`op_rh_v2_calculos_jornada`, `op_rh_v2_calculos_segmentos`). |
| **Motor de Jornada P2 Calculation Runtime (P2)** | OPERATIONAL | Domain service `JourneyCalculationService` and 14/14 runtime tests PASS (`tests/v2_journey_calculation_runtime_tests.php`). |
| **API Journey On-Demand Evaluation Boundary** | OPERATIONAL (DEVELOPMENT) | `POST /api/v2/journeys/evaluate`, server-to-server Portal3 JWT (`biometricos.leer`), workplace scope enforcement, orchestrating P1 pairing + P2 calculation; JRB01-JRB30 (30/30 PASS). |
| **Administrative Incidences Contract (V2.5-D)** | FROZEN V1.0 | Formally frozen administrative contract (`docs/v2/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_CONTRACT_V1.0.md`). |
| **Administrative Incidences Physical Model (V2.5-S)** | FROZEN V1.0 | Formally frozen administrative physical model (`docs/architecture/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_V1.0.md`). |
| **Administrative Incidences Schema (V2.5-M)** | IMPLEMENTED | SQL migrations 000003 created and validated via 10/10 schema tests. |
| **Administrative Provenance Addendum (V2.5-SA)** | FROZEN V1.1 | Formally frozen addendum (`docs/architecture/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_ADDENDUM_V1.1.md`). |
| **Administrative Provenance Additive Schema (V2.5-MA)** | IMPLEMENTED | SQL migrations 000004 created and validated via 7/7 schema tests. |
| **Administrative Evaluation Runtime (V2.5)** | OPERATIONAL | Domain service `AdministrativeEvaluationService` and 10/10 runtime tests PASS (`tests/v2_administrative_runtime_tests.php`). |
| **P3-G Administrative API Boundary Contract (P3-G API)** | FROZEN V1.0 | Formally frozen server-to-server API boundary contract matching Portal3 companion contract (SHA256: `e418687501fbdfdf89bcc6f11aab60e49226f265411c4590a39a5fa7799c374a`). |
| **P3-G Administrative Read Boundary (P3G-A)** | OPERATIONAL (DEVELOPMENT) | `GET /api/v2/incidences`, `GET /api/v2/incidences/{uuid}`, `GET /api/v2/administrative-evaluations` implemented; P3GA01-P3GA32 (32/32 PASS); P3G-B WRITE BOUNDARY REMINDS BLOCKED. |
| **Parallel Comparison Contract (V2.6)** | FROZEN V1.0 | Formally frozen comparison contract (`docs/v2/CHECADOR_V2_LEGACY_PAYROLL_PARALLEL_COMPARISON_ADAPTER_CONTRACT_V1.0.md`). |
| **Parallel Comparison Physical Model (V2.6-S)** | FROZEN V1.0 | Formally frozen comparison physical data model (`docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md`). |
| **Lector Baseline** | COMPLETE / FROZEN | AUTH-3 CLOSED, AUTH-4 CLOSED/VERIFIED. Lector is NOT active development track. |
| **Portal3 Client Integration** | IN_PROGRESS (SERVER-TO-SERVER READ BOUNDARIES APPROVED_AND_IMPLEMENTED_DEVELOPMENT) | Daily observed PunchEvents consultation (PORTAL3-P03 CLOSED_VERIFIED_DEVELOPMENT); Level 1 Snapshot integration (PORTAL3-P04A / P3-A CLOSED_VERIFIED_DEVELOPMENT); Synthetic fixture (user 999, workplace 1) DEVELOPMENT_ONLY; Production NOT_AUTHORIZED; Portal+Telegram FROZEN_DEFERRED; V2.7 NOT_FULLY_CLOSED. |
| **AUTH-4 Cross-Project E2E** | CLOSED / VERIFIED | All 20 E2E scenarios validated. Evidence report consolidated. |
| **SEC-A Database Fail-Closed** | CLOSED_VERIFIED | Strict environment variable enforcement in `modelo/BD.php`; 12/12 tests PASS (`tests/v2_db_configuration_fail_closed_tests.php`). |
| **SEC-B Acceso-Empresa Transport** | OPEN_TRANSITION | Dual-stack POST (JSON + form-urlencoded) operational; no query fallback (15/15 PASS); 7 remote physical terminals pending migration; legacy GET branch transitional. |
| **SEC-C Production TLS Gate** | OPEN / PENDING PREPROD | Perimeter TLS active on `portal3.admongas.com.mx`; feature branch not deployed; pre-production target NOT_DEFINED. |
| **SEC-D1 Application Method/Payload Guards** | CLOSED_VERIFIED | HTTP 405 with `Allow`, HTTP 415 on invalid media types, schema field length guardrails; 20/20 PASS (`tests/v2_sec_d1_method_content_payload_tests.php`). |
| **SEC-D3 / SEC-D4 Perimeter Rate Limiting** | SPECIFICATION_ONLY | Nginx method-aware mapping specification finalized; runtime NOT_IMPLEMENTED on edge servers; zero numerical thresholds approved. |
| **SEC-D8 Public IP / NAT Discovery** | NOT_REQUIRED | Physical station public IP / NAT gateway discovery is NOT required for normal Checador V2 architecture. Egress IP is dynamic transport metadata only. |
| **Lector HTTP 429 Recovery** | VERIFIED_SAFE_LOCAL_LAB | Local lab verified using durable atomic JSON disk queue (`%AppData%\AdmonGasLector\v2_punch_queue.json`); production/remote fleet unverified. |
| **SEC-E Database Least Privilege** | AUDIT_COMPLETE / SPEC_ONLY | Local runtime uses overprivileged `root@localhost`; least-privilege matrix is specification only; zero GRANT/REVOKE commands executed. |

---

## 6. OPERATIONAL DEBT & PREPRODUCTION RISKS LEDGER

- **F-01 (Legacy Offline Queue):** DEFERRED / KNOWN LEGACY LIMITATION. Legacy attendance flow lacks local durable queue. Not a blocker for V2 comparación paralela sin afectar nómina.
- **F-02 (Cold-Start Offline Login):** PREPRODUCTION RESILIENCE. Lector cold-start while offline cannot call `GET /acceso-empresa`. Deferred to pre-production resilience review.
- **F-03 (Station Consistency):** KNOWN_PREPRODUCTION_OPERATIONAL_RISK. API side support AVAILABLE. Lector side remediation NOT authorized / DEFERRED_PREPRODUCTION_REVIEW.
- **F-04 (Legacy Login URL Parameters):** PREPRODUCTION SECURITY. Legacy `GET /acceso-empresa` passes user/password in query parameters. Controlled via SEC-B transition.
- **SEC-DEBT-01 (Legacy Reversible Encryption):** Legacy user/station passwords use two-way symmetric encryption (`Encriptar::encrypt`) instead of one-way cryptographic hashing (Bcrypt/Argon2id). Risk: MEDIUM; Pre-production required: NO; Protocol remediation: Requires migration design.
- **SEC-DEBT-02 (Plaintext Session Token):** Active operational tokens stored plaintext in `op_rh_localidades_perfil.token`. Risk: MEDIUM; Pre-production required: NO; Protocol remediation: Can be remediated without protocol change.
- **SEC-DEBT-03 (Non-Parameterized Legacy SQL):** Legacy authentication queries in `Acceso.php` concatenate ciphertext variables directly. Risk: LOW (mitigated by ciphertext); Pre-production required: NO; Protocol remediation: Can be remediated without protocol change.
- **SEC-DEBT-04 (Transitional GET Credential Exposure):** Legacy GET branch exposes credentials in query strings/access logs until all 8 physical terminals cut over to POST. Risk: MEDIUM; Pre-production required: YES (via SEC-B completion); Protocol remediation: Remediated once GET branch is disabled.

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

### 8.1. Current-status hash reference reconciliation (2026-11-02)

The Activity Ledger and Delivery Roadmap reference digests in the mandatory gate
were stale. Their current contents are unchanged from API commit
`b567ebb7d4dd90bd5357c8a6b4f16a077f9808c3` (2026-10-23), verified by hashing
both the working files and the committed blobs. The verified SHA256 values are
`5c886f5f31f06de78442163ee1ca8c8b0be6261e62da2c3c32fa0b988d97cb8b` for the
Activity Ledger and
`06e2d6080952dd74235885a4b3b80451ae9ac64e66bebd4906f55dd8668d5955` for the
Delivery Roadmap. No content was changed in either referenced document during
this reconciliation. The stale references were updated here under the explicit
user authorization recorded in the current task; the Architecture Freeze and
the frozen Shift Scheduling specifications were verified unchanged.

- **Architecture Freeze V1.0:** `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`
- **Shift Model Physical Spec V1.0:** `7fa934b7e12ed7a8cca74e52420de0373494ed7cd47b233635288fe5abea52a6`
- **Shift Service & API Contract Spec V1.0:** `0a3ebc362471ec952d49d2c8debd504656f7fc842f0701d4b1c8db82d0ffa30f`
- **Level 1 Contract:** `65e989f2611661fd47c80844ca427fafc146eece8f37025cab3c0917c78f7c3d`
- **Level 2 Contract:** `7176c45aceb2bb59dba5470cd3b74f729a9bf5e3c797860e1dd752be3fb51a1c`
- **AUTH-4 Evidence Report:** `117a6c3d72d610882c987ad5b030ae1e374714af164d04f5cc7323583f647924`

## 9. LEVEL 1 NON-TELEGRAM DEVELOPMENT CLOSURE (2026-10-09)

- **Level 1 non-Telegram DEVELOPMENT slice:** COMPLETE. This is a DEVELOPMENT-only closure; production remains unauthorized.
- **Event UUID contract:** FROZEN V1.0. `event_uuid` is an opaque immutable identifier, 1–64 valid UTF-8 characters after trim; RFC UUID syntax is not required. Stored capitalization and the existing `utf8mb4_unicode_ci` identity semantics are preserved. Exact replay still requires the same stored representation and canonical payload equality.
- **Device-ingestion implementation:** COMPLETE FOR CURRENT DEVICE INGESTION; 13 focused checks PASS / 0 FAIL. Integration regressions PASS for exact retry, divergent idempotency conflict, non-RFC event UUID, case-variant conflict, same UUID on different devices, HMAC sealing, and HMAC fail-closed. No implementation regression was detected.
- **Migration 000001:** `APPLIED + VERIFIED` in DEVELOPMENT. Snapshot `event_uuid` widened from `VARCHAR(36)` to `VARCHAR(64)` while preserving `utf8mb4`, `utf8mb4_unicode_ci`, `NOT NULL`, and the existing index. Raw `event_uuid` was unchanged. Automatic narrowing is prohibited; production is not authorized.
- **Migration 000002:** `CLOSED_VERIFIED` in DEVELOPMENT. Snapshot `id_raw_punch` is signed `INT(11) NOT NULL`, with `idx_snapshot_member_raw_punch(id_raw_punch)`, FK `fk_snapshot_member_raw_punch` to raw `id` with `ON DELETE RESTRICT`, and unique `(id_snapshot,id_raw_punch)`. Existing member-order unique, snapshot FK with `ON DELETE CASCADE`, cursor index, and `event_uuid VARCHAR(64) utf8mb4_unicode_ci NOT NULL` remain intact.
- **Final DEVELOPMENT data evidence:** 0 snapshot members, 0 orphans, 0 duplicate `(id_snapshot,id_raw_punch)` groups, 0 duplicate `(id_snapshot,member_order)` groups; raw table row count 7; no unexpected raw-table schema change. Static migration test: 21 PASS / 0 FAIL. Migration SHA256: `21878ed23d7cd9c94999320d314a71ce29a0ab799d55c24d0733023fd6b1af44`.
- **Portal + Telegram:** architecture `FROZEN`; implementation `FROZEN_DEFERRED`. No Telegram schema/runtime implementation is authorized or reactivated.
- **Next active milestone:** V2.7 — Pre-production Hardening & Cutover Readiness.

## 10. V2.7 SECURITY STATUS RECONCILIATION (2026-10-12)

- **Station / Device Identity:** Authenticated API credentials (`X-Device-Id`, `X-Credential-Key`, Bearer secret / Bcrypt for V2 punch events; credentials for `/acceso-empresa`). Station resolution is internal (`id_estacion`), **never public IP**. Public IP is transport metadata only.
- **SEC-D8 NAT Discovery:** Classified as `NOT_REQUIRED_FOR_NORMAL_ARCHITECTURE`. Removed from V2.7 critical path.
- **Rate-Limiting Numeric Values:** `NOT_APPROVED`. Candidate B (20 req/s, burst 60) for ingest is `MODELING_CANDIDATE_FOR_PREPRODUCTION`. Auth candidate (5 req/s, burst 15) is `NOT_APPROVED`. Ingest body limit 64 KB is `CANDIDATE_NOT_APPROVED` (rationale: single-punch contract margin, protocol growth margin, and payload abuse mitigation; punch batching is not applicable). Production capacity remains `NOT_VERIFIED`.
- **SEC-G Canonical Definition:** `SECURITY_EVIDENCE_AND_DOCUMENTATION_ALIGNMENT` (reconciliation of implemented security controls, verified regression evidence, specifications, lab tests, and pre-production gates; rate limit approval remains an unapproved pre-production requirement).
- **Control Classification:**
  - `IMPLEMENTED_AND_VERIFIED`:
    - SEC-L03: Portal3 JWT supervisor authorization regression (34 / 34 PASS in `tests/sec_l03_authorization_regression.php`).
    - SEC-A: Database fail-closed configuration; elimination of hardcoded fallbacks (12 / 12 PASS in `tests/v2_db_configuration_fail_closed_tests.php`).
    - SEC-B Core: Dual-stack POST `/acceso-empresa` (JSON + form-urlencoded); query fallback rejected (15 / 15 PASS in `tests/v2_sec_b_acceso_empresa_transport_tests.php`).
    - SEC-D1: HTTP 405 with `Allow`, 415 unsupported media types, schema field length boundaries (20 / 20 PASS in `tests/v2_sec_d1_method_content_payload_tests.php`).
    - SEC-F01: HTTP 500 exception message sanitization (0 raw exception reflections; 12 / 12 PASS in `tests/v2_sec_f01_exception_sanitization_tests.php`).
  - `SPECIFICATION_ONLY`:
    - SEC-D3: Perimeter rate-limiting architecture memo.
    - SEC-D4: Nginx method-aware mapping specification.
    - SEC-E: Database least-privilege matrix (`portal_api_app` vs `portal_api_migrator`).
    - SEC-F02: Pre-production PHP error configuration plan (FP201–FP212).
  - `LOCAL_LAB_ONLY`:
    - Lector HTTP 429 recovery via durable atomic JSON disk queue (`%AppData%\AdmonGasLector\v2_punch_queue.json`).
    - Local DB runtime privilege audit (`root@localhost`).
    - Controlled local smoke of Lector POST `/acceso-empresa` (1 local station).
  - `OPEN_TRANSITION`:
    - SEC-B Fleet Migration: 7 remote physical terminals pending migration; transitional GET branch active until rollout completes.
  - `PREPRODUCTION_PENDING`:
    - SEC-C: TLS gate and API pre-production deployment validation (`PREPRODUCTION_TARGET=NOT_DEFINED`).
    - SEC-F02: Runtime verification of `display_errors = Off` on pre-production PHP-FPM host.
    - SEC-F04: Perimeter access log query string redaction verification.
  - `DEFERRED`:
    - HTTP replay protection (`DEFERRED_SECURITY_HARDENING`).

## 11. SHIFT SCHEDULING AUTHORIZATION ADDENDUM AND STATION-MANAGER SLICE (2026-11-02)

- **User authorization:** Explicitly authorized the narrowly scoped Shift Scheduling permission amendment and implementation for local development. No production deployment or Lector work is authorized.
- **Contract addendum:** `docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_AUTHORIZATION_ADDENDUM_V1.0.md`, SHA-256 `c717482d5402a3d41d7fe9d932f39d0722a67bdd6a26bfa65c018ec0162c2253`. The Portal3 companion was verified byte-identical. `CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`, the frozen Shift Scheduling model/service contracts, and the shared Lector baseline remain unchanged.
- **Authorization model:** Dedicated capabilities `checador.programacion.leer`, `checador.programacion.editar`, and separately granted `checador.plantillas.administrar`. Encargado (position 6) receives read/edit only in its own uniquely mapped active station. Dirección (position 13) receives read-only access to its authorized active station scope. No broad `biometricos.editar` grant was added; template administration was not granted to either QA profile.
- **API implementation:** Fresh permission and station-scope resolution, canonical physical-station/locality mapping, membership interval validation on assignment writes, idempotent/optimistic close-and-replace handling, explicit day-off support, and template writes behind the distinct admin grant. Existing Block A filtering remains in force; no Block B cursor/consistency semantics or J2 runtime was added.
- **Portal3 implementation:** Dedicated permission reader and authorization path, schedule-specific routes/actions, CSRF-protected write handlers with API readback, date-aware worker selector and server-side membership revalidation. The Comodines module keeps its original `biometricos.*` gate. A separate template form is available only to a user carrying the specific template-admin grant.
- **Development ACL/schema:** Local `bd_admongas` development migrations `2026_11_02_000001_create_v2_shift_scheduling_permission_grants.sql` and `2026_11_02_000002_allow_explicit_day_off_assignments.sql` applied. Active grants are limited to position 6 read/edit and position 13 read. The operational schedule-write gate remains disabled.
- **Verification:** API authorization 23/23, assignment read-scope 25/25, membership coverage 8/8; Portal M03 capture 16/16, Access Foundation 57/57, UI Quality Gate 36/36; authenticated read-only Chrome E2E 1/1 for Encargado and 1/1 for Dirección, at 1440/1024/768/375. Relevant PHP and JavaScript lint passed. Candidate screenshots are stored in the private E2E artifact directory; visual approval remains pending.
- **Development records at this activity's earlier read-only verification:** Template and assignment tables each contained zero rows before and after the migration/read verification. No template or assignment was written in that earlier activity. No canonical punch, payroll, production, or Lector data changed then.
- **Operational status at that earlier verification:** Station-scoped reads were browser-verified, while real template administration and assignment write/readback were still unverified because the local write gate was off and no RH-confirmed schedule had been provided. The subsequent QA assignment evidence and its non-official status are recorded in Section 12; do not interpret it as an approved labor schedule.
- **Next prerequisites for official RH scheduling:** Obtain RH-approved shift definition and effective dates, then use the separately authorized template-administration process if template changes are needed. The QA persistence path is now verified once; any further QA or official assignments require their own explicit scope and authorization.

## 12. FIRST REAL QA ASSIGNMENT THROUGH PORTAL3 (2026-11-02)

- **Status:** `CLOSED_VERIFIED_DEVELOPMENT_QA`; this proves the authorized QA persistence path, not operational RH scheduling or a payroll/Journey outcome.
- **Assignment:** Encargado ID 33, Interlomas locality 1, QA worker 1004, active template 3/version 1 (`08:00–14:00`), Tuesday 2026-11-03, validity `[2026-11-03, 2026-11-04)`. No retroactive row was created.
- **API response contract:** The API now returns actual persisted `creado_at` and `registrado_at_utc` for creation and exact replay. Portal3 accepts success only on API HTTP 201 with valid audit timestamps plus successful API readback. The `Idempotency-Key` header is sent by Portal3 but is not consumed by the API; duplicate protection is the separate exact-match/row-lock path.
- **Persistence / readback:** MariaDB `bd_admongas` contains exactly one assignment (ID 1) with `creado_at=2026-11-02 13:44:53` and `registrado_at_utc=2026-11-02 19:44:53`. API readback matched. Authenticated Chrome showed `Legacy Interlomas — BANDERERO DE APOYO 1 — 08:00–14:00` after save and after a fresh reload. Portal3's API-readback duplicate guard prevented a second row; cross-station workplace 2 tampering was rejected before API write.
- **Profiles / scope:** Encargado retained schedule read/edit only in Interlomas and no template-admin grant. Dirección retained read-only station supervision. Development global POST gate was off, schedule-specific gate on; no other-module POST was sent.
- **Protected data:** No Journey/materialized snapshot, payroll, PIN, incidence, template, membership, or other-locality write occurred. Original raw punches remain 89 unique UUIDs; SHA-256 of the UUID set remains `91384da9f49fd3632188f10811eb17ab644e5293ca76be23d857aef40207d343`. No production or Lector change.
- **Validation and limitations:** Portal regression 24/24; API read-scope 25/25, scheduling authorization 23/23, source-only response contract 6/6. UI gate 36 PASS / 0 FAIL / 1 BLOCKED; authenticated Chrome separately passed for both profiles. The DB-backed API runtime suite was not run without an isolated DB. The generic schedule-write static authorization suite remains 18 PASS / 6 FAIL on older broad-permission expectations and was not changed. Human visual approval is pending.
- **History:** Section 11's zero-assignment/write-gate-off statements describe the state before this activity. Architecture Freeze, frozen model/service contracts, Delivery Roadmap, and shared implementation status remain unchanged.

## 13. M03 OPERATIONAL COMPLETION REVIEW (2026-11-02)

- **Status:** `PARTIALLY_VERIFIED_LOCAL`; the single development QA assignment in Section 12 remains preserved and verified. This review does not certify official staffing or close/replacement runtime.
- **Authorization regression:** The six former static failures were obsolete expectations predating the approved Shift Scheduling Authorization Addendum. They expected broad `biometricos.editar` gates and a single authorization helper on routes that now intentionally use dedicated schedule, template-admin, and legacy materialization capabilities. The test was changed to assert those exact route policies; result 31/31 PASS. No ACL or runtime authorization code changed.
- **Assignment Idempotency-Key:** The frozen Shift Scheduling API Contract V1.0 documents optional `X-Idempotency-Key` for template creation only, not `POST /shift-assignments`. Assignment exact-payload replay/overlap protection remains as implemented, but key reuse/conflict requires a contract decision defining scope, retention, replay semantics, and durable storage. No schema or contract change was made. Do not claim assignment header idempotency.
- **Worker readiness:** Verified date-aware membership logic and server revalidation remain in place. Known verified memberships are for the QA cohort 1004–1008; they do not establish current membership or shift decisions for operative staff. No RH-authoritative assignments or schedule details were provided; no worker schedules were created.
- **Close/replacement:** Endpoints and authorization paths exist, but DB lifecycle validation is pending an isolated test database. The current runtime suite drops/recreates Shift Scheduling and Journey tables; it was not run against `bd_admongas`.
- **Validation:** Authorization static 31/31, role/scope 23/23, read scope 25/25, membership 8/8, Portal M03 capture 24/24; PHP lint and diff check PASS. Prior real Chrome evidence for Encargado create/readback and Dirección read-only remains applicable because no Portal runtime/UI changes were made. Human visual approval remains pending.
- **Data / governance:** No database writes, production changes, contract edits, ACL changes, or changes to the 89 original punch UUIDs in this review. The QA assignment remains explicitly non-official. Architecture Freeze and Delivery Roadmap remain unchanged. `LAST_COMPLETED_ACTIVITY` remains the Section 12 QA assignment; this review is not a full M03 closeout.
