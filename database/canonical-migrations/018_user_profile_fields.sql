-- Phase 18: User Profile Fields (middle_name, suffix, id_type, structured address fields)
-- Allows detailed profile management, profile avatar, and complete address breakdown.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `middle_name` VARCHAR(100) NULL AFTER `first_name`,
  ADD COLUMN IF NOT EXISTS `suffix` VARCHAR(30) NULL AFTER `middle_initial`,
  ADD COLUMN IF NOT EXISTS `id_type` VARCHAR(100) NULL AFTER `nationality`,
  ADD COLUMN IF NOT EXISTS `street_address` VARCHAR(255) NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `barangay` VARCHAR(100) NULL AFTER `street_address`,
  ADD COLUMN IF NOT EXISTS `city` VARCHAR(100) NULL AFTER `barangay`,
  ADD COLUMN IF NOT EXISTS `province` VARCHAR(100) NULL AFTER `city`;
