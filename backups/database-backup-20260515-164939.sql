-- Parish Management System Database Backup
-- Created: 2026-05-15 16:49:39

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `announcements`;
CREATE TABLE `announcements` (
  `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `content` text NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `type` enum('announcement','schedule','event','obituary') DEFAULT 'announcement',
  `published_by` int(11) NOT NULL,
  `published_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `expiry_date` datetime DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`announcement_id`),
  KEY `published_by` (`published_by`),
  KEY `published_date` (`published_date`),
  CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `announcements` (`announcement_id`, `title`, `content`, `image_path`, `type`, `published_by`, `published_date`, `expiry_date`, `status`, `created_at`, `updated_at`) VALUES ('1', 'ssd', 'dsdsd', NULL, 'schedule', '1', '2026-05-07 19:37:37', NULL, 'active', '2026-05-07 19:37:37', '2026-05-07 19:37:37');
INSERT INTO `announcements` (`announcement_id`, `title`, `content`, `image_path`, `type`, `published_by`, `published_date`, `expiry_date`, `status`, `created_at`, `updated_at`) VALUES ('2', 'jznajzj', 'kaznkaznka', NULL, 'schedule', '4', '2026-05-08 20:46:00', NULL, 'active', '2026-05-08 20:46:00', '2026-05-08 20:46:00');

