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
        'certificate' => [
            'label' => 'Certificate Update',
            'icon' => 'fa-file-lines',
            'tone' => 'info',
            'keywords' => ['certificate', 'certification']
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
            'tone' => 'warning',
            'keywords' => ['processing', 'in progress', 'pending']
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
            'tone' => 'announcement',
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
        'ai' => [
            'label' => 'AI Assistant',
            'icon' => 'fa-robot',
            'tone' => 'ai',
            'keywords' => ['ai assistant', 'tugon ai', 'chatbot']
        ],
        'notice' => [
            'label' => 'General Notice',
            'icon' => 'fa-bell',
            'tone' => 'info',
            'keywords' => []
        ]
    ];
}

function notificationTypeMeta($notification) {
    $type=(string)($notification['notification_type']??'system');
    $key=str_starts_with($type,'reservation_')?'schedule':(str_starts_with($type,'request_')?'request':(str_starts_with($type,'certificate_')?'certificate':($type==='announcement_published'?'announcement':($type==='system'?'notice':'account'))));
    $fallback = notificationTypeCatalog()[$key] ?? notificationTypeCatalog()['notice'];
    return [
        'key' => $key,
        'label' => $fallback['label'],
        'icon' => $fallback['icon'],
        'tone' => $fallback['tone']
    ];
}

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

function notificationActionUrl($notification) {
    return NotificationService::actionUrl($notification['action_key'] ?? null);
}

function notificationShortMessage($message, $limit = 150) {
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
    return !empty($notification['entity_type']) && !empty($notification['entity_id']) ? ucfirst($notification['entity_type']).' #'.(int)$notification['entity_id'] : 'Not linked';
}

function notificationMatchesType($notification, $type_filter) {
    if ($type_filter === 'all') {
        return true;
    }
    if ($type_filter === 'archived') {
        return false;
    }

    $meta_key = notificationTypeMeta($notification)['key'];
    $type_groups = [
        'request' => ['request', 'submitted', 'approved', 'processing', 'rejected', 'payment'],
        'certificate' => ['certificate', 'file'],
    ];

    if (isset($type_groups[$type_filter])) {
        return in_array($meta_key, $type_groups[$type_filter], true);
    }

    return $meta_key === $type_filter;
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

    if ($action === 'save_preferences') {
        $categories = ['announcements', 'requests', 'schedules', 'system'];
        foreach ($categories as $cat) {
            $in_app = isset($_POST['in_app_' . $cat]) ? 1 : 0;
            $email = isset($_POST['email_' . $cat]) ? 1 : 0;
            $sms = isset($_POST['sms_' . $cat]) ? 1 : 0;
            $stmt = $conn->prepare("INSERT INTO notification_preferences (user_id, category, in_app_enabled, email_enabled, sms_enabled)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE in_app_enabled = VALUES(in_app_enabled), email_enabled = VALUES(email_enabled), sms_enabled = VALUES(sms_enabled)");
            if ($stmt) {
                $stmt->bind_param('isiii', $user_id, $cat, $in_app, $email, $sms);
                $stmt->execute();
                $stmt->close();
            }
        }
        if (function_exists('createAuditLog')) {
            createAuditLog($conn, $user_id, 'UPDATE_NOTIFICATION_PREFERENCES', 'users', $user_id);
        }
        $success = 'Notification channel preferences saved successfully.';
    }
}

$user_preferences = [];
$pref_result = $conn->query("SELECT category, in_app_enabled, email_enabled, sms_enabled FROM notification_preferences WHERE user_id = $user_id");
while ($pref_result && $row = $pref_result->fetch_assoc()) {
    $user_preferences[$row['category']] = [
        'in_app' => intval($row['in_app_enabled']),
        'email' => intval($row['email_enabled']),
        'sms' => intval($row['sms_enabled'])
    ];
}
foreach (['announcements', 'requests', 'schedules', 'system'] as $cat) {
    if (!isset($user_preferences[$cat])) {
        $user_preferences[$cat] = ['in_app' => 1, 'email' => 1, 'sms' => 1];
    }
}

