# CHECADOR API V2 — PRE-PRODUCTION SECURITY CHECKLIST

**API Version:** V2.1  
**Audit Reference:** V2.2-G8-A7  
**Scope:** Verification matrix divided into Code Verified vs Environment Operations

---

## VERIFICACIONES DE CÓDIGO (CODE VERIFIED - PASS)

- [x] **SQL Injection:** Todas las consultas en `modelo/v2/` usan `mysqli_stmt` de forma parametrizada.
- [x] **Custodia de Claves Secretas:** Contraseñas y secretos de dispositivos guardados mediante `password_hash(..., PASSWORD_BCRYPT)`.
- [x] **Custodia de PIN Temporal:** Ningún PIN temporal se almacena en texto plano; se guarda únicamente su hash Bcrypt.
- [x] **Single-View Response:** El PIN temporal se devuelve una sola vez en la respuesta de `/authorize`.
- [x] **No Plaintext Logging:** Verificado en pruebas automatizadas que los PINs y secretos no se imprimen ni registran en logs.
- [x] **Fail-Closed Env:** Ausencia de `JWT_SECRET` provoca HTTP `500` intencional sin asumir secretos por defecto.
- [x] **No Cache Headers:** Encabezados `Cache-Control: no-store` emitidos en las respuestas de controladores V2.
- [x] **UUID Integrity:** Todos los UUIDs entrantes se validan con formato estricto UUIDv4.
- [x] **Station Isolation:** Las consultas a base de datos aplican `id_estacion` extraído de la sesión/token autorizada del supervisor.
- [x] **Lazy Expiration:** Transición atómica a `EXPIRED` de registros vencidos en cada interacción.
- [x] **Brute-Force Lock:** Bloqueo `LOCKED` automático al tercer intento de validación erróneo.

---

## VERIFICACIONES DE ENTORNO Y OPERACIÓN (ENVIRONMENT TO VERIFY AT DEPLOYMENT)

- [ ] **HTTPS / TLS Configuration:** Verificar que el servidor Web fuerce HTTPS y TLS 1.2+ en la URL `/api/v2/`.
- [ ] **JWT_SECRET Provisioning:** Confirmar que la variable de entorno `JWT_SECRET` se encuentre inyectada de forma segura en PHP-FPM / servidor.
- [ ] **Perimeter Rate Limiting:** Aplicar regla `limit_req` en Nginx/Plesk para proteger `/api/v2/` contra ataques de fuerza bruta.
- [ ] **MySQL Database Permissions:** Asegurar que el usuario de base de datos tenga permisos para tablas `op_rh_v2_*`.
- [ ] **Clock Synchronization:** Garantizar sincronización NTP en el servidor de producción para evitar desviaciones de tiempo UTC.
