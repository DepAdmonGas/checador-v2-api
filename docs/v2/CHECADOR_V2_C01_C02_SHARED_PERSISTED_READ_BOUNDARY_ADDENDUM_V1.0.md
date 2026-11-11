# Checador V2 — C01/C02 Shared Persisted Read Boundary Addendum V1.0

**Status:** `USER_AUTHORIZED_DEVELOPMENT_SPECIFICATION`
**Implementation scope:** read-only development implementation in API V2 and Portal3 only
**User authorization:** current task `CHECADOR V2 — CIERRE TÉCNICO E IMPLEMENTACIÓN C01/C02`
**Architecture Freeze amendment:** `NO`
**Companion proposals:** `CHECADOR_V2_PERSISTED_JOURNEY_READ_CONTRACT_PROPOSAL_V1.0.md` and `CHECADOR_V2_COMPLETE_INCIDENCE_READ_CONTRACT_PROPOSAL_V1.0.md`

## 1. Purpose and governance

This addendum records the user's specific authorization for a bounded, API-owned, read-only development boundary for existing persisted Journey evaluations (C01) and a complete incidence workspace projection (C02). It supplements, but does not edit or supersede, Architecture Freeze V1.0, J1/J2, M05, P3G/P3G-A, P3H, M06, or P3G-B. Architecture Freeze V1.0 remains unchanged and frozen. The approval recorded here is the user's authorization for the development scope below; it does not claim a separate API, Lector, or business-owner approval.

The current Portal3 persisted-read services perform direct SQL reads. Those services are observed implementation, not an approved source boundary. Portal3 must not use them as an alternative canonical V2 store. Existing P3G-A reads and P3H evaluation are reusable only within their frozen semantics; no equivalence is presumed beyond what their response contracts provide.

## 2. Common requirements proposed for C01 and C02

1. **Transport and identity:** backend-to-backend API request with the existing Portal3 user-delegated JWT family. API revalidates the dedicated read permission, active actor, canonical workplace scope, worker identity/membership, and all filters for every request. Browser calls API neither directly nor with API/device credentials.
2. **Read-only guarantee:** GET performs no evaluation, snapshot creation, materialization, migration, audit mutation, or other persistent write. Journey evaluation remains a separate explicit operation; a missing persisted result must never trigger `POST /api/v2/journeys/evaluate` from the read.
3. **Canonical identity:** public worker/workplace identifiers use the canonical V2 ID spaces. Portal3 station IDs, `numlista`, and canonical locality IDs are never equated implicitly. A worker filter narrows the server-derived workplace scope and cannot grant it.
4. **Completeness:** successful empty, partial, unavailable, and not-evaluated are distinct. The response echoes the normalized query, provides deterministic ordering and page metadata, and indicates whether the requested result set and its child projections are complete. Errors use stable safe codes; no SQL exception text or secret is returned.
5. **Time:** civil dates remain local work dates. API returns UTC instants and the authoritative IANA workplace timezone/effective date needed to display them. Missing or ambiguous timezone/schedule is explicit and cannot be silently replaced by a device timezone or a global fallback.
6. **Provenance:** API returns public stable identifiers and source/revision/version references sufficient to explain the projection. Raw PunchEvents remain immutable and separately identifiable from calculated Journey results or incidence decisions.

## 3. C01 persisted Journey read — operation proposal

**Required logical operation:** list/read already persisted Journey calculation revisions for an authorized workplace and local-date interval, with optional canonical worker and calculation-state filters; provide the schedule, evaluation revision/metrics/provenance, segments, associated raw event public UUIDs, and canonical applied-incidence references needed by the current Portal3 workspace. It must not calculate or persist a result.

**Existing endpoint reuse:** none currently provides this operation. `POST /api/v2/journeys/evaluate` is not a read and is expressly excluded as a substitute. M05 raw `GET /api/v2/punch-events`, effective-schedule GET, and workplace-timezone GET remain useful independent sources but do not expose a persisted calculation revision, evaluated segments, or its exact provenance/link set.

