-- Migración Shift Scheduling V2 Physical Data Model V1.0
-- Submódulo API V2.3: Reusable Shift Templates, Shift Segments, Historical Shift Assignments, and Materialized Daily Scheduled Shifts

-- 1. Reusable Versioned Shift Templates (ShiftTemplate)
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

-- 2. Shift Segments (ShiftSegment)
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

-- 3. Historical Effective Shift Assignments (ShiftAssignment)
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

-- 4. Materialized Daily Scheduled Shifts (ScheduledShift)
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
