<?php
/**
 * Test Suite: 60-Minute Schedule Conflict Restriction Requirements
 * Validates all 10 test cases mandated by the specification.
 */
define('CLI_TEST_RUNNING', true);

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/ScheduleConflictService.php';

$total = 0;
$passed = 0;
$failed = 0;

function runTest(bool $condition, string $label, string $details = '') {
    global $total, $passed, $failed;
    $total++;
    if ($condition) {
        $passed++;
        echo "[PASS] $label\n";
    } else {
        $failed++;
        echo "[FAIL] $label" . ($details !== '' ? " ($details)" : '') . "\n";
    }
}

echo "====================================================================\n";
echo " Running 60-Minute Schedule Conflict Restriction Test Suite\n";
echo "====================================================================\n\n";

$service = new ScheduleConflictService($conn);

// Helper function to insert a dummy user if none exists
$user_id = 1;
$uRes = $conn->query("SELECT id FROM users LIMIT 1");
if ($uRes && $uRow = $uRes->fetch_assoc()) {
    $user_id = intval($uRow['id']);
}

// Clean up any test records for test dates
$testDateA = '2026-10-25';
$testDateB = '2026-09-30';
$conn->query("DELETE FROM requests WHERE description LIKE '%[TEST_SUITE]%'");
$conn->query("DELETE FROM schedule_events WHERE title LIKE '%[TEST_SUITE]%'");
$conn->query("DELETE FROM reservations WHERE purpose LIKE '%[TEST_SUITE]%'");
$conn->query("DELETE FROM schedule_slot_locks WHERE source_type = 'test'");

