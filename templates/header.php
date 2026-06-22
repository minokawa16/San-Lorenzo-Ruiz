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
    include '../database/config.php';
}
if (!function_exists('isLoggedIn')) {
    include '../includes/helpers.php';
}

$current_page = basename($_SERVER['PHP_SELF']);
$style_version = file_exists(__DIR__ . '/../assets/css/style.css')
    ? filemtime(__DIR__ . '/../assets/css/style.css')
    : time();
$premium_style_version = file_exists(__DIR__ . '/../assets/css/premium-parish.css')
    ? filemtime(__DIR__ . '/../assets/css/premium-parish.css')
    : time();
$is_user_area = isLoggedIn() && !isAdmin() && (
    strpos($_SERVER['PHP_SELF'], '/users/') !== false ||
    in_array($current_page, ['profile.php', 'change-password.php'], true)
);
$is_admin_area = isLoggedIn() && isAdmin() && strpos($_SERVER['PHP_SELF'], '/admin/') !== false;
$unread_notification_count = 0;
$recent_header_notifications = [];
if (isLoggedIn()) {
    $unread_notification_count = getUnreadNotificationCount($conn, $_SESSION['user_id']);
    $recent_header_notifications = getRecentNotifications($conn, $_SESSION['user_id'], 5);
}
$current_language = function_exists('tugonCurrentLanguage') ? tugonCurrentLanguage() : 'en';
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
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo $style_version; ?>">
    <link rel="stylesheet" href="../assets/css/premium-parish.css?v=<?php echo $premium_style_version; ?>">
</head>
<body class="<?php echo $is_user_area ? 'user-area' : ($is_admin_area ? 'premium-admin' : ''); ?> church-theme">
    <?php if ($is_user_area): ?>
    <div class="user-shell">
        <?php include '../includes/user-sidebar.php'; ?>
        <div class="user-main">
            <header class="user-topbar premium-glass">
                <div class="topbar-left">
                    <button class="topbar-toggle" id="userSidebarToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="topbar-title">San Lorenzo Ruiz Mission Station</div>
                </div>
                <form class="topbar-search" action="<?php echo BASE_URL; ?>users/my-requests.php" method="GET">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" placeholder="<?php echo e(t('search.requests', 'Search requests...')); ?>" value="<?php echo e($_GET['q'] ?? ''); ?>">
                </form>
                <div class="topbar-actions">
                    <div class="language-switcher compact" aria-label="<?php echo e(t('lang.label', 'Language')); ?>">
                        <a href="<?php echo e(tugonLanguageUrl('en')); ?>" class="<?php echo $current_language === 'en' ? 'active' : ''; ?>">EN</a>
                        <a href="<?php echo e(tugonLanguageUrl('fil')); ?>" class="<?php echo $current_language === 'fil' ? 'active' : ''; ?>">FIL</a>
                    </div>
                    <!-- Optional settings icon (keeps header minimal) -->
                    <button class="icon-btn" id="settingsBtn" title="<?php echo e(t('nav.settings', 'Settings')); ?>" type="button">
                        <i class="fas fa-cog"></i>
                    </button>
                    <button class="icon-btn" id="darkModeToggle" type="button" aria-label="Toggle dark mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <div class="dropdown">
                        <button class="profile-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="profile-avatar">
                                <?php echo strtoupper(substr($_SESSION['fullname'] ?? 'U', 0, 1)); ?>
                            </span>
                            <span class="profile-name"><?php echo sanitize($_SESSION['fullname']); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="../auth/profile.php"><?php echo e(t('nav.profile_settings', 'Profile Settings')); ?></a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../auth/logout.php"><?php echo e(t('nav.logout', 'Logout')); ?></a></li>
                        </ul>
                    </div>
                </div>
            </header>
            <main class="user-content">
    <?php elseif ($is_admin_area): ?>
    <div class="premium-admin-shell">
        <?php include '../includes/admin-sidebar.php'; ?>
        <main class="premium-admin-content">
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
                    <li class="nav-item">
                        <div class="language-switcher navbar-language" aria-label="<?php echo e(t('lang.label', 'Language')); ?>">
                            <a href="<?php echo e(tugonLanguageUrl('en')); ?>" class="<?php echo $current_language === 'en' ? 'active' : ''; ?>">EN</a>
                            <a href="<?php echo e(tugonLanguageUrl('fil')); ?>" class="<?php echo $current_language === 'fil' ? 'active' : ''; ?>">FIL</a>
                        </div>
                    </li>
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
    <div class="page-content">
    <?php endif; ?>
