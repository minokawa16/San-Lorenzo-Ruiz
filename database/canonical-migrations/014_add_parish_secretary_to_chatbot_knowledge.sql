-- Canonical Migration 014: Add Parish Secretary knowledge item to chatbot_knowledge
-- Parish Secretary: Agnes C. Calapaan
-- Contact: 0997 742 8176

REPLACE INTO chatbot_knowledge (
    knowledge_id,
    topic,
    keywords,
    answer,
    steps,
    category,
    source,
    status,
    approval_status,
    version,
    effective_date,
    language,
    reviewed_at,
    content_hash
) VALUES (
    46,
    'Parish Secretary',
    'who is the parish secretary,parish secretary,secretary,sino ang secretary,sino ang kalihim,parish secretary name,parish secretary contact,agnes calapaan,agnes,calapaan,secretary po,contact secretary',
    'The Parish Secretary is Agnes C. Calapaan. For official parish transactions, certificate inquiries, and GCash payment verifications, you may contact her at 0997 742 8176.',
    'Contact Agnes C. Calapaan at 0997 742 8176 during official office hours:\nTuesday - Saturday: 8:00 AM - 5:00 PM (Lunch Break: 12:00 PM - 1:00 PM)\nSunday: 7:00 AM - 12:00 PM\nMonday: Office Closed',
    'office',
    'TUGON parish knowledge base',
    'active',
    'approved',
    1,
    '2026-07-12',
    'bilingual',
    NOW(),
    '7b8b26cb1e2e3f6b545434084cf63c117486de194390489b40e28f693fdea8a8'
);

REPLACE INTO chatbot_knowledge_meta (meta_key, meta_value)
VALUES ('official_dataset_version', '2026-09-03-canonical-v2');
