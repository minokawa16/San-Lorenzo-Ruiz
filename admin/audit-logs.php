<?php
/**
 * Admin Audit Logs
 * Tracks admin and system actions for accountability.
 */

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('audit.view');

$page_title = 'Audit Logs';
$q = trim($_GET['q'] ?? '');
$q_safe = $conn->real_escape_string($q);
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
}
if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
}

$from_sql = $from !== '' ? $conn->real_escape_string($from . ' 00:00:00') : '';
$to_sql = $to !== '' ? $conn->real_escape_string($to . ' 23:59:59') : '';

// Audit Fetch Rows Function - Documents this helper's role in the parish management workflow.
function auditFetchRows($conn, $sql) {
    $rows = [];
    $result = $conn->query($sql);
    while ($result && $row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

// Audit Fetch Count Function - Documents this helper's role in the parish management workflow.
function auditFetchCount($conn, $sql) {
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return intval($row['count'] ?? 0);
}

// Audit Preview Function - Documents this helper's role in the parish management workflow.
function auditPreview($value) {
    if ($value === null || $value === '' || strtoupper((string) $value) === 'NULL') {
        return 'No details';
    }

    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $parts = [];
        foreach ($decoded as $key => $item) {
            if (is_array($item)) {
                $item = json_encode($item);
            }
            $parts[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $item;
        }
        return implode(', ', array_slice($parts, 0, 4));
    }

    return mb_strimwidth((string) $value, 0, 120, '...');
}

$source_queries = [];
if (tableExists($conn, 'audit_logs')) {
    $source_queries[] = "SELECT log_id, user_id, action_type AS action_name, table_name, record_id, old_value, new_value, ip_address, user_agent, `timestamp` AS activity_date, 'audit_logs' AS source_table FROM audit_logs";
}
if (tableExists($conn, 'audit_log')) {
    $source_queries[] = "SELECT log_id, user_id, action AS action_name, table_name, record_id, old_value, new_value, ip_address, '' AS user_agent, created_at AS activity_date, 'audit_log' AS source_table FROM audit_log";
}

$audit_rows = [];
$action_summary = [];
$total_logs = 0;
$unique_admins = 0;
$latest_action = null;

if (!empty($source_queries)) {
    $audit_union = implode(' UNION ALL ', $source_queries);
    $conditions = [];
    if ($q !== '') {
        $like = "'%" . $q_safe . "%'";
        $conditions[] = "(l.action_name LIKE $like
            OR l.table_name LIKE $like
            OR l.record_id LIKE $like
            OR l.ip_address LIKE $like
            OR u.fullname LIKE $like
            OR u.email LIKE $like)";
    }
    if ($from_sql !== '') {
        $conditions[] = "l.activity_date >= '$from_sql'";
    }
    if ($to_sql !== '') {
        $conditions[] = "l.activity_date <= '$to_sql'";
    }
    $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $audit_sql = "SELECT l.*, COALESCE(u.fullname, 'System') AS admin_name, COALESCE(u.email, '') AS admin_email
                  FROM ($audit_union) l
                  LEFT JOIN users u ON l.user_id = u.id
                  $where
                  ORDER BY l.activity_date DESC
                  LIMIT 100";
    $audit_rows = auditFetchRows($conn, $audit_sql);

    $count_sql = "SELECT COUNT(*) AS count
                  FROM ($audit_union) l
                  LEFT JOIN users u ON l.user_id = u.id
                  $where";
    $total_logs = auditFetchCount($conn, $count_sql);

    $unique_sql = "SELECT COUNT(DISTINCT l.user_id) AS count
                   FROM ($audit_union) l
                   LEFT JOIN users u ON l.user_id = u.id
                   $where";
    $unique_admins = auditFetchCount($conn, $unique_sql);

    $summary_sql = "SELECT l.action_name AS label, COUNT(*) AS count
                    FROM ($audit_union) l
                    LEFT JOIN users u ON l.user_id = u.id
                    $where
                    GROUP BY l.action_name
                    ORDER BY count DESC
                    LIMIT 6";
    $action_summary = auditFetchRows($conn, $summary_sql);
    $latest_action = $audit_rows[0]['activity_date'] ?? null;
}

$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Audit Logs' => null
];
?>
<?php include '../templates/header.php'; ?>

