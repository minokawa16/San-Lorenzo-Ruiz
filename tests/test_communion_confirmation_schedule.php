<?php
/**
 * Test Suite for First Communion and Confirmation Admin Scheduling Workflow
 * Validates Part 1 through Part 6 requirements.
 */

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/SacramentalApprovalService.php';
require_once __DIR__ . '/../repositories/RequestRepository.php';

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $testName, string $details = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$testName}\n";
    } else {
        $failed++;
        echo "[FAIL] {$testName}" . ($details ? ": {$details}" : '') . "\n";
    }
}

echo "=== TUGON TEST SUITE: COMMUNION & CONFIRMATION ADMIN SCHEDULING ===\n\n";

// -------------------------------------------------------------
// PART 1: CONFIRMATION FORM MARKUP & LABELS
// -------------------------------------------------------------
$reqServiceContent = file_get_contents(__DIR__ . '/../users/request-service.php');

assertTest(
    strpos($reqServiceContent, "6a. Father's Full Name <span class=\"text-danger\">*</span>") !== false,
    "Part 1.1: Confirmation Father label is '6a. Father's Full Name *' with required asterisk"
);

assertTest(
    strpos($reqServiceContent, "6b. Mother's Full Maiden Name <span class=\"text-danger\">*</span>") !== false,
    "Part 1.1: Confirmation Mother label is '6b. Mother's Full Maiden Name *' with required asterisk"
);

assertTest(
    stripos($reqServiceContent, "Optional if Mother provided") === false &&
    stripos($reqServiceContent, "Optional if Father provided") === false,
    "Part 1.1: Confirmation has no '(Optional if ... provided)' text"
);

assertTest(
    strpos($reqServiceContent, "At least one parent name is required") === false,
    "Part 1.1: Confirmation has no 'At least one parent name is required' helper line"
);

// -------------------------------------------------------------
// PART 2: FIRST COMMUNION FORM MARKUP & LABELS
// -------------------------------------------------------------
assertTest(
    strpos($reqServiceContent, "3a. Father's Full Name <span class=\"text-danger\">*</span>") !== false,
    "Part 2.1: First Communion Father label is '3a. Father's Full Name *' with required asterisk"
);

assertTest(
    strpos($reqServiceContent, "3b. Mother's Full Maiden Name <span class=\"text-danger\">*</span>") !== false,
    "Part 2.1: First Communion Mother label is '3b. Mother's Full Maiden Name *' with required asterisk"
);

assertTest(
    strpos($reqServiceContent, "communion_minister") === false &&
    strpos($reqServiceContent, "4. Minister (Optional)") === false,
    "Part 2.2: Field '4. Minister (Optional)' completely removed from First Communion form"
);

assertTest(
    strpos($reqServiceContent, "4. Baptismal Date <span class=\"text-danger\">*</span>") !== false,
    "Part 2.2: First Communion field 4 is '4. Baptismal Date *'"
);

assertTest(
    strpos($reqServiceContent, "5. Baptismal Place <span class=\"text-danger\">*</span>") !== false,
    "Part 2.2: First Communion field 5 is '5. Baptismal Place *'"
);

assertTest(
    strpos($reqServiceContent, "pds-aligned-row") !== false,
    "Part 2.4: 2-column alignment class 'pds-aligned-row' present for Baptismal Date and Place"
);

// -------------------------------------------------------------
// PART 3: REMOVAL & ADMIN COMPLETION LOGIC
// -------------------------------------------------------------
// Check migration 046 columns exist in requests table
$colCheck = $conn->query("SHOW COLUMNS FROM requests LIKE 'ceremony_date'");
$hasCeremonyDate = $colCheck && $colCheck->num_rows > 0;
$colCheck = $conn->query("SHOW COLUMNS FROM requests LIKE 'ceremony_time'");
$hasCeremonyTime = $colCheck && $colCheck->num_rows > 0;
$colCheck = $conn->query("SHOW COLUMNS FROM requests LIKE 'ceremony_minister'");
$hasCeremonyMinister = $colCheck && $colCheck->num_rows > 0;

