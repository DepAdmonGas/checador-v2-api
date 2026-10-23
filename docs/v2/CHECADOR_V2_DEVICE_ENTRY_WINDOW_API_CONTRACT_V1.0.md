# CHECADOR V2 — DEVICE ENTRY WINDOW API CONTRACT V1.0

**Document Status:** APPROVED_FROZEN_V1.4 (Especificación técnica de contrato aprobada y congelada)  
**Governance Scope:** Frozen Device-Facing Protocol Addendum to `docs/v2/PUNCH_EVENTS_INGESTION_API.md` and `docs/architecture/CHECADOR_V2_EARLY_ENTRY_GATEKEEPER_ADDENDUM_V1.0.md`  
**Revision:** V1.4  
**Approval Date:** 2026-10-20  
**Approval Authority:** Project Owner  
**Shared Scope:** `portal-api-v.1.0.1` (API) y `AdmonGasLector` (Lector Windows)  
**Approved Directional Decisions:**
- **Decisión A:** Detección automática de Entrada/Salida en sensor Live20R sin botones F1/F2.
- **Decisión B:** Bloqueo de entrada ordinaria únicamente con horario confiable antes de `T_inicio - 10 minutos`.
- **Decisión C:** Ingesta y confirmación obligatoria para trabajadores sin horario asignado (`UNSCHEDULED`).
- **Decisión D:** Diferenciación estricta entre `UNSCHEDULED`, `SCHEDULE_UNKNOWN` y `DAY_OFF`.
- **Decisión E:** Aprobación de diseño de la tabla aditiva `op_rh_v2_marcaciones_intentos_auditoria`.
- **Decisión F:** Debounce de 30 segundos configurable contra dobles capturas.
- **Decisión G:** Procedimiento de regularización administrativa en Portal3.
- **Decisión I:** Aceptación y captura obligatoria de marcaciones en Día de Descanso (`DAY_OFF`), utilizando la cola ordinaria sin generar huecos de secuencia ni horas extras automáticas.

**Target Client Hardware:** Aplicación Windows en C# + Sensor USB ZKTeco Live20R  
**Local Storage Configuration:** Resuelto mediante `V2_LOCAL_DATA_SUBFOLDER` y el resolvedor de almacenamiento de `DurablePunchQueue`.  
Ruta real del piloto aislado: `%AppData%\AdmonGasLector_Preprod_Dev2\v2_punch_queue.json`  
**Target Milestone:** V2.7+ (Pre-production Hardening & Gatekeeper Service)  
**Runtime Implementation:** NOT STARTED (SPECIFICATION ONLY)  
**Database Executed:** NO  

---

## 1. PRINCIPIOS DE AUTORIDAD Y LÍMITES DE RESPONSABILIDAD

### 1.1 Autoridad Exclusiva de la API y Desacoplamiento del Cliente
- **Autoridad de Dirección y Horarios:** La API central y el motor de jornada (`JourneyPairingService`) son la única autoridad para interpretar si un evento corresponde a una `ENTRADA` o a una `SALIDA`, y si existe un turno programado o día de descanso.
- **Rol del Lector:** El Lector Windows es un perímetro de captura física. **No calcula jornadas ni alterna estados de dirección de forma unilateral.** En modo en línea, consulta a la API la decisión autorizada (`gatekeeper_action`).
- **Resiliencia Multi-Terminal y Concurrencia:** En estaciones con múltiples terminales (ej. PC 1 en mostrador y PC 2 en oficina), la API central concilia los eventos de todas las terminales en MariaDB para evitar decisiones inconsistentes o carreras de estado. Una consulta previa a la compuerta no garantiza por sí sola la ausencia total de carreras concurrentes; la reconciliación definitiva la realiza el motor P1 al procesar la ingesta.
- **Autenticación Fuerte de Dispositivo (SEC-L03):** Se utilizan exclusivamente credenciales de hardware (`X-Device-Id`, `X-Credential-Key`, `Authorization: Bearer <secret>` verificado con Bcrypt en `op_rh_v2_dispositivo_credenciales`). Los dispositivos tienen prohibido el uso de tokens JWT administrativos de Portal3.
- **Cero Datos Biométricos:** Queda prohibido transmitir o almacenar minucias, vectores o plantillas biométricas en los payloads del protocolo.

