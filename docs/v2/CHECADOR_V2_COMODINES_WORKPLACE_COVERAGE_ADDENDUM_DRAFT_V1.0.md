# Checador V2 — Coberturas temporales de Workplace por Rol de Comodines

**Estado:** `DRAFT — PENDIENTE DE APROBACIÓN CONTRACTUAL CRUZADA`
**Versión propuesta:** 1.0
**Aplica a:** Portal3 y API V2
**Complementa, sólo tras aprobación:** Shift Scheduling Service & API Contract V1.0, su Authorization Addendum, J1/J2 y M03/M04 Addendum V1.0
**No modifica:** texto de Architecture Freeze V1.0, Lector, PunchEvents, nómina ni reglas normales M03/M04
**Implementación autorizada:** NO
**Migración ejecutada:** NO

## 1. Propósito y reglas de negocio aprobadas

Este addendum propone una relación de cobertura temporal, versionada y autorizada, para que una cobertura de Rol de Comodines pueda aportar contexto de Workplace y programación efectiva sin cambiar la adscripción habitual del trabajador. Al aprobarse, complementaría de forma estrictamente acotada los contratos de API Shift Scheduling, autorización de programación, M03/M04 y J1/J2 citados abajo; no los modifica antes de la aprobación.

El responsable confirmó expresamente estas reglas el 2026-11-09:

1. **Autoridad:** Dirección de Operaciones aprueba las coberturas entre Workplaces. Encargados no las aprueban por cuenta propia. Una eventual capacidad de solicitar debe existir como permiso independiente y no se concede automáticamente.
2. **Precedencia:** una cobertura vigente, aprobada y no cancelada prevalece temporalmente sobre el horario habitual en las fechas laborales cubiertas. Al acabar, se vuelve a resolver el horario habitual que siga vigente. No se reescriben jornadas históricas consolidadas; requieren un procedimiento específico.
3. **Adscripción:** la cobertura no cierra, sustituye ni crea una adscripción habitual. La fuente versionada de adscripción permanece intacta.

Estas reglas de negocio están aprobadas; el presente contrato técnico todavía requiere aprobación cruzada. Este documento no concede permisos ni autoriza código, DDL o cambios de datos.

## 2. Evidencia de contratos y estado del código

### 2.1 Contratos aprobados

- **Architecture Freeze V1.0 §17:** requiere conceptualmente `WorkerWorkplaceAssignment` con historia para cambio de centro, cobertura, asignaciones temporales y trabajo en múltiples centros. El diseño físico queda pendiente. §§14 y 18 separan asignación/turno programado del Motor de Jornada; §37 deja pendiente la política general de checadas en otro centro.
- **J1 canónico §§2, 4–9, 13–20:** separa PunchEvent, horario efectivo, Journey e incidencia; fija identidad `op_rh_personal.id`, Workspace canónico en `op_rh_localidades.id`, Journey `ON_DEMAND`, delegación server-to-server y alcance de localidad. J1 §8 deja pendiente la precedencia general de programación especial; este addendum propone una regla específica para cobertura V2 y no cambia la programación especial Legacy.
- **J2 V1.0 §§2–5 y 11:** exige horario bitemporal, timezone IANA de Workplace, descanso explícito y resultado neutral ante evidencia insuficiente. §2.3 excluye la programación especial Legacy del resolver de fase 1; esa exclusión permanece. La ausencia no equivale a descanso ni falta.
- **M03/M04 Addendum V1.0 §§3–7:** define `HABITUAL`/`TEMPORAL` por trabajador, Workplace, weekday e intervalo; exige membresía canónica completa y restringe edición al alcance efectivo del actor. No define cobertura entre Workplaces ni una aprobación de Dirección para esa operación.
- **Shift Scheduling Service & API Contract V1.0 §§1–6:** ya contiene templates/versions, assignments temporales, resolución por trabajador + Workplace + fecha, intervalos semiabiertos y soporte multi-Workplace. El permiso estándar y su resolver no otorgan a un Encargado alcance entre localidades.
- **Shift Scheduling Authorization Addendum V1.0 §§2–5:** `checador.programacion.editar` es station-scoped. Una cobertura no cambia el alcance administrativo del Encargado.

### 2.2 Alcance normativo que el nuevo addendum suplementaría

Sólo tras su aprobación, se propone complementar sin reescribir los textos fuente:

1. **Shift Scheduling Service & API Contract §§4.2, 4.3 y 6.2:** reconocer `coverage_version_id` como fundamento específico y verificable para las asignaciones TEMPORAL del Workplace de cobertura y permitir resolución opt-in `WORKER_EFFECTIVE`.
2. **Shift Scheduling Authorization Addendum §§2–5:** añadir operaciones independientes de solicitud/aprobación/cancelación y su doble scope origen/destino; el permiso ordinario `programacion.editar` sigue home-station-scoped.
3. **M03/M04 Addendum §§3, 4, 5 y 6:** mantener intacta la regla de membresía completa para todas las asignaciones normales; excepción limitada únicamente a filas `TEMPORAL` creadas por API dentro de una cobertura `AUTHORIZED` exacta, vinculadas a su versión y con home-membership acreditada. Nunca se crea membership de destino ni cambia el home.
4. **J1 §8 y J2 §§2.3 y 3–5:** incorporar esta fuente versionada V2 con la precedencia aprobada en §1. La exclusión de programación especial Legacy de J2 §2.3 permanece vigente.

