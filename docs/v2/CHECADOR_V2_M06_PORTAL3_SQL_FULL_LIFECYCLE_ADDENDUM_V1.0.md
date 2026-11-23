# CHECADOR V2 — M06 PORTAL3 SQL FULL LIFECYCLE ADDENDUM V1.0

**Estado:** APROBADO POR EL PROPIETARIO PARA IMPLEMENTACIÓN EN M06; la activación queda condicionada al corte verificado del escritor API.  
**Aprobación:** instrucción expresa del propietario del 2026-11-23.  
**Ámbito:** exclusivamente el módulo M06 Incidencias de Portal3.  
**Freeze:** no modifica `CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`, J1 ni J2.

## 1. Aprobación y arquitectura de M06

El propietario autoriza que Portal3 sea el único escritor administrativo del ciclo de vida M06 utilizando exclusivamente la conexión MariaDB institucional `default`. Portal3 no usará HTTP hacia API V2 para leer ni mutar incidencias. El navegador se comunica sólo con Portal3.

API V2 conserva la ingesta de marcaciones, integridad, evaluación/cálculo y demás responsabilidades fuera de M06. Esta adenda no autoriza evaluación de jornadas, aplicación automática a jornadas o nómina, creación de FALTAS, modificación de marcaciones, ni cambia la responsabilidad de M01/M03/M04/M07/M11.

## 2. Conciliación contractual limitada

Para M06 únicamente, esta adenda sustituye o complementa:

1. P3-G §12.1, “Portal3 Backend → API Only”, para lecturas y operaciones administrativas M06: Portal3 consulta directamente MariaDB `default`; no hace solicitudes HTTP a API V2.
2. P3-G §12.3, en lo relativo a la propiedad y ejecución de las capacidades M06 `READ`, `CREATE`, `CREATE REVISION`, `RESOLVE`, `AUTHORIZE`, `REJECT` y `REVOKE`.
3. CHECADOR_V2_M06_SQL_DIRECT_ADDENDUM_V1.0, ampliando el alcance inicial de creación/historial al ciclo administrativo completo descrito en §3.
4. Cualquier requisito previo de inmutabilidad durable del historial a nivel MariaDB para M06, sustituido por la regla de aplicación y el riesgo aceptado de §5.

Las capacidades y permisos dedicados de P3-G §11 siguen siendo independientes. Lectura, creación, revisión, resolución y autorización no se heredan entre sí. La aprobación final de FALTA sigue siendo una decisión separada, humana y exclusiva de Dirección, conforme al permiso existente; ninguna operación de este addendum crea ni autoriza una FALTA.

No se cambian los contratos congelados como texto ni se transfieren a Portal3 cálculos, evaluaciones, ingesta, integridad, efectos económicos o reglas del lector.

## 3. Operaciones autorizadas y modelo persistido

Se reutilizan exclusivamente las tablas existentes:

- `op_rh_v2_incidencias_maestro`: identidad lógica UUID, trabajador, localidad canónica, categoría, periodo y proyección del estado/revisión vigentes.
- `op_rh_v2_incidencias_revisiones`: filas de historial con revisión, estado, motivo, evidencia, actor, actor de aprobación cuando aplique y fecha UTC.

Portal3 implementa:

| Operación | Persistencia M06 autorizada |
|---|---|
| Crear | Insertar maestro y revisión 1 `PENDING_APPROVAL` en una transacción. Mantener UUIDv5 de creación y comprobar el contenido original en reintentos. |
| Crear revisión | Insertar la revisión siguiente con estado vigente conservado. Actualizar sólo la proyección actual del maestro y fechas permitidas, en la misma transacción. |
| Resolver | Insertar revisión `RESOLVED_PENDING_AUTHORIZATION`; no constituye autorización final. |
| Autorizar | Insertar revisión `ACTIVE`, registrando al actor en `aprobado_por_usuario_id`. |
| Rechazar | Insertar revisión `REJECTED`, registrando al actor en `aprobado_por_usuario_id`, de acuerdo con la semántica existente. |
| Revocar | Insertar revisión `CANCELLED`, registrando al actor en `aprobado_por_usuario_id`, de acuerdo con la semántica existente. |

Todas las mutaciones requieren transacción, bloqueo del maestro, validación de revisión esperada, comprobación de localidad y estado actual, auditoría del actor y lectura SQL posterior del maestro y de la revisión nueva antes de informar éxito. Un conflicto, rollback, error SQL o readback incompleto nunca se presenta como guardado.

Las revisiones son append-only en la lógica de Portal3: se insertan nuevas filas y nunca se ejecuta UPDATE o DELETE sobre `op_rh_v2_incidencias_revisiones`. La actualización de `op_rh_v2_incidencias_maestro` sólo mantiene la proyección de estado/revisión/periodo vigente dentro de la misma transacción.

## 4. Identidad, autorización y transiciones

En cada solicitud se valida sesión activa, token CSRF, actor institucional, ACL efectiva y localidad canónica autorizada. La localidad se valida contra `id_localidad`; no se infiere de `id_estacion` ni del número de estación. El UUID no amplía el ámbito de lectura o escritura.

- Dirección mantiene lectura y no obtiene permiso de escritura por este addendum.
- Crear exige Encargado activo, permisos M06 existentes de lectura/creación y localidad autorizada.
- Crear revisión exige permiso M06 `crear` o permiso de edición del namespace de resolución ya existente, y localidad autorizada; continúa denegado a Dirección.
- Resolver exige el permiso efectivo de edición de `checador-v2-incidencias-resolucion`.
- Autorizar, rechazar y revocar exigen los permisos efectivos de `checador-v2-incidencias-autorizacion` para cada acción, de acuerdo con `ChecadorAccessService`.

