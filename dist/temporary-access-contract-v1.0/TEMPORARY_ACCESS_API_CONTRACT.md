# TEMPORARY ACCESS API CONTRACT — CHECADOR V2

**TEMPORARY_ACCESS_CONTRACT_VERSION:** V1.0  
**STATUS:** FROZEN  
**EFFECTIVE_DATE:** 2026-09-30  

---

## 1. GOBERNANZA Y FUENTE DE VERDAD

Este documento constituye la especificación contractual oficial del flujo de contingencia y acceso temporal por PIN para los proyectos:

- Checador API (`portal-api-v.1.0.1`)
- Checador Lector (`lector-biometrico`)
- Portal3 (`portal3-web`)

### Reglas de Gobernanza:
1. **Contrato Compartido Identico:** API, Lector y Portal3 deben implementar exactamente esta especificación. Ningún proyecto puede modificar de forma unilateral las URLs, estructuras JSON, códigos de respuesta o transiciones de estado descritas en este documento.
2. **Control de Cambios:** Cualquier modificación a este contrato requerirá una propuesta formal de cambio (RFC), la conciliación técnica entre las partes y la emisión de una versión incremental (`V1.1`, `V2.0`).
3. **Preservación del Architecture Freeze:** Este contrato respeta rigurosamente el documento `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`, el cual permanece intacto.
4. **Delimitación de Alcance:** Este contrato rige la captura de marcaciones en contingencia y la emisión/validación de PINs temporales. No define cálculo de jornada, incidencias ni nómina.

---

## 2. PROPÓSITO Y PRINCIPIOS DEL ACCESO TEMPORAL

El acceso temporal existe **exclusivamente como un mecanismo de contingencia** cuando la captura biométrica por huella no puede identificar a un trabajador.

### Principios Fundamentales:
- **Huella como Método Principal:** La huella dactilar se mantiene como `PRIMARY_CAPTURE_METHOD`.
- **Naturaleza Excepcional:** El PIN temporal **NO es un PIN permanente**, no es un método ordinario de checada y no sustituye la inscripción biométrica.
- **Uso Único (Single-Use):** Un PIN temporal puede ser validado para **una sola marcación exitosa**.
- **Corta Vigencia (TTL Estricto):** El PIN caduca automáticamente tras transcurrir su tiempo de vida configurado.
- **Supervisión y Autorización:** Requiere ser solicitado explícitamente y autorizado por un supervisor en Portal3.
- **Enlazamiento de Dominio:** Un PIN queda estrictamente acoplado al trabajador, estación, dispositivo y supervisor autorizador.
- **Operación Exclusivamente En Línea:** El PIN temporal **NO funciona offline**; requiere comunicación en vivo con el servidor.

---

## 3. FLUJO OPERATIVO END-TO-END

```text
[EMPLEADO]               [LECTOR]                  [API V2]                 [PORTAL3 / SUPERVISOR]
    │                       │                         │                               │
    ├─ Intenta Huella 3x ───┤                         │                               │
    │  (Falla lectura)      ├─ Ofrece Contingencia    │                               │
    │                       │  y pide ID Empleado     │                               │
    ├─ Captura ID Empleado ─┤                         │                               │
    │                       ├─ Genera request_uuid    │                               │
    │                       ├─ POST /requests ───────>│                               │
    │                       │  (Fire-and-return)      ├─ Resuelve trabajador/estación  │
    │                       │                         ├─ Crea Solicitud PENDING ─────>│
    │                       ├─ Vuelve a modo Huella   │                               ├─ Muestra en Panel Pending
    │                       │  (No bloquea la fila)   │                               │
    │                       │                         │                               ├─ Supervisor presiona Autorizar
    │                       │                         │<── POST /requests/authorize ──┤ (Servidor valida Tenant)
    │                       │                         ├─ Genera PIN 6 dígitos         │
    │                       │                         ├─ Hash BCRYPT en BD            │
    │                       │                         └─ Retorna PIN (1 sola vez) ───>│ Muestra PIN en pantalla
    ├─ Obtiene PIN ─────────┴─────────────────────────────────────────────────────────┤ (Supervisor entrega PIN)
    │
    ├─ Regresa a Lector ───>│
    ├─ Captura ID + PIN ────┤
    │                       ├─ Genera validation_uuid │
    │                       ├─ POST /validate ───────>│
    │                       │                         ├─ Autentica Dispositivo        │
    │                       │                         ├─ Resuelve Trabajador/Estación │
    │                       │                         ├─ Evalúa password_verify()     │
    │                       │                         ├─ Transición atómica -> USED   │
    │                       │<── 200 VALID ───────────┤                               │
    │                       │                         │                               │
    │                       ├─ Crea PunchEvent ──────>│                               │
    │                       │  capture_method=        │                               │
    │                       │  TEMPORARY_CODE         ├─ Persiste Marcación Cruda    │
    │                       │  (metadata con IDs)     │  en op_rh_v2_marcaciones_crudas│
    │                       │<── 201 ACCEPTED ────────┤                               │
```

