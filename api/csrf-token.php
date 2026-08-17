<?php
/**
 * Returns a current CSRF token to an authenticated same-origin client.
 * This lets long-lived chatbot pages recover after the one-hour token expiry.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

include __DIR__ . '/../includes/session.php';
include __DIR__ . '/../includes/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Your login session has expired.']);
    exit;
}

echo json_encode([
    'success' => true,
    'token' => generateCsrfToken(),
    'name' => csrfTokenName(),
]);

