<?php
/**
 * Notifications API Endpoint - Provides real-time unread counts, preference management, and state actions.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/NotificationService.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid user session.']);
    exit;
}

ensureEmailNotificationSchema($conn);
$notificationService = new NotificationService($conn);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'count';

    if ($action === 'count') {
        $unread_count = getUnreadNotificationCount($conn, $user_id);
        echo json_encode([
            'success' => true,
            'unread_count' => $unread_count
        ]);
        exit;
    }

    if ($action === 'list') {
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 10)));
        $notifications = [];
        $stmt = $conn->prepare("SELECT notification_id, notification_type, title, message, entity_type, entity_id, action_key, state, is_read, created_at FROM notifications WHERE user_id = ? AND state <> 'deleted' ORDER BY created_at DESC LIMIT ?");
        if ($stmt) {
            $stmt->bind_param('ii', $user_id, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $row['is_read'] = ($row['state'] === 'read' || (int) $row['is_read'] === 1) ? 1 : 0;
                $row['action_url'] = NotificationService::actionUrl($row['action_key'] ?? '');
                $row['time_ago'] = formatDateTime($row['created_at']);
                $notifications[] = $row;
            }
            $stmt->close();
        }

        $unread_count = getUnreadNotificationCount($conn, $user_id);
        echo json_encode([
            'success' => true,
            'unread_count' => $unread_count,
            'notifications' => $notifications
        ]);
        exit;
    }

    if ($action === 'preferences') {
        $categories = ['announcements', 'requests', 'schedules', 'system'];
        $preferences = [];
        $stmt = $conn->prepare("SELECT category, COALESCE(in_app_enabled, 1) AS in_app_enabled, COALESCE(email_enabled, 1) AS email_enabled, COALESCE(sms_enabled, 1) AS sms_enabled FROM notification_preferences WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $preferences[$r['category']] = [
                    'in_app' => (int) $r['in_app_enabled'],
                    'email' => (int) $r['email_enabled'],
                    'sms' => (int) $r['sms_enabled']
                ];
            }
            $stmt->close();
        }

        foreach ($categories as $cat) {
            if (!isset($preferences[$cat])) {
                $preferences[$cat] = ['in_app' => 1, 'email' => 1, 'sms' => 1];
            }
        }

        echo json_encode([
            'success' => true,
            'preferences' => $preferences
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown GET action.']);
    exit;
}

if ($method === 'POST') {
    $raw_input = file_get_contents('php://input');
    $json = json_decode($raw_input, true) ?? [];
    $data = !empty($json) ? $json : $_POST;

    $csrfName = function_exists('csrfTokenName') ? csrfTokenName() : '_csrf_token';
    $token = (string) ($data[$csrfName] ?? ($data['csrf_token'] ?? ($data['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))));
    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token.']);
        exit;
    }

    $action = $data['action'] ?? '';

    if ($action === 'mark_read') {
        $id = (int) ($data['notification_id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Notification ID required.']);
            exit;
        }

        try {
            $notificationService->transition($id, $user_id, 'read');
            $unread_count = getUnreadNotificationCount($conn, $user_id);
            echo json_encode([
                'success' => true,
                'message' => 'Notification marked as read.',
                'unread_count' => $unread_count
            ]);
            exit;
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'mark_all_read') {
        try {
            $notificationService->markAllRead($user_id);
            echo json_encode([
                'success' => true,
                'message' => 'All notifications marked as read.',
                'unread_count' => 0
            ]);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'save_preferences') {
        $categories = ['announcements', 'requests', 'schedules', 'system'];
        $prefs_input = $data['preferences'] ?? [];

        foreach ($categories as $cat) {
            $cat_data = $prefs_input[$cat] ?? [];
            $in_app = isset($cat_data['in_app']) ? ((int) $cat_data['in_app'] ? 1 : 0) : (isset($data['in_app_' . $cat]) ? 1 : (isset($data['has_form_submit']) ? 0 : 1));
            $email = isset($cat_data['email']) ? ((int) $cat_data['email'] ? 1 : 0) : (isset($data['email_' . $cat]) ? 1 : 0);
            $sms = isset($cat_data['sms']) ? ((int) $cat_data['sms'] ? 1 : 0) : (isset($data['sms_' . $cat]) ? 1 : 0);

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

        echo json_encode([
            'success' => true,
            'message' => 'Notification channel preferences saved successfully.'
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown POST action.']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
