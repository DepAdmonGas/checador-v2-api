# CHECADOR V2 API — CHANGELOG

## 2026-11-05 — Shift Assignment Snapshot Pagination (development)
- **Activity:** `CHECADOR_V2_M03_FINAL_OPERATIONAL_UNBLOCK_APPROVED_V1`.
- **Approval:** The responsible owner approved the cross-project addendum SHA-256 `50f67f0468f9dfabb38bf7ea3d363f6ad7e618ee6d3a68cc6ce82393ebcc9385`; Portal3 and API copies remain byte-identical. Architecture Freeze and J1/J2 were not changed.
- **API:** Added snapshot-backed continuation to the existing assignment GET only when `pagination_mode=snapshot` is requested. The service binds tokens to the authenticated actor, authorized locality scope, filters, and membership fingerprint; it uses deterministic ordering, a five-minute absolute TTL, bounded InnoDB snapshot/member storage, sequential-page checks, and the approved fail-closed errors. Legacy consumers retain their prior response mode. Two additive migrations create the snapshot tables and add the approved lifecycle state column, with scoped rollback scripts.
- **Portal3:** M03 now reads all assignment pages sequentially, keeps the continuation token server-side, validates snapshot metadata and exact total count, rejects duplicates, and restarts once after snapshot expiry without mixing snapshots. Incomplete/error reads remain PARTIAL/UNAVAILABLE.
- **Validation:** API snapshot contract 10/10, assignment read-scope 25/25, response contract 6/6, scheduling authorization 23/23, replacement/audit 13/13; Portal3 snapshot reader 4/4, historical validity 27/27, schedule capture 38/38, access foundation 57/57, canonical route 7/7, UI quality gate 36/36 after updating two stale assertions to reflect native worker dropdowns and the removal of a duplicate M04 template editor. PHP lint passed. Authenticated local Chrome GETs for Encargado and Dirección returned HTTP 200; MariaDB readback showed 156 READY snapshots, 78 empty and 78 one-page snapshots (468 member records). The live dataset did not exercise a multi-page snapshot; Portal3 multi-page assembly and duplicate rejection were covered with transport mocks.
- **Membership gate:** No baseline import or schedule write was performed. A read-only current-roster query confirmed the exact approved 41 IDs and no history, alongside 47 active Interlomas workers. The official preview requires an authenticated RH session and actor; no suitable session or preview route was available, so the authenticated preview/fingerprint gate was not satisfied. The 41 memberships remain unimported and M03 remains operationally blocked for those workers.
- **Invariants:** No ordinary-worker assignments, membership rows, M04 data, production, Lector, global POST gate, or shared implementation status were changed. The existing six versioned workers remained the only Encargado scheduling options. No commit or push.

## 2026-11-04 — M04 Global Template Station Customization (approval)
- User expressly approved the exact source addendum SHA-256 `96744a64edecbde0a63fa5c55756b44e56d03dabbd00e61f8c89acc443a1846b` as responsible owner for both Portal3 and API V2.
- Development-only implementation is authorized for immutable global-source lineage and unique station copies through the existing shift-template writer. Encargado remains station-scoped; Dirección remains read-only.
- Architecture Freeze V1.0 and J1/J2 remain unchanged. No assignments or global source templates are authorized for mutation.

## 2026-11-03 — M11 Administrative Device Inventory Read (development only)
- **Activity:** `CHECADOR_V2_M11_CROSS_APPROVAL_AND_IMPLEMENTATION_V1`.
- **Approval:** Cross-project owner approval for the identical addendum in Portal3 and API V2; approved source SHA-256 `2308c7f39378699822bab2d583193bdfac0246a97f1c1948ec5771458546a639`. Current reconciled copies: `a2f0ad3980032bd992e9aade2aea5f51c9cf141027e7cde64a3d130506f36eb6`.
- **API:** Added development-only `GET /api/v2/administrative-devices`. It validates delegated Portal3 JWT, dedicated read grant, current station scope, verified `numlista` locality mapping, and returns a bounded read-only projection with explicit completeness. Persisted status and telemetry receipt remain separate; no online/offline inference or device secrets/payload are returned.
- **ACL:** Applied only `checador-v2-dispositivos.leer` position grants for positions 6 and 13 in `bd_admongas`; no DDL or broad permissions. Out-of-scope explicit locality was verified as HTTP 403 with no rows.
- **Readback:** Real API read using `bd_admongas`: profile 33/position 6 returned 2 Interlomas records; profile 19/position 13 returned 3 records in authorized scope, both COMPLETE. Projection regression 11/11 PASS.
- **Excluded:** No device writes, assignment changes, credentials, telemetry, schema, production, global POST gate, Architecture Freeze, J1/J2, Lector, commit, or push.

## 2026-11-02 — M05 Station-Scoped Journey Observation Read Authorization
- **Activity:** `CHECADOR_V2_M05_STATION_JOURNEYS_READ_AUTHORIZED_V1`
- **Contract:** Added the byte-identical local-development companion `docs/v2/CHECADOR_V2_M05_JOURNEY_READ_AUTHORIZATION_ADDENDUM_V1.0.md`. It defines `checador.jornadas.leer` for only the raw observation GET and station timezone GET used by M05. Architecture Freeze, base read contracts, Lector, Journey evaluation, snapshots, and writes remain unchanged.
- **API:** Added `JourneyReadAuthorizationService`; raw PunchEvent reads may fall back to the narrow grant only after JWT validation and only when the existing Biometrics denial is functional-permission-only. The timezone GET also recognizes the narrow grant after existing Biometrics and Shift Scheduling paths. Both enforce the existing active station resolver and requested locality scope. No other route recognizes the permission.
- **Development ACL:** Added an idempotent position-6 grant and exact-source revocation migration. Position 13 receives no new grant and retains its existing Biometrics read path. No user, write, device, snapshot, Journey evaluation, production, or data grant was added.
- **Validation:** API authorization suite 16/16, SEC-L03 JWT regression 34/34, Portal3 M05 transport integration 7/7, GET-side-effect guard 13/13, Portal3 UI Quality Gate 36/36, and read-only Chrome E2E 2/2 across Encargado and Dirección. Foreign station, empty scope, malformed scope, and missing/overridden permission negatives pass in isolated authorization tests. No Journey POST or snapshot request occurred.
- **Invariants:** Global POST gate, schedule-specific gate, P0 GET guard, 89 source punch UUIDs, and QA assignment records remain unchanged. No commit or push.

