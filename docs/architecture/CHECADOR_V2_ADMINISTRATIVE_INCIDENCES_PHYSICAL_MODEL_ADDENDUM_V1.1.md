# CHECADOR V2 — ADMINISTRATIVE INCIDENCES PHYSICAL MODEL ADDENDUM V1.1
## EXACT PROVENANCE & ADJUSTMENT REVISION MODEL

**Document Status:** FROZEN_V1.1
**Date:** 2026-10-07
**Target System:** Checador V2 — API
**Project Path:** `/home/silvinol/Proyectos/portal-api-v.1.0.1`
**Base Spec:** `docs/architecture/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_PHYSICAL_MODEL_V1.0.md` (SHA256: `9bbffb34e736fea1cd9dfb433bb1cc1168051af410f34d041fa4bc8ddf6ec29a`)
**Contract Spec:** `docs/v2/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_CONTRACT_V1.0.md` (SHA256: `94f91abf6d0ddeee641c736159b922debbadad5fb60db91da1d26827dfac6b7d`)

---

## 1. PURPOSE & SCOPE OF SUPERSESSION

This physical model addendum addresses and resolves the specific forensic gaps identified during `V2_5_MCP`:
1. **Missing Exact Evaluation-to-Incidence Linkage:** `op_rh_v2_evaluaciones_administrativas` previously had no direct physical mechanism linking an evaluation revision to the exact set of `op_rh_v2_incidencias_aplicaciones` consumed during evaluation execution.
2. **Missing Exact Evaluation-to-Adjustment Linkage:** `op_rh_v2_evaluaciones_administrativas` previously had no direct physical mechanism linking an evaluation revision to the exact set of `op_rh_v2_ajustes_administrativos` consumed during evaluation execution.
3. **Missing AdministrativeAdjustment Logical Revision Identity:** `op_rh_v2_ajustes_administrativos` previously used `uuid_ajuste` per physical row without explicit logical lineage columns (`uuid_ajuste_logico`, `revision_numero`, `id_ajuste_anterior`), preventing append-only audit tracking of corrections and cancellations.

### Scope Rule:
This V1.1 Addendum **supersedes only** the specific evaluation input set association and adjustment revisioning sections of Physical Model Spec V1.0. All other domain principles, table definitions, FK constraints, and DB triggers established in V1.0 (and implemented in Migration 000003) **remain 100% unchanged, active, and authoritative**. Migration 000003 remains byte-for-byte immutable.

---

## 2. PROVENANCE & REVISION MODEL SPECIFICATION

### 2.1 Normalized Input Set Association Tables
To satisfy the contract requirement of historically reproducible evaluations:
- **Incidence Set Association Table (`op_rh_v2_evaluaciones_incidencias`):**
  Maps an explicit immutable evaluation revision (`id_evaluacion_administrativa`) directly to 0..N consumed incidence application revisions (`id_incidencia_aplicacion`).
  - `INCIDENCE_LINK_TARGET`: `op_rh_v2_incidencias_aplicaciones` (`id_incidencia_aplicacion`), which already binds `id_incidencia_revision` + `id_jornada_programada`.
- **Adjustment Set Association Table (`op_rh_v2_evaluaciones_ajustes`):**
  Maps an explicit immutable evaluation revision (`id_evaluacion_administrativa`) directly to 0..N consumed adjustment revisions (`id_ajuste_administrativo`).

### 2.2 Empty Set & Aggregate Immutability Semantics
- An evaluation row with **0 link rows** in `op_rh_v2_evaluaciones_incidencias` unambiguously represents an exact consumed incidence set of `[]`.
- An evaluation row with **0 link rows** in `op_rh_v2_evaluaciones_ajustes` unambiguously represents an exact consumed adjustment set of `[]`.
- **Aggregate Immutability Invariant:** An administrative evaluation header (`op_rh_v2_evaluaciones_administrativas`) and its associated link rows (`op_rh_v2_evaluaciones_incidencias` and `op_rh_v2_evaluaciones_ajustes`) are persisted atomically in a single database transaction. Once committed, no `INSERT`, `UPDATE`, or `DELETE` operations are ever performed against link membership for that evaluation revision.

