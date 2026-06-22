-- Migration: Create Backup and Recovery Management Tables
-- Purpose: Track backup schedules, backup history, and restore operations
-- Date: 2026-06-15

-- Backup Configuration and History
CREATE TABLE IF NOT EXISTS backup_records (
    backup_id INT PRIMARY KEY AUTO_INCREMENT,
    backup_type ENUM('full', 'incremental', 'database_only', 'files_only') DEFAULT 'full',
    backup_name VARCHAR(255) NOT NULL UNIQUE,
    backup_path VARCHAR(500) NOT NULL,
    backup_size BIGINT UNSIGNED,
    database_tables_count INT,
    files_count INT,
    backup_status ENUM('pending', 'in_progress', 'completed', 'failed', 'partial') DEFAULT 'pending',
    initiated_by INT NOT NULL,
    initiated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    duration_seconds INT NULL,
    error_message TEXT NULL,
    compression_enabled TINYINT(1) DEFAULT 1,
    encryption_enabled TINYINT(1) DEFAULT 0,
    verified TINYINT(1) DEFAULT 0,
    verified_at TIMESTAMP NULL,
    retention_days INT DEFAULT 30,
    scheduled_backup TINYINT(1) DEFAULT 0,
    FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_backup_status (backup_status),
    INDEX idx_backup_timestamp (initiated_at),
    INDEX idx_backup_type (backup_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backup Schedule Configuration
CREATE TABLE IF NOT EXISTS backup_schedules (
    schedule_id INT PRIMARY KEY AUTO_INCREMENT,
    schedule_name VARCHAR(100) NOT NULL,
    backup_type ENUM('full', 'incremental', 'database_only', 'files_only') DEFAULT 'full',
    frequency ENUM('hourly', 'daily', 'weekly', 'monthly') NOT NULL,
    day_of_week INT NULL COMMENT '0=Sunday, 6=Saturday (for weekly)',
    day_of_month INT NULL COMMENT '1-31 (for monthly)',
    time_of_day TIME NOT NULL,
    enabled TINYINT(1) DEFAULT 1,
    retention_days INT DEFAULT 30,
    compression_enabled TINYINT(1) DEFAULT 1,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_run TIMESTAMP NULL,
    next_run TIMESTAMP NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_schedule_enabled (enabled),
    INDEX idx_schedule_next_run (next_run)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backup Restore History
CREATE TABLE IF NOT EXISTS backup_restore_history (
    restore_id INT PRIMARY KEY AUTO_INCREMENT,
    backup_id INT NOT NULL,
    restore_type ENUM('full', 'partial', 'selective') DEFAULT 'full',
    restored_by INT NOT NULL,
    restore_initiated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    restore_completed_at TIMESTAMP NULL,
    restore_status ENUM('pending', 'in_progress', 'completed', 'failed', 'partial') DEFAULT 'pending',
    duration_seconds INT NULL,
    tables_restored INT NULL,
    files_restored INT NULL,
    restore_destination VARCHAR(255) NULL,
    pre_restore_snapshot LONGBLOB NULL COMMENT 'Backup metadata before restore for safety',
    error_message TEXT NULL,
    verified TINYINT(1) DEFAULT 0,
    rollback_available TINYINT(1) DEFAULT 1,
    FOREIGN KEY (backup_id) REFERENCES backup_records(backup_id) ON DELETE CASCADE,
    FOREIGN KEY (restored_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_restore_status (restore_status),
    INDEX idx_restore_timestamp (restore_initiated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backup Monitoring and Health Check
CREATE TABLE IF NOT EXISTS backup_health_checks (
    check_id INT PRIMARY KEY AUTO_INCREMENT,
    backup_id INT NOT NULL,
    check_type VARCHAR(50) NOT NULL COMMENT 'integrity, restore_test, size_validation',
    check_status ENUM('passed', 'warning', 'failed') DEFAULT 'passed',
    check_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    details JSON NULL,
    recommendations TEXT NULL,
    FOREIGN KEY (backup_id) REFERENCES backup_records(backup_id) ON DELETE CASCADE,
    INDEX idx_check_status (check_status),
    INDEX idx_check_timestamp (check_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
