<?php
/**
 * Reminders & Alerts
 * ------------------
 * One simple list of scheduled requests grouped by day (Asia/Manila), with a
 * small hidden "Reminder settings" panel. Uses the same data source as the
 * notification bell (ScheduleReminderService::getUpcomingSchedules).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../services/ScheduleReminderService.php';

requireLogin();

$userId = (int)($_SESSION['user_id'] ?? 0);
$roles = userRoleKeys($userId, $conn);
if (!isAdmin() && !in_array('secretary', $roles, true) && !in_array('parish_staff', $roles, true) && !hasPermission('admin.access')) {
    header('Location: ' . getUserDashboardURL(), true, 302);
    exit;
}

date_default_timezone_set('Asia/Manila');

$page_title = 'Reminders & Alerts';
$admin_header_title = 'Reminders & Alerts';
$admin_header_description = 'Scheduled requests coming up, listed by day.';
$header_icon = 'fa-bell';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Reminders & Alerts' => null
];

$saved = false;
$error = '';
$showSettings = false;

// Load current settings first so unchanged options are preserved on save
$settings = ScheduleReminderService::getSettings($conn, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $showSettings = true;
    try {
        $data = [
            'remind_3_days' => isset($_POST['remind_3_days']) ? 1 : 0,
            'remind_2_days' => isset($_POST['remind_2_days']) ? 1 : 0,
            'remind_1_day' => isset($_POST['remind_1_day']) ? 1 : 0,
            'remind_0_day' => isset($_POST['remind_0_day']) ? 1 : 0,
            'channel_email' => isset($_POST['channel_email']) ? 1 : 0,
            // Not shown on this page: keep existing values
            'send_time' => '08:00',
            'channel_in_app' => !empty($settings['channel_in_app']) ? 1 : 0,
            'channel_sms' => !empty($settings['channel_sms']) ? 1 : 0,
            'stale_days_threshold' => (int)($settings['stale_days_threshold'] ?? 3),
            'notify_new_request' => !empty($settings['notify_new_request']) ? 1 : 0,
            'notify_cancelled_request' => !empty($settings['notify_cancelled_request']) ? 1 : 0,
            'notify_conflicts' => !empty($settings['notify_conflicts']) ? 1 : 0,
            'notify_stale_requests' => !empty($settings['notify_stale_requests']) ? 1 : 0,
        ];
        $saved = ScheduleReminderService::saveSettings($conn, $data, $userId);
        if (!$saved) {
            $error = 'Could not save settings. Please try again.';
        }
    } catch (Throwable $e) {
        error_log('[NotificationSettingsSave] ' . $e->getMessage());
        $error = 'Could not save settings. Please try again.';
    }
}

// Keep reminders fresh; debug output goes to the error log only
try {
    $run = ScheduleReminderService::processReminders($conn);
    if (!empty($run['debug_line'])) {
        error_log($run['debug_line']);
    }
} catch (Throwable $e) {
    error_log('[NotificationSettingsReminderTrigger] ' . $e->getMessage());
}

$settings = ScheduleReminderService::getSettings($conn, $userId);

// Range selector
$range = (int)($_GET['range'] ?? 7);
if (!in_array($range, [7, 14, 30], true)) {
    $range = 7;
}

// Always load 30 days, then filter, so summary counts are independent of the range
$all = ScheduleReminderService::getUpcomingSchedules($conn, 30);

$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
$items = [];
$countToday = 0;
$countTomorrow = 0;
$countWeek = 0;
foreach ($all as $s) {
    $d = (int)($s['days_diff'] ?? 0);
    if ($d < 0) continue;
    if ($d === 0) $countToday++;
    if ($d === 1) $countTomorrow++;
    if ($d <= 7) $countWeek++;
    if ($d <= $range) {
        $ts = strtotime((string)($s['start_time'] ?? '')) ?: PHP_INT_MAX;
        $s['_sort'] = $ts;
        $items[] = $s;
    }
}
usort($items, static function ($a, $b) {
    return [$a['event_date'], $a['_sort']] <=> [$b['event_date'], $b['_sort']];
});

$groups = [];
foreach ($items as $s) {
    $groups[$s['event_date']][] = $s;
}

$prettyService = static function (string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return 'Parish Service';
    return ucwords(str_replace(['_', '-'], ' ', $raw));
};

$statusMeta = static function (string $status): array {
    $s = strtolower(trim($status));
    if ($s === 'approved' || $s === 'upcoming' || $s === 'confirmed') return ['Approved', 'approved'];
    if ($s === 'processing' || $s === 'in_progress') return ['Processing', 'processing'];
    return ['Pending', 'pending'];
};

include __DIR__ . '/../templates/header.php';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

<style>
.ra-page { --ra-green:#1d3a2a; --ra-gold:#b98a2e; --ra-cream:#f5f2ea; --ra-card:#ffffff; --ra-text:#1d2a22; --ra-muted:#5f6b63; --ra-border:#e5dfd0;
    --ra-today:#c62828; --ra-tomorrow:#e67e22; --ra-soon:#b98a2e; --ra-later:#2e7d4f;
    font-family:'Figtree',system-ui,sans-serif; font-size:16px; color:var(--ra-text); background:var(--ra-cream);
    padding:24px; border-radius:16px; }
@media (prefers-color-scheme: dark) { .ra-page { --ra-card:#18261e; --ra-text:#ece8dc; --ra-muted:#a9b3ab; --ra-border:#2c3d33; --ra-cream:#101a14; } }
[data-theme="dark"] .ra-page, .dark-mode .ra-page, body.dark .ra-page { --ra-card:#18261e; --ra-text:#ece8dc; --ra-muted:#a9b3ab; --ra-border:#2c3d33; --ra-cream:#101a14; }
.ra-page h1 { font-family:'Playfair Display',Georgia,serif; font-size:2rem; color:var(--ra-green); margin:0 0 4px; }
@media (prefers-color-scheme: dark) { .ra-page h1 { color:#e8d9a8; } }
.ra-sub { color:var(--ra-muted); margin:0; }
.ra-head { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:20px; }
.ra-link-btn { background:transparent; border:1px solid var(--ra-border); color:var(--ra-text); border-radius:999px; padding:8px 16px; font-size:15px; font-weight:600; cursor:pointer; }
.ra-link-btn:hover { border-color:var(--ra-gold); color:var(--ra-gold); }
.ra-summary { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:20px; }
.ra-stat { background:var(--ra-card); border:1px solid var(--ra-border); border-radius:16px; padding:18px 20px; }
.ra-stat .n { font-family:'Playfair Display',Georgia,serif; font-size:2.4rem; line-height:1; color:var(--ra-green); }
@media (prefers-color-scheme: dark) { .ra-stat .n { color:#e8d9a8; } }
.ra-stat .l { color:var(--ra-muted); font-weight:600; margin-top:6px; }
.ra-stat.today .n { color:var(--ra-today); } .ra-stat.tomorrow .n { color:var(--ra-tomorrow); }
.ra-seg { display:inline-flex; background:var(--ra-card); border:1px solid var(--ra-border); border-radius:999px; padding:4px; margin-bottom:20px; flex-wrap:wrap; }
.ra-seg a { padding:8px 18px; border-radius:999px; color:var(--ra-muted); text-decoration:none; font-weight:600; }
.ra-seg a.active { background:var(--ra-green); color:#fff; }
.ra-day { margin-bottom:26px; }
.ra-day-head { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-bottom:10px; }
.ra-tag { color:#fff; font-weight:700; padding:4px 14px; border-radius:999px; font-size:15px; letter-spacing:.04em; text-transform:uppercase; }
.ra-day-title { font-family:'Playfair Display',Georgia,serif; font-size:1.35rem; font-weight:700; }
.ra-day-meta { color:var(--ra-muted); }
.ra-count { margin-left:auto; color:var(--ra-muted); font-weight:600; }
.ra-card { display:flex; align-items:center; gap:20px; background:var(--ra-card); border:1px solid var(--ra-border); border-left:6px solid var(--edge); border-radius:16px; padding:16px 20px; margin-bottom:10px; }
.ra-time { min-width:110px; font-family:'Playfair Display',Georgia,serif; font-size:1.5rem; font-weight:700; color:var(--edge); }
.ra-body { flex:1; min-width:0; }
.ra-service { font-weight:700; font-size:1.15rem; margin-bottom:2px; }
.ra-meta { color:var(--ra-muted); }
.ra-badge { display:inline-block; font-size:14px; font-weight:700; padding:3px 12px; border-radius:999px; margin-top:6px; }
.ra-badge.pending { background:#fff3cd; color:#8a6100; } .ra-badge.approved { background:#dcf1e3; color:#1e6b3f; } .ra-badge.processing { background:#ffe4cc; color:#a3500a; }
.ra-view { background:var(--ra-green); color:#fff !important; text-decoration:none; font-weight:600; border-radius:12px; padding:10px 18px; white-space:nowrap; }
.ra-view:hover { background:var(--ra-gold); }
.ra-empty { background:var(--ra-card); border:1px dashed var(--ra-border); border-radius:16px; text-align:center; padding:48px 20px; }
.ra-empty h2 { font-family:'Playfair Display',Georgia,serif; margin:0 0 6px; }
.ra-settings { display:none; background:var(--ra-card); border:1px solid var(--ra-border); border-radius:16px; padding:20px; margin-bottom:20px; }
.ra-settings.open { display:block; }
.ra-settings h2 { font-family:'Playfair Display',Georgia,serif; font-size:1.3rem; margin:0 0 12px; }
.ra-switch { display:flex; justify-content:space-between; align-items:center; padding:12px 0; border-bottom:1px solid var(--ra-border); font-size:16px; }
.ra-switch input { width:48px; height:26px; accent-color:var(--ra-green); cursor:pointer; }
.ra-save { background:var(--ra-green); color:#fff; border:0; border-radius:12px; padding:10px 24px; font-weight:700; font-size:16px; cursor:pointer; margin-top:14px; }
.ra-saved { color:#1e6b3f; font-weight:700; margin-left:12px; }
.ra-err { color:#c62828; font-weight:600; margin-left:12px; }
@media (max-width:640px) {
    .ra-page { padding:14px; }
    .ra-summary { gap:8px; } .ra-stat { padding:12px; } .ra-stat .n { font-size:1.8rem; }
    .ra-card { flex-direction:column; align-items:flex-start; gap:8px; }
    .ra-time { min-width:0; }
    .ra-view { width:100%; text-align:center; box-sizing:border-box; }
    .ra-count { margin-left:0; width:100%; }
}
</style>

<div class="container-fluid px-3 px-md-4 py-3">
<div class="ra-page">
    <div class="ra-head">
        <div>
            <h1>Reminders &amp; Alerts</h1>
            <p class="ra-sub">Scheduled requests coming up, listed by day. Check here each morning.</p>
        </div>
        <button type="button" class="ra-link-btn" id="raSettingsToggle" aria-expanded="<?php echo $showSettings ? 'true' : 'false'; ?>" aria-controls="raSettings">
            <i class="fas fa-sliders me-1"></i> Reminder settings
        </button>
    </div>

    <form method="POST" action="" class="ra-settings<?php echo $showSettings ? ' open' : ''; ?>" id="raSettings">
        <?php echo csrfInput(); ?>
        <h2>Reminder settings</h2>
        <label class="ra-switch"><span>3 days before</span>
            <input type="checkbox" name="remind_3_days" value="1" <?php echo !empty($settings['remind_3_days']) ? 'checked' : ''; ?>></label>
        <label class="ra-switch"><span>2 days before</span>
            <input type="checkbox" name="remind_2_days" value="1" <?php echo !empty($settings['remind_2_days']) ? 'checked' : ''; ?>></label>
        <label class="ra-switch"><span>1 day before</span>
            <input type="checkbox" name="remind_1_day" value="1" <?php echo !empty($settings['remind_1_day']) ? 'checked' : ''; ?>></label>
        <label class="ra-switch"><span>On the day (every morning at 8:00 AM)</span>
            <input type="checkbox" name="remind_0_day" value="1" <?php echo !empty($settings['remind_0_day']) ? 'checked' : ''; ?>></label>
        <label class="ra-switch"><span>Also send by email</span>
            <input type="checkbox" name="channel_email" value="1" <?php echo !empty($settings['channel_email']) ? 'checked' : ''; ?>></label>
        <button type="submit" class="ra-save">Save</button>
        <?php if ($saved): ?><span class="ra-saved">Saved ✓</span><?php endif; ?>
        <?php if ($error): ?><span class="ra-err"><?php echo htmlspecialchars($error); ?></span><?php endif; ?>
    </form>

    <div class="ra-summary">
        <div class="ra-stat today"><div class="n"><?php echo $countToday; ?></div><div class="l">Today</div></div>
        <div class="ra-stat tomorrow"><div class="n"><?php echo $countTomorrow; ?></div><div class="l">Tomorrow</div></div>
        <div class="ra-stat"><div class="n"><?php echo $countWeek; ?></div><div class="l">Next 7 days</div></div>
    </div>

    <nav class="ra-seg" aria-label="Date range">
        <?php foreach ([7 => 'Next 7 days', 14 => 'Next 14 days', 30 => 'Next 30 days'] as $r => $label): ?>
            <a href="?range=<?php echo $r; ?>" class="<?php echo $range === $r ? 'active' : ''; ?>"><?php echo $label; ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if (empty($groups)): ?>
        <div class="ra-empty">
            <h2>Nothing scheduled</h2>
            <p class="ra-sub">No schedules in the next <?php echo $range; ?> days.</p>
        </div>
    <?php else: ?>
        <?php foreach ($groups as $dateStr => $rows):
            $d = (int)$rows[0]['days_diff'];
            $dt = new DateTimeImmutable($dateStr, new DateTimeZone('Asia/Manila'));
            if ($d === 0) { $edge = 'var(--ra-today)'; }
            elseif ($d === 1) { $edge = 'var(--ra-tomorrow)'; }
            elseif ($d <= 3) { $edge = 'var(--ra-soon)'; }
            else { $edge = 'var(--ra-later)'; }
            $n = count($rows);
        ?>
        <section class="ra-day">
            <div class="ra-day-head">
                <?php if ($d === 0): ?>
                    <span class="ra-tag" style="background:var(--ra-today)">Today</span>
                    <span class="ra-day-meta"><?php echo $dt->format('l · F j'); ?></span>
                <?php elseif ($d === 1): ?>
                    <span class="ra-tag" style="background:var(--ra-tomorrow)">Tomorrow</span>
                    <span class="ra-day-meta"><?php echo $dt->format('l · F j'); ?></span>
                <?php else: ?>
                    <span class="ra-day-title"><?php echo $dt->format('l'); ?></span>
                    <span class="ra-day-meta"><?php echo $dt->format('F j'); ?> · In <?php echo $d; ?> days</span>
                <?php endif; ?>
                <span class="ra-count"><?php echo $n; ?> schedule<?php echo $n === 1 ? '' : 's'; ?></span>
            </div>
            <?php foreach ($rows as $s):
                [$stLabel, $stClass] = $statusMeta((string)($s['status'] ?? 'pending'));
                $isReq = !empty($s['request_id']) || (($s['source_type'] ?? '') === 'request' && !empty($s['source_id']));
                $link = $isReq
                    ? 'request-workflow.php?id=' . (int)($s['request_id'] ?? $s['source_id'])
                    : 'manage-calendar.php?event=' . (int)($s['schedule_id'] ?? $s['source_id'] ?? 0);
                $tsT = strtotime((string)($s['start_time'] ?? ''));
                $timeLabel = $tsT ? date('g:i A', $tsT) : ((string)($s['start_time'] ?? '') !== '' ? (string)$s['start_time'] : 'Time TBD');
            ?>
            <div class="ra-card" style="--edge:<?php echo $edge; ?>">
                <div class="ra-time"><?php echo htmlspecialchars($timeLabel); ?></div>
                <div class="ra-body">
                    <div class="ra-service"><?php echo htmlspecialchars($prettyService((string)($s['service_label'] ?? $s['title'] ?? ''))); ?></div>
                    <div class="ra-meta"><i class="far fa-user me-1"></i><?php echo htmlspecialchars((string)($s['requester_name'] ?? 'Parishioner')); ?>
                        &nbsp;·&nbsp;<i class="fas fa-location-dot me-1"></i><?php echo htmlspecialchars((string)($s['location'] ?? 'Main Church')); ?></div>
                    <span class="ra-badge <?php echo $stClass; ?>"><?php echo $stLabel; ?></span>
                </div>
                <a class="ra-view" href="<?php echo htmlspecialchars($link); ?>"><?php echo $isReq ? 'View Request' : 'View Event'; ?></a>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</div>

<script>
document.getElementById('raSettingsToggle').addEventListener('click', function () {
    var p = document.getElementById('raSettings');
    var open = p.classList.toggle('open');
    this.setAttribute('aria-expanded', open ? 'true' : 'false');
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
