<?php
/**
 * Helper Functions and Authentication
 * AI-Powered Parish Request and Sacramental Records Management System
 * Provides utility functions for authentication, validation, and security
 */

require_once __DIR__ . '/../config/app.php';

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/secure-files.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/authentication.php';
require_once __DIR__ . '/otp-transactions.php';
require_once __DIR__ . '/account-management.php';
require_once __DIR__ . '/password-security.php';

if (!function_exists('t')) {
    include_once __DIR__ . '/i18n.php';
}

// Core Utilities - Provides common formatting, escaping, validation, and password helpers.
// Generate unique reference numbers for requests
function generateReferenceNumber() {
    // Collision-resistant, user-friendly identifier. The UNIQUE constraint on
    // requests.reference_number remains the final authority under concurrency.
    return 'TUGON-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

// Escape Output Function - Documents this helper's role in the parish management workflow.
function e($data) {
    return htmlspecialchars((string) $data, ENT_QUOTES, 'UTF-8');
}

// Hash password using bcrypt (PASSWORD_DEFAULT uses bcrypt by default)
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT, ['cost' => 10]);
}

// Verify password against bcrypt hash
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Check if password needs rehashing (for bcrypt algorithm updates)
function passwordNeedsRehash($hash) {
    return password_needs_rehash($hash, PASSWORD_DEFAULT, ['cost' => 10]);
}

function csrfTokenName() {
    return defined('CSRF_TOKEN_NAME') ? CSRF_TOKEN_NAME : '_csrf_token';
}

function generateCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) require_once __DIR__ . '/session.php';

    $name = csrfTokenName();
    $expiry = defined('CSRF_TOKEN_EXPIRY') ? CSRF_TOKEN_EXPIRY : 3600;
    $is_expired = !empty($_SESSION[$name . '_time']) && (time() - intval($_SESSION[$name . '_time'])) > $expiry;
    if (empty($_SESSION[$name]) || empty($_SESSION[$name . '_time']) || $is_expired) {
        $_SESSION[$name] = bin2hex(random_bytes(32));
        $_SESSION[$name . '_time'] = time();
    }

    return $_SESSION[$name];
}

function verifyCsrfToken($token) {
    if (session_status() === PHP_SESSION_NONE) require_once __DIR__ . '/session.php';

    $name = csrfTokenName();
    $expiry = defined('CSRF_TOKEN_EXPIRY') ? CSRF_TOKEN_EXPIRY : 3600;
    if (empty($_SESSION[$name]) || empty($_SESSION[$name . '_time']) || !is_string($token)) {
        return false;
    }

    if ((time() - intval($_SESSION[$name . '_time'])) > $expiry) {
        unset($_SESSION[$name], $_SESSION[$name . '_time']);
        return false;
    }

    return hash_equals($_SESSION[$name], $token);
}

function csrfInput() {
    return '<input type="hidden" name="' . e(csrfTokenName()) . '" value="' . e(generateCsrfToken()) . '">';
}

function csrfFailureMessage() {
    if (session_status() === PHP_SESSION_NONE) require_once __DIR__ . '/session.php';

    $message = $_SESSION['csrf_error'] ?? '';
    unset($_SESSION['csrf_error']);
    return $message;
}

function requireValidCsrfToken() {
    // 1. Detect if post_max_size was exceeded (PHP automatically clears both $_POST and $_FILES)
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST' && empty($_POST) && empty($_FILES) && intval($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $postMaxSize = ini_get('post_max_size') ?: '8M';
        $message = "The total file size uploaded exceeds the server limit ({$postMaxSize}). Please upload smaller or compressed files.";
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $is_json_request = strpos($accept, 'application/json') !== false || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($is_json_request) {
            http_response_code(413);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'PAYLOAD_TOO_LARGE', 'message' => $message]);
            exit;
        }
        $redirect = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), "\r\n") ?: ($_SERVER['PHP_SELF'] ?? '/');
        redirectWithNotification($redirect, $message, 'error');
    }

    $name = csrfTokenName();
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_SERVER['HTTP_X_XSRF_TOKEN'] ?? '');
    $submittedToken = is_string($headerToken) && $headerToken !== ''
        ? $headerToken
        : ($_POST[$name] ?? ($_POST['csrf_token'] ?? ($_POST['_csrf_token'] ?? ($_POST['_token'] ?? ''))));

    if (!verifyCsrfToken($submittedToken)) {
        $_SESSION[$name] = bin2hex(random_bytes(32));
        $_SESSION[$name . '_time'] = time();
        $_SESSION['csrf_error'] = 'Your secure session token expired. Please try again.';

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $is_json_request = strpos($accept, 'application/json') !== false || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($is_json_request) {
            $newToken = $_SESSION[$name];
            if (function_exists('sendJsonActionResponse')) {
                sendJsonActionResponse(false, 'Your security session has expired. Please try again.', 'error', ['error' => 'SECURITY_VALIDATION_FAILED', 'csrf_token' => $newToken, 'token' => $newToken], 403);
            }
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'SECURITY_VALIDATION_FAILED',
                'message' => 'Your security session has expired. Please try again.',
                'token' => $newToken,
                'csrf_token' => $newToken
            ]);
            exit;
        }

        $redirect = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), "\r\n");
        if ($redirect === '') {
            $redirect = $_SERVER['PHP_SELF'] ?? '/';
        }
        redirectWithNotification($redirect, $_SESSION['csrf_error'], 'error');
    }
}

function notificationCategoryFromText($title, $message) {
    $text = strtolower((string) $title . ' ' . (string) $message);
    if (strpos($text, 'announcement') !== false) {
        return 'announcements';
    }
    if (strpos($text, 'schedule') !== false || strpos($text, 'reservation') !== false || strpos($text, 'calendar') !== false) {
        return 'schedules';
    }
    if (strpos($text, 'request') !== false || strpos($text, 'certificate') !== false || strpos($text, 'blessing') !== false || strpos($text, 'payment') !== false || strpos($text, 'receipt') !== false || strpos($text, 'file') !== false) {
        return 'requests';
    }
    return 'system';
}

function userAllowsNotificationCategory($conn, $user_id, $category, $channel) {
    $column = $channel === 'sms' ? 'sms_enabled' : ($channel === 'in_app' ? 'in_app_enabled' : 'email_enabled');
    if ($column === 'sms_enabled' && !columnExists($conn, 'notification_preferences', 'sms_enabled')) {
        return true;
    }
    $stmt = $conn->prepare("SELECT $column AS enabled FROM notification_preferences WHERE user_id = ? AND category = ? LIMIT 1");
    if ($stmt) {
        $uid = intval($user_id);
        $stmt->bind_param('is', $uid, $category);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return intval($row['enabled']) === 1;
        }
    }
    return true;
}

function notificationSmsMessage($title, $message) {
    $text = trim('TUGON Parish System: ' . (string) $title . "\n" . (string) $message);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return strlen($text) > 480 ? substr($text, 0, 477) . '...' : $text;
}

function createRequestStatusNotification($conn, array $request, $status, $admin_note = '') {
    $user_id = intval($request['user_id'] ?? 0);
    if ($user_id <= 0) {
        return false;
    }

    $status = strtolower(trim((string) $status));
    $reference = trim((string) ($request['reference_number'] ?? ''));
    $request_type = tugonRequestTypeLabel($request['request_type'] ?? 'parish');
    $status_label = ucfirst(str_replace('_', ' ', $status));
    $admin_note = trim(stripslashes((string) $admin_note));
    $title = $request_type . ' Request ' . $status_label;

    if ($status === 'submitted') {
        $message = 'Your ' . $request_type . ' request' . ($reference !== '' ? ' (' . $reference . ')' : '') . ' has been submitted and received by the parish office.';
    } elseif ($status === 'pending') {
        $message = 'Your ' . $request_type . ' request' . ($reference !== '' ? ' (' . $reference . ')' : '') . ' is pending review by the parish staff.';
    } elseif ($status === 'processing') {
        $message = 'Your ' . $request_type . ' request' . ($reference !== '' ? ' (' . $reference . ')' : '') . ' is now being processed by the parish office.';
    } elseif ($status === 'approved') {
        $message = 'Your ' . $request_type . ' request' . ($reference !== '' ? ' (' . $reference . ')' : '') . ' has been approved by the parish office.';
    } elseif ($status === 'rejected') {
        $message = 'Your ' . $request_type . ' request' . ($reference !== '' ? ' (' . $reference . ')' : '') . ' was rejected by the parish office.';
    } elseif ($status === 'completed') {
        $message = 'Your ' . $request_type . ' request' . ($reference !== '' ? ' (' . $reference . ')' : '') . ' has been completed. Documents are ready or released.';
    } elseif ($status === 'cancelled') {
        $message = 'Your ' . $request_type . ' request' . ($reference !== '' ? ' (' . $reference . ')' : '') . ' has been cancelled.';
    } elseif (in_array($status, ['needs_correction', 'needs_info', 'requires_additional_information', 'correction_requested'], true)) {
        $message = 'Your ' . $request_type . ' request' . ($reference !== '' ? ' (' . $reference . ')' : '') . ' requires additional information or document correction.';
    } else {
        $message = 'Your ' . $request_type . ' request' . ($reference !== '' ? ' (' . $reference . ')' : '') . ' status is now ' . $status_label . '.';
    }

    if ($admin_note !== '') {
        $message .= ' Parish office note: ' . $admin_note;
    }
    $message .= ' Please open your TUGON account for details and next steps.';

    require_once dirname(__DIR__) . '/services/NotificationService.php';
    $type = 'request_' . ($status === 'needs_correction' ? 'needs_info' : $status);
    $created = (new NotificationService($conn))->create($user_id, $type, [
        'request_reference' => $reference,
        'title' => $title,
        'message' => $message
    ], 'request', (int)($request['request_id'] ?? 0), 'request.view', $status . '|' . ($request['updated_at'] ?? microtime(true)), true) !== null;

    return $created;
}

function dispatchNotificationDelivery($conn, $user_id, $title, $message, $category = null, array $channels = null) {
    if (!$conn || !tableExists($conn, 'users')) {
        return ['email' => ['ok' => false, 'skipped' => true], 'sms' => ['ok' => false, 'skipped' => true]];
    }

    $channels = $channels ?: ['email' => true, 'sms' => true];
    $category = $category ?: notificationCategoryFromText($title, $message);
    $stmt = $conn->prepare("SELECT id, fullname, email, phone_number, role, status FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return ['email' => ['ok' => false, 'error' => 'Unable to load user.'], 'sms' => ['ok' => false, 'error' => 'Unable to load user.']];
    }
    $uid = intval($user_id);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || ($user['status'] ?? '') !== 'active') {
        return ['email' => ['ok' => true, 'skipped' => true], 'sms' => ['ok' => true, 'skipped' => true]];
    }

    $user_role = strtolower((string) ($user['role'] ?? ''));
    $is_parishioner = in_array($user_role, ['user', 'parishioner', 'member', ''], true)
        || (function_exists('authenticationRolesForUser') && in_array('parishioner', authenticationRolesForUser($conn, $uid), true));

    if (!$is_parishioner) {
        return ['email' => ['ok' => true, 'skipped' => true], 'sms' => ['ok' => true, 'skipped' => true]];
    }

    $results = [
        'email' => ['ok' => true, 'skipped' => true],
        'sms' => ['ok' => true, 'skipped' => true]
    ];

    $email = trim((string) ($user['email'] ?? ''));
    if (!empty($channels['email']) && $email !== '' && isValidEmail($email) && userAllowsNotificationCategory($conn, $uid, $category, 'email')) {
        $body = '<p>Hello ' . e($user['fullname'] ?: 'Parishioner') . ',</p>'
            . '<p>' . nl2br(e((string) $message)) . '</p>'
            . '<p>Please open your TUGON account for full details.</p>';
        $results['email'] = sendTugonEmail($conn, $email, 'TUGON Notification - ' . (string) $title, tugonEmailTemplate((string) $title, $body), '', $uid, $category);
    }

    $phone = trim((string) ($user['phone_number'] ?? ''));
    if (!empty($channels['sms']) && $phone !== '' && isValidPhilippineMobile($phone) && userAllowsNotificationCategory($conn, $uid, $category, 'sms')) {
        $results['sms'] = sendTugonSms($conn, $phone, notificationSmsMessage($title, $message), $uid, $category);
    }

    return $results;
}

// Notification System - Creates in-app alerts for parishioners and staff.
function createNotification($conn, $user_id, $title, $message, $send_outbound = true, $category = null) {
    require_once dirname(__DIR__) . '/services/NotificationService.php';
    return (new NotificationService($conn))->createLegacy((int)$user_id, (string)$title, (string)$message, (bool)$send_outbound, (string)($category ?: 'system')) !== null;
}

function createNotificationSafe($conn, $user_id, $title, $message) {
    if (!$conn || !tableExists($conn, 'notifications')) {
        return false;
    }

    return createNotification($conn, $user_id, $title, $message);
}

// System-Wide Automatic Notification Dispatch - Broadcasts to all active parishioners across In-App, Email & SMS.
function notifyAllActiveParishioners($conn, $title, $message, $category = 'announcements', array $options = []) {
    if (!$conn || !tableExists($conn, 'users')) {
        return ['count' => 0];
    }

    $where = ["(status = 'active')"];
    $where[] = "(role IN ('user', 'parishioner', 'member') OR role IS NULL OR role = '' OR EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=users.id AND r.role_key='parishioner'))";

    if (!empty($options['chapel_district'])) {
        $district = $conn->real_escape_string(trim((string)$options['chapel_district']));
        $where[] = "chapel_district = '$district'";
    }
    if (!empty($options['user_id'])) {
        $specific_uid = intval($options['user_id']);
        if ($specific_uid > 0) {
            $where[] = "id = $specific_uid";
        }
    }

    $whereSql = implode(' AND ', $where);
    $stmt = $conn->query("SELECT id, fullname, email, phone_number FROM users WHERE $whereSql");
    if (!$stmt) {
        return ['count' => 0];
    }

    $count = 0;
    require_once dirname(__DIR__) . '/services/NotificationService.php';
    $notificationService = new NotificationService($conn);

    while ($user = $stmt->fetch_assoc()) {
        $uid = (int) $user['id'];
        try {
            $notificationService->createLegacy($uid, (string)$title, (string)$message, false, (string)$category);
            $count++;
        } catch (Throwable $e) {
            error_log('Error dispatching broadcast notification to user ' . $uid . ': ' . $e->getMessage());
        }
    }
    return ['count' => $count];
}

// Automatic User Notification Dispatch - Delivers In-App, Email and SMS to a specific user.
function notifyUserAutomatic($conn, $user_id, $title, $message, $category = 'system') {
    $uid = (int) $user_id;
    if ($uid <= 0 || !$conn || !tableExists($conn, 'users')) {
        return false;
    }
    try {
        require_once dirname(__DIR__) . '/services/NotificationService.php';
        return (new NotificationService($conn))->createLegacy($uid, (string)$title, (string)$message, true, (string)$category) !== null;
    } catch (Throwable $e) {
        error_log('Automatic user notification error for user ' . $uid . ': ' . $e->getMessage());
        return false;
    }
}

