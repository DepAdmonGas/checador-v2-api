# CHECADOR V2 API — CHANGELOG

## 2026-10-01 — V2.2-G8-A2 API Temporary Access Strict Contract Remediation
- **Slice:** G8-A2_TEMPORARY_ACCESS_STRICT_CONTRACT_REMEDIATION
- **Estado:** COMPLETE (56/56 Tests PASS, Baseline 18/18 Tests PASS)
- **Remediación:**
  - El primer intento de implementación G8-A falló la auditoría de conformidad contractual G8-A1 (Rutas erróneas, falta de cancel, falta de request_uuid/validation_uuid en el cliente, modelo de base de datos con 2 tablas no contractuales y estados no oficiales).
  - G8-A2 corrigió integralmente la implementación del API para alinearse 100% con `TEMPORARY_ACCESS_API_CONTRACT.md` V1.0 y `CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`.
- **Tablas Creadas:**
  - `op_rh_v2_solicitudes_acceso_temporal` (Única tabla física contractual).
  - La tabla previa `op_rh_v2_pins_acceso_temporal` fue eliminada del diseño y del script de migración.
- **Archivos Creados / Modificados:**
  - `migraciones/v2/2026_10_01_000001_create_v2_temporary_access_tables.sql` (Actualizado a modelo estricto de 1 sola tabla V1.0).
  - `migraciones/v2/2026_10_01_000001_rollback_v2_temporary_access_tables.sql`
  - `modelo/v2/Portal3JwtAuthHelper.php` (Validación de firma, `iss=portal3_auth`, `exp`, `type=access`, `sub`).
  - `modelo/v2/TemporaryAccessV2Service.php` (Transiciones exactas V1.0, idempotencia por `request_uuid` y `validation_uuid` para Lost ACK).
  - `modelo/v2/TemporaryAccessV2Controller.php` (Implementación de los 6 endpoints contractuales exactos).
  - `index.php` (Ruteo estricto con `request_uuid` en path).
  - `tests/run_v2_temporary_access_tests.php` (Runner de 56 pruebas de remediación).
- **Rutas Oficiales Físicamente Implementadas:**
  1. `POST /api/v2/temporary-access/requests`
  2. `GET /api/v2/temporary-access/requests`
  3. `POST /api/v2/temporary-access/requests/{request_uuid}/authorize`
  4. `POST /api/v2/temporary-access/requests/{request_uuid}/reject`
  5. `POST /api/v2/temporary-access/requests/{request_uuid}/cancel`
  6. `POST /api/v2/temporary-access/validate`

---

## 2026-09-28 — V2.1 API Raw Punch Ingestion
- **Slice:** API_V2_RAW_PUNCH_INGESTION
- **Estado:** COMPLETE (18/18 Tests PASS)
- **Tablas Creadas:**
  - `op_rh_v2_dispositivos`
  - `op_rh_v2_dispositivo_credenciales`
  - `op_rh_v2_dispositivo_identidad_personal`
  - `op_rh_v2_marcaciones_crudas`
- **Archivos Creados:**
  - `migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql`
  - `migraciones/v2/2026_09_28_000001_rollback_v2_raw_punch_tables.sql`
  - `modelo/v2/DispositivoV2.php`
  - `modelo/v2/PunchEventV2.php`
  - `modelo/v2/IngestaPunchV2Controller.php`
  - `docs/v2/PUNCH_EVENTS_INGESTION_API.md`
  - `tests/run_v2_tests.php`

---

## 2026-09-28 — HOTFIX Routing API V2
- **Problema:** Solicitudes al endpoint desplegado retornaban error HTTP 404.
- **Causa Raíz:** `index.php` evaluaba únicamente la condición `$partes_ruta[0] == 'portal-api-v.2.0'`, mientras que el deployment público sirve bajo `portal-api-v.1.0.1`.
- **Archivos Modificados:**
  - `index.php` (Actualización de la condición de ruteo para permitir ambos segmentos `portal-api-v.1.0.1` y `portal-api-v.2.0`).
