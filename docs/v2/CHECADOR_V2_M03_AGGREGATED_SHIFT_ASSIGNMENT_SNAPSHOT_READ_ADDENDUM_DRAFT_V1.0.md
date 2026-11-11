# Checador V2 — Aggregated Shift Assignment Snapshot Read Addendum V1.0 (Draft)

**Status:** DRAFT — PENDING CROSS-PROJECT APPROVAL AND DEVELOPMENT IMPLEMENTATION AUTHORIZATION  
**Applies to:** API V2 and Portal3, M03 weekly shift-assignment reads only  
**Supplements:** Shift Assignment Pagination Completeness Addendum V1.0, normative document identified by SHA-256 `50f67f0468f9dfabb38bf7ea3d363f6ad7e618ee6d3a68cc6ce82393ebcc9385`  
**Does not revise:** Architecture Freeze V1.0, J1/J2, M04, assignment writers, membership rules, ACL grants, or production  
**Implementation authorized:** NO

## 1. Purpose, scope, and governing boundaries

The existing per-worker snapshot protocol creates one snapshot for each worker. M03 needs to read the authorized assignment history needed to resolve a complete seven-day week for a station roster. Repeating an individual snapshot for each worker consumes snapshot capacity on every page load. This addendum proposes an opt-in aggregate read mode that creates one bounded, immutable assignment snapshot for one canonical workplace and one exact worker set. It does not raise the existing worker, page, TTL, or snapshot-capacity limits.

This contract covers assignment reads only. It does not certify that the worker roster itself is complete, resolve PunchEvents or Journey data, authorize assignment writes, alter schedule semantics, or turn missing data into a rest day, absence, or other employment result. J1/J2 completeness remains independent. A Portal3 weekly matrix may be called complete only under Section 7.

The API remains the authorization and membership authority. Portal3 must preserve its authenticated session and effective ACL checks at the backend-for-frontend boundary. No browser request may call API V2 directly or receive the delegated API JWT or snapshot token.

## 2. Backward-compatible opt-in request

The existing endpoint remains `GET /api/v2/shift-assignments`. Existing individual requests using `id_personal`, current filters, `page`, `per_page`, and the existing response fields retain their current semantics. Existing consumers are not required to send any new parameter and do not gain snapshot-completeness claims.

Aggregate mode is explicitly opt-in and requires all of:

- `pagination_mode=snapshot`
- `scope_mode=aggregate`
- `workplace_id=<canonical op_rh_localidades.id>`
- `worker_ids=<comma-separated canonical worker IDs>`
- `week_start=YYYY-MM-DD`
- `page=1` and the existing `per_page` on the initial request
- `X-Read-Snapshot` on each continuation request, with the same request filters and next sequential page

Aggregate mode MUST NOT accept `id_personal`, `id_estacion`, or `fecha_referencia`. The aggregate filters above are mutually bound; unknown, repeated, or conflicting query parameters are rejected. An aggregate request without snapshot mode, or snapshot mode without the required aggregate parameters, is rejected. The existing individual snapshot mode remains unchanged and is selected by `id_personal` without `scope_mode=aggregate`.

Example initial request (server-to-server only):

```http
GET /api/v2/shift-assignments?pagination_mode=snapshot&scope_mode=aggregate&workplace_id=1&worker_ids=1004%2C1006&week_start=2026-11-02&page=1&per_page=200
Authorization: Bearer <delegated Portal3 JWT>
X-Read-Request-Key: <22-character-base64url-retry-key>
```

Example continuation:

```http
GET /api/v2/shift-assignments?pagination_mode=snapshot&scope_mode=aggregate&workplace_id=1&worker_ids=1004%2C1006&week_start=2026-11-02&page=2&per_page=200
Authorization: Bearer <fresh delegated Portal3 JWT>
X-Read-Snapshot: <opaque-token>
```

### 2.1 Worker ID wire contract

`worker_ids` occurs exactly once and contains one comma-delimited list in the query string. The HTTP query is decoded exactly once before parsing. Each item MUST be a canonical positive base-10 integer from `1` through `2147483647`, with no sign, whitespace, decimal point, exponent, empty item, or leading zero. The list MUST contain 1–50 distinct IDs. The API canonicalizes it by numeric ascending order before hashing and snapshot binding. A second `worker_ids` parameter, duplicate ID, malformed item, empty set, or invalid encoding returns HTTP 400 `INVALID_ASSIGNMENT_QUERY`. More than 50 IDs returns HTTP 413 `ASSIGNMENT_WORKER_SET_TOO_LARGE` before any snapshot is created.