El cambio físico requerido en el modelo congelado Shift Scheduling Physical Data Model V1.0, si se aprueba, debe documentarse como addendum de esquema antes de DDL; este documento no modifica el freeze ni hace esa extensión efectiva.

### 2.3 Código y esquema observados

- Portal3 ya tiene el módulo y ruta institucional Rol de Comodines, controladores/servicios/modelos `RhRolComodines` y `RhComodinesDia`, vista y JavaScript. El registro existente describe periodo, centro/estación, trabajador y día, pero no conserva una asignación canónica aprobada ni el enlace a una versión de plantilla.
- Los modelos y fixtures existentes muestran `op_rh_rol_comodines` con `id_estacion`, `status`, `fecha_inicio`, `fecha_fin`, y `op_rh_comodines_dia` con `id_reporte`, `id_usuario`, `id_personal` (cuando está disponible), `id_estacion` y `dia`. `id_estacion` de tablas Legacy no es prueba de `op_rh_localidades.id`; requiere el puente `numlista` único y válido.
- El historial canónico de membresía `op_rh_personal_localidad_version` conserva `worker_id`, `workplace_id`, vigencias UTC, `recorded_at_utc`, actor, motivo y supersesión. No contiene un tipo de relación que identifique por sí mismo una cobertura laboral temporal distinta de la membresía.
- La asignación V2 existente conserva `id_personal`, `id_estacion` canónico, `id_plantilla`, `tipo_asignacion`, `dia_semana`, vigencias, actor y registro UTC. No tiene una referencia a la cobertura que la originó.
- La validación API actual de membresía exige cobertura única de la relación canónica para el intervalo de la asignación. No se encontró un writer aprobado de Rol de Comodines en API V2. El resolver de horarios resuelve un Workplace que ya se le entrega; no selecciona la cobertura global del trabajador.
- `op_rh_v2_turnos_audit_log` registra entidades `TEMPLATE` y `ASSIGNMENT`; admitir el ciclo de cobertura requiere ampliar su vocabulario o una estructura equivalente aprobada.

**Verificación de esquema de desarrollo (2026-11-09):** consulta de sólo lectura a `bd_admongas` en MariaDB `11.8.8-MariaDB`, host local `fedora`, puerto `3306`. Las tablas RH, Workplace, estación, usuario, asignación, plantilla, auditoría, jornada y Rol de Comodines consultadas usan InnoDB. No existe `op_rh_v2_worker_workplace_coverage_versions`. Las claves referenciables observadas son: `op_rh_personal.id INT`, `op_rh_localidades.id INT`, `tb_estaciones.id INT`, `tb_usuarios.id INT`, `op_rh_personal_localidad_version.id INT UNSIGNED`, `op_rh_v2_turnos_plantillas.id INT UNSIGNED`, y `op_rh_v2_turnos_asignaciones.id BIGINT UNSIGNED`. La columna física de asignación no contiene todavía vínculo de cobertura; `op_rh_v2_turnos_audit_log.entity_type` sólo permite `TEMPLATE` y `ASSIGNMENT`. La inspección detectó que el primer DDL candidato declaraba `id_home_membership_version BIGINT UNSIGNED`, incompatible con la PK real `INT UNSIGNED`; se corrigió abajo. No se ejecutaron escrituras ni DDL.

## 3. Modelo canónico propuesto

### 3.1 Entidad y campos

Cada solicitud/cobertura es una entidad `WorkerWorkplaceCoverage` versionada y append-only. Su versión conserva como mínimo:

| Campo conceptual | Regla |
|---|---|
| `coverage_uuid`, `revision_no`, `supersedes_id` | Identidad lógica estable, revisión creciente y enlace a la versión anterior. |
| `worker_id` | ID canónico de `op_rh_personal.id`; `id_usuario`, nombre, número de lista y alias no sustituyen esta identidad. |
| `home_workplace_id` | Workplace de adscripción habitual probado por la versión de membresía referenciada. No se cambia al cubrir. |
| `home_membership_version_id` | Versión canónica de adscripción que acredita el origen y cubre el periodo solicitado. Si una transición RH cae dentro del periodo, la solicitud debe dividirse en intervalos y aprobarse por separado. |
| `coverage_workplace_id` | Workplace destino canónico en espacio `op_rh_localidades.id`. No asumir equivalencia con ID físico ni `numlista`. |
| `home_physical_station_id`, `coverage_physical_station_id` | Referencias físicas opcionales sólo como contexto/resolución verificable; nunca son los IDs canónicos del contrato. |
| `effective_from_local_date`, `effective_to_local_date` | Fechas civiles en intervalo semiabierto `[desde, hasta)` del Workplace de cobertura. Ambas son requeridas; fin debe ser posterior a inicio. |
| `timezone_iana` | Se resuelve de la autoridad canónica del Workplace destino. Para este alcance se acepta `America/Mexico_City`; si la configuración del destino no coincide, se rechaza. No se usa timezone del cliente, dispositivo, app ni offset fijo como fallback. |
| `proposed_weekly_schedule` | Patrón explícito para los siete días: por weekday `WORK` con `template_id` + versión inmutable, o `DAY_OFF` explícito. No omitir días ni inferir descanso. |
| `status` | `REQUESTED`, `AUTHORIZED`, `REJECTED` o `CANCELLED`; cada transición es una versión nueva, no un UPDATE histórico. |
| actor y motivos | `requested_by`, `requested_at_utc`, `approved_by`, `approved_at_utc`, actor de transición, motivo de solicitud y motivo de decisión/cancelación. Actor se deriva del JWT validado, nunca del body. |
| `operation_uuid`, `idempotency_key_hash`, `payload_fingerprint` | Idempotencia por operación y detección de clave reutilizada con carga distinta. No guardar claves originales o credenciales. |
| `recorded_at_utc` | Instante de registro asignado por API V2 usando UTC. El tiempo registrado no se confunde con tiempo efectivo. |
| assignment linkage | Cada `ShiftAssignment` V2 generado al aprobar conserva `coverage_version_id`; su template/versión exacta está en la propia asignación mediante la FK canónica al template inmutable. |

