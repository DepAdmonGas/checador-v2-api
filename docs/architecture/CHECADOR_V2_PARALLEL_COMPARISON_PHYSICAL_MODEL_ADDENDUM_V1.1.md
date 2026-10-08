# CHECADOR V2 — LEGACY & PAYROLL PARALLEL COMPARISON PHYSICAL MODEL ADDENDUM V1.1

**Status:** FROZEN_V1.1
**Version:** 1.1
**Date:** 2026-10-07
**Module:** Checador V2 API — Parallel Comparison Engine (`V2.6-SA`)
**Canonical Milestone:** `V2.6-SA — Legacy & Payroll Parallel Comparison Physical Model Addendum V1.1`
**Canonical Business Terminology:** `comparación paralela sin afectar nómina`
**Contract Reference:** `docs/v2/CHECADOR_V2_LEGACY_PAYROLL_PARALLEL_COMPARISON_ADAPTER_CONTRACT_V1.0.md` (SHA256: `d21a59d79de7fbcca12fe1c43b1b398f9e3bbae9b54e8ef972f0fceacf50bacb`)
**Base Physical Model Reference:** `docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md` (SHA256: `cd4e1aee46d9a0f5aa8c5bd175b8ff41aae731e08ed77de144d21e4b013b763f`)
**Implementation Authorized:** NO (Physical Model Remediation Design & Freeze Only)

---

## 1. FORENSIC FINDING & PURPOSE OF ADDENDUM V1.1

A read-only forensic verification (`V2.6-SC`) of `CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md` established:

- **`LEGACY_ATTENDANCE_SNAPSHOT_HASH_PRESENT`:** `YES` (`snapshot_hash` column exists in table `op_rh_v2_comparaciones_legacy_capturas`).
- **`COMPARISON_FINGERPRINT_INCLUDES_ATTENDANCE_SNAPSHOT_IDENTITY`:** `NO` (`fingerprint_ejecucion` formula in Section 4 and Section 8 relied on `$legacyAttendanceIdOrMissing` raw integer/sentinel rather than captured attendance snapshot hash identity).
- **`SAME_ID_MUTATED_VALUES_CAUSE_COLLISION`:** `YES` (If the same legacy source record ID is updated in the legacy system from values $A$ to values $B$, comparing the same V2.5 evaluation against mutated legacy values $B$ generates an identical fingerprint, causing a `UNIQUE KEY` collision on `uk_v2_comp_fingerprint` and wrongfully collapsing the execution as an idempotent rerun instead of persisting a new comparison revision).
- **`HISTORICAL_REPRODUCIBILITY_VERDICT`:** `SNAPSHOT_CAPTURED_BUT_NOT_IN_IDEMPOTENCY_KEY`.

### Scope of Supersession
This Addendum V1.1 supersedes **ONLY** the calculation formula, deterministic serialization, and missing-record canonicalization of `fingerprint_ejecucion` in `op_rh_v2_comparaciones_encabezado`. 

All four physical tables, field definitions, foreign keys, database triggers, discrepancy cardinality, rollback scripts, and non-monetary boundaries defined in `CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md` remain **100% UNCHANGED and FROZEN**.

---

## 2. DEFECT STATEMENT & SCENARIO PROOF

### The V1.0 Defect:
In V1.0, `fingerprint_ejecucion` was conceptualized as:
```php
hash('sha256', "{$idEvaluacionAdmin}_{$legacyAttendanceIdOrMissing}_{$sortedLegacyIncidenceSnapshotHashes}_{$algorithmVersion}_{$policyVersion}")
```
Where `$legacyAttendanceIdOrMissing` was the legacy record primary key `id_personal_asistencia` (e.g. `123`) or `'MISSING_RECORD'`.

### Concrete Failure Scenario:
1. **At Time $T_1$:** Legacy row `id = 123` contains attendance values $A$ (`hora_entrada_sensor = '08:00:00'`). Evaluation $E_1$ is compared. Captured snapshot hash is `hash(A)`. Fingerprint generated: `hash("E1_123_IncHashes_V1.0_V1.0")`. Comparison record $C_1$ (Revision 1) is created.
2. **At Time $T_2$:** Legacy administrative user updates row `123` to values $B$ (`hora_entrada_sensor = '08:15:00'`).
3. **At Time $T_3$:** Comparison engine re-evaluates $E_1$. The underlying legacy values are now $B$, yielding captured snapshot hash `hash(B)`.
4. **V1.0 Failure:** Under V1.0 formula, the fingerprint input is still `"E1_123_IncHashes_V1.0_V1.0"`. Fingerprint $C_2$ equals Fingerprint $C_1$. Insertion fails on `uk_v2_comp_fingerprint`, preventing historical revision persistence for mutated legacy attendance data.

