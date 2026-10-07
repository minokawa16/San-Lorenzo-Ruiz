<?php
/**
 * Test Suite: AIExtractor Enterprise Prompt & Schema Parsing
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

echo "=== Testing AIExtractor Enterprise Schema & Prompt Validation ===\n\n";

// Test 1: Enterprise Schema with extraction & metadata
$sampleEnterprise = json_encode([
    'status' => 'SUCCESS',
    'extraction' => [
        'first_name' => 'Rey Mark',
        'middle_name' => 'Dela Cruz',
        'last_name' => 'Cavañas',
        'id_number' => '1234-5678-9012-3456',
        'date_of_birth' => '2000-01-15',
        'address' => 'Poblacion 1, Aleosan, Cotabato',
        'sex' => 'Male'
    ],
    'metadata' => [
        'document_type' => 'PhilSys National ID',
        'confidence_score' => 0.99
    ]
]);

$result1 = $extractor->parseAiContent($sampleEnterprise);

assertAITest($result1 !== null, "Test 1.1: parseAiContent returns non-null array");
assertAITest(($result1['first_name'] ?? '') === 'REY MARK', "Test 1.2: Un-truncated multi-word given name ('REY MARK')");
assertAITest(($result1['middle_name'] ?? '') === 'DELA CRUZ', "Test 1.3: Un-truncated middle name ('DELA CRUZ')");
assertAITest(($result1['last_name'] ?? '') === 'CAVAÑAS', "Test 1.4: Accented legal surname ('CAVAÑAS')");
assertAITest(($result1['id_number'] ?? '') === '1234-5678-9012-3456', "Test 1.5: Exact ID number format");
assertAITest(($result1['date_of_birth'] ?? '') === '2000-01-15', "Test 1.6: ISO date format");
assertAITest(($result1['address'] ?? '') === 'POBLACION 1, ALEOSAN, COTABATO', "Test 1.7: Full address string");
assertAITest(($result1['id_type_detected'] ?? '') === 'PhilSys National ID', "Test 1.8: metadata.document_type parsed");
assertAITest(($result1['confidence_score'] ?? 0) === 0.99, "Test 1.9: metadata.confidence_score parsed");

// Test 2: Structured format with extracted_data & confidence_scores
$sampleExtractedData = json_encode([
    'status' => 'SUCCESS',
    'id_type_detected' => 'Driver\'s License',
    'extracted_data' => [
        'first_name' => 'Maria Theresa',
        'middle_name' => null,
        'surname' => 'Santos',
        'id_number' => 'N01-12-345678',
        'address' => 'Davao City',
        'date_of_birth' => '1995-04-12',
        'sex' => 'Female'
    ],
    'confidence_scores' => [
        'first_name' => 1.0,
        'surname' => 1.0,
        'id_number' => 1.0
    ]
]);

$result2 = $extractor->parseAiContent($sampleExtractedData);
assertAITest($result2 !== null, "Test 2.1: extracted_data format parsed");
assertAITest(($result2['first_name'] ?? '') === 'MARIA THERESA', "Test 2.2: first_name extracted");
assertAITest(($result2['last_name'] ?? '') === 'SANTOS', "Test 2.3: surname mapped to last_name");
assertAITest($result2['middle_name'] === null, "Test 2.4: null middle name retained");

// Test 3: Markdown fence stripping
$markdownWrapped = "```json\n" . $sampleEnterprise . "\n```";
$result3 = $extractor->parseAiContent($markdownWrapped);
assertAITest($result3 !== null && ($result3['first_name'] ?? '') === 'REY MARK', "Test 3.1: Correctly strips ```json markdown fences");

// Test 4: Flat legacy format
$sampleFlat = json_encode([
    'id_type_detected' => 'UMID',
    'confidence_score' => 0.91,
    'first_name' => 'Pedro',
    'last_name' => 'Penduko',
    'id_number' => 'CRN-0123-4567-8901'
]);
$result4 = $extractor->parseAiContent($sampleFlat);
assertAITest($result4 !== null && ($result4['last_name'] ?? '') === 'PENDUKO', "Test 4.1: Flat legacy format supported");

// Test 5: Rejection of invalid payload without names
$sampleEmpty = json_encode([
    'status' => 'FAIL',
    'extraction' => [
        'first_name' => null,
        'last_name' => null
    ]
]);
$result5 = $extractor->parseAiContent($sampleEmpty);
assertAITest($result5 === null, "Test 5.1: Rejects payload with missing names");

echo "\nSummary: Total Passed: $passed, Total Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
