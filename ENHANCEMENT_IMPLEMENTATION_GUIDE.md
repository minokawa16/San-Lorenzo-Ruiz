# Parish Management System Enhancement Implementation Guide

## Overview

This guide describes the comprehensive enhancements made to the Parish Management System to improve usability, reliability, security, and operational efficiency.

## Features Implemented

### 1. Archive Management System

**Purpose**: Replace hard deletion with soft deletes for data integrity and historical reference.

**Components**:
- **Database Schema**: `deleted_at`, `archived_by`, `archive_reason` columns on core tables
- **ArchiveManager Service** (`includes/ArchiveManager.php`):
  - `archiveRecord()` - Soft delete a record
  - `restoreRecord()` - Restore archived record
  - `getArchivedRecords()` - View archived records
  - `purgeOldArchives()` - Permanently delete old archives after retention period

**Usage**:
```php
include 'includes/ArchiveManager.php';
$archive_mgr = new ArchiveManager($conn, $logger);

// Archive a request
$archive_mgr->archiveRecord('requests', $request_id, $user_id, 'Duplicate request');

// Restore an archived request
$archive_mgr->restoreRecord('requests', $request_id, $user_id);

// Get all archived requests
$archived = $archive_mgr->getArchivedRecords('requests', 50, 0);
```

---

### 2. Activity Timeline Tracking

**Purpose**: Comprehensive audit trail for all system activities with request-level timelines.

**Database Tables**:
- `activity_logs` - Comprehensive audit trail
- `request_activity_timeline` - Quick reference for request-specific timelines
- `system_change_log` - Major system events

**ActivityLogger Service** (`includes/ActivityLogger.php`):
- `logAction()` - Record any system action
- `logRequestAction()` - Log request status changes
- `getEntityTimeline()` - Get timeline for any entity
- `getRequestTimeline()` - Get detailed request timeline
- `getUserActivityLog()` - Get user's activity history
- `generateAuditReport()` - Generate compliance audit reports

**Usage**:
```php
include 'includes/ActivityLogger.php';
$activity_logger = new ActivityLogger($conn);

// Log a request submission
$activity_logger->logRequestAction(
    $request_id,
    $user_id,
    'submitted',
    'New certificate request from parishioner'
);

// Log request approval
$activity_logger->logRequestAction(
    $request_id,
    $admin_id,
    'approved',
    'Request approved by staff'
);

// Get request timeline
$timeline = $activity_logger->getRequestTimeline($request_id);
foreach ($timeline as $event) {
    echo $event['timestamp_recorded'] . ' - ' . $event['action'] . ' by ' . $event['fullname'];
}
```

---

### 3. Backup and Recovery Management

**Purpose**: Automated database and file backups with scheduling, monitoring, and restoration.

**Database Tables**:
- `backup_records` - Backup history and status
- `backup_schedules` - Scheduled backup configurations
- `backup_restore_history` - Restore operation logs
- `backup_health_checks` - Backup integrity verification

**BackupManager Service** (`includes/BackupManager.php`):
- `createFullBackup()` - Full database and file backup
- `createDatabaseBackup()` - Database-only backup
- `getBackupList()` - List all backups
- `verifyBackupIntegrity()` - Verify backup
- `getBackupById()` - Retrieve specific backup

**Features**:
- Automatic ZIP compression
- Database dump or mysqldump
- File backup (uploads, configs, critical files)
- Manifest generation
- Backup metadata tracking
- Compression and verification

**Usage**:
```php
include 'includes/BackupManager.php';
$backup_mgr = new BackupManager($conn, $logger);

// Create full backup
$result = $backup_mgr->createFullBackup($user_id, 'backup_2026-06-15');
if ($result['success']) {
    echo 'Backup created: ' . $result['backup_path'];
}

// Get backup list
$backups = $backup_mgr->getBackupList(50, 0);
```

---

### 4. Automated Email Notification Templates

**Purpose**: Professional, customizable email templates for all request workflow milestones.

**Database Tables**:
- `email_templates` - Master template storage
- `email_template_versions` - Version history for audit
- `email_send_log` - Sent email tracking
- `notification_preferences` - User notification preferences

**EmailTemplateManager Service** (`includes/EmailTemplateManager.php`):
- `getTemplateByKey()` - Retrieve template
- `renderTemplate()` - Render with variable substitution
- `createTemplate()` - Create custom template
- `updateTemplate()` - Update existing template
- `logEmailSend()` - Track email sends
- `seedDefaultTemplates()` - Initialize default templates