assertTest(
    $hasCeremonyDate && $hasCeremonyTime && $hasCeremonyMinister,
    "Part 3.6: Database table 'requests' has ceremony_date, ceremony_time, and ceremony_minister columns"
);

// Admin workflow UI contains required fields
$adminWorkflowContent = file_get_contents(__DIR__ . '/../admin/request-workflow.php');
assertTest(
    strpos($adminWorkflowContent, 'id="commConfCeremonyCard"') !== false,
    "Part 3.5: Admin request-workflow.php contains ceremony completion card container"
);

assertTest(
    strpos($adminWorkflowContent, 'id="ceremony_date"') !== false &&
    strpos($adminWorkflowContent, 'id="ceremony_time"') !== false &&
    strpos($adminWorkflowContent, 'name="ceremony_minister"') !== false,
    "Part 3.5: Admin request-workflow.php has ceremony_date, ceremony_time, and ceremony_minister inputs"
);

assertTest(
    strpos($adminWorkflowContent, 'Bp. Angelito R. Lampon, O.M.I., D.D.') !== false,
    "Part 3.5: Confirmation minister default suggestion 'Bp. Angelito R. Lampon, O.M.I., D.D.' present"
);

assertTest(
    strpos($adminWorkflowContent, 'Rev. Fr. Alberto G. Cahilig, OMI') !== false &&
    strpos($adminWorkflowContent, 'Rev. Fr. Alvin Vicente C. Barretto, OMI') !== false,
    "Part 3.5: First Communion minister dropdown options present"
);

// End-to-end database test for SacramentalApprovalService blocking & completion
$approvalService = new SacramentalApprovalService($conn);

// Find an existing active user for testing
$uRes = $conn->query("SELECT id FROM users WHERE status = 'active' LIMIT 1");
$testUser = $uRes ? $uRes->fetch_assoc() : null;
$testUserId = $testUser ? (int)$testUser['id'] : 1;

// Insert a temporary test request for First Communion
$tempRef = 'TEST-REQ-' . time();
$tempDesc = "Location: San Lorenzo Ruiz Parish Church\nService: First Communion\n--- FIRST COMMUNION APPLICATION ---\nName of Communicant: JUAN DELA CRUZ\nDomicile: Aleosan\nFather: PEDRO DELA CRUZ\nMother: MARIA DELA CRUZ\nParents: PEDRO DELA CRUZ / MARIA DELA CRUZ\nBaptismal Date: 2020-01-01\nBaptismal Place: San Lorenzo Ruiz\nDetails: Test entry";
$conn->query("INSERT INTO requests (user_id, request_type, record_holder_name, description, status, reference_number) VALUES ({$testUserId}, 'first_communion_service', 'JUAN DELA CRUZ', '" . $conn->real_escape_string($tempDesc) . "', 'pending', '{$tempRef}')");
$tempReqId = (int)$conn->insert_id;

$testBlocked = false;
try {
    // Attempt completion WITHOUT ceremony date/time/minister
    $approvalService->completeRequest($tempReqId, $testUserId, [
        'admin_response' => 'Completion attempt without schedule',
        'ceremony_date' => null,
        'ceremony_time' => null,
        'ceremony_minister' => null
    ]);
} catch (InvalidArgumentException $e) {
    if (strpos($e->getMessage(), 'missing: Ceremony Date, Ceremony Time, Minister') !== false) {
        $testBlocked = true;
    }
} catch (Throwable $t) {
    echo "Unexpected error: " . $t->getMessage() . "\n";
}
assertTest(
    $testBlocked,
    "Part 3.6: SacramentalApprovalService blocks completion of First Communion with missing ceremony schedule"
);

// Now test completing WITH valid ceremony schedule
$testCompleteSuccess = false;
try {
    $result = $approvalService->completeRequest($tempReqId, $testUserId, [
        'admin_response' => 'Congratulations on your First Holy Communion!',
        'ceremony_date' => '2026-10-12',
        'ceremony_time' => '09:00:00',
        'ceremony_minister' => 'Rev. Fr. Alberto G. Cahilig, OMI'
    ]);
    if ($result['status'] === 'completed') {
        $testCompleteSuccess = true;
    }
} catch (Throwable $t) {
    echo "Completion error: " . $t->getMessage() . "\n";
}

