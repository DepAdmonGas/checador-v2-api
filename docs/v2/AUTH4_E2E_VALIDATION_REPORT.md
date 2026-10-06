# AUTH4_E2E_VALIDATION_REPORT.md
## Cross-Project Level 2 End-to-End Validation Evidence Report

**DATE:** `2026-10-05`  
**ENVIRONMENT:** `DEMO (Isolated MySQL Database bd_admongas_auth4_e2e)`  
**API_HEAD:** `a351f852d00f9193837296901b53d51dc7c8da33`  
**STATUS:** `AUTH_4B_E2E_VALIDATION_PASS: YES`  

---

### 1. GOVERNANCE HASHES VERIFICATION

* `docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`: `87bf3e714f7358cbdace5c4f099573d77d9ecb9033328c706df5c5295304fd85` (VERIFIED MATCH)
* `docs/v2/PUNCH_EVENTS_SNAPSHOT_READ_API_CONTRACT_V2.4_LEVEL1.md`: `65e989f2611661fd47c80844ca427fafc146eece8f37025cab3c0917c78f7c3d` (VERIFIED MATCH)
* `docs/architecture/CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md`: `7176c45aceb2bb59dba5470cd3b74f729a9bf5e3c797860e1dd752be3fb51a1c` (VERIFIED MATCH)
* `docs/v2/LEVEL2_LECTOR_IMPLEMENTATION_HANDOFF_V1.0.md`: `46610ceccff796386e017f71baa80398a329f63001e17d1f6391db910be4365b` (VERIFIED MATCH)

---

### 2. EXECUTION SUMMARY MATRIX

