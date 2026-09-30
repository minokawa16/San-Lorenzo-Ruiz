-- Canonical Migration 040: Personal Dashboard Performance Indexes
-- Adds dedicated composite indexes for user-scoped dashboard stat aggregations and recent activity queries.
-- Idempotent: checks information_schema.STATISTICS before adding any index.

-- 1. Index on requests(user_id, status)
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND INDEX_NAME = 'idx_requests_user_status');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE requests ADD INDEX idx_requests_user_status (user_id, status)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Index on requests(user_id, deleted_at, status) for soft-deleted aggregation
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND INDEX_NAME = 'idx_requests_user_deleted_status');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE requests ADD INDEX idx_requests_user_deleted_status (user_id, deleted_at, status)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Index on requests(user_id, date_requested) for recent requests list
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND INDEX_NAME = 'idx_requests_user_date_requested');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE requests ADD INDEX idx_requests_user_date_requested (user_id, date_requested DESC)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Index on reservations(user_id, event_date, status) for upcoming user reservations
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservations' AND INDEX_NAME = 'idx_reservations_user_event_date_status');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE reservations ADD INDEX idx_reservations_user_event_date_status (user_id, event_date, status)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
