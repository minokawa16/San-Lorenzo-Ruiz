<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/authentication.php';
require_once __DIR__ . '/../includes/password-security.php';

echo "=== TESTING COMPLETE PASSWORD LIFECYCLE ===\n\n";

// 1. Create or Find test user
$testEmail = 'test_auth_user@example.com';
$testPhone = '09123456789';
$initialPw = 'InitialPass@123';
$newPw = 'UpdatedPass@456';

$conn->query("DELETE FROM users WHERE email = '{$testEmail}' OR phone_number = '{$testPhone}'");

$hash = password_hash($initialPw, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO users (fullname, email, phone_number, password, role, status) VALUES ('Test Auth User', ?, ?, ?, 'parishioner', 'active')");
$stmt->bind_param('sss', $testEmail, $testPhone, $hash);
$stmt->execute();
$userId = (int)$conn->insert_id;
$stmt->close();

synchronizeAuthenticationIdentifier($conn, $userId, 'email', $testEmail);
synchronizeAuthenticationIdentifier($conn, $userId, 'mobile', $testPhone);

$parishionerRoleId = 0;
$rRes = $conn->query("SELECT role_id FROM roles WHERE role_key = 'parishioner' LIMIT 1");
if ($rRes && $rRow = $rRes->fetch_assoc()) {
    $parishionerRoleId = (int)$rRow['role_id'];
    $conn->query("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES ({$userId}, {$parishionerRoleId})");
}

echo "Created test user ID: {$userId}\n";

// 2. Test Initial Login
$auth1 = beginPasswordAuthentication($conn, $testEmail, $initialPw);
echo "1. Initial Login with initial password ({$initialPw}): " . ($auth1['ok'] ? 'SUCCESS' : 'FAILED: ' . ($auth1['error'] ?? '')) . "\n";

// 3. Test Initial Login with wrong password
$authWrong = beginPasswordAuthentication($conn, $testEmail, 'WrongPassword@999');
echo "2. Login with wrong password: " . (!$authWrong['ok'] ? 'CORRECTLY REJECTED' : 'UNEXPECTED SUCCESS') . "\n";

// 4. Update Password using updateAccountPassword()
$updateRes = updateAccountPassword($conn, $userId, $newPw);
echo "3. Password change to ({$newPw}): " . ($updateRes['ok'] ? 'SUCCESS' : 'FAILED: ' . ($updateRes['error'] ?? '')) . "\n";

// 5. Verify Database State after update
$checkUser = $conn->query("SELECT password, password_changed_at, must_change_password, LENGTH(password) as hash_len FROM users WHERE id = {$userId}")->fetch_assoc();
echo "4. DB Hash Length: {$checkUser['hash_len']} | Changed At: {$checkUser['password_changed_at']} | Must Change: {$checkUser['must_change_password']}\n";

// 6. Test Login with old password (MUST fail)
$authOld = beginPasswordAuthentication($conn, $testEmail, $initialPw);
echo "5. Login with old password ({$initialPw}): " . (!$authOld['ok'] ? 'CORRECTLY REJECTED' : 'UNEXPECTED SUCCESS') . "\n";

// 7. Test Login with NEW password (MUST succeed)
$authNewEmail = beginPasswordAuthentication($conn, $testEmail, $newPw);
echo "6. Login with new password via Email ({$testEmail}): " . ($authNewEmail['ok'] ? 'SUCCESS' : 'FAILED: ' . ($authNewEmail['error'] ?? '')) . "\n";

$authNewPhone = beginPasswordAuthentication($conn, $testPhone, $newPw);
echo "7. Login with new password via Phone ({$testPhone}): " . ($authNewPhone['ok'] ? 'SUCCESS' : 'FAILED: ' . ($authNewPhone['error'] ?? '')) . "\n";

// Clean up
$conn->query("DELETE FROM users WHERE id = {$userId}");
$conn->query("DELETE FROM user_roles WHERE user_id = {$userId}");
$conn->query("DELETE FROM user_auth_identifiers WHERE user_id = {$userId}");
$conn->query("DELETE FROM password_security_history WHERE user_id = {$userId}");
echo "\nTest user cleaned up successfully.\n";