The API validates the whole set atomically. If any ID is unknown, inactive, ambiguous, not a worker, outside the selected workplace, or lacks the required membership evidence for the complete requested week, the API rejects the entire set with the generic HTTP 403 `ASSIGNMENT_WORKER_SET_UNAUTHORIZED`. The response MUST NOT identify which ID failed or reveal whether a worker exists. The API never silently removes an ID, substitutes another worker, splits the result, or broadens scope.

`worker_set_sha256` is SHA-256 over the UTF-8 bytes of the canonical comma-separated ascending decimal IDs, with no spaces and no trailing comma. Portal3 sends this canonical set and verifies the returned hash and count. The token binds to this exact set; no continuation may add, remove, reorder semantically, or replace an ID.

## 3. Canonical locality, authorization, and membership

`workplace_id` is specifically a canonical `op_rh_localidades.id` value. It is not a `tb_estaciones.id`, `numlista`, department ID, group ID, or device ID. The field name and contract namespace are intentional to prevent numeric collisions between catalogs. API V2 MUST resolve the canonical workplace through the existing authoritative, unique mapping used by Shift Scheduling. It MUST NOT infer equivalence from equal numeric IDs, names, or client-supplied mappings. Missing, inactive, unsupported, non-unique, department-only, or ambiguous mappings fail closed with HTTP 403 `UNAUTHORIZED_STATION_ACCESS` (or the existing exact workplace-scope error, if already defined by the base contract).

For every initial and continuation request, API V2 derives the actor only from the validated delegated JWT `sub`; it does not accept an actor ID in request data. It validates the active human user/session, effective `checador.programacion.leer` permission, and current authorization for the single canonical `workplace_id`. The permission is read-only and is not implied to grant assignment creation, editing, template administration, `biometricos.editar`, or any other capability. Portal3 also revalidates its authenticated session and read permission before each backend request.

For each worker, API V2 verifies a unique, non-overlapping, versioned membership in the selected canonical workplace that continuously covers the entire seven-day interval. Membership intervals remain UTC semi-open intervals `[membership_from_utc, membership_until_utc)`. A gap, overlap, ambiguity, inactive/invalid interval, or transfer to/from another workplace at any instant in the week rejects the complete aggregate request; API V2 does not attribute assignments to dates for which the requested workplace membership is not evidenced. The current physical station or roster row is not a historical substitute.

Every continuation revalidates actor identity, session/JWT, permission, canonical workplace scope, and the exact membership evidence for every requested worker. The snapshot token binds to actor, canonical workplace, week filters, canonical worker set and hash, page size, membership fingerprint, immutable assignment result, and expiry. A locality-scope change (expansion or reduction), membership change, or loss of permission invalidates the token and requires a new first-page read. No token may expand its original scope.

## 4. Weekly civil-date and assignment semantics

`week_start` is a civil ISO date (`YYYY-MM-DD`) that MUST be a Monday in the canonical workplace's configured IANA timezone. The API derives `week_end_exclusive` as the following Monday's civil date. The requested local interval is `[week_start 00:00, week_end_exclusive 00:00)` in that workplace timezone. For membership validation, API V2 converts both boundaries using the configured IANA timezone and its timezone database to UTC; the resulting elapsed duration may differ from 168 hours across a timezone transition. An unresolved/invalid workplace timezone or a boundary that cannot be resolved uniquely is rejected fail-closed using the existing timezone error contract; no fixed offset is applied.

A weekly aggregate request MUST NOT use one `fecha_referencia` as a proxy for the week. `week_start` is required, and `fecha_referencia` is forbidden in aggregate mode. Invalid dates, non-Monday dates, and nonexistent civil dates return HTTP 400 `INVALID_ASSIGNMENT_QUERY`.

The snapshot contains every authorized assignment-history row for each requested worker whose existing civil-date validity interval overlaps `[week_start, week_end_exclusive)`, using the existing semi-open assignment validity semantics:

`valido_desde < week_end_exclusive AND (valido_hasta IS NULL OR valido_hasta > week_start)`.

The API preserves assignment type (`HABITUAL`/`TEMPORAL`), explicit day-off representation, template/version references, actor/audit fields already exposed by the individual contract, and historical versions needed to resolve all seven civil dates. It does not collapse history to one row per worker, select a single date, infer missing days as rest, or modify permanent/temporary precedence. The current canonical resolver and interval rules remain authoritative.

Each requested worker receives an explicit `worker_results` summary in snapshot metadata containing only canonical `id_personal` and the count of matching assignment rows in this snapshot. A count of zero is an explicit complete empty assignment result for that worker within the captured filters; it is not a rest day or absence. Assignment rows always include their canonical `id_personal`.

## 5. Snapshot creation, pagination, retry, and errors

One initial aggregate request creates one immutable snapshot for the exact validated actor, one canonical workplace, one canonical worker set, one week interval, page size, membership evidence, and assignment result. API V2 captures all membership facts and assignment rows for that set from one shared InnoDB consistent-read view, then atomically commits the temporary snapshot header, request-key binding, worker summaries, and ordered result payloads. A concurrent schedule write is therefore wholly before or wholly after the captured read view; it cannot make different workers or pages represent different assignment revisions. The snapshot is not usable unless its complete initialization transaction commits. The aggregate order is deterministic: `id_personal ASC, valido_desde DESC, id DESC`, where assignment `id` is the unique tie-breaker. Each record is assigned an immutable 1-based snapshot order. The aggregate has one `total_records`, `total_pages`, and `has_more`; `total_pages` is zero for zero records and otherwise `ceil(total_records / per_page)`.

The response preserves the existing response envelope and adds `data.pagination` fields `total_records`, `total_pages`, `has_more`, `read_snapshot`, `expires_at_utc`, `workplace_id`, `week_start`, `week_end_exclusive`, `worker_count`, `worker_set_sha256`, and `worker_results`. The `worker_results` array contains exactly one entry for every canonical requested worker and is identical on every page. `expires_at_utc` is fixed at snapshot creation and is not extended by use. Empty assignment results still create an explicit empty snapshot with zero pages and all worker result counts equal to zero.

A continuation accepts only the next sequential page. All bound parameters and the worker set must match the snapshot exactly. Repeating the current page with the same actor, token, and filters returns the byte-equivalent logical page and metadata; it is not appended twice by Portal3. A first-page request MUST include `X-Read-Request-Key`, exactly 22 canonical unpadded base64url characters encoding 128 bits generated by a cryptographically secure random source; decoding and re-encoding the value without padding MUST reproduce the identical string. A first-page retry after an uncertain network outcome MUST reuse the same key; continuation requests MUST omit it and use `X-Read-Snapshot`. The API stores only a digest of the key and binds it to actor and the canonical aggregate request for the snapshot TTL. Before returning a replay, API V2 revalidates actor, permission, scope, and membership exactly as it does for a continuation. The same key and same still-authorized request returns the original snapshot/page 1; same key with different request parameters returns HTTP 409 `READ_SNAPSHOT_INVALID_OR_EXPIRED`. A new logical read uses a fresh key. Tokens and request keys are never exposed to browser JavaScript or logged.

Existing page-size bounds and stable ordering from the pagination addendum remain in force (`per_page` default 50, maximum 200, unless the approved base text specifies a stricter bound). The aggregate-specific 50-worker cap does not change the page or record bounds. A snapshot whose result exceeds the previously approved page, row, payload-byte, or storage limits fails with the existing documented HTTP 413/429/503 error; it is never truncated or reported complete.

The base pagination addendum's 300-second absolute TTL, maximum 100 unexpired snapshots per actor, global storage limits, sequential page rules, capacity handling, and error vocabulary remain unchanged. One aggregate snapshot consumes one actor snapshot slot, regardless of worker count or page count. A retry with the same request key does not consume another slot. No change to any bound is proposed here.

## 6. More than 50 workers

