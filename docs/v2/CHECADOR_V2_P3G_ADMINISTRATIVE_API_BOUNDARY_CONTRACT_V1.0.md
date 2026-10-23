# CHECADOR V2 — P3-G ADMINISTRATIVE API BOUNDARY CONTRACT V1.0

**Status:** FROZEN_OWNER_APPROVED  
**Version:** 1.0  
**Owner Approval Date:** 2026-10-14  
**Architecture Option:** OPTION B  
**Companion Portal3 Contract:** `docs/v2/CHECADOR_V2_PORTAL3_P3G_INCIDENCE_ADMINISTRATIVE_CONTRACT_V1.0.md`  
**Companion Portal3 Contract SHA-256:** `e26e888015b27f9f1023e19cf4e005795836a99fd81a98c5638589fde5ca234d`  
**Architecture Freeze Reference:** `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`  
**Architecture Freeze SHA-256:** `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`  
**Implementation Authorized:** NO (Contract and Governance Freeze Only)  
**Database Changes Authorized:** NO  
**Production Changes Authorized:** NO  

---

## 1. Scope, Purpose & Governance Authority

### 1.1 Purpose
This contract establishes and freezes the authoritative server-to-server Application Programming Interface (API) boundary between the Portal3 backend and the Checador V2 API for Milestone P3-G:
- Administrative Incidences (vacation evidence, permission evidence, disability administrative context, authorized lateness/early departure, official mission);
- Append-only Incident Revisions and lifecycle tracking;
- Conflict Resolution and administrative review;
- Final Administrative Authorization, Rejection, and Revocation/Cancellation;
- Downstream Administrative Evaluation associated with Journey/P2 workdays.

### 1.2 Governance Authority & Hierarchy
This contract is governed strictly by:
1. `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md` (SHA-256: `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`);
2. The owner-approved product decisions finalized on 2026-10-14;
3. Companion Portal3 Contract `docs/v2/CHECADOR_V2_PORTAL3_P3G_INCIDENCE_ADMINISTRATIVE_CONTRACT_V1.0.md` (SHA-256: `e26e888015b27f9f1023e19cf4e005795836a99fd81a98c5638589fde5ca234d`).

This document does NOT modify, amend, or reinterpret the Architecture Freeze.

---

## 2. Core Architectural Invariants

### 2.1 Approved Architecture: OPTION B
In accordance with explicit owner approval on 2026-10-14:
1. **Canonical V2 Domain:** The Checador V2 domain model (`op_rh_v2_incidencias_maestro`, `op_rh_v2_incidencias_revisiones`, `op_rh_v2_incidencias_aplicaciones`, `op_rh_v2_ajustes_administrativos`, `op_rh_v2_evaluaciones_administrativas`, and provenance association tables) is the sole canonical source of truth for V2 attendance incidences.
2. **Legacy Sources as Read-Only Adapters:** Legacy administrative tables (`op_rh_formatos*`, `op_rh_permisos*`) serve exclusively as read-only evidence adapters where their lifecycle and source authority can be verified.
3. **Legacy Attendance Tables Disclaimed:** Legacy tables `op_rh_personal_asistencia` and `op_rh_personal_asistencia_incidencia` are NOT the V2 source of truth and shall never be treated as authoritative records by this boundary.
4. **V2.5 Canonical Foundation:** The existing V2.5 API domain service (`AdministrativeEvaluationService`) constitutes the canonical administrative engine. The API boundary exposes this existing engine; it does NOT design a secondary administrative processor.

### 2.2 PunchEvent Immutability
A raw `PunchEvent` represents an unalterable historical occurrence. Administrative operations shall never:
- modify or shift a punch timestamp;
- delete, truncate, or physically drop a punch;
- hide, mask, or exclude a punch from query views;
- fabricate or insert a synthetic replacement punch for an absent mark.

Observed raw physical punches remain permanently visible in consultation endpoints (`GET /api/v2/punch-events`), Level 1 snapshots, and Journey evaluations regardless of any approved administrative incidence.

### 2.3 Zero Legacy Dual-Write (`LEGACY_DUAL_WRITE = NO`)
The API boundary strictly prohibits writing administrative conclusions, adjustments, or incident resolutions into legacy tables:
```text
LEGACY_DUAL_WRITE = NO
```
No P3-G API operation shall execute `INSERT`, `UPDATE`, or `DELETE` on `op_rh_personal_asistencia` or `op_rh_personal_asistencia_incidencia`.

