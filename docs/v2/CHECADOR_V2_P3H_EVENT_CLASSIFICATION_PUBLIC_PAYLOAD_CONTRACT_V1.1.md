# CHECADOR V2 — P3-H Event Classification Public Payload Contract V1.1

**Activity:** `CHECADOR_V2_API_P3H_EVENT_CLASSIFICATION_CONTRACT_FREEZE_V1_1`  
**Status:** `FROZEN_OWNER_APPROVED`  
**Scope:** API additive payload enrichment contract for Checador V2 P3-H (Endpoint Reference Reconciliation Amendment)  
**Approval Date:** 2026-10-14  
**Version:** V1.1  
**Predecessor Contract:** `docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.0.md` (SHA-256: `c710165953f455352cf5675b7581a581be03a632a493e6a0c939276865d6658f`)  

---

## 1. PURPOSE AND STATUS GATE

This contract defines the frozen P3-H additive public payload contract, reconciling the endpoint reference from V1.0 to reflect the authoritative, pre-existing implemented Journey evaluation resource following explicit Project Owner approval on 2026-10-14.

### 1.1 Supersession & Immutability Rules
- **Historical Evidence Preservation:** V1.0 (`docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.0.md`) remains immutable historical evidence and is NOT modified in place.
- **Narrow Supersession Scope:** V1.1 supersedes V1.0 ONLY for the erroneous Journey endpoint reference.
- **Semantic Invariance:** All other V1.0 semantics (`pairing_role`, `segment_ordinal`, allowed values, ambiguity scoping, four-slot projection rules, and architectural invariants) remain 100% identical and unchanged.
- **Runtime Alignment:** Runtime implementation (`POST /api/v2/journeys/evaluate`) was already correct, verified, and required zero route corrections or new endpoints.

In V1.0, the target endpoint was erroneously drafted as `GET /api/v2/journeys/on-demand`. Forensic inspection of:
1. Pre-existing canonical Journey contracts (`docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.0.md` and `V1.1.md`, Section 3.1);
2. Actual API routing (`index.php` line 518);
3. API controller implementation (`JourneyEvaluationV2Controller.php`);
4. Portal3 client backend integration (`ChecadorDailyReadService::evaluateJourney()`);
5. 100% of regression test suites (`v2_journey_boundary_tests.php` and `v2_journey_provenance_boundary_tests.php`);

all prove conclusively that the canonical and implemented Journey evaluation resource is:
`POST /api/v2/journeys/evaluate`.

This V1.1 contract amends ONLY that erroneous endpoint reference. All additive payload definitions (`pairing_role`, `segment_ordinal`), allowed values, ambiguity scoping, and architectural invariants from V1.0 remain 100% identical and unchanged.

```text
STATUS: FROZEN_OWNER_APPROVED
OWNER_APPROVAL_DATE: 2026-10-14
OWNER_APPROVAL_RECORDED: YES
OWNER_DECISION_REQUIRED: NO
CANONICAL_JOURNEY_ENDPOINT: POST /api/v2/journeys/evaluate
API_V1_1_CHANGE_SCOPE: ENDPOINT_REFERENCE_ONLY
RUNTIME_ROUTE_CHANGE_REQUIRED: NO
NEW_API_ENDPOINT_REQUIRED: NO
EXISTING_ENDPOINT_CAN_BE_ENRICHED: YES
NEW_SCHEMA_REQUIRED: NO
NEW_DATABASE_MIGRATION_REQUIRED: NO
RECOMPUTATION_REQUIRED: NO
```

---

## 2. CANONICAL BINDING & HASH VERIFICATION

This contract binds the following frozen and companion contract documents:

