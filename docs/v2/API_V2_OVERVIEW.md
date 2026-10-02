# CHECADOR API V2 — OVERVIEW

**API Version:** V2.1  
**Architecture Status:** Frozen (`docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`)  
**Development Line:** Primary Official Line for Checador DEMO  
**Base URL:** `/api/v2/`

---

## 1. INTRODUCCIÓN Y PROPÓSITO

El API V2 de Checador proporciona la capa central de servicios REST/JSON para la ingesta inalterable de eventos de marcación biométrica (`Punch Events`) y la gestión transaccional de contingencias mediante accesos temporales (`Temporary Access`).

Diseñado bajo principios de inmutabilidad, trazabilidad total, idempotencia nativa y seguridad fail-closed, el API V2 sirve como el puente autoritativo entre los terminales físicos de lectura (`Lector`), el panel web administrativo y de supervisión (`Portal3`), y los motores de cálculo diferido.

---

## 2. ARQUITECTURA DE TRES CAPAS (LECTOR ↔ API V2 ↔ PORTAL3)

El ecosistema Checador V2 opera mediante tres actores claramente delimitados:

```text
  ┌────────────────────────┐                   ┌────────────────────────┐
  │     LECTOR BIOMÉTRICO  │                   │        PORTAL3         │
  │   (Hardware / Station) │                   │  (Panel Web Supervisor)│
  └───────────┬────────────┘                   └───────────┬────────────┘
              │                                            │
              │  Device Auth (Bearer Secret + Bcrypt)     │  Supervisor Auth (Portal3 JWT)
              │  X-Device-Id, X-Credential-Key             │  Authorization: Bearer <JWT>
              │                                            │
              └─────────────────────┬──────────────────────┘
                                    │
                                    ▼
                      ┌───────────────────────────┐
                      │      CHECADOR API V2      │
                      │  (/api/v2/punch-events)   │
                      │  (/api/v2/temporary-...)  │
                      └─────────────┬─────────────┘
                                    │
                                    ▼
                      ┌───────────────────────────┐
                      │    BASE DE DATOS MYSQL    │
                      │  op_rh_v2_marcaciones_... │
                      │  op_rh_v2_solicitudes_... │
                      └───────────────────────────┘
```

### Roles y Responsabilidades:
1. **Lector Biométrico (`lector-biometrico`):**
   - Captura eventos biométricos físicos en la estación de trabajo.
   - Genera UUIDs del lado del cliente (`event_uuid`, `request_uuid`, `validation_uuid`) para garantizar idempotencia previa a la transmisión.
   - Ofrece el flujo de contingencia cuando falla la lectura de huella.
   - Transmite marcaciones y solicita/valida accesos temporales mediante Device Auth.

2. **Checador API V2 (`portal-api-v.1.0.1`):**
   - Autentica dispositivos (`Bcrypt`) y supervisores (`JWT HS256`).
   - Aplica aislamiento estricto por estación (`Station Scope`).
   - Persiste marcaciones crudas inalterables (`PunchEvent`).
   - Maneja la máquina de estados y vigencias temporales de PINs de contingencia.
   - Resuelve identidades de personal en tiempo de ingesta sin alterar el evento crudo.

3. **Portal3 (`portal3-web`):**
   - Interfaz web para supervisores y administradores de estación.
   - Muestra solicitudes de acceso temporal pendientes en tiempo real.
   - Permite autorizar (generando un PIN único de 6 dígitos), rechazar o cancelar solicitudes.
   - Operado mediante credenciales y tokens JWT de Portal3.

---

## 3. DOMINIOS PRINCIPALES DEL API V2

### 3.1 Raw Punch Ingestion (`V2.1`)
- **Endpoint:** `POST /api/v2/punch-events`
- **Objeto Central:** `PunchEvent`
- **Propósito:** Ingesta de hechos originales e inmutables de presencia.
- **Regla Fundamental de Dominio:** Un `PunchEvent` representa exclusivamente un hecho físico. **NUNCA** se interpreta en la ingesta como entrada, salida, retardo, falta, hora extra o incidencia. No se modifica ni recalcula posteriormente.

### 3.2 Contingency & Temporary Access (`V2.2 - Slice G8-A`)
- **Endpoints:** `/api/v2/temporary-access/*`
- **Objeto Central:** `TemporaryAccessRequest`
- **Propósito:** Permitir la checada de un trabajador registrado cuando la captura biométrica por huella falla repetidamente.
- **Principios Clave:**
  - Excepcional y de uso único (Single-Use).
  - Corta vigencia (TTL estricto de 30 min para solicitud, 15 min para PIN).
  - Requiere autorización activa de un supervisor.
  - El PIN de 6 dígitos se retorna **una sola vez** en pantalla y se custodia en hash `Bcrypt`.
  - Operación exclusivamente en línea (Fail-Closed offline).

---

## 4. ESTÁNDAR DE TIEMPO Y MANEJO UTC

Para garantizar la consistencia en entornos distribuidos con múltiples zonas horarias y cambios de horario de verano:

1. **Fuente de Tiempo Autoritativa:** `SERVER UTC` (`gmdate('Y-m-d H:i:s')`).
2. **Expiración y Evaluaciones:** Todas las comparaciones de expiración (`request_expires_at_utc`, `pin_expires_at_utc`) se realizan usando tiempo UTC del servidor. Se prohíbe confiar en relojes del cliente o del navegador.
3. **Preservación del Contexto de Origen:** En los `PunchEvents`, el API registra `source_timestamp` (la hora cruda reportada por el dispositivo), `source_timezone` (la zona IANA del dispositivo) y calcula `ocurrido_at_utc` para almacenamiento y ordenamiento global.

---

## 5. INMUTABILIDAD DE EVENTOS CRUDOS

Las marcaciones persistidas en `op_rh_v2_marcaciones_crudas` son strictly inmutables:
- No se permiten operaciones `UPDATE` o `DELETE` sobre eventos registrados.
- La re-transmisión de un mismo `(id_dispositivo, event_uuid)` por Lost ACK retorna `200 ALREADY_RECEIVED` sin duplicar filas ni alterar marcas de tiempo.
- Cualquier interpretación de asistencia (cálculo de turnos, incidencias o nómina) debe ejecutarse en procesos analíticos independientes leídos desde los eventos crudos.

---

## 6. GOBERNANZA Y REFERENCIAS

- **Architecture Freeze:** `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`
- **Temporary Access Contract:** `docs/v2/TEMPORARY_ACCESS_API_CONTRACT.md`
- **Ingestion API Contract:** `docs/v2/PUNCH_EVENTS_INGESTION_API.md`