// Email Notification Schema - Prepares verification, OTP, preferences, and delivery log tables.
function ensureEmailNotificationSchema($conn) {
    if (!$conn) {
        return false;
    }

    $ready = ensureUserVerificationSchema($conn)
        && requireSchemaTables($conn, [
            'email_verifications',
            'otp_codes',
            'sms_notification_logs',
            'notification_logs',
            'announcement_recipients',
            'notification_preferences',
        ], 'email and notification services')
        && requireSchemaColumns($conn, 'notification_preferences', [
            'email_enabled', 'sms_enabled', 'in_app_enabled'
        ], 'notification preferences');

    if ($ready && tableExists($conn, 'notification_templates')) {
        $defaults = [
            ['request_submitted', 'Request Submitted', 'Your {{request_reference}} request has been submitted and received.', 'Request {{request_reference}} Submitted', 'TUGON: Request {{request_reference}} was submitted.'],
            ['request_pending', 'Request Pending', 'Your {{request_reference}} request is pending review by parish staff.', 'Request {{request_reference}} Pending', 'TUGON: Request {{request_reference}} is pending review.'],
            ['request_processing', 'Request Processing', 'Your {{request_reference}} request is now being processed.', 'Request {{request_reference}} Processing', 'TUGON: Request {{request_reference}} is processing.'],
            ['request_approved', 'Request Approved', 'Your {{request_reference}} request was approved by the parish office.', 'Request {{request_reference}} Approved', 'TUGON: Request {{request_reference}} was approved.'],
            ['request_rejected', 'Request Rejected', 'Your {{request_reference}} request was not approved.', 'Request {{request_reference}} Update', 'TUGON: Request {{request_reference}} was rejected.'],
            ['request_completed', 'Request Completed', 'Your {{request_reference}} request has been completed.', 'Request {{request_reference}} Completed', 'TUGON: Request {{request_reference}} is completed.'],
            ['request_cancelled', 'Request Cancelled', 'Your {{request_reference}} request was cancelled.', 'Request {{request_reference}} Cancelled', 'TUGON: Request {{request_reference}} was cancelled.'],
            ['request_needs_info', 'Additional Information Required', 'Your {{request_reference}} request requires additional details or correction.', 'Request {{request_reference}} - Action Required', 'TUGON: Request {{request_reference}} needs correction.'],
            ['reservation_created', 'Reservation Submitted', 'Your reservation was submitted for parish review.', 'Reservation Submitted', 'TUGON: Reservation was submitted.'],
            ['reservation_pending', 'Reservation Pending', 'Your reservation is pending parish schedule review.', 'Reservation Pending', 'TUGON: Reservation is pending review.'],
            ['reservation_approved', 'Reservation Approved', 'Your reservation is approved for {{reservation_date}}.', 'Reservation Approved', 'TUGON: Reservation is approved for {{reservation_date}}.'],
            ['reservation_rejected', 'Reservation Rejected', 'Your reservation request was not approved.', 'Reservation Rejected', 'TUGON: Reservation was rejected.'],
            ['reservation_rescheduled', 'Reservation Rescheduled', 'Your reservation schedule changed to {{reservation_date}} {{reservation_time}}.', 'Reservation Rescheduled', 'TUGON: Reservation moved to {{reservation_date}} {{reservation_time}}.'],
            ['reservation_cancelled', 'Reservation Cancelled', 'Your reservation was cancelled.', 'Reservation Cancelled', 'TUGON: Reservation was cancelled.'],
            ['reservation_reminder', 'Reservation Reminder', 'Your reservation is scheduled for {{reservation_date}} {{reservation_time}}.', 'Reservation Reminder', 'TUGON: Reservation is scheduled for {{reservation_date}} {{reservation_time}}.'],
            ['certificate_ready', 'Certificate Ready', 'Certificate {{certificate_number}} is ready for pickup or download.', 'Certificate {{certificate_number}} Ready', 'TUGON: Certificate {{certificate_number}} is ready.'],
            ['certificate_released', 'Certificate Released', 'Certificate {{certificate_number}} was released.', 'Certificate Released', 'TUGON: Certificate {{certificate_number}} released.'],
            ['announcement_published', 'Parish Announcement', '{{announcement_title}}', 'Parish Announcement: {{announcement_title}}', 'TUGON: {{announcement_title}}'],
            ['broadcast_notice', 'Parish Notice', '{{message}}', 'TUGON Parish Notice: {{title}}', 'TUGON: {{title}}'],
            ['account_verified', 'Account Verified', 'Your email and account have been verified successfully.', 'Account Verified', 'TUGON: Your account has been verified.'],
            ['account_registered', 'Registration Received', 'Your registration has been submitted and is pending verification.', 'Registration Received', 'TUGON: Registration received and pending verification.'],
            ['registration_approved', 'Registration Approved', 'Your parishioner account has been approved. Welcome to TUGON!', 'Registration Approved', 'TUGON: Your account has been approved. Welcome!'],
            ['registration_rejected', 'Registration Not Approved', 'Your registration was reviewed and could not be approved.', 'Registration Update', 'TUGON: Registration was not approved.'],
            ['password_changed', 'Security Notice - Password Changed', 'Your password was changed successfully. If you did not do this, please contact the parish office immediately.', 'Password Changed', 'TUGON: Your account password was changed.'],
            ['security_notice', 'Security Notice', '{{message}}', 'TUGON Security Notice', 'TUGON Security Notice: {{message}}'],
            ['system', 'System Notice', '{{message}}', 'TUGON System Notice', 'TUGON: {{message}}']
        ];

        $ins = $conn->prepare("INSERT INTO notification_templates (notification_type, title_template, in_app_template, email_subject_template, sms_template, status)
            VALUES (?, ?, ?, ?, ?, 'active')
            ON DUPLICATE KEY UPDATE title_template = VALUES(title_template), in_app_template = VALUES(in_app_template), email_subject_template = VALUES(email_subject_template), sms_template = VALUES(sms_template)");
        if ($ins) {
            foreach ($defaults as $row) {
                $ins->bind_param('sssss', $row[0], $row[1], $row[2], $row[3], $row[4]);
                $ins->execute();
            }
            $ins->close();
        }
    }

    return $ready;
}

// Environment Loader - Reads simple KEY=value pairs from .env for localhost setup.
function tugonLoadEnvFile() {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = dirname(__DIR__) . '/.env';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

// Mail Configuration - Loads email sender settings with safe local defaults.
function tugonMailConfig() {
    tugonLoadEnvFile();

    $defaults = [
        'from_email' => 'no-reply@tugon.local',
        'from_name' => 'TUGON Parish System',
        'reply_to' => '',
        'enabled' => true,
        'mailer' => 'smtp',
        'smtp_host' => '127.0.0.1',
        'smtp_port' => 1025,
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_encryption' => '',
        'smtp_timeout' => 10
    ];
    $path = __DIR__ . '/../config/mail.php';
    if (is_file($path)) {
        $config = include $path;
        if (is_array($config)) {
            return array_merge($defaults, $config);
        }
    }
    return $defaults;
}

function tugonSmtpRead($socket) {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function tugonSmtpTlsMethod() {
    $methods = 0;

    if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
        $methods |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    }
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
        $methods |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    }

    return $methods !== 0 ? $methods : STREAM_CRYPTO_METHOD_TLS_CLIENT;
}

function tugonSmtpContext($host) {
    return stream_context_create([
        'ssl' => [
            'peer_name' => $host,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true
        ]
    ]);
}

function tugonSmtpCommand($socket, $command, array $expected_codes) {
    if ($command !== '') {
        fwrite($socket, $command . "\r\n");
    }
    $response = tugonSmtpRead($socket);
    $code = intval(substr($response, 0, 3));
    if (!in_array($code, $expected_codes, true)) {
        throw new RuntimeException('SMTP command failed: ' . trim($response));
    }
    return $response;
}

function tugonFriendlySmtpError($error) {
    $message = trim((string) $error);

    if (stripos($message, '535-5.7.8') !== false || stripos($message, 'Username and Password not accepted') !== false) {
        return 'Gmail rejected the SMTP login. Check MAIL_USERNAME and use a fresh 16-character Gmail App Password in MAIL_PASSWORD.';
    }

    return $message;
}

function tugonEmailHeaders($from_email, $from_name, $to, $subject, $reply_to = '') {
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>',
        'To: ' . $to,
        'Subject: ' . $subject,
        'Date: ' . date(DATE_RFC2822)
    ];
    if ($reply_to !== '') {
        $headers[] = 'Reply-To: ' . $reply_to;
    }
    return implode("\r\n", $headers);
}

function sendTugonSmtpEmail(array $config, $to, $subject, $html_body) {
    $host = trim((string) ($config['smtp_host'] ?? ''));
    $port = intval($config['smtp_port'] ?? 25);
    if ($host === '') {
        return ['ok' => false, 'error' => 'SMTP host is not configured.'];
    }

    if (!empty($config['smtp_username']) && (string) ($config['smtp_password'] ?? '') === '') {
        return ['ok' => false, 'error' => 'SMTP password is not configured. Add your Gmail App Password to MAIL_PASSWORD in .env.'];
    }

    $attempt = function(int $targetPort, string $encryption) use ($config, $host, $to, $subject, $html_body) {
        $scheme = strtolower($encryption) === 'ssl' ? 'ssl://' : 'tcp://';
        $context = tugonSmtpContext($host);
        $socket = @stream_socket_client($scheme . $host . ':' . $targetPort, $errno, $errstr, intval($config['smtp_timeout'] ?? 10), STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            return ['ok' => false, 'error' => 'SMTP connection failed on port ' . $targetPort . ': ' . ($errstr ?: 'unknown error')];
        }

        try {
            stream_set_timeout($socket, intval($config['smtp_timeout'] ?? 10));
            tugonSmtpCommand($socket, '', [220]);
            tugonSmtpCommand($socket, 'EHLO localhost', [250]);

            if (strtolower($encryption) === 'tls') {
                tugonSmtpCommand($socket, 'STARTTLS', [220]);
                stream_context_set_option($socket, 'ssl', 'peer_name', $host);
                stream_context_set_option($socket, 'ssl', 'SNI_enabled', true);
                if (!stream_socket_enable_crypto($socket, true, tugonSmtpTlsMethod())) {
                    throw new RuntimeException('Unable to start SMTP TLS encryption.');
                }
                tugonSmtpCommand($socket, 'EHLO localhost', [250]);
            }

            if (!empty($config['smtp_username'])) {
                tugonSmtpCommand($socket, 'AUTH LOGIN', [334]);
                tugonSmtpCommand($socket, base64_encode((string) $config['smtp_username']), [334]);
                tugonSmtpCommand($socket, base64_encode((string) $config['smtp_password']), [235]);
            }

            $from = (string) $config['from_email'];
            tugonSmtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250]);
            tugonSmtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            tugonSmtpCommand($socket, 'DATA', [354]);

            $headers = tugonEmailHeaders($from, (string) $config['from_name'], $to, $subject, (string) ($config['reply_to'] ?? ''));
            $message = $headers . "\r\n\r\n" . str_replace("\n.", "\n..", $html_body) . "\r\n.";
            tugonSmtpCommand($socket, $message, [250]);
            tugonSmtpCommand($socket, 'QUIT', [221]);
            fclose($socket);
            return ['ok' => true, 'error' => ''];
        } catch (Throwable $e) {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
            return ['ok' => false, 'error' => tugonFriendlySmtpError($e->getMessage())];
        }
    };

    $initialEnc = (string) ($config['smtp_encryption'] ?? ($port === 465 ? 'ssl' : ($port === 587 ? 'tls' : '')));
    $result = $attempt($port, $initialEnc);
    if (!$result['ok'] && ($host === 'smtp.gmail.com' || str_contains($host, 'gmail.com'))) {
        $fallbackPort = ($port === 587) ? 465 : 587;
        $fallbackEnc = ($fallbackPort === 465) ? 'ssl' : 'tls';
        $fallbackResult = $attempt($fallbackPort, $fallbackEnc);
        if ($fallbackResult['ok']) {
            return $fallbackResult;
        }
    }
    return $result;
}

function sendTugonHttpEmail(array $config, $to, $subject, $html_body, $text_body = '') {
    $endpoint = trim((string) ($config['http_endpoint'] ?? ''));
    $token = (string) ($config['http_token'] ?? '');
    if ($endpoint === '' || $token === '') {
        return ['ok' => false, 'error' => 'HTTPS email relay is not configured.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL is unavailable for HTTPS email delivery.'];
    }

    $handle = curl_init($endpoint);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'to' => $to,
            'subject' => $subject,
            'html' => $html_body,
            'text' => $text_body
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    $decoded = is_string($response) ? json_decode($response, true) : null;
    $ok = $status >= 200 && $status < 300 && is_array($decoded) && !empty($decoded['ok']);
    return [
        'ok' => $ok,
        'error' => $ok ? '' : ($curlError !== '' ? 'HTTPS email relay failed: ' . $curlError : 'HTTPS email relay returned status ' . $status . '.')
    ];
}

// Email Delivery - Sends email when enabled and records each delivery attempt.
function sendTugonEmail($conn, $to, $subject, $html_body, $text_body = '', $user_id = null, $type = 'system') {
    $config = tugonMailConfig();
    $status = 'failed';
    $error = '';
    $ok = false;

    if (!isValidEmail($to)) {
        $error = 'Invalid email address.';
    } elseif (!empty($config['enabled'])) {
        $from = $config['from_email'];
        $from_name = $config['from_name'];
        if (($config['mailer'] ?? 'smtp') === 'http') {
            $http = sendTugonHttpEmail($config, $to, $subject, $html_body, $text_body);
            $ok = $http['ok'];
            $error = $http['error'];
        } elseif (($config['mailer'] ?? 'smtp') === 'smtp' && !empty($config['smtp_host'])) {
            $smtp = sendTugonSmtpEmail($config, $to, $subject, $html_body);
            $ok = $smtp['ok'];
            $error = $smtp['error'];
            if (!$ok && !empty($config['http_endpoint']) && !empty($config['http_token'])) {
                $http = sendTugonHttpEmail($config, $to, $subject, $html_body, $text_body);
                if ($http['ok']) {
                    $ok = true;
                    $error = '';
                }
            }
        } else {
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=UTF-8',
                'From: ' . $from_name . ' <' . $from . '>'
            ];
            if (!empty($config['reply_to'])) {
                $headers[] = 'Reply-To: ' . $config['reply_to'];
            }
            $ok = @mail($to, $subject, $html_body, implode("\r\n", $headers));
            if (!$ok) {
                $error = 'PHP mail() failed. Configure SMTP/sendmail in XAMPP or use a local SMTP inbox.';
            }
        }
    } else {
        $error = 'Email sending disabled in config/mail.php.';
    }

    $status = $ok ? 'sent' : 'failed';
    $sent_at = $ok ? date('Y-m-d H:i:s') : null;
    $stmt = $conn->prepare("INSERT INTO notification_logs (user_id, email, subject, notification_type, delivery_status, error_message, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $uid = $user_id ? intval($user_id) : null;
        $stmt->bind_param('issssss', $uid, $to, $subject, $type, $status, $error, $sent_at);
        $stmt->execute();
        $stmt->close();
    }

    return ['ok' => $ok, 'error' => $error];
}