---

## 3. AUTHORITATIVE SNAPSHOT IDENTITY

The exact existing legacy attendance snapshot hash column in Table `op_rh_v2_comparaciones_legacy_capturas` is:

```text
LEGACY_ATTENDANCE_SNAPSHOT_HASH_COLUMN: snapshot_hash
```

### Canonical Scalar Input Values for Attendance `snapshot_hash`:
When a legacy attendance row (`op_rh_personal_asistencia`) is captured, `snapshot_hash` is computed over the pipe-delimited canonical string of its observed scalar values:

```php
$legacyAttendanceSnapshotHash = hash('sha256', implode('|', [
    $idPersonalAsistenciaLegacy ?? 'NULL',
    $idEstacionLegacy ?? 'NULL',
    $idPersonalLegacy ?? 'NULL',
    $fechaLegacy ?? 'NULL',
    $horaEntradaLegacy ?? 'NULL',
    $horaSalidaLegacy ?? 'NULL',
    $horaEntradaSensorLegacy ?? 'NULL',
    $horaSalidaSensorLegacy ?? 'NULL',
    $retardoMinutosLegacy ?? 'NULL',
    $incidenciaDiasLegacy ?? 'NULL',
    $incidenciaLegacy ?? 'NULL',
    $sdLegacy ?? 'NULL'
]));
```

---

## 4. CORRECTED DETERMINISTIC FINGERPRINT V1.1

`fingerprint_ejecucion` in `op_rh_v2_comparaciones_encabezado` is computed using the exact captured `snapshot_hash` of the legacy attendance record instead of its bare primary key integer.

### Canonical Conceptual Formula:
```php
fingerprint_ejecucion = hash(
    'sha256',
    idEvaluacionAdmin
    + ':' + legacyPresenceState
    + ':' + legacyAttendanceSnapshotHash
    + ':' + sortedLegacyIncidenceSnapshotHashes
    + ':' + algorithmVersion
    + ':' + policyVersion
)
```

### Exact Serialization Specification:
- **Delimiter:** Colon (`:`)
- **Field 1 (`idEvaluacionAdmin`):** Unsigned integer primary key of `op_rh_v2_evaluaciones_administrativas` formatted as string (e.g. `"15"`).
- **Field 2 (`legacyPresenceState`):** Uppercase string enum stored in `op_rh_v2_comparaciones_encabezado.estado_presencia_legacy` (`"OBSERVED"`, `"MISSING_RECORD"`, `"DATA_INCOMPLETE"`, or `"AMBIGUOUS"`).
- **Field 3 (`legacyAttendanceSnapshotHash`):** 64-character lowercase hex SHA256 string stored in `op_rh_v2_comparaciones_legacy_capturas.snapshot_hash`.
- **Field 4 (`sortedLegacyIncidenceSnapshotHashes`):** Pipe-delimited string of 64-character lowercase hex SHA256 hashes (`row_hash`) from `op_rh_v2_comparaciones_legacy_incidencias`, sorted alphabetically ascending. If 0 legacy incidence rows exist (`[]`), this field MUST be the literal string `"NO_INCIDENCES"`.
- **Field 5 (`algorithmVersion`):** Version string stored in `op_rh_v2_comparaciones_encabezado.version_algoritmo_comparacion` (e.g. `"V1.0"`).
- **Field 6 (`policyVersion`):** Version string stored in `op_rh_v2_comparaciones_encabezado.version_politica_comparacion` (e.g. `"V1.0"`).

### Complete Code Expression:
```php
$incidenceHashString = empty($sortedRowHashes) 
    ? 'NO_INCIDENCES' 
    : implode('|', $sortedRowHashes); // $sortedRowHashes is sorted with sort($sortedRowHashes, SORT_STRING)

$fingerprintInput = implode(':', [
    (string)$idEvaluacionAdmin,
    (string)$legacyPresenceState,
    (string)$legacyAttendanceSnapshotHash,
    $incidenceHashString,
    (string)$algorithmVersion,
    (string)$policyVersion
]);

$fingerprintEjecucion = hash('sha256', $fingerprintInput);
```

---

## 5. MISSING LEGACY RECORD CANONICALIZATION

For comparisons where no legacy attendance record exists (`estado_presencia_legacy = 'MISSING_RECORD'`):