### 2.4 Payroll Integration Deferred (`PAYROLL_INTEGRATION = DEFERRED`)
Checador V2 operates strictly under the principle of "Comparación paralela sin afectar nómina":
```text
PAYROLL_INTEGRATION = DEFERRED
```
The P3-G boundary shall never:
- calculate compensation, wages, deductions, or bonuses;
- author or modify payroll batches, periods, or slips (`RecibosNomina`);
- authorize or calculate payable overtime;
- emit direct monetary or payroll consequences.

---

## 3. Trust Boundary, Transport & Authentication

### 3.1 Trust Boundary Topology
```text
[ Browser Client ]
       |  (HTTPS / Portal3 Session)
       v
[ Portal3 Backend ]
       |  (HTTPS / User-Delegated JWT / Port 443)
       v
[ Checador V2 API Boundary ]
       |
       v
[ V2.5 Domain Engine (AdministrativeEvaluationService) ]
```

### 3.2 Transport Invariants
1. **Portal3 Backend to API Only:** The P3-G boundary is strictly server-to-server (`Portal3 backend → API only`).
2. **No Direct Browser Access:** Direct browser requests to the Checador V2 API boundary are strictly forbidden (`BROWSER_DIRECT_API = NO`). No API keys, signing secrets, or direct endpoint paths shall be exposed to client-side JavaScript.
3. **No Device Auth:** Biometric device credentials (Bearer secret / Bcrypt) are strictly prohibited on administrative endpoints (`DEVICE_AUTH_ALLOWED = NO`). Calls bearing Device Auth headers shall fail closed with HTTP 401 UNAUTHORIZED.

### 3.3 Authentication Family: Portal3 User-Delegated JWT
Every request to the P3-G API boundary must provide an `Authorization: Bearer <jwt>` header meeting the canonical Portal3 JWT specification:
- `iss`: strictly `'portal3_auth'`;
- `sub`: valid integer representing `tb_usuarios.id`;
- `type`: strictly `'access'`;
- `iat`: issued-at Unix timestamp;
- `exp`: expiration timestamp strictly in the future;
- Signature: HMAC-SHA256 verified against the server-side `JWT_SECRET`.

### 3.4 Principal Validation
The API independently validates that:
1. `tb_usuarios.id` exists and is active (`estatus = 0`);
2. The user holds the specific functional permission required for the requested endpoint in the assigned ACL namespace;
3. The targeted workplace falls strictly within the user's authorized Workplace scope (`tb_multiestacion_usuario` / `tb_modulos_config`).

---

## 4. Canonical Identities & Workplace Scoping

### 4.1 Canonical Worker Identity: `op_rh_personal.id`
The sole canonical worker identifier across the P3-G boundary is `op_rh_personal.id`:
```text
Worker = op_rh_personal.id
```
- Input parameter name: `worker_id` (integer).
- The API resolves `worker_id` strictly against `op_rh_personal.id`.
- If `worker_id` is unknown, deleted, or inactive, the endpoint fails closed with HTTP 404 `WORKER_NOT_FOUND`.
- Forbidden worker identifiers: `no_colaborador`, employee name, email, Portal3 user account ID (`tb_usuarios.id`), external system references.

### 4.2 Canonical Workplace Identity: `op_rh_localidades.id`
The canonical Workplace identifier is `op_rh_localidades.id`:
```text
Workplace = op_rh_localidades.id
```
- Input parameter name: `workplace_id` or `station_id` (integer).
- Portal3 station references (`tb_estaciones.id`) bridge to the canonical Workplace strictly through the authoritative unique station number:
  ```text
  tb_estaciones.id -> unique valid numlista -> op_rh_localidades.id
  ```
- Numeric equality between `tb_estaciones.id` and `op_rh_localidades.id` is NEVER assumed.
- The API independently verifies that the resolved Workplace is within the caller's authorized scope.
- An empty scope assignment results in immediate fail-closed denial (HTTP 403 `STATION_SCOPE_DENIED`). Empty scope is NEVER interpreted as "all workplaces".

---

## 5. Dedicated ACL Semantic Namespaces

P3-G establishes three dedicated functional ACL namespaces. Generic legacy permissions (such as `recursos-humanos.editar`) possess ZERO authority in Checador V2:

| Namespace | Required Semantic Permission | Allowed API Operations | Explicit Exclusions |
|---|---|---|---|
| `checador-v2-incidencias` | `READ` (`leer = 1`) | `GET /api/v2/incidences`<br>`GET /api/v2/incidences/{uuid}`<br>`GET /api/v2/administrative-evaluations` | Cannot create revisions, resolve conflicts, authorize, reject, or revoke. |
| `checador-v2-incidencias` | `CREATE` (`crear = 1`) | `POST /api/v2/incidences` | Cannot resolve, authorize, reject, or revoke. |
| `checador-v2-incidencias-resolucion` | `READ` (`leer = 1`) | Query incidences & conflicts in resolution views | Cannot authorize or reject. |
| `checador-v2-incidencias-resolucion` | `RESOLVE` / `REVIEW` (`editar = 1`) | `POST /api/v2/incidences/{uuid}/revisions`<br>`POST /api/v2/incidences/{uuid}/resolve`<br>`POST /api/v2/administrative-evaluations/evaluate` | Cannot grant final authorization. All actions must be append-only. |
| `checador-v2-incidencias-autorizacion` | `READ` (`leer = 1`) | Read authorization queues and decision audit | Cannot authorize without edit/authorize flag. |
| `checador-v2-incidencias-autorizacion` | `AUTHORIZE` (`editar = 1`) | `POST /api/v2/incidences/{uuid}/authorize` | Cannot modify raw punches; no payroll authority. |
| `checador-v2-incidencias-autorizacion` | `REJECT` (`editar = 1`) | `POST /api/v2/incidences/{uuid}/reject` | Cannot delete historical records; no payroll authority. |
| `checador-v2-incidencias-autorizacion` | `REVOKE` / `CANCEL` (`eliminar = 1` or `editar = 1`) | `POST /api/v2/incidences/{uuid}/revoke` | Cannot mutate or delete old revisions. Revocation is append-only. |

---

## 6. Conceptual Categories & Source Mapping Matrix

| Conceptual Category | API Code | Source Mapping Authority Status | Lifecycle / Integration Rule |
|---|---|---|---|
| Vacation | `VACATION` | `READ_ONLY_ADAPTER` (Legacy Form 6) | Read-only evidence; ambiguous lifecycle states default to `REVIEW_REQUIRED`. |
| Permission | `PERMISSION` | `READ_ONLY_ADAPTER` (Legacy Permisos) | Read-only evidence; ambiguous lifecycle states default to `REVIEW_REQUIRED`. |
| Disability / Incapacity | `DISABILITY` | `BLOCKED_BY_SOURCE_AUTHORITY` | Automatic legacy ingestion is BLOCKED due to unproven lifecycle and schema drift. Requires explicit V2 entry. |
| Authorized Lateness | `AUTHORIZED_LATENESS` | `NO_AUTHORITATIVE_SOURCE_FROZEN` | No authoritative legacy source; cannot produce automated final effect; `REVIEW_REQUIRED`. |
| Authorized Early Departure | `AUTHORIZED_EARLY_DEPARTURE` | `NO_AUTHORITATIVE_SOURCE_FROZEN` | No authoritative legacy source; cannot produce automated final effect; `REVIEW_REQUIRED`. |
| Official Mission | `OFFICIAL_MISSION` | `NO_AUTHORITATIVE_SOURCE_FROZEN` | No authoritative legacy source; cannot produce automated final effect; `REVIEW_REQUIRED`. |

---

## 7. Conflict Model & Default Policies

### 7.1 Conflict Default: `REVIEW_REQUIRED`
```text
Conflict Default = REVIEW_REQUIRED
```
Whenever administrative evidence overlaps with another administrative record, or contradicts physical `PunchEvent` marks:
1. **No Automatic Precedence:** The API shall NEVER encode automatic precedence between categories (e.g., Vacation does not silently suppress a PunchEvent; physical punches do not automatically cancel an approved Permission).
2. **Preservation of Raw Facts:** Both the physical marks and the administrative records remain preserved in full detail.
3. **Mandatory Human Review:** The state is flagged as `REVIEW_REQUIRED` (`CONTRADICTION_DETECTED` or `OVERLAP_CONFLICT`) until an authorized administrative actor reviews and resolves the case.

### 7.2 Mandatory Conflict Conditions
- `VACATION` + valid physical `PunchEvent` on the same workday -> `REVIEW_REQUIRED`
- `DISABILITY` + valid physical `PunchEvent` on the same workday -> `REVIEW_REQUIRED`
- `PERMISSION` + valid physical `PunchEvent` during the excused period -> `REVIEW_REQUIRED`
- Overlap between `VACATION` and `DISABILITY` -> `REVIEW_REQUIRED`
- Overlap between `VACATION` and `PERMISSION` -> `REVIEW_REQUIRED`
- Overlap between `DISABILITY` and `PERMISSION` -> `REVIEW_REQUIRED`

---

## 8. Attendance Classification Gates: SIN MARCACIÓN & FALTA

