<?php
/**
 * Test Suite: AIExtractor High-Precision Prompt & Data Parsing
 */

require_once __DIR__ . '/../ocr/AIExtractor.php';

$extractor = new AIExtractor();
$passed = 0;
$failed = 0;

function assertAITest(bool $condition, string $name, string $details = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] $name\n";
    } else {
        $failed++;
        echo "[FAIL] $name: $details\n";
    }
}

echo "=== Testing AIExtractor with High-Precision Prompt Template ===\n\n";

// Test 1: New Structured Format with extracted_data & confidence_scores
$sampleNewFormat = json_encode([
    'status' => 'SUCCESS',
    'id_type_detected' => 'PhilSys National ID',
    'extracted_data' => [
        'first_name' => 'Juan Carlos',
        'middle_name' => 'Dela Cruz',
        'surname' => 'Santos',
        'id_number' => '1234-5678-9012-3456',
        'address' => 'Purok 3, Barangay Pag-asa, Aleosan, Cotabato',
        'date_of_birth' => '1998-05-14',
        'sex' => 'Male'
    ],
    'confidence_scores' => [
        'first_name' => 0.98,
        'middle_name' => 0.95,
        'surname' => 0.99,
        'id_number' => 1.0,
        'address' => 0.92,
        'date_of_birth' => 1.0,
        'sex' => 1.0
    ]
]);

$result1 = $extractor->parseAiContent($sampleNewFormat);

assertAITest($result1 !== null, "Test 1.1: parseAiContent returns non-null array");
assertAITest(($result1['first_name'] ?? '') === 'JUAN CARLOS', "Test 1.2: first_name extracted & normalized to UPPERCASE");
assertAITest(($result1['middle_name'] ?? '') === 'DELA CRUZ', "Test 1.3: middle_name extracted & normalized");
assertAITest(($result1['last_name'] ?? '') === 'SANTOS', "Test 1.4: surname mapped to last_name");
assertAITest(($result1['id_number'] ?? '') === '1234-5678-9012-3456', "Test 1.5: id_number extracted");
assertAITest(($result1['date_of_birth'] ?? '') === '1998-05-14', "Test 1.6: date_of_birth extracted");
assertAITest(($result1['sex'] ?? '') === 'MALE', "Test 1.7: sex extracted");
assertAITest(($result1['confidence_scores']['first_name'] ?? 0) === 0.98, "Test 1.8: per-field confidence score preserved");
assertAITest(($result1['confidence_score'] ?? 0) >= 0.95, "Test 1.9: overall confidence computed from per-field scores");

// Test 2: Handling markdown code blocks (```json ... ```)
$markdownWrapped = "```json\n" . $sampleNewFormat . "\n```";
$result2 = $extractor->parseAiContent($markdownWrapped);
assertAITest($result2 !== null && ($result2['first_name'] ?? '') === 'JUAN CARLOS', "Test 2.1: Correctly strips ```json markdown fences");

// Test 3: Null middle name handling
$sampleNoMiddle = json_encode([
    'status' => 'SUCCESS',
    'id_type_detected' => 'Passport',
    'extracted_data' => [
        'first_name' => 'Maria',
        'middle_name' => null,
        'surname' => 'Gonzales',
        'id_number' => 'P1234567A',
        'address' => null,
        'date_of_birth' => '2001-08-20',
        'sex' => 'Female'
    ],
    'confidence_scores' => [
        'first_name' => 1.0,
        'middle_name' => 0.0,
        'surname' => 1.0
    ]
]);
$result3 = $extractor->parseAiContent($sampleNoMiddle);
assertAITest($result3['middle_name'] === null, "Test 3.1: Null middle name correctly retained as null (zero-hallucination)");
assertAITest($result3['address'] === null, "Test 3.2: Null address correctly retained as null");

// Test 4: Backward compatibility with legacy flat format
$sampleLegacyFlat = json_encode([
    'id_type_detected' => 'PhilSys National ID',
    'confidence_score' => 0.88,
    'first_name' => 'Pedro',
    'middle_name' => 'A',
    'last_name' => 'Penduko',
    'id_number' => '9999-8888-7777-6666'
]);
$result4 = $extractor->parseAiContent($sampleLegacyFlat);
assertAITest($result4 !== null && ($result4['last_name'] ?? '') === 'PENDUKO', "Test 4.1: Backward compatibility with flat format");

// Test 5: Rejection of empty/hallucinated payload without names
$sampleEmpty = json_encode([
    'status' => 'FAIL',
    'extracted_data' => [
        'first_name' => null,
        'surname' => null
    ]
]);
$result5 = $extractor->parseAiContent($sampleEmpty);
assertAITest($result5 === null, "Test 5.1: Rejects payload with missing names as invalid/illegible");

echo "\nSummary: Total Passed: $passed, Total Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