---

## 4. SEPARACIÓN DE IDENTIFICADORES Y CONTEXTO

| Identificador | Generado Por | Ámbito y Propósito |
| :--- | :--- | :--- |
| `request_uuid` | Lector (Device) | Idempotencia en la **creación de la solicitud de contingencia** (`POST /api/v2/temporary-access/requests`). Evita solicitudes duplicadas ante Lost ACK en la creación. |
| `validation_uuid` | Lector (Device) | Idempotencia en la **validación del PIN** (`POST /api/v2/temporary-access/validate`). Permite recuperar la respuesta en caso de Lost ACK durante la validación sin consumir un PIN ya usado. |
| `event_uuid` | Lector (Device) | Idempotencia nativa de la **marcación cruda inalterable** (`POST /api/v2/punch-events`). |

---

## 5. REASON OFFICIAL CODE

- **Código Oficial:** `FINGERPRINT_NOT_RECOGNIZED`
- **Regla:** El motivo es semánticamente independiente del número de intentos fallidos configurado en el cliente. Queda prohibido el uso de strings con números hardcodeados (ej. `BIOMETRIC_FAIL_3X`).

---

## 6. PARÁMETROS CONFIGURABLES DEL SISTEMA

Los siguientes parámetros rigen la operación y deben ser configurables a nivel de servidor/aplicación:

| Parámetro | Propósito | Default Inicial |
| :--- | :--- | :--- |
| `TEMPORARY_REQUEST_TTL_MINUTES` | Tiempo de vida de la solicitud en estado `PENDING` antes de expirar | 30 minutos |
| `TEMPORARY_PIN_LENGTH` | Longitud del PIN numérico | 6 dígitos (`000000` - `999999`) |
| `TEMPORARY_PIN_TTL_MINUTES` | Tiempo de vida del PIN en estado `AUTHORIZED` antes de expirar | 15 minutos |
| `TEMPORARY_PIN_MAX_ATTEMPTS` | Intentos fallidos máximos de validación antes del bloqueo | 3 intentos |

---

## 7. DEFINICIÓN DE ESTADOS Y MÁQUINA DE ESTADOS

### Estados Oficiales:
1. `PENDING`: Solicitud creada por el Lector, en espera de revisión por el supervisor en Portal3.
2. `AUTHORIZED`: Autorizada por el supervisor; PIN activo y no expirado.
3. `REJECTED`: Rechazada explícitamente por el supervisor (**Estado Terminal**).
4. `EXPIRED`: Solicitud o PIN cuyo tiempo de vida expiró sin ser utilizado (**Estado Terminal**).
5. `LOCKED`: Solicitud bloqueada automáticamente por alcanzar el máximo de intentos fallidos (**Estado Terminal**).
6. `USED`: Solicitud validada atómicamente y consumida para una marcación (**Estado Terminal**).
7. `CANCELLED`: Solicitud revocada manualmente por el supervisor o revocada automáticamente por el sistema al entrar una nueva solicitud (**Estado Terminal**).

### Transiciones Válidas de Estado:

```text
               ┌─────────────── PENDING ───────────────┐
               │                                       │
               ├──> AUTHORIZED                         ├──> REJECTED (Terminal)
               │         │                             ├──> CANCELLED (Terminal)
               │         ├──> USED (Terminal)          └──> EXPIRED (Terminal)
               │         ├──> LOCKED (Terminal)
               │         ├──> CANCELLED (Terminal)
               │         └──> EXPIRED (Terminal)
```

---

## 8. POLÍTICA DE SOLICITUD ACTIVA Y MULTITENANCY DE ESTACIÓN

