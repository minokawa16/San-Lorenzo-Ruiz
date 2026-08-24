<?php

/** Shared in-session and JSON action notification helpers. */
function normalizeActionNotificationType($type) {
    $type = strtolower(trim((string) $type));
    $aliases = [
        'ok' => 'success', 'danger' => 'error', 'failed' => 'error',
        'failure' => 'error', 'notice' => 'info', 'primary' => 'info',
        'secondary' => 'info',
    ];
    $type = $aliases[$type] ?? $type;
    return in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
}

function queueActionNotification($message, $type = 'info', $title = '') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $message = trim((string) $message);
    if ($message === '') {
        return;
    }
    if (!isset($_SESSION['action_notifications']) || !is_array($_SESSION['action_notifications'])) {
        $_SESSION['action_notifications'] = [];
    }
    $_SESSION['action_notifications'][] = [
        'type' => normalizeActionNotificationType($type),
        'message' => $message,
        'title' => trim((string) $title),
    ];
}

function consumeActionNotifications() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $notifications = $_SESSION['action_notifications'] ?? [];
    unset($_SESSION['action_notifications']);
    return array_values(array_filter((array) $notifications, function($item) {
        return is_array($item) && trim((string) ($item['message'] ?? '')) !== '';
    }));
}

function redirectWithNotification($location, $message, $type = 'success', $title = '') {
    queueActionNotification($message, $type, $title);
    header('Location: ' . $location, true, 303);
    exit;
}

function actionResponse($success, $message, $type = null, $extra = []) {
    $success = (bool) $success;
    $type = $type !== null ? normalizeActionNotificationType($type) : ($success ? 'success' : 'error');
    return array_merge([
        'success' => $success,
        'ok' => $success,
        'status' => $success ? 'success' : 'error',
        'type' => $type,
        'message' => (string) $message,
    ], (array) $extra);
}

function sendJsonActionResponse($success, $message, $type = null, $extra = [], $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode(actionResponse($success, $message, $type, $extra));
    exit;
}
