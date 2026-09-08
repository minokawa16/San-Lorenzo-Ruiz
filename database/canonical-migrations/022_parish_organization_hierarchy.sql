-- Phase 022: Dynamic 5-Tier Parish Organizational Hierarchy
-- Creates relational schema for org_positions, org_members, and position_assignments.
-- Enforces system role immutability, vacancy states, term dates, and dynamic Rank 5 ministry positions.

SET @dbname = DATABASE();

-- 1. Table: org_positions
CREATE TABLE IF NOT EXISTS `org_positions` (
    `position_id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(150) NOT NULL,
    `rank_level` INT NOT NULL, -- 1: Parish Priest, 2: Assistant Priest, 3: Parish Secretary, 4: PPC Board, 5: Ministry Coordinator
    `display_order` INT NOT NULL DEFAULT 0,
    `is_system_role` TINYINT(1) NOT NULL DEFAULT 0, -- Guard: 1 = Core system role (cannot be deleted/archived), 0 = Dynamic
    `max_occupants` INT NOT NULL DEFAULT 1,
    `role_code` VARCHAR(60) NULL UNIQUE,
    `description` VARCHAR(255) NULL,
    `status` ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_org_pos_rank_order` (`rank_level`, `display_order`),
    KEY `idx_org_pos_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Table: org_members
CREATE TABLE IF NOT EXISTS `org_members` (
    `member_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) NULL,
    `title_prefix` VARCHAR(50) NULL,
    `full_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(150) NULL,
    `phone` VARCHAR(50) NULL,
    `photo_url` VARCHAR(255) NULL,
    `bio` TEXT NULL,
    `status` ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_org_members_user` (`user_id`),
    KEY `idx_org_members_status` (`status`),
    CONSTRAINT `fk_org_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Table: position_assignments
CREATE TABLE IF NOT EXISTS `position_assignments` (
    `assignment_id` INT AUTO_INCREMENT PRIMARY KEY,
    `position_id` INT NOT NULL,
    `member_id` INT NOT NULL,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `notes` VARCHAR(255) NULL,
    `assigned_by` INT(11) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_pos_assign_position` (`position_id`, `is_active`),
    KEY `idx_pos_assign_member` (`member_id`, `is_active`),
    CONSTRAINT `fk_pos_assign_position` FOREIGN KEY (`position_id`) REFERENCES `org_positions` (`position_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pos_assign_member` FOREIGN KEY (`member_id`) REFERENCES `org_members` (`member_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pos_assign_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Core Fixed Positions (Ranks 1-4: is_system_role = 1)
INSERT INTO `org_positions` (`position_id`, `title`, `rank_level`, `display_order`, `is_system_role`, `max_occupants`, `role_code`, `description`, `status`)
VALUES
(1, 'Parish Priest', 1, 1, 1, 1, 'PARISH_PRIEST', 'Pastoral & Canonical Head of Mission Station', 'active'),
(2, 'Parochial Vicar (Assistant Priest)', 2, 1, 1, 5, 'PAROCHIAL_VICAR', 'Assistant Pastoral & Liturgical Ministry', 'active'),
(3, 'Parish Secretary', 3, 1, 1, 1, 'PARISH_SECRETARY', 'Chancery, Office & Sacramental Operations Head', 'active'),
(4, 'PPC President', 4, 1, 1, 1, 'PPC_PRESIDENT', 'Parish Pastoral Council Executive Board President', 'active'),
(5, 'PPC Vice President', 4, 2, 1, 1, 'PPC_VICE_PRESIDENT', 'Parish Pastoral Council Executive Board Vice President', 'active'),
(6, 'PPC Secretary', 4, 3, 1, 1, 'PPC_SECRETARY', 'Parish Pastoral Council Executive Board Secretary', 'active'),
(7, 'PPC Treasurer', 4, 4, 1, 1, 'PPC_TREASURER', 'Parish Pastoral Council Executive Board Treasurer', 'active')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `rank_level` = VALUES(`rank_level`), `is_system_role` = 1;

-- Seed Initial Dynamic Ministry Positions (Rank 5: is_system_role = 0)
INSERT INTO `org_positions` (`position_id`, `title`, `rank_level`, `display_order`, `is_system_role`, `max_occupants`, `role_code`, `description`, `status`)
VALUES
(8, 'Commission on Liturgy & Sacred Music', 5, 1, 0, 1, 'MIN_LITURGY', 'Liturgical planning, choir coordination, and liturgical servers', 'active'),
(9, 'Parish Youth Ministry (PYM)', 5, 2, 0, 1, 'MIN_YOUTH', 'Youth empowerment, faith formation, and campus fellowship', 'active'),
(10, 'Commission on Catechesis & Faith Formation', 5, 3, 0, 1, 'MIN_CATECHESIS', 'Religious instruction, sacraments preparation, and bible apostolate', 'active'),
(11, 'Ministry of Greeters & Ushers', 5, 4, 0, 1, 'MIN_USHERS', 'Hospitality, mass ushering, and order maintenance', 'active')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `rank_level` = VALUES(`rank_level`), `is_system_role` = VALUES(`is_system_role`);

-- Seed Members
INSERT INTO `org_members` (`member_id`, `user_id`, `title_prefix`, `full_name`, `email`, `phone`, `bio`, `status`)
VALUES
(1, NULL, 'Rev. Fr.', 'Alberto Cahilig, OMI', 'fr.alberto@sanlorenzoruiz.ph', '+63 917 123 4567', 'Parish Priest and Pastoral Head of San Lorenzo Ruiz Mission Station.', 'active'),
(2, NULL, 'Rev. Fr.', 'Mark Anthony Santos, OMI', 'fr.mark@sanlorenzoruiz.ph', '+63 918 234 5678', 'Parochial Vicar assisting with sacramental ministry and pastoral outreach.', 'active'),
(3, NULL, 'Ms.', 'Agnes Calapaan', 'office@sanlorenzoruiz.ph', '+63 919 345 6789', 'Parish Secretary overseeing office operations and sacramental archives.', 'active'),
(4, NULL, 'Bro.', 'Eduardo Villanueva', 'ppc.pres@sanlorenzoruiz.ph', '+63 920 456 7890', 'President of the Parish Pastoral Council Executive Board.', 'active'),
(5, NULL, 'Sis.', 'Ma. Cristina Reyes', 'ppc.vp@sanlorenzoruiz.ph', '+63 921 567 8901', 'Vice President of the Parish Pastoral Council Executive Board.', 'active'),
(6, NULL, 'Sis.', 'Teresa Gonzales', 'ppc.sec@sanlorenzoruiz.ph', '+63 922 678 9012', 'Secretary of the Parish Pastoral Council Executive Board.', 'active'),
(7, NULL, 'Bro.', 'Manuel Santos', 'ppc.treas@sanlorenzoruiz.ph', '+63 923 789 0123', 'Treasurer of the Parish Pastoral Council Executive Board.', 'active'),
(8, NULL, 'Bro.', 'Rafael Dalisay', 'liturgy@sanlorenzoruiz.ph', '+63 924 890 1234', 'Coordinator for the Commission on Liturgy and Sacred Music.', 'active'),
(9, NULL, 'Sis.', 'Angela De Leon', 'youth@sanlorenzoruiz.ph', '+63 925 901 2345', 'Coordinator for Parish Youth Ministry (PYM).', 'active'),
(10, NULL, 'Sis.', 'Lourdes Fernandez', 'catechesis@sanlorenzoruiz.ph', '+63 926 012 3456', 'Head Catechist for Faith Formation and Children Sacramental Prep.', 'active')
ON DUPLICATE KEY UPDATE `full_name` = VALUES(`full_name`), `title_prefix` = VALUES(`title_prefix`);

-- Seed Active Position Assignments (Leaves Position 11 'Ministry of Greeters & Ushers' as VACANT to showcase vacancy state)
INSERT INTO `position_assignments` (`position_id`, `member_id`, `start_date`, `is_active`, `notes`)
VALUES
(1, 1, '2024-01-01', 1, 'Canonical assignment as Parish Priest'),
(2, 2, '2024-03-01', 1, 'Canonical assignment as Parochial Vicar'),
(3, 3, '2023-01-15', 1, 'Office administration and chancery operations'),
(4, 4, '2024-01-01', 1, 'Elected 2-year PPC Executive term'),
(5, 5, '2024-01-01', 1, 'Elected 2-year PPC Executive term'),
(6, 6, '2024-01-01', 1, 'Elected 2-year PPC Executive term'),
(7, 7, '2024-01-01', 1, 'Elected 2-year PPC Executive term'),
(8, 8, '2024-02-01', 1, 'Commission Coordinator appointment'),
(9, 9, '2024-02-01', 1, 'Commission Coordinator appointment'),
(10, 10, '2024-02-01', 1, 'Commission Coordinator appointment')
ON DUPLICATE KEY UPDATE `is_active` = 1;
