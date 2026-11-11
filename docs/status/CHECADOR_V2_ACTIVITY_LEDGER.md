# CHECADOR V2 — ACTIVITY LEDGER

**Document Status:** CANONICAL APPEND-ONLY LEDGER
**Last Updated At:** 2026-10-07
**Project:** Checador V2 API (`/home/silvinol/Proyectos/portal-api-v.1.0.1`)

---

## RECONSTRUCTED ACTIVITY HISTORY

### Entry 001
- **DATE:** 2026-09-28
- **ACTIVITY_ID:** `CHECADOR_V2_ARCHITECTURE_FREEZE`
- **PROJECT:** API & Lector
- **TYPE:** ARCHITECTURE_GOVERNANCE
- **STATUS:** CLOSED
- **BASE_HEAD:** UNKNOWN
- **FINAL_HEAD:** UNKNOWN
- **COMMIT:** UNKNOWN
- **ARTIFACTS:** `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md` (SHA256: `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`)
- **TEST_RESULTS:** N/A
- **DECISIONS:** Established 4-layer separation (Hecho -> Interpretación -> Ajuste -> Nómina), multi-workplace abstraction, offline-first durable queue requirement, server-side identity resolution authority.
- **BLOCKERS:** NONE
- **NEXT_STEP:** API V2.1 Raw Ingestion

---

### Entry 002
- **DATE:** 2026-10-01
- **ACTIVITY_ID:** `API_V2_1_RAW_PUNCH_INGESTION`
- **PROJECT:** Checador API
- **TYPE:** FEATURE_IMPLEMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** UNKNOWN
- **FINAL_HEAD:** UNKNOWN
- **COMMIT:** UNKNOWN
- **ARTIFACTS:** `modelo/v2/IngestaPunchV2Controller.php`, `modelo/v2/DispositivoV2.php`, `modelo/v2/PunchEventV2.php`
- **TEST_RESULTS:** 18 / 18 PASS (`tests/run_v2_tests.php`)
- **DECISIONS:** Implemented Bearer secret + Bcrypt device auth, `(id_dispositivo, event_uuid)` idempotency (201 ACCEPTED / 200 ALREADY_RECEIVED), historical `DeviceWorkerIdentity` resolution.
- **BLOCKERS:** NONE
- **NEXT_STEP:** API V2.3 Raw Punch Integrity

---

### Entry 003
- **DATE:** 2026-10-05
- **ACTIVITY_ID:** `API_V2_3_RAW_PUNCH_INTEGRITY_AND_READ_API`
- **PROJECT:** Checador API
- **TYPE:** FEATURE_IMPLEMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `4bd95e7d5ef0a5dbff4eb7f3e82717906d2dd0a5`
- **FINAL_HEAD:** `78076de01b33fa39fb3eecac15aeeaf42c13cbef`
- **COMMIT:** `78076de01b33fa39fb3eecac15aeeaf42c13cbef`
- **ARTIFACTS:** `modelo/v2/PunchEventIntegrityService.php`, `migraciones/v2/2026_10_05_000001_add_append_only_raw_punch_triggers.sql`, `modelo/v2/PunchEventReadV2Controller.php`
- **TEST_RESULTS:** 16/16 Integrity PASS, 11/11 Append-Only PASS, 11/11 Read API PASS
- **DECISIONS:** Enforced server-side HMAC-SHA256 integrity sealing, MySQL triggers for append-only immutability, `GET /api/v2/punch-events` read API with `biometricos.leer` ACL and dynamic seal verification.
- **BLOCKERS:** NONE
- **NEXT_STEP:** Level 2 Ingestion Completeness

---

### Entry 004
- **DATE:** 2026-10-05
- **ACTIVITY_ID:** `API_LEVEL2_SERVER_PREREQUISITES`
- **PROJECT:** Checador API
- **TYPE:** FEATURE_IMPLEMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `cd4154f`
- **FINAL_HEAD:** `553f56afc926d12a82202ce813c0589fa8a71798`
- **COMMIT:** `553f56afc926d12a82202ce813c0589fa8a71798`
- **ARTIFACTS:** `modelo/v2/Level2ServerController.php`, `modelo/v2/Level2ServerService.php`, `migraciones/v2/2026_10_05_000002_create_v2_level2_server_tables.sql`
- **TEST_RESULTS:** 16 / 16 PASS (`tests/v2_level2_server_api_tests.php`)
- **DECISIONS:** Implemented device generation V1/V2 registration, sequence gap detection (`accepted_through`), operational telemetry ingestion, and window status evaluation.
- **BLOCKERS:** NONE
- **NEXT_STEP:** AUTH-4 E2E Cross-Project Validation

---

### Entry 005
- **DATE:** 2026-10-06
- **ACTIVITY_ID:** `AUTH_4B_CROSS_PROJECT_LEVEL2_E2E_VALIDATION`
- **PROJECT:** API & Lector
- **TYPE:** E2E_VALIDATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `a351f852d00f9193837296901b53d51dc7c8da33`
- **FINAL_HEAD:** `a351f852d00f9193837296901b53d51dc7c8da33`
- **COMMIT:** `a351f852d00f9193837296901b53d51dc7c8da33`
- **ARTIFACTS:** `docs/v2/AUTH4_E2E_VALIDATION_REPORT.md` (SHA256: `117a6c3d72d610882c987ad5b030ae1e374714af164d04f5cc7323583f647924`)
- **TEST_RESULTS:** 20 / 20 E2E Scenarios PASS (E01-E20)
- **DECISIONS:** Validated physical Live20R fingerprint capture, Windows restart queue recovery (`v2_punch_queue.json`), SSH tunnel disconnect recovery, gap filling, and Level 2 VERIFIED status.
- **BLOCKERS:** NONE
- **NEXT_STEP:** Documentation Consolidation

---

### Entry 006
- **DATE:** 2026-10-06
- **ACTIVITY_ID:** `DOC_1A_DOCUMENTATION_CONSOLIDATION`
- **PROJECT:** Checador API
- **TYPE:** DOCUMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `a351f852d00f9193837296901b53d51dc7c8da33`
- **FINAL_HEAD:** `1de70d42d4f6c0a758fc8c9aba8188d77d100d07`
- **COMMIT:** `1de70d42d4f6c0a758fc8c9aba8188d77d100d07`
- **ARTIFACTS:** `docs/README.md`, `docs/manifests/CANONICAL_DOCUMENTATION_MANIFEST_V1.0.md`
- **TEST_RESULTS:** N/A
- **DECISIONS:** Consolidated API documentation inventory, classified all documents into FROZEN, CANONICAL, HANDOFF, EVIDENCE, and HISTORICAL tiers.
- **BLOCKERS:** NONE
- **NEXT_STEP:** Station Consistency Audit

---

### Entry 007
- **DATE:** 2026-10-06
- **ACTIVITY_ID:** `CHECADOR_V2_STATION_CONSISTENCY_AUDIT`
- **PROJECT:** API & Lector
- **TYPE:** ARCHITECTURE_AUDIT
- **STATUS:** CLOSED
- **BASE_HEAD:** `1de70d42d4f6c0a758fc8c9aba8188d77d100d07`
- **FINAL_HEAD:** `1de70d42d4f6c0a758fc8c9aba8188d77d100d07`
- **COMMIT:** N/A (Read-only)
- **ARTIFACTS:** Formal audit findings report
- **TEST_RESULTS:** N/A
- **DECISIONS:** Identified F-03 cross-flow station risk where physical Lector could log into legacy station B while submitting V2 punches under V2 device station A. Clarified operational architecture: Lector is autonomous; Portal3 is not a runtime dependency for Lector.
- **BLOCKERS:** F-03 station divergence
- **NEXT_STEP:** API Station Alignment Remediation

---

### Entry 008
- **DATE:** 2026-10-06
- **ACTIVITY_ID:** `CHECADOR_V2_API_STATION_ALIGNMENT_REMEDIATION`
- **PROJECT:** Checador API
- **TYPE:** FEATURE_REMEDIATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `1de70d42d4f6c0a758fc8c9aba8188d77d100d07`
- **FINAL_HEAD:** `61e942f55ef59a22a47bb22ba3cd79dceeac7444`
- **COMMIT:** `61e942f55ef59a22a47bb22ba3cd79dceeac7444`
- **ARTIFACTS:** `modelo/Acceso.php`, `modelo/v2/Level2ServerController.php`, `index.php`, `tests/v2_station_alignment_api_tests.php`
- **TEST_RESULTS:** 9 / 9 PASS (`tests/v2_station_alignment_api_tests.php`), 18/18 V2 Ingestion PASS, 34/34 SEC-L03 PASS
- **DECISIONS:** Exposed `resultado.id_estacion` in `GET /acceso-empresa` and created `GET /api/v2/devices/context` returning technical device station ID (`id_estacion`). Closed F-03 API side.
- **BLOCKERS:** Lector side F-03 enforcement pending
- **NEXT_STEP:** DOC-2 Project Control Documentation

---

### Entry 009
- **DATE:** 2026-10-06
- **ACTIVITY_ID:** `CHECADOR_V2_DOC2A_PROJECT_CONTROL_STATE_CORRECTION`
- **PROJECT:** Checador API & Lector Governance
- **TYPE:** DOCUMENTATION_CORRECTION
- **STATUS:** CLOSED
- **BASE_HEAD:** `59b386fa72a1042e0f30b85fada28c67bc484c85`
- **FINAL_HEAD:** `59b386fa72a1042e0f30b85fada28c67bc484c85`
- **COMMIT:** N/A (Doc correction commit)
- **ARTIFACTS:** `docs/status/CHECADOR_V2_CURRENT_STATUS.md`, `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`, `docs/status/CHECADOR_V2_DELIVERY_ROADMAP.md`
- **TEST_RESULTS:** N/A
- **DECISIONS:** Corrected project-control status based on project owner clarification: Lector implementation is COMPLETE, FINAL/FROZEN, and NOT active development. AUTH-3 is CLOSED and AUTH-4 is CLOSED/VERIFIED. F-03 station divergence API side is AVAILABLE (`61e942f55ef59a22a47bb22ba3cd79dceeac7444`) while Lector remediation is DEFERRED_PREPRODUCTION_REVIEW. Reset current active milestone to PENDING_CANONICAL_RESELECTION.
- **BLOCKERS:** NONE
- **NEXT_STEP:** Reselect next unfinished milestone toward 2026-12-31 based on canonical roadmap dependencies.

---

### Entry 010
- **DATE:** 2026-10-06
- **ACTIVITY_ID:** `V2_3_A01_SHIFT_SCHEDULING_MODEL_AND_CURRENT_SCHEDULE_AUDIT`
- **PROJECT:** Checador API
- **TYPE:** DOMAIN_AUDIT
- **STATUS:** CLOSED
- **BASE_HEAD:** `f736370af9ec0ff0d4e3f4bfca76378c8c459ca2`
- **FINAL_HEAD:** `f736370af9ec0ff0d4e3f4bfca76378c8c459ca2`
- **COMMIT:** N/A (Read-only audit)
- **ARTIFACTS:** Detailed domain audit findings
- **TEST_RESULTS:** N/A
- **DECISIONS:** Audited legacy `op_rh_personal_horario` table. Established that legacy schedule model lacks effective dating, night-shift support, and multi-segment support required for V2. Confirmed Section 14 of Freeze V1.0 is sufficient for physical model design.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.3-A02 Shift Scheduling V2 Physical Data Model Design

---

### Entry 011
- **DATE:** 2026-10-06
- **ACTIVITY_ID:** `V2_3_A02_SHIFT_SCHEDULING_V2_PHYSICAL_DATA_MODEL_DESIGN`
- **PROJECT:** Checador API
- **TYPE:** DOCUMENTATION_DESIGN
- **STATUS:** CLOSED
- **BASE_HEAD:** `f736370af9ec0ff0d4e3f4bfca76378c8c459ca2`
- **FINAL_HEAD:** `f736370af9ec0ff0d4e3f4bfca76378c8c459ca2`
- **COMMIT:** Pending docs commit
- **ARTIFACTS:** `docs/architecture/CHECADOR_V2_SHIFT_SCHEDULING_PHYSICAL_MODEL_V1.0.md`
- **TEST_RESULTS:** N/A
- **DECISIONS:** Designed physical schema for `op_rh_v2_turnos_plantillas`, `op_rh_v2_turnos_segmentos`, `op_rh_v2_turnos_asignaciones`, and `op_rh_v2_jornadas_programadas`. Resolved cross-midnight shifts, multi-segment breaks, and materialized `ScheduledShift` persistence. Corrected project-control doc facts (JSON queue & `acceso-temporal` ACL).
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.3-A03 Physical Data Model Implementation (when migration execution is authorized).

---

### Entry 012
- **DATE:** 2026-10-06
- **ACTIVITY_ID:** `V2_3_A02F_SHIFT_SCHEDULING_PHYSICAL_MODEL_FREEZE_REVIEW`
- **PROJECT:** Checador API
- **TYPE:** ARCHITECTURE_REVIEW
- **STATUS:** CLOSED
- **BASE_HEAD:** `aff27a0dfd1ebfbab2d5d836ddfb1fca584742cb`
- **FINAL_HEAD:** `aff27a0dfd1ebfbab2d5d836ddfb1fca584742cb`
- **COMMIT:** Pending docs commit
- **ARTIFACTS:** `docs/architecture/CHECADOR_V2_SHIFT_SCHEDULING_PHYSICAL_MODEL_V1.0.md`
- **TEST_RESULTS:** N/A
- **DECISIONS:** Evaluated pre-freeze physical data model. Outcome: `PHYSICAL_MODEL_REQUIRES_REVISION`. Resolved multi-workplace uniqueness by scoping `uk_v2_jornada_personal_estacion_fecha` to `(id_personal, id_estacion, fecha_jornada_local)`. Established canonical workplace timezone authority. Added template versioning (`clave`, `version`) and assignment audit timestamp (`registrado_at_utc`).
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.3-A03 Physical Model Implementation (SQL migration creation when explicitly authorized).

---

### Entry 013
- **DATE:** 2026-10-06
- **ACTIVITY_ID:** `V2_3_A02G_SHIFT_SCHEDULING_PHYSICAL_MODEL_FINAL_FREEZE`
- **PROJECT:** Checador API
- **TYPE:** ARCHITECTURE_FREEZE
- **STATUS:** CLOSED
- **BASE_HEAD:** `b1e8d2fef94c65349ebf40fdf9420067bb2ca3eb`
- **FINAL_HEAD:** `b1e8d2fef94c65349ebf40fdf9420067bb2ca3eb`
- **COMMIT:** Pending docs commit
- **ARTIFACTS:** `docs/architecture/CHECADOR_V2_SHIFT_SCHEDULING_PHYSICAL_MODEL_V1.0.md` (SHA256: `87c6193c50261f6a81d6eaa9bbb2c05bc68956b593b038cba7618c6c8bb3475a`)
- **TEST_RESULTS:** N/A
- **DECISIONS:** Validated consistency and formally froze physical specification `docs/architecture/CHECADOR_V2_SHIFT_SCHEDULING_PHYSICAL_MODEL_V1.0.md` as FROZEN_V1.0. Verified multi-workplace scoping, canonical workplace timezone, template versioning, and as-recorded auditability. Set active milestone to `V2.3-A03` (pending authorization).
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.3-A03 Physical Model Implementation (creating migration file when explicitly authorized).

---

### Entry 014
- **DATE:** 2026-10-06
- **ACTIVITY_ID:** `V2_3_A03_SHIFT_SCHEDULING_V2_PHYSICAL_MODEL_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** SCHEMA_IMPLEMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `1ad6623b805af2f4a7b50575a3102f34a6b2441f`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `migraciones/v2/2026_10_06_000001_create_v2_shift_scheduling_tables.sql`, `migraciones/v2/2026_10_06_000001_rollback_v2_shift_scheduling_tables.sql`, `tests/v2_shift_scheduling_schema_tests.php`
- **TEST_RESULTS:** 20 / 20 PASS (`tests/v2_shift_scheduling_schema_tests.php`), SEC-L03 (34/34 PASS), V2 Raw Ingestion (18/18 PASS), Station Alignment (9/9 PASS)
- **DECISIONS:** Implemented physical persistence schema for Checador V2 Shift Scheduling exactly per frozen specification `docs/architecture/CHECADOR_V2_SHIFT_SCHEDULING_PHYSICAL_MODEL_V1.0.md`. Created UP/DOWN SQL migration scripts for `op_rh_v2_turnos_plantillas`, `op_rh_v2_turnos_segmentos`, `op_rh_v2_turnos_asignaciones`, and `op_rh_v2_jornadas_programadas`. Validated schema creation, FK enforcement, UK constraints, overnight shifts, split shifts, multi-workplace dates, and rollback idempotency across 20 test cases.
- **BLOCKERS:** NONE
- **NEXT_STEP:** Await project owner authorization before proceeding to V2.3-A04 Shift Scheduling Service & API implementation.

---

### Entry 015
- **DATE:** 2026-10-06
- **ACTIVITY_ID:** `V2_3_A04_SHIFT_SCHEDULING_SERVICE_API_DESIGN`
- **PROJECT:** Checador API
- **TYPE:** DOCUMENTATION_DESIGN
- **STATUS:** CLOSED
- **BASE_HEAD:** `3d629c5284cf72d2143a4e9279db5892e32f09d4`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md` (SHA256: `49efd5989f6e961237d661deaa5587215214345b51b406a759763b52a9615ea7`)
- **TEST_RESULTS:** N/A (Documentation Design Only)
- **DECISIONS:** Designed service boundary (`ShiftTemplateService`, `ShiftAssignmentService`, `ScheduleResolutionService`, `ScheduledShiftMaterializationService`) and REST API contract for V2 Shift Scheduling. Defined template versioning, effective-dated assignment overlap rules, workplace timezone authority, `SCHEDULED`/`DAY_OFF`/`UNSCHEDULED`/`UNKNOWN` resolution logic, materialization trigger boundary, and error envelope. Reconciled stale A02G SHA typo in project control documentation.
- **BLOCKERS:** NONE
- **NEXT_STEP:** Await project owner authorization for pre-freeze review or freeze of Shift Scheduling Service & API contract.

---

