-- Migration: create requirements and payments tables
CREATE TABLE IF NOT EXISTS `Requirements_Submissions` (
  `submission_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `certificate_type` VARCHAR(50) NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'Requirements Pending Review',
  `remarks` TEXT,
  `submitted_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `Requirement_Files` (
  `file_id` INT AUTO_INCREMENT PRIMARY KEY,
  `submission_id` INT NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(1024) NOT NULL,
  `file_type` VARCHAR(50) NOT NULL,
  `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`submission_id`) REFERENCES `Requirements_Submissions`(`submission_id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `Payment_Transactions` (
  `payment_id` INT AUTO_INCREMENT PRIMARY KEY,
  `submission_id` INT DEFAULT NULL,
  `payment_method` VARCHAR(50) NOT NULL,
  `reference_number` VARCHAR(255),
  `transaction_number` VARCHAR(255),
  `amount` DECIMAL(10,2) NOT NULL,
  `receipt_file` VARCHAR(1024),
  `verification_status` VARCHAR(50) NOT NULL DEFAULT 'Pending Verification',
  `verified_by` INT DEFAULT NULL,
  `verified_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`submission_id`) REFERENCES `Requirements_Submissions`(`submission_id`) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS `Notifications` (
  `notification_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `Audit_Logs` (
  `log_id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `action` VARCHAR(255) NOT NULL,
  `details` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
