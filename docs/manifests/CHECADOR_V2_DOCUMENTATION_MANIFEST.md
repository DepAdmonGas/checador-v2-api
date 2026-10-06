# CHECADOR_V2_DOCUMENTATION_MANIFEST.md
## Checador V2 API — Canonical Documentation Manifest

**REPOSITORY:** `portal-api-v.1.0.1`  
**BRANCH:** `feature/api-level2-server`  
**COMMIT:** `a351f852d00f9193837296901b53d51dc7c8da33`  
**DATE:** `2026-10-06`  

---

### 1. Governance & Contract Documents

| Document Title | File Path | Version | Primary Status | Shared with Lector? | SHA256 Checksum | Scope & Purpose |
| :--- | :--- | :---: | :---: | :---: | :--- | :--- |
| **Architecture Freeze V1.0** | `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md` | `1.0` | `FROZEN` | **YES** | `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85` | Overall Checador V2 architecture governance, shadow mode, legacy protection, append-only raw punch doctrine. |
| **Level 2 Ingestion Contract V1.1** | `docs/architecture/CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md` | `1.1` | `FROZEN` / `CANONICAL_CONTRACT` | **YES** | `7176c45aceb2bb59dba5470cd3b74f729a9bf5e3c797860e1dd752be3fb51a1c` | Level 2 sequence tracking, generation registry, operational telemetry, coverage policies, and window evaluation. |
| **Level 1 Read Snapshot API Contract V2.4** | `docs/v2/PUNCH_EVENTS_SNAPSHOT_READ_API_CONTRACT_V2.4_LEVEL1.md` | `2.4` | `FROZEN` / `CANONICAL_CONTRACT` | **NO** | `65e989f2611661fd47c80844ca427fafc146eece8f37025cab3c0917c78f7c3d` | Level 1 exact database snapshot completeness, cursor pagination, audit manifest, immutable snapshot scope. |
| **Level 2 Lector Implementation Handoff V1.0** | `docs/v2/LEVEL2_LECTOR_IMPLEMENTATION_HANDOFF_V1.0.md` | `1.0` | `IMPLEMENTATION_HANDOFF` | **YES** | `46610ceccff796386e017f71baa80398a329f63001e17d1f6391db910be4365b` | Authoritative wire contract and HTTP schemas (`generations`, `punch-events`, `telemetry`) for Lector integration. |
| **AUTH-4 E2E Validation Report** | `docs/v2/AUTH4_E2E_VALIDATION_REPORT.md` | `1.0` | `EVIDENCE` | **NO** | `117a6c3d72d610882c987ad5b030ae1e374714af164d04f5cc7323583f647924` | Executed cross-project E2E test matrix evidence report (E01-E20, X01, Z01, Z02). |

---

### 2. Feature Specification Documents

| Document Title | File Path | Primary Status | Shared with Lector? | Scope & Purpose |
| :--- | :--- | :---: | :---: | :--- |
| **Punch Events Ingestion API** | `docs/v2/PUNCH_EVENTS_INGESTION_API.md` | `CANONICAL_CONTRACT` | **YES** | V2.1 Raw Punch ingestion endpoint request/response JSON schemas. |
| **Punch Events Integrity Addendum V1.0** | `docs/v2/PUNCH_EVENTS_INTEGRITY_ADDENDUM_V1.0.md` | `CANONICAL_CONTRACT` | **YES** | Cryptographic integrity seals and seal verification for raw punch events. |
| **Punch Events Read API Contract V1.0** | `docs/v2/PUNCH_EVENTS_READ_API_CONTRACT_V1.0.md` | `SUPERSEDED` | **NO** | Legacy raw read API contract (superseded for snapshot reads by Level 1 V2.4). |
| **Temporary Access API Contract** | `docs/v2/TEMPORARY_ACCESS_API_CONTRACT.md` | `CANONICAL_CONTRACT` | **YES** | Temporary supervisor PIN validation and temporary access request flow. |
| **Temporary Access Security Addendum V1.0** | `docs/v2/TEMPORARY_ACCESS_SECURITY_ADDENDUM_V1.0.md` | `CANONICAL_CONTRACT` | **NO** | Security model, ACL enforcement, and authorization rules for temporary access. |
| **OpenAPI Specification** | `docs/v2/openapi.yaml` | `REFERENCE` | **YES** | Machine-readable OpenAPI 3.0 specification for API V2. |

---

### 3. Historical & Candidate Documents

| Document Title | File Path | Primary Status | Superseded By | Scope & Purpose |
| :--- | :--- | :---: | :--- | :--- |
| **Level 2 Ingestion Candidate V1.0** | `docs/architecture/CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_CANDIDATE_V1.0.md` | `SUPERSEDED` | `CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md` | Initial candidate proposal for Level 2 contract. Retained for historical audit. |
| **Level 2 Ingestion Candidate V1.1** | `docs/architecture/CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_CANDIDATE_V1.1.md` | `SUPERSEDED` | `CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md` | Candidate proposal V1.1 incorporating clock trust addenda. Retained for historical audit. |

---

### 4. Operational & Planning Documents

| Document Title | File Path | Primary Status | Scope & Purpose |
| :--- | :--- | :---: | :--- |
| **Master Schedule 2026** | `docs/planning/CHECADOR_V2_MASTER_SCHEDULE_2026.md` | `PLANNING` | Project schedule, activities, responsibilities, and calendar gates. |
| **Implementation Status** | `docs/status/CHECADOR_V2_IMPLEMENTATION_STATUS.md` | `STATUS` | Global project phase status (shared between API and Lector). |
| **API Changelog** | `docs/changelog/CHECADOR_V2_API_CHANGELOG.md` | `CHANGELOG` | Chronological record of API software releases, migrations, and test results. |
| **API V2 Overview** | `docs/v2/API_V2_OVERVIEW.md` | `REFERENCE` | High-level summary of V2 API capabilities. |
| **API V2 Authentication** | `docs/v2/API_V2_AUTHENTICATION.md` | `REFERENCE` | Overview of Device Auth (Bearer + Bcrypt) and User Auth (Portal3 JWT). |
| **API V2 Configuration** | `docs/v2/API_V2_CONFIGURATION.md` | `REFERENCE` | Environment variables, database connection config, and JWT secrets. |
| **API V2 Deployment** | `docs/v2/API_V2_DEPLOYMENT.md` | `REFERENCE` | Deployment guidelines for staging and production web servers. |
| **API V2 Reference** | `docs/v2/API_V2_REFERENCE.md` | `REFERENCE` | General API endpoint quick reference. |
| **API V2 Security** | `docs/v2/API_V2_SECURITY.md` | `REFERENCE` | Security design, threat modeling, and input sanitization guidelines. |
| **API V2 Security Checklist** | `docs/v2/API_V2_SECURITY_CHECKLIST.md` | `REFERENCE` | Security verification checklist prior to release. |
| **API V2 Testing** | `docs/v2/API_V2_TESTING.md` | `REFERENCE` | Test suite execution instructions (`tests/*.php`). |