## 2026-11-02 — Persisted Audit Timestamps for Shift Assignment Creation (M03)
- **Activity:** `CHECADOR_V2_M03_FIRST_REAL_ASSIGNMENT_E2E_V1`
- **API response:** `POST /api/v2/shift-assignments` now returns the actual persisted `creado_at` and `registrado_at_utc` values for new rows and exact-match replay responses. New-row timestamps are read back inside the transaction; missing values fail closed. Existing response fields and HTTP 201 success status are preserved.
- **Portal3:** The assignment handler accepts success only for API HTTP 201 with both timestamp values in the required format, then confirms the record by API readback before displaying success. The `Idempotency-Key` request header is still not consumed by the API; the existing exact-match/row-lock duplicate path is distinct from header idempotency.
- **Verification:** One authorized local QA assignment (worker 1004, Interlomas 1, template 3/version 1, 2026-11-03) was created through authenticated Portal3 and verified by API readback, MariaDB SELECT, and post-reload Chrome. Exact duplicate replay did not add a row; cross-station tampering was rejected before API write. See Activity Ledger Entry 055 for precise record, timestamps, browser evidence, test results, and limitations.
- **Tests:** Portal3 M03 capture regression 24/24 PASS; API assignment read scope 25/25, scheduling authorization 23/23, and source-only response contract 6/6 PASS. The DB-backed API runtime suite was not run because a safe isolated test DB is unavailable. The generic schedule-write authorization static suite remains 18 PASS / 6 FAIL from older assertions expecting broad permissions; it was not altered here. UI Quality Gate: 36 PASS / 0 FAIL / 1 BLOCKED, with separate authenticated Chrome verification completed.
- **Scope / invariants:** Development-only `bd_admongas`; no Journey evaluation, payroll result, template change, membership change, unrelated-module POST, raw-punch change, production, Lector, contract, schema, or Architecture Freeze change. The assignment is QA evidence, not an official labor schedule. Human visual approval remains pending. No commit or push.

## 2026-11-02 — Authorized Legacy Shift Template Bootstrap (M03)
- **Activity:** `CHECADOR_V2_M03_AUTHORIZED_TEMPLATE_BOOTSTRAP_EXECUTION_V1`
- **Authorization:** The owner approved an identical §7 exception in the Portal3/API Shift Scheduling Authorization Addendum for a development-only, CLI-only initial catalog bootstrap. The addendum copies have SHA-256 `09ec77ee0ff76279df48f3efb04579cbd15f9f625230de5bc7c99a7884717f40`. No HTTP authorization, role, user grant, normal template-admin requirement, or Architecture Freeze changed.
- **Source:** Interlomas legacy catalog revalidated at 17 rows: 15 positive-duration entries, 12 unique time pairs, and zero-duration `VACACIONES`/`INCAPACIDAD` excluded. Duplicate legacy intervals were consolidated by deterministic key and their source references/hashes retained.
- **Bootstrap:** Added `scripts/bootstrap_legacy_shift_templates.php`, default preview and explicit `--apply`, restricted to CLI + `APP_ENV=dev` + verified local `bd_admongas`; includes transaction, named lock, deterministic keys, exact-match idempotency, fail-on-conflict/no-overwrite, and provenance manifest. Created 12 V2 templates and 12 work segments (two distinct overnight pairs). No assignments, rest days, memberships, or work-validity dates were created.
- **Readback:** MariaDB `bd_admongas` confirmed 12 templates, 12 segments, zero assignments, 89 raw punch rows and 89 distinct original UUIDs. Two subsequent apply runs created zero records and matched all 12 definitions with no conflicts. `docs/status/CHECADOR_V2_M03_LEGACY_TEMPLATE_BOOTSTRAP_MANIFEST.json` records source references, normalized definitions, IDs, and run outcomes.
- **Portal3:** Weekly schedule catalog now reads existing template versions, displays segment hours/overnight offsets, and offers the catalog to the station manager without template-admin capability. Chrome Playwright passed for Encargado ID 33 and Dirección ID 19, including 46 workers, 12 templates, and responsive widths 1440/1024/768/375. Encargado selected a night template without submitting; Dirección remained read-only. No template or assignment mutation was submitted; the E2E guard allows only the existing module-context selection POST, if emitted.
- **Validation:** Bootstrap helper 14/14; Shift Scheduling authorization 23/23; assignment read-scope 25/25; membership coverage 8/8; Portal M03 regression 21/21; Portal UI Quality Gate 36/36; both authenticated browser profiles 1/1 PASS. PHP/Node syntax checks PASS.
- **Invariants:** Global API POST gate remains off; schedule-only development gate and ACL unchanged; no global template-admin grant, worker assignment, canonical Journey/Nómina/punch modification, production change, Lector change, schema change, commit, or push. Human visual approval remains pending. Individual scheduling still requires its own authorized worker/turn/date selection.

## 2026-11-02 — Station-Scoped Weekly Schedule Authorization and Assignment Lifecycle
- **Activity:** `CHECADOR_V2_M03_STATION_SCHEDULING_AUTHORIZED_IMPLEMENTATION_V1`
- **Authorization:** Explicit user approval for this local-development slice, including a narrow Shift Scheduling permission addendum and development-only permission grants. No approval inferred for production, Lector, API Block B, J2, M07, or general Biometricos access.
- **Contract:** Added `docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_AUTHORIZATION_ADDENDUM_V1.0.md` (SHA-256 `c717482d5402a3d41d7fe9d932f39d0722a67bdd6a26bfa65c018ec0162c2253`), byte-identical to the Portal3 companion. It separates schedule read, schedule edit, and global template administration. Position 6 reads/edits only its own mapped station; position 13 reads its authorized active station set; template catalog writes require the separate template-admin grant.
- **API changes:** Added `ShiftSchedulingAuthorizationService` and `ShiftWorkerMembershipService`; applied specific authorization and canonical locality scoping to Shift Scheduling reads/writes; added controlled assignment replace route; retained Block A fail-closed behavior; permitted explicit REST assignments without a template ID under the approved model. No new pagination contract or J2 runtime.
- **Development schema and grants:** Applied migrations `2026_11_02_000001_create_v2_shift_scheduling_permission_grants.sql` and `2026_11_02_000002_allow_explicit_day_off_assignments.sql` only to verified local `bd_admongas` development. Grants added only for position 6 schedule read/edit and position 13 schedule read. No template-admin grant. Readback after migration: zero templates and zero assignments, unchanged from preflight.
- **Verification:** API authorization 23/23, assignment read scope 25/25, membership interval 8/8; PHP syntax checks PASS. Portal3 authenticated read-only Playwright passed for Encargado ID 33 and Dirección ID 19. No write was attempted because the operational write gate is disabled and no RH-approved schedule data/template is available. No production data or canonical punch records changed.
- **Pending:** Real template and schedule writes/readback require RH-approved schedule details, a legitimately authorized global template administrator, and activation of the existing development write gate through its authorized process. Human visual acceptance remains pending.

