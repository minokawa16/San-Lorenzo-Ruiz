<?php
/**
 * Manage Announcements Page
 * Admin interface for managing parish announcements
 */

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
require_once '../services/AnnouncementService.php';

requireAdmin();
requirePermission('announcements.manage');

$error = '';
$success = '';
$queued_announcement_id = 0;

ensureExpandedAnnouncementTypeSchema($conn);
ensureAnnouncementAttachmentSchema($conn);
ensureEmailNotificationSchema($conn);
ensureAnnouncementDeliveryQueueSchema($conn);

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
    return requireSchemaColumns($conn, 'announcements', [
        'deleted_at', 'event_date', 'is_pinned', 'scheduled_at'
    ], 'announcement management');
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

function recordAnnouncementAttachment($conn, $announcement_id, array $attachment, $actor_id) {
    if (empty($attachment['path'])) return;
    $absolute = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $attachment['path']);
    $hash = is_file($absolute) ? hash_file('sha256', $absolute) : str_repeat('0', 64);
    $stmt = $conn->prepare('INSERT INTO announcement_attachments(announcement_id,stored_path,original_name,mime_type,file_size,file_hash,uploaded_by) VALUES(?,?,?,?,?,?,?)');
    $stmt->bind_param('isssisi', $announcement_id, $attachment['path'], $attachment['original_name'], $attachment['mime_type'], $attachment['size'], $hash, $actor_id);
    $stmt->execute(); $stmt->close();
}

