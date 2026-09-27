<?php
/**
 * Schedule Conflict Check API Endpoint
 * Provides client-side real-time double-booking checking and occupied slot lookup.
 */
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/ScheduleConflictService.php';

if (!defined('CLI_TEST_RUNNING')) {
    requireLogin();
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$params = $method === 'POST' ? array_merge($_GET, $_POST) : $_GET;

$action = trim((string) ($params['action'] ?? 'check'));
$date = trim((string) ($params['date'] ?? ''));
$time = trim((string) ($params['time'] ?? ''));
$location = trim((string) ($params['location'] ?? ''));
$bufferMinutes = isset($params['buffer_minutes']) ? intval($params['buffer_minutes']) : ScheduleConflictService::BUFFER_MINUTES_DEFAULT;
$exactMatch = !isset($params['exact_match']) || filter_var($params['exact_match'], FILTER_VALIDATE_BOOLEAN);

if ($date === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please provide a valid schedule date.'
    ]);
    exit;
}

$service = new ScheduleConflictService($conn);

if ($action === 'occupied_slots' || ($time === '' && $action !== 'check')) {
    $occupied = $service->getOccupiedSlots($date, $location !== '' ? $location : null, [
        'buffer_minutes' => $bufferMinutes,
        'exact_match' => $exactMatch
    ]);
    $occupiedTimes = array_values(array_unique(array_column($occupied, 'time')));

    echo json_encode([
        'success' => true,
        'date' => ScheduleConflictService::normalizeDate($date),
        'location' => $location,
        'occupied_times' => $occupiedTimes,
        'slots' => $occupied,
        'count' => count($occupiedTimes)
    ]);
    exit;
}

// Action: check specific date, time, and location
if ($time === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please provide a schedule time to evaluate.'
    ]);
    exit;
}

$result = $service->checkConflict($date, $time, $location !== '' ? $location : 'Main Church', [
    'exact_match' => $exactMatch,
    'buffer_minutes' => $bufferMinutes
]);

$statusCode = $result['has_conflict'] ? 409 : 200;
http_response_code($statusCode);

echo json_encode(array_merge([
    'success' => true,
    'date' => ScheduleConflictService::normalizeDate($date),
    'time' => ScheduleConflictService::normalizeTime($time),
    'location' => $location
], $result));
exit;
