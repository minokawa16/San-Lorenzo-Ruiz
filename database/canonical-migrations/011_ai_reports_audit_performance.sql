-- Phase 11-15: governed AI knowledge, auditable operations, reporting indexes.

ALTER TABLE chatbot_knowledge
  ADD COLUMN approval_status ENUM('draft','approved','rejected','superseded') NOT NULL DEFAULT 'draft' AFTER status,
  ADD COLUMN author_id INT NULL AFTER approval_status,
  ADD COLUMN reviewer_id INT NULL AFTER author_id,
  ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER reviewer_id,
  ADD COLUMN effective_date DATE NULL AFTER version,
  ADD COLUMN expiry_date DATE NULL AFTER effective_date,
  ADD COLUMN language ENUM('en','fil','bilingual') NOT NULL DEFAULT 'bilingual' AFTER expiry_date,
  ADD COLUMN reviewed_at DATETIME NULL AFTER language,
  ADD COLUMN content_hash CHAR(64) NULL AFTER reviewed_at,
  ADD KEY idx_knowledge_current (approval_status, effective_date, expiry_date, language),
  ADD CONSTRAINT fk_knowledge_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_knowledge_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL;

UPDATE chatbot_knowledge
SET approval_status = CASE WHEN status = 'active' THEN 'approved' ELSE 'draft' END,
    source = COALESCE(NULLIF(TRIM(source), ''), 'TUGON parish knowledge base'),
    effective_date = COALESCE(effective_date, DATE(created_at)),
    content_hash = SHA2(CONCAT_WS('|', topic, COALESCE(keywords, ''), answer, COALESCE(steps, '')), 256),
    reviewed_at = CASE WHEN status = 'active' THEN updated_at ELSE NULL END;

