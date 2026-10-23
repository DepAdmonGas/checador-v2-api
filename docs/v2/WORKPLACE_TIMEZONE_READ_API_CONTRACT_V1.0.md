# WORKPLACE TIMEZONE READ API CONTRACT V1.0 (FROZEN SPECIFICATION)

**Document Status:** FROZEN TECHNICAL SPECIFICATION V1.0  
**Activity:** `CHECADOR_V2_WORKPLACE_TIMEZONE_READ_BOUNDARY_CONTRACT_FREEZE`  
**Governance:** Aligned with `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md` and `docs/architecture/CHECADOR_V2_WORKPLACE_TIMEZONE_AUTHORITY_ADDENDUM_V1.0.md`.  
**Runtime Implementation:** NOT STARTED (SPECIFICATION ONLY)

---

## 1. PURPOSE AND ARCHITECTURAL SCOPE

This document defines the narrow, read-only HTTP API contract for resolving the authoritative IANA timezone and corresponding local-day UTC query window for a specific workplace and calendar date:

`GET /api/v2/workplaces/{workplace_id}/timezone?date=YYYY-MM-DD`

### 1.1 Problem Statement and Context
Portal3 operates in Option B (server-to-server reads against Checador V2 API using Portal3 JWT). To query raw attendance records via `GET /api/v2/punch-events`, Portal3 supervisors select an authorized workplace and a local calendar day (e.g., `2026-10-12`). Under the Checador V2 architecture:
- Raw punch events are strictly stored and indexed in UTC (`ocurrido_at_utc`).
- Workplace timezone authority is owned exclusively by Checador V2 in `op_rh_v2_localidades_zonas_horarias`, referencing `op_rh_localidades.id`.
- Direct SQL access by Portal3 into Checador V2 tables is strictly prohibited.
- Device timezones, server host timezones, PHP defaults, and hardcoded fallbacks (such as `America/Mexico_City`) are prohibited as canonical authority.

### 1.2 Boundary Responsibilities (Option B Model)
To avoid duplicating complex timezone and DST transition logic in consumer clients, the Checador V2 API assumes full authority for both:
1. Resolving the authoritative IANA timezone for `(workplace_id, local_date)`.
2. Calculating the exact, DST-accurate UTC interval `[from_utc, to_utc)` representing the complete local calendar day (`[YYYY-MM-DD 00:00:00, next_day 00:00:00)` in local civil time).

Portal3 consumes `from_utc` and `to_utc` directly to parameterize downstream calls to `GET /api/v2/punch-events?station_id={workplace_id}&from={from_utc}&to={to_utc}`.

### 1.3 Strict Source Completeness Boundary
This endpoint provides **TEMPORAL AUTHORITY ONLY**. It returns zero attendance classifications, zero punch summaries, and zero payroll evaluations. It must never fabricate or return concepts such as `SIN MARCACIÓN`, `FALTA`, `RETARDO`, `JORNADA`, or `INCIDENCIA`.

---

## 2. ROUTING AND URI DESIGN

### 2.1 Canonical URI
```http
GET /api/v2/workplaces/{workplace_id}/timezone?date={date}
```

### 2.2 URI Evaluation & Consistency
- **Resource Hierarchy:** The canonical workplace identifier in Checador V2 is `op_rh_localidades.id`. The URI pattern `/api/v2/workplaces/{workplace_id}/...` directly reflects standard RESTful resource hierarchy and matches the domain naming established in `CHECADOR_V2_WORKPLACE_TIMEZONE_AUTHORITY_ADDENDUM_V1.0.md`.
- **Query Parameter:** `date` represents a civil calendar date (`YYYY-MM-DD`).
- **Rejected Alternatives:**
  - `GET /api/v2/stations/{id}/timezone`: Rejected because the domain entity is `workplace` (`op_rh_localidades`), whereas `station` (`id_gas`) is legacy perimeter terminology.
  - POST-based RPC: Rejected because timezone resolution is an idempotent, safe, cacheable read operation.

---

## 3. AUTHENTICATION & AUTHORIZATION MODEL

### 3.1 Authentication: Portal3 Server-Side JWT
- **Header:** `Authorization: Bearer <Portal3_JWT>`
- **Validation Engine:** `Portal3JwtAuthHelper::validateToken()`
- **Issuer (`iss`):** Must strictly match `portal3_auth`.
- **Subject (`sub`):** Must be a positive integer referencing an active user in `tb_usuarios` (`estatus = 0`).
- **Signature:** HMAC-SHA256 verified against `JWT_SECRET` environment variable.
- **Expiration:** Active token verification (`exp > time()`).
- **Prohibited Authentication:**
  - Device Auth (`X-Device-Id`, `X-Credential-Key`) is strictly rejected (`401 UNAUTHORIZED`).
  - Lector bearer tokens / session cookies are rejected.
  - Browser-held secrets or client-supplied API keys are rejected.