El patrón contiene exactamente siete entradas weekday para cada revisión autorizable. El API crea, en la transacción de autorización, las filas `TEMPORAL` de M03 para los días de trabajo y filas temporales `DAY_OFF` para descansos explícitos; así la cobertura activa no cae por ausencia de fila al horario habitual. Las asignaciones se crean sólo al autorizar, no al solicitar.

### 3.2 Relación con adscripción y membresía

La cobertura es una relación efectiva adicional, no una segunda membresía permanente y no una transferencia RH. La asignación de turno al Workplace de destino queda autorizada por la cobertura `AUTHORIZED` que la origina y su vínculo; no se inserta una membresía ficticia ni se modifica `op_rh_personal_localidad_version`.

Al solicitar y al aprobar, API verifica que una única versión de adscripción habitual al Workplace de origen cubra íntegramente el intervalo. Si hay cambio de Workplace dentro del intervalo, exige que RH divida la cobertura de acuerdo con cada membresía efectiva. La validación de la asignación V2 se amplía sólo para admitir como prueba de elegibilidad el `coverage_version_id` aprobado que coincida exactamente en worker/destino/intervalo; fuera de esa relación continúa exigiendo membership ordinaria. No se relajan filtros de localidad.

### 3.3 Resolución efectiva

Se amplía el camino existente de API `GET /api/v2/workers/{id_personal}/effective-schedule` con un modo opt-in `resolution_context=WORKER_EFFECTIVE`. La forma existente que envía explícitamente `id_estacion` conserva su semántica y compatibilidad para consumidores actuales.

En `WORKER_EFFECTIVE`, el servicio API existente, en un único flujo determinista:

1. Valida JWT, permiso de lectura y alcance del actor; resuelve `worker_id` desde identidad canónica.
2. Resuelve membresía habitual única y efectiva en la fecha civil solicitada; si falta o es ambigua, devuelve `UNKNOWN`/`DATA_INCOMPLETE`.
3. Busca una única revisión `AUTHORIZED` de cobertura para ese trabajador y fecha, con target, zona, horario y plantilla válidos. Coberturas solapadas producen conflicto y `UNKNOWN`, nunca un ganador arbitrario.
4. Si la cobertura está autorizada, usa su `coverage_workplace_id` y el patrón explícito del día. `WORK` resuelve la asignación `TEMPORAL` vinculada y su template exacto; `DAY_OFF` devuelve descanso explícito. Si falta el enlace o no coincide worker/periodo/template, devuelve `UNKNOWN`.
5. La cobertura autorizada prevalece sobre el habitual de origen en esa fecha; el resolver no emite simultáneamente dos horarios esperados. Al finalizar el intervalo vuelve a resolver el habitual vigente en el home Workplace.
6. Si no hay cobertura aplicable, el mismo resolver de turnos consulta la programación habitual del Workplace de adscripción. La ausencia de una asignación sigue siendo `UNSCHEDULED`/`UNKNOWN`, no `DAY_OFF` ni `FALTA`.
7. Devuelve procedencia verificable: `coverage_uuid/revision`, estado y actor de aprobación, `home_workplace_id`, `effective_workplace_id`, `home_membership_version_id`, `assignment_id`, template ID/version, fecha local, timezone y segmentos.

Esto amplía el contexto de selección del resolver API actual; no crea un evaluator de turno paralelo en Portal3. J1/J2 requerirán addendum aprobado para incorporar esta fuente V2 y su precedencia. J2 §2.3 continuará excluyendo datos Legacy.

### 3.4 Jornada, marcaciones e incidencias

