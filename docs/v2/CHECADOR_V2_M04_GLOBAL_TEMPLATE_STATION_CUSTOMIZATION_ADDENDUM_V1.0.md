# Checador V2 — Global Template Station Customization Addendum V1.0

**Status:** APPROVED FOR CROSS-PROJECT IMPLEMENTATION  
**Applies to:** Portal3 and Checador V2 API, local development only  
**Supplements:** Shift Scheduling Service & API Contract V1.0 and M03/M04 Local Templates and Habitual/Temporary Schedule Addendum V1.0  
**Does not revise:** Architecture Freeze V1.0, J1/J2, production configuration, Lector contracts, global template data, assignments, or original PunchEvents  
**Functional decision:** The responsible user has decided that an Encargado may personalize any global turn visible to the Encargado's authorized station; Dirección de Operaciones remains read-only.  
**Contract approval:** APPROVED — CROSS-PROJECT OWNER  
**Implementation authorized:** YES — LOCAL DEVELOPMENT ONLY

## 1. Purpose and scope

This addendum defines a safe, traceable copy of a visible `GLOBAL` shift template into one authorized `STATION` catalog. It does not authorize editing, versioning, activating, or deactivating the global source. The global source and its segments remain unchanged. A station customization is a new local template with its own identity and immutable versions; existing worker assignments are never replaced or migrated automatically.

Implementation is limited to local `bd_admongas` development after the cross-project approval gate in Section 9 is completed. No production or Lector change is authorized.

## 2. Source identity and lineage

The customization records, as immutable lineage for the local template's entire version history:

- the source global template's logical `clave`;
- the exact global source `version` copied;
- the local derived template's logical identity; and
- the API-resolved canonical station (`id_estacion`) that owns the derived template.

The relation is established and returned by API V2. Portal3 must not infer it from a name, a local key, segment equality, timestamps, or audit text. The client cannot assert the destination station or source scope: API V2 resolves the actor and station from the validated JWT and rechecks that the selected source version is `GLOBAL` and visible to that actor in the authorized catalog.

Lineage identifies the exact source version originally copied. Later edits create new immutable local versions while retaining that original lineage; they do not silently refresh from a changed global template. Re-copying a newer global version is not a second customization and is rejected as a duplicate until an explicitly approved replacement/migration policy exists.

## 3. Uniqueness and duplicate behavior

For each canonical station and each global logical template `clave`, at most one local customization may exist. The unique identity is `(id_estacion, source_global_clave)`; source version is recorded as provenance, not used to allow duplicate copies of the same logical global turn. Creation must be atomic under concurrent requests. A repeated request for an already existing customization returns its current local identity/version through a verified readback and creates no row or version. A request that conflicts with the established source version or local identity fails closed with a conflict response and no mutation.

The API owns generation or allocation of the local logical key. It must be unique within the existing station-template key rules and must not encode a client-chosen alias that could shadow a global key. The exact physical representation (additive lineage table or equivalent normalized storage) and DDL/rollback must be documented in the implementation record before code changes. It must enforce the uniqueness above in MariaDB, retain the source reference across local versions, and preserve all existing keys, versions, IDs, assignments, and global rows.

## 4. Authorized operation and API behavior

The existing station-template creation/versioning API family is reused with an additive, backward-compatible customization input identifying the global source `clave` and exact version. No browser-to-API calls, parallel writer, Legacy writer, technical Lector credential, or global-template write permission is allowed.

For a first customization, API V2 must, in one transaction:

1. validate the JWT, active user, `checador.turnos.estacion.administrar`, active canonical station mapping, and station scope;
2. load and lock the exact global source version, confirm it is a visible `GLOBAL` source, and read its authoritative segments;
3. check the station/source uniqueness key;
4. create the new `STATION` template and its initial local version, using source schedule fields as defaults while accepting only validated editable fields;
5. persist immutable lineage and actor/station audit facts; and
6. commit, then provide the local template, lineage, version, and segments for Portal3 readback.

The source must be revalidated server-side even if the browser sent the same values. Actor and station come from API authorization, never from request actor/scope fields. Missing, ambiguous, inactive, changed, or out-of-scope source/station facts fail closed. Direction remains read-only and receives no template administration grant.

