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
$user_first_name = trim(explode(' ', trim((string)($user_name ?? '')))[0] ?? '');
if ($user_first_name === '') {
    $user_first_name = 'Parishioner';
}

$request_counts = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'completed' => 0,
];
$unread_count = getUnreadNotificationCount($conn, $user_id);

$status_map = [
    'submitted' => 'pending',
    'pending' => 'pending',
    'requirements_review' => 'pending',
    'under_review' => 'pending',
    'needs_information' => 'pending',
    'payment_required' => 'pending',
    'payment_review' => 'pending',
    'approved' => 'approved',
    'processing' => 'processing',
    'scheduled' => 'processing',
    'ready_for_release' => 'processing',
    'completed' => 'completed',
    'rejected' => 'rejected',
    'cancelled' => 'cancelled',
];

$stmt = $conn->prepare("SELECT status, COUNT(*) AS count FROM requests WHERE user_id = ? GROUP BY status");
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $raw_status = strtolower(trim((string) $row['status']));
        $count = intval($row['count']);
        $request_counts['total'] += $count;
        $target_status = $status_map[$raw_status] ?? (isset($request_counts[$raw_status]) ? $raw_status : 'pending');
        if (isset($request_counts[$target_status])) {
            $request_counts[$target_status] += $count;
        }
    }
    $stmt->close();
}

// 8 Stat Cards KPI Data
$kpis = array(
    'total_users' => 0,
    'total_requests' => 0,
    'pending_requests' => 0,
    'total_records' => 0,
    'total_reservations' => 0,
    'active_announcements' => 0,
    'active_schedules' => 0
);

if (function_exists('ensureScheduleEventsTable')) {
    ensureScheduleEventsTable($conn);
}

