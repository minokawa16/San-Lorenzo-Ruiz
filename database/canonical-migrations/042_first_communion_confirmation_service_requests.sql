-- Canonical Migration 042: First Communion and Confirmation Service Requests Schema Updates
-- Ensures first_communion_records.folio is nullable so records can be created without folio.
-- Allows schedule_slot_locks slot_time/slot_end_time to be nullable for date-only requests ("Time to be set").
-- Idempotently backfills schedule_slot_locks for existing First Communion and Confirmation requests.

-- 1. Ensure folio column in first_communion_records is nullable
ALTER TABLE first_communion_records MODIFY folio VARCHAR(50) NULL DEFAULT NULL;

-- 2. Ensure slot_time and slot_end_time in schedule_slot_locks are nullable
ALTER TABLE schedule_slot_locks MODIFY slot_time TIME NULL DEFAULT NULL;
ALTER TABLE schedule_slot_locks MODIFY slot_end_time TIME NULL DEFAULT NULL;

-- 3. Idempotently backfill schedule_slot_locks for historical/existing First Communion and Confirmation requests
INSERT INTO schedule_slot_locks (slot_date, slot_time, slot_end_time, source_type, source_id, status)
SELECT 
    STR_TO_DATE(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(r.description, 'Preferred date: ', -1), '\n', 1)), '%Y-%m-%d') AS slot_date,
    NULL AS slot_time,
    NULL AS slot_end_time,
    'request' AS source_type,
    r.request_id AS source_id,
    'active' AS status
FROM requests r
WHERE r.request_type IN ('first_communion_service', 'first_communion', 'confirmation_service', 'confirmation')
  AND r.description LIKE '%Preferred date:%'
  AND STR_TO_DATE(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(r.description, 'Preferred date: ', -1), '\n', 1)), '%Y-%m-%d') IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM schedule_slot_locks s_locks 
      WHERE s_locks.source_type = 'request' AND s_locks.source_id = r.request_id
  );