Read/list and exact-version detail responses for local templates expose a stable, read-only `derived_from_global` provenance object (source logical key and copied version), or `null` for independently created local templates. They never label a local template as global. Responses do not expose unrelated station data.

## 5. Portal3 interaction

M04 shows the existing native action menu only when the server confirms the authenticated Encargado can administer the selected station:

- `GLOBAL`: “Personalizar para mi estación”.
- `STATION` belonging to the selected authorized station: “Editar”.
- `STATION` belonging to another station or any row for Dirección: no write action.

The customization modal is prefilled from the actual selected global version's name and segments. It clearly says that it creates a station-local copy and leaves the global source unchanged. On submit Portal3 uses the existing Alpine.js/Axios, CSRF, session, and server-side validation patterns. The UI reports success only after API/database readback; it refreshes the existing DataTables row without F5. If a customization already exists, M04 opens/uses that station-local record rather than making another copy. No assignment is changed.

## 6. Error and recovery semantics

- `401`: session/JWT is absent or expired; no write.
- `403`: missing station-administration permission, invalid station mapping, or station/source outside caller scope; no source-existence disclosure outside scope.
- `404`: exact source is not available in the authorized catalog; no write.
- `409`: a customization already exists or source/version/local identity conflicts; return only the authorized existing station-local reference when applicable, verified through readback.
- `422`: malformed, unsupported, or inconsistent source/default/edited segments; no write.
- `5xx` or network uncertainty: Portal3 performs a scoped lookup/readback before any retry. It must never issue another create request merely because the first response was lost.

Errors and logs must not leak JWTs, credentials, or data from another station. Prior global and local versions remain immutable.

## 7. Required acceptance tests

API isolated and MariaDB development tests must prove:

- copying an exact global key/version creates one local `STATION` template with persisted lineage;
- the global template, segments, version and ID remain unchanged;
- station/source uniqueness under duplicate and concurrent requests, including a lost response and safe readback;
- the lineage remains stable through subsequent local version edits;
- an Encargado can copy only a source visible in the Encargado's server-resolved station catalog;
- foreign-station requests, forged station/source fields, global writes, and ambiguous mappings fail closed;
- Dirección can read but cannot create, customize, or edit;
- another station cannot see the local copy;
- existing assignments continue to reference their original template IDs/versions and remain unchanged;
- API reads, Portal3 DataTables, retry/readback, F5, and browser responsive behavior agree.

Use only isolated QA fixtures and local `bd_admongas`; do not alter existing global templates or institutional assignments. Verify the existing 89 original PunchEvent UUIDs remain unchanged. No Journey evaluation, materialization, or other module write is part of these tests.

## 8. Boundaries

This addendum does not authorize editing global templates, changing global catalog data, replacing assignments, changing ACL outside the existing station-template permission, enabling a global POST gate, modifying M03 habitual/temporary assignment rules, implementing J2 runtime, changing Architecture Freeze/J1/J2, production deployment, or modifying Lector, Nómina, Journey, PunchEvents, or Legacy writers.

## 9. Approval gate

The functional decision in Section 1 is recorded from `CHECADOR_V2_M04_GLOBAL_TEMPLATE_STATION_CUSTOMIZATION_V1`. Cross-project approval and technical implementation authorization were expressly granted by the user as the authorized responsible owner of both Portal3 and API V2 on 2026-11-04.

- **Status:** `CROSS_PROJECT_APPROVED; DEVELOPMENT_IMPLEMENTATION_AUTHORIZED`.
- **Approved source bytes SHA-256:** `96744a64edecbde0a63fa5c55756b44e56d03dabbd00e61f8c89acc443a1846b`.
- **Approval activity:** `CHECADOR_V2_M04_GLOBAL_TEMPLATE_CUSTOMIZATION_APPROVAL_V1`.
- **Implementation scope:** Additive API/schema and Portal3 implementation; migrations and QA writes only in verified local development `bd_admongas`.
- **Reconciled copies:** After approval, this status metadata was synchronized in both repositories. Current copies must remain byte-identical; their current hash is recorded in the project status and Activity Ledger. The approved source SHA above remains the approval identity.
- **Protected scope:** Architecture Freeze V1.0 and J1/J2 remain unchanged; no production changes, global source edits, assignment modifications, Legacy writers, Lector, Journey, or original PunchEvents are authorized.
