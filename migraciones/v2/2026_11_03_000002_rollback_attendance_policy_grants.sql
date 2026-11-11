-- Revoke only grants installed by this task; preserve versioned policy rows.
UPDATE `op_rh_v2_permission_grants`
SET `active` = 0, `granted` = 0,
    `reason` = 'Grant revocado por rollback de M11; consultar el historial de policy grants.',
    `updated_at_utc` = UTC_TIMESTAMP()
WHERE `source_task` = 'CHECADOR_V2_M11_M06_CONFIGURATION_IMPLEMENTATION_REMEDIATION_V2'
  AND `principal_type` = 'POSITION'
  AND `principal_id` = 13
  AND `permission_key` IN ('checador.configuracion.leer', 'checador.configuracion.retardo_incidencias.editar');
