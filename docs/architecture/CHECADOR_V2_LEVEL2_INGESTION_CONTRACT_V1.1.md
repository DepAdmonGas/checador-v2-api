# CHECADOR V2 LEVEL 2 INGESTION COMPLETENESS CONTRACT

**DOCUMENT_VERSION:** 1.1  
**STATUS:** FROZEN  
**EFFECTIVE_DATE:** 2026-10-05  
**CANONICAL_SHARED_DOCUMENT:** YES  
**LEVEL2_ARCHITECTURE_STATUS:** FROZEN  
**SOURCE_CANDIDATE:** CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_CANDIDATE_V1.1.md  
**SOURCE_CANDIDATE_SHA256:** c58dc26f7ed8f952070bfda38e9b406ec16e6a8a94c485ae09950fb855b6c915  
**READY_FOR_LEVEL2_IMPLEMENTATION:** NO  
**IMPLEMENTATION_AUTHORIZED:** NO  

---

## 1. GOBERNANZA Y ROL DEL CONTRATO CONGELADO V1.1

Este documento constituye la especificación técnica oficial y congelada para el contrato compartido de **Completitud de Ingesta Nivel 2 (Level 2 Ingestion Completeness Contract)** entre:

- **Checador API** (`portal-api-v.1.0.1`)
- **Checador Lector** (`lector-biometrico`)

### Reglas de Gobernanza:
1. **Documento Canónico Compartido:** Las copias de este documento en las carpetas de arquitectura del proyecto API (`docs/architecture/CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md`) y del Lector deben permanecer estrictamente **BYTE-IDENTICAL** (verificables por hash SHA256).
2. **Preservación del Historial:** Los documentos candidatos V1.0 (`CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_CANDIDATE_V1.0.md`) y V1.1 (`CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_CANDIDATE_V1.1.md`) permanecen guardados e intactos como registro de trazabilidad y procedencia del diseño.
3. **Prohibición de Edición In-Situ:** Este documento tiene el estado **`FROZEN`**. Ningún proyecto puede modificar de forma unilateral sus contenidos, estructuras, definiciones de marcas de agua o políticas. Cualquier cambio futuro requerirá una propuesta formal de cambio (RFC), consenso explícito entre equipos y la emisión de una versión incremental posterior (ej. `V1.2` o `V2.0`).
4. **Delimitación de Alcance de Implementación:** El congelamiento de la arquitectura de Nivel 2 en este documento **NO AUTORIZA LA IMPLEMENTACIÓN INMEDIATA DE CÓDIGO FUENTE**, modificaciones de esquemas de base de datos, alteración del formato de colas existentes ni cambios en la configuración de producción. La implementación requiere una autorización explícita e independiente.
5. **Compatibilidad con Architecture Freeze V1.0:** Este contrato respeta íntegramente el documento `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`, el cual se mantiene intacto.

---

## 2. ALCANCE Y SUB-GARANTÍAS DE NIVEL 2

El Nivel 2 rige la relación y transferencia probatoria **DISPOSITIVO → API (DEVICE → API INGESTION COMPLETENESS)**.

La completitud de ingesta Nivel 2 NO se define mediante una única variable booleana primitiva, sino como la satisfacción conjunta de tres sub-garantías independientes:

```text
                                  ┌─────────────────────────────────────────┐
                                  │   LEVEL 2 INGESTION COMPLETENESS        │
                                  └────────────────────┬────────────────────┘
                                                       │
         ┌─────────────────────────────────────────────┼─────────────────────────────────────────────┐
         ▼                                             ▼                                             ▼
┌─────────────────────────┐               ┌─────────────────────────┐               ┌─────────────────────────┐
│ LOCAL_CAPTURE_DURABILITY│               │  DELIVERY_COMPLETENESS  │               │   OPERATIONAL_COVERAGE  │
│ (Persistencia Origen)   │               │  (Entrega Contigua API) │               │ (Salud y Cobertura App) │
└─────────────────────────┘               └─────────────────────────┘               └─────────────────────────┘
```

1. **`LOCAL_CAPTURE_DURABILITY` (Durabilidad de Captura Local):** Garantiza que todo evento biométrico o de contingencia que haya alcanzado el límite de compromiso durable en el dispositivo queda registrado atómicamente en almacenamiento no volátil antes de cualquier transmisión de red.
2. **`DELIVERY_COMPLETENESS` (Completitud de Entrega):** Garantiza que cada marcación durablemente comprometida en el origen hasta una frontera de secuencia determinada ha sido recibida y aceptada por el API sin lagunas de secuencia ni marcaciones rechazadas sin resolver.
3. **`OPERATIONAL_COVERAGE` (Cobertura Operacional):** Garantiza que el subsistema de captura del dispositivo (Hardware, SDK, Proceso y Cola) estuvo operativo y en estado saludable durante el intervalo temporal gobernado.