## 2026-10-30 — Shift Assignment Read Scope Security Block A
- **Activity:** `CHECADOR_V2_SHIFT_API_SECURITY_BLOCK_A_V1`
- **Autorización:** Implementación autorizada explícitamente por el propietario sólo para el entorno de desarrollo y el Bloque A. Bloques B y C/J2 permanecen fuera de alcance.
- **Corrección:** `GET /api/v2/shift-assignments` conserva JWT Portal3 + `biometricos.leer` y consume el `authorized_stations` fresco del helper. La localidad explícita se rechaza con 403 si no pertenece al alcance; si se omite, el SQL filtra por todas y sólo las localidades autorizadas. El filtro `id_personal` es obligatorio y siempre se combina con el alcance de localidad. `fecha_referencia` ahora exige una fecha civil real `YYYY-MM-DD` y devuelve 400 si es inválida; al omitirse, conserva la consulta de historial limitada al alcance.
- **Compatibilidad:** La respuesta, aliases de consulta, semántica histórica sin fecha y límites existentes `page`/`per_page` se conservan. Sin cursor, revisión, `has_more`, paginación adicional, runtime J2, cambio ACL, API nueva, datos o migración.
- **Archivos de la actividad:** `modelo/v2/shift/ShiftSchedulingV2Controller.php`, `modelo/v2/shift/ShiftAssignmentService.php`, `tests/v2_shift_assignment_read_scope_tests.php`, actualización de llamada en `tests/v2_shift_scheduling_runtime_tests.php`.
- **Pruebas:** 24/24 de alcance con SQLite en memoria; Effective Schedule Authorization 20/20; Schedule Write Authorization 24/24; SEC-L03 JWT/ACL con mocks 34/34; PHP lint y `git diff --check` PASS. Las pruebas Shift Scheduling y mapeo canónico que requieren conexión MariaDB no se ejecutaron: configuración local de base de datos ausente; no se consultaron bases ni se ejecutaron escrituras.
- **Gobierno e invariantes:** Architecture Freeze y contratos congelados sin cambios; los hallazgos permanecen dentro de V2.7 hardening y la autorización de desarrollo del Bloque A. Sin llamadas API, escrituras canónicas, Lector, cambios de Portal3, commit o push. Los cambios preexistentes del worktree se conservaron.

## 2026-10-27 — Local QA Authentication and Real Ingestion Closure
- **Activity:** `CHECADOR_V2_LOCAL_AUTH_AND_REAL_QA_INGESTION`
- **Estado:** API_LOCAL_INGESTION_PASS; Portal3 authenticated reads await Portal3-local secret configuration and authorized user context.
- **Cambios:** `modelo/v2/IngestaPunchV2Controller.php` now returns the stored `recibido_at_utc` on an idempotent retry, matching the ingestion contract. Local-only `.env.qa.local` stores a QA JWT verifier key and distinct mandatory raw-punch HMAC key; `.qa-local-fixtures.json` records synthetic fixture identifiers. Both files are ignored by local Git exclude and mode 0600.
- **Fixtures:** Local `bd_admongas` only: two clearly labeled synthetic workers, one QA device on existing station 1, an active credential, identity mappings, generation, and effective station assignment. Three events were inserted through `POST /api/v2/punch-events`; no raw punch was inserted manually and no schema or permission change was made.
- **Verificación:** Three persisted events returned HTTP 201, mapped to synthetic workers and station 1, each integrity-sealed; identical retry returned HTTP 200 `ALREADY_RECEIVED` and the original receive timestamp; divergent payload returned HTTP 409 `IDEMPOTENCY_CONFLICT`. Raw event count/UUID uniqueness is now 77/77; the 74 pre-existing records retain their prior SHA-256 aggregate `f321572aeffa81a58dfc39a621c316882a2e5820097092f6d7a08c126407e2c8`. PHP lint PASS. Four protected read routes returned 401 without JWT as expected.
- **Pendiente:** Portal3 must set `CHECADOR_API_BASE_URL=http://127.0.0.1:8081/` and configure `CHECADOR_API_JWT_SECRET` to match API `JWT_SECRET` through the private local secret channel. A legitimate Portal3 access token and an active user with `biometricos.leer` scoped to workplace 1 are required to verify data reads; no token was fabricated. No Portal3 repository, production, Plesk, contracts, commits, or push were changed.

## 2026-10-27 — Preproduction Level 2 Coverage Release Package
- **Activity:** `CHECADOR_V2_LEVEL2_COVERAGE_PREPROD_SURGICAL_RELEASE_V1`
- **Estado:** PACKAGE_PREPARED_AND_ISOLATED_VERIFIED; not deployed.
- **Base exacta:** Reconstruida desde `deployment/preprod/release_telemetry_20261027/Level2ServerService.php`; SHA-256 de entrada verificado `76283fcfdbb784e2683274c91b93af12a2c2522e8f66e19ac678daa83c02304b`.
- **Parche/resultado:** `deployment/preprod/2026-10-27_level2_coverage.patch` cambia exclusivamente `evaluarVentanaNivel2()`. Archivo final SHA-256 `2762093a56acec2cad4a1830df51ffe2aa14c51bac47b55338b8eff0dd3457c5`; ZIP `deployment/preprod/release_coverage_20261027.zip` SHA-256 `4f59505298ad72d14bf22cf43957ff8aa030d04a774bfff38991f96c26005577`.
- **Comportamiento:** Evalúa las cinco señales booleanas congeladas V1.1 desde `payload_json` persistido; `capture_worker_alive` no sustituye `capture_thread_alive`. Evidencia ausente/malformada, falta de telemetría o ventanas inválidas/futuras no producen `VERIFIED`; señales falsas, gaps de secuencia y brechas de heartbeat se clasifican `INCOMPLETE`; reloj desconocido/no sincronizado o fuera de tolerancia no produce atribución `VERIFIED`.
- **Dependencias:** Columnas y políticas existentes de telemetría/Level 2 del esquema documentado; ninguna columna, servicio o endpoint nuevo; ningún cambio de controlador, grants o migración.
- **Pruebas:** Contra el archivo reconstruido y parcheado en MariaDB desechable: cobertura 18/18 PASS, persistencia append-only 12/12 PASS, PHP lint y `git diff --check` PASS. La suite API Level 2 existente también pasó 16/16 en el runner local. Contenedor desechable eliminado. La telemetría append-only queda intacta en el archivo final.
- **Paquete:** ZIP contiene sólo `Level2ServerService.php`, `MANIFEST.sha256` y `README-INSTALL-ROLLBACK.md`; se verificó su contenido. La ruta de instalación, preflight del controlador y reversión están documentados. La presencia del controlador en Plesk no se comprobó.
- **Invariantes:** Sin Plesk, bases reales, cambios de esquema, contratos, Lector, permisos, despliegue, commit o push.

## 2026-10-27 — Level 2 Canonical Health Coverage Regression
- **Activity:** `CHECADOR_V2_LEVEL2_HEALTH_COVERAGE_IMPLEMENTATION_V1`
- **Estado:** FOCUSED_LOCAL_VERIFICATION_PASS; this does not certify a full daily coverage journey.
- **Implementación revisada:** `Level2ServerService::evaluarVentanaNivel2()` consumes persisted `payload_json`, requires boolean `application_running`, `sdk_initialized`, `sensor_connected`, `capture_thread_alive`, and `queue_writable`, and classifies known false evidence as `INCOMPLETE`. Missing or malformed evidence remains `UNKNOWN`. The V1.0 example field `capture_worker_alive` is not accepted as a substitute for frozen V1.1 `capture_thread_alive`; frozen documents were not edited.
- **Validación:** The active coverage policy governs heartbeat gap, telemetry continuity, and client/server time tolerance; explicit non-SYNCED/unknown clock quality cannot yield `VERIFIED`. Invalid, reversed, and open future windows fail closed. No health state is inferred from absent telemetry.
- **Archivos de esta actividad:** `tests/v2_level2_telemetry_coverage_runtime_tests.php` extended with individual false-signal cases for all five signals, sensor+thread failure, alias mismatch, non-boolean values, UNSYNCED clock, and malformed/reversed windows; this changelog updated. Runtime evaluator code was already present in the preserved local worktree and required no change in this activity.
- **Pruebas:** Isolated MariaDB — Level 2 server API 16/16 PASS; telemetry coverage 17/17 PASS; append-only regressions 12/12 PASS; PHP lint and `git diff --check` PASS. Disposable DB/container removed.
- **Invariantes:** No real DB, Plesk, Lector, or contract changes; no deployment, commit, or push. The earlier full-day coverage limitation remains separate from these focused evaluator tests.

