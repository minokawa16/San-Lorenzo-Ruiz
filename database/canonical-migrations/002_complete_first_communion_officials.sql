-- Fields used by the First Communion editor were missing from the pre-baseline
-- live schema. Keep the correction as an additive canonical migration.
ALTER TABLE `first_communion_records`
    ADD COLUMN `parish_priest` VARCHAR(120) NULL AFTER `remarks`,
    ADD COLUMN `parish_secretary` VARCHAR(120) NULL AFTER `parish_priest`;

