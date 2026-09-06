-- 020_create_sacramental_records_views.sql
-- Creates canonical updatable views for sacramental records to match standard Book register naming conventions.

CREATE OR REPLACE VIEW sacramental_records_baptism AS 
SELECT * FROM baptism_records;

CREATE OR REPLACE VIEW sacramental_records_marriage AS 
SELECT * FROM marriage_records;

CREATE OR REPLACE VIEW sacramental_records_death AS 
SELECT * FROM funeral_records;
