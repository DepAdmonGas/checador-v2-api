# CHECADOR V2 — PUNCH EVENTS SNAPSHOT READ API CONTRACT (V2.4 LEVEL 1)

> **STATUS:** FROZEN
> **IMPLEMENTATION_STATUS:** NOT_IMPLEMENTED
> **IMPLEMENTATION_AUTHORIZED:** NO
> **LEVEL_1_SCOPE:** READ SNAPSHOT STABILITY / API DB COMPLETENESS
> **LEVEL_2_SCOPE:** OUT OF SCOPE / NOT READY
> **GOVERNANCE REF:** API-V2.4-C

---

## 1. PROPÓSITO Y ALCANCE

Este documento define el contrato formal aditivo candidate para el **API Read Snapshot V2.4 (Level 1)** del Checador V2.

Este contrato es completamente aditivo y retrocompatible con el endpoint existente `GET /api/v2/punch-events` (V2.3), cuyo comportamiento permanece intacto e inalterado.

### 1.1 Definición de Completes Level 1

`LEVEL_1_COMPLETE` garantiza únicamente que el consumidor (Portal3) ha recuperado la totalidad de las marcaciones brutas (`PunchEvent`) que pertenecían a una membresía de snapshot inmutable y exacta, generada bajo una vista MVCC consistente al momento de su creación ($T_{create}$), dentro del alcance de estaciones autorizado.

> [!IMPORTANT]
> **Lo que NO demuestra Level 1:**
> - NO demuestra que la cola de ingesta de Lector esté vacía.
> - NO demuestra que no existan marcaciones retenidas por desconexión en dispositivos físicos.
> - NO demuestra que no existan saltos en la secuencia de eventos de un dispositivo.
> - NO autoriza ni demuestra la deducción de ausencia laboral ("SIN MARCACIÓN" / "FALTA CANDIDATA").
> - NO reemplaza la completitud de ingesta en la nube (Level 2, pendiente de `LECTOR-C1`).

---

## 2. ARQUITECTURA DEL SNAPSHOT

### 2.1 Modelo de Membresía Materializada
La membresía de marcaciones brutas pertenecientes a un snapshot se materializa de forma exacta e inmutable durante su creación mediante una vista aislada MVCC (`START TRANSACTION WITH CONSISTENT SNAPSHOT`).
- **No boundaries inseguros**: Se prohíbe explícitamente depender de boundaries dinámicos como `MAX(id)`, `received_at` dinámico o comparaciones flotantes.

### 2.2 Ciclo de Vida del Snapshot
Todo snapshot sigue un ciclo de vida estrictamente definido por los siguientes estados:
1. `CREATING`: Proceso de colección y persistencia de membresía en curso. El snapshot es estrictamente **no legible** por la API de paginación.
2. `READY`: Membresía persistida completamente con éxito. El snapshot se vuelve consultable mediante su `snapshot_token`.
3. `FAILED`: Ocurrió un error o caída de proceso durante la materialización. La membresía parcial es purgada y el snapshot queda inaccesible.
4. `EXPIRED`: El `Access TTL` transitorio ha expirado. Las tablas/índices transitorios de paginación pueden ser purgados.

### 2.3 Idempotencia de Creación
Para evitar la duplicación accidental de snapshots por timeouts de red en el cliente, el endpoint de creación soporta idempotencia mediante la cabecera `Idempotency-Key` (o campo `creation_request_uuid` en el cuerpo del request). Si se recibe un request idéntico dentro de la ventana de idempotencia, se retorna el handle del snapshot existente en lugar de materializar uno nuevo.

---

## 3. MODELO DE RECURSOS HTTP

### 3.1 Creación de Snapshot
```http
POST /api/v2/punch-events/read-snapshots
Authorization: Bearer <JWT_PORTAL3>
Content-Type: application/json
Idempotency-Key: <UUID_CLIENTE>

{
  "from": "2026-10-01T00:00:00Z",
  "to": "2026-10-05T23:59:59Z",
  "station_id": 15,
  "external_worker_reference": "EMP-9021",
  "device_id": null,
  "capture_method": null
}
```

#### Respuesta `201 Created`
```json
{
  "status": "success",
  "data": {
    "snapshot": {
      "snapshot_token": "snap_tok_live_7f8a9b2c3d4e5f6a",
      "snapshot_audit_ref": "audit_ref_sha256_9a8b7c6d5e4f3a2b1c",
      "status": "READY",
      "created_at": "2026-10-05T12:45:00Z",
      "expires_at": "2026-10-06T12:45:00Z",
      "total_records": 142
    },
    "completeness": {
      "level_1_api_snapshot": true,
      "level_2_ingestion_completeness": false,
      "status": "LEVEL_1_COMPLETE_ONLY"
    }
  }
}
```

