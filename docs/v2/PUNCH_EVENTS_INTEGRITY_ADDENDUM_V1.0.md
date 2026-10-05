# PUNCH EVENTS INTEGRITY & IMMUTABILITY SECURITY ADDENDUM V1.0

**ADDENDUM_VERSION:** V1.0
**STATUS:** SPECIFICATION / FROZEN FOR IMPLEMENTATION
**EFFECTIVE_DATE:** 2026-10-02
**TARGET_TABLE:** `op_rh_v2_marcaciones_crudas`
**GOVERNANCE_REFERENCE:** `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`

---

## 1. PROPÓSITO Y PRINCIPIOS NO NEGOCIABLES

Este documento especifica el modelo oficial de inmutabilidad, sellado criptográfico server-side y custodia probatoria para las marcaciones biométricas y de contingencia (`Punch Events`) registradas en el API V2 de Checador.

### Principios Fundamentales:
1. **Inmutabilidad Absoluta del Hecho Original:** Una marcación original registrada en `op_rh_v2_marcaciones_crudas` **NUNCA** se modifica, corrige ni elimina en operación normal.
2. **Sin Fabricación de Eventos:** Una marcación faltante **NUNCA** se sustituye por una hora inventada, administrativa o corregida.
3. **Independencia de Incidencias:** Las incidencias (vacaciones, incapacidades, justificaciones) explican o cambian el efecto administrativo, pero **NUNCA** modifican ni reemplazan el `PunchEvent` original.
4. **Trazabilidad Total:** Toda jornada interpretada en capas superiores debe poder rastrearse directamente hasta los `PunchEvents` originales inalterables.
5. **Detección Criptográfica de Alteraciones (Tamper-Evidence):** Cualquier alteración posterior del contenido protegido de un `PunchEvent` en base de datos debe ser inmediatamente detectable mediante un sello criptográfico verificable.
6. **Ausencia de Clasificación en Ingesta:** El API de ingesta **NO** clasifica marcaciones como `ENTRADA`, `SALIDA_COMIDA`, `REGRESO_COMIDA` o `SALIDA`. Registra exclusivamente un hecho físico atemporal ("ocurrió una marcación").
7. **Resolución Aditiva de Identidad:** Las resoluciones de identidad tardías (`PENDING_MATCH`) son aditivas y **NO** modifican la fila `PunchEvent` original.
8. **Congelamiento de Estado en Ingesta:** Tanto `processing_status` como `validation_flags` quedan estrictamente congelados en la fila original tras el `INSERT`. Cualquier bandera o estado posterior de flujo de trabajo se almacena en entidades aditivas externas.

---

## 2. SECURITY ENVELOPE V1 (`RAW_PUNCH_INTEGRITY_ENVELOPE_V1`)

El sello de integridad protege el contenido del hecho original y los atributos autoritativos fijados por el servidor al momento de la ingesta.

### 2.1 Campos Incluidos en el Envelope Protegido:
- `event_uuid` (String UTF-8, UUIDv4 del cliente)
- `id_dispositivo` (Integer, Dispositivo autenticado por el servidor)
- `id_estacion_resuelta` (Integer/NULL, Estación resuelta por el servidor)
- `referencia_externa_personal` (String UTF-8, ID reportado por el hardware)
- `id_personal_resuelto` (Integer/NULL, Identidad resuelta en ingesta; congelada en la fila original)
- `source_timestamp_raw` (String UTF-8, Timestamp exacto enviado por el hardware)
- `source_timezone_reported` (String UTF-8 / NULL, Zona IANA reportada por el hardware)
- `effective_timezone` (String UTF-8 / NULL, Zona IANA efectiva calculada en servidor)
- `timezone_fallback_applied` (Integer `0` o `1`, Indicador de fallback de zona horaria)
- `ocurrido_at_utc` (String UTF-8 / NULL, Timestamp `Y-m-d H:i:s` UTC calculado)
- `recibido_at_utc` (String UTF-8, Timestamp `Y-m-d H:i:s` UTC de recepción en servidor)
- `metodo_captura` (String UTF-8, ej. `BIOMETRIC`, `TEMPORARY_CODE`)
- `external_event_id` (String UTF-8 / NULL, Secuencial del lector)
- `secuencia_dispositivo` (Integer/NULL, Contador del lector)
- `validation_flags_canonical` (String UTF-8, Array JSON canónico de banderas operativas de ingesta)
- `metadata_json_canonical` (String UTF-8, Objeto JSON canónico de metadatos de ingesta)

### 2.2 Campos Excluidos del Envelope Protegido:
- `integrity_seal`, `integrity_version`, `integrity_key_id` (Metadatos propios del sello).

---

## 3. ESPECIFICACIÓN DE SERIALIZACIÓN CANÓNICA (`CANONICAL SERIALIZATION V1`)

Para evitar falsos positivos de alteración producidos por diferencias de espaciado, codificación u ordenamiento de claves JSON, la serialización canónica V1 se define mediante un objeto JSON estricto sin espacios en blanco irrelevantes y con claves ordenadas lexicográficamente.

