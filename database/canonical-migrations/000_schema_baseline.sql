
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `announcement_recipients` (
  `recipient_id` int(11) NOT NULL AUTO_INCREMENT,
  `announcement_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `delivery_status` varchar(30) DEFAULT 'pending',
  `sms_delivery_status` varchar(30) DEFAULT 'skipped',
  `sent_at` datetime DEFAULT NULL,
  `sms_sent_at` datetime DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`recipient_id`),
  UNIQUE KEY `uniq_announcement_user` (`announcement_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `parish_priest` varchar(120) DEFAULT NULL,
  `parish_secretary` varchar(120) DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`baptism_id`),
  KEY `fullname` (`fullname`),
  KEY `baptism_date` (`baptism_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `certificate_file_templates` (
  `template_id` int(11) NOT NULL AUTO_INCREMENT,
  `template_name` varchar(150) NOT NULL,
  `certificate_type` varchar(80) NOT NULL,
  `file_original_name` varchar(255) NOT NULL,
  `file_stored_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `mime_type` varchar(120) NOT NULL,
  `file_size` int(11) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `version` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(30) NOT NULL DEFAULT 'available',
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`template_id`),
  KEY `idx_cert_template_type` (`certificate_type`),
  KEY `idx_cert_template_active` (`certificate_type`,`is_active`),
  KEY `idx_cert_template_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `certificate_issuances` (
  `certificate_id` int(11) NOT NULL AUTO_INCREMENT,
  `certificate_type` varchar(50) NOT NULL,
  `record_table` varchar(80) NOT NULL,
  `record_id` int(11) NOT NULL,
  `template_id` int(11) DEFAULT NULL,
  `layout_snapshot` longtext DEFAULT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `certificate_layouts` (
  `layout_id` int(11) NOT NULL AUTO_INCREMENT,
  `certificate_type` varchar(80) NOT NULL,
  `layout_name` varchar(150) NOT NULL,
  `layout_settings` longtext NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`layout_id`),
  UNIQUE KEY `certificate_type` (`certificate_type`),
  KEY `idx_certificate_layout_type` (`certificate_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chatbot_knowledge` (
  `knowledge_id` int(11) NOT NULL AUTO_INCREMENT,
  `topic` varchar(120) NOT NULL,
  `keywords` text DEFAULT NULL,
  `answer` text NOT NULL,
  `steps` text DEFAULT NULL,
  `category` varchar(80) DEFAULT 'general',
  `source` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`knowledge_id`),
  KEY `idx_chatbot_knowledge_status` (`status`),
  KEY `idx_chatbot_knowledge_category` (`category`),
  FULLTEXT KEY `ft_chatbot_knowledge` (`topic`,`keywords`,`answer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chatbot_knowledge_meta` (
  `meta_key` varchar(80) NOT NULL,
  `meta_value` varchar(160) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `parish_priest` varchar(120) DEFAULT NULL,
  `parish_secretary` varchar(120) DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`confirmation_id`),
  KEY `fullname` (`fullname`),
  KEY `confirmation_date` (`confirmation_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_logs` (
  `maintenance_id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `maintenance_type` varchar(80) NOT NULL,
  `status` varchar(40) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`maintenance_id`),
  KEY `idx_maintenance_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
  `parish_priest` varchar(120) DEFAULT NULL,
  `parish_secretary` varchar(120) DEFAULT NULL,
  `status` enum('active','archived') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`marriage_id`),
  KEY `husband_name` (`husband_name`),
  KEY `wife_name` (`wife_name`),
  KEY `wedding_date` (`wedding_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_preferences` (
  `preference_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `category` varchar(80) NOT NULL,
  `email_enabled` tinyint(1) DEFAULT 1,
  `sms_enabled` tinyint(1) DEFAULT 1,
  `in_app_enabled` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`preference_id`),
  UNIQUE KEY `uniq_user_category` (`user_id`,`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `request_documents` (
  `document_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `document_type` varchar(60) DEFAULT 'requirement',
  `requirement_name` varchar(160) DEFAULT NULL,
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
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `request_payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `receipt_document_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(60) NOT NULL,
  `reference_number` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','verified','rejected') DEFAULT 'pending',
  `admin_remarks` text DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `receipt_sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`payment_id`),
  KEY `idx_request_payments_request` (`request_id`),
  KEY `idx_request_payments_user` (`user_id`),
  KEY `idx_request_payments_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `setting_key` varchar(120) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fullname` varchar(255) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `surname` varchar(100) DEFAULT NULL,
  `middle_initial` varchar(5) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `email_verification_sent_at` timestamp NULL DEFAULT NULL,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `verification_method` enum('email','mobile') DEFAULT 'email',
  `login_otp_enabled` tinyint(1) DEFAULT 0,
  `chapel_district` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `birth_place` varchar(150) DEFAULT NULL,
  `sex` varchar(20) DEFAULT NULL,
  `nationality` varchar(80) DEFAULT NULL,
  `id_number_hash` char(64) DEFAULT NULL,
  `id_number_encrypted` text DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('user','admin') DEFAULT 'user',
  `status` enum('active','inactive','pending_verification','rejected','archived') DEFAULT 'active',
  `profile_picture` varchar(255) DEFAULT NULL,
  `valid_id_path` varchar(255) DEFAULT NULL,
  `valid_id_original_name` varchar(255) DEFAULT NULL,
  `valid_id_mime_type` varchar(100) DEFAULT NULL,
  `valid_id_back_path` varchar(255) DEFAULT NULL,
  `valid_id_back_mime_type` varchar(100) DEFAULT NULL,
  `valid_id_capture_method` varchar(40) DEFAULT 'live_camera',
  `face_image_path` varchar(255) DEFAULT NULL,
  `face_image_mime_type` varchar(100) DEFAULT NULL,
  `face_verification_status` varchar(40) DEFAULT 'pending',
  `face_verified_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_id_number_hash` (`id_number_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