---

## 3. ESTADOS DE EJECUCIÓN Y DELIMITACIÓN FÍSICA

### 3.1 Estados de Resultado Nivel 2 (Runtime Result States):
En tiempo de ejecución o consulta, el resultado del Nivel 2 para una ventana o estación se evalúa exclusivamente en uno de tres estados:

- **`VERIFIED`:** Las tres sub-garantías (Durabilidad, Entrega y Cobertura) y la atribución temporal se cumplen satisfactoriamente para la frontera requerida.
- **`INCOMPLETE`:** Existe una falla o brecha comprobada (laguna de secuencia, marcación no aceptada, evento rechazado, brecha en telemetría de salud o falla de hardware).
- **`UNKNOWN`:** Evidencia insuficiente para emitir un juicio (dispositivo no registrado, historial de asignación ambiguo, telemetría faltante, calidad de reloj no confiable o historia pre-cutover).

*Nota:* El término `CONDITIONAL` NO es un estado de resultado runtime. Se utiliza únicamente como categoría de análisis explicativo (ej. reemplazo de hardware o reasignación de estación).

### 3.2 Límite Físico del Software (Physical Interaction Limit):
```text
PHYSICAL_INTERACTION_PROOF_SUPPORTED: NO
```
El Nivel 2 **NUNCA prueba que un humano no haya tocado el sensor físico** ni que no haya existido un intento biológico. El dominio gobernado por el software inicia strictly en la evidencia digital generada por el subsistema de captura.

### 3.3 Etapas del Evento (Event Lifecycle Stages):
Se distinguen semánticamente cuatro etapas no equivalentes:
1. `PHYSICAL_INTERACTION`: Dedo tocando físicamente el cristal del sensor dactilar.
2. `RECOGNIZED_IN_MEMORY`: Coincidencia exitosa en memoria RAM (`zkfp2.DBMatch`).
3. `DURABLY_CAPTURED_EVENT`: Evento grabado atómicamente en almacenamiento no volátil (`SaveEventsUnsafe` flushed en disco).
4. `API_ACCEPTED_EVENT`: Marcación recibida y confirmada por el servidor API (HTTP 201 ACCEPTED o HTTP 200 `ALREADY_RECEIVED`).

---

## 4. CONTRATO DE CAPTURA ATÓMICA Y DURABILIDAD LOCAL

### 4.1 Dominio de Captura Durable:
Un `PunchEvent` ingresa formalmente al dominio gobernado por el Nivel 2 únicamente después de confirmarse su persistencia durable en el almacenamiento local del dispositivo.

### 4.2 Riesgo Residual Pre-Persistencia:
Existe un intervalo no nulo de riesgo residual entre la coincidencia en memoria RAM (`RECOGNIZED_IN_MEMORY`) y el compromiso en disco (`DURABLY_CAPTURED_EVENT`). Una interrupción del proceso, crash o pérdida de energía durante ese micro-intervalo puede perder la coincidencia de memoria sin dejar un registro durable.

### 4.3 Orden de Operación Futuro (Future Atomic Capture Order):
El flujo de captura debe ejecutar estrictamente el siguiente orden:

```text
[RECOGNICIÓN ACEPTADA EN RAM]
              │
              ▼
[GENERACIÓN DE event_uuid]
              │
              ▼
[ASIGNACIÓN ATÓMICA DE device_sequence]
              │
              ▼
[COMPROMISO DURABLE EN DISCO LOCAL (Event + Sequence + Generation)]
              │
              ▼
┌─────────────────────────────────────────────────────────┐
│ SÓLO TRAS ÉXITO DE PERSISTENCIA DURABLE EN DISCO LOCAL  │
└────────────────────────────┬────────────────────────────┘
                             │
         ┌───────────────────┴───────────────────┐
         ▼                                       ▼
[FLUJO LEGACY / UI]                     [DISPATCHER DE RED V2 HTTP]
```

Regla: **Ningún intento HTTP V2 debe ocurrir antes del compromiso durable en disco.**

---

## 5. GENERACIÓN DE DISPOSITIVO (DEVICE GENERATION / EPOCH)

