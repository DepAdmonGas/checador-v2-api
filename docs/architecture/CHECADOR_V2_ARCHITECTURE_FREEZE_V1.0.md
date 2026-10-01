# CHECADOR V2 — ARCHITECTURE FREEZE V1.0

**Status:** FROZEN  
**Version:** 1.0  
**Date:** 2026-09-28  
**Scope:** API + Lector + Motor de Jornada  
**Implementation Authorized:** NO

---

## 1. SOURCE OF TRUTH

Este documento constituye la especificación arquitectónica compartida y oficial de Checador V2 para los proyectos:

- Checador API
- Checador Lector

Ambos proyectos deberán conservar una copia idéntica de:

`docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`

Ninguno de los proyectos puede modificar unilateralmente esta especificación.

Cualquier modificación arquitectónica deberá:

1. proponerse explícitamente;
2. evaluarse contra API y Lector;
3. ser aprobada antes de modificar este documento;
4. generar una nueva revisión de la especificación.

Mientras `Implementation Authorized` permanezca en `NO`, este documento no autoriza:

- implementación;
- creación de tablas;
- migraciones;
- cambios en producción;
- cambios de nómina;
- sustitución de legacy;
- refactor derivado de esta arquitectura.

---

## 2. PRINCIPIO FUNDAMENTAL

Checador V2 se construirá alrededor de la siguiente separación:

```text
EL DISPOSITIVO PRODUCE HECHOS.

EL MOTOR INTERPRETA LOS HECHOS.

RH AJUSTA LA INTERPRETACIÓN.

NÓMINA CONSUME UN RESULTADO VALIDADO.
```

Estas responsabilidades deberán mantenerse separadas.

La arquitectura distingue cuatro capas conceptuales:

```text
HECHO ORIGINAL
      ↓
INTERPRETACIÓN
      ↓
AJUSTE ADMINISTRATIVO
      ↓
RESULTADO VALIDADO
```

Una intervención administrativa nunca deberá modificar silenciosamente el hecho original.

---

## 3. ESTRATEGIA DE EVOLUCIÓN

Checador V2 será una evolución incremental.

No se realizará una reescritura completa del sistema actual.

Durante las primeras fases:

```text
LEGACY = sistema oficial

V2 = sistema sombra
```

El sistema actual continuará funcionando mientras V2:

- captura;
- procesa;
- calcula;
- compara;
- valida.

Ninguna transición hacia V2 deberá realizarse hasta completar los gates correspondientes.

---

## 4. DOMINIO MULTICENTRO

El dominio no estará basado en el concepto de gasolinera.

La abstracción principal será:

```text
Company
   ↓
Workplace
```

`Workplace` representa un centro de trabajo.

Podrá representar, entre otros:

- gasolinera;
- oficina;
- restaurante;
- almacén;
- taller;
- centro administrativo;
- otro centro de trabajo.

La lógica central de asistencia no deberá depender del giro del centro de trabajo.

---

## 5. RESPONSABILIDAD DEL LECTOR

El Lector tendrá como responsabilidades conceptuales:

- capturar una identificación;
- conocer el dispositivo de origen;
- generar o conservar el identificador técnico del evento;
- conservar la fecha/hora disponible del dispositivo;
- persistir localmente el evento cuando sea necesario;
- transmitirlo;
- reintentar cuando no exista conectividad;
- esperar confirmación del API antes de considerar sincronizado un evento.

El Lector NO será responsable de determinar definitivamente:

- entrada;
- salida;
- retardo;
- falta;
- horas trabajadas;
- tiempo extraordinario;
- incidencia;
- jornada nocturna;
- resultado de nómina.

---

## 6. RESPONSABILIDAD DEL API DE INGESTA

El API de ingesta tendrá como responsabilidades conceptuales:

- autenticar al dispositivo o cliente autorizado;
- validar la estructura del evento;
- registrar recepción;
- aplicar idempotencia;
- detectar transmisiones repetidas;
- persistir el hecho original;
- devolver confirmación de recepción/persistencia.

La petición de ingesta no deberá ejecutar de forma acoplada todo el cálculo de jornada.

---

## 7. PunchEvent

`PunchEvent` representa una marcación original.

Representa un hecho, no una interpretación.

Conceptualmente podrá conservar información equivalente a:

```text
event_uuid
device_id
external_worker_reference
source_timestamp
source_timezone
occurred_at_utc
received_at_utc
capture_method
external_event_id
device_sequence
processing_status
metadata
```

