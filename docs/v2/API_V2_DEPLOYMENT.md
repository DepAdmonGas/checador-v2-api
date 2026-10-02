# CHECADOR API V2 — DEPLOYMENT AND OPERATIONAL RUNBOOK

**API Version:** V2.1  
**Deployment Target:** Staging / Production Environments  
**Status:** Pre-Production Guide

---

## 1. REQUISITOS PREVIOS AL DESPLIEGUE

Antes de autorizar el despliegue en un entorno de pruebas o producción, verificar:

1. **Aprobación Formal:** Confirmar que la fase actual en `docs/status/CHECADOR_V2_IMPLEMENTATION_STATUS.md` autorice explícitamente el despliegue (`PRODUCTION_DEPLOYED: YES`).
2. **Suite de Pruebas limpia:** Ejecución de `php tests/run_v2_temporary_access_tests.php` (62 PASS) y `php tests/run_v2_tests.php` (18 PASS).
3. **Variables de Entorno Configuradas:**
   - `JWT_SECRET` presente y con una cadena aleatoria de al menos 32 caracteres.
   - Parámetros de conexión a base de datos validados.

---

## 2. PASOS DE DESPLIEGUE (CHECKLIST DE OPERACIÓN)

```text
[ PASO 1 ] ──> Ejecutar Migraciones DDL Aditivas en BD
               (Crear tablas op_rh_v2_* sin alterar tablas legacy)
                   │
                   ▼
[ PASO 2 ] ──> Desplegar Código PHP en el Servidor de Aplicación
               (modelo/v2/*, index.php, etc.)
                   │
                   ▼
[ PASO 3 ] ──> Configurar Variables de Entorno en Servidor / PHP-FPM
               (setenv JWT_SECRET ...)
                   │
                   ▼
[ PASO 4 ] ──> Aplicar Reglas Nginx / Web Server
               (Aislamiento de cache no-store & Rate Limiting)
                   │
                   ▼
[ PASO 5 ] ──> Ejecutar Smoke Tests de Verificación en Vivo
```

---

## 3. SMOKE TESTS POST-DESPLIEGUE

1. **Health / Guardrail Check:**
   Petición GET a `/api/v2/punch-events` debe retornar `405 Method Not Allowed` o error JSON en lugar de 404.
2. **Fail-Closed Security Check:**
   Petición GET a `/api/v2/temporary-access/requests` sin header Authorization debe retornar `401 Unauthorized`.
3. **JWT Secret Check:**
   Verificar en logs de aplicación que no existan advertencias de `JWT_SECRET missing`.

---

## 4. PLAN DE ROLLBACK (PROCEDIMIENTO DE REVERSIÓN)

En caso de emergencia o falla crítica durante el despliegue:

1. **Reversión de Código:** Revertir los archivos PHP a la versión previa mediante Git / Deploy Tool.
2. **Migraciones Down:** Las tablas `op_rh_v2_*` son totalmente aditivas. Su remoción o coexistencia no altera la operación de las tablas legacy `op_rh_personal` o `op_rh_personal_asistencia`.
3. **Restauración de Nginx:** Revertir el archivo de configuración del servidor web y recargar el servicio (`nginx -s reload`).
