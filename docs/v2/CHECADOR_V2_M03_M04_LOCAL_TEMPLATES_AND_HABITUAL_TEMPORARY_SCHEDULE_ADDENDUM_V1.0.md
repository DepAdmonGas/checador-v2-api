# CHECADOR V2 — M03/M04 Local Templates and Habitual/Temporary Schedule Addendum V1.0

**Status:** APPROVED FOR LOCAL DEVELOPMENT IMPLEMENTATION  
**Applies to:** Portal3 and Checador V2 API, local development only  
**Supplements:** Shift Scheduling Service & API Contract V1.0 and Shift Scheduling Authorization Addendum V1.0  
**Does not revise:** Architecture Freeze V1.0, J2 V1.0, Lector contracts, production configuration, or existing raw punch data  
**Implementation authorized:** YES, development only, under the approval recorded in Section 10

## 1. Purpose and recorded functional decisions

This proposal translates the functional decisions confirmed for `CHECADOR_V2_M03_M04_CONTRACT_REMEDIATION_V1` into a development contract:

1. An Encargado may create and administer turn definitions scoped exclusively to the Encargado's currently authorized station.
2. Existing global V2 templates remain intact and available under their existing read/select permissions.
3. A worker may have one explicit habitual schedule per weekday and effective interval.
4. M03 may record date-bounded temporary exceptions without changing the habitual schedule.
5. A valid temporary exception takes precedence on its covered local dates; after its end, resolution falls back to the habitual schedule effective for that date.
6. A day off is explicit and is not inferred from a missing row.
7. Template and assignment changes preserve effective validity, actor provenance, recorded history, and immutable prior versions.

This addendum proposes additive development behavior only. It is not an approval to change the Architecture Freeze or J2 text, and it is not effective until the approval gate in Section 10 is completed.

## 2. Template scope and versioning

The existing global catalog remains the same logical catalog and its existing rows and IDs are preserved. New station templates are a separate scope in the same canonical API catalog:

- `GLOBAL`: existing templates; read/select behavior remains as currently authorized. Encargado cannot create, edit, activate, deactivate, or version these rows.
- `STATION`: a template belongs to exactly one canonical workplace `id_estacion`. API V2 resolves the caller's active physical station to canonical locality using the approved mapping and verifies the requested locality is within the current server-side station scope.
- An Encargado can create and version only `STATION` templates for the Encargado's own active authorized station. Client-provided station IDs never establish scope.
- Dirección de Operaciones retains read/supervision access only under current authorized-locality scope; this addendum grants it no template write capability.
- Existing global keys and versions remain unchanged. Local key/version uniqueness is evaluated within the canonical station scope. A station-local template cannot mutate or shadow an existing global row; list/read responses identify scope unambiguously.
- Template versions are immutable. Editing creates the next version in the same scope. Existing assignments continue to reference their exact template version; a new version does not silently change current or historical assignments.
- Existing `WORK`/`BREAK` segment representation, ordinals, local times, and day offsets are reused. A break is explicit only when represented by the canonical segment data; crossing midnight continues to use the contract's day offsets.

The additive physical migration must support the scope discriminator and canonical station reference without rewriting the existing global rows. Exact DDL, indexes, and rollback must be supplied with implementation and tested against the current MariaDB version before application.

## 3. Assignment kinds and effective validity

The canonical assignment gains an explicit `tipo_asignacion` with exactly `HABITUAL` or `TEMPORAL`:

- `HABITUAL` is a worker/workplace/weekday assignment with `valido_desde` inclusive and `valido_hasta` exclusive or `NULL` for open-ended validity, following the existing API contract.
- `TEMPORAL` is a worker/workplace/weekday override with the same half-open stored interval. The Portal3 form may accept an inclusive final local date; API V2 normalizes that date to the following date as the exclusive `valido_hasta` boundary. No timezone or UTC conversion is applied to a civil-date boundary.
- A weekly exception is represented by explicit temporary weekday rows for the affected days. Each write is idempotent and read back. The UI must report partial completion as partial; it must never claim the entire exception was saved if any requested weekday failed.
- Both kinds require complete, unique, canonical membership coverage for the entire effective interval. Unverified or ambiguous membership rejects the write. Current `id_estacion` is not a historical fallback.
- `id_plantilla = NULL` means an explicit `DAY_OFF` for that assignment kind and interval. An absent assignment row means no schedule evidence, not a rest day.

## 4. Resolution priority and conflict handling

For a worker, canonical workplace, and local calendar date:

1. Resolve the weekday using the canonical workplace-local date.
2. If exactly one active `TEMPORAL` assignment covers that date and weekday, use it, including an explicit temporary day off.
3. If no temporary assignment covers the date, use exactly one active `HABITUAL` assignment covering that date and weekday.
4. If neither exists, return the existing neutral `UNSCHEDULED` result. Never infer a station default, rest day, absence, or work schedule.
5. If more than one applicable assignment exists at the same priority, return `UNKNOWN`/`NEEDS_REVIEW` with a conflict indicator. Do not select a winner by ID, creation time, or template version, and do not silently fall back from a conflicting temporary row to habitual.

Overlapping `HABITUAL` intervals for the same worker/workplace/weekday are rejected. Overlapping `TEMPORARY` intervals for the same worker/workplace/weekday are rejected except for an exact idempotent retry. Overlap between one habitual and one temporary interval is allowed because the priority rule is explicit. A temporary interval never closes, edits, or replaces the habitual record. On the first local date not covered by the temporary interval, the effective resolver automatically returns to the single habitual assignment then in force.

## 5. Version history, actor, and recorded-time provenance

- The API derives the actor only from the validated Portal3 JWT subject resolved to an active user. Actor identity, position, or scope supplied in the request body is ignored/rejected.
- Each successful create, version, close, replacement, or correction records actor, operation, canonical workplace, effective interval, assignment kind/template version, and `recorded_at_utc`.
- Prior template versions and assignment states remain reconstructable as recorded. Corrections append a revision/audit fact; they do not erase the earlier recorded state. Existing `registrado_at_utc` is retained but is not by itself a substitute for the J2 bitemporal as-recorded history requirement.
- The API implementation must use additive persistence for any missing actor/revision facts, with a read path that can distinguish effective time from recorded time. No legacy writer may overwrite `op_rh_personal_horario` or serve as V2 persistence.

## 6. Permissions and server-side isolation

Existing permission meanings remain separate:

- `checador.programacion.leer`: current authorized schedule read/supervision.
- `checador.programacion.editar`: assignment create/close/replace for verified workers inside the caller's server-resolved station scope; position 6 retains the existing development grant and position 13 remains read-only.
- Proposed `checador.turnos.estacion.administrar`: create/version station-local templates for position 6, limited to its active home station. This is not `checador.plantillas.administrar`, does not permit global-template administration, and grants no Biométricos, materialization, or other module capability.
- No new write capability is granted to Dirección by this proposal.

Portal3 and API V2 must independently check the authenticated permission, active position/direct-user deny precedence, canonical station mapping, requested station, template scope, worker scope, membership coverage, and date validity on every mutation. Missing, ambiguous, stale, or unavailable authorization fails closed. The API is the final security boundary.

## 7. API compatibility scope

Implementation should reuse the existing shift-template and shift-assignment endpoint family and existing JWT delegation from Portal3. Contract amendments to those request/response shapes are limited to the scope and type fields above and the required immutable as-recorded audit behavior. No browser-to-API access, technical Lector credential, new catalog, Legacy writer, production deployment, Journey evaluation, scheduled-shift materialization, or global POST gate is authorized.

Any endpoint or storage detail required to realize this contract must be documented in the implementation record before code is changed. Existing global templates, assignments, IDs, and 89 original PunchEvent UUIDs must remain unchanged.

