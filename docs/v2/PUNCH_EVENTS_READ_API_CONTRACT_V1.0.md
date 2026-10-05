# PUNCH EVENTS READ API CONTRACT V1.0 (SPECIFICATION CANDIDATE)

**Document Status:** CANDIDATE SPECIFICATION FOR V2.3-G4B
**Governance:** Aligned with `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md` and `docs/v2/PUNCH_EVENTS_INTEGRITY_ADDENDUM_V1.0.md`.

---

## 1. PURPOSE AND SCOPE

This document specifies the read-only HTTP API contract for querying raw punch events and their cryptographic integrity status:

`GET /api/v2/punch-events`

This endpoint is designed for consumption by **Portal3** back-office supervisors and system components to inspect unaltered raw attendance logs, historical device capture metadata, and server-side cryptographic seals.

### Key Governance Constraints:
1. **READ-ONLY:** No POST, PUT, PATCH, or DELETE operations exist for editing or deleting raw punches.
2. **NO JOURNEY CLASSIFICATION:** This endpoint returns pure raw events without classifying them into Entrance, Meal Start, Meal End, or Exit, and without fabricating missing punch slots or generating attendance infractions.
3. **FAIL-CLOSED SECURITY:** Protected by Portal3 Human JWT authentication and fine-grained ACL station scoping.

---

## 2. AUTHENTICATION & AUTHORIZATION MODEL

### Authentication:
- **Header:** `Authorization: Bearer <Portal3_JWT>`
- **Validation Helper:** `Portal3JwtAuthHelper::validateToken()`
- **Issuer:** Must strictly be `portal3_auth`.
- **Subject:** `payload.sub` must be a positive integer matching an active user in `tb_usuarios` (`estatus = 0`).
- **Expiration:** Fresh token check (`exp > time()`).

### Authorization (ACL):
- **Module Key:** `biometricos` (existing functional module key in `modulos_dpto_operativo` & `tb_modulos_config`).
- **Required Permission:** `leer = 1` (read-only).
- **ACL Precedence (Strict Non-Merging):**
  1. Check if user has any direct grant in `modulos_usuarios_do`.
  2. If direct grants exist, evaluate `biometricos.leer` exclusively from `modulos_usuarios_do`.
  3. If no direct grants exist globally, fallback to role grants in `modulos_puestos_do`.
- **Station Scope (Server-Side Filter):**
  - Resolved fresh via user/puesto multi-station configuration (`tb_multiestacion_usuario` / `tb_multiestacion_puesto`) or home station (`tb_usuarios.id_gas`).
  - Intersected with active supported stations in `tb_modulos_config` for `biometricos`.
  - Mapped to `op_rh_localidades.id` space.
  - Queries are strictly restricted to the user's authorized station locality IDs.

---

## 3. QUERY PARAMETERS

All query parameters must be validated strictly with prepared statements.

| Parameter | Type | Required | Description / Validation |
| :--- | :--- | :--- | :--- |
| `from` | String (ISO-8601 UTC) | Optional | Start of range, e.g., `2026-10-01T00:00:00Z` or `Y-m-d H:i:s` UTC. Default: 30 days prior. Maximum date range duration: 31 days (2678400 seconds). |
| `to` | String (ISO-8601 UTC) | Optional | End of range, e.g., `2026-10-02T23:59:59Z` or `Y-m-d H:i:s` UTC. Default: Current UTC. Maximum date range duration: 31 days (2678400 seconds). |
| `station_id` | Integer | Optional | Must match one of the user's server-side authorized station locality IDs. If unauthorized, returns `403 FORBIDDEN`. |
| `external_worker_reference` | String | Optional | Filter by external worker ID / num_emp (max 64 chars). |
| `device_id` | Integer | Optional | Filter by device ID (`id_dispositivo`). |
| `capture_method` | String | Optional | Filter by method: `BIOMETRIC`, `PIN`, `TEMPORARY_CODE`, etc. |
| `page` | Integer | Optional | Page number (default: 1, min: 1). |
| `per_page` | Integer | Optional | Items per page (default: 50, max: 200). |

---

## 4. DYNAMIC INTEGRITY VERIFICATION

For each row fetched from `op_rh_v2_marcaciones_crudas`, `PunchEventIntegrityService::verify()` is executed dynamically against the server's key ring:

- `VALID`: HMAC matches canonical JSON byte-for-byte.
- `INVALID`: Tampering detected on protected fields.
- `UNVERIFIABLE`: Version unknown or key ID missing from key ring.
- `LEGACY_UNSEALED`: Historical pre-V2.3 record where all integrity columns are `NULL`.

*Note:* `integrity_seal` (HMAC hex) is NOT exposed in the JSON response payload to avoid disclosing internal hashes, but `integrity_version`, `integrity_key_id`, and `integrity_status` are included.

---

## 5. RESPONSE FORMAT (JSON V1)

### HTTP 200 OK
```json
{
  "code": 200,
  "status": "OK",
  "data": [
    {
      "event_uuid": "550e8400-e29b-41d4-a716-446655440000",
      "id_dispositivo": 10,
      "id_estacion_resuelta": 5,
      "referencia_externa_personal": "EMP-001",
      "id_personal_resuelto": 100,
      "source_timestamp_raw": "2026-10-02T12:00:00Z",
      "source_timezone_reported": "America/Mexico_City",
      "effective_timezone": "America/Mexico_City",
      "timezone_fallback_applied": 0,
      "ocurrido_at_utc": "2026-10-02 18:00:00",
      "recibido_at_utc": "2026-10-02 18:00:01",
      "metodo_captura": "BIOMETRIC",
      "external_event_id": "EXT-123",
      "secuencia_dispositivo": 1,
      "processing_status": "RECEIVED",
      "validation_flags": [],
      "metadata": {
        "firmware": "2.0.1"
      },
      "integrity": {
        "version": "v1",
        "key_id": "k1",
        "status": "VALID"
      }
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 50,
    "total_records": 1,
    "total_pages": 1
  }
}
```

---

## 6. ERROR RESPONSES

- `400 BAD REQUEST`: Invalid parameters, bad date range (`from > to`), date range duration > 31 days (`DATE_RANGE_TOO_LARGE`), or `per_page > 200`.
- `401 UNAUTHORIZED`: Missing, invalid, expired, or non-`portal3_auth` JWT.
- `403 FORBIDDEN`: Missing `biometricos.leer` grant or station out of scope.
- `500 INTERNAL ERROR`: Server-side database failure.
- `503 SERVICE UNAVAILABLE`: ACL/Database dependency error.

---

## 7. PROPOSED DATABASE INDEX OPTIMIZATION

To support multi-column queries by station locality and date range efficiently:

```sql
ALTER TABLE `op_rh_v2_marcaciones_crudas`
  ADD INDEX `idx_estacion_ocurrido` (`id_estacion_resuelta`, `ocurrido_at_utc`);
```
*(No DDL executed in G4A - candidate for G4B).*