### Reglas de Canonicalización:
1. **Encoding:** UTF-8 binario.
2. **Canonical JSON Representation:** El payload canónico es una cadena JSON válida generada con claves ordenadas alfabéticamente en todos los niveles de profundidad.
3. **Escapado de Caracteres:** Caracteres Unicode se representan directamente en UTF-8 sin secuencias `\uXXXX` innecesarias (`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`).
4. **Tratamiento de Valores NULL:** Representados explícitamente como `null` JSON.
5. **Fechas y Cadenas:** Cadenas de texto preservadas exactamente.
6. **Números Enteros y Decimales:** Representados como números JSON numéricos sin comillas ni ceros no significativos. Cadenas numéricas exactas en metadatos se conservan como string.
7. **Canonicalization de `metadata_json` y `validation_flags`:**
   - Los arrays y objetos en `metadata_json` se decodifican y re-serializan ordenando lexicográficamente todas las llaves (`ksort` recursivo).
   - Los valores en `validation_flags` se ordenan alfabéticamente.

### Estructura Canónica Base V1:
```json
{"effective_timezone":"America/Mexico_City","event_uuid":"550e8400-e29b-41d4-a716-446655440000","external_event_id":null,"id_dispositivo":1,"id_estacion_resuelta":1,"id_personal_resuelto":20,"metadata_json":{},"metodo_captura":"BIOMETRIC","ocurrido_at_utc":"2026-09-28 18:30:00","recibido_at_utc":"2026-09-28 18:30:00","referencia_externa_personal":"150","secuencia_dispositivo":142,"source_timestamp_raw":"2026-09-28 12:30:00","source_timezone_reported":"America/Mexico_City","timezone_fallback_applied":0,"validation_flags":[]}
```

---

## 4. ESTRUCTURA DE COLUMNAS Y REGISTROS HISTÓRICOS

### 4.1 Definición de Schema Aditivo:
```sql
ALTER TABLE `op_rh_v2_marcaciones_crudas`
  ADD COLUMN `integrity_version` VARCHAR(10) NULL DEFAULT NULL AFTER `metadata_json`,
  ADD COLUMN `integrity_key_id` VARCHAR(32) NULL DEFAULT NULL AFTER `integrity_version`,
  ADD COLUMN `integrity_seal` CHAR(64) NULL DEFAULT NULL AFTER `integrity_key_id`;
```

### 4.2 Distinción de Filas Históricas (`LEGACY_UNSEALED`):
- Los registros existentes previos a la migración conservarán `integrity_version = NULL`, `integrity_key_id = NULL` y `integrity_seal = NULL`.
- Las verificaciones de integridad identificarán inequívocamente estos registros como `LEGACY_UNSEALED` sin considerarlos alterados (`INVALID`).
- **PROHIBICIÓN DE DEFAULT AUTOMÁTICO:** Las columnas se crean con `DEFAULT NULL` para evitar asignar implícitamente `v1` a filas del pasado.

### 4.3 Activación Obligatoria (`Activation Boundary`):
- Una vez activada la funcionalidad mediante configuración del servidor (`RAW_PUNCH_INTEGRITY_MANDATORY=true`), todo nuevo `PunchEvent` registrado **DEBE** incluir explícitamente `integrity_version = 'v1'`, `integrity_key_id` y `integrity_seal` (CHAR(64) Hex minúscula).
- Cualquier nuevo evento que ingrese sin sello post-activación provocado por fallos de configuración de clave será rechazado en modo Fail-Closed (HTTP `500`).

---

## 5. MODELO CRIPTOGRÁFICO Y GESTIÓN DE CLAVES

### 5.1 Algoritmo y Formato:
- **Algoritmo:** HMAC-SHA256.
- **Formato del Sello (`integrity_seal`):** Cadena de 64 caracteres en hexadecimal en minúsculas (`CHAR(64)`).

### 5.2 Configuración de Claves (`Active Key & Key Ring`):
- **Active Key ID:** Define el identificador de la clave actualmente activa para firmar (`RAW_PUNCH_ACTIVE_KEY_ID`, ej. `"k1"`).
- **Key Ring Config:** Diccionario de claves de verificación en el entorno (`RAW_PUNCH_KEY_RING = {"k1": "Secret_K1_...", "k2": "Secret_K2_..."}`), fuera de MySQL y fuera del repositorio Git.

### 5.3 Rotación de Claves (`Key Rotation`):
- Al rotar la clave activa a `k2`, los nuevos eventos se firmarán con `k2`.
- Las marcaciones históricas conservan `integrity_key_id = 'k1'` y se verifican consultando `k1` en el Key Ring.
- **PROHIBICIÓN ABSOLUTA:** Queda prohibido re-firmar o recalcular sellos históricos durante un proceso de rotación de claves.

---

## 6. TRANSACCIÓN DE SELLADO E INSERCIÓN (`SEAL CREATION`)

El sello de integridad se calcula y persiste en la misma transacción atómica de inserción:

```text
[ CLIENT REQUEST ] ──> 1. Autenticar Dispositivo (Bcrypt)
                       2. Validar Payload JSON
                       3. Resolver Estación y Trabajador Histórico
                       4. Calcular timestamps UTC y banderas de ingesta
                       5. Generar JSON Canónico de Envelope V1
                       6. Calcular HMAC-SHA256 con active key
                       7. INSERT INTO op_rh_v2_marcaciones_crudas
                          (..., integrity_version, integrity_key_id, integrity_seal)
                       8. COMMIT
```

---

## 7. MANEJO DE REINTENTOS IDEMPOTENTES Y CONFLICTOS DE PAYLOAD

### 7.1 Idempotencia Exacta (`ALREADY_RECEIVED`):
- Si el Lector reenvía un `event_uuid` existente para el mismo dispositivo:
  - El API localiza la fila existente en `op_rh_v2_marcaciones_crudas`.
  - Retorna `200 ALREADY_RECEIVED` sin ejecutar `UPDATE` y sin modificar el sello o timestamps originales.

### 7.2 Equivalencia de Cliente para Idempotencia (`Client Fingerprint`):
- Para verificar si un reintento por Lost ACK es equivalente, el servidor compara exclusivamente los campos enviados por el cliente (`event_uuid`, `referencia_externa_personal`, `source_timestamp_raw`, `source_timezone_reported`, `metodo_captura`, `external_event_id`, `secuencia_dispositivo`, `metadata_json`).
- Los atributos generados por el servidor en la segunda petición (como un nuevo `recibido_at_utc` T2) **NO** se usan para declarar conflicto de idempotencia.

### 7.3 Conflicto de Payload (`HTTP 409 CONFLICT`):
- Si ingresa un `event_uuid` idéntico para el mismo dispositivo pero con atributos de cliente divergentes (ej. diferente `source_timestamp_raw` o distinta `referencia_externa_personal`), el API rechaza la solicitud (`HTTP 409 CONFLICT` / `IDEMPOTENCY_PAYLOAD_CONFLICT`).
- Este comportamiento constituye una respuesta aditiva de seguridad (`ADDITIVE_RESPONSE_BEHAVIOR`).

---

## 8. MODELO DE RESOLUCIÓN ADITIVA (`PENDING_MATCH`)

- Si una marcación no puede resolver un `id_personal_resuelto` en el momento de la ingesta, se registra con `id_personal_resuelto = NULL` y `processing_status = 'PENDING_MATCH'`.
- **Regla de Inmutabilidad:** La fila original en `op_rh_v2_marcaciones_crudas` **NUNCA** se actualiza posteriormente para asignar el `id_personal_resuelto`.
- **Resolución Tardía:** La asociación posterior entre el `PunchEvent` y el trabajador se almacena en una tabla aditiva independiente de resolución/reconciliación (`op_rh_v2_resolucion_identidades`), manteniendo el `PunchEvent` y su sello 100% intactos.

---

## 9. MODELO DE INFRAESTRUCTURA APPEND-ONLY (PRIVILEGIOS Y TRIGGERS)

1. **Privilegios de Usuario MySQL API:** El principal de la base de datos para la aplicación API V2 (`RAW_PUNCH_RUNTIME_DB_PRINCIPAL`) tendrá permisos estrictamente delimitados a `SELECT, INSERT` sobre `op_rh_v2_marcaciones_crudas`. Los permisos `UPDATE` y `DELETE` estarán totalmente revocados.
2. **Triggers Preventivos en Base de Datos:**
   ```sql
   CREATE TRIGGER trg_prevent_raw_punch_update
   BEFORE UPDATE ON op_rh_v2_marcaciones_crudas
   FOR EACH ROW
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ERROR: Raw PunchEvents are strictly immutable and cannot be updated.';

   CREATE TRIGGER trg_prevent_raw_punch_delete
   BEFORE DELETE ON op_rh_v2_marcaciones_crudas
   FOR EACH ROW
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ERROR: Raw PunchEvents are strictly immutable and cannot be deleted.';
   ```

---

## 10. ESTADOS DE VERIFICACIÓN Y RECOMENDACIONES DE AUDITORÍA

### 10.1 Estados de Verificación:
- `VALID`: El HMAC recalculado coincide exactamente usando `hash_equals()`.
- `INVALID`: El contenido de la fila o el sello sufrieron alteración posterior.
- `UNVERIFIABLE`: La clave secreta asociada a `integrity_key_id` no se encuentra disponible en el Key Ring.
- `LEGACY_UNSEALED`: Registro histórico con `integrity_seal = NULL`.

### 10.2 Política de Rollback de Migración:
- El script DDL de rollback (`migraciones/v2/*_rollback_*.sql`) que elimine las columnas `integrity_*` estará permitido únicamente durante la fase de desarrollo previa a producción.
- En entorno de producción/staging con marcaciones registradas, el rollback DDL está **ESTRICTAMENTE PROHIBIDO** para evitar la destrucción de la evidencia criptográfica.
