# Checador V2 — Addendum de autoridad SQL Portal3 para M08 V1.0

**Documento:** propuesta de transición administrativa M08
**Versión:** V1.0; origen: `TEMPORARY_ACCESS_PORTAL3_SQL_AUTHORITY_ADDENDUM_DRAFT_V1.0.md`, conservado.
**Estado:** arquitectura y preparación local API aprobadas por el propietario; activación operativa, validación real y producción pendientes. No autoriza el corte ni cambios de permisos.
**Contratos base:** `TEMPORARY_ACCESS_API_CONTRACT.md` V1.0; `TEMPORARY_ACCESS_SECURITY_ADDENDUM_V1.0.md`, §§3–4 sustituidos por `CHECADOR_PIN_TEMPORAL_ACL_ADDENDUM_V1.1.md`
**Arquitectura:** `CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`, sin cambios

## 1. Propósito y límites

Este borrador propone transferir a Portal3 las operaciones humanas de consulta e historial, autorización/emisión, rechazo y cancelación de solicitudes M08 mediante SQL transaccional sobre la tabla canónica existente. API conserva la creación de solicitudes por el Lector, autenticación de dispositivo, validación/consumo de PIN y expiración/contadores necesarios para esa validación. Lector continúa usando exclusivamente API; no obtiene acceso SQL.

Este addendum aprobado para preparación local no cambia el contrato congelado, no acredita que la tabla exista en un runtime, no concede privilegios SQL y no activa el corte. La activación requiere implementación compatible de Portal3, validación real y corte coordinado.

## 2. Evidencia de implementación inspeccionada

El repositorio contiene una implementación posterior a la sección histórica que llama al modelo “NOT YET IMPLEMENTED”:

- `index.php` enruta los seis endpoints M08.
- `TemporaryAccessV2Controller.php` separa Device Auth (solicitud y validación) de JWT/ACL/scope Portal3 (consulta y mutaciones humanas).
- `TemporaryAccessV2Service.php` opera `op_rh_v2_solicitudes_acceso_temporal`.
- `migraciones/v2/2026_10_01_000001_create_v2_temporary_access_tables.sql` crea esa tabla InnoDB con índices únicos para `request_uuid` y `successful_validation_uuid`, además de índices por estación/estado, dispositivo/referencia/estado y expiración.
- El changelog registra la remediación G8-A2 y los seis endpoints.
- En el controlador, las operaciones humanas pasan por `authorizeTemporaryAccessInheritedPrepared`; el resolvedor normaliza `crear`, `editar` y `eliminar` a la acción heredada `editar`, consistente con la política V1.1 para escritura. La política exige puesto/usuario activo, permiso efectivo, submódulo Checador, scope y denegación individual.

Por tanto, “NOT YET IMPLEMENTED” describe el estado del documento conceptual histórico, no el estado del código/migración versionados. No se consultó MariaDB: la presencia, versión y grants efectivos de la tabla en local, PREPROD o producción quedan **sin verificar**.

La tabla versionada contiene: `id`, `request_uuid`, `successful_validation_uuid`, `id_dispositivo`, `id_estacion`, `referencia_externa_personal`, `id_personal_resuelto`, `reason`, `status`, `pin_hash`, `pin_failed_attempts`, timestamps de solicitud/expiración, actor y timestamp de autorización/rechazo/cancelación, expiración del PIN y consumo. El DDL no declara foreign keys ni una restricción física que garantice una sola solicitud activa por trabajador/dispositivo; el servicio impone esa regla transaccionalmente.

## 3. Responsabilidad propuesta por componente

### Portal3 — autoridad administrativa después del corte

- Resolver en cada petición la identidad institucional, usuario activo, permiso efectivo y jurisdicción según el addendum ACL V1.1: puesto, grants efectivos, denegaciones individuales y correspondencia unívoca estación física/localidad. No aceptar IDs del navegador como autorización.
- Consultar la cola y el historial autorizado por su conexión SQL institucional.
- En una transacción, bloquear la solicitud, revalidar su estación, trabajador, dispositivo, estado y vencimiento; emitir, rechazar o cancelar según las transiciones V1.0; verificar el resultado persistido antes de responder.
- En autorización, generar el PIN en el servidor Portal3 con CSPRNG compatible con el formato contractual y calcular `password_hash($pin, PASSWORD_BCRYPT)`. Guardar solo el hash, mostrar el PIN una sola vez y no incluir PIN/hash en logs, historial o consultas de lectura.
- Mantener auditoría en las columnas de actor/timestamp existentes. No cambiar solicitudes, identidades, marcaciones ni campos ajenos a M08.

### API — autoridad de dispositivo y consumo

- Mantener `POST /requests` como escritor de solicitudes del Lector, con Device Auth, resolución de identidad vigente, estación derivada del dispositivo, idempotencia por `request_uuid` y estado `PENDING`.
- Mantener `POST /validate` como única autoridad que acepta el PIN: Device Auth, trabajador/dispositivo/estación/referencia coincidentes, `password_verify`, vencimiento, máximo de tres intentos, uso único y consumo atómico con `validation_uuid`.
- Mantener expiración y actualización de estados vinculadas a la petición/consumo según el contrato.
- No aceptar PIN ni estado de autorización como afirmación del Lector. No enviar PIN o `pin_hash` al Lector excepto el PIN introducido por la persona en el payload de validación; nunca registrar esos valores.

