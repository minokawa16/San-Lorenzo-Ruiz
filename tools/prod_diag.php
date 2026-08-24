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

$action = $_GET['action'] ?? ($argv[1] ?? '');
$paramPhone = $_GET['phone'] ?? ($argv[2] ?? '');

// Action: sync / activate
if ($action === 'activate_all') {
    $conn->query("UPDATE users SET status = 'active' WHERE status != 'active'");
    echo "All users updated to 'active' status.\n\n";
}

if ($action === 'add_user') {
    $phone = $paramPhone ?: '09631237247';
    $name = $_GET['name'] ?? ($argv[3] ?? 'Prince Ondoy');
    $email = $_GET['email'] ?? ($argv[4] ?? 'princeondoy0@gmail.com');
    
    $check = $conn->prepare("SELECT id FROM users WHERE phone_number = ? OR email = ?");
    $check->bind_param('ss', $phone, $email);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();
    
    if (!$existing) {
        $u2 = $conn->query("SELECT role FROM users LIMIT 1")->fetch_assoc();
        $validRole = $u2['role'] ?? 'user';
        echo "Detected valid role: [{$validRole}]\n";
        
        $pw = password_hash('Parishioner@123', PASSWORD_DEFAULT);
        $insertSql = "INSERT INTO users (fullname, email, phone_number, password, role, status) VALUES (?, ?, ?, ?, ?, 'active')";
        $stmt = $conn->prepare($insertSql);
        if (!$stmt) {
            echo "Prepare failed: " . $conn->error . "\n";
        } else {
            $stmt->bind_param('sssss', $name, $email, $phone, $pw, $validRole);
            if (!$stmt->execute()) {
                echo "Execute failed: " . $stmt->error . "\n";
            } else {
                $newId = $conn->insert_id;
                echo "Created User #{$newId} ({$name}, {$phone}, {$email})\n";
                synchronizeAuthenticationIdentifier($conn, $newId, 'mobile', $phone);
                synchronizeAuthenticationIdentifier($conn, $newId, 'email', $email);
            }
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("UPDATE users SET status = 'active', phone_number = ? WHERE id = ?");
        $stmt->bind_param('si', $phone, $existing['id']);
        $stmt->execute();
        $stmt->close();
        synchronizeAuthenticationIdentifier($conn, $existing['id'], 'mobile', $phone);
        echo "User already exists with ID #{$existing['id']} - updated to active\n";
    }
}

if ($action === 'test_sms') {
    $targetPhone = $paramPhone ?: '09635866550';
    echo "Sending direct SMS to {$targetPhone} from Railway...\n";
    $smsResult = sendTugonSms($conn, $targetPhone, "TUGON Railway Test: TextBee is connected! Time: " . date('H:i:s'), 1, 'test');
    echo "Result: " . json_encode($smsResult) . "\n\n";
}

if ($action === 'test_forgot_pw') {
    $targetPhone = $paramPhone ?: '09635866550';
    echo "Simulating Forgot Password OTP creation for {$targetPhone} on Railway...\n";
    $user = findUserByAuthenticationIdentifier($conn, $targetPhone);
    if (!$user) {
        echo "findUserByAuthenticationIdentifier returned NULL for {$targetPhone}\n";
    } else {
        echo "Found User #{$user['id']} ({$user['fullname']}), status: {$user['status']}\n";
        $sent = createOtpTransaction($conn, (int) $user['id'], 'password_reset', 'mobile');
        echo "createOtpTransaction result: " . json_encode($sent) . "\n\n";
    }
}

if ($action === 'set_pw') {
    $targetPhone = $paramPhone ?: '09635866550';
    $newPw = $_GET['new_password'] ?? ($argv[3] ?? 'Parishioner@123');
    $hash = password_hash($newPw, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE phone_number = ? OR email = ?");
    $stmt->bind_param('sss', $hash, $targetPhone, $targetPhone);
    $stmt->execute();
    echo "Updated password for {$targetPhone} to: [{$newPw}] (affected: {$stmt->affected_rows})\n\n";
    $stmt->close();
}

if ($action === 'check_pw') {
    echo "=== CHECKING PASSWORDS FOR ALL USERS ===\n";
    $res = $conn->query("SELECT id, fullname, phone_number, email, password FROM users");
    $common = ['Reymark@123', 'Parishioner@123', 'Admin@123', 'Password@123', 'password123', 'admin123', '12345678', 'reymark123', 'password'];
    while ($u = $res->fetch_assoc()) {
        echo "User #{$u['id']}: {$u['fullname']} ({$u['phone_number']} / {$u['email']})\n";
        $found = false;
        foreach ($common as $p) {
            if (password_verify($p, $u['password'])) {
                echo "  MATCHED: [{$p}]\n";
                $found = true;
                break;
            }
        }
        if (!$found) {
            echo "  Hash: " . substr($u['password'], 0, 20) . "... (no standard dictionary match)\n";
        }
    }
if ($action === 'test_login') {
    $targetPhone = $paramPhone ?: '09635866550';
    $password = $_GET['password'] ?? ($argv[3] ?? 'Reymark@123');
    echo "=== TESTING LOGIN AUTHENTICATION FOR [{$targetPhone}] ===\n";
    $auth = beginPasswordAuthentication($conn, $targetPhone, $password);
    echo "Result:\n";
    print_r($auth);
    echo "\n";
}

echo "=== PRODUCTION USERS ===\n";
$res = $conn->query("SELECT id, fullname, phone_number, email, role, status FROM users ORDER BY id");
while ($r = $res->fetch_assoc()) {
    echo "User #" . $r['id'] . ": " . $r['fullname'] . " | Phone: [" . $r['phone_number'] . "] | Email: [" . $r['email'] . "] | Role: [" . $r['role'] . "] | Status: " . $r['status'] . "\n";
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