- **M05 / Asistencia del día:** consume el modo `WORKER_EFFECTIVE` mediante Portal3 backend → API con JWT delegado. Portal3 no determina el Workplace ni calcula el turno. Se presenta la procedencia de cobertura y se falla de forma segura ante `UNKNOWN`.
- **Journey:** sigue `ON_DEMAND`; recibe el horario y Workplace efectivos del resolver API, junto con los PunchEvents raw, timezone y reglas. No se materializa una jornada por guardar una cobertura.
- **PunchEvents/dispositivo:** una cobertura no cambia `id_dispositivo`, identidad externa, hora raw ni `id_estacion_resuelta`. La ubicación física se mantiene conforme al contrato del evento; Freeze §37 conserva su política general pendiente. La cobertura no prueba desde qué dispositivo/centro se marcó.
- **Incidencias/M06:** el registro de cobertura no crea ni resuelve una incidencia. Una incidencia posterior se relaciona al Journey canónico de worker + fecha local + Workplace resultante y conserva su propio historial. Si existe una incidencia/resolución o jornada consolidada en las fechas afectadas, la cobertura retroactiva se rechaza; no se cambia el cálculo histórico silenciosamente.
- **M07/Ajustes administrativos:** permanece sin cambios; la cobertura no es ajuste ni autorización final de asistencia.

## 4. Autoridad, permisos y alcance

### 4.1 Capacidades propuestas, sin concesión

| Capacidad contractual propuesta | Uso | Principal/alcance |
|---|---|---|
| `checador.comodines.leer` | Ver solicitudes/coberturas de localidades autorizadas. | Server scope de Workplace en cada consulta. |
| `checador.comodines.solicitar` | Crear o modificar propuesta pendiente. | Encargado únicamente para trabajador de su Workplace habitual y destino explícito; requiere grant efectivo separado. |
| `checador.comodines.aprobar` | Autorizar o rechazar. | Dirección de Operaciones, y sólo cuando origen y destino están dentro de su alcance efectivo. Ningún Encargado puede ejercerla. |
| `checador.comodines.cancelar` | Cancelar una cobertura futura autorizada. | Dirección de Operaciones con ambos Workplaces en alcance; requiere motivo y nueva revisión. |

Estas claves son nombres de contrato propuestos; no existen grants implícitos por puesto, por módulo RH, por permiso M03 ni por ser Encargado/Dirección. La implementación de grants requiere aprobación de este addendum y una decisión de configuración posterior. Dirección recibe la autoridad de negocio aprobadora aquí aprobada, pero API sólo la materializa cuando el grant específico esté activo.

### 4.2 Reglas de autorización

- El requestor y aprobador se obtienen sólo del JWT validado (`iss=portal3_auth`, `sub=tb_usuarios.id`) y cuenta activa. El payload no puede elegir/forjar actor, posición o localidades.
- API V2 es el límite final y revalida identidad, capacidad, pertenencia de origen, destino, estado activo, template/version y rango en cada operación/read.
- Un Encargado puede solicitar si tiene explícitamente `checador.comodines.solicitar` y sólo desde su Workplace autorizado; no puede aprobar, rechazar ni cancelar. La solicitud no le da lectura administrativa completa ni permiso sobre el Workplace destino.
- Dirección aprueba/rechaza/cancela; debe tener `checador.comodines.aprobar`/`cancelar` y alcance efectivo simultáneo sobre origen y destino. La aprobación de cobertura no concede `checador.programacion.editar` ni permisos administrativos M03 sobre otra localidad.
- Se conserva la precedencia del autorizador de API V2: regla directa USER existente, incluida una revocación/denegación, prevalece sobre POSITION; sólo si no hay regla directa se evalúa el grant de puesto. Un deny o ACL ausente/indisponible niega. Portal3 repite las comprobaciones pero no puede ampliar el resultado de API. No copiar grants de un puesto a usuarios.
- La consulta de Dirección se limita a su allowlist actual; no se convierte en acceso global. IDs físicos se convierten por mapping `numlista` válido, único y activo; IDs de catálogos distintos no se comparan por igualdad numérica.

## 5. Ciclo de vida, conflictos y fechas

### 5.1 Flujo y estados

```text
REQUESTED --Dirección aprueba--> AUTHORIZED
REQUESTED --Dirección rechaza--> REJECTED
AUTHORIZED --Dirección cancela futuro elegible--> CANCELLED
```

Una nueva propuesta cambia worker, Workplace, intervalo, weekdays, template/version o patrón: crea una nueva solicitud lógica/versionada y requiere autorización nueva. No se modifica una versión previamente solicitada o aprobada. Sólo Dirección puede rechazar. Sólo Dirección puede cancelar una cobertura autorizada, con motivo. Si ya inició, sólo puede cerrar el tramo futuro sin cambiar días transcurridos; si el corte afecta un Journey, Incidencia o periodo consolidado, se rechaza y se exige el procedimiento de corrección específico.

La autorización y creación de las asignaciones M03 temporales vinculadas son una sola transacción API. Si cualquiera de las filas, auditorías, restricciones o readbacks obligatorios falla, se revierte toda la autorización. Un reintento idéntico devuelve el estado existente; una misma clave con otro fingerprint devuelve conflicto. Concurrencia serializada por trabajador y por solicitud.

### 5.2 Fechas, timezone, elegibilidad y retroactividad

