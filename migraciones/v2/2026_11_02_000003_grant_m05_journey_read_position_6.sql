-- Development-only, narrow read grant for M05; independent of biometricos.*.
-- INSERT IGNORE preserves any existing explicit grant or revocation.
INSERT IGNORE INTO `op_rh_v2_permission_grants`
  (`permission_key`, `principal_type`, `principal_id`, `granted`, `active`, `reason`, `source_task`)
VALUES
  ('checador.jornadas.leer', 'POSITION', 6, 1, 1,
   'Lectura M05 limitada a la estación activa propia y localidad canónica verificada.',
   'CHECADOR_V2_M05_STATION_JOURNEYS_READ_AUTHORIZED_V1');
