# Checador V2 — contrato de administración biométrica e identidades V1.1

**Autorización funcional del propietario:** 2026-11-25. **Estado del documento:** aprobado para desarrollo; operación y gates permanecen desactivados hasta validación técnica y física. Este addendum coordina API, Portal3 y Lector Windows. Architecture Freeze V1.0 y los contratos existentes se conservan; este addendum amplía únicamente el uso de sesión administrativa para las tres rutas biométricas descritas.

## 1. Límites entre proyectos

- **Portal3** crea trabajadores mediante sus escritores SQL autorizados, incluyendo `op_rh_personal`, `op_rh_personal_localidad_version` y `op_rh_personal_acceso` (`huella=''`, `pin=0`). Portal3 no llama a API para alta, horarios, turnos o consultas de asistencia. Sus consultas leen información persistida mediante sus límites autorizados.
- **API** autentica dispositivos, sincroniza identidades V2, administra plantillas biométricas y recibe marcaciones. API no crea trabajadores ni filas de acceso.
- **Lector Windows** se comunica con API para sincronizar identidades, descargar el roster y realizar operaciones administrativas en línea.
- Las lecturas de jornada/asistencia de Portal3 no ejecutan el Motor desde Portal3 y no dependen de llamadas Portal3 → API.

## 2. Autorización común

Las rutas de este contrato requieren HTTPS y Device Auth (`X-Device-Id`, `X-Credential-Key`, `Authorization: Bearer <device_secret>`). El servidor valida dispositivo activo, credencial actual vigente, estación física activa y exactamente un grant biométrico vigente asociado a ese dispositivo, estación y credencial.

Las rutas de listado, estado y escritura requieren además `X-Administrative-Session` con una sesión administrativa activa, no expirada y dentro del límite de inactividad. Se revalidan dentro de la transacción el usuario activo, puesto Encargado (6), el permiso institucional efectivo de edición del Checador y la jurisdicción vigente de la estación; las denegaciones individuales explícitas de los permisos específicos prevalecen. No se crean permisos nuevos. La sesión PIN por sí sola no concede autorización biométrica; el permiso y el grant continúan siendo obligatorios.

La sincronización de identidades usa solo Device Auth y grant biométrico vigente. No requiere PIN ni sesión administrativa.

La autorización, identidad, pertenencia canónica y alcance se revalidan también en reintentos idempotentes. Un reintento no recupera una respuesta si el actor, dispositivo, credencial, grant, sesión o trabajador dejaron de ser autorizados.

## 3. Fuente y alcance del trabajador

La API resuelve `external_worker_reference` a una única identidad V2 actual del dispositivo y a un `op_rh_personal.id` único. Exige simultáneamente `op_rh_personal.estado=1`, `op_rh_personal.id_estacion` igual a la estación física autenticada y una pertenencia canónica vigente y única en `op_rh_personal_localidad_version`, correspondiente a la localidad canónica 1:1 de esa estación.

La identidad vigente debe coincidir en dispositivo, referencia externa y trabajador; identidades ausentes, históricas, duplicadas, traslapadas o con trabajador diferente se rechazan. La sincronización conserva identidades actuales únicas y crea UUID V4 únicamente si el trabajador no tiene historial V2. Conflictos se reportan sin modificar identidades históricas. Esta operación no cambia las reglas de baja, reactivación o traslado.

## 4. Revisión y secreto de operación

La revisión es un token opaco `b1.<key_id>.<mac_base64url>`. El MAC HMAC-SHA-256 se calcula con separación de dominio sobre dispositivo, trabajador, contador monotónico de mutaciones confirmadas y bytes de la plantilla vigente (vacíos cuando no existe plantilla). El contador es `MAX(biometric_revision)+1` en la tabla de operaciones, serializado por el bloqueo del trabajador. Así, restaurar bytes de una plantilla anterior **no** restaura una revisión previa.

La clave privada se configura solo en API mediante `CHECADOR_DEVICE_OPERATION_HMAC_KEY_RING` (JSON `{key_id: base64_de_32_bytes}`) y `CHECADOR_DEVICE_OPERATION_ACTIVE_KEY_ID`. Se usa HMAC con etiquetas de dominio distintas para revisión y fingerprint idempotente. No se guarda plantilla ni digest biométrico directo. Las claves antiguas se conservan mientras existan filas o revisiones que las referencien; si falta una clave requerida, la API falla cerrada con 503.

## 5. Listado administrativo

`GET /api/v2/devices/station/admin-workers?limit=100&offset=0`