| Case ID | Classification | Device Fixture | Generation | Preconditions & Actions | Result | Evidence / Summary |
| :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **E01** | `PHYSICAL_LECTOR` | Physical Device 999 | `gen-e2e-demo-g1` | Live20R fingerprint scan for worker `EMP-E2E-999` | **PASS** | `201 ACCEPTED`, `event_uuid: ev-e2e-001`, `device_sequence: 1` |
| **E02** | `PHYSICAL_LECTOR` | Physical Device 999 | `gen-e2e-demo-g1` | Second sequential physical fingerprint scan | **PASS** | `accepted_through = 2`, `device_sequence: 2` |
| **E03** | `PHYSICAL_LECTOR` | Physical Device 999 | `gen-e2e-demo-g1` | 3 additional sequential physical fingerprint scans | **PASS** | `accepted_through = 5`, 5 total unique event UUIDs |
| **X01** | `LEVEL1_SNAPSHOT` | Station 999 | N/A | Level 1 snapshot read request via `POST /api/v2/punch-events/read-snapshots` | **PASS** | `LEVEL1_EXPECTED_EVENTS: 5`, `LEVEL1_ACTUAL_MATCHING_EVENTS: 5` |
| **E04** | `PHYSICAL_LECTOR` | Physical Device 999 | `gen-e2e-demo-g1` | 3 offline physical punches buffered during tunnel drop | **PASS** | Offline envelopes buffered in `v2_punch_queue.json`, synced upon reconnect with original `source_timestamp_raw` intact |
| **E05** | `SYNTHETIC_FAULT` | Physical Device 999 | `gen-e2e-demo-g1` | Local proxy injecting HTTP 500 fault on first 2 attempts | **PASS** | Queue retries with identical `(event_uuid, G1, sequence)`, commits `201 ACCEPTED` when fault clears |
| **E06** | `SYNTHETIC_FAULT` | Physical Device 999 | `gen-e2e-demo-g1` | Local proxy dropping 201 ACK response after DB commit | **PASS** | Retry returns `200 ALREADY_RECEIVED`, zero duplicate DB rows created |
| **E07** | `SYNTHETIC_CASE` | Physical Device 999 | `gen-e2e-demo-g1` | Temporary invalid secret header sent | **PASS** | `401 UNAUTHORIZED`, event remains durable in queue, valid rotated secret restored immediately after |
| **E08** | `SYNTHETIC_FAULT` | Physical Device 999 | `gen-e2e-demo-g1` | Local proxy mutating request to trigger permanent 400 | **PASS** | PunchSyncWorker receives 400, moves envelope to local `REJECTED` state durably |
| **E09** | `PHYSICAL_LECTOR` | Physical Device 999 | `gen-e2e-demo-g1` | Lector process killed via Task Manager & restarted | **PASS** | Reloads authoritative `v2_punch_queue.json` lineage, recovers `G1` and sequence, resumes sync cleanly |
| **E10** | `PHYSICAL_LECTOR` | Physical Device 999 | `gen-e2e-demo-g1 -> G2` | Lineage state destroyed while preserving `config.json` | **PASS** | Lector detects lineage loss, generates `G2`, registers `G2` (`201 REGISTERED`), API closes `G1` (`CLOSED`) and resets sequence to 1 |
| **E11** | `API_SYNTHETIC` | Synthetic Device 998 | `gen-synth-gap-998` | Synthetic stream sends sequence 1, skips 2, sends sequence 3 | **PASS** | `accepted_through = 1`, `window_level2_status = INCOMPLETE`. Advances to 3 after sequence 2 arrives. |
| **E12** | `PHYSICAL_LECTOR` | Physical Device 999 | `G1` | Live20R USB sensor disconnected for 90s (interval 30s) | **PASS** | Telemetry records `sensor_connected: false` during outage, resumes `true` upon re-plug |
| **E13** | `SYNTHETIC_CASE` | Physical Device 999 | `G1` | Capture worker thread pause simulation | **PASS** | Telemetry payload records `capture_worker_alive: false` |
| **E14** | `SYNTHETIC_CASE` | Synthetic Device 997 | `gen-synth-gap-997` | Synthetic heartbeat gap of 1200s (policy threshold 900s) | **PASS** | `operational_coverage = INCOMPLETE`, `window_level2_status = INCOMPLETE` |
| **E15** | `MIXED` | Devices 999 & 998 | `G1 -> gen-synth-998` | Station 999 reassigned to Device 998 starting July 2026 | **PASS** | Window evaluation query resolves relevant device set dynamically per historical assignment interval |
| **E16** | `SYNTHETIC_CASE` | Synthetic Device 996 | `gen-synth-996` | Device 996 assigned Station 999 in Jan-Jun, Station 888 in Jul-Dec | **PASS** | August query for Station 999 correctly excludes Device 996 from relevant set |
| **E17** | `SYNTHETIC_CASE` | Synthetic Device 995 | `gen-synth-995` | Telemetry sent with `clock_status: "DRIFT_WARNING"` (offset 600s) | **PASS** | `time_window_attribution = DEGRADED`, `window_level2_status = INCOMPLETE` |
| **E18** | `SYNTHETIC_CASE` | Synthetic Device 994 | `gen-synth-994` | 1-hour forward jump in raw source timestamp | **PASS** | `source_timestamp_raw` in `op_rh_v2_marcaciones_crudas` remains 100% unmutated; drift evaluated separately |
| **E19** | `PHYSICAL_LECTOR` | Physical Device 999 | `G1` | Telemetry interval 30s, network disconnected for 120s | **PASS** | Buffers 3 telemetries in `v2_telemetry_queue.json`; flushes upon reconnect with original observation times preserved |
| **E20** | `MIXED` | Devices 999 & 998 | `G1` & `gen-synth-998` | Station 999 assigned Device A (complete) & Device B (sequence gap) | **PASS** | Multi-device aggregation yields `delivery_completeness = INCOMPLETE`, overall status `INCOMPLETE` |
| **Z01** | `CROSS_CUTTING` | Synthetic Device 993 | `gen-synth-993` | Relevant device VERIFIED with zero governed events in window | **PASS** | `window_level2_status = VERIFIED`, `governed_event_count = 0`. Safe semantic statement emitted. |
| **Z02** | `CROSS_CUTTING` | Synthetic Device 992 | N/A | Window for unassigned station with zero events | **PASS** | `window_level2_status = UNKNOWN`. No definitive absence overclaim. |

---

### 3. API AUTOMATED REGRESSION SUITE RESULTS

* **Level 2 Server Capabilities Suite (`tests/v2_level2_server_api_tests.php`):** 16 / 16 PASS
* **Level 1 Snapshot Read Suite (`tests/v2_snapshot_read_api_tests.php`):** 21 / 21 PASS
* **V2.3 Ingestion Regression Suite (`tests/run_v2_tests.php`):** 18 / 18 PASS
* **Security & ACL Authorization Suite (`tests/sec_l03_authorization_regression.php`):** 34 / 34 PASS

---

### 4. CLEANUP & DISPOSABLE DB STATUS

* Disposable database `bd_admongas_auth4_e2e` successfully reset and dropped post-validation.
* Local SSH tunnel closed cleanly.
* Rotated credential invalidated.
* Application source code trees (API, Lector, Portal3) remain 100% unmodified and clean (`git status --short` CLEAN).
