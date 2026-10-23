# CHECADOR V2 — IMPLEMENTATION STATUS

**Current Date:** 2026-10-13
**Architecture Freeze Status:** FROZEN
**Architecture Version:** V1.0

---

## 1. EXECUTIVE SUMMARY

- **V2.0 Architecture Freeze:** COMPLETE (SHA256: `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`)
- **V2.1 API Raw Punch Ingestion:** COMPLETE (18/18 Tests PASS)
- **V2.2 Lector Durable Punch Queue:** COMPLETE (22/22 Tests PASS)
- **V2.2 Temporary Access Remediated (SEC-L03):** COMPLETE (62/62 Temporary Access PASS, 34/34 SEC-L03 PASS)
- **V2.3 API Raw Punch Integrity & Immutability:** CLOSED & COMMITTED (`78076de870d821ab92dd9b95f7ddaf424c61ee0a`)
  - **G0/G1/G1A Integrity Schema & Service:** HMAC-SHA256 server-side seal pre-INSERT (16/16 Integrity Tests PASS, 9/9 Ingestion Tests PASS)
  - **G2 Integrity Ingestion Integration:** Fail-closed mandatory integrity, idempotency `200 ALREADY_RECEIVED` & `409 IDEMPOTENCY_CONFLICT`
  - **G3 Append-Only DB Hardening:** Database-enforced BEFORE UPDATE / BEFORE DELETE triggers (11/11 Append-Only Tests PASS)
  - **G4A/G4B/G4C/G4D Authorized Read API:** `GET /api/v2/punch-events` with dynamic integrity status, `biometricos.leer` ACL, fresh station scope, pagination max 200, default 30 days, maximum 31 days UTC window (`400 DATE_RANGE_TOO_LARGE`) (11/11 Read API Tests PASS)
- **End-to-End Real Integration Test:** PASS
- **HTTP Routing:** RESOLVED (8 V2 endpoints routed under `/api/v2`)
- **Comparación Paralela sin Afectar Nómina (V2.6):** COMPLETE / OPERATIONAL; this is not payroll cutover.
- **Legacy Systems Replaced:** NO (op_rh_personal, op_rh_personal_asistencia intact)
- **V2.4 Journey (Pairing / Calculation) & V2.5 Administrative Incidences:** COMPLETE / OPERATIONAL (see canonical Current Status and Activity Ledger for evidence).
- **Event UUID Contract:** FROZEN V1.0; opaque immutable identifier, 1–64 UTF-8 characters after trim, RFC UUID syntax not required, no case normalization. Current device-ingestion implementation COMPLETE (13 focused PASS / 0 FAIL); exact retry, divergent conflict, non-RFC value, case-variant conflict, cross-device same UUID, HMAC sealing, and HMAC fail-closed regressions PASS.
- **Level 1 Non-Telegram DEVELOPMENT Slice (P04 / P3-A):** CLOSED_VERIFIED_DEVELOPMENT; snapshot creation & reading governed by `biometricos.leer` + authorized workplace scope (SRA01-SRA16 16/16 PASS). P1 parity defect REMEDIATED and VERIFIED (14/14 SP PASS; exact 1:1 parity with raw punch read).
- **Workplace Timezone Read Boundary:** CLOSED_VERIFIED_DEVELOPMENT (`GET /api/v2/workplaces/{id}/timezone`, 23/23 PASS; 8 development mappings configured).
- **Portal3 Server-to-Server Read Boundaries:** APPROVED_AND_IMPLEMENTED_DEVELOPMENT (P03, P04/P3-A verified in DEVELOPMENT with synthetic fixture user 999, workplace 1; P04B/P3-B Level 2 window-status remediated to `biometricos.leer` and verified 20/20 L2R PASS; production NOT_AUTHORIZED).
- **Portal + Telegram Capture Channel:** FROZEN_DEFERRED.
- **V2.7 Pre-production Hardening:** NOT_FULLY_CLOSED (SEC-A, SEC-D1 closed; SEC-B open transition; SEC-C, SEC-E, SEC-F pending).
- **Payroll Cutover:** NOT_STARTED; production deployment and cutover remain unauthorized.

---

## 2. GOVERNANCE & SCHEDULE POINTERS

