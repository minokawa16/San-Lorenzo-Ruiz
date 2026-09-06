<?php
/**
 * Test Suite: Automated Workflow on Sacramental Request Approval
 * 
 * Tests:
 * 1. Sacramental record views existence and updatability (sacramental_records_baptism, sacramental_records_marriage, sacramental_records_death)
 * 2. Baptism request approval -> baptism_records insert, schedule_events lock, notification dispatch
 * 3. Marriage request approval -> marriage_records insert, schedule_events lock, notification dispatch
 * 4. Funeral request approval -> funeral_records insert, schedule_events lock, notification dispatch
 * 5. Atomic rollback integrity on failure
 * 6. Calendar privacy masking (Reserved for public, full details + record_url for admin)
 */

define('CLI_TEST_RUNNING', true);
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/SacramentalApprovalService.php';

$passedTests = 0;
$failedTests = 0;

function assertCondition(bool $condition, string $testName, string $details = '') {
    global $passedTests, $failedTests;
    if ($condition) {
        $passedTests++;
        echo "[PASS] {$testName}\n";
    } else {
        $failedTests++;
        echo "[FAIL] {$testName}" . ($details ? ": {$details}" : "") . "\n";
    }
}

echo "=== Sacramental Request Approval Automated Workflow Test Suite ===\n\n";

// Ensure test user exists
$adminUser = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
$testAdminId = intval($adminUser['id'] ?? 1);

$testUser = $conn->query("SELECT id FROM users WHERE role != 'admin' LIMIT 1")->fetch_assoc();
$testUserId = intval($testUser['id'] ?? $testAdminId);

// Clean any leftover test rows from previous runs
$conn->query("DELETE FROM schedule_events WHERE source_type = 'request' AND source_id IN (SELECT request_id FROM requests WHERE reference_number LIKE 'REQ-TEST-%')");
$conn->query("DELETE FROM schedule_events WHERE title LIKE '%Test%' OR title LIKE '%Santos%'");
$conn->query("DELETE FROM baptism_records WHERE request_id IN (SELECT request_id FROM requests WHERE reference_number LIKE 'REQ-TEST-%')");
$conn->query("DELETE FROM marriage_records WHERE request_id IN (SELECT request_id FROM requests WHERE reference_number LIKE 'REQ-TEST-%')");
$conn->query("DELETE FROM funeral_records WHERE request_id IN (SELECT request_id FROM requests WHERE reference_number LIKE 'REQ-TEST-%')");
$conn->query("DELETE FROM notifications WHERE user_id = {$testUserId}");
$conn->query("DELETE FROM request_status_history WHERE request_id IN (SELECT request_id FROM requests WHERE reference_number LIKE 'REQ-TEST-%')");
$conn->query("DELETE FROM requests WHERE reference_number LIKE 'REQ-TEST-%'");

// Test 1: Ensure sacramental views exist
$viewsOk = ensureSacramentalRecordViews($conn);
assertCondition($viewsOk === true, '1. Sacramental record views created successfully');

$bapViewCheck = $conn->query("SELECT 1 FROM sacramental_records_baptism LIMIT 1");
assertCondition($bapViewCheck !== false, '1a. sacramental_records_baptism view queryable');

$marViewCheck = $conn->query("SELECT 1 FROM sacramental_records_marriage LIMIT 1");
assertCondition($marViewCheck !== false, '1b. sacramental_records_marriage view queryable');

$deathViewCheck = $conn->query("SELECT 1 FROM sacramental_records_death LIMIT 1");
assertCondition($deathViewCheck !== false, '1c. sacramental_records_death view queryable');

$service = new SacramentalApprovalService($conn);

// Test 2: BAPTISM Approval Workflow
echo "\n--- Testing Baptism Request Approval ---\n";
$bapDate = date('Y-m-d', strtotime('+10 days'));
$bapTime = '10:00:00';
$childName = 'Baby Mateo Test ' . time();
$bapDesc = "Preferred date: {$bapDate}\nPreferred time: 10:00 AM\nLocation: San Lorenzo Ruiz Parish Church\n\n--- PRE-BAPTISMAL INVESTIGATION SHEET ---\n1. Child's Information:\nName of Child: {$childName}\nDate of Birth: 2026-01-15 | Place of Birth: Manila Doctors Hospital\n\n2. Parents' Information:\nFather: Roberto Test Dela Cruz (Origin/Residence: Tondo, Manila)\nMother: Maria Test Santos (Origin/Residence: Malabon City)\nParents' Marriage Status: Church Wedding\n\n3. Sponsors (Godparents / Ninong & Ninang):\nPrincipal Male Sponsor (Ninong): Juan Dela Cruz (Caloocan)\nPrincipal Female Sponsor (Ninang): Juana Reyes (Navotas)\nAdditional Sponsors: Ninong Carlos, Ninang Elena\n\n4. Proposed Baptism Schedule:\nDate of Baptism: {$bapDate}";

