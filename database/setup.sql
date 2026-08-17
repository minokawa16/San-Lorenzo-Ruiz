-- Database Setup Schema - Creates the core parish management database tables and seed data.
-- Create Parish Management System Database
CREATE DATABASE IF NOT EXISTS parish_management_system;
USE parish_management_system;

-- Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fullname VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    surname VARCHAR(100),
    middle_initial VARCHAR(5),
    phone_number VARCHAR(20) UNIQUE NULL,
    email VARCHAR(150) UNIQUE NULL,
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    email_verification_sent_at TIMESTAMP NULL DEFAULT NULL,
    phone_verified_at TIMESTAMP NULL DEFAULT NULL,
    verification_method ENUM('email','mobile') DEFAULT 'email',
    login_otp_enabled TINYINT(1) DEFAULT 0,
    chapel_district VARCHAR(255),
    address VARCHAR(255),
    birthdate DATE,
    id_number_hash CHAR(64),
    id_number_encrypted TEXT,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') DEFAULT 'user',
    status ENUM('active', 'inactive', 'pending_verification', 'rejected', 'archived') DEFAULT 'active',
    profile_picture VARCHAR(255),
    valid_id_path VARCHAR(255),
    valid_id_original_name VARCHAR(255),
    valid_id_mime_type VARCHAR(100),
    valid_id_capture_method VARCHAR(40) DEFAULT 'live_camera',
    face_image_path VARCHAR(255),
    face_image_mime_type VARCHAR(100),
    face_verification_status VARCHAR(40) DEFAULT 'pending',
    face_verified_at TIMESTAMP NULL DEFAULT NULL,
    rejection_reason TEXT,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    verified_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_id_number_hash (id_number_hash)
);

