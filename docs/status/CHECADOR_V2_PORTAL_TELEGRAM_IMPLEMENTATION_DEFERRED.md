# Checador V2 — Portal + Telegram Implementation Status

**PORTAL_TELEGRAM_ARCHITECTURE:** FROZEN  
**PORTAL_TELEGRAM_IMPLEMENTATION:** FROZEN_DEFERRED

**REASON:** LEGACY_TELEGRAM_CHATBOT_PENDING_RECOVERY_AND_REUSE_AUDIT  
**LEGACY_REUSE_PREFERENCE:** YES

## Decision

Implementation of the Portal + Telegram punch channel is deferred. A
pre-existing Telegram personnel chatbot/channel handled personnel
functionality such as alerts and/or token delivery, but its relevant
source/version is not available in the current checkout. Recover and audit
that implementation before building a second Telegram subsystem. Prefer
reuse and hardening of the existing channel where compatible.

This status changes implementation status only. It does not invalidate,
rewrite, or replace the frozen architecture, addenda, physical model, or
command API contract.

## Authorization

**IMPLEMENTATION_AUTHORIZED:** NO  
**TELEGRAM_MIGRATIONS_AUTHORIZED:** NO  
**TELEGRAM_DATABASE_CHANGES_AUTHORIZED:** NO  
**PRODUCTION_CHANGES_AUTHORIZED:** NO

Until explicit reactivation, do not implement Telegram punch UI, OTP
delivery, association migration, enrollment/replacement flows, Telegram
API adapters or bot/webhook/configuration changes, or Telegram-specific
database changes.

This deferment does not block separately authorized non-Telegram Checador
V2 work. It grants no implementation authorization for that work.

## Reactivation gate

**REACTIVATION_REQUIRES:**
`CHECADOR_V2_LEGACY_TELEGRAM_CHATBOT_REUSE_FORENSIC`

Recover the legacy chatbot source/version and complete a read-only forensic
audit before reopening implementation. The audit must establish its source
provenance, bot/webhook/service architecture, secret handling, user-to-
Telegram association and `op_token_telegram` semantics, token lifecycle,
chat resolution, enrollment/revocation, notifications, authorization,
audit/logging, concurrency/idempotency, tests, security posture, and
compatibility with Portal3 identity and sessions and the frozen V1.0
contracts.

Do not decide reuse, adapter, partial reuse, or replacement until that
source has been inspected. If incompatibility with a frozen contract is
found, prepare a narrow amendment proposal and obtain explicit approval
before changing the contract or implementation.
