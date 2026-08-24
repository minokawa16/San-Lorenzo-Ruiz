<?php
/**
 * Archives Module
 * Shows archived requests, announcements, and sacramental records.
 */

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
require_once '../services/SacramentalRecordService.php';

requireAdmin();
requirePermission('archives.manage');

$page_title = 'Archives';
$error = '';
$success = '';
$active_tab = $_GET['tab'] ?? 'requests';
$allowed_tabs = ['requests', 'announcements', 'records'];
if (!in_array($active_tab, $allowed_tabs, true)) {
    $active_tab = 'requests';
}
$archive_search = trim((string) ($_GET['q'] ?? ''));
$archive_filter = trim((string) ($_GET['filter'] ?? ''));
$date_from = trim((string) ($_GET['date_from'] ?? ''));
$date_to = trim((string) ($_GET['date_to'] ?? ''));

// Ensure Archive Column Function - Documents this helper's role in the parish management workflow.
function ensureArchiveColumn($conn, $table) {
    return columnExists($conn, $table, 'deleted_at');
}

// Archive Table Exists Function - Documents this helper's role in the parish management workflow.
function archiveTableExists($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    return schemaTableExists($conn, $table);
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
                (new SacramentalRecordService($conn))->restore($record_type,$record_id,(int)$_SESSION['user_id']);
                $success = $meta['label'] . ' record restored successfully!';
                $active_tab = 'records';
            } catch(Throwable $exception) {$error=$exception->getMessage();}
        }
    }
}

$requests = [];
$request_sql = "SELECT r.request_id, r.reference_number, r.request_type, r.status, r.date_requested, r.deleted_at, u.fullname, u.email
                FROM requests r
                JOIN users u ON r.user_id = u.id
                WHERE r.deleted_at IS NOT NULL
                ORDER BY r.deleted_at DESC";
$request_result = $conn->query($request_sql);
while ($request_result && $row = $request_result->fetch_assoc()) {
    $requests[] = $row;
}

$announcements = [];
$announcement_sql = "SELECT a.announcement_id, a.title, a.content, a.type, a.published_date, a.deleted_at, u.fullname
                     FROM announcements a
                     JOIN users u ON a.published_by = u.id
                     WHERE a.deleted_at IS NOT NULL
                     ORDER BY a.deleted_at DESC";
$announcement_result = $conn->query($announcement_sql);
while ($announcement_result && $row = $announcement_result->fetch_assoc()) {
    $announcements[] = $row;
}

$records = [];
foreach ($record_tables as $type => $meta) {
    if (!archiveTableExists($conn, $meta['table'])) {
        continue;
    }

    $sql = "SELECT '{$type}' AS record_type,
                   '{$meta['label']}' AS record_label,
                   {$meta['id']} AS record_id,
                   {$meta['name']} AS record_name,
                   {$meta['date']} AS record_date,
                   {$meta['detail']} AS record_detail,
                   updated_at AS archived_at
            FROM {$meta['table']}
            WHERE status = 'archived'";
    $result = $conn->query($sql);
    while ($result && $row = $result->fetch_assoc()) {
        $records[] = $row;
    }
}
usort($records, function ($a, $b) {
    return strtotime($b['archived_at'] ?? '1970-01-01') <=> strtotime($a['archived_at'] ?? '1970-01-01');
});

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
];
foreach ($archive_filter_options as $key => $values) {
    $archive_filter_options[$key] = array_values(array_filter($values, function ($value) {
        return trim((string) $value) !== '';
    }));
    sort($archive_filter_options[$key]);
}

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

function archiveTextMatches($haystack, $needle) {
    if ($needle === '') {
        return true;
    }
    return stripos(implode(' ', array_map('strval', $haystack)), $needle) !== false;
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
            $announcement['fullname'] ?? '',
        ], $archive_search);
    }));
} else {
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
        ], $archive_search);
    }));
}

$active_archive_count = $active_tab === 'requests' ? count($requests) : ($active_tab === 'announcements' ? count($announcements) : count($records));

$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Archives' => null
];
?>
<?php include '../templates/header.php'; ?>