### 3.2 Authorization: Double-Check Workplace Scope (Fail-Closed)
The API independently validates the user's scope. Portal3 client assertions are never trusted without server-side verification:
1. **Module Check:** The authenticated identity must possess `leer = 1` grant on the `biometricos` module (derived via `modulos_usuarios_do` direct grant precedence, or `modulos_puestos_do` role fallback).
2. **Workplace Scope Resolution:** The user's authorized workplace IDs are resolved from `tb_multiestacion_usuario`, `tb_multiestacion_puesto`, or home station `tb_usuarios.id_gas`, mapped into `op_rh_localidades.id` space and intersected with active supported stations in `tb_modulos_config`.
3. **Target Workplace Enforcement:** If the requested `{workplace_id}` path parameter is NOT present in the authenticated user's authorized workplace list:
   - Returns `HTTP 403 FORBIDDEN` (`WORKPLACE_SCOPE_DENIED`).
   - Fails closed immediately without database lookup into timezone configuration.

---

## 4. INPUT SPECIFICATION & VALIDATION

### 4.1 Path Parameters
| Parameter | Type | Validation Rule |
| :--- | :--- | :--- |
| `workplace_id` | Integer | Required. Positive integer (`> 0`). Must reference an existing record in `op_rh_localidades.id`. |

### 4.2 Query Parameters
| Parameter | Type | Required | Validation Rule |
| :--- | :--- | :--- | :--- |
| `date` | String | Required | Strict format `YYYY-MM-DD`. Valid calendar date verified by Gregorian calendar rules. |

### 4.3 Validation Constraints
- **Strict Date-Only:** Time components, UTC offsets, or time zone designators (e.g., `2026-10-12T00:00:00Z`) in the `date` parameter are strictly rejected with `HTTP 400 BAD_REQUEST`.
- **No Client Timezone Override:** The client cannot provide a suggested or override timezone parameter. Any parameter such as `timezone` or `tz` is ignored or rejected.
- **Fail-Closed on Unknown Workplace:** If `{workplace_id}` does not exist in `op_rh_localidades`, returns `HTTP 404 NOT_FOUND` (`WORKPLACE_NOT_FOUND`).

---

## 5. AUTHORITATIVE TIMEZONE RESOLUTION LOGIC

### 5.1 Resolution Source
Resolution queries the effective-dated table `op_rh_v2_localidades_zonas_horarias`:
```sql
SELECT timezone_iana, valido_desde, valido_hasta
FROM op_rh_v2_localidades_zonas_horarias
WHERE id_localidad = ?
  AND valido_desde <= ?
  AND (valido_hasta IS NULL OR ? < valido_hasta)
ORDER BY valido_desde ASC;
```

### 5.2 Resolution Cardinality Rules
1. **Zero Matches:** If no row satisfies the date predicate, the resolution fails closed. Returns `HTTP 404 NOT_FOUND` with error code `WORKPLACE_TIMEZONE_UNRESOLVED`.
2. **Multiple Matches (> 1):** If more than one row matches, an integrity violation (unauthorized configuration overlap) has occurred. The resolution fails closed without using `LIMIT 1`. Returns `HTTP 409 CONFLICT` with error code `WORKPLACE_TIMEZONE_AMBIGUOUS`.
3. **Invalid Stored Value:** If the stored `timezone_iana` string is not a valid, exact-case identifier in `DateTimeZone::listIdentifiers()`, returns `HTTP 500 INTERNAL_ERROR` with error code `WORKPLACE_TIMEZONE_INVALID`.
4. **Prohibition of Global Fallback:** The server MUST NEVER fall back to `America/Mexico_City`, device metadata, or server OS timezone.

---

## 6. LOCAL-DAY UTC WINDOW CALCULATION

### 6.1 Calculation Algorithm
When a canonical `timezone_iana` is resolved for the local date `YYYY-MM-DD`:
1. Instantiate `DateTimeZone($timezoneIana)`.
2. Compute `start_local`: `new DateTimeImmutable("{$date} 00:00:00", $tz)`.
3. Compute `end_local`: `new DateTimeImmutable("{$date} 00:00:00 +1 day", $tz)` (representing `00:00:00` of the subsequent calendar day).
4. Convert both boundaries independently to UTC:
   - `$fromUtc = $startLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');`
   - `$toUtc = $endLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');`

