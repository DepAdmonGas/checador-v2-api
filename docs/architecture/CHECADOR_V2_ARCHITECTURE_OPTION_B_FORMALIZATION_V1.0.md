# CHECADOR V2 — Architecture Option B Formalization V1.0

**STATUS:** `DRAFT_PENDING_CONTRACT_APPROVAL`  
**PROJECT:** `CHECADOR_V2_API`  
**TASK:** `CHECADOR_V2_ARCHITECTURE_OPTION_B_FORMALIZATION_V1`  
**DATE:** 2026-10-26  
**ARCHITECTURAL DIRECTION:** `OPTION_B_APPROVED`  
**CONTRACT AMENDMENTS:** `PENDING`  
**IMPLEMENTATION AUTHORIZED:** `NO`

> Este documento registra como aprobada la dirección arquitectónica comunicada por el responsable: Portal3 leerá una proyección administrativa persistida, versionada y publicada por API V2. La aprobación de dirección no congela aún los textos de enmienda, el esquema, permisos, migraciones o código. Este archivo es una propuesta de formalización y no modifica ni sustituye contratos congelados.

## 1. Registro formal de decisión

### Decisión

**AD-READ-01 — Proyección administrativa persistida para Portal3.** Aprobar como objetivo la arquitectura:

```text
LECTOR → API V2 → BASE DE DATOS → PORTAL3
```

Portal3 tendrá autorización contractual futura para consultar, mediante su backend y Eloquent con privilegios mínimos, una proyección administrativa persistida, versionada y publicada por el componente propietario de los cálculos. La superficie concedida será únicamente dicha proyección; la decisión no autoriza consultas indiscriminadas a tablas internas V2.

| Aspecto | Registro |
|---|---|
| Decisión | Opción B: proyección administrativa persistida/versionada para lectura Portal3 |
| Estado | Dirección aprobada según instrucción expresa del responsable recibida para esta actividad |
| Contratos y textos | Pendientes de conciliación y aprobación formal |
| Autoridad de publicación | API V2, que ya es propietaria de integridad, continuidad y resultados derivados |
| Alcance inicial | Pantalla Portal3 “Asistencia del día” |
| Escrituras administrativas | Permanecen por los mecanismos autorizados actuales; esta decisión no crea acceso SQL de escritura |
| PIN temporal | Continúa validándose por API, sin consulta de PIN/hash desde Portal3 |
| Producción | Sin autorización de cambio o despliegue |

### Arquitectura anterior y motivo del cambio

La arquitectura de lectura administrativa actualmente congelada para Portal3 usa servicios backend de Portal3 que solicitan a API V2 los eventos, snapshots, Level 2, horarios, jornadas, incidencias y evaluaciones mediante límites HTTP autenticados. La dirección aprobada agrega un canal SQL de lectura limitado a una proyección publicada por API para la consulta ordinaria de Asistencia del día.

La proyección reduce el acoplamiento de la pantalla a múltiples respuestas y revisiones operativas, permite leer una publicación coherente y da un contrato de lectura estable. No cambia quién produce la información ni permite a Portal3 reconstruir hechos, pareos, cálculos o evaluaciones.

### Responsabilidades

| Componente | Responsabilidad bajo la dirección aprobada | Límites conservados |
|---|---|---|
| Lector | Captura biométrica; mecanismo autorizado de PIN alternativo; transmite hechos a API V2. | No interpreta entrada/salida, jornada, incidencia ni falta; no conoce el esquema de proyección. |
| API V2 | Valida e ingiere eventos; aplica HMAC e idempotencia; persiste hechos; resuelve identidad/dispositivo; conserva Level 1/2; resuelve horarios; ejecuta pareos, jornadas y evaluaciones; publica revisiones completas de la proyección. | No altera `PunchEvent` original; no infiere resultado administrativo a partir de ausencia de eventos; la publicación no equivale a nómina. |
| Base de datos | Conserva los hechos y resultados autorizados y, una vez aprobada su especificación/implementación, las revisiones de proyección. | No se asume base, host o esquema compartido entre procesos; no autoriza cambios por esta decisión. |
| Portal3 backend | Autentica/autoriza usuarios, obtiene el workplace autorizado, lee por Eloquent la proyección, aplica filtros servidor y presenta su estado y procedencia. | No lee directamente tablas internas V2; no recalcula, reinterpreta, escribe, valida PIN ni expone SQL al navegador. |
| Navegador | Solicita y presenta datos al backend Portal3. | Sin credenciales SQL, acceso directo a base o llamadas directas al API administrativo. |

### Componentes que permanecen sin cambios y transición

Permanecen los contratos de ingesta del Lector, autenticación de dispositivos, integridad HMAC, idempotencia, almacenamiento inmutable de hechos, continuidad Level 2, generación/membresía de snapshots Level 1, scheduling, pareo, cálculo y P3-G administrativo. Las cuatro posiciones compactas de P3-H siguen neutrales y no limitan la colección íntegra de eventos. El sistema legacy y nómina no se alteran.

Hasta que las enmiendas se aprueben e implementen, continúa vigente el canal contractual existente Portal3 backend → API. La futura lectura SQL será un canal adicional y limitado para la proyección indicada. No se retiran endpoints ni se cambia a consumidores existentes sin periodo de compatibilidad y aceptación coordinados.

### Riesgos y dependencias principales

Riesgos: relación física-estación-workplace no probada; esquema activo no verificable en esta sesión; réplica atrasada o publicación obsoleta; mezcla de revisiones; lectura parcial confundida con resultado final; acceso entre workplaces; divergencia entre semánticas HTTP y SQL; selección de persona no resuelta; pérdida de historial por reemplazo destructivo. Dependencias y compuertas quedan detalladas en §§5–12.

## 2. Cláusulas contractuales afectadas y textos propuestos

Los siguientes textos son redacciones candidatas listas para revisar. **Todas requieren aprobación.** No editar, reemplazar ni volver a congelar los documentos originales durante esta tarea.

### 2.1 Architecture Freeze V1.0

