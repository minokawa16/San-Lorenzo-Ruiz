<?php
/**
 * Manage Announcements Page
 * Admin interface for managing parish announcements
 */

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('announcements.manage');

$error = '';
$success = '';

ensureExpandedAnnouncementTypeSchema($conn);
ensureAnnouncementAttachmentSchema($conn);
ensureEmailNotificationSchema($conn);

$announcement_types = [
    'announcement' => 'General Announcement',
    'parish_event' => 'Parish Event',
    'mass_schedule' => 'Mass Schedule',
    'monthly_schedule' => 'Monthly Schedule',
    'patronal_fiesta_schedule' => 'Patronal Fiesta Schedule',
    'sacramental_activity' => 'Sacramental Activities',
    'important_notice' => 'Important Notice'
];

$announcement_meta = [
    'announcement' => ['icon' => 'fa-bullhorn', 'tone' => 'general', 'label' => 'General Announcement'],
    'parish_event' => ['icon' => 'fa-people-group', 'tone' => 'event', 'label' => 'Parish Event'],
    'mass_schedule' => ['icon' => 'fa-church', 'tone' => 'schedule', 'label' => 'Mass Schedule'],
    'monthly_schedule' => ['icon' => 'fa-calendar-days', 'tone' => 'schedule', 'label' => 'Monthly Schedule'],
    'patronal_fiesta_schedule' => ['icon' => 'fa-star', 'tone' => 'event', 'label' => 'Patronal Fiesta'],
    'sacramental_activity' => ['icon' => 'fa-hands-praying', 'tone' => 'sacrament', 'label' => 'Sacramental Activities'],
    'important_notice' => ['icon' => 'fa-circle-exclamation', 'tone' => 'important', 'label' => 'Important Notice']
];

// Ensure Announcement Management Schema Function - Documents this helper's role in the parish management workflow.
function ensureAnnouncementManagementSchema($conn) {
    if (!columnExists($conn, 'announcements', 'deleted_at')) {
        $conn->query("ALTER TABLE announcements ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
    }
    if (!columnExists($conn, 'announcements', 'event_date')) {
        $conn->query("ALTER TABLE announcements ADD COLUMN event_date DATE NULL AFTER expiry_date");
    }
    if (!columnExists($conn, 'announcements', 'is_pinned')) {
        $conn->query("ALTER TABLE announcements ADD COLUMN is_pinned TINYINT(1) DEFAULT 0 AFTER event_date");
    }
    if (!columnExists($conn, 'announcements', 'scheduled_at')) {
        $conn->query("ALTER TABLE announcements ADD COLUMN scheduled_at DATETIME NULL AFTER published_date");
    }
    return true;
}

// Announcement Type Meta Function - Documents this helper's role in the parish management workflow.
function announcementTypeMeta($type, $meta) {
    return $meta[$type] ?? ['icon' => 'fa-bullhorn', 'tone' => 'general', 'label' => ucfirst(str_replace('_', ' ', (string) $type))];
}

// Announcement Preview Text Function - Documents this helper's role in the parish management workflow.
function announcementPreviewText($content, $length = 210) {
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string) $content)));
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($plain, 0, $length, '...');
    }
    return strlen($plain) > $length ? substr($plain, 0, $length - 3) . '...' : $plain;
}

// Clean Announcement Content Function - Documents this helper's role in the parish management workflow.
function cleanAnnouncementContent($content) {
    $content = trim((string) $content);
    $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
    $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $content);
    return strip_tags($content, '<p><br><strong><b><em><i><u><ul><ol><li><a>');
}

// Active Announcement Where Function - Documents this helper's role in the parish management workflow.
function activeAnnouncementWhere() {
    return "deleted_at IS NULL AND status = 'active' AND (scheduled_at IS NULL OR scheduled_at <= NOW())";
}

// Save Announcement Attachment Function - Documents this helper's role in the parish management workflow.
function saveAnnouncementAttachment($file) {
    if (!isset($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'original_name' => null, 'mime_type' => null, 'size' => null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Unable to upload the attachment. Please try again.'];
    }

    $config = getAnnouncementAttachmentConfig();
    $original_name = basename($file['name']);
    $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $size = intval($file['size']);
    $tmp_name = $file['tmp_name'];
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime_type = $finfo ? finfo_file($finfo, $tmp_name) : mime_content_type($tmp_name);
    if ($finfo) {
        finfo_close($finfo);
    }

    if ($size > $config['max_size']) {
        return ['ok' => false, 'error' => 'Attachment must not exceed 10MB.'];
    }

    if (!in_array($extension, $config['extensions'], true) || !in_array($mime_type, $config['mime_types'], true)) {
        return ['ok' => false, 'error' => 'Attachment type is not allowed. Use an image, PDF, Office document, or text file.'];
    }

    $upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'announcements';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $index_file = $upload_dir . DIRECTORY_SEPARATOR . 'index.php';
    if (!is_file($index_file)) {
        file_put_contents($index_file, "<?php\nhttp_response_code(403);\nexit('Access denied');\n");
    }
    $htaccess_file = $upload_dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess_file)) {
        file_put_contents($htaccess_file, "Options -Indexes\nRequire all denied\nDeny from all\n");
    }

    $safe_filename = 'announcement-' . date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
    $target_path = $upload_dir . DIRECTORY_SEPARATOR . $safe_filename;
    if (!move_uploaded_file($tmp_name, $target_path)) {
        return ['ok' => false, 'error' => 'Unable to save the announcement attachment.'];
    }

    return [
        'ok' => true,
        'path' => 'uploads/announcements/' . $safe_filename,
        'original_name' => $original_name,
        'mime_type' => $mime_type,
        'size' => $size
    ];
}