V1 aggregate mode supports at most 50 workers and MUST NOT partition a larger station roster into independently created snapshots. If the independently verified station roster exceeds 50, Portal3 MUST NOT submit batches and combine them as if they were one coherent weekly read. Such batches could capture different assignment and membership revisions, duplicate or omit a worker, and incorrectly claim one consistent roster matrix. API V2 returns HTTP 413 `ASSIGNMENT_WORKER_SET_TOO_LARGE` if a request contains more than 50 IDs; Portal3 retains `PARTIAL/UNKNOWN` and does not claim station-wide assignment completeness. The current known 47-worker Interlomas roster fits the numeric bound, subject to full-week membership and independently complete roster evidence.

Supporting more than 50 workers requires a separately approved read-group contract that binds all partitions to one canonical roster fingerprint, actor, workplace, week, membership revision, and common database read revision; proves disjoint/exhaustive worker-ID coverage and rejects duplicates/omissions; shares one expiry and invalidation rule; and accounts for capacity as one logical snapshot. This addendum does not define or authorize that extension. The worker cap, 100-snapshot actor limit, 300-second TTL, page limits, and global capacity limits MUST NOT be raised to avoid this behavior.

## 7. Separate completeness states and Portal3 behavior

`ASSIGNMENT_READ_COMPLETE` means only that API V2 returned every assignment row for the exact authorized `worker_ids` set, canonical workplace, and week interval captured in one immutable snapshot. Portal3 may set it only after all pages succeed sequentially, the worker count and hash equal the submitted canonical set, `worker_results` contains that exact set once, all assignment IDs and worker-result IDs are unique, every item belongs to the requested worker/workplace and passes schema checks, counts match metadata, and the final page has `has_more=false` at exactly `total_pages`. For zero rows, the explicit zero-page snapshot is complete only if the worker manifest is also exact and validated.

`STATION_ROSTER_COMPLETE` is a separate status established only by the independently authorized station-roster source under its own contract. Assignment API success, worker-set hash, short page, snapshot metadata, or membership validation MUST NOT establish roster completeness. Portal3 records the roster source's complete manifest/fingerprint for the same canonical workplace and week. The exact canonical worker-ID set with complete membership evidence used for the aggregate read must equal the independently verified complete station-roster set. If the roster source is absent, partial, ambiguous, or has workers without full-week membership, `STATION_ROSTER_COMPLETE` or the matrix cannot be complete.

Portal3 may set `M03_WEEKLY_MATRIX_COMPLETE` only when all three independent conditions hold: `ASSIGNMENT_READ_COMPLETE=true`, `STATION_ROSTER_COMPLETE=true`, and every worker in that complete roster has unique full-week membership evidence for the selected workplace and is represented exactly once in the aggregate worker manifest. Otherwise the matrix is `PARTIAL/UNKNOWN`. Workers lacking membership may remain visible as roster references but are not authorized for assignment attribution or writes. A complete assignment read for a subset never implies a complete station matrix. No missing assignment becomes a rest day, absence, or other employment conclusion.

Portal3 follows pages in order, rejects missing/duplicate/reordered pages, duplicate assignment IDs, worker mismatches, inconsistent metadata, membership failures, timeouts, expiry, storage errors, and scope changes. On any failure it discards the aggregate result for completeness purposes and preserves `PARTIAL/UNKNOWN`; it does not merge snapshots from retries or partitions.

## 8. Temporary snapshot storage and database-write scope

Aggregate mode uses the same API-owned shared snapshot storage, lifecycle, and limits authorized by the preceding pagination addendum. Snapshot payloads are immutable and isolated from mutable assignment rows. The storage must be shared across API PHP processes. Process-local variables, one PHP session, a per-process cache, or a transaction held open across HTTP requests do not satisfy this contract.

The only database writes permitted by this proposed read mode are temporary snapshot operations in the API V2 database: create an aggregate snapshot header and its immutable membership/assignment result rows on the first-page GET; record the first-page request-key binding; update only continuation/read-state needed by the pagination protocol; and delete only expired snapshot-owned rows during the authorized bounded cleanup. These temporary writes must be transactional and constrained by snapshot foreign keys/ownership. They MUST NOT write or update worker, workplace, membership, assignment, assignment-audit, template, materialized schedule, PunchEvent, Journey, payroll, ACL, or legacy records. Portal3 makes no direct database write for this read.

This addendum does not itself authorize creating/changing snapshot tables, indexes, database grants, retention jobs, storage code, ACL grants, endpoint behavior, or operational reads. Each required API implementation/storage/migration change remains subject to explicit cross-project approval of this exact document and the separate technical implementation authorization described in Section 10. Existing database schema and grants are not presumed to contain aggregate storage fields; they must be reviewed before implementation authorization is acted upon.