**Available Templates**:
- `request_submitted` - Request submission confirmation
- `request_approved` - Approval notification
- `request_rejected` - Rejection with reason
- `payment_received` - Payment confirmation
- `payment_pending` - Payment reminder
- `document_ready` - Document ready for pickup
- `certificate_ready` - Certificate ready for pickup
- `certificate_released` - Certificate released

**Variables**:
Templates support variable substitution using `{{variable_name}}` syntax:
- `{{user_name}}`, `{{request_id}}`, `{{request_type}}`
- `{{submission_date}}`, `{{approval_date}}`, `{{rejection_reason}}`
- `{{amount}}`, `{{payment_date}}`, `{{tracking_link}}`

**Usage**:
```php
include 'includes/EmailTemplateManager.php';
$email_mgr = new EmailTemplateManager($conn, $logger);

// Render template
$rendered = $email_mgr->renderTemplate('request_approved', [
    'user_name' => 'John Smith',
    'request_id' => 'REQ-12345',
    'request_type' => 'Baptism Certificate',
    'approval_date' => date('M d, Y'),
    'next_steps' => 'Please provide proof of payment',
    'tracking_link' => 'http://localhost/ParishSystem/users/requests.php?id=12345'
]);

// Send email
mail($email, $rendered['subject'], $rendered['body']);

// Log the send
$email_mgr->logEmailSend($template_id, $user_id, $email, 'requests', $request_id, $rendered['subject']);
```

---

### 5. Parish Calendar Conflict Detection

**Purpose**: Intelligent scheduling with automatic conflict detection and prevention.

**Database Tables**:
- `calendar_event_conflicts` - Detected conflicts
- `conflict_detection_rules` - Configurable rules
- `reservation_resources` - Resources (venues, priests, rooms)
- `resource_reservations` - Resource bookings

**ConflictDetectionService** (`includes/ConflictDetectionService.php`):
- `checkConflicts()` - Check for scheduling conflicts
- `registerConflict()` - Record detected conflict
- `resolveConflict()` - Mark conflict as resolved
- `getUnresolvedConflicts()` - Get pending conflicts
- `getAvailableTimeSlots()` - Find free time slots
- `reserveResource()` - Book a resource

**Conflict Types**:
- `time_conflict` - Events at same time
- `resource_conflict` - Resource already booked
- `availability_constraint` - Resource unavailable
- `time_window_conflict` - Outside available hours

**Usage**:
```php
include 'includes/ConflictDetectionService.php';
$conflict_mgr = new ConflictDetectionService($conn, $logger);

// Check for conflicts when creating event
$start = new DateTime('2026-06-20 10:00:00');
$end = new DateTime('2026-06-20 12:00:00');
$resources = [
    ['id' => 1, 'type' => 'venue', 'name' => 'Main Church']
];

$check = $conflict_mgr->checkConflicts($event_id, 'wedding', $start, $end, $resources);
if ($check['has_conflicts']) {
    // Show conflicts to user
    foreach ($check['conflicts'] as $conflict) {
        echo $conflict['conflict_type'] . ': ' . $conflict['description'];
    }
}

// Get available time slots
$available = $conflict_mgr->getAvailableTimeSlots($start, $end, 60, $resources);
foreach ($available as $slot) {
    echo $slot['formatted'];
}
```

---

### 6. Role-Based Dashboards

**Purpose**: Customized dashboard experience for each user role with relevant widgets and data.

**Database Tables**:
- `role_configurations` - Role permissions and default widgets
- `dashboard_preferences` - User-specific dashboard settings

**RoleManager Service** (`includes/RoleManager.php`):
- `getRoleConfig()` - Get role configuration
- `getUserDashboardPreferences()` - Get user's dashboard setup
- `setDashboardPreferences()` - Save user preferences
- `hasPermission()` - Check user permissions
- `initializeDefaultRoles()` - Initialize system roles
- `getDefaultWidgetsForRole()` - Get default widgets

**Default Roles**:

| Role | Permissions | Default Widgets |
|------|-------------|-----------------|
| Admin | All permissions | Dashboard overview, Pending requests, User stats, System health, Activities, Announcements, Calendar, Quick actions |
| Staff | Manage requests/records, View reports | Dashboard overview, Pending requests, Assigned tasks, Activities, Calendar, Quick actions |
| Priest | View/approve requests, Post announcements | Dashboard overview, Pending approvals, Activities, Announcements, Calendar |
| Coordinator | View calendar, Coordinate members | Dashboard overview, Assigned members, Calendar, Announcements |
| User | Submit requests, Update profile | My requests, Announcements, Calendar, Notifications |

