# CHECADOR API V2 — ENDPOINT REFERENCE

**API Version:** V2.1  
**Content-Type:** `application/json; charset=utf-8`  
**Base URL:** `/api/v2`

---

## SUMARIO DE ENDPOINTS

| Método | Ruta | Actor | Tipo de Autenticación |
| :--- | :--- | :--- | :--- |
| `POST` | `/api/v2/punch-events` | Lector | Device Auth |
| `POST` | `/api/v2/temporary-access/requests` | Lector | Device Auth |
| `GET` | `/api/v2/temporary-access/requests` | Portal3 | Supervisor JWT |
| `POST` | `/api/v2/temporary-access/requests/{uuid}/authorize` | Portal3 | Supervisor JWT |
| `POST` | `/api/v2/temporary-access/requests/{uuid}/reject` | Portal3 | Supervisor JWT |
| `POST` | `/api/v2/temporary-access/requests/{uuid}/cancel` | Portal3 | Supervisor JWT |
| `POST` | `/api/v2/temporary-access/validate` | Lector | Device Auth |

---

## 1. INGESTA DE MARCACIONES CRUDAS

### 1.1 POST `/api/v2/punch-events`

**Propósito:** Ingesta inalterable de un hecho original de marcación biométrica o de contingencia.

#### Headers Requeridos:
- `X-Device-Id`: UUID permanente del dispositivo (ej. `DEV-ZK-EST01-001`).
- `X-Credential-Key`: Identificador no secreto de la credencial (ej. `CRD-KEY-2026-A`).
- `Authorization`: `Bearer <secret>` (Clave secreta del dispositivo).

#### Cuerpo de la Petición (Request Body):
```json
{
  "event_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "external_worker_reference": "150",
  "source_timestamp": "2026-09-28 12:30:00",
  "source_timezone": "America/Mexico_City",
  "capture_method": "BIOMETRIC",
  "external_event_id": "99812",
  "device_sequence": 142,
  "metadata": {
    "firmware": "v2.1.0"
  }
}
```

- `event_uuid` (String, Obligatorio): UUIDv4 generado en el cliente para idempotencia.
- `external_worker_reference` (String, Obligatorio): ID reportado por el hardware/lector.
- `source_timestamp` (String, Obligatorio): Fecha/hora local reportada por el dispositivo.
- `source_timezone` (String, Opcional): Nombre IANA de la zona horaria del dispositivo.
- `capture_method` (String, Opcional): `BIOMETRIC`, `TEMPORARY_CODE`, `CARD`, `PIN`, etc.
- `external_event_id` (String, Opcional): ID secuencial de hardware.
- `device_sequence` (Int, Opcional): Contador numérico incremental del cliente.
- `metadata` (Object, Opcional): Metadatos adicionales (ej. UUIDs de contingencia).

#### Respuestas HTTP:

- **201 Created (ACCEPTED):** Marcación recibida y registrada exitosamente por primera vez.
  ```json
  {
    "code": 201,
    "status": "ACCEPTED",
    "event_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "recibido_at_utc": "2026-09-28 18:30:00",
    "processing_status": "RECEIVED",
    "id_personal_resuelto": 150,
    "validation_flags": []
  }
  ```

- **200 OK (ALREADY_RECEIVED - Idempotente):** Reenvío de un `event_uuid` previamente registrado para la misma combinación de dispositivo.
  ```json
  {
    "code": 200,
    "status": "ALREADY_RECEIVED",
    "event_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "recibido_at_utc": "2026-09-28 18:30:00",
    "processing_status": "RECEIVED",
    "id_personal_resuelto": 150,
    "validation_flags": []
  }
  ```

- **400 Bad Request:** Estructura JSON inválida o campos requeridos ausentes.
- **401 Unauthorized:** Credenciales de dispositivo inválidas, expiradas o revocadas.

---

## 2. SOLICITUD Y GESTIÓN DE ACCESOS TEMPORALES

### 2.1 POST `/api/v2/temporary-access/requests`

**Propósito:** Crear una nueva solicitud de acceso temporal cuando falla la captura de huella dactilar.

#### Headers Requeridos:
`X-Device-Id`, `X-Credential-Key`, `Authorization: Bearer <secret>`

#### Request Body:
```json
{
  "request_uuid": "c71e8400-e29b-41d4-a716-446655440001",
  "external_worker_reference": "150",
  "reason": "FINGERPRINT_NOT_RECOGNIZED"
}
```

#### Respuestas HTTP:
- **201 Created (REQUEST_ACCEPTED):** Solicitud creada o devuelta por idempotencia (`request_uuid` idéntico).
  ```json
  {
    "code": 201,
    "status": "REQUEST_ACCEPTED",
    "request_uuid": "c71e8400-e29b-41d4-a716-446655440001",
    "request_status": "PENDING",
    "mensaje": "Solicitud de acceso temporal registrada exitosamente."
  }
  ```
- **400 Bad Request:** UUID no válido o payload incompleto.
- **404 Not Found (WORKER_NOT_FOUND):** Trabajador no enrolado o no resuelto para el dispositivo.

---

### 2.2 GET `/api/v2/temporary-access/requests`

**Propósito:** Listar solicitudes activas en estado `PENDING` pertenecientes a las estaciones autorizadas del supervisor autenticado.

#### Headers Requeridos:
`Authorization: Bearer <Portal3_JWT>`