### 3.2 Paginación del Snapshot
```http
GET /api/v2/punch-events/read-snapshots/snap_tok_live_7f8a9b2c3d4e5f6a/events?cursor=eyJvY3Vycmlkb19hdF91dGMiOiIyMDI2LTEwLTAzVDEwOjE1OjAwWiIsImlkX2Rpc3Bvc2l0aXZvIjo0LCJldmVudF91dWlkIjoiNWY2YTdiOGMifQ==&limit=100
Authorization: Bearer <JWT_PORTAL3>
```

#### Respuesta `200 OK`
```json
{
  "status": "success",
  "data": {
    "items": [
      {
        "id_dispositivo": 4,
        "event_uuid": "5f6a7b8c-9d0e-11ef-8000-00155d000101",
        "ocurrido_at_utc": "2026-10-03T10:15:00Z",
        "timezone_dispositivo": "America/Mexico_City",
        "modo_ingreso": "BIOMETRIC",
        "verificacion_resultado": "SUCCESS",
        "external_worker_reference": "EMP-9021",
        "id_estacion": 15,
        "processing_status": "RECEIVED",
        "validation_flags": []
      }
    ],
    "pagination": {
      "next_cursor": "eyJvY3Vycmlkb19hdF91dGMiOiIyMDI2LTEwLTA0VDA4OjAwOjAwWiIsImlkX2Rpc3Bvc2l0aXZvIjo0LCJldmVudF91dWlkIjoiOGM3YjZhNWYifQ==",
      "has_more": true,
      "page_size": 100
    }
  }
}
```

---

## 4. SEGURIDAD Y CONTROL DE ACCESO (ACL)

### 4.1 Requisitos de Autenticación y Autorización
- **JWT Issuer:** `portal3_auth`
- **Requisitos de Usuario:** Usuario activo con módulo `biometricos` y permiso `leer`.
- **Precedencia:** Asignación directa por usuario. No aplica bypass por superadministrador.
- **Scope Dinámico de Estaciones:** Scope evaluado en tiempo real en cada llamada de página.

### 4.2 Reglas de Reducción y Expansión de ACL
- **Reducción de Scope (FAIL CLOSED):** Si la autorización del usuario se reduce después de crear el snapshot y ya no abarca la totalidad del alcance de estaciones original del snapshot, la solicitud de página **FALLA** inmediatamente con `403 SNAPSHOT_SCOPE_CHANGED`. No se permite filtrado parcial ni mutación de membresía.
- **Expansión de Scope:** Si el usuario gana permisos a estaciones adicionales, el snapshot existente **NO se expande**. Las nuevas estaciones no se incluyen. Se requiere un nuevo snapshot.
- **Viculación al Creador:** El `snapshot_token` está estrictamente vinculado al `sub` (usuario) creador y al scope autorizado original. Un usuario distinto no puede consumir dicho token.

---

## 5. IDENTIDAD CANÓNICA Y ORDEN TOTAL DETERMINISTA

### 5.1 Identidad Canónica del Evento
- **Identidad de Negocio Expuesta:** Tupla compuesta `(id_dispositivo, event_uuid)`.
- **Identificador Interno de BD (`raw_punch.id`):** Permanece como detalle del servidor. **NUNCA** se expone públicamente en las respuestas ni es requerido por Portal3 como identificador de dominio.

### 5.2 Orden Total Determinista
La paginación basada en cursor opaco utiliza estrictamente el siguiente criterio de ordenamiento total para prevenir omisiones o duplicaciones:
$$(\text{ocurrido\_at\_utc ASC}, \text{id\_dispositivo ASC}, \text{event\_uuid ASC}, \text{internal\_id ASC})$$
*Nota: `internal_id` se utiliza únicamente en el servidor como desempate final absoluto en el improbable caso de marcaciones simultáneas idénticas.*

---

## 6. REPRODUCIBILIDAD Y AUDITORÍA DURABLE

### 6.1 Separación de Estado Transitorio y Auditoría Durable
- **Transient Access State (`CONFIGURABLE_BOUNDED_TTL`):** Contiene el `snapshot_token` y las tablas de índice de paginación activa. Se purga al expirar el TTL de acceso.
- **Durable Audit State (`POLICY_CONTROLLED_DURABLE_RETENTION`):** Retiene el `snapshot_audit_ref`, los parámetros congelados, el alcance original y la lista canónica exacta de miembros `(id_dispositivo, event_uuid)` junto con el digest de integridad manifest SHA256. La expiración del TTL de acceso **NUNCA** destruye la evidencia de auditoría durable.

