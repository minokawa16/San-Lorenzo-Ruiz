<?php
/**
 * Manage Announcements Page
 * Admin interface for managing parish announcements
 */

require_once '../includes/session.php';
require_once '../database/config.php';
require_once '../includes/helpers.php';
require_once '../services/AnnouncementService.php';

requireAdmin();
requirePermission('announcements.manage');

$error = '';
$success = '';
$queued_announcement_id = 0;

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
if (!function_exists('ensureAnnouncementManagementSchema')) {
    function ensureAnnouncementManagementSchema($conn) {
        return requireSchemaColumns($conn, 'announcements', [
            'deleted_at', 'event_date', 'is_pinned', 'scheduled_at'
        ], 'announcement management');
    }
}

// Announcement Type Meta Function - Documents this helper's role in the parish management workflow.
if (!function_exists('announcementTypeMeta')) {
    function announcementTypeMeta($type, $meta) {
        return $meta[$type] ?? ['icon' => 'fa-bullhorn', 'tone' => 'general', 'label' => ucfirst(str_replace('_', ' ', (string) $type))];
    }
}

// Announcement Preview Text Function - Documents this helper's role in the parish management workflow.
if (!function_exists('announcementPreviewText')) {
    function announcementPreviewText($content, $length = 210) {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string) $content)));
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($plain, 0, $length, '...');
        }
        return strlen($plain) > $length ? substr($plain, 0, $length - 3) . '...' : $plain;
    }
}

// Clean Announcement Content Function - Documents this helper's role in the parish management workflow.
if (!function_exists('cleanAnnouncementContent')) {
    function cleanAnnouncementContent($content) {
        $content = trim((string) $content);
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
        $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $content);
        return strip_tags($content, '<p><br><strong><b><em><i><ul><ol><li><a>');
    }
}

// Active Announcement Where Function - Documents this helper's role in the parish management workflow.
if (!function_exists('activeAnnouncementWhere')) {
    function activeAnnouncementWhere() {
        return "deleted_at IS NULL AND status = 'active' AND (scheduled_at IS NULL OR scheduled_at <= NOW())";
    }
}

// Save Announcement Attachment Function - Documents this helper's role in the parish management workflow.
if (!function_exists('saveAnnouncementAttachment')) {
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
}

if (!function_exists('recordAnnouncementAttachment')) {
    function recordAnnouncementAttachment($conn, $announcement_id, array $attachment, $actor_id) {
        if (empty($attachment['path'])) return;
        $absolute = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $attachment['path']);
        $hash = is_file($absolute) ? hash_file('sha256', $absolute) : str_repeat('0', 64);
        $stmt = $conn->prepare('INSERT INTO announcement_attachments(announcement_id,stored_path,original_name,mime_type,file_size,file_hash,uploaded_by) VALUES(?,?,?,?,?,?,?)');
        $stmt->bind_param('isssisi', $announcement_id, $attachment['path'], $attachment['original_name'], $attachment['mime_type'], $attachment['size'], $hash, $actor_id);
        $stmt->execute(); $stmt->close();
    }
}

if (!function_exists('announcementRelativeTime')) {
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
}

if (!function_exists('ensureAnnouncementDeliveryQueueSchema')) {
    function ensureAnnouncementDeliveryQueueSchema($conn) {
        return requireSchemaColumns($conn, 'announcement_recipients', [
            'sms_delivery_status', 'sms_sent_at', 'last_error'
        ], 'announcement delivery queue');
    }
}

// Announcement Notification Queue - Creates in-app alerts now and queues email/SMS delivery for fast posting.
if (!function_exists('queueAnnouncementNotifications')) {
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
}

if (!function_exists('processAnnouncementDeliveryQueue')) {
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
}

// Publish Due Scheduled Announcements Function - Documents this helper's role in the parish management workflow.
if (!function_exists('publishDueScheduledAnnouncements')) {
    function publishDueScheduledAnnouncements($conn) {
        (new AnnouncementService($conn))->tick((int)($_SESSION['user_id']??0));
    }
}

