# Batch de jornadas — persistencia independiente Level 2

**Decisión del propietario:** aprobada para implementación, 2026-11-27. La aprobación no activa batch ni autoriza ejecución operativa en PREPROD.

Una evaluación Level 2 válida y respaldada por evidencia es un registro independiente. Si la candidatura pasa el preflight inicial, la evaluación puede persistirse y conservarse aunque la candidatura posteriormente sea bloqueada para J1. Ese registro no afirma que la jornada se procesó, cerró o evaluó administrativamente. Si el preflight inicial marca BLOCKED, batch no debe escribir Level 2, jornadas ni resultados J1/J2.

Cuando cambian las entradas, J1 debe reevaluar su elegibilidad y el procesamiento debe recuperarse en un ciclo posterior. La reutilización de Level 2 requiere estación física, ventana UTC, política, generaciones y vigencia de evidencia compatibles; evidencia obsoleta no se reutiliza. No se eliminan evaluaciones para simular atomicidad ni se modifica el historial. J1/J2 preservan huellas, revisiones e idempotencia. La decisión no ordena una transacción global Level 2/J1/J2.

Base: `docs/architecture/CHECADOR_V2_LEVEL2_INGESTION_CONTRACT_V1.1.md` §§10.5, 11.1–11.3 y 12.2; `CHECADOR_V2_JOURNEY_PAIRING_CONTRACT_V1.1.md` §§cierre y competencia. No modifica Architecture Freeze ni contratos congelados. La evidencia y la recuperación bajo concurrencia deben comprobarse con pruebas MariaDB aisladas antes de habilitar batch en PREPROD.
