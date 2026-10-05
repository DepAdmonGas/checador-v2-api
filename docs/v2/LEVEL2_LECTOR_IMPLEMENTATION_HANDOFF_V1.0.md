# LEVEL2_LECTOR_IMPLEMENTATION_HANDOFF_V1.0.md
## API Level 2 Wire & Interface Implementation Handoff for Lector

**AUTHORITATIVE_API_COMMIT:** `553f56afc926d12a82202ce813c0589fa8a71798`  
**LEVEL2_FROZEN_CONTRACT_SHA256:** `7176c45aceb2bb59dba5470cd3b74f729a9bf5e3c797860e1dd752be3fb51a1c`  
**DATE:** `2026-10-05`  
**STATUS:** `APPROVED_FOR_AUTH3_LECTOR_IMPLEMENTATION`  

---

### 1. GOVERNANCE & PURPOSE

This document defines the exact HTTP endpoints, headers, JSON request/response schemas, error codes, authentication protocols, retry idempotency semantics, and transition/cutover rules implemented in the **Checador V2 API** (`portal-api-v.1.0.1`).

It serves as the **authoritative wire-contract implementation handoff** for the physically separate **Lector** client project to implement **AUTH-3 (Lector Level 2 Generation, Sequence & Telemetry)** without inventing or assuming any server-side interface behavior.

---

### 2. AUTHORITY DOMAIN SEPARATION MATRIX

The API strictly segregates **Device Authentication** (biometric terminals/Lector) from **User JWT Authentication** (Portal3 human users/management dashboard):

| Route Path | HTTP Method | Auth Domain | Credentials Required | Intended Consumer |
| :--- | :--- | :--- | :--- | :--- |
| `<V2_API_BASE_URL>/api/v2/punch-events` | `POST` | Device Auth | `X-Device-Id`, `X-Credential-Key`, `Authorization: Bearer <SECRET>` | **Lector** (Punch Ingestion) |
| `<V2_API_BASE_URL>/api/v2/devices/generations` | `POST` | Device Auth | `X-Device-Id`, `X-Credential-Key`, `Authorization: Bearer <SECRET>` | **Lector** (Generation Registration) |
| `<V2_API_BASE_URL>/api/v2/devices/telemetry` | `POST` | Device Auth | `X-Device-Id`, `X-Credential-Key`, `Authorization: Bearer <SECRET>` | **Lector** (Heartbeat & Telemetry) |
| `<V2_API_BASE_URL>/api/v2/level2/window-status` | `GET` | User JWT | `Authorization: Bearer <PORTAL3_JWT>` | **Portal3 Dashboard / Audit UI** |

> **IMPORTANT:** Lector MUST NEVER attempt to call `GET /api/v2/level2/window-status`. That route requires human user JWT authentication with functional ACL grants (`biometricos` -> `leer`). Lector ONLY calls the three Device Auth endpoints above.

---

### 3. DEVICE AUTHENTICATION PROTOCOL

For all device endpoints (`punch-events`, `generations`, `telemetry`), Lector must supply three HTTP headers:

```http
X-Device-Id: <DEVICE_ID_INT>
X-Credential-Key: <CREDENTIAL_KEY_STRING>
Authorization: Bearer <DEVICE_SECRET_BCRYPT_PLAINTEXT>
Content-Type: application/json; charset=utf-8
```

#### Authentication Rules & Errors:
* `X-Device-Id`: Integer string matching the registered device `id` (e.g. `100`).
* `X-Credential-Key`: String matching active credential key for the device (e.g. `key-100`).
* `Authorization`: `Bearer ` followed by the plaintext device secret string.
* **401 UNAUTHORIZED:** Returned if headers are missing, key is invalid/revoked, or secret hash verification fails.
* **403 DEVICE_MISMATCH:** Returned if `X-Device-Id` does not match the device UUID registered in the payload.

---

### 4. ENDPOINT 1: GENERATION REGISTRATION (`POST /api/v2/devices/generations`)

Lector must register its local `device_generation` identifier with the API prior to issuing Level 2 PunchEvents or Telemetry.

#### Request Schema
```json
{
  "device_uuid": "<DEVICE_UUID>",
  "device_generation": "<DEVICE_GENERATION_STRING>"
}
```

| Field Name | Data Type | Required? | Nullable? | Semantic Meaning / Validation |
| :--- | :--- | :--- | :--- | :--- |
| `device_uuid` | `string` | **Yes** | No | Unique hardware/installation UUID of the device (must match authenticated device). |
| `device_generation` | `string` | **Yes** | No | Generation identifier created locally by Lector upon initial boot or factory reset (max 64 chars). |

#### Response Schemas

