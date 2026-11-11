-- M11 development-only grants for Dirección de Operaciones (position 13).
-- The API still revalidates the dedicated grant and canonical station scope.
INSERT IGNORE INTO `op_rh_v2_permission_grants`
  (`permission_key`, `principal_type`, `principal_id`, `granted`, `active`, `reason`, `source_task`)
VALUES
  ('checador.configuracion.leer', 'POSITION', 13, 1, 1,
   'Consulta de políticas versionadas en localidades canónicas autorizadas, M11 desarrollo.',
   'CHECADOR_V2_M11_M06_CONFIGURATION_IMPLEMENTATION_REMEDIATION_V2'),
  ('checador.configuracion.retardo_incidencias.editar', 'POSITION', 13, 1, 1,
   'Edición prospectiva de políticas versionadas en localidades canónicas autorizadas, M11 desarrollo.',
   'CHECADOR_V2_M11_M06_CONFIGURATION_IMPLEMENTATION_REMEDIATION_V2');