### 6.2 DST / Transition Invariance
- Both boundaries are converted independently through the timezone database rules.
- Under standard 24-hour days, duration is exactly 86,400 seconds.
- On DST "spring forward" days (e.g., 23-hour local civil day), the interval duration is 82,800 seconds.
- On DST "fall back" days (e.g., 25-hour local civil day), the interval duration is 90,000 seconds.
- Computing `end = start + 24 hours` is **STRICTLY PROHIBITED**.

---

## 7. RESPONSE SPECIFICATION

### 7.1 Success Response (`HTTP 200 OK`)
```json
{
  "code": 200,
  "status": "OK",
  "data": {
    "workplace_id": 14,
    "local_date": "2026-10-12",
    "timezone": "America/Mexico_City",
    "from_utc": "2026-10-12T06:00:00Z",
    "to_utc": "2026-10-13T06:00:00Z"
  }
}
```

### 7.2 Response Field Definitions
- `workplace_id` (integer): Authoritative workplace ID matching `op_rh_localidades.id`.
- `local_date` (string): Echoes the requested calendar date `YYYY-MM-DD`.
- `timezone` (string): Canonical, exact-case IANA timezone identifier (e.g., `America/Mexico_City`, `America/Tijuana`, `America/Cancun`).
- `from_utc` (string): Inclusive start of the local calendar day in ISO-8601 UTC (`YYYY-MM-DDTHH:MM:SSZ`).
- `to_utc` (string): Exclusive end of the local calendar day in ISO-8601 UTC (`YYYY-MM-DDTHH:MM:SSZ`), corresponding to `00:00:00` local time of the next calendar day.

---

## 8. FAIL-CLOSED RESPONSE MATRIX

| HTTP Status | Error Status Code | Condition | Response Payload Example |
| :--- | :--- | :--- | :--- |
| `400 Bad Request` | `BAD_DATE` | Query parameter `date` is missing, malformed, non-Gregorian, or includes time/zone offset. | `{"code":400,"status":"BAD_DATE","mensaje":"Fecha local inválida. Formato estricto requerido: YYYY-MM-DD."}` |
| `400 Bad Request` | `INVALID_WORKPLACE_ID` | Path parameter `workplace_id` is non-numeric or `<= 0`. | `{"code":400,"status":"INVALID_WORKPLACE_ID","mensaje":"Identificador de localidad inválido."}` |
| `401 Unauthorized` | `UNAUTHORIZED` | Missing, malformed, expired, non-`portal3_auth`, or invalid signature JWT; Device Auth header present. | `{"code":401,"status":"UNAUTHORIZED","mensaje":"Token de autenticación Portal3 inválido o ausente."}` |
| `403 Forbidden` | `FUNCTION_PERMISSION_DENIED` | Authenticated user lacks `leer = 1` permission on `biometricos` module. | `{"code":403,"status":"FUNCTION_PERMISSION_DENIED","mensaje":"Permiso funcional insuficiente para consultar huso horario."}` |
| `403 Forbidden` | `WORKPLACE_SCOPE_DENIED` | Requested `workplace_id` is outside the user's authorized workplace scope. | `{"code":403,"status":"WORKPLACE_SCOPE_DENIED","mensaje":"Acceso denegado: el centro de trabajo solicitado está fuera de su alcance."}` |
| `404 Not Found` | `WORKPLACE_NOT_FOUND` | `workplace_id` does not exist in canonical table `op_rh_localidades`. | `{"code":404,"status":"WORKPLACE_NOT_FOUND","mensaje":"El centro de trabajo especificado no existe."}` |
| `404 Not Found` | `WORKPLACE_TIMEZONE_UNRESOLVED` | No approved effective mapping exists in `op_rh_v2_localidades_zonas_horarias` for `(workplace_id, date)`. | `{"code":404,"status":"WORKPLACE_TIMEZONE_UNRESOLVED","mensaje":"Sin huso horario oficial configurado para el centro de trabajo en la fecha solicitada."}` |
| `409 Conflict` | `WORKPLACE_TIMEZONE_AMBIGUOUS` | Multiple overlapping configurations exist for `(workplace_id, date)`. | `{"code":409,"status":"WORKPLACE_TIMEZONE_AMBIGUOUS","mensaje":"Configuración de huso horario ambigua o corrupta para el centro de trabajo."}` |
| `500 Internal Error`| `WORKPLACE_TIMEZONE_INVALID` | Stored `timezone_iana` is unrecognized by PHP timezone database. | `{"code":500,"status":"WORKPLACE_TIMEZONE_INVALID","mensaje":"Error interno: identificador de huso horario almacenado no es un estándar IANA válido."}` |
| `500 Internal Error`| `INTERNAL_ERROR` | Database connection or SQL query execution failure. Sanitized; no raw exception disclosure. | `{"code":500,"status":"INTERNAL_ERROR","mensaje":"Error interno al resolver huso horario del centro de trabajo."}` |