DROP TABLE IF EXISTS `audit_log`;
CREATE TABLE `audit_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_value` longtext DEFAULT NULL,
  `new_value` longtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`),
  CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('1', '5', 'LOGIN', 'users', '5', '', '', '::1', '2026-05-07 12:06:22');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('2', '1', 'LOGIN', 'users', '1', 'NULL', 'NULL', '::1', '2026-05-07 19:28:22');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('3', '1', 'ADD_RECORD', 'baptism_records', '1', 'NULL', 'NULL', '::1', '2026-05-07 19:30:43');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('4', '1', 'GENERATE_CERTIFICATE', 'records', '1', '\"Certificate generated for baptism\"', 'NULL', '::1', '2026-05-07 19:31:01');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('5', '1', 'ADD_RECORD', 'baptism_records', '2', 'NULL', 'NULL', '::1', '2026-05-07 19:32:52');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('6', '1', 'LOGOUT', 'users', '1', 'NULL', 'NULL', '::1', '2026-05-07 19:39:37');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('7', '6', 'REGISTRATION', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-07 19:40:18');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('8', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-07 19:40:24');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('9', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-07 19:40:32');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('10', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-07 19:55:42');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('11', '6', 'CREATE_REQUEST', 'requests', '1', 'NULL', 'NULL', '::1', '2026-05-07 20:03:56');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('12', '6', 'LOGOUT', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-07 20:05:22');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('13', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-07 20:05:27');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('14', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-08 18:15:30');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('15', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-08 20:13:54');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('16', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-08 20:44:27');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('17', '4', 'LOGOUT', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-08 20:46:06');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('18', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-08 20:46:10');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('19', '6', 'LOGOUT', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-08 20:48:21');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('20', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-08 20:48:24');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('21', '4', 'UPDATE_REQUEST', 'requests', '1', 'NULL', 'NULL', '::1', '2026-05-08 20:49:59');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('22', '4', 'LOGOUT', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-08 20:50:03');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('23', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-08 20:50:08');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('24', '6', 'CREATE_REQUEST', 'requests', '2', 'NULL', 'NULL', '::1', '2026-05-08 20:50:20');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('25', '6', 'CREATE_REQUEST', 'requests', '3', 'NULL', 'NULL', '::1', '2026-05-08 20:52:15');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('26', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-09 01:09:13');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('27', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-09 01:23:02');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('28', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-09 01:28:20');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('29', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-09 20:46:10');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('30', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-10 12:35:08');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('31', '4', 'UPDATE_REQUEST', 'requests', '3', 'NULL', 'NULL', '::1', '2026-05-10 12:35:29');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('32', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-10 12:35:39');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('33', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-12 09:04:23');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('34', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-12 09:04:35');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('35', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-12 09:42:42');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('36', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-12 09:43:36');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('37', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-12 10:02:27');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('38', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-12 10:18:44');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('39', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-12 10:23:31');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('40', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-12 10:44:43');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('41', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-13 08:11:31');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('42', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-13 08:19:15');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('43', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-13 21:57:03');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('44', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-14 00:03:59');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('45', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-14 00:08:04');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('46', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-14 00:08:46');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('47', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-14 01:03:13');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('48', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-14 01:04:04');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('49', '1', 'LOGIN', 'users', '1', 'NULL', 'NULL', '::1', '2026-05-14 03:16:25');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('50', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-14 03:31:44');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('51', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-14 03:31:55');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('52', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-14 03:37:04');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('53', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-14 11:07:07');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('54', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-14 14:42:39');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('55', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-14 14:43:01');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('56', '1', 'ADD_SCHEDULE_EVENT', 'schedule_events', '1', 'NULL', 'NULL', 'UNKNOWN', '2026-05-14 15:14:52');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('57', '1', 'DELETE_SCHEDULE_EVENT', 'schedule_events', '1', 'NULL', 'NULL', 'UNKNOWN', '2026-05-14 15:15:02');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('58', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-15 12:22:32');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('59', '4', 'ADD_SCHEDULE_EVENT', 'schedule_events', '2', 'NULL', 'NULL', '::1', '2026-05-15 12:31:07');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('60', '7', 'REGISTRATION', 'users', '7', 'NULL', 'NULL', '::1', '2026-05-15 13:15:55');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('61', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-15 13:28:09');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('62', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-15 13:55:15');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('63', '8', 'REGISTRATION', 'users', '8', 'NULL', 'NULL', '::1', '2026-05-15 21:06:15');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('64', '8', 'LOGIN', 'users', '8', 'NULL', 'NULL', '::1', '2026-05-15 21:06:51');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('65', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-15 21:55:24');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('66', '8', 'LOGIN', 'users', '8', 'NULL', 'NULL', '::1', '2026-05-15 22:11:45');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('67', '8', 'LOGIN', 'users', '8', 'NULL', 'NULL', '::1', '2026-05-15 22:12:57');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('68', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-15 22:18:26');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('69', '4', 'GENERATE_CERTIFICATE', 'records', '1', '\"Certificate generated for baptism\"', 'NULL', '::1', '2026-05-15 22:43:45');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('70', '4', 'CREATE_FULL_BACKUP', 'system', '0', 'NULL', 'NULL', '::1', '2026-05-15 22:49:36');

DROP TABLE IF EXISTS `baptism_records`;
CREATE TABLE `baptism_records` (
  `baptism_id` int(11) NOT NULL AUTO_INCREMENT,
  `fullname` varchar(100) NOT NULL,
  `birth_date` date DEFAULT NULL,
  `baptism_date` date DEFAULT NULL,
  `parents` varchar(200) DEFAULT NULL,
  `godparents` varchar(200) DEFAULT NULL,
  `priest` varchar(100) DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`baptism_id`),
  KEY `fullname` (`fullname`),
  KEY `baptism_date` (`baptism_date`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `baptism_records` (`baptism_id`, `fullname`, `birth_date`, `baptism_date`, `parents`, `godparents`, `priest`, `status`, `created_at`, `updated_at`) VALUES ('1', 'REY MARK C. CAVANAS', '2005-12-16', '2006-04-25', 'joy cavanas/ roberto cavanas', 'lee', 'fr. lee javier,omi.', 'active', '2026-05-07 19:30:43', '2026-05-07 19:30:43');
INSERT INTO `baptism_records` (`baptism_id`, `fullname`, `birth_date`, `baptism_date`, `parents`, `godparents`, `priest`, `status`, `created_at`, `updated_at`) VALUES ('2', 'REY MARK C. CAVANAS', '2005-12-16', '2006-04-25', 'joy cavanas/ roberto cavanas', 'lee', 'fr. lee javier,omi.', 'active', '2026-05-07 19:32:52', '2026-05-07 19:32:52');

DROP TABLE IF EXISTS `certificate_templates`;
CREATE TABLE `certificate_templates` (
  `template_id` int(11) NOT NULL AUTO_INCREMENT,
  `certificate_type` enum('baptismal','confirmation','first_communion','marriage') NOT NULL,
  `template_content` longtext NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`template_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `certificate_templates_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('1', 'baptismal', '<h1>Baptismal Certificate</h1><p>Full Name: {{fullname}}</p><p>Birth Date: {{birth_date}}</p><p>Baptism Date: {{baptism_date}}</p>', '1', '2026-05-07 11:52:23', '2026-05-07 11:52:23');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('2', 'confirmation', '<h1>Confirmation Certificate</h1><p>Full Name: {{fullname}}</p><p>Confirmation Date: {{confirmation_date}}</p>', '1', '2026-05-07 11:52:23', '2026-05-07 11:52:23');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('3', 'first_communion', '<h1>First Communion Certificate</h1><p>Full Name: {{fullname}}</p><p>Communion Date: {{communion_date}}</p>', '1', '2026-05-07 11:52:23', '2026-05-07 11:52:23');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('4', 'marriage', '<h1>Marriage Certificate</h1><p>Groom: {{husband_name}}</p><p>Bride: {{wife_name}}</p><p>Wedding Date: {{wedding_date}}</p>', '1', '2026-05-07 11:52:23', '2026-05-07 11:52:23');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('5', 'baptismal', '<h1>Baptismal Certificate</h1><p>Full Name: {{fullname}}</p><p>Birth Date: {{birth_date}}</p><p>Baptism Date: {{baptism_date}}</p>', '1', '2026-05-07 11:55:41', '2026-05-07 11:55:41');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('6', 'confirmation', '<h1>Confirmation Certificate</h1><p>Full Name: {{fullname}}</p><p>Confirmation Date: {{confirmation_date}}</p>', '1', '2026-05-07 11:55:41', '2026-05-07 11:55:41');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('7', 'first_communion', '<h1>First Communion Certificate</h1><p>Full Name: {{fullname}}</p><p>Communion Date: {{communion_date}}</p>', '1', '2026-05-07 11:55:41', '2026-05-07 11:55:41');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('8', 'marriage', '<h1>Marriage Certificate</h1><p>Groom: {{husband_name}}</p><p>Bride: {{wife_name}}</p><p>Wedding Date: {{wedding_date}}</p>', '1', '2026-05-07 11:55:41', '2026-05-07 11:55:41');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('9', 'baptismal', '<h1>Baptismal Certificate</h1><p>Full Name: {{fullname}}</p><p>Birth Date: {{birth_date}}</p><p>Baptism Date: {{baptism_date}}</p>', '1', '2026-05-07 11:59:49', '2026-05-07 11:59:49');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('10', 'confirmation', '<h1>Confirmation Certificate</h1><p>Full Name: {{fullname}}</p><p>Confirmation Date: {{confirmation_date}}</p>', '1', '2026-05-07 11:59:49', '2026-05-07 11:59:49');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('11', 'first_communion', '<h1>First Communion Certificate</h1><p>Full Name: {{fullname}}</p><p>Communion Date: {{communion_date}}</p>', '1', '2026-05-07 11:59:49', '2026-05-07 11:59:49');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('12', 'marriage', '<h1>Marriage Certificate</h1><p>Groom: {{husband_name}}</p><p>Bride: {{wife_name}}</p><p>Wedding Date: {{wedding_date}}</p>', '1', '2026-05-07 11:59:49', '2026-05-07 11:59:49');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('13', 'baptismal', '<h1>Baptismal Certificate</h1><p>Full Name: {{fullname}}</p><p>Birth Date: {{birth_date}}</p><p>Baptism Date: {{baptism_date}}</p>', '1', '2026-05-07 12:04:29', '2026-05-07 12:04:29');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('14', 'confirmation', '<h1>Confirmation Certificate</h1><p>Full Name: {{fullname}}</p><p>Confirmation Date: {{confirmation_date}}</p>', '1', '2026-05-07 12:04:29', '2026-05-07 12:04:29');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('15', 'first_communion', '<h1>First Communion Certificate</h1><p>Full Name: {{fullname}}</p><p>Communion Date: {{communion_date}}</p>', '1', '2026-05-07 12:04:29', '2026-05-07 12:04:29');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('16', 'marriage', '<h1>Marriage Certificate</h1><p>Groom: {{husband_name}}</p><p>Bride: {{wife_name}}</p><p>Wedding Date: {{wedding_date}}</p>', '1', '2026-05-07 12:04:29', '2026-05-07 12:04:29');

DROP TABLE IF EXISTS `confirmation_records`;
CREATE TABLE `confirmation_records` (
  `confirmation_id` int(11) NOT NULL AUTO_INCREMENT,
  `fullname` varchar(100) NOT NULL,
  `birth_date` date DEFAULT NULL,
  `confirmation_date` date DEFAULT NULL,
  `confirmation_name` varchar(100) DEFAULT NULL,
  `sponsor` varchar(100) DEFAULT NULL,
  `bishop_priest` varchar(100) DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`confirmation_id`),
  KEY `fullname` (`fullname`),
  KEY `confirmation_date` (`confirmation_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `first_communion_records`;
