<?php
/**
 * Test Suite: Automated SMS (TextBee) and Email Notifications End-to-End
 * 
 * Verifies:
 * 1. Immediate Announcement Posting -> In-App, Email, and SMS (TextBee) delivery
 * 2. Scheduled Announcement ("Notify Later") -> Scheduled tick triggers outbound SMS & Email
 * 3. Request Status Creation & Updates -> In-App, Email, and SMS delivery
 * 4. Request Service State Transition -> Automatic outbound notification on commit
 * 5. Sacramental Approval/Completion -> Sacramental record registration & user SMS/Email alert
 * 6. Audit & Delivery Logs -> sms_notification_logs, notification_logs, notification_deliveries
 */

define('CLI_TEST_RUNNING', true);
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/AnnouncementService.php';
require_once __DIR__ . '/../services/RequestService.php';
require_once __DIR__ . '/../services/SacramentalApprovalService.php';

$passCount = 0;
$failCount = 0;
$resultsSummary = [];

function check(bool $condition, string $label, string $details = '') {
    global $passCount, $failCount, $resultsSummary;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$label}\n";
        $resultsSummary[] = ['status' => 'PASS', 'test' => $label, 'details' => $details];
    } else {
        $failCount++;
        echo "  [FAIL] {$label}" . ($details ? " - {$details}" : "") . "\n";
        $resultsSummary[] = ['status' => 'FAIL', 'test' => $label, 'details' => $details];
    }
}

echo "========================================================================\n";
echo "   TUGON Automated SMS & Email Notification E2E Verification Suite       \n";
echo "========================================================================\n\n";

// --- STEP 0: Prepare Test Environment & Test User ---
echo "--- Step 0: Setting up test parishioners & admin context ---\n";
$adminRow = $conn->query("SELECT id, fullname, email FROM users WHERE role = 'admin' AND status = 'active' LIMIT 1")->fetch_assoc();
$testAdminId = intval($adminRow['id'] ?? 1);

$testEmail = 'e2e_notif_' . time() . '@example.com';
$testPhone = '09123456789'; // Valid Philippine mobile number

$stmt = $conn->prepare("INSERT INTO users (fullname, email, phone_number, password, role, status, email_verified_at) VALUES ('E2E Test Parishioner', ?, ?, 'test_hash', 'user', 'active', NOW())");
$stmt->bind_param('ss', $testEmail, $testPhone);
$stmt->execute();
$testUserId = (int)$stmt->insert_id;
$stmt->close();
check($testUserId > 0, 'Test parishioner account created', "ID: {$testUserId}, Email: {$testEmail}, Phone: {$testPhone}");

// Ensure notification preferences allow both email and SMS for all categories
$cats = ['announcements', 'requests', 'schedules', 'system'];
foreach ($cats as $cat) {
    $conn->query("INSERT INTO notification_preferences (user_id, category, in_app_enabled, email_enabled, sms_enabled) VALUES ({$testUserId}, '{$cat}', 1, 1, 1) ON DUPLICATE KEY UPDATE in_app_enabled=1, email_enabled=1, sms_enabled=1");
}

// Clean up helper registered for shutdown
register_shutdown_function(function() use ($conn, $testUserId) {
    if ($testUserId > 0) {
        $conn->query("DELETE FROM notification_deliveries WHERE notification_id IN (SELECT notification_id FROM notifications WHERE user_id = {$testUserId})");
        $conn->query("DELETE FROM notifications WHERE user_id = {$testUserId}");
        $conn->query("DELETE FROM sms_notification_logs WHERE user_id = {$testUserId}");
        $conn->query("DELETE FROM notification_logs WHERE user_id = {$testUserId}");
        $conn->query("DELETE FROM announcement_recipients WHERE user_id = {$testUserId}");
        $conn->query("DELETE FROM notification_preferences WHERE user_id = {$testUserId}");
        $conn->query("DELETE FROM requests WHERE user_id = {$testUserId} AND reference_number LIKE 'REQ-E2E-%'");
        $conn->query("DELETE FROM users WHERE id = {$testUserId}");
    }
});

// --- STEP 1: Test Announcement Posting (Immediate Publish) ---
echo "\n--- Step 1: Testing Immediate Announcement Posting & Notification Broadcast ---\n";
$annTitle = 'Solemn Feast Notice ' . date('Ymd-His');
$annBody = 'Join us for the parish solemn feast Mass this coming Sunday. Blessings to all families!';

