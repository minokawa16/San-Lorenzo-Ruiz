<?php
$conn = null;
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/account-management.php';

$conn = $conn ?? null;

echo "========================================================\n";
echo "Testing System-Wide Automatic Notification Dispatch\n";
echo "========================================================\n\n";

$tests_passed = 0;
$total_tests = 0;

function assertCondition($name, $condition) {
    global $tests_passed, $total_tests;
    $total_tests++;
    if ($condition) {
        echo " [PASS] $name\n";
        $tests_passed++;
    } else {
        echo " [FAIL] $name\n";
    }
}

// Test 1: Helper functions exist and callable
assertCondition("notifyAllActiveParishioners exists", function_exists('notifyAllActiveParishioners'));
assertCondition("notifyUserAutomatic exists", function_exists('notifyUserAutomatic'));
assertCondition("dispatchNotificationDelivery exists", function_exists('dispatchNotificationDelivery'));
assertCondition("createRequestStatusNotification exists", function_exists('createRequestStatusNotification'));

// Test 2: Execution of notifyAllActiveParishioners without throwing
try {
    $result = notifyAllActiveParishioners($conn, 'Test Automatic Broadcast', 'Automated broadcast test message', 'announcements');
    assertCondition("notifyAllActiveParishioners runs safely without exceptions", is_array($result) && isset($result['count']));
} catch (Throwable $e) {
    assertCondition("notifyAllActiveParishioners runs safely without exceptions", false);
    echo "  Error: " . $e->getMessage() . "\n";
}

// Test 3: Execution of notifyUserAutomatic for invalid user (handles silently)
try {
    $user_res = notifyUserAutomatic($conn, 999999, 'Test User Notification', 'Test user message', 'system');
    assertCondition("notifyUserAutomatic handles nonexistent user safely", is_array($user_res) || $user_res === false);
} catch (Throwable $e) {
    assertCondition("notifyUserAutomatic handles nonexistent user safely", false);
}

// Test 4: Verify UI form files have ZERO notification channel selection checkboxes
$announcements_file = file_get_contents(__DIR__ . '/../admin/manage-announcements.php');
assertCondition("manage-announcements.php has no notify_email checkbox", strpos($announcements_file, 'name="notify_email"') === false);
assertCondition("manage-announcements.php has no notify_sms checkbox", strpos($announcements_file, 'name="notify_sms"') === false);
assertCondition("manage-announcements.php has no notify_all checkbox", strpos($announcements_file, 'name="notify_all"') === false);
assertCondition("manage-announcements.php has no notify_system checkbox", strpos($announcements_file, 'name="notify_system"') === false);
assertCondition("manage-announcements.php has no Notification Channels panel header", strpos($announcements_file, 'Notification Channels & Options') === false);

$calendar_file = file_get_contents(__DIR__ . '/../admin/manage-calendar.php');
assertCondition("manage-calendar.php has no notify_email switch", strpos($calendar_file, 'id="notify_email"') === false);
assertCondition("manage-calendar.php has no notify_sms switch", strpos($calendar_file, 'id="notify_sms"') === false);

$api_calendar_file = file_get_contents(__DIR__ . '/../api/calendar-events.php');
assertCondition("api/calendar-events.php defaults notify_email to 1", strpos($api_calendar_file, '$notify_email = 1;') !== false);
assertCondition("api/calendar-events.php defaults notify_sms to 1", strpos($api_calendar_file, '$notify_sms = 1;') !== false);

echo "\n========================================================\n";
echo "Summary: $tests_passed / $total_tests tests passed.\n";
echo "========================================================\n";
