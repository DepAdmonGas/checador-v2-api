# Checador V2 — vinculación de dispositivo y roster biométrico V1.2

Estado: decisión del propietario autorizada para desarrollo; esquema propuesto pendiente de revisión y autorización, pruebas MariaDB y Windows pendientes, sin despliegue. Este addendum acota la excepción aprobada el 2026-11-24 al addendum M11 que reservaba el acceso al dominio de dispositivos a la API: Portal3 puede escribir únicamente el token de vinculación y el permiso biométrico descritos aquí, con autorización humana. No modifica Architecture Freeze, contratos congelados ni permisos de otras operaciones. V1.1 sigue vigente mientras `CHECADOR_DEVICE_BIOMETRIC_GRANT_ENABLED` no sea exactamente `1`.

## Identidades, transporte y preparación

El dispositivo existente y su estación física `tb_estaciones.id` son asignados por administración; los campos del cliente no seleccionan estación. Portal3 debe verificar sesión humana activa, permiso explícito para vincular dispositivos y para conceder biometría, y jurisdicción vigente del usuario en la estación canónica mapeada de forma única a la estación física. El usuario no recibe privilegios adicionales por iniciar sesión. Consultar y volver a validar dispositivo activo y estación activa dentro de la transacción de emisión. Sin autorización o mapeo inequívoco, denegar y no generar token ni permiso.

La propuesta DDL es `CHECADOR_V2_DEVICE_LINK_SCHEMA_PROPOSAL_V1.0.sql`. Antes de aplicar, revisar tipos, índices, integridad referencial, privilegios y capacidad transaccional contra el esquema efectivo y obtener autorización explícita. Las tablas son InnoDB; la emisión usa la conexión compartida, sin HTTP a la API. El consumo requiere permiso de lectura/escritura limitado a tokens y credenciales y lectura de dispositivo, estación, localidad y grant. No guardar en logs ni auditoría los secretos, hashes, cabeceras o plantillas.

## Portal3: emisión y revocación

1. Con autorización humana y alcance verificados, iniciar transacción y bloquear `op_rh_v2_dispositivos.id` con `SELECT ... FOR UPDATE`. Verificar `estado=1`, `id_estacion` y estación física activa. Toda emisión/rotación administrativa de credenciales debe coordinarse con este mismo bloqueo.
2. No crear ni ampliar grants al emitir el token. La emisión autoriza que la API cree el grant para la credencial nueva solo al consumirlo. Por tanto, la credencial anterior no adquiere permisos nuevos. Un grant biométrico está asociado además al `id_credencial` y no autoriza PIN ni otras funciones.
3. Generar `tokenHex = bin2hex(random_bytes(32))`: exactamente 64 caracteres ASCII hexadecimales minúsculos. Calcular `token_sha256 = hash('sha256', tokenHex)` sobre los 64 bytes ASCII, no sobre los 32 bytes decodificados. Guardar exclusivamente ese hash minúsculo; nunca almacenar el token en DB o logs.
4. Insertar `id_dispositivo`, `id_estacion`, `emitido_por_usuario_id`, `emitido_at_utc=UTC_TIMESTAMP(6)` y `expira_at_utc=emitido_at_utc+INTERVAL 15 MINUTE`; `consumido_at_utc` y `revocado_at_utc` son NULL. Usar el mismo instante para ambos campos. Confirmar transacción antes de mostrar el token una sola vez mediante interfaz HTTPS sin caché. Prohibido incluirlo en URL, logs, notificaciones o almacenamientos persistentes del navegador.
5. Si se revoca un token, bloquear su fila y registrar `revocado_at_utc`. El endpoint no acepta tokens expirados, revocados o consumidos. En una respuesta perdida o error al guardar en Windows, el propietario emite otro token; no se recupera ni se reutiliza el anterior.

## API: vinculación

`POST /api/v2/devices/link`, HTTPS, `Content-Type: application/json`, límite de cuerpo 1024 bytes. Se habilita solo cuando `CHECADOR_DEVICE_LINK_ENABLED=1`; por defecto la ruta está deshabilitada. Solicitud exacta:

```json
{"link_token":"<64 hex minúsculos>"}
```

El token se transmite solo en el cuerpo. La API calcula SHA-256 del texto ASCII, bloquea token, dispositivo y estación y comprueba vigencia de 900 segundos exactos, no consumo/revocación, dispositivo activo y estación física y localidad inequívocas. En la misma transacción revoca todas las credenciales `ACTIVE`, crea `credential_key` aleatorio de 16 bytes hex y `device_secret` de 32 bytes hex, conserva solo bcrypt del secreto, revoca grants anteriores, crea un grant vinculado a la nueva credencial y marca consumido el token. La restricción única `uk_one_active_device_grant` impide dos grants activos por dispositivo. Un fallo revierte todas las operaciones. El commit ocurre antes de la respuesta y un único consumidor gana. Las identidades de trabajadores y marcaciones no se modifican.