**Route:** `GET /api/v2/journeys/persisted`, distinct from `POST /api/v2/journeys/evaluate`.

**Query:** required `workplace_id`, `from`, `to`; optional `worker_id`, `status`, `page` (default 1), `per_page` (default 50, range 1–200). Dates are valid inclusive civil `YYYY-MM-DD`; the inclusive interval may contain at most 62 calendar dates. `to < from`, invalid filters, or a longer interval returns safe `400 INVALID_QUERY`. Unknown status is rejected. Workplace is canonical `op_rh_localidades.id`; worker is canonical `op_rh_personal.id`. API enforces the M05 `checador.jornadas.leer` grant and fresh server-resolved station scope on every page. Worker filter only narrows that scope.

**Response:** HTTP 200 envelope `{status:"success", data:{query:{workplace_id,from,to,worker_id,status}, pagination:{page,per_page,total_records,total_pages,has_more,complete,consistency:"PER_PAGE_READ"}, journeys:[...]}}`. Each Journey uses public `evaluation_ref` (persisted `fingerprint_ejecucion`), canonical worker/workplace identity and labels, local work date, evaluation state/revision/algorithm/fingerprint/time, schedule type and source/version, UTC scheduled bounds, authoritative IANA timezone, metrics, segments, raw event UUID references/trace fields, and applied incidence UUID/category/status/application state. Internal database primary keys are not public identifiers. A successful no-match query is an empty collection, not a new evaluation. Missing child evidence or inconsistent page totals is `PARTIAL`, not empty.

Ordering is work date descending, worker display name ascending, then public `evaluation_ref` ascending. `projection_status` is `EMPTY` when no persisted result matches, `PARTIAL` when an included result lacks an authoritative projection field, and `COMPLETE` when every returned result is complete. `EMPTY` means no persisted evaluation matched; it does not assert that no schedule exists or that a result was evaluated. `NOT_EVALUATED` is represented only in a schedule/evaluation context that proves a scheduled Journey has no evaluation; this persisted-results collection does not enumerate unevaluated schedules. `UNAVAILABLE` is an HTTP error (`SOURCE_UNAVAILABLE`), not an empty collection. Pagination `complete` is true only on the last page and only if its projections are complete; it is false for a partial page, including the last page. Clients assemble a complete collection only after every page succeeds with the same echoed query and total. Cross-page consistency is explicitly `PER_PAGE_READ`, not an atomic multi-request snapshot. Stable safe errors are `INVALID_QUERY`, `UNAUTHORIZED`, `FUNCTION_PERMISSION_DENIED`, `STATION_SCOPE_DENIED`, `WORKER_NOT_FOUND`, `SOURCE_UNAVAILABLE`, and `INTERNAL_ERROR`; no SQL text is returned. GET performs SELECT only and never materializes a Journey or snapshot.

## 4. C02 complete incidence read — operation proposal

**Existing operations reusable:**

- P3G-A `GET /api/v2/incidences` for its existing applicable summary query by canonical worker, workplace and date interval.
- P3G-A `GET /api/v2/incidences/{uuid_incidencia}` for a single canonical incidence and its full append-only revision history.
- P3G-A `GET /api/v2/administrative-evaluations` for an existing evaluation or neutral no-evaluation result for its defined worker/workplace/work-date query.
- M05 `GET /api/v2/punch-events` only for raw observations within its independently authorized scope and completeness rules; M06's `check_reference` is an exact relation only for its defined PUNCH_ATTENDANCE cases.

These do not, as currently specified, provide the current workplace-wide date-overlap listing with type/status filters, pagination, linked applications, schedule/shift projection, all related evaluations, or a generic exact incidence-to-PunchEvent relation.

**Required logical operation:** retrieve the complete set of canonical incidences overlapping an authorized workplace/date interval, optionally narrowed by worker/category/status, and provide enough canonical application/revision/evaluation/schedule references to preserve the current workspace without Portal3 joins. Related punches must be exposed only as exact API-owned links where such a relation exists; date-coincident events must not be mislabeled as incidence-linked.

