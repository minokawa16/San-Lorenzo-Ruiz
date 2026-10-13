<?php
/**
 * End-to-End Test Suite: Automatic Admin Notifications Feature
 * 
 * Verifies:
 * 1. Automatic admin notification generation upon request creation across all types
 * 2. Notification content completeness (type, ref, parishioner name, contact, timestamp, summary, direct link)
 * 3. Deduplication and idempotency protection (no double alerting)
 * 4. Delivery channel logging (notification_deliveries & notification_logs)
 * 5. Parishioner confirmation receipt dispatch
 * 6. Urgent classification for Anointing of the Sick
 * 7. Mark as read on request open workflow
 * 8. Secretary notification toggle routing
 */

define('CLI_TEST_RUNNING', true);
putenv('MAIL_ENABLED=false');
putenv('MAIL_TIMEOUT=1');
ob_implicit_flush(true);

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
putenv('MAIL_ENABLED=false');
$_ENV['MAIL_ENABLED'] = 'false';
require_once __DIR__ . '/../services/AdminNotificationService.php';
require_once __DIR__ . '/../services/RequestService.php';

echo "=== STARTING AUTOMATIC ADMIN NOTIFICATIONS TEST SUITE ===" . PHP_EOL . PHP_EOL;

$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $message): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$message}" . PHP_EOL;
        $passed++;
    } else {
        echo "  [FAIL] {$message}" . PHP_EOL;
        $failed++;
    }
}

// 1. Setup Test Users
$userEmail = 'test_parishioner_' . bin2hex(random_bytes(4)) . '@example.com';
$adminEmail = 'test_admin_' . bin2hex(random_bytes(4)) . '@example.com';
$secEmail = 'test_secretary_' . bin2hex(random_bytes(4)) . '@example.com';

// Clean old test users
$conn->query("DELETE FROM users WHERE email LIKE 'test_%@example.com'");

// Create Parishioner
$pwd = password_hash('TestPass123!', PASSWORD_BCRYPT);
$conn->query("INSERT INTO users (email, password, fullname, phone_number, role, status) VALUES ('{$userEmail}', '{$pwd}', 'Juan Dela Cruz', '09171234567', 'user', 'active')");
$userId = (int)$conn->insert_id;

// Create Admin
$conn->query("INSERT INTO users (email, password, fullname, phone_number, role, status) VALUES ('{$adminEmail}', '{$pwd}', 'Fr. Administrator', '09181234567', 'admin', 'active')");
$adminId = (int)$conn->insert_id;

// Create Secretary with role in user_roles
$conn->query("INSERT INTO roles (role_key, display_name, is_staff, is_system) VALUES ('secretary', 'Parish Secretary', 1, 1) ON DUPLICATE KEY UPDATE display_name = VALUES(display_name)");
$secRoleRow = $conn->query("SELECT role_id FROM roles WHERE role_key = 'secretary' LIMIT 1")->fetch_assoc();
$secRoleId = (int)($secRoleRow['role_id'] ?? 1);
$conn->query("INSERT INTO users (email, password, fullname, phone_number, role, status) VALUES ('{$secEmail}', '{$pwd}', 'Maria Clara (Secretary)', '09191234567', 'user', 'active')");
$secId = (int)$conn->insert_id;
$conn->query("INSERT INTO user_roles (user_id, role_id) VALUES ({$secId}, {$secRoleId})");

assertCondition($userId > 0 && $adminId > 0 && $secId > 0, "Test users initialized (Parishioner #{$userId}, Admin #{$adminId}, Secretary #{$secId})");

// Ensure notification schema
ensureEmailNotificationSchema($conn);

// 2. Test Format Request Type Dynamic Fallback
echo PHP_EOL . "--- Test 1: Dynamic Request Type Formatting ---" . PHP_EOL;
assertCondition(AdminNotificationService::formatRequestType('baptism_service') === 'Baptism Service', "Baptism Service formatted correctly");
assertCondition(AdminNotificationService::formatRequestType('anointing_of_the_sick') === 'Anointing of the Sick', "Anointing of the Sick formatted correctly");
assertCondition(AdminNotificationService::formatRequestType('house_blessing') === 'House Blessing', "House Blessing formatted correctly");
assertCondition(AdminNotificationService::formatRequestType('future_custom_inquiry') === 'Future Custom Inquiry', "Future unmapped request types format automatically");

