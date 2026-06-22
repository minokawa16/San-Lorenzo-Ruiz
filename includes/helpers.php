<?php
/**
 * Helper Functions and Authentication
 * AI-Powered Parish Request and Sacramental Records Management System
 * Provides utility functions for authentication, validation, and security
 */

if (!defined('BASE_URL')) {
    define('BASE_URL', '/ParishSystem/');
}

if (!function_exists('t')) {
    include_once __DIR__ . '/i18n.php';
}

// Core Utilities - Provides common formatting, escaping, validation, and password helpers.
// Generate unique reference numbers for requests
function generateReferenceNumber() {
    return 'PRQ-' . date('Ymd') . '-' . mt_rand(10000, 99999);
}

// Sanitize input data - remove special characters and trim whitespace
function sanitize($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

// Escape Output Function - Documents this helper's role in the parish management workflow.
function e($data) {
    return htmlspecialchars((string) $data, ENT_QUOTES, 'UTF-8');
}

// Validate email format
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function isValidPhilippineMobile($phone) {
    return preg_match('/^09\d{9}$/', (string) $phone);
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

// Validate password strength
function isValidPassword($password) {
    // Minimum 8 characters, at least one uppercase, one lowercase, one number
    return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[a-zA-Z\d@$!%*?&]{8,}$/', $password);
}

function csrfTokenName() {
    return defined('CSRF_TOKEN_NAME') ? CSRF_TOKEN_NAME : '_csrf_token';
}

function generateCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $name = csrfTokenName();
    if (empty($_SESSION[$name]) || empty($_SESSION[$name . '_time'])) {
        $_SESSION[$name] = bin2hex(random_bytes(32));
        $_SESSION[$name . '_time'] = time();
    }

    return $_SESSION[$name];
}

function verifyCsrfToken($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

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

function requireValidCsrfToken() {
    $name = csrfTokenName();
    if (!verifyCsrfToken($_POST[$name] ?? '')) {
        http_response_code(403);
        die('Security check failed. Please refresh the page and try again.');
    }
}

// Notification System - Creates in-app alerts for parishioners and staff.
function createNotification($conn, $user_id, $title, $message) {
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
    if (!$stmt) {
        return false;
    }

    $user_id = intval($user_id);
    $stmt->bind_param('iss', $user_id, $title, $message);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function createNotificationSafe($conn, $user_id, $title, $message) {
    if (!$conn || !tableExists($conn, 'notifications')) {
        return false;
    }

    return createNotification($conn, $user_id, $title, $message);
}

// Email Notification Schema - Prepares verification, OTP, preferences, and delivery log tables.
function ensureEmailNotificationSchema($conn) {
    ensureUserVerificationSchema($conn);

    $conn->query("CREATE TABLE IF NOT EXISTS email_verifications (
        verification_id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        email VARCHAR(150) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        verified_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_email_verifications_user (user_id),
        INDEX idx_email_verifications_token (token_hash)
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS otp_codes (
        otp_id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        email VARCHAR(150) NOT NULL,
        purpose VARCHAR(40) NOT NULL,
        otp_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        attempts INT DEFAULT 0,
        verified_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_otp_user_purpose (user_id, purpose),
        INDEX idx_otp_email_purpose (email, purpose)
    )");

    $conn->query("DELETE FROM otp_codes WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");

    $conn->query("CREATE TABLE IF NOT EXISTS sms_notification_logs (
        log_id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NULL,
        phone_number VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        notification_type VARCHAR(80) DEFAULT 'system',
        delivery_status VARCHAR(30) DEFAULT 'pending',
        error_message TEXT NULL,
        sent_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sms_logs_phone (phone_number),
        INDEX idx_sms_logs_created (created_at)
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS notification_logs (
        log_id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NULL,
        email VARCHAR(150) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        notification_type VARCHAR(80) DEFAULT 'system',
        delivery_status VARCHAR(30) DEFAULT 'pending',
        error_message TEXT NULL,
        sent_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notification_logs_status (delivery_status),
        INDEX idx_notification_logs_created (created_at)
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS announcement_recipients (
        recipient_id INT PRIMARY KEY AUTO_INCREMENT,
        announcement_id INT NOT NULL,
        user_id INT NOT NULL,
        email VARCHAR(150) NOT NULL,
        delivery_status VARCHAR(30) DEFAULT 'pending',
        sent_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_announcement_user (announcement_id, user_id)
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS notification_preferences (
        preference_id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        category VARCHAR(80) NOT NULL,
        email_enabled TINYINT(1) DEFAULT 1,
        in_app_enabled TINYINT(1) DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_category (user_id, category)
    )");

    $columns = [
        'email_verified_at' => "ALTER TABLE users ADD COLUMN email_verified_at TIMESTAMP NULL DEFAULT NULL AFTER email",
        'email_verification_sent_at' => "ALTER TABLE users ADD COLUMN email_verification_sent_at TIMESTAMP NULL DEFAULT NULL AFTER email_verified_at",
        'phone_verified_at' => "ALTER TABLE users ADD COLUMN phone_verified_at TIMESTAMP NULL DEFAULT NULL AFTER email_verification_sent_at",
        'verification_method' => "ALTER TABLE users ADD COLUMN verification_method ENUM('email','mobile') DEFAULT 'email' AFTER phone_verified_at",
        'login_otp_enabled' => "ALTER TABLE users ADD COLUMN login_otp_enabled TINYINT(1) DEFAULT 0 AFTER verification_method"
    ];

    foreach ($columns as $column => $sql) {
        if (!columnExists($conn, 'users', $column)) {
            $conn->query($sql);
        }
    }

    $conn->query("UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE status = 'active' AND email_verified_at IS NULL");
}

// Mail Configuration - Loads email sender settings with safe local defaults.
function tugonMailConfig() {
    $defaults = [
        'from_email' => 'no-reply@tugon.local',
        'from_name' => 'TUGON Parish System',
        'reply_to' => '',
        'enabled' => true
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

// Email Delivery - Sends email when enabled and records each delivery attempt.
function sendTugonEmail($conn, $to, $subject, $html_body, $text_body = '', $user_id = null, $type = 'system') {
    $config = tugonMailConfig();
    $status = 'failed';
    $error = '';
    $ok = false;

    if (!empty($config['enabled'])) {
        $from = $config['from_email'];
        $from_name = $config['from_name'];
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
            $error = 'PHP mail() failed. Configure SMTP/sendmail in XAMPP or add an SMTP provider.';
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

// Tugon Email Template Function - Documents this helper's role in the parish management workflow.
function tugonEmailTemplate($title, $body, $button_label = '', $button_url = '') {
    $button = '';
    if ($button_label !== '' && $button_url !== '') {
        $button = '<p style="margin:24px 0;"><a href="' . e($button_url) . '" style="background:#d7ad43;color:#171205;padding:12px 18px;border-radius:8px;text-decoration:none;font-weight:700;">' . e($button_label) . '</a></p>';
    }
    return '<div style="font-family:Arial,sans-serif;background:#f7f9fc;padding:24px;color:#172033;">'
        . '<div style="max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;">'
        . '<h2 style="margin:0 0 10px;">' . e($title) . '</h2>'
        . '<div style="line-height:1.6;color:#334155;">' . $body . '</div>'
        . $button
        . '<p style="border-top:1px solid #e5e7eb;margin-top:24px;padding-top:14px;color:#64748b;font-size:13px;">San Lorenzo Ruiz Mission Station | TUGON Parish System</p>'
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
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL;
    $url = $base . 'auth/verify-email.php?token=' . urlencode($token);
    $body = '<p>Hello ' . e($fullname ?: 'Parishioner') . ',</p><p>Please verify your Gmail address to continue your TUGON registration.</p><p>This verification link expires in 24 hours.</p>';
    return sendTugonEmail($conn, $email, 'Verify your TUGON Gmail account', tugonEmailTemplate('Gmail Verification', $body, 'Verify Gmail', $url), '', $user_id, 'email_verification');
}

// OTP Security - Generates short-lived one-time passcodes for account verification.
function createOtpCode($conn, $user_id, $email, $purpose = 'registration') {
    ensureEmailNotificationSchema($conn);
    $conn->query("DELETE FROM otp_codes WHERE expires_at < NOW()");

    $window_start = date('Y-m-d H:i:s', time() - 900);
    $limit = $conn->prepare("SELECT COUNT(*) as total FROM otp_codes WHERE email = ? AND purpose = ? AND created_at >= ?");
    if ($limit) {
        $limit->bind_param('sss', $email, $purpose, $window_start);
        $limit->execute();
        $count = $limit->get_result()->fetch_assoc();
        $limit->close();
        if (intval($count['total'] ?? 0) >= 4) {
            return ['ok' => false, 'error' => 'Maximum OTP resend requests reached. Please wait 15 minutes before requesting another code.'];
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
    $created = createOtpCode($conn, $user_id, $email, $purpose);
    if (!$created['ok']) {
        return $created;
    }
    $body = '<p>Your TUGON verification code is:</p><p style="font-size:28px;font-weight:800;letter-spacing:6px;">' . e($created['otp']) . '</p><p>This OTP expires in 5 minutes. Do not share it with anyone.</p>';
    $sent = sendTugonEmail($conn, $email, 'Your TUGON OTP Code', tugonEmailTemplate('One-Time Password', $body), '', $user_id, 'otp_' . $purpose);
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

    $message = 'Your TUGON OTP code is ' . $created['otp'] . '. It expires in 5 minutes. Do not share it.';
    $ok = false;
    $error = '';
    $sent_at = null;

    require_once __DIR__ . '/../config/security.php';
    if (defined('TWILIO_ACCOUNT_SID') && TWILIO_ACCOUNT_SID !== '' && defined('TWILIO_AUTH_TOKEN') && TWILIO_AUTH_TOKEN !== '' && defined('TWILIO_PHONE_NUMBER') && TWILIO_PHONE_NUMBER !== '' && function_exists('curl_init')) {
        $to = '+63' . substr($phone_number, 1);
        $payload = http_build_query([
            'To' => $to,
            'From' => TWILIO_PHONE_NUMBER,
            'Body' => $message
        ]);
        $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode(TWILIO_ACCOUNT_SID) . '/Messages.json');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN,
            CURLOPT_TIMEOUT => 15
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        $ok = $status >= 200 && $status < 300;
        $error = $ok ? '' : ($curl_error ?: 'Twilio SMS failed with HTTP status ' . $status . '.');
        $sent_at = $ok ? date('Y-m-d H:i:s') : null;
    } else {
        $ok = true;
        $sent_at = date('Y-m-d H:i:s');
        $error = 'SMS provider is not configured or cURL is unavailable. OTP was recorded in the local SMS log for testing.';
    }

    $stmt = $conn->prepare("INSERT INTO sms_notification_logs (user_id, phone_number, message, notification_type, delivery_status, error_message, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $uid = intval($user_id);
        $type = 'otp_' . $purpose;
        $status = $ok ? 'sent' : 'failed';
        $stmt->bind_param('issssss', $uid, $phone_number, $message, $type, $status, $error, $sent_at);
        $stmt->execute();
        $stmt->close();
    }

    return ['ok' => $ok, 'error' => $error, 'expires_at' => $created['expires_at']];
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
    $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'users/my-requests.php?q=' . urlencode($reference_number);
    $body = '<p>Hello ' . e($user['fullname']) . ',</p><p>Your ' . e($request_label) . ' was submitted successfully.</p><p>Reference number: <strong>' . e($reference_number) . '</strong></p><p>You will receive updates when the parish office reviews your request.</p>';
    return sendTugonEmail($conn, $user['email'], 'TUGON Request Submitted - ' . $reference_number, tugonEmailTemplate('Request Submitted', $body, 'Track Request', $url), '', $user_id, 'request_submitted');
}

// Column Exists Function - Documents this helper's role in the parish management workflow.
function columnExists($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $column_safe = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column_safe'");
    return $result && $result->num_rows > 0;
}

// Table Exists Function - Documents this helper's role in the parish management workflow.
function tableExists($conn, $table) {
    $table_safe = $conn->real_escape_string(preg_replace('/[^a-zA-Z0-9_]/', '', $table));
    $result = $conn->query("SHOW TABLES LIKE '$table_safe'");
    return $result && $result->num_rows > 0;
}

// Chatbot Logging - Stores AI assistant questions and answers for audit and analysis.
function ensureChatbotInquirySchema($conn) {
    if (!$conn) {
        return false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS chatbot_inquiries (
        inquiry_id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NULL,
        user_role VARCHAR(30) DEFAULT 'user',
        question TEXT NOT NULL,
        answer_preview TEXT,
        mode VARCHAR(40) DEFAULT 'chat',
        context_limited TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_chatbot_inquiries_created (created_at),
        INDEX idx_chatbot_inquiries_user (user_id)
    )";

    return (bool) $conn->query($sql);
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

// Request Management - Extends request records with detailed service and reservation fields.
function ensureExpandedRequestTypeSchema($conn) {
    if (!$conn) {
        return false;
    }

    $result = $conn->query("SHOW TABLES LIKE 'requests'");
    if (!$result || $result->num_rows === 0) {
        return false;
    }

    return (bool) $conn->query("ALTER TABLE requests MODIFY COLUMN request_type VARCHAR(80) NOT NULL");
}

// Request Documents - Prepares file metadata storage for uploaded parish requirements.
function ensureRequestDocumentsSchema($conn) {
    if (!$conn || !tableExists($conn, 'requests')) {
        return false;
    }

    $sql = "CREATE TABLE IF NOT EXISTS request_documents (
        document_id INT PRIMARY KEY AUTO_INCREMENT,
        request_id INT NOT NULL,
        uploaded_by INT NOT NULL,
        document_type VARCHAR(60) DEFAULT 'requirement',
        file_path VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(120) NOT NULL,
        file_size INT UNSIGNED NOT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_request_documents_request (request_id),
        INDEX idx_request_documents_uploader (uploaded_by)
    )";

    return (bool) $conn->query($sql);
}

// Request Payments - Stores parishioner receipt submissions tied to the parent request.
function ensureRequestPaymentsSchema($conn) {
    if (!$conn || !tableExists($conn, 'requests')) {
        return false;
    }

    ensureRequestDocumentsSchema($conn);

    $sql = "CREATE TABLE IF NOT EXISTS request_payments (
        payment_id INT PRIMARY KEY AUTO_INCREMENT,
        request_id INT NOT NULL,
        user_id INT NOT NULL,
        receipt_document_id INT NULL,
        amount DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(60) NOT NULL,
        reference_number VARCHAR(120) NULL,
        notes TEXT NULL,
        status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
        admin_remarks TEXT NULL,
        verified_by INT NULL,
        verified_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_request_payments_request (request_id),
        INDEX idx_request_payments_user (user_id),
        INDEX idx_request_payments_status (status)
    )";

    return (bool) $conn->query($sql);
}

// Get Request Document Config Function - Documents this helper's role in the parish management workflow.
function getRequestDocumentConfig() {
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

// Is Request Image Document Function - Documents this helper's role in the parish management workflow.
function isRequestImageDocument($mime_type) {
    return in_array((string) $mime_type, ['image/jpeg', 'image/png', 'image/gif'], true);
}

// Request Documents - Validates, stores, and records uploaded supporting documents.
function saveRequestDocument($conn, $request_id, $uploaded_by, $file, $document_type = 'requirement') {
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

    $db_path = 'uploads/request_requirements/' . $safe_filename;
    $stmt = $conn->prepare("INSERT INTO request_documents (request_id, uploaded_by, document_type, file_path, original_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        @unlink($target_path);
        return ['ok' => false, 'error' => 'Unable to prepare the file record.'];
    }

    $request_id = intval($request_id);
    $uploaded_by = intval($uploaded_by);
    $stmt->bind_param('iissssi', $request_id, $uploaded_by, $document_type, $db_path, $original_name, $mime_type, $size);
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
    if (!$conn) {
        return false;
    }

    $result = $conn->query("SHOW TABLES LIKE 'announcements'");
    if (!$result || $result->num_rows === 0) {
        return false;
    }

    return (bool) $conn->query("ALTER TABLE announcements MODIFY COLUMN type VARCHAR(50) DEFAULT 'announcement'");
}

// Announcement Attachments - Prepares secure file metadata storage for announcement uploads.
function ensureAnnouncementAttachmentSchema($conn) {
    if (!$conn || !tableExists($conn, 'announcements')) {
        return false;
    }

    $columns = [
        'attachment_path' => "ALTER TABLE announcements ADD COLUMN attachment_path VARCHAR(255) NULL AFTER image_path",
        'attachment_original_name' => "ALTER TABLE announcements ADD COLUMN attachment_original_name VARCHAR(255) NULL AFTER attachment_path",
        'attachment_mime_type' => "ALTER TABLE announcements ADD COLUMN attachment_mime_type VARCHAR(120) NULL AFTER attachment_original_name",
        'attachment_size' => "ALTER TABLE announcements ADD COLUMN attachment_size INT UNSIGNED NULL AFTER attachment_mime_type"
    ];

    foreach ($columns as $column => $sql) {
        if (!columnExists($conn, 'announcements', $column)) {
            $conn->query($sql);
        }
    }

    return true;
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

// User Verification - Adds registration approval, identity review, and encrypted ID fields.
function ensureUserVerificationSchema($conn) {
    $conn->query("ALTER TABLE users MODIFY COLUMN status ENUM('active','inactive','pending_verification','rejected','archived') DEFAULT 'active'");

    $columns = [
        'first_name' => "ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER fullname",
        'surname' => "ALTER TABLE users ADD COLUMN surname VARCHAR(100) NULL AFTER first_name",
        'middle_initial' => "ALTER TABLE users ADD COLUMN middle_initial VARCHAR(5) NULL AFTER surname",
        'address' => "ALTER TABLE users ADD COLUMN address VARCHAR(255) NULL AFTER chapel_district",
        'birthdate' => "ALTER TABLE users ADD COLUMN birthdate DATE NULL AFTER address",
        'birth_place' => "ALTER TABLE users ADD COLUMN birth_place VARCHAR(150) NULL AFTER birthdate",
        'id_number_hash' => "ALTER TABLE users ADD COLUMN id_number_hash CHAR(64) NULL AFTER birth_place",
        'id_number_encrypted' => "ALTER TABLE users ADD COLUMN id_number_encrypted TEXT NULL AFTER id_number_hash",
        'valid_id_path' => "ALTER TABLE users ADD COLUMN valid_id_path VARCHAR(255) NULL AFTER profile_picture",
        'valid_id_original_name' => "ALTER TABLE users ADD COLUMN valid_id_original_name VARCHAR(255) NULL AFTER valid_id_path",
        'valid_id_mime_type' => "ALTER TABLE users ADD COLUMN valid_id_mime_type VARCHAR(100) NULL AFTER valid_id_original_name",
        'valid_id_capture_method' => "ALTER TABLE users ADD COLUMN valid_id_capture_method VARCHAR(40) DEFAULT 'live_camera' AFTER valid_id_mime_type",
        'face_image_path' => "ALTER TABLE users ADD COLUMN face_image_path VARCHAR(255) NULL AFTER valid_id_capture_method",
        'face_image_mime_type' => "ALTER TABLE users ADD COLUMN face_image_mime_type VARCHAR(100) NULL AFTER face_image_path",
        'face_verification_status' => "ALTER TABLE users ADD COLUMN face_verification_status VARCHAR(40) DEFAULT 'pending' AFTER face_image_mime_type",
        'face_verified_at' => "ALTER TABLE users ADD COLUMN face_verified_at TIMESTAMP NULL DEFAULT NULL AFTER face_verification_status",
        'ocr_extracted_text_encrypted' => "ALTER TABLE users ADD COLUMN ocr_extracted_text_encrypted MEDIUMTEXT NULL AFTER face_verified_at",
        'ocr_extracted_data_encrypted' => "ALTER TABLE users ADD COLUMN ocr_extracted_data_encrypted TEXT NULL AFTER ocr_extracted_text_encrypted",
        'ocr_match_score' => "ALTER TABLE users ADD COLUMN ocr_match_score TINYINT UNSIGNED DEFAULT 0 AFTER ocr_extracted_data_encrypted",
        'ocr_status' => "ALTER TABLE users ADD COLUMN ocr_status VARCHAR(40) DEFAULT 'manual_review' AFTER ocr_match_score",
        'ocr_processed_at' => "ALTER TABLE users ADD COLUMN ocr_processed_at TIMESTAMP NULL DEFAULT NULL AFTER ocr_status",
        'rejection_reason' => "ALTER TABLE users ADD COLUMN rejection_reason TEXT NULL AFTER ocr_processed_at",
        'verified_at' => "ALTER TABLE users ADD COLUMN verified_at TIMESTAMP NULL DEFAULT NULL AFTER rejection_reason",
        'verified_by' => "ALTER TABLE users ADD COLUMN verified_by INT NULL AFTER verified_at"
    ];

    foreach ($columns as $column => $sql) {
        if (!columnExists($conn, 'users', $column)) {
            $conn->query($sql);
        }
    }

    if (!indexExists($conn, 'users', 'idx_users_id_number_hash')) {
        $conn->query("CREATE INDEX idx_users_id_number_hash ON users (id_number_hash)");
    }
}

// Index Exists Function - Documents this helper's role in the parish management workflow.
function indexExists($conn, $table, $index) {
    $table_safe = $conn->real_escape_string($table);
    $index_safe = $conn->real_escape_string($index);
    $result = $conn->query("SHOW INDEX FROM `$table_safe` WHERE Key_name = '$index_safe'");
    return $result && $result->num_rows > 0;
}

// Identity Protection - Derives the encryption key used for sensitive verification assets.
function getVerificationEncryptionKey() {
    $configured = getenv('PARISH_VERIFICATION_KEY');
    if ($configured) {
        return hash('sha256', $configured, true);
    }

    $seed = (defined('DB_NAME') ? DB_NAME : 'parish') . '|' . __DIR__ . '|verification-documents';
    return hash('sha256', $seed, true);
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
    $plain = openssl_decrypt($ciphertext, 'AES-256-CBC', getVerificationEncryptionKey(), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
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

function normalizeOcrText($value) {
    $value = strtolower((string) $value);
    $value = strtr($value, [
        '0' => 'o',
        '1' => 'l',
        '|' => 'l',
        '5' => 's',
        '8' => 'b'
    ]);
    return preg_replace('/[^a-z0-9]/i', '', $value);
}

function preprocessOcrImage($image_path) {
    if (!function_exists('imagecreatefromstring') || !is_file($image_path)) {
        return $image_path;
    }

    $binary = file_get_contents($image_path);
    $source = $binary !== false ? @imagecreatefromstring($binary) : false;
    if (!$source) {
        return $image_path;
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $scale = max(1, min(4, (int) ceil(2400 / max(1, $width))));
    $target_width = $width * $scale;
    $target_height = $height * $scale;
    $processed = imagecreatetruecolor($target_width, $target_height);

    imagecopyresampled($processed, $source, 0, 0, 0, 0, $target_width, $target_height, $width, $height);
    imagefilter($processed, IMG_FILTER_GRAYSCALE);
    imagefilter($processed, IMG_FILTER_CONTRAST, -34);
    imagefilter($processed, IMG_FILTER_BRIGHTNESS, 10);
    if (function_exists('imageconvolution')) {
        imageconvolution($processed, [
            [0, -1, 0],
            [-1, 5, -1],
            [0, -1, 0]
        ], 1, 0);
    }

    $processed_path = tempnam(sys_get_temp_dir(), 'tugon_ocr_clean_');
    if ($processed_path === false) {
        imagedestroy($source);
        imagedestroy($processed);
        return $image_path;
    }
    $processed_path .= '.png';
    imagepng($processed, $processed_path);
    imagedestroy($source);
    imagedestroy($processed);

    return $processed_path;
}

function findLocalTesseractCommand() {
    $commands = ['tesseract', 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe'];
    foreach ($commands as $command) {
        $probe = @shell_exec('"' . $command . '" --version 2>&1');
        if (is_string($probe) && stripos($probe, 'tesseract') !== false) {
            return $command;
        }
    }

    return '';
}

// OCR Verification - Extracts text from valid ID images when local OCR tools are available.
function runValidIdOcr($image_path) {
    $result = [
        'available' => false,
        'text' => '',
        'error' => ''
    ];

    if (!is_file($image_path)) {
        $result['error'] = 'ID image file was not found.';
        return $result;
    }

    $command = findLocalTesseractCommand();
    if ($command !== '') {
        $ocr_image = preprocessOcrImage($image_path);
        $ocr_images = array_values(array_unique(array_filter([$ocr_image, $image_path])));
        $tessdata_flags = '--oem 1 -l eng -c preserve_interword_spaces=1';
        $psm_modes = [6, 11, 4, 3, 12];
        $best_text = '';
        $best_error = '';

        foreach ($ocr_images as $candidate_image) {
            foreach ($psm_modes as $psm) {
                $output_base = tempnam(sys_get_temp_dir(), 'parish_ocr_');
                if ($output_base === false) {
                    $result['error'] = 'Unable to create OCR temporary file.';
                    return $result;
                }
                @unlink($output_base);

                $ocr_command = '"' . $command . '" "' . $candidate_image . '" "' . $output_base . '" ' . $tessdata_flags . ' --psm ' . intval($psm) . ' 2>&1';
                $command_output = @shell_exec($ocr_command);
                $output_file = $output_base . '.txt';
                $text = is_file($output_file) ? trim((string) file_get_contents($output_file)) : '';
                @unlink($output_file);
                if (strlen($text) > strlen($best_text)) {
                    $best_text = $text;
                }
                if (is_string($command_output) && trim($command_output) !== '') {
                    $best_error = trim($command_output);
                }
            }
        }

        if ($ocr_image !== $image_path && is_file($ocr_image)) {
            @unlink($ocr_image);
        }

        $result['available'] = true;
        $result['text'] = $best_text;
        $result['error'] = $best_error;
        return $result;
    }

    $result['error'] = 'OCR engine is not installed or not available to PHP.';
    return $result;
}

function combineOcrResults($results) {
    $combined = [
        'available' => false,
        'text' => '',
        'error' => ''
    ];
    $errors = [];

    foreach ($results as $index => $result) {
        if (!is_array($result)) {
            continue;
        }
        $combined['available'] = $combined['available'] || !empty($result['available']);
        $label = $index === 0 ? 'FRONT ID' : 'BACK ID';
        $text = trim((string) ($result['text'] ?? ''));
        if ($text !== '') {
            $combined['text'] .= ($combined['text'] === '' ? '' : "\n\n") . '[' . $label . "]\n" . $text;
        }
        $error = trim((string) ($result['error'] ?? ''));
        if ($error !== '') {
            $errors[] = $label . ': ' . $error;
        }
    }

    $combined['error'] = implode(' | ', $errors);
    return $combined;
}

function cleanOcrNameValue($value) {
    $value = preg_replace('/[^A-Za-zÑñ\s\.,-]/u', ' ', (string) $value);
    $value = preg_replace('/\b(REPUBLIC|PILIPINAS|PHILIPPINES|GOVERNMENT|VALID|UNTIL|SIGNATURE|ADDRESS|BIRTH|DATE|SEX|NATIONALITY|CARD|NUMBER|ID)\b/i', ' ', $value);
    return trim(preg_replace('/\s+/', ' ', $value));
}

function extractOcrLabelValue($text, $lines, $labels) {
    $label_pattern = implode('|', array_map(function ($label) {
        return preg_quote($label, '/');
    }, $labels));

    if (preg_match('/(?:' . $label_pattern . ')\s*[:\-]?\s*([A-ZÑ][A-ZÑ\s\.,-]{1,60})/iu', $text, $matches)) {
        return trim($matches[1]);
    }

    foreach ($lines as $index => $line) {
        if (preg_match('/^(?:' . $label_pattern . ')\s*[:\-]?$/iu', trim($line)) && isset($lines[$index + 1])) {
            return trim($lines[$index + 1]);
        }
        if (preg_match('/(?:' . $label_pattern . ')\s*[:\-]?\s*(.+)$/iu', $line, $matches)) {
            return trim($matches[1]);
        }
    }

    return '';
}

function splitOcrFullName($full_name) {
    $name = cleanOcrNameValue($full_name);
    $parts = array_values(array_filter(preg_split('/\s+/', $name)));
    $result = [
        'first_name' => '',
        'surname' => '',
        'middle_initial' => ''
    ];

    if (count($parts) < 2) {
        return $result;
    }

    $result['surname'] = strtoupper(array_pop($parts));
    if (count($parts) >= 2 && preg_match('/^[A-ZÑ]\.?$/i', end($parts))) {
        $result['middle_initial'] = strtoupper(substr(preg_replace('/[^A-Za-zÑñ]/u', '', array_pop($parts)), 0, 1));
    }
    $result['first_name'] = strtoupper(trim(implode(' ', $parts)));
    return $result;
}

// OCR Verification - Parses ID type, name, birth date, and number from extracted text.
function extractValidIdData($ocr_text) {
    $text = trim((string) $ocr_text);
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R+/', $text))));
    $data = [
        'full_name' => '',
        'surname' => '',
        'first_name' => '',
        'middle_initial' => '',
        'birthdate' => '',
        'birth_place' => '',
        'address' => '',
        'id_number' => ''
    ];

    $data['surname'] = strtoupper(cleanOcrNameValue(extractOcrLabelValue($text, $lines, ['surname', 'apelyido', 'last name'])));
    $data['first_name'] = strtoupper(cleanOcrNameValue(extractOcrLabelValue($text, $lines, ['first name', 'given name', 'given names', 'pangalan'])));
    $data['middle_initial'] = strtoupper(substr(preg_replace('/[^A-Za-zÑñ]/u', '', cleanOcrNameValue(extractOcrLabelValue($text, $lines, ['middle initial', 'middle name', 'gitnang apelyido']))), 0, 1));

    if (preg_match('/(?:name|pangalan|full\s*name|apelyido.*pangalan|given\s*name|surname)\s*[:\-]?\s*([A-Z][A-Z\s\.,-]{4,})/i', $text, $matches)) {
        $data['full_name'] = cleanOcrNameValue($matches[1]);
    } elseif (!empty($lines)) {
        $name_lines = [];
        foreach ($lines as $line) {
            if (preg_match('/[A-Za-z]{2,}\s+[A-Za-z]{2,}/', $line) && !preg_match('/republic|address|birth|date|signature|license|identification|philippines|government|valid|until|sex|nationality/i', $line)) {
                $data['full_name'] = cleanOcrNameValue($line);
                break;
            }
            $clean_line = trim(preg_replace('/[^A-Za-z\s\.,-]/', ' ', $line));
            $clean_line = preg_replace('/\s+/', ' ', $clean_line);
            if (strlen($clean_line) >= 3 && strlen($clean_line) <= 48 && !preg_match('/republic|address|birth|date|signature|license|identification|philippines|government|valid|until|sex|nationality|male|female|card|number|philsys|postal|blood/i', $clean_line)) {
                $name_lines[] = $clean_line;
            }
        }
        if ($data['full_name'] === '' && count($name_lines) >= 2) {
            $data['full_name'] = cleanOcrNameValue(implode(' ', array_slice($name_lines, 0, 3)));
        }
    }

    if ($data['full_name'] === '' && ($data['first_name'] !== '' || $data['surname'] !== '')) {
        $data['full_name'] = trim($data['first_name'] . ' ' . ($data['middle_initial'] !== '' ? $data['middle_initial'] . '. ' : '') . $data['surname']);
    }

    if ($data['first_name'] === '' || $data['surname'] === '') {
        $split_name = splitOcrFullName($data['full_name']);
        $data['first_name'] = $data['first_name'] !== '' ? $data['first_name'] : $split_name['first_name'];
        $data['surname'] = $data['surname'] !== '' ? $data['surname'] : $split_name['surname'];
        $data['middle_initial'] = $data['middle_initial'] !== '' ? $data['middle_initial'] : $split_name['middle_initial'];
    }

    if (preg_match('/(?:birth(?:date)?|date of birth|dob|kapanganakan|petsa\s+ng\s+kapanganakan)\s*[:\-]?\s*([0-9]{1,2}[\/\-.][0-9]{1,2}[\/\-.][0-9]{2,4}|[0-9]{1,2}\s+[A-Za-z]{3,9}\s+\d{4}|[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4})/i', $text, $matches)) {
        $timestamp = strtotime($matches[1]);
        $data['birthdate'] = $timestamp ? date('Y-m-d', $timestamp) : '';
    } elseif (preg_match('/\b([0-9]{4}[\/\-.][0-9]{1,2}[\/\-.][0-9]{1,2}|[0-9]{1,2}[\/\-.][0-9]{1,2}[\/\-.][0-9]{2,4}|[0-9]{1,2}\s+[A-Za-z]{3,9}\s+\d{4}|[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4})\b/', $text, $matches)) {
        $timestamp = strtotime($matches[1]);
        $data['birthdate'] = $timestamp ? date('Y-m-d', $timestamp) : '';
    }

    if (preg_match('/(?:place\s+of\s+birth|birth\s+place|pook\s+ng\s+kapanganakan|lugar\s+ng\s+kapanganakan)\s*[:\-]?\s*(.{4,120})/i', $text, $matches)) {
        $data['birth_place'] = trim(preg_replace('/\s+/', ' ', $matches[1]));
    } else {
        foreach ($lines as $line) {
            if (preg_match('/\b(cotabato|aleosan|midsayap|kidapawan|davao|general santos|manila|quezon|cebu|iloilo|province|city|municipality)\b/i', $line)
                && !preg_match('/address|residence|tirahan|valid|until|signature/i', $line)) {
                $data['birth_place'] = trim($line);
                break;
            }
        }
    }

    if (preg_match('/(?:address|tirahan|residence)\s*[:\-]?\s*(.{8,160})/i', $text, $matches)) {
        $data['address'] = trim($matches[1]);
    } else {
        foreach ($lines as $line) {
            if (preg_match('/\b(aleosan|cotabato|barangay|brgy\.?|purok|street|st\.)\b/i', $line)) {
                $data['address'] = $line;
                break;
            }
        }
    }

    if (preg_match('/(?:id(?:\s*no\.?|\s*number)?|card\s*no\.?|license\s*no\.?|crn|tin|sss|umid|philhealth|psn|pcn|philsys\s*(?:card)?\s*(?:no\.?|number)?)\s*[:\-#]?\s*([A-Z0-9\- ]{5,36})/i', $text, $matches)) {
        $data['id_number'] = trim($matches[1]);
    } elseif (preg_match('/\b([A-Z0-9]{2,5}[- ]?[A-Z0-9]{3,6}[- ]?[A-Z0-9]{3,8}(?:[- ]?[A-Z0-9]{2,8})?)\b/', $text, $matches)) {
        $data['id_number'] = trim($matches[1]);
    }

    return $data;
}

function getOcrFieldConfidence($extracted, $checks) {
    $confidence = [];
    foreach (['full_name' => 'name', 'surname' => 'surname', 'first_name' => 'first_name', 'middle_initial' => 'middle_initial', 'birthdate' => 'birthdate', 'birth_place' => 'birth_place', 'address' => 'address', 'id_number' => 'id_number'] as $field => $check_key) {
        $value = trim((string) ($extracted[$field] ?? ''));
        if ($value === '') {
            $confidence[$field] = 0;
        } elseif (!empty($checks[$check_key])) {
            $confidence[$field] = $field === 'id_number' ? 95 : 90;
        } else {
            $confidence[$field] = 45;
        }
    }

    return $confidence;
}

// Identity Matching - Compares submitted registration details against OCR-extracted ID data.
function compareIdentityData($submitted, $extracted, $ocr_text = '') {
    $checks = [];
    $score = 0;
    $normalized_ocr = normalizeOcrText($ocr_text);

    $submitted_name = normalizeIdentityValue($submitted['fullname'] ?? '');
    $extracted_name = normalizeIdentityValue($extracted['full_name'] ?? '');
    similar_text($submitted_name, $extracted_name, $name_percent);
    $submitted_name_ocr = normalizeOcrText($submitted['fullname'] ?? '');
    $name_parts = array_values(array_filter([
        normalizeOcrText($submitted['first_name'] ?? ''),
        normalizeOcrText($submitted['surname'] ?? '')
    ]));
    $matched_name_parts = 0;
    foreach ($name_parts as $name_part) {
        if ($name_part !== '' && strpos($normalized_ocr, $name_part) !== false) {
            $matched_name_parts++;
        }
    }
    $checks['name'] = ($extracted_name !== '' && $name_percent >= 58)
        || ($submitted_name_ocr !== '' && strpos($normalized_ocr, $submitted_name_ocr) !== false)
        || ($submitted_name_ocr !== '' && $extracted_name !== '' && levenshtein(substr($submitted_name_ocr, 0, 60), substr(normalizeOcrText($extracted_name), 0, 60)) <= 8)
        || ($matched_name_parts >= max(1, min(2, count($name_parts))));
    $score += $checks['name'] ? 35 : 0;

    foreach (['surname', 'first_name'] as $name_key) {
        $submitted_part = normalizeIdentityValue($submitted[$name_key] ?? '');
        $extracted_part = normalizeIdentityValue($extracted[$name_key] ?? '');
        $checks[$name_key] = $submitted_part !== '' && (
            ($extracted_part !== '' && ($submitted_part === $extracted_part || levenshtein(substr($submitted_part, 0, 40), substr($extracted_part, 0, 40)) <= 5))
            || strpos($normalized_ocr, normalizeOcrText($submitted[$name_key] ?? '')) !== false
        );
    }

    $submitted_middle = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($submitted['middle_initial'] ?? '')), 0, 1));
    $extracted_middle = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($extracted['middle_initial'] ?? '')), 0, 1));
    $checks['middle_initial'] = $submitted_middle !== '' && ($extracted_middle === '' || $submitted_middle === $extracted_middle || strpos($normalized_ocr, strtolower($submitted_middle)) !== false);

    $submitted_birthdate = (string) ($submitted['birthdate'] ?? '');
    $extracted_birthdate = (string) ($extracted['birthdate'] ?? '');
    $birth_variants = [];
    if ($submitted_birthdate !== '') {
        $ts = strtotime($submitted_birthdate);
        if ($ts) {
            $birth_variants = [
                date('Ymd', $ts),
                date('mdY', $ts),
                date('dmY', $ts),
                strtolower(date('MjdY', $ts)),
                strtolower(date('FjdY', $ts))
            ];
        }
    }
    $checks['birthdate'] = $submitted_birthdate !== '' && ($submitted_birthdate === $extracted_birthdate || count(array_filter($birth_variants, function ($variant) use ($normalized_ocr) {
        return $variant !== '' && strpos($normalized_ocr, normalizeOcrText($variant)) !== false;
    })) > 0);
    $score += $checks['birthdate'] ? 20 : 0;

    $submitted_birth_place = normalizeIdentityValue($submitted['birth_place'] ?? '');
    $extracted_birth_place = normalizeIdentityValue($extracted['birth_place'] ?? '');
    $birth_place_tokens = array_values(array_filter(preg_split('/\s+/', strtolower((string) ($submitted['birth_place'] ?? ''))), function ($token) {
        return strlen(preg_replace('/[^a-z0-9]/i', '', $token)) >= 4;
    }));
    $matched_birth_place_tokens = 0;
    foreach ($birth_place_tokens as $token) {
        if (strpos($normalized_ocr, normalizeOcrText($token)) !== false) {
            $matched_birth_place_tokens++;
        }
    }
    $checks['birth_place'] = $submitted_birth_place !== '' && (
        ($extracted_birth_place !== '' && levenshtein(substr($submitted_birth_place, 0, 80), substr($extracted_birth_place, 0, 80)) <= 35)
        || ($matched_birth_place_tokens >= max(1, min(2, count($birth_place_tokens))))
    );
    $score += $checks['birth_place'] ? 10 : 0;

    $submitted_address = normalizeIdentityValue($submitted['address'] ?? '');
    $extracted_address = normalizeIdentityValue($extracted['address'] ?? '');
    $address_tokens = array_values(array_filter(preg_split('/\s+/', strtolower((string) ($submitted['address'] ?? ''))), function ($token) {
        return strlen(preg_replace('/[^a-z0-9]/i', '', $token)) >= 4;
    }));
    $matched_tokens = 0;
    foreach ($address_tokens as $token) {
        if (strpos($normalized_ocr, normalizeOcrText($token)) !== false) {
            $matched_tokens++;
        }
    }
    $checks['address'] = $extracted_address !== '' && (strpos($extracted_address, 'aleosan') !== false || levenshtein(substr($submitted_address, 0, 80), substr($extracted_address, 0, 80)) <= 45)
        || ($matched_tokens >= max(1, min(3, count($address_tokens))));
    $score += $checks['address'] ? 20 : 0;

    $submitted_id = normalizeIdentityValue($submitted['id_number'] ?? '');
    $extracted_id = normalizeIdentityValue($extracted['id_number'] ?? '');
    $checks['id_number'] = ($submitted_id === '' && $extracted_id !== '') || ($submitted_id !== '' && (
        ($extracted_id !== '' && ($submitted_id === $extracted_id || strpos($extracted_id, $submitted_id) !== false || strpos($submitted_id, $extracted_id) !== false))
        || strpos($normalized_ocr, normalizeOcrText($submitted['id_number'] ?? '')) !== false
        || ($extracted_id !== '' && levenshtein(substr($submitted_id, 0, 32), substr($extracted_id, 0, 32)) <= 3)
    ));
    $score += $checks['id_number'] ? 20 : 0;

    return [
        'score' => min(100, $score),
        'checks' => $checks,
        'status' => $score >= 70 ? 'matched' : ($score > 0 ? 'needs_review' : 'manual_review')
    ];
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

    $ai_api_configured = getenv('OPENAI_API_KEY') || getenv('GEMINI_API_KEY') || getenv('AI_API_KEY');
    $smtp_configured = defined('SMTP_HOST') && SMTP_HOST !== '' && SMTP_HOST !== 'localhost' && defined('SMTP_USERNAME') && SMTP_USERNAME !== '';
    $twilio_configured = defined('TWILIO_ACCOUNT_SID') && TWILIO_ACCOUNT_SID !== '' && defined('TWILIO_AUTH_TOKEN') && TWILIO_AUTH_TOKEN !== '' && defined('TWILIO_PHONE_NUMBER') && TWILIO_PHONE_NUMBER !== '';
    $pdf_library_ready = class_exists('Dompdf\\Dompdf') || class_exists('Mpdf\\Mpdf') || class_exists('TCPDF');
    $tesseract_command = findLocalTesseractCommand();

    $items = [];
    $items[] = integrationStatusItem(
        1,
        'AI Technologies and Third-Party Components',
        $ai_api_configured && $smtp_configured && $twilio_configured && $pdf_library_ready ? 92 : 78,
        $ai_api_configured ? 'Online-ready' : 'Offline-ready',
        $ai_api_configured ? 'External AI credentials detected' : 'Local AI-assisted assistant with offline fallback',
        [
            'AI assistant pages and chatbot inquiry logging are available.',
            $tesseract_command ? 'Local OCR engine detected: ' . basename($tesseract_command) : 'OCR fallback/manual review is available.',
            $twilio_configured ? 'Twilio SMS credentials are configured.' : 'SMS uses local logging until Twilio credentials are added.',
            $smtp_configured ? 'SMTP settings appear configured.' : 'Email uses PHP mail/local server behavior until SMTP is configured.'
        ],
        [
            'Add live AI API credentials only when internet hosting is ready.',
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
        5,
        'OCR-Based Identity Verification Improvement',
        $tesseract_command ? 86 : 78,
        $tesseract_command ? 'OCR available' : 'Manual-review fallback',
        $tesseract_command ? 'Local Tesseract OCR' : 'Offline capture plus admin review',
        [
            'Live face capture and valid ID capture are stored for admin review.',
            'OCR match score/status fields are available.',
            $tesseract_command ? 'Tesseract command is callable by PHP.' : 'Tesseract is not detected; registrations route to review.'
        ],
        [
            'Install Tesseract on the production server.',
            'Define acceptance thresholds in the documentation.',
            'Use a face matching provider only if the parish requires true biometric matching.'
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

// Get OCR Status Label Function - Documents this helper's role in the parish management workflow.
function getOcrStatusLabel($status) {
    $labels = [
        'matched' => 'Matched',
        'needs_review' => 'Needs Review',
        'manual_review' => 'Manual Review',
        'unreadable' => 'Unreadable ID',
        'ocr_unavailable' => 'OCR Unavailable'
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
}

// Get notification count
function getUnreadNotificationCount($conn, $user_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    if (!$stmt) {
        return 0;
    }

    $user_id = intval($user_id);
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
    $stmt = $conn->prepare("SELECT notification_id, title, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    if (!$stmt) {
        return $notifications;
    }

    $user_id = intval($user_id);
    $limit = max(1, intval($limit));
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
    $user_id = !empty($user_id) ? intval($user_id) : 'NULL';
    $action = $conn->real_escape_string($action);
    $table_name = $conn->real_escape_string($table_name);
    $old_value = $old_value ? $conn->real_escape_string(json_encode($old_value)) : 'NULL';
    $new_value = $new_value ? $conn->real_escape_string(json_encode($new_value)) : 'NULL';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $ip = $conn->real_escape_string($ip);
    
    $sql = "INSERT INTO audit_log (user_id, action, table_name, record_id, old_value, new_value, ip_address)
            VALUES ($user_id, '$action', '$table_name', $record_id, '$old_value', '$new_value', '$ip')";
    return $conn->query($sql);
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

// Schedule Event Column Exists Function - Documents this helper's role in the parish management workflow.
function scheduleEventColumnExists($conn, $column) {
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM schedule_events LIKE '$column'");
    return $result && $result->num_rows > 0;
}

// Calendar Schema - Creates and upgrades the shared parish schedule events table.
function ensureScheduleEventsTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS schedule_events (
        schedule_id INT PRIMARY KEY AUTO_INCREMENT,
        title VARCHAR(200) NOT NULL,
        description TEXT,
        event_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NULL,
        location VARCHAR(150),
        category VARCHAR(50) DEFAULT 'event',
        priority VARCHAR(20) DEFAULT 'normal',
        color_label VARCHAR(20) DEFAULT '#1a73e8',
        recurrence_rule VARCHAR(100) DEFAULT 'none',
        assigned_personnel VARCHAR(150),
        visibility ENUM('public', 'private') DEFAULT 'public',
        approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved',
        status ENUM('active', 'upcoming', 'ongoing', 'finished', 'cancelled') DEFAULT 'upcoming',
        reminder_minutes INT DEFAULT 30,
        notify_email TINYINT(1) DEFAULT 0,
        notify_sms TINYINT(1) DEFAULT 0,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
        INDEX idx_schedule_date (event_date),
        INDEX idx_schedule_status_date (status, event_date, start_time)
    )";

    if (!$conn->query($sql)) {
        return false;
    }

    $columns = [
        'category' => "ALTER TABLE schedule_events ADD COLUMN category VARCHAR(50) DEFAULT 'event' AFTER location",
        'priority' => "ALTER TABLE schedule_events ADD COLUMN priority VARCHAR(20) DEFAULT 'normal' AFTER category",
        'color_label' => "ALTER TABLE schedule_events ADD COLUMN color_label VARCHAR(20) DEFAULT '#1a73e8' AFTER priority",
        'recurrence_rule' => "ALTER TABLE schedule_events ADD COLUMN recurrence_rule VARCHAR(100) DEFAULT 'none' AFTER color_label",
        'assigned_personnel' => "ALTER TABLE schedule_events ADD COLUMN assigned_personnel VARCHAR(150) AFTER recurrence_rule",
        'visibility' => "ALTER TABLE schedule_events ADD COLUMN visibility ENUM('public', 'private') DEFAULT 'public' AFTER assigned_personnel",
        'approval_status' => "ALTER TABLE schedule_events ADD COLUMN approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved' AFTER visibility",
        'reminder_minutes' => "ALTER TABLE schedule_events ADD COLUMN reminder_minutes INT DEFAULT 30 AFTER status",
        'notify_email' => "ALTER TABLE schedule_events ADD COLUMN notify_email TINYINT(1) DEFAULT 0 AFTER reminder_minutes",
        'notify_sms' => "ALTER TABLE schedule_events ADD COLUMN notify_sms TINYINT(1) DEFAULT 0 AFTER notify_email",
        'source_type' => "ALTER TABLE schedule_events ADD COLUMN source_type VARCHAR(40) DEFAULT 'manual' AFTER notify_sms",
        'source_id' => "ALTER TABLE schedule_events ADD COLUMN source_id INT NULL AFTER source_type"
    ];

    foreach ($columns as $column => $alterSql) {
        if (!scheduleEventColumnExists($conn, $column) && !$conn->query($alterSql)) {
            return false;
        }
    }

    $conn->query("ALTER TABLE schedule_events MODIFY COLUMN status ENUM('active', 'upcoming', 'ongoing', 'finished', 'cancelled') DEFAULT 'upcoming'");
    $conn->query("ALTER TABLE schedule_events ADD INDEX idx_schedule_source (source_type, source_id)");

    return true;
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
    $event_date = requestCalendarField($description, ['Date of Patronal Fiesta', 'Preferred date', 'Event date', 'Date']);
    if (!validDateValue($event_date)) {
        return ['conflict' => true, 'message' => 'This request has no valid preferred date, so it cannot be approved into the calendar.'];
    }

    $start_time = normalizeRequestCalendarTime(requestCalendarField($description, ['Preferred time', 'Event time', 'Time']));
    return calendarSlotConflict($conn, $event_date, $start_time, null, 'request', $request_id);
}

// Request Calendar Sync - Converts approved service requests into calendar events.
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

    if (!$request || $request['status'] !== 'approved') {
        return ['success' => true, 'message' => 'Request is not approved; calendar sync skipped.'];
    }

    if (!in_array($request['request_type'], $calendar_request_types, true)) {
        return ['success' => true, 'message' => 'Request type does not require calendar sync.'];
    }

    $description = (string) ($request['description'] ?? '');
    $event_date = requestCalendarField($description, ['Date of Patronal Fiesta', 'Preferred date', 'Event date', 'Date']);
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
    $blessing_request_types = ['house_blessing', 'car_blessing', 'vehicle_blessing', 'business_blessing', 'office_blessing', 'event_blessing'];
    $service_request_types = ['baptism_service', 'marriage_wedding_service', 'funeral_mass', 'anointing_of_the_sick', 'patronal_fiesta'];
    $category = in_array($request['request_type'], $blessing_request_types, true) ? 'blessing' : (in_array($request['request_type'], $service_request_types, true) ? 'sacramental' : 'reservation');
    $priority = 'normal';
    $color_label = $category === 'blessing' ? '#d7ad43' : ($category === 'sacramental' ? '#7c3aed' : '#188038');
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
        'approved' => 'success',
        'rejected' => 'danger',
        'processing' => 'info',
        'completed' => 'success',
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

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function normalizeUserRole($role) {
    $role = strtolower(trim((string) $role));
    $aliases = [
        'administrator' => 'admin',
        'staff' => 'parish_staff',
        'parish staff' => 'parish_staff',
        'records clerk' => 'records_clerk',
        'finance' => 'finance_staff',
        'cashier' => 'finance_staff',
        'district_coordinator' => 'coordinator',
        'chapel_coordinator' => 'coordinator',
        'member' => 'user',
        'parishioner' => 'user',
        'volunteer' => 'user',
    ];

    return $aliases[$role] ?? ($role !== '' ? $role : 'guest');
}

function rolePermissions($role) {
    $role = normalizeUserRole($role);
    $permissions = [
        'admin' => ['*'],
        'parish_staff' => [
            'admin.access',
            'dashboard.view',
            'users.view',
            'registrations.verify',
            'requests.manage',
            'records.manage',
            'certificates.manage',
            'announcements.manage',
            'calendar.manage',
            'reservations.manage',
            'reports.view',
            'ai.use',
        ],
        'records_clerk' => [
            'admin.access',
            'dashboard.view',
            'records.manage',
            'certificates.manage',
            'requests.view',
            'reports.view',
        ],
        'finance_staff' => [
            'admin.access',
            'dashboard.view',
            'requests.view',
            'payments.verify',
            'reports.view',
        ],
        'coordinator' => [
            'dashboard.view',
            'members.view_district',
            'announcements.view',
            'calendar.view',
            'reservations.view',
            'ai.use',
        ],
        'user' => [
            'profile.manage',
            'requests.create',
            'requests.view_own',
            'documents.upload_own',
            'payments.upload_own',
            'announcements.view',
            'calendar.view',
            'notifications.view_own',
            'ai.use',
        ],
        'guest' => [
            'auth.register',
            'auth.login',
            'announcements.view_public',
        ],
    ];

    return $permissions[$role] ?? $permissions['guest'];
}

function hasPermission($permission, $role = null) {
    $role = normalizeUserRole($role ?? ($_SESSION['role'] ?? 'guest'));
    $permissions = rolePermissions($role);
    return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
}

function hasAnyPermission($permissions, $role = null) {
    foreach ((array) $permissions as $permission) {
        if (hasPermission($permission, $role)) {
            return true;
        }
    }
    return false;
}

// Check user role - admin
function isAdmin() {
    return normalizeUserRole($_SESSION['role'] ?? '') === 'admin';
}

function isBackOfficeUser() {
    return hasPermission('admin.access');
}

// Check user role - regular user
function isUser() {
    return normalizeUserRole($_SESSION['role'] ?? '') === 'user';
}

// Require login - redirect if not logged in
// Access Control - Requires an authenticated session before continuing.
function requireLogin() {
    if (!isLoggedIn()) {
        redirect('../auth/login.php');
    }
}

// Require admin access
// Access Control - Restricts the current page or action to administrator users.
function requireAdmin() {
    if (!isLoggedIn() || !hasPermission('admin.access')) {
        redirect('../auth/login.php');
    }
}

function requirePermission($permission, $redirect = null) {
    if (!isLoggedIn()) {
        redirect('../auth/login.php');
    }

    if (!hasPermission($permission)) {
        http_response_code(403);
        redirect($redirect ?: getUserDashboardURL());
    }
}

function getUserDashboardURL() {
    return hasPermission('admin.access') ? '../admin/dashboard.php' : '../users/dashboard.php';
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
