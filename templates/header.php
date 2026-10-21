<?php
/**
 * Header Template
 * Navigation and session management
 */

// Safe session initialization - prevents duplicate session_start() warnings
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    include __DIR__ . '/../database/config.php';
}
if (!function_exists('isLoggedIn')) {
    include __DIR__ . '/../includes/helpers.php';
}
if (!function_exists('pdsButton')) {
    include __DIR__ . '/../includes/ui-components.php';
}
if (!class_exists('NotificationService')) {
    include_once __DIR__ . '/../services/NotificationService.php';
}

$current_page = basename($_SERVER['PHP_SELF']);
$style_version = file_exists(__DIR__ . '/../assets/css/style.css')
    ? filemtime(__DIR__ . '/../assets/css/style.css')
    : time();
$design_system_version = file_exists(__DIR__ . '/../assets/css/parish-design-system.css')
    ? filemtime(__DIR__ . '/../assets/css/parish-design-system.css')
    : time();
$premium_style_version = file_exists(__DIR__ . '/../assets/css/premium-parish.css')
    ? filemtime(__DIR__ . '/../assets/css/premium-parish.css')
    : time();
$theme_style_version = file_exists(__DIR__ . '/../assets/css/theme.css')
    ? filemtime(__DIR__ . '/../assets/css/theme.css')
    : time();
$mobile_design_style_version = file_exists(__DIR__ . '/../assets/css/mobile-design-system.css')
    ? filemtime(__DIR__ . '/../assets/css/mobile-design-system.css')
    : time();
$is_user_area = isLoggedIn() && !isAdmin() && (
    strpos($_SERVER['PHP_SELF'], '/users/') !== false ||
    in_array($current_page, ['profile.php', 'change-password.php'], true)
);
$user_roles_list = (isLoggedIn() && function_exists('userRoleKeys')) ? userRoleKeys($_SESSION['user_id'] ?? 0, $conn) : [];
$is_admin_area = isLoggedIn() && (isAdmin() || (function_exists('hasPermission') && hasPermission('admin.access')) || in_array('secretary', $user_roles_list, true)) && (
    strpos($_SERVER['PHP_SELF'], '/admin/') !== false ||
    in_array($current_page, ['profile.php', 'change-password.php'], true)
);
$unread_notification_count = 0;
$recent_header_notifications = [];
$recent_schedule_reminders = [];
$recent_other_updates = [];
$latest_header_notification_id = 0;

