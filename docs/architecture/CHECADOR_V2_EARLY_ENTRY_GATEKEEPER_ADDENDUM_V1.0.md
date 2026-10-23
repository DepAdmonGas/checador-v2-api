# CHECADOR V2 — EARLY ENTRY GATEKEEPER ARCHITECTURE ADDENDUM V1.0

**Document Status:** APPROVED_FROZEN_V1.4 (Especificación arquitectónica aprobada y congelada)  
**Governance Scope:** Frozen Addendum to `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`  
**Revision:** V1.4  
**Approval Date:** 2026-10-20  
**Approval Authority:** Project Owner  
**Shared Scope:** `portal-api-v.1.0.1` (API) y `AdmonGasLector` (Lector Windows)  
**Approved Directional Decisions:**
- **Decisión A:** Detección automática de Entrada/Salida: el empleado únicamente coloca su huella en el sensor USB ZKTeco Live20R (sin botones F1/F2 ni selección manual). La API central y el motor de jornada conservan la autoridad para determinar la dirección; el Lector no calcula jornadas por cuenta propia ni envía clasificaciones de dirección unilaterales.
- **Decisión B:** Rechazo de entrada ordinaria restringido exclusivamente a cuando exista un turno confiable y el intento ocurra antes de `T_inicio - 10 minutos`.
- **Decisión C:** Aceptación obligatoria de marcaciones de trabajadores sin turno asignado (`UNSCHEDULED`), preservando la evidencia física en la cola durable e ingesta cruda.
- **Decisión D:** Diferenciación estricta entre `UNSCHEDULED` (ausencia confirmada de horario), `SCHEDULE_UNKNOWN` (contingencia de verificación no disponible) y `DAY_OFF` (descanso programado confirmado).
- **Decisión E:** Aprobación de diseño de la tabla aditiva independiente `op_rh_v2_marcaciones_intentos_auditoria`.
- **Decisión F:** Ventana de debounce de 30 segundos (configurable) para descarte de capturas duplicadas accidentales.
- **Decisión G:** Procedimiento formal de regularización administrativa en Portal3 para marcaciones sin horario, descansos trabajados, contingencias y ambigüedades.
- **Decisión I:** Aceptación obligatoria de marcaciones en Día de Descanso (`DAY_OFF`), preservando la evidencia física en la cola durable ordinaria e ingesta cruda sin generar asistencia ordinaria ni horas extras automáticas.