- El request contiene `[fecha_desde_local, fecha_hasta_local_exclusiva)`; no se infiere `fecha_inicio`/`fecha_fin` Legacy. El UI puede presentar una fecha final inclusiva sólo si API la normaliza y devuelve el límite exclusivo.
- El timezone se valida contra la autoridad del Workplace de destino. En este alcance debe ser `America/Mexico_City`; falta/configuración distinta bloquea. Se resuelve cada fecha laboral del destino con su timezone, incluyendo medianoche, offsets de segmentos y cambios de reloj. No se convierten días civiles a UTC mediante un offset fijo.
- El periodo sólo puede comenzar después de la fecha local actual del Workplace de destino en el momento de aprobar. Solicitudes para fechas ya iniciadas, consolidadas o con Journey/Incidencia persistida se rechazan con error estable. Cualquier excepción de fecha actual o corrección histórica requerirá contrato propio y aprobación específica.
- La membresía habitual de origen debe cubrir el intervalo completo con una sola versión vigente y registrada al tiempo de decisión. Ni el roster actual ni la estación física suplanta esa evidencia.

### 5.3 Duplicados, superposición y colisión de turnos

- Una cobertura lógica se identifica por operación UUID; para el mismo trabajador se rechazan intervalos efectivos superpuestos, incluso si los destinos difieren. El exact retry es idempotente.
- Cada solicitud incluye los siete weekdays explícitos: para cada día, `WORK` con una plantilla activa/version exacta del destino, o `DAY_OFF`. No hay día omitido ni descanso inferido.
- API verifica plantilla y versión exactas, scope GLOBAL o STATION permitido en el destino, segmentos válidos, asignaciones existentes, memberships y otros overrides. Rechaza plantilla archivada/no autorizada, destino incorrecto, varias coberturas, colisión de segmentos horarios, turno incompatible en fecha, mapeo ambiguo o timezone no resuelto.
- Hasta aprobar política empresarial de dobles turnos (Freeze §49 P06), no se permiten dos intervalos de trabajo UTC que se solapen ni una segunda jornada simultánea del mismo trabajador en fecha cubierta. La cobertura reemplaza el esperado de origen conforme a §3.3, no suma automáticamente ambos horarios.
- El horario de descanso se representa con `TEMPORAL` explícito (`id_plantilla = NULL` conforme al contrato vigente). Sin `DAY_OFF` explícito no se trata la ausencia como descanso.

### 5.4 Cancelaciones, transferencias y eventos consolidados

- Cancelar sólo se admite antes del primer día efectivo y antes de crear/materializar/consolidar hechos derivados. API crea una revisión `CANCELLED`, cierra las asignaciones `TEMPORAL` aún futuras asociadas en una transacción y registra auditoría; la adscripción y el habitual originales no cambian.
- Después del inicio, una cancelación nunca altera fechas pasadas. Si hay fechas futuras por cancelar, API crea una nueva revisión con límite efectivo en la primera fecha futura elegible y cierra sólo assignments futuros vinculados; si no puede separar pasado/futuro sin afectar historia, rechaza y requiere procedimiento de corrección aprobado.
- Cambios RH de Workplace que se crucen con el intervalo requieren nueva segmentación y aprobación de Dirección por cada tramo. No se crea cobertura a nombre de un nuevo home retroactivamente.
- Si un Journey, incidencia, ajuste o ScheduledShift histórico ya se consolidó, su evidencia no se reescribe. Las decisiones administrativas existentes conservan su referencia y el mecanismo de corrección/reapertura se gobierna separadamente.

## 6. API y Portal3

### 6.1 Operaciones API propuestas

Se reutiliza JWT delegado y API server-to-server. Rutas nuevas propuestas, aún no autorizadas:

| Ruta propuesta | Permiso | Efecto |
|---|---|---|
| `POST /api/v2/workplace-coverages` | `checador.comodines.solicitar` | Crear solicitud `REQUESTED`, sin fila de turno activa. |
| `GET /api/v2/workplace-coverages` | `checador.comodines.leer` | Listar con filtros/paginación acotados por scope y continuidad verificable. |
| `GET /api/v2/workplace-coverages/{coverage_uuid}` | `checador.comodines.leer` | Detalle, versiones, patrón, estado y auditoría disponible dentro del scope. |
| `POST /api/v2/workplace-coverages/{coverage_uuid}/authorize` | `checador.comodines.aprobar` | Aprobar y crear todos los `TEMPORAL`/`DAY_OFF` assignments vinculados en una transacción. |
| `POST /api/v2/workplace-coverages/{coverage_uuid}/reject` | `checador.comodines.aprobar` | Rechazo append-only, sin writer de horarios. |
| `POST /api/v2/workplace-coverages/{coverage_uuid}/cancel` | `checador.comodines.cancelar` | Cancelación futura elegible y cierre atómico de asignaciones vinculadas. |

Todas las mutaciones requieren `Idempotency-Key`, fingerprint, CSRF en Portal3, validación de permiso/scope del API, transacción InnoDB, audit event UTC y readback canónico. Respuesta 403/409/422 no se convierte a 200. El navegador nunca accede directamente a API V2.

