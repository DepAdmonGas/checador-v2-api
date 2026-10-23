# Checador V2 — API

Servicio centralizado de alta integridad y baja latencia para la ingesta, validación, cálculo y auditoría del registro de asistencia y control de acceso del personal de estaciones de servicio y centros de trabajo del grupo empresarial.

---

## 1. Descripción general

El proyecto **Checador V2 — API** constituye el núcleo de backend para la captura inmutable de eventos biométricos de asistencia (*PunchEvents*), la resolución determinista de horarios de trabajo, la evaluación de jornadas laborales y la gestión de acceso temporal supervisado.

### Objetivo
Proveer una plataforma unificada, trazable y auditable que sustituya de forma progresiva los mecanismos legados de captura de asistencia, garantizando:
- **Inmutabilidad de marcaciones:** Registro de hechos históricos originales sin alteraciones destructivas.
- **Idempotencia estricta:** Tolerancia a reintentos de red desde dispositivos biométricos y aplicaciones cliente sin duplicación de registros.
- **Integridad criptográfica:** Sellado digital HMAC-SHA256 y verificación de firmas de credenciales de dispositivos.
- **Aislamiento institucional:** Validación canónica de permisos funcionales y alcance por estación de servicio.
- **Operación en modo espejo (*Shadow Mode*):** Evaluación y comparación paralela contra nómina legacy sin alterar la operación productiva preexistente.

### Alcance del proyecto
La API Checador V2 atiende exclusivamente la lógica centralizada de persistencia, normalización temporal, emparejamiento de segmentos, cálculo de desviaciones y contratos RESTful. No gestiona directamente hardware de reloj checador (función asignada al proyecto Lector) ni funge como interfaz gráfica principal para usuarios corporativos (función delegada a Portal3).

---

## 2. Arquitectura del sistema

La solución global de control de asistencia opera bajo una arquitectura distribuida conformada por tres componentes independientes y coordinados:

```
┌─────────────────────────────────────────────────────────┐
│               Lector (Dispositivos / Edge)              │
│  - Captura biométrica local en estaciones de servicio   │
│  - Autenticación Device Auth (Bearer Secret + Bcrypt)   │
│  - Reconciliación offline y colas de sincronización     │
└────────────────────────────┬────────────────────────────┘
                             │
                             ▼ POST /api/v2/punch-events
┌─────────────────────────────────────────────────────────┐
│                   Checador V2 — API                     │
│  - Backend PHP 8.x nativo + MySQL / MariaDB             │
│  - Autoridad horaria (Workplace Timezone Authority)     │
│  - Ingesta cruda inmutable, sellos HMAC y snapshots     │
│  - Gatekeeper de entrada anticipada y acceso temporal   │
│  - Resolución de horarios [valido_desde, valido_hasta)   │
│  - Emparejamiento de jornadas (P1) y cálculo (P2)       │
└────────────────────────────▲────────────────────────────┘
                             │
                             │ REST API + JWT Exclusivo
┌────────────────────────────┴────────────────────────────┐
│                    Portal3 (Web Client)                 │
│  - Interfaz de Recursos Humanos, supervisión y nómina   │
│  - Autenticación institucional basada en roles (Puestos)│
│  - Consulta de horarios efectivos y reportes DataTables │
└─────────────────────────────────────────────────────────┘
```

### Responsabilidades por componente

| Componente | Responsabilidad principal | Protocolo de comunicación |
| :--- | :--- | :--- |
| **Checador V2 API** | Autoridad de persistencia, reglas de negocio de asistencia, cálculo de jornadas y seguridad. | HTTP RESTful JSON / HTTPS |
| **Lector** | Hardware de captura, lector biométrico y teclado en estaciones de servicio. | Device Auth (Bearer Secret + Bcrypt) |
| **Portal3** | Cliente administrativo web para gestión de personal, incidencias, horarios y reportes. | Portal3 Auth (Bearer JWT V2 institucional) |

---

## 3. Funcionalidades del sistema

