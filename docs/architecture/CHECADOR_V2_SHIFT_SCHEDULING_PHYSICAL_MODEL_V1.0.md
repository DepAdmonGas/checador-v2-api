# CHECADOR V2 — SHIFT SCHEDULING PHYSICAL DATA MODEL SPECIFICATION V1.0

**Status:** FROZEN_V1.0
**Version:** 1.0
**Date:** 2026-10-06
**Freeze Date:** 2026-10-06
**Freeze Scope:** Shift Scheduling V2 Physical Persistence Model
**Scope:** Checador V2 API (`/home/silvinol/Proyectos/portal-api-v.1.0.1`)
**Implementation Authorized:** NO (Physical DDL Specification Only)
**Database Executed:** NO

---

## 1. PURPOSE & ARCHITECTURAL CONTEXT

This document defines the frozen physical MariaDB/MySQL data model for the Shift Scheduling V2 domain as normatively required by **Checador V2 Architecture Freeze V1.0 Section 14** (`docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`).

> [!NOTE]
> **FROZEN DATA MODEL SPECIFICATION V1.0.** Any future semantic or structural change to these physical table definitions requires an explicit architecture addendum and design revision before implementation.

### Key Architectural Guarantees:
1. **Raw Punch Fact Protection:** The shift scheduling domain **never** modifies or overwrites raw biometric punch facts recorded in `op_rh_v2_marcaciones_crudas`. Shifts dictate expected work intervals for derived journey evaluation only.
2. **Effective Dating & History:** Historical schedule assignments use explicit `valido_desde` and `valido_hasta` intervals to support deterministic historical workday reconstruction on arbitrary dates.
3. **Decoupled Architecture:** Replaces legacy static `op_rh_personal_horario` table with normalized V2 entities while keeping legacy tables intact for parallel operations.
4. **Multi-Workplace Alignment:** Supports workers operating across multiple workplaces by scoping materialized shifts to `(id_personal, id_estacion, fecha_jornada_local)`.

---

## 2. NORMATIVE ENTITIES & TABLE NAMES

The physical data model maps Section 14 conceptual entities into canonical V2 database tables:

| Conceptual Entity (Freeze Sec 14) | Physical Table Name | Purpose |
| :--- | :--- | :--- |
| **ShiftTemplate** | `op_rh_v2_turnos_plantillas` | Reusable, versioned shift configuration (e.g. Day Shift, Night Shift, Split Shift). |
| **ShiftSegment** | `op_rh_v2_turnos_segmentos` | Ordered internal segments per template version (`WORK`, `BREAK`). |
| **ShiftAssignment** | `op_rh_v2_turnos_asignaciones` | Effective-dated worker-to-template schedule assignments per workplace. |
| **ScheduledShift** | `op_rh_v2_jornadas_programadas` | Materialized daily scheduled workdays per worker and workplace. |

---

## 3. PHYSICAL TABLE SPECIFICATIONS & PROPOSED DDL

> [!IMPORTANT]
> **PROPOSED / NON-APPLIED DDL ONLY.** The SQL statements below are for technical specification only and MUST NOT be executed against database environments during this slice.

```sql
-- =============================================================================
-- CHECADOR V2 — SHIFT SCHEDULING PHYSICAL SCHEMA (PROPOSED DDL - NON-APPLIED)
-- =============================================================================

-- 1. REUSABLE VERSIONED SHIFT TEMPLATES (ShiftTemplate)
CREATE TABLE IF NOT EXISTS `op_rh_v2_turnos_plantillas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `clave` VARCHAR(64) NOT NULL COMMENT 'Technical identifier (e.g., TURNO_DIA_06_14, TURNO_NOCHE_22_06)',
  `version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Version number for immutable template revisioning',
  `nombre` VARCHAR(128) NOT NULL COMMENT 'Human-readable description (e.g., Turno Matutino Gasolinera)',
  `tipo_turno` VARCHAR(32) NOT NULL DEFAULT 'REGULAR' COMMENT 'Shift metadata (REGULAR, NOCTURNO, MIXTO, ROTATIVO)',
  `es_activo` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Active, 0 = Disabled',
  `creado_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_turnos_plantillas_clave_version` (`clave`, `version`),
  KEY `idx_v2_turnos_plantillas_activo` (`es_activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 2. SHIFT SEGMENTS (ShiftSegment)
