<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$secretToken = 'tugon_secret_unlock_2026';
$providedToken = (string) ($_GET['token'] ?? ($_POST['token'] ?? ''));

if (!hash_equals($secretToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/authentication.php';

$action = (string) ($_GET['action'] ?? ($_POST['action'] ?? 'unlock'));

// 1. Clear failed login attempts table
$deletedAttempts = 0;
if ($conn->query("DELETE FROM login_attempts WHERE was_successful = 0")) {
    $deletedAttempts = $conn->affected_rows;
}

// 2. Ensure Parishioner 09635866550 is active and has correct password
$parishionerPhone = '09635866550';
$parishionerPwHash = password_hash('Reymark@123', PASSWORD_DEFAULT);
$stmt1 = $conn->prepare("UPDATE users SET password = ?, status = 'active' WHERE phone_number = ? OR email = ?");
$stmt1->bind_param('sss', $parishionerPwHash, $parishionerPhone, $parishionerPhone);
$stmt1->execute();
$parishionerUpdated = $stmt1->affected_rows;
$stmt1->close();

// 3. Ensure Admin 09631237247 is active and has correct password
$adminPhone = '09631237247';
$adminEmail = 'princeondoy0@gmail.com';
$adminPwHash = password_hash('Parishioner@123', PASSWORD_DEFAULT);
$stmt2 = $conn->prepare("UPDATE users SET password = ?, status = 'active' WHERE phone_number = ? OR email = ? OR email = ?");
$stmt2->bind_param('ssss', $adminPwHash, $adminPhone, $adminPhone, $adminEmail);
$stmt2->execute();
$adminUpdated = $stmt2->affected_rows;
$stmt2->close();

// 4. Synchronize authentication identifiers
$identRes = $conn->query("SELECT id, phone_number, email FROM users WHERE status = 'active'");
$syncedUsers = 0;
if ($identRes) {
    while ($row = $identRes->fetch_assoc()) {
        $syncedUsers++;
        if (!empty($row['phone_number']) && function_exists('synchronizeAuthenticationIdentifier')) {
            synchronizeAuthenticationIdentifier($conn, (int)$row['id'], 'mobile', $row['phone_number']);
        }
        if (!empty($row['email']) && function_exists('synchronizeAuthenticationIdentifier')) {
            synchronizeAuthenticationIdentifier($conn, (int)$row['id'], 'email', $row['email']);
        }
    }
}

// 5. Test authentication directly for 09635866550
$testAuth = beginPasswordAuthentication($conn, $parishionerPhone, 'Reymark@123');

echo json_encode([
    'success' => true,
    'deleted_failed_attempts' => $deletedAttempts,
    'parishioner_updated' => $parishionerUpdated,
    'admin_updated' => $adminUpdated,
    'synced_users' => $syncedUsers,
    'test_auth_parishioner' => [
        'ok' => !empty($testAuth['ok']),
        'error' => $testAuth['error'] ?? null,
        'user_name' => $testAuth['user']['fullname'] ?? null,
    ]
], JSON_PRETTY_PRINT);
