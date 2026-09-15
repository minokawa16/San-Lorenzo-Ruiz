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
        $archive_reason = trim((string)($_POST['archive_reason'] ?? '')) ?: 'Archived from user management';
        if (transitionAccountStatus($conn, $user_id, 'archived', 'archived', $archive_reason, (int) $_SESSION['user_id'])) {
            createAuditLog($conn, $_SESSION['user_id'], 'ARCHIVE_USER', 'users', $user_id);
            $success = 'Parishioner archived successfully! The record has been moved to the <a href="archives.php?tab=parishioners" class="alert-link text-decoration-underline fw-bold">Archives</a> section.';
        } else {
            $error = 'Error archiving parishioner: ' . $conn->error;
        }
    }
}

// ── Status Filter & Search Parameters ────────────────────────
$status_filter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($status_filter, ['all', 'pending', 'approved', 'rejected'], true)) {
    $status_filter = 'all';
}
$search = trim((string)($_GET['search'] ?? ''));
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;

// Base condition: always exclude archived and deleted records from main view
$where = "WHERE u.role = 'user' AND u.status != 'archived' AND u.status != 'deleted'";

// Verification status filter mapping
if ($status_filter === 'pending') {
    $where .= " AND u.status IN ('pending', 'pending_verification')";
} elseif ($status_filter === 'approved') {
    $where .= " AND u.status IN ('active', 'approved')";
} elseif ($status_filter === 'rejected') {
    $where .= " AND u.status = 'rejected'";
}

if ($search !== '') {
    $search_escaped = $conn->real_escape_string($search);
    $where .= " AND (u.fullname LIKE '%$search_escaped%' OR u.email LIKE '%$search_escaped%' OR u.phone_number LIKE '%$search_escaped%' OR u.address LIKE '%$search_escaped%' OR u.chapel_district LIKE '%$search_escaped%')";
}

// Live Status Counts for Filter Options
$count_base = "WHERE u.role = 'user' AND u.status != 'archived' AND u.status != 'deleted'";
$count_sql = "SELECT 
    COUNT(*) as total_all,
    SUM(CASE WHEN u.status IN ('pending', 'pending_verification') THEN 1 ELSE 0 END) as total_pending,
    SUM(CASE WHEN u.status IN ('active', 'approved') THEN 1 ELSE 0 END) as total_approved,
    SUM(CASE WHEN u.status = 'rejected' THEN 1 ELSE 0 END) as total_rejected
FROM users u $count_base";
$counts_res = $conn->query($count_sql);
$counts_row = $counts_res ? $counts_res->fetch_assoc() : [];
$counts = [
    'all' => intval($counts_row['total_all'] ?? 0),
    'pending' => intval($counts_row['total_pending'] ?? 0),
    'approved' => intval($counts_row['total_approved'] ?? 0),
    'rejected' => intval($counts_row['total_rejected'] ?? 0),
];


// ── Printable View Mode Handler (?print=1) ───────────────────
if (isset($_GET['print']) && $_GET['print'] === '1') {
    $print_users_sql = "SELECT u.*, verifier.fullname AS verified_by_name
        FROM users u
        LEFT JOIN users verifier ON u.verified_by = verifier.id
        $where
        ORDER BY u.fullname ASC";
    $print_res = $conn->query($print_users_sql);
    $print_users = [];
    while ($print_res && $row = $print_res->fetch_assoc()) {
        $print_users[] = $row;
    }

    $logoPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'parish-logo.jpg';
    if (!is_file($logoPath)) {
        $logoPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'san-lorenzo-logo.png';
    }
    $logoBase64 = '';
    if (is_file($logoPath)) {
        $mime = (pathinfo($logoPath, PATHINFO_EXTENSION) === 'png') ? 'image/png' : 'image/jpeg';
        $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
    }

    $generatedBy = !empty($_SESSION['fullname']) ? (string)$_SESSION['fullname'] : 'Parish Administrator';
    $generatedAt = date('F d, Y \a\t h:i A');

    $filterLabel = ucfirst($status_filter);
    if ($status_filter === 'all') {
        $filterLabel = 'All Statuses';
    }

    renderPrintableParishionerRegistry($print_users, $filterLabel, $search, $logoBase64, $generatedBy, $generatedAt);
    exit;
}

