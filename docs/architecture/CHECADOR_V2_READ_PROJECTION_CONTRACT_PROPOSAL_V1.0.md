# CHECADOR V2 — Read Projection Contract Proposal V1.0

**STATUS:** `DRAFT_NOT_APPROVED`  
**PROJECT:** `CHECADOR_V2_API`  
**TASK:** `CHECADOR_V2_READ_PROJECTION_CONTRACT_DESIGN_V1`  
**DATE:** 2026-10-26  
**SCOPE:** Propuesta documental para lectura administrativa; no es contrato runtime ni autoriza implementación.

> Toda entidad, campo, llave o índice marcado como propuesto es diseño conceptual. No acredita que exista en la base activa. Este archivo no modifica ni sustituye Architecture Freeze, P3-H, P3-G, Level 1, Level 2, Scheduling o Journey.

## 1. Objetivo

Proponer un contrato versionado de lectura persistida para que Portal3 consulte la vista **Asistencia del día** sin rehacer pareos, cálculos, evaluación administrativa, continuidad ni zona horaria. API V2 conserva propiedad de los hechos, resultados, integridad y generación de la proyección. Portal3 autentica a la persona administrativa, valida su ámbito institucional en servidor, presenta el resultado y no escribe en Checador V2.

La propuesta retoma como antecedente `Portal3/docs/architecture/CHECADOR_V2_PORTAL3_DIRECT_DATABASE_READ_PROPOSAL_V1.0.md`. Es una propuesta nueva y enfocada a esta pantalla; no convierte aquel documento ni la Opción B en aprobación arquitectónica.

## 2. Alcance

Ruta de Portal3: `/departamento-operativo/recursos-humanos/checador/asistencia`.

Incluye colaborador, horario efectivo, cuatro slots neutrales, estado de evaluación, incidencias, referencias a detalle/jornada, estación, fecha laboral, identidad canónica, completitud, continuidad, procedencia, versión de evaluación y actualización.

No incluye otros reportes, modificación de UI, operaciones de escritura, nuevo endpoint, SQL ejecutable, migración ni acceso browser-to-database. Las cuatro posiciones son una presentación compacta; no limitan la colección completa de eventos.

## 3. Arquitectura propuesta

```text
Lector → API V2 → fuentes persistidas y resultados V2
                         ↓ productor API, por definir
              proyección versionada de lectura
                         ↓ SELECT dedicado
              servicio backend de Portal3
                         ↓ autorización Portal3
                       interfaz
```

API seguiría siendo responsable de autenticación de dispositivos, integridad HMAC, idempotencia, hechos originales, Level 2, snapshots Level 1, horarios, pareos, cálculos y evaluación. API generaría/publicaría la proyección propuesta. Portal3 haría lectura de servidor, autorización ACL y alcance, y presentación. No se asume que API y Portal3 compartan host, base, esquema o instancia.

**Propuesta:** Opción B significa aquí “proyección administrativa de lectura versionada”. No debe confundirse con la Opción B aprobada por P3-G para la gestión de incidencias (dominio V2 canónico y adapters legacy sólo de lectura).

## 4. Fuentes autoritativas y estado de verificación

| Dominio | Fuente autoritativa/candidata conocida por contratos y documentos | Naturaleza | Límite de verificación |
|---|---|---|---|
| Persona | `op_rh_personal.id` | Identidad canónica | El registro y sus relaciones activas no se consultaron en servidor. |
| Evento original | `op_rh_v2_marcaciones_crudas` | Hecho inmutable recibido; no equivale a entrada/salida | No consultado en servidor. |
| Dispositivo e identidad histórica | `op_rh_v2_dispositivos`, `op_rh_v2_dispositivo_identidad_personal` | Origen, referencia externa y resolución temporal | No verificar cobertura/vigencia real sin esquema y datos activos. |
| Localidad / workplace | `op_rh_localidades.id` | Identificador canónico workplace | No equivale por valor numérico a `tb_estaciones.id`. |
| Membresía Level 1 | `op_rh_v2_read_snapshots`, `op_rh_v2_read_snapshot_members` (según antecedente documental) | Corte/membresía exacta | Retención y estado en base activa no confirmados. |
| Nivel 2 | Contrato Level 2 V1.1: generaciones, secuencias, watermark y evaluación de ventana | Evidencia de continuidad/cobertura | Nombres/cobertura física varían; confirmar contra versión de migración y servidor. |
| Horarios | `op_rh_v2_turnos_plantillas`, `op_rh_v2_turnos_segmentos`, `op_rh_v2_turnos_asignaciones`, `op_rh_v2_jornadas_programadas` | Definición, vigencia y hecho programado | Esquema activo y existencia de jornada para fecha consultada no verificados. |
| Pareo/cálculo | `op_rh_v2_pareos_jornada`, tablas asociadas y `op_rh_v2_calculos_jornada`, `op_rh_v2_calculos_segmentos` | Resultados derivados versionados | Candidatos documentados; despliegue/runtime y revisión vigente requieren verificación. |
| Incidencias/evaluación | `op_rh_v2_incidencias_*`, `op_rh_v2_evaluaciones_administrativas`, asociaciones de incidencias/ajustes | Evidencia y resultado administrativo | No presumir evaluación por el mero hecho de que existan tablas/código documentados. |
| Localidad–timezone | `op_rh_v2_localidades_zonas_horarias` y `op_rh_v2_jornadas_programadas.timezone_local` conforme addendum | Configuración efectiva y snapshot inmutable | No inferir desde timezone de dispositivo ni fallback global. Verificar intervalo efectivo. |

