-- Synthetic development data only. Never run against production.
-- Password for all seeded accounts: DevOnly!2026 (change immediately).
SET @pw = '$2y$12$9g7YjWm7Yz7fH1xQ5q4m9u9xQw4sZcKq0m9V6bQm8Jm5lYx2p3r4S';

INSERT INTO users (fullname, first_name, surname, email, password, role, status, login_otp_enabled)
VALUES
 ('Synthetic Administrator','Synthetic','Administrator','dev.admin@example.test',@pw,'admin','active',1),
 ('Synthetic Parish Staff','Synthetic','Staff','dev.staff@example.test',@pw,'user','active',1),
 ('Synthetic Records Clerk','Synthetic','Clerk','dev.records@example.test',@pw,'user','active',1),
 ('Synthetic Finance Staff','Synthetic','Finance','dev.finance@example.test',@pw,'user','active',1),
 ('Synthetic Parishioner One','Synthetic','Parishioner','dev.parishioner1@example.test',@pw,'user','active',0),
 ('Synthetic Parishioner Pending','Synthetic','Pending','dev.pending@example.test',@pw,'user','pending_verification',0),
 ('Synthetic Parishioner Archived','Synthetic','Archived','dev.archived@example.test',@pw,'user','archived',0)
ON DUPLICATE KEY UPDATE fullname=VALUES(fullname);

INSERT IGNORE INTO user_roles (user_id, role_id)
SELECT u.id, r.role_id FROM users u JOIN roles r ON r.role_key = CASE
 WHEN u.email='dev.admin@example.test' THEN 'administrator'
 ELSE 'parishioner' END
WHERE u.email LIKE 'dev.%@example.test';

INSERT INTO requests (user_id, request_type, description, status, reference_number)
SELECT id, 'certificate', 'Synthetic development request', 'pending', CONCAT('SEED-', id, '-REQ')
FROM users WHERE email='dev.parishioner1@example.test'
ON DUPLICATE KEY UPDATE description=VALUES(description);

INSERT INTO reservations (user_id, reservation_type, event_date, event_time, event_details, status)
SELECT id, 'church_venue', DATE_ADD(CURDATE(), INTERVAL 14 DAY), '10:00:00', 'Synthetic development reservation', 'pending'
FROM users WHERE email='dev.parishioner1@example.test';

INSERT INTO notifications (user_id, title, message, is_read)
SELECT id, 'Synthetic notification', 'Development-only notification.', 0
FROM users WHERE email='dev.parishioner1@example.test';

INSERT INTO announcements (title, content, published_by, published_date, status)
SELECT 'Synthetic development announcement', 'Development-only announcement.', id, NOW(), 'active'
FROM users WHERE email='dev.admin@example.test';