### Lector

- Sin cambios de endpoint ni de DTO en esta propuesta. Sigue solicitando por API, recibiendo `request_uuid`, capturando PIN y validándolo en línea con API. Sin conexión ni permisos SQL.

## 4. Tabla, estados y concurrencia

Se conserva como única fuente persistente `op_rh_v2_solicitudes_acceso_temporal`; no se propone tabla ni columna adicional.

Transiciones existentes que Portal3 debe respetar:

| Origen | Operación | Destino | Condición |
|---|---|---|---|
| `PENDING` | Autorizar | `AUTHORIZED` | Solicitud no vencida, actor y estación autorizados; guardar hash, actor, hora y expiración de PIN |
| `PENDING` | Rechazar | `REJECTED` | Solicitud no vencida, actor y estación autorizados |
| `PENDING` o `AUTHORIZED` | Cancelar | `CANCELLED` | Actor y estación autorizados; no terminal ni consumida |
| `PENDING` | Expirar | `EXPIRED` | `request_expires_at_utc <= UTC_TIMESTAMP(6)` |
| `AUTHORIZED` | Expirar | `EXPIRED` | `pin_expires_at_utc <= UTC_TIMESTAMP(6)` |
| `AUTHORIZED` | Consumo API | `USED` | API verifica PIN y hace transición condicional dentro de su transacción |
| `AUTHORIZED` | Intentos agotados API | `LOCKED` | Tercer intento incorrecto |

Los estados terminales no se reabren ni se reutilizan. Un nuevo intento de contingencia usa otro `request_uuid`; la creación API cancela solicitudes activas anteriores del mismo dispositivo/trabajador.

Toda escritura humana propuesta usa InnoDB y `START TRANSACTION`; bloquea con `SELECT ... FOR UPDATE` la fila de dispositivo primero y luego la fila por `request_uuid`, vuelve a leer/revalidar la relación dispositivo-estación y la solicitud bajo el lock, verifica estado/UTC, aplica una actualización condicional, confirma (`COMMIT`) y hace readback. No debe haber llamadas HTTP a API dentro de la transacción. Mantener el orden dispositivo → solicitud evita invertir el orden de creación/validación de API y reduce deadlocks. Ante conflicto concurrente, estado vencido, cambio de scope o fallo SQL: rollback y denegación; no reportar éxito.

La consulta de cola/historial excluye `pin_hash` y cualquier secreto. Los PIN se presentan una vez en la respuesta de autorización; no se recuperan desde SQL ni se regeneran al reintentar una operación ya confirmada.

### Idempotencia y pérdida de respuesta administrativa

El contrato existente define idempotencia de creación mediante `request_uuid` y de validación/lost ACK mediante `validation_uuid`; no define UUID de operación humana ni recuperación de PIN emitido. Este borrador no inventa una nueva clave ni almacena PIN en claro. Si Portal3 pierde la respuesta después de confirmar autorización, el PIN no puede recuperarse: la UI debe indicar que no está disponible y el actor deberá cancelar esa autorización y solicitar una nueva solicitud, que produce otro PIN de un solo uso. Repetir autorización sobre una fila ya `AUTHORIZED` no genera otro PIN ni revela el hash.

## 5. Parámetros y custodia

Se conserva: solicitud `PENDING` hasta 30 minutos; PIN numérico de seis caracteres hasta 15 minutos; máximo tres fallos; validación en línea; consumo único; asociación a dispositivo, estación y trabajador resuelto; PIN de una sola vista; UTC de servidor/DB. No se cambia el PIN de asistencia ni el PIN administrativo de configuración.

El propietario aprobó conservar `random_int(0, 999999)` más padding a seis caracteres y `password_hash(..., PASSWORD_BCRYPT)`; API valida seis dígitos y usa `password_verify`. Los ceros iniciales son válidos. Esto concilia para esta revisión el rango `000000`–`999999` de §6 con la descripción histórica `random_int(100000, 999999)` de §11 y `API_V2_SECURITY.md`, sin editar documentos congelados.

No hace falta secreto HMAC compartido para validar el PIN: bcrypt almacena un hash con salt, y API verifica el valor introducido. Portal3 y API requieren conexiones SQL con privilegio mínimo al mismo esquema; no deben intercambiar credenciales HTTP de servicio.

## 6. SQL y permisos mínimos propuestos

No se ha verificado el usuario SQL ni sus grants actuales. Antes del corte, inspeccionar `SHOW GRANTS` y `information_schema` de forma autorizada; no ampliar permisos basándose en este borrador.