Las referencias de tabla son nombres documentados/candidatos, no confirmación de esquema desplegado. Se requiere introspección read-only del esquema activo y correspondencia de versiones antes de cualquier implementación. No se consultó una base de datos en esta tarea.

## 5. Categorías contractuales de datos

- **Original:** datos persistidos de un `PunchEvent`; no se reescriben para asignar significado.
- **Derivado:** horario resuelto, pareo, cálculo, estado de continuidad o completitud generado por lógica autorizada.
- **Administrativo:** incidencia, ajuste y evaluación autorizada, con actor/revisión/linaje cuando aplique.
- **Metadato de proyección:** versión de contrato, publicación, corte, estado y referencias de procedencia.

Los nombres siguientes son **propuestos** para el contrato lógico de lectura, no nombres de columnas físicas existentes.

## 6. Diccionario de datos propuesto

Nulabilidad: `NO` significa requerido para emitir fila completa; `SÍ` significa que puede ser nulo con el motivo/estado indicado. Identificadores de filas fuente deberán ser opacos/publicables o referencias estables aprobadas; no filtrar claves secretas, hashes de credenciales o datos biométricos.

| Campo propuesto | Tipo / nulable | Clase; fuente/relación | Significado y disponibilidad | Asignación/actualización y seguridad |
|---|---|---|---|---|
| `projection_contract_version` | string, NO | Metadato; contrato | Versión semántica compatible de proyección | API productor. Cambio compatible aditivo; versión mayor para cambio incompatible. |
| `projection_key` | string, NO | Metadato; clave lógica propuesta | Identifica workplace + fecha + worker, con representación estable opaca | API; único dentro del contrato. No usar como permiso. |
| `workplace_id` | entero según fuente, NO | Derivado/contexto; `op_rh_localidades.id` | Centro de trabajo canónico | API lo resuelve; Portal3 valida ámbito servidor. Nunca mapear por igualdad con estación física. |
| `physical_station_ref` | string/ID nullable | Original/procedencia; dispositivo/relación histórica | Referencia de estación/dispositivo asociada al evento, cuando se pueda probar | API asigna desde relación autoritativa; ausente/ambiguo no se sustituye con workplace. |
| `work_date` | DATE, NO | Derivado; `ScheduledShift`/resolución jornada | Fecha laboral local, no mera fecha UTC/calendario | Motor autorizado; jornada nocturna respeta fecha de inicio/regla congelada. |
| `timezone_iana` | string, SÍ | Derivado; autoridad efectiva y snapshot de jornada | Zona IANA usada para mostrar/interpretar tiempo | Nulo si no resoluble; no aplicar fallback. No recalcular en Portal3. |
| `worker_id` | ID canónico, NO para fila resuelta | Contexto; `op_rh_personal.id` | Identidad canónica | API resuelve. No usar nombre, email, cuenta Portal, número de empleado o fuzzy match como llave. Pendiente identidad no resuelta queda fuera de filas por trabajador y en estado agregado de incompletitud con referencia segura. |
| `worker_display_name` | string, SÍ | Presentación; padrón de personal | Etiqueta del colaborador | Fuente de personal autorizada; no utilizar para vincular ni autorizar. Minimizar datos personales. |
| `source_identity_ref` | string, SÍ | Original/procedencia; identidad dispositivo histórica | Referencia opaca a la resolución y su vigencia | API; no exponer referencia externa sensible si no se aprueba. |
| `schedule_status` | enum/string, NO | Derivado; resolución de horario/jornada | `SCHEDULED`, `DAY_OFF`, `UNSCHEDULED`, `UNKNOWN` u otros estados vigentes del contrato | Servicio de horarios. No inferir `DAY_OFF` por ausencia de asignación. |
| `schedule_ref` | string, SÍ | Derivado; jornada programada/asignación/plantilla | Referencia a detalle de horario efectivo | API resuelve historial, versión, segmentos y fecha. |
| `schedule_summary` | objeto/string estructurado, SÍ | Derivado; segmentos de turno efectivos | Horario que la pantalla presenta | Sólo producir desde horario efectivo; estructura y etiquetas precisan aprobación. No reconstruir desde template en Portal3. |
| `snapshot_ref` | string, SÍ | Metadato/procedencia; snapshot Level 1 | ID público de snapshot origen | API. Requerido si la cobertura del resultado depende de Level 1. |
| `snapshot_version` | string/entero, SÍ | Metadato; snapshot Level 1 | Versión/fingerprint del corte | API; conservar junto a estado y membresía/cobertura. |
| `snapshot_generated_at_utc` | timestamp UTC, SÍ | Metadato; snapshot Level 1 | Momento de generación | API. No equivale a frescura actual sin política aprobada. |
| `snapshot_membership_status` | enum, NO | Derivado/metadato; membresía Level 1 | `COMPLETE`, `INCOMPLETE`, `UNKNOWN`, `NOT_APPLICABLE` | API según membresía completa/orden/cobertura. Página parcial nunca equivale a snapshot completo. |
| `snapshot_expected_count` / `snapshot_returned_count` | enteros no negativos, SÍ | Metadato; snapshot | Cobertura y detección de lectura truncada | API/lector con regla exacta por aprobar; discrepancia fuerza incompleto. |
| `continuity_status` | enum, NO | Derivado; evaluación Level 2 | Mantener vocabulario contractual: `VERIFIED`, `INCOMPLETE`, `UNKNOWN` (y subestados si el contrato fuente lo exige) | API. No traducir ausente a VERIFIED ni a cero eventos. |
| `continuity_ref` | string, SÍ | Metadato/procedencia; evaluación Level 2 | Referencia a ventana/generación evaluada | API. |
| `device_generation_ref` | string, SÍ | Original/procedencia; generación del dispositivo | Fuente de secuencias comparable | API. Puede diferir entre dispositivos; no combinar secuencias sin contrato. |
| `sequence_first` / `sequence_last` | entero unsigned, SÍ | Original/procedencia; eventos de origen | Cobertura observada de secuencia en la ventana | API. Nulos no significan continuidad completa. |
| `accepted_through_sequence` | entero unsigned, SÍ | Derivado; watermark Level 2 | Última secuencia confirmada por contrato | Fuente Level 2/API. No calcular Portal3. |
| `pending_event_count` | entero no negativo, SÍ | Derivado; evidencia de cola/Level 2 | Eventos pendientes de ACK, si el contrato expone dato comparable | Fuente Level 2; si no está disponible, continuidad puede ser UNKNOWN. |
| `detected_gap_count` | entero no negativo, SÍ | Derivado; evaluación Level 2 | Brechas registradas, no interpretación de ausencia laboral | API; no convertir brechas en faltas. |
| `window_evaluated_at_utc` | timestamp UTC, SÍ | Metadato/derivado; Level 2 | Momento de evaluación de ventana | API. Su antigüedad no se clasifica sin SLA aprobado. |
| `punch_collection_ref` | string, NO | Referencia a colección completa | Acceso a todos los eventos de la jornada/snapshot, no sólo cuatro slots | API; colección paginada completa y verificable. |
| `slot_1_event_ref` … `slot_4_event_ref` | string, SÍ | Derivado; asociación P1 | Referencia a evento en posición `segment_ordinal`/`pairing_role` | P1/API: slot 1 ENTRY ordinal 1; slot 2 EXIT ordinal 1; slot 3 ENTRY ordinal 2; slot 4 EXIT ordinal 2. Sin nueva regla de pareo. |
| `slot_1_occurred_at_utc` … `slot_4_occurred_at_utc` | timestamp UTC, SÍ | Original mostrado mediante asociación derivada | Hora original seleccionada para slot | API; hueco null sólo significa “no se asignó evento a esta posición”. UI usa etiqueta neutral y no concluye ausencia. |
| `slot_1_state` … `slot_4_state` | enum, NO por slot | Derivado; estado evento/asociación | `AVAILABLE`, `NO_EVENT_ASSIGNED`, `PENDING_EVALUATION`, `INVALID`, `UNVERIFIABLE`, `RESULT_INCOMPLETE` | API define precisión y precedencia de estado; valores distinguibles. Nulo no equivale a `NO_EVENT_ASSIGNED`. |
| `extra_event_count` | entero no negativo, SÍ | Derivado; colección P1 | Eventos adicionales/no asignados a los cuatro slots | API; no esconderlos ni truncar. Nulo si colección incompleta. |
| `pairing_ref` / `pairing_revision` | string/entero, SÍ | Derivado; pareo P1 | Procedencia y revisión de emparejamiento | API; referencia estable a versión seleccionada. |
| `journey_ref` / `calculation_revision` | string/entero, SÍ | Derivado; P2 | Jornada y revisión calculada | API; no recomputar ni seleccionar revisión unilateralmente. |
| `evaluation_ref` / `evaluation_version` | string/entero, SÍ | Administrativo; P3-G | Evaluación administrativa y versión | API; nulo significa evaluación no disponible/no aplicable sólo con estado explícito. |
| `evaluation_status` | enum/string, NO | Administrativo; evaluación autorizada | Estado contractual de evaluación; incluye `NOT_AVAILABLE`, `PENDING`, `REVIEW_REQUIRED`, `DATA_INCOMPLETE` donde el motor lo emita | API. No crear `FALTA` ni derivarla; `FALTA` sólo con autorización humana bajo P3-G. |
| `incidence_refs` | lista de refs, NO (vacía válida) | Administrativo; incidencias y asociaciones a evaluación | Referencias a incidencia/revisión aplicable | API resuelve pertenencia/revisión. Lista vacía no demuestra que la evaluación está completa. |
| `row_completeness_status` | enum, NO | Metadato; agregación contractual | `COMPLETE`, `INCOMPLETE`, `UNKNOWN` | API productor; reglas dependen de snapshot, continuidad y fuentes requeridas. |
| `incompleteness_reasons` | lista enum, NO (vacía si completa) | Metadato | Códigos explícitos de fuente faltante, pendiente, truncada o no verificable | API. Sin textos internos, SQL ni secretos. |
| `source_provenance` | objeto estructurado, NO | Metadato | Identifica refs/versions de snapshot, eventos, jornada, pareo y evaluación realmente usados | API. No incluir HMAC secreto, credencial o template biométrico. |
| `evaluation_updated_at_utc` | timestamp UTC, SÍ | Metadato/administrativo | Instante de creación/revisión de evaluación vigente referenciada | API. No afirmar que toda fuente fue actualizada a ese instante. |
| `projection_published_at_utc` | timestamp UTC, NO | Metadato | Instante de publicación de esta revisión de proyección | API. Sólo indicador temporal; no implica actualidad contractual. |
| `projection_revision` | entero/UUID monotónico o único, NO | Metadato | Revisión atómica/versionada del resultado lógico | API; esquema de orden/versionado requiere aprobación. Nunca sobrescribir historial sin linaje. |

