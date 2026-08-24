-- Phase 5: shared request workflow metadata, history, messaging, assignment, and idempotency.
ALTER TABLE requests
  MODIFY status ENUM('draft','submitted','requirements_review','needs_information','payment_required','payment_review','approved','scheduled','processing','ready_for_release','completed','rejected','cancelled','pending') NOT NULL DEFAULT 'submitted',
  ADD COLUMN priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER status,
  ADD COLUMN due_date DATE NULL AFTER priority,
  ADD COLUMN assigned_to INT NULL AFTER due_date,
  ADD COLUMN assigned_at DATETIME NULL AFTER assigned_to,
  ADD COLUMN assigned_by INT NULL AFTER assigned_at,
  ADD KEY idx_requests_assignee (assigned_to, status),
  ADD CONSTRAINT fk_requests_assigned_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT fk_requests_assigned_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE request_status_history (
  history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id INT NOT NULL,
  previous_status VARCHAR(40) NULL,
  new_status VARCHAR(40) NOT NULL,
  actor_user_id INT NULL,
  reason VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (history_id), KEY idx_request_history (request_id, created_at),
  CONSTRAINT fk_request_history_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_request_history_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE request_messages (
  message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id INT NOT NULL,
  sender_user_id INT NOT NULL,
  message TEXT NOT NULL,
  visibility ENUM('public','internal') NOT NULL DEFAULT 'public',
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (message_id), KEY idx_request_messages (request_id, visibility, created_at),
  CONSTRAINT fk_request_messages_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_request_messages_sender FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE request_internal_notes (
  note_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id INT NOT NULL,
  author_user_id INT NOT NULL,
  note TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (note_id), KEY idx_request_notes (request_id, created_at),
  CONSTRAINT fk_request_notes_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_request_notes_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE request_assignments (
  assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id INT NOT NULL,
  assigned_to INT NOT NULL,
  assigned_by INT NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unassigned_at DATETIME NULL,
  PRIMARY KEY (assignment_id), KEY idx_request_assignments (request_id, assigned_at),
  CONSTRAINT fk_request_assignments_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_request_assignments_to FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_request_assignments_by FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE request_idempotency_keys (
  idempotency_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  operation VARCHAR(60) NOT NULL,
  idempotency_key CHAR(64) NOT NULL,
  request_id INT NULL,
  response_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (idempotency_id), UNIQUE KEY uq_request_idempotency (user_id, operation, idempotency_key),
  CONSTRAINT fk_request_idempotency_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_request_idempotency_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO request_status_history (request_id, previous_status, new_status, reason)
SELECT request_id, NULL, CASE WHEN status='pending' THEN 'submitted' ELSE status END, 'legacy status history baseline'
FROM requests;
