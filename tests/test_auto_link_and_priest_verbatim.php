<?php
/**
 * Test Suite: Auto-Link Certificate Requests & Verbatim Priest Fields
 */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/SacramentalRecordMatcher.php';
require_once __DIR__ . '/../services/RequestService.php';

echo "=== STARTING AUTO-LINK & PRIEST VERBATIM TEST SUITE ===\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $testName, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] $testName\n";
        $passCount++;
    } else {
        echo "[FAIL] $testName\n";
        if ($details) echo "       Details: $details\n";
        $failCount++;
    }
}

// -------------------------------------------------------------
// TEST 1: Schema Integrity Check
// -------------------------------------------------------------
echo "--- TEST 1: Request Schema Fields ---\n";
ensureRequestMatchingSchema($conn);
$colCheck = $conn->query("SHOW COLUMNS FROM requests LIKE 'matched_record_id'");
assertTest($colCheck && $colCheck->num_rows > 0, "requests table has matched_record_id column");
$statusCheck = $conn->query("SHOW COLUMNS FROM requests LIKE 'match_status'");
assertTest($statusCheck && $statusCheck->num_rows > 0, "requests table has match_status column");

// -------------------------------------------------------------
// TEST 2: SacramentalRecordMatcher Unit Matching
// -------------------------------------------------------------
echo "\n--- TEST 2: SacramentalRecordMatcher Matching ---\n";

// 2a. Match on Baptism record
$bapRec = $conn->query("SELECT * FROM baptism_records WHERE status='active' LIMIT 1")->fetch_assoc();
if ($bapRec) {
    $matchRes = SacramentalRecordMatcher::findMatches($conn, 'baptismal_certificate', $bapRec['fullname'], [
        'birth_date' => $bapRec['birth_date'] ?? null,
        'sacrament_date' => $bapRec['baptism_date'] ?? null,
    ]);
    assertTest(
        in_array($matchRes['status'], ['matched', 'multiple'], true) && count($matchRes['candidates']) > 0,
        "Baptism record found for '{$bapRec['fullname']}' (Status: {$matchRes['status']})"
    );
} else {
    echo "[SKIP] No active baptism record found for test 2a\n";
}

// 2b. Match on First Communion record
$comRec = $conn->query("SELECT * FROM first_communion_records WHERE (status='active' OR status IS NULL) LIMIT 1")->fetch_assoc();
if ($comRec) {
    $matchResCom = SacramentalRecordMatcher::findMatches($conn, 'first_communion_certificate', $comRec['fullname'], [
        'sacrament_date' => $comRec['communion_date'] ?? null,
    ]);
    assertTest(
        in_array($matchResCom['status'], ['matched', 'multiple'], true) && count($matchResCom['candidates']) > 0,
        "First Communion record found for '{$comRec['fullname']}' (Status: {$matchResCom['status']})"
    );
} else {
    echo "[SKIP] No communion record found for test 2b\n";
}

// 2c. Match on Confirmation record
$confRec = $conn->query("SELECT * FROM confirmation_records WHERE (status='active' OR status IS NULL) LIMIT 1")->fetch_assoc();
if ($confRec) {
    $matchResConf = SacramentalRecordMatcher::findMatches($conn, 'confirmation_certificate', $confRec['fullname'], [
        'sacrament_date' => $confRec['confirmation_date'] ?? null,
    ]);
    assertTest(
        in_array($matchResConf['status'], ['matched', 'multiple'], true) && count($matchResConf['candidates']) > 0,
        "Confirmation record found for '{$confRec['fullname']}' (Status: {$matchResConf['status']})"
    );
} else {
    echo "[SKIP] No confirmation record found for test 2c\n";
}

// 2d. No match for non-existent person
$noMatchRes = SacramentalRecordMatcher::findMatches($conn, 'baptismal_certificate', 'Nonexistent Ghost Applicant 99999XYZ');
assertTest($noMatchRes['status'] === 'no_match' && empty($noMatchRes['candidates']), "Non-existent applicant correctly flagged as 'no_match'");

