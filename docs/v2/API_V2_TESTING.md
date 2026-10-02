# CHECADOR API V2 — TESTING PROCEDURES MANUAL

**API Version:** V2.1  
**Test Suite Coverage:** 80 Tests Total (18 Ingestion + 62 Temporary Access)  
**Execution Environment:** Local PHP CLI / MySQL Sandbox

---

## 1. EJECUCIÓN DE LA SUITE DE PRUEBAS AUTOMATIZADAS

La suite de pruebas automatizadas valida la sintaxis PHP, el cumplimiento estricto del contrato contractual V1.0, las transiciones de estado, la inmutabilidad de la base de datos legacy y los guardrails de seguridad.

### Comandos de Ejecución:

```bash
# 1. Verificar sintaxis PHP (Lint)
php -l modelo/v2/Portal3JwtAuthHelper.php
php -l modelo/v2/TemporaryAccessV2Controller.php
php -l modelo/v2/TemporaryAccessV2Service.php
php -l modelo/v2/IngestaPunchV2Controller.php

# 2. Ejecutar Pruebas de Contingencia y Acceso Temporal (62 Pruebas)
php tests/run_v2_temporary_access_tests.php

# 3. Ejecutar Pruebas de Ingesta Raw Punch Events (18 Pruebas)
php tests/run_v2_tests.php
```

---

## 2. COBERURA DE PRUEBAS DE ACCESO TEMPORAL (`62 PASS`)

1. **Hashes de Gobernanza intactos (Pruebas 0a, 0b):** Valida SHA-256 de `CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md` y `TEMPORARY_ACCESS_API_CONTRACT.md`.
2. **Creación e Idempotencia (Pruebas 1 - 7):** Registros válidos (201), trabajadores desconocidos (404), conservación de `request_uuid`, reintentos por Lost ACK y revocación automática de solicitudes activas previas.
3. **Listado y Filtros por Estación (Pruebas 8 - 10, 40 - 41):** Filtrado seguro por `Station Scope Enforcement` y rechazo de estaciones no asignadas (403).
4. **Autorización y Generación de PIN (Pruebas 11 - 14, 19 - 21):** Generación de PIN de 6 dígitos una sola vez, hash `Bcrypt` en BD y ausencia de campos planos.
5. **Rechazo y Cancelación (Pruebas 15 - 18):** Transiciones a `REJECTED` y `CANCELLED` con auditoría de usuario.
6. **Validación de PIN en Lector (Pruebas 22 - 32):** Conteo de intentos fallidos, transición atómica a `LOCKED` al 3er intento, expiración por TTL y reintentos idénticos por Lost ACK (`validation_uuid`).
7. **Autenticación JWT de Portal3 (Pruebas 33 - 39):** Verificación de firma HS256, expiración (`exp`), issuer (`portal3_auth`), tipo (`access`) y claim `sub`.
8. **Seguridad & Guardrails (Pruebas 42 - 55c):** Encabezados `Cache-Control: no-store` (SEC-M01), comportamiento Fail-Closed si falta `JWT_SECRET` (SEC-M02), validación sintáctica de UUIDv4 (SEC-L01) y guardrail de prefijo `/api/v2/` en el enrutador.

---

## 3. COBERURA DE PRUEBAS DE INGESTA CRUDAS (`18 PASS`)

1. **Autenticación de Dispositivo (Pruebas 1, 3, 12, 13):** Verificación de firma Bcrypt y rechazo de credenciales revocadas o inválidas.
2. **Idempotencia de Eventos (Prueba 2):** Retorno de `200 ALREADY_RECEIVED` sin duplicar filas.
3. **Resolución de Identidad (Pruebas 4 - 6, 10, 11):** Resolución histórica de personal sin alterar el evento crudo.
4. **Inmutabilidad de Estructuras (Pruebas 8, 9, 17):** Confirmación de que las tablas legacy (`op_rh_personal_asistencia`) no sufren alteraciones.
