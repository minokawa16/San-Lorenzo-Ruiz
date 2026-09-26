<?php
/**
 * Mobile-only parishioner navigation. Routes mirror the desktop sidebar.
 */
$mobile_nav_page = basename($_SERVER['PHP_SELF']);
$mobile_nav_items = [
    [
        'label' => t('nav.dashboard', 'Dashboard'),
        'href' => BASE_URL . 'users/index.php?view=dashboard',
        'icon' => 'fa-house',
        'theme' => 'dashboard',
        'description' => 'Overview and parish updates',
        'active' => in_array($mobile_nav_page, ['index.php', 'dashboard.php'], true) && strpos($_SERVER['PHP_SELF'], '/users/') !== false,
    ],
    [
        'label' => t('nav.certificates', 'Certificates'),
        'href' => BASE_URL . 'users/request-certificate.php',
        'icon' => 'fa-certificate',
        'theme' => 'certificates',
        'description' => 'Request official parish documents',
        'active' => $mobile_nav_page === 'request-certificate.php',
    ],
    [
        'label' => t('nav.blessings', 'Blessings'),
        'href' => BASE_URL . 'users/request-blessing.php',
        'icon' => 'fa-hands-praying',
        'theme' => 'blessings',
        'description' => 'Schedule a parish blessing',
        'active' => $mobile_nav_page === 'request-blessing.php',
    ],
    [
        'label' => t('nav.sacramental_services', 'Sacramental Services'),
        'href' => BASE_URL . 'users/request-service.php',
        'icon' => 'church-custom',
        'theme' => 'services',
        'description' => 'Baptism, marriage, funeral, and more',
        'active' => $mobile_nav_page === 'request-service.php',
    ],
    [
        'label' => t('nav.track_requests', 'Track Requests'),
        'href' => BASE_URL . 'users/my-requests.php',
        'icon' => 'fa-list-check',
        'theme' => 'requests',
        'description' => 'Follow your submitted requests',
        'active' => in_array($mobile_nav_page, ['my-requests.php', 'view-request.php'], true),
    ],
    [
        'label' => t('nav.schedule', 'Parish Calendar'),
        'href' => BASE_URL . 'users/view-schedule.php',
        'icon' => 'fa-calendar-days',
        'theme' => 'schedule',
        'description' => 'Masses, events, and appointments',
        'active' => $mobile_nav_page === 'view-schedule.php',
    ],
    [
        'label' => t('nav.announcements', 'Announcements'),
        'href' => BASE_URL . 'users/announcements.php',
        'icon' => 'fa-bullhorn',
        'theme' => 'announcements',
        'description' => 'Official parish news and notices',
        'active' => $mobile_nav_page === 'announcements.php',
    ],
    [
        'label' => t('nav.notifications', 'Notifications'),
        'href' => BASE_URL . 'users/notifications.php',
        'icon' => 'fa-bell',
        'theme' => 'notifications',
        'description' => 'View request and parish alerts',
        'active' => $mobile_nav_page === 'notifications.php',
        'count' => $unread_notification_count ?? 0,
    ],
    [
        'label' => t('nav.ai_assistant', 'AI Assistant'),
        'href' => BASE_URL . 'users/ai-assistant.php',
        'icon' => 'fa-robot',
        'theme' => 'assistant',
        'description' => 'Ask about parish services',
        'active' => $mobile_nav_page === 'ai-assistant.php',
    ],
    [
        'label' => t('nav.profile_settings', 'Profile Settings'),
        'href' => BASE_URL . 'auth/profile.php',
        'icon' => 'fa-user-gear',
        'theme' => 'profile',
        'description' => 'Account and security settings',
        'active' => in_array($mobile_nav_page, ['profile.php', 'change-password.php'], true),
    ],
    [
        'label' => t('nav.logout', 'Logout'),
        'href' => BASE_URL . 'auth/logout.php',
        'icon' => 'fa-right-from-bracket',
        'theme' => 'logout',
        'description' => 'Securely end your session',
        'active' => false,
    ],
];

// The phone dashboard intentionally exposes the eight most-used destinations.
// AI, profile, and logout remain available from their existing navigation entry points.
if (!empty($dashboard_mobile_quick_access)) {
    $mobile_nav_items = array_slice($mobile_nav_items, 0, 8);
}
?>

<nav class="user-mobile-card-nav" aria-label="Parishioner navigation">
    <?php foreach ($mobile_nav_items as $mobile_nav_item): ?>
        <a
            class="user-mobile-menu-card menu-theme-<?php echo e($mobile_nav_item['theme']); ?><?php echo $mobile_nav_item['active'] ? ' active' : ''; ?>"
            href="<?php echo e($mobile_nav_item['href']); ?>"
            <?php echo $mobile_nav_item['active'] ? 'aria-current="page"' : ''; ?>
        >
            <span class="user-mobile-menu-icon" aria-hidden="true">
                <?php if ($mobile_nav_item['icon'] === 'church-custom'): ?>
                    <svg class="custom-church-svg" viewBox="0 0 24 24" width="22" height="22" fill="currentColor">
                        <path d="M11 1h2v2h2v1.5h-2V6h-2V4.5H9V3h2V1z"/>
                        <path d="M12 5.2L4 10.8v1.6l1.5-1V21h13V11.4l1.5 1v-1.6L12 5.2zM8.5 12.5a1 1 0 0 1 1-1h.2a1 1 0 0 1 1 1v2.5a.8.8 0 0 1-.8.8h-.6a.8.8 0 0 1-.8-.8v-2.5zm4.8 7.3h-2.6v-4.2a1.3 1.3 0 0 1 1.3-1.3 1.3 1.3 0 0 1 1.3 1.3v4.2zm2.2-4.8a.8.8 0 0 1-.8.8h-.6a.8.8 0 0 1-.8-.8v-2.5a1 1 0 0 1 1-1h.2a1 1 0 0 1 1 1v2.5zM12 8a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4z"/>
                    </svg>
                <?php else: ?>
                    <i class="fas <?php echo e($mobile_nav_item['icon']); ?>"></i>
                <?php endif; ?>
                <?php if (!empty($mobile_nav_item['count'])): ?>
                    <span class="user-mobile-menu-count" aria-label="<?php echo intval($mobile_nav_item['count']); ?> unread">
                        <?php echo intval($mobile_nav_item['count']); ?>
                    </span>
                <?php endif; ?>
            </span>
            <span class="user-mobile-menu-label">
                <strong><?php echo e($mobile_nav_item['label']); ?></strong>
                <small><?php echo e($mobile_nav_item['description']); ?></small>
            </span>
            <span class="user-mobile-menu-affordance" aria-hidden="true">
                <i class="fas fa-chevron-right"></i>
            </span>
        </a>
    <?php endforeach; ?>
</nav>