### 8.1 SIN MARCACIÓN Gate
A workday is never classified as `SIN MARCACIÓN` merely because zero punches exist in a query view. To classify a workday as `SIN MARCACIÓN`, the evaluation gate must affirmatively prove:
1. Level 2 source completeness is verified (`window_level2_status = VERIFIED`);
2. An effective scheduled shift exists and required attendance;
3. Zero valid physical marks occurred during the shift window; AND
4. **No active, approved administrative incidence** (vacation, permission, disability, mission) covers the shift.

If administrative applicability is unproven, incomplete, or in conflict, the status remains `REVIEW_REQUIRED` or `DATA_INCOMPLETE`.

### 8.2 FALTA Invariant: Human Authorization Required
```text
FALTA Rule: NEVER INFERRED AUTOMATICALLY (FALTA human authorization)
AUTOMATIC_FALTA = NO
```
Under NO circumstances shall the Checador V2 API boundary automatically emit, calculate, or infer a `FALTA` from:
- zero observed marks;
- verified Level 2 status;
- an active work schedule;
- a `SIN MARCACIÓN` state;
- absence of recorded incidences;
- or any automated combination thereof.

A final `FALTA` classification strictly requires explicit human evaluation, authorized by a user with `checador-v2-incidencias-autorizacion` permission, recorded as an append-only administrative evaluation revision.

---

## 9. Minimum API Resource Model & Endpoint Specifications

### 9.1 Resource Identifier Conventions
- Incident Aggregate Public ID: `uuid_incidencia` (`VARCHAR(64)` format `INC_<unique_id>` or RFC 4122 UUIDv4). Internal database primary keys (`id`) are NEVER exposed in public URLs or contract payloads.
- Revision Identifier: Monotonic integer `revision_numero` scoped to `uuid_incidencia`.
- Adjustment Public ID: `uuid_ajuste` / `uuid_ajuste_logico` (`VARCHAR(64)`).
- Evaluation Public Identifier: `fingerprint_ejecucion` (`VARCHAR(64)` SHA-256 digest).

---

### 9.2 Endpoint 1: Read Applicable Administrative Incidences
- **HTTP Method:** `GET`
- **Path:** `/api/v2/incidences`
- **Caller:** Portal3 Backend
- **Authentication:** Portal3 JWT (`Bearer <jwt>`)
- **Functional Permission:** `checador-v2-incidencias.leer = 1` OR `checador-v2-incidencias-resolucion.leer = 1` OR `checador-v2-incidencias-autorizacion.leer = 1`
- **Workplace Scope:** Must match caller's assigned Workplace scope in `tb_multiestacion_usuario`.
- **Query Parameters:**
  - `worker_id` (required, int): Canonical `op_rh_personal.id`.
  - `workplace_id` (optional, int): Canonical `op_rh_localidades.id`.
  - `from` (required, string): Start local calendar date `YYYY-MM-DD`.
  - `to` (required, string): End local calendar date `YYYY-MM-DD`.
  - `category` (optional, string): Filter by conceptual category code.
  - `status` (optional, string): Filter by current status (`ACTIVE`, `PENDING_APPROVAL`, `SUPERSEDED`, `CANCELLED`, `REJECTED`).
- **Response Format (HTTP 200 OK):**
```json
{
  "status": "success",
  "data": {
    "worker_id": 8801,
    "workplace_id": 1,
    "query_range": {
      "from": "2026-10-01",
      "to": "2026-10-14"
    },
    "total_records": 1,
    "incidences": [
      {
        "uuid_incidencia": "INC_670d8a1b2c3d4",
        "worker_id": 8801,
        "workplace_id": 1,
        "category": "VACATION",
        "scope": "WORKER",
        "date_range": {
          "start_date": "2026-10-05",
          "end_date": "2026-10-12"
        },
        "time_interval": null,
        "current_status": "ACTIVE",
        "current_revision": 1,
        "reason": "Periodo vacacional anual ordinario aprobado",
        "evidence_reference": "FORM6-FOLIO-2026-8801",
        "source_classification": "READ_ONLY_ADAPTER",
        "created_at_utc": "2026-10-04T18:30:00Z",
        "created_by_user_id": 101,
        "approved_by_user_id": 102
      }
    ]
  }
}
```

---

### 9.3 Endpoint 2: Read Single Incident Detail & Complete Revision History
- **HTTP Method:** `GET`
- **Path:** `/api/v2/incidences/{uuid_incidencia}`
- **Caller:** Portal3 Backend
- **Authentication:** Portal3 JWT (`Bearer <jwt>`)
- **Functional Permission:** `checador-v2-incidencias.leer = 1` OR resolution/authorization read
- **Workplace Scope:** Verified against the incident's associated workplace or worker station assignment.
- **Path Parameters:**
  - `uuid_incidencia` (required, string): Canonical public UUID of the incident.
