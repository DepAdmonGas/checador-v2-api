# AGENTS.md — Checador V2 API

## 1. PROPÓSITO

Este archivo define las reglas obligatorias para cualquier agente de IA,
Codex o asistente técnico que trabaje dentro de este proyecto.

Proyecto:
Checador V2 — API

Stack actual:
- PHP 8.x nativo
- MySQL
- mysqli
- routing procedural mediante index.php

Este proyecto forma parte de una arquitectura compartida con el proyecto Lector.

## 2. DOCUMENTOS OBLIGATORIOS

Antes de analizar, proponer o modificar código relacionado con Checador V2,
debes leer:

1. docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md
2. docs/status/CHECADOR_V2_IMPLEMENTATION_STATUS.md
3. docs/planning/CHECADOR_V2_MASTER_SCHEDULE_2026.md
4. docs/v2/PUNCH_EVENTS_INGESTION_API.md
5. docs/changelog/CHECADOR_V2_API_CHANGELOG.md

Orden de autoridad:
1. Architecture Freeze
2. contrato físico aprobado
3. Implementation Status
4. Master Schedule
5. instrucciones específicas de la tarea
6. Changelog

Si existe contradicción:
DETENTE.

Reporta:
ARCHITECTURE_CONFLICT

o:
API_CONTRACT_CONFLICT

según corresponda.

No improvises una nueva arquitectura.

## 3. ARCHITECTURE FREEZE

El archivo:
docs/architecture/CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md

está congelado.

No modificarlo salvo autorización explícita del usuario.
No reinterpretarlo unilateralmente.
No implementar cambios arquitectónicos no aprobados.

## 4. IMPLEMENTATION STATUS

El archivo:
docs/status/CHECADOR_V2_IMPLEMENTATION_STATUS.md

indica:
- qué slices están terminados;
- qué actividad está actualmente autorizada;
- qué sigue;
- qué todavía no está autorizado.

Antes de iniciar trabajo nuevo, verifica:
CURRENT_PHASE
CURRENT_ACTIVITY
STATUS
IMPLEMENTATION_AUTHORIZED

No avanzar automáticamente al siguiente slice.

## 5. MASTER SCHEDULE

El archivo:
docs/planning/CHECADOR_V2_MASTER_SCHEDULE_2026.md

define fechas, actividades, responsables y gates.

No mover fechas silenciosamente.
Si una actividad se retrasa, registrar la desviación.

Estados de calendario:
GREEN
YELLOW
RED

Fecha límite del proyecto:
2026-12-31

Target Code Freeze:
2026-12-18

## 6. ESTADO ACTUAL

Actualmente se considera implementado y validado:

V2.1 — API Raw Punch Ingestion
- Device
- DeviceCredential
- DeviceWorkerIdentity
- PunchEvent
- endpoint de ingesta V2
- autenticación Bearer + Bcrypt
- idempotencia
- timestamps
- timezone
- resolución histórica
- shadow mode

Pruebas:
18 / 18 PASS

Integración real con Lector:
PASS

El sistema legacy continúa operativo.

## 7. ENDPOINT V2 ACTUAL

Endpoint público actual:
POST https://portal3.admongas.com.mx/portal-api-v.1.0.1/api/v2/punch-events

Contrato físico:
docs/v2/PUNCH_EVENTS_INGESTION_API.md

No modificar el contrato unilateralmente.

## 8. LEGACY PROTEGIDO

No modificar comportamiento legacy salvo autorización explícita.

Especialmente:
- op_rh_personal
- op_rh_personal_acceso
- op_rh_personal_asistencia
- op_rh_personal_asistencia_incidencia
- nómina
- cron existentes
- reportes existentes
- endpoints legacy

Checador V2 opera actualmente en:
SHADOW_MODE

## 9. REGLA DE PunchEvent

PunchEvent representa un hecho original.

Nunca debe interpretarse directamente como:
- entrada
- salida
- retardo
- falta
- horas extra
- incidencia
- resultado de nómina

No sobrescribir PunchEvent para reinterpretarlo.

## 10. CAMBIOS DE BASE DE DATOS

Antes de crear tablas:
1. inspeccionar estructuras existentes;
2. justificar por qué no pueden reutilizarse;
3. utilizar migraciones aditivas;
4. proporcionar rollback;
5. evitar cambios destructivos.

No ejecutar migraciones productivas sin autorización explícita.

## 11. PRODUCCIÓN

Distinguir siempre:
CODE_IMPLEMENTED

de:
PRODUCTION_DEPLOYED

Una implementación local no autoriza despliegue.

## 12. SEGURIDAD

Nunca almacenar:
- secrets en texto plano;
- templates biométricos en PunchEvent;
- passwords en logs;
- Authorization headers completos.

Actualmente:
DEVICE_AUTH:
Bearer Secret + Bcrypt + TLS

HTTP_REPLAY_PROTECTION:
DEFERRED_SECURITY_HARDENING

No declarar como implementado algo que está diferido.

## 13. CAMBIOS MÍNIMOS

No aprovechar una tarea para:
- refactorizar todo el proyecto;
- renombrar estructuras sin necesidad;
- actualizar dependencias no relacionadas;
- modernizar legacy;
- cambiar convenciones generales.

Mantener diffs pequeños y revisables.

## 14. PRUEBAS

Todo nuevo slice debe incluir:
- sintaxis/lint;
- pruebas específicas;
- regresión relacionada;
- validación de legacy cuando aplique.

Distinguir:
NEW_FAILURE

de:
PREEXISTING_FAILURE

No ocultar errores.

## 15. CHANGELOG

Después de completar una actividad aprobada del API,
actualizar:
docs/changelog/CHECADOR_V2_API_CHANGELOG.md

Registrar:
- fecha;
- slice/activity;
- archivos creados;
- archivos modificados;
- DB/migraciones;
- pruebas;
- correcciones;
- pendientes.

No reescribir historial previo.

## 16. STATUS COMPARTIDO

Cuando una actividad cambie el estado global del proyecto,
proponer actualización de:
docs/status/CHECADOR_V2_IMPLEMENTATION_STATUS.md

Ese documento debe mantenerse idéntico en API y Lector.

No modificarlo unilateralmente.

## 17. ACTIVIDAD ACTUAL

Consultar siempre:
docs/status/CHECADOR_V2_IMPLEMENTATION_STATUS.md

El siguiente bloque previsto es:
V2.3 — Shift Scheduling

Su implementación todavía no debe comenzar hasta que la actividad correspondiente
esté explícitamente autorizada.

## 18. REGLA FINAL

Si no sabes si algo está autorizado:
NO LO IMPLEMENTES.

Primero:
- inspecciona;
- informa;
- propone;
- espera autorización.