<div class="container-fluid mt-4">
    <?php include '../includes/breadcrumb.php'; ?>
    <?php include '../includes/back_button.php'; ?>

    <div class="row mb-4">
        <div class="col-md-12">
            <h1 class="mb-2"><i class="fas fa-box-archive"></i> Archives</h1>
            <p class="text-muted">View and restore archived requests, announcements, and sacramental records.</p>
        </div>
    </div>

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

    <div class="card">
        <div class="card-body">
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab === 'requests' ? 'active' : ''; ?>" href="?tab=requests">
                        <i class="fas fa-inbox"></i> Requests
                        <span class="badge bg-secondary"><?php echo count($requests); ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab === 'announcements' ? 'active' : ''; ?>" href="?tab=announcements">
                        <i class="fas fa-bullhorn"></i> Announcements
                        <span class="badge bg-secondary"><?php echo count($announcements); ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_tab === 'records' ? 'active' : ''; ?>" href="?tab=records">
                        <i class="fas fa-book-bible"></i> Sacramental Records
                        <span class="badge bg-secondary"><?php echo count($records); ?></span>
                    </a>
                </li>
            </ul>

            <form class="border rounded bg-light p-3 mb-3" method="GET" action="">
                <input type="hidden" name="tab" value="<?php echo e($active_tab); ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-4">
                        <label class="form-label" for="archiveSearch">Search</label>
                        <input class="form-control" id="archiveSearch" type="text" name="q" value="<?php echo e($archive_search); ?>" placeholder="Search archived items">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label" for="archiveFilter">
                            <?php echo $active_tab === 'requests' ? 'Status' : ($active_tab === 'announcements' ? 'Type' : 'Record Type'); ?>
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
                        <label class="form-label" for="archiveDateFrom">From</label>
                        <input class="form-control" id="archiveDateFrom" type="date" name="date_from" value="<?php echo e($date_from); ?>">
                    </div>
                    <div class="col-lg-2">
                        <label class="form-label" for="archiveDateTo">To</label>
                        <input class="form-control" id="archiveDateTo" type="date" name="date_to" value="<?php echo e($date_to); ?>">
                    </div>
                    <div class="col-lg-1 d-grid">
                        <button class="btn btn-primary" type="submit"><i class="fas fa-filter"></i></button>
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
                                    <th>Archived</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $request): ?>
                                    <tr>
                                        <td><strong><?php echo e($request['reference_number']); ?></strong></td>
                                        <td><?php echo e($request['fullname']); ?><br><small><?php echo e($request['email']); ?></small></td>
                                        <td><?php echo e(ucfirst(str_replace('_', ' ', $request['request_type']))); ?></td>
                                        <td><span class="badge bg-<?php echo getStatusBadgeClass($request['status']); ?>"><?php echo e(ucfirst($request['status'])); ?></span></td>
                                        <td><?php echo formatDateTime($request['deleted_at']); ?></td>
                                        <td>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Restore this request?');">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="restore_request">
                                                <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">
                                                    <i class="fas fa-rotate-left"></i> Restore
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mb-0">No archived requests.</div>
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
                                        <td><?php echo e($announcement['fullname']); ?></td>
                                        <td><?php echo formatDateTime($announcement['deleted_at']); ?></td>
                                        <td>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Restore this announcement?');">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="restore_announcement">
                                                <input type="hidden" name="announcement_id" value="<?php echo $announcement['announcement_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">
                                                    <i class="fas fa-rotate-left"></i> Restore
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mb-0">No archived announcements.</div>
                <?php endif; ?>
            <?php else: ?>
                <?php if (count($records) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Record Type</th>
                                    <th>Name</th>
                                    <th>Date</th>
                                    <th>Details</th>
                                    <th>Archived</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($records as $record): ?>
                                    <tr>
                                        <td><?php echo e($record['record_label']); ?></td>
                                        <td><strong><?php echo e($record['record_name']); ?></strong></td>
                                        <td><?php echo formatDate($record['record_date']); ?></td>
                                        <td><?php echo e($record['record_detail']); ?></td>
                                        <td><?php echo formatDateTime($record['archived_at']); ?></td>
                                        <td>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Restore this sacramental record?');">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="restore_record">
                                                <input type="hidden" name="record_type" value="<?php echo e($record['record_type']); ?>">
                                                <input type="hidden" name="record_id" value="<?php echo $record['record_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success">
                                                    <i class="fas fa-rotate-left"></i> Restore
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mb-0">No archived sacramental records.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
