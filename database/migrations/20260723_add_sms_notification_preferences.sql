-- Adds SMS opt-in support for notification categories.

ALTER TABLE notification_preferences
    ADD COLUMN IF NOT EXISTS sms_enabled TINYINT(1) DEFAULT 1 AFTER email_enabled;

UPDATE notification_preferences
SET sms_enabled = 1
WHERE sms_enabled IS NULL;