### 6.2 Manifiesto Canónico y Digest
- **Digest Role:** $SHA256(\text{canonical\_manifest\_bytes})$ valida la integridad del manifiesto durable pero **NO reemplaza** el contenido exacto de los miembros.
- **Reconstrucción Histórica Explicita:** La reconstrucción de un snapshot histórico $S$ se realiza leyendo el manifiesto durable retained. **NO** re-ejecutando una consulta por rango de fechas sobre la tabla cruda, ya que eventos de llegada tardía (*late-arriving events*) habrían alterado la respuesta.

### 6.3 Diferenciación de Reproducibilidad
- **Snapshot Reproducibility:** Demuestra la totalidad de eventos brutos visibles en el API Snapshot $S$ al instante $T_{create}$.
- **Journey Reproducibility:** Registra en Portal3 el `snapshot_audit_ref` + la lista de eventos realmente evaluados por el motor de reglas + versión del motor + versión del turno + versión de la zona horaria.

---

## 7. TRATAMIENTO DE DIAGNÓSTICOS Y EVENTOS ANÓMALOS

### 7.1 Eventos de Llegada Tardía (*Late-Arriving Events*)
Cualquier marcación bruta insertada/comprometida posteriormente a $T_{create}$ **NO se incluye** en el Snapshot $S$, incluso si su `ocurrido_at_utc` cae dentro del rango de fechas de $S$. Formará parte de un snapshot posterior $S_2$.

### 7.2 Marcaciones con Estación Nula o No Resuelta (`NULL`)
No se exponen globalmente a cualquier usuario. Los diagnósticos de completitud sobre estaciones no resueltas se restringen estrictamente a dispositivos asociados al scope autorizado del usuario. Si no se puede probar la asignación histórica dispositivo-estación, se reporta diagnóstico `UNKNOWN`.

### 7.3 Marcaciones con Tiempo Nulo (`ocurrido_at_utc IS NULL`)
Permanecen como hechos originales intactos. Se excluyen del ordenamiento cronológico normal y se exponen exclusivamente mediante diagnósticos de completitud autorizados.

### 7.4 Marcaciones en Estado `PENDING_MATCH`
Se preservan como hechos originales intactos. Se exponen factuales en diagnósticos de completitud de lectura para Portal3 sin forzar resoluciones mediante fallback externo.

---

## 8. MATRIZ DE CÓDIGOS DE ERROR Y CONTRATO DE FALLOS

| Código HTTP | Error Code | Descripción |
| :--- | :--- | :--- |
| `400 Bad Request` | `SNAPSHOT_NOT_READY` | Intentar paginar un snapshot en estado `CREATING` o `FAILED`. |
| `400 Bad Request` | `DATE_RANGE_TOO_LARGE` | Rango de fechas superior al máximo contractual de 31 días. |
| `401 Unauthorized` | `UNAUTHENTICATED` | Bearer JWT ausente o inválido. |
| `403 Forbidden` | `SNAPSHOT_SCOPE_CHANGED` | El scope del usuario cambió y ya no cubre el alcance original del snapshot. |
| `403 Forbidden` | `SNAPSHOT_ACCESS_DENIED` | El usuario no es el creador del snapshot. |
| `404 Not Found` | `SNAPSHOT_EXPIRED` | El `snapshot_token` no existe o superó su `Access TTL`. |
| `422 Unprocessable`| `INVALID_CURSOR` | El cursor proporcionado está alterado o no pertenece a este snapshot. |

---

## 9. INVARIANTE DE INMUTABILIDAD DE TABLAS CRUDAS

El API Read Snapshot V2.4 opera de manera puramente aditiva y no intrusiva:
- **PROHIBIDO** ejecutar `UPDATE` o `DELETE` sobre la tabla `op_rh_v2_marcaciones_crudas`.
- **PROHIBIDO** agregar columnas de estado de snapshot o flags mutables en la tabla cruda.
- Los estados de snapshot y tablas de membresía viven en estructuras aisladas dedicadas.

---

## 10. ESTADO DE GOBERNANZA

- **Estado Documental:** `FROZEN`
- **Autorización de Arquitectura Level 1:** `FROZEN`
- **Autorización de Código de Implementación:** `NO`
- **Fase Autorizada Siguiente:** Evaluación Preflight Lector-C1 (`LECTOR-C1`). Este contrato constituye la referencia oficial congelada de gobernanza para la arquitectura de lectura V2.4 Level 1.