- **Portal3 lector:** `SELECT` solamente sobre las columnas de presentación/estado necesarias de solicitudes y las columnas necesarias de dispositivo y trabajador. Nunca seleccionar `pin_hash`.
- **Portal3 escritor administrativo:** `SELECT` (incluido `FOR UPDATE`) de las columnas necesarias para scope/estado/fechas/auditoría, `UPDATE` únicamente de `status`, `pin_hash`, `pin_failed_attempts` cuando proceda, `authorized_by_user_id`, `authorized_at_utc`, `rejected_by_user_id`, `rejected_at_utc`, `cancelled_by_user_id`, `cancelled_at_utc` y `pin_expires_at_utc`. No requiere `INSERT` ni `DELETE` sobre solicitudes; API crea las solicitudes y el historial se conserva.
- **API:** conserva los permisos indispensables para insertar solicitudes, resolver dispositivo/identidad, leer hashes para `password_verify`, actualizar intentos/estado y consumir atómicamente. Estos permisos no se transfieren a Lector.
- Los privilegios de autorización Portal3 sobre sus tablas institucionales y el acceso a estaciones/localidades se mantienen por los mecanismos existentes; no se proponen grants funcionales nuevos.

La factibilidad operativa de SQL directo depende de que Portal3 tenga acceso autorizado a la misma base/esquema y de que su conexión pueda ejecutar las lecturas de ACL/scope requeridas. Eso no se verificó en esta tarea.

## 7. Corte sin escritores administrativos paralelos

No operar el escritor API administrativo y el escritor SQL Portal3 en paralelo. Secuencia propuesta, sujeta a autorización posterior:

1. Preparar Portal3 con lecturas, autorización efectiva, operaciones SQL preparadas, transacciones/locks/readback, auditoría y pruebas; mantenerlo sin escrituras reales.
2. Confirmar esquema real y permisos mínimos en cada ambiente y probar colisión entre autorización, cancelación y validación concurrentes en un entorno autorizado.
3. Ventana coordinada: detener temporalmente operaciones humanas M08; no detener la recepción/validación del Lector sin una decisión operativa explícita.
4. Cambiar Portal3 a escritor administrativo SQL como único escritor humano, tras desactivar y drenar todas las instancias del escritor API. El control `CHECADOR_M08_HUMAN_WRITER` acepta exactamente `API` o `PORTAL3`; ausente equivale a `API`, y cualquier otro valor deniega las mutaciones. Con `PORTAL3`, las rutas siguen registradas y devuelven 503 `ADMINISTRATIVE_WRITER_UNAVAILABLE` sin iniciar transacción. El control es local por proceso: confirmar configuración efectiva homogénea en todas las instancias y drenar operaciones en curso antes de habilitar Portal3.
5. Verificar estado/readback y autoría. Mantener API `POST /requests` y `/validate` activos para Lector.
6. Monitorear concurrencia, errores, expiraciones y conciliación de historial antes de declarar el corte estable.

Reversión: pausar de nuevo las acciones humanas, asegurar que no quede una transacción en curso, restaurar el escritor administrativo único anterior, comprobar filas/estados bajo read-only y reabrir operaciones tras conciliar. No reactivar ambos escritores a la vez. El rollback no elimina solicitudes, PIN hashes ni historial.

## 8. Pruebas de aceptación previas al corte

Portal3/API con el mismo esquema y fixtures sintéticos autorizados:

1. Actor activo, puesto y permiso Checador efectivos; usuario sin permiso, denegación individual, Dirección sólo lectura y usuario inactivo.
2. Jurisdicción propia, estación ajena, estación/localidad sin mapeo o ambigua; el cliente no puede ampliar scope.
3. Consulta de cola e historial sin seleccionar/exponer `pin_hash`.
4. PIN seis caracteres con cero inicial, hash bcrypt compatible, visualización una sola vez y no-cache; ningún PIN/hash en logs.
5. Solicitud/PIN vencidos, rechazada, cancelada, bloqueada, usada; tres intentos y uso repetido.
6. `authorize` concurrente con `cancel`, `reject`, nueva solicitud y `validate`; resultado serializado por InnoDB y una única transición ganadora.
7. Lost ACK de solicitud y validación; lost ACK de autorización no recupera el PIN y ofrece el flujo seguro de cancelación/nueva solicitud.
8. Comprobar permisos de Portal3 por columna, permisos API, readback, rollback ante fallo y que Lector no puede acceder SQL.
9. Confirmar las mismas decisiones de ACL/scope en Portal3 y API durante la transición y probar la ruta API mutante deshabilitada antes de habilitar SQL Portal3.

## 9. Preguntas que requieren aprobación antes de implementar

1. Completar implementación compatible de Portal3 y verificar equivalencia de ACL/scope con API.
2. Verificar el esquema, los grants efectivos y la concurrencia real; aplicar únicamente permisos autorizados y necesarios.
3. Autorizar y ejecutar por separado el corte operativo coordinado y, posteriormente, cualquier paso de producción.

## 10. Archivos de referencia y estado

Este addendum formaliza las decisiones aprobadas para preparación local. No modifica `TEMPORARY_ACCESS_API_CONTRACT.md`, Architecture Freeze ni el addendum ACL. La sección histórica “NOT YET IMPLEMENTED” del contrato base se conserva hasta una futura revisión formal; no editarla unilateralmente.
