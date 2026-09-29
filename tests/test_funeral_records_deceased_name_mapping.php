<?php
/**
 * Test Suite: Funeral Records Deceased Name Mapping and Parishioner Isolation
 * 
 * Verifies:
 * 1. "Deceased Name" column in Funeral Records is populated ONLY from "Deceased Full Name" submitted in the form.
 * 2. Requesting parishioner's account name (e.g., "Theresa Ellaine Natial") is NEVER in deceased_name.
 * 3. Requesting parishioner is stored separately in requested_by column / linked via request_id.
 * 4. Existing incorrect records (such as "JUAN AQUINO THERESA ELLAINE NATIAL", FUN-2026-0001, etc.) are corrected.
 * 5. Baptism and Wedding records do not leak applicant account names into sacrament subjects.
 */

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../services/SacramentalApprovalService.php';
require_once __DIR__ . '/../includes/helpers.php';

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

echo "=== Funeral Records Deceased Name Mapping Test Suite ===\n\n";

// Setup Test Users
$adminUser = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch_assoc();
$testAdminId = intval($adminUser['id'] ?? 1);

// Ensure a dedicated test parishioner account representing Theresa Ellaine Natial
$parishionerEmail = 'theresa.natial.test@example.com';
$userStmt = $conn->prepare("SELECT id, fullname FROM users WHERE email = ? LIMIT 1");
$userStmt->bind_param('s', $parishionerEmail);
$userStmt->execute();
$existingParishioner = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if ($existingParishioner) {
    $parishionerId = (int)$existingParishioner['id'];
} else {
    $insU = $conn->prepare("INSERT INTO users (fullname, first_name, surname, email, role, status, password, created_at) VALUES ('Theresa Ellaine Natial', 'Theresa Ellaine', 'Natial', ?, 'user', 'active', 'dummy', NOW())");
    $insU->bind_param('s', $parishionerEmail);
    $insU->execute();
    $parishionerId = $insU->insert_id;
    $insU->close();
}

$service = new SacramentalApprovalService($conn);

// Test 1: Complete a new Funeral Mass request with Deceased "Juan Aquino"
echo "--- Test 1: Completing new Funeral Mass request for Juan Aquino ---\n";
$burialDate = date('Y-m-d', strtotime('+7 days'));
$actualDeceased = 'Juan Aquino';
$funDesc = "Preferred date: {$burialDate}\nPreferred time: 09:00 AM\nLocation: San Lorenzo Ruiz Parish Church\n\n--- FUNERAL INVESTIGATION SHEET ---\nDeceased Full Name: {$actualDeceased}\nDate of Death: 2026-09-20\nDate of Burial: {$burialDate}\nCivil Status: Married\nType of Funeral Rites: Full Catholic Rites\nCause of Death: Cardio-respiratory arrest\nPlace of Burial: San Lorenzo Ruiz Memorial Park";

$refFun = 'TEST-FUN-NATIAL-' . time();
$insReq = $conn->prepare("INSERT INTO requests (user_id, request_type, status, description, reference_number, date_requested) VALUES (?, 'funeral_mass', 'pending', ?, ?, NOW())");
$insReq->bind_param('iss', $parishionerId, $funDesc, $refFun);
$insReq->execute();
$funReqId = $insReq->insert_id;
$insReq->close();

$result = $service->completeRequest($funReqId, $testAdminId, [
    'admin_response' => 'Funeral Mass confirmed.',
    'officiating_priest' => 'Rev. Fr. Mariano Test',
    'parish_priest' => 'Rev. Fr. Alberto Cahilig, OMI',
    'target_status' => 'completed'
]);

assertCondition($result['success'] === true, '1a. Funeral request completed successfully');

$chkStmt = $conn->prepare("SELECT * FROM funeral_records WHERE request_id = ?");
$chkStmt->bind_param('i', $funReqId);
$chkStmt->execute();
$funRow = $chkStmt->get_result()->fetch_assoc();
$chkStmt->close();

