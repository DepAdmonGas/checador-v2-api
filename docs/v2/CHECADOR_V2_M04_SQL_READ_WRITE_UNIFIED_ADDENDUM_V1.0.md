# CHECADOR V2 — M04 SQL Read/Write Unified Addendum V1.0

**Status:** Approved by the Portal3 owner for M04 on 2026-11-19, with Fedora-only and Plesk amendments approved 2026-11-23. Production/Plesk activation gates remain mandatory.
**Applies to:** Portal3 and Checador V2 API; both repositories must contain byte-identical copies.
**Scope:** M04 station-template catalog/detail reads, segments, create, station customization, immutable versioning, audit, and readback.
**Does not change:** Architecture Freeze V1.0, J1/J2 semantics, Lector, M03 assignments, M11, or API ingestion and calculation responsibilities.

## 1. Binding transport and ownership

Portal3 reads M04 templates, exact versions, segments, lineage, and audit-relevant confirmation directly from canonical MariaDB through Portal3's existing institutional SQL connection. Portal3 does not communicate with API V2 over HTTP for any M04 read, readback, or mutation. The browser communicates only with Portal3; it never connects to MariaDB or API V2.

After the exclusive cutover in §7, Portal3 is the sole administrative writer of station-scoped M04 templates, using the existing V2 tables. API V2 retains device ingestion, persistence integrity, interpretation, and journey calculations. API V2 does not accept station-template administrative mutations after cutover. Its GET routes may remain for other consumers; Portal3 never invokes them.

Portal3 must not duplicate API-owned calculations or rewrite original punch events. A missing or unavailable persisted projection remains unavailable; it is not a reason to call API over HTTP or synthesize a result.

## 2. Contract clauses reconciled

This addendum supersedes or supplements only M04 template behavior in these clauses:

| Source | Clause | M04 treatment |
|---|---|---|
| `CHECADOR_V2_SHIFT_SCHEDULING_SERVICE_API_CONTRACT_V1.0.md` | §4.1 template catalog/detail GETs and create/version POSTs; §6.1 template ownership/versioning | For Portal3 M04, all reads and writes use the SQL boundaries in this agreement. API route definitions may remain for non-Portal3 GET consumers; station-template mutation routes reject without mutation after cutover. M03 §4.2 is unchanged. |
| `CHECADOR_V2_M03_M04_LOCAL_TEMPLATES_AND_HABITUAL_TEMPORARY_SCHEDULE_ADDENDUM_V1.0.md` | §§2, 5, 6, 7 | Supplemented only for M04 template persistence and transport. M03 assignment ownership and permissions are unchanged. |
| `CHECADOR_V2_M04_GLOBAL_TEMPLATE_STATION_CUSTOMIZATION_ADDENDUM_V1.0.md` | §§3–6 | The Portal3 SQL writer creates local copies and lineage; the GLOBAL source remains immutable. Existing uniqueness, provenance, and recovery requirements remain. |
| `CHECADOR_V2_PREPRODUCTION_TARGET_DEFINITION_V1.0.md` | §5.4 | A narrow M04 station-template exception applies only after target-specific approval and cutover. All other direct Portal3 V2 writes remain governed by their own contracts. |

No clause outside this list is amended. The Architecture Freeze remains byte-for-byte unchanged.

## 3. GLOBAL templates and allowed operations

GLOBAL templates and their segments are read-only in M04 station administration. M04 cannot create, edit, version, activate, or deactivate a GLOBAL template. The allowed station operations are:

1. Create a new station template at version 1.
2. Append an immutable next version to a station template.
3. Create a station-local customization from an exact, authorized GLOBAL version, preserving its source lineage.

No operation changes assignments or rewrites historical versions.

## 4. Authorization and scope

Every mutation requires an active authenticated Portal3 user, valid CSRF, effective `checador.turnos.estacion.administrar`, Encargado position 6, and the user's own server-resolved canonical station scope. The handler rechecks authorization and scope; client-supplied actor, station, hidden fields, or modal state confer no authority. Explicit user-level denial overrides a position grant.

Dirección remains read-only. Autolavado department 9 does not inherit Palo Solo station 2 permissions and has no write authority under this agreement. Any future Autolavado writer requires a separately approved permission and scope contract.

