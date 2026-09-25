-- Canonical migration 032: Add request record matching fields
-- Adds matched_record_id, matched_record_type, match_status, and match_details to requests table
-- Allows fast-tracking certificate generation and clear manual review indicators

ALTER TABLE requests
ADD COLUMN matched_record_id INT NULL DEFAULT NULL AFTER record_holder_name,
ADD COLUMN matched_record_type VARCHAR(50) NULL DEFAULT NULL AFTER matched_record_id,
ADD COLUMN match_status ENUM('unmatched', 'matched', 'multiple', 'no_match') NOT NULL DEFAULT 'unmatched' AFTER matched_record_type,
ADD COLUMN match_details TEXT NULL DEFAULT NULL AFTER match_status;

ALTER TABLE requests
ADD INDEX idx_request_matched_record (matched_record_type, matched_record_id),
ADD INDEX idx_request_match_status (match_status);
