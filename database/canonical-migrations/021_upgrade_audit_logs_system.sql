-- Phase 21: Upgrade Audit Logs System & UI Architecture
-- Adds metadata columns, severity, category, target references, description, and diff columns.
-- Compatible with MySQL 5.7+, MySQL 8.0+, and MariaDB.

SET @dbname = DATABASE();
SET @tablename = 'audit_log';

-- 1. user_name
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'user_name');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `audit_log` ADD COLUMN `user_name` VARCHAR(150) NULL AFTER `user_id`', 'SELECT 1');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. user_role
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'user_role');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `audit_log` ADD COLUMN `user_role` VARCHAR(50) NULL AFTER `user_name`', 'SELECT 1');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. user_agent
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'user_agent');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `audit_log` ADD COLUMN `user_agent` TEXT NULL AFTER `ip_address`', 'SELECT 1');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. event_category
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'event_category');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `audit_log` ADD COLUMN `event_category` ENUM(\'AUTH\',\'SACRAMENTS\',\'REQUESTS\',\'ACCOUNTS\',\'CERTIFICATES\',\'SYSTEM\') NOT NULL DEFAULT \'SYSTEM\' AFTER `user_agent`', 'SELECT 1');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. severity
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'severity');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `audit_log` ADD COLUMN `severity` ENUM(\'INFO\',\'WARNING\',\'CRITICAL\') NOT NULL DEFAULT \'INFO\' AFTER `action`', 'SELECT 1');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. target_type
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'target_type');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `audit_log` ADD COLUMN `target_type` VARCHAR(80) NULL AFTER `severity`', 'SELECT 1');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. target_id
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'target_id');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `audit_log` ADD COLUMN `target_id` INT(11) NULL AFTER `target_type`', 'SELECT 1');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8. description
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'description');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `audit_log` ADD COLUMN `description` TEXT NULL AFTER `target_id`', 'SELECT 1');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 9. old_values
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'old_values');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `audit_log` ADD COLUMN `old_values` LONGTEXT NULL AFTER `description`', 'SELECT 1');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 10. new_values
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'new_values');
SET @query = IF(@col_exists = 0, 'ALTER TABLE `audit_log` ADD COLUMN `new_values` LONGTEXT NULL AFTER `old_values`', 'SELECT 1');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add performance indexes if missing
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = 'idx_audit_category_severity');
SET @query = IF(@idx_exists = 0, 'ALTER TABLE `audit_log` ADD KEY `idx_audit_category_severity` (`event_category`, `severity`)', 'SELECT 1');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = 'idx_audit_severity');
SET @query = IF(@idx_exists = 0, 'ALTER TABLE `audit_log` ADD KEY `idx_audit_severity` (`severity`)', 'SELECT 1');
PREPARE stmt FROM @query; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Create or replace canonical view audit_logs
CREATE OR REPLACE VIEW `audit_logs` AS
SELECT
  l.log_id AS id,
  l.user_id,
  l.user_name,
  l.user_role,
  l.ip_address,
  l.user_agent,
  l.event_category,
  l.action,
  l.severity,
  COALESCE(l.target_type, l.table_name, 'system') AS target_type,
  COALESCE(l.target_id, l.record_id) AS target_id,
  l.description,
  COALESCE(l.old_values, l.old_value) AS old_values,
  COALESCE(l.new_values, l.new_value) AS new_values,
  l.created_at,
  l.component,
  l.event,
  l.correlation_id,
  l.table_name,
  l.record_id,
  l.old_value,
  l.new_value,
  l.log_id
FROM `audit_log` l;

-- Backfill existing rows with actor names and roles from users table
UPDATE `audit_log` l
LEFT JOIN `users` u ON u.id = l.user_id
SET
  l.user_name = COALESCE(u.fullname, CASE WHEN l.user_id IS NULL THEN 'Unknown / Visitor' ELSE 'System' END),
  l.user_role = COALESCE(u.role, CASE WHEN l.user_id IS NULL THEN 'guest' ELSE 'system' END)
WHERE l.user_name IS NULL OR l.user_name = '';

-- Backfill target_type, target_id, old_values, and new_values
UPDATE `audit_log`
SET
  target_type = COALESCE(target_type, table_name, 'system'),
  target_id = COALESCE(target_id, record_id),
  old_values = COALESCE(old_values, old_value),
  new_values = COALESCE(new_values, new_value)
WHERE target_type IS NULL OR target_id IS NULL OR (old_values IS NULL AND old_value IS NOT NULL) OR (new_values IS NULL AND new_value IS NOT NULL);

