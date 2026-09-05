<?php
/**
 * Test Suite: Calendar Slot Conflict & Double-Booking Control (Rule 2)
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../services/ReservationService.php';
require_once __DIR__ . '/../services/ResourceAvailabilityService.php';

$passed = 0;
$failed = 0;

function calCheck(bool $condition, string $label): void {
    global $passed, $failed;
    if ($condition) {
        echo "PASS: $label\n";
        $passed++;
    } else {
        echo "FAIL: $label\n";
        $failed++;
    }
}

// Ensure active user and resource
$userRow = $conn->query("SELECT id FROM users WHERE status='active' ORDER BY id LIMIT 1")->fetch_assoc();
$resourceRow = $conn->query("SELECT resource_id FROM resources WHERE status='available' AND deleted_at IS NULL ORDER BY resource_id LIMIT 1")->fetch_assoc();

if (!$userRow || !$resourceRow) {
    echo "FAIL: Test requires an active user and at least one available resource.\n";
    exit(1);
}

$userId = (int) $userRow['id'];
$resourceId = (int) $resourceRow['resource_id'];

$service = new ReservationService($conn);
$availability = new ResourceAvailabilityService($conn);

$createdReservationIds = [];
$testDate = '2099-' . str_pad((string)random_int(1,12), 2, '0', STR_PAD_LEFT) . '-' . str_pad((string)random_int(1,28), 2, '0', STR_PAD_LEFT);

// Pre-clean any stray test reservations for this date
$conn->query("DELETE FROM reservation_resources WHERE reservation_id IN (SELECT reservation_id FROM reservations WHERE event_date = '$testDate')");
$conn->query("DELETE FROM reservations WHERE event_date = '$testDate'");

try {
    $key1 = hash('sha256', random_bytes(32));
    $key2 = hash('sha256', random_bytes(32));

    // Test 1: Parishioner A books 10:00 AM - 11:00 AM with 15m setup + 15m cleanup (30m transition buffer)
    $res1 = $service->create($userId, [
        'reservation_type' => 'wedding',
        'start_at' => $testDate . ' 10:00:00',
        'service_duration_minutes' => 60,
        'setup_duration_minutes' => 15,
        'cleanup_duration_minutes' => 15,
        'event_details' => 'Parishioner A Wedding',
        'resource_ids' => [$resourceId]
    ], $key1);

    calCheck(!empty($res1['reservation_id']) && $res1['status'] === 'pending', 'Parishioner A successfully books initial time slot');
    $res1Id = (int) $res1['reservation_id'];
    $createdReservationIds[] = $res1Id;

    // Test 2: Double-booking prevention - exact overlapping slot is rejected
    $doubleBooked = false;
    try {
        $service->create($userId, [
            'reservation_type' => 'baptism',
            'start_at' => $testDate . ' 10:00:00',
            'service_duration_minutes' => 60,
            'setup_duration_minutes' => 15,
            'cleanup_duration_minutes' => 15,
            'event_details' => 'Parishioner B Collision Attempt',
            'resource_ids' => [$resourceId]
        ], $key2);
    } catch (DomainException $e) {
        $doubleBooked = true;
    }
    calCheck($doubleBooked, 'Exact double-booking attempt is rejected by availability engine');

    // Test 3: Partial overlapping slot (e.g. 10:30 AM - 11:30 AM) is rejected
    $partialOverlap = false;
    $key3 = hash('sha256', random_bytes(32));
    try {
        $service->create($userId, [
            'reservation_type' => 'baptism',
            'start_at' => $testDate . ' 10:30:00',
            'service_duration_minutes' => 60,
            'setup_duration_minutes' => 15,
            'cleanup_duration_minutes' => 15,
            'event_details' => 'Partial overlap collision',
            'resource_ids' => [$resourceId]
        ], $key3);
    } catch (DomainException $e) {
        $partialOverlap = true;
    }
    calCheck($partialOverlap, 'Partial overlapping slot is rejected');

    // Test 4: 30-minute transition buffer enforcement
    // Service ends at 11:00 + 15m cleanup = 11:15 occupied.
    // An event attempting to start at 11:05 with 15m setup reaches back to 10:50, overlapping the buffer!
    $bufferConflict = false;
    $key4 = hash('sha256', random_bytes(32));
    try {
        $service->create($userId, [
            'reservation_type' => 'church_venue',
            'start_at' => $testDate . ' 11:05:00',
            'service_duration_minutes' => 60,
            'setup_duration_minutes' => 15,
            'cleanup_duration_minutes' => 15,
            'event_details' => 'Buffer collision',
            'resource_ids' => [$resourceId]
        ], $key4);
    } catch (DomainException $e) {
        $bufferConflict = true;
    }
    calCheck($bufferConflict, '30-minute transition buffer conflict is strictly enforced');

    // Test 5: Distinct slot after full transition buffer is allowed (e.g. starting at 11:30 AM)
    // Setup for 11:30 is 11:15, which meets exactly the 11:15 cleanup boundary without overlap!
    $key5 = hash('sha256', random_bytes(32));
    $resAfterBuffer = $service->create($userId, [
        'reservation_type' => 'church_venue',
        'start_at' => $testDate . ' 11:30:00',
        'service_duration_minutes' => 60,
        'setup_duration_minutes' => 15,
        'cleanup_duration_minutes' => 15,
        'event_details' => 'Adjacent slot after full buffer',
        'resource_ids' => [$resourceId]
    ], $key5);
    calCheck(!empty($resAfterBuffer['reservation_id']), 'Booking after transition buffer is successfully permitted');
    $createdReservationIds[] = (int) $resAfterBuffer['reservation_id'];

    // Test 6: Cancelled reservation frees up the time slot
    $conn->query("UPDATE reservations SET status='cancelled' WHERE reservation_id = $res1Id");
    $key6 = hash('sha256', random_bytes(32));
    $resReclaimed = $service->create($userId, [
        'reservation_type' => 'baptism',
        'start_at' => $testDate . ' 10:00:00',
        'service_duration_minutes' => 60,
        'setup_duration_minutes' => 15,
        'cleanup_duration_minutes' => 15,
        'event_details' => 'Reclaiming slot after cancellation',
        'resource_ids' => [$resourceId]
    ], $key6);
    calCheck(!empty($resReclaimed['reservation_id']), 'Slot is freely available after previous reservation is cancelled');
    $createdReservationIds[] = (int) $resReclaimed['reservation_id'];

    // Test 7: api/calendar-availability.php returns accurate slot states
    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = 'user';
    $_SESSION['fully_authenticated'] = true;
    $_GET['date'] = $testDate;
    $_GET['resource_ids'] = (string)$resourceId;
    $_GET['duration'] = '60';
    $_GET['buffer'] = '30';

    ob_start();
    include __DIR__ . '/../api/calendar-availability.php';
    $apiJson = ob_get_clean();
    $apiData = json_decode($apiJson, true);

    calCheck(!empty($apiData['success']), 'Availability API responds with success: true');
    calCheck(isset($apiData['slots']) && is_array($apiData['slots']), 'Availability API returns slots array');
    calCheck(isset($apiData['occupied_intervals']) && is_array($apiData['occupied_intervals']), 'Availability API returns occupied_intervals array');

} finally {
    foreach ($createdReservationIds as $cid) {
        $conn->query("DELETE FROM reservation_schedule_history WHERE reservation_id = $cid");
        $conn->query("DELETE FROM reservation_resources WHERE reservation_id = $cid");
        $conn->query("DELETE FROM reservation_notifications WHERE reservation_id = $cid");
        $rRow = $conn->query("SELECT request_id FROM reservations WHERE reservation_id = $cid")->fetch_assoc();
        $conn->query("DELETE FROM reservations WHERE reservation_id = $cid");
        if (!empty($rRow['request_id'])) {
            $rid = (int) $rRow['request_id'];
            $conn->query("DELETE FROM request_status_history WHERE request_id = $rid");
            $conn->query("DELETE FROM request_idempotency_keys WHERE request_id = $rid");
            $conn->query("DELETE FROM requests WHERE request_id = $rid");
        }
    }
}

echo "\nCalendar Slot Conflict Tests: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
