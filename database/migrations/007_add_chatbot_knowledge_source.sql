ALTER TABLE chatbot_knowledge
    ADD COLUMN IF NOT EXISTS source VARCHAR(255) NULL AFTER category;
