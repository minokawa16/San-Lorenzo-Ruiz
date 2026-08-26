<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';

// Set up authenticated session for user ID 1 (administrator)
$admin = $conn->query("SELECT * FROM users WHERE email = 'tugonparish@gmail.com' LIMIT 1")->fetch_assoc();
$_SESSION['user_id'] = $admin['id'];
$_SESSION['role'] = $admin['role'];
$_SESSION['user_role'] = $admin['role'];
$_SESSION['admin_logged_in'] = true;
$_SESSION['logged_in'] = true;
$_SESSION['fully_authenticated'] = true;
$_SESSION['mfa_verified'] = true;
$_SESSION['permissions'] = ['admin.access', 'registrations.verify', 'users.view'];

// Let's create a valid session token in user_sessions if table exists
if ($conn->query("SHOW TABLES LIKE 'user_sessions'")->num_rows > 0) {
    $token = bin2hex(random_bytes(32));
    $hashed = hash('sha256', $token);
    $_SESSION['session_token'] = $token;
    $ip = '127.0.0.1';
    $ua = 'Test';
    $conn->query("INSERT INTO user_sessions (user_id, token_hash, ip_address, user_agent, expires_at) VALUES ({$admin['id']}, '$hashed', '$ip', '$ua', DATE_ADD(NOW(), INTERVAL 1 DAY))");
}

echo "Authenticated user check: " . (getAuthenticatedUser($conn) ? 'SUCCESS' : 'FAILED') . "\n";
echo "Has registrations.verify permission: " . (hasPermission('registrations.verify') ? 'YES' : 'NO') . "\n";

// Test User ID 5 (no face document)
$_GET['id'] = 5;
$_GET['type'] = 'face';

ob_start();
include __DIR__ . '/../admin/view-valid-id.php';
$output = ob_get_clean();

echo "User 5 Document Output Length: " . strlen($output) . " bytes\n";
echo "Is SVG placeholder: " . (strpos($output, '<svg') !== false ? 'YES' : 'NO') . "\n";
echo "SVG sample:\n" . substr($output, 0, 200) . "\n";
