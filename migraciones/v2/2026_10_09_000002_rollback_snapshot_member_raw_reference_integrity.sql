-- NON_REVERSIBLE_BY_AUTOMATIC_CONSTRAINT_REMOVAL
-- Removing the FK and UNIQUE constraints would weaken the frozen Level 1
-- physical-model guarantees. Recovery requires explicit operator authorization.
-- This rollback intentionally performs no schema or data change.

SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'Automatic rollback prohibited: removing the FK/UNIQUE constraints would weaken the frozen Level 1 integrity contract. No automatic schema rollback is permitted.';