### Ingesta y registro de marcaciones crudas
- **`POST /api/v2/punch-events`:** Ingesta de marcaciones procedentes de dispositivos autorizados.
- **Entidad `PunchEvent`:** Representa un hecho original e inalterable en la tabla `op_rh_v2_marcaciones_crudas`. Nunca se sobrescribe para forzar clasificaciones heurísticas de entrada o salida.
- **Normalización UTC:** Almacenamiento simultáneo de la estampa de tiempo local del dispositivo, la zona horaria efectiva de la estación y la marca de tiempo calculada en UTC.

### Integridad criptográfica e idempotencia
- **Sello digital HMAC-SHA256:** Cada marcación cruda incorpora un sello criptográfico generado sobre un subconjunto canónico normalizado de campos protegidos (`event_uuid`, `id_dispositivo`, `ocurrido_at_utc`, etc.).
- **Tolerancia a fallos e idempotencia:** Identificación única por `event_uuid` (UUIDv4 de 36 caracteres). Solicitudes repetidas con el mismo identificador y carga útil devuelven `200 ALREADY_RECEIVED` conservando el registro intacto; divergencias en datos con el mismo UUID retornan `409 CONFLICT`.

### Snapshots de lectura auditables (Nivel 1)
- **`POST /api/v2/read-snapshots`** y **`GET /api/v2/read-snapshots/{token}`:** Permite a Portal3 congelar vistas consistentes de marcaciones históricas para auditoría y paginación mediante cursores opacos, protegiendo las consultas contra inserciones concurrentes tardías.
- **Manifiestos durables:** Generación de resúmenes de integridad SHA-256 para verificación forense de lotes de marcaciones.

### Verificación de continuidad en ingesta (Nivel 2)
- Detección de saltos (*gaps*) en secuencias emitidas por dispositivos (`device_sequence`).
- Ventanas de sincronización continua que garantizan que el cálculo de jornadas opere únicamente sobre ventanas de datos completamente verificadas (`VERIFIED`).

### Programación de turnos y horarios efectivos
- **Plantillas versionadas:** Definición de turnos fijos o rotativos en `op_rh_v2_turnos_plantillas` con histórico inmutable de versiones.
- **Segmentos arbitrarios:** Soporte para múltiples intervalos de trabajo y descansos intermedios sin límite predefinido de 4 ranuras (*slots*).
- **Cruce de medianoche:** Soporte nativo para turnos nocturnos mediante desplazamientos de día (`dia_offset_inicio`, `dia_offset_fin`).
- **Resolución histórica:** Intervalo semiabierto `[valido_desde, valido_hasta)` en `op_rh_v2_turnos_asignaciones`. Permite reconstruir con fidelidad retrospectiva el horario asignado a un colaborador en cualquier fecha pasada o vigente.
- **Detección de traslapes:** Detección fail-closed ante asignaciones superpuestas para el mismo colaborador, estación y día, devolviendo `409 AMBIGUOUS_SCHEDULE_ASSIGNMENT`.

### Autoridad de zona horaria de centros de trabajo
- **`WorkplaceTimezoneAuthorityService`:** Resolución determinista de la zona horaria canónica IANA (ej. `America/Mexico_City`, `America/Tijuana`, `America/Matamoros`) asociada a cada estación de servicio en una fecha efectiva, desacoplada de la configuración técnica de los dispositivos.

### Gatekeeper de entrada anticipada y auditoría de acceso
- **`POST /api/v2/device/entry-window/evaluate`:** Evaluación en tiempo real para terminales biométricas antes de permitir una marcación. Rechaza intentos con más de 10 minutos de anticipación al inicio programado del turno (`REJECT_EARLY_ENTRY`).
- **`POST /api/v2/device/access-audits`:** Registro auditable e inmutable de rechazos de acceso o intentos denegados, sin consumir secuencias de marcación ni escribir sobre la tabla de eventos crudos.