**Route:** candidate `GET /api/v2/incidences/collection` conflicts with the existing `GET /api/v2/incidences/{uuid_incidencia}` route and would be interpreted as UUID `collection`. Use the unambiguous alternative `GET /api/v2/workplaces/{workplace_id}/incidences`. Existing `/api/v2/incidences` remains unchanged.

**Query:** required inclusive valid `from`/`to`; optional canonical `worker_id`, `category`, `status`, `page` (default 1), and `per_page` (default 50, range 1–200). Maximum interval is 93 inclusive calendar dates. Invalid/excessive range, category, status or workplace returns safe `400 INVALID_QUERY`; do not silently swap/truncate. API rechecks the existing `checador-v2-incidencias.leer` permission and requires the path workplace within the fresh authorized station scope. Worker filter narrows that scope.

**Response:** HTTP 200 envelope `{status:"success", data:{query:{workplace_id,from,to,worker_id,category,status}, pagination:{page,per_page,total_records,total_pages,has_more,complete,consistency:"PER_PAGE_READ"}, incidences:[...]}}`. Each item contains the existing P3G public summary fields (UUID, canonical worker/workplace, category/scope, local date range/time interval, current status/revision, reason/evidence/source classification, actor IDs and UTC timestamps), append-only revision history, application references/statuses, linked schedule/shift projection, evaluation references/results, and raw PunchEvent context with public event UUIDs. Internal DB primary keys are not public identifiers. Events are described as incidence-linked only when a canonical P3G/M06 reference proves the link; date-coincident events are contextual observations. A failed child projection or inconsistent page totals yields `PARTIAL`, never silently omitted detail or `EMPTY`.

Ordering is start date descending, worker display name ascending, then incidence UUID ascending. `projection_status` is `EMPTY`, `PARTIAL`, or `COMPLETE`; `UNAVAILABLE` is the safe HTTP error `SOURCE_UNAVAILABLE`. An application with no administrative evaluation is explicitly `NOT_EVALUATED`; an incidence with no applicable schedule has no evaluation state. Each application's local time uses the authoritative workplace timezone effective for that work date. Contextual punches are grouped by local date and timezone and labeled `CONTEXTUAL_DATE_OVERLAP`; they are not asserted to be an exact incidence link. Pagination `complete` is true only on the last page when no child projection is partial, and remains false on a partial last page. Clients compare echoed query and total across pages and mark assembled results partial if they differ. Stable safe errors are `INVALID_QUERY`, `UNAUTHORIZED`, `FUNCTION_PERMISSION_DENIED`, `STATION_SCOPE_DENIED`, `SOURCE_UNAVAILABLE`, and `INTERNAL_ERROR`, with no SQL text. GET is SELECT-only and never evaluates or mutates an incidence.

## 5. Shared approvals and implementation gates

The current user authorization closes the development scope for the operations specified above. It does not authorize an ACL seed, broaden access, change the frozen Architecture Freeze, or claim a separate Lector/business-owner approval. The current user explicitly requires that GET not trigger Journey or administrative evaluation. No migration, database write, production change, or deployment is included.

## 6. Current blocking decisions

- **C01:** no existing endpoint read persisted Journey calculation results; this addendum specifies the new API-owned read. M05 grant and scope remain mandatory; no ACL seeding is included.
- **C02:** existing P3G-A reads remain unchanged; this addendum specifies the separate workplace collection path to avoid UUID route collision.
- **Repository state:** both repositories contain substantial pre-existing modifications and untracked files. They must be preserved and selectively reviewed; no unrelated changes are authorized by this addendum.

## 7. Source references

- Portal3: `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`, J1/J2, M05, P3H V1.1, P3G companion, M06 and P3G-B addendum.
- API V2: `docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.1.md` and `docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md`.
- Observed current implementation is mapped in the two companion proposals. Contract text and status evidence are not treated as runtime verification.
