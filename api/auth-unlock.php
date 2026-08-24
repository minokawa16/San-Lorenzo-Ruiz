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

// 1. Clear failed login attempts table
$conn->query("DELETE FROM login_attempts WHERE was_successful = 0");

// 2. Ensure roles table has administrator and parishioner
$conn->query("INSERT IGNORE INTO roles (role_key, role_name, description) VALUES ('administrator', 'Administrator', 'Full system access'), ('parishioner', 'Parishioner', 'Standard parishioner account')");

$adminRoleId = 0;
$parishionerRoleId = 0;
$rRes = $conn->query("SELECT role_id, role_key FROM roles");
while ($r = $rRes->fetch_assoc()) {
    if ($r['role_key'] === 'administrator') $adminRoleId = (int)$r['role_id'];
    if ($r['role_key'] === 'parishioner') $parishionerRoleId = (int)$r['role_id'];
}

// 3. Find or Create `tugonparish@gmail.com` as the ONLY Administrator
$targetAdminEmail = 'tugonparish@gmail.com';
$adminPwHash = password_hash('Parishioner@123', PASSWORD_DEFAULT);

$findAdmin = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$findAdmin->bind_param('s', $targetAdminEmail);
$findAdmin->execute();
$existingAdmin = $findAdmin->get_result()->fetch_assoc();
$findAdmin->close();

$soleAdminId = 0;
if ($existingAdmin) {
    $soleAdminId = (int)$existingAdmin['id'];
    $uStmt = $conn->prepare("UPDATE users SET fullname = 'TUGON Parish Admin', password = ?, role = 'admin', status = 'active' WHERE id = ?");
    $uStmt->bind_param('si', $adminPwHash, $soleAdminId);
    $uStmt->execute();
    $uStmt->close();
} else {
    $iStmt = $conn->prepare("INSERT INTO users (fullname, email, password, role, status) VALUES ('TUGON Parish Admin', ?, ?, 'admin', 'active')");
    $iStmt->bind_param('ss', $targetAdminEmail, $adminPwHash);
    $iStmt->execute();
    $soleAdminId = (int)$conn->insert_id;
    $iStmt->close();
}

// Grant administrator role in user_roles for tugonparish@gmail.com
if ($soleAdminId > 0 && $adminRoleId > 0) {
    $conn->query("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES ({$soleAdminId}, {$adminRoleId})");
}
if ($soleAdminId > 0 && function_exists('synchronizeAuthenticationIdentifier')) {
    synchronizeAuthenticationIdentifier($conn, $soleAdminId, 'email', $targetAdminEmail);
}

// 4. DEMOTE / REMOVE any other user from Administrator role
// Target: princeondoy0@gmail.com / 09631237247
$demoteStmt = $conn->prepare("SELECT id, fullname, email, phone_number FROM users WHERE email = 'princeondoy0@gmail.com' OR phone_number = '09631237247'");
$demoteStmt->execute();
$demoteRes = $demoteStmt->get_result();
$demotedUsers = [];
while ($dUser = $demoteRes->fetch_assoc()) {
    $dId = (int)$dUser['id'];
    if ($dId !== $soleAdminId) {
        // Remove administrator role from user_roles
        if ($adminRoleId > 0) {
            $conn->query("DELETE FROM user_roles WHERE user_id = {$dId} AND role_id = {$adminRoleId}");
        }
        // Ensure parishioner role in user_roles
        if ($parishionerRoleId > 0) {
            $conn->query("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES ({$dId}, {$parishionerRoleId})");
        }
        // Change legacy role column to parishioner
        $conn->query("UPDATE users SET role = 'parishioner' WHERE id = {$dId}");
        $demotedUsers[] = $dUser['email'] . ' / ' . $dUser['phone_number'];
    }
}
$demoteStmt->close();

// Also remove admin role from ANY other user except tugonparish@gmail.com
if ($adminRoleId > 0 && $soleAdminId > 0) {
    $conn->query("DELETE FROM user_roles WHERE role_id = {$adminRoleId} AND user_id != {$soleAdminId}");
    $conn->query("UPDATE users SET role = 'parishioner' WHERE id != {$soleAdminId} AND (role = 'admin' OR role = 'administrator')");
}

// 5. Ensure Parishioner 09635866550 is active and has correct password
$parishionerPhone = '09635866550';
$parishionerPwHash = password_hash('Reymark@123', PASSWORD_DEFAULT);
$stmtP = $conn->prepare("UPDATE users SET password = ?, status = 'active' WHERE phone_number = ? OR email = ?");
$stmtP->bind_param('sss', $parishionerPwHash, $parishionerPhone, $parishionerPhone);
$stmtP->execute();
$stmtP->close();

// 6. Synchronize authentication identifiers for all users
$identRes = $conn->query("SELECT id, phone_number, email FROM users WHERE status = 'active'");
if ($identRes) {
    while ($row = $identRes->fetch_assoc()) {
        if (!empty($row['phone_number']) && function_exists('synchronizeAuthenticationIdentifier')) {
            synchronizeAuthenticationIdentifier($conn, (int)$row['id'], 'mobile', $row['phone_number']);
        }
        if (!empty($row['email']) && function_exists('synchronizeAuthenticationIdentifier')) {
            synchronizeAuthenticationIdentifier($conn, (int)$row['id'], 'email', $row['email']);
        }
    }
}

// 7. Fetch current all users and roles for verification
$allUsers = [];
$uQuery = $conn->query("
    SELECT u.id, u.fullname, u.email, u.phone_number, u.role AS legacy_role, u.status,
           GROUP_CONCAT(r.role_key SEPARATOR ', ') AS assigned_roles
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.role_id = ur.role_id
    GROUP BY u.id
    ORDER BY u.id ASC
");
if ($uQuery) {
    while ($urow = $uQuery->fetch_assoc()) {
        $allUsers[] = $urow;
    }
}

// Test admin login directly
$testAdminAuth = beginPasswordAuthentication($conn, 'tugonparish@gmail.com', 'Parishioner@123');

echo json_encode([
    'success' => true,
    'sole_admin' => 'tugonparish@gmail.com',
    'demoted_from_admin' => $demotedUsers,
    'test_admin_auth' => [
        'ok' => !empty($testAdminAuth['ok']),
        'user_name' => $testAdminAuth['user']['fullname'] ?? null,
        'roles' => $testAdminAuth['ok'] ? authenticationRolesForUser($conn, (int)$testAdminAuth['user']['id']) : []
    ],
    'users_in_database' => $allUsers
], JSON_PRETTY_PRINT);