1. **Child Capture Table (`op_rh_v2_comparaciones_legacy_capturas`):**
   - Scalar legacy columns remain `NULL`.
   - `snapshot_hash` MUST be set to the deterministic missing sentinel hash:
     ```php
     $legacyAttendanceSnapshotHash = hash('sha256', 'MISSING_LEGACY_ATTENDANCE_RECORD');
     // Result: "4a0889f81a7042a9bca5df36f2e82b7b51b2e105e468352b207558a36d9d4d12"
     ```
2. **Fingerprint Input:**
   - `legacyPresenceState` = `"MISSING_RECORD"`
   - `legacyAttendanceSnapshotHash` = `"4a0889f81a7042a9bca5df36f2e82b7b51b2e105e468352b207558a36d9d4d12"`
3. **Distinctness:** A missing record sentinel produces a completely different hash than an observed record containing empty or default fields, preventing collision between absence of data and presence of zeroed data.

---

## 6. LEGACY MUTATION PROOF & INVARIANTS

The following invariants are mathematically guaranteed under V1.1:

```text
SAME_ID_MUTATED_VALUES_PRODUCE_NEW_FINGERPRINT: YES
```

- **Proof:**
  - $T_1$: Legacy ID `123` with values $A$ yields `snapshot_hash = hash(A)`. Fingerprint $C_1 = \text{hash}(\text{E1:OBSERVED:hash(A):...})$.
  - $T_2$: Legacy ID `123` with updated values $B$ yields `snapshot_hash = hash(B)`.
  - Since $A \neq B$, $\text{hash}(A) \neq \text{hash}(B)$.
  - Therefore, Fingerprint $C_2 = \text{hash}(\text{E1:OBSERVED:hash(B):...}) \neq C_1$.
  - Result: `uk_v2_comp_fingerprint` does NOT trigger a collision. Comparison $C_2$ is cleanly inserted as Revision 2.

---

## 7. FINGERPRINT VS AUTHORITATIVE PROVENANCE

```text
FINGERPRINT_IS_PROVENANCE_SOURCE: NO
```

`fingerprint_ejecucion` is strictly an idempotency control and indexable execution hash.

Authoritative historical evidence remains stored in full within:
1. `op_rh_v2_comparaciones_legacy_capturas` (Exact snapshot of legacy attendance fields).
2. `op_rh_v2_comparaciones_legacy_incidencias` (Exact snapshot set of legacy incidences).
3. `op_rh_v2_evaluaciones_administrativas` (FK to immutable V2.5 administrative evaluation).

`fingerprint_ejecucion` MUST NEVER substitute for stored snapshot evidence.

---

## 8. PHYSICAL PERSISTENCE & SCHEMA IMPACT

```text
TABLE_SET_CHANGED: NO
NEW_COLUMN_REQUIRED: NO
NEW_TABLE_REQUIRED: NO
PROPOSED_DDL_STRUCTURE_CHANGED: NO
```

- All required columns (`fingerprint_ejecucion` in `op_rh_v2_comparaciones_encabezado` and `snapshot_hash` in `op_rh_v2_comparaciones_legacy_capturas`) already exist in the frozen V1.0 DDL specification.
- The physical DDL structure defined in V1.0 remains **100% VALID** for migration `000005`.
- V1.1 changes only the runtime fingerprint computation logic.

---

## 9. IDEMPOTENCY & BEHAVIOR MATRIX

| Scenario | Inputs | Outcome |
| :--- | :--- | :--- |
| **Identical Rerun** | Same V2.5 evaluation + same legacy attendance values + same legacy incidences + same code versions | Identical `fingerprint_ejecucion`. Return existing header (idempotent 200). |
| **Mutated Attendance Values** | Same V2.5 evaluation + same legacy row ID + **changed** attendance values | `snapshot_hash` changes $\rightarrow$ different fingerprint $\rightarrow$ **New Revision (Revision 2)** inserted. |
| **Mutated Incidences Set** | Same V2.5 evaluation + same legacy attendance values + **changed** incidence set | Incidence hash string changes $\rightarrow$ different fingerprint $\rightarrow$ **New Revision** inserted. |
| **Algorithm Version Upgrade** | Same inputs + algorithm version upgraded from `"V1.0"` to `"V1.1"` | Algorithm version changes $\rightarrow$ different fingerprint $\rightarrow$ **New Revision** inserted. |
| **Policy Version Upgrade** | Same inputs + policy version upgraded from `"V1.0"` to `"V1.1"` | Policy version changes $\rightarrow$ different fingerprint $\rightarrow$ **New Revision** inserted. |
| **Evaluation Revision Change** | New V2.5 administrative evaluation revision ($E_2$) | `idEvaluacionAdmin` changes $\rightarrow$ different fingerprint $\rightarrow$ **New Revision** inserted. |

