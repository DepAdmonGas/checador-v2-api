# CHECADOR V2 API — CHANGELOG

## 2026-10-22 — Historical Schedule Resolution Fix (V8C_R1)
- **Activity:** `CHECADOR_V2_HISTORICAL_SCHEDULE_RESOLUTION_FIX_V8C_R1`
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT
- **Objetivo:** Corregir la resolución de horarios históricos en `modelo/v2/shift/ScheduleResolutionService.php` para permitir que Portal3 V8C consulte el horario efectivo de un colaborador en fechas pasadas dentro del intervalo de vigencia semiabierto `[valido_desde, valido_hasta)`.
- **Causa Raíz:** `ScheduleResolutionService::resolveSchedule` filtraba de forma estricta mediante `AND es_activo = 1`. Al cerrar o sustituir una asignación mediante `ShiftAssignmentService::closeAssignment`, el registro establece `valido_hasta = $closeDate` y `es_activo = 0`. Esto provocaba que cualquier consulta para fechas históricas dentro de su vigencia omitiera la asignación y retornara incorrectamente `UNSCHEDULED`.
- **Semántica Implementada:**
  - Intervalo semiabierto `[valido_desde, valido_hasta)`: `valido_desde` inclusive, `valido_hasta` exclusive (o NULL si indefinida).
  - Asignaciones históricas cerradas: resolubles para fechas $T$ que cumplan `valido_desde <= T < valido_hasta` aun cuando tengan `es_activo = 0`.
  - Asignaciones vigentes: resolubles para fechas $T$ que cumplan `es_activo = 1 AND valido_desde <= T AND (valido_hasta > T OR valido_hasta IS NULL)`.
  - Asignaciones revocadas / canceladas: con `es_activo = 0` y `valido_hasta IS NULL` o `valido_hasta <= valido_desde` nunca se resuelven.
  - Detección de ambigüedad / traslape: si existen múltiples asignaciones coincidentes para `(id_personal, id_estacion, dia_semana)` en la fecha $T$, la resolución falla cerrado emitiendo excepción `AMBIGUOUS_SCHEDULE_ASSIGNMENT` (409 Conflict en controlador) en lugar de elegir arbitrariamente con `LIMIT 1`.
- **Archivos Modificados:**
  - `modelo/v2/shift/ScheduleResolutionService.php`: Corregida la consulta SQL para soportar asignaciones cerradas e integrados chequeos de ambigüedad fail-closed.
  - `modelo/v2/shift/ShiftAssignmentService.php`: Añadido método de servicio `cancelAssignment(int $id)` y corregido el filtro temporal en `listAssignments` al proveer `fecha_referencia`.
  - `modelo/v2/shift/ShiftSchedulingV2Controller.php`: Soporte retrocompatible de parámetros de consulta (`workplace_id` / `id_estacion`, `date` / `fecha_local`, `reference_date` / `fecha_referencia`) y mapeo a HTTP 409 de `AMBIGUOUS_SCHEDULE_ASSIGNMENT`.
- **Pruebas:**
  - Creada suite focalizada: `tests/v2_historical_schedule_resolution_tests.php` con 13/13 pruebas exitosas (HIST-01 a HIST-13).
  - Regresión `tests/v2_shift_scheduling_runtime_tests.php`: 30/30 PASS.
  - Regresión `tests/v2_effective_schedule_authorization_runtime_tests.php`: 20/20 PASS.
  - Regresión `tests/v2_operational_authorization_policy_tests.php`: 15/15 PASS.
- **Invariantes:**
  - `DATABASE_MODIFIED: NO` (cero cambios DDL en MariaDB).
  - `PRODUCTION_MODIFIED: NO` (cero cambios en producción / preproducción).
  - `ARCHITECTURE_FREEZE_UNCHANGED: YES`.
  - Cruce de medianoche en turnos nocturnos (`dia_offset_fin = 1`) y Workplace Timezone Authority preservados 100%.

---

## 2026-10-14 — P3-H Event Classification Public Payload Contract Freeze V1.1
- **Activity:** `CHECADOR_V2_API_P3H_EVENT_CLASSIFICATION_CONTRACT_FREEZE_V1_1`
- **Estado:** CLOSED_FROZEN_OWNER_APPROVED
- **Objetivo:** Congelar formalmente la enmienda contractual V1.1 (`docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.1.md`) tras la aprobación explícita del Project Owner, corrigiendo de manera exclusiva la referencia errónea al endpoint de evaluación de jornada de V1.0.
- **Corrección de Endpoint:**
  - De: `GET /api/v2/journeys/on-demand` (erróneo en borrador V1.0)
  - A: `POST /api/v2/journeys/evaluate` (endpoint canónico e implementado en API y cliente Portal3).
- **Contrato Congelado:** `docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.1.md` (SHA-256: `df6b0bec7c6977cb0c12bf5409c8243528af145036474aa3adcf237b21f4711c`).
- **Reglas de Supersesión:**
  - V1.0 (`docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.0.md`, SHA-256: `c710165953f455352cf5675b7581a581be03a632a493e6a0c939276865d6658f`) permanece como evidencia histórica inmutable.
  - V1.1 supersede a V1.0 EXCLUSIVAMENTE en la referencia al endpoint del recurso de jornada.
  - Todas las demás semánticas de V1.0 (`pairing_role` [`ENTRY`, `EXIT`, `SURPLUS`, `UNMATCHED`], `segment_ordinal`, alcance de ambigüedad en segmento/jornada, soporte multi-segmento 3+ y 5+ marcaciones, prohibición de FALTA o SIN MARCACIÓN automáticas, y preservación de inmutabilidad) permanecen 100% idénticas e inalteradas.
- **Invariantes Arquitectónicas:**
  - Cero código de runtime modificado (`RUNTIME_FILES_MODIFIED = NONE`). El runtime de la API ya era correcto y congruente con los contratos canónicos de Journey (`POST /api/v2/journeys/evaluate`).
  - Cero rutas nuevas o alias (`NEW_API_ENDPOINT_REQUIRED = NO`, `RUNTIME_ROUTE_CHANGE_REQUIRED = NO`).
  - Cero mutaciones en base de datos (`DATABASE_MODIFIED = NO`).
  - Cero cambios de esquema (`SCHEMA_MODIFIED = NO`).
  - Cero cambios de ACL (`ACL_MODIFIED = NO`).
  - Pruebas no re-ejecutadas (`TESTS_RERUN = NO`).
- **Archivos Modificados:**
  - `docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.1.md` (congelado como `FROZEN_OWNER_APPROVED`).
  - `docs/changelog/CHECADOR_V2_API_CHANGELOG.md`
  - `docs/status/CHECADOR_V2_ACTIVITY_LEDGER.md` (Entrada 052)
  - `docs/status/CHECADOR_V2_CURRENT_STATUS.md`

---

## 2026-10-14 — P3-H Event Classification Public Payload Implementation
- **Activity:** `CHECADOR_V2_API_P3H_EVENT_CLASSIFICATION_PAYLOAD_IMPLEMENTATION`
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT
- **Objetivo:** Implementar el enriquecimiento aditivo y retrocompatible de payload público para la clasificación de eventos evaluados en el límite Journey On-Demand (`POST /api/v2/journeys/evaluate`) conforme al contrato congelado `docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.0.md` (SHA-256: `c710165953f455352cf5675b7581a581be03a632a493e6a0c939276865d6658f`).
- **Campos Aditivos Implementados:**
  - `pairing_role`: `string|null`, valores canónicos expuestos: `ENTRY`, `EXIT`, `SURPLUS`, `UNMATCHED`. Prohibido el uso de `AMBIGUOUS_PAIRING` a nivel de marcación (`AMBIGUITY_AS_PAIRING_ROLE = NO`).
  - `segment_ordinal`: `integer|null`, entero positivo (`1, 2, 3, ...`) asociado al segmento físico emparejado, o `null` si la marcación es `UNMATCHED`.
- **Ubicaciones en Payload:**
  - `data.evaluated_raw_events[]`: Enriquecido con `pairing_role` y `segment_ordinal`.
  - `data.provenance.evaluated_raw_events[]`: Espejeado con exactamente el mismo conjunto, garantizando 100% de paridad con la colección primaria.
- **Implementación de Runtime:**
  - `modelo/v2/journey/JourneyPairingService.php`: Modificado `getEvaluatedCandidatesForPairing(int $pairingId)` para realizar `LEFT JOIN op_rh_v2_pareos_segmentos s ON a.id_pareo_segmento = s.id` sobre `op_rh_v2_pareos_marcaciones_asociadas a` y `op_rh_v2_marcaciones_crudas m`, extrayendo de forma fidedigna `a.rol_marcacion` y `s.ordinal_segmento` ordenados determinísticamente por `m.ocurrido_at_utc ASC, m.id ASC`.
  - Propagación automática a `JourneyEvaluationV2Controller.php` sin alteraciones adicionales de controladores ni enrutadores.
