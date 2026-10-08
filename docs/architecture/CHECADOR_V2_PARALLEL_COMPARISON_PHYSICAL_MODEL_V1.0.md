# CHECADOR V2 — LEGACY & PAYROLL PARALLEL COMPARISON PHYSICAL MODEL SPECIFICATION V1.0

**Status:** FROZEN_V1.0
**Version:** 1.0
**Date:** 2026-10-07
**Module:** Checador V2 API — Parallel Comparison Engine (`V2.6-S`)
**Canonical Milestone:** `V2.6-S — Legacy & Payroll Parallel Comparison Physical Model Design`
**Canonical Business Terminology:** `comparación paralela sin afectar nómina`
**Contract Reference:** `docs/v2/CHECADOR_V2_LEGACY_PAYROLL_PARALLEL_COMPARISON_ADAPTER_CONTRACT_V1.0.md` (SHA256: `d21a59d79de7fbcca12fe1c43b1b398f9e3bbae9b54e8ef972f0fceacf50bacb`)
**Implementation Authorized:** NO (Physical Model Design Only)

---

## 1. EXECUTIVE SUMMARY & SOURCE OF TRUTH

This specification defines the physical persistence data model for **Checador V2.6 Legacy & Payroll Parallel Comparison Adapter** under strict compliance with frozen contract `CHECADOR_V2_LEGACY_PAYROLL_PARALLEL_COMPARISON_ADAPTER_CONTRACT_V1.0.md`.

### Core Architectural Invariants:
1. **`comparación paralela sin afectar nómina`:**
   - Zero payroll table writes or monetary wage/salary calculations.
   - Legacy attendance tables (`op_rh_personal_asistencia`, `op_rh_personal_asistencia_incidencia`) remain 100% READ-ONLY reference sources.
   - Upstream V2.5 administrative evaluations (`op_rh_v2_evaluaciones_administrativas`), P2 calculations (`op_rh_v2_calculos_jornada`), P1 pairing (`op_rh_v2_pareos_jornada`), scheduled shifts (`op_rh_v2_jornadas_programadas`), and raw punches (`op_rh_v2_marcaciones_crudas`) remain 100% un-mutated.
2. **Historical Reproducibility Doctrine:**
   - Legacy rows (`op_rh_personal_asistencia`) are **NOT globally immutable** in the legacy system (they can be updated via `RegistraSalida` or edited by legacy administrative users).
   - Therefore, a live foreign key `id_personal_asistencia` alone is **INSUFFICIENT** to guarantee historical reproducibility.
   - V2.6 captures an **immutable, normalized comparison-time snapshot** of all consumed legacy fields (`op_rh_v2_comparaciones_legacy_capturas`) and legacy incidence sets (`op_rh_v2_comparaciones_legacy_incidencias`) at execution time.
   - Historical comparisons ($C_1$) remain 100% provable against captured snapshot values even if legacy rows are subsequently updated ($T_2$) or deleted ($T_3$).

---

## 2. LEGACY SOURCE STRUCTURE ANALYSIS

Inspection of local physical legacy database tables yields the following exact metadata:

### A. Primary Legacy Table: `op_rh_personal_asistencia`
- `id`: `INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY`
- `id_estacion`: `INT(11) NOT NULL` (Legacy workplace ID)
- `id_personal`: `INT(11) NOT NULL` (Worker ID)
- `fecha`: `DATE NOT NULL` (Local work date `YYYY-MM-DD`)
- `hora_entrada`: `TIME NOT NULL` (Scheduled entry time string)
- `hora_salida`: `TIME NOT NULL` (Scheduled exit time string)
- `hora_entrada_sensor`: `TIME NOT NULL` (Sensor/Biometric entry time string)
- `hora_salida_sensor`: `TIME NOT NULL` (Sensor/Biometric exit time string)
- `retardo_minutos`: `INT(11) NOT NULL` (Legacy tardiness duration in minutes)
- `incidencia_dias`: `INT(11) NOT NULL` (Legacy absence/incidence days count)
- `incidencia`: `DOUBLE NOT NULL` (Legacy incidence value)
- `sd`: `DOUBLE NOT NULL` (Legacy salary parameter — captured as raw reference value only, NOT used for payroll calculation)

