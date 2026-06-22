-- Migration: Create Email Templates and Notification Configuration Tables
-- Purpose: Store customizable email templates for various request states
-- Date: 2026-06-15

-- Email Templates Master
CREATE TABLE IF NOT EXISTS email_templates (
    template_id INT PRIMARY KEY AUTO_INCREMENT,
    template_name VARCHAR(100) NOT NULL UNIQUE,
    template_key VARCHAR(50) NOT NULL UNIQUE COMMENT 'request_submitted, request_approved, payment_verified, etc.',
    subject_line VARCHAR(255) NOT NULL,
    email_body LONGTEXT NOT NULL,
    plain_text_body LONGTEXT NULL,
    template_variables JSON NULL COMMENT 'Available variables for substitution',
    recipient_type ENUM('user', 'admin', 'staff', 'priest', 'all') DEFAULT 'user',
    is_active TINYINT(1) DEFAULT 1,
    is_system TINYINT(1) DEFAULT 0 COMMENT '1 = system template, cannot be deleted',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_template_key (template_key),
    INDEX idx_template_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email Template Audit Trail
CREATE TABLE IF NOT EXISTS email_template_versions (
    version_id INT PRIMARY KEY AUTO_INCREMENT,
    template_id INT NOT NULL,
    subject_line VARCHAR(255) NOT NULL,
    email_body LONGTEXT NOT NULL,
    changed_by INT NOT NULL,
    change_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES email_templates(template_id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_template_versions (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email Send Log
CREATE TABLE IF NOT EXISTS email_send_log (
    email_id INT PRIMARY KEY AUTO_INCREMENT,
    template_id INT NOT NULL,
    recipient_user_id INT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    entity_type VARCHAR(50) NULL COMMENT 'request, announcement, payment, etc.',
    entity_id INT UNSIGNED NULL,
    subject_sent VARCHAR(255) NOT NULL,
    send_status ENUM('pending', 'sent', 'failed', 'bounced', 'spam') DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    failed_reason TEXT NULL,
    retry_count INT DEFAULT 0,
    last_retry_at TIMESTAMP NULL,
    FOREIGN KEY (template_id) REFERENCES email_templates(template_id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_email_status (send_status),
    INDEX idx_email_timestamp (sent_at),
    INDEX idx_email_user (recipient_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification Preferences
CREATE TABLE IF NOT EXISTS notification_preferences (
    preference_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    template_key VARCHAR(50) NOT NULL,
    email_enabled TINYINT(1) DEFAULT 1,
    sms_enabled TINYINT(1) DEFAULT 0,
    in_app_enabled TINYINT(1) DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_template (user_id, template_key),
    INDEX idx_preference_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
