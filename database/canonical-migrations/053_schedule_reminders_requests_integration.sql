-- Migration 053: Schedule Reminders Request Integration & Resiliency
-- 1. Ensure requests table has preferred_date and preferred_time columns
-- 2. Ensure reminder_log supports request_id and has nullable schedule_id
-- 3. Ensure notification_settings table and defaults exist for all admin & secretary users

-- 1. Extend requests table for preferred_date and preferred_time
SET @col_exists_pref_date = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'requests' 
      AND column_name = 'preferred_date'
);
SET @sql_pref_date = IF(@col_exists_pref_date = 0, 'ALTER TABLE `requests` ADD COLUMN `preferred_date` VARCHAR(50) NULL DEFAULT NULL AFTER `status`', 'SELECT 1');
PREPARE stmt_pdate FROM @sql_pref_date; EXECUTE stmt_pdate; DEALLOCATE PREPARE stmt_pdate;

SET @col_exists_pref_time = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'requests' 
      AND column_name = 'preferred_time'
);
SET @sql_pref_time = IF(@col_exists_pref_time = 0, 'ALTER TABLE `requests` ADD COLUMN `preferred_time` VARCHAR(50) NULL DEFAULT NULL AFTER `preferred_date`', 'SELECT 1');
PREPARE stmt_ptime FROM @sql_pref_time; EXECUTE stmt_ptime; DEALLOCATE PREPARE stmt_ptime;

-- 2. Extend reminder_log table to support request-based reminders
CREATE TABLE IF NOT EXISTS `reminder_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `schedule_id` INT NULL DEFAULT NULL,
    `request_id` INT NULL DEFAULT NULL,
    `days_before` INT NOT NULL,
    `sent_at` DATETIME NOT NULL,
    `channel` VARCHAR(50) NOT NULL DEFAULT 'in-app',
    `status` VARCHAR(50) NOT NULL DEFAULT 'sent',
    `error_message` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_reminder_schedule` (`schedule_id`),
    INDEX `idx_reminder_request` (`request_id`),
    INDEX `idx_reminder_days` (`days_before`),
    INDEX `idx_reminder_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure schedule_id in reminder_log is nullable if table already existed
SET @sched_nullable = (
    SELECT is_nullable 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'reminder_log' 
      AND column_name = 'schedule_id'
);
SET @sql_mod_sched = IF(@sched_nullable = 'NO', 'ALTER TABLE `reminder_log` MODIFY COLUMN `schedule_id` INT NULL DEFAULT NULL', 'SELECT 1');
PREPARE stmt_msched FROM @sql_mod_sched; EXECUTE stmt_msched; DEALLOCATE PREPARE stmt_msched;

-- Add request_id to reminder_log if missing
SET @col_exists_req_log = (
    SELECT COUNT(*) 
    FROM information_schema.columns 
    WHERE table_schema = DATABASE() 
      AND table_name = 'reminder_log' 
      AND column_name = 'request_id'
);
SET @sql_req_log = IF(@col_exists_req_log = 0, 'ALTER TABLE `reminder_log` ADD COLUMN `request_id` INT NULL DEFAULT NULL AFTER `schedule_id`, ADD INDEX `idx_reminder_request` (`request_id`)', 'SELECT 1');
PREPARE stmt_rlog FROM @sql_req_log; EXECUTE stmt_rlog; DEALLOCATE PREPARE stmt_rlog;

-- 3. Ensure notification_settings table exists
CREATE TABLE IF NOT EXISTS `notification_settings` (
    `setting_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `remind_3_days` TINYINT(1) NOT NULL DEFAULT 1,
    `remind_2_days` TINYINT(1) NOT NULL DEFAULT 1,
    `remind_1_day` TINYINT(1) NOT NULL DEFAULT 1,
    `remind_0_day` TINYINT(1) NOT NULL DEFAULT 1,
    `send_time` VARCHAR(10) NOT NULL DEFAULT '08:00',
    `channel_in_app` TINYINT(1) NOT NULL DEFAULT 1,
    `channel_email` TINYINT(1) NOT NULL DEFAULT 1,
    `channel_sms` TINYINT(1) NOT NULL DEFAULT 0,
    `stale_days_threshold` INT NOT NULL DEFAULT 3,
    `notify_new_request` TINYINT(1) NOT NULL DEFAULT 1,
    `notify_cancelled_request` TINYINT(1) NOT NULL DEFAULT 1,
    `notify_conflicts` TINYINT(1) NOT NULL DEFAULT 1,
    `notify_stale_requests` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_setting` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert global defaults if missing
INSERT INTO `notification_settings` (
    `user_id`, `remind_3_days`, `remind_2_days`, `remind_1_day`, `remind_0_day`,
    `send_time`, `channel_in_app`, `channel_email`, `channel_sms`,
    `stale_days_threshold`, `notify_new_request`, `notify_cancelled_request`,
    `notify_conflicts`, `notify_stale_requests`
)
SELECT NULL, 1, 1, 1, 1, '08:00', 1, 1, 0, 3, 1, 1, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `notification_settings` WHERE `user_id` IS NULL);

-- Insert default rows for all secretary and admin staff users
INSERT IGNORE INTO `notification_settings` (
    `user_id`, `remind_3_days`, `remind_2_days`, `remind_1_day`, `remind_0_day`,
    `send_time`, `channel_in_app`, `channel_email`, `channel_sms`,
    `stale_days_threshold`, `notify_new_request`, `notify_cancelled_request`,
    `notify_conflicts`, `notify_stale_requests`
)
SELECT u.id, 1, 1, 1, 1, '08:00', 1, 1, 0, 3, 1, 1, 1, 1
FROM `users` u
WHERE u.role IN ('admin', 'administrator', 'secretary', 'parish_staff');

