<?php
/**
 * AdminNotificationService
 * ------------------------
 * Centralized automatic notification dispatcher for parish administrators and staff.
 * 
 * Automatically triggers immediate, reliable notifications every time a parishioner
 * submits any request (Sacraments, Mass intentions, Certificates, Blessings, Appointments, etc.)
 * across In-App, Email, and Urgent SMS channels with full idempotency, retry tracking,
 * and parishioner confirmations.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/services/NotificationService.php';

final class AdminNotificationService
{
    /**
     * Humanize any parish request type dynamically.
     * New request types added later are automatically formatted.
     */
    public static function formatRequestType(string $type): string
    {
        $raw = strtolower(trim($type));
        $labels = [
            'baptism_service'               => 'Baptism Service',
            'confirmation_service'          => 'Confirmation Service',
            'first_communion_service'       => 'First Communion Service',
            'first_communion'               => 'First Communion Service',
            'marriage_wedding_service'      => 'Marriage / Wedding Ceremony',
            'wedding'                       => 'Marriage / Wedding Ceremony',
            'funeral_mass'                  => 'Funeral Mass & Burial',
            'anointing_of_the_sick'         => 'Anointing of the Sick',
            'urgent_anointing'              => 'Urgent Anointing of the Sick',
            'mass_intention'                => 'Mass Intention / Offering',
            'mass_offering'                 => 'Mass Intention / Offering',
            'counseling'                    => 'Priest Counseling / Appointment',
            'priest_appointment'           => 'Priest Appointment',
            'house_blessing'                => 'House Blessing',
            'business_blessing'             => 'Business / Store Blessing',
            'vehicle_blessing'              => 'Vehicle / Car Blessing',
            'event_blessing'                => 'Event Blessing',
            'other_blessing'                => 'Blessing Request',
            'baptismal_certificate'         => 'Baptismal Certificate',
            'confirmation_certificate'      => 'Confirmation Certificate',
            'first_communion_certificate'   => 'First Communion Certificate',
            'marriage_certification'        => 'Marriage Certificate',
            'funeral_certification'         => 'Funeral / Burial Certificate',
            'death_certificate'             => 'Death Certificate',
        ];

        if (isset($labels[$raw])) {
            return $labels[$raw];
        }

        // Generic fallback for any future request type
        $clean = str_replace(['_', '-'], ' ', $raw);
        return ucwords($clean);
    }

    /**
     * Check if a request type is urgent (requiring priority alerts/SMS).
     */
    public static function isUrgentRequest(string $type, string $priority = ''): bool
    {
        $typeNorm = strtolower(trim($type));
        $priorityNorm = strtolower(trim($priority));

        if ($priorityNorm === 'urgent' || $priorityNorm === 'high') {
            return true;
        }

        $urgentTypes = [
            'anointing_of_the_sick',
            'urgent_anointing',
            'funeral_mass',
            'burial',
            'viaticum',
            'emergency'
        ];

        foreach ($urgentTypes as $ut) {
            if (str_contains($typeNorm, $ut)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Main Trigger: Automatically notify all active Admins (and Secretary if enabled)
     * when a parishioner submits any request. Also confirms receipt to the parishioner.
     *
     * @param mysqli $conn Active database connection
     * @param int|array $requestOrId Request ID integer or request associative array
     * @param array $options Optional configuration overrides
     * @return array Results summary of delivery attempts
     */
    public static function notifyOnNewRequest(mysqli $conn, int|array $requestOrId, array $options = []): array
    {
        // 1. Fetch full request record
        $req = is_array($requestOrId) ? $requestOrId : self::loadRequest($conn, (int)$requestOrId);
        if (!$req || empty($req['request_id'])) {
            return ['ok' => false, 'error' => 'Request not found'];
        }

        $requestId = (int)$req['request_id'];
        $userId = (int)($req['user_id'] ?? 0);
        $reference = trim((string)($req['reference_number'] ?? 'REQ-' . $requestId));
        $rawType = trim((string)($req['request_type'] ?? 'parish_request'));
        $typeLabel = self::formatRequestType($rawType);
        $description = trim((string)($req['description'] ?? ''));
        $recordHolder = trim((string)($req['record_holder_name'] ?? ''));
        $priority = trim((string)($req['priority'] ?? 'normal'));
        $isUrgent = self::isUrgentRequest($rawType, $priority);

        // 2. Fetch Parishioner / Requester details
        $parishioner = self::loadUser($conn, $userId);
        $parishionerName = !empty($parishioner['fullname']) ? $parishioner['fullname'] : ($recordHolder ?: 'Parishioner');
        $parishionerEmail = trim((string)($parishioner['email'] ?? ''));
        $parishionerPhone = trim((string)($parishioner['phone_number'] ?? ''));

        // 3. Format Date / Time information
        $submittedAt = !empty($req['date_requested']) 
            ? date('M d, Y \a\t g:i A', strtotime($req['date_requested'])) 
            : date('M d, Y \a\t g:i A');

        $scheduleText = self::extractRequestedSchedule($req);

        // 4. Format concise summary
        $summary = $description !== '' ? $description : ($recordHolder !== '' ? "Record Holder: {$recordHolder}" : 'No additional details provided.');
        $shortSummary = mb_strimwidth($summary, 0, 300, '...');

        // 5. Direct Admin Dashboard Link
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/';
        $directAdminUrl = rtrim($baseUrl, '/') . '/admin/request-workflow.php?id=' . $requestId;

        // 6. Resolve Admin and Secretary Staff Recipients
        $recipients = self::resolveStaffRecipients($conn);
        if (empty($recipients)) {
            error_log("[AdminNotificationService] Warning: No active admin recipients found for request #{$requestId}");
        }

        $results = [
            'ok' => true,
            'request_id' => $requestId,
            'reference' => $reference,
            'recipients_count' => count($recipients),
            'in_app' => 0,
            'email' => 0,
            'sms' => 0,
            'errors' => []
        ];

        // 7. Notification Titles & Messages
        $urgentPrefix = $isUrgent ? '⚠️ [URGENT] ' : '';
        $inAppTitle = "{$urgentPrefix}New {$typeLabel} Request: {$reference}";
        $inAppMessage = "Parishioner: {$parishionerName}\n"
                      . "Contact: " . ($parishionerPhone ?: 'No phone') . " · " . ($parishionerEmail ?: 'No email') . "\n"
                      . "Submitted: {$submittedAt}\n"
                      . "Schedule: {$scheduleText}\n"
                      . "Summary: {$shortSummary}\n"
                      . "Ref: {$reference}";

        // 8. Deliver to each Admin and Secretary
        $emailEnabled = (readSetting($conn, 'notify_admin_on_requests', '1') !== '0');
        $smsEnabled = $isUrgent && (readSetting($conn, 'notify_urgent_sms_enabled', '1') !== '0');

        foreach ($recipients as $staff) {
            $staffId = (int)$staff['id'];
            $staffEmail = trim((string)($staff['email'] ?? ''));
            $staffPhone = trim((string)($staff['phone_number'] ?? ''));

            // Deduplication key per recipient & request
            $dedupeKey = hash('sha256', "admin_new_request|{$requestId}|{$staffId}");

            // --- A. In-App Notification with Deduplication ---
            $inAppCreated = self::createInAppNotification(
                $conn,
                $staffId,
                'new_request_admin',
                $inAppTitle,
                $inAppMessage,
                'request',
                $requestId,
                'admin.request.view',
                $dedupeKey
            );

            if ($inAppCreated) {
                $results['in_app']++;
            }

            // --- B. Admin Email Notification ---
            if ($emailEnabled && $staffEmail !== '' && isValidEmail($staffEmail)) {
                $emailDedupe = hash('sha256', "admin_req_email|{$requestId}|{$staffId}");
                if (!self::hasDeliveredChannel($conn, $requestId, 'email', $staffId)) {
                    $emailSubject = "{$urgentPrefix}[TUGON Alert] New {$typeLabel} Request: {$reference} ({$parishionerName})";
                    $emailBodyHtml = self::buildAdminEmailHtml(
                        $typeLabel,
                        $reference,
                        $parishionerName,
                        $parishionerPhone,
                        $parishionerEmail,
                        $submittedAt,
                        $scheduleText,
                        $shortSummary,
                        $directAdminUrl,
                        $isUrgent
                    );

                    try {
                        $mailResult = sendTugonEmail(
                            $conn,
                            $staffEmail,
                            $emailSubject,
                            $emailBodyHtml,
                            $inAppMessage,
                            $staffId,
                            'new_request_admin'
                        );

                        $deliveryStatus = (!empty($mailResult['ok'])) ? 'sent' : 'failed';
                        $mailError = $mailResult['error'] ?? null;
                        self::logStaffDelivery($conn, $staffId, 'email', $requestId, $emailDedupe, $deliveryStatus, $mailError);

                        if ($deliveryStatus === 'sent') {
                            $results['email']++;
                        } else {
                            $results['errors'][] = "Email to {$staffEmail} failed: {$mailError}";
                        }
                    } catch (Throwable $mailEx) {
                        $results['errors'][] = "Email exception for {$staffEmail}: " . $mailEx->getMessage();
                        self::logStaffDelivery($conn, $staffId, 'email', $requestId, $emailDedupe, 'failed', $mailEx->getMessage());
                    }
                }
            }

            // --- C. Urgent SMS Notification (Optional, for anointing / emergencies) ---
            if ($smsEnabled && !empty($staffPhone) && function_exists('sendTugonSms')) {
                $smsDedupe = hash('sha256', "admin_req_sms|{$requestId}|{$staffId}");
                if (!self::hasDeliveredChannel($conn, $requestId, 'sms', $staffId)) {
                    $smsText = "TUGON URGENT ALERT: {$parishionerName} requested {$typeLabel} (Ref: {$reference}). Phone: {$parishionerPhone}. Please review on Admin Dashboard.";
                    try {
                        $smsResult = sendTugonSms($conn, $staffPhone, $smsText, $staffId, 'urgent_request_alert');
                        $smsStatus = (!empty($smsResult['ok'])) ? 'sent' : 'failed';
                        $smsError = $smsResult['error'] ?? null;
                        self::logStaffDelivery($conn, $staffId, 'sms', $requestId, $smsDedupe, $smsStatus, $smsError);

                        if ($smsStatus === 'sent') {
                            $results['sms']++;
                        }
                    } catch (Throwable $smsEx) {
                        self::logStaffDelivery($conn, $staffId, 'sms', $requestId, $smsDedupe, 'failed', $smsEx->getMessage());
                    }
                }
            }
        }

        // 9. Send Immediate Confirmation to the Parishioner
        self::sendParishionerConfirmation(
            $conn,
            $userId,
            $requestId,
            $reference,
            $typeLabel,
            $parishionerName,
            $parishionerEmail,
            $submittedAt
        );

        return $results;
    }

    /**
     * Mark all unread notifications for a specific request as read
     * when the admin opens the request workflow or detail page.
     */
    public static function markAsReadForRequest(mysqli $conn, int $requestId, int $adminUserId): int
    {
        if ($requestId <= 0 || $adminUserId <= 0) {
            return 0;
        }

        $stmt = $conn->prepare("
            UPDATE notifications 
            SET state = 'read', is_read = 1, read_at = NOW() 
            WHERE entity_type = 'request' 
              AND entity_id = ? 
              AND user_id = ? 
              AND (state = 'unread' OR is_read = 0)
        ");

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('ii', $requestId, $adminUserId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return max(0, $affected);
    }

    /**
     * Send immediate confirmation notification and email to the submitting parishioner.
     */
    private static function sendParishionerConfirmation(
        mysqli $conn,
        int $userId,
        int $requestId,
        string $reference,
        string $typeLabel,
        string $fullname,
        string $email,
        string $submittedAt
    ): void {
        if ($userId <= 0) {
            return;
        }

        $dedupe = hash('sha256', "parishioner_confirm|{$requestId}|{$userId}");

        // In-App Notification with Deduplication
        $title = "Request Received: {$reference}";
        $message = "Peace be with you, {$fullname}! Your {$typeLabel} request ({$reference}) has been successfully received by San Lorenzo Ruiz Mission Station and is now queued for review.";
        
        self::createInAppNotification(
            $conn,
            $userId,
            'request_submitted',
            $title,
            $message,
            'request',
            $requestId,
            'request.view',
            $dedupe
        );

        // Confirmation Email to Parishioner
        if ($email !== '' && isValidEmail($email)) {
            $subject = "Your {$typeLabel} Request Received - {$reference}";
            $body = "<p>Dear {$fullname},</p>"
                  . "<p>Peace and blessings from San Lorenzo Ruiz Mission Station!</p>"
                  . "<p>We have successfully received your request for <strong>{$typeLabel}</strong>.</p>"
                  . "<div style='background-color:#F8F6F0; border-left:4px solid #C89B3C; padding:12px 16px; margin:16px 0; border-radius:4px;'>"
                  . "<strong>Reference Number:</strong> {$reference}<br>"
                  . "<strong>Date Submitted:</strong> {$submittedAt}<br>"
                  . "<strong>Current Status:</strong> <span style='color:#b45309; font-weight:bold;'>Pending Review</span>"
                  . "</div>"
                  . "<p>Our parish administration is currently verifying your submitted details. You will be alerted immediately once the status of your request is updated.</p>"
                  . "<p>May God bless you and your family.</p>";

            sendTugonEmail(
                $conn,
                $email,
                $subject,
                tugonEmailTemplate($title, $body),
                $message,
                $userId,
                'request'
            );
        }
    }

    /**
     * Extract schedule date and time from request record or description text.
     */
    private static function extractRequestedSchedule(array $req): string
    {
        if (!empty($req['ceremony_date'])) {
            $d = date('M d, Y', strtotime($req['ceremony_date']));
            $t = !empty($req['ceremony_time']) ? date('h:i A', strtotime($req['ceremony_time'])) : '';
            return trim("{$d} {$t}");
        }

        // Try extracting from description
        $desc = (string)($req['description'] ?? '');
        if (preg_match('/(Preferred Date|Date of Ceremony|Wedding Date|Baptism Date|Event Date|Schedule Date):\s*([^\r\n;,]+)/i', $desc, $m)) {
            return trim($m[2]);
        }

        if (preg_match('/\b(202[5-9]-\d{2}-\d{2})\b/', $desc, $m)) {
            return date('M d, Y', strtotime($m[1]));
        }

        return 'No specific schedule requested';
    }

    /**
     * Resolve all active administrators and, if enabled, parish secretaries.
     */
    private static function resolveStaffRecipients(mysqli $conn): array
    {
        $includeSecretary = (readSetting($conn, 'notify_secretary_on_requests', '1') !== '0');
        
        $roleFilter = $includeSecretary 
            ? "role IN ('admin', 'administrator', 'secretary')" 
            : "role IN ('admin', 'administrator')";

        $sql = "
            SELECT DISTINCT id, fullname, email, phone_number, role 
            FROM users 
            WHERE status = 'active' 
              AND ({$roleFilter} 
                   OR id IN (
                       SELECT ur.user_id 
                       FROM user_roles ur 
                       JOIN roles r ON r.role_id = ur.role_id 
                       WHERE r.role_key IN ('administrator', 'admin'" . ($includeSecretary ? ", 'secretary', 'parish_staff', 'records_clerk'" : "") . ")
                   )
              )
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
     * Insert in-app notification with deduplication.
     */
    private static function createInAppNotification(
        mysqli $conn,
        int $userId,
        string $type,
        string $title,
        string $message,
        string $entityType,
        int $entityId,
        string $actionKey,
        string $dedupeKey
    ): bool {
        // Check if exists
        $chk = $conn->prepare("SELECT notification_id FROM notifications WHERE user_id = ? AND deduplication_key = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param('is', $userId, $dedupeKey);
            $chk->execute();
            $exists = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($exists) {
                return false; // Already notified
            }
        }

        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, notification_type, title, message, entity_type, entity_id, action_key, state, deduplication_key, is_read) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'unread', ?, 0)
        ");

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('issssiss', $userId, $type, $title, $message, $entityType, $entityId, $actionKey, $dedupeKey);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }

    /**
     * Check if a specific delivery channel has already been recorded for this request.
     */
    private static function hasDeliveredChannel(mysqli $conn, int $requestId, string $channel, int $userId): bool
    {
        if (!function_exists('tableExists') || !tableExists($conn, 'notification_deliveries')) {
            return false;
        }

        $dedupe = hash('sha256', "admin_req_{$channel}|{$requestId}|{$userId}");
        $stmt = $conn->prepare("SELECT delivery_id FROM notification_deliveries WHERE idempotency_key = ? AND status = 'sent' LIMIT 1");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $dedupe);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return !empty($row);
    }

    /**
     * Log delivery attempt in notification_deliveries table.
     */
    private static function logStaffDelivery(
        mysqli $conn,
        int $userId,
        string $channel,
        int $requestId,
        string $idempotencyKey,
        string $status,
        ?string $failureReason = null
    ): void {
        if (!function_exists('tableExists') || !tableExists($conn, 'notification_deliveries')) {
            return;
        }

        // Get notification id if any
        $notifId = 0;
        $nStmt = $conn->prepare("SELECT notification_id FROM notifications WHERE user_id = ? AND entity_type = 'request' AND entity_id = ? ORDER BY notification_id DESC LIMIT 1");
        if ($nStmt) {
            $nStmt->bind_param('ii', $userId, $requestId);
            $nStmt->execute();
            $nr = $nStmt->get_result()->fetch_assoc();
            $nStmt->close();
            if ($nr) {
                $notifId = (int)$nr['notification_id'];
            }
        }

        $attemptCount = 1;
        $sentAt = $status === 'sent' ? date('Y-m-d H:i:s') : null;
        $failedAt = $status === 'failed' ? date('Y-m-d H:i:s') : null;

        if ($notifId > 0) {
            $stmt = $conn->prepare("
                INSERT INTO notification_deliveries (
                    notification_id, channel, idempotency_key, status, attempt_count, sent_at, failed_at, failure_reason, last_attempt_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    status = VALUES(status), 
                    attempt_count = attempt_count + 1,
                    sent_at = VALUES(sent_at),
                    failed_at = VALUES(failed_at),
                    failure_reason = VALUES(failure_reason),
                    last_attempt_at = NOW()
            ");

            if ($stmt) {
                $stmt->bind_param('isssisss', $notifId, $channel, $idempotencyKey, $status, $attemptCount, $sentAt, $failedAt, $failureReason);
                $stmt->execute();
                $stmt->close();
            }
        }

        // Also record in notification_logs for centralized channel auditing
        if (function_exists('tableExists') && tableExists($conn, 'notification_logs')) {
            $u = self::loadUser($conn, $userId);
            $targetEmail = $u['email'] ?? '';
            $sub = "Staff Notification: Request #{$requestId}";
            $ntype = 'new_request_admin';
            $logStmt = $conn->prepare("
                INSERT INTO notification_logs (user_id, channel, email, recipient, subject, notification_type, delivery_status, error_message, sent_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            if ($logStmt) {
                $logStmt->bind_param('issssssss', $userId, $channel, $targetEmail, $targetEmail, $sub, $ntype, $status, $failureReason, $sentAt);
                $logStmt->execute();
                $logStmt->close();
            }
        }
    }

    /**
     * Build institutional HTML email template for administrators and staff.
     */
    private static function buildAdminEmailHtml(
        string $typeLabel,
        string $reference,
        string $parishionerName,
        string $phone,
        string $email,
        string $submittedAt,
        string $scheduleText,
        string $summary,
        string $dashboardUrl,
        bool $isUrgent
    ): string {
        $bannerBg = $isUrgent ? '#991B1B' : '#1D3A2A';
        $badgeText = $isUrgent ? 'URGENT PARISH REQUEST' : 'NEW REQUEST SUBMISSION';

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>New Parish Request Alert</title>
        </head>
        <body style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,Helvetica,Arial,sans-serif; background-color:#F4F1EA; margin:0; padding:24px; color:#1C1917;'>
            <table cellpadding='0' cellspacing='0' style='max-width:620px; width:100%; margin:0 auto; background:#FFFFFF; border:1px solid #E7E0D2; border-radius:12px; overflow:hidden; box-shadow:0 4px 14px rgba(0,0,0,0.06);'>
                <!-- Header -->
                <tr>
                    <td style='background:{$bannerBg}; padding:20px 28px; text-align:center;'>
                        <div style='color:#C89B3C; font-size:11px; font-weight:700; letter-spacing:1px; text-transform:uppercase;'>ARCHDIOCESE OF COTABATO</div>
                        <div style='color:#FFFFFF; font-size:18px; font-weight:700; margin-top:2px;'>SAN LORENZO RUIZ MISSION STATION</div>
                        <div style='display:inline-block; margin-top:10px; background:rgba(255,255,255,0.15); color:#FFFFFF; font-size:10px; font-weight:700; padding:4px 12px; border-radius:20px; text-transform:uppercase;'>{$badgeText}</div>
                    </td>
                </tr>
                <!-- Content -->
                <tr>
                    <td style='padding:28px 32px;'>
                        <h2 style='margin:0 0 8px 0; font-size:19px; color:#1C1917; font-weight:700;'>New {$typeLabel} Submitted</h2>
                        <p style='margin:0 0 20px 0; font-size:13px; color:#78716C; line-height:1.4;'>A new request has been submitted by a parishioner and requires administrative review.</p>
                        
                        <table cellpadding='0' cellspacing='0' style='width:100%; border-collapse:collapse; background:#FBF9F5; border:1px solid #ECE4D6; border-radius:8px; overflow:hidden; margin-bottom:24px;'>
                            <tr>
                                <td style='padding:10px 14px; border-bottom:1px solid #ECE4D6; width:35%; font-size:12px; font-weight:600; color:#57534E;'>Reference Number:</td>
                                <td style='padding:10px 14px; border-bottom:1px solid #ECE4D6; font-size:13px; font-weight:700; color:#1D3A2A;'>{$reference}</td>
                            </tr>
                            <tr>
                                <td style='padding:10px 14px; border-bottom:1px solid #ECE4D6; font-size:12px; font-weight:600; color:#57534E;'>Request Type:</td>
                                <td style='padding:10px 14px; border-bottom:1px solid #ECE4D6; font-size:12.5px; font-weight:600; color:#1C1917;'>{$typeLabel}</td>
                            </tr>
                            <tr>
                                <td style='padding:10px 14px; border-bottom:1px solid #ECE4D6; font-size:12px; font-weight:600; color:#57534E;'>Parishioner Name:</td>
                                <td style='padding:10px 14px; border-bottom:1px solid #ECE4D6; font-size:12.5px; font-weight:600; color:#1C1917;'>{$parishionerName}</td>
                            </tr>
                            <tr>
                                <td style='padding:10px 14px; border-bottom:1px solid #ECE4D6; font-size:12px; font-weight:600; color:#57534E;'>Contact Details:</td>
                                <td style='padding:10px 14px; border-bottom:1px solid #ECE4D6; font-size:12px; color:#1C1917;'>" . ($phone ? "📞 {$phone}" : "No phone") . ($email ? " &nbsp;|&nbsp; ✉️ {$email}" : "") . "</td>
                            </tr>
                            <tr>
                                <td style='padding:10px 14px; border-bottom:1px solid #ECE4D6; font-size:12px; font-weight:600; color:#57534E;'>Submitted At:</td>
                                <td style='padding:10px 14px; border-bottom:1px solid #ECE4D6; font-size:12px; color:#1C1917;'>{$submittedAt}</td>
                            </tr>
                            <tr>
                                <td style='padding:10px 14px; border-bottom:1px solid #ECE4D6; font-size:12px; font-weight:600; color:#57534E;'>Requested Schedule:</td>
                                <td style='padding:10px 14px; border-bottom:1px solid #ECE4D6; font-size:12px; font-weight:600; color:#92400E;'>{$scheduleText}</td>
                            </tr>
                            <tr>
                                <td style='padding:10px 14px; font-size:12px; font-weight:600; color:#57534E; vertical-align:top;'>Parishioner Notes:</td>
                                <td style='padding:10px 14px; font-size:12px; color:#44403C; line-height:1.4;'>" . nl2br(htmlspecialchars($summary, ENT_QUOTES, 'UTF-8')) . "</td>
                            </tr>
                        </table>

                        <!-- CTA Button -->
                        <div style='text-align:center; margin:28px 0 16px 0;'>
                            <a href='{$dashboardUrl}' style='display:inline-block; background-color:#8C6427; color:#FFFFFF; text-decoration:none; padding:12px 28px; font-size:13.5px; font-weight:700; border-radius:6px; box-shadow:0 2px 6px rgba(140,100,39,0.3);'>
                                Open Request in Admin Dashboard &rarr;
                            </a>
                        </div>
                    </td>
                </tr>
                <!-- Footer -->
                <tr>
                    <td style='background:#F7F4ED; padding:14px 28px; text-align:center; border-top:1px solid #ECE4D6; font-size:11px; color:#78716C;'>
                        CONFIDENTIAL &mdash; San Lorenzo Ruiz Mission Station Automated System Notification
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";
    }

    /**
     * Helper to load request row from database.
     */
    private static function loadRequest(mysqli $conn, int $requestId): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM requests WHERE request_id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Helper to load user record.
     */
    private static function loadUser(mysqli $conn, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $conn->prepare("SELECT id, fullname, email, phone_number FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}
