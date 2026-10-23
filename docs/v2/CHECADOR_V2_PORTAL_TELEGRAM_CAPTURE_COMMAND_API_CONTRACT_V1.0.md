# CHECADOR V2 — PORTAL + TELEGRAM CAPTURE COMMAND API CONTRACT V1.0

**Status:** FROZEN V1.0
**Implementation Authorized:** NO
**Database Changes Authorized:** NO
**Production Changes Authorized:** NO
**General Portal3 Read:** `NOT_YET_AUTHORIZED`

## 1. Scope and responsibility split

Portal3 is the narrow server-to-server command client and owns resolving the verified Telegram destination, server-side access to `chat_id`, revalidating the association before delivery, and sending the OTP. The browser never supplies trusted `chat_id`.

The API validates service and delegated principal, resolves the exact identity bridge and active worker, owns operation/challenge/OTP/idempotency, confirms the command, creates the raw PunchEvent, and computes HMAC Envelope V1. The API does not send Telegram messages and never receives or returns `chat_id`.

## 2. Authentication and eligibility

Every command requires HTTPS, dedicated Portal3 service authentication, and independently validated delegated human identity. The account must have `tb_usuarios.estatus = 0`; exactly one effective bridge must identify the worker; `op_rh_personal.estado = 1` is required.

At operation creation the API stores the exact `id_worker_identity` row alongside `id_usuario` and `id_personal`. At confirmation it revalidates that same row: still effective/ACTIVE, belongs to the delegated user, and resolves to the stored worker; the worker must remain active. If the mapping changed or was revoked, fail closed without a PunchEvent. Never silently switch to a replacement bridge.

## 3. Request and response controls

- HTTPS, JSON UTF-8, server timestamps in UTC.
- Each logical request includes an opaque `Idempotency-Key` and `X-Correlation-ID`; only a purpose-separated hash/HMAC of the key is persisted.
- API rejects browser-supplied worker ID, device ID, external worker reference, capture method, hardware timestamp, or trusted `chat_id`.
- Any response containing plaintext OTP uses `Cache-Control: no-store`; body is excluded from logs, APM, and tracing.
- Plaintext OTP is never persisted and never returned to the browser.

## 4. Operation and idempotency semantics

`POST /api/v2/portal-capture/operations` creates or retrieves the idempotent operation only; it does not create a challenge.

Initial response semantics:

```text
status: OPERATION_CREATED
next_action: CHALLENGE_REQUIRED
```

This does not claim a challenge generation exists. A retry with the same operation key retrieves that operation and its current state.

The operation table enforces at most one open operation per worker using `active_worker_key`, generated from `id_personal` only when state is `CHALLENGE_REQUIRED` or `CHALLENGE_PENDING`. Those are the exact open states; `CONFIRMED`, `EXPIRED`, and `CANCELLED` are terminal.

Challenge constraints:

```sql
UNIQUE (id_operacion, idempotency_key_hash)
UNIQUE (id_operacion, generacion)
```

- Retry operation creation with the same key: same operation.
- Retry challenge with the same key: same generation, no code rotation and no second OTP disclosure.
- Explicit resend: new key, lock operation, next generation, prior challenge superseded atomically.
- Same challenge key in a different operation is allowed; scope is `id_operacion`.
- Concurrent resends serialize on the operation row; only the latest committed generation remains active.
- Same-key insertion race recovers the existing challenge row rather than inserting another.
- Repeated successful confirmation returns the same receipt and never creates another event.

## 5. OTP generation and Portal3 delivery

OTP is eight decimal digits, generated uniformly using a CSPRNG, valid for three minutes, and limited to five failed attempts per challenge. Persist only protected HMAC verifier; use constant-time verification. `hmac_key_id VARCHAR(32)` identifies the server-side HMAC/pepper key version for controlled rotation. Do not persist plaintext OTP, secret key, pepper, or reusable credential.

The API returns plaintext OTP exactly once to the authenticated Portal3 backend in the initial challenge response. It records the one-time disclosure before returning; an idempotent retry does not reveal the OTP again. If Portal3 loses that response before delivery, it requests explicit resend with a new key; the old challenge is superseded and a new OTP generated.

Portal3 re-resolves and revalidates the current verified Telegram destination, then sends the code. On delivery failure it requests a resend using a new key. OTP is never browser-visible. API receives no `chat_id` or association reference. At most one operation is open per worker and at most one challenge is active per operation.

