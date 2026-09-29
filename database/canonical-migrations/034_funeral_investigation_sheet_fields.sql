-- Canonical Migration 034: Ensure funeral_records has all fields for full Funeral Investigation Sheet
-- Adds civil_status and funeral_rites columns if not present.
-- These match the columns shown in the Parish Records > Funeral Records admin table.

-- civil_status
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funeral_records' AND COLUMN_NAME = 'civil_status');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `funeral_records` ADD COLUMN `civil_status` VARCHAR(80) NULL DEFAULT NULL AFTER `date_of_burial`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- funeral_rites
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funeral_records' AND COLUMN_NAME = 'funeral_rites');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `funeral_records` ADD COLUMN `funeral_rites` VARCHAR(120) NULL DEFAULT NULL AFTER `civil_status`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Unique constraint on request_id to prevent duplicate funeral records per request
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funeral_records' AND INDEX_NAME = 'uq_funeral_request');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `funeral_records` ADD UNIQUE KEY `uq_funeral_request` (`request_id`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
