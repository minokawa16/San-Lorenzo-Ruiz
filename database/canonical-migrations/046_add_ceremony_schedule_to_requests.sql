-- Canonical Migration 046: Add ceremony schedule columns to requests table
-- Idempotent, safe to run multiple times, additive only, no dropped columns or data loss.

SET @col_exists_date = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'requests' 
      AND column_name = 'ceremony_date'
);

SET @sql_date = IF(
    @col_exists_date = 0,
    'ALTER TABLE `requests` ADD COLUMN `ceremony_date` DATE NULL DEFAULT NULL AFTER `due_date`',
    'SELECT 1'
);

PREPARE stmt_date FROM @sql_date;
EXECUTE stmt_date;
DEALLOCATE PREPARE stmt_date;

SET @col_exists_time = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'requests' 
      AND column_name = 'ceremony_time'
);

SET @sql_time = IF(
    @col_exists_time = 0,
    'ALTER TABLE `requests` ADD COLUMN `ceremony_time` TIME NULL DEFAULT NULL AFTER `ceremony_date`',
    'SELECT 1'
);

PREPARE stmt_time FROM @sql_time;
EXECUTE stmt_time;
DEALLOCATE PREPARE stmt_time;

SET @col_exists_minister = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'requests' 
      AND column_name = 'ceremony_minister'
);

SET @sql_minister = IF(
    @col_exists_minister = 0,
    'ALTER TABLE `requests` ADD COLUMN `ceremony_minister` VARCHAR(255) NULL DEFAULT NULL AFTER `ceremony_time`',
    'SELECT 1'
);

PREPARE stmt_minister FROM @sql_minister;
EXECUTE stmt_minister;
DEALLOCATE PREPARE stmt_minister;