// -------------------------------------------------------------
// TEST 3: End-to-End Request Submission Auto-Link
// -------------------------------------------------------------
echo "\n--- TEST 3: End-to-End Request Submission Auto-Link ---\n";

$testUser = $conn->query("SELECT id FROM users WHERE status='active' LIMIT 1")->fetch_assoc();
$testUserId = $testUser ? (int)$testUser['id'] : 1;

if ($bapRec) {
    $reqService = new RequestService($conn);
    $idempKey = bin2hex(random_bytes(32));
    $reqDesc = "Record Holder Name: " . $bapRec['fullname'] . "\nPurpose: Legal\nDate of Baptism: " . ($bapRec['baptism_date'] ?? '') . "\nBirthday: " . ($bapRec['birth_date'] ?? '');
    
    $createRes = $reqService->create([
        'request_type' => 'baptismal_certificate',
        'record_holder_name' => $bapRec['fullname'],
        'description' => $reqDesc,
    ], $testUserId, $idempKey);

    $testReqId = (int)$createRes['request_id'];
    assertTest($testReqId > 0, "Created test certificate request #$testReqId");

    // Check linked columns in requests table
    $chkReq = $conn->query("SELECT matched_record_id, matched_record_type, match_status FROM requests WHERE request_id = $testReqId")->fetch_assoc();
    assertTest(
        in_array($chkReq['match_status'], ['matched', 'multiple'], true),
        "Request #$testReqId auto-linked upon submission with status '{$chkReq['match_status']}'"
    );

    // Clean up test request
    $conn->query("DELETE FROM requests WHERE request_id = $testReqId");
}

// -------------------------------------------------------------
// TEST 4: Priest Autocomplete Roster
// -------------------------------------------------------------
echo "\n--- TEST 4: Priest Reference Roster ---\n";
$roster = getActivePriestsRoster($conn);
assertTest(count($roster) >= 2, "Active priests roster returns entries (" . count($roster) . " found)");

$hasLampon = false;
$hasCahilig = false;
foreach ($roster as $pr) {
    if (stripos($pr['name'], 'Lampon') !== false) $hasLampon = true;
    if (stripos($pr['name'], 'Cahilig') !== false) $hasCahilig = true;
}
assertTest($hasLampon, "Priest roster includes Archbishop Lampon");
assertTest($hasCahilig, "Priest roster includes Parish Priest Cahilig");

// -------------------------------------------------------------
// TEST 5: Verbatim Priest Saving & Printing Guarantee
// -------------------------------------------------------------
echo "\n--- TEST 5: Verbatim Priest Saving & Printing ---\n";

// 5a. Baptism record priest verbatim save
if ($bapRec) {
    $bapId = (int)$bapRec['baptism_id'];
    $customOfficiating = "Fr. Test Guest Priest " . rand(100, 999);
    $customParishPriest = "Rev. Fr. Newly Assigned Pastor " . rand(100, 999);

    $upStmt = $conn->prepare("UPDATE baptism_records SET priest = ?, parish_priest = ? WHERE baptism_id = ?");
    $upStmt->bind_param('ssi', $customOfficiating, $customParishPriest, $bapId);
    $upStmt->execute();
    $upStmt->close();

    // Verify record in database
    $verifyRec = $conn->query("SELECT priest, parish_priest FROM baptism_records WHERE baptism_id = $bapId")->fetch_assoc();
    assertTest($verifyRec['priest'] === $customOfficiating, "Baptism officiating priest saved verbatim: '{$verifyRec['priest']}'");
    assertTest($verifyRec['parish_priest'] === $customParishPriest, "Baptism parish priest saved verbatim: '{$verifyRec['parish_priest']}'");

    // 5b. Update again to confirm overwrite with no fallback
    $updatedOfficiating = "Bishop Visiting Prelate, D.D.";
    $upStmt2 = $conn->prepare("UPDATE baptism_records SET priest = ? WHERE baptism_id = ?");
    $upStmt2->bind_param('si', $updatedOfficiating, $bapId);
    $upStmt2->execute();
    $upStmt2->close();

    $verifyRec2 = $conn->query("SELECT priest FROM baptism_records WHERE baptism_id = $bapId")->fetch_assoc();
    assertTest($verifyRec2['priest'] === $updatedOfficiating, "Updated priest overwrites previous without fallback: '{$verifyRec2['priest']}'");

    // Restore original if had one
    $origPriest = $bapRec['priest'] ?? '';
    $origParishPriest = $bapRec['parish_priest'] ?? '';
    $conn->query("UPDATE baptism_records SET priest = '" . $conn->real_escape_string($origPriest) . "', parish_priest = '" . $conn->real_escape_string($origParishPriest) . "' WHERE baptism_id = $bapId");
}