### 5.1 Concepto e Identificador:
Se define el identificador obligatorio:
```text
device_generation (UUIDv4)
```
Ámbito del espacio de nombres: `(device_uuid, device_generation)`.

### 5.2 Propósito:
El `device_generation` distingue cambios estructurales en el almacenamiento local del Lector, tales como:
- Borrado o reseteo de la cola local (`v2_punch_queue.json`).
- Reinstalación o re-aprovisionamiento de la aplicación.
- Reemplazo del equipo o almacenamiento físico.
- Reseteo de contadores de secuencia.

No existe continuidad de secuencias entre distintas generaciones (`device_generation`).

### 5.3 Modelo de Propiedad Coordinada (Coordinated Ownership):
- **Lector:** Genera un nuevo `device_generation` (UUIDv4) al inicializar una cola durable desde cero.
- **API:** Registra, valida y conserva el historial autoritativo de generaciones para cada `device_uuid`.

### 5.4 Detección de Reseteo de Cola:
El borrado o pérdida de la cola local no puede continuar silenciosamente el `device_generation` anterior. La nueva inicialización genera un nuevo `device_generation`. Al recibir un nuevo `device_generation`, el API detecta la discontinuidad del almacenamiento local e invalida el resultado Nivel 2 para la frontera que cruce dicho reseteo.

---

## 6. SECUENCIA MONOTÓNICA DEL DISPOSITIVO (DEVICE SEQUENCE)

### 6.1 Propiedades de `device_sequence`:
1. **Ámbito:** Vinculada estrictamente al par `(device_uuid, device_generation)`.
2. **Monotonía:** Entero de 64 bits (`Int64`) estrictamente incremental (1, 2, 3...).
3. **Unicidad:** Única dentro de su `device_generation`.
4. **Inmutabilidad:** Inmutable una vez asignada y guardada.
5. **Atomicidad:** Se asigna y persiste atómicamente en el mismo commit durable que el `PunchEvent`.
6. **Persistencia:** Sobrevive a reinicios normales de la aplicación y del sistema operativo.
7. **No Reutilización:** Queda prohibida la reutilización de números de secuencia dentro de la misma generación.

### 6.2 Regla de Ausencia de Lagunas por Riesgo Pre-Persistencia:
```text
PRE_PERSISTENCE_LOSS_CREATES_SEQUENCE_GAP: NO
```
Debido a que la asignación de `device_sequence` y el guardado del evento ocurren en una única operación atómica de commit en disco, si el proceso se interrumpe antes del compromiso durable, **la secuencia N nunca fue asignada ni comprometida**. Por ende, no genera una laguna numerada de secuencia en la cola durable comprometida.

### 6.3 Semántica de Laguna de Secuencia (Sequence Gap):
Una **laguna de secuencia (Gap)** observada por el API significa que existe evidencia de haber recibido o registrado una secuencia comprometida posterior (ej. N+2) sin que la secuencia anterior (N+1) haya sido aceptada o contabilizada para esa generación.

---

## 7. MARCAS DE AGUA Y REGLAS DE ENTREGA (WATERMARKS)

### 7.1 Marca de Agua de Captura Local (`captured_through_sequence`):
Definida localmente en el Lector como la secuencia durablemente comprometida más alta X dentro de una generación para la cual **todos los números de secuencia <= X tienen un registro durable local exacto contabilizado**.

Un registro contabilizado puede estar en estado: `PENDING`, `SENDING`, `RETRY`, `AUTH_CONFIGURATION_ERROR`, `REJECTED` o `SYNCED`.  
*Nota:* `captured_through_sequence` NO implica aceptación por parte del API.

### 7.2 Marca de Agua de Aceptación Autorítativa (`accepted_through_sequence`):
El **API es el único propietario y calculador autoritativo** de `accepted_through_sequence`.

Definición: La secuencia contigua más alta X para la cual **cada evento durable gobernado con secuencia <= X ha sido aceptado por el API**.

Aceptación válida significa exclusivamente:
- `HTTP 201 ACCEPTED`
- `HTTP 200 ALREADY_RECEIVED` (para el mismo `event_uuid` estable).

### 7.3 Bloqueo Estricto por Eventos Rechazados (`REJECTED`):
```text
REJECTED_BLOCKS_ACCEPTED_THROUGH: YES
```
Un evento en estado `HTTP 400 REJECTED` **NO cuenta como aceptado por el API**. Un evento rechazado **bloquea en forma absoluta** el avance de `accepted_through_sequence` antes de su número de secuencia. Ninguna resolución administrativa o etiqueta de negocio puede transformar un evento rechazado en un evento aceptado por el API a efectos del watermark de ingesta.

