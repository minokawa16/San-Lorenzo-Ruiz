<?php
/**
 * Automated Schedule Reminders Runner Endpoint
 * --------------------------------------------
 * Triggered by scheduled cron jobs (e.g., Vercel Cron at 0 0 * * * = 8:00 AM Asia/Manila)
 * or system monitoring tools.
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Manila');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../services/ScheduleReminderService.php';

try {
    @$conn->query("SET time_zone = '+08:00'");
    $results = ScheduleReminderService::processReminders($conn);

    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get(),
        'checked_schedules' => $results['checked_schedules'],
        'in_next_3_days' => $results['in_next_3_days'],
        'reminders_sent' => $results['reminders_sent'],
        'superseded_reminders' => $results['superseded_reminders'],
        'debug' => $results['debug_line'] ?? '',
        'errors' => $results['errors'] ?? []
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
