-- Admin Password Repair Script - Resets the administrator password during local setup or recovery.

USE parish_management_system;
UPDATE users SET password = '$2y$10$MSxxVEVGY25e08MyKYkhKeJLXcW5PdJT1jnrEsYOMLYJS1wyosof' WHERE role='admin';
SELECT id, email, role, password FROM users WHERE role='admin';