<style>
    .audit-page {
        max-width: 1440px;
        margin: 0 auto;
    }

    .audit-hero {
        border-radius: 8px;
        padding: 24px;
        color: #fff;
        background:
            radial-gradient(circle at 12% 15%, rgba(255,255,255,0.42), transparent 18%),
            linear-gradient(135deg, #172033, #425f92 58%, #987a2d);
        box-shadow: 0 14px 36px rgba(15, 23, 42, 0.16);
    }

    .audit-hero h1 {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 8px;
        font-weight: 800;
    }

    .audit-hero p {
        max-width: 760px;
        margin: 0;
        color: rgba(255,255,255,0.8);
    }

    .audit-stats {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        margin: 18px 0;
    }

    .audit-card,
    .audit-table-card,
    .audit-filter {
        border: 1px solid rgba(23, 32, 51, 0.1);
        border-radius: 8px;
        background: linear-gradient(150deg, rgba(255,255,255,0.9), rgba(255,250,240,0.76));
        box-shadow: 0 14px 40px rgba(30, 41, 59, 0.08);
    }

    .audit-card {
        padding: 16px;
    }

    .audit-card span {
        display: block;
        color: #64748b;
        font-size: 0.78rem;
        font-weight: 800;
        text-transform: uppercase;
    }

    .audit-card strong {
        display: block;
        margin-top: 8px;
        color: #172033;
        font-size: 1.6rem;
    }

    .audit-filter {
        padding: 14px;
        margin-bottom: 14px;
    }

    .audit-table-card {
        overflow: hidden;
    }

    .audit-table-card .table {
        margin-bottom: 0;
    }

    .audit-table-card thead th {
        background: #f8fafc;
        color: #475569;
        font-size: 0.76rem;
        text-transform: uppercase;
    }

    .audit-action-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 5px 10px;
        background: #fff3c4;
        color: #4a3410;
        font-weight: 800;
        font-size: 0.78rem;
    }

    .audit-detail {
        max-width: 360px;
        color: #475569;
        font-size: 0.88rem;
    }

    .audit-summary-list {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 14px;
    }

    .audit-summary-pill {
        border: 1px solid rgba(255,255,255,0.22);
        border-radius: 999px;
        padding: 7px 12px;
        color: #fff;
        background: rgba(255,255,255,0.12);
        font-size: 0.85rem;
    }

    @media (max-width: 768px) {
        .audit-stats {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="container-fluid mt-4">
    <div class="audit-page">
        <?php include '../includes/breadcrumb.php'; ?>
        <?php include '../includes/back_button.php'; ?>

        <section class="audit-hero mb-3">
            <h1><i class="fas fa-clipboard-list"></i> Audit Logs</h1>
            <p>Action history, tracking, and accountability for admin/system changes. Use this page to see who changed something, what action was done, when it happened, and which record was affected.</p>
            <?php if (!empty($action_summary)): ?>
                <div class="audit-summary-list">
                    <?php foreach ($action_summary as $summary): ?>
                        <span class="audit-summary-pill"><?php echo e(ucwords(strtolower(str_replace('_', ' ', $summary['label'])))); ?>: <?php echo intval($summary['count']); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="audit-stats">
            <div class="audit-card">
                <span>Total Tracked Actions</span>
                <strong><?php echo number_format($total_logs); ?></strong>
            </div>
            <div class="audit-card">
                <span>Admins/System Users</span>
                <strong><?php echo number_format($unique_admins); ?></strong>
            </div>
            <div class="audit-card">
                <span>Latest Action</span>
                <strong><?php echo $latest_action ? e(formatDateTime($latest_action)) : 'None'; ?></strong>
            </div>
        </div>

        <form class="audit-filter" method="GET">
            <div class="row g-2 align-items-end">
                <div class="col-lg-5">
                    <label for="auditSearch" class="form-label fw-bold">Search Audit Logs</label>
                    <input type="search" id="auditSearch" name="q" class="form-control" placeholder="Search action, admin, email, table, record ID, or IP address" value="<?php echo e($q); ?>">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="auditFrom" class="form-label fw-bold">From Date</label>
                    <input type="date" id="auditFrom" name="from" class="form-control" value="<?php echo e($from); ?>">
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label for="auditTo" class="form-label fw-bold">To Date</label>
                    <input type="date" id="auditTo" name="to" class="form-control" value="<?php echo e($to); ?>">
                </div>
                <div class="col-lg-3 d-grid gap-2 d-lg-flex">
                    <button class="btn btn-primary flex-fill" type="submit"><i class="fas fa-filter"></i> Filter</button>
                    <?php if ($q !== '' || $from !== '' || $to !== ''): ?>
                        <a class="btn btn-outline-secondary" href="audit-logs.php">Clear All</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <section class="audit-table-card">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Admin/System</th>
                            <th>Action</th>
                            <th>Table</th>
                            <th>Record</th>
                            <th>Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audit_rows as $row): ?>
                            <tr>
                                <td><?php echo e(formatDateTime($row['activity_date'])); ?></td>
                                <td>
                                    <strong><?php echo e($row['admin_name']); ?></strong>
                                    <?php if (!empty($row['admin_email'])): ?>
                                        <div class="text-muted small"><?php echo e($row['admin_email']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="audit-action-badge"><i class="fas fa-shield-halved"></i> <?php echo e(ucwords(strtolower(str_replace('_', ' ', $row['action_name'])))); ?></span></td>
                                <td><?php echo e($row['table_name'] ?: 'System'); ?></td>
                                <td><?php echo e($row['record_id'] ?: 'N/A'); ?></td>
                                <td class="audit-detail"><?php echo e(auditPreview($row['new_value'] ?: $row['old_value'])); ?></td>
                                <td><?php echo e($row['ip_address'] ?: 'N/A'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($audit_rows)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No audit logs found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
