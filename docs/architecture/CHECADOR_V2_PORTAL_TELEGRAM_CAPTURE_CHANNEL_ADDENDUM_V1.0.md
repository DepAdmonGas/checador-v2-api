# CHECADOR V2 — PORTAL + TELEGRAM CAPTURE CHANNEL ADDENDUM V1.0

**Status:** Reconciled architecture addendum prepared for byte-identical publication  
**Version:** 1.0  
**Scope:** Portal3 command client + Checador API capture channel  
**Implementation Authorized:** NO

---

## 1. RELATION TO THE MAIN ARCHITECTURE FREEZE

This addendum supplements:

`CHECADOR_V2_ARCHITECTURE_FREEZE_V1.0.md`

It does not modify or replace the main Architecture Freeze. If a future implementation detail is not defined here, the main Freeze and the existing V2 API contracts remain authoritative.

This addendum does not authorize implementation, schema changes, migrations, production changes, payroll integration, legacy replacement, or general Portal3 API access.

The main Freeze remains unchanged with:

```text
Implementation Authorized: NO
```

---

## 2. CANONICAL ATTENDANCE POPULATION

`op_rh_personal` is the canonical company personnel/payroll master and defines the personnel population that participates in Checador.

`tb_usuarios` represents Portal3 accounts. It may include employees, external users, and users who need Portal3 for compliance or interaction with other institutions. A Portal3 account alone does not establish Checador participation.

For Portal capture:

- The authenticated Portal3 principal must resolve to exactly one valid canonical worker.
- That worker must exist in the canonical Checador personnel population, `op_rh_personal`.
- A user with no canonical worker mapping is not Checador personnel and cannot create a Portal punch or PunchEvent.
- Existing canonical V2 worker-status and Workplace-resolution contracts continue to apply. This addendum creates no new personnel-status flag.

Biometric/Lector capture remains available to eligible personnel independently of whether they have a Portal3 account.

---

## 3. PORTAL USER TO WORKER IDENTITY BRIDGE

An explicit, auditable identity relationship is required between:

```text
tb_usuarios.id
        ↕
op_rh_personal.id
```

No authoritative relationship was found in the inspected Portal3/API code and documentation. The physical model and ownership process remain to be designed; no physical table name is frozen here.

The relationship is an identity bridge only. It is not:

- an attendance permission;
- a role or module permission;
- a locality or Workplace policy;
- a work-context eligibility rule;
- a duplicate of employee master data.

Resolution is fail-closed:

```text
zero valid mappings
=> DENY

more than one simultaneously valid mapping for the Portal principal
=> DENY

exactly one valid mapping to a canonical worker
=> CONTINUE
```

The model must support effective history, revocation, audit provenance, and unambiguous resolution. It must not duplicate worker master data.

Never infer the relationship from:

- equal numeric IDs;
- names;
- email alone;
- phone alone;
- `no_colaborador`;
- `external_worker_reference`.

The employee must never choose or submit `op_rh_personal.id` from the browser.

Workers without a Portal3 account remain eligible for the unchanged biometric/Lector channel. Portal users without a canonical worker remain eligible for their unrelated Portal3 functions, but not Checador attendance.

---

## 4. NO ADDITIONAL PORTAL CAPTURE POLICY IN V1

For V1, canonical membership in `op_rh_personal` defines the attendance population. The identity bridge establishes which worker the authenticated Portal3 principal represents.

Do not introduce a separate general eligibility system such as:

- `portal_capture = yes/no`;
- `requires_punch = yes/no`;
- locality-specific capture permission;
- job-title-specific capture permission;
- work-context-specific capture eligibility.

Do not add a generic `requiere_checar` field that duplicates the canonical attendance population.

Do not treat Checador submenu access, `biometricos.leer`, job title, Portal role, or Telegram association as proof of worker identity or authorization to create a PunchEvent.

---

## 5. LOCATION AND WORK CONTEXT

V1 does not require the employee to select or classify the physical place where the punch is made.

Do not require selection of:

- `OFFICE`;
- `MAINTENANCE_FIELD`;
- `GESTORIA_PROCEDURE`;
- `EXTERNAL_MEETING`;
- `OTHER_AUTHORIZED`;
- a company locality or Workplace.

These labels are not V1 eligibility controls. A physical office, maintenance site, institution, external procedure, or meeting does not thereby become a new company Workplace.

GPS is not required.

If an event or downstream journey requires a canonical Workplace/locality, the backend resolves it from existing authoritative personnel, assignment, schedule, and V2 domain data according to the existing V2 contracts. Portal Capture must not add a user-maintained location configuration. If canonical resolution is unavailable, the backend follows the existing fail-closed/incomplete-data contract; it must not invent a locality or ask the employee to choose one.

---

## 6. CAPTURE SEMANTICS

The employee action is:

```text
Registrar marcación
```

V1 does not ask the employee to choose “Entrada” or “Salida.” It does not impose a four-punch maximum. P1/P2 interpret the preserved event sequence later.

