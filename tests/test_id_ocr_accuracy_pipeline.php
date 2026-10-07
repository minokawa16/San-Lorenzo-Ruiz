<?php
/**
 * Test Suite: TUGON Parish Management System - OCR & ID Extraction Accuracy
 * Comprehensive validation of:
 * - Image quality gate (blur, glare, resolution, aspect ratio)
 * - Document type identification
 * - Strict label-based extraction and suffix handling
 * - Strict date validation (rejects OCR corruptions like 12/0B/2007, future dates, invalid calendar days)
 * - PCN extraction and validation
 * - Zero birthplace inference from address
 * - Two-pass consensus reconciliation & uninflated honest confidence scoring
 */

require_once __DIR__ . '/../ocr/IDOCRProcessor.php';
require_once __DIR__ . '/../ocr/AIExtractor.php';

$passed = 0;
$failed = 0;

function assertAccuracy(bool $cond, string $name, string $details = '') {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name}" . ($details ? ": {$details}" : '') . "\n";
    }
}

echo "====================================================================\n";
echo " TUGON OCR & ID EXTRACTION ACCURACY VALIDATION TEST SUITE\n";
echo "====================================================================\n\n";

// ── 1. Image Quality Assessment Tests ─────────────────────────────────────────
echo "--- 1. Image Quality Assessment Tests ---\n";

// Create test image files in temp directory
$tmpDir = sys_get_temp_dir() . '/tugon_quality_tests_' . uniqid();
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0777, true);
}

// 1a. Tiny image (below 320px)
$tinyPath = $tmpDir . '/tiny.jpg';
$tinyImg = imagecreatetruecolor(100, 60);
imagejpeg($tinyImg, $tinyPath);
imagedestroy($tinyImg);

$resTiny = IDOCRProcessor::evaluateImageQuality($tinyPath);
assertAccuracy(!$resTiny['pass'], "Rejects image smaller than minimum resolution");
assertAccuracy(in_array('too_small', $resTiny['issues'], true), "Identifies 'too_small' issue flag");

// 1b. Extreme aspect ratio (e.g. 10:1 banner, not an ID card)
$skewPath = $tmpDir . '/skewed.jpg';
$skewImg = imagecreatetruecolor(1200, 80);
imagejpeg($skewImg, $skewPath);
imagedestroy($skewImg);

$resSkew = IDOCRProcessor::evaluateImageQuality($skewPath);
assertAccuracy(!$resSkew['pass'], "Rejects extreme non-ID aspect ratio");
assertAccuracy(in_array('extreme_aspect_ratio', $resSkew['issues'], true), "Identifies 'extreme_aspect_ratio' issue flag");

// 1c. Good quality ID aspect ratio (856 x 540)
$goodPath = $tmpDir . '/good.jpg';
$goodImg = imagecreatetruecolor(856, 540);
// Fill with realistic card background and text contrast
$bg = imagecolorallocate($goodImg, 220, 220, 220);
imagefilledrectangle($goodImg, 0, 0, 856, 540, $bg);
$photoColor = imagecolorallocate($goodImg, 50, 70, 90);
imagefilledrectangle($goodImg, 40, 80, 240, 340, $photoColor);
$fg = imagecolorallocate($goodImg, 20, 20, 20);
imagestring($goodImg, 5, 280, 80, "REPUBLIKA NG PILIPINAS", $fg);
imagestring($goodImg, 4, 280, 110, "PAMBANSANG PAGKAKAKILANLAN", $fg);
imagestring($goodImg, 4, 280, 140, "PHILIPPINE IDENTIFICATION SYSTEM", $fg);
imagestring($goodImg, 4, 280, 180, "APELYIDO / LAST NAME: DELA CRUZ", $fg);
imagestring($goodImg, 4, 280, 210, "MGA PANGALAN / GIVEN NAMES: JUAN", $fg);
imagejpeg($goodImg, $goodPath, 90);
imagedestroy($goodImg);

$resGood = IDOCRProcessor::evaluateImageQuality($goodPath);
assertAccuracy($resGood['pass'], "Accepts readable image with standard ID dimensions and lighting");

// ── 2. Document Type Identification Tests ─────────────────────────────────────
echo "\n--- 2. Document Type Identification Tests ---\n";

$textPhilSys = "REPUBLIKA NG PILIPINAS PAMBANSANG PAGKAKAKILANLAN PHILIPPINE IDENTIFICATION SYSTEM PCN 1234-5678-9012-3456";
$docPhilSys = IDOCRProcessor::detectDocumentType($textPhilSys);
assertAccuracy($docPhilSys['type'] === 'Philippine National ID / PhilSys', "Detects PhilSys National ID");
assertAccuracy($docPhilSys['confidence'] >= 0.90, "High confidence for PhilSys keywords");