- **Invariantes Arquitectónicas Verificadas:**
  - `BACKWARD_COMPATIBLE_ADDITIVE_CHANGE: YES`. Todos los campos existentes de `evaluated_raw_events` (`event_uuid`, `id_dispositivo`, `ocurrido_at_utc`, `metodo_captura`) permanecen intactos.
  - `NEW_SCHEMA_REQUIRED: NO`. Cero cambios de esquema, cero DDL, cero migraciones de base de datos.
  - `REQUIRES_P1_RECOMPUTATION: NO`. Se consumen los registros ya persistidos en `op_rh_v2_pareos_marcaciones_asociadas`.
  - `REQUIRES_P2_RECOMPUTATION: NO`. La revisión de cálculo y métricas permanecen inalteradas.
  - `INTERNAL_RAW_PK_EXPOSED: NO`. Claves primarias numéricas internas (`id_marcacion_cruda`, `id_pareo_segmento`) no son expuestas en el payload público.
  - Cero inferencia automática de `FALTA` o `SIN MARCACIÓN` (`AUTOMATIC_FALTA = NO`).
  - Cero mutaciones en `PunchEvent` (`op_rh_v2_marcaciones_crudas`).
  - Cero escritura dual legacy (`LEGACY_DUAL_WRITE = NO`) ni impacto en nómina (`PAYROLL_WRITE = NO`).
- **Archivos Creados / Modificados:**
  - Creado: `tests/v2_p3h_event_classification_payload_tests.php` (32 pruebas enfocadas P3HEC01-P3HEC30 + 2 SHA assertions).
  - Modificado: `modelo/v2/journey/JourneyPairingService.php` (`getEvaluatedCandidatesForPairing`).
- **Pruebas y Verificación:**
  - Suite Enfocada P3-H (`tests/v2_p3h_event_classification_payload_tests.php`): 32 PASS / 0 FAIL.
  - Suite Provenance Journey (`tests/v2_journey_provenance_boundary_tests.php`): 33 PASS / 0 FAIL.
  - Suite Límite Journey (`tests/v2_journey_boundary_tests.php`): 30 PASS / 0 FAIL.
  - Suite Límite Lectura P3-G (`tests/v2_p3g_administrative_read_boundary_tests.php`): 32 PASS / 0 FAIL.
  - Suite Creación y Revisión P3-G (`tests/v2_p3g_b_create_and_revision_tests.php`): 35 PASS / 0 FAIL.
  - Suite Acciones Ciclo de Vida P3-G (`tests/v2_p3g_c_lifecycle_actions_tests.php`): 35 PASS / 0 FAIL.
  - Suite Ejecución Evaluación P3-G (`tests/v2_p3g_d_evaluation_execution_tests.php`): 40 PASS / 0 FAIL.
  - Regresiones Core (9 suites adicionales: L2 Read Auth, Snapshot Parity, Snapshot Auth, Punch Read, Workplace Timezone Read, Effective Schedule Auth, Schedule Write Auth, SEC-L03 Auth, Temporary Access): 230 PASS / 0 FAIL.
  - Total Pruebas: 467 PASS / 0 FAIL.
  - Sintaxis PHP: 0 errores de sintaxis (`php -l`).

---

## 2026-10-14 — P3-H Event Classification Public Payload Contract Freeze V1.0
- **Activity:** `CHECADOR_V2_API_P3H_EVENT_CLASSIFICATION_CONTRACT_FREEZE_V1_0`
- **Estado:** FROZEN_OWNER_APPROVED
- **Objetivo:** Congelar formalmente el contrato de enriquecimiento aditivo de payload público para la clasificación de eventos en el endpoint Journey On-Demand (`GET /api/v2/journeys/on-demand`) tras la aprobación explícita del Project Owner.
- **Contrato Congelado:** `docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.0.md` (SHA-256: `c710165953f455352cf5675b7581a581be03a632a493e6a0c939276865d6658f`).
- **Campos Públicos Aditivos Definidos:**
  - `pairing_role`: `string|null`, valores canónicos permitidos: `ENTRY`, `EXIT`, `SURPLUS`, `UNMATCHED`. Prohibido el uso de `AMBIGUOUS_PAIRING` a nivel de marcación (`AMBIGUITY_AS_PAIRING_ROLE = NO`).
  - `segment_ordinal`: `integer|null`, enteros positivos (`1, 2, 3, ...`), o `null` si la marcación es `UNMATCHED` o el pareo no está disponible.
- **Alcance de Ambigüedad:** Se congela que la ambigüedad pertenece exclusivamente al nivel de segmento (`AMBIGUOUS_PAIRING`) o jornada (`AMBIGUOUS_PAIRING`). Las marcaciones candidatas dentro de un segmento ambiguo conservan sus roles físicos posicionales.
- **Mapeo de Presentación en Portal3 Aprobado:** `ENTRY` $\rightarrow$ "Entrada", `EXIT` $\rightarrow$ "Salida", `SURPLUS` $\rightarrow$ "Marcación adicional", `UNMATCHED` $\rightarrow$ "Sin emparejar".
- **Invariantes:**
  - Cero código de runtime modificado (`RUNTIME_FILES_MODIFIED = NONE`).
  - Cero mutaciones de base de datos (`DATABASE_MODIFIED = NO`).
  - Cero cambios de esquema (`NEW_SCHEMA_REQUIRED = NO`).
  - Cero recálculos (`REQUIRES_P1_RECOMPUTATION = NO`, `REQUIRES_P2_RECOMPUTATION = NO`).
  - Cero exposición de claves primarias internas de SQL (`INTERNAL_RAW_PK_EXPOSED = NO`).
  - Compatibilidad hacia atrás garantizada (`BACKWARD_COMPATIBLE_ADDITIVE_CHANGE = YES`).

## 2026-10-14 — P3-G Administrative Evaluation Execution Implementation (P3G-D)
- **Activity:** `CHECADOR_V2_API_P3G_D_ADMINISTRATIVE_EVALUATION_EXECUTION_IMPLEMENTATION`
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT
- **Objetivo:** Implementar la rebanada de ejecución de evaluación administrativa del límite API P3-G congelado (`P3G-D — ADMINISTRATIVE EVALUATION EXECUTION`) activando el endpoint protegido `POST /api/v2/administrative-evaluations/evaluate` conforme al contrato físico `docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md` (SHA-256: `e418687501fbdfdf89bcc6f11aab60e49226f265411c4590a39a5fa7799c374a`).
- **Endpoint de Ejecución Implementado:**
  - `POST /api/v2/administrative-evaluations/evaluate`: Ejecución de evaluación administrativa por jornada (Contrato Sección 9.11). Consume la revisión existente e inmutable de cálculo P2 y las aplicaciones activas de incidencias administrativas sin recalcular pareo P1, cálculo P2, membresía de marcaciones ni turnos programados.
- **Seguridad y Control de Acceso:**
  - Autenticación estricta con Portal3 JWT (`iss: portal3_auth`, usuario activo en `tb_usuarios`). Rechazo estricto de Device Auth y credenciales biométricas (401 `UNAUTHORIZED`).
  - Autorización en ACL dedicada: Requiere `checador-v2-incidencias-resolucion.editar = 1` OR `checador-v2-incidencias-autorizacion.editar = 1`.
  - Alcance de estación multi-estación obligatorio en `op_rh_localidades.id`; alcance vacío o fuera de perfil genera 403 `STATION_SCOPE_DENIED`.
  - Trabajador canónico verificado contra `op_rh_personal.id` (404 `WORKER_NOT_FOUND`).
  - Concurrencia optimista opcional: `expected_calculation_revision_id` verificado contra la revisión de cálculo activa (mismatch genera 409 `STALE_REVISION_CONFLICT`).
- **Compuerta Humana para FALTA (AUTOMATIC_FALTA = NO):**
  - Cero marcaciones + turno programado + Nivel 2 VERIFIED + ausencia de incidencias NUNCA emite `FALTA` automáticamente.
  - En ausencia de autorización humana explícita, emite `REVIEW_REQUIRED` con `human_review_status = 'PENDING_REVIEW'`.
  - La clasificación final como `FALTA` requiere obligatoriamente `checador-v2-incidencias-autorizacion.editar = 1` y el flag explícito `authorize_falta: true`. Permisos de resolución solos que intenten pasar `authorize_falta: true` son denegados con 403 `FUNCTION_PERMISSION_DENIED`.
- **Idempotencia e Inmutabilidad:**
  - Encabezado `Idempotency-Key` obligatorio (max 64 caracteres). Reintento idéntico retorna 200 con la respuesta original cacheada sin crear evaluaciones duplicadas. Reintento con payload divergente retorna 409 `IDEMPOTENCY_CONFLICT`.
  - Disparadores de base de datos (`tg_op_rh_v2_evaluaciones_admin_no_update`, `tg_op_rh_v2_evaluaciones_admin_no_delete`) verificados y activos, protegiendo las evaluaciones contra cualquier actualización o eliminación destructiva.
- **Invariantes Arquitectónicas:**
  - `SCHEMA_PREREQUISITE_REQUIRED: NO`. Cero cambios de esquema, cero DDL, cero migraciones.
  - Cero mutaciones a `PunchEvent` (`op_rh_v2_marcaciones_crudas`).
  - Cero escritura dual legacy (`LEGACY_DUAL_WRITE = NO`).
  - Cero efectos o cálculos de nómina (`PAYROLL_WRITE = NO`).
  - `policy_rule_version` permanece estrictamente `null` en el payload de respuesta.
  - Actor derivado estrictamente de JWT `sub`; actores spoofed en body ignorados.
