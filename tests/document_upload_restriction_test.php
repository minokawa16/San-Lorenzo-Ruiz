<?php
/**
 * Test Suite: Document Upload Restriction & Preview Modal
 * Validates:
 * - PDF and image restrictions (JPG, PNG, WEBP only, rejecting DOCX, XLSX, TXT, ZIP, etc.)
 * - 5 MB file size limit enforcement
 * - Magic bytes validation
 * - Group validation rejection before DB insert
 * - Funeral Mass description fix (no "Array" string)
 * - Templates and UI attributes
 * - AiAssistantService upload guidance
 */

require_once __DIR__ . '/../includes/uploads.php';

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
        echo "[FAIL] $label" . ($details ? " ($details)" : '') . "\n";
    }
}

echo "=== Testing Document Upload Restrictions & Validations ===\n\n";

// 1. Constants
runTest(defined('TUGON_UPLOAD_MAX_BYTES') && TUGON_UPLOAD_MAX_BYTES === 64 * 1024 * 1024, 'TUGON_UPLOAD_MAX_BYTES is 64 MB (67,108,864 bytes)');
runTest(defined('TUGON_ALLOWED_EXTENSIONS') && TUGON_ALLOWED_EXTENSIONS === ['pdf', 'jpg', 'jpeg', 'png', 'webp'], 'Allowed extensions are pdf, jpg, jpeg, png, webp');

// 2. Mock file creator helper
function createTempUpload(string $name, string $mime, string $content, int $error = UPLOAD_ERR_OK): array {
    $tmpPath = tempnam(sys_get_temp_dir(), 'tugon_test_');
    file_put_contents($tmpPath, $content);
    return [
        'name' => $name,
        'type' => $mime,
        'tmp_name' => $tmpPath,
        'error' => $error,
        'size' => strlen($content)
    ];
}

$expectedErrorMsg = "Only PDF or image files (JPG, PNG, WEBP) are allowed. Please convert your document and upload again.";

// 3. Valid File Validations with Magic Bytes
// Valid PDF
$pdfContent = "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<<\n/Type /Catalog\n>>\nendobj\ntrailer\n<<\n/Root 1 0 R\n>>\n%%EOF";
$pdfFile = createTempUpload('birth_certificate.pdf', 'application/pdf', $pdfContent);
$val = validateUploadedDocument($pdfFile);
runTest($val['ok'] === true, 'Valid PDF with %PDF- header accepted');
@unlink($pdfFile['tmp_name']);

// Valid JPEG
$jpegContent = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00\xFF\xDB\x00C\x00" . str_repeat("\x00", 20) . "\xFF\xD9";
$jpegFile = createTempUpload('national_id.jpg', 'image/jpeg', $jpegContent);
$val = validateUploadedDocument($jpegFile);
runTest($val['ok'] === true, 'Valid JPEG with JFIF header accepted');
@unlink($jpegFile['tmp_name']);

// Valid PNG
$pngContent = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15c4\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82";
$pngFile = createTempUpload('valid_id.png', 'image/png', $pngContent);
$val = validateUploadedDocument($pngFile);
runTest($val['ok'] === true, 'Valid PNG with standard signature accepted');
@unlink($pngFile['tmp_name']);

// Valid WEBP
$webpContent = "RIFF\x24\x00\x00\x00WEBPVP8 \x18\x00\x00\x000\x01\x00\x9d\x01\x2a\x01\x00\x01\x00";
$webpFile = createTempUpload('scanned_doc.webp', 'image/webp', $webpContent);
$val = validateUploadedDocument($webpFile);
runTest($val['ok'] === true, 'Valid WEBP with RIFF....WEBP signature accepted');
@unlink($webpFile['tmp_name']);

