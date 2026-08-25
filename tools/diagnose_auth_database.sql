-- =============================================================================
-- SQL Diagnostic Script: Authentication, Password Columns, and Hash Integrity
-- Purpose: Run this script in MySQL CLI or phpMyAdmin to inspect:
--          1. Column definitions & character limits for password/hash fields
--          2. Truncated or malformed hashes (length < 60)
--          3. Active login lockout states and failed attempt counts
--          4. Triggers or scheduled events that could alter user records
-- =============================================================================

-- 1. Check all password, hash, and token column definitions in the database
SELECT 
    TABLE_NAME, 
    COLUMN_NAME, 
    DATA_TYPE, 
    CHARACTER_MAXIMUM_LENGTH, 
    COLUMN_TYPE, 
    IS_NULLABLE, 
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND (
      COLUMN_NAME LIKE '%pass%' 
      OR COLUMN_NAME LIKE '%hash%' 
      OR COLUMN_NAME LIKE '%token%' 
      OR COLUMN_NAME LIKE '%auth%'
  )
ORDER BY TABLE_NAME, COLUMN_NAME;

-- 2. Audit stored password hashes in `users` table for truncation or invalid prefixes
SELECT 
    id, 
    email, 
    phone_number, 
    role, 
    status, 
    LENGTH(password) AS hash_length, 
    LEFT(password, 7) AS hash_algorithm_prefix,
    CASE 
        WHEN password IS NULL OR password = '' THEN 'EMPTY (INVALID)'
        WHEN LENGTH(password) < 60 THEN 'TRUNCATED (INVALID - MUST BE AT LEAST 60 CHARS)'
        WHEN LEFT(password, 4) IN ('$2y$', '$2a$', '$2b$') THEN 'VALID BCRYPT'
        WHEN LEFT(password, 7) = '$argon2' THEN 'VALID ARGON2'
        ELSE 'UNKNOWN/UNRECOGNIZED HASH FORMAT'
    END AS hash_health_status,
    password_changed_at,
    must_change_password
FROM users;

-- 3. Check recent failed login attempts and potential throttling locks
SELECT 
    identifier_hash,
    COUNT(*) AS total_failed_attempts,
    MAX(attempted_at) AS most_recent_failure,
    failure_reason
FROM login_attempts
WHERE was_successful = 0 
  AND attempted_at >= NOW() - INTERVAL 1 HOUR
GROUP BY identifier_hash, failure_reason
ORDER BY most_recent_failure DESC;

-- 4. Check for any active MySQL Triggers on auth-related tables
SHOW TRIGGERS WHERE `Table` IN ('users', 'user_auth_identifiers', 'password_security_history');

-- 5. Check for any scheduled MySQL Events (cron jobs inside database)
SHOW EVENTS;
