-- Canonical migration 032: Add request record matching fields
-- Adds matched_record_id, matched_record_type, match_status, and match_details to requests table
-- Allows fast-tracking certificate generation and clear manual review indicators

-- matched_record_id
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME = 'matched_record_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE requests ADD COLUMN matched_record_id INT NULL DEFAULT NULL AFTER record_holder_name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- matched_record_type
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME = 'matched_record_type');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE requests ADD COLUMN matched_record_type VARCHAR(50) NULL DEFAULT NULL AFTER matched_record_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- match_status
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME = 'match_status');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE requests ADD COLUMN match_status ENUM(\'unmatched\', \'matched\', \'multiple\', \'no_match\') NOT NULL DEFAULT \'unmatched\' AFTER matched_record_type', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- match_details
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND COLUMN_NAME = 'match_details');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE requests ADD COLUMN match_details TEXT NULL DEFAULT NULL AFTER match_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- idx_request_matched_record
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND INDEX_NAME = 'idx_request_matched_record');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE requests ADD INDEX idx_request_matched_record (matched_record_type, matched_record_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- idx_request_match_status
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'requests' AND INDEX_NAME = 'idx_request_match_status');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE requests ADD INDEX idx_request_match_status (match_status)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

