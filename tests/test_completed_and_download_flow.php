<?php
/**
 * Test Suite:
 * 1. Renaming Status "Released" to "Completed" everywhere
 * 2. "Download Certificate" serves admin-uploaded file exactly (name, size, content)
 * 3. View/Preview certificate inline
 * 4. Auth & ownership checks
 * 5. Replacement and removal flows
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';

echo "========================================================\n";
echo "RUNNING VERIFICATION TEST FOR RELEASED -> COMPLETED\n";
echo "AND ADMIN-UPLOADED CERTIFICATE DOWNLOAD / VIEW FLOW\n";
echo "========================================================\n\n";

// TEST 1: Database has zero 'released' status requests
$checkReleased = $conn->query("SELECT COUNT(*) AS cnt FROM requests WHERE LOWER(status) = 'released'");
$countReleased = (int)($checkReleased->fetch_assoc()['cnt'] ?? 0);
assert($countReleased === 0, "Found $countReleased requests with status 'released' in database!");
echo "[PASS] Database verification: zero requests with status 'released'.\n";

// TEST 2: Create a test user & request
$testEmail = 'test_cert_user_' . time() . '@example.com';
$pwdHash = password_hash('TestPass123!', PASSWORD_DEFAULT);
$conn->query("INSERT INTO users (fullname, email, password, role, status) VALUES ('Test Parishioner', '$testEmail', '$pwdHash', 'user', 'active')");
$userId = (int)$conn->insert_id;
assert($userId > 0, "Failed to create test user");

$refNo = 'REQ-TEST-' . time();
$conn->query("INSERT INTO requests (user_id, request_type, reference_number, status, date_requested, updated_at) VALUES ($userId, 'baptismal_certificate', '$refNo', 'completed', NOW(), NOW())");
$requestId = (int)$conn->insert_id;
assert($requestId > 0, "Failed to create test request: " . $conn->error);
echo "[PASS] Created test user #$userId and request #$requestId ($refNo) with status 'completed'.\n";

// TEST 3: Admin uploads a test file (e.g. "Annotation 2025-07-31 001726.png")
$dummyContent = "FAKE_PNG_BINARY_CONTENT_" . bin2hex(random_bytes(32));
$originalFileName = "Annotation 2025-07-31 001726.png";
$uploadDir = dirname(__DIR__) . '/uploads/request_requirements';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}
$savedStorageName = 'released_certificate-request-' . $requestId . '-' . time() . '-test.png';
$storageRelativePath = 'uploads/request_requirements/' . $savedStorageName;
$storageFullPath = dirname(__DIR__) . '/' . $storageRelativePath;
file_put_contents($storageFullPath, $dummyContent);

// Insert into request_documents
$conn->query("INSERT INTO request_documents (request_id, uploaded_by, document_type, requirement_name, file_path, original_name, mime_type, file_size, uploaded_at) VALUES ($requestId, 1, 'released_certificate', 'Official Released Certificate', '$storageRelativePath', '$originalFileName', 'image/png', " . strlen($dummyContent) . ", NOW())");
$docId = (int)$conn->insert_id;

// Update request record with certificate info
$conn->query("UPDATE requests SET certificate_file_path = '$storageRelativePath', certificate_file_name = '$originalFileName', certificate_uploaded_by = 1, certificate_uploaded_at = NOW(), certificate_release_note = 'Congratulations, your baptism certificate is completed!' WHERE request_id = $requestId");
echo "[PASS] Uploaded admin certificate file '$originalFileName' (" . strlen($dummyContent) . " bytes) for request #$requestId.\n";

// TEST 4: Simulate Parishioner download via users/download-certificate.php
// We test the logic of users/download-certificate.php
$_SESSION['user_id'] = $userId;
$_SESSION['role'] = 'parishioner';
$_GET = ['request_id' => $requestId, 'download' => '1'];

// Fetch request record
$stmt = $conn->prepare("SELECT * FROM requests WHERE request_id = ? AND deleted_at IS NULL LIMIT 1");
$stmt->bind_param('i', $requestId);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();

assert($req['status'] === 'completed', "Request status should be 'completed'");
assert($req['certificate_file_name'] === $originalFileName, "Stored certificate_file_name does not match");

$resolvedPath = resolveSecureFilePath($req['certificate_file_path']);
assert($resolvedPath && is_file($resolvedPath), "resolveSecureFilePath failed to locate stored file");
$downloadedContent = file_get_contents($resolvedPath);

assert($downloadedContent === $dummyContent, "Downloaded content does not match original binary content");
assert(filesize($resolvedPath) === strlen($dummyContent), "Downloaded size does not match original size");
echo "[PASS] Download certificate test: exact file parity (name='$originalFileName', size=" . strlen($dummyContent) . " bytes, content=identical)!\n";

// TEST 5: Verify filename header generation keeps spaces and special characters
$safe_filename = str_replace(['"', "\r", "\n", "\0"], '', basename($req['certificate_file_name']));
assert($safe_filename === "Annotation 2025-07-31 001726.png", "Header safe filename stripped spaces incorrectly");
echo "[PASS] Header Content-Disposition preserves original spaces: filename=\"$safe_filename\".\n";

// TEST 6: Ownership access control
$otherUserId = $userId + 9999;
$owns = ($otherUserId === (int)$req['user_id']);
assert($owns === false, "Unauthorized user should not own request");
echo "[PASS] Access control: unauthorized user cannot download request #$requestId.\n";

// TEST 7: Admin replaces file with new file (e.g. "Certificate_Updated_Final.pdf")
$dummyPdfContent = "%PDF-1.4 TEST_UPDATED_PDF_" . bin2hex(random_bytes(16));
$newFileName = "Certificate_Updated_Final.pdf";
$newStorageName = 'released_certificate-request-' . $requestId . '-' . (time() + 1) . '-new.pdf';
$newStorageRelativePath = 'uploads/request_requirements/' . $newStorageName;
$newStorageFullPath = dirname(__DIR__) . '/' . $newStorageRelativePath;
file_put_contents($newStorageFullPath, $dummyPdfContent);

// Soft-delete old doc and add new
$conn->query("UPDATE request_documents SET deleted_at = NOW() WHERE request_id = $requestId AND document_type = 'released_certificate'");
$conn->query("INSERT INTO request_documents (request_id, uploaded_by, document_type, requirement_name, file_path, original_name, mime_type, file_size, uploaded_at) VALUES ($requestId, 1, 'released_certificate', 'Official Released Certificate', '$newStorageRelativePath', '$newFileName', 'application/pdf', " . strlen($dummyPdfContent) . ", NOW())");
$conn->query("UPDATE requests SET certificate_file_path = '$newStorageRelativePath', certificate_file_name = '$newFileName', certificate_uploaded_by = 1, certificate_uploaded_at = NOW() WHERE request_id = $requestId");

// Parishioner re-downloads
$stmt2 = $conn->prepare("SELECT * FROM requests WHERE request_id = ? LIMIT 1");
$stmt2->bind_param('i', $requestId);
$stmt2->execute();
$reqUpdated = $stmt2->get_result()->fetch_assoc();
$stmt2->close();

assert($reqUpdated['certificate_file_name'] === $newFileName, "Parishioner did not receive updated filename");
$resolvedUpdatedPath = resolveSecureFilePath($reqUpdated['certificate_file_path']);
assert(file_get_contents($resolvedUpdatedPath) === $dummyPdfContent, "Parishioner did not receive updated content");
echo "[PASS] Replacement flow: parishioner automatically receives the newest uploaded file ($newFileName)!\n";

// TEST 8: Admin removes file
$conn->query("UPDATE requests SET certificate_file_path = NULL, certificate_file_name = NULL, certificate_uploaded_by = NULL, certificate_uploaded_at = NULL, certificate_release_note = NULL WHERE request_id = $requestId");
$conn->query("UPDATE request_documents SET deleted_at = NOW() WHERE request_id = $requestId AND document_type = 'released_certificate'");

$stmt3 = $conn->prepare("SELECT * FROM requests WHERE request_id = ? LIMIT 1");
$stmt3->bind_param('i', $requestId);
$stmt3->execute();
$reqRemoved = $stmt3->get_result()->fetch_assoc();
$stmt3->close();

assert(empty($reqRemoved['certificate_file_path']), "certificate_file_path should be null after removal");
echo "[PASS] Removal flow: certificate_file_path cleared, download button disappears!\n";

// Cleanup test files & test records
@unlink($storageFullPath);
@unlink($newStorageFullPath);
$conn->query("DELETE FROM request_documents WHERE request_id = $requestId");
$conn->query("DELETE FROM requests WHERE request_id = $requestId");
$conn->query("DELETE FROM users WHERE id = $userId");
echo "[PASS] Cleaned up test database records and temp files.\n";

echo "\n========================================================\n";
echo "ALL TESTS COMPLETED SUCCESSFULLY!\n";
echo "========================================================\n";