### Entry 016
- **DATE:** 2026-10-06
- **ACTIVITY_ID:** `V2_3_A04F_SHIFT_SCHEDULING_SERVICE_API_FREEZE_REVIEW`
- **PROJECT:** Checador API
- **TYPE:** ARCHITECTURE_FREEZE
- **STATUS:** CLOSED
- **BASE_HEAD:** `fc3f44cee26d1661ccdb9e0c2cd2bfe1e7a5f985`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md` (SHA256: `3c155272198f793e7be19088e04c6b71140ad4a3acd5dc0382385bc6183675a8`)
- **TEST_RESULTS:** N/A (Contract Freeze Review)
- **DECISIONS:** Validated and formally froze Shift Scheduling Service & API specification `docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md` as FROZEN_V1.0. Enforced strict historical immutability of `op_rh_v2_jornadas_programadas` (no in-place UPDATE or force_re-materialize overwrite), replaced PATCH with `POST /api/v2/shift-assignments/{id}/close` for append-only auditability, confirmed `GET /effective-schedule` is strictly pure read-only without write side effects, bounded materialization and read pagination, and verified reuse of `biometricos` ACL module.
- **BLOCKERS:** NONE
- **NEXT_STEP:** Await project owner authorization to begin V2.3-A05 Shift Scheduling V2 Runtime Implementation.

---

### Entry 017
- **DATE:** 2026-10-06
- **ACTIVITY_ID:** `V2_3_A05_SHIFT_SCHEDULING_V2_RUNTIME_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** RUNTIME_IMPLEMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `29c26e47d7a8fefdddbc711eaa5d13d47789f36c`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `modelo/v2/shift/ShiftTemplateService.php`, `modelo/v2/shift/ShiftAssignmentService.php`, `modelo/v2/shift/ScheduleResolutionService.php`, `modelo/v2/shift/ScheduledShiftMaterializationService.php`, `modelo/v2/shift/ShiftSchedulingV2Controller.php`, `index.php`, `tests/v2_shift_scheduling_runtime_tests.php`
- **TEST_RESULTS:** 30 / 30 PASS (`tests/v2_shift_scheduling_runtime_tests.php`), Schema (20/20 PASS), SEC-L03 (34/34 PASS), V2 Raw Ingestion (18/18 PASS), Station Alignment (9/9 PASS)
- **DECISIONS:** Implemented backend domain services (`ShiftTemplateService`, `ShiftAssignmentService`, `ScheduleResolutionService`, `ScheduledShiftMaterializationService`), controller (`ShiftSchedulingV2Controller`), and API routes in `index.php` strictly following frozen specifications. Enforced template versioning, assignment overlap checking per workplace, pure read-only schedule resolution without side-effects, workplace timezone authority, bounded batch materialization, and immutable `ScheduledShift` persistence.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.4-P1D PunchEvent / ScheduledShift Pairing Contract Design & Freeze.

---

### Entry 018
- **DATE:** 2026-10-06
- **ACTIVITY_ID:** `V2_4_P1D_JOURNEY_PAIRING_CONTRACT_DESIGN`
- **PROJECT:** Checador API
- **TYPE:** DOMAIN_CONTRACT_DESIGN
- **STATUS:** CLOSED
- **BASE_HEAD:** `308a607e7269515f2fb8851b3956b0663802b91e`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `docs/v2/CHECADOR_V2_JOURNEY_PAIRING_CONTRACT_V1.0.md` (SHA256: `7cacdc6ca33cc478b0eb65103002784d95d03865f4cf76f99177dcf2cc4a31d5`)
- **TEST_RESULTS:** N/A (Domain Contract Design & Freeze)
- **DECISIONS:** Designed and formally froze `docs/v2/CHECADOR_V2_JOURNEY_PAIRING_CONTRACT_V1.0.md` as FROZEN_V1.0. Defined deterministic pairing of raw `PunchEvent` records with `ScheduledShift` slots and expected segments without calculating lateness, overtime, worked-time payroll effects, FALTA, or incidences. Formulated raw punch immutability, fail-closed ambiguity resolution, candidate search windows, night-shift midnight crossing rules, multi-segment slot allocation, surplus punch handling, Level 2 completeness integration, and canonical test matrix.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.4-P1S Journey Pairing Physical Schema Design & Conditional Freeze.

---

### Entry 019
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_4_P1S_PAIRING_PHYSICAL_SCHEMA_DESIGN`
- **PROJECT:** Checador API
- **TYPE:** PHYSICAL_MODEL_DESIGN
- **STATUS:** CLOSED
- **BASE_HEAD:** `19d5512c83ec374a4acc718d1ae92181a54eefa7`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `docs/architecture/CHECADOR_V2_JOURNEY_PAIRING_PHYSICAL_MODEL_V1.0.md` (SHA256: `b5865e22ce5e2ddfaa505968ec981e735ee82667d58b7ceeec99b81fe4054d6a`)
- **TEST_RESULTS:** N/A (Physical Model Design & Freeze)
- **DECISIONS:** Designed and formally froze physical persistence model `docs/architecture/CHECADOR_V2_JOURNEY_PAIRING_PHYSICAL_MODEL_V1.0.md` as FROZEN_V1.0. Specified normalized 3-table storage architecture (`op_rh_v2_pareos_jornada`, `op_rh_v2_pareos_segmentos`, `op_rh_v2_pareos_marcaciones_asociadas`). Enforced append-only revisioning per `id_jornada_programada`, single-consumption DB constraint within execution revisions (`uk_v2_pareo_revision_marcacion`), primary Entry/Exit slot limits, arbitrary surplus punch links, Level 2 completeness provenance, and proposed DDL.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.4-P1M Motor de Jornada P1 Pairing Physical Schema Implementation.

---

### Entry 020
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_4_P1M_MOTOR_DE_JORNADA_PAIRING_SCHEMA_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** SCHEMA_IMPLEMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `622b1fe89531787483002c884a18248e63ba3a20`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `migraciones/v2/2026_10_07_000001_create_v2_journey_pairing_tables.sql`, `migraciones/v2/2026_10_07_000001_rollback_v2_journey_pairing_tables.sql`, `tests/v2_journey_pairing_schema_tests.php`
- **TEST_RESULTS:** 25 / 25 PASS (`tests/v2_journey_pairing_schema_tests.php`), Shift Schema (20/20 PASS), Shift Runtime (30/30 PASS), V2 Raw Ingestion (18/18 PASS), SEC-L03 (34/34 PASS), Station Alignment (9/9 PASS)
- **DECISIONS:** Implemented physical persistence schema for Checador V2 Journey Pairing P1 strictly following frozen specification `docs/architecture/CHECADOR_V2_JOURNEY_PAIRING_PHYSICAL_MODEL_V1.0.md`. Created UP/DOWN SQL migrations for `op_rh_v2_pareos_jornada`, `op_rh_v2_pareos_segmentos`, and `op_rh_v2_pareos_marcaciones_asociadas`. Implemented append-only triggers (`tg_op_rh_v2_pareos_jornada_no_update`, `tg_op_rh_v2_pareos_jornada_no_delete`). Validated schema creation, FK enforcement (`ON DELETE RESTRICT`), single-consumption constraint within executions (`uk_v2_pareo_revision_marcacion`), historical revision raw-event reuse across executions, arbitrary surplus punch linking, Level 2 completeness provenance, and clean rollback/re-application across 25 schema tests.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.4-P1 Motor de Jornada P1 Pairing Runtime Implementation.

---

### Entry 021
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_4_P1_MOTOR_DE_JORNADA_PAIRING_RUNTIME_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** RUNTIME_IMPLEMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `3a24f512ce2580a87c7dc49807bcd354dd2311d3`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `modelo/v2/journey/JourneyPairingService.php`, `tests/v2_journey_pairing_runtime_tests.php`
- **TEST_RESULTS:** 16 / 16 PASS (`tests/v2_journey_pairing_runtime_tests.php`), Pairing Schema (25/25 PASS), Shift Schema (20/20 PASS), Shift Runtime (30/30 PASS), V2 Raw Ingestion (18/18 PASS), SEC-L03 (34/34 PASS), Station Alignment (9/9 PASS)
- **DECISIONS:** Implemented internal domain pairing service `JourneyPairingService` strictly following frozen specifications. Enforced candidate time window evaluation (S_start - 4h, S_end + 4h), deterministic punch sorting, single-consumption within execution revisions, arbitrary surplus punch persistence, night shift post-midnight mapping, workplace alignment verification, Level 2 completeness provenance, SHA256 input fingerprint calculation for idempotent re-runs, and append-only revisioning per ScheduledShift without emitting FALTA or worked-time payroll calculations.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.4-P1C Journey Pairing Runtime Closure Reconciliation.

---

### Entry 022
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_4_P2D_JOURNEY_CALCULATION_CONTRACT_DESIGN`
- **PROJECT:** Checador API
- **TYPE:** DOMAIN_CONTRACT_DESIGN
- **STATUS:** CLOSED
- **BASE_HEAD:** `ced3c184608f643e9de429377ca99267eb6f5ff0`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `docs/v2/CHECADOR_V2_JOURNEY_CALCULATION_CONTRACT_V1.0.md` (SHA256: `9e976213010f6787c05a43b5ebc7d6d2da313c104dda9a2d635e69e88f4d1f96`)
- **TEST_RESULTS:** N/A (Domain Contract Design & Freeze)
- **DECISIONS:** Designed and formally froze `docs/v2/CHECADOR_V2_JOURNEY_CALCULATION_CONTRACT_V1.0.md` as FROZEN_V1.0. Formulated technical attendance calculation rules, duration metrics, signed arrival/departure deltas, multi-segment work aggregation, night shifts crossing midnight, partial pairing fail-closed behavior, non-final pending sync handling, and explicit overtime/incidences/payroll boundary rules. Enforced zero mutation of raw punches, scheduled shifts, or pairing history. Established official business terminology “comparación paralela sin afectar nómina”. Determined that P2 requires append-only result persistence and physical schema design prior to runtime calculation implementation.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.4-P2S Journey Calculation Physical Schema Design & Conditional Freeze.

---

### Entry 023
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_4_P2S_JOURNEY_CALCULATION_PHYSICAL_SCHEMA_DESIGN`
- **PROJECT:** Checador API
- **TYPE:** PHYSICAL_MODEL_DESIGN
- **STATUS:** CLOSED
- **BASE_HEAD:** `f3b8c6eff16a42cb17d539988f203040acf189eb`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `docs/architecture/CHECADOR_V2_JOURNEY_CALCULATION_PHYSICAL_MODEL_V1.0.md` (SHA256: `784d22fb9f2450315a841379190c6906bca9c9e88975daa98587356649af313a`)
- **TEST_RESULTS:** N/A (Physical Model Design & Freeze)
- **DECISIONS:** Designed and formally froze physical persistence model `docs/architecture/CHECADOR_V2_JOURNEY_CALCULATION_PHYSICAL_MODEL_V1.0.md` as FROZEN_V1.0. Specified normalized 2-table storage architecture (`op_rh_v2_calculos_jornada`, `op_rh_v2_calculos_segmentos`). Enforced append-only calculation revisioning per ScheduledShift (`uk_v2_calculo_jornada_revision`), explicit immutable P1 pairing revision FK (`id_pareo_jornada`), seconds-based technical metrics (`*_segundos`), signed arrival/departure deltas, SHA256 input fingerprint idempotency (`uk_v2_calculo_fingerprint`), database triggers blocking header UPDATE/DELETE operations, and proposed DDL with future schema test matrix.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.4-P2M Motor de Jornada P2 Calculation Physical Schema Implementation.

---

### Entry 024
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_4_P2M_JOURNEY_CALCULATION_SCHEMA_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** SCHEMA_IMPLEMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `febb505bd7026d7a9fe6721e7f9fdb4f63a0e899`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `migraciones/v2/2026_10_07_000002_create_v2_journey_calculation_tables.sql` (SHA256: `e357a0c590f6e0b83b0f070c553600608bd363fed740d93bcb905abc9699e46d`), `migraciones/v2/2026_10_07_000002_rollback_v2_journey_calculation_tables.sql` (SHA256: `d3266a904cf9cded7f705736f9608969a0a6020cb0e7a3f62752c960647f2da4`), `tests/v2_journey_calculation_schema_tests.php`
- **TEST_RESULTS:** 30 / 30 PASS (`tests/v2_journey_calculation_schema_tests.php`), Pairing Schema (25/25 PASS), Pairing Runtime (16/16 PASS), Shift Schema (20/20 PASS), Shift Runtime (30/30 PASS), V2 Raw Ingestion (18/18 PASS), SEC-L03 (34/34 PASS), Station Alignment (9/9 PASS)
- **DECISIONS:** Implemented physical persistence schema for Checador V2 Journey Calculation P2 strictly following frozen specifications. Created UP/DOWN SQL migrations for `op_rh_v2_calculos_jornada` and `op_rh_v2_calculos_segmentos`. Implemented append-only triggers (`tg_op_rh_v2_calculos_jornada_no_update`, `tg_op_rh_v2_calculos_jornada_no_delete`). Validated schema creation, FK type compatibility and ON DELETE RESTRICT enforcement against ScheduledShift and P1 pairing headers, seconds-based duration storage (`*_segundos`), signed entry/exit deltas, SHA256 input fingerprint idempotency (`uk_v2_calculo_fingerprint`), non-calculable NULL duration semantics for PARTIAL_DATA/INVALID_INTERVAL, DAY_OFF (0 scheduled seconds), UNSCHEDULED (NULL scheduled seconds), UNKNOWN, PENDING_SYNC, and clean rollback/re-application across 30 focused schema tests.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.4-P2 Motor de Jornada P2 Journey Calculation Runtime Implementation.

---

### Entry 025
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_4_P2_MOTOR_DE_JORNADA_CALCULATION_RUNTIME_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** RUNTIME_IMPLEMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `7e27ddd98619a2843694eace3aef649fbaf7be2e`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `modelo/v2/journey/JourneyCalculationService.php`, `tests/v2_journey_calculation_runtime_tests.php`
- **TEST_RESULTS:** 14 / 14 PASS (`tests/v2_journey_calculation_runtime_tests.php`), Calculation Schema (30/30 PASS), Pairing Schema (25/25 PASS), Pairing Runtime (16/16 PASS), Shift Schema (20/20 PASS), Shift Runtime (30/30 PASS), V2 Raw Ingestion (18/18 PASS), SEC-L03 (34/34 PASS), Station Alignment (9/9 PASS)
- **DECISIONS:** Implemented internal domain calculation engine `JourneyCalculationService` strictly following frozen specifications (`CHECADOR_V2_JOURNEY_CALCULATION_CONTRACT_V1.0.md` & `CHECADOR_V2_JOURNEY_CALCULATION_PHYSICAL_MODEL_V1.0.md`). Consumed explicit immutable ScheduledShifts and P1 pairing revisions (`op_rh_v2_pareos_jornada`), deriving seconds-precision technical worked durations (`segundos_trabajados_totales`), signed entry/exit timestamp deltas (`delta_llegada_segundos`, `delta_salida_segundos`), multi-segment split work aggregation (excluding unworked gaps), night shift UTC timestamp math spanning midnight, fail-closed `PARTIAL_DATA` with NULL worked seconds, `DAY_OFF_RECORDED` (0 scheduled seconds), `UNSCHEDULED_RECORDED` (NULL scheduled seconds), `UNMATCHED_NO_PUNCHES` (NULL worked seconds without emitting FALTA), monotonic per-shift revision numbering (`revision_numero`), SHA256 input execution fingerprints for idempotent re-runs, and append-only P2 revision persistence in `op_rh_v2_calculos_jornada` and `op_rh_v2_calculos_segmentos`. Enforced zero public API exposure, zero DB schema/migration changes, zero incidence emission, zero payroll/monetary calculations, and complete non-mutation of raw punches, scheduled shifts, and pairing history.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.5-D Administrative Incidences & Adjustments Contract Design & Conditional Freeze.

---

### Entry 026
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_5_D_ADMINISTRATIVE_INCIDENCES_CONTRACT_DESIGN`
- **PROJECT:** Checador API
- **TYPE:** DOMAIN_CONTRACT_DESIGN
- **STATUS:** CLOSED
- **BASE_HEAD:** `13634c9d78e991b9b1bac811055d3e28800ae8e9`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `docs/v2/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_CONTRACT_V1.0.md` (SHA256: `94f91abf6d0ddeee641c736159b922debbadad5fb60db91da1d26827dfac6b7d`)
- **TEST_RESULTS:** N/A (Domain Contract Design & Freeze)
- **DECISIONS:** Designed and formally froze `docs/v2/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_CONTRACT_V1.0.md` as FROZEN_V1.0. Formulated administrative incidence master date-range model (Vacation, Disability), shift-specific permissions, daily application derivation, administrative adjustment overrides, contradiction retention (`CONTRADICTION_DETECTED` when punches occur during approved absence), fail-closed handling for incomplete Level 2 sources, strict prerequisites for administrative `FALTA` (scheduled shift, `VERIFIED` Level 2, `UNMATCHED_NO_PUNCHES` P1/P2, and absence of approved incidence/adjustment), seconds-precision preservation without rounding, zero mutation of upstream raw punches/shifts/pairing/P2 calculation records, zero synthetic raw punch creation, zero public API endpoints, and zero payroll monetary calculations (`comparación paralela sin afectar nómina`). Determined that V2.5 requires physical schema design prior to runtime implementation.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.5-S Administrative Incidences Physical Schema Design & Conditional Freeze.

---