Requiere Device Auth, grant, sesión y autorización efectiva. `limit` entero de 1 a 200; `offset` entero no negativo. Orden estable por `id_personal`. Solo incluye personal activo, con pertenencia canónica vigente e identidad única actual de ese dispositivo. No retorna ID interno, PIN, secretos ni plantilla.

```json
{
  "code": 200,
  "status": "OK",
  "data": {
    "workers": [{
      "external_worker_reference": "ref-externa",
      "nombre": "Nombre",
      "biometric_status": "NOT_ENROLLED",
      "revision": "b1.k1.mac-opaco"
    }],
    "limit": 100,
    "offset": 0
  }
}
```

La lectura no prolonga la inactividad de la sesión.

## 6. Estado biométrico individual

`GET /api/v2/devices/station/admin-workers/{external_worker_reference}/biometric-status`

La respuesta requiere exactamente una fila existente de acceso. `NOT_ENROLLED` significa que `huella` es `NULL` o vacía; `ENROLLED` significa plantilla Base64 válida dentro del límite. Cero o más de una fila, contenido inválido o identidad ambigua producen conflicto, nunca ausencia.

```json
{
  "code": 200,
  "status": "OK",
  "data": {
    "external_worker_reference": "ref-externa",
    "biometric_status": "NOT_ENROLLED",
    "revision": "b1.k1.mac-opaco"
  }
}
```

La revisión se emite también para una fila vacía; permite que CREATE detecte escrituras intermedias. Esta lectura tampoco prolonga inactividad.

## 7. Registro inicial y reemplazo

`POST /api/v2/devices/station/admin-workers/{external_worker_reference}/biometric-enrollments`

JSON exacto:

```json
{
  "request_id": "uuid-v4-en-minúsculas",
  "operation": "CREATE",
  "expected_revision": "b1.k1.mac-opaco",
  "template_format": "ZKTECO_10_BASE64",
  "template_base64": "..."
}
```

`expected_revision` es obligatoria en CREATE y REPLACE. CREATE requiere el estado vacío actual. REPLACE requiere una plantilla existente y revisión idéntica a la actual. Ambos modos exigen exactamente una fila de acceso. La revisión obsoleta, estado distinto o conflicto de fila retorna 409; no se crea una fila de acceso como reparación.

El formato aprobado para cable es `ZKTECO_10_BASE64`: Base64 estricto de los bytes exportados por ZKTeco SDK 10.0. El máximo es 2048 bytes decodificados. Los tamaños/prefijos habituales documentados para Live20R no son validaciones obligatorias. La compatibilidad física de captura, almacenamiento, lectura y `DBMatch` aún debe validarse con SDK y Lector reales.

La escritura modifica exclusivamente `op_rh_personal_acceso.huella`; nunca inserta trabajador/acceso ni consulta o modifica `pin`. Dentro de una transacción InnoDB, la API bloquea dispositivo, credencial, grant, sesión, estación/localidad, trabajador, pertenencia, identidad, fila de acceso y registro idempotente en ese orden. Revalida autorización inmediatamente antes del cambio. Utiliza comparación de revisión, `UPDATE ... SET huella = ?` sobre la fila existente, relee el valor, registra el resultado/auditoría y la nueva revisión, actualiza `last_activity_at_utc` de la sesión y confirma todo en el mismo commit. El readback debe coincidir antes del commit; cualquier falla revierte toda la operación.

Respuestas:

- `201 CREATED` para registro inicial.
- `200 UPDATED` para reemplazo.
- Un replay idempotente devuelve el mismo status y body confirmados, con `Idempotent-Replay: true`.
- Códigos de error: `400 BAD_REQUEST`, `401 DEVICE_UNAUTHORIZED`/`ADMIN_SESSION_INVALID`, `403 DEVICE_SCOPE_DENIED`/`ADMIN_PERMISSION_DENIED`, `404 WORKER_NOT_FOUND`, `409 IDEMPOTENCY_CONFLICT`/`REVISION_CONFLICT`/`ACCESS_ROW_CONFLICT`/`IDENTITY_CONFLICT`, `413 PAYLOAD_TOO_LARGE`, `415 UNSUPPORTED_MEDIA_TYPE`, `422 INVALID_BIOMETRIC_TEMPLATE`, `503 BIOMETRIC_ADMIN_UNAVAILABLE`.

## 8. Idempotencia durable y auditoría

