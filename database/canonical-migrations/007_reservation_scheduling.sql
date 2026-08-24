-- Phase 7: centralized resources, occupancy windows, proposals, history, and reminders.
CREATE TABLE resources (
  resource_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  resource_type ENUM('area','chapel','hall','priest','staff','equipment') NOT NULL,
  name VARCHAR(150) NOT NULL,
  description VARCHAR(500) NULL,
  location VARCHAR(200) NULL,
  capacity INT UNSIGNED NULL,
  status ENUM('available','unavailable','maintenance','archived') NOT NULL DEFAULT 'available',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (resource_id), UNIQUE KEY uq_resource_name_type (resource_type,name),
  KEY idx_resources_status_type (status,resource_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO resources (resource_type,name,description,location,capacity) VALUES
 ('area','Main Church','Primary worship and sacramental area','Parish grounds',500),
 ('chapel','Parish Chapel','Chapel resource','Parish grounds',120),
 ('hall','Parish Hall','General parish function hall','Parish grounds',200),
 ('equipment','Sound System','Shared parish sound system','Equipment storage',NULL);

ALTER TABLE reservations
  DROP KEY unique_reservation,
  ADD COLUMN request_id INT NULL AFTER reservation_id,
  ADD COLUMN start_at DATETIME NULL AFTER event_time,
  ADD COLUMN end_at DATETIME NULL AFTER start_at,
  ADD COLUMN service_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60 AFTER end_at,
  ADD COLUMN setup_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER service_duration_minutes,
  ADD COLUMN cleanup_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER setup_duration_minutes,
  ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Manila' AFTER cleanup_duration_minutes,
  ADD UNIQUE KEY uq_reservations_request (request_id),
  ADD KEY idx_reservations_window_status (start_at,end_at,status),
  ADD CONSTRAINT fk_reservations_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE RESTRICT ON UPDATE CASCADE;

UPDATE reservations SET
 start_at=TIMESTAMP(event_date,COALESCE(event_time,'08:00:00')),
 end_at=DATE_ADD(TIMESTAMP(event_date,COALESCE(event_time,'08:00:00')),INTERVAL service_duration_minutes MINUTE);

INSERT INTO requests (user_id,request_type,description,status,reference_number)
SELECT user_id,'reservation',CONCAT('Legacy reservation: ',reservation_type),
 CASE status WHEN 'approved' THEN 'approved' WHEN 'rejected' THEN 'rejected' WHEN 'cancelled' THEN 'cancelled' ELSE 'submitted' END,
 CONCAT('RES-',LPAD(reservation_id,8,'0'))
FROM reservations WHERE request_id IS NULL;

UPDATE reservations r JOIN requests q ON q.reference_number=CONCAT('RES-',LPAD(r.reservation_id,8,'0'))
SET r.request_id=q.request_id WHERE r.request_id IS NULL;

CREATE TABLE reservation_resources (
  reservation_id INT NOT NULL,
  resource_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (reservation_id,resource_id), KEY idx_reservation_resources_resource (resource_id,reservation_id),
  CONSTRAINT fk_reservation_resources_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_reservation_resources_resource FOREIGN KEY (resource_id) REFERENCES resources(resource_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO reservation_resources (reservation_id,resource_id)
SELECT r.reservation_id,x.resource_id FROM reservations r JOIN resources x ON x.name='Main Church';

CREATE TABLE resource_unavailability (
  unavailability_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  resource_id INT UNSIGNED NOT NULL,
  start_at DATETIME NOT NULL,
  end_at DATETIME NOT NULL,
  reason VARCHAR(500) NOT NULL,
  recurrence_rule VARCHAR(120) NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (unavailability_id), KEY idx_resource_blackout (resource_id,start_at,end_at),
  CONSTRAINT fk_resource_blackout_resource FOREIGN KEY (resource_id) REFERENCES resources(resource_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_resource_blackout_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reservation_schedule_history (
  history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_id INT NOT NULL,
  previous_start_at DATETIME NULL, previous_end_at DATETIME NULL,
  new_start_at DATETIME NULL, new_end_at DATETIME NULL,
  changed_by INT NULL,
  change_reason VARCHAR(1000) NOT NULL,
  change_type ENUM('initial_schedule','admin_proposal','parishioner_acceptance','administrator_reschedule','system_adjustment','cancellation') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (history_id), KEY idx_reservation_schedule_history (reservation_id,created_at),
  CONSTRAINT fk_reservation_history_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_reservation_history_actor FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO reservation_schedule_history (reservation_id,new_start_at,new_end_at,change_reason,change_type)
SELECT reservation_id,start_at,end_at,'Legacy schedule baseline','initial_schedule' FROM reservations;

CREATE TABLE schedule_proposals (
  proposal_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_id INT NOT NULL,
  proposed_start_at DATETIME NOT NULL, proposed_end_at DATETIME NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  proposed_by INT NOT NULL,
  status ENUM('pending','accepted','rejected','expired') NOT NULL DEFAULT 'pending',
  expires_at DATETIME NULL, responded_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (proposal_id), KEY idx_schedule_proposal_owner (reservation_id,status,created_at),
  CONSTRAINT fk_schedule_proposal_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_schedule_proposal_actor FOREIGN KEY (proposed_by) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schedule_proposal_resources (
  proposal_id BIGINT UNSIGNED NOT NULL, resource_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (proposal_id,resource_id),
  CONSTRAINT fk_proposal_resources_proposal FOREIGN KEY (proposal_id) REFERENCES schedule_proposals(proposal_id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_proposal_resources_resource FOREIGN KEY (resource_id) REFERENCES resources(resource_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reservation_notifications (
  notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_id INT NOT NULL,
  notification_type VARCHAR(50) NOT NULL,
  scheduled_for DATETIME NOT NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notification_id), UNIQUE KEY uq_reservation_notification (reservation_id,notification_type,scheduled_for),
  KEY idx_reservation_notifications_due (sent_at,scheduled_for),
  CONSTRAINT fk_reservation_notifications_reservation FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE schedule_events ADD UNIQUE KEY uq_schedule_source (source_type,source_id);