// Total Users
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'user'");
if ($stmt) {
    $stmt->execute();
    $kpis['total_users'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();
}

// Total Requests
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM requests WHERE deleted_at IS NULL");
if ($stmt) {
    $stmt->execute();
    $kpis['total_requests'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();
}

// Pending Requests
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM requests WHERE status = 'pending' AND deleted_at IS NULL");
if ($stmt) {
    $stmt->execute();
    $kpis['pending_requests'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();
}

// Total Records (all sacramental records)
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM baptism_records UNION ALL SELECT COUNT(*) FROM confirmation_records UNION ALL SELECT COUNT(*) FROM first_communion_records UNION ALL SELECT COUNT(*) FROM marriage_records");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $total += $row['count'] ?? 0;
    }
    $kpis['total_records'] = $total;
    $stmt->close();
}

// Total Reservations
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM reservations");
if ($stmt) {
    $stmt->execute();
    $kpis['total_reservations'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();
}

// Active Announcements
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM announcements WHERE status = 'active' AND deleted_at IS NULL");
if ($stmt) {
    $stmt->execute();
    $kpis['active_announcements'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();
}

// Active Public Schedules
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM schedule_events WHERE status != 'cancelled' AND approval_status = 'approved'");
if ($stmt) {
    $stmt->execute();
    $kpis['active_schedules'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
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

    /* ── 8 Stat Cards High Density Grid ───────────────────── */
    .dashboard-stats-grid,
    body.user-area .dashboard-stats-grid {
        display: grid !important;
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 12px !important;
        margin-bottom: 20px !important;
        width: 100% !important;
    }

    .stat-card-compact,
    body.user-area .stat-card-compact {
        background: #ffffff !important;
        border: 1px solid #d8d6cc !important;
        border-radius: 8px !important;
        padding: 12px 14px !important;
        text-decoration: none !important;
        color: #1e293b !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: space-between !important;
        min-height: 94px !important;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02) !important;
        transition: all 0.15s ease !important;
    }

    .stat-card-compact:hover,
    body.user-area .stat-card-compact:hover {
        transform: translateY(-2px) !important;
        border-color: #c4c1b5 !important;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05) !important;
        color: #1e293b !important;
    }

    .stat-card-header,
    body.user-area .stat-card-header {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 8px !important;
        margin-bottom: 3px !important;
    }

    .stat-card-label,
    body.user-area .stat-card-label {
        font-size: 0.7rem !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        color: #6b6a63 !important;
        margin: 0 !important;
        line-height: 1.2 !important;
    }

    .stat-card-icon,
    body.user-area .stat-card-icon {
        width: 28px !important;
        height: 28px !important;
        border-radius: 6px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        font-size: 12px !important;
        flex-shrink: 0 !important;
    }

    .icon-blue { background: #eff6ff !important; color: #2563eb !important; }
    .icon-indigo { background: #eef2ff !important; color: #4f46e5 !important; }
    .icon-amber { background: #fffbeb !important; color: #d97706 !important; }
    .icon-emerald { background: #ecfdf5 !important; color: #059669 !important; }
    .icon-purple { background: #faf5ff !important; color: #7c3aed !important; }
    .icon-teal { background: #f0fdfa !important; color: #0d9488 !important; }
    .icon-cyan { background: #ecfeff !important; color: #0891b2 !important; }
    .icon-slate { background: #f1f5f9 !important; color: #475569 !important; }

    .stat-card-value,
    body.user-area .stat-card-value {
        font-size: 1.4rem !important;
        font-weight: 800 !important;
        color: #0f172a !important;
        line-height: 1.15 !important;
        margin: 2px 0 4px 0 !important;
        font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
    }

    .stat-card-footer,
    body.user-area .stat-card-footer {
        display: flex !important;
        align-items: center !important;
        gap: 4px !important;
        font-size: 0.68rem !important;
        font-weight: 500 !important;
        color: #6b6a63 !important;
    }

    .trend-pill,
    body.user-area .trend-pill {
        display: inline-flex !important;
        align-items: center !important;
        gap: 3px !important;
        padding: 1px 6px !important;
        border-radius: 4px !important;
        font-size: 0.65rem !important;
        font-weight: 700 !important;
        line-height: 1.2 !important;
        letter-spacing: 0.2px !important;
    }

    .trend-pill.success { background: #dcfce7 !important; color: #166534 !important; }
    .trend-pill.warning { background: #fef3c7 !important; color: #92400e !important; }
    .trend-pill.danger { background: #fee2e2 !important; color: #991b1b !important; }
    .trend-pill.neutral { background: #f1f5f9 !important; color: #475569 !important; }

    @media (max-width: 1200px) {
        .dashboard-stats-grid,
        body.user-area .dashboard-stats-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        }
    }

    @media (max-width: 900px) {
        .dashboard-stats-grid,
        body.user-area .dashboard-stats-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        }
    }

    @media (max-width: 480px) {
        .dashboard-stats-grid,
        body.user-area .dashboard-stats-grid {
            grid-template-columns: 1fr !important;
        }
    }

    /* ── Desktop: always show full dashboard, hide mobile-only sections ── */
    @media (min-width: 900px) {
        .client-dashboard > .dashboard-removed {
            display: grid !important;
        }

        .dashboard-mobile-summary,
        body.user-area .dashboard-mobile-summary,
        .mobile-dashboard-quick-label,
        .mobile-dashboard-section-label,
        .user-mobile-card-nav,
        .user-bottom-nav {
            display: none !important;
        }
    }

    /* ── Mobile/Tablet (<900px): hide desktop panels, show mobile sections ── */
    @media (max-width: 899px) {
        .client-dashboard > .dashboard-removed {
            display: none !important;
        }
    }
</style>

<div class="client-dashboard<?php echo $show_mobile_dashboard_features ? ' show-dashboard-features' : ' show-mobile-menu'; ?>">
    <!-- Parishioner Welcome Banner -->
    <section class="client-welcome-panel" aria-label="Parishioner welcome dashboard">
        <div>
            <h1>Welcome back,<span class="client-welcome-name"><?php echo e($user_name); ?></span></h1>
            <p class="client-verse">
                "The Lord bless you and keep you; the Lord make his face shine on you and be gracious to you."
                <span>Numbers 6:24-25</span>
            </p>
        </div>
    </section>

    <!-- Compact 4-Column Stat Cards Grid (8 Key Metrics) -->
    <div class="dashboard-stats-grid">
        <!-- 1. Total Parishioners -->
        <a href="<?php echo isAdmin() ? '../admin/manage-users.php' : 'javascript:void(0);'; ?>" class="stat-card-compact" aria-label="View total parishioners">
            <div class="stat-card-header">
                <span class="stat-card-label">Total Parishioners</span>
                <span class="stat-card-icon icon-blue"><i class="fas fa-users"></i></span>
            </div>
            <div class="stat-card-value"><?php echo number_format($kpis['total_users']); ?></div>
            <div class="stat-card-footer">
                <span class="trend-pill success"><i class="fas fa-arrow-up"></i> Active users</span>
            </div>
        </a>

        <!-- 2. Total Requests -->
        <a href="my-requests.php" class="stat-card-compact" aria-label="View all requests">
            <div class="stat-card-header">
                <span class="stat-card-label">Total Requests</span>
                <span class="stat-card-icon icon-indigo"><i class="fas fa-list-check"></i></span>
            </div>
            <div class="stat-card-value"><?php echo number_format($kpis['total_requests']); ?></div>
            <div class="stat-card-footer">
                <span class="trend-pill neutral"><i class="fas fa-chart-line"></i> All time</span>
            </div>
        </a>

        <!-- 3. Pending Requests -->
        <a href="my-requests.php?status=pending" class="stat-card-compact" aria-label="View pending requests">
            <div class="stat-card-header">
                <span class="stat-card-label">Pending Requests</span>
                <span class="stat-card-icon icon-amber"><i class="fas fa-hourglass-half"></i></span>
            </div>
            <div class="stat-card-value"><?php echo number_format($kpis['pending_requests']); ?></div>
            <div class="stat-card-footer">
                <?php if ($kpis['pending_requests'] > 5): ?>
                    <span class="trend-pill danger"><i class="fas fa-circle-exclamation"></i> Action needed</span>
                <?php else: ?>
                    <span class="trend-pill success"><i class="fas fa-check"></i> Under control</span>
                <?php endif; ?>
            </div>
        </a>

        <!-- 4. Sacramental Records -->
        <a href="<?php echo isAdmin() ? '../admin/manage-records.php' : 'my-requests.php'; ?>" class="stat-card-compact" aria-label="View sacramental records">
            <div class="stat-card-header">
                <span class="stat-card-label">Sacramental Records</span>
                <span class="stat-card-icon icon-emerald"><i class="fas fa-book-bible"></i></span>
            </div>
            <div class="stat-card-value"><?php echo number_format($kpis['total_records']); ?></div>
            <div class="stat-card-footer">
                <span class="trend-pill neutral"><i class="fas fa-database"></i> Digitized</span>
            </div>
        </a>

        <!-- 5. Event Reservations -->
        <a href="make-reservation.php" class="stat-card-compact" aria-label="View event reservations">
            <div class="stat-card-header">
                <span class="stat-card-label">Event Reservations</span>
                <span class="stat-card-icon icon-purple"><i class="fas fa-calendar-check"></i></span>
            </div>
            <div class="stat-card-value"><?php echo number_format($kpis['total_reservations']); ?></div>
            <div class="stat-card-footer">
                <span class="trend-pill neutral"><i class="fas fa-box-archive"></i> Scheduled</span>
            </div>
        </a>

        <!-- 6. Active Announcements -->
        <a href="announcements.php" class="stat-card-compact" aria-label="View active announcements">
            <div class="stat-card-header">
                <span class="stat-card-label">Announcements</span>
                <span class="stat-card-icon icon-teal"><i class="fas fa-bullhorn"></i></span>
            </div>
            <div class="stat-card-value"><?php echo number_format($kpis['active_announcements']); ?></div>
            <div class="stat-card-footer">
                <span class="trend-pill success"><i class="fas fa-signal"></i> Live now</span>
            </div>
        </a>

        <!-- 7. Calendar Schedules -->
        <a href="view-schedule.php" class="stat-card-compact" aria-label="View calendar schedules">
            <div class="stat-card-header">
                <span class="stat-card-label">Schedules &amp; Events</span>
                <span class="stat-card-icon icon-cyan"><i class="fas fa-calendar-days"></i></span>
            </div>
            <div class="stat-card-value"><?php echo number_format($kpis['active_schedules']); ?></div>
            <div class="stat-card-footer">
                <span class="trend-pill neutral"><i class="fas fa-clock"></i> Approved</span>
            </div>
        </a>

        <!-- 8. System Audit -->
        <a href="<?php echo isAdmin() ? '../admin/audit-logs.php' : 'javascript:void(0);'; ?>" class="stat-card-compact" aria-label="View system audit">
            <div class="stat-card-header">
                <span class="stat-card-label">System Audit</span>
                <span class="stat-card-icon icon-slate"><i class="fas fa-shield-halved"></i></span>
            </div>
            <div class="stat-card-value">Live</div>
            <div class="stat-card-footer">
                <span class="trend-pill success"><i class="fas fa-lock"></i> Tracking active</span>
            </div>
        </a>
    </div>

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
                                        <?php 
                                            $disp_status = strtolower($request['status'] ?? 'pending');
                                        ?>
                                        <td data-label="Status"><span class="badge rounded-pill border px-2.5 py-1 fw-semibold <?php echo getStatusBadgeClass($disp_status); ?>"><?php echo e(ucfirst($disp_status)); ?></span></td>
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