### Evaluación y cálculo de jornadas laborales
- **Fase P1 — Emparejamiento (`JourneyPairingService`):** Asocia marcaciones reales a los segmentos planificados de la jornada (`EXACT_PAIR`, `PARTIAL_MISSING_IN`, `PARTIAL_MISSING_OUT`, `UNMATCHED_NO_PUNCHES`, `AMBIGUOUS_PAIRING`, `WORKPLACE_MISMATCH`).
- **Fase P2 — Cálculo (`JourneyCalculationService`):** Deriva de forma inmutable los segundos laborados exactos, desviaciones de entrada/salida y tiempo fuera de horario.
- **Clasificación pública de eventos:** Exposición de roles de marcación (`ENTRY`, `EXIT`, `SURPLUS`, `UNMATCHED`) y ordinal de segmento en `POST /api/v2/journeys/evaluate`.
- **Prohibición de inferencias destructivas:** Cero asignación automática de faltas injustificadas o deducciones directas de nómina dentro del motor de captura.

### Acceso temporal mediante PIN de contingencia
- Flujo de solicitud y autorización remota cuando un colaborador no cuenta con registro biométrico activo en la estación.
- Emisión de PIN de 6 dígitos con vigencia temporal limitada, almacenamiento exclusivo mediante hash Bcrypt (sin almacenamiento de PIN plano) y consumo atómico de un solo uso.

---

## 4. Tecnologías y requisitos del sistema

### Requisitos de entorno
- **Lenguaje:** PHP 8.1 o superior (validado en PHP 8.4.x).
- **Motor de Base de Datos:** MariaDB 10.11+ / MySQL 8.0+.
- **Servidor Web:** Apache 2.4+ / Nginx con soporte de PHP-FPM y reescritura de URLs (`mod_rewrite`).
- **Extensiones de PHP obligatorias:**
  - `mysqli` (conector nativo transaccional con consultas preparadas obligatorias).
  - `curl` (comunicación HTTP).
  - `json` (serialización y deserialización de cargas útiles).
  - `mbstring` (manejo de cadenas UTF-8).
  - `openssl` (algoritmos criptográficos HMAC-SHA256 y hashing).

### Dependencias y diseño de código
El proyecto no emplea frameworks monolíticos pesados, garantizando bajo consumo de memoria, tiempos de respuesta mínimos en hardware de borde y mantenibilidad a largo plazo:
- **Enrutamiento:** Procedural modular en `index.php` con inspección de segmentos de ruta URI y verbos HTTP.
- **Servicios de dominio:** Clases orientadas a objetos con tipado estricto (`declare(strict_types=1);`), transacciones atómicas InnoDB y separación de responsabilidades en `modelo/v2/`.

---

## 5. Configuración de desarrollo

### Instalación local

1. Clonar el repositorio en el directorio de trabajo local:
   ```bash
   git clone https://github.com/DepAdmonGas/checador-v2-api.git
   cd checador-v2-api
   ```

2. Configurar el servidor web local (Apache o PHP dev server) apuntando la raíz del documento al directorio del proyecto.

3. Configurar variables de entorno locales:
   La API consume las credenciales de base de datos y llaves criptográficas directamente desde el entorno del sistema operativo o directivas de servidor (PHP-FPM / Apache `SetEnv`).

### Variables de entorno reconocidas

| Variable | Descripción | Ejemplo de desarrollo local |
| :--- | :--- | :--- |
| `DB_HOST` | Host del servidor MariaDB/MySQL | `127.0.0.1` |
| `DB_USER` | Usuario de base de datos | `root` |
| `DB_PASS` | Contraseña de base de datos | `tu_contraseña_local` |
| `DB_NAME` | Nombre de la base de datos | `bd_admongas_v2_test_20261002` |
| `JWT_SECRET` | Llave secreta para tokens Portal3 JWT | `secreto_jwt_desarrollo_local` |
| `HMAC_KEY_CURRENT` | Llave activa para sellado HMAC de marcaciones | `llave_hmac_local_pruebas` |
| `HMAC_KEY_ID_CURRENT` | Identificador de la llave activa de sellado | `key_dev_v1` |
| `CHECADOR_DEPLOYMENT_PROFILE` | Perfil de despliegue activo | `development` / `preproduction` |

