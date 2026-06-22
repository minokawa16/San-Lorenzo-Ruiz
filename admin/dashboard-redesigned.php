<?php
/**
 * Admin Dashboard - REDESIGNED
 * Modern, Holy-Themed Professional Interface
 * Fixed: Missing process-request.php connection
 * Updated: May 8, 2026
 */

// Include security and dependencies
include '../config/security.php';
include '../includes/Security.php';
include '../includes/Logger.php';
include '../database/BaseDB.php';
include '../database/config.php';
include '../includes/session.php';
include '../includes/helpers.php';

// Check admin access
requireAdmin();

// Initialize components
$logger = new Logger();
$db = new BaseDB($conn);

// Get dashboard statistics
try {
    // Users count
    $total_users = $db->count("SELECT COUNT(*) as count FROM users WHERE role = 'user' AND deleted_at IS NULL");
    
    // Requests stats
    $pending_requests = $db->count("SELECT COUNT(*) as count FROM requests WHERE status = 'pending' AND deleted_at IS NULL");
    $approved_requests = $db->count("SELECT COUNT(*) as count FROM requests WHERE status = 'approved' AND deleted_at IS NULL");
    $completed_requests = $db->count("SELECT COUNT(*) as count FROM requests WHERE status = 'completed' AND deleted_at IS NULL");
    $total_requests = $pending_requests + $approved_requests + $completed_requests + 
                      $db->count("SELECT COUNT(*) as count FROM requests WHERE status = 'rejected' AND deleted_at IS NULL") +
                      $db->count("SELECT COUNT(*) as count FROM requests WHERE status = 'processing' AND deleted_at IS NULL");

    // Sacramental records
    $total_records = 0;
    foreach (['baptism_records', 'first_communion_records', 'confirmation_records', 'marriage_records'] as $table) {
        $total_records += $db->count("SELECT COUNT(*) as count FROM $table WHERE deleted_at IS NULL");
    }

} catch (Exception $e) {
    $logger->error("Dashboard stats error: " . $e->getMessage());
    $total_users = $pending_requests = $approved_requests = $completed_requests = $total_records = 0;
}

// Get recent pending requests
$recent_sql = "SELECT r.*, u.fullname, u.email FROM requests r 
              JOIN users u ON r.user_id = u.id 
              WHERE r.status = 'pending' AND r.deleted_at IS NULL 
              ORDER BY r.date_requested DESC 
              LIMIT 5";
$recent_requests = $db->select($recent_sql);

// Get recent notifications
$notifications_sql = "SELECT * FROM notifications_log 
                     WHERE status = 'pending' 
                     ORDER BY created_at DESC 
                     LIMIT 5";
$notifications = $db->select($notifications_sql);

