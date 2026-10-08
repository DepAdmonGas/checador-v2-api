# CHECADOR V2 — MASTER SCHEDULE 2026

**Project:** Checador V2  
**Final Deadline:** 2026-12-31  
**Target Code Freeze:** 2026-12-18  
**Overall Schedule Status:** GREEN  

---

## 1. SCHEDULE GOVERNANCE RULES

1. **No Silent Changes:** Dates cannot be altered without recording the explicit deviation and reason.
2. **Status Colors:**
   - `GREEN`: On track, no blockers.
   - `YELLOW`: Minor delay or risk identified; mitigation plan active.
   - `RED`: Critical blocker or deadline at risk; immediate intervention required.
3. **Execution Gate:** No activity marked as `NOT_STARTED` or `NOT_AUTHORIZED` may begin implementation until explicitly approved.

---

## 2. DETAILED MASTER SCHEDULE TRACKER

| Activity ID | Task Description | Owner | Start Date | Due Date | Status | Expected Result | Acceptance Criteria | Blockers | Actual Completion |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **GOV-001** | Documentación y gobierno baseline (AGENTS.md, Status, Schedule, Changelog) | API + Lector | 2026-09-29 | 2026-10-02 | COMPLETE | Baseline documental congelada y sincronizada | Archivos creados, SHA256 verificado | None | 2026-09-29 |
| **UX-01** | Rediseño de UI del Lector Windows | Lector | 2026-09-29 | 2026-09-29 | COMPLETE | Modernización UI de Lector | Build PASS, QA Visual PASS, 22/22 PASS | None | 2026-09-29 |
| **V2.2-HF01** | Corrección de parseo de timestamp UTC Backoff en Lector | Lector | 2026-09-29 | 2026-09-29 | COMPLETE | Corrección de comparación `next_attempt_at` en UTC | Build PASS, 22/22 PASS | None | 2026-09-29 |
| **V2.3-A01** | Auditoría READ-ONLY del modelo actual de horarios y turnos legacy | API | 2026-10-05 | 2026-10-09 | READY_TO_START | Informe de brechas de modelo de turnos | Documento entregado sin modificar código | GOV-001 Sign-off | Pending |
| **V2.3-A02/A03**| Diseño y aprobación del módulo Shift Scheduling V2 | API | 2026-10-12 | 2026-10-16 | NOT_STARTED | Especificación física aprobada de turnos | RFC aprobado por ambas partes | V2.3-A01 Sign-off | Pending |
| **V2.3-A04/A07**| Implementación y pruebas de Shift Scheduling V2 | API (Primary), Lector (Reg) | 2026-10-19 | 2026-10-30 | NOT_STARTED | Módulo de asignación de turnos V2 funcional | Tests unitarios y de integración PASS | V2.3-A02 Sign-off | Pending |
| **V2.4-P1** | Motor de Jornada — Asociación PunchEvent / ScheduledShift | API | 2026-11-02 | 2026-11-13 | NOT_STARTED | Pareamiento de marcaciones crudas con turnos | Algoritmo determinista probado en comparación paralela sin afectar nómina | V2.3 Complete | Pending |
| **V2.4-P2** | Motor de Jornada — Interpretación, horas extras, turnos nocturnos | API (Primary), Lector (Real) | 2026-11-16 | 2026-11-27 | NOT_STARTED | Cálculo completo de asistencia en comparación paralela sin afectar nómina | Conciliación de resultados vs Legacy | V2.4-P1 Complete | Pending |
| **V2.5** | Ajustes administrativos, incidencias y auditoría | API | 2026-11-30 | 2026-12-04 | NOT_STARTED | Módulo de justificaciones e incidencias V2 | Bitácora inmutable verificada | V2.4 Complete | Pending |
| **V2.6** | Legacy & Payroll Parallel Comparison Adapter | API | 2026-12-07 | 2026-12-11 | NOT_STARTED | Adaptador de sincronización en comparación paralela sin afectar nómina hacia Nómina | Cero alteración de cálculos legacy | V2.5 Complete | Pending |
| **HARDENING** | Seguridad, performance, observabilidad, regresión completa | API + Lector | 2026-12-14 | 2026-12-18 | NOT_STARTED | Code Freeze alcanzado con suite completa | All tests PASS, Replay hardening evaluated | All Slices Done | Pending |
| **FINAL_VAL** | Comparación paralela sin afectar nómina final, rollback test, decisión técnica | API + Lector | 2026-12-21 | 2026-12-24 | NOT_STARTED | Informe final de preparación para Cutover | Decisión formal de producción | Hardening PASS | Pending |
| **CONTINGENCY**| Periodo de contingencia y cierre anual de desarrollo | API + Lector | 2026-12-28 | 2026-12-31 | NOT_STARTED | Estabilidad del sistema y documentación de cierre | Cierre de proyecto exitoso | Final Sign-off | Pending |