**Usage**:
```php
include 'includes/RoleManager.php';
$role_mgr = new RoleManager($conn, $logger);

// Get user's dashboard configuration
$preferences = $role_mgr->getUserDashboardPreferences($user_id);

// Check permissions
if ($role_mgr->hasPermission($user_id, 'manage_users')) {
    // Show user management link
}

// Save user's widget preferences
$role_mgr->setDashboardPreferences($user_id, 'staff', [
    'dashboard_overview',
    'pending_requests',
    'calendar_events'
]);
```

---

## Installation Instructions

### 1. Run Database Migrations

Navigate to the migration runner:
```
http://localhost/ParishSystem/database/run-migrations.php
```

This will execute all SQL migration files in order:
- Archive column additions
- Activity logging tables
- Backup and recovery tables
- Email template tables
- Dashboard and conflict detection tables

### 2. Initialize Service Classes

The service classes can be included and used anywhere in your application:

```php
include 'database/config.php';
include 'includes/Logger.php';
include 'includes/ArchiveManager.php';
include 'includes/ActivityLogger.php';
include 'includes/BackupManager.php';
include 'includes/EmailTemplateManager.php';
include 'includes/ConflictDetectionService.php';
include 'includes/RoleManager.php';

// Initialize services
$logger = new Logger();
$archive_mgr = new ArchiveManager($conn, $logger);
$activity_logger = new ActivityLogger($conn);
$backup_mgr = new BackupManager($conn, $logger);
$email_mgr = new EmailTemplateManager($conn, $logger);
$conflict_mgr = new ConflictDetectionService($conn, $logger);
$role_mgr = new RoleManager($conn, $logger);
```

### 3. Initialize Default Data

```php
// Initialize default roles
$role_mgr->initializeDefaultRoles();

// Seed default email templates
$email_mgr->seedDefaultTemplates();
```

---

## Integration Examples

### Example 1: Processing Request Submission

```php
// User submits a new request
$request_id = createRequest($user_id, 'Baptism Certificate', $description);

// Log the action
$activity_logger->logRequestAction($request_id, $user_id, 'submitted', 'New request created');

// Send confirmation email
$rendered = $email_mgr->renderTemplate('request_submitted', [
    'user_name' => $user_fullname,
    'request_id' => $request_id,
    'request_type' => 'Baptism Certificate',
    'submission_date' => date('M d, Y'),
    'tracking_link' => BASE_URL . "users/requests.php?id=$request_id"
]);

mail($user_email, $rendered['subject'], $rendered['body']);
$email_mgr->logEmailSend($template_id, $user_id, $user_email, 'requests', $request_id, $rendered['subject']);
```

### Example 2: Approving Request with Timeline

```php
// Admin approves request
updateRequestStatus($request_id, 'approved');

// Log approval action
$activity_logger->logRequestAction(
    $request_id,
    $admin_id,
    'approved',
    'Request approved by ' . $admin_name
);

// Send approval email
$rendered = $email_mgr->renderTemplate('request_approved', [
    'user_name' => $user_fullname,
    'request_id' => $request_id,
    'approval_date' => date('M d, Y'),
    'next_steps' => 'Submit payment of $50 to complete the process',
    'tracking_link' => BASE_URL . "users/requests.php?id=$request_id"
]);

mail($user_email, $rendered['subject'], $rendered['body']);

// Show timeline
$timeline = $activity_logger->getRequestTimeline($request_id);
?>
<div class="timeline">
    <?php foreach ($timeline as $event): ?>
        <div class="timeline-item">
            <time><?php echo $event['timestamp_recorded']; ?></time>
            <div class="timeline-action"><?php echo ucfirst($event['action']); ?></div>
            <div class="timeline-user">By: <?php echo $event['fullname']; ?></div>
            <div class="timeline-description"><?php echo $event['description']; ?></div>
        </div>
    <?php endforeach; ?>
</div>
```

### Example 3: Creating a Calendar Event with Conflict Check

