-- Migration: create certificate requests and related tables
CREATE TABLE IF NOT EXISTS `Certificate_Requests` (
  `request_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `certificate_type` VARCHAR(50) NOT NULL,
  `release_method` VARCHAR(50) NOT NULL,
  `request_status` VARCHAR(50) NOT NULL DEFAULT 'Pending Review',
  `submitted_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `Request_Requirements` (
  `requirement_id` INT AUTO_INCREMENT PRIMARY KEY,
  `request_id` INT NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(1024) NOT NULL,
  `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`request_id`) REFERENCES `Certificate_Requests`(`request_id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `Payment_Receipts` (
  `receipt_id` INT AUTO_INCREMENT PRIMARY KEY,
  `request_id` INT NOT NULL,
  `payment_method` VARCHAR(50) NOT NULL,
  `reference_number` VARCHAR(255),
  `transaction_number` VARCHAR(255),
  `amount` DECIMAL(10,2) DEFAULT 0.00,
  `receipt_file` VARCHAR(1024),
  `verification_status` VARCHAR(50) NOT NULL DEFAULT 'Pending Verification',
  `verified_by` INT DEFAULT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`request_id`) REFERENCES `Certificate_Requests`(`request_id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `Certificate_Files` (
  `certificate_id` INT AUTO_INCREMENT PRIMARY KEY,
  `request_id` INT NOT NULL,
  `certificate_file` VARCHAR(1024) NOT NULL,
  `uploaded_by` INT NOT NULL,
  `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`request_id`) REFERENCES `Certificate_Requests`(`request_id`) ON DELETE CASCADE
);
