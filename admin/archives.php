<?php
/**
 * Archives Module
 * Shows archived requests, announcements, sacramental records, and parishioners.
 */

require_once '../includes/session.php';
require_once '../database/config.php';
require_once '../includes/helpers.php';
require_once '../services/SacramentalRecordService.php';
require_once '../includes/account-management.php';

requireAdmin();
requirePermission('archives.manage');

$page_title = 'Archives';
$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'requests';
if ($active_tab === 'users') {
    $active_tab = 'parishioners';
}
$allowed_tabs = ['requests', 'announcements', 'records', 'parishioners'];
if (!in_array($active_tab, $allowed_tabs, true)) {
    $active_tab = 'requests';
}
$archive_search = trim((string) ($_GET['q'] ?? ''));
$archive_filter = trim((string) ($_GET['filter'] ?? ''));
$date_from = trim((string) ($_GET['date_from'] ?? ''));
$date_to = trim((string) ($_GET['date_to'] ?? ''));

// Ensure Archive Column Function
if (!function_exists('ensureArchiveColumn')) {
function ensureArchiveColumn($conn, $table) {
    return columnExists($conn, $table, 'deleted_at');
}
}

// Archive Table Exists Function
if (!function_exists('archiveTableExists')) {
function archiveTableExists($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    return schemaTableExists($conn, $table);
}
}

ensureArchiveColumn($conn, 'requests');
ensureArchiveColumn($conn, 'announcements');