- **Current Phase:** LEVEL2_READ_REMEDIATION_COMPLETE; NEXT: PORTAL3_P04B_LEVEL2_WINDOW_COMPLETENESS_INTEGRATION
- **API V2.3 Code Status:** CLOSED (`API_V2_3_CODE_STATUS: CLOSED`, `API_V2_3_CODE_COMMITTED: YES`)
- **API V2.3 Production Deployment Ready:** NO (`PRODUCTION_DEPLOYMENT_BLOCKED: YES` - Pending Infrastructure Deployment Gates)
- **Current Local HEAD:** `28c38918c1ac02900cfbc6b0eedd68719b2e128e` (this documentation sync is uncommitted)
- **Branch:** `feature/api-level2-server`
- **Push Performed:** NO (`PUSH_PERFORMED: NO`)
- **Production Modified:** NO (`PRODUCTION_MODIFIED: NO`)
- **Governance Hashes:**
  - Architecture Freeze V1.0: `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`
  - Integrity Addendum V1.0: `e8891532d5adfe6d07078d51c8368b7ca9b513d3a3a48d5f08231ae562c36878`
  - Read API Contract V1.0: `d2fe9ff808fd30534cce9e2bc5e7c44ec471fe809e018e7cd93fb73b1d91212b`
  - Temporary Access Contract V1.0: `75396d690fc83f0ad592dc0b559671859c8d6ddf3e95d37feedde57c6beb5ab9`
- **Target Code Freeze:** 2026-12-18
- **Final Deadline:** 2026-12-31
- **Schedule Status:** GREEN

---

## 3. LOCAL COMMITS SUMMARY (API V2.3)

1. `4bd95e7d5ef0a5dbff4eb7f3e82717906d2dd0a5` — `security(checador-v2): enforce Portal3 authorization for temporary access`
2. `ea86f0f5b40cfbcddbce9f67a78377b31ff55b70` — `feat(v2.3): add raw punch integrity sealing`
3. `b8dedb2dcdb6ceef696d7eaeb4779836371cb1a2` — `feat(v2.3): enforce append-only raw punches`
4. `d74e2197be34bfd27d09eddf3dbeab33fa35583b` — `feat(v2.3): add authorized raw punch read API`
5. `78076de01b33fa39fb3eecac15aeeaf42c13cbef` — `docs(v2.3): document raw punch integrity and read API`

---

## 4. ACTIVE DEPLOYMENT CONTRACTS & ENDPOINT INVENTORY (8 ENDPOINTS)

### Public API V2 Endpoints:
1. `POST /api/v2/punch-events` — Ingesta de Marcación Biométrica (Device Auth, HMAC-SHA256 Sealed, Idempotencia 200/409)
2. `GET /api/v2/punch-events` — Consulta de Marcaciones Crudas (Supervisor JWT, `biometricos.leer`, Scope Estación, Max 31 días UTC, Max per_page 200)
3. `POST /api/v2/temporary-access/requests` — Solicitud de Acceso Temporal (Device Auth)
4. `GET /api/v2/temporary-access/requests` — Listar Solicitudes Pendientes (Supervisor JWT, `acceso-temporal.leer`)
5. `POST /api/v2/temporary-access/requests/{request_uuid}/authorize` — Autorizar Solicitud (Supervisor JWT, `acceso-temporal.editar`)
6. `POST /api/v2/temporary-access/requests/{request_uuid}/reject` — Rechazar Solicitud (Supervisor JWT, `acceso-temporal.editar`)
7. `POST /api/v2/temporary-access/requests/{request_uuid}/cancel` — Cancelar Solicitud (Supervisor JWT, `acceso-temporal.editar`)
8. `POST /api/v2/temporary-access/validate` — Validar PIN Temporal (Device Auth)

---

## 5. RECENT ACTIVITY DETAILS (API V2.3)

### A. Raw Punch Evidence & Immutability Model
- **Raw Punch Event Store:** Preserva el hecho original inalterable en `op_rh_v2_marcaciones_crudas`.
- **HMAC-SHA256 Evidence Seal:** Generado server-side sobre envelope V1 (16 campos) antes del `INSERT`. No se actualiza posteriormente.
- **Database Append-Only Hardening:** Triggers MySQL `tg_op_rh_v2_marcaciones_crudas_no_update` y `tg_op_rh_v2_marcaciones_crudas_no_delete` impiden cualquier modificación o borrado de filas raw o legacy.
- **Integrity Read Verification:** `GET /api/v2/punch-events` calcula dinámicamente `VALID`, `INVALID`, `UNVERIFIABLE` o `LEGACY_UNSEALED` sin exponer el string de sello HMAC ni el ID interno primario auto-incremental de la base de datos.
- **Strict Query Bounds:** Paginación máxima 200 registros. Rango por omisión de 30 días UTC; ventana máxima explícita de 31 días UTC (2,678,400 segundos). Consultas que excedan la ventana son rechazadas antes de ejecutar consultas DB con `400 DATE_RANGE_TOO_LARGE`.

---

## 6. COMPONENT STATUS BREAKDOWN