Las tres familias (`IDENTITY_SYNC`, `BIOMETRIC_CREATE`, `BIOMETRIC_REPLACE`) usan exclusivamente `op_rh_v2_device_operation_requests`. La clave es `(id_dispositivo, request_id)`. El registro contiene operación, estación, credencial, actor y trabajador cuando aplica, HMAC del sobre canónico, resultado HTTP/JSON y secuencia de revisión confirmada. La plantilla y su hash directo nunca se persisten allí.

El sobre biométrico autenticado incluye dispositivo, estación, credencial, `actor_user_id` de la sesión administrativa, trabajador/referencia, operación, request ID, revisión esperada, formato y plantilla Base64. El fingerprint es HMAC-SHA-256 con clave privada. Antes de reproducir, API revalida autorización/scope y exige que el actor actual coincida con el actor persistido; el mismo ID y fingerprint del mismo actor devuelve la respuesta persistida. Reutilizarlo desde otro actor, con otra operación o contenido produce 409 `IDEMPOTENCY_CONFLICT`. Respuestas de negocio 409 también se registran para conservar el resultado del request. Errores de autenticación, formato o infraestructura no se almacenan. La operación confirmada, mutación, readback, auditoría y respuesta durable son atómicas.

Las filas son append-only para runtime API, sin UPDATE/DELETE ni purga automática. El listado de operaciones y su tabla identifican actor, trabajador y revisión sin guardar plantillas. No se registran en logs, auditoría ni mensajes de error plantillas, Base64, hashes directos, secretos o headers de autenticación.

## 9. Sincronización automática de identidades

`POST /api/v2/devices/station/identities/synchronize`, solo Device Auth y grant, JSON exacto:

```json
{"request_id":"uuid-v4-en-minúsculas"}
```

Dentro de la misma transacción compartida revalida estación/localidad y selecciona trabajadores activos con pertenencia canónica vigente. Incluye trabajadores sin huella. Conserva identidad actual única; crea UUID V4 mediante el escritor canónico cuando no hay historial; devuelve conflictos sin alterar historial. Verifica las inserciones con readback y registra el resultado en la tabla compartida. No requiere PIN, sesión, consulta ni respuesta de Portal3.

Éxito `200 OK`:

```json
{"code":200,"status":"OK","data":{"request_id":"...","created":1,"preserved":2,"conflicts":0}}
```

## 10. Escritor legacy, gates y seguridad

El único escritor PHP legacy localizado es `Personal::EditarHUELLA()` detrás de `PUT /editar-huella`. Al activar el gate de escritura biométrica V2, el router bloquea esa ruta para que no eluda revisiones. Cualquier otro escritor a `huella` debe quedar fuera de servicio o coordinar la misma revisión/transacción antes de habilitar V2. La cuenta runtime de API solo necesita `SELECT(id,id_personal,huella)` y `UPDATE(huella)` en acceso; no necesita `pin`, INSERT o DELETE allí.

Los gates `CHECADOR_BIOMETRIC_ADMIN_ENABLED` y `CHECADOR_IDENTITY_SYNC_ENABLED` permanecen apagados por defecto. Las rutas biométricas requieren ambos `CHECADOR_ADMIN_PIN_ENABLED=1` y `CHECADOR_BIOMETRIC_ADMIN_ENABLED=1`; la sincronización requiere su propio gate y no activa el gate administrativo. No se activa ningún gate por este contrato. Cada operación sensible usa `Cache-Control: no-store`; el servicio solo opera en línea.

## 11. DDL y privilegios

El DDL propuesto y exclusivo es `docs/v2/CHECADOR_V2_DEVICE_OPERATION_REQUESTS_DDL_PROPOSAL.sql`. Reemplaza la antigua propuesta `op_rh_v2_device_identity_sync_requests`; no deben crearse ambas. Antes de aplicarlo, comprobar motores InnoDB, tipos/signedness de PK/FK, índices, constraints, datos y privilegios efectivos. API runtime necesita `SELECT, INSERT` en la tabla de operaciones; nunca UPDATE/DELETE allí. La estructura no se ha aplicado por esta integración.

## 12. Criterios antes de habilitar

Lint y pruebas aisladas no equivalen a validación transaccional real. Antes de activar: aplicar DDL aprobado por operador; confirmar SQL least-privilege y PHP-FPM keyring; correr concurrencia/idempotencia/rollback en MariaDB aislada; validar que el legacy writer permanece bloqueado; probar capture/store/readback y `DBMatch` físicamente con el SDK y Lector reales; verificar respuestas por HTTP desde el cliente. Plesk no se modifica ni despliega en esta actividad.