El GET existente de asignaciones y el resolver con `id_estacion` explícito mantienen comportamiento. El parámetro opt-in `resolution_context=WORKER_EFFECTIVE` es aditivo; consumidores actuales no pasan a usarlo sin modificación explícita.

### 6.2 Rol de Comodines existente

Portal3 conserva la ruta y UI de Rol de Comodines, adaptada sólo después de aprobación para ser cliente de API: crea solicitudes y consulta estados mediante controller/service Portal3 → API V2. API V2 es propietaria de la nueva persistencia y el estado. Portal3 **no hace INSERT/UPDATE/DELETE** directo en tablas de Rol de Comodines, memberships, shift assignments, jornadas o incidencias.

No se convierten automáticamente filas antiguas de `op_rh_rol_comodines`/`op_rh_comodines_dia` a `AUTHORIZED`; sin aprobación, plantilla/version, actor decisor y fechas laborales completas no son evidencia suficiente. Se preservan como Legacy/contexto existente y se muestran separadas del horario efectivo.

## 7. Persistencia aditiva propuesta — no ejecutable/no aplicada

La propuesta evita una tabla duplicada de turnos: el patrón se valida al solicitar; las filas activas efectivas se persisten en `op_rh_v2_turnos_asignaciones` con `tipo_asignacion=TEMPORAL` y referencia a la revisión autorizada. El catálogo/segmentos M04 existente conserva la definición de turno.

### 7.1 Objetos existentes y requeridos

| Objeto/campo | Estado observado | Propuesta |
|---|---|---|
| Personal canónico `op_rh_personal.id` | Existe; identity source. | Reutilizar. |
| Adscripción `op_rh_personal_localidad_version` | Existe, versionada/append-only. | Reutilizar para home; no insertar coberturas como membership. |
| Templates/segments M04 | Existe con versión/segmentos. | Reutilizar ID y versión exactos. |
| Turnos `op_rh_v2_turnos_asignaciones` | Existe `HABITUAL`/`TEMPORAL`, weekday, validity, actor. | Agregar nullable `id_cobertura_version` con FK a la revisión autorizada; llenar sólo para assignments emitidos por este flujo. |
| Audit `op_rh_v2_turnos_audit_log` | Existe con `entity_type` restringido a TEMPLATE/ASSIGNMENT y actor/operation/Workplace/details UTC. | Aditivamente permitir `WORKPLACE_COVERAGE`; mantener formato y reusar el log. Cada transición tiene actor, `id_estacion` = coverage Workplace y ambas localidades en details. |
| Coverage history | No se encontró entidad/campo de cobertura versionada en los modelos/migraciones inspeccionados. | Nueva tabla propuesta `op_rh_v2_worker_workplace_coverage_versions`, append-only. |
| Rol Legacy | Guarda header y filas de día/actor legacy; sin versión M04 aprobada, estado API ni trazabilidad de aprobación Dirección. | No alterar ni migrar datos automáticamente. |

### 7.2 DDL candidato no ejecutado

El SQL siguiente es una propuesta contractual revisada contra `information_schema` de `bd_admongas` (MariaDB 11.8.8, 2026-11-09), no una migración lista para correr. No ejecutar antes de la aprobación cruzada y de la autorización separada de migración.