- **Response Format (HTTP 200 OK):**
```json
{
  "status": "success",
  "data": {
    "uuid_incidencia": "INC_670d8a1b2c3d4",
    "worker_id": 8801,
    "workplace_id": 1,
    "category": "PERMISSION",
    "scope": "WORKER",
    "date_range": {
      "start_date": "2026-10-14",
      "end_date": "2026-10-14"
    },
    "time_interval": {
      "start_time": "08:00:00",
      "end_time": "12:00:00"
    },
    "current_status": "ACTIVE",
    "current_revision": 2,
    "revisions": [
      {
        "revision_number": 1,
        "status": "SUPERSEDED",
        "reason": "Permiso médico matutino",
        "evidence_reference": "PERM-2026-10-14-A",
        "created_by_user_id": 101,
        "approved_by_user_id": null,
        "created_at_utc": "2026-10-13T16:00:00Z"
      },
      {
        "revision_number": 2,
        "status": "ACTIVE",
        "reason": "Permiso médico matutino con comprobante clínico validado",
        "evidence_reference": "IMSS-FOLIO-998877",
        "created_by_user_id": 101,
        "approved_by_user_id": 105,
        "created_at_utc": "2026-10-14T08:15:00Z"
      }
    ]
  }
}
```

---

### 9.4 Endpoint 3: Create New V2 Incident
- **HTTP Method:** `POST`
- **Path:** `/api/v2/incidences`
- **Caller:** Portal3 Backend
- **Authentication:** Portal3 JWT (`Bearer <jwt>`)
- **Functional Permission:** `checador-v2-incidencias.crear = 1`
- **Workplace Scope:** Verified against `workplace_id` and caller scope.
- **Mandatory Headers:**
  - `Idempotency-Key` (required, string): Unique client-supplied idempotency key (max 64 chars).
- **Request Body (JSON):**
```json
{
  "worker_id": 8801,
  "workplace_id": 1,
  "category": "VACATION",
  "scope": "WORKER",
  "start_date": "2026-10-20",
  "end_date": "2026-10-25",
  "time_interval": null,
  "reason": "Solicitud de vacaciones programadas",
  "evidence_reference": "REQ-VAC-2026-8801"
}
```
- **Lifecycle & Actors:**
  - Actor is derived STRICTLY from the authenticated JWT `sub` (`creado_por_usuario_id`). Client-supplied actor IDs are completely ignored.
  - Multi-day periods create ONE incident master encompassing the entire range.
  - Generates initial `revision_numero = 1` with initial status `PENDING_APPROVAL` (or `ACTIVE` if self-authorizing policy applies).
- **Response Format (HTTP 201 Created):**
```json
{
  "status": "success",
  "data": {
    "uuid_incidencia": "INC_670d8e2a1b9c0",
    "worker_id": 8801,
    "workplace_id": 1,
    "category": "VACATION",
    "current_revision": 1,
    "status": "PENDING_APPROVAL",
    "created_at_utc": "2026-10-14T15:30:00Z"
  }
}
```

---

### 9.5 Endpoint 4: Create Append-Only Revision (Amendment)
- **HTTP Method:** `POST`
- **Path:** `/api/v2/incidences/{uuid_incidencia}/revisions`
- **Caller:** Portal3 Backend
- **Authentication:** Portal3 JWT (`Bearer <jwt>`)
- **Functional Permission:** `checador-v2-incidencias-resolucion.editar = 1` OR `checador-v2-incidencias.crear = 1`
- **Workplace Scope:** Verified against incident workplace.
- **Mandatory Headers:**
  - `Idempotency-Key` (required, string).
- **Request Body (JSON):**
```json
{
  "expected_current_revision": 1,
  "reason": "Modificación de fecha fin por reprogramación autorizada",
  "evidence_reference": "ADDENDUM-VAC-8801",
  "start_date": "2026-10-20",
  "end_date": "2026-10-24"
}
```
- **Append-Only Rule:** Revision 1 is marked `SUPERSEDED`. Monotonic revision 2 is created. Revision 1 data remains permanently untouched.
- **Response Format (HTTP 201 Created):**
```json
{
  "status": "success",
  "data": {
    "uuid_incidencia": "INC_670d8e2a1b9c0",
    "new_revision_number": 2,
    "status": "ACTIVE",
    "created_at_utc": "2026-10-14T15:45:00Z"
  }
}
```

---

