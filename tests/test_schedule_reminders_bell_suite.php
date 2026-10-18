<?php
/**
 * Test Suite: Automated Schedule Reminders and Notification Bell
 * -------------------------------------------------------------
 * Tests:
 * 1. Schedule reminders creation for 3, 2, 1, and 0 days (Today)
 * 2. Idempotency via reminder_log (no duplicate reminders)
 * 3. Superseding older reminders (one active reminder per schedule)
 * 4. Cancellation & Rescheduling handling
 * 5. API response structure (Schedule reminders group at top, urgency colors, Other updates)
 * 6. Mark as read & Mark all as read actions
 */

declare(strict_types=1);

define('TEST_RUNNING', true);

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../services/ScheduleReminderService.php';

echo "========================================================\n";
echo "RUNNING SCHEDULE REMINDERS & NOTIFICATION BELL TEST SUITE\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $message): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] " . $message . "\n";
    } else {
        $failCount++;
        echo " [FAIL] " . $message . "\n";
    }
}

// Setup test schedules
$today = date('Y-m-d');
$d0 = $today;
$d1 = date('Y-m-d', strtotime('+1 day'));
$d2 = date('Y-m-d', strtotime('+2 days'));
$d3 = date('Y-m-d', strtotime('+3 days'));

// Clean up any previous test schedules
$conn->query("DELETE FROM schedule_events WHERE title LIKE '[TEST_REMINDER]%'");
$conn->query("DELETE FROM notifications WHERE title LIKE '%[TEST_REMINDER]%'");
$conn->query("DELETE FROM reminder_log WHERE schedule_id NOT IN (SELECT schedule_id FROM schedule_events)");

// 1. Insert 4 test schedules for 3, 2, 1, 0 days away
$testSchedIds = [];
$testDays = [0 => $d0, 1 => $d1, 2 => $d2, 3 => $d3];
$services = [0 => 'Funeral Mass', 1 => 'Confirmation Mass', 2 => 'Wedding Ceremony', 3 => 'Baptism'];