### 7.4 Estados No Aceptados (All Unaccepted States):
Computan como no aceptados contra la entrega de Nivel 2:
- `PENDING`
- `SENDING`
- `RETRY`
- `AUTH_CONFIGURATION_ERROR`
- `REJECTED`

El estado de retardo o backoff de red NO equivale a sincronización.

### 7.5 Regla de Aceptación Contigua (Contiguous ACK Rule):
La marca `accepted_through_sequence` **NUNCA es simplemente MAX(secuencia recibida)**.

*Ejemplo:*  
Si la secuencia 98 está en estado `PENDING`/`RETRY`, pero las secuencias 99 y 100 fueron recibidas y aceptadas por el API:
```text
accepted_through_sequence <= 97
```
permanecerá congelada en 97 hasta que la secuencia 98 sea efectivamente aceptada por el API.

### 7.6 Semántica de Cola Vacía vs Completitud:
Se distingue conceptualmente:
- `READY_TO_PROCESS_COUNT`: Eventos listos para retransmisión inmediata.
- `ALL_UNACCEPTED_EVENT_COUNT`: Todos los eventos no confirmados por el API (incluyendo retries futuros y rechazados).

El Nivel 2 requiere `ALL_UNACCEPTED_EVENT_COUNT = 0` dentro de la frontera evaluada.

---

## 8. TELEMETRÍA OPERACIONAL, COBERTURA Y POLÍTICA INMUTABLE

### 8.1 Propósito del Heartbeat:
El Heartbeat es una muestra de telemetría discreta y periódica enviada por el Lector al API (`AUTHENTICATED_DEVICE_TELEMETRY`). **NO prueba la salud continua en cada instante físico**, sino que provee observaciones de salud muestreadas.

### 8.2 Modelo de Política de Cobertura Versionada e Inmutable (`coverage_policy_ref`):
Todas las reglas que rigen la evaluación de cobertura operacional, dimensiones de salud, atribución temporal y calidad de reloj se agrupan en un paquete de política inmutable con identificador explícito:
```text
coverage_policy_ref (ej. POL-COV-2026-V1)
```

Propiedades de `coverage_policy_ref`:
1. **Inmutabilidad:** Una vez utilizada para una evaluación histórica de Nivel 2, la versión de política es inmutable y no se edita in-situ. Cualquier ajuste genera una nueva versión/referencia.
2. **Historial de Vigencia:** Cada versión posee un periodo de vigencia explícito (`effective_from_utc`, `effective_to_utc`). Un cambio posterior de política NUNCA reinterpreta ni sobreescribe retrospectivamente los resultados calculados bajo políticas anteriores.
3. **Parámetros Gobernados:** Incluye la cadencia de latidos (`CONFIGURABLE_HEARTBEAT_CADENCE`), el umbral máximo de brecha tolerada (`CONFIGURABLE_MAX_COVERAGE_GAP`), reglas de inicio/apagado, ventana de gracia y dimensiones de salud requeridas.

### 8.3 Autoridad de Tiempo de Telemetría:
El **timestamp de recepción en el servidor API es la evidencia autoritativa** de la llegada de la telemetría. El timestamp reportado por el cliente se conserva únicamente con fines de diagnóstico.

### 8.4 Dimensiones de Salud del Subsistema (`CAPTURE_HEALTHY`):
El estado `CAPTURE_HEALTHY = true` se deriva exclusivamente de la satisfacción conjunta de las siguientes dimensiones:
1. `application_running`: Proceso de la aplicación activo.
2. `sdk_initialized`: SDK biométrico inicializado correctamente.
3. `sensor_connected`: Hardware del lector biométrico conectado y respondiendo.
4. `capture_thread_alive`: Hilo de captura/escucha activo.
5. `queue_writable`: Almacenamiento local de la cola accesible para escritura.

Si cualquiera de estas dimensiones falla (ej. lector desconectado o hilo de captura muerto), `CAPTURE_HEALTHY = false`.

