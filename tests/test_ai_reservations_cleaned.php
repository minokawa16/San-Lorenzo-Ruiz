<?php
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../services/AiAssistantService.php';

$service = new AiAssistantService($conn);
$queries = [
    'Where to View Submitted Requests',
    'how to track my requests',
    'check my reservations',
    'how to request baptism service'
];

echo "=== TESTING AI ASSISTANT QUERY RESPONSES ===\n\n";

foreach ($queries as $q) {
    echo "QUERY: {$q}\n";
    $res = $service->respond(1, [], $q);
    $text = $res['answer'] ?? ($res['response'] ?? '');
    echo "RESPONSE:\n" . $text . "\n";
    
    // Assert no mentions of my-reservations.php or make-reservation.php
    if (str_contains($text, 'my-reservations.php')) {
        echo "[FAIL] Found 'my-reservations.php' in response!\n";
        exit(1);
    }
    if (str_contains($text, 'make-reservation.php')) {
        echo "[FAIL] Found 'make-reservation.php' in response!\n";
        exit(1);
    }
    if (str_contains($text, 'church bookings')) {
        echo "[FAIL] Found 'church bookings' in response!\n";
        exit(1);
    }
    echo "[PASS] Response is clean without church reservations/my-reservations.\n";
    echo "---------------------------------------------------------\n";
}

echo "ALL AI ASSISTANT CHECKS PASSED!\n";
