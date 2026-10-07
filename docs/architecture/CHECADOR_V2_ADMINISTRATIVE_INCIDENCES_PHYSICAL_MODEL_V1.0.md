# CHECADOR V2 — V2.5 ADMINISTRATIVE INCIDENCES & ADJUSTMENTS PHYSICAL MODEL SPECIFICATION

**Document Version:** 1.0 (FROZEN_V1.0)  
**Status:** FROZEN_V1.0  
**Freeze Date:** 2026-10-07  
**Project:** Checador V2 API (`/home/silvinol/Proyectos/portal-api-v.1.0.1`)  
**Target Completion Date:** 2026-12-31  

---

## 1. PURPOSE AND MANDATORY GOVERNANCE

This document establishes the frozen physical data persistence specification for **Checador V2 Slice V2.5 — Administrative Incidences & Adjustments**.

Authoritative Domain Contract:
`docs/v2/CHECADOR_V2_ADMINISTRATIVE_INCIDENCES_CONTRACT_V1.0.md` (SHA256: `94f91abf6d0ddeee641c736159b922debbadad5fb60db91da1d26827dfac6b7d`)

### Core Physical Doctrine:
1. **Append-Only Immutability:** Revision history and evaluations MUST be append-only. UPDATE and DELETE operations on evaluation headers are strictly prohibited by database triggers.
2. **Fact Separation:** Physical raw punches (`op_rh_v2_marcaciones_crudas`), scheduled shifts (`op_rh_v2_jornadas_programadas`), P1 pairing (`op_rh_v2_pareos_jornada`), and P2 calculations (`op_rh_v2_calculos_jornada`) MUST remain 100% un-mutated.
3. **Normalized Multi-Table Storage:** Separate normalized tables represent Incidence Masters, Incidence Revisions, Daily Applications, Administrative Adjustments, Evaluation Headers, and Evaluation Link associations.

---

## 2. REPOSED PHYSICAL ENTITIES & CANONICAL DB TABLES (5 TABLES)

V2.5 persistence comprises 5 distinct normalized tables in MySQL/MariaDB:

1. **`op_rh_v2_incidencias_maestro` (Incidence Master Aggregate):**
   - Stores logical administrative incidence master records (e.g. multi-day Vacation or Disability range, or single-shift permission).
2. **`op_rh_v2_incidencias_revisiones` (Incidence Revision Audit Trail):**
   - Immutable audit trail storing every version, correction, status transition (`ACTIVE`, `CANCELLED`, `SUPERSEDED`), and approving actor.
3. **`op_rh_v2_incidencias_aplicaciones` (Daily Incidence Application):**
   - Derived daily application per `ScheduledShift` linked to an explicit incidence revision.
4. **`op_rh_v2_ajustes_administrativos` (Administrative Review Adjustments):**
   - Explicit administrative review overrides and interpreted time adjustments applied to a specific P2 calculation revision.
5. **`op_rh_v2_evaluaciones_administrativas` (Administrative Evaluation Header & Revision):**
   - Append-only persisted evaluation result per `ScheduledShift`, linking explicit P2 calculation revision, policy version, administrative algorithm version, evaluation status (`NORMAL_COMPLETED`, `VACATION_APPLIED`, `DISABILITY_APPLIED`, `PERMISSION_APPLIED`, `ADMINISTRATIVE_SIN_MARCACION`, `FALTA`, `CONTRADICTION_DETECTED`, `OVERLAP_CONFLICT`, `DATA_INCOMPLETE`, `REVIEW_REQUIRED`), and SHA256 input fingerprint.

---

## 3. PROPOSED DDL SPECIFICATION (NOT APPLIED)