## 2026-10-27 — Preproduction Telemetry Release Package
- **Activity:** `CHECADOR_V2_API_PREPROD_TELEMETRY_RELEASE_PACKAGE_V1`
- **Estado:** PACKAGE_READY_FOR_MANUAL_TRANSFER_AFTER_APPROVAL; not deployed.
- **Contenido:** `deployment/preprod/release_telemetry_20261027.zip` contains only the final `Level2ServerService.php`, `MANIFEST.sha256`, and `README-INSTALL-ROLLBACK.md`.
- **Hashes:** ZIP SHA-256 `191f5e32fc7ef118ddfbbd5acbe6d752642629173ae60b9bc4951aeb0698ef00`; packaged PHP SHA-256 `76283fcfdbb784e2683274c91b93af12a2c2522e8f66e19ac678daa83c02304b`; Plesk baseline SHA-256 `97e02f789b0cbde0f7bfb316b1e23a27ad1257468c544ab1fb31348f06f72a56`.
- **Validación:** Patch reaplicado a copia limpia del baseline; salida coincide con SHA esperado. PHP lint del archivo empaquetado PASS. Prueba focalizada contra el archivo final: 12/12 PASS; regresiones Level 2 API 16/16 y cobertura 11/11 PASS; diff check PASS. ZIP inspeccionado: tres entradas previstas, sin credenciales, configuración privada ni bases de datos.
- **Invariantes:** Archivo Fedora no sobrescrito; ningún acceso a Plesk o bases reales; sin cambios a las cinco señales, cobertura, contratos o esquema; sin despliegue, commit o push.

## 2026-10-27 — Plesk Level 2 Telemetry Surgical Release Comparison
- **Activity:** `CHECADOR_V2_API_PREPROD_SURGICAL_RELEASE_COMPARISON_V1`
- **Estado:** PATCH_REVIEW_PACKAGE_READY; not deployed.
- **Baseline:** User-provided Plesk `Level2ServerService.php` SHA-256 `97e02f789b0cbde0f7bfb316b1e23a27ad1257468c544ab1fb31348f06f72a56` verified. Fedora corrected service SHA-256 `2f5834564052a1c1ff76f88fecc3af6cc48d8ee61ab404c0a5bc4bdf18bfb3bb`; surgical Plesk-based output SHA-256 `76283fcfdbb784e2683274c91b93af12a2c2522e8f66e19ac678daa83c02304b`.
- **Patch:** `deployment/preprod/2026-10-27_level2_telemetry_append_only.patch` changes only `registrarTelemetria()` and adds payload normalization. `tests/run_v2_level2_preprod_patch_tests.sh` verifies source/output hashes and applies/tests a temporary copy without replacing the Fedora service.
- **Alcance:** Removes the `ON DUPLICATE KEY UPDATE` requirement; preserves append-only semantics, canonical sequence uniqueness, exact payload/timestamp collision checks, idempotent retry, concurrency handling, and original server receipt. No schema, grant, controller, or frozen contract changes.
- **Diferencias excluidas:** Fedora historical station resolver and `evaluarVentanaNivel2()` health/coverage corrections were not included. The V1.1 frozen contract uses `capture_thread_alive`, while the V1.0 approved wire handoff example says `capture_worker_alive`; recorded as `API_CONTRACT_CONFLICT` for separate governance resolution.
- **Pruebas:** Exact Plesk-based patched service: append-only focused 12/12 PASS (including account without UPDATE); existing Level 2 API 16/16 PASS; telemetry coverage 11/11 PASS; loopback controller response mapping 201/200/409 PASS; PHP lint and `git diff --check` PASS. Disposable rootless MariaDB removed.
- **Liberación:** One service file is the proposed deployment artifact, but verify the live target hash and unique sequence index read-only before release. Back up and record the live target hash; rollback by restoring that exact copy. No Plesk access, real DB access, deployment, commit, or push occurred.

## 2026-10-27 — Level 2 Telemetry Append-Only HTTP 500 Fix
- **Activity:** `CHECADOR_V2_LEVEL2_TELEMETRY_APPEND_ONLY_HTTP500_FIX_V1`
- **Estado:** `LEVEL2_TELEMETRY_APPEND_ONLY_FIX_VERIFIED` en runtime local aislado; sin despliegue de preproducción.
- **Causa confirmada:** El log de Plesk proporcionado para esta actividad identifica `mysqli_sql_exception` por privilegio `UPDATE` denegado durante la preparación del upsert en `op_rh_v2_dispositivo_telemetria`. La implementación local ya estaba cambiada a `INSERT` puro, eliminando la dependencia de `UPDATE`; se verificó contra el DDL vigente, cuya clave única es `(id_dispositivo, device_generation, telemetry_sequence)`.
- **Corrección y comportamiento:** Un conflicto de clave consulta la muestra existente y compara timestamp original, batería, estado de red, estado del reloj y payload con tipos estrictos, ignorando el nuevo `server_received_at_utc`. Objetos JSON equivalentes ignoran el orden de claves; diferencias de tipo o contenido producen `409 SEQUENCE_COLLISION`. La clave única sigue siendo la protección final ante concurrencia; sólo el error SQL 1062 puede entrar en la resolución idempotente.
- **Archivos:** Ajustado `modelo/v2/Level2ServerService.php`; creados `tests/v2_level2_telemetry_append_only_tests.php` y `tests/run_v2_level2_append_only_tests.sh`; actualizado este changelog. No se cambió el controlador ni el contrato.
- **Base de datos:** MariaDB 10.11 rootless en contenedor desechable, con migraciones existentes aplicadas sólo al esquema temporal y cuentas sintéticas para verificar permisos. No se accedió ni escribió `bd_admongas`, `bd_admongas_v2_preprod` ni Plesk.
- **Pruebas:** Level 2 Server API 16/16 PASS; cobertura de telemetría 11/11 PASS; regresiones append-only T1–T10 10/10 PASS; HTTP loopback 201/200/409 PASS; PHP lint y `git diff --check` PASS. Se verificaron inserción única, payload/timestamp divergentes, continuidad, concurrencia igual/divergente, recepción original inmutable y operación con SELECT/INSERT sin UPDATE.
- **Despliegue:** No ejecutado. La causa coincide con la evidencia Plesk suministrada; el origen desplegado aún debe compararse y el cambio requiere aprobación antes de modificar Plesk.
- **Invariantes:** Contratos y migraciones sin cambios; producción y preproducción sin modificaciones; sin commit ni push.

