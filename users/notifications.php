<?php
/**
 * Notification System - Displays account alerts, request updates, and parish messages.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
require_once '../services/NotificationService.php';

requireLogin();
if (!isUser()) {
    redirect('../auth/login.php');
}

$user_id = intval($_SESSION['user_id']);
$success = '';
$error = '';

function notificationIconMeta($notification) {
    $type = (string)($notification['notification_type'] ?? 'system');
    if (str_starts_with($type, 'payment_')) {
        return ['icon' => 'fa-receipt', 'tone' => 'success'];
    }
    if (str_contains($type, 'approved')) {
        return ['icon' => 'fa-circle-check', 'tone' => 'success'];
    }
    if (str_contains($type, 'rejected')) {
        return ['icon' => 'fa-circle-exclamation', 'tone' => 'danger'];
    }
    if (str_starts_with($type, 'certificate_') || $type === 'file_released') {
        return ['icon' => 'fa-file-lines', 'tone' => 'info'];
    }
    if (str_starts_with($type, 'request_')) {
        return ['icon' => 'fa-list-check', 'tone' => 'primary'];
    }
    if (str_starts_with($type, 'announcement_')) {
        return ['icon' => 'fa-bullhorn', 'tone' => 'warning'];
    }
    if (str_starts_with($type, 'reservation_') || str_starts_with($type, 'schedule_')) {
        return ['icon' => 'fa-calendar-check', 'tone' => 'warning'];
    }
    if (str_starts_with($type, 'ai_')) {
        return ['icon' => 'fa-robot', 'tone' => 'ai'];
    }
    return ['icon' => 'fa-bell', 'tone' => 'info'];
}

function notificationGroupLabel($date) {
    $created = new DateTime(date('Y-m-d', strtotime($date)));
    $today = new DateTime(date('Y-m-d'));
    $days = (int) $created->diff($today)->format('%r%a');

    if ($days === 0) {
        return 'TODAY';
    }
    if ($days === 1) {
        return 'YESTERDAY';
    }
    if ($days <= 7) {
        return 'THIS WEEK';
    }
    return 'EARLIER';
}

function notificationActionUrl($notification, $conn = null) {
    return NotificationService::resolveActionUrl(
        $notification['action_key'] ?? null,
        $notification['entity_type'] ?? null,
        $notification['entity_id'] ?? null,
        $notification,
        $conn
    );
}

function notificationShortMessage($message, $limit = 220) {
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string) $message)));
    if (function_exists('mb_strlen') && mb_strlen($plain) > $limit) {
        return mb_substr($plain, 0, $limit - 1) . '...';
    }
    if (!function_exists('mb_strlen') && strlen($plain) > $limit) {
        return substr($plain, 0, $limit - 1) . '...';
    }
    return $plain;
}

function notificationReferenceNumber($notification) {
    return !empty($notification['entity_type']) && !empty($notification['entity_id']) ? ucfirst($notification['entity_type']) . ' #' . (int)$notification['entity_id'] : '';
}

function notificationCount($conn, $sql) {
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }
    $row = $result->fetch_assoc();
    return intval($row['count'] ?? 0);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';
    $notificationService = new NotificationService($conn);

    if ($action === 'mark_all_read') {
        $notificationService->markAllRead($user_id);
        $success = 'All notifications marked as read.';
    }

    if ($action === 'mark_read') {
        $notification_id = intval($_POST['notification_id'] ?? 0);
        $notificationService->transition($notification_id, $user_id, 'read');
        $success = 'Notification marked as read.';
    }

    if ($action === 'archive_notification' || $action === 'delete_notification') {
        $notification_id = intval($_POST['notification_id'] ?? 0);
        $notificationService->transition($notification_id, $user_id, $action === 'archive_notification' ? 'archived' : 'deleted');
        $success = $action === 'archive_notification' ? 'Notification archived.' : 'Notification removed.';
    }
}

$search = trim($_GET['q'] ?? '');
$read_filter = $_GET['read'] ?? 'all';
$sort = $_GET['sort'] ?? 'latest';
$allowed_read_filters = ['all', 'unread', 'read', 'archived'];
$allowed_sorts = ['latest', 'oldest'];

if (!in_array($read_filter, $allowed_read_filters, true)) {
    $read_filter = 'all';
}
if (!in_array($sort, $allowed_sorts, true)) {
    $sort = 'latest';
}

$notifications = [];
$where = ['user_id = ?', "state <> 'deleted'"];
$types = 'i';
$params = [$user_id];

if ($read_filter === 'unread') {
    $where[] = "state = 'unread'";
} elseif ($read_filter === 'read') {
    $where[] = "state = 'read'";
} elseif ($read_filter === 'archived') {
    $where[] = "state = 'archived'";
} else {
    // 'all' active (unread + read, excluding archived)
    $where[] = "state <> 'archived'";
}

if ($search !== '') {
    $where[] = '(title LIKE ? OR message LIKE ?)';
    $search_like = '%' . $search . '%';
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= 'ss';
}

$order_sql = $sort === 'oldest' ? 'created_at ASC' : 'created_at DESC';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 30;

$countStmt = $conn->prepare("SELECT COUNT(*) count FROM notifications WHERE " . implode(' AND ', $where));
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$filtered_total = (int)($countStmt->get_result()->fetch_assoc()['count'] ?? 0);
$countStmt->close();

$total_pages = max(1, (int)ceil($filtered_total / $per_page));
$offset = ($page - 1) * $per_page;

$stmt = $conn->prepare("SELECT notification_id, notification_type, title, message, entity_type, entity_id, action_key, state, is_read, created_at FROM notifications WHERE " . implode(' AND ', $where) . " ORDER BY $order_sql LIMIT ? OFFSET ?");
if ($stmt) {
    $queryTypes = $types . 'ii';
    $queryParams = array_merge($params, [$per_page, $offset]);
    $stmt->bind_param($queryTypes, ...$queryParams);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
}

$total_count = notificationCount($conn, "SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND state <> 'deleted' AND state <> 'archived'");
$unread_count = notificationCount($conn, "SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND state = 'unread'");
$read_count = notificationCount($conn, "SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND state = 'read'");
$archived_count = notificationCount($conn, "SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND state = 'archived'");

$grouped_notifications = [];
foreach ($notifications as $notification) {
    $grouped_notifications[notificationGroupLabel($notification['created_at'])][] = $notification;
}

$page_title = 'Notifications';
$body_extra_class = 'user-notifications-page';
?>
<?php include '../templates/header.php'; ?>

<?php include '../includes/back_button.php'; ?>

<style>
    /* Full Width Notification Workspace */
    .notification-workspace {
        width: 100%;
        max-width: 1280px;
        margin: 0 auto;
        padding: 0 clamp(12px, 2vw, 24px);
    }

    .notification-layout {
        width: 100%;
        display: block;
    }

    /* Single Consolidated Control Panel */
    .notification-control-panel {
        background: #FFFFFF !important;
        border: 1px solid #E8E2D5 !important;
        border-radius: 16px !important;
        box-shadow: 0 4px 18px rgba(44, 38, 30, 0.04) !important;
        padding: 20px 22px !important;
        margin-bottom: 24px !important;
    }

    .notification-status-chips {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-bottom: 16px;
        margin-bottom: 16px;
        border-bottom: 1px solid #F0E9DC;
    }

    .status-chip-group {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
    }

    .status-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-height: 36px;
        padding: 6px 14px;
        border-radius: 9999px;
        font-size: 0.84rem;
        font-weight: 700;
        text-decoration: none;
        transition: all 0.18s ease;
        border: 1px solid #D8D0C2;
        background: #FAF7F2;
        color: #4A443B;
    }

    .status-chip:hover {
        background: #F4EFE6;
        color: #2E261D;
        border-color: #C89B3C;
    }

    .status-chip.active {
        background: #9A7B38 !important;
        border-color: #8A681B !important;
        color: #FFFFFF !important;
        box-shadow: 0 2px 8px rgba(154, 123, 56, 0.25);
    }

    .status-chip .badge {
        font-size: 0.75rem;
        padding: 3px 8px;
        border-radius: 9999px;
    }

    .status-chip.active .badge {
        background: #FFFFFF !important;
        color: #2E261D !important;
    }

    .btn-mark-all-read {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 0.82rem;
        font-weight: 700;
        color: #9A7B38;
        background: #FAF7F2;
        border: 1px solid #D8D0C2;
        padding: 6px 14px;
        border-radius: 9999px;
        cursor: pointer;
        transition: all 0.18s ease;
    }

    .btn-mark-all-read:hover {
        background: #9A7B38;
        border-color: #8A681B;
        color: #FFFFFF;
    }

    /* Filter Form Controls */
    .notification-filter-form {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 180px auto;
        gap: 12px;
        align-items: center;
    }

    @media (max-width: 768px) {
        .notification-filter-form {
            grid-template-columns: 1fr;
        }
    }

    .filter-input-wrap {
        position: relative;
        width: 100%;
    }

    .filter-input-wrap i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #8C8273;
        font-size: 0.88rem;
        pointer-events: none;
    }

    .filter-input {
        width: 100%;
        height: 42px;
        padding: 8px 14px 8px 38px;
        background: #FAF7F2;
        border: 1px solid #D8D0C2;
        border-radius: 10px;
        font-size: 0.88rem;
        color: #1F2937;
        transition: all 0.18s ease;
    }

    .filter-input:focus,
    .filter-select:focus {
        background: #FFFFFF;
        border-color: #9A7B38;
        box-shadow: 0 0 0 3px rgba(154, 123, 56, 0.15);
        outline: none;
    }

    .filter-select {
        width: 100%;
        height: 42px;
        padding: 8px 14px;
        background: #FAF7F2;
        border: 1px solid #D8D0C2;
        border-radius: 10px;
        font-size: 0.88rem;
        color: #1F2937;
        font-weight: 600;
        cursor: pointer;
    }

    .btn-filter-submit {
        height: 42px;
        padding: 0 18px;
        background: #9A7B38;
        border: 1px solid #8A681B;
        border-radius: 10px;
        color: #FFFFFF;
        font-size: 0.88rem;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        cursor: pointer;
        transition: all 0.18s ease;
        box-shadow: 0 2px 6px rgba(154, 123, 56, 0.2);
    }

    .btn-filter-submit:hover {
        background: #8A681B;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(154, 123, 56, 0.28);
    }

    .btn-filter-reset {
        height: 42px;
        width: 42px;
        display: grid;
        place-items: center;
        border: 1px solid #D8D0C2;
        border-radius: 10px;
        background: #FAF7F2;
        color: #6B6357;
        text-decoration: none;
        transition: all 0.18s ease;
    }

    .btn-filter-reset:hover {
        background: #E8E2D5;
        color: #1F2937;
    }

    /* Timeline Group Header */
    .notification-group-title {
        margin: 24px 0 14px;
        color: #9A7B38;
        font-size: 0.82rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .notification-group-title::after {
        content: '';
        flex: 1;
        height: 1px;
        background: #E8E2D5;
    }

    /* Notification Cards: Clear Header -> Body -> Footer Flow */
    .notification-list {
        display: grid;
        gap: 14px;
    }

    body.user-area .notification-card,
    .notification-card {
        position: relative;
        background: #FFFFFF !important;
        border: 1px solid #E8E2D5 !important;
        border-left: 5px solid transparent !important;
        border-radius: 16px !important;
        padding: 18px 22px !important;
        box-shadow: 0 4px 18px rgba(44, 38, 30, 0.04) !important;
        display: flex;
        flex-direction: column;
        gap: 10px;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    body.user-area .notification-card:hover,
    .notification-card:hover {
        transform: translateY(-2px);
        border-color: #9A7B38 !important;
        box-shadow: 0 8px 24px rgba(154, 123, 56, 0.12) !important;
    }

    body.user-area .notification-card.unread,
    .notification-card.unread {
        border-left: 5px solid #9A7B38 !important;
        background: #FFFDF9 !important;
    }

    .notification-card-link {
        position: absolute;
        inset: 0;
        z-index: 1;
        border-radius: 16px;
    }

    /* 1. Header Row: Title + Status Badge */
    .notification-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        position: relative;
        z-index: 2;
    }

    .notification-card-title-wrap {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
        flex: 1;
    }

    .unread-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #9A7B38;
        box-shadow: 0 0 0 3px rgba(154, 123, 56, 0.22);
        flex-shrink: 0;
    }

    .notification-card-icon {
        width: 32px;
        height: 32px;
        border-radius: 9px;
        display: grid;
        place-items: center;
        font-size: 0.95rem;
        flex-shrink: 0;
    }

    .notification-card-icon.success { color: #2E7D32; background: #E8F5E9; }
    .notification-card-icon.danger { color: #C62828; background: #FFEBEE; }
    .notification-card-icon.warning { color: #8A6116; background: #FEF3C7; }
    .notification-card-icon.primary,
    .notification-card-icon.info { color: #1D4ED8; background: #EEF2FF; }
    .notification-card-icon.ai { color: #6D28D9; background: #F5F3FF; }

    .notification-card-title {
        margin: 0;
        font-size: 1.02rem;
        font-weight: 750;
        color: #1E252B;
        line-height: 1.35;
        word-break: break-word;
    }

    .read-label {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        min-height: 24px;
        padding: 3px 10px;
        border-radius: 9999px;
        font-size: 0.74rem;
        font-weight: 750;
        letter-spacing: 0.02em;
        white-space: nowrap;
        background: #F3EFEA;
        color: #78716C;
        border: 1px solid #E5DEC9;
    }

    .read-label.unread {
        background: #FEF3C7;
        color: #92400E;
        border-color: #FDE68A;
    }

    /* 2. Message Body */
    .notification-card-body {
        position: relative;
        z-index: 2;
    }

    .notification-message {
        margin: 0;
        color: #5F6672;
        font-size: 0.92rem;
        line-height: 1.55;
    }

    /* 3. Footer Row: Timestamp + Action Buttons */
    .notification-card-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        padding-top: 10px;
        margin-top: 4px;
        border-top: 1px solid #F3EFE6;
        position: relative;
        z-index: 2;
    }

    .notification-meta {
        display: flex;
        align-items: center;
        gap: 14px;
        flex-wrap: wrap;
        color: #78716C;
        font-size: 0.8rem;
        font-weight: 500;
    }

    .notification-meta span {
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .notification-ref-badge {
        background: #FAF7F2;
        border: 1px solid #E5DEC9;
        padding: 2px 8px;
        border-radius: 6px;
        font-weight: 600;
        color: #6B6357;
    }

    .notification-actions {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .notification-view-btn {
        background-color: #9A7B38 !important;
        border: 1px solid #8A681B !important;
        color: #FFFFFF !important;
        font-size: 0.8rem !important;
        font-weight: 650 !important;
        padding: 5px 14px !important;
        border-radius: 8px !important;
        text-decoration: none !important;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        white-space: nowrap;
        transition: all 0.15s ease !important;
    }

    .notification-view-btn:hover {
        background-color: #7E6329 !important;
        transform: translateY(-1px);
    }

    .notification-icon-btn {
        width: 32px !important;
        height: 32px !important;
        border-radius: 8px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 0 !important;
        border: 1px solid #D8D0C2 !important;
        background: #FFFFFF !important;
        color: #6B6357 !important;
        font-size: 0.78rem !important;
        transition: all 0.15s ease !important;
        cursor: pointer;
    }

    .notification-icon-btn:hover {
        background: #FAF7F2 !important;
        border-color: #9A7B38 !important;
        color: #9A7B38 !important;
        transform: translateY(-1px);
    }

    .notification-icon-btn.success {
        border-color: #C6F6D5 !important;
        color: #2F855A !important;
    }

    .notification-icon-btn.success:hover {
        background: #F0FFF4 !important;
        border-color: #2F855A !important;
        color: #2F855A !important;
    }

    /* Empty State */
    .notification-empty {
        background: #FFFFFF !important;
        border: 1px dashed #E5DEC9 !important;
        border-radius: 16px !important;
        padding: 48px 20px;
        color: #6F6F6F;
        text-align: center;
        box-shadow: 0 4px 18px rgba(44, 38, 30, 0.04) !important;
    }

    .notification-empty i {
        width: 56px;
        height: 56px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 14px;
        border-radius: 16px;
        color: #9A7B38;
        background: #FBF2DF;
        font-size: 1.4rem;
    }

    /* Detail Drawer Panel */
    .notification-detail-panel {
        position: fixed;
        top: 0;
        right: 0;
        z-index: 1080;
        width: min(480px, 92vw);
        height: 100vh;
        padding: 24px;
        overflow-y: auto;
        background: #FCFBF8;
        border-left: 1px solid #E6E0D4;
        visibility: hidden;
        transform: translateX(105%);
        transition: transform 0.24s ease;
        pointer-events: none;
    }

    .notification-detail-panel:target {
        visibility: visible;
        box-shadow: -24px 0 60px rgba(0, 0, 0, 0.12);
        transform: translateX(0);
        pointer-events: auto;
    }

    .detail-panel-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 20px;
    }

    .detail-panel-header h2 {
        margin: 0;
        color: #1E252B;
        font-size: 1.35rem;
        font-weight: 750;
        line-height: 1.3;
    }

    .detail-panel-close {
        width: 36px;
        height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #E6E0D4;
        border-radius: 10px;
        color: #2C2C2C;
        background: #FFFFFF;
        text-decoration: none;
    }

    .detail-panel-card {
        padding: 16px;
        margin-bottom: 14px;
        border: 1px solid #E6E0D4;
        border-radius: 14px;
        background: #FFFFFF;
    }

    .detail-panel-card h3 {
        margin: 0 0 10px;
        color: #1E252B;
        font-size: 0.84rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .detail-panel-card p,
    .detail-panel-card li {
        color: #5F5F5F;
        font-size: 0.9rem;
        line-height: 1.55;
    }

    .detail-list {
        display: grid;
        gap: 8px;
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .detail-list li {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-bottom: 6px;
        border-bottom: 1px solid #F5EFE6;
    }

    .detail-list li:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }

    .timeline-list {
        margin: 0;
        padding-left: 20px;
    }

    .timeline-list li {
        margin-bottom: 8px;
    }
</style>

<div class="notification-workspace mt-3" id="notification-feed">
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

    <div class="notification-layout">
        <main class="notification-feed-panel">
            <!-- Consolidated Control Panel Card -->
            <div class="notification-control-panel">
                <!-- Status Filter Chips + Mark All as Read -->
                <div class="notification-status-chips">
                    <div class="status-chip-group">
                        <a class="status-chip <?php echo $read_filter === 'all' ? 'active' : ''; ?>" 
                           href="notifications.php?read=all<?php echo $search !== '' ? '&q=' . urlencode($search) : ''; ?><?php echo $sort !== 'latest' ? '&sort=' . urlencode($sort) : ''; ?>">
                            All <span class="badge"><?php echo number_format($total_count); ?></span>
                        </a>
                        <a class="status-chip <?php echo $read_filter === 'unread' ? 'active' : ''; ?>" 
                           href="notifications.php?read=unread<?php echo $search !== '' ? '&q=' . urlencode($search) : ''; ?><?php echo $sort !== 'latest' ? '&sort=' . urlencode($sort) : ''; ?>">
                            Unread <span class="badge"><?php echo number_format($unread_count); ?></span>
                        </a>
                        <a class="status-chip <?php echo $read_filter === 'read' ? 'active' : ''; ?>" 
                           href="notifications.php?read=read<?php echo $search !== '' ? '&q=' . urlencode($search) : ''; ?><?php echo $sort !== 'latest' ? '&sort=' . urlencode($sort) : ''; ?>">
                            Read <span class="badge"><?php echo number_format($read_count); ?></span>
                        </a>
                        <a class="status-chip <?php echo $read_filter === 'archived' ? 'active' : ''; ?>" 
                           href="notifications.php?read=archived<?php echo $search !== '' ? '&q=' . urlencode($search) : ''; ?><?php echo $sort !== 'latest' ? '&sort=' . urlencode($sort) : ''; ?>">
                            Archived <span class="badge"><?php echo number_format($archived_count); ?></span>
                        </a>
                    </div>
                    <?php if ($unread_count > 0): ?>
                        <form method="POST" class="d-inline mb-0">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="btn-mark-all-read">
                                <i class="fas fa-check-double"></i> Mark All as Read
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Search and Sort Filter Form (Category Filter Removed) -->
                <form method="GET" class="notification-filter-form">
                    <div class="filter-input-wrap">
                        <i class="fas fa-search"></i>
                        <input type="search" class="filter-input" name="q" value="<?php echo e($search); ?>" placeholder="Search notifications, requests, or updates...">
                    </div>
                    <div>
                        <select name="sort" class="filter-select" aria-label="Sort notifications">
                            <option value="latest" <?php echo $sort === 'latest' ? 'selected' : ''; ?>>Latest</option>
                            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
                        </select>
                    </div>
                    <input type="hidden" name="read" value="<?php echo e($read_filter); ?>">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn-filter-submit">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <?php if ($search !== '' || $sort !== 'latest'): ?>
                            <a href="notifications.php?read=<?php echo e($read_filter); ?>" class="btn-filter-reset" title="Clear Filters" aria-label="Clear Filters">
                                <i class="fas fa-rotate-left"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Grouped Notifications -->
            <?php 
                $unresolved_notifications = [];
            ?>
            <?php if (!empty($grouped_notifications)): ?>
                <?php foreach ($grouped_notifications as $group => $items): ?>
                    <h2 class="notification-group-title"><?php echo e($group); ?></h2>
                    <div class="notification-list">
                        <?php foreach ($items as $notification): ?>
                            <?php
                                $iconMeta = notificationIconMeta($notification);
                                $detail_id = 'notification-detail-' . intval($notification['notification_id']);
                                $reference_number = notificationReferenceNumber($notification);
                                $action_url = notificationActionUrl($notification, $conn);
                                if (empty($action_url)) {
                                    $unresolved_notifications[] = [
                                        'id' => (int) $notification['notification_id'],
                                        'title' => (string) $notification['title'],
                                        'type' => (string) $notification['notification_type']
                                    ];
                                }
                            ?>
                            <article class="notification-card <?php echo !$notification['is_read'] ? 'unread' : 'read'; ?>">
                                <a class="notification-card-link" href="#<?php echo e($detail_id); ?>" aria-label="Open notification details"></a>

                                <!-- 1. Header row: Title + Status Badge -->
                                <div class="notification-card-header">
                                    <div class="notification-card-title-wrap">
                                        <?php if (!$notification['is_read']): ?>
                                            <span class="unread-dot" title="Unread"></span>
                                        <?php endif; ?>
                                        <div class="notification-card-icon <?php echo e($iconMeta['tone']); ?>">
                                            <i class="fas <?php echo e($iconMeta['icon']); ?>"></i>
                                        </div>
                                        <h3 class="notification-card-title"><?php echo e($notification['title']); ?></h3>
                                    </div>
                                    <span class="read-label <?php echo !$notification['is_read'] ? 'unread' : 'read'; ?>">
                                        <i class="fas <?php echo $notification['is_read'] ? 'fa-envelope-open' : 'fa-envelope'; ?>"></i>
                                        <?php echo $notification['is_read'] ? 'Read' : 'Unread'; ?>
                                    </span>
                                </div>

                                <!-- 2. Message body -->
                                <div class="notification-card-body">
                                    <p class="notification-message"><?php echo e(notificationShortMessage($notification['message'])); ?></p>
                                </div>

                                <!-- 3. Footer row: Timestamp + Actions -->
                                <div class="notification-card-footer">
                                    <div class="notification-meta">
                                        <span><i class="fas fa-clock"></i> <?php echo e(formatDateTime($notification['created_at'])); ?></span>
                                        <?php if (!empty($reference_number)): ?>
                                            <span class="notification-ref-badge"><i class="fas fa-hashtag"></i> <?php echo e($reference_number); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notification-actions">
                                        <?php if (!empty($action_url)): ?>
                                            <a class="notification-view-btn" href="<?php echo e($action_url); ?>">
                                                <i class="fas fa-arrow-up-right-from-square"></i> View
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!$notification['is_read']): ?>
                                            <form method="POST" class="d-inline mb-0">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="notification_id" value="<?php echo intval($notification['notification_id']); ?>">
                                                <button type="submit" class="notification-icon-btn success" title="Mark as Read" aria-label="Mark as Read">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($notification['state'] !== 'archived'): ?>
                                            <form method="POST" class="d-inline mb-0">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="archive_notification">
                                                <input type="hidden" name="notification_id" value="<?php echo intval($notification['notification_id']); ?>">
                                                <button type="submit" class="notification-icon-btn" title="Archive" aria-label="Archive">
                                                    <i class="fas fa-box-archive"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>

                            <!-- Slide-in Detail Drawer -->
                            <aside class="notification-detail-panel" id="<?php echo e($detail_id); ?>" aria-label="Notification detail panel">
                                <div class="detail-panel-header">
                                    <div>
                                        <h2 class="mt-1"><?php echo e($notification['title']); ?></h2>
                                        <span class="read-label <?php echo !$notification['is_read'] ? 'unread' : 'read'; ?> mt-2">
                                            <i class="fas <?php echo $notification['is_read'] ? 'fa-envelope-open' : 'fa-envelope'; ?>"></i>
                                            <?php echo $notification['is_read'] ? 'Read' : 'Unread'; ?>
                                        </span>
                                    </div>
                                    <a class="detail-panel-close" href="#notification-feed" aria-label="Close notification details"><i class="fas fa-xmark"></i></a>
                                </div>
                                <div class="detail-panel-card">
                                    <h3>Full Message</h3>
                                    <p class="mb-0"><?php echo nl2br(e($notification['message'])); ?></p>
                                </div>
                                <div class="detail-panel-card">
                                    <h3>Related Record</h3>
                                    <ul class="detail-list">
                                        <?php if (!empty($reference_number)): ?>
                                            <li><span>Reference</span><strong><?php echo e($reference_number); ?></strong></li>
                                        <?php endif; ?>
                                        <li><span>Status</span><strong><?php echo $notification['is_read'] ? 'Read' : 'Unread'; ?></strong></li>
                                        <li><span>Received</span><strong><?php echo e(formatDateTime($notification['created_at'])); ?></strong></li>
                                    </ul>
                                </div>
                                <div class="detail-panel-card">
                                    <h3>Timeline</h3>
                                    <ol class="timeline-list">
                                        <li>Notification created on <?php echo e(formatDateTime($notification['created_at'])); ?>.</li>
                                        <li><?php echo $notification['is_read'] ? 'Marked as read.' : 'Awaiting review.'; ?></li>
                                    </ol>
                                </div>
                            </aside>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="notification-empty">
                    <i class="fas fa-bell-slash"></i>
                    <h5>You're all caught up!</h5>
                    <p class="mb-0">No notifications match your current filters. New request and parish updates will appear here.</p>
                </div>
            <?php endif; ?>

            <?php if ($total_pages > 1): ?>
                <nav class="mt-4" aria-label="Notification pages">
                    <ul class="pagination flex-wrap">
                        <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo e(http_build_query(['q' => $search, 'read' => $read_filter, 'sort' => $sort, 'page' => $p])); ?>"><?php echo $p; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php if (!empty($unresolved_notifications)): ?>
<script>
(function() {
    var unlinked = <?php echo json_encode($unresolved_notifications); ?>;
    if (unlinked && unlinked.length > 0 && typeof console !== 'undefined' && console.warn) {
        unlinked.forEach(function(item) {
            console.warn('[Tugon Notifications] Unlinked notification #' + item.id + ' ("' + item.title + '"): target resource could not be resolved; View button safely hidden.', item);
        });
    }
})();
</script>
<?php endif; ?>

<?php include '../templates/footer.php'; ?>
