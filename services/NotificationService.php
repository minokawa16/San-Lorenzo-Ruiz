<?php
/**
 * Notification Service - Centralized notification dispatch, state management, and channel routing.
 */
require_once dirname(__DIR__) . '/includes/helpers.php';

final class NotificationService
{
    private mysqli $db;

    private const ACTIONS = [
        'request.view' => 'my-requests.php',
        'reservation.view' => 'view-schedule.php',
        'announcement.view' => 'announcements.php',
        'certificate.view' => 'my-requests.php',
        'account.view' => 'notifications.php',
        'settings.view' => 'notifications.php?tab=preferences',
        'security.view' => 'profile.php'
    ];

    private const CATEGORIES = [
        // Requests & Certificates
        'request_submitted' => 'requests',
        'request_pending' => 'requests',
        'request_processing' => 'requests',
        'request_approved' => 'requests',
        'request_rejected' => 'requests',
        'request_completed' => 'requests',
        'request_cancelled' => 'requests',
        'request_needs_info' => 'requests',
        'certificate_ready' => 'requests',
        'certificate_released' => 'requests',
        'payment_submitted' => 'requests',
        'payment_verified' => 'requests',
        'payment_rejected' => 'requests',

        // Schedules & Reservations
        'reservation_created' => 'schedules',
        'reservation_pending' => 'schedules',
        'reservation_approved' => 'schedules',
        'reservation_rejected' => 'schedules',
        'reservation_rescheduled' => 'schedules',
        'reservation_cancelled' => 'schedules',
        'reservation_reminder' => 'schedules',
        'reservation_updated' => 'schedules',
        'schedule_proposal_created' => 'schedules',
        'schedule_proposal_response' => 'schedules',

        // Announcements & Broadcasts
        'announcement_published' => 'announcements',
        'broadcast_notice' => 'announcements',
        'announcement_reminder' => 'announcements',

        // System & Security Notices
        'account_verified' => 'system',
        'account_registered' => 'system',
        'registration_approved' => 'system',
        'registration_rejected' => 'system',
        'password_changed' => 'system',
        'security_notice' => 'system',
        'system' => 'system'
    ];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public static function actionUrl(?string $key, ?string $entityType = null, $entityId = null, ?array $notification = null, ?mysqli $conn = null): string
    {
        return self::resolveActionUrl($key, $entityType, $entityId, $notification, $conn) ?? '';
    }

    public static function resolveActionUrl(
        ?string $actionKey = null,
        ?string $entityType = null,
        $entityId = null,
        ?array $notification = null,
        ?mysqli $conn = null
    ): ?string {
        if ($notification !== null) {
            $actionKey = $actionKey ?: ($notification['action_key'] ?? null);
            $entityType = $entityType ?: ($notification['entity_type'] ?? null);
            $entityId = $entityId !== null ? $entityId : ($notification['entity_id'] ?? null);
        }

        $entityIdInt = intval($entityId ?? 0);
        $entityType = strtolower(trim((string) ($entityType ?? '')));
        $actionKey = trim((string) ($actionKey ?? ''));
        $title = (string) ($notification['title'] ?? '');
        $message = (string) ($notification['message'] ?? '');
        $notifType = strtolower(trim((string) ($notification['notification_type'] ?? '')));
        $userId = intval($notification['user_id'] ?? 0);

        // 1. Explicit entity type and entity ID
        if ($entityIdInt > 0) {
            if ($entityType === 'request' || $entityType === 'certificate') {
                return 'view-request.php?id=' . $entityIdInt;
            }
            if ($entityType === 'announcement') {
                return 'announcements.php?id=' . $entityIdInt . '#announcementModal-' . $entityIdInt;
            }
            if ($entityType === 'reservation') {
                if ($conn && $conn instanceof mysqli) {
                    $stmt = $conn->prepare("SELECT request_id FROM reservations WHERE reservation_id = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param('i', $entityIdInt);
                        $stmt->execute();
                        $r = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if (!empty($r['request_id'])) {
                            return 'view-request.php?id=' . (int) $r['request_id'];
                        }
                    }
                }
                return 'view-schedule.php?reservation_id=' . $entityIdInt;
            }
            if ($entityType === 'schedule') {
                return 'view-schedule.php?event_id=' . $entityIdInt;
            }
        }

        // 2. Scan text for reference numbers (TUGON-2026-XXXX, PRQ-XXXX, REQ-XXXX)
        $textToScan = $message . ' ' . $title;
        if (preg_match('/\b(TUGON-\d{4}-[A-Fa-f0-9]+|PRQ-\d{8}-\d+|REQ-[A-Za-z0-9-]+|TEST-REQ-[A-Za-z0-9-]+)\b/', $textToScan, $refMatches)) {
            $ref = $refMatches[1];
            if ($conn && $conn instanceof mysqli) {
                $stmt = $conn->prepare("SELECT request_id FROM requests WHERE reference_number = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $ref);
                    $stmt->execute();
                    $r = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!empty($r['request_id'])) {
                        return 'view-request.php?id=' . (int) $r['request_id'];
                    }
                }
            }
            return 'view-request.php?ref=' . urlencode($ref);
        }

