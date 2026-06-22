-- Parish Management System Database Backup
-- Created: 2026-06-06 17:14:29
-- Database: parish_management_system

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `announcement_recipients`;
CREATE TABLE `announcement_recipients` (
  `recipient_id` int(11) NOT NULL AUTO_INCREMENT,
  `announcement_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `delivery_status` varchar(30) DEFAULT 'pending',
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`recipient_id`),
  UNIQUE KEY `uniq_announcement_user` (`announcement_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `announcements`;
CREATE TABLE `announcements` (
  `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `content` text NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_original_name` varchar(255) DEFAULT NULL,
  `attachment_mime_type` varchar(120) DEFAULT NULL,
  `attachment_size` int(10) unsigned DEFAULT NULL,
  `type` varchar(50) DEFAULT 'announcement',
  `published_by` int(11) NOT NULL,
  `published_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `scheduled_at` datetime DEFAULT NULL,
  `expiry_date` datetime DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `is_pinned` tinyint(1) DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`announcement_id`),
  KEY `published_by` (`published_by`),
  KEY `published_date` (`published_date`),
  CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `announcements` (`announcement_id`, `title`, `content`, `image_path`, `attachment_path`, `attachment_original_name`, `attachment_mime_type`, `attachment_size`, `type`, `published_by`, `published_date`, `scheduled_at`, `expiry_date`, `event_date`, `is_pinned`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES ('2', 'jznajzj', 'kaznkaznka', NULL, NULL, NULL, NULL, NULL, 'schedule', '4', '2026-05-08 20:46:00', NULL, NULL, NULL, '0', 'inactive', '2026-05-08 20:46:00', '2026-05-20 07:05:08', '2026-05-20 07:05:08');
INSERT INTO `announcements` (`announcement_id`, `title`, `content`, `image_path`, `attachment_path`, `attachment_original_name`, `attachment_mime_type`, `attachment_size`, `type`, `published_by`, `published_date`, `scheduled_at`, `expiry_date`, `event_date`, `is_pinned`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES ('3', 'pogi lang', 'pogi pogi', NULL, NULL, NULL, NULL, NULL, 'announcement', '4', '2026-05-19 23:52:37', NULL, NULL, NULL, '0', 'active', '2026-05-19 23:52:37', '2026-05-19 23:52:37', NULL);

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
) ENGINE=InnoDB AUTO_INCREMENT=211 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('71', '4', 'CREATE_DATABASE_BACKUP', 'system', '0', 'NULL', 'NULL', '::1', '2026-05-15 22:49:39');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('72', '4', 'CREATE_FULL_BACKUP', 'system', '0', 'NULL', 'NULL', '::1', '2026-05-15 22:49:46');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('73', '4', 'CREATE_DATABASE_BACKUP', 'system', '0', 'NULL', 'NULL', '::1', '2026-05-15 22:49:50');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('74', '4', 'CREATE_FULL_BACKUP', 'system', '0', 'NULL', 'NULL', '::1', '2026-05-15 22:50:25');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('75', '4', 'CREATE_DATABASE_BACKUP', 'system', '0', 'NULL', 'NULL', '::1', '2026-05-15 22:50:30');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('76', '4', 'CREATE_FULL_BACKUP', 'system', '0', 'NULL', 'NULL', '::1', '2026-05-15 22:50:56');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('77', '4', 'GENERATE_CERTIFICATE', 'records', '1', '\"Certificate generated for baptism\"', 'NULL', '::1', '2026-05-15 22:55:56');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('78', '4', 'ARCHIVE_REQUEST', 'requests', '3', 'NULL', 'NULL', '::1', '2026-05-15 22:56:54');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('79', '4', 'ARCHIVE_REQUEST', 'requests', '2', 'NULL', 'NULL', '::1', '2026-05-15 23:04:02');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('80', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-15 23:20:43');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('81', '9', 'REGISTRATION_PENDING_VERIFICATION', 'users', '9', 'NULL', 'NULL', '::1', '2026-05-16 00:42:24');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('82', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-16 00:42:37');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('83', '4', 'APPROVE_REGISTRATION', 'users', '9', 'NULL', 'NULL', '::1', '2026-05-16 00:45:28');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('84', '9', 'LOGIN', 'users', '9', 'NULL', 'NULL', '::1', '2026-05-16 00:46:32');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('85', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-16 00:53:21');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('86', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-16 03:27:14');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('87', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-16 10:26:10');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('88', '4', 'CREATE_DATABASE_BACKUP', 'system', '0', 'NULL', 'NULL', '::1', '2026-05-16 10:30:26');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('89', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-16 10:30:45');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('90', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-16 19:42:53');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('91', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-17 13:55:55');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('92', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-18 02:28:47');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('93', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-18 02:36:23');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('94', '6', 'CREATE_REQUEST', 'requests', '4', 'NULL', 'NULL', '::1', '2026-05-18 02:36:42');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('95', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-18 02:36:53');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('96', '4', 'UPDATE_REQUEST', 'requests', '4', 'NULL', 'NULL', '::1', '2026-05-18 02:37:40');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('97', '4', 'ADD_SCHEDULE_EVENT', 'schedule_events', '3', 'NULL', 'NULL', '::1', '2026-05-18 03:36:34');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('98', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-18 03:36:53');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('99', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-18 04:00:14');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('100', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-19 09:56:13');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('101', '8', 'LOGIN', 'users', '8', 'NULL', 'NULL', '::1', '2026-05-19 09:56:52');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('102', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-19 09:57:16');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('103', '8', 'LOGIN', 'users', '8', 'NULL', 'NULL', '::1', '2026-05-19 10:23:28');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('104', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-19 10:27:57');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('105', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-19 14:04:39');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('106', '4', 'UPDATE_USER', 'users', '9', 'NULL', 'NULL', '::1', '2026-05-19 14:07:17');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('107', '4', 'UPDATE_USER', 'users', '9', 'NULL', 'NULL', '::1', '2026-05-19 14:07:27');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('108', '4', 'UPDATE_USER', 'users', '9', 'NULL', 'NULL', '::1', '2026-05-19 14:08:01');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('109', '4', 'UPDATE_USER', 'users', '9', 'NULL', 'NULL', '::1', '2026-05-19 14:08:18');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('110', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-19 14:27:02');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('111', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-19 14:29:32');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('112', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-19 14:29:47');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('113', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-19 17:23:28');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('114', '5', 'LOGIN', 'users', '5', 'NULL', 'NULL', '::1', '2026-05-19 17:48:39');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('115', '5', 'CREATE_REQUEST', 'requests', '5', 'NULL', 'NULL', '::1', '2026-05-19 17:50:03');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('116', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-19 17:50:14');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('117', '4', 'UPDATE_REQUEST', 'requests', '5', 'NULL', 'NULL', '::1', '2026-05-19 17:50:41');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('118', '5', 'LOGIN', 'users', '5', 'NULL', 'NULL', '::1', '2026-05-19 18:02:38');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('119', '5', 'CREATE_RESERVATION', 'reservations', '5', 'NULL', 'NULL', '::1', '2026-05-19 18:03:49');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('120', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-19 18:04:10');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('121', '4', 'UPDATE_RESERVATION', 'reservations', '5', 'NULL', 'NULL', '::1', '2026-05-19 18:04:57');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('122', '4', 'SYNC_RESERVATION_CALENDAR', 'schedule_events', '4', 'NULL', 'NULL', '::1', '2026-05-19 18:04:58');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('123', '5', 'LOGIN', 'users', '5', 'NULL', 'NULL', '::1', '2026-05-19 18:05:27');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('124', '5', 'CREATE_RESERVATION', 'reservations', '6', 'NULL', 'NULL', '::1', '2026-05-19 18:06:17');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('125', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-19 18:06:22');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('126', '4', 'UPDATE_RESERVATION', 'reservations', '6', 'NULL', 'NULL', '::1', '2026-05-19 18:08:42');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('127', '4', 'SYNC_RESERVATION_CALENDAR', 'schedule_events', '5', 'NULL', 'NULL', '::1', '2026-05-19 18:08:42');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('128', '4', 'UPDATE_SCHEDULE_EVENT', 'schedule_events', '4', '{\"schedule_id\":4,\"title\":\"Wedding Reservation - REYMARK\",\"description\":\"Please\\n\\nAdmin notes: okay\",\"event_date\":\"2026-05-20\",\"start_time\":\"07:30:00\",\"end_time\":\"08:30:00\",\"location\":\"Parish\",\"category\":\"reservation\",\"priority\":\"normal\",\"color_label\":\"#188038\",\"recurrence_rule\":\"none\",\"assigned_personnel\":\"\",\"visibility\":\"public\",\"approval_status\":\"approved\",\"status\":\"upcoming\",\"reminder_minutes\":30,\"notify_email\":0,\"notify_sms\":0,\"source_type\":\"reservation\",\"source_id\":5,\"created_by\":4,\"created_at\":\"2026-05-19 18:04:58\",\"updated_at\":\"2026-05-19 18:04:58\"}', 'NULL', '::1', '2026-05-19 18:09:26');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('129', '4', 'ADD_SCHEDULE_EVENT', 'schedule_events', '6', 'NULL', 'NULL', '::1', '2026-05-19 18:09:50');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('130', '5', 'LOGIN', 'users', '5', 'NULL', 'NULL', '::1', '2026-05-19 18:17:57');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('131', '5', 'CREATE_RESERVATION', 'reservations', '7', 'NULL', 'NULL', '::1', '2026-05-19 18:19:58');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('132', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-19 18:20:13');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('133', '4', 'UPDATE_REQUEST', 'requests', '5', 'NULL', 'NULL', '::1', '2026-05-19 18:20:33');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('134', '4', 'SYNC_REQUEST_CALENDAR', 'schedule_events', '7', 'NULL', 'NULL', '::1', '2026-05-19 18:20:33');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('135', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-19 20:40:15');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('136', '10', 'REGISTRATION_PENDING_VERIFICATION', 'users', '10', 'NULL', 'NULL', '::1', '2026-05-19 23:38:34');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('137', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-19 23:38:49');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('138', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-19 23:38:51');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('139', '4', 'APPROVE_REGISTRATION', 'users', '10', 'NULL', 'NULL', '::1', '2026-05-19 23:39:13');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('140', '4', 'UPDATE_USER', 'users', '9', 'NULL', 'NULL', '::1', '2026-05-19 23:39:59');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('141', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-20 00:16:43');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('142', '10', 'LOGIN', 'users', '10', 'NULL', 'NULL', '::1', '2026-05-20 00:17:16');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('143', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-20 01:00:47');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('144', '10', 'LOGIN', 'users', '10', 'NULL', 'NULL', '::1', '2026-05-20 01:02:36');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('145', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-20 04:18:18');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('146', '5', 'LOGIN', 'users', '5', 'NULL', 'NULL', '::1', '2026-05-20 04:18:40');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('147', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-20 04:52:10');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('148', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-20 05:29:50');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('149', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-20 07:02:14');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('150', '4', 'ARCHIVE_ANNOUNCEMENT', 'announcements', '2', 'NULL', 'NULL', '::1', '2026-05-20 07:05:08');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('151', '5', 'LOGIN', 'users', '5', 'NULL', 'NULL', '::1', '2026-05-20 07:09:25');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('152', '5', 'CREATE_REQUEST', 'requests', '6', 'NULL', 'NULL', '::1', '2026-05-20 07:11:07');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('153', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-20 07:11:37');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('154', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-20 08:10:29');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('155', '11', 'REGISTRATION_PENDING_VERIFICATION', 'users', '11', 'NULL', 'NULL', '::1', '2026-05-20 08:49:26');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('156', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-20 08:49:36');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('157', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-20 08:49:38');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('158', '4', 'APPROVE_REGISTRATION', 'users', '11', 'NULL', 'NULL', '::1', '2026-05-20 08:50:01');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('159', '11', 'LOGIN', 'users', '11', 'NULL', 'NULL', '::1', '2026-05-20 08:50:31');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('160', '11', 'CREATE_REQUEST', 'requests', '7', 'NULL', 'NULL', '::1', '2026-05-20 08:50:52');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('161', '11', 'CREATE_REQUEST', 'requests', '8', 'NULL', 'NULL', '::1', '2026-05-20 08:51:35');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('162', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-20 08:52:05');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('163', '4', 'UPDATE_REQUEST', 'requests', '8', 'NULL', 'NULL', '::1', '2026-05-20 08:52:27');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('164', '4', 'SYNC_REQUEST_CALENDAR', 'schedule_events', '8', 'NULL', 'NULL', '::1', '2026-05-20 08:52:27');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('165', '11', 'LOGIN', 'users', '11', 'NULL', 'NULL', '::1', '2026-05-20 08:53:35');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('166', '11', 'CREATE_REQUEST', 'requests', '9', 'NULL', 'NULL', '::1', '2026-05-20 08:54:47');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('167', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-20 08:54:51');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('168', '11', 'LOGIN', 'users', '11', 'NULL', 'NULL', '::1', '2026-05-20 08:57:11');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('169', '11', 'CREATE_REQUEST', 'requests', '10', 'NULL', 'NULL', '::1', '2026-05-20 08:59:49');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('170', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-20 08:59:53');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('171', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-20 09:10:42');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('172', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-20 09:17:28');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('173', '12', 'REGISTRATION_OCR_PENDING_VERIFICATION', 'users', '12', 'NULL', '{\"ocr_status\":\"unreadable\",\"ocr_match_score\":0}', '::1', '2026-05-26 23:34:13');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('174', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-26 23:50:03');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('175', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-26 23:50:05');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('176', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-27 00:07:09');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('177', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-27 01:26:33');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('178', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-28 22:09:46');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('179', '4', 'APPROVE_REGISTRATION', 'users', '12', 'NULL', 'NULL', '::1', '2026-05-28 22:10:24');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('180', '13', 'REGISTRATION_OCR_PENDING_VERIFICATION', 'users', '13', 'NULL', '{\"ocr_status\":\"unreadable\",\"ocr_match_score\":0}', '::1', '2026-05-28 22:23:14');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('181', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-28 22:23:26');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('182', '4', 'REJECT_REGISTRATION', 'users', '13', 'NULL', '{\"reason\":\"sayod kaw\"}', '::1', '2026-05-28 22:24:34');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('183', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-05-28 22:29:22');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('184', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-05-28 22:29:56');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('185', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-06-01 19:14:49');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('186', '5', 'LOGIN', 'users', '5', 'NULL', 'NULL', '::1', '2026-06-01 19:20:17');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('187', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-06-01 19:37:25');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('188', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-06-01 20:30:47');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('189', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-06-01 20:55:17');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('190', '1', 'AUTO_DAILY_BACKUP', 'system', '0', 'NULL', 'NULL', '127.0.0.1', '2026-06-02 14:40:26');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('191', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-06-02 14:42:12');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('192', '4', 'RUN_MONTHLY_MAINTENANCE', 'system', '0', 'NULL', 'NULL', '::1', '2026-06-02 14:44:17');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('193', '4', 'CREATE_COMPLETE_SYSTEM_BACKUP', 'system', '0', 'NULL', 'NULL', '::1', '2026-06-02 14:52:22');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('194', '4', 'CREATE_DATABASE_BACKUP', 'system', '0', 'NULL', 'NULL', '::1', '2026-06-02 14:52:33');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('195', '4', 'CREATE_WEEKLY_BACKUP', 'system', '0', 'NULL', 'NULL', '::1', '2026-06-02 14:52:37');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('196', '1', 'ISSUE_CERTIFICATE', 'baptism_records', '1', 'NULL', '{\"certificate_number\":\"BAP-2026-000001\"}', '127.0.0.1', '2026-06-02 14:54:47');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('197', '4', 'ISSUE_CERTIFICATE', 'confirmation_records', '2', 'NULL', '{\"certificate_number\":\"CON-2026-000001\"}', '::1', '2026-06-02 15:02:42');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('198', '4', 'DELETE_ANNOUNCEMENT', 'announcements', '1', 'NULL', 'NULL', '::1', '2026-06-02 15:16:22');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('199', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-06-05 16:34:20');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('200', '4', 'AUTO_DAILY_BACKUP', 'system', '0', 'NULL', 'NULL', '::1', '2026-06-05 16:45:26');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('201', '4', 'CREATE_COMPLETE_SYSTEM_BACKUP', 'system', '0', 'NULL', 'NULL', '::1', '2026-06-05 16:45:55');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('202', '14', 'REGISTRATION_OCR_PENDING_VERIFICATION', 'users', '14', 'NULL', '{\"ocr_status\":\"unreadable\",\"ocr_match_score\":0}', '::1', '2026-06-05 16:49:27');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('203', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-06-05 16:49:59');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('204', '15', 'REGISTRATION_OCR_PENDING_VERIFICATION', 'users', '15', 'NULL', '{\"ocr_status\":\"unreadable\",\"ocr_match_score\":0}', '::1', '2026-06-06 02:08:05');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('205', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '127.0.0.1', '2026-06-06 02:23:11');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('206', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-06-06 22:30:22');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('207', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-06-06 22:33:07');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('208', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-06-06 22:35:36');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('209', '6', 'LOGIN', 'users', '6', 'NULL', 'NULL', '::1', '2026-06-06 22:36:05');
INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `table_name`, `record_id`, `old_value`, `new_value`, `ip_address`, `created_at`) VALUES ('210', '4', 'LOGIN', 'users', '4', 'NULL', 'NULL', '::1', '2026-06-06 22:50:32');

DROP TABLE IF EXISTS `baptism_records`;
CREATE TABLE `baptism_records` (
  `baptism_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) DEFAULT NULL,
  `registry_no` varchar(50) DEFAULT NULL,
  `book_no` varchar(40) DEFAULT NULL,
  `page_no` varchar(40) DEFAULT NULL,
  `entry_no` varchar(40) DEFAULT NULL,
  `fullname` varchar(100) NOT NULL,
  `birth_date` date DEFAULT NULL,
  `birth_place` varchar(150) DEFAULT NULL,
  `birth_status` varchar(80) DEFAULT NULL,
  `baptism_date` date DEFAULT NULL,
  `parents` varchar(200) DEFAULT NULL,
  `parent_address` varchar(200) DEFAULT NULL,
  `godparents` varchar(200) DEFAULT NULL,
  `parish_address` varchar(200) DEFAULT NULL,
  `priest` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`baptism_id`),
  KEY `fullname` (`fullname`),
  KEY `baptism_date` (`baptism_date`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `baptism_records` (`baptism_id`, `request_id`, `registry_no`, `book_no`, `page_no`, `entry_no`, `fullname`, `birth_date`, `birth_place`, `birth_status`, `baptism_date`, `parents`, `parent_address`, `godparents`, `parish_address`, `priest`, `remarks`, `status`, `created_at`, `updated_at`) VALUES ('1', NULL, NULL, NULL, NULL, NULL, 'REY MARK C. CAVANAS', '2005-12-16', NULL, NULL, '2006-04-25', 'joy cavanas/ roberto cavanas', NULL, 'lee', NULL, 'fr. lee javier,omi.', NULL, 'active', '2026-05-07 19:30:43', '2026-05-07 19:30:43');
INSERT INTO `baptism_records` (`baptism_id`, `request_id`, `registry_no`, `book_no`, `page_no`, `entry_no`, `fullname`, `birth_date`, `birth_place`, `birth_status`, `baptism_date`, `parents`, `parent_address`, `godparents`, `parish_address`, `priest`, `remarks`, `status`, `created_at`, `updated_at`) VALUES ('2', NULL, '', NULL, NULL, NULL, 'REY MARK C. CAVANAS', '2005-12-16', '', '', '2006-04-25', 'joy cavanas/ roberto cavanas', '', 'lee', '', 'fr. lee javier,omi.', '', 'active', '2026-05-07 19:32:52', '2026-05-19 14:12:09');

DROP TABLE IF EXISTS `certificate_issuances`;
CREATE TABLE `certificate_issuances` (
  `certificate_id` int(11) NOT NULL AUTO_INCREMENT,
  `certificate_type` varchar(50) NOT NULL,
  `record_table` varchar(80) NOT NULL,
  `record_id` int(11) NOT NULL,
  `certificate_number` varchar(40) NOT NULL,
  `verification_code` varchar(80) NOT NULL,
  `issued_by` int(11) DEFAULT NULL,
  `issued_to` varchar(150) DEFAULT NULL,
  `status` varchar(30) DEFAULT 'valid',
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`certificate_id`),
  UNIQUE KEY `certificate_number` (`certificate_number`),
  UNIQUE KEY `verification_code` (`verification_code`),
  KEY `idx_certificate_record` (`record_table`,`record_id`),
  KEY `idx_certificate_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `certificate_issuances` (`certificate_id`, `certificate_type`, `record_table`, `record_id`, `certificate_number`, `verification_code`, `issued_by`, `issued_to`, `status`, `issued_at`, `updated_at`) VALUES ('1', 'baptism', 'baptism_records', '1', 'BAP-2026-000001', 'BAP-4E8DB5CD-95D5B740', '1', 'REY MARK C. CAVANAS', 'valid', '2026-06-02 14:54:47', '2026-06-02 14:54:47');
INSERT INTO `certificate_issuances` (`certificate_id`, `certificate_type`, `record_table`, `record_id`, `certificate_number`, `verification_code`, `issued_by`, `issued_to`, `status`, `issued_at`, `updated_at`) VALUES ('2', 'confirmation', 'confirmation_records', '2', 'CON-2026-000001', 'CON-4A2102F4-BD540CC5', '4', 'REY MARK C. CAVANAS', 'valid', '2026-06-02 15:02:42', '2026-06-02 15:02:42');

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
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('5', 'baptismal', '<h1>Baptismal Certificate</h1><p>Full Name: {{fullname}}</p><p>Birth Date: {{birth_date}}</p><p>Baptism Date: {{baptism_date}}</p>', '1', '2026-05-07 11:55:41', '2026-05-07 11:55:41');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('6', 'confirmation', '<h1>Confirmation Certificate</h1><p>Full Name: {{fullname}}</p><p>Confirmation Date: {{confirmation_date}}</p>', '1', '2026-05-07 11:55:41', '2026-05-07 11:55:41');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('7', 'first_communion', '<h1>First Communion Certificate</h1><p>Full Name: {{fullname}}</p><p>Communion Date: {{communion_date}}</p>', '1', '2026-05-07 11:55:41', '2026-05-07 11:55:41');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('9', 'baptismal', '<h1>Baptismal Certificate</h1><p>Full Name: {{fullname}}</p><p>Birth Date: {{birth_date}}</p><p>Baptism Date: {{baptism_date}}</p>', '1', '2026-05-07 11:59:49', '2026-05-07 11:59:49');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('10', 'confirmation', '<h1>Confirmation Certificate</h1><p>Full Name: {{fullname}}</p><p>Confirmation Date: {{confirmation_date}}</p>', '1', '2026-05-07 11:59:49', '2026-05-07 11:59:49');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('11', 'first_communion', '<h1>First Communion Certificate</h1><p>Full Name: {{fullname}}</p><p>Communion Date: {{communion_date}}</p>', '1', '2026-05-07 11:59:49', '2026-05-07 11:59:49');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('13', 'baptismal', '<h1>Baptismal Certificate</h1><p>Full Name: {{fullname}}</p><p>Birth Date: {{birth_date}}</p><p>Baptism Date: {{baptism_date}}</p>', '1', '2026-05-07 12:04:29', '2026-05-07 12:04:29');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('14', 'confirmation', '<h1>Confirmation Certificate</h1><p>Full Name: {{fullname}}</p><p>Confirmation Date: {{confirmation_date}}</p>', '1', '2026-05-07 12:04:29', '2026-05-07 12:04:29');
INSERT INTO `certificate_templates` (`template_id`, `certificate_type`, `template_content`, `created_by`, `created_at`, `updated_at`) VALUES ('15', 'first_communion', '<h1>First Communion Certificate</h1><p>Full Name: {{fullname}}</p><p>Communion Date: {{communion_date}}</p>', '1', '2026-05-07 12:04:29', '2026-05-07 12:04:29');

DROP TABLE IF EXISTS `chatbot_inquiries`;
CREATE TABLE `chatbot_inquiries` (
  `inquiry_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `user_role` varchar(30) DEFAULT 'user',
  `question` text NOT NULL,
  `answer_preview` text DEFAULT NULL,
  `mode` varchar(40) DEFAULT 'chat',
  `context_limited` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`inquiry_id`),
  KEY `idx_chatbot_inquiries_created` (`created_at`),
  KEY `idx_chatbot_inquiries_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `chatbot_inquiries` (`inquiry_id`, `user_id`, `user_role`, `question`, `answer_preview`, `mode`, `context_limited`, `created_at`) VALUES ('1', '4', 'admin', 'Show analytics report summary', 'Administrative activity summary: Pending requests are manageable. Review oldest items first to keep turnaround time steady. There are open reservations to monitor for schedule conflicts and confirmation messages.', 'analytics', '1', '2026-05-27 01:45:24');
INSERT INTO `chatbot_inquiries` (`inquiry_id`, `user_id`, `user_role`, `question`, `answer_preview`, `mode`, `context_limited`, `created_at`) VALUES ('2', '5', 'user', 'xla,xma', 'Sorry, I can only answer questions related to Tugon and parish services. Your question is outside my assigned context.', 'chat', '1', '2026-06-01 19:20:25');
INSERT INTO `chatbot_inquiries` (`inquiry_id`, `user_id`, `user_role`, `question`, `answer_preview`, `mode`, `context_limited`, `created_at`) VALUES ('3', '5', 'user', 'schedule of mass', 'The stored Mass schedule is Sunday Mass at 6:00 AM, 8:00 AM, and 5:00 PM; weekday Mass is Monday to Friday at 5:30 PM.', 'chat', '1', '2026-06-01 19:20:35');
INSERT INTO `chatbot_inquiries` (`inquiry_id`, `user_id`, `user_role`, `question`, `answer_preview`, `mode`, `context_limited`, `created_at`) VALUES ('4', '5', 'user', 'how to request baptismal certificate?', 'To request a certificate, go to Certificates, select the certificate type, enter the needed details, attach your requirements file, and submit the request for parish review.', 'chat', '1', '2026-06-01 19:22:03');
INSERT INTO `chatbot_inquiries` (`inquiry_id`, `user_id`, `user_role`, `question`, `answer_preview`, `mode`, `context_limited`, `created_at`) VALUES ('5', '5', 'user', 'what are the  requirements?', 'For certificate and sacramental requests, submit accurate details and upload a clear requirements file such as a valid ID, supporting parish document, or required record scan before submitting.', 'chat', '1', '2026-06-01 19:22:17');
INSERT INTO `chatbot_inquiries` (`inquiry_id`, `user_id`, `user_role`, `question`, `answer_preview`, `mode`, `context_limited`, `created_at`) VALUES ('6', '5', 'user', 'am i pogi?\\', 'Sorry, I can only answer questions related to Tugon and parish services. Your question is outside my assigned context.', 'chat', '1', '2026-06-01 19:23:53');
INSERT INTO `chatbot_inquiries` (`inquiry_id`, `user_id`, `user_role`, `question`, `answer_preview`, `mode`, `context_limited`, `created_at`) VALUES ('7', '4', 'admin', 'Show analytics report summary', 'Administrative activity summary: Pending requests are manageable. Review oldest items first to keep turnaround time steady. There are open reservations to monitor for schedule conflicts and confirmation messages.', 'analytics', '1', '2026-06-05 16:44:40');

DROP TABLE IF EXISTS `confirmation_records`;
CREATE TABLE `confirmation_records` (
  `confirmation_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) DEFAULT NULL,
  `registry_no` varchar(50) DEFAULT NULL,
  `fullname` varchar(100) NOT NULL,
  `birth_date` date DEFAULT NULL,
  `confirmation_date` date DEFAULT NULL,
  `confirmation_name` varchar(100) DEFAULT NULL,
  `age` varchar(30) DEFAULT NULL,
  `origin_parish` varchar(150) DEFAULT NULL,
  `origin_province` varchar(150) DEFAULT NULL,
  `baptismal_place` varchar(150) DEFAULT NULL,
  `parents` varchar(200) DEFAULT NULL,
  `sponsor` varchar(100) DEFAULT NULL,
  `bishop_priest` varchar(100) DEFAULT NULL,
  `stipend_pesos` varchar(30) DEFAULT NULL,
  `stipend_cents` varchar(30) DEFAULT NULL,
  `observations` text DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`confirmation_id`),
  KEY `fullname` (`fullname`),
  KEY `confirmation_date` (`confirmation_date`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `confirmation_records` (`confirmation_id`, `request_id`, `registry_no`, `fullname`, `birth_date`, `confirmation_date`, `confirmation_name`, `age`, `origin_parish`, `origin_province`, `baptismal_place`, `parents`, `sponsor`, `bishop_priest`, `stipend_pesos`, `stipend_cents`, `observations`, `status`, `created_at`, `updated_at`) VALUES ('2', NULL, '1', 'REY MARK C. CAVANAS', '2005-12-16', '2000-01-12', 'rei', '12', 'slr', 'cotabato', 'slr', 'dimpol', 'joy', 'fr. roger', 'sas', 'sas', '', 'active', '2026-05-18 03:30:16', '2026-05-18 03:30:16');

DROP TABLE IF EXISTS `email_verifications`;
CREATE TABLE `email_verifications` (
  `verification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`verification_id`),
  KEY `idx_email_verifications_user` (`user_id`),
  KEY `idx_email_verifications_token` (`token_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `email_verifications` (`verification_id`, `user_id`, `email`, `token_hash`, `expires_at`, `verified_at`, `created_at`) VALUES ('1', '14', 'reyjohn@gmail.com', '9a0629ef4805e93f2bd5a0091f35887a5c008ae67db61fb98c511d583152ab44', '2026-06-06 10:49:22', NULL, '2026-06-05 16:49:22');
INSERT INTO `email_verifications` (`verification_id`, `user_id`, `email`, `token_hash`, `expires_at`, `verified_at`, `created_at`) VALUES ('2', '15', 'pare@gmail.com', '47d8b0eedbc3477531d456087287a8fdb60a5e81286b3762186d662b785e732e', '2026-06-06 20:08:00', NULL, '2026-06-06 02:08:00');

DROP TABLE IF EXISTS `first_communion_records`;
CREATE TABLE `first_communion_records` (
  `communion_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) DEFAULT NULL,
  `registry_no` varchar(50) DEFAULT NULL,
  `fullname` varchar(100) NOT NULL,
  `birth_date` date DEFAULT NULL,
  `communion_date` date DEFAULT NULL,
  `domicile` varchar(150) DEFAULT NULL,
  `parents` varchar(200) DEFAULT NULL,
  `sponsor` varchar(100) DEFAULT NULL,
  `priest` varchar(100) DEFAULT NULL,
  `folio` varchar(50) DEFAULT NULL,
  `baptismal_date` date DEFAULT NULL,
  `baptismal_place` varchar(150) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`communion_id`),
  KEY `fullname` (`fullname`),
  KEY `communion_date` (`communion_date`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `first_communion_records` (`communion_id`, `request_id`, `registry_no`, `fullname`, `birth_date`, `communion_date`, `domicile`, `parents`, `sponsor`, `priest`, `folio`, `baptismal_date`, `baptismal_place`, `remarks`, `status`, `created_at`, `updated_at`) VALUES ('2', NULL, '12', 'tere', '2000-12-07', '2025-05-20', 'dado, alamada', 'REY MARK', 'PRINCE', 'fr. roger', 'SA', '2026-05-20', 'SLR', '', 'active', '2026-05-20 04:53:10', '2026-05-20 04:55:10');

DROP TABLE IF EXISTS `funeral_records`;
CREATE TABLE `funeral_records` (
  `funeral_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) DEFAULT NULL,
  `registry_no` varchar(50) DEFAULT NULL,
  `deceased_name` varchar(150) NOT NULL,
  `family_name` varchar(150) DEFAULT NULL,
  `date_of_death` date DEFAULT NULL,
  `date_of_burial` date DEFAULT NULL,
  `civil_status` varchar(80) DEFAULT NULL,
  `funeral_rites` varchar(120) DEFAULT NULL,
  `cause_of_death` varchar(200) DEFAULT NULL,
  `place_of_burial` varchar(200) DEFAULT NULL,
  `minister` varchar(120) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`funeral_id`),
  KEY `deceased_name` (`deceased_name`),
  KEY `family_name` (`family_name`),
  KEY `date_of_burial` (`date_of_burial`),
  KEY `request_id` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `maintenance_logs`;
CREATE TABLE `maintenance_logs` (
  `maintenance_id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `maintenance_type` varchar(80) NOT NULL,
  `status` varchar(40) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`maintenance_id`),
  KEY `idx_maintenance_logs_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `maintenance_logs` (`maintenance_id`, `admin_id`, `maintenance_type`, `status`, `details`, `created_at`) VALUES ('1', '4', 'monthly_maintenance', 'completed', 'Database tables repaired and optimized.\nExpired OTP records removed.\nExpired unverified email tokens removed.\nCache and temporary files reviewed.\n13 backup file(s) passed validation.\n0 expired backup file(s) removed by retention policy.', '2026-06-02 14:44:17');

DROP TABLE IF EXISTS `marriage_records`;
CREATE TABLE `marriage_records` (
  `marriage_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) DEFAULT NULL,
  `registry_no` varchar(50) DEFAULT NULL,
  `husband_name` varchar(100) NOT NULL,
  `husband_status` varchar(80) DEFAULT NULL,
  `husband_age` varchar(30) DEFAULT NULL,
  `husband_birth_origin` varchar(150) DEFAULT NULL,
  `husband_residence` varchar(200) DEFAULT NULL,
  `husband_parents` varchar(200) DEFAULT NULL,
  `wife_name` varchar(100) NOT NULL,
  `wife_status` varchar(80) DEFAULT NULL,
  `wife_age` varchar(30) DEFAULT NULL,
  `wife_birth_origin` varchar(150) DEFAULT NULL,
  `wife_residence` varchar(200) DEFAULT NULL,
  `wife_parents` varchar(200) DEFAULT NULL,
  `wedding_date` date DEFAULT NULL,
  `sponsors` varchar(200) DEFAULT NULL,
  `witnesses_residence` varchar(200) DEFAULT NULL,
  `officiating_priest` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`marriage_id`),
  KEY `husband_name` (`husband_name`),
  KEY `wife_name` (`wife_name`),
  KEY `wedding_date` (`wedding_date`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `notification_logs`;
CREATE TABLE `notification_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `notification_type` varchar(80) DEFAULT 'system',
  `delivery_status` varchar(30) DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_notification_logs_status` (`delivery_status`),
  KEY `idx_notification_logs_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `notification_logs` (`log_id`, `user_id`, `email`, `subject`, `notification_type`, `delivery_status`, `error_message`, `sent_at`, `created_at`) VALUES ('1', '14', 'reyjohn@gmail.com', 'Verify your TUGON Gmail account', 'email_verification', 'failed', 'PHP mail() failed. Configure SMTP/sendmail in XAMPP or add an SMTP provider.', NULL, '2026-06-05 16:49:24');
INSERT INTO `notification_logs` (`log_id`, `user_id`, `email`, `subject`, `notification_type`, `delivery_status`, `error_message`, `sent_at`, `created_at`) VALUES ('2', '14', 'reyjohn@gmail.com', 'Your TUGON OTP Code', 'otp_registration', 'failed', 'PHP mail() failed. Configure SMTP/sendmail in XAMPP or add an SMTP provider.', NULL, '2026-06-05 16:49:27');
INSERT INTO `notification_logs` (`log_id`, `user_id`, `email`, `subject`, `notification_type`, `delivery_status`, `error_message`, `sent_at`, `created_at`) VALUES ('3', '15', 'pare@gmail.com', 'Verify your TUGON Gmail account', 'email_verification', 'failed', 'PHP mail() failed. Configure SMTP/sendmail in XAMPP or add an SMTP provider.', NULL, '2026-06-06 02:08:02');
INSERT INTO `notification_logs` (`log_id`, `user_id`, `email`, `subject`, `notification_type`, `delivery_status`, `error_message`, `sent_at`, `created_at`) VALUES ('4', '15', 'pare@gmail.com', 'Your TUGON OTP Code', 'otp_registration', 'failed', 'PHP mail() failed. Configure SMTP/sendmail in XAMPP or add an SMTP provider.', NULL, '2026-06-06 02:08:05');

DROP TABLE IF EXISTS `notification_preferences`;
CREATE TABLE `notification_preferences` (
  `preference_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `category` varchar(80) NOT NULL,
  `email_enabled` tinyint(1) DEFAULT 1,
  `in_app_enabled` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`preference_id`),
  UNIQUE KEY `uniq_user_category` (`user_id`,`category`)
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
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('1', '6', 'Request Created', 'Your request has been submitted with reference: PRQ-20260507-25361', '1', '2026-05-07 20:03:56');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('2', '6', 'Request Update', 'Your request status has been updated to: Rejected', '1', '2026-05-08 20:49:59');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('3', '6', 'Request Created', 'Your request has been submitted with reference: PRQ-20260508-85057', '1', '2026-05-08 20:50:20');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('4', '6', 'Request Created', 'Your request has been submitted with reference: PRQ-20260508-14732', '1', '2026-05-08 20:52:15');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('5', '6', 'Request Update', 'Your request status has been updated to: Rejected', '1', '2026-05-10 12:35:29');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('6', '9', 'Account Approved', 'Your account has been approved. You may now log in to the Parish Management System.', '0', '2026-05-16 00:45:28');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('7', '6', 'Request Created', 'Your request has been submitted with reference: PRQ-20260517-53926', '0', '2026-05-18 02:36:42');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('8', '6', 'Request Update', 'Your request status has been updated to: Approved', '0', '2026-05-18 02:37:40');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('9', '5', 'Blessing Request Created', 'Your blessing request has been submitted with reference: PRQ-20260519-61174', '0', '2026-05-19 17:50:03');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('10', '5', 'Request Update', 'Your request status has been updated to: Processing', '0', '2026-05-19 17:50:41');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('11', '5', 'Reservation Submitted', 'Your reservation request has been submitted for review.', '0', '2026-05-19 18:03:49');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('12', '5', 'Reservation Update', 'Your Wedding reservation is now Approved.', '0', '2026-05-19 18:04:57');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('13', '5', 'Reservation Submitted', 'Your reservation request has been submitted for review.', '0', '2026-05-19 18:06:17');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('14', '5', 'Reservation Update', 'Your Wedding reservation is now Approved.', '0', '2026-05-19 18:08:42');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('15', '5', 'Reservation Submitted', 'Your reservation request has been submitted for review.', '0', '2026-05-19 18:19:58');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('16', '5', 'Request Update', 'Your request status has been updated to: Approved', '0', '2026-05-19 18:20:33');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('17', '10', 'Account Approved', 'Your account has been approved. You may now log in to the Parish Management System.', '0', '2026-05-19 23:39:13');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('18', '5', 'Blessing Request Created', 'Your blessing request has been submitted with reference: PRQ-20260520-27479', '0', '2026-05-20 07:11:07');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('19', '11', 'Account Approved', 'Your account has been approved. You may now log in to the Parish Management System.', '0', '2026-05-20 08:50:01');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('20', '11', 'Certificate Request Created', 'Your certificate request has been submitted with reference: PRQ-20260520-73152', '0', '2026-05-20 08:50:52');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('21', '11', 'Blessing Request Created', 'Your blessing request has been submitted with reference: PRQ-20260520-11272', '0', '2026-05-20 08:51:35');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('22', '11', 'Request Update', 'Your request status has been updated to: Approved', '0', '2026-05-20 08:52:27');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('23', '11', 'Sacramental Service Request Created', 'Your service request has been submitted with reference: PRQ-20260520-85956', '0', '2026-05-20 08:54:47');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('24', '11', 'Blessing Request Created', 'Your blessing request has been submitted with reference: PRQ-20260520-90396', '0', '2026-05-20 08:59:49');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('25', '12', 'Account Approved', 'Your account has been approved. You may now log in to the Parish Management System.', '0', '2026-05-28 22:10:24');
INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `message`, `is_read`, `created_at`) VALUES ('26', '13', 'Registration Not Approved', 'Your registration was not approved by the parish administrator. Reason: sayod kaw', '0', '2026-05-28 22:24:34');

DROP TABLE IF EXISTS `otp_codes`;
CREATE TABLE `otp_codes` (
  `otp_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `purpose` varchar(40) NOT NULL,
  `otp_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` int(11) DEFAULT 0,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`otp_id`),
  KEY `idx_otp_user_purpose` (`user_id`,`purpose`),
  KEY `idx_otp_email_purpose` (`email`,`purpose`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `recovery_logs`;
CREATE TABLE `recovery_logs` (
  `recovery_id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `recovery_type` varchar(80) NOT NULL,
  `backup_file` varchar(255) DEFAULT NULL,
  `files_restored` int(11) DEFAULT 0,
  `status` varchar(40) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`recovery_id`),
  KEY `idx_recovery_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `request_documents`;
CREATE TABLE `request_documents` (
  `document_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `document_type` varchar(60) DEFAULT 'requirement',
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `file_size` int(10) unsigned NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`document_id`),
  KEY `idx_request_documents_request` (`request_id`),
  KEY `idx_request_documents_uploader` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `requests`;
CREATE TABLE `requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `request_type` varchar(80) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','processing','completed') DEFAULT 'pending',
  `date_requested` timestamp NOT NULL DEFAULT current_timestamp(),
  `admin_response` text DEFAULT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  UNIQUE KEY `reference_number` (`reference_number`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `requests` (`request_id`, `user_id`, `request_type`, `description`, `status`, `date_requested`, `admin_response`, `reference_number`, `updated_at`, `deleted_at`) VALUES ('1', '6', 'marriage_certificate', 'kkk', 'rejected', '2026-05-07 20:03:56', '', 'PRQ-20260507-25361', '2026-05-08 20:49:59', NULL);
INSERT INTO `requests` (`request_id`, `user_id`, `request_type`, `description`, `status`, `date_requested`, `admin_response`, `reference_number`, `updated_at`, `deleted_at`) VALUES ('2', '6', 'baptismal_certificate', '', 'pending', '2026-05-08 20:50:20', NULL, 'PRQ-20260508-85057', '2026-05-15 23:04:02', '2026-05-15 23:04:02');
INSERT INTO `requests` (`request_id`, `user_id`, `request_type`, `description`, `status`, `date_requested`, `admin_response`, `reference_number`, `updated_at`, `deleted_at`) VALUES ('3', '6', 'confirmation_certificate', '', 'rejected', '2026-05-08 20:52:15', 'pangit ka', 'PRQ-20260508-14732', '2026-05-15 22:56:54', '2026-05-15 22:56:54');
INSERT INTO `requests` (`request_id`, `user_id`, `request_type`, `description`, `status`, `date_requested`, `admin_response`, `reference_number`, `updated_at`, `deleted_at`) VALUES ('4', '6', 'first_communion_certificate', '', 'approved', '2026-05-18 02:36:42', '', 'PRQ-20260517-53926', '2026-05-18 02:37:40', NULL);
INSERT INTO `requests` (`request_id`, `user_id`, `request_type`, `description`, `status`, `date_requested`, `admin_response`, `reference_number`, `updated_at`, `deleted_at`) VALUES ('5', '5', 'house_blessing', 'Preferred date: 2026-06-20\nPreferred time: 08:30\nLocation: Sitio Taguan, San Mateo, Aleosan, Cotabato\nDetails: likod balay ni pani', 'approved', '2026-05-19 17:50:03', '', 'PRQ-20260519-61174', '2026-05-19 18:20:33', NULL);
INSERT INTO `requests` (`request_id`, `user_id`, `request_type`, `description`, `status`, `date_requested`, `admin_response`, `reference_number`, `updated_at`, `deleted_at`) VALUES ('6', '5', 'house_blessing', 'Preferred date: 2026-05-20\nPreferred time: 08:00\nLocation: ASAs\nDetails: None', 'pending', '2026-05-20 07:11:07', NULL, 'PRQ-20260520-27479', '2026-05-20 07:11:07', NULL);
INSERT INTO `requests` (`request_id`, `user_id`, `request_type`, `description`, `status`, `date_requested`, `admin_response`, `reference_number`, `updated_at`, `deleted_at`) VALUES ('7', '11', 'baptismal_certificate', '', 'pending', '2026-05-20 08:50:52', NULL, 'PRQ-20260520-73152', '2026-05-20 08:50:52', NULL);
INSERT INTO `requests` (`request_id`, `user_id`, `request_type`, `description`, `status`, `date_requested`, `admin_response`, `reference_number`, `updated_at`, `deleted_at`) VALUES ('8', '11', 'vehicle_blessing', 'Preferred date: 2026-05-21\nPreferred time: 08:30\nLocation: Sitio Taguan, San Mateo, Aleosan, Cotabato\nDetails: None', 'approved', '2026-05-20 08:51:35', '', 'PRQ-20260520-11272', '2026-05-20 08:52:27', NULL);
INSERT INTO `requests` (`request_id`, `user_id`, `request_type`, `description`, `status`, `date_requested`, `admin_response`, `reference_number`, `updated_at`, `deleted_at`) VALUES ('9', '11', 'marriage_wedding_service', 'Preferred date: 2026-05-21\nPreferred time: 08:30\nLocation: asadahda\nDetails: None', 'pending', '2026-05-20 08:54:47', NULL, 'PRQ-20260520-85956', '2026-05-20 08:54:47', NULL);
INSERT INTO `requests` (`request_id`, `user_id`, `request_type`, `description`, `status`, `date_requested`, `admin_response`, `reference_number`, `updated_at`, `deleted_at`) VALUES ('10', '11', 'business_blessing', 'Preferred date: 2026-05-21\nPreferred time: 08:30\nLocation: Sitio Taguan, San Mateo, Aleosan, Cotabato\nDetails: None', 'pending', '2026-05-20 08:59:49', NULL, 'PRQ-20260520-90396', '2026-05-20 08:59:49', NULL);

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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `reservations` (`reservation_id`, `user_id`, `reservation_type`, `event_date`, `event_time`, `event_details`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES ('1', '6', 'baptism', '0020-12-23', '09:49:00', '', 'pending', NULL, '2026-05-08 20:47:25', '2026-05-08 20:47:25');
INSERT INTO `reservations` (`reservation_id`, `user_id`, `reservation_type`, `event_date`, `event_time`, `event_details`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES ('2', '6', 'church_venue', '2026-05-12', '20:49:00', '', 'pending', NULL, '2026-05-08 20:47:56', '2026-05-08 20:47:56');
INSERT INTO `reservations` (`reservation_id`, `user_id`, `reservation_type`, `event_date`, `event_time`, `event_details`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES ('3', '6', 'baptism', '2022-12-31', '21:53:00', '', 'pending', NULL, '2026-05-08 20:51:10', '2026-05-08 20:51:10');
INSERT INTO `reservations` (`reservation_id`, `user_id`, `reservation_type`, `event_date`, `event_time`, `event_details`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES ('4', '6', 'wedding', '2026-12-20', '21:51:00', '', 'pending', NULL, '2026-05-08 20:51:59', '2026-05-08 20:51:59');
INSERT INTO `reservations` (`reservation_id`, `user_id`, `reservation_type`, `event_date`, `event_time`, `event_details`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES ('5', '5', 'wedding', '2026-05-20', '07:30:00', 'Please', 'approved', 'okay', '2026-05-19 18:03:49', '2026-05-19 18:04:57');
INSERT INTO `reservations` (`reservation_id`, `user_id`, `reservation_type`, `event_date`, `event_time`, `event_details`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES ('6', '5', 'wedding', '2026-05-20', '10:00:00', 'please', 'approved', 'yess', '2026-05-19 18:06:17', '2026-05-19 18:08:42');
INSERT INTO `reservations` (`reservation_id`, `user_id`, `reservation_type`, `event_date`, `event_time`, `event_details`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES ('7', '5', 'baptism', '2026-05-20', '08:00:00', '', 'pending', NULL, '2026-05-19 18:19:58', '2026-05-19 18:19:58');

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
  `source_type` varchar(40) DEFAULT 'manual',
  `source_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`schedule_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_schedule_date` (`event_date`),
  KEY `idx_schedule_status_date` (`status`,`event_date`,`start_time`),
  KEY `idx_schedule_source` (`source_type`,`source_id`),
  CONSTRAINT `schedule_events_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `schedule_events` (`schedule_id`, `title`, `description`, `event_date`, `start_time`, `end_time`, `location`, `category`, `priority`, `color_label`, `recurrence_rule`, `assigned_personnel`, `visibility`, `approval_status`, `status`, `reminder_minutes`, `notify_email`, `notify_sms`, `source_type`, `source_id`, `created_by`, `created_at`, `updated_at`) VALUES ('2', 'sasa', '', '2026-05-15', '08:00:00', '09:00:00', 'asa', 'mass', 'normal', '#34a853', 'none', 'sasa', 'public', 'approved', 'upcoming', '30', '0', '0', 'manual', NULL, '4', '2026-05-15 12:31:07', '2026-05-15 12:31:07');
INSERT INTO `schedule_events` (`schedule_id`, `title`, `description`, `event_date`, `start_time`, `end_time`, `location`, `category`, `priority`, `color_label`, `recurrence_rule`, `assigned_personnel`, `visibility`, `approval_status`, `status`, `reminder_minutes`, `notify_email`, `notify_sms`, `source_type`, `source_id`, `created_by`, `created_at`, `updated_at`) VALUES ('3', 'DIMPL', 'ASASASA', '2026-05-18', '05:00:00', '09:00:00', '', 'meeting', 'normal', '#00acc1', 'none', '', 'public', 'approved', 'upcoming', '30', '0', '0', 'manual', NULL, '4', '2026-05-18 03:36:34', '2026-05-18 03:36:34');
INSERT INTO `schedule_events` (`schedule_id`, `title`, `description`, `event_date`, `start_time`, `end_time`, `location`, `category`, `priority`, `color_label`, `recurrence_rule`, `assigned_personnel`, `visibility`, `approval_status`, `status`, `reminder_minutes`, `notify_email`, `notify_sms`, `source_type`, `source_id`, `created_by`, `created_at`, `updated_at`) VALUES ('4', 'Wedding Reservation - REYMARK', 'Please\n\nAdmin notes: okay', '2026-05-19', '07:30:00', '08:30:00', 'Parish', 'reservation', 'normal', '#188038', 'none', '', 'public', 'approved', 'upcoming', '30', '0', '0', 'reservation', '5', '4', '2026-05-19 18:04:58', '2026-05-19 18:09:26');
INSERT INTO `schedule_events` (`schedule_id`, `title`, `description`, `event_date`, `start_time`, `end_time`, `location`, `category`, `priority`, `color_label`, `recurrence_rule`, `assigned_personnel`, `visibility`, `approval_status`, `status`, `reminder_minutes`, `notify_email`, `notify_sms`, `source_type`, `source_id`, `created_by`, `created_at`, `updated_at`) VALUES ('5', 'Wedding Reservation - REYMARK', 'please\n\nAdmin notes: yess', '2026-05-20', '10:00:00', '11:00:00', 'Parish', 'reservation', 'normal', '#188038', 'none', '', 'public', 'approved', 'upcoming', '30', '0', '0', 'reservation', '6', '4', '2026-05-19 18:08:42', '2026-05-19 18:08:42');
INSERT INTO `schedule_events` (`schedule_id`, `title`, `description`, `event_date`, `start_time`, `end_time`, `location`, `category`, `priority`, `color_label`, `recurrence_rule`, `assigned_personnel`, `visibility`, `approval_status`, `status`, `reminder_minutes`, `notify_email`, `notify_sms`, `source_type`, `source_id`, `created_by`, `created_at`, `updated_at`) VALUES ('6', 'n=ana', 'cscs', '2026-05-20', '08:00:00', '09:00:00', '', 'reservation', 'normal', '#188038', 'none', '', 'public', 'approved', 'upcoming', '30', '0', '0', 'manual', NULL, '4', '2026-05-19 18:09:50', '2026-05-19 18:09:50');
INSERT INTO `schedule_events` (`schedule_id`, `title`, `description`, `event_date`, `start_time`, `end_time`, `location`, `category`, `priority`, `color_label`, `recurrence_rule`, `assigned_personnel`, `visibility`, `approval_status`, `status`, `reminder_minutes`, `notify_email`, `notify_sms`, `source_type`, `source_id`, `created_by`, `created_at`, `updated_at`) VALUES ('7', 'House blessing - REYMARK', 'Preferred date: 2026-06-20\nPreferred time: 08:30\nLocation: Sitio Taguan, San Mateo, Aleosan, Cotabato\nDetails: likod balay ni pani', '2026-06-20', '08:30:00', '09:30:00', 'Sitio Taguan, San Mateo, Aleosan, Cotabato', 'blessing', 'normal', '#d7ad43', 'none', '', 'public', 'approved', 'upcoming', '30', '0', '0', 'request', '5', '4', '2026-05-19 18:20:33', '2026-05-19 18:20:33');
INSERT INTO `schedule_events` (`schedule_id`, `title`, `description`, `event_date`, `start_time`, `end_time`, `location`, `category`, `priority`, `color_label`, `recurrence_rule`, `assigned_personnel`, `visibility`, `approval_status`, `status`, `reminder_minutes`, `notify_email`, `notify_sms`, `source_type`, `source_id`, `created_by`, `created_at`, `updated_at`) VALUES ('8', 'Vehicle blessing - RYAN NAMBONG', 'Preferred date: 2026-05-21\nPreferred time: 08:30\nLocation: Sitio Taguan, San Mateo, Aleosan, Cotabato\nDetails: None', '2026-05-21', '08:30:00', '09:30:00', 'Sitio Taguan, San Mateo, Aleosan, Cotabato', 'blessing', 'normal', '#d7ad43', 'none', '', 'public', 'approved', 'upcoming', '30', '0', '0', 'request', '8', '4', '2026-05-20 08:52:27', '2026-05-20 08:52:27');

DROP TABLE IF EXISTS `sms_notification_logs`;
CREATE TABLE `sms_notification_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `phone_number` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `notification_type` varchar(80) DEFAULT 'system',
  `delivery_status` varchar(30) DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_sms_logs_phone` (`phone_number`),
  KEY `idx_sms_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `setting_key` varchar(120) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('last_daily_backup', '2026-06-05 10:45:26', '2026-06-05 16:45:26');
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('last_monthly_backup', '2026-06-05 10:45:55', '2026-06-05 16:45:55');
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('last_monthly_maintenance', '2026-06-02 08:44:16', '2026-06-02 14:44:16');
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES ('last_weekly_backup', '2026-06-02 08:52:37', '2026-06-02 14:52:37');

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fullname` varchar(255) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `surname` varchar(100) DEFAULT NULL,
  `middle_initial` varchar(5) DEFAULT NULL,
  `phone_number` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `email_verification_sent_at` timestamp NULL DEFAULT NULL,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `verification_method` enum('email','mobile') DEFAULT 'email',
  `login_otp_enabled` tinyint(1) DEFAULT 0,
  `chapel_district` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `id_number_hash` char(64) DEFAULT NULL,
  `id_number_encrypted` text DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('user','admin') DEFAULT 'user',
  `status` enum('active','inactive','pending_verification','rejected','archived') DEFAULT 'active',
  `profile_picture` varchar(255) DEFAULT NULL,
  `valid_id_path` varchar(255) DEFAULT NULL,
  `valid_id_original_name` varchar(255) DEFAULT NULL,
  `valid_id_mime_type` varchar(100) DEFAULT NULL,
  `valid_id_capture_method` varchar(40) DEFAULT 'live_camera',
  `face_image_path` varchar(255) DEFAULT NULL,
  `face_image_mime_type` varchar(100) DEFAULT NULL,
  `face_verification_status` varchar(40) DEFAULT 'pending',
  `face_verified_at` timestamp NULL DEFAULT NULL,
  `ocr_extracted_text_encrypted` mediumtext DEFAULT NULL,
  `ocr_extracted_data_encrypted` text DEFAULT NULL,
  `ocr_match_score` tinyint(3) unsigned DEFAULT 0,
  `ocr_status` varchar(40) DEFAULT 'manual_review',
  `ocr_processed_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_id_number_hash` (`id_number_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `users` (`id`, `fullname`, `first_name`, `surname`, `middle_initial`, `phone_number`, `email`, `email_verified_at`, `email_verification_sent_at`, `phone_verified_at`, `verification_method`, `login_otp_enabled`, `chapel_district`, `address`, `birthdate`, `id_number_hash`, `id_number_encrypted`, `password`, `role`, `status`, `profile_picture`, `valid_id_path`, `valid_id_original_name`, `valid_id_mime_type`, `valid_id_capture_method`, `face_image_path`, `face_image_mime_type`, `face_verification_status`, `face_verified_at`, `ocr_extracted_text_encrypted`, `ocr_extracted_data_encrypted`, `ocr_match_score`, `ocr_status`, `ocr_processed_at`, `rejection_reason`, `verified_at`, `verified_by`, `created_at`, `updated_at`) VALUES ('1', 'Admin User', NULL, NULL, NULL, '', 'admin@parish.com', '2026-06-01 20:30:44', NULL, NULL, 'email', '0', NULL, NULL, NULL, NULL, NULL, '$2y$10$4SOqfUllLomRA4aPdbsBDOlwYl8HRJKtYhovw5JrmAbUN3iHmspTG', 'admin', 'active', NULL, NULL, NULL, NULL, 'live_camera', NULL, NULL, 'pending', NULL, NULL, NULL, '0', 'manual_review', NULL, NULL, NULL, NULL, '2026-05-07 11:52:23', '2026-06-01 20:30:44');
INSERT INTO `users` (`id`, `fullname`, `first_name`, `surname`, `middle_initial`, `phone_number`, `email`, `email_verified_at`, `email_verification_sent_at`, `phone_verified_at`, `verification_method`, `login_otp_enabled`, `chapel_district`, `address`, `birthdate`, `id_number_hash`, `id_number_encrypted`, `password`, `role`, `status`, `profile_picture`, `valid_id_path`, `valid_id_original_name`, `valid_id_mime_type`, `valid_id_capture_method`, `face_image_path`, `face_image_mime_type`, `face_verification_status`, `face_verified_at`, `ocr_extracted_text_encrypted`, `ocr_extracted_data_encrypted`, `ocr_match_score`, `ocr_status`, `ocr_processed_at`, `rejection_reason`, `verified_at`, `verified_by`, `created_at`, `updated_at`) VALUES ('4', 'Admin', NULL, NULL, NULL, '555-0000', 'admin@gmail.com', '2026-06-01 20:30:44', NULL, NULL, 'email', '0', 'Main Chapel', NULL, NULL, NULL, NULL, '$2y$10$4SOqfUllLomRA4aPdbsBDOlwYl8HRJKtYhovw5JrmAbUN3iHmspTG', 'admin', 'active', NULL, NULL, NULL, NULL, 'live_camera', NULL, NULL, 'pending', NULL, NULL, NULL, '0', 'manual_review', NULL, NULL, NULL, NULL, '2026-05-07 12:04:29', '2026-06-01 20:30:44');
INSERT INTO `users` (`id`, `fullname`, `first_name`, `surname`, `middle_initial`, `phone_number`, `email`, `email_verified_at`, `email_verification_sent_at`, `phone_verified_at`, `verification_method`, `login_otp_enabled`, `chapel_district`, `address`, `birthdate`, `id_number_hash`, `id_number_encrypted`, `password`, `role`, `status`, `profile_picture`, `valid_id_path`, `valid_id_original_name`, `valid_id_mime_type`, `valid_id_capture_method`, `face_image_path`, `face_image_mime_type`, `face_verification_status`, `face_verified_at`, `ocr_extracted_text_encrypted`, `ocr_extracted_data_encrypted`, `ocr_match_score`, `ocr_status`, `ocr_processed_at`, `rejection_reason`, `verified_at`, `verified_by`, `created_at`, `updated_at`) VALUES ('5', 'REYMARK', NULL, NULL, NULL, '09635866550', 'reymarkcavanas0@gmail.com', '2026-06-01 20:30:44', NULL, NULL, 'email', '0', 'East District', NULL, NULL, NULL, NULL, '$2y$10$IHJbcO8C1c/EvOMrja6zEOhHdRiK7LSJBAhbcditzdknzy/SbtbAy', 'user', 'active', NULL, NULL, NULL, NULL, 'live_camera', NULL, NULL, 'pending', NULL, NULL, NULL, '0', 'manual_review', NULL, NULL, NULL, NULL, '2026-05-07 12:06:18', '2026-06-01 20:30:44');
INSERT INTO `users` (`id`, `fullname`, `first_name`, `surname`, `middle_initial`, `phone_number`, `email`, `email_verified_at`, `email_verification_sent_at`, `phone_verified_at`, `verification_method`, `login_otp_enabled`, `chapel_district`, `address`, `birthdate`, `id_number_hash`, `id_number_encrypted`, `password`, `role`, `status`, `profile_picture`, `valid_id_path`, `valid_id_original_name`, `valid_id_mime_type`, `valid_id_capture_method`, `face_image_path`, `face_image_mime_type`, `face_verification_status`, `face_verified_at`, `ocr_extracted_text_encrypted`, `ocr_extracted_data_encrypted`, `ocr_match_score`, `ocr_status`, `ocr_processed_at`, `rejection_reason`, `verified_at`, `verified_by`, `created_at`, `updated_at`) VALUES ('6', 'dimpol', NULL, NULL, NULL, '09635866550', 'dimpowalabalo@gmail.com', '2026-06-01 20:30:44', NULL, NULL, 'email', '0', 'Central Parish', NULL, NULL, NULL, NULL, '$2y$10$HF36TcM5yvgvNidZ6Lc9sOeGhLGycdLwBD5oIZyJSykHb2W2VW.Qq', 'user', 'active', NULL, NULL, NULL, NULL, 'live_camera', NULL, NULL, 'pending', NULL, NULL, NULL, '0', 'manual_review', NULL, NULL, NULL, NULL, '2026-05-07 19:40:18', '2026-06-01 20:30:44');
INSERT INTO `users` (`id`, `fullname`, `first_name`, `surname`, `middle_initial`, `phone_number`, `email`, `email_verified_at`, `email_verification_sent_at`, `phone_verified_at`, `verification_method`, `login_otp_enabled`, `chapel_district`, `address`, `birthdate`, `id_number_hash`, `id_number_encrypted`, `password`, `role`, `status`, `profile_picture`, `valid_id_path`, `valid_id_original_name`, `valid_id_mime_type`, `valid_id_capture_method`, `face_image_path`, `face_image_mime_type`, `face_verification_status`, `face_verified_at`, `ocr_extracted_text_encrypted`, `ocr_extracted_data_encrypted`, `ocr_match_score`, `ocr_status`, `ocr_processed_at`, `rejection_reason`, `verified_at`, `verified_by`, `created_at`, `updated_at`) VALUES ('7', 'hazel gadingan', NULL, NULL, NULL, '09123456789', 'blabla@gmail.com', '2026-06-01 20:30:44', NULL, NULL, 'email', '0', 'Main Chapel', NULL, NULL, NULL, NULL, '$2y$10$7DJHvL6u//kLUlS7S5dZW.eiaj7jJCZpgl8xeMYV3iQDNow74DQPi', 'user', 'active', NULL, NULL, NULL, NULL, 'live_camera', NULL, NULL, 'pending', NULL, NULL, NULL, '0', 'manual_review', NULL, NULL, NULL, NULL, '2026-05-15 13:15:55', '2026-06-01 20:30:44');
INSERT INTO `users` (`id`, `fullname`, `first_name`, `surname`, `middle_initial`, `phone_number`, `email`, `email_verified_at`, `email_verification_sent_at`, `phone_verified_at`, `verification_method`, `login_otp_enabled`, `chapel_district`, `address`, `birthdate`, `id_number_hash`, `id_number_encrypted`, `password`, `role`, `status`, `profile_picture`, `valid_id_path`, `valid_id_original_name`, `valid_id_mime_type`, `valid_id_capture_method`, `face_image_path`, `face_image_mime_type`, `face_verification_status`, `face_verified_at`, `ocr_extracted_text_encrypted`, `ocr_extracted_data_encrypted`, `ocr_match_score`, `ocr_status`, `ocr_processed_at`, `rejection_reason`, `verified_at`, `verified_by`, `created_at`, `updated_at`) VALUES ('8', 'prince ondoy', NULL, NULL, NULL, '09631237247', 'princeondoy0@gmail.com', '2026-06-01 20:30:44', NULL, NULL, 'email', '0', 'Main Chapel', NULL, NULL, NULL, NULL, '$2y$10$FSUsZSU7lcNgVMD48mom4uKCkc4VOlSXNZMdP6SHmep2Sg1ug7I7a', 'user', 'active', NULL, NULL, NULL, NULL, 'live_camera', NULL, NULL, 'pending', NULL, NULL, NULL, '0', 'manual_review', NULL, NULL, NULL, NULL, '2026-05-15 21:06:15', '2026-06-01 20:30:44');
INSERT INTO `users` (`id`, `fullname`, `first_name`, `surname`, `middle_initial`, `phone_number`, `email`, `email_verified_at`, `email_verification_sent_at`, `phone_verified_at`, `verification_method`, `login_otp_enabled`, `chapel_district`, `address`, `birthdate`, `id_number_hash`, `id_number_encrypted`, `password`, `role`, `status`, `profile_picture`, `valid_id_path`, `valid_id_original_name`, `valid_id_mime_type`, `valid_id_capture_method`, `face_image_path`, `face_image_mime_type`, `face_verification_status`, `face_verified_at`, `ocr_extracted_text_encrypted`, `ocr_extracted_data_encrypted`, `ocr_match_score`, `ocr_status`, `ocr_processed_at`, `rejection_reason`, `verified_at`, `verified_by`, `created_at`, `updated_at`) VALUES ('9', 'qwerty', NULL, NULL, NULL, '09123456789', 'qwerty@gmail.com', '2026-06-01 20:30:44', NULL, NULL, 'email', '0', 'Main Chapel', 'San Mateo, Aleosan, Cotabato', NULL, NULL, NULL, '$2y$10$epek1yVmKtPM0q50q2nbhenz32TsNHCI0bGXAGFOr4qjuuouKirtC', 'user', 'active', NULL, 'uploads/valid_ids/valid-id-20260515184224-10dd9cbe06fc.png', NULL, NULL, 'live_camera', NULL, NULL, 'pending', NULL, NULL, NULL, '0', 'manual_review', NULL, NULL, '2026-05-16 00:45:28', '4', '2026-05-16 00:42:24', '2026-06-01 20:30:44');
INSERT INTO `users` (`id`, `fullname`, `first_name`, `surname`, `middle_initial`, `phone_number`, `email`, `email_verified_at`, `email_verification_sent_at`, `phone_verified_at`, `verification_method`, `login_otp_enabled`, `chapel_district`, `address`, `birthdate`, `id_number_hash`, `id_number_encrypted`, `password`, `role`, `status`, `profile_picture`, `valid_id_path`, `valid_id_original_name`, `valid_id_mime_type`, `valid_id_capture_method`, `face_image_path`, `face_image_mime_type`, `face_verification_status`, `face_verified_at`, `ocr_extracted_text_encrypted`, `ocr_extracted_data_encrypted`, `ocr_match_score`, `ocr_status`, `ocr_processed_at`, `rejection_reason`, `verified_at`, `verified_by`, `created_at`, `updated_at`) VALUES ('10', 'asasa', NULL, NULL, NULL, '09123456789', 'teresa@gmail.com', '2026-06-01 20:30:44', NULL, NULL, 'email', '0', 'District 2', 'sitio taguan', NULL, NULL, NULL, '$2y$10$A8TGEES.QuQzRj9m//czsOQk0AzEeD.MgK5AyurYLa6jlAUewKbVK', 'user', 'active', NULL, 'uploads/valid_ids/valid-id-20260519173834-df2aff028c29.jpg', NULL, NULL, 'live_camera', NULL, NULL, 'pending', NULL, NULL, NULL, '0', 'manual_review', NULL, NULL, '2026-05-19 23:39:13', '4', '2026-05-19 23:38:34', '2026-06-01 20:30:44');
INSERT INTO `users` (`id`, `fullname`, `first_name`, `surname`, `middle_initial`, `phone_number`, `email`, `email_verified_at`, `email_verification_sent_at`, `phone_verified_at`, `verification_method`, `login_otp_enabled`, `chapel_district`, `address`, `birthdate`, `id_number_hash`, `id_number_encrypted`, `password`, `role`, `status`, `profile_picture`, `valid_id_path`, `valid_id_original_name`, `valid_id_mime_type`, `valid_id_capture_method`, `face_image_path`, `face_image_mime_type`, `face_verification_status`, `face_verified_at`, `ocr_extracted_text_encrypted`, `ocr_extracted_data_encrypted`, `ocr_match_score`, `ocr_status`, `ocr_processed_at`, `rejection_reason`, `verified_at`, `verified_by`, `created_at`, `updated_at`) VALUES ('11', 'RYAN NAMBONG', NULL, NULL, NULL, '09234567891', 'ryan@gmail.com', '2026-06-01 20:30:44', NULL, NULL, 'email', '0', 'District 1', 'Sitio Upi, San Mateo, Aleosan', NULL, NULL, NULL, '$2y$10$O8c.uuavXq5yPu.4MU46rOYOjKqxPChsSQzSDCUIB2AbXz0La8vJi', 'user', 'active', NULL, 'uploads/valid_ids/valid-id-20260520024926-f9786addcaa8.jpg', NULL, NULL, 'live_camera', NULL, NULL, 'pending', NULL, NULL, NULL, '0', 'manual_review', NULL, NULL, '2026-05-20 08:50:01', '4', '2026-05-20 08:49:26', '2026-06-01 20:30:44');
INSERT INTO `users` (`id`, `fullname`, `first_name`, `surname`, `middle_initial`, `phone_number`, `email`, `email_verified_at`, `email_verification_sent_at`, `phone_verified_at`, `verification_method`, `login_otp_enabled`, `chapel_district`, `address`, `birthdate`, `id_number_hash`, `id_number_encrypted`, `password`, `role`, `status`, `profile_picture`, `valid_id_path`, `valid_id_original_name`, `valid_id_mime_type`, `valid_id_capture_method`, `face_image_path`, `face_image_mime_type`, `face_verification_status`, `face_verified_at`, `ocr_extracted_text_encrypted`, `ocr_extracted_data_encrypted`, `ocr_match_score`, `ocr_status`, `ocr_processed_at`, `rejection_reason`, `verified_at`, `verified_by`, `created_at`, `updated_at`) VALUES ('12', 'dave esler', NULL, NULL, NULL, '09123456789', 'esler@gmail.com', '2026-06-01 20:30:44', NULL, NULL, 'email', '0', 'District 1', 'San Mateo, Aleosan, Cotabato', '2000-10-11', '5994471abb01112afcc18159f6cc74b4f511b99806da59b3caf5a9c173cacfc5', '0BS9SF+cm/d6qqSeHnDCCLLBgTETHLa7MFxltT47VJk=', '$2y$10$wFA2KT0zob7caVn.WaXZfuNR5DYU6zw5T1X/b0UjLYhZ0eWWkQBNy', 'user', 'active', NULL, 'uploads/valid_ids/live-valid-id-20260526173413-7ee338f1ba09.jpg.enc', 'live-camera-id.jpg', 'image/jpeg', 'live_camera', 'uploads/live_faces/live-face-20260526173413-4ceaa123b3cc.jpg.enc', 'image/jpeg', 'live_camera_verified', '2026-05-26 23:34:13', NULL, 'NhhMa+Nxu8xTfS+OkyEyIK+dYEAS3zoJpNovW4Q7s2VfbNZ5+M7dGRI1M6aWdnt2+hEptzpmWe1J3RLr8peTtOx+7jymF18vxhzfzLKopMr4hRCYWtacXVOA1z7wvAO2rMcM9AttjpCuUYtKcIJrPhZ4eSt+tvpUpIaAqBJF/94Lp1ZCWCLl8Egemmyew1oLwBJi/iv3guHA6oCRZ/pIma8XTaBebzQuy0kVOErXSjo4VOc/vfRjAMCqjTzrwCNS', '0', 'unreadable', '2026-05-26 23:34:13', NULL, '2026-05-28 22:10:24', '4', '2026-05-26 23:34:13', '2026-06-01 20:30:44');
INSERT INTO `users` (`id`, `fullname`, `first_name`, `surname`, `middle_initial`, `phone_number`, `email`, `email_verified_at`, `email_verification_sent_at`, `phone_verified_at`, `verification_method`, `login_otp_enabled`, `chapel_district`, `address`, `birthdate`, `id_number_hash`, `id_number_encrypted`, `password`, `role`, `status`, `profile_picture`, `valid_id_path`, `valid_id_original_name`, `valid_id_mime_type`, `valid_id_capture_method`, `face_image_path`, `face_image_mime_type`, `face_verification_status`, `face_verified_at`, `ocr_extracted_text_encrypted`, `ocr_extracted_data_encrypted`, `ocr_match_score`, `ocr_status`, `ocr_processed_at`, `rejection_reason`, `verified_at`, `verified_by`, `created_at`, `updated_at`) VALUES ('13', 'trey S. ascscs', 'trey', 'ascscs', 'S', '09123456789', 'trey@gmail.com', NULL, NULL, NULL, 'email', '0', 'District 1', 'Sitio Upi, San Mateo, Aleosan', '2004-12-12', '1ea2f89d934cb4a2af0b486736609cf9cb4bdafdc6e946e79aecb02b9d9dceb4', 'SS5WzXesKzxcxphUXcVPtKw2sCwBttPwr4SqzfP8bww=', '$2y$10$vbm4pSP.3SpjqEYw0HY6Pe5mZp9LhcnhizaJC7psg3tzFJRgFOEqu', 'user', 'rejected', NULL, 'uploads/valid_ids/live-valid-id-20260528162314-9aa9805e6ccd.jpg.enc', 'live-camera-id.jpg', 'image/jpeg', 'live_camera', 'uploads/live_faces/live-face-20260528162314-aac877ff82c5.jpg.enc', 'image/jpeg', 'live_camera_verified', '2026-05-28 22:23:14', NULL, 'IfOOGoTR6VCPDU40G/eBjkbTCtfm1xa4Djcb2PP3OVlmLBgoUhQKlyvxncANKrgDp3Ydve3CrXXIhcDexqcbQKWrMvSE9P6f7ULnLVw/4rPPFnvR4Q0OalZ0SVZ+WeQdPxoYrzsw1YCpy3SMQcNFEc330s3l72qSRF6yD1qPJHT76WPUEQ/4VLyyaAC5Ujwif+mMeVeNz6O+Mrf8IHJTyvGva3yl1920F8rn5n+RXvNwlbLLVeOSA2WOTyTDdDpb', '0', 'unreadable', '2026-05-28 22:23:14', 'sayod kaw', '2026-05-28 22:24:34', '4', '2026-05-28 22:23:14', '2026-05-28 22:24:34');
INSERT INTO `users` (`id`, `fullname`, `first_name`, `surname`, `middle_initial`, `phone_number`, `email`, `email_verified_at`, `email_verification_sent_at`, `phone_verified_at`, `verification_method`, `login_otp_enabled`, `chapel_district`, `address`, `birthdate`, `id_number_hash`, `id_number_encrypted`, `password`, `role`, `status`, `profile_picture`, `valid_id_path`, `valid_id_original_name`, `valid_id_mime_type`, `valid_id_capture_method`, `face_image_path`, `face_image_mime_type`, `face_verification_status`, `face_verified_at`, `ocr_extracted_text_encrypted`, `ocr_extracted_data_encrypted`, `ocr_match_score`, `ocr_status`, `ocr_processed_at`, `rejection_reason`, `verified_at`, `verified_by`, `created_at`, `updated_at`) VALUES ('14', 'rey john R. ilisan', 'rey john', 'ilisan', 'R', '097643454545', 'reyjohn@gmail.com', NULL, '2026-06-05 16:49:22', NULL, 'email', '0', 'District 1', 'San Mateo, Aleosan, Cotabato', '2008-06-05', 'f30d5f9230d912d509c865051c3bf502cf16bd916fb8083ae0e482ac0506aef4', '2z4WFha7dx7EeligKaBSti/M9IVqswj0fPz6d1sKHnY=', '$2y$10$zK8ggsjp0NdtMBE3TKqFUufTnsg9KlFRB9Va0nJ/P/tlE3M7tT8Wi', 'user', 'pending_verification', NULL, 'uploads/valid_ids/live-valid-id-20260605104921-67eb0ea6e3ac.jpg.enc', 'live-camera-id.jpg', 'image/jpeg', 'live_camera', 'uploads/live_faces/live-face-20260605104921-c2db746656c1.jpg.enc', 'image/jpeg', 'live_camera_verified', '2026-06-05 16:49:22', NULL, 'T57Y73KFXukUqWc/A+uldavT/X+yfx2RxxT3aAYs7qK9GbFOvu9qHDFXKdsyFmcDjbMYxoHwR0QztiX5NH92kTW7j5jYgS/O4teKL01gNoDIBk58AQdbZwJ/tjrnJ0vgb8KiX9CG+y/0fqbsM9QKQSD24HxBhQsa1bpZIju1Smj8rxVMn1GR7rdo3SfgeHxitrhkek1ejA3h01QQAn/nY3deGvxFWStKZjKXfS6cZXDUI4h9BWuB44gmLGWs8OaP', '0', 'unreadable', '2026-06-05 16:49:22', NULL, NULL, NULL, '2026-06-05 16:49:22', '2026-06-05 16:49:22');
INSERT INTO `users` (`id`, `fullname`, `first_name`, `surname`, `middle_initial`, `phone_number`, `email`, `email_verified_at`, `email_verification_sent_at`, `phone_verified_at`, `verification_method`, `login_otp_enabled`, `chapel_district`, `address`, `birthdate`, `id_number_hash`, `id_number_encrypted`, `password`, `role`, `status`, `profile_picture`, `valid_id_path`, `valid_id_original_name`, `valid_id_mime_type`, `valid_id_capture_method`, `face_image_path`, `face_image_mime_type`, `face_verification_status`, `face_verified_at`, `ocr_extracted_text_encrypted`, `ocr_extracted_data_encrypted`, `ocr_match_score`, `ocr_status`, `ocr_processed_at`, `rejection_reason`, `verified_at`, `verified_by`, `created_at`, `updated_at`) VALUES ('15', 'PARE S. KOY', 'PARE', 'KOY', 'S', '0912345678', 'pare@gmail.com', NULL, '2026-06-06 02:08:00', NULL, 'email', '0', 'District 1', 'San Mateo, Aleosan, Cotabato', '2000-12-12', 'f8058b1c53812e04a48d3168238a82eae442aa7f5eec2ec501c9d46c54fe0b70', 't4CkgtMMi/79M8KgCPUSBwFm6It6rh0WijokufYj4RE=', '$2y$10$m02HBDq420.3XASbUkgw8uwKT5PVhQcRLzePusbRX6lRgfLZregJa', 'user', 'pending_verification', NULL, 'uploads/valid_ids/live-valid-id-20260605200800-7d1ea3bb11d1.jpg.enc', 'live-camera-id.jpg', 'image/jpeg', 'live_camera', 'uploads/live_faces/live-face-20260605200800-9d90a338ea5d.jpg.enc', 'image/jpeg', 'live_camera_verified', '2026-06-06 02:08:00', NULL, 'FgVoORVc+oQ4Q9EbXIYNcDF//ZqcAI4M627o2pZoQsxQj6ZgaibLEI4yFSkJdjYWUUboBrI0nicDB/p431yNtk5WVpQtadJVcMHsXOeUHWHLxHo+/ILv47aOAP9gL8zFbM+Touxv7JQpDtUtWnH6L7j+K5jFw6d8c81x4N6olTeA50MS6lU8B+nuFxFCm7tAnETATzpndTedwvEO2xmarSDb4FpBgBoQCpYtSt/mXJanR6JIPOg+ydOwwwCnLa2C', '0', 'unreadable', '2026-06-06 02:08:00', NULL, NULL, NULL, '2026-06-06 02:08:00', '2026-06-06 02:08:00');

SET FOREIGN_KEY_CHECKS=1;
