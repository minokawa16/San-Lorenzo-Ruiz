<?php
/**
 * Notifications Bell API Endpoint
 * --------------------------------
 * Dedicated endpoint for Parish Secretary and Admin Notification Bell:
 * - Polls unread counts & grouped notifications (Schedule Reminders vs Other Updates)
 * - Urgency color mapping: Today (solid red), Tomorrow (red), 2 days (orange), 3 days (gold)
 * - Single notification per schedule (old ones superseded/archived)
 * - Mark as read (single & all)
 * - Role-enforced: Secretary and Administrator access only; users can read only their own
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/ScheduleReminderService.php';

// 1. Authentication & Role Check
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid session.']);
    exit;
}

$userRoles = userRoleKeys($userId, $conn);
$isSecretaryOrAdmin = isAdmin() 
    || in_array('secretary', $userRoles, true) 
    || in_array('parish_staff', $userRoles, true)
    || hasPermission('admin.access')
    || hasPermission('requests.manage');

if (!$isSecretaryOrAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. Staff or Secretary privilege required.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Helper for formatting time ago
function bellTimeAgo(string $datetime): string {
    $time = strtotime($datetime);
    if (!$time) return 'Just now';
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 172800) return 'Yesterday';
    return date('M j', $time);
}

// GET: Return grouped notifications and unread count
if ($method === 'GET') {
    $limitReminders = max(1, min(20, (int)($_GET['limit_reminders'] ?? 10)));
    $limitOthers = max(1, min(20, (int)($_GET['limit_others'] ?? 10)));
    $lastId = (int)($_GET['last_id'] ?? 0);

    // Unread count
    $unreadCount = getUnreadNotificationCount($conn, $userId);

    // 1. Group 1: Schedule Reminders (next 3 days & today)
    // Only non-deleted and non-archived reminders for this user
    // Sorted by urgency: days_before ASC (0 = Today first, then 1, 2, 3), then created_at DESC
    $scheduleReminders = [];
    $schedStmt = $conn->prepare("
        SELECT 
            n.notification_id, n.notification_type, n.title, n.message,
            n.entity_type, n.entity_id, n.action_key, n.state, n.is_read,
            n.related_request_id, n.schedule_id, n.days_before, n.created_at,
            se.event_date, se.start_time, se.end_time, se.location
        FROM notifications n
        LEFT JOIN schedule_events se ON n.schedule_id = se.schedule_id
        WHERE n.user_id = ?
          AND n.schedule_id IS NOT NULL
          AND n.notification_type = 'schedule_reminder'
          AND n.state <> 'deleted'
          AND n.state <> 'archived'
        ORDER BY 
          CASE WHEN n.days_before IS NULL THEN 99 ELSE n.days_before END ASC,
          n.created_at DESC
        LIMIT ?
    ");

    if ($schedStmt) {
        $schedStmt->bind_param('ii', $userId, $limitReminders);
        $schedStmt->execute();
        $res = $schedStmt->get_result();
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/';

        while ($r = $res->fetch_assoc()) {
            $isUnread = (empty($r['is_read']) && ($r['state'] ?? '') !== 'read');
            $days = $r['days_before'] !== null ? (int)$r['days_before'] : null;

            // Urgency color scheme
            // Today (0): solid red
            // Tomorrow (1): red
            // In 2 days (2): orange
            // In 3 days (3): gold
            $urgencyCode = match ($days) {
                0 => 'today',
                1 => 'tomorrow',
                2 => 'in_2_days',
                3 => 'in_3_days',
                default => 'gold'
            };

            $edgeColor = match ($days) {
                0 => '#DC2626', // solid red
                1 => '#EF4444', // red
                2 => '#F97316', // orange
                3 => '#C89B3C', // gold
                default => '#C89B3C'
            };

            $urgencyLabel = match ($days) {
                0 => 'Today',
                1 => 'Tomorrow',
                2 => 'In 2 days',
                3 => 'In 3 days',
                default => 'Upcoming'
            };

            // Action URL
            $actionUrl = !empty($r['related_request_id'])
                ? rtrim($baseUrl, '/') . '/admin/request-workflow.php?id=' . (int)$r['related_request_id']
                : (!empty($r['schedule_id']) 
                    ? rtrim($baseUrl, '/') . '/admin/manage-calendar.php?event=' . (int)$r['schedule_id']
                    : rtrim($baseUrl, '/') . '/admin/manage-requests.php');

            $scheduleReminders[] = [
                'id' => (int)$r['notification_id'],
                'type' => $r['notification_type'],
                'title' => $r['title'],
                'message' => $r['message'],
                'days_before' => $days,
                'urgency_code' => $urgencyCode,
                'urgency_label' => $urgencyLabel,
                'edge_color' => $edgeColor,
                'is_unread' => $isUnread ? 1 : 0,
                'action_url' => $actionUrl,
                'time_ago' => bellTimeAgo($r['created_at']),
                'created_at' => $r['created_at']
            ];
        }
        $schedStmt->close();
    }

    // 2. Group 2: Other Updates (New requests, cancellations, conflicts, stale requests)
    $otherUpdates = [];
    $otherStmt = $conn->prepare("
        SELECT 
            n.notification_id, n.notification_type, n.title, n.message,
            n.entity_type, n.entity_id, n.action_key, n.state, n.is_read,
            n.related_request_id, n.schedule_id, n.created_at
        FROM notifications n
        WHERE n.user_id = ?
          AND (n.schedule_id IS NULL OR n.notification_type <> 'schedule_reminder')
          AND n.state <> 'deleted'
          AND n.state <> 'archived'
        ORDER BY n.created_at DESC
        LIMIT ?
    ");

    if ($otherStmt) {
        $otherStmt->bind_param('ii', $userId, $limitOthers);
        $otherStmt->execute();
        $res = $otherStmt->get_result();
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/';

        while ($r = $res->fetch_assoc()) {
            $isUnread = (empty($r['is_read']) && ($r['state'] ?? '') !== 'read');
            $type = $r['notification_type'];

            // Action URL
            $actionUrl = !empty($r['related_request_id'])
                ? rtrim($baseUrl, '/') . '/admin/request-workflow.php?id=' . (int)$r['related_request_id']
                : ($r['entity_type'] === 'request' && !empty($r['entity_id'])
                    ? rtrim($baseUrl, '/') . '/admin/request-workflow.php?id=' . (int)$r['entity_id']
                    : rtrim($baseUrl, '/') . '/admin/manage-requests.php');

            $icon = match ($type) {
                'new_request_admin', 'new_request' => 'fa-file-circle-plus',
                'schedule_cancelled', 'request_cancelled' => 'fa-ban',
                'schedule_conflict' => 'fa-calendar-xmark',
                'stale_request' => 'fa-clock-rotate-left',
                default => 'fa-bell'
            };

            $iconColor = match ($type) {
                'new_request_admin', 'new_request' => '#0F5132',
                'schedule_cancelled', 'request_cancelled' => '#DC2626',
                'schedule_conflict' => '#B45309',
                'stale_request' => '#C89B3C',
                default => '#475569'
            };

            $otherUpdates[] = [
                'id' => (int)$r['notification_id'],
                'type' => $type,
                'title' => $r['title'],
                'message' => $r['message'],
                'icon' => $icon,
                'icon_color' => $iconColor,
                'is_unread' => $isUnread ? 1 : 0,
                'action_url' => $actionUrl,
                'time_ago' => bellTimeAgo($r['created_at']),
                'created_at' => $r['created_at']
            ];
        }
        $otherStmt->close();
    }

    // Determine max ID across all notifications to detect newly arrived items
    $maxStmt = $conn->prepare("SELECT MAX(notification_id) AS max_id FROM notifications WHERE user_id = ? AND state <> 'deleted'");
    $latestId = 0;
    if ($maxStmt) {
        $maxStmt->bind_param('i', $userId);
        $maxStmt->execute();
        $row = $maxStmt->get_result()->fetch_assoc();
        $latestId = (int)($row['max_id'] ?? 0);
        $maxStmt->close();
    }

    $hasNew = ($lastId > 0 && $latestId > $lastId && $unreadCount > 0);

    echo json_encode([
        'success' => true,
        'unread_count' => $unreadCount,
        'schedule_reminders' => $scheduleReminders,
        'other_updates' => $otherUpdates,
        'latest_id' => $latestId,
        'has_new' => $hasNew
    ]);
    exit;
}

// POST: Actions like mark_read, mark_all_read, dismiss
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
    $data = !empty($input) ? $input : $_POST;

    // CSRF check if provided
    $csrfName = function_exists('csrfTokenName') ? csrfTokenName() : '_csrf_token';
    $token = (string)($data[$csrfName] ?? ($data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    if ($token !== '' && !verifyCsrfToken($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }

    $action = $data['action'] ?? '';

    // Mark single notification as read
    if ($action === 'mark_read') {
        $notifId = (int)($data['notification_id'] ?? 0);
        if ($notifId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Notification ID is required.']);
            exit;
        }

        // Strictly check ownership: user_id = $userId
        $stmt = $conn->prepare("UPDATE notifications SET state = 'read', is_read = 1, read_at = NOW() WHERE notification_id = ? AND user_id = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $notifId, $userId);
            $stmt->execute();
            $stmt->close();
        }

        $unreadCount = getUnreadNotificationCount($conn, $userId);
        echo json_encode([
            'success' => true,
            'message' => 'Notification marked as read.',
            'unread_count' => $unreadCount
        ]);
        exit;
    }

    // Mark all notifications as read for current user
    if ($action === 'mark_all_read') {
        $stmt = $conn->prepare("UPDATE notifications SET state = 'read', is_read = 1, read_at = NOW() WHERE user_id = ? AND (state = 'unread' OR is_read = 0)");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }

        echo json_encode([
            'success' => true,
            'message' => 'All notifications marked as read.',
            'unread_count' => 0
        ]);
        exit;
    }

    // Dismiss / Archive notification
    if ($action === 'dismiss') {
        $notifId = (int)($data['notification_id'] ?? 0);
        if ($notifId > 0) {
            $stmt = $conn->prepare("UPDATE notifications SET state = 'archived', archived_at = NOW() WHERE notification_id = ? AND user_id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $notifId, $userId);
                $stmt->execute();
                $stmt->close();
            }
        }

        $unreadCount = getUnreadNotificationCount($conn, $userId);
        echo json_encode([
            'success' => true,
            'unread_count' => $unreadCount
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported action.']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
