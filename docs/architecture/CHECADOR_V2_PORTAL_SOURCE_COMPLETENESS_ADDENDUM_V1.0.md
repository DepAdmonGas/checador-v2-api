# CHECADOR V2 — PORTAL SOURCE COMPLETENESS ADDENDUM V1.0

**Status:** FROZEN — additive contract  
**Scope:** Portal-origin attendance events and mixed-source journey finality  
**Implementation Authorized:** NO

This addendum freezes only the source-completeness semantics for Portal-origin events. It does not modify or supersede the Architecture Freeze V1.0, the approved Portal + Telegram Capture Channel Addendum V1.0, or closed P1/P2/V2.5/V2.6 milestones. It does not authorize implementation, database changes, migrations, or general Portal3 V2 read access.

## 1. Governing distinction

```text
EVENT AUTHENTICITY IS NOT SOURCE COMPLETENESS
```

A valid Portal-origin event may be individually trustworthy because it came through the authenticated Portal command flow, was bound to the canonical worker, passed the Telegram challenge, used API server time, was persisted atomically, and has a valid integrity seal. Those facts do not prove that the applicable attendance source or workday contains every expected event.

## 2. Source scopes

### BIOMETRIC_DEVICE

Existing Level 1 and Level 2 contracts remain authoritative for device-origin events. Level 2 completeness retains its existing device/station/date meaning and is not redefined as a generic source engine.

### PORTAL_TELEGRAM_VERIFIED

A Portal-origin event has no physical device, device generation, device sequence, device telemetry, or Level 2 device evidence. None of these may be fabricated or inferred for Portal. The event's authenticity is established by its command transaction and integrity seal; its source completeness is a separate question.

Without separate sufficient completeness evidence under an approved Portal-source contract, Portal source completeness is `UNKNOWN`, not `VERIFIED`.

## 3. Aggregate source-completeness states

The additive, source-aware aggregate states are:

| State | Meaning |
|---|---|
| `INCOMPLETE` | At least one applicable source has sufficient evidence that it is incomplete. |
| `UNKNOWN` | No applicable source proves incompleteness, but one or more applicable sources lack sufficient completeness evidence. |
| `VERIFIED` | Every applicable source has sufficient completeness evidence under its own contract. |

Aggregation rules:

1. `INCOMPLETE` applies when any applicable source has sufficient evidence of incompleteness.
2. Otherwise, `UNKNOWN` applies when any applicable source lacks sufficient completeness evidence.
3. `VERIFIED` applies only when every applicable source is sufficiently evidenced as complete.
4. Absence of evidence, events, or a Level 2 evaluation is never by itself evidence of completeness.
5. A source not applicable to the journey is not treated as an incomplete source; applicability must be recorded with the evaluation.

These rules are source-aware and additive. They do not reinterpret existing Level 2 results or retroactively rewrite historical biometric-only P1 results.

## 4. P1 additive contract

P1 may continue to pair valid biometric and Portal `PunchEvent`s chronologically for the same canonical worker and journey. It must not split a journey solely because capture methods differ.

For a source-aware P1 evaluation:

- record aggregate source completeness (`VERIFIED`, `INCOMPLETE`, or `UNKNOWN`);
- record the applicable source set and the evidence references used for each applicable source;
- retain `evaluation_ref` as Level 2-specific; it may be nullable or not applicable for Portal and must not be fabricated;
- use a source-completeness reference/state separate from the Level 2 evaluation reference;
- preserve pairing even when completeness is unknown or the result requires review;
- treat a Portal-only source without sufficient completeness evidence as `UNKNOWN`;
- never convert missing source evidence into `VERIFIED`.

Completeness/finality is independent of whether individual PunchEvents are trusted. An evaluation with `UNKNOWN` or `INCOMPLETE` source completeness remains non-final/review as appropriate.

## 5. P2 propagation

P2 must propagate upstream `UNKNOWN` or `INCOMPLETE` source completeness as a non-final/review/data-incomplete result. The ability to calculate metrics does not permit P2 to elevate uncertainty into a final complete journey. Existing fully `VERIFIED` biometric journeys remain unchanged.

## 6. V2.5 administrative safety

`FALTA` requires sufficient facts and sufficient source completeness. If completeness is `UNKNOWN` or `INCOMPLETE`, V2.5 must not infer `FALTA` from missing events. It must produce a non-final/review/data-incomplete administrative state as applicable and must not rewrite raw `PunchEvent`s.

Schedule facts alone, absence of an event, or absence of a completeness evaluation are insufficient to establish `FALTA`.

## 7. V2.6 propagation

No V2.6 redesign is required. Existing `NOT_COMPARABLE`/review behavior may be used when upstream V2.5 is non-final. An uncertain result must not be compared as final absence or normal completion.

## 8. Snapshot and raw-read compatibility

The frozen semantic requirement for Portal-origin raw events is:

```text
id_dispositivo = NULL
```

Never substitute `0` or a synthetic device. Snapshot membership must identify the exact raw event without requiring a device identity. Raw-read JSON must preserve:

```json
"id_dispositivo": null
```

This is a semantic compatibility requirement only. It does not authorize general Portal3 V2 read access.

## 9. Integrity representation

The existing HMAC envelope remains V1. Canonical representation must preserve:

```text
NULL != 0
NULL != ""
```

No new integrity-envelope version is required solely for nullable Portal device/reference fields.

## 10. Worker eligibility evidence

Authorized development forensic evidence closes the worker-status gate:

```text
op_rh_personal.estado = 1  => ACTIVE
op_rh_personal.estado = 0  => INACTIVE / NOT ACTIVE
```

For future Portal capture eligibility, the canonical worker must satisfy `op_rh_personal.estado = 1`. `estado = 0` may have multiple business causes and must not be interpreted as one exclusive termination reason. This evidence does not alter historical raw events.

## 11. Legacy DDL preflight — closed

The authorized development DDL preflight is closed. The verified structures relevant to the Portal capture contract are:

- `tb_usuarios`: InnoDB; signed `INT(11)` primary key `id`, auto-increment; `estatus INT(11) NOT NULL`.
- `op_rh_personal`: InnoDB; signed `INT(11)` primary key `id`, auto-increment; `estado INT(11) NOT NULL` (`1` is active; `0` is not active).
- `op_token_telegram`: InnoDB; signed `INT(11)` primary key `id`, auto-increment; `id_usuario INT(11) NOT NULL`; `chat_id BIGINT(20) NOT NULL`.

No table is modified or authorized for modification by this addendum.

## 12. Telegram association replacement and physical constraint

A currently verified Telegram association remains active while a replacement association is pending. Replacement follows this sequence:

1. retain the current verified destination;
2. verify the new destination;
3. atomically promote the new destination;
4. retire the old destination;
5. preserve the audit trail.

The current physical `op_token_telegram` schema does **not** enforce unique active association by user or chat: it has no `UNIQUE` constraint on `id_usuario` or `chat_id`, and no declared foreign key to `tb_usuarios`. Enforcement hardening remains future implementation work; this freeze does not alter the schema.

## 13. Non-claims and implementation gate

A successful Portal punch receipt means only:

```text
PUNCH RECORDED
```

It must not claim attendance, journey, or day completeness. Freezing this addendum authorizes only the architecture/contract semantics recorded here.

```text
GENERAL_PORTAL3_READ_BOUNDARY: NOT_YET_AUTHORIZED
IMPLEMENTATION_AUTHORIZED: NO
DATABASE_CHANGES_AUTHORIZED: NO
MIGRATIONS_AUTHORIZED: NO
```
