-- Migration 028: Merge family_name into deceased_name in funeral_records

-- For records where family_name is set and not already present in deceased_name, concatenate it
UPDATE `funeral_records`
SET `deceased_name` = CONCAT(TRIM(`deceased_name`), ' ', TRIM(`family_name`))
WHERE `family_name` IS NOT NULL 
  AND TRIM(`family_name`) != '' 
  AND TRIM(`deceased_name`) NOT LIKE CONCAT('%', TRIM(`family_name`), '%');

-- Nullify family_name for all records to finalize the consolidation
UPDATE `funeral_records`
SET `family_name` = NULL
WHERE `family_name` IS NOT NULL;