> [!CAUTION]
> **Protección de credenciales:** Nunca almacenes credenciales reales de preproducción o producción en archivos versionados ni declares contraseñas en texto plano dentro del código fuente.

---

## 6. Pruebas automatizadas

El repositorio cuenta con suites de pruebas funcionales, unitarias y de integración desarrolladas en PHP nativo que validan el comportamiento determinista y fail-closed de los servicios.

### Ejecución de pruebas locales

Para ejecutar las pruebas en un entorno local con base de datos de pruebas (`bd_admongas_v2_test_20261002`):

```bash
# 1. Resolución de horarios históricos e intervalos semiabiertos (V8C_R1)
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_pass DB_NAME=bd_admongas_v2_test_20261002 php tests/v2_historical_schedule_resolution_tests.php

# 2. Programación de turnos, plantillas y segmentos
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_pass DB_NAME=bd_admongas_v2_test_20261002 php tests/v2_shift_scheduling_runtime_tests.php

# 3. Políticas institucionales de autorización y alcance por puesto
php tests/v2_operational_authorization_policy_tests.php

# 4. Autorización funcional para lectura de horarios efectivos
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_pass DB_NAME=bd_admongas_v2_test_20261002 php tests/v2_effective_schedule_authorization_runtime_tests.php

# 5. Integridad de marcaciones crudas y sellos HMAC
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_pass DB_NAME=bd_admongas_v2_test_20261002 php tests/v2_punch_integrity_ingestion_tests.php
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_pass DB_NAME=bd_admongas_v2_test_20261002 php tests/v2_punch_integrity_tests.php

# 6. Snapshots de lectura y manifiestos auditables (Nivel 1)
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_pass DB_NAME=bd_admongas_v2_test_20261002 php tests/v2_snapshot_read_api_tests.php

# 7. Capacidades del servidor Nivel 2 (continuidad y telemetría)
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_pass DB_NAME=bd_admongas_v2_test_20261002 php tests/v2_level2_server_api_tests.php

# 8. Emparejamiento (P1) y cálculo (P2) de jornadas laborales
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_pass DB_NAME=bd_admongas_v2_test_20261002 php tests/v2_journey_pairing_runtime_tests.php
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_pass DB_NAME=bd_admongas_v2_test_20261002 php tests/v2_journey_calculation_runtime_tests.php

# 9. Gatekeeper de entrada anticipada y auditoría de acceso
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_pass DB_NAME=bd_admongas_v2_test_20261002 php tests/v2_device_entry_window_api_tests.php
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_pass DB_NAME=bd_admongas_v2_test_20261002 php tests/v2_device_access_audit_api_tests.php

# 10. Flujo de acceso temporal con PIN de contingencia
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_pass DB_NAME=bd_admongas_v2_test_20261002 php tests/run_v2_temporary_access_tests.php

# 11. Validación de sintaxis PHP (Linting general)
find . -type f -name "*.php" ! -path "*/vendor/*" -exec php -l {} +
```

---

## 7. Políticas de seguridad

### Autenticación
1. **Device Authentication (Lector / Edge):**
   - Esquema HTTP Bearer utilizando identificador de credencial y secreto con firma hash Bcrypt en `op_rh_v2_dispositivo_credenciales`.
   - Prohibido el uso de contraseñas compartidas o en texto plano.
2. **Portal3 Authentication (Cliente Administrativo):**
   - Esquema HTTP Bearer con tokens JWT firmados criptográficamente y validados en `Portal3JwtAuthHelper`.
   - Validación obligatoria de emisor (`iss=portal3_auth`), vigencia temporal (`exp`), tipo de token (`type=access`) y usuario activo (`tb_usuarios.estatus = 0`).

