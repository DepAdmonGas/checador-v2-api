# Checador V2 API — Master Documentation Index

Welcome to the **Checador V2 API** documentation directory (`portal-api-v.1.0.1`).  
This master index provides the canonical entry point, reading order, governance classifications, and document hierarchy for developers and automated agents working on Checador V2.

---

## 1. Quick Navigation & Frequently Asked Questions

* **What is Checador V2?**  
  Checador V2 is the next-generation attendance and biometric event ingestion platform for AdmonGas. It replaces legacy attendance endpoints with sequence-aware, append-only, tamper-sealed raw punch ingestion and snapshot read capabilities.
* **Which document is the overall architecture source of truth?**  
  [CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md](architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md) (FROZEN).
* **Which document governs Level 1 Snapshot Read?**  
  [PUNCH_EVENTS_SNAPSHOT_READ_API_CONTRACT_V2.4_LEVEL1.md](v2/PUNCH_EVENTS_SNAPSHOT_READ_API_CONTRACT_V2.4_LEVEL1.md) (FROZEN / CANONICAL).
* **Which document governs Level 2 Ingestion & Completeness?**  
  [CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md](architecture/CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md) (FROZEN / CANONICAL).
* **Which document tells the Lector client exactly what the API expects?**  
  [LEVEL2_LECTOR_IMPLEMENTATION_HANDOFF_V1.0.md](v2/LEVEL2_LECTOR_IMPLEMENTATION_HANDOFF_V1.0.md) (IMPLEMENTATION_HANDOFF).
* **Where is the cross-project E2E validation evidence?**  
  [AUTH4_E2E_VALIDATION_REPORT.md](v2/AUTH4_E2E_VALIDATION_REPORT.md) (EVIDENCE / AUTH-4 CLOSED).
* **Which documents are historical or candidate proposals?**  
  Level 2 candidates `V1.0` and `V1.1` under `docs/architecture/`. See Section 4 below.
* **What is the current implementation status?**  
  V2.3 Raw Punch Ingestion, AUTH-1 Level 1 Snapshot Read, AUTH-2 Level 2 Server Capabilities, AUTH-3 Lector Level 2 Client, and AUTH-4 Cross-Project E2E Validation are all **CLOSED**. Production deployment and Portal3 Journey Level 3 are deferred to future phases.

---

## 2. Recommended Reading Order

Developers and coding assistants MUST review documentation in the following sequence:

1. **[CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md](architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md)** — Architectural principles, legacy protection, append-only doctrine, shadow mode.
2. **[CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md](architecture/CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md)** — Level 2 sequence tracking, generation registry, operational telemetry, coverage policy, window evaluation rules.
3. **[PUNCH_EVENTS_SNAPSHOT_READ_API_CONTRACT_V2.4_LEVEL1.md](v2/PUNCH_EVENTS_SNAPSHOT_READ_API_CONTRACT_V2.4_LEVEL1.md)** — Level 1 snapshot read API, cursor pagination, audit manifest, immutable snapshot scope.
4. **[LEVEL2_LECTOR_IMPLEMENTATION_HANDOFF_V1.0.md](v2/LEVEL2_LECTOR_IMPLEMENTATION_HANDOFF_V1.0.md)** — Executable HTTP wire interface for Lector integration (`generations`, `punch-events`, `telemetry`).
5. **[PUNCH_EVENTS_INGESTION_API.md](v2/PUNCH_EVENTS_INGESTION_API.md)** & **[PUNCH_EVENTS_INTEGRITY_ADDENDUM_V1.0.md](v2/PUNCH_EVENTS_INTEGRITY_ADDENDUM_V1.0.md)** — Base punch ingestion payload schemas and cryptographic integrity seals.
6. **[AUTH4_E2E_VALIDATION_REPORT.md](v2/AUTH4_E2E_VALIDATION_REPORT.md)** — Executed cross-project E2E test matrix evidence (E01-E20, X01, Z01, Z02).

---