### 2.3 AdministrativeAdjustment Lineage & Revision Identity
To preserve historical adjustment corrections and cancellations without in-place mutations:
- `uuid_ajuste_logico VARCHAR(64) NOT NULL`: Canonical UUID representing the aggregate logical adjustment across all its revisions.
- `revision_numero INT UNSIGNED NOT NULL DEFAULT 1`: Monotonic revision counter for a logical adjustment.
- `id_ajuste_anterior BIGINT UNSIGNED NULL`: FK reference to the immediate prior adjustment revision replaced by this row.
- `estado_ajuste VARCHAR(32) NOT NULL DEFAULT 'ACTIVE'`: Status tracking (`ACTIVE`, `SUPERSEDED`, `CANCELLED`).
- **Correction Model:** A correction inserts a new row with identical `uuid_ajuste_logico`, `revision_numero = N+1`, `id_ajuste_anterior = N_id`, `estado_ajuste = 'ACTIVE'`, and updates the prior row's status to `'SUPERSEDED'`.
- **Cancellation Model:** A cancellation inserts a new row with identical `uuid_ajuste_logico`, `revision_numero = N+1`, `id_ajuste_anterior = N_id`, `estado_ajuste = 'CANCELLED'`, preserving the prior active state intact in row N.

### 2.4 Existing Migration 000003 Compatibility & Backfill Strategy
For rows created under Migration 000003 prior to Migration 000004:
- `uuid_ajuste_logico` is defaulted to `uuid_ajuste`.
- `revision_numero` is defaulted to `1`.
- `estado_ajuste` is defaulted to `'ACTIVE'`.
- `id_ajuste_anterior` is defaulted to `NULL`.
This guarantees 100% data compatibility without data loss or breaking constraints.

---

## 3. SHA256 FINGERPRINT CANONICALIZATION (V1.1)

`fingerprint_ejecucion` remains an integrity aid and idempotency key. Its deterministic inputs are calculated as:

$$\text{Fingerprint} = \text{SHA256}(\text{ShiftID} \parallel \text{P2RevID} \parallel \text{SortedIncidenceAppIDs} \parallel \text{SortedAdjustmentIDs} \parallel \text{AlgorithmVersion} \parallel \text{PolicyVersion})$$

### Canonicalization Rule:
1. `SortedIncidenceAppIDs`: Comma-separated list of `id_incidencia_aplicacion` sorted numerically ascending (or `NONE` if `[]`).
2. `SortedAdjustmentIDs`: Comma-separated list of `id_ajuste_administrativo` sorted numerically ascending (or `NONE` if `[]`).
Link tables remain the sole **authoritative historical provenance source**; the fingerprint confirms input set integrity.

---

## 4. PROPOSED MIGRATION 000004 DDL (PROPOSED / NOT APPLIED)