Se conservan las transiciones y estados canónicos existentes: `RESOLVED_PENDING_AUTHORIZATION`, `ACTIVE`, `REJECTED` y `CANCELLED`. Las transiciones no permitidas y las revisiones obsoletas se rechazan sin mutación. No se modifica el significado de categorías ni se admite `FALTA` como tipo de incidencia.

## 5. Privilegios SQL y riesgo aceptado

El propietario autoriza la conexión institucional compartida `default` con sus privilegios amplios existentes, sin crear usuarios o conexiones, sin cambiar grants y sin exigir triggers append-only.

Se acepta expresamente que, si el principal `default` tiene UPDATE/DELETE/ALL, MariaDB no garantiza la inmutabilidad del historial frente a ese principal o a otro proceso que comparta esas credenciales. Append-only es una regla obligatoria de la aplicación Portal3, no una garantía de seguridad de la base de datos. Portal3 no ejecutará UPDATE/DELETE en la tabla de revisiones.

El preflight que conserva el escritor verifica el esquema, engine, relaciones, índices y operaciones efectivamente requeridas en la conexión `default`, incluyendo SELECT en fuentes consultadas, INSERT en maestro y revisiones y UPDATE en el maestro para mantener la proyección vigente. No exige privilegios exclusivos ni interpreta los privilegios amplios como falta de permisos. Un fallo estructural o de operación requerida continúa cerrando el escritor.

## 6. Idempotencia semántica y concurrencia

La creación conserva el UUIDv5 y compara el maestro y la revisión inicial completos antes de tratar una repetición como éxito. El esquema actual no tiene una columna de clave/idempotencia para cada transición, por lo que el propietario aprueba expresamente la estrategia de revisión esperada y comparación semántica persistida:

1. El cliente envía `expected_current_revision` y el contenido de la acción.
2. Portal3 bloquea el maestro en la transacción y valida que la revisión vigente y la última revisión persistida concuerden.
3. Si se aplica la operación, se inserta únicamente la siguiente revisión y se actualiza la proyección del maestro de forma atómica.
4. Si la revisión actual ya avanzó exactamente una posición y la última revisión coincide en estado, actor, motivo/evidencia y fechas/proyección disponibles, Portal3 devuelve esa operación como replay semántico sin insertar otra fila.
5. Si no coincide o la revisión avanzó por otra operación, se devuelve conflicto y no se escribe.

No se promete deduplicación durable por clave para acciones posteriores: la tabla no almacena tal clave. No se oculta metadata en `motivo`, no se inventan columnas y no se declara que UUID único equivalga a idempotencia completa. El bloqueo transaccional y el índice único existente de `(id_incidencia_maestro, revision_numero)` protegen la secuencia local; la propiedad de escritor único sigue siendo obligatoria.

## 7. Corte único de escritor API

La inspección de `API V2 origin/work` en el commit `1d0da3c23607bd94c2bf7cf95d43d01082ad10d0` encontró seis POST que llegan a `AdministrativeIncidenceV2Controller`; ese código no acredita el estado de un runtime activo:

- `POST /api/v2/incidences` — crear.
- `POST /api/v2/incidences/{uuid}/revisions` — crear revisión.
- `POST /api/v2/incidences/{uuid}/resolve` — resolver.
- `POST /api/v2/incidences/{uuid}/authorize` — autorizar.
- `POST /api/v2/incidences/{uuid}/reject` — rechazar.
- `POST /api/v2/incidences/{uuid}/revoke` — revocar.

El corte abarcará únicamente esos seis POST. GET, evaluaciones administrativas, ingesta, marcaciones y rutas ajenas permanecen intactos. Antes del corte se terminarán y probarán en Portal3 las seis operaciones equivalentes. Después, el cambio API deberá responder con rechazo explícito antes del controlador mutante y demostrar por lectura/comparación que no cambian maestro, revisiones ni auditoría. El corte del runtime activo no se realiza ni se presume en esta implementación.

Secuencia requerida: (1) implementación y regresiones aisladas Portal3; (2) corte exclusivo API, publicación controlada y verificación de los seis rechazos sin mutaciones; (3) acreditación del runtime y evidencia de corte; (4) habilitación del escritor Portal3. Se admite una ventana sin escritor, nunca dos escritores activos. Para reversión, cerrar primero Portal3 y sólo después reabrir API.

El servicio Portal3 mantiene el control de corte cerrado por defecto. No se agregan variables `.env` ni atestaciones nuevas para historial. Este documento no autoriza cierre API, ejecución de escrituras operativas, activación de Portal3 ni despliegue a Plesk.

## 8. Validación y límites de la aprobación

La validación automatizada M06 usa base SQLite aislada y datos controlados para verificar transacciones, rollback, historial append-only desde la aplicación, permisos, reintentos semánticos, conflictos y readback. Esas pruebas no acreditan persistencia real en MariaDB ni el corte efectivo de un runtime API.

La aprobación cubre el desarrollo de M06 bajo el alcance descrito; no autoriza Plesk/producción, cambios de esquema o grants, otras bases, nuevos usuarios/conexiones, cambios a Architecture Freeze/J1/J2, API o Lector. La escritura operativa permanecerá cerrada hasta la verificación independiente del corte API y del preflight en el destino.
