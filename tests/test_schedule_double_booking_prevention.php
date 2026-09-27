<?php
/**
 * Test Suite: Parish Schedule Double-Booking Prevention
 * Validates server-side conflict detection and client-side integration.
 */
require_once __DIR__ . '/../services/ScheduleConflictService.php';

$total = 0;
$passed = 0;
$failed = 0;

function runTest(bool $condition, string $label) {
    global $total, $passed, $failed;
    $total++;
    if ($condition) {
        $passed++;
        echo "[PASS] $label\n";
    } else {
        $failed++;
        echo "[FAIL] $label\n";
    }
}

echo "=== Testing Parish Schedule Conflict & Double-Booking Prevention ===\n\n";

// 1. Location Normalization & Matching
runTest(ScheduleConflictService::normalizeLocation('  Main Church  ') === 'main church', 'Normalize "Main Church"');
runTest(ScheduleConflictService::normalizeLocation('San Lorenzo Ruiz Parish Church') === 'main church', 'Normalize "San Lorenzo Ruiz Parish Church" to canonical church');
runTest(ScheduleConflictService::normalizeLocation('Parish') === 'main church', 'Normalize "Parish" to canonical church');
runTest(ScheduleConflictService::normalizeLocation('Altar') === 'main church', 'Normalize "Altar" to canonical church');
runTest(ScheduleConflictService::locationsMatch('Church', 'Main Church'), 'Location match: "Church" and "Main Church"');
runTest(ScheduleConflictService::locationsMatch('San Lorenzo Ruiz Parish', 'Main Church'), 'Location match: "San Lorenzo Ruiz Parish" and "Main Church"');
runTest(ScheduleConflictService::locationsMatch('Chapel 1', 'Chapel 1, Brgy San Jose'), 'Location match: "Chapel 1" and "Chapel 1, Brgy San Jose"');
runTest(!ScheduleConflictService::locationsMatch('Hospital Room 204', 'Main Church'), 'Location non-match: "Hospital Room 204" and "Main Church"');
runTest(!ScheduleConflictService::locationsMatch('123 Mabini St (Home)', 'Main Church'), 'Location non-match: Home address and Main Church');

// 2. Time Normalization
runTest(ScheduleConflictService::normalizeTime('8:30') === '08:30', 'Normalize "8:30" to "08:30"');
runTest(ScheduleConflictService::normalizeTime('08:30:00') === '08:30', 'Normalize "08:30:00" to "08:30"');
runTest(ScheduleConflictService::normalizeTime('8:30 AM') === '08:30', 'Normalize "8:30 AM" to "08:30"');
runTest(ScheduleConflictService::normalizeTime('2:15 PM') === '14:15', 'Normalize "2:15 PM" to "14:15"');

// 3. Time Conflict Detection (Exact match vs buffer)
runTest(ScheduleConflictService::timesConflict('08:30', '08:30:00', null, true, 0), 'Exact time match detected: 08:30 vs 08:30');
runTest(!ScheduleConflictService::timesConflict('09:30', '08:30:00', null, true, 0), 'Exact time non-match: 09:30 vs 08:30 with 0 buffer');
runTest(ScheduleConflictService::timesConflict('09:00', '08:30:00', '09:30:00', false, 0), 'Window conflict detected: 09:00 within 08:30-09:30');
runTest(ScheduleConflictService::timesConflict('09:45', '08:30:00', '09:30:00', false, 30), 'Buffer conflict detected: 09:45 within 30m buffer of 09:30');

// 4. Error Message Formatting Verification
class MockScheduleConflictService extends ScheduleConflictService {
    public function formatTest(string $date, string $time, string $location): string {
        $res = $this->formatConflictResponse($date, $time, $location);
        return $res['message'];
    }
}

$mock = new MockScheduleConflictService();
$msg = $mock->formatTest('2026-09-29', '08:30', 'Main Church');
$expectedMsg = 'This date and time (Sep 29, 2026 at 8:30 AM) at Main Church is already occupied. Please choose another available schedule.';
runTest($msg === $expectedMsg, 'Error message exactly matches requested format: "' . $expectedMsg . '"');

// 5. Verify Server-Side Files Integration
$svcContent = file_get_contents(__DIR__ . '/../users/request-service.php');
runTest(strpos($svcContent, 'checkScheduleConflict($conn, $preferred_date, $preferred_time, $location)') !== false, 'request-service.php calls checkScheduleConflict before insert');
runTest(strpos($svcContent, "'status_code' => 409") !== false, 'request-service.php rejects with HTTP 409 on conflict');

$blsContent = file_get_contents(__DIR__ . '/../users/request-blessing.php');
runTest(strpos($blsContent, 'checkScheduleConflict($conn, $preferred_date, $preferred_time, $location)') !== false, 'request-blessing.php calls checkScheduleConflict before insert');
runTest(strpos($blsContent, "'status_code' => 409") !== false, 'request-blessing.php rejects with HTTP 409 on conflict');

$hlpContent = file_get_contents(__DIR__ . '/../includes/helpers.php');
runTest(strpos($hlpContent, 'function checkScheduleConflict') !== false, 'helpers.php exports checkScheduleConflict');
runTest(strpos($hlpContent, 'function getOccupiedScheduleSlots') !== false, 'helpers.php exports getOccupiedScheduleSlots');
runTest(strpos($hlpContent, 'checkScheduleConflict($conn, $event_date, $start_time, $location') !== false, 'requestApprovalConflict uses location-aware conflict check');

// 6. Verify API Endpoint
$apiFile = __DIR__ . '/../api/check-schedule-conflict.php';
runTest(file_exists($apiFile), 'api/check-schedule-conflict.php exists');
$apiContent = file_get_contents($apiFile);
runTest(strpos($apiContent, 'ScheduleConflictService') !== false, 'api endpoint uses ScheduleConflictService');
runTest(strpos($apiContent, 'occupied_slots') !== false, 'api endpoint supports occupied_slots action');

// 7. Verify Client-Side JS & CSS
$jsContent = file_get_contents(__DIR__ . '/../assets/js/request-modern.js');
runTest(strpos($jsContent, 'initScheduleConflictChecker') !== false, 'request-modern.js contains initScheduleConflictChecker');
runTest(strpos($jsContent, 'hasScheduleConflictState') !== false, 'request-modern.js tracks hasScheduleConflictState');
runTest(strpos($jsContent, 'scheduleConflictFeedback') !== false, 'request-modern.js binds scheduleConflictFeedback container');
runTest(strpos($jsContent, 'occupiedSlotsNotice') !== false, 'request-modern.js renders occupiedSlotsNotice');

$cssContent = file_get_contents(__DIR__ . '/../assets/css/request-modern.css');
runTest(strpos($cssContent, '.schedule-conflict-notice') !== false, 'request-modern.css styles schedule-conflict-notice');
runTest(strpos($cssContent, '.occupied-slots-container') !== false, 'request-modern.css styles occupied-slots-container');
runTest(strpos($cssContent, '.occupied-slot-pill') !== false, 'request-modern.css styles occupied-slot-pill');

echo "\nTest Summary: $passed/$total passed (" . ($failed === 0 ? "ALL PASSED" : "$failed FAILED") . ")\n";

if ($failed > 0) {
    exit(1);
}