**Documento:** `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md` (copia API y copia Lector).  
**Cláusulas:** §1 Source of Truth; §3 Strategy of Evolution; §§6, 18–20 (responsabilidades API y resultados); §52 Governance.  
**Regla vigente:** el Freeze es autoridad compartida e inmutable; no hay implementación ni cambios de tablas/producción sin aprobación y nueva revisión; define API como propietario de ingesta y resultados. No define una lectura Portal3 de la proyección.

**Adición propuesta — nueva sección “Read Projection Boundary” (en una revisión futura aprobada):**

> **Proyección administrativa de lectura.** API V2 podrá generar y publicar una proyección persistida, versionada y trazable de resultados administrativos ya producidos por los componentes autorizados. Portal3 podrá consultarla exclusivamente desde su backend mediante una identidad SQL de sólo lectura limitada al objeto de proyección aprobado y después de validar la autorización institucional y el workplace en cada solicitud. Esta lectura no concede acceso general a tablas operativas V2 ni transfiere a Portal3 propiedad de `PunchEvent`, integridad, identidad, continuidad, snapshots, horarios, pareos, cálculos, incidencias, evaluaciones o validación de PIN. Las operaciones de escritura administrativa y de PIN conservan sus límites contractuales vigentes. La publicación de una revisión parcial no podrá etiquetarse como completa ni sustituir silenciosamente una revisión publicada anterior. La aprobación de esta sección por sí sola no autoriza migraciones, implementación o despliegue.

**Compatibilidad:** deja intactos los límites de Lector, legacy, payroll y hecho original.  
**Riesgo:** nueva frontera persistida de datos y permisos; exige contrato específico, estrategia de compatibilidad y aprobación de los propietarios API/Lector/Portal3.  
**Aprobación:** pendiente del responsable de arquitectura y propietarios API, Lector y Portal3.

El texto requiere registro como adenda/revisión arquitectónica nueva, no inserción unilateral en una copia congelada. Las copias API y Lector deberán actualizarse de forma coordinada e idéntica si el propietario aprueba modificar el Freeze compartido.

### 2.2 API P3-H Event Classification Public Payload V1.1

**Documento:** `docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.1.md`.  
**Cláusulas:** §§1.1, 3–4 (V1.1 limita su supersesión a referencia de endpoint; conserva `pairing_role`, `segment_ordinal` y semántica de payload).  
**Regla vigente:** el contrato API congela el enriquecimiento del payload Journey y sus semánticas; no define una proyección SQL como canal de presentación.

**Adición propuesta — “Projection Compatibility” (no cambiar alcance de V1.1):**

> La proyección administrativa persistida aprobada constituye un canal de lectura adicional para la vista Portal3 “Asistencia del día”. Su productor SHALL conservar la semántica de este contrato: `pairing_role` sólo podrá ser `ENTRY`, `EXIT`, `SURPLUS` o `UNMATCHED`; `segment_ordinal` conservará su valor y nulabilidad contractual; la ambigüedad seguirá perteneciendo a segmento/jornada. Las filas de proyección SHALL identificar la versión de contrato y las revisiones de origen usadas. El canal SQL no altera ni reemplaza este contrato HTTP, el endpoint Journey canónico ni la responsabilidad del motor P1.

**Compatibilidad:** endpoint, autorización y payload HTTP permanecen vigentes; cuatro slots y colección completa siguen reglas P3-H.  
**Riesgo:** productores HTTP y SQL podrían escoger revisiones diferentes; se requiere una serialización semántica común y prueba de paridad.  
**Aprobación:** pendiente de propietarios API P3-H y Portal3 P3-H.

### 2.3 Portal3 P3-H Presentation / Projection V1.1

**Documento:** `Portal3/docs/v2/CHECADOR_V2_PORTAL3_P3H_PRESENTATION_PROJECTION_CONTRACT_V1.1.md`.  
**Cláusulas:** §§3–5.  
**Regla vigente:** presenta el payload API bajo cuatro slots neutrales; conserva colección completa; slot vacío no significa sin marcación/falta; Portal3 no recalcula zona horaria ni jornada.

**Adición propuesta — “Persisted Read Source”:**

> Para la vista Asistencia del día, una vez que su contrato y permisos se aprueben, el backend Portal3 podrá obtener la representación P3-H equivalente desde la proyección Checador V2 publicada. La lectura persistida SHALL preservar las etiquetas y las reglas de selección de V1.1, los estados administrativos separados, todas las marcaciones y las referencias de procedencia. Una respuesta SQL no autoriza reinterpretar el payload, recalcular horarios, pareos, incidencias, zona horaria o falta. Cuando el origen esté incompleto, desconocido, no disponible o sea incompatible, Portal3 SHALL mostrar ese estado sin completar valores por inferencia. La lectura de la proyección no habilita conexión desde navegador.

**Compatibilidad:** no altera orden accesible, presentación, ACL ni composición de cuatro slots; API HTTP sigue siendo el contrato vigente hasta aprobación/activación coordinada del canal.  
**Riesgo:** divergencia entre origen HTTP y SQL y manejo ambiguo de fallos/caché.  
**Aprobación:** pendiente Portal3 y API; futuras copias oficiales y cambios deberán coordinarse entre repositorios correspondientes.

### 2.4 API P3-G Administrative API Boundary V1.0

**Documento:** `docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md`.  
**Cláusulas:** §§1.1, 3.1–3.4, 4.2, §5 (ACL), §9.2 (lectura de incidencias), §9.10 (lectura de evaluación) y §9.11 (solicitud/ejecución de evaluación).  
**Regla vigente:** P3-G es un límite autenticado Portal3 backend→API; contempla lecturas de incidencias y evaluaciones por API, además de mutaciones idempotentes, autorizadas y auditables. P3-G Opción B actual se refiere al dominio V2 canónico de incidencias y no aprueba por sí sola esta Opción B de proyección SQL.

**Adición propuesta — excepción estrecha de lectura a §§3.1 y 5:**

