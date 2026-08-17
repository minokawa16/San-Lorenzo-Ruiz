<?php
/**
 * Email OTP JSON API - Sends, resends, and verifies email OTP codes for localhost-friendly testing.
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include '../database/config.php';
include '../includes/helpers.php';

ensureEmailNotificationSchema($conn);

function otpJson($ok, $message, $status = 200, $extra = []) {
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => (bool) $ok,
        'message' => $message
    ], $extra));
    exit;
}

function otpInput() {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}

function findOtpUserByEmail($conn, $email) {
    $stmt = $conn->prepare("SELECT id, fullname, email, email_verified_at, status FROM users WHERE email = ? AND role = 'user' LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

$data = otpInput();
$action = strtolower(trim((string) ($data['action'] ?? ($_GET['action'] ?? 'send'))));
$email = strtolower(trim((string) ($data['email'] ?? '')));
$purpose = trim((string) ($data['purpose'] ?? 'registration'));
$allowed_purposes = ['registration', 'login', 'password_reset'];

if (!in_array($action, ['send', 'resend', 'verify'], true)) {
    otpJson(false, 'Unsupported OTP action.', 400);
}
if (!in_array($purpose, $allowed_purposes, true)) {
    otpJson(false, 'Unsupported OTP purpose.', 400);
}
if (!isValidEmail($email)) {
    otpJson(false, 'Please enter a valid email address.', 422);
}

$user = findOtpUserByEmail($conn, $email);
if (!$user) {
    otpJson(false, 'No user account was found for this email address.', 404);
}

$user_id = intval($user['id']);
if ($purpose === 'registration' && !empty($user['email_verified_at'])) {
    otpJson(false, 'This email address is already verified.', 409);
}

if ($action === 'verify') {
    $otp = preg_replace('/\D/', '', (string) ($data['otp'] ?? ''));
    if (strlen($otp) !== 6) {
        otpJson(false, 'Please enter the 6-digit OTP.', 422);
    }

    $verified = verifyOtpCode($conn, $user_id, $email, $purpose, $otp);
    if (!$verified['ok']) {
        $message = $verified['error'] ?: 'Unable to verify OTP.';
        $status = str_contains(strtolower($message), 'expired') ? 410 : 422;
        otpJson(false, $message, $status);
    }

    if ($purpose === 'registration') {
        $stmt = $conn->prepare("UPDATE users SET email_verified_at = NOW() WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->close();
        }
        createAuditLog($conn, $user_id, 'VERIFY_REGISTRATION_EMAIL_OTP_API', 'users', $user_id);
    }

    otpJson(true, 'OTP verified successfully.');
}

$sent = sendOtpEmail($conn, $user_id, $email, $purpose);
if (!$sent['ok']) {
    $message = $sent['error'] ?: 'Unable to send OTP.';
    $status = str_contains(strtolower($message), 'wait') ? 429 : 500;
    otpJson(false, $message, $status);
}

createAuditLog($conn, $user_id, strtoupper($action) . '_EMAIL_OTP_API', 'users', $user_id, null, [
    'purpose' => $purpose,
    'email' => $email
]);

otpJson(true, $action === 'resend' ? 'A new OTP was sent to your email address.' : 'OTP sent to your email address.', 200, [
    'expires_at' => $sent['expires_at'] ?? null
]);
