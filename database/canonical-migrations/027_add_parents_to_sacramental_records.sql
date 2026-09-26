-- Migration 027: Add parents, father_name, and mother_name columns to sacramental records for simplified certificate generation

-- funeral_records.parents
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funeral_records' AND COLUMN_NAME = 'parents');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `funeral_records` ADD COLUMN `parents` VARCHAR(255) NULL AFTER `family_name`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- funeral_records.father_name
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funeral_records' AND COLUMN_NAME = 'father_name');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `funeral_records` ADD COLUMN `father_name` VARCHAR(150) NULL AFTER `parents`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- funeral_records.mother_name
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funeral_records' AND COLUMN_NAME = 'mother_name');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `funeral_records` ADD COLUMN `mother_name` VARCHAR(150) NULL AFTER `father_name`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- funeral_records.parish_priest
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funeral_records' AND COLUMN_NAME = 'parish_priest');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `funeral_records` ADD COLUMN `parish_priest` VARCHAR(120) NULL AFTER `minister`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- first_communion_records.father_name
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'first_communion_records' AND COLUMN_NAME = 'father_name');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `first_communion_records` ADD COLUMN `father_name` VARCHAR(150) NULL AFTER `parents`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- first_communion_records.mother_name
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'first_communion_records' AND COLUMN_NAME = 'mother_name');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `first_communion_records` ADD COLUMN `mother_name` VARCHAR(150) NULL AFTER `father_name`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- confirmation_records.father_name
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'confirmation_records' AND COLUMN_NAME = 'father_name');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `confirmation_records` ADD COLUMN `father_name` VARCHAR(150) NULL AFTER `parents`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- confirmation_records.mother_name
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'confirmation_records' AND COLUMN_NAME = 'mother_name');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `confirmation_records` ADD COLUMN `mother_name` VARCHAR(150) NULL AFTER `father_name`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

