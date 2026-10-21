<?php
/**
 * Test Suite: Service Request Schedule Reminder E2E Acceptance Test
 * ----------------------------------------------------------------
 * Tests the exact user scenario:
 * - Request TUGON-2026-C3F31447 with Preferred Date "Oct 22, 2026" & Preferred Time "11:00 AM"
 * - Reference date 2026-10-21 (1 day away)
 * - Verifies:
 *   1. Upcoming schedules list contains TUGON-2026-C3F31447 with countdown label "Tomorrow"
 *   2. In-app notification created with "Tomorrow:" prefix and "11:00 AM"
 *   3. Bell notifications query returns this notification under Group 1
 *   4. Idempotency prevents duplicates
 *   5. Date transition to "Today" supersedes "Tomorrow" reminder
 */

declare(strict_types=1);

define('TEST_RUNNING', true);

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../services/ScheduleReminderService.php';

echo "========================================================\n";
echo "RUNNING REQUEST SCHEDULE REMINDER E2E ACCEPTANCE TEST\n";
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

// 1. Ensure a test user exists
$userRes = $conn->query("SELECT id FROM users WHERE role = 'secretary' LIMIT 1");
$secUser = $userRes->fetch_assoc();
$secUid = $secUser ? (int)$secUser['id'] : 1;

// Clean up existing test request & notifications
$refNum = 'TUGON-2026-C3F31447';
$conn->query("DELETE FROM reminder_log WHERE request_id IN (SELECT request_id FROM requests WHERE reference_number = '{$refNum}')");
$conn->query("DELETE FROM notifications WHERE related_request_id IN (SELECT request_id FROM requests WHERE reference_number = '{$refNum}')");
$conn->query("DELETE FROM requests WHERE reference_number = '{$refNum}'");