Los nombres físicos definitivos serán determinados posteriormente durante el diseño técnico.

`PunchEvent` NO deberá contener como hecho original:

```text
is_entry
is_exit
late_minutes
overtime_minutes
incident
payroll_result
```

porque esos conceptos pertenecen a capas posteriores.

Una marcación original aceptada no será sobrescrita debido a una reinterpretación administrativa.

---

## 8. IDENTIDAD EXTERNA DEL TRABAJADOR

No deberá asumirse que el identificador del trabajador utilizado por un dispositivo es igual al identificador interno del trabajador.

Debe existir conceptualmente una resolución equivalente a:

```text
Device
+
External Worker Identity
+
Validity
        ↓
Worker
```

Entidad conceptual:

```text
DeviceWorkerIdentity
```

Su responsabilidad será asociar una identidad externa proveniente de un dispositivo o sistema de captura con el trabajador interno correspondiente.

Esto permitirá que un mismo trabajador pueda poseer identificadores diferentes en diferentes dispositivos.

El diseño físico definitivo queda pendiente para la fase de modelo de datos.

---

## 9. DISPOSITIVOS

Entidad conceptual:

```text
Device
```

Cada dispositivo deberá poder identificarse de manera independiente.

Conceptualmente podrá estar asociado a:

```text
Workplace
manufacturer
model
serial
device_type
status
timezone
```

La arquitectura no deberá depender exclusivamente de ZKTeco.

Podrán existir en el futuro diferentes métodos de captura, por ejemplo:

```text
BIOMETRIC
PIN
CARD
NFC
MOBILE
WEB_TERMINAL
API_IMPORT
OTHER
```

---

## 10. IDEMPOTENCIA

Un mismo evento transmitido múltiples veces deberá representar un solo `PunchEvent`.

El cliente V2 deberá generar conceptualmente:

```text
event_uuid
```

antes del primer intento de transmisión.

Todos los reintentos del mismo evento utilizarán el mismo identificador.

Cuando exista un identificador confiable proporcionado por el dispositivo podrá utilizarse adicionalmente como mecanismo de deduplicación.

Para hardware legacy sin identificador confiable podrá existir deduplicación secundaria.

La deduplicación heurística no será considerada equivalente a la idempotencia nativa V2.

---

## 11. PunchReceipt / TransmissionReceipt

Se reconoce conceptualmente la diferencia entre:

```text
PunchEvent
```

y:

```text
TransmissionReceipt
```

`PunchEvent` representa el hecho original.

`TransmissionReceipt` representa un intento técnico de transmisión o recepción.

Una marcación transmitida tres veces deberá continuar representando:

```text
1 PunchEvent
```

aunque puedan existir múltiples intentos técnicos.

La decisión de implementar `TransmissionReceipt` como tabla física independiente o como mecanismo de logging técnico queda pendiente para el diseño físico.

---

## 12. OPERACIÓN OFFLINE

Checador V2 seguirá el principio:

```text
CAPTURAR PRIMERO
SINCRONIZAR DESPUÉS
```

El cliente de adquisición deberá disponer conceptualmente de una cola local durable.

Flujo:

```text
CAPTURA
   ↓
EVENTO LOCAL
   ↓
COLA DURABLE
   ↓
INTENTO API
   ↓
ACK
   ↓
SINCRONIZADO
```

Si existe:

- pérdida de Internet;
- API no disponible;
- servidor no disponible;
- problema temporal de conectividad;

el evento deberá permanecer pendiente.

Un timeout no deberá considerarse confirmación de pérdida ni generar un nuevo evento distinto.

---

## 13. ACK DEL API

El lector solamente deberá considerar sincronizado un evento después de recibir confirmación válida del servidor.

Conceptualmente podrán existir respuestas equivalentes a:

```text
ACCEPTED
ALREADY_RECEIVED
REJECTED
```

Los nombres físicos se definirán durante la especificación del API.

---

## 14. MODELO DE TURNOS

El modelo conceptual distinguirá:

```text
ShiftTemplate
ShiftSegment
ShiftAssignment
ScheduledShift
```

### ShiftTemplate

Describe una configuración reutilizable de turno.

Ejemplo:

```text
06:00 → 14:00
```

o:

```text
22:00 → 06:00
```

### ShiftSegment

Permite representar segmentos internos cuando sean necesarios.

Ejemplo conceptual:

```text
WORK
BREAK
WORK
```

### ShiftAssignment

Relaciona históricamente un trabajador con una configuración de turno.

Las asignaciones deberán tener vigencia temporal.

### ScheduledShift

Representa una jornada programada concreta para un trabajador.

Ejemplo:

```text
worker: 150
work_date: 2026-09-28
scheduled_start: 2026-09-28 22:00
scheduled_end: 2026-09-29 06:00
```

---

## 15. FECHA LABORAL Y TURNO NOCTURNO

Fecha calendario y fecha laboral no son necesariamente equivalentes.

Un turno:

```text
28-sep 22:00
      ↓
29-sep 06:00
```

representa una sola jornada.

La fecha lógica de la jornada será, por defecto arquitectónico, la fecha de inicio del turno:

```text
work_date = 2026-09-28
```

Los timestamps completos deberán conservarse.

El Motor de Jornada no deberá resolver turnos nocturnos mediante comparaciones aisladas de campos `TIME`.

---

## 16. ASIGNACIONES HISTÓRICAS

Las asignaciones de trabajador, centro y turno deberán poder conservar vigencia histórica.

Conceptualmente:

```text
valid_from
valid_to
```

Modificar actualmente el centro o turno de un trabajador no deberá cambiar silenciosamente la interpretación histórica de jornadas anteriores.

---

## 17. WorkerWorkplaceAssignment

Debe existir conceptualmente una relación histórica equivalente a:

```text
WorkerWorkplaceAssignment
```

que permita determinar a qué centro de trabajo pertenecía o estaba asignado un trabajador en un periodo determinado.

Esto permitirá soportar:

- cambios de centro;
- coberturas;
- asignaciones temporales;
- personal que trabaje en más de un centro.

El diseño físico definitivo queda pendiente.

---

## 18. MOTOR DE JORNADA

El Motor de Jornada recibirá conceptualmente:

```text
Worker
+
ScheduledShift
+
PunchEvents
+
Incidents
+
ApplicableRuleSet
```

y producirá un resultado calculated.

El Motor de Jornada no modificará los `PunchEvent`.

---

## 19. AttendanceRecord

Entidad conceptual:

```text
AttendanceRecord
```

Representa el resultado de interpretar una jornada.

Podrá contener conceptualmente:

```text
worker
scheduled_shift
work_date
calculated_entry
calculated_exit
worked_minutes
ordinary_minutes
break_minutes
late_minutes
early_departure_minutes
overtime_detected_minutes
overtime_authorized_minutes
absence_status
rule_version
calculation_version
status
```

Los nombres y campos físicos definitivos se establecerán en una fase posterior.

---

## 20. ASOCIACIÓN MARCACIÓN → JORNADA

La relación entre un `PunchEvent` y su interpretación deberá poder representarse de manera independiente.

Entidad conceptual:

```text
AttendancePunchAssignment
```

Ejemplo:

```text
Punch 501 → Attendance 700 → ENTRY

Punch 502 → Attendance 700 → INTERMEDIATE

Punch 503 → Attendance 700 → EXIT
```

Cambiar una interpretación no deberá alterar el `PunchEvent`.

---

## 21. MARCACIONES INTERMEDIAS

Todas las marcaciones originales deberán conservarse.

Ejemplo:

```text
05:58
10:01
10:34
14:03
```

El sistema no deberá reducir físicamente estas cuatro marcaciones a únicamente:

```text
entrada
salida
```

El Motor de Jornada determinará cuáles participan en el resultado y con qué interpretación.

---

## 22. REGLAS PARAMETRIZABLES

No deberán codificarse como constantes universales reglas susceptibles de variar por:

- vigencia;
- empresa;
- centro;
- turno;
- política;
- normativa.

Entidad conceptual:

```text
RuleSet
RuleVersion
```

Cada cálculo deberá poder identificar la versión de reglas utilizada.

Los requisitos deberán clasificarse cuando corresponda como:

```text
LEGAL_CONFIRMED
REGULATORY_CONFIRMED
COMPANY_POLICY
TECHNICAL_BEST_PRACTICE
ARCHITECTURAL_DECISION
PENDING_NORMATIVE
```

Una recomendación técnica no deberá presentarse como obligación legal si no existe fundamento confirmado.

---

## 23. TIEMPO EXTRAORDINARIO

El dominio deberá distinguir conceptualmente:

```text
worked_minutes
scheduled_minutes
overtime_detected_minutes
overtime_authorized_minutes
```

El hecho de que una persona permanezca después de su horario programado no implica por sí mismo que todo ese tiempo constituya tiempo autorizado para nómina.

El Motor podrá detectar.

La política administrativa podrá validar.

Nómina consumirá posteriormente el resultado autorizado correspondiente.

Las reglas normativas exactas deberán ser parametrizables y permanecer sujetas a validación jurídica y normativa.

---

## 24. INCIDENCIAS

Las incidencias estarán separadas de `PunchEvent`.

Entidad conceptual:

```text
AttendanceIncident
```

Una incidencia podrá afectar el resultado calculado o validado sin modificar las marcaciones originales.

---

## 25. AJUSTES ADMINISTRATIVOS

Las modificaciones realizadas por RH no deberán ejecutarse modificando directamente una marcación original.

Entidad conceptual:

```text
AttendanceAdjustment
```

Conceptualmente deberá poder identificar:

```text
attendance_record
adjustment_type
previous_value
new_value
reason
created_by
approved_by
created_at
```

El diseño físico definitivo queda pendiente.

---

## 26. AUDITORÍA

Entidad conceptual:

```text
AuditEvent
```

Las acciones administrativas significativas deberán ser auditables.

Conceptualmente deberá poder reconstruirse:

```text
actor
action
entity
record
previous_value
new_value
timestamp
reason
origin
correlation_id
```

La auditoría append-only se adopta como decisión arquitectónica y control técnico.

No deberá describirse automáticamente como requisito textual de STPS mientras no exista fundamento normativo específico confirmado.

---

## 27. VERSIONAMIENTO DE CÁLCULOS

Un reprocesamiento no deberá borrar silenciosamente el resultado anterior.

Entidad conceptual:

```text
AttendanceCalculationVersion
```

Deberá poder distinguirse entre:

```text
RECALCULATE
```

y:

```text
ALTER CLOSED HISTORY
```

Un cálculo actualizado podrá convertirse en vigente, manteniendo trazabilidad de las versiones anteriores cuando corresponda.

---

## 28. CICLO DE VIDA DE LA JORNADA

Estados conceptuales posibles:

```text
OPEN
CALCULATED
REVIEW_REQUIRED
ADJUSTED
VALIDATED
CLOSED
```

No todas las jornadas deberán recorrer obligatoriamente todos los estados.

Ante una ambigüedad relevante el sistema deberá preferir:

```text
REVIEW_REQUIRED
```

antes de inventar una interpretación.

---

## 29. CIERRE DE PERIODOS

Se distinguirán conceptualmente:

- cierre de jornada;
- cierre administrativo;
- cierre de periodo;
- bloqueo asociado a nómina.

Cuando un resultado ya haya sido utilizado por nómina, una corrección posterior deberá seguir un procedimiento explícito.

No deberá sobrescribirse silenciosamente el resultado previamente utilizado.

---

## 30. TIMEZONE

Cada `Workplace` deberá poder tener un timezone IANA.

Ejemplos:

```text
America/Mexico_City
America/Tijuana
America/Cancun
```

No deberá asumirse un timezone único global para toda la plataforma.

---

## 31. POLÍTICA DE TIMESTAMPS

Una marcación deberá conservar conceptualmente información suficiente para distinguir:

```text
device/source timestamp
source timezone
occurred_at_utc
server received_at_utc
```

Se conservarán tanto la hora reportada por el dispositivo como la hora de recepción del servidor.

La política definitiva de confianza entre ambas permanece parametrizable.

El sistema no deberá depender exclusivamente de la hora local de la PC cliente.

---

## 32. CLOCK DRIFT

El sistema deberá poder detectar diferencias significativas entre:

```text
source timestamp
```

y:

```text
server received timestamp
```

Un desfase anormal podrá generar alerta o revisión.

No deberá implicar automáticamente la eliminación del evento.

---

## 33. CONTRATO LECTOR → API

El contrato será neutral respecto al fabricante.

Objeto conceptual:

```text
PunchEvent
```

El contrato deberá transportar información suficiente para identificar:

```text
event_uuid
device
external_worker_reference
source timestamp
timezone/offset
capture method
external event reference cuando exista
metadata técnica necesaria
```

La identidad interna del trabajador podrá ser resuelta por el servidor mediante `DeviceWorkerIdentity`.

Los nombres JSON, endpoints y esquema físico definitivo serán establecidos en la especificación del API.

