-- Email OTP localhost support.
-- Existing installs may already have this table; this migration keeps the schema compatible.

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
    INDEX idx_otp_user_purpose (user_id, purpose),
    INDEX idx_otp_email_purpose (email, purpose)
);

ALTER TABLE otp_codes MODIFY otp_hash VARCHAR(255) NOT NULL;