- **Archivos Modificados / Creados:**
  - Creado: `tests/v2_p3g_d_evaluation_execution_tests.php` (40 pruebas P3GD01-P3GD40).
  - Modificado: `modelo/v2/journey/AdministrativeEvaluationService.php` (`evaluateShiftAdministration`, soporte de gate `enforce_p3g_human_falta_gate` en `evaluateCalculationRevision`).
  - Modificado: `modelo/v2/Portal3JwtAuthHelper.php` (`authorizeAdministrativeEvaluationExecution`).
  - Modificado: `modelo/v2/AdministrativeIncidenceV2Controller.php` (`handleExecuteAdministrativeEvaluation`).
  - Modificado: `index.php` (enrutamiento de `POST /api/v2/administrative-evaluations/evaluate` y rechazo con 405 Method Not Allowed / `Allow: POST` para métodos no-POST).
  - Modificado: `tests/v2_p3g_administrative_read_boundary_tests.php` (P3GA32 adaptado para verificar 405 en método GET no soportado).
  - Modificado: `tests/v2_p3g_b_create_and_revision_tests.php` (P3GB33 adaptado para verificar 405 en método GET no soportado).
  - Modificado: `tests/v2_p3g_c_lifecycle_actions_tests.php` (P3GC35 adaptado para verificar 405 en método GET no soportado).
- **Pruebas y Verificación:**
  - Suite P3G-D (`tests/v2_p3g_d_evaluation_execution_tests.php`): 40 PASS / 0 FAIL.
  - Suite P3G-C (`tests/v2_p3g_c_lifecycle_actions_tests.php`): 35 PASS / 0 FAIL.
  - Suite P3G-B (`tests/v2_p3g_b_create_and_revision_tests.php`): 35 PASS / 0 FAIL.
  - Suite P3G-A (`tests/v2_p3g_administrative_read_boundary_tests.php`): 32 PASS / 0 FAIL.
  - Regresiones Core (10 suites adicionales): 191 PASS / 0 FAIL.
  - Total Pruebas: 333 PASS / 0 FAIL.
  - Smoke de Desarrollo en `bd_admongas`: `NOT_RUN_MISSING_DEV_ACL_CONFIGURATION` (cero inserciones en shared dev).

---

## 2026-10-14 — P3-G Administrative Lifecycle Actions Implementation (P3G-C)
- **Activity:** `CHECADOR_V2_API_P3G_C_ADMINISTRATIVE_LIFECYCLE_IMPLEMENTATION`
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT
- **Objetivo:** Implementar la rebanada de acciones de ciclo de vida administrativo del límite API P3-G congelado (`P3G-C — ADMINISTRATIVE LIFECYCLE ACTIONS`) conforme al contrato físico `docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md` (SHA-256: `e418687501fbdfdf89bcc6f11aab60e49226f265411c4590a39a5fa7799c374a`).
- **Endpoints de Ciclo de Vida Implementados:**
  - `POST /api/v2/incidences/{uuid_incidencia}/resolve`: Resolución de incidencia administrativa (Contrato 9.6). Requiere permiso `checador-v2-incidencias-resolucion.editar = 1`. Transiciona estado de revisión y estado maestro a `RESOLVED_PENDING_AUTHORIZATION`. Rechaza estados terminales (`CANCELLED`, `REVOKED`) con 409 `INVALID_LIFECYCLE_TRANSITION`.
  - `POST /api/v2/incidences/{uuid_incidencia}/authorize`: Autorización formal de incidencia (Contrato 9.7). Requiere permiso `checador-v2-incidencias-autorizacion.editar = 1`. Transiciona estado de revisión y maestro a `ACTIVE`. Rechaza estados cancelados o rechazados con 409 `INVALID_LIFECYCLE_TRANSITION`. Los permisos de resolución o creación por sí solos son denegados (403 `FUNCTION_PERMISSION_DENIED`).
  - `POST /api/v2/incidences/{uuid_incidencia}/reject`: Rechazo administrativo de incidencia (Contrato 9.8). Requiere permiso `checador-v2-incidencias-autorizacion.editar = 1`. Transiciona estado de revisión y maestro a `REJECTED`.
  - `POST /api/v2/incidences/{uuid_incidencia}/revoke`: Revocación/Cancelación administrativa (Contrato 9.9). Requiere permiso `checador-v2-incidencias-autorizacion.eliminar = 1 OR editar = 1`. Transiciona estado de revisión y maestro a `CANCELLED`. La revisión autorizada previa permanece inmutable y visible en el historial.
- **Límite Protegido No Implementado:**
  - `POST /api/v2/administrative-evaluations/evaluate`: Permanece estrictamente bloqueado con 405 Method Not Allowed (`Allow: GET`).
- **Control de Concurrencia e Idempotencia:**
  - Verificación de `expected_current_revision` obligatoria en los 4 endpoints para prevenir sobreescrituras concurrentes (mismatch genera 409 `STALE_REVISION_CONFLICT`).
  - Encabezado `Idempotency-Key` obligatorio (max 64 caracteres). Reintento idéntico retorna 200 con la respuesta original cacheada sin crear revisiones duplicadas. Payload divergente genera 409 `IDEMPOTENCY_CONFLICT`.
  - Orden estricto de validación: Idempotencia -> Concurrencia (expected_current_revision) -> Transición de Ciclo de Vida.
- **Invariantes Arquitectónicas:**
  - `SCHEMA_PREREQUISITE_REQUIRED: NO`. Cero cambios de esquema, cero DDL, cero migraciones.
  - Cero mutaciones a `PunchEvent` (`op_rh_v2_marcaciones_crudas`).
  - Cero escritura dual legacy (`LEGACY_DUAL_WRITE = NO`).
  - Cero efectos o cálculos de nómina (`PAYROLL_WRITE = NO`).
  - Cero inferencia de `FALTA` o `SIN MARCACIÓN` (`AUTOMATIC_FALTA = NO`).
  - Actor derivado estrictamente de JWT `sub`.
- **Archivos Modificados / Creados:**
  - Creado: `tests/v2_p3g_c_lifecycle_actions_tests.php` (35 pruebas P3GC01-P3GC35).
  - Modificado: `modelo/v2/journey/AdministrativeEvaluationService.php` (soporte separado de `estado_actual` y `estado_revision` en `createIncidenceRevision`).
  - Modificado: `modelo/v2/Portal3JwtAuthHelper.php` (`authorizeIncidenceResolution`, `authorizeIncidenceAuthorization`, `authorizeIncidenceRejection`, `authorizeIncidenceRevocation`).
  - Modificado: `modelo/v2/AdministrativeIncidenceV2Controller.php` (`handleResolveIncident`, `handleAuthorizeIncident`, `handleRejectIncident`, `handleRevokeIncident`).
  - Modificado: `index.php` (enrutamiento de los 4 endpoints POST de ciclo de vida).
  - Modificado: `tests/v2_p3g_administrative_read_boundary_tests.php` (P3GA28-P3GA31 adaptados para verificar 405 en métodos GET no soportados, 32/32 PASS).
  - Modificado: `tests/v2_p3g_b_create_and_revision_tests.php` (P3GB29-P3GB32 adaptados para verificar 405 en métodos GET no soportados, 35/35 PASS).
- **Pruebas y Verificación:**
  - Suite P3G-C (`tests/v2_p3g_c_lifecycle_actions_tests.php`): 35 PASS / 0 FAIL.
  - Suite P3G-B (`tests/v2_p3g_b_create_and_revision_tests.php`): 35 PASS / 0 FAIL.
  - Suite P3G-A (`tests/v2_p3g_administrative_read_boundary_tests.php`): 32 PASS / 0 FAIL.
  - Regresiones Core (10 suites adicionales): 191 PASS / 0 FAIL.
  - Total Pruebas: 293 PASS / 0 FAIL.
  - Smoke de Desarrollo en `bd_admongas`: `NOT_RUN_MISSING_DEV_ACL_CONFIGURATION` (cero inserciones en shared dev).

---

## 2026-10-14 — P3-G Administrative Create & Revision Boundary Implementation (P3G-B)
- **Activity:** `CHECADOR_V2_API_P3G_B_CREATE_AND_REVISION_IMPLEMENTATION`
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT
- **Objetivo:** Implementar la rebanada de mutación append-only del límite API P3-G congelado (`P3G-B — CREATE AND REVISION BOUNDARY`) conforme al contrato físico `docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md` (SHA-256: `e418687501fbdfdf89bcc6f11aab60e49226f265411c4590a39a5fa7799c374a`).
- **Endpoints de Mutación Implementados:**
  - `POST /api/v2/incidences`: Creación de maestro de incidencia administrativa e inserción de revisión inicial 1 con estado `PENDING_APPROVAL`. Validación de trabajador canónico (`op_rh_personal`), estación autorizada en perfil (`op_rh_localidades`), categoría válida y fechas locales. Encabezado `Idempotency-Key` obligatorio (max 64 chars). Idempotencia estricta: reintento idéntico retorna 201/200 con el registro existente; reintento con payload divergente retorna 409 `IDEMPOTENCY_CONFLICT`. Retorna payload conforme a Sección 9.4.
  - `POST /api/v2/incidences/{uuid_incidencia}/revisions`: Creación de revisión monótona append-only (N+1). Control de concurrencia optimista obligatorio mediante `expected_current_revision` (mismatch retorna 409 `STALE_REVISION_CONFLICT`). Preservación inmutable byte-por-byte de revisiones anteriores. Replay idempotente validado. Retorna payload conforme a Sección 9.5.
