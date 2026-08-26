<?php
/**
 * ADMIN DASHBOARD
 * Parish Management System - Admin Control Panel
 * Features: KPIs, Analytics, Charts, Quick Actions
 */

include '../config/security.php';
include '../includes/session.php';
include '../includes/helpers.php';
include '../includes/auth.php';
include '../database/config.php';

// Define BASE_URL if not already defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/ParishSystem/');
}

// Check session expiration
initSession();
if (isSessionExpired()) {
    logoutUser();
}

// Require admin access
requireAuth();
requireAdmin();

// Get KPI Data
$kpis = array(
    'total_users' => 0,
    'total_requests' => 0,
    'pending_requests' => 0,
    'total_records' => 0,
    'total_reservations' => 0,
    'active_announcements' => 0,
    'active_schedules' => 0
);

ensureScheduleEventsTable($conn);

// Ensure Dashboard Archive Column Function - Documents this helper's role in the parish management workflow.
function ensureDashboardArchiveColumn($conn, $table) {
    return columnExists($conn, $table, 'deleted_at');
}

ensureDashboardArchiveColumn($conn, 'requests');
ensureDashboardArchiveColumn($conn, 'announcements');

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

// Get Recent Requests
$recent_requests = array();
$stmt = $conn->prepare("SELECT r.request_id, r.reference_number, r.request_type, r.status, r.date_requested, u.fullname FROM requests r JOIN users u ON r.user_id = u.id WHERE r.deleted_at IS NULL ORDER BY r.date_requested DESC LIMIT 10");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_requests[] = $row;
    }
    $stmt->close();
}

// Get Status Distribution
$status_dist = array(
    'pending' => 0,
    'processing' => 0,
    'approved' => 0,
    'completed' => 0,
    'rejected' => 0
);

$stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM requests WHERE deleted_at IS NULL GROUP BY status");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (isset($status_dist[$row['status']])) {
            $status_dist[$row['status']] = $row['count'];
        }
    }
    $stmt->close();
}
$page_title = 'Admin Dashboard - Parish Management';
$dashboard_unread_count = function_exists('getUnreadNotificationCount') ? getUnreadNotificationCount($conn, $_SESSION['user_id'] ?? 0) : 0;
$dashboard_profile_name = sanitize($_SESSION['fullname'] ?? 'Administrator');
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/holy-theme.css">
    <?php
    $premium_style_version = file_exists(__DIR__ . '/../assets/css/premium-parish.css')
        ? filemtime(__DIR__ . '/../assets/css/premium-parish.css')
        : time();
    $design_system_version = file_exists(__DIR__ . '/../assets/css/parish-design-system.css')
        ? filemtime(__DIR__ . '/../assets/css/parish-design-system.css')
        : time();
    ?>
    <link rel="stylesheet" href="../assets/css/premium-parish.css?v=<?php echo $premium_style_version; ?>">
    <link rel="stylesheet" href="../assets/css/parish-design-system.css?v=<?php echo $design_system_version; ?>">
    <link rel="stylesheet" href="../assets/css/admin-sidebar.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/admin-sidebar.css') ? filemtime(__DIR__ . '/../assets/css/admin-sidebar.css') : time(); ?>">
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
    <style id="compact-admin-dashboard-styles">
        /* ── Compact Enterprise Dashboard Styles ─────────────────── */
        body.premium-admin {
            background-color: #f8fafc;
            color: #0f172a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        .premium-admin-content {
            padding: 16px 20px 28px !important;
            max-width: 1600px;
            margin: 0 auto;
        }

        /* ── Topbar Streamlining ──────────────────────────────────── */
        .dashboard-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 10px 16px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 14px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
        }

        .dashboard-title-block h1 {
            font-size: 1.15rem !important;
            font-weight: 700 !important;
            color: #0f172a;
            margin: 0 !important;
            line-height: 1.25;
            letter-spacing: -0.2px;
        }

        .dashboard-title-block p {
            font-size: 0.76rem !important;
            color: #64748b;
            margin: 2px 0 0 0 !important;
        }

        .app-header-search {
            position: relative;
            width: 100%;
            max-width: 380px;
        }

        .app-header-search input {
            height: 34px !important;
            font-size: 0.78rem !important;
            border-radius: 6px !important;
            padding-left: 32px !important;
            padding-right: 56px !important;
            background: #f8fafc !important;
            border: 1px solid #e2e8f0 !important;
            color: #0f172a !important;
        }

        .app-header-search input:focus {
            background: #ffffff !important;
            border-color: #c89b3c !important;
            box-shadow: 0 0 0 2px rgba(200, 155, 60, 0.15) !important;
        }

        .app-header-search i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 12px;
            color: #94a3b8;
            pointer-events: none;
        }

        .app-header-search kbd {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            padding: 2px 5px;
            border-radius: 4px;
            background: #e2e8f0;
            color: #475569;
            border: 1px solid #cbd5e1;
        }

        .admin-profile-btn {
            height: 34px !important;
            padding: 3px 10px !important;
            border-radius: 6px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
            background: #f8fafc !important;
            border: 1px solid #e2e8f0 !important;
            color: #0f172a !important;
            font-size: 0.8rem !important;
            cursor: pointer;
        }

        .admin-profile-btn:hover {
            background: #f1f5f9 !important;
            border-color: #cbd5e1 !important;
        }

        .admin-profile-btn .profile-avatar {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #1e2d24;
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .admin-profile-btn .profile-name {
            font-weight: 600;
            font-size: 0.78rem;
            color: #0f172a;
        }

        .admin-profile-btn .profile-role {
            font-size: 0.68rem;
            color: #64748b;
            margin-left: 2px;
        }

        /* ── Quick Action Toolbar ─────────────────────────────────── */
        .dashboard-quick-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .action-btn-compact {
            height: 34px;
            padding: 0 12px;
            font-size: 0.76rem;
            font-weight: 600;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.15s ease;
            white-space: nowrap;
        }

        .action-btn-compact.primary {
            background: #1e2d24;
            color: #ffffff;
            border: 1px solid #1e2d24;
        }
        .action-btn-compact.primary:hover {
            background: #142018;
            border-color: #142018;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .action-btn-compact.gold {
            background: #c89b3c;
            color: #ffffff;
            border: 1px solid #c89b3c;
        }
        .action-btn-compact.gold:hover {
            background: #b58930;
            border-color: #b58930;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .action-btn-compact.secondary {
            background: #ffffff;
            color: #334155;
            border: 1px solid #e2e8f0;
        }
        .action-btn-compact.secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #0f172a;
            transform: translateY(-1px);
        }

        /* ── Compact 4-Column Stat Cards Grid ─────────────────────── */
        .dashboard-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        @media (max-width: 1100px) {
            .dashboard-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 580px) {
            .dashboard-stats-grid {
                grid-template-columns: 1fr;
            }
        }

        .stat-card-compact {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 14px;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 94px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
            transition: all 0.15s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card-compact:hover {
            transform: translateY(-2px);
            border-color: #cbd5e1;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.06);
            color: inherit;
        }

        .stat-card-compact::after {
            display: none !important; /* Remove legacy oversized blurry watermarks */
        }

        .stat-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 3px;
        }

        .stat-card-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin: 0;
            line-height: 1.2;
        }

        .stat-card-icon {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
        }

        /* Icon Badge Color Variants */
        .icon-blue { background: #eff6ff; color: #2563eb; }
        .icon-indigo { background: #eef2ff; color: #4f46e5; }
        .icon-amber { background: #fffbeb; color: #d97706; }
        .icon-emerald { background: #ecfdf5; color: #059669; }
        .icon-purple { background: #faf5ff; color: #7c3aed; }
        .icon-teal { background: #f0fdfa; color: #0d9488; }
        .icon-cyan { background: #ecfeff; color: #0891b2; }
        .icon-slate { background: #f1f5f9; color: #475569; }

        .stat-card-value {
            font-size: 1.4rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.15;
            margin: 2px 0 4px 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
        }

        .stat-card-footer {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 0.68rem;
            font-weight: 500;
            color: #64748b;
        }

        .trend-pill {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 1px 6px;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: 0.2px;
        }

        .trend-pill.success { background: #dcfce7; color: #166534; }
        .trend-pill.warning { background: #fef3c7; color: #92400e; }
        .trend-pill.danger { background: #fee2e2; color: #991b1b; }
        .trend-pill.neutral { background: #f1f5f9; color: #475569; }

        /* ── Tables & Activity Panels ─────────────────────────────── */
        .dashboard-content-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.75fr);
            gap: 14px;
            align-items: start;
        }

        @media (max-width: 960px) {
            .dashboard-content-grid {
                grid-template-columns: 1fr;
            }
        }

        .premium-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 14px 16px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
        }

        .premium-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #f1f5f9;
        }

        .premium-panel-title {
            font-size: 0.92rem !important;
            font-weight: 700 !important;
            color: #0f172a;
            margin: 0;
        }

        .dashboard-view-link {
            font-size: 0.72rem;
            font-weight: 600;
            color: #c89b3c;
            text-decoration: none;
        }
        .dashboard-view-link:hover {
            color: #a87f2e;
            text-decoration: underline;
        }

        .dashboard-recent-table {
            width: 100%;
            font-size: 0.78rem;
        }

        .dashboard-recent-table th {
            font-size: 0.68rem;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.4px;
            color: #64748b;
            padding: 6px 8px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .dashboard-recent-table td {
            padding: 7px 8px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            color: #334155;
        }

        .dashboard-activity-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .dashboard-activity-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 6px;
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            text-decoration: none;
            color: #334155;
            font-size: 0.76rem;
            transition: background 0.12s ease;
        }

        .dashboard-activity-item:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .dashboard-activity-item strong {
            color: #0f172a;
        }

        .dashboard-activity-item small {
            display: block;
            color: #94a3b8;
            font-size: 0.68rem;
            margin-top: 2px;
        }

        .dashboard-activity-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #475569;
            font-size: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .dashboard-activity-icon.approved, .dashboard-activity-icon.completed { background: #dcfce7; color: #166534; }
        .dashboard-activity-icon.pending { background: #fef3c7; color: #92400e; }
        .dashboard-activity-icon.rejected { background: #fee2e2; color: #991b1b; }

        /* Dark mode compatibility */
        body[data-theme="dark"] .dashboard-topbar,
        body[data-theme="dark"] .stat-card-compact,
        body[data-theme="dark"] .premium-panel {
            background: #1e293b !important;
            border-color: #334155 !important;
        }
        body[data-theme="dark"] .dashboard-title-block h1,
        body[data-theme="dark"] .stat-card-value,
        body[data-theme="dark"] .premium-panel-title {
            color: #f8fafc !important;
        }
        body[data-theme="dark"] .stat-card-compact:hover {
            border-color: #475569 !important;
            background: #243248 !important;
        }
        body[data-theme="dark"] .dashboard-recent-table th {
            background: #0f172a !important;
            border-color: #334155 !important;
        }
        body[data-theme="dark"] .dashboard-recent-table td {
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }
        body[data-theme="dark"] .action-btn-compact.secondary {
            background: #1e293b !important;
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }
        body[data-theme="dark"] .dashboard-activity-item {
            background: #0f172a !important;
            border-color: #334155 !important;
            color: #cbd5e1 !important;
        }
    </style>
</head>
<body class="premium-admin">
    <div class="premium-admin-shell">
        <!-- Include Admin Sidebar -->
        <?php include '../includes/admin-sidebar.php'; ?>

        <!-- Main Content -->
        <div class="premium-admin-content">

            <!-- Topbar / Header -->
            <header class="dashboard-topbar">
                <div class="dashboard-title-block">
                    <h1>Dashboard</h1>
                    <p>Monitor parish activities, requests, records, and operations.</p>
                </div>
                <div class="app-header-center d-none d-md-block">
                    <form class="app-header-search" action="<?php echo BASE_URL; ?>admin/manage-users.php" method="GET">
                        <i class="fas fa-magnifying-glass"></i>
                        <input id="adminSmartSearch" name="search" type="search" placeholder="Search parishioners, requests, records...">
                        <kbd>Ctrl K</kbd>
                    </form>
                </div>
                <div class="dashboard-header-tools">
                    <div class="dropdown">
                        <button class="admin-profile-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="profile-avatar"><?php echo strtoupper(substr($_SESSION['fullname'] ?? 'A', 0, 1)); ?></span>
                            <span class="profile-name"><?php echo $dashboard_profile_name; ?></span>
                            <span class="profile-role d-none d-sm-inline">Admin</span>
                            <i class="fas fa-chevron-down ms-1" style="font-size: 10px; color: #94a3b8;"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li><a class="dropdown-item" href="../auth/profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="settings.php"><i class="fas fa-gear me-2"></i> Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-arrow-right-from-bracket me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </header>

            <!-- Quick Action Toolbar -->
            <nav class="dashboard-quick-actions" aria-label="Quick dashboard actions">
                <a class="action-btn-compact primary" href="manage-requests.php">
                    <i class="fas fa-circle-plus"></i> New Request
                </a>
                <a class="action-btn-compact gold" href="<?php echo e(BASE_URL . 'admin/certificate-generator.php'); ?>">
                    <i class="fas fa-award"></i> Generate Certificate
                </a>
                <a class="action-btn-compact secondary" href="manage-calendar.php">
                    <i class="fas fa-calendar-plus"></i> Add Event
                </a>
                <a class="action-btn-compact secondary" href="manage-announcements.php">
                    <i class="fas fa-bullhorn"></i> Post Announcement
                </a>
            </nav>

            <!-- Compact 4-Column Stat Cards Grid (8 Key Metrics) -->
            <div class="dashboard-stats-grid">
                <!-- 1. Total Parishioners -->
                <a href="manage-users.php" class="stat-card-compact" aria-label="View total parishioners">
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
                <a href="manage-requests.php" class="stat-card-compact" aria-label="View all requests">
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
                <a href="manage-requests.php?status=pending" class="stat-card-compact" aria-label="View pending requests">
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
                <a href="manage-records.php" class="stat-card-compact" aria-label="View sacramental records">
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
                <a href="manage-reservations.php" class="stat-card-compact" aria-label="Manage event reservations">
                    <div class="stat-card-header">
                        <span class="stat-card-label">Event Reservations</span>
                        <span class="stat-card-icon icon-purple"><i class="fas fa-calendar-check"></i></span>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($kpis['total_reservations']); ?></div>
                    <div class="stat-card-footer">
                        <span class="trend-pill neutral"><i class="fas fa-calendar-alt"></i> Scheduled</span>
                    </div>
                </a>

                <!-- 6. Active Announcements -->
                <a href="manage-announcements.php" class="stat-card-compact" aria-label="View active announcements">
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
                <a href="manage-calendar.php" class="stat-card-compact" aria-label="View calendar schedules">
                    <div class="stat-card-header">
                        <span class="stat-card-label">Schedules &amp; Events</span>
                        <span class="stat-card-icon icon-cyan"><i class="fas fa-calendar-days"></i></span>
                    </div>
                    <div class="stat-card-value"><?php echo number_format($kpis['active_schedules']); ?></div>
                    <div class="stat-card-footer">
                        <span class="trend-pill neutral"><i class="fas fa-clock"></i> Approved</span>
                    </div>
                </a>

                <!-- 8. Audit Logs -->
                <a href="audit-logs.php" class="stat-card-compact" aria-label="View audit logs">
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

            <!-- Operational Tables & Activity Section -->
            <section class="dashboard-content-grid">
                <!-- Recent Requests Table -->
                <div class="premium-panel">
                    <div class="premium-panel-header">
                        <h2 class="premium-panel-title">Recent Certificate Requests</h2>
                        <a class="dashboard-view-link" href="manage-requests.php">View All &rarr;</a>
                    </div>
                    <div class="table-responsive">
                        <table class="dashboard-recent-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Parishioner</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_requests)): ?>
                                    <?php foreach ($recent_requests as $req): ?>
                                        <?php $request_status = strtolower($req['status'] ?? 'pending'); ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($req['reference_number'] ?? 'N/A'); ?></strong></td>
                                            <td><?php echo htmlspecialchars($req['fullname'] ?? 'Unknown'); ?></td>
                                            <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $req['request_type'] ?? 'Request'))); ?></td>
                                            <td><span class="trend-pill <?php echo ($request_status === 'approved' || $request_status === 'completed') ? 'success' : (($request_status === 'rejected') ? 'danger' : 'warning'); ?>"><?php echo htmlspecialchars(ucfirst($request_status)); ?></span></td>
                                            <td><?php echo !empty($req['date_requested']) ? date('M d, Y', strtotime($req['date_requested'])) : 'N/A'; ?></td>
                                            <td><a class="dashboard-view-link" href="request-workflow.php?id=<?php echo intval($req['request_id']); ?>">View</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">No recent requests found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Activity Panel -->
                <aside class="premium-panel">
                    <div class="premium-panel-header">
                        <h2 class="premium-panel-title">Recent Activity</h2>
                        <a class="dashboard-view-link" href="audit-logs.php">View All &rarr;</a>
                    </div>
                    <div class="dashboard-activity-list">
                        <?php if (!empty($recent_requests)): ?>
                            <?php foreach (array_slice($recent_requests, 0, 5) as $activity): ?>
                                <?php $activity_status = strtolower($activity['status'] ?? 'pending'); ?>
                                <a class="dashboard-activity-item" href="request-workflow.php?id=<?php echo intval($activity['request_id']); ?>">
                                    <span class="dashboard-activity-icon <?php echo htmlspecialchars($activity_status); ?>">
                                        <i class="fas fa-file-lines"></i>
                                    </span>
                                    <div>
                                        <strong><?php echo htmlspecialchars($activity['fullname'] ?? 'Parishioner'); ?></strong>
                                        submitted <?php echo htmlspecialchars(strtolower(str_replace('_', ' ', $activity['request_type'] ?? 'a request'))); ?>.
                                        <small><?php echo !empty($activity['date_requested']) ? date('M d, Y h:i A', strtotime($activity['date_requested'])) : 'Recently'; ?></small>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">No recent activity yet.</div>
                        <?php endif; ?>
                    </div>
                </aside>
            </section>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/components.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const themeToggle = document.getElementById('adminThemeToggle');
            if (localStorage.getItem('parishTheme') === 'dark') {
                document.body.dataset.theme = 'dark';
            }
            if (themeToggle) {
                themeToggle.addEventListener('click', function() {
                    const isDark = document.body.dataset.theme === 'dark';
                    document.body.dataset.theme = isDark ? 'light' : 'dark';
                    localStorage.setItem('parishTheme', isDark ? 'light' : 'dark');
                });
            }
        });
    </script>
</body>
</html>