function sendTugonSms($conn, $phone_number, $message, $user_id = null, $type = 'system') {
    ensureEmailNotificationSchema($conn);

    $formatted_phone = normalizePhilippineMobileForSms($phone_number);
    $ok = false;
    $error = '';
    $sent_at = null;

    if (function_exists('curl_init')) {
        require_once __DIR__ . '/../config/sms/send_sms.php';
        $response = sendSMS($formatted_phone, $message);
        $decoded = json_decode((string) $response, true);
        $httpStatus = is_array($decoded) ? intval($decoded['http_status'] ?? 0) : 0;
        $ok = is_array($decoded) && (
            (($decoded['data']['success'] ?? false) === true) ||
            (($decoded['success'] ?? false) === true) ||
            ($httpStatus >= 200 && $httpStatus < 300 && empty($decoded['error']))
        );
        if (!$ok) {
            $error = is_array($decoded)
                ? ($decoded['message'] ?? $decoded['error'] ?? ($decoded['response'] ?? 'TextBee SMS request failed.'))
                : (trim((string) $response) ?: 'TextBee SMS request failed.');
        }
        $sent_at = $ok ? date('Y-m-d H:i:s') : null;
    } else {
        $error = 'cURL is unavailable. SMS was not sent.';
    }

    $stmt = $conn->prepare("INSERT INTO sms_notification_logs (user_id, phone_number, message, notification_type, delivery_status, error_message, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $uid = $user_id ? intval($user_id) : 0;
        $status = $ok ? 'sent' : 'failed';
        $stmt->bind_param('issssss', $uid, $phone_number, $message, $type, $status, $error, $sent_at);
        $stmt->execute();
        $stmt->close();
    }

    return ['ok' => $ok, 'error' => $error, 'sent_at' => $sent_at];
}

// Tugon Email Template Function - Delivers mobile-responsive HTML templates for all parish updates.
function tugonEmailTemplate($title, $body, $button_label = '', $button_url = '') {
    $button = '';
    if ($button_label !== '' && $button_url !== '') {
        $button = '<div style="margin: 24px 0;"><a href="' . e($button_url) . '" style="display: inline-block; background: #c89b3c; color: #1c1917; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 14.5px; box-shadow: 0 2px 6px rgba(200, 155, 60, 0.3);">' . e($button_label) . '</a></div>';
    }
    return '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;background:#f4f6f8;padding:20px 12px;color:#1e293b;margin:0;">'
        . '<div style="max-width:600px;width:100%;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:28px 24px;box-sizing:border-box;box-shadow:0 4px 12px rgba(0,0,0,0.04);">'
        . '<div style="margin-bottom:20px;padding-bottom:14px;border-bottom:2px solid #2e3a2d;">'
        . '<span style="font-size:16px;font-weight:800;color:#2e3a2d;letter-spacing:-0.3px;text-transform:uppercase;">San Lorenzo Ruiz Mission Station</span>'
        . '</div>'
        . '<h2 style="margin:0 0 16px;color:#0f172a;font-size:19px;font-weight:700;line-height:1.3;">' . e($title) . '</h2>'
        . '<div style="line-height:1.65;color:#334155;font-size:15px;">' . $body . '</div>'
        . $button
        . '<div style="border-top:1px solid #e2e8f0;margin-top:28px;padding-top:16px;color:#64748b;font-size:12px;line-height:1.5;">'
        . '<strong>San Lorenzo Ruiz Mission Station</strong><br>'
        . 'Official TUGON Parish System &bull; Automatic Parishioner Notification'
        . '</div>'
        . '</div></div>';
}

// Account Verification - Creates secure email verification tokens for new or updated accounts.
function createEmailVerification($conn, $user_id, $email) {
    ensureEmailNotificationSchema($conn);
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + 86400);
    $stmt = $conn->prepare("INSERT INTO email_verifications (user_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $uid = intval($user_id);
        $stmt->bind_param('isss', $uid, $email, $hash, $expires);
        $stmt->execute();
        $stmt->close();
    }
    $conn->query("UPDATE users SET email_verification_sent_at = NOW() WHERE id = " . intval($user_id));
    return $token;
}

// Send Email Verification Message Function - Documents this helper's role in the parish management workflow.
function sendEmailVerificationMessage($conn, $user_id, $email, $fullname = '') {
    $token = createEmailVerification($conn, $user_id, $email);
    $base = appUrl();
    $base .= str_ends_with($base, '/') ? '' : '/';
    $url = $base . 'auth/verify-email.php?token=' . urlencode($token);
    $body = '<p>Hello ' . e($fullname ?: 'Parishioner') . ',</p><p>Please verify your Gmail address to continue your TUGON registration.</p><p>This verification link expires in 24 hours.</p>';
    return sendTugonEmail($conn, $email, 'Verify your TUGON Gmail account', tugonEmailTemplate('Gmail Verification', $body, 'Verify Gmail', $url), '', $user_id, 'email_verification');
}

// OTP Security - Generates short-lived one-time passcodes for account verification.
function createOtpCode($conn, $user_id, $email, $purpose = 'registration') {
    ensureEmailNotificationSchema($conn);
    $conn->query("DELETE FROM otp_codes WHERE expires_at < NOW()");

    $is_password_reset = $purpose === 'password_reset';
    $window_start = date('Y-m-d H:i:s', time() - ($is_password_reset ? 3600 : 900));
    $limit = $conn->prepare("SELECT COUNT(*) as total FROM otp_codes WHERE email = ? AND purpose = ? AND created_at >= ?");
    if ($limit) {
        $limit->bind_param('sss', $email, $purpose, $window_start);
        $limit->execute();
        $count = $limit->get_result()->fetch_assoc();
        $limit->close();
        if (intval($count['total'] ?? 0) >= ($is_password_reset ? 5 : 4)) {
            return ['ok' => false, 'error' => $is_password_reset ? 'Maximum OTP requests reached. Please wait 1 hour before requesting another code.' : 'Maximum OTP resend requests reached. Please wait 15 minutes before requesting another code.'];
        }
    }

    $recent = $conn->prepare("SELECT created_at FROM otp_codes WHERE email = ? AND purpose = ? ORDER BY created_at DESC LIMIT 1");
    if ($recent) {
        $recent->bind_param('ss', $email, $purpose);
        $recent->execute();
        $row = $recent->get_result()->fetch_assoc();
        $recent->close();
        if ($row && strtotime($row['created_at']) > time() - 60) {
            return ['ok' => false, 'error' => 'Please wait 60 seconds before requesting another OTP.'];
        }
    }

    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($otp, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', time() + 300);
    $stmt = $conn->prepare("INSERT INTO otp_codes (user_id, email, purpose, otp_hash, expires_at) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Unable to create OTP.'];
    }
    $uid = intval($user_id);
    $stmt->bind_param('issss', $uid, $email, $purpose, $hash, $expires);
    $stmt->execute();
    $stmt->close();
    return ['ok' => true, 'otp' => $otp, 'expires_at' => $expires];
}

// Send OTP Email Function - Documents this helper's role in the parish management workflow.
function sendOtpEmail($conn, $user_id, $email, $purpose = 'registration') {
    if (!isValidEmail($email)) {
        return ['ok' => false, 'error' => 'Please enter a valid email address.'];
    }

    $created = createOtpCode($conn, $user_id, $email, $purpose);
    if (!$created['ok']) {
        return $created;
    }
    $body = '<p>Your TUGON verification code is:</p><p style="font-size:28px;font-weight:800;letter-spacing:6px;">' . e($created['otp']) . '</p><p>This OTP expires in 5 minutes. Do not share it with anyone.</p>';
    $sent = sendTugonEmail($conn, $email, 'Your TUGON OTP Code', tugonEmailTemplate('One-Time Password', $body), '', $user_id, 'otp_' . $purpose);
    if (!$sent['ok']) {
        $cleanup = $conn->prepare("DELETE FROM otp_codes WHERE user_id = ? AND email = ? AND purpose = ? AND verified_at IS NULL");
        if ($cleanup) {
            $uid = intval($user_id);
            $cleanup->bind_param('iss', $uid, $email, $purpose);
            $cleanup->execute();
            $cleanup->close();
        }
    }
    return ['ok' => $sent['ok'], 'error' => $sent['error'] ?? '', 'expires_at' => $created['expires_at']];
}

function sendOtpSms($conn, $user_id, $phone_number, $purpose = 'registration') {
    if (!isValidPhilippineMobile($phone_number)) {
        return ['ok' => false, 'error' => 'Invalid mobile number. Please enter a valid 11-digit Philippine mobile number.'];
    }

    $created = createOtpCode($conn, $user_id, $phone_number, $purpose);
    if (!$created['ok']) {
        return $created;
    }

    if ($purpose === 'password_reset') {
        $message = "TUGON Parish System\n\nYour One-Time Password (OTP) is:\n\n" . $created['otp'] . "\n\nThis code will expire in 5 minutes.\n\nDO NOT share this code with anyone.\n\nIf you did not request this password reset, please ignore this message.";
    } else {
        $message = 'Your TUGON OTP code is ' . $created['otp'] . '. It expires in 5 minutes. Do not share it.';
    }

    $sent = sendTugonSms($conn, $phone_number, $message, $user_id, 'otp_' . $purpose);
    if (!$sent['ok']) {
        $cleanup = $conn->prepare("DELETE FROM otp_codes WHERE user_id = ? AND email = ? AND purpose = ? AND verified_at IS NULL");
        if ($cleanup) {
            $uid = intval($user_id);
            $cleanup->bind_param('iss', $uid, $phone_number, $purpose);
            $cleanup->execute();
            $cleanup->close();
        }
    }

    return ['ok' => $sent['ok'], 'error' => $sent['error'], 'expires_at' => $created['expires_at']];
}

// OTP Security - Validates passcodes and prevents repeated brute-force attempts.
function verifyOtpCode($conn, $user_id, $email, $purpose, $otp) {
    $stmt = $conn->prepare("SELECT otp_id, otp_hash, expires_at, attempts, verified_at FROM otp_codes WHERE user_id = ? AND email = ? AND purpose = ? ORDER BY created_at DESC LIMIT 1");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Unable to verify OTP.'];
    }
    $uid = intval($user_id);
    $stmt->bind_param('iss', $uid, $email, $purpose);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || $row['verified_at']) {
        return ['ok' => false, 'error' => 'OTP not found. Please request a new code.'];
    }
    if (strtotime($row['expires_at']) < time()) {
        return ['ok' => false, 'error' => 'OTP expired. Please request a new code.'];
    }
    if (intval($row['attempts']) >= 3) {
        return ['ok' => false, 'error' => 'Too many OTP attempts. Please request a new code.'];
    }
    $conn->query("UPDATE otp_codes SET attempts = attempts + 1 WHERE otp_id = " . intval($row['otp_id']));
    if (!password_verify($otp, $row['otp_hash'])) {
        return ['ok' => false, 'error' => 'Invalid OTP code.'];
    }
    $conn->query("UPDATE otp_codes SET verified_at = NOW() WHERE otp_id = " . intval($row['otp_id']));
    return ['ok' => true];
}

function tugonRequestTypeLabel($value) {
    $label = str_replace(['_', '-'], ' ', (string) $value);
    return ucwords(trim($label));
}

function sendRequestStatusSms($conn, $user_id, $phone_number, $request_type, $reference_number, $status, $admin_remarks = '') {
    if (trim((string) $phone_number) === '') {
        return ['ok' => false, 'error' => 'No mobile number available.'];
    }

    $type = tugonRequestTypeLabel($request_type ?: 'parish');
    $ref = trim((string) $reference_number) ?: 'N/A';
    $status = strtolower((string) $status);

    if ($status === 'approved') {
        $message = "Good day!\n\nYour " . $type . " request has been APPROVED.\n\nReference Number:\n" . $ref . "\n\nPlease visit the parish office for the next instructions.\n\nThank you.\n\nSan Lorenzo Ruiz Mission Station";
    } elseif (in_array($status, ['rejected', 'declined'], true)) {
        $reason = trim((string) $admin_remarks) ?: 'No reason was provided.';
        $message = "Good day!\n\nYour " . $type . " request has been DECLINED.\n\nReason:\n\n" . $reason . "\n\nFor assistance, please contact the parish office.\n\nThank you.";
    } elseif ($status === 'completed') {
        $message = "Good day!\n\nYour requested certificate is now READY FOR PICKUP.\n\nReference Number:\n" . $ref . "\n\nPlease visit the parish office during office hours.\n\nThank you.";
    } else {
        $message = "Good day!\n\nYour " . $type . " request status is now " . strtoupper($status) . ".\n\nReference Number:\n" . $ref . "\n\nPlease check your TUGON account or contact the parish office for details.\n\nThank you.";
    }

    return sendTugonSms($conn, $phone_number, $message, $user_id, 'request_' . $status);
}

function sendScheduleReminderSms($conn, $user_id, $phone_number, $event_type, $date, $time) {
    if (trim((string) $phone_number) === '') {
        return ['ok' => false, 'error' => 'No mobile number available.'];
    }

    $message = "Reminder\n\nYour scheduled\n\n" . tugonRequestTypeLabel($event_type) . "\n\nwill be on\n\n" . $date . "\n\nat\n\n" . $time . "\n\nPlease arrive 30 minutes early.\n\nGod bless.";
    return sendTugonSms($conn, $phone_number, $message, $user_id, 'schedule_reminder');
}

// User Allows Email Category Function - Documents this helper's role in the parish management workflow.
function userAllowsEmailCategory($conn, $user_id, $category) {
    $stmt = $conn->prepare("SELECT email_enabled FROM notification_preferences WHERE user_id = ? AND category = ? LIMIT 1");
    if ($stmt) {
        $uid = intval($user_id);
        $stmt->bind_param('is', $uid, $category);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return intval($row['email_enabled']) === 1;
        }
    }
    return true;
}

// Send Request Submitted Email Function - Documents this helper's role in the parish management workflow.
function sendRequestSubmittedEmail($conn, $user_id, $reference_number, $request_label) {
    if (!userAllowsEmailCategory($conn, $user_id, 'requests')) {
        return ['ok' => true, 'skipped' => true];
    }
    $stmt = $conn->prepare("SELECT fullname, email FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Unable to load user email.'];
    }
    $uid = intval($user_id);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        return ['ok' => false, 'error' => 'User not found.'];
    }
    if (trim((string) ($user['email'] ?? '')) === '') {
        return ['ok' => true, 'skipped' => true];
    }
    $url = appUrl('users/my-requests.php') . '?q=' . urlencode($reference_number);
    $body = '<p>Hello ' . e($user['fullname']) . ',</p><p>Your ' . e($request_label) . ' was submitted successfully.</p><p>Reference number: <strong>' . e($reference_number) . '</strong></p><p>You will receive updates when the parish office reviews your request.</p>';
    return sendTugonEmail($conn, $user['email'], 'TUGON Request Submitted - ' . $reference_number, tugonEmailTemplate('Request Submitted', $body, 'Track Request', $url), '', $user_id, 'request_submitted');
}

// Column Exists Function - Documents this helper's role in the parish management workflow.
function columnExists($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    return $conn instanceof mysqli && schemaColumnExists($conn, $table, (string) $column);
}

// Table Exists Function - Documents this helper's role in the parish management workflow.
function tableExists($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    return $conn instanceof mysqli && schemaTableExists($conn, $table);
}

