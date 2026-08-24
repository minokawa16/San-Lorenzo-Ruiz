<?php
require_once dirname(__DIR__) . '/database/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

function phase2Check(bool $ok, string $label): void {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
    if (!$ok) exit(1);
}

phase2Check(findUserByAuthenticationIdentifier($conn, '09123456789') === null, 'ambiguous legacy mobile is not assigned to an arbitrary account');
phase2Check(findUserByAuthenticationIdentifier($conn, '09635866550') === null, 'second ambiguous legacy mobile is not assigned to an arbitrary account');
$admins = $conn->query("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.role_id = ur.role_id WHERE r.role_key = 'administrator'");
while ($admin = $admins->fetch_assoc()) {
    phase2Check(userRequiresLoginMfa($conn, ['id' => $admin['id'], 'login_otp_enabled' => 0], authenticationRolesForUser($conn, (int) $admin['id'])) === (APP_ENVIRONMENT === 'production'), 'administrator MFA follows the environment policy');
}
phase2Check(hasPermission('admin.access', 'administrator'), 'administrator retains admin.access permission');
echo "Phase 2 authentication checks passed." . PHP_EOL;