La cardinalidad de una fila por `workplace_id + work_date + worker_id + revisión seleccionada` es una propuesta pendiente. Debe resolverse cómo representar múltiples jornadas/trabajos del mismo trabajador y día en distintos workplace sin colapsarlos.

## 7. Cuatro marcaciones y estados

P3-H V1.1 fija el mapeo compacto:

| Posición | Regla autoritativa | Etiqueta |
|---|---|---|
| 1 | `segment_ordinal=1`, `pairing_role=ENTRY` | Entrada 1 |
| 2 | `segment_ordinal=1`, `pairing_role=EXIT` | Salida 1 |
| 3 | `segment_ordinal=2`, `pairing_role=ENTRY` | Entrada 2 |
| 4 | `segment_ordinal=2`, `pairing_role=EXIT` | Salida 2 |

Los roles continúan siendo `ENTRY`, `EXIT`, `SURPLUS`, `UNMATCHED`; no se introduce semántica de comida. Soportar 5+ eventos y segmentos 3+ mediante colección/detalle completo. No aplicar “primeras cuatro” como fallback, no eliminar extras, no modificar reglas P1. Un slot vacío nunca significa `SIN MARCACIÓN`, `FALTA`, ausencia ni error. Evento inválido/no verificable conserva referencia y estado que el contrato fuente autorice; no se presenta como un evento válido ni se borra.

