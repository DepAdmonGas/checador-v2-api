-- Revoke only the development grant introduced by this task; retain ACL history.
UPDATE `op_rh_v2_permission_grants`
SET `granted` = 0,
    `active` = 0,
    `reason` = 'Revocado por rollback de CHECADOR_V2_M05_STATION_JOURNEYS_READ_AUTHORIZED_V1',
    `updated_at_utc` = UTC_TIMESTAMP()
WHERE `source_task` = 'CHECADOR_V2_M05_STATION_JOURNEYS_READ_AUTHORIZED_V1'
  AND `permission_key` = 'checador.jornadas.leer'
  AND `principal_type` = 'POSITION'
  AND `principal_id` = 6;
