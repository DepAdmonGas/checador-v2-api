# CHECADOR V2 — IMPLEMENTATION STATUS

**Current Date:** 2026-10-05
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
- **Shadow Mode:** ACTIVE
- **Legacy Systems Replaced:** NO (op_rh_personal, op_rh_personal_asistencia intact)
- **Motor de Jornada V2 & Incidencias:** NOT_STARTED (Next Phase: Portal3 Daily Journey & Incidents)
- **Payroll Cutover:** NOT_STARTED

---

## 2. GOVERNANCE & SCHEDULE POINTERS

- **Current Phase:** V2.3_RAW_PUNCH_INTEGRITY_AND_READ_API
- **API V2.3 Code Status:** CLOSED (`API_V2_3_CODE_STATUS: CLOSED`, `API_V2_3_CODE_COMMITTED: YES`)
- **API V2.3 Production Deployment Ready:** NO (`PRODUCTION_DEPLOYMENT_BLOCKED: YES` - Pending Infrastructure Deployment Gates)
- **Final Committed HEAD:** `78076de870d821ab92dd9b95f7ddaf424c61ee0a`
- **Branch:** `main`
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
| **API Raw Ingestion Endpoint (`POST /punch-events`)** | COMPLETE | 18 / 18 PASS | Shadow Mode + HMAC Seal |
| **Raw Punch Read Endpoint (`GET /punch-events`)** | COMPLETE | 11 / 11 PASS | Supervisor JWT + Dynamic Integrity |
| **Raw Punch Integrity Service** | COMPLETE | 16 / 16 PASS | Canonical HMAC-SHA256 |
| **Append-Only DB Hardening (Triggers)** | COMPLETE | 11 / 11 PASS | Database-Enforced Append-Only |
| **Temporary Access V2 & SEC-L03 Authz** | COMPLETE | 62 / 62 PASS (34 SEC-L03) | Supervisor JWT + Device Auth |
| **Device & Credential Auth** | COMPLETE | PASS | Bearer Secret + Bcrypt |
| **DeviceWorkerIdentity** | COMPLETE | PASS | Historical Resolution Active |
| **Lector Durable Queue** | COMPLETE | 22 / 22 PASS | SQLite Local Queue |
| **Shift Engine V2 (Motor / Jornada)** | NOT_STARTED | N/A | Next Phase: Portal3 Daily Journey |
| **Incidents & Infractions V2** | NOT_STARTED | N/A | Next Phase: Portal3 Incidents |
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

### Out of Scope in API V2.3 (Not Implemented Yet):
- Clasificación de jornada diaria (Entrada, Salida Comer, Regreso Comer, Salida).
- Generación de incidencias (retardos, faltas, horas extra, vacaciones, incapacidades, permisos).
- Fabricación de marcaciones faltantes (la API raw devuelve únicamente marcaciones existentes).
- Resolución administrativa o flujo completo de asignación tardía `PENDING_MATCH`.

### Next Phase:
- **PORTAL3 — JORNADA DIARIA + INCIDENCIAS:** Inicio de preflight arquitectónico y especificación candidate para el motor de jornada diaria y gestión de incidencias en Portal3, manteniendo la inmutabilidad y trazabilidad completa hacia los eventos crudos de `op_rh_v2_marcaciones_crudas`.