---

## 2. ESPECIFICACIÓN DETALLADA DE ENDPOINTS

### 2.1 Endpoint 1: Consulta de Decisión Gatekeeper y Ventana de Entrada

```http
GET /portal-api-v.1.0.1/api/v2/device/workers/{external_worker_reference}/entry-window?current_timestamp_local={ISO}&pending_local_punches_count={INT}&last_local_pending_timestamp={ISO}&last_local_pending_uuid={UUID}
```

#### Headers Requeridos
| Header | Tipo | Descripción | Ejemplo |
| :--- | :--- | :--- | :--- |
| `X-Device-Id` | String | UUID inmutable del dispositivo registrado | `DEV-ZK-EST01-001` |
| `X-Credential-Key` | String | Identificador público de credencial activa | `CRD-KEY-2026-A` |
| `Authorization` | String | Clave secreta con esquema Bearer (Bcrypt) | `Bearer SEC_SUPER_SECRET_98765` |

#### Parámetros URI y Query
- `external_worker_reference` (Path, Obligatorio, String): Identificador o número de huella reportado por el sensor Live20R (ej. `"150"`).
- `current_timestamp_local` (Query, Opcional, String): Timestamp actual del reloj de la PC (`YYYY-MM-DD HH:MM:SS`) para detección de deriva horaria.
- `pending_local_punches_count` (Query, Opcional, Int): Conteo neutral de marcaciones locales en cola pendientes de sincronización para el trabajador.
- `last_local_pending_timestamp` (Query, Opcional, String): Timestamp local del último registro pendiente en la cola local para ese trabajador.
- `last_local_pending_uuid` (Query, Opcional, String): UUID del último registro pendiente en la cola local.

---

#### Respuestas HTTP

##### Caso 1: 200 OK — Entrada Bloqueada por Anticipada (`gatekeeper_action = REJECT_EARLY_ENTRY`)
Emitido cuando existe un turno programado confiable y la hora actual es anterior a `T_inicio - 10 minutos`.

```json
{
  "status": "success",
  "data": {
    "gatekeeper_action": "REJECT_EARLY_ENTRY",
    "is_entry_allowed": false,
    "should_record_punch": false,
    "inferred_direction": "ENTRY",
    "external_worker_reference": "150",
    "workplace_id": 1,
    "fecha_local": "2026-10-19",
    "timezone": "America/Mexico_City",
    "estado_jornada": "SCHEDULED",
    "scheduled_start_local": "14:00:00",
    "scheduled_end_local": "22:00:00",
    "earliest_allowed_entry_local": "13:50:00",
    "server_time_local": "2026-10-19 13:45:12",
    "seconds_until_allowed": 288,
    "minutes_until_allowed": 5,
    "desktop_ui_message": {
      "header": "ENTRADA ANTICIPADA NO PERMITIDA",
      "detail": "Tu turno comienza a las 14:00. Podrás registrar tu entrada a partir de las 13:50.",
      "card_color": "RED",
      "sound_alert": "ALERT_WARNING"
    }
  }
}
```

##### Caso 2: 200 OK — Entrada Permitida en Ventana (`gatekeeper_action = ALLOW_ENTRY`)
Emitido cuando la hora es igual o posterior a `T_inicio - 10 minutos`.

```json
{
  "status": "success",
  "data": {
    "gatekeeper_action": "ALLOW_ENTRY",
    "is_entry_allowed": true,
    "should_record_punch": true,
    "inferred_direction": "ENTRY",
    "external_worker_reference": "150",
    "workplace_id": 1,
    "fecha_local": "2026-10-19",
    "timezone": "America/Mexico_City",
    "estado_jornada": "SCHEDULED",
    "scheduled_start_local": "14:00:00",
    "scheduled_end_local": "22:00:00",
    "earliest_allowed_entry_local": "13:50:00",
    "server_time_local": "2026-10-19 13:52:14",
    "seconds_until_allowed": 0,
    "desktop_ui_message": {
      "header": "ENTRADA REGISTRADA",
      "detail": "Hora: 13:52:14. ¡Bienvenido!",
      "card_color": "GREEN",
      "sound_alert": "CONFIRMATION_SUCCESS"
    }
  }
}
```