### 8.5 Evaluación de Cobertura Operacional (`OPERATIONAL_COVERAGE`):
Para un intervalo temporal gobernado `[T1, T2]`:
- `OPERATIONAL_COVERAGE = VERIFIED`: Únicamente si las observaciones de telemetría validadas cubren el intervalo conforme a la versión de política activa (`coverage_policy_ref`).
- `OPERATIONAL_COVERAGE = INCOMPLETE`: Si existe una falla conocida (sensor desconectado, hilo muerto, aplicación cerrada) o una brecha no explicada en los latidos.
- `OPERATIONAL_COVERAGE = UNKNOWN`: Si falta evidencia de telemetría para el intervalo.

Queda strictly prohibido interpretar una brecha de cobertura como "no hubo marcación física".

### 8.6 Telemetría Fuera de Línea (Offline Telemetry & Continuity):
Si la red o el API están inaccesibles mientras el Lector opera correctamente:
- `DURABLE_TELEMETRY_BUFFER_REQUIRED: CONDITIONAL` (Requerido si el sistema pretende demostrar cobertura operacional durante desconexiones).
- La telemetría local debe almacenarse de forma durable y transmitirse al reconectar, incluyendo un identificador monotónico de secuencia de telemetría vinculado a la generación (`device_generation`) para detectar lotes perdidos, reordenados o duplicados.
- Sin telemetría durable fuera de línea o evidencia equivalente, la cobertura de un intervalo offline se evalúa como `UNKNOWN`.

---

## 9. CONJUNTO DE DISPOSITIVOS RELEVANTES Y ASIGNACIÓN HISTÓRICA

### 9.1 Asignación Histórica en Servidor:
El API mantiene la fuente de verdad inmutable del historial de asignación de dispositivos a centros de trabajo/estaciones:
```text
(device_uuid, device_generation, id_estacion, active_from_utc, active_to_utc)
```

### 9.2 Conjunto de Dispositivos Relevantes (`RELEVANT_DEVICE_GENERATIONS`):
Para verificar una estación $W$ en un intervalo $[T1, T2]$, el API resuelve el conjunto de todas las generaciones de dispositivos asignadas activamente a $W$ durante dicho intervalo.

Si no se puede determinar el conjunto de dispositivos relevantes:
```text
LEVEL_2 = UNKNOWN
```

### 9.3 Múltiples Dispositivos por Estación:
Si una estación cuenta con múltiples dispositivos activos, **cada generación de dispositivo asignada debe cumplir individualmente** sus requisitos de Entrega y Cobertura para su intervalo de responsabilidad. No se exige que cada dispositivo haya registrado marcaciones, pero sí que todos hayan reportado cobertura operacional saludable.

### 9.4 Reemplazo de Dispositivos y Reasignación de Estación:
El reemplazo de hardware o la reasignación de estación no constituyen estados runtime de Nivel 2. Se evalúan bajo las siguientes reglas:
- **Reemplazo:** `VERIFIED` únicamente si la asignación de la generación anterior $G_1$ se cierra formalmente, la asignación de $G_2$ se abre, no existe una brecha de traspaso no autorizada y todos los eventos durables de $G_1$ fueron contabilizados.
- **Reasignación:** `VERIFIED` únicamente si el historial de asignaciones no presenta solapamientos ni brechas ambiguas. De lo contrario, resulta en `INCOMPLETE` o `UNKNOWN`.

---

## 10. CONFIANZA DE RELOJ, ATRIBUCIÓN TEMPORAL Y NIVEL 2 POR VENTANA (`WINDOW_LEVEL2`)

### 10.1 Inmutabilidad del Timestamp de Origen (`source_timestamp`):
El campo `source_timestamp` emitido por el reloj local del cliente Windows es un dato de entrada fáctico e inmutable. **Queda estrictamente prohibido sobreescribir o alterar `source_timestamp`** para "corregir" desvíos de reloj o interpretaciones de negocio.

### 10.2 Separación con Timezone Fallback:
La bandera `TIMEZONE_FALLBACK_APPLIED` es exclusivamente un hecho de resolución de zona horaria. **NO constituye evidencia de desvío de reloj, sincronización de tiempo ni estatus de calidad del reloj**.

### 10.3 Calidad de Tiempo de Origen (`SOURCE_TIME_QUALITY`):
Dimensión independiente que evalúa la confiabilidad del reloj del dispositivo en una escala conceptual: `TRUSTED`, `DEGRADED`, `UNKNOWN`.
- Su evaluación se rige por la política inmutable `coverage_policy_ref`.
- **Independencia con Entrega:** `DELIVERY_COMPLETENESS` puede ser `VERIFIED` aun cuando `SOURCE_TIME_QUALITY` sea `DEGRADED` o `UNKNOWN`, ya que la ingesta contigua por secuencias no depende del reloj de pared.