$total_result = $conn->query("SELECT COUNT(*) as count FROM users u $where");
$total = $total_result ? intval($total_result->fetch_assoc()['count'] ?? 0) : 0;
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

/**
 * Helper to build pagination and filter URLs maintaining active query params
 */
function buildParishionerFilterUrl(array $params = []): string {
    $current = [];
    if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
        $current['status'] = $_GET['status'];
    }
    if (!empty($_GET['search'])) {
        $current['search'] = $_GET['search'];
    }
    $merged = array_merge($current, $params);
    if (isset($merged['status']) && $merged['status'] === 'all') {
        unset($merged['status']);
    }
    if (isset($merged['page']) && $merged['page'] <= 1) {
        unset($merged['page']);
    }
    $qs = http_build_query($merged);
    return 'manage-users.php' . ($qs !== '' ? '?' . $qs : '');
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

$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Manage Parishioners' => null
];

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

/* ── Filter Dropdown, Action Buttons & Quick Filter Pills ─── */
.parish-filter-select-wrap {
    min-width: 170px;
}

.parish-filter-select {
    width: 100%;
    height: 42px;
    border-radius: 10px;
    border: 1px solid var(--border-warm-strong);
    background: #FFFFFF;
    padding: 0 14px;
    font-size: 0.86rem;
    font-weight: 600;
    color: var(--text-charcoal-dark);
    outline: none;
    cursor: pointer;
    transition: all 0.15s ease;
}

.parish-filter-select:focus {
    border-color: var(--brand-gold-warm);
    box-shadow: 0 0 0 3px rgba(197, 155, 39, 0.15);
}

