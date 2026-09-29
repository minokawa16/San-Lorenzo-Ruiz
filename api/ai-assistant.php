<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
ini_set('display_errors', '0');

function aiJson(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. CORS headers & OPTIONS preflight handling
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $allowedOrigins = array_filter(array_map('trim', explode(',', (string) (getenv('ALLOWED_ORIGINS') ?: ''))));
    $parsedHost = parse_url($origin, PHP_URL_HOST);
    $serverHost = $_SERVER['HTTP_HOST'] ?? '';
    if (empty($allowedOrigins) || in_array($origin, $allowedOrigins, true) || $parsedHost === $serverHost || str_ends_with((string)$parsedHost, 'vercel.app') || str_ends_with((string)$parsedHost, 'railway.app') || $parsedHost === 'localhost' || $parsedHost === '127.0.0.1') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Accept, X-CSRF-Token, X-Requested-With');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    aiJson(['success' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.'], 405);
}

try {
    require_once __DIR__ . '/../includes/session.php';
    require_once __DIR__ . '/../database/config.php';
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../includes/Logger.php';
    require_once __DIR__ . '/../services/AiAssistantService.php';

    if (!isLoggedIn() || empty($_SESSION['fully_authenticated'])) {
        aiJson(['success' => false, 'error' => 'AUTH_REQUIRED', 'message' => 'Please log in to use TUGON AI.'], 401);
    }
    requireValidCsrfToken();
    if (strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') {
        aiJson(['success' => false, 'error' => 'INVALID_CONTENT_TYPE', 'message' => 'Content-Type must be application/json.'], 415);
    }

    $rawInput = (string) file_get_contents('php://input');
    $rawLength = strlen($rawInput);
    if ($rawLength > 1024 * 1024) { // 1MB payload limit for AI assistant requests
        aiJson(['success' => false, 'error' => 'PAYLOAD_TOO_LARGE', 'message' => 'Your message is too large. Please ask a shorter question.'], 413);
    }
    if ($rawLength > 30000) {
        try {
            (new Logger())->warning('Large AI assistant payload', ['component' => 'ai', 'event' => 'ai.payload.large', 'bytes' => $rawLength, 'user_id' => (int) ($_SESSION['user_id'] ?? 0)]);
        } catch (Throwable $e) {}
    }

    $payload = json_decode($rawInput, true);
    if (!is_array($payload)) {
        aiJson(['success' => false, 'error' => 'INVALID_JSON', 'message' => 'Invalid JSON request.'], 400);
    }

    $staff = hasPermission('ai.staff.use') || hasPermission('ai.admin.use');
    if (!$staff && !hasPermission('ai.parishioner.use')) {
        aiJson(['success' => false, 'error' => 'FORBIDDEN', 'message' => 'Your account is not authorized to use TUGON AI.'], 403);
    }

    $caps = [
        'staff' => $staff,
        'admin' => hasPermission('ai.admin.use'),
        'records' => hasPermission('ai.search.records'),
        'reports' => hasPermission('ai.search.reports'),
        'feedback' => hasPermission('ai.review.feedback'),
    ];

    $service = new AiAssistantService($conn);
    if (($payload['action'] ?? '') === 'feedback') {
        if (empty($_SESSION['user_id'])) {
            aiJson(['success' => false, 'error' => 'UNAUTHORIZED', 'message' => 'Please log in to submit feedback.'], 401);
        }
        $service->saveFeedback((int) $_SESSION['user_id'], (string) ($payload['response_reference'] ?? ''), (string) ($payload['rating'] ?? ''), (string) ($payload['comments'] ?? ''));
        aiJson(['success' => true, 'message' => 'Feedback saved.']);
    }

    $mode = in_array(($payload['mode'] ?? 'chat'), ['chat', 'search', 'analytics'], true) ? $payload['mode'] : 'chat';
    $conversation = [];
    foreach (array_slice((array) ($payload['conversation'] ?? []), -6) as $turn) {
        if (!is_array($turn)) continue;
        $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $conversation[] = ['role' => $role, 'content' => mb_strimwidth((string) ($turn['content'] ?? ''), 0, 500, '')];
    }

    $response = $service->respond((int) $_SESSION['user_id'], $caps, (string) ($payload['message'] ?? ''), $mode, $conversation);
    aiJson($response);
} catch (InvalidArgumentException|DomainException $e) {
    aiJson(['success' => false, 'error' => 'VALIDATION_ERROR', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[TUGON AI API Error] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    try {
        (new Logger())->error('AI request failed', ['component' => 'ai', 'event' => 'ai.request.failed', 'exception' => get_class($e), 'message' => $e->getMessage()]);
    } catch (Throwable $logEx) {}
    aiJson(['success' => false, 'error' => 'INTERNAL_ERROR', 'message' => 'The assistant is temporarily unavailable. Please contact parish staff if your concern is urgent.'], 500);
}