| Document | Canonical SHA-256 | Status |
|---|---|---|
| Architecture Freeze (`docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`) | `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85` | FROZEN_OWNER_APPROVED |
| API P3-H Predecessor Contract V1.0 (`docs/v2/CHECADOR_V2_P3H_EVENT_CLASSIFICATION_PUBLIC_PAYLOAD_CONTRACT_V1.0.md`) | `c710165953f455352cf5675b7581a581be03a632a493e6a0c939276865d6658f` | HISTORICAL_FROZEN_EVIDENCE |
| API P3-G Boundary Contract (`docs/v2/CHECADOR_V2_P3G_ADMINISTRATIVE_API_BOUNDARY_CONTRACT_V1.0.md`) | `e418687501fbdfdf89bcc6f11aab60e49226f265411c4590a39a5fa7799c374a` | FROZEN_OWNER_APPROVED |
| Portal3 P3-G Contract (`docs/v2/CHECADOR_V2_PORTAL3_P3G_INCIDENCE_ADMINISTRATIVE_CONTRACT_V1.0.md`) | `e26e888015b27f9f1023e19cf4e005795836a99fd81a98c5638589fde5ca234d` | FROZEN_OWNER_APPROVED |
| Journey On-Demand API Contract V1.0 (`docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.0.md`) | `9eda2b2c7174154d6524f7130ec197678958801df41004925a60928462876c9b` | FROZEN_V1.0 |
| Journey On-Demand API Contract V1.1 (`docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.1.md`) | `7f71372d9deceb6820e6e71e1be38dadd12e87cd5bb4a5f69bcd250ee85c6f3f` | FROZEN_V1.1 |
| Portal3 P3-H Companion Contract (`docs/v2/CHECADOR_V2_PORTAL3_P3H_PRESENTATION_PROJECTION_CONTRACT_V1.0.md`) | `96a700731fe93173b6383effcd648cd4d785ce2178d32a0331cd0b9f2aa9b584` | COMPANION_PRESENTATION_CONTRACT |

---

## 3. DOMAIN & RUNTIME FORENSIC EVIDENCE

Forensic inspection of the canonical Checador V2 database schema and runtime services confirms:

### 3.1 Existing Physical Storage
The physical table `op_rh_v2_pareos_marcaciones_asociadas` (created under migration `2026_10_07_000001_create_v2_journey_pairing_tables.sql`) already records every evaluated punch event for every pairing revision:
- `id_pareo_jornada`: FK to pairing execution header (`op_rh_v2_pareos_jornada`)
- `id_pareo_segmento`: Nullable FK to segment (`op_rh_v2_pareos_segmentos`)
- `id_marcacion_cruda`: FK to immutable punch fact (`op_rh_v2_marcaciones_crudas`)
- `rol_marcacion`: `VARCHAR(32)` representing the authoritative punch role

### 3.2 Authoritative Runtime Classifications
During P1 pairing execution (`JourneyPairingService::allocateSegmentSlots`), the engine deterministically assigns and persists:
1. `ENTRY`: Assigned to the primary entry punch for a scheduled shift segment.
2. `EXIT`: Assigned to the primary exit punch for a scheduled shift segment.
3. `SURPLUS`: Assigned to intermediate candidate punches that occur chronologically between the resolved primary entry and resolved primary exit of a segment.
4. `UNMATCHED`: Assigned to evaluated candidate punches that fall outside all scheduled segments, or all candidate punches when the worker has an unscheduled / day-off shift.

### 3.3 Ambiguity Scope (Segment and Journey, Not Isolated Punch)
In canonical V2 semantics:
- `AMBIGUOUS_PAIRING` is tracked at **SEGMENT** level (`op_rh_v2_pareos_segmentos.estado_segmento = 'AMBIGUOUS_PAIRING'`) and at **JOURNEY** level (`op_rh_v2_pareos_jornada.estado_pareo = 'AMBIGUOUS_PAIRING'`).
- Ambiguity is triggered when punch validation flags contain `INVALID` / `UNVERIFIABLE`, or when conflicting punch sequences cannot be resolved without heuristic assumptions.
- Punches within an ambiguous segment preserve their candidate physical role (`ENTRY`, `EXIT`, `SURPLUS`), while the segment's `status` indicates `AMBIGUOUS_PAIRING`.
- Ambiguity is therefore **scoped to the segment or journey**, and is NOT flattened into an artificial punch role. `AMBIGUOUS_PAIRING` is strictly prohibited as a `pairing_role` value.

---

## 4. PUBLIC CONTRACT ENRICHMENT SPECIFICATION

### 4.1 Target Endpoint
`POST /api/v2/journeys/evaluate`

*(Reconciled from erroneous `GET /api/v2/journeys/on-demand` in V1.0 to reflect the pre-existing authoritative Journey Evaluation boundary defined in `docs/v2/CHECADOR_V2_JOURNEY_ON_DEMAND_API_CONTRACT_V1.0.md` and `V1.1.md`).*

### 4.2 Enriched Location
Inside each element of `data.evaluated_raw_events[]` and mirrored in `data.provenance.evaluated_raw_events[]`.

### 4.3 Additive Public Fields

