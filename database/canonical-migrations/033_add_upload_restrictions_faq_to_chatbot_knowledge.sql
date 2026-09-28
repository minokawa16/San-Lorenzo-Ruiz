-- Migration: 033_add_upload_restrictions_faq_to_chatbot_knowledge.sql
-- Purpose: Add FAQ entries explaining TUGON document upload restrictions (PDF and images only, 5 MB limit)

INSERT INTO chatbot_knowledge (
    knowledge_id, topic, keywords, answer, steps, category, source, status, approval_status, version, effective_date, language, updated_at, content_hash
) VALUES
(
    281,
    'Allowed Document Upload Formats and File Size Limit',
    'allowed file types,allowed formats,file upload format,file size limit,maximum file size,can i upload docx,can i upload word,upload pdf,upload jpg,upload png,upload webp,sukat ng file,anong format ng file',
    'TUGON strictly accepts PDF (.pdf) and image files (.jpg, .jpeg, .png, .webp) up to 5 MB per file. Word documents (.docx, .doc), text files, spreadsheets, and ZIP files are not allowed so all submitted documents can be previewed inline immediately.',
    '1. Ensure your file is in PDF or image format (JPG, PNG, WEBP)\n2. Verify the file size is 5 MB or less\n3. Select and upload your file on the request form\n\n[Request Certificate](../users/request-certificate.php) • [Request Service](../users/request-service.php)',
    'documents',
    'TUGON parish knowledge base',
    'active',
    'approved',
    1,
    '2026-09-28',
    'bilingual',
    NOW(),
    SHA2('upload_format_size_restriction_faq', 256)
)
ON DUPLICATE KEY UPDATE
    topic = VALUES(topic),
    keywords = VALUES(keywords),
    answer = VALUES(answer),
    steps = VALUES(steps),
    category = VALUES(category),
    source = VALUES(source),
    status = VALUES(status),
    approval_status = VALUES(approval_status),
    version = version + 1,
    updated_at = NOW(),
    content_hash = VALUES(content_hash);
