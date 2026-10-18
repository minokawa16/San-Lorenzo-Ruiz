<?php
/**
 * Daily Scheduled Job for Schedule Reminders & Parish Secretary Alerts
 * --------------------------------------------------------------------
 * Can be executed via Cron, Windows Task Scheduler, or CLI:
 *   php bin/schedule-reminders-cron.php
 *   php bin/schedule-reminders-cron.php --force
 *   php bin/schedule-reminders-cron.php --date=2026-10-22
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/services/ScheduleReminderService.php';

// Parse CLI arguments
$options = getopt('', ['force', 'date:']);
$isForce = isset($options['force']);
$targetDate = !empty($options['date']) ? trim((string)$options['date']) : null;

echo "[" . date('Y-m-d H:i:s') . "] Starting Parish Schedule Reminder Job...\n";

// Check configured send time unless --force
$settings = ScheduleReminderService::getSettings($conn);
$configuredTime = $settings['send_time'] ?? '08:00';
$currentHour = date('H:00');

if (!$isForce && $targetDate === null) {
    // If not matching configured hour, notify and exit unless forced
    if ($currentHour !== $configuredTime) {
        echo "[" . date('Y-m-d H:i:s') . "] Current hour ({$currentHour}) does not match configured send time ({$configuredTime}). Skipping (use --force to override).\n";
        exit(0);
    }
}

// 1. Process 3, 2, 1, 0 days schedule reminders
$reminderResults = ScheduleReminderService::processReminders($conn, $targetDate, $isForce);
echo "[" . date('Y-m-d H:i:s') . "] Schedule Reminders Processed for date " . ($targetDate ?: date('Y-m-d')) . ":\n";
echo "  - Checked schedules: {$reminderResults['checked_schedules']}\n";
echo "  - Reminders sent: {$reminderResults['reminders_sent']}\n";
echo "  - Superseded older reminders: {$reminderResults['superseded_reminders']}\n";
if (!empty($reminderResults['errors'])) {
    echo "  - Errors logged: " . implode('; ', $reminderResults['errors']) . "\n";
}

// 2. Check stale pending requests (> 3 days)
$staleResults = ScheduleReminderService::checkStalePendingRequests($conn, (int)($settings['stale_days_threshold'] ?? 3));
echo "[" . date('Y-m-d H:i:s') . "] Stale Pending Requests Alert: " . count($staleResults) . " alert(s) dispatched.\n";

// 3. Check booking conflicts
$conflictResults = ScheduleReminderService::checkBookingConflicts($conn);
echo "[" . date('Y-m-d H:i:s') . "] Booking Conflicts Checked: " . count($conflictResults) . " conflict(s) detected.\n";

echo "[" . date('Y-m-d H:i:s') . "] Reminder job completed successfully.\n";