### 10.4 Atribución a la Ventana Temporal (`TIME_WINDOW_ATTRIBUTION`):
Representa el grado de confianza para asociar los `source_timestamp` de las marcaciones con el intervalo de negocio `[T1, T2]`. Estados: `VERIFIED`, `DEGRADED`, `UNKNOWN`.

### 10.5 Criterio de Verificación por Ventana (`WINDOW_LEVEL2`):
Para consultas de Journey o ventanas temporales, `WINDOW_LEVEL2 = VERIFIED` **SSI y sólo si**:
```text
(RELEVANT_DEVICE_SET == KNOWN)
AND (DELIVERY_COMPLETENESS == VERIFIED)
AND (OPERATIONAL_COVERAGE == VERIFIED)
AND (TIME_WINDOW_ATTRIBUTION == VERIFIED)
AND (UNRESOLVED_RESETS == 0)
```
Si `SOURCE_TIME_QUALITY` o `TIME_WINDOW_ATTRIBUTION` resultan en `UNKNOWN` o `DEGRADED`, `WINDOW_LEVEL2` se clasifica como `UNKNOWN` o `INCOMPLETE` (nunca `VERIFIED`).

### 10.6 Limitaciones Técnicas del Tiempo de Servidor:
- `server_received_at != occurred_at`: La hora de recepción en el servidor no sustituye el timestamp de captura en el dispositivo. Un envío diferido por trabajo offline hace que el evento llegue mucho tiempo después de su ocurrencia.
- La diferencia entre el timestamp del cliente y del servidor puede deberse a desvío, latencia o buffering offline; por tanto, una sola diferencia no prueba un desvío exacto de reloj.

---

## 11. REGISTRO DE AUDITORÍA HISTÓRICA Y CONSERVACIÓN DE EVIDENCIA

### 11.1 Registro de Evaluación Histórica Durable:
Para garantizar la reproducibilidad de auditorías, la evaluación histórica de Nivel 2 debe conservar un registro durable con la siguiente estructura conceptual:
- `coverage_policy_ref` (Identificador inmutable de la política utilizada).
- Intervalo fuente `[T1, T2]`.
- Generaciones de dispositivos evaluadas (`RELEVANT_DEVICE_GENERATIONS`).
- `evaluated_at_utc` (Timestamp de la evaluación).
- Resultado global (`VERIFIED`, `INCOMPLETE`, `UNKNOWN`).
- Referencias de evidencia de soporte (`delivery_result`, `coverage_result`, `time_attribution_result`).

### 11.2 Retención de Evidencia Operacional (`POLICY_CONTROLLED_DURABLE_TELEMETRY_RETENTION`):
Las evaluaciones históricas retenidas deben contar con el respaldo de la evidencia operacional fáctica (Heartbeats y logs de ingesta).

### 11.3 Clarificación Fundamental: El Hash NO Reemplaza la Evidencia:
Un digest o hash criptográfico demuestra la **integridad de la evidencia**, pero **NO reconstruye la evidencia faltante por sí solo**. Por ende, la reproducibilidad histórica exige:
- **Opción A:** Retención de la telemetría y evidencias operacionales históricas exactas; o
- **Opción B:** Retención de un manifiesto canónico de evidencia que contenga las observaciones exactas necesarias.

Un resultado de evaluación Nivel 2 retenido NO debe sobrevivir a la destrucción de la evidencia requerida para reproducirlo si se pretende mantener el estatus de auditabilidad plena.

---

## 12. DECLARACIÓN SEGURA Y DELIMITACIÓN CON NIVEL 1 Y NIVEL 3

### 12.1 Declaración Segura de Ausencia de Marcación (Safe Level 2 Statement):
La declaración probatoria autorizada para la ingesta de Nivel 2 es exclusivamente:
> *"No existe ningún PunchEvent gobernado y capturado dentro del contrato de origen verificado de Nivel 2 para la ventana especificada."*

Esta declaración es válida **únicamente cuando `WINDOW_LEVEL2 = VERIFIED`**. De lo contrario, se reporta que el estatus de completitud es `UNKNOWN` o `INCOMPLETE` debido a las deficiencias de evidencia correspondientes. Queda estrictamente prohibido declarar: *"Ninguna interacción física ocurrió en el sensor."*