Los estados `PENDING_EVALUATION`, `INVALID` y `UNVERIFIABLE` son nombres **propuestos** de estado de presentación, no vocabulario congelado de API. Su mapeo exacto al estado de procesamiento/integridad requiere confirmación de contrato antes de implementar. `RESULT_INCOMPLETE` afecta la conclusión/posición cuando el cálculo necesario no puede declararse completo; no inventa una marcación.

## 8. Identidad, dispositivo, estación y localidad

Identidad canónica de trabajador: `op_rh_personal.id`, según P3-G. La referencia externa del dispositivo se resuelve mediante identidad histórica por dispositivo y vigencia; nunca se usa el valor externo, nombre, correo, `no_colaborador` o `tb_usuarios.id` como reemplazo de una identidad canónica sin resolver.

Workplace Checador: `op_rh_localidades.id`. Portal3 `tb_estaciones.id` es otro espacio de claves. P3-G documenta puente mediante número de estación único válido (`tb_estaciones.id → numlista → op_rh_localidades.id`), mientras que la autoridad completa histórica dispositivo→estación física→workplace requiere verificar cardinalidad, vigencias, reasignaciones y fuente que gobierna el mapeo. La igualdad numérica está prohibida.

**Contrato propuesto:** API publica `workplace_id` resuelto, la referencia a la asociación física histórica y una condición `VERIFIED`/`UNKNOWN`/`AMBIGUOUS` (vocabulario sujeto a aprobación). Si mapeo ausente o ambiguo, no devuelve datos dentro de un workplace inventado; la fila no es autorizable y el conjunto se reporta incompleto. Portal3 aplica su alcance autorizado en cada consulta y nunca confía en el workplace enviado desde navegador como permiso.

