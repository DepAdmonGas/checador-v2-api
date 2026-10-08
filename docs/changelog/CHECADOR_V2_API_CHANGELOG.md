# CHECADOR V2 API — CHANGELOG

## 2026-10-07 — V2.5 Administrative Incidence & Evaluation Runtime Engine Implementation
- **Activity:** V2_5_ADMINISTRATIVE_INCIDENCE_EVALUATION_RUNTIME_IMPLEMENTATION
- **Estado:** COMPLETE (10/10 Runtime Test Blocks PASS in `tests/v2_administrative_runtime_tests.php`)
- **Componentes Implementados:**
  - `modelo/v2/journey/AdministrativeEvaluationService.php`: Servicio de dominio interno para gestión de maestro/revisiones de incidencias (`createIncidenceMaster`, `createIncidenceRevision`), materialización diaria de aplicaciones por turno (`applyIncidenceRevisionToShifts`), registro de linaje inmutable de ajustes administrativos (`createAdjustmentRevision`), y motor de decisiones y persistencia de evaluaciones administrativas (`evaluateCalculationRevision`).
  - `tests/v2_administrative_runtime_tests.php`: Suite de 10 bloques de prueba validando creación de incidencias, aplicación a turnos, linaje de ajustes, asociación exacta de proveniencia (`op_rh_v2_evaluaciones_incidencias`, `op_rh_v2_evaluaciones_ajustes`), derivación determinista de FALTA, detección de contradicciones (`CONTRADICTION_DETECTED`), resolución de conflictos por solapamiento (`OVERLAP_CONFLICT`), inmunidad para turnos DAY_OFF/UNSCHEDULED e idempotencia mediante huellas SHA256.
- **Invariantes Garantizadas:**
  - Cero controladores o rutas de API REST pública expuestas.
  - Cero cambios o migraciones de esquemas de base de datos (Las migraciones 000003 y 000004 permanecen 100% inalteradas byte por byte).
  - Cero cálculos monetarios o efectos de nómina.
  - Inmutabilidad total de marcaciones crudas, turnos programados, pareos P1 y cálculos P2.

---

## 2026-10-07 — V2.5-MA Administrative Incidences Provenance Additive Schema Implementation
- **Activity:** V2_5_MA_ADMINISTRATIVE_INCIDENCES_PROVENANCE_ADDITIVE_SCHEMA_IMPLEMENTATION
- **Estado:** COMPLETE (7/7 Test Blocks PASS in `tests/v2_administrative_provenance_schema_tests.php` covering 44 physical addendum invariants)
- **Componentes Implementados:**
  - `migraciones/v2/2026_10_07_000004_create_v2_administrative_provenance_tables.sql`: Migración UP aditiva creando 2 tablas de asociación inmutables (`op_rh_v2_evaluaciones_incidencias`, `op_rh_v2_evaluaciones_ajustes`) y añadiendo columnas de linaje de revisiones de ajustes (`uuid_ajuste_logico`, `revision_numero`, `estado_ajuste`, `id_ajuste_anterior`) con backfill determinista para filas de 000003.
  - `migraciones/v2/2026_10_07_000004_rollback_v2_administrative_provenance_tables.sql`: Migración DOWN aditiva eliminando tablas de asociación y columnas de linaje sin eliminar las 5 tablas base de 000003.
  - `tests/v2_administrative_provenance_schema_tests.php`: Suite de 7 bloques de prueba validando asociación exacta de proveniencia, semántica de conjunto vacío `[]` (0 filas asociadas), invariante de no alteración retroactiva sobre evaluaciones previas E1, linaje de revisiones de ajustes N+1, restricciones `ON DELETE RESTRICT` y reconstrucción determinista de huellas SHA256.
- **Invariantes Garantizadas:**
  - Migración 000003 permanece 100% inalterada e idéntica byte por byte (SHA256: `fcac402726488349479b0c95360980ff67d2ca8acb846e9df5f5643ac7b9efbf`).
  - Cero código de runtime de evaluación administrativa implementado.
  - Cero endpoints de API pública autorizados o creados.
  - Cero alteraciones o reescrituras a marcaciones crudas, turnos programados, pareos P1 o cálculos P2.

