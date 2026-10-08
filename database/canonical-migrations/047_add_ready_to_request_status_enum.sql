-- Migration 047: Add 'ready' and 'ready_for_pickup' to requests status ENUM
ALTER TABLE requests 
MODIFY COLUMN status ENUM('pending', 'processing', 'ready', 'ready_for_pickup', 'completed', 'rejected') NOT NULL DEFAULT 'pending';
