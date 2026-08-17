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
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
</head>
<body class="premium-admin">
    <div class="premium-admin-shell">
        <!-- Include Admin Sidebar -->
        <?php include '../includes/admin-sidebar.php'; ?>

        <!-- Main Content -->
        <div class="premium-admin-content">
            <header class="app-global-header premium-admin-topbar dashboard-topbar">
                <div class="app-header-left dashboard-title-block">
                    <div>
                        <h1>Dashboard</h1>
                        <p>Monitor parish activities, requests, records, and operations.</p>
                    </div>
                </div>
                <div class="app-header-center">
                    <form class="premium-search app-header-search" action="<?php echo BASE_URL; ?>admin/manage-users.php" method="GET">
                        <i class="fas fa-magnifying-glass"></i>
                        <input id="adminSmartSearch" name="search" type="search" placeholder="Search parishioners, requests, records...">
                        <kbd>Ctrl K</kbd>
                    </form>
                </div>
                <div class="app-header-right dashboard-header-tools">
                    <div class="dropdown">
                        <button class="profile-btn admin-profile-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="profile-avatar"><?php echo strtoupper(substr($_SESSION['fullname'] ?? 'A', 0, 1)); ?></span>
                            <span class="profile-meta">
                                <span class="profile-name"><?php echo $dashboard_profile_name; ?></span>
                                <span class="profile-role">Administrator</span>
                            </span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="../auth/profile.php"><i class="fas fa-user"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="../auth/profile.php"><i class="fas fa-gear"></i> Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../auth/logout.php"><i class="fas fa-arrow-right-from-bracket"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </header>

            <nav class="dashboard-quick-actions" aria-label="Quick dashboard actions">
                <a class="dashboard-action-btn primary" href="manage-requests.php">
                    <i class="fas fa-circle-plus"></i> New Request
                </a>
                <a class="dashboard-action-btn gold" href="certificate-generator.php">
                    <i class="fas fa-award"></i> Generate Certificate
                </a>
                <a class="dashboard-action-btn secondary" href="manage-calendar.php">
                    <i class="fas fa-calendar-plus"></i> Add Event
                </a>
                <a class="dashboard-action-btn secondary" href="manage-announcements.php">
                    <i class="fas fa-bullhorn"></i> Post Announcement
                </a>
            </nav>

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
                <a href="manage-requests.php?status=pending" class="premium-kpi-card premium-glass urgent" aria-label="View pending requests">
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

            <section class="dashboard-content-grid">
                <div class="premium-panel dashboard-table-panel">
                    <div class="premium-panel-header">
                        <h2 class="premium-panel-title">Recent Certificate Requests</h2>
                        <a class="dashboard-view-link" href="manage-requests.php">View All</a>
                    </div>
                    <div class="premium-table-wrap">
                        <table class="premium-admin-table dashboard-recent-table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Parishioner</th>
                                    <th>Request Type</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_requests)): ?>
                                    <?php foreach ($recent_requests as $req): ?>
                                        <?php $request_status = strtolower($req['status'] ?? 'pending'); ?>
                                        <tr>
                                            <td data-label="Reference"><strong><?php echo htmlspecialchars($req['reference_number'] ?? 'N/A'); ?></strong></td>
                                            <td data-label="Parishioner"><?php echo htmlspecialchars($req['fullname'] ?? 'Unknown'); ?></td>
                                            <td data-label="Request Type"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $req['request_type'] ?? 'Request'))); ?></td>
                                            <td data-label="Status"><span class="premium-status <?php echo htmlspecialchars($request_status); ?>"><?php echo htmlspecialchars(ucfirst($request_status)); ?></span></td>
                                            <td data-label="Date"><?php echo !empty($req['date_requested']) ? date('M d, Y', strtotime($req['date_requested'])) : 'N/A'; ?></td>
                                            <td data-label="Action"><a class="dashboard-view-link" href="request-workflow.php?id=<?php echo intval($req['request_id']); ?>">View</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="dashboard-empty-row">No recent requests found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <aside class="premium-panel dashboard-activity-panel">
                    <div class="premium-panel-header">
                        <h2 class="premium-panel-title">Recent Activity</h2>
                        <a class="dashboard-view-link" href="audit-logs.php">View All</a>
                    </div>
                    <div class="dashboard-activity-list">
                        <?php if (!empty($recent_requests)): ?>
                            <?php foreach (array_slice($recent_requests, 0, 6) as $activity): ?>
                                <?php $activity_status = strtolower($activity['status'] ?? 'pending'); ?>
                                <a class="dashboard-activity-item" href="request-workflow.php?id=<?php echo intval($activity['request_id']); ?>">
                                    <span class="dashboard-activity-icon <?php echo htmlspecialchars($activity_status); ?>">
                                        <i class="fas fa-file-lines"></i>
                                    </span>
                                    <span>
                                        <strong><?php echo htmlspecialchars($activity['fullname'] ?? 'Parishioner'); ?></strong>
                                        submitted <?php echo htmlspecialchars(strtolower(str_replace('_', ' ', $activity['request_type'] ?? 'a request'))); ?>.
                                        <small><?php echo !empty($activity['date_requested']) ? date('M d, Y h:i A', strtotime($activity['date_requested'])) : 'Recently'; ?></small>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="dashboard-empty-row">No recent activity yet.</div>
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
        // Admin sidebar toggle for mobile
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


