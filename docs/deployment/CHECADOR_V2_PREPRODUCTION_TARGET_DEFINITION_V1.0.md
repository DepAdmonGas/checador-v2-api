# CHECADOR V2 — PREPRODUCTION TARGET DEFINITION & GOVERNANCE RECONCILIATION

**Document Version:** V1.0  
**Status:** DRAFT_READY_FOR_OWNER_APPROVAL  
**Date:** 2026-10-15  
**Primary Project:** `portal-api-v.1.0.1`  
**Related Projects:** `Portal3`, `Lector`  

---

## 1. PROPÓSITO Y ALCANCE

Este documento formaliza la definición canónica del entorno de **Preproducción (Staging)** para Checador V2, registra las decisiones de gobernanza aprobadas formalmente por la Dirección/Project Owner, y establece el paquete técnico exacto para el traspaso (*handoff*) hacia el equipo de Infraestructura.

Este documento no ejecuta mutaciones físicas sobre servidores, DNS, certificados TLS, bases de datos o código runtime.

---

## 2. DECISIONES DE GOBERNANZA APROBADAS POR EL OWNER

Las siguientes decisiones han sido formalmente aprobadas y resueltas por el Project Owner:

1. **Objetivo Oficial de la Demostración del 10 de Diciembre:**
   - **Target Oficial:** `DEDICATED_PREPRODUCTION` (Preproducción Dedicada).
   - La base de datos de desarrollo compartido (`bd_admongas` en dev) **NO** es la sede de la demo.
   - `DEC10_DEMO_TARGET: PREPRODUCTION`
   - `SHARED_DEV_ACL_DEC10_REQUIRED: NO`
   - La falta de configuración ACL de P3-G en desarrollo compartido se reclasifica como deuda técnica de desarrollo (`DEVELOPMENT_UAT_DEBT`) y **no** bloquea la demo del 10 de diciembre.

2. **Estrategia de Certificados TLS (SEC-C):**
   - **Estrategia Preferente:** `LETS_ENCRYPT_AUTOMATED` (cuando DNS público y validación ACME estén disponibles).
   - **Estrategia Fallback:** `CORPORATE_SSL` (si las políticas corporativas exigen certificado wildcard existente).
   - **Requisitos Obligatorios:** TLS 1.2+, HTTPS estricto, redirección forzada 301 de HTTP a HTTPS.

3. **Umbrales de Rate Limiting Perimetral (SEC-D):**
   - Se aprueba el **Candidato B** para **MODELADO EN PREPRODUCCIÓN**:
     - *Ingesta (`/api/v2/punch-events`):* 20 req/s, burst 60.
     - *Autenticación / Acceso Temporal:* 5 req/s, burst 15.
   - `SEC_D_PREPROD_MODEL_APPROVED: YES`
   - `SEC_D_FINAL_RC_REVIEW_REQUIRED: YES`
   - La aprobación para modelado en preproducción no constituye tuning definitivo de producción; antes de Release Candidate se revisará el tráfico real y el riesgo de falsos positivos.

4. **Rollout de Terminales Físicas Remotas (SEC-B):**
   - Se autoriza la migración progresiva de las 7 terminales físicas remotas restantes de GET a POST **después** de verificar con éxito el flujo E2E en Preproducción.
   - `SEC_B_ROLLOUT_AUTHORIZED_AFTER_PREPROD_E2E: YES`
   - *Secuencia aprobada:* Preprod API funcional -> Preprod E2E PASS -> Selección de primera terminal piloto remota -> Verificación de telemetría -> Rollout progresivo -> Apagado definitivo de GET antes de Cutover a Producción.

5. **Línea de Tiempo y Relevancia de V2.6:**
   - `V2_6_CANONICAL_TIMELINE: 2026-12-07 a 2026-12-11` (Master Schedule 2026).
   - `V2_6_DEC10_RELEVANCE: NON_BLOCKING`.
   - V2.6 es un adaptador de comparación en sombra contra nómina legacy; no altera resultados y no forma parte del camino crítico de la demo operativa del 10 de diciembre.

6. **Deuda ACL de Desarrollo Compartido:**
   - `SHARED_DEV_ACL_DEC10_CLASSIFICATION: NOT_REQUIRED`
   - Clasificada formalmente como `DEVELOPMENT_UAT_DEBT`.

---

## 3. NOMENCLATURA AUTORIZADA Y CONCILIACIÓN DE DOMINIO OFICIAL