CREATE TABLE ai_responses (
  response_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  response_reference CHAR(36) NOT NULL,
  user_id INT NOT NULL,
  audience ENUM('parishioner','staff') NOT NULL,
  mode ENUM('chat','search','analytics') NOT NULL DEFAULT 'chat',
  language ENUM('en','fil') NOT NULL DEFAULT 'en',
  question_redacted VARCHAR(1000) NOT NULL,
  answer_redacted TEXT NOT NULL,
  source_snapshot JSON NULL,
  provider VARCHAR(40) NOT NULL DEFAULT 'grounded',
  correlation_id CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (response_id),
  UNIQUE KEY uq_ai_response_reference (response_reference),
  KEY idx_ai_response_user_created (user_id, created_at),
  KEY idx_ai_response_correlation (correlation_id),
  CONSTRAINT fk_ai_response_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_feedback (
  feedback_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  response_id BIGINT UNSIGNED NOT NULL,
  rating ENUM('correct','incorrect','needs_review') NOT NULL,
  comments VARCHAR(1000) NULL,
  reviewer_user_id INT NOT NULL,
  knowledge_source_snapshot JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (feedback_id),
  UNIQUE KEY uq_ai_feedback_reviewer (response_id, reviewer_user_id),
  KEY idx_ai_feedback_rating_created (rating, created_at),
  CONSTRAINT fk_ai_feedback_response FOREIGN KEY (response_id) REFERENCES ai_responses(response_id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_feedback_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE chatbot_inquiries
  ADD COLUMN correlation_id CHAR(36) NULL AFTER context_limited,
  ADD COLUMN response_reference CHAR(36) NULL AFTER correlation_id,
  ADD KEY idx_chatbot_inquiries_correlation (correlation_id),
  ADD KEY idx_chatbot_inquiries_response (response_reference);

ALTER TABLE audit_log
  ADD COLUMN correlation_id CHAR(36) NULL AFTER ip_address,
  ADD COLUMN component VARCHAR(80) NOT NULL DEFAULT 'application' AFTER correlation_id,
  ADD COLUMN event VARCHAR(120) NULL AFTER component,
  ADD COLUMN user_agent_hash CHAR(64) NULL AFTER event,
  ADD KEY idx_audit_pagination (created_at, log_id),
  ADD KEY idx_audit_correlation (correlation_id),
  ADD KEY idx_audit_component_event (component, event);

CREATE TABLE reservation_conflict_events (
  conflict_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempted_by INT NULL,
  reservation_id INT NULL,
  resource_id INT UNSIGNED NULL,
  requested_start DATETIME NOT NULL,
  requested_end DATETIME NOT NULL,
  reason VARCHAR(500) NOT NULL,
  correlation_id CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (conflict_id),
  KEY idx_conflict_reporting (created_at, resource_id),
  CONSTRAINT fk_conflict_actor FOREIGN KEY (attempted_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_conflict_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE SET NULL,
  CONSTRAINT fk_conflict_resource FOREIGN KEY (resource_id) REFERENCES resources(resource_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE notification_deliveries
  ADD COLUMN idempotency_key CHAR(64) NULL AFTER channel,
  ADD COLUMN next_attempt_at DATETIME NULL AFTER last_attempt_at,
  ADD UNIQUE KEY uq_notification_idempotency (idempotency_key),
  ADD KEY idx_delivery_due (status, next_attempt_at);

UPDATE notification_deliveries nd
JOIN notifications n ON n.notification_id = nd.notification_id
SET nd.idempotency_key = SHA2(CONCAT(nd.notification_id, '|', nd.channel, '|', n.user_id, '|1'), 256)
WHERE nd.idempotency_key IS NULL;

INSERT INTO permissions (permission_key, display_name, category, description) VALUES
('ai.parishioner.use', 'Use Parishioner AI', 'ai', 'Use AI within the authenticated parishioner data scope'),
('ai.staff.use', 'Use Staff AI', 'ai', 'Use staff-facing AI guidance'),
('ai.admin.use', 'Use Administrator AI', 'ai', 'Use administrator AI analytics and broad authorized search'),
('ai.search.records', 'AI Record Search', 'ai', 'Search records through permission-scoped AI'),
('ai.search.reports', 'AI Report Search', 'ai', 'Read authorized aggregate reports through AI'),
('ai.manage.knowledge', 'Manage AI Knowledge', 'ai', 'Manage and approve authoritative AI knowledge'),
('ai.review.feedback', 'Review AI Feedback', 'ai', 'Submit and review AI response feedback'),
('audit.export', 'Export Audit Logs', 'audit', 'Export filtered and redacted audit records'),
('reports.export', 'Export Reports', 'reports', 'Export authorized filtered reports')
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), category=VALUES(category), description=VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id FROM roles r JOIN permissions p
WHERE (r.role_key = 'parishioner' AND p.permission_key = 'ai.parishioner.use')
   OR (r.role_key = 'administrator' AND p.permission_key IN ('ai.staff.use','ai.admin.use','ai.search.records','ai.search.reports','ai.manage.knowledge','ai.review.feedback','audit.export','reports.export'))
   OR (r.role_key = 'records_clerk' AND p.permission_key IN ('ai.staff.use','ai.search.records'))
   OR (r.role_key = 'finance_staff' AND p.permission_key IN ('ai.staff.use','ai.search.reports'))
   OR (r.role_key = 'parish_staff' AND p.permission_key IN ('ai.staff.use','ai.search.records'));

ALTER TABLE requests
  ADD KEY idx_requests_reporting (date_requested, status, request_type, due_date),
  ADD KEY idx_requests_due_state (due_date, status);
ALTER TABLE request_status_history
  ADD KEY idx_request_history_state_date (new_status, created_at, request_id);
ALTER TABLE reservations
  ADD KEY idx_reservation_reporting (event_date, status, reservation_type);
ALTER TABLE certificate_issuances
  ADD KEY idx_certificate_reporting (updated_at, status, certificate_type);
ALTER TABLE notification_deliveries
  ADD KEY idx_notification_reporting (last_attempt_at, status, channel);