---

## 34. SEGURIDAD

Cada dispositivo deberá poseer identidad propia o una identidad técnica equivalente.

La arquitectura deberá permitir:

- autenticación;
- autorización;
- revocación;
- rotación de credenciales cuando aplique;
- protección contra replay;
- TLS;
- least privilege;
- logging técnico.

El mecanismo concreto de autenticación NO queda congelado en V1.0.

En particular, este documento NO obliga todavía a utilizar:

```text
JWT
OAuth
API Key
mTLS
```

La selección se realizará durante el diseño de seguridad del contrato físico.

---

## 35. DATOS BIOMÉTRICOS

El dominio de jornada no necesita conocer imágenes o templates biométricos para calcular asistencia.

La separación conceptual será:

```text
BIOMETRIC SYSTEM
       ↓
IDENTIFICATION
       ↓
PunchEvent
```

El `PunchEvent` podrá indicar que el método de identificación fue biométrico sin transportar obligatoriamente la plantilla biométrica.

La custodia de datos biométricos será tratada como un boundary de seguridad independiente.

---

## 36. MULTIDISPOSITIVO

No deberá asumirse:

```text
1 trabajador = 1 dispositivo
```

ni:

```text
1 centro = 1 dispositivo
```

Una jornada podrá recibir eventos provenientes de dispositivos diferentes cuando la política organizacional lo permita.

---

## 37. CHECADA EN OTRO CENTRO

La arquitectura permitirá políticas configurables para un trabajador que registre asistencia desde un centro diferente al principal.

Posibles políticas futuras:

```text
ALLOW
ALLOW_WITH_ALERT
ASSIGNMENT_REQUIRED
DENY
```

La política definitiva no se congela en V1.0.

---

## 38. PAUSAS

El modelo deberá permitir representar pausas y segmentos de jornada.

Permanece pendiente definir, según política aplicable:

- si requieren marcación;
- si pueden derivarse;
- si son pagadas;
- si son no pagadas;
- si dependen del turno;
- cómo pueden corregirse.

No se congela en V1.0 una única política universal de pausas.

---

## 39. TURNO PARTIDO

La arquitectura deberá soportar turnos con más de un segmento de trabajo durante una misma jornada lógica.

La representación física definitiva se resolverá durante el diseño del Motor de Jornada.

---

## 40. DOBLE TURNO

La arquitectura permitirá que un trabajador posea más de una jornada programada relacionada con la misma fecha calendario.

No deberá determinar automáticamente si dos turnos consecutivos constituyen:

- una jornada extendida;
- dos jornadas;
- cobertura;
- tiempo extraordinario;

únicamente por proximidad horaria.

---

## 41. SHADOW MODE

La transición oficial será progresiva.

Conceptualmente:

```text
               CHECADA
                  │
        ┌─────────┴─────────┐
        ▼                   ▼
       V1                  V2
        │                   │
resultado oficial     resultado sombra
        │                   │
        └─────────┬─────────┘
                  ▼
             CONCILIACIÓN
```

Durante esta etapa V2 no será la fuente oficial de nómina.

---

## 42. LEGACY BOUNDARY

Las tablas actuales:

```text
op_rh_personal_asistencia
op_rh_personal_asistencia_incidencia
```

no serán eliminadas durante las primeras fases.

El modelo V2 no deberá construirse alrededor de estas tablas.

Existirá conceptualmente:

```text
V2
 ↓
LegacyAdapter
 ↓
legacy
```

`LegacyAdapter` será el boundary autorizado para traducir resultados cuando posteriormente sea necesario.

---

## 43. PAYROLL BOUNDARY

Inicialmente:

```text
Payroll Source = Legacy
```

V2 funcionará como fuente sombra.

El Motor de Jornada no deberá calcular directamente importes monetarios de nómina.

Debe permanecer desacoplado el:

```text
cálculo laboral de jornada
```

del:

```text
cálculo económico de nómina
```

La transición de fuente oficial requerirá autorización explícita futura.

---

## 44. CONCILIACIÓN LEGACY / V2

Durante shadow mode deberá poder compararse conceptualmente:

```text
trabajador
fecha laboral
resultado legacy
resultado V2
diferencia
clasificación
```

Posibles categorías podrán incluir:

```text
MATCH
SHIFT_DIFFERENCE
PUNCH_DIFFERENCE
INCIDENT_DIFFERENCE
OVERTIME_DIFFERENCE
LEGACY_LIMITATION
REVIEW_REQUIRED
```