$textDrivers = "REPUBLIC OF THE PHILIPPINES DEPARTMENT OF TRANSPORTATION LAND TRANSPORTATION OFFICE DRIVER'S LICENSE LICENSE NO: N01-12-345678";
$docDrivers = IDOCRProcessor::detectDocumentType($textDrivers);
assertAccuracy($docDrivers['type'] === "Driver's License", "Detects Driver's License");

$textUMID = "UNIFIED MULTI-PURPOSE ID UMID CRN-0123-4567-8901 REPUBLIC OF THE PHILIPPINES";
$docUMID = IDOCRProcessor::detectDocumentType($textUMID);
assertAccuracy($docUMID['type'] === 'UMID', "Detects UMID");

$textPassport = "REPUBLIKA NG PILIPINAS PASAPORTE PASSPORT PASSPORT NO. P1234567A";
$docPassport = IDOCRProcessor::detectDocumentType($textPassport);
assertAccuracy($docPassport['type'] === 'Passport', "Detects Passport");

$textVoters = "COMMISSION ON ELECTIONS VOTER'S IDENTIFICATION CARD VOTER ID";
$docVoters = IDOCRProcessor::detectDocumentType($textVoters);
assertAccuracy($docVoters['type'] === "Voter's ID", "Detects Voter's ID");

// ── 3. Suffix Extraction Tests ─────────────────────────────────────────────────
echo "\n--- 3. Suffix Extraction Tests ---\n";

$name1 = "DELA CRUZ JR.";
$suf1 = IDOCRProcessor::extractSuffix($name1);
assertAccuracy($suf1 === 'JR.' && $name1 === 'DELA CRUZ', "Extracts 'JR.' from compound surname");

$name2 = "SANTOS, III";
$suf2 = IDOCRProcessor::extractSuffix($name2);
assertAccuracy($suf2 === 'III' && $name2 === 'SANTOS', "Extracts 'III' with comma separator");

$name3 = "REYES SR";
$suf3 = IDOCRProcessor::extractSuffix($name3);
assertAccuracy($suf3 === 'SR' && $name3 === 'REYES', "Extracts 'SR' without period");

$name4 = "GARCIA";
$suf4 = IDOCRProcessor::extractSuffix($name4);
assertAccuracy($suf4 === null && $name4 === 'GARCIA', "Returns null suffix for standard surname");

// ── 4. Strict Date Validation Tests ───────────────────────────────────────────
echo "\n--- 4. Strict Date Validation Tests ---\n";

// 4a. OCR corrupted date with letter (e.g. '12/0B/2007') must be rejected!
$corruptedDate = IDOCRProcessor::normalizeDate('12/0B/2007');
assertAccuracy($corruptedDate === null, "Rejects OCR-corrupted date with letter '12/0B/2007'");

// 4b. Impossible calendar dates must be rejected!
assertAccuracy(IDOCRProcessor::normalizeDate('2023-02-29') === null, "Rejects non-leap year Feb 29 (2023-02-29)");
assertAccuracy(IDOCRProcessor::normalizeDate('2023-04-31') === null, "Rejects April 31 (2023-04-31)");
assertAccuracy(IDOCRProcessor::normalizeDate('13/05/1990') === '1990-05-13', "Resolves unambiguous DD/MM/YYYY when day > 12");

// 4c. Future dates must be rejected!
$futureYear = (int) date('Y') + 2;
assertAccuracy(IDOCRProcessor::normalizeDate("{$futureYear}-01-15") === null, "Rejects future birthdate ({$futureYear}-01-15)");

// 4d. Valid dates correctly normalized
assertAccuracy(IDOCRProcessor::normalizeDate('14 MAY 1998') === '1998-05-14', "Normalizes '14 MAY 1998' to '1998-05-14'");
assertAccuracy(IDOCRProcessor::normalizeDate('DECEMBER 16, 2005') === '2005-12-16', "Normalizes 'DECEMBER 16, 2005' to '2005-12-16'");

// ── 5. Zero Birthplace Inference Tests ─────────────────────────────────────────
echo "\n--- 5. Zero Birthplace Inference Tests ---\n";

$processor = new IDOCRProcessor($tmpDir);
$parseRef = new ReflectionMethod(IDOCRProcessor::class, 'parseFields');
$parseRef->setAccessible(true);