##### 201 Created (New Generation Registered)
```json
{
  "success": true,
  "code": 201,
  "status": "REGISTERED",
  "id": 15,
  "estado": "ACTIVE"
}
```

##### 200 OK (Idempotent Re-registration)
If Lector re-sends an already registered generation for the same device:
```json
{
  "success": true,
  "code": 200,
  "status": "ALREADY_REGISTERED",
  "id": 15,
  "estado": "ACTIVE"
}
```

##### 403 Forbidden (Device Mismatch / Spoofing Attempt)
If `device_uuid` does not match the authenticated `X-Device-Id`:
```json
{
  "success": false,
  "code": 403,
  "status": "DEVICE_MISMATCH",
  "error": "El dispositivo autenticado no coincide con el UUID proporcionado."
}
```

---

### 5. ENDPOINT 2: PUNCH EVENT INGESTION (`POST /api/v2/punch-events`)

Lector streams raw biometric attendance punches to this endpoint.

#### Request Schema (Level 2 Enhanced)
```json
{
  "event_uuid": "ev-550e8400-e29b-41d4-a716-446655440000",
  "id_dispositivo": 100,
  "referencia_externa_personal": "EMP-10042",
  "source_timestamp": "2026-10-05T08:00:00.000-06:00",
  "source_timezone": "America/Mexico_City",
  "metodo_captura": "FINGERPRINT",
  "external_event_id": "PUNCH-99881",
  "device_generation": "gen-100-v1",
  "device_sequence": 1,
  "metadata": {}
}
```

| Field Name | Data Type | Level 1 | Level 2 | Nullable? | Validation / Behavior |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `event_uuid` | `string` (UUID v4) | Required | Required | No | Canonical PunchEvent identity. Immutable once assigned by Lector. |
| `id_dispositivo` | `integer` | Required | Required | No | Device ID (must match `X-Device-Id`). |
| `referencia_externa_personal` | `string` | Required | Required | No | External worker ID / Payroll badge number. |
| `source_timestamp` | `string` (ISO 8601) | Required | Required | No | Original punch timestamp with offset. |
| `source_timezone` | `string` | Optional | Optional | Yes | Reported IANA timezone name. |
| `metodo_captura` | `string` | Required | Required | No | Capture method (`FINGERPRINT`, `PIN`, `FACIAL`, `CARD`). |
| `external_event_id` | `string` | Optional | Optional | Yes | Lector internal database PK or ticket string. |
| `device_generation` | `string` | **Omitted** | **Required** | No | Registered generation string for Level 2 governing. |
| `device_sequence` | `integer` | **Omitted** | **Required** | No | Monotonic 1-based sequence integer for Level 2 sequence tracking. |
| `metadata` | `object` | Optional | Optional | Yes | Key-value JSON object for capture diagnostics. |

#### Level 2 Transition Rule
* **Pre-Level2 Events:** If `device_generation` or `device_sequence` is missing/null, the API ingests the event as a **Level 1 unsequenced PunchEvent**. No error is raised, ensuring backwards compatibility for legacy queues.
* **Level 2 Governed Events:** If both `device_generation` and `device_sequence` are present, the API validates generation registration, binds Level 2 sidecar metadata (`op_rh_v2_marcaciones_nivel2_metadata`), and enforces sequence collision / contiguity checks.
* **Partial Level 2 Payload:** If only one of `device_generation` or `device_sequence` is provided without the other, the API rejects the request with HTTP `400 BAD_REQUEST`.

#### Response Schemas

##### 201 Created (New Governed Event Accepted)
```json
{
  "code": 201,
  "status": "ACCEPTED",
  "event_uuid": "ev-550e8400-e29b-41d4-a716-446655440000",
  "recibido_at_utc": "2026-10-05 14:00:05",
  "processing_status": "RECEIVED",
  "id_personal_resuelto": 42,
  "validation_flags": []
}
```

##### 200 OK (Idempotent Retry of Same Event)
If Lector retries an exact event (`same event_uuid`, `same device_generation`, `same device_sequence`):
```json
{
  "code": 200,
  "status": "ALREADY_RECEIVED",
  "event_uuid": "ev-550e8400-e29b-41d4-a716-446655440000",
  "recibido_at_utc": "2026-10-05 14:00:05",
  "processing_status": "RECEIVED",
  "id_personal_resuelto": 42,
  "validation_flags": []
}
```

##### 409 Conflict (Sequence Collision / Divergent Retry)
* If `device_sequence` was already assigned to a *different* `event_uuid`:
```json
{
  "code": 409,
  "status": "SEQUENCE_COLLISION",
  "mensaje": "La secuencia ya fue asignada a otro evento diferente dentro de la misma generación."
}
```
* If `event_uuid` already exists with a *different* sequence or generation:
```json
{
  "code": 409,
  "status": "SEQUENCE_MISMATCH",
  "mensaje": "El evento ya tiene metadatos Nivel 2 vinculados con diferente secuencia o generación."
}
```