// Dispatch Announcement Notifications Function - Documents this helper's role in the parish management workflow.
function dispatchAnnouncementNotifications($conn, $announcement_id, $title, $content, $send_email = true, $send_system = true) {
    $announcement_id = intval($announcement_id);
    $recipients = $conn->query("SELECT u.id, u.email, u.fullname, COALESCE(np.email_enabled, 1) AS email_enabled, COALESCE(np.in_app_enabled, 1) AS in_app_enabled
        FROM users u
        LEFT JOIN notification_preferences np ON np.user_id = u.id AND np.category = 'announcements'
        WHERE u.role = 'user' AND u.status = 'active'");
    $sent_count = 0;
    $system_count = 0;
    $failed_count = 0;
    $view_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'users/announcements.php';

    while ($recipients && $recipient = $recipients->fetch_assoc()) {
        $delivery_status = 'skipped';
        if ($send_system && intval($recipient['in_app_enabled']) === 1) {
            if (createNotification($conn, intval($recipient['id']), 'New Parish Announcement', $title)) {
                $system_count++;
            }
        }
        if ($send_email && intval($recipient['email_enabled']) === 1) {
            $email_body = '<p><strong>' . e($title) . '</strong></p><p>' . nl2br(e(strip_tags($content))) . '</p><p>Published: ' . e(formatDateTime(date('Y-m-d H:i:s'))) . '</p>';
            $sent = sendTugonEmail($conn, $recipient['email'], 'New Parish Announcement: ' . $title, tugonEmailTemplate('Parish Announcement', $email_body, 'View Announcement', $view_url), '', $recipient['id'], 'announcement');
            $delivery_status = $sent['ok'] ? 'sent' : 'failed';
            $sent['ok'] ? $sent_count++ : $failed_count++;
        }

        $log_stmt = $conn->prepare("INSERT INTO announcement_recipients (announcement_id, user_id, email, delivery_status, sent_at)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE delivery_status = VALUES(delivery_status), sent_at = VALUES(sent_at)");
        if ($log_stmt) {
            $sent_at = $delivery_status === 'sent' ? date('Y-m-d H:i:s') : null;
            $uid = intval($recipient['id']);
            $log_stmt->bind_param('iisss', $announcement_id, $uid, $recipient['email'], $delivery_status, $sent_at);
            $log_stmt->execute();
            $log_stmt->close();
        }
    }

    return ['sent' => $sent_count, 'system' => $system_count, 'failed' => $failed_count];
}

// Publish Due Scheduled Announcements Function - Documents this helper's role in the parish management workflow.
function publishDueScheduledAnnouncements($conn) {
    $due = $conn->query("SELECT announcement_id, title, content FROM announcements WHERE deleted_at IS NULL AND status = 'inactive' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()");
    while ($due && $announcement = $due->fetch_assoc()) {
        $id = intval($announcement['announcement_id']);
        if ($conn->query("UPDATE announcements SET status = 'active', published_date = NOW() WHERE announcement_id = $id")) {
            dispatchAnnouncementNotifications($conn, $id, $announcement['title'], $announcement['content'], true, true);
        }
    }
}

ensureAnnouncementManagementSchema($conn);
publishDueScheduledAnnouncements($conn);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    $announcement_id = intval($_POST['announcement_id'] ?? 0);

    if ($action === 'archive_announcement' && $announcement_id > 0) {
        if ($conn->query("UPDATE announcements SET deleted_at = NOW(), status = 'inactive', is_pinned = 0 WHERE announcement_id = $announcement_id")) {
            createAuditLog($conn, $_SESSION['user_id'], 'ARCHIVE_ANNOUNCEMENT', 'announcements', $announcement_id);
            $success = 'Announcement archived successfully.';
        } else {
            $error = 'Error archiving announcement: ' . $conn->error;
        }
    } elseif ($action === 'delete_announcement' && $announcement_id > 0) {
        $file_result = $conn->query("SELECT attachment_path FROM announcements WHERE announcement_id = $announcement_id");
        $file_row = $file_result ? $file_result->fetch_assoc() : null;
        $conn->query("DELETE FROM announcement_recipients WHERE announcement_id = $announcement_id");
        if ($conn->query("DELETE FROM announcements WHERE announcement_id = $announcement_id")) {
            if (!empty($file_row['attachment_path'])) {
                $full_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . $file_row['attachment_path'];
                if (is_file($full_path)) {
                    @unlink($full_path);
                }
            }
            createAuditLog($conn, $_SESSION['user_id'], 'DELETE_ANNOUNCEMENT', 'announcements', $announcement_id);
            $success = 'Announcement deleted permanently.';
        } else {
            $error = 'Error deleting announcement: ' . $conn->error;
        }
    } elseif ($action === 'toggle_pin' && $announcement_id > 0) {
        $conn->query("UPDATE announcements SET is_pinned = IF(is_pinned = 1, 0, 1) WHERE announcement_id = $announcement_id AND deleted_at IS NULL");
        createAuditLog($conn, $_SESSION['user_id'], 'PIN_ANNOUNCEMENT', 'announcements', $announcement_id);
        $success = 'Pinned announcement setting updated.';
    } elseif ($action === 'duplicate_announcement' && $announcement_id > 0) {
        $stmt = $conn->prepare("INSERT INTO announcements (title, content, image_path, attachment_path, attachment_original_name, attachment_mime_type, attachment_size, type, published_by, published_date, scheduled_at, expiry_date, event_date, status, is_pinned)
            SELECT CONCAT('Copy of ', title), content, image_path, attachment_path, attachment_original_name, attachment_mime_type, attachment_size, type, ?, NOW(), NULL, expiry_date, event_date, 'inactive', 0
            FROM announcements WHERE announcement_id = ?");
        if ($stmt) {
            $admin_id = intval($_SESSION['user_id']);
            $stmt->bind_param('ii', $admin_id, $announcement_id);
            if ($stmt->execute()) {
                createAuditLog($conn, $_SESSION['user_id'], 'DUPLICATE_ANNOUNCEMENT', 'announcements', $stmt->insert_id);
                $success = 'Announcement duplicated as an inactive draft.';
            } else {
                $error = 'Error duplicating announcement: ' . $stmt->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'send_notification' && $announcement_id > 0) {
        $announcement = $conn->query("SELECT title, content FROM announcements WHERE announcement_id = $announcement_id")->fetch_assoc();
        if ($announcement) {
            $result = dispatchAnnouncementNotifications($conn, $announcement_id, $announcement['title'], $announcement['content'], true, true);
            createAuditLog($conn, $_SESSION['user_id'], 'SEND_ANNOUNCEMENT_NOTIFICATION', 'announcements', $announcement_id);
            $success = 'Notifications processed: ' . $result['system'] . ' dashboard, ' . $result['sent'] . ' email sent, ' . $result['failed'] . ' email failed.';
        }
    } elseif (in_array($action, ['add_announcement', 'edit_announcement'], true)) {
        $title = trim(sanitize($_POST['title'] ?? ''));
        $content = cleanAnnouncementContent($_POST['content'] ?? '');
        $type_raw = $_POST['type'] ?? 'announcement';
        $type = array_key_exists($type_raw, $announcement_types) ? $type_raw : 'announcement';
        $event_date = trim($_POST['event_date'] ?? '');
        $event_date_value = $event_date !== '' ? $event_date : null;
        $publish_mode = $_POST['publish_mode'] ?? 'now';
        $scheduled_at = trim($_POST['scheduled_at'] ?? '');
        $scheduled_value = $publish_mode === 'later' && $scheduled_at !== '' ? str_replace('T', ' ', $scheduled_at) . ':00' : null;
        $status = $publish_mode === 'later' ? 'inactive' : 'active';
        $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
        $notify_all = isset($_POST['notify_all']);
        $notify_email = isset($_POST['notify_email']);
        $notify_system = isset($_POST['notify_system']);

        if ($title === '' || trim(strip_tags($content)) === '') {
            $error = 'Please add a title and announcement content.';
        } elseif ($publish_mode === 'later' && $scheduled_at === '') {
            $error = 'Please choose a schedule date and time.';
        } else {
            $attachment = saveAnnouncementAttachment($_FILES['attachment'] ?? null);
            if (!$attachment['ok']) {
                $error = $attachment['error'];
            } elseif ($action === 'add_announcement') {
                $image_path = isAnnouncementImageAttachment($attachment['mime_type'] ?? '') ? $attachment['path'] : null;
                $stmt = $conn->prepare("INSERT INTO announcements (title, content, image_path, attachment_path, attachment_original_name, attachment_mime_type, attachment_size, type, published_by, published_date, scheduled_at, status, event_date, is_pinned)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)");
                if ($stmt) {
                    $admin_id = intval($_SESSION['user_id']);
                    $attachment_size = $attachment['size'];
                    $stmt->bind_param('ssssssisisssi', $title, $content, $image_path, $attachment['path'], $attachment['original_name'], $attachment['mime_type'], $attachment_size, $type, $admin_id, $scheduled_value, $status, $event_date_value, $is_pinned);
                    if ($stmt->execute()) {
                        $new_id = $stmt->insert_id;
                        createAuditLog($conn, $_SESSION['user_id'], 'ADD_ANNOUNCEMENT', 'announcements', $new_id);
                        if ($status === 'active' && ($notify_all || $notify_email || $notify_system)) {
                            dispatchAnnouncementNotifications($conn, $new_id, $title, $content, $notify_all || $notify_email, $notify_all || $notify_system);
                        }
                        $success = $status === 'active' ? 'Announcement published successfully.' : 'Announcement scheduled successfully.';
                    } else {
                        $error = 'Error posting announcement: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error = 'Error preparing announcement: ' . $conn->error;
                }
            } else {
                $attachment_sql = '';
                $params = [$title, $content, $type, $scheduled_value, $status, $event_date_value, $is_pinned, $announcement_id];
                $types = 'ssssssii';
                if (!empty($attachment['path'])) {
                    $image_path = isAnnouncementImageAttachment($attachment['mime_type'] ?? '') ? $attachment['path'] : null;
                    $attachment_sql = ', image_path = ?, attachment_path = ?, attachment_original_name = ?, attachment_mime_type = ?, attachment_size = ?';
                    $params = [$title, $content, $type, $scheduled_value, $status, $event_date_value, $is_pinned, $image_path, $attachment['path'], $attachment['original_name'], $attachment['mime_type'], $attachment['size'], $announcement_id];
                    $types = 'ssssssissssii';
                }
                $stmt = $conn->prepare("UPDATE announcements SET title = ?, content = ?, type = ?, scheduled_at = ?, status = ?, event_date = ?, is_pinned = ?$attachment_sql WHERE announcement_id = ?");
                if ($stmt) {
                    $stmt->bind_param($types, ...$params);
                    if ($stmt->execute()) {
                        createAuditLog($conn, $_SESSION['user_id'], 'EDIT_ANNOUNCEMENT', 'announcements', $announcement_id);
                        if ($status === 'active' && ($notify_all || $notify_email || $notify_system)) {
                            dispatchAnnouncementNotifications($conn, $announcement_id, $title, $content, $notify_all || $notify_email, $notify_all || $notify_system);
                        }
                        $success = 'Announcement updated successfully.';
                    } else {
                        $error = 'Error updating announcement: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error = 'Error preparing announcement update: ' . $conn->error;
                }
            }
        }
    }
}

$page = max(1, intval($_GET['page'] ?? 1));
$limit = 9;
$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? 'all';
$valid_filters = ['all', 'announcement', 'parish_event', 'mass_schedule', 'monthly_schedule', 'patronal_fiesta_schedule', 'sacramental_activity', 'important_notice', 'scheduled', 'archived'];
if (!in_array($filter, $valid_filters, true)) {
    $filter = 'all';
}

$where_parts = [];
if ($filter === 'archived') {
    $where_parts[] = 'a.deleted_at IS NOT NULL';
} else {
    $where_parts[] = 'a.deleted_at IS NULL';
    if ($filter === 'scheduled') {
        $where_parts[] = "a.status = 'inactive' AND a.scheduled_at IS NOT NULL AND a.scheduled_at > NOW()";
    } elseif ($filter !== 'all') {
        $safe_filter = $conn->real_escape_string($filter);
        $where_parts[] = "a.type = '$safe_filter'";
    }
}
if ($search !== '') {
    $safe_search = $conn->real_escape_string($search);
    $where_parts[] = "(a.title LIKE '%$safe_search%' OR a.content LIKE '%$safe_search%' OR a.type LIKE '%$safe_search%')";
}
$where = 'WHERE ' . implode(' AND ', $where_parts);

$total_result = $conn->query("SELECT COUNT(*) AS count FROM announcements a $where");
$total = $total_result ? intval($total_result->fetch_assoc()['count']) : 0;
$pagination = getPaginationData($page, $limit, $total);

$announcements = [];
$sql = "SELECT a.*, COALESCE(u.fullname, 'Parish Office') AS fullname,
        (SELECT COUNT(*) FROM announcement_recipients ar WHERE ar.announcement_id = a.announcement_id AND ar.delivery_status = 'sent') AS sent_emails
        FROM announcements a
        LEFT JOIN users u ON a.published_by = u.id
        $where
        ORDER BY a.is_pinned DESC, a.published_date DESC
        LIMIT {$pagination['offset']}, {$pagination['limit']}";
$result = $conn->query($sql);
while ($result && $row = $result->fetch_assoc()) {
    $announcements[] = $row;
}

$featured = null;
$featured_result = $conn->query("SELECT a.*, COALESCE(u.fullname, 'Parish Office') AS fullname
    FROM announcements a
    LEFT JOIN users u ON a.published_by = u.id
    WHERE " . activeAnnouncementWhere() . "
    ORDER BY a.is_pinned DESC, FIELD(a.type, 'important_notice') DESC, a.published_date DESC
    LIMIT 1");
if ($featured_result) {
    $featured = $featured_result->fetch_assoc();
}

$stats = [
    'total' => 0,
    'active' => 0,
    'scheduled' => 0,
    'archived' => 0
];
$stats['total'] = intval(($conn->query("SELECT COUNT(*) AS count FROM announcements WHERE deleted_at IS NULL")->fetch_assoc()['count'] ?? 0));
$stats['active'] = intval(($conn->query("SELECT COUNT(*) AS count FROM announcements WHERE " . activeAnnouncementWhere())->fetch_assoc()['count'] ?? 0));
$stats['scheduled'] = intval(($conn->query("SELECT COUNT(*) AS count FROM announcements WHERE deleted_at IS NULL AND status = 'inactive' AND scheduled_at IS NOT NULL AND scheduled_at > NOW()")->fetch_assoc()['count'] ?? 0));
$stats['archived'] = intval(($conn->query("SELECT COUNT(*) AS count FROM announcements WHERE deleted_at IS NOT NULL")->fetch_assoc()['count'] ?? 0));

$page_title = 'Manage Announcements';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Manage Announcements' => null
];
?>
<?php include '../templates/header.php'; ?>

<style>
    .announcement-admin-page { max-width: 1480px; margin: 0 auto; color: #172033; }
    .announcement-shell { background: #f7f9fc; border: 1px solid #e7ecf2; border-radius: 8px; padding: 18px; }
    .announcement-hero { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 16px; align-items: center; margin-bottom: 16px; }
    .announcement-hero h1 { margin: 0 0 6px; font-size: 1.75rem; font-weight: 900; letter-spacing: 0; }
    .announcement-hero p { margin: 0; color: #667085; line-height: 1.55; }
    .gold-kicker, .category-badge, .status-pill { display: inline-flex; align-items: center; gap: 7px; min-height: 30px; padding: 6px 10px; border-radius: 999px; font-size: .78rem; font-weight: 850; }
    .gold-kicker { margin-bottom: 10px; color: #80611b; background: #fff8df; border: 1px solid rgba(212, 175, 55, .34); }
    .stat-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
    .stat-card, .featured-panel, .filter-panel, .announcement-card, .empty-panel { background: #fff; border: 1px solid #e3e9f0; border-radius: 8px; box-shadow: 0 12px 28px rgba(30, 41, 59, .07); }
    .stat-card { padding: 16px; display: flex; gap: 12px; align-items: center; }
    .stat-icon { width: 46px; height: 46px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; color: #17446a; background: #eef5fb; font-size: 1.15rem; }
    .stat-card strong { display: block; font-size: 1.55rem; line-height: 1; }
    .stat-card span { color: #667085; font-size: .86rem; font-weight: 700; }
    .featured-panel { display: grid; grid-template-columns: minmax(0, 1fr) 220px; gap: 0; margin-bottom: 16px; overflow: hidden; border-left: 4px solid #d4af37; }
    .featured-body { padding: 20px; }
    .featured-body h2 { font-size: 1.35rem; font-weight: 900; margin: 6px 0 8px; }
    .featured-body p, .announcement-card p { color: #667085; line-height: 1.6; margin: 0; }
    .featured-art { background: linear-gradient(135deg, #eef5fb, #fff8df); display: grid; place-items: center; color: #17446a; min-height: 190px; }
    .featured-art img { width: 100%; height: 100%; object-fit: cover; }
    .featured-art i { font-size: 3.2rem; opacity: .82; }
    .filter-panel { padding: 14px; margin-bottom: 16px; }
    .input-with-icon { position: relative; }
    .input-with-icon i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
    .input-with-icon .form-control { padding-left: 42px; }
    .control-lg { min-height: 46px; border-radius: 8px; border-color: #dfe4ea; }
    .filter-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .filter-tabs a { min-height: 36px; display: inline-flex; align-items: center; gap: 7px; padding: 7px 11px; border-radius: 999px; border: 1px solid #dfe4ea; color: #334155; background: #fff; text-decoration: none; font-size: .82rem; font-weight: 850; }
    .filter-tabs a.active, .filter-tabs a:hover { background: #fff8df; border-color: rgba(212, 175, 55, .48); color: #171205; }
    .announcement-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
    .announcement-card { display: flex; flex-direction: column; min-height: 100%; overflow: hidden; }
    .card-media { height: 148px; display: grid; place-items: center; color: #17446a; background: linear-gradient(135deg, #f8fafc, #eef5fb); }
    .card-media img { width: 100%; height: 100%; object-fit: cover; }
    .card-media i { font-size: 2.25rem; opacity: .82; }
    .card-main { padding: 16px; display: grid; gap: 10px; flex: 1; }
    .card-main h3 { margin: 0; font-size: 1.06rem; font-weight: 900; line-height: 1.3; }
    .announcement-meta { display: flex; flex-wrap: wrap; gap: 8px 12px; color: #667085; font-size: .83rem; }
    .announcement-meta span { display: inline-flex; gap: 6px; align-items: center; }
    .category-badge { border: 1px solid transparent; }
    .category-badge.general { background: #eef5fb; color: #17446a; }
    .category-badge.event { background: #f0fdf4; color: #166534; }
    .category-badge.schedule { background: #fff8df; color: #80611b; }
    .category-badge.sacrament { background: #f5f3ff; color: #5b21b6; }
    .category-badge.important { background: #fff1f2; color: #9f1239; }
    .status-pill.active { color: #166534; background: #dcfce7; }
    .status-pill.scheduled { color: #80611b; background: #fff8df; }
    .status-pill.archived { color: #475569; background: #f1f5f9; }
    .action-row { display: flex; flex-wrap: wrap; gap: 8px; padding: 0 16px 16px; }
    .icon-btn { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
    .empty-panel { padding: 44px 18px; text-align: center; color: #667085; }
    .editor-toolbar { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px; background: #f8fafc; border: 1px solid #dfe4ea; border-bottom: 0; border-radius: 8px 8px 0 0; }
    .editor-toolbar button { width: 34px; height: 34px; border: 1px solid #dfe4ea; background: #fff; border-radius: 7px; }
    .rich-editor { min-height: 190px; padding: 12px; border: 1px solid #dfe4ea; border-radius: 0 0 8px 8px; background: #fff; line-height: 1.6; outline: none; }
    .modal .form-label { font-weight: 800; color: #334155; }
    @media (max-width: 1180px) { .announcement-grid, .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 768px) { .announcement-shell { padding: 12px; } .announcement-hero, .featured-panel, .announcement-grid, .stat-grid { grid-template-columns: 1fr; } .announcement-hero .text-end { text-align: left !important; } }
</style>

<div class="container-fluid mt-4">
    <?php include '../includes/breadcrumb.php'; ?>
    <?php include '../includes/back_button.php'; ?>

    <div class="announcement-admin-page">
        <div class="announcement-shell">
            <section class="announcement-hero">
                <div>
                    <span class="gold-kicker"><i class="fas fa-bullhorn"></i> Parish Communication Center</span>
                    <h1>Manage Announcements</h1>
                    <p>Create, schedule, pin, archive, and distribute official parish announcements for parishioners.</p>
                </div>
                <div class="text-end">
                    <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#announcementModal">
                        <i class="fas fa-plus"></i> New Announcement
                    </button>
                </div>
            </section>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show"><?php echo e($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show"><?php echo e($success); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <section class="stat-grid" aria-label="Announcement statistics">
                <div class="stat-card"><span class="stat-icon"><i class="fas fa-layer-group"></i></span><div><strong><?php echo $stats['total']; ?></strong><span>Total Announcements</span></div></div>
                <div class="stat-card"><span class="stat-icon"><i class="fas fa-check"></i></span><div><strong><?php echo $stats['active']; ?></strong><span>Active Announcements</span></div></div>
                <div class="stat-card"><span class="stat-icon"><i class="fas fa-clock"></i></span><div><strong><?php echo $stats['scheduled']; ?></strong><span>Scheduled Announcements</span></div></div>
                <div class="stat-card"><span class="stat-icon"><i class="fas fa-archive"></i></span><div><strong><?php echo $stats['archived']; ?></strong><span>Archived Announcements</span></div></div>
            </section>

            <?php if ($featured): ?>
                <?php $featured_meta = announcementTypeMeta($featured['type'], $announcement_meta); ?>
                <section class="featured-panel">
                    <div class="featured-body">
                        <span class="gold-kicker"><i class="fas fa-star"></i> Featured Announcement</span>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <span class="category-badge <?php echo e($featured_meta['tone']); ?>"><i class="fas <?php echo e($featured_meta['icon']); ?>"></i> <?php echo e($featured_meta['label']); ?></span>
                            <?php if (intval($featured['is_pinned'] ?? 0) === 1): ?><span class="status-pill scheduled"><i class="fas fa-thumbtack"></i> Pinned</span><?php endif; ?>
                        </div>
                        <h2><?php echo e($featured['title']); ?></h2>
                        <p><?php echo e(announcementPreviewText($featured['content'], 260)); ?></p>
                        <div class="announcement-meta mt-3">
                            <span><i class="fas fa-user"></i> Posted by <?php echo e($featured['fullname']); ?></span>
                            <span><i class="fas fa-calendar"></i> <?php echo e(formatDateTime($featured['published_date'])); ?></span>
                        </div>
                    </div>
                    <div class="featured-art">
                        <?php if (!empty($featured['attachment_path']) && isAnnouncementImageAttachment($featured['attachment_mime_type'] ?? '')): ?>
                            <img src="../announcement-attachment.php?id=<?php echo intval($featured['announcement_id']); ?>" alt="<?php echo e($featured['attachment_original_name'] ?: 'Featured announcement image'); ?>">
                        <?php else: ?>
                            <i class="fas <?php echo e($featured_meta['icon']); ?>"></i>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <form method="GET" class="filter-panel">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-7">
                        <label class="form-label">Search Announcements</label>
                        <div class="input-with-icon">
                            <i class="fas fa-search"></i>
                            <input class="form-control control-lg" type="search" name="q" value="<?php echo e($search); ?>" placeholder="Search title, keywords, or content...">
                        </div>
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label">Filter</label>
                        <select class="form-select control-lg" name="filter">
                            <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Announcements</option>
                            <?php foreach ($announcement_types as $value => $label): ?>
                                <option value="<?php echo e($value); ?>" <?php echo $filter === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                            <option value="scheduled" <?php echo $filter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="archived" <?php echo $filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                        </select>
                    </div>
                    <div class="col-lg-2 d-grid">
                        <button class="btn btn-primary control-lg" type="submit"><i class="fas fa-filter"></i> Apply</button>
                    </div>
                </div>
                <div class="filter-tabs">
                    <a class="<?php echo $filter === 'all' ? 'active' : ''; ?>" href="manage-announcements.php?q=<?php echo urlencode($search); ?>"><i class="fas fa-layer-group"></i> All</a>
                    <a class="<?php echo $filter === 'announcement' ? 'active' : ''; ?>" href="?filter=announcement&q=<?php echo urlencode($search); ?>">General</a>
                    <a class="<?php echo $filter === 'parish_event' ? 'active' : ''; ?>" href="?filter=parish_event&q=<?php echo urlencode($search); ?>">Events</a>
                    <a class="<?php echo $filter === 'mass_schedule' ? 'active' : ''; ?>" href="?filter=mass_schedule&q=<?php echo urlencode($search); ?>">Schedules</a>
                    <a class="<?php echo $filter === 'sacramental_activity' ? 'active' : ''; ?>" href="?filter=sacramental_activity&q=<?php echo urlencode($search); ?>">Sacraments</a>
                    <a class="<?php echo $filter === 'important_notice' ? 'active' : ''; ?>" href="?filter=important_notice&q=<?php echo urlencode($search); ?>">Important Notices</a>
                    <a class="<?php echo $filter === 'archived' ? 'active' : ''; ?>" href="?filter=archived&q=<?php echo urlencode($search); ?>">Archived</a>
                </div>
            </form>

            <?php if (!empty($announcements)): ?>
                <section class="announcement-grid">
                    <?php foreach ($announcements as $announcement): ?>
                        <?php
                            $meta = announcementTypeMeta($announcement['type'], $announcement_meta);
                            $is_archived = !empty($announcement['deleted_at']);
                            $is_scheduled = !$is_archived && $announcement['status'] === 'inactive' && !empty($announcement['scheduled_at']) && strtotime($announcement['scheduled_at']) > time();
                            $status_label = $is_archived ? 'Archived' : ($is_scheduled ? 'Scheduled' : 'Active');
                            $status_class = $is_archived ? 'archived' : ($is_scheduled ? 'scheduled' : 'active');
                        ?>
                        <article class="announcement-card">
                            <div class="card-media">
                                <?php if (!empty($announcement['attachment_path']) && isAnnouncementImageAttachment($announcement['attachment_mime_type'] ?? '')): ?>
                                    <img src="../announcement-attachment.php?id=<?php echo intval($announcement['announcement_id']); ?>" alt="<?php echo e($announcement['attachment_original_name'] ?: 'Announcement image'); ?>">
                                <?php else: ?>
                                    <i class="fas <?php echo e($meta['icon']); ?>"></i>
                                <?php endif; ?>
                            </div>
                            <div class="card-main">
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="category-badge <?php echo e($meta['tone']); ?>"><i class="fas <?php echo e($meta['icon']); ?>"></i> <?php echo e($meta['label']); ?></span>
                                    <span class="status-pill <?php echo e($status_class); ?>"><?php echo e($status_label); ?></span>
                                    <?php if (intval($announcement['is_pinned'] ?? 0) === 1): ?><span class="status-pill scheduled"><i class="fas fa-thumbtack"></i> Pinned</span><?php endif; ?>
                                </div>
                                <h3><?php echo e($announcement['title']); ?></h3>
                                <p><?php echo e(announcementPreviewText($announcement['content'])); ?></p>
                                <div class="announcement-meta">
                                    <span><i class="fas fa-user"></i> <?php echo e($announcement['fullname']); ?></span>
                                    <span><i class="fas fa-calendar"></i> <?php echo e(formatDate($announcement['published_date'])); ?></span>
                                    <?php if ($announcement['updated_at'] && $announcement['updated_at'] !== $announcement['created_at']): ?>
                                        <span><i class="fas fa-pen"></i> Updated <?php echo e(formatDate($announcement['updated_at'])); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($announcement['scheduled_at'])): ?>
                                        <span><i class="fas fa-clock"></i> <?php echo e(formatDateTime($announcement['scheduled_at'])); ?></span>
                                    <?php endif; ?>
                                    <?php if (intval($announcement['sent_emails'] ?? 0) > 0): ?>
                                        <span><i class="fas fa-envelope"></i> <?php echo intval($announcement['sent_emails']); ?> emails</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="action-row">
                                <?php if (!$is_archived): ?>
                                    <button class="btn btn-outline-primary icon-btn" data-bs-toggle="modal" data-bs-target="#editAnnouncement-<?php echo intval($announcement['announcement_id']); ?>" title="Edit"><i class="fas fa-pen"></i></button>
                                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="toggle_pin"><input type="hidden" name="announcement_id" value="<?php echo intval($announcement['announcement_id']); ?>"><button class="btn btn-outline-warning icon-btn" title="Pin announcement"><i class="fas fa-thumbtack"></i></button></form>
                                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="duplicate_announcement"><input type="hidden" name="announcement_id" value="<?php echo intval($announcement['announcement_id']); ?>"><button class="btn btn-outline-secondary icon-btn" title="Duplicate"><i class="fas fa-copy"></i></button></form>
                                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="send_notification"><input type="hidden" name="announcement_id" value="<?php echo intval($announcement['announcement_id']); ?>"><button class="btn btn-outline-success icon-btn" title="Send notification"><i class="fas fa-paper-plane"></i></button></form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Archive this announcement?');"><input type="hidden" name="action" value="archive_announcement"><input type="hidden" name="announcement_id" value="<?php echo intval($announcement['announcement_id']); ?>"><button class="btn btn-outline-dark icon-btn" title="Archive"><i class="fas fa-archive"></i></button></form>
                                <?php endif; ?>
                                <button class="btn btn-outline-danger icon-btn" data-bs-toggle="modal" data-bs-target="#deleteAnnouncement-<?php echo intval($announcement['announcement_id']); ?>" title="Delete"><i class="fas fa-trash"></i></button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php else: ?>
                <div class="empty-panel">
                    <i class="fas fa-bullhorn fa-2x mb-3"></i>
                    <h5>No announcements found.</h5>
                    <p class="mb-0">Try a different search or create a new announcement for parishioners.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$blank_announcement = [
    'announcement_id' => '',
    'title' => '',
    'content' => '',
    'type' => 'announcement',
    'event_date' => '',
    'scheduled_at' => '',
    'status' => 'active',
    'is_pinned' => 0
];
$modal_announcements = array_merge([$blank_announcement], $announcements);
?>

<?php foreach ($modal_announcements as $modal_item): ?>
    <?php
        $is_edit = !empty($modal_item['announcement_id']);
        $modal_id = $is_edit ? 'editAnnouncement-' . intval($modal_item['announcement_id']) : 'announcementModal';
        $content_id = $is_edit ? 'editor-' . intval($modal_item['announcement_id']) : 'editor-new';
        $input_id = $is_edit ? 'content-' . intval($modal_item['announcement_id']) : 'content-new';
        $scheduled_local = !empty($modal_item['scheduled_at']) ? date('Y-m-d\TH:i', strtotime($modal_item['scheduled_at'])) : '';
        $is_later = $scheduled_local !== '' && $modal_item['status'] === 'inactive';
    ?>
    <div class="modal fade" id="<?php echo e($modal_id); ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data" class="announcement-form">
                    <div class="modal-header">
                        <div>
                            <span class="gold-kicker mb-1"><i class="fas fa-bullhorn"></i> Announcement Editor</span>
                            <h5 class="modal-title"><?php echo $is_edit ? 'Edit Announcement' : 'Create New Announcement'; ?></h5>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="<?php echo $is_edit ? 'edit_announcement' : 'add_announcement'; ?>">
                        <?php if ($is_edit): ?><input type="hidden" name="announcement_id" value="<?php echo intval($modal_item['announcement_id']); ?>"><?php endif; ?>
                        <input type="hidden" name="content" id="<?php echo e($input_id); ?>" value="<?php echo e($modal_item['content']); ?>">

                        <div class="row g-3">
                            <div class="col-lg-8">
                                <label class="form-label">Title</label>
                                <input class="form-control control-lg" type="text" name="title" value="<?php echo e($modal_item['title']); ?>" required>
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label">Category</label>
                                <select class="form-select control-lg" name="type" required>
                                    <?php foreach ($announcement_types as $value => $label): ?>
                                        <option value="<?php echo e($value); ?>" <?php echo $modal_item['type'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Announcement Content</label>
                                <div class="editor-toolbar" role="toolbar">
                                    <button type="button" data-command="bold" title="Bold"><i class="fas fa-bold"></i></button>
                                    <button type="button" data-command="italic" title="Italic"><i class="fas fa-italic"></i></button>
                                    <button type="button" data-command="underline" title="Underline"><i class="fas fa-underline"></i></button>
                                    <button type="button" data-command="insertUnorderedList" title="Bullet list"><i class="fas fa-list-ul"></i></button>
                                    <button type="button" data-command="insertOrderedList" title="Numbered list"><i class="fas fa-list-ol"></i></button>
                                    <button type="button" data-command="createLink" title="Link"><i class="fas fa-link"></i></button>
                                </div>
                                <div class="rich-editor" id="<?php echo e($content_id); ?>" contenteditable="true" data-target="<?php echo e($input_id); ?>"><?php echo cleanAnnouncementContent($modal_item['content']); ?></div>
                                <div class="form-text">Use short paragraphs for readability. The public page will show a preview with a Read More option.</div>
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label">Attachment Upload</label>
                                <input class="form-control control-lg" type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain">
                                <div class="form-text">PDFs, images, event flyers, Office docs, or text files. Max 10MB.</div>
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label">Event Date</label>
                                <input class="form-control control-lg" type="date" name="event_date" value="<?php echo e($modal_item['event_date']); ?>">
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label">Schedule Publication</label>
                                <select class="form-select control-lg publish-mode" name="publish_mode">
                                    <option value="now" <?php echo !$is_later ? 'selected' : ''; ?>>Publish Now</option>
                                    <option value="later" <?php echo $is_later ? 'selected' : ''; ?>>Schedule for Later</option>
                                </select>
                            </div>
                            <div class="col-lg-4 schedule-field" style="<?php echo $is_later ? '' : 'display:none;'; ?>">
                                <label class="form-label">Publish Date and Time</label>
                                <input class="form-control control-lg scheduled-at" type="datetime-local" name="scheduled_at" value="<?php echo e($scheduled_local); ?>">
                            </div>
                            <div class="col-lg-8">
                                <label class="form-label">Notification Settings</label>
                                <div class="d-flex flex-wrap gap-3 pt-2">
                                    <label><input type="checkbox" name="notify_all" checked> Notify All Parishioners</label>
                                    <label><input type="checkbox" name="notify_email" checked> Send Email Notification</label>
                                    <label><input type="checkbox" name="notify_system" checked> Send In-System Notification</label>
                                    <label><input type="checkbox" name="is_pinned" <?php echo intval($modal_item['is_pinned']) === 1 ? 'checked' : ''; ?>> Pin Announcement</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> <?php echo $is_edit ? 'Save Changes' : 'Publish Announcement'; ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php foreach ($announcements as $announcement): ?>
    <div class="modal fade" id="deleteAnnouncement-<?php echo intval($announcement['announcement_id']); ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Announcement</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-1">Delete <strong><?php echo e($announcement['title']); ?></strong> permanently?</p>
                        <p class="text-muted mb-0">Archiving is recommended when you want to keep a record.</p>
                        <input type="hidden" name="action" value="delete_announcement">
                        <input type="hidden" name="announcement_id" value="<?php echo intval($announcement['announcement_id']); ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.rich-editor').forEach(function(editor) {
        const target = document.getElementById(editor.dataset.target);
        const form = editor.closest('form');
        // Sync Editor Function - Documents this helper's role in the parish management workflow.
        function syncEditor() {
            if (target) {
                target.value = editor.innerHTML.trim();
            }
        }
        editor.addEventListener('input', syncEditor);
        if (form) {
            form.addEventListener('submit', syncEditor);
        }
    });

    document.querySelectorAll('.editor-toolbar button').forEach(function(button) {
        button.addEventListener('click', function() {
            const toolbar = button.closest('.editor-toolbar');
            const editor = toolbar ? toolbar.nextElementSibling : null;
            if (!editor) {
                return;
            }
            editor.focus();
            const command = button.dataset.command;
            if (command === 'createLink') {
                const url = window.prompt('Enter link URL');
                if (url) {
                    document.execCommand(command, false, url);
                }
            } else {
                document.execCommand(command, false, null);
            }
            editor.dispatchEvent(new Event('input'));
        });
    });

    document.querySelectorAll('.publish-mode').forEach(function(select) {
        // Toggle Schedule Function - Documents this helper's role in the parish management workflow.
        function toggleSchedule() {
            const wrapper = select.closest('.modal-body');
            const field = wrapper ? wrapper.querySelector('.schedule-field') : null;
            const input = wrapper ? wrapper.querySelector('.scheduled-at') : null;
            const isLater = select.value === 'later';
            if (field) {
                field.style.display = isLater ? '' : 'none';
            }
            if (input) {
                input.required = isLater;
                if (!isLater) {
                    input.value = '';
                }
            }
        }
        select.addEventListener('change', toggleSchedule);
        toggleSchedule();
    });
});
</script>

<?php include '../templates/footer.php'; ?>