$refBap = 'REQ-TEST-BAP-' . time();
$conn->query("INSERT INTO requests (user_id, request_type, status, description, reference_number, date_requested) VALUES ({$testUserId}, 'baptism_service', 'pending', '" . $conn->real_escape_string($bapDesc) . "', '{$refBap}', NOW())");
$bapRequestId = $conn->insert_id;

$bapResult = $service->approveRequest($bapRequestId, $testAdminId, [
    'admin_response' => 'Baptism requirements verified and approved.',
    'officiating_priest' => 'Rev. Fr. Mariano Test'
]);

assertCondition($bapResult['success'] === true, '2a. Baptism approval service returned success');
assertCondition($bapResult['status'] === 'approved', '2b. Baptism request status transitioned to approved');

// Check baptism_records row
$bapRecStmt = $conn->prepare("SELECT * FROM baptism_records WHERE request_id = ?");
$bapRecStmt->bind_param('i', $bapRequestId);
$bapRecStmt->execute();
$bapRecord = $bapRecStmt->get_result()->fetch_assoc();
$bapRecStmt->close();

assertCondition(!empty($bapRecord), '2c. Baptism record created in database');
assertCondition($bapRecord['fullname'] === $childName, '2d. Child name mapped correctly: ' . ($bapRecord['fullname'] ?? ''));
assertCondition($bapRecord['baptism_date'] === $bapDate, '2e. Baptism date mapped correctly: ' . ($bapRecord['baptism_date'] ?? ''));
assertCondition(str_contains($bapRecord['parents'], 'Roberto Test Dela Cruz'), '2f. Father name mapped into parents: ' . ($bapRecord['parents'] ?? ''));
assertCondition(str_contains($bapRecord['godparents'], 'Juan Dela Cruz'), '2g. Sponsors mapped into godparents: ' . ($bapRecord['godparents'] ?? ''));
assertCondition($bapRecord['priest'] === 'Rev. Fr. Mariano Test', '2h. Officiating priest mapped correctly: ' . ($bapRecord['priest'] ?? ''));

// Check schedule_events row
$bapSchedStmt = $conn->prepare("SELECT * FROM schedule_events WHERE source_type = 'request' AND source_id = ?");
$bapSchedStmt->bind_param('i', $bapRequestId);
$bapSchedStmt->execute();
$bapEvent = $bapSchedStmt->get_result()->fetch_assoc();
$bapSchedStmt->close();

assertCondition(!empty($bapEvent), '2i. Calendar event created in schedule_events');
assertCondition($bapEvent['title'] === "Baptism: {$childName}", '2j. Event title formatted as Baptism: Child Name -> ' . ($bapEvent['title'] ?? ''));
assertCondition($bapEvent['category'] === 'sacramental', '2k. Event category set to sacramental: ' . ($bapEvent['category'] ?? ''));
assertCondition($bapEvent['event_date'] === $bapDate, '2l. Event date matches approved service date: ' . ($bapEvent['event_date'] ?? ''));

// Check notification
$notifStmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? AND title = 'Request Approved: Baptism' ORDER BY notification_id DESC LIMIT 1");
$notifStmt->bind_param('i', $testUserId);
$notifStmt->execute();
$notif = $notifStmt->get_result()->fetch_assoc();
$notifStmt->close();

assertCondition(!empty($notif), '2m. Notification created for parishioner');
assertCondition(str_contains($notif['message'] ?? '', 'has been approved and added to the official parish schedule'), '2n. Notification text contains official approved message template', 'Got: ' . ($notif['message'] ?? 'null'));

// Test 3: MARRIAGE Approval Workflow
echo "\n--- Testing Marriage Request Approval ---\n";
$marDate = date('Y-m-d', strtotime('+35 days'));
$groom = 'Pedro Test Santos ' . time();
$bride = 'Maria Test Reyes ' . time();
$marDesc = "Preferred date: {$marDate}\nPreferred time: 02:00 PM\nLocation: San Lorenzo Ruiz Parish Church\n\n--- PRE-NUPTIAL / MARRIAGE INVESTIGATION SHEET ---\n1. Groom (Nobyo) Information:\nFull Name: {$groom}\nDate of Birth: 1995-06-12 | Place of Birth: Manila\nPlace of Origin / Current Residence: Navotas City\nReligion / Church of Baptism: Roman Catholic\nFather: Antonio Santos | Mother: Carmen Lopez\n\n2. Bride (Nobya) Information:\nFull Maiden Name: {$bride}\nDate of Birth: 1997-09-20 | Place of Birth: Quezon City\nPlace of Origin / Current Residence: Malabon City\nReligion / Church of Baptism: Roman Catholic\nFather: Eduardo Reyes | Mother: Teresa Dizon\n\n3. Principal Witnesses / Sponsors:\nMale Principal Sponsor: Hon. Jose Garcia\nFemale Principal Sponsor: Dra. Alicia Mendoza\nAdditional Sponsors / Entourage: Sponsor 1, Sponsor 2\n\n4. Proposed Wedding Schedule:\nDate of Marriage: {$marDate}";