Opciones por aprobar: (1) una relación efectiva-datada gobernada y publicada por API; (2) reutilizar puente P3-G `numlista` sólo después de confirmar que aplica igualmente a cada dispositivo/evento y su historia. No autorizar conversión por igualdad numérica ni usar IDs legacy descifrados como identidad canónica.

## 9. Snapshots Level 1

Level 1 es el corte/MVCC de pertenencia de eventos y no una lectura oportunista de filas crudas. Para reproducirlo, la propuesta requiere `snapshot_ref`, versión o fingerprint, generación, membresía total, orden/cobertura, estado de completitud y procedencia de resultados que consumieron esa membresía.

Si el origen está paginado, se requiere recuperar todas las páginas y verificar el conjunto contra conteo/membresía/fin de cursor del contrato. Timeout, página faltante, membresía no retenida, ref desconocida, versión incompatible o discrepancia de conteo producen `INCOMPLETE` o `UNKNOWN`, nunca `COMPLETE`. No descartar eventos por el límite de página ni equiparar conteo crudo parcial con snapshot completo. La retención de snapshots/membresía y acceso de Portal3 deben definirse antes de implementar.

**Detección de desalineación propuesta:** el productor publica el identificador/version exactos de snapshot consumido y un token de cobertura (por ejemplo, conteo y huella estable contractual). El consumidor verifica identidad y cardinalidad del snapshot referenciado, no recalcula su contenido semántico. Forma del token, persistencia y retención quedan pendientes.

## 10. Continuidad Level 2

Level 2 documenta identidad/generación de dispositivo, secuencias, `accepted_through_sequence`, eventos pendientes, brechas, cobertura operacional y evaluación de ventana. La vista debería incluir estado agregado de ventana y referencia; dimensiones y watermarks sólo si la proyección los necesita para explicar su estado. La lógica de secuencia y evaluación no se duplica.

`VERIFIED`, `INCOMPLETE` y `UNKNOWN` se mantienen distintos. UNKNOWN incluye evidencia no suficiente; INCOMPLETE indica brecha/pending/evaluación incompleta conforme al contrato; VERIFIED sólo cuando las condiciones fuente Level 2 aplican para ventana, dispositivo(s), generación(es), cobertura y atribución temporal. Falta de telemetría o campo nulo no puede convertirse en VERIFIED. Las marcaciones históricas no secuenciadas permanecen Level 2 desconocido según contrato.

Una brecha no equivale a día sin asistencia. Cuando continuidad no esté disponible se emite `UNKNOWN`; los datos parciales pueden mostrarse como evidencia con `row_completeness_status=INCOMPLETE/UNKNOWN`, sin conclusión negativa sobre trabajador.

## 11. Horarios y jornadas

Consumir el resultado de resolución efectiva y jornada materializada/autoritativa, con referencias de asignación/plantilla y vigencias, workplace, fecha laboral y zona horaria histórica. `SCHEDULED`, `DAY_OFF`, `UNSCHEDULED`, `UNKNOWN` siguen siendo semánticas distintas. No inferir turno de `op_rh_personal_horario`, del horario actual o de un template sin resolución efectiva. `timezone_local` de jornada materializada conserva el valor usado; timezone de dispositivo es diagnóstico, no autoridad. La autoridad de timezone efectiva-datada exige comprobar la configuración efectiva para esa fecha; sin ella, mostrar no evaluable/unknown.

La asignación de `ENTRY`/`EXIT` y ordinal corresponde a P1; cálculos de jornada a P2; incidencia/evaluación a P3-G. La pantalla consume refs/versiones exactas. Horario faltante no es falta, y una jornada `UNKNOWN` no se presenta como `DAY_OFF`.

## 12. Estados de la proyección

Estados propuestos para conjunto/lectura (son distintos de estados de slot y evaluación):

| Estado | Significado/condición |
|---|---|
| `COMPLETE` | Todas las fuentes obligatorias, snapshot/membresía y Level 2 alcanzan criterios contractuales; revisión y cobertura consistentes. |
| `INCOMPLETE` | Falta evidencia requerida, existe pendiente/brecha, truncamiento o dependencia sin resolver. Parciales pueden mostrarse con advertencia. |
| `UNKNOWN` | No hay datos suficientes para decidir completitud, mapeo o estado de origen. |
| `STALE` | Sólo tras aprobar una regla temporal/SLA versionada; edad excedida no se declara arbitrariamente. Puede coexistir con `COMPLETE` del corte conocido. |
| `UNAVAILABLE` | Fuente de lectura no accesible; no se devuelve como conjunto vacío. |
| `NO_RECORDS_VERIFIED` | Conjunto completo cuya cobertura autoritativa confirma cero eventos; no equivale a evaluación `FALTA`. |