## 6. Create or retrieve operation

`POST /api/v2/portal-capture/operations`

Example body:

```json
{"request":"REGISTER_PUNCH"}
```

Requires service authentication, delegated principal, `Idempotency-Key`, and `X-Correlation-ID`. Initial response is `202 Accepted` with `operation_uuid`, `OPERATION_CREATED`, and `CHALLENGE_REQUIRED`. A retry returns the same operation and its current state. No OTP or challenge generation is implied by operation creation.

States:

- `CHALLENGE_REQUIRED`, `CHALLENGE_PENDING`: open;
- `CONFIRMED`, `EXPIRED`, `CANCELLED`: terminal.

## 7. Issue or resend challenge

`POST /api/v2/portal-capture/operations/{operation_uuid}/challenge`

Initial issuance requires `CHALLENGE_REQUIRED`. An explicit resend requires a new idempotency key. Under an operation-row lock, supersede the prior active challenge, allocate the next generation, insert the new active challenge, and keep operation state `CHALLENGE_PENDING` atomically.

Initial response includes operation UUID, generation, expiry, state, and OTP to Portal3 backend only. It never includes chat ID. A retry with the same key returns existing generation/state without rotation, a second Telegram-triggering OTP disclosure, or plaintext OTP. If delivery cannot proceed, use explicit resend with a new key.

## 8. Confirm

`POST /api/v2/portal-capture/operations/{operation_uuid}/confirm`

Body:

```json
{"code":"12345678"}
```

API resolves the unique active challenge; the browser does not send generation. Validate operation, OTP, expiry, attempts, principal, and the exact bridge row stored at operation creation. Failed attempts update atomically; the fifth failure locks the challenge.

On success, a single transaction consumes the challenge, inserts the raw event, closes the operation as `CONFIRMED`, and stores `id_raw_punch` plus bounded `receipt_json` on the operation row.

Portal event fields:

```text
id_dispositivo = NULL
referencia_externa_personal = NULL
source_timestamp_raw = NULL
source_timezone_reported = NULL
metodo_captura = PORTAL_TELEGRAM_VERIFIED
ocurrido_at_utc = server UTC
recibido_at_utc = server UTC
```

Success response is `201 Created` and means only `PUNCH RECORDED`; it does not assert attendance or journey completeness. Repeated confirmation returns the persisted receipt.

## 9. Recover the same result

`POST /api/v2/portal-capture/operations/{operation_uuid}/result`

Returns only the receipt stored on that operation, to the same authorized service/principal. It does not search by worker/date, list events, or retrieve another operation. There is no separate result table and this is not a general PunchEvent read API.

## 10. Source completeness and downstream safety

Portal event authenticity does not prove source completeness. Without sufficient evidence, `PORTAL_TELEGRAM_VERIFIED` has completeness `UNKNOWN`. P1 persists applicable sources, references, evidence, and contract versions; P2 propagates the P1 source-completeness state and finality. `UNKNOWN` and `INCOMPLETE` cannot be final. V2.5 remains an additive safety gate and V2.6 is unchanged.

## 11. Errors, redaction, and authorization boundary

| Condition | HTTP | Meaning |
|---|---:|---|
| Operation/challenge accepted | 202 | Command accepted |
| Idempotent retry/result recovery | 200 | Same operation/result |
| Punch recorded | 201 | Event created |
| Invalid JSON/fields | 400 | Invalid request |
| Invalid service/principal | 401 | Unauthorized |
| Inactive account, bridge, or worker | 403 | Denied without sensitive disclosure |
| Operation unavailable/out of scope | 404 | Not found |
| Key/payload/state conflict | 409 | Conflict |
| Rate limit | 429 | Rate limited |
| Dependency unavailable | 503 | Safe retry with same key |

Do not reveal account, worker, or Telegram association enumeration. Never log OTP, HMAC verifier, key material, credentials, tokens, `chat_id`, or response body containing OTP.

```text
GENERAL_PORTAL3_READ_BOUNDARY: NOT_YET_AUTHORIZED
IMPLEMENTATION_AUTHORIZED: NO
DATABASE_CHANGES_AUTHORIZED: NO
MIGRATIONS_AUTHORIZED: NO
PRODUCTION_CHANGES_AUTHORIZED: NO
```
