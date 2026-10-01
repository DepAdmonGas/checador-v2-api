# ESPECIFICACIÓN DEL CONTRATO DE INGESTA API V2

**API Version:** V2.1 (Shadow Mode)  
**Endpoint:** `POST /portal-api-v.1.0.1/api/v2/punch-events`  
**ContentType:** `application/json; charset=utf-8`

---

## 1. HEADERS HTTP REQUERIDOS

| Header | Tipo | Descripción | Ejemplo |
| :--- | :--- | :--- | :--- |
| `X-Device-Id` | String | UUID permanente del dispositivo | `DEV-ZK-EST01-001` |
| `X-Credential-Key` | String | Identificador no secreto de la credencial activa | `CRD-KEY-2026-A` |
| `Authorization` | String | Bearer token con la clave secreta | `Bearer SEC_SUPER_SECRET_98765` |

---

## 2. CUERPO DE LA PETICIÓN (JSON REQUEST)

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

### Descripción de Campos Request:
- `event_uuid` (Obligatorio, String): UUID único de la marcación generado en el cliente antes de la transmisión. Base de la idempotencia.
- `external_worker_reference` (Obligatorio, String): ID o número de referencia reportado por el lector físico (ej. número de huella o PIN).
- `source_timestamp` (Obligatorio, String): String original de la fecha/hora reportada por el dispositivo.
- `source_timezone` (Opcional, String): Nombre IANA de la zona horaria reportada (ej. `America/Mexico_City`).
- `capture_method` (Opcional, String): `BIOMETRIC`, `PIN`, `CARD`, `NFC`, `MOBILE`, `WEB_TERMINAL`, etc. (Default: `BIOMETRIC`).
- `external_event_id` (Opcional, String): ID de secuencia interno del hardware ZKTeco.
- `device_sequence` (Opcional, Int): Secuencia numérica incremental del dispositivo.
- `metadata` (Opcional, Object): Objeto libre con detalles técnicos.

---

## 3. RESPUESTAS HTTP (JSON RESPONSES)

### A. 201 CREADO (ACCEPTED)
Respuesta emitida cuando la marcación es recibida e insertada por primera vez.

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

### B. 200 OK (ALREADY_RECEIVED - Idempotente)
Respuesta emitida cuando se reenvía una marcación con un `(X-Device-Id, event_uuid)` ya existente. El Lector debe marcar la marcación como sincronizada localmente.

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

### C. 401 NO AUTORIZADO (UNAUTHORIZED)
Respuesta emitida si la credencial del dispositivo es inválida, expirada o está revocada.

```json
{
  "code": 401,
  "status": "NO AUTORIZADO",
  "mensaje": "Autenticación de dispositivo fallida.",
  "error_detail": "INVALID_SECRET"
}
```

### D. 400 SOLICITUD INCORRECTA (BAD REQUEST)
Respuesta emitida si faltan campos obligatorios en el JSON.

```json
{
  "code": 400,
  "status": "SOLICITUD INCORRECTA",
  "mensaje": "Campos obligatorios ausentes: event_uuid, external_worker_reference, source_timestamp."
}
```