| Field Name | Type | Nullability | Allowed Values | Source Authority | Semantic Description |
|---|---|---|---|---|---|
| `pairing_role` | `string` | `nullable` | `"ENTRY"`, `"EXIT"`, `"SURPLUS"`, `"UNMATCHED"` | `op_rh_v2_pareos_marcaciones_asociadas.rol_marcacion` | Authoritative role assigned to the punch during P1 pairing. `null` only when Journey has no pairing (e.g. `UNSCHEDULED_DAY` with unevaluated pairing). |
| `segment_ordinal` | `integer` | `nullable` | `1`, `2`, `3`, ... | `op_rh_v2_pareos_segmentos.ordinal_segmento` | 1-based ordinal of the scheduled shift segment with which this punch is associated. `null` when `pairing_role` is `"UNMATCHED"` or pairing is unavailable. |

### 4.4 Backward Compatibility
- Existing consumers inspecting `event_uuid`, `id_dispositivo`, `ocurrido_at_utc`, and `metodo_captura` remain 100% unaffected.
- No existing field names, types, or values are modified.
- No existing field is removed.
- No routes, authentication, or request parameters change.
- The addition is purely additive.

---

## 5. PAYLOAD COMPARISON (BEFORE vs AFTER)

### Current Payload (V1.1)
```json
{
  "status": "success",
  "data": {
    "evaluated_raw_event_count": 3,
    "evaluated_raw_events": [
      {
        "event_uuid": "ev-punch-001",
        "id_dispositivo": 1,
        "ocurrido_at_utc": "2026-10-12 14:02:00",
        "metodo_captura": "BIOMETRIC"
      },
      {
        "event_uuid": "ev-punch-002",
        "id_dispositivo": 1,
        "ocurrido_at_utc": "2026-10-12 18:30:00",
        "metodo_captura": "BIOMETRIC"
      },
      {
        "event_uuid": "ev-punch-003",
        "id_dispositivo": 1,
        "ocurrido_at_utc": "2026-10-12 22:01:00",
        "metodo_captura": "BIOMETRIC"
      }
    ]
  }
}
```

### Proposed Enriched Payload (V1.2 Additive)
```json
{
  "status": "success",
  "data": {
    "evaluated_raw_event_count": 3,
    "evaluated_raw_events": [
      {
        "event_uuid": "ev-punch-001",
        "id_dispositivo": 1,
        "ocurrido_at_utc": "2026-10-12 14:02:00",
        "metodo_captura": "BIOMETRIC",
        "pairing_role": "ENTRY",
        "segment_ordinal": 1
      },
      {
        "event_uuid": "ev-punch-002",
        "id_dispositivo": 1,
        "ocurrido_at_utc": "2026-10-12 18:30:00",
        "metodo_captura": "BIOMETRIC",
        "pairing_role": "SURPLUS",
        "segment_ordinal": 1
      },
      {
        "event_uuid": "ev-punch-003",
        "id_dispositivo": 1,
        "ocurrido_at_utc": "2026-10-12 22:01:00",
        "metodo_captura": "BIOMETRIC",
        "pairing_role": "EXIT",
        "segment_ordinal": 1
      }
    ]
  }
}
```

---

## 6. PORTAL3 CONSUMPTION & FOUR-SLOT PROJECTION RULES

Under this contract, Portal3 projects events into the four-slot UI and evidence list without guessing:

1. **Slots 1 and 2 (Segment 1):**
   - `SLOT_1` (Entrada 1): Event where `segment_ordinal == 1` and `pairing_role == "ENTRY"`.
   - `SLOT_2` (Salida 1): Event where `segment_ordinal == 1` and `pairing_role == "EXIT"`.
2. **Slots 3 and 4 (Segment 2):**
   - `SLOT_3` (Entrada 2): Event where `segment_ordinal == 2` and `pairing_role == "ENTRY"`.
   - `SLOT_4` (Salida 2): Event where `segment_ordinal == 2` and `pairing_role == "EXIT"`.
3. **Surplus Events (`SURPLUS`):**
   - Events where `pairing_role == "SURPLUS"` are displayed in the evidence list as "Marcación adicional", linked to `segment_ordinal`.
   - They are never placed into primary entry/exit slots.
4. **Unmatched Events (`UNMATCHED`):**
   - Events where `pairing_role == "UNMATCHED"` (`segment_ordinal == null`) are displayed in the evidence list as "Sin emparejar".
   - They are never placed into primary entry/exit slots.
5. **Ambiguous Segments (`AMBIGUOUS_PAIRING`):**
   - When `data.pairing.segments[s].status == "AMBIGUOUS_PAIRING"`, Portal3 renders that segment with an ambiguous indicator based on the segment status.
   - Individual punches within that segment retain their physical candidate `pairing_role` (`ENTRY`, `EXIT`, `SURPLUS`) and are never mutated to `AMBIGUOUS`.

---

## 7. ARCHITECTURAL INVARIANTS & CONSTRAINTS

