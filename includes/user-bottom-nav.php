<?php
/** Compact mobile navigation for signed-in parishioners. */
$bottom_nav_page = basename($_SERVER['PHP_SELF']);
$bottom_nav_items = [
    [
        'label' => 'Home',
        'href' => BASE_URL . 'users/index.php',
        'icon' => 'fa-house',
        'active' => in_array($bottom_nav_page, ['index.php', 'dashboard.php'], true),
    ],
    [
        'label' => 'Requests',
        'href' => BASE_URL . 'users/my-requests.php',
        'icon' => 'fa-file-lines',
        'active' => in_array($bottom_nav_page, [
            'request-certificate.php',
            'request-blessing.php',
            'request-service.php',
            'my-requests.php',
            'view-request.php',
        ], true),
    ],
    [
        'label' => 'Alerts',
        'href' => BASE_URL . 'users/notifications.php',
        'icon' => 'fa-bell',
        'active' => $bottom_nav_page === 'notifications.php',
    ],
    [
        'label' => 'Profile',
        'href' => BASE_URL . 'auth/profile.php',
        'icon' => 'fa-user',
        'active' => in_array($bottom_nav_page, ['profile.php', 'change-password.php'], true),
    ],
];
$bottom_nav_unread = isset($conn) && function_exists('getUnreadNotificationCount')
    ? getUnreadNotificationCount($conn, intval($_SESSION['user_id'] ?? 0))
    : 0;
?>

<nav class="user-bottom-nav" aria-label="Primary mobile navigation">
    <?php foreach ($bottom_nav_items as $item): ?>
        <a class="user-bottom-nav-item<?php echo $item['active'] ? ' active' : ''; ?>"
           href="<?php echo e($item['href']); ?>"
           <?php echo $item['active'] ? 'aria-current="page"' : ''; ?>>
            <span class="user-bottom-nav-icon" aria-hidden="true">
                <i class="fas <?php echo e($item['icon']); ?>"></i>
                <?php if ($item['label'] === 'Alerts' && $bottom_nav_unread > 0): ?>
                    <span class="user-bottom-nav-badge"><?php echo min(99, $bottom_nav_unread); ?></span>
                <?php endif; ?>
            </span>
            <span><?php echo e($item['label']); ?></span>
        </a>
    <?php endforeach; ?>
</nav>
