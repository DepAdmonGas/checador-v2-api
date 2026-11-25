# Checador V2 — administración biométrica V1.0

**Estado: propuesta coordinada; escrituras y rutas nuevas pendientes de aprobación.** Concilia el contrato administrativo aprobado, la propuesta de alta/PIN/biometría de Portal3 y el roster V1.2. No modifica Architecture Freeze ni habilita operaciones. El contrato administrativo aprobado no especifica inscripción, formato ni persistencia de huellas.

## Autoridad y alcance

Cada operación propuesta requiere Device Auth, credencial y grant biométrico vigentes, sesión administrativa vigente y autorización vigente del Encargado para la acción y la estación. El servidor deriva dispositivo y estación de la credencial, resuelve una única localidad canónica y una identidad V2 vigente inequívoca para el trabajador, verifica personal activo y adscrito a la estación física y niega ambigüedades o cambios de alcance. Ningún identificador de estación enviado por el cliente concede acceso. No se permiten PIN, contraseñas, plantillas o secretos en logs o auditoría; respuestas `Cache-Control: no-store` y TLS obligatorio.

El GET de roster existente incluye personal nativo activo con identidad vigente, con o sin huella; usa autenticación de dispositivo y grant y conserva su contrato propio. No es evidencia de que exista una consulta administrativa con sesión. Comodines no se infieren del legacy.

## Interfaces propuestas para coordinación

- `GET /api/v2/devices/station/admin-workers`: lista trabajadores autorizados sin plantilla: `{"workers":[{"external_worker_reference":"...","worker_id":123,"name":"...","biometric_status":"ENROLLED|NOT_ENROLLED"}]}`. La paginación debe acordarse antes de implementar.
- `GET /api/v2/devices/station/admin-workers/{external_worker_reference}/biometric-status`: `{"biometric_status":"ENROLLED|NOT_ENROLLED","revision":"opaque-or-null"}`. Múltiples filas ambiguas no se presentan como ausencia.
- `POST /api/v2/devices/station/admin-workers/{external_worker_reference}/biometric-enrollments`: `{"request_id":"UUIDv4","mode":"CREATE|REPLACE","expected_revision":null,"template_format":"FORMATO_APROBADO","template_base64":"..."}`. CREATE exige ausencia; REPLACE exige revisión vigente inequívoca. Éxito propuesto 201 `{"request_id":"...","biometric_status":"ENROLLED","revision":"opaque"}` solo tras commit y readback. Conflicto 409, entrada inválida 422, autorización 401/403 y dependencia 503; los envelopes exactos deben coordinarse con Windows.

La sesión se presenta en `X-Administrative-Session`; requiere permisos explícitos separados `checador.biometria.estado`, `checador.biometria.registrar` y `checador.biometria.actualizar`. No se crean ni asignan permisos automáticamente. Una escritura exitosa permite al Lector volver a descargar el roster autorizado. Nunca devolver plantilla por el endpoint administrativo.

## Persistencia y decisiones pendientes

El roster lee `op_rh_personal_acceso.huella` agrupando por trabajador y entrega plantilla únicamente si existe exactamente una fila de acceso; múltiples filas producen plantilla nula. Un valor nulo o Base64 inválido tampoco demuestra por sí solo que sea seguro crear una fila. No alterar PIN ni otras columnas. Deben verificarse estructura real, InnoDB, índices, columnas requeridas, duplicados y todos los escritores legacy; el bloqueo debe coordinarse con ellos o la escritura permanecerá deshabilitada.

1. **Formato:** aprobar el identificador y representación exacta de plantilla exportada por Live 20R y tamaño máximo, demostrando compatibilidad real con el Lector y las huellas existentes. Base64 válido y límite actual de 2048 bytes en roster no demuestran compatibilidad del SDK.
2. **Persistencia y concurrencia:** aprobar si se permite actualizar exclusivamente `huella` en una única fila existente, cómo tratar cero o varias filas y cómo coordinar todos los escritores. Definir revisión opaca y comparación atómica para impedir pérdida de actualizaciones. Si los escritores existentes no participan, no afirmar exclusión mutua.
3. **Idempotencia durable:** identificar una estructura existente que garantice unicidad por dispositivo y `request_id`, con resultado y HMAC del payload bajo clave privada, o aprobar un esquema aditivo separado. La restricción actual prohíbe crear tablas. No guardar plantilla ni digest sin clave.
4. **ACL y auditoría:** aprobar las tres capacidades específicas y su fuente, precedencia y registro de decisiones; sin asignación automática. Verificar retención, cifrado en reposo y permisos SQL efectivos.
5. **Contrato físico:** aprobar las rutas y DTO anteriores, límites de paginación y errores coordinados con Portal3 y Windows. El documento de Portal3 los presenta como propuestas; no son endpoints existentes.

Hasta resolver esas decisiones solo se puede usar el roster publicado en su alcance actual; no se implementan escrituras ni fallback legacy.