> [!IMPORTANT]
> **DECISIÓN DE GOBERNANZA CONFIRMADA POR EL PROJECT OWNER (2026-10-22):**
> `api.admongas.com.mx` es el **dominio oficial y definitivo** para Checador V2.
> El subdominio tentativo preliminar `preprod-api.admongas.com.mx` queda formalmente descartado y no se creará.
> Actualmente, el dominio oficial `api.admongas.com.mx` opera con perfil de preproducción (`CHECADOR_DEPLOYMENT_PROFILE=preproduction`) y base de datos preproductiva aislada (`bd_admongas_v2_preprod`).
> El uso del dominio oficial definitivo NO implica que los datos actuales sean productivos. La activación productiva de Checador V2 será una etapa posterior bajo autorización independiente.

| Recurso | Nomenclatura Autorizada | Estado de Validación |
| :--- | :--- | :--- |
| **API Domain (Oficial Definitivo)** | `api.admongas.com.mx` | **CONFIRMADO:** Operativo en preproducción (`82e9a5d.online-server.cloud`, IP: `74.208.128.52`). |
| **Portal3 Subdomain (Preproducción)**| `preprod-portal.admongas.com.mx` | **PROPUESTO:** Pendiente de alta física por Infraestructura para demo/staging. |
| **Database Schema (Preproducción)** | `bd_admongas_v2_preprod` | **CONFIRMADO:** Creado y activo en MariaDB 10.11.19. |

---

## 4. TOPOLOGÍA ARQUITECTÓNICA DE PREPRODUCCIÓN

```text
  [ Terminal Lector Preprod / Test ]
                 │
                 │ HTTPS (TLS 1.2+) + Device Credentials (Bearer Bcrypt)
                 ▼
       [ Preproduction API ]
       (api.admongas.com.mx - Profile: preproduction)
        │                       ▲
        │ MySQL DML             │ Server-to-Server JWT
        ▼                       │ (iss=portal3_auth, sub=tb_usuarios.id)
 [ Isolated Preprod DB ]        │
 (bd_admongas_v2_preprod)       │
                                │
                    [ Portal3 Preproduction ]
                    (preprod-portal.admongas.com.mx / Local Fedora)
                                ▲
                                │ HTTPS
                                │
                        [ Browser / Usuario ]
```

### Reglas Estrictas de Límites de Confianza:
- `BROWSER_DIRECT_API: NO` (El navegador nunca interactúa directamente con el API V2; todo acceso es vía Portal3).
- `PORTAL3_DEVICE_AUTH: NO` (Portal3 no utiliza Device Auth; utiliza exclusivamente JWT server-to-server).
- `LECTOR_PORTAL3_JWT: NO` (Lector utiliza exclusivamente Device Auth Bearer + Bcrypt; no maneja JWT).
- `PRODUCTION_DB_CONNECTION: NO` (Preproducción tiene estrictamente prohibido conectarse a la base de producción).

---

## 5. AISLAMIENTO ESTRICTO DE BASE DE DATOS

La base de datos preproductiva `bd_admongas_v2_preprod` debe cumplir:
1. **Aislamiento Total:** Separada físicamente o lógicamente de `bd_admongas` (producción), `bd_admongas` (desarrollo compartido) y `bd_admongas_v2_test_20261002` (pruebas locales).
2. **Cero Foreign Keys Cruzadas:** Prohibida cualquier llave foránea hacia esquemas externos.
3. **Cero Escrituras a Producción:** Ningún componente de preproducción tiene credenciales hacia bases productivas.
4. **Cero Escrituras Directas de Portal3 en V2:** Portal3 nunca escribe directamente en tablas `op_rh_v2_*`; todas las mutaciones administrativas se canalizan por el API V2.

---

## 6. LÍNEA BASE DE MIGRACIONES APROBADAS (15 MIGRACIONES)

Se desplegarán exclusivamente las **15 migraciones aditivas activas**, en estricto orden cronológico:

1. `migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql`
2. `migraciones/v2/2026_10_01_000001_create_v2_temporary_access_tables.sql`
3. `migraciones/v2/2026_10_02_000002_add_integrity_seal_to_raw_punches.sql`
4. `migraciones/v2/2026_10_02_000003_add_raw_punch_immutability_triggers.sql`
5. `migraciones/v2/2026_10_02_000004_add_raw_punch_read_index.sql`
6. `migraciones/v2/2026_10_05_000001_create_v2_read_snapshot_tables.sql`
7. `migraciones/v2/2026_10_05_000002_create_v2_level2_server_tables.sql`
8. `migraciones/v2/2026_10_06_000001_create_v2_shift_scheduling_tables.sql`
9. `migraciones/v2/2026_10_07_000001_create_v2_journey_pairing_tables.sql`
10. `migraciones/v2/2026_10_07_000002_create_v2_journey_calculation_tables.sql`
11. `migraciones/v2/2026_10_07_000003_create_v2_administrative_incidences_tables.sql`
12. `migraciones/v2/2026_10_07_000004_create_v2_administrative_provenance_tables.sql`
13. `migraciones/v2/2026_10_09_000001_widen_snapshot_event_uuid.sql`
14. `migraciones/v2/2026_10_09_000002_add_snapshot_member_raw_reference_integrity.sql`
15. `migraciones/v2/2026_10_12_000001_create_v2_workplace_timezone_authority.sql`