### 9.6 Endpoint 5: Resolve Conflict / Administrative Review
- **HTTP Method:** `POST`
- **Path:** `/api/v2/incidences/{uuid_incidencia}/resolve`
- **Caller:** Portal3 Backend
- **Authentication:** Portal3 JWT (`Bearer <jwt>`)
- **Functional Permission:** `checador-v2-incidencias-resolucion.editar = 1`
- **Workplace Scope:** Verified against incident workplace.
- **Mandatory Headers:**
  - `Idempotency-Key` (required, string).
- **Request Body (JSON):**
```json
{
  "expected_current_revision": 1,
  "resolution_action": "CONFIRM_INCIDENCE_PREVALENCE",
  "resolution_notes": "El colaborador acudió físicamente a recoger pertenencias durante vacaciones; las marcas físicas se preservan pero no invalidan las vacaciones.",
  "evidence_reference": "ACTA-ACLARACION-8801"
}
```
- **Response Format (HTTP 200 OK):**
```json
{
  "status": "success",
  "data": {
    "uuid_incidencia": "INC_670d8e2a1b9c0",
    "resolution_revision_number": 2,
    "resolution_status": "RESOLVED_PENDING_AUTHORIZATION",
    "resolved_by_user_id": 105,
    "resolved_at_utc": "2026-10-14T15:50:00Z"
  }
}
```

---

### 9.7 Endpoint 6: Authorize Incident Revision
- **HTTP Method:** `POST`
- **Path:** `/api/v2/incidences/{uuid_incidencia}/authorize`
- **Caller:** Portal3 Backend
- **Authentication:** Portal3 JWT (`Bearer <jwt>`)
- **Functional Permission:** `checador-v2-incidencias-autorizacion.editar = 1`
- **Workplace Scope:** Verified against incident workplace.
- **Mandatory Headers:**
  - `Idempotency-Key` (required, string).
- **Request Body (JSON):**
```json
{
  "expected_current_revision": 1,
  "authorization_notes": "Autorizado formalmente por Jefatura de Recursos Humanos"
}
```
- **Response Format (HTTP 200 OK):**
```json
{
  "status": "success",
  "data": {
    "uuid_incidencia": "INC_670d8e2a1b9c0",
    "authorized_revision_number": 2,
    "status": "ACTIVE",
    "authorized_by_user_id": 108,
    "authorized_at_utc": "2026-10-14T15:55:00Z"
  }
}
```

---

### 9.8 Endpoint 7: Reject Incident Revision
- **HTTP Method:** `POST`
- **Path:** `/api/v2/incidences/{uuid_incidencia}/reject`
- **Caller:** Portal3 Backend
- **Authentication:** Portal3 JWT (`Bearer <jwt>`)
- **Functional Permission:** `checador-v2-incidencias-autorizacion.editar = 1`
- **Workplace Scope:** Verified against incident workplace.
- **Mandatory Headers:**
  - `Idempotency-Key` (required, string).
- **Request Body (JSON):**
```json
{
  "expected_current_revision": 1,
  "rejection_reason": "No cumple con anticipación mínima de solicitud"
}
```
- **Response Format (HTTP 200 OK):**
```json
{
  "status": "success",
  "data": {
    "uuid_incidencia": "INC_670d8e2a1b9c0",
    "rejected_revision_number": 2,
    "status": "REJECTED",
    "rejected_by_user_id": 108,
    "rejected_at_utc": "2026-10-14T16:00:00Z"
  }
}
```

---

### 9.9 Endpoint 8: Revoke / Cancel Incident (Append-Only)
- **HTTP Method:** `POST`
- **Path:** `/api/v2/incidences/{uuid_incidencia}/revoke`
- **Caller:** Portal3 Backend
- **Authentication:** Portal3 JWT (`Bearer <jwt>`)
- **Functional Permission:** `checador-v2-incidencias-autorizacion.eliminar = 1` OR `checador-v2-incidencias-autorizacion.editar = 1`
- **Workplace Scope:** Verified against incident workplace.
- **Mandatory Headers:**
  - `Idempotency-Key` (required, string).
- **Request Body (JSON):**
```json
{
  "expected_current_revision": 2,
  "revocation_reason": "Cancelación solicitada por el colaborador por cambio de fecha",
  "evidence_reference": "CANCEL-FORMATO-8801"
}
```
- **Append-Only Revocation Invariant:** Under NO circumstances is the master row or any prior revision deleted or mutated. A new revision record is inserted with `estado_revision = 'CANCELLED'`. The master row `estado_actual` transitions to `'CANCELLED'`.
- **Response Format (HTTP 200 OK):**
```json
{
  "status": "success",
  "data": {
    "uuid_incidencia": "INC_670d8e2a1b9c0",
    "revocation_revision_number": 3,
    "status": "CANCELLED",
    "revoked_by_user_id": 108,
    "revoked_at_utc": "2026-10-14T16:05:00Z"
  }
}
```

