# Checador V2 — Shift Assignment Pagination Completeness Addendum V1.0 (Draft)

**Status:** DRAFT — PENDING CROSS-PROJECT APPROVAL
**Applies to:** API V2 and Portal3, M03 shift-assignment reads only
**Supplements:** Shift Scheduling Service & API Contract V1.0 and Shift Scheduling Authorization Addendum V1.0
**Does not revise:** Architecture Freeze V1.0, Portal3 J1/J2, assignment write operations, M04, production, or Lector contracts
**Implementation authorized:** NO

## 1. Purpose and boundary

The frozen `GET /api/v2/shift-assignments` contract accepts bounded `page` and `per_page` parameters but does not define metadata or a stable read set proving that every matching assignment was read. This addendum proposes a backward-compatible, opt-in read-snapshot protocol for that endpoint only.

`ASSIGNMENT_READ_COMPLETE` means that the complete, authorized assignment result set for one worker/query was read from one immutable API snapshot. It does not mean PunchEvent/source completeness, ingestion completeness, or Journey-domain completeness under J1/J2. The M03 screen must continue to use `PARTIAL/UNKNOWN` when the assignment snapshot cannot be fully read. An absent assignment never becomes a rest day or absence.

This proposal adds no new endpoint, assignment writer, permission, membership rule, validity rule, schedule type, or business interpretation. It authorizes no implementation, migration, database operation, or production change until the approvals in Section 9 are recorded.

## 2. Existing behavior confirmed in the local checkouts

- The existing endpoint is `GET /api/v2/shift-assignments`. `id_personal` is required; `id_estacion` is optional and authorized; `fecha_referencia` is optional and uses the existing interval rules. There is no `tipo_asignacion` query filter.
- Each API request validates the delegated Portal3 JWT, the schedule-read capability, current authorized locality scope, and worker membership. SQL combines the worker and authorized-locality predicates.
- `page` defaults to 1. `per_page` defaults to 50 and is capped at 200. The current service uses offset pagination and orders by `valido_desde DESC` without a unique tie-breaker.
- The response currently contains `page`, `per_page`, and `items`, without total or continuation metadata. Portal3 requests page 1 only and correctly leaves schedule coverage partial.
- The current API's delegated JWT is created for each API request and carries a fresh `jti`; the API validates the active user and effective ACL on each request. The snapshot therefore binds to the validated user identity (`sub`), not to one reusable JWT string or `jti`. Portal3's authenticated session remains required at the BFF boundary.
- API V2 has shared MariaDB-backed PunchEvent snapshots, but their member rows and durable manifest are event-specific. They cannot be reused as assignment payload storage without changing their contract/schema semantics. No suitable shared Redis/APCu assignment-snapshot cache or persistent PHP-session store was found. Process-local PHP memory, static variables, or a per-process filesystem cache cannot support multi-process reads reliably.

## 3. Opt-in token and request protocol

The existing endpoint, `page`, `per_page`, filters, authorization, and legacy response fields remain. Snapshot reads are opt-in and use the query parameter `pagination_mode=snapshot`.

### 3.1 Initial request

The initial request must be page 1 and must not include the `X-Read-Snapshot` header:

```http
GET /api/v2/shift-assignments?id_personal=1006&id_estacion=1&fecha_referencia=2026-11-02&page=1&per_page=200&pagination_mode=snapshot
Authorization: Bearer <JWT_PORTAL3>
```

`fecha_referencia` may be omitted for the existing history-list semantics. Snapshot mode accepts the canonical filter names only; unsupported or unknown filters (including a proposed `tipo_asignacion` filter, which this endpoint does not define) return HTTP 400 `INVALID_ASSIGNMENT_QUERY`. API V2 validates all existing query requirements, captures the exactly filtered result set and its membership context, stores the immutable snapshot, then returns page 1. A successful zero-row query still creates an explicit empty snapshot.

### 3.2 Continuation request

Every continuation includes the same filters, page size, `pagination_mode=snapshot`, the next sequential page, and the returned `read_snapshot`:

```http
GET /api/v2/shift-assignments?id_personal=1006&id_estacion=1&fecha_referencia=2026-11-02&page=2&per_page=200&pagination_mode=snapshot
Authorization: Bearer <JWT_PORTAL3>
X-Read-Snapshot: <opaque-token>
```

The stored query is authoritative. Every repeated filter and `per_page` must exactly match its stored canonical value. A caller cannot change worker, locality, date, type, ordering, or page size while continuing a token. The only changing request field is the next sequential `page`; the token is never accepted as a way to omit or broaden the original scope. `X-Read-Snapshot` is a server-to-server header, never a query/path parameter, and must be redacted from API, proxy, and diagnostic logs.

