-- REVIEW TEMPLATE ONLY — NOT A MIGRATION; NOT EXECUTED.
-- This is an operator-gated rollback template, not a standalone runnable file.
-- Use only in the same explicitly authorized development database and only
-- with row IDs and original values recorded from the successful seed run.
-- Never select rollback targets by source_task alone.
-- Any failed/mismatched precondition means ROLLBACK and manual review.

START TRANSACTION;

-- Replace each <...> token from the immutable seed execution record.
-- Keep the transaction open while inspecting this locking read.
-- Expected result: exactly the recorded rows, with every original value equal.
SELECT
  id, permission_key, principal_type, principal_id,
  granted, active, reason, source_task,
  updated_by_user_id, created_at_utc, updated_at_utc
FROM op_rh_v2_permission_grants
WHERE id IN (<RECORDED_INSERTED_ROW_IDS>)
FOR UPDATE;

-- MANUAL CHECKPOINT — do not run the UPDATE unless the SELECT returned every
-- recorded row exactly once and all values match the execution record. If the
-- set is empty, partial, duplicated in the record, or changed, issue ROLLBACK.
-- Keep the expected original values in every predicate below.
-- The predicates prevent touching a row whose recorded state has changed.
-- Soft revocation preserves audit history; no pre-existing row is a target.
UPDATE op_rh_v2_permission_grants
SET granted = 0,
    active = 0,
    reason = 'Revocado por rollback de CHECADOR_V2_M11_DEVICE_ADMIN_READ_GRANT_SEED_V1',
    updated_at_utc = UTC_TIMESTAMP()
WHERE
  (id = <ID_INSERTED_FOR_POSITION_6>
   AND permission_key = 'checador-v2-dispositivos.leer'
   AND principal_type = 'POSITION'
   AND principal_id = 6
   AND granted = 1 AND active = 1
   AND reason = 'Lectura M11 de inventario de dispositivos; alcance institucional vigente.'
   AND source_task = 'CHECADOR_V2_M11_DEVICE_ADMIN_READ_GRANT_SEED_V1'
   AND updated_by_user_id IS NULL
   AND created_at_utc = '<RECORDED_CREATED_AT_UTC_6>'
   AND updated_at_utc = '<RECORDED_UPDATED_AT_UTC_6>')
  OR
  (id = <ID_INSERTED_FOR_POSITION_13>
   AND permission_key = 'checador-v2-dispositivos.leer'
   AND principal_type = 'POSITION'
   AND principal_id = 13
   AND granted = 1 AND active = 1
   AND reason = 'Lectura M11 de inventario de dispositivos; alcance institucional vigente.'
   AND source_task = 'CHECADOR_V2_M11_DEVICE_ADMIN_READ_GRANT_SEED_V1'
   AND updated_by_user_id IS NULL
   AND created_at_utc = '<RECORDED_CREATED_AT_UTC_13>'
   AND updated_at_utc = '<RECORDED_UPDATED_AT_UTC_13>');

-- Review ROW_COUNT and read back each recorded ID before COMMIT. The operator
-- must compare changed-row count to the approved target count while locks are
-- held. If it is not an exact match, issue ROLLBACK; do not accept partial
-- rollback. Commit only after the readback confirms both intended outcomes.
-- For a run that inserted only one position, remove the other branch entirely.
-- No COMMIT is included intentionally: this template requires review gates.