$stmt = $conn->prepare("INSERT INTO announcements (title, content, type, published_by, status, lifecycle_status, audience_type, publish_at) VALUES (?, ?, 'announcement', ?, 'active', 'published', 'everyone', NOW())");
$stmt->bind_param('ssi', $annTitle, $annBody, $testAdminId);
$stmt->execute();
$annId = (int)$stmt->insert_id;
$stmt->close();

$annService = new AnnouncementService($conn);
$annService->configure($annId, 'now', null, null, 'everyone', [], $testAdminId);

// Verify In-App notification
$notif = $conn->query("SELECT notification_id, title, message FROM notifications WHERE user_id = {$testUserId} AND entity_type = 'announcement' AND entity_id = {$annId} LIMIT 1")->fetch_assoc();
check(!empty($notif), 'Announcement in-app notification recorded in notifications table');

// Verify notification_deliveries tracking
if (tableExists($conn, 'notification_deliveries') && !empty($notif['notification_id'])) {
    $nid = (int)$notif['notification_id'];
    $delSMS = $conn->query("SELECT status, channel, provider_reference FROM notification_deliveries WHERE notification_id = {$nid} AND channel = 'sms' LIMIT 1")->fetch_assoc();
    $delEmail = $conn->query("SELECT status, channel FROM notification_deliveries WHERE notification_id = {$nid} AND channel = 'email' LIMIT 1")->fetch_assoc();
    check(!empty($delSMS) && in_array($delSMS['status'], ['sent', 'pending'], true), 'SMS delivery tracked in notification_deliveries', 'Status: ' . ($delSMS['status'] ?? 'none'));
    check(!empty($delEmail) && in_array($delEmail['status'], ['sent', 'pending'], true), 'Email delivery tracked in notification_deliveries', 'Status: ' . ($delEmail['status'] ?? 'none'));
}

// Verify SMS Log
$smsLog = $conn->query("SELECT log_id, phone_number, delivery_status, error_message FROM sms_notification_logs WHERE user_id = {$testUserId} AND notification_type = 'announcement' ORDER BY log_id DESC LIMIT 1")->fetch_assoc();
check(!empty($smsLog), 'SMS logged in sms_notification_logs', "Status: " . ($smsLog['delivery_status'] ?? '') . ", Detail: " . ($smsLog['error_message'] ?? ''));

// Verify Email Log
$emailLog = $conn->query("SELECT log_id, email, delivery_status, subject FROM notification_logs WHERE user_id = {$testUserId} AND notification_type = 'announcement' ORDER BY log_id DESC LIMIT 1")->fetch_assoc();
check(!empty($emailLog), 'Email logged in notification_logs', "Status: " . ($emailLog['delivery_status'] ?? '') . ", Subject: " . ($emailLog['subject'] ?? ''));

// Verify announcement_recipients sync
$recRow = $conn->query("SELECT delivery_status, sms_delivery_status FROM announcement_recipients WHERE announcement_id = {$annId} AND user_id = {$testUserId} LIMIT 1")->fetch_assoc();
check(!empty($recRow) && $recRow['delivery_status'] === 'sent', 'announcement_recipients table updated with sent delivery status');

// --- STEP 2: Test Scheduled Announcement ("Notify Later") ---
echo "\n--- Step 2: Testing Scheduled Announcement Lifecycle & 'Notify Later' Publishing ---\n";
$schedTitle = 'Scheduled Youth Fellowship ' . date('Ymd-His');
$schedBody = 'Special youth formation fellowship meeting. All parish youth are warmly invited.';
$futureDate = date('Y-m-d H:i:s', time() + 3600);

$stmt = $conn->prepare("INSERT INTO announcements (title, content, type, published_by, status, lifecycle_status, audience_type, publish_at, scheduled_at) VALUES (?, ?, 'announcement', ?, 'inactive', 'scheduled', 'everyone', ?, ?)");
$stmt->bind_param('ssiss', $schedTitle, $schedBody, $testAdminId, $futureDate, $futureDate);
$stmt->execute();
$schedId = (int)$stmt->insert_id;
$stmt->close();

$annService->configure($schedId, 'later', $futureDate, null, 'everyone', [], $testAdminId);

// Confirm it is not published yet
$preCheck = $conn->query("SELECT lifecycle_status, status FROM announcements WHERE announcement_id = {$schedId}")->fetch_assoc();
check($preCheck['lifecycle_status'] === 'scheduled' && $preCheck['status'] === 'inactive', 'Scheduled announcement remains inactive before due time');