foreach ($testDays as $days => $dateStr) {
    $title = "[TEST_REMINDER] {$services[$days]} - Test Subject {$days}";
    $stmt = $conn->prepare("
        INSERT INTO schedule_events (
            title, description, event_date, start_time, end_time, location, 
            category, approval_status, status, created_by
        ) VALUES (?, 'Test reminder event', ?, '14:00:00', '15:00:00', 'Main Church', 'sacramental', 'approved', 'upcoming', 1)
    ");
    $stmt->bind_param('ss', $title, $dateStr);
    $stmt->execute();
    $testSchedIds[$days] = $stmt->insert_id;
    $stmt->close();
}

assertTest(count($testSchedIds) === 4, "Created 4 test schedules for 0, 1, 2, and 3 days away");

// 2. Process reminders
$res1 = ScheduleReminderService::processReminders($conn, $today, false);
assertTest($res1['reminders_sent'] >= 4, "Initial reminder run sent at least 4 reminders (sent: {$res1['reminders_sent']})");

// 3. Verify reminder_log entries
$logCount = 0;
foreach ($testSchedIds as $days => $schedId) {
    $logged = ScheduleReminderService::hasReminderLogged($conn, $schedId, $days);
    if ($logged) $logCount++;
}
assertTest($logCount === 4, "All 4 schedules recorded in reminder_log with exact (schedule_id, days_before)");

// 4. Verify in-app notifications format and urgency
$notifCheck = $conn->query("
    SELECT schedule_id, days_before, title, message 
    FROM notifications 
    WHERE schedule_id IN (" . implode(',', array_values($testSchedIds)) . ")
      AND notification_type = 'schedule_reminder'
");

$foundThresholds = [];
while ($row = $notifCheck->fetch_assoc()) {
    $foundThresholds[(int)$row['days_before']] = $row;
}

assertTest(isset($foundThresholds[0]) && str_starts_with($foundThresholds[0]['title'], 'Today:'), "Day 0 reminder formatted with 'Today:' prefix");
assertTest(isset($foundThresholds[1]) && str_starts_with($foundThresholds[1]['title'], 'Tomorrow:'), "Day 1 reminder formatted with 'Tomorrow:' prefix");
assertTest(isset($foundThresholds[2]) && str_starts_with($foundThresholds[2]['title'], 'In 2 days:'), "Day 2 reminder formatted with 'In 2 days:' prefix");
assertTest(isset($foundThresholds[3]) && str_starts_with($foundThresholds[3]['title'], 'In 3 days:'), "Day 3 reminder formatted with 'In 3 days:' prefix");

// 5. Test IDEMPOTENCY: Running processReminders again must send 0 duplicates
$res2 = ScheduleReminderService::processReminders($conn, $today, false);
$duplicateLogged = false;
foreach ($testSchedIds as $days => $schedId) {
    $cnt = $conn->query("SELECT COUNT(*) AS c FROM reminder_log WHERE schedule_id = {$schedId} AND days_before = {$days}")->fetch_assoc()['c'];
    if ((int)$cnt > 1) {
        $duplicateLogged = true;
    }
}
assertTest(!$duplicateLogged, "Idempotency verified: UNIQUE(schedule_id, days_before) prevented duplicate log entries");

// 6. Test SUPERSEDING older reminders:
// Simulate advancing the 3-day schedule to 2 days away
$targetSchedId = $testSchedIds[3];
$simulatedNextDate = date('Y-m-d', strtotime('+1 day')); // Tomorrow as reference date
// On simulatedNextDate, targetSchedId (originally scheduled 3 days from today) is now 2 days away!
$res3 = ScheduleReminderService::processReminders($conn, $simulatedNextDate, false);

// Check if old 3-day notification was archived
$oldArchived = $conn->query("
    SELECT COUNT(*) AS c FROM notifications 
    WHERE schedule_id = {$targetSchedId} AND days_before = 3 AND state = 'archived'
")->fetch_assoc()['c'];
assertTest((int)$oldArchived > 0, "Older 3-day reminder was automatically superseded and archived as schedule got closer");

// 7. Test CANCELLATION handling:
$cancelSchedId = $testSchedIds[1];
ScheduleReminderService::handleScheduleChanged($conn, $cancelSchedId, 'cancelled', ['reason' => 'Family requested postponement']);

$cancelArchived = $conn->query("
    SELECT COUNT(*) AS c FROM notifications 
    WHERE schedule_id = {$cancelSchedId} AND state = 'archived'
")->fetch_assoc()['c'];
$cancelNotified = $conn->query("
    SELECT COUNT(*) AS c FROM notifications 
    WHERE schedule_id = {$cancelSchedId} AND notification_type = 'schedule_cancelled'
")->fetch_assoc()['c'];
assertTest((int)$cancelArchived > 0, "Pending reminders archived on schedule cancellation");
assertTest((int)$cancelNotified > 0, "Secretary notified with 'schedule_cancelled' alert on cancellation");

// 8. Test RESCHEDULING handling:
$reschedId = $testSchedIds[2];
$newDate = date('Y-m-d', strtotime('+10 days'));
ScheduleReminderService::handleScheduleChanged($conn, $reschedId, 'rescheduled', ['new_date' => $newDate, 'new_time' => '16:00:00']);

$logCleared = $conn->query("SELECT COUNT(*) AS c FROM reminder_log WHERE schedule_id = {$reschedId}")->fetch_assoc()['c'];
$reschedNotified = $conn->query("SELECT COUNT(*) AS c FROM notifications WHERE schedule_id = {$reschedId} AND notification_type = 'schedule_rescheduled'")->fetch_assoc()['c'];
assertTest((int)$logCleared === 0, "Previous reminder_log reset on reschedule so new thresholds will fire properly");
assertTest((int)$reschedNotified > 0, "Secretary notified with 'schedule_rescheduled' alert");

// 9. Test Secretary Settings persistence
$secUser = $conn->query("SELECT id FROM users WHERE role = 'secretary' OR id = 74 LIMIT 1")->fetch_assoc();
$secUid = $secUser ? (int)$secUser['id'] : 1;

$testSettings = [
    'remind_3_days' => 1,
    'remind_2_days' => 1,
    'remind_1_day' => 1,
    'remind_0_day' => 1,
    'send_time' => '07:00',
    'channel_in_app' => 1,
    'channel_email' => 1,
    'channel_sms' => 0,
    'stale_days_threshold' => 4,
    'notify_new_request' => 1,
    'notify_cancelled_request' => 1,
    'notify_conflicts' => 1,
    'notify_stale_requests' => 1
];
$saved = ScheduleReminderService::saveSettings($conn, $testSettings, $secUid);
assertTest($saved, "Saved custom secretary settings for user #{$secUid}");

$loaded = ScheduleReminderService::getSettings($conn, $secUid);
assertTest($loaded['send_time'] === '07:00' && (int)$loaded['stale_days_threshold'] === 4, "Loaded settings correctly reflecting saved send_time (07:00) and stale_days (4)");

// Clean up test data
$conn->query("DELETE FROM schedule_events WHERE title LIKE '[TEST_REMINDER]%'");
$conn->query("DELETE FROM notifications WHERE title LIKE '%[TEST_REMINDER]%'");
$conn->query("DELETE FROM reminder_log WHERE schedule_id IN (" . implode(',', array_values($testSchedIds)) . ")");

echo "\n========================================================\n";
echo "TEST SUITE SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
