-- Canonical migration 031: Remove duplicate marriage requirement from chatbot knowledge
-- Updates knowledge record #30 to remove redundant 'Parents\' latest marriage contract or receipt'

UPDATE chatbot_knowledge 
SET steps = 'Chapel recommendation\nPhotocopy of marriage certificate, if married\nPhotocopy of the child\'s live birth certificate with registry number\nTwo white cards of sponsors\nWhite cards of parents\nPre-baptismal investigation sheet, if requested by the parish office'
WHERE knowledge_id = 30;
