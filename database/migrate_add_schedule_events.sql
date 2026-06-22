-- Schedule Events Migration - Adds the calendar table used for Mass schedules and parish events.

USE parish_management_system;

CREATE TABLE IF NOT EXISTS schedule_events (
    schedule_id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    event_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NULL,
    location VARCHAR(150),
    category VARCHAR(50) DEFAULT 'event',
    priority VARCHAR(20) DEFAULT 'normal',
    color_label VARCHAR(20) DEFAULT '#1a73e8',
    recurrence_rule VARCHAR(100) DEFAULT 'none',
    assigned_personnel VARCHAR(150),
    visibility ENUM('public', 'private') DEFAULT 'public',
    approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved',
    status ENUM('active', 'upcoming', 'ongoing', 'finished', 'cancelled') DEFAULT 'upcoming',
    reminder_minutes INT DEFAULT 30,
    notify_email TINYINT(1) DEFAULT 0,
    notify_sms TINYINT(1) DEFAULT 0,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_schedule_date (event_date),
    INDEX idx_schedule_status_date (status, event_date, start_time)
);