## 2026-10-27 — Level 2 Telemetry Health and Continuity Fail-Closed
- **Activity:** `CHECADOR_V2_LEVEL2_TELEMETRY_COVERAGE_POSITIVE_E2E_V1`
- **Estado:** `LEVEL2_COVERAGE_BLOCKED` for a full daily HTTP journey; telemetry evaluator corrections verified in isolated development.
- **Corrección:** Window evaluation now requires all five frozen capture-health booleans, honors `require_telemetry_continuity`, requires a known `SYNCED` client clock plus configured drift tolerance, and returns `UNKNOWN` for invalid or not-yet-closed windows. Known unhealthy signals, time gaps, and telemetry sequence gaps remain `INCOMPLETE`.
- **Ingesta:** Telemetry sequences must be positive JSON integers. Exact retries return `200 ALREADY_RECEIVED` without rewriting the original server receipt; conflicting reuse returns `409 SEQUENCE_COLLISION` and preserves the first sample.
- **Valores vigentes:** Default development policy row is 900 seconds maximum heartbeat gap and 300 seconds maximum client/server timestamp difference. These are database policy values, not numeric constants mandated by the frozen contract. The contract requires evaluation against the active versioned policy.
- **Pruebas:** Level 2 telemetry coverage 11/11 PASS; Level 2 server API 16/16 PASS; authenticated Level 2 read and station-scope regression 20/20 PASS; Journey Calculation runtime 14/14 PASS after fixtures generated evidence via the existing isolated server-receipt test seam. Real loopback HTTP accepted three current-time healthy samples and its persisted short window evaluated `VERIFIED`; a separate HTTP sequence gap evaluated `INCOMPLETE`; sequence zero returned HTTP 400. The full daily P1/P2-to-Portal3 route was not run because valid 24-hour coverage was unavailable in this execution.
- **Lector:** Source verification remains blocked because `/home/silvinol/Proyectos/Lector` contains no executable source repository; no readiness claim was made.
- **Invariantes:** Disposable MariaDB 11.8.8 container removed; no access or writes to `bd_admongas`; no production/Portal3/Lector changes; frozen contracts and schemas unchanged; no commits or push.

## 2026-10-26 — Canonical Workplace to Physical Station Resolution for P1/P2
- **Activity:** `CHECADOR_V2_DAILY_ATTENDANCE_PAIRING_INTEGRATION_V1`
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT (isolated API/Portal3 E2E)
- **Corrección:** Journey requests and schedules retain canonical `op_rh_localidades.id`; Level 2 evidence and raw punches resolve through the unique `numlista` mapping to the active physical `tb_estaciones.id`. Missing or ambiguous mappings fail closed. The resolved physical station is included in the P1 execution fingerprint so an earlier result from the mismatched ID space is not reused.
- **Compatibilidad:** Request/response shapes, pairing allocation rules, P1/P2 contracts, schemas, and Architecture Freeze were not changed.
- **Archivos:** Added `modelo/v2/WorkplacePhysicalStationResolver.php`; updated `modelo/v2/journey/JourneyEvaluationV2Controller.php`, `modelo/v2/journey/JourneyPairingService.php`, and P1/mapping regression tests.
- **Base de datos:** API/Portal3 E2E ran only against disposable MariaDB 11.8.8 loopback container databases. No access or writes to `bd_admongas`; no production or migration changes.
- **Pruebas:** Workplace mapping 3/3 PASS; P1 pairing runtime 16/16 PASS; authenticated API/Portal3 HTTP E2E 10/10 PASS. API returned two `EXACT_PAIR` segments and four ENTRY/EXIT roles; Level 2 source status remained `UNKNOWN`, so Portal3 displayed all four positions as `—` while preserving all four HMAC-valid source events. The API also returned `pairing.completeness_l2 = VERIFIED` without a stored Level 2 evaluation row; Portal3 requires the top-level source status to be `VERIFIED` as well and therefore fails closed. Encargado cross-station requests returned HTTP 403 in Portal3 and API.
- **Invariantes:** `CONTRACTS_MODIFIED: NO`; `SCHEMA_MODIFIED: NO`; `PRODUCTION_MODIFIED: NO`; `COMMITS_CREATED: NO`.

## 2026-10-26 — HTTP 409 Idempotency Contract Compatibility (V1)
- **Activity:** `CHECADOR_V2_HTTP_409_CONTRACT_COMPATIBILITY_FIX_V1`
- **Estado:** CLOSED_VERIFIED_DEVELOPMENT
- **Objetivo:** Alinear exclusivamente la respuesta HTTP 409 de conflicto de idempotencia de `POST /api/v2/punch-events` con el cuerpo documentado en `docs/v2/PUNCH_EVENTS_INGESTION_API.md`.
- **Corrección:** La rama `IDEMPOTENCY_CONFLICT` devuelve ahora HTTP 409 y exactamente los campos `status_code`, `result`, `event_uuid` y `error`. Las respuestas HTTP 201 y 200 mantienen su comportamiento existente. No se modificó el contrato.
- **Compatibilidad del consumidor:** El directorio local Lector no contiene código fuente ejecutable ni es un repositorio Git; se revisaron la documentación de ingesta y la guía Level 2 disponible. Se conserva el código HTTP 409 y no se cambió el tratamiento de conflictos permanentes; validación del parser runtime del Lector pendiente de disponer de su código.
- **Archivos creados/modificados en esta actividad:** `modelo/v2/IngestaPunchV2Controller.php`; `tests/v2_real_ingestion_http_integration.php`; `docs/changelog/CHECADOR_V2_API_CHANGELOG.md`.
- **Base de datos:** MariaDB 10.11 en contenedor Podman rootless desechable; fixtures sintéticos únicamente. Scripts de migración existentes aplicados sólo al esquema temporal; `bd_admongas` sin escrituras ni migraciones.
- **Pruebas:** Integración HTTP y DB 25/25 PASS_REAL; reintento HTTP 200, conflicto HTTP 409 con JSON contractual exacto, evento y sello originales sin cambios; resolución histórica, HMAC, Level 2 e inmutabilidad conservados. Pruebas focalizadas de estación 14/14 PASS; regresión PIN de servicio/BD 62/62 PASS; PHP lint y `git diff --check` PASS.
- **Pendientes:** Prueba HTTP end-to-end del PIN temporal fuera del alcance de esta actividad; revisión del consumidor runtime Lector cuando su código esté disponible.
- **Invariantes:** `CONTRACTS_MODIFIED: NO`; `DATABASE_WRITES_BD_ADMONGAS: NO`; `PRODUCTION_MODIFIED: NO`; `COMMITS_CREATED: NO`.

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