- **Rutas de Ciclo de Vida Avanzado Protegidas (405 Method Not Allowed):**
  - Todas las operaciones avanzadas permanecen bloqueadas en este slice: `POST /api/v2/incidences/{uuid}/resolve` (405), `POST /api/v2/incidences/{uuid}/authorize` (405), `POST /api/v2/incidences/{uuid}/reject` (405), `POST /api/v2/incidences/{uuid}/revoke` (405), `POST /api/v2/administrative-evaluations/evaluate` (405).
  - Intentos de forzar transiciones de ciclo de vida (`RESOLVED`, `AUTHORIZED`, `REJECTED`, `REVOKED`, `CANCELLED`) mediante el endpoint general de revisiones son rechazados con 422 `LIFECYCLE_TRANSITION_FORBIDDEN`.
- **Seguridad y Control de Acceso:**
  - Autenticación estricta con JWT de Portal3 (`iss: portal3_auth`, usuario activo en `tb_usuarios`).
  - Autorización en ACL dedicada:
    - Creación de incidencia: `checador-v2-incidencias.crear = 1`.
    - Creación de revisión: `checador-v2-incidencias-resolucion.editar = 1 OR checador-v2-incidencias.crear = 1`.
  - Alcance de estación multi-estación obligatorio en `op_rh_localidades.id`; alcance vacío o fuera de perfil genera 403 `STATION_SCOPE_DENIED`.
  - Identidad del actor derivada estrictamente del token JWT (`sub`). Actores proporcionados en payload por el cliente son completamente ignorados.
  - Rechazo de Device Auth y acceso directo desde navegador (401 `UNAUTHORIZED`).
- **Invariantes Arquitectónicas Garantizadas:**
  - Cero cambios de esquema DDL (`SCHEMA_PREREQUISITE_REQUIRED: NO`, cero migraciones).
  - Cero mutaciones a `PunchEvent` (`op_rh_v2_marcaciones_crudas`).
  - Cero escritura dual en legacy (`LEGACY_DUAL_WRITE = NO`, cero escrituras a `op_rh_personal_asistencia` ni `op_rh_personal_asistencia_incidencia`).
  - Cero efectos o cálculos de nómina (`PAYROLL_WRITE = NO`).
  - Prohibición absoluta de inferir `FALTA` automáticamente (`AUTOMATIC_FALTA = NO`).
  - Preservación de conflictos como `REVIEW_REQUIRED` sin preferencia inventada.
- **Archivos Modificados:**
  - `modelo/v2/AdministrativeIncidenceV2Controller.php`: Incorporación de `handleCreateIncident`, `handleCreateIncidentRevision`, helper de saneamiento `extractCleanReason`, y extracción insensible a mayúsculas de `Idempotency-Key`.
  - `modelo/v2/journey/AdministrativeEvaluationService.php`: Soporte para `estado_actual` / `estado_revision` en `createIncidenceMaster`, verificación de `expected_current_revision` en `createIncidenceRevision`, y materialización condicionada a `ACTIVE`.
  - `modelo/v2/Portal3JwtAuthHelper.php`: Método `authorizeIncidenceRevision` soportando resolución o creación.
  - `index.php`: Enrutamiento para `POST /api/v2/incidences` y `POST /api/v2/incidences/{uuid}/revisions`.
  - Creado: `tests/v2_p3g_b_create_and_revision_tests.php`: Suite completa P3GB01–P3GB35 (35/35 PASS).
  - `tests/v2_p3g_administrative_read_boundary_tests.php`: Adaptación de P3GA26/P3GA27 para validar 405 en métodos no soportados (`PUT /incidences`, `GET /revisions`), manteniendo 32/32 PASS.
- **Pruebas y Verificación:**
  - Suite P3G-B (`tests/v2_p3g_b_create_and_revision_tests.php`): 35 PASS / 0 FAIL.
  - Suite P3G-A Read Boundary (`tests/v2_p3g_administrative_read_boundary_tests.php`): 32 PASS / 0 FAIL.
  - Regresiones Core (10 suites adicionales): 191 PASS / 0 FAIL.
  - Total Pruebas Ejecutadas: 258 PASS / 0 FAIL / 0 SKIPPED.
  - Smoke de Desarrollo en `bd_admongas`: `NOT_RUN_MISSING_DEV_ACL_CONFIGURATION` (cero inserciones en shared dev).
- **Invariantes:** Architecture Freeze SHA-256 intacto (`87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`). Contrato API P3-G SHA-256 intacto (`e418687501fbdfdf89bcc6f11aab60e49226f265411c4590a39a5fa7799c374a`). Cero staging, commits o push.

## 2026-10-14 — P3-G Administrative Read Boundary Implementation (P3G-A)
- **Activity:** `CHECADOR_V2_API_P3G_A_READ_BOUNDARY_IMPLEMENTATION`
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT
- **Objetivo:** Implementar la rebanada mínima y segura de tiempo de ejecución del límite API P3-G congelado (`P3G-A — READ-ONLY ADMINISTRATIVE INCIDENCE / EVALUATION BOUNDARY`) conforme al contrato físico `docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md` (SHA-256: `e418687501fbdfdf89bcc6f11aab60e49226f265411c4590a39a5fa7799c374a`).
- **Endpoints de Lectura Implementados:**
  - `GET /api/v2/incidences`: Consulta de incidencias administrativas aplicables por trabajador (`op_rh_personal.id`), estación (`op_rh_localidades.id`) y rango de fechas locales (`from`, `to`), filtrando por categoría y estatus opcionales. Devuelve payload exacto conforme a Sección 9.2 del contrato.
  - `GET /api/v2/incidences/{uuid_incidencia}`: Consulta de detalle e historial completo de revisiones append-only ordenadas determinísticamente por `revision_numero ASC`, conforme a Sección 9.3 del contrato.
  - `GET /api/v2/administrative-evaluations`: Consulta pura de lectura de la evaluación administrativa existente para trabajador, estación y fecha de jornada (`work_date`), con enlaces a incidencias y ajustes. Si no existe evaluación previa, retorna `evaluation_exists: false` y `evaluation: null` con HTTP 200 OK sin invocar Level 1 ni Level 2.
- **Rutas de Escritura Bloqueadas (405 Method Not Allowed):**
  - Cero endpoints de escritura habilitados en P3G-A. Toda llamada a `POST /api/v2/incidences`, `POST /api/v2/incidences/{uuid}/revisions`, `POST /api/v2/incidences/{uuid}/resolve`, `POST /api/v2/incidences/{uuid}/authorize`, `POST /api/v2/incidences/{uuid}/reject`, `POST /api/v2/incidences/{uuid}/revoke` y `POST /api/v2/administrative-evaluations/evaluate` retorna HTTP 405 Method Not Allowed con encabezado `Allow: GET`.
- **Seguridad y Control de Acceso:**
  - Autenticación estricta con JWT de Portal3 (`iss: portal3_auth`, usuario activo en `tb_usuarios`).
  - Autorización en namespace ACL dedicado: `checador-v2-incidencias.leer`.
  - Verificación estricta de alcance de estación (`op_rh_localidades.id`), rechazando alcances vacíos o estaciones fuera de perfil con 403 `STATION_SCOPE_DENIED`.
  - Prohibición total de acceso directo desde navegador y Device Auth (401 `UNAUTHORIZED`).
  - Resolución canónica de trabajador exclusivamente contra `op_rh_personal.id` (404 `WORKER_NOT_FOUND`).
- **Archivos Creados / Modificados:**
  - Creado: `modelo/v2/AdministrativeIncidenceV2Controller.php`.
  - Creado: `tests/v2_p3g_administrative_read_boundary_tests.php`.
  - Modificado: `modelo/v2/Portal3JwtAuthHelper.php` (incorporado método `authorizeIncidences` y genérico `authorizeModuleByKey`).
  - Modificado: `index.php` (enrutamiento de `/api/v2/incidences` y `/api/v2/administrative-evaluations`, con rechazo 405 a métodos no-GET).
  - Modificado: `tests/v2_journey_boundary_tests.php` y `tests/v2_journey_provenance_boundary_tests.php` (idempotencia de fixtures con TRUNCATE bajo FK=0).