La salida distingue `no rows`, `partial rows`, error de conexión, no autorizado, estado administrativo y ausencia de evaluación. `NO_RECORDS_VERIFIED` requiere cobertura/snapshot y continuidad aplicables y nunca genera automáticamente `FALTA` bajo P3-G.

## 13. Actualización, consistencia y versiones

| Estrategia | Ventajas | Riesgos y controles requeridos |
|---|---|---|
| A. Por eventos | Baja latencia potencial; actualiza al cambiar evento o resultado | Reintentos/orden, eventos duplicados, transacciones parciales y reprocesamiento concurrente; exige idempotencia, outbox/transacción y publicación por revisión completa. |
| B. Proceso controlado | Reconciliación/reproceso explícitos y operación acotada; recuperable por lote | Latencia mayor, colas pendientes y lotes incompletos; requiere watermark, checkpoint, reintento y reportar ventana no actualizada como incompleta. |
| C. Híbrida | Actualización por eventos más conciliación completa controlada | Más componentes; la conciliación debe detectar pérdidas sin publicar revisiones regresivas. |

**Recomendación para evaluar:** híbrida. Cada disparo marca una clave lógica como pendiente; el productor genera una revisión aislada desde snapshot/fuentes identificadas y la publica atómicamente sólo después de verificar consistencia. Reintentos idempotentes usan clave y huella de insumos. Concurrencia se serializa por clave lógica o control optimista de revisión; conflictos reintentan sin mezclar source revisions. Conciliación periódica compara watermark de eventos y revisiones fuente. La selección temporal, cron, umbral de frescura y outbox son decisiones futuras, no implementación autorizada.

Conservar publicaciones previas append-only o con historial explícito; ninguna corrección de horario, pareo, cálculo, incidencia o evaluación sobrescribe silenciosamente el resultado consumido. `projection_published_at_utc`/revisión describe publicación, no frescura. `STALE` sólo puede emitirse con SLA aprobado y medido contra watermark autoritativo.

## 14. Modelo conceptual de persistencia SQL (no DDL)

Entidades propuestas, nombres no aprobados:

1. **Projection batch/revision:** revisión, versión de contrato, estado de generación, timestamps, idempotency key, hash de entradas y resultado de validación.
2. **Daily attendance projection:** clave workplace/fecha/worker y revisión; referencias a horario, snapshot, Level 2, Journey, evaluación; estado completitud y campos de presentación.
3. **Projection event membership:** relación de la proyección con eventos originales/roles/ordinal; conserva todos los eventos y asociación de origen, no sólo cuatro slots.
4. **Projection incidence membership:** referencias a incidencia y revisión administrativa aplicables.

Relaciones conceptuales: un batch contiene muchas filas; una fila referencia un snapshot y evaluación/revisión concretos, una o más fuentes de continuidad cuando haya varios dispositivos y 0..N eventos/incidencias. FK `RESTRICT` o equivalente para no destruir linaje; append-only de revisiones publicadas. No crear tabla duplicada de hechos, ni evento sintético. La opción alternativa de una vista de lectura sobre resultados persistidos debe demostrar snapshot consistente, estabilidad contractual, permisos mínimos y versionado; si no los satisface, no sustituye la proyección.

Índices candidatos (pendientes de esquema/carga): clave de consulta `(workplace_id, work_date, worker_id, projection_revision)`; lookup por `(workplace_id, work_date, state)`; unicidad de clave lógica y revisión; índices de referencia a snapshot/journey/evaluation; índice de orden estable de membresía `(projection_revision, segment_ordinal, event_order)`. Tipos, cardinalidades, particionado, collation, PK/FK y selección de índices requieren revisión del esquema activo y EXPLAIN controlado en etapa aprobada.

No se entrega DDL ni SQL ejecutable. No se puede decidir si reutilizar tablas/vistas existentes hasta inspeccionar el esquema desplegado, triggers, índices, retención y capacidades de las tablas candidatas.

## 15. Acceso de Portal3 y seguridad

Diseño de acceso propuesto: conexión Eloquent aparte por entorno, backend únicamente, identidad SQL dedicada de sólo lectura, TLS, permisos `SELECT` únicamente sobre vista/proyección autorizada, consultas parametrizadas y límites por fecha/paginación. Sin acceso a tablas operativas completas, secretos HMAC, credenciales de dispositivo, templates biométricos ni permisos DML/DDL. No modificar `.env` ni copiar secretos en el diseño.

Portal3 autentica usuario y valida permiso funcional y lista vigente de workplaces en servidor antes de consultar. Filtros `workplace_id`, fecha y worker son parámetros no confiables hasta ser validados. Toda ruta conserva el aislamiento incluso con IDs adivinados; responder sin revelar existencia de otras estaciones. Errores no filtran SQL, host ni credenciales. Navegador sólo habla con backend Portal3.