### 3.3 Response metadata

The response preserves `data.page`, `data.per_page`, and `data.items`, and adds:

```json
{
  "status": "success",
  "data": {
    "page": 1,
    "per_page": 200,
    "items": [],
    "pagination": {
      "total_records": 0,
      "total_pages": 0,
      "has_more": false,
      "read_snapshot": "<opaque-token>",
      "expires_at_utc": "2026-11-04T20:05:00Z"
    }
  }
}
```

`total_records` counts the exact authorized filtered set. `total_pages` is zero for an empty result; otherwise it is `ceil(total_records / per_page)`. `has_more` is true if and only if a subsequent page exists in this snapshot. `expires_at_utc` is fixed when page 1 is created and is not extended by use.

The stable order is `valido_desde DESC, id DESC`, with unique assignment `id` as the tie-breaker. Snapshot members are assigned an immutable 1-based member order under that sort.

The API accepts only the next sequential page for a token. Repeating a page, skipping a page, using another token, or returning a page whose metadata or item count disagrees with the snapshot is rejected; no page is silently skipped. A retry of the current page may return the exact same stored page and is read-only. Portal3 appends each page at most once and independently rejects duplicate assignment IDs across the assembled result.

## 4. Expiration, binding, and HTTP errors

- TTL is exactly **300 seconds (5 minutes)** from snapshot creation, absolute and non-sliding. The token expires at `expires_at_utc`; a request at or after that instant is expired.
- The token is 256 bits of cryptographically secure randomness, encoded base64url without padding. Only its SHA-256 digest is stored. It is bound to `sub`/active Portal3 user ID, canonical authorized locality set, worker ID, optional requested locality, optional reference date, `per_page`, canonical filters, membership fingerprint, and immutable result contents.
- Every continuation revalidates the Portal3 session at its BFF request boundary and validates a fresh API access JWT, active user identity, permission, locality scope, and worker membership context. Because Portal3 issues a fresh delegated JWT (and fresh `jti`) per API request, the snapshot is bound to stable actor identity, not to the individual JWT bytes or `jti`.
- Any change to the authorized locality set, whether expansion or reduction, invalidates the token and returns **HTTP 403 `READ_SNAPSHOT_SCOPE_CHANGED`**. The API never expands the old snapshot or returns a filtered subset.
- Any relevant versioned worker-membership change invalidates the token and returns **HTTP 409 `READ_SNAPSHOT_MEMBERSHIP_CHANGED`**. The caller must discard all pages and restart at page 1 under current membership evidence.
- A malformed, unknown, expired, actor-mismatched, filter-mismatched, page-size-mismatched, or invalidated token returns one generic **HTTP 409 `READ_SNAPSHOT_INVALID_OR_EXPIRED`** response. The response does not reveal whether a token existed, who owns it, or what filters it contains. If `pagination_mode=snapshot` is sent without `X-Read-Snapshot` on page > 1, or the header is sent without snapshot mode, return this same error.
- A snapshot exceeding a configured bound returns **HTTP 413 `READ_SNAPSHOT_TOO_LARGE`**, never a truncated success. A shared-storage failure returns **HTTP 503 `READ_SNAPSHOT_STORAGE_UNAVAILABLE`**, never an empty result. Unauthorized locality remains **HTTP 403** under the existing authorization contract.
- Sensitive responses use `Cache-Control: private, no-store` and `Pragma: no-cache`. Tokens and snapshot payloads must not be written to application logs, URLs beyond the required server-to-server query, browser-visible links, or diagnostic messages. Portal3 does not expose the API token in HTML or client-side JavaScript.

## 5. Selected snapshot storage and lifecycle

### 5.1 Alternatives considered

1. **Temporary snapshot in existing shared storage:** the current `op_rh_v2_read_snapshots` / `op_rh_v2_read_snapshot_members` tables are shared across PHP processes and implement TTL-bound API snapshots, but their member schema and manifest contract are specific to immutable PunchEvents. Reusing their event foreign keys or audit manifest for mutable shift-assignment payloads would conflate domains and alter a frozen contract. No existing Redis/APCu or other shared ephemeral cache was found.
2. **No additional persistence / transaction held between pages:** a MariaDB consistent-read transaction can give one stable view only while the same transaction/connection remains open. The API serves separate HTTP requests through independent PHP workers/connections and has no safe transaction handle spanning them. PHP process memory, local variables, or per-process filesystem state cannot guarantee multi-process continuation. This alternative cannot guarantee the same result set across pages and is rejected.