---

### 9.10 Endpoint 9: Read Administrative Evaluation for Journey Day
- **HTTP Method:** `GET`
- **Path:** `/api/v2/administrative-evaluations`
- **Caller:** Portal3 Backend
- **Authentication:** Portal3 JWT (`Bearer <jwt>`)
- **Functional Permission:** `checador-v2-incidencias.leer = 1` OR `biometricos.leer = 1`
- **Workplace Scope:** Verified against `workplace_id` and caller scope.
- **Query Parameters:**
  - `worker_id` (required, int): Canonical `op_rh_personal.id`.
  - `workplace_id` (required, int): Canonical `op_rh_localidades.id`.
  - `work_date` (required, string): Local date `YYYY-MM-DD`.
- **Response Format (HTTP 200 OK):**
```json
{
  "status": "success",
  "data": {
    "worker_id": 8801,
    "workplace_id": 1,
    "work_date": "2026-10-12",
    "evaluation_exists": true,
    "evaluation": {
      "revision_number": 1,
      "administrative_status": "VACATION_APPLIED",
      "human_review_status": "NOT_REQUIRED",
      "fingerprint": "a3f5b7c8...64chars",
      "algorithm_version": "v2.5-d",
      "policy_version": "POLICY_V1.0_DEFAULT",
      "created_at_utc": "2026-10-13T02:00:00Z",
      "associated_calculation_revision_id": 401,
      "linked_incidences": [
        {
          "uuid_incidencia": "INC_670d8a1b2c3d4",
          "category": "VACATION",
          "revision_number": 1,
          "application_status": "APPLIED"
        }
      ],
      "linked_adjustments": []
    }
  }
}
```

---

### 9.11 Endpoint 10: Request / Execute Administrative Evaluation
- **HTTP Method:** `POST`
- **Path:** `/api/v2/administrative-evaluations/evaluate`
- **Caller:** Portal3 Backend
- **Authentication:** Portal3 JWT (`Bearer <jwt>`)
- **Functional Permission:** `checador-v2-incidencias-resolucion.editar = 1` OR `checador-v2-incidencias-autorizacion.editar = 1`
- **Workplace Scope:** Verified against `workplace_id` and caller scope.
- **Mandatory Headers:**
  - `Idempotency-Key` (required, string).
- **Request Body (JSON):**
```json
{
  "worker_id": 8801,
  "workplace_id": 1,
  "work_date": "2026-10-12"
}
```
- **Execution Invariant:** Calls existing `AdministrativeEvaluationService::evaluateShiftAdministration()`. Consumes existing P2 calculation revision and active incidences. If evaluation fingerprint matches an existing evaluation, reuses it idempotently. Append-only triggers prevent any mutation of historical evaluations.
- **Response Format (HTTP 200 OK):**
```json
{
  "status": "success",
  "data": {
    "worker_id": 8801,
    "workplace_id": 1,
    "work_date": "2026-10-12",
    "revision_number": 1,
    "administrative_status": "VACATION_APPLIED",
    "human_review_status": "NOT_REQUIRED",
    "fingerprint": "a3f5b7c8...64chars",
    "created_at_utc": "2026-10-14T16:10:00Z"
  }
}
```

---

## 10. Idempotency Contract

All mutating endpoints (`POST`) require the `Idempotency-Key` HTTP header.

### 10.1 Replay Rules
1. **Identical Replay:** If a request is received with an identical `Idempotency-Key` and an identical semantic payload for the same resource:
   - The API shall NOT create a duplicate revision or duplicate record;
   - The API shall return HTTP 200/201 with the identical response body produced by the original request.
2. **Conflicting Replay:** If a request is received with an identical `Idempotency-Key` but a divergent semantic payload:
   - The request shall immediately fail closed with HTTP 409 `IDEMPOTENCY_CONFLICT`;
   - No database mutation shall occur.

---

## 11. Fail-Closed Error Specifications

All responses use standard HTTP status codes and structured, non-leaking JSON error bodies:

