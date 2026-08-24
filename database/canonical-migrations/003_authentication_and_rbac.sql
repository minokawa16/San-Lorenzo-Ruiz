-- Phase 2: centralized authentication, OTP transactions, account history, and RBAC.

ALTER TABLE `users`
    ADD COLUMN `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password`,
    ADD COLUMN `password_changed_at` DATETIME NULL AFTER `must_change_password`,
    ADD COLUMN `account_state_changed_at` DATETIME NULL AFTER `status`;

CREATE TABLE `roles` (
    `role_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_key` VARCHAR(64) NOT NULL,
    `display_name` VARCHAR(120) NOT NULL,
    `is_staff` TINYINT(1) NOT NULL DEFAULT 0,
    `is_system` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`role_id`),
    UNIQUE KEY `uniq_roles_key` (`role_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permissions` (
    `permission_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `permission_key` VARCHAR(120) NOT NULL,
    `display_name` VARCHAR(160) NOT NULL,
    `category` VARCHAR(80) NOT NULL,
    `description` VARCHAR(255) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`permission_id`),
    UNIQUE KEY `uniq_permissions_key` (`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `role_permissions` (
    `role_id` INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    `granted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`role_id`, `permission_id`),
    CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_roles` (
    `user_id` INT NOT NULL,
    `role_id` INT UNSIGNED NOT NULL,
    `assigned_by` INT NULL,
    `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `role_id`),
    KEY `idx_user_roles_role` (`role_id`),
    CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_roles_assigner` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_auth_identifiers` (
    `identifier_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `identifier_type` ENUM('email','mobile') NOT NULL,
    `normalized_value` VARCHAR(190) NOT NULL,
    `verified_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`identifier_id`),
    UNIQUE KEY `uniq_auth_identifier` (`identifier_type`, `normalized_value`),
    UNIQUE KEY `uniq_user_identifier_type` (`user_id`, `identifier_type`),
    CONSTRAINT `fk_auth_identifiers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* Import only unambiguous legacy email identities.  Do not fail (or guess an
   owner) when old data contains duplicate email addresses.  Those accounts
   remain intact and can be recovered by an administrator or by a newly
   verified contact method. */
INSERT INTO `user_auth_identifiers` (`user_id`, `identifier_type`, `normalized_value`, `verified_at`)
SELECT u.`id`, 'email', LOWER(TRIM(u.`email`)), u.`email_verified_at`
FROM `users` u
JOIN (
    SELECT LOWER(TRIM(`email`)) AS normalized_email
    FROM `users`
    WHERE `email` IS NOT NULL AND TRIM(`email`) <> ''
    GROUP BY LOWER(TRIM(`email`))
    HAVING COUNT(*) = 1
) unique_email ON unique_email.normalized_email = LOWER(TRIM(u.`email`))
WHERE u.`email` IS NOT NULL AND TRIM(u.`email`) <> '';

INSERT INTO `user_auth_identifiers` (`user_id`, `identifier_type`, `normalized_value`, `verified_at`)
SELECT normalized.user_id, 'mobile', normalized.mobile, normalized.phone_verified_at
FROM (
    SELECT
        u.id AS user_id,
        u.phone_verified_at,
        CASE
            WHEN REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(u.phone_number), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') LIKE '639%'
                THEN CONCAT('0', SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(u.phone_number), ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), 3))
            ELSE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(u.phone_number), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')
        END AS mobile
    FROM users u
    WHERE u.phone_number IS NOT NULL AND TRIM(u.phone_number) <> ''
) normalized
JOIN (
    SELECT mobile, COUNT(*) AS total
    FROM (
        SELECT CASE
            WHEN REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone_number), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') LIKE '639%'
                THEN CONCAT('0', SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone_number), ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), 3))
            ELSE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(phone_number), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')
        END AS mobile
        FROM users
        WHERE phone_number IS NOT NULL AND TRIM(phone_number) <> ''
    ) grouped_mobile
    GROUP BY mobile
    HAVING COUNT(*) = 1
) unique_mobile ON unique_mobile.mobile = normalized.mobile
WHERE normalized.mobile REGEXP '^09[0-9]{9}$';

