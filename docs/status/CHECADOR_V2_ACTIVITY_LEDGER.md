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