> **Lectura de proyección (excepción limitada).** Para las consultas ordinarias de presentación de Asistencia del día y sólo después de aprobar el contrato de proyección, Portal3 backend podrá ejecutar `SELECT` parametrizado sobre el objeto publicado de proyección con una cuenta dedicada de sólo lectura. La autorización de usuario y workplace se valida en Portal3 para cada consulta, y el objeto sólo expone datos de la proyección para work places aprobados. Esta excepción no reemplaza las rutas API P3-G para crear, revisar, autorizar, rechazar, revocar, resolver o solicitar evaluación; tampoco sustituye rutas de detalle/procedencia salvo que una cláusula específica lo autorice. Proyección y evaluaciones continúan bajo propiedad API. El mecanismo de PIN permanece exclusivamente en API. Un error SQL no habilita fallback que oculte incompletitud ni cambia el resultado a cero filas.

**Compatibilidad:** operaciones P3-G de escritura, autorización, evaluación bajo demanda y PIN permanecen por API y con su ACL/actor/auditoría vigentes.  
**Riesgo:** frontera de confianza cambia para lectura de resultados administrativos; debe quedar especificada minimización de datos y revocación.  
**Aprobación:** responsable P3-G, API, Portal3, seguridad y base de datos. No se presume aprobada por “Architecture Option B”.

### 2.5 Portal3 P3-G Incidence / Administrative Evaluation V1.0

**Documento:** `Portal3/docs/v2/CHECADOR_V2_PORTAL3_P3G_INCIDENCE_ADMINISTRATIVE_CONTRACT_V1.0.md`.  
**Cláusulas:** §§9 (evaluation), 11 (ACL), 12.1–12.3 (“Portal3 Backend → API Only”), 13 (provenance).  
**Regla vigente:** el límite de transporte para operaciones P3-G es Portal3 backend→API; lecturas y escrituras administrativas se someten a identidad, permisos y alcance de workplace; las evaluaciones consumen P1/P2 y no reconstruyen hechos.

**Adición propuesta — “Projection Read Exception”:**

> La regla Portal3 backend→API permanece para todas las operaciones P3-G que muten datos, soliciten evaluación o requieran una acción de dominio. Una futura excepción de transporte se limita a lectura `SELECT` de la proyección administrativa aprobada para la pantalla Asistencia del día. La proyección tendrá versión, refs de origen, estado de completitud, workplace canónico y revisión de evaluación; no se utilizará como almacén de escritura ni se considerará autoridad separada. El backend autenticará al usuario, comprobará sus permisos y resolverá su ámbito institucional vigente antes de consultar; la base limitará privilegios al objeto aprobado. Los conflictos, revisiones y decisiones siguen procesándose por API. No usar legacy para completar el resultado ni derivar `FALTA` desde slots vacíos.

**Compatibilidad:** mantiene dominios de incidencia, semántica humana de autorización, no dual-write legacy y nómina diferida.  
**Riesgo:** acceso a información sensible de evaluación si el objeto/ACL no se reduce al ámbito efectivo.  
**Aprobación:** responsable P3-G/API/Portal3/seguridad.

Las propuestas de §2.4–2.5 requieren actualización sincronizada de los contratos complementarios en API y Portal3; no editar ninguno ahora.

### 2.6 Level 1 Snapshot Read V2.4

**Documento:** `docs/v2/PUNCH_EVENTS_SNAPSHOT_READ_API_CONTRACT_V2.4_LEVEL1.md`.  
**Cláusulas:** §1.1 (garantía y límites), §2.1–2.3 (membresía/ciclo de vida), §§3.2–3.3 (paginación), §§4.1–4.2 (ACL), §6.2 (manifiesto/digest), §§7.2–7.4 (estación/tiempo/pendientes).  
**Regla vigente:** Level 1 garantiza recuperación íntegra de una membresía inmutable bajo MVCC; no garantiza que cola local esté vacía ni que exista continuidad de ingestión; snapshots son íntegros/expirables según lifecycle y scope.

**Adición propuesta — “Projection Derived from Level 1”:**

> La proyección administrativa podrá derivarse de resultados que refieran snapshots Level 1 `READY` y su membresía completa. La fila publicada SHALL conservar `snapshot_token` o referencia pública equivalente, su manifiesto/digest o fingerprint contractual, instante de creación, versión y evidencia de completitud consumida. El productor sólo podrá declarar completa la dependencia Level 1 después de consumir la membresía íntegra y validar conteo/orden/digest de acuerdo con este contrato. La lectura SQL de una fila de proyección no afirma que Portal3 haya recuperado directamente todas las filas snapshot y no altera el ciclo de vida, expiración, ACL o contrato HTTP de Level 1. Snapshot `CREATING`, `FAILED`, `EXPIRED`, ref ausente, consumo parcial o manifiesto incongruente prohíbe publicar la dependencia como completa.

**Compatibilidad:** Level 1 sigue siendo la autoridad de membresía y recuperación; la proyección preserva referencia/garantía, no recrea membresía.  
**Riesgo:** si el TTL elimina evidencia necesaria antes de reproducir la proyección, ésta podría perder auditabilidad; retención y digest requieren definición.  
**Aprobación:** dueño Level 1/API, Portal3 y arquitectura.

### 2.7 Level 2 Continuity V1.1

**Documento:** `docs/architecture/CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md`.  
**Cláusulas:** §§7.1–7.6 (watermarks/ACK y cola), §§8.2–8.6 (política/telemetría/cobertura), §§9.1–9.4 (asignación histórica y generaciones relevantes), §§10.3–10.5 (`WINDOW_LEVEL2`), §§11.1–11.3 (evidencia durable), §12 (frontera entre niveles).  
**Regla vigente:** la completitud Level 2 se evalúa por generación de dispositivo, entrega, cobertura y atribución de ventana; `UNKNOWN`/`INCOMPLETE` no autoriza declarar que no hubo marcación; Level 1 no sustituye Level 2.

**Adición propuesta — “Projection Carries Level 2 Evidence”:**

