-- Tracks queued email/SMS announcement delivery separately so posting stays fast.

ALTER TABLE announcement_recipients
    ADD COLUMN IF NOT EXISTS sms_delivery_status VARCHAR(30) DEFAULT 'skipped' AFTER delivery_status,
    ADD COLUMN IF NOT EXISTS sms_sent_at DATETIME NULL AFTER sent_at,
    ADD COLUMN IF NOT EXISTS last_error TEXT NULL AFTER sms_sent_at;
