<?php
/**
 * Test Suite: 30-Minute Buffer Schedule Conflict Verification
 * 
 * Verifies that:
 * 1. 30-minute buffer window is enforced (exact match, <=30m before start, <=30m after start/end).
 * 2. Beyond 30m is allowed (31m before, 31m after).
 * 3. Scope conflict check per location (different locations do not conflict).
 * 4. Only Reserved/Approved/Confirmed events are evaluated (cancelled/rejected are ignored).
 * 5. Informative, polite error message is generated without exposing private details.
 * 6. Client-side and server-side integration artifacts exist and enforce the checks.
 */

require_once __DIR__ . '/../services/ScheduleConflictService.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$total = 0;
$passed = 0;
$failed = 0;

function it(bool $condition, string $label) {
    global $total, $passed, $failed;
    $total++;
    if ($condition) {
        $passed++;
        echo "  [PASS] $label\n";
    } else {
        $failed++;
        echo "  [FAIL] $label\n";
    }
}

echo "=================================================================\n";
echo " TEST SUITE: 30-MINUTE BUFFER SCHEDULE CONFLICT CHECK\n";
echo "=================================================================\n\n";

// --- SECTION 1: Pure Time Window Logic (timesConflict) ---
echo "Section 1: 30-Minute Buffer Boundary Logic (Instantaneous / No End Time Event at 09:00)\n";
// Window is 08:30 to 09:30 inclusive
it(!ScheduleConflictService::timesConflict('08:29', '09:00', null, false, 30), '08:29 (31 min before 09:00) is CLEAR');
it(ScheduleConflictService::timesConflict('08:30', '09:00', null, false, 30), '08:30 (exact 30 min before 09:00) is CONFLICT');
it(ScheduleConflictService::timesConflict('08:45', '09:00', null, false, 30), '08:45 (15 min before 09:00) is CONFLICT');
it(ScheduleConflictService::timesConflict('09:00', '09:00', null, false, 30), '09:00 (exact time match) is CONFLICT');
it(ScheduleConflictService::timesConflict('09:15', '09:00', null, false, 30), '09:15 (15 min after 09:00) is CONFLICT');
it(ScheduleConflictService::timesConflict('09:30', '09:00', null, false, 30), '09:30 (exact 30 min after 09:00) is CONFLICT');
it(!ScheduleConflictService::timesConflict('09:31', '09:00', null, false, 30), '09:31 (31 min after 09:00) is CLEAR');

echo "\nSection 2: 30-Minute Buffer with Event Duration (Event 09:00 to 10:00)\n";
// Window is 08:30 to 10:30 inclusive
it(!ScheduleConflictService::timesConflict('08:29', '09:00', '10:00', false, 30), '08:29 (31 min before start) is CLEAR');
it(ScheduleConflictService::timesConflict('08:30', '09:00', '10:00', false, 30), '08:30 (30 min before start) is CONFLICT');
it(ScheduleConflictService::timesConflict('09:30', '09:00', '10:00', false, 30), '09:30 (during event) is CONFLICT');
it(ScheduleConflictService::timesConflict('10:00', '09:00', '10:00', false, 30), '10:00 (event end time) is CONFLICT');
it(ScheduleConflictService::timesConflict('10:30', '09:00', '10:00', false, 30), '10:30 (30 min after event end) is CONFLICT');
it(!ScheduleConflictService::timesConflict('10:31', '09:00', '10:00', false, 30), '10:31 (31 min after event end) is CLEAR');

echo "\nSection 3: Default Buffer Constant\n";
it(ScheduleConflictService::BUFFER_MINUTES_DEFAULT === 30, 'ScheduleConflictService::BUFFER_MINUTES_DEFAULT is 30 minutes');
it(ScheduleConflictService::timesConflict('08:45', '09:00'), 'timesConflict defaults to 30m buffer without explicit parameter');

echo "\nSection 4: Database Conflict Check (with Temporary Calendar Event)\n";
$testDate = '2098-11-20';
$testLocation = 'San Lorenzo Ruiz Parish Church';

// Ensure clean slate for test date
$conn->query("DELETE FROM schedule_events WHERE event_date = '$testDate'");

