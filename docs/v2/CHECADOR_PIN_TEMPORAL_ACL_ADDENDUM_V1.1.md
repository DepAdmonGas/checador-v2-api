# Checador V2 — Addendum de autorización del PIN temporal V1.1

**Estado:** APROBADO POR EL PROPIETARIO — 25/11/2026
**Base:** `TEMPORARY_ACCESS_SECURITY_ADDENDUM_V1.0.md`
**Alcance de conciliación:** sustituye exclusivamente sus §§3 y 4.
**Arquitectura:** Architecture Freeze V1.0, contrato de transporte, rutas, payloads, Device Auth, TTL y consumo único permanecen sin cambios.

## 1. Política funcional (§3 sustituido)

- Toda solicitud humana exige sesión Portal3/JWT válido, usuario institucional activo e identidad `tb_usuarios.id` verificada en servidor.
- La autorización funcional se hereda del módulo institucional Recursos Humanos y de la asignación activa al submódulo `checador`, usando el resolvedor y la precedencia institucional vigentes. Cuando hay grants directos aplicables, no se fusionan con grants del puesto. Denegaciones, falta de permiso efectivo, filas ambiguas o errores de consulta deniegan el acceso.
- **Encargado, puesto 6:** consulta solicitudes cuando tiene lectura efectiva de Checador; autoriza, rechaza o cancela cuando además tiene edición efectiva de Checador.
- **Dirección de Operaciones, puesto 13:** consulta únicamente. Nunca autoriza, rechaza ni cancela.
- Los demás puestos no acceden a este módulo.
- Una fila individual explícita de `acceso-temporal`, cuando exista, es una restricción adicional: `leer=0` deniega consulta y edición; `editar=0` deniega edición. Una fila ausente no deniega ni concede permisos. Grants positivos propios de `acceso-temporal` no sustituyen el permiso efectivo de Checador. Filas individuales duplicadas o inválidas fallan cerrado.
- No se requieren grants positivos independientes por puesto o usuario para `acceso-temporal`. La falta o inactividad del módulo o de su configuración específica no crea una concesión ni limita el alcance heredado; la autorización base y el alcance de Checador deben estar activos y resolverse correctamente.

## 2. Alcance de estación (§4 sustituido)

- El alcance deriva del resolvedor institucional de Checador y de su configuración activa. No se utiliza `allow_all`.
- Encargado queda limitado a la estación física propia autorizada por Checador. Dirección queda limitada a las estaciones físicas autorizadas por el alcance de Checador y conserva sólo lectura.
- Para cada localidad solicitada, Portal3 y API validan una estación física activa asociada mediante un mapeo único de `numlista` a `op_rh_localidades.id`. No se equiparan IDs, no se aceptan departamentos por inferencia y el identificador enviado por navegador no concede alcance.
- API vuelve a validar usuario, puesto, permisos, estación, solicitud, trabajador y dispositivo en cada operación humana. Las solicitudes del Lector continúan autenticándose mediante Device Auth.
- Los rechazos por permiso o alcance no ejecutan mutaciones. Se mantienen los códigos HTTP definidos en V1.0 para autenticación, autorización y fallos de dependencia.

## 3. Integración y transición

- Portal3 aplica esta política a navegación, acceso directo, consulta de cola y operaciones existentes; mantiene CSRF, confirmación, comprobación de pertenencia de solicitud y revalidación de jurisdicción.
- API aplica la misma política a los endpoints existentes de consulta, autorización, rechazo y cancelación. No se agregan rutas ni formatos nuevos.
- Se conserva el PIN de asistencia separado del PIN administrativo de configuración del Lector. Este addendum no altera el PIN administrativo.
- `CHECADOR_TEMPORARY_ACCESS_POSTS_ENABLED` y los demás gates existentes conservan su estado y comportamiento. Este addendum no los activa ni habilita POST de otros módulos. La activación operativa requiere validación coordinada Portal3/API; Plesk no se despliega por esta aprobación.
- No se insertan permisos ni configuraciones, no se crean tablas y no se modifican listas de estaciones.

## 4. Pruebas de conformidad

Las regresiones deben cubrir Encargado con permisos Checador de lectura y edición sin grant positivo específico de PIN; Encargado sólo lectura; denegación individual de lectura y edición; Dirección lectura y denegación de escritura; usuario inactivo o sin Checador; estación ajena; mapeo inexistente o ambiguo; fallo SQL; consulta de cola y operaciones existentes; CSRF, confirmación y gates POST cerrados. Portal3 y API deben producir decisiones equivalentes para las mismas filas y alcance canónico.
