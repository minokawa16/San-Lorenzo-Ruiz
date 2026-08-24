<?php
/**
 * Compatibility handler for the retired legacy certificate subsystem.
 * Modern certificate requests use users/request-certificate.php and the
 * requests/request_documents/request_payments tables exclusively.
 */
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$route = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

if ($method === 'GET' || $method === 'HEAD') {
    $target = $route === 'request_form.php'
        ? '../users/request-certificate.php'
        : '../users/my-requests.php';
    header('Location: ' . $target, true, 302);
    exit;
}

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'success' => false,
    'message' => 'This legacy certificate endpoint has been retired. Submit certificate requests through the current request workflow.',
]);
exit;

