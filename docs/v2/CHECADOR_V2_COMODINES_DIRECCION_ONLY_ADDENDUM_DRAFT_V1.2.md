# Checador V2 — Coberturas temporales de Workplace por Rol de Comodines
## Revisión de propietario de escritura — V1.2

**Estado:** APROBADO POR DECISIÓN EXPRESA DEL RESPONSABLE PARA IMPLEMENTACIÓN EN DESARROLLO  
**Aplica a:** Portal3 y API V2  
**Revisión que complementa:** V1.1 (aprobada; se conserva intacta como antecedente)  
**Alcance:** cambia exclusivamente la propiedad de escritura administrativa y el consumo de coberturas. La política Dirección-only de V1.1 permanece vigente.  
**No modifica:** Architecture Freeze V1.0, J1, J2, ingesta biométrica, jornadas históricas consolidadas ni permisos de otros módulos.

## 1. Decisión de arquitectura

El recorrido administrativo canónico será **Portal3 backend → `bd_admongas` → API V2**.

1. Portal3 backend es el único escritor administrativo de solicitudes, transiciones, revisiones, asignaciones `TEMPORAL` vinculadas y eventos de auditoría de cobertura.
2. Portal3 escribe con la conexión PDO institucional desde el servidor. El navegador nunca se conecta a MariaDB ni envía SQL. Actor, puesto y sesión se derivan del contexto autenticado del servidor; los identificadores del cliente no conceden identidad ni alcance.
3. API V2 es consumidor de sólo lectura de las revisiones persistidas y autorizadas. Conserva `WORKER_EFFECTIVE`, prioridad temporal, resolución de turno y evaluación canónica de Journey. No crea, cambia ni cancela coberturas, asignaciones vinculadas o auditoría administrativa.
4. Las rutas API de mutación de coberturas existentes dejan de aceptar mutaciones en el corte de responsabilidad definido en §7. Las rutas de lectura autorizada y el resto de API V2 permanecen vigentes.
5. No puede existir más de un escritor administrativo activo. El corte requiere denegación efectiva de las mutaciones API antes de habilitar el writer Portal3.

## 2. Actores y reglas conservadas

Rige la política aprobada por V1.1: sólo Dirección de Operaciones (puesto 13), con permiso efectivo por operación y alcance vigente, puede consultar, solicitar, aprobar o cancelar. Encargado (puesto 6) está denegado en menú, ruta, controlador y cualquier endpoint. La misma persona de Dirección puede solicitar y luego aprobar mediante operaciones explícitas separadas; la solicitud nunca se autoaprueba.

Portal3 valida en el servidor identidad/sesión, puesto efectivo, grants, scope de ambos Workplaces, trabajador activo, membresía habitual que cubre el intervalo, turno y versión exactos del Workplace destino, vigencia, timezone, solapamientos y estado previo. API V2 conserva sus validaciones de lectura y scope al resolver el Workplace efectivo. Ninguna autorización del módulo amplía permisos de programación regular.

## 3. Transacciones administrativas

Cada solicitud, aprobación o cancelación se ejecuta en una transacción InnoDB única desde Portal3:

- **Solicitud:** inserta revisión `REQUESTED`, fingerprint y hash idempotente, actor, motivo y tiempo UTC; no crea asignaciones activas.
- **Aprobación:** vuelve a validar estado, permisos, membresía, localidad, timezone, plantilla/versión, conflictos y ausencia de hechos consolidados; inserta revisión `AUTHORIZED`, sus asignaciones `TEMPORAL` vinculadas mediante `id_cobertura_version` y auditoría. Toda la operación confirma o revierte en conjunto.
- **Cancelación:** aplica los límites temporales del contrato vigente; inserta revisión `CANCELLED`, cierra únicamente el tramo futuro elegible de asignaciones vinculadas y registra auditoría en la misma transacción. No reescribe historia consolidada.

Todas las transiciones conservan versiones append-only, `Idempotency-Key` almacenada como hash, fingerprint para rechazar reutilización con payload distinto, bloqueo por trabajador/revisión para concurrencia, motivo obligatorio, actor autenticado, timestamps UTC y readback antes del commit. Se rechazan duplicados, intervalos superpuestos, asignaciones ambiguas y cualquier discrepancia de estado. Las filas Legacy no se convierten ni se usan como fuente canónica.

## 4. Contrato de persistencia compartida

Se reutiliza el esquema V2 ya desplegado: `op_rh_v2_worker_workplace_coverage_versions`, `op_rh_v2_turnos_asignaciones.id_cobertura_version` y `op_rh_v2_turnos_audit_log` con entidad `WORKPLACE_COVERAGE`. No se propone DDL nuevo en esta revisión.

Portal3 conserva el patrón explícito de siete días: cada día es `WORK` con una plantilla y versión exactas o `DAY_OFF`. La aprobación persiste las filas `TEMPORAL` vinculadas sólo tras validar el conjunto completo. Al cancelar, los vínculos y cierres deben seguir correspondiendo a la revisión y al límite efectivo. Adscripción habitual, membresía, turnos `HABITUAL`, jornadas e incidencias históricas permanecen intactos.