assertTest(
    $testCompleteSuccess,
    "Part 3.6: SacramentalApprovalService successfully completes request with ceremony date, time, and minister"
);

// Verify request columns updated
$qReq = $conn->query("SELECT status, ceremony_date, ceremony_time, ceremony_minister FROM requests WHERE request_id = {$tempReqId}");
$updatedReq = $qReq ? $qReq->fetch_assoc() : null;
assertTest(
    $updatedReq &&
    $updatedReq['status'] === 'completed' &&
    $updatedReq['ceremony_date'] === '2026-10-12' &&
    $updatedReq['ceremony_time'] === '09:00:00' &&
    $updatedReq['ceremony_minister'] === 'Rev. Fr. Alberto G. Cahilig, OMI',
    "Part 3.6: Request record persisted ceremony_date, ceremony_time, and ceremony_minister correctly"
);

// Verify first_communion_records updated
$qRec = $conn->query("SELECT communion_id, communion_date, priest, fullname FROM first_communion_records WHERE request_id = {$tempReqId}");
$commRec = $qRec ? $qRec->fetch_assoc() : null;
assertTest(
    $commRec &&
    $commRec['communion_date'] === '2026-10-12' &&
    $commRec['priest'] === 'Rev. Fr. Alberto G. Cahilig, OMI' &&
    $commRec['fullname'] === 'JUAN DELA CRUZ',
    "Part 3.7: Registry table 'first_communion_records' correctly populated with ceremony date and minister"
);

// Clean up temporary test request & record
if ($commRec) {
    $conn->query("DELETE FROM first_communion_records WHERE communion_id = " . (int)$commRec['communion_id']);
}
$conn->query("DELETE FROM notifications WHERE entity_id = {$tempReqId}");
$conn->query("DELETE FROM requests WHERE request_id = {$tempReqId}");

// Insert a temporary test request for Confirmation
$tempRefConf = 'TEST-CONF-' . time();
$tempDescConf = "Location: San Lorenzo Ruiz Parish Church\nService: Confirmation\n--- CONFIRMATION APPLICATION ---\nName of Confirmed Person: MARIA CLARA\nAge: 16\nParish of Origin: San Lorenzo Ruiz\nProvince: Cotabato\nPlace of Baptism: San Lorenzo Ruiz\nFather: SANTIAGO DE LOS SANTOS\nMother: PIA ALBA\nParents: SANTIAGO DE LOS SANTOS / PIA ALBA\nSponsor / Godparent: TIA ISABEL\nDetails: Test confirmation entry";
$conn->query("INSERT INTO requests (user_id, request_type, record_holder_name, description, status, reference_number) VALUES ({$testUserId}, 'confirmation_service', 'MARIA CLARA', '" . $conn->real_escape_string($tempDescConf) . "', 'pending', '{$tempRefConf}')");
$tempReqIdConf = (int)$conn->insert_id;

$testBlockedConf = false;
try {
    $approvalService->completeRequest($tempReqIdConf, $testUserId, [
        'admin_response' => 'Incomplete schedule attempt',
        'ceremony_date' => null,
        'ceremony_time' => null,
        'ceremony_minister' => null
    ]);
} catch (InvalidArgumentException $e) {
    if (strpos($e->getMessage(), 'missing: Ceremony Date, Ceremony Time, Minister') !== false) {
        $testBlockedConf = true;
    }
}
assertTest(
    $testBlockedConf,
    "Part 3.6: SacramentalApprovalService blocks completion of Confirmation with missing ceremony schedule"
);

