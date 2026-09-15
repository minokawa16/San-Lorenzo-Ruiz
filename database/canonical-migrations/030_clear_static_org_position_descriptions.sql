-- Phase 030: Clear static hardcoded position descriptions so cards are clean and blank by default
UPDATE `org_positions` 
SET `description` = NULL 
WHERE `description` IN (
    'Pastoral & Canonical Head of Mission Station',
    'Assistant Pastoral & Liturgical Ministry',
    'Chancery, Office & Sacramental Operations Head',
    'Chancery & Office Operations',
    'Parish Pastoral Council Executive Board President',
    'Parish Pastoral Council Executive Board Vice President',
    'Parish Pastoral Council Executive Board Secretary',
    'Parish Pastoral Council Executive Board Treasurer',
    'Liturgical planning, choir coordination, and liturgical servers',
    'Youth empowerment, faith formation, and campus fellowship',
    'Religious instruction, sacraments preparation, and bible apostolate',
    'Hospitality, mass ushering, and order maintenance',
    'Test desc'
) OR `is_system_role` = 1;
