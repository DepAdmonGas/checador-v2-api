# CHECADOR V2 — DELIVERY ROADMAP & MILESTONE TRACKER

**Document Status:** CANONICAL
**Last Updated At:** 2026-10-09
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
| **V2.5-S Administrative Incidences Physical Model** | Design & freeze `CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_V1.0.md` (FROZEN_V1.0) | 2026-10-07 | FROZEN V1.0 (`9bbffb34e736...`) |
| **V2.5-M Administrative Incidences Schema Implementation** | Create SQL migration files & 52-scenario schema test suite for V2.5 Administrative Incidences tables | 2026-10-07 | 10 / 10 Schema Tests PASS (`tests/v2_administrative_incidences_schema_tests.php`) |
| **V2.5-SA Provenance Addendum** | Design & freeze `CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_ADDENDUM_V1.1.md` (FROZEN_V1.1) | 2026-10-07 | FROZEN V1.1 (`13c624213b9f...`) |
| **V2.5-MA Provenance Schema Implementation** | Create SQL migration files for Addendum V1.1 (`000004`) & 44-invariant test suite | 2026-10-07 | 7 / 7 Schema Tests PASS (`tests/v2_administrative_provenance_schema_tests.php`) |
| **V2.5 Administrative Evaluation Runtime** | Implement `AdministrativeEvaluationService` domain service & 10-block runtime test suite | 2026-10-07 | 10 / 10 Runtime Tests PASS (`tests/v2_administrative_runtime_tests.php`) |
| **V2.6 Parallel Comparison Contract** | Design & freeze `CHECADOR_V2_LEGACY_PAYROLL_PARALLEL_COMPARISON_ADAPTER_CONTRACT_V1.0.md` (FROZEN_V1.0) | 2026-10-07 | FROZEN V1.0 (`d21a59d79de7...`) |
| **V2.6-S Parallel Comparison Physical Model** | Design & freeze `CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md` (FROZEN_V1.0) | 2026-10-07 | FROZEN V1.0 (`cd4e1aee46d9...`) |
| **V2.6-SA Physical Model Addendum V1.1** | Design & freeze `CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_ADDENDUM_V1.1.md` (FROZEN_V1.1) | 2026-10-07 | FROZEN V1.1 (`451179ed195d...`) |
| **V2.6-M Parallel Comparison Schema Implementation** | Create SQL migration files (`000005`) & 12-block schema test suite for V2.6 Parallel Comparison tables | 2026-10-07 | 12 / 12 Test Blocks PASS (`tests/v2_parallel_comparison_schema_tests.php`) |
| **V2.6 Parallel Comparison Runtime Engine** | Implement `ParallelComparisonService` domain service & 9-block runtime test suite | 2026-10-08 | 9 / 9 Runtime Tests PASS (`tests/v2_parallel_comparison_runtime_tests.php`) |
| **Level 1 Non-Telegram DEVELOPMENT Closure** | Freeze-compatible event UUID validation plus snapshot event UUID width and raw-reference integrity migrations applied/verified in DEVELOPMENT | 2026-10-09 | Event UUID focused 13/13 PASS; migration 000002 static 21/21 PASS; DEVELOPMENT schema/data final verification CLOSED_VERIFIED; production not authorized |
| **V2.7-SEC-A DB Fail-Closed Hardening** | Environment variable fail-closed configuration; removal of fallback credentials | 2026-10-12 | 12 / 12 PASS (`tests/v2_db_configuration_fail_closed_tests.php`) |
| **V2.7-SEC-B Dual-Stack Auth Transport** | Dual-stack POST (JSON + form-urlencoded); query fallback rejected; GET transitional | 2026-10-12 | 15 / 15 PASS (`tests/v2_sec_b_acceso_empresa_transport_tests.php`) |
| **V2.7-SEC-D1 HTTP Method, Content & Payload Guards** | Standard HTTP 405 with `Allow`, 415 unsupported media types, schema field length boundaries | 2026-10-12 | 20 / 20 PASS (`tests/v2_sec_d1_method_content_payload_tests.php`) |
| **V2.7 Security Canonical Status Reconciliation** | Reconciled canonical status across SEC-A to SEC-E; separated implementation, spec, lab evidence; verified station identity is not public IP | 2026-10-12 | Canonical reconciliation complete |
| **Workplace Timezone Read API & Authority** | Effective-dated timezone authority table + REST read endpoint (`GET /api/v2/workplaces/{id}/timezone`) | 2026-10-12 | 23 / 23 PASS (`tests/v2_workplace_timezone_read_endpoint_tests.php`), 25 / 25 PASS (`tests/v2_workplace_timezone_authority_runtime_tests.php`) |
| **Workplace Timezone Business Mappings (DEV)** | Authorized development mappings for physical workplaces 1–7, 14 -> `America/Mexico_City` | 2026-10-12 | 8 rows inserted in `op_rh_v2_localidades_zonas_horarias`; production not authorized |
| **Level 1 Snapshot Auth Compliance Remediation** | Aligned snapshot authorization to `biometricos.leer` + authorized workplace scope | 2026-10-12 | 16 / 16 PASS (`tests/v2_snapshot_authorization_compliance_tests.php`) |
| **Post-Oct 12 Stabilization & Parity Audit** | Reconciled worktree provenance; detected `P1_SNAPSHOT_MEMBERSHIP_INCONSISTENCY` (0 vs 4); stopped runtime changes | 2026-10-13 | Provenance 0 unknown changes; full regression PASS; owner resolution required |
| **P1 Snapshot Parity & Level 2 Auth Remediation** | Fixed predicate in SnapshotReadV2Service; aligned Level2ServerController to `biometricos.leer` | 2026-10-13 | 14/14 SP PASS; 20/20 L2R PASS; 5-step dev smoke 100% PASS |