CREATE TABLE IF NOT EXISTS `op_rh_v2_turnos_segmentos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_plantilla` INT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_turnos_plantillas',
  `ordinal` TINYINT UNSIGNED NOT NULL COMMENT 'Segment position sequence (1, 2, 3...)',
  `tipo_segmento` VARCHAR(32) NOT NULL DEFAULT 'WORK' COMMENT 'Segment classification (WORK, BREAK, etc.)',
  `hora_inicio_local` TIME NOT NULL COMMENT 'Local start time (HH:MM:SS)',
  `hora_fin_local` TIME NOT NULL COMMENT 'Local end time (HH:MM:SS)',
  `dia_offset_inicio` TINYINT NOT NULL DEFAULT 0 COMMENT '0 = Same calendar day, 1 = Next day',
  `dia_offset_fin` TINYINT NOT NULL DEFAULT 0 COMMENT '0 = Same day, 1 = Next day (Cross-midnight)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_segmento_plantilla_ordinal` (`id_plantilla`, `ordinal`),
  KEY `idx_v2_segmento_plantilla` (`id_plantilla`),
  CONSTRAINT `fk_v2_segmentos_plantilla` FOREIGN KEY (`id_plantilla`) REFERENCES `op_rh_v2_turnos_plantillas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 3. HISTORICAL EFFECTIVE SHIFT ASSIGNMENTS (ShiftAssignment)
CREATE TABLE IF NOT EXISTS `op_rh_v2_turnos_asignaciones` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_personal` INT NOT NULL COMMENT 'Worker ID in op_rh_personal',
  `id_estacion` INT NOT NULL COMMENT 'Workplace / Station ID in op_rh_localidades',
  `id_plantilla` INT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_turnos_plantillas',
  `dia_semana` ENUM('LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO', 'DOMINGO') NOT NULL COMMENT 'ISO Weekday assignment',
  `valido_desde` DATE NOT NULL COMMENT 'Effective start date inclusive',
  `valido_hasta` DATE NULL COMMENT 'Effective end date exclusive (NULL = Indefinite)',
  `es_activo` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Active assignment, 0 = Revoked',
  `creado_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `registrado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Audit timestamp for as-recorded reconstruction',
  PRIMARY KEY (`id`),
  KEY `idx_v2_asig_busqueda_historica` (`id_personal`, `id_estacion`, `dia_semana`, `valido_desde`, `valido_hasta`),
  KEY `idx_v2_asig_plantilla` (`id_plantilla`),
  CONSTRAINT `fk_v2_asig_plantilla` FOREIGN KEY (`id_plantilla`) REFERENCES `op_rh_v2_turnos_plantillas` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 4. MATERIALIZED DAILY SCHEDULED SHIFTS (ScheduledShift)