##### Caso 3: 200 OK — Salida Detectada por el Servidor (`gatekeeper_action = ALLOW_EXIT`)
Emitido cuando la API determina que el trabajador tiene una entrada previa abierta en esa estación. No aplica el bloqueo de 10 minutos.

```json
{
  "status": "success",
  "data": {
    "gatekeeper_action": "ALLOW_EXIT",
    "is_entry_allowed": true,
    "should_record_punch": true,
    "inferred_direction": "EXIT",
    "external_worker_reference": "150",
    "workplace_id": 1,
    "fecha_local": "2026-10-19",
    "timezone": "America/Mexico_City",
    "estado_jornada": "SCHEDULED",
    "scheduled_start_local": "14:00:00",
    "scheduled_end_local": "22:00:00",
    "earliest_allowed_entry_local": null,
    "server_time_local": "2026-10-19 22:04:10",
    "desktop_ui_message": {
      "header": "SALIDA REGISTRADA",
      "detail": "Hora: 22:04:10. ¡Buen descanso!",
      "card_color": "BLUE",
      "sound_alert": "CONFIRMATION_SUCCESS"
    }
  }
}
```

##### Caso 4: 200 OK — Trabajador Sin Horario Asignado (`gatekeeper_action = ALLOW_UNSCHEDULED` - Decisión C)
Emitido cuando la identidad biométrica es válida y la API confirma que **no tiene turno programado para hoy**. La marcación **debe guardarse e ingresarse** sin alterar el flujo ordinario.

```json
{
  "status": "success",
  "data": {
    "gatekeeper_action": "ALLOW_UNSCHEDULED",
    "is_entry_allowed": true,
    "should_record_punch": true,
    "inferred_direction": "ENTRY",
    "external_worker_reference": "150",
    "workplace_id": 1,
    "fecha_local": "2026-10-19",
    "timezone": "America/Mexico_City",
    "estado_jornada": "UNSCHEDULED",
    "scheduled_start_local": null,
    "scheduled_end_local": null,
    "earliest_allowed_entry_local": null,
    "server_time_local": "2026-10-19 13:45:12",
    "metadata_payload": {
      "schedule_status": "UNSCHEDULED"
    },
    "desktop_ui_message": {
      "header": "MARCACIÓN REGISTRADA",
      "detail": "SIN HORARIO ASIGNADO. PENDIENTE DE REVISIÓN POR RH.",
      "card_color": "AMBER",
      "sound_alert": "CONFIRMATION_NEUTRAL"
    }
  }
}
```

##### Caso 5: 200 OK — Marcación en Día de Descanso (`gatekeeper_action = ALLOW_DAY_OFF` - Decisión I Aprobada)
Emitido cuando la API confirma que el trabajador tiene asignado descanso programado (`DAY_OFF`). La marcación **se acepta obligatoriamente**, preservando la evidencia laboral sin generar asistencia ordinaria ni horas extras automáticas.

```json
{
  "status": "success",
  "data": {
    "gatekeeper_action": "ALLOW_DAY_OFF",
    "is_entry_allowed": true,
    "should_record_punch": true,
    "inferred_direction": "ENTRY",
    "external_worker_reference": "150",
    "workplace_id": 1,
    "fecha_local": "2026-10-19",
    "timezone": "America/Mexico_City",
    "estado_jornada": "DAY_OFF",
    "scheduled_start_local": null,
    "scheduled_end_local": null,
    "earliest_allowed_entry_local": null,
    "server_time_local": "2026-10-19 13:45:12",
    "metadata_payload": {
      "schedule_status": "DAY_OFF"
    },
    "desktop_ui_message": {
      "header": "MARCACIÓN REGISTRADA",
      "detail": "DÍA DE DESCANSO PROGRAMADO. PENDIENTE DE REVISIÓN POR RH.",
      "card_color": "AMBER",
      "sound_alert": "CONFIRMATION_NEUTRAL"
    }
  }
}
```

##### Caso 6: 200 OK — Dirección Ambigua (`gatekeeper_action = AMBIGUOUS_DIRECTION`)
Emitido ante inconsistencia irresoluble entre terminales o turnos superpuestos.

