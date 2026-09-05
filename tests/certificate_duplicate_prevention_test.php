<?php
/**
 * Test Suite: Certificate Requests Anti-Spam Duplicate Prevention (Rule 1)
 */
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../services/RequestService.php';
require_once __DIR__ . '/../includes/helpers.php';

ensureCertificateDuplicateGuardSchema($conn);

$passed = 0;
$failed = 0;

function certCheck(bool $condition, string $label): void {
    global $passed, $failed;
    if ($condition) {
        echo "PASS: $label\n";
        $passed++;
    } else {
        echo "FAIL: $label\n";
        $failed++;
    }
}

// Get or create a test user
$userRow = $conn->query("SELECT id FROM users WHERE status='active' ORDER BY id LIMIT 1")->fetch_assoc();
if (!$userRow) {
    echo "FAIL: Test requires at least one active user.\n";
    exit(1);
}
$userId = (int) $userRow['id'];
$service = new RequestService($conn);

$conn->begin_transaction();
try {
    $holder1 = 'Test Person Alpha ' . bin2hex(random_bytes(4));
    $holder2 = 'Test Person Beta ' . bin2hex(random_bytes(4));
    $key1 = hash('sha256', random_bytes(32));
    $key2 = hash('sha256', random_bytes(32));
    $key3 = hash('sha256', random_bytes(32));

    // Test 1: First submission for holder1 succeeds
    $req1 = $service->create([
        'request_type' => 'baptismal_certificate',
        'description' => 'Test first certificate request',
        'record_holder_name' => $holder1
    ], $userId, $key1);

    certCheck(!empty($req1['request_id']) && $req1['status'] === 'submitted', 'First certificate request succeeds');
    $req1Id = (int) $req1['request_id'];

    // Test 2: Submitting duplicate request for same user, same holder, and same certificate family is BLOCKED with 409
    $blocked = false;
    $exceptionCaught = null;
    try {
        $service->create([
            'request_type' => 'baptism_certification', // same family: baptism
            'description' => 'Duplicate attempt while first is pending',
            'record_holder_name' => $holder1
        ], $userId, $key2);
    } catch (DuplicateRequestException $e) {
        $blocked = true;
        $exceptionCaught = $e;
    }

    certCheck($blocked, 'Duplicate certificate request for same record holder is blocked');
    certCheck($exceptionCaught && $exceptionCaught->getCode() === 409, 'Duplicate request exception returns HTTP 409');
    certCheck($exceptionCaught && $exceptionCaught->getReferenceNumber() === $req1['reference_number'], 'Exception references active existing request reference number');

    // Test 3: Submitting request for a DIFFERENT record holder is ALLOWED
    $reqDifferentHolder = $service->create([
        'request_type' => 'baptismal_certificate',
        'description' => 'Request for different holder',
        'record_holder_name' => $holder2
    ], $userId, $key3);

    certCheck(!empty($reqDifferentHolder['request_id']), 'Different record holder request is permitted');

    // Test 4: When the first request is completed, a new request for holder1 is now ALLOWED
    // Transition req1 to completed: submitted -> requirements_review -> payment_required -> payment_review -> approved -> processing -> ready_for_release -> completed
    // Directly update status to 'completed' for testing
    $conn->query("UPDATE requests SET status='completed' WHERE request_id = $req1Id");

    $key4 = hash('sha256', random_bytes(32));
    $reqAfterCompleted = $service->create([
        'request_type' => 'baptismal_certificate',
        'description' => 'New request after previous was completed',
        'record_holder_name' => $holder1
    ], $userId, $key4);

    certCheck(!empty($reqAfterCompleted['request_id']), 'New request is permitted after previous request reaches completed');

    // Test 5: When marked cancelled, a new request is also ALLOWED
    $reqAfterId = (int) $reqAfterCompleted['request_id'];
    $conn->query("UPDATE requests SET status='cancelled' WHERE request_id = $reqAfterId");

    $key5 = hash('sha256', random_bytes(32));
    $reqAfterCancelled = $service->create([
        'request_type' => 'baptismal_certificate',
        'description' => 'New request after previous was cancelled',
        'record_holder_name' => $holder1
    ], $userId, $key5);

    certCheck(!empty($reqAfterCancelled['request_id']), 'New request is permitted after previous request reaches cancelled');

    // Test 6: When marked rejected, a new request is also ALLOWED
    $reqCancId = (int) $reqAfterCancelled['request_id'];
    $conn->query("UPDATE requests SET status='rejected' WHERE request_id = $reqCancId");

    $key6 = hash('sha256', random_bytes(32));
    $reqAfterRejected = $service->create([
        'request_type' => 'baptismal_certificate',
        'description' => 'New request after previous was rejected',
        'record_holder_name' => $holder1
    ], $userId, $key6);

    certCheck(!empty($reqAfterRejected['request_id']), 'New request is permitted after previous request reaches rejected');

} finally {
    // Rollback test changes to preserve database clean state
    $conn->rollback();
}

echo "\nCertificate Duplicate Prevention Tests: $passed passed, $failed failed.\n";
exit($failed === 0 ? 0 : 1);
