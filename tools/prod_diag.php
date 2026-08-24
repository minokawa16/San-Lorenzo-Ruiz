<?php
if (php_sapi_name() !== 'cli' && ($_GET['token'] ?? '') !== 'tugon_secret_diag_2026') {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/authentication.php';
require_once __DIR__ . '/../includes/account-management.php';
require_once __DIR__ . '/../includes/otp-transactions.php';
require_once __DIR__ . '/../config/textbee.php';

echo "=== PRODUCTION TEXTBEE CONFIG ===\n";
echo "TEXTBEE_API_KEY: " . (defined('TEXTBEE_API_KEY') ? substr(TEXTBEE_API_KEY, 0, 10) . '... len=' . strlen(TEXTBEE_API_KEY) : 'UNDEFINED') . "\n";
echo "TEXTBEE_DEVICE_ID: " . (defined('TEXTBEE_DEVICE_ID') ? TEXTBEE_DEVICE_ID : 'UNDEFINED') . "\n";
echo "TEXTBEE_BASE_URL: " . (defined('TEXTBEE_BASE_URL') ? TEXTBEE_BASE_URL : 'UNDEFINED') . "\n\n";

// Action: sync / activate
if (isset($_GET['action']) && $_GET['action'] === 'activate_all') {
    $conn->query("UPDATE users SET status = 'active' WHERE status != 'active'");
    echo "All users updated to 'active' status.\n\n";
}

if (isset($_GET['action']) && $_GET['action'] === 'add_user') {
    $phone = $_GET['phone'] ?? '09631237247';
    $name = $_GET['name'] ?? 'Prince Ondoy';
    $email = $_GET['email'] ?? 'princeondoy0@gmail.com';
    
    $check = $conn->prepare("SELECT id FROM users WHERE phone_number = ? OR email = ?");
    $check->bind_param('ss', $phone, $email);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();
    
    if (!$existing) {
        $stmt = $conn->prepare("INSERT INTO users (fullname, email, phone_number, password, role, status, is_verified, created_at) VALUES (?, ?, ?, ?, 'parishioner', 'active', 1, NOW())");
        $pw = password_hash('Parishioner@123', PASSWORD_DEFAULT);
        $stmt->bind_param('ssss', $name, $email, $phone, $pw);
        $stmt->execute();
        $newId = $conn->insert_id;
        $stmt->close();
        echo "Created User #{$newId} ({$name}, {$phone}, {$email})\n";
        synchronizeAuthenticationIdentifier($conn, $newId, 'mobile', $phone);
        synchronizeAuthenticationIdentifier($conn, $newId, 'email', $email);
    } else {
        echo "User already exists with ID #{$existing['id']}\n";
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'test_sms') {
    $targetPhone = $_GET['phone'] ?? '09635866550';
    echo "Sending direct SMS to {$targetPhone} from Railway...\n";
    $smsResult = sendTugonSms($conn, $targetPhone, "TUGON Railway Test: TextBee is connected! Time: " . date('H:i:s'), 1, 'test');
    echo "Result: " . json_encode($smsResult) . "\n\n";
}

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
$res = $conn->query("SELECT * FROM sms_notification_logs ORDER BY log_id DESC LIMIT 5");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        echo "#" . $r['log_id'] . " | Phone: " . $r['phone_number'] . " | Status: " . $r['delivery_status'] . " | Err: " . $r['error_message'] . " | Time: " . $r['created_at'] . "\n";
    }
}
