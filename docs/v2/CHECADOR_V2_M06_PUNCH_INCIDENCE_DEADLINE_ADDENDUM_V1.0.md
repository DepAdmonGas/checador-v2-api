# Checador V2 — M06 Punch Incidence Deadline Addendum V1.0

**Status:** Authorized for local development implementation under `CHECADOR_V2_M11_M06_CONFIGURATION_IMPLEMENTATION_REMEDIATION_V2` and the user's explicit deadline clarification on 2026-11-03  
**Applies to:** Portal3 and API V2, local Fedora development database `bd_admongas` only  
**Does not revise:** Architecture Freeze V1.0, P2/Journey calculation, Lector, payroll, or P3G-C/D lifecycle permissions

## 1. Authorized policy values

- `retardo_tolerance_minutes`: integer 0–120. Zero means no additional grace period.
- `incidence_deadline_days`: integer 0–30 natural days.
- Preserve each valid legacy locality value as the initial reference. Do not substitute a new default or edit the legacy table.
- Position 13, Dirección de Operaciones, may read and create prospective versions for authorized localities. Gerente is read-only only after an actual identity/ACL position is verified; no such position is currently mapped. No broad `biometricos.editar` grant is authorized.

## 2. Deadline semantics

The local work/check date is day zero and is not counted. For a policy of N natural days, the last permitted instant is 23:59:59 on `check_date + N calendar days`, in the locality timezone effective for the check date. N=0 therefore ends at 23:59:59 on the check date. At any instant after that cutoff, the API must reject a new ordinary punch-related incidence. Requests received on time remain eligible to continue through their already-authorized review after the cutoff. No automatic or manual reopening is authorized.

The policy version is selected by the locality and check date. The server stores that policy-version reference with the submitted incidence/check link; later policy versions do not change prior cutoffs. Where no V2 policy version covers the check date, API returns a distinct unversioned-policy error and does not infer an historical V2 policy from the current legacy reference.

## 3. Incidence/check link and categories

The user authorizes punch-related cases for entry, meal exit, meal return, final exit, lateness, and omitted punches. Portal3/API represent one such case using a dedicated `PUNCH_ATTENDANCE` incidence category with a single check date, a check kind (`ENTRY`, `LUNCH_EXIT`, `LUNCH_RETURN`, `FINAL_EXIT`), and a nature (`LATENESS`, `OMISSION`). This declaration remains a pending incidence; it is not an official lateness, omission, or Journey evaluation.

When nature is `LATENESS`, the case must include the source PunchEvent's device ID and event UUID. API verifies the immutable source event belongs to the same worker, maps through its physical station's unique `numlista` to the requested canonical locality, and occurred on the declared local check date. It does not calculate or attest that the event is late. For `OMISSION`, no PunchEvent UUID is supplied; the durable check link is worker + canonical locality + local check date + expected check kind + bound policy version. API does not fabricate a PunchEvent or infer the omission as an official result.

Vacation, disability, other leave/permissions, and official missions remain separate categories with their existing rules and do not use this punch-deadline policy.

## 4. Persistence and safety boundary

The append-only P3G master and revision stay canonical. An additive association row links the punch-attendance incidence to its declared daily check and the exact policy version; it does not edit or duplicate a PunchEvent. The association records the timezone resolved for the check date and the computed local cutoff, so later configuration changes cannot alter the already-determined deadline. The association is created in the same database transaction as the incidence and its initial revision. API readback returns the link and policy reference. Idempotent replay includes the same check data; divergent reuse is rejected.

The API validates the deadline, category, check date, locality, worker, event reference (when applicable), dedicated P3G-B ACL and current locality scope on every create. Portal3 additionally requires the independent `CHECADOR_P3G_B_POSTS_ENABLED` development gate. No global POST gate is enabled.

This addendum does not authorize auto-created incidences, lifecycle actions, final approval/rejection/revocation, Journey evaluation, J2, payroll effects, modifications/deletions of original punches, or legacy dual-write.