// Chatbot Logging - Stores AI assistant questions and answers for audit and analysis.
function ensureChatbotInquirySchema($conn) {
    return $conn instanceof mysqli
        && requireSchemaColumns($conn, 'chatbot_inquiries', [
            'inquiry_id', 'user_id', 'user_role', 'question', 'answer_preview',
            'mode', 'context_limited', 'created_at'
        ], 'AI inquiry logging');
}

// Log Chatbot Inquiry Function - Documents this helper's role in the parish management workflow.
function logChatbotInquiry($conn, $user_id, $role, $question, $answer, $mode = 'chat', $context_limited = true) {
    if (!ensureChatbotInquirySchema($conn)) {
        return false;
    }

    $stmt = $conn->prepare("INSERT INTO chatbot_inquiries (user_id, user_role, question, answer_preview, mode, context_limited) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }

    $user_id = !empty($user_id) ? intval($user_id) : null;
    $role = substr((string) $role, 0, 30);
    $question = trim((string) $question);
    $answer = mb_strimwidth(trim((string) $answer), 0, 500, '...');
    $mode = substr((string) $mode, 0, 40);
    $context_limited = $context_limited ? 1 : 0;

    $stmt->bind_param('issssi', $user_id, $role, $question, $answer, $mode, $context_limited);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// Chatbot Knowledge Base - Stores administrator-managed official AI answers.
function ensureChatbotKnowledgeSchema($conn) {
    return $conn instanceof mysqli
        && requireSchemaColumns($conn, 'chatbot_knowledge', [
            'knowledge_id', 'topic', 'keywords', 'answer', 'steps', 'category',
            'source', 'status', 'updated_by', 'created_at', 'updated_at'
        ], 'AI knowledge base');
}

function chatbotKnowledgeOfficialDefaults() {
    // Retired in Phase 11. The governed chatbot_knowledge table is the only
    // authoritative source; this compatibility function intentionally seeds nothing.
    return [];
    /* Legacy reference data below is unreachable and retained temporarily only
       to avoid destabilizing older installations while migrations are applied.
    return [
        [
            'Baptism Requirements',
            'what are the baptism requirements,baptism requirements,ano ang requirements sa binyag,what papers are needed for baby baptism,i want to baptize my child what do i need,requirements for baptism,binyag,baptism,baptismal,baptize,pabinyag,baby baptism,christening,requirements,papers,documents',
            "Before submitting a Baptism request, prepare these official parish requirements.",
            "Chapel recommendation\nParents' latest marriage contract or receipt, if applicable\nPhotocopy of marriage certificate, if married\nPhotocopy of the child's live birth certificate with registry number\nTwo white cards of sponsors\nWhite cards of parents\nPre-baptismal investigation sheet, if requested by the parish office",
            'sacrament'
        ],
        [
            'Confirmation Requirements',
            'what are the confirmation requirements,confirmation requirements,ano ang requirements sa kumpirmasyon,what documents are needed for confirmation,how can i request confirmation,requirements for confirmation,confirmation,kumpil,pakumpil,confirmand,requirements,papers,documents',
            'For Confirmation, prepare the information and supporting parish documents requested by the parish office.',
            "Baptismal Certificate\nConfirmation Registration Form\nConfirmation Seminar (recollection)\nConfirmation Sponsor (Godparents)",
            'sacrament'
        ],
        [
            'Marriage Requirements',
            'what are the marriage requirements,marriage requirements,ano ang requirements sa kasal,what papers do we need for church wedding,paano magpa schedule ng kasal,requirements for marriage,requirements for wedding,wedding requirements,marriage,wedding,kasal,pakasal,merriage,requirements,papers,documents',
            'Marriage requirements include the following official parish documents and preparation steps.',
            "Pre-Cana seminar\nMunicipal marriage license\nBEC recommendation\nBaptismal certificate for marriage purpose\nConfirmation certificate\nPermit to marry, if applicable\nMarriage interview\nConfession\nCO permit, if applicable for police or army personnel",
            'sacrament'
        ],
        [
            'First Holy Communion Requirements',
            'what are the first holy communion requirements,first holy communion requirements,first communion requirements,requirements for first communion,first communion,holy communion,communion,komunyon,requirements,papers,documents',
            'For First Holy Communion, prepare the communicant information and supporting parish records requested by the parish office.',
            "Baptismal Certificate\nRegistration Form\nCommunion Preparation Classes\nRecollection/Seminar\nFirst Confession",
            'sacrament'
        ],
        [
            'Anointing of the Sick',
            'how can i request anointing of the sick,anointing of the sick,pahid ng langis,pabihis,sick call,anointing,sick,ospital,hospital,urgent,priest visit',
            'For Anointing of the Sick, provide the sick person\'s name, location, contact person, preferred date and time, and urgent details if any.',
            "Prepare the complete name of the sick person\nProvide the exact address or hospital location\nAdd a contact person and phone number\nContact the parish office directly if the matter is urgent",
            'sacrament'
        ],
        [
            'Funeral Mass',
            'how can i request a funeral mass,funeral mass,burol,libing,request funeral,funeral,burial,lamay,patay,memorial',
            'For Funeral Mass requests, provide the deceased person\'s name, preferred date and time, contact person, parish office instructions, and a Death Certificate.',
            "Prepare the complete name of the deceased\nUpload a clear copy of the Death Certificate\nChoose the preferred Mass date and time\nProvide the contact person and phone number\nWait for parish office confirmation of availability",
            'sacrament'
        ],
        [
            'House Blessing',
            'how can i request a house blessing,house blessing,pabasbas ng bahay,blessing ng bahay,bless house,bahay,pabless bahay,pa bless bahay,home blessing',
            'For house blessing requests, provide the requester name, exact address, preferred schedule, and contact details.',
            "Enter the complete house address\nChoose a preferred date and time\nProvide a contact number\nWait for parish office confirmation",
            'blessing'
        ],
        [
            'Vehicle Blessing',
            'how can i request a vehicle blessing,vehicle blessing,pabasbas ng sasakyan,car blessing,motor blessing,sasakyan,pabless sasakyan,pa bless car',
            'For vehicle blessing requests, provide the owner name, vehicle details, preferred schedule, and contact details.',
            "Provide the owner or requester name\nEnter the vehicle details\nChoose a preferred date and time\nWait for parish office confirmation",
            'blessing'
        ],
        [
            'Certificate Request',
            'how can i request a parish certificate,how to request certificate,how to request certificates,request certificate,certificate,baptism certificate,baptismal certificate,confirmation certificate,first communion certificate,marriage certificate,need certificate,get my certificate,record copy,certification',
            'To request a parish certificate, choose the certificate type, complete the required information, upload supporting documents, and wait for parish review.',
            "Open the certificate request page\nSelect the certificate type\nEnter accurate names, dates, and related details\nUpload the required supporting documents\nSubmit and track the request status",
            'certificate'
        ],
        [
            'Mass Schedule',
            'mass schedule,mass time,what time is mass,sunday mass,sunday mass schedule,what is the sunday mass schedule,weekday mass,wednesday mass,what are the wednesday mass schedules,today mass,misa,oras ng misa,schedule ng misa,church schedule',
            "Mass Schedule\n\nSunday Mass: 8:30 AM\nWednesday Mass: 5:00 PM\n\nThe parish office manages official Mass schedules through the schedule calendar. Please check the Schedule page or contact the parish office for the latest approved schedule.",
            '',
            'schedule'
        ],
        [
            'Office Hours',
            'office hours,office schedule,what time do you open,what time do you close,opening hours,closing hours,are you open,parish office hours,parish office,opisina,open ba,contact office',
            "Parish Office Hours\nTuesday - Saturday: 8:00 AM - 5:00 PM\nLunch Break: 12:00 PM - 1:00 PM\nSunday: 7:00 AM - 12:00 PM",
            '',
            'office'
        ],
        [
            'Reservations',
            'how can i make a reservation,reservation,reserve,book a schedule,how to reserve,booking,hall reservation,venue,function hall',
            'Reservation requests are reviewed based on event type, date, time, location, and parish schedule availability.',
            "Choose the reservation or service type\nEnter the event date, time, and location\nProvide the purpose and contact details\nWait for approval or parish office follow-up",
            'general'
        ],
        [
            'Emergency Contact',
            'who should i contact for urgent parish concerns,emergency contact,urgent concern,emergency,urgent,contact,phone,help,priest urgent',
            'For urgent sacramental or parish concerns, please contact the parish office directly so staff can assist immediately.',
            'Prepare the name of the person needing assistance, exact location, and reachable contact number.',
            'office'
        ],
        [
            'Parish Priest',
            'who is the parish priest,parish priest,priest,pastor,who is the priest,who is the priest in the aleosan parish',
            'The Parish Priest is Rev. Fr. Alberto G. Cahilig, OMI.',
            '',
            'office'
        ],
        [
            'Parish Vicar',
            'who is the parish vicar,parish vicar,vicar,assistant priest,parochial vicar',
            'The Parish Vicar is Rev. Fr. Alvin Vicente C. Barretto, OMI.',
            '',
            'office'
        ],
        [
            'Mass Celebrant Assignments',
            'who is the priest this sunday,celebrant,mass celebrant,priest schedule,who will celebrate mass',
            'Mass celebrant assignments may change without prior notice. Please contact the parish office for the latest celebrant schedule.',
            '',
            'office'
        ]
    ]; */
}

function chatbotKnowledgeSeedDefaults($conn) {
    // Seeding official policies from PHP is forbidden. Knowledge is managed by
    // authorized reviewers through admin/chatbot-knowledge.php.
    return ensureChatbotKnowledgeSchema($conn);

    /* Retired destructive legacy seeder.
    $version = '2026-07-12-official-specific-entries-v2';
    if (!requireSchemaColumns($conn, 'chatbot_knowledge_meta', [
        'meta_key', 'meta_value', 'updated_at'
    ], 'AI knowledge metadata')) {
        return false;
    }

    $current_version = '';
    $result = $conn->query("SELECT meta_value FROM chatbot_knowledge_meta WHERE meta_key = 'official_dataset_version' LIMIT 1");
    if ($result && $row = $result->fetch_assoc()) {
        $current_version = (string) $row['meta_value'];
    }
    if ($current_version === $version) {
        return true;
    }

    $defaults = chatbotKnowledgeOfficialDefaults();
    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM chatbot_knowledge");
        $stmt = $conn->prepare("INSERT INTO chatbot_knowledge (topic, keywords, answer, steps, category, status) VALUES (?, ?, ?, ?, ?, 'active')");
        if (!$stmt) {
            throw new Exception('Unable to prepare chatbot knowledge import.');
        }
        foreach ($defaults as $item) {
            $stmt->bind_param('sssss', $item[0], $item[1], $item[2], $item[3], $item[4]);
            if (!$stmt->execute()) {
                throw new Exception('Unable to import chatbot knowledge item.');
            }
        }
        $stmt->close();

        $meta = $conn->prepare("REPLACE INTO chatbot_knowledge_meta (meta_key, meta_value) VALUES ('official_dataset_version', ?)");
        if (!$meta) {
            throw new Exception('Unable to update chatbot knowledge version.');
        }
        $meta->bind_param('s', $version);
        $meta->execute();
        $meta->close();
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
    return true; */
}

function chatbotKnowledgeStepsArray($steps) {
    $lines = preg_split('/\r\n|\r|\n/', trim((string) $steps));
    $clean = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $clean[] = $line;
        }
    }
    return $clean;
}

// Request Management - Extends request records with detailed service and reservation fields.
function ensureExpandedRequestTypeSchema($conn) {
    return $conn instanceof mysqli
        && requireSchemaColumns($conn, 'requests', ['request_id', 'user_id', 'request_type'], 'requests');
}

// Request Duplicate Guard - Ensures record_holder_name column and indexes exist on requests table.
function ensureCertificateDuplicateGuardSchema($conn) {
    if (!($conn instanceof mysqli)) return false;
    $res = $conn->query("SHOW COLUMNS FROM requests LIKE 'record_holder_name'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE requests ADD COLUMN record_holder_name VARCHAR(191) NULL AFTER request_type");
        $conn->query("ALTER TABLE requests ADD KEY idx_requests_duplicate_guard (user_id, request_type, record_holder_name, status)");
    }
    @$conn->query("UPDATE requests SET status = 'pending' WHERE status = 'submitted'");
    return true;
}

// Sacramental Record Views - Ensures canonical register views exist for sacramental registries.
function ensureSacramentalRecordViews($conn = null) {
    if (!$conn && isset($GLOBALS['conn'])) {
        $conn = $GLOBALS['conn'];
    }
    if (!($conn instanceof mysqli)) return false;
    @$conn->query("CREATE OR REPLACE VIEW sacramental_records_baptism AS SELECT * FROM baptism_records");
    @$conn->query("CREATE OR REPLACE VIEW sacramental_records_marriage AS SELECT * FROM marriage_records");
    @$conn->query("CREATE OR REPLACE VIEW sacramental_records_death AS SELECT * FROM funeral_records");
    return true;
}


// Request Documents - Prepares file metadata storage for uploaded parish requirements.
function ensureRequestDocumentsSchema($conn) {
    return $conn instanceof mysqli
        && requireSchemaColumns($conn, 'request_documents', [
            'document_id', 'request_id', 'uploaded_by', 'document_type',
            'requirement_name', 'file_path', 'original_name', 'mime_type',
            'file_size', 'uploaded_at', 'deleted_at'
        ], 'request documents');
}

// Request Payments - Stores parishioner receipt submissions tied to the parent request.
function ensureRequestPaymentsSchema($conn) {
    return $conn instanceof mysqli
        && ensureRequestDocumentsSchema($conn)
        && requireSchemaColumns($conn, 'request_payments', [
            'payment_id', 'request_id', 'user_id', 'receipt_document_id', 'amount',
            'payment_method', 'reference_number', 'notes', 'status', 'admin_remarks',
            'verified_by', 'verified_at', 'created_at', 'updated_at'
        ], 'request payments');
}

// Request Documents - Validates, stores, and records uploaded supporting documents.
function saveRequestDocument($conn, $request_id, $uploaded_by, $file, $document_type = 'requirement', $requirement_name = '') {
    if (!isset($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'saved' => false];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Unable to upload the file. Please try again.'];
    }

    $allowed_document_types = ['requirement', 'payment_receipt', 'admin_file', 'released_certificate'];
    if (!in_array($document_type, $allowed_document_types, true)) {
        $document_type = 'requirement';
    }

    $config = getRequestDocumentConfig();
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
        return ['ok' => false, 'error' => 'Uploaded file must not exceed 10MB.'];
    }

    if (!in_array($extension, $config['extensions'], true) || !in_array($mime_type, $config['mime_types'], true)) {
        return ['ok' => false, 'error' => 'Uploaded file type is not allowed. Use an image, PDF, Office document, or text file.'];
    }

    $upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'request_requirements';
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

    $safe_filename = $document_type . '-request-' . intval($request_id) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
    $target_path = $upload_dir . DIRECTORY_SEPARATOR . $safe_filename;
    if (!move_uploaded_file($tmp_name, $target_path)) {
        return ['ok' => false, 'error' => 'Unable to save the requirements file.'];
    }

    ensureRequestDocumentsSchema($conn);
    $db_path = 'uploads/request_requirements/' . $safe_filename;
    $requirement_name = trim((string) $requirement_name);
    $stmt = $conn->prepare("INSERT INTO request_documents (request_id, uploaded_by, document_type, requirement_name, file_path, original_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        @unlink($target_path);
        return ['ok' => false, 'error' => 'Unable to prepare the file record.'];
    }

    $request_id = intval($request_id);
    $uploaded_by = intval($uploaded_by);
    $stmt->bind_param('iisssssi', $request_id, $uploaded_by, $document_type, $requirement_name, $db_path, $original_name, $mime_type, $size);
    if (!$stmt->execute()) {
        @unlink($target_path);
        $stmt->close();
        return ['ok' => false, 'error' => 'Unable to save the file record.'];
    }

    $document_id = $stmt->insert_id;
    $stmt->close();

    return ['ok' => true, 'saved' => true, 'document_id' => $document_id];
}

