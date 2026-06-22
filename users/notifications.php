<?php
/**
 * Notification System - Displays account alerts, request updates, and parish messages.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();
if (!isUser()) {
    redirect('../auth/login.php');
}

$user_id = intval($_SESSION['user_id']);
$success = '';
$error = '';

function notificationTypeCatalog() {
    return [
        'payment' => [
            'label' => 'Payment Update',
            'icon' => 'fa-receipt',
            'tone' => 'success',
            'keywords' => ['payment receipt', 'payment', 'receipt reviewed', 'verified payment']
        ],
        'file' => [
            'label' => 'File Released',
            'icon' => 'fa-file-arrow-down',
            'tone' => 'info',
            'keywords' => ['file available', 'parish office file', 'released certificate', 'download']
        ],
        'approved' => [
            'label' => 'Approved Request',
            'icon' => 'fa-circle-check',
            'tone' => 'success',
            'keywords' => ['approved']
        ],
        'processing' => [
            'label' => 'In Progress',
            'icon' => 'fa-spinner',
            'tone' => 'primary',
            'keywords' => ['processing', 'in progress']
        ],
        'rejected' => [
            'label' => 'Needs Attention',
            'icon' => 'fa-circle-exclamation',
            'tone' => 'danger',
            'keywords' => ['rejected', 'not approved', 'needs correction']
        ],
        'submitted' => [
            'label' => 'Request Submitted',
            'icon' => 'fa-file-circle-plus',
            'tone' => 'primary',
            'keywords' => ['created', 'submitted']
        ],
        'request' => [
            'label' => 'Request Update',
            'icon' => 'fa-list-check',
            'tone' => 'primary',
            'keywords' => ['request status', 'request', 'reference']
        ],
        'announcement' => [
            'label' => 'Parish Announcement',
            'icon' => 'fa-bullhorn',
            'tone' => 'info',
            'keywords' => ['announcement']
        ],
        'schedule' => [
            'label' => 'Schedule Update',
            'icon' => 'fa-calendar-check',
            'tone' => 'warning',
            'keywords' => ['schedule', 'reservation', 'calendar']
        ],
        'account' => [
            'label' => 'Account Notice',
            'icon' => 'fa-user-shield',
            'tone' => 'secondary',
            'keywords' => ['account', 'verification', 'otp', 'login', 'profile']
        ],
        'notice' => [
            'label' => 'General Notice',
            'icon' => 'fa-bell',
            'tone' => 'secondary',
            'keywords' => []
        ]
    ];
}

// Notification Type Meta Function - Centralizes labels, icons, colors, and filters.
function notificationTypeMeta($notification) {
    $text = strtolower(($notification['title'] ?? '') . ' ' . ($notification['message'] ?? ''));

    foreach (notificationTypeCatalog() as $key => $meta) {
        foreach ($meta['keywords'] as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return [
                    'key' => $key,
                    'label' => $meta['label'],
                    'icon' => $meta['icon'],
                    'tone' => $meta['tone']
                ];
            }
        }
    }

    $fallback = notificationTypeCatalog()['notice'];
    return [
        'key' => 'notice',
        'label' => $fallback['label'],
        'icon' => $fallback['icon'],
        'tone' => $fallback['tone']
    ];
}

// Notification Group Label Function - Documents this helper's role in the parish management workflow.
function notificationGroupLabel($date) {
    $created = new DateTime(date('Y-m-d', strtotime($date)));
    $today = new DateTime(date('Y-m-d'));
    $days = (int) $created->diff($today)->format('%r%a');

    if ($days === 0) {
        return 'Today';
    }
    if ($days === 1) {
        return 'Yesterday';
    }
    if ($days <= 7) {
        return 'This Week';
    }
    return 'Earlier';
}

// Notification Action URL Function - Documents this helper's role in the parish management workflow.
function notificationActionUrl($notification) {
    $text = strtolower(($notification['title'] ?? '') . ' ' . ($notification['message'] ?? ''));
    if (strpos($text, 'payment') !== false || strpos($text, 'file') !== false || strpos($text, 'certificate') !== false || strpos($text, 'request') !== false || strpos($text, 'reference') !== false) {
        return 'my-requests.php';
    }
    if (strpos($text, 'announcement') !== false) {
        return 'announcements.php';
    }
    if (strpos($text, 'schedule') !== false || strpos($text, 'reservation') !== false) {
        return 'view-schedule.php';
    }
    return 'index.php';
}

// Notification Count Function - Documents this helper's role in the parish management workflow.
function notificationCount($conn, $sql) {
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }
    $row = $result->fetch_assoc();
    return intval($row['count'] ?? 0);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_all_read') {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $success = $stmt->execute() ? 'All notifications marked as read.' : 'Unable to update notifications.';
            $stmt->close();
        } else {
            $error = 'Unable to update notifications.';
        }
    }

    if ($action === 'mark_read') {
        $notification_id = intval($_POST['notification_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $notification_id, $user_id);
            $success = $stmt->execute() ? 'Notification marked as read.' : 'Unable to update notification.';
            $stmt->close();
        } else {
            $error = 'Unable to update notification.';
        }
    }

    if ($action === 'delete_notification') {
        $notification_id = intval($_POST['notification_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $notification_id, $user_id);
            $success = $stmt->execute() ? 'Notification deleted.' : 'Unable to delete notification.';
            $stmt->close();
        } else {
            $error = 'Unable to delete notification.';
        }
    }
}

$search = trim($_GET['q'] ?? '');
$read_filter = $_GET['read'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$sort = $_GET['sort'] ?? 'latest';
$allowed_read_filters = ['all', 'unread', 'read'];
$notification_type_catalog = notificationTypeCatalog();
$allowed_type_filters = array_merge(['all'], array_keys($notification_type_catalog));
$allowed_sorts = ['latest', 'oldest'];
if (!in_array($read_filter, $allowed_read_filters, true)) {
    $read_filter = 'all';
}
if (!in_array($type_filter, $allowed_type_filters, true)) {
    $type_filter = 'all';
}
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'latest';
}

$notifications = [];
$where = ['user_id = ?'];
$types = 'i';
$params = [$user_id];

if ($read_filter === 'unread') {
    $where[] = 'is_read = 0';
} elseif ($read_filter === 'read') {
    $where[] = 'is_read = 1';
}

if ($search !== '') {
    $where[] = '(title LIKE ? OR message LIKE ?)';
    $search_like = '%' . $search . '%';
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= 'ss';
}

$order_sql = $sort === 'oldest' ? 'created_at ASC' : 'created_at DESC';
$stmt = $conn->prepare("SELECT notification_id, title, message, is_read, created_at FROM notifications WHERE " . implode(' AND ', $where) . " ORDER BY $order_sql");
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
}

if ($type_filter !== 'all') {
    $notifications = array_values(array_filter($notifications, function($notification) use ($type_filter) {
        return notificationTypeMeta($notification)['key'] === $type_filter;
    }));
}

$total_count = notificationCount($conn, "SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id");
$unread_count = notificationCount($conn, "SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND is_read = 0");
$read_count = max(0, $total_count - $unread_count);
$today_count = notificationCount($conn, "SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND DATE(created_at) = CURDATE()");

$grouped_notifications = [];
foreach ($notifications as $notification) {
    $grouped_notifications[notificationGroupLabel($notification['created_at'])][] = $notification;
}

$page_title = 'Notifications';
$breadcrumbs = [
    'Dashboard' => 'index.php',
    'Notifications' => null
];
?>
<?php include '../templates/header.php'; ?>

<?php include '../includes/breadcrumb.php'; ?>
<?php include '../includes/back_button.php'; ?>

<style>
    .notification-center {
        width: min(100%, 1300px);
        max-width: 1300px;
        margin: 0 auto;
        padding: 0 clamp(12px, 2vw, 24px);
    }

    .notifications-page-shell {
        width: 100%;
        margin: 0;
        padding: 0;
    }

    .notification-hero,
    .notification-toolbar,
    .notification-card,
    .notification-empty {
        border: 1px solid rgba(23, 32, 51, 0.1);
        border-radius: 8px;
        background: #ffffff;
        box-shadow: 0 14px 34px rgba(30, 41, 59, 0.08);
    }

    .notification-hero {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 30px clamp(18px, 3vw, 34px);
        margin: 0 auto 18px;
        border-top: 4px solid #d7ad43;
        background: linear-gradient(135deg, #ffffff, #fff8df 52%, #eef5fb);
    }

    .notification-hero-inner {
        width: 100%;
        max-width: 820px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
    }

    .notification-hero h1 {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 12px;
        color: #172033;
        font-size: 1.85rem;
        font-weight: 900;
        margin: 0;
    }

    .notification-hero p {
        max-width: 760px;
        color: #667085;
        margin: 0 auto;
        line-height: 1.6;
    }

    .notification-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        width: 100%;
        max-width: 980px;
        margin: 22px auto 0;
    }

    .notification-stat {
        padding: 13px;
        border: 1px solid rgba(23, 32, 51, 0.08);
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.72);
        text-align: center;
    }

    .notification-stat span {
        display: block;
        color: #667085;
        font-size: 0.76rem;
        font-weight: 850;
        text-transform: uppercase;
    }

    .notification-stat strong {
        color: #172033;
        font-size: 1.6rem;
    }

    .notification-toolbar {
        width: 100%;
        padding: 18px;
        margin: 0 auto 18px;
    }

    .notification-toolbar .row {
        justify-content: center;
    }

    .toolbar-control {
        min-height: 44px;
        border-radius: 8px;
        border-color: #dfe4ea;
    }

    .input-with-icon {
        position: relative;
    }

    .input-with-icon i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        z-index: 1;
    }

    .input-with-icon .form-control {
        padding-left: 42px;
    }

    .notification-group-title {
        margin: 22px 0 10px;
        color: #172033;
        font-size: 0.9rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .notification-list {
        display: grid;
        gap: 12px;
        width: 100%;
    }

    .notification-card {
        position: relative;
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 16px;
        padding: 18px;
        border-left: 5px solid #cbd5e1;
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .notification-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 18px 42px rgba(30, 41, 59, 0.12);
    }

    .notification-card.unread {
        border-left-color: #3b82f6;
        background: linear-gradient(90deg, #f8fbff, #ffffff);
    }

    .notification-card.read {
        opacity: 0.82;
    }

    .notification-icon {
        width: 44px;
        height: 44px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: #eef5fb;
        color: #17446a;
    }

    .notification-main {
        min-width: 0;
    }

    .notification-title-row {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 4px;
    }

    .notification-card h3 {
        margin: 0;
        color: #172033;
        font-size: 1rem;
        font-weight: 900;
    }

    .notification-card.read h3 {
        font-weight: 750;
    }

    .notification-card p {
        margin: 5px 0 8px;
        color: #475569;
        line-height: 1.55;
    }

    .notification-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 12px;
        color: #667085;
        font-size: 0.82rem;
    }

    .notification-type {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        min-height: 28px;
        padding: 4px 9px;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 850;
    }

    .notification-type.success { color: #166534; background: #dcfce7; }
    .notification-type.danger { color: #9f1239; background: #ffe4e6; }
    .notification-type.primary { color: #17446a; background: #eef5fb; }
    .notification-type.info { color: #075985; background: #e0f2fe; }
    .notification-type.warning { color: #80611b; background: #fff8df; }
    .notification-type.secondary { color: #475569; background: #f1f5f9; }

    .read-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-height: 26px;
        padding: 3px 8px;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 800;
        color: #475569;
        background: #f8fafc;
    }

    .read-label.unread {
        color: #17446a;
        background: #dbeafe;
    }

    .unread-dot {
        width: 10px;
        height: 10px;
        display: inline-block;
        border-radius: 50%;
        background: #3b82f6;
        box-shadow: 0 0 0 5px rgba(59, 130, 246, 0.16);
    }

    .notification-actions {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .notification-empty {
        padding: 46px 18px;
        text-align: center;
        color: #667085;
    }

    .notification-empty i {
        width: 64px;
        height: 64px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 14px;
        border-radius: 8px;
        color: #17446a;
        background: #eef5fb;
        font-size: 1.7rem;
    }

    @media (max-width: 768px) {
        .notification-stats {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .notification-card {
            grid-template-columns: 1fr;
        }

        .notification-actions {
            justify-content: flex-start;
        }
    }

    @media (max-width: 576px) {
        .notification-center {
            padding: 0 10px;
        }

        .notification-stats {
            grid-template-columns: 1fr;
        }

        .notification-hero {
            padding: 24px 14px;
        }

        .notification-hero h1 {
            font-size: 1.45rem;
        }
    }
</style>

<div class="notifications-page-shell mt-4">
    <div class="notification-center">
        <section class="notification-hero">
            <div class="notification-hero-inner">
                <div>
                    <h1><i class="fas fa-bell"></i> Notifications Center</h1>
                    <p>Stay updated with request progress, parish announcements, sacramental updates, verification reminders, and important notices.</p>
                </div>
                <?php if ($unread_count > 0): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="fas fa-check-double"></i> Mark all as read
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="notification-stats">
                <div class="notification-stat"><span>Total</span><strong><?php echo number_format($total_count); ?></strong></div>
                <div class="notification-stat"><span>Unread</span><strong><?php echo number_format($unread_count); ?></strong></div>
                <div class="notification-stat"><span>Read</span><strong><?php echo number_format($read_count); ?></strong></div>
                <div class="notification-stat"><span>Today</span><strong><?php echo number_format($today_count); ?></strong></div>
            </div>
        </section>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

        <form method="GET" class="notification-toolbar">
            <div class="row g-2 align-items-end">
                <div class="col-lg-5">
                    <label class="form-label">Search notifications</label>
                    <div class="input-with-icon">
                        <i class="fas fa-search"></i>
                        <input type="search" class="form-control toolbar-control" name="q" value="<?php echo e($search); ?>" placeholder="Search notifications, requests, or updates...">
                    </div>
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label">Read status</label>
                    <select name="read" class="form-select toolbar-control">
                        <option value="all" <?php echo $read_filter === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="unread" <?php echo $read_filter === 'unread' ? 'selected' : ''; ?>>Unread only</option>
                        <option value="read" <?php echo $read_filter === 'read' ? 'selected' : ''; ?>>Read only</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label">Category</label>
                    <select name="type" class="form-select toolbar-control">
                        <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All types</option>
                        <?php foreach ($notification_type_catalog as $key => $type_meta): ?>
                            <option value="<?php echo e($key); ?>" <?php echo $type_filter === $key ? 'selected' : ''; ?>><?php echo e($type_meta['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-4">
                    <label class="form-label">Sort</label>
                    <select name="sort" class="form-select toolbar-control">
                        <option value="latest" <?php echo $sort === 'latest' ? 'selected' : ''; ?>>Latest first</option>
                        <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                    </select>
                </div>
                <div class="col-lg-1 d-grid">
                    <button type="submit" class="btn btn-primary toolbar-control"><i class="fas fa-filter"></i></button>
                </div>
            </div>
        </form>

        <?php if (!empty($grouped_notifications)): ?>
            <?php foreach ($grouped_notifications as $group => $items): ?>
                <h2 class="notification-group-title"><?php echo e($group); ?></h2>
                <div class="notification-list">
                    <?php foreach ($items as $notification): ?>
                        <?php $meta = notificationTypeMeta($notification); ?>
                        <article class="notification-card <?php echo !$notification['is_read'] ? 'unread' : 'read'; ?>">
                            <div class="notification-icon"><i class="fas <?php echo e($meta['icon']); ?>"></i></div>
                            <div class="notification-main">
                                <div class="notification-title-row">
                                    <?php if (!$notification['is_read']): ?><span class="unread-dot"></span><?php endif; ?>
                                    <h3><?php echo e($notification['title']); ?></h3>
                                    <span class="notification-type <?php echo e($meta['tone']); ?>"><i class="fas <?php echo e($meta['icon']); ?>"></i> <?php echo e($meta['label']); ?></span>
                                    <span class="read-label <?php echo !$notification['is_read'] ? 'unread' : ''; ?>">
                                        <i class="fas <?php echo $notification['is_read'] ? 'fa-envelope-open' : 'fa-envelope'; ?>"></i>
                                        <?php echo $notification['is_read'] ? 'Read' : 'Unread'; ?>
                                    </span>
                                </div>
                                <p><?php echo e($notification['message']); ?></p>
                                <div class="notification-meta">
                                    <span><i class="fas fa-clock"></i> <?php echo e(formatDateTime($notification['created_at'])); ?></span>
                                </div>
                            </div>
                            <div class="notification-actions">
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo e(notificationActionUrl($notification)); ?>"><i class="fas fa-arrow-up-right-from-square"></i> View</a>
                                <?php if (!$notification['is_read']): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="mark_read">
                                        <input type="hidden" name="notification_id" value="<?php echo intval($notification['notification_id']); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success"><i class="fas fa-check"></i> Mark Read</button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" onsubmit="return confirm('Delete this notification?');">
                                    <input type="hidden" name="action" value="delete_notification">
                                    <input type="hidden" name="notification_id" value="<?php echo intval($notification['notification_id']); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="notification-empty">
                <i class="fas fa-bell-slash"></i>
                <h5>You’re all caught up!</h5>
                <p class="mb-0">No notifications match your current filters. New request and parish updates will appear here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