### 12.2 Delimitación entre Niveles 1, 2 y 3:
- **Level 1 (API Snapshot):** Garantiza completitud matemática del snapshot en la base de datos del servidor.
- **Level 2 (Device Ingestion):** Garantiza entrega de borde a servidor, cobertura operacional y atribución temporal.
- **Level 3 (Portal3 Domain):** Interpreta jornadas, horarios, permisos, incapacidades y determina la presencia administrativa de **SIN MARCACIÓN** o **FALTA**.

Level 1 + Level 2 por sí solos **NO equivalen a FALTA**.

---

## 13. CAPACIDADES REQUERIDAS (CAPABILITIES, NOT ENDPOINTS)

Se definen las siguientes capacidades abstractas necesarias para soportar el contrato Nivel 2 (sin congelar URLs ni rutas HTTP finales):

1. `DEVICE_GENERATION_REGISTRATION`: Capacidad de registrar y validar el inicio de una generación de dispositivo.
2. `SEQUENCE_AWARE_PUNCH_INGESTION`: Capacidad de procesar marcaciones crudas validando `device_sequence` y `device_generation`.
3. `OPERATIONAL_TELEMETRY_INGESTION`: Capacidad de recibir y registrar latidos de telemetría de salud operacional usando el tiempo de recepción del servidor.
4. `COMPLETENESS_STATUS_QUERY`: Capacidad de evaluar y retornar el estado de completitud Nivel 2 (`VERIFIED`, `INCOMPLETE`, `UNKNOWN`) para una ventana y estación.
5. `HISTORICAL_DEVICE_ASSIGNMENT`: Capacidad de gestionar el registro inmutable de asignación de dispositivos a estaciones.

---

## 14. MATRIZ NORMATIVA DE FALLAS CONGELADA (FROZEN NORMATIVE FAILURE MATRIX V1.1)

| Escenario de Falla | Capture Durability | Delivery Completeness | Operational Coverage | Window Level 2 Result |
| :--- | :--- | :--- | :--- | :--- |
| **Red Offline (Con telemetría durable)** | VERIFIED | INCOMPLETE (Pending ACK) | VERIFIED (Tras reconexión) | INCOMPLETE (Hasta sincronizar) |
| **Red Offline (Sin telemetría durable)** | VERIFIED | INCOMPLETE (Pending ACK) | UNKNOWN | UNKNOWN |
| **API 500 Server Error** | VERIFIED | INCOMPLETE | VERIFIED | INCOMPLETE |
| **Lost ACK (Respuesta perdida)** | VERIFIED | VERIFIED (tras 200 ALREADY_RECEIVED) | VERIFIED | VERIFIED |
| **HTTP 401 Auth Failure** | VERIFIED | INCOMPLETE | VERIFIED | INCOMPLETE |
| **HTTP 400 Payload Rejected** | VERIFIED | INCOMPLETE | VERIFIED | INCOMPLETE |
| **Calidad de Reloj Desconocida / Desviada** | VERIFIED | VERIFIED | VERIFIED | UNKNOWN / INCOMPLETE |
| **Crash ANTES de Persistencia** | NOT_APPLICABLE | NOT_APPLICABLE | VERIFIED (durante ejecución) | INCOMPLETE |
| **Crash DESPUÉS de Persistencia** | VERIFIED | INCOMPLETE (hasta sincronizar) | INCOMPLETE (ventana de caída) | INCOMPLETE |
| **Pérdida Energía DESPUÉS de Persistencia** | VERIFIED | INCOMPLETE (hasta sincronizar) | INCOMPLETE (ventana de caída) | INCOMPLETE |
| **Corrupción de Cola (Recuperable .bak)** | VERIFIED | INCOMPLETE | VERIFIED | INCOMPLETE |
| **Corrupción de Cola (Irrecuperable)** | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN |
| **Reseteo de Cola / Borrado AppData** | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN |
| **Sensor Biométrico Desconectado** | NOT_APPLICABLE | NOT_APPLICABLE | INCOMPLETE (`CAPTURE_HEALTHY=false`) | INCOMPLETE |
| **Hilo de Captura Muerto** | NOT_APPLICABLE | NOT_APPLICABLE | INCOMPLETE (`CAPTURE_HEALTHY=false`) | INCOMPLETE |
| **Aplicación Cerrada / Apagada** | NOT_APPLICABLE | NOT_APPLICABLE | INCOMPLETE (Sin Heartbeat) | INCOMPLETE / UNKNOWN |
| **Reemplazo de Dispositivo (Conforme)** | VERIFIED | VERIFIED | VERIFIED | VERIFIED |
| **Reasignación de Estación (Conforme)** | VERIFIED | VERIFIED | VERIFIED | VERIFIED |

