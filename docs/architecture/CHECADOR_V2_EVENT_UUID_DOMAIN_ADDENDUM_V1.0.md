# CHECADOR V2 — EVENT UUID DOMAIN ADDENDUM V1.0

**Status:** FROZEN V1.0  
**Implementation Authorized:** NO  
**Database Changes Authorized:** NO  
**Production Changes Authorized:** NO

## 1. Purpose and scope

This addendum resolves the V2 PunchEvent `event_uuid` domain and its
physical width across raw ingestion and Level 1 snapshot membership. It
supersedes only conflicting `event_uuid` format, UUID-only, maximum-width,
and snapshot-width assumptions in applicable V2 contracts and physical
models.

This addendum does not reopen or amend the main Architecture Freeze. It
does not change the meaning of a PunchEvent, Level 1 snapshot semantics
other than the `event_uuid` representation, Level 2 governing semantics,
or any unrelated Checador V2 contract.

## 2. Semantic domain

`event_uuid` is an opaque, immutable event identifier.

For device-origin PunchEvents, the client/Lector generates the identifier
before first transmission and reuses it unchanged on retries. A
server-generated identifier is permitted for a future Portal-origin
PunchEvent. This statement does not authorize or reactivate Portal +
Telegram implementation.

The identifier participates in the existing applicable idempotency scope.
For device-origin events that scope remains `(id_dispositivo, event_uuid)`.
The identifier is not globally unique by contract.

## 3. Maximum length and encoding

After the existing API trim behavior, `event_uuid` MUST contain between
1 and 64 characters and MUST be valid UTF-8.

RFC UUID/UUIDv4 syntax is not required. UUID-form values and other opaque
identifier formats are permitted, including identifiers with prefixes,
provided they meet the non-empty and maximum-length rules.

## 4. Whitespace behavior

The API applies its existing `trim()` behavior to the submitted value
before validation and persistence. It rejects a value that is empty after
trimming. The trimmed value is persisted and used by subsequent identity
and retry processing.

No additional whitespace normalization is defined by this addendum.

## 5. Representation preservation

The system preserves the submitted capitalization and other characters of
the post-trim value. It MUST NOT lowercase, uppercase, rewrite, truncate,
or otherwise normalize the identifier.

Retries MUST submit the same post-trim `event_uuid` representation as the
original event.

## 6. Database identity equivalence

Database identity continues to use the existing
`utf8mb4_unicode_ci` equivalence semantics. This equivalence is broader
than ASCII case-insensitivity; the contract preserves the collation's
existing equivalence behavior generally.

For a given device, values considered equal by
`utf8mb4_unicode_ci` collide under the existing device-scoped identity and
cannot create separate raw PunchEvents. The raw collation MUST NOT be
changed to `utf8mb4_bin` or another case-sensitive identity collation by
this addendum.

## 7. Exact-retry semantics

A matching database identity is not by itself an accepted exact replay.
`200 ALREADY_RECEIVED` requires the stored `event_uuid` representation
to match exactly and the existing canonical client-payload equality
checks to pass.

If a submitted representation collides with the stored identifier under
`utf8mb4_unicode_ci` but differs from its exact stored representation, the
API returns `409 IDEMPOTENCY_CONFLICT`. It is neither an exact replay nor
a new event. A divergent client payload for an existing identity also
returns `409 IDEMPOTENCY_CONFLICT`.

The existing integrity verification of a stored event remains in force.
HMAC Envelope V1 semantics are unchanged.

## 8. Device-scoped idempotency

The raw unique key remains:

```text
UNIQUE KEY idx_idempotencia_dispositivo_event
    (id_dispositivo, event_uuid)
```

This addendum does not change device-scoped idempotency, Level 2
generation/sequence requirements, or retry behavior for those fields.
The same exact `event_uuid` may be used for different devices where the
existing composite identity permits it. No global
`UNIQUE(event_uuid)` constraint is introduced.

## 9. Raw physical representation

The raw column remains:

```text
op_rh_v2_marcaciones_crudas.event_uuid
VARCHAR(64) CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci NOT NULL
```

No raw width or collation delta is required.

## 10. Level 1 snapshot representation

The Level 1 snapshot member column MUST support the complete accepted
`event_uuid` domain:

```text
op_rh_v2_read_snapshot_members.event_uuid
VARCHAR(64) CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci NOT NULL
```

The current `VARCHAR(36)` snapshot representation is superseded by this
addendum. Snapshot materialization MUST preserve the exact raw stored
`event_uuid` value. Truncation, rewriting, or case conversion is
prohibited.

## 11. Ingestion validation follow-up

Before persistence, ingestion implementation MUST:

1. Require a string value under the request contract.
2. Apply the existing API trim behavior.
3. Reject an empty post-trim value.
4. Reject a post-trim value exceeding 64 characters.
5. Not require RFC UUID/UUIDv4 syntax.

This is a future implementation requirement, not implementation
authorization in this addendum.

## 12. Historical and backward compatibility

Existing raw identifiers remain unchanged. No historical identifier is
rewritten or truncated. Existing snapshot manifests retain their historical
meaning.

Widening the snapshot column does not authorize manifest regeneration,
snapshot backfill, or rewriting snapshot history. Development data
observations are migration-planning evidence only; they do not define the
identifier's semantic domain.

## 13. Non-goals and unchanged contracts

This addendum does not alter:

- the main Architecture Freeze;
- raw-event immutability;
- HMAC Envelope V1;
- device-scoped idempotency;
- Portal operation idempotency or its scope;
- source-completeness requirements;
- Level 1 snapshot semantics other than `event_uuid` representation;
- Level 2 generation, sequence, or governing semantics;
- P1, P2, V2.5, or V2.6;
- the Portal3 read boundary.

## 14. Portal + Telegram implementation status

Portal + Telegram architecture remains frozen and its implementation
remains `FROZEN_DEFERRED`. This addendum makes the identifier domain
compatible with future server-generated Portal-origin identifiers but does
not authorize Portal + Telegram UI, runtime, database changes, migrations,
or production configuration. The legacy Telegram chatbot recovery and
reuse forensic gate remains in force.

## 15. Authorization status

```text
Implementation Authorized: NO
Database Changes Authorized: NO
Production Changes Authorized: NO
```

This document freezes the narrow `event_uuid` domain contract only. Any
implementation or migration requires separate authorization.