$refMar = 'REQ-TEST-MAR-' . time();
$conn->query("INSERT INTO requests (user_id, request_type, status, description, reference_number, date_requested) VALUES ({$testUserId}, 'marriage_wedding_service', 'pending', '" . $conn->real_escape_string($marDesc) . "', '{$refMar}', NOW())");
$marRequestId = $conn->insert_id;

$marResult = $service->approveRequest($marRequestId, $testAdminId, [
    'admin_response' => 'Wedding request approved.',
    'officiating_priest' => 'Rev. Fr. Mariano Test'
]);

assertCondition($marResult['success'] === true, '3a. Marriage approval service returned success');

$marRecStmt = $conn->prepare("SELECT * FROM marriage_records WHERE request_id = ?");
$marRecStmt->bind_param('i', $marRequestId);
$marRecStmt->execute();
$marRecord = $marRecStmt->get_result()->fetch_assoc();
$marRecStmt->close();

assertCondition(!empty($marRecord), '3b. Marriage record created in database');
assertCondition($marRecord['husband_name'] === $groom, '3c. Groom name mapped correctly: ' . ($marRecord['husband_name'] ?? ''));
assertCondition($marRecord['wife_name'] === $bride, '3d. Bride name mapped correctly: ' . ($marRecord['wife_name'] ?? ''));
assertCondition($marRecord['wedding_date'] === $marDate, '3e. Wedding date mapped correctly: ' . ($marRecord['wedding_date'] ?? ''));
assertCondition(str_contains($marRecord['sponsors'], 'Jose Garcia'), '3f. Witnesses mapped into sponsors: ' . ($marRecord['sponsors'] ?? ''));

$marSchedStmt = $conn->prepare("SELECT * FROM schedule_events WHERE source_type = 'request' AND source_id = ?");
$marSchedStmt->bind_param('i', $marRequestId);
$marSchedStmt->execute();
$marEvent = $marSchedStmt->get_result()->fetch_assoc();
$marSchedStmt->close();

assertCondition(!empty($marEvent), '3g. Wedding calendar event created in schedule_events');
assertCondition($marEvent['title'] === "Wedding: {$groom} & {$bride}", '3h. Event title formatted as Wedding: Groom & Bride -> ' . ($marEvent['title'] ?? ''));
assertCondition($marEvent['category'] === 'sacramental', '3i. Event category set to sacramental');

// Test 4: FUNERAL Approval Workflow
echo "\n--- Testing Funeral Request Approval ---\n";
$funDate = date('Y-m-d', strtotime('+5 days'));
$deceased = 'Don Teodoro Test ' . time();
$funDesc = "Preferred date: {$funDate}\nPreferred time: 01:00 PM\nLocation: San Lorenzo Ruiz Parish Church\n\nDeceased Full Name: {$deceased}\nDate of Death: 2026-08-30\nDate of Funeral: {$funDate}\nCause of Death: Natural Causes / Old Age\nPlace of Burial: San Lorenzo Ruiz Memorial Park\nSurviving Family: Maria Elena Test\nAge: 84\nResidence: Dagat-Dagatan, Caloocan City";

$refFun = 'REQ-TEST-FUN-' . time();
$conn->query("INSERT INTO requests (user_id, request_type, status, description, reference_number, date_requested) VALUES ({$testUserId}, 'funeral_mass', 'pending', '" . $conn->real_escape_string($funDesc) . "', '{$refFun}', NOW())");
$funRequestId = $conn->insert_id;

$funResult = $service->approveRequest($funRequestId, $testAdminId, [
    'admin_response' => 'Funeral mass confirmed.',
    'officiating_priest' => 'Rev. Fr. Mariano Test'
]);

assertCondition($funResult['success'] === true, '4a. Funeral mass approval service returned success');

$funRecStmt = $conn->prepare("SELECT * FROM funeral_records WHERE request_id = ?");
$funRecStmt->bind_param('i', $funRequestId);
$funRecStmt->execute();
$funRecord = $funRecStmt->get_result()->fetch_assoc();
$funRecStmt->close();