Topologías pendientes:

| Topología | Ventaja | Riesgo/condición |
|---|---|---|
| Base primaria compartida, privilegio SELECT acotado | Frescura directa y operación simple | Acopla carga/ disponibilidad; aislamiento de cuenta/esquema y controles de lectura obligatorios. |
| Réplica read-only | Aísla carga de escritura | Replicación atrasada; mostrar watermark/revisión replica y tratar retraso con política aprobada. |
| Esquema/almacén dedicado de proyección | Frontera y permisos más estrechos | Pipeline adicional y consistencia eventual; ownership, sincronización y recuperación definidos. |

No se asume que las aplicaciones compartan servidor/esquema. Infraestructura debe aprobar conectividad, TLS, segmentación, secretos, réplica y continuidad operativa antes de implementación.

## 16. Contratos afectados: enmiendas para aprobación

| Contrato | Regla vigente verificada | Cambio propuesto | Riesgo | Aprobación |
|---|---|---|---|---|
| Architecture Freeze V1.0 | Congelado; define roles, hechos/resultados y prohíbe implementación no autorizada. No define proyección SQL para Portal3. | Enmienda versionada que permita a Portal3 leer sólo proyección aprobada, preservando API como dueña de cálculos/resultados. | Nueva frontera de datos, acoplamiento e impacto seguridad. | Responsable de arquitectura/API/Lector; no editar freeze unilateralmente. |
| Portal3 P3-H V1.1 | Presentación consume payload contractual API; no cálculo local; slots neutrales y colección completa. | Extender canal de lectura para el mismo resultado versionado desde proyección, con semántica idéntica y compatibilidad HTTP/SQL. | Divergencia entre payload y SQL. | Dueños API y Portal3; versión nueva/adenda. |
| Portal3 P3-G V1.0 | Dominio canónico V2, ACL/scope en backend; operaciones P3-G por Portal3 backend→API. | Permitir exclusivamente lectura de evaluación/incidencias incluidas en proyección; mantener escritura, autorización, PIN y comandos por API. | Contradicción con frontera exclusiva actual de operaciones/lectura P3-G. | Aprobación explícita propietarios P3-G/API/seguridad; no presumirla por “Option B”. |
| Level 1 Snapshot V2.4 | Snapshot y recuperación completa, paginada, alcance autorizado. | Especificar representación SQL de referencia/membresía/version y prueba de integridad de conjunto. | Lectura incompleta o mezcla de cortes. | Dueño Level 1 y arquitectura. |
| Level 2 V1.1 | Watermark y estados de continuidad; UNKNOWN/INCOMPLETE no confirman cero eventos. | Publicar estado agregado y referencias suficientes sin duplicar cálculo. | Falsa completitud/ausencia. | Dueño Level 2 y API/Lector. |
| Scheduling/Journey/P3-H payload | API resuelve horario, pareo, cálculo, zona horaria y versiones; Portal3 no recalcula. | Exponer resultados/refs/revisiones en la proyección con serialización semántica común. | Selección de revisión equivocada; estructura candidata no disponible. | Dueños de contratos de horarios, P1/P2, timezone, P3-H. |

La tabla registra compatibilidad a solicitar; ninguna enmienda aquí se considera aprobada. El antecedente Portal3 también la trata como pendiente. Conflictos de transporte de P3-G deben resolverse mediante decisión formal antes de implementar; no se reinterpretan unilateralmente.

## 17. Dependencias

- Aprobación de Opción B **para proyección de lectura** y de las enmiendas de §16.
- Verificación read-only de esquema activo y correlación de versiones migración/runtime.
- Contrato autoritativo, efectivo-datado y auditable de estación física→dispositivo→workplace, junto con identidad histórica.
- Confirmar retención/completitud de snapshots y evaluaciones Level 2 para fechas consultadas.
- Confirmar disponibilidad/runtime y versión vigente de ScheduledShift, P1, P2 y P3-G.
- Decidir topología SQL, frescura, retención, paginación, versión, historial y política de reproceso.
- Definir ACL, esquema de salida, clasificación de datos, propietario operativo, monitoreo y manejo de incidentes.
- Coordinación API–Portal3 y documento compartido si cambia el estado global; no actualizar status compartido unilateralmente.

## 18. Riesgos

Mapeo institucional equivocado; exposición cross-workplace; datos parciales presentados como concluyentes; estado viejo confundido con actual; desalineación entre HTTP y SQL; joins que mezclan revisiones; pérdida de historial por upsert destructivo; confusión entre slot vacío, evento inválido y falta; permisos SQL demasiado amplios; referencia de identidad externa expuesta; expectativas de runtime basadas sólo en modelos documentados. Mitigaciones contractuales: fail-closed, referencias/versiones, publicación atómica, estados separados, permiso sólo a proyección y pruebas negativas de alcance.

