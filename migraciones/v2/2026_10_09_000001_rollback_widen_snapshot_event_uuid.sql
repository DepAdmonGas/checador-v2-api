-- NON_REVERSIBLE_BY_AUTOMATIC_SCHEMA_NARROWING
-- This migration implements the currently frozen event_uuid VARCHAR(64) contract.
-- Automatic schema narrowing is prohibited. Recovery requires explicit operator
-- authorization under the then-current contract and data state. Automatic data
-- truncation or rewrite is not permitted.
--
-- Intentionally fail closed: this file does not perform a schema or data change.

SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Automatic rollback prohibited: frozen event_uuid VARCHAR(64) contract, explicit operator authorization is required. No automatic narrowing, truncation, or rewrite is permitted.';
