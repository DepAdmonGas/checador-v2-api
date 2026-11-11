-- Safe permission rollback: revoke only the grants seeded by this task.
-- Keep the additive ACL table and any later principal overrides intact.
UPDATE `op_rh_v2_permission_grants`
SET `granted` = 0,
    `active` = 0,
    `reason` = 'Revocado por rollback de CHECADOR_V2_M03_STATION_SCHEDULING_AUTHORIZATION_V1',
    `updated_at_utc` = UTC_TIMESTAMP()
WHERE `source_task` = 'CHECADOR_V2_M03_STATION_SCHEDULING_AUTHORIZATION_V1'
  AND `principal_type` = 'POSITION'
  AND ((`permission_key` = 'checador.programacion.leer' AND `principal_id` IN (6, 13))
    OR (`permission_key` = 'checador.programacion.editar' AND `principal_id` = 6));