## 19. Plan de aceptación futuro (no ejecutado)

Fixtures aislados: cuatro posiciones y más de cuatro eventos; slots vacíos/pendientes; evento inválido/no verificable; worker sin identidad resuelta; múltiples workplaces; sin registros con y sin cobertura completa; horarios históricos/nocturnos/divididos; `DAY_OFF`, `UNSCHEDULED`, `UNKNOWN`; evaluaciones e incidencias con revisiones; corrección administrativa; snapshot incompleto, página perdida y conteo divergente; Level 2 VERIFIED/INCOMPLETE/UNKNOWN, evento pendiente, gap y generación nueva; reintento idempotente y concurrencia; revisión vieja/nueva sin pérdida de historia.

Datos persistidos reales, en un ambiente autorizado futuro: reconciliar proyección con refs exactas de marcaciones/snapshot/P1/P2/P3-G; identity y mapping vigentes históricos; watermark/continuidad; versiones, tiempos y source freshness.

Seguridad: usuario permitido/no permitido, estación dentro/fuera de scope, IDs manipulados, aislamiento cruzado, conexión read-only (intentos DML/DDL denegados), credenciales no disponibles al navegador, consulta parametrizada, errores sin secretos. Portal3: filtros, lectura backend, render de cuatro slots neutrales y extras, detalle/jornada, incompleto, vacío verificado, no disponible y versión incompatible. Fixtures y datos reales deben informarse por separado. No correr estas pruebas en la fase de diseño.

## 20. Decisiones pendientes

1. ¿Aprobar proyección SQL versionada como canal adicional y formalizar enmiendas de contratos?
2. ¿Quién es dueño/aprobador de contrato, productor, esquema y seguridad?
3. ¿Cuál relación efectiva y autoritativa resuelve estación/dispositivo/workplace y worker?
4. ¿Qué topología (primary, replica, store dedicado), retención y SLA de frescura se autorizan?
5. ¿Qué criterios deterministas hacen `COMPLETE`, `STALE` y `NO_RECORDS_VERIFIED` para cada fuente?
6. ¿Se proyectan incidencias/evaluaciones, o esas lecturas siguen por HTTP API bajo P3-G?
7. ¿Cómo se versiona y retiene el historial de proyección y cómo se selecciona revisión vigente sin borrar referencias históricas?
8. ¿Cuál es el esquema de compatibilidad, cardinalidad, paginación y referencia pública?

## 21. Recomendación técnica final

Avanzar sólo a revisión/aprobación del diseño de una proyección dedicada, versionada, con historial y propiedad de API V2, consumida por una conexión SQL read-only desde backend Portal3. Para consistencia, evaluar publicación híbrida por evento más conciliación controlada, publicando únicamente revisiones verificadas y sin actualizar destructivamente resultados previos. Conservar todas las responsabilidades de API y los límites de P3-H/P3-G/Level 1/Level 2.

Hasta que se resuelvan las decisiones anteriores y se aprueben enmiendas contractuales, continúan vigentes los canales actuales y **no se autoriza implementación**. El documento propone; no declara aprobación ni existencia de tablas o proyección.

## Referencias consultadas

- API: `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md` (FROZEN).
- API: `docs/status/CHECADOR_V2_IMPLEMENTATION_STATUS.md` y `docs/planning/CHECADOR_V2_MASTER_SCHEDULE_2026.md`.
- API: `docs/v2/PUNCH_EVENTS_INGESTION_API.md`, `docs/v2/PUNCH_EVENTS_SNAPSHOT_READ_API_CONTRACT_V2.4_LEVEL1.md`.
- API: `docs/architecture/CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md`, `docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md`.
- API: contratos Journey P1/P2, modelos físicos de pairing, calculation y shift scheduling; `docs/architecture/CHECADOR_V2_WORKPLACE_TIMEZONE_AUTHORITY_ADDENDUM_V1.0.md`.
- API: `docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.1.md`.
- Portal3: `docs/architecture/CHECADOR_V2_PORTAL3_DIRECT_DATABASE_READ_PROPOSAL_V1.0.md` (archivo local no rastreado preexistente, consultado sin modificar).
- Portal3: P3-H Presentation / Projection V1.1 y P3-G Incidence / Administrative Evaluation V1.0 (ambos congelados).

## Estado de esta actividad

`STATUS: DRAFT_NOT_APPROVED`  
`IMPLEMENTATION_AUTHORIZED: NO`  
`PERSISTED_PROJECTION_EXISTS: NOT_VERIFIED; no projection identified in reviewed documents`  
`SCHEMA_VERIFICATION_REQUIRED: YES`  
`ARCHITECTURE_FREEZE_CHANGED: NO`