**Target Client Hardware:** PC Windows (Aplicación de Escritorio C#) + Lector Biométrico USB ZKTeco Live20R  
**Local Storage Configuration:** Resuelto mediante `V2_LOCAL_DATA_SUBFOLDER` y el resolvedor de almacenamiento de `DurablePunchQueue`.  
Ruta real del piloto aislado: `%AppData%\AdmonGasLector_Preprod_Dev2\v2_punch_queue.json`  
**Target Milestone:** V2.7+ (Pre-production Hardening & Gatekeeper Governance)  
**Database Modification:** NO  
**Runtime Implementation:** NO  

---

## 1. PRINCIPIOS DE AUTORIDAD Y ARQUITECTURA DISTRIBUIDA

### 1.1 Autoridad Exclusiva de la API y Desacoplamiento del Lector
1. **La API central es la única autoridad:** La determinación de turnos, horarios de inicio/fin, segmentos, asignaciones vigentes, zonas horarias y la **clasificación definitiva de Entrada vs. Salida** residen exclusivamente en el servidor (`op_rh_v2_turnos_plantillas`, `op_rh_v2_turnos_segmentos`, `op_rh_v2_turnos_asignaciones`, `op_rh_v2_localidades_zonas_horarias`, `JourneyPairingService`).
2. **Rol del Lector (Perímetro Físico de Captura):** El Lector Windows **no calcula jornadas, no evalúa pares P1 ni alterna estados de entrada/salida unilateralmente**. Su función perimetral es capturar la huella, suministrar contexto local neutral (estampa de tiempo, eventos locales en cola), consultar a la API la ventana/decisión autorizada y aplicar el bloqueo de entrada de los 10 minutos.
3. **Diferenciación entre Decisión Gatekeeper y Clasificación de Jornada:**
   - La respuesta del endpoint de ventana es una **decisión provisional perimetral** (*Gatekeeper Action*) para decidir si se autoriza la marcación física en ese instante.
   - Una consulta previa no garantiza por sí sola la ausencia total de carreras entre terminales. La **clasificación definitiva** (asistencia, retardo, tiempo trabajado, horas extras) la realiza posteriormente el motor de jornadas P1/P2 en el servidor de forma determinista y auditable.

### 1.2 Regla Perimetral de los 10 Minutos (Decisión B)
> **"Ningún empleado puede confirmar una entrada ordinaria antes de los 10 minutos previos al inicio de su turno programado."**
- Aplica **únicamente cuando exista un turno regular o mixto confiable** verificado por la API o por la caché local autorizada y vigente.
- Antes de `T_inicio - 10 minutos`: Entrada ordinaria **RECHAZADA**.
- Desde `T_inicio - 10 minutos`: Entrada **PERMITIDA** (gracia preparatoria).
- Posterior a `T_inicio`: Entrada **PERMITIDA** (retardo evaluado en P1/P2/P3-G).
- **Las SALIDAS legítimas no están sujetas a este bloqueo de entrada anticipada.**
- Se preserva estrictamente la fecha y hora real de captura física en hardware.

---

## 2. POLÍTICA DE DÍAS DE DESCANSO (`DAY_OFF` - DECISIÓN I APROBADA)

### 2.1 Principios Operativos y de Protección Laboral
Cuando un trabajador identificado biométricamente coloca su huella y la API central (o una caché local autorizada y vigente) confirma que tiene un día de descanso programado (`DAY_OFF`):

1. **Aceptación y Captura Obligatoria:** El Lector **permite capturar y guardar su marcación**. Queda estrictamente prohibido rechazar o bloquear el evento únicamente por tratarse de un día de descanso.
2. **Preservación de Evidencia Física:** Se preserva íntegramente la estampa de tiempo real de hardware (`source_timestamp_raw`), garantizando la protección de la evidencia de trabajo efectivamente realizado ante inspecciones o aclaraciones laborales.
3. **Mecanismo Ordinario de Enqueue y Secuencia Nivel 2:**
   - El evento se procesa mediante el flujo ordinario existente de `DurablePunchQueue`.
   - Se le asigna un `event_uuid` criptográfico y el consecutivo incremental `device_sequence`.
   - Se guarda atómicamente en la cola ordinaria `v2_punch_queue.json`.
   - Se transmite al servidor mediante el endpoint estándar `POST /api/v2/punch-events`.
4. **Garantía Anti-Huecos:** Al utilizar la cola ordinaria, las marcaciones en día de descanso **no generan huecos artificiales** en la secuencia Nivel 2 (`delivery_completeness = 'VERIFIED'`).
5. **Aislamiento Laboral en Motor P1/P2:**
   - La captura física **no crea automáticamente una jornada ordinaria**.
   - **No genera automáticamente asistencias ordinarias, retardos, faltas ni horas extras.**
   - En el motor de cálculo P2 ([JourneyCalculationService.php#L136](file:///home/silvinol/Proyectos/portal-api-v.1.0.1/modelo/v2/journey/JourneyCalculationService.php#L136)), el día queda clasificado deterministamente como `estado_calculo = 'DAY_OFF_RECORDED'`, con `segundos_programados_totales = 0` y `segundos_trabajados_totales = NULL`.
6. **Revisión y Regularización en Portal3:** El evento queda visible en la bandeja de excepciones de Portal3 para que la administración determine si correspondió a labor extraordinaria autorizada (generando la incidencia correspondiente) o a una marcación incidental.

### 2.2 Mensaje en la Interfaz Gráfica de Windows (C#)
Al registrarse una marcación en día de descanso, la aplicación de escritorio muestra:
- **Encabezado:** `MARCACIÓN REGISTRADA`
- **Detalle:** `DÍA DE DESCANSO PROGRAMADO. PENDIENTE DE REVISIÓN POR RH.`
- **Estilo Visual:** Tarjeta informativa en color Ámbar / Azul.
- **Retroalimentación Sonora:** Sonido de confirmación neutro/informativo del sistema.

---

## 3. TAXONOMÍA RIGUROSA DE ESTADOS OPERATIVOS (DECISIONES C, D E I)

Es mandatorio distinguir con precisión matemática los cuatro estados operativos de consulta:

| Estado | Definición Técnica | Validación Autoritativa | Comportamiento Lector Windows | Destino Físico | Impacto P1/P2 |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **`SCHEDULED`** | Trabajador con turno activo regular asignado. | API o caché vigente con plantilla activa. | Aplica regla 10 min. Entrada anticipada rechazada; ventana permitida aceptada. | `v2_punch_queue.json` -> `op_rh_v2_marcaciones_crudas`. | Pareo y cálculo ordinario contra segmentos. |
| **`UNSCHEDULED`** | Trabajador enrolado formalmente pero **sin turno asignado para la fecha**. | API o caché vigente confirma ausencia de plantilla. | **Acepta marcación**. UI: "Sin horario asignado. Pendiente de revisión". | `v2_punch_queue.json` -> `op_rh_v2_marcaciones_crudas`. | `UNSCHEDULED_RECORDED`, tiempo trabajado `NULL`. |
| **`DAY_OFF`** | Trabajador con **día de descanso programado confirmado**. | API o caché vigente confirma asignación de descanso. | **Acepta marcación**. UI: "Día de descanso programado. Pendiente de revisión". | `v2_punch_queue.json` -> `op_rh_v2_marcaciones_crudas`. | `DAY_OFF_RECORDED`, programado `0`, trabajado `NULL`. |
| **`SCHEDULE_UNKNOWN`** | **Falla técnica de verificación:** Red caída + caché expirada (>24h) o reloj con deriva (>300s). | Imposibilidad técnica de verificar autoridad. | **Fail-Closed:** Registro provisional; no confirma asistencia ordinaria. | `contingency_queue.json` (Estructura aislada propuesta). | No ingresa a P1/P2 sin conciliación previa. |

> [!WARNING]
> **Condiciones de Seguridad de Horarios:**
> - `DAY_OFF` no debe confundirse con `UNSCHEDULED` ni con `SCHEDULE_UNKNOWN`.
> - Si un supervisor realizó un cambio de turno o asignó una jornada extraordinaria en Portal3, la autoridad de horarios devuelve el turno activo aplicable; **no debe etiquetarse como descanso un día para el que existe un turno autorizado**.

---

## 4. INTEGRIDAD DE SECUENCIA NIVEL 2 Y AUDITORÍA INDEPENDIENTE

### 4.1 Invariante de Secuencia Nivel 2 (`device_sequence`)
1. **Punto de Asignación de Secuencia:** El Lector Windows incrementa y asigna `device_sequence` estrictamente en el momento de encolar una marcación en la cola durable ordinaria (`Enqueue`).
2. **Resolución Previa a Enqueue:**
   - Los intentos rechazados por ser anticipados (`REJECT_EARLY_ENTRY`) y las capturas duplicadas descartadas por debounce (<30 s) se resuelven **antes** de llamar a `Enqueue`.
   - **Garantía Anti-Huecos:** Al resolverse antes de la cola ordinaria, los intentos bloqueados **no consumen números de secuencia Nivel 2**. Esto previene la generación de huecos artificiales en `device_sequence`.
   - Las marcaciones aceptadas (`SCHEDULED` en ventana, `UNSCHEDULED` y `DAY_OFF`) utilizan la cola ordinaria y consumen secuencia Nivel 2 continua sin generar huecos.

### 4.2 Canal Independiente de Auditoría (Decisión E)
- Los intentos rechazados poseen su propia identidad criptográfica e idempotencia mediante un identificador `attempt_uuid`.
- Se transmiten y persisten de forma totalmente independiente a través de `POST /api/v2/device/access-audits` hacia la tabla aditiva propuesta `op_rh_v2_marcaciones_intentos_auditoria`.
- **Cero Datos Biométricos:** Queda estrictamente prohibido transmitir minucias, vectores o plantillas biométricas en el canal de auditoría. Solo se registran identificadores alfanuméricos, estampa temporal y motivo del rechazo.

---

## 5. CONTRATOS DE ESTADOS Y COMPATIBILIDAD CON CÓDIGO EXISTENTE

### 5.1 Verificación en Esquema Físico y Controladores
1. **`op_rh_v2_marcaciones_crudas.processing_status`:**
   - Tipo físico real: `VARCHAR(32) NOT NULL DEFAULT 'RECEIVED'`.
   - Tanto para `SCHEDULED`, como para `UNSCHEDULED` y `DAY_OFF`, la ingesta ordinaria utiliza estrictamente `'RECEIVED'`, preservando el contrato físico existente sin inventar nuevos valores en la base de datos.
2. **`op_rh_v2_marcaciones_crudas.validation_flags`:**
   - Tipo físico real: `TEXT NULL` (array JSON serializado).
   - Las banderas de servidor se reservan para validaciones técnicas (`INVALID_TIMEZONE_REPORTED`, `CLOCK_DRIFT_ALERT`, etc.). El cliente transmite el contexto de horario dentro de `metadata_json` (ej. `"metadata": {"schedule_status": "DAY_OFF"}` o `"metadata": {"schedule_status": "UNSCHEDULED"}`).
3. **`op_rh_v2_calculos_jornada.estado_calculo` (P2):**
   - Tanto `UNSCHEDULED_RECORDED` como `DAY_OFF_RECORDED` **están formalmente implementados y probados** en [JourneyCalculationService.php](file:///home/silvinol/Proyectos/portal-api-v.1.0.1/modelo/v2/journey/JourneyCalculationService.php) y en la migración `2026_10_07_000002_create_v2_journey_calculation_tables.sql`.

---

## 6. ALMACENAMIENTO LOCAL AISLADO Y CONTINGENCIA FUERA DE LÍNEA

### 6.1 Almacenamiento Local Aislado y Ruta Real del Piloto
- El almacenamiento local se resuelve mediante el parámetro de configuración `V2_LOCAL_DATA_SUBFOLDER` y el resolvedor interno de almacenamiento de `DurablePunchQueue`.
- **Ruta Real del Piloto Preproducción:** La cola ordinaria en el piloto aislado reside exactamente en:
  `%AppData%\AdmonGasLector_Preprod_Dev2\v2_punch_queue.json`
- **Invariante:** No se modifica la ruta, formato ni estructura de `DurablePunchQueue`.

### 6.2 Especificación de la Cola de Contingencia Propuesta (`contingency_queue.json`)
> [!NOTE]
> **Propuesta Técnica (No Preexistente):** La nueva cola provisional de contingencia es estrictamente una propuesta de diseño para desarrollo futuro en el Lector; no existe actualmente en el cliente.
- **Ruta de Nueva Estructura Propuesta:** `%AppData%\AdmonGasLector_Preprod_Dev2\contingency_queue.json` (ubicada en la misma carpeta del piloto aislado sin alterar `v2_punch_queue.json`).
- **Garantías de Persistencia:** Escritura atómica a disco con flush obligatorio para resistir cortes de energía en la PC.
- **Reconciliación y Prevención de Huecos:**
  - Los eventos en la cola de contingencia **no consumen `device_sequence` ordinaria**.
  - Al restablecerse la red, se envían a un endpoint de regularización técnica para que un supervisor o regla autorizada los reconcilie, evitando duplicaciones de hechos o huecos en la secuencia de auditoría Nivel 2.
- **Prevención de Bypass:** Los registros de contingencia **no** se promueven automáticamente a asistencia regular sin conciliación en Portal3, impidiendo que desconectar el cable de red sea usado como vía para eludir la regla de los 10 minutos.

---

## 7. REGULARIZACIÓN ADMINISTRATIVA EN PORTAL3 (DECISIÓN G)

El módulo de Checador V2 en Portal3 dispondrá de un flujo de regularización para eventos especiales:

1. **Bandeja de Excepciones:** Los supervisores con permiso `biometricos.editar` o `checador-v2-incidencias-resolucion` visualizan marcaciones en día de descanso (`DAY_OFF`), marcaciones sin turno (`UNSCHEDULED`), registros de contingencia sincronizados y eventos de dirección ambigua.
2. **Acciones de Regularización para Días de Descanso Laborados:**
   - *Incidencia de Descanso Trabajado:* Si el supervisor autorizó la labor extraordinaria, emite una incidencia administrativa formal (`DESCANSO_LABORADO` / `TIEMPO_EXTRAORDINARIO`).
   - *Asignación Extraordinaria:* Si se trató de un cambio de rol operativo, asocia la jornada a un turno aplicable retroactivo.
3. **Invariante de Hechos Crudos:** La regularización **nunca muta ni sobrescribe** la fila física en `op_rh_v2_marcaciones_crudas`; vincula el hecho original mediante procedencia e incidencias administrativas inmutables.
4. **Trazabilidad:** Se registra el identificador del supervisor, timestamp UTC y justificación obligatoria.

---

## 8. GOBERNANZA, CONDICIONES PREVIAS A LA ACTIVACIÓN Y REVISIÓN CRUZADA

### 8.1 Condiciones Previas a la Activación Operativa del Gatekeeper
Las siguientes condiciones técnicas y operativas deben satisfacerse antes de activar el Gatekeeper en producción:
1. **Autorización Independiente de Migración DDL:** Aprobación formal para ejecutar la migración DDL que crea físicamente la tabla `op_rh_v2_marcaciones_intentos_auditoria`.
2. **Definición de Procedimiento y SLA en Portal3:** Formalización del flujo de usuario y tiempo máximo de respuesta para que Recursos Humanos regularice marcaciones de descanso y sin horario antes del corte de prenómina.
3. **Política Administrativa ante Dirección Ambigua Irresoluble:** Mecanismo operativo formal cuando un empleado marca en terminales distintas sin secuencia lógica o con conflicto no resuelto.

> [!NOTE]
> Estas condiciones previas no invalidan la aprobación ni el congelamiento arquitectónico de este Addendum V1.4, pero impiden declarar lista la activación operativa hasta que sean materializadas.

### 8.2 Estado de Gobernanza y Cierre Arquitectónico
- **Estado Oficial:** `APPROVED_FROZEN_V1.4`.
- **Fecha de Aprobación Formal:** 2026-10-20.
- **Autoridad:** Project Owner.
- **Alcance:** Especificación compartida y congelada entre `portal-api-v.1.0.1` y `AdmonGasLector`.
- **Freeze Inalterado:** El archivo [CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md](file:///home/silvinol/Proyectos/portal-api-v.1.0.1/docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md) se mantiene 100% inalterado y congelado.
- **Gobernanza Compartida:** Cualquier modificación futura de V1.4 requerirá una propuesta formal de cambio y la autorización expresa de ambos proyectos bajo las reglas de gobernanza vigentes.