> La proyección SHALL incorporar el `window_level2_status` y referencias a la evaluación Level 2, ventanas UTC, conjunto de `RELEVANT_DEVICE_GENERATIONS`, política de cobertura y watermarks que justifican el resultado cuando estos datos sean aplicables. El productor SHALL consumir la evaluación emitida por el propietario Level 2 y no recalcularla dentro del proyector ni en Portal3. La proyección puede conservar un resumen de presentación, pero dicho resumen SHALL mantener distintos `VERIFIED`, `INCOMPLETE` y `UNKNOWN`. UNKNOWN, INCOMPLETE, evidencia vencida no evaluada, ausencia de telemetría o generación/dispositivo no resoluble no puede publicar como completa una conclusión de cero eventos o una ausencia laboral. La consulta SQL no reemplaza capacidades Level 2 ni cambia la atribución temporal congelada.

**Compatibilidad:** Level 2 continúa bajo API/Lector; no añade estados a la máquina Level 2 ni modifica políticas.  
**Riesgo:** mapeo histórico incompleto de dispositivo puede distorsionar el conjunto de generaciones y la ventana.  
**Aprobación:** propietarios Level 2/API/Lector y arquitectura; copia del contrato compartido debe coordinarse con Lector.

### 2.8 Shift Scheduling y Journey P1/P2

**Documentos:** `docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md`, `docs/v2/CHECADOR_V2_JOURNEY_PAIRING_CONTRACT_V1.0.md`, `docs/v2/CHECADOR_V2_JOURNEY_CALCULATION_CONTRACT_V1.0.md`.  
**Cláusulas:** Scheduling §§1, 2–4, 6.1–6.8; P1 §§3–7; P2 §§3–7 y §9.  
**Regla vigente:** Scheduling resuelve horario efectivo/histórico y materializa jornada; P1 asocia hechos inmutables con segmentos; P2 calcula resultados sin cambiar hechos. Las reglas y revisiones viven en servicios API; P1/P2 no confieren autoridad de recálculo a Portal3.

**Adición propuesta a los tres contratos — “Projection Read Consumer”:**

> Un productor API autorizado podrá publicar en la proyección una copia de lectura referenciada del resultado vigente producido por Scheduling/Journey, identificando `worker_id`, `workplace_id`, `work_date`, zona horaria efectiva, `scheduled_shift` y versión de plantilla/asignación, snapshot consumido, `pairing_ref`/revisión y `calculation_ref`/revisión. Esta publicación no ejecuta ni cambia la resolución, materialización, pairing o cálculo; no modifica su regla de versionado ni convierte el endpoint/servicio interno en lectura SQL pública de sus tablas. Portal3 consume el resultado publicado y no selecciona por heurística una revisión diferente. `DAY_OFF`, `UNSCHEDULED`, `UNKNOWN`, datos parciales, P1 ambiguo o `PENDING_SYNC` permanecen diferenciados. Ningún estado P1/P2 se traduce automáticamente a `FALTA`.

**Compatibilidad:** APIs y servicios vigentes siguen disponibles para sus consumidores y operaciones; propietario de Scheduling/P1/P2 conserva lógica.  
**Riesgo:** referencias de revisión o timezone efectivas mal enlazadas producirían mezcla temporal; requiere tests de conciliación fuente-proyección.  
**Aprobación:** responsables Scheduling, P1/P2, API y Portal3.

## 3. Contrato candidato de la proyección — Asistencia del día

**Tipo:** esquema lógico candidato; todos los nombres son propuestos y no designan columnas/tablas implementadas.  
**Clave lógica candidata:** `(workplace_id, work_date, worker_id, publication_revision)`. Se debe decidir cardinalidad para un trabajador con más de una jornada en distintos workplaces o turnos múltiples durante la misma fecha.  
**Productor:** API V2, posterior a la aprobación/implementación del proyector.  
**Consumidor:** servicio de backend Portal3 únicamente.

### 3.1 Campos contractuales candidatos

| Grupo | Campos lógicos propuestos | Fuente y estado |
|---|---|---|
| Identidad/alcance | `worker_id` canónico; referencia a resolución histórica; `workplace_id`; `physical_station_ref` y estado de mapeo | `op_rh_personal.id`; identidad V2 por dispositivo/vigencia; relación estación-workplace aún bloqueada (§4). |
| Fecha/hora | `work_date`; `timezone_iana`; timestamps UTC de eventos; hora local derivada sólo para presentación | ScheduledShift y autoridad timezone efectiva; no convertir en Portal3 ni fallback de device timezone. |
| Horario/jornada | estado de horario; `schedule_ref`; resumen de segmentos; `journey_ref`; `pairing_ref`, `calculation_ref` y revisiones | Scheduling/P1/P2. Estado no resuelto permanece explícito. |
| Slots neutrales | refs/tiempos/estado para Entrada 1, Salida 1, Entrada 2, Salida 2 | `segment_ordinal` + `pairing_role` de P1. Sin reinterpretar ni truncar la colección. |
| Evaluación | `evaluation_ref`, versión, estado y `incidence_refs` con revisiones | P3-G. `FALTA` requiere las condiciones y autorización humana de P3-G. |
| Level 1 | referencia/token snapshot, versión/manifest, creación, estado de membresía y cobertura | Sólo `READY` y membresía íntegra pueden satisfacer la dependencia. |
| Level 2 | `evaluation_ref`, `window_level2_status`, generaciones/política/watermark y hora evaluada | Evaluador Level 2/API. Conservar VERIFIED/INCOMPLETE/UNKNOWN distintos. |
| Publicación | `projection_contract_version`; clave/revisión; `generated_at_utc`; `published_at_utc`; `updated_at_utc`; referencias/hashes de entrada | Metadatos del productor; no equivalen a frescura ni autorizan elegir revisiones localmente. |
| Completitud | `projection_status`, `incompleteness_reasons`, `source_provenance`, conteos de cobertura requeridos | API evalúa las compuertas de cada fuente; incompleto nunca se normaliza a ausencia. |
| Detalle íntegro | `punch_collection_ref`, número/orden/continuación de colección, `extra_event_count` | Debe permitir acceso a todas las marcaciones autorizadas por el contrato; no sólo cuatro slots. |

