# Checador V2 — Workplace Timezone Authority Addendum V1.0

**Authored by:** `CHECADOR_V2_7_WORKPLACE_TIMEZONE_AUTHORITY_GOVERNANCE_DECISION`
**Governance review:** `CHECADOR_V2_7_WORKPLACE_TIMEZONE_PHYSICAL_MODEL_REVIEW_AND_ADDENDUM_FREEZE`
**Status:** FROZEN V1.0 — physical contract; migration and runtime implementation remain unauthorized.
**Scope:** Narrow clarification of the authority and effective history of the timezone used to resolve scheduled shifts.

## 1. Decision

The canonical Workplace identity for Checador scheduling is `op_rh_localidades.id`. A Workplace timezone is authoritative configuration belonging to that locality, not to a device, Portal account, employee selection, locality name, or coincidentally equal identifier in another table.

Each Workplace must resolve to exactly one valid IANA timezone for the effective period being scheduled. `op_rh_v2_dispositivos.timezone` remains technical device metadata that may be used for capture context or diagnostics; it is never a source for canonical schedule resolution, including through `LIMIT 1`.

`America/Mexico_City` is not an unconditional production fallback. If the authoritative timezone is missing, invalid, or ambiguous, resolution fails closed with the existing `WORKPLACE_TIMEZONE_UNRESOLVED` contract error before a `ScheduledShift` is materialized. No new business or completeness state is introduced. Internal diagnostics may distinguish missing, invalid, and overlapping configuration without changing the external contract.

## 2. Effective-time rule and immutable snapshot

Timezone authority is effective-dated. Resolve it for the scheduled local work date `fecha_jornada_local` (logical input `fecha_local`), the existing scheduling key. Periods use an inclusive `valido_desde` and exclusive `valido_hasta`; a `NULL` end is open-ended. Thus, a timezone change takes effect at a local-work-date boundary. This does not change shift assignment precedence, shift boundaries, or any other scheduling semantics.

Exactly one matching configuration is required. Zero matches produce `WORKPLACE_TIMEZONE_UNRESOLVED`; more than one is an integrity violation and fails closed under that same existing error contract. Never use `LIMIT 1` to conceal ambiguity.

`op_rh_v2_jornadas_programadas.timezone_local` remains the immutable snapshot of the exact resolved timezone used at materialization. Later configuration changes do not reinterpret or rewrite materialized shifts. An unresolved historical work date is resolved using the configuration effective on that date, not the current configuration.

## 3. Value validation and backfill governance

The canonical value must be a non-empty, exact-case IANA timezone identifier recognized by the PHP timezone database (or the equivalent existing runtime facility); validation must check the identifier list, not merely whether a permissive constructor accepts the string. `timezone_iana` uses ASCII with `ascii_bin`, preserving and comparing canonical spelling case-sensitively. Fixed UTC-offset strings, abbreviations, and arbitrary offsets are not canonical Workplace values. `VARCHAR(64)` follows the existing snapshot capacity and is ample for the current PHP tzdb list (longest observed identifier: 32 characters); do not treat that observation as permission to accept non-IANA values. Missing or invalid values fail closed as `WORKPLACE_TIMEZONE_UNRESOLVED`; do not hardcode a single accepted zone.

Every active Workplace required for preproduction must receive an explicitly approved IANA mapping from an authoritative business/operations source or another explicitly approved source. Do not infer mappings from device timezones, locality/state/city names, matching numeric IDs, or a global Mexico City assumption. The observed DEVELOPMENT inventory (24 localities, 8 profiles, 3 devices all observed at `America/Mexico_City`, zero assignments, zero materialized shifts) does not authorize any backfill value.

## 4. Proposed additive physical model (not created)

The recommended model is one narrow effective-dated V2 configuration table. No table, migration, backfill, or runtime change is made by this addendum.

**Proposed table:** `op_rh_v2_localidades_zonas_horarias` (InnoDB; table default `utf8mb4_unicode_ci`, with explicit ASCII/binary semantics for the timezone column).

| Column | Proposed definition | Rule |
| --- | --- | --- |
| `id` | `BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT` | Row identity |
| `id_localidad` | `INT(11) NOT NULL` (signed) | References `op_rh_localidades.id`; exact referenced integer type |
| `timezone_iana` | `VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL` | Exact-case IANA identifier; no default |
| `valido_desde` | `DATE NOT NULL` | Inclusive local-work-date start |
| `valido_hasta` | `DATE NULL DEFAULT NULL` | Exclusive end; `NULL` means open-ended |
| `registrado_at_utc` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | UTC audit timestamp; matches V2 audit convention, with DB session timezone required to be UTC |

No other domain fields are proposed. `id_localidad`, `timezone_iana`, and `valido_desde` are non-null; only `valido_hasta` may be null. Require `valido_hasta > valido_desde` when an end exists. MariaDB 11.8.8 supports the proposed row-level checks `CHECK (CHAR_LENGTH(TRIM(timezone_iana)) > 0)` and `CHECK (valido_hasta IS NULL OR valido_hasta > valido_desde)`; application validation remains authoritative for IANA membership.

