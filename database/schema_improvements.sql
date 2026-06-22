-- ===================================================================
-- PARISH MANAGEMENT SYSTEM - SCHEMA IMPROVEMENTS
-- Security & Performance Enhancements
-- ===================================================================

USE parish_management_system;

-- ===================================================================
-- 1. ADD SOFT DELETES TO EXISTING TABLES
-- ===================================================================

ALTER TABLE users ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE requests ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE announcements ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE baptism_records ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE first_communion_records ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE confirmation_records ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE marriage_records ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;

-- ===================================================================
-- 2. ENHANCED AUDIT LOG TABLE
-- ===================================================================

DROP TABLE IF EXISTS audit_logs;
CREATE TABLE audit_logs (
    log_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action_type VARCHAR(50) NOT NULL,
    table_name VARCHAR(100),
    record_id INT,
    old_value JSON,
    new_value JSON,
    ip_address VARCHAR(45),
    user_agent VARCHAR(500),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX (action_type),
    INDEX (timestamp)
);

-- ===================================================================
-- 3. LOGIN ATTEMPTS TRACKING (Security)
-- ===================================================================

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(100),
    ip_address VARCHAR(45),
    attempt_status ENUM('success', 'failed') DEFAULT 'failed',
    reason VARCHAR(255),
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (email),
    INDEX (ip_address),
    INDEX (attempted_at)
);

-- ===================================================================
-- 4. NOTIFICATIONS LOG
-- ===================================================================

CREATE TABLE IF NOT EXISTS notifications_log (
    notification_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    notification_type ENUM('email', 'sms', 'in_app') DEFAULT 'email',
    subject VARCHAR(255),
    message TEXT,
    recipient VARCHAR(100),
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX (user_id),
    INDEX (status),
    INDEX (created_at)
);

-- ===================================================================
-- 5. USER PREFERENCES (for dark mode, notifications, etc.)
-- ===================================================================

CREATE TABLE IF NOT EXISTS user_preferences (
    preference_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    dark_mode BOOLEAN DEFAULT 0,
    email_notifications BOOLEAN DEFAULT 1,
    sms_notifications BOOLEAN DEFAULT 0,
    language VARCHAR(10) DEFAULT 'en',
    timezone VARCHAR(50) DEFAULT 'UTC',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ===================================================================
-- 6. SYSTEM SETTINGS
-- ===================================================================

CREATE TABLE IF NOT EXISTS system_settings (
    setting_id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value LONGTEXT,
    data_type VARCHAR(20) DEFAULT 'string',
    description TEXT,
    is_public BOOLEAN DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT INTO system_settings (setting_key, setting_value, data_type, description, is_public) VALUES
('app_name', 'Parish Management System', 'string', 'Application Name', 1),
('app_version', '2.0.0', 'string', 'Current App Version', 1),
('maintenance_mode', '0', 'boolean', 'Enable maintenance mode', 0),
('password_min_length', '8', 'integer', 'Minimum password length', 0),
('password_require_special_chars', '1', 'boolean', 'Require special characters in password', 0),
('session_timeout_minutes', '30', 'integer', 'Session timeout in minutes', 0),
('max_login_attempts', '5', 'integer', 'Max failed login attempts before lockout', 0),
('lockout_duration_minutes', '15', 'integer', 'Lockout duration after max attempts', 0),
('cache_ttl_minutes', '30', 'integer', 'Cache time-to-live in minutes', 0),
('smtp_host', '', 'string', 'SMTP Server Host', 0),
('smtp_port', '587', 'string', 'SMTP Server Port', 0),
('smtp_username', '', 'string', 'SMTP Username', 0),
('smtp_password', '', 'string', 'SMTP Password', 0),
('smtp_from_email', '', 'string', 'From Email Address', 0),
('twilio_account_sid', '', 'string', 'Twilio Account SID', 0),
('twilio_auth_token', '', 'string', 'Twilio Auth Token', 0),
('twilio_phone_number', '', 'string', 'Twilio Phone Number', 0);

-- ===================================================================
-- 7. PASSWORD HISTORY (prevent reuse)
-- ===================================================================

CREATE TABLE IF NOT EXISTS password_history (
    history_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX (user_id)
);

-- ===================================================================
-- 8. OPTIMIZE EXISTING TABLES WITH INDEXES
-- ===================================================================

-- Requests table indexes
CREATE INDEX idx_requests_user_status ON requests(user_id, status);
CREATE INDEX idx_requests_type_status ON requests(request_type, status);
CREATE INDEX idx_requests_date ON requests(date_requested);

-- Records table indexes
CREATE INDEX idx_baptism_name_date ON baptism_records(fullname, baptism_date);
CREATE INDEX idx_communion_name_date ON first_communion_records(fullname, communion_date);
CREATE INDEX idx_confirmation_name_date ON confirmation_records(fullname, confirmation_date);
CREATE INDEX idx_marriage_names_date ON marriage_records(husband_name, wife_name, wedding_date);

-- Users table indexes
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_users_role ON users(role);

-- Announcements indexes
CREATE INDEX idx_announcements_status ON announcements(status);
CREATE INDEX idx_announcements_created ON announcements(created_at);

-- ===================================================================
-- 9. SOFT DELETE VIEWS (for querying non-deleted records)
-- ===================================================================

CREATE OR REPLACE VIEW active_users AS
SELECT * FROM users WHERE deleted_at IS NULL;

CREATE OR REPLACE VIEW active_requests AS
SELECT * FROM requests WHERE deleted_at IS NULL;

CREATE OR REPLACE VIEW active_announcements AS
SELECT * FROM announcements WHERE deleted_at IS NULL;

-- ===================================================================
-- 10. UPDATE USER TABLE WITH ADDITIONAL FIELDS
-- ===================================================================

ALTER TABLE users ADD COLUMN last_login TIMESTAMP NULL;
ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45);
ALTER TABLE users ADD COLUMN failed_login_attempts INT DEFAULT 0;
ALTER TABLE users ADD COLUMN account_locked_until TIMESTAMP NULL;

-- ===================================================================
-- 11. ENHANCE REQUESTS TABLE
-- ===================================================================

ALTER TABLE requests ADD COLUMN completion_date TIMESTAMP NULL;
ALTER TABLE requests ADD COLUMN assigned_to INT;
ALTER TABLE requests ADD COLUMN priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal';
ALTER TABLE requests ADD CONSTRAINT fk_requests_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL;

-- ===================================================================
-- 12. APPROVAL WORKFLOW TABLE
-- ===================================================================

CREATE TABLE IF NOT EXISTS request_approvals (
    approval_id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL,
    approved_by INT NOT NULL,
    approval_status ENUM('approved', 'rejected', 'pending_review') DEFAULT 'pending_review',
    notes TEXT,
    reviewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX (request_id),
    INDEX (approved_by)
);

-- ===================================================================
-- 13. CACHE INVALIDATION TRACKING
-- ===================================================================

CREATE TABLE IF NOT EXISTS cache_invalidation (
    cache_key VARCHAR(255) PRIMARY KEY,
    invalidated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ===================================================================
-- END OF SCHEMA IMPROVEMENTS
-- ===================================================================
