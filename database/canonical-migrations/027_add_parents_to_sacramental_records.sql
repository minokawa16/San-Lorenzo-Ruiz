-- Migration 027: Add parents, father_name, and mother_name columns to sacramental records for simplified certificate generation

ALTER TABLE `funeral_records` 
    ADD COLUMN IF NOT EXISTS `parents` VARCHAR(255) NULL AFTER `family_name`,
    ADD COLUMN IF NOT EXISTS `father_name` VARCHAR(150) NULL AFTER `parents`,
    ADD COLUMN IF NOT EXISTS `mother_name` VARCHAR(150) NULL AFTER `father_name`,
    ADD COLUMN IF NOT EXISTS `parish_priest` VARCHAR(120) NULL AFTER `minister`;

ALTER TABLE `first_communion_records`
    ADD COLUMN IF NOT EXISTS `father_name` VARCHAR(150) NULL AFTER `parents`,
    ADD COLUMN IF NOT EXISTS `mother_name` VARCHAR(150) NULL AFTER `father_name`;

ALTER TABLE `confirmation_records`
    ADD COLUMN IF NOT EXISTS `father_name` VARCHAR(150) NULL AFTER `parents`,
    ADD COLUMN IF NOT EXISTS `mother_name` VARCHAR(150) NULL AFTER `father_name`;
