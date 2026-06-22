-- Request workflow payments tied to the core requests table.
CREATE TABLE IF NOT EXISTS `request_payments` (
  `payment_id` INT PRIMARY KEY AUTO_INCREMENT,
  `request_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `receipt_document_id` INT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `payment_method` VARCHAR(60) NOT NULL,
  `reference_number` VARCHAR(120) NULL,
  `notes` TEXT NULL,
  `status` ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
  `admin_remarks` TEXT NULL,
  `verified_by` INT NULL,
  `verified_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_request_payments_request` (`request_id`),
  INDEX `idx_request_payments_user` (`user_id`),
  INDEX `idx_request_payments_status` (`status`)
);
