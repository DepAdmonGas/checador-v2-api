-- DEVELOPMENT HISTORICAL REFERENCE: do not execute in PREPROD.
-- Owner reports PREPROD scope and grant already installed; no reapplication authorized.
-- Prepared for owner-authorized local development; not executed by API development.
-- Owner reports the SERVICE enum and UTC validity columns have already been
-- applied in bd_admongas. Do not repeat that migration.
-- This script only prepares one dedicated scope row and the read grant.
-- The nine values below are physical tb_estaciones.id values, as required by
-- WorkplacePhysicalStationResolver::resolveCanonicalLocality().

-- READ-ONLY PREFLIGHT. Review all results before the APPLY section.
SELECT DATABASE() AS database_name, VERSION() AS mariadb_version;

SHOW CREATE TABLE op_rh_v2_permission_grants;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'tb_modulos_config'
  AND COLUMN_NAME IN ('modulo_key', 'tipo', 'estaciones_soportadas', 'tipo_departamento', 'allow_all', 'activo')
ORDER BY ORDINAL_POSITION;

SHOW INDEX FROM tb_modulos_config;

SELECT id, modulo_key, tipo, estaciones_soportadas, tipo_departamento, allow_all, activo
FROM tb_modulos_config
WHERE modulo_key IN ('biometricos', 'checador_journey_service');

SELECT e.id AS physical_station_id, e.numlista,
       COUNT(DISTINCT e2.id) AS stations_for_numlista,
       COUNT(DISTINCT l.id) AS localities_for_numlista,
       MAX(e.estatus = 0) AS selected_station_active,
       MIN(l.id) AS canonical_locality_id
FROM tb_estaciones e
LEFT JOIN tb_estaciones e2 ON e2.numlista = e.numlista
LEFT JOIN op_rh_localidades l ON l.numlista = e.numlista
WHERE e.id IN (1,2,3,4,5,6,7,8,14)
GROUP BY e.id, e.numlista
ORDER BY e.id;

SELECT id, permission_key, principal_type, principal_id, active, granted,
       valid_from_utc, valid_until_utc
FROM op_rh_v2_permission_grants
WHERE permission_key = 'checador.comodines.leer'
  AND principal_type = 'SERVICE'
  AND principal_id = 1;

-- Must remain zero: this service scope must not create a Portal3 module or
-- human USER/POSITION permissions.
SELECT COUNT(*) AS matching_human_module_catalog_rows
FROM modulos_dpto_operativo
WHERE clave = 'checador_journey_service';

SELECT COUNT(*) AS matching_human_acl_rows
FROM modulos_dpto_operativo m
LEFT JOIN modulos_usuarios_do u ON u.id_modulo = m.id
LEFT JOIN modulos_puestos_do p ON p.id_modulo = m.id
WHERE m.clave = 'checador_journey_service';

-- APPLY ONLY AFTER the owner-reviewed preflight is clean.
-- Keep this DML in one transaction. These INSERTs never overwrite an existing
-- configuration, grant, or explicit denial. A conflicting row is left intact
-- for manual review; it is not silently repaired by this script.
START TRANSACTION;

INSERT INTO tb_modulos_config
    (modulo_key, tipo, estaciones_soportadas, tipo_departamento, allow_all, activo)
SELECT
    'checador_journey_service',
    'stations_only',
    '[1,2,3,4,5,6,7,8,14]',
    'localidades',
    0,
    1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM tb_modulos_config WHERE modulo_key = 'checador_journey_service'
)
AND NOT EXISTS (
    SELECT 1 FROM op_rh_v2_permission_grants
    WHERE permission_key = 'checador.comodines.leer'
      AND principal_type = 'SERVICE'
      AND principal_id = 1
)
AND NOT EXISTS (
    SELECT 1
    FROM (
        SELECT 1 AS physical_id UNION ALL SELECT 2 UNION ALL SELECT 3
        UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
        UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 14
    ) requested
    LEFT JOIN tb_estaciones e ON e.id = requested.physical_id AND e.estatus = 0
    LEFT JOIN tb_estaciones e2 ON e2.numlista = e.numlista
    LEFT JOIN op_rh_localidades l ON l.numlista = e.numlista
    GROUP BY requested.physical_id
    HAVING COUNT(DISTINCT e.id) <> 1
        OR COUNT(DISTINCT e2.id) <> 1
        OR COUNT(DISTINCT l.id) <> 1
);

INSERT INTO op_rh_v2_permission_grants
    (permission_key, principal_type, principal_id, granted, active, reason, source_task,
     valid_from_utc, valid_until_utc)
SELECT
    'checador.comodines.leer',
    'SERVICE',
    1,
    1,
    1,
    'Read-only comodines coverage resolution for journey-local; approved physical/canonical scope only.',
    'COMODINES_SERVICE_SCOPE_V1',
    UTC_TIMESTAMP(6),
    '2026-12-19 06:00:00.000000'
FROM DUAL
WHERE EXISTS (
    SELECT 1 FROM tb_modulos_config
    WHERE modulo_key = 'checador_journey_service'
      AND tipo = 'stations_only'
      AND estaciones_soportadas = '[1,2,3,4,5,6,7,8,14]'
      AND tipo_departamento = 'localidades'
      AND allow_all = 0
      AND activo = 1
)
AND NOT EXISTS (
    SELECT 1
    FROM (
        SELECT 1 AS physical_id UNION ALL SELECT 2 UNION ALL SELECT 3
        UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
        UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 14
    ) requested
    LEFT JOIN tb_estaciones e ON e.id = requested.physical_id AND e.estatus = 0
    LEFT JOIN tb_estaciones e2 ON e2.numlista = e.numlista
    LEFT JOIN op_rh_localidades l ON l.numlista = e.numlista
    GROUP BY requested.physical_id
    HAVING COUNT(DISTINCT e.id) <> 1
        OR COUNT(DISTINCT e2.id) <> 1
        OR COUNT(DISTINCT l.id) <> 1
)
AND UTC_TIMESTAMP(6) < '2026-12-19 06:00:00.000000'
AND NOT EXISTS (
    SELECT 1 FROM op_rh_v2_permission_grants
    WHERE permission_key = 'checador.comodines.leer'
      AND principal_type = 'SERVICE'
      AND principal_id = 1
);

-- POSTFLIGHT: require exactly one matching config and one currently active
-- grant, with the exact UTC upper bound. Inspect before COMMIT; ROLLBACK if
-- either row differs or the station mapping preflight was not one-to-one.
SELECT id, modulo_key, tipo, estaciones_soportadas, tipo_departamento, allow_all, activo
FROM tb_modulos_config
WHERE modulo_key = 'checador_journey_service';

SELECT id, permission_key, principal_type, principal_id, active, granted,
       valid_from_utc, valid_until_utc
FROM op_rh_v2_permission_grants
WHERE permission_key = 'checador.comodines.leer'
  AND principal_type = 'SERVICE'
  AND principal_id = 1;

SELECT COUNT(*) AS matching_human_module_catalog_rows
FROM modulos_dpto_operativo
WHERE clave = 'checador_journey_service';

SELECT COUNT(*) AS matching_human_acl_rows
FROM modulos_dpto_operativo m
LEFT JOIN modulos_usuarios_do u ON u.id_modulo = m.id
LEFT JOIN modulos_puestos_do p ON p.id_modulo = m.id
WHERE m.clave = 'checador_journey_service';

-- COMMIT only after manual verification; otherwise execute ROLLBACK.
-- COMMIT;
-- ROLLBACK;