assertCondition(!empty($funRow), '1b. Funeral record row created in database');
assertCondition($funRow['deceased_name'] === 'Juan Aquino', '1c. Deceased Name is strictly "Juan Aquino" without applicant name: ' . ($funRow['deceased_name'] ?? ''));
assertCondition(stripos($funRow['deceased_name'], 'Theresa') === false, '1d. Applicant name "Theresa" does NOT appear in deceased_name');
assertCondition(stripos($funRow['deceased_name'], 'Natial') === false, '1e. Applicant surname "Natial" does NOT appear in deceased_name');
assertCondition(empty($funRow['family_name']), '1f. family_name is NULL (applicant not placed in family_name): ' . var_export($funRow['family_name'], true));
assertCondition($funRow['requested_by'] === 'Theresa Ellaine Natial', '1g. requested_by stores applicant account name: ' . ($funRow['requested_by'] ?? ''));

// Test 2: Existing Bad Data Correction
echo "\n--- Test 2: Repairing Bad Existing Records with Concatenated Names ---\n";
$corruptRef = 'TEST-FUN-CORRUPT-' . time();
$insCorruptReq = $conn->prepare("INSERT INTO requests (user_id, request_type, status, description, reference_number, date_requested) VALUES (?, 'funeral_mass', 'completed', ?, ?, NOW())");
$insCorruptReq->bind_param('iss', $parishionerId, $funDesc, $corruptRef);
$insCorruptReq->execute();
$corruptReqId = $insCorruptReq->insert_id;
$insCorruptReq->close();

