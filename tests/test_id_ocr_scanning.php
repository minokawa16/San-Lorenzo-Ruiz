<?php
/**
 * Test Suite: Philippine National ID (PhilSys) OCR Scanning & Auto-fill
 * Validates:
 * - Date normalization (Tagalog and English formats -> ISO YYYY-MM-DD)
 * - Date display formatting (-> Month DD, YYYY)
 * - PhilSys Card Number (PCN) 16-digit validation, formatting, and masking
 * - Sex parsing (Male / Female)
 * - PhilSys ID layout parsing with bilingual labels and special characters (e.g. "Cavañas")
 * - WEBP/PNG/JPEG camera capture decoding
 */

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/ocr/IDOCRProcessor.php';
require_once dirname(__DIR__) . '/ocr/AIExtractor.php';

$passedCount = 0;
$failedCount = 0;

function assertCondition(bool $cond, string $testName, string $details = ''): void {
    global $passedCount, $failedCount;
    if ($cond) {
        $passedCount++;
        echo " [PASS] {$testName}" . PHP_EOL;
    } else {
        $failedCount++;
        echo " [FAIL] {$testName}" . ($details ? ": {$details}" : "") . PHP_EOL;
    }
}

echo "==========================================================" . PHP_EOL;
echo " Running PhilSys ID OCR & Parsing Test Suite" . PHP_EOL;
echo "==========================================================" . PHP_EOL;

// 1. Date Normalization Tests
echo PHP_EOL . "--- 1. Date Normalization Tests ---" . PHP_EOL;

$datesToTest = [
    'DECEMBER 16, 2005' => '2005-12-16',
    '16 DISYEMBRE 2005' => '2005-12-16',
    'ENERO 1, 2000'     => '2000-01-01',
    '15 OKTUBRE 1998'   => '1998-10-15',
    'MAY 14, 1998'      => '1998-05-14',
    '2005-12-16'        => '2005-12-16',
    '12/16/2005'        => '2005-12-16',
    '16/12/2005'        => '2005-12-16',
    'PEBRERO 28 2001'   => '2001-02-28',
    'AGOSTO 9, 1995'    => '1995-08-09'
];

foreach ($datesToTest as $raw => $expected) {
    $normalized = IDOCRProcessor::normalizeDate($raw);
    assertCondition(
        $normalized === $expected,
        "normalizeDate('{$raw}')",
        "expected '{$expected}', got " . var_export($normalized, true)
    );
}

// 2. Date Display Formatting Tests
echo PHP_EOL . "--- 2. Date Display Formatting Tests ---" . PHP_EOL;
$displayDate = IDOCRProcessor::formatDateDisplay('2005-12-16');
assertCondition($displayDate === 'December 16, 2005', "formatDateDisplay('2005-12-16')", "got " . var_export($displayDate, true));

$displayDate2 = IDOCRProcessor::formatDateDisplay('2000-01-01');
assertCondition($displayDate2 === 'January 01, 2000', "formatDateDisplay('2000-01-01')", "got " . var_export($displayDate2, true));

// 3. PhilSys Card Number (PCN) Validation Tests
echo PHP_EOL . "--- 3. PCN 16-Digit Validation Tests ---" . PHP_EOL;
assertCondition(IDOCRProcessor::validatePhilSysCardNumber('1234-5678-9012-3456') === true, "Valid formatted PCN (1234-5678-9012-3456)");
assertCondition(IDOCRProcessor::validatePhilSysCardNumber('1234567890123456') === true, "Valid continuous 16 digits (1234567890123456)");
assertCondition(IDOCRProcessor::validatePhilSysCardNumber('1234 5678 9012 3456') === true, "Valid space-separated PCN");
assertCondition(IDOCRProcessor::validatePhilSysCardNumber('1234-5678-9012') === false, "Invalid 12-digit PCN rejected");
assertCondition(IDOCRProcessor::validatePhilSysCardNumber('1234-5678-9012-3456-7890') === false, "Invalid 20-digit string rejected");
assertCondition(IDOCRProcessor::validatePhilSysCardNumber('') === false, "Empty PCN rejected");
assertCondition(IDOCRProcessor::validatePhilSysCardNumber(null) === false, "Null PCN rejected");

// 4. PCN Formatting Tests
echo PHP_EOL . "--- 4. PCN Formatting Tests ---" . PHP_EOL;
$formattedPcn = IDOCRProcessor::formatPhilSysCardNumber('1234567890123456');
assertCondition($formattedPcn === '1234-5678-9012-3456', "formatPhilSysCardNumber('1234567890123456')", "got " . var_export($formattedPcn, true));

// 5. PCN Masking Tests (Privacy & Security Requirement)
echo PHP_EOL . "--- 5. PCN Masking Tests (Privacy & Security) ---" . PHP_EOL;
$maskedPcn = IDOCRProcessor::maskPhilSysCardNumber('1234567890123456');
assertCondition($maskedPcn === '••••-••••-••••-3456', "maskPhilSysCardNumber unformatted", "got " . var_export($maskedPcn, true));

$maskedPcn2 = IDOCRProcessor::maskPhilSysCardNumber('9876-5432-1098-7654');
assertCondition($maskedPcn2 === '••••-••••-••••-7654', "maskPhilSysCardNumber hyphenated", "got " . var_export($maskedPcn2, true));

