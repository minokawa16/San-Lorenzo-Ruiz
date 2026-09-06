-- Phase 19: Normalize 'submitted' status to 'pending' across request workflow
-- Changes default status on requests table to 'pending' and updates any legacy 'submitted' records.

ALTER TABLE requests MODIFY status ENUM('draft','submitted','requirements_review','needs_information','payment_required','payment_review','approved','scheduled','processing','ready_for_release','completed','rejected','cancelled','pending') NOT NULL DEFAULT 'pending';

UPDATE requests SET status = 'pending' WHERE status = 'submitted';