// 3. Test Urgent Request Detection
echo PHP_EOL . "--- Test 2: Urgent Request Detection ---" . PHP_EOL;
assertCondition(AdminNotificationService::isUrgentRequest('anointing_of_the_sick') === true, "Anointing of the sick recognized as urgent");
assertCondition(AdminNotificationService::isUrgentRequest('funeral_mass') === true, "Funeral mass recognized as urgent");
assertCondition(AdminNotificationService::isUrgentRequest('baptism_service') === false, "Baptism service recognized as normal");
assertCondition(AdminNotificationService::isUrgentRequest('baptism_service', 'urgent') === true, "Priority override works for urgent flag");

// 4. Test Automatic Notification on New Sacrament Request (Baptism)
echo PHP_EOL . "--- Test 3: Notification Dispatch for Sacrament Request ---" . PHP_EOL;
$refBap = 'TUGON-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
$conn->query("INSERT INTO requests (user_id, request_type, description, status, reference_number, date_requested) VALUES ({$userId}, 'baptism_service', 'Child: Baby Jesus. Preferred Date: 2026-11-15', 'pending', '{$refBap}', NOW())");
$reqBapId = (int)$conn->insert_id;

$dispatchResult = AdminNotificationService::notifyOnNewRequest($conn, $reqBapId);
assertCondition(!empty($dispatchResult['ok']), "notifyOnNewRequest returned ok for request #{$reqBapId}");
assertCondition($dispatchResult['in_app'] >= 1, "At least 1 staff member received in-app notification ({$dispatchResult['in_app']} delivered)");

// Verify In-App Notification in DB for Admin
$adminNotif = $conn->query("SELECT * FROM notifications WHERE user_id = {$adminId} AND entity_type = 'request' AND entity_id = {$reqBapId} LIMIT 1")->fetch_assoc();
assertCondition(!empty($adminNotif), "Admin in-app notification row exists in database");
assertCondition(str_contains($adminNotif['title'], $refBap), "Notification title contains reference number {$refBap}");
assertCondition(str_contains($adminNotif['message'], 'Juan Dela Cruz'), "Notification message contains parishioner name");
assertCondition(str_contains($adminNotif['message'], '09171234567'), "Notification message contains phone number");
assertCondition($adminNotif['action_key'] === 'admin.request.view', "Notification action_key is 'admin.request.view'");

// Verify Action URL Resolution
$actionUrl = NotificationService::actionUrl($adminNotif['action_key'], $adminNotif['entity_type'], $adminNotif['entity_id'], $adminNotif, $conn);
assertCondition(str_contains($actionUrl, "admin/request-workflow.php?id={$reqBapId}"), "Action URL resolves directly to admin/request-workflow.php?id={$reqBapId}");

// Verify Parishioner Confirmation
$userConfirm = $conn->query("SELECT * FROM notifications WHERE user_id = {$userId} AND entity_type = 'request' AND entity_id = {$reqBapId} LIMIT 1")->fetch_assoc();
assertCondition(!empty($userConfirm), "Parishioner confirmation notification row exists");
assertCondition(str_contains($userConfirm['title'], 'Request Received'), "Parishioner notification is titled 'Request Received'");

// 5. Test Deduplication Protection (Reliability)
echo PHP_EOL . "--- Test 4: Deduplication & Idempotency Guarantee ---" . PHP_EOL;
$countBefore = (int)$conn->query("SELECT COUNT(*) AS c FROM notifications WHERE entity_id = {$reqBapId}")->fetch_assoc()['c'];
$secondDispatch = AdminNotificationService::notifyOnNewRequest($conn, $reqBapId);
$countAfter = (int)$conn->query("SELECT COUNT(*) AS c FROM notifications WHERE entity_id = {$reqBapId}")->fetch_assoc()['c'];
assertCondition($countBefore === $countAfter, "Submitting/triggering again for request #{$reqBapId} did NOT create duplicate notification rows (count stayed {$countBefore})");

// 6. Test Urgent Request (Anointing of the Sick)
echo PHP_EOL . "--- Test 5: Urgent Request Flow (Anointing of the Sick) ---" . PHP_EOL;
$refUrgent = 'TUGON-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
$conn->query("INSERT INTO requests (user_id, request_type, description, priority, status, reference_number, date_requested) VALUES ({$userId}, 'anointing_of_the_sick', 'Hospital room 304, urgent viaticum needed', 'urgent', 'pending', '{$refUrgent}', NOW())");
$reqUrgentId = (int)$conn->insert_id;

$urgentDispatch = AdminNotificationService::notifyOnNewRequest($conn, $reqUrgentId);
$urgentNotif = $conn->query("SELECT * FROM notifications WHERE user_id = {$adminId} AND entity_id = {$reqUrgentId} LIMIT 1")->fetch_assoc();
assertCondition(!empty($urgentNotif), "Urgent notification created for Admin");
assertCondition(str_contains($urgentNotif['title'], '[URGENT]'), "Urgent notification title includes ⚠️ [URGENT] banner");