$record_tables = [
    'baptism' => [
        'table' => 'baptism_records',
        'id' => 'baptism_id',
        'name' => 'fullname',
        'date' => 'baptism_date',
        'detail' => "CONCAT('Priest: ', COALESCE(priest, 'N/A'))",
        'label' => 'Baptism'
    ],
    'communion' => [
        'table' => 'first_communion_records',
        'id' => 'communion_id',
        'name' => 'fullname',
        'date' => 'communion_date',
        'detail' => "CONCAT('Priest: ', COALESCE(priest, 'N/A'))",
        'label' => 'First Communion'
    ],
    'confirmation' => [
        'table' => 'confirmation_records',
        'id' => 'confirmation_id',
        'name' => 'fullname',
        'date' => 'confirmation_date',
        'detail' => "CONCAT('Minister: ', COALESCE(bishop_priest, 'N/A'))",
        'label' => 'Confirmation'
    ],
    'marriage' => [
        'table' => 'marriage_records',
        'id' => 'marriage_id',
        'name' => "CONCAT(husband_name, ' & ', wife_name)",
        'date' => 'wedding_date',
        'detail' => "CONCAT('Priest: ', COALESCE(officiating_priest, 'N/A'))",
        'label' => 'Marriage'
    ],
    'funeral' => [
        'table' => 'funeral_records',
        'id' => 'funeral_id',
        'name' => 'deceased_name',
        'date' => 'date_of_burial',
        'detail' => "CONCAT('Burial place: ', COALESCE(place_of_burial, 'N/A'))",
        'label' => 'Funeral'
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'restore_request') {
        $request_id = intval($_POST['request_id'] ?? 0);
        if ($conn->query("UPDATE requests SET deleted_at = NULL WHERE request_id = $request_id")) {
            createAuditLog($conn, $_SESSION['user_id'], 'RESTORE_REQUEST', 'requests', $request_id);
            $success = 'Request restored successfully!';
            $active_tab = 'requests';
        } else {
            $error = 'Error restoring request: ' . $conn->error;
        }
    } elseif ($action === 'restore_announcement') {
        $announcement_id = intval($_POST['announcement_id'] ?? 0);
        if ($conn->query("UPDATE announcements SET deleted_at = NULL, status = 'inactive', lifecycle_status='draft', archived_at=NULL, archived_by=NULL, archive_reason=NULL WHERE announcement_id = $announcement_id")) {
            createAuditLog($conn, $_SESSION['user_id'], 'RESTORE_ANNOUNCEMENT', 'announcements', $announcement_id);
            $success = 'Announcement restored successfully!';
            $active_tab = 'announcements';
        } else {
            $error = 'Error restoring announcement: ' . $conn->error;
        }
    } elseif ($action === 'restore_record') {
        $record_type = $_POST['record_type'] ?? '';
        $record_id = intval($_POST['record_id'] ?? 0);

        if (isset($record_tables[$record_type])) {
            $meta = $record_tables[$record_type];
            try {
                (new SacramentalRecordService($conn))->restore($record_type, $record_id, (int)$_SESSION['user_id']);
                $success = $meta['label'] . ' record restored successfully!';
                $active_tab = 'records';
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'restore_parishioner' || $action === 'restore_user') {
        $user_id = intval($_POST['user_id'] ?? 0);
        if ($user_id > 0 && transitionAccountStatus($conn, $user_id, 'active', 'restored', null, (int) $_SESSION['user_id'])) {
            createAuditLog($conn, $_SESSION['user_id'], 'RESTORE_PARISHIONER', 'users', $user_id);
            $success = 'Parishioner account restored successfully to Active!';
            $active_tab = 'parishioners';
        } else {
            $error = 'Error restoring parishioner account: ' . ($conn->error ?: 'Operation failed');
        }
    }
}

$requests = [];
$req_has_reason = columnExists($conn, 'requests', 'archive_reason');
$req_reason_sel = $req_has_reason ? "r.archive_reason" : "NULL AS archive_reason";
$request_sql = "SELECT r.request_id, r.reference_number, r.request_type, r.status, r.date_requested, r.deleted_at,
                       r.admin_response, {$req_reason_sel}, u.fullname, u.email
                FROM requests r
                LEFT JOIN users u ON r.user_id = u.id
                WHERE r.deleted_at IS NOT NULL
                ORDER BY r.deleted_at DESC";
$request_result = $conn->query($request_sql);
while ($request_result && $row = $request_result->fetch_assoc()) {
    $requests[] = $row;
}

$announcements = [];
$announcement_sql = "SELECT a.announcement_id, a.title, a.content, a.type, a.published_date,
                            COALESCE(a.archived_at, a.deleted_at) AS deleted_at,
                            a.archive_reason,
                            u.fullname AS published_by_name,
                            arch_u.fullname AS archived_by_name
                     FROM announcements a
                     LEFT JOIN users u ON a.published_by = u.id
                     LEFT JOIN users arch_u ON a.archived_by = arch_u.id
                     WHERE a.deleted_at IS NOT NULL OR a.lifecycle_status = 'archived'
                     ORDER BY COALESCE(a.archived_at, a.deleted_at) DESC";
$announcement_result = $conn->query($announcement_sql);
while ($announcement_result && $row = $announcement_result->fetch_assoc()) {
    $announcements[] = $row;
}

$records = [];
foreach ($record_tables as $type => $meta) {
    if (!archiveTableExists($conn, $meta['table'])) {
        continue;
    }

    $hasReasonCol = columnExists($conn, $meta['table'], 'archive_reason');
    $hasArchivedAtCol = columnExists($conn, $meta['table'], 'archived_at');
    $hasArchivedByCol = columnExists($conn, $meta['table'], 'archived_by');
    $hasRegistryCol = columnExists($conn, $meta['table'], 'registry_no');
    $hasBookCol = columnExists($conn, $meta['table'], 'book_no');
    $hasPageCol = columnExists($conn, $meta['table'], 'page_no');
    $hasEntryCol = columnExists($conn, $meta['table'], 'entry_no');

    $reasonSelect = $hasReasonCol ? "t.archive_reason" : "NULL AS archive_reason";
    $archivedAtSelect = $hasArchivedAtCol ? "COALESCE(t.archived_at, t.updated_at) AS archived_at" : "t.updated_at AS archived_at";
    $archivedBySelect = $hasArchivedByCol ? "t.archived_by" : "NULL AS archived_by";
    $registrySelect = $hasRegistryCol ? "t.registry_no" : "NULL AS registry_no";
    $bookSelect = $hasBookCol ? "t.book_no" : "NULL AS book_no";
    $pageSelect = $hasPageCol ? "t.page_no" : "NULL AS page_no";
    $entrySelect = $hasEntryCol ? "t.entry_no" : "NULL AS entry_no";

    $joinUser = $hasArchivedByCol ? "LEFT JOIN users u ON u.id = t.archived_by" : "";
    $userNameSelect = $hasArchivedByCol ? "u.fullname AS archived_by_name" : "NULL AS archived_by_name";

    $nameExpr = strpos($meta['name'], '(') !== false ? $meta['name'] : 't.' . $meta['name'];
    $dateExpr = strpos($meta['date'], '(') !== false ? $meta['date'] : 't.' . $meta['date'];

    $sql = "SELECT '{$type}' AS record_type,
                   '{$meta['label']}' AS record_label,
                   t.{$meta['id']} AS record_id,
                   {$nameExpr} AS record_name,
                   {$dateExpr} AS record_date,
                   {$meta['detail']} AS record_detail,
                   {$archivedAtSelect},
                   {$reasonSelect},
                   {$archivedBySelect},
                   {$userNameSelect},
                   {$registrySelect},
                   {$bookSelect},
                   {$pageSelect},
                   {$entrySelect}
            FROM {$meta['table']} t
            {$joinUser}
            WHERE t.status = 'archived'";
    $result = $conn->query($sql);
    while ($result && $row = $result->fetch_assoc()) {
        if (empty($row['archive_reason'])) {
            // Fallback: check audit_log for reason
            $log_stmt = $conn->prepare("SELECT new_values, new_value, description FROM audit_log WHERE table_name = ? AND (target_id = ? OR record_id = ?) AND action = 'ARCHIVE_SACRAMENTAL_RECORD' ORDER BY log_id DESC LIMIT 1");
            if ($log_stmt) {
                $rec_id = (int)$row['record_id'];
                $log_stmt->bind_param("sii", $meta['table'], $rec_id, $rec_id);
                $log_stmt->execute();
                $log_row = $log_stmt->get_result()->fetch_assoc();
                $log_stmt->close();
                if ($log_row) {
                    $parsed = json_decode($log_row['new_values'] ?? $log_row['new_value'] ?? '', true);
                    if (!empty($parsed['reason'])) {
                        $row['archive_reason'] = (string)$parsed['reason'];
                    }
                }
            }
        }
        $records[] = $row;
    }
}
usort($records, function ($a, $b) {
    return strtotime($b['archived_at'] ?? '1970-01-01') <=> strtotime($a['archived_at'] ?? '1970-01-01');
});

$parishioners = [];
$hasUserArchivedReason = columnExists($conn, 'users', 'archive_reason');
$userReasonCol = $hasUserArchivedReason ? "u.archive_reason," : "";
$userReasonCoalesce = $hasUserArchivedReason ? "COALESCE(u.archive_reason, ash.reason, u.rejection_reason)" : "COALESCE(ash.reason, u.rejection_reason)";
$parishioner_sql = "SELECT u.id, u.fullname, u.email, u.phone_number, u.address, u.chapel_district, u.status, u.role,
                           {$userReasonCol}
                           COALESCE(u.account_state_changed_at, u.updated_at, u.created_at) AS archived_at,
                           {$userReasonCoalesce} AS archive_reason,
                           act_u.fullname AS archived_by_name
                    FROM users u
                    LEFT JOIN (
                        SELECT ash1.user_id, ash1.reason, ash1.actor_user_id
                        FROM account_status_history ash1
                        INNER JOIN (
                            SELECT user_id, MAX(history_id) AS max_id
                            FROM account_status_history
                            WHERE new_status = 'archived' OR action = 'archived'
                            GROUP BY user_id
                        ) ash2 ON ash1.history_id = ash2.max_id
                    ) ash ON ash.user_id = u.id
                    LEFT JOIN users act_u ON act_u.id = ash.actor_user_id
                    WHERE u.status = 'archived'
                    ORDER BY archived_at DESC";
$parishioner_result = $conn->query($parishioner_sql);
while ($parishioner_result && $row = $parishioner_result->fetch_assoc()) {
    $parishioners[] = $row;
}

$archive_filter_options = [
    'requests' => array_values(array_unique(array_map(function ($request) {
        return (string) ($request['status'] ?? '');
    }, $requests))),
    'announcements' => array_values(array_unique(array_map(function ($announcement) {
        return (string) ($announcement['type'] ?? '');
    }, $announcements))),
    'records' => array_values(array_unique(array_map(function ($record) {
        return (string) ($record['record_type'] ?? '');
    }, $records))),
    'parishioners' => array_values(array_unique(array_map(function ($parishioner) {
        return (string) ($parishioner['chapel_district'] ?? '');
    }, $parishioners))),
];
foreach ($archive_filter_options as $key => $values) {
    $archive_filter_options[$key] = array_values(array_filter($values, function ($value) {
        return trim((string) $value) !== '';
    }));
    sort($archive_filter_options[$key]);
}

if (!function_exists('archiveDateMatches')) {
function archiveDateMatches($value, $date_from, $date_to) {
    $timestamp = strtotime((string) $value);
    if (!$timestamp) {
        return false;
    }
    if ($date_from !== '' && $timestamp < strtotime($date_from . ' 00:00:00')) {
        return false;
    }
    if ($date_to !== '' && $timestamp > strtotime($date_to . ' 23:59:59')) {
        return false;
    }
    return true;
}
}

if (!function_exists('archiveTextMatches')) {
function archiveTextMatches($haystack, $needle) {
    if ($needle === '') {
        return true;
    }
    return stripos(implode(' ', array_map('strval', $haystack)), $needle) !== false;
}
}

if ($active_tab === 'requests') {
    $requests = array_values(array_filter($requests, function ($request) use ($archive_search, $archive_filter, $date_from, $date_to) {
        if ($archive_filter !== '' && (string) ($request['status'] ?? '') !== $archive_filter) {
            return false;
        }
        if (($date_from !== '' || $date_to !== '') && !archiveDateMatches($request['deleted_at'] ?? '', $date_from, $date_to)) {
            return false;
        }
        return archiveTextMatches([
            $request['reference_number'] ?? '',
            $request['request_type'] ?? '',
            $request['status'] ?? '',
            $request['fullname'] ?? '',
            $request['email'] ?? '',
            $request['archive_reason'] ?? '',
            $request['admin_response'] ?? '',
        ], $archive_search);
    }));
} elseif ($active_tab === 'announcements') {
    $announcements = array_values(array_filter($announcements, function ($announcement) use ($archive_search, $archive_filter, $date_from, $date_to) {
        if ($archive_filter !== '' && (string) ($announcement['type'] ?? '') !== $archive_filter) {
            return false;
        }
        if (($date_from !== '' || $date_to !== '') && !archiveDateMatches($announcement['deleted_at'] ?? '', $date_from, $date_to)) {
            return false;
        }
        return archiveTextMatches([
            $announcement['title'] ?? '',
            $announcement['content'] ?? '',
            $announcement['type'] ?? '',
            $announcement['published_by_name'] ?? $announcement['fullname'] ?? '',
            $announcement['archived_by_name'] ?? '',
            $announcement['archive_reason'] ?? '',
        ], $archive_search);
    }));
} elseif ($active_tab === 'records') {
    $records = array_values(array_filter($records, function ($record) use ($archive_search, $archive_filter, $date_from, $date_to) {
        if ($archive_filter !== '' && (string) ($record['record_type'] ?? '') !== $archive_filter) {
            return false;
        }
        if (($date_from !== '' || $date_to !== '') && !archiveDateMatches($record['archived_at'] ?? '', $date_from, $date_to)) {
            return false;
        }
        return archiveTextMatches([
            $record['record_label'] ?? '',
            $record['record_name'] ?? '',
            $record['record_date'] ?? '',
            $record['record_detail'] ?? '',
            $record['archive_reason'] ?? '',
            $record['archived_by_name'] ?? '',
            $record['registry_no'] ?? '',
            $record['book_no'] ?? '',
            $record['page_no'] ?? '',
            $record['entry_no'] ?? '',
        ], $archive_search);
    }));
} else {
    $parishioners = array_values(array_filter($parishioners, function ($parishioner) use ($archive_search, $archive_filter, $date_from, $date_to) {
        if ($archive_filter !== '' && (string) ($parishioner['chapel_district'] ?? '') !== $archive_filter) {
            return false;
        }
        $archived_date = $parishioner['archived_at'] ?? ($parishioner['account_state_changed_at'] ?? ($parishioner['updated_at'] ?? $parishioner['created_at']));
        if (($date_from !== '' || $date_to !== '') && !archiveDateMatches($archived_date, $date_from, $date_to)) {
            return false;
        }
        return archiveTextMatches([
            $parishioner['fullname'] ?? '',
            $parishioner['email'] ?? '',
            $parishioner['phone_number'] ?? '',
            $parishioner['address'] ?? '',
            $parishioner['chapel_district'] ?? '',
            $parishioner['archive_reason'] ?? '',
            $parishioner['archived_by_name'] ?? '',
        ], $archive_search);
    }));
}

$active_archive_count = $active_tab === 'requests' ? count($requests) : ($active_tab === 'announcements' ? count($announcements) : ($active_tab === 'records' ? count($records) : count($parishioners)));

$page_title = 'Archives';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Archives' => null
];

include '../templates/header.php';
?>

<div class="container-fluid px-0">
    <!-- Standardized Section Header -->
    <?php
    $page_header_title = 'Archives';
    $page_header_subtitle = 'View and restore archived requests, announcements, sacramental records, and parishioners.';
    $page_header_icon = 'fa-box-archive';
    $show_back_button = true;
    $back_button_url = BASE_URL . 'admin/dashboard.php';
    include '../includes/page_header.php';
    ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-4">
            <style>
                .archive-reason-box {
                    background: #fef2f2;
                    border: 1px solid #fecaca;
                    border-left: 3.5px solid #dc2626;
                    border-radius: 6px;
                    padding: 6px 10px;
                    max-width: 320px;
                }
                .archive-reason-text {
                    font-size: 0.84rem;
                    font-weight: 600;
                    color: #b91c1c;
                    line-height: 1.35;
                    word-break: break-word;
                }
                .archive-meta-date {
                    font-size: 0.83rem;
                    font-weight: 600;
                    color: #1e293b;
                }
                .archive-meta-by {
                    font-size: 0.77rem;
                    color: #64748b;
                    margin-top: 2px;
                }
            </style>

            <ul class="nav nav-tabs mb-4">
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab === 'requests' ? 'active fw-bold' : ''; ?>" href="?tab=requests">
                        <i class="fas fa-inbox me-1"></i> Requests
                        <span class="badge bg-secondary ms-1"><?php echo count($requests); ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab === 'announcements' ? 'active fw-bold' : ''; ?>" href="?tab=announcements">
                        <i class="fas fa-bullhorn me-1"></i> Announcements
                        <span class="badge bg-secondary ms-1"><?php echo count($announcements); ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab === 'records' ? 'active fw-bold' : ''; ?>" href="?tab=records">
                        <i class="fas fa-book-bible me-1"></i> Sacramental Records
                        <span class="badge bg-secondary ms-1"><?php echo count($records); ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab === 'parishioners' ? 'active fw-bold' : ''; ?>" href="?tab=parishioners">
                        <i class="fas fa-users me-1"></i> Parishioners
                        <span class="badge bg-secondary ms-1"><?php echo count($parishioners); ?></span>
                    </a>
                </li>
            </ul>

            <form class="border rounded bg-light p-3 mb-4" method="GET" action="">
                <input type="hidden" name="tab" value="<?php echo e($active_tab); ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-4">
                        <label class="form-label fw-semibold" for="archiveSearch">Search</label>
                        <input class="form-control" id="archiveSearch" type="text" name="q" value="<?php echo e($archive_search); ?>" placeholder="Search archived items, reasons...">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label fw-semibold" for="archiveFilter">
                            <?php 
                                if ($active_tab === 'requests') {
                                    echo 'Status';
                                } elseif ($active_tab === 'announcements') {
                                    echo 'Type';
                                } elseif ($active_tab === 'records') {
                                    echo 'Record Type';
                                } else {
                                    echo 'Chapel / District';
                                }
                            ?>
                        </label>
                        <select class="form-select" id="archiveFilter" name="filter">
                            <option value="">All</option>
                            <?php foreach ($archive_filter_options[$active_tab] ?? [] as $option): ?>
                                <option value="<?php echo e($option); ?>" <?php echo $archive_filter === $option ? 'selected' : ''; ?>>
                                    <?php echo e(ucfirst(str_replace('_', ' ', $option))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2">
                        <label class="form-label fw-semibold" for="archiveDateFrom">From</label>
                        <input class="form-control" id="archiveDateFrom" type="date" name="date_from" value="<?php echo e($date_from); ?>">
                    </div>
                    <div class="col-lg-2">
                        <label class="form-label fw-semibold" for="archiveDateTo">To</label>
                        <input class="form-control" id="archiveDateTo" type="date" name="date_to" value="<?php echo e($date_to); ?>">
                    </div>
                    <div class="col-lg-1 d-grid">
                        <button class="btn btn-primary" type="submit" title="Apply Filter"><i class="fas fa-filter"></i></button>
                    </div>
                </div>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                    <small class="text-muted"><?php echo intval($active_archive_count); ?> result<?php echo $active_archive_count === 1 ? '' : 's'; ?> shown</small>
                    <?php if ($archive_search !== '' || $archive_filter !== '' || $date_from !== '' || $date_to !== ''): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="?tab=<?php echo urlencode($active_tab); ?>">Clear filters</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($active_tab === 'requests'): ?>
                <?php if (count($requests) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Ref #</th>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th style="min-width: 220px;">Reason / Response</th>
                                    <th>Archived</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $request): ?>
                                    <tr>
                                        <td><strong><?php echo e($request['reference_number']); ?></strong></td>
                                        <td><?php echo e($request['fullname']); ?><br><small class="text-muted"><?php echo e($request['email']); ?></small></td>
                                        <td><?php echo e(ucfirst(str_replace('_', ' ', $request['request_type']))); ?></td>
                                        <?php 
                                            $disp_status = strtolower($request['status'] ?? 'pending');
                                        ?>
                                        <td><span class="badge rounded-pill border px-2.5 py-1 fw-semibold <?php echo getStatusBadgeClass($disp_status); ?>"><?php echo e(ucfirst($disp_status)); ?></span></td>
                                        <td>
                                            <?php 
                                                $req_note = trim($request['archive_reason'] ?? '') ?: trim($request['admin_response'] ?? '');
                                            ?>
                                            <?php if (!empty($req_note)): ?>
                                                <div class="archive-reason-box">
                                                    <div class="d-flex align-items-start gap-1">
                                                        <i class="fas fa-comment-dots text-danger mt-1 flex-shrink-0" style="font-size: 0.8rem;"></i>
                                                        <span class="archive-reason-text">
                                                            <?php echo e($req_note); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border fst-italic">No reason recorded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo formatDateTime($request['deleted_at']); ?></td>
                                        <td>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Restore this request?');">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="restore_request">
                                                <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">
                                                    <i class="fas fa-rotate-left me-1"></i> Restore
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mb-0">No archived requests found.</div>
                <?php endif; ?>

            <?php elseif ($active_tab === 'announcements'): ?>
                <?php if (count($announcements) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Title</th>
                                    <th>Type</th>
                                    <th>By</th>
                                    <th style="min-width: 240px;">Archive Reason</th>
                                    <th>Archived</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($announcements as $announcement): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($announcement['title']); ?></strong><br>
                                            <small class="text-muted"><?php echo e(substr($announcement['content'], 0, 100)); ?>...</small>
                                        </td>
                                        <td><?php echo e(ucfirst($announcement['type'])); ?></td>
                                        <td><?php echo e($announcement['published_by_name'] ?? $announcement['fullname'] ?? 'System'); ?></td>
                                        <td>
                                            <?php if (!empty($announcement['archive_reason'])): ?>
                                                <div class="archive-reason-box">
                                                    <div class="d-flex align-items-start gap-1">
                                                        <i class="fas fa-comment-dots text-danger mt-1 flex-shrink-0" style="font-size: 0.8rem;"></i>
                                                        <span class="archive-reason-text">
                                                            <?php echo e($announcement['archive_reason']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border fst-italic">No reason recorded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="archive-meta-date">
                                                <i class="fas fa-clock text-muted me-1"></i><?php echo formatDateTime($announcement['deleted_at']); ?>
                                            </div>
                                            <?php if (!empty($announcement['archived_by_name'])): ?>
                                                <div class="archive-meta-by">
                                                    <i class="fas fa-user-shield text-muted me-1"></i>By <?php echo e($announcement['archived_by_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Restore this announcement?');">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="restore_announcement">
                                                <input type="hidden" name="announcement_id" value="<?php echo $announcement['announcement_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">
                                                    <i class="fas fa-rotate-left me-1"></i> Restore
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mb-0">No archived announcements found.</div>
                <?php endif; ?>

            <?php elseif ($active_tab === 'records'): ?>
                <?php if (count($records) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width: 140px;">Record Type</th>
                                    <th style="min-width: 180px;">Name</th>
                                    <th style="min-width: 110px;">Date</th>
                                    <th style="min-width: 170px;">Details</th>
                                    <th style="min-width: 250px;">Archive Reason</th>
                                    <th style="min-width: 170px;">Archived</th>
                                    <th style="width: 110px; text-align: center;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($records as $record): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-light text-dark border px-2 py-1 fw-semibold">
                                                <i class="fas fa-book-bible me-1 text-primary"></i><?php echo e($record['record_label']); ?>
                                            </span>
                                            <?php if (!empty($record['registry_no'])): ?>
                                                <div class="small text-muted mt-1"><i class="fas fa-hashtag me-1"></i>Reg: <?php echo e($record['registry_no']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong class="text-dark"><?php echo e($record['record_name']); ?></strong>
                                            <?php if (!empty($record['book_no']) || !empty($record['page_no'])): ?>
                                                <div class="text-muted small">
                                                    Bk <?php echo e($record['book_no'] ?: '-'); ?>, Pg <?php echo e($record['page_no'] ?: '-'); ?><?php if (!empty($record['entry_no'])): ?>, Ent <?php echo e($record['entry_no']); ?><?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo formatDate($record['record_date']); ?></td>
                                        <td>
                                            <span class="text-secondary small"><?php echo e($record['record_detail']); ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($record['archive_reason'])): ?>
                                                <div class="archive-reason-box">
                                                    <div class="d-flex align-items-start gap-1">
                                                        <i class="fas fa-comment-dots text-danger mt-1 flex-shrink-0" style="font-size: 0.82rem;"></i>
                                                        <span class="archive-reason-text">
                                                            <?php echo e($record['archive_reason']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border fst-italic">No reason recorded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="archive-meta-date">
                                                <i class="fas fa-clock text-muted me-1"></i><?php echo formatDateTime($record['archived_at']); ?>
                                            </div>
                                            <?php if (!empty($record['archived_by_name'])): ?>
                                                <div class="archive-meta-by">
                                                    <i class="fas fa-user-shield text-muted me-1"></i>By <?php echo e($record['archived_by_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Restore this sacramental record?');">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="restore_record">
                                                <input type="hidden" name="record_type" value="<?php echo e($record['record_type']); ?>">
                                                <input type="hidden" name="record_id" value="<?php echo $record['record_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">
                                                    <i class="fas fa-rotate-left me-1"></i> Restore
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mb-0">No archived sacramental records found.</div>
                <?php endif; ?>

            <?php elseif ($active_tab === 'parishioners'): ?>
                <?php if (count($parishioners) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Full Name &amp; Contact</th>
                                    <th>Address &amp; District</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th style="min-width: 240px;">Archive Reason</th>
                                    <th>Archived Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($parishioners as $p): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($p['fullname']); ?></strong><br>
                                            <small class="text-muted"><i class="fas fa-envelope me-1"></i><?php echo e($p['email'] ?: 'No email'); ?></small>
                                            <?php if (!empty($p['phone_number'])): ?>
                                                <br><small class="text-muted"><i class="fas fa-phone me-1"></i><?php echo e($p['phone_number']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo e($p['address'] ?: 'Not provided'); ?>
                                            <?php if (!empty($p['chapel_district'])): ?>
                                                <br><small class="text-muted"><i class="fas fa-church me-1"></i><?php echo e($p['chapel_district']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-info text-dark"><?php echo e(ucfirst($p['role'])); ?></span></td>
                                        <td><span class="badge bg-secondary">Archived</span></td>
                                        <td>
                                            <?php if (!empty($p['archive_reason'])): ?>
                                                <div class="archive-reason-box">
                                                    <div class="d-flex align-items-start gap-1">
                                                        <i class="fas fa-comment-dots text-danger mt-1 flex-shrink-0" style="font-size: 0.8rem;"></i>
                                                        <span class="archive-reason-text">
                                                            <?php echo e($p['archive_reason']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border fst-italic">No reason recorded</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="archive-meta-date">
                                                <i class="fas fa-clock text-muted me-1"></i><?php echo formatDateTime($p['archived_at']); ?>
                                            </div>
                                            <?php if (!empty($p['archived_by_name'])): ?>
                                                <div class="archive-meta-by">
                                                    <i class="fas fa-user-shield text-muted me-1"></i>By <?php echo e($p['archived_by_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Restore this parishioner account to Active?');">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="restore_parishioner">
                                                <input type="hidden" name="user_id" value="<?php echo intval($p['id']); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success" title="Restore Parishioner">
                                                    <i class="fas fa-rotate-left me-1"></i> Restore
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mb-0">No archived parishioners found.</div>
                <?php endif; ?>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