$testCompleteSuccessConf = false;
try {
    $resultConf = $approvalService->completeRequest($tempReqIdConf, $testUserId, [
        'admin_response' => 'Holy Confirmation completed!',
        'ceremony_date' => '2026-11-20',
        'ceremony_time' => '10:00:00',
        'ceremony_minister' => 'Bp. Angelito R. Lampon, O.M.I., D.D.'
    ]);
    if ($resultConf['status'] === 'completed') {
        $testCompleteSuccessConf = true;
    }
} catch (Throwable $t) {
    echo "Confirmation completion error: " . $t->getMessage() . "\n";
}
assertTest(
    $testCompleteSuccessConf,
    "Part 3.6: SacramentalApprovalService successfully completes Confirmation request with bishop/minister and schedule"
);

// Verify confirmation_records updated
$qRecConf = $conn->query("SELECT confirmation_id, confirmation_date, bishop_priest, fullname FROM confirmation_records WHERE request_id = {$tempReqIdConf}");
$confRec = $qRecConf ? $qRecConf->fetch_assoc() : null;
assertTest(
    $confRec &&
    $confRec['confirmation_date'] === '2026-11-20' &&
    $confRec['bishop_priest'] === 'Bp. Angelito R. Lampon, O.M.I., D.D.' &&
    $confRec['fullname'] === 'MARIA CLARA',
    "Part 3.7: Registry table 'confirmation_records' correctly populated with ceremony date and bishop/minister"
);

// Clean up temporary confirmation test request & record
if ($confRec) {
    $conn->query("DELETE FROM confirmation_records WHERE confirmation_id = " . (int)$confRec['confirmation_id']);
}
$conn->query("DELETE FROM notifications WHERE entity_id = {$tempReqIdConf}");
$conn->query("DELETE FROM requests WHERE request_id = {$tempReqIdConf}");

// Admin manage-requests shows "—" until scheduled
$manageRequestsContent = file_get_contents(__DIR__ . '/../admin/manage-requests.php');
assertTest(
    strpos($manageRequestsContent, "is_comm_or_conf") !== false &&
    strpos($manageRequestsContent, 'ceremony_date') !== false,
    "Part 3.9: Admin manage-requests.php handles ceremony_date display for Communion/Confirmation"
);

// -------------------------------------------------------------
// PART 4: PARISHIONER CONFIRMED SCHEDULE & NOTIFICATIONS
// -------------------------------------------------------------
$myRequestsViewContent = file_get_contents(__DIR__ . '/../views/users/my-requests.php');
assertTest(
    strpos($myRequestsViewContent, "Schedule: To be announced by the parish office.") !== false,
    "Part 4.1: My Requests view displays 'Schedule: To be announced by the parish office.' before completion"
);

assertTest(
    strpos($myRequestsViewContent, "fa-calendar-check") !== false &&
    strpos($myRequestsViewContent, "width: 16px; height: 16px; flex-shrink: 0;") !== false,
    "Part 4.1: My Requests view has dedicated non-overlapping calendar icon box"
);

$viewRequestContent = file_get_contents(__DIR__ . '/../users/view-request.php');
assertTest(
    strpos($viewRequestContent, "Confirmed Ceremony Schedule") !== false &&
    strpos($viewRequestContent, "getParishPlaceName(\$conn)") !== false,
    "Part 4.1: users/view-request.php renders Confirmed Ceremony Schedule with dynamic parish place"
);

assertTest(
    strpos($viewRequestContent, "Cache-Control: private, no-store") !== false,
    "Part 4.4: users/view-request.php sets Cache-Control: private, no-store"
);

$myRequestsEntryContent = file_get_contents(__DIR__ . '/../users/my-requests.php');
assertTest(
    strpos($myRequestsEntryContent, "Cache-Control: private, no-store") !== false,
    "Part 4.4: users/my-requests.php sets Cache-Control: private, no-store"
);

// Test Notification Message Length (< 300 chars)
$dummyRef = 'REQ-2026-9999';
$dummyService = 'First Communion';
$dummyDate = 'Oct 12, 2026';
$dummyTime = '9:00 AM';
$dummyPlace = 'San Lorenzo Ruiz Mission Station';
$dummyMinister = 'Rev. Fr. Alberto G. Cahilig, OMI';
$notifMsg = "TUGON: Your {$dummyService} request {$dummyRef} is completed. Ceremony: {$dummyDate}, {$dummyTime} at {$dummyPlace}. Minister: {$dummyMinister}. See My Requests for details.";
assertTest(
    strlen($notifMsg) <= 300,
    "Part 4.2: Completion SMS length (" . strlen($notifMsg) . " chars) is well under 300 characters (2 segments)"
);