## 2026-11-02 — M03 Authorization Regression Reconciliation
- **Activity:** `CHECADOR_V2_M03_OPERATIONAL_COMPLETION_V1`
- **Change:** Reworked the static write/read authorization contract test to assert each route's current addendum-authorized capability: template administration, station-scoped schedule edits, and legacy materialization permission. It now verifies the authorized addendum text, JWT checks, station scoping, and specific read gates.
- **Former failures:** All six failures were obsolete assertions predating the approved permission addendum; no authorization-code defect was found. New focused static suite: 31/31 PASS. Scheduling authorization 23/23, read-scope 25/25, membership coverage 8/8, and Portal M03 capture regression 24/24 PASS.
- **Idempotency boundary:** The frozen Shift Scheduling API Contract V1.0 specifies optional `X-Idempotency-Key` only for template creation. Assignment creation has exact-payload idempotency/overlap protection but no key-based replay or conflicting-key contract. Portal3's `Idempotency-Key` header remains unconsumed for assignments. No undocumented key store, migration, or contract change was introduced; assignment-level key semantics require an approved extension defining key scope, replay, conflict, and persistence.
- **Close/replace and workforce:** Real DB close/replace tests remain unrun because the existing runtime suite drops/recreates Shift Scheduling and Journey tables and no isolated test DB is configured. Only previously verified QA membership records (1004–1008) are available; no operative staff schedule was invented or persisted. QA assignment ID 1 and the 89 original punch UUIDs remain unchanged; no database writes occurred in this activity.
- **Scope:** Regression test and documentation only. The previous authenticated browser evidence remains valid because no Portal runtime/UI code changed; M03 is not declared operationally complete, and visual approval remains pending. No commit or push.

## 2026-11-04 — M04 Global Template Station Customization

- **Approval:** Development-only cross-project implementation approved for source addendum SHA-256 `96744a64edecbde0a63fa5c55756b44e56d03dabbd00e61f8c89acc443a1846b`; reconciled synchronized copies SHA-256 `651a8b963f39d60663999e12c962fee0ec1ac4a6325b27ac55b7902d923a854e`.
- **Schema:** Added `op_rh_v2_shift_template_station_lineage` via additive migration `2026_11_04_000001_create_v2_shift_template_station_lineage.sql`; unique station/source and station/local keys prevent duplicate derivations.
- **API:** Extended the existing authorized shift-template writer for explicit global-source customization, transactional immutable lineage, API-generated station-local identity and duplicate readback. Local version detail exposes provenance within authorized station scope.
- **Validation:** One local QA copy and one subsequent local version edit persisted in `bd_admongas`; the source GLOBAL row/version and six existing assignments remained unchanged. Duplicate retry returned the same local identity. Focused API tests 11/11 and authenticated Chrome E2E passed for Encargado and Dirección; see Activity Ledger Entry 061.
- **Protected:** No production, Architecture Freeze/J1/J2, Legacy writer, assignment, PunchEvent, Journey or global POST gate changes; no commit or push.

## 2026-11-04 — M03 Shift Audit UTC Timestamp Remediation
- **Root cause:** Shift assignment rows explicitly used `UTC_TIMESTAMP()`, while all four canonical inserts into `op_rh_v2_turnos_audit_log` omitted `recorded_at_utc` and inherited `DEFAULT CURRENT_TIMESTAMP`. The development MariaDB session timezone was `SYSTEM` (UTC−06:00); both columns are `DATETIME`, so MariaDB stored the local wall time without conversion.
- **Fix:** Assignment CREATE and template CUSTOMIZE_GLOBAL, CREATE, and VERSION audit inserts now supply `recorded_at_utc = UTC_TIMESTAMP()` explicitly. No timezone compensation, global session change, schema change, or historical rewrite was made.
- **Scope:** The separate device access-attempt audit receives an explicit server-created UTC timestamp; this defect was confined to the shift audit table's canonical writers.
- **Validation:** Added `tests/v2_shift_audit_utc_timestamp_tests.php`; source assertions cover all four audit writers and the assignment writer. Read-only local MariaDB checks confirmed `bd_admongas`, `DATETIME` column types, and invariant UTC time with session zones `+00:00`, `+05:30`, and `-06:00`. Existing worker 1006 readback found seven assignments and seven CREATE audits, each with the historical 21,600-second offset; records were left unchanged.
- **Protected:** No assignments, schedules, attendance results, permissions, production data, contracts, or Architecture Freeze files changed; no commit or push.

## 2026-11-05 — M03 Permanent Replacement Actor and Audit Remediation
- **Activity:** `CHECADOR_V2_M03_PERMANENT_SCHEDULE_CHANGE_AUDIT_REMEDIATION_V1`
- **Root cause:** The approved `/api/v2/shift-assignments/{id}/replace` path authenticated and scoped the caller but did not pass the JWT-derived actor into `ShiftAssignmentService::replaceAssignment()`. The successor insert therefore left `creado_por_usuario_id` NULL and emitted no replacement audit event.
- **Fix:** The controller now forwards the validated actor ID. The canonical replacement transaction records `actualizado_por_usuario_id` on the closed source, persists `creado_por_usuario_id` on the successor, and appends `CLOSE` and `REPLACE` audit events with source/successor linkage and explicit `UTC_TIMESTAMP()` values. Audit preparation, insert, and readback failures throw and roll back the complete close/replacement transaction.
- **Portal3:** `ChecadorDailyReadService::replaceShiftAssignment()` now requires actor and audit readback metadata before reporting replacement success; no client-provided actor is accepted.
- **Schema:** No new migration was required; the approved M03/M04 additive schema already contains actor columns and `op_rh_v2_turnos_audit_log`. Existing historical QA assignment 30 was not rewritten.
- **Validation:** API static contract tests `13/13 PASS`, PHP syntax checks PASS. Real QA Chrome/MariaDB replacement completed for worker 1006, source assignment 22, and successor assignment 31 on Tuesday 2026-12-01: Portal3 returned HTTP 303, the successor persisted `creado_por_usuario_id=33`, the source persisted `actualizado_por_usuario_id=33`, and audit rows 33 (`CLOSE`) and 34 (`REPLACE`) were read back with actor 33 and the same UTC instant `2026-11-05 00:46:23`. A second active Tuesday assignment was not created; the exact-match idempotent service branch and replay-without-insert checks are covered by the focused test. Dirección remained read-only in the authenticated read path. The existing Portal3 M03 regression remained `33/33 PASS`.
- **Protected:** No production, M04, Legacy schedules, PunchEvents, permissions, Architecture Freeze or J2 changes; no commit or push.

## 2026-11-05 — M04 Autolavado Local Template Scope
- **Activity:** `CHECADOR_V2_M04_AUTOLAVADO_ACTIVACION_FUNCIONAL`
- **Authorization:** The responsible owner's task explicitly authorized the Autolavado-only adaptation if required, with existing effective M04 permissions and no broadening to other departments. This entry records the task decision; it does not change Architecture Freeze V1.0, J1/J2, the shared implementation phase, or production authorization.
- **Canonical mapping:** Autolavado is `op_rh_localidades.id=9`, `numlista=10`, a department. Palo Solo is canonical locality 2, `numlista=1`. `tb_estaciones.id=9` is Ventura Puente and is not used as the Autolavado identity.
- **API implementation:** Template catalog list and detail now authorize the explicitly supported Autolavado scope and return only `STATION` rows for canonical locality 9. Template create/version uses the existing `STATION_TEMPLATE_ADMIN` permission after independently validating the existing Portal3 department scope; the scope is restricted to Autolavado and does not alter M03 assignment authorization. Dirección remains read-only. Global-template customization is denied in the department scope. No new endpoint or writer was introduced.
- **Schema/data:** No tables, columns, migrations, grants, or data changes. Existing `op_rh_v2_turnos_plantillas` `scope/id_estacion` representation is reused; `id_estacion` carries the canonical locality key already used by the model.
- **Validation:** PHP syntax PASS; API scheduling authorization 23/23, global template customization 11/11, and Autolavado scope contract regression 12/12 PASS. Runtime suite was not run because its bootstrap requires a separate disposable database, which this activity does not authorize. Authenticated Chrome Dirección confirmed the Autolavado selector state, an empty isolated catalog, and no write action. A valid Encargado browser session was unavailable; real template create/version and MariaDB write readback remain NOT VERIFIED. No POST was executed.
- **Portal3 integration:** Server-side scope/read integration and focused regressions are recorded in `docs/status/CHECADOR_V2_PORTAL3_STATUS.md` under the same activity.
- **Protected:** Palo Solo rows, M03 assignments, unrelated departments, ACL grants, production, Architecture Freeze/J1/J2 and M04 visual structure; no commit or push.