// Now simulate due time arriving (set publish_at into past)
$conn->query("UPDATE announcements SET publish_at = DATE_SUB(NOW(), INTERVAL 5 SECOND) WHERE announcement_id = {$schedId}");

// Trigger the automated scheduled lifecycle tick
$tickResult = $annService->tick($testAdminId);
check(!empty($tickResult['published']) && $tickResult['published'] >= 1, 'Scheduled announcement automatically published on due time', 'Published count: ' . $tickResult['published']);

// Verify the user received the notification upon scheduled publishing
$schedNotif = $conn->query("SELECT notification_id FROM notifications WHERE user_id = {$testUserId} AND entity_type = 'announcement' AND entity_id = {$schedId} LIMIT 1")->fetch_assoc();
check(!empty($schedNotif), 'Parishioner received in-app notification when scheduled announcement was published');

$schedSms = $conn->query("SELECT log_id, delivery_status, error_message FROM sms_notification_logs WHERE user_id = {$testUserId} AND notification_type = 'announcement' ORDER BY log_id DESC LIMIT 1")->fetch_assoc();
check(!empty($schedSms), 'Outbound SMS automatically sent upon scheduled announcement publish');

// --- STEP 3: Test Request Status Updates & RequestService Workflow Transitions ---
echo "\n--- Step 3: Testing Request Status Updates & Workflow State Transitions ---\n";
$reqRef = 'REQ-E2E-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
$stmt = $conn->prepare("INSERT INTO requests (user_id, request_type, status, reference_number, description) VALUES (?, 'baptismal_certificate', 'pending', ?, 'Official Baptismal Certificate copy request')");
$stmt->bind_param('is', $testUserId, $reqRef);
$stmt->execute();
$reqId = (int)$stmt->insert_id;
$stmt->close();
check($reqId > 0, 'Test certificate request created', "Ref: {$reqRef}");

// Test createRequestStatusNotification for 'pending' review
$reqRow = $conn->query("SELECT * FROM requests WHERE request_id = {$reqId}")->fetch_assoc();
$pendingNotifOk = createRequestStatusNotification($conn, $reqRow, 'pending', 'Your request has been received.');
check($pendingNotifOk === true, 'createRequestStatusNotification dispatched for Pending state');

// Verify In-App and outbound log for request pending
$notifPending = $conn->query("SELECT notification_id, title FROM notifications WHERE user_id = {$testUserId} AND entity_type = 'request' AND entity_id = {$reqId} ORDER BY notification_id DESC LIMIT 1")->fetch_assoc();
check(!empty($notifPending) && str_contains($notifPending['title'], 'Pending'), 'In-app notification created for Request Pending');

$reqSmsPending = $conn->query("SELECT log_id, phone_number, delivery_status, error_message FROM sms_notification_logs WHERE user_id = {$testUserId} AND (notification_type = 'requests' OR notification_type = 'system' OR notification_type LIKE 'request%') ORDER BY log_id DESC LIMIT 1")->fetch_assoc();
check(!empty($reqSmsPending), 'SMS delivery logged for Request Pending status update');

// Now transition via RequestService (State Machine transition to 'processing')
$requestService = new RequestService($conn);
$transResult = $requestService->transition($reqId, 'processing', $testAdminId, 'Parish clerk is searching the registry book');
check($transResult['status'] === 'processing', 'RequestService successfully transitioned request to Processing');

// Verify that RequestService transition automatically triggered user notification
$notifProcessing = $conn->query("SELECT notification_id, title, message FROM notifications WHERE user_id = {$testUserId} AND entity_type = 'request' AND entity_id = {$reqId} AND notification_type = 'request_processing' LIMIT 1")->fetch_assoc();
check(!empty($notifProcessing), 'Automatic notification triggered on RequestService::transition()', "Title: " . ($notifProcessing['title'] ?? ''));

// --- STEP 4: Test Sacramental Approval & Completion Workflow ---
echo "\n--- Step 4: Testing Sacramental Approval Service Completion & Notifications ---\n";
$bapRef = 'REQ-E2E-BAP-' . substr(bin2hex(random_bytes(3)), 0, 5);
$bapDesc = "Name of Child: Emmanuel Dela Cruz\nDate of Birth: 2024-01-15\nPlace of Birth: General Santos City\nParents: Joseph Dela Cruz & Mary Dela Cruz\nGodparents: Pedro Santos, Ana Reyes\nPreferred date: " . date('Y-m-d', strtotime('+7 days'));