### Entry 027
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_5_S_ADMINISTRATIVE_INCIDENCES_PHYSICAL_SCHEMA_DESIGN`
- **PROJECT:** Checador API
- **TYPE:** PHYSICAL_MODEL_DESIGN
- **STATUS:** CLOSED
- **BASE_HEAD:** `e0f3d69f8d54382d259ec15d3e14776197506037`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `docs/architecture/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_V1.0.md` (SHA256: `9bbffb34e736fea1cd9dfb433bb1cc1168051af410f34d041fa4bc8ddf6ec29a`)
- **TEST_RESULTS:** N/A (Physical Model Design & Freeze)
- **DECISIONS:** Designed and formally froze physical data persistence model `docs/architecture/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_V1.0.md` as FROZEN_V1.0. Specified normalized 5-table storage architecture (`op_rh_v2_incidencias_maestro`, `op_rh_v2_incidencias_revisiones`, `op_rh_v2_incidencias_aplicaciones`, `op_rh_v2_ajustes_administrativos`, `op_rh_v2_evaluaciones_administrativas`). Enforced append-only evaluation revisioning (`uk_v2_eval_shift_revision`), database triggers blocking header UPDATE/DELETE operations (`tg_op_rh_v2_evaluaciones_admin_no_update`, `tg_op_rh_v2_evaluaciones_admin_no_delete`), single master range incidence persistence for multi-day Vacation/Disability, derived daily application records linked per ScheduledShift, explicit P2 calculation revision FK provenance (`id_calculo_jornada`), administrative interpreted time storage (`hora_interpretada_entrada_utc`, `hora_interpretada_salida_utc`) without touching raw punch tables, SHA256 evaluation execution fingerprints for idempotency (`uk_v2_eval_fingerprint`), policy version provenance (`version_politica`), `ON DELETE RESTRICT` delete safety across all primary FKs, and proposed DDL with future 52-scenario schema test matrix.
- **BLOCKERS:** NONE
- **NEXT_STEP:** Await project owner authorization for downstream milestone V2.5-M (Administrative Incidences Physical Schema Implementation).

---

### Entry 028
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_5_M_ADMINISTRATIVE_INCIDENCES_SCHEMA_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** SCHEMA_IMPLEMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `0430ec15927ab09a8b9a342b4cda5d52c83d0989`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `migraciones/v2/2026_10_07_000003_create_v2_administrative_incidences_tables.sql` (SHA256: `fcac402726488349479b0c95360980ff67d2ca8acb846e9df5f5643ac7b9efbf`), `migraciones/v2/2026_10_07_000003_rollback_v2_administrative_incidences_tables.sql` (SHA256: `d870048ab15b4971c26176395b058a97fdb5a109a9dbf1e9c5edcdd8d8d3e2ef`), `tests/v2_administrative_incidences_schema_tests.php`
- **TEST_RESULTS:** 10 / 10 PASS (covering 52 schema invariants in `tests/v2_administrative_incidences_schema_tests.php`), P2 Calculation Schema (30/30 PASS), P2 Calculation Runtime (14/14 PASS), P1 Pairing Schema (25/25 PASS), P1 Pairing Runtime (16/16 PASS), Shift Scheduling Schema (30/30 PASS), Shift Scheduling Runtime (30/30 PASS), V2 Raw Punch Ingestion (18/18 PASS), SEC-L03 ACL (34/34 PASS), Station Alignment (9/9 PASS), Level 2 API Capabilities (16/16 PASS)
- **DECISIONS:** Implemented exact physical persistence schema for Checador V2.5 Administrative Incidences & Adjustments strictly per frozen specification `docs/architecture/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_V1.0.md`. Created UP/DOWN SQL migration scripts for 5 normalized tables (`op_rh_v2_incidencias_maestro`, `op_rh_v2_incidencias_revisiones`, `op_rh_v2_incidencias_aplicaciones`, `op_rh_v2_ajustes_administrativos`, `op_rh_v2_evaluaciones_administrativas`). Implemented database triggers `tg_op_rh_v2_evaluaciones_admin_no_update` and `tg_op_rh_v2_evaluaciones_admin_no_delete` enforcing strict hard immutability on evaluation revisions. Validated FK type compatibility, single master range persistence for multi-day absences, derived daily shift applications, explicit P2 calculation revision FK link (`id_calculo_jornada`), administrative interpreted time isolation (`hora_interpretada_entrada_utc`, `hora_interpretada_salida_utc`) without raw punch masquerading, SHA256 evaluation fingerprints (`uk_v2_eval_fingerprint`), policy version provenance (`version_politica`), evaluation status header flags (`FALTA`, `CONTRADICTION_DETECTED`, `REVIEW_REQUIRED`), `ON DELETE RESTRICT` FK deletion protection, zero payroll monetary columns, and clean UP/DOWN migration idempotency.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.5-SA Administrative Incidences Physical Model Provenance & Adjustment Revision Addendum V1.1.

---

### Entry 029
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_5_SA_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_ADDENDUM_V1_1`
- **PROJECT:** Checador API
- **TYPE:** PHYSICAL_MODEL_REMEDIATION_DESIGN
- **STATUS:** CLOSED
- **BASE_HEAD:** `99d53ee90d1cb22d5593000032cba8f3aa5c8bed`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `docs/architecture/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_ADDENDUM_V1.1.md` (SHA256: `13c624213b9fdd2cad733d802f823e31e8dc287142173f4b80935802dcf670c1`)
- **TEST_RESULTS:** N/A (Physical Model Addendum Design & Freeze)
- **DECISIONS:** Designed and formally froze physical persistence addendum `docs/architecture/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_ADDENDUM_V1.1.md` as FROZEN_V1.1 to resolve forensic findings from `V2_5_MCP`. Specified 2 normalized append-only link tables (`op_rh_v2_evaluaciones_incidencias` linking evaluation revisions to `op_rh_v2_incidencias_aplicaciones`, and `op_rh_v2_evaluaciones_ajustes` linking evaluation revisions to `op_rh_v2_ajustes_administrativos`) establishing exact, historically reproducible input-set membership where 0 link rows explicitly denote an empty set `[]`. Added explicit logical adjustment lineage columns (`uuid_ajuste_logico`, `revision_numero`, `id_ajuste_anterior`, `estado_ajuste`) to `op_rh_v2_ajustes_administrativos` to support append-only adjustment corrections and cancellations. Established 100% backward data compatibility for Migration 000003 rows. Formulated proposed Migration 000004 DDL and a 40-scenario test matrix. Kept Migration 000003 byte-for-byte unchanged.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.5-MA Administrative Incidences Provenance Additive Schema Implementation.

---

### Entry 030
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_5_MA_ADMINISTRATIVE_INCIDENCES_PROVENANCE_ADDITIVE_SCHEMA_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** SCHEMA_IMPLEMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `5a7804e5b8d232fc7fa1abbd235c679fcca2d201`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `migraciones/v2/2026_10_07_000004_create_v2_administrative_provenance_tables.sql` (SHA256: `24914f38612e94ce012f5d1ea8360685ca9dd3a9c51122e80e5160582ade1eea`), `migraciones/v2/2026_10_07_000004_rollback_v2_administrative_provenance_tables.sql` (SHA256: `098968562c36ea3fc0166abaf0c556447194d6be2571927e71d102e1cc085c35`), `tests/v2_administrative_provenance_schema_tests.php`
- **TEST_RESULTS:** 7 / 7 PASS (covering 44 physical addendum invariants in `tests/v2_administrative_provenance_schema_tests.php`), V2.5-M Schema (10/10 PASS), P2 Calculation Schema (30/30 PASS), P2 Calculation Runtime (14/14 PASS), P1 Pairing Schema (25/25 PASS), P1 Pairing Runtime (16/16 PASS), Shift Scheduling Schema (30/30 PASS), Shift Scheduling Runtime (30/30 PASS), V2 Raw Punch Ingestion (18/18 PASS), SEC-L03 ACL (34/34 PASS), Station Alignment (9/9 PASS), Level 2 API Capabilities (16/16 PASS)
- **DECISIONS:** Implemented additive physical schema for Checador V2.5 Administrative Provenance strictly per frozen addendum `docs/architecture/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_ADDENDUM_V1.1.md`. Created UP/DOWN SQL migration scripts for 000004 (`op_rh_v2_evaluaciones_incidencias`, `op_rh_v2_evaluaciones_ajustes`, `uuid_ajuste_logico`, `revision_numero`, `estado_ajuste`, `id_ajuste_anterior`). Validated 0-link empty set semantics (`[]`), retroactive incidence/adjustment isolation for historical evaluation E1, append-only adjustment revision lineage, `ON DELETE RESTRICT` delete safety, backfill data compatibility for 000003 rows, deterministic SHA256 fingerprint input reconstruction, byte-for-byte immutability of Migration 000003 (`fcac402726488349479b0c95360980ff67d2ca8acb846e9df5f5643ac7b9efbf`), and zero creation of administrative evaluation runtime or REST endpoints.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.5 Administrative Incidence & Evaluation Runtime Implementation (when explicitly authorized by project owner).

---

### Entry 031
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_5_ADMINISTRATIVE_INCIDENCE_EVALUATION_RUNTIME_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** RUNTIME_IMPLEMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `c21a8d69fca16933845ee046c46783164b4f35c1`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `modelo/v2/journey/AdministrativeEvaluationService.php`, `tests/v2_administrative_runtime_tests.php`
- **TEST_RESULTS:** 10 / 10 PASS (`tests/v2_administrative_runtime_tests.php`), V2.5 Provenance Schema (7/7 PASS), V2.5 Incidence Schema (10/10 PASS), P2 Calculation Schema (30/30 PASS), P2 Calculation Runtime (14/14 PASS), P1 Pairing Schema (25/25 PASS), P1 Pairing Runtime (16/16 PASS), Shift Scheduling Schema (30/30 PASS), Shift Scheduling Runtime (30/30 PASS), V2 Raw Punch Ingestion (18/18 PASS), SEC-L03 ACL (34/34 PASS), Station Alignment (9/9 PASS), Level 2 API Capabilities (16/16 PASS)
- **DECISIONS:** Implemented domain runtime service `AdministrativeEvaluationService` strictly following frozen specifications (`CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_CONTRACT_V1.0.md`, `CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_V1.0.md`, and `CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_ADDENDUM_V1.1.md`). Consumed explicit immutable ScheduledShifts (`id_jornada_programada`) and P2 calculation revisions (`id_calculo_jornada`), deriving master incidence creation (`createIncidenceMaster`), multi-day shift applications (`applyIncidenceRevisionToShifts`), append-only administrative adjustments (`createAdjustmentRevision`), exact provenance association (`op_rh_v2_evaluaciones_incidencias`, `op_rh_v2_evaluaciones_ajustes`), interpretation of worked/excused times without modifying raw punches, deterministic FALTA derivation (SCHEDULED + VERIFIED L2 + UNMATCHED_NO_PUNCHES + zero active incidence/adjustment), contradiction retention (`CONTRADICTION_DETECTED` when punches occur during approved absence), overlap conflict handling (`OVERLAP_CONFLICT` + `PENDING_REVIEW`), DAY_OFF/UNSCHEDULED immunity from FALTA, monotonic revision numbering (`revision_numero`), execution fingerprint SHA256 hashing for idempotent re-runs, and append-only evaluation revision persistence in `op_rh_v2_evaluaciones_administrativas`. Enforced zero public API controllers/routes exposure, zero DB schema/migration changes (Migrations 000003 and 000004 remained byte-for-byte unchanged), zero payroll monetary calculations, and complete non-mutation of raw punches, scheduled shifts, P1 pairing, or P2 calculations.
- **BLOCKERS:** NONE
- **NEXT_STEP:** Await project owner authorization before proceeding to V2.6 Legacy & Payroll Parallel Comparison Adapter.


---

### Entry 032
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_6_LEGACY_PAYROLL_PARALLEL_COMPARISON_CONTRACT_DISCOVERY`
- **PROJECT:** Checador API
- **TYPE:** DOMAIN_CONTRACT_DESIGN
- **STATUS:** CLOSED
- **BASE_HEAD:** `e0ba20716456921d81e77b7313896b0382437088`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `docs/v2/CHECADOR_V2_LEGACY_PAYROLL_PARALLEL_COMPARISON_ADAPTER_CONTRACT_V1.0.md` (SHA256: `1e9ec3783b29b09116179168cb45695bf56330ac306bf9cdac81aef49bf09917`)
- **TEST_RESULTS:** N/A (Domain Contract Design & Freeze)
- **DECISIONS:** Designed and formally froze contract specification `docs/v2/CHECADOR_V2_LEGACY_PAYROLL_PARALLEL_COMPARISON_ADAPTER_CONTRACT_V1.0.md` as FROZEN_V1.0. Defined parallel comparison engine boundary comparing V2.5 administrative evaluations (`op_rh_v2_evaluaciones_administrativas`) against legacy attendance records (`op_rh_personal_asistencia`, `op_rh_personal_asistencia_incidencia`) under strict `comparación paralela sin afectar nómina` rules. Enforced zero payroll monetary calculations, zero legacy database mutation, zero public API exposure, zero Lector/Portal3 code modifications, fail-closed handling for missing/incomplete data (`LEVEL2_INCOMPLETE`, `PAIRING_AMBIGUOUS`), deterministic discrepancy categorization (`MATCH`, `PUNCH_DIFFERENCE`, `SHIFT_DIFFERENCE`, `INCIDENT_DIFFERENCE`, `OVERTIME_DIFFERENCE`, `LEGACY_LIMITATION`, `REVIEW_REQUIRED`, `MISSING_LEGACY_RECORD`), explicit evaluation revision linkage, and SHA256 comparison execution fingerprints. Determined that V2.6 requires physical database model design prior to schema implementation (`NEW_SCHEMA_REQUIRED: YES`).
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.6-S Legacy & Payroll Parallel Comparison Adapter Physical Schema Design & Conditional Freeze.

---

### Entry 033
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_6_S_LEGACY_PAYROLL_PARALLEL_COMPARISON_PHYSICAL_MODEL_DESIGN`
- **PROJECT:** Checador API
- **TYPE:** PHYSICAL_MODEL_DESIGN
- **STATUS:** CLOSED
- **BASE_HEAD:** `4b06106227067b702dac87de4e85db11afaf6081`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md` (SHA256: `def2bc34d445c3eb0677a71e9eaefe978cc1c0f2892c8a16516e45df192b6a37`)
- **TEST_RESULTS:** N/A (Physical Model Design & Freeze)
- **DECISIONS:** Designed and formally froze physical persistence model `docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md` as FROZEN_V1.0. Specified normalized 4-table storage architecture (`op_rh_v2_comparaciones_encabezado`, `op_rh_v2_comparaciones_legacy_capturas`, `op_rh_v2_comparaciones_legacy_incidencias`, `op_rh_v2_comparaciones_discrepancias`). Established historical reproducibility doctrine: because legacy rows (`op_rh_personal_asistencia`) can be modified, V2.6 persists an immutable comparison-time normalized snapshot of all consumed legacy attendance fields and legacy incidence sets (`op_rh_v2_comparaciones_legacy_incidencias`). Enforced append-only comparison revisioning (`uk_v2_comp_eval_revision`), database triggers blocking header UPDATE/DELETE operations (`tg_op_rh_v2_comparaciones_encabezado_no_update`, `tg_op_rh_v2_comparaciones_encabezado_no_delete`), explicit V2.5 administrative evaluation revision FK provenance (`id_evaluacion_administrativa`), SHA256 execution fingerprints (`uk_v2_comp_fingerprint`), normalized value-level discrepancy evidence logging (`valor_v2`, `valor_legacy`), explicit missing-record presence provenance (`MISSING_RECORD`), zero monetary/payroll columns, `ON DELETE RESTRICT` delete safety across all V2 FKs, and proposed DDL with future 40-scenario schema test matrix.
- **BLOCKERS:** NONE
- **NEXT_STEP:** Await project owner authorization before proceeding to V2.6-M Legacy & Payroll Parallel Comparison Schema Implementation.

---

### Entry 034
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_6_SA_PARALLEL_COMPARISON_PHYSICAL_MODEL_ADDENDUM`
- **PROJECT:** Checador API
- **TYPE:** PHYSICAL_MODEL_ADDENDUM
- **STATUS:** CLOSED
- **BASE_HEAD:** `40d505241a5d52c043a07135c7907ff7b4d9114d`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_ADDENDUM_V1.1.md` (SHA256: `451179ed195d6e8f7970f8cf5cb01c6a2a432e07b90f245d338e63197cfa6f66`)
- **TEST_RESULTS:** N/A (Physical Model Addendum Design & Freeze)
- **DECISIONS:** Created and formally froze physical model addendum `docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_ADDENDUM_V1.1.md` as FROZEN_V1.1. Remediated fingerprint collision defect identified in V2.6-SC by embedding captured attendance snapshot hash identity (`snapshot_hash`) into `fingerprint_ejecucion` formula (`hash('sha256', "{$idEvaluacionAdmin}:{$legacyPresenceState}:{$legacyAttendanceSnapshotHash}:{$sortedLegacyIncidenceSnapshotHashes}:{$algorithmVersion}:{$policyVersion}")`). Ensured that mutations of identical legacy source record IDs yield distinct comparison execution fingerprints, enabling multi-revision append-only history. Preserved frozen V1.0 physical tables DDL structure (`op_rh_v2_comparaciones_encabezado`, `op_rh_v2_comparaciones_legacy_capturas`, `op_rh_v2_comparaciones_legacy_incidencias`, `op_rh_v2_comparaciones_discrepancias`), contract V1.0, and zero-monetary boundaries. V2.6-M is now ready.
- **BLOCKERS:** NONE
- **NEXT_STEP:** V2.6-M Legacy & Payroll Parallel Comparison Schema Implementation.

---

### Entry 035
- **DATE:** 2026-10-07
- **ACTIVITY_ID:** `V2_6_M_LEGACY_PAYROLL_PARALLEL_COMPARISON_SCHEMA_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** SCHEMA_IMPLEMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `dd4b13f94fd7e8e15ff58c43eb5e54d3f7d0be2b`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `migraciones/v2/2026_10_07_000005_create_v2_parallel_comparison_tables.sql` (SHA256: `a8f109ec793783100c18c9595d5f3e1a56e9df83e69bc80292aed61a9d589dc1`), `migraciones/v2/2026_10_07_000005_rollback_v2_parallel_comparison_tables.sql` (SHA256: `c3946cc581fe824425cced112ca3c4bb6725c3b4334241359742721016d1f405`), `tests/v2_parallel_comparison_schema_tests.php`
- **TEST_RESULTS:** 12 / 12 Test Blocks (38 assertions) PASS (`tests/v2_parallel_comparison_schema_tests.php`), V2.5 Runtime (10/10 PASS), V2.5 Provenance Schema (7/7 PASS), V2.5 Incidence Schema (10/10 PASS), P2 Calculation Schema (14/14 PASS), P1 Pairing Schema (25/25 PASS), P1 Pairing Runtime (16/16 PASS), Shift Scheduling Schema (20/20 PASS), Shift Scheduling Runtime (30/30 PASS), V2 Raw Punch Ingestion (18/18 PASS), Station Alignment (9/9 PASS), Level 2 API Server (16/16 PASS).
- **DECISIONS:** Implemented migration 000005 creating 4 parallel comparison persistence tables (`op_rh_v2_comparaciones_encabezado`, `op_rh_v2_comparaciones_legacy_capturas`, `op_rh_v2_comparaciones_legacy_incidencias`, `op_rh_v2_comparaciones_discrepancias`) strictly following frozen specs `CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md` and Addendum `V1.1`. Persisted explicit FK provenance to immutable V2.5 administrative evaluations (`id_evaluacion_administrativa`), monotonic revisioning (`revision_numero`), SHA256 execution fingerprint (`fingerprint_ejecucion`) incorporating `snapshot_hash` for same-ID mutation distinction, missing-record sentinel canonicalization (`MISSING_RECORD`), 0..N legacy incidence set persistence, discrepancy evidence logging (`valor_v2`, `valor_legacy`), hard immutability database triggers (`tg_op_rh_v2_comparaciones_encabezado_no_update`, `tg_op_rh_v2_comparaciones_encabezado_no_delete`), zero monetary/payroll fields, and clean UP/DOWN rollback cycle.
- **BLOCKERS:** NONE
- **NEXT_STEP:** Await project owner authorization before proceeding to V2.6 Runtime Engine Implementation.

---

