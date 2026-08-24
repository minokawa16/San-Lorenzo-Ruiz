INSERT INTO notification_templates(notification_type,title_template,in_app_template,email_subject_template,sms_template) VALUES
('reservation_rejected','Reservation rejected','Your reservation was rejected.','Reservation rejected','Reservation rejected.'),
('schedule_proposal_created','New schedule proposal','The parish office proposed a new reservation schedule. Please review it.','New schedule proposal','A new reservation schedule was proposed.'),
('schedule_proposal_response','Schedule proposal response','The schedule proposal was {{proposal_response}}.','Schedule proposal response','Schedule proposal {{proposal_response}}.')
ON DUPLICATE KEY UPDATE title_template=VALUES(title_template),in_app_template=VALUES(in_app_template),email_subject_template=VALUES(email_subject_template),sms_template=VALUES(sms_template);