Los nombres físicos definitivos se decidirán posteriormente.

---

## 45. GATEWAY DE INGESTA

Un Gateway central delante de Legacy y V2 NO forma parte obligatoria de Architecture Freeze V1.0.

Podrá evaluarse en el futuro.

La primera implementación podrá utilizar una arquitectura más simple:

```text
DISPOSITIVO
    ↓
CLIENTE / LECTOR
    ↓
COLA LOCAL
    ↓
API V2
```

La decisión de introducir posteriormente un Gateway deberá justificarse y aprobarse mediante cambio arquitectónico.

---

## 46. MODELO CONCEPTUAL CONSOLIDADO

Las entidades conceptuales oficiales de V1.0 son:

```text
Company
Workplace

Worker
WorkerWorkplaceAssignment

Device
DeviceWorkerIdentity

PunchEvent
TransmissionReceipt

ShiftTemplate
ShiftSegment
ShiftAssignment
ScheduledShift

AttendanceRecord
AttendancePunchAssignment

AttendanceIncident
AttendanceAdjustment
AttendanceCalculationVersion

RuleSet
RuleVersion

AuditEvent

PayrollPeriod
AttendancePeriodClose

LegacyAdapter
```

Esta lista representa conceptos de dominio.

NO implica automáticamente:

```text
1 entidad = 1 tabla
```

La decisión física corresponde a la siguiente fase.

---

## 47. PENDIENTES NORMATIVOS

Todo requisito no confirmado deberá permanecer identificado como:

```text
PENDING_NORMATIVE
```

Entre los elementos pendientes se encuentran, cuando no exista todavía fundamento oficial confirmado:

- formato técnico definitivo requerido para entrega a autoridad;
- mecanismo específico de exportación;
- requisitos específicos de firma o sello;
- requisitos técnicos de integridad;
- periodos regulatorios específicos de conservación;
- disposiciones secundarias aplicables;
- excepciones;
- requisitos específicos de dispositivos;
- detalles normativos sobre tiempo extraordinario que requieran validación.

Estos pendientes no deberán convertirse unilateralmente en reglas del software.

---

## 48. DECISIONES CONGELADAS V1.0

Se consideran decisiones arquitectónicas aprobadas:

```text
A01 Evolución incremental.

A02 Legacy continúa operativo durante la transición.

A03 PunchEvent representa el hecho original.

A04 PunchEvent no se sobrescribe por reinterpretaciones.

A05 El lector no calcula la jornada definitiva.

A06 El contrato de ingesta es independiente del fabricante.

A07 Workplace es la abstracción genérica de centro de trabajo.

A08 Las asignaciones poseen historial y vigencia.

A09 ScheduledShift representa una jornada programada concreta.

A10 work_date utiliza como base la fecha de inicio del turno.

A11 Se soportan jornadas que cruzan medianoche.

A12 event_uuid constituye la base de idempotencia V2.

A13 La adquisición debe soportar operación offline mediante cola durable.

A14 Se conservan timestamp de dispositivo y timestamp de servidor.

A15 Motor de Jornada y nómina permanecen desacoplados.

A16 AttendanceRecord y PunchEvent son conceptos diferentes.

A17 Las intervenciones de RH se representan mediante ajustes separados.

A18 Las acciones administrativas significativas son auditables.

A19 V2 inicia en shadow mode.

A20 Legacy continúa como fuente oficial de nómina inicialmente.

A21 Las reglas deberán estar versionadas.

A22 Los cálculos deberán permitir versionamiento/reprocesamiento controlado.

A23 Los requisitos normativos no confirmados permanecen parametrizables.

A24 Debe existir una resolución explícita entre identidad externa del dispositivo y Worker.

A25 Gateway no es requisito obligatorio de V1.0.
```

---

## 49. DECISIONES PENDIENTES

Permanecen pendientes para fases posteriores:

```text
P01 Política exacta de trusted time.

P02 Diseño físico de DeviceWorkerIdentity.

P03 Ventanas exactas de asociación PunchEvent → ScheduledShift.

P04 Política definitiva de pausas.

P05 Representación física de turnos partidos.

P06 Tratamiento administrativo de dobles turnos.

P07 Política empresarial para checadas en otro centro.

P08 Ciclo exacto de aprobación RH.

P09 Procedimiento de reapertura después de cierre.

P10 Momento de cutover de nómina.

P11 Implementación física de TransmissionReceipt.

P12 Posible Gateway futuro.

P13 Reglas exactas de tiempo extraordinario.

P14 Requisitos secundarios de STPS pendientes de confirmación.

P15 Mecanismo concreto de autenticación dispositivo → API.
```

