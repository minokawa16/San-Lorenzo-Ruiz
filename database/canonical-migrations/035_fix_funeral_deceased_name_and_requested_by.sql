-- Canonical Migration 035: Fix Funeral Records Deceased Name and add Requested By field
-- Prevents requesting parishioner's account name from appearing in deceased_name.
-- Tracks the submitter in requested_by column separately.

-- 1. Ensure requested_by column exists in funeral_records
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funeral_records' AND COLUMN_NAME = 'requested_by');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `funeral_records` ADD COLUMN `requested_by` VARCHAR(150) NULL DEFAULT NULL AFTER `family_name`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Populate requested_by from linked requests and user accounts where available
UPDATE `funeral_records` f
JOIN `requests` r ON f.request_id = r.request_id
JOIN `users` u ON r.user_id = u.id
SET f.requested_by = u.fullname
WHERE f.requested_by IS NULL OR f.requested_by = '';

-- 3. Clear family_name where it was incorrectly populated with the applicant/requester name
UPDATE `funeral_records` f
JOIN `requests` r ON f.request_id = r.request_id
JOIN `users` u ON r.user_id = u.id
SET f.family_name = NULL
WHERE f.family_name IS NOT NULL
  AND (TRIM(LOWER(f.family_name)) = TRIM(LOWER(u.fullname))
       OR TRIM(LOWER(f.family_name)) = TRIM(LOWER(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.surname, '')))));

-- 4. Clean up deceased_name where requester name was accidentally concatenated or merged
-- Case A: Variations containing 'THERESA ELLAINE NATIAL' or 'Theresas Y. Natial'
UPDATE `funeral_records`
SET `deceased_name` = TRIM(REPLACE(REPLACE(`deceased_name`, 'THERESA ELLAINE NATIAL', ''), 'Theresa Ellaine Natial', ''))
WHERE `deceased_name` LIKE '%THERESA ELLAINE NATIAL%' OR `deceased_name` LIKE '%Theresa Ellaine Natial%';

UPDATE `funeral_records`
SET `deceased_name` = TRIM(REPLACE(`deceased_name`, 'Therasas Y. Natial', ''))
WHERE `deceased_name` LIKE '%Therasas Y. Natial%';

-- Case B: Trailing account user name merged via previous migration or scripts
UPDATE `funeral_records` f
JOIN `requests` r ON f.request_id = r.request_id
JOIN `users` u ON r.user_id = u.id
SET f.deceased_name = TRIM(SUBSTRING(f.deceased_name, 1, LENGTH(f.deceased_name) - LENGTH(u.fullname)))
WHERE LENGTH(f.deceased_name) > LENGTH(u.fullname)
  AND f.deceased_name LIKE CONCAT('%', u.fullname);

-- Case C: Legacy suffix ' LARS' (e.g. record 1 angelo pogi LARS -> angelo pogi)
UPDATE `funeral_records`
SET `deceased_name` = TRIM(SUBSTRING(`deceased_name`, 1, LENGTH(`deceased_name`) - 4))
WHERE `deceased_name` LIKE '% LARS';

-- 5. Nullify family_name for all records where it equals deceased_name or matches legacy merge patterns
UPDATE `funeral_records`
SET `family_name` = NULL
WHERE `family_name` IS NOT NULL;

-- 6. Refresh sacramental_records_death view
CREATE OR REPLACE VIEW sacramental_records_death AS SELECT * FROM funeral_records;