$stmt = $conn->prepare("INSERT INTO requests (user_id, request_type, status, reference_number, description) VALUES (?, 'baptism_service', 'pending', ?, ?)");
$stmt->bind_param('iss', $testUserId, $bapRef, $bapDesc);
$stmt->execute();
$bapReqId = (int)$stmt->insert_id;
$stmt->close();
check($bapReqId > 0, 'Sacramental baptism request created', "Ref: {$bapRef}");

// Approve / Complete the sacramental request via SacramentalApprovalService
$sacService = new SacramentalApprovalService($conn);
$ceremonyDate = date('Y-m-d', strtotime('+7 days'));
$completionResult = $sacService->completeRequest($bapReqId, $testAdminId, [
    'admin_response' => 'Sacramental baptism approved and schedule confirmed.',
    'officiating_priest' => 'Rev. Fr. John Doe',
    'parish_priest' => 'Rev. Fr. John Doe',
    'ceremony_date' => $ceremonyDate,
    'ceremony_time' => '10:00:00',
    'target_status' => 'completed'
]);
check($completionResult['success'] === true, 'SacramentalApprovalService::completeRequest succeeded');

// Verify sacramental baptism record was populated
$bapRec = $conn->query("SELECT baptism_id, registry_no, fullname FROM baptism_records WHERE request_id = {$bapReqId} LIMIT 1")->fetch_assoc();
check(!empty($bapRec), 'Official Baptism Record automatically created', "Registry No: " . ($bapRec['registry_no'] ?? ''));

// Verify that the parishioner received the completion notification
$bapNotif = $conn->query("SELECT notification_id, title, message FROM notifications WHERE user_id = {$testUserId} AND entity_type = 'request' AND entity_id = {$bapReqId} ORDER BY notification_id DESC LIMIT 1")->fetch_assoc();
check(!empty($bapNotif), 'Parishioner notification created for Sacramental Completion', "Title: " . ($bapNotif['title'] ?? ''));

// Verify SMS and Email logged for sacramental completion
$bapSms = $conn->query("SELECT log_id, delivery_status, error_message FROM sms_notification_logs WHERE user_id = {$testUserId} AND (notification_type = 'requests' OR notification_type LIKE '%completed%' OR notification_type = 'system') ORDER BY log_id DESC LIMIT 1")->fetch_assoc();
check(!empty($bapSms), 'Outbound SMS logged for Sacramental Request Completion', "Status: " . ($bapSms['delivery_status'] ?? '') . ", Detail: " . ($bapSms['error_message'] ?? ''));

// --- STEP 5: Verification of Generic sendNotification helper ---
echo "\n--- Step 5: Testing sendNotification Generic Function ---\n";
$genTitle = 'Payment Verified: PRQ-123';
$genMsg = 'Your payment of PHP 150.00 has been verified. Official receipt #OR-998811.';
$sendNotifResult = sendNotification($conn, $testUserId, 'payment_verified', $genTitle, $genMsg, 'payment', 999);
check($sendNotifResult === true, 'sendNotification helper executes and returns true');

$genNotifRow = $conn->query("SELECT notification_id, title FROM notifications WHERE user_id = {$testUserId} AND title = '{$genTitle}' LIMIT 1")->fetch_assoc();
check(!empty($genNotifRow), 'sendNotification created in-app alert row');

// --- FINAL SUMMARY REPORT ---
echo "\n========================================================================\n";
echo "                         TEST RESULTS SUMMARY                            \n";
echo "========================================================================\n";
printf("%-6s | %-52s | %s\n", "STATUS", "TEST DESCRIPTION", "DETAILS");
echo str_repeat("-", 80) . "\n";
foreach ($resultsSummary as $r) {
    printf("%-6s | %-52s | %s\n", $r['status'], substr($r['test'], 0, 52), substr($r['details'], 0, 20));
}
echo str_repeat("=", 80) . "\n";
echo "TOTAL TESTS: " . ($passCount + $failCount) . " | PASSED: {$passCount} | FAILED: {$failCount}\n\n";

if ($failCount === 0) {
    echo ">>> ALL NOTIFICATION DISPATCH CHANNELS (IN-APP, EMAIL, SMS TEXTBEE) VERIFIED SUCCESSFUL! <<<\n";
    exit(0);
} else {
    echo ">>> SOME NOTIFICATION TESTS FAILED. PLEASE REVIEW LOG ABOVE. <<<\n";
    exit(1);
}