## 8. Required acceptance tests after approval

API isolated and MariaDB development tests must cover:

- Global template preservation; local template create/version; local key uniqueness; immutable prior versions; duplicate/idempotent and conflicting retries.
- Encargado allowed at home station, denied at foreign station, with a forged client station, with an explicit deny, or with missing/ambiguous station mapping.
- Dirección read-only and locality-scoped; no write grant.
- Habitual assignment per weekday; explicit habitual day off; exact membership coverage; rejection for incomplete, ambiguous, or foreign worker membership.
- Temporary override precedence, explicit temporary day off, start boundary, last included date, first date after end returning to habitual, different weekdays, overlapping temporary conflict, habitual conflict, and exact idempotent retry.
- As-recorded reconstruction before and after a correction, with actor derived from JWT.
- Existing 12 global templates and all existing assignments remain intact; no writes to Legacy schedules or original PunchEvents.

After approval, perform a controlled authenticated Chrome E2E in local `bd_admongas` with QA workers 1004–1008: local template create/version, habitual schedule, temporary exception, explicit rest, resolver fallback, API/database readback, F5, foreign-station rejection, and Dirección read-only. No operational worker schedule is to be created without an explicit RH decision.

## 9. Development and deployment boundary

Approval, if granted, authorizes only additive implementation and controlled QA in local development after verifying the Portal3 and API connections target `bd_admongas`. It does not authorize production, deployment, destructive migration, changes to global gates, original PunchEvents, payroll, Lector, or unrelated modules. Rollback may remove only newly added schema objects and QA rows after verifying references; existing global templates and assignment history are preserved.

## 10. Approval gate

**Functional decisions:** confirmed by the responsible user for the scope listed in Section 1.  
**Contract approval:** APPROVED 2026-11-03 by the responsible owner for Portal3 and API V2.  
**Portal3 approval:** APPROVED; same cross-project owner instruction.  
**API V2 approval:** APPROVED; same cross-project owner instruction.  
**Development implementation:** AUTHORIZED for the exact scope below; production remains excluded.

The existing Shift Scheduling Authorization Addendum V1.0 remains in force except where this approved addendum supplements it. In particular, no current position or user is granted global `checador.plantillas.administrar` by this addendum.

Approval evidence: task `CHECADOR_V2_M03_M04_CONTRACT_APPROVAL_AND_IMPLEMENTATION_V1`, dated 2026-11-03, approved the source addendum SHA-256 `54e879148144f1a7bca178ee05d7426cdde3998383bc5eebba50b5247083c11a`. The approval authorizes additive implementation and migrations only in local `bd_admongas`, position 6's station-scoped `checador.turnos.estacion.administrar` grant, local templates, `HABITUAL`/`TEMPORAL` resolution and audit semantics. Direction remains read/supervision only. The Architecture Freeze V1.0 and J2 V1.0 are unchanged.

### Exact approval text required

> I approve, for both Portal3 and Checador V2 API in local development only, the exact addendum `CHECADOR_V2_M03_M04_LOCAL_TEMPLATES_AND_HABITUAL_TEMPORARY_SCHEDULE_ADDENDUM_V1.0.md`, SHA-256 recorded after the synchronized copies are prepared. I authorize its additive API and Portal3 implementation, development-only migrations and ACL grant for `checador.turnos.estacion.administrar` to position 6 strictly within the Encargado's server-resolved home station, and the `HABITUAL`/`TEMPORAL` resolution and audit semantics stated in the addendum. Direction remains read-only. I do not authorize production changes, Legacy writes, global template administration by Encargado, unrelated ACL/gate changes, Journey evaluation, materialization, or modification of original PunchEvents. The Architecture Freeze V1.0 and J2 V1.0 remain unchanged; this addendum supplements them only within its stated M03/M04 scope.