CREATE TABLE `login_attempts` (
    `attempt_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NULL,
    `identifier_hash` CHAR(64) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `was_successful` TINYINT(1) NOT NULL DEFAULT 0,
    `failure_reason` VARCHAR(80) NULL,
    `user_agent` VARCHAR(500) NULL,
    `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`attempt_id`),
    KEY `idx_login_attempt_user_time` (`user_id`, `attempted_at`),
    KEY `idx_login_attempt_identifier_time` (`identifier_hash`, `attempted_at`),
    KEY `idx_login_attempt_ip_time` (`ip_address`, `attempted_at`),
    CONSTRAINT `fk_login_attempt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `otp_transactions` (
    `otp_transaction_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(64) NOT NULL,
    `user_id` INT NOT NULL,
    `purpose` VARCHAR(40) NOT NULL,
    `delivery_method` ENUM('email','mobile') NOT NULL,
    `destination` VARCHAR(190) NOT NULL,
    `otp_hash` VARCHAR(255) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    `resend_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `last_sent_at` DATETIME NOT NULL,
    `verified_at` DATETIME NULL,
    `consumed_at` DATETIME NULL,
    `invalidated_at` DATETIME NULL,
    `request_ip` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(500) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`otp_transaction_id`),
    UNIQUE KEY `uniq_otp_public_id` (`public_id`),
    KEY `idx_otp_transaction_user_purpose` (`user_id`, `purpose`, `created_at`),
    KEY `idx_otp_transaction_ip_time` (`request_ip`, `created_at`),
    KEY `idx_otp_transaction_expiry` (`expires_at`, `invalidated_at`, `consumed_at`),
    CONSTRAINT `fk_otp_transaction_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_security_history` (
    `history_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `password_hash` VARCHAR(255) NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `change_source` VARCHAR(80) NOT NULL,
    `actor_user_id` INT NULL,
    `ip_address` VARCHAR(45) NULL,
    `metadata` LONGTEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`history_id`),
    KEY `idx_password_history_user_time` (`user_id`, `created_at`),
    CONSTRAINT `fk_password_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_password_history_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `account_status_history` (
    `history_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `previous_status` VARCHAR(40) NULL,
    `new_status` VARCHAR(40) NOT NULL,
    `action` VARCHAR(60) NOT NULL,
    `reason` TEXT NULL,
    `actor_user_id` INT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(500) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`history_id`),
    KEY `idx_account_status_user_time` (`user_id`, `created_at`),
    CONSTRAINT `fk_account_status_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_account_status_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `registration_reviews` (
    `review_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `review_action` ENUM('submitted','approved','rejected','resubmitted') NOT NULL,
    `previous_status` VARCHAR(40) NULL,
    `new_status` VARCHAR(40) NOT NULL,
    `reason` TEXT NULL,
    `reviewed_by` INT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(500) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`review_id`),
    KEY `idx_registration_reviews_user_time` (`user_id`, `created_at`),
    CONSTRAINT `fk_registration_reviews_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_registration_reviews_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`role_key`, `display_name`, `is_staff`) VALUES
    ('administrator', 'Administrator', 1),
    ('parish_staff', 'Parish Staff', 1),
    ('records_clerk', 'Records Clerk', 1),
    ('finance_staff', 'Finance Staff', 1),
    ('parishioner', 'Parishioner', 0);

INSERT INTO `permissions` (`permission_key`, `display_name`, `category`, `description`) VALUES
    ('admin.access', 'Access administration area', 'Administration', 'Open protected staff pages'),
    ('dashboard.view', 'View dashboard', 'General', 'View the role dashboard'),
    ('users.view', 'View users', 'Users', 'View parishioner and staff accounts'),
    ('users.manage', 'Manage users', 'Users', 'Update or archive user accounts'),
    ('registrations.verify', 'Review registrations', 'Users', 'Approve or reject registration submissions'),
    ('roles.manage', 'Manage staff roles', 'Security', 'Assign staff roles and permissions'),
    ('mfa.manage', 'Manage MFA policy', 'Security', 'Manage account MFA settings'),
    ('requests.view', 'View requests', 'Requests', 'View parish service requests'),
    ('requests.manage', 'Manage requests', 'Requests', 'Approve and update parish service requests'),
    ('records.view', 'View sacramental records', 'Records', 'Read sacramental records'),
    ('records.manage', 'Manage sacramental records', 'Records', 'Create and update sacramental records'),
    ('certificates.manage', 'Manage certificates', 'Certificates', 'Generate and manage certificates and templates'),
    ('reservations.manage', 'Manage reservations', 'Reservations', 'Manage facility and service reservations'),
    ('announcements.manage', 'Manage announcements', 'Communications', 'Create and publish announcements'),
    ('notifications.manage', 'Manage notifications', 'Communications', 'Send and manage notifications'),
    ('payments.verify', 'Verify payments', 'Finance', 'Review payment evidence and financial status'),
    ('financial.view', 'View financial information', 'Finance', 'View financial reports and request payments'),
    ('reports.view', 'View reports', 'Reports', 'Generate and view reports'),
    ('calendar.manage', 'Manage calendar', 'Calendar', 'Manage parish schedule events'),
    ('audit.view', 'View audit logs', 'Security', 'Review security and application audit history'),
    ('system.settings', 'Change security settings', 'Security', 'Manage system and security settings'),
    ('archives.manage', 'Archive records', 'Records', 'Archive and restore records'),
    ('ai.use', 'Use AI assistant', 'General', 'Use the parish AI assistant'),
    ('profile.manage', 'Manage own profile', 'Self Service', 'Update the authenticated account profile'),
    ('requests.create', 'Create own requests', 'Self Service', 'Create parish service requests'),
    ('requests.view_own', 'View own requests', 'Self Service', 'View the account own requests'),
    ('documents.upload_own', 'Upload own documents', 'Self Service', 'Upload documents to owned requests'),
    ('payments.upload_own', 'Upload own payments', 'Self Service', 'Upload payment evidence to owned requests'),
    ('announcements.view', 'View announcements', 'Self Service', 'View parish announcements'),
    ('calendar.view', 'View calendar', 'Self Service', 'View parish schedules'),
    ('notifications.view_own', 'View own notifications', 'Self Service', 'View account notifications');

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id FROM roles r CROSS JOIN permissions p WHERE r.role_key = 'administrator';

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id FROM roles r JOIN permissions p ON p.permission_key IN (
    'admin.access','dashboard.view','users.view','registrations.verify','requests.view','requests.manage',
    'records.view','records.manage','certificates.manage','reservations.manage','announcements.manage',
    'notifications.manage','reports.view','calendar.manage','archives.manage','ai.use'
) WHERE r.role_key = 'parish_staff';

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id FROM roles r JOIN permissions p ON p.permission_key IN (
    'admin.access','dashboard.view','requests.view','records.view','records.manage',
    'certificates.manage','reports.view'
) WHERE r.role_key = 'records_clerk';

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id FROM roles r JOIN permissions p ON p.permission_key IN (
    'admin.access','dashboard.view','requests.view','payments.verify','financial.view','reports.view'
) WHERE r.role_key = 'finance_staff';

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id FROM roles r JOIN permissions p ON p.permission_key IN (
    'dashboard.view','profile.manage','requests.create','requests.view_own','documents.upload_own',
    'payments.upload_own','announcements.view','calendar.view','notifications.view_own','ai.use'
) WHERE r.role_key = 'parishioner';

INSERT INTO `user_roles` (`user_id`, `role_id`)
SELECT u.id, r.role_id
FROM users u
JOIN roles r ON r.role_key = CASE WHEN u.role = 'admin' THEN 'administrator' ELSE 'parishioner' END;

INSERT INTO `account_status_history` (`user_id`, `previous_status`, `new_status`, `action`, `reason`)
SELECT id, NULL, status, 'legacy_import', rejection_reason FROM users;

INSERT INTO `registration_reviews` (`user_id`, `review_action`, `previous_status`, `new_status`, `reason`, `reviewed_by`, `created_at`)
SELECT id,
       CASE WHEN status = 'rejected' THEN 'rejected' WHEN status = 'active' THEN 'approved' ELSE 'submitted' END,
       NULL,
       status,
       rejection_reason,
       verified_by,
       COALESCE(verified_at, created_at)
FROM users
WHERE role = 'user';

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
    ('auth.max_failed_attempts', '5'),
    ('auth.failure_window_seconds', '900'),
    ('auth.lockout_seconds', '900'),
    ('auth.progressive_delay_max_ms', '2000'),
    ('auth.admin_mfa_required', '1'),
    ('otp.ttl_seconds', '300'),
    ('otp.max_attempts', '5'),
    ('otp.resend_cooldown_seconds', '60'),
    ('otp.max_resends', '4'),
    ('otp.account_hourly_limit', '10'),
    ('otp.ip_hourly_limit', '30'),
    ('security.password_history_count', '5')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
