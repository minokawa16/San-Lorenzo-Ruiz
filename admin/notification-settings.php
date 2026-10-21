<?php
/**
 * Secretary Notification & Schedule Reminders Settings
 * ----------------------------------------------------
 * Manage automated reminder thresholds, send times, delivery channels,
 * view upcoming schedule countdowns (next 7 days), and inspect delivery logs.
 * Includes interactive demo simulator tools (Skip to next day, Simulate new request).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../services/ScheduleReminderService.php';
require_once __DIR__ . '/../config/textbee.php';

requireLogin();

// Allow Secretary, Staff with permissions, and Administrators
$userId = (int)($_SESSION['user_id'] ?? 0);
$roles = userRoleKeys($userId, $conn);
if (!isAdmin() && !in_array('secretary', $roles, true) && !in_array('parish_staff', $roles, true) && !hasPermission('admin.access')) {
    header('Location: ' . getUserDashboardURL(), true, 302);
    exit;
}

$page_title = 'Notification Settings';
$admin_header_title = 'Notification Settings';
$admin_header_description = 'Automated schedule reminders, delivery channels, and alerts for parish staff & secretaries.';
$header_icon = 'fa-bell';

$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Notifications' => null
];

$success = '';
$error = '';
$simulatedDate = $_SESSION['demo_simulated_date'] ?? null;

// Handle Form Submissions & Demo Simulator Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = trim((string)($_POST['action'] ?? 'save_settings'));

    try {
        if ($action === 'save_settings') {
            $data = [
                'remind_3_days' => isset($_POST['remind_3_days']) ? 1 : 0,
                'remind_2_days' => isset($_POST['remind_2_days']) ? 1 : 0,
                'remind_1_day' => isset($_POST['remind_1_day']) ? 1 : 0,
                'remind_0_day' => isset($_POST['remind_0_day']) ? 1 : 0,
                'send_time' => trim((string)($_POST['send_time'] ?? '08:00')),
                'channel_in_app' => isset($_POST['channel_in_app']) ? 1 : 0,
                'channel_email' => isset($_POST['channel_email']) ? 1 : 0,
                'channel_sms' => isset($_POST['channel_sms']) ? 1 : 0,
                'stale_days_threshold' => (int)($_POST['stale_days_threshold'] ?? 3),
                'notify_new_request' => isset($_POST['notify_new_request']) ? 1 : 0,
                'notify_cancelled_request' => isset($_POST['notify_cancelled_request']) ? 1 : 0,
                'notify_conflicts' => isset($_POST['notify_conflicts']) ? 1 : 0,
                'notify_stale_requests' => isset($_POST['notify_stale_requests']) ? 1 : 0,
            ];

            // Save for current user or parish
            ScheduleReminderService::saveSettings($conn, $data, $userId);
            $success = 'Notification settings saved successfully.';
        } elseif ($action === 'run_now') {
            $res = ScheduleReminderService::processReminders($conn, $simulatedDate, true);
            $success = "Reminders executed! Checked {$res['checked_schedules']} schedules, sent {$res['reminders_sent']} reminder(s), superseded {$res['superseded_reminders']} older reminder(s).";
        } elseif ($action === 'skip_next_day') {
            // Advance simulation date by +1 day
            $currentSim = $simulatedDate ? new DateTime($simulatedDate) : new DateTime();
            $currentSim->modify('+1 day');
            $newDateStr = $currentSim->format('Y-m-d');
            $_SESSION['demo_simulated_date'] = $newDateStr;
            $simulatedDate = $newDateStr;

            // Process reminders for the new simulated day
            $res = ScheduleReminderService::processReminders($conn, $newDateStr, false);
            $success = "Demo advanced to {$newDateStr}! Reminders processed for the new day ({$res['reminders_sent']} sent, {$res['superseded_reminders']} superseded).";
        } elseif ($action === 'reset_demo_date') {
            unset($_SESSION['demo_simulated_date']);
            $simulatedDate = null;
            $success = "Simulation date reset to actual current date (" . date('M d, Y') . ").";
        } elseif ($action === 'simulate_new_request') {
            $simRes = ScheduleReminderService::simulateNewRequest($conn);
            if (!empty($simRes['ok'])) {
                $success = "Simulated new request {$simRes['reference_number']} ({$simRes['requester']}) created! Notification dispatched to Secretary.";
            } else {
                $error = $simRes['error'] ?? 'Failed to simulate request.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Automatically process reminders on page load to keep schedules & alerts fresh
$remDebug = ['checked_schedules' => 0, 'reminders_sent' => 0, 'debug_line' => ''];
try {
    $remDebug = ScheduleReminderService::processReminders($conn, $simulatedDate);
} catch (Throwable $e) {
    error_log('[NotificationSettingsReminderTrigger] ' . $e->getMessage());
}

// Load current settings
$settings = ScheduleReminderService::getSettings($conn, $userId);
$smsConfigured = isTextBeeConfigured();

// Load Upcoming Schedules (next 7 days)
$upcomingSchedules = ScheduleReminderService::getUpcomingSchedules($conn, 7, $simulatedDate);

// Load Delivery Logs
$deliveryLogs = ScheduleReminderService::getDeliveryLogs($conn, 15);

include __DIR__ . '/../templates/header.php';
?>

<div class="container-fluid px-3 px-md-4 py-3 parish-notification-settings-page">
    <!-- Header Title Banner -->
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 pb-2 border-bottom">
        <div>
            <h2 class="h4 mb-1 text-dark fw-bold" style="font-family: 'Playfair Display', Georgia, serif; color: #1e2d24;">
                <i class="fas fa-bell text-warning me-2"></i> Secretary Notification & Schedule Reminders
            </h2>
            <p class="text-muted small mb-0">
                Automated 3-2-1-0 day schedule reminders, delivery channels, and front-desk alerts.
                <?php if ($simulatedDate): ?>
                    <span class="badge bg-warning text-dark ms-2">
                        <i class="fas fa-flask me-1"></i> Demo Active: Simulating date <?php echo htmlspecialchars($simulatedDate); ?>
                    </span>
                <?php endif; ?>
            </p>
        </div>

        <!-- Simulator Toolbar -->
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <form method="POST" class="d-inline">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="skip_next_day">
                <button type="submit" class="btn btn-sm btn-outline-success d-inline-flex align-items-center gap-1 shadow-sm" title="Advance calendar by 1 day to simulate upcoming reminders">
                    <i class="fas fa-forward-step"></i> Skip to next day (demo)
                </button>
            </form>

            <form method="POST" class="d-inline">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="simulate_new_request">
                <button type="submit" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 shadow-sm" title="Simulate a parishioner submitting a new request">
                    <i class="fas fa-file-circle-plus"></i> Simulate a new request
                </button>
            </form>

            <form method="POST" class="d-inline">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="run_now">
                <button type="submit" class="btn btn-sm btn-dark d-inline-flex align-items-center gap-1 shadow-sm" title="Trigger schedule reminders immediately">
                    <i class="fas fa-play"></i> Run Reminders Now
                </button>
            </form>

            <?php if ($simulatedDate): ?>
                <form method="POST" class="d-inline">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="reset_demo_date">
                    <button type="submit" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1 shadow-sm" title="Reset date back to today">
                        <i class="fas fa-rotate-left"></i> Reset Demo Date
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Admin Debug Engine Status -->
    <?php if (!empty($remDebug['debug_line'])): ?>
        <div class="alert alert-info py-2 px-3 small d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 shadow-sm border-0 bg-info-subtle text-info-emphasis rounded-3">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-circle-info text-info fs-6"></i>
                <span><strong>Reminder Engine:</strong> <?php echo htmlspecialchars($remDebug['debug_line']); ?></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-white text-dark border">Server Date: <?php echo date('M d, Y'); ?></span>
                <span class="badge bg-success text-white">Asia/Manila (UTC+8)</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Alert Messages -->
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm" role="alert">
            <i class="fas fa-circle-check fs-5 text-success"></i>
            <div><?php echo htmlspecialchars($success); ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm" role="alert">
            <i class="fas fa-circle-exclamation fs-5 text-danger"></i>
            <div><?php echo htmlspecialchars($error); ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Left Column: Settings Configuration Form -->
        <div class="col-lg-7 col-xl-6">
            <form method="POST" action="">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="save_settings">

                <!-- 1. Schedule Reminder Thresholds Card -->
                <div class="card shadow-sm border-0 mb-4 rounded-3 overflow-hidden">
                    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-2">

                            <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 32px; height: 32px; background: #1e2d24;">
                                <i class="fas fa-clock" style="font-size: 14px;"></i>
                            </span>
                            <h5 class="card-title m-0 fw-bold fs-6 text-dark">Schedule Reminder Thresholds</h5>
                        </div>
                        <span class="badge bg-light text-dark border">Automated</span>
                    </div>
                    <div class="card-body p-3 p-md-4">
                        <p class="text-muted small mb-3">
                            Remind the secretary about approved schedules approaching within the next 3 days and on the morning of the event. Older reminders are automatically replaced as schedules get closer.
                        </p>

                        <!-- Threshold 3 days -->
                        <div class="d-flex align-items-center justify-content-between p-3 mb-2 rounded border bg-light-subtle" style="border-left: 4px solid #C89B3C !important;">
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <strong class="text-dark fs-6">3 Days Before</strong>
                                    <span class="badge rounded-pill" style="background: #FEF3C7; color: #92400E; font-size: 11px;">Gold Edge</span>
                                </div>
                                <div class="text-muted small mt-1">E.g., "In 3 days: Baptism — 9:00 AM · Juan Dela Cruz"</div>
                            </div>
                            <div class="form-check form-switch m-0 fs-5">
                                <input class="form-check-input" type="checkbox" role="switch" name="remind_3_days" id="remind_3_days" value="1" <?php echo !empty($settings['remind_3_days']) ? 'checked' : ''; ?>>
                            </div>
                        </div>

                        <!-- Threshold 2 days -->
                        <div class="d-flex align-items-center justify-content-between p-3 mb-2 rounded border bg-light-subtle" style="border-left: 4px solid #F97316 !important;">
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <strong class="text-dark fs-6">2 Days Before</strong>
                                    <span class="badge rounded-pill" style="background: #FFEDD5; color: #C2410C; font-size: 11px;">Orange Edge</span>
                                </div>
                                <div class="text-muted small mt-1">E.g., "In 2 days: Wedding — 10:00 AM · Santos Nuptials"</div>
                            </div>
                            <div class="form-check form-switch m-0 fs-5">
                                <input class="form-check-input" type="checkbox" role="switch" name="remind_2_days" id="remind_2_days" value="1" <?php echo !empty($settings['remind_2_days']) ? 'checked' : ''; ?>>
                            </div>
                        </div>

                        <!-- Threshold 1 day (Tomorrow) -->
                        <div class="d-flex align-items-center justify-content-between p-3 mb-2 rounded border bg-light-subtle" style="border-left: 4px solid #EF4444 !important;">
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <strong class="text-dark fs-6">1 Day Before (Tomorrow)</strong>
                                    <span class="badge rounded-pill" style="background: #FEE2E2; color: #B91C1C; font-size: 11px;">Red Edge</span>
                                </div>
                                <div class="text-muted small mt-1">E.g., "Tomorrow: Confirmation Mass — 4:00 PM · Bea Mendoza"</div>
                            </div>
                            <div class="form-check form-switch m-0 fs-5">
                                <input class="form-check-input" type="checkbox" role="switch" name="remind_1_day" id="remind_1_day" value="1" <?php echo !empty($settings['remind_1_day']) ? 'checked' : ''; ?>>
                            </div>
                        </div>

                        <!-- Threshold 0 day (Today) -->
                        <div class="d-flex align-items-center justify-content-between p-3 rounded border bg-light-subtle" style="border-left: 4px solid #DC2626 !important;">
                            <div>
                                <div class="d-flex align-items-center gap-2">
                                    <strong class="text-dark fs-6">On the Day (Today)</strong>
                                    <span class="badge rounded-pill text-white" style="background: #DC2626; font-size: 11px;">Solid Red Edge</span>
                                </div>
                                <div class="text-muted small mt-1">E.g., "Today: Funeral Mass — 1:00 PM · Dela Cruz Family"</div>
                            </div>
                            <div class="form-check form-switch m-0 fs-5">
                                <input class="form-check-input" type="checkbox" role="switch" name="remind_0_day" id="remind_0_day" value="1" <?php echo !empty($settings['remind_0_day']) ? 'checked' : ''; ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Send Time & Delivery Channels Card -->
                <div class="card shadow-sm border-0 mb-4 rounded-3 overflow-hidden">
                    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center gap-2">
                        <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 32px; height: 32px; background: #C89B3C;">
                            <i class="fas fa-paper-plane" style="font-size: 14px;"></i>
                        </span>
                        <h5 class="card-title m-0 fw-bold fs-6 text-dark">Send Time & Delivery Channels</h5>
                    </div>
                    <div class="card-body p-3 p-md-4">
                        <!-- Send Time Option -->
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark fs-6 d-flex align-items-center gap-2 mb-2">
                                Daily Send Time <small class="text-muted fw-normal">(Morning reminder run)</small>
                            </label>
                            <div class="row g-2">
                                <?php foreach (['07:00' => '7:00 AM', '08:00' => '8:00 AM (Default)', '09:00' => '9:00 AM'] as $tVal => $tLabel): ?>
                                    <div class="col-4">
                                        <label class="d-block text-center p-2 rounded border cursor-pointer <?php echo ($settings['send_time'] === $tVal) ? 'border-success bg-success-subtle fw-bold text-success' : 'bg-white text-muted'; ?>" style="cursor: pointer;">
                                            <input type="radio" name="send_time" value="<?php echo $tVal; ?>" class="form-check-input me-1" <?php echo ($settings['send_time'] === $tVal) ? 'checked' : ''; ?>>
                                            <?php echo $tLabel; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <hr class="my-3 opacity-25">

                        <!-- Channels Toggles -->
                        <label class="form-label fw-bold text-dark fs-6 mb-2">Notification Channels</label>
                        
                        <!-- In-App -->
                        <div class="d-flex align-items-center justify-content-between p-2 mb-2 rounded border bg-light-subtle">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-bell text-warning fs-5"></i>
                                <div>
                                    <strong class="text-dark small d-block">In-App Notification Bell</strong>
                                    <span class="text-muted" style="font-size: 11px;">Displays in top navigation bell with shake & toast</span>
                                </div>
                            </div>
                            <div class="form-check form-switch m-0 fs-5">
                                <input class="form-check-input" type="checkbox" role="switch" name="channel_in_app" id="channel_in_app" value="1" <?php echo !empty($settings['channel_in_app']) ? 'checked' : ''; ?>>
                            </div>
                        </div>

                        <!-- Email -->
                        <div class="d-flex align-items-center justify-content-between p-2 mb-2 rounded border bg-light-subtle">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-envelope text-primary fs-5"></i>
                                <div>
                                    <strong class="text-dark small d-block">Staff Email Dispatch</strong>
                                    <span class="text-muted" style="font-size: 11px;">Institutional email alert with schedule details link</span>
                                </div>
                            </div>
                            <div class="form-check form-switch m-0 fs-5">
                                <input class="form-check-input" type="checkbox" role="switch" name="channel_email" id="channel_email" value="1" <?php echo !empty($settings['channel_email']) ? 'checked' : ''; ?>>
                            </div>
                        </div>

                        <!-- SMS -->
                        <div class="d-flex align-items-center justify-content-between p-2 rounded border bg-light-subtle <?php echo !$smsConfigured ? 'opacity-75' : ''; ?>">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-comment-sms text-success fs-5"></i>
                                <div>
                                    <div class="d-flex align-items-center gap-2">
                                        <strong class="text-dark small">SMS Text Alert</strong>
                                        <?php if ($smsConfigured): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 10px;">TextBee Gateway Ready</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary text-white" style="font-size: 10px;">Provider Not Configured</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-muted" style="font-size: 11px;">
                                        <?php echo $smsConfigured ? 'Direct mobile notification for urgent daily schedules' : 'SMS provider not configured in .env. Setup TEXTBEE_API_KEY to enable.'; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="form-check form-switch m-0 fs-5">
                                <input class="form-check-input" type="checkbox" role="switch" name="channel_sms" id="channel_sms" value="1" 
                                    <?php echo (!empty($settings['channel_sms']) && $smsConfigured) ? 'checked' : ''; ?>
                                    <?php echo !$smsConfigured ? 'disabled title="SMS gateway not configured"' : ''; ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. Additional Alerts Card (Other Updates) -->
                <div class="card shadow-sm border-0 mb-4 rounded-3 overflow-hidden">
                    <div class="card-header bg-white py-3 border-bottom d-flex align-items-center gap-2">
                        <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 32px; height: 32px; background: #0F5132;">
                            <i class="fas fa-list-check" style="font-size: 14px;"></i>
                        </span>
                        <h5 class="card-title m-0 fw-bold fs-6 text-dark">Other Secretary Alerts</h5>
                    </div>
                    <div class="card-body p-3 p-md-4">
                        <p class="text-muted small mb-3">Grouped under "Other updates" in the bell dropdown.</p>

                        <!-- New Request -->
                        <div class="d-flex align-items-center justify-content-between p-2 mb-2 rounded border bg-light-subtle">
                            <div>
                                <strong class="text-dark small d-block">New Request Submissions</strong>
                                <span class="text-muted" style="font-size: 11px;">Notify secretary immediately when parishioner submits a service request</span>
                            </div>
                            <div class="form-check form-switch m-0 fs-5">
                                <input class="form-check-input" type="checkbox" role="switch" name="notify_new_request" id="notify_new_request" value="1" <?php echo !empty($settings['notify_new_request']) ? 'checked' : ''; ?>>
                            </div>
                        </div>

                        <!-- Cancelled Request -->
                        <div class="d-flex align-items-center justify-content-between p-2 mb-2 rounded border bg-light-subtle">
                            <div>
                                <strong class="text-dark small d-block">Cancelled or Rescheduled Requests</strong>
                                <span class="text-muted" style="font-size: 11px;">Alert when a booking is cancelled or time changes</span>
                            </div>
                            <div class="form-check form-switch m-0 fs-5">
                                <input class="form-check-input" type="checkbox" role="switch" name="notify_cancelled_request" id="notify_cancelled_request" value="1" <?php echo !empty($settings['notify_cancelled_request']) ? 'checked' : ''; ?>>
                            </div>
                        </div>

                        <!-- Booking Conflicts -->
                        <div class="d-flex align-items-center justify-content-between p-2 mb-2 rounded border bg-light-subtle">
                            <div>
                                <strong class="text-dark small d-block">Booking Conflict Warnings</strong>
                                <span class="text-muted" style="font-size: 11px;">Alert when two schedule events or reservations overlap</span>
                            </div>
                            <div class="form-check form-switch m-0 fs-5">
                                <input class="form-check-input" type="checkbox" role="switch" name="notify_conflicts" id="notify_conflicts" value="1" <?php echo !empty($settings['notify_conflicts']) ? 'checked' : ''; ?>>
                            </div>
                        </div>

                        <!-- Stale Requests -->
                        <div class="d-flex align-items-center justify-content-between p-2 rounded border bg-light-subtle">
                            <div>
                                <strong class="text-dark small d-block">Stale Pending Requests Alert</strong>
                                <div class="d-flex align-items-center gap-2 mt-1">
                                    <span class="text-muted" style="font-size: 11px;">Alert if pending for more than</span>
                                    <input type="number" name="stale_days_threshold" value="<?php echo htmlspecialchars((string)($settings['stale_days_threshold'] ?? 3)); ?>" min="1" max="30" class="form-control form-control-sm text-center" style="width: 60px; height: 26px; padding: 2px;">
                                    <span class="text-muted" style="font-size: 11px;">days</span>
                                </div>
                            </div>
                            <div class="form-check form-switch m-0 fs-5">
                                <input class="form-check-input" type="checkbox" role="switch" name="notify_stale_requests" id="notify_stale_requests" value="1" <?php echo !empty($settings['notify_stale_requests']) ? 'checked' : ''; ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Save Button -->
                <div class="text-end mb-4">
                    <button type="submit" class="btn btn-success px-4 py-2 fw-bold shadow-sm" style="background: #1e2d24; border-color: #1e2d24;">
                        <i class="fas fa-floppy-disk me-1"></i> Save Notification Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- Right Column: Upcoming Schedules & Delivery Log -->
        <div class="col-lg-5 col-xl-6">
            <!-- 1. Upcoming Schedules Card (Next 7 Days with Countdowns) -->
            <div class="card shadow-sm border-0 mb-4 rounded-3 overflow-hidden">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 32px; height: 32px; background: #0F5132;">
                            <i class="fas fa-calendar-days" style="font-size: 14px;"></i>
                        </span>
                        <h5 class="card-title m-0 fw-bold fs-6 text-dark">Upcoming Schedules (Next 7 Days)</h5>
                    </div>
                    <a href="manage-calendar.php" class="btn btn-sm btn-link text-decoration-none p-0 text-success fw-bold" style="font-size: 12px;">
                        Calendar &rarr;
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($upcomingSchedules)): ?>
                        <div class="list-group list-group-flush" style="max-height: 380px; overflow-y: auto;">
                            <?php foreach ($upcomingSchedules as $uSched): 
                                $badgeStyle = match ($uSched['urgency']) {
                                    'today' => 'background: #DC2626; color: #FFFFFF;',
                                    'tomorrow' => 'background: #EF4444; color: #FFFFFF;',
                                    'in_2_days' => 'background: #F97316; color: #FFFFFF;',
                                    'in_3_days' => 'background: #C89B3C; color: #FFFFFF;',
                                    default => 'background: #64748B; color: #FFFFFF;'
                                };
                                $borderEdge = match ($uSched['urgency']) {
                                    'today' => 'border-left: 4px solid #DC2626 !important;',
                                    'tomorrow' => 'border-left: 4px solid #EF4444 !important;',
                                    'in_2_days' => 'border-left: 4px solid #F97316 !important;',
                                    'in_3_days' => 'border-left: 4px solid #C89B3C !important;',
                                    default => 'border-left: 4px solid #94A3B8 !important;'
                                };
                            ?>
                                <div class="list-group-item p-3 d-flex align-items-start justify-content-between gap-3 border-bottom" style="<?php echo $borderEdge; ?>">
                                    <div class="flex-grow-1 overflow-hidden">
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="badge rounded-pill fw-bold" style="<?php echo $badgeStyle; ?> font-size: 10px; padding: 3px 8px;">
                                                <?php echo htmlspecialchars($uSched['countdown_label']); ?>
                                            </span>
                                            <strong class="text-dark text-truncate" style="font-size: 13.5px;">
                                                <?php echo htmlspecialchars($uSched['service_label']); ?>
                                            </strong>
                                        </div>
                                        <div class="text-muted small">
                                            <i class="far fa-clock me-1"></i>
                                            <?php echo !empty($uSched['start_time']) ? (strtotime($uSched['start_time']) ? date('g:i A', strtotime($uSched['start_time'])) : htmlspecialchars($uSched['start_time'])) : 'TBD'; ?>
                                            &nbsp;·&nbsp;
                                            <i class="far fa-user me-1"></i><?php echo htmlspecialchars($uSched['requester_name']); ?>
                                        </div>
                                        <div class="text-muted mt-1" style="font-size: 11px;">
                                            <i class="fas fa-location-dot me-1 text-secondary"></i>
                                            <?php echo htmlspecialchars($uSched['location'] ?? 'Main Church'); ?>
                                            &nbsp;·&nbsp;
                                            <?php echo date('M d, Y', strtotime($uSched['event_date'])); ?>
                                        </div>
                                    </div>
                                    <div class="flex-shrink-0">
                                        <?php if (!empty($uSched['request_id']) || (!empty($uSched['source_id']) && ($uSched['source_type'] ?? '') === 'request')): ?>
                                            <a href="request-workflow.php?id=<?php echo (int)($uSched['request_id'] ?? $uSched['source_id']); ?>" class="btn btn-sm btn-outline-dark" style="font-size: 11px; padding: 2px 8px;">
                                                View Request
                                            </a>
                                        <?php else: ?>
                                            <a href="manage-calendar.php?event=<?php echo (int)($uSched['schedule_id'] ?? $uSched['source_id']); ?>" class="btn btn-sm btn-outline-dark" style="font-size: 11px; padding: 2px 8px;">
                                                View Event
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 px-3 text-muted">
                            <i class="far fa-calendar-check mb-2" style="font-size: 28px; color: #94A3B8;"></i>
                            <p class="mb-0 small">No approved schedules booked in the next 7 days.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 2. Delivery Log Card -->
            <div class="card shadow-sm border-0 mb-4 rounded-3 overflow-hidden">
                <div class="card-header bg-white py-3 border-bottom d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width: 32px; height: 32px; background: #334155;">
                            <i class="fas fa-file-waveform" style="font-size: 14px;"></i>
                        </span>
                        <h5 class="card-title m-0 fw-bold fs-6 text-dark">Reminder Delivery Log</h5>
                    </div>
                    <span class="badge bg-light text-muted border">Idempotent Log</span>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($deliveryLogs)): ?>
                        <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                            <table class="table table-hover align-middle mb-0" style="font-size: 12px;">
                                <thead class="table-light text-muted" style="font-size: 11px;">
                                    <tr>
                                        <th class="ps-3">Schedule</th>
                                        <th>Threshold</th>
                                        <th>Channel</th>
                                        <th>Sent At</th>
                                        <th class="pe-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($deliveryLogs as $log): 
                                        $thresholdBadge = match ((int)$log['days_before']) {
                                            0 => '<span class="badge text-white" style="background: #DC2626;">Today</span>',
                                            1 => '<span class="badge text-white" style="background: #EF4444;">Tomorrow</span>',
                                            2 => '<span class="badge text-white" style="background: #F97316;">2 Days</span>',
                                            3 => '<span class="badge text-white" style="background: #C89B3C;">3 Days</span>',
                                            default => '<span class="badge bg-secondary">' . (int)$log['days_before'] . 'd</span>'
                                        };
                                    ?>
                                        <tr>
                                            <td class="ps-3 fw-bold text-dark text-truncate" style="max-width: 160px;">
                                                <?php echo htmlspecialchars($log['schedule_title'] ?? ('Event #' . $log['schedule_id'])); ?>
                                            </td>
                                            <td><?php echo $thresholdBadge; ?></td>
                                            <td>
                                                <span class="badge bg-light text-dark border">
                                                    <?php echo htmlspecialchars(strtoupper($log['channel'])); ?>
                                                </span>
                                            </td>
                                            <td class="text-muted" style="white-space: nowrap;">
                                                <?php echo date('M d, g:i A', strtotime($log['sent_at'])); ?>
                                            </td>
                                            <td class="pe-3">
                                                <span class="badge bg-success-subtle text-success">
                                                    <i class="fas fa-check me-1"></i> <?php echo htmlspecialchars($log['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 px-3 text-muted">
                            <i class="far fa-folder-open mb-2" style="font-size: 24px; color: #94A3B8;"></i>
                            <p class="mb-0 small">No reminders recorded yet in reminder_log.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
