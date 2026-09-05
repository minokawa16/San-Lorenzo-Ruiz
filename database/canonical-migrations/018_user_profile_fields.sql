-- Phase 18: User Profile Fields (middle_name, suffix, id_type, structured address fields)
-- Compatible with MySQL 5.7+, MySQL 8.0+, and MariaDB

SET @dbname = DATABASE();
SET @tablename = 'users';

-- middle_name
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'middle_name');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `users` ADD COLUMN `middle_name` VARCHAR(100) NULL AFTER `first_name`', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- suffix
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'suffix');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `users` ADD COLUMN `suffix` VARCHAR(30) NULL AFTER `middle_initial`', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- id_type
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'id_type');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `users` ADD COLUMN `id_type` VARCHAR(100) NULL AFTER `nationality`', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- street_address
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'street_address');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `users` ADD COLUMN `street_address` VARCHAR(255) NULL AFTER `address`', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- barangay
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'barangay');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `users` ADD COLUMN `barangay` VARCHAR(100) NULL AFTER `street_address`', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- city
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'city');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `users` ADD COLUMN `city` VARCHAR(100) NULL AFTER `barangay`', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- province
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'province');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `users` ADD COLUMN `province` VARCHAR(100) NULL AFTER `city`', 'SELECT 1');
PREPARE stmt FROM @query;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