ensureAnnouncementManagementSchema($conn);
ensureAnnouncementDeliveryQueueSchema($conn);
publishDueScheduledAnnouncements($conn);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';
    $announcement_id = intval($_POST['announcement_id'] ?? 0);
    $announcement_service = new AnnouncementService($conn);
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_POST['ajax']);

    if ($action === 'process_announcement_queue') {
        header('Content-Type: application/json');
        echo json_encode(processAnnouncementDeliveryQueue($conn, $announcement_id, 5));
        exit;
    } elseif ($action === 'archive_announcement' && $announcement_id > 0) {
        try {
            $announcement_service->archive($announcement_id, (string)($_POST['archive_reason'] ?? 'Archived through announcement management.'), (int)$_SESSION['user_id']);
            $success = 'Announcement archived successfully.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => empty($error), 'message' => $success, 'error' => $error]);
            exit;
        }
    } elseif ($action === 'delete_announcement' && $announcement_id > 0) {
        try {
            $announcement_service->archive($announcement_id, (string)($_POST['archive_reason'] ?? 'Archived instead of permanent deletion.'), (int)$_SESSION['user_id']);
            $success = 'Announcement archived; its history and attachments were preserved.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => empty($error), 'message' => $success, 'error' => $error]);
            exit;
        }
    } elseif ($action === 'toggle_pin' && $announcement_id > 0) {
        $conn->query("UPDATE announcements SET is_pinned = IF(is_pinned = 1, 0, 1) WHERE announcement_id = $announcement_id AND deleted_at IS NULL");
        createAuditLog($conn, $_SESSION['user_id'], 'PIN_ANNOUNCEMENT', 'announcements', $announcement_id);
        $success = 'Pinned announcement setting updated.';
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'message' => $success]);
            exit;
        }
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
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => empty($error), 'message' => $success, 'error' => $error]);
            exit;
        }
    } elseif ($action === 'send_notification' && $announcement_id > 0) {
        $announcement_service->notifyNow($announcement_id, (int)$_SESSION['user_id']);
        $success = 'Audience-aware notifications processed. Existing deliveries were not duplicated.';
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'message' => $success]);
            exit;
        }
    } elseif (in_array($action, ['add_announcement', 'edit_announcement'], true)) {
        $title = trim(sanitize($_POST['title'] ?? ''));
        $content = cleanAnnouncementContent($_POST['content'] ?? '');
        $type_raw = $_POST['type'] ?? 'announcement';
        $type = array_key_exists($type_raw, $announcement_types) ? $type_raw : 'announcement';
        $event_date = trim($_POST['event_date'] ?? '');
        $event_date_value = $event_date !== '' ? $event_date : null;
        $publish_mode = $_POST['publish_mode'] ?? 'now';
        if (!in_array($publish_mode, ['now', 'later', 'draft'], true)) {
            $publish_mode = 'now';
        }
        $scheduled_at = trim($_POST['scheduled_at'] ?? '');
        $scheduled_value = $publish_mode === 'later' && $scheduled_at !== '' ? str_replace('T', ' ', $scheduled_at) . (strlen($scheduled_at) === 16 ? ':00' : '') : null;
        $status = $publish_mode === 'now' ? 'active' : 'inactive';
        $expires_raw = trim($_POST['expires_at'] ?? '');
        $expires_value = !empty($expires_raw) ? str_replace('T', ' ', $expires_raw) . (strlen($expires_raw) === 16 ? ':00' : '') : null;
        $audience_type = (string)($_POST['audience_type'] ?? 'everyone');
        if (!in_array($audience_type, ['everyone', 'district', 'chapel', 'selected_users'], true)) {
            $audience_type = 'everyone';
        }
        $audience_values = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['audience_values'] ?? '')))));
        $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
        $notify_all = isset($_POST['notify_all']);
        $notify_email = isset($_POST['notify_email']);
        $notify_sms = isset($_POST['notify_sms']);
        $notify_system = isset($_POST['notify_system']);

        if ($title === '') {
            $error = 'Please enter an announcement title.';
        } elseif (trim(strip_tags($content)) === '') {
            $error = 'Please provide the announcement content.';
        } elseif ($publish_mode === 'later' && empty($scheduled_at)) {
            $error = 'Please specify a future date and time for publication.';
        } elseif ($publish_mode === 'later' && strtotime($scheduled_value) !== false && strtotime($scheduled_value) <= time()) {
            $error = 'The scheduled publication date and time must be in the future.';
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
                        recordAnnouncementAttachment($conn, $new_id, $attachment, (int)$_SESSION['user_id']);
                        try {
                            $announcement_service->configure($new_id, $publish_mode, $scheduled_value, $expires_value, $audience_type, $audience_values, (int)$_SESSION['user_id']);
                            createAuditLog($conn, $_SESSION['user_id'], 'ADD_ANNOUNCEMENT', 'announcements', $new_id);
                            if ($publish_mode === 'now' && ($notify_all || $notify_email || $notify_sms || $notify_system)) {
                                $queued = queueAnnouncementNotifications($conn, $new_id, $title, $notify_email, $notify_system, $notify_sms);
                                $queued_announcement_id = $new_id;
                            }
                            $success = $publish_mode === 'now' ? 'Announcement published successfully.' : ($publish_mode === 'draft' ? 'Announcement saved as draft.' : 'Announcement scheduled successfully.');
                        } catch (Throwable $e) {
                            $error = 'Announcement created, but configuration error occurred: ' . $e->getMessage();
                        }
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
                        recordAnnouncementAttachment($conn, $announcement_id, $attachment, (int)$_SESSION['user_id']);
                        try {
                            $announcement_service->configure($announcement_id, $publish_mode, $scheduled_value, $expires_value, $audience_type, $audience_values, (int)$_SESSION['user_id']);
                            createAuditLog($conn, $_SESSION['user_id'], 'EDIT_ANNOUNCEMENT', 'announcements', $announcement_id);
                            $success = 'Announcement updated successfully.';
                        } catch (Throwable $e) {
                            $error = 'Announcement updated, but configuration error occurred: ' . $e->getMessage();
                        }
                    } else {
                        $error = 'Error updating announcement: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error = 'Error preparing announcement update: ' . $conn->error;
                }
            }
        }

        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => empty($error),
                'message' => $success,
                'error' => $error,
                'announcement_id' => $new_id ?? $announcement_id,
                'queued_announcement_id' => $queued_announcement_id ?? null
            ]);
            exit;
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
        (SELECT COUNT(*) FROM announcement_recipients ar WHERE ar.announcement_id = a.announcement_id AND (ar.delivery_status = 'pending' OR ar.sms_delivery_status = 'pending')) AS pending_deliveries,
        (SELECT GROUP_CONCAT(COALESCE(audience_value, user_id) SEPARATOR ', ') FROM announcement_audiences aa WHERE aa.announcement_id = a.announcement_id AND aa.audience_type != 'everyone') AS audience_values_list
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
    body.modal-open { overflow: hidden !important; }
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

    /* Announcement Modal Architecture & Strict Internal Scrolling */
    .announcement-modal-dialog {
        width: min(1100px, 95vw);
        max-width: 1100px;
        margin: 1.5rem auto;
    }
    .announcement-modal-content {
        max-height: 90vh;
        height: 90vh;
        display: flex;
        flex-direction: column;
        border-radius: 16px;
        border: 1px solid #e6e0d4;
        background: #ffffff;
        box-shadow: 0 25px 60px rgba(46, 58, 45, 0.28);
        overflow: hidden;
    }
    .announcement-form {
        display: flex;
        flex-direction: column;
        flex: 1 1 auto;
        min-height: 0;
        height: 100%;
        overflow: hidden;
    }
    .announcement-modal-header {
        flex-shrink: 0;
        position: sticky;
        top: 0;
        z-index: 15;
        padding: 18px 24px;
        background: #ffffff;
        border-bottom: 1px solid #e6e0d4;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .announcement-modal-header h5 {
        font-size: 21px;
        font-weight: 700;
        color: #2c2c2c;
        margin: 0;
        letter-spacing: -0.01em;
    }
    .announcement-modal-body {
        flex: 1 1 auto;
        overflow-y: auto;
        min-height: 0;
        padding: 24px;
        background: #ffffff;
        -webkit-overflow-scrolling: touch;
    }
    .announcement-modal-footer {
        flex-shrink: 0;
        position: sticky;
        bottom: 0;
        z-index: 15;
        padding: 16px 24px;
        border-top: 1px solid #e6e0d4;
        background: #ffffff;
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 12px;
        box-shadow: 0 -4px 14px rgba(0, 0, 0, 0.03);
    }
    .announcement-modal-footer .btn {
        min-height: 42px;
        padding: 8px 20px;
        font-weight: 600;
        border-radius: 8px;
    }

    /* Modal Form Element Styling */
    .modal .form-label {
        font-weight: 700;
        color: #2e3a2d;
        font-size: 13px;
        margin-bottom: 6px;
    }
    .editor-wrapper {
        border: 1px solid #dcd6c8;
        border-radius: 8px;
        overflow: hidden;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
        background: #ffffff;
    }
    .editor-wrapper:focus-within {
        border-color: #c89b3c;
        box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.2);
    }
    .editor-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        padding: 8px 10px;
        background: #fbf9f5;
        border-bottom: 1px solid #e6e0d4;
    }
    .editor-toolbar button {
        width: 34px;
        height: 34px;
        border: 1px solid #e2dcce;
        background: #ffffff;
        color: #4a4a4a;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 13px;
        transition: all 0.15s ease;
    }
    .editor-toolbar button:hover {
        background: #f5efe1;
        border-color: #c89b3c;
        color: #2e3a2d;
    }
    .rich-editor {
        min-height: 180px;
        max-height: 280px;
        overflow-y: auto;
        padding: 14px;
        background: #ffffff;
        line-height: 1.65;
        outline: none;
        font-size: 14px;
        color: #2c2c2c;
    }
    .rich-editor:empty:before {
        content: attr(data-placeholder);
        color: #94a3b8;
        pointer-events: none;
    }
    .attachment-box {
        border: 1.5px dashed #dcd6c8;
        background: #fcfbf9;
        border-radius: 10px;
        padding: 14px 16px;
        transition: border-color 0.2s, background 0.2s;
    }
    .attachment-box:hover {
        border-color: #c89b3c;
        background: #faf6ed;
    }
    .attachment-file-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 12px;
        background: #edf7f1;
        border: 1px solid #c2e2cc;
        color: #1b5e20;
        border-radius: 6px;
        font-size: 12.5px;
        font-weight: 500;
        margin-top: 8px;
    }
    .attachment-file-pill button.btn-remove-file {
        background: transparent;
        border: none;
        color: #dc2626;
        cursor: pointer;
        padding: 0 2px;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
    }
    .attachment-file-pill button.btn-remove-file:hover {
        color: #991b1b;
    }
    .publish-mode-group {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        margin-bottom: 12px;
    }
    .publish-mode-card {
        border: 1.5px solid #e2dcce;
        border-radius: 10px;
        padding: 12px 14px;
        background: #ffffff;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        flex-direction: column;
        gap: 4px;
        user-select: none;
    }
    .publish-mode-card:hover {
        border-color: #c89b3c;
        background: #fdfbf7;
    }
    .publish-mode-card.active {
        border-color: #2e3a2d;
        background: #f5f3ee;
        box-shadow: 0 2px 8px rgba(46, 58, 45, 0.1);
    }
    .publish-mode-card input[type="radio"] {
        margin-right: 6px;
        accent-color: #2e3a2d;
    }
    .publish-mode-card .mode-title {
        font-weight: 700;
        font-size: 13px;
        color: #2c2c2c;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .publish-mode-card .mode-desc {
        font-size: 11.5px;
        color: #6f6f6f;
        line-height: 1.35;
        padding-left: 20px;
    }
    .notification-settings-panel {
        background: #f8f6f1;
        border: 1px solid #e6e0d4;
        border-radius: 10px;
        padding: 14px 16px;
    }
    .form-check-custom {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 500;
        color: #334155;
        cursor: pointer;
        user-select: none;
    }
    .form-check-custom input[type="checkbox"] {
        width: 17px;
        height: 17px;
        accent-color: #2e3a2d;
        cursor: pointer;
    }
    .field-error-message {
        color: #dc2626;
        font-size: 12px;
        font-weight: 600;
        margin-top: 4px;
        display: none;
    }
    .is-invalid + .field-error-message,
    .has-error .field-error-message {
        display: block;
    }
    .form-control.is-invalid, .form-select.is-invalid {
        border-color: #dc2626 !important;
        background-color: #fef2f2 !important;
    }

    @media (max-width: 1180px) {
        .stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .announcement-card { min-height: 200px; }
    }
    @media (max-width: 768px) {
        .announcement-shell { padding: 12px; }
        .stat-grid { grid-template-columns: 1fr; }
        .announcement-card { grid-template-columns: 1fr; min-height: 0; border-radius: 16px; }
        .announcement-icon-panel { min-height: 82px; flex-direction: row; justify-content: flex-start; padding: 16px 18px; border-radius: 15px 15px 0 0; }
        .announcement-icon-panel i { font-size: 24px; }
        .card-main { padding: 20px 18px; }
        .announcement-card-top { align-items: flex-start; }
        .announcement-card-tools > span { display: none; }
        .card-main h3 { font-size: 20px; }
        .card-main p { font-size: 14px; }
        .announcement-meta { display: grid; gap: 8px; }
        .announcement-card-footer { align-items: stretch; flex-direction: column; }
        .view-details-btn { width: 100%; }
        .announcement-full-content img { width: 100%; max-width: none; }
        .announcement-section-heading { align-items: start; }
        .feed-pagination { align-items: flex-start; flex-direction: column; }

        .announcement-modal-dialog {
            width: 96vw;
            max-width: 96vw;
            margin: 0.75rem auto;
        }
        .announcement-modal-content {
            max-height: 92vh;
            height: 92vh;
            border-radius: 14px;
        }
        .announcement-modal-header {
            padding: 14px 16px;
        }
        .announcement-modal-body {
            padding: 16px;
        }
        .announcement-modal-footer {
            padding: 12px 16px;
            flex-direction: row;
            justify-content: flex-end;
        }
        .publish-mode-group {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="container-fluid px-0">
    <!-- Standardized Section Header -->
    <?php
    $page_header_title = 'Announcements';
    $page_header_subtitle = 'Publish and manage parish notices, bulletins, and event updates.';
    $page_header_icon = 'fa-bullhorn';
    $show_back_button = true;
    $back_button_url = BASE_URL . 'admin/dashboard.php';
    include '../includes/page_header.php';
    ?>

    <div class="announcement-admin-page">
        <div class="announcement-shell">
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
    'expires_at' => '',
    'status' => 'active',
    'lifecycle_status' => 'published',
    'audience_type' => 'everyone',
    'audience_values_list' => '',
    'is_pinned' => 0,
    'attachment_original_name' => '',
    'attachment_size' => 0
];
$modal_announcements = array_merge([$blank_announcement], $announcements);
?>

<?php foreach ($modal_announcements as $modal_item): ?>
    <?php
        $is_edit = !empty($modal_item['announcement_id']);
        $modal_id = $is_edit ? 'editAnnouncement-' . intval($modal_item['announcement_id']) : 'announcementModal';
        $content_id = $is_edit ? 'editor-' . intval($modal_item['announcement_id']) : 'editor-new';
        $input_id = $is_edit ? 'content-' . intval($modal_item['announcement_id']) : 'content-new';
        $file_input_id = $is_edit ? 'attachment-' . intval($modal_item['announcement_id']) : 'attachment-new';
        $file_pill_id = $is_edit ? 'attachment-pill-' . intval($modal_item['announcement_id']) : 'attachment-pill-new';
        $scheduled_local = !empty($modal_item['scheduled_at']) ? date('Y-m-d\TH:i', strtotime($modal_item['scheduled_at'])) : '';
        $expires_local = !empty($modal_item['expires_at']) ? date('Y-m-d\TH:i', strtotime($modal_item['expires_at'])) : '';
        $is_later = !empty($modal_item['scheduled_at']) && strtotime($modal_item['scheduled_at']) > time() && ($modal_item['status'] === 'inactive' || ($modal_item['lifecycle_status'] ?? '') === 'scheduled');
        $is_draft = ($modal_item['lifecycle_status'] ?? '') === 'draft';
        $current_mode = $is_later ? 'later' : ($is_draft ? 'draft' : 'now');
        $current_audience = $modal_item['audience_type'] ?? 'everyone';
        $audience_vals = $modal_item['audience_values_list'] ?? '';
    ?>
    <div class="modal fade announcement-modal-root" id="<?php echo e($modal_id); ?>" tabindex="-1" aria-labelledby="<?php echo e($modal_id); ?>Label" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog announcement-modal-dialog">
            <div class="modal-content announcement-modal-content">
                <form method="POST" enctype="multipart/form-data" class="announcement-form" novalidate>
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="<?php echo $is_edit ? 'edit_announcement' : 'add_announcement'; ?>">
                    <?php if ($is_edit): ?><input type="hidden" name="announcement_id" value="<?php echo intval($modal_item['announcement_id']); ?>"><?php endif; ?>
                    <input type="hidden" name="content" id="<?php echo e($input_id); ?>" value="<?php echo e($modal_item['content']); ?>">

                    <!-- Fixed Modal Header -->
                    <div class="modal-header announcement-modal-header">
                        <div>
                            <span class="gold-kicker mb-1"><i class="fas fa-bullhorn"></i> Announcement Editor</span>
                            <h5 class="modal-title" id="<?php echo e($modal_id); ?>Label"><?php echo $is_edit ? 'Edit Announcement' : 'Create New Announcement'; ?></h5>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <!-- Scrollable Modal Body -->
                    <div class="modal-body announcement-modal-body">
                        <!-- In-modal alert container for instant error feedback -->
                        <div class="modal-alert-container" style="display:none;"></div>

                        <div class="row g-3">
                            <!-- Row 1: Title & Category -->
                            <div class="col-lg-8">
                                <label class="form-label" for="title-<?php echo e($modal_id); ?>">Title <span class="text-danger">*</span></label>
                                <input class="form-control control-lg announcement-title-input" id="title-<?php echo e($modal_id); ?>" type="text" name="title" value="<?php echo e($modal_item['title']); ?>" placeholder="Enter announcement title..." required>
                                <div class="field-error-message"></div>
                            </div>
                            <div class="col-lg-4">
                                <label class="form-label" for="type-<?php echo e($modal_id); ?>">Category <span class="text-danger">*</span></label>
                                <select class="form-select control-lg announcement-category-select" id="type-<?php echo e($modal_id); ?>" name="type" required>
                                    <?php foreach ($announcement_types as $value => $label): ?>
                                        <option value="<?php echo e($value); ?>" <?php echo $modal_item['type'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="field-error-message"></div>
                            </div>

                            <!-- Row 2: Rich Text Announcement Content -->
                            <div class="col-12">
                                <label class="form-label">Announcement Content <span class="text-danger">*</span></label>
                                <div class="editor-wrapper">
                                    <div class="editor-toolbar" role="toolbar" aria-label="Editor toolbar">
                                        <button type="button" data-command="bold" title="Bold (Ctrl+B)"><i class="fas fa-bold"></i></button>
                                        <button type="button" data-command="italic" title="Italic (Ctrl+I)"><i class="fas fa-italic"></i></button>
                                        <button type="button" data-command="underline" title="Underline (Ctrl+U)"><i class="fas fa-underline"></i></button>
                                        <button type="button" data-command="insertUnorderedList" title="Bulleted List"><i class="fas fa-list-ul"></i></button>
                                        <button type="button" data-command="insertOrderedList" title="Numbered List"><i class="fas fa-list-ol"></i></button>
                                        <button type="button" data-command="createLink" title="Insert Link"><i class="fas fa-link"></i></button>
                                    </div>
                                    <div class="rich-editor" id="<?php echo e($content_id); ?>" contenteditable="true" data-target="<?php echo e($input_id); ?>" data-placeholder="Write announcement content here..."><?php echo cleanAnnouncementContent($modal_item['content']); ?></div>
                                </div>
                                <div class="field-error-message"></div>
                                <div class="form-text mt-1 text-muted" style="font-size: 12px;">Use short paragraphs for readability. The public page will show a preview with a Read More option.</div>
                            </div>

                            <!-- Row 3: Attachment Upload & Event Date -->
                            <div class="col-lg-6">
                                <label class="form-label" for="<?php echo e($file_input_id); ?>">Attachment Upload</label>
                                <div class="attachment-box">
                                    <input class="form-control announcement-file-input" id="<?php echo e($file_input_id); ?>" type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain" data-pill="<?php echo e($file_pill_id); ?>">
                                    <div class="form-text" style="font-size: 11.5px; margin-top: 4px;">PDFs, images, flyers, Office documents, or text files (Max 10MB).</div>
                                    <div class="attachment-file-pill" id="<?php echo e($file_pill_id); ?>" style="<?php echo (!empty($modal_item['attachment_original_name'])) ? '' : 'display:none;'; ?>">
                                        <i class="fas fa-paperclip me-1"></i>
                                        <span class="file-name-text"><?php echo e($modal_item['attachment_original_name'] ?: 'Selected file'); ?></span>
                                        <?php if (!empty($modal_item['attachment_size'])): ?>
                                            <span class="badge bg-secondary ms-1" style="font-size:10px;"><?php echo formatFileSize($modal_item['attachment_size']); ?></span>
                                        <?php endif; ?>
                                        <button type="button" class="btn-remove-file ms-2" title="Remove attached file"><i class="fas fa-times"></i></button>
                                    </div>
                                    <div class="field-error-message"></div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <label class="form-label" for="event_date-<?php echo e($modal_id); ?>">Event Date <span class="text-muted fw-normal" style="font-size: 12px;">(Optional)</span></label>
                                <input class="form-control control-lg announcement-event-date" id="event_date-<?php echo e($modal_id); ?>" type="date" name="event_date" value="<?php echo e($modal_item['event_date']); ?>">
                                <div class="form-text" style="font-size: 11.5px; margin-top: 4px;">Specify if this announcement is for a date-specific parish event, mass, or feast day.</div>
                                <div class="field-error-message"></div>
                            </div>

                            <!-- Row 4: Publication Schedule -->
                            <div class="col-12">
                                <label class="form-label mb-2">Schedule Publication <span class="text-danger">*</span></label>
                                <div class="publish-mode-group">
                                    <label class="publish-mode-card <?php echo $current_mode === 'now' ? 'active' : ''; ?>">
                                        <div class="mode-title">
                                            <input type="radio" name="publish_mode" value="now" <?php echo $current_mode === 'now' ? 'checked' : ''; ?>>
                                            <i class="fas fa-bolt text-warning"></i> Publish Now
                                        </div>
                                        <div class="mode-desc">The announcement is published immediately and visible to parishioners.</div>
                                    </label>
                                    <label class="publish-mode-card <?php echo $current_mode === 'later' ? 'active' : ''; ?>">
                                        <div class="mode-title">
                                            <input type="radio" name="publish_mode" value="later" <?php echo $current_mode === 'later' ? 'checked' : ''; ?>>
                                            <i class="fas fa-calendar-plus text-primary"></i> Schedule Publication
                                        </div>
                                        <div class="mode-desc">Set a future date and time for automatic publication.</div>
                                    </label>
                                    <label class="publish-mode-card <?php echo $current_mode === 'draft' ? 'active' : ''; ?>">
                                        <div class="mode-title">
                                            <input type="radio" name="publish_mode" value="draft" <?php echo $current_mode === 'draft' ? 'checked' : ''; ?>>
                                            <i class="fas fa-file-pen text-secondary"></i> Save as Draft
                                        </div>
                                        <div class="mode-desc">Save the announcement privately to review or publish later.</div>
                                    </label>
                                </div>
                            </div>

                            <!-- Dynamic Scheduled Date/Time & Expiration Group -->
                            <div class="col-lg-6 schedule-datetime-wrapper" style="<?php echo $current_mode === 'later' ? '' : 'display:none;'; ?>">
                                <label class="form-label" for="scheduled_at-<?php echo e($modal_id); ?>">Publication Date and Time <span class="text-danger">*</span></label>
                                <input class="form-control control-lg announcement-scheduled-input" id="scheduled_at-<?php echo e($modal_id); ?>" type="datetime-local" name="scheduled_at" value="<?php echo e($scheduled_local); ?>" min="<?php echo date('Y-m-d\TH:i'); ?>">
                                <div class="form-text" style="font-size: 11.5px;">Philippine Standard Time (Asia/Manila). Must be in the future.</div>
                                <div class="field-error-message"></div>
                            </div>
                            <div class="col-lg-6">
                                <label class="form-label" for="expires_at-<?php echo e($modal_id); ?>">Expiration Date & Time <span class="text-muted fw-normal" style="font-size: 12px;">(Optional)</span></label>
                                <input class="form-control control-lg announcement-expires-input" id="expires_at-<?php echo e($modal_id); ?>" type="datetime-local" name="expires_at" value="<?php echo e($expires_local); ?>" min="<?php echo date('Y-m-d\TH:i'); ?>">
                                <div class="form-text" style="font-size: 11.5px;">Announcement automatically archives after this date/time.</div>
                                <div class="field-error-message"></div>
                            </div>

                            <!-- Row 5: Recipients & Visibility / Audience -->
                            <div class="col-lg-6">
                                <label class="form-label" for="audience_type-<?php echo e($modal_id); ?>">Recipients / Visibility</label>
                                <select class="form-select control-lg announcement-audience-select" id="audience_type-<?php echo e($modal_id); ?>" name="audience_type">
                                    <option value="everyone" <?php echo $current_audience === 'everyone' ? 'selected' : ''; ?>>Everyone (Public Parish Announcement)</option>
                                    <option value="district" <?php echo $current_audience === 'district' ? 'selected' : ''; ?>>Specific District</option>
                                    <option value="chapel" <?php echo $current_audience === 'chapel' ? 'selected' : ''; ?>>Specific Chapel</option>
                                    <option value="selected_users" <?php echo $current_audience === 'selected_users' ? 'selected' : ''; ?>>Selected User IDs</option>
                                </select>
                            </div>
                            <div class="col-lg-6 audience-values-wrapper" style="<?php echo $current_audience !== 'everyone' ? '' : 'display:none;'; ?>">
                                <label class="form-label" for="audience_values-<?php echo e($modal_id); ?>">Target Audience Values</label>
                                <input class="form-control control-lg announcement-audience-values" id="audience_values-<?php echo e($modal_id); ?>" name="audience_values" value="<?php echo e($audience_vals); ?>" placeholder="e.g. San Roque, District 1, or 12, 15">
                                <div class="form-text" style="font-size: 11.5px;">Comma-separated chapel/district names or user IDs.</div>
                                <div class="field-error-message"></div>
                            </div>

                            <!-- Row 6: Notification Channels & Pinned Status -->
                            <div class="col-12">
                                <label class="form-label">Notification Channels & Options</label>
                                <div class="notification-settings-panel">
                                    <div class="d-flex flex-wrap gap-4">
                                        <label class="form-check-custom">
                                            <input type="checkbox" name="notify_all" checked>
                                            <span>Notify All Parishioners</span>
                                        </label>
                                        <label class="form-check-custom">
                                            <input type="checkbox" name="notify_email" checked>
                                            <span>Send Email</span>
                                        </label>
                                        <label class="form-check-custom">
                                            <input type="checkbox" name="notify_sms" checked>
                                            <span>Send SMS</span>
                                        </label>
                                        <label class="form-check-custom">
                                            <input type="checkbox" name="notify_system" checked>
                                            <span>Send In-System Notification</span>
                                        </label>
                                        <label class="form-check-custom">
                                            <input type="checkbox" name="is_pinned" <?php echo intval($modal_item['is_pinned']) === 1 ? 'checked' : ''; ?>>
                                            <span><i class="fas fa-thumbtack text-warning me-1"></i> Pin Announcement</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Fixed Modal Footer with Always-Accessible Action Buttons -->
                    <div class="modal-footer announcement-modal-footer">
                        <button type="button" class="btn btn-outline-secondary announcement-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary announcement-submit-btn">
                            <i class="fas fa-paper-plane me-1"></i>
                            <span class="submit-btn-text"><?php echo $is_edit ? 'Save Changes' : ($current_mode === 'later' ? 'Schedule Announcement' : ($current_mode === 'draft' ? 'Save as Draft' : 'Publish Announcement')); ?></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php foreach ($announcements as $announcement): ?>
    <div class="modal fade" id="deleteAnnouncement-<?php echo intval($announcement['announcement_id']); ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 14px; border: 1px solid #e6e0d4; overflow: hidden;">
                <form method="POST">
                    <?php echo csrfInput(); ?>
                    <div class="modal-header" style="background: #ffffff; border-bottom: 1px solid #e6e0d4; padding: 16px 20px;">
                        <h5 class="modal-title" style="font-weight: 700; color: #a93232; margin: 0;"><i class="fas fa-trash-can me-2"></i>Delete Announcement</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="padding: 20px;">
                        <p class="mb-1">Are you sure you want to archive <strong><?php echo e($announcement['title']); ?></strong>?</p>
                        <p class="text-muted mb-0" style="font-size: 13px;">The announcement and its historical records will be preserved safely in the archives.</p>
                        <input type="hidden" name="action" value="delete_announcement">
                        <input type="hidden" name="announcement_id" value="<?php echo intval($announcement['announcement_id']); ?>">
                        <label class="form-label mt-3 fw-bold">Archive Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="archive_reason" required minlength="5" placeholder="State reason for archiving..."></textarea>
                    </div>
                    <div class="modal-footer" style="background: #ffffff; border-top: 1px solid #e6e0d4; padding: 14px 20px; display: flex; justify-content: flex-end; gap: 10px;">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-archive me-1"></i> Archive</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const queuedAnnouncementId = <?php echo intval($queued_announcement_id ?? 0); ?>;
    const pendingDeliveries = <?php echo intval($pending_announcement_delivery_count ?? 0); ?>;

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

    // Toggle details expansion
    document.querySelectorAll('.announcement-details-toggle').forEach(function(button) {
        button.addEventListener('click', function() {
            const details = document.getElementById(button.dataset.target || '');
            if (!details) return;
            const willOpen = details.hidden;
            details.hidden = !willOpen;
            document.querySelectorAll('[data-target="' + details.id + '"]').forEach(function(linkedButton) {
                linkedButton.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                if (linkedButton.classList.contains('view-details-btn')) {
                    linkedButton.innerHTML = willOpen
                        ? 'Hide Details <i class="fas fa-arrow-up ms-1"></i>'
                        : 'View Details <i class="fas fa-arrow-right ms-1"></i>';
                }
            });
        });
    });

    // Rich text editor synchronization
    document.querySelectorAll('.rich-editor').forEach(function(editor) {
        const target = document.getElementById(editor.dataset.target);
        function syncEditor() {
            if (target) {
                target.value = editor.innerHTML.trim();
            }
        }
        editor.addEventListener('input', syncEditor);
        editor.addEventListener('keyup', syncEditor);
        editor.addEventListener('paste', function() {
            setTimeout(syncEditor, 10);
        });
        editor.addEventListener('blur', syncEditor);
    });

    // Rich text editor toolbar buttons
    document.querySelectorAll('.editor-toolbar button').forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const toolbar = button.closest('.editor-toolbar');
            const editor = toolbar ? toolbar.nextElementSibling : null;
            if (!editor) return;
            editor.focus();
            const command = button.dataset.command;
            if (command === 'createLink') {
                const url = window.prompt('Enter website URL:');
                if (url && url.trim()) {
                    document.execCommand(command, false, url.trim());
                }
            } else {
                document.execCommand(command, false, null);
            }
            editor.dispatchEvent(new Event('input'));
        });
    });

    // Attachment file validation & pill management
    const maxFileSize = 10 * 1024 * 1024; // 10MB
    const allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];

    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    document.querySelectorAll('.announcement-file-input').forEach(function(fileInput) {
        const pillId = fileInput.dataset.pill;
        const pill = document.getElementById(pillId);
        const errorDiv = fileInput.closest('.attachment-box')?.querySelector('.field-error-message');

        fileInput.addEventListener('change', function() {
            if (errorDiv) {
                errorDiv.style.display = 'none';
                errorDiv.textContent = '';
            }
            if (fileInput.files && fileInput.files[0]) {
                const file = fileInput.files[0];
                const ext = file.name.split('.').pop().toLowerCase();

                if (file.size > maxFileSize) {
                    fileInput.value = '';
                    if (pill) pill.style.display = 'none';
                    if (errorDiv) {
                        errorDiv.textContent = 'Attachment exceeds 10MB limit. Please choose a smaller file.';
                        errorDiv.style.display = 'block';
                    }
                    return;
                }

                if (!allowedExtensions.includes(ext)) {
                    fileInput.value = '';
                    if (pill) pill.style.display = 'none';
                    if (errorDiv) {
                        errorDiv.textContent = 'File type not allowed. Allowed formats: images, PDF, Office documents, and text files.';
                        errorDiv.style.display = 'block';
                    }
                    return;
                }

                if (pill) {
                    const nameSpan = pill.querySelector('.file-name-text');
                    if (nameSpan) nameSpan.textContent = file.name + ' (' + formatBytes(file.size) + ')';
                    pill.style.display = 'inline-flex';
                }
            } else {
                if (pill) pill.style.display = 'none';
            }
        });

        if (pill) {
            const removeBtn = pill.querySelector('.btn-remove-file');
            if (removeBtn) {
                removeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    fileInput.value = '';
                    pill.style.display = 'none';
                    if (errorDiv) {
                        errorDiv.style.display = 'none';
                        errorDiv.textContent = '';
                    }
                });
            }
        }
    });

    // Publication mode card selector & dynamic date/time fields
    document.querySelectorAll('.announcement-form').forEach(function(form) {
        const modeCards = form.querySelectorAll('.publish-mode-card');
        const modeRadios = form.querySelectorAll('input[name="publish_mode"]');
        const scheduleWrapper = form.querySelector('.schedule-datetime-wrapper');
        const scheduledInput = form.querySelector('.announcement-scheduled-input');
        const submitBtnText = form.querySelector('.submit-btn-text');
        const isEdit = form.querySelector('input[name="action"]')?.value === 'edit_announcement';

        function updatePublishMode() {
            let selectedValue = 'now';
            modeRadios.forEach(function(radio) {
                if (radio.checked) {
                    selectedValue = radio.value;
                }
            });

            modeCards.forEach(function(card) {
                const radio = card.querySelector('input[type="radio"]');
                if (radio && radio.checked) {
                    card.classList.add('active');
                } else {
                    card.classList.remove('active');
                }
            });

            if (selectedValue === 'later') {
                if (scheduleWrapper) scheduleWrapper.style.display = 'block';
                if (scheduledInput) scheduledInput.required = true;
                if (!isEdit && submitBtnText) submitBtnText.textContent = 'Schedule Announcement';
            } else if (selectedValue === 'draft') {
                if (scheduleWrapper) scheduleWrapper.style.display = 'none';
                if (scheduledInput) {
                    scheduledInput.required = false;
                }
                if (!isEdit && submitBtnText) submitBtnText.textContent = 'Save as Draft';
            } else {
                if (scheduleWrapper) scheduleWrapper.style.display = 'none';
                if (scheduledInput) {
                    scheduledInput.required = false;
                }
                if (!isEdit && submitBtnText) submitBtnText.textContent = 'Publish Announcement';
            }
        }

        modeCards.forEach(function(card) {
            card.addEventListener('click', function() {
                const radio = card.querySelector('input[type="radio"]');
                if (radio && !radio.checked) {
                    radio.checked = true;
                    updatePublishMode();
                }
            });
        });

        modeRadios.forEach(function(radio) {
            radio.addEventListener('change', updatePublishMode);
        });

        // Audience type selection dynamic field
        const audienceSelect = form.querySelector('.announcement-audience-select');
        const audienceWrapper = form.querySelector('.audience-values-wrapper');
        if (audienceSelect && audienceWrapper) {
            audienceSelect.addEventListener('change', function() {
                if (audienceSelect.value !== 'everyone') {
                    audienceWrapper.style.display = 'block';
                } else {
                    audienceWrapper.style.display = 'none';
                }
            });
        }
    });

    // Form validation helper
    function showFieldError(fieldElement, message) {
        if (!fieldElement) return;
        fieldElement.classList.add('is-invalid');
        const container = fieldElement.closest('.col-12, .col-lg-8, .col-lg-6, .col-lg-4, .attachment-box, .editor-wrapper') || fieldElement.parentElement;
        if (container) {
            const errorDiv = container.querySelector('.field-error-message');
            if (errorDiv) {
                errorDiv.textContent = message;
                errorDiv.style.display = 'block';
            }
        }
    }

    function clearFormErrors(form) {
        form.querySelectorAll('.is-invalid').forEach(function(el) {
            el.classList.remove('is-invalid');
        });
        form.querySelectorAll('.field-error-message').forEach(function(el) {
            el.style.display = 'none';
            el.textContent = '';
        });
        const alertContainer = form.querySelector('.modal-alert-container');
        if (alertContainer) {
            alertContainer.style.display = 'none';
            alertContainer.innerHTML = '';
        }
    }

    function validateForm(form) {
        clearFormErrors(form);
        let isValid = true;
        let firstErrorElement = null;

        // Title validation
        const titleInput = form.querySelector('input[name="title"]');
        if (titleInput && !titleInput.value.trim()) {
            showFieldError(titleInput, 'Announcement title is required.');
            isValid = false;
            if (!firstErrorElement) firstErrorElement = titleInput;
        }

        // Category validation
        const categorySelect = form.querySelector('select[name="type"]');
        if (categorySelect && !categorySelect.value) {
            showFieldError(categorySelect, 'Please select an announcement category.');
            isValid = false;
            if (!firstErrorElement) firstErrorElement = categorySelect;
        }

        // Content validation
        const editor = form.querySelector('.rich-editor');
        const hiddenContent = form.querySelector('input[name="content"]');
        const plainText = editor ? editor.innerText.trim() : (hiddenContent ? hiddenContent.value.replace(/<[^>]*>/g, '').trim() : '');
        if (!plainText) {
            const editorWrapper = form.querySelector('.editor-wrapper');
            showFieldError(editorWrapper, 'Announcement content is required.');
            isValid = false;
            if (!firstErrorElement) firstErrorElement = editor;
        }

        // Scheduled Publication validation
        const publishModeRadio = form.querySelector('input[name="publish_mode"]:checked');
        const publishMode = publishModeRadio ? publishModeRadio.value : 'now';
        if (publishMode === 'later') {
            const scheduledInput = form.querySelector('.announcement-scheduled-input');
            if (scheduledInput) {
                if (!scheduledInput.value) {
                    showFieldError(scheduledInput, 'Please specify a future publication date and time.');
                    isValid = false;
                    if (!firstErrorElement) firstErrorElement = scheduledInput;
                } else {
                    const selectedTime = new Date(scheduledInput.value).getTime();
                    const nowTime = new Date().getTime();
                    if (isNaN(selectedTime) || selectedTime <= nowTime) {
                        showFieldError(scheduledInput, 'The scheduled publication date and time must be in the future.');
                        isValid = false;
                        if (!firstErrorElement) firstErrorElement = scheduledInput;
                    }
                }
            }
        }

        // Expiration validation
        const expiresInput = form.querySelector('.announcement-expires-input');
        if (expiresInput && expiresInput.value) {
            const expTime = new Date(expiresInput.value).getTime();
            const nowTime = new Date().getTime();
            if (isNaN(expTime) || expTime <= nowTime) {
                showFieldError(expiresInput, 'Expiration date and time must be in the future.');
                isValid = false;
                if (!firstErrorElement) firstErrorElement = expiresInput;
            }
        }

        if (!isValid && firstErrorElement) {
            firstErrorElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (firstErrorElement.focus) firstErrorElement.focus();
        }

        return isValid;
    }

    // AJAX form submission with loading state & feedback
    document.querySelectorAll('.announcement-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();

            // Sync rich editor to hidden input
            const editor = form.querySelector('.rich-editor');
            const hiddenContent = form.querySelector('input[name="content"]');
            if (editor && hiddenContent) {
                hiddenContent.value = editor.innerHTML.trim();
            }

            if (!validateForm(form)) {
                return;
            }

            const submitBtn = form.querySelector('.announcement-submit-btn');
            const cancelBtn = form.querySelector('.announcement-cancel-btn');
            const submitBtnText = submitBtn ? submitBtn.querySelector('.submit-btn-text') : null;
            const originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';
            const isEdit = form.querySelector('input[name="action"]')?.value === 'edit_announcement';
            const publishMode = form.querySelector('input[name="publish_mode"]:checked')?.value || 'now';

            let loadingLabel = 'Saving...';
            if (!isEdit) {
                loadingLabel = publishMode === 'later' ? 'Scheduling...' : (publishMode === 'draft' ? 'Saving Draft...' : 'Publishing...');
            }

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + loadingLabel;
            }
            if (cancelBtn) cancelBtn.disabled = true;

            const formData = new FormData(form);
            formData.append('ajax', '1');

            fetch('manage-announcements.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(function(res) {
                if (!res.ok) throw new Error('HTTP error ' + res.status);
                return res.json();
            })
            .then(function(data) {
                if (data.ok) {
                    if (submitBtn) {
                        submitBtn.innerHTML = '<i class="fas fa-check me-2"></i>' + (isEdit ? 'Saved Successfully' : (publishMode === 'later' ? 'Scheduled Successfully' : (publishMode === 'draft' ? 'Saved as Draft' : 'Published Successfully')));
                        submitBtn.classList.remove('btn-primary');
                        submitBtn.classList.add('btn-success');
                    }

                    const modalEl = form.closest('.modal');
                    const bsModal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;

                    window.setTimeout(function() {
                        if (bsModal) {
                            bsModal.hide();
                        }
                        if (data.queued_announcement_id) {
                            processAnnouncementQueue(data.queued_announcement_id);
                        }
                        window.location.reload();
                    }, 500);
                } else {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnHtml;
                    }
                    if (cancelBtn) cancelBtn.disabled = false;

                    const alertContainer = form.querySelector('.modal-alert-container');
                    if (alertContainer) {
                        alertContainer.innerHTML = '<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-circle-exclamation me-2"></i>' + (data.error || 'Unable to publish announcement. Please check the form and try again.') + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
                        alertContainer.style.display = 'block';
                        alertContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                }
            })
            .catch(function(err) {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnHtml;
                }
                if (cancelBtn) cancelBtn.disabled = false;

                const alertContainer = form.querySelector('.modal-alert-container');
                if (alertContainer) {
                    alertContainer.innerHTML = '<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-circle-exclamation me-2"></i>Unable to communicate with the server. Please check the form and try again.<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
                    alertContainer.style.display = 'block';
                    alertContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
        });
    });
});
</script>

<?php include '../templates/footer.php'; ?>
