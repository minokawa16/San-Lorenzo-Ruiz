<?php
/**
 * Parishioner Navigation Configuration
 * Single source of truth for parishioner portal navigation.
 * Shared by the desktop sidebar and mobile module tile grid.
 */

if (!function_exists('getParishionerNavConfig')) {
    /**
     * Returns structured sections and flat modules for parishioner navigation.
     *
     * @param mysqli|null $conn Database connection
     * @param int|null $user_id Logged-in user ID
     * @param int $pending_count Optional pending request count for badge
     * @return array Array with 'sections' and 'modules'
     */
    function getParishionerNavConfig($conn = null, $user_id = null, $pending_count = 0) {
        if ($user_id === null && isset($_SESSION['user_id'])) {
            $user_id = (int)$_SESSION['user_id'];
        }

        $unread_notifications = 0;
        if ($conn && $user_id && function_exists('getUnreadNotificationCount')) {
            $unread_notifications = (int)getUnreadNotificationCount($conn, $user_id);
        }

        $current_script = basename($_SERVER['PHP_SELF'] ?? '');
        $current_uri = $_SERVER['PHP_SELF'] ?? '';
        $is_users_dir = strpos($current_uri, '/users/') !== false;

        $t = static function ($key, $default) {
            return function_exists('t') ? t($key, $default) : $default;
        };

        $base_url = defined('BASE_URL') ? BASE_URL : '/ParishSystem/';

        // Canonical navigation structure
        $sections = [
            'main' => [
                'label' => $t('nav.main_menu', 'Main Menu'),
                'items' => [
                    [
                        'key'         => 'dashboard',
                        'title'       => $t('nav.dashboard', 'Dashboard'),
                        'short_title' => 'Dashboard',
                        'url'         => $base_url . 'users/index.php',
                        'icon'        => 'fa-table-cells-large',
                        'active'      => in_array($current_script, ['index.php', 'dashboard.php'], true) && $is_users_dir,
                        'badge'       => 0,
                        'badge_class' => 'bg-secondary',
                        'tooltip'     => $t('nav.dashboard', 'Dashboard'),
                    ],
                    [
                        'key'            => 'requests_group',
                        'title'          => $t('nav.my_requests', 'My Requests'),
                        'icon'           => 'fa-layer-group',
                        'is_collapsible' => true,
                        'subitems'       => [
                            [
                                'key'         => 'certificates',
                                'title'       => $t('nav.certificates', 'Certificates'),
                                'short_title' => 'Certificates',
                                'url'         => $base_url . 'users/request-certificate.php',
                                'icon'        => 'fa-certificate',
                                'active'      => $current_script === 'request-certificate.php',
                                'badge'       => 0,
                                'badge_class' => '',
                                'tooltip'     => $t('nav.certificates', 'Certificates'),
                            ],
                            [
                                'key'         => 'blessings',
                                'title'       => $t('nav.blessings', 'Blessings'),
                                'short_title' => 'Blessings',
                                'url'         => $base_url . 'users/request-blessing.php',
                                'icon'        => 'fa-hands-praying',
                                'active'      => $current_script === 'request-blessing.php',
                                'badge'       => 0,
                                'badge_class' => '',
                                'tooltip'     => $t('nav.blessings', 'Blessings'),
                            ],
                            [
                                'key'         => 'services',
                                'title'       => $t('nav.sacramental_services', 'Sacramental Services'),
                                'short_title' => 'Services',
                                'url'         => $base_url . 'users/request-service.php',
                                'icon'        => 'fa-church',
                                'active'      => $current_script === 'request-service.php',
                                'badge'       => 0,
                                'badge_class' => '',
                                'tooltip'     => $t('nav.sacramental_services', 'Sacramental Services'),
                            ],
                            [
                                'key'         => 'requests',
                                'title'       => $t('nav.track_requests', 'Track Requests'),
                                'short_title' => 'Requests',
                                'url'         => $base_url . 'users/my-requests.php',
                                'icon'        => 'fa-list-check',
                                'active'      => in_array($current_script, ['my-requests.php', 'view-request.php'], true),
                                'badge'       => (int)$pending_count,
                                'badge_class' => 'bg-warning text-dark',
                                'tooltip'     => $t('nav.track_requests', 'Track Requests'),
                            ],
                        ],
                    ],
                ],
            ],
            'communication' => [
                'label' => $t('nav.communication', 'Communication'),
                'items' => [
                    [
                        'key'         => 'calendar',
                        'title'       => $t('nav.schedule', 'Parish Calendar'),
                        'short_title' => 'Calendar',
                        'url'         => $base_url . 'users/view-schedule.php',
                        'icon'        => 'fa-calendar-days',
                        'active'      => $current_script === 'view-schedule.php',
                        'badge'       => 0,
                        'badge_class' => '',
                        'tooltip'     => $t('nav.schedule', 'Parish Calendar'),
                    ],
                    [
                        'key'         => 'announcements',
                        'title'       => $t('nav.announcements', 'Announcements'),
                        'short_title' => 'Announcements',
                        'url'         => $base_url . 'users/announcements.php',
                        'icon'        => 'fa-bullhorn',
                        'active'      => $current_script === 'announcements.php',
                        'badge'       => 0,
                        'badge_class' => '',
                        'tooltip'     => $t('nav.announcements', 'Announcements'),
                    ],
                    [
                        'key'         => 'organization',
                        'title'       => $t('nav.organization', 'Parish Organization Chart'),
                        'short_title' => 'Organization',
                        'url'         => $base_url . 'users/organization.php',
                        'icon'        => 'fa-sitemap',
                        'active'      => $current_script === 'organization.php',
                        'badge'       => 0,
                        'badge_class' => '',
                        'tooltip'     => $t('nav.organization', 'Parish Organization Chart'),
                    ],
                    [
                        'key'         => 'notifications',
                        'title'       => $t('nav.notifications', 'Notifications'),
                        'short_title' => 'Notifications',
                        'url'         => $base_url . 'users/notifications.php',
                        'icon'        => 'fa-bell',
                        'active'      => $current_script === 'notifications.php',
                        'badge'       => (int)$unread_notifications,
                        'badge_class' => 'bg-danger',
                        'tooltip'     => $t('nav.notifications', 'Notifications'),
                    ],
                    [
                        'key'         => 'ai_assistant',
                        'title'       => $t('nav.ai_assistant', 'AI Assistant'),
                        'short_title' => 'AI Assistant',
                        'url'         => $base_url . 'users/ai-assistant.php',
                        'icon'        => 'fa-robot',
                        'active'      => $current_script === 'ai-assistant.php',
                        'badge'       => 0,
                        'badge_class' => '',
                        'tooltip'     => $t('nav.ai_assistant', 'AI Assistant'),
                        'is_ai'       => true,
                    ],
                ],
            ],
            'account' => [
                'label' => $t('nav.account', 'Account'),
                'items' => [
                    [
                        'key'         => 'profile',
                        'title'       => $t('nav.profile_settings', 'Profile Settings'),
                        'short_title' => 'Profile',
                        'url'         => $base_url . 'auth/profile.php',
                        'icon'        => 'fa-user-gear',
                        'active'      => in_array($current_script, ['profile.php', 'change-password.php'], true),
                        'badge'       => 0,
                        'badge_class' => '',
                        'tooltip'     => $t('nav.profile_settings', 'Profile Settings'),
                    ],
                    [
                        'key'         => 'help',
                        'title'       => $t('nav.help', 'Help & Guide'),
                        'short_title' => 'Help',
                        'url'         => $base_url . 'users/help.php',
                        'icon'        => 'fa-circle-question',
                        'active'      => $current_script === 'help.php',
                        'badge'       => 0,
                        'badge_class' => '',
                        'tooltip'     => $t('nav.help', 'Help & Guide'),
                    ],
                ],
            ],
        ];

        // Derive flat modules for the mobile grid directly from the configuration
        // In natural user order: Dashboard, Requests, Certificates, Blessings, Services,
        // Calendar, Announcements, Organization, Notifications, AI Assistant, Profile, Help
        $modules = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                if (!empty($item['is_collapsible']) && !empty($item['subitems'])) {
                    // Include submodules
                    foreach ($item['subitems'] as $sub) {
                        $modules[] = $sub;
                    }
                } else {
                    $modules[] = $item;
                }
            }
        }

        return [
            'sections' => $sections,
            'modules'  => $modules,
            'unread_notifications' => $unread_notifications,
        ];
    }
}
