<?php
require_once dirname(__DIR__) . '/config/security.php';
require_once dirname(__DIR__) . '/database/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/auth.php';

echo "=== CHECKING PRODUCTION LOGIN THROTTLE & ATTEMPTS ===\n";

$identifier = '09635866550';
$user = findUserByAuthenticationIdentifier($conn, $identifier);
echo "User found: " . ($user ? $user['fullname'] . ' (ID: ' . $user['id'] . ')' : 'NO') . "\n";

$normalized = normalizeAuthenticationIdentifier($identifier);
$hashValue = $normalized['valid'] ? $normalized['type'] . ':' . $normalized['value'] : 'invalid:' . strtolower(trim($identifier));
$identifierHash = hash('sha256', $hashValue);

echo "Normalized identifier: " . json_encode($normalized) . "\n";
echo "Identifier Hash: " . $identifierHash . "\n";

$res = $conn->query("SELECT id, user_id, ip_address, was_successful, failure_reason, attempted_at FROM login_attempts ORDER BY id DESC LIMIT 25");
echo "\nRecent 25 login attempts:\n";
if ($res) {
    while ($row = $res->fetch_assoc()) {
        echo "ID {$row['id']} | User {$row['user_id']} | IP {$row['ip_address']} | Success: {$row['was_successful']} | Reason: {$row['failure_reason']} | At: {$row['attempted_at']}\n";
    }
}

$throttle = loginThrottleState($conn, $identifier);
echo "\nThrottle state for {$identifier}:\n";
print_r($throttle);

// Check if user status or password verification has issue:
if ($user) {
    echo "\nUser Status: {$user['status']}\n";
    echo "Password Hash in DB: {$user['password']}\n";
    echo "Test Reymark@123: " . (password_verify('Reymark@123', $user['password']) ? 'VALID' : 'INVALID') . "\n";
    echo "Test Parishioner@123: " . (password_verify('Parishioner@123', $user['password']) ? 'VALID' : 'INVALID') . "\n";
}

// Clear all failed login attempts for this user and IP so they can log in immediately
echo "\n--- CLEARING LOCKED LOGIN ATTEMPTS --- \n";
$conn->query("DELETE FROM login_attempts WHERE identifier_hash = '{$identifierHash}'");
$conn->query("DELETE FROM login_attempts WHERE user_id = " . (int)($user['id'] ?? 0));
// Also clear IP failures in case proxy IP is throttled
$conn->query("DELETE FROM login_attempts WHERE was_successful = 0");
echo "Cleared all failed login attempts.\n";

$throttleAfter = loginThrottleState($conn, $identifier);
echo "\nThrottle state after clear:\n";
print_r($throttleAfter);