// Insert test approved calendar event: 09:00 to 09:00 (no duration) at Main Church
$stmt = $conn->prepare("
    INSERT INTO schedule_events 
    (title, description, event_date, start_time, end_time, location, category, approval_status, status, visibility, created_by)
    VALUES ('Buffer Test Event', 'Testing 30m buffer', ?, '09:00:00', '09:00:00', ?, 'event', 'approved', 'upcoming', 'public', 1)
");
$stmt->bind_param('ss', $testDate, $testLocation);
$stmt->execute();
$insertedScheduleId = $stmt->insert_id;
$stmt->close();

$service = new ScheduleConflictService($conn);

// Test conflict checking on the test event
$res829 = $service->checkConflict($testDate, '08:29', $testLocation);
it(!$res829['has_conflict'], 'DB Check: 08:29 is NOT in conflict');

$res830 = $service->checkConflict($testDate, '08:30', $testLocation);
it($res830['has_conflict'], 'DB Check: 08:30 IS in conflict (30m before)');

$res900 = $service->checkConflict($testDate, '09:00', $testLocation);
it($res900['has_conflict'], 'DB Check: 09:00 IS in conflict (exact time)');

$res915 = $service->checkConflict($testDate, '09:15', $testLocation);
it($res915['has_conflict'], 'DB Check: 09:15 IS in conflict (15m after)');

$res930 = $service->checkConflict($testDate, '09:30', $testLocation);
it($res930['has_conflict'], 'DB Check: 09:30 IS in conflict (30m after)');

$res931 = $service->checkConflict($testDate, '09:31', $testLocation);
it(!$res931['has_conflict'], 'DB Check: 09:31 is NOT in conflict');

echo "\nSection 5: Location Scoping Verification\n";
// Request at a distinct chapel should NOT conflict with Main Church event
$resDiffLoc = $service->checkConflict($testDate, '09:00', 'Chapel of the Holy Cross, Sitio Esperanza');
it(!$resDiffLoc['has_conflict'], 'DB Check: Different chapel/location at 09:00 does NOT conflict with Main Church');

// Request at Main Church alias SHOULD conflict
$resAliasLoc = $service->checkConflict($testDate, '09:00', 'Parish Church');
it($resAliasLoc['has_conflict'], 'DB Check: Canonical alias "Parish Church" DOES conflict with Main Church');

echo "\nSection 6: Status Filter Verification (Cancelled / Rejected Ignored)\n";
// Mark event as cancelled
$conn->query("UPDATE schedule_events SET status = 'cancelled', approval_status = 'rejected' WHERE schedule_id = $insertedScheduleId");
$resCancelled = $service->checkConflict($testDate, '09:00', $testLocation);
it(!$resCancelled['has_conflict'], 'DB Check: Cancelled/rejected event is ignored and does NOT block slots');

// Clean up test event
$conn->query("DELETE FROM schedule_events WHERE event_date = '$testDate'");

echo "\nSection 7: Clear Error Message Formatting\n";
$sampleConflict = $service->checkConflict('2026-09-10', '08:45', 'Main Church', [
    'buffer_minutes' => 30
]);
// Test mock formatting directly
class ConcreteConflictService extends ScheduleConflictService {
    public function getSampleResponse(): array {
        return $this->formatConflictResponse('2026-09-10', '08:45', 'Main Church', [
            'event_time' => '09:00',
            'event_end_time' => '09:00',
            'buffer_minutes' => 30
        ]);
    }
}
$mockService = new ConcreteConflictService();
$formatted = $mockService->getSampleResponse();

it(strpos($formatted['message'], 'This date and time is already occupied') !== false, 'Message begins with "This date and time is already occupied"');
it(strpos($formatted['message'], '9:00 AM') !== false, 'Message indicates the booked time (9:00 AM)');
it(strpos($formatted['message'], '8:30 AM') !== false, 'Message indicates the buffer start boundary (8:30 AM)');
it(strpos($formatted['message'], '9:30 AM') !== false, 'Message indicates the buffer end boundary (9:30 AM)');
it(strpos($formatted['message'], 'password') === false && strpos($formatted['message'], 'email') === false, 'Message does not expose private parishioner information');

echo "\nSection 8: Front-End Button Disabling and Integration\n";
$jsCode = file_get_contents(__DIR__ . '/../assets/js/request-modern.js');
it(strpos($jsCode, 'updateSubmitButtonsConflictState(true)') !== false, 'request-modern.js calls updateSubmitButtonsConflictState(true) on conflict');
it(strpos($jsCode, 'updateSubmitButtonsConflictState(false)') !== false, 'request-modern.js calls updateSubmitButtonsConflictState(false) when available');
it(strpos($jsCode, 'btn.disabled = true') !== false, 'request-modern.js disables submit buttons on conflict');
it(strpos($jsCode, 'btn.disabled = false') !== false, 'request-modern.js re-enables submit buttons when non-conflicting');
it(strpos($jsCode, 'disabled-by-conflict') !== false, 'request-modern.js applies disabled-by-conflict visual state');

$svcCode = file_get_contents(__DIR__ . '/../users/request-service.php');
it(strpos($svcCode, 'window.hasScheduleConflictState') !== false, 'request-service.php guards validateForReview with conflict state');

$blsCode = file_get_contents(__DIR__ . '/../users/request-blessing.php');
it(strpos($blsCode, 'window.hasScheduleConflictState') !== false, 'request-blessing.php guards submitBlessing with conflict state');

echo "\n=================================================================\n";
echo " RESULTS: $passed / $total passed (" . ($failed === 0 ? "ALL PASSED" : "$failed FAILED") . ")\n";
echo "=================================================================\n";

if ($failed > 0) {
    exit(1);
}