**Keys and indexes:** primary key (`id`); unique key `uk_v2_localidad_timezone_start` (`id_localidad`, `valido_desde`) to disallow duplicate starts and support the temporal lookup and FK leading-column requirement; foreign key `fk_v2_localidad_timezone_localidad` (`id_localidad`) → `op_rh_localidades.id` with `ON DELETE RESTRICT` to preserve referenced history. No separate (`id_localidad`, `valido_desde`, `valido_hasta`) index is needed: it duplicates the unique key's useful leftmost prefix. The development schema confirms the referenced key is signed `INT(11)`.

**Non-overlap:** for proposed interval `[s,e)` and existing interval `[a,b)`, they overlap exactly when `(:new_end IS NULL OR a < :new_end) AND (b IS NULL OR :new_start < b)`. On update, exclude the row being updated. The unique key does not enforce arbitrary range non-overlap. Serialize all writes for a locality in one InnoDB transaction: lock the canonical parent row with `SELECT ... FOR UPDATE`, then read that locality's intervals using a locking/current read (`SELECT ... FOR UPDATE`), reject any overlap, and insert/update only if none exists. All writers must lock the same parent row first and use this path; no direct writer may bypass it. This parent-row lock serializes concurrent writers even when there are no child rows yet; the subsequent locking read observes the latest committed intervals under InnoDB's default REPEATABLE READ as well as READ COMMITTED. Commit after the write. Verify the guarantee with a disposable-DB concurrency test before implementation. Never use `LIMIT 1` to conceal overlap or lookup ambiguity.

**Open-ended period:** `valido_hasta IS NULL` means the interval is open-ended. At most one such interval may exist per locality, and it must not overlap any other interval. MariaDB does not provide a portable partial unique index for this rule here; enforce it through the same serialized overlap transaction. Two open-ended intervals necessarily satisfy the overlap predicate and are rejected.

**Historical lookup:** for `(id_localidad, fecha_jornada_local)` (logical input `fecha_local`), find rows satisfying `valido_desde <= fecha_jornada_local AND (valido_hasta IS NULL OR fecha_jornada_local < valido_hasta)` and evaluate cardinality: exactly one uses its `timezone_iana`; zero produces `WORKPLACE_TIMEZONE_UNRESOLVED`; more than one is an integrity error and fails closed under that same contract. Do not substitute device or global timezone values, and do not select an arbitrary first row.

## 5. Physical-model alternatives

- **Option A — add one timezone column to `op_rh_localidades`:** insufficient. It identifies the right Workplace, but a mutable single value cannot preserve effective history or answer historical work-date resolution.
- **Option B — add/use `op_rh_localidades_perfil`:** insufficient as the authority model. The profile is not the canonical locality identity and a mutable profile value does not independently preserve effective history; coupling scheduling authority to profile state is unnecessary. It would still require a separate history model to meet this decision.
- **Option C — new narrow effective-dated V2 table:** recommended. It references the authoritative locality ID, preserves timezone history, supports deterministic date-based lookup, and leaves existing Workplace, device, and scheduling entities otherwise unchanged.

## 6. Narrow precedence over conflicting timezone fallback clauses

This addendum clarifies and supersedes only conflicting timezone fallback behavior in:

1. `docs/v2/CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md`, §6.6: use of `op_rh_localidades_perfil` or a device's timezone as canonical Workplace authority, and fallback to `America/Mexico_City` when effective canonical configuration is unavailable. The effective-dated V2 configuration table is the sole scheduling authority; `WORKPLACE_TIMEZONE_UNRESOLVED` governs otherwise. Its `WORKPLACE_TIMEZONE_RUNTIME_GAP: NO` line is not evidence that this proposed effective-dated authority table or lookup has been implemented; this addendum makes no runtime-readiness assertion.
2. `docs/architecture/CHECADOR_V2_SHIFT_SCHEDULING_PHYSICAL_MODEL_V1.0.md`, §3: the `America/Mexico_City` default for `timezone_local`, and §4.B: device-timezone fallback. For future schedule materialization, the application must explicitly provide the resolved Workplace timezone. The future migration contract must remove the database default from `op_rh_v2_jornadas_programadas.timezone_local`, keep it non-null, and preserve all existing stored values so an omitted insert cannot silently materialize the global fallback. This requires a separately authorized schema step; no existing frozen file or row is changed here.

No other clause or shift semantic is changed. The Architecture Freeze, Portal/Telegram contracts, device capture behavior, raw PunchEvents, P1, P2, V2.5, V2.6, payroll behavior, and already-materialized ScheduledShift rows remain unchanged. The downstream safeguards remain: no wrong timezone may silently materialize a Journey; P1 must not select wrong PunchEvents through shifted boundaries; P2 must not calculate from incorrectly shifted boundaries; V2.5 must not derive `FALTA` from missing timezone authority; V2.6 remains parallel and non-payroll-impacting.

## 7. Implementation gates

This is a frozen contract clarification and physical schema proposal only. The future preproduction input must be an explicitly approved mapping with exactly `id_localidad`, `timezone_iana`, `valido_desde`, and `valido_hasta` (nullable), validated for required Workplace coverage, exact-case IANA membership, interval validity, and absence of overlaps; this artifact authorizes no values. Before implementation, separately authorize migration authoring and disposable-database validation, approved per-Workplace backfill inputs, runtime resolution changes, and regression coverage. Production access or execution is not authorized.
