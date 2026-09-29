<?php
/**
 * Parish Schedule Availability & Conflict Check API Endpoint
 * GET /api/schedule/check?date=YYYY-MM-DD&time=HH:MM&excludeId=123&location=Main+Church
 * Returns { available: boolean, conflicts: [...], suggestions: [...] }
 */

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../database/config.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../services/ScheduleConflictService.php';

if (!defined('CLI_TEST_RUNNING')) {
    requireLogin();
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$params = $method === 'POST' ? array_merge($_GET, $_POST) : $_GET;

$date = trim((string) ($params['date'] ?? ''));
$time = trim((string) ($params['time'] ?? ''));
$location = trim((string) ($params['location'] ?? 'Main Church'));
$excludeId = intval($params['excludeId'] ?? ($params['exclude_id'] ?? 0));
$scope = trim((string) ($params['scope'] ?? ''));
$action = trim((string) ($params['action'] ?? ''));

if ($date === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'available' => false,
        'message' => 'Please provide a valid schedule date (YYYY-MM-DD).'
    ]);
    exit;
}

$normDate = ScheduleConflictService::normalizeDate($date);
$service = new ScheduleConflictService($conn);
$options = [
    'exclude_request_id' => $excludeId,
    'scope' => $scope !== '' ? $scope : (defined('CONFLICT_SCOPE') ? CONFLICT_SCOPE : 'calendar')
];

// If no time is specified, or action is occupied_slots: return the full day availability snapshot
if ($time === '' || $action === 'occupied_slots') {
    $occupied = $service->getOccupiedSlots($normDate, $location !== '' ? $location : null, $options);
    $suggestions = $service->getAvailableSuggestions($normDate, $location !== '' ? $location : null, $options);
    $occupiedTimes = array_values(array_unique(array_column($occupied, 'time')));

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'available' => true,
        'date' => $normDate,
        'location' => $location,
        'occupied_times' => $occupiedTimes,
        'conflicts' => $occupied,
        'slots' => $occupied,
        'suggestions' => $suggestions,
        'count' => count($occupiedTimes)
    ]);
    exit;
}

// Evaluate specific date and time slot
$result = $service->checkConflict($normDate, $time, $location !== '' ? $location : 'Main Church', $options);

// Status code: 409 if conflict/occupied (unless client explicitly requests status 200 via suppress_409)
$suppress409 = isset($params['status_200']) || isset($params['suppress_409']);
$statusCode = ($result['available'] || $suppress409) ? 200 : 409;
http_response_code($statusCode);

echo json_encode(array_merge([
    'success' => true,
    'date' => $normDate,
    'time' => ScheduleConflictService::normalizeTime($time),
    'location' => $location
], $result));
exit;