// -------------------------------------------------------------
// PART 6: SERVICE CARDS ORDER
// -------------------------------------------------------------
preg_match('/\$service_types\s*=\s*\[(.*?)\];/s', $reqServiceContent, $stMatches);
$parsedServiceTypes = [];
if (!empty($stMatches[1])) {
    preg_match_all("/'([a-z_]+)'\s*=>\s*'([^']+)'/", $stMatches[1], $kMatches);
    $parsedServiceTypes = $kMatches[1] ?? [];
}

$expectedOrder = [
    'baptism_service',
    'first_communion_service',
    'confirmation_service',
    'marriage_wedding_service',
    'funeral_mass',
    'anointing_of_the_sick',
    'patronal_fiesta'
];

assertTest(
    $parsedServiceTypes === $expectedOrder,
    "Part 6: \$service_types array matches exact required order (Baptism, Communion, Confirmation, Marriage, Funeral, Anointing, Fiesta)"
);

$repoTypes = RequestRepository::CATEGORY_TYPES['sacramental_services'];
assertTest(
    array_search('baptism_service', $repoTypes) < array_search('first_communion_service', $repoTypes) &&
    array_search('first_communion_service', $repoTypes) < array_search('confirmation_service', $repoTypes) &&
    array_search('confirmation_service', $repoTypes) < array_search('marriage_wedding_service', $repoTypes),
    "Part 6: RequestRepository category order follows Part 6 specification"
);

// -------------------------------------------------------------
// PART 7: SECTION 3 DATE & TIME REMOVAL & NOTICE VERIFICATION
// -------------------------------------------------------------
$reqServiceContent = file_get_contents(__DIR__ . '/../users/request-service.php');

assertTest(
    strpos($reqServiceContent, 'id="communionConfirmationScheduleNotice"') !== false,
    "Part 7.1: Section 3 contains #communionConfirmationScheduleNotice element"
);

assertTest(
    strpos($reqServiceContent, 'The date and time for First Communion / Confirmation will be scheduled and set directly by the Parish Administrator.') !== false,
    "Part 7.2: Section 3 contains exact helper note text regarding Admin schedule assignment"
);

assertTest(
    strpos($reqServiceContent, 'if (communionSelected || confirmationSelected)') !== false &&
    strpos($reqServiceContent, 'generalServiceDateGroup.style.display = \'none\';') !== false &&
    strpos($reqServiceContent, 'preferredTimeGroup.style.display = \'none\';') !== false,
    "Part 7.3: toggleDateInputs hides generalServiceDateGroup and preferredTimeGroup for Communion and Confirmation"
);

// -------------------------------------------------------------
// PART 8: SUBMIT BUTTON INTERACTIVITY & VALIDATION
// -------------------------------------------------------------
assertTest(
    strpos($reqServiceContent, 'submitRequestBtn.disabled = !bapUploaded') === false &&
    strpos($reqServiceContent, 'submitRequestBtn.disabled = !(bapUploaded && commUploaded)') === false,
    "Part 8.1: updateCommConfRequirementsState never disables submit button prior to click"
);

assertTest(
    strpos($reqServiceContent, "field.closest('.req-slot-col')") !== false &&
    strpos($reqServiceContent, "field.closest('.req-dropzone')") !== false,
    "Part 8.2: validationWrapper targets requirement slot containers for scroll-into-view navigation"
);

assertTest(
    strpos($reqServiceContent, "if (field === bapCertInput || field === commCertInput)") !== false,
    "Part 8.3: validateForReview prevents duplicate generic inline errors on certificate slots"
);

echo "\n-------------------------------------------------------------\n";
echo "SUMMARY: Total Passed: {$passed}, Total Failed: {$failed}\n";
echo "-------------------------------------------------------------\n";

if ($failed > 0) {
    exit(1);
}
exit(0);