.parish-filter-actions {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.parish-print-btn {
    height: 42px;
    border-radius: 10px;
    border: 1.5px solid var(--brand-green-deep);
    background: var(--brand-green-deep);
    color: #FFFFFF;
    font-size: 0.86rem;
    font-weight: 700;
    padding: 0 20px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.15s ease;
}

.parish-print-btn:hover {
    background: var(--brand-green-forest);
    border-color: var(--brand-green-forest);
    color: #FFFFFF;
    transform: translateY(-1px);
    box-shadow: 0 3px 10px rgba(20, 61, 40, 0.25);
}

.parish-reset-btn {
    height: 42px;
    border-radius: 10px;
    border: 1px solid var(--border-warm-strong);
    background: #FFFFFF;
    color: var(--text-charcoal-muted);
    font-size: 0.86rem;
    font-weight: 600;
    padding: 0 16px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    transition: all 0.15s ease;
}

.parish-reset-btn:hover {
    background: #F8FAFC;
    color: var(--text-charcoal-dark);
    border-color: #94A3B8;
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
    .parish-filter-row {
        flex-direction: column;
        align-items: stretch;
    }
    .parish-filter-select-wrap,
    .parish-search-input-wrap {
        width: 100%;
    }
    .parish-filter-actions {
        display: flex;
        gap: 8px;
        width: 100%;
    }
    .parish-search-submit-btn,
    .parish-print-btn,
    .parish-reset-btn {
        flex: 1;
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
    <!-- Standardized Section Header -->
    <?php
    $page_header_title = 'Manage Parishioners';
    $page_header_subtitle = 'Review parishioner accounts, verification status, and personal registry entries.';
    $page_header_icon = 'fa-people-roof';
    $show_back_button = true;
    $back_button_url = BASE_URL . 'admin/dashboard.php';
    include '../includes/page_header.php';
    ?>

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
        <form method="GET" action="manage-users.php" class="parish-filter-row" id="parishionerFilterForm">
            <div class="parish-filter-select-wrap">
                <select id="statusFilter" name="status" class="parish-filter-select" onchange="this.form.submit()" title="Filter by verification status">
                    <option value="all" <?php echo ($status_filter === 'all') ? 'selected' : ''; ?>>All Statuses (<?php echo $counts['all']; ?>)</option>
                    <option value="pending" <?php echo ($status_filter === 'pending') ? 'selected' : ''; ?>>Pending (<?php echo $counts['pending']; ?>)</option>
                    <option value="approved" <?php echo ($status_filter === 'approved') ? 'selected' : ''; ?>>Approved (<?php echo $counts['approved']; ?>)</option>
                    <option value="rejected" <?php echo ($status_filter === 'rejected') ? 'selected' : ''; ?>>Rejected (<?php echo $counts['rejected']; ?>)</option>
                </select>
            </div>
            <div class="parish-search-input-wrap">
                <i class="fas fa-magnifying-glass search-icon" aria-hidden="true"></i>
                <input id="tableSearchInput" type="text" class="parish-table-search-field" name="search" placeholder="Search by name, email, phone, or address..." value="<?php echo sanitize($search); ?>" autocomplete="off">
            </div>
            <div class="parish-filter-actions">
                <button class="parish-search-submit-btn" type="submit" title="Search parishioners">
                    <i class="fas fa-search"></i> Search
                </button>
                <button class="parish-print-btn" type="button" onclick="printParishioners()" title="Print current list of parishioners">
                    <i class="fas fa-print"></i> Print
                </button>
                <?php if ($search !== '' || $status_filter !== 'all'): ?>
                    <a href="manage-users.php" class="parish-reset-btn" title="Reset all filters">
                        <i class="fas fa-rotate-left"></i> Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>

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
                                        <?php echo renderUserAvatar($user, 32); ?>
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
                                        <button type="button" class="parish-btn-action" data-bs-toggle="modal" data-bs-target="#<?php echo e($view_modal_id); ?>" data-stable-modal-open="#<?php echo e($view_modal_id); ?>" title="View Parishioner Details">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if ($user['status'] !== 'archived'): ?>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Archive this parishioner? The account will be automatically removed from this list and moved to the Archives section.');">
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

            <?php if ($pagination['total_pages'] > 1): ?>
                <div class="parish-pagination-wrap d-flex justify-content-between align-items-center mt-3 pt-2">
                    <div class="text-muted small">
                        Showing <?php echo $pagination['offset'] + 1; ?> to <?php echo min($total, $pagination['offset'] + $pagination['limit']); ?> of <?php echo $total; ?> parishioners
                    </div>
                    <nav aria-label="Parishioner table pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo buildParishionerFilterUrl(['page' => $page - 1]); ?>" aria-label="Previous">&laquo; Prev</a>
                                </li>
                            <?php endif; ?>
                            <?php for ($p = 1; $p <= $pagination['total_pages']; $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildParishionerFilterUrl(['page' => $p]); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($page < $pagination['total_pages']): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo buildParishionerFilterUrl(['page' => $page + 1]); ?>" aria-label="Next">Next &raquo;</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>

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
                                <div class="d-flex align-items-center gap-3">
                                    <?php echo renderUserAvatar($user, 44); ?>
                                    <div>
                                        <h5 class="modal-title fw-bold text-dark mb-0">
                                            <?php echo e($user['fullname']); ?>
                                        </h5>
                                        <small class="text-muted"><?php echo e($user['email'] ?: 'No email on record'); ?></small>
                                    </div>
                                </div>
                                <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" data-stable-modal-close aria-label="Close parishioner details"></button>
                            </div>
                            <div class="modal-body p-4">
                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <h6 class="fw-bold border-bottom pb-2 mb-3 text-success">Personal Information</h6>
                                        <div class="row g-3">
                                            <div class="col-sm-6"><div class="text-muted small">Full Name</div><div class="fw-semibold"><?php echo e(userDetailValue($user['fullname'])); ?></div></div>
                                            <div class="col-sm-6"><div class="text-muted small">Birthdate</div><div class="fw-semibold"><?php echo e(userDetailDate($user['birthdate'] ?? '')); ?></div></div>
                                            <div class="col-12"><div class="text-muted small">Birthplace</div><div class="fw-semibold"><?php echo e(userDetailValue($user['birth_place'] ?? '')); ?></div></div>
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

                                    <div class="col-12">
                                        <h6 class="fw-bold border-bottom pb-2 mb-3 text-success">Account &amp; Verification Details</h6>
                                        <div class="row g-3">
                                            <div class="col-sm-6 col-md-3">
                                                <div class="text-muted small">Date Registered / Joined</div>
                                                <div class="fw-semibold"><?php echo e(userDetailDateTime($user['created_at'] ?? '')); ?></div>
                                            </div>
                                            <div class="col-sm-6 col-md-3">
                                                <div class="text-muted small">Verification Status</div>
                                                <span class="badge bg-<?php echo e(getUserStatusBadgeClass($user['status'])); ?>"><?php echo e(getUserStatusLabel($user['status'])); ?></span>
                                            </div>
                                            <div class="col-sm-6 col-md-3">
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
                                            <div class="col-sm-6 col-md-3">
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
                                            <div class="col-sm-6 col-md-3">
                                                <div class="text-muted small">Face Status</div>
                                                <div class="fw-semibold"><?php echo e(userDetailValue($user['face_verification_status'] ?? '')); ?></div>
                                            </div>
                                            <?php if (!empty($user['rejection_reason'])): ?>
                                                <div class="col-sm-6 col-md-3">
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
                                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal" data-stable-modal-close>Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

        <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-users-slash fa-3x mb-3 text-secondary opacity-50"></i>
                <h5>No parishioners found</h5>
                <p class="small">Try adjusting your search criteria or switch status filters.</p>
                <?php if ($search !== '' || $status_filter !== 'all'): ?>
                    <a href="manage-users.php" class="btn btn-sm btn-outline-secondary mt-2">
                        <i class="fas fa-rotate-left me-1"></i> Reset Filters
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
/**
 * Print Parishioners List
 * Opens the print preview page respecting active status filter and search query.
 */
function printParishioners() {
    const statusSelect = document.getElementById('statusFilter');
    const searchInput = document.getElementById('tableSearchInput');
    const status = statusSelect ? statusSelect.value : 'all';
    const search = searchInput ? searchInput.value.trim() : '';

    const params = new URLSearchParams();
    params.set('print', '1');
    if (status && status !== 'all') {
        params.set('status', status);
    }
    if (search) {
        params.set('search', search);
    }

    const printUrl = 'manage-users.php?' + params.toString();
    const printWindow = window.open(printUrl, '_blank');
    if (printWindow) {
        printWindow.focus();
    } else {
        window.location.href = printUrl;
    }
}

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
            const topSearch = document.getElementById('tableSearchInput');
            if (topSearch) {
                topSearch.focus();
                topSearch.select();
            }
        }
    });
});
</script>

