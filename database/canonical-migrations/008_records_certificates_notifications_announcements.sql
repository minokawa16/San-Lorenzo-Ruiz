-- Phases 8-10: official records, controlled certificates, typed notifications, announcement lifecycle.

UPDATE baptism_records SET registry_no=NULLIF(TRIM(registry_no),''),book_no=NULLIF(TRIM(book_no),''),page_no=NULLIF(TRIM(page_no),''),entry_no=NULLIF(TRIM(entry_no),'');
ALTER TABLE baptism_records
 ADD COLUMN duplicate_fingerprint CHAR(64) NULL AFTER request_id,
 ADD COLUMN locked_at DATETIME NULL, ADD COLUMN locked_by INT NULL, ADD COLUMN lock_reason VARCHAR(500) NULL,
 ADD COLUMN archived_at DATETIME NULL, ADD COLUMN archived_by INT NULL, ADD COLUMN archive_reason VARCHAR(500) NULL,
 ADD COLUMN restored_at DATETIME NULL, ADD COLUMN restored_by INT NULL,
 ADD UNIQUE KEY uq_baptism_registry_no (registry_no), ADD UNIQUE KEY uq_baptism_book_page_entry (book_no,page_no,entry_no),
 ADD KEY idx_baptism_duplicate (duplicate_fingerprint),
 ADD CONSTRAINT fk_baptism_locked_by FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_baptism_archived_by FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_baptism_restored_by FOREIGN KEY (restored_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE confirmation_records SET registry_no=NULLIF(TRIM(registry_no),'');
ALTER TABLE confirmation_records
 ADD COLUMN duplicate_fingerprint CHAR(64) NULL AFTER request_id,
 ADD COLUMN book_no VARCHAR(40) NULL AFTER registry_no, ADD COLUMN page_no VARCHAR(40) NULL AFTER book_no, ADD COLUMN entry_no VARCHAR(40) NULL AFTER page_no,
 ADD COLUMN locked_at DATETIME NULL, ADD COLUMN locked_by INT NULL, ADD COLUMN lock_reason VARCHAR(500) NULL,
 ADD COLUMN archived_at DATETIME NULL, ADD COLUMN archived_by INT NULL, ADD COLUMN archive_reason VARCHAR(500) NULL,
 ADD COLUMN restored_at DATETIME NULL, ADD COLUMN restored_by INT NULL,
 ADD UNIQUE KEY uq_confirmation_registry_no (registry_no), ADD UNIQUE KEY uq_confirmation_book_page_entry (book_no,page_no,entry_no),
 ADD KEY idx_confirmation_duplicate (duplicate_fingerprint),
 ADD CONSTRAINT fk_confirmation_locked_by FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_confirmation_archived_by FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_confirmation_restored_by FOREIGN KEY (restored_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE first_communion_records SET registry_no=NULLIF(TRIM(registry_no),'');
ALTER TABLE first_communion_records
 ADD COLUMN duplicate_fingerprint CHAR(64) NULL AFTER request_id,
 ADD COLUMN book_no VARCHAR(40) NULL AFTER registry_no, ADD COLUMN page_no VARCHAR(40) NULL AFTER book_no, ADD COLUMN entry_no VARCHAR(40) NULL AFTER page_no,
 ADD COLUMN locked_at DATETIME NULL, ADD COLUMN locked_by INT NULL, ADD COLUMN lock_reason VARCHAR(500) NULL,
 ADD COLUMN archived_at DATETIME NULL, ADD COLUMN archived_by INT NULL, ADD COLUMN archive_reason VARCHAR(500) NULL,
 ADD COLUMN restored_at DATETIME NULL, ADD COLUMN restored_by INT NULL,
 ADD UNIQUE KEY uq_communion_registry_no (registry_no), ADD UNIQUE KEY uq_communion_book_page_entry (book_no,page_no,entry_no),
 ADD KEY idx_communion_duplicate (duplicate_fingerprint),
 ADD CONSTRAINT fk_communion_locked_by FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_communion_archived_by FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_communion_restored_by FOREIGN KEY (restored_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE marriage_records SET registry_no=NULLIF(TRIM(registry_no),'');
ALTER TABLE marriage_records
 ADD COLUMN duplicate_fingerprint CHAR(64) NULL AFTER request_id,
 ADD COLUMN book_no VARCHAR(40) NULL AFTER registry_no, ADD COLUMN page_no VARCHAR(40) NULL AFTER book_no, ADD COLUMN entry_no VARCHAR(40) NULL AFTER page_no,
 ADD COLUMN husband_birth_date DATE NULL AFTER husband_name, ADD COLUMN wife_birth_date DATE NULL AFTER wife_name,
 ADD COLUMN wedding_location VARCHAR(200) NULL AFTER wedding_date,
 ADD COLUMN locked_at DATETIME NULL, ADD COLUMN locked_by INT NULL, ADD COLUMN lock_reason VARCHAR(500) NULL,
 ADD COLUMN archived_at DATETIME NULL, ADD COLUMN archived_by INT NULL, ADD COLUMN archive_reason VARCHAR(500) NULL,
 ADD COLUMN restored_at DATETIME NULL, ADD COLUMN restored_by INT NULL,
 ADD UNIQUE KEY uq_marriage_registry_no (registry_no), ADD UNIQUE KEY uq_marriage_book_page_entry (book_no,page_no,entry_no),
 ADD KEY idx_marriage_duplicate (duplicate_fingerprint),
 ADD CONSTRAINT fk_marriage_locked_by FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_marriage_archived_by FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_marriage_restored_by FOREIGN KEY (restored_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE funeral_records SET registry_no=NULLIF(TRIM(registry_no),'');
ALTER TABLE funeral_records
 ADD COLUMN duplicate_fingerprint CHAR(64) NULL AFTER request_id,
 ADD COLUMN book_no VARCHAR(40) NULL AFTER registry_no, ADD COLUMN page_no VARCHAR(40) NULL AFTER book_no, ADD COLUMN entry_no VARCHAR(40) NULL AFTER page_no,
 ADD COLUMN birth_date DATE NULL AFTER deceased_name,
 ADD COLUMN locked_at DATETIME NULL, ADD COLUMN locked_by INT NULL, ADD COLUMN lock_reason VARCHAR(500) NULL,
 ADD COLUMN archived_at DATETIME NULL, ADD COLUMN archived_by INT NULL, ADD COLUMN archive_reason VARCHAR(500) NULL,
 ADD COLUMN restored_at DATETIME NULL, ADD COLUMN restored_by INT NULL,
 ADD UNIQUE KEY uq_funeral_registry_no (registry_no), ADD UNIQUE KEY uq_funeral_book_page_entry (book_no,page_no,entry_no),
 ADD KEY idx_funeral_duplicate (duplicate_fingerprint),
 ADD CONSTRAINT fk_funeral_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL ON UPDATE CASCADE,
 ADD CONSTRAINT fk_funeral_locked_by FOREIGN KEY (locked_by) REFERENCES users(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_funeral_archived_by FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_funeral_restored_by FOREIGN KEY (restored_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE sacramental_record_corrections (
 correction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, record_type ENUM('baptism','confirmation','communion','marriage','funeral') NOT NULL,
 record_id INT NOT NULL, reason VARCHAR(1000) NOT NULL, status ENUM('pending','approved','rejected','applied','cancelled') NOT NULL DEFAULT 'pending',
 requested_by INT NOT NULL, approved_by INT NULL, edited_by INT NULL, requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 approved_at DATETIME NULL, applied_at DATETIME NULL, rejected_at DATETIME NULL, review_reason VARCHAR(1000) NULL,
 PRIMARY KEY(correction_id), KEY idx_correction_record(record_type,record_id,status), KEY idx_correction_status(status,requested_at),
 CONSTRAINT fk_correction_requester FOREIGN KEY(requested_by) REFERENCES users(id),
 CONSTRAINT fk_correction_approver FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_correction_editor FOREIGN KEY(edited_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE sacramental_correction_changes (
 change_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, correction_id BIGINT UNSIGNED NOT NULL, field_name VARCHAR(80) NOT NULL,
 previous_value TEXT NULL, new_value TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(change_id), UNIQUE KEY uq_correction_field(correction_id,field_name),
 CONSTRAINT fk_correction_change FOREIGN KEY(correction_id) REFERENCES sacramental_record_corrections(correction_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sacramental_import_batches (
 import_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, record_type ENUM('baptism','confirmation','communion','marriage','funeral') NOT NULL,
 original_name VARCHAR(255) NOT NULL, stored_path VARCHAR(500) NOT NULL, file_hash CHAR(64) NOT NULL, status ENUM('preview','confirmed','imported','failed','cancelled') NOT NULL DEFAULT 'preview',
 total_rows INT UNSIGNED NOT NULL DEFAULT 0, valid_rows INT UNSIGNED NOT NULL DEFAULT 0, invalid_rows INT UNSIGNED NOT NULL DEFAULT 0,
 created_by INT NOT NULL, confirmed_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, confirmed_at DATETIME NULL, imported_at DATETIME NULL,
 PRIMARY KEY(import_id), UNIQUE KEY uq_import_hash_user(file_hash,created_by), CONSTRAINT fk_import_creator FOREIGN KEY(created_by) REFERENCES users(id), CONSTRAINT fk_import_confirmer FOREIGN KEY(confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE sacramental_import_rows (
 import_row_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, import_id BIGINT UNSIGNED NOT NULL, `row_number` INT UNSIGNED NOT NULL,
 row_data LONGTEXT NOT NULL, validation_status ENUM('valid','invalid','warning','duplicate') NOT NULL, errors_json LONGTEXT NULL, imported_record_id INT NULL,
 PRIMARY KEY(import_row_id), UNIQUE KEY uq_import_row(import_id,`row_number`), KEY idx_import_row_status(import_id,validation_status),
 CONSTRAINT fk_import_row_batch FOREIGN KEY(import_id) REFERENCES sacramental_import_batches(import_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE certificate_issuances SET status='issued' WHERE status='valid';
ALTER TABLE certificate_issuances
 MODIFY certificate_number VARCHAR(60) NULL, MODIFY verification_code VARCHAR(128) NULL,
 MODIFY status ENUM('draft','review','approval','issued','released','revoked','reissued') NOT NULL DEFAULT 'draft',
 ADD COLUMN request_id INT NULL AFTER certificate_id,
 ADD COLUMN certificate_snapshot LONGTEXT NULL AFTER layout_snapshot,
 ADD COLUMN pdf_path VARCHAR(500) NULL AFTER certificate_snapshot, ADD COLUMN certificate_hash CHAR(64) NULL AFTER pdf_path,
 ADD COLUMN reviewed_by INT NULL, ADD COLUMN reviewed_at DATETIME NULL, ADD COLUMN approved_by INT NULL, ADD COLUMN approved_at DATETIME NULL,
 ADD COLUMN released_by INT NULL, ADD COLUMN released_at DATETIME NULL,
 ADD COLUMN revoked_by INT NULL, ADD COLUMN revoked_at DATETIME NULL, ADD COLUMN revocation_reason VARCHAR(1000) NULL,
 ADD COLUMN original_certificate_id INT NULL, ADD COLUMN reissue_reason VARCHAR(1000) NULL,
 ADD KEY idx_certificate_request(request_id), ADD KEY idx_certificate_lifecycle(status,updated_at), ADD KEY idx_certificate_original(original_certificate_id),
 ADD CONSTRAINT fk_certificate_request FOREIGN KEY(request_id) REFERENCES requests(request_id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_certificate_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_certificate_approver FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_certificate_releaser FOREIGN KEY(released_by) REFERENCES users(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_certificate_revoker FOREIGN KEY(revoked_by) REFERENCES users(id) ON DELETE SET NULL,
 ADD CONSTRAINT fk_certificate_original FOREIGN KEY(original_certificate_id) REFERENCES certificate_issuances(certificate_id) ON DELETE RESTRICT;
UPDATE certificate_issuances SET certificate_snapshot=JSON_OBJECT('legacy',TRUE,'certificate_type',certificate_type,'record_table',record_table,'record_id',record_id,'issued_to',issued_to,'certificate_number',certificate_number) WHERE certificate_snapshot IS NULL;
CREATE TABLE certificate_number_sequences (sequence_year SMALLINT UNSIGNED NOT NULL, prefix VARCHAR(12) NOT NULL, next_number INT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY(sequence_year,prefix)) ENGINE=InnoDB;
CREATE TABLE certificate_events (
 event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, certificate_id INT NOT NULL, request_id INT NULL, actor_user_id INT NULL,
 action ENUM('drafted','previewed','reviewed','approved','issued','released','downloaded','revoked','reissued') NOT NULL,
 reason VARCHAR(1000) NULL, metadata_json LONGTEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(event_id), KEY idx_certificate_events(certificate_id,created_at),
 CONSTRAINT fk_certificate_event_certificate FOREIGN KEY(certificate_id) REFERENCES certificate_issuances(certificate_id) ON DELETE RESTRICT,
 CONSTRAINT fk_certificate_event_request FOREIGN KEY(request_id) REFERENCES requests(request_id) ON DELETE SET NULL,
 CONSTRAINT fk_certificate_event_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE notifications
 ADD COLUMN notification_type VARCHAR(80) NOT NULL DEFAULT 'system' AFTER user_id,
 ADD COLUMN entity_type VARCHAR(60) NULL AFTER message, ADD COLUMN entity_id BIGINT NULL AFTER entity_type,
 ADD COLUMN action_key VARCHAR(80) NULL AFTER entity_id, ADD COLUMN state ENUM('unread','read','archived','deleted') NOT NULL DEFAULT 'unread' AFTER action_key,
 ADD COLUMN deduplication_key CHAR(64) NULL AFTER state, ADD COLUMN read_at DATETIME NULL, ADD COLUMN archived_at DATETIME NULL, ADD COLUMN deleted_at DATETIME NULL,
 ADD UNIQUE KEY uq_notification_dedupe(user_id,deduplication_key), ADD KEY idx_notification_entity(entity_type,entity_id), ADD KEY idx_notification_state(user_id,state,created_at);
UPDATE notifications SET state=IF(is_read=1,'read','unread'),read_at=IF(is_read=1,created_at,NULL);
CREATE TABLE notification_templates (
 notification_type VARCHAR(80) NOT NULL, title_template VARCHAR(200) NOT NULL, in_app_template TEXT NOT NULL, email_subject_template VARCHAR(255) NULL, email_template TEXT NULL, sms_template VARCHAR(500) NULL,
 is_mandatory TINYINT(1) NOT NULL DEFAULT 0, status ENUM('active','inactive') NOT NULL DEFAULT 'active', updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(notification_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO notification_templates(notification_type,title_template,in_app_template,email_subject_template,sms_template) VALUES
('request_submitted','Request submitted','Your request {{request_reference}} was submitted.','Request {{request_reference}} submitted','Request {{request_reference}} submitted.'),
('request_approved','Request approved','Your request {{request_reference}} was approved.','Request {{request_reference}} approved','Request {{request_reference}} approved.'),
('request_rejected','Request update','Your request {{request_reference}} was rejected.','Request {{request_reference}} rejected','Request {{request_reference}} rejected.'),
('reservation_created','Reservation submitted','Your reservation was submitted for review.','Reservation submitted','Reservation submitted.'),
('reservation_approved','Reservation approved','Your reservation is approved for {{reservation_date}}.','Reservation approved','Reservation approved for {{reservation_date}}.'),
('reservation_rescheduled','Reservation rescheduled','Your reservation schedule changed to {{reservation_date}} {{reservation_time}}.','Reservation rescheduled','Reservation moved to {{reservation_date}} {{reservation_time}}.'),
('reservation_cancelled','Reservation cancelled','Your reservation was cancelled.','Reservation cancelled','Reservation cancelled.'),
('reservation_reminder','Reservation reminder','Your reservation is scheduled for {{reservation_date}} {{reservation_time}}.','Reservation reminder','Reservation: {{reservation_date}} {{reservation_time}}.'),
('certificate_ready','Certificate ready','Certificate {{certificate_number}} is ready.','Certificate {{certificate_number}} ready','Certificate {{certificate_number}} is ready.'),
('certificate_released','Certificate released','Certificate {{certificate_number}} was released.','Certificate released','Certificate {{certificate_number}} released.'),
('announcement_published','New parish announcement','{{announcement_title}}','{{announcement_title}}','New announcement: {{announcement_title}}');
CREATE TABLE notification_deliveries (
 delivery_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, notification_id INT NOT NULL, channel ENUM('in_app','email','sms') NOT NULL,
 status ENUM('pending','sent','failed','cancelled') NOT NULL DEFAULT 'pending', attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 sent_at DATETIME NULL, failed_at DATETIME NULL, failure_reason VARCHAR(1000) NULL, provider_reference VARCHAR(255) NULL, last_attempt_at DATETIME NULL,
 PRIMARY KEY(delivery_id), UNIQUE KEY uq_notification_channel(notification_id,channel), KEY idx_delivery_retry(channel,status,last_attempt_at),
 CONSTRAINT fk_delivery_notification FOREIGN KEY(notification_id) REFERENCES notifications(notification_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE announcements
 ADD COLUMN lifecycle_status ENUM('draft','scheduled','published','expired','archived') NOT NULL DEFAULT 'published' AFTER status,
 ADD COLUMN audience_type ENUM('everyone','district','chapel','selected_users') NOT NULL DEFAULT 'everyone' AFTER lifecycle_status,
 ADD COLUMN publish_at DATETIME NULL AFTER scheduled_at, ADD COLUMN expires_at DATETIME NULL AFTER expiry_date,
 ADD COLUMN archived_at DATETIME NULL, ADD COLUMN archived_by INT NULL, ADD COLUMN archive_reason VARCHAR(500) NULL,
 ADD KEY idx_announcement_lifecycle(lifecycle_status,publish_at,expires_at),
 ADD CONSTRAINT fk_announcement_archiver FOREIGN KEY(archived_by) REFERENCES users(id) ON DELETE SET NULL;
UPDATE announcements SET publish_at=COALESCE(scheduled_at,published_date),expires_at=expiry_date,lifecycle_status=CASE WHEN deleted_at IS NOT NULL THEN 'archived' WHEN status='inactive' AND scheduled_at>NOW() THEN 'scheduled' WHEN expiry_date IS NOT NULL AND expiry_date<=NOW() THEN 'expired' WHEN status='active' THEN 'published' ELSE 'draft' END;
CREATE TABLE announcement_audiences (
 audience_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, announcement_id INT NOT NULL, audience_type ENUM('everyone','district','chapel','selected_user') NOT NULL,
 audience_value VARCHAR(255) NULL, user_id INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(audience_id), UNIQUE KEY uq_announcement_audience(announcement_id,audience_type,audience_value,user_id), KEY idx_audience_resolution(audience_type,audience_value),
 CONSTRAINT fk_audience_announcement FOREIGN KEY(announcement_id) REFERENCES announcements(announcement_id) ON DELETE CASCADE,
 CONSTRAINT fk_audience_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO announcement_audiences(announcement_id,audience_type) SELECT announcement_id,'everyone' FROM announcements;
CREATE TABLE announcement_attachments (
 attachment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, announcement_id INT NOT NULL, stored_path VARCHAR(500) NOT NULL, original_name VARCHAR(255) NOT NULL,
 mime_type VARCHAR(120) NOT NULL, file_size INT UNSIGNED NOT NULL, file_hash CHAR(64) NOT NULL, uploaded_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, deleted_at DATETIME NULL,
 PRIMARY KEY(attachment_id), KEY idx_announcement_attachment(announcement_id,deleted_at),
 CONSTRAINT fk_attachment_announcement FOREIGN KEY(announcement_id) REFERENCES announcements(announcement_id) ON DELETE RESTRICT,
 CONSTRAINT fk_attachment_uploader FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO announcement_attachments(announcement_id,stored_path,original_name,mime_type,file_size,file_hash,uploaded_by)
 SELECT announcement_id,attachment_path,COALESCE(attachment_original_name,'attachment'),COALESCE(attachment_mime_type,'application/octet-stream'),COALESCE(attachment_size,0),REPEAT('0',64),published_by FROM announcements WHERE attachment_path IS NOT NULL AND attachment_path<>'';

INSERT IGNORE INTO permissions(permission_key,display_name,category,description) VALUES
('records.correct_locked','Approve locked record corrections','Records','Privileged approval and application of corrections to locked official records'),
('certificates.issue','Issue certificates','Certificates','Approve and issue official certificates'),
('certificates.revoke','Revoke certificates','Certificates','Revoke and reissue official certificates'),
('notifications.retry','Retry notifications','Notifications','Retry failed channel deliveries');
INSERT IGNORE INTO role_permissions(role_id,permission_id)
 SELECT r.role_id,p.permission_id FROM roles r JOIN permissions p ON p.permission_key IN('records.correct_locked','certificates.issue','certificates.revoke','notifications.retry') WHERE r.role_key='administrator';
