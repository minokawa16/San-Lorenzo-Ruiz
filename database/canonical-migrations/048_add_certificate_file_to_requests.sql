-- Canonical Migration 048: Add released certificate columns to requests table
-- Idempotent, safe to run multiple times, additive only, no dropped columns or data loss.

SET @col_exists_path = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'requests' 
      AND column_name = 'certificate_file_path'
);

SET @sql_path = IF(
    @col_exists_path = 0,
    'ALTER TABLE `requests` ADD COLUMN `certificate_file_path` VARCHAR(255) NULL DEFAULT NULL AFTER `admin_response`',
    'SELECT 1'
);

PREPARE stmt_path FROM @sql_path;
EXECUTE stmt_path;
DEALLOCATE PREPARE stmt_path;

SET @col_exists_name = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'requests' 
      AND column_name = 'certificate_file_name'
);

SET @sql_name = IF(
    @col_exists_name = 0,
    'ALTER TABLE `requests` ADD COLUMN `certificate_file_name` VARCHAR(255) NULL DEFAULT NULL AFTER `certificate_file_path`',
    'SELECT 1'
);

PREPARE stmt_name FROM @sql_name;
EXECUTE stmt_name;
DEALLOCATE PREPARE stmt_name;

SET @col_exists_by = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'requests' 
      AND column_name = 'certificate_uploaded_by'
);

SET @sql_by = IF(
    @col_exists_by = 0,
    'ALTER TABLE `requests` ADD COLUMN `certificate_uploaded_by` INT NULL DEFAULT NULL AFTER `certificate_file_name`',
    'SELECT 1'
);

PREPARE stmt_by FROM @sql_by;
EXECUTE stmt_by;
DEALLOCATE PREPARE stmt_by;

SET @col_exists_at = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'requests' 
      AND column_name = 'certificate_uploaded_at'
);

SET @sql_at = IF(
    @col_exists_at = 0,
    'ALTER TABLE `requests` ADD COLUMN `certificate_uploaded_at` DATETIME NULL DEFAULT NULL AFTER `certificate_uploaded_by`',
    'SELECT 1'
);

PREPARE stmt_at FROM @sql_at;
EXECUTE stmt_at;
DEALLOCATE PREPARE stmt_at;

SET @col_exists_note = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'requests' 
      AND column_name = 'certificate_release_note'
);

SET @sql_note = IF(
    @col_exists_note = 0,
    'ALTER TABLE `requests` ADD COLUMN `certificate_release_note` TEXT NULL DEFAULT NULL AFTER `certificate_uploaded_at`',
    'SELECT 1'
);

PREPARE stmt_note FROM @sql_note;
EXECUTE stmt_note;
DEALLOCATE PREPARE stmt_note;