function announcementRelativeTime($value) {
    $timestamp = strtotime((string) $value);
    if (!$timestamp) {
        return 'Date unavailable';
    }
    $difference = time() - $timestamp;
    if ($difference < 0 || $difference >= 604800) {
        return date('M j, Y', $timestamp);
    }
    if ($difference < 60) {
        return 'Just now';
    }
    if ($difference < 3600) {
        $minutes = max(1, (int) floor($difference / 60));
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
    }
    if ($difference < 86400) {
        $hours = max(1, (int) floor($difference / 3600));
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }
    $days = max(1, (int) floor($difference / 86400));
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

function ensureAnnouncementDeliveryQueueSchema($conn) {
    return requireSchemaColumns($conn, 'announcement_recipients', [
        'sms_delivery_status', 'sms_sent_at', 'last_error'
    ], 'announcement delivery queue');
}

// Announcement Notification Queue - Creates in-app alerts now and queues email/SMS delivery for fast posting.
function queueAnnouncementNotifications($conn, $announcement_id, $title, $send_email = true, $send_system = true, $send_sms = true) {
    $announcement_id = intval($announcement_id);
    $recipients = $conn->query("SELECT u.id, u.email, u.phone_number, u.fullname, COALESCE(np.email_enabled, 1) AS email_enabled, COALESCE(np.sms_enabled, 1) AS sms_enabled, COALESCE(np.in_app_enabled, 1) AS in_app_enabled
        FROM users u
        LEFT JOIN notification_preferences np ON np.user_id = u.id AND np.category = 'announcements'
        WHERE u.role = 'user' AND u.status = 'active'");
    $email_count = 0;
    $sms_count = 0;
    $system_count = 0;

    while ($recipients && $recipient = $recipients->fetch_assoc()) {
        $email_status = 'skipped';
        $sms_status = 'skipped';
        if ($send_system && intval($recipient['in_app_enabled']) === 1) {
            if (createNotification($conn, intval($recipient['id']), 'New Parish Announcement', $title, false, 'announcements')) {
                $system_count++;
            }
        }
        if ($send_email && intval($recipient['email_enabled']) === 1 && isValidEmail($recipient['email'] ?? '')) {
            $email_status = 'pending';
            $email_count++;
        }
        if ($send_sms && intval($recipient['sms_enabled']) === 1 && isValidPhilippineMobile($recipient['phone_number'] ?? '')) {
            $sms_status = 'pending';
            $sms_count++;
        }

        $log_stmt = $conn->prepare("INSERT INTO announcement_recipients (announcement_id, user_id, email, delivery_status, sms_delivery_status, sent_at, sms_sent_at, last_error)
            VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL)
            ON DUPLICATE KEY UPDATE delivery_status = VALUES(delivery_status), sms_delivery_status = VALUES(sms_delivery_status), sent_at = NULL, sms_sent_at = NULL, last_error = NULL");
        if ($log_stmt) {
            $uid = intval($recipient['id']);
            $log_stmt->bind_param('iisss', $announcement_id, $uid, $recipient['email'], $email_status, $sms_status);
            $log_stmt->execute();
            $log_stmt->close();
        }
    }

    return ['queued_email' => $email_count, 'queued_sms' => $sms_count, 'system' => $system_count];
}

function processAnnouncementDeliveryQueue($conn, $announcement_id = 0, $limit = 5) {
    $announcement_id = intval($announcement_id);
    $limit = max(1, min(20, intval($limit)));
    $where = $announcement_id > 0 ? "AND ar.announcement_id = $announcement_id" : '';
    $rows = $conn->query("SELECT ar.recipient_id, ar.announcement_id, ar.delivery_status, ar.sms_delivery_status, u.id AS user_id, u.email, u.phone_number, a.title, a.content
        FROM announcement_recipients ar
        JOIN users u ON u.id = ar.user_id
        JOIN announcements a ON a.announcement_id = ar.announcement_id
        WHERE (ar.delivery_status = 'pending' OR ar.sms_delivery_status = 'pending') $where
        ORDER BY ar.created_at ASC
        LIMIT $limit");

    $processed = 0;
    $sent_email = 0;
    $sent_sms = 0;
    $failed = 0;
    $view_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'users/announcements.php';

    while ($rows && $row = $rows->fetch_assoc()) {
        $processed++;
        $errors = [];
        if ($row['delivery_status'] === 'pending') {
            $email_body = '<p><strong>' . e($row['title']) . '</strong></p><p>' . nl2br(e(strip_tags($row['content']))) . '</p><p>Published: ' . e(formatDateTime(date('Y-m-d H:i:s'))) . '</p>';
            $sent = sendTugonEmail($conn, $row['email'], 'New Parish Announcement: ' . $row['title'], tugonEmailTemplate('Parish Announcement', $email_body, 'View Announcement', $view_url), '', $row['user_id'], 'announcement');
            $email_status = $sent['ok'] ? 'sent' : 'failed';
            $sent['ok'] ? $sent_email++ : $failed++;
            if (!$sent['ok']) {
                $errors[] = $sent['error'] ?? 'Email failed.';
            }
            $stmt = $conn->prepare("UPDATE announcement_recipients SET delivery_status = ?, sent_at = ?, last_error = ? WHERE recipient_id = ?");
            if ($stmt) {
                $sent_at = $sent['ok'] ? date('Y-m-d H:i:s') : null;
                $error_text = implode(' ', $errors);
                $rid = intval($row['recipient_id']);
                $stmt->bind_param('sssi', $email_status, $sent_at, $error_text, $rid);
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($row['sms_delivery_status'] === 'pending') {
            $sms = sendTugonSms($conn, $row['phone_number'], notificationSmsMessage('New Parish Announcement', $row['title']), $row['user_id'], 'announcement');
            $sms_status = $sms['ok'] ? 'sent' : 'failed';
            $sms['ok'] ? $sent_sms++ : $failed++;
            if (!$sms['ok']) {
                $errors[] = $sms['error'] ?? 'SMS failed.';
            }
            $stmt = $conn->prepare("UPDATE announcement_recipients SET sms_delivery_status = ?, sms_sent_at = ?, last_error = ? WHERE recipient_id = ?");
            if ($stmt) {
                $sent_at = $sms['ok'] ? date('Y-m-d H:i:s') : null;
                $error_text = implode(' ', $errors);
                $rid = intval($row['recipient_id']);
                $stmt->bind_param('sssi', $sms_status, $sent_at, $error_text, $rid);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    $remaining_result = $conn->query("SELECT COUNT(*) AS count FROM announcement_recipients WHERE (delivery_status = 'pending' OR sms_delivery_status = 'pending')" . ($announcement_id > 0 ? " AND announcement_id = $announcement_id" : ''));
    $remaining = $remaining_result ? intval($remaining_result->fetch_assoc()['count'] ?? 0) : 0;
    return ['processed' => $processed, 'sent_email' => $sent_email, 'sent_sms' => $sent_sms, 'failed' => $failed, 'remaining' => $remaining];
}

// Publish Due Scheduled Announcements Function - Documents this helper's role in the parish management workflow.
function publishDueScheduledAnnouncements($conn) {
    (new AnnouncementService($conn))->tick((int)($_SESSION['user_id']??0));
}

ensureAnnouncementManagementSchema($conn);
publishDueScheduledAnnouncements($conn);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';
    $announcement_id = intval($_POST['announcement_id'] ?? 0);
    $announcement_service = new AnnouncementService($conn);

    if ($action === 'process_announcement_queue') {
        header('Content-Type: application/json');
        echo json_encode(processAnnouncementDeliveryQueue($conn, $announcement_id, 5));
        exit;
    } elseif ($action === 'archive_announcement' && $announcement_id > 0) {
        try{$announcement_service->archive($announcement_id,(string)($_POST['archive_reason']??'Archived through announcement management.'),(int)$_SESSION['user_id']);$success='Announcement archived successfully.';}catch(Throwable $e){$error=$e->getMessage();}
    } elseif ($action === 'delete_announcement' && $announcement_id > 0) {
        try{$announcement_service->archive($announcement_id,(string)($_POST['archive_reason']??'Archived instead of permanent deletion.'),(int)$_SESSION['user_id']);$success='Announcement archived; its history and attachments were preserved.';}catch(Throwable $e){$error=$e->getMessage();}
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
        $announcement_service->notifyNow($announcement_id,(int)$_SESSION['user_id']);
        $success = 'Audience-aware notifications processed. Existing deliveries were not duplicated.';
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
        $status = $publish_mode === 'now' ? 'active' : 'inactive';
        $expires_value = !empty($_POST['expires_at']) ? str_replace('T',' ',trim($_POST['expires_at'])).':00' : null;
        $audience_type = (string)($_POST['audience_type']??'everyone');
        $audience_values = array_values(array_filter(array_map('trim',explode(',',(string)($_POST['audience_values']??'')))));
        $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
        $notify_all = isset($_POST['notify_all']);
        $notify_email = isset($_POST['notify_email']);
        $notify_sms = isset($_POST['notify_sms']);
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
                        recordAnnouncementAttachment($conn,$new_id,$attachment,(int)$_SESSION['user_id']);
                        $announcement_service->configure($new_id,$publish_mode,$scheduled_value,$expires_value,$audience_type,$audience_values,(int)$_SESSION['user_id']);
                        createAuditLog($conn, $_SESSION['user_id'], 'ADD_ANNOUNCEMENT', 'announcements', $new_id);
                        $success = $publish_mode === 'now' ? 'Announcement published successfully.' : ($publish_mode==='draft'?'Announcement saved as draft.':'Announcement scheduled successfully.');
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
                        recordAnnouncementAttachment($conn,$announcement_id,$attachment,(int)$_SESSION['user_id']);
                        $announcement_service->configure($announcement_id,$publish_mode,$scheduled_value,$expires_value,$audience_type,$audience_values,(int)$_SESSION['user_id']);
                        createAuditLog($conn, $_SESSION['user_id'], 'EDIT_ANNOUNCEMENT', 'announcements', $announcement_id);
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
        (SELECT COUNT(*) FROM announcement_recipients ar WHERE ar.announcement_id = a.announcement_id AND ar.delivery_status = 'sent') AS sent_emails,
        (SELECT COUNT(*) FROM announcement_recipients ar WHERE ar.announcement_id = a.announcement_id AND ar.sms_delivery_status = 'sent') AS sent_sms,
        (SELECT COUNT(*) FROM announcement_recipients ar WHERE ar.announcement_id = a.announcement_id AND (ar.delivery_status = 'pending' OR ar.sms_delivery_status = 'pending')) AS pending_deliveries
        FROM announcements a
        LEFT JOIN users u ON a.published_by = u.id
        $where
        ORDER BY COALESCE(a.published_date, a.created_at) DESC, a.announcement_id DESC
        LIMIT {$pagination['offset']}, {$pagination['limit']}";
$result = $conn->query($sql);
while ($result && $row = $result->fetch_assoc()) {
    $announcements[] = $row;
}
$pending_delivery_result = $conn->query("SELECT COUNT(*) AS count FROM announcement_recipients WHERE delivery_status = 'pending' OR sms_delivery_status = 'pending'");
$pending_announcement_delivery_count = $pending_delivery_result ? intval($pending_delivery_result->fetch_assoc()['count'] ?? 0) : 0;

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
    .announcement-admin-page { max-width: 1480px; margin: 0 auto; color: #2c2c2c; font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; }
    .announcement-shell { background: #f8f6f1; border: 1px solid #e6e0d4; border-radius: 12px; padding: 20px; }
    .announcement-hero { margin-bottom: 16px; }
    .announcement-hero h1 { margin: 0 0 4px; font-size: 28px; font-weight: 700; letter-spacing: -.02em; }
    .announcement-hero p { margin: 0; color: #6f6f6f; font-size: 13px; }
    .gold-kicker { display: inline-flex; align-items: center; gap: 6px; color: #80611b; font-size: 12px; font-weight: 600; }
    .category-badge, .status-pill { display: inline-flex; align-items: center; gap: 6px; min-height: 27px; padding: 5px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; }
    .filter-panel, .stat-card, .announcement-card, .empty-panel { background: #fff; border: 1px solid #e6e0d4; border-radius: 10px; box-shadow: 0 5px 18px rgba(46, 58, 45, .055); }
    .filter-panel { padding: 12px; margin-bottom: 14px; }
    .input-with-icon { position: relative; }
    .input-with-icon i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
    .input-with-icon .form-control { padding-left: 42px; }
    .control-lg { min-height: 44px; border-radius: 8px; border-color: #e6e0d4; }
    .filter-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .filter-tabs a { min-height: 33px; display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 999px; border: 1px solid #e6e0d4; color: #2e3a2d; background: #fff; text-decoration: none; font-size: 12px; font-weight: 600; }
    .filter-tabs a.active, .filter-tabs a:hover { background: #f7f0df; border-color: #c89b3c; color: #2e3a2d; }
    .stat-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 18px; }
    .stat-card { min-height: 82px; padding: 13px 14px; display: flex; gap: 11px; align-items: center; border-top: 3px solid #c89b3c; }
    .stat-icon { width: 38px; height: 38px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; color: #2e3a2d; background: #f5efe1; font-size: 14px; }
    .stat-card strong { display: block; font-size: 24px; line-height: 1; }
    .stat-card span { color: #6f6f6f; font-size: 12px; font-weight: 500; }
    .announcement-section-heading { display: flex; justify-content: space-between; align-items: end; gap: 12px; margin: 4px 2px 12px; color: #6f6f6f; font-size: 12px; }
    .announcement-section-heading div { display: grid; gap: 2px; }
    .announcement-section-heading div > span { color: #2c2c2c; font-size: 17px; font-weight: 700; }
    .announcement-section-heading small { color: #6f6f6f; }
    .announcement-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 18px; max-width: 1200px; }
    .announcement-card { --card-accent: #2e3a2d; display: grid; grid-template-columns: 132px minmax(0, 1fr); width: 100%; min-height: 220px; overflow: visible; border-radius: 18px; transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease; }
    .announcement-card:hover { transform: translateY(-2px); border-color: #d7c9ad; box-shadow: 0 10px 26px rgba(46, 58, 45, .09); }
    .announcement-card:focus-within { outline: 3px solid rgba(200, 155, 60, .22); outline-offset: 2px; }
    .announcement-card.tone-event { --card-accent: #2e8b57; }
    .announcement-card.tone-general { --card-accent: #356b91; }
    .announcement-card.tone-schedule { --card-accent: #7654b8; }
    .announcement-card.tone-sacrament { --card-accent: #c89b3c; }
    .announcement-card.tone-important { --card-accent: #c62828; }
    .announcement-card.is-archived { --card-accent: #777; }
    .announcement-card.is-pinned { background: #fffdf7; }
    .announcement-icon-panel { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 24px 16px; color: var(--card-accent); background: #f5f3ee; border-radius: 17px 0 0 17px; }
    .tone-event .announcement-icon-panel { background: #edf7f1; }
    .tone-general .announcement-icon-panel { background: #edf4f8; }
    .tone-schedule .announcement-icon-panel { background: #f3effa; }
    .tone-sacrament .announcement-icon-panel { background: #faf4e5; }
    .tone-important .announcement-icon-panel { background: #fbeeee; }
    .is-archived .announcement-icon-panel { background: #f1f1f1; }
    .announcement-icon-panel i { font-size: 32px; }
    .announcement-icon-panel .status-pill { background: rgba(255,255,255,.82); color: var(--card-accent); }
    .card-main { min-width: 0; padding: 28px 30px; display: grid; align-content: start; gap: 13px; }
    .announcement-card-top { display: flex; justify-content: space-between; align-items: center; gap: 16px; }
    .announcement-card-top > div:first-child { display: flex; flex-wrap: wrap; gap: 8px; }
    .announcement-card-tools { display: flex; align-items: center; gap: 10px; color: #6f6f6f; font-size: 12px; white-space: nowrap; }
    .announcement-menu-btn { width: 44px; height: 44px; border: 1px solid #e6e0d4; border-radius: 10px; color: #2e3a2d; background: #fff; }
    .announcement-menu-btn:hover, .announcement-menu-btn:focus { color: #2e3a2d; border-color: #c89b3c; background: #f9f4e8; }
    .announcement-card .dropdown-menu { min-width: 190px; padding: 7px; border-color: #e6e0d4; border-radius: 10px; box-shadow: 0 12px 30px rgba(44,44,44,.12); }
    .announcement-card .dropdown-item { min-height: 40px; display: flex; align-items: center; gap: 10px; border-radius: 7px; font-size: 13px; }
    .announcement-card .dropdown-item.text-danger { color: #a93232 !important; }
    .card-main h3 { margin: 0; color: #2c2c2c; font-size: clamp(21px, 2vw, 25px); font-weight: 700; line-height: 1.25; }
    .card-main p { color: #6f6f6f; font-size: 15px; line-height: 1.65; margin: 0; }
    .announcement-preview { display: -webkit-box; overflow: hidden; -webkit-box-orient: vertical; -webkit-line-clamp: 3; }
    .announcement-meta { display: flex; flex-wrap: wrap; gap: 10px 18px; padding-top: 2px; color: #6f6f6f; font-size: 12px; font-weight: 500; }
    .announcement-meta span { display: inline-flex; gap: 6px; align-items: center; }
    .category-badge { border: 1px solid transparent; }
    .category-badge.general { background: #edf4f8; color: #356b91; }
    .category-badge.event { background: #edf7f1; color: #267749; }
    .category-badge.schedule { background: #f3effa; color: #65469f; }
    .category-badge.sacrament { background: #faf4e5; color: #866317; }
    .category-badge.important { background: #fff1f2; color: #9f1239; }
    .status-pill.active { color: #166534; background: #dcfce7; }
    .status-pill.scheduled { color: #80611b; background: #f7f0df; }
    .status-pill.archived { color: #475569; background: #f1f5f9; }
    .announcement-card-footer { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding-top: 4px; }
    .view-details-btn { min-height: 44px; padding: 8px 15px; border: 1px solid #c89b3c; border-radius: 9px; color: #78591b; background: #fff; font-weight: 600; }
    .view-details-btn:hover, .view-details-btn:focus { color: #2c2c2c; background: #f5e8c5; border-color: #b88b30; }
    .announcement-full-content { padding: 14px 0 2px; border-top: 1px solid #eee9df; color: #4f4f4f; font-size: 14px; line-height: 1.65; }
    .announcement-full-content img { display: block; max-width: 360px; max-height: 220px; margin: 0 0 14px; border-radius: 10px; object-fit: cover; }
    .feed-pagination { max-width: 1200px; margin-top: 20px; padding: 14px 16px; display: flex; justify-content: space-between; align-items: center; gap: 14px; color: #6f6f6f; font-size: 13px; }
    .feed-pagination .pagination { margin: 0; }
    .feed-pagination .page-link { min-width: 40px; min-height: 40px; display: grid; place-items: center; color: #2e3a2d; border-color: #e6e0d4; }
    .feed-pagination .active .page-link { color: #fff; background: #c89b3c; border-color: #c89b3c; }
    .empty-panel { padding: 44px 18px; text-align: center; color: #667085; }
    .editor-toolbar { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px; background: #f8fafc; border: 1px solid #dfe4ea; border-bottom: 0; border-radius: 8px 8px 0 0; }
    .editor-toolbar button { width: 34px; height: 34px; border: 1px solid #dfe4ea; background: #fff; border-radius: 7px; }
    .rich-editor { min-height: 190px; padding: 12px; border: 1px solid #dfe4ea; border-radius: 0 0 8px 8px; background: #fff; line-height: 1.6; outline: none; }
    .modal .form-label { font-weight: 800; color: #334155; }
    @media (max-width: 1180px) { .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .announcement-card { min-height: 200px; } }
    @media (max-width: 768px) { .announcement-shell { padding: 12px; } .stat-grid { grid-template-columns: 1fr; } .announcement-card { grid-template-columns: 1fr; min-height: 0; border-radius: 16px; } .announcement-icon-panel { min-height: 82px; flex-direction: row; justify-content: flex-start; padding: 16px 18px; border-radius: 15px 15px 0 0; } .announcement-icon-panel i { font-size: 24px; } .card-main { padding: 20px 18px; } .announcement-card-top { align-items: flex-start; } .announcement-card-tools > span { display: none; } .card-main h3 { font-size: 20px; } .card-main p { font-size: 14px; } .announcement-meta { display: grid; gap: 8px; } .announcement-card-footer { align-items: stretch; flex-direction: column; } .view-details-btn { width: 100%; } .announcement-full-content img { width: 100%; max-width: none; } .announcement-section-heading { align-items: start; } .feed-pagination { align-items: flex-start; flex-direction: column; } }
</style>

<div class="container-fluid mt-4">
    <?php include '../includes/breadcrumb.php'; ?>
    <?php include '../includes/back_button.php'; ?>

    <div class="announcement-admin-page">
        <div class="announcement-shell">
            <section class="announcement-hero">
                <div>
                    <h1>Announcements</h1>
                    <p>Publish parish notices and updates.</p>
                </div>
            </section>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show"><?php echo e($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show"><?php echo e($success); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <form method="GET" class="filter-panel">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-6">
                        <div class="input-with-icon">
                            <i class="fas fa-search"></i>
                            <input class="form-control control-lg" type="search" name="q" value="<?php echo e($search); ?>" placeholder="Search announcements..." aria-label="Search announcements">
                        </div>
                    </div>
                    <div class="col-lg-3">
                        <select class="form-select control-lg" name="filter">
                            <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Announcements</option>
                            <?php foreach ($announcement_types as $value => $label): ?>
                                <option value="<?php echo e($value); ?>" <?php echo $filter === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                            <option value="scheduled" <?php echo $filter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                            <option value="archived" <?php echo $filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                        </select>
                    </div>
                    <div class="col-lg-1 d-grid">
                        <button class="btn btn-primary control-lg" type="submit" title="Apply filter"><i class="fas fa-filter"></i><span class="visually-hidden">Apply filter</span></button>
                    </div>
                    <div class="col-lg-2 d-grid">
                        <button class="btn btn-primary control-lg" type="button" data-bs-toggle="modal" data-bs-target="#announcementModal"><i class="fas fa-plus"></i> New Announcement</button>
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

            <section class="stat-grid" aria-label="Announcement statistics">
                <div class="stat-card"><span class="stat-icon"><i class="fas fa-layer-group"></i></span><div><strong><?php echo $stats['total']; ?></strong><span>Total Announcements</span></div></div>
                <div class="stat-card"><span class="stat-icon"><i class="fas fa-check"></i></span><div><strong><?php echo $stats['active']; ?></strong><span>Active Announcements</span></div></div>
                <div class="stat-card"><span class="stat-icon"><i class="fas fa-clock"></i></span><div><strong><?php echo $stats['scheduled']; ?></strong><span>Scheduled Announcements</span></div></div>
                <div class="stat-card"><span class="stat-icon"><i class="fas fa-archive"></i></span><div><strong><?php echo $stats['archived']; ?></strong><span>Archived Announcements</span></div></div>
            </section>

            <div class="announcement-section-heading">
                <div><span>Recent Announcements</span><small>Newest announcements appear first</small></div>
                <span><?php echo count($announcements); ?> shown</span>
            </div>

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
                        <article class="announcement-card tone-<?php echo e($meta['tone']); ?> <?php echo intval($announcement['is_pinned'] ?? 0) === 1 ? 'is-pinned' : ''; ?> <?php echo $is_archived ? 'is-archived' : ''; ?>">
                            <div class="announcement-icon-panel">
                                <i class="fas <?php echo e($meta['icon']); ?>" aria-hidden="true"></i>
                                <span class="status-pill <?php echo e($status_class); ?>"><?php echo e($status_label); ?></span>
                            </div>
                            <div class="card-main">
                                <div class="announcement-card-top">
                                    <div>
                                        <span class="category-badge <?php echo e($meta['tone']); ?>"><?php echo e($meta['label']); ?></span>
                                        <?php if (intval($announcement['is_pinned'] ?? 0) === 1): ?><span class="status-pill scheduled"><i class="fas fa-thumbtack"></i> Pinned</span><?php endif; ?>
                                    </div>
                                    <div class="announcement-card-tools">
                                        <span><?php echo e(announcementRelativeTime($announcement['published_date'] ?? $announcement['created_at'])); ?></span>
                                        <div class="dropdown">
                                            <button class="announcement-menu-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for <?php echo e($announcement['title']); ?>"><i class="fas fa-ellipsis-vertical"></i></button>
                                            <div class="dropdown-menu dropdown-menu-end">
                                                <button class="dropdown-item announcement-details-toggle" type="button" data-target="announcement-details-<?php echo intval($announcement['announcement_id']); ?>"><i class="fas fa-eye"></i> View details</button>
                                                <?php if (!$is_archived): ?>
                                                    <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#editAnnouncement-<?php echo intval($announcement['announcement_id']); ?>"><i class="fas fa-pen"></i> Edit</button>
                                                    <form method="POST"><?php echo csrfInput(); ?><input type="hidden" name="action" value="toggle_pin"><input type="hidden" name="announcement_id" value="<?php echo intval($announcement['announcement_id']); ?>"><button class="dropdown-item" type="submit"><i class="fas fa-thumbtack"></i> <?php echo intval($announcement['is_pinned'] ?? 0) === 1 ? 'Unpin' : 'Pin'; ?></button></form>
                                                    <form method="POST"><?php echo csrfInput(); ?><input type="hidden" name="action" value="duplicate_announcement"><input type="hidden" name="announcement_id" value="<?php echo intval($announcement['announcement_id']); ?>"><button class="dropdown-item" type="submit"><i class="fas fa-copy"></i> Duplicate</button></form>
                                                    <form method="POST"><?php echo csrfInput(); ?><input type="hidden" name="action" value="send_notification"><input type="hidden" name="announcement_id" value="<?php echo intval($announcement['announcement_id']); ?>"><button class="dropdown-item" type="submit"><i class="fas fa-paper-plane"></i> Send notification</button></form>
                                                    <form method="POST" onsubmit="return confirm('Archive this announcement?');"><?php echo csrfInput(); ?><input type="hidden" name="action" value="archive_announcement"><input type="hidden" name="archive_reason" value="Archived through announcement management."><input type="hidden" name="announcement_id" value="<?php echo intval($announcement['announcement_id']); ?>"><button class="dropdown-item" type="submit"><i class="fas fa-archive"></i> Archive</button></form>
                                                <?php endif; ?>
                                                <button class="dropdown-item text-danger" type="button" data-bs-toggle="modal" data-bs-target="#deleteAnnouncement-<?php echo intval($announcement['announcement_id']); ?>"><i class="fas fa-trash"></i> Delete</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <h3><?php echo e($announcement['title']); ?></h3>
                                <p class="announcement-preview"><?php echo e(announcementPreviewText($announcement['content'], 320)); ?></p>
                                <div class="announcement-meta">
                                    <span><i class="fas fa-calendar"></i> <?php echo e(formatDate($announcement['published_date'])); ?></span>
                                    <span><i class="fas fa-user"></i> <?php echo e($announcement['fullname']); ?></span>
                                    <span><i class="fas fa-clock"></i> <?php echo e(date('g:i A', strtotime($announcement['published_date']))); ?></span>
                                    <?php if (!empty($announcement['event_date'])): ?><span><i class="fas fa-calendar-day"></i> Event: <?php echo e(formatDate($announcement['event_date'])); ?></span><?php endif; ?>
                                </div>
                                <div class="announcement-full-content" id="announcement-details-<?php echo intval($announcement['announcement_id']); ?>" hidden>
                                    <?php if (!empty($announcement['attachment_path']) && isAnnouncementImageAttachment($announcement['attachment_mime_type'] ?? '')): ?><img src="../announcement-attachment.php?id=<?php echo intval($announcement['announcement_id']); ?>" alt="<?php echo e($announcement['attachment_original_name'] ?: 'Announcement image'); ?>"><?php endif; ?>
                                    <?php echo nl2br(e(strip_tags((string) $announcement['content']))); ?>
                                </div>
                                <div class="announcement-card-footer">
                                    <span class="announcement-meta">
                                        <?php if (intval($announcement['sent_emails'] ?? 0) > 0): ?><span><i class="fas fa-envelope"></i> <?php echo intval($announcement['sent_emails']); ?> emails</span><?php endif; ?>
                                        <?php if (intval($announcement['sent_sms'] ?? 0) > 0): ?><span><i class="fas fa-mobile-screen-button"></i> <?php echo intval($announcement['sent_sms']); ?> SMS</span><?php endif; ?>
                                    </span>
                                    <button class="view-details-btn announcement-details-toggle" type="button" data-target="announcement-details-<?php echo intval($announcement['announcement_id']); ?>" aria-expanded="false">View Details <i class="fas fa-arrow-right"></i></button>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            <?php else: ?>
                <div class="empty-panel">
                    <i class="fas fa-bullhorn fa-2x mb-3"></i>
                    <h5>No announcements yet</h5>
                    <p class="mb-3">Create your first parish announcement to keep parishioners informed.</p>
                    <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#announcementModal"><i class="fas fa-plus"></i> Create Announcement</button>
                </div>
            <?php endif; ?>

            <?php if ($total > 0): ?>
                <?php
                    $showing_from = $pagination['offset'] + 1;
                    $showing_to = min($total, $pagination['offset'] + count($announcements));
                    $page_params = ['q' => $search, 'filter' => $filter];
                ?>
                <nav class="feed-pagination" aria-label="Announcement pagination">
                    <span>Showing <?php echo $showing_from; ?>–<?php echo $showing_to; ?> of <?php echo $total; ?> announcements</span>
                    <?php if ($pagination['total_pages'] > 1): ?>
                        <ul class="pagination pagination-sm">
                            <?php for ($page_number = 1; $page_number <= $pagination['total_pages']; $page_number++): ?>
                                <?php $page_url = '?' . http_build_query(array_merge($page_params, ['page' => $page_number])); ?>
                                <li class="page-item <?php echo $page_number === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo e($page_url); ?>" <?php echo $page_number === $page ? 'aria-current="page"' : ''; ?>><?php echo $page_number; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    <?php endif; ?>
                </nav>
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
                    <?php echo csrfInput(); ?>
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
                                    <option value="draft">Save as Draft</option>
                                </select>
                            </div>
                            <div class="col-lg-4 schedule-field" style="<?php echo $is_later ? '' : 'display:none;'; ?>">
                                <label class="form-label">Publish Date and Time</label>
                                <input class="form-control control-lg scheduled-at" type="datetime-local" name="scheduled_at" value="<?php echo e($scheduled_local); ?>">
                            </div>
                            <div class="col-lg-4"><label class="form-label">Expiration (optional)</label><input class="form-control control-lg" type="datetime-local" name="expires_at" value="<?php echo !empty($modal_item['expires_at'])?e(date('Y-m-d\TH:i',strtotime($modal_item['expires_at']))):''; ?>"></div>
                            <div class="col-lg-4"><label class="form-label">Audience</label><select class="form-select control-lg" name="audience_type"><option value="everyone">Everyone</option><option value="district">District</option><option value="chapel">Chapel</option><option value="selected_users">Selected users</option></select></div>
                            <div class="col-lg-4"><label class="form-label">Audience values</label><input class="form-control control-lg" name="audience_values" placeholder="Comma-separated chapel/district names or user IDs"><div class="form-text">Leave blank only for Everyone.</div></div>
                            <div class="col-lg-8">
                                <label class="form-label">Notification Settings</label>
                                <div class="d-flex flex-wrap gap-3 pt-2">
                                    <label><input type="checkbox" name="notify_all" checked> Notify All Parishioners</label>
                                    <label><input type="checkbox" name="notify_email" checked> Send Email Notification</label>
                                    <label><input type="checkbox" name="notify_sms" checked> Send SMS Notification</label>
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
                    <?php echo csrfInput(); ?>
                    <div class="modal-header">
                        <h5 class="modal-title">Delete Announcement</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-1">Archive <strong><?php echo e($announcement['title']); ?></strong>?</p>
                        <p class="text-muted mb-0">The announcement and its history will be preserved.</p>
                        <input type="hidden" name="action" value="delete_announcement">
                        <input type="hidden" name="announcement_id" value="<?php echo intval($announcement['announcement_id']); ?>">
                        <label class="form-label mt-3">Archive reason</label><textarea class="form-control" name="archive_reason" required minlength="5"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-archive"></i> Archive</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const queuedAnnouncementId = <?php echo intval($queued_announcement_id); ?>;
    const pendingDeliveries = <?php echo intval($pending_announcement_delivery_count); ?>;

    function processAnnouncementQueue(announcementId) {
        const body = new URLSearchParams();
        body.set('action', 'process_announcement_queue');
        body.set('<?php echo e(csrfTokenName()); ?>', '<?php echo e(generateCsrfToken()); ?>');
        if (announcementId > 0) {
            body.set('announcement_id', String(announcementId));
        }

        fetch('manage-announcements.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        })
            .then(function(response) { return response.ok ? response.json() : null; })
            .then(function(result) {
                if (result && Number(result.remaining) > 0) {
                    window.setTimeout(function() {
                        processAnnouncementQueue(announcementId);
                    }, 1200);
                }
            })
            .catch(function() {});
    }

    if (queuedAnnouncementId > 0 || pendingDeliveries > 0) {
        window.setTimeout(function() {
            processAnnouncementQueue(queuedAnnouncementId);
        }, 300);
    }

    document.querySelectorAll('.announcement-details-toggle').forEach(function(button) {
        button.addEventListener('click', function() {
            const details = document.getElementById(button.dataset.target || '');
            if (!details) {
                return;
            }
            const willOpen = details.hidden;
            details.hidden = !willOpen;
            document.querySelectorAll('[data-target="' + details.id + '"]').forEach(function(linkedButton) {
                linkedButton.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                if (linkedButton.classList.contains('view-details-btn')) {
                    linkedButton.innerHTML = willOpen
                        ? 'Hide Details <i class="fas fa-arrow-up"></i>'
                        : 'View Details <i class="fas fa-arrow-right"></i>';
                }
            });
        });
    });

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