        // 3. Announcements matching by type, actionKey, or title
        if ($entityType === 'announcement' || $actionKey === 'announcement.view' || $notifType === 'announcement_published' || $notifType === 'broadcast_notice' || stripos($title, 'announcement') !== false) {
            if ($conn && $conn instanceof mysqli && $title !== '') {
                $candidateTitle = (strcasecmp($title, 'New Parish Announcement') === 0) ? trim($message) : $title;
                $firstLine = trim((string) strtok($candidateTitle, "\n\r"));
                if ($firstLine !== '') {
                    $stmt = $conn->prepare("SELECT announcement_id FROM announcements WHERE title = ? OR title LIKE ? LIMIT 1");
                    if ($stmt) {
                        $likeParam = '%' . $firstLine . '%';
                        $stmt->bind_param('ss', $firstLine, $likeParam);
                        $stmt->execute();
                        $r = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if (!empty($r['announcement_id'])) {
                            return 'announcements.php?id=' . (int) $r['announcement_id'] . '#announcementModal-' . (int) $r['announcement_id'];
                        }
                    }
                }
            }
            return 'announcements.php';
        }

        // 4. Request / Sacramental matching
        if ($entityType === 'request' || $actionKey === 'request.view' || $notifType === 'request' || str_starts_with($notifType, 'request_') || stripos($title, 'Request') !== false) {
            if ($conn && $conn instanceof mysqli && $userId > 0) {
                $serviceKeywords = ['baptism', 'marriage', 'funeral', 'blessing', 'communion', 'confirmation'];
                $matchedKw = null;
                foreach ($serviceKeywords as $kw) {
                    if (stripos($title . ' ' . $message, $kw) !== false) {
                        $matchedKw = $kw;
                        break;
                    }
                }
                if ($matchedKw) {
                    $stmt = $conn->prepare("SELECT request_id FROM requests WHERE user_id = ? AND request_type LIKE ? ORDER BY request_id DESC LIMIT 1");
                    if ($stmt) {
                        $kwParam = '%' . $matchedKw . '%';
                        $stmt->bind_param('is', $userId, $kwParam);
                        $stmt->execute();
                        $r = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if (!empty($r['request_id'])) {
                            return 'view-request.php?id=' . (int) $r['request_id'];
                        }
                    }
                }
                $stmt = $conn->prepare("SELECT request_id FROM requests WHERE user_id = ? ORDER BY request_id DESC LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $r = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!empty($r['request_id'])) {
                        return 'view-request.php?id=' . (int) $r['request_id'];
                    }
                }
            }
            return 'my-requests.php';
        }

        // 5. Reservation / Schedule matching
        if ($entityType === 'reservation' || $actionKey === 'reservation.view' || str_starts_with($notifType, 'reservation_') || str_starts_with($notifType, 'schedule_') || stripos($title, 'Reservation') !== false || stripos($title, 'Schedule') !== false) {
            return 'view-schedule.php';
        }

        // 6. Security / Account matching
        if ($actionKey === 'security.view' || stripos($title, 'Password') !== false) {
            return 'profile.php';
        }
        if ($actionKey === 'settings.view') {
            return 'notifications.php?tab=preferences';
        }

        // 7. Static actions fallback if registered (except dashboard)
        if ($actionKey !== '' && isset(self::ACTIONS[$actionKey])) {
            return self::ACTIONS[$actionKey];
        }

        // Truly unresolvable notification -> return null so caller can hide View button
        return null;
    }

    public function create(
        int $userId,
        string $type,
        array $variables = [],
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $actionKey = null,
        ?string $dedupeSeed = null,
        bool $outbound = true
    ): ?int {
        if ($userId <= 0) {
            throw new DomainException('Notification recipient is required.');
        }

        $stmt = $this->db->prepare("SELECT * FROM notification_templates WHERE notification_type = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param('s', $type);
        $stmt->execute();
        $tpl = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $category = self::CATEGORIES[$type] ?? 'system';
        $isAnnouncement = ($category === 'announcements' || $type === 'announcement_published' || $type === 'broadcast_notice');

        if ($isAnnouncement) {
            // For announcements, preserve the exact announcement body word-for-word
            $rawMsg = trim((string) ($variables['message'] ?? $variables['content'] ?? ''));
            if ($rawMsg !== '') {
                $message = $rawMsg;
            } elseif ($tpl) {
                $message = $this->render($tpl['in_app_template'], $variables);
            } else {
                $message = $variables['message'] ?? '';
            }
            $rawTitle = trim((string) ($variables['title'] ?? $variables['announcement_title'] ?? ''));
            $title = $rawTitle !== '' ? $rawTitle : 'Parish Announcement';
        } else {
            if (!$tpl) {
                $title = $variables['title'] ?? ucfirst(str_replace('_', ' ', $type));
                $message = $variables['message'] ?? 'You have a new update regarding ' . str_replace('_', ' ', $type) . '.';
            } else {
                $title = $this->render($tpl['title_template'], $variables);
                $message = $this->render($tpl['in_app_template'], $variables);
            }
        }
        $dedupe = $dedupeSeed !== null ? hash('sha256', $type . '|' . $entityType . '|' . $entityId . '|' . $dedupeSeed) : null;

        // Verify in-app allowance
        $allowInApp = $this->allows($userId, $category, 'in_app');
        $id = null;

        if ($allowInApp) {
            $stmt = $this->db->prepare("INSERT IGNORE INTO notifications (user_id, notification_type, title, message, entity_type, entity_id, action_key, state, deduplication_key, is_read) VALUES (?, ?, ?, ?, ?, ?, ?, 'unread', ?, 0)");
            $stmt->bind_param('issssiss', $userId, $type, $title, $message, $entityType, $entityId, $actionKey, $dedupe);
            $stmt->execute();
            $id = (int) $stmt->insert_id;
            $stmt->close();
        }

        // Outbound channels (Email & SMS)
        if ($outbound) {
            $channels = [
                'email' => $this->allows($userId, $category, 'email'),
                'sms' => $this->allows($userId, $category, 'sms')
            ];

            if ($channels['email'] || $channels['sms']) {
                if (function_exists('dispatchNotificationDelivery')) {
                    dispatchNotificationDelivery($this->db, $userId, $title, $message, $category, $channels);
                }
            }
        }

        // Log notification deliveries if table exists and notification was saved
        if ($id > 0 && function_exists('tableExists') && tableExists($this->db, 'notification_deliveries')) {
            foreach (['in_app', 'email', 'sms'] as $channel) {
                $allowed = $channel === 'in_app' ? $allowInApp : ($outbound && $this->allows($userId, $category, $channel));
                $status = $allowed ? ($channel === 'in_app' ? 'sent' : 'pending') : 'cancelled';
                $idempotency = hash('sha256', $id . '|' . $channel . '|' . $userId . '|1');
                $stmt = $this->db->prepare("INSERT INTO notification_deliveries (notification_id, channel, idempotency_key, status, attempt_count, sent_at, next_attempt_at) VALUES (?, ?, ?, ?, 0, IF(?='sent', NOW(), NULL), IF(?='pending', NOW(), NULL))");
                if ($stmt) {
                    $stmt->bind_param('isssss', $id, $channel, $idempotency, $status, $status, $status);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        return $id > 0 ? $id : null;
    }

    public function createLegacy(
        int $userId,
        string $title,
        string $message,
        bool $outbound = true,
        string $category = 'system',
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $actionKey = null
    ): ?int {
        if ($userId <= 0) {
            return null;
        }

        $message = trim($message);
        if ($message === '') {
            return null;
        }

        $isAnnouncement = in_array(strtolower($category), ['announcements', 'announcement', 'broadcast', 'broadcast_notice'], true);
        if ($isAnnouncement && trim($title) === '') {
            $title = 'Parish Announcement';
        }

        // Auto-detect entityType, entityId, actionKey if not explicitly provided
        if ($entityType === null && $entityId === null && $actionKey === null) {
            $textToScan = $message . ' ' . $title;
            if (preg_match('/\b(TUGON-\d{4}-[A-Fa-f0-9]+|PRQ-\d{8}-\d+|REQ-[A-Za-z0-9-]+|TEST-REQ-[A-Za-z0-9-]+)\b/', $textToScan, $m)) {
                $ref = $m[1];
                $stmt = $this->db->prepare("SELECT request_id FROM requests WHERE reference_number = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('s', $ref);
                    $stmt->execute();
                    $r = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!empty($r['request_id'])) {
                        $entityType = 'request';
                        $entityId = (int) $r['request_id'];
                        $actionKey = 'request.view';
                    }
                }
            } elseif ($isAnnouncement) {
                $entityType = 'announcement';
                $actionKey = 'announcement.view';
            } elseif (in_array(strtolower($category), ['schedules', 'reservation', 'reservations'], true)) {
                $entityType = 'reservation';
                $actionKey = 'reservation.view';
            }
        }

        $allowInApp = $this->allows($userId, $category, 'in_app');
        $id = null;

        if ($allowInApp) {
            $notifType = $isAnnouncement ? 'announcement_published' : ($entityType === 'request' ? 'request' : 'system');
            $stmt = $this->db->prepare("INSERT INTO notifications (user_id, notification_type, title, message, entity_type, entity_id, action_key, state, is_read) VALUES (?, ?, ?, ?, ?, ?, ?, 'unread', 0)");
            if ($stmt) {
                $stmt->bind_param('issssis', $userId, $notifType, $title, $message, $entityType, $entityId, $actionKey);
                $stmt->execute();
                $id = (int) $stmt->insert_id;
                $stmt->close();
            }
        }

        if ($outbound) {
            $channels = [
                'email' => $this->allows($userId, $category, 'email'),
                'sms' => $this->allows($userId, $category, 'sms')
            ];

            if ($channels['email'] || $channels['sms']) {
                if (function_exists('dispatchNotificationDelivery')) {
                    dispatchNotificationDelivery($this->db, $userId, $title, $message, $category, $channels);
                }
            }
        }

        return $id;
    }

    public function transition(int $id, int $userId, string $state): void
    {
        if (!in_array($state, ['read', 'unread', 'archived', 'deleted'], true)) {
            throw new DomainException('Invalid notification state.');
        }
        $read = in_array($state, ['read', 'archived'], true) ? 1 : 0;
        $readAtSql = $state === 'read' ? 'read_at = NOW(), ' : ($state === 'unread' ? 'read_at = NULL, ' : '');
        $archivedAtSql = $state === 'archived' ? 'archived_at = NOW(), ' : '';
        $deletedAtSql = $state === 'deleted' ? 'deleted_at = NOW(), ' : '';

        $stmt = $this->db->prepare("UPDATE notifications SET {$readAtSql}{$archivedAtSql}{$deletedAtSql}state = ?, is_read = ? WHERE notification_id = ? AND user_id = ? AND state <> 'deleted'");
        $stmt->bind_param('siii', $state, $read, $id, $userId);
        $stmt->execute();
        $stmt->close();
    }

    public function markAllRead(int $userId): void
    {
        $stmt = $this->db->prepare("UPDATE notifications SET state = 'read', is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE user_id = ? AND (state = 'unread' OR is_read = 0)");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    public function retry(int $deliveryId): void
    {
        $stmt = $this->db->prepare("SELECT d.*, n.user_id, n.notification_type, n.title, n.message FROM notification_deliveries d JOIN notifications n ON n.notification_id = d.notification_id WHERE d.delivery_id = ? AND d.status IN ('failed', 'pending') AND d.attempt_count < 5");
        $stmt->bind_param('i', $deliveryId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new DomainException('No retryable delivery was found.');
        }

        $channel = $row['channel'];
        if ($channel === 'in_app') {
            $status = 'sent';
            $failure = null;
        } else {
            $category = self::CATEGORIES[$row['notification_type']] ?? 'system';
            $channels = ['email' => false, 'sms' => false];
            $channels[$channel] = true;
            $result = dispatchNotificationDelivery($this->db, (int) $row['user_id'], $row['title'], $row['message'], $category, $channels);
            $attempt = $result[$channel] ?? [];
            $status = !empty($attempt['ok']) && !empty($attempt['skipped']) ? 'cancelled' : (!empty($attempt['ok']) ? 'sent' : 'failed');
            $failure = $attempt['error'] ?? null;
        }

        $delay = min(3600, 60 * (2 ** (int) $row['attempt_count']));
        $stmt = $this->db->prepare("UPDATE notification_deliveries SET status = ?, attempt_count = attempt_count + 1, last_attempt_at = NOW(), next_attempt_at = IF(?='failed', DATE_ADD(NOW(), INTERVAL ? SECOND), NULL), sent_at = IF(?='sent', NOW(), sent_at), failed_at = IF(?='failed', NOW(), failed_at), failure_reason = ? WHERE delivery_id = ?");
        $stmt->bind_param('ssisssi', $status, $status, $delay, $status, $status, $failure, $deliveryId);
        $stmt->execute();
        $stmt->close();
    }

    public function allows(int $user, string $category, string $channel): bool
    {
        $column = $channel === 'sms' ? 'sms_enabled' : ($channel === 'in_app' ? 'in_app_enabled' : 'email_enabled');
        if (function_exists('columnExists') && !columnExists($this->db, 'notification_preferences', $column)) {
            return true;
        }
        $stmt = $this->db->prepare("SELECT `$column` AS enabled FROM notification_preferences WHERE user_id = ? AND category = ? LIMIT 1");
        if (!$stmt) {
            return true;
        }
        $stmt->bind_param('is', $user, $category);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return !$row || (int) $row['enabled'] === 1;
    }

    private function render(string $template, array $vars): string
    {
        return preg_replace_callback('/{{([a-zA-Z0-9_]+)}}/', function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) ? trim(strip_tags((string) $vars[$m[1]])) : '—';
        }, $template);
    }
}
