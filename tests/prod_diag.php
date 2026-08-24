<?php
// CLI diagnostic script
if (php_sapi_name() !== 'cli' && ($_GET['token'] ?? '') !== 'tugon_secret_diag_2026') {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/authentication.php';
require_once __DIR__ . '/../includes/otp-transactions.php';
require_once __DIR__ . '/../config/textbee.php';

echo "=== PRODUCTION TEXTBEE CONFIG ===\n";
echo "TEXTBEE_API_KEY: " . (defined('TEXTBEE_API_KEY') ? substr(TEXTBEE_API_KEY, 0, 10) . '... len=' . strlen(TEXTBEE_API_KEY) : 'UNDEFINED') . "\n";
echo "TEXTBEE_DEVICE_ID: " . (defined('TEXTBEE_DEVICE_ID') ? TEXTBEE_DEVICE_ID : 'UNDEFINED') . "\n";
echo "TEXTBEE_BASE_URL: " . (defined('TEXTBEE_BASE_URL') ? TEXTBEE_BASE_URL : 'UNDEFINED') . "\n\n";

echo "=== PRODUCTION USERS ===\n";
$res = $conn->query("SELECT id, fullname, phone_number, email, status FROM users ORDER BY id");
while ($r = $res->fetch_assoc()) {
    echo "User #" . $r['id'] . ": " . $r['fullname'] . " | Phone: [" . $r['phone_number'] . "] | Email: [" . $r['email'] . "] | Status: " . $r['status'] . "\n";
}

echo "\n=== PRODUCTION USER_AUTH_IDENTIFIERS ===\n";
$res = $conn->query("SELECT user_id, identifier_type, normalized_value, verified_at FROM user_auth_identifiers ORDER BY user_id");
while ($r = $res->fetch_assoc()) {
    echo "User #" . $r['user_id'] . " | " . $r['identifier_type'] . ": [" . $r['normalized_value'] . "] | Verified: " . ($r['verified_at'] ?: "NO") . "\n";
}

echo "\n=== PRODUCTION RECENT SMS LOGS ===\n";
$res = $conn->query("SELECT * FROM sms_notification_logs ORDER BY log_id DESC LIMIT 10");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        echo "#" . $r['log_id'] . " | Phone: " . $r['phone_number'] . " | Status: " . $r['delivery_status'] . " | Err: " . $r['error_message'] . " | Time: " . $r['created_at'] . "\n";
    }
}

echo "\n=== PRODUCTION RECENT OTP TRANSACTIONS ===\n";
$res = $conn->query("SELECT id, user_id, purpose, delivery_method, destination, expires_at, verified_at, invalidated_at, created_at FROM otp_transactions ORDER BY id DESC LIMIT 10");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        echo "Tx #" . $r['id'] . " | User: " . $r['user_id'] . " | Method: " . $r['delivery_method'] . " | Dest: " . $r['destination'] . " | Created: " . $r['created_at'] . " | Exp: " . $r['expires_at'] . " | Ver: " . ($r['verified_at'] ?: 'NO') . "\n";
    }
}