```sql
-- NON-APPLIED CONTRACT PROPOSAL ONLY. Schema types/FKs were reconciled against
-- local bd_admongas MariaDB 11.8.8 on 2026-11-09. Do not execute before
-- cross-project approval and separate migration authorization.
CREATE TABLE op_rh_v2_worker_workplace_coverage_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    coverage_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision_no INT UNSIGNED NOT NULL,
    supersedes_id BIGINT UNSIGNED NULL,
    id_personal INT NOT NULL,
    id_home_workplace INT NOT NULL,
    id_home_membership_version INT UNSIGNED NOT NULL,
    id_coverage_workplace INT NOT NULL,
    id_home_physical_station INT NULL,
    id_coverage_physical_station INT NULL,
    fecha_desde_local DATE NOT NULL,
    fecha_hasta_local_exclusiva DATE NOT NULL,
    timezone_iana VARCHAR(64) CHARACTER SET ascii NOT NULL,
    proposed_schedule_json JSON NOT NULL,
    status ENUM('REQUESTED','AUTHORIZED','REJECTED','CANCELLED') NOT NULL,
    requested_by_usuario_id INT NOT NULL,
    requested_at_utc DATETIME(6) NOT NULL,
    approved_by_usuario_id INT NULL,
    approved_at_utc DATETIME(6) NULL,
    transition_actor_usuario_id INT NOT NULL,
    transition_action ENUM('REQUEST','AUTHORIZE','REJECT','CANCEL','REVISE') NOT NULL,
    motivo_solicitud VARCHAR(500) NOT NULL,
    motivo_transicion VARCHAR(500) NULL,
    idempotency_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recorded_at_utc DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_coverage_uuid_revision (coverage_uuid, revision_no),
    UNIQUE KEY uk_coverage_idempotency (idempotency_key_hash),
    KEY idx_coverage_worker_window (id_personal, status, fecha_desde_local, fecha_hasta_local_exclusiva),
    KEY idx_coverage_home_target (id_home_workplace, id_coverage_workplace, status),
    KEY idx_coverage_target_scope (id_coverage_workplace, status, fecha_desde_local, fecha_hasta_local_exclusiva),
    KEY idx_coverage_supersedes (supersedes_id),
    KEY idx_coverage_home_membership (id_home_membership_version),
    KEY idx_coverage_home_physical_station (id_home_physical_station),
    KEY idx_coverage_target_physical_station (id_coverage_physical_station),
    KEY idx_coverage_requested_by (requested_by_usuario_id),
    KEY idx_coverage_approved_by (approved_by_usuario_id),
    KEY idx_coverage_transition_actor (transition_actor_usuario_id),
    KEY idx_coverage_recorded (recorded_at_utc),
    CONSTRAINT chk_coverage_valid_range CHECK (fecha_hasta_local_exclusiva > fecha_desde_local),
    CONSTRAINT chk_coverage_distinct_workplaces CHECK (id_home_workplace <> id_coverage_workplace),
    CONSTRAINT fk_coverage_supersedes FOREIGN KEY (supersedes_id)
      REFERENCES op_rh_v2_worker_workplace_coverage_versions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_worker FOREIGN KEY (id_personal)
      REFERENCES op_rh_personal (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_home_workplace FOREIGN KEY (id_home_workplace)
      REFERENCES op_rh_localidades (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_home_membership FOREIGN KEY (id_home_membership_version)
      REFERENCES op_rh_personal_localidad_version (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_target_workplace FOREIGN KEY (id_coverage_workplace)
      REFERENCES op_rh_localidades (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_home_station FOREIGN KEY (id_home_physical_station)
      REFERENCES tb_estaciones (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_target_station FOREIGN KEY (id_coverage_physical_station)
      REFERENCES tb_estaciones (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_request_actor FOREIGN KEY (requested_by_usuario_id)
      REFERENCES tb_usuarios (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_approve_actor FOREIGN KEY (approved_by_usuario_id)
      REFERENCES tb_usuarios (id) ON DELETE RESTRICT,
    CONSTRAINT fk_coverage_transition_actor FOREIGN KEY (transition_actor_usuario_id)
      REFERENCES tb_usuarios (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE op_rh_v2_turnos_asignaciones
    ADD COLUMN id_cobertura_version BIGINT UNSIGNED NULL,
    ADD KEY idx_shift_assignment_coverage (id_cobertura_version),
    ADD CONSTRAINT fk_shift_assignment_coverage FOREIGN KEY (id_cobertura_version)
      REFERENCES op_rh_v2_worker_workplace_coverage_versions (id) ON DELETE RESTRICT;

ALTER TABLE op_rh_v2_turnos_audit_log
    MODIFY COLUMN entity_type ENUM('TEMPLATE','ASSIGNMENT','WORKPLACE_COVERAGE') NOT NULL;
```

Las FK y sus índices explícitos anteriores corresponden a los tipos reales verificados. La FK a la membresía confirma que la versión referenciada existe; API además debe comprobar que esa misma fila corresponde al `id_personal` y `id_home_workplace` declarados, porque las FK simples no expresan esa igualdad compuesta sin alterar los índices del historial de membresías. Los IDs físicos se validan además contra el mapeo canónico único `numlista`; una FK por sí sola no acredita esa equivalencia. Los `template_id`/versiones dentro del JSON se validan por API contra plantillas/versiones activas. El formato de patrón se congela antes de implementar. No se desactivan restricciones.

Rollback futuro sólo podrá quitar los objetos/columna nuevos tras verificar cero referencias y preservar las filas existentes. No se propone reescribir `op_rh_personal_localidad_version`, assignments, global templates, punches, jornadas ni incidencias.

## 8. Compatibilidad y plan de aceptación

### Compatibilidad protegida

- No cambia el endpoint individual de Shift Assignment ni su comportamiento salvo que se envíe el nuevo vínculo de cobertura.
- Las asignaciones `HABITUAL` y `TEMPORAL` actuales sin coverage reference mantienen su validación y resolución.
- El resolver de turnos existente y M04 permanecen dueños de plantillas/segmentos; el writer de cobertura sólo los referencia.
- No modifica M02, M03, M04, M05, M06, M07, PIN temporal, Comparación Nómina, Configuración o permisos actuales.
- No altera memberships existentes, plantillas, `PunchEvents`, asignaciones, jornadas consolidadas, evaluaciones/incidencias o producción.

### Pruebas posteriores a aprobación

