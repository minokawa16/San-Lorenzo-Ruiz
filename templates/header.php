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
$is_admin_area = isLoggedIn() && isAdmin() && (
    strpos($_SERVER['PHP_SELF'], '/admin/') !== false ||
    in_array($current_page, ['profile.php', 'change-password.php'], true)
);
$unread_notification_count = 0;
$recent_header_notifications = [];
if (isLoggedIn()) {
    $unread_notification_count = getUnreadNotificationCount($conn, $_SESSION['user_id']);
    $recent_header_notifications = getRecentNotifications($conn, $_SESSION['user_id'], 5);
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
    'manage-calendar.php' => ['Calendar', 'Coordinate Masses, events, and schedules.'],
    'reports.php' => ['Reports & Analytics', 'Review parish trends, activity, and records.'],
    'archives.php' => ['Archives', 'Access archived requests and parish records.'],
    'audit-logs.php' => ['Audit Logs', 'Review system activity and accountability records.'],
    'verify-registrations.php' => ['Verify Registrations', 'Approve trusted access for parishioners.'],
    'settings.php' => ['Settings', 'Configure system preferences and parish details.'],
    'integration-health.php' => ['Integration Health', 'Monitor connected services and system readiness.'],
    'request-workflow.php' => ['Request Workflow', 'Track request progress and operational steps.'],
    'ai-assistant.php' => ['AI Assistant', 'Use parish knowledge to support staff workflows.'],
    'chatbot-knowledge.php' => ['Chatbot Knowledge', 'Maintain the assistant knowledge base.'],
    'ui-style-guide.php' => ['UI Style Guide', 'Preview shared interface components.'],
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
    'manage-reservations.php' => 'fa-calendar-check',
    'manage-announcements.php' => 'fa-bullhorn',
    'post-announcement.php' => 'fa-pen-to-square',
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
$admin_search_placeholders = [
    'dashboard.php' => 'Search parishioners, requests, records...',
    'manage-announcements.php' => 'Search announcements...',
    'post-announcement.php' => 'Search announcements...',
    'manage-records.php' => 'Search sacramental records...',
    'baptism-records.php' => 'Search sacramental records...',
    'confirmation-records.php' => 'Search sacramental records...',
    'communion-records.php' => 'Search sacramental records...',
    'marriage-records.php' => 'Search sacramental records...',
    'funeral-records.php' => 'Search sacramental records...',
    'certificate-generator.php' => 'Search certificates...',
    'certificate-templates.php' => 'Search certificates...',
    'certificate-layout-editor.php' => 'Search certificates...',
    'manage-calendar.php' => 'Search schedules and events...',
    'manage-users.php' => 'Search parishioners...',
    'manage-parishioners.php' => 'Search parishioners...',
    'settings.php' => 'Search settings...',
    'audit-logs.php' => 'Search audit logs...',
    'reports.php' => 'Search reports...',
    'archives.php' => 'Search archives...',
    'ai-assistant.php' => 'Search AI assistant knowledge...',
    'chatbot-knowledge.php' => 'Search knowledge base...',
    'profile.php' => 'Search profile settings...',
    'change-password.php' => 'Search account settings...',
];
$user_page_titles = [
    'index.php' => ['Dashboard', 'Track your requests, schedules, announcements, and parish updates.'],
    'dashboard.php' => ['Dashboard', 'Track your requests, schedules, announcements, and parish updates.'],
    'request-certificate.php' => ['Certificate Requests', 'Request parish certificates and follow their progress.'],
    'request-blessing.php' => ['Blessing Requests', 'Submit and monitor blessing requests.'],
    'request-service.php' => ['Sacramental Services', 'Request sacramental services from the parish office.'],
    'my-requests.php' => ['My Requests', 'Review your submitted requests and current status.'],
    'view-request.php' => ['Request Details', 'Review request information, requirements, and updates.'],
    'make-reservation.php' => ['Reservations', 'Reserve parish facilities and services.'],
    'my-reservations.php' => ['My Reservations', 'Review your upcoming and past reservations.'],
    'announcements.php' => ['Announcements', 'Read official parish announcements and updates.'],
    'notifications.php' => ['Notifications', 'Review parish updates and request alerts.'],
    'view-schedule.php' => ['Schedule', 'View Masses, parish events, and service schedules.'],
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
    'request-service.php' => 'fa-church',
    'my-requests.php' => 'fa-list-check',
    'view-request.php' => 'fa-file-lines',
    'make-reservation.php' => 'fa-calendar-plus',
    'my-reservations.php' => 'fa-calendar-check',
    'announcements.php' => 'fa-bullhorn',
    'notifications.php' => 'fa-bell',
    'view-schedule.php' => 'fa-calendar-days',
    'ai-assistant.php' => 'fa-robot',
    'profile.php' => 'fa-user-gear',
    'change-password.php' => 'fa-lock',
];
$user_search_placeholders = [
    'index.php' => 'Search requests, certificates, schedules...',
    'dashboard.php' => 'Search requests, certificates, schedules...',
    'request-certificate.php' => 'Search certificates...',
    'my-requests.php' => 'Search your requests...',
    'view-request.php' => 'Search request details...',
    'announcements.php' => 'Search announcements...',
    'notifications.php' => 'Search notifications...',
    'view-schedule.php' => 'Search schedules and events...',
    'make-reservation.php' => 'Search reservations...',
    'request-blessing.php' => 'Search blessing requests...',
    'request-service.php' => 'Search sacramental services...',
    'ai-assistant.php' => 'Search AI assistant...',
    'profile.php' => 'Search profile settings...',
    'change-password.php' => 'Search account settings...',
];
$header_icon = $is_admin_area ? ($admin_header_icons[$current_page] ?? 'fa-table-cells-large') : ($user_header_icons[$current_page] ?? 'fa-table-cells-large');
$header_search_placeholder = $is_admin_area ? ($admin_search_placeholders[$current_page] ?? 'Search parishioners, requests, records...') : ($user_search_placeholders[$current_page] ?? 'Search parish services...');
$header_search_action = $is_admin_area ? BASE_URL . 'admin/manage-users.php' : BASE_URL . 'users/my-requests.php';
$header_search_name = $is_admin_area ? 'search' : 'q';
$header_search_value = $_GET[$header_search_name] ?? ($_GET['q'] ?? ($_GET['search'] ?? ''));
$header_user_name = sanitize($_SESSION['fullname'] ?? ($is_admin_area ? 'Administrator' : 'Parishioner'));
$header_user_first_name_parts = preg_split('/\s+/', trim((string) ($_SESSION['fullname'] ?? 'Parishioner')));
$header_user_first_name = sanitize($header_user_first_name_parts[0] ?? 'Parishioner');
$header_user_role = $is_admin_area ? 'Administrator' : 'Parishioner';
?>
<!DOCTYPE html>
<html lang="<?php echo $current_language === 'fil' ? 'fil' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' | ' : ''; ?>San Lorenzo Ruiz Mission Station</title>
    
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
    </style>
</head>
<body class="<?php echo $is_user_area ? 'user-area' : ($is_admin_area ? 'premium-admin' : ''); ?> church-theme app-page-<?php echo e(preg_replace('/[^a-z0-9-]+/', '-', strtolower(pathinfo($current_page, PATHINFO_FILENAME)))); ?><?php echo !empty($body_extra_class) ? ' ' . e($body_extra_class) : ''; ?>">
    <a class="tugon-skip-link" href="#main-content">Skip to main content</a>
    <?php if ($is_user_area): ?>
    <div class="user-shell">
        <?php include '../includes/user-sidebar.php'; ?>
        <div class="user-main">
            <header class="app-global-header user-topbar user-global-topbar premium-glass">
                <div class="app-header-left admin-global-title user-global-title">
                    <div>
                        <h1><?php echo e($user_header_title); ?></h1>
                        <p><?php echo e($user_header_description); ?></p>
                    </div>
                    <div class="mobile-dashboard-brand" aria-hidden="true">
                        <strong><span>San Lorenzo Ruiz</span><em>Mission Station</em></strong>
                        <small>Welcome back, <?php echo e($header_user_first_name); ?></small>
                    </div>
                </div>
                <div class="app-header-center">
                    <form class="premium-search topbar-search app-header-search" action="<?php echo e($header_search_action); ?>" method="GET">
                        <i class="fas fa-magnifying-glass"></i>
                        <input type="search" name="<?php echo e($header_search_name); ?>" placeholder="<?php echo e($header_search_placeholder); ?>" value="<?php echo e($header_search_value); ?>">
                        <kbd>Ctrl K</kbd>
                    </form>
                </div>
                <div class="app-header-right admin-global-actions user-global-actions">
                    <div class="dropdown">
                        <button class="profile-btn user-profile-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="profile-avatar">
                                <?php echo strtoupper(substr($_SESSION['fullname'] ?? 'U', 0, 1)); ?>
                            </span>
                            <span class="profile-meta">
                                <span class="profile-name"><?php echo $header_user_name; ?></span>
                                <span class="profile-role"><?php echo e($header_user_role); ?></span>
                            </span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="../auth/profile.php"><i class="fas fa-user"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>users/ai-assistant.php"><i class="fas fa-circle-question"></i> Help</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item user-header-logout" href="../auth/logout.php"><i class="fas fa-power-off"></i> <?php echo e(t('nav.logout', 'Log Out')); ?></a></li>
                        </ul>
                    </div>
                </div>
            </header>
            <main class="user-content" id="main-content" tabindex="-1">
    <?php elseif ($is_admin_area): ?>
    <div class="app-layout premium-admin-shell">
        <?php include '../includes/admin-sidebar.php'; ?>
        <main class="premium-admin-content main-content" id="main-content" tabindex="-1">
            <?php if (empty($hide_global_header)): ?>
            <header class="app-global-header admin-global-topbar flex items-center justify-between w-full py-3 mb-4 bg-transparent" style="background: transparent !important; border: none !important; box-shadow: none !important;">
                <!-- Left Title & Subtitle -->
                <div class="admin-global-title flex items-center gap-3">
                    <h1 class="text-xl font-bold text-gray-900 m-0" style="font-family: 'Playfair Display', Georgia, serif; font-size: 1.45rem; font-weight: 700; color: #1e293b;"><?php echo e($admin_header_title); ?></h1>
                    <span class="text-xs text-muted d-none d-sm-inline-block" style="font-size: 0.8rem; color: #6b6a63;"><?php echo e($admin_header_description); ?></span>
                </div>

                <!-- Center Search Bar (No double borders) -->
                <div class="app-header-center flex-1 max-w-md mx-6">
                    <form class="app-header-search" action="<?php echo e($header_search_action); ?>" method="GET" style="background: transparent; border: none; box-shadow: none;">
                        <i class="fas fa-magnifying-glass"></i>
                        <input type="search" name="<?php echo e($header_search_name); ?>" placeholder="<?php echo e($header_search_placeholder); ?>" value="<?php echo e($header_search_value); ?>" autocomplete="off">
                        <kbd>Ctrl K</kbd>
                    </form>
                </div>

                <!-- Right Admin Profile -->
                <div class="admin-global-actions">
                    <div class="dropdown">
                        <button class="profile-chip-btn admin-profile-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="profile-chip-avatar profile-avatar"><?php echo strtoupper(substr($_SESSION['fullname'] ?? 'A', 0, 1)); ?></span>
                            <span class="profile-chip-meta profile-meta">
                                <span class="profile-chip-name profile-name"><?php echo $header_user_name; ?></span>
                                <span class="profile-chip-role profile-role"><?php echo e($header_user_role); ?></span>
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
