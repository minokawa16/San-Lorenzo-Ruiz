-- Canonical Migration 041: Service Date Filter Performance Indexes and Historical Schedule Backfill
-- Adds composite indexes for requested service date filtering and status grouping.
-- Idempotent: checks information_schema before adding any index or inserting missing slot records.

-- 1. Index on schedule_slot_locks(source_type, slot_date, slot_time)
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schedule_slot_locks' AND INDEX_NAME = 'idx_slot_source_date_time');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE schedule_slot_locks ADD INDEX idx_slot_source_date_time (source_type, slot_date, slot_time)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Index on requests(request_type, status)
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND INDEX_NAME = 'idx_requests_type_status');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE requests ADD INDEX idx_requests_type_status (request_type, status)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Index on reservations(event_date, status)
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservations' AND INDEX_NAME = 'idx_reservations_event_date_status');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE reservations ADD INDEX idx_reservations_event_date_status (event_date, status)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Idempotently backfill schedule_slot_locks for historical blessing/sacramental requests
INSERT INTO schedule_slot_locks (slot_date, slot_time, slot_end_time, source_type, source_id, status)
SELECT 
    STR_TO_DATE(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(r.description, 'Preferred date: ', -1), '\n', 1)), '%Y-%m-%d') AS slot_date,
    STR_TO_DATE(CONCAT(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(r.description, 'Preferred time: ', -1), '\n', 1)), ':00'), '%H:%i:%s') AS slot_time,
    ADDTIME(STR_TO_DATE(CONCAT(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(r.description, 'Preferred time: ', -1), '\n', 1)), ':00'), '%H:%i:%s'), '01:00:00') AS slot_end_time,
    'request' AS source_type,
    r.request_id AS source_id,
    'active' AS status
FROM requests r
WHERE r.request_type NOT LIKE '%certif%'
  AND r.description LIKE '%Preferred date:%'
  AND STR_TO_DATE(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(r.description, 'Preferred date: ', -1), '\n', 1)), '%Y-%m-%d') IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM schedule_slot_locks s_locks 
      WHERE s_locks.source_type = 'request' AND s_locks.source_id = r.request_id
  );
