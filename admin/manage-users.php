<?php
/**
 * Manage Users Page
 * Admin interface for managing user accounts
 */

// Include centralized session management
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

// Require admin access
requireAdmin();
requirePermission('users.view');
ensureUserVerificationSchema($conn);

$error = '';
$success = '';

// Handle user status update
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') == 'POST') {
    requirePermission('users.manage');
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);
    
    if ($action == 'update_status') {
        $allowed_statuses = ['active', 'inactive', 'pending_verification', 'rejected', 'archived'];
        $status = $_POST['status'] ?? 'inactive';
        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'inactive';
        }
        if (transitionAccountStatus($conn, $user_id, $status, 'status_updated', null, (int) $_SESSION['user_id'])) {
            createAuditLog($conn, $_SESSION['user_id'], 'UPDATE_USER', 'users', $user_id);
            $success = 'Parishioner status updated successfully!';
        } else {
            $error = 'Error updating parishioner: ' . $conn->error;
        }
    } elseif ($action == 'archive_user') {
        if (transitionAccountStatus($conn, $user_id, 'archived', 'archived', null, (int) $_SESSION['user_id'])) {
            createAuditLog($conn, $_SESSION['user_id'], 'ARCHIVE_USER', 'users', $user_id);
            $success = 'Parishioner archived successfully!';
        } else {
            $error = 'Error archiving parishioner: ' . $conn->error;
        }
    }
}

// Get users
$search = $_GET['search'] ?? '';
$page = intval($_GET['page'] ?? 1);
$limit = 10;

$scope = $_GET['scope'] ?? '';
$where = $scope === 'archived' ? "WHERE u.role = 'user' AND u.status = 'archived'" : "WHERE u.role = 'user' AND u.status != 'archived'";
if (!empty($search)) {
    $search_escaped = $conn->real_escape_string($search);
    $where .= " AND (u.fullname LIKE '%$search_escaped%' OR u.email LIKE '%$search_escaped%' OR u.address LIKE '%$search_escaped%' OR u.chapel_district LIKE '%$search_escaped%')";
}

$total_result = $conn->query("SELECT COUNT(*) as count FROM users u $where");
$total = $total_result->fetch_assoc()['count'];
$pagination = getPaginationData($page, $limit, $total);

$sql = "SELECT u.*, verifier.fullname AS verified_by_name
    FROM users u
    LEFT JOIN users verifier ON u.verified_by = verifier.id
    $where
    ORDER BY u.created_at DESC
    LIMIT {$pagination['offset']}, {$pagination['limit']}";
$result = $conn->query($sql);
$users = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

function userDetailValue($value, $fallback = 'Not provided') {
    $value = trim((string) ($value ?? ''));
    return $value !== '' ? $value : $fallback;
}

function userDetailDate($value) {
    return !empty($value) ? formatDate($value) : 'Not provided';
}

function userDetailDateTime($value) {
    return !empty($value) ? formatDateTime($value) : 'Not provided';
}

$page_title = 'Manage Parishioners';
$body_extra_class = 'stable-detail-modals';

// Set breadcrumb data
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Manage Parishioners' => null
];

$hide_global_header = true; // Use the dedicated custom pixel-perfect header for Manage Parishioners
include '../templates/header.php'; 

$admin_display_name = !empty($_SESSION['fullname']) ? $_SESSION['fullname'] : 'TUGON Parish Admin';
$admin_avatar_letter = strtoupper(substr($admin_display_name, 0, 1));
?>

<style>
/* ── Theme Tokens & Typography ───────────────────────────────── */
:root {
    --brand-green-deep: #0E3321;
    --brand-green-forest: #143D28;
    --brand-green-light: #E8F0EA;
    --brand-gold-warm: #C59B27;
    --brand-gold-dark: #8A6409;
    --brand-gold-light: #FAF4E6;
    --bg-page-warm: #FAF8F5;
    --bg-card-pure: #FFFFFF;
    --bg-table-head: #F4EFE6;
    --border-warm-subtle: #EAE6DF;
    --border-warm-strong: #D8D2C6;
    --text-charcoal-dark: #1E293B;
    --text-charcoal-muted: #64748B;
    --badge-approved-bg: #DCFCE7;
    --badge-approved-text: #15803D;
    --badge-pending-bg: #FEF3C7;
    --badge-pending-text: #B45309;
}