## 3. Core Implementation & Governance Status

```
[ V2.1 Raw Ingestion ] ──────> [ AUTH-1 Level 1 Snapshot ] ──────> [ AUTH-2 Level 2 Server ]
      (CLOSED)                           (CLOSED)                           (CLOSED)
                                                                               │
[ Portal3 Journey L3 ] <────── [ AUTH-4 Cross-Project E2E ] <────── [ AUTH-3 Lector Client ]
   (NEXT PHASE)                          (CLOSED)                           (CLOSED)
```

* **API V2.3 Raw Punch Ingestion:** CLOSED
* **AUTH-1 (Level 1 Read Snapshot API):** CLOSED
* **AUTH-2 (Level 2 Server Capabilities):** CLOSED
* **AUTH-3 (Lector Level 2 Client Capabilities):** CLOSED
* **AUTH-4 (Cross-Project Level 2 E2E Validation):** CLOSED
* **Production Deployment:** NOT INCLUDED IN CURRENT PHASE
* **Portal3 Journey (Level 3 Integration):** NOT IMPLEMENTED; REQUIRES SEPARATE AUTHORIZATION

---

## 4. Semantic Levels Distinction

* **LEVEL 1 (Snapshot Read):** Exact, immutable database snapshot retrieval (`/api/v2/punch-events/read-snapshots`). Guarantees query point-in-time completeness from API storage.
* **LEVEL 2 (Device Ingestion Completeness):** Source-level delivery completeness (`accepted_through`), operational telemetry heartbeat coverage, and device clock attribution (`/api/v2/level2/window-status`).
* **LEVEL 3 (Administrative Journey & Attendance):** Portal3 administrative interpretation (shift schedule matching, tardiness, absences, payroll calculations). **Level 3 is not part of the API ingestion layer.**

> **CRITICAL DOMAIN INVARIANTS:**
> * `op_rh_v2_marcaciones_crudas` is strictly append-only and immutable. UPDATE and DELETE operations are prohibited by database triggers.
> * A missing punch is NEVER replaced by an invented administrative time record.
> * Level 2 `VERIFIED` window status does NOT prove physical non-interaction or employee absence.
> * Zero governed events under a `VERIFIED` Level 2 window means zero punches captured under verified source contract; it is NOT an administrative `FALTA` or `SIN MARCACIÓN`.

---

## 5. Document Precedence Rules

If ambiguity or contradiction appears between documentation files, the following hierarchy applies:

1. **Frozen Architecture & Contracts** (`docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`, `CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md`, `PUNCH_EVENTS_SNAPSHOT_READ_API_CONTRACT_V2.4_LEVEL1.md`)
2. **Authoritative Wire Handoff** (`docs/v2/LEVEL2_LECTOR_IMPLEMENTATION_HANDOFF_V1.0.md`)
3. **Current API Feature Specifications** (`docs/v2/PUNCH_EVENTS_INGESTION_API.md`, `TEMPORARY_ACCESS_API_CONTRACT.md`)
4. **Audited E2E Evidence Reports** (`docs/v2/AUTH4_E2E_VALIDATION_REPORT.md`)
5. **Historical & Candidate Proposals** (`CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_CANDIDATE_V1.*.md`)

---

## 6. Shared Cross-Project Governance

The following three documents MUST remain **byte-identical** between the `portal-api-v.1.0.1` and `Lector` repositories:

1. `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md` (`87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85`)
2. `docs/architecture/CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md` (`7176c45aceb2bb59dba5470cd3b74f729a9bf5e3c797860e1dd752be3fb51a1c`)
3. `docs/v2/LEVEL2_LECTOR_IMPLEMENTATION_HANDOFF_V1.0.md` (`46610ceccff796386e017f71baa80398a329f63001e17d1f6391db910be4365b`)

For a complete tabular list of all files, paths, and SHA256 checksums, refer to the [Checador V2 Documentation Manifest](manifests/CHECADOR_V2_DOCUMENTATION_MANIFEST.md).