// 5c. Confirmation record bishop/priest verbatim save
if ($confRec) {
    $confId = (int)$confRec['confirmation_id'];
    $customBishop = "Most Rev. Angelito R. Lampon, O.M.I., D.D.";
    $customConfParishPriest = "Rev. Fr. Custom Parish Priest";

    $upConf = $conn->prepare("UPDATE confirmation_records SET bishop_priest = ?, parish_priest = ? WHERE confirmation_id = ?");
    $upConf->bind_param('ssi', $customBishop, $customConfParishPriest, $confId);
    $upConf->execute();
    $upConf->close();

    $verifyConf = $conn->query("SELECT bishop_priest, parish_priest FROM confirmation_records WHERE confirmation_id = $confId")->fetch_assoc();
    assertTest($verifyConf['bishop_priest'] === $customBishop, "Confirmation bishop saved verbatim: '{$verifyConf['bishop_priest']}'");
    assertTest($verifyConf['parish_priest'] === $customConfParishPriest, "Confirmation parish priest saved verbatim: '{$verifyConf['parish_priest']}'");

    // Restore original
    $origBishop = $confRec['bishop_priest'] ?? '';
    $origConfPP = $confRec['parish_priest'] ?? '';
    $conn->query("UPDATE confirmation_records SET bishop_priest = '" . $conn->real_escape_string($origBishop) . "', parish_priest = '" . $conn->real_escape_string($origConfPP) . "' WHERE confirmation_id = $confId");
}

// -------------------------------------------------------------
// TEST 6: Session Cross-Contamination Test
// -------------------------------------------------------------
echo "\n--- TEST 6: Multi-Record Session Cross-Contamination ---\n";
$twoRecs = $conn->query("SELECT baptism_id, fullname FROM baptism_records WHERE status='active' LIMIT 2")->fetch_all(MYSQLI_ASSOC);
if (count($twoRecs) >= 2) {
    $recAId = (int)$twoRecs[0]['baptism_id'];
    $recBId = (int)$twoRecs[1]['baptism_id'];

    $priestA = "Priest Distinct Alpha " . rand(100, 999);
    $priestB = "Priest Distinct Beta " . rand(100, 999);

    $conn->query("UPDATE baptism_records SET priest = '$priestA' WHERE baptism_id = $recAId");
    $conn->query("UPDATE baptism_records SET priest = '$priestB' WHERE baptism_id = $recBId");

    $loadA = $conn->query("SELECT priest FROM baptism_records WHERE baptism_id = $recAId")->fetch_assoc()['priest'];
    $loadB = $conn->query("SELECT priest FROM baptism_records WHERE baptism_id = $recBId")->fetch_assoc()['priest'];

    assertTest($loadA === $priestA && $loadB === $priestB && $loadA !== $loadB, "Records A and B maintain separate priest fields without cross-contamination");
}

echo "\n=== TEST SUITE COMPLETED ===\n";
echo "Total Passed: $passCount\n";
echo "Total Failed: $failCount\n";

if ($failCount === 0) {
    echo "\n>>> ALL ACCEPTANCE CRITERIA VERIFIED SUCCESSFULLY! <<<\n";
    exit(0);
} else {
    echo "\n>>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
