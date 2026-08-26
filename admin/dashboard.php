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

// Ensure Dashboard Archive Column Function
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
$dashboard_profile_name = sanitize($_SESSION['fullname'] ?? 'TUGON Parish Admin');
$dashboard_avatar_letter = strtoupper(substr($dashboard_profile_name ?: 'T', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/holy-theme.css">
    <link rel="stylesheet" href="../assets/css/admin-sidebar.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/admin-sidebar.css') ? filemtime(__DIR__ . '/../assets/css/admin-sidebar.css') : time(); ?>">
    <style id="dashboard-custom-theme">
        /* ── Core Theme Palette & Layout ────────────────────────── */
        :root {
            --bg-cream: #F1EFE8;
            --border-warm: #d8d6cc;
            --text-primary: #1e293b;
            --text-secondary: #6b6a63;
            --text-muted: #9a9890;
            --avatar-bg: #F0D9A8;
            --avatar-text: #8a5a12;
            --card-bg: #ffffff;
            --brand-green: #1E2D24;
            --brand-gold: #c89b3c;
        }

        body.premium-admin {
            background-color: var(--bg-cream);
            color: var(--text-primary);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 0;
        }

        .premium-admin-shell {
            display: flex;
            min-height: 100vh;
        }

        .premium-admin-content {
            flex: 1;
            padding: 20px 24px 36px !important;
            max-width: 1600px;
            margin: 0 auto;
            min-width: 0;
        }

        /* ── 1. Refactored Header / Topbar ───────────────────────── */
        .dashboard-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: nowrap;
            padding: 4px 0 20px 0;
            margin-bottom: 12px;
        }

        .dashboard-header-title-block {
            flex-shrink: 0;
        }

        .dashboard-header-title-block h1 {
            font-family: 'Playfair Display', Georgia, 'Times New Roman', serif !important;
            font-size: 2.25rem !important;
            font-weight: 700 !important;
            color: #1e293b !important;
            line-height: 1.15 !important;
            margin: 0 !important;
            letter-spacing: -0.3px;
        }

        .dashboard-header-title-block p {
            font-size: 0.86rem !important;
            color: var(--text-secondary) !important;
            margin: 4px 0 0 0 !important;
            font-weight: 500;
        }

        /* ── Center: Search Input Pill ────────────────────────────── */
        .dashboard-header-search-wrap {
            flex: 1;
            max-width: 360px;
            min-width: 180px;
            position: relative;
        }

        .dashboard-header-search-form {
            position: relative;
            width: 100%;
            display: flex;
            align-items: center;
        }

        .dashboard-search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            color: var(--text-muted);
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dashboard-search-input {
            width: 100%;
            height: 42px;
            background: var(--card-bg);
            border: 1px solid var(--border-warm);
            border-radius: 999px;
            padding: 0 68px 0 38px;
            font-size: 0.82rem;
            color: var(--text-primary);
            font-weight: 500;
            outline: none;
            transition: all 0.15s ease;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
        }

        .dashboard-search-input::placeholder {
            color: var(--text-muted);
        }

        .dashboard-search-input:focus {
            border-color: var(--brand-gold);
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.15);
        }

        .dashboard-search-kbd {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 11px;
            color: var(--text-muted);
            background: transparent;
            border: 1px solid var(--border-warm);
            border-radius: 6px;
            padding: 2px 7px;
            font-family: inherit;
            pointer-events: none;
            line-height: 1.2;
        }

        /* ── Right: Profile Chip ──────────────────────────────────── */
        .dashboard-header-profile-wrap {
            flex-shrink: 0;
        }

        .profile-chip-btn {
            height: 44px;
            background: var(--card-bg);
            border: 1px solid var(--border-warm);
            border-radius: 999px;
            padding: 4px 14px 4px 5px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
            transition: all 0.15s ease;
        }

        .profile-chip-btn:hover,
        .profile-chip-btn:focus {
            background: #faf8f5;
            border-color: #c4c1b5;
        }

        .profile-chip-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background-color: var(--avatar-bg);
            color: var(--avatar-text);
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .profile-chip-meta {
            display: flex;
            flex-direction: column;
            text-align: left;
            line-height: 1.15;
        }

        .profile-chip-name {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--text-primary);
            white-space: nowrap;
        }

        .profile-chip-role {
            font-size: 0.7rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .profile-chip-chevron {
            width: 12px;
            height: 12px;
            color: var(--text-muted);
            margin-left: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ── 2. Quick Actions Toolbar ─────────────────────────────── */
        .dashboard-quick-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
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
            background: var(--brand-green);
            color: #ffffff;
            border: 1px solid var(--brand-green);
        }
        .action-btn-compact.primary:hover {
            background: #142018;
            border-color: #142018;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .action-btn-compact.gold {
            background: var(--brand-gold);
            color: #ffffff;
            border: 1px solid var(--brand-gold);
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
            border: 1px solid var(--border-warm);
        }
        .action-btn-compact.secondary:hover {
            background: #faf8f5;
            border-color: #c4c1b5;
            color: #0f172a;
            transform: translateY(-1px);
        }

        /* ── 3. High Density 4-Column Stat Cards ──────────────────── */
        .dashboard-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .stat-card-compact {
            background: #ffffff;
            border: 1px solid var(--border-warm);
            border-radius: 8px;
            padding: 12px 14px;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 94px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
            transition: all 0.15s ease;
        }

        .stat-card-compact:hover {
            transform: translateY(-2px);
            border-color: #c4c1b5;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            color: inherit;
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
            color: var(--text-secondary);
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
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
        }

        .stat-card-footer {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 0.68rem;
            font-weight: 500;
            color: var(--text-secondary);
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

        /* ── 4. Tables & Activity Panels ──────────────────────────── */
        .dashboard-content-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.75fr);
            gap: 14px;
            align-items: start;
        }

        .premium-panel {
            background: #ffffff;
            border: 1px solid var(--border-warm);
            border-radius: 8px;
            padding: 14px 16px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
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
            color: var(--brand-gold);
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
            color: var(--text-secondary);
            padding: 6px 8px;
            border-bottom: 1px solid var(--border-warm);
            background: #faf8f5;
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
            background: #faf8f5;
            border: 1px solid #f1f5f9;
            text-decoration: none;
            color: #334155;
            font-size: 0.76rem;
            transition: background 0.12s ease;
        }

        .dashboard-activity-item:hover {
            background: #f1efe8;
            color: #0f172a;
        }

        .dashboard-activity-item strong {
            color: #0f172a;
        }

        .dashboard-activity-item small {
            display: block;
            color: var(--text-muted);
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

        /* ── Responsiveness ───────────────────────────────────────── */
        @media (max-width: 1100px) {
            .dashboard-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 960px) {
            .dashboard-content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .dashboard-header-row {
                flex-wrap: wrap;
                gap: 12px;
            }
            .dashboard-header-search-wrap {
                order: 3;
                max-width: 100%;
                width: 100%;
            }
        }

        @media (max-width: 580px) {
            .dashboard-stats-grid {
                grid-template-columns: 1fr;
            }
            .dashboard-header-title-block h1 {
                font-size: 1.75rem !important;
            }
        }
    </style>
</head>
<body class="premium-admin">
    <div class="app-layout premium-admin-shell">
        <!-- Include Admin Sidebar -->
        <?php include '../includes/admin-sidebar.php'; ?>

        <!-- Main Content (Flex Sibling with min-width: 0) -->
        <main class="premium-admin-content main-content">

            <!-- 1. Refactored Header: Title, Search Pill, Profile Chip -->
            <!-- 1. Top Header Layout -->
            <header class="dashboard-header-row parish-top-nav-bar">
                <!-- Left: Muted Green Icon + Serif Title & Subtitle -->
                <div class="d-flex align-items-center gap-3 parish-nav-left">
                    <div class="parish-nav-badge-icon" aria-hidden="true">
                        <i class="fas fa-gauge-high"></i>
                    </div>
                    <div class="dashboard-header-title-block">
                        <h1>Dashboard</h1>
                        <p>Monitor parish activities, requests, records, and operations.</p>
                    </div>
                </div>

                <!-- Center: Pill Search Input with Inline SVG & Ctrl K Badge -->
                <div class="dashboard-header-search-wrap parish-nav-center">
                    <form class="dashboard-header-search-form parish-nav-search-form" action="<?php echo BASE_URL; ?>admin/manage-users.php" method="GET">
                        <i class="fas fa-magnifying-glass search-icon" aria-hidden="true"></i>
                        <input id="adminSmartSearch" class="dashboard-search-input parish-nav-search-input" name="search" type="search" placeholder="Search parishioners, requests..." autocomplete="off">
                        <kbd class="dashboard-search-kbd">Ctrl K</kbd>
                    </form>
                </div>

                <!-- Right: Profile Chip Button with Gold Avatar & Dropdown -->
                <div class="dashboard-header-profile-wrap parish-nav-right">
                    <div class="dropdown">
                        <button class="profile-chip-btn parish-profile-pill-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="profile-chip-avatar parish-profile-avatar"><?php echo htmlspecialchars($dashboard_avatar_letter); ?></span>
                            <span class="profile-chip-meta parish-profile-meta">
                                <span class="profile-chip-name parish-profile-name"><?php echo htmlspecialchars($dashboard_profile_name); ?></span>
                                <span class="profile-chip-role parish-profile-role">Administrator</span>
                            </span>
                            <i class="fas fa-chevron-down ms-1" style="font-size: 10px; color: #9a9890;"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li><a class="dropdown-item" href="../auth/profile.php"><i class="fas fa-user me-2 text-muted"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="settings.php"><i class="fas fa-gear me-2 text-muted"></i> Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-arrow-right-from-bracket me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </header>

            <!-- 2. Quick Action Toolbar -->
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

            <!-- 3. Compact 4-Column Stat Cards Grid (8 Key Metrics) -->
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

            <!-- 4. Operational Tables & Activity Section -->
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

        </main>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/components.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Global Ctrl+K shortcut listener to focus the search bar
            window.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                    e.preventDefault();
                    const searchInput = document.getElementById('adminSmartSearch');
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }
            });
        });
    </script>
</body>
</html>
