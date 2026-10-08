-- Canonical Migration 049: Rename status Released to Completed and sync certificate files
-- Idempotent, safe to run multiple times, updates rows and notification templates.

-- 1. Merge any requests with status 'released' to 'completed'
UPDATE `requests` 
SET `status` = 'completed', `updated_at` = NOW() 
WHERE LOWER(`status`) = 'released';

-- 2. Populate certificate_file_path and certificate_file_name on requests from request_documents if missing
UPDATE `requests` r
JOIN (
    SELECT rd.request_id, rd.file_path, rd.original_name, rd.uploaded_by, rd.uploaded_at
    FROM request_documents rd
    INNER JOIN (
        SELECT request_id, MAX(document_id) AS max_doc_id
        FROM request_documents
        WHERE document_type = 'released_certificate' AND deleted_at IS NULL
        GROUP BY request_id
    ) m ON rd.document_id = m.max_doc_id
) d ON r.request_id = d.request_id
SET r.certificate_file_path = d.file_path,
    r.certificate_file_name = d.original_name,
    r.certificate_uploaded_by = COALESCE(r.certificate_uploaded_by, d.uploaded_by),
    r.certificate_uploaded_at = COALESCE(r.certificate_uploaded_at, d.uploaded_at)
WHERE r.certificate_file_path IS NULL OR r.certificate_file_path = '';

-- 3. Update notification templates from 'released' to 'completed'
UPDATE `notification_templates` 
SET `title_template` = 'Certificate Completed',
    `in_app_template` = 'Your official certificate is completed and ready for download.',
    `email_subject_template` = 'Certificate Completed - San Lorenzo Ruiz Parish',
    `sms_template` = 'TUGON: Your official certificate is completed and ready for download.'
WHERE `notification_type` IN ('certificate_released', 'certificate_ready_for_download');

-- 4. Clean up any historical notification message texts referencing 'released'
UPDATE `notifications` 
SET `message` = REPLACE(`message`, 'has been released and is ready', 'has been completed and is ready') 
WHERE `message` LIKE '%has been released%';

UPDATE `notifications` 
SET `title` = REPLACE(`title`, 'Released', 'Completed') 
WHERE `title` LIKE '%Released%';

UPDATE `sms_notification_logs` 
SET `message` = REPLACE(`message`, 'has been released and is ready', 'has been completed and is ready') 
WHERE `message` LIKE '%has been released%';