| HTTP Status | Error Code | Trigger Condition |
|---|---|---|
| `400 Bad Request` | `INVALID_PAYLOAD` | Malformed JSON, invalid date format, missing required body field. |
| `401 Unauthorized` | `UNAUTHORIZED` | Missing or invalid Portal3 JWT, expired token, signature mismatch, or Device Auth usage. |
| `403 Forbidden` | `FUNCTION_PERMISSION_DENIED` | Authenticated user lacks required permission in the targeted dedicated ACL namespace. |
| `403 Forbidden` | `STATION_SCOPE_DENIED` | Target workplace is outside caller's authorized multi-station scope. |
| `404 Not Found` | `WORKER_NOT_FOUND` | `worker_id` does not exist in `op_rh_personal`. |
| `404 Not Found` | `INCIDENCE_NOT_FOUND` | `uuid_incidencia` does not exist within the caller's authorized workplace scope. |
| `409 Conflict` | `STALE_REVISION_CONFLICT` | `expected_current_revision` does not match the active database revision (optimistic concurrency). |
| `409 Conflict` | `IDEMPOTENCY_CONFLICT` | Reused `Idempotency-Key` with divergent payload. |
| `409 Conflict` | `INVALID_LIFECYCLE_TRANSITION` | Attempting to authorize a cancelled incident, or revoke an already cancelled incident. |
| `422 Unprocessable` | `INVALID_DATE_RANGE` | `end_date < start_date` or range exceeds operational limits. |
| `422 Unprocessable` | `UNRESOLVED_IDENTITY` | Worker or workplace cannot be canonically resolved with 100% certainty. |
| `500 Server Error` | `SERVER_ERROR` | Internal error. Detailed SQL traces, exceptions, and stack frames are completely sanitized and never leaked. |
| `503 Unavailable` | `SOURCE_UNAVAILABLE` | Legacy evidence adapter data store unreachable. |

---

## 12. Audit & Provenance Invariants

Every administrative operation and evaluation outcome persisted by the API must retain:
1. Canonical worker ID (`op_rh_personal.id`);
2. Canonical Workplace ID (`op_rh_localidades.id`);
3. Calendar date range (`YYYY-MM-DD`);
4. Master incident UUID (`uuid_incidencia`);
5. Revision number (`revision_numero`);
6. Authenticated actor ID (`creado_por_usuario_id`, `aprobado_por_usuario_id`);
7. Authoritative UTC timestamp (`creado_at_utc`);
8. Mandatory justification text (`motivo`);
9. External evidence reference (`folio_evidencia`);
10. Explicit evaluation fingerprint (`fingerprint_ejecucion` SHA-256);
11. Explicit upstream linkage (`id_calculo_jornada` linking immutable P2 calculation revision).

---

## 13. Schema Prerequisites Assessment

- **Physical Tables Existing:** All 7 required V2.5 domain tables (`op_rh_v2_incidencias_maestro`, `op_rh_v2_incidencias_revisiones`, `op_rh_v2_incidencias_aplicaciones`, `op_rh_v2_ajustes_administrativos`, `op_rh_v2_evaluaciones_administrativas`, `op_rh_v2_evaluaciones_incidencias`, `op_rh_v2_evaluaciones_ajustes`) are already created in the repository DDL and development database.
- **Canonical Public UUIDs:** `uuid_incidencia` (`VARCHAR(64)` UNIQUE), `uuid_ajuste` (`VARCHAR(64)` UNIQUE), `uuid_ajuste_logico` (`VARCHAR(64)`), and `fingerprint_ejecucion` (`VARCHAR(64)` UNIQUE) already exist in the schema.
- **Monotonic Revision Numbers:** `revision_numero` exists on both revisions and evaluations.
- **Append-Only Triggers:** Database triggers (`tg_op_rh_v2_evaluaciones_admin_no_update`, `tg_op_rh_v2_evaluaciones_admin_no_delete`) already protect evaluation tables from update/delete.
- **Conclusion:**
  ```text
  SCHEMA_PREREQUISITE_REQUIRED: NO
  SCHEMA_PREREQUISITES: NONE
  ```

---

## 14. Non-Goals & Explicit Exclusions

The following are strictly out of scope for the P3-G boundary:
1. **No P3-H Implementation:** Visual 4-slot morning/afternoon UI projection is deferred to Milestone P3-H.
2. **No Automatic Incapacity:** Legacy incapacity automatic ingestion remains blocked.
3. **No Payroll Calculations:** Wage calculations, overtime compensation, and `RecibosNomina` writes are forbidden.
4. **No Legacy Dual-Write:** Normal operations shall never write to legacy attendance or incidence tables.
5. **No Production Deployment:** This contract authorizes documentation and boundary freeze only.

---

## 15. Contract Freeze Status & Sign-off

- **API Contract Status:** `CLOSED_FROZEN_OWNER_APPROVED`
- **Companion Portal3 Contract Status:** `FROZEN_OWNER_APPROVED`
- **Architecture Freeze Invariant:** Preserved and matched (`87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`)
- **Runtime Modification:** NONE
- **Next Step:** When implementation is explicitly authorized, implement the narrow P3-G API boundary controller and router in `~/Proyectos/portal-api-v.1.0.1` adhering strictly to this frozen specification.