---

## 2026-10-07 — V2.5-M Administrative Incidences & Adjustments Physical Schema Implementation
- **Activity:** V2_5_M_ADMINISTRATIVE_INCIDENCES_SCHEMA_IMPLEMENTATION
- **Estado:** COMPLETE (10/10 Schema Test Blocks PASS covering 52 physical invariants)
- **Componentes Implementados:**
  - `migraciones/v2/2026_10_07_000003_create_v2_administrative_incidences_tables.sql`: Migración UP creando 5 tablas físicas (`op_rh_v2_incidencias_maestro`, `op_rh_v2_incidencias_revisiones`, `op_rh_v2_incidencias_aplicaciones`, `op_rh_v2_ajustes_administrativos`, `op_rh_v2_evaluaciones_administrativas`) y 2 disparadores de inmutabilidad (`tg_op_rh_v2_evaluaciones_admin_no_update`, `tg_op_rh_v2_evaluaciones_admin_no_delete`).
  - `migraciones/v2/2026_10_07_000003_rollback_v2_administrative_incidences_tables.sql`: Migración DOWN idempotente eliminando disparadores y tablas en orden inverso de claves foráneas.
  - `tests/v2_administrative_incidences_schema_tests.php`: Suite de pruebas automatizadas que valida la creación de esquemas, compatibilidad exacta de tipos FK, persistencia de rango maestro para incidencias multidía, aplicaciones diarias por turno, horas interpretadas aisladas de marcaciones crudas, evaluaciones administrativas inmutables append-only con huella SHA256 y banderas `FALTA`/`CONTRADICTION_DETECTED`/`REVIEW_REQUIRED`, protección contra eliminación en cascada (`ON DELETE RESTRICT`) e idempotencia de reversión.
- **Invariantes Garantizadas:**
  - Cero código de runtime de evaluación administrativa implementado.
  - Cero endpoints de API pública autorizados o creados.
  - Cero alteraciones o reescrituras a marcaciones crudas, turnos programados, pareos P1 o cálculos P2.
  - Cero columnas de cálculos monetarios o de nómina.

---

## 2026-10-07 — V2.4-P2 Motor de Jornada Journey Calculation Runtime Implementation
- **Activity:** V2_4_P2_MOTOR_DE_JORNADA_CALCULATION_RUNTIME_IMPLEMENTATION
- **Estado:** COMPLETE (14/14 Runtime Tests PASS, 30/30 Schema Tests PASS)
- **Componentes Implementados:**
  - `modelo/v2/journey/JourneyCalculationService.php`: Servicio interno del dominio para consumo de `ScheduledShift` e historial inmutable de pareos P1 (`op_rh_v2_pareos_jornada`), cálculo de duración trabajada en segundos (`segundos_trabajados_totales`), deltas de llegada/salida (`delta_llegada_segundos`, `delta_salida_segundos`), agregación de jornada dividida (excluyendo intervalos no trabajados), matemática exacta de timestamps UTC para turnos nocturnos que cruzan la medianoche, manejo de `PARTIAL_DATA` sin fabricar marcaciones sintéticas, `DAY_OFF_RECORDED` (0s programados), `UNSCHEDULED_RECORDED` (NULL programados), `UNMATCHED_NO_PUNCHES` (sin emitir FALTA), versionado por turno (`revision_numero`), huellas SHA256 para idempotencia (`fingerprint_ejecucion`) y persistencia append-only en `op_rh_v2_calculos_jornada` y `op_rh_v2_calculos_segmentos`.
  - `tests/v2_journey_calculation_runtime_tests.php`: Suite de 14 pruebas de runtime en comparación paralela sin afectar nómina validando todas las invariantes físicas y de dominio.
- **Invariantes Garantizadas:**
  - Cero endpoints de API pública autorizados o creados.
  - Cero cambios o migraciones de base de datos ejecutados.
  - Cero emisión de FALTA, RETARDO, SALIDA ANTICIPADA o montos pagables/monetarios de nómina.
  - Inmutabilidad total de marcaciones crudas, turnos programados e historial de pareos P1.

---

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
