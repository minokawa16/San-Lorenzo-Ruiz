-- Migration Script: Link Sacramental Records to Requests
-- This script adds request_id foreign keys to all sacramental record tables
-- Run this ONCE on your existing database to update the schema

-- Add request_id column to baptism_records
ALTER TABLE baptism_records 
ADD COLUMN request_id INT DEFAULT NULL AFTER baptism_id,
ADD FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL,
ADD INDEX idx_request_id (request_id);

-- Add request_id column to first_communion_records
ALTER TABLE first_communion_records 
ADD COLUMN request_id INT DEFAULT NULL AFTER communion_id,
ADD FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL,
ADD INDEX idx_request_id (request_id);

-- Add request_id column to confirmation_records
ALTER TABLE confirmation_records 
ADD COLUMN request_id INT DEFAULT NULL AFTER confirmation_id,
ADD FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL,
ADD INDEX idx_request_id (request_id);

-- Add request_id column to marriage_records
ALTER TABLE marriage_records 
ADD COLUMN request_id INT DEFAULT NULL AFTER marriage_id,
ADD FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL,
ADD INDEX idx_request_id (request_id);

-- Migration Complete!
-- The sacramental records are now linked to requests.
-- When a request is deleted, the sacramental record's request_id will be set to NULL (preserved but unlinked).
