-- The Workplace timezone authority is a frozen production contract.
-- Automatic DROP is intentionally prohibited once mappings may exist.
-- Rollback is fail-closed and requires explicit operator authorization under
-- the then-current contract and data state.

SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Automatic rollback prohibited: Workplace timezone authority is forward-only; explicit operator authorization is required.';