// Insert the exact request from the user's issue
$desc = "Preferred date: Oct 22, 2026\nPreferred time: 11:00 AM\nLocation: Main Church\nDetails: Car blessing";
$stmt = $conn->prepare("
    INSERT INTO requests (
        user_id, request_type, description, status, reference_number, 
        preferred_date, preferred_time
    ) VALUES (
        ?, 'car_blessing', ?, 'pending', ?, 
        'Oct 22, 2026', '11:00 AM'
    )
");
$stmt->bind_param('iss', $secUid, $desc, $refNum);
$stmt->execute();
$reqId = $stmt->insert_id;
$stmt->close();

assertTest($reqId > 0, "Created test request {$refNum} (ID: {$reqId}) with Preferred Date 'Oct 22, 2026' and Preferred Time '11:00 AM'");

// 2. Test upcoming schedules parsing
$referenceDate = '2026-10-21';
$upcoming = ScheduleReminderService::getUpcomingSchedules($conn, 3, $referenceDate);

$foundSchedule = null;
foreach ($upcoming as $item) {
    if (isset($item['reference_number']) && $item['reference_number'] === $refNum) {
        $foundSchedule = $item;
        break;
    }
}

assertTest($foundSchedule !== null, "getUpcomingSchedules correctly parsed request {$refNum}");
assertTest($foundSchedule['days_diff'] === 1, "Days away is correctly calculated as 1");
assertTest($foundSchedule['countdown_label'] === 'Tomorrow', "Countdown label is 'Tomorrow'");
assertTest($foundSchedule['start_time'] === '11:00 AM', "Event time preserved as '11:00 AM'");

// 3. Process reminders on 2026-10-21
$result1 = ScheduleReminderService::processReminders($conn, $referenceDate, false);
assertTest($result1['reminders_sent'] >= 1, "processReminders generated at least 1 reminder (sent: {$result1['reminders_sent']})");

// 4. Verify notification in database
$notifRes = $conn->query("
    SELECT * FROM notifications 
    WHERE related_request_id = {$reqId} 
      AND notification_type = 'schedule_reminder'
      AND state <> 'archived'
    ORDER BY notification_id DESC LIMIT 1
");
$notif = $notifRes->fetch_assoc();

assertTest(!empty($notif), "Notification record created for request {$refNum}");
assertTest(str_starts_with($notif['title'] ?? '', 'Tomorrow:'), "Notification title starts with 'Tomorrow:' (Title: " . ($notif['title'] ?? 'none') . ")");
assertTest(str_contains($notif['message'] ?? '', '11:00 AM'), "Notification message includes time '11:00 AM' (Message: " . ($notif['message'] ?? 'none') . ")");
assertTest((int)($notif['days_before'] ?? -1) === 1, "Notification has days_before = 1");

// 5. Test bell query (Group 1 - Schedule Reminders)
// Exactly replicates templates/header.php and api/notifications-bell.php query
$bellQuery = $conn->prepare("
    SELECT n.*
    FROM notifications n
    WHERE n.user_id = ?
      AND n.notification_type = 'schedule_reminder'
      AND n.state <> 'archived'
      AND (
          n.schedule_id IN (
              SELECT se.schedule_id FROM schedule_events se 
              WHERE se.event_date BETWEEN ? AND DATE_ADD(?, INTERVAL 3 DAY)
          )
          OR n.related_request_id IS NOT NULL
      )
    ORDER BY COALESCE(n.days_before, 99) ASC, n.created_at DESC
");
$bellQuery->bind_param('iss', $secUid, $referenceDate, $referenceDate);
$bellQuery->execute();
$bellItems = $bellQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$bellQuery->close();

$foundInBell = false;
foreach ($bellItems as $item) {
    if ((int)$item['related_request_id'] === $reqId) {
        $foundInBell = true;
        break;
    }
}
assertTest($foundInBell, "Bell dropdown query includes request {$refNum} in Group 1 schedule reminders");

// 6. Test IDEMPOTENCY: Running processReminders again on 2026-10-21 produces 0 duplicate notifications
$result2 = ScheduleReminderService::processReminders($conn, $referenceDate, false);
$countLogs = $conn->query("SELECT COUNT(*) AS c FROM reminder_log WHERE request_id = {$reqId} AND days_before = 1")->fetch_assoc()['c'];
$countNotifs = $conn->query("SELECT COUNT(*) AS c FROM notifications WHERE related_request_id = {$reqId} AND days_before = 1 AND user_id = {$secUid}")->fetch_assoc()['c'];

assertTest((int)$countLogs === 1, "reminder_log contains exactly 1 entry for request {$refNum} at 1 day before");
assertTest((int)$countNotifs === 1, "notifications table contains exactly 1 entry per recipient for request {$refNum} at 1 day before");

// 7. Test SUPERSEDING on the next day (2026-10-22, "Today")
$nextDay = '2026-10-22';
$result3 = ScheduleReminderService::processReminders($conn, $nextDay, false);

$todayNotif = $conn->query("
    SELECT * FROM notifications 
    WHERE related_request_id = {$reqId} 
      AND notification_type = 'schedule_reminder' 
      AND days_before = 0 
      AND state <> 'archived'
")->fetch_assoc();

$oldTomorrowArchived = $conn->query("
    SELECT COUNT(*) AS c FROM notifications 
    WHERE related_request_id = {$reqId} 
      AND days_before = 1 
      AND state = 'archived'
")->fetch_assoc()['c'];

assertTest(!empty($todayNotif) && str_starts_with($todayNotif['title'], 'Today:'), "On next day, 'Today:' notification created");
assertTest((int)$oldTomorrowArchived >= 1, "Previous 'Tomorrow:' notification was archived (superseded)");

// Cleanup test records
$conn->query("DELETE FROM reminder_log WHERE request_id = {$reqId}");
$conn->query("DELETE FROM notifications WHERE related_request_id = {$reqId}");
$conn->query("DELETE FROM requests WHERE request_id = {$reqId}");

echo "\n========================================================\n";
echo "E2E ACCEPTANCE TEST SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