Respuesta 200 (`Cache-Control: no-store`, `Pragma: no-cache`):

```json
{"code":200,"status":"OK","data":{"device_id":2,"device_uuid":"DEV-EJEMPLO","credential_key":"<32 hex>","device_secret":"<64 hex>","id_estacion":1,"station_name":"Interlomas","timezone":"America/Mexico_City"}}
```

Errores JSON: 400 `BAD_REQUEST` para cuerpo o formato inválido; 401 `LINK_DENIED` para token inexistente, vencido, consumido, revocado, estación o dispositivo no autorizados; 405 `METHOD_NOT_ALLOWED`; 415 `UNSUPPORTED_MEDIA_TYPE`; 429 `RATE_LIMITED` con `Retry-After`; 503 `LINK_UNAVAILABLE` para dependencia interna o limitador indisponible. La ruta desactivada retorna 404. El limitador acepta 10 solicitudes por dirección remota en 60 segundos usando `flock`, y falla cerrado si `CHECADOR_DEVICE_LINK_RATE_DIR` no apunta a directorio privado escribible perteneciente al usuario FPM. Las instancias deben compartir almacenamiento con `flock` coherente y validar el manejo de proxy: se usa `REMOTE_ADDR`, no cabeceras del cliente. TLS y pruebas efectivas en FPM siguen pendientes; no existe replay protection adicional.

## Lector Windows

Solicitar el token al propietario y enviar exactamente la solicitud anterior. Validar HTTPS, código 200, tipos, identificador, estación, `credential_key`, secreto y zona horaria. Persistir credenciales de forma atómica con protección del sistema (DPAPI) antes de marcar vinculado; no escribir tokens, secretos ni huellas en logs. Si falla la persistencia, borrar el material parcial, informar fallo y solicitar un nuevo token. El token no se reintenta tras un éxito incierto: puede haber sido consumido y la credencial emitida. Ante pérdida de respuesta o fallo posterior a commit, pedir a Portal3 un nuevo token; la nueva vinculación revocará la credencial anterior al emitir la nueva. Mantener operaciones administrativas y autorización de PIN temporal con sesión humana y ACL existentes; no conservar ni habilitar fallback a rutas legacy.

## Roster V1.2

`GET /api/v2/devices/station/biometric-roster`: enviar `X-Device-Id`, `X-Credential-Key` y `Authorization: Bearer <device_secret>`. Con `CHECADOR_DEVICE_BIOMETRIC_GRANT_ENABLED=1`, no requiere `X-Supervisor-Token`: Device Auth debe estar activa y existe exactamente un grant biométrico vigente no revocado para el dispositivo, la credencial autenticada y su estación física actual. La estación no proviene del cliente. No incluir PIN, contraseñas o hashes. `Cache-Control: no-store`; auditoría sin biométricos ni secretos.

## Revocaciones diferenciadas

Portal3 expone acciones autorizadas y CSRF: `revocar-token` invalida tokens pendientes sin revocar credenciales ni grant; `revocar-biometria` invalida grants y tokens pendientes, conservando autenticación de dispositivo; `revocar` invalida tokens y grants y revoca todas las credenciales activas, impidiendo nuevas autenticaciones. Estas acciones no borran marcaciones ni la cola local del Lector. Una operación iniciada antes de la revocación puede haber autenticado previamente; la siguiente autenticación consulta el estado actualizado.

La respuesta mantiene el envelope y DTO V1.1: `code` entero 200, `status` `OK`, `id_estacion` entero, `station_name` cadena, `total_workers` y `workers_with_biometrics` enteros, `timestamp` ISO 8601, y `workers` arreglo. Cada trabajador contiene `external_worker_reference` cadena, `id_personal`, `no_colaborador` e `id_estacion_adscripcion` enteros, `nombre` y `puesto` cadenas, `es_comodin` y `has_biometric_template` booleanos, `biometric_template` Base64 o null. Se incluyen trabajadores nativos activos con una identidad vigente única de ese dispositivo, con o sin huella; comodines no se infieren del legacy. La lectura deniega con 403 `DEVICE_BIOMETRIC_PERMISSION_DENIED` si falta o se revocó el grant, y 503 ante dependencia no disponible. No hay acceso a otra estación.

V1.2 no declara endpoint operativo; requiere revisión del esquema, tests MariaDB de expiración, consumo concurrente, revocación, rotación, jurisdicción y recuperación, regresiones afectadas, preparación Portal3/Windows y HTTP 200 autorizado desde Windows.