## 5. Atomicity, versioning, audit, idempotency, and readback

Writes use the existing MariaDB V2 schema; this agreement authorizes no DDL, migration, or data repair. Each create, customization, or version operation is one InnoDB transaction covering the template row, all ordered segments, lineage when applicable, and mandatory audit. Any validation, uniqueness, audit, or concurrency failure rolls back the complete operation.

The writer serializes M04 mutations by locking the canonical `op_rh_localidades` row for the authorized station. A cryptographically random idempotency key and canonical payload digest are recorded in the same audit transaction. A replay with the same key and digest returns the existing persisted result; reuse with a different digest conflicts. Existing unique keys remain the collision guard. No new writer may bypass this lock protocol. The audit table has no dedicated unique idempotency index; if the row lock, audit lookup capacity, JSON validity, or transaction behavior cannot provide the specified guarantee, M04 writes stay closed and a separately approved schema/contract revision is required.

Success is returned only after SQL readback confirms template identity, station scope, version, segments, and lineage. Confirmation cannot be assembled from the request payload or an in-memory insert list. Historical versions, GLOBAL rows, and existing assignment references remain unchanged.

## 6. Database principal and preflight

Before enabling writes in an environment, verify its actual runtime database and principal. Compare `SHOW CREATE TABLE`, engines, generated columns, unique indexes, foreign keys, audit JSON constraints, and lineage against the approved model. Verify effective SELECT/INSERT privileges for that exact principal and database. The normal least-privilege requirement remains: no UPDATE/DELETE capability over immutable template/history rows. The only exceptions are the explicitly scoped Fedora and Plesk targets in §§10–11; evidence from either target does not establish grants or readiness elsewhere.

Repository DDL, isolated fixtures, and a schema-only check do not establish runtime grants, user permissions, or a successful operational write.

## 7. Exclusive cutover and rollback

All Portal3 M04 write gates default closed. Cutover order is mandatory:

1. Install the identical approved addendum in both repositories and preserve its SHA-256.
2. Verify the target DDL, engines, indices, constraints, lock behavior, grants, and audit lookup capacity with read-only checks.
3. Deploy/prepare the Portal3 SQL code with its writer gate closed; verify every Portal3 M04 read and readback is SQL-only.
4. Close the three API station-template mutations: create, GLOBAL-to-station customization, and immutable version append. Their responses must prove rejection without mutation. Do not affect template GETs, authorized GLOBAL administration, M03 assignments, or other modules.
5. For Plesk and every non-Fedora target, independently verify the API cut, target connection/principal, schema/preflight, effective SELECT/INSERT grants, and Encargado station permission. Only then set the four Portal3 M04 attestations for that environment: `CHECADOR_M04_SQL_WRITER_ENABLED`, `CHECADOR_M04_API_WRITER_CLOSED`, `CHECADOR_M04_SQL_GRANTS_VERIFIED`, and `CHECADOR_M04_SQL_PREFLIGHT_VERIFIED`. These values record prior evidence; they are not substitutes for live connection identity and structural/grant preflight. The Plesk exception in §11 changes only acceptance of broad privileges and does not waive any attestation or the API cut.
6. Run the acceptance checks in §8. No simultaneous writers or HTTP fallback are permitted.
7. Rollback closes the Portal3 SQL writer first. API station writes remain closed until Portal3 is verified closed and outstanding operations are reconciled. Never delete or rewrite history as rollback.

## 8. Acceptance evidence

For each environment, verify authorized create, GLOBAL customization, immutable version append, exact segments, audit, idempotent replay/conflict, concurrency/version conflict, rollback on injected failure, SQL readback, F5 persistence, and denial of foreign station scope. Confirm Dirección cannot write, Encargado can write only its authorized station, GLOBAL remains unchanged, and Autolavado 9 does not inherit station 2 rights. Confirm Portal3 M04 makes no HTTP request to API and remains independent of API availability. Label isolated, local, and remote evidence separately.

## 9. Approval and environment boundary

The Portal3 owner approved this M04 agreement on 2026-11-19 and approved the Fedora and Plesk privilege exceptions on 2026-11-23. Those approvals accept the specific application/database privilege risks in §§10–11; they do not attest API runtime closure, effective PHP-FPM database identity, target schema, authenticated acceptance, or Plesk readiness. The four production attestations, live preflight, exclusive API cut, and user permission remain mandatory. No migration, SQL grant change, or deployment is authorized by this document.