```sql
-- =============================================================================
-- MIGRACIÓN ADITIVA V2.5-MA: PROVENIENCIA Y REVISIONES DE AJUSTES (V1.1 ADDENDUM)
-- Spec Version: 1.1 (FROZEN_V1.1 Addendum)
-- =============================================================================

-- 1. Alteración aditiva de op_rh_v2_ajustes_administrativos para linaje de revisiones
ALTER TABLE `op_rh_v2_ajustes_administrativos`
  ADD COLUMN `uuid_ajuste_logico` VARCHAR(64) NULL COMMENT 'Canonical logical UUID for adjustment lineage' AFTER `uuid_ajuste`,
  ADD COLUMN `revision_numero` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Monotonic adjustment revision number' AFTER `uuid_ajuste_logico`,
  ADD COLUMN `estado_ajuste` VARCHAR(32) NOT NULL DEFAULT 'ACTIVE' COMMENT 'Status (ACTIVE, SUPERSEDED, CANCELLED)' AFTER `revision_numero`,
  ADD COLUMN `id_ajuste_anterior` BIGINT UNSIGNED NULL COMMENT 'FK to prior superseded/cancelled adjustment revision' AFTER `estado_ajuste`;

-- Backfill determinista para filas existentes de 000003
UPDATE `op_rh_v2_ajustes_administrativos`
SET `uuid_ajuste_logico` = `uuid_ajuste`
WHERE `uuid_ajuste_logico` IS NULL;

ALTER TABLE `op_rh_v2_ajustes_administrativos`
  MODIFY COLUMN `uuid_ajuste_logico` VARCHAR(64) NOT NULL COMMENT 'Canonical logical UUID for adjustment lineage',
  ADD UNIQUE KEY `uk_v2_ajuste_logico_rev` (`uuid_ajuste_logico`, `revision_numero`),
  ADD CONSTRAINT `fk_v2_ajuste_anterior` FOREIGN KEY (`id_ajuste_anterior`) REFERENCES `op_rh_v2_ajustes_administrativos` (`id`) ON DELETE RESTRICT;

-- 2. Tabla de Asociación Inmutable Evaluaciones -> Aplicaciones de Incidencias
CREATE TABLE IF NOT EXISTS `op_rh_v2_evaluaciones_incidencias` (
  `id_evaluacion_administrativa` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_evaluaciones_administrativas',
  `id_incidencia_aplicacion` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_incidencias_aplicaciones',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_evaluacion_administrativa`, `id_incidencia_aplicacion`),
  KEY `idx_v2_eval_incid_app` (`id_incidencia_aplicacion`),
  CONSTRAINT `fk_v2_eval_incid_eval` FOREIGN KEY (`id_evaluacion_administrativa`) REFERENCES `op_rh_v2_evaluaciones_administrativas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_v2_eval_incid_app` FOREIGN KEY (`id_incidencia_aplicacion`) REFERENCES `op_rh_v2_incidencias_aplicaciones` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabla de Asociación Inmutable Evaluaciones -> Ajustes Administrativos