| Component | Status | Test Coverage | Operational Mode |
| :--- | :--- | :--- | :--- |
| **API Raw Ingestion Endpoint (`POST /punch-events`)** | COMPLETE | 18 / 18 PASS | Comparación Paralela sin Afectar Nómina + HMAC Seal |
| **Raw Punch Read Endpoint (`GET /punch-events`)** | COMPLETE | 11 / 11 PASS | Supervisor JWT + Dynamic Integrity |
| **Raw Punch Integrity Service** | COMPLETE | 16 / 16 PASS | Canonical HMAC-SHA256 |
| **Append-Only DB Hardening (Triggers)** | COMPLETE | 11 / 11 PASS | Database-Enforced Append-Only |
| **Temporary Access V2 & SEC-L03 Authz** | COMPLETE | 62 / 62 PASS (34 SEC-L03) | Supervisor JWT + Device Auth |
| **Device & Credential Auth** | COMPLETE | PASS | Bearer Secret + Bcrypt |
| **DeviceWorkerIdentity** | COMPLETE | PASS | Historical Resolution Active |
| **Journey Pairing & Calculation (V2.4)** | OPERATIONAL | 16/16 pairing and 14/14 calculation runtime tests PASS | Derived journey processing |
| **Administrative Incidences & Evaluation (V2.5)** | OPERATIONAL | 10/10 runtime tests PASS | Append-only evaluation; no payroll monetary calculation |
| **Legacy & Payroll Parallel Comparison (V2.6)** | OPERATIONAL | 9/9 runtime test blocks PASS | Read-only legacy comparison; no payroll cutover |
| **Level 1 Snapshot Read Endpoint (`POST /api/v2/snapshots`)** | COMPLETE (DEV) | 21 / 21 PASS (16 / 16 SRA) | Portal3 JWT + `biometricos.leer` |
| **Workplace Timezone Read Endpoint (`GET /api/v2/workplaces/{id}/timezone`)** | COMPLETE (DEV) | 23 / 23 PASS (25 / 25 Authority) | Portal3 JWT + `biometricos.leer` |
| **Level 1 Non-Telegram DEVELOPMENT Schema** | COMPLETE (DEVELOPMENT) | Event UUID focused validation 13/13; raw-reference migration static 21/21 | Production not authorized |
| **SEC-A Database Fail-Closed** | COMPLETE | 12 / 12 PASS | Strict env var requirement; fail-closed connection |
| **SEC-B Acceso-Empresa Dual-Stack POST** | OPEN_TRANSITION | 15 / 15 PASS | Body credentials; no query fallback; 7/8 remote stations pending |
| **SEC-D1 HTTP Method, Media & Field Guards** | COMPLETE | 20 / 20 PASS | 405 Method Not Allowed + Allow, 415 Media Type, Field Bounds |
| **Lector Durable Queue & 429 Recovery** | COMPLETE (LOCAL LAB) | 22 / 22 PASS (Queue) / Local Lab (429) | Atomic JSON File (`%AppData%\AdmonGasLector\v2_punch_queue.json`) |
| **Payroll Adapter (Legacy)** | NOT_STARTED | N/A | Legacy Payroll Unmodified |

---

## 7. INFRASTRUCTURE & DEPLOYMENT GATES (PENDING FOR PRODUCTION)

Los siguientes requerimientos de despliegue se encuentran identificados y pendientes de materialización en el entorno de producción (no representan bloqueos de código):

1. Aprovisionamiento del principal MySQL dedicado con modelo de menor privilegio (`SELECT`, `INSERT` únicamente sobre `op_rh_v2_marcaciones_crudas`).
2. Configuración e inyección segura de las variables de entorno de llaves HMAC (`RAW_PUNCH_KEY_RING` y `RAW_PUNCH_ACTIVE_KEY_ID`).
3. Definición del procedimiento operativo de rotación de llaves HMAC.
4. Configuración de `JWT_SECRET` en FPM / Entorno productivo.
5. Verificación de TLS / HSTS y límites de cuerpo de solicitud HTTP.
6. Rate limiting en Nginx / Plesk.
7. Desactivación de `display_errors` en entorno productivo.
8. Plan de ejecución y verificación de respaldos para la migración de producción.

---

## 8. OUT OF SCOPE & NEXT PHASE POINTERS

### Historical V2.3 scope boundaries and current disposition:
- Clasificación, pairing y cálculo de jornada se implementaron en V2.4; la evaluación de incidencias administrativas opera en V2.5.
- Fabricar marcaciones faltantes continúa prohibido; la API raw devuelve únicamente eventos existentes.
- La atribución de eventos `PENDING_MATCH` no se fuerza; cualquier resolución futura requiere un flujo aditivo autorizado.

### Next Active Milestone:
- **V2.7 — PRE-PRODUCTION HARDENING & CUTOVER READINESS:** Operational/security hardening and cutover-readiness planning. This is the next active target only; no production deployment, migration, or cutover is authorized by this status update.