1. Crear request con persona canónica, home membership version, Workplace destino, timezone, siete estados weekday, template/version y razón.
2. Encargado sin grant `solicitar`: 403; con grant explícito puede solicitar dentro de su home scope, pero no aprobar.
3. Dirección sin `aprobar`, con denegación directa, o sin scope de origen/destino: 403; con permiso y ambos Workplaces en scope puede aprobar.
4. Confirmar precedencia USER directa (incluye `active=0`/`granted=0`) sobre POSITION; no se reactivan grants indirectamente.
5. Payload con worker, membership, estación, localidad, versión o actor falsificados: rechazado.
6. Rechazar pertenencia inexistente/ambigua, traslado dentro del rango no segmentado, localidad no autorizada, `numlista` no único, timezone distinto/missing, template fuera de target, fecha inválida y falta de weekdays.
7. Aprobar: en una sola transacción revisar coverage version + auditoría + assignments para cada weekday; error forzado revierte todo. Confirmar `TEMPORAL` + template version exacta y `id_cobertura_version`.
8. Idempotencia: mismo request/approve/cancel retorna la revisión existente; la misma clave con distinto payload es conflict; concurrencia no duplica revisiones ni shift rows.
9. Probar inicio inclusivo/fin exclusivo, retorno al horario habitual, `DAY_OFF` explícito, noche/DST y `America/Mexico_City` con instantes UTC calculados por timezone authority.
10. Confirmar `WORKER_EFFECTIVE`: cobertura autorizada reemplaza el home schedule en esos días; sin cobertura usa habitual; datos faltantes o duplicados dan `UNKNOWN`, nunca descanso/falta.
11. Intentar solapar dos coberturas, asignación ajena/doble jornada, cancelación iniciada/consolidada: conflicto sin historia alterada.
12. Journey on-demand y lectura de incidencias vinculadas al Journey/Workplace resultante conservan procedencia; las filas históricas previas y punches son idénticos.
13. Portal3 Chrome: flujo de solicitud Encargado, aprobación Dirección, readback, F5 y tabla; rechazo/cancelación autorizados; consulta de actores/localidades no autorizados permanece denegada.
14. Comparar M02–M07, PIN, selector institucional y ACL antes/después; no activar grants automáticamente.

Mocks, fixtures y pruebas de servicio no sustituyen prueba autenticada contra MariaDB de desarrollo y Chrome; no usar personas operativas en nuevas escrituras sin autorización específica.

## 9. Decisiones restantes y gates

Las reglas de autoridad aprobadora, precedencia de cobertura y preservación del home Workplace ya fueron resueltas por el responsable. Antes de implementación todavía se requieren:

1. **Aprobación cruzada de este SHA** por responsables Portal3/API V2.
2. **Migración:** preparar el archivo up/down final a partir del mapeo de tipos/FK verificado, someterlo a revisión independiente y obtener autorización específica de ejecución; este DDL candidato no es una orden de migración.
3. **Autorización específica de grants** para cada permiso propuesto. Este addendum no concede los grants; en particular, la capacidad de Encargado para solicitar es opt-in y no se presume.
4. **Revisión de contrato API/J1/J2** para el selector `WORKER_EFFECTIVE` y la fuente V2; la exclusión Legacy de J2 §2.3 queda intacta.
5. **Despliegue de timezone:** confirmar que el Workplace target y, para este alcance, el origen operativo usan el timezone contractual `America/Mexico_City`. Si no, bloquear el request hasta decisión de negocio; no aplicar fallback.
6. Confirmar de manera operativa qué periodos se consideran “consolidados” en cada componente de Journey/Incidencias/M07. Mientras no esté resuelto, cualquier fecha con un registro derivado persistido se trata como consolidada y no se corrige mediante cobertura.

## 10. Plan mínimo posterior a aprobación

1. Registrar aprobación cruzada y congelar el SHA del addendum.
2. Reconciliar schema real y producir migration up/down final, aditiva, con revisión independiente; autorización de migración separada.
3. Implementar autorización/read/write/readback API para coverage y transiciones auditables.
4. Extender el validador de membership para aceptar sólo cobertura autorizada y exacta en los turnos enlazados, conservando las reglas M03 normales.
5. Integrar el módulo Portal3 existente como cliente API; no escribir tablas Legacy.
6. Extender el resolver existente en modo opt-in `WORKER_EFFECTIVE`; integrar el resultado en Journey/M05 e incidencias sin duplicar evaluador.
7. Ejecutar pruebas con QA aislado y `bd_admongas` de desarrollo, revisar regresiones y evidencia de Chrome.
8. Preparar preproducción en un paso separado con autorización específica; producción no está autorizada aquí.

## 11. Estado de propuesta

```yaml
COMODINES_BUSINESS_RULES: APPROVED
COVERAGE_APPROVAL_AUTHORITY: DIRECTION_OPERATIONS
COVERAGE_SHIFT_PRIORITY: APPROVED_TEMPORARY
BASE_WORKPLACE_PRESERVED: YES
CANONICAL_MODEL_DEFINED: YES
API_WRITER_CONTRACT: READY
SHIFT_RESOLUTION_CONTRACT: READY
LOCALITY_AUTHORIZATION: READY
PHYSICAL_SCHEMA_PROPOSAL: READY
MIGRATION_EXECUTED: NO
FUNCTIONAL_CODE_CHANGED: NO
DATABASE_CHANGED: NO
ARCHITECTURE_FREEZE_CHANGED: NO
FINAL_ADDENDUM_APPROVAL_REQUIRED: YES
```

`READY` indica que la propuesta está definida para revisión contractual, no que existan permisos, código o DDL autorizado para ejecutar. El SQL es no aplicado y requiere cotejo de tipo/FK antes de una migración ejecutable.