-- Requests Table
CREATE TABLE IF NOT EXISTS requests (
    request_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    request_type VARCHAR(80) NOT NULL,
    description TEXT,
    status ENUM('pending', 'approved', 'rejected', 'processing', 'completed') DEFAULT 'pending',
    date_requested TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    admin_response TEXT,
    reference_number VARCHAR(50) UNIQUE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Request Requirement Documents Table
CREATE TABLE IF NOT EXISTS request_documents (
    document_id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL,
    uploaded_by INT NOT NULL,
    document_type VARCHAR(60) DEFAULT 'requirement',
    requirement_name VARCHAR(160) NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_request_documents_request (request_id),
    INDEX idx_request_documents_uploader (uploaded_by)
);

-- Baptism Records Table
CREATE TABLE IF NOT EXISTS baptism_records (
    baptism_id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT,
    registry_no VARCHAR(50),
    fullname VARCHAR(100) NOT NULL,
    birth_date DATE,
    birth_place VARCHAR(150),
    birth_status VARCHAR(80),
    baptism_date DATE,
    parents VARCHAR(200),
    parent_address VARCHAR(200),
    godparents VARCHAR(200),
    parish_address VARCHAR(200),
    priest VARCHAR(100),
    remarks TEXT,
    parish_priest VARCHAR(120),
    parish_secretary VARCHAR(120),
    status ENUM('active', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL,
    INDEX (fullname),
    INDEX (baptism_date),
    INDEX (request_id)
);

-- First Communion Records Table
CREATE TABLE IF NOT EXISTS first_communion_records (
    communion_id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT,
    registry_no VARCHAR(50),
    fullname VARCHAR(100) NOT NULL,
    birth_date DATE,
    communion_date DATE,
    domicile VARCHAR(150),
    parents VARCHAR(200),
    sponsor VARCHAR(100),
    priest VARCHAR(100),
    folio VARCHAR(50),
    baptismal_date DATE,
    baptismal_place VARCHAR(150),
    remarks TEXT,
    parish_priest VARCHAR(120),
    parish_secretary VARCHAR(120),
    status ENUM('active', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL,
    INDEX (fullname),
    INDEX (communion_date),
    INDEX (request_id)
);

-- Confirmation Records Table
CREATE TABLE IF NOT EXISTS confirmation_records (
    confirmation_id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT,
    registry_no VARCHAR(50),
    fullname VARCHAR(100) NOT NULL,
    birth_date DATE,
    confirmation_date DATE,
    confirmation_name VARCHAR(100),
    age VARCHAR(30),
    origin_parish VARCHAR(150),
    origin_province VARCHAR(150),
    baptismal_place VARCHAR(150),
    parents VARCHAR(200),
    sponsor VARCHAR(100),
    bishop_priest VARCHAR(100),
    stipend_pesos VARCHAR(30),
    stipend_cents VARCHAR(30),
    observations TEXT,
    parish_priest VARCHAR(120),
    parish_secretary VARCHAR(120),
    status ENUM('active', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL,
    INDEX (fullname),
    INDEX (confirmation_date),
    INDEX (request_id)
);

-- Marriage Records Table
CREATE TABLE IF NOT EXISTS marriage_records (
    marriage_id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT,
    registry_no VARCHAR(50),
    husband_name VARCHAR(100) NOT NULL,
    husband_status VARCHAR(80),
    husband_age VARCHAR(30),
    husband_birth_origin VARCHAR(150),
    husband_residence VARCHAR(200),
    husband_parents VARCHAR(200),
    wife_name VARCHAR(100) NOT NULL,
    wife_status VARCHAR(80),
    wife_age VARCHAR(30),
    wife_birth_origin VARCHAR(150),
    wife_residence VARCHAR(200),
    wife_parents VARCHAR(200),
    wedding_date DATE,
    sponsors VARCHAR(200),
    witnesses_residence VARCHAR(200),
    officiating_priest VARCHAR(100),
    remarks TEXT,
    parish_priest VARCHAR(120),
    parish_secretary VARCHAR(120),
    status ENUM('active', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL,
    INDEX (husband_name),
    INDEX (wife_name),
    INDEX (wedding_date),
    INDEX (request_id)
);

-- Funeral Records Table
CREATE TABLE IF NOT EXISTS funeral_records (
    funeral_id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT,
    registry_no VARCHAR(50),
    deceased_name VARCHAR(150) NOT NULL,
    family_name VARCHAR(150),
    date_of_death DATE,
    date_of_burial DATE,
    civil_status VARCHAR(80),
    funeral_rites VARCHAR(120),
    cause_of_death VARCHAR(200),
    place_of_burial VARCHAR(200),
    minister VARCHAR(120),
    remarks TEXT,
    status ENUM('active', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (deceased_name),
    INDEX (family_name),
    INDEX (date_of_burial),
    INDEX (request_id)
);

-- Announcements Table
CREATE TABLE IF NOT EXISTS announcements (
    announcement_id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    image_path VARCHAR(255),
    attachment_path VARCHAR(255),
    attachment_original_name VARCHAR(255),
    attachment_mime_type VARCHAR(120),
    attachment_size INT UNSIGNED,
    type VARCHAR(50) DEFAULT 'announcement',
    published_by INT NOT NULL,
    published_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expiry_date DATETIME,
    event_date DATE,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (published_by) REFERENCES users(id),
    INDEX (published_date)
);

-- Reservations Table
CREATE TABLE IF NOT EXISTS reservations (
    reservation_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    reservation_type ENUM('wedding', 'baptism', 'confirmation', 'burial', 'church_venue') NOT NULL,
    event_date DATE NOT NULL,
    event_time TIME,
    event_details TEXT,
    status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending',
    admin_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX (event_date),
    UNIQUE KEY unique_reservation (reservation_type, event_date, event_time)
);

-- Schedule Events Table
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

-- Certificate Templates Table
CREATE TABLE IF NOT EXISTS certificate_templates (
    template_id INT PRIMARY KEY AUTO_INCREMENT,
    certificate_type ENUM('baptismal', 'confirmation', 'first_communion') NOT NULL,
    template_content LONGTEXT NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Notifications Table
CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX (user_id, is_read)
);

-- AI Chatbot Inquiry Reports Table
CREATE TABLE IF NOT EXISTS chatbot_inquiries (
    inquiry_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    user_role VARCHAR(30) DEFAULT 'user',
    question TEXT NOT NULL,
    answer_preview TEXT,
    mode VARCHAR(40) DEFAULT 'chat',
    context_limited TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_chatbot_inquiries_created (created_at),
    INDEX idx_chatbot_inquiries_user (user_id)
);

-- OTP Codes Table
CREATE TABLE IF NOT EXISTS otp_codes (
    otp_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    email VARCHAR(150) NOT NULL,
    purpose VARCHAR(40) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts INT DEFAULT 0,
    verified_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_otp_user_purpose (user_id, purpose),
    INDEX idx_otp_email_purpose (email, purpose)
);

-- SMS Notification Logs Table
CREATE TABLE IF NOT EXISTS sms_notification_logs (
    log_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    phone_number VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    notification_type VARCHAR(80) DEFAULT 'system',
    delivery_status VARCHAR(30) DEFAULT 'pending',
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_sms_logs_phone (phone_number),
    INDEX idx_sms_logs_created (created_at)
);

-- Audit Log Table
CREATE TABLE IF NOT EXISTS audit_log (
    log_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    old_value LONGTEXT,
    new_value LONGTEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX (created_at)
);

-- Create default admin user (password: admin123)
INSERT INTO users (fullname, phone_number, email, chapel_district, password, role, status) 
VALUES ('Admin', '555-0000', 'admin@gmail.com', 'Main Chapel', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36DxYXpm', 'admin', 'active')
ON DUPLICATE KEY UPDATE id=id;

-- Create default certificate templates
INSERT INTO certificate_templates (certificate_type, template_content, created_by) 
VALUES 
    ('baptismal', '<h1>Baptismal Certificate</h1><p>Full Name: {{fullname}}</p><p>Birth Date: {{birth_date}}</p><p>Baptism Date: {{baptism_date}}</p>', 1),
    ('confirmation', '<h1>Confirmation Certificate</h1><p>Full Name: {{fullname}}</p><p>Confirmation Date: {{confirmation_date}}</p>', 1),
    ('first_communion', '<h1>First Communion Certificate</h1><p>Full Name: {{fullname}}</p><p>Communion Date: {{communion_date}}</p>', 1)
ON DUPLICATE KEY UPDATE template_id=template_id;
