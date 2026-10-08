-- Canonical Migration 051: Update Confirmation Requirements in chatbot_knowledge
-- Replace "Confirmation Certificate" with "First Communion Certificate" in the Confirmation requirements list
UPDATE `chatbot_knowledge`
SET `steps` = 'Baptismal Certificate\nFirst Communion Certificate\nConfirmation Registration Form\nConfirmation Seminar (recollection)\nConfirmation Sponsor (Godparents)',
    `content_hash` = SHA2(CONCAT_WS('|', `topic`, COALESCE(`keywords`, ''), `answer`, 'Baptismal Certificate\nFirst Communion Certificate\nConfirmation Registration Form\nConfirmation Seminar (recollection)\nConfirmation Sponsor (Godparents)'), 256),
    `updated_at` = NOW()
WHERE `knowledge_id` = 31 OR `topic` = 'Confirmation Requirements';