### Política de Solicitud Activa Única:
- Solo puede existir **UNA** solicitud activa (`PENDING` o `AUTHORIZED` no expirada) por cada combinación de `(id_dispositivo, id_personal_resuelto)`.
- Si el Lector envía un `request_uuid` idéntico (reintento por Lost ACK), el API retorna la solicitud existente sin crear una nueva.
- Si ingresa un `request_uuid` nuevo para un trabajador con una solicitud activa previa, el API **cancela atómicamente la solicitud previa** (`status = 'CANCELLED'`, `cancelled_at_utc = NOW()`, `cancelled_by_user_id = NULL`) y registra la nueva solicitud en estado `PENDING`.

### Aislamiento de Tenant (Server-Side Enforcement):
- El API **NUNCA** confía en `id_estacion` enviado en el JSON por Portal3 o el cliente web.
- Al listar, autorizar, rechazar o cancelar, el API extrae la `id_estacion` directamente del usuario supervisor autenticado en la sesión de Portal3.
- Se ejecuta la verificación estricta:
  `WHERE id_estacion = :id_estacion_supervisor AND id_estacion = :id_estacion_solicitud`
- Si un supervisor intenta operar sobre una solicitud de otra estación, el API retorna `403 FORBIDDEN`.

---

## 9. ESTRATEGIA DE EXPIRACIÓN Y TIEMPO

- **Estrategia de Expiración:** `LAZY EXPIRATION` (Evaluación Perezosa Transaccional).
- **Mecanismo:** En cada interacción (`LIST`, `AUTHORIZE`, `VALIDATE`, `REJECT`, `CANCEL`), el API evalúa `NOW() >= request_expires_at_utc` o `NOW() >= pin_expires_at_utc`. Si la fecha expiró, ejecuta transaccionalmente la actualización a `EXPIRED`. No se requiere un job o scheduler de background para garantizar la seguridad.
- **Fuente de Tiempo:** `SERVER UTC` (`gmdate('Y-m-d H:i:s')`). Queda estrictamente prohibido utilizar relojes del cliente o del navegador para evaluar decisiones de seguridad o expiración.

---

## 10. MODELO FÍSICO CONCEPTUAL PROPUESTO (NOT YET IMPLEMENTED)