## 2026-11-05 — Autolavado M03/M04 access follow-up
- **Activity:** `CHECADOR_V2_AUTOLAVADO_M03_M04_ACCESS_FIX`.
- **Scope:** Explicit Autolavado locality 9 scheduling read/edit scope is returned only for the supported Encargado profile bound to Palo Solo and Dirección, only when the existing effective M03 permission and department ACL explicitly include locality 9. No wildcard, other department, global permission, or M04 grant is inferred.
- **Assignment integrity:** The canonical assignment create and replacement writers now require any non-rest template for locality 9 to be an active `STATION` template owned by locality 9. Other workstation assignment template validation is unchanged. This prevents cross-catalog use; it does not create assignments.
- **Operational guard:** M03 remains unavailable in Portal3 until locality 9 has an authoritative V2 timezone mapping. The local `op_rh_v2_localidades_zonas_horarias` read returned zero rows for locality 9. No timezone was inferred or inserted, and membership/effective-date validation remains fail-closed.
- **Validation:** scheduling authorization 23/23, template station-customization contract 11/11, Autolavado scope/unit/static checks 19/19; PHP syntax and `git diff --check` PASS. No runtime DB mutation test was run. Chrome Dirección confirmed M04 Autolavado catalog read with an empty local catalog and no write control; Encargado UI creation was not verified because no authenticated Encargado session was available. No POST or database write was performed.
- **Preserved:** Palo Solo scheduling path, global and station template data, memberships, assignments, grants, schemas, migrations, Freeze/J1/J2, and production.

### 2026-11-06 — PIN temporal operation permissions

- Recorded the approved development-only M07 addendum SHA-256 `87916221327e3f36195288213e5d941792d5a5e70bd90ef450fa9b87f34f7cc5` without changing its substantive content.
- Applied the approved action-to-permission mapping to API V2: queue read=`leer`, authorization=`crear`, cancellation=`eliminar`, rejection=`editar`; retained the narrowly approved legacy `editar` compatibility.
- Authorization regression: 40/40 PASS; direct Encargado rejection attempt returned 403. No live transition was made because no legitimate request was pending.
- No schema, migration, production, Architecture Freeze, J1/J2, or device authentication change.

## 2026-11-06 — M07 canonical locality / physical station scope fix
- Kept Portal3's canonical locality authorization result distinct from the physical station IDs stored on temporary-access requests.
- M07 list and transition handlers now pass only the uniquely mapped, currently authorized physical station IDs to the existing request service; canonical locality IDs remain available to Portal3 and are not used to filter physical-station request rows.
- Added regression coverage where canonical locality `801` maps to physical station `10`, asserting the different ID spaces and server-side request filtering.
- Validation: `tests/sec_l03_authorization_regression.php` 42/42 PASS; PHP syntax and `git diff --check` PASS. No API contract/schema change or station/ACL expansion.
- Shared-reader Palo Solo/Autolavado attribution remains unsupported: current request rows have no effective worker-locality field and the device identity mapping resolves only `id_personal`. Autolavado remains out of approved scope pending cross-project contractual approval.

## 2026-11-06 — Read-only administrative adjustment inventory (approved development slice)

- Approval: Implemented the read-only collection/detail contract whose approved Portal3 document SHA-256 is d7697b14416f60a157cf552acd4fdc70c06c0e86d98942eab3a11b4bff4c98ae.
- API: Added GET-only collection and detail projections, bounded filters/paging, deterministic ordering, persisted lineage/evaluation links, referential integrity checks, and delegated JWT authorization.
- ACL: Added an adjustment-specific evaluator that uses only direct or position grants for checador-v2-ajustes.leer; same-module direct deny wins, other-module grants do not affect inheritance. Current API module/grant/scope configuration is read at request time; no grant or configuration was inserted.
- Schema/data: No table, column, migration, data, legacy write, evaluation behavior, Architecture Freeze, J1/J2, or production change.
- Portal3 integration: Same-origin authenticated read routes and DataTables/detail UI call API through the existing delegated JWT read service; browser never calls API directly. The existing administrative-evaluation GET and operations were not changed.
- Validation: Focused authorization, syntax, and read-boundary tests recorded in the task report. No write or grant was performed. Real adjustment-row and authenticated browser verification remain dependent on existing data/grants and QA session availability.

## 2026-11-09 — Comodines canonical API stage 1 (development, disabled)

- Recorded express cross-project approval of addendum SHA-256 `0cd553e2dbb3eac43a9a221832bcdb5c51aa0a0d7f36b3af1f7e27eff10ef618`; synchronized source copies remained identical and were not edited.
- Added a dormant API V2 coverage-version writer, request/list/detail/history and authorize/reject/cancel handlers, dedicated JWT/grant/scope checks, and the opt-in `WORKER_EFFECTIVE` branch reusing `ScheduleResolutionService`. Existing ordinary effective-schedule behavior remains the default. Coverage-created assignments link to the exact coverage revision and are transactional with audit/readback.
- Added a fail-closed schema/feature gate. It requires exact opt-in, canonical dependencies, required columns/indexes/FK, InnoDB storage and audit vocabulary. The current development DB lacks the approved coverage schema, so the new API operations remain unavailable.
- Validation: isolated coverage policy 45/45; route/gate static 12/12; shift-scheduling authorization 23/23; changed PHP syntax and whitespace checks PASS. Existing broader scheduling write contract checks show 28/31 PASS with SWA03/SWA04/SWA17 unrelated to changed coverage files; no claim that suite is fully green.
- No migration, ACL grant, environment activation, operational/database write, Portal3 UI integration, production, Architecture Freeze, J1/J2, Legacy writer, M02–M07, Journey or PunchEvent change. The process environment flag is unset; feature remains disabled. No commit or push.


## 2026-11-09 — Comodines Stage 2 schema and regression review (migration not applied)

- Verified the exact approved addendum SHA in both projects; the contract document was not edited.
- Performed read-only information_schema verification against local `bd_admongas` / MariaDB 11.8.8. The up/preflight/postflight and rollback proposals are in `docs/v2/CHECADOR_V2_COMODINES_COVERAGE_DDL_PROPOSAL_2026-11-09.sql` and `docs/v2/CHECADOR_V2_COMODINES_COVERAGE_DDL_ROLLBACK_PROPOSAL_2026-11-09.sql`. The preflight SELECT-only section was run and all checks returned READY. DDL was not run; its MariaDB implicit commits and partial-failure recovery are explicitly documented.
- Fixed the dormant schema gate's comparison of MariaDB unsigned integer metadata (`int(10) unsigned`, `bigint(20) unsigned`). Added deterministic type-matching coverage; 8/8 pass. No API writer semantics or feature activation changed.
- Classified SWA03/SWA04/SWA17 as stale static expectations versus the approved template authorization helpers; full suite remains 28/31. The unrelated tests and template handlers were not changed.
- Focused coverage policy 45/45, route/gate 12/12, schema gate types 8/8, scheduling authorization 23/23, M04 customization 11/11, Autolavado scope 19/19, assignment read scope 25/25 and worker membership 8/8 passed. No runtime writes, migration, grants, activation, production or commit/push.