El acceso de escritura de la cuenta de BD que utiliza Portal3 deberá limitarse a las tablas/campos necesarios para la transacción de cobertura y a las lecturas canónicas requeridas; el despliegue no se considera listo hasta verificar que el privilegio efectivo no permita escrituras RH o de otros módulos no relacionadas.

## 5. Consumo por API V2

API V2 lee sólo la última revisión válida por `coverage_uuid`, con estado y rango efectivo consistentes, verifica el enlace de asignación y plantilla/versión, y resuelve la membresía habitual de origen. Una cobertura autorizada prevalece temporalmente en su Workplace destino; al concluir o cancelarse su tramo efectivo, vuelve a resolver el horario habitual de origen. Falta de datos, múltiples fuentes efectivas, referencia rota, versión distinta o alcance no verificable producen `UNKNOWN`, nunca un turno inferido.

`WORKER_EFFECTIVE` conserva procedencia: cobertura UUID/revisión, actor de aprobación, Workplace habitual y efectivo, versión de membresía, asignación vinculada, plantilla/versión, fecha local y timezone. Journey continúa evaluándose sólo en API V2; Portal3 no calcula jornadas ni modifica PunchEvents, DailyJourney o incidencias.

## 6. Gates independientes

- **Portal3 writer gate:** controla únicamente las escrituras administrativas anteriores; default-off y limitado a desarrollo hasta autorización operativa separada. No depende de HTTP API V2.
- **API V2 reader/resolver gate:** controla lectura y uso de `WORKER_EFFECTIVE`; no concede capacidad de mutación administrativa.
- Las rutas de mutación API se deniegan en el punto servidor efectivo aunque el reader gate esté activo.
- El gate de ingesta de marcaciones no cambia.

Ningún gate se activa por aprobar este contrato. Los estados de activación se verifican por separado en cada proceso en ejecución.

## 7. Corte único de escritor

El corte sólo se completa cuando todas estas condiciones se verifican en desarrollo: (a) preflight de esquema, FK, índices y privilegios; (b) writer Portal3 implementado y validado; (c) mutaciones HTTP API denegadas incluso con su antiguo feature flag activo; (d) lecturas API y resolución efectiva preservadas; (e) pruebas de atomicidad, concurrencia, idempotencia, readback y rollback; (f) gates de operación cerrados hasta revisión manual. Antes de cumplirlas, no se habilitan escrituras por ninguno de los dos caminos.

La fecha/instante del corte se registra al ejecutar el procedimiento autorizado; no se atribuye retrospectivamente ni se inventa en esta propuesta.

## 8. Compatibilidad y límites

La revisión preserva el endpoint de lectura y la semántica de `WORKER_EFFECTIVE`, M03/M04 regulares, memberships, J1/J2 y los invariantes de hora efectiva e historial. La única compatibilidad retirada es la mutación de coberturas por API V2; después del corte debe responder con denegación estable y no escribir filas ni auditoría. Los demás endpoints de asignación habitual/temporal de M03/M04 no se modifican.

## 9. Evidencia de esquema para esta revisión

Preflight de sólo lectura por la conexión institucional de Portal3 a `bd_admongas`, MariaDB `11.8.8-MariaDB`: las tres tablas citadas existen y son InnoDB; `id_cobertura_version` es nullable `BIGINT UNSIGNED` con índice y FK a la revisión; la revisión tiene uniques `(coverage_uuid, revision_no)` e `idempotency_key_hash`, checks de rango y Workplace distinto, y FKs de trabajador, localidades, membresía, plantilla y actores; `entity_type` admite `WORKPLACE_COVERAGE`. En la consulta, coberturas, asignaciones vinculadas y auditoría de cobertura tenían cero filas.

Se detectó documentación de implementación antigua que aún afirma que esos objetos no existen; el changelog de migración registra su aplicación. Esta evidencia debe acompañar la revisión y prevalece el preflight verificable, no la nota obsoleta. No se ejecutó DDL ni DML.

La cuenta efectiva usada por el runtime es `dev@localhost` y `SHOW GRANTS` devolvió privilegios `ALL` sobre `*.*`, además de grants amplios sobre `bd_admongas.*`. Aunque las validaciones de aplicación y el gate mantienen cerradas las mutaciones operativas, este principal excede el mínimo de privilegios para operación. No se modificaron grants conforme a las restricciones; por ello la activación operativa queda pendiente de una cuenta de aplicación con privilegio acotado y autorización separada.

## 10. Aprobación y alcance de implementación

Esta revisión formaliza la decisión arquitectónica de propiedad del escritor y sustituye únicamente las cláusulas V1.1 que designan al API como mutador. V1.1 permanece intacto como antecedente; las reglas de actores y cobertura se conservan. La implementación queda limitada a Portal3 writer, denegación de mutaciones API, lector/resolver API y sus regresiones. No autoriza producción, nuevos grants, nuevas tablas, cambios en J1/J2 ni activación operativa.