body.premium-admin,
body.church-theme {
    background-color: var(--bg-page-warm) !important;
    font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: var(--text-charcoal-dark);
}


/* ── 2. Top Navigation Header (Seamless Transparent) ────────── */
.parish-top-nav-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 4px 0 20px 0;
    margin-bottom: 8px;
    width: 100%;
    flex-wrap: nowrap;
}

.parish-nav-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}

.parish-nav-badge-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: var(--brand-green-light);
    color: var(--brand-green-deep);
    border: 1px solid rgba(20, 61, 40, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
}

.parish-nav-left h1 {
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 1.55rem;
    font-weight: 700;
    color: var(--text-charcoal-dark);
    margin: 0;
    line-height: 1.15;
}

.parish-nav-left p {
    font-size: 0.8rem;
    color: var(--text-charcoal-muted);
    margin: 2px 0 0 0;
    font-weight: 500;
}

.parish-nav-center {
    flex: 1;
    max-width: 420px;
    position: relative;
}

.parish-nav-search-form {
    position: relative;
    width: 100%;
    display: flex;
    align-items: center;
}

.parish-nav-search-form .search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #94A3B8;
    font-size: 13px;
    pointer-events: none;
    z-index: 2;
}

.parish-nav-search-input {
    width: 100%;
    height: 38px;
    font-size: 0.82rem;
    border-radius: 999px;
    padding-left: 36px;
    padding-right: 64px;
    background: #FFFFFF;
    border: 1px solid var(--border-warm-subtle);
    color: var(--text-charcoal-dark);
    outline: none;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
    transition: all 0.15s ease;
}

.parish-nav-search-input:focus {
    border-color: var(--brand-gold-warm);
    box-shadow: 0 0 0 3px rgba(197, 155, 39, 0.15);
}

.parish-nav-search-form kbd {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 11px;
    color: #94A3B8;
    background: transparent;
    border: 1px solid var(--border-warm-subtle);
    border-radius: 6px;
    padding: 2px 6px;
    pointer-events: none;
}

.parish-nav-right {
    display: flex;
    align-items: center;
}

.parish-profile-pill-btn {
    height: 42px;
    background: #FFFFFF;
    border: 1px solid var(--border-warm-subtle);
    border-radius: 999px;
    padding: 4px 14px 4px 5px;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    color: var(--text-charcoal-dark);
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
    transition: all 0.15s ease;
}

.parish-profile-pill-btn:hover {
    background: #FAF8F5;
    border-color: var(--border-warm-strong);
}

.parish-profile-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #F0D9A8;
    color: #8A5A12;
    font-weight: 700;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.parish-profile-meta {
    display: flex;
    flex-direction: column;
    text-align: left;
    line-height: 1.15;
}

.parish-profile-name {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--text-charcoal-dark);
    white-space: nowrap;
}

.parish-profile-role {
    font-size: 0.68rem;
    color: var(--text-charcoal-muted);
    font-weight: 500;
}

/* ── 3. Page Title Section & Go Back Button ─────────────────── */
.parish-page-header-section {
    margin-bottom: 20px;
}

.parish-back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--brand-gold-dark);
    background: #FFFFFF;
    border: 1px solid var(--brand-gold-warm);
    padding: 6px 14px;
    border-radius: 8px;
    text-decoration: none;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
    transition: all 0.15s ease;
    margin-bottom: 14px;
}

.parish-back-link:hover {
    background: var(--brand-gold-light);
    color: #694b05;
    border-color: #A37E1C;
    transform: translateY(-1px);
}

.parish-section-title-wrap {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.parish-section-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-family: 'Playfair Display', Georgia, serif;
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-charcoal-dark);
    margin: 0;
}