- **Pruebas y Verificación:**
  - Suite P3G-A (`tests/v2_p3g_administrative_read_boundary_tests.php`): 32 PASS / 0 FAIL (P3GA01 a P3GA32).
  - Regresión de Procedencia de Jornada (`tests/v2_journey_provenance_boundary_tests.php`): 33 PASS / 0 FAIL.
  - Regresión de Límite de Jornada (`tests/v2_journey_boundary_tests.php`): 30 PASS / 0 FAIL.
  - Regresión de Autorización Level 2 (`tests/v2_level2_read_authorization_tests.php`): 20 PASS / 0 FAIL.
  - Regresión de Paridad de Snapshot (`tests/v2_snapshot_parity_tests.php`): 14 PASS / 0 FAIL.
  - Regresión de Cumplimiento de Snapshot (`tests/v2_snapshot_authorization_compliance_tests.php`): 16 PASS / 0 FAIL.
  - Regresión de Lectura de Punch (`tests/v2_punch_read_api_tests.php`): 11 PASS / 0 FAIL.
  - Regresión de Timezone de Localidad (`tests/v2_workplace_timezone_read_endpoint_tests.php`): 23 PASS / 0 FAIL.
  - Regresión de Turno Efectivo (`tests/v2_effective_schedule_authorization_runtime_tests.php`): 20 PASS / 0 FAIL.
  - Regresión de Escritura de Turno (`tests/v2_schedule_write_authorization_contract_tests.php`): 24 PASS / 0 FAIL.
  - Verificación de no-mutación estricta de base de datos (P3GA25): Estado de base de datos byte-idéntico antes y después de operaciones de lectura.
  - Smoke Seguro de Desarrollo en `bd_admongas`: `NOT_RUN_MISSING_DEV_ACL_CONFIGURATION` (cero inserciones de ACL en desarrollo compartido).
- **Invariantes Preservadas:** Architecture Freeze SHA-256 intacto. Contrato API P3-G SHA-256 intacto. Cero modificaciones de esquema DDL. Cero escritura dual en legacy (`LEGACY_DUAL_WRITE = NO`). Cero inferencia automática de `FALTA` o `SIN MARCACIÓN`. Cero staging, commits o push.

## 2026-10-14 — P3-G Administrative API Boundary Contract Freeze V1.0
- **Activity:** `API_P3G_BOUNDARY_CONTRACT_FREEZE_V1_0`
- **Estado:** CLOSED_FROZEN_OWNER_APPROVED
- **Objetivo:** Formalizar y congelar el contrato del límite API server-to-server (`docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md`) correspondiente al hito Portal3 P3-G (incidencias administrativas, vacaciones, permisos, incapacidades, revisiones inmutables, resolución, autorización y evaluación administrativa).
- **Contrato Físico Congelado:** Creado y congelado `docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md` (SHA-256: `e418687501fbdfdf89bcc6f11aab60e49226f265411c4590a39a5fa7799c374a`), emparejado con el contrato de Portal3 (SHA-256: `e26e888015b27f9f1023e19cf4e005795836a99fd81a98c5638589fde5ca234d`) y alineado con Architecture Freeze (SHA-256: `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`).
- **Arquitectura Opción B y Reglas de Negocio:**
  - V2 es canónico para incidencias, revisiones, ajustes y evaluaciones mediante el servicio de dominio existente `AdministrativeEvaluationService`.
  - Adaptadores legacy de vacaciones y permisos son de sólo lectura (`READ_ONLY`). Adaptador automático de incapacidades permanece bloqueado (`BLOCKED_BY_SOURCE_AUTHORITY`).
  - Immutabilidad estricta de `PunchEvent`: prohibición total de alterar marcas, sustituirlas o fabricar marcas sintéticas.
  - Cero escritura dual en legacy (`LEGACY_DUAL_WRITE = NO`). Prohibido escribir en `op_rh_personal_asistencia` u `op_rh_personal_asistencia_incidencia`.
  - Integración con nómina diferida (`PAYROLL_INTEGRATION = DEFERRED`). Cero cálculo de salarios o deducciones.
  - Manejo de conflictos: default estricto a `REVIEW_REQUIRED`. Cero precedencia automática.
  - Invariante de `FALTA`: nunca se infiere automáticamente; requiere autorización humana explícita por rol facultado.
  - Ciclo de vida append-only: toda modificación, resolución o revocación/cancelación genera una nueva revisión; revisiones previas son inmutables.
- **Seguridad y Transporte:**
  - Límite exclusivo Portal3 backend -> Checador V2 API (`Portal3 backend → API only`).
  - Prohibido acceso directo desde navegador (`BROWSER_DIRECT_API = NO`) y prohibido Device Auth (`DEVICE_AUTH_ALLOWED = NO`).
  - Autenticación mediante JWT de Portal3 (`iss: portal3_auth`, usuario activo `tb_usuarios.estatus = 0`, verificación de alcance de localidad `op_rh_localidades.id`).
  - Namespaces ACL dedicados: `checador-v2-incidencias`, `checador-v2-incidencias-resolucion`, `checador-v2-incidencias-autorizacion`. Permiso genérico `recursos-humanos.editar` carece de validez.
- **Modelo de Recursos API:**
  - Definidos 10 endpoints: consulta de incidencias aplicables, detalle e historial de revisiones, creación de incidencia, adición de revisión, resolución de conflicto, autorización, rechazo, revocación append-only, consulta de evaluación administrativa y ejecución de evaluación.
- **Invariantes del Freeze:** Cero archivos de código en ejecución modificados (`RUNTIME_FILES_MODIFIED: NONE`), cero cambios de base de datos o esquema (`SCHEMA_PREREQUISITE_REQUIRED: NO`), cero filas de ACL creadas, cero modificaciones en producción, cero staging, commits o push.

## 2026-10-13 — Journey Provenance Boundary V1.1
- **Activity:** `CHECADOR_V2_API_JOURNEY_PROVENANCE_BOUNDARY_V1_1`
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT
- **Objetivo:** Extender aditivamente el endpoint de evaluación de jornada server-to-server (`POST /api/v2/journeys/evaluate`) con la membresía exacta del conjunto candidato evaluado en P1 (`evaluated_raw_events`, `evaluated_raw_event_count`) y la procedencia técnica completa (`provenance`, timestamps `creado_at_utc`, enlace UUID en segmentos) requerida por Portal3 P3-F para auditoría y reproducibilidad.
- **Contrato Físico Aditivo V1.1:** Creado y congelado `docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.1.md` (SHA-256: `7f71372d9deceb6820e6e71e1be38dadd12e87cd5bb4a5f69bcd250ee85c6f3f`). Contrato V1.0 preservado intacto (SHA-256: `9eda2b2c7174154d6524f7130ec197678958801df41004925a60928462876c9b`).
- **Servicio y Controlador Evolucionados:**
  - `JourneyPairingService.php`: Implementado `getEvaluatedCandidatesForPairing(int $pairingId): array` consultando `op_rh_v2_pareos_marcaciones_asociadas` e `op_rh_v2_marcaciones_crudas` (orden determinista `ocurrido_at_utc ASC, id ASC`, excluyendo PKs internas, preservando `id_dispositivo` nullable sin forzar a 0). Agregada reconstrucción de segmentos en reutilización idempotente.
  - `JourneyEvaluationV2Controller.php`: Incorporada serialización del bloque `provenance` completo, enriquecimiento de segmentos con `entry_event_uuid` y `exit_event_uuid`, inclusión de `creado_at_utc` en `pairing` y `calculation`, exposición de `evaluated_raw_events` y `evaluated_raw_event_count` tanto a nivel raíz de `data` como dentro de `provenance`. `policy_rule_version` explícitamente `null` (sin fabricar versiones sintéticas).
- **Pruebas y Regresión:**
  - Suite de Procedencia de Jornada (JPV01–JPV30 + Reproducibilidad + Aislamiento de Marcaciones Tardías + Reutilización Idempotente): 33 PASS / 0 FAIL.
  - Suite de Límite de Jornada (JRB01–JRB30): 30 PASS / 0 FAIL (compatibilidad hacia atrás V1.0 verificada).
  - Regresiones previas: 100% PASS (Pairing Schema 25/25, Pairing Runtime 16/16, Calculation Schema 30/30, Calculation Runtime 14/14, Shift Scheduling Schema 20/20, Shift Scheduling Runtime 30/30, Effective Schedule Auth 20/20, Schedule Write Auth 24/24, Timezone Authority 25/25, Timezone Read 23/23, SEC-L03 Auth 34/34, Snapshot Auth 16/16, Level 2 Read Auth 20/20, Punch Read API 11/11, Temporary Access 62/62).
  - PHP Lint: PASS (0 errores de sintaxis).
  - Smoke Seguro de Lectura en `bd_admongas`: HTTP 200, `evaluated: false`, `estado_jornada: UNSCHEDULED`, `journey_status: UNSCHEDULED_DAY`. Cero marcas sintéticas o fechas falsas (`evaluated_raw_events === null`, `p1_created_at_utc === null`, `p2_created_at_utc === null`, `policy_rule_version === null`). Cero mutaciones en BD persistente (0 turnos, 0 pareos, 0 cálculos).
  - Smoke de Autenticación Negativa en `bd_admongas`: 401 sin JWT, 403 fuera de alcance, 401 Device Auth.
- **Invariantes:** Architecture Freeze SHA-256 intacto. Cero modificaciones en Portal3 o Lector. Cero modificaciones de esquema DDL en `bd_admongas`. Cero commits, staging o push.