No se incluyen biometría, secretos, hashes HMAC, Authorization headers, PIN ni sus hashes. El nombre de colaborador es etiqueta de presentación, no identificador ni criterio de autorización.

### 3.2 Slots y estado de datos

| Slot | Regla de selección sin modificación | Presentación |
|---|---|---|
| 1 | `segment_ordinal=1 AND pairing_role=ENTRY` | Entrada 1 |
| 2 | `segment_ordinal=1 AND pairing_role=EXIT` | Salida 1 |
| 3 | `segment_ordinal=2 AND pairing_role=ENTRY` | Entrada 2 |
| 4 | `segment_ordinal=2 AND pairing_role=EXIT` | Salida 2 |

Preservar `ENTRY`, `EXIT`, `SURPLUS`, `UNMATCHED`; no inventar rol `MEAL`. Un slot no asignado no significa que faltó una marcación, ausencia o `FALTA`. Cinco o más eventos y ordinales 3+ se conservan en la colección completa indicada por P3-H. `PENDING_EVALUATION`, `INVALID`, `UNVERIFIABLE`, `RESULT_INCOMPLETE` se podrán incluir como estados de presentación sólo tras mapearlos al vocabulario API vigente; no se propone modificar el estado del hecho original.

Estados de fila candidatos: `COMPLETE`, `INCOMPLETE`, `UNKNOWN`, `UNAVAILABLE`; `STALE` únicamente cuando exista un TTL/SLA aprobado y medido contra watermark autoritativo. `NO_RECORDS_VERIFIED` sólo es válido con cobertura fuente completa y evaluación de continuidad aplicable. Error de SQL, identidad/workplace no resuelta, snapshot truncado, evento pendiente o Level 2 desconocido nunca devuelve una lista vacía como si fuera resultado íntegro.

## 4. Mapeo institucional y de estación

### Hechos documentados

1. P3-G Portal3 V1.0 §4.2 y el API Boundary P3-G V1.0 §4.2 establecen `op_rh_localidades.id` como workplace canónico y el puente de alcance administrativo `tb_estaciones.id → numlista válido único → op_rh_localidades.id`. Prohíben igualdad numérica de claves y requieren verificación server-side del scope.
2. Migración API raw `2026_09_28_000001` define `op_rh_v2_dispositivos.id_estacion` y `op_rh_v2_marcaciones_crudas.id_estacion_resuelta`; esta última puede ser NULL. El fragmento de migración no declara una FK de esas columnas a `tb_estaciones` ni a `op_rh_localidades`.
3. Migración Level 2 `2026_10_05_000002` define `op_rh_v2_dispositivo_estacion_historial(id_dispositivo, id_estacion, valido_desde_utc, valido_hasta_utc)` y `op_rh_v2_level2_window_evaluations.id_estacion`; el contrato Level 2 §9.1 atribuye a API el historial efectivo de asignación dispositivo→estación. Esto prueba el historial y las ventanas dentro de un namespace de ID denominado `id_estacion`, pero los documentos consultados no prueban que ese namespace sea `tb_estaciones.id` o `op_rh_localidades.id` ni establecen una conversión entre ellos.
4. Migración de Shift Scheduling comenta que `id_estacion` de asignaciones/jornadas es ID de workplace/estación en `op_rh_localidades`. Esa semántica no se debe aplicar por inferencia a `id_estacion` de dispositivos.
5. `op_rh_v2_dispositivo_identidad_personal` registra referencia externa de trabajador, dispositivo, `id_personal` y vigencia. Es la relación de identidad candidata; ausencia/solapamiento o filas actuales deben verificarse con esquema/datos autorizados, nunca resolverse por texto.
6. El Architecture Freeze §17 requiere conceptualmente una asignación histórica trabajador→workplace (`WorkerWorkplaceAssignment`), pero la revisión focalizada no confirmó una relación física activa con vigencia que resuelva todo el padrón administrativo para cada fecha. Las asignaciones de turno worker/workplace sirven para resolver horario cuando existen; no demuestran por sí mismas el workplace administrativo principal ni autorizan acceso del usuario Portal3.

### Dictamen

`WORKPLACE_MAPPING_STATUS: BLOCKED_AUTHORITATIVE_SOURCE`

**Fuente autoritativa demostrada:** existe una regla congelada para resolver el workplace autorizado desde la estación Portal3 vía `numlista` único válido. No se demostró una fuente que conecte inequívocamente `op_rh_v2_dispositivo_estacion_historial.id_estacion` / `dispositivos.id_estacion` con la estación física Portal3 ni con `op_rh_localidades.id` a través del tiempo. El contrato Level 2 identifica al API como dueño del historial dispositivo→estación, pero no resuelve por sí solo el espacio de claves en los artefactos inspeccionados.

**`HISTORICAL_MAPPING_DEFINED`:** parcial: se define historial device→station en Level 2 y vigencia de identidad device→worker; no se define/valida puente histórico físico station→localidad/workplace ni la relación administrativa worker→workplace para cada fecha/punch. La autoridad comercial para resolver esas brechas y la validación activa permanecen pendientes.

### Diseño de fail-closed para alcance

Portal3 debe construir el conjunto de workplaces permitidos desde la autorización vigente del usuario (configuración multiestación/home station y módulo funcional) y traducir station→workplace mediante la regla P3-G de número único, nunca igualdad de IDs. La consulta debe exigir `workplace_id` en el conjunto autorizado, además de fecha limitada; parámetros del cliente no otorgan permisos. La proyección sólo publica la estación/workplace que API haya resuelto mediante una asignación efectiva-datada demostrable y deja la referencia de esa relación.

Si una de las relaciones es nula, ambigua, solapada, no vigente para `occurred_at`/`work_date`, o apunta a un workplace distinto del horario/jornada, API bloquea publicación como completa (`UNKNOWN`/`INCOMPLETE` con motivo de mapeo). Portal3 falla cerrado sin revelar si hay datos de otra estación. No se crean mapeos sintéticos, no se descifra una clave legacy para inferirlos y no se muestra un registro de otro workplace mediante un fallback.

