-- Migration: uploaded certificate template files and issuance version pinning
CREATE TABLE IF NOT EXISTS certificate_file_templates (
    template_id INT PRIMARY KEY AUTO_INCREMENT,
    template_name VARCHAR(150) NOT NULL,
    certificate_type VARCHAR(80) NOT NULL,
    file_original_name VARCHAR(255) NOT NULL,
    file_stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size INT NOT NULL DEFAULT 0,
    description TEXT NULL,
    version VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'available',
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cert_template_type (certificate_type),
    INDEX idx_cert_template_active (certificate_type, is_active),
    INDEX idx_cert_template_status (status)
);

ALTER TABLE certificate_issuances
    ADD COLUMN template_id INT NULL AFTER record_id;

ALTER TABLE certificate_issuances
    ADD COLUMN layout_snapshot LONGTEXT NULL AFTER template_id;

CREATE TABLE IF NOT EXISTS certificate_layouts (
    layout_id INT PRIMARY KEY AUTO_INCREMENT,
    certificate_type VARCHAR(80) NOT NULL UNIQUE,
    layout_name VARCHAR(150) NOT NULL,
    layout_settings LONGTEXT NOT NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_certificate_layout_type (certificate_type)
);
