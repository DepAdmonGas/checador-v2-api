# Checador V2 — Addendum de asignaciones vinculadas a coberturas
## Propuesta de propietario de escritura Portal3

**Estado:** APROBADO POR DECISIÓN EXPRESA DEL RESPONSABLE PARA IMPLEMENTACIÓN EN DESARROLLO  
**Contrato base:** `CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md` (permanece intacto)  
**Ámbito:** sólo filas `TEMPORAL` con `id_cobertura_version` no nulo, generadas al aprobar coberturas de Rol de Comodines.  
**Complementa:** propuesta de revisión de Comodines V1.2.  
**No cambia:** asignaciones habituales, cambios temporales normales de M03/M04, plantillas M04, resolución regular, permisos, Journey ni J1/J2.

## 1. Alcance y excepción explícita

Para asignaciones normales, el contrato base conserva `ShiftAssignmentService` de API V2 como propietario de creación, cierre y validación. Para la excepción de cobertura, Portal3 backend será el único coordinador de escritura sobre una transacción que contiene: revisión de cobertura, asignaciones `TEMPORAL` vinculadas y auditoría. Esta excepción no constituye una ruta general de escritura de horarios desde Portal3.

Una asignación pertenece a esta excepción sólo si su fila tiene `id_cobertura_version` que referencia la revisión `AUTHORIZED` correspondiente y si trabajador, Workplace destino, weekday, intervalo y plantilla/versión concuerdan con esa revisión. Ninguna fila sin enlace puede tratarse como cobertura.

## 2. Escritura y consistencia

La transacción Portal3 valida actor de Dirección, permisos efectivos, doble alcance, membresía habitual, identidad activa, plantilla/versionado, siete días explícitos, fechas, timezone, conflictos y no consolidación. Inserta/append-only la revisión y auditoría; al aprobar inserta todas las asignaciones vinculadas; al cancelar cierra sólo fechas futuras elegibles y conserva las ya efectivas. Error de cualquier paso revierte toda la operación.

La transacción usa InnoDB, bloqueo de fila por trabajador y revisión, unicidad de clave idempotente y fingerprint de payload. La aplicación verifica readback antes del commit. El actor es el usuario autenticado en sesión Portal3; la fecha registrada es UTC. No se crean PunchEvents ni se reescriben jornadas/incidencias históricas.

## 3. Consumo API V2

`ScheduleResolutionService` y `WORKER_EFFECTIVE` continúan siendo los únicos resolutores. API V2 lee la revisión de cobertura autorizada y sus asignaciones vinculadas; verifica la FK, actor/estado, rango, trabajador, destino y versión exacta. El turno de cobertura tiene prioridad temporal, y al concluir o alcanzar el límite de cancelación se resuelve el horario habitual de la membresía de origen. Cualquier enlace roto, solapamiento o incoherencia produce `UNKNOWN`.

API V2 no inserta, actualiza ni cierra cobertura, asignación vinculada o evento administrativo de cobertura. Sus mutaciones HTTP existentes quedan denegadas en servidor después del corte definido por el addendum V1.2. Las rutas de lectura y los escritores normales de asignaciones no cambian.

## 4. Idempotencia, historial y auditoría

Cada solicitud y transición requiere clave de idempotencia y fingerprint. Exact retry devuelve el readback de la operación existente; misma clave con carga distinta es conflicto. Cada cambio de estado crea revisión nueva y referencia a la anterior; no se edita ni elimina historia. Toda revisión registra actor, acción, motivo, tiempo de solicitud/transición/registro UTC, y se relaciona con el evento `WORKPLACE_COVERAGE` de auditoría. Las asignaciones temporales guardan la referencia a la revisión que las originó.

## 5. Contrato de transición de escritor

El addendum no habilita operaciones hasta que el preflight confirme esquema, llaves foráneas, índices, InnoDB y privilegios efectivos; que el writer Portal3 supere pruebas de rollback, reintento, concurrencia, solapamiento, cancelación y auditoría; y que API V2 rechace las rutas mutadoras antiguas aun con el antiguo gate de cobertura activado. El gate lector/resolver API es independiente y no concede escritura.

El contrato base sigue rigiendo todas las demás asignaciones y plantillas. No se amplía acceso de Portal3 a otras tablas o escritores V2.
