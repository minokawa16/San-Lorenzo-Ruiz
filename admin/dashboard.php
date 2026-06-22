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
    define('BASE_URL', 'http://localhost/ParishSystem/');
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
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'deleted_at'");
    if ($result && $result->num_rows > 0) {
        return;
    }

    $conn->query("ALTER TABLE `$table` ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
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
    <style>
        :root {
            --primary-navy: #1a1f3a;
            --primary-royal-blue: #004085;
            --primary-gold: #d4af37;
            --status-success: #28a745;
            --status-warning: #ffc107;
            --status-danger: #dc3545;
            --status-info: #17a2b8;
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.15);
            --space-sm: 8px;
            --space-md: 12px;
            --space-lg: 16px;
            --space-xl: 24px;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e8eef5 100%);
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
        }

        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        .admin-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px 20px;
            transition: margin-left 0.3s;
        }

        .admin-content.collapsed {
            margin-left: 70px;
        }

        .page-header {
            margin-bottom: 30px;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-navy);
            margin-bottom: 10px;
        }

        .page-subtitle {
            color: #6c757d;
            font-size: 0.95rem;
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .kpi-card {
            display: block;
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border: 1px solid #e0e7ff;
            border-left: 4px solid var(--primary-gold);
            color: inherit;
            text-decoration: none;
        }

        .kpi-card:hover,
        .kpi-card:focus {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            color: inherit;
            text-decoration: none;
            outline: 2px solid rgba(212, 175, 55, 0.35);
            outline-offset: 2px;
        }

        .kpi-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
        }

        .kpi-icon.users {
            background: #e3f2fd;
            color: #1976d2;
        }

        .kpi-icon.requests {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .kpi-icon.pending {
            background: #fff3e0;
            color: #f57c00;
        }

        .kpi-icon.records {
            background: #e8f5e9;
            color: #388e3c;
        }

        .kpi-icon.reservations {
            background: #fce4ec;
            color: #c2185b;
        }

        .kpi-icon.announcements {
            background: #ede7f6;
            color: #512da8;
        }

        .kpi-icon.schedules {
            background: #e0f2fe;
            color: #0369a1;
        }

        .kpi-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 8px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kpi-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-navy);
            margin-bottom: 10px;
        }

        .kpi-change {
            font-size: 0.85rem;
            color: var(--status-success);
        }

        .kpi-change.negative {
            color: var(--status-danger);
        }

        /* Dashboard Sections */
        .dashboard-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--primary-gold);
        }

        .section-title {
            font-size: 1.35rem;
            font-weight: 600;
            color: var(--primary-navy);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--primary-gold);
        }

        /* Tables */
        .admin-table {
            width: 100%;
            border-collapse: collapse;
        }

        .admin-table thead {
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
        }

        .admin-table th {
            padding: 15px;
            font-weight: 600;
            color: var(--primary-navy);
            text-align: left;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .admin-table td {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
            color: #6c757d;
        }

        .admin-table tbody tr:hover {
            background: #f8f9fa;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-pending {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge-processing {
            background: #fff3e0;
            color: #f57c00;
        }

        .badge-approved {
            background: #e8f5e9;
            color: #388e3c;
        }

        .badge-completed {
            background: #e0f2f1;
            color: #00796b;
        }

        .badge-rejected {
            background: #ffebee;
            color: #d32f2f;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
        }

        .action-btn {
            background: var(--primary-royal-blue);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .action-btn:hover {
            background: var(--primary-navy);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            color: white;
        }

        .action-btn.gold {
            background: var(--primary-gold);
            color: var(--primary-navy);
        }

        .action-btn.gold:hover {
            background: #e8c547;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .admin-content {
                margin-left: 70px;
                padding: 20px 15px;
            }

            .kpi-grid {
                grid-template-columns: 1fr;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .admin-table {
                font-size: 0.85rem;
            }

            .admin-table th,
            .admin-table td {
                padding: 10px;
            }
        }

        /* Animation */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .kpi-card, .dashboard-section {
            animation: fadeInUp 0.6s ease-out;
        }
    </style>
    <link rel="stylesheet" href="../assets/css/premium-parish.css">
</head>
<body class="premium-admin">
    <div class="premium-admin-shell">
        <!-- Include Admin Sidebar -->
        <?php include '../includes/admin-sidebar.php'; ?>

        <!-- Main Content -->
        <div class="premium-admin-content">
            <header class="premium-admin-topbar premium-glass">
                <label class="premium-search" for="adminSmartSearch">
                    <i class="fas fa-magnifying-glass"></i>
                    <input id="adminSmartSearch" type="search" placeholder="Smart search parishioners, records, certificates, reservations...">
                </label>
                <div class="premium-admin-actions">
                    <button class="premium-icon-btn" type="button" id="adminThemeToggle" aria-label="Toggle dark mode">
                        <i class="fas fa-moon"></i>
                    </button>
                    <button class="premium-icon-btn" type="button" aria-label="Notifications">
                        <i class="fas fa-bell"></i>
                    </button>
                </div>
            </header>

            <!-- Page Header -->
            <section class="premium-admin-hero">
                <div>
                    <span class="premium-pill landing-eyebrow"><i class="fas fa-cross"></i> Parish command center</span>
                    <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['fullname']); ?>.</h1>
                    <p>Monitor parishioners, sacramental records, certificate requests, reservations, announcements, audits, and AI-assisted workflows from one calm administrative workspace.</p>
                </div>
                <div class="hero-orb" aria-hidden="true">
                    <i class="fas fa-church"></i>
                </div>
            </section>

            <!-- KPI Cards -->
            <div class="premium-kpi-grid">
                <!-- Total Users -->
                <a href="manage-users.php" class="premium-kpi-card premium-glass" aria-label="View total parishioners">
                    <div class="premium-kpi-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="premium-kpi-label">Total Parishioners</div>
                    <div class="premium-kpi-value"><?php echo $kpis['total_users']; ?></div>
                    <div class="premium-kpi-note">
                        <i class="fas fa-arrow-up"></i> Active users
                    </div>
                </a>

                <!-- Total Requests -->
                <a href="manage-requests.php" class="premium-kpi-card premium-glass" aria-label="View all requests">
                    <div class="premium-kpi-icon">
                        <i class="fas fa-list-check"></i>
                    </div>
                    <div class="premium-kpi-label">Total Requests</div>
                    <div class="premium-kpi-value"><?php echo $kpis['total_requests']; ?></div>
                    <div class="premium-kpi-note">
                        <i class="fas fa-chart-line"></i> All time
                    </div>
                </a>

                <!-- Pending Requests -->
                <a href="manage-requests.php?status=pending" class="premium-kpi-card premium-glass" aria-label="View pending requests">
                    <div class="premium-kpi-icon">
                        <i class="fas fa-hourglass-end"></i>
                    </div>
                    <div class="premium-kpi-label">Pending Requests</div>
                    <div class="premium-kpi-value"><?php echo $kpis['pending_requests']; ?></div>
                    <div class="premium-kpi-note">
                        <?php echo ($kpis['pending_requests'] > 5) ? '<i class="fas fa-arrow-up"></i>' : '<i class="fas fa-arrow-down"></i>'; ?>
                        <?php echo ($kpis['pending_requests'] > 5) ? 'Action needed!' : 'Under control'; ?>
                    </div>
                </a>

                <!-- Total Records -->
                <a href="manage-records.php" class="premium-kpi-card premium-glass" aria-label="View sacramental records">
                    <div class="premium-kpi-icon">
                        <i class="fas fa-archive"></i>
                    </div>
                    <div class="premium-kpi-label">Sacramental Records</div>
                    <div class="premium-kpi-value"><?php echo $kpis['total_records']; ?></div>
                    <div class="premium-kpi-note">
                        <i class="fas fa-database"></i> Digitized
                    </div>
                </a>

                <!-- Reservations -->
                <a href="manage-reservations.php" class="premium-kpi-card premium-glass" aria-label="Manage event reservations">
                    <div class="premium-kpi-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="premium-kpi-label">Event Reservations</div>
                    <div class="premium-kpi-value"><?php echo $kpis['total_reservations']; ?></div>
                    <div class="premium-kpi-note">
                        <i class="fas fa-calendar-alt"></i> Scheduled
                    </div>
                </a>

                <!-- Announcements -->
                <a href="manage-announcements.php" class="premium-kpi-card premium-glass" aria-label="View active announcements">
                    <div class="premium-kpi-icon">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <div class="premium-kpi-label">Active Announcements</div>
                    <div class="premium-kpi-value"><?php echo $kpis['active_announcements']; ?></div>
                    <div class="premium-kpi-note">
                        <i class="fas fa-megaphone"></i> Live now
                    </div>
                </a>

                <!-- Calendar Schedules -->
                <a href="manage-calendar.php" class="premium-kpi-card premium-glass" aria-label="View calendar schedules">
                    <div class="premium-kpi-icon">
                        <i class="fas fa-calendar-days"></i>
                    </div>
                    <div class="premium-kpi-label">Calendar Schedules</div>
                    <div class="premium-kpi-value"><?php echo $kpis['active_schedules']; ?></div>
                    <div class="premium-kpi-note">
                        <i class="fas fa-clock"></i> Approved events
                    </div>
                </a>

                <a href="audit-logs.php" class="premium-kpi-card premium-glass" aria-label="View audit logs">
                    <div class="premium-kpi-icon">
                        <i class="fas fa-shield-halved"></i>
                    </div>
                    <div class="premium-kpi-label">Audit Logs</div>
                    <div class="premium-kpi-value">Live</div>
                    <div class="premium-kpi-note">
                        <i class="fas fa-lock"></i> Tracking enabled
                    </div>
                </a>
            </div>

            <!-- Recent Requests -->
            <div class="premium-dashboard-grid">
            <section class="premium-panel premium-glass">
                <div class="premium-panel-header">
                    <h2 class="premium-panel-title"><i class="fas fa-history"></i> Recent Certificate Requests</h2>
                    <a href="manage-requests.php" class="premium-btn secondary">View All</a>
                </div>
                <div class="premium-table-wrap">
                    <table class="premium-admin-table">
                        <thead>
                            <tr>
                                <th>Reference #</th>
                                <th>Parishioner</th>
                                <th>Request Type</th>
                                <th>Status</th>
                                <th>Date Submitted</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recent_requests) > 0): ?>
                                <?php foreach ($recent_requests as $req): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($req['reference_number'] ?? 'N/A'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($req['fullname']); ?></td>
                                        <td><?php echo str_replace('_', ' ', ucfirst($req['request_type'])); ?></td>
                                        <td>
                                            <span class="premium-status <?php echo strtolower($req['status']); ?>">
                                                <?php echo ucfirst($req['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($req['date_requested'])); ?></td>
                                        <td>
                                            <a href="request-workflow.php?id=<?php echo $req['request_id']; ?>" class="premium-btn primary" style="min-height: 34px; padding: 6px 12px; font-size: 0.82rem;">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 30px; color: #6c757d;">
                                        <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 10px;"></i><br>
                                        No recent requests found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <aside class="premium-panel premium-glass" id="ai-assistant">
                <div class="premium-panel-header">
                    <h2 class="premium-panel-title"><i class="fas fa-robot"></i> AI Assistant</h2>
                </div>
                <div class="ai-command-card">
                    <strong>Ask TUGON AI</strong>
                    <span>Search records, automate parish inquiry responses, summarize pending requests, and recommend next administrative actions.</span>
                    <textarea placeholder="Example: Summarize pending certificate requests this week."></textarea>
                    <a class="premium-btn primary" href="ai-assistant.php"><i class="fas fa-wand-magic-sparkles"></i> Open Assistant</a>
                </div>

                <hr>

                <div class="premium-panel-header">
                    <h2 class="premium-panel-title"><i class="fas fa-calendar"></i> Reservation Calendar</h2>
                </div>
                <div class="mini-calendar" aria-label="Reservation availability calendar">
                    <?php for ($day = 1; $day <= 28; $day++): ?>
                        <span class="<?php echo in_array($day, [4, 12, 19, 26], true) ? 'active' : ''; ?>"><?php echo $day; ?></span>
                    <?php endfor; ?>
                </div>

                <hr>

                <div class="schedule-list">
                    <div class="schedule-row"><span class="date-tile"><i class="fas fa-circle-dot"></i></span><div><strong>Pending</strong><br><span>Awaiting parish review</span></div></div>
                    <div class="schedule-row"><span class="date-tile"><i class="fas fa-check"></i></span><div><strong>Approved</strong><br><span>Ready for confirmation</span></div></div>
                    <div class="schedule-row"><span class="date-tile"><i class="fas fa-certificate"></i></span><div><strong>Certificate Preview</strong><br><span>QR, watermark, and digital signature ready</span></div></div>
                </div>
            </aside>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/components.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        // Admin sidebar toggle for mobile
        document.addEventListener('DOMContentLoaded', function() {
            const adminSidebarToggle = document.getElementById('adminSidebarToggle');
            const adminSidebar = document.querySelector('.admin-sidebar');
            const adminContent = document.querySelector('.premium-admin-content');
            const themeToggle = document.getElementById('adminThemeToggle');
            
            if (adminSidebarToggle) {
                adminSidebarToggle.addEventListener('click', function() {
                    adminSidebar.classList.toggle('expanded');
                    adminContent.classList.toggle('collapsed');
                });
            }

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