**Exclusión Explícita:**
- `2026_10_07_000005_create_v2_parallel_comparison_tables.sql` (V2.6): **EXCLUIDA** hasta la activación de la comparación en paralelo.
- `V2_6_MIGRATION_EXCLUDED: YES`
- **Modificaciones estructurales a tablas legacy:** `0`.

---

## 7. MATRIZ DE MÍNIMO PRIVILEGIO (SEC-E)

Se crean dos principales de base de datos con responsabilidades segregadas:

| Principal | Propósito | Privilegios Otorgados | Privilegios Prohibidos | Alcance |
| :--- | :--- | :--- | :--- | :--- |
| `portal_api_migrator` | Ejecución de migraciones DDL | `CREATE`, `ALTER`, `DROP`, `INDEX`, `REFERENCES`, `TRIGGER`, `CREATE VIEW`, `SHOW VIEW`, `SELECT`, `INSERT`, `UPDATE`, `DELETE` | `GRANT OPTION`, `SUPER`, privilegios sobre otros esquemas | `bd_admongas_v2_preprod.*` |
| `portal_api_app` | Conexión runtime de la aplicación API | `SELECT`, `INSERT`, `UPDATE` (y `DELETE` donde sea estrictamente necesario para tablas transitorias) | `CREATE`, `DROP`, `ALTER`, `INDEX`, `TRIGGER`, `SUPER`, `FILE`, `GRANT OPTION` | `bd_admongas_v2_preprod.*` |

---

## 8. INVENTARIO DE SECRETOS Y CONFIGURACIÓN

Variables de entorno requeridas en el host de preproducción (sin imprimir valores):

1. `DB_HOST`: Host/IP del servidor MySQL interno.
2. `DB_PORT`: Puerto de MySQL (por defecto 3306).
3. `DB_NAME`: Nombre del esquema (`bd_admongas_v2_preprod`).
4. `DB_USER`: Usuario runtime (`portal_api_app`).
5. `DB_PASS`: Contraseña criptográficamente segura de `portal_api_app`.
6. `JWT_SECRET`: Clave compartida para validación de tokens Portal3 (mínimo 32 caracteres aleatorios).
7. `HMAC_SEAL_SECRET`: Clave para firma y sellado de marcaciones crudas V2 (`op_rh_v2_marcaciones_crudas`).
8. `ENVIRONMENT` / `APP_ENV`: Declarado como `preproduction`.
9. `API_BASE_URL`: URL base configurada en Portal3 (`https://api.admongas.com.mx/api/v2`).
10. `PHP_ERROR_LOG`: Ruta absoluta al archivo de log de errores del sistema.
11. Configuración de terminales: `credential_key` y hash Bcrypt generados para la terminal de pruebas Lector en `op_rh_v2_dispositivo_credenciales`.

---

## 9. VERIFICACIONES DE SEGURIDAD OPERATIVA (SEC-F)

- **SEC-F01 (Sanitización HTTP 500):** CERRADO_VERIFICADO (Cero reflejo de excepciones en respuestas HTTP).
- **SEC-F02 (Configuración de Errores PHP):** Verificar en runtime que `display_errors = Off` y `display_startup_errors = Off` en el host PHP-FPM.
- **SEC-F04 (Ofuscación de Logs de Acceso):** Configurar Nginx/Webserver para ofuscar query strings en logs de acceso sobre `/acceso-empresa` para prevenir fuga de contraseñas de terminales legacy.
- **Verificación Fail-Closed:** Comprobar que `/api/v2/punch-events` responda `405 Method Not Allowed`, peticiones sin token respondan `401 Unauthorized`, y la falta de `JWT_SECRET` falle cerrado en `500`.

---

## 10. LISTA DE CONTROL DE APROVISIONAMIENTO (22 PASOS NO EJECUTADOS)

