-- Phase 029: Extend email column length for Facebook profile links and social handles
ALTER TABLE `org_members` MODIFY COLUMN `email` VARCHAR(255) NULL;