### B. Secondary Legacy Table: `op_rh_personal_asistencia_incidencia`
- `id`: `INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY`
- `id_asistencia`: `INT(11) NOT NULL` (FK referencing `op_rh_personal_asistencia.id`)
- `fecha`: `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `incidencia`: `TEXT NOT NULL` (Incidence title/type text)
- `comentario`: `TEXT NOT NULL` (Notes/comments)
- `documento`: `TEXT NOT NULL` (Document attachment reference)
- `estado`: `INT(11) NOT NULL` (Approval/review status)

---

## 3. PROPOSED NORMALIZED TABLE SET (4 TABLES)

V2.6 introduces four additive, normalized persistence tables:

1. **`op_rh_v2_comparaciones_encabezado`**:
   - Master comparison revision header representing an append-only comparison execution for a specific V2.5 Administrative Evaluation revision.
2. **`op_rh_v2_comparaciones_legacy_capturas`**:
   - Immutable captured snapshot of legacy attendance record values (`op_rh_personal_asistencia`) observed at comparison execution time.
3. **`op_rh_v2_comparaciones_legacy_incidencias`**:
   - Immutable captured snapshot set of legacy incidence rows (`op_rh_personal_asistencia_incidencia`) observed at comparison execution time (0 rows explicitly represent an empty set `[]`).
4. **`op_rh_v2_comparaciones_discrepancias`**:
   - Normalized discrepancy details logging exact field differences, V2 values, legacy values, and technical discrepancy categories.

---

## 4. DETAILED TABLE SCHEMAS & COLUMN SPECIFICATIONS

### Table 1: `op_rh_v2_comparaciones_encabezado` (Comparison Header)

```sql
CREATE TABLE IF NOT EXISTS op_rh_v2_comparaciones_encabezado (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_evaluacion_administrativa INT(11) UNSIGNED NOT NULL,
    id_personal INT(11) NOT NULL,
    id_estacion INT(11) NOT NULL,
    fecha_jornada_local DATE NOT NULL,
    revision_numero INT(11) UNSIGNED NOT NULL DEFAULT 1,
    categoria_resultado VARCHAR(40) NOT NULL,
    requiere_revision TINYINT(1) NOT NULL DEFAULT 0,
    estado_presencia_legacy VARCHAR(30) NOT NULL DEFAULT 'OBSERVED',
    version_algoritmo_comparacion VARCHAR(20) NOT NULL DEFAULT 'V1.0',
    version_politica_comparacion VARCHAR(20) NOT NULL DEFAULT 'V1.0',
    fingerprint_ejecucion VARCHAR(64) NOT NULL,
    registrado_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_v2_comp_enc_eval_admin
        FOREIGN KEY (id_evaluacion_administrativa)
        REFERENCES op_rh_v2_evaluaciones_administrativas (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,

    CONSTRAINT uk_v2_comp_eval_revision
        UNIQUE KEY (id_evaluacion_administrativa, revision_numero),

    CONSTRAINT uk_v2_comp_fingerprint
        UNIQUE KEY (fingerprint_ejecucion),

    INDEX idx_v2_comp_enc_personal_fecha (id_personal, fecha_jornada_local),
    INDEX idx_v2_comp_enc_categoria (categoria_resultado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Field Design Rationale:
- `id_evaluacion_administrativa`: Explicit FK to immutable V2.5 evaluation revision (`ON DELETE RESTRICT`).
- `revision_numero`: Monotonic comparison revision number for a given V2.5 evaluation.
- `categoria_resultado`: Enum string (`MATCH`, `PUNCH_DIFFERENCE`, `SHIFT_DIFFERENCE`, `INCIDENT_DIFFERENCE`, `OVERTIME_DIFFERENCE`, `LEGACY_LIMITATION`, `REVIEW_REQUIRED`, `MISSING_LEGACY_RECORD`, `NOT_COMPARABLE`).
- `estado_presencia_legacy`: Distinguishes `OBSERVED` (legacy row present), `MISSING_RECORD` (no legacy row existed at execution time), `DATA_INCOMPLETE`, or `AMBIGUOUS`.
- `fingerprint_ejecucion`: SHA256 input fingerprint calculated as:
  `hash('sha256', "{$idEvaluacionAdmin}_{$legacyAttendanceIdOrMissing}_{$sortedLegacyIncidenceSnapshotHashes}_{$algorithmVersion}_{$policyVersion}")`.

---

### Table 2: `op_rh_v2_comparaciones_legacy_capturas` (Legacy Attendance Snapshot)

```sql
CREATE TABLE IF NOT EXISTS op_rh_v2_comparaciones_legacy_capturas (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_comparacion_encabezado INT(11) UNSIGNED NOT NULL,
    id_personal_asistencia_legacy INT(11) NULL,
    id_estacion_legacy INT(11) NULL,
    id_personal_legacy INT(11) NULL,
    fecha_legacy DATE NULL,
    hora_entrada_legacy TIME NULL,
    hora_salida_legacy TIME NULL,
    hora_entrada_sensor_legacy TIME NULL,
    hora_salida_sensor_legacy TIME NULL,
    retardo_minutos_legacy INT(11) NULL,
    incidencia_dias_legacy INT(11) NULL,
    incidencia_legacy DOUBLE NULL,
    sd_legacy DOUBLE NULL,
    snapshot_hash VARCHAR(64) NOT NULL,
    capturado_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_v2_comp_snap_encabezado
        FOREIGN KEY (id_comparacion_encabezado)
        REFERENCES op_rh_v2_comparaciones_encabezado (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,

    CONSTRAINT uk_v2_comp_snap_encabezado
        UNIQUE KEY (id_comparacion_encabezado),

    INDEX idx_v2_comp_snap_legacy_id (id_personal_asistencia_legacy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Field Design Rationale:
- `id_personal_asistencia_legacy`: Supplementary, nullable reference to live legacy row ID (`op_rh_personal_asistencia.id`). No DB FK constraint is placed on legacy table to prevent cascade locks or errors if legacy rows are pruned.
- Captured columns store the exact values observed at comparison time. If no legacy row existed (`MISSING_LEGACY_RECORD`), columns are `NULL` and `snapshot_hash` records `'MISSING_RECORD_SNAPSHOT_HASH'`.

---

### Table 3: `op_rh_v2_comparaciones_legacy_incidencias` (Legacy Incidences Snapshot Set)

```sql
CREATE TABLE IF NOT EXISTS op_rh_v2_comparaciones_legacy_incidencias (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_comparacion_encabezado INT(11) UNSIGNED NOT NULL,
    id_asistencia_incidencia_legacy INT(11) NULL,
    fecha_incidencia_legacy DATETIME NULL,
    incidencia_texto_legacy TEXT NULL,
    comentario_legacy TEXT NULL,
    documento_legacy TEXT NULL,
    estado_legacy INT(11) NULL,
    orden_secuencia INT(11) UNSIGNED NOT NULL DEFAULT 1,
    row_hash VARCHAR(64) NOT NULL,

    CONSTRAINT fk_v2_comp_inc_encabezado
        FOREIGN KEY (id_comparacion_encabezado)
        REFERENCES op_rh_v2_comparaciones_encabezado (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,

    CONSTRAINT uk_v2_comp_inc_enc_seq
        UNIQUE KEY (id_comparacion_encabezado, orden_secuencia),

    INDEX idx_v2_comp_inc_legacy_id (id_asistencia_incidencia_legacy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Field Design Rationale:
- Stores 0..N captured legacy incidence rows associated with the comparison execution.
- Zero rows in this table for a given `id_comparacion_encabezado` explicitly denote an empty legacy incidence set `[]`.

---

### Table 4: `op_rh_v2_comparaciones_discrepancias` (Discrepancy Detail Log)

```sql
CREATE TABLE IF NOT EXISTS op_rh_v2_comparaciones_discrepancias (
    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_comparacion_encabezado INT(11) UNSIGNED NOT NULL,
    categoria_discrepancia VARCHAR(40) NOT NULL,
    campo_evaluado VARCHAR(50) NOT NULL,
    valor_v2 TEXT NULL,
    valor_legacy TEXT NULL,
    descripcion_diferencia TEXT NULL,
    orden_secuencia INT(11) UNSIGNED NOT NULL DEFAULT 1,

    CONSTRAINT fk_v2_comp_disc_encabezado
        FOREIGN KEY (id_comparacion_encabezado)
        REFERENCES op_rh_v2_comparaciones_encabezado (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,

    CONSTRAINT uk_v2_comp_disc_enc_seq
        UNIQUE KEY (id_comparacion_encabezado, orden_secuencia),

    INDEX idx_v2_comp_disc_cat (categoria_discrepancia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Field Design Rationale:
- Stores normalized value-level evidence explaining WHY a comparison was categorized with a discrepancy (e.g. `campo_evaluado = 'hora_entrada_sensor'`, `valor_v2 = '08:00:00'`, `valor_legacy = '08:15:00'`).

---

## 5. HARD IMMUTABILITY TRIGGERS

To enforce strict append-only revisioning and prevent accidental tampering with historical comparison results, database-level triggers block `UPDATE` and `DELETE` operations on comparison header records:

```sql
DELIMITER $$

CREATE TRIGGER tg_op_rh_v2_comparaciones_encabezado_no_update
BEFORE UPDATE ON op_rh_v2_comparaciones_encabezado
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'V2.6 Error: Modifications to op_rh_v2_comparaciones_encabezado rows are prohibited. New comparisons must create append-only revision records.';
END$$

CREATE TRIGGER tg_op_rh_v2_comparaciones_encabezado_no_delete
BEFORE DELETE ON op_rh_v2_comparaciones_encabezado
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'V2.6 Error: Deletions from op_rh_v2_comparaciones_encabezado are prohibited. Comparison history is immutable.';
END$$

DELIMITER ;
```

---

## 6. PROPOSED ROLLBACK SCRIPT (SQL MIGRATION 000005 DOWN)

The rollback sequence cleanly drops triggers and tables in reverse dependency order without touching any legacy or upstream V2 tables:

```sql
DROP TRIGGER IF EXISTS tg_op_rh_v2_comparaciones_encabezado_no_delete;
DROP TRIGGER IF EXISTS tg_op_rh_v2_comparaciones_encabezado_no_update;

DROP TABLE IF EXISTS op_rh_v2_comparaciones_discrepancias;
DROP TABLE IF EXISTS op_rh_v2_comparaciones_legacy_incidencias;
DROP TABLE IF EXISTS op_rh_v2_comparaciones_legacy_capturas;
DROP TABLE IF EXISTS op_rh_v2_comparaciones_encabezado;
```

---

## 7. FUTURE SCHEMA TEST MATRIX (40 TEST CASES)

When physical schema implementation is authorized (Milestone `V2.6-M`), the test suite `tests/v2_parallel_comparison_schema_tests.php` MUST validate the following 40 physical invariants:

- **S01:** All 4 V2.6 tables created cleanly during UP migration.
- **S02:** FK type compatibility and `ON DELETE RESTRICT` enforcement on `id_evaluacion_administrativa`.
- **S03:** Unique constraint `uk_v2_comp_eval_revision` rejects duplicate revision numbers for same evaluation.
- **S04:** Unique constraint `uk_v2_comp_fingerprint` enforces execution idempotency.
- **S05:** Valid comparison header creation for `MATCH` result.
- **S06:** Valid comparison header creation for `PUNCH_DIFFERENCE`.
- **S07:** Valid comparison header creation for `SHIFT_DIFFERENCE`.
- **S08:** Valid comparison header creation for `INCIDENT_DIFFERENCE`.
- **S09:** Valid comparison header creation for `OVERTIME_DIFFERENCE` (verifying zero monetary/approval columns).
- **S10:** Valid comparison header creation for `LEGACY_LIMITATION`.
- **S11:** Valid comparison header creation for `REVIEW_REQUIRED`.
- **S12:** Valid comparison header creation for `MISSING_LEGACY_RECORD`.
- **S13:** Valid comparison header creation for `NOT_COMPARABLE`.
- **S14:** Legacy attendance snapshot insert with exact scalar field retention.
- **S15:** Legacy attendance snapshot nullability for `MISSING_LEGACY_RECORD`.
- **S16:** Legacy incidence snapshot set creation with sequence ordinals 1 and 2.
- **S17:** Empty legacy incidence set representation (0 child rows).
- **S18:** Discrepancy detail log insert linking header and discrepancy category.
- **S19:** Value-level evidence persistence (`valor_v2` vs `valor_legacy`).
- **S20:** `tg_op_rh_v2_comparaciones_encabezado_no_update` trigger blocks `UPDATE` operations.
- **S21:** `tg_op_rh_v2_comparaciones_encabezado_no_delete` trigger blocks `DELETE` operations.
- **S22:** Retroactive legacy update scenario: C1 retains snapshot A, C2 creates snapshot B.
- **S23:** Retroactive legacy incidence addition scenario: C1 retains `[]`, C2 retains `[I1]`.
- **S24:** Missing-then-created scenario: C1 retains `MISSING_RECORD`, C2 compares against newly created legacy row.
- **S25:** Monotonic comparison revision increment ($N+1$).
- **S26:** `ON DELETE RESTRICT` prevents deletion of parent V2.5 administrative evaluation revision.
- **S27:** `ON DELETE RESTRICT` prevents deletion of parent comparison header when child snapshots exist.
- **S28:** Zero monetary/payroll columns in `op_rh_v2_comparaciones_encabezado`.
- **S29:** Zero monetary/payroll columns in `op_rh_v2_comparaciones_discrepancias`.
- **S30:** Zero legacy table schema modifications (`op_rh_personal_asistencia` unaltered).
- **S31:** Zero legacy incidence table schema modifications (`op_rh_personal_asistencia_incidencia` unaltered).
- **S32:** Upstream raw punch table (`op_rh_v2_marcaciones_crudas`) unaltered.
- **S33:** Upstream scheduled shift table (`op_rh_v2_jornadas_programadas`) unaltered.
- **S34:** Upstream P1 pairing tables unaltered.
- **S35:** Upstream P2 calculation tables unaltered.
- **S36:** Upstream V2.5 administrative evaluation tables unaltered.
- **S37:** DOWN rollback script drops triggers and tables in reverse dependency order cleanly.
- **S38:** UP migration re-executes cleanly after DOWN rollback.
- **S39:** Database collation and charset (`utf8mb4_unicode_ci`) compatibility across all 4 tables.
- **S40:** Zero public API endpoint/route assumptions built into database structure.

---

## 8. SUMMARY OF PHYSICAL MODEL DECISIONS

```text
PHYSICAL_MODEL_FILE: docs/architecture/CHECADOR_V2_PARALLEL_COMPARISON_PHYSICAL_MODEL_V1.0.md
PHYSICAL_MODEL_STATUS: FROZEN_V1.0
CONTRACT_SHA256: d21a59d79de7fbcca12fe1c43b1b398f9e3bbae9b54e8ef972f0fceacf50bacb
FROZEN_TABLE_SET: op_rh_v2_comparaciones_encabezado, op_rh_v2_comparaciones_legacy_capturas, op_rh_v2_comparaciones_legacy_incidencias, op_rh_v2_comparaciones_discrepancias
LEGACY_ATTENDANCE_GLOBALLY_IMMUTABLE: NO
LEGACY_INCIDENCE_GLOBALLY_IMMUTABLE: NO
LEGACY_SNAPSHOT_MODEL: Immutable comparison-time normalized row snapshot + exact incidence set snapshot
LEGACY_COMPARISON_INPUT_HISTORICALLY_REPRODUCIBLE: YES
EXACT_LEGACY_INCIDENCE_SET_PERSISTED: YES
EMPTY_LEGACY_INCIDENCE_SET_REPRESENTABLE: YES
MISSING_LEGACY_LOOKUP_PROVENANCE: PASS (Explicit MISSING_RECORD presence state + snapshot hash)
LEGACY_MUTATION_HISTORICAL_REPRODUCIBILITY: PASS
LEGACY_INCIDENCE_RETROACTIVITY: PASS
MISSING_THEN_CREATED_PROVENANCE: PASS
EXPLICIT_V2_5_REVISION_PROVENANCE: PASS (id_evaluacion_administrativa FK)
COMPARISON_LOGICAL_AGGREGATE_KEY: (id_personal, fecha_jornada_local) via id_evaluacion_administrativa
COMPARISON_REVISION_MODEL: Monotonic revision_numero per id_evaluacion_administrativa
DISCREPANCY_CARDINALITY: MULTIPLE (0..N detail rows per comparison execution)
COMPARISON_EVIDENCE_STORAGE_MODEL: Normalized field-level evidence (campo_evaluado, valor_v2, valor_legacy, descripcion_diferencia)
FINGERPRINT_CANONICALIZATION: hash('sha256', "{$idEvaluacionAdmin}_{$legacyAttendanceIdOrMissing}_{$sortedLegacyIncidenceSnapshotHashes}_{$algorithmVersion}_{$policyVersion}")
FINGERPRINT_IS_PROVENANCE_SOURCE: NO
HEADER_IMMUTABILITY_MODEL: Database BEFORE UPDATE and BEFORE DELETE triggers
CHILD_IMMUTABILITY_MODEL: ON DELETE RESTRICT FK constraints + append-only aggregate lifecycle
LEGACY_TABLES_MODIFIED: NO
MONETARY_FIELDS_INCLUDED: NO
PAYROLL_EFFECT_FIELDS_INCLUDED: NO
OVERTIME_APPROVAL_FIELDS_INCLUDED: NO
PUBLIC_V2_6_API_REQUIRED: NO
LECTOR_CHANGE_REQUIRED: NO
PORTAL3_CHANGE_REQUIRED: NO
PROPOSED_DDL_INCLUDED: YES
DDL_EXECUTED: NO
SOURCE_MODIFIED: NO
TESTS_MODIFIED: NO
MIGRATIONS_MODIFIED: NO
DATABASE_MODIFIED: NO
NEXT_CANONICAL_MILESTONE: V2.6-M — Legacy & Payroll Parallel Comparison Schema Implementation
```