### Autorización y alcance institucional
- **Puesto 13 (Supervisión / Recursos Humanos):** Alcance global de lectura sobre estaciones de servicio soportadas y activas.
- **Puesto 6 (Encargado de Estación):** Acceso acotado de forma estricta a su estación física asignada (`id_gas` / `id_estacion`).
- **Excepción Palo Solo (Localidad 2):** Acceso condicionado al centro de Autolavado (Localidad 9) únicamente mediante autorización departamental individual explícita.
- **Puestos 3 y 14:** Sin alcance multiestación automático.
- **Principio Fail-Closed:** Ante inconsistencias de base de datos, parámetros no mapeados o credenciales inválidas, el sistema deniega el acceso con respuestas HTTP estándar (`401 Unauthorized` o `403 Forbidden`).

---

## 8. Estado del desarrollo y fases del proyecto

El proyecto opera bajo un calendario maestro con fechas de corte y revisiones de arquitectura controladas:

```
[V2.1 Raw Ingestion] ──▶ [V2.2 Temporary Access] ──▶ [V2.3 Shift Scheduling] ──▶ [V2.4 Snapshots & L2] ──▶ [V2.5 Admin Incidences] ──▶ [V2.6 Parallel Comparison]
       ✔ PASS                    ✔ PASS                      ✔ PASS                       ✔ PASS                     ✔ PASS                         ✔ PASS
```

### Distinción obligatoria de estados
- **`CODE_IMPLEMENTED`:** La funcionalidad está desarrollada, modularizada y verificada contra la suite completa de pruebas locales y de integración.
- **`PRODUCTION_DEPLOYED`:** Requiere autorización formal del Project Owner, ejecución controlada de migraciones aditivas y auditoría de compuertas de seguridad (*gates*).

> [!IMPORTANT]
> **Operación en Shadow Mode:** Actualmente la API opera en modo de sombra. Los eventos procesados no afectan el cálculo de nómina productiva, reportes consolidados legados ni tablas operativas históricas (`op_rh_personal`, `op_rh_personal_asistencia`).

---

## 9. Documentación técnica y contratos congelados

Para consultar los contratos de interfaz, modelos de persistencia física y bitácoras de actividades, consulta la documentación correspondiente en el repositorio:

- **Arquitectura congelada:**
  - [Architecture Freeze V1.0](docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md)
  - [Addendum — Autoridad de Zona Horaria](docs/architecture/CHECADOR_V2_WORKPLACE_TIMEZONE_AUTHORITY_ADDENDUM_V1.0.md)
  - [Addendum — Gatekeeper de Entrada Anticipada](docs/architecture/CHECADOR_V2_EARLY_ENTRY_GATEKEEPER_ADDENDUM_V1.0.md)
  - [Addendum — Dominio de Event UUID](docs/architecture/CHECADOR_V2_EVENT_UUID_DOMAIN_ADDENDUM_V1.0.md)
- **Contratos de API V2:**
  - [API Ingesta de Marcaciones Crudas (PunchEvents)](docs/v2/PUNCH_EVENTS_INGESTION_API.md)
  - [API Programación de Turnos y Horarios Efectivos](docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md)
  - [API Acceso Temporal por PIN](docs/v2/TEMPORARY_ACCESS_API_CONTRACT.md)
  - [API Lectura de Zona Horaria Canónica](docs/v2/WORKPLACE_TIMEZONE_READ_API_CONTRACT_V1.0.md)
  - [API Ventana de Entrada Gatekeeper](docs/v2/CHECADOR_V2_DEVICE_ENTRY_WINDOW_API_CONTRACT_V1.0.md)
  - [API Evaluación de Jornada On-Demand](docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.1.md)
  - [API Límite Administrativo de Jornadas](docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md)
  - [API Clasificación Pública de Eventos](docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.1.md)
- **Control y seguimiento:**
  - [Bitácora de Cambios del API (Changelog)](docs/changelog/CHECADOR_V2_API_CHANGELOG.md)
  - [Estado de Implementación Compartido](docs/status/CHECADOR_V2_IMPLEMENTATION_STATUS.md)
  - [Calendario Maestro 2026](docs/planning/CHECADOR_V2_MASTER_SCHEDULE_2026.md)
  - [Hoja de Ruta de Entregables (Roadmap)](docs/status/CHECADOR_V2_DELIVERY_ROADMAP.md)