Para liberar el bloqueo harán falta: definir el namespace de ID en cada componente; identificar el registro de negocio que vincula ID físico a Portal3 `tb_estaciones` y número único; confirmar sus vigencias, reasignaciones y aprobadores; identificar la fuente efectiva-datada de relación trabajador→workplace para la fecha laboral; e inspeccionar sólo metadatos autorizados/fixtures de desarrollo para confirmar claves y restricciones. El scope del usuario Portal3 y la asignación del trabajador son validaciones distintas. Ninguna relación de negocio se puede inventar en este diseño.

## 5. Verificación del esquema activo

**Resultado de intento de verificación:** `ACTIVE_SCHEMA_VERIFICATION: BLOCKED`.

Se intentó una consulta de metadatos read-only usando el cliente local MariaDB, socket local, base objetivo `bd_admongas`, consulta limitada a identidad de servidor/base/usuario activo (sin leer filas personales). El servidor rechazó la autenticación (`ERROR 1698`, usuario local `silvinol`), por lo que no se pudo demostrar identidad de sesión, grants, ni que la conexión corresponda al DEVELOPMENT aprobado. No se intentaron credenciales alternas, no se leyó ni imprimió `.env`, no se abrió conexión de red/producción y no se hicieron consultas de datos.

| Elemento | Estado en repositorio/migraciones | Estado de esquema activo |
|---|---|---|
| Raw punches, devices, identity | Migración `2026_09_28_000001` documenta tablas/columnas e índices | No verificado ahora |
| Level 1 snapshots | Migración `2026_10_05_000001` y contrato V2.4 documentan snapshots/membresía | No verificado ahora |
| Level 2 | Migración `2026_10_05_000002`, contrato V1.1 y changelog de DEVELOPMENT documentan tablas/evaluaciones | No verificado ahora |
| Scheduling | Migración `2026_10_06_000001` y contrato V1.0 documentan plantillas/segmentos/asignaciones/jornadas | No verificado ahora |
| Pairing/calculation | Migraciones `2026_10_07_000001/000002` y contratos físicos documentan resultados/versiones | No verificado ahora |
| Incidencias/evaluaciones | Migraciones `2026_10_07_000003/000004` y P3-G documentan persistencia | No verificado ahora |
| Proyección administrativa | No aparece una migración de proyección en el inventario revisado; propuesta anterior afirma que no se identificó proyección | Existencia activa no verificada |

La evidencia previa del changelog registra verificaciones y acciones autorizadas en `bd_admongas`/DEVELOPMENT durante octubre. Esa evidencia histórica no acredita que las credenciales/grants estén disponibles en esta sesión ni actualiza el esquema a la fecha. Por tanto:

`PERSISTED_PROJECTION_EXISTS: NOT_VERIFIED`  
`SCHEMA DOCUMENTED ≠ ACTIVE SCHEMA VERIFIED`

No se justifica crear tabla o elegir vista hasta completar una inspección read-only autorizada de `information_schema` para sólo las tablas necesarias y contrastar columnas/PK/FK/índices con migraciones. Antes de acceder, el administrador del entorno deberá habilitar/confirmar credenciales read-only con etiqueta explícita de DEVELOPMENT; ninguna credencial se solicita ni se muestra aquí.

## 6. Actualización híbrida y publicación consistente

**Contrato candidato:** disparos event-driven para baja latencia más una conciliación controlada para recuperar fallos. No instalar cron, worker ni proceso en esta fase.

### 6.1 Disparadores candidatos

Cambios o evidencias nuevas que pueden invalidar una proyección: PunchEvent aceptado/idempotente; cambio de resolución histórica de identidad o device-station; nueva generación/telemetría o evaluación Level 2; snapshot Level 1 listo/cambio a nueva membresía de fuente; modificación efectiva de horario/asignación/jornada; nueva revisión P1/P2; nueva incidencia, ajuste, resolución/evaluación P3-G. Sólo APIs/componentes propietarios notifican el cambio y sus revisiones. Evento repetido con misma clave/fingerprint no crea duplicados.

### 6.2 Ciclo de generación

1. Registrar una solicitud lógica de regeneración por clave `(workplace, work_date, worker, source_revision_set)` o clave equivalente aprobada. Idempotency key única por conjunto de entradas; duplicado devuelve la misma revisión/estado.
2. Leer y fijar un manifiesto de fuentes: snapshot/token/membresía, evaluaciones Level 2 y generaciones relevantes, identidad/mapeo, schedule, pairing, calculation, incidencias/evaluación. Verificar compatibilidad de versiones, vigencia, integridad, autorización de workplace y cobertura total.
3. Construir en staging/espacio no publicado una nueva revisión inmutable; verificar cardinalidad, refs, orden de eventos, estados y conteos; nunca completar datos inexistentes con valores fabricados.
4. Si falla una dependencia requerida, conservar evidencia de la ejecución fallida y motivo. No promover la revisión a vigente/completa. La última revisión publicada íntegra permanece legible con su edad y refs; indicar que existe solicitud no finalizada por un registro de estado separable, sin sobrescribir el resultado histórico.
5. Sólo tras pasar compuertas, publicar la revisión completa y cambiar atómicamente el puntero/vigencia a ella. Mantener la versión anterior y su linaje. El lector ve una única revisión consistente, no una mezcla de staging/publicación.

### 6.3 Atomicidad e idempotencia

**Viabilidad condicionada, no verificada:** si fuentes y proyección están en la misma base InnoDB, el diseño candidato es insertar filas nuevas, membresías y manifiesto y cambiar puntero vigente dentro de una transacción, con unique key de idempotencia, aislamiento consistente y serialización/compare-and-swap por clave lógica. Un rollback deja el puntero previo intacto. No hacer `UPDATE` destructivo de valores históricos; un puntero current puede actualizarse sólo en la misma transacción que valida y publica la nueva revisión, manteniendo historial append-only.

