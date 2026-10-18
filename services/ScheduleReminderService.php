<?php
/**
 * ScheduleReminderService
 * -----------------------
 * Automated notification and reminder engine for Parish Secretaries and Administrators.
 * 
 * Features:
 * 1. Automatic threshold reminders (3 days, 2 days, 1 day / Tomorrow, and 0 days / Today)
 * 2. Deduplication & Idempotency via reminder_log table (schedule_id + days_before)
 * 3. Single-reminder per schedule: older reminders are superseded/archived as schedule approaches
 * 4. Reschedule / Cancellation management: removes pending reminders & notifies secretary
 * 5. Other updates: new requests, cancellations, stale requests (>3 days), booking conflicts
 * 6. Multi-channel delivery: in-app, email, and SMS (with try/catch so delivery failures never block)
 * 7. Configurable send time (7, 8, 9 AM) and secretary preferences
 * 8. Simulator utilities for demoing threshold shifts and new request simulations
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/textbee.php';

final class ScheduleReminderService
{
    public const THRESHOLDS = [3, 2, 1, 0];

    /**
     * Get notification settings for a user or global parish defaults.
     */
    public static function getSettings(mysqli $conn, ?int $userId = null): array
    {
        $defaults = [
            'remind_3_days' => 1,
            'remind_2_days' => 1,
            'remind_1_day' => 1,
            'remind_0_day' => 1,
            'send_time' => '08:00',
            'channel_in_app' => 1,
            'channel_email' => 1,
            'channel_sms' => 0,
            'stale_days_threshold' => 3,
            'notify_new_request' => 1,
            'notify_cancelled_request' => 1,
            'notify_conflicts' => 1,
            'notify_stale_requests' => 1
        ];

        if (!function_exists('tableExists') || !tableExists($conn, 'notification_settings')) {
            return $defaults;
        }

        // Try user-specific first if provided
        if ($userId !== null && $userId > 0) {
            $stmt = $conn->prepare("SELECT * FROM notification_settings WHERE user_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    return array_merge($defaults, $row);
                }
            }
        }

        // Fallback to global defaults (user_id IS NULL)
        $res = $conn->query("SELECT * FROM notification_settings WHERE user_id IS NULL LIMIT 1");
        if ($res && ($row = $res->fetch_assoc())) {
            return array_merge($defaults, $row);
        }

        return $defaults;
    }

    /**
     * Save notification settings for a user or global parish defaults.
     */
    public static function saveSettings(mysqli $conn, array $data, ?int $userId = null): bool
    {
        if (!function_exists('tableExists') || !tableExists($conn, 'notification_settings')) {
            return false;
        }

        $remind3 = !empty($data['remind_3_days']) ? 1 : 0;
        $remind2 = !empty($data['remind_2_days']) ? 1 : 0;
        $remind1 = !empty($data['remind_1_day']) ? 1 : 0;
        $remind0 = !empty($data['remind_0_day']) ? 1 : 0;
        $sendTime = in_array($data['send_time'] ?? '', ['07:00', '08:00', '09:00'], true) ? $data['send_time'] : '08:00';
        $inApp = !empty($data['channel_in_app']) ? 1 : 0;
        $email = !empty($data['channel_email']) ? 1 : 0;
        $sms = (!empty($data['channel_sms']) && isTextBeeConfigured()) ? 1 : 0;
        $staleDays = max(1, min(30, (int)($data['stale_days_threshold'] ?? 3)));
        $newReq = !empty($data['notify_new_request']) ? 1 : 0;
        $cancelReq = !empty($data['notify_cancelled_request']) ? 1 : 0;
        $conflicts = !empty($data['notify_conflicts']) ? 1 : 0;
        $staleReq = !empty($data['notify_stale_requests']) ? 1 : 0;

        if ($userId !== null && $userId > 0) {
            $stmt = $conn->prepare("
                INSERT INTO notification_settings (
                    user_id, remind_3_days, remind_2_days, remind_1_day, remind_0_day,
                    send_time, channel_in_app, channel_email, channel_sms,
                    stale_days_threshold, notify_new_request, notify_cancelled_request,
                    notify_conflicts, notify_stale_requests
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    remind_3_days = VALUES(remind_3_days),
                    remind_2_days = VALUES(remind_2_days),
                    remind_1_day = VALUES(remind_1_day),
                    remind_0_day = VALUES(remind_0_day),
                    send_time = VALUES(send_time),
                    channel_in_app = VALUES(channel_in_app),
                    channel_email = VALUES(channel_email),
                    channel_sms = VALUES(channel_sms),
                    stale_days_threshold = VALUES(stale_days_threshold),
                    notify_new_request = VALUES(notify_new_request),
                    notify_cancelled_request = VALUES(notify_cancelled_request),
                    notify_conflicts = VALUES(notify_conflicts),
                    notify_stale_requests = VALUES(notify_stale_requests)
            ");
            if (!$stmt) return false;
            $stmt->bind_param(
                'iiiiisiiiiiiii',
                $userId, $remind3, $remind2, $remind1, $remind0,
                $sendTime, $inApp, $email, $sms,
                $staleDays, $newReq, $cancelReq, $conflicts, $staleReq
            );
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        // Global row
        $stmt = $conn->prepare("
            INSERT INTO notification_settings (
                user_id, remind_3_days, remind_2_days, remind_1_day, remind_0_day,
                send_time, channel_in_app, channel_email, channel_sms,
                stale_days_threshold, notify_new_request, notify_cancelled_request,
                notify_conflicts, notify_stale_requests
            ) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                remind_3_days = VALUES(remind_3_days),
                remind_2_days = VALUES(remind_2_days),
                remind_1_day = VALUES(remind_1_day),
                remind_0_day = VALUES(remind_0_day),
                send_time = VALUES(send_time),
                channel_in_app = VALUES(channel_in_app),
                channel_email = VALUES(channel_email),
                channel_sms = VALUES(channel_sms),
                stale_days_threshold = VALUES(stale_days_threshold),
                notify_new_request = VALUES(notify_new_request),
                notify_cancelled_request = VALUES(notify_cancelled_request),
                notify_conflicts = VALUES(notify_conflicts),
                notify_stale_requests = VALUES(notify_stale_requests)
        ");
        if (!$stmt) return false;
        $stmt->bind_param(
            'iiiisiiiiiiii',
            $remind3, $remind2, $remind1, $remind0,
            $sendTime, $inApp, $email, $sms,
            $staleDays, $newReq, $cancelReq, $conflicts, $staleReq
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Resolve all active staff users with Secretary role (and Administrator role).
     */
    public static function resolveStaffRecipients(mysqli $conn): array
    {
        $sql = "
            SELECT DISTINCT u.id, u.fullname, u.email, u.phone_number, u.role
            FROM users u
            WHERE u.status = 'active'
              AND (
                  u.role IN ('admin', 'administrator', 'secretary')
                  OR u.id IN (
                      SELECT ur.user_id
                      FROM user_roles ur
                      JOIN roles r ON r.role_id = ur.role_id
                      WHERE r.role_key IN ('administrator', 'secretary', 'parish_staff')
                  )
              )
            ORDER BY u.id ASC
        ";

        $res = $conn->query($sql);
        $recipients = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $recipients[] = $row;
            }
        }
        return $recipients;
    }

    /**
     * Format service label from title/category.
     */
    public static function formatServiceLabel(string $title, ?string $category = null): string
    {
        $title = trim($title);
        if ($title === '') {
            return !empty($category) ? ucwords(str_replace(['_', '-'], ' ', $category)) : 'Parish Service';
        }

        // Clean up prefixes like "Wedding Reservation - " or "Baptism - "
        if (preg_match('/^(.*?)\s*[-–—:]\s*(.*)$/u', $title, $m)) {
            $candidate = trim($m[1]);
            if (strlen($candidate) >= 3 && !preg_match('/^[A-Z0-9_-]+$/', $candidate)) {
                return ucwords($candidate);
            }
        }
        return $title;
    }

    /**
     * Format requester name from schedule event or linked request.
     */
    public static function extractRequesterName(mysqli $conn, array $event): string
    {
        // 1. If source_type is request and source_id is set
        if (!empty($event['source_type']) && $event['source_type'] === 'request' && !empty($event['source_id'])) {
            $reqId = (int)$event['source_id'];
            $stmt = $conn->prepare("
                SELECT r.record_holder_name, u.fullname 
                FROM requests r 
                LEFT JOIN users u ON r.user_id = u.id 
                WHERE r.request_id = ? LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $reqId);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($r) {
                    if (!empty($r['record_holder_name'])) return trim($r['record_holder_name']);
                    if (!empty($r['fullname'])) return trim($r['fullname']);
                }
            }
        }

        // 2. If source_type is reservation
        if (!empty($event['source_type']) && $event['source_type'] === 'reservation' && !empty($event['source_id'])) {
            $resId = (int)$event['source_id'];
            $stmt = $conn->prepare("
                SELECT res.reserved_by_name, res.contact_person, u.fullname 
                FROM reservations res 
                LEFT JOIN users u ON res.user_id = u.id 
                WHERE res.reservation_id = ? LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param('i', $resId);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($r) {
                    if (!empty($r['reserved_by_name'])) return trim($r['reserved_by_name']);
                    if (!empty($r['contact_person'])) return trim($r['contact_person']);
                    if (!empty($r['fullname'])) return trim($r['fullname']);
                }
            }
        }

        // 3. Fallback: Parse from title if formatted like "Service - Person Name"
        $title = trim((string)($event['title'] ?? ''));
        if (preg_match('/[-–—:]\s*([A-Za-z\s\.\,\'\-]+)$/u', $title, $m)) {
            $cand = trim($m[1]);
            if (strlen($cand) > 2) return ucwords(strtolower($cand));
        }

        // 4. Default to Parishioner
        return 'Parishioner';
    }

    /**
     * Main Scheduler Engine:
     * Evaluates all upcoming schedules against reminder thresholds (3, 2, 1, 0 days away).
     * 
     * @param mysqli $conn
     * @param string|null $referenceDate e.g. '2026-10-19' or null for current date
     * @param bool $force Force dispatch even if reminder_log entry exists
     * @return array Summary of processed and sent reminders
     */
    public static function processReminders(mysqli $conn, ?string $referenceDate = null, bool $force = false): array
    {
        $refDateStr = $referenceDate ?: date('Y-m-d');
        $refDate = new DateTime($refDateStr);
        $settings = self::getSettings($conn);
        $recipients = self::resolveStaffRecipients($conn);

        $results = [
            'reference_date' => $refDateStr,
            'checked_schedules' => 0,
            'reminders_sent' => 0,
            'superseded_reminders' => 0,
            'errors' => [],
            'details' => []
        ];

        if (empty($recipients)) {
            $results['errors'][] = 'No active staff or secretary recipients found.';
            return $results;
        }

        // Find approved/confirmed schedules from referenceDate to referenceDate + 3 days
        $endDate = (clone $refDate)->modify('+4 days')->format('Y-m-d');
        $startDate = $refDate->format('Y-m-d');

        $sql = "
            SELECT se.* 
            FROM schedule_events se
            WHERE se.event_date >= ? AND se.event_date <= ?
              AND (se.approval_status = 'approved' OR se.approval_status IS NULL)
              AND (se.status IS NULL OR se.status NOT IN ('cancelled', 'rejected'))
            ORDER BY se.event_date ASC, se.start_time ASC
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $results['errors'][] = "Query prepare error: " . $conn->error;
            return $results;
        }

        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $results['checked_schedules'] = count($schedules);

        foreach ($schedules as $sched) {
            $schedId = (int)$sched['schedule_id'];
            $eventDate = new DateTime($sched['event_date']);
            
            // Calculate exact whole days difference
            $diffDays = (int)$refDate->diff($eventDate)->format('%r%a');

            // Only process thresholds 0, 1, 2, 3
            if ($diffDays < 0 || $diffDays > 3) {
                continue;
            }

            // Check if this specific threshold is enabled in settings
            $thresholdEnabled = match ($diffDays) {
                3 => !empty($settings['remind_3_days']),
                2 => !empty($settings['remind_2_days']),
                1 => !empty($settings['remind_1_day']),
                0 => !empty($settings['remind_0_day']),
                default => false
            };

            if (!$thresholdEnabled) {
                continue;
            }

            // Idempotency: Check reminder_log for (schedule_id, days_before)
            if (!$force && self::hasReminderLogged($conn, $schedId, $diffDays)) {
                continue;
            }

            // Build reminder contents
            $prefix = match ($diffDays) {
                3 => 'In 3 days',
                2 => 'In 2 days',
                1 => 'Tomorrow',
                0 => 'Today'
            };

            $serviceName = self::formatServiceLabel($sched['title'], $sched['category'] ?? null);
            $requesterName = self::extractRequesterName($conn, $sched);
            $timeFormatted = !empty($sched['start_time']) ? date('g:i A', strtotime($sched['start_time'])) : 'Scheduled Time';
            $dateFormatted = date('M d, Y', strtotime($sched['event_date']));

            // Format: "Tomorrow: Confirmation Mass"
            $notifTitle = "{$prefix}: {$serviceName}";
            // Format: "4:00 PM · Bea Mendoza"
            $notifMessage = "{$timeFormatted} · {$requesterName} ({$dateFormatted})";

            // Resolve linked request id if available
            $linkedRequestId = null;
            if (!empty($sched['source_type']) && $sched['source_type'] === 'request' && !empty($sched['source_id'])) {
                $linkedRequestId = (int)$sched['source_id'];
            }

            $actionKey = 'schedule.view';
            $entityType = 'schedule';
            $entityId = $schedId;

            // 1. Supersede / Archive older reminders for this schedule so secretary sees only one reminder per schedule!
            // E.g. when 2-day reminder arrives, replace 3-day reminder; when Tomorrow arrives, replace 2-day reminder.
            $supersededCount = self::supersedeOlderReminders($conn, $schedId, $diffDays);
            $results['superseded_reminders'] += $supersededCount;

            // 2. Dispatch to each Secretary / Admin recipient
            $sentAny = false;
            foreach ($recipients as $recipient) {
                $recipientId = (int)$recipient['id'];
                
                // In-app notification
                if (!empty($settings['channel_in_app'])) {
                    $inAppOk = self::insertNotification(
                        $conn,
                        $recipientId,
                        'schedule_reminder',
                        $notifTitle,
                        $notifMessage,
                        $entityType,
                        $entityId,
                        $actionKey,
                        $linkedRequestId,
                        $schedId,
                        $diffDays
                    );
                    if ($inAppOk) {
                        $sentAny = true;
                    }
                }

                // Email notification (wrapped in try/catch so failure never blocks)
                if (!empty($settings['channel_email']) && !empty($recipient['email']) && isValidEmail($recipient['email'])) {
                    if (!defined('TEST_RUNNING') && !str_ends_with(strtolower($recipient['email']), '@example.com')) {
                        try {
                            $emailSubject = "[Parish Reminder] {$notifTitle} — {$timeFormatted}";
                            $emailHtml = self::buildReminderEmailHtml(
                                $prefix,
                                $serviceName,
                                $timeFormatted,
                                $dateFormatted,
                                $requesterName,
                                $sched['location'] ?? 'Main Church',
                                $linkedRequestId,
                                $diffDays
                            );
                            sendTugonEmail($conn, $recipient['email'], $emailSubject, $emailHtml, '', $recipientId, 'schedule_reminder');
                        } catch (Throwable $e) {
                            $results['errors'][] = "Email to {$recipient['email']} failed: " . $e->getMessage();
                            error_log("[ScheduleReminderService] Email failure: " . $e->getMessage());
                        }
                    }
                }

                // SMS notification (only if provider configured and channel enabled)
                if (!empty($settings['channel_sms']) && isTextBeeConfigured() && !empty($recipient['phone_number'])) {
                    try {
                        $smsBody = "Parish Alert: {$notifTitle} at {$timeFormatted} ({$requesterName}).";
                        if (function_exists('sendSMS')) {
                            sendSMS($recipient['phone_number'], $smsBody);
                        }
                    } catch (Throwable $e) {
                        $results['errors'][] = "SMS to {$recipient['phone_number']} failed: " . $e->getMessage();
                        error_log("[ScheduleReminderService] SMS failure: " . $e->getMessage());
                    }
                }
            }

            // 3. Log to reminder_log table (schedule_id, days_before, sent_at)
            $logOk = self::logReminderSent($conn, $schedId, $diffDays, 'in-app', 'sent');
            if ($logOk) {
                $results['reminders_sent']++;
                $results['details'][] = [
                    'schedule_id' => $schedId,
                    'title' => $notifTitle,
                    'days_before' => $diffDays,
                    'requester' => $requesterName
                ];
            }
        }

        return $results;
    }

    /**
     * Checks if a reminder was already recorded in reminder_log for this schedule and threshold.
     */
    public static function hasReminderLogged(mysqli $conn, int $scheduleId, int $daysBefore): bool
    {
        if (!function_exists('tableExists') || !tableExists($conn, 'reminder_log')) {
            return false;
        }

        $stmt = $conn->prepare("SELECT id FROM reminder_log WHERE schedule_id = ? AND days_before = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('ii', $scheduleId, $daysBefore);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return !empty($row);
    }

    /**
     * Records sent reminder into reminder_log.
     */
    public static function logReminderSent(
        mysqli $conn, 
        int $scheduleId, 
        int $daysBefore, 
        string $channel = 'in-app', 
        string $status = 'sent',
        ?string $error = null
    ): bool {
        if (!function_exists('tableExists') || !tableExists($conn, 'reminder_log')) {
            return false;
        }

        $stmt = $conn->prepare("
            INSERT INTO reminder_log (schedule_id, days_before, sent_at, channel, status, error_message)
            VALUES (?, ?, NOW(), ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                sent_at = NOW(), 
                status = VALUES(status), 
                error_message = VALUES(error_message)
        ");
        if (!$stmt) return false;
        $stmt->bind_param('iisss', $scheduleId, $daysBefore, $channel, $status, $error);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Archives older threshold reminders for this schedule so the secretary sees one current reminder per schedule.
     */
    public static function supersedeOlderReminders(mysqli $conn, int $scheduleId, int $currentDaysBefore): int
    {
        // Archive notifications for this schedule where days_before > currentDaysBefore (e.g. 3-day reminder superseded by 2-day reminder)
        // or any older schedule_reminder for this schedule
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET state = 'archived', archived_at = NOW() 
            WHERE schedule_id = ? 
              AND notification_type = 'schedule_reminder' 
              AND state <> 'deleted'
              AND (days_before > ? OR days_before IS NULL)
        ");
        if (!$stmt) return 0;
        $stmt->bind_param('ii', $scheduleId, $currentDaysBefore);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return max(0, $affected);
    }

    /**
     * Insert in-app notification row.
     */
    public static function insertNotification(
        mysqli $conn,
        int $userId,
        string $type,
        string $title,
        string $message,
        string $entityType,
        int $entityId,
        string $actionKey,
        ?int $relatedRequestId = null,
        ?int $scheduleId = null,
        ?int $daysBefore = null
    ): bool {
        $dedupeKey = hash('sha256', "sched_remind|{$userId}|{$scheduleId}|{$daysBefore}|" . date('Y-m-d'));

        // Check deduplication key
        $chk = $conn->prepare("SELECT notification_id FROM notifications WHERE user_id = ? AND deduplication_key = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param('is', $userId, $dedupeKey);
            $chk->execute();
            $exists = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($exists) {
                return false;
            }
        }

        $stmt = $conn->prepare("
            INSERT INTO notifications (
                user_id, notification_type, title, message, entity_type, entity_id,
                action_key, state, deduplication_key, is_read, related_request_id,
                schedule_id, days_before, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'unread', ?, 0, ?, ?, ?, NOW())
        ");

        if (!$stmt) return false;
        $stmt->bind_param(
            'issssissiii',
            $userId, $type, $title, $message, $entityType, $entityId,
            $actionKey, $dedupeKey, $relatedRequestId, $scheduleId, $daysBefore
        );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Handles schedule changes (reschedule or cancellation).
     * Removes or archives pending reminders, resets reminder_log if rescheduled, and notifies secretary.
     */
    public static function handleScheduleChanged(
        mysqli $conn, 
        int $scheduleId, 
        string $changeType, 
        array $details = []
    ): void {
        // Fetch event info
        $stmt = $conn->prepare("SELECT * FROM schedule_events WHERE schedule_id = ? LIMIT 1");
        if (!$stmt) return;
        $stmt->bind_param('i', $scheduleId);
        $stmt->execute();
        $sched = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sched) return;

        $serviceName = self::formatServiceLabel($sched['title'], $sched['category'] ?? null);
        $recipients = self::resolveStaffRecipients($conn);
        $settings = self::getSettings($conn);

        if ($changeType === 'cancelled') {
            // 1. Archive active notifications for this schedule
            $conn->query("UPDATE notifications SET state = 'archived', archived_at = NOW() WHERE schedule_id = {$scheduleId}");

            // 2. Notify secretary of cancellation if enabled
            if (!empty($settings['notify_cancelled_request'])) {
                $title = "Schedule Cancelled: {$serviceName}";
                $reason = !empty($details['reason']) ? " Reason: {$details['reason']}" : "";
                $dateStr = date('M d, Y', strtotime($sched['event_date']));
                $timeStr = !empty($sched['start_time']) ? date('g:i A', strtotime($sched['start_time'])) : '';
                $message = "The scheduled {$serviceName} on {$dateStr} {$timeStr} has been cancelled.{$reason}";

                foreach ($recipients as $recip) {
                    self::insertNotification(
                        $conn,
                        (int)$recip['id'],
                        'schedule_cancelled',
                        $title,
                        $message,
                        'schedule',
                        $scheduleId,
                        'schedule.view',
                        $sched['source_type'] === 'request' ? (int)$sched['source_id'] : null,
                        $scheduleId,
                        null
                    );
                }
            }
        } elseif ($changeType === 'rescheduled') {
            // 1. Archive previous notifications for this schedule
            $conn->query("UPDATE notifications SET state = 'archived', archived_at = NOW() WHERE schedule_id = {$scheduleId}");

            // 2. Clear old reminder_log rows for this schedule so new thresholds fire for the new date!
            $conn->query("DELETE FROM reminder_log WHERE schedule_id = {$scheduleId}");

            // If new_date is provided, update schedule_events record
            if (!empty($details['new_date'])) {
                $nTime = !empty($details['new_time']) ? $details['new_time'] : null;
                $stmtUpd = $conn->prepare("UPDATE schedule_events SET event_date = ?, start_time = COALESCE(?, start_time) WHERE schedule_id = ?");
                if ($stmtUpd) {
                    $stmtUpd->bind_param('ssi', $details['new_date'], $nTime, $scheduleId);
                    $stmtUpd->execute();
                    $stmtUpd->close();
                }
            }

            // 3. Notify secretary of reschedule
            $newDate = !empty($details['new_date']) ? date('M d, Y', strtotime($details['new_date'])) : date('M d, Y', strtotime($sched['event_date']));
            $newTime = !empty($details['new_time']) ? date('g:i A', strtotime($details['new_time'])) : (!empty($sched['start_time']) ? date('g:i A', strtotime($sched['start_time'])) : '');
            
            $title = "Schedule Rescheduled: {$serviceName}";
            $message = "The schedule for {$serviceName} was moved to {$newDate} at {$newTime}.";

            foreach ($recipients as $recip) {
                self::insertNotification(
                    $conn,
                    (int)$recip['id'],
                    'schedule_rescheduled',
                    $title,
                    $message,
                    'schedule',
                    $scheduleId,
                    'schedule.view',
                    $sched['source_type'] === 'request' ? (int)$sched['source_id'] : null,
                    $scheduleId,
                    null
                );
            }

            // Immediately check and process reminders for the new date!
            self::processReminders($conn);
        }
    }

    /**
     * Check for stale pending requests (> N days, default 3) and alert secretary.
     */
    public static function checkStalePendingRequests(mysqli $conn, int $thresholdDays = 3): array
    {
        $settings = self::getSettings($conn);
        if (empty($settings['notify_stale_requests'])) {
            return [];
        }

        $thresholdDays = max(1, $thresholdDays ?: (int)($settings['stale_days_threshold'] ?? 3));
        $recipients = self::resolveStaffRecipients($conn);
        $staleAlerts = [];

        $sql = "
            SELECT r.request_id, r.reference_number, r.request_type, r.date_requested, 
                   DATEDIFF(NOW(), r.date_requested) AS days_pending,
                   u.fullname AS requester_name
            FROM requests r
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.status = 'pending'
              AND r.deleted_at IS NULL
              AND r.date_requested <= NOW() - INTERVAL ? DAY
            ORDER BY r.date_requested ASC
            LIMIT 15
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $thresholdDays);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $todayStr = date('Y-m-d');
        foreach ($rows as $row) {
            $reqId = (int)$row['request_id'];
            $ref = $row['reference_number'] ?: "REQ-{$reqId}";
            $days = (int)$row['days_pending'];
            $typeLabel = ucwords(str_replace(['_', '-'], ' ', (string)$row['request_type']));
            $requester = $row['requester_name'] ?: 'Parishioner';

            $title = "Pending Request Alert: {$ref}";
            $message = "{$typeLabel} request ({$requester}) has been pending for {$days} days.";

            foreach ($recipients as $recip) {
                $recipId = (int)$recip['id'];
                $dedupeKey = hash('sha256', "stale_req|{$recipId}|{$reqId}|{$todayStr}");

                $chk = $conn->prepare("SELECT notification_id FROM notifications WHERE user_id = ? AND deduplication_key = ? LIMIT 1");
                if ($chk) {
                    $chk->bind_param('is', $recipId, $dedupeKey);
                    $chk->execute();
                    $exists = $chk->get_result()->fetch_assoc();
                    $chk->close();
                    if ($exists) continue;
                }

                $ins = $conn->prepare("
                    INSERT INTO notifications (
                        user_id, notification_type, title, message, entity_type, entity_id,
                        action_key, state, deduplication_key, is_read, related_request_id, created_at
                    ) VALUES (?, 'stale_request', ?, ?, 'request', ?, 'request.view', 'unread', ?, 0, ?, NOW())
                ");
                if ($ins) {
                    $ins->bind_param('issisi', $recipId, $title, $message, $reqId, $dedupeKey, $reqId);
                    $ins->execute();
                    $ins->close();
                }
            }
            $staleAlerts[] = $ref;
        }

        return $staleAlerts;
    }

    /**
     * Check for booking conflicts among upcoming schedules and alert secretary.
     */
    public static function checkBookingConflicts(mysqli $conn): array
    {
        $settings = self::getSettings($conn);
        if (empty($settings['notify_conflicts'])) {
            return [];
        }

        $recipients = self::resolveStaffRecipients($conn);
        $conflicts = [];

        // Check overlapping schedules on the same day within the next 30 days
        $sql = "
            SELECT 
                a.schedule_id AS id_a, a.title AS title_a, a.event_date, a.start_time AS start_a, a.end_time AS end_a,
                b.schedule_id AS id_b, b.title AS title_b, b.start_time AS start_b, b.end_time AS end_b
            FROM schedule_events a
            JOIN schedule_events b ON a.event_date = b.event_date 
                                  AND a.schedule_id < b.schedule_id
                                  AND a.status NOT IN ('cancelled', 'rejected')
                                  AND b.status NOT IN ('cancelled', 'rejected')
            WHERE a.event_date >= CURDATE() 
              AND a.event_date <= CURDATE() + INTERVAL 30 DAY
              AND (
                  (a.start_time < b.end_time AND a.end_time > b.start_b)
                  OR (a.start_time = b.start_b)
              )
            LIMIT 10
        ";

        $res = $conn->query($sql);
        if (!$res) return [];

        $todayStr = date('Y-m-d');
        while ($row = $res->fetch_assoc()) {
            $idA = (int)$row['id_a'];
            $idB = (int)$row['id_b'];
            $date = date('M d, Y', strtotime($row['event_date']));
            $timeA = date('g:i A', strtotime($row['start_a']));
            $timeB = date('g:i A', strtotime($row['start_b']));

            $title = "Booking Conflict Detected on {$date}";
            $message = "'{$row['title_a']}' ({$timeA}) overlaps with '{$row['title_b']}' ({$timeB}).";

            foreach ($recipients as $recip) {
                $recipId = (int)$recip['id'];
                $dedupeKey = hash('sha256', "conflict|{$recipId}|{$idA}_{$idB}|{$todayStr}");

                $chk = $conn->prepare("SELECT notification_id FROM notifications WHERE user_id = ? AND deduplication_key = ? LIMIT 1");
                if ($chk) {
                    $chk->bind_param('is', $recipId, $dedupeKey);
                    $chk->execute();
                    $exists = $chk->get_result()->fetch_assoc();
                    $chk->close();
                    if ($exists) continue;
                }

                $ins = $conn->prepare("
                    INSERT INTO notifications (
                        user_id, notification_type, title, message, entity_type, entity_id,
                        action_key, state, deduplication_key, is_read, schedule_id, created_at
                    ) VALUES (?, 'schedule_conflict', ?, ?, 'schedule', ?, 'schedule.view', 'unread', ?, 0, ?, NOW())
                ");
                if ($ins) {
                    $ins->bind_param('issisi', $recipId, $title, $message, $idA, $dedupeKey, $idA);
                    $ins->execute();
                    $ins->close();
                }
            }
            $conflicts[] = "{$row['title_a']} vs {$row['title_b']} on {$date}";
        }

        return $conflicts;
    }

    /**
     * Get upcoming schedules for the next N days (default 7) with countdown labels and urgency colors.
     */
    public static function getUpcomingSchedules(mysqli $conn, int $days = 7, ?string $referenceDate = null): array
    {
        $refDateStr = $referenceDate ?: date('Y-m-d');
        $refDate = new DateTime($refDateStr);
        $endDate = (clone $refDate)->modify("+{$days} days")->format('Y-m-d');
        $startDate = $refDate->format('Y-m-d');

        $sql = "
            SELECT se.* 
            FROM schedule_events se
            WHERE se.event_date >= ? AND se.event_date <= ?
              AND (se.approval_status = 'approved' OR se.approval_status IS NULL)
              AND (se.status IS NULL OR se.status NOT IN ('cancelled', 'rejected'))
            ORDER BY se.event_date ASC, se.start_time ASC
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $output = [];
        foreach ($rows as $row) {
            $eventDate = new DateTime($row['event_date']);
            $diffDays = (int)$refDate->diff($eventDate)->format('%r%a');

            $label = match ($diffDays) {
                0 => 'Today',
                1 => 'Tomorrow',
                2 => 'In 2 days',
                3 => 'In 3 days',
                default => "In {$diffDays} days"
            };

            $urgency = match ($diffDays) {
                0 => 'today',
                1 => 'tomorrow',
                2 => 'in_2_days',
                3 => 'in_3_days',
                default => 'upcoming'
            };

            $row['days_diff'] = $diffDays;
            $row['countdown_label'] = $label;
            $row['urgency'] = $urgency;
            $row['requester_name'] = self::extractRequesterName($conn, $row);
            $row['service_label'] = self::formatServiceLabel($row['title'], $row['category'] ?? null);
            $output[] = $row;
        }

        return $output;
    }

    /**
     * Get recent reminder deliveries from reminder_log.
     */
    public static function getDeliveryLogs(mysqli $conn, int $limit = 20): array
    {
        if (!function_exists('tableExists') || !tableExists($conn, 'reminder_log')) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        $sql = "
            SELECT rl.*, se.title AS schedule_title, se.event_date, se.start_time
            FROM reminder_log rl
            LEFT JOIN schedule_events se ON rl.schedule_id = se.schedule_id
            ORDER BY rl.sent_at DESC, rl.id DESC
            LIMIT ?
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $logs;
    }

    /**
     * Helper to simulate a new request submission (for testing and demo buttons).
     */
    public static function simulateNewRequest(mysqli $conn): array
    {
        // Pick an existing parishioner user
        $uRes = $conn->query("SELECT id, fullname, email FROM users WHERE role IN ('user', 'parishioner') AND status = 'active' LIMIT 1");
        $user = $uRes ? $uRes->fetch_assoc() : null;
        $userId = $user ? (int)$user['id'] : 1;
        $parishionerName = $user ? $user['fullname'] : 'Demo Requester';

        $types = ['baptism_service', 'confirmation_service', 'wedding', 'funeral_mass', 'house_blessing'];
        $chosenType = $types[array_rand($types)];
        $refNum = 'REQ-DEMO-' . strtoupper(substr(md5((string)microtime(true)), 0, 6));

        $stmt = $conn->prepare("
            INSERT INTO requests (
                user_id, request_type, record_holder_name, description, 
                status, priority, reference_number, date_requested
            ) VALUES (?, ?, ?, 'Simulated demo request for secretary notification testing.', 'pending', 'normal', ?, NOW())
        ");

        if (!$stmt) return ['ok' => false, 'error' => $conn->error];
        $stmt->bind_param('isss', $userId, $chosenType, $parishionerName, $refNum);
        $ok = $stmt->execute();
        $reqId = $stmt->insert_id;
        $stmt->close();

        if (!$ok) return ['ok' => false, 'error' => 'Failed to insert request'];

        // Trigger notification to Secretary / Admin
        $recipients = self::resolveStaffRecipients($conn);
        $title = "New Request: " . ucwords(str_replace('_', ' ', $chosenType));
        $msg = "{$refNum} submitted by {$parishionerName}. Click to review.";

        foreach ($recipients as $recip) {
            self::insertNotification(
                $conn,
                (int)$recip['id'],
                'new_request_admin',
                $title,
                $msg,
                'request',
                $reqId,
                'request.view',
                $reqId,
                null,
                null
            );
        }

        return [
            'ok' => true,
            'request_id' => $reqId,
            'reference_number' => $refNum,
            'type' => $chosenType,
            'requester' => $parishionerName
        ];
    }

    /**
     * Builds responsive HTML email for schedule reminder.
     */
    private static function buildReminderEmailHtml(
        string $prefix,
        string $serviceName,
        string $timeFormatted,
        string $dateFormatted,
        string $requesterName,
        string $location,
        ?int $requestId,
        int $daysBefore
    ): string {
        $urgencyBg = match ($daysBefore) {
            0 => '#DC2626',
            1 => '#EF4444',
            2 => '#F97316',
            3 => '#C89B3C',
            default => '#1D3A2A'
        };

        $baseUrl = defined('BASE_URL') ? BASE_URL : '/';
        $viewUrl = $requestId 
            ? rtrim($baseUrl, '/') . "/admin/request-workflow.php?id={$requestId}" 
            : rtrim($baseUrl, '/') . "/admin/manage-calendar.php";

        return "
        <!DOCTYPE html>
        <html>
        <head><meta charset='UTF-8'><title>{$prefix}: {$serviceName}</title></head>
        <body style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif; background-color:#F4F1EA; margin:0; padding:24px; color:#1C1917;'>
            <table cellpadding='0' cellspacing='0' style='max-width:580px; width:100%; margin:0 auto; background:#FFFFFF; border:1px solid #E7E0D2; border-radius:12px; overflow:hidden; box-shadow:0 4px 14px rgba(0,0,0,0.06);'>
                <tr>
                    <td style='background:#1D3A2A; padding:20px 24px; text-align:center;'>
                        <div style='color:#C89B3C; font-size:11px; font-weight:700; letter-spacing:1px; text-transform:uppercase;'>PARISH SCHEDULE ALERT</div>
                        <div style='color:#FFFFFF; font-size:18px; font-weight:700; margin-top:4px;'>SAN LORENZO RUIZ MISSION STATION</div>
                        <div style='display:inline-block; margin-top:10px; background:{$urgencyBg}; color:#FFFFFF; font-size:11px; font-weight:700; padding:4px 14px; border-radius:20px; text-transform:uppercase;'>{$prefix}</div>
                    </td>
                </tr>
                <tr>
                    <td style='padding:24px 28px;'>
                        <h2 style='font-size:18px; color:#1C1917; margin:0 0 16px 0; font-weight:700;'>{$prefix}: {$serviceName}</h2>
                        <table style='width:100%; border-collapse:collapse; margin-bottom:20px;'>
                            <tr>
                                <td style='padding:8px 0; font-size:13px; color:#6B7280; width:110px;'>Date:</td>
                                <td style='padding:8px 0; font-size:13px; font-weight:600; color:#111827;'>{$dateFormatted}</td>
                            </tr>
                            <tr>
                                <td style='padding:8px 0; font-size:13px; color:#6B7280;'>Time:</td>
                                <td style='padding:8px 0; font-size:13px; font-weight:600; color:#111827;'>{$timeFormatted}</td>
                            </tr>
                            <tr>
                                <td style='padding:8px 0; font-size:13px; color:#6B7280;'>Requester:</td>
                                <td style='padding:8px 0; font-size:13px; font-weight:600; color:#111827;'>{$requesterName}</td>
                            </tr>
                            <tr>
                                <td style='padding:8px 0; font-size:13px; color:#6B7280;'>Venue:</td>
                                <td style='padding:8px 0; font-size:13px; font-weight:600; color:#111827;'>{$location}</td>
                            </tr>
                        </table>
                        <div style='text-align:center; margin-top:20px;'>
                            <a href='{$viewUrl}' style='display:inline-block; background:#1D3A2A; color:#FFFFFF; text-decoration:none; padding:10px 22px; font-size:13px; font-weight:600; border-radius:6px;'>Open Details in Parish System &rarr;</a>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style='background:#F8FAFC; padding:12px 24px; text-align:center; font-size:11px; color:#94A3B8; border-top:1px solid #E2E8F0;'>
                        This is an automated notification from the San Lorenzo Ruiz Parish Management System.
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";
    }
}