## 9. Compatibility, errors, and prior pagination addendum status

The individual `id_personal` endpoint path and all existing non-snapshot consumers remain unchanged. The aggregate mode is additive and opt-in. Legacy callers without `scope_mode=aggregate`, `workplace_id`, `worker_ids`, and `week_start` keep their existing request/response behavior and do not receive aggregate completeness semantics. Unknown parameters are rejected only according to the existing endpoint behavior for that request mode; aggregate-only validation does not reinterpret legacy requests.

Aggregate-specific errors are:

| Condition | HTTP | Code |
| --- | ---: | --- |
| Malformed, repeated, missing, conflicting, noncanonical, or duplicate aggregate parameters/worker IDs; missing, repeated, or malformed initial `X-Read-Request-Key`; an initial `X-Read-Snapshot`; a continuation missing `X-Read-Snapshot` or carrying `X-Read-Request-Key`; invalid/non-Monday `week_start` | 400 | `INVALID_ASSIGNMENT_QUERY` |
| More than 50 worker IDs | 413 | `ASSIGNMENT_WORKER_SET_TOO_LARGE` |
| Any worker unauthorized, inactive, ambiguous, or lacking continuous full-week membership | 403 | `ASSIGNMENT_WORKER_SET_UNAUTHORIZED` |
| Canonical workplace unsupported, unmapped, ambiguous, or outside current actor scope | 403 | `UNAUTHORIZED_STATION_ACCESS` |
| Invalid/expired/actor-mismatched/filter-mismatched token or request-key conflict | 409 | `READ_SNAPSHOT_INVALID_OR_EXPIRED` |
| Authorized workplace scope changed | 403 | `READ_SNAPSHOT_SCOPE_CHANGED` |
| Relevant membership changed during continuation | 409 | `READ_SNAPSHOT_MEMBERSHIP_CHANGED` |
| Snapshot result exceeds an already approved snapshot size/capacity limit | existing 413/429/503 base error | exact code from pagination addendum |
| Snapshot storage unavailable | 503 | `READ_SNAPSHOT_STORAGE_UNAVAILABLE` |

Errors use the established API JSON envelope and do not disclose whether a worker or snapshot exists. No parameter or error in this addendum creates a write permission.

The preceding pagination addendum is referenced by its exact SHA-256 above and its normative text is not changed by this document. The responsible owner's prior approval of that exact pagination addendum is the basis for treating its protocol and limits as the existing baseline. The local API copy currently retains a `DRAFT — PENDING CROSS-PROJECT APPROVAL` status header, which conflicts with that approval state. Before implementation, the authorized governance record/status procedure MUST reconcile that status without rewriting the pagination addendum's normative clauses. This draft does not silently mark that separate document approved or use its status discrepancy to expand this proposal's scope.

## 10. Compatibility with Architecture Freeze, J1/J2, and approvals

This proposal adds only an opt-in M03 aggregate read contract on the existing assignment endpoint. It does not amend Architecture Freeze V1.0, J1/J2, the Shift Assignment writers, assignment validity intervals, membership rules, the individual endpoint contract, M04, or existing permissions. It reuses the `checador.programacion.leer` authority defined by the Shift Scheduling Authorization Addendum; it adds no ACL grant and no write capability.

`ASSIGNMENT_READ_COMPLETE` is only a statement about assignment rows and does not satisfy or override PunchEvent, source, Journey, or J1/J2 completeness. API V2 remains responsible for authorized immutable assignment snapshot data; Portal3 remains responsible for its station roster source and presentation state. No runtime J1/J2 evaluation or materialization is introduced.

This draft is not an approval and authorizes no implementation, migration, snapshot storage change, ACL change, or operational read. Before implementation, record: (1) express cross-project approval by the authorized API V2 and Portal3 owners of this exact synchronized SHA; (2) separate technical implementation authorization limited to development for the aggregate request/response behavior and the temporary API snapshot writes/storage changes described here; and (3) reconciliation of the preceding pagination addendum's repository status with its existing approval record, without changing its normative text. Until those gates are complete, current API and Portal3 behavior remains unchanged.