---

### 6. ENDPOINT 3: OPERATIONAL TELEMETRY INGESTION (`POST /api/v2/devices/telemetry`)

Lector periodically streams operational heartbeats and telemetry logs to this endpoint.

#### Request Schema
```json
{
  "device_generation": "gen-100-v1",
  "telemetry_sequence": 1,
  "observation_timestamp": "2026-10-05T07:55:00.000Z",
  "battery_level": 98,
  "network_status": "CONNECTED",
  "clock_status": "SYNCED",
  "payload": {
    "temperature_celsius": 36.5,
    "storage_free_mb": 4096,
    "sdk_initialized": true,
    "sensor_connected": true,
    "capture_worker_alive": true,
    "queue_writable": true
  }
}
```

| Field Name | Data Type | Required? | Nullable? | Semantic Meaning / Validation |
| :--- | :--- | :--- | :--- | :--- |
| `device_generation` | `string` | **Yes** | No | Registered generation identifier. |
| `telemetry_sequence` | `integer` | **Yes** | No | Monotonic 1-based telemetry sequence integer. |
| `observation_timestamp` | `string` | **Yes** | No | Timestamp when telemetry was captured on device (ISO 8601 or UTC raw string). |
| `battery_level` | `integer` | Optional | Yes | Battery percentage (0-100). |
| `network_status` | `string` | Optional | Yes | Network status (`CONNECTED`, `DISCONNECTED`, `CELLULAR`, `WIFI`). |
| `clock_status` | `string` | Optional | Yes | Hardware clock quality status (`SYNCED`, `DRIFT_WARNING`, `UNSYNCED`, `NTP_UNAVAILABLE`). |
| `payload` | `object` | Optional | Yes | Arbitrary JSON object containing operational metrics, SDK health, and diagnostics. |

#### Offline & Re-ordered Telemetry Semantics
* `observation_timestamp` MUST record the actual device-side observation time.
* The API records `server_received_at_utc` automatically upon HTTP arrival. Lector MUST NOT overwrite `observation_timestamp` with upload time.
* Telemetry records are stored durably in `op_rh_v2_dispositivo_telemetria`. The API retains full history (does not keep only the latest heartbeat).

#### Response Schemas

##### 201 Created (Telemetry Recorded)
```json
{
  "success": true,
  "code": 201,
  "status": "TELEMETRY_RECORDED",
  "id": 102
}
```

##### 400 Bad Request (Unregistered Generation)
```json
{
  "success": false,
  "code": 400,
  "status": "INVALID_GENERATION",
  "error": "La generación enviada no está registrada para este dispositivo."
}
```

---

### 7. PUNCH & TELEMETRY ERROR MATRIX

| HTTP Status | Machine Status Code | Meaning / Root Cause | Lector Action / Retryability | Same Event UUID Retain? | Same Sequence Retain? |
| :---: | :--- | :--- | :--- | :---: | :---: |
| `201` | `ACCEPTED` / `REGISTERED` / `TELEMETRY_RECORDED` | Successfully created and stored. | Remove from local retry queue. | N/A | N/A |
| `200` | `ALREADY_RECEIVED` / `ALREADY_REGISTERED` | Idempotent duplicate re-send. | Treat as success; remove from queue. | YES | YES |
| `400` | `BAD_REQUEST` / `INVALID_GENERATION` | Missing required fields or generation unregistered. | **Do NOT retry blindly.** Register generation first, then retry. | YES | YES |
| `401` | `UNAUTHORIZED` | Invalid secret, expired key, or missing headers. | **Pause queue.** Re-authenticate / refresh credentials. | YES | YES |
| `403` | `DEVICE_MISMATCH` | `X-Device-Id` does not match `device_uuid`. | **Fail-closed / Log Error.** Misconfigured device credentials. | YES | YES |
| `409` | `SEQUENCE_COLLISION` | Sequence number already used by *different* `event_uuid`. | **LECTOR_DECISION_REQUIRED.** Log severe sequence bug. Do not mutate `event_uuid`. | YES | YES |
| `409` | `SEQUENCE_MISMATCH` | `event_uuid` already bound to different sequence/generation. | **LECTOR_DECISION_REQUIRED.** Log inconsistency. | YES | YES |
| `500` | `INTERNAL_ERROR` | Server database or unexpected runtime error. | **Retryable.** Exponential backoff retry. | YES | YES |
| `503` | `SERVICE_UNAVAILABLE` | Database connection or server overload. | **Retryable.** Exponential backoff retry. | YES | YES |

---

### 8. LECTOR SEQUENCE ALLOCATION & CUTOVER PROCEDURE

