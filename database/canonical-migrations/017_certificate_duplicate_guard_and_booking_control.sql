-- Phase 17: Certificate duplicate guard and calendar booking control
-- Adds record_holder_name to requests table and performance indexes for anti-spam duplicate checks and reservation windows.

ALTER TABLE requests
  ADD COLUMN record_holder_name VARCHAR(191) NULL AFTER request_type,
  ADD KEY idx_requests_duplicate_guard (user_id, request_type, record_holder_name, status);

-- Ensure reservations table has window and status index for conflict checks
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservations' AND INDEX_NAME = 'idx_reservations_window_status');
SET @stmt = IF(@idx_exists = 0, 'ALTER TABLE reservations ADD KEY idx_reservations_window_status (start_at, end_at, status)', 'SELECT 1');
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
