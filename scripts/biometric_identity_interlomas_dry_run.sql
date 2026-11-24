-- Read-only classification. Execute only after checking the database identity.
-- A first identity means no V2 identity history on any device.
SELECT DATABASE() AS database_name, CURRENT_USER() AS effective_user, UTC_TIMESTAMP() AS evaluated_at_utc;
SELECT CASE WHEN COUNT(*) = 1 THEN 'READY' ELSE 'STOP' END AS device_station_status
FROM op_rh_v2_dispositivos WHERE id = 2 AND id_estacion = 1 AND estado = 1;
SELECT COUNT(*) AS active_workers,
       COALESCE(SUM(classification = 'CONSERVAR'), 0) AS conservar,
       COALESCE(SUM(classification = 'PRIMERA_IDENTIDAD'), 0) AS primera_identidad_candidatos,
       COALESCE(SUM(classification = 'RECHAZAR'), 0) AS rechazar
FROM (
  SELECT p.id,
    CASE
      WHEN (SELECT COUNT(*) FROM op_rh_v2_dispositivo_identidad_personal i
            WHERE i.id_dispositivo = 2 AND i.id_personal = p.id AND i.es_activo = 1
              AND i.valido_desde <= UTC_TIMESTAMP()
              AND (i.valido_hasta IS NULL OR i.valido_hasta > UTC_TIMESTAMP())) = 1
       AND EXISTS (SELECT 1 FROM op_rh_v2_dispositivo_identidad_personal v
                   WHERE v.id_dispositivo = 2 AND v.id_personal = p.id AND v.es_activo = 1
                     AND v.valido_desde <= UTC_TIMESTAMP()
                     AND (v.valido_hasta IS NULL OR v.valido_hasta > UTC_TIMESTAMP())
                     AND CHAR_LENGTH(TRIM(v.referencia_externa_personal)) BETWEEN 1 AND 64
                     AND BINARY v.referencia_externa_personal = BINARY TRIM(v.referencia_externa_personal))
       AND NOT EXISTS (
         SELECT 1 FROM op_rh_v2_dispositivo_identidad_personal c
         JOIN op_rh_v2_dispositivo_identidad_personal x
           ON x.id_dispositivo = c.id_dispositivo
          AND x.referencia_externa_personal = c.referencia_externa_personal
          AND x.id <> c.id
          AND x.valido_desde < COALESCE(c.valido_hasta, '9999-12-31 23:59:59')
          AND COALESCE(x.valido_hasta, '9999-12-31 23:59:59') > c.valido_desde
         WHERE c.id_dispositivo = 2 AND c.id_personal = p.id AND c.es_activo = 1
           AND c.valido_desde <= UTC_TIMESTAMP()
           AND (c.valido_hasta IS NULL OR c.valido_hasta > UTC_TIMESTAMP())
       ) THEN 'CONSERVAR'
      WHEN NOT EXISTS (SELECT 1 FROM op_rh_v2_dispositivo_identidad_personal h WHERE h.id_personal = p.id)
        THEN 'PRIMERA_IDENTIDAD'
      ELSE 'RECHAZAR'
    END AS classification
  FROM op_rh_personal p
  WHERE p.estado = 1 AND p.id_estacion = 1
) candidates;