## 10. Owner-approved Fedora development exception — 2026-11-23

The Portal3 owner expressly authorizes a local-only exception for `APP_ENV=dev`, `development`, or `local`, and only when the existing `default` MySQL connection is configured for a loopback host and both its configured database and live `SELECT DATABASE()` identity equal `bd_admongas`. The demo/read-only mode must remain disabled. `APP_ENV` by itself is never sufficient. No new SQL user, connection, credential, grant, table, or migration is authorized.

For this exact Fedora target, the four environment attestations listed in §7 are not required to open Portal3's SQL writer, and the existing broad grants on `default` are accepted. The live structural preflight remains mandatory and continues to verify the actual database identity, required tables/columns, InnoDB engines, generated `scope_id`, required indexes, foreign keys, and SQL JSON support. It also verifies required SELECT and INSERT privileges while allowing the accepted extra UPDATE/DELETE/ALL privileges. Authentication, Encargado permission, canonical locality, CSRF, confirmation, validation, transaction/row locking, idempotency, immutable-version write behavior, mandatory audit insertion, and persisted readback remain enforced by Portal3.

**Risk explicitly accepted for Fedora only:** MariaDB privileges do not enforce append-only history for this principal. A user or process with the same broad credentials could update or delete template/history rows outside this writer. The Portal3 writer itself continues to append new versions and never updates prior versions, but this is an application behavior rather than a database-enforced immutability guarantee. The owner also waives the four activation attestations for this local database only; this exception must not be used as evidence that API station-template writers are closed.

This exception does not apply to Plesk, preproduction, production, any remote host, any database other than `bd_admongas`, or any environment whose live database identity cannot be confirmed. Those targets remain subject to §§6–9, including the API cut, least-privilege verification, all four attestations, and target-specific approval. This amendment leaves the Architecture Freeze, J1/J2, API V2, and Lector unchanged and authorizes no deployment.

## 11. Owner-approved Plesk privilege exception — 2026-11-23

The Portal3 owner expressly approves use of the existing institutional `default` connection and its current effective SQL privileges for M04 on `portal3.admongas.com.mx`, without creating a SQL user or connection and without changing grants. The exception is limited to the live Portal3 vhost rooted at `/var/www/vhosts/admongas.com.mx/portal3.admongas.com.mx/Portal3`, with `APP_ENV=prod` or `production`, MySQL driver, configured and live database name exactly `bd_admongas_v2_preprod`, and a nonempty `CURRENT_USER()` whose account name matches the configured `default` username. Code must confirm these values from the active connection/request; neither APP_ENV nor the four environment attestations alone identify this target.

For that exact target only, the structural preflight may accept the current principal's additional UPDATE/DELETE/ALL privileges, while still querying effective grants and requiring the necessary SELECT and INSERT privileges. All existing structural checks remain mandatory: live database identity; required tables and columns; InnoDB; generated `scope_id`; required unique indexes and foreign keys; lineage; audit JSON support; and SQL availability. If the live vhost, project root, database, principal, schema, required privileges, or preflight cannot be confirmed, M04 writes stay closed. The four attestations in §7 remain required and must be set only after separate evidence for the exclusive API cut, grants, schema preflight, and Encargado permission has been recorded.

**Risk explicitly accepted for Plesk:** because the current SQL principal can perform UPDATE/DELETE/ALL, MariaDB cannot guarantee immutable history against that principal or another process using the same credentials. The Portal3 M04 writer remains required to append versions and never update or delete prior template, segment, lineage, or audit records. This is an application-level invariant, not a database-enforced one. The owner accepts this risk for the identified Plesk target only; no other server or database inherits the privilege exception.

This amendment does not waive the exclusive API V2 writer cut. The three API station-template mutations—create, GLOBAL customization, and version append—must be explicitly closed and independently verified to reject without mutation before the four Portal3 production attestations are enabled. It does not authorize an operational write test, DDL, grant changes, or deployment. Architecture Freeze V1.0, J1/J2, API responsibilities, and all unrelated modules remain unchanged.
