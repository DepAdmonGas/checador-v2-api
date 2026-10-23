# CHECADOR V2 — PORTAL + TELEGRAM CAPTURE PHYSICAL MODEL V1.0

**Status:** FROZEN V1.0
**Implementation Authorized:** NO
**Database Changes Authorized:** NO
**Production Changes Authorized:** NO
**General Portal3 Read:** `NOT_YET_AUTHORIZED`

## 1. Governance and scope

This document is additive to the Architecture Freeze, Portal + Telegram Capture Channel Addendum, Portal Source Completeness Addendum, and applicable V2 contracts. It does not replace approved decisions or authorize implementation, migrations, production changes, or general Portal3 reads.

`op_rh_personal` is the canonical personnel population. A worker is active when `op_rh_personal.estado = 1`. A Portal account is active when `tb_usuarios.estatus = 0`.

## 2. Raw event and Portal-compatible nulls

The existing raw table is `op_rh_v2_marcaciones_crudas`; its primary key is signed `INT`. Its current schema makes `id_dispositivo`, `referencia_externa_personal`, and `source_timestamp_raw` NOT NULL. The additive Portal delta makes each nullable.

A Portal event stores:

```text
id_dispositivo = NULL
referencia_externa_personal = NULL
source_timestamp_raw = NULL
source_timezone_reported = NULL
id_personal_resuelto = mapped op_rh_personal.id
metodo_captura = PORTAL_TELEGRAM_VERIFIED
```

`source_timestamp_raw` means only the exact timestamp reported by hardware/Lector. Portal has no such value. The API server supplies UTC `ocurrido_at_utc` and `recibido_at_utc`. Never invent an external worker reference, device, generation, sequence, hardware timestamp, or telemetry. HMAC Envelope V1 preserves `NULL` distinctly from `0` and `""`.

## 3. Level 1 snapshot membership

The existing table is `op_rh_v2_read_snapshot_members`:

| Column | Current definition |
|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`, primary key |
| `id_snapshot` | `BIGINT UNSIGNED NOT NULL`, FK to snapshot, `ON DELETE CASCADE` |
| `member_order` | `INT NOT NULL` |
| `id_raw_punch` | `BIGINT UNSIGNED NOT NULL` |
| `id_dispositivo` | `INT NOT NULL` |
| `event_uuid` | `VARCHAR(36) NOT NULL` |
| `ocurrido_at_utc` | `DATETIME NULL` |

Current constraints are UNIQUE (`id_snapshot`, `member_order`) and index (`id_snapshot`, `ocurrido_at_utc`, `id_dispositivo`, `event_uuid`, `id`). There is no raw-event FK or per-snapshot raw-ID uniqueness. Raw PK is signed `INT`, unlike current `id_raw_punch`. The reader joins `id_raw_punch` to `raw.id` and pages by materialized `member_order`. The current manifest identity uses device/event/time, not raw PK. Serialization must not cast `NULL` device to `0`.

Additive compatibility delta:

1. Retain `id_raw_punch`; do not add a duplicate raw-ID column.
2. Change it to signed `INT NOT NULL`.
3. Add FK to `op_rh_v2_marcaciones_crudas.id ON DELETE RESTRICT` and UNIQUE (`id_snapshot`, `id_raw_punch`).
4. Change `id_dispositivo` to `INT NULL` and add `metodo_captura VARCHAR(32) NOT NULL`.
5. Preserve snapshot FK and UNIQUE (`id_snapshot`, `member_order`). Use cursor index (`id_snapshot`, `ocurrido_at_utc`, `id_dispositivo`, `event_uuid`, `id_raw_punch`).
6. Before enforcing constraints, validate every existing member joins a raw row, cached values match, and IDs fit signed `INT`; reconcile existing data verifiably. Do not rewrite historical manifests.
7. A new manifest version records exact `id_raw_punch`, method, nullable device, UUID, and occurrence time per member. Old manifests retain historical meaning.

JSON must preserve `"id_dispositivo": null`. This compatibility does not authorize general Portal3 access to Level 1.

## 4. Portal-to-worker identity bridge

`op_rh_v2_portal_worker_identity`:

- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`;
- `id_usuario INT NOT NULL`, `id_personal INT NOT NULL`;
- `valido_desde_utc DATETIME NOT NULL`, `valido_hasta_utc DATETIME NULL`;
- `estado ENUM('ACTIVE','REVOKED') NOT NULL`;
- `asignado_por INT NOT NULL`, `motivo VARCHAR(255) NOT NULL`;
- `creado_at_utc DATETIME NOT NULL`, `revocado_at_utc DATETIME NULL`;
- FKs to `tb_usuarios.id` and `op_rh_personal.id` (signed `INT`);
- generated nullable `active_user_key INT`, `CASE WHEN estado='ACTIVE' THEN id_usuario ELSE NULL END`;
- generated nullable `active_worker_key INT`, `CASE WHEN estado='ACTIVE' THEN id_personal ELSE NULL END`;
- UNIQUE (`active_user_key`) and UNIQUE (`active_worker_key`).