---

## 15. SEGURIDAD Y MODELO DE CONFIANZA

1. **Autenticación del Dispositivo:** La telemetría y marcaciones de Nivel 2 deben transmitirse utilizando la autenticación del dispositivo existente (`X-Device-Id`, `X-Credential-Key`, `Authorization: Bearer <secret>` protegido por Windows DPAPI sobre TLS).
2. **Prohibición de Secretos Plaintext:** Queda estrictamente prohibido incluir claves secretas, tokens en texto plano o datos biométricos en los documentos de contrato, logs de telemetría o payloads JSON.
3. **Nivel de Confianza de Telemetría:** La telemetría se clasifica como `AUTHENTICATED_DEVICE_TELEMETRY`. No constituye una atestación criptográfica de hardware inalterable en el cliente (Hardware Tamper-Proof Attestation).

---

## 16. POLÍTICA DE CUTOVER Y LEGACY

1. **Marcaciones Históricas / Pre-Cutover:** Las marcaciones registradas antes de la entrada en vigor del Nivel 2 (con `device_sequence = null` y sin `device_generation`) se clasifican como `LEGACY_UNSEQUENCED_GENERATION`. Su estado Nivel 2 es `LEVEL_2_COMPLETENESS_UNKNOWN`. Queda prohibida la fabricación retroactiva de números de secuencia.
2. **Fecha de Corte (Cutover Timestamp $T_{cut}$):** La evaluación del Nivel 2 inicia estrictamente a partir de un timestamp de corte formal $T_{cut}$ y la primera generación de dispositivo registrada.

---

## 17. REQUISITOS DE PRUEBAS FUTURAS (FUTURE TEST CONTRACT V1.1)

Previo a autorizar cualquier implementación de código, la suite de pruebas del Lector y del API deberá incorporar verificaciones para:
1. Evaluación de cobertura histórica reproducible mediante la versión de política original V1.
2. Comprobación de que una nueva versión de política V2 no sobreescribe retrospectivamente resultados V1.
3. Supervivencia de la telemetría y evidencias históricas durante el periodo de retención auditada.
4. Verificación de que un digest de evidencia aislado NO sustituye la evidencia requerida ante datos destruidos.
5. Clasificación de `WINDOW_LEVEL2 = UNKNOWN` cuando `SOURCE_TIME_QUALITY` es `UNKNOWN`.
6. Evaluación del reloj del cliente desviado manteniendo `TIMEZONE_FALLBACK_APPLIED` sin manipular.
7. Comprobación de inmutabilidad estricta del `source_timestamp` original ante cambios de reloj de Windows.
8. Manejo de gran divergencia entre el timestamp del cliente y la hora de recepción del servidor.
9. Captura offline con buffering durable de telemetría y carga posterior exitosa.
10. Captura offline sin buffering durable de telemetría evaluando la cobertura como `UNKNOWN`.
11. Detección por secuencia de telemetría de lotes perdidos, reordenados o duplicados.
12. Asignación atómica de `device_sequence` y `PunchEvent` en el compromiso en disco.
13. Creación obligatoria de un nuevo `device_generation` ante el borrado de la cola durable.
14. Bloqueo estricto del watermark `accepted_through_sequence` ante eventos `HTTP 400 REJECTED`.
15. Emisión de `CAPTURE_HEALTHY = false` al desconectar el sensor biométrico o morir el hilo de captura.
16. Agregación correcta de múltiples dispositivos asignados a una misma estación.
17. Traspaso conforme de completitud durante el reemplazo de dispositivos.
18. Clasificación correcta de marcaciones legacy como `LEVEL_2_UNKNOWN`.

---

## 18. ESTADO DE IMPLEMENTACIÓN Y NO AUTORIZACIÓN

```text
CURRENT_LEVEL2_IMPLEMENTATION: NOT_READY
READY_FOR_LEVEL2_IMPLEMENTATION: NO
IMPLEMENTATION_AUTHORIZED: NO
DOCUMENT_STATUS: FROZEN
```
Este documento establece formalmente la especificación congelada de Nivel 2. **NO AUTORIZA LA MODIFICACIÓN DE CÓDIGO FUENTE, BASE DE DATOS, NINGUNA COLA LOCAL NI DESPLEGUES EN PRODUCCIÓN.**
