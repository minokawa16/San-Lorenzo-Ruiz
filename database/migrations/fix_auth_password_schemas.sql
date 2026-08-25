-- =============================================================================
-- Migration: Fix and Guarantee Full Column Sizes for Authentication & Hashes
-- Description: Ensures password, hash, and token columns have at least VARCHAR(255)
--              to prevent truncation of modern Bcrypt, Argon2, and SHA256 hashes.
-- =============================================================================

-- 1. Ensure `users` table password and security tracking columns
ALTER TABLE `users` 
    MODIFY COLUMN `password` VARCHAR(255) NOT NULL,
    MODIFY COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
    MODIFY COLUMN `password_changed_at` DATETIME NULL;

-- 2. Ensure `password_security_history` table hash storage
CREATE TABLE IF NOT EXISTS `password_security_history` (
    `history_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `password_hash` VARCHAR(255) NULL,
    `event_type` VARCHAR(50) NOT NULL DEFAULT 'password_changed',
    `change_source` VARCHAR(50) NOT NULL DEFAULT 'authenticated_change',
    `actor_user_id` INT NULL,
    `ip_address` VARCHAR(45) NULL,
    `metadata` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_password_history_user_time` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `password_security_history`
    MODIFY COLUMN `password_hash` VARCHAR(255) NULL;

-- 3. Ensure `otp_transactions` table hash column size
CREATE TABLE IF NOT EXISTS `otp_transactions` (
    `otp_transaction_id` INT AUTO_INCREMENT PRIMARY KEY,
    `public_id` VARCHAR(64) NOT NULL UNIQUE,
    `user_id` INT NOT NULL,
    `purpose` VARCHAR(32) NOT NULL,
    `delivery_method` VARCHAR(16) NOT NULL,
    `destination_target` VARCHAR(191) NOT NULL,
    `otp_hash` VARCHAR(255) NOT NULL,
    `attempts_count` INT NOT NULL DEFAULT 0,
    `max_attempts` INT NOT NULL DEFAULT 3,
    `expires_at` DATETIME NOT NULL,
    `verified_at` DATETIME NULL,
    `consumed_at` DATETIME NULL,
    `invalidated_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `otp_transactions`
    MODIFY COLUMN `otp_hash` VARCHAR(255) NOT NULL;

-- 4. Ensure `login_attempts` table columns
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `attempt_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `identifier_hash` CHAR(64) NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `was_successful` TINYINT(1) NOT NULL DEFAULT 0,
    `failure_reason` VARCHAR(64) NULL,
    `user_agent` VARCHAR(500) NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_login_identifier_time` (`identifier_hash`, `attempted_at`),
    KEY `idx_login_user_time` (`user_id`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `login_attempts`
    MODIFY COLUMN `identifier_hash` CHAR(64) NOT NULL;

-- 5. Ensure `email_verifications` table token hash column size
CREATE TABLE IF NOT EXISTS `email_verifications` (
    `verification_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_email_verif_token` (`token_hash`),
    KEY `idx_email_verif_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `email_verifications`
    MODIFY COLUMN `token_hash` CHAR(64) NOT NULL;

-- 6. Ensure `user_auth_identifiers` table exists and has proper types
CREATE TABLE IF NOT EXISTS `user_auth_identifiers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `identifier_type` ENUM('email', 'mobile') NOT NULL,
    `normalized_value` VARCHAR(191) NOT NULL,
    `verified_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_auth_identifier` (`identifier_type`, `normalized_value`),
    KEY `idx_auth_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
