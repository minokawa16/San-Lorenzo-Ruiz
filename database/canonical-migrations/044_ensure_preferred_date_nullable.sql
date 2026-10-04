-- Canonical Migration 044: Ensure requests preferred_date column is nullable if present
-- Idempotent, safe to run multiple times, does not drop columns or modify existing data.

SET @col_exists = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'requests' 
      AND column_name = 'preferred_date'
);

SET @sql = IF(
    @col_exists > 0,
    'ALTER TABLE `requests` MODIFY COLUMN `preferred_date` DATE NULL DEFAULT NULL',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