<?php
/**
 * Standalone Printable View for Parishioner Directory
 */
function renderPrintableParishionerRegistry(array $users, string $filterLabel, string $searchQuery, string $logoBase64, string $generatedBy, string $generatedAt): void {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parishioner Registry - San Lorenzo Ruiz Mission Station</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1e293b;
            background-color: #f8fafc;
            line-height: 1.4;
            font-size: 13px;
        }
        .print-toolbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: #1e293b;
            color: #ffffff;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .toolbar-info {
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-toolbar {
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 700;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }
        .btn-print-action {
            background: #15803d;
            color: #ffffff;
        }
        .btn-print-action:hover {
            background: #166534;
        }
        .btn-close-action {
            background: #475569;
            color: #ffffff;
        }
        .btn-close-action:hover {
            background: #334155;
        }
        .document-wrapper {
            max-width: 960px;
            margin: 24px auto;
            background: #ffffff;
            padding: 40px 48px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        /* Letterhead */
        .letterhead-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .logo-col {
            width: 85px;
            vertical-align: middle;
            text-align: left;
        }
        .logo-img {
            width: 72px;
            height: 72px;
            object-fit: contain;
        }
        .logo-placeholder {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #64748b;
        }
        .text-col {
            vertical-align: middle;
            text-align: center;
        }
        .diocese-title {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 1.5px;
            color: #475569;
            text-transform: uppercase;
        }
        .parish-title {
            font-family: Georgia, "Times New Roman", serif;
            font-size: 21px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: 0.5px;
            margin: 3px 0;
            text-transform: uppercase;
        }
        .location-title {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            letter-spacing: 0.5px;
        }
        .spacer-col {
            width: 85px;
        }
        /* Cross divider */
        .divider-table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0 20px 0;
        }
        .divider-table td {
            padding: 0;
        }
        .divider-line {
            border-top: 1.5px solid #d97706;
            width: 48%;
        }
        .divider-cross {
            width: 4%;
            text-align: center;
            font-size: 15px;
            color: #d97706;
            line-height: 1;
        }
        /* Report Title & Meta Bar */
        .doc-header-block {
            text-align: center;
            margin-bottom: 24px;
        }
        .doc-heading {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: #1e293b;
            margin-bottom: 6px;
        }
        .doc-meta-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            flex-wrap: wrap;
            font-size: 12px;
            color: #475569;
            margin-top: 8px;
            padding: 8px 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }
        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
        }
        .meta-pill-badge {
            background: #0f3321;
            color: #ffffff;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        /* Table */
        .registry-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
            font-size: 12px;
        }
        .registry-table th {
            background-color: #f1f5f9;
            color: #334155;
            font-weight: 800;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            text-align: left;
        }
        .registry-table td {
            padding: 9px 12px;
            border: 1px solid #e2e8f0;
            vertical-align: middle;
            color: #1e293b;
        }
        .registry-table tbody tr:nth-child(even) {
            background-color: #fafbfc;
        }
        .status-badge-print {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .status-approved {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
        }
        .status-pending {
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fcd34d;
        }
        .status-rejected {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fca5a5;
        }
        .status-other {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        /* Certification block */
        .certification-block {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            padding-top: 20px;
            page-break-inside: avoid;
        }
        .cert-signature-box {
            width: 44%;
            text-align: center;
        }
        .cert-line {
            border-bottom: 1px solid #0f172a;
            margin-bottom: 8px;
            height: 40px;
        }
        .cert-name {
            font-weight: 700;
            font-size: 13px;
            color: #0f172a;
        }
        .cert-title {
            font-size: 11px;
            color: #64748b;
        }
        .footer-note {
            margin-top: 30px;
            text-align: center;
            font-size: 10.5px;
            color: #94a3b8;
            border-top: 1px solid #f1f5f9;
            padding-top: 12px;
        }
        /* Print rules */
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: #ffffff !important;
                color: #000000 !important;
                font-size: 11px !important;
            }
            .document-wrapper {
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
            }
            @page {
                size: portrait;
                margin: 12mm 10mm 15mm 10mm;
            }
            thead {
                display: table-header-group;
            }
            tr {
                page-break-inside: avoid;
            }
            .registry-table th {
                background-color: #f1f5f9 !important;
                color: #000000 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .status-badge-print {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="print-toolbar no-print">
        <div class="toolbar-info">
            <i class="fas fa-church"></i>
            <span>Parishioner Directory &bull; <?php echo count($users); ?> records</span>
        </div>
        <div class="toolbar-actions">
            <button type="button" class="btn-toolbar btn-print-action" onclick="window.print()">
                <i class="fas fa-print"></i> Print Document
            </button>
            <button type="button" class="btn-toolbar btn-close-action" onclick="window.close()">
                <i class="fas fa-xmark"></i> Close
            </button>
        </div>
    </div>

    <div class="document-wrapper">
        <table class="letterhead-table">
            <tr>
                <td class="logo-col">
                    <?php if (!empty($logoBase64)): ?>
                        <img src="<?php echo $logoBase64; ?>" alt="Parish Logo" class="logo-img">
                    <?php else: ?>
                        <div class="logo-placeholder">SLR</div>
                    <?php endif; ?>
                </td>
                <td class="text-col">
                    <div class="diocese-title">Archdiocese of Cotabato</div>
                    <div class="parish-title">San Lorenzo Ruiz Mission Station</div>
                    <div class="location-title">Aleosan, North Cotabato</div>
                </td>
                <td class="spacer-col"></td>
            </tr>
        </table>

        <table class="divider-table">
            <tr>
                <td class="divider-line"></td>
                <td class="divider-cross">&#8224;</td>
                <td class="divider-line"></td>
            </tr>
        </table>

        <div class="doc-header-block">
            <h1 class="doc-heading">Official Parishioner Registry</h1>
            <div class="doc-meta-bar">
                <span class="meta-pill">
                    Status: <span class="meta-pill-badge"><?php echo htmlspecialchars($filterLabel); ?></span>
                </span>
                <?php if ($searchQuery !== ''): ?>
                    <span class="meta-pill">
                        Search: <strong>"<?php echo htmlspecialchars($searchQuery); ?>"</strong>
                    </span>
                <?php endif; ?>
                <span class="meta-pill">
                    Total Records: <strong><?php echo count($users); ?></strong>
                </span>
                <span class="meta-pill">
                    Generated: <strong><?php echo $generatedAt; ?></strong>
                </span>
            </div>
        </div>

        <table class="registry-table">
            <thead>
                <tr>
                    <th style="width: 35px; text-align: center;">#</th>
                    <th>Full Name</th>
                    <th>Email Address</th>
                    <th>Contact No.</th>
                    <th>Chapel / Address</th>
                    <th>Status</th>
                    <th>Date Joined</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($users)): ?>
                    <?php $idx = 1; foreach ($users as $user): ?>
                        <?php
                        $st = strtolower($user['status'] ?? 'pending');
                        $is_approved = in_array($st, ['active', 'approved'], true);
                        $is_pending = in_array($st, ['pending', 'pending_verification'], true);
                        $is_rejected = ($st === 'rejected');
                        
                        $badge_class = 'status-other';
                        $status_text = ucfirst(str_replace('_', ' ', $st));
                        if ($is_approved) {
                            $badge_class = 'status-approved';
                            $status_text = 'Approved';
                        } elseif ($is_pending) {
                            $badge_class = 'status-pending';
                            $status_text = 'Pending';
                        } elseif ($is_rejected) {
                            $badge_class = 'status-rejected';
                            $status_text = 'Rejected';
                        }
                        
                        $chapel = trim((string)($user['chapel_district'] ?? ''));
                        $address = trim((string)($user['address'] ?? ''));
                        $formatted_address = '—';
                        if ($chapel !== '' && $address !== '') {
                            $formatted_address = htmlspecialchars($chapel, ENT_QUOTES, 'UTF-8') . ' &bull; ' . htmlspecialchars($address, ENT_QUOTES, 'UTF-8');
                        } elseif ($chapel !== '') {
                            $formatted_address = htmlspecialchars($chapel, ENT_QUOTES, 'UTF-8');
                        } elseif ($address !== '') {
                            $formatted_address = htmlspecialchars($address, ENT_QUOTES, 'UTF-8');
                        }
                        ?>
                        <tr>
                            <td style="text-align: center; color: #64748b;"><?php echo $idx++; ?></td>
                            <td><strong><?php echo htmlspecialchars($user['fullname'] ?? ''); ?></strong></td>
                            <td><?php echo !empty($user['email']) ? htmlspecialchars($user['email']) : '—'; ?></td>
                            <td><?php echo !empty($user['phone_number']) ? htmlspecialchars($user['phone_number']) : '—'; ?></td>
                            <td><?php echo $formatted_address; ?></td>
                            <td>
                                <span class="status-badge-print <?php echo $badge_class; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </td>
                            <td><?php echo !empty($user['created_at']) ? date('M d, Y', strtotime($user['created_at'])) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 24px; color: #64748b;">
                            No parishioner records found matching the current criteria.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="certification-block">
            <div class="cert-signature-box">
                <div class="cert-line"></div>
                <div class="cert-name"><?php echo htmlspecialchars($generatedBy); ?></div>
                <div class="cert-title">Prepared by / Parish Records Administrator</div>
            </div>
            <div class="cert-signature-box">
                <div class="cert-line"></div>
                <div class="cert-name">Rev. Fr. Parish Priest / Administrator</div>
                <div class="cert-title">Attested &amp; Verified</div>
            </div>
        </div>

        <div class="footer-note">
            San Lorenzo Ruiz Mission Station &bull; Archdiocese of Cotabato &bull; Official Registry Document &bull; Generated on <?php echo $generatedAt; ?>
        </div>
    </div>

    <script>
        window.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                window.print();
            }, 500);
        });
    </script>
</body>
</html>
<?php
}
?>

<script src="../assets/js/main.js"></script>
<?php include '../templates/footer.php'; ?>