.parish-section-icon-badge {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--brand-green-light);
    color: var(--brand-green-forest);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    border: 1px solid rgba(20, 61, 40, 0.2);
}

.parish-gold-underline {
    width: 56px;
    height: 3px;
    background: linear-gradient(90deg, var(--brand-gold-warm), transparent);
    border-radius: 2px;
    margin: 4px 0 6px 0;
}

.parish-section-subtitle {
    font-size: 0.85rem;
    color: var(--text-charcoal-muted);
    margin: 0;
}

/* ── 4. Main Data Card ──────────────────────────────────────── */
.parish-main-card {
    background: var(--bg-card-pure);
    border: 1px solid var(--border-warm-subtle);
    border-radius: 16px; /* rounded-2xl */
    box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05);
    padding: 24px;
    margin-bottom: 30px;
}

/* ── Filter & Search Control Row ────────────────────────────── */
.parish-filter-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
    width: 100%;
}

.parish-search-input-wrap {
    flex: 1;
    position: relative;
}

.parish-search-input-wrap .search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #94A3B8;
    font-size: 14px;
}

.parish-table-search-field {
    width: 100%;
    height: 42px;
    border-radius: 10px;
    border: 1px solid var(--border-warm-strong);
    background: #FFFFFF;
    padding: 0 14px 0 38px;
    font-size: 0.86rem;
    color: var(--text-charcoal-dark);
    outline: none;
    transition: all 0.15s ease;
}

.parish-table-search-field:focus {
    border-color: var(--brand-gold-warm);
    box-shadow: 0 0 0 3px rgba(197, 155, 39, 0.15);
}

.parish-search-submit-btn {
    height: 42px;
    border-radius: 10px;
    border: 1.5px solid var(--brand-gold-warm);
    background: var(--bg-page-warm);
    color: var(--brand-gold-dark);
    font-size: 0.86rem;
    font-weight: 700;
    padding: 0 22px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.15s ease;
}

.parish-search-submit-btn:hover {
    background: var(--brand-gold-warm);
    color: #FFFFFF;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(197, 155, 39, 0.25);
}

/* ── Toggle Tabs (Active vs. Archived) ──────────────────────── */
.parish-toggle-tabs {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 20px;
    background: rgba(0, 0, 0, 0.02);
    padding: 4px;
    border-radius: 10px;
    border: 1px solid var(--border-warm-subtle);
}

.parish-tab-btn {
    padding: 8px 18px;
    font-size: 0.82rem;
    font-weight: 700;
    border-radius: 8px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
    border: 1px solid transparent;
}

.parish-tab-btn.active {
    background: var(--brand-green-deep);
    color: #FFFFFF;
    border-color: var(--brand-green-deep);
    box-shadow: 0 2px 6px rgba(14, 51, 33, 0.2);
}

.parish-tab-btn.inactive {
    background: transparent;
    color: #475569;
    border-color: transparent;
}

.parish-tab-btn.inactive:hover {
    background: #FFFFFF;
    color: var(--text-charcoal-dark);
    border-color: var(--border-warm-subtle);
}

/* ── 5. Data Table Styling ──────────────────────────────────── */
.parish-table-container {
    overflow-x: auto;
    border-radius: 12px;
    border: 1px solid var(--border-warm-subtle);
    background: #FFFFFF;
}

.parish-data-table {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
    text-align: left;
}

.parish-data-table thead {
    background-color: var(--bg-table-head);
    border-bottom: 1px solid var(--border-warm-strong);
}

.parish-data-table th {
    padding: 12px 16px;
    font-size: 0.72rem;
    font-weight: 800;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    border: none;
    white-space: nowrap;
}

.parish-data-table tbody tr {
    border-bottom: 1px solid var(--border-warm-subtle);
    transition: background-color 0.12s ease;
}

.parish-data-table tbody tr:last-child {
    border-bottom: none;
}

.parish-data-table tbody tr:hover {
    background-color: #FAF9F6;
}

.parish-data-table td {
    padding: 14px 16px;
    font-size: 0.84rem;
    color: var(--text-charcoal-dark);
    vertical-align: middle;
    border: none;
}

