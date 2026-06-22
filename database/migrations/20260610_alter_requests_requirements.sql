-- Migration: add requirement_name to Request_Requirements and request_notes to Certificate_Requests
ALTER TABLE `Request_Requirements`
  ADD COLUMN `requirement_name` VARCHAR(255) DEFAULT NULL AFTER `file_name`;

ALTER TABLE `Certificate_Requests`
  ADD COLUMN `request_notes` TEXT DEFAULT NULL AFTER `request_status`;