-- Backfill event_category based on action and table
UPDATE `audit_log`
SET event_category = CASE
  WHEN action LIKE '%LOGIN%' OR action LIKE '%PASSWORD%' OR action LIKE '%OTP%' OR action LIKE '%AUTH%' OR component = 'authentication' THEN 'AUTH'
  WHEN table_name IN ('baptism_records','marriage_records','funeral_records','first_communion_records','confirmation_records') OR action LIKE '%SACRAMENTAL%' THEN 'SACRAMENTS'
  WHEN table_name IN ('requests','request_documents','request_payments','reservations','schedule_proposals','schedule_events') OR action LIKE '%REQUEST%' OR action LIKE '%RESERVATION%' THEN 'REQUESTS'
  WHEN table_name IN ('users','profiles') OR action LIKE '%REGISTRATION%' OR action LIKE '%PROFILE%' OR action LIKE '%ACCOUNT%' OR action LIKE '%USER%' THEN 'ACCOUNTS'
  WHEN table_name IN ('certificates','certificate_issuances','certificate_layouts','certificate_templates') OR action LIKE '%CERTIFICATE%' THEN 'CERTIFICATES'
  ELSE 'SYSTEM'
END
WHERE event_category = 'SYSTEM';

-- Backfill severity
UPDATE `audit_log`
SET severity = CASE
  WHEN action LIKE '%ARCHIVE%' OR action LIKE '%DELETE%' OR action LIKE '%PURGE%' OR action LIKE '%DESTROY%' OR action LIKE '%DROP%' THEN 'CRITICAL'
  WHEN action LIKE '%FAIL%' OR action LIKE '%REJECT%' OR action LIKE '%CONFLICT%' OR action LIKE '%DENIED%' OR action LIKE '%CANCEL%' THEN 'WARNING'
  ELSE 'INFO'
END
WHERE severity = 'INFO';

-- Backfill human-readable descriptions for historical entries
UPDATE `audit_log`
SET description = CASE
  WHEN action = 'LOGIN' THEN CONCAT(COALESCE(user_name, 'User'), ' logged into the system.')
  WHEN action = 'LOGIN_FAILURE' THEN 'Failed login attempt.'
  WHEN action = 'LOGOUT' THEN CONCAT(COALESCE(user_name, 'User'), ' logged out.')
  WHEN action = 'APPROVED_SACRAMENTAL_REQUEST' THEN CONCAT('Approved sacramental request #', COALESCE(record_id, target_id, 'N/A'), ' and synced to parish calendar & sacramental records.')
  WHEN action = 'COMPLETED_SACRAMENTAL_REQUEST' THEN CONCAT('Completed sacramental request #', COALESCE(record_id, target_id, 'N/A'), '.')
  WHEN action = 'CREATE_REQUEST' THEN CONCAT('Submitted new request #', COALESCE(record_id, target_id, 'N/A'), '.')
  WHEN action = 'ARCHIVE_SACRAMENTAL_RECORD' THEN CONCAT('Archived ', COALESCE(table_name, 'sacramental'), ' record #', COALESCE(record_id, target_id, 'N/A'), '.')
  WHEN action = 'RESTORE_SACRAMENTAL_RECORD' THEN CONCAT('Restored ', COALESCE(table_name, 'sacramental'), ' record #', COALESCE(record_id, target_id, 'N/A'), '.')
  WHEN action = 'GENERATE_CERTIFICATE' THEN CONCAT('Generated sacramental certificate for record #', COALESCE(record_id, target_id, 'N/A'), '.')
  WHEN action = 'DOWNLOAD_CERTIFICATE' THEN CONCAT('Downloaded sacramental certificate for record #', COALESCE(record_id, target_id, 'N/A'), '.')
  WHEN action = 'SYNC_REQUEST_CALENDAR' THEN CONCAT('Synced request schedule event #', COALESCE(record_id, target_id, 'N/A'), ' to parish calendar.')
  WHEN action = 'SYNC_RESERVATION_CALENDAR' THEN CONCAT('Synced reservation schedule event #', COALESCE(record_id, target_id, 'N/A'), ' to parish calendar.')
  WHEN action = 'APPROVE_REGISTRATION' THEN CONCAT('Approved parishioner account registration #', COALESCE(record_id, target_id, 'N/A'), '.')
  WHEN action = 'REJECT_REGISTRATION' THEN CONCAT('Rejected parishioner account registration #', COALESCE(record_id, target_id, 'N/A'), '.')
  WHEN action = 'EXPORT_AUDIT_LOG' THEN 'Exported audit log data report.'
  ELSE CONCAT(REPLACE(LOWER(action), '_', ' '), ' on ', COALESCE(table_name, 'system'), CASE WHEN record_id IS NOT NULL THEN CONCAT(' #', record_id) ELSE '' END)
END
WHERE description IS NULL OR description = '' OR description = 'Standard activity';