1. Aprovisionar Host/VM Linux dedicado.
2. Validar versión de SO, PHP 8.x nativo y extensiones (`mysqli`, `json`, `random`).
3. Confirmar registros DNS tipo A para `api.admongas.com.mx` y `preprod-portal.admongas.com.mx`.
4. Configurar Web Server (Nginx virtual hosts y reglas de reescritura para `/api/v2/`).
5. Tramitar e instalar certificado TLS 1.2+ (Let's Encrypt o corporativo) con redirección HTTPS forzada.
6. Crear base de datos aislada `bd_admongas_v2_preprod` con `utf8mb4_unicode_ci`.
7. Crear usuarios `portal_api_migrator` (DDL) y `portal_api_app` (DML).
8. Aplicar las 15 migraciones DDL aprobadas con `portal_api_migrator`.
9. Verificar creación de índices, llaves foráneas y triggers de inmutabilidad en marcaciones crudas.
10. Desplegar código fuente de `portal-api-v.1.0.1` en el DocumentRoot asignado.
11. Inyectar variables de entorno seguras (`JWT_SECRET`, credenciales DB, etc.) en PHP-FPM pool.
12. Desplegar aplicación Portal3 en el entorno preproductivo.
13. Configurar cliente Portal3 apuntando a la URL base de API Preprod con el `JWT_SECRET` correspondiente.
14. Sembrar credenciales de preproducción para la estación de pruebas Lector en base de datos.
15. Configurar una instancia de Lector en laboratorio apuntando al API de Preproducción.
16. Ejecutar smoke tests de API (rutas 405, 401, fail-closed).
17. Ejecutar smoke tests de Portal3 (login, carga de módulo de checador).
18. Ejecutar smoke tests de ingesta Lector -> API (envío de marcación y confirmación ACK).
19. Ejecutar prueba integral E2E (Marcación -> Snapshot L1 -> Ventana L2 -> Jornada -> Incidencia P3-G -> Vista P3-H).
20. Ejecutar verificaciones SEC-F (`display_errors = Off`, ofuscación de access logs).
21. Realizar validación manual UAT responsive en navegadores de escritorio y móviles.
22. Preparar el plan de rollout progresivo SEC-B para las 7 estaciones remotas.

---

## 11. PARÁMETROS DE INFRAESTRUCTURA AÚN REQUERIDOS (HANDOFF INPUTS)

El equipo de Infraestructura / Operaciones debe suministrar los siguientes parámetros físicos para habilitar la ejecución del aprovisionamiento:

1. **Proveedor y Ubicación del Host:** Proveedor de VM/VPS (ej. AWS, Linode, On-Premise, VPS local).
2. **Dirección IP del Servidor:** IP pública (para acceso DNS) e IP privada.
3. **Acceso de Administración / SSH:** Método de conexión (SSH key, puerto, usuario, IP bastion o VPN).
4. **Distribución y Versión de Linux:** (ej. Rocky Linux 9, Ubuntu 22.04 LTS, RHEL 9).
5. **Panel / Zona de Gestión DNS:** Administrador o consola donde residen los DNS autoritativos de `admongas.com.mx`.
6. **Confirmación de Subdominios:** Aprobación física del alta de `preprod-portal.admongas.com.mx` (y confirmación del DocumentRoot de `api.admongas.com.mx`).
7. **Servidor y Puerto de Base de Datos:** Host/IP interno de la instancia MySQL/MariaDB y puerto de conexión.
8. **Confirmación del Nombre de Base de Datos:** Ratificación de `bd_admongas_v2_preprod`.
9. **Mecanismo de Despliegue de Código:** Método acordado para desplegar código (Git clone / pull, rsync vía SSH, pipeline CI/CD).
10. **Directorio Raíz de Despliegue:** Ruta absoluta en el servidor (`/var/www/vhosts/admongas.com.mx/api.admongas.com.mx`).
11. **Software de Servidor Web:** Elección definitiva de Nginx standalone vs Apache con PHP-FPM.
12. **Mecanismo de Desafío TLS:** Confirmación del método de validación ACME (HTTP-01 en puerto 80 o DNS-01).
13. **Capacidad de Snapshot / Backup:** Confirmación de respaldos automatizados previos a la puesta en marcha.

---

## 12. ESTADO DE PREPARACIÓN RECONCILIADO

- **DEC10_DEMO_READINESS:** `READY_WITH_DEBT`
  - La selección de Preproducción Dedicada como sede oficial elimina la falta de ACL en desarrollo compartido como bloqueador de la demo.
  - El código funcional de P3-G y P3-H cuenta con 100% de pruebas aprobadas (237/237 API, 746/746 Portal3).
- **PREPROD_PROVISIONING_READY:** `NO` (A la espera de los parámetros de infraestructura del equipo de TI/Infraestructura).
- **DECISIONES DEL OWNER PENDIENTES:** `NINGUNA` (Todas las decisiones arquitectónicas y de gobernanza han sido formalizadas).

---

## 13. SIGUIENTE ACCIÓN

Transferir el presente documento a Infraestructura para la recolección de los parámetros técnicos mediante la actividad:
`CHECADOR_V2_7_PREPRODUCTION_INFRASTRUCTURE_INPUT_COLLECTION`.
