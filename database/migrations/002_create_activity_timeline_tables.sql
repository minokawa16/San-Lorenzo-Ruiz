-- Migration: Create Activity Timeline and Audit Logging Tables
-- Purpose: Track all system activities for audit trail and timeline display
-- Date: 2026-06-15

-- Activity Logs Table - Comprehensive audit trail
CREATE TABLE IF NOT EXISTS activity_logs (
    activity_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    entity_type VARCHAR(50) NOT NULL COMMENT 'users, requests, records, announcements, payments, etc.',
    entity_id INT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL COMMENT 'created, updated, approved, rejected, archived, restored, etc.',
    action_category VARCHAR(30) NOT NULL COMMENT 'request, approval, system, user, payment, etc.',
    old_values JSON NULL COMMENT 'Previous values for update operations',
    new_values JSON NULL COMMENT 'New values for update operations',
    description TEXT NULL COMMENT 'Human-readable description',
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    status VARCHAR(20) DEFAULT 'completed' COMMENT 'completed, pending, failed',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_entity (entity_type, entity_id),
    INDEX idx_activity_action (action),
    INDEX idx_activity_created (created_at),
    INDEX idx_activity_category (action_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Request Activity Timeline (Quick reference for request-specific timeline)
CREATE TABLE IF NOT EXISTS request_activity_timeline (
    timeline_id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL,
    activity_id INT NOT NULL,
    user_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    timestamp_recorded TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE CASCADE,
    FOREIGN KEY (activity_id) REFERENCES activity_logs(activity_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_request_activity (request_id),
    INDEX idx_activity_ref (activity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System Change Log (For major system events)
CREATE TABLE IF NOT EXISTS system_change_log (
    change_id INT PRIMARY KEY AUTO_INCREMENT,
    change_type VARCHAR(50) NOT NULL,
    affected_table VARCHAR(50) NOT NULL,
    description TEXT NOT NULL,
    changed_by INT NOT NULL,
    change_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_system_change_timestamp (change_timestamp),
    INDEX idx_system_change_type (change_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