```sql
-- =============================================================================
-- MIGRACIÓN ADITIVA V2.5-S: IMPLEMENTACIÓN SCHEMA INCIDENCIAS Y AJUSTES
-- Submódulo Motor de Jornada V2: Tablas de Incidencias, Ajustes y Evaluaciones
-- Spec Version: 1.0 (FROZEN_V1.0)
-- =============================================================================

-- 1. Tabla Maestro de Incidencias Administrativas
CREATE TABLE IF NOT EXISTS `op_rh_v2_incidencias_maestro` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid_incidencia` VARCHAR(64) NOT NULL COMMENT 'Canonical external UUID for incidence aggregate',
  `id_personal` INT NOT NULL COMMENT 'Canonical Worker ID (FK to op_rh_personal)',
  `id_estacion` INT NULL COMMENT 'Optional Workplace Station ID constraint (NULL if worker-wide)',
  `tipo_incidencia` VARCHAR(32) NOT NULL COMMENT 'Type code (VACATION, DISABILITY, PERMISSION, AUTHORIZED_LATENESS, AUTHORIZED_EARLY_DEPARTURE, OFFICIAL_MISSION)',
  `alcance_incidencia` VARCHAR(32) NOT NULL DEFAULT 'WORKER' COMMENT 'Scope (WORKER, WORKPLACE, SCHEDULED_SHIFT)',
  `fecha_inicio_local` DATE NOT NULL COMMENT 'Inclusive start local date (YYYY-MM-DD)',
  `fecha_fin_local` DATE NOT NULL COMMENT 'Inclusive end local date (YYYY-MM-DD)',
  `estado_actual` VARCHAR(32) NOT NULL DEFAULT 'ACTIVE' COMMENT 'Current status (ACTIVE, CANCELLED, SUPERSEDED)',
  `revision_actual` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Current active revision number',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_incidencia_uuid` (`uuid_incidencia`),
  KEY `idx_v2_incid_personal_fechas` (`id_personal`, `fecha_inicio_local`, `fecha_fin_local`),
  KEY `idx_v2_incid_estacion_tipo` (`id_estacion`, `tipo_incidencia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla de Revisiones de Incidencias (Audit Trail Inmutable)
CREATE TABLE IF NOT EXISTS `op_rh_v2_incidencias_revisiones` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_incidencia_maestro` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_incidencias_maestro',
  `revision_numero` INT UNSIGNED NOT NULL COMMENT 'Monotonic revision number',
  `estado_revision` VARCHAR(32) NOT NULL COMMENT 'Revision status (ACTIVE, CANCELLED, SUPERSEDED)',
  `motivo` TEXT NULL COMMENT 'Administrative explanation / note',
  `folio_evidencia` VARCHAR(100) NULL COMMENT 'External medical/official document folio reference',
  `creado_por_usuario_id` INT NOT NULL COMMENT 'Actor user ID creating revision (tb_usuarios.id)',
  `aprobado_por_usuario_id` INT NULL COMMENT 'Actor user ID authorizing revision (tb_usuarios.id)',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_incid_rev_numero` (`id_incidencia_maestro`, `revision_numero`),
  CONSTRAINT `fk_v2_incid_rev_maestro` FOREIGN KEY (`id_incidencia_maestro`) REFERENCES `op_rh_v2_incidencias_maestro` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabla de Aplicaciones Diarias de Incidencia a Jornadas Programadas
CREATE TABLE IF NOT EXISTS `op_rh_v2_incidencias_aplicaciones` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_incidencia_revision` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_incidencias_revisiones',
  `id_jornada_programada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_jornadas_programadas',
  `fecha_jornada_local` DATE NOT NULL COMMENT 'Local work date (YYYY-MM-DD)',
  `estado_aplicacion` VARCHAR(32) NOT NULL DEFAULT 'APPLIED' COMMENT 'Application state (APPLIED, CONTRADICTION, OVERLAP_CONFLICT)',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_incid_app_shift_rev` (`id_jornada_programada`, `id_incidencia_revision`),
  KEY `idx_v2_incid_app_rev` (`id_incidencia_revision`),
  CONSTRAINT `fk_v2_incid_app_rev` FOREIGN KEY (`id_incidencia_revision`) REFERENCES `op_rh_v2_incidencias_revisiones` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_v2_incid_app_shift` FOREIGN KEY (`id_jornada_programada`) REFERENCES `op_rh_v2_jornadas_programadas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabla de Ajustes Administrativos Manuales
CREATE TABLE IF NOT EXISTS `op_rh_v2_ajustes_administrativos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid_ajuste` VARCHAR(64) NOT NULL COMMENT 'Canonical UUID for adjustment',
  `id_jornada_programada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_jornadas_programadas',
  `id_calculo_jornada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to explicit op_rh_v2_calculos_jornada P2 revision',
  `tipo_ajuste` VARCHAR(32) NOT NULL COMMENT 'Adjustment type (OVERRIDE_STATUS, INTERPRETED_ENTRY_TIME, INTERPRETED_EXIT_TIME, JUSTIFY_LATENESS)',
  `hora_interpretada_entrada_utc` DATETIME NULL COMMENT 'Interpreted administrative entry time (NOT a raw PunchEvent)',
  `hora_interpretada_salida_utc` DATETIME NULL COMMENT 'Interpreted administrative exit time (NOT a raw PunchEvent)',
  `motivo_ajuste` TEXT NOT NULL COMMENT 'Mandatory administrative justification note',
  `creado_por_usuario_id` INT NOT NULL COMMENT 'Actor user ID creating adjustment (tb_usuarios.id)',
  `aprobado_por_usuario_id` INT NOT NULL COMMENT 'Actor user ID approving adjustment (tb_usuarios.id)',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_ajuste_uuid` (`uuid_ajuste`),
  KEY `idx_v2_ajuste_shift_calc` (`id_jornada_programada`, `id_calculo_jornada`),
  CONSTRAINT `fk_v2_ajuste_shift` FOREIGN KEY (`id_jornada_programada`) REFERENCES `op_rh_v2_jornadas_programadas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_v2_ajuste_calculo` FOREIGN KEY (`id_calculo_jornada`) REFERENCES `op_rh_v2_calculos_jornada` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabla Header de Evaluaciones Administrativas (Revisiones Append-Only)
CREATE TABLE IF NOT EXISTS `op_rh_v2_evaluaciones_administrativas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_jornada_programada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_jornadas_programadas',
  `id_calculo_jornada` BIGINT UNSIGNED NOT NULL COMMENT 'FK to explicit immutable op_rh_v2_calculos_jornada revision',
  `id_personal` INT NOT NULL COMMENT 'Worker ID snapshot',
  `id_estacion` INT NOT NULL COMMENT 'Workplace station ID snapshot',
  `fecha_jornada_local` DATE NOT NULL COMMENT 'Local work date snapshot (YYYY-MM-DD)',
  `revision_numero` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Monotonic evaluation revision number per shift',
  `version_algoritmo_admin` VARCHAR(32) NOT NULL DEFAULT 'v2.5-d' COMMENT 'Administrative evaluation algorithm version',
  `version_politica` VARCHAR(32) NOT NULL DEFAULT 'POLICY_V1.0_DEFAULT' COMMENT 'Policy version provenance',
  `estado_evaluacion` VARCHAR(32) NOT NULL COMMENT 'Administrative status (NORMAL_COMPLETED, VACATION_APPLIED, DISABILITY_APPLIED, PERMISSION_APPLIED, ADMINISTRATIVE_SIN_MARCACION, FALTA, CONTRADICTION_DETECTED, OVERLAP_CONFLICT, DATA_INCOMPLETE, REVIEW_REQUIRED)',
  `estado_revision_humana` VARCHAR(32) NOT NULL DEFAULT 'NOT_REQUIRED' COMMENT 'Human review status (NOT_REQUIRED, PENDING_REVIEW, REVIEWED_APPROVED, REVIEWED_REJECTED)',
  `fingerprint_ejecucion` VARCHAR(64) NOT NULL COMMENT 'SHA256 fingerprint of P2 revision + applicable incidence revisions + adjustment IDs + policy version',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_eval_shift_revision` (`id_jornada_programada`, `revision_numero`),
  UNIQUE KEY `uk_v2_eval_fingerprint` (`fingerprint_ejecucion`),
  KEY `idx_v2_eval_calculo_jornada` (`id_calculo_jornada`),
  KEY `idx_v2_eval_personal_fecha` (`id_personal`, `fecha_jornada_local`, `revision_numero`),
  KEY `idx_v2_eval_estado` (`estado_evaluacion`),
  CONSTRAINT `fk_v2_eval_shift` FOREIGN KEY (`id_jornada_programada`) REFERENCES `op_rh_v2_jornadas_programadas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_v2_eval_calculo` FOREIGN KEY (`id_calculo_jornada`) REFERENCES `op_rh_v2_calculos_jornada` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Triggers de Protección Append-Only sobre Evaluaciones Administrativas
DELIMITER $$

DROP TRIGGER IF EXISTS `tg_op_rh_v2_evaluaciones_admin_no_update`$$
CREATE TRIGGER `tg_op_rh_v2_evaluaciones_admin_no_update`
BEFORE UPDATE ON `op_rh_v2_evaluaciones_administrativas`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. UPDATE operations are strictly prohibited on op_rh_v2_evaluaciones_administrativas.';
END$$

DROP TRIGGER IF EXISTS `tg_op_rh_v2_evaluaciones_admin_no_delete`$$
CREATE TRIGGER `tg_op_rh_v2_evaluaciones_admin_no_delete`
BEFORE DELETE ON `op_rh_v2_evaluaciones_administrativas`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'ERROR: Hard immutability enforced. DELETE operations are strictly prohibited on op_rh_v2_evaluaciones_administrativas.';
END$$

DELIMITER ;
```

---

## 4. FOREIGN KEY TYPE COMPATIBILITY MATRIX

- `id_personal` (`INT(11)` in `op_rh_personal`) ↔ `INT` in V2.5 tables (`PASS`).
- `id_estacion` (`INT(11)` in `op_rh_localidades`) ↔ `INT` in V2.5 tables (`PASS`).
- `id_jornada_programada` (`BIGINT UNSIGNED` in `op_rh_v2_jornadas_programadas`) ↔ `BIGINT UNSIGNED` in V2.5 tables (`PASS`).
- `id_calculo_jornada` (`BIGINT UNSIGNED` in `op_rh_v2_calculos_jornada`) ↔ `BIGINT UNSIGNED` in V2.5 tables (`PASS`).
- `creado_por_usuario_id` (`INT(11)` in `tb_usuarios`) ↔ `INT` in V2.5 tables (`PASS`).

FK Delete Safety: All foreign keys to primary entities (`ScheduledShift`, `P2 Calculation`, `Incidence Revision`) enforce **`ON DELETE RESTRICT`**. Upstream records cannot be cascade-deleted.

---

## 5. FUTURE SCHEMA TEST MATRIX (S01 – S52)

Implementation slice `V2.5-M` must satisfy a 52-scenario schema test suite:

- `S01`: Clean table creation for all 5 V2.5 tables.
- `S02`: Foreign key type compatibility and constraint verification.
- `S03`: Worker-wide incidence master creation (`id_estacion` NULL).
- `S04`: Workplace-scoped incidence master creation.
- `S05`: Shift-scoped incidence master creation.
- `S06`: Date range fields `fecha_inicio_local` and `fecha_fin_local` formatted as `YYYY-MM-DD`.
- `S07`: Invalid range (`fecha_fin_local` < `fecha_inicio_local`) rejected by service logic.
- `S08`: Single master incidence range used for 15-day disability.
- `S09`: Creation of incidence revision 1.
- `S10`: Creation of incidence revision 2 preserves revision 1.
- `S11`: Status `ACTIVE` set on active revision.
- `S12`: Status `CANCELLED` set on cancelled revision.
- `S13`: Status `SUPERSEDED` set on superseded revision.
- `S14`: Audit actor provenance fields `creado_por_usuario_id` and `aprobado_por_usuario_id` persisted.
- `S15`: Daily incidence application linked to ScheduledShift.
- `S16`: Duplicate application for same incidence revision and shift rejected by `uk_v2_incid_app_shift_rev`.
- `S17`: New incidence revision allows new daily application record.
- `S18`: Direct FK to P2 calculation header `id_calculo_jornada`.
- `S19`: Administrative adjustment creation with mandatory justification.
- `S20`: Administrative adjustment revisioning.
- `S21`: Administrative interpreted time stored in `hora_interpretada_entrada_utc` without touching raw punches.
- `S22`: Nullable interpreted time fields when adjustment does not specify timestamps.
- `S23`: Evaluation header revision 1 created.
- `S24`: Evaluation header revision 2 created for new administrative evaluation.
- `S25`: Duplicate shift revision number rejected by `uk_v2_eval_shift_revision`.
- `S26`: Fingerprint uniqueness enforced by `uk_v2_eval_fingerprint`.
- `S27`: Administrative algorithm version `version_algoritmo_admin` persisted.
- `S28`: Policy version `version_politica` persisted.
- `S29`: Daily evaluation links to 0..N incidence application records.
- `S30`: Daily evaluation links to 0..N administrative adjustment records.
- `S31`: Status `OVERLAP_CONFLICT` supported.
- `S32`: Status `CONTRADICTION_DETECTED` supported.
- `S33`: Status `REVIEW_REQUIRED` supported.
- `S34`: Status `ADMINISTRATIVE_SIN_MARCACION` supported.
- `S35`: Status `FALTA` supported.
- `S36`: Status `DATA_INCOMPLETE` fail-closed supported.
- `S37`: Multi-workplace shift evaluations remain distinct per shift.
- `S38`: No `id_marcacion_cruda` or synthetic raw punch columns present in V2.5 tables.
- `S39`: No upstream mutation fields present.
- `S40`: No overtime approval or payable overtime fields present.
- `S41`: No money, salary, or payroll fields present.
- `S42`: `ON DELETE RESTRICT` on P2 calculation FK prevents calculation deletion.
- `S43`: `ON DELETE RESTRICT` on ScheduledShift FK prevents shift deletion.
- `S44`: `ON DELETE RESTRICT` on incidence master prevents master deletion.
- `S45`: `ON DELETE RESTRICT` on adjustment prevents adjustment deletion.
- `S46`: Trigger `tg_op_rh_v2_evaluaciones_admin_no_update` blocks UPDATE on evaluations.
- `S47`: Trigger `tg_op_rh_v2_evaluaciones_admin_no_delete` blocks DELETE on evaluations.
- `S48`: Historical administrative evaluation revisions remain queryable.
- `S49`: Policy version change creates new administrative evaluation revision.
- `S50`: Algorithm version change creates new administrative evaluation revision.
- `S51`: Clean rollback via DOWN migration script.
- `S52`: Clean re-application via UP migration script.
