-- Migration 052: Schedule Reminders and Notification Settings
-- Adds schedule and request references to notifications table
-- Creates reminder_log table for idempotent reminder tracking
-- Creates notification_settings table for secretary and parish notification preferences
-- Grants secretary role appropriate staff and notification permissions

-- 1. Extend notifications table if columns don't exist
SET @col_exists_req = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'related_request_id');
SET @sql_col_req = IF(@col_exists_req = 0, 'ALTER TABLE `notifications` ADD COLUMN `related_request_id` INT NULL AFTER `entity_id`', 'SELECT 1');
PREPARE stmt_req FROM @sql_col_req; EXECUTE stmt_req; DEALLOCATE PREPARE stmt_req;

SET @col_exists_sched = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'schedule_id');
SET @sql_col_sched = IF(@col_exists_sched = 0, 'ALTER TABLE `notifications` ADD COLUMN `schedule_id` INT NULL AFTER `related_request_id`', 'SELECT 1');
PREPARE stmt_sched FROM @sql_col_sched; EXECUTE stmt_sched; DEALLOCATE PREPARE stmt_sched;

SET @col_exists_days = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'days_before');
SET @sql_col_days = IF(@col_exists_days = 0, 'ALTER TABLE `notifications` ADD COLUMN `days_before` INT NULL AFTER `schedule_id`', 'SELECT 1');
PREPARE stmt_days FROM @sql_col_days; EXECUTE stmt_days; DEALLOCATE PREPARE stmt_days;

SET @idx_exists_req = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'notifications' AND index_name = 'idx_notif_related_request');
SET @sql_idx_req = IF(@idx_exists_req = 0, 'ALTER TABLE `notifications` ADD INDEX `idx_notif_related_request` (`related_request_id`)', 'SELECT 1');
PREPARE stmt_idx_req FROM @sql_idx_req; EXECUTE stmt_idx_req; DEALLOCATE PREPARE stmt_idx_req;

SET @idx_exists_sched = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'notifications' AND index_name = 'idx_notif_schedule');
SET @sql_idx_sched = IF(@idx_exists_sched = 0, 'ALTER TABLE `notifications` ADD INDEX `idx_notif_schedule` (`schedule_id`)', 'SELECT 1');
PREPARE stmt_idx_sched FROM @sql_idx_sched; EXECUTE stmt_idx_sched; DEALLOCATE PREPARE stmt_idx_sched;

SET @idx_exists_days = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'notifications' AND index_name = 'idx_notif_days_before');
SET @sql_idx_days = IF(@idx_exists_days = 0, 'ALTER TABLE `notifications` ADD INDEX `idx_notif_days_before` (`days_before`)', 'SELECT 1');
PREPARE stmt_idx_days FROM @sql_idx_days; EXECUTE stmt_idx_days; DEALLOCATE PREPARE stmt_idx_days;

-- 2. Create reminder_log table
CREATE TABLE IF NOT EXISTS `reminder_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `schedule_id` INT NOT NULL,
    `days_before` INT NOT NULL,
    `sent_at` DATETIME NOT NULL,
    `channel` VARCHAR(50) NOT NULL DEFAULT 'in-app',
    `status` VARCHAR(50) NOT NULL DEFAULT 'sent',
    `error_message` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_schedule_days` (`schedule_id`, `days_before`),
    INDEX `idx_reminder_schedule` (`schedule_id`),
    INDEX `idx_reminder_days` (`days_before`),
    INDEX `idx_reminder_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create notification_settings table
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

-- Insert global default settings if not exists
INSERT INTO `notification_settings` (
    `user_id`, `remind_3_days`, `remind_2_days`, `remind_1_day`, `remind_0_day`,
    `send_time`, `channel_in_app`, `channel_email`, `channel_sms`,
    `stale_days_threshold`, `notify_new_request`, `notify_cancelled_request`,
    `notify_conflicts`, `notify_stale_requests`
) 
SELECT NULL, 1, 1, 1, 1, '08:00', 1, 1, 0, 3, 1, 1, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `notification_settings` WHERE `user_id` IS NULL);

-- 4. Ensure secretary role has staff and notification permissions
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r_sec.role_id, p.permission_id
FROM `roles` r_sec
JOIN `roles` r_staff ON r_staff.role_key = 'parish_staff'
JOIN `role_permissions` rp ON rp.role_id = r_staff.role_id
JOIN `permissions` p ON p.permission_id = rp.permission_id
WHERE r_sec.role_key = 'secretary';
