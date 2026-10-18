-- Migration 052: Schedule Reminders and Notification Settings
-- Adds schedule and request references to notifications table
-- Creates reminder_log table for idempotent reminder tracking
-- Creates notification_settings table for secretary and parish notification preferences
-- Grants secretary role appropriate staff and notification permissions

-- 1. Extend notifications table if columns don't exist
ALTER TABLE `notifications` 
    ADD COLUMN IF NOT EXISTS `related_request_id` INT NULL AFTER `entity_id`,
    ADD COLUMN IF NOT EXISTS `schedule_id` INT NULL AFTER `related_request_id`,
    ADD COLUMN IF NOT EXISTS `days_before` INT NULL AFTER `schedule_id`,
    ADD INDEX IF NOT EXISTS `idx_notif_related_request` (`related_request_id`),
    ADD INDEX IF NOT EXISTS `idx_notif_schedule` (`schedule_id`),
    ADD INDEX IF NOT EXISTS `idx_notif_days_before` (`days_before`);

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
