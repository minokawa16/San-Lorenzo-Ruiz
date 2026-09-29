-- Migration 036: Baptism Certificate Support & Parish Priest-in-Charge Setting

-- 1. Ensure godparents is TEXT to support multiple godparents without truncation
ALTER TABLE `baptism_records` MODIFY COLUMN `godparents` TEXT NULL;

-- 2. Ensure system_settings entries for Priest-in-Charge exist with canonical defaults
INSERT INTO `system_settings` (`setting_key`, `setting_value`) 
VALUES ('parish.priest_in_charge', 'REV. FR. HERIBERTO C. VILLAS, O.M.I.')
ON DUPLICATE KEY UPDATE `setting_value` = IF(`setting_value` IS NULL OR `setting_value` = '', VALUES(`setting_value`), `setting_value`);

INSERT INTO `system_settings` (`setting_key`, `setting_value`) 
VALUES ('parish.priest_in_charge_title', 'Priest-in-Charge')
ON DUPLICATE KEY UPDATE `setting_value` = IF(`setting_value` IS NULL OR `setting_value` = '', VALUES(`setting_value`), `setting_value`);

INSERT INTO `system_settings` (`setting_key`, `setting_value`) 
VALUES ('parish_priest_name', 'REV. FR. HERIBERTO C. VILLAS, O.M.I.')
ON DUPLICATE KEY UPDATE `setting_value` = IF(`setting_value` IS NULL OR `setting_value` = '', VALUES(`setting_value`), `setting_value`);

-- 3. Verify and align Rey Mark sample record to match the official parish certificate reference layout
UPDATE `baptism_records`
SET 
    `fullname` = 'REY MARK CANTOMAYOR CAVAÑAS',
    `birth_place` = 'San Mateo, Aleosan, Cotabato',
    `birth_date` = '2005-12-16',
    `parent_address` = 'San Mateo, Aleosan, Cotabato',
    `father_name` = 'Roberto Amualla Cavañas',
    `father_birth_place` = 'San Mateo, Aleosan, Cotabato',
    `mother_name` = 'Joy C. Cantomayor',
    `mother_birth_place` = 'San Mateo, Aleosan, Cotabato',
    `baptism_date` = '2006-04-23',
    `priest` = 'ALVIN VICENTE C. BARRETO, OMI',
    `parish_priest` = 'REV. FR. HERIBERTO C. VILLAS, O.M.I.',
    `godparents` = 'Nida Paredes\nReynante Pan',
    `status` = 'active'
WHERE `baptism_id` = 1 OR `fullname` LIKE '%REY MARK%';