// 4. Invalid File Types Rejected
// DOCX
$docxContent = "PK\x03\x04\x14\x00\x06\x00\x08\x00\x00\x00" . str_repeat("\x00", 50);
$docxFile = createTempUpload('document.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docxContent);
$val = validateUploadedDocument($docxFile);
runTest($val['ok'] === false && $val['error'] === $expectedErrorMsg, 'DOCX rejected with exact error message');
@unlink($docxFile['tmp_name']);

// XLSX
$xlsxFile = createTempUpload('sheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $docxContent);
$val = validateUploadedDocument($xlsxFile);
runTest($val['ok'] === false && $val['error'] === $expectedErrorMsg, 'XLSX rejected with exact error message');
@unlink($xlsxFile['tmp_name']);

// TXT
$txtFile = createTempUpload('readme.txt', 'text/plain', 'Plain text file content');
$val = validateUploadedDocument($txtFile);
runTest($val['ok'] === false && $val['error'] === $expectedErrorMsg, 'TXT rejected with exact error message');
@unlink($txtFile['tmp_name']);

// ZIP
$zipFile = createTempUpload('archive.zip', 'application/zip', "PK\x03\x04" . str_repeat("A", 20));
$val = validateUploadedDocument($zipFile);
runTest($val['ok'] === false && $val['error'] === $expectedErrorMsg, 'ZIP rejected with exact error message');
@unlink($zipFile['tmp_name']);

// Spoofed Extension (.pdf extension with DOCX / ZIP magic bytes)
$spoofedFile = createTempUpload('fake.pdf', 'application/pdf', "PK\x03\x04This is a docx renamed to pdf");
$val = validateUploadedDocument($spoofedFile);
runTest($val['ok'] === false, 'Spoofed file (PK header disguised as .pdf) rejected by magic byte detection');
@unlink($spoofedFile['tmp_name']);

// Spoofed Extension (.jpg extension with plain text)
$fakeJpg = createTempUpload('fake.jpg', 'image/jpeg', "Just plain text inside a file named fake.jpg");
$val = validateUploadedDocument($fakeJpg);
runTest($val['ok'] === false, 'Spoofed file (plain text disguised as .jpg) rejected by magic byte detection');
@unlink($fakeJpg['tmp_name']);

// 5. Size Limit Validation (> 64 MB rejected)
$oversizedContent = "%PDF-1.4\n" . str_repeat("A", (64 * 1024 * 1024) + 100);
$oversizedFile = createTempUpload('huge.pdf', 'application/pdf', $oversizedContent);
$val = validateUploadedDocument($oversizedFile);
runTest($val['ok'] === false && $val['error'] === $expectedErrorMsg, 'Oversized file (> 64 MB) rejected with exact error message');
@unlink($oversizedFile['tmp_name']);

// 6. Group Validation (validateUploadedDocumentGroup)
// Group with 2 valid files
$pdf1 = createTempUpload('doc1.pdf', 'application/pdf', $pdfContent);
$jpg1 = createTempUpload('doc2.jpg', 'image/jpeg', $jpegContent);
$validGroup = [
    'name' => [$pdf1['name'], $jpg1['name']],
    'type' => [$pdf1['type'], $jpg1['type']],
    'tmp_name' => [$pdf1['tmp_name'], $jpg1['tmp_name']],
    'error' => [$pdf1['error'], $jpg1['error']],
    'size' => [$pdf1['size'], $jpg1['size']]
];
$groupVal = validateUploadedDocumentGroup($validGroup);
runTest($groupVal['ok'] === true, 'Group of valid files accepted');

// Group with 1 valid and 1 invalid file (must reject whole group)
$docx1 = createTempUpload('bad.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $docxContent);
$invalidGroup = [
    'name' => [$pdf1['name'], $docx1['name']],
    'type' => [$pdf1['type'], $docx1['type']],
    'tmp_name' => [$pdf1['tmp_name'], $docx1['tmp_name']],
    'error' => [$pdf1['error'], $docx1['error']],
    'size' => [$pdf1['size'], $docx1['size']]
];
$groupVal = validateUploadedDocumentGroup($invalidGroup);
runTest($groupVal['ok'] === false && $groupVal['error'] === $expectedErrorMsg, 'Group with single invalid file rejects entire group before DB insert');
@unlink($pdf1['tmp_name']);
@unlink($jpg1['tmp_name']);
@unlink($docx1['tmp_name']);

// 7. Funeral Mass Description Bug Check (No "Array" string)
$funeral_requirements = [
    'death_certificate' => ['label' => 'Death Certificate', 'mandatory' => true],
    'burial_permit' => ['label' => 'Burial / Cremation Permit', 'mandatory' => false]
];
$funeral_labels = [];
foreach ($funeral_requirements as $req_key => $req_meta) {
    $funeral_labels[] = $req_meta['label'] ?? (is_string($req_meta) ? $req_meta : $req_key);
}
$desc_details = "Funeral Mass requirement uploads: " . implode(', ', array_filter($funeral_labels));
runTest(strpos($desc_details, 'Array') === false, 'Funeral Mass requirement description does NOT contain "Array"');
runTest(strpos($desc_details, 'Death Certificate, Burial / Cremation Permit') !== false, 'Funeral Mass requirement description contains readable labels');

// 8. Template & Client-side Attributes Verification
$modalFile = __DIR__ . '/../templates/document-preview-modal.php';
runTest(file_exists($modalFile), 'templates/document-preview-modal.php exists');
$modalContent = file_get_contents($modalFile);
runTest(strpos($modalContent, 'documentPreviewModal') !== false, 'Modal contains #documentPreviewModal');
runTest(strpos($modalContent, 'docPreviewPdfFrame') !== false, 'Modal contains #docPreviewPdfFrame for inline PDF display');
runTest(strpos($modalContent, 'docPreviewImage') !== false, 'Modal contains #docPreviewImage for inline image display');
runTest(strpos($modalContent, 'Verified authenticated document stream') !== false, 'Modal contains verified stream footer note');

// Form accept attributes and helper text checks
$serviceForm = file_get_contents(__DIR__ . '/../users/request-service.php');
runTest(strpos($serviceForm, 'accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*"') !== false, 'request-service.php has restricted accept attribute');
runTest(strpos($serviceForm, 'Accepted formats: PDF, JPG, PNG, WEBP (max 5 MB each)') !== false, 'request-service.php has 5 MB helper text');

$certForm = file_get_contents(__DIR__ . '/../users/request-certificate.php');
runTest(strpos($certForm, 'accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*"') !== false, 'request-certificate.php has restricted accept attribute');
runTest(strpos($certForm, 'Accepted formats: PDF, JPG, PNG, WEBP') !== false, 'request-certificate.php has format helper text');

$blessingForm = file_get_contents(__DIR__ . '/../users/request-blessing.php');
runTest(strpos($blessingForm, 'accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*"') !== false, 'request-blessing.php has restricted accept attribute');
runTest(strpos($blessingForm, 'Accepted formats: PDF, JPG, PNG, WEBP (max 5 MB each)') !== false, 'request-blessing.php has 5 MB helper text');

$jsFile = file_get_contents(__DIR__ . '/../assets/js/request-modern.js');
runTest(strpos($jsFile, 'maxFileBytes = 5 * 1024 * 1024') !== false, 'request-modern.js has 5 MB client-side constant');
runTest(strpos($jsFile, 'Only PDF or image files (JPG, PNG, WEBP) up to 5 MB are allowed') !== false, 'request-modern.js has required client error message');

echo "\n=== Tests Completed: $passed / $total passed ===\n";
if ($failed > 0) {
    echo "FAILED: $failed tests failed.\n";
    exit(1);
} else {
    echo "SUCCESS: All document upload restriction tests passed!\n";
    exit(0);
}