CREATE TABLE IF NOT EXISTS `op_rh_v2_jornadas_programadas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_personal` INT NOT NULL COMMENT 'Worker ID in op_rh_personal',
  `id_estacion` INT NOT NULL COMMENT 'Workplace / Station ID in op_rh_localidades',
  `fecha_jornada_local` DATE NOT NULL COMMENT 'Local calendar workday date',
  `id_asignacion_origen` BIGINT UNSIGNED NULL COMMENT 'Provenance FK to op_rh_v2_turnos_asignaciones',
  `id_plantilla_origen` INT UNSIGNED NULL COMMENT 'Provenance FK to op_rh_v2_turnos_plantillas',
  `tipo_jornada` ENUM('SCHEDULED', 'DAY_OFF', 'UNSCHEDULED') NOT NULL DEFAULT 'SCHEDULED',
  `scheduled_start_utc` DATETIME NULL COMMENT 'Exact UTC start timestamp of shift',
  `scheduled_end_utc` DATETIME NULL COMMENT 'Exact UTC end timestamp of shift',
  `timezone_local` VARCHAR(64) NOT NULL DEFAULT 'America/Mexico_City' COMMENT 'Canonical Workplace timezone used for UTC conversion',
  `payload_segmentos_json` JSON NULL COMMENT 'Snapshot of shift segments for immutable audit',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_jornada_personal_estacion_fecha` (`id_personal`, `id_estacion`, `fecha_jornada_local`),
  KEY `idx_v2_jornada_estacion_fecha` (`id_estacion`, `fecha_jornada_local`),
  KEY `idx_v2_jornada_intervalo_utc` (`scheduled_start_utc`, `scheduled_end_utc`),
  CONSTRAINT `fk_v2_jornada_asignacion` FOREIGN KEY (`id_asignacion_origen`) REFERENCES `op_rh_v2_turnos_asignaciones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_v2_jornada_plantilla` FOREIGN KEY (`id_plantilla_origen`) REFERENCES `op_rh_v2_turnos_plantillas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 4. REVISED DESIGN DECISIONS & AUDIT RESOLUTIONS

### A. Multi-Workplace Scoping (`uk_v2_jornada_personal_estacion_fecha`)
Unique key for `op_rh_v2_jornadas_programadas` is scoped to `(id_personal, id_estacion, fecha_jornada_local)`. This ensures workers assigned across multiple workplaces (e.g. Station A in morning, Station B in evening) can have valid scheduled workdays at each workplace without global date collisions.

### B. Canonical Workplace Timezone Authority
Workplace timezone resolution derives from `op_rh_v2_dispositivos.timezone` associated with `op_rh_localidades.id` (or fallback workplace config), recorded explicitly in `timezone_local` of `op_rh_v2_jornadas_programadas`. Technical device timezones serve as hardware fallback when workplace timezone is unconfigured, but do NOT override workplace authority.

### C. Template Versioning & Historical Immutability
`op_rh_v2_turnos_plantillas` includes a `version` column (`uk_v2_turnos_plantillas_clave_version`). Editing a template creates a new version record, ensuring that past `op_rh_v2_jornadas_programadas` and `op_rh_v2_turnos_asignaciones` retain immutable provenance to their exact original segment configuration.

### D. As-Recorded Audit Reconstruction
`op_rh_v2_turnos_asignaciones` records `registrado_at_utc` in addition to `valido_desde`/`valido_hasta`. This enables bitemporal audit queries ("What was the valid schedule on Oct 1 as recorded on Oct 10 vs corrected on Oct 20").

### E. Night Shift & Segment Offsets
Night shifts (e.g. `22:00` to `06:00` next day) use `dia_offset_inicio = 0` and `dia_offset_fin = 1` in `op_rh_v2_turnos_segmentos`. Workday ownership belongs to the local calendar date of shift start (`fecha_jornada_local`).

---

## 5. SEED MIGRATION STRATEGY FROM LEGACY

1. **Legacy Reference:** Legacy rows in `op_rh_personal_horario` will be read for seed migration.
2. **Template Seed:** Unique legacy `(hora_entrada, hora_salida)` pairs map to version 1 `op_rh_v2_turnos_plantillas` and `op_rh_v2_turnos_segmentos`.
3. **Assignment Seed:** Worker schedule rows populate `op_rh_v2_turnos_asignaciones` with `valido_desde = CUTOVER_DATE` and `valido_hasta = NULL`.
4. **Pre-Cutover History:** Pre-cutover dates will **NOT** fabricate historical validity; historical V2 journey evaluation applies only from cutover date forward.