$active_tab = in_array($_GET['tab'] ?? '', ['feed', 'preferences'], true) ? $_GET['tab'] : 'feed';
$search = trim($_GET['q'] ?? '');
$read_filter = $_GET['read'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$sort = $_GET['sort'] ?? 'latest';
$allowed_read_filters = ['all', 'unread', 'read'];
$notification_type_catalog = notificationTypeCatalog();
$allowed_type_filters = array_merge(['all', 'archived'], array_keys($notification_type_catalog));
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
$where = ['user_id = ?', "state <> 'deleted'"];
$types = 'i';
$params = [$user_id];

if ($read_filter === 'unread') {
    $where[] = "state = 'unread'";
} elseif ($read_filter === 'read') {
    $where[] = "state = 'read'";
}
if($type_filter==='archived')$where[]="state='archived'";else$where[]="state<>'archived'";
$typeSql=[
    'request'=>"(notification_type LIKE 'request_%' OR notification_type LIKE 'payment_%')",
    'payment'=>"notification_type LIKE 'payment_%'",
    'file'=>"notification_type IN ('certificate_ready','certificate_released')",
    'certificate'=>"notification_type LIKE 'certificate_%'",
    'approved'=>"notification_type LIKE '%_approved'",
    'processing'=>"notification_type IN ('request_submitted','reservation_created')",
    'rejected'=>"notification_type LIKE '%_rejected'",
    'submitted'=>"notification_type IN ('request_submitted','reservation_created')",
    'announcement'=>"notification_type LIKE 'announcement_%'",
    'schedule'=>"(notification_type LIKE 'reservation_%' OR notification_type LIKE 'schedule_%')",
    'account'=>"notification_type IN ('system','account','password_changed')",
    'ai'=>"notification_type LIKE 'ai_%'",
    'notice'=>"notification_type='system'"
];
if(isset($typeSql[$type_filter]))$where[]=$typeSql[$type_filter];

if ($search !== '') {
    $where[] = '(title LIKE ? OR message LIKE ?)';
    $search_like = '%' . $search . '%';
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= 'ss';
}

$order_sql = $sort === 'oldest' ? 'created_at ASC' : 'created_at DESC';
$page=max(1,(int)($_GET['page']??1));$per_page=30;$countStmt=$conn->prepare("SELECT COUNT(*) count FROM notifications WHERE ".implode(' AND ',$where));$countStmt->bind_param($types,...$params);$countStmt->execute();$filtered_total=(int)($countStmt->get_result()->fetch_assoc()['count']??0);$countStmt->close();$total_pages=max(1,(int)ceil($filtered_total/$per_page));$offset=($page-1)*$per_page;
$stmt = $conn->prepare("SELECT notification_id,notification_type,title,message,entity_type,entity_id,action_key,state,is_read,created_at FROM notifications WHERE " . implode(' AND ', $where) . " ORDER BY $order_sql LIMIT ? OFFSET ?");
if ($stmt) {
    $queryTypes=$types.'ii';$queryParams=array_merge($params,[$per_page,$offset]);$stmt->bind_param($queryTypes, ...$queryParams);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
}

$total_count = notificationCount($conn, "SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND state<>'deleted'");
$unread_count = notificationCount($conn, "SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND state='unread'");
$read_count = max(0, $total_count - $unread_count);
$today_count = notificationCount($conn, "SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND DATE(created_at) = CURDATE()");
$archived_count = notificationCount($conn, "SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND state='archived'");

$category_filters = [
    'all' => ['label' => 'All Notifications', 'icon' => 'fa-inbox', 'type' => 'all'],
    'unread' => ['label' => 'Unread', 'icon' => 'fa-envelope', 'read' => 'unread', 'type' => 'all'],
    'request' => ['label' => 'Requests', 'icon' => 'fa-list-check', 'type' => 'request'],
    'certificate' => ['label' => 'Certificates', 'icon' => 'fa-file-lines', 'type' => 'certificate'],
    'announcement' => ['label' => 'Announcements', 'icon' => 'fa-bullhorn', 'type' => 'announcement'],
    'schedule' => ['label' => 'Schedule Updates', 'icon' => 'fa-calendar-check', 'type' => 'schedule'],
    'account' => ['label' => 'Account', 'icon' => 'fa-user-shield', 'type' => 'account'],
    'ai' => ['label' => 'AI Assistant', 'icon' => 'fa-robot', 'type' => 'ai'],
    'archived' => ['label' => 'Archived', 'icon' => 'fa-box-archive', 'type' => 'archived'],
];

$category_counts = array_fill_keys(array_keys($category_filters), 0);
$category_counts['all'] = $total_count;
$category_counts['unread'] = $unread_count;
$category_counts['archived'] = $archived_count;
$stmt = $conn->prepare("SELECT notification_type,COUNT(*) count FROM notifications WHERE user_id = ? AND state='unread' GROUP BY notification_type");
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $type=(string)$row['notification_type'];$count=(int)$row['count'];
        if(str_starts_with($type,'request_')||str_starts_with($type,'payment_'))$category_counts['request']+=$count;
        if(str_starts_with($type,'certificate_'))$category_counts['certificate']+=$count;
        if(str_starts_with($type,'announcement_'))$category_counts['announcement']+=$count;
        if(str_starts_with($type,'reservation_')||str_starts_with($type,'schedule_'))$category_counts['schedule']+=$count;
        if(in_array($type,['system','account','password_changed'],true))$category_counts['account']+=$count;
        if(str_starts_with($type,'ai_'))$category_counts['ai']+=$count;
    }
    $stmt->close();
}

$grouped_notifications = [];
foreach ($notifications as $notification) {
    $grouped_notifications[notificationGroupLabel($notification['created_at'])][] = $notification;
}

$page_title = 'Notifications';
$body_extra_class = 'user-notifications-page';
?>
<?php include '../templates/header.php'; ?>

<form method="GET" class="notification-mobile-search" role="search">
    <i class="fas fa-search" aria-hidden="true"></i>
    <input type="search" name="q" value="<?php echo e($search); ?>" placeholder="Search notifications..." aria-label="Search notifications">
    <input type="hidden" name="read" value="<?php echo e($read_filter); ?>">
    <input type="hidden" name="type" value="<?php echo e($type_filter); ?>">
    <input type="hidden" name="sort" value="<?php echo e($sort); ?>">
</form>

<?php include '../includes/back_button.php'; ?>

