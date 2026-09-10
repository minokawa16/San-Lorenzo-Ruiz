-- Migration 026: Add parent birthplaces and names as first-class columns in baptism_records

-- Add father_name if not exists
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'baptism_records' AND COLUMN_NAME = 'father_name');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE baptism_records ADD COLUMN father_name VARCHAR(150) NULL AFTER parents', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add father_birth_place if not exists
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'baptism_records' AND COLUMN_NAME = 'father_birth_place');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE baptism_records ADD COLUMN father_birth_place VARCHAR(150) NULL AFTER father_name', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add mother_name if not exists
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'baptism_records' AND COLUMN_NAME = 'mother_name');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE baptism_records ADD COLUMN mother_name VARCHAR(150) NULL AFTER father_birth_place', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add mother_birth_place if not exists
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'baptism_records' AND COLUMN_NAME = 'mother_birth_place');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE baptism_records ADD COLUMN mother_birth_place VARCHAR(150) NULL AFTER mother_name', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Populate Rey Mark Cantomayor Cavañas records with authentic values
UPDATE baptism_records 
SET 
    father_name = 'Roberto Amualla Cavañas',
    father_birth_place = 'San Mateo, Aleosan, Cotabato',
    mother_name = 'Joy C. Cantomayor',
    mother_birth_place = 'San Mateo, Aleosan, Cotabato'
WHERE fullname LIKE '%REY MARK%' OR baptism_id IN (1, 2, 3);
