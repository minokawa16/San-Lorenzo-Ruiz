-- Migration: Add Archive Support to Core Tables
-- Purpose: Enable soft deletes and archive tracking for data integrity
-- Date: 2026-06-15

-- Add archive columns to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS archived_by INT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS archive_reason VARCHAR(255) NULL;
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_deleted_at (deleted_at);
ALTER TABLE users ADD FOREIGN KEY IF NOT EXISTS fk_users_archived_by 
    REFERENCES users(id) ON DELETE SET NULL;

-- Add archive columns to requests table
ALTER TABLE requests ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE requests ADD COLUMN IF NOT EXISTS archived_by INT NULL;
ALTER TABLE requests ADD COLUMN IF NOT EXISTS archive_reason VARCHAR(255) NULL;
ALTER TABLE requests ADD INDEX IF NOT EXISTS idx_requests_deleted_at (deleted_at);
ALTER TABLE requests ADD FOREIGN KEY IF NOT EXISTS fk_requests_archived_by 
    REFERENCES users(id) ON DELETE SET NULL;

-- Add archive columns to announcements table if it exists
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS archived_by INT NULL;
ALTER TABLE announcements ADD COLUMN IF NOT EXISTS archive_reason VARCHAR(255) NULL;
ALTER TABLE announcements ADD INDEX IF NOT EXISTS idx_announcements_deleted_at (deleted_at);
ALTER TABLE announcements ADD FOREIGN KEY IF NOT EXISTS fk_announcements_archived_by 
    REFERENCES users(id) ON DELETE SET NULL;

-- Add archive columns to sacramental records tables
ALTER TABLE baptism_records ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE baptism_records ADD COLUMN IF NOT EXISTS archived_by INT NULL;
ALTER TABLE confirmation_records ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE confirmation_records ADD COLUMN IF NOT EXISTS archived_by INT NULL;
ALTER TABLE first_communion_records ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE first_communion_records ADD COLUMN IF NOT EXISTS archived_by INT NULL;
ALTER TABLE marriage_records ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE marriage_records ADD COLUMN IF NOT EXISTS archived_by INT NULL;
ALTER TABLE funeral_records ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE funeral_records ADD COLUMN IF NOT EXISTS archived_by INT NULL;