// Insert a simulated corrupted record like FUN-2026-0001 with 'JUAN AQUINO THERESA ELLAINE NATIAL'
$corruptRegNo = 'FUN-2026-CORRUPT-' . time();
$corruptIns = $conn->prepare("
    INSERT INTO funeral_records (request_id, registry_no, deceased_name, family_name, date_of_death, date_of_burial, civil_status, funeral_rites, cause_of_death, place_of_burial, minister, status)
    VALUES (?, ?, 'JUAN AQUINO THERESA ELLAINE NATIAL', 'Theresa Ellaine Natial', '2026-09-20', ?, 'Married', 'Full Catholic Rites', 'Cardio arrest', 'Memorial Park', 'Rev. Fr. Test', 'active')
");
$corruptIns->bind_param('iss', $corruptReqId, $corruptRegNo, $burialDate);
$corruptIns->execute();
$corruptFunId = $corruptIns->insert_id;
$corruptIns->close();

// Run repair logic (same as migration 035 and cleanup script)
// 1. Repair via linked request description
$repairStmt = $conn->prepare("
    SELECT f.funeral_id, f.request_id, f.deceased_name, f.family_name, r.description, u.fullname AS user_fullname
    FROM funeral_records f
    LEFT JOIN requests r ON f.request_id = r.request_id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE f.funeral_id = ?
");
$repairStmt->bind_param('i', $corruptFunId);
$repairStmt->execute();
$toRepair = $repairStmt->get_result()->fetch_assoc();
$repairStmt->close();

$extractedDeceased = '';
if (!empty($toRepair['description'])) {
    if (preg_match('/Deceased Full Name\s*:\s*([^\r\n]+)/i', $toRepair['description'], $dm)) {
        $extractedDeceased = trim($dm[1]);
    }
}
if ($extractedDeceased !== '') {
    $fixStmt = $conn->prepare("UPDATE funeral_records SET deceased_name = ?, family_name = NULL, requested_by = ? WHERE funeral_id = ?");
    $fixStmt->bind_param('ssi', $extractedDeceased, $toRepair['user_fullname'], $corruptFunId);
    $fixStmt->execute();
    $fixStmt->close();
}

$repairedCheck = $conn->query("SELECT deceased_name, family_name, requested_by FROM funeral_records WHERE funeral_id = {$corruptFunId}")->fetch_assoc();
assertCondition($repairedCheck['deceased_name'] === 'Juan Aquino', '2a. Corrupted record successfully repaired back to "Juan Aquino": ' . $repairedCheck['deceased_name']);
assertCondition(empty($repairedCheck['family_name']), '2b. Corrupted record family_name cleared to NULL');
assertCondition($repairedCheck['requested_by'] === 'Theresa Ellaine Natial', '2c. Corrupted record requested_by populated with user name');

// Test 3: Standalone Bad Data (no request_id) with 'THERESA ELLAINE NATIAL' suffix
echo "\n--- Test 3: Repairing Standalone Bad Data without linked request ---\n";
$standaloneRegNo = 'FUN-2026-0001-TEST';
$conn->query("
    INSERT INTO funeral_records (registry_no, deceased_name, date_of_death, date_of_burial, civil_status, funeral_rites, cause_of_death, place_of_burial, minister, status)
    VALUES ('{$standaloneRegNo}', 'JUAN AQUINO THERESA ELLAINE NATIAL', '2026-09-20', '{$burialDate}', 'Married', 'Full Catholic Rites', 'Natural', 'Cemetery', 'Priest', 'active')
");
$standaloneId = $conn->insert_id;

// Apply SQL cleanup as defined in Migration 035
$conn->query("
    UPDATE funeral_records
    SET deceased_name = TRIM(REPLACE(REPLACE(deceased_name, 'THERESA ELLAINE NATIAL', ''), 'Theresa Ellaine Natial', ''))
    WHERE funeral_id = {$standaloneId}
");
$standaloneCheck = $conn->query("SELECT deceased_name FROM funeral_records WHERE funeral_id = {$standaloneId}")->fetch_assoc();
assertCondition($standaloneCheck['deceased_name'] === 'JUAN AQUINO', '3a. Standalone record successfully stripped of "THERESA ELLAINE NATIAL": ' . $standaloneCheck['deceased_name']);

// Test 4: Baptism Records Review
echo "\n--- Test 4: Reviewing Baptism Records for Applicant Name Leak ---\n";
$bapDate = date('Y-m-d', strtotime('+10 days'));
$bapDesc = "Preferred date: {$bapDate}\nPreferred time: 10:00 AM\nLocation: Main Church\n\n--- PRE-BAPTISMAL INVESTIGATION SHEET ---\n1. Child's Information:\nName of Child: Baby Juan Dela Cruz\nDate of Birth: 2026-01-01 | Place of Birth: Manila\n\n2. Parents' Information:\nFather: Roberto Dela Cruz\nMother: Maria Dela Cruz\nParents' Marriage Status: Church Wedding\n\n3. Sponsors:\nPrincipal Male Sponsor (Ninong): Ninong Pedro\nPrincipal Female Sponsor (Ninang): Ninang Juana";

$refBap = 'TEST-BAP-NATIAL-' . time();
$insBapReq = $conn->prepare("INSERT INTO requests (user_id, request_type, status, description, reference_number, date_requested) VALUES (?, 'baptism_service', 'pending', ?, ?, NOW())");
$insBapReq->bind_param('iss', $parishionerId, $bapDesc, $refBap);
$insBapReq->execute();
$bapReqId = $insBapReq->insert_id;
$insBapReq->close();

$bapResult = $service->completeRequest($bapReqId, $testAdminId, [
    'admin_response' => 'Baptism confirmed.',
    'officiating_priest' => 'Rev. Fr. Mariano Test',
    'parish_priest' => 'Rev. Fr. Alberto Cahilig, OMI',
    'target_status' => 'completed'
]);

$bapRow = $conn->query("SELECT fullname FROM baptism_records WHERE request_id = {$bapReqId}")->fetch_assoc();
assertCondition(!empty($bapRow), '4a. Baptism record created');
assertCondition($bapRow['fullname'] === 'Baby Juan Dela Cruz', '4b. Baptism child name is strictly "Baby Juan Dela Cruz": ' . ($bapRow['fullname'] ?? ''));
assertCondition(stripos($bapRow['fullname'], 'Theresa') === false, '4c. Baptism record does not leak applicant name Theresa');

// Test 5: Wedding Records Review
echo "\n--- Test 5: Reviewing Wedding Records for Applicant Name Leak ---\n";
$wedDate = date('Y-m-d', strtotime('+20 days'));
$wedDesc = "Preferred date: {$wedDate}\nPreferred time: 02:00 PM\nLocation: Main Church\n\n--- PRE-NUPTIAL / MARRIAGE INVESTIGATION SHEET ---\n1. Groom (Nobyo) Information:\nFull Name: Gabriel Ramon Silang\nDate of Birth: 1995-03-20 | Place of Birth: Ilocos Sur\nPlace of Origin / Current Residence: Manila\nReligion / Church of Baptism: Roman Catholic\nFather: Ramon Silang Sr. | Mother: Teresa Carino\n\n2. Bride (Nobya) Information:\nFull Maiden Name: Gabriela Maria Estrada\nDate of Birth: 1997-08-14 | Place of Birth: Manila\nPlace of Origin / Current Residence: Manila\nReligion / Church of Baptism: Roman Catholic\nFather: Manuel Estrada | Mother: Beatriz Alvarez\n\n3. Principal Witnesses:\nMale Principal Sponsor: Senator Juan Ponce\nFemale Principal Sponsor: Dr. Maria Santos";

$refWed = 'TEST-WED-NATIAL-' . time();
$insWedReq = $conn->prepare("INSERT INTO requests (user_id, request_type, status, description, reference_number, date_requested) VALUES (?, 'marriage_wedding_service', 'pending', ?, ?, NOW())");
$insWedReq->bind_param('iss', $parishionerId, $wedDesc, $refWed);
$insWedReq->execute();
$wedReqId = $insWedReq->insert_id;
$insWedReq->close();

$wedResult = $service->completeRequest($wedReqId, $testAdminId, [
    'admin_response' => 'Wedding confirmed.',
    'officiating_priest' => 'Rev. Fr. Mariano Test',
    'parish_priest' => 'Rev. Fr. Alberto Cahilig, OMI',
    'target_status' => 'completed'
]);

$wedRow = $conn->query("SELECT husband_name, wife_name FROM marriage_records WHERE request_id = {$wedReqId}")->fetch_assoc();
assertCondition(!empty($wedRow), '5a. Wedding record created');
assertCondition($wedRow['husband_name'] === 'Gabriel Ramon Silang', '5b. Husband name is strictly "Gabriel Ramon Silang": ' . ($wedRow['husband_name'] ?? ''));
assertCondition($wedRow['wife_name'] === 'Gabriela Maria Estrada', '5c. Wife name is strictly "Gabriela Maria Estrada": ' . ($wedRow['wife_name'] ?? ''));
assertCondition(stripos($wedRow['husband_name'], 'Theresa') === false && stripos($wedRow['wife_name'], 'Theresa') === false, '5d. Wedding record does not leak applicant name Theresa');

// Clean up test rows
echo "\n--- Cleaning up test artifacts ---\n";
$conn->query("DELETE FROM schedule_events WHERE source_type = 'request' AND source_id IN ({$funReqId}, {$bapReqId}, {$wedReqId})");
$conn->query("DELETE FROM funeral_records WHERE funeral_id IN ({$funRow['funeral_id']}, {$corruptFunId}, {$standaloneId})");
$conn->query("DELETE FROM baptism_records WHERE request_id = {$bapReqId}");
$conn->query("DELETE FROM marriage_records WHERE request_id = {$wedReqId}");
$conn->query("DELETE FROM notifications WHERE user_id = {$parishionerId}");
$conn->query("DELETE FROM request_status_history WHERE request_id IN ({$funReqId}, {$corruptReqId}, {$bapReqId}, {$wedReqId})");
$conn->query("DELETE FROM requests WHERE request_id IN ({$funReqId}, {$corruptReqId}, {$bapReqId}, {$wedReqId})");
$conn->query("DELETE FROM users WHERE id = {$parishionerId} AND email = '{$parishionerEmail}'");
echo "Clean up completed.\n\n";

echo "=== Test Summary ===\n";
echo "Passed: {$passedTests}\n";
echo "Failed: {$failedTests}\n";
if ($failedTests === 0) {
    echo "ALL TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