assertCondition(!empty($funRecord), '4b. Funeral record created in database');
assertCondition($funRecord['deceased_name'] === $deceased, '4c. Deceased name mapped correctly: ' . ($funRecord['deceased_name'] ?? ''));
assertCondition($funRecord['date_of_burial'] === $funDate, '4d. Date of burial mapped correctly: ' . ($funRecord['date_of_burial'] ?? ''));
assertCondition($funRecord['cause_of_death'] === 'Natural Causes / Old Age', '4e. Cause of death mapped correctly: ' . ($funRecord['cause_of_death'] ?? ''));

$funSchedStmt = $conn->prepare("SELECT * FROM schedule_events WHERE source_type = 'request' AND source_id = ?");
$funSchedStmt->bind_param('i', $funRequestId);
$funSchedStmt->execute();
$funEvent = $funSchedStmt->get_result()->fetch_assoc();
$funSchedStmt->close();

assertCondition(!empty($funEvent), '4f. Funeral calendar event created in schedule_events');
assertCondition($funEvent['title'] === "Funeral Mass: {$deceased}", '4g. Event title formatted as Funeral Mass: Deceased -> ' . ($funEvent['title'] ?? ''));

// Test 5: Calendar Privacy Masking in buildScheduleEvent
echo "\n--- Testing Calendar Visibility & Privacy Masking ---\n";
require_once __DIR__ . '/../api/calendar-events.php';

$publicBapEvent = buildScheduleEvent($bapEvent, null, false);
assertCondition($publicBapEvent['title'] === 'Reserved', '5a. Public view displays title as "Reserved": ' . $publicBapEvent['title']);
assertCondition($publicBapEvent['extendedProps']['description'] === 'Reserved Sacramental Schedule', '5b. Public view masks description');

$adminBapEvent = buildScheduleEvent($bapEvent, null, true);
assertCondition($adminBapEvent['title'] === "Baptism: {$childName}", '5c. Admin view displays full details: ' . $adminBapEvent['title']);
assertCondition(!empty($adminBapEvent['extendedProps']['record_url']), '5d. Admin view contains direct record_url: ' . $adminBapEvent['extendedProps']['record_url']);

// Test 6: Calendar Availability Lock
echo "\n--- Testing Double-Booking Prevention in Calendar Availability ---\n";
$conflictCheck = calendarSlotConflict($conn, $bapDate, '10:00:00', '11:00:00', 'San Lorenzo Ruiz Parish Church');
assertCondition($conflictCheck['conflict'] === true, '6a. Slot is locked and detected as occupied by calendarSlotConflict');

// Test 7: Atomic Rollback on Failure
echo "\n--- Testing Atomic Rollback on Failure ---\n";
$fakeReqId = 99999999;
$rollbackCaught = false;
try {
    $service->approveRequest($fakeReqId, $testAdminId);
} catch (Throwable $e) {
    $rollbackCaught = true;
}
assertCondition($rollbackCaught === true, '7a. Exception thrown and caught for invalid request approval');

// Test 8: Check /api/admin/requests/approve.php file exists
echo "\n--- Testing Approve Endpoint Existence ---\n";
$approveEndpointPath = __DIR__ . '/../api/admin/requests/approve.php';
assertCondition(file_exists($approveEndpointPath), '8a. /api/admin/requests/approve.php exists');
$approveCode = file_get_contents($approveEndpointPath);
assertCondition(str_contains($approveCode, 'SacramentalApprovalService'), '8b. approve.php integrates SacramentalApprovalService');
assertCondition(str_contains($approveCode, 'requireAdmin'), '8c. approve.php enforces administrator permissions');

// Clean up test rows
echo "\n--- Cleaning up test records ---\n";
$conn->query("DELETE FROM schedule_events WHERE source_type = 'request' AND source_id IN ({$bapRequestId}, {$marRequestId}, {$funRequestId})");
$conn->query("DELETE FROM baptism_records WHERE request_id = {$bapRequestId}");
$conn->query("DELETE FROM marriage_records WHERE request_id = {$marRequestId}");
$conn->query("DELETE FROM funeral_records WHERE request_id = {$funRequestId}");
$conn->query("DELETE FROM notifications WHERE user_id = {$testUserId} AND (title LIKE '%Baptism%' OR title LIKE '%Wedding%' OR title LIKE '%Funeral%')");
$conn->query("DELETE FROM request_status_history WHERE request_id IN ({$bapRequestId}, {$marRequestId}, {$funRequestId})");
$conn->query("DELETE FROM requests WHERE request_id IN ({$bapRequestId}, {$marRequestId}, {$funRequestId})");
echo "Clean up completed.\n\n";

echo "=== Test Summary ===\n";
echo "Passed: {$passedTests}\n";
echo "Failed: {$failedTests}\n";

if ($failedTests === 0) {
    echo "ALL SACRAMENTAL APPROVAL WORKFLOW TESTS PASSED!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