CREATE TABLE IF NOT EXISTS `op_rh_v2_evaluaciones_ajustes` (
  `id_evaluacion_administrativa` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_evaluaciones_administrativas',
  `id_ajuste_administrativo` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_ajustes_administrativos',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_evaluacion_administrativa`, `id_ajuste_administrativo`),
  KEY `idx_v2_eval_ajuste_adj` (`id_ajuste_administrativo`),
  CONSTRAINT `fk_v2_eval_ajuste_eval` FOREIGN KEY (`id_evaluacion_administrativa`) REFERENCES `op_rh_v2_evaluaciones_administrativas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_v2_eval_ajuste_adj` FOREIGN KEY (`id_ajuste_administrativo`) REFERENCES `op_rh_v2_ajustes_administrativos` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Proposed Rollback DDL (000004 Down):
```sql
DROP TABLE IF EXISTS `op_rh_v2_evaluaciones_ajustes`;
DROP TABLE IF EXISTS `op_rh_v2_evaluaciones_incidencias`;
ALTER TABLE `op_rh_v2_ajustes_administrativos` DROP FOREIGN KEY `fk_v2_ajuste_anterior`;
ALTER TABLE `op_rh_v2_ajustes_administrativos` DROP INDEX `uk_v2_ajuste_logico_rev`;
ALTER TABLE `op_rh_v2_ajustes_administrativos`
  DROP COLUMN `id_ajuste_anterior`,
  DROP COLUMN `estado_ajuste`,
  DROP COLUMN `revision_numero`,
  DROP COLUMN `uuid_ajuste_logico`;
```

---

## 5. RE-EVALUATION OF FORENSIC PROVENANCE (V1.1 ADDENDUM)

With this V1.1 Addendum applied via future Migration 000004:
- `E1` created at T1 with no incidences links 0 rows in `op_rh_v2_evaluaciones_incidencias`.
- Retroactive incidence `A1` inserted at T2 creates application row `A1`.
- `E2` created at T3 links row `A1` in `op_rh_v2_evaluaciones_incidencias`.
- **Result:** `E1` remains provably `[]` forever, while `E2` proves `[A1]`.
- Same exact proof holds for `E1` (`[]` adjustments) vs `E2` (`[J1]` adjustments).
- `FALTA`, `ADMINISTRATIVE_SIN_MARCACION`, `CONTRADICTION_DETECTED`, and `OVERLAP_CONFLICT` statuses become 100% historically reconstructable and reproducible.

---

## 6. FUTURE MIGRATION 000004 TEST MATRIX (40 SCENARIOS)

| Test ID | Scenario Description |
| :--- | :--- |
| **A01** | Evaluation revision with 0 incidence links represents exact consumed set `[]`. |
| **A02** | Evaluation revision linked to 1 incidence application row. |
| **A03** | Evaluation revision linked to multiple (2+) incidence application rows. |
| **A04** | Duplicate `(id_evaluacion_administrativa, id_incidencia_aplicacion)` rejected by PK constraint. |
| **A05** | Retroactive incidence insertion does NOT alter historical evaluation E1 (`[]`). |
| **A06** | Subsequent evaluation E2 correctly links newly created retroactive incidence application. |
| **A07** | `ON DELETE RESTRICT` on `id_incidencia_aplicacion` prevents deletion of referenced incidence application. |
| **A08** | Evaluation revision with 0 adjustment links represents exact consumed set `[]`. |
| **A09** | Evaluation revision linked to 1 adjustment row. |
| **A10** | Evaluation revision linked to multiple (2+) adjustment rows. |
| **A11** | Duplicate `(id_evaluacion_administrativa, id_ajuste_administrativo)` rejected by PK constraint. |
| **A12** | Retroactive adjustment insertion does NOT alter historical evaluation E1 (`[]`). |
| **A13** | Subsequent evaluation E2 correctly links newly created adjustment row. |
| **A14** | `ON DELETE RESTRICT` on `id_ajuste_administrativo` prevents deletion of referenced adjustment. |
| **A15** | Exact fingerprint inputs reconstructable from link tables. |
| **A16** | SHA256 execution fingerprint canonical ordering is deterministic. |
| **A17** | `uuid_ajuste_logico` persists logical adjustment aggregate identity. |
| **A18** | Adjustment revision 1 created successfully. |
| **A19** | Adjustment revision 2 created for same logical adjustment identity. |
| **A20** | Duplicate `(uuid_ajuste_logico, revision_numero)` rejected by UK constraint. |
| **A21** | Adjustment correction creates revision 2 with `estado_ajuste = 'ACTIVE'` and sets revision 1 to `'SUPERSEDED'`. |
| **A22** | Adjustment cancellation creates revision 2 with `estado_ajuste = 'CANCELLED'` without deleting revision 1. |
| **A23** | Historical adjustment revision 1 remains queryable and immutable. |
| **A24** | Backfill strategy populates `uuid_ajuste_logico = uuid_ajuste`, `revision_numero = 1`, `estado_ajuste = 'ACTIVE'` for pre-existing 000003 rows. |
| **A25** | `FALTA` evaluation with empty sets reproducible. |
| **A26** | `ADMINISTRATIVE_SIN_MARCACION` evaluation with empty sets reproducible. |
| **A27** | `CONTRADICTION_DETECTED` evaluation exact incidence sources reconstructable. |
| **A28** | `OVERLAP_CONFLICT` evaluation exact incidence sources reconstructable. |
| **A29** | Service invariant enforces aggregate immutability on link tables (no post-commit INSERTs). |
| **A30** | Delete operations on link tables blocked by service invariant. |
| **A31** | Historical link set cannot gain members through supported service paths. |
| **A32** | P2 calculation revision FK link (`id_calculo_jornada`) remains explicit and unchanged. |
| **A33** | Raw `PunchEvent` table remains 100% untouched. |
| **A34** | P1/P2/Shift schemas remain 100% untouched. |
| **A35** | Zero payroll monetary columns added. |
| **A36** | Zero payable overtime approval columns added. |
| **A37** | Additive UP migration 000004 executes cleanly. |
| **A38** | Additive DOWN migration 000004 rolls back cleanly without dropping 000003 base tables. |
| **A39** | Migration 000003 file remains byte-for-byte unchanged (SHA256: `fcac402726488349479b0c95360980ff67d2ca8acb846e9df5f5643ac7b9efbf`). |
| **A40** | All existing 10 test blocks in `tests/v2_administrative_incidences_schema_tests.php` continue to PASS. |