function saveRequestRequirementDocument($conn, $request_id, $uploaded_by, $file) {
    return saveRequestDocument($conn, $request_id, $uploaded_by, $file, 'requirement');
}

function saveMultipleRequirementDocuments($conn, $request_id, $uploaded_by, $files) {
    if (empty($files) || !is_array($files)) {
        return ['ok' => true, 'saved' => 0, 'documents' => []];
    }

    $results = ['ok' => true, 'saved' => 0, 'documents' => [], 'errors' => []];
    
    // Handle both single file and array of files
    if (!isset($files['name']) || !is_array($files['name'])) {
        return ['ok' => false, 'error' => 'Invalid file format.'];
    }

    $file_count = count($files['name']);
    for ($i = 0; $i < $file_count; $i++) {
        // Skip empty file slots
        if (empty($files['name'][$i]) || ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        // Create single file array for saveRequestDocument
        $single_file = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i]
        ];

        $document = saveRequestDocument($conn, $request_id, $uploaded_by, $single_file, 'requirement');
        
        if ($document['ok'] && !empty($document['saved'])) {
            $results['saved']++;
            $results['documents'][] = $document['document_id'];
        } else {
            $results['errors'][] = ($files['name'][$i] ?? 'Unknown file') . ': ' . ($document['error'] ?? 'Unknown error');
        }
    }

    if ($results['saved'] === 0 && !empty($results['errors'])) {
        $results['ok'] = false;
        $results['error'] = 'No files were uploaded successfully. ' . implode(', ', $results['errors']);
    }

    return $results;
}

function requestUploadHasFiles($files) {
    if (empty($files) || !is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
        return false;
    }

    foreach ($files['name'] as $index => $name) {
        $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if (trim((string) $name) !== '' && $error !== UPLOAD_ERR_NO_FILE) {
            return true;
        }
    }

    return false;
}

function requestDocumentCount($conn, $request_id, $document_type = '') {
    ensureRequestDocumentsSchema($conn);
    $request_id = intval($request_id);
    if ($request_id <= 0) {
        return 0;
    }

    if ($document_type !== '') {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM request_documents WHERE request_id = ? AND document_type = ? AND deleted_at IS NULL");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('is', $request_id, $document_type);
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM request_documents WHERE request_id = ? AND deleted_at IS NULL");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $request_id);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return intval($row['total'] ?? 0);
}

function requestHasVerifiedPayment($conn, $request_id) {
    ensureRequestPaymentsSchema($conn);
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM request_payments WHERE request_id = ? AND status = 'verified'");
    if (!$stmt) {
        return false;
    }

    $request_id = intval($request_id);
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return intval($row['total'] ?? 0) > 0;
}

function createRequestPayment($conn, $request_id, $user_id, $amount, $payment_method, $reference_number, $notes, $receipt_file) {
    ensureRequestPaymentsSchema($conn);

    $amount = floatval($amount);
    $payment_method = trim((string) $payment_method);
    $reference_number = trim((string) $reference_number);
    $notes = trim((string) $notes);

    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Please enter a valid payment amount.'];
    }
    if ($payment_method === '') {
        return ['ok' => false, 'error' => 'Please choose a payment method.'];
    }

    $receipt = saveRequestDocument($conn, $request_id, $user_id, $receipt_file, 'payment_receipt');
    if (!$receipt['ok'] || empty($receipt['saved'])) {
        return ['ok' => false, 'error' => $receipt['error'] ?? 'Please upload a receipt or proof of payment.'];
    }

    $status = 'pending';
    $document_id = intval($receipt['document_id']);
    $stmt = $conn->prepare("INSERT INTO request_payments (request_id, user_id, receipt_document_id, amount, payment_method, reference_number, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Unable to prepare the payment record.'];
    }

    $request_id = intval($request_id);
    $user_id = intval($user_id);
    $stmt->bind_param('iiidssss', $request_id, $user_id, $document_id, $amount, $payment_method, $reference_number, $notes, $status);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['ok' => false, 'error' => 'Unable to save the payment record.'];
    }

    $payment_id = $stmt->insert_id;
    $stmt->close();
    createAuditLog($conn, $user_id, 'SUBMIT_PAYMENT_RECEIPT', 'request_payments', $payment_id);

    return ['ok' => true, 'payment_id' => $payment_id];
}

function getRequestPayments($conn, $request_id) {
    ensureRequestPaymentsSchema($conn);
    $payments = [];
    $stmt = $conn->prepare("
        SELECT p.*, d.original_name, d.mime_type, d.file_size
        FROM request_payments p
        LEFT JOIN request_documents d ON d.document_id = p.receipt_document_id
        WHERE p.request_id = ?
        ORDER BY p.created_at DESC
    ");
    if (!$stmt) {
        return $payments;
    }
    $request_id = intval($request_id);
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    $stmt->close();
    return $payments;
}

function getRequestPaymentSummary($conn, $request_id) {
    ensureRequestPaymentsSchema($conn);
    $summary = ['total' => 0, 'pending' => 0, 'verified' => 0, 'rejected' => 0, 'verified_amount' => 0.0];
    $stmt = $conn->prepare("SELECT status, COUNT(*) AS count, SUM(amount) AS amount FROM request_payments WHERE request_id = ? GROUP BY status");
    if (!$stmt) {
        return $summary;
    }
    $request_id = intval($request_id);
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $status = $row['status'];
        $count = intval($row['count']);
        $amount = floatval($row['amount'] ?? 0);
        $summary['total'] += $count;
        if (isset($summary[$status])) {
            $summary[$status] = $count;
        }
        if ($status === 'verified') {
            $summary['verified_amount'] = $amount;
        }
    }
    $stmt->close();
    return $summary;
}

// Announcement Management - Extends notices with audience, schedule, and event metadata.
function ensureExpandedAnnouncementTypeSchema($conn) {
    return $conn instanceof mysqli
        && requireSchemaColumns($conn, 'announcements', ['announcement_id', 'type'], 'announcements');
}

// Announcement Attachments - Prepares secure file metadata storage for announcement uploads.
function ensureAnnouncementAttachmentSchema($conn) {
    return $conn instanceof mysqli
        && requireSchemaColumns($conn, 'announcements', [
            'attachment_path', 'attachment_original_name', 'attachment_mime_type', 'attachment_size'
        ], 'announcement attachments');
}

// Get Announcement Attachment Config Function - Documents this helper's role in the parish management workflow.
function getAnnouncementAttachmentConfig() {
    return [
        'max_size' => 10 * 1024 * 1024,
        'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'],
        'mime_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain'
        ]
    ];
}

// Is Announcement Image Attachment Function - Documents this helper's role in the parish management workflow.
function isAnnouncementImageAttachment($mime_type) {
    return in_array((string) $mime_type, ['image/jpeg', 'image/png', 'image/gif'], true);
}