Si la lectura usa réplica, esquema separado u otra instancia, una única transacción local no garantiza atomicidad con las fuentes. En ese caso, fijar versiones/fingerprints fuente en manifiesto y emitir estado sólo después de que el conjunto completo se haya aplicado y verificado; publicar el puntero en una transacción local atómica. Consistencia entre servicios y ventanas de atraso se documentan como eventuales, con revisión de fuente y watermark visibles. La topología real y soporte de transacción aún no están verificados; el esquema exacto no se aprueba en este documento.

### 6.4 Frescura y recuperación

`generated_at_utc`, `published_at_utc`, revisiones y watermarks son evidencia, no definición de “actual”. No declarar `STALE` ni un SLA sin parámetro aprobado (umbral, fuente temporal, horario operativo y comportamiento de UI). Conciliación controlada compara revisiones/watermarks origen vs proyección, reintenta por clave con backoff definido en fase de implementación y permite replay determinista. Un error repetido conserva la última revisión completa y agrega estado de generación fallida; nunca borra su procedencia ni publica parcial como definitiva.

## 7. Conexión SQL de Portal3 y topología

### Contrato de acceso candidato

- Eloquent en backend Portal3 mediante conexión Checador independiente por entorno; no reutilizar por suposición la conexión de runtime actual.
- Usuario/rol separado, `SELECT` sólo sobre vista/tablas de proyección aprobadas. Sin DML, DDL, `GRANT`, acceso a credenciales de dispositivo, clave HMAC, ni tablas internas no incluidas.
- TLS y secreto almacenado por mecanismo aprobado de plataforma; secreto no presente en código, errores, logs ni navegador. Procedimiento de rotación y revocación requerido antes de preproducción.
- Autenticar primero sesión/JWT Portal3 y ACL funcional; resolver de nuevo workplace autorizado; consulta parametrizada con `workplace_id`, fecha/rango acotado, worker opcional y orden/paginación fija. La capa de datos vuelve a comprobar alcance. IDs de usuario/navegador son sólo filtros, no autoridad.
- Auditoría de acceso con actor lógico, resultado, workplace, fecha, revisión de proyección y correlation ID; excluir Authorization headers, PIN, secretos y datos personales no requeridos.
- No SQL desde browser. Errores distintos `UNAUTHORIZED`, `SCOPE_DENIED`, `SOURCE_UNAVAILABLE`, `PROJECTION_INCOMPLETE`, `VERSION_UNSUPPORTED`; cero filas sólo con conjunto completo y autorizado.

### Alternativas de topología

| Alternativa | Ventajas | Riesgos/condiciones |
|---|---|---|
| A. Base compartida con usuario de lectura | Menos infraestructura y potencialmente menor atraso; operación sencilla si hay misma región/instancia. | La cuenta debe quedar limitada a proyección. Lectura compite con API; servidor/base común amplía impacto de incidente. No verificado que API/Portal3 compartan instancia. |
| B. Réplica de lectura | Aísla carga y cuenta; no expone primario a Portal3. | Replication lag puede mostrar revisiones anteriores; requiere watermark, monitor, comportamiento ante atraso y SLA. No se verificó una réplica. |
| C. Esquema/almacén dedicado de proyección | Superficie de acceso más estrecha y contrato estable; puede desacoplar internamente las tablas V2. | Pipeline y almacenamiento adicionales, consistencia eventual y recuperación; requiere owner/infra y costo. No se verificó una plataforma existente. |

**Recomendación:** no fijar topología aún; estado `PENDING_INFRASTRUCTURE_EVIDENCE`. Como principio de seguridad, preferir réplica o esquema/almacén dedicado si la infraestructura los soporta; usar base compartida sólo si se confirma que una vista/esquema aislado concede SELECT exclusivamente sobre proyección y la carga/aislamiento son aceptables. Falta confirmar host, instancia, red y TLS entre API y Portal3, disponibilidad de réplica/almacén, lag objetivo, soporte transaccional, mecanismo de secretos, recuperación y grants. No crear usuarios ni modificar conexión ahora.

## 8. Plan técnico de implementación por etapas (no autorizado)

| Etapa | Responsable | Dependencias / archivos candidatos | Aceptación y evidencia requerida | Riesgo / bloqueo |
|---|---|---|---|---|
| 1. Contratos | API + Portal3 + Lector cuando cambia Freeze | Adenda de Architecture Freeze coordinada API/Lector; revisión nueva P3-H/P3-G API y Portal3; enmiendas a Level1/2/Scheduling/Journey. | Firmas/aprobaciones explícitas; hashes/versiones; semántica compatible; decisión de scope y propietario. | Bloqueada hasta aprobar textos. No cambiar copias durante esta tarea. |
| 2. Esquema y permisos | API + DBA/Seguridad/Infraestructura; Portal3 para reader role | Read-only `information_schema`; migración aditiva y rollback sólo después de diseño aprobado; configuración de conexión futura de Portal3. | Diff de esquema actual vs migraciones, elección documentada tabla/vista, claves/índices/retención; cuenta read-only probada con SELECT permitido y DML/DDL denegado en DB disposable; sin prod. | Ahora bloqueada: acceso local rechazado y schema active no verificado; mapeo bloqueado. |
| 3. Productor API | API | Servicios/capa nueva de proyección y worker/dispatcher bajo ruta definida tras contratos; candidato de test aislado; no determinar nombres de código ahora. | Pruebas de idempotencia/retry, concurrencia/rollback, fail-closed, publicación atómica, lineage, Level1/2, versiones, no mutar hechos y reproducibilidad. | No desarrollar antes de esquema/contrato aprobado. |
| 4. Consumidor Portal3 | Portal3 | Conexión Eloquent independiente, servicio de lectura, controller y config de entorno identificados por Portal3 después de su aprobación. | ACL y scope para workplace; filtros parametrizados; paridad con P3-H; estados completos/parciales/error; no browser SQL; roles read-only. | Fuera del repo de esta tarea; exige contrato y topología. |
| 5. Integración de desarrollo | API + Portal3 | Ambiente DEVELOPMENT autorizado, fixtures aislados, fuente y proyección aprobadas. | Conciliar fuente vs proyección, paginación, mapping, revisiones, controles cross-workplace y carga; evidencia sin datos sensibles. | Bloqueada hasta schema/mapping/conectividad. |
| 6. Preproducción | Operaciones/Seguridad + API/Portal3 | Entorno aislado y plan de despliegue/rollback revisado. | Validación de grants, TLS, latencia/frescura acordada, recuperación, observabilidad, regresión e isolación; aprobación formal separada. | No autoriza ni implica producción. Producción requiere gate independiente. |