## 2026-10-13 — Journey On-Demand Portal3 Boundary V1
- **Activity:** `CHECADOR_V2_API_JOURNEY_ON_DEMAND_PORTAL3_BOUNDARY_V1`
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT
- **Objetivo:** Exponer el dominio autoritativo de Evaluación de Jornada (P1 Pareo + P2 Cálculo) a través de un límite de servicio server-to-server (`POST /api/v2/journeys/evaluate`) para desbloquear la integración de Portal3 P04D / P3-E.
- **Contrato Físico Aditivo:** Creado y congelado `docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.0.md` (SHA-256: `9eda2b2c7174154d6524f7130ec197678958801df41004925a60928462876c9b`).
- **Controlador Implementado:** `modelo/v2/journey/JourneyEvaluationV2Controller.php` orquesta los servicios de dominio existentes (`ScheduleResolutionService`, `ScheduledShiftMaterializationService`, `JourneyPairingService` [v2.4-p1], `JourneyCalculationService` [v2.4-p2], `Level2ServerService`, `WorkplaceTimezoneAuthorityService`) sin duplicar ni reimplementar algoritmos. Enrutado en `index.php`.
- **Autenticación y Alcance:** Autenticación estricta con JWT de Portal3 (`iss: portal3_auth`, usuario activo, `biometricos.leer = 1`) y verificación de alcance en `op_rh_localidades.id`. Verificación estricta de trabajador en `op_rh_personal.id` (404 si desconocido, rechazo de IDs no numéricos o `tb_usuarios.id` con 400/404). Autoridad horaria canónica de localidad obligatoria. Rechazo de Device Auth con 401.
- **Corrección de Alineación de Contrato:**
  - `ScheduleResolutionService.php`: Agregados `scheduled_start_utc` y `scheduled_end_utc` calculados en los segmentos resueltos para el payload de turnos materializados.
  - `JourneyPairingService.php`: Agregado fallback defensivo para asignar límites de inicio/fin de jornada y tipo de segmento en turnos de un solo segmento.
- **Pruebas y Regresión:**
  - Suite de Límite de Jornada (JRB01–JRB30): 30 PASS / 0 FAIL.
  - Journey Pairing Schema: 25 PASS / 0 FAIL.
  - Journey Pairing Runtime: 16 PASS / 0 FAIL.
  - Journey Calculation Schema: 30 PASS / 0 FAIL.
  - Journey Calculation Runtime: 14 PASS / 0 FAIL.
  - Shift Scheduling Schema: 20 PASS / 0 FAIL.
  - Shift Scheduling Runtime: 30 PASS / 0 FAIL.
  - Effective Schedule Read Auth: 20 PASS / 0 FAIL.
  - Schedule Write Auth Contract: 24 PASS / 0 FAIL.
  - Workplace Timezone Authority: 25 PASS / 0 FAIL.
  - Workplace Timezone Read Endpoint: 23 PASS / 0 FAIL.
  - SEC-L03 Authorization: 34 PASS / 0 FAIL.
  - Snapshot Auth Compliance (SRA01–SRA16): 16 PASS / 0 FAIL.
  - Level 2 Read Auth: 20 PASS / 0 FAIL.
  - Punch Read API: 11 PASS / 0 FAIL.
  - Temporary Access: 62 PASS / 0 FAIL.
  - PHP Lint: PASS en todos los archivos.
  - Smoke Seguro de Lectura en `bd_admongas`: HTTP 200, `evaluated: false`, `estado_jornada: UNSCHEDULED`, `journey_status: UNSCHEDULED_DAY`. Cero mutaciones en BD persistente (0 jornadas programadas, 0 pareos, 0 calculos).
  - Smoke de Autenticación Negativa en `bd_admongas`: 401 sin JWT, 403 fuera de alcance, 401 Device Auth.
- **Invariantes:** Architecture Freeze SHA-256 intacto. Cero modificaciones de esquema, datos persistentes o ACL en producción/desarrollo. Cero staging, commits o push.

## 2026-10-13 — Scheduling Auth Final Regression and Write Route Reconciliation
- **Activity:** `CHECADOR_V2_API_SCHEDULING_AUTH_FINAL_REGRESSION_AND_WRITE_ROUTE_RECONCILIATION`
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT
- **Defecto Corregido:** Las cinco rutas WRITE de Shift Scheduling (`handleCreateTemplate`, `handleCreateTemplateVersion`, `handleCreateAssignment`, `handleCloseAssignment`, `handleMaterializeScheduledShifts`) mantenían referencias al método inexistente `Portal3JwtAuthHelper::authorizeUser()`. Se introdujo el helper canónico `$this->authorizeScheduleWrite()` requiriendo JWT Portal3 válido + permiso `biometricos.editar = 1`, preservando la verificación de alcance de estación (`authorized_stations`) en asignaciones y materializaciones.
- **Base de Datos de Prueba:** Restaurada la capacidad de la base disposable `bd_admongas_v2_test_20261002`, aplicando migraciones pendientes y aislando ejecuciones con `SET FOREIGN_KEY_CHECKS = 0/1`.
- **Regresiones Ejecutadas:** 100% PASS en todas las suites deferred: Shift Scheduling Schema (20/20), Shift Scheduling Runtime (30/30), Workplace Timezone Runtime (25/25), Timezone Read Endpoint (23/23), SEC-L03 Authorization (34/34), Snapshot Auth Compliance (16/16), Level 2 Read Auth (20/20), Punch Read API (11/11), Temporary Access (62/62), Effective Schedule Read Auth (20/20), Schedule Write Auth Contract (24/24), Portal3 Security Suite (237/237), Portal3 P04C (30/30), Portal3 P04B (30/30), Portal3 P04A (32/32), Portal3 P03 (30/30), Checador Access Foundation (22/22).
- **Smoke Real Development:** `GET /api/v2/workers/1/effective-schedule?id_estacion=1&fecha_local=2026-10-12` -> HTTP 200, `estado_jornada = UNSCHEDULED`.
- **Compuertas:** `API_SCHEDULING_FINAL_GATE = CLOSED_VERIFIED_DEVELOPMENT`, `JOURNEY_GATE = OPEN`.
- **Invariantes:** Sin cambios a Architecture Freeze ni contratos. Cero modificaciones a esquema, datos de negocio o ACL en producción o desarrollo persistente. Cero commits, staging o push.

## 2026-10-13 — Effective Schedule Read Authorization Runtime Remediation
- **Activity:** `CHECADOR_V2_API_EFFECTIVE_SCHEDULE_AUTHORIZATION_RUNTIME_REMEDIATION`
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT
- **Defecto corregido:** Los cuatro endpoints GET de Shift Scheduling llamaban al método inexistente `Portal3JwtAuthHelper::authorizeUser()`. Ahora autentican el JWT Portal3 y reutilizan `authorizeBiometricos(..., 'leer')`, incluyendo el alcance fresco de workplace en `op_rh_localidades.id`.
- **Alcance preservado:** Las rutas POST, la resolución de horarios, `DAY_OFF`/`UNSCHEDULED`, offsets nocturnos, segmentos múltiples y la ausencia de fallback de estación no cambiaron.
- **Evidencia:** ESA01–ESA20 (20/20 PASS), lint PHP PASS, smoke DEVELOPMENT Portal3→API: timezone HTTP 200 y effective schedule HTTP 200 (`UNSCHEDULED`) para worker 1/workplace 1/2026-10-12; sin HTTP 500. Respuestas sin JWT/JWT inválido: 401.
- **Invariantes:** Sin cambios Portal3/Lector, sin cambios de esquema/ACL/datos, sin producción, sin commit ni push. Suites que requieren una base temporal fueron omitidas/bloqueadas por la compuerta de entorno para evitar mutaciones.

## 2026-10-13 — API P1 Snapshot Parity and Level 2 Auth Remediation
- **Activity:** `CHECADOR_V2_API_P1_SNAPSHOT_PARITY_AND_LEVEL2_AUTH_REMEDIATION`
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT
- **Defecto 1 Corregido (P1 Parity):** Eliminado `OR ocurrido_at_utc IS NULL` en el predicado SQL de `SnapshotReadV2Service.php` (línea 196). Las filas sin timestamp ocurrido quedan excluidas de los snapshots acotados, restaurando paridad exacta 1:1 con `PunchEventReadV2Controller.php`. Verificado con suite SP01–SP10 (14/14 PASS) y prueba en vivo en `bd_admongas` (0 raw events = 0 snapshot events).
- **Defecto 2 Corregido (Level 2 Auth):** `Level2ServerController::handleGetWindowStatus()` actualizado para delegar autorización a `Portal3JwtAuthHelper::authorizeBiometricos()` (`biometricos.leer`) en lugar de `authorizeTemporaryAccess()`. Normalizadas las cadenas de fecha UTC en `Level2ServerService::evaluarVentanaNivel2()` a formato DATETIME compatible con MariaDB/MySQL. Verificado con suite L2R01–L2R20 (20/20 PASS).
- **Archivos Modificados:**
  - `modelo/v2/SnapshotReadV2Service.php`: Eliminado `OR ocurrido_at_utc IS NULL`.
  - `modelo/v2/Level2ServerController.php`: Delegación a `authorizeBiometricos()`.
  - `modelo/v2/Level2ServerService.php`: Normalización de datetime UTC de entrada en `evaluarVentanaNivel2()`.
- **Archivos Creados:**
  - `tests/v2_snapshot_parity_tests.php`: Suite de paridad SP01–SP10 (14/14 PASS).
  - `tests/v2_level2_read_authorization_tests.php`: Suite de autorización Level 2 L2R01–L2R20 (20/20 PASS).
