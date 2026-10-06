# CHECADOR V2 — SHIFT SCHEDULING PHYSICAL DATA MODEL SPECIFICATION V1.0

**Status:** DRAFT FOR FREEZE
**Version:** 1.0
**Date:** 2026-10-06
**Scope:** Checador V2 API (`/home/silvinol/Proyectos/portal-api-v.1.0.1`)
**Implementation Authorized:** NO (Physical DDL Design Only)
**Database Executed:** NO

---

## 1. PURPOSE & ARCHITECTURAL CONTEXT

This document defines the physical MariaDB/MySQL data model for the Shift Scheduling V2 domain as normatively required by **Checador V2 Architecture Freeze V1.0 Section 14** (`docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`).

### Key Architectural Guarantees:
1. **Raw Punch Fact Protection:** The shift scheduling domain **never** modifies or overwrites raw biometric punch facts recorded in `op_rh_v2_marcaciones_crudas`. Shifts dictate expected work intervals for derived journey evaluation only.
2. **Effective Dating & History:** Historical schedule assignments use explicit `valido_desde` and `valido_hasta` intervals to support deterministic historical workday reconstruction on arbitrary dates.
3. **Decoupled Architecture:** Replaces legacy static `op_rh_personal_horario` table (which lacks versioning, night-shift support, or segment support) with normalized V2 entities while keeping legacy tables intact for parallel operations.

---

## 2. NORMATIVE ENTITIES & TABLE NAMES

The physical data model maps Section 14 conceptual entities into canonical V2 database tables:

| Conceptual Entity (Freeze Sec 14) | Physical Table Name | Purpose |
| :--- | :--- | :--- |
| **ShiftTemplate** | `op_rh_v2_turnos_plantillas` | Reusable shift configuration (e.g. Day Shift, Night Shift, Split Shift). |
| **ShiftSegment** | `op_rh_v2_turnos_segmentos` | Ordered internal segments per template (`WORK`, `BREAK`). |
| **ShiftAssignment** | `op_rh_v2_turnos_asignaciones` | Effective-dated worker-to-template schedule assignments. |
| **ScheduledShift** | `op_rh_v2_jornadas_programadas` | Materialized daily scheduled workdays per worker. |

---

## 3. PHYSICAL TABLE SPECIFICATIONS & PROPOSED DDL

> [!IMPORTANT]
> **PROPOSED / NON-APPLIED DDL ONLY.** The SQL statements below are for technical specification only and MUST NOT be executed against database environments during this slice.