### Entry 036
- **DATE:** 2026-10-08
- **ACTIVITY_ID:** `V2_6_LEGACY_PAYROLL_PARALLEL_COMPARISON_RUNTIME_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** RUNTIME_ENGINE_IMPLEMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `8a1b59a3a0d74d57292e20aea3b434f4a43a3b38`
- **FINAL_HEAD:** Pending commit
- **COMMIT:** Pending commit
- **ARTIFACTS:** `modelo/v2/journey/ParallelComparisonService.php`, `tests/v2_parallel_comparison_runtime_tests.php`
- **TEST_RESULTS:** 9 / 9 Test Blocks (29 assertions) PASS (`tests/v2_parallel_comparison_runtime_tests.php`), V2.6 Schema (12/12 PASS), V2.5 Runtime (10/10 PASS), V2.5 Provenance Schema (7/7 PASS), V2.5 Incidence Schema (10/10 PASS), P2 Calculation Schema (14/14 PASS), P1 Pairing Schema (25/25 PASS), P1 Pairing Runtime (16/16 PASS), Shift Scheduling Schema (20/20 PASS), Shift Scheduling Runtime (30/30 PASS), V2 Raw Punch Ingestion (18/18 PASS), Station Alignment (9/9 PASS), Level 2 API Server (16/16 PASS).
- **DECISIONS:** Implemented domain runtime service `ParallelComparisonService` (`modelo/v2/journey/ParallelComparisonService.php`) consuming explicit immutable V2.5 administrative evaluations (`id_evaluacion_administrativa`), reading legacy attendance/incidence tables in read-only mode, capturing immutable comparison-time attendance snapshots (`snapshot_hash`), persisting 0..N legacy incidence snapshot sets, generating Addendum V1.1 execution fingerprints (`uk_v2_comp_fingerprint`), canonicalizing missing records (`MISSING_RECORD`), executing deterministic fail-closed comparison evaluations (`MATCH`, `PUNCH_DIFFERENCE`, `SHIFT_DIFFERENCE`, `INCIDENT_DIFFERENCE`, `LEGACY_LIMITATION`, `REVIEW_REQUIRED`, `MISSING_LEGACY_RECORD`, `NOT_COMPARABLE`), persisting normalized discrepancy evidence logs, and allocating monotonic append-only comparison revisions (`revision_numero`). Ensured zero legacy mutations, zero upstream V2 mutations, zero public API exposure, and zero monetary/payroll writes.
- **BLOCKERS:** NONE
- **NEXT_STEP:** Await project owner authorization before proceeding to V2.7 Pre-production Hardening & Cutover Readiness.

---

### Entry 037
- **DATE:** 2026-10-09
- **ACTIVITY_ID:** `CHECADOR_V2_LEVEL1_DEVELOPMENT_GOVERNANCE_SYNC_20261009_RESUME`
- **PROJECT:** Checador API
- **TYPE:** DEVELOPMENT_SCHEMA_VERIFICATION_AND_GOVERNANCE_DOCUMENTATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e` (documentation changes remain uncommitted)
- **COMMIT:** N/A (no commit authorized or created)
- **ARTIFACTS:** `migraciones/v2/2026_10_09_000001_widen_snapshot_event_uuid.sql`; `migraciones/v2/2026_10_09_000002_add_snapshot_member_raw_reference_integrity.sql`; Level 1 DEVELOPMENT verification evidence; four canonical status documents.
- **TEST_RESULTS:** Event UUID focused validation 13 PASS / 0 FAIL; snapshot raw-reference migration static test 21 PASS / 0 FAIL; PHP lint PASS; `git diff --check` PASS. DEVELOPMENT schema/data verification passed: zero snapshot members, orphans, and duplicate groups; raw table count 7; no unexpected raw schema change.
- **DECISIONS:** Recorded `event_uuid` as FROZEN V1.0 (opaque immutable identifier, 1–64 UTF-8 characters after trim, RFC UUID syntax not required, stored capitalization preserved) and current device-ingestion implementation as complete. Focused validation passed for exact retry, divergent idempotency conflict, non-RFC event UUID, case-variant conflict, same UUID across devices, HMAC sealing, and HMAC fail-closed. Recorded migration 000001 as APPLIED + VERIFIED and migration 000002 as CLOSED_VERIFIED in DEVELOPMENT only. Closed the current Level 1 non-Telegram DEVELOPMENT slice. Preserved Portal + Telegram as FROZEN_DEFERRED; no production authorization, Telegram reactivation, code implementation, or database operation occurred during this documentation sync.
- **BLOCKERS:** Production verification/execution remains unauthorized and outside this activity.
- **NEXT_STEP:** V2.7 Pre-production Hardening & Cutover Readiness (only when explicitly authorized).

---

### Entry 038
- **DATE:** 2026-10-12
- **ACTIVITY_ID:** `CHECADOR_V2_7_SECURITY_CANONICAL_STATUS_RECONCILIATION`
- **PROJECT:** Checador API
- **TYPE:** GOVERNANCE_SECURITY_RECONCILIATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (documentation only; uncommitted)
- **ARTIFACTS:** `docs/status/CHECADOR_V2_CURRENT_STATUS.md`, `docs/status/CHECADOR_V2_IMPLEMENTATION_STATUS.md`, `docs/status/CHECADOR_V2_DELIVERY_ROADMAP.md`, `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`
- **TEST_RESULTS:** SEC-A DB Fail-Closed (12/12 PASS), SEC-B Acceso-Empresa Transport (15/15 PASS), SEC-D1 Method/Content/Payload Guards (20/20 PASS).
- **DECISIONS:** Reconciled canonical status documents with audited V2.7 security truth. Established that station/device identity is authenticated API credentials and internal resolution (`id_estacion`), NOT public IP. Formally recorded SEC-D8 (NAT Discovery) as `NOT_REQUIRED_FOR_NORMAL_ARCHITECTURE` and removed it from the critical path. Reconciled SEC-A as `CLOSED_VERIFIED` (12/12 PASS), SEC-B as `OPEN_TRANSITION` (dual-stack POST implemented, query fallback rejected, 15/15 PASS; 7/8 remote stations pending migration), SEC-C as `OPEN` (preproduction target `NOT_DEFINED`), SEC-D1 as `CLOSED_VERIFIED` (20/20 PASS), SEC-D3 as `SPECIFICATION_ONLY`, SEC-D4 as `CLOSED_SPECIFICATION_VERIFIED` (runtime `NOT_IMPLEMENTED`), SEC-E as `AUDIT_COMPLETE` / `SPECIFICATION_ONLY` (local runtime uses overprivileged `root@localhost`; zero grants executed). Confirmed that NO rate-limiting numerical thresholds are approved (ingest 20 req/s, burst 60 remains `MODELING_CANDIDATE_FOR_PREPRODUCTION`; auth 5 req/s, burst 15 is `NOT_APPROVED`; body limit 64 KB is `CANDIDATE_NOT_APPROVED` without batching justification). Reconciled Lector HTTP 429 recovery as `VERIFIED_SAFE_LOCAL_LAB` via durable atomic JSON disk queue (`%AppData%\AdmonGasLector\v2_punch_queue.json`). Cataloged legacy auth security debt (reversible encryption, plaintext session tokens, non-parameterized SQL, transitional GET).
- **BLOCKERS:** Pre-production target definition (SEC-C); project owner authorization for remote fleet cutover (SEC-B).
- **NEXT_STEP:** Await operational authorization before proceeding to SEC-B remote fleet cutover or SEC-C pre-production target definition.

---

### Entry 039
- **DATE:** 2026-10-12
- **ACTIVITY_ID:** `CHECADOR_V2_7_SEC_G_SECURITY_EVIDENCE_AND_DOCUMENTATION_ALIGNMENT`
- **PROJECT:** Checador API
- **TYPE:** GOVERNANCE_ALIGNMENT_AND_REGRESSION
- **STATUS:** CLOSED
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (documentation only; uncommitted)
- **ARTIFACTS:** `docs/status/CHECADOR_V2_CURRENT_STATUS.md`, `docs/status/CHECADOR_V2_DELIVERY_ROADMAP.md`, `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`
- **TEST_RESULTS:** SEC-L03 (34/34 PASS), SEC-A (12/12 PASS), SEC-B (15/15 PASS), SEC-D1 (20/20 PASS), SEC-F01 (12/12 PASS).
- **DECISIONS:** Formally aligned canonical governance documents with verified V2.7 security truth. Clarified that SEC-G canonically means `SECURITY_EVIDENCE_AND_DOCUMENTATION_ALIGNMENT`, distinct from rate-limit approval. Reran and verified SEC-L03 Portal3 supervisor authorization test suite (34 / 34 PASS in `tests/sec_l03_authorization_regression.php`). Reconciled full security control matrix across SEC-L03, SEC-A, SEC-B, SEC-C, SEC-D1, SEC-D3, SEC-D4, SEC-E, SEC-F01, SEC-F02, SEC-F03, SEC-F04. Confirmed zero rate-limiting numerical thresholds are approved (Candidate B: 20 req/s, burst 60 remains MODELING_CANDIDATE_FOR_PREPRODUCTION; auth candidate 5 req/s, burst 15 is NOT_APPROVED). Recorded remaining pre-production blockers: SEC-B remote terminal rollout (7 stations), SEC-C pre-production target definition, SEC-E least-privilege DB principal provisioning, SEC-F02 pre-production PHP error configuration verification, and SEC-F04 perimeter access log query string scrubbing.
- **BLOCKERS:** Pre-production target definition (SEC-C); project owner authorization for remote fleet cutover (SEC-B).
- **NEXT_STEP:** Execute SEC-B Fleet Cutover plan for the 7 remote physical terminals upon project owner authorization.

---

### Entry 040
- **DATE:** 2026-10-13
- **ACTIVITY_ID:** `CHECADOR_V2_API_POST_OCT12_STABILIZATION_AND_LEVEL2_READ_CONTINUATION`
- **PROJECT:** Checador API
- **TYPE:** CONTROLLED_STABILIZATION_AND_LEVEL2_READ_CONTINUATION
- **STATUS:** CLOSED
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (uncommitted)
- **ARTIFACTS:** `docs/status/CHECADOR_V2_CURRENT_STATUS.md`, `docs/status/CHECADOR_V2_DELIVERY_ROADMAP.md`, `docs/status/CHECADOR_V2_IMPLEMENTATION_STATUS.md`, `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`
- **TEST_RESULTS:** SRA Suite SRA01-SRA16 (16/16 PASS), Level 1 Snapshot Regression (21/21 PASS), Level 2 Server API (16/16 PASS), Level 2 Punch Integrity Ingestion (9/9 PASS), Punch Read Regression (11/11 PASS), SEC-L03 Authorization (34/34 PASS), Temporary Access Regression (62/62 PASS), Workplace Timezone Read Endpoint (23/23 PASS), Workplace Timezone Authority Runtime (25/25 PASS), Shift Scheduling Schema (20/20 PASS), PHP Lint (PASS).
- **DECISIONS:** Reconciled worktree provenance across 19 modified and 28 untracked files (zero unknown changes). Executed raw vs snapshot parity verification for user 999, workplace 1, local date 2026-10-12: raw read returned 0, snapshot read returned 4 (`RAW_SNAPSHOT_MEMBERSHIP_EQUAL: NO`). Established definitive root cause: predicate discrepancy in `SnapshotReadV2Service.php` (`OR ocurrido_at_utc IS NULL`), which returns all historical events with NULL timestamps for the station regardless of date window, whereas `PunchEventReadV2Controller.php` filters strictly by UTC timestamp range. Classified as `P1_SNAPSHOT_MEMBERSHIP_INCONSISTENCY` and stopped runtime functional progression per instructions. Confirmed snapshot authorization remediation to `biometricos.leer` + authorized workplace scope (SRA01-SRA16 16/16 PASS). Reconciled canonical Portal3 roadmap sequencing (PORTAL3-P03, PORTAL3-P04A/P3-A, PORTAL3-P04B/P3-B, PORTAL3-P04C/P3-C+D, PORTAL3-P04D/P3-E, PORTAL3-P05, PORTAL3-P06). Audited Level 2 read boundary `GET /api/v2/level2/window-status`: contract explicitly authorizes human User JWT (`biometricos.leer`), but runtime implementation defect delegates to `authorizeTemporaryAccess` (module `acceso-temporal`). Production remains unauthorized; no staging, commit, push, or schema/ACL changes executed.
- **BLOCKERS:** Resolution of `P1_SNAPSHOT_MEMBERSHIP_INCONSISTENCY` (predicate fix in `SnapshotReadV2Service.php`) and owner authorization for Level 2 controller remediation.
- **NEXT_STEP:** Owner authorization to remediate SnapshotReadV2Service predicate and align Level2ServerController authorization to `biometricos.leer`.

---

### Entry 041
- **DATE:** 2026-10-13
- **ACTIVITY_ID:** `CHECADOR_V2_API_P1_SNAPSHOT_PARITY_AND_LEVEL2_AUTH_REMEDIATION`
- **PROJECT:** Checador API
- **TYPE:** NARROW_CONTRACT_COMPLIANCE_FIX_AND_LEVEL2_AUTH_REMEDIATION
- **STATUS:** CLOSED_VERIFIED_DEVELOPMENT
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (uncommitted per strict instructions)
- **ARTIFACTS:** `modelo/v2/SnapshotReadV2Service.php`, `modelo/v2/Level2ServerController.php`, `modelo/v2/Level2ServerService.php`, `tests/v2_snapshot_parity_tests.php`, `tests/v2_level2_read_authorization_tests.php`, `docs/status/CHECADOR_V2_CURRENT_STATUS.md`, `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`, `docs/status/CHECADOR_V2_DELIVERY_ROADMAP.md`, `docs/status/CHECADOR_V2_IMPLEMENTATION_STATUS.md`, `docs/changelog/CHECADOR_V2_API_CHANGELOG.md`
- **TEST_RESULTS:** Snapshot Parity SP01-SP10 (14/14 PASS), Level 2 Read Auth L2R01-L2R20 (20/20 PASS), Snapshot Read Regression (21/21 PASS), Snapshot Auth Compliance SRA01-SRA16 (16/16 PASS), Level 2 Server API (16/16 PASS), Punch Integrity Ingestion (9/9 PASS), Punch Read API (11/11 PASS), SEC-L03 Authorization (34/34 PASS), Temporary Access Regression (62/62 PASS), Workplace Timezone Read Endpoint (23/23 PASS), Workplace Timezone Authority Runtime (25/25 PASS), Portal3 Security Suite (237/237 PASS), Portal3 P04 (32/32 PASS), Portal3 P03 (30/30 PASS), Portal3 Foundation (22/22 PASS), PHP Lint (PASS).
- **DECISIONS:**
  1. Defect 1 (P1): Removed `OR ocurrido_at_utc IS NULL` predicate from `SnapshotReadV2Service.php`, restoring exact 1:1 event membership parity between Level 1 Snapshots and Raw Punch Reads across arbitrary date windows. Excluded NULL timestamp rows from bounded snapshots per Contract §7.3. Verified live on `bd_admongas`: 0 raw events = 0 snapshot events.
  2. Defect 2: Changed authorization delegation in `Level2ServerController::handleGetWindowStatus()` from `authorizeTemporaryAccess()` to `authorizeBiometricos()` (`biometricos.leer`), aligning with the frozen contract (`docs/v2/LEVEL2_LECTOR_IMPLEMENTATION_HANDOFF_V1.0.md`).
  3. Normalized input UTC datetime strings in `Level2ServerService::evaluarVentanaNivel2()` to ensure MariaDB DATETIME compatibility.
  4. Preserved all invariant governance: Architecture Freeze SHA-256 intact, Level 1 Snapshot Contract SHA-256 intact, Level 2 Ingestion Contract SHA-256 intact. Zero persistent schema or ACL alterations. Zero production changes. Zero staging, commits, or push.
- **BLOCKERS:** None for Level 2 Window Status read. Next Portal3 integration slice (PORTAL3-P04B / P3-B) is unblocked. Remote fleet cutover (SEC-B) pending project owner authorization.
- **NEXT_STEP:** Owner authorization for PORTAL3-P04B / P3-B Level 2 Window Completeness UI/Read Integration in Portal3.

---

### Entry 042
- **DATE:** 2026-10-13
- **ACTIVITY_ID:** `CHECADOR_V2_API_SCHEDULING_AUTH_FINAL_REGRESSION_AND_WRITE_ROUTE_RECONCILIATION`
- **PROJECT:** Checador API
- **TYPE:** FINAL_REGRESSION_CLOSURE_AND_WRITE_ROUTE_RECONCILIATION
- **STATUS:** CLOSED_VERIFIED_DEVELOPMENT
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (uncommitted per strict instructions)
- **ARTIFACTS:** `modelo/v2/shift/ShiftSchedulingV2Controller.php`, `tests/v2_schedule_write_authorization_contract_tests.php`, `tests/v2_shift_scheduling_runtime_tests.php`, `tests/run_v2_temporary_access_tests.php`, `tests/v2_sec_d1_method_content_payload_tests.php`, `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`, `docs/status/CHECADOR_V2_CURRENT_STATUS.md`, `docs/changelog/CHECADOR_V2_API_CHANGELOG.md`
- **TEST_RESULTS:** Shift Scheduling Schema (20/20 PASS), Shift Scheduling Runtime (30/30 PASS), Timezone Authority Runtime (25/25 PASS), Timezone Read Endpoint (23/23 PASS), SEC-L03 Authorization (34/34 PASS), Snapshot Auth Compliance SRA01-SRA16 (16/16 PASS), Level 2 Read Authorization (20/20 PASS), Punch Read API (11/11 PASS), Temporary Access Regression (62/62 PASS), Effective Schedule Authorization (20/20 PASS), Schedule Write Auth Contract SWA01-SWA24 (24/24 PASS), PHP Lint (PASS), Portal3 Security Suite (237/237 PASS), Portal3 P04C Effective Schedule (30/30 PASS), Portal3 P04B Level 2 (30/30 PASS), Portal3 P04A Snapshot (32/32 PASS), Portal3 P03 Daily Read (30/30 PASS), Checador Access Foundation (22/22 PASS), Effective Schedule Real Smoke on `bd_admongas` (200 OK, `UNSCHEDULED`).
- **DECISIONS:**
  1. Restored disposable test database (`bd_admongas_v2_test_20261002`) capability and wrapped migration resets with `SET FOREIGN_KEY_CHECKS = 0/1` across tests without mutating persistent business DB `bd_admongas`.
  2. Reconciled all 5 Shift Scheduling write routes in `ShiftSchedulingV2Controller.php`, removing obsolete calls to nonexistent `Portal3JwtAuthHelper::authorizeUser()` and introducing canonical `authorizeScheduleWrite()` requiring valid Portal3 JWT + `biometricos.editar = 1` + station scope verification where applicable.
  3. Verified all 5 write routes contractually in `tests/v2_schedule_write_authorization_contract_tests.php` (24/24 PASS).
  4. Executed all previously deferred regression suites across API and Portal3, verifying 100% pass across all test dimensions.
  5. Validated effective schedule real smoke against development `bd_admongas` (HTTP 200, `estado_jornada: UNSCHEDULED`).
  6. Closed final API scheduling gate: `API_SCHEDULING_FINAL_GATE = CLOSED_VERIFIED_DEVELOPMENT`, opening `JOURNEY_GATE = OPEN` for `PORTAL3-P04D / P3-E`.
- **BLOCKERS:** NONE.
- **NEXT_STEP:** Proceed immediately to `PORTAL3-P04D / P3-E` ON_DEMAND Journey integration in Portal3.

---

