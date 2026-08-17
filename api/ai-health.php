<?php
/** Lightweight authenticated status check for the local Ollama service. */

header('Content-Type: application/json');
header('Cache-Control: no-store');
ini_set('display_errors', '0');

include __DIR__ . '/../includes/session.php';
include __DIR__ . '/../includes/helpers.php';
include __DIR__ . '/../includes/OllamaClient.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

try {
    $health = (new OllamaClient())->healthCheck();
    echo json_encode([
        'success' => true,
        'online' => (bool) $health['online'],
        'model_available' => (bool) $health['model_available'],
        'status' => !$health['online'] ? 'offline' : ($health['model_available'] ? 'online' : 'model_unavailable'),
    ]);
} catch (Throwable $exception) {
    error_log('TUGON AI health check failed: ' . $exception->getMessage());
    echo json_encode(['success' => true, 'online' => false, 'model_available' => false, 'status' => 'offline']);
}