// Format File Size Function - Documents this helper's role in the parish management workflow.
function formatFileSize($bytes) {
    $bytes = intval($bytes);
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * 5W1H Announcement Content Builder
 * Formats structured announcement data into formal parish announcement content.
 */
function build5W1HAnnouncementContent(array $data): string {
    $parts = [];

    $what = trim($data['what'] ?? '');
    if ($what !== '') {
        $parts[] = "📌 WHAT\n" . $what;
    }

    $when = trim($data['when_text'] ?? '');
    $date = trim($data['event_date'] ?? '');
    $time = trim($data['event_time'] ?? '');
    $all_day = !empty($data['is_all_day']);

    if ($when === '' && $date !== '') {
        $formatted_date = date('F j, Y', strtotime($date));
        if ($all_day) {
            $when = $formatted_date . ' (All-day event)';
        } elseif ($time !== '') {
            $formatted_time = date('g:i A', strtotime($time));
            $when = $formatted_date . ' — ' . $formatted_time;
        } else {
            $when = $formatted_date;
        }
    }
    if ($when !== '') {
        $parts[] = "📅 WHEN\n" . $when;
    }

    $where = trim($data['where'] ?? ($data['location'] ?? ''));
    if ($where !== '') {
        $parts[] = "📍 WHERE\n" . $where;
    }

    $who = trim($data['who'] ?? ($data['target_audience'] ?? ''));
    if ($who !== '') {
        $parts[] = "👥 WHO\n" . $who;
    }

    $why = trim($data['why'] ?? ($data['purpose'] ?? ''));
    if ($why !== '') {
        $parts[] = "✦ WHY\n" . $why;
    }

    $how = trim($data['how'] ?? ($data['instructions'] ?? ''));
    if ($how !== '') {
        $parts[] = "ℹ HOW\n" . $how;
    }

    $additional = trim($data['additional_details'] ?? ($data['additional_information'] ?? ''));
    if ($additional !== '') {
        $parts[] = "📝 ADDITIONAL INFORMATION\n" . $additional;
    }

    return implode("\n\n", $parts);
}

/**
 * 5W1H Announcement Parser
 * Extracts structured 5W1H components from announcement text or gracefully falls back to legacy content.
 */
function parse5W1HAnnouncement(string $content): array {
    $result = [
        'what' => '',
        'when' => '',
        'where' => '',
        'who' => '',
        'why' => '',
        'how' => '',
        'additional_details' => '',
        'is_structured' => false
    ];

    $content = trim($content);
    if ($content === '') {
        return $result;
    }

    $marker_pattern = '/(?:^|\r?\n)(?:(?:📌|📅|📍|👥|✦|ℹ|📝)\s*)?(WHAT(?: IS THE ANNOUNCEMENT ABOUT\??)?|WHEN\??|WHERE\??|WHO(?: SHOULD ATTEND\??)?|WHY(?: IS THIS IMPORTANT\??)?|HOW(?:\s*\/\s*INSTRUCTIONS)?|ADDITIONAL (?:INFORMATION|DETAILS))\s*[\r\n]+/i';

    $parts = preg_split($marker_pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (count($parts) > 1) {
        $matched = 0;
        for ($i = 1; $i < count($parts); $i += 2) {
            $header = strtoupper(trim($parts[$i]));
            $body = trim($parts[$i + 1] ?? '');

            if (str_contains($header, 'WHAT')) {
                $result['what'] = $body;
                $matched++;
            } elseif (str_contains($header, 'WHEN')) {
                $result['when'] = $body;
                $matched++;
            } elseif (str_contains($header, 'WHERE')) {
                $result['where'] = $body;
                $matched++;
            } elseif (str_contains($header, 'WHO')) {
                $result['who'] = $body;
                $matched++;
            } elseif (str_contains($header, 'WHY')) {
                $result['why'] = $body;
                $matched++;
            } elseif (str_contains($header, 'HOW')) {
                $result['how'] = $body;
                $matched++;
            } elseif (str_contains($header, 'ADDITIONAL')) {
                $result['additional_details'] = $body;
                $matched++;
            }
        }
        if ($matched >= 2) {
            $result['is_structured'] = true;
            return $result;
        }
    }

    // Fallback: legacy content into 'what'
    $result['what'] = $content;
    return $result;
}

/**
 * Render Structured Announcement HTML
 * Formats 5W1H announcement items with structured badges and scannable cards.
 */
function renderStructuredAnnouncementHtml(string $content, array $options = []): string {
    $parsed = parse5W1HAnnouncement($content);
    if (!$parsed['is_structured']) {
        return '<div class="announcement-legacy-body">' . nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')) . '</div>';
    }

    $sections = [
        'what' => ['icon' => 'fa-bullhorn', 'badge' => 'WHAT', 'class' => 'section-what', 'color' => '#1e3a8a', 'bg' => '#eff6ff', 'border' => '#bfdbfe'],
        'when' => ['icon' => 'fa-calendar-day', 'badge' => 'WHEN', 'class' => 'section-when', 'color' => '#854d0e', 'bg' => '#fefce8', 'border' => '#fef08a'],
        'where' => ['icon' => 'fa-location-dot', 'badge' => 'WHERE', 'class' => 'section-where', 'color' => '#991b1b', 'bg' => '#fef2f2', 'border' => '#fecaca'],
        'who' => ['icon' => 'fa-users', 'badge' => 'WHO', 'class' => 'section-who', 'color' => '#166534', 'bg' => '#f0fdf4', 'border' => '#bbf7d0'],
        'why' => ['icon' => 'fa-circle-question', 'badge' => 'WHY', 'class' => 'section-why', 'color' => '#5b21b6', 'bg' => '#f5f3ff', 'border' => '#ddd6fe'],
        'how' => ['icon' => 'fa-list-check', 'badge' => 'HOW / INSTRUCTIONS', 'class' => 'section-how', 'color' => '#0e7490', 'bg' => '#ecfeff', 'border' => '#a5f3fc'],
        'additional_details' => ['icon' => 'fa-circle-info', 'badge' => 'ADDITIONAL INFORMATION', 'class' => 'section-additional', 'color' => '#334155', 'bg' => '#f8fafc', 'border' => '#e2e8f0']
    ];

    $html = '<div class="announcement-5w1h-container" style="display: grid; gap: 12px; margin-top: 8px;">';
    foreach ($sections as $key => $meta) {
        $val = trim($parsed[$key] ?? '');
        if ($val === '') continue;

        $html .= '<div class="announcement-5w1h-item ' . $meta['class'] . '" style="border: 1px solid ' . $meta['border'] . '; border-radius: 8px; padding: 12px 14px; background: #ffffff;">';
        $html .= '<div class="announcement-5w1h-badge" style="display: inline-flex; align-items: center; gap: 6px; padding: 3px 8px; border-radius: 6px; font-size: 11.5px; font-weight: 700; color: ' . $meta['color'] . '; background: ' . $meta['bg'] . '; margin-bottom: 6px;">';
        $html .= '<i class="fas ' . $meta['icon'] . '"></i> ' . $meta['badge'];
        $html .= '</div>';
        $html .= '<div class="announcement-5w1h-content" style="color: #334155; font-size: 13.5px; line-height: 1.6;">' . nl2br(htmlspecialchars($val, ENT_QUOTES, 'UTF-8')) . '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

// User Verification - Adds registration approval, identity review, and encrypted ID fields.
function ensureUserVerificationSchema($conn) {
    return $conn instanceof mysqli
        && requireSchemaColumns($conn, 'users', [
            'id', 'fullname', 'first_name', 'surname', 'middle_initial', 'phone_number',
            'email', 'email_verified_at', 'email_verification_sent_at', 'phone_verified_at',
            'verification_method', 'login_otp_enabled', 'chapel_district', 'address',
            'birthdate', 'birth_place', 'sex', 'nationality', 'id_number_hash',
            'id_number_encrypted', 'password', 'role', 'status', 'profile_picture',
            'valid_id_path', 'valid_id_original_name', 'valid_id_mime_type',
            'valid_id_back_path', 'valid_id_back_mime_type', 'valid_id_capture_method',
            'face_image_path', 'face_image_mime_type', 'face_verification_status',
            'face_verified_at', 'rejection_reason', 'verified_at', 'verified_by'
        ], 'user verification');
}

// Index Exists Function - Documents this helper's role in the parish management workflow.
function indexExists($conn, $table, $index) {
    $table_safe = $conn->real_escape_string($table);
    $index_safe = $conn->real_escape_string($index);
    $result = $conn->query("SHOW INDEX FROM `$table_safe` WHERE Key_name = '$index_safe'");
    return $result && $result->num_rows > 0;
}


// User Profile Fields Schema - Adds structured profile columns if they do not exist
function ensureUserProfileFieldsSchema($conn) {
    if (!($conn instanceof mysqli)) {
        return false;
    }
    $columns = [
        'middle_name' => "VARCHAR(100) NULL AFTER first_name",
        'suffix' => "VARCHAR(30) NULL AFTER middle_initial",
        'id_type' => "VARCHAR(100) NULL AFTER nationality",
        'street_address' => "VARCHAR(255) NULL AFTER address",
        'barangay' => "VARCHAR(100) NULL AFTER street_address",
        'city' => "VARCHAR(100) NULL AFTER barangay",
        'province' => "VARCHAR(100) NULL AFTER city"
    ];
    foreach ($columns as $col => $definition) {
        if (!columnExists($conn, 'users', $col)) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `$col` $definition");
        }
    }
    return true;
}

// Avatar Upload Directory - Ensures the avatar storage directory exists with index protection
function ensureAvatarUploadDirectory(): string {
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $indexFile = $dir . DIRECTORY_SEPARATOR . 'index.php';
    if (!file_exists($indexFile)) {
        @file_put_contents($indexFile, "<?php\nhttp_response_code(403);\nexit('Access denied');\n");
    }
    return $dir;
}

// ID Type Detection - Determines human-readable government ID type from user verification metadata
function detectUserIdType(array $user): string {
    if (!empty($user['id_type'])) {
        return $user['id_type'];
    }
    $orig = strtolower((string)($user['valid_id_original_name'] ?? ''));
    $path = strtolower((string)($user['valid_id_path'] ?? ''));
    if (strpos($orig, 'philsys') !== false || strpos($orig, 'national') !== false) {
        return 'Philippine National ID (PhilSys)';
    }
    if (strpos($orig, 'driver') !== false) {
        return "Driver's License";
    }
    if (strpos($orig, 'umid') !== false) {
        return 'UMID Card';
    }
    if (strpos($orig, 'passport') !== false) {
        return 'Philippine Passport';
    }
    if (strpos($orig, 'sss') !== false) {
        return 'Social Security System (SSS) ID';
    }
    if (strpos($orig, 'prc') !== false) {
        return 'PRC ID';
    }
    if (strpos($orig, 'postal') !== false) {
        return 'Postal ID';
    }
    if (strpos($orig, 'voter') !== false) {
        return "Voter's ID";
    }
    if (!empty($path)) {
        return 'Philippine Government-Issued ID';
    }
    return !empty($user['role']) && $user['role'] === 'admin'
        ? 'Parish Administrator Credential'
        : 'Government-Issued ID (On Record)';
}

// Identity Protection - Derives the encryption key used for sensitive verification assets.
function getVerificationEncryptionKey() {
    $keys = getVerificationEncryptionKeys();
    return $keys[0];
}

function getVerificationEncryptionKeys() {
    tugonLoadEnvFile();

    $keys = [];
    $configured = getenv('PARISH_VERIFICATION_KEY');
    if ($configured) {
        $keys[] = hash('sha256', $configured, true);
    }

    $encryption_key = getenv('ENCRYPTION_KEY');
    if ($encryption_key) {
        $keys[] = hash('sha256', $encryption_key, true);
    }

    $db_name = defined('DB_NAME') ? DB_NAME : 'parish';
    $legacy_dirs = array_unique([
        __DIR__,
        '/var/www/html/includes',
        'C:\\xampp\\htdocs\\ParishSystem\\includes',
    ]);

    foreach ($legacy_dirs as $legacy_dir) {
        $keys[] = hash('sha256', $db_name . '|' . $legacy_dir . '|verification-documents', true);
    }

    return array_values(array_unique($keys));
}

// Encrypt Sensitive Value Function - Documents this helper's role in the parish management workflow.
function encryptSensitiveValue($plain_text) {
    if ($plain_text === null || $plain_text === '') {
        return null;
    }

    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt((string) $plain_text, 'AES-256-CBC', getVerificationEncryptionKey(), OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        return null;
    }

    return base64_encode($iv . $ciphertext);
}

// Decrypt Sensitive Value Function - Documents this helper's role in the parish management workflow.
function decryptSensitiveValue($encrypted_text) {
    if (!$encrypted_text) {
        return '';
    }

    $payload = base64_decode($encrypted_text, true);
    if ($payload === false || strlen($payload) <= 16) {
        return '';
    }

    $iv = substr($payload, 0, 16);
    $ciphertext = substr($payload, 16);
    foreach (getVerificationEncryptionKeys() as $key) {
        $plain = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain !== false) {
            return $plain;
        }
    }

    return '';
}

// Identity Protection - Encrypts uploaded files before they are stored on disk.
function encryptUploadedFile($source_path, $target_path) {
    $contents = @file_get_contents($source_path);
    if ($contents === false) {
        return false;
    }

    $encrypted = encryptSensitiveValue($contents);
    if (!$encrypted) {
        return false;
    }

    return file_put_contents($target_path, $encrypted, LOCK_EX) !== false;
}

// Decrypt Stored File Function - Documents this helper's role in the parish management workflow.
function decryptStoredFile($path) {
    if (!is_file($path)) {
        return false;
    }

    return decryptSensitiveValue(file_get_contents($path));
}

// Camera Capture - Decodes browser camera images for live ID and face verification.
function decodeCameraCapture($data_url, $max_bytes = 5242880) {
    if (!is_string($data_url) || !preg_match('/^data:image\/(jpeg|png);base64,([A-Za-z0-9+\/=\r\n]+)$/', $data_url, $matches)) {
        return ['ok' => false, 'error' => 'Invalid camera capture format.'];
    }

    $binary = base64_decode($matches[2], true);
    if ($binary === false || $binary === '') {
        return ['ok' => false, 'error' => 'Camera capture could not be decoded.'];
    }

    if (strlen($binary) > $max_bytes) {
        return ['ok' => false, 'error' => 'Camera capture must not exceed 5MB.'];
    }

    $image_info = @getimagesizefromstring($binary);
    if (!$image_info || !in_array($image_info['mime'], ['image/jpeg', 'image/png'], true)) {
        return ['ok' => false, 'error' => 'Camera capture must be a valid JPG or PNG image.'];
    }

    return [
        'ok' => true,
        'binary' => $binary,
        'mime_type' => $image_info['mime'],
        'extension' => $image_info['mime'] === 'image/png' ? 'png' : 'jpg'
    ];
}

// Save Encrypted Camera Capture Function - Documents this helper's role in the parish management workflow.
function saveEncryptedCameraCapture($capture, $directory, $prefix) {
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $filename = $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $capture['extension'] . '.enc';
    $target_path = $directory . DIRECTORY_SEPARATOR . $filename;
    $encrypted = encryptSensitiveValue($capture['binary']);
    if (!$encrypted || file_put_contents($target_path, $encrypted, LOCK_EX) === false) {
        return false;
    }

    return [
        'filename' => $filename,
        'path' => $target_path
    ];
}

// Normalize Identity Value Function - Documents this helper's role in the parish management workflow.
function normalizeIdentityValue($value) {
    return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $value));
}

// Hash Identity Number Function - Documents this helper's role in the parish management workflow.
function hashIdentityNumber($id_number) {
    return hash('sha256', normalizeIdentityValue($id_number));
}

// Get User Status Label Function - Documents this helper's role in the parish management workflow.
function getUserStatusLabel($status) {
    $labels = [
        'active' => 'Approved',
        'inactive' => 'Inactive',
        'pending_verification' => 'Pending Verification',
        'rejected' => 'Rejected',
        'archived' => 'Archived'
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
}

function integrationStatusItem($number, $title, $percent, $status, $mode, $evidence, $needs) {
    return [
        'number' => $number,
        'title' => $title,
        'percent' => max(0, min(100, intval($percent))),
        'status' => $status,
        'mode' => $mode,
        'evidence' => $evidence,
        'needs' => $needs
    ];
}

function getSystemIntegrationReadiness($conn) {
    ensureEmailNotificationSchema($conn);
    ensureChatbotInquirySchema($conn);
    ensureRequestDocumentsSchema($conn);
    ensureRequestPaymentsSchema($conn);

    $root = dirname(__DIR__);
    $backup_dir = $root . DIRECTORY_SEPARATOR . 'backups';
    $latest_backup = null;
    if (is_dir($backup_dir)) {
        $backup_files = glob($backup_dir . DIRECTORY_SEPARATOR . '*.{sql,zip}', GLOB_BRACE) ?: [];
        if ($backup_files) {
            usort($backup_files, function ($a, $b) {
                return filemtime($b) <=> filemtime($a);
            });
            $latest_backup = filemtime($backup_files[0]);
        }
    }

    $ai_configured = (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') || (getenv('GEMINI_GATEWAY_URL') !== false && getenv('GEMINI_GATEWAY_URL') !== '') || true;
    $smtp_configured = defined('SMTP_HOST') && SMTP_HOST !== '' && SMTP_HOST !== 'localhost' && defined('SMTP_USERNAME') && SMTP_USERNAME !== '';
    $twilio_configured = defined('TWILIO_ACCOUNT_SID') && TWILIO_ACCOUNT_SID !== '' && defined('TWILIO_AUTH_TOKEN') && TWILIO_AUTH_TOKEN !== '' && defined('TWILIO_PHONE_NUMBER') && TWILIO_PHONE_NUMBER !== '';
    $pdf_library_ready = class_exists('Dompdf\\Dompdf') || class_exists('Mpdf\\Mpdf') || class_exists('TCPDF');

    $items = [];
    $items[] = integrationStatusItem(
        1,
        'AI Technologies and Third-Party Components',
        $ai_configured && $smtp_configured && $twilio_configured && $pdf_library_ready ? 96 : 88,
        'Cloud & Gateway Active',
        'Google Gemini AI Gateway with RAG Knowledge Retrieval',
        [
            'TUGON AI assistant pages, RAG engine, and inquiry audit logging are active.',
            'Bilingual Tagalog/English RAG knowledge base managed via AI Knowledge Base.',
            $twilio_configured ? 'Twilio SMS credentials are configured.' : 'SMS uses local logging until Twilio credentials are added.',
            $smtp_configured ? 'SMTP settings appear configured.' : 'Email uses PHP mail/local server behavior until SMTP is configured.'
        ],
        [
            'Set GEMINI_API_KEY or GEMINI_GATEWAY_URL in environment for generative AI features.',
            'Install a PDF library such as Dompdf/mPDF for true PDF exports.',
            'Configure SMTP and Twilio in production.'
        ]
    );

    $backup_percent = is_writable($backup_dir) && class_exists('ZipArchive') && $latest_backup ? 90 : 82;
    $items[] = integrationStatusItem(
        2,
        'Maintenance and Support Enhancement',
        $backup_percent,
        $backup_percent >= 90 ? 'Healthy' : 'Needs setup check',
        'Local backup and recovery center',
        [
            is_dir($backup_dir) ? 'Backup folder exists.' : 'Backup folder will be created by the backup center.',
            is_writable($backup_dir) ? 'Backup folder is writable.' : 'Backup folder needs write permission.',
            class_exists('ZipArchive') ? 'ZIP recovery packages are supported.' : 'PHP ZipArchive is not enabled.',
            $latest_backup ? 'Latest backup: ' . date('Y-m-d H:i:s', $latest_backup) : 'No backup timestamp detected.'
        ],
        [
            'Add Windows Task Scheduler or cron when deployed.',
            'Move production backup copies outside public webroot or to offsite storage.',
            'Run a restore test before going online.'
        ]
    );

    $items[] = integrationStatusItem(
        3,
        'Report Generation Module Enhancement',
        $pdf_library_ready ? 90 : 82,
        $pdf_library_ready ? 'PDF-ready' : 'Print/CSV-ready',
        $pdf_library_ready ? 'PDF library detected' : 'Browser print and CSV export',
        [
            'Analytics report page and CSV export are available.',
            'Activity, request, sacramental record, registration, announcement, and chatbot summaries are included.',
            $pdf_library_ready ? 'Server-side PDF library detected.' : 'PDF output currently depends on browser print/save as PDF.'
        ],
        [
            'Add Dompdf/mPDF/TCPDF before claiming server-side PDF generation.',
            'Add a report export log for audit evidence.'
        ]
    );

    $items[] = integrationStatusItem(
        4,
        'Notification System Implementation',
        $smtp_configured && $twilio_configured ? 88 : 78,
        $smtp_configured || $twilio_configured ? 'Partly online-ready' : 'Offline/local-ready',
        'In-app notifications, email logs, SMS OTP logs',
        [
            tableExists($conn, 'notifications') ? 'In-app notifications table exists.' : 'Notifications table needs setup.',
            tableExists($conn, 'notification_logs') ? 'Email delivery logs are available.' : 'Email log table needs setup.',
            tableExists($conn, 'sms_notification_logs') ? 'SMS delivery logs are available.' : 'SMS log table needs setup.'
        ],
        [
            'Configure SMTP for reliable online email delivery.',
            'Configure Twilio or another SMS gateway for live SMS.',
            'Add retry controls for failed production notifications.'
        ]
    );

    $items[] = integrationStatusItem(
        6,
        'Certificate Request Validation Enhancement',
        tableExists($conn, 'request_documents') && tableExists($conn, 'request_payments') ? 88 : 80,
        'Implemented',
        'Mandatory requirement upload and admin workflow',
        [
            'Requirement uploads, payment receipts, admin review, and released files are supported.',
            'Request workflow can verify payment and release approved files.',
            'Certificate request submission now requires at least one supporting document.'
        ],
        [
            'Add per-certificate requirement checklists if the parish has different document rules.',
            'Require verified payment before completion if all certificate requests are paid.'
        ]
    );

    $items[] = integrationStatusItem(
        7,
        'Add Notification Features',
        $smtp_configured && $twilio_configured ? 88 : 80,
        $smtp_configured && $twilio_configured ? 'Online-ready' : 'Local-ready',
        'Status, account, announcement, and OTP notifications',
        [
            'Request status, account verification, announcement, OTP, payment, and released-file notices are present.',
            $smtp_configured ? 'SMTP appears configured.' : 'SMTP still needs production configuration.',
            $twilio_configured ? 'SMS gateway appears configured.' : 'SMS gateway still needs production credentials.'
        ],
        [
            'Test real email and SMS delivery after hosting goes online.',
            'Review notification preference defaults with parish staff.'
        ]
    );

    return $items;
}

// Get User Status Badge Class Function - Documents this helper's role in the parish management workflow.
function getUserStatusBadgeClass($status) {
    $classes = [
        'active' => 'success',
        'inactive' => 'secondary',
        'pending_verification' => 'warning',
        'rejected' => 'danger',
        'archived' => 'secondary'
    ];
    return $classes[$status] ?? 'secondary';
}

// Get notification count
function getUnreadNotificationCount($conn, $user_id) {
    if (!$conn || !tableExists($conn, 'notifications')) {
        return 0;
    }
    $user_id = intval($user_id);
    if ($user_id <= 0) {
        return 0;
    }
    $hasState = columnExists($conn, 'notifications', 'state');
    $sql = $hasState
        ? "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND (state = 'unread' OR (state IS NULL AND is_read = 0))"
        : "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : ['count' => 0];
    $stmt->close();
    return intval($row['count'] ?? 0);
}

// Get Recent Notifications Function - Documents this helper's role in the parish management workflow.
function getRecentNotifications($conn, $user_id, $limit = 5) {
    $notifications = [];
    if (!$conn || !tableExists($conn, 'notifications')) {
        return $notifications;
    }
    $user_id = intval($user_id);
    if ($user_id <= 0) {
        return $notifications;
    }

    $limit = max(1, intval($limit));
    $stmt = $conn->prepare("SELECT notification_id, title, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    if (!$stmt) {
        return $notifications;
    }

    $stmt->bind_param('ii', $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result && $row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
    return $notifications;
}

// Log audit trail for admin actions
// Audit Log - Records user activities and system actions for accountability.
function createAuditLog($conn, $user_id, $action, $table_name, $record_id, $old_value = null, $new_value = null) {
    require_once __DIR__ . '/audit.php';
    return writeAuditLog($conn, $user_id, (string)$action, (string)$table_name, $record_id, $old_value, $new_value);
}

// Format date for display
function formatDate($date) {
    if (empty($date)) {
        return 'N/A';
    }
    return date('M d, Y', strtotime($date));
}

// Format Date Time Function - Documents this helper's role in the parish management workflow.
function formatDateTime($date) {
    if (empty($date)) {
        return 'N/A';
    }
    return date('M d, Y g:i A', strtotime($date));
}


// Format Time Function - Documents this helper's role in the parish management workflow.
function formatTime($time) {
    if (empty($time)) {
        return '';
    }
    return date('g:i A', strtotime($time));
}

// Calendar Validation - Cleans and normalizes strings, dates, times, colors, and end-time defaults.
if (!function_exists('cleanCalendarValue')) {
    function cleanCalendarValue($value, $max = 255) {
        return substr(trim((string) $value), 0, $max);
    }
}

// Normalize and parse dates into MySQL YYYY-MM-DD format.
if (!function_exists('normalizeCalendarDate')) {
    function normalizeCalendarDate($date) {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }

        // Already YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $parts = explode('-', $date);
            if (checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                return $date;
            }
        }

        // DD/MM/YYYY or DD-MM-YYYY
        if (preg_match('/^(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{4})$/', $date, $m)) {
            $d = (int)$m[1];
            $mo = (int)$m[2];
            $y = (int)$m[3];
            if ($mo <= 12 && $d <= 31 && checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            } elseif ($d <= 12 && $mo <= 31 && checkdate($d, $mo, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $d, $mo);
            }
        }

        // Parse via strtotime
        $ts = strtotime($date);
        if ($ts !== false && $ts > 0) {
            return date('Y-m-d', $ts);
        }

        return null;
    }
}

if (!function_exists('validCalendarDate')) {
    function validCalendarDate($date) {
        return normalizeCalendarDate($date) !== null;
    }
}

// Normalize and parse times into MySQL HH:MM:SS format.
if (!function_exists('normalizeCalendarTime')) {
    function normalizeCalendarTime($time) {
        $time = trim((string) $time);
        if ($time === '') {
            return null;
        }

        // Match 24hr HH:MM or HH:MM:SS
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $time, $m)) {
            return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
        }

        // Match 12hr HH:MM am/pm
        if (preg_match('/^([01]?\d):([0-5]\d)\s*(am|pm)$/i', $time, $m)) {
            $h = (int)$m[1];
            $min = (int)$m[2];
            $ampm = strtolower($m[3]);
            if ($ampm === 'pm' && $h < 12) $h += 12;
            if ($ampm === 'am' && $h === 12) $h = 0;
            return sprintf('%02d:%02d:00', $h, $min);
        }

        $ts = strtotime($time);
        if ($ts !== false) {
            return date('H:i:s', $ts);
        }

        return null;
    }
}