### Entry 043
- **DATE:** 2026-10-13
- **ACTIVITY_ID:** `CHECADOR_V2_API_JOURNEY_ON_DEMAND_PORTAL3_BOUNDARY_V1`
- **PROJECT:** Checador API
- **TYPE:** MINIMAL_API_BOUNDARY_AND_JOURNEY_ORCHESTRATION
- **STATUS:** CLOSED_VERIFIED_DEVELOPMENT
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (uncommitted per strict instructions)
- **ARTIFACTS:** `docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.0.md`, `modelo/v2/journey/JourneyEvaluationV2Controller.php`, `index.php`, `modelo/v2/shift/ScheduleResolutionService.php`, `modelo/v2/journey/JourneyPairingService.php`, `tests/v2_journey_boundary_tests.php`, `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`, `docs/status/CHECADOR_V2_CURRENT_STATUS.md`, `docs/changelog/CHECADOR_V2_API_CHANGELOG.md`
- **TEST_RESULTS:** Journey Boundary JRB01-JRB30 (30/30 PASS), Journey Pairing Schema (25/25 PASS), Journey Pairing Runtime (16/16 PASS), Journey Calculation Schema (30/30 PASS), Journey Calculation Runtime (14/14 PASS), Shift Scheduling Schema (20/20 PASS), Shift Scheduling Runtime (30/30 PASS), Effective Schedule Authorization (20/20 PASS), Schedule Write Auth Contract (24/24 PASS), Timezone Authority Runtime (25/25 PASS), Timezone Read Endpoint (23/23 PASS), SEC-L03 Authorization (34/34 PASS), Snapshot Auth Compliance SRA01-SRA16 (16/16 PASS), Level 2 Read Authorization (20/20 PASS), Punch Read API (11/11 PASS), Temporary Access Regression (62/62 PASS), PHP Lint (PASS), Controlled Smoke Test on `bd_admongas` (HTTP 200, `evaluated: false`, `estado_jornada: UNSCHEDULED`, `journey_status: UNSCHEDULED_DAY`), Zero Persistent Mutations (0 jornadas programadas, 0 pareos, 0 calculos).
- **DECISIONS:**
  1. Authoritative Contract: Authored and froze `docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.0.md` (SHA-256: `9eda2b2c7174154d6524f7130ec197678958801df41004925a60928462876c9b`) specifying the server-to-server endpoint `POST /api/v2/journeys/evaluate`.
  2. Minimal Controller: Implemented `JourneyEvaluationV2Controller` orchestrating existing authoritative domains (`ScheduleResolutionService`, `ScheduledShiftMaterializationService`, `JourneyPairingService` [v2.4-p1], `JourneyCalculationService` [v2.4-p2], `Level2ServerService`, and `WorkplaceTimezoneAuthorityService`) without reimplementing algorithms.
  3. Security & Scope Enforcement: Enforced canonical Portal3 JWT validation (`iss: portal3_auth`, active human user, `biometricos.leer = 1`, and authorized workplace scope in `op_rh_localidades`). Rejected missing JWT (401), invalid JWT (401), missing permission (403), out-of-scope station (403), and Device Auth (401). Worker resolved strictly against `op_rh_personal.id` (unknown worker returns 404). Timezone resolved strictly from canonical workplace authority, ignoring client overrides.
  4. Contract Alignment Remediation: Enriched `ScheduleResolutionService.php` to populate `scheduled_start_utc` and `scheduled_end_utc` in resolved segments, and strengthened `JourneyPairingService.php` with defensive fallbacks for single-segment shift bounds and segment types.
  5. Invariants Preserved: Architecture Freeze SHA-256 intact (`87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`), zero database schema changes, zero persistent data mutations in `bd_admongas`, zero ACL modifications, zero production deployments, zero git commits or staging.
- **BLOCKERS:** NONE.
- **NEXT_STEP:** Proceed to `PORTAL3-P04D / P3-E` ON_DEMAND Journey UI and client integration in Portal3 repository.

---