---

## 2. CANONICAL PORTAL3 INTEGRATION SEQUENCE MAPPING

To resolve the naming collision between early product roadmaps and technical dependency sequencing, the canonical sequence is defined as follows:

| Canonical ID | Technical Milestone | Scope / Feature Description | Development Status |
| :--- | :--- | :--- | :--- |
| **PORTAL3-P03** | Daily Punch Consultation | Query raw observed PunchEvents via `GET /api/v2/punch-events` | CLOSED_VERIFIED_DEVELOPMENT |
| **PORTAL3-P04A / P3-A** | Level 1 Snapshot Read | Point-in-time durable read snapshot creation & consumption | CLOSED_VERIFIED_DEVELOPMENT |
| **PORTAL3-P04B / P3-B** | Level 2 Window Completeness | Query window completeness via `GET /api/v2/level2/window-status` | READY_FOR_INTEGRATION (API Remediated & Verified) |
| **PORTAL3-P04C / P3-C+D**| Schedule & Timezone Prerequisites | Authoritative workplace timezone + effective shift schedule | CLOSED_VERIFIED_DEVELOPMENT |
| **PORTAL3-P04D / P3-E** | On-Demand Journey Processing | Dynamic pairing and calculation on demand | PENDING_P04B |
| **PORTAL3-P05** | Administrative Evaluation | Append-only administrative evaluation and incidence resolution | PENDING_P04D |
| **PORTAL3-P06** | Parallel Comparison | Non-disruptive shadow comparison against legacy attendance | PENDING_P05 |

---

## 3. CURRENT ACTIVE MILESTONE

### Milestone: V2.7_PREPRODUCTION_HARDENING_AND_CUTOVER_READINESS
- **Goal:** Pre-production security controls, database least-privilege, logging redaction, fleet authentication transition, and operational cutover sign-off.
- **Current Hardening State:** SEC-A CLOSED_VERIFIED; SEC-B OPEN_TRANSITION (1/8 stations tested locally, 7/8 remote stations pending); SEC-C OPEN (pending pre-production target); SEC-D1 CLOSED_VERIFIED; SEC-D3/D4 SPECIFICATION_ONLY; SEC-D8 NOT_REQUIRED_FOR_NORMAL_ARCHITECTURE; SEC-E AUDIT_COMPLETE (specification only; root@localhost runtime); Lector 429 recovery VERIFIED_SAFE_LOCAL_LAB.
- **Numeric Thresholds:** NOT_APPROVED. Ingest 20 req/s, burst 60 is MODELING_CANDIDATE_FOR_PREPRODUCTION.
- **Target Completion:** Pending explicit operational authorization.

---

## 4. NEXT PLANNED MILESTONES (SCHEDULED FOR 2026-12-31 TARGET)

1. **V2.7 — Pre-production Hardening & Cutover Readiness:**
   - SEC-B remote physical terminal cutover (7 stations).
   - SEC-C pre-production deployment target and perimeter TLS validation.
   - SEC-E database least-privilege role separation on staging/production.
   - SEC-F error logging and sensitive data redaction review.
   - SEC-G security evidence and documentation alignment.
   - Formal sign-off on rate-limiting and burst parameters before edge configuration (SEC-D).

---

## 4. PRE-PRODUCTION & KNOWN RISKS LEDGER

- **F-01 (Legacy Offline Queue):** DEFERRED / KNOWN LEGACY LIMITATION. Legacy attendance flow lacks local durable queue. Not a blocker for V2 comparación paralela sin afectar nómina.
- **F-02 (Cold-Start Offline Login):** PREPRODUCTION RESILIENCE. Lector cold-start while offline cannot call `GET /acceso-empresa`. Deferred to pre-production resilience review.
- **F-03 (Station Consistency):** KNOWN_PREPRODUCTION_OPERATIONAL_RISK. API side support AVAILABLE (`61e942f55ef5...`). Lector side remediation NOT authorized / DEFERRED_PREPRODUCTION_REVIEW.
- **F-04 (Legacy Login URL Parameters):** PREPRODUCTION SECURITY. Legacy `GET /acceso-empresa` passes user/password in query parameters. Controlled via SEC-B transition.
- **SEC-DEBT-01 (Legacy Reversible Encryption):** Legacy user/station passwords use two-way symmetric encryption (`Encriptar::encrypt`) instead of one-way cryptographic hashing (Bcrypt/Argon2id). Risk: MEDIUM; Pre-production required: NO; Protocol remediation: Requires migration design.
- **SEC-DEBT-02 (Plaintext Session Token):** Active operational tokens stored plaintext in `op_rh_localidades_perfil.token`. Risk: MEDIUM; Pre-production required: NO; Protocol remediation: Can be remediated without protocol change.
- **SEC-DEBT-03 (Non-Parameterized Legacy SQL):** Legacy authentication queries in `Acceso.php` concatenate ciphertext variables directly. Risk: LOW (mitigated by ciphertext); Pre-production required: NO; Protocol remediation: Can be remediated without protocol change.
- **SEC-DEBT-04 (Transitional GET Credential Exposure):** Legacy GET branch exposes credentials in query strings/access logs until all 8 physical terminals cut over to POST. Risk: MEDIUM; Pre-production required: YES (via SEC-B completion); Protocol remediation: Remediated once GET branch is disabled.
- [ ] Staging/Pre-production deployment target definition (SEC-C)
- [ ] Database least-privilege principal provisioning (SEC-E)
- [ ] Edge perimeter rate limiting deployment following operational sign-off (SEC-D4)