---

## 9. BUSINESS CONFIGURATION STATUS

- **Current Database State:** The underlying table `op_rh_v2_localidades_zonas_horarias` exists in development schema.
- **Business Mappings:** `PENDING_APPROVAL`.
- **Contract Resilience:** This contract requires zero guessed or mock mappings to be valid. In the absence of an approved business mapping, every validly authenticated and authorized query returns `HTTP 404 NOT_FOUND` (`WORKPLACE_TIMEZONE_UNRESOLVED`). A successful `HTTP 200 OK` can only occur once business operations explicitly author and approve effective-dated timezone records.

---

## 10. ARCHITECTURE FREEZE EVALUATION

- **Compatibility:** This endpoint provides a read-only boundary for existing domain authority defined in `CHECADOR_V2_WORKPLACE_TIMEZONE_AUTHORITY_ADDENDUM_V1.0.md` without modifying any legacy payroll table, device capture flow, or raw punch event immutability.
- **Architecture Freeze Impact:** **NO AMENDMENT REQUIRED**. It fits within existing architectural principles and fulfills Option B requirements approved by the project owner.

---

## 11. CONTRACT TEST PLAN

| Test ID | Scenario Description | Expected Outcome |
| :--- | :--- | :--- |
| `TZR01` | Valid Portal3 JWT + authorized workplace + resolved business mapping | `200 OK` with canonical timezone and correct `from_utc`/`to_utc` |
| `TZR02` | Missing `Authorization` header | `401 UNAUTHORIZED` |
| `TZR03` | Invalid or expired Portal3 JWT | `401 UNAUTHORIZED` |
| `TZR04` | Valid JWT requesting a workplace outside the user's station scope | `403 FORBIDDEN` (`WORKPLACE_SCOPE_DENIED`) |
| `TZR05` | Valid JWT requesting non-existent workplace ID | `404 NOT_FOUND` (`WORKPLACE_NOT_FOUND`) |
| `TZR06` | Valid JWT and authorized workplace, but zero timezone mappings configured | `404 NOT_FOUND` (`WORKPLACE_TIMEZONE_UNRESOLVED`) |
| `TZR07` | Workplace has a corrupted/non-IANA timezone string stored in database | `500 INTERNAL_ERROR` (`WORKPLACE_TIMEZONE_INVALID`) |
| `TZR08` | Workplace has overlapping effective configuration rows in database | `409 CONFLICT` (`WORKPLACE_TIMEZONE_AMBIGUOUS`) |
| `TZR09` | Local date is strictly before the `valido_desde` of the earliest mapping | `404 NOT_FOUND` (`WORKPLACE_TIMEZONE_UNRESOLVED`) |
| `TZR10` | Local date is on or after `valido_hasta` of an expired mapping without open-ended row | `404 NOT_FOUND` (`WORKPLACE_TIMEZONE_UNRESOLVED`) |
| `TZR11` | Client supplies query parameters attempting to specify or override timezone (`tz=...`) | Client parameters ignored; authoritative database timezone returned |
| `TZR12` | Request includes Device Auth headers (`X-Device-Id`, `X-Credential-Key`) | `401 UNAUTHORIZED` |
| `TZR13` | Request attempts cookie/browser-based authentication without Bearer JWT | `401 UNAUTHORIZED` |
| `TZR14` | Local date falls on DST spring-forward boundary (23-hour civil day) | `from_utc` and `to_utc` span exactly 23 hours (82,800s) |
| `TZR15` | Local date falls on DST fall-back boundary (25-hour civil day) | `from_utc` and `to_utc` span exactly 25 hours (90,000s) |
| `TZR16` | Local date falls on standard non-DST day (24-hour civil day) | `from_utc` and `to_utc` span exactly 24 hours (86,400s) |
| `TZR17` | Unmapped workplace does not fall back to `America/Mexico_City` or server default | `404 NOT_FOUND` (`WORKPLACE_TIMEZONE_UNRESOLVED`) |
| `TZR18` | Response payload inspected for secrets, credentials, or internal hashes | No tokens, DB passwords, or internal secrets returned |
| `TZR19` | Response payload inspected for synthetic attendance classifications | Zero attendance conclusions (`FALTA`, `RETARDO`, `SIN MARCACIÓN`) present |
| `TZR20` | Multiple identical queries for identical workplace and date | Deterministic, idempotent byte-identical response |