- **Pruebas y Verificación:**
  - Snapshot Parity (SP01–SP10): 14 PASS / 0 FAIL.
  - Level 2 Read Authorization (L2R01–L2R20): 20 PASS / 0 FAIL.
  - Level 2 Server API: 16 PASS / 0 FAIL.
  - Level 2 Ingestion: 9 PASS / 0 FAIL.
  - Snapshot Read Regression: 21 PASS / 0 FAIL.
  - Snapshot Auth Compliance (SRA01–SRA16): 16 PASS / 0 FAIL.
  - Punch Read API: 11 PASS / 0 FAIL.
  - SEC-L03 Authorization: 34 PASS / 0 FAIL.
  - Temporary Access: 62 PASS / 0 FAIL.
  - Workplace Timezone Read Endpoint: 23 PASS / 0 FAIL.
  - Workplace Timezone Authority: 25 PASS / 0 FAIL.
  - Portal3 Focused P04: 32 PASS / 0 FAIL.
  - Portal3 Focused P03: 30 PASS / 0 FAIL.
  - Portal3 Checador Access: 22 PASS / 0 FAIL.
  - Portal3 Full Security Suite: 237 PASS / 0 FAIL.
  - API Controlled Dev Smoke (bd_admongas, user 999, workplace 1): 5/5 PASS (Timezone 200, Raw Read 200, Snapshot Create 201, Snapshot Page 200, Level 2 Window Status 200).
- **Invariantes:** Architecture Freeze SHA-256 intacto. Contratos congelados intactos. Cero modificaciones a esquemas de BD ni ACL persistente. Cero cambios en producción. Sin staging, commit ni push.

## 2026-10-13 — API Post-Oct 12 Stabilization and Level 2 Read Continuation
- **Activity:** `CHECADOR_V2_API_POST_OCT12_STABILIZATION_AND_LEVEL2_READ_CONTINUATION`
- **Estado:** STABILIZATION_GATES_PARITY_STOPPED (paridad 0 vs 4 clasificada como P1_SNAPSHOT_MEMBERSHIP_INCONSISTENCY; progresión de código funcional detenida)
- **Worktree Provenance:** Reconciliados 19 archivos modificados y 28 no rastreados con 0 cambios desconocidos.
- **Paridad Raw vs Snapshot:** Verificada en `bd_admongas` para usuario 999, estación 1, ventana local 2026-10-12: raw punch = 0, snapshot = 4 (`ev-l2-001`, `ev-l2-002`, `ev-l2-003`, `ev-l2-004`). Causa raíz demostrada: discrepancia de predicado en `SnapshotReadV2Service.php` (`OR ocurrido_at_utc IS NULL`), que incluye eventos históricos con fecha nula independientemente del filtro temporal, mientras `PunchEventReadV2Controller.php` filtra estrictamente por rango UTC.
- **Auditoría de Endpoint Level 2:** Endpoint `GET /api/v2/level2/window-status` tiene contrato congelado (`docs/v2/LEVEL2_LECTOR_IMPLEMENTATION_HANDOFF_V1.0.md`) que explícitamente designa invocador humano Portal3 con User JWT y permiso `biometricos.leer`. La implementación actual en `Level2ServerController.php` delega erróneamente a `authorizeTemporaryAccess` (`acceso-temporal`). Corrección en espera de decisión del propietario.
- **Documentación Reconciliada:** Actualizados los documentos canónicos de estado, ledger, roadmap y estatus con la secuencia Portal3 acordada (P03, P04A/P3-A, P04B/P3-B, P04C/P3-C+D, P04D/P3-E, P05, P06).
- **Pruebas y Regresión:**
  - SRA01–SRA16: 16 PASS / 0 FAIL.
  - Level 1 Snapshot Regression: 21 PASS / 0 FAIL.
  - Level 2 Server API: 16 PASS / 0 FAIL.
  - Level 2 Ingestion: 9 PASS / 0 FAIL.
  - Punch Read: 11 PASS / 0 FAIL.
  - SEC-L03: 34 PASS / 0 FAIL.
  - Temporary Access: 62 PASS / 0 FAIL.
  - Timezone Read Endpoint: 23 PASS / 0 FAIL.
  - Timezone Authority: 25 PASS / 0 FAIL.
  - Shift Scheduling Schema: 20 PASS / 0 FAIL.
  - PHP Lint: PASS en todos los archivos.
- **Invariantes:** Cero modificaciones a Architecture Freeze o Contratos congelados. Cero modificaciones a esquemas de BD o ACL persistente. Cero acciones en producción. Sin staging, commit ni push.

## 2026-10-13 — Level 1 Snapshot Authorization Contract Compliance Remediation
- **Activity:** `CHECADOR_V2_LEVEL1_SNAPSHOT_AUTHORIZATION_CONTRACT_COMPLIANCE_REMEDIATION` (and `CHECADOR_V2_LEVEL1_SNAPSHOT_AUTHORIZATION_REMEDIATION_RESUME_AFTER_INTERRUPTION`)
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT
- **PREVIOUS_RUNTIME_DEFECT:** Snapshot Level 1 authorization (`POST /api/v2/punch-events/read-snapshots` and `GET /api/v2/punch-events/read-snapshots/{token}/events`) incorrectly depended on `acceso-temporal` via delegation to `Portal3JwtAuthHelper::authorizeTemporaryAccess()`.
- **CORRECTED_RUNTIME:** Snapshot Level 1 authorization uses `biometricos.leer` and canonical workplace scope (`op_rh_localidades.id`) per frozen contract `docs/v2/PUNCH_EVENTS_SNAPSHOT_READ_API_CONTRACT_V2.4_LEVEL1.md` via `Portal3JwtAuthHelper::authorizeBiometricos()`.
- **Archivos Modificados:**
  - `modelo/v2/Portal3JwtAuthHelper.php`: Añadido método helper `authorizeBiometricos()` con validación estricta de módulo funcional `biometricos`, precedencia directa de usuario sobre puesto, y resolución de alcance de estación. `authorizeTemporaryAccess()` preservado intacto.
  - `modelo/v2/SnapshotReadV2Service.php`: `autorizarAccesoBiometricos()` actualizado para delegar a `Portal3JwtAuthHelper::authorizeBiometricos()`.
  - `tests/v2_snapshot_read_api_tests.php`: Adaptado fixture para incluir configuración de módulo `biometricos`.
- **Archivos Creados:**
  - `tests/v2_snapshot_authorization_compliance_tests.php`: Suite completa SRA01–SRA16 (16/16 PASS).
- **Pruebas y Verificación:**
  - SRA01–SRA16: 16 PASS / 0 FAIL.
  - Snapshot Regression: 21 PASS / 0 FAIL.
  - SEC-L03 Regression: 34 PASS / 0 FAIL.
  - SEC-A Regression: 12 PASS / 0 FAIL.
  - SEC-D1 Regression: 20 PASS / 0 FAIL.
  - SEC-F01 Regression: 12 PASS / 0 FAIL.
  - Punch Read Regression: 11 PASS / 0 FAIL.
  - Temporary Access Regression: 62 PASS / 0 FAIL.
  - API Development Smoke: PASS (POST 201 Created READY, GET 200 OK 4 items).
  - Portal3 P04 Real Smoke: PASS (Timezone 200, Snapshot Create 201, Snapshot Page 200, 4 items).
  - Portal3 P04 Focused Suite: 32 PASS / 0 FAIL.
  - Portal3 P03 Regression: 30 PASS / 0 FAIL.
  - Portal3 Checador Access Regression: 22 PASS / 0 FAIL.
  - Portal3 Security Regression: 237 PASS / 0 FAIL.
- **Invariantes:** Cero modificaciones a Architecture Freeze o Contratos congelados. Cero modificaciones a datos de ACL ni esquemas de BD. Cero acciones en producción. Sin staging, commit ni push.

## 2026-10-12 — Workplace Timezone Business Mapping Development Apply
- **Activity:** `CHECADOR_V2_WORKPLACE_TIMEZONE_BUSINESS_MAPPING_DEVELOPMENT_APPLY`
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT (authorized development database configuration and full regression verification; production untouched)
- **Base de Datos:** `bd_admongas` en host local `fedora` (MariaDB 11.8.8-MariaDB).
- **Mapeos Aplicados:** 8 registros atómicos insertados en `op_rh_v2_localidades_zonas_horarias` para `op_rh_localidades.id` (1, 2, 3, 4, 5, 6, 7, 14) con `timezone_iana = 'America/Mexico_City'`, `valido_desde = '2026-10-12'`, `valido_hasta = NULL`.
- **Verificación de Endpoint:** `GET /api/v2/workplaces/{id}/timezone?date=2026-10-12` verificado para los 8 centros de trabajo (200 OK con ventana UTC `[2026-10-12T06:00:00Z, 2026-10-13T06:00:00Z)`). Verificación de fecha previa pre-efectiva `2026-10-11` falla cerrado con `404 WORKPLACE_TIMEZONE_UNRESOLVED`. Scope y autorización de JWT independientes intactos.
- **Pruebas:** Suites TZR01–TZR20 (23/23 PASS), SEC-L03 (34/34 PASS), SEC-A (12/12 PASS), SEC-B (15/15 PASS), SEC-D1 (20/20 PASS), SEC-F01 (12/12 PASS) y Punch Read API (11/11 PASS) al 100%.
- **Invariantes:** Cero backfill histórico (fechas anteriores a 2026-10-12 permanecen no resueltas). Cero fallbacks globales a nivel de sistema. Cero cambios en producción. Sin staging, commit ni push.

