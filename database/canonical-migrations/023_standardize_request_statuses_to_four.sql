-- Migration 023: Standardize Request Statuses to strictly Four Lifecycle States:
-- 'pending', 'processing', 'completed', 'rejected'

-- 1. Map existing legacy records to the four canonical statuses
UPDATE requests 
SET status = 'processing' 
WHERE status IN ('Under Review', 'Approved', 'Approved / Scheduled', 'approved', 'scheduled', 'requirements_review', 'payment_review', 'ready_for_release');

UPDATE requests 
SET status = 'rejected' 
WHERE status IN ('Declined', 'Declined / Cancelled', 'Cancelled', 'cancelled', 'rejected');

UPDATE requests 
SET status = 'pending' 
WHERE status IN ('submitted', 'draft', 'needs_information', 'payment_required');

-- 2. Modify requests table ENUM definition
ALTER TABLE requests 
MODIFY COLUMN status ENUM('pending', 'processing', 'completed', 'rejected') NOT NULL DEFAULT 'pending';