```json
{
  "status": "success",
  "data": {
    "gatekeeper_action": "AMBIGUOUS_DIRECTION",
    "is_entry_allowed": false,
    "should_record_punch": false,
    "inferred_direction": "AMBIGUOUS",
    "external_worker_reference": "150",
    "desktop_ui_message": {
      "header": "VERIFICACIÓN REQUERIDA",
      "detail": "No se pudo determinar si es Entrada o Salida. Consulte al supervisor.",
      "card_color": "AMBER",
      "sound_alert": "ALERT_ERROR"
    }
  }
}
```

---

### 2.2 Endpoint 2: Registro de Auditoría de Intentos Rechazados (Decisión E)

```http
POST /portal-api-v.1.0.1/api/v2/device/access-audits
Content-Type: application/json; charset=utf-8
```

#### Propósito
Persistir los intentos bloqueados por entrada anticipada o eventos ambiguos en un canal independiente sin alterar la secuencia Nivel 2 ni contaminar `op_rh_v2_marcaciones_crudas`.

#### Payload de Solicitud (JSON Request)
```json
{
  "attempt_uuid": "7a3b4c5d-6e7f-8a9b-0c1d-2e3f4a5b6c7d",
  "external_worker_reference": "150",
  "event_type": "EARLY_ENTRY_ATTEMPT",
  "rejection_reason": "REJECTED_TOO_EARLY",
  "inferred_direction": "ENTRY",
  "attempt_timestamp_local": "2026-10-19 13:45:12",
  "scheduled_start_local": "14:00:00",
  "earliest_allowed_entry_local": "13:50:00",
  "device_clock_status": "SYNCHRONIZED",
  "clock_drift_seconds": 2,
  "client_version": "Lector-Windows-Live20R-v2.3.1"
}
```

#### Respuesta (201 Created)
```json
{
  "status": "success",
  "data": {
    "attempt_uuid": "7a3b4c5d-6e7f-8a9b-0c1d-2e3f4a5b6c7d",
    "recorded_at_utc": "2026-10-19 19:45:13",
    "status": "AUDITED"
  }
}
```

---

### 2.3 Endpoint 3: Descarga de Horarios Activos para Caché Local Fuera de Línea

```http
GET /portal-api-v.1.0.1/api/v2/device/active-schedules?days_ahead=2
```

#### Propósito
Permite al Lector descargar y mantener en memoria y disco local bajo `%AppData%\AdmonGasLector_Preprod_Dev2\station_schedules.json` (`V2_LOCAL_DATA_SUBFOLDER`) las asignaciones de turno de la estación para contingencias de red.

#### Respuesta (200 OK)
```json
{
  "status": "success",
  "data": {
    "workplace_id": 1,
    "workplace_name": "LOCALIDAD PILOTO 01 - NORTE",
    "timezone": "America/Mexico_City",
    "generated_at_utc": "2026-10-19 12:00:00",
    "cache_ttl_seconds": 86400,
    "early_window_minutes": 10,
    "debounce_threshold_seconds": 30,
    "schedules": [
      {
        "external_worker_reference": "150",
        "id_personal": 558,
        "date_local": "2026-10-19",
        "day_of_week": "LUNES",
        "shift_type": "REGULAR",
        "scheduled_start_local": "14:00:00",
        "scheduled_end_local": "22:00:00",
        "earliest_allowed_entry_local": "13:50:00",
        "is_day_off": false
      }
    ]
  }
}
```

---

## 3. INTEGRIDAD DE SECUENCIA NIVEL 2 Y PREVENCIÓN DE HUECOS

1. **Momento de Asignación de Secuencia:** El Lector asigna `device_sequence` estrictamente al momento de ejecutar `Enqueue` en la cola atómica ordinaria `v2_punch_queue.json`.
2. **Resolución Previa (Pre-Enqueue):**
   - El descarte por debounce (< 30 s) y el bloqueo por entrada anticipada (`REJECT_EARLY_ENTRY`) se evalúan y ejecutan **antes de encolar**.
   - **Garantía Anti-Huecos:** Al resolverse antes de la cola ordinaria, los intentos bloqueados **no consumen `device_sequence`**. Esto garantiza una secuencia estrictamente continua `1, 2, 3...` en `op_rh_v2_marcaciones_nivel2_metadata`, satisfaciendo la regla de completitud Nivel 2 (`delivery_completeness = 'VERIFIED'`).
   - Las marcaciones aceptadas (`SCHEDULED` en ventana, `UNSCHEDULED` y `DAY_OFF`) utilizan la cola ordinaria y consumen secuencia Nivel 2 continua sin generar huecos.