### 5.2 Selected alternative

Use **dedicated, short-lived, MariaDB-backed assignment-read snapshot storage** in the existing API database, subject to separate schema/implementation authorization. This task creates no tables or migrations. The implementation proposal uses two additive InnoDB tables:

- `op_rh_v2_shift_assignment_read_snapshots`: token digest, actor ID, canonical locality-scope fingerprint, canonical filter fingerprint/values, `per_page`, membership fingerprint, row/page counts, state, `created_at_utc`, and fixed `expires_at_utc`.
- `op_rh_v2_shift_assignment_read_snapshot_members`: snapshot ID, immutable member order, assignment ID, and exact normalized assignment response payload as JSON. Unique keys enforce `(snapshot_id, member_order)` and `(snapshot_id, assignment_id)`; cascade deletion applies only to these temporary snapshot rows. No foreign key to a mutable assignment row is used for payload readback.

On page 1, API V2 starts a consistent InnoDB read, applies the existing authorization, worker, locality, optional date, and membership checks, sorts deterministically, and copies the exact selected assignment response values into the snapshot tables in one transaction. Continuation pages read only these immutable stored payloads after revalidating access and membership. Concurrent create/close/replace operations cannot shift or rewrite the captured pages. This operation writes only temporary read-snapshot state; it does not write assignment, assignment-audit, membership, Journey, or materialized-schedule records.

Hard bounds for this proposal:

- `per_page`: existing default 50, existing maximum 200.
- At most 50 pages per snapshot; therefore at most `per_page × 50` records, capped at 10,000.
- At most 4 MiB total canonical member-payload bytes per snapshot.
- At most 100 unexpired snapshots per API user, sufficient for the M03 46-worker read plus concurrent workspace use. A further initial request returns HTTP 429 `READ_SNAPSHOT_LIMIT_REACHED` with `Retry-After` no greater than the remaining TTL of the oldest snapshot for that actor.
- Global bounds: at most 5,000 unexpired snapshots, 1,000,000 stored member rows, and 512 MiB of canonical payload bytes across live and expired-but-not-yet-purged snapshots. Exceeding a live-snapshot cap returns HTTP 429 `READ_SNAPSHOT_CAPACITY_REACHED`; a physical storage cap that remains reached after the bounded purge returns HTTP 503 `READ_SNAPSHOT_STORAGE_CAPACITY`. Neither path returns a truncated or empty-success result.
- Snapshot initialization acquires the shared MariaDB named lock `checador-v2-shift-read-snapshot-capacity` while purging, counting user/global limits, checking byte/row capacity, and committing the new header. This serializes only first-page initialization; page reads remain concurrent. The lock is released in `finally`; lock/storage failure returns HTTP 503 without creating a snapshot.
- The 300-second absolute TTL applies even when a token is in active use. No sliding extension.

The API checks expiration on every access. On each new snapshot initialization, it performs an indexed purge of at most 1,000 expired member rows and at most 100 expired headers with no remaining members; `ON DELETE CASCADE` removes only temporary snapshot rows. If the global physical row/byte cap remains exceeded, creation fails closed until later bounded purges free capacity. No separate PHP session, cron process, or per-worker cache is required. Expired snapshots are inaccessible immediately; when the API is idle, expired rows may remain physically stored until a later initialization, subject to the global physical caps.

The storage is protected by the API database's existing connection and grants. Only API V2 accesses these tables; Portal3 receives page payloads through the existing authenticated backend-to-API call and never receives database access. Token digests, actor/scope/filter bindings, and payloads are protected at rest under the existing database access boundary. Schema, indexes, retention, and rollback must be reviewed and authorized before migration.

## 6. Portal3 completeness and compatibility

Portal3 opts in using `pagination_mode=snapshot` and follows pages sequentially. It may set **`ASSIGNMENT_READ_COMPLETE`** only if:

1. all page requests succeed under the same authenticated Portal3 user/session and same worker/query;
2. API page metadata echoes the requested page/page size and matches the stored `total_records`, `total_pages`, `has_more`, snapshot token, and expiration;
3. pages are received once and in sequence, all IDs are unique, every item passes current identity/locality/schema checks, and the aggregate item count equals `total_records`;
4. API authorization and membership revalidation succeeds on every page; and
5. the final page is exactly `total_pages` with `has_more=false`.

