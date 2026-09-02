-- 013_purge_prince_ondoy_for_reregistration.sql
-- Canonical migration to cleanly remove and purge prince ondoy test account so he can register freshly

-- 1. Delete deliveries for notifications belonging to Prince Ondoy
DELETE FROM notification_deliveries 
WHERE notification_id IN (
    SELECT notification_id FROM notifications 
    WHERE user_id IN (
        SELECT id FROM users 
        WHERE LOWER(email) = 'princeondoy0@gmail.com' 
           OR phone_number = '09631237247' 
           OR LOWER(fullname) LIKE '%ondoy%'
    )
);

-- 2. Delete notifications for Prince Ondoy
DELETE FROM notifications 
WHERE user_id IN (
    SELECT id FROM users 
    WHERE LOWER(email) = 'princeondoy0@gmail.com' 
       OR phone_number = '09631237247' 
       OR LOWER(fullname) LIKE '%ondoy%'
);

-- 3. Delete notification preferences
DELETE FROM notification_preferences 
WHERE user_id IN (
    SELECT id FROM users 
    WHERE LOWER(email) = 'princeondoy0@gmail.com' 
       OR phone_number = '09631237247' 
       OR LOWER(fullname) LIKE '%ondoy%'
);

-- 4. Delete user roles
DELETE FROM user_roles 
WHERE user_id IN (
    SELECT id FROM users 
    WHERE LOWER(email) = 'princeondoy0@gmail.com' 
       OR phone_number = '09631237247' 
       OR LOWER(fullname) LIKE '%ondoy%'
);

-- 5. Delete audit log records
DELETE FROM audit_log 
WHERE user_id IN (
    SELECT id FROM users 
    WHERE LOWER(email) = 'princeondoy0@gmail.com' 
       OR phone_number = '09631237247' 
       OR LOWER(fullname) LIKE '%ondoy%'
);

-- 6. Delete login attempts
DELETE FROM login_attempts 
WHERE user_id IN (
    SELECT id FROM users 
    WHERE LOWER(email) = 'princeondoy0@gmail.com' 
       OR phone_number = '09631237247' 
       OR LOWER(fullname) LIKE '%ondoy%'
);

-- 7. Delete authentication identifiers
DELETE FROM user_auth_identifiers 
WHERE normalized_value = 'princeondoy0@gmail.com' 
   OR normalized_value = '09631237247' 
   OR user_id IN (
       SELECT id FROM users 
       WHERE LOWER(email) = 'princeondoy0@gmail.com' 
          OR phone_number = '09631237247' 
          OR LOWER(fullname) LIKE '%ondoy%'
   );

-- 8. Delete user record from users table
DELETE FROM users 
WHERE LOWER(email) = 'princeondoy0@gmail.com' 
   OR phone_number = '09631237247' 
   OR LOWER(fullname) LIKE '%ondoy%';