The API server time is canonical. The employee cannot backdate the punch.

A successful operation creates an immutable V2 PunchEvent. The API, not Portal3, is responsible for creating it. Portal3 never writes V2 raw tables directly.

The capture origin/method must identify Portal + verified Telegram as a distinct server-controlled source. The API sets that provenance; it must not trust a browser-supplied capture method. The physical representation and exact API contract must be reconciled with the existing device-origin schema without fabricating a device identifier, device generation, or device sequence.

Telegram proves possession of the verified channel. It does not prove physical presence.

Incidences do not rewrite raw PunchEvents. Lector and biometric ingestion remain unchanged.

---

## 7. TELEGRAM DESTINATION AND CAPTURE CHALLENGE

### 7.1 Existing Telegram association

The existing user-to-chat association may be considered as a delivery destination only after its current verified state, revocation behavior, uniqueness, and governance have been reviewed for this use.

The browser must not supply the Telegram `chat_id`. Portal3 backend resolves the destination from the authenticated principal’s current verified association. Enrollment or replacement must not remove the current verified destination before the replacement has been verified and committed.

The existing short Telegram association token is not a punch OTP and must not be reused as one.

### 7.2 New capture OTP

The V1 capture challenge uses a new OTP:

- 8 decimal digits, generated uniformly with a cryptographically secure random-number generator;
- server-side validity of 3 minutes;
- no more than 5 failed attempts per challenge;
- one active challenge per worker;
- reissue/resend rotates the code and invalidates the prior code;
- request and confirmation rate limits are mandatory; exact thresholds belong to the API/security contract;
- persist only a protected HMAC/hash representation, never the plaintext OTP;
- compare safely and update attempt/consumption state atomically;
- atomically consume the challenge and create the PunchEvent;
- redact OTPs, hashes, credentials, tokens, and sensitive payloads from logs.

The OTP is delivered through the verified Telegram association by server-side code. It must not be returned to browser JavaScript, included in a browser response, or persisted/logged in plaintext.

### 7.3 Idempotency and retries

A repeated request with the same idempotency identity must not create a second challenge operation or PunchEvent.

If confirmation succeeds but its response is lost, Portal3 may recover only the result associated with that same idempotent operation and the same authenticated/delegated principal. A repeated confirmation must not create another event.

This result-recovery command is not a general PunchEvent read API.

---

## 8. NARROW PORTAL3-TO-API COMMAND BOUNDARY

Portal3 may act as a command client only for:

1. requesting or reissuing a Portal punch challenge;
2. confirming and consuming that challenge;
3. recovering the result of the same idempotent operation after response loss.

This boundary does not authorize:

- generic Portal3 API access;
- general PunchEvent reads;
- P1/P2 reads;
- V2.5/V2.6 reads;
- payroll operations;
- employee administration;
- device administration;
- direct writes by Portal3 to raw V2 tables.

The general Portal3 V2 read boundary remains:

```text
NOT_YET_AUTHORIZED
```

### 8.1 Trust requirements

The command boundary requires dedicated server-to-server authentication that:

- identifies Portal3 as the calling service;
- is scoped to the allowed command audience and operations;
- uses short-lived credentials and server-side secret/key ownership;
- is never exposed to the browser;
- requires the employee identity to be delegated separately.

The service credential alone must not imply an employee identity. The API independently validates the delegated Portal3 principal, confirms that the account is active, resolves exactly one valid identity bridge to a canonical worker, and applies the rules in this addendum.

This architecture does not freeze HS256 or any other signing mechanism. The exact credential and verification mechanism belongs to the API/security contract. Existing authentication infrastructure should be reused where safe, without broadening the current human-session verifier to grant these command privileges.

---

## 9. REQUIRED CONTRACT AND DATA-MODEL DESIGN BEFORE IMPLEMENTATION

Before implementation is authorized, the API/Portal technical design must specify:

- the explicit, effective, revocable, auditable Portal-principal-to-worker identity bridge;
- challenge lifecycle storage and safe attempt/resend handling;
- stable idempotency and same-operation result recovery;
- the authenticated command and delegated-principal contract;
- server-side Telegram destination resolution and delivery;
- the event provenance representation for Portal + Telegram;
- how a non-device-origin event is represented without fabricating device fields;
- integrity/seal behavior for all new canonical event fields;
- behavior when worker, Workplace/locality, mapping, Telegram association, or challenge resolution is unavailable.

Physical names, migrations, and implementation details are not established by this addendum.

---

## 10. NON-GOALS AND IMPLEMENTATION GATE

This addendum does not modify:

- the main Architecture Freeze;
- the biometric/Lector channel;
- device authentication or ingestion;
- general V2 read authorization;
- legacy attendance or payroll behavior;
- production configuration or data.

Until separate explicit implementation authorization is given:

```text
DO_NOT_IMPLEMENT_YET:
YES
```
