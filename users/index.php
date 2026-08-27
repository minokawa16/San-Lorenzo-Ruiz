<?php
/**
 * User Dashboard Module - Presents parishioner request status, schedules, notifications, and quick actions.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
include '../includes/auth.php';

// Check session expiration
initSession();
if (isSessionExpired()) {
    logoutUser();
}

// Require authentication and parishioner role
requireAuth();
requireParishioner();

$user_id = getCurrentUserId();
$user_name = getUserFullName();

$request_counts = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'completed' => 0,
];
$unread_count = getUnreadNotificationCount($conn, $user_id);

$stmt = $conn->prepare("SELECT status, COUNT(*) AS count FROM requests WHERE user_id = ? GROUP BY status");
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $status = $row['status'];
        $count = intval($row['count']);
        $request_counts['total'] += $count;
        if (isset($request_counts[$status])) {
            $request_counts[$status] = $count;
        }
    }
    $stmt->close();
}

$recent_requests = [];
$stmt = $conn->prepare("SELECT request_id, reference_number, request_type, status, date_requested
                        FROM requests
                        WHERE user_id = ?
                        ORDER BY date_requested DESC
                        LIMIT 5");
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_requests[] = $row;
    }
    $stmt->close();
}

$upcoming_reservations = [];
$stmt = $conn->prepare("SELECT reservation_id, reservation_type, event_date, event_time, event_details, status
                        FROM reservations
                        WHERE user_id = ? AND event_date >= CURDATE()
                        ORDER BY event_date ASC, event_time ASC
                        LIMIT 4");
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $upcoming_reservations[] = $row;
    }
    $stmt->close();
}

$upcoming_events = [];
$today_schedule = [];
ensureScheduleEventsTable($conn);
$stmt = $conn->prepare("SELECT title, description, event_date, start_time, end_time, location, category
                        FROM schedule_events
                        WHERE visibility = 'public'
                          AND approval_status = 'approved'
                          AND status != 'cancelled'
                          AND event_date >= CURDATE()
                        ORDER BY event_date ASC, start_time ASC
                        LIMIT 5");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $upcoming_events[] = $row;
    }
    $stmt->close();
}

$stmt = $conn->prepare("SELECT title, description, start_time, end_time, location, category
                        FROM schedule_events
                        WHERE visibility = 'public'
                          AND approval_status = 'approved'
                          AND status != 'cancelled'
                          AND event_date = CURDATE()
                        ORDER BY start_time ASC
                        LIMIT 8");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $today_schedule[] = $row;
    }
    $stmt->close();
}

$latest_announcements = [];
$stmt = $conn->prepare("SELECT announcement_id, title, content, type, published_date, event_date
                        FROM announcements
                        WHERE status = 'active'
                          AND deleted_at IS NULL
                          AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                          AND (expiry_date IS NULL OR expiry_date >= NOW())
                        ORDER BY is_pinned DESC, published_date DESC
                        LIMIT 3");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $latest_announcements[] = $row;
    }
    $stmt->close();
}

$notification_summary = [
    'new' => $unread_count,
    'request_updates' => 0,
    'event_reminders' => 0,
    'system_messages' => 0,
];
$stmt = $conn->prepare("SELECT
                            SUM(CASE WHEN title LIKE '%request%' OR message LIKE '%request%' THEN 1 ELSE 0 END) AS request_updates,
                            SUM(CASE WHEN title LIKE '%event%' OR title LIKE '%schedule%' OR message LIKE '%event%' OR message LIKE '%schedule%' THEN 1 ELSE 0 END) AS event_reminders,
                            SUM(CASE WHEN title LIKE '%system%' OR message LIKE '%system%' THEN 1 ELSE 0 END) AS system_messages
                        FROM notifications
                        WHERE user_id = ? AND is_read = 0");
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: [];
    $notification_summary['request_updates'] = intval($summary['request_updates'] ?? 0);
    $notification_summary['event_reminders'] = intval($summary['event_reminders'] ?? 0);
    $notification_summary['system_messages'] = intval($summary['system_messages'] ?? 0);
    $stmt->close();
}

function dashboardTextPreview($content, $length = 120) {
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string) $content)));
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($plain, 0, $length, '...');
    }
    return strlen($plain) > $length ? substr($plain, 0, $length - 3) . '...' : $plain;
}

function dashboardRequestLabel($type) {
    return ucwords(str_replace('_', ' ', (string) $type));
}

function dashboardTimeRange($start, $end = '') {
    if (empty($start)) {
        return 'Time to be announced';
    }
    $range = date('g:i A', strtotime($start));
    if (!empty($end)) {
        $range .= ' - ' . date('g:i A', strtotime($end));
    }
    return $range;
}

function dashboardIcon($name) {
    $icons = [
        'file' => '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15h6"/><path d="M9 11h2"/></svg>',
        'church' => '<svg viewBox="0 0 24 24"><path d="M12 3v5"/><path d="M10 5h4"/><path d="m5 10 7-4 7 4"/><path d="M6 10v10h12V10"/><path d="M10 20v-5a2 2 0 0 1 4 0v5"/></svg>',
        'hand' => '<svg viewBox="0 0 24 24"><path d="M8 13V5a2 2 0 1 1 4 0v7"/><path d="M12 12V4a2 2 0 1 1 4 0v9"/><path d="M16 13V7a2 2 0 1 1 4 0v8a7 7 0 0 1-7 7h-1a8 8 0 0 1-7.4-5L3 13a2 2 0 0 1 3.7-1.5L8 14"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24"><path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 10h18"/></svg>',
        'list' => '<svg viewBox="0 0 24 24"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>',
        'megaphone' => '<svg viewBox="0 0 24 24"><path d="m3 11 18-5v12L3 14z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>',
        'bell' => '<svg viewBox="0 0 24 24"><path d="M10 21h4"/><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/></svg>',
        'clock' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
        'check' => '<svg viewBox="0 0 24 24"><path d="m20 6-11 11-5-5"/></svg>',
        'sparkles' => '<svg viewBox="0 0 24 24"><path d="m12 3-1.9 5.8L4 11l6.1 2.2L12 19l1.9-5.8L20 11l-6.1-2.2z"/><path d="M5 3v4"/><path d="M3 5h4"/><path d="M19 17v4"/><path d="M17 19h4"/></svg>',
    ];
    return $icons[$name] ?? $icons['file'];
}

$next_reservation = $upcoming_reservations[0] ?? null;
$next_event = $upcoming_events[0] ?? null;
$office_hours = 'Mon-Sat, 8:00 AM - 5:00 PM';

$page_title = 'User Dashboard';
$show_mobile_dashboard_features = ($_GET['view'] ?? '') === 'dashboard';
$body_extra_class = $show_mobile_dashboard_features ? 'user-dashboard-feature-view' : 'user-dashboard-menu-view';
?>
<?php include '../templates/header.php'; ?>

<style>
    .client-dashboard {
        display: grid;
        gap: 16px;
        animation: dashboardFade 220ms ease both;
    }

    .client-welcome-panel,
    .client-panel,
    .client-stat-card {
        background: #FFFFFF;
        border: 1px solid #E8E1D5;
        border-radius: 10px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        color: #1F2937;
    }

    .client-welcome-panel {
        position: relative;
        padding: 16px 20px;
    }

    .client-welcome-panel h1 {
        margin: 0;
        font-family: "Inter", "Segoe UI", Arial, sans-serif;
        font-size: 19px;
        line-height: 1.25;
        font-weight: 700;
        color: #1F2937;
    }

    .client-welcome-name {
        display: inline;
        color: #2E3A2D;
        margin-left: 6px;
    }

    .client-verse {
        margin: 6px 0 0;
        color: #5C584E;
        font-family: "Inter", "Segoe UI", Arial, sans-serif;
        font-size: 13.5px;
        line-height: 1.45;
        font-style: italic;
    }

    .client-verse span {
        display: inline;
        margin-left: 6px;
        color: #6B7280;
        font-style: normal;
        font-size: 12px;
        font-weight: 600;
    }

    .client-stat-card:hover,
    .client-panel:hover {
        transform: translateY(-1px);
        border-color: rgba(200, 155, 60, 0.4);
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }

    .client-icon {
        width: 36px;
        height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: rgba(200, 155, 60, 0.1);
        color: #C89B3C;
        border: 1px solid rgba(200, 155, 60, 0.2);
    }

    .client-icon svg {
        width: 18px;
        height: 18px;
        fill: none;
        stroke: currentColor;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .client-stat-card strong {
        display: block;
        color: #1F2937;
        font-size: 13px;
        font-weight: 600;
    }

    .client-stat-card span,
    .client-panel-muted {
        color: #6B7280;
        font-size: 12px;
        line-height: 1.4;
    }

    .client-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 16px;
    }

    .client-stat-card {
        padding: 14px 16px;
        text-decoration: none;
        transition: transform 200ms ease, box-shadow 200ms ease, border-color 200ms ease;
    }

    .client-stat-value {
        margin: 8px 0 2px;
        color: #1F2937;
        font-family: "Inter", "Segoe UI", Arial, sans-serif;
        font-size: 26px;
        font-weight: 700;
        line-height: 1.15;
    }

    .client-stat-trend {
        margin-top: 6px;
        color: #10B981;
        font-size: 12px;
        font-weight: 600;
    }

    .client-dashboard-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.45fr) minmax(300px, 0.75fr);
        gap: 16px;
        align-items: start;
    }

    .client-stack {
        display: grid;
        gap: 16px;
    }

    .client-panel {
        padding: 16px;
        transition: transform 200ms ease, box-shadow 200ms ease, border-color 200ms ease;
    }

    .client-panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 12px;
    }

    .client-section-title {
        margin: 0;
        font-family: "Inter", "Segoe UI", Arial, sans-serif;
        font-size: 16px;
        font-weight: 600;
        color: #1F2937;
    }

    .client-link-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 30px;
        padding: 4px 10px;
        border: 1px solid #E8E1D5;
        border-radius: 6px;
        background: #FFFFFF;
        color: #C89B3C;
        font-size: 12px;
        font-weight: 600;
        text-decoration: none;
    }

    .client-link-btn:hover {
        border-color: #C89B3C;
        background: #FAF7F2;
        color: #A77F2A;
    }

    .client-event-list,
    .client-card-list,
    .client-schedule-list,
    .client-notification-list {
        display: grid;
        gap: 10px;
    }

    .client-list-item {
        display: grid;
        gap: 4px;
        padding: 10px 12px;
        border: 1px solid #E8E1D5;
        border-radius: 8px;
        background: #FCFBF8;
    }

    .client-list-item strong {
        color: #1F2937;
        font-size: 13.5px;
    }

    .client-list-meta {
        color: #6B7280;
        font-size: 12px;
    }

    .client-requests-table {
        overflow: auto;
        border: 1px solid #E8E1D5;
        border-radius: 8px;
    }

    .client-empty {
        padding: 20px;
        text-align: center;
        color: #6B7280;
        border: 1px dashed #E8E1D5;
        border-radius: 8px;
        background: #FCFBF8;
        font-size: 13px;
    }

    .client-schedule-row,
    .client-notification-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid #E8E1D5;
    }

    .client-schedule-row:last-child,
    .client-notification-row:last-child {
        border-bottom: 0;
    }

    @keyframes dashboardFade {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 1080px) {
        .client-dashboard-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 680px) {
        .client-welcome-panel,
        .client-panel {
            padding: 14px;
        }

        .client-stats-grid {
            grid-template-columns: 1fr;
        }

        .client-panel-header,
        .client-schedule-row,
        .client-notification-row {
            align-items: flex-start;
            flex-direction: column;
        }
    }

    .client-ai-feature-card {
        background: linear-gradient(135deg, #FFFFFF 0%, #FAF8F5 100%);
        border: 1px solid rgba(200, 155, 60, 0.45);
        box-shadow: 0 4px 16px rgba(46, 58, 45, 0.06);
        position: relative;
        overflow: hidden;
    }

    .client-ai-feature-card::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, #2E3A2D, #C89B3C, #2E3A2D);
    }

    .client-ai-badge-icon {
        width: 36px;
        height: 36px;
        min-width: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: linear-gradient(135deg, #2E3A2D, #1E271D);
        color: #C89B3C;
        border: 1.5px solid #C89B3C;
        font-size: 16px;
        box-shadow: 0 2px 8px rgba(46, 58, 45, 0.15);
    }

    .client-ai-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 11px;
        font-weight: 600;
        color: #10B981;
        margin-top: 1px;
    }

    .client-ai-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: #10B981;
        box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25);
        animation: aiPulseDot 2s infinite;
    }

    @keyframes aiPulseDot {
        0%, 100% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.3); opacity: 0.7; }
    }

    .client-ai-feature-desc {
        color: #5C584E;
        font-size: 13px;
        line-height: 1.45;
        margin: 6px 0 10px 0;
    }

    .client-ai-quick-prompts {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .client-ai-prompt-chip {
        display: inline-flex;
        align-items: center;
        padding: 4px 9px;
        background: #FFFFFF;
        border: 1px solid #E8E1D5;
        border-radius: 6px;
        color: #2E3A2D;
        font-size: 11.5px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.18s ease;
    }

    .client-ai-prompt-chip:hover {
        background: #FAF7F2;
        border-color: #C89B3C;
        color: #A97F24;
        transform: translateY(-1px);
        box-shadow: 0 2px 6px rgba(200, 155, 60, 0.15);
    }

    .client-ai-open-btn {
        background: linear-gradient(135deg, #2E3A2D, #1E271D) !important;
        color: #FFFFFF !important;
        border: 1px solid #C89B3C !important;
        cursor: pointer;
        transition: all 0.18s ease !important;
    }

    .client-ai-open-btn:hover {
        background: #1E271D !important;
        color: #C89B3C !important;
        box-shadow: 0 2px 8px rgba(200, 155, 60, 0.3) !important;
        transform: translateY(-1px);
    }
</style>

<div class="client-dashboard<?php echo $show_mobile_dashboard_features ? ' show-dashboard-features' : ' show-mobile-menu'; ?>">
    <section class="dashboard-mobile-summary" aria-labelledby="mobileRequestSummaryTitle">
        <h2 id="mobileRequestSummaryTitle">My Requests</h2>
        <div class="dashboard-mobile-summary-grid">
            <a href="my-requests.php?status=pending">
                <strong><?php echo intval($request_counts['pending']); ?></strong>
                <span>Pending</span>
            </a>
            <a href="my-requests.php?status=approved">
                <strong><?php echo intval($request_counts['approved']); ?></strong>
                <span>Approved</span>
            </a>
            <a href="my-requests.php?status=completed">
                <strong><?php echo intval($request_counts['completed']); ?></strong>
                <span>Completed</span>
            </a>
        </div>
    </section>

    <div class="mobile-dashboard-quick-label">Quick Access</div>
    <?php $dashboard_mobile_quick_access = true; ?>
    <?php include __DIR__ . '/../includes/user-mobile-nav.php'; ?>

    <section class="client-welcome-panel dashboard-removed" aria-label="Parishioner welcome dashboard">
        <div>
            <h1>Welcome back,<span class="client-welcome-name"><?php echo e($user_name); ?></span></h1>
            <p class="client-verse">
                "The Lord bless you and keep you; the Lord make his face shine on you and be gracious to you."
                <span>Numbers 6:24-25</span>
            </p>
        </div>
    </section>

    <section class="client-dashboard-grid dashboard-removed">
        <div class="client-stack">
            <article class="client-panel">
                <div class="client-panel-header">
                    <h2 class="client-section-title">Upcoming Parish Events</h2>
                    <a class="client-link-btn" href="view-schedule.php">View All</a>
                </div>
                <?php if (!empty($upcoming_events)): ?>
                    <div class="client-event-list">
                        <?php foreach ($upcoming_events as $event): ?>
                            <div class="client-list-item">
                                <strong><?php echo e($event['title']); ?></strong>
                                <div class="client-list-meta"><?php echo e(formatDate($event['event_date'])); ?> · <?php echo e(dashboardTimeRange($event['start_time'], $event['end_time'] ?? '')); ?><?php echo !empty($event['location']) ? ' · ' . e($event['location']) : ''; ?></div>
                                <div class="client-panel-muted"><?php echo e(dashboardTextPreview($event['description'] ?? '', 120)); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="client-empty">No upcoming parish events have been published yet.</div>
                <?php endif; ?>
            </article>

            <article class="client-panel">
                <div class="client-panel-header">
                    <h2 class="client-section-title">Recent Requests</h2>
                    <a class="client-link-btn" href="my-requests.php">View All Requests</a>
                </div>
                <?php if (!empty($recent_requests)): ?>
                    <div class="client-requests-table table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Type</th>
                                    <th>Date Submitted</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_requests as $request): ?>
                                    <tr>
                                        <td data-label="Reference"><strong><?php echo e($request['reference_number']); ?></strong></td>
                                        <td data-label="Request Type"><?php echo e(dashboardRequestLabel($request['request_type'])); ?></td>
                                        <td data-label="Date Submitted"><?php echo e(formatDate($request['date_requested'])); ?></td>
                                        <td data-label="Status"><span class="badge bg-<?php echo e(getStatusBadgeClass($request['status'])); ?>"><?php echo e(ucfirst($request['status'])); ?></span></td>
                                        <td data-label="Action"><a class="btn btn-sm btn-outline-primary" href="view-request.php?id=<?php echo intval($request['request_id']); ?>">View Details</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="client-empty">You have not submitted any parish requests yet.</div>
                <?php endif; ?>
            </article>

            <article class="client-panel">
                <div class="client-panel-header">
                    <h2 class="client-section-title">Latest Announcements</h2>
                    <a class="client-link-btn" href="announcements.php">View All</a>
                </div>
                <?php if (!empty($latest_announcements)): ?>
                    <div class="client-card-list">
                        <?php foreach ($latest_announcements as $announcement): ?>
                            <div class="client-list-item">
                                <strong><?php echo e($announcement['title']); ?></strong>
                                <div class="client-list-meta"><?php echo e(formatDate($announcement['published_date'])); ?> · <?php echo e(ucwords(str_replace('_', ' ', $announcement['type']))); ?></div>
                                <div class="client-panel-muted"><?php echo e(dashboardTextPreview($announcement['content'], 150)); ?></div>
                                <div><a class="client-link-btn" href="announcements.php">Read More</a></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="client-empty">No announcements are available right now.</div>
                <?php endif; ?>
            </article>
        </div>

        <aside class="client-stack">
            <article class="client-panel client-ai-feature-card" aria-label="TUGON AI Assistant">
                <div class="client-panel-header">
                    <div class="d-flex align-items-center gap-2">
                        <span class="client-ai-badge-icon" aria-hidden="true">
                            <i class="fas fa-robot"></i>
                        </span>
                        <div>
                            <h2 class="client-section-title">TUGON AI Assistant</h2>
                            <span class="client-ai-status-pill"><span class="client-ai-dot"></span> Online &amp; Ready</span>
                        </div>
                    </div>
                    <button type="button" class="client-link-btn client-ai-open-btn" data-open-ai-chat="true" title="Open TUGON AI chat">
                        <i class="fas fa-comments me-1"></i> Chat Now
                    </button>
                </div>
                <p class="client-ai-feature-desc">
                    Need help? Ask TUGON AI about certificate requirements, request status, Mass schedules, GCash verification, and parish services.
                </p>
                <div class="client-ai-quick-prompts">
                    <button type="button" class="client-ai-prompt-chip" data-ai-prompt="How do I request a certificate?">
                        <i class="fas fa-file-lines me-1"></i> Request Certificate
                    </button>
                    <button type="button" class="client-ai-prompt-chip" data-ai-prompt="What is the status of my request?">
                        <i class="fas fa-list-check me-1"></i> Track My Request
                    </button>
                    <button type="button" class="client-ai-prompt-chip" data-ai-prompt="Where can I see the parish schedule?">
                        <i class="fas fa-calendar-days me-1"></i> Parish Schedule
                    </button>
                </div>
            </article>

            <article class="client-panel">
                <div class="client-panel-header">
                    <h2 class="client-section-title">Today's Mass Schedule</h2>
                    <a class="client-link-btn" href="view-schedule.php">Calendar</a>
                </div>
                <?php if (!empty($today_schedule)): ?>
                    <div class="client-schedule-list">
                        <?php foreach ($today_schedule as $schedule): ?>
                            <div class="client-schedule-row">
                                <div>
                                    <strong><?php echo e($schedule['title']); ?></strong>
                                    <div class="client-list-meta"><?php echo e(dashboardTimeRange($schedule['start_time'], $schedule['end_time'] ?? '')); ?></div>
                                </div>
                                <span class="badge bg-info"><?php echo e(ucwords(str_replace('_', ' ', $schedule['category'] ?? 'Schedule'))); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="client-empty">No public Mass or parish schedule has been posted for today.</div>
                <?php endif; ?>
            </article>

            <article class="client-panel">
                <div class="client-panel-header">
                    <h2 class="client-section-title">Upcoming Reservations</h2>
                    <a class="client-link-btn" href="make-reservation.php">Manage</a>
                </div>
                <?php if (!empty($upcoming_reservations)): ?>
                    <div class="client-card-list">
                        <?php foreach ($upcoming_reservations as $reservation): ?>
                            <div class="client-list-item">
                                <strong><?php echo e(dashboardRequestLabel($reservation['reservation_type'])); ?></strong>
                                <div class="client-list-meta"><?php echo e(formatDate($reservation['event_date'])); ?> · <?php echo e(dashboardTimeRange($reservation['event_time'])); ?></div>
                                <div><span class="badge bg-<?php echo e(getStatusBadgeClass($reservation['status'])); ?>"><?php echo e(ucfirst($reservation['status'])); ?></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="client-empty">You have no upcoming reservations.</div>
                <?php endif; ?>
            </article>

            <article class="client-panel">
                <div class="client-panel-header">
                    <h2 class="client-section-title">Notification Summary</h2>
                    <a class="client-link-btn" href="notifications.php">View All Notifications</a>
                </div>
                <div class="client-notification-list">
                    <div class="client-notification-row"><span>New Notifications</span><strong><?php echo intval($notification_summary['new']); ?></strong></div>
                    <div class="client-notification-row"><span>Request Updates</span><strong><?php echo intval($notification_summary['request_updates']); ?></strong></div>
                    <div class="client-notification-row"><span>Event Reminders</span><strong><?php echo intval($notification_summary['event_reminders']); ?></strong></div>
                    <div class="client-notification-row"><span>System Messages</span><strong><?php echo intval($notification_summary['system_messages']); ?></strong></div>
                </div>
            </article>
        </aside>
    </section>
</div>

<?php include '../templates/footer.php'; ?>