if (isLoggedIn()) {
    $current_uid = (int)($_SESSION['user_id'] ?? 0);

    // If staff/secretary/admin, process schedule reminders on page load
    $currentUserRoles = function_exists('userRoleKeys') ? userRoleKeys($current_uid, $conn) : [];
    if (isAdmin() || in_array('secretary', $currentUserRoles, true) || in_array('parish_staff', $currentUserRoles, true) || (function_exists('hasPermission') && hasPermission('admin.access'))) {
        if (!class_exists('ScheduleReminderService')) {
            @require_once __DIR__ . '/../services/ScheduleReminderService.php';
        }
        if (class_exists('ScheduleReminderService')) {
            try {
                ScheduleReminderService::processReminders($conn);
            } catch (Throwable $ignoreReminders) {
                error_log('[HeaderReminderTrigger] ' . $ignoreReminders->getMessage());
            }
        }
    }

    $unread_notification_count = getUnreadNotificationCount($conn, $current_uid);
    $recent_header_notifications = getRecentNotifications($conn, $current_uid, 5);

    // Group 1: Schedule reminders for secretary / staff (sorted soonest first)
    $srStmt = $conn->prepare("
        SELECT notification_id, notification_type, title, message, entity_type, entity_id, action_key, state, is_read, related_request_id, schedule_id, days_before, created_at
        FROM notifications
        WHERE user_id = ?
          AND notification_type = 'schedule_reminder'
          AND state <> 'deleted'
          AND state <> 'archived'
        ORDER BY CASE WHEN days_before IS NULL THEN 99 ELSE days_before END ASC, created_at DESC
        LIMIT 6
    ");
    if ($srStmt) {
        $srStmt->bind_param('i', $current_uid);
        $srStmt->execute();
        $srRes = $srStmt->get_result();
        while ($srRow = $srRes->fetch_assoc()) {
            $recent_schedule_reminders[] = $srRow;
        }
        $srStmt->close();
    }

    // Group 2: Other updates
    $ouStmt = $conn->prepare("
        SELECT notification_id, notification_type, title, message, entity_type, entity_id, action_key, state, is_read, related_request_id, schedule_id, created_at
        FROM notifications
        WHERE user_id = ?
          AND notification_type <> 'schedule_reminder'
          AND state <> 'deleted'
          AND state <> 'archived'
        ORDER BY created_at DESC
        LIMIT 6
    ");

    if ($ouStmt) {
        $ouStmt->bind_param('i', $current_uid);
        $ouStmt->execute();
        $ouRes = $ouStmt->get_result();
        while ($ouRow = $ouRes->fetch_assoc()) {
            $recent_other_updates[] = $ouRow;
        }
        $ouStmt->close();
    }

    // Latest notification ID for real-time shake tracking
    $maxStmt = $conn->prepare("SELECT MAX(notification_id) AS max_id FROM notifications WHERE user_id = ? AND state <> 'deleted'");
    if ($maxStmt) {
        $maxStmt->bind_param('i', $current_uid);
        $maxStmt->execute();
        $mRow = $maxStmt->get_result()->fetch_assoc();
        $latest_header_notification_id = (int)($mRow['max_id'] ?? 0);
        $maxStmt->close();
    }
}
$current_language = function_exists('tugonCurrentLanguage') ? tugonCurrentLanguage() : 'en';
$admin_page_titles = [
    'dashboard.php' => ['Dashboard', 'Monitor parish activities, requests, records, and operations.'],
    'manage-requests.php' => ['Certificate Requests', 'Review and manage parish service requests.'],
    'certificate-generator.php' => ['Generate Certificates', 'Prepare and release official parish certificates.'],
    'certificate-templates.php' => ['Certificate Layouts', 'Maintain premium certificate templates and layouts.'],
    'certificate-layout-editor.php' => ['Certificate Layout Editor', 'Refine certificate presentation and parish identity.'],
    'manage-records.php' => ['Sacramental Records', 'Browse and maintain sacramental registries.'],
    'baptism-records.php' => ['Baptism Records', 'Manage baptism registry entries.'],
    'confirmation-records.php' => ['Confirmation Records', 'Manage confirmation registry entries.'],
    'communion-records.php' => ['First Communion Records', 'Manage First Communion registry entries.'],
    'marriage-records.php' => ['Marriage Records', 'Manage marriage registry entries.'],
    'funeral-records.php' => ['Funeral Records', 'Manage funeral registry entries.'],
    'manage-users.php' => ['Parishioners', 'Manage parishioner accounts and verification.'],
    'manage-parishioners.php' => ['Parishioners', 'Review parishioner profiles and records.'],
    'manage-reservations.php' => ['Reservations', 'Coordinate facility and event reservations.'],
    'manage-announcements.php' => ['Announcements', 'Publish parish notices and updates.'],
    'post-announcement.php' => ['Post Announcement', 'Create a clear and timely parish announcement.'],
    'manage-calendar.php' => ['Parish Calendar', 'Coordinate Masses, events, and schedules.'],
    'reports.php' => ['Analytics & Reports', 'Parish operational statistics, sacramental records, and request monitoring.'],
    'archives.php' => ['Archives', 'Access archived requests and parish records.'],
    'audit-logs.php' => ['Audit Logs', 'Review system activity and accountability records.'],
    'verify-registrations.php' => ['Verify Registrations', 'Approve trusted access for parishioners.'],
    'settings.php' => ['Settings', 'Configure system preferences and parish details.'],
    'integration-health.php' => ['Integration Health', 'Monitor connected services and system readiness.'],
    'request-workflow.php' => ['Request Workflow', 'Track request progress and operational steps.'],
    'ai-assistant.php' => ['AI Assistant', 'Use parish knowledge to support staff workflows.'],
    'chatbot-knowledge.php' => ['Chatbot Knowledge', 'Maintain the assistant knowledge base.'],
    'ui-style-guide.php' => ['UI Style Guide', 'Preview shared interface components.'],
    'broadcast-notification.php' => ['Broadcast Notification', 'Publish parish announcements or broadcast alerts to active parishioners.'],
    'notification-deliveries.php' => ['Notification Deliveries', 'Track and retry queued email and SMS delivery attempts.'],
    'organization.php' => ['Parish Organization', 'Manage clerical appointments, pastoral council, and ministry coordinators.'],
    'profile.php' => ['Profile', 'Manage your administrator account and contact information.'],
    'change-password.php' => ['Change Password', 'Update your administrator account security.'],
];
$admin_header_title = $admin_page_titles[$current_page][0] ?? (isset($page_title) ? preg_replace('/\s*\|\s*.*$/', '', $page_title) : ucwords(str_replace(['-', '.php'], [' ', ''], $current_page)));
$admin_header_description = $admin_page_titles[$current_page][1] ?? 'Manage parish operations with clarity and care.';
$admin_header_icons = [
    'dashboard.php' => 'fa-gauge-high',
    'manage-requests.php' => 'fa-list-check',
    'certificate-generator.php' => 'fa-award',
    'certificate-templates.php' => 'fa-layer-group',
    'certificate-layout-editor.php' => 'fa-pen-ruler',
    'manage-records.php' => 'fa-book-bible',
    'baptism-records.php' => 'fa-water',
    'confirmation-records.php' => 'fa-dove',
    'communion-records.php' => 'fa-wheat-awn',
    'marriage-records.php' => 'fa-ring',
    'funeral-records.php' => 'fa-cross',
    'manage-users.php' => 'fa-users',
    'manage-parishioners.php' => 'fa-users',
    'organization.php' => 'fa-sitemap',
    'manage-reservations.php' => 'fa-calendar-check',
    'manage-announcements.php' => 'fa-bullhorn',
    'post-announcement.php' => 'fa-pen-to-square',
    'broadcast-notification.php' => 'fa-paper-plane',
    'notification-deliveries.php' => 'fa-envelope-circle-check',
    'manage-calendar.php' => 'fa-calendar-days',
    'reports.php' => 'fa-chart-line',
    'archives.php' => 'fa-box-archive',
    'audit-logs.php' => 'fa-shield-halved',
    'verify-registrations.php' => 'fa-user-check',
    'settings.php' => 'fa-gear',
    'integration-health.php' => 'fa-heart-pulse',
    'request-workflow.php' => 'fa-diagram-project',
    'ai-assistant.php' => 'fa-robot',
    'chatbot-knowledge.php' => 'fa-brain',
    'ui-style-guide.php' => 'fa-palette',
    'profile.php' => 'fa-user-gear',
    'change-password.php' => 'fa-lock',
];
$user_page_titles = [
    'index.php' => ['Dashboard', 'Track your requests, schedules, announcements, and parish updates.'],
    'dashboard.php' => ['Dashboard', 'Track your requests, schedules, announcements, and parish updates.'],
    'request-certificate.php' => ['Certificate Requests', 'Request parish certificates and follow their progress.'],
    'request-blessing.php' => ['Blessing Requests', 'Submit and monitor blessing requests.'],
    'request-service.php' => ['Sacramental Services', 'Request sacramental services from the parish office.'],
    'my-requests.php' => ['My Requests', 'Review your submitted requests and current status.'],
    'view-request.php' => ['Request Details', 'Review request information, requirements, and updates.'],
    'organization.php' => ['Parish Organization Chart', 'Explore the clergy, pastoral council, and ministry coordinators.'],
    'announcements.php' => ['Announcements', 'Read official parish announcements and updates.'],
    'notifications.php' => ['Notifications', 'Review parish updates and request alerts.'],
    'view-schedule.php' => ['Parish Calendar', 'View Masses, parish events, and service schedules.'],
    'ai-assistant.php' => ['AI Assistant', 'Get guidance about parish services and requests.'],
    'profile.php' => ['Profile', 'Manage your account and contact information.'],
    'change-password.php' => ['Change Password', 'Update your account security.'],
];
$user_header_title = $user_page_titles[$current_page][0] ?? (isset($page_title) ? preg_replace('/\s*\|\s*.*$/', '', $page_title) : ucwords(str_replace(['-', '.php'], [' ', ''], $current_page)));
$user_header_description = $user_page_titles[$current_page][1] ?? 'Manage your parish services in one place.';
$user_header_icons = [
    'index.php' => 'fa-gauge-high',
    'dashboard.php' => 'fa-gauge-high',
    'request-certificate.php' => 'fa-certificate',
    'request-blessing.php' => 'fa-hands-praying',
    'organization.php' => 'fa-sitemap',
    'announcements.php' => 'fa-bullhorn',
    'notifications.php' => 'fa-bell',
    'view-schedule.php' => 'fa-calendar-days',
    'ai-assistant.php' => 'fa-robot',
    'profile.php' => 'fa-user-gear',
    'change-password.php' => 'fa-lock',
];
$header_icon = $is_admin_area ? ($admin_header_icons[$current_page] ?? 'fa-table-cells-large') : ($user_header_icons[$current_page] ?? 'fa-table-cells-large');

if ($is_admin_area) {
    $header_user_name = 'Parish Admin';
    $header_avatar_initial = 'P';
    $header_user_role = '';
} else {
    $header_user_name = sanitize($_SESSION['fullname'] ?? 'Parishioner');
    $header_avatar_initial = strtoupper(substr($header_user_name ?: 'P', 0, 1));
    $header_user_first_name = trim(explode(' ', $header_user_name)[0] ?? $header_user_name);
    $header_user_role = 'Parishioner';
}

$header_avatar_url = '';
if (isLoggedIn()) {
    if (!isset($_SESSION['profile_picture']) && isset($conn) && $conn instanceof mysqli) {
        $u_stmt = $conn->prepare("SELECT profile_picture FROM users WHERE id = ? LIMIT 1");
        if ($u_stmt) {
            $u_stmt->bind_param('i', $_SESSION['user_id']);
            $u_stmt->execute();
            $u_res = $u_stmt->get_result();
            if ($u_row = $u_res->fetch_assoc()) {
                $_SESSION['profile_picture'] = (string) ($u_row['profile_picture'] ?? '');
            }
            $u_stmt->close();
        }
    }
    if (!empty($_SESSION['profile_picture'])) {
        $header_avatar_url = function_exists('getUserAvatarUrl')
            ? getUserAvatarUrl((int)$_SESSION['user_id'], (string)$_SESSION['profile_picture'])
            : BASE_URL . ltrim($_SESSION['profile_picture'], '/');
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_language === 'fil' ? 'fil' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/img/church-tile-maroon.svg">
    <link rel="alternate icon" href="<?php echo BASE_URL; ?>assets/img/church-icon.svg">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Generated from readable source files by tools/build-assets.php. -->
    <?php $core_bundle = __DIR__ . '/../assets/css/tugon-core.bundle.min.css'; ?>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/tugon-core.bundle.min.css?v=<?php echo file_exists($core_bundle) ? filemtime($core_bundle) : time(); ?>">
    <style id="critical-sidebar-colors">
        .admin-sidebar,
        .premium-admin-sidebar,
        .user-sidebar {
            background: linear-gradient(180deg, #2E3A2D, #263225) !important;
            color: #FFFFFF !important;
            border-right: 1px solid rgba(200, 155, 60, 0.3) !important;
            box-shadow: 6px 0 20px rgba(20, 29, 20, 0.12) !important;
        }
        .admin-sidebar .sidebar-brand,
        .premium-admin-sidebar .sidebar-brand,
        .user-sidebar .sidebar-brand {
            background: rgba(255, 255, 255, 0.035) !important;
            border-bottom: 1px solid rgba(200, 155, 60, 0.38) !important;
            color: #FFFFFF !important;
        }
        .admin-sidebar .brand-logo,
        .admin-sidebar .pill-badge,
        .premium-admin-sidebar .brand-logo,
        .premium-admin-sidebar .pill-badge,
        .user-sidebar .brand-logo,
        .user-sidebar .pill-badge {
            background: rgba(200, 155, 60, 0.1) !important;
            color: #C89B3C !important;
            border: 1px solid rgba(200, 155, 60, 0.55) !important;
        }
        .admin-sidebar .nav-link,
        .premium-admin-sidebar .nav-link,
        .user-sidebar .nav-link,
        .admin-sidebar .nav-toggle,
        .premium-admin-sidebar .nav-toggle,
        .user-sidebar .nav-toggle {
            color: rgba(255, 255, 255, 0.88) !important;
        }
        .admin-sidebar .nav-link.active,
        .user-sidebar .nav-link.active {
            background: rgba(200, 155, 60, 0.15) !important;
            color: #FFFFFF !important;
        }
        /* Secretary Notification Bell & Schedule Reminders */
        @keyframes bellShake {
            0% { transform: rotate(0); }
            15% { transform: rotate(16deg); }
            30% { transform: rotate(-16deg); }
            45% { transform: rotate(12deg); }
            60% { transform: rotate(-12deg); }
            75% { transform: rotate(6deg); }
            85% { transform: rotate(-6deg); }
            100% { transform: rotate(0); }
        }
        .bell-shaking {
            animation: bellShake 0.8s cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
            transform-origin: top center;
            color: #C89B3C !important;
        }
        .unread-gold-dot {
            width: 8px;
            height: 8px;
            background-color: #C89B3C;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
            box-shadow: 0 0 6px rgba(200, 155, 60, 0.6);
        }
        .notif-dropdown-row:hover,
        .notif-item-row:hover {
            background-color: #F8FAFC !important;
        }
        .btn-mark-item-read:hover {
            color: #0F5132 !important;
            background-color: #E2E8F0 !important;
            border-radius: 50%;
        }
    </style>
    <?php if ($is_admin_area): ?>
    <script>
        (function() {
            try {
                var state = sessionStorage.getItem('admin_sidebar_state');
                if (state === 'expanded' && window.innerWidth >= 1024) {
                    document.documentElement.classList.add('admin-sidebar-expanded');
                    document.documentElement.classList.remove('admin-sidebar-collapsed');
                } else {
                    document.documentElement.classList.add('admin-sidebar-collapsed');
                    document.documentElement.classList.remove('admin-sidebar-expanded');
                }
            } catch (e) {
                document.documentElement.classList.add('admin-sidebar-collapsed');
            }
        })();
    </script>
    <?php endif; ?>
</head>
<body class="<?php echo $is_user_area ? 'user-area' : ($is_admin_area ? 'premium-admin admin-sidebar-collapsed' : ''); ?> church-theme app-page-<?php echo e(preg_replace('/[^a-z0-9-]+/', '-', strtolower(pathinfo($current_page, PATHINFO_FILENAME)))); ?><?php echo !empty($body_extra_class) ? ' ' . e($body_extra_class) : ''; ?>">
    <a class="tugon-skip-link" href="#main-content">Skip to main content</a>
    <?php if ($is_user_area): ?>
    <div class="user-shell">
        <?php include __DIR__ . '/../includes/user-sidebar.php'; ?>
        <div class="user-main">
            <header class="app-global-header user-topbar user-global-topbar premium-glass">
                <div class="app-header-left admin-global-title user-global-title">
                    <div class="user-header-text">
                        <h1><?php echo e($user_header_title); ?></h1>
                        <p><?php echo e($user_header_description); ?></p>
                    </div>
                    <a href="<?php echo BASE_URL; ?>users/index.php" class="mobile-dashboard-brand" aria-label="Go to Parish Dashboard">
                        <span class="mobile-brand-icon"><i class="fas fa-church"></i></span>
                        <div class="mobile-brand-text">
                            <strong>TUGON</strong>
                            <small><?php echo e($user_header_title !== 'Dashboard' ? $user_header_title : 'Parish Portal'); ?></small>
                        </div>
                    </a>
                </div>
                <div class="app-header-right admin-global-actions user-global-actions">
                    <div class="dropdown">
                        <button class="profile-btn user-profile-btn parish-profile-pill-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="userProfileDropdown">
                            <span class="profile-avatar parish-profile-avatar" style="overflow: hidden; padding: 0;">
                                <?php if (!empty($header_avatar_url)): ?>
                                    <img src="<?php echo e($header_avatar_url); ?>" alt="Avatar" class="parish-avatar-img" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block;" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                                    <span style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center;"><?php echo $header_avatar_initial; ?></span>
                                <?php else: ?>
                                    <?php echo $header_avatar_initial; ?>
                                <?php endif; ?>
                            </span>
                            <span class="profile-meta parish-profile-meta">
                                <span class="profile-name parish-profile-name"><?php echo $header_user_name; ?></span>
                                <span class="profile-role parish-profile-role"><?php echo e($header_user_role); ?></span>
                            </span>
                            <i class="fas fa-chevron-down ms-1" style="font-size: 10px; color: #9A733B;"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="userProfileDropdown">
                            <li><a class="dropdown-item" href="../auth/profile.php"><i class="fas fa-user-gear me-2 text-muted"></i> Profile Settings</a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>users/notifications.php"><i class="fas fa-bell me-2 text-muted"></i> Notifications<?php if ($unread_notification_count > 0): ?> <span class="badge bg-danger ms-auto"><?php echo $unread_notification_count; ?></span><?php endif; ?></a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger user-header-logout" href="../auth/logout.php"><i class="fas fa-power-off me-2"></i> <?php echo e(t('nav.logout', 'Log Out')); ?></a></li>
                        </ul>
                    </div>
                </div>
            </header>
            <main class="user-content" id="main-content" tabindex="-1">
    <?php elseif ($is_admin_area): ?>
    <div class="app-layout premium-admin-shell">
        <?php include __DIR__ . '/../includes/admin-sidebar.php'; ?>
        <main class="premium-admin-content main-content" id="main-content" tabindex="-1">
            <?php if (empty($hide_global_header)): ?>
            <header class="dashboard-header-row parish-top-nav-bar app-global-header admin-global-topbar flex items-center justify-between w-full py-2 mb-3 bg-transparent" style="background: transparent !important; border: none !important; box-shadow: none !important;">
                <!-- Left Title & Subtitle with Light Muted Green Square Icon Container -->
                <div class="d-flex align-items-center gap-3 parish-nav-left admin-global-title">
                    <div class="parish-nav-badge-icon" aria-hidden="true">
                        <i class="fas <?php echo e($header_icon); ?>"></i>
                    </div>
                    <div class="dashboard-header-title-block">
                        <h1 class="text-xl font-bold text-gray-900 m-0" style="font-family: 'Playfair Display', Georgia, serif; font-size: 2.25rem; font-weight: 700; color: #1e293b; line-height: 1.15; letter-spacing: -0.3px;"><?php echo e($admin_header_title); ?></h1>
                        <p class="text-xs text-muted m-0 d-none d-sm-block" style="font-size: 0.86rem; color: #6b6a63; font-weight: 500; margin-top: 4px;"><?php echo e($admin_header_description); ?></p>
                    </div>
                </div>

                <!-- Right Admin Profile & Notification Widget -->
                <div class="dashboard-header-profile-wrap parish-nav-right admin-global-actions">
                    <div class="d-flex align-items-center gap-2">
                        <!-- Admin & Secretary Notification Bell Dropdown -->
                        <div class="dropdown" id="adminNotifDropdownContainer">
                            <input type="hidden" id="adminInitialLatestId" value="<?php echo (int)$latest_header_notification_id; ?>">
                            <button class="btn pds-btn-notif-bell position-relative d-inline-flex align-items-center justify-content-center" 
                                    type="button" 
                                    data-bs-toggle="dropdown" 
                                    data-bs-auto-close="true"
                                    aria-expanded="false" 
                                    id="adminNotifDropdownBtn"
                                    title="Parish Notifications"
                                    style="width: 42px; height: 42px; border-radius: 50%; background: #FFFFFF; border: 1px solid #E2E8F0; color: #475569; box-shadow: 0 1px 3px rgba(0,0,0,0.06); transition: all 0.2s ease;">
                                <i class="fas fa-bell bell-icon" style="font-size: 16px;"></i>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger admin-unread-badge-counter" 
                                      id="adminUnreadBadge"
                                      style="<?php echo $unread_notification_count > 0 ? '' : 'display: none !important;'; ?> font-size: 10px; padding: 3px 6px; border: 2px solid #FFFFFF;">
                                    <?php echo $unread_notification_count > 99 ? '99+' : $unread_notification_count; ?>
                                    <span class="visually-hidden">unread notifications</span>
                                </span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end shadow-lg p-0" 
                                 aria-labelledby="adminNotifDropdownBtn"
                                 id="adminNotifDropdownMenu"
                                 style="width: 380px; max-width: 94vw; border-radius: 12px; border: 1px solid #E2E8F0; overflow: hidden; font-family: 'Inter', system-ui, sans-serif;">
                                <!-- Dropdown Header -->
                                <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom" style="background: #1e2d24; color: #FFFFFF;">
                                    <div class="d-flex align-items-center gap-2">
                                        <strong style="font-size: 13px; font-weight: 700; color: #FFFFFF;">Notifications</strong>
                                        <span class="badge rounded-pill" id="adminUnreadHeaderCount" style="<?php echo $unread_notification_count > 0 ? '' : 'display: none !important;'; ?> background: #C89B3C; color: #1e2d24; font-size: 10px; font-weight: 700;">
                                            <?php echo $unread_notification_count; ?> unread
                                        </span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" id="btnMarkAllRead" class="btn btn-link p-0 text-decoration-none" style="font-size: 11px; font-weight: 600; color: #deb042;" title="Mark all notifications as read">
                                            Mark all read
                                        </button>
                                        <a href="<?php echo BASE_URL; ?>admin/notification-settings.php" class="text-decoration-none" style="color: #E2E8F0; font-size: 12px;" title="Reminder Settings">
                                            <i class="fas fa-gear"></i>
                                        </a>
                                    </div>
                                </div>

                                <!-- Scrollable Lists Container -->
                                <div class="admin-notif-dropdown-list" style="max-height: 420px; overflow-y: auto;">
                                    <!-- SECTION 1: Schedule Reminders · Next 3 Days -->
                                    <div class="px-3 py-1 bg-light text-uppercase fw-bold text-muted d-flex align-items-center justify-content-between border-bottom" style="font-size: 10px; letter-spacing: 0.5px;">
                                        <span><i class="fas fa-calendar-check text-warning me-1"></i> Schedule reminders · next 3 days</span>
                                        <span class="badge bg-secondary-subtle text-secondary" style="font-size: 9px;" id="countScheduleReminders"><?php echo count($recent_schedule_reminders); ?></span>
                                    </div>
                                    <div id="scheduleRemindersList">
                                        <?php if (!empty($recent_schedule_reminders)): ?>
                                            <?php foreach ($recent_schedule_reminders as $sr): 
                                                $isUnread = empty($sr['is_read']) && ($sr['state'] ?? '') !== 'read';
                                                $days = $sr['days_before'] !== null ? (int)$sr['days_before'] : null;
                                                $edgeColor = match ($days) {
                                                    0 => '#DC2626', // solid red
                                                    1 => '#EF4444', // red
                                                    2 => '#F97316', // orange
                                                    3 => '#C89B3C', // gold
                                                    default => '#C89B3C'
                                                };
                                                $actionUrl = !empty($sr['related_request_id'])
                                                    ? (BASE_URL . 'admin/request-workflow.php?id=' . (int)$sr['related_request_id'])
                                                    : (!empty($sr['schedule_id']) 
                                                        ? (BASE_URL . 'admin/manage-calendar.php?event=' . (int)$sr['schedule_id'])
                                                        : (BASE_URL . 'admin/manage-requests.php'));
                                            ?>
                                                <div class="position-relative d-flex align-items-start gap-2 p-2 px-3 border-bottom notif-item-row" 
                                                     id="notif_row_<?php echo $sr['notification_id']; ?>"
                                                     style="<?php echo $isUnread ? 'background: #FAF8F2;' : 'background: #FFFFFF;'; ?> border-left: 4px solid <?php echo $edgeColor; ?> !important; transition: background 0.15s ease;">
                                                    <a href="<?php echo e($actionUrl); ?>" class="text-decoration-none flex-grow-1 overflow-hidden" style="color: inherit;">
                                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                                            <strong class="text-truncate d-block" style="font-size: 12.5px; color: #1E293B; max-width: 240px;">
                                                                <?php echo e($sr['title']); ?>
                                                            </strong>
                                                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                                                <?php if ($isUnread): ?>
                                                                    <span class="unread-gold-dot" id="dot_<?php echo $sr['notification_id']; ?>" title="Unread"></span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <p class="mb-1 text-muted text-truncate" style="font-size: 11.5px; line-height: 1.35;">
                                                            <?php echo e(notificationShortMessage($sr['message'] ?? '', 95)); ?>
                                                        </p>
                                                        <small class="text-muted" style="font-size: 10px;">
                                                            <i class="far fa-clock me-1"></i><?php echo formatDateTime($sr['created_at']); ?>
                                                        </small>
                                                    </a>
                                                    <?php if ($isUnread): ?>
                                                        <button type="button" class="btn btn-sm text-muted p-0 flex-shrink-0 btn-mark-item-read" 
                                                                onclick="window.markParishNotificationRead(event, <?php echo $sr['notification_id']; ?>)"
                                                                title="Mark as read" style="font-size: 12px; width: 22px; height: 22px; line-height: 22px; text-align: center;">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="text-center py-3 px-2 text-muted" style="font-size: 11.5px;">
                                                <i class="far fa-calendar-check me-1 text-secondary"></i> No schedule reminders in next 3 days
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- SECTION 2: Other Updates -->
                                    <div class="px-3 py-1 bg-light text-uppercase fw-bold text-muted d-flex align-items-center justify-content-between border-top border-bottom" style="font-size: 10px; letter-spacing: 0.5px;">
                                        <span><i class="fas fa-bell text-secondary me-1"></i> Other updates</span>
                                        <span class="badge bg-secondary-subtle text-secondary" style="font-size: 9px;" id="countOtherUpdates"><?php echo count($recent_other_updates); ?></span>
                                    </div>
                                    <div id="otherUpdatesList">
                                        <?php if (!empty($recent_other_updates)): ?>
                                            <?php foreach ($recent_other_updates as $ou): 
                                                $isUnread = empty($ou['is_read']) && ($ou['state'] ?? '') !== 'read';
                                                $type = $ou['notification_type'];
                                                $iconClass = match ($type) {
                                                    'new_request_admin', 'new_request' => 'fa-file-circle-plus',
                                                    'schedule_cancelled', 'request_cancelled' => 'fa-ban',
                                                    'schedule_conflict' => 'fa-calendar-xmark',
                                                    'stale_request' => 'fa-clock-rotate-left',
                                                    default => 'fa-bell'
                                                };
                                                $iconColor = match ($type) {
                                                    'new_request_admin', 'new_request' => '#0F5132',
                                                    'schedule_cancelled', 'request_cancelled' => '#DC2626',
                                                    'schedule_conflict' => '#B45309',
                                                    'stale_request' => '#C89B3C',
                                                    default => '#475569'
                                                };
                                                $actionUrl = !empty($ou['related_request_id'])
                                                    ? (BASE_URL . 'admin/request-workflow.php?id=' . (int)$ou['related_request_id'])
                                                    : ($ou['entity_type'] === 'request' && !empty($ou['entity_id'])
                                                        ? (BASE_URL . 'admin/request-workflow.php?id=' . (int)$ou['entity_id'])
                                                        : (BASE_URL . 'admin/manage-requests.php'));
                                            ?>
                                                <div class="position-relative d-flex align-items-start gap-2 p-2 px-3 border-bottom notif-item-row" 
                                                     id="notif_row_<?php echo $ou['notification_id']; ?>"
                                                     style="<?php echo $isUnread ? 'background: #FAF8F2;' : 'background: #FFFFFF;'; ?> transition: background 0.15s ease;">
                                                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 mt-1" 
                                                         style="width: 26px; height: 26px; background: #F1F5F9; color: <?php echo $iconColor; ?>; font-size: 11px;">
                                                        <i class="fas <?php echo $iconClass; ?>"></i>
                                                    </div>
                                                    <a href="<?php echo e($actionUrl); ?>" class="text-decoration-none flex-grow-1 overflow-hidden" style="color: inherit;">
                                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                                            <strong class="text-truncate d-block" style="font-size: 12px; color: #1E293B; max-width: 220px;">
                                                                <?php echo e($ou['title']); ?>
                                                            </strong>
                                                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                                                <?php if ($isUnread): ?>
                                                                    <span class="unread-gold-dot" id="dot_<?php echo $ou['notification_id']; ?>" title="Unread"></span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <p class="mb-1 text-muted text-truncate" style="font-size: 11px; line-height: 1.35;">
                                                            <?php echo e(notificationShortMessage($ou['message'] ?? '', 85)); ?>
                                                        </p>
                                                        <small class="text-muted" style="font-size: 10px;">
                                                            <i class="far fa-clock me-1"></i><?php echo formatDateTime($ou['created_at']); ?>
                                                        </small>
                                                    </a>
                                                    <?php if ($isUnread): ?>
                                                        <button type="button" class="btn btn-sm text-muted p-0 flex-shrink-0 btn-mark-item-read" 
                                                                onclick="window.markParishNotificationRead(event, <?php echo $ou['notification_id']; ?>)"
                                                                title="Mark as read" style="font-size: 12px; width: 22px; height: 22px; line-height: 22px; text-align: center;">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="text-center py-3 px-2 text-muted" style="font-size: 11.5px;">
                                                <i class="far fa-bell-slash me-1 text-secondary"></i> No other pending updates
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Dropdown Footer -->
                                <div class="p-2 border-top d-flex align-items-center justify-content-between" style="background: #F8FAFC;">
                                    <a href="<?php echo BASE_URL; ?>admin/notification-settings.php" class="text-decoration-none text-muted" style="font-size: 11px; font-weight: 600;">
                                        <i class="fas fa-sliders me-1"></i> Reminder Settings
                                    </a>
                                    <a href="<?php echo BASE_URL; ?>admin/manage-requests.php" class="text-decoration-none text-success" style="font-size: 11px; font-weight: 600;">
                                        All Requests &rarr;
                                    </a>
                                </div>
                            </div>
                        </div>
                        <script src="<?php echo BASE_URL; ?>assets/js/notifications-bell.js?v=<?php echo file_exists(__DIR__ . '/../assets/js/notifications-bell.js') ? filemtime(__DIR__ . '/../assets/js/notifications-bell.js') : time(); ?>"></script>

                        <!-- Admin Profile Dropdown -->
                        <div class="dropdown">
                            <button class="profile-chip-btn admin-profile-btn parish-profile-pill-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <span class="profile-chip-avatar profile-avatar parish-profile-avatar" style="overflow: hidden; padding: 0;">
                                    <?php if (!empty($header_avatar_url)): ?>
                                        <img src="<?php echo e($header_avatar_url); ?>" alt="Avatar" class="parish-avatar-img" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block;" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline-flex';">
                                        <span style="display: none; width: 100%; height: 100%; align-items: center; justify-content: center;"><?php echo $header_avatar_initial; ?></span>
                                    <?php else: ?>
                                        <?php echo $header_avatar_initial; ?>
                                    <?php endif; ?>
                                </span>
                                <span class="profile-chip-meta profile-meta parish-profile-meta">
                                    <span class="profile-chip-name profile-name parish-profile-name"><?php echo $header_user_name; ?></span>
                                    <?php if (!empty($header_user_role)): ?>
                                        <span class="profile-chip-role profile-role parish-profile-role"><?php echo e($header_user_role); ?></span>
                                    <?php endif; ?>
                                </span>
                                <i class="fas fa-chevron-down ms-1" style="font-size: 10px; color: #9a9890;"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><a class="dropdown-item" href="../auth/profile.php"><i class="fas fa-user me-2 text-muted"></i> My Profile</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/settings.php"><i class="fas fa-gear me-2 text-muted"></i> Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-arrow-right-from-bracket me-2"></i> <?php echo e(t('nav.logout', 'Logout')); ?></a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>
            <?php endif; ?>
    <?php else: ?>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo isAdmin() ? '../admin/dashboard.php' : '../index.php'; ?>">
                <i class="fas fa-church"></i> San Lorenzo Ruiz Mission Station
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <?php if (isLoggedIn()): ?>
                        <?php if (isAdmin()): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="../admin/dashboard.php">
                                    <i class="fas fa-gauge"></i> <?php echo e(t('nav.dashboard', 'Dashboard')); ?>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="../admin/archives.php">
                                    <i class="fas fa-box-archive"></i> <?php echo e(t('nav.archives', 'Archives')); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <li class="nav-item">
                            <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#notificationModal">
                                <i class="fas fa-bell"></i> <?php echo e(t('nav.notifications', 'Notifications')); ?>
                                <?php 
                                $count = getUnreadNotificationCount($conn, $_SESSION['user_id']);
                                if ($count > 0): 
                                ?>
                                    <span class="badge bg-danger"><?php echo $count; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#" data-bs-toggle="dropdown">
                                <i class="fas fa-user-circle"></i> <?php echo sanitize($_SESSION['fullname']); ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="../auth/profile.php">Profile</a></li>
                                <li><a class="dropdown-item" href="../auth/profile.php"><?php echo e(t('nav.profile_settings', 'Profile Settings')); ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="../auth/logout.php"><?php echo e(t('nav.logout', 'Logout')); ?></a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="../index.php">Login</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="page-content" id="main-content" tabindex="-1">
    <?php endif; ?>