---

## 10. FUTURE V2.6-M TEST ADDITIONS (TEST MATRIX F01-F15)

When migration `000005` and schema runtime tests are executed, the test suite `tests/v2_parallel_comparison_schema_tests.php` MUST validate the following 15 fingerprint invariants:

- **F01:** Same legacy ID + same snapshot hash produces identical fingerprint inputs string.
- **F02:** Same legacy ID + changed attendance scalar values produces different `snapshot_hash`.
- **F03:** Changed `snapshot_hash` produces different `fingerprint_ejecucion`.
- **F04:** Insertion of comparison $C_2$ for mutated legacy values does not collide with $C_1$ on `uk_v2_comp_fingerprint`.
- **F05:** Identical comparison rerun $C_1$ produces exact matching `fingerprint_ejecucion` for idempotency check.
- **F06:** Missing legacy record sentinel `hash('sha256', 'MISSING_LEGACY_ATTENDANCE_RECORD')` is deterministic and predictable.
- **F07:** Missing record sentinel produces distinct fingerprint from an observed record with zero/blank fields.
- **F08:** Changed legacy incidence set produces different `fingerprint_ejecucion`.
- **F09:** Legacy incidence set `row_hash` array sorting is strictly alphabetical ascending.
- **F10:** Algorithm version change (`"V1.0"` $\rightarrow$ `"V1.1"`) produces different `fingerprint_ejecucion`.
- **F11:** Policy version change (`"V1.0"` $\rightarrow$ `"V1.1"`) produces different `fingerprint_ejecucion`.
- **F12:** V2.5 administrative evaluation ID change produces different `fingerprint_ejecucion`.
- **F13:** Discrepancy details log does not affect `fingerprint_ejecucion`.
- **F14:** Table set (`op_rh_v2_comparaciones_encabezado`, `op_rh_v2_comparaciones_legacy_capturas`, `op_rh_v2_comparaciones_legacy_incidencias`, `op_rh_v2_comparaciones_discrepancias`) is unchanged from V1.0.
- **F15:** Zero monetary/payroll columns are present across all tables.

---

## 11. SUMMARY OF ADDENDUM DECISIONS

```text
ADDENDUM_FILE: docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_ADDENDUM_V1.1.md
ADDENDUM_STATUS: FROZEN_V1.1
V1_0_PHYSICAL_MODEL_UNCHANGED: YES (V1.0 authoritative except for fingerprint formula superseded by V1.1)
V2_6_CONTRACT_UNCHANGED: YES (Contract SHA256 d21a59d79de7fbcca12fe1c43b1b398f9e3bbae9b54e8ef972f0fceacf50bacb un-mutated)
LEGACY_ATTENDANCE_SNAPSHOT_HASH_COLUMN: snapshot_hash
FINGERPRINT_V1_1_FORMULA: hash('sha256', "{$idEvaluacionAdmin}:{$legacyPresenceState}:{$legacyAttendanceSnapshotHash}:{$sortedLegacyIncidenceSnapshotHashes}:{$algorithmVersion}:{$policyVersion}")
MISSING_RECORD_CANONICALIZATION: legacyPresenceState = 'MISSING_RECORD', legacyAttendanceSnapshotHash = hash('sha256', 'MISSING_LEGACY_ATTENDANCE_RECORD')
SAME_ID_MUTATED_VALUES_PRODUCE_NEW_FINGERPRINT: YES
IDENTICAL_RERUN_REMAINS_IDEMPOTENT: YES
LEGACY_INCIDENCE_SET_AFFECTS_FINGERPRINT: YES
ALGORITHM_VERSION_AFFECTS_FINGERPRINT: YES
POLICY_VERSION_AFFECTS_FINGERPRINT: YES
FINGERPRINT_IS_PROVENANCE_SOURCE: NO
TABLE_SET_CHANGED: NO
NEW_COLUMN_REQUIRED: NO
NEW_TABLE_REQUIRED: NO
PROPOSED_DDL_STRUCTURE_CHANGED: NO
V2_6_M_READY: YES
NEXT_CANONICAL_MILESTONE: V2.6-M — Legacy & Payroll Parallel Comparison Schema Implementation
```
