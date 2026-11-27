# Checador V2 — Pareo de jornada J1 V1.1

Estado: reglas funcionales autorizadas por el propietario; implementación y validación pendientes. Esta revisión no modifica J1 V1.0 ni Architecture Freeze.

J1 clasifica hechos originales de un trabajador por jornada y segmento sin exigir selección de entrada o salida al trabajador. Conserva todos los eventos y sus revisiones. J2 calcula métricas técnicas; las decisiones administrativas, tolerancias, incidencias y nómina permanecen fuera de J1.

La ventana técnica de búsqueda no concede por sí sola una entrada. La entrada ordinaria puede comenzar diez minutos antes del segmento, inclusive. Deben preservarse los eventos anteriores y examinar su posible pertenencia a otra jornada antes de rechazarlos como entrada. La primera marcación válida antes o durante el segmento puede ser entrada incluso después del punto medio. Un único evento posterior al fin es salida sin entrada si no existe conflicto; ante competencia entre jornadas o segmentos prevalece la ambigüedad.

Eventos físicos diferentes del mismo trabajador y segmento dentro de cinco minutos de la primera marcación del grupo son posibles repeticiones; no son retransmisiones idempotentes y todos se conservan. El límite de grupo se mide desde el primer evento. La asignación de eventos entre segmentos debe ser exclusiva.

Con entrada inequívoca, la última marcación independiente en los sesenta minutos anteriores al fin y en la ventana técnica posterior es candidata prioritaria de salida. Una salida anterior puede clasificarse al cierre y con sincronización confirmada, sin convertir una repetición en salida; antes de ello permanece provisional cuando corresponda. Los eventos intermedios conservan su asociación. Segmentos divididos y nocturnos se resuelven por intervalos completos, evitando usar el mismo evento en posiciones incompatibles y conservando la fecha de inicio de la jornada.

No se inventan marcaciones, faltas, horas trabajadas para segmentos incompletos ni horas extra autorizadas. La llegada tardía y los cambios de Level 2 requieren reevaluación; la huella debe incluir versión del algoritmo, parámetros efectivos y entradas relevantes, conservando revisiones históricas y evitando duplicados.

Los parámetros de 10, 5 y 60 minutos se adoptan inicialmente por decisión del propietario. Su evolución por localidad y fecha requiere incorporar versiones efectivas al mecanismo M11 y a la huella; no debe aplicarse una versión futura a jornadas históricas. La tolerancia de retardo y el plazo de incidencia siguen sujetos a sus contratos administrativos y M06. Esta revisión no habilita resultados administrativos oficiales ni modifica la política de nómina.

## Cierre y competencia ratificados

El cierre definitivo exige, conjuntamente, que la hora UTC confiable alcance el fin de la ventana técnica de la jornada, que Level 2 verifique íntegramente el intervalo UTC pertinente para la estación física y que no existan conflictos de asignación sin resolver. La ventana técnica posterior es de cuatro horas. Una coincidencia de fecha civil no acredita cobertura. Antes del cierre se permiten resultados provisionales compatibles con los estados existentes; evidencia UNKNOWN o INCOMPLETE no acredita cierre.

La competencia debe considerar jornadas anteriores y posteriores, materializadas o no, su fecha lógica, segmentos, adscripción y políticas efectivas. No se resuelve por orden de ejecución. Una marcación inequívoca se asigna a una sola posición; posiciones incompatibles no resueltas producen AMBIGUOUS_PAIRING. Una posible entrada de la jornada siguiente no invalida por sí sola una salida inequívoca de la anterior.

La identidad de evaluación V1.1 incorpora algoritmo, política versionada y parámetros, jornada y segmentos, eventos candidatos, estado Level 2, cierre y conflictos. Incluye transiciones de estado, no la hora actual cambiante. La implementación y validación de estas decisiones siguen pendientes.

## Parámetros de política ratificados — 26/11/2026

El propietario aprueba `early_entry_allowed_minutes` de 0 a 10, `duplicate_punch_window_minutes` de 1 a 10 y `exit_priority_window_minutes` de 30 a 120 minutos, exclusivamente enteros. Cero entrada anticipada comienza en el inicio programado. Los tres valores deben existir conjuntamente; una política incompleta o fuera de rango no activa V1.1 ni permite degradación silenciosa a V1.0. Una versión histórica con los tres valores NULL no contiene política V1.1. La política se resuelve por localidad canónica y fecha lógica de jornada; una versión futura no se aplica retroactivamente.
