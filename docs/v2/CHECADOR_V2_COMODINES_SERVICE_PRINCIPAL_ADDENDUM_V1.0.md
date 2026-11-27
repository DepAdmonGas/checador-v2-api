# CHECADOR V2 — COMODINES SERVICE PRINCIPAL ADDENDUM V1.0

**Estado:** APROBADO POR EL PROPIETARIO PARA IMPLEMENTACIÓN API — 2026-11-26.  
**Aprobación cruzada API/Lector:** No consta en la evidencia disponible; no se declara aprobada.  
**Alcance:** procesador PHP CLI `journey-local`; lectura/resolución de coberturas para el Motor J1/J2.  
**No modifica:** Architecture Freeze V1.0, contratos HTTP/J1/J2 congelados, Portal3, Lector, ni autorización humana.

## 1. Resolución documental del estado previo

El changelog del 2026-11-09 registra aprobación cruzada del SHA `0cd553e2dbb3eac43a9a221832bcdb5c51aa0a0d7f36b3af1f7e27eff10ef618` para la implementación de desarrollo de cobertura. Los campos `FINAL_ADDENDUM_APPROVAL_REQUIRED: YES` del documento de propuesta V1.0 son un estado histórico de esa revisión, no una revocación posterior de la aprobación registrada. Esa aprobación cubrió el modelo y el slice de cobertura documentados; no autorizó entonces un principal `SERVICE`. El propietario aprobó este addendum de SERVICE para implementación API el 2026-11-26; no se infiere aprobación cruzada API/Lector.

## 2. Principal y permiso

- Tipo independiente: `SERVICE`; nunca se traduce a `USER` o `POSITION`.
- Identidad local autorizada para desarrollo: `journey-local`, asociada al ID de principal `SERVICE` 1 en `op_rh_v2_permission_grants`. El ID 1 pertenece sólo al espacio `SERVICE` y no identifica a un usuario.
- Única capacidad: `checador.comodines.leer`. No puede solicitar, aprobar, cancelar, editar ni eliminar coberturas.
- El proceso debe ser CLI, ejecutarse bajo el UID del archivo de configuración privada ya validado y usar `service_id=journey-local`.
- El grant debe estar activo, concedido y dentro de su intervalo UTC obligatorio. No se inserta ningún grant mediante este addendum.

## 3. Alcance institucional y aislamiento

La autoridad institucional del procesador se obtiene exclusivamente de `tb_modulos_config.modulo_key='checador_journey_service'`, con `activo=1`, `tipo='stations_only'`, `tipo_departamento='localidades'`, `allow_all=0` y lista explícita de estaciones físicas. Para desarrollo la lista aprobada es `[1,2,3,4,5,6,7,8,14]`; cada elemento se interpreta como `tb_estaciones.id`, y debe mapearse de forma única y activa mediante `tb_estaciones.id → numlista → op_rh_localidades.id`. No se lee, cambia ni amplía `biometricos`.

Para resolver una cobertura se requiere que **origen y destino** pertenezcan simultáneamente al alcance institucional vigente de `checador_journey_service` y al allowlist canónico `stations` del archivo CLI. Este último sólo reduce el alcance. Cada Workplace vuelve a mapearse a una estación física activa antes de resolver. Cobertura desconocida, vencida, solapada, asignación no vinculada o jornada competidora materializada producen `UNKNOWN`/denegación; no se cae al Workplace habitual como sustituto de una cobertura incoherente.

La fila se limita a `tb_modulos_config`. No se registra `checador_journey_service` en `modulos_dpto_operativo` y no se insertan filas en `modulos_usuarios_do` ni `modulos_puestos_do`. Por tanto, esta configuración no concede permisos humanos ni crea por sí misma un módulo navegable. El preflight operativo debe comprobar que no haya una clave homónima en el catálogo/permisos de Portal3.

## 4. Integración con J1/J2

El procesador CLI consulta el resolver existente `WorkplaceCoverageService::resolveWorkerEffective()` bajo el mismo bloqueo por trabajador. El descubrimiento puede ver asignaciones habituales y TEMPORAL enlazadas, pero procesa sólo la jornada cuyo Workplace efectivo resuelve el servicio. Un materializado contradictorio en otro Workplace bloquea el caso; no se crea una segunda jornada ni se reasigna una marcación. El Motor J1/J2 y sus reglas no cambian.

El endpoint HTTP existente conserva su JWT humano, ACL y scope actuales. Nunca acepta `SERVICE` desde una petición HTTP. Portal3 no obtiene ni usa esta identidad.

## 5. Auditoría

Cada decisión de autorización genera una entrada JSON de log con identidad de servicio, permiso, Workplaces origen/destino, decisión y tiempo UTC. No se incluyen secretos ni datos personales. La salida estructurada del procesador también identifica `service_id`. El grant no concede acceso administrativo a Portal3 ni a tablas de autorización humana.

## 6. Impacto contractual exacto

Adición interna al consumidor de `WORKER_EFFECTIVE`: cambiar la fuente de alcance del principal CLI de `biometricos` a la configuración dedicada `checador_journey_service`, conservando el permiso read-only y el doble alcance. No cambia request/response HTTP, autenticación humana, reglas de precedencia de cobertura, materialización, clasificación J1/J2, ni el schema de jornadas. El soporte de `SERVICE` y las columnas de vigencia ya fueron aplicados por el propietario en `bd_admongas`; aquí no se repite esa migración. El SQL adjunto prepara la configuración dedicada y el grant específico; el propietario ejecutará el SQL.

## 7. Activación y validación

El código implementa el cambio aprobado y deniega si faltan las columnas ACL, el grant vigente, la configuración institucional, el mapeo físico/canónico o el scope dual. El gate existente de cobertura continúa default-off; este addendum no autoriza activar gates, ejecutar cron ni procesamiento real. El SQL no se ejecutó. Antes de habilitar el gate siguen requeridas la verificación de instalación/grant y las pruebas MariaDB de lectura e integración J1/J2. La aprobación cruzada API/Lector permanece separada y no está acreditada aquí.