1. **No Frontend / Chronological Inference:** Portal3 must not classify an event as `SURPLUS` merely because it is not one of the first four events. Classification is derived strictly from `pairing_role`.
2. **No Raw Internal Database PKs:** Internal IDs (`op_rh_v2_marcaciones_crudas.id`) are not exposed. Public audit identity remains `event_uuid`.
3. **Multi-Segment & Multi-Event Scalability:** The contract supports 1, 2, 3+ segments, 5+ punches, and overnight shifts without restriction. The four-slot UI is a presentation projection, not an API domain constraint. `segment_ordinal` may be 3, 4, 5, etc.
4. **No Administrative Leakage:** `pairing_role` does not encode `FALTA`, `SIN MARCACIÓN`, vacations, permissions, or payroll conclusions. Those belong to P3-G administrative evaluation and payroll adapter layers.
5. **Zero Recomputations:** The enriched fields are fetched directly from existing persisted P1 pairing tables (`op_rh_v2_pareos_marcaciones_asociadas` and `op_rh_v2_pareos_segmentos`). P1 and P2 algorithms are not re-executed during read serialization (`REQUIRES_P1_RECOMPUTATION: NO`, `REQUIRES_P2_RECOMPUTATION: NO`, `REQUIRES_RAW_REQUERY_AS_AUTHORITY: NO`).
6. **Zero Schema Mutations:** All underlying tables, foreign keys, and columns were created in V2.4-P1. No new tables or columns are required (`NEW_SCHEMA_REQUIRED: NO`, `SCHEMA_PREREQUISITE_DISCOVERED: NO`).
7. **Four-Slot UI Presentation-Only Invariants:**
   - Empty slot != `SIN MARCACIÓN`.
   - P3-H automatic FALTA = `NO`.
   - Final `FALTA` remains P3-G administrative authority.
   - Administrative state never rewrites PunchEvent timestamps.

---

## 8. OWNER APPROVAL & CANONICAL DECISIONS RECORDED

On 2026-10-14, the Project Owner explicitly reviewed and approved all canonical decisions for this contract:

1. **Public Additive Field Names Approved:**
   - Field 1: `pairing_role` (string, nullable)
   - Field 2: `segment_ordinal` (integer, nullable)
   - Mirroring: mirrored identically in `data.provenance.evaluated_raw_events[]`.
2. **Canonical Allowed Values for `pairing_role`:**
   - `ENTRY`
   - `EXIT`
   - `SURPLUS`
   - `UNMATCHED`
   - Strict rule: No punch-level `AMBIGUOUS` role is permitted (`AMBIGUITY_AS_PAIRING_ROLE: NO`).
3. **Canonical Semantics for `segment_ordinal`:**
   - `1`, `2`, `3`, ...
   - `null` when the event is unassigned (`UNMATCHED`) or pairing is unavailable.
4. **Ambiguity Authority Scoping Approved:**
   - Ambiguity is an attribute of the `SEGMENT` and/or `JOURNEY` level (`AMBIGUOUS_PAIRING`).
   - Ambiguity is never flattened into an individual punch `pairing_role`.
5. **Portal3 Spanish Presentation Labels Approved:**
   - `ENTRY` $\rightarrow$ "Entrada"
   - `EXIT` $\rightarrow$ "Salida"
   - `SURPLUS` $\rightarrow$ "Marcación adicional"
   - `UNMATCHED` $\rightarrow$ "Sin emparejar"
   - *(Note: These are UI presentation labels only; API runtime canonical values remain uppercase English identifiers).*
6. **Companion Presentation Constraints Approved:**
   - Four-slot UI is a presentation projection only.
   - Slots correspond to authoritative P1 segments ordinal 1 and 2.
   - Segments ordinal $\ge 3$ and 5+ PunchEvents remain fully preserved in canonical evidence.
   - Portal3 must never select the first four raw events as authority.
   - Empty slot does not imply `SIN MARCACIÓN`.
   - Zero automatic `FALTA`.
   - Final `FALTA` requires explicit human authorization under P3-G ACL.
   - Administrative state never rewrites raw PunchEvents.
7. **Endpoint Reference Reconciliation Approved (V1.1 Freeze):**
   - Erroneous V1.0 draft reference `GET /api/v2/journeys/on-demand` corrected to canonical `POST /api/v2/journeys/evaluate`.
   - Predecessor V1.0 remains immutable historical evidence.
   - V1.1 is formally frozen as `FROZEN_OWNER_APPROVED`.
   - Companion Portal3 P3-H contract is authorized to bind this final API V1.1 SHA-256.