CREATE TABLE `first_communion_records` (
  `communion_id` int(11) NOT NULL AUTO_INCREMENT,
  `fullname` varchar(100) NOT NULL,
  `birth_date` date DEFAULT NULL,
  `communion_date` date DEFAULT NULL,
  `sponsor` varchar(100) DEFAULT NULL,
  `priest` varchar(100) DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`communion_id`),
  KEY `fullname` (`fullname`),
  KEY `communion_date` (`communion_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `marriage_records`;
CREATE TABLE `marriage_records` (
  `marriage_id` int(11) NOT NULL AUTO_INCREMENT,
  `husband_name` varchar(100) NOT NULL,
  `wife_name` varchar(100) NOT NULL,
  `wedding_date` date DEFAULT NULL,
  `sponsors` varchar(200) DEFAULT NULL,
  `officiating_priest` varchar(100) DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`marriage_id`),
  KEY `husband_name` (`husband_name`),
  KEY `wife_name` (`wife_name`),
  KEY `wedding_date` (`wedding_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `user_id` (`user_id`,`is_read`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('1', '6', 'Request Created', 'Your request has been submitted with reference: PRQ-20260507-25361', '1', '2026-05-07 20:03:56');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('2', '6', 'Request Update', 'Your request status has been updated to: Rejected', '1', '2026-05-08 20:49:59');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('3', '6', 'Request Created', 'Your request has been submitted with reference: PRQ-20260508-85057', '1', '2026-05-08 20:50:20');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('4', '6', 'Request Created', 'Your request has been submitted with reference: PRQ-20260508-14732', '1', '2026-05-08 20:52:15');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('5', '6', 'Request Update', 'Your request status has been updated to: Rejected', '1', '2026-05-10 12:35:29');

DROP TABLE IF EXISTS `requests`;
CREATE TABLE `requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `request_type` enum('baptismal_certificate','confirmation_certificate','first_communion_certificate','marriage_certificate','house_blessing','car_blessing','church_reservation','wedding_reservation','burial_reservation') NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','processing','completed') DEFAULT 'pending',
  `date_requested` timestamp NOT NULL DEFAULT current_timestamp(),
  `admin_response` text DEFAULT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`request_id`),
  UNIQUE KEY `reference_number` (`reference_number`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `requests` (`request_id`, `user_id`, `request_type`, `description`, `status`, `date_requested`, `admin_response`, `reference_number`, `updated_at`) VALUES ('1', '6', 'marriage_certificate', 'kkk', 'rejected', '2026-05-07 20:03:56', '', 'PRQ-20260507-25361', '2026-05-08 20:49:59');
INSERT INTO `requests` (`request_id`, `user_id`, `request_type`, `description`, `status`, `date_requested`, `admin_response`, `reference_number`, `updated_at`) VALUES ('2', '6', 'baptismal_certificate', '', 'pending', '2026-05-08 20:50:20', NULL, 'PRQ-20260508-85057', '2026-05-08 20:50:20');
INSERT INTO `requests` (`request_id`, `user_id`, `request_type`, `description`, `status`, `date_requested`, `admin_response`, `reference_number`, `updated_at`) VALUES ('3', '6', 'confirmation_certificate', '', 'rejected', '2026-05-08 20:52:15', 'pangit ka', 'PRQ-20260508-14732', '2026-05-10 12:35:29');

DROP TABLE IF EXISTS `reservations`;
CREATE TABLE `reservations` (
  `reservation_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `reservation_type` enum('wedding','baptism','confirmation','burial','church_venue') NOT NULL,
  `event_date` date NOT NULL,
  `event_time` time DEFAULT NULL,
  `event_details` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`reservation_id`),
  UNIQUE KEY `unique_reservation` (`reservation_type`,`event_date`,`event_time`),
  KEY `user_id` (`user_id`),
  KEY `event_date` (`event_date`),
  CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `reservations` (`reservation_id`, `user_id`, `reservation_type`, `event_date`, `event_time`, `event_details`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES ('1', '6', 'baptism', '0020-12-23', '09:49:00', '', 'pending', NULL, '2026-05-08 20:47:25', '2026-05-08 20:47:25');
INSERT INTO `reservations` (`reservation_id`, `user_id`, `reservation_type`, `event_date`, `event_time`, `event_details`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES ('2', '6', 'church_venue', '2026-05-12', '20:49:00', '', 'pending', NULL, '2026-05-08 20:47:56', '2026-05-08 20:47:56');
INSERT INTO `reservations` (`reservation_id`, `user_id`, `reservation_type`, `event_date`, `event_time`, `event_details`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES ('3', '6', 'baptism', '2022-12-31', '21:53:00', '', 'pending', NULL, '2026-05-08 20:51:10', '2026-05-08 20:51:10');
INSERT INTO `reservations` (`reservation_id`, `user_id`, `reservation_type`, `event_date`, `event_time`, `event_details`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES ('4', '6', 'wedding', '2026-12-20', '21:51:00', '', 'pending', NULL, '2026-05-08 20:51:59', '2026-05-08 20:51:59');

DROP TABLE IF EXISTS `schedule_events`;
CREATE TABLE `schedule_events` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `category` varchar(50) DEFAULT 'event',
  `priority` varchar(20) DEFAULT 'normal',
  `color_label` varchar(20) DEFAULT '#1a73e8',
  `recurrence_rule` varchar(100) DEFAULT 'none',
  `assigned_personnel` varchar(150) DEFAULT NULL,
  `visibility` enum('public','private') DEFAULT 'public',
  `approval_status` enum('pending','approved','rejected') DEFAULT 'approved',
  `status` enum('active','upcoming','ongoing','finished','cancelled') DEFAULT 'upcoming',
  `reminder_minutes` int(11) DEFAULT 30,
  `notify_email` tinyint(1) DEFAULT 0,
  `notify_sms` tinyint(1) DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`schedule_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_schedule_date` (`event_date`),
  KEY `idx_schedule_status_date` (`status`,`event_date`,`start_time`),
  CONSTRAINT `schedule_events_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `schedule_events` (`schedule_id`, `title`, `description`, `event_date`, `start_time`, `end_time`, `location`, `category`, `priority`, `color_label`, `recurrence_rule`, `assigned_personnel`, `visibility`, `approval_status`, `status`, `reminder_minutes`, `notify_email`, `notify_sms`, `created_by`, `created_at`, `updated_at`) VALUES ('2', 'sasa', '', '2026-05-15', '08:00:00', '09:00:00', 'asa', 'mass', 'normal', '#34a853', 'none', 'sasa', 'public', 'approved', 'upcoming', '30', '0', '0', '4', '2026-05-15 12:31:07', '2026-05-15 12:31:07');

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fullname` varchar(255) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `chapel_district` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('user','admin') DEFAULT 'user',
  `status` enum('active','inactive') DEFAULT 'active',
  `profile_picture` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `users` (`id`, `fullname`, `phone_number`, `email`, `chapel_district`, `password`, `role`, `status`, `profile_picture`, `created_at`, `updated_at`) VALUES ('1', 'Admin User', '', 'admin@parish.com', NULL, '$2y$10$4SOqfUllLomRA4aPdbsBDOlwYl8HRJKtYhovw5JrmAbUN3iHmspTG', 'admin', 'active', NULL, '2026-05-07 11:52:23', '2026-05-07 19:17:15');
INSERT INTO `users` (`id`, `fullname`, `phone_number`, `email`, `chapel_district`, `password`, `role`, `status`, `profile_picture`, `created_at`, `updated_at`) VALUES ('4', 'Admin', '555-0000', 'admin@gmail.com', 'Main Chapel', '$2y$10$4SOqfUllLomRA4aPdbsBDOlwYl8HRJKtYhovw5JrmAbUN3iHmspTG', 'admin', 'active', NULL, '2026-05-07 12:04:29', '2026-05-07 19:17:15');
INSERT INTO `users` (`id`, `fullname`, `phone_number`, `email`, `chapel_district`, `password`, `role`, `status`, `profile_picture`, `created_at`, `updated_at`) VALUES ('5', 'REYMARK', '09635866550', 'reymarkcavanas0@gmail.com', 'East District', '$2y$10$IHJbcO8C1c/EvOMrja6zEOhHdRiK7LSJBAhbcditzdknzy/SbtbAy', 'user', 'active', NULL, '2026-05-07 12:06:18', '2026-05-07 12:06:18');
INSERT INTO `users` (`id`, `fullname`, `phone_number`, `email`, `chapel_district`, `password`, `role`, `status`, `profile_picture`, `created_at`, `updated_at`) VALUES ('6', 'dimpol', '09635866550', 'dimpowalabalo@gmail.com', 'Central Parish', '$2y$10$HF36TcM5yvgvNidZ6Lc9sOeGhLGycdLwBD5oIZyJSykHb2W2VW.Qq', 'user', 'active', NULL, '2026-05-07 19:40:18', '2026-05-07 19:40:18');
INSERT INTO `users` (`id`, `fullname`, `phone_number`, `email`, `chapel_district`, `password`, `role`, `status`, `profile_picture`, `created_at`, `updated_at`) VALUES ('7', 'hazel gadingan', '09123456789', 'blabla@gmail.com', 'Main Chapel', '$2y$10$7DJHvL6u//kLUlS7S5dZW.eiaj7jJCZpgl8xeMYV3iQDNow74DQPi', 'user', 'active', NULL, '2026-05-15 13:15:55', '2026-05-15 13:15:55');
INSERT INTO `users` (`id`, `fullname`, `phone_number`, `email`, `chapel_district`, `password`, `role`, `status`, `profile_picture`, `created_at`, `updated_at`) VALUES ('8', 'prince ondoy', '09631237247', 'princeondoy0@gmail.com', 'Main Chapel', '$2y$10$FSUsZSU7lcNgVMD48mom4uKCkc4VOlSXNZMdP6SHmep2Sg1ug7I7a', 'user', 'active', NULL, '2026-05-15 21:06:15', '2026-05-15 21:06:15');

SET FOREIGN_KEY_CHECKS=1;
