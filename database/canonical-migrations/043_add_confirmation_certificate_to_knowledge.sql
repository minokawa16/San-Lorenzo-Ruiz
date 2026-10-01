-- Canonical Migration 043: Add Confirmation Certificate to Confirmation Requirements in chatbot_knowledge
-- Safe-to-run-twice / idempotent update for knowledge record #31 and topic 'Confirmation Requirements'

UPDATE `chatbot_knowledge`
SET `steps` = 'Baptismal Certificate\nConfirmation Certificate\nConfirmation Registration Form\nConfirmation Seminar (recollection)\nConfirmation Sponsor (Godparents)',
    `content_hash` = SHA2(CONCAT_WS('|', `topic`, COALESCE(`keywords`, ''), `answer`, 'Baptismal Certificate\nConfirmation Certificate\nConfirmation Registration Form\nConfirmation Seminar (recollection)\nConfirmation Sponsor (Godparents)'), 256),
    `updated_at` = NOW()
WHERE `knowledge_id` = 31 OR `topic` = 'Confirmation Requirements';