// 6. Sex Parsing Tests
echo PHP_EOL . "--- 6. Sex Parsing Tests ---" . PHP_EOL;
assertCondition(IDOCRProcessor::parseSex('LALAKI / MALE') === 'Male', "parseSex('LALAKI / MALE')");
assertCondition(IDOCRProcessor::parseSex('BABAE / FEMALE') === 'Female', "parseSex('BABAE / FEMALE')");
assertCondition(IDOCRProcessor::parseSex('M') === 'Male', "parseSex('M')");
assertCondition(IDOCRProcessor::parseSex('F') === 'Female', "parseSex('F')");
assertCondition(IDOCRProcessor::parseSex('Female') === 'Female', "parseSex('Female')");
assertCondition(IDOCRProcessor::parseSex('Male') === 'Male', "parseSex('Male')");

// 7. Full PhilSys ID Layout & Special Character Parsing Tests
echo PHP_EOL . "--- 7. PhilSys Bilingual Layout & Special Characters Parsing ---" . PHP_EOL;
$processor = new IDOCRProcessor(sys_get_temp_dir() . '/tugon_ocr_test');
$parseMethod = new ReflectionMethod(IDOCRProcessor::class, 'parseFields');
$parseMethod->setAccessible(true);

$philSysSample = <<<'TEXT'
REPUBLIKA NG PILIPINAS
PAMBANSANG PAGKAKAKILANLAN
PHILIPPINE IDENTIFICATION SYSTEM

APELYIDO / LAST NAME
CAVAÑAS

MGA PANGALAN / GIVEN NAMES
JUAN CARLOS

GITNANG APELYIDO / MIDDLE NAME
DELA CRUZ

PETSA NG KAPANGANAKAN / DATE OF BIRTH
DECEMBER 16, 2005

KASARIAN / SEX
LALAKI / MALE

TIRAHAN / ADDRESS
PUROK 3, DUALING, ALEOSAN, COTABATO

1234-5678-9012-3456
TEXT;

$fields = $parseMethod->invoke($processor, $philSysSample);

assertCondition(($fields['last_name'] ?? '') === 'CAVAÑAS', "Extracted Last Name with ñ: 'CAVAÑAS'", "got " . var_export($fields['last_name'] ?? null, true));
assertCondition(($fields['first_name'] ?? '') === 'JUAN CARLOS', "Extracted Given Names: 'JUAN CARLOS'", "got " . var_export($fields['first_name'] ?? null, true));
assertCondition(($fields['middle_name'] ?? '') === 'DELA CRUZ', "Extracted Middle Name: 'DELA CRUZ'", "got " . var_export($fields['middle_name'] ?? null, true));
assertCondition(($fields['date_of_birth'] ?? '') === '2005-12-16', "Normalized Date of Birth: '2005-12-16'", "got " . var_export($fields['date_of_birth'] ?? null, true));
assertCondition(($fields['sex'] ?? '') === 'Male', "Parsed Kasarian/Sex: 'Male'", "got " . var_export($fields['sex'] ?? null, true));
assertCondition(strpos($fields['address'] ?? '', 'ALEOSAN') !== false, "Extracted Address contains 'ALEOSAN'", "got " . var_export($fields['address'] ?? null, true));
assertCondition(IDOCRProcessor::validatePhilSysCardNumber($fields['id_number'] ?? ''), "Extracted Valid 16-Digit PCN", "got " . var_export($fields['id_number'] ?? null, true));

// 8. Base64 Camera Capture Decoding (JPEG, PNG, WEBP)
echo PHP_EOL . "--- 8. Multi-Format Camera Capture Decoding ---" . PHP_EOL;

// 1x1 Transparent PNG
$pngDataUrl = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
$pngDecoded = decodeCameraCapture($pngDataUrl);
assertCondition($pngDecoded['ok'] && $pngDecoded['extension'] === 'png' && $pngDecoded['mime_type'] === 'image/png', "Decoded PNG Data URL");

// 1x1 JPEG
$jpgDataUrl = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';
$jpgDecoded = decodeCameraCapture($jpgDataUrl);
assertCondition($jpgDecoded['ok'] && $jpgDecoded['extension'] === 'jpg' && $jpgDecoded['mime_type'] === 'image/jpeg', "Decoded JPEG Data URL");

// 1x1 WEBP
$webpDataUrl = 'data:image/webp;base64,UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==';
$webpDecoded = decodeCameraCapture($webpDataUrl);
assertCondition($webpDecoded['ok'] && $webpDecoded['extension'] === 'webp' && $webpDecoded['mime_type'] === 'image/webp', "Decoded WEBP Data URL");

// Invalid capture check
$invalidDataUrl = 'data:text/plain;base64,SGVsbG8=';
$invalidDecoded = decodeCameraCapture($invalidDataUrl);
assertCondition($invalidDecoded['ok'] === false, "Rejected non-image data URL");

// 9. Summary & Exit Code
echo PHP_EOL . "==========================================================" . PHP_EOL;
echo " Test Results: {$passedCount} Passed, {$failedCount} Failed" . PHP_EOL;
echo "==========================================================" . PHP_EOL;

if ($failedCount > 0) {
    exit(1);
}
exit(0);