Ninguna decisión pendiente deberá resolverse unilateralmente durante implementación.

---

## 50. ORDEN DE IMPLEMENTACIÓN FUTURA

Cuando el propietario del producto autorice desarrollo, el orden conceptual será:

```text
FASE 0
Baseline y protección del sistema legacy.

FASE 1
Device + DeviceWorkerIdentity + PunchEvent.

FASE 2
API de ingesta + idempotencia + timestamps + cola offline.

FASE 3
Validación de captura V2 en shadow mode.

FASE 4
ShiftTemplate + ShiftAssignment + ScheduledShift.

FASE 5
Motor de Jornada V2 en shadow mode.

FASE 6
Versionamiento, incidencias, ajustes y auditoría.

FASE 7
Conciliación V1 / V2.

FASE 8
Panel y reportes V2.

FASE 9
LegacyAdapter y pre-nómina.

FASE 10
Cutover controlado, únicamente después de autorización explícita.

FASE 11
Retiro selectivo de componentes legacy cuando no existan consumidores dependientes.
```

Cada fase deberá tener pruebas y gate de aceptación antes de avanzar.

---

## 51. PRIMER SLICE FUTURO

Cuando se autorice implementación, la primera capacidad V2 deberá centrarse en conservar de manera confiable las marcaciones originales.

Conceptualmente:

```text
Device Registry
+
DeviceWorkerIdentity
+
PunchEvent
+
API de ingesta
+
event_uuid
+
source timestamp
+
server received timestamp
+
timezone
+
cola offline
+
auditoría técnica de recepción
```

Todavía no deberá:

- reemplazar nómina;
- eliminar tablas legacy;
- reemplazar reportes oficiales;
- automatizar completamente tiempo extraordinario;
- sustituir incidencias legacy;
- realizar cutover.

---

## 52. GOBERNANZA DEL ARCHITECTURE FREEZE

Este documento no podrá modificarse unilateralmente por el proyecto API ni por el proyecto Lector.

Para modificar una decisión congelada deberá existir una propuesta de cambio.

Conceptualmente:

```text
RFC
 ↓
ANÁLISIS API
 ↓
ANÁLISIS LECTOR
 ↓
IMPACTO LEGACY
 ↓
APROBACIÓN
 ↓
NUEVA REVISIÓN
```

Cambios compatibles podrán producir:

```text
V1.1
V1.2
```

Cambios arquitectónicos incompatibles podrán requerir:

```text
V2.0
```

según el alcance approved.

---

## 53. ESTADO FINAL

```text
ARCHITECTURE_FREEZE_VERSION:
V1.0

ARCHITECTURE_STATUS:
FROZEN

API_SCOPE_DEFINED:
YES

LECTOR_SCOPE_DEFINED:
YES

RAW_PUNCH_MODEL_DEFINED:
YES

DEVICE_IDENTITY_MODEL_DEFINED:
YES

WORKPLACE_MODEL_DEFINED:
YES

SHIFT_MODEL_DEFINED:
YES

CROSS_MIDNIGHT_MODEL_DEFINED:
YES

IDEMPOTENCY_STRATEGY_DEFINED:
YES

OFFLINE_STRATEGY_DEFINED:
YES

AUDIT_MODEL_DEFINED:
YES

LEGACY_COMPATIBILITY_DEFINED:
YES

PAYROLL_TRANSITION_DEFINED:
YES

PENDING_DECISIONS_EXIST:
YES

PENDING_NORMATIVE_ITEMS_EXIST:
YES

IMPLEMENTATION_AUTHORIZED:
NO

IMPLEMENTATION_STARTED:
NO
```

---

## 54. CIERRE

Checador V2 se construirá bajo el principio:

```text
CAPTURAR EL HECHO.
PRESERVAR EL HECHO.
INTERPRETARLO DE FORMA VERSIONADA.
AUDITAR LAS INTERVENCIONES.
VALIDAR EL RESULTADO.
TRANSICIONAR SIN ROMPER LEGACY.
```

Hasta recibir autorización explícita de implementación:

```text
DO_NOT_IMPLEMENT_YET:
YES
```