#### Sequence Rules for Lector Implementation
1. **Scope:** `device_sequence` is strictly scoped to `(id_dispositivo, device_generation)`.
2. **Monotonicity:** Sequence MUST start at `1` for a new generation and increment monotonically (`1, 2, 3...`).
3. **HTTP Retry Rule:** Lector MUST NEVER increment `device_sequence` during HTTP retries or network re-connections. Retries of an unacknowledged PunchEvent MUST re-send the EXACT same `event_uuid`, `device_generation`, and `device_sequence`.
4. **Pre-Level2 Queue Preservation:** Existing PunchEvents stored in Lector's SQLite/local database prior to Level 2 cutover MUST be sent WITHOUT `device_generation` and WITHOUT `device_sequence` (as Level 1 events). Lector MUST NOT retroactively assign sequences to legacy queued events.

#### Ordered Cutover Sequence for Lector
1. **Finish Legacy Stream:** Allow current offline queue to drain or remain marked as Level 1.
2. **Generate Local Generation:** Upon Level 2 cutover or factory reset, generate a unique `device_generation` string (e.g. `gen-<DEVICE_ID>-<TIMESTAMP_OR_UUID>`) and persist it locally.
3. **Register Generation:** Send `POST /api/v2/devices/generations` to API. Confirm HTTP `201 REGISTERED` or `200 ALREADY_REGISTERED`.
4. **Initialize Sequence Counters:**
   * Set `local_punch_sequence = 1`
   * Set `local_telemetry_sequence = 1`
5. **Begin Governed Streaming:**
   * Attach `device_generation` and `device_sequence` to all new PunchEvents.
   * Attach `device_generation` and `telemetry_sequence` to all periodic Telemetry heartbeats.

---

### 9. LECTOR CONFIGURATION MAPPING

| Existing / New Setting | Type | Example Value | Description |
| :--- | :--- | :--- | :--- |
| `V2_API_BASE_URL` | `string` | `https://portal3.admongas.com.mx/portal-api-v.1.0.1` | Base URL of the Checador V2 API. |
| `V2_DEVICE_ID` | `integer` | `100` | Local device numeric identifier (`X-Device-Id`). |
| `V2_DEVICE_UUID` | `string` | `dev-uuid-100` | Canonical hardware UUID. |
| `V2_CREDENTIAL_KEY` | `string` | `key-100` | Active API credential key (`X-Credential-Key`). |
| `V2_DEVICE_SECRET` | `string` | `secret123` | Plaintext device secret (`Authorization: Bearer ...`). |
| `NEW_LECTOR_SETTING_REQUIRED` (`V2_LEVEL2_ENABLED`) | `boolean` | `true` | Enables Level 2 generation, sequence tracking, and telemetry streaming. |
| `NEW_LECTOR_SETTING_REQUIRED` (`V2_TELEMETRY_INTERVAL_SEC`) | `integer` | `300` | Heartbeat interval in seconds (default 300s = 5 min). |

---

### 10. AUTH-3 LECTOR IMPLEMENTATION CHECKLIST

- [ ] **Generation Persistence:** Persist active `device_generation` string in local database/storage across device reboots.
- [ ] **Generation Registration:** Issue `POST /api/v2/devices/generations` upon startup and verify `201/200` response before sending Level 2 events.
- [ ] **Atomic Sequence Allocation:** Atomically allocate 1-based `device_sequence` for PunchEvents within the active generation.
- [ ] **Stable Retry Identity:** Ensure retries preserve identical `(event_uuid, device_generation, device_sequence)` tuple.
- [ ] **Legacy Queue Preservation:** Ingest pre-cutover queued items as Level 1 without generating fake sequences.
- [ ] **Telemetry Worker & Sequences:** Implement background telemetry worker sending `POST /api/v2/devices/telemetry` with monotonic `telemetry_sequence`.
- [ ] **Durable Telemetry Buffering:** Buffer telemetry offline if network is down; preserve original `observation_timestamp` upon upload.
- [ ] **API Error Handling:** Implement status mapping per Error Matrix (Section 7).
- [ ] **Authority Domain Adherence:** Do NOT call human/JWT endpoints (`/api/v2/level2/window-status`).

---

### 11. NO ARCHITECTURE SPECIFICATION AMENDMENT

This document describes the **currently implemented API wire interface** (`553f56afc926d12a82202ce813c0589fa8a71798`).  
It does **NOT** amend, alter, or replace:
1. `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`
2. `docs/v2/PUNCH_EVENTS_SNAPSHOT_READ_API_CONTRACT_V2.4_LEVEL1.md`
3. `docs/architecture/CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md`

All contract definitions and governance freezes remain 100% authoritative and unchanged.