### Entry 044
- **DATE:** 2026-10-13
- **ACTIVITY_ID:** `CHECADOR_V2_API_JOURNEY_PROVENANCE_BOUNDARY_V1_1`
- **PROJECT:** Checador API
- **TYPE:** ADDITIVE_CONTRACT_EVOLUTION_AND_PROVENANCE_BOUNDARY
- **STATUS:** CLOSED_VERIFIED_DEVELOPMENT
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (uncommitted per strict instructions)
- **ARTIFACTS:** `docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.1.md`, `modelo/v2/journey/JourneyPairingService.php`, `modelo/v2/journey/JourneyEvaluationV2Controller.php`, `tests/v2_journey_provenance_boundary_tests.php`, `docs/changelog/CHECADOR_V2_API_CHANGELOG.md`, `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`, `docs/status/CHECADOR_V2_CURRENT_STATUS.md`
- **TEST_RESULTS:** Journey Provenance Boundary JPV01-JPV30 + Phase 4/5 (33/33 PASS), Journey Boundary JRB01-JRB30 (30/30 PASS), Journey Pairing Schema (25/25 PASS), Journey Pairing Runtime (16/16 PASS), Journey Calculation Schema (30/30 PASS), Journey Calculation Runtime (14/14 PASS), Shift Scheduling Schema (20/20 PASS), Shift Scheduling Runtime (30/30 PASS), Effective Schedule Authorization (20/20 PASS), Schedule Write Auth Contract (24/24 PASS), Timezone Authority Runtime (25/25 PASS), Timezone Read Endpoint (23/23 PASS), SEC-L03 Authorization (34/34 PASS), Snapshot Auth Compliance (16/16 PASS), Level 2 Read Auth (20/20 PASS), Punch Read API (11/11 PASS), Temporary Access Regression (62/62 PASS), PHP Lint (PASS), Controlled Smoke Test on `bd_admongas` (HTTP 200, `evaluated: false`, `estado_jornada: UNSCHEDULED`, `journey_status: UNSCHEDULED_DAY`), Zero Persistent Mutations (0 jornadas programadas, 0 pareos, 0 calculos).
- **DECISIONS:**
  1. Contract V1.1 Frozen: Created additive contract `docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.1.md` (SHA-256: `7f71372d9deceb6820e6e71e1be38dadd12e87cd5bb4a5f69bcd250ee85c6f3f`) specifying evaluated candidate membership (`evaluated_raw_events`, `evaluated_raw_event_count`) and technical provenance (`provenance`, `p1_created_at_utc`, `p2_created_at_utc`, segment public event UUID links). V1.0 contract preserved unchanged (SHA-256: `9eda2b2c7174154d6524f7130ec197678958801df41004925a60928462876c9b`).
  2. Evaluated Candidate Set Implementation: Extended `JourneyPairingService` with `getEvaluatedCandidatesForPairing(int $pairingId): array` querying existing table `op_rh_v2_pareos_marcaciones_asociadas` joined with `op_rh_v2_marcaciones_crudas` (ordered strictly by `m.ocurrido_at_utc ASC, m.id ASC`, omitting internal raw PKs, preserving `id_dispositivo` nullable without forcing to 0). Added segment loading in `findExistingExecutionByFingerprint` for idempotent reuse.
  3. Controller Provenance Serialization: Updated `JourneyEvaluationV2Controller.php` to enrich segments with `entry_event_uuid` and `exit_event_uuid`, add `creado_at_utc` to pairing and calculation sections, expose `evaluated_raw_events` and `evaluated_raw_event_count` at root and in `provenance`, and return authoritative versions (`p1_algorithm_version: 'v2.4-p1'`, `p2_algorithm_version: 'v2.4-p2'`, `source_completeness_contract_version: 'v2.4'`, `level2_policy_ref: 'POLICY_DEFAULT'`, and `policy_rule_version: null` per strict anti-fabrication rule).
  4. Reproducibility & Isolation Verified: Verified Phase 4 direct reproducibility against `JourneyPairingService::getEvaluatedCandidatesForPairing()` and Phase 5 late punch isolation (punches arriving after pairing are not included in existing pairing revision's evaluated set).
  5. Invariants Preserved: Architecture Freeze SHA-256 intact (`87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`), zero DB schema changes (`DATABASE_SCHEMA_REQUIRED: NO`), zero modifications to Portal3 or Lector repositories, zero mutations in persistent `bd_admongas`, zero git staging, commits, or push.
- **BLOCKERS:** NONE. PORTAL3-P3F unblocked.
- **NEXT_STEP:** Proceed to Portal3 P3-F Journey Audit / Provenance integration in Portal3 repository.

---

### Entry 045
- **DATE:** 2026-10-14
- **ACTIVITY_ID:** `API_P3G_BOUNDARY_CONTRACT_FREEZE_V1_0`
- **PROJECT:** Checador API
- **TYPE:** CONTRACT_FREEZE
- **STATUS:** CLOSED_FROZEN_OWNER_APPROVED
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (uncommitted per strict instructions)
- **ARTIFACTS:** `docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md` (SHA-256: `e418687501fbdfdf89bcc6f11aab60e49226f265411c4590a39a5fa7799c374a`), `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`, `docs/status/CHECADOR_V2_CURRENT_STATUS.md`, `docs/changelog/CHECADOR_V2_API_CHANGELOG.md`
- **TEST_RESULTS:** N/A (Documentation and Contract Freeze Only; full forensic regression baseline 641 PASS / 0 FAIL / 0 SKIPPED across 32 active test suites; PHP Lint 38/38 PASS).
- **DECISIONS:**
  1. Companion Contract Frozen: Authored and froze `docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md` matching Portal3 companion contract `docs/v2/CHECADOR_V2_PORTAL3_P3G_INCIDENCE_ADMINISTRATIVE_CONTRACT_V1.0.md` (SHA-256: `e26e888015b27f9f1023e19cf4e005795836a99fd81a98c5638589fde5ca234d`).
  2. Architecture Option B Enforced: V2 is canonical for incidences, revisions, adjustments, and evaluations (`AdministrativeEvaluationService`); legacy vacation and permission sources are read-only evidence adapters; legacy incapacity automated adapter remains strictly BLOCKED (`BLOCKED_BY_SOURCE_AUTHORITY`); zero legacy dual-write (`LEGACY_DUAL_WRITE = NO`); payroll integration deferred (`PAYROLL_INTEGRATION = DEFERRED`).
  3. Trust & Security Boundary: Dedicated Portal3 backend -> API server-to-server boundary using Portal3 user-delegated JWT (`iss: portal3_auth`, active user, functional permission, multi-station workplace scope in `op_rh_localidades`). Direct browser access FORBIDDEN (`BROWSER_DIRECT_API = NO`); Device Auth FORBIDDEN (`DEVICE_AUTH_ALLOWED = NO`).
  4. Dedicated ACL Namespaces: Frozen semantics for `checador-v2-incidencias` (READ, CREATE), `checador-v2-incidencias-resolucion` (READ, RESOLVE/REVIEW), `checador-v2-incidencias-autorizacion` (READ, AUTHORIZE, REJECT, REVOKE/CANCEL). Generic `recursos-humanos.editar` has zero authority.
  5. Minimal API Surface: Defined 10 endpoints for read applicable incidences, single incident history, create incident, append-only revision, conflict resolution, authorization, rejection, append-only revocation/cancellation, read administrative evaluation, and evaluate shift administration.
  6. Immutability & Conflict Rules: Raw PunchEvent immutability preserved. Mandatory `REVIEW_REQUIRED` default for all conflicts and physical punch contradictions. Zero automatic precedence. Zero automatic `FALTA`; `FALTA` strictly requires human authorization.
  7. Invariants Preserved: Architecture Freeze SHA-256 intact (`87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`), zero runtime files modified (`RUNTIME_FILES_MODIFIED: NONE`), zero schema or DB changes (`DATABASE_MODIFIED: NO`, `SCHEMA_PREREQUISITE_REQUIRED: NO`), zero ACL rows created, zero production changes, zero git commits or staging.
- **BLOCKERS:** NONE.
- **NEXT_STEP:** Prepare the smallest P3-G implementation slice without reopening P03-P3F when implementation is explicitly authorized.

---

### Entry 046
- **DATE:** 2026-10-14
- **ACTIVITY_ID:** `CHECADOR_V2_API_P3G_A_READ_BOUNDARY_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** RUNTIME_READ_BOUNDARY_IMPLEMENTATION
- **STATUS:** CLOSED_VERIFIED_DEVELOPMENT
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (uncommitted per strict instructions)
- **ARTIFACTS:** `modelo/v2/AdministrativeIncidenceV2Controller.php`, `modelo/v2/Portal3JwtAuthHelper.php`, `index.php`, `tests/v2_p3g_administrative_read_boundary_tests.php`, `docs/changelog/CHECADOR_V2_API_CHANGELOG.md`, `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`, `docs/status/CHECADOR_V2_CURRENT_STATUS.md`
- **TEST_RESULTS:** P3G-A Read Boundary Suite P3GA01-P3GA32 (32/32 PASS), Journey Provenance Boundary JPV01-JPV30 (33/33 PASS), Journey Boundary JRB01-JRB30 (30/30 PASS), Level 2 Read Auth (20/20 PASS), Snapshot Parity (14/14 PASS), Snapshot Auth Compliance (16/16 PASS), Punch Read API (11/11 PASS), Workplace Timezone Read (23/23 PASS), Effective Schedule Auth (20/20 PASS), Schedule Write Auth (24/24 PASS), Strict Database Non-Mutation Verification (P3GA25 PASS, byte-identical before and after reads), PHP Lint (PASS, zero syntax errors), Real DEV Smoke on `bd_admongas`: `NOT_RUN_MISSING_DEV_ACL_CONFIGURATION` (zero persistent ACL rows created in shared development).
- **DECISIONS:**
  1. Smallest Safe Runtime Read Slice: Implemented exclusively the read capabilities frozen in `docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md` (`GET /api/v2/incidences`, `GET /api/v2/incidences/{uuid_incidencia}`, `GET /api/v2/administrative-evaluations`). Zero write endpoints implemented; all non-GET routes return 405 Method Not Allowed with `Allow: GET`.
  2. Pure Read Evaluation Boundary: `GET /api/v2/administrative-evaluations` queries persisted evaluations and linked applications/adjustments. If no evaluation exists, it returns `200 OK` with `evaluation_exists: false` and `evaluation: null` without triggering pairing (P1) or calculation (P2).
  3. Security & Scope Enforcement: Enforced Portal3 JWT authentication (`iss: portal3_auth`, active human user in `tb_usuarios`) and dedicated ACL authorization in `checador-v2-incidencias.leer`. Scoped strictly to authorized workplaces (`op_rh_localidades.id`); fail-closed on empty or out-of-scope stations (403 `STATION_SCOPE_DENIED`). Worker resolved strictly against `op_rh_personal.id` (404 `WORKER_NOT_FOUND`). Device Auth and direct browser access strictly rejected.
  4. Invariant Preservation: Implemented under Option B without legacy dual-write (`LEGACY_DUAL_WRITE = NO`). Prohibited automatic inference of `FALTA` or `SIN MARCACIÓN`. Preserved append-only history and deterministic ordering (`revision_numero ASC`). Preserved `REVIEW_REQUIRED` without synthetic precedence.
  5. Invariants Preserved: Architecture Freeze SHA-256 intact (`87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`), P3-G Contract SHA-256 intact (`e418687501fbdfdf89bcc6f11aab60e49226f265411c4590a39a5fa7799c374a`), zero DB schema changes, zero persistent data mutations in `bd_admongas`, zero ACL modifications in shared development, zero git commits or staging.
- **BLOCKERS:** NONE.
- **NEXT_STEP:** Authorize P3G-B Create and Revision Boundary implementation.

---

### Entry 047
- **DATE:** 2026-10-14
- **ACTIVITY_ID:** `CHECADOR_V2_API_P3G_B_CREATE_AND_REVISION_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** RUNTIME_MUTATION_BOUNDARY_IMPLEMENTATION
- **STATUS:** CLOSED_VERIFIED_DEVELOPMENT
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (uncommitted per strict instructions)
- **ARTIFACTS:** `modelo/v2/AdministrativeIncidenceV2Controller.php`, `modelo/v2/journey/AdministrativeEvaluationService.php`, `modelo/v2/Portal3JwtAuthHelper.php`, `index.php`, `tests/v2_p3g_b_create_and_revision_tests.php`, `tests/v2_p3g_administrative_read_boundary_tests.php`, `docs/changelog/CHECADOR_V2_API_CHANGELOG.md`, `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`, `docs/status/CHECADOR_V2_CURRENT_STATUS.md`
- **TEST_RESULTS:** P3G-B Suite P3GB01-P3GB35 (35/35 PASS), P3G-A Read Boundary Suite P3GA01-P3GA32 (32/32 PASS), Journey Provenance Boundary JPV01-JPV30 (33/33 PASS), Journey Boundary JRB01-JRB30 (30/30 PASS), Level 2 Read Auth (20/20 PASS), Snapshot Parity (14/14 PASS), Snapshot Auth Compliance (16/16 PASS), Punch Read API (11/11 PASS), Workplace Timezone Read (23/23 PASS), Effective Schedule Auth (20/20 PASS), Schedule Write Auth (24/24 PASS). Total: 258 PASS / 0 FAIL / 0 SKIPPED. PHP Lint (PASS, zero syntax errors). Real DEV Smoke on `bd_admongas`: `NOT_RUN_MISSING_DEV_ACL_CONFIGURATION` (zero persistent ACL rows created in shared development).
- **DECISIONS:**
  1. Focused P3G-B Implementation: Implemented exclusively the create and append-only revision endpoints frozen in `docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md` (`POST /api/v2/incidences` and `POST /api/v2/incidences/{uuid_incidencia}/revisions`).
  2. Idempotency Contract Enforced: Required `Idempotency-Key` header on all mutating endpoints. Stored metadata cleanly in `motivo` without schema changes, stripped via `extractCleanReason` on read views. Identical replays return existing records (201/200); divergent replays fail closed with 409 `IDEMPOTENCY_CONFLICT`.
  3. Optimistic Concurrency Control: Required `expected_current_revision` on revision creation; mismatches fail closed with 409 `STALE_REVISION_CONFLICT`.
  4. Append-Only Immutability: Prior revisions in `op_rh_v2_incidencias_revisiones` remain 100% byte-for-byte identical; new revision rows are strictly inserted, never updating or deleting existing revision records.
  5. Security & Delegation: Derived actor strictly from Portal3 JWT `sub` (`creado_por_usuario_id`). Client-supplied actor IDs are completely ignored. Functional permission enforced via `checador-v2-incidencias.crear = 1` for creation, and `checador-v2-incidencias-resolucion.editar = 1 OR checador-v2-incidencias.crear = 1` for revisions. Multi-station workplace scoping verified. Device Auth and direct browser access strictly rejected.
  6. Forbidden Lifecycles Protected: Endpoints for resolve, authorize, reject, revoke, and evaluate remain 405 Method Not Allowed. Lifecycle transition attempts via revision creation rejected with 422 `LIFECYCLE_TRANSITION_FORBIDDEN`.
  7. Invariants Preserved: Architecture Freeze SHA-256 intact (`87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`), P3-G Contract SHA-256 intact (`e418687501fbdfdf89bcc6f11aab60e49226f265411c4590a39a5fa7799c374a`), zero DDL migrations, zero legacy dual-write (`LEGACY_DUAL_WRITE = NO`), zero punch mutations, zero payroll writes (`PAYROLL_WRITE = NO`), zero automatic `FALTA` (`AUTOMATIC_FALTA = NO`), zero git commits or staging.
- **BLOCKERS:** P3G-C/D/E (Resolution, Authorization, Revocation, Administrative Evaluation) remain deferred until authorized.
- **NEXT_STEP:** Report completion of P3G-B Create and Revision Boundary implementation; keep advanced lifecycle endpoints blocked.

---

### Entry 048
- **DATE:** 2026-10-14
- **ACTIVITY_ID:** `CHECADOR_V2_API_P3G_C_ADMINISTRATIVE_LIFECYCLE_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** RUNTIME_LIFECYCLE_IMPLEMENTATION
- **STATUS:** CLOSED_VERIFIED_DEVELOPMENT
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (uncommitted per strict instructions)
- **ARTIFACTS:** `modelo/v2/AdministrativeIncidenceV2Controller.php`, `modelo/v2/journey/AdministrativeEvaluationService.php`, `modelo/v2/Portal3JwtAuthHelper.php`, `index.php`, `tests/v2_p3g_c_lifecycle_actions_tests.php`, `tests/v2_p3g_b_create_and_revision_tests.php`, `tests/v2_p3g_administrative_read_boundary_tests.php`, `docs/changelog/CHECADOR_V2_API_CHANGELOG.md`, `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`
- **TEST_RESULTS:** P3G-C Suite P3GC01-P3GC35 (35/35 PASS), P3G-B Suite P3GB01-P3GB35 (35/35 PASS), P3G-A Read Boundary Suite P3GA01-P3GA32 (32/32 PASS), Journey Provenance Boundary JPV01-JPV30 (33/33 PASS), Journey Boundary JRB01-JRB30 (30/30 PASS), Level 2 Read Auth (20/20 PASS), Snapshot Parity (14/14 PASS), Snapshot Auth Compliance (16/16 PASS), Punch Read API (11/11 PASS), Workplace Timezone Read (23/23 PASS), Effective Schedule Auth (20/20 PASS), Schedule Write Auth (24/24 PASS). Total: 293 PASS / 0 FAIL. PHP Lint (PASS, zero syntax errors). Real DEV Smoke on `bd_admongas`: `NOT_RUN_MISSING_DEV_ACL_CONFIGURATION` (zero persistent ACL rows created in shared development).
- **DECISIONS:**
  1. Focused P3G-C Implementation: Implemented exclusively the 4 lifecycle action endpoints frozen in `docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md` (`POST /api/v2/incidences/{uuid}/resolve`, `POST /api/v2/incidences/{uuid}/authorize`, `POST /api/v2/incidences/{uuid}/reject`, `POST /api/v2/incidences/{uuid}/revoke`).
  2. Privilege Separation: Enforced strict privilege separation: `checador-v2-incidencias-resolucion.editar = 1` for resolve (transitions to `RESOLVED_PENDING_AUTHORIZATION`); `checador-v2-incidencias-autorizacion.editar = 1` for authorize (transitions to `ACTIVE`) and reject (transitions to `REJECTED`); `checador-v2-incidencias-autorizacion.eliminar = 1 OR editar = 1` for revoke (transitions to `CANCELLED`). Resolution or creation permissions alone cannot authorize (403 `FUNCTION_PERMISSION_DENIED`).
  3. Ordered Invariant Checks: Enforced validation order: 1) Idempotency check with semantic hash matching, 2) Optimistic concurrency control via `expected_current_revision` check (409 `STALE_REVISION_CONFLICT`), 3) Lifecycle state transition check (409 `INVALID_LIFECYCLE_TRANSITION`).
  4. Append-Only Lifecycle History: Every action creates a new revision record in `op_rh_v2_incidencias_revisiones` and updates `revision_actual` / `estado_actual` in `op_rh_v2_incidencias_maestro`. Prior revisions remain completely immutable and visible in history.
  5. Evaluation Command Blocked: `POST /api/v2/administrative-evaluations/evaluate` remains 405 Method Not Allowed (`Allow: GET`).
  6. Invariants Preserved: Architecture Freeze SHA-256 intact (`87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`), P3-G Contract SHA-256 intact (`e418687501fbdfdf89bcc6f11aab60e49226f265411c4590a39a5fa7799c374a`), zero DDL migrations, zero legacy dual-write (`LEGACY_DUAL_WRITE = NO`), zero punch mutations, zero payroll writes (`PAYROLL_WRITE = NO`), zero automatic `FALTA` (`AUTOMATIC_FALTA = NO`), zero git commits or staging.
- **BLOCKERS:** Administrative Evaluation execution command (`POST /api/v2/administrative-evaluations/evaluate`) remains deferred until authorized.
- **NEXT_STEP:** Await project owner authorization before proceeding to P3G-D/E administrative evaluation execution slice.

---

### Entry 049
- **DATE:** 2026-10-14
- **ACTIVITY_ID:** `CHECADOR_V2_API_P3G_D_ADMINISTRATIVE_EVALUATION_EXECUTION_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** RUNTIME_EXECUTION_BOUNDARY_IMPLEMENTATION
- **STATUS:** CLOSED_VERIFIED_DEVELOPMENT
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (uncommitted per strict instructions)
- **ARTIFACTS:** `modelo/v2/AdministrativeIncidenceV2Controller.php`, `modelo/v2/journey/AdministrativeEvaluationService.php`, `modelo/v2/Portal3JwtAuthHelper.php`, `index.php`, `tests/v2_p3g_d_evaluation_execution_tests.php`, `tests/v2_p3g_c_lifecycle_actions_tests.php`, `tests/v2_p3g_b_create_and_revision_tests.php`, `tests/v2_p3g_administrative_read_boundary_tests.php`, `docs/changelog/CHECADOR_V2_API_CHANGELOG.md`, `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`
- **TEST_RESULTS:** P3G-D Suite P3GD01-P3GD40 (40/40 PASS), P3G-C Suite P3GC01-P3GC35 (35/35 PASS), P3G-B Suite P3GB01-P3GB35 (35/35 PASS), P3G-A Read Boundary Suite P3GA01-P3GA32 (32/32 PASS), Journey Provenance Boundary JPV01-JPV30 (33/33 PASS), Journey Boundary JRB01-JRB30 (30/30 PASS), Level 2 Read Auth (20/20 PASS), Snapshot Parity (14/14 PASS), Snapshot Auth Compliance (16/16 PASS), Punch Read API (11/11 PASS), Workplace Timezone Read (23/23 PASS), Effective Schedule Auth (20/20 PASS), Schedule Write Auth (24/24 PASS). Total: 333 PASS / 0 FAIL. PHP Lint (PASS, zero syntax errors). Real DEV Smoke on `bd_admongas`: `NOT_RUN_MISSING_DEV_ACL_CONFIGURATION` (zero persistent ACL rows created in shared development).
- **DECISIONS:**
  1. Focused P3G-D Implementation: Activated exclusively the frozen administrative-evaluation execution command (`POST /api/v2/administrative-evaluations/evaluate`) strictly per Contract Section 9.11. Non-POST methods return 405 Method Not Allowed with header `Allow: POST`.
  2. Consumption of Existing Provenance: The execution consumes existing, immutable P2 journey calculation headers (`op_rh_v2_calculos_jornada`) and active incidence applications (`op_rh_v2_incidencias_aplicaciones`). Zero recomputation of P1 pairing, zero recomputation of P2 calculations, zero alteration of raw punch membership.
  3. Compuerta Humana para FALTA (AUTOMATIC_FALTA = NO): Enforced contract Section 8.2 invariant that zero punches + scheduled shift + Level 2 VERIFIED + no incidences alone NEVER emits `FALTA`. Emits `REVIEW_REQUIRED` with `human_review_status = 'PENDING_REVIEW'`. Final `FALTA` classification strictly requires `checador-v2-incidencias-autorizacion.editar = 1` and explicit `authorize_falta: true`. Callers with resolution permission alone attempting to authorize FALTA are rejected with 403 `FUNCTION_PERMISSION_DENIED`.
  4. Idempotency & Immutability: Enforced mandatory `Idempotency-Key` header (max 64 chars). Reused key with identical semantic payload returns cached 200 without duplicate evaluation records; divergent payload returns 409 `IDEMPOTENCY_CONFLICT`. Hard immutability database triggers (`tg_op_rh_v2_evaluaciones_admin_no_update`, `tg_op_rh_v2_evaluaciones_admin_no_delete`) confirmed active and preventing update/delete operations.
  5. Policy Rule Version Invariant: Returned `policy_rule_version: null` in response payload when no independent policy rule version is defined.
  6. Invariants Preserved: Architecture Freeze SHA-256 intact (`87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`), P3-G Contract SHA-256 intact (`e418687501fbdfdf89bcc6f11aab60e49226f265411c4590a39a5fa7799c374a`), zero DDL migrations, zero legacy dual-write (`LEGACY_DUAL_WRITE = NO`), zero punch mutations, zero payroll writes (`PAYROLL_WRITE = NO`), zero git commits or staging.
- **BLOCKERS:** NONE. Core P3-G API boundary slices (P3G-A, P3G-B, P3G-C, P3G-D) are now fully implemented and verified.
- **NEXT_STEP:** Report completion of P3G-D Administrative Evaluation Execution Implementation to project owner; await next milestone authorization.

---

### Entry 050
- **DATE:** 2026-10-14
- **ACTIVITY_ID:** `CHECADOR_V2_API_P3H_EVENT_CLASSIFICATION_CONTRACT_FREEZE_V1_0`
- **PROJECT:** Checador API
- **TYPE:** DOCUMENTATION_CONTRACT_FREEZE
- **STATUS:** FROZEN_OWNER_APPROVED
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (uncommitted per strict instructions)
- **ARTIFACTS:** `docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.0.md` (SHA-256: `c710165953f455352cf5675b7581a581be03a632a493e6a0c939276865d6658f`), `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`, `docs/changelog/CHECADOR_V2_API_CHANGELOG.md`
- **DECISIONS:**
  1. Contract Freeze: Formally froze additive public payload contract `docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.0.md` at status `FROZEN_OWNER_APPROVED` following explicit Project Owner approval.
  2. Public Additive Fields: Defined `pairing_role` (string, nullable) and `segment_ordinal` (integer, nullable) inside each item of `data.evaluated_raw_events[]` and mirrored in `data.provenance.evaluated_raw_events[]` of `GET /api/v2/journeys/on-demand`.
  3. Canonical Values: Allowed values for `pairing_role` strictly restricted to `ENTRY`, `EXIT`, `SURPLUS`, `UNMATCHED`. No punch-level `AMBIGUOUS` role is permitted (`AMBIGUITY_AS_PAIRING_ROLE = NO`).
  4. Ambiguity Scope: Ambiguity remains strictly scoped at segment and/or Journey level (`AMBIGUOUS_PAIRING`). Punches in an ambiguous segment retain their candidate physical pairing roles.
  5. Presentation Labels Approved: Portal3 Spanish presentation mapping approved: `ENTRY` -> 'Entrada', `EXIT` -> 'Salida', `SURPLUS` -> 'Marcación adicional', `UNMATCHED` -> 'Sin emparejar'.
  6. Invariants Preserved: Zero runtime code changes, zero database mutations, zero new schema (`NEW_SCHEMA_REQUIRED = NO`), zero recomputation (`REQUIRES_P1_RECOMPUTATION = NO`, `REQUIRES_P2_RECOMPUTATION = NO`), zero internal database PK exposure (`INTERNAL_RAW_PK_EXPOSED = NO`), multi-segment (3+) and 5+ event support confirmed, four-slot UI confirmed as presentation projection only without automatic FALTA or SIN MARCACIÓN inference.
- **BLOCKERS:** NONE. `OWNER_DECISION_REQUIRED: NO`. `RUNTIME_IMPLEMENTATION_READY: YES`.
- **NEXT_STEP:** Reconcile and freeze companion Portal3 contract `docs/v2/CHECADOR_V2_PORTAL3_P3H_PRESENTATION_PROJECTION_CONTRACT_V1.0.md` before authorizing runtime implementation.

---

### Entry 051
- **DATE:** 2026-10-14
- **ACTIVITY_ID:** `CHECADOR_V2_API_P3H_EVENT_CLASSIFICATION_PAYLOAD_IMPLEMENTATION`
- **PROJECT:** Checador API
- **TYPE:** RUNTIME_ENRICHMENT_IMPLEMENTATION
- **STATUS:** CLOSED_VERIFIED_DEVELOPMENT
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (uncommitted per strict instructions)
- **ARTIFACTS:** `modelo/v2/journey/JourneyPairingService.php`, `tests/v2_p3h_event_classification_payload_tests.php`, `docs/changelog/CHECADOR_V2_API_CHANGELOG.md`, `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`, `docs/status/CHECADOR_V2_CURRENT_STATUS.md`
- **TEST_RESULTS:** Focused P3-H Suite P3HEC01-P3HEC30 + 2 SHA assertions (32/32 PASS), Journey Provenance Boundary JPV01-JPV30 (33/33 PASS), Journey Boundary JRB01-JRB30 (30/30 PASS), P3G-A Read Boundary (32/32 PASS), P3G-B Create & Revision (35/35 PASS), P3G-C Lifecycle Actions (35/35 PASS), P3G-D Evaluation Execution (40/40 PASS), Level 2 Read Auth (20/20 PASS), Snapshot Parity (14/14 PASS), Snapshot Auth Compliance (16/16 PASS), Punch Read API (11/11 PASS), Workplace Timezone Read (23/23 PASS), Effective Schedule Auth (20/20 PASS), Schedule Write Auth (24/24 PASS), SEC-L03 Auth (34/34 PASS), Temporary Access (62/62 PASS). Total: 467 PASS / 0 FAIL. PHP Lint (0 syntax errors).
- **DECISIONS:**
  1. Additive Payload Implementation: Implemented backward-compatible enrichment of evaluated raw events in `POST /api/v2/journeys/evaluate` by adding `pairing_role` (`ENTRY`, `EXIT`, `SURPLUS`, `UNMATCHED`) and `segment_ordinal` (`integer|null`) per frozen contract `docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.0.md`.
  2. Data Extraction via Left Join: Modified `JourneyPairingService::getEvaluatedCandidatesForPairing` to perform `LEFT JOIN op_rh_v2_pareos_segmentos s ON a.id_pareo_segmento = s.id` over `op_rh_v2_pareos_marcaciones_asociadas a` and `op_rh_v2_marcaciones_crudas m`, deterministically ordered by `m.ocurrido_at_utc ASC, m.id ASC`.
  3. Mirror Parity: Ensured 100% element-by-element and field-by-field parity between primary `data.evaluated_raw_events[]` and provenance `data.provenance.evaluated_raw_events[]`.
  4. Ambiguity Scope Preserved: Confirmed that ambiguous segments retain candidate physical pairing roles without using `AMBIGUOUS_PAIRING` as a punch-level role (`AMBIGUITY_AS_PAIRING_ROLE = NO`).
  5. Invariants Preserved: Zero P1/P2 recomputations (`REQUIRES_P1_RECOMPUTATION = NO`, `REQUIRES_P2_RECOMPUTATION = NO`), zero schema modifications (`NEW_SCHEMA_REQUIRED = NO`), zero internal raw database PK exposure (`INTERNAL_RAW_PK_EXPOSED = NO`), zero punch mutations, zero legacy writes, zero automatic FALTA or SIN MARCACIÓN inference.
- **BLOCKERS:** NONE.
- **NEXT_STEP:** Ready for Portal3 P3-H UI presentation runtime implementation consuming the enriched API payload.

---

### Entry 052
- **DATE:** 2026-10-14
- **ACTIVITY_ID:** `CHECADOR_V2_API_P3H_EVENT_CLASSIFICATION_CONTRACT_FREEZE_V1_1`
- **PROJECT:** Checador API
- **TYPE:** DOCUMENTATION_CONTRACT_FREEZE
- **STATUS:** CLOSED_FROZEN_OWNER_APPROVED
- **BASE_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **FINAL_HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e`
- **COMMIT:** N/A (uncommitted per strict instructions)
- **ARTIFACTS:** `docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.1.md` (SHA-256: `df6b0bec7c6977cb0c12bf5409c8243528af145036474aa3adcf237b21f4711c`), `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md`, `docs/changelog/CHECADOR_V2_API_CHANGELOG.md`, `docs/status/CHECADOR_V2_CURRENT_STATUS.md`
- **TEST_RESULTS:** N/A (Documentation freeze only; TESTS_RERUN = NO)
- **DECISIONS:**
  1. Contract Freeze V1.1: Formally froze API contract amendment `docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.1.md` at status `FROZEN_OWNER_APPROVED` following explicit Project Owner approval on 2026-10-14.
  2. Erroneous Reference Corrected: Reconciled target endpoint reference from erroneous draft value `GET /api/v2/journeys/on-demand` to canonical, pre-existing implemented endpoint `POST /api/v2/journeys/evaluate`.
  3. Predecessor Contract Preserved: Predecessor contract V1.0 (`docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.0.md`, SHA-256: `c710165953f455352cf5675b7581a581be03a632a493e6a0c939276865d6658f`) preserved byte-for-byte as immutable historical evidence.
  4. Supersession Scope: V1.1 supersedes V1.0 exclusively in the Journey endpoint reference. All other semantics (`pairing_role` [`ENTRY`, `EXIT`, `SURPLUS`, `UNMATCHED`], `segment_ordinal`, ambiguity scoping, multi-segment 3+ and 5+ punch scaling, zero automatic FALTA or SIN MARCACIÓN) remain 100% identical.
  5. Invariants Preserved: Zero runtime code changes (`RUNTIME_FILES_MODIFIED: NONE`), zero route changes or aliases (`RUNTIME_ROUTE_CHANGE_REQUIRED: NO`, `NEW_API_ENDPOINT_REQUIRED: NO`), zero database modifications, zero schema changes, zero ACL modifications.
- **BLOCKERS:** NONE.
- **NEXT_STEP:** Create and freeze companion Portal3 P3-H contract V1.1 binding `FINAL_API_P3H_V1_1_SHA256` (`df6b0bec7c6977cb0c12bf5409c8243528af145036474aa3adcf237b21f4711c`).

### Entry 054
- **DATE:** 2026-11-02
- **ACTIVITY_ID:** `CHECADOR_V2_M03_AUTHORIZED_TEMPLATE_BOOTSTRAP_EXECUTION_V1`
- **PROJECT:** Portal3 + Checador API V2
- **TYPE:** DEVELOPMENT_ONLY_SHIFT_TEMPLATE_BOOTSTRAP
- **STATUS:** CLOSED_VERIFIED_DEVELOPMENT; NO WORKER ASSIGNMENTS CREATED
- **BASE_HEAD / FINAL_HEAD:** unchanged; implementation remains local and uncommitted.
- **AUTHORIZATION:** Explicit owner authorization to amend only §7 of the identical Portal3/API Shift Scheduling Authorization Addendum V1.0 copies and run the bootstrap CLI in local Fedora development. The Architecture Freeze, HTTP POST authorization, normal template-admin permission, user ACL, Lector, production, Block B, and J2 runtime remain unchanged.
- **CONTRACT:** §7 now permits only a CLI-only initial bootstrap/idempotent verification for the approved Interlomas legacy catalog in `APP_ENV=dev` and exact local database `bd_admongas`; preview is the default, `--apply` is explicit, and provenance, deterministic keys, transaction, lock, no-overwrite conflict handling, and exact readback are required. Portal3/API copies are byte-identical (SHA-256 `09ec77ee0ff76279df48f3efb04579cbd15f9f625230de5bc7c99a7884717f40`).
- **SOURCE AND RESULT:** Revalidated 17 legacy rows: 15 positive work intervals, 12 unique time-pair definitions, and two zero-duration administrative rows (`VACACIONES`, `INCAPACIDAD`) excluded. Created 12 V2 templates and 12 work segments, including the two distinct overnight pairs; no rest days, worker assignments, memberships, or effective dates were inferred. Provenance manifest: `docs/status/CHECADOR_V2_M03_LEGACY_TEMPLATE_BOOTSTRAP_MANIFEST.json`.
- **DATABASE:** Verified local `APP_ENV=dev`, MariaDB on Fedora, and exact `bd_admongas` identity before the write. First explicit apply created 12 templates/segments. Readback: 12 templates, 12 segments, 0 assignments, 89 raw punches and 89 distinct UUIDs. Two subsequent applies returned `created=0`, `matched_exact=12`, `conflicts=[]`; no duplicates or overwrites.
- **PORTAL3:** The existing weekly schedule view now displays template segment hours and next-day offsets and exposes the approved catalog in the assignment selector without granting global template administration. Authenticated Playwright passed for Encargado ID 33 and Dirección ID 19; both showed 46 workers and 12 templates at 1440/1024/768/375 px. Encargado selected a night template without submitting; Dirección remained read-only. No template or assignment mutation was submitted; the E2E request guard allowed only the existing module-context selection POST, if emitted.
- **TEST_RESULTS:** Bootstrap helper 14/14 PASS; Shift Scheduling authorization 23/23 PASS; Block A read scope 25/25 PASS; membership coverage 8/8 PASS; Portal M03 capture regression 21/21 PASS; Portal UI Quality Gate fixture 36/36 PASS after successful authenticated E2E evidence; PHP/Node syntax checks PASS.
- **INVARIANTS:** The global API POST gate remains off; the schedule-only development gate and existing ACL remain unchanged; no global template-admin grant; no assignment, Journey, payroll, membership, production, or canonical punch write; all 89 original punch UUIDs remain intact. No Lector, Architecture Freeze, schema, commit, or push change.
- **BLOCKERS:** None for the approved catalog bootstrap and Portal3 selection. Individual worker scheduling remains separate and requires an explicit authorized assignment and verified work schedule. Human visual approval remains pending.

### Entry 053
- **DATE:** 2026-11-02
- **ACTIVITY_ID:** `CHECADOR_V2_M03_STATION_SCHEDULING_AUTHORIZED_IMPLEMENTATION_V1`
- **PROJECT:** Portal3 + Checador API V2
- **TYPE:** SHIFT_SCHEDULING_AUTHORIZATION_AND_STATION_MANAGER_IMPLEMENTATION
- **STATUS:** IMPLEMENTED_VERIFIED_DEVELOPMENT; REAL SCHEDULE WRITE NOT VERIFIED
- **BASE_HEAD / FINAL_HEAD:** `9d8cb77fccb0b54828a38f602af258ecefc1d390` (unchanged; work remains uncommitted as required).
- **AUTHORIZATION:** Explicit user authorization to amend the Shift Scheduling permission contract and implement station-manager scheduling in local development. Shared Architecture Freeze, Lector, API Block B, J2 runtime, M07, and production remain unchanged/out of scope.
- **CONTRACT:** Added the identical, narrowly scoped `CHECADOR_V2_SHIFT_SCHEDULING_AUTHORIZATION_ADDENDUM_V1.0.md` to API and Portal3. SHA-256: `c717482d5402a3d41d7fe9d932f39d0722a67bdd6a26bfa65c018ec0162c2253`. New capabilities are `checador.programacion.leer`, `checador.programacion.editar`, and separately granted `checador.plantillas.administrar`. Position 6 receives read/edit only within its active mapped home station; position 13 receives read-only access to its active mapped station scope. Global template administration is never implied by scheduling edit.
- **IMPLEMENTATION:** API adds fresh identity/permission/scope authorization, physical-station-to-canonical-locality mapping, membership interval validation, assignment replacement/close handling, separate template authorization, and explicit day-off support. Portal3 adds a dedicated permission reader, station-scoped access checks, CSRF-protected schedule handlers, template capture behind its separate permission, per-date worker eligibility and API readback. Comodines retains its existing `biometricos.*` authorization path.
- **DEVELOPMENT DATABASE:** Confirmed `APP_ENV=dev`, `bd_admongas` on local Fedora. Applied reversible/idempotent API migrations `2026_11_02_000001_create_v2_shift_scheduling_permission_grants.sql` and `2026_11_02_000002_allow_explicit_day_off_assignments.sql`. Position grants are limited to (6, read), (6, edit), and (13, read); no template-admin grant was created. Post-migration readback: 0 shift templates and 0 shift assignments, same counts as preflight. No labor schedule was fabricated or persisted.
- **AUTHORIZATION FACTS:** Encargado user 33 / position 6 has schedule read/edit for canonical locality 1 only; Dirección user 19 / position 13 has schedule read, no edit, and station-only supervision scope. Both retain their previous biometric permissions; Encargado receives no global template-admin capability. The local write gate is false, so the assignment form remains hidden and no write was attempted.
- **TEST_RESULTS:** API scheduling authorization 23/23 PASS; Block A assignment read-scope regression 25/25 PASS; worker membership coverage 8/8 PASS. Portal M03 capture regression 16/16 PASS; Access Foundation 57/57 PASS; Portal UI Quality Gate 36/36 PASS. Authenticated read-only Playwright: Encargado 1/1 PASS, Dirección 1/1 PASS; 46 rows visible per profile, 1440/1024/768/375 screenshots captured, no browser writes. Relevant PHP and JavaScript syntax checks PASS.
- **INVARIANTS:** API Block A and GET guard P0 preserved; no cursor/Block B, no J2, no M07, no Lector, no change to frozen Architecture Freeze or frozen Shift Scheduling service/model contracts, no payroll or raw-punch writes, no change to the 89 canonical PunchEvents. No git staging, commit, or push.
- **BLOCKERS:** No RH-confirmed shift/template data exists; no authorized template-administrator identity/grant was available; local schedule-write gate remains disabled. Therefore template creation, assignment create/edit/close through real browser/API, and persisted readback have not been operationally verified. Human visual approval remains pending.
- **NEXT_STEP:** Obtain RH-approved template/segments and effective dates plus the legitimate template-administrator authorization; then enable the approved local write gate through its existing authorized process and verify one real assignment end-to-end. Do not treat empty-table reads or passing mocks as operational schedule data.

### Entry 055
- **DATE:** 2026-11-02
- **ACTIVITY_ID:** `CHECADOR_V2_M03_FIRST_REAL_ASSIGNMENT_E2E_V1`
- **PROJECT:** Portal3 + Checador API V2
- **TYPE:** DEVELOPMENT_ONLY_QA_SHIFT_ASSIGNMENT_AND_READBACK
- **STATUS:** CLOSED_VERIFIED_DEVELOPMENT_QA; NOT AN OFFICIAL LABOR SCHEDULE
- **AUTHORIZATION / SCOPE:** The user explicitly authorized one development-only QA assignment through the existing Portal3 flow in Fedora `portal3.test` / `bd_admongas`: authenticated Encargado ID 33, canonical Interlomas locality 1, QA worker 1004, active template 3/version 1, and future validity `[2026-11-03, 2026-11-04)`. No additional assignments, production work, Lector, M07, Block B, or J2 runtime were authorized.
- **API RESPONSE CONTRACT:** `ShiftAssignmentService` now returns persisted `creado_at` and `registrado_at_utc` values for new rows and its exact-match replay branch. New-row response timestamps are read back from the inserted row inside the transaction; missing persisted audit values fail closed. Portal3 requires API HTTP 201 and valid persisted timestamp values before reporting success, then performs API readback. Existing response fields are retained. Portal3 sends `Idempotency-Key`, but the API does not consume that header; this is not header-based idempotency.
- **PERSISTED RESULT:** Portal3's authorized form flow received API HTTP 201 and its API readback matched. MariaDB read-only verification found exactly one assignment: ID 1, worker 1004, workplace 1, template 3, Tuesday, valid from `2026-11-03` until exclusive `2026-11-04`, active, `creado_at=2026-11-02 13:44:53`, `registrado_at_utc=2026-11-02 19:44:53`. The row is QA/development evidence, not an official labor obligation, payroll result, or Journey evaluation.
- **PORTAL3 / BROWSER:** Authenticated Chrome showed the schedule after the real form submission and after reload. The row rendered in Tuesday's weekly cell as `Legacy Interlomas — BANDERERO DE APOYO 1 — 08:00–14:00`. A duplicate resubmission was detected by Portal3's existing API-readback guard and no second row was created. This browser check does not exercise the API's DB-backed exact-replay branch. Tampered workplace 2 was rejected before any API schedule POST; its warning is bound to the validated session locality. Dirección ID 19 remained read-only and saw the assignment; Encargado ID 33 retained only Interlomas edit scope and no template-admin capability. Responsive read verification passed at 1440/1024/768/375 for Encargado and 1440/375 for Dirección; no browser console/HTTP errors or overflow were observed. Human visual approval remains pending.
- **GATES / DATA INVARIANTS:** Development global POST gate remained off and the schedule-specific gate on. No Journey, payroll, PIN, Incidencias, M07, or other-module write was issued. No materialized shift or Journey calculation was created. Raw punches remain 89 rows / 89 unique UUIDs with unchanged UUID-set SHA-256 `91384da9f49fd3632188f10811eb17ab644e5293ca76be23d857aef40207d343`. Templates/segments remain 12/12; verified membership coverage for this assignment remains one row.
- **TEST_RESULTS:** Portal3 `tests/checador_m03_schedule_capture_regression.php` 24/24 PASS; UI Quality Gate 36 PASS / 0 FAIL / 1 BLOCKED (its fixture render cannot certify browser visuals; authenticated Chrome was separately run). API assignment read-scope 25/25 PASS; scheduling authorization 23/23 PASS; source-only response contract 6/6 PASS. API `v2_schedule_write_authorization_contract_tests.php` remains 18 PASS / 6 FAIL because existing static assertions expect broad `biometricos.*` authorization, incompatible with the previously approved dedicated schedule-capability addendum; it was not changed in this task. The DB-backed API runtime suite, including added audit-timestamp/replay checks, was not run because its destructive runner has no configured isolated test database; no API test DB write was attempted. PHP lint and `git diff --check` passed for changed API/Portal files.
- **FILES:** API `modelo/v2/shift/ShiftAssignmentService.php`, `tests/v2_shift_scheduling_runtime_tests.php`, `tests/v2_shift_assignment_response_contract_static_tests.php`; Portal3 `app/Services/ChecadorDailyReadService.php`, `app/Controllers/ChecadorController.php`, `tests/checador_m03_schedule_capture_regression.php`; plus this Activity Ledger, API Changelog, and Current Status only. Work remains local/uncommitted.
- **BLOCKERS / LIMITS:** This proves one authorized QA assignment lifecycle only. It does not establish official RH scheduling, completion of the DB-backed runtime regression, API-header idempotency, or human visual approval. No Architecture Freeze, frozen contract, Delivery Roadmap, shared implementation status, or production artifact changed. No commit or push.

### Entry 056
- **DATE:** 2026-11-02
- **ACTIVITY_ID:** `CHECADOR_V2_M03_OPERATIONAL_COMPLETION_V1`
- **PROJECT:** Portal3 + Checador API V2
- **TYPE:** M03_COMPLETION_REGRESSION_RECONCILIATION
- **STATUS:** PARTIAL_BLOCKED; QA CREATE PATH REMAINS VERIFIED, OPERATIONAL CLOSEOUT NOT CLAIMED
- **AUTHORIZATION:** User requested M03 completion, reconciliation of the six write-authorization assertions, worker coverage preparation, and validation without production changes or a new database.
- **AUTHORIZATION REGRESSION:** The six former failures in `tests/v2_schedule_write_authorization_contract_tests.php` were stale static expectations from before the approved Shift Scheduling Authorization Addendum: they expected every write route to use `authorizeScheduleWrite`, broad `biometricos.editar` as the schedule gate, and direct `authorizeScheduleRead` calls for all read routes. The current contract instead assigns template writes to `plantillas.administrar`, worker assignment create/close/replace to `programacion.editar` plus station/membership checks, and retains `biometricos.editar` only for scheduled-shift materialization. The test now asserts those per-route gates and the addendum text; result 31/31 PASS. The dedicated role/scope suite remains 23/23 PASS.
+- **IDEMPOTENCY CONTRACT GAP:** Frozen Shift Scheduling API Contract V1.0 documents optional `X-Idempotency-Key` only for `POST /shift-templates`; `POST /shift-assignments` specifies overlap conflict and exact-payload retry behavior but no key scope, replay response, key-reuse conflict, retention, or persistent key store. Portal3's assignment request currently uses `Idempotency-Key`, which the API does not consume. Implementing key reuse/conflict semantics on assignment would require an approved additive contract decision and durable storage/migration. No contract or schema was changed and no unsupported behavior was claimed.
+- **WORKER COVERAGE:** The verified membership cohort remains the synthetic QA workers 1004–1008; it is not evidence of operative staff assignment. Assignment capture already filters by verified date and the API rechecks membership. No RH-approved shift decisions, effective dates, or verified operational-worker memberships were available in this activity, so no operational assignments were created.
+- **CLOSE / REPLACE:** API routes and authorization are present. Their real DB lifecycle was not executed: `tests/v2_shift_scheduling_runtime_tests.php` drops/recreates Shift Scheduling and Journey tables, and no isolated test database is configured. The existing QA assignment ID 1 was not touched; no database write or API mutation occurred in this activity.
+- **TEST_RESULTS:** Authorization static regression 31/31 PASS; scheduling authorization 23/23 PASS; assignment read scope 25/25 PASS; membership coverage 8/8 PASS; Portal M03 capture regression 24/24 PASS; PHP lint and `git diff --check` PASS. The prior authenticated Chrome create/readback and Dirección read-only evidence remain the applicable browser evidence; this activity made no Portal runtime/UI change, so the successful first E2E was not repeated. Visual approval remains pending.
+- **FILES:** API `tests/v2_schedule_write_authorization_contract_tests.php`, this Activity Ledger, API Changelog, and Current Status. No service/controller, Portal view, database/schema, contract, permission, or data change.
+- **REMAINING BLOCKERS:** Contract owner decision on assignment-level `X-Idempotency-Key` semantics and persistent storage; a safe isolated database to run close/replace runtime tests; RH-authoritative memberships and shift decisions for operative workers. M03 remains partially verified; no commit or push.

### Entry 057
- **DATE:** 2026-11-03
- **ACTIVITY_ID:** `CHECADOR_V2_M11_DEVICE_ADMIN_GOVERNANCE_APPROVAL_V1`
- **PROJECT:** Portal3 + Checador API V2
- **TYPE:** FUNCTIONAL_APPROVAL_GOVERNANCE_RECORD
- **STATUS:** `FUNCTIONAL_APPROVAL_RECORDED; CROSS_PROJECT_APPROVAL_PENDING; IMPLEMENTATION_NOT_AUTHORIZED`
- **APPROVAL EVIDENCE:** The user, acting as the responsible functional approver, expressly approved the development-only scope on 2026-11-03. The approval refers to `docs/v2/CHECADOR_V2_M11_DEVICE_ADMIN_READ_AUTHORIZATION_ADDENDUM_V1.0.md`, SHA-256 `2308c7f39378699822bab2d583193bdfac0246a97f1c1948ec5771458546a639`. The supplied approval did not identify the approver by personal name; none is inferred here.
- **APPROVED FUNCTIONAL SCOPE:** Read-only administrative device inventory in development; backend-only Portal3 → API V2 access using delegated user JWT; dedicated read capability `checador-v2-dispositivos.leer` scoped to Encargado's authorized station and Dirección's institutionally authorized localities; M11 inventory display; persisted status and last telemetry kept distinct; station-to-group relation remains optional and unverified.
- **EXCLUSIONS:** No device writes, reassignment, activation/deactivation, telemetry modification, production changes, additional database, or commit/push. Architecture Freeze V1.0 and J1/J2 remain unchanged.
- **GOVERNANCE GATE:** The addendum still states `PROPOSED_FOR_CROSS_PROJECT_APPROVAL` and `Implementation authorized: NO`. No express Portal3-owner or API-project-owner approval for this exact SHA, and no completed cross-project approval record, was found during this task. The functional approval is not represented as either owner's approval. No endpoint, Portal3 runtime integration, ACL grant, data read, or runtime change was started.
- **NEXT REQUIRED APPROVALS:** Record express approval of the exact addendum SHA from the authorized Portal3 project owner and API V2 project owner through the applicable cross-project process. Only after that gate is complete may implementation proceed under the separately stated development-only scope; do not infer authority for writes or production.

### Entry 058
- **DATE:** 2026-11-03
- **ACTIVITY_ID:** `CHECADOR_V2_M11_CROSS_APPROVAL_AND_IMPLEMENTATION_V1`
- **PROJECT:** Portal3 + Checador API V2
- **TYPE:** CROSS_PROJECT_APPROVAL_AND_DEVELOPMENT_IMPLEMENTATION_AUTHORIZATION
- **STATUS:** `CROSS_PROJECT_APPROVED; DEVELOPMENT_IMPLEMENTATION_AUTHORIZED`
- **APPROVER:** The user expressly states they are the authorized responsible owner for both Portal3 and API V2 and approves in both capacities. No personal name was supplied; none is inferred.
- **APPROVED SOURCE ARTIFACT:** `docs/v2/CHECADOR_V2_M11_DEVICE_ADMIN_READ_AUTHORIZATION_ADDENDUM_V1.0.md`, approved source SHA-256 `2308c7f39378699822bab2d583193bdfac0246a97f1c1948ec5771458546a639`.
- **STATUS-TRANSITIONED ARTIFACT:** Following approval, governance wording and the explicit development ACL authorization were reconciled in both copies without changing the approved read contract. Current byte-identical copies have SHA-256 `a2f0ad3980032bd992e9aade2aea5f51c9cf141027e7cde64a3d130506f36eb6`.
- **AUTHORIZATION:** Development-only `GET /api/v2/administrative-devices`; backend Portal3 M11 integration through delegated user JWT; dedicated read grant `checador-v2-dispositivos.leer` for position 6 limited to its authorized station and position 13 limited to its authorized localities. Persisted status and telemetry remain distinct; group is optional and not inferred.
- **EXCLUSIONS:** No device writes, reassignment, activation/deactivation, credential changes, telemetry mutation, schema/migration, POST-gate changes, Level 2 evaluation, production, additional database, or commit/push. Architecture Freeze V1.0, J1, and J2 remain unchanged.
- **GOVERNANCE:** This entry records the express cross-project owner approval and implementation authorization. The approval is not an architectural amendment to the Freeze or frozen J1/J2 contracts. Do not expand beyond the approved read slice.

### Entry 059
- **DATE:** 2026-11-03
- **ACTIVITY_ID:** `CHECADOR_V2_M11_CROSS_APPROVAL_AND_IMPLEMENTATION_V1`
- **PROJECT:** Checador API V2 + Portal3
- **TYPE:** DEVELOPMENT_READ_ENDPOINT_AND_M11_INTEGRATION
- **STATUS:** `IMPLEMENTED_AND_VERIFIED_DEVELOPMENT_READ_ONLY`
- **IMPLEMENTATION:** Added `GET /api/v2/administrative-devices` with delegated Portal3 JWT validation, dedicated capability, current authorized workplace scope, verified physical/canonical mapping, bounded read-only snapshot, persisted device status separated from last telemetry, and no connectivity inference. Portal3 calls through its authenticated backend BFF and renders the approved fields using its institutional DataTable. No browser-to-API request or device credential is used.
- **DEVELOPMENT ACL:** Applied only `checador-v2-dispositivos.leer` POSITION grants for positions 6 and 13 in `bd_admongas`; active user-level revocations were checked and none existed for QA users 33/19. The Portal3 ACL readback is active/granted for both positions. No broad biometric permission or write gate was changed.
- **REAL READ / SCOPE:** Read-only API requests against `bd_admongas` returned HTTP 200 / COMPLETE with 2 authorized Interlomas devices for user 33 (position 6) and 3 devices across user 19's authorized scope (position 13). An explicit out-of-scope locality request returned HTTP 403 `STATION_SCOPE_DENIED` with no rows. Physical/canonical IDs were mapped by `numlista`; group remains explicitly unavailable.
- **VALIDATION:** API projection contract tests 11/11 PASS; API PHP syntax PASS. Authenticated Playwright M11 read scenario passed for both QA profiles at 1440, 768, and 375 px; same-origin BFF returned real data, no direct browser API access, no writes, no page errors, and authorized scope assertions passed. The initial live probe exposed a wrong locality column name and malformed SQL grouping; both were corrected before the passing read. API 405 and data-partial branches remain separately covered by route/pure projection checks.
- **INVARIANTS:** No device, assignment, credential, or telemetry writes; no schema/migration, production, POST gate, Architecture Freeze, J1, J2, or Lector changes; no commits or push.

### Entry 060
- **DATE:** 2026-11-04
- **ACTIVITY_ID:** `CHECADOR_V2_M04_GLOBAL_TEMPLATE_CUSTOMIZATION_APPROVAL_V1`
- **PROJECT:** Checador API V2 + Portal3
- **TYPE:** CROSS_PROJECT_CONTRACT_APPROVAL_AND_DEVELOPMENT_IMPLEMENTATION
- **STATUS:** `CROSS_PROJECT_APPROVED; DEVELOPMENT_IMPLEMENTATION_AUTHORIZED`
- **APPROVER:** User expressly identified themselves as the responsible authorized owner of both projects and approved in both capacities; no personal name inferred.
- **APPROVED SOURCE:** `docs/v2/CHECADOR_V2_M04_GLOBAL_TEMPLATE_STATION_CUSTOMIZATION_ADDENDUM_V1.0.md`; SHA-256 `96744a64edecbde0a63fa5c55756b44e56d03dabbd00e61f8c89acc443a1846b`.
- **SCOPE:** Development-only immutable global-version lineage, station/source uniqueness, extension of existing template creation/versioning and M04 UI; Encargado restricted to server-authorized station; Dirección remains read-only. No assignment replacement, global source mutation, production, Freeze/J1/J2, Legacy, Lector, Journey, or PunchEvent mutation.
- **RECONCILED ADDENDUM COPIES:** Approval/status metadata was reconciled identically after approval; current synchronized copies SHA-256 `651a8b963f39d60663999e12c962fee0ec1ac4a6325b27ac55b7902d923a854e`. The approved source bytes SHA remains recorded above; functional scope is unchanged.

### Entry 061
- **DATE:** 2026-11-04
- **ACTIVITY_ID:** `CHECADOR_V2_M04_GLOBAL_TEMPLATE_CUSTOMIZATION_APPROVAL_V1`
- **PROJECT:** Checador API V2 + Portal3
- **TYPE:** DEVELOPMENT_ONLY_GLOBAL_TO_STATION_TEMPLATE_CUSTOMIZATION
- **STATUS:** `IMPLEMENTED_AND_VERIFIED_DEVELOPMENT_QA`
- **APPROVAL:** Cross-project owner approval and implementation authorization were expressly supplied by the user for source addendum SHA-256 `96744a64edecbde0a63fa5c55756b44e56d03dabbd00e61f8c89acc443a1846b`. Synchronized reconciled copies are SHA-256 `651a8b963f39d60663999e12c962fee0ec1ac4a6325b27ac55b7902d923a854e`. Architecture Freeze V1.0 and J1/J2 were not modified.
- **SCHEMA:** Applied additive migration `migraciones/v2/2026_11_04_000001_create_v2_shift_template_station_lineage.sql` once to verified local `bd_admongas`. It enforces unique station/source-global and station/local identities and unique local template identity. No existing template or assignment rows were rewritten.
- **API:** Extended the existing authorized station-template writer for explicit global-source customization, transactional lineage, server-generated local identity, and duplicate readback. Exact-version detail is station scoped. No endpoint family or permission grant was added.
- **PORTAL3:** M04 shows “Personalizar para mi estación” for visible GLOBAL rows and “Editar” for an owned STATION row. Portal3 calls API only through its backend delegated client; CSRF, station scope, readback and DataTables update are preserved.
- **REAL QA WRITE / READBACK:** Encargado user 33 customized global `LEGACY_IL1_200000_060000`, source row 11/version 1, for station 1. API returned 201. Local row 17 used API-generated key `ST1_C52e9c7dbaaaf651ee6e7609389c56e46`, actor 33, QA name `QA Personalización Turno Nocturno 20261104`. Repeated customization returned HTTP 200 and the same identity without a duplicate. A versioned local edit produced version 2; both local versions read back the same immutable source key/version lineage. Source GLOBAL row/version/segments remained unchanged; existing assignments remained six.
- **SECURITY:** Foreign-station customization and Dirección write attempts returned HTTP 403. Dirección read-only UI and authorized catalog read passed. No grant, role, broad biometric permission, or gate changed.
- **TESTS:** API customization static checks 11/11 PASS; scheduling authorization 23/23 PASS; Portal M04 customization regression 9/9 PASS; versioned local-template regression 3/3 PASS; authenticated Chrome Playwright 2/2 PASS. PHP/JavaScript syntax checks PASS. UI Quality Gate fixture was 36 PASS / 0 FAIL / 1 BLOCKED because its embedded DOM fixture does not verify live browser geometry; focused Chrome E2E separately passed. Human visual approval remains pending.
- **INVARIANTS:** No global source edit, assignment change, production write, Legacy writer, PunchEvent or Journey mutation, global POST gate change, extra database, commit, or push.

### Entry 062
- **DATE:** 2026-11-04
- **ACTIVITY_ID:** `CHECADOR_V2_M04_FORMAL_CLOSURE_V1`
- **PROJECT:** Portal3 + Checador API V2
- **TYPE:** OWNER_APPROVED_M04_FUNCTIONAL_AND_VISUAL_QA_CLOSURE
- **STATUS:** `M04_CLOSED_FUNCTIONAL_QA`
- **APPROVAL:** The responsible user formally approved M04 functional and visual closure. `FUNCTIONAL_APPROVAL: YES`; `VISUAL_APPROVAL: YES`; `PRODUCTION_APPROVAL: NO`. This final owner approval supersedes historical pending-visual-approval notes in preceding implementation entries.
- **ENVIRONMENT:** Acceptance and verification are limited to local development `bd_admongas`; no production status is implied.
- **VERIFIED_SCOPE:** Catalog consultation; local shift creation; versioned editing; global-to-station customization with immutable source lineage and duplicate prevention; explicit work/rest segments; overnight shift display; Encargado station-only write scope; Dirección read-only supervision; and institutional DataTables/search/pagination/responsive presentation.
- **EVIDENCE:** Existing development readbacks and QA records remain the evidence for persisted creation, version history, source lineage, and scope. Focused M04 tests were retained (customization 13/13, local versioning 3/3, API contract 11/11, authorization 23/23). The latest authenticated Chrome run passed 2/2 profiles at 1440/768/375 for name presentation, actions header, dropdowns, search and paging. Prior creation/version/personalization Chrome and MariaDB evidence remains in Entry 061 and the task-specific candidate-screenshot directories; tests were not rerun solely for this closure.
- **PRESERVED:** Existing QA templates/assignments and lineage are retained. No global source rows, assignments, permissions, migrations, API contracts, Architecture Freeze, J1/J2, PunchEvents, production data, or gates were changed for this closure. No commit or push.
- **NEXT_MODULE:** `M03_PROGRAMACION_SEMANAL`.

### Entry 063
- **DATE:** 2026-11-06
- **ACTIVITY_ID:** `CHECADOR_V2_PIN_TEMPORAL_IMPLEMENTACION_FINAL_APROBADA`
- **PROJECT:** Portal3 + Checador API V2
- **TYPE:** CROSS_PROJECT_CONTRACT_APPROVAL
- **STATUS:** `APPROVED_FOR_DEVELOPMENT_IMPLEMENTATION`
- **APPROVED_ARTIFACT:** `docs/v2/TEMPORARY_ACCESS_OPERATION_PERMISSION_SPLIT_ADDENDUM_DRAFT_V1.0.md` (approved source SHA-256: `87916221327e3f36195288213e5d941792d5a5e70bd90ef450fa9b87f34f7cc5`)
- **APPROVAL:** The responsible user expressly approved the exact addendum and its operation-to-capability mapping for both Portal3 and API V2, development only.
- **SCOPE:** `leer` reads, `crear` authorizes, `eliminar` cancels, `editar` rejects; existing effective `editar` compatibility is preserved only for previously authorized profiles. Palo Solo Encargado may read/authorize/cancel but not reject; Dirección is read-only; Autolavado remains excluded.
- **CONSTRAINTS:** No Architecture Freeze/J1/J2, production, M02/M03/M04, unrelated ACL, table, column, or migration changes.
- **IMPLEMENTATION_AUTHORIZED:** YES, limited to the approved development slice.
- **NEXT_STEP:** Implement and verify Portal3/API V2 operation-specific permission checks and the exact authorized development ACL.

### Entry 064
- **DATE:** 2026-11-06
- **ACTIVITY_ID:** `CHECADOR_V2_PIN_TEMPORAL_IMPLEMENTACION_FINAL_APROBADA`
- **PROJECT:** Portal3 + Checador API V2
- **TYPE:** APPROVED_DEVELOPMENT_IMPLEMENTATION
- **STATUS:** `IMPLEMENTED_FOCUSED_VALIDATION_PASS`
- **APPROVED_ARTIFACT_SHA256:** `87916221327e3f36195288213e5d941792d5a5e70bd90ef450fa9b87f34f7cc5` (addendum content remains unchanged)
- **API_CHANGE:** API V2 resolves `leer`, `crear`, `eliminar`, and `editar` independently for queue, authorize, cancel, and reject, preserving only the approved legacy `editar` compatibility. Rejecting and canceling require their own effective capability unless the actor is in that legacy-compatible population.
- **VALIDATION:** `tests/sec_l03_authorization_regression.php` 40/40 PASS; PHP syntax checks PASS. Local API direct reject using the Encargado Palo Solo JWT returned HTTP 403 `FUNCTION_PERMISSION_DENIED`. No request transition was written because the live queue contained zero requests for both Encargado and Dirección.
- **LIMITS:** No API schema/migration, route family, Architecture Freeze, J1/J2, production, or device-auth change. Live authorize/cancel transition, audit readback, and browser validation remain untested pending a legitimate Lector-originated QA request and authenticated Chrome session.

### Entry 065
- **DATE:** 2026-11-06
- **ACTIVITY_ID:** `CHECADOR_V2_PIN_TEMPORAL_INTEGRACION_GENERAL_V1`
- **PROJECT:** Portal3 + Checador API V2
- **TYPE:** M07_GENERAL_STATION_SCOPE_ID_SPACE_REMEDIATION
- **STATUS:** `IMPLEMENTED_FOCUSED_VALIDATION_PASS; DEVICE_AND_BROWSER_E2E_NOT_TESTED`
- **APPROVED_PERMISSION_CONTRACT:** Operation split addendum SHA-256 `87916221327e3f36195288213e5d941792d5a5e70bd90ef450fa9b87f34f7cc5`; no contract text or schema was changed.
- **ROOT CAUSE:** Portal3 JWT authorization resolves authorized canonical locality IDs, while M07 request rows persist the reader host's physical station ID in `op_rh_v2_solicitudes_acceso_temporal.id_estacion`. The controller previously passed canonical IDs to service queries comparing against physical IDs, causing incorrect/empty scope when the numeric ID spaces differ.
- **FIX:** `Portal3JwtAuthHelper` now returns both the canonical locality scope and its validated physical-station counterpart derived through the existing unique institutional mapping. M07 list/authorize/reject/cancel service calls receive physical IDs for filtering request rows; canonical IDs remain the Portal3 scope result. Mapping remains allowlist- and grant-controlled and fails closed. No station was enabled by this change.
- **VALIDATION:** `tests/sec_l03_authorization_regression.php` 42/42 PASS; changed PHP syntax and `git diff --check` PASS. Prior local read-only API GETs for Encargado 52 and Dirección 19 returned HTTP 200 with an empty queue. Direct unauthorized Encargado rejection and Dirección authorization returned HTTP 403. No legitimate request was pending, so no authorize/cancel transition or state/audit readback was performed.
- **READER/LIFECYCLE LIMIT:** No executable Lector source was found in the inspected project checkout; no reader-originated request was generated. API lifecycle constants and service routes remain existing (30-minute request, 15-minute PIN, single use, three failed attempts), but a live full lifecycle was not exercised.
- **SHARED READER BLOCKER:** Current request schema persists reader device and physical host station, external worker reference, and resolved worker ID; device-worker identity mapping resolves only the worker ID and has no locality. A shared Palo Solo reader therefore cannot distinguish a request belonging to Palo Solo locality 2 from Autolavado locality 9. The approved M07 scope explicitly excludes Autolavado. Supporting that case needs cross-project contract approval for authoritative effective-locality attribution and persistence; no ACL expansion or schema change was made.
- **PROTECTED:** No new table/column/migration, grant, station allowlist, production, device credential, PIN transition, schedule, M02/M03/M04, Architecture Freeze, J1/J2, commit, or push.

### Entry 066
- **DATE:** 2026-11-09
- **ACTIVITY_ID:** `CHECADOR_V2_COMODINES_API_CANONICAL_IMPLEMENTATION_STAGE_1`
- **PROJECT:** Checador API V2 (cross-project approval applies to Portal3/API V2)
- **TYPE:** CROSS_PROJECT_APPROVAL_AND_DEVELOPMENT_ONLY_API_IMPLEMENTATION
- **STATUS:** `APPROVED_SOURCE_SHA; API_STAGE_1_IMPLEMENTED; FEATURE_DISABLED`
- **APPROVER:** The user expressly authorized the exact source SHA as responsible for Portal3 and API V2; no personal name supplied or inferred.
- **APPROVED_ADDENDUM:** `docs/v2/CHECADOR_V2_COMODINES_WORKPLACE_COVERAGE_ADDENDUM_DRAFT_V1.0.md`, SHA-256 `0cd553e2dbb3eac43a9a221832bcdb5c51aa0a0d7f36b3af1f7e27eff10ef618`. Portal3 and API copies were reverified identical. The approved document bytes and its DRAFT label were not changed.
- **IMPLEMENTED API SLICE:** Added the dormant canonical coverage request/version writer, state transitions, scoped read/detail with revision audit history, dedicated JWT/grant authorization, and opt-in `WORKER_EFFECTIVE` dispatch to the existing resolver. Existing ordinary worker effective-schedule routing remains the fallback. Writes are transactional, idempotency-key/fingerprint protected, and require exact canonical membership, locality, timezone, template-version and linked assignment evidence. The readiness gate verifies the exact feature flag, required schema, InnoDB dependencies, indexes and foreign-key link before any operation.
- **SECURITY / SCOPE:** Role ceilings and dedicated capabilities match the approved addendum: position 6 may read/request only; position 13 may read/approve/cancel only, each requiring an effective dedicated grant and current canonical scope. No grant was created. Legacy Rol de Comodines tables are not a schedule or write source.
- **VALIDATION:** Isolated policy tests 45/45 PASS; coverage route/gate static tests 12/12 PASS; existing shift-scheduling authorization tests 23/23 PASS; PHP syntax and whitespace checks PASS. The broader pre-existing `v2_schedule_write_authorization_contract_tests.php` reports 28/31 PASS, with existing failures SWA03, SWA04, SWA17 concerning template creation/version read gates; those source files were not changed by this slice and the failures are not represented as coverage failures.
- **GATES / UNTESTED:** Development `bd_admongas` currently lacks the proposed coverage table, assignment linkage and expanded audit vocabulary (previous read-only schema verification); therefore no live API write/readback, cancellation transaction, or browser E2E was run. `CHECADOR_V2_WORKPLACE_COVERAGE_ENABLED` is unset in the current process and evaluates disabled. Migration, grants, operational writes, activation, Portal3 feature integration, production, Architecture Freeze, J1/J2, M02–M07, PIN and Legacy writers were not changed.
- **NO DATA CHANGE:** No migration, grant, schedule, membership, Journey, incidence, PunchEvent or other database write; no commit or push.


### Entry 067
- **DATE:** 2026-11-09
- **ACTIVITY_ID:** `CHECADOR_V2_COMODINES_STAGE_2_SCHEMA_AND_REGRESSION_REVIEW`
- **PROJECT:** Checador API V2 (cross-project contract applies; Portal3 code was not changed)
- **TYPE:** DEVELOPMENT_SCHEMA_PREFLIGHT_AND_REGRESSION_REVIEW
- **STATUS:** `DDL_PROPOSAL_READY_FOR_SEPARATE_MIGRATION_APPROVAL; FEATURE_DISABLED`
- **APPROVED_CONTRACT:** Addendum source SHA-256 `0cd553e2dbb3eac43a9a221832bcdb5c51aa0a0d7f36b3af1f7e27eff10ef618`; bytes unchanged.
- **REAL_SCHEMA:** Read-only connection verified `bd_admongas`, MariaDB `11.8.8-MariaDB`. The approved coverage table and assignment link are absent; existing assignment/audit, worker, canonical workplace, membership, station, user, template, segment, timezone, grants and scheduled-shift tables are InnoDB. Membership PK is `INT UNSIGNED`; coverage ID link must be `BIGINT UNSIGNED`; worker/workplace/station/user PKs are signed `INT`; audit entity enum currently permits only TEMPLATE/ASSIGNMENT. The proposal preflight SELECTs returned READY for all checks. No DDL ran.
- **DDL PROPOSALS:** Added review-only up/preflight/postflight proposal and companion rollback proposal under `docs/v2/`. Both explain MariaDB statement-level implicit commits, stop/resume rules, data-preserving recovery and rollback only when there are no coverage rows, links, audit events or dependent FKs. No migration runner path was added.
- **GATE DEFECT FIX:** MariaDB `information_schema.COLUMN_TYPE` includes display widths (`int(10) unsigned`, `bigint(20) unsigned`). The dormant feature gate previously compared widthless type names and would remain closed even after a matching schema migration. Added width-tolerant exact unsigned integer-type matching and deterministic tests (8/8 PASS).
- **REGRESSION CLASSIFICATION:** SWA03/SWA04 assert a direct `authorizeTemplateAdministration()` call in handlers, but the approved station-template flow calls `authorizeTemplateMutation(payload)`, which checks station-template permission for the Encargado and global-template administration for other eligible roles before mutation. SWA17 asserts `authorizeScheduleRead()`, while the approved scheduling authorization addendum permits template-version GET through the dedicated `authorizeTemplateCatalogRead()` helper (`programacion.leer` or `plantillas.administrar`). These three static assertions are stale relative to the approved API policy and do not exercise coverage code. The test file contains uncommitted changes relative to HEAD, so historical introduction timing cannot be established. No unrelated template code/tests were changed.
- **VALIDATION:** Coverage policy 45/45; route/gate static 12/12; schema-type tests 8/8; scheduling authorization 23/23; template station-customization contract 11/11; Autolavado scope 19/19; assignment read-scope fixture 25/25; membership coverage 8/8. PHP lint and whitespace checks PASS. Broader scheduling write suite remains 28/31 due the three stale assertions above. Resolver policy/precedence is fixture-tested; MariaDB writer transactions, idempotent/concurrent replay, cancellation readback, and Chrome remain NOT TESTED because the required schema is absent and this task forbids migration/activation.
- **ACL:** No dedicated Comodines grants exist in local `op_rh_v2_permission_grants`. Future minimum position grants were prepared conceptually (position 6: read/request; position 13: read/approve/cancel), subject to separate approval and effective canonical scope. No ACL write was made.
- **PRESERVED:** No operational data, schedule, membership, Journey, incidence, PunchEvent, ACL, migration, feature flag, Portal3, Architecture Freeze, J1/J2, M02–M07, PIN, production, commit or push was changed.
