-- Add catechist_coordinator and principal to first_communion_records
ALTER TABLE `first_communion_records` 
ADD COLUMN IF NOT EXISTS `catechist_coordinator` VARCHAR(255) NULL AFTER `parish_priest`,
ADD COLUMN IF NOT EXISTS `principal` VARCHAR(255) NULL AFTER `catechist_coordinator`;