No se define despliegue, cron, DDL o cambio runtime en esta actividad.

## 9. Decisiones pendientes y bloqueos

| Decisión | Estado | Evidencia/acción necesaria para resolver |
|---|---|---|
| Dirección arquitectónica Opción B | APROBADA como dirección | Instrucción expresa del responsable en solicitud de esta tarea; conservar límites del §1. |
| Textos de enmienda y revisiones contractuales | PENDING | Aprobación por dueños; después actualizar de forma coordinada los contratos originales/copias. |
| Fuente/puente físico station→workplace | `BLOCKED_AUTHORITATIVE_SOURCE` | Definir namespace ID y autoridad de relación; demostrar mapeo histórico vigente con fuente gobernada, sin igualdad numérica. |
| Esquema de base activa | `BLOCKED` | Acceso explícitamente read-only a DEVELOPMENT con grants; comparar metadatos de tablas candidatas contra migraciones. |
| Existencia de proyección persistida | `NOT_VERIFIED` | Esquema activo verificado y búsqueda en objetos autorizados; no existe migración de proyección en inventario API revisado. |
| Topología SQL | PENDING_INFRASTRUCTURE_EVIDENCE | Confirmar primary/replica/store, conectividad, aislamiento, TLS, lag, transacciones y owner. |
| Criterio `COMPLETE`/`STALE`/`NO_RECORDS_VERIFIED` | PENDING | Aprobar dependencias requeridas, SLAs, retención snapshot y watermark de todas las fuentes. |
| Cardinalidad y retención/versionado de proyección | PENDING | Definir llave para múltiples jornadas/workplaces y política audit/history. |
| Inclusión de P3-G incidencias/evaluaciones | PENDING | Aprobar excepción limitada de lectura; decisiones/escrituras permanecen API. |
| Autorización de implementación | NO | Sólo con contratos aprobados, esquema/mapping verificados y gates de status/schedule. |

## 10. Condiciones para autorizar implementación

No comenzar implementación hasta que se cumplan todas las condiciones siguientes:

1. El responsable aprueba texto concreto y la versión de cada enmienda; copies oficiales compartidas tienen plan de sincronización y aprobación API/Lector/Portal3.
2. Se verifica fuente autoritativa de workplace y relación histórica estación/dispositivo/localidad; reglas de fail-closed pasan revisión de seguridad.
3. DBA/owner autoriza acceso read-only a metadatos DEVELOPMENT; el esquema activo se compara con migraciones y queda trazabilidad de discrepancias.
4. Contrato candidato define campos, estados, claves, versiones, paginación, lineage, retención, compatibilidad y semántica de frescura.
5. Topología, rol SQL, ACL Portal3, TLS, secretos, logging, revocación y aislamiento están aprobados por infraestructura/seguridad.
6. Diseño de publicación define idempotencia, fuentes fijadas, compuertas, transacción o publicación equivalente, no-publicación parcial y recuperación.
7. `docs/status/CHECADOR_V2_IMPLEMENTATION_STATUS.md` confirma actividad autorizada; se evalúa gate contra master schedule y no se avanza automáticamente a otro slice.
8. Aprobación separada para migraciones/código/ambientes. Producción nunca se infiere de aprobación de desarrollo.

## Referencias

### API

- `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md` (FROZEN, SHA-256 conservado en contratos).
- `docs/status/CHECADOR_V2_IMPLEMENTATION_STATUS.md`; `docs/planning/CHECADOR_V2_MASTER_SCHEDULE_2026.md`.
- `docs/architecture/CHECADOR_V2_READ_PROJECTION_CONTRACT_PROPOSAL_V1.0.md` (propuesta previa; se usa como antecedente, sin modificación).
- `docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.1.md`; `docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md`.
- `docs/v2/PUNCH_EVENTS_SNAPSHOT_READ_API_CONTRACT_V2.4_LEVEL1.md`; `docs/architecture/CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md`.
- `docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md`; Journey P1/P2 contracts and physical models; workplace timezone addendum.
- Migrations `2026_09_28_000001`, `2026_10_05_000001/000002`, `2026_10_06_000001`, `2026_10_07_000001–000004`, `2026_10_12_000001` (documented candidates only; not proof of active schema).
- `docs/changelog/CHECADOR_V2_API_CHANGELOG.md` (prior DEVELOPMENT verification evidence).

### Portal3

- `docs/architecture/CHECADOR_V2_PORTAL3_DIRECT_DATABASE_READ_PROPOSAL_V1.0.md` (pre-existing, untracked local proposal; consulted without modification).
- `docs/v2/CHECADOR_V2_PORTAL3_P3H_PRESENTATION_PROJECTION_CONTRACT_V1.1.md`.
- `docs/v2/CHECADOR_V2_PORTAL3_P3G_INCIDENCE_ADMINISTRATIVE_CONTRACT_V1.0.md`.

## Cierre de esta formalización

`ARCHITECTURE_OPTION_B_APPROVED: YES (direction only)`  
`CONTRACT_AMENDMENTS_APPROVED: NO`  
`WORKPLACE_MAPPING_STATUS: BLOCKED_AUTHORITATIVE_SOURCE`  
`ACTIVE_SCHEMA_VERIFICATION: BLOCKED`  
`PERSISTED_PROJECTION_EXISTS: NOT_VERIFIED`  
`IMPLEMENTATION_AUTHORIZED: NO`