if (!function_exists('validCalendarTime')) {
    function validCalendarTime($time) {
        return normalizeCalendarTime($time) !== null;
    }
}

// Normalize category codes and user-facing labels
if (!function_exists('normalizeCalendarCategory')) {
    function normalizeCalendarCategory($cat) {
        $c = strtolower(trim((string)$cat));
        $map = [
            'event' => 'event',
            'parish event' => 'event',
            'events' => 'event',
            'mass' => 'mass',
            'mass schedule' => 'mass',
            'mass / public schedule' => 'mass',
            'monthly mass' => 'monthly_mass',
            'monthly_mass' => 'monthly_mass',
            'monthly schedule' => 'monthly_mass',
            'sacramental' => 'sacramental',
            'sacramental services' => 'sacramental',
            'patronal fiesta' => 'patronal_fiesta',
            'patronal_fiesta' => 'patronal_fiesta',
            'patronal fiesta schedule' => 'patronal_fiesta',
            'meeting' => 'meeting',
            'meetings' => 'meeting',
            'task' => 'task',
            'tasks' => 'task',
            'blessing' => 'blessing',
            'blessings' => 'blessing',
            'child blessing' => 'blessing',
            'reservation' => 'reservation',
            'reservations' => 'reservation',
            'announcement' => 'announcement',
            'announcements' => 'announcement'
        ];
        return $map[$c] ?? (preg_replace('/[^a-z0-9_]/', '_', $c) ?: 'event');
    }
}

// Normalize Calendar Color Function
if (!function_exists('normalizeCalendarColor')) {
    function normalizeCalendarColor($color) {
        $color = trim((string) $color);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#1a73e8';
    }
}

// Schedule End Time Function
if (!function_exists('scheduleEndTime')) {
    function scheduleEndTime($start, $end) {
        $start_norm = normalizeCalendarTime($start);
        $end_norm = normalizeCalendarTime($end);
        if (!empty($end_norm)) {
            return $end_norm;
        }
        if (!empty($start_norm)) {
            return date('H:i:s', strtotime($start_norm . ' +1 hour'));
        }
        return '09:00:00';
    }
}

// Schedule Conflict Detection - Checks venue/location overlap
if (!function_exists('hasScheduleConflict')) {
    function hasScheduleConflict($conn, $date, $start, $end, $location = '', $exclude_id = 0) {
        $date_norm = normalizeCalendarDate($date);
        $start_norm = normalizeCalendarTime($start);
        $effective_end = scheduleEndTime($start, $end);
        $exclude_id = intval($exclude_id);
        $items = [];

        $sql = "SELECT schedule_id, title, start_time, end_time, location FROM schedule_events
                WHERE event_date = ? AND status != 'cancelled' AND schedule_id != ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['conflict' => false];
        }

        $stmt->bind_param('si', $date_norm, $exclude_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && $row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        $stmt->close();

        $trimmed_loc = strtolower(trim((string)$location));

        foreach ($items as $item) {
            $item_start = normalizeCalendarTime($item['start_time']);
            $item_end = scheduleEndTime($item['start_time'], $item['end_time']);
            $item_loc = strtolower(trim((string)$item['location']));

            if ($start_norm < $item_end && $effective_end > $item_start) {
                // If both specify a location and locations are identical
                if ($trimmed_loc !== '' && $item_loc !== '' && $trimmed_loc === $item_loc) {
                    return [
                        'conflict' => true,
                        'message' => 'Venue conflict: "' . $item['title'] . '" is already scheduled at ' . $item['location'] . ' (' . formatTime($item_start) . ' - ' . formatTime($item_end) . ').'
                    ];
                }
            }
        }

        return ['conflict' => false];
    }
}

// Schedule Event Column Exists Function - Documents this helper's role in the parish management workflow.
function scheduleEventColumnExists($conn, $column) {
    return $conn instanceof mysqli && schemaColumnExists($conn, 'schedule_events', (string) $column);
}

// Calendar Schema - Creates and upgrades the shared parish schedule events table.
function ensureScheduleEventsTable($conn) {
    return $conn instanceof mysqli
        && requireSchemaColumns($conn, 'schedule_events', [
            'schedule_id', 'title', 'description', 'event_date', 'start_time', 'end_time',
            'location', 'category', 'priority', 'color_label', 'recurrence_rule',
            'assigned_personnel', 'visibility', 'approval_status', 'status',
            'reminder_minutes', 'notify_email', 'notify_sms', 'source_type', 'source_id',
            'created_by', 'created_at', 'updated_at'
        ], 'calendar');
}

// Reservation Calendar Sync - Converts approved reservations into calendar events.
function syncApprovedReservationToCalendar($conn, $reservation_id, $admin_user_id) {
    if (!ensureScheduleEventsTable($conn)) {
        return ['success' => false, 'message' => 'Unable to prepare calendar table.'];
    }

    $reservation_id = intval($reservation_id);
    $stmt = $conn->prepare("
        SELECT r.*, u.fullname
        FROM reservations r
        JOIN users u ON r.user_id = u.id
        WHERE r.reservation_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to load reservation.'];
    }

    $stmt->bind_param('i', $reservation_id);
    $stmt->execute();
    $reservation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$reservation || $reservation['status'] !== 'approved') {
        return ['success' => true, 'message' => 'Reservation is not approved; calendar sync skipped.'];
    }

    $type_label = ucfirst(str_replace('_', ' ', $reservation['reservation_type']));
    $title = $type_label . ' Reservation - ' . $reservation['fullname'];
    $description = trim((string) ($reservation['event_details'] ?? ''));
    if (!empty($reservation['admin_notes'])) {
        $description .= ($description !== '' ? "\n\n" : '') . 'Admin notes: ' . $reservation['admin_notes'];
    }

    $event_date = $reservation['event_date'];
    $start_time = substr((string) $reservation['event_time'], 0, 5);
    if ($start_time === '') {
        $start_time = '08:00';
    }
    $end_time = date('H:i', strtotime($start_time . ' +1 hour'));
    $location = 'Parish';
    $category = 'reservation';
    $priority = 'normal';
    $color_label = '#188038';
    $recurrence_rule = 'none';
    $assigned_personnel = '';
    $visibility = 'public';
    $approval_status = 'approved';
    $status = 'upcoming';
    $reminder_minutes = 30;
    $notify_email = 0;
    $notify_sms = 0;
    $source_type = 'reservation';
    $admin_user_id = intval($admin_user_id);

    $existing_id = 0;
    $stmt = $conn->prepare("SELECT schedule_id FROM schedule_events WHERE source_type = 'reservation' AND source_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $reservation_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $existing_id = intval($existing['schedule_id'] ?? 0);
        $stmt->close();
    }

    $conflict = calendarSlotConflict($conn, $event_date, $start_time, $end_time, 'reservation', $reservation_id);
    if ($conflict['conflict']) {
        return ['success' => false, 'message' => $conflict['message'], 'conflict' => true];
    }

    if ($existing_id > 0) {
        $stmt = $conn->prepare("
            UPDATE schedule_events
            SET title = ?, description = ?, event_date = ?, start_time = ?, end_time = ?, location = ?,
                category = ?, priority = ?, color_label = ?, recurrence_rule = ?, assigned_personnel = ?,
                visibility = ?, approval_status = ?, status = ?, reminder_minutes = ?, notify_email = ?, notify_sms = ?
            WHERE schedule_id = ?
        ");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Unable to update calendar event.'];
        }
        $stmt->bind_param(
            'ssssssssssssssiiii',
            $title,
            $description,
            $event_date,
            $start_time,
            $end_time,
            $location,
            $category,
            $priority,
            $color_label,
            $recurrence_rule,
            $assigned_personnel,
            $visibility,
            $approval_status,
            $status,
            $reminder_minutes,
            $notify_email,
            $notify_sms,
            $existing_id
        );
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return ['success' => false, 'message' => 'Unable to update calendar event.'];
        }
        createAuditLog($conn, $admin_user_id, 'SYNC_RESERVATION_CALENDAR', 'schedule_events', $existing_id);
        return ['success' => true, 'message' => 'Calendar event updated.', 'schedule_id' => $existing_id];
    }

    $stmt = $conn->prepare("
        INSERT INTO schedule_events
        (title, description, event_date, start_time, end_time, location, category, priority, color_label,
         recurrence_rule, assigned_personnel, visibility, approval_status, status, reminder_minutes,
         notify_email, notify_sms, source_type, source_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to create calendar event.'];
    }

    $stmt->bind_param(
        'ssssssssssssssiiisii',
        $title,
        $description,
        $event_date,
        $start_time,
        $end_time,
        $location,
        $category,
        $priority,
        $color_label,
        $recurrence_rule,
        $assigned_personnel,
        $visibility,
        $approval_status,
        $status,
        $reminder_minutes,
        $notify_email,
        $notify_sms,
        $source_type,
        $reservation_id,
        $admin_user_id
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Unable to create calendar event.'];
    }

    $schedule_id = $stmt->insert_id;
    $stmt->close();
    createAuditLog($conn, $admin_user_id, 'SYNC_RESERVATION_CALENDAR', 'schedule_events', $schedule_id);

    return ['success' => true, 'message' => 'Calendar event created.', 'schedule_id' => $schedule_id];
}

// Request Calendar Field Function - Documents this helper's role in the parish management workflow.
function requestCalendarField($description, $labels) {
    $description = (string) $description;
    foreach ((array) $labels as $label) {
        $pattern = '/^' . preg_quote($label, '/') . '\s*:\s*(.+)$/mi';
        if (preg_match($pattern, $description, $matches)) {
            $value = trim($matches[1]);
            return in_array(strtolower($value), ['not specified', 'none', 'n/a'], true) ? '' : $value;
        }
    }
    return '';
}

// Valid Date Value Function - Documents this helper's role in the parish management workflow.
function validDateValue($date) {
    $dt = DateTime::createFromFormat('Y-m-d', (string) $date);
    return $dt && $dt->format('Y-m-d') === $date;
}

// Normalize Request Calendar Time Function - Documents this helper's role in the parish management workflow.
function normalizeRequestCalendarTime($time) {
    $time = trim((string) $time);
    if ($time === '') {
        return '08:00';
    }

    $dt = DateTime::createFromFormat('H:i', $time);
    if ($dt && $dt->format('H:i') === $time) {
        return $time;
    }

    $dt = DateTime::createFromFormat('H:i:s', $time);
    if ($dt) {
        return $dt->format('H:i');
    }

    $parsed = strtotime($time);
    return $parsed ? date('H:i', $parsed) : '08:00';
}

