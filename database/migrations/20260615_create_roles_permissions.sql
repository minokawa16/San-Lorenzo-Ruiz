-- Migration: role and permission foundation
-- Keeps the existing users.role column compatible while allowing staff/coordinator roles.

ALTER TABLE users
  MODIFY role ENUM('user', 'admin', 'parish_staff', 'records_clerk', 'finance_staff', 'coordinator') DEFAULT 'user';

CREATE TABLE IF NOT EXISTS roles (
  role_key VARCHAR(50) PRIMARY KEY,
  role_name VARCHAR(100) NOT NULL,
  description VARCHAR(255),
  is_system TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS permissions (
  permission_key VARCHAR(80) PRIMARY KEY,
  permission_name VARCHAR(120) NOT NULL,
  module VARCHAR(60) NOT NULL,
  description VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS role_permissions (
  role_key VARCHAR(50) NOT NULL,
  permission_key VARCHAR(80) NOT NULL,
  granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_key, permission_key),
  FOREIGN KEY (role_key) REFERENCES roles(role_key) ON DELETE CASCADE,
  FOREIGN KEY (permission_key) REFERENCES permissions(permission_key) ON DELETE CASCADE
);

INSERT INTO roles (role_key, role_name, description) VALUES
  ('admin', 'Administrator', 'Full system access'),
  ('parish_staff', 'Parish Staff', 'Operational staff access for requests, records, announcements, calendar, and reports'),
  ('records_clerk', 'Records Clerk', 'Sacramental records and certificate preparation access'),
  ('finance_staff', 'Finance Staff', 'Payment verification and request review access'),
  ('coordinator', 'Chapel/District Coordinator', 'District coordination, schedules, announcements, and member support'),
  ('user', 'Registered Member', 'Parishioner self-service access')
ON DUPLICATE KEY UPDATE
  role_name = VALUES(role_name),
  description = VALUES(description);

INSERT INTO permissions (permission_key, permission_name, module, description) VALUES
  ('admin.access', 'Access Admin Area', 'Core', 'Allows access to the administrative interface'),
  ('dashboard.view', 'View Dashboard', 'Core', 'View role dashboard and summaries'),
  ('users.view', 'View Parishioners', 'Users', 'View registered member accounts'),
  ('registrations.verify', 'Verify Registrations', 'Users', 'Approve or reject pending registrations'),
  ('requests.view', 'View Requests', 'Requests', 'View parish service and certificate requests'),
  ('requests.manage', 'Manage Requests', 'Requests', 'Approve, reject, process, or complete requests'),
  ('payments.verify', 'Verify Payments', 'Payments', 'Review and verify submitted payment receipts'),
  ('records.manage', 'Manage Sacramental Records', 'Records', 'Create, update, search, and archive sacramental records'),
  ('certificates.manage', 'Manage Certificates', 'Certificates', 'Generate, issue, upload, and release certificates'),
  ('announcements.manage', 'Manage Announcements', 'Announcements', 'Create, publish, update, and archive announcements'),
  ('calendar.manage', 'Manage Calendar', 'Calendar', 'Create and manage schedule events'),
  ('reservations.view', 'View Reservations', 'Reservations', 'View reservation records'),
  ('reservations.manage', 'Manage Reservations', 'Reservations', 'Approve, reject, or update reservations'),
  ('reports.view', 'View Reports', 'Reports', 'View reports and analytics'),
  ('audit.view', 'View Audit Logs', 'Audit', 'View audit trail and activity logs'),
  ('archives.manage', 'Manage Archives', 'Archives', 'Archive and restore archived records'),
  ('system.settings', 'Manage System Settings', 'Settings', 'Manage backups, recovery, maintenance, and settings'),
  ('ai.use', 'Use AI Assistant', 'AI Assistant', 'Use the system assistant')
ON DUPLICATE KEY UPDATE
  permission_name = VALUES(permission_name),
  module = VALUES(module),
  description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_key, permission_key)
SELECT 'admin', permission_key FROM permissions;

INSERT IGNORE INTO role_permissions (role_key, permission_key) VALUES
  ('parish_staff', 'admin.access'),
  ('parish_staff', 'dashboard.view'),
  ('parish_staff', 'users.view'),
  ('parish_staff', 'registrations.verify'),
  ('parish_staff', 'requests.manage'),
  ('parish_staff', 'records.manage'),
  ('parish_staff', 'certificates.manage'),
  ('parish_staff', 'announcements.manage'),
  ('parish_staff', 'calendar.manage'),
  ('parish_staff', 'reservations.manage'),
  ('parish_staff', 'reports.view'),
  ('parish_staff', 'ai.use'),
  ('records_clerk', 'admin.access'),
  ('records_clerk', 'dashboard.view'),
  ('records_clerk', 'records.manage'),
  ('records_clerk', 'certificates.manage'),
  ('records_clerk', 'requests.view'),
  ('records_clerk', 'reports.view'),
  ('finance_staff', 'admin.access'),
  ('finance_staff', 'dashboard.view'),
  ('finance_staff', 'requests.view'),
  ('finance_staff', 'payments.verify'),
  ('finance_staff', 'reports.view'),
  ('coordinator', 'dashboard.view'),
  ('coordinator', 'reservations.view'),
  ('coordinator', 'ai.use');