```sql
-- Nombre de tabla propuesto: op_rh_v2_solicitudes_acceso_temporal
-- ESTADO: NOT YET IMPLEMENTED

CREATE TABLE IF NOT EXISTS `op_rh_v2_solicitudes_acceso_temporal` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `request_uuid` VARCHAR(64) NOT NULL,
  `successful_validation_uuid` VARCHAR(64) NULL,
  `id_dispositivo` INT NOT NULL,
  `id_estacion` INT NOT NULL,
  `referencia_externa_personal` VARCHAR(64) NOT NULL,
  `id_personal_resuelto` INT NOT NULL,
  `reason` VARCHAR(100) NOT NULL DEFAULT 'FINGERPRINT_NOT_RECOGNIZED',
  `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  `pin_hash` VARCHAR(255) NULL,
  `pin_failed_attempts` INT NOT NULL DEFAULT 0,
  `requested_at_utc` DATETIME NOT NULL,
  `request_expires_at_utc` DATETIME NOT NULL,
  `authorized_by_user_id` INT NULL,
  `authorized_at_utc` DATETIME NULL,
  `rejected_by_user_id` INT NULL,
  `rejected_at_utc` DATETIME NULL,
  `cancelled_by_user_id` INT NULL,
  `cancelled_at_utc` DATETIME NULL,
  `pin_expires_at_utc` DATETIME NULL,
  `used_at_utc` DATETIME NULL,
  UNIQUE KEY `idx_request_uuid` (`request_uuid`),
  UNIQUE KEY `idx_successful_validation` (`successful_validation_uuid`),
  KEY `idx_estacion_status` (`id_estacion`, `status`),
  KEY `idx_disp_ref_status` (`id_dispositivo`, `referencia_externa_personal`, `status`),
  KEY `idx_request_expires` (`request_expires_at_utc`),
  KEY `idx_pin_expires` (`pin_expires_at_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 11. CUSTODIA DEL PIN Y BÚSQUEDA BCRYPT

- **Generación:** PIN numérico aleatorio de 6 dígitos mediante `random_int(100000, 999999)`.
- **Almacenamiento:** El PIN se guarda exclusivamente como hash `password_hash($pin, PASSWORD_BCRYPT)`. Se prohíbe guardar o registrar en logs el PIN en texto plano.
- **Visualización:** El PIN se retorna **UNA SOLA VEZ** en la respuesta JSON al momento exacto en que el supervisor presiona "Autorizar". Si la pantalla se cierra, la solicitud debe cancelarse y emitirse una nueva.
- **Estrategia de Búsqueda Segura:** Al validar un PIN (`POST /api/v2/temporary-access/validate`), el API NO ejecuta búsquedas globales de hashes. Filtra únicamente la solicitud activa candidata del trabajador y dispositivo autenticado:
  ```sql
  SELECT * FROM op_rh_v2_solicitudes_acceso_temporal
  WHERE id_dispositivo = :id_dispositivo
    AND id_estacion = :id_estacion
    AND id_personal_resuelto = :id_personal_resuelto
    AND status = 'AUTHORIZED'
    AND pin_expires_at_utc > NOW()
  FOR UPDATE
  ```
  y ejecuta `password_verify($pin, $row['pin_hash'])` sobre el registro único obtenido.

---

## 12. ESPECIFICACIÓN DE ENDPOINTS OFICIALES

### 12.1 POST `/api/v2/temporary-access/requests`
- **Consumidor:** Lector (Device Auth)
- **Headers:** `X-Device-Id`, `X-Credential-Key`, `Authorization: Bearer <secret>`
- **Request Body:**
  ```json
  {
    "request_uuid": "c71e8400-e29b-41d4-a716-446655440001",
    "external_worker_reference": "150",
    "reason": "FINGERPRINT_NOT_RECOGNIZED"
  }
  ```
- **Response Success (201 Created):**
  ```json
  {
    "code": 201,
    "status": "REQUEST_ACCEPTED",
    "request_uuid": "c71e8400-e29b-41d4-a716-446655440001",
    "request_status": "PENDING",
    "mensaje": "Solicitud de acceso temporal registrada exitosamente."
  }
  ```

### 12.2 GET `/api/v2/temporary-access/requests`
- **Consumidor:** Portal3 (Supervisor Auth)
- **Headers:** `Authorization: Bearer <user_token>` (o Sesión Activa)
- **Response Success (200 OK):**
  ```json
  {
    "code": 200,
    "status": "OK",
    "data": [
      {
        "request_uuid": "c71e8400-e29b-41d4-a716-446655440001",
        "id_personal_resuelto": 150,
        "nombre_trabajador": "Juan Pérez",
        "id_estacion": 1,
        "dispositivo_nombre": "Lector Estación 1",
        "reason": "FINGERPRINT_NOT_RECOGNIZED",
        "requested_at_utc": "2026-09-30 15:00:00",
        "request_expires_at_utc": "2026-09-30 15:30:00"
      }
    ]
  }
  ```

### 12.3 POST `/api/v2/temporary-access/requests/{request_uuid}/authorize`
- **Consumidor:** Portal3 (Supervisor Auth)
- **Headers:** `Authorization: Bearer <user_token>`
- **Response Success (200 OK):**
  ```json
  {
    "code": 200,
    "status": "AUTHORIZED",
    "request_uuid": "c71e8400-e29b-41d4-a716-446655440001",
    "temporary_pin": "849201",
    "pin_expires_at_utc": "2026-09-30 15:15:00",
    "mensaje": "Código temporal generado. Proporcione este código al trabajador (Se muestra una sola vez)."
  }
  ```

### 12.4 POST `/api/v2/temporary-access/requests/{request_uuid}/reject`
- **Consumidor:** Portal3 (Supervisor Auth)
- **Headers:** `Authorization: Bearer <user_token>`
- **Response Success (200 OK):**
  ```json
  {
    "code": 200,
    "status": "REJECTED",
    "request_uuid": "c71e8400-e29b-41d4-a716-446655440001",
    "mensaje": "Solicitud rechazada exitosamente."
  }
  ```

### 12.5 POST `/api/v2/temporary-access/requests/{request_uuid}/cancel`
- **Consumidor:** Portal3 (Supervisor Auth)
- **Headers:** `Authorization: Bearer <user_token>`
- **Response Success (200 OK):**
  ```json
  {
    "code": 200,
    "status": "CANCELLED",
    "request_uuid": "c71e8400-e29b-41d4-a716-446655440001",
    "mensaje": "Solicitud cancelada exitosamente."
  }
  ```

### 12.6 POST `/api/v2/temporary-access/validate`
- **Consumidor:** Lector (Device Auth)
- **Headers:** `X-Device-Id`, `X-Credential-Key`, `Authorization: Bearer <secret>`
- **Request Body:**
  ```json
  {
    "external_worker_reference": "150",
    "temporary_pin": "849201",
    "validation_uuid": "e91e8400-e29b-41d4-a716-446655440099"
  }
  ```
- **Response Success (200 OK - Validated):**
  ```json
  {
    "code": 200,
    "status": "VALID",
    "request_uuid": "c71e8400-e29b-41d4-a716-446655440001",
    "validation_uuid": "e91e8400-e29b-41d4-a716-446655440099",
    "id_personal_resuelto": 150,
    "mensaje": "Código temporal validado exitosamente. Registre el PunchEvent."
  }
  ```
- **Response Error (400 / 403 / 409):**
  - Si el PIN es incorrecto: `{"code": 400, "status": "INVALID_PIN", "attempts_remaining": 2}`
  - Si supera 3 intentos: `{"code": 403, "status": "LOCKED", "mensaje": "Código bloqueado por superar el límite de intentos fallidos."}`
  - Si expiró: `{"code": 400, "status": "EXPIRED", "mensaje": "El código temporal ha expirado."}`
  - Si ya fue consumido por otro `validation_uuid`: `{"code": 409, "status": "ALREADY_USED", "mensaje": "El código temporal ya fue utilizado."}`

---

## 13. TRATAMIENTO DE LOST ACK E IDEMPOTENCIA

### Lost ACK en Validación de PIN (`POST /validate`):
- El Lector envía `validation_uuid`.
- Si el servidor procesa la validación, actualiza `status = 'USED'` y guarda `successful_validation_uuid = :validation_uuid`, pero la respuesta se pierde en el canal:
  1. El Lector reintenta el endpoint enviando el **mismo `validation_uuid`**.
  2. El API detecta `status = 'USED'` AND `successful_validation_uuid == :validation_uuid`.
  3. El API reconoce el reenvío legítimo y responde nuevamente `200 VALID`.

### Lost ACK en Marcación Final (`POST /punch-events`):
- Se resuelve mediante la idempotencia nativa por `event_uuid`.
- El Lector envía `capture_method = 'TEMPORARY_CODE'` y en `metadata` incluye `{"request_uuid": "c71e8400-...", "validation_uuid": "e91e8400-..."}`.
- El `PunchEvent` **NUNCA** contiene el PIN en texto plano ni el hash del PIN.

---

## 14. MATRIZ DE SEGURIDAD Y RESTRICCIONES OFFLINE

- **TEMPORARY_PIN_OFFLINE_ALLOWED:** `NO` (El PIN exige validación HTTP en vivo con el servidor).
- **Fail-Closed:** Ante falta de conectividad o error de servidor, el Lector no valida el PIN y rechaza la checada temporal.
- **Tenant Isolation:** Estrictamente validado en el servidor para evitar accesos cruzados de supervisores entre estaciones.
- **Protección contra Fuerza Bruta:** Bloqueo automático `LOCKED` al 3er intento fallido.

---

## 15. PUNCH EVENT INTEGRITY RFC

- **PUNCH_EVENT_INTEGRITY_RFC_REQUIRED:** `YES`
- **Nota:** La especificación de firmas criptográficas/HMAC para el sello de integridad del `PunchEvent` se definirá en un RFC técnico independiente. No forma parte de las obligaciones de este contrato V1.0.

---

## 16. NON-GOALS / REJECTED DESIGNS

- **NO PIN Permanente:** Se descarta explícitamente cualquier concepto de PIN fijo o permanente por trabajador.
- **NO Grant Tokens:** Se eliminan los tokens intermediarios `grant_token` por redundancia con `validation_uuid`.
- **NO Client-Side Station Trust:** Se descarta la confianza en `id_estacion` enviado por el navegador.
- **NO Biometric Fail Hardcoded String:** Se elimina `BIOMETRIC_FAIL_3X` en favor de `FINGERPRINT_NOT_RECOGNIZED`.
- **NO Offline Temporary Validation:** Se rechaza la validación offline de códigos temporales.

---

## 17. ESTADO DE IMPLEMENTACIÓN

```text
CODE_IMPLEMENTED: NO
DATABASE_IMPLEMENTED: NO
ENDPOINTS_IMPLEMENTED: NO
PORTAL3_IMPLEMENTED: NO
LECTOR_REAL_API_INTEGRATION: NO
```
