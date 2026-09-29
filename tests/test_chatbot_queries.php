<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../services/AiAssistantService.php';

$service = new AiAssistantService($conn);
$capabilities = [
    'staff' => false,
    'admin' => false,
    'records' => false,
    'reports' => false,
    'feedback' => false,
];

// Test with parishioner user ID 1 (or any existing user)
$userId = 1;

$testQueries = [
    'What are the mass schedules?' => 'Mass Schedules',
    'How do I request a Baptismal Certificate?' => 'Baptism Certificate',
    'What are the requirements for Matrimony/Wedding?' => 'Wedding Guidelines',
    'How do I track my submitted request?' => 'Track My Request'
];

echo "=== Testing TUGON AI Assistant Service Queries ===\n\n";

$allPassed = true;

foreach ($testQueries as $query => $label) {
    echo "--- Testing: [{$label}] \"{$query}\" ---\n";
    try {
        $result = $service->respond($userId, $capabilities, $query, 'chat', []);
        if (!empty($result['success']) && !empty($result['answer'])) {
            echo "[PASS] Successfully got answer (" . strlen($result['answer']) . " bytes)\n";
            echo "Category: " . ($result['category'] ?? 'N/A') . "\n";
            echo "Preview:\n" . substr($result['answer'], 0, 180) . "...\n\n";
        } else {
            echo "[FAIL] Unexpected response format:\n";
            print_r($result);
            $allPassed = false;
        }
    } catch (Throwable $e) {
        echo "[FAIL] Exception: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
        $allPassed = false;
    }
}

if ($allPassed) {
    echo "ALL 4 CORE CHATBOT QUERIES PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "SOME QUERIES FAILED!\n";
    exit(1);
}