```php
// Create event dates
$event_start = new DateTime('2026-07-15 10:00:00');
$event_end = new DateTime('2026-07-15 12:00:00');

// Define resources needed
$resources = [
    ['id' => 1, 'type' => 'venue', 'name' => 'Main Church'],
    ['id' => 2, 'type' => 'priest', 'name' => 'Fr. John']
];

// Check for conflicts
$check = $conflict_mgr->checkConflicts(
    0,
    'wedding',
    $event_start,
    $event_end,
    $resources
);

if ($check['has_conflicts']) {
    // Show conflicts and suggest alternatives
    echo '<div class="alert alert-warning">';
    echo '<h4>Scheduling Conflicts Detected</h4>';
    foreach ($check['conflicts'] as $conflict) {
        echo '<p>' . $conflict['type'] . ': ' . $conflict['message'] . '</p>';
    }
    echo '</div>';

    // Get available slots
    $available = $conflict_mgr->getAvailableTimeSlots($event_start, $event_end, 120, $resources);
    echo '<h5>Available Time Slots:</h5>';
    foreach ($available as $slot) {
        echo '<button value="' . $slot['start'] . '">' . $slot['formatted'] . '</button>';
    }
} else {
    // Create event
    $event_id = createScheduleEvent($user_id, 'Wedding', $event_start, $event_end);
    
    // Reserve resources
    foreach ($resources as $resource) {
        $conflict_mgr->reserveResource($event_id, $resource['id'], $user_id);
    }
    
    // Log the action
    $activity_logger->logAction($user_id, 'schedule_events', $event_id, 'created', 'event');
}
```

### Example 4: Creating and Scheduling Backups

```php
// Manual backup
$backup_result = $backup_mgr->createFullBackup($user_id, 'manual_backup_' . date('Y-m-d'));
if ($backup_result['success']) {
    echo 'Backup created: ' . $backup_result['backup_path'];
}

// View backup history
$backups = $backup_mgr->getBackupList(20, 0);
?>
<table>
    <thead>
        <tr>
            <th>Backup Name</th>
            <th>Type</th>
            <th>Status</th>
            <th>Size</th>
            <th>Created</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($backups as $backup): ?>
            <tr>
                <td><?php echo $backup['backup_name']; ?></td>
                <td><?php echo $backup['backup_type']; ?></td>
                <td><?php echo $backup['backup_status']; ?></td>
                <td><?php echo formatBytes($backup['backup_size']); ?></td>
                <td><?php echo $backup['initiated_at']; ?></td>
                <td>
                    <a href="verify_backup.php?id=<?php echo $backup['backup_id']; ?>">Verify</a>
                    <a href="download_backup.php?id=<?php echo $backup['backup_id']; ?>">Download</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
```

---

## Admin Interface Pages (To Be Created)

The following admin pages should be created to manage these features:

### Archive Management
- `/admin/manage-archives.php` - View and restore archived records
- Filters by entity type, archive date, reason

### Activity Timeline
- `/admin/audit-logs.php` - Extended with timeline view
- Generate audit reports
- Filter by action, user, date range

### Backup Management
- `/admin/backups.php` - Create, monitor, restore backups
- Schedule backups
- Verify integrity
- Download backups

### Email Templates
- `/admin/email-templates.php` - Create, edit, test templates
- Version history
- Email send log

### Calendar & Conflicts
- `/admin/calendar-conflicts.php` - View and resolve conflicts
- Resource management
- Event scheduling UI

### Role & Dashboard
- `/admin/roles.php` - Manage roles and permissions
- `/admin/dashboard-settings.php` - Configure dashboard widgets per role

---

## Security Considerations

1. **Archive Retention**: Implement automatic purging of archived records after 90 days
2. **Backup Encryption**: Enable encryption for sensitive backups
3. **Email Template Injection**: All variables are sanitized with `htmlspecialchars()`
4. **Conflict Resolution**: Only authorized users can resolve conflicts
5. **Activity Logging**: All system changes are tracked for compliance

---

## Performance Optimization

1. **Indexes**: All tables have appropriate indexes on frequently queried columns
2. **Pagination**: Use limit/offset for large result sets
3. **Backup Compression**: Enable ZIP compression for backups
4. **Archive Purging**: Schedule regular cleanup of old archived records
5. **Email Queue**: Consider implementing email queue for bulk sends

---

## Troubleshooting

### Migrations Not Running
- Check file permissions on `/database/migrations/`
- Verify MySQL user has ALTER TABLE privileges
- Check error logs

### Backup Failures
- Ensure `/backups/` directory is writable
- Check available disk space
- Verify MySQL credentials

### Email Templates Not Rendering
- Check for syntax errors in templates
- Verify variable names match template_variables
- Check email_send_log for failures

### Conflict Detection Issues
- Verify schedule_events table exists
- Check resource_reservations table
- Ensure event times are in correct format

---

## Next Steps

1. Create admin interface pages for managing archives, backups, templates
2. Implement role-based dashboard pages
3. Add comprehensive testing suite (PHPUnit)
4. Create user documentation
5. Deploy to production with data migration

---

**Last Updated**: 2026-06-15
**Version**: 1.0
**Status**: Phase 1 Complete, Phase 2 In Progress