$philSysNoBirthPlace = <<<'TEXT'
REPUBLIKA NG PILIPINAS
APELYIDO/SURNAME
DELA CRUZ
PANGALAN/GIVEN NAMES
JUAN
GITNANG APELYIDO/MIDDLE NAME
SANTOS
DATE OF BIRTH
1998-05-14
ADDRESS
PUROK 3, DUALING, ALEOSAN, COTABATO
PCN 1234-5678-9012-3456
TEXT;

$extractedFields = $parseRef->invoke($processor, $philSysNoBirthPlace);
assertAccuracy(
    ($extractedFields['birth_place'] ?? null) === null,
    "Birthplace is strictly null when absent on ID (never inferred from address)",
    "got " . var_export($extractedFields['birth_place'] ?? null, true)
);

// If ID explicitly has LUGAR NG KAPANGANAKAN / PLACE OF BIRTH:
$idWithBirthPlace = <<<'TEXT'
REPUBLIC OF THE PHILIPPINES
SURNAME: DELA CRUZ
FIRST NAME: JUAN
MIDDLE NAME: SANTOS
PLACE OF BIRTH / LUGAR NG KAPANGANAKAN: DAVAO CITY
DATE OF BIRTH: 1998-05-14
TEXT;

$extractedWithBirthPlace = $parseRef->invoke($processor, $idWithBirthPlace);
assertAccuracy(
    ($extractedWithBirthPlace['birth_place'] ?? null) === 'DAVAO CITY',
    "Birthplace correctly extracted when explicitly labeled on document"
);

// ── 6. Address Preservation Tests ─────────────────────────────────────────────
echo "\n--- 6. Address Preservation Tests ---\n";

$fullAddressText = <<<'TEXT'
APELYIDO/SURNAME: REYES
MGA PANGALAN/GIVEN NAMES: MARIA
ADDRESS: BLK 4 LOT 12, MAHOGANY ST., PUROK 2, BARANGAY SAN JOSE, GENERAL SANTOS CITY, SOUTH COTABATO
TEXT;

$extractedAddr = $parseRef->invoke($processor, $fullAddressText);
assertAccuracy(
    str_contains($extractedAddr['address'] ?? '', 'MAHOGANY ST.')
    && str_contains($extractedAddr['address'] ?? '', 'GENERAL SANTOS CITY')
    && str_contains($extractedAddr['address'] ?? '', 'SOUTH COTABATO'),
    "Full address with street, barangay, city, and province is completely preserved"
);

// ── 7. Two-Pass Consensus & Honest Confidence Tests ───────────────────────────
echo "\n--- 7. Two-Pass Consensus & Honest Confidence Tests ---\n";

$extractor = new AIExtractor();

// Section 20 Schema representation
$sampleSection20 = json_encode([
    'status' => 'SUCCESS',
    'id_type_detected' => 'Philippine National ID',
    'document_confidence' => 0.98,
    'fields' => [
        'first_name'    => ['value' => 'JUAN CARLOS', 'confidence' => 0.98],
        'middle_name'   => ['value' => 'DELA CRUZ',   'confidence' => 0.94],
        'last_name'     => ['value' => 'SANTOS',      'confidence' => 0.99],
        'suffix'        => ['value' => null,          'confidence' => 0.0],
        'id_number'     => ['value' => '1234-5678-9012-3456', 'confidence' => 0.99],
        'date_of_birth' => ['value' => '2000-05-20',   'confidence' => 0.99],
        'address'       => ['value' => 'PUROK 5, POBLACION, ALEOSAN, COTABATO', 'confidence' => 0.92],
        'birth_place'   => ['value' => null,          'confidence' => 0.0],
        'sex'           => ['value' => 'Male',        'confidence' => 0.99]
    ],
    'needs_review' => false
]);

$parsedAi = $extractor->parseAiContent($sampleSection20);
assertAccuracy($parsedAi !== null, "Parses Section 20 Schema successfully");
assertAccuracy(($parsedAi['fields']['first_name']['confidence'] ?? 0) === 0.98, "Honest confidence 0.98 preserved without artificial inflation");
assertAccuracy(is_null($parsedAi['fields']['birth_place']['value']), "Birthplace value is strictly null");
assertAccuracy(($parsedAi['fields']['birth_place']['confidence'] ?? -1) === 0.0, "Birthplace confidence is 0.0 when absent");
assertAccuracy(($parsedAi['id_type_detected'] ?? '') === 'Philippine National ID', "Detects correct ID type");

// Clean up temp files
@unlink($tinyPath);
@unlink($skewPath);
@unlink($goodPath);
@rmdir($tmpDir);

echo "\n====================================================================\n";
echo " Accuracy Test Results: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n";

exit($failed === 0 ? 0 : 1);