$page_title = 'Admin Dashboard';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Parish Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/holy-theme.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e8eef5 100%);
            font-family: 'Poppins', 'Inter', sans-serif;
        }

        .navbar-holy {
            background: linear-gradient(135deg, #1E3A5F 0%, #0D2338 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-bottom: 3px solid #D4AF37;
            padding: 15px 30px;
        }

        .navbar-holy .brand {
            color: #D4AF37;
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar {
            background: white;
            border-right: 1px solid #E5E7EB;
            min-height: calc(100vh - 80px);
            padding: 20px;
            position: fixed;
            width: 250px;
            left: 0;
            top: 80px;
            overflow-y: auto;
        }

        .main-content {
            margin-left: 250px;
            padding: 30px;
            min-height: calc(100vh - 80px);
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            margin-bottom: 8px;
            border-radius: 8px;
            color: #6B7280;
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
            cursor: pointer;
        }

        .menu-item:hover {
            background: #F5F7FA;
            color: #1E3A5F;
            transform: translateX(4px);
        }

        .menu-item.active {
            background: linear-gradient(135deg, #1E3A5F 0%, #10B981 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .menu-item i {
            width: 20px;
            text-align: center;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            border-top: 4px solid #D4AF37;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: -50px;
            width: 150px;
            height: 150px;
            background: radial-gradient(circle, rgba(212, 175, 55, 0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .stat-card .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 15px;
        }

        .stat-card .stat-label {
            font-size: 12px;
            text-transform: uppercase;
            color: #9CA3AF;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .stat-card .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1E3A5F;
        }

        .icon-users {
            background: linear-gradient(135deg, #E0F2FE 0%, #BAE6FD 100%);
            color: #1E3A5F;
        }

        .icon-pending {
            background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
            color: #B45309;
        }

        .icon-approved {
            background: linear-gradient(135deg, #DCFCE7 0%, #BBF7D0 100%);
            color: #15803D;
        }

        .icon-records {
            background: linear-gradient(135deg, #F3E8FF 0%, #E9D5FF 100%);
            color: #6B21A8;
        }

        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: #1E3A5F;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-title::before {
            content: '';
            width: 4px;
            height: 24px;
            background: linear-gradient(180deg, #D4AF37 0%, #10B981 100%);
            border-radius: 2px;
        }

        .request-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .request-table table {
            margin-bottom: 0;
        }

        .request-table thead {
            background: linear-gradient(135deg, #1E3A5F 0%, #0D2338 100%);
            color: white;
        }

        .request-table thead th {
            padding: 15px 20px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
            border: none;
        }

        .request-table tbody tr {
            border-bottom: 1px solid #E5E7EB;
            transition: all 0.3s ease;
        }

        .request-table tbody tr:hover {
            background: #F5F7FA;
        }

        .request-table tbody td {
            padding: 15px 20px;
            vertical-align: middle;
        }

        .badge-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-pending {
            background: #FEF3C7;
            color: #78350F;
        }

        .badge-approved {
            background: #DCFCE7;
            color: #15803D;
        }

        .badge-completed {
            background: #DBEAFE;
            color: #0C2D6B;
        }

        .review-btn {
            background: linear-gradient(135deg, #1E3A5F 0%, #10B981 100%);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .review-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
            color: white;
            text-decoration: none;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #9CA3AF;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }

            .main-content {
                margin-left: 0;
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar-holy">
        <div class="brand">
            <i class="fas fa-church"></i>
            Parish Management
        </div>
    </nav>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="menu-section">
            <a href="dashboard.php" class="menu-item active">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="manage-requests.php" class="menu-item">
                <i class="fas fa-tasks"></i>
                <span>Manage Requests</span>
            </a>
            <a href="manage-records.php" class="menu-item">
                <i class="fas fa-database"></i>
                <span>Sacramental Records</span>
            </a>
            <a href="manage-certificates.php" class="menu-item">
                <i class="fas fa-certificate"></i>
                <span>Certificates</span>
            </a>
            <a href="manage-users.php" class="menu-item">
                <i class="fas fa-users"></i>
                <span>Users</span>
            </a>
            <a href="reports.php" class="menu-item">
                <i class="fas fa-chart-bar"></i>
                <span>Reports</span>
            </a>
            <a href="manage-announcements.php" class="menu-item">
                <i class="fas fa-bullhorn"></i>
                <span>Announcements</span>
            </a>
        </div>
        <hr style="margin: 20px 0; border-color: #E5E7EB;">
        <div>
            <p style="font-size: 11px; color: #9CA3AF; text-transform: uppercase; margin-bottom: 10px;">Account</p>
            <a href="../auth/profile.php" class="menu-item">
                <i class="fas fa-user-circle"></i>
                <span>Profile</span>
            </a>
            <a href="../auth/logout.php" class="menu-item" style="color: #EF4444;">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Welcome Header -->
        <div style="margin-bottom: 30px;">
            <h1 style="color: #1E3A5F; margin-bottom: 5px;">Welcome back, <?php echo htmlspecialchars($_SESSION['fullname']); ?>! 🙏</h1>
            <p style="color: #9CA3AF; margin: 0;">Here's what's happening in your parish today</p>
        </div>

        <!-- Statistics Cards -->
        <div class="row">
            <div class="col-md-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon icon-users">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-label">Total Users</div>
                    <div class="stat-value"><?php echo $total_users; ?></div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon icon-pending">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                    <div class="stat-label">Pending Requests</div>
                    <div class="stat-value"><?php echo $pending_requests; ?></div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon icon-approved">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-label">Approved Requests</div>
                    <div class="stat-value"><?php echo $approved_requests; ?></div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon icon-records">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stat-label">Sacramental Records</div>
                    <div class="stat-value"><?php echo $total_records; ?></div>
                </div>
            </div>
        </div>

        <!-- Recent Requests Section -->
        <div style="margin-top: 40px;">
            <div class="section-title">
                <i class="fas fa-clock"></i>
                Recent Pending Requests
            </div>

            <?php if (!empty($recent_requests)): ?>
                <div class="request-table">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Reference #</th>
                                <th>User</th>
                                <th>Request Type</th>
                                <th>Submitted</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_requests as $request): ?>
                                <tr>
                                    <td><strong><?php echo $request['reference_number']; ?></strong></td>
                                    <td>
                                        <div style="font-weight: 600; color: #1E3A5F;">
                                            <?php echo htmlspecialchars($request['fullname']); ?>
                                        </div>
                                        <div style="font-size: 12px; color: #9CA3AF;">
                                            <?php echo htmlspecialchars($request['email']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $request['request_type'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($request['date_requested'])); ?></td>
                                    <td>
                                        <a href="request-workflow.php?id=<?php echo $request['request_id']; ?>" class="review-btn">
                                            <i class="fas fa-eye"></i> Review
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="request-table">
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p style="color: #9CA3AF;">No pending requests at this moment</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div style="margin-top: 40px;">
            <div class="section-title">
                <i class="fas fa-lightning-bolt"></i>
                Quick Actions
            </div>
            <div class="row">
                <div class="col-md-4">
                    <a href="manage-requests.php" class="btn btn-primary" style="width: 100%; padding: 12px; border-radius: 8px;">
                        <i class="fas fa-tasks"></i> View All Requests
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="generate-cert.php" class="btn btn-success" style="width: 100%; padding: 12px; border-radius: 8px;">
                        <i class="fas fa-file-pdf"></i> Generate Certificate
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="manage-announcements.php" class="btn btn-info" style="width: 100%; padding: 12px; border-radius: 8px;">
                        <i class="fas fa-bullhorn"></i> Make Announcement
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
