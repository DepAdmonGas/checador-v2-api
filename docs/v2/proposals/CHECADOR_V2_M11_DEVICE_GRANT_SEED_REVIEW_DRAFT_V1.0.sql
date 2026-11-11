-- REVIEW TEMPLATE ONLY — NOT A MIGRATION; NOT EXECUTED.
-- Location outside migraciones/v2 is intentional. Do not run until a later
-- execution authorization, target verification, SQL review, and test approval.
-- Scope: development only; exact permission and POSITION principals below.
-- Existing decisions are preserved. No USER row is inserted or changed.
-- Any SQL error is fatal: issue ROLLBACK and do not continue or commit.

-- PRE-FLIGHT (read-only): retain this result as the before-state evidence.
SELECT
  id, permission_key, principal_type, principal_id,
  granted, active, reason, source_task,
  updated_by_user_id, created_at_utc, updated_at_utc
FROM op_rh_v2_permission_grants
WHERE permission_key = 'checador-v2-dispositivos.leer'
  AND principal_type = 'POSITION'
  AND principal_id IN (6, 13)
ORDER BY principal_id;

-- Operator checkpoint: verify this is the explicitly authorized development
-- database. Existing granted rows are already provisioned; any denied,
-- inactive, or otherwise unexpected row is a conflict to report, not repair.
-- Do not proceed if the target or pre-existing decisions are uncertain.

START TRANSACTION;

-- One atomic insert statement. The unique key is the final concurrency guard.
-- Existing rows of any decision state are excluded; no UPDATE/upsert/IGNORE.
-- If a concurrent insert wins, this statement errors; ROLLBACK the transaction.
INSERT INTO op_rh_v2_permission_grants
  (permission_key, principal_type, principal_id, granted, active, reason, source_task)
SELECT requested.permission_key,
       requested.principal_type,
       requested.principal_id,
       1,
       1,
       requested.reason,
       'CHECADOR_V2_M11_DEVICE_ADMIN_READ_GRANT_SEED_V1'
FROM (
  SELECT
    'checador-v2-dispositivos.leer' AS permission_key,
    'POSITION' AS principal_type,
    6 AS principal_id,
    'Lectura M11 de inventario de dispositivos; alcance institucional vigente.' AS reason
  UNION ALL
  SELECT
    'checador-v2-dispositivos.leer',
    'POSITION',
    13,
    'Lectura M11 de inventario de dispositivos; alcance institucional vigente.'
) AS requested
LEFT JOIN op_rh_v2_permission_grants AS existing
  ON existing.permission_key = requested.permission_key
 AND existing.principal_type = requested.principal_type
 AND existing.principal_id = requested.principal_id
WHERE existing.id IS NULL;

-- READBACK: retain the complete result and compare it with the pre-flight.
SELECT
  id, permission_key, principal_type, principal_id,
  granted, active, reason, source_task,
  updated_by_user_id, created_at_utc, updated_at_utc
FROM op_rh_v2_permission_grants
WHERE permission_key = 'checador-v2-dispositivos.leer'
  AND principal_type = 'POSITION'
  AND principal_id IN (6, 13)
ORDER BY principal_id;

-- Operator checkpoint before COMMIT (not included):
-- 1. Compare before/after rows and INSERT affected-row count.
-- 2. Record exact inserted IDs and every original column value externally.
-- 3. Confirm only the two authorized keys exist and no USER rows changed.
-- 4. On any mismatch, issue ROLLBACK. Commit only after independent review.
-- `source_task` identifies this mechanism but does not prove per-run creation.
