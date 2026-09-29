-- Migration 037: Schedule Slot Conflict Controls and Concurrency Locks
-- Enforces 60-minute schedule slot locking and prevents concurrent double-booking.

CREATE TABLE IF NOT EXISTS `schedule_slot_locks` (
    `lock_id` INT AUTO_INCREMENT PRIMARY KEY,
    `slot_date` DATE NOT NULL,
    `slot_time` TIME NOT NULL,
    `slot_end_time` TIME NOT NULL,
    `source_type` VARCHAR(40) NOT NULL DEFAULT 'request',
    `source_id` INT NOT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_slot_date_time` (`slot_date`, `slot_time`),
    INDEX `idx_slot_source` (`source_type`, `source_id`),
    INDEX `idx_slot_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