```sql
-- =============================================================================
-- CHECADOR V2 — SHIFT SCHEDULING PHYSICAL SCHEMA (PROPOSED DDL - NON-APPLIED)
-- =============================================================================

-- 1. REUSABLE SHIFT TEMPLATES (ShiftTemplate)
CREATE TABLE IF NOT EXISTS `op_rh_v2_turnos_plantillas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `clave` VARCHAR(64) NOT NULL COMMENT 'Unique technical identifier (e.g., TURNO_DIA_06_14, TURNO_NOCHE_22_06)',
  `nombre` VARCHAR(128) NOT NULL COMMENT 'Human-readable description (e.g., Turno Matutino Gasolinera)',
  `tipo_turno` ENUM('REGULAR', 'NOCTURNO', 'MIXTO', 'ROTATIVO') NOT NULL DEFAULT 'REGULAR',
  `es_activo` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Active, 0 = Disabled',
  `creado_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_turnos_plantillas_clave` (`clave`),
  KEY `idx_v2_turnos_plantillas_activo` (`es_activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 2. SHIFT SEGMENTS (ShiftSegment)
CREATE TABLE IF NOT EXISTS `op_rh_v2_turnos_segmentos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_plantilla` INT UNSIGNED NOT NULL COMMENT 'FK to op_rh_v2_turnos_plantillas',
  `ordinal` TINYINT UNSIGNED NOT NULL COMMENT 'Segment position sequence (1, 2, 3...)',
  `tipo_segmento` ENUM('WORK', 'BREAK') NOT NULL DEFAULT 'WORK' COMMENT 'Segment classification',
  `hora_inicio_local` TIME NOT NULL COMMENT 'Local start time (HH:MM:SS)',
  `hora_fin_local` TIME NOT NULL COMMENT 'Local end time (HH:MM:SS)',
  `dia_offset_inicio` TINYINT NOT NULL DEFAULT 0 COMMENT '0 = Same calendar day, 1 = Next day',
  `dia_offset_fin` TINYINT NOT NULL DEFAULT 0 COMMENT '0 = Same day, 1 = Next day (Cross-midnight)',
  `duracion_minutos` INT UNSIGNED NOT NULL COMMENT 'Calculated length of segment in minutes',
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
  `timezone_local` VARCHAR(64) NOT NULL DEFAULT 'America/Mexico_City' COMMENT 'Workplace timezone used for UTC conversion',
  `payload_segmentos_json` JSON NULL COMMENT 'Snapshot of shift segments for immutable audit',
  `creado_at_utc` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_v2_jornada_personal_fecha` (`id_personal`, `fecha_jornada_local`),
  KEY `idx_v2_jornada_estacion_fecha` (`id_estacion`, `fecha_jornada_local`),
  KEY `idx_v2_jornada_intervalo_utc` (`scheduled_start_utc`, `scheduled_end_utc`),
  CONSTRAINT `fk_v2_jornada_asignacion` FOREIGN KEY (`id_asignacion_origen`) REFERENCES `op_rh_v2_turnos_asignaciones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_v2_jornada_plantilla` FOREIGN KEY (`id_plantilla_origen`) REFERENCES `op_rh_v2_turnos_plantillas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 4. DESIGN DECISIONS & SEMANTIC RESOLUTIONS

### A. Materialized `ScheduledShift` Persistence
`ScheduledShift` (`op_rh_v2_jornadas_programadas`) is designed as a **materialized persistent entity** rather than calculated strictly on demand. Materialization guarantees that historical shift windows, UTC start/end timestamps, and segment snapshots remain immutably reproducible even if a master template is subsequently updated.

### B. Cross-Midnight / Night Shift Representation
Night shifts (e.g. `22:00` to `06:00` next day) are represented explicitly in `op_rh_v2_turnos_segmentos`:
- `hora_inicio_local`: `22:00:00` (`dia_offset_inicio`: `0`)
- `hora_fin_local`: `06:00:00` (`dia_offset_fin`: `1`)
- **Workday Ownership:** Night shifts belong to the `fecha_jornada_local` of the **starting local calendar date** (`22:00`).

### C. Multi-Segment & Meal Break Support
Templates support 1..N ordered segments. For example, a split shift with lunch break:
- Segment 1: `WORK` (08:00 - 12:00, ordinal 1)
- Segment 2: `BREAK` (12:00 - 14:00, ordinal 2)
- Segment 3: `WORK` (14:00 - 18:00, ordinal 3)
No hardcoded limit of 4 slots is enforced.

### D. Day Off vs Unscheduled Representation
- `DAY_OFF`: Explicitly assigned rest days are materialized in `op_rh_v2_jornadas_programadas` with `tipo_jornada = 'DAY_OFF'` (`scheduled_start_utc` is NULL).
- `UNSCHEDULED`: Workers without an active assignment for a given date are marked `tipo_jornada = 'UNSCHEDULED'`.

### E. Timezone Authority
Timezone resolution derives from `op_rh_v2_dispositivos.timezone` associated with the station (`op_rh_localidades`), recorded in `timezone_local` of `op_rh_v2_jornadas_programadas` during UTC calculation.

---

## 5. SEED MIGRATION STRATEGY FROM LEGACY

1. **Legacy Reference:** Legacy rows in `op_rh_personal_horario` will be read for seed migration.
2. **Template Seed:** Unique legacy `(hora_entrada, hora_salida)` pairs will map to generated `op_rh_v2_turnos_plantillas` and `op_rh_v2_turnos_segmentos`.
3. **Assignment Seed:** Worker schedule rows will be inserted into `op_rh_v2_turnos_asignaciones` with `valido_desde = CUTOVER_DATE` and `valido_hasta = NULL`.
4. **Pre-Cutover History:** Pre-cutover dates will **NOT** fabricate historical validity; historical V2 journey evaluation applies only from cutover date forward.