/* Row elements */
.parish-user-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.parish-user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--brand-green-light);
    color: var(--brand-green-forest);
    font-weight: 700;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(20, 61, 40, 0.2);
    flex-shrink: 0;
}

.parish-user-name {
    font-weight: 700;
    color: var(--text-charcoal-dark);
    letter-spacing: 0.1px;
}

/* Status Badges */
.parish-status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.2px;
    white-space: nowrap;
}

.parish-status-badge.approved {
    background-color: var(--badge-approved-bg);
    color: var(--badge-approved-text);
    border: 1px solid rgba(21, 128, 61, 0.2);
}

.parish-status-badge.pending {
    background-color: var(--badge-pending-bg);
    color: var(--badge-pending-text);
    border: 1px solid rgba(180, 83, 9, 0.2);
}

.parish-status-badge.archived,
.parish-status-badge.inactive {
    background-color: #F1F5F9;
    color: #475569;
    border: 1px solid #CBD5E1;
}

.parish-status-badge.rejected {
    background-color: #FEE2E2;
    color: #DC2626;
    border: 1px solid rgba(220, 38, 38, 0.2);
}

/* Action Buttons */
.parish-action-btns {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.parish-btn-action {
    height: 32px;
    padding: 0 12px;
    border-radius: 6px;
    font-size: 0.78rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    text-decoration: none;
    border: 1px solid var(--brand-gold-warm);
    background: #FFFFFF;
    color: var(--brand-gold-dark);
    cursor: pointer;
    transition: all 0.15s ease;
}

.parish-btn-action:hover {
    background: var(--brand-gold-light);
    color: #694b05;
    border-color: #A37E1C;
    transform: translateY(-1px);
}

.parish-btn-action.archive {
    border-color: #CBD5E1;
    color: #475569;
}

.parish-btn-action.archive:hover {
    background: #F1F5F9;
    border-color: #94A3B8;
    color: #1E293B;
}

/* ── 6. Mobile Responsiveness Card View Fallback ────────────── */
@media (max-width: 860px) {
    .parish-top-nav-bar {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    .parish-nav-center {
        max-width: 100%;
        width: 100%;
    }
    .parish-nav-right {
        align-self: flex-end;
        margin-top: -46px;
    }
    .parish-filter-row {
        flex-direction: column;
    }
    .parish-search-submit-btn {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 720px) {
    .parish-data-table,
    .parish-data-table tbody,
    .parish-data-table tr,
    .parish-data-table td {
        display: block;
        width: 100%;
    }
    .parish-data-table thead {
        display: none;
    }
    .parish-data-table tbody tr {
        margin-bottom: 14px;
        border: 1px solid var(--border-warm-subtle);
        border-radius: 10px;
        padding: 12px;
        background: #FFFFFF;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    }
    .parish-data-table td {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 4px;
        border-bottom: 1px dashed #F1EFE8;
    }
    .parish-data-table td:last-child {
        border-bottom: none;
        padding-top: 12px;
        justify-content: flex-end;
    }
    .parish-data-table td::before {
        content: attr(data-label);
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--text-charcoal-muted);
    }
}
</style>

<div class="container-fluid px-0">


    <!-- 2. Main Navigation Header (Seamless Transparent) -->
    <header class="parish-top-nav-bar">
        <!-- Left: Badge Icon + Title & Subtitle -->
        <div class="parish-nav-left">
            <div class="parish-nav-badge-icon" aria-hidden="true">
                <i class="fas fa-users"></i>
            </div>
            <div>
                <h1>Parishioners</h1>
                <p>Manage parishioner accounts and verification.</p>
            </div>
        </div>

        <!-- Center: Rounded Pill Search Input with Ctrl K Badge -->
        <div class="parish-nav-center">
            <form class="parish-nav-search-form" action="<?php echo BASE_URL; ?>admin/manage-users.php" method="GET">
                <i class="fas fa-magnifying-glass search-icon" aria-hidden="true"></i>
                <input id="globalParishionerSearch" class="parish-nav-search-input" type="search" name="search" placeholder="Search parishioners..." value="<?php echo sanitize($search); ?>" autocomplete="off">
                <kbd>Ctrl K</kbd>
            </form>
        </div>

        <!-- Right: User Profile Pill with Avatar Initial -->
        <div class="parish-nav-right">
            <div class="dropdown">
                <button class="parish-profile-pill-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="parish-profile-avatar"><?php echo htmlspecialchars($admin_avatar_letter); ?></span>
                    <span class="parish-profile-meta">
                        <span class="parish-profile-name"><?php echo htmlspecialchars($admin_display_name); ?></span>
                        <span class="parish-profile-role">Administrator</span>
                    </span>
                    <i class="fas fa-chevron-down ms-1" style="font-size: 10px; color: #94A3B8;"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><a class="dropdown-item" href="../auth/profile.php"><i class="fas fa-user me-2 text-muted"></i> My Profile</a></li>
                    <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/settings.php"><i class="fas fa-gear me-2 text-muted"></i> Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-arrow-right-from-bracket me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </header>

    <!-- 3. Page Title Section -->
    <section class="parish-page-header-section">
        <!-- Go Back Button with Gold Accent -->
        <a href="<?php echo BASE_URL; ?>admin/dashboard.php" class="parish-back-link">
            <i class="fas fa-arrow-left"></i> Go Back
        </a>

        <!-- Section Title & Gold Underline -->
        <div class="parish-section-title-wrap">
            <h2 class="parish-section-title">
                <span class="parish-section-icon-badge">
                    <i class="fas fa-people-roof"></i>
                </span>
                Manage Parishioners
            </h2>
            <div class="parish-gold-underline"></div>
            <p class="parish-section-subtitle">Review parishioner accounts, verification status, and personal registry entries.</p>
        </div>
    </section>

    <!-- System Alerts -->
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 shadow-sm mb-4">
            <i class="fas fa-circle-exclamation me-2"></i><?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 shadow-sm mb-4">
            <i class="fas fa-circle-check me-2"></i><?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 4. Main Data Card -->
    <div class="parish-main-card">

        <!-- Filter & Search Control Row -->
        <form method="GET" action="manage-users.php" class="parish-filter-row">
            <input type="hidden" name="scope" value="<?php echo e($scope); ?>">
            <div class="parish-search-input-wrap">
                <i class="fas fa-magnifying-glass search-icon" aria-hidden="true"></i>
                <input id="tableSearchInput" type="text" class="parish-table-search-field" name="search" placeholder="Search by name, email, or address..." value="<?php echo sanitize($search); ?>" autocomplete="off">
            </div>
            <button class="parish-search-submit-btn" type="submit">
                <i class="fas fa-search"></i> Search
            </button>
        </form>

        <!-- Toggle Tabs (Active Parishioners vs. Archived Parishioners) -->
        <div class="parish-toggle-tabs" role="tablist">
            <a class="parish-tab-btn <?php echo $scope !== 'archived' ? 'active' : 'inactive'; ?>" href="manage-users.php">
                <i class="fas fa-user-check"></i> Active Parishioners
            </a>
            <a class="parish-tab-btn <?php echo $scope === 'archived' ? 'active' : 'inactive'; ?>" href="manage-users.php?scope=archived">
                <i class="fas fa-box-archive"></i> Archived Parishioners
            </a>
        </div>

        <!-- 5. Data Table -->
        <?php if (!empty($users)): ?>
            <div class="parish-table-container">
                <table class="parish-data-table" id="parishionerTable">
                    <thead>
                        <tr>
                            <th>NAME</th>
                            <th>EMAIL</th>
                            <th>PHONE</th>
                            <th>STATUS</th>
                            <th>JOINED</th>
                            <th style="text-align: right;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php 
                            $view_modal_id = 'viewUserModal' . intval($user['id']);
                            $name_initial = strtoupper(substr(trim($user['fullname'] ?? 'U'), 0, 1));
                            $user_status = strtolower($user['status'] ?? 'pending');
                            $is_approved = in_array($user_status, ['active', 'approved'], true);
                            ?>
                            <tr class="parish-user-row" data-name="<?php echo strtolower(e($user['fullname'])); ?>" data-email="<?php echo strtolower(e($user['email'])); ?>" data-phone="<?php echo strtolower(e($user['phone_number'] ?? '')); ?>">
                                <td data-label="NAME">
                                    <div class="parish-user-cell">
                                        <span class="parish-user-avatar"><?php echo htmlspecialchars($name_initial); ?></span>
                                        <span class="parish-user-name"><?php echo sanitize($user['fullname']); ?></span>
                                    </div>
                                </td>
                                <td data-label="EMAIL">
                                    <span class="text-muted"><?php echo $user['email'] ? e($user['email']) : '—'; ?></span>
                                </td>
                                <td data-label="PHONE">
                                    <span><?php echo e(!empty($user['phone_number']) ? $user['phone_number'] : '—'); ?></span>
                                </td>
                                <td data-label="STATUS">
                                    <?php if ($is_approved): ?>
                                        <span class="parish-status-badge approved">
                                            <i class="fas fa-check"></i> Approved
                                        </span>
                                    <?php elseif ($user_status === 'pending_verification'): ?>
                                        <span class="parish-status-badge pending">
                                            <i class="fas fa-clock"></i> Pending
                                        </span>
                                    <?php elseif ($user_status === 'rejected'): ?>
                                        <span class="parish-status-badge rejected">
                                            <i class="fas fa-times-circle"></i> Rejected
                                        </span>
                                    <?php elseif ($user_status === 'archived'): ?>
                                        <span class="parish-status-badge archived">
                                            <i class="fas fa-box-archive"></i> Archived
                                        </span>
                                    <?php else: ?>
                                        <span class="parish-status-badge inactive">
                                            <?php echo e(ucfirst($user_status)); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="JOINED">
                                    <span class="text-muted"><?php echo formatDate($user['created_at']); ?></span>
                                </td>
                                <td data-label="ACTIONS" style="text-align: right;">
                                    <div class="parish-action-btns">
                                        <button type="button" class="parish-btn-action" data-stable-modal-open="#<?php echo e($view_modal_id); ?>" title="View Parishioner Details">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if ($user['status'] !== 'archived'): ?>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Archive this parishioner? The account will be moved to the archived list.');">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="archive_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="parish-btn-action archive" title="Archive Parishioner">
                                                    <i class="fas fa-box-archive"></i> Archive
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Modals for Parishioner Details -->
            <?php foreach ($users as $user): ?>
                <?php
                $view_modal_id = 'viewUserModal' . intval($user['id']);
                $front_id_url = !empty($user['valid_id_path']) ? 'view-valid-id.php?id=' . intval($user['id']) . '&type=id' : '';
                $back_id_url = !empty($user['valid_id_back_path']) ? 'view-valid-id.php?id=' . intval($user['id']) . '&type=back' : '';
                $face_url = !empty($user['face_image_path']) ? 'view-valid-id.php?id=' . intval($user['id']) . '&type=face' : '';
                $can_view_documents = hasPermission('registrations.verify');
                ?>
                <div class="modal stable-detail-modal" id="<?php echo e($view_modal_id); ?>" tabindex="-1" role="dialog" aria-modal="true" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content rounded-4 border-0 shadow">
                            <div class="modal-header bg-light">
                                <h5 class="modal-title fw-bold text-dark">
                                    <i class="fas fa-user-circle me-2 text-success"></i><?php echo e($user['fullname']); ?>
                                </h5>
                                <button type="button" class="btn-close" data-stable-modal-close aria-label="Close parishioner details"></button>
                            </div>
                            <div class="modal-body p-4">
                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <h6 class="fw-bold border-bottom pb-2 mb-3 text-success">Personal Information</h6>
                                        <div class="row g-3">
                                            <div class="col-sm-6"><div class="text-muted small">Full Name</div><div class="fw-semibold"><?php echo e(userDetailValue($user['fullname'])); ?></div></div>
                                            <div class="col-sm-6"><div class="text-muted small">Birthdate</div><div class="fw-semibold"><?php echo e(userDetailDate($user['birthdate'] ?? '')); ?></div></div>
                                            <div class="col-sm-6"><div class="text-muted small">Birthplace</div><div class="fw-semibold"><?php echo e(userDetailValue($user['birth_place'] ?? '')); ?></div></div>
                                            <div class="col-sm-6"><div class="text-muted small">Civil Status</div><div class="fw-semibold"><?php echo e(userDetailValue($user['civil_status'] ?? '')); ?></div></div>
                                            <div class="col-sm-6"><div class="text-muted small">Gender/Sex</div><div class="fw-semibold"><?php echo e(userDetailValue($user['sex'] ?? $user['gender'] ?? '')); ?></div></div>
                                            <div class="col-sm-6"><div class="text-muted small">Nationality</div><div class="fw-semibold"><?php echo e(userDetailValue($user['nationality'] ?? '')); ?></div></div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <h6 class="fw-bold border-bottom pb-2 mb-3 text-success">Contact Information</h6>
                                        <div class="row g-3">
                                            <div class="col-sm-6"><div class="text-muted small">Email Address</div><div class="fw-semibold"><?php echo e(userDetailValue($user['email'] ?? '')); ?></div></div>
                                            <div class="col-sm-6"><div class="text-muted small">Phone Number</div><div class="fw-semibold"><?php echo e(userDetailValue($user['phone_number'] ?? '')); ?></div></div>
                                            <div class="col-12"><div class="text-muted small">Complete Home Address</div><div class="fw-semibold"><?php echo e(userDetailValue($user['address'] ?? '')); ?></div></div>
                                            <div class="col-12"><div class="text-muted small">Chapel / District</div><div class="fw-semibold"><?php echo e(userDetailValue($user['chapel_district'] ?? '')); ?></div></div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <h6 class="fw-bold border-bottom pb-2 mb-3 text-success">Sacramental Information</h6>
                                        <div class="row g-3">
                                            <div class="col-12"><div class="text-muted small">Registration Sacramental Fields</div><div class="fw-semibold">Not collected during account registration.</div></div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <h6 class="fw-bold border-bottom pb-2 mb-3 text-success">Account &amp; Verification Details</h6>
                                        <div class="row g-3">
                                            <div class="col-sm-6">
                                                <div class="text-muted small">Date Registered / Joined</div>
                                                <div class="fw-semibold"><?php echo e(userDetailDateTime($user['created_at'] ?? '')); ?></div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="text-muted small">Verification Status</div>
                                                <span class="badge bg-<?php echo e(getUserStatusBadgeClass($user['status'])); ?>"><?php echo e(getUserStatusLabel($user['status'])); ?></span>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="text-muted small">Date Verified</div>
                                                <div>
                                                    <?php 
                                                        if (in_array($user['status'], ['active', 'approved'], true)) {
                                                            $v_time = !empty($user['verified_at']) ? $user['verified_at'] : ($user['updated_at'] ?? $user['created_at']);
                                                            echo '<span class="fw-semibold">' . e(date('M d, Y h:i A', strtotime($v_time))) . '</span>';
                                                        } elseif ($user['status'] === 'pending_verification') {
                                                            echo '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pending Verification</span>';
                                                        } elseif ($user['status'] === 'rejected') {
                                                            echo '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Rejected</span>';
                                                        } else {
                                                            echo e(ucfirst(str_replace('_', ' ', (string) $user['status'])));
                                                        }
                                                    ?>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="text-muted small"><?php echo ($user['verification_method'] ?? '') === 'mobile' ? 'Registered Mobile' : 'Registered Email'; ?></div>
                                                <div class="fw-semibold">
                                                    <?php echo e(($user['verification_method'] ?? '') === 'mobile' ? ($user['phone_number'] ?: $user['email'] ?: 'Not provided') : ($user['email'] ?: $user['phone_number'] ?: 'Not provided')); ?>
                                                    <?php if (in_array($user['status'], ['active', 'approved'], true) || !empty($user['email_verified_at']) || !empty($user['phone_verified_at'])): ?>
                                                        <span class="badge bg-success ms-1"><i class="fas fa-check-circle"></i> Verified</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark ms-1"><i class="fas fa-clock"></i> Pending</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="text-muted small">Face Status</div>
                                                <div class="fw-semibold"><?php echo e(userDetailValue($user['face_verification_status'] ?? '')); ?></div>
                                            </div>
                                            <?php if (!empty($user['rejection_reason'])): ?>
                                                <div class="col-sm-6">
                                                    <div class="text-muted small">Rejection Reason</div>
                                                    <div class="text-danger fw-semibold"><?php echo e($user['rejection_reason']); ?></div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <h6 class="fw-bold border-bottom pb-2 mb-3 text-success">Submitted Documents</h6>
                                        <?php if (!$can_view_documents): ?>
                                            <div class="alert alert-warning mb-0">Document preview requires registration verification permission.</div>
                                        <?php else: ?>
                                            <div class="row g-3">
                                                <?php if ($front_id_url): ?>
                                                    <div class="col-md-4">
                                                        <a href="<?php echo e($front_id_url); ?>" target="_blank" class="d-block text-decoration-none">
                                                            <img src="<?php echo e($front_id_url); ?>" class="img-thumbnail mb-2" alt="Valid ID front" style="height: 150px; width: 100%; object-fit: cover;" onerror="this.onerror=null; this.src='../assets/img/document-placeholder.svg';">
                                                            <span class="btn btn-sm btn-outline-success w-100">Open Valid ID Front</span>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($back_id_url): ?>
                                                    <div class="col-md-4">
                                                        <a href="<?php echo e($back_id_url); ?>" target="_blank" class="d-block text-decoration-none">
                                                            <img src="<?php echo e($back_id_url); ?>" class="img-thumbnail mb-2" alt="Valid ID back" style="height: 150px; width: 100%; object-fit: cover;" onerror="this.onerror=null; this.src='../assets/img/document-placeholder.svg';">
                                                            <span class="btn btn-sm btn-outline-success w-100">Open Valid ID Back</span>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($face_url): ?>
                                                    <div class="col-md-4">
                                                        <a href="<?php echo e($face_url); ?>" target="_blank" class="d-block text-decoration-none">
                                                            <img src="<?php echo e($face_url); ?>" class="img-thumbnail mb-2" alt="Face verification image" style="height: 150px; width: 100%; object-fit: cover;" onerror="this.onerror=null; this.src='../assets/img/document-placeholder.svg';">
                                                            <span class="btn btn-sm btn-outline-success w-100">Open Face Image</span>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!$front_id_url && !$back_id_url && !$face_url): ?>
                                                    <div class="col-12"><div class="alert alert-info mb-0">No submitted documents found for this parishioner.</div></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer bg-light">
                                <button type="button" class="btn btn-secondary px-4" data-stable-modal-close>Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-users-slash fa-3x mb-3 text-secondary opacity-50"></i>
                <h5>No parishioners found</h5>
                <p class="small">Try adjusting your search criteria or switch between active and archived filters.</p>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
// Interactive Client-Side Real-Time Filter & Search Helper
document.addEventListener('DOMContentLoaded', function() {
    const tableSearchInput = document.getElementById('tableSearchInput');
    const tableRows = document.querySelectorAll('.parish-user-row');

    if (tableSearchInput) {
        tableSearchInput.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            tableRows.forEach(function(row) {
                const name = row.getAttribute('data-name') || '';
                const email = row.getAttribute('data-email') || '';
                const phone = row.getAttribute('data-phone') || '';
                if (name.includes(query) || email.includes(query) || phone.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }

    // Global Ctrl+K to focus search input
    window.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            const topSearch = document.getElementById('globalParishionerSearch');
            if (topSearch) {
                topSearch.focus();
                topSearch.select();
            }
        }
    });
});
</script>

<?php include '../templates/footer.php'; ?>
