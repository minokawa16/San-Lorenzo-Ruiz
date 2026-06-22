-- Migration: Create Role-Based Dashboard and Conflict Detection Tables
-- Purpose: Support role-based configurations, dashboard preferences, and calendar conflict tracking
-- Date: 2026-06-15

-- Role Configuration and Permissions
CREATE TABLE IF NOT EXISTS role_configurations (
    config_id INT PRIMARY KEY AUTO_INCREMENT,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    permissions JSON NOT NULL COMMENT 'List of permissions for this role',
    dashboard_widgets JSON NOT NULL COMMENT 'Default widgets for dashboard',
    is_system TINYINT(1) DEFAULT 0 COMMENT '1 = cannot be deleted',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_role_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User Dashboard Preferences
CREATE TABLE IF NOT EXISTS dashboard_preferences (
    preference_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    role VARCHAR(50) NOT NULL,
    selected_widgets JSON NOT NULL COMMENT 'Array of widget identifiers to display',
    widget_order JSON NULL COMMENT 'Order of widgets',
    refresh_interval INT DEFAULT 300 COMMENT 'Seconds',
    theme VARCHAR(20) DEFAULT 'light',
    items_per_page INT DEFAULT 10,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_dashboard (user_id),
    INDEX idx_preference_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calendar Event Conflict Detection
CREATE TABLE IF NOT EXISTS calendar_event_conflicts (
    conflict_id INT PRIMARY KEY AUTO_INCREMENT,
    event_id INT NOT NULL,
    conflicting_event_id INT NOT NULL,
    conflict_type VARCHAR(50) NOT NULL COMMENT 'venue_overlap, priest_busy, room_reserved, etc.',
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    description TEXT NOT NULL,
    detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved TINYINT(1) DEFAULT 0,
    resolution_action VARCHAR(255) NULL,
    resolved_by INT NULL,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (event_id) REFERENCES schedule_events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (conflicting_event_id) REFERENCES schedule_events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_conflict_event (event_id),
    INDEX idx_conflict_resolved (resolved),
    INDEX idx_conflict_severity (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conflict Detection Rules
CREATE TABLE IF NOT EXISTS conflict_detection_rules (
    rule_id INT PRIMARY KEY AUTO_INCREMENT,
    rule_name VARCHAR(100) NOT NULL,
    rule_type VARCHAR(50) NOT NULL COMMENT 'venue_overlap, resource_conflict, staff_conflict',
    event_type_1 VARCHAR(50) NOT NULL,
    event_type_2 VARCHAR(50) NOT NULL,
    conflict_condition TEXT NOT NULL COMMENT 'SQL condition or JSON condition',
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    auto_prevent TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rule_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reservation Constraints (Venues, Priests, Rooms, etc.)
CREATE TABLE IF NOT EXISTS reservation_resources (
    resource_id INT PRIMARY KEY AUTO_INCREMENT,
    resource_name VARCHAR(100) NOT NULL,
    resource_type VARCHAR(50) NOT NULL COMMENT 'venue, priest, room, equipment',
    description TEXT NULL,
    capacity INT NULL,
    available_from TIME NULL,
    available_to TIME NULL,
    is_available TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_resource_type (resource_type),
    INDEX idx_resource_available (is_available)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resource Reservation Mapping
CREATE TABLE IF NOT EXISTS resource_reservations (
    reservation_id INT PRIMARY KEY AUTO_INCREMENT,
    event_id INT NOT NULL,
    resource_id INT NOT NULL,
    reserved_by INT NOT NULL,
    status ENUM('pending', 'confirmed', 'cancelled', 'completed') DEFAULT 'pending',
    reserved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES schedule_events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (resource_id) REFERENCES reservation_resources(resource_id) ON DELETE CASCADE,
    FOREIGN KEY (reserved_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_event_resource (event_id, resource_id),
    INDEX idx_resource_reservation_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