3. **Auditoría Aislada:** Los registros de auditoría de intentos utilizan un identificador independiente `attempt_uuid` y no interfieren con la numeración de marcaciones de asistencia.

---

## 4. ESPECIFICACIÓN DE CONTINGENCIA OFFLINE Y COLA AISLADA

> [!IMPORTANT]
> **Especificación de Diseño (No Preexistente):** Esta sección define el contrato y requisitos de persistencia que deberán implementarse en el Lector; no se asume que esta cola ya exista en el cliente actual.

1. **Ubicación Aislada:** Resuelta mediante `V2_LOCAL_DATA_SUBFOLDER` (Ruta real del piloto aislado: `%AppData%\AdmonGasLector_Preprod_Dev2\`).
2. **Estructura de la Cola de Contingencia Propuesta (`contingency_queue.json`):**
   - Reside en `%AppData%\AdmonGasLector_Preprod_Dev2\contingency_queue.json` junto a la cola ordinaria `v2_punch_queue.json`, sin trasladar ni modificar esta última.
   - Se utiliza exclusivamente ante estado `SCHEDULE_UNKNOWN` (falla de comunicación + caché expirada o deriva de reloj > 300 s).
   - Estructura atómica con flush a disco para garantizar durabilidad ante apagones.
   - **No asigna `device_sequence` ordinaria.**
   - La UI Windows muestra: `"HORARIO NO VERIFICABLE. REGISTRO PROVISIONAL ALMACENADO"`.
   - **No se emite sonido de confirmación ordinaria.**
   - Al reconectar, se sincroniza a una ruta de conciliación en el servidor sin eludir el control de entrada anticipada y sin generar huecos ni duplicados.

---

## 5. MATRIZ DE CASOS DE PRUEBA Y CRITERIOS DE ACEPTACIÓN

| Código | Escenario | Contexto de Entrada | Respuesta API | UI Windows C# | Destino Físico |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **TC-01** | Entrada 11 min antes | Turno 14:00, hora 13:49:00 | 200 OK, `REJECT_EARLY_ENTRY` | Tarjeta Roja: "ENTRADA ANTICIPADA NO PERMITIDA. Permitida: 13:50" | Envía `access-audits`. Cero filas en `marcaciones_crudas`. No consume secuencia L2. |
| **TC-02** | Entrada 10 min antes (Límite) | Turno 14:00, hora 13:50:00 | 200 OK, `ALLOW_ENTRY` | Tarjeta Verde: "ENTRADA REGISTRADA. 13:50:00" | Encola en `v2_punch_queue.json`. Incrementa `device_sequence`. Ingesta 201 ACCEPTED. |
| **TC-03** | Entrada 9 min antes | Turno 14:00, hora 13:51:00 | 200 OK, `ALLOW_ENTRY` | Tarjeta Verde: "ENTRADA REGISTRADA. 13:51:00" | Encola en `v2_punch_queue.json`. Ingesta 201 ACCEPTED. |
| **TC-04** | Entrada con retardo | Turno 14:00, hora 14:15:00 | 200 OK, `ALLOW_ENTRY` | Tarjeta Verde: "ENTRADA REGISTRADA. 14:15:00" | Ingesta 201 ACCEPTED. Retardo evaluado en servidor. |
| **TC-05** | Sin turno asignado (`UNSCHEDULED`) | Identidad válida, sin horario | 200 OK, `ALLOW_UNSCHEDULED` | Tarjeta Ámbar: "MARCACIÓN REGISTRADA. SIN HORARIO ASIGNADO" | Encola con secuencia L2. Ingesta 201 (`RECEIVED`, metadata: `UNSCHEDULED`). |
| **TC-06** | Marcación en Día de Descanso (`DAY_OFF`) | Descanso programado confirmado | 200 OK, `ALLOW_DAY_OFF` | Tarjeta Ámbar: "MARCACIÓN REGISTRADA. DÍA DE DESCANSO PROGRAMADO" | Encola en `v2_punch_queue.json`. Ingesta 201 (`RECEIVED`, metadata: `DAY_OFF`). P2: `DAY_OFF_RECORDED`. |
| **TC-07** | Contingencia (`SCHEDULE_UNKNOWN`) | Red caída y caché > 24 h | Contingencia Fail-Closed | Tarjeta Ámbar: "REGISTRO PROVISIONAL. HORARIO NO VERIFICABLE" | Guarda en `contingency_queue.json`. No asigna secuencia ordinaria. |
| **TC-08** | Salida legítima detectada | Turno nocturno previo, hora 06:05 | 200 OK, `ALLOW_EXIT` | Tarjeta Azul: "SALIDA REGISTRADA. 06:05:00" | Ingesta 201 ACCEPTED. No bloquea por 10 min. |
| **TC-09** | Intento de evasión como salida | Hora 13:45 (Sin entrada previa) | 200 OK, `REJECT_EARLY_ENTRY` | Tarjeta Roja: "ENTRADA ANTICIPADA NO PERMITIDA" | Servidor detecta ausencia de entrada; rechaza falsa salida. |
| **TC-10** | Doble captura (< 30 s) | Mismo trabajador en < 30 s | Descarte por Debounce (30 s) | Tarjeta Gris: "Marcación ya registrada recientemente" | Descarte local pre-enqueue. No duplica eventos ni secuencias L2. |
| **TC-11** | Marcación previa en cola offline | Entrada marcada offline hace 4 h | 200 OK, `ALLOW_EXIT` | Tarjeta Azul: "SALIDA REGISTRADA" | Reconcilia metadata enviada por cliente; autoriza salida. |
| **TC-12** | Reloj Windows desfasado (>5 min) | Deriva de reloj = 400 s | Bloqueo Fail-Closed | Tarjeta Roja: "Reloj desfasado. Notifique a supervisor" | Registro provisional; no confirma asistencia ordinaria. |
| **TC-13** | Turno nocturno medianoche | Turno 00:00, hora 23:49:59 | 200 OK, `REJECT_EARLY_ENTRY` | Tarjeta Roja: "Podrás registrar: 23:50:00" | Evaluación ISO entre días contiguos. |
| **TC-14** | Dirección ambigua | Conflicto multi-terminal | 200 OK, `AMBIGUOUS_DIRECTION` | Tarjeta Ámbar: "VERIFICACIÓN REQUERIDA. Consulte a supervisor" | Registra auditoría sin crear marcación ficticia. |
| **TC-15** | Regularización en Portal3 | Evento descanso/sin turno | Regularización administrativa | Vista de excepciones en Portal3 | Evalúa jornada histórica sin alterar hecho crudo. |

---

## 6. GOBERNANZA, CONDICIONES PREVIAS Y REGLAS DE INTEGRIDAD

1. **Estado:** `APPROVED_FROZEN_V1.4` (Especificación técnica congelada aprobada el 2026-10-20 por el Project Owner).
2. **Condiciones Previas a la Activación Operativa:**
   - A. Autorización independiente para crear físicamente la tabla de auditoría (`op_rh_v2_marcaciones_intentos_auditoria`).
   - B. Definición del SLA y procedimiento administrativo de regularización en Portal3.
   - C. Política administrativa de tratamiento de direcciones ambiguas irresolubles entre múltiples terminales.
   *Nota:* Estas condiciones no invalidan la aprobación formal del contrato técnico V1.4, pero impiden declarar lista la activación operativa en producción hasta su cumplimiento.
3. **Freeze Inalterado:** No modifica contratos existentes ni esquemas de base de datos congelados.
4. **Cero Plantillas Biométricas:** Prohibida la inclusión de datos biométricos en cualquier payload.
5. **Preservación de Evidencia:** Se garantiza que ningún trabajador sea rechazado por descansar (`DAY_OFF`) o por fallas de asignación (`UNSCHEDULED`), canalizando los casos a regularización controlada sin generar horas extras automáticas.
6. **Gobernanza Compartida:** Cualquier modificación futura de V1.4 requerirá una propuesta formal de cambio y la autorización expresa de ambos proyectos (`portal-api-v.1.0.1` y `AdmonGasLector`).