For zero rows, a successful page-1 snapshot explicitly returns zero records/pages, no items, and `has_more=false`; only that response establishes an empty complete assignment set. Any failure, duplicate, missing/reordered page, metadata mismatch, timeout, expiry, storage error, authorization or membership change keeps the aggregate `PARTIAL/UNKNOWN`. It never becomes a rest day, missing assignment conclusion, absence, or complete empty result.

Legacy consumers that omit `pagination_mode` and `X-Read-Snapshot` retain the current offset-page behavior and existing `page`, `per_page`, and `items` response shape. They do not receive or claim snapshot completeness. Their request semantics, defaults, and authorization remain unchanged. Snapshot-aware clients receive additive `data.pagination` metadata. `pagination_mode=snapshot` without a token is valid only for page 1; a token without snapshot mode is invalid. No existing consumer is required to opt in as part of this contract change.

This status is assignment-read completeness only. It does not change or satisfy J1/J2's separate PunchEvent snapshot, ingestion, or Journey completeness levels.

## 7. Authorization and membership invariants

Every request independently validates the access JWT, active human user, current schedule-read permission, and current authorized locality set before reading snapshot contents. The API compares the complete canonical locality set with the snapshot's captured set; any difference fails with `403 READ_SNAPSHOT_SCOPE_CHANGED`, including a scope expansion. It never adds newly authorized localities to an existing token.

The API re-resolves the worker's canonical membership evidence for the assignment history/date predicate on every continuation. The first request stores a fingerprint over the exact membership versions/facts used to validate the result. A changed, missing, ambiguous, or no-longer-valid membership result invalidates the token with `409 READ_SNAPSHOT_MEMBERSHIP_CHANGED`; the caller restarts from page 1. Existing membership validation rules and effective-date semantics are unchanged.

The actor is taken only from the validated JWT `sub`; request fields cannot choose an actor or locality. Portal3 continues to enforce its authenticated session and ACL before each BFF operation. The API remains the final authority. No new permission or broader scope is granted.

## 8. Isolated acceptance tests

After approval, tests must use fixtures/in-memory or an authorized isolated database, never operational schedule rows. Cover:

- zero results, one page, multiple pages, 200-row cap, 50-page/10,000-row bound, 4 MiB payload bound, per-user snapshot limit, and 300-second absolute expiry;
- deterministic `valido_desde DESC, id DESC` order including equal-date rows;
- exact query/token binding; changed worker, locality, date, type/unknown filter, page size, actor, or page sequence rejected;
- exact retry of a page returns the identical page without creating a second snapshot; repeated pages are not appended twice by Portal3;
- missing/duplicate IDs, skipped pages, wrong total/`has_more`, page failure, malformed payload, and count mismatch remain `PARTIAL/UNKNOWN`;
- permission denial, scope reduction, scope expansion, user deactivation, and relevant membership change between pages fail closed and require restart;
- concurrent assignment create, close, and replace after page 1 do not alter captured page values; a new first-page snapshot observes the later committed state;
- multi-process continuation and simultaneous snapshot creation use shared storage safely; TTL purge is bounded and never deletes assignment/audit/membership rows;
- `HABITUAL`, `TEMPORAL`, explicit day-off, and effective-date filters retain current semantics;
- old clients without snapshot parameters retain legacy response fields and behavior;
- API storage errors return the documented error, not an empty result.

### SQLite scope fixture

The existing `tests/v2_shift_assignment_read_scope_tests.php` fixture omits the `tipo_asignacion` column selected by the current service. The test-only minimum correction is to add `tipo_asignacion TEXT NOT NULL DEFAULT 'HABITUAL'` to that fixture's `CREATE TABLE`. Existing inserts may rely on the default; new temporal cases should specify `TEMPORAL` explicitly. No production schema or data change is implied.

## 9. Approval and implementation gate

This draft is not an approval and authorizes no implementation. Before changing the frozen API contract, API source, Portal3 reader, snapshot tables, or ACL, record:

1. express cross-project approval of the exact synchronized draft SHA by the authorized API V2 and Portal3 project owners;
2. separate technical implementation authorization for the development-only endpoint/consumer behavior and the two additive snapshot tables, limits, cleanup, and storage access boundary defined here;
3. confirmation that Architecture Freeze V1.0, J1/J2, assignment writers, permissions, membership semantics, validity intervals, schedules, and production remain unchanged.

Until those gates are complete, the existing API response and Portal3 `PARTIAL/UNKNOWN` behavior remain in force. Do not apply migrations, write data, alter permissions, modify frozen contracts, or implement the proposed protocol.