## 2026-11-09 — Shift-template local-write authorization boundary

- **Root cause:** the global checador.plantillas.administrar path forwarded client scope=STATION and id_estacion to local template creation/versioning without station-template authorization or canonical locality validation.
- **Fix:** local writes now use the existing checador.turnos.estacion.administrar authorizer and validate the requested canonical locality against the server-resolved scope before returning write context. Encargado retains only its authorized local scope; Dirección remains read-only. Global catalog writes retain checador.plantillas.administrar and now return scope=GLOBAL with station_id=NULL, ignoring any client locality value.
- **Input handling:** invalid scope and non-integer/non-positive id_estacion are rejected before any writer is invoked.
- **Validation:** SWA 31/31; scheduling authorization 23/23; M04 station customization 11/11; Autolavado scope 19/19; assignment read scope 25/25; worker-membership coverage 8/8; Comodines policy 45/45, route/gate 12/12, schema gate 8/8; PHP syntax and git diff --check PASS. No live HTTP/DB write test was run.
- **Existing unrelated regression:** v2_effective_schedule_authorization_runtime_tests.php remains 17/20; ESA01, ESA05, and ESA20 still assert pre-addendum biometricos.* authorization expectations and were not changed.
- **Protected:** No contracts, Architecture Freeze, J1/J2, grants, database, migration, Comodines activation, Portal3, production, or other functional modules changed. No commit or push.


## 2026-11-09 — Comodines Stage 3 development schema migration

- **Authorization:** Explicit owner authorization applied only to local development database `bd_admongas`; approved Workplace Coverage addendum SHA-256 `0cd553e2dbb3eac43a9a221832bcdb5c51aa0a0d7f36b3af1f7e27eff10ef618`.
- **Backup:** Full development dump verified at `/tmp/checador-v2-comodines-backup-20261109_185535/bd_admongas.sql`, SHA-256 `510bd98acd701c7160334a80d166e9853c96740a3ef81019a40ed4ae5d8d2c5a`; directory mode 0700 and file mode 0600.
- **Schema:** Applied the three approved additive DDL statements from `docs/v2/CHECADOR_V2_COMODINES_COVERAGE_DDL_PROPOSAL_2026-11-09.sql`: create `op_rh_v2_worker_workplace_coverage_versions`; add nullable `id_cobertura_version`, index and FK to `op_rh_v2_turnos_asignaciones`; extend `op_rh_v2_turnos_audit_log.entity_type` with `WORKPLACE_COVERAGE`. Postflight verified InnoDB, column types, constraints, indexes, and zero coverage rows/linked assignments/coverage audit events.
- **Activation/data:** `CHECADOR_V2_WORKPLACE_COVERAGE_ENABLED` remains unset/false. No grants, operational coverage rows, assignments, audit rows, memberships, templates, schedules, journeys, or incidence rows were written. Existing tables’ auto-increment values remained unchanged; no DML was executed.
- **Validation:** Development target and MariaDB 11.8.8 confirmed; every migration preflight check returned READY; no active InnoDB transaction or other active query on `bd_admongas` was present before execution. Coverage policy 45/45, coverage route 12/12, schema gate types 8/8, SWA 31/31, scheduling authorization 23/23, assignment replace audit 13/13, worker membership 8/8, and Portal3 Comodines focused regressions 50/50 passed. `v2_effective_schedule_authorization_runtime_tests.php` remains 17/20 with the previously known ESA01, ESA05 and ESA20 expectation failures. Destructive database schema/runtime suites were not run against the operational development database.
- **Protected:** No API/Portal3 functional code, permissions, feature flags, operational data, Architecture Freeze, J1/J2, production, preproduction, or Legacy tables changed; no commit or push.


## 2026-11-09 — Comodines Dirección-only V1.1 (development)

- **Approval:** The owner approved the Direction-only actor policy, same-user separate audited approval, and development-only implementation. V1.0 remains immutable. The final V1.1 companion is `docs/v2/CHECADOR_V2_COMODINES_DIRECCION_ONLY_ADDENDUM_DRAFT_V1.1.md`; its SHA-256 is recorded in the corresponding Portal3/API copies.
- **API:** Coverage policy now limits all four operations to authenticated position 13 with the operation-specific grant; position 6 is denied even if an old position grant remains. Request authorization checks both canonical home and coverage Workplaces against the current scope. Approval permits the same Direction user to authorize only through a separate `AUTHORIZE` transition; request creation remains `REQUESTED` and records its own audit event.
- **Portal3:** Coverage permission constants and a Direction-only grant helper back the Checador card, sidebar link, page route and internal handlers. Request, approval and cancellation actions are independently gated. Legacy Rol Comodines endpoints deny non-Direction actors.
- **Development ACL:** In `bd_admongas`, the two position-6 grants were explicitly deactivated; the position-13 request grant was added; the existing position-13 read/approve/cancel grants were preserved. No direct USER grant or unrelated ACL row was changed.
- **Data/gates:** No coverage request, approval, cancellation, assignment or other operational record was created. Feature gates may be re-enabled only in local development after security regressions pass; production and preproduction remain unchanged.
- **Validation:** Focused policy, route, Portal3 authorization/view/API-proxy tests, PHP lint and whitespace checks are recorded in the implementation report. Authenticated Chrome E2E is not claimed unless a valid QA session is available.

## 2026-11-11 — C01/C02 HTTP authentication status mapping

- **Root cause:** C01 and C02 treated the JWT helper's semantic `status` string as the numeric HTTP status. C02 also made that same inversion for authorization failures, so `http_response_code()` received `0` and the response retained HTTP 200.
- **Fix:** C01 and C02 now pass the helper's numeric `code` to the response helper and preserve `status` as the stable JSON error code. The response envelope and successful HTTP 200 path are unchanged.
- **Validation:** Focused status-mapping regression 13/13 PASS; C01 journey-read authorization regression 16/16 PASS; PHP lint PASS. Broader routing/404 regressions were blocked by missing development `DB_*` configuration (their API probes returned HTTP 500). A local HTTP server could not bind in this environment; real endpoint HTTP and authenticated C01/C02 readbacks remain unverified.
- **Authentication procedure:** Portal3 development login is `POST /login/acceso`; successful login returns the access JWT in the response's `data.token` and sets the access cookie. Use an active development test user and required 2FA, then send the token as `Authorization: Bearer <token>` to API V2. Do not log the credential or token. This checkout has no `DB_*` or `JWT_SECRET` process variables, so no authenticated request was made.
- **Protected:** No Portal3/Lector source, database, migration, production, gate, contract, commit, or push changed.
