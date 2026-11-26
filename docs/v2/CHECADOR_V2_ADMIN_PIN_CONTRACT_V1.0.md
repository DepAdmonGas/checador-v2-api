# Checador V2 — contrato PIN administrativo V1.0

**Reglas aprobadas por el propietario: 2026-11-25.** Alcance: configuración administrativa; no autoriza marcación ni inscripción biométrica. Architecture Freeze V1.0 y el contrato congelado de PIN de asistencia permanecen vigentes. Este contrato coordina API, Portal3 y Lector; la disponibilidad efectiva depende de implementación y validación separadas.

## Autorización y emisión

Portal3 emite y revoca mediante SQL, con sesión institucional y CSRF. El emisor debe estar activo, ser Encargado (puesto 6), disponer de permisos institucionales efectivos y jurisdicción de estación, con permiso institucional de edición del Checador, sin exigir grants positivos adicionales para `checador.admin_pin.emitir` o `checador.admin_pin.revocar`. Las denegaciones individuales prevalecen. Dirección consulta sin emitir ni revocar. La API revalida independientemente usuario, permisos y jurisdicción; requiere dispositivo activo, credencial vigente y grant biométrico vigente durante el canje y toda la sesión. No se conceden permisos automáticamente.

El PIN es aleatorio, de seis dígitos, válido quince minutos UTC y de un solo uso. Durante la transición se acepta hasta su vencimiento original un PIN emitido con vigencia de cinco minutos. Se muestra una sola vez y solo se almacena un verificador resistente a fuerza bruta protegido por secreto compartido privado. No se almacenan ni registran PIN, credenciales o secretos en claro. La reemisión no reinicia límites. Existe como máximo un PIN pendiente por dispositivo.

## Límites y sesión

Tres fallos acumulados por dispositivo durante 15 minutos bloquean el dispositivo durante 15 minutos, conservando el contador entre reemisiones. Adicionalmente, 60 fallos por IP en una ventana móvil de 15 minutos bloquean nuevos intentos hasta que expire la ventana. El almacenamiento del limitador por IP es privado, persistente, compartido entre procesos y protegido contra concurrencia; ante error se deniega. Se usa `REMOTE_ADDR`; ninguna cabecera de proxy es confiable sin configuración explícita y validada. No se registran secretos.

El canje consume el PIN y crea la sesión atómicamente; se permite una sesión activa por dispositivo. El secreto de sesión consiste en 32 bytes aleatorios, se entrega una sola vez por TLS y solo se conserva su SHA-256. La sesión dura como máximo cinco minutos, con límite de inactividad de dos minutos. Consultar estado no renueva actividad. Revocación, cierre, rotación o revocación de credencial invalidan la sesión. El cierre es idempotente para la misma sesión y credencial vigente. Un canje cuya respuesta se pierde no recupera el secreto: se revoca la sesión incierta y se solicita otro PIN.

## Interfaces API

Todas requieren Device Auth y TLS; la sesión se presenta en `X-Administrative-Session`, nunca en URL o logs. Respuestas sensibles `Cache-Control: no-store`.

- `POST /api/v2/devices/station/admin-sessions/redeem`: `{"pin":"012345","request_id":"UUIDv4"}`. Éxito 201 con `session_id`, `session_secret`, `expires_at_utc` e `idle_timeout_seconds:120`; 401 `ADMIN_DENIED`, 409 `SESSION_ACTIVE`, 429 `RATE_LIMITED`, 503 `ADMIN_UNAVAILABLE`.
- `POST /api/v2/devices/station/admin-sessions/validate`: valida sesión sin extender inactividad; devuelve estado y vencimiento.
- `POST /api/v2/devices/station/admin-sessions/close`: `{"request_id":"UUIDv4"}`; devuelve `state: CLOSED`, también en repetición autorizada.

`request_id` en canje y cierre es correlación, no recuperación del secreto. El PIN de asistencia y el administrativo tienen fines, verificadores y tablas separados. No se definen aquí formatos, rutas ni persistencia de inscripción o reemplazo de huella.