## 2026-10-12 — Workplace Timezone Read Endpoint Implementation
- **Activity:** `CHECADOR_V2_WORKPLACE_TIMEZONE_READ_ENDPOINT_IMPLEMENTATION`
- **Estado:** CLOSED_VERIFIED (implementation and verified isolated runtime tests; production untouched)
- **Archivos:**
  - Creado: `modelo/v2/WorkplaceTimezoneReadV2Controller.php`
  - Creado: `tests/v2_workplace_timezone_read_endpoint_tests.php`
  - Modificado: `index.php` (enrutamiento `GET /api/v2/workplaces/{workplace_id}/timezone?date=YYYY-MM-DD` preservando segmentos '0' en rutas)
- **Decisión:** Implementa de forma estricta el contrato congelado `docs/v2/WORKPLACE_TIMEZONE_READ_API_CONTRACT_V1.0.md` (SHA-256: `27ee027aaa8358c3510693475f302bbdbbb526d289d81e3d1a2e132ee3d32f01`). Autenticación vía JWT de Portal3 con validación independiente de permisos funcionales (`biometricos.leer`) y alcance de estación en el espacio `op_rh_localidades.id`. Cálculo de ventana civil local independiente a UTC con protección DST. Falla cerrado con códigos deterministas (`BAD_DATE`, `INVALID_WORKPLACE_ID`, `UNAUTHORIZED`, `FUNCTION_PERMISSION_DENIED`, `WORKPLACE_SCOPE_DENIED`, `WORKPLACE_NOT_FOUND`, `WORKPLACE_TIMEZONE_UNRESOLVED`, `WORKPLACE_TIMEZONE_AMBIGUOUS`, `WORKPLACE_TIMEZONE_INVALID`, `INTERNAL_ERROR`).
- **Pruebas:** TZR01–TZR20 más pruebas de seguridad de entrada (23/23 PASS) en base de datos desechable aislada con ejecución vía php-cgi. Regresiones SEC-L03 (34/34 PASS), SEC-A (12/12 PASS), SEC-B (15/15 PASS), SEC-D1 (20/20 PASS), SEC-F01 (12/12 PASS), Timezone Authority (25/25 PASS) y Punch Read API (11/11 PASS) verificadas al 100%.
- **Invariantes:** Cero mapeos reales de negocio insertados (estado: PENDING_APPROVAL). Cero modificaciones a esquemas de base de datos o datos persistentes. Cero fallbacks globales a America/Mexico_City. Sin staging, commit ni push.

## 2026-10-12 — V2.7 Workplace Timezone Authority Schema and Runtime
- **Activity:** `CHECADOR_V2_7_WORKPLACE_TIMEZONE_SCHEMA_RUNTIME_IMPLEMENTATION_NO_BACKFILL`
- **Estado:** CLOSED LOCAL (implementation and isolated disposable-DB verification; production and persistent DEVELOPMENT untouched)
- **Archivos:** Added the effective-dated `op_rh_v2_localidades_zonas_horarias` additive migration, fail-closed rollback marker, `WorkplaceTimezoneAuthorityService`, canonical `ScheduleResolutionService` lookup, and focused isolated runtime/schema tests.
- **Decisión:** `op_rh_localidades.id` plus `(id_localidad, fecha_jornada_local)` is the scheduling authority. Device timezone remains diagnostic metadata; missing, invalid, or ambiguous authority reuses `WORKPLACE_TIMEZONE_UNRESOLVED`; no business mappings were inserted.
- **Pruebas:** TZ01–TZ15 and schema gates executed only in a uniquely named disposable database, which was removed after the run. Materialized `timezone_local` snapshots remain immutable.
- **Invariantes:** Architecture Freeze and Workplace Timezone Addendum were not modified; no production action, persistent DEVELOPMENT DDL/DML, staging, commit, or push.

## 2026-10-09 — V2.7 Workplace Timezone Authority Governance Decision
- **Activity:** `CHECADOR_V2_7_WORKPLACE_TIMEZONE_AUTHORITY_GOVERNANCE_DECISION`
- **Estado:** COMPLETE (narrow governance clarification and proposed physical model; implementation not authorized)
- **Documentación Creada:** `docs/architecture/CHECADOR_V2_WORKPLACE_TIMEZONE_AUTHORITY_ADDENDUM_V1.0.md`
- **Decisión:** `op_rh_localidades.id` is canonical Workplace identity; effective-dated Workplace IANA timezone is authoritative; device timezone and global defaults cannot replace missing authority; unresolved configuration reuses `WORKPLACE_TIMEZONE_UNRESOLVED`.
- **Invariantes:** No Architecture Freeze edits, runtime/schema/migration/backfill/test changes, database access, production actions, staging, commit, or push. Existing dirty work was preserved.

## 2026-10-09 — V2.7 Workplace Timezone Physical Model Review and Addendum Freeze
- **Activity:** `CHECADOR_V2_7_WORKPLACE_TIMEZONE_PHYSICAL_MODEL_REVIEW_AND_ADDENDUM_FREEZE`
- **Estado:** FROZEN V1.0 (`docs/architecture/CHECADOR_V2_WORKPLACE_TIMEZONE_AUTHORITY_ADDENDUM_V1.0.md`, SHA-256: `e2f8d7d8b09ee9a40764aeef580e3ffd0aa1cee87484a412644fb8ce4b33525a`)
- **Validación:** Read-only DEVELOPMENT identity verified as MariaDB 11.8.8, `bd_admongas`, host `fedora`, port `3306`; `op_rh_localidades` is InnoDB with signed `INT(11) NOT NULL AUTO_INCREMENT` PK `id`. Effective dates align with existing V2 `DATE` conventions.
- **Modelo congelado:** `op_rh_v2_localidades_zonas_horarias`; `UNIQUE(id_localidad,valido_desde)` supports temporal lookup and FK prefix, so no duplicate lookup index is proposed. Non-overlap and the single open-ended interval rule use serialized InnoDB parent-row locking and locking reads.
- **Ajustes documentales:** Exact SQL types, case-sensitive IANA semantics, overlap predicate, concurrency rule, approved mapping input, and future removal of the global `timezone_local` database default are recorded in the addendum.
- **Invariantes:** No database writes, migration, runtime/test change, Architecture Freeze edit, staging, commit, push, or production action. Pre-existing local changes were preserved.

## 2026-10-07 — V2.6-S Legacy & Payroll Parallel Comparison Physical Model Design
- **Activity:** V2_6_S_LEGACY_PAYROLL_PARALLEL_COMPARISON_PHYSICAL_MODEL_DESIGN
- **Estado:** COMPLETE (FROZEN_V1.0 en `docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md`, SHA256: `def2bc34d445c3eb0677a71e9eaefe978cc1c0f2892c8a16516e45df192b6a37`)
- **Documentación Creada:**
  - `docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md`: Especificación del diseño de modelo físico para persistencia aditiva de comparaciones en 4 tablas (`op_rh_v2_comparaciones_encabezado`, `op_rh_v2_comparaciones_legacy_capturas`, `op_rh_v2_comparaciones_legacy_incidencias`, `op_rh_v2_comparaciones_discrepancias`) con capturas de fotos históricas inmutables de registros de asistencia e incidencias legacy.
- **Invariantes Garantizadas:**
  - Cero código PHP, SQL o archivos de migración creados o ejecutados.
  - Cero modificaciones a tablas físicas legacy (`op_rh_personal_asistencia`).
  - Cero campos de montos pagables, salario o aprobación de tiempo extra incluidos.
  - Cero alteración a marcaciones crudas, turnos programados, pareos P1, cálculos P2 o evaluaciones V2.5.

---

## 2026-10-07 — V2.6 Legacy & Payroll Parallel Comparison Adapter Contract Freeze
- **Activity:** V2_6_LEGACY_PAYROLL_PARALLEL_COMPARISON_CONTRACT_DISCOVERY
- **Estado:** COMPLETE (FROZEN_V1.0 en `docs/v2/CHECADOR_V2_LEGACY_PAYROLL_PARALLEL_COMPARISON_ADAPTER_CONTRACT_V1.0.md`, SHA256: `1e9ec3783b29b09116179168cb45695bf56330ac306bf9cdac81aef49bf09917`)
- **Documentación Creada:**
  - `docs/v2/CHECADOR_V2_LEGACY_PAYROLL_PARALLEL_COMPARISON_ADAPTER_CONTRACT_V1.0.md`: Especificación contractual del adaptador de comparación paralela entre evaluaciones administrativas V2.5 (`op_rh_v2_evaluaciones_administrativas`) y registros de asistencia legacy (`op_rh_personal_asistencia`, `op_rh_personal_asistencia_incidencia`) bajo la regla estricta de `comparación paralela sin afectar nómina`.
- **Invariantes Garantizadas:**
  - Cero código de runtime, controladores o rutas de API REST pública expuestas.
  - Cero cambios o migraciones de esquemas de base de datos creados.
  - Cero cálculos monetarios, montos pagables o alteraciones a nómina.
  - Cero mutaciones a tablas de base de datos legacy.
  - Inmutabilidad total de marcaciones crudas, turnos programados, pareos P1, cálculos P2 y evaluaciones V2.5.

---

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
