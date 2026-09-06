<?php
/**
 * API Endpoint: POST /api/admin/requests/approve.php
 * 
 * Automated Workflow on Sacramental Request Approval:
 * 1. Transitions request status from PENDING (or submitted/processing) to APPROVED.
 * 2. Populates official Sacramental Records (sacramental_records_baptism, sacramental_records_marriage, sacramental_records_death).
 * 3. Inserts and locks scheduled event into Parish Calendar (schedule_events).
 * 4. Dispatches in-app notification to the parishioner.
 * 5. Executes within an atomic database transaction.
 */

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../../../includes/session.php';
require_once __DIR__ . '/../../../database/config.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../services/SacramentalApprovalService.php';

// Enforce admin privileges
requireAdmin();
requirePermission('requests.manage');

// Enforce POST method
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'METHOD_NOT_ALLOWED',
        'message' => 'Only POST requests are permitted.'
    ]);
    exit;
}

// Parse input (JSON or form-encoded)
$rawBody = file_get_contents('php://input');
$jsonBody = json_decode((string) $rawBody, true);
$input = is_array($jsonBody) ? $jsonBody : $_POST;

// Verify CSRF token
$csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '');
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'CSRF_INVALID',
        'message' => 'CSRF token validation failed or expired. Please refresh the page and try again.'
    ]);
    exit;
}

$requestId = intval($input['request_id'] ?? $input['id'] ?? 0);
if ($requestId <= 0) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => 'INVALID_REQUEST_ID',
        'message' => 'A valid request_id is required.'
    ]);
    exit;
}

$adminResponse = trim((string) ($input['admin_response'] ?? $input['remarks'] ?? ''));
$officiatingPriest = trim((string) ($input['officiating_priest'] ?? $input['minister'] ?? ''));
$actorUserId = intval($_SESSION['user_id'] ?? 0);

try {
    $service = new SacramentalApprovalService($conn);
    $result = $service->approveRequest($requestId, $actorUserId, [
        'admin_response' => $adminResponse,
        'officiating_priest' => $officiatingPriest
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $result['message'] ?? 'Sacramental request approved successfully.',
        'data' => $result
    ]);
    exit;
} catch (InvalidArgumentException | DomainException $e) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error' => 'BUSINESS_RULE_VIOLATION',
        'message' => $e->getMessage()
    ]);
    exit;
} catch (Throwable $e) {
    error_log("Error in /api/admin/requests/approve.php for request #{$requestId}: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'APPROVAL_FAILED',
        'message' => 'Failed to complete sacramental approval workflow: ' . $e->getMessage()
    ]);
    exit;
}