<style>
    .notification-mobile-search,
    .notification-mobile-mark-all,
    .notification-mobile-spinner {
        display: none;
    }

    .notification-workspace {
        width: min(100%, 1420px);
        margin: 0 auto;
        padding: 0 clamp(12px, 2vw, 24px);
    }

    .notification-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 16px 0 12px;
    }

    .notification-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 34px;
        padding: 7px 12px;
        border: 1px solid #e6e0d4;
        border-radius: 999px;
        color: #2c2c2c;
        background: #fff;
        font-size: 0.82rem;
        font-weight: 800;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.04);
        text-decoration: none;
    }

    .notification-chip strong {
        color: #9a6f18;
    }

    .notification-layout {
        display: grid;
        grid-template-columns: minmax(240px, 28%) minmax(0, 1fr);
        gap: 18px;
        align-items: start;
    }

    .notification-categories,
    .notification-toolbar,
    .notification-card,
    .notification-empty {
        border: 1px solid #e6e0d4;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.05);
    }

    .notification-categories {
        position: sticky;
        top: 92px;
        padding: 10px;
    }

    .notification-category-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 6px 6px 12px;
    }

    .notification-category-header h2 {
        margin: 0;
        color: #2c2c2c;
        font-family: "Playfair Display", Georgia, serif;
        font-size: 1.18rem;
        font-weight: 700;
    }

    .notification-category-list {
        display: grid;
        max-height: 0;
        padding: 0;
        overflow: hidden;
        visibility: hidden;
        opacity: 0;
        transition: max-height 0.3s ease, opacity 0.2s ease, visibility 0.3s;
    }

    .notification-category-card {
        overflow: hidden;
        border: 1px solid #e6e0d4;
        border-radius: 14px;
        background: #fff;
    }

    .notification-category-card.open .notification-category-list {
        max-height: 520px;
        visibility: visible;
        opacity: 1;
    }

    .notification-category-toggle {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 54px;
        padding: 11px 12px;
        border: 0;
        color: #8a6116;
        background: #fbf2df;
        text-align: left;
        cursor: pointer;
    }

    .notification-category-toggle .notification-category-icon {
        background: #fff;
    }

    .notification-category-toggle-label {
        flex: 1;
        min-width: 0;
        font-size: 0.9rem;
        font-weight: 900;
    }

    .notification-category-chevron {
        color: #8a6116;
        font-size: 0.75rem;
        transition: transform 0.25s ease;
    }

    .notification-category-card.open .notification-category-chevron {
        transform: rotate(90deg);
    }

    .notification-active-filter {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        width: fit-content;
        margin: 0 0 8px;
        padding: 6px 8px 6px 11px;
        border: 1px solid #c89b3c;
        border-radius: 999px;
        color: #8a6116;
        background: #fff;
        font-size: 0.76rem;
        font-weight: 850;
        text-decoration: none;
    }

    .notification-active-filter:hover {
        color: #6f4d10;
        background: #fffaf0;
    }

    .notification-active-filter i {
        width: 18px;
        height: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: #fbf2df;
        font-size: 0.65rem;
    }

    .notification-category-link {
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 42px;
        padding: 9px 10px;
        border-top: 1px solid #eee8dc;
        border-radius: 0;
        color: #4f4f4f;
        text-decoration: none;
        transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
    }

    .notification-category-link:hover,
    .notification-category-link.active {
        color: #2c2c2c;
        background: #fbf2df;
    }

    .notification-category-link.active {
        color: #8a6116;
        box-shadow: inset 3px 0 0 #c89b3c;
    }

    .notification-category-icon {
        width: 28px;
        height: 28px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 9px;
        color: #9a6f18;
        background: #fbf2df;
    }

    .notification-category-action {
        width: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #8a8274;
        font-size: 0.78rem;
    }

    .notification-category-link span {
        flex: 1;
        min-width: 0;
        font-size: 0.9rem;
        font-weight: 800;
    }

    .notification-category-count {
        min-width: 28px;
        padding: 3px 8px;
        border-radius: 999px;
        color: #8a6116;
        background: #f8edcf;
        text-align: center;
        font-size: 0.75rem;
        font-weight: 900;
    }

    .notification-feed-panel {
        min-width: 0;
    }

    .notification-toolbar {
        position: sticky;
        top: 92px;
        z-index: 12;
        padding: 12px;
        margin-bottom: 16px;
        backdrop-filter: blur(14px);
        background: rgba(255, 255, 255, 0.94);
    }

    .notification-toolbar-grid {
        display: grid;
        grid-template-columns: minmax(220px, 1fr) 150px 170px 145px auto;
        gap: 8px;
        align-items: center;
    }

    .notification-mark-all-form {
        margin-top: 8px;
    }

    .toolbar-control {
        min-height: 42px;
        border-radius: 12px;
        border-color: #e6e0d4;
        color: #2c2c2c;
        background-color: #fff;
        font-size: 0.86rem;
    }

    .toolbar-control:focus {
        border-color: #c89b3c;
        box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.16);
    }

    .input-with-icon {
        position: relative;
    }

    .input-with-icon i {
        position: absolute;
        left: 14px;
        top: 50%;
        z-index: 1;
        color: #9a6f18;
        transform: translateY(-50%);
    }

    .input-with-icon .form-control {
        padding-left: 40px;
    }

    .notification-group-title {
        margin: 18px 0 10px;
        color: #6f6f6f;
        font-size: 0.82rem;
        font-weight: 900;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .notification-list {
        display: grid;
        gap: 10px;
    }

    .notification-card {
        position: relative;
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 14px;
        padding: 14px;
        border-left: 4px solid transparent;
        transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease, background 0.22s ease;
    }

    .notification-card:hover {
        border-color: #c89b3c;
        box-shadow: 0 12px 32px rgba(0, 0, 0, 0.08);
        transform: translateY(-2px);
    }

    .notification-card.unread {
        border-left-color: #c89b3c;
        background: #fcf8ef;
    }

    .notification-card.read {
        background: #fff;
    }

    .notification-card-link {
        position: absolute;
        inset: 0;
        z-index: 1;
        border-radius: 14px;
    }

    .notification-icon {
        width: 40px;
        height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        color: #5e81ac;
        background: #eaf1f8;
    }

    .notification-icon.success { color: #4f8a5b; background: #e9f4eb; }
    .notification-icon.danger { color: #c85a54; background: #fbe9e7; }
    .notification-icon.warning { color: #a7791d; background: #fbf2df; }
    .notification-icon.primary,
    .notification-icon.info { color: #5e81ac; background: #eaf1f8; }
    .notification-icon.announcement { color: #9a6f18; background: #fbf2df; }
    .notification-icon.secondary { color: #6f6f6f; background: #f3f1ec; }
    .notification-icon.ai { color: #7952b3; background: #f0eafa; }

    .notification-main,
    .notification-actions {
        position: relative;
        z-index: 2;
    }

    .notification-title-row {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 5px;
    }

    .notification-card h3 {
        margin: 0;
        color: #2c2c2c;
        font-size: 0.98rem;
        font-weight: 900;
        line-height: 1.35;
    }

    .notification-card.read h3 {
        font-weight: 700;
    }

    .notification-card p {
        margin: 0 0 8px;
        color: #6f6f6f;
        font-size: 0.9rem;
        line-height: 1.5;
    }

    .notification-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 12px;
        color: #777;
        font-size: 0.78rem;
        font-weight: 700;
    }

    .notification-type,
    .read-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        min-height: 24px;
        padding: 3px 8px;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 900;
    }

    .notification-type.success { color: #3f7448; background: #e8f3e9; }
    .notification-type.danger { color: #a9423d; background: #fae8e6; }
    .notification-type.primary,
    .notification-type.info { color: #3f668b; background: #e8f0f7; }
    .notification-type.warning { color: #8a6116; background: #f8edcf; }
    .notification-type.announcement { color: #8a6116; background: #f8edcf; }
    .notification-type.secondary { color: #626262; background: #f2f0ec; }
    .notification-type.ai { color: #6842a0; background: #efe8f8; }

    .read-label {
        color: #6f6f6f;
        background: #f3f1ec;
    }

    .read-label.unread {
        color: #8a6116;
        background: #f8edcf;
    }

    .unread-dot {
        width: 9px;
        height: 9px;
        border-radius: 999px;
        background: #c89b3c;
        box-shadow: 0 0 0 5px rgba(200, 155, 60, 0.15);
    }

    .notification-actions {
        display: flex;
        align-items: flex-start;
        justify-content: flex-end;
        gap: 6px;
        flex-wrap: wrap;
        min-width: 214px;
    }

    .notification-actions .btn {
        min-height: 34px;
        border-radius: 10px;
        font-size: 0.78rem;
        font-weight: 850;
    }

    .notification-empty {
        padding: 42px 18px;
        color: #6f6f6f;
        text-align: center;
    }

    .notification-empty i {
        width: 56px;
        height: 56px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 14px;
        border-radius: 16px;
        color: #9a6f18;
        background: #fbf2df;
        font-size: 1.4rem;
    }

    .notification-detail-panel {
        position: fixed;
        top: 0;
        right: 0;
        z-index: 1080;
        width: min(480px, 92vw);
        height: 100vh;
        padding: 22px;
        overflow-y: auto;
        background: #fcfbf8;
        border-left: 1px solid #e6e0d4;
        visibility: hidden;
        box-shadow: none;
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
        margin-bottom: 18px;
    }

    .detail-panel-header h2 {
        margin: 0;
        color: #2c2c2c;
        font-family: "Playfair Display", Georgia, serif;
        font-size: 1.45rem;
        line-height: 1.25;
    }

    .detail-panel-close {
        width: 38px;
        height: 38px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e6e0d4;
        border-radius: 12px;
        color: #2c2c2c;
        background: #fff;
        text-decoration: none;
    }

    .detail-panel-card {
        padding: 16px;
        margin-bottom: 12px;
        border: 1px solid #e6e0d4;
        border-radius: 14px;
        background: #fff;
    }

    .detail-panel-card h3 {
        margin: 0 0 10px;
        color: #2c2c2c;
        font-size: 0.9rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .detail-panel-card p,
    .detail-panel-card li {
        color: #5f5f5f;
        font-size: 0.92rem;
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
        justify-content: space-between;
        gap: 12px;
        padding-bottom: 8px;
        border-bottom: 1px solid #f0ebe2;
    }

    .detail-list li:last-child {
        padding-bottom: 0;
        border-bottom: 0;
    }

    .detail-list span {
        color: #8a8274;
        font-weight: 800;
    }

    .detail-list strong {
        color: #2c2c2c;
        text-align: right;
    }

    .timeline-list {
        margin: 0;
        padding-left: 18px;
    }

    .timeline-list li + li {
        margin-top: 8px;
    }

    @media (max-width: 1100px) {
        .notification-layout {
            grid-template-columns: 1fr;
        }

        .notification-categories {
            position: static;
        }

        .notification-toolbar {
            top: 78px;
        }
    }

    @media (max-width: 900px) {
        .notification-toolbar-grid {
            grid-template-columns: 1fr 1fr;
        }

        .notification-toolbar-grid .toolbar-search {
            grid-column: 1 / -1;
        }

        .notification-card {
            grid-template-columns: auto minmax(0, 1fr);
        }

        .notification-actions {
            grid-column: 2;
            justify-content: flex-start;
            min-width: 0;
        }
    }

    @media (max-width: 576px) {
        .notification-workspace {
            padding: 0 8px;
        }

        .notification-toolbar-grid {
            grid-template-columns: 1fr;
        }

        .notification-toolbar {
            position: static;
        }

        .notification-card {
            grid-template-columns: 1fr;
        }

        .notification-actions {
            grid-column: 1;
        }
    }

    /* Notification & Security Channel Preferences Styles */
    .nav-tab-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        background: #f4ede0;
        padding: 6px;
        border-radius: 14px;
        width: fit-content;
        margin-bottom: 20px;
        border: 1px solid #e5dcce;
    }

    .nav-tab-pill {
        padding: 9px 20px;
        border-radius: 10px;
        font-size: 0.9rem;
        font-weight: 800;
        color: #4a554b;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 9px;
        transition: all 0.22s ease;
    }

    .nav-tab-pill:hover {
        color: #344536;
        background: rgba(255, 255, 255, 0.65);
    }

    .nav-tab-pill.active {
        background: #344536;
        color: #fff;
        box-shadow: 0 4px 14px rgba(52, 69, 54, 0.22);
    }

    .nav-tab-pill .badge {
        font-size: 0.75rem;
        padding: 3px 7px;
        border-radius: 999px;
    }

    .pref-container {
        max-width: 1080px;
        margin: 0 auto 30px;
    }

    .pref-header {
        background: linear-gradient(135deg, #344536 0%, #1f2b20 100%);
        color: #fff;
        border-radius: 18px;
        padding: 26px 30px;
        margin-bottom: 24px;
        box-shadow: 0 10px 32px rgba(52, 69, 54, 0.16);
        position: relative;
        overflow: hidden;
        border: 1px solid rgba(201, 166, 70, 0.25);
    }

    .pref-header h2 {
        font-size: 1.35rem;
        font-weight: 900;
        margin: 0 0 6px;
        color: #fff;
        letter-spacing: -0.01em;
    }

    .pref-header p {
        color: #d1dcce;
        font-size: 0.92rem;
        margin: 0;
        max-width: 720px;
        line-height: 1.5;
    }

    .pref-header-accent {
        position: absolute;
        right: -20px;
        bottom: -30px;
        width: 180px;
        height: 180px;
        background: radial-gradient(circle, rgba(201, 166, 70, 0.22) 0%, transparent 70%);
        border-radius: 50%;
        pointer-events: none;
    }

    .pref-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
        gap: 20px;
        margin-bottom: 24px;
    }

    .pref-card {
        background: #fff;
        border: 1px solid #e7dfd2;
        border-radius: 16px;
        padding: 22px;
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.04);
        transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
        display: flex;
        flex-direction: column;
    }

    .pref-card:hover {
        border-color: #c89b3c;
        box-shadow: 0 10px 28px rgba(200, 155, 60, 0.12);
        transform: translateY(-2px);
    }

    .pref-card-header {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 12px;
    }

    .pref-card-icon {
        width: 46px;
        height: 46px;
        border-radius: 13px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        color: #344536;
        background: #fbf5e8;
        border: 1px solid #ebdfc7;
        flex-shrink: 0;
    }

    .pref-card-title {
        margin: 0;
        font-size: 1.08rem;
        font-weight: 850;
        color: #222e23;
    }

    .pref-card-desc {
        color: #636b64;
        font-size: 0.88rem;
        line-height: 1.48;
        margin-bottom: 18px;
        flex-grow: 1;
    }

    .pref-channels {
        border-top: 1px solid #f0e9dc;
        padding-top: 14px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .pref-channel-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 10px 14px;
        background: #fcf9f2;
        border-radius: 11px;
        border: 1px solid #ede5d7;
        transition: background 0.15s ease, border-color 0.15s ease;
    }

    .pref-channel-row:hover {
        background: #faf3e5;
        border-color: #dfd4c0;
    }

    .pref-channel-label {
        display: flex;
        align-items: center;
        gap: 11px;
        font-size: 0.88rem;
        font-weight: 750;
        color: #344536;
        margin: 0;
        cursor: pointer;
    }

    .pref-channel-label i {
        width: 18px;
        color: #c89b3c;
        text-align: center;
        font-size: 0.95rem;
    }

    .form-check-input.pref-switch {
        width: 2.85rem;
        height: 1.5rem;
        cursor: pointer;
        border-color: #cbd3cb;
        background-color: #e5e9e5;
    }

    .form-check-input.pref-switch:checked {
        background-color: #344536;
        border-color: #344536;
    }

    .form-check-input.pref-switch:focus {
        border-color: #c89b3c;
        box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.2);
    }

    .pref-save-bar {
        position: sticky;
        bottom: 24px;
        background: rgba(255, 255, 255, 0.96);
        backdrop-filter: blur(14px);
        border: 1px solid #e5dcce;
        border-radius: 16px;
        padding: 16px 24px;
        box-shadow: 0 12px 34px rgba(0, 0, 0, 0.1);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        z-index: 100;
        flex-wrap: wrap;
    }

    .pref-save-btn {
        background: #344536;
        color: #fff;
        border: 1px solid #28372a;
        font-weight: 850;
        padding: 10px 24px;
        border-radius: 11px;
        font-size: 0.92rem;
        display: inline-flex;
        align-items: center;
        gap: 9px;
        transition: all 0.2s ease;
    }

    .pref-save-btn:hover {
        background: #253327;
        color: #fff;
        box-shadow: 0 6px 18px rgba(52, 69, 54, 0.28);
        transform: translateY(-1px);
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

    <!-- Main Navigation Tabs: Feed vs Channel Preferences -->
    <div class="nav-tab-pills">
        <a class="nav-tab-pill <?php echo $active_tab === 'feed' ? 'active' : ''; ?>" href="notifications.php?tab=feed">
            <i class="fas fa-inbox"></i> Notifications Feed
            <?php if ($unread_count > 0): ?>
                <span class="badge bg-danger ms-1"><?php echo number_format($unread_count); ?></span>
            <?php endif; ?>
        </a>
        <a class="nav-tab-pill <?php echo $active_tab === 'preferences' ? 'active' : ''; ?>" href="notifications.php?tab=preferences">
            <i class="fas fa-sliders"></i> Notification Preferences
        </a>
    </div>

    <?php if ($active_tab === 'preferences'): ?>
        <!-- Dedicated Notification & Channel Preferences Section -->
        <div class="pref-container">
            <header class="pref-header">
                <div class="pref-header-accent" aria-hidden="true"></div>
                <h2><i class="fas fa-bell me-2" style="color: #c9a646;"></i> Notification & Channel Preferences</h2>
                <p>Customize how and where you receive updates from San Lorenzo Ruiz Parish. Choose your preferred delivery channels across In-System Alerts, Email Messages, and SMS Text Blasts.</p>
            </header>

            <form id="notificationPreferencesForm" method="POST" action="notifications.php?tab=preferences">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="save_preferences">

                <div class="pref-grid">
                    <!-- Category 1: Parish Announcements -->
                    <div class="pref-card">
                        <div class="pref-card-header">
                            <div class="pref-card-icon">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            <div>
                                <h3 class="pref-card-title">Parish Announcements</h3>
                                <small class="text-muted">Parish-wide bulletins & notices</small>
                            </div>
                        </div>
                        <p class="pref-card-desc">
                            Pastoral letters, feast day schedules, seasonal mass timings, parish fiesta updates, and urgent community advisories from the Parish Priest.
                        </p>
                        <div class="pref-channels">
                            <div class="pref-channel-row">
                                <label class="pref-channel-label" for="in_app_announcements">
                                    <i class="fas fa-bell"></i> In-System Alerts
                                </label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input pref-switch" type="checkbox" id="in_app_announcements" name="in_app_announcements" <?php echo !empty($user_preferences['announcements']['in_app']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="pref-channel-row">
                                <label class="pref-channel-label" for="email_announcements">
                                    <i class="fas fa-envelope"></i> Email Messages
                                </label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input pref-switch" type="checkbox" id="email_announcements" name="email_announcements" <?php echo !empty($user_preferences['announcements']['email']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="pref-channel-row">
                                <label class="pref-channel-label" for="sms_announcements">
                                    <i class="fas fa-comment-sms"></i> SMS Text Blasts
                                </label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input pref-switch" type="checkbox" id="sms_announcements" name="sms_announcements" <?php echo !empty($user_preferences['announcements']['sms']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Category 2: Request Status Updates -->
                    <div class="pref-card">
                        <div class="pref-card-header">
                            <div class="pref-card-icon">
                                <i class="fas fa-list-check"></i>
                            </div>
                            <div>
                                <h3 class="pref-card-title">Request Status Updates</h3>
                                <small class="text-muted">Certificates & service requests</small>
                            </div>
                        </div>
                        <p class="pref-card-desc">
                            Updates on submitted, pending, processing, approved, completed, or cancelled certificate applications, and requests requiring additional documents.
                        </p>
                        <div class="pref-channels">
                            <div class="pref-channel-row">
                                <label class="pref-channel-label" for="in_app_requests">
                                    <i class="fas fa-bell"></i> In-System Alerts
                                </label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input pref-switch" type="checkbox" id="in_app_requests" name="in_app_requests" <?php echo !empty($user_preferences['requests']['in_app']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="pref-channel-row">
                                <label class="pref-channel-label" for="email_requests">
                                    <i class="fas fa-envelope"></i> Email Messages
                                </label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input pref-switch" type="checkbox" id="email_requests" name="email_requests" <?php echo !empty($user_preferences['requests']['email']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="pref-channel-row">
                                <label class="pref-channel-label" for="sms_requests">
                                    <i class="fas fa-comment-sms"></i> SMS Text Blasts
                                </label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input pref-switch" type="checkbox" id="sms_requests" name="sms_requests" <?php echo !empty($user_preferences['requests']['sms']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Category 3: Schedule and Reservation Updates -->
                    <div class="pref-card">
                        <div class="pref-card-header">
                            <div class="pref-card-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div>
                                <h3 class="pref-card-title">Schedule & Reservations</h3>
                                <small class="text-muted">Sacraments & venue schedules</small>
                            </div>
                        </div>
                        <p class="pref-card-desc">
                            Confirmations, approvals, cancellations, schedule proposals, and approaching date reminders for weddings, baptisms, blessings, and masses.
                        </p>
                        <div class="pref-channels">
                            <div class="pref-channel-row">
                                <label class="pref-channel-label" for="in_app_schedules">
                                    <i class="fas fa-bell"></i> In-System Alerts
                                </label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input pref-switch" type="checkbox" id="in_app_schedules" name="in_app_schedules" <?php echo !empty($user_preferences['schedules']['in_app']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="pref-channel-row">
                                <label class="pref-channel-label" for="email_schedules">
                                    <i class="fas fa-envelope"></i> Email Messages
                                </label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input pref-switch" type="checkbox" id="email_schedules" name="email_schedules" <?php echo !empty($user_preferences['schedules']['email']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="pref-channel-row">
                                <label class="pref-channel-label" for="sms_schedules">
                                    <i class="fas fa-comment-sms"></i> SMS Text Blasts
                                </label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input pref-switch" type="checkbox" id="sms_schedules" name="sms_schedules" <?php echo !empty($user_preferences['schedules']['sms']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Category 4: System and Verification Notices -->
                    <div class="pref-card">
                        <div class="pref-card-header">
                            <div class="pref-card-icon">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <div>
                                <h3 class="pref-card-title">System & Security Notices</h3>
                                <small class="text-muted">Account verification & security</small>
                            </div>
                        </div>
                        <p class="pref-card-desc">
                            Account approval notices, ID document verification results, password change security confirmations, and system maintenance advisories.
                        </p>
                        <div class="pref-channels">
                            <div class="pref-channel-row">
                                <label class="pref-channel-label" for="in_app_system">
                                    <i class="fas fa-bell"></i> In-System Alerts
                                </label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input pref-switch" type="checkbox" id="in_app_system" name="in_app_system" <?php echo !empty($user_preferences['system']['in_app']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="pref-channel-row">
                                <label class="pref-channel-label" for="email_system">
                                    <i class="fas fa-envelope"></i> Email Messages
                                </label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input pref-switch" type="checkbox" id="email_system" name="email_system" <?php echo !empty($user_preferences['system']['email']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="pref-channel-row">
                                <label class="pref-channel-label" for="sms_system">
                                    <i class="fas fa-comment-sms"></i> SMS Text Blasts
                                </label>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input pref-switch" type="checkbox" id="sms_system" name="sms_system" <?php echo !empty($user_preferences['system']['sms']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sticky Save Action Bar -->
                <div class="pref-save-bar">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-shield-halved text-success"></i>
                        <span class="small text-muted">Preferences are saved directly to your parish account profile.</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="notifications.php?tab=feed" class="btn btn-outline-secondary btn-sm px-3">Back to Feed</a>
                        <button type="submit" id="savePreferencesBtn" class="pref-save-btn">
                            <i class="fas fa-save"></i> Save Preferences
                        </button>
                    </div>
                </div>
            </form>
        </div>
    <?php else: ?>
        <!-- Feed View -->
        <nav class="notification-summary" aria-label="Notification filters">
            <a class="notification-chip <?php echo $read_filter === 'all' && $type_filter === 'all' ? 'active' : ''; ?>" href="notifications.php">All <strong><?php echo number_format($total_count); ?></strong></a>
            <a class="notification-chip <?php echo $read_filter === 'unread' && $type_filter === 'all' ? 'active' : ''; ?>" href="notifications.php?read=unread&amp;type=all">Unread <strong><?php echo number_format($unread_count); ?></strong></a>
            <a class="notification-chip <?php echo $read_filter === 'read' && $type_filter === 'all' ? 'active' : ''; ?>" href="notifications.php?read=read&amp;type=all">Read <strong><?php echo number_format($read_count); ?></strong></a>
            <a class="notification-chip <?php echo $type_filter === 'archived' ? 'active' : ''; ?>" href="notifications.php?read=all&amp;type=archived">Archived <strong><?php echo number_format($archived_count); ?></strong></a>
        </nav>

        <div class="notification-layout">
            <aside class="notification-categories" aria-label="Notification categories">
                <div class="notification-category-header">
                    <h2>Categories</h2>
                    <?php if ($unread_count > 0): ?>
                        <form method="POST" class="notification-mobile-mark-all">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" aria-label="Mark all notifications as read" title="Mark all as read"><i class="fas fa-check-double" aria-hidden="true"></i></button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php
                    $active_category_key = 'all';
                    foreach ($category_filters as $category_key => $category) {
                        $category_read = $category['read'] ?? 'all';
                        if ($read_filter === $category_read && $type_filter === $category['type']) {
                            $active_category_key = $category_key;
                            break;
                        }
                    }
                    $active_category = $category_filters[$active_category_key];
                    $clear_category_url = 'notifications.php?read=all&type=all&sort=' . urlencode($sort);
                    if ($search !== '') {
                        $clear_category_url .= '&q=' . urlencode($search);
                    }
                ?>
                <?php if ($active_category_key !== 'all'): ?>
                    <a class="notification-active-filter" href="<?php echo e($clear_category_url); ?>" aria-label="Clear <?php echo e($active_category['label']); ?> filter">
                        <span>Filtered by: <?php echo e($active_category['label']); ?></span>
                        <i class="fas fa-xmark" aria-hidden="true"></i>
                    </a>
                <?php endif; ?>
                <div class="notification-category-card" id="notificationCategoryCard">
                    <button class="notification-category-toggle" id="notificationCategoryToggle" type="button" aria-expanded="false" aria-controls="notificationCategoryList">
                        <i class="notification-category-icon fas <?php echo e($active_category['icon']); ?>" aria-hidden="true"></i>
                        <span class="notification-category-toggle-label"><?php echo e($active_category['label']); ?></span>
                        <b class="notification-category-count"><?php echo number_format($category_counts[$active_category_key] ?? 0); ?></b>
                        <i class="notification-category-chevron fas fa-chevron-right" aria-hidden="true"></i>
                    </button>
                    <nav class="notification-category-list" id="notificationCategoryList" aria-label="Choose a notification category">
                    <?php foreach ($category_filters as $category_key => $category): ?>
                        <?php if ($category_key === 'all') { continue; } ?>
                        <?php
                            $category_read = $category['read'] ?? 'all';
                            $category_type = $category['type'];
                            $category_url = 'notifications.php?read=' . urlencode($category_read) . '&type=' . urlencode($category_type) . '&sort=' . urlencode($sort);
                            if ($search !== '') {
                                $category_url .= '&q=' . urlencode($search);
                            }
                            $is_category_active = $read_filter === $category_read && $type_filter === $category_type;
                        ?>
                        <a class="notification-category-link <?php echo $is_category_active ? 'active' : ''; ?>" href="<?php echo e($category_url); ?>" <?php echo $is_category_active ? 'aria-current="page"' : ''; ?> data-category-option>
                            <i class="notification-category-icon fas <?php echo e($category['icon']); ?>"></i>
                            <span><?php echo e($category['label']); ?></span>
                            <b class="notification-category-count"><?php echo number_format($category_counts[$category_key] ?? 0); ?></b>
                            <?php
                                $category_action_icons = [
                                    'all' => 'fa-chevron-right',
                                    'unread' => 'fa-star',
                                    'request' => 'fa-clock',
                                    'certificate' => 'fa-file-arrow-down',
                                    'announcement' => 'fa-bell',
                                    'schedule' => 'fa-clock',
                                    'account' => 'fa-shield-halved',
                                    'ai' => 'fa-wand-magic-sparkles',
                                    'archived' => 'fa-trash-can',
                                ];
                            ?>
                            <i class="notification-category-action fas <?php echo e($category_action_icons[$category_key] ?? 'fa-chevron-right'); ?>" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                        <!-- Quick Link to Preferences -->
                        <a class="notification-category-link" href="notifications.php?tab=preferences">
                            <i class="notification-category-icon fas fa-sliders"></i>
                            <span>Preferences</span>
                            <i class="notification-category-action fas fa-gear ms-auto" aria-hidden="true"></i>
                        </a>
                    </nav>
                </div>
            </aside>

            <main class="notification-feed-panel">
                <section class="notification-toolbar" aria-label="Notification filters">
                    <form method="GET" class="notification-toolbar-grid">
                        <div class="input-with-icon toolbar-search">
                            <i class="fas fa-search"></i>
                            <input type="search" class="form-control toolbar-control" name="q" value="<?php echo e($search); ?>" placeholder="Search notifications, requests, or updates...">
                        </div>
                        <select name="read" class="form-select toolbar-control">
                            <option value="all" <?php echo $read_filter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="unread" <?php echo $read_filter === 'unread' ? 'selected' : ''; ?>>Unread only</option>
                            <option value="read" <?php echo $read_filter === 'read' ? 'selected' : ''; ?>>Read only</option>
                        </select>
                        <select name="type" class="form-select toolbar-control">
                            <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All types</option>
                            <?php foreach ($notification_type_catalog as $key => $type_meta): ?>
                                <option value="<?php echo e($key); ?>" <?php echo $type_filter === $key ? 'selected' : ''; ?>><?php echo e($type_meta['label']); ?></option>
                            <?php endforeach; ?>
                            <option value="archived" <?php echo $type_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                        </select>
                        <select name="sort" class="form-select toolbar-control">
                            <option value="latest" <?php echo $sort === 'latest' ? 'selected' : ''; ?>>Latest first</option>
                            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest first</option>
                        </select>
                        <button type="submit" class="btn btn-primary toolbar-control"><i class="fas fa-filter"></i> Filter</button>
                    </form>
                    <?php if ($unread_count > 0): ?>
                        <form method="POST" class="notification-mark-all-form">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-check-double"></i> Mark All as Read</button>
                        </form>
                    <?php endif; ?>
                </section>

                <?php if (!empty($grouped_notifications)): ?>
                    <?php foreach ($grouped_notifications as $group => $items): ?>
                        <h2 class="notification-group-title"><?php echo e($group); ?></h2>
                        <div class="notification-list">
                            <?php foreach ($items as $notification): ?>
                                <?php
                                    $meta = notificationTypeMeta($notification);
                                    $detail_id = 'notification-detail-' . intval($notification['notification_id']);
                                    $reference_number = notificationReferenceNumber($notification);
                                ?>
                                <article class="notification-card <?php echo !$notification['is_read'] ? 'unread' : 'read'; ?>">
                                    <a class="notification-card-link" href="#<?php echo e($detail_id); ?>" aria-label="Open notification details"></a>
                                    <div class="notification-icon <?php echo e($meta['tone']); ?>"><i class="fas <?php echo e($meta['icon']); ?>"></i></div>
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
                                        <p><?php echo e(notificationShortMessage($notification['message'])); ?></p>
                                        <div class="notification-meta">
                                            <span><i class="fas fa-clock"></i> <?php echo e(formatDateTime($notification['created_at'])); ?></span>
                                            <span><i class="fas fa-hashtag"></i> <?php echo e($reference_number); ?></span>
                                        </div>
                                    </div>
                                    <div class="notification-actions">
                                        <a class="btn btn-sm btn-outline-primary" href="<?php echo e(notificationActionUrl($notification)); ?>"><i class="fas fa-arrow-up-right-from-square"></i> View</a>
                                        <?php if (!$notification['is_read']): ?>
                                            <form method="POST">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="notification_id" value="<?php echo intval($notification['notification_id']); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success"><i class="fas fa-check"></i> Mark as Read</button>
                                            </form>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled><i class="fas fa-envelope-open"></i> Read</button>
                                        <?php endif; ?>
                                        <form method="POST"><?php echo csrfInput(); ?><input type="hidden" name="action" value="archive_notification"><input type="hidden" name="notification_id" value="<?php echo intval($notification['notification_id']); ?>"><button class="btn btn-sm btn-outline-secondary"><i class="fas fa-box-archive"></i> Archive</button></form>
                                        <form method="POST" onsubmit="return confirm('Delete this notification?');">
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="action" value="delete_notification">
                                            <input type="hidden" name="notification_id" value="<?php echo intval($notification['notification_id']); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i> Delete</button>
                                        </form>
                                    </div>
                                </article>

                                <aside class="notification-detail-panel" id="<?php echo e($detail_id); ?>" aria-label="Notification detail panel">
                                    <div class="detail-panel-header">
                                        <div>
                                            <span class="notification-type <?php echo e($meta['tone']); ?>"><i class="fas <?php echo e($meta['icon']); ?>"></i> <?php echo e($meta['label']); ?></span>
                                            <h2 class="mt-2"><?php echo e($notification['title']); ?></h2>
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
                                            <li><span>Reference</span><strong><?php echo e($reference_number); ?></strong></li>
                                            <li><span>Category</span><strong><?php echo e($meta['label']); ?></strong></li>
                                            <li><span>Status</span><strong><?php echo $notification['is_read'] ? 'Read' : 'Unread'; ?></strong></li>
                                            <li><span>Received</span><strong><?php echo e(formatDateTime($notification['created_at'])); ?></strong></li>
                                        </ul>
                                    </div>
                                    <div class="detail-panel-card">
                                        <h3>Timeline</h3>
                                        <ol class="timeline-list">
                                            <li>Notification created on <?php echo e(formatDateTime($notification['created_at'])); ?>.</li>
                                            <li><?php echo $notification['is_read'] ? 'Marked as read by the parishioner.' : 'Awaiting parishioner review.'; ?></li>
                                        </ol>
                                    </div>
                                    <div class="detail-panel-card">
                                        <h3>Attached Documents</h3>
                                        <p class="mb-0">No attached documents are linked to this notification.</p>
                                    </div>
                                    <div class="detail-panel-card">
                                        <h3>Action History</h3>
                                        <p class="mb-0">Available actions are shown on the notification card: view, mark as read, archive, and delete.</p>
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
                    <nav class="mt-3" aria-label="Notification pages">
                        <ul class="pagination flex-wrap">
                            <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo e(http_build_query(['q' => $search, 'read' => $read_filter, 'type' => $type_filter, 'sort' => $sort, 'page' => $p])); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            </main>
        </div>
    <?php endif; ?>
</div>

<div class="notification-mobile-spinner" aria-hidden="true"><span></span></div>

<script>
    (function () {
        var card = document.getElementById('notificationCategoryCard');
        var toggle = document.getElementById('notificationCategoryToggle');
        if (card && toggle) {
            toggle.addEventListener('click', function () {
                var isOpen = card.classList.toggle('open');
                toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            card.querySelectorAll('[data-category-option]').forEach(function (option) {
                option.addEventListener('click', function () {
                    card.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                });
            });
        }

        // AJAX Save Preferences handler
        var prefForm = document.getElementById('notificationPreferencesForm');
        var saveBtn = document.getElementById('savePreferencesBtn');
        if (prefForm && saveBtn) {
            prefForm.addEventListener('submit', function (e) {
                if (!window.fetch) return; // Allow native form submit if fetch is unsupported
                e.preventDefault();
                var originalHtml = saveBtn.innerHTML;
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

                var formData = new FormData(prefForm);
                fetch('../api/notifications.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    saveBtn.disabled = false;
                    if (data.success) {
                        saveBtn.innerHTML = '<i class="fas fa-check"></i> Preferences Saved!';
                        setTimeout(function () { saveBtn.innerHTML = originalHtml; }, 2500);
                    } else {
                        saveBtn.innerHTML = '<i class="fas fa-circle-exclamation"></i> Error';
                        alert(data.error || 'Unable to save preferences.');
                        setTimeout(function () { saveBtn.innerHTML = originalHtml; }, 2500);
                    }
                })
                .catch(function () {
                    // Fallback to native submission
                    prefForm.submit();
                });
            });
        }
    }());
</script>

<?php include '../templates/footer.php'; ?>