#### Parámetros Query (Opcionales):
`?id_estacion=1` (Permite filtrar por estación específica dentro de las autorizadas).

#### Respuestas HTTP:
- **200 OK:**
  ```json
  {
    "code": 200,
    "status": "OK",
    "data": [
      {
        "request_uuid": "c71e8400-e29b-41d4-a716-446655440001",
        "id_personal_resuelto": 150,
        "nombre_trabajador": "Juan Pérez",
        "id_estacion": 1,
        "dispositivo_nombre": "Lector Estación 1",
        "reason": "FINGERPRINT_NOT_RECOGNIZED",
        "requested_at_utc": "2026-09-30 15:00:00",
        "request_expires_at_utc": "2026-09-30 15:30:00"
      }
    ]
  }
  ```
- **403 Forbidden:** Si el supervisor intenta filtrar una estación a la que no tiene acceso.

---

### 2.3 POST `/api/v2/temporary-access/requests/{request_uuid}/authorize`

**Propósito:** Autorizar una solicitud `PENDING` por parte de un supervisor y generar el PIN temporal de 6 dígitos.

#### Headers Requeridos:
`Authorization: Bearer <Portal3_JWT>`

#### Path Parameters:
`request_uuid`: UUID de la solicitud.

#### Respuestas HTTP:
- **200 OK:**
  ```json
  {
    "code": 200,
    "status": "AUTHORIZED",
    "request_uuid": "c71e8400-e29b-41d4-a716-446655440001",
    "temporary_pin": "849201",
    "pin_expires_at_utc": "2026-09-30 15:15:00",
    "mensaje": "Código temporal generado. Proporcione este código al trabajador (Se muestra una sola vez)."
  }
  ```
- **403 Forbidden:** Si la solicitud pertenece a una estación no asignada al supervisor.
- **409 Conflict (REQUEST_EXPIRED / INVALID_STATE):** Si la solicitud expiró o ya no está en estado `PENDING`.

---

### 2.4 POST `/api/v2/temporary-access/requests/{request_uuid}/reject`

**Propósito:** Rechazar explícitamente una solicitud en estado `PENDING`.

#### Headers Requeridos:
`Authorization: Bearer <Portal3_JWT>`

#### Respuestas HTTP:
- **200 OK:**
  ```json
  {
    "code": 200,
    "status": "REJECTED",
    "request_uuid": "c71e8400-e29b-41d4-a716-446655440001",
    "mensaje": "Solicitud rechazada exitosamente."
  }
  ```

---

### 2.5 POST `/api/v2/temporary-access/requests/{request_uuid}/cancel`

**Propósito:** Cancelar manualmente por supervisor una solicitud activa (`PENDING` o `AUTHORIZED`).

#### Headers Requeridos:
`Authorization: Bearer <Portal3_JWT>`

#### Respuestas HTTP:
- **200 OK:**
  ```json
  {
    "code": 200,
    "status": "CANCELLED",
    "request_uuid": "c71e8400-e29b-41d4-a716-446655440001",
    "mensaje": "Solicitud cancelada exitosamente."
  }
  ```

---

### 2.6 POST `/api/v2/temporary-access/validate`

**Propósito:** Validar el PIN ingresado por el trabajador en el Lector para autorizar la marcación.

#### Headers Requeridos:
`X-Device-Id`, `X-Credential-Key`, `Authorization: Bearer <secret>`

#### Request Body:
```json
{
  "external_worker_reference": "150",
  "temporary_pin": "849201",
  "validation_uuid": "e91e8400-e29b-41d4-a716-446655440099"
}
```

#### Respuestas HTTP:
- **200 OK (VALID):** PIN validado correctamente. Transiciona la solicitud atómicamente a `USED`.
  ```json
  {
    "code": 200,
    "status": "VALID",
    "request_uuid": "c71e8400-e29b-41d4-a716-446655440001",
    "validation_uuid": "e91e8400-e29b-41d4-a716-446655440099",
    "id_personal_resuelto": 150,
    "mensaje": "Código temporal validado exitosamente. Registre el PunchEvent."
  }
  ```
- **400 Bad Request (INVALID_PIN):** PIN incorrecto. Retorna intentos restantes (ej. `{"attempts_remaining": 2}`).
- **400 Bad Request (EXPIRED):** PIN o solicitud vencidos por tiempo.
- **403 Forbidden (LOCKED):** Solicitud bloqueada automáticamente tras superar 3 intentos fallidos.
- **409 Conflict (ALREADY_USED):** Solicitud consumida previamente por un `validation_uuid` diferente.

---

## 3. REGLAS DE REINVENTO Y LOST ACK

1. **Lost ACK en `POST /punch-events`:** El Lector reenvía la petición con el mismo `event_uuid`. El API detecta duplicado en `(id_dispositivo, event_uuid)` y responde `200 ALREADY_RECEIVED`.
2. **Lost ACK en `POST /temporary-access/requests`:** El Lector reenvía la petición con el mismo `request_uuid`. El API retorna `201 REQUEST_ACCEPTED` con el estado original `PENDING`.
3. **Lost ACK en `POST /temporary-access/validate`:** El Lector reenvía la petición con el mismo `validation_uuid`. El API detecta `status = 'USED'` y `successful_validation_uuid == validation_uuid`, retornando nuevamente `200 VALID`.
