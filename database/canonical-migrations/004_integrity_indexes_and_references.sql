-- Phase 4: verified foreign keys, historical delete rules, and workload indexes.
-- Orphan audits were run before this migration; all targeted counts were zero.

ALTER TABLE request_documents
  ADD CONSTRAINT fk_request_documents_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_request_documents_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE request_payments
  ADD CONSTRAINT fk_request_payments_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_request_payments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_request_payments_receipt FOREIGN KEY (receipt_document_id) REFERENCES request_documents(document_id) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_request_payments_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE reservations DROP FOREIGN KEY reservations_ibfk_1,
  ADD CONSTRAINT fk_reservations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE notifications DROP FOREIGN KEY notifications_ibfk_1,
  ADD CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE notification_preferences
  ADD UNIQUE KEY uq_notification_preferences_user_category (user_id, category),
  ADD CONSTRAINT fk_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE announcement_recipients
  ADD UNIQUE KEY uq_announcement_recipient (announcement_id, user_id),
  ADD CONSTRAINT fk_announcement_recipients_announcement FOREIGN KEY (announcement_id) REFERENCES announcements(announcement_id) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_announcement_recipients_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE baptism_records
  ADD CONSTRAINT fk_baptism_records_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE confirmation_records
  ADD CONSTRAINT fk_confirmation_records_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE first_communion_records
  ADD CONSTRAINT fk_first_communion_records_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE marriage_records
  ADD CONSTRAINT fk_marriage_records_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE certificate_issuances
  ADD CONSTRAINT fk_certificate_issuances_template FOREIGN KEY (template_id) REFERENCES certificate_templates(template_id) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_certificate_issuances_issuer FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE;
ALTER TABLE certificate_layouts
  ADD CONSTRAINT fk_certificate_layouts_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_certificate_layouts_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE requests
  ADD KEY idx_requests_user_status_created (user_id, status, date_requested),
  ADD KEY idx_requests_status_updated (status, updated_at);
ALTER TABLE reservations
  ADD KEY idx_reservations_conflict (reservation_type, event_date, event_time, status),
  ADD KEY idx_reservations_user_status (user_id, status, event_date);
ALTER TABLE notifications
  ADD KEY idx_notifications_user_read_created (user_id, is_read, created_at),
  ADD KEY idx_notifications_user_category_created (user_id, title, created_at);