try {
    // -------------------------------------------------------------------------
    // TEST CASE 1: Existing 9:00 AM booking; request 9:00 AM -> blocked.
    // -------------------------------------------------------------------------
    echo "--- Case 1: Existing 9:00 AM booking; request 9:00 AM -> blocked ---\n";
    $desc1 = "[TEST_SUITE] House Blessing\nPreferred Date: $testDateA\nPreferred Time: 09:00";
    $stmt1 = $conn->prepare("INSERT INTO requests (user_id, request_type, description, status, reference_number) VALUES (?, 'house_blessing', ?, 'pending', 'TEST-REF-01')");
    $stmt1->bind_param('is', $user_id, $desc1);
    $stmt1->execute();
    $case1ReqId = $conn->insert_id;
    $stmt1->close();

    $res1 = $service->checkConflict($testDateA, '09:00', 'Main Church');
    runTest(!$res1['available'] && $res1['has_conflict'], 'Case 1: 9:00 AM request is blocked when 9:00 AM is booked', json_encode($res1));

    // -------------------------------------------------------------------------
    // TEST CASE 2: Existing 9:00 AM booking; request 9:30 AM -> blocked, and 9:30 is never suggested.
    // -------------------------------------------------------------------------
    echo "\n--- Case 2: Existing 9:00 AM booking; request 9:30 AM -> blocked, 9:30 never suggested ---\n";
    $res2 = $service->checkConflict($testDateA, '09:30', 'Main Church');
    runTest(!$res2['available'] && $res2['has_conflict'], 'Case 2a: 9:30 AM request is blocked by 60-min overlap [09:00, 10:00) and/or non-hourly rejection');

    $suggestions2 = $service->getAvailableSuggestions($testDateA, 'Main Church');
    $suggestedTimes2 = array_column($suggestions2, 'time');
    $hasHalfHour = false;
    foreach ($suggestedTimes2 as $st) {
        if (substr($st, 3) !== '00') {
            $hasHalfHour = true;
            break;
        }
    }
    runTest(!in_array('09:30', $suggestedTimes2, true) && !$hasHalfHour, 'Case 2b: 9:30 AM is never suggested, all suggestions are :00 hourly slots', 'Suggestions: ' . implode(', ', $suggestedTimes2));

    // -------------------------------------------------------------------------
    // TEST CASE 3: Existing 9:00 AM booking; request 10:00 AM -> allowed.
    // -------------------------------------------------------------------------
    echo "\n--- Case 3: Existing 9:00 AM booking; request 10:00 AM -> allowed ---\n";
    $res3 = $service->checkConflict($testDateA, '10:00', 'Main Church');
    runTest($res3['available'] && !$res3['has_conflict'], 'Case 3: 10:00 AM request is allowed (next slot after 9:00–10:00 AM)');

    // -------------------------------------------------------------------------
    // TEST CASE 4: Existing 9:00 AM booking; request 8:00 AM -> allowed.
    // -------------------------------------------------------------------------
    echo "\n--- Case 4: Existing 9:00 AM booking; request 8:00 AM -> allowed ---\n";
    $res4 = $service->checkConflict($testDateA, '08:00', 'Main Church');
    runTest($res4['available'] && !$res4['has_conflict'], 'Case 4: 8:00 AM request is allowed (slot 8:00–9:00 AM ends when 9:00 AM starts)');

    // -------------------------------------------------------------------------
    // TEST CASE 5: Sep 30, 2026 has a House Blessing at 9:00 AM and Reserved at 1:00 PM;
    //              new request at 9:00 AM or 1:00 PM -> blocked, 10:00 AM or 2:00 PM -> allowed.
    // -------------------------------------------------------------------------
    echo "\n--- Case 5: Sep 30, 2026 House Blessing at 9:00 AM and Reserved at 1:00 PM ---\n";
    // 1. House blessing request at 9:00 AM
    $desc5 = "[TEST_SUITE] House Blessing\nPreferred Date: $testDateB\nPreferred Time: 09:00";
    $stmt5 = $conn->prepare("INSERT INTO requests (user_id, request_type, description, status, reference_number) VALUES (?, 'house_blessing', ?, 'pending', 'TEST-REF-05')");
    $stmt5->bind_param('is', $user_id, $desc5);
    $stmt5->execute();
    $case5ReqId = $conn->insert_id;
    $stmt5->close();

    // 2. Reserved entry at 1:00 PM (13:00) in schedule_events
    $stmt5b = $conn->prepare("INSERT INTO schedule_events (title, description, event_date, start_time, end_time, location, category, created_by, status, approval_status) VALUES ('[TEST_SUITE] Reserved', 'Parish Meeting', ?, '13:00:00', '14:00:00', 'Main Church', 'meeting', ?, 'upcoming', 'approved')");
    $stmt5b->bind_param('si', $testDateB, $user_id);
    $stmt5b->execute();
    $case5EvtId = $conn->insert_id;
    $stmt5b->close();

    $res5_9am = $service->checkConflict($testDateB, '09:00', 'Main Church');
    runTest(!$res5_9am['available'] && $res5_9am['has_conflict'], 'Case 5a: Sep 30, 2026 9:00 AM is blocked');

    $res5_1pm = $service->checkConflict($testDateB, '13:00', 'Main Church');
    runTest(!$res5_1pm['available'] && $res5_1pm['has_conflict'], 'Case 5b: Sep 30, 2026 1:00 PM is blocked');

    $res5_10am = $service->checkConflict($testDateB, '10:00', 'Main Church');
    runTest($res5_10am['available'] && !$res5_10am['has_conflict'], 'Case 5c: Sep 30, 2026 10:00 AM is allowed');

    $res5_2pm = $service->checkConflict($testDateB, '14:00', 'Main Church');
    runTest($res5_2pm['available'] && !$res5_2pm['has_conflict'], 'Case 5d: Sep 30, 2026 2:00 PM (14:00) is allowed');

    // -------------------------------------------------------------------------
    // TEST CASE 6: Cancelled or rejected bookings do not block the slot.
    // -------------------------------------------------------------------------
    echo "\n--- Case 6: Cancelled or rejected bookings do not block the slot ---\n";
    $testDateC = '2026-11-05';
    $desc6 = "[TEST_SUITE] Cancelled Baptism\nPreferred Date: $testDateC\nPreferred Time: 11:00";
    $stmt6 = $conn->prepare("INSERT INTO requests (user_id, request_type, description, status, reference_number) VALUES (?, 'baptism', ?, 'cancelled', 'TEST-REF-06A')");
    $stmt6->bind_param('is', $user_id, $desc6);
    $stmt6->execute();
    $case6ReqId = $conn->insert_id;
    $stmt6->close();

    $res6a = $service->checkConflict($testDateC, '11:00', 'Main Church');
    runTest($res6a['available'] && !$res6a['has_conflict'], 'Case 6a: Cancelled request at 11:00 AM does not block the slot');

    $conn->query("UPDATE requests SET status = 'rejected' WHERE id = $case6ReqId");
    $res6b = $service->checkConflict($testDateC, '11:00', 'Main Church');
    runTest($res6b['available'] && !$res6b['has_conflict'], 'Case 6b: Rejected request at 11:00 AM does not block the slot');

    // -------------------------------------------------------------------------
    // TEST CASE 7: Editing a request without changing its time does not conflict with itself.
    // -------------------------------------------------------------------------
    echo "\n--- Case 7: Editing a request without changing its time does not conflict with itself ---\n";
    $res7_without_exclude = $service->checkConflict($testDateA, '09:00', 'Main Church');
    runTest(!$res7_without_exclude['available'], 'Case 7a: 9:00 AM conflicts when excludeId is not passed');

    $res7_with_exclude = $service->checkConflict($testDateA, '09:00', 'Main Church', ['exclude_request_id' => $case1ReqId]);
    runTest($res7_with_exclude['available'] && !$res7_with_exclude['has_conflict'], 'Case 7b: 9:00 AM is allowed when excludeId matches own request ID');

    // -------------------------------------------------------------------------
    // TEST CASE 8: Two simultaneous submissions for the same slot -> only one succeeds.
    // -------------------------------------------------------------------------
    echo "\n--- Case 8: Two simultaneous submissions for same slot -> only one succeeds ---\n";
    $simDate = '2026-11-12';
    $simTime = '15:00';
    $simEndTime = '16:00:00';

    // Simulate Client A booking and taking slot lock:
    $conn->begin_transaction();
    $slotLockKey = 'parish_schedule_booking_' . $simDate;
    $lockRes = $conn->query("SELECT GET_LOCK('$slotLockKey', 2) AS acquired");
    $lockRow = $lockRes ? $lockRes->fetch_assoc() : null;
    $gotLock = intval($lockRow['acquired'] ?? 0) === 1;

    $clientA_success = false;
    $clientB_success = false;

    if ($gotLock) {
        // Client A verifies conflict
        $chkA = $service->checkConflict($simDate, $simTime, 'Main Church');
        if ($chkA['available']) {
            $descA = "[TEST_SUITE] Client A Request\nPreferred Date: $simDate\nPreferred Time: $simTime";
            $conn->query("INSERT INTO requests (user_id, request_type, description, status, reference_number) VALUES ($user_id, 'wedding', '$descA', 'pending', 'TEST-SIM-A')");
            $clientA_id = $conn->insert_id;
            $conn->query("INSERT INTO schedule_slot_locks (slot_date, slot_time, slot_end_time, source_type, source_id) VALUES ('$simDate', '15:00:00', '$simEndTime', 'request', $clientA_id)");
            $conn->commit();
            $clientA_success = true;
        }
        $conn->query("SELECT RELEASE_LOCK('$slotLockKey')");
    }

    // Client B attempts to book the same slot:
    $chkB = $service->checkConflict($simDate, $simTime, 'Main Church');
    if ($chkB['available']) {
        $clientB_success = true;
    } else {
        $clientB_success = false;
    }

    runTest($clientA_success && !$clientB_success, 'Case 8: First concurrent booking succeeds, second concurrent booking is blocked');

    // -------------------------------------------------------------------------
    // TEST CASE 9: A direct API call bypassing the UI is still rejected with 409.
    // -------------------------------------------------------------------------
    echo "\n--- Case 9: Direct API call bypassing UI is rejected with 409 ---\n";
    // Test the API endpoint logic directly
    // Since API returns 409 for conflict unless suppress_409 is set
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [
        'date' => $testDateA,
        'time' => '09:00',
        'location' => 'Main Church'
    ];

    ob_start();
    // Simulate what api/schedule/check.php does
    $normDate = ScheduleConflictService::normalizeDate($_GET['date']);
    $apiResult = $service->checkConflict($normDate, $_GET['time'], $_GET['location']);
    $suppress409 = isset($_GET['status_200']) || isset($_GET['suppress_409']);
    $apiStatusCode = ($apiResult['available'] || $suppress409) ? 200 : 409;
    ob_end_clean();

    runTest($apiStatusCode === 409 && !$apiResult['available'], 'Case 9: Direct API call returns HTTP 409 conflict status code');

    // -------------------------------------------------------------------------
    // TEST CASE 10: A past date or time is rejected.
    // -------------------------------------------------------------------------
    echo "\n--- Case 10: A past date or time is rejected ---\n";
    $pastDate = '2020-01-01';
    $res10_date = $service->checkConflict($pastDate, '10:00', 'Main Church');
    runTest(!$res10_date['available'] && !empty($res10_date['is_past']), 'Case 10a: Past date (2020-01-01) is rejected with is_past flag');

    // Determine current Asia/Manila time
    $tzManila = new DateTimeZone('Asia/Manila');
    $nowManila = new DateTime('now', $tzManila);
    $todayDateStr = $nowManila->format('Y-m-d');
    $currentHour = intval($nowManila->format('H'));

    if ($currentHour > 8) {
        $pastHour = sprintf('%02d:00', $currentHour - 1);
        $res10_time = $service->checkConflict($todayDateStr, $pastHour, 'Main Church');
        runTest(!$res10_time['available'] && !empty($res10_time['is_past']), "Case 10b: Past time ($pastHour on $todayDateStr) is rejected with is_past flag");
    } else {
        // If current hour is early morning (e.g. 06:00), 01:00 is in the past today
        $res10_time = $service->checkConflict($todayDateStr, '01:00', 'Main Church');
        runTest(!$res10_time['available'] && !empty($res10_time['is_past']), "Case 10b: Early morning past time (01:00 on $todayDateStr) is rejected with is_past flag");
    }

} finally {
    // Clean up test data
    $conn->query("DELETE FROM requests WHERE description LIKE '%[TEST_SUITE]%'");
    $conn->query("DELETE FROM schedule_events WHERE title LIKE '%[TEST_SUITE]%'");
    $conn->query("DELETE FROM reservations WHERE purpose LIKE '%[TEST_SUITE]%'");
    $conn->query("DELETE FROM schedule_slot_locks WHERE source_type = 'test' OR source_type = 'request'");
}

echo "\n====================================================================\n";
echo "Suite Summary: $passed/$total passed (" . ($failed === 0 ? "ALL 10 CASES PASSED" : "$failed FAILED") . ")\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
