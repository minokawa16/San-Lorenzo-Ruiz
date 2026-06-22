-- Migration: add requirement_name and submission_notes
ALTER TABLE `Requirement_Files`
  ADD COLUMN `requirement_name` VARCHAR(255) DEFAULT NULL AFTER `file_name`;

ALTER TABLE `Requirements_Submissions`
  ADD COLUMN `submission_notes` TEXT DEFAULT NULL AFTER `remarks`;
