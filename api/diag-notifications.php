<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$secretToken = 'tugon_secret_diag_2026';
$providedToken = (string) ($_GET['token'] ?? ($_POST['token'] ?? ''));

if (!hash_equals($secretToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/textbee.php';
require_once __DIR__ . '/../config/sms/send_sms.php';

$action = (string) ($_GET['action'] ?? ($_POST['action'] ?? 'status'));
$response = ['success' => true, 'action' => $action];

// Check active parishioners
$uStmt = $conn->query("SELECT id, fullname, email, phone_number, role, status FROM users WHERE (role IN ('user', 'parishioner', 'member') OR role IS NULL OR role = '') AND status = 'active'");
$activeUsers = [];
if ($uStmt) {
    while ($u = $uStmt->fetch_assoc()) {
        $activeUsers[] = [
            'id' => (int) $u['id'],
            'fullname' => $u['fullname'],
            'email' => $u['email'],
            'email_valid' => isValidEmail($u['email']),
            'phone' => $u['phone_number'],
            'phone_valid' => isValidPhilippineMobile($u['phone_number']),
            'role' => $u['role'],
            'status' => $u['status']
        ];
    }
}
$response['active_parishioners_count'] = count($activeUsers);
$response['active_parishioners'] = $activeUsers;

if ($action === 'test_sms') {
    $targetPhone = (string) ($_GET['phone'] ?? '09635866550');
    $msg = 'TUGON Live SMS Test from Railway at ' . date('Y-m-d H:i:s');
    $smsResult = sendTugonSms($conn, $targetPhone, $msg, 1, 'test');
    $response['sms_target'] = $targetPhone;
    $response['sms_result'] = $smsResult;
} elseif ($action === 'test_email') {
    $targetEmail = (string) ($_GET['email'] ?? 'reymarkcavanasa@gmail.com');
    $mailConfig = tugonMailConfig();
    $emailResult = sendTugonEmail(
        $conn,
        $targetEmail,
        'TUGON Live Email Test (' . date('H:i:s') . ')',
        tugonEmailTemplate('Live Test Notification', '<p>This is a live test notification from TUGON Railway server.</p>', 'Visit Portal', 'https://san-lorenzo-ruiz.vercel.app/'),
        'Live test from TUGON',
        1,
        'test'
    );
    $response['email_target'] = $targetEmail;
    $response['mail_config'] = [
        'enabled' => $mailConfig['enabled'] ?? false,
        'mailer' => $mailConfig['mailer'] ?? '',
        'host' => $mailConfig['smtp_host'] ?? '',
        'port' => $mailConfig['smtp_port'] ?? '',
        'username' => $mailConfig['smtp_username'] ?? '',
        'from' => $mailConfig['from_email'] ?? ''
    ];
    $response['email_result'] = $emailResult;
} elseif ($action === 'broadcast_test') {
    $bResult = notifyAllActiveParishioners($conn, 'TUGON Broadcast Live Test', 'This is a live automated broadcast test at ' . date('Y-m-d H:i:s'), 'announcements');
    $response['broadcast_result'] = $bResult;
} elseif ($action === 'update_parishioner_contacts') {
    $uid = intval($_GET['user_id'] ?? 2);
    $email = trim((string) ($_GET['email'] ?? 'reymarkcavanasa@gmail.com'));
    $phone = trim((string) ($_GET['phone'] ?? '09635866550'));
    
    $upStmt = $conn->prepare("UPDATE users SET email = ?, phone_number = ? WHERE id = ?");
    $upStmt->bind_param('ssi', $email, $phone, $uid);
    $upStmt->execute();
    $affected = $upStmt->affected_rows;
    $upStmt->close();
    
    $response['updated_user_id'] = $uid;
    $response['updated_email'] = $email;
    $response['updated_phone'] = $phone;
    $response['affected_rows'] = $affected;
    
    // Now trigger an immediate dual-channel test notification to this user
    $dualTest = notifyUserAutomatic($conn, $uid, 'TUGON Dual Channel Verified', 'Both SMS and Email notifications are now active on your account!', 'system');
    $response['dual_channel_test'] = $dualTest;
}

// Fetch last 5 email logs
$mLogs = [];
$mRes = $conn->query("SELECT log_id, user_id, email, subject, notification_type, delivery_status, error_message, created_at, sent_at FROM notification_logs ORDER BY log_id DESC LIMIT 5");
if ($mRes) {
    while ($ml = $mRes->fetch_assoc()) {
        $mLogs[] = $ml;
    }
}
$response['recent_email_logs'] = $mLogs;

// Fetch last 5 sms logs
$sLogs = [];
$sRes = $conn->query("SELECT log_id, user_id, phone_number, notification_type, delivery_status, error_message, created_at, sent_at FROM sms_notification_logs ORDER BY log_id DESC LIMIT 5");
if ($sRes) {
    while ($sl = $sRes->fetch_assoc()) {
        $sLogs[] = $sl;
    }
}
$response['recent_sms_logs'] = $sLogs;

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
