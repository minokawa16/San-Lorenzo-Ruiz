-- Phase 4: canonical monetary storage while preserving malformed legacy text.
ALTER TABLE confirmation_records
  ADD COLUMN stipend_amount DECIMAL(10,2) NULL AFTER stipend_cents;

UPDATE confirmation_records
SET stipend_amount = CASE
    WHEN stipend_pesos REGEXP '^[0-9]+(\\.[0-9]{1,2})?$' THEN CAST(stipend_pesos AS DECIMAL(10,2))
    ELSE NULL
END
WHERE stipend_pesos IS NOT NULL;

ALTER TABLE confirmation_records
  ADD KEY idx_confirmation_registry (registry_no),
  ADD KEY idx_confirmation_birth_event (birth_date, confirmation_date);