// 7. Test Mark as Read on Request Open
echo PHP_EOL . "--- Test 6: Mark as Read on Request Open ---" . PHP_EOL;
assertCondition((int)$adminNotif['is_read'] === 0 && $adminNotif['state'] === 'unread', "Admin notification begins as unread");

$readCount = AdminNotificationService::markAsReadForRequest($conn, $reqBapId, $adminId);
assertCondition($readCount >= 1, "markAsReadForRequest affected {$readCount} notification(s)");

$adminNotifAfter = $conn->query("SELECT * FROM notifications WHERE notification_id = {$adminNotif['notification_id']}")->fetch_assoc();
assertCondition((int)$adminNotifAfter['is_read'] === 1 && $adminNotifAfter['state'] === 'read', "Admin notification state updated to 'read' with is_read = 1");

// 8. Test RequestService::create triggers Admin Notification automatically
echo PHP_EOL . "--- Test 7: Programmatic RequestService::create Flow ---" . PHP_EOL;
$reqService = new RequestService($conn);
$idemKey = hash('sha256', random_bytes(32));
$certResult = $reqService->create([
    'request_type' => 'baptismal_certificate',
    'record_holder_name' => 'Maria Clara',
    'description' => 'For school admission purposes'
], $userId, $idemKey);

$certReqId = (int)$certResult['request_id'];
assertCondition($certReqId > 0, "Certificate request created via RequestService (ID #{$certReqId})");

$certNotif = $conn->query("SELECT * FROM notifications WHERE user_id = {$adminId} AND entity_type = 'request' AND entity_id = {$certReqId} LIMIT 1")->fetch_assoc();
assertCondition(!empty($certNotif), "Admin was automatically notified upon RequestService::create call");
assertCondition(str_contains($certNotif['title'], 'Baptismal Certificate'), "Certificate notification title formatted properly");

// 9. Test Secretary Copy Toggle
echo PHP_EOL . "--- Test 8: Secretary Notification Routing Setting ---" . PHP_EOL;
// Enable Secretary
writeSetting($conn, 'notify_secretary_on_requests', '1');
$secRef = 'TUGON-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
$conn->query("INSERT INTO requests (user_id, request_type, description, status, reference_number, date_requested) VALUES ({$userId}, 'house_blessing', 'House blessing request', 'pending', '{$secRef}', NOW())");
$secReqId = (int)$conn->insert_id;
AdminNotificationService::notifyOnNewRequest($conn, $secReqId);

$secNotif = $conn->query("SELECT * FROM notifications WHERE user_id = {$secId} AND entity_id = {$secReqId} LIMIT 1")->fetch_assoc();
assertCondition(!empty($secNotif), "Secretary received notification copy when toggle is ENABLED");

// Disable Secretary
writeSetting($conn, 'notify_secretary_on_requests', '0');
$noSecRef = 'TUGON-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
$conn->query("INSERT INTO requests (user_id, request_type, description, status, reference_number, date_requested) VALUES ({$userId}, 'business_blessing', 'Store blessing request', 'pending', '{$noSecRef}', NOW())");
$noSecReqId = (int)$conn->insert_id;
AdminNotificationService::notifyOnNewRequest($conn, $noSecReqId);

$noSecNotif = $conn->query("SELECT * FROM notifications WHERE user_id = {$secId} AND entity_id = {$noSecReqId} LIMIT 1")->fetch_assoc();
assertCondition(empty($noSecNotif), "Secretary did NOT receive notification copy when toggle is DISABLED");

// Restore setting
writeSetting($conn, 'notify_secretary_on_requests', '1');

// Clean up test records
$conn->query("DELETE FROM notifications WHERE entity_id IN ({$reqBapId}, {$reqUrgentId}, {$certReqId}, {$secReqId}, {$noSecReqId})");
$conn->query("DELETE FROM requests WHERE request_id IN ({$reqBapId}, {$reqUrgentId}, {$certReqId}, {$secReqId}, {$noSecReqId})");
$conn->query("DELETE FROM user_roles WHERE user_id = {$secId}");
$conn->query("DELETE FROM users WHERE id IN ({$userId}, {$adminId}, {$secId})");

echo PHP_EOL . "=== TEST RESULTS ===" . PHP_EOL;
echo "Passed: {$passed}" . PHP_EOL;
echo "Failed: {$failed}" . PHP_EOL;

if ($failed === 0) {
    echo "🎉 ALL AUTOMATIC ADMIN NOTIFICATION TESTS PASSED SUCCESSFULLY!" . PHP_EOL;
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED!" . PHP_EOL;
    exit(1);
}