Thus each active Portal user maps to at most one worker and each active worker to at most one Portal user. Historical `REVOKED` rows remain. Activation/replacement/revocation serializes affected user and worker identities. This bridge is identity only, not an eligibility flag or authorization grant. Never infer it from matching IDs, names, email, phone, `no_colaborador`, or `external_worker_reference`.

## 5. Telegram association

`op_rh_v2_portal_telegram_associations`:

- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`;
- `id_usuario INT NOT NULL`, `chat_id BIGINT NOT NULL`;
- `estado ENUM('PENDING','VERIFIED','REVOKED','REPLACED') NOT NULL`;
- `verificado_at_utc DATETIME NULL`, `valido_desde_utc DATETIME NULL`, `valido_hasta_utc DATETIME NULL`;
- `reemplaza_id BIGINT UNSIGNED NULL`, self-FK to `id ON DELETE RESTRICT`;
- `creado_at_utc DATETIME NOT NULL`, FK `id_usuario` to `tb_usuarios.id`;
- generated `active_user_key INT NULL`: `CASE WHEN estado='VERIFIED' THEN id_usuario ELSE NULL END`;
- generated `active_chat_key BIGINT NULL`: `CASE WHEN estado='VERIFIED' THEN chat_id ELSE NULL END`;
- UNIQUE (`active_user_key`) and UNIQUE (`active_chat_key`).

`VERIFIED` means currently effective. A replacement may reference the row it replaces; the first association has `reemplaza_id = NULL`. The old row remains verified while a replacement is pending. Then, atomically, old becomes `REPLACED` and new becomes `VERIFIED`; audit is preserved. Portal3 owns enrollment, replacement, destination resolution, revalidation, and delivery. Browser never supplies trusted `chat_id`. The API neither accepts nor returns `chat_id`, and does not persist an association reference. Do not modify `op_token_telegram`.

## 6. Capture operations

`op_rh_v2_portal_capture_operations`:

- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`, `operation_uuid CHAR(36) NOT NULL UNIQUE`;
- `id_usuario INT NOT NULL`, `id_personal INT NOT NULL`;
- `id_worker_identity BIGINT UNSIGNED NOT NULL`, FK to bridge `id ON DELETE RESTRICT`;
- `idempotency_key_hash BINARY(32) NOT NULL`, `request_fingerprint BINARY(32) NOT NULL`;
- `estado ENUM('CHALLENGE_REQUIRED','CHALLENGE_PENDING','CONFIRMED','EXPIRED','CANCELLED') NOT NULL`;
- `event_uuid VARCHAR(64) NOT NULL UNIQUE`;
- generated `active_worker_key INT NULL`: `CASE WHEN estado IN ('CHALLENGE_REQUIRED','CHALLENGE_PENDING') THEN id_personal ELSE NULL END`;
- `id_raw_punch INT NULL UNIQUE`, FK to signed raw `id`;
- `receipt_json JSON NULL`, `creado_at_utc DATETIME NOT NULL`, `expira_at_utc DATETIME NOT NULL`, `confirmado_at_utc DATETIME NULL`;
- FKs from `id_usuario` and `id_personal` to their signed-`INT` canonical IDs;
- UNIQUE (`id_usuario`, `idempotency_key_hash`) and UNIQUE (`active_worker_key`).

Only `CHALLENGE_REQUIRED` and `CHALLENGE_PENDING` are open; the other states are terminal. The generated unique key allows at most one open operation per worker. At operation creation the API stores the exact bridge row with the user/worker snapshot. At confirmation it revalidates that same row, user, worker and active worker state; if changed or revoked, fail closed without a PunchEvent and do not switch to a newer bridge. The idempotency key is never stored in plaintext. The bounded receipt contains no OTP, secrets, or `chat_id`. There is no separate result table.

## 7. Challenges

`op_rh_v2_portal_capture_challenges`:

- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`, `id_operacion BIGINT UNSIGNED NOT NULL`;
- `generacion INT UNSIGNED NOT NULL`, `idempotency_key_hash BINARY(32) NOT NULL`;
- `challenge_hmac BINARY(32) NOT NULL`, `hmac_key_id VARCHAR(32) NOT NULL`;
- `estado ENUM('ACTIVE','CONSUMED','SUPERSEDED','EXPIRED','LOCKED') NOT NULL`;
- `intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0`;
- `creado_at_utc DATETIME NOT NULL`, `expira_at_utc DATETIME NOT NULL`, `consumido_at_utc DATETIME NULL`, `otp_revealed_at_utc DATETIME NULL`;
- generated `active_operation_key BIGINT UNSIGNED NULL`: `CASE WHEN estado='ACTIVE' THEN id_operacion ELSE NULL END`;
- FK `id_operacion` to operations `id ON DELETE RESTRICT`;
- UNIQUE (`id_operacion`, `idempotency_key_hash`), UNIQUE (`id_operacion`, `generacion`), UNIQUE (`active_operation_key`).

The operation enforces one open challenge owner per worker; the challenge unique key allows one active challenge per operation. No `id_personal` is duplicated. A resend locks the operation, increments generation and supersedes the prior challenge atomically. `hmac_key_id` identifies the server-side HMAC/pepper key version for controlled rotation; never store plaintext OTP, secret key, pepper, or reusable credential.

## 8. P1 source-completeness persistence

The existing `op_rh_v2_pareos_jornada` header has: `id BIGINT UNSIGNED` PK; `id_jornada_programada BIGINT UNSIGNED`; `id_personal INT`; `id_estacion INT`; `fecha_jornada_local DATE`; `revision_numero INT UNSIGNED`; `version_algoritmo VARCHAR(32)`; `estado_completitud_l2 VARCHAR(32)`; `estado_pareo VARCHAR(32)`; `fingerprint_ejecucion VARCHAR(64) NULL`; `supercedido_por_id BIGINT UNSIGNED NULL`; `creado_at_utc DATETIME`. It has UNIQUE (`id_jornada_programada`, `revision_numero`), worker/date, station/completeness and fingerprint indexes, and a journey FK. It has no `evaluation_ref`; Level 2 owns `op_rh_v2_level2_window_evaluations.evaluation_ref VARCHAR(64) UNIQUE`.

Add nullable fields to preserve historical revisions without reinterpretation:

- `evaluation_ref VARCHAR(64) NULL`, Level 2-only FK to the Level 2 `evaluation_ref`;
- `source_completeness_status ENUM('VERIFIED','INCOMPLETE','UNKNOWN') NULL`;
- `source_completeness_ref VARCHAR(64) NULL UNIQUE`;
- `applicable_sources_json JSON NULL`;
- `source_evidence_json JSON NULL`;
- `source_completeness_contract_version VARCHAR(32) NULL`.

New source-aware P1 revisions persist aggregate state, distinct source reference, applicable source set, per-source evidence references and contract versions. Historical rows remain null in new fields. `evaluation_ref` is never replaced by the source-completeness reference.

## 9. P2 propagation and finality

The existing `op_rh_v2_calculos_jornada` header has: `id BIGINT UNSIGNED` PK; `id_jornada_programada BIGINT UNSIGNED`; `id_pareo_jornada BIGINT UNSIGNED`; `id_personal INT`; `id_estacion INT`; `fecha_jornada_local DATE`; `revision_numero INT UNSIGNED`; `version_algoritmo_calculo VARCHAR(32)`; `estado_calculo VARCHAR(32)`; `segundos_programados_totales INT UNSIGNED NULL`; `segundos_trabajados_totales INT UNSIGNED NULL`; `segundos_candidatos_fuera_horario INT UNSIGNED NULL`; `fingerprint_ejecucion VARCHAR(64)`; `creado_at_utc DATETIME`. It has unique revision/fingerprint keys, P1, worker/date and status indexes, and FKs to journey/P1.

Add nullable fields for historical compatibility:

- `source_completeness_status ENUM('VERIFIED','INCOMPLETE','UNKNOWN') NULL`;
- `source_completeness_ref VARCHAR(64) NULL`, FK to the unique P1 source reference;
- `source_completeness_contract_version VARCHAR(32) NULL`;
- `estado_finalidad ENUM('FINAL','REVIEW_REQUIRED','DATA_INCOMPLETE') NULL`.

New P2 revisions copy status, reference and version from the exact immutable P1 revision. `UNKNOWN` requires `REVIEW_REQUIRED`; `INCOMPLETE` requires `DATA_INCOMPLETE`; `FINAL` is allowed only for `VERIFIED` and when all other P2 finality gates pass. Fields are inserted into the append-only revision, not updated later. Detailed evidence is reproducible through the referenced P1 revision. Existing rows are not reinterpreted. V2.5 remains an additive safety gate and cannot infer absence from uncertain completeness. V2.6 is unchanged.

## 10. Audit, database compatibility, and authorization

`op_rh_v2_portal_capture_audit` is append-only and records operation, actor, event type, prior/new state, reason, correlation, UTC timestamp, and redacted metadata. It never records OTP, secret HMAC, credentials, tokens, or `chat_id`.

The logical model targets the documented MySQL 5.7+ / MariaDB 10.3+ families. Exact generated-column/index syntax and existing-data compatibility require a version-specific preflight; no production version is assumed.

```text
GENERAL_PORTAL3_READ_BOUNDARY: NOT_YET_AUTHORIZED
IMPLEMENTATION_AUTHORIZED: NO
DATABASE_CHANGES_AUTHORIZED: NO
MIGRATIONS_AUTHORIZED: NO
PRODUCTION_CHANGES_AUTHORIZED: NO
```