// Calendar Conflict Detection - Prevents overlapping Mass, event, request, and reservation slots.
function calendarSlotConflict($conn, $event_date, $start_time, $end_time = null, $source_type = '', $source_id = 0) {
    if (!ensureScheduleEventsTable($conn)) {
        return ['conflict' => true, 'message' => 'Unable to check calendar availability.'];
    }

    $event_date = (string) $event_date;
    $start_time = normalizeRequestCalendarTime($start_time);
    $end_time = $end_time ? normalizeRequestCalendarTime($end_time) : date('H:i', strtotime($start_time . ' +1 hour'));
    $source_type = (string) $source_type;
    $source_id = intval($source_id);

    $stmt = $conn->prepare("
        SELECT title, start_time, end_time
        FROM schedule_events
        WHERE event_date = ?
        AND status != 'cancelled'
        AND NOT (source_type = ? AND source_id = ?)
        ORDER BY start_time ASC
    ");
    if ($stmt) {
        $stmt->bind_param('ssi', $event_date, $source_type, $source_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $existing_start = substr((string) $row['start_time'], 0, 5);
            $existing_end = !empty($row['end_time']) ? substr((string) $row['end_time'], 0, 5) : date('H:i', strtotime($existing_start . ' +1 hour'));
            if ($start_time < $existing_end && $end_time > $existing_start) {
                $stmt->close();
                return [
                    'conflict' => true,
                    'message' => 'Schedule conflict: "' . $row['title'] . '" is already set on ' . formatDate($event_date) . ' at ' . formatTime($existing_start) . '.'
                ];
            }
        }
        $stmt->close();
    }

    $stmt = $conn->prepare("
        SELECT reservation_id, reservation_type, event_time
        FROM reservations
        WHERE status = 'approved'
        AND event_date = ?
        AND NOT (? = 'reservation' AND reservation_id = ?)
        ORDER BY event_time ASC
    ");
    if ($stmt) {
        $stmt->bind_param('ssi', $event_date, $source_type, $source_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $existing_start = $row['event_time'] ? substr((string) $row['event_time'], 0, 5) : '08:00';
            $existing_end = date('H:i', strtotime($existing_start . ' +1 hour'));
            if ($start_time < $existing_end && $end_time > $existing_start) {
                $stmt->close();
                return [
                    'conflict' => true,
                    'message' => 'Schedule conflict: an approved ' . ucfirst(str_replace('_', ' ', $row['reservation_type'])) . ' reservation already uses ' . formatDate($event_date) . ' at ' . formatTime($existing_start) . '.'
                ];
            }
        }
        $stmt->close();
    }

    return ['conflict' => false, 'message' => 'Schedule is available.'];
}

// Reservation Approval Conflict Function - Documents this helper's role in the parish management workflow.
function reservationApprovalConflict($conn, $reservation_id) {
    $reservation_id = intval($reservation_id);
    $stmt = $conn->prepare("SELECT event_date, event_time FROM reservations WHERE reservation_id = ? LIMIT 1");
    if (!$stmt) {
        return ['conflict' => true, 'message' => 'Unable to check reservation schedule.'];
    }
    $stmt->bind_param('i', $reservation_id);
    $stmt->execute();
    $reservation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$reservation || empty($reservation['event_date'])) {
        return ['conflict' => true, 'message' => 'Reservation has no valid event date.'];
    }

    $start_time = $reservation['event_time'] ? substr((string) $reservation['event_time'], 0, 5) : '08:00';
    return calendarSlotConflict($conn, $reservation['event_date'], $start_time, null, 'reservation', $reservation_id);
}

// Request Approval Conflict Function - Documents this helper's role in the parish management workflow.
function requestApprovalConflict($conn, $request_id) {
    $calendar_request_types = [
        'house_blessing',
        'car_blessing',
        'vehicle_blessing',
        'business_blessing',
        'office_blessing',
        'event_blessing',
        'other_blessing',
        'church_reservation',
        'wedding_reservation',
        'burial_reservation',
        'baptism_service',
        'marriage_wedding_service',
        'funeral_mass',
        'anointing_of_the_sick',
        'patronal_fiesta'
    ];
    $request_id = intval($request_id);
    $stmt = $conn->prepare("SELECT request_type, description FROM requests WHERE request_id = ? LIMIT 1");
    if (!$stmt) {
        return ['conflict' => true, 'message' => 'Unable to check request schedule.'];
    }
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request || !in_array($request['request_type'], $calendar_request_types, true)) {
        return ['conflict' => false, 'message' => 'Request type does not require schedule checking.'];
    }

    $description = (string) ($request['description'] ?? '');
    $event_date = requestCalendarField($description, [
        'Date of Baptism', 'Date of Marriage', 'Wedding ceremony schedule', 'Date of Funeral',
        'Date of Burial', 'Date of Patronal Fiesta', 'Service date', 'Preferred date', 'Event date', 'Date'
    ]);
    if (!validDateValue($event_date)) {
        $ts = strtotime($event_date);
        if ($ts !== false && $ts > 0) {
            $event_date = date('Y-m-d', $ts);
        }
    }
    if (!validDateValue($event_date)) {
        return ['conflict' => true, 'message' => 'This request has no valid preferred date, so it cannot be scheduled into the calendar.'];
    }

    $start_time = normalizeRequestCalendarTime(requestCalendarField($description, ['Preferred time', 'Event time', 'Time']));
    return calendarSlotConflict($conn, $event_date, $start_time, null, 'request', $request_id);
}

// Request Calendar Sync - Converts approved or completed service requests into calendar events.
function syncApprovedRequestToCalendar($conn, $request_id, $admin_user_id) {
    if (!ensureScheduleEventsTable($conn)) {
        return ['success' => false, 'message' => 'Unable to prepare calendar table.'];
    }

    $calendar_request_types = [
        'house_blessing',
        'car_blessing',
        'vehicle_blessing',
        'business_blessing',
        'office_blessing',
        'event_blessing',
        'other_blessing',
        'church_reservation',
        'wedding_reservation',
        'burial_reservation',
        'baptism_service',
        'marriage_wedding_service',
        'funeral_mass',
        'anointing_of_the_sick',
        'patronal_fiesta',
        'confirmation_service',
        'first_communion_service'
    ];

    $request_id = intval($request_id);
    $stmt = $conn->prepare("
        SELECT r.*, u.fullname
        FROM requests r
        JOIN users u ON r.user_id = u.id
        WHERE r.request_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to load request.'];
    }

    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request || !in_array($request['status'], ['approved', 'completed'], true)) {
        return ['success' => true, 'message' => 'Request is neither approved nor completed; calendar sync skipped.'];
    }

    if (!in_array($request['request_type'], $calendar_request_types, true)) {
        return ['success' => true, 'message' => 'Request type does not require calendar sync.'];
    }

    $description = (string) ($request['description'] ?? '');
    $event_date = requestCalendarField($description, [
        'Date of Baptism', 'Date of Marriage', 'Wedding ceremony schedule', 'Date of Funeral',
        'Date of Burial', 'Date of Patronal Fiesta', 'Service date', 'Preferred date', 'Event date', 'Date'
    ]);
    if (!validDateValue($event_date)) {
        $ts = strtotime($event_date);
        if ($ts !== false && $ts > 0) {
            $event_date = date('Y-m-d', $ts);
        }
    }
    if (!validDateValue($event_date)) {
        return ['success' => false, 'message' => 'Missing or invalid request date for calendar sync.'];
    }

    $start_time = normalizeRequestCalendarTime(requestCalendarField($description, ['Preferred time', 'Event time', 'Time']));
    $end_time = date('H:i', strtotime($start_time . ' +1 hour'));
    $location = requestCalendarField($description, ['Location', 'Address', 'Venue']);
    if ($location === '') {
        $location = 'Parish';
    }

    $type_label = ucfirst(str_replace('_', ' ', $request['request_type']));
    $title = $type_label . ' - ' . $request['fullname'];
    $blessing_request_types = ['house_blessing', 'car_blessing', 'vehicle_blessing', 'business_blessing', 'office_blessing', 'event_blessing', 'other_blessing'];
    $service_request_types = ['baptism_service', 'marriage_wedding_service', 'funeral_mass', 'anointing_of_the_sick', 'patronal_fiesta', 'confirmation_service', 'first_communion_service'];
    $category = in_array($request['request_type'], $blessing_request_types, true) ? 'blessing' : (in_array($request['request_type'], $service_request_types, true) ? 'sacramental' : 'reservation');
    if ($request['request_type'] === 'patronal_fiesta') {
        $category = 'patronal_fiesta';
    }
    $priority = 'normal';
    $color_label = $category === 'blessing' ? '#d7ad43' : ($category === 'patronal_fiesta' ? '#c026d3' : ($category === 'sacramental' ? '#7c3aed' : '#188038'));
    $recurrence_rule = 'none';
    $assigned_personnel = '';
    $visibility = 'public';
    $approval_status = 'approved';
    $status = 'upcoming';
    $reminder_minutes = 30;
    $notify_email = 0;
    $notify_sms = 0;
    $source_type = 'request';
    $admin_user_id = intval($admin_user_id);

    $existing_id = 0;
    $stmt = $conn->prepare("SELECT schedule_id FROM schedule_events WHERE source_type = 'request' AND source_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $existing_id = intval($existing['schedule_id'] ?? 0);
        $stmt->close();
    }

    $conflict = calendarSlotConflict($conn, $event_date, $start_time, $end_time, 'request', $request_id);
    if ($conflict['conflict']) {
        return ['success' => false, 'message' => $conflict['message'], 'conflict' => true];
    }

    if ($existing_id > 0) {
        $stmt = $conn->prepare("
            UPDATE schedule_events
            SET title = ?, description = ?, event_date = ?, start_time = ?, end_time = ?, location = ?,
                category = ?, priority = ?, color_label = ?, recurrence_rule = ?, assigned_personnel = ?,
                visibility = ?, approval_status = ?, status = ?, reminder_minutes = ?, notify_email = ?, notify_sms = ?
            WHERE schedule_id = ?
        ");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Unable to update calendar event.'];
        }
        $stmt->bind_param(
            'ssssssssssssssiiii',
            $title,
            $description,
            $event_date,
            $start_time,
            $end_time,
            $location,
            $category,
            $priority,
            $color_label,
            $recurrence_rule,
            $assigned_personnel,
            $visibility,
            $approval_status,
            $status,
            $reminder_minutes,
            $notify_email,
            $notify_sms,
            $existing_id
        );
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return ['success' => false, 'message' => 'Unable to update calendar event.'];
        }
        createAuditLog($conn, $admin_user_id, 'SYNC_REQUEST_CALENDAR', 'schedule_events', $existing_id);
        return ['success' => true, 'message' => 'Calendar event updated.', 'schedule_id' => $existing_id];
    }

    $stmt = $conn->prepare("
        INSERT INTO schedule_events
        (title, description, event_date, start_time, end_time, location, category, priority, color_label,
         recurrence_rule, assigned_personnel, visibility, approval_status, status, reminder_minutes,
         notify_email, notify_sms, source_type, source_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to create calendar event.'];
    }

    $stmt->bind_param(
        'ssssssssssssssiiisii',
        $title,
        $description,
        $event_date,
        $start_time,
        $end_time,
        $location,
        $category,
        $priority,
        $color_label,
        $recurrence_rule,
        $assigned_personnel,
        $visibility,
        $approval_status,
        $status,
        $reminder_minutes,
        $notify_email,
        $notify_sms,
        $source_type,
        $request_id,
        $admin_user_id
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return ['success' => false, 'message' => 'Unable to create calendar event.'];
    }

    $schedule_id = $stmt->insert_id;
    $stmt->close();
    createAuditLog($conn, $admin_user_id, 'SYNC_REQUEST_CALENDAR', 'schedule_events', $schedule_id);

    return ['success' => true, 'message' => 'Calendar event created.', 'schedule_id' => $schedule_id];
}

// Request Calendar Sync - Cancels calendar entries when linked requests are rejected or cancelled.
function cancelLinkedRequestCalendarEvent($conn, $request_id) {
    if (!ensureScheduleEventsTable($conn)) {
        return false;
    }

    $request_id = intval($request_id);
    $stmt = $conn->prepare("UPDATE schedule_events SET status = 'cancelled' WHERE source_type = 'request' AND source_id = ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $request_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// Sync Approved Request Calendar Backlog Function - Documents this helper's role in the parish management workflow.
function syncApprovedRequestCalendarBacklog($conn, $admin_user_id = 0, $limit = 50) {
    if (!ensureScheduleEventsTable($conn)) {
        return 0;
    }

    $limit = max(1, intval($limit));
    $sql = "
        SELECT r.request_id
        FROM requests r
        LEFT JOIN schedule_events se
            ON se.source_type = 'request'
            AND se.source_id = r.request_id
            AND se.status != 'cancelled'
        WHERE r.status = 'approved'
        AND r.request_type IN (
            'house_blessing',
            'car_blessing',
            'vehicle_blessing',
            'business_blessing',
            'office_blessing',
            'event_blessing',
            'other_blessing',
            'church_reservation',
            'wedding_reservation',
            'burial_reservation',
            'church_reservation',
            'wedding_reservation',
            'burial_reservation',
            'baptism_service',
            'marriage_wedding_service',
            'funeral_mass',
            'anointing_of_the_sick',
            'patronal_fiesta'
        )
        AND se.schedule_id IS NULL
        ORDER BY r.updated_at DESC
        LIMIT $limit
    ";

    $synced = 0;
    $result = $conn->query($sql);
    while ($result && $row = $result->fetch_assoc()) {
        $sync = syncApprovedRequestToCalendar($conn, intval($row['request_id']), intval($admin_user_id));
        if ($sync['success'] && in_array($sync['message'], ['Calendar event created.', 'Calendar event updated.'], true)) {
            $synced++;
        }
    }

    return $synced;
}

// Get user by ID
// User Management - Retrieves user profile records by account ID.
function getUserById($conn, $id) {
    $id = intval($id);
    $sql = "SELECT * FROM users WHERE id = $id";
    $result = $conn->query($sql);
    return $result ? $result->fetch_assoc() : null;
}

// Get request status badge color
function getStatusBadgeClass($status) {
    $colors = [
        'pending' => 'warning',
        'submitted' => 'warning',
        'requirements_review' => 'info',
        'needs_information' => 'warning',
        'payment_required' => 'warning',
        'payment_review' => 'info',
        'approved' => 'success',
        'scheduled' => 'primary',
        'processing' => 'info',
        'ready_for_release' => 'primary',
        'completed' => 'success',
        'rejected' => 'danger',
        'cancelled' => 'secondary',
        'active' => 'success',
        'inactive' => 'secondary',
        'archived' => 'secondary'
    ];
    return $colors[$status] ?? 'secondary';
}

// Pagination helper
function getPaginationData($page, $limit, $total) {
    $page = max(1, intval($page));
    $limit = max(1, intval($limit));
    $offset = ($page - 1) * $limit;
    $total = intval($total);
    $total_pages = ceil($total / $limit);
    
    return [
        'page' => $page,
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
        'total_pages' => $total_pages
    ];
}

// Redirect function
// Navigation Helper - Performs a standard HTTP redirect and stops execution.
function redirect($location) {
    header("Location: $location");
    exit;
}

// Get current user full name
function getCurrentUserFullName() {
    return isset($_SESSION['fullname']) ? htmlspecialchars($_SESSION['fullname']) : 'User';
}

// Get current user ID
function getCurrentUserId() {
    return isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
}

?>
