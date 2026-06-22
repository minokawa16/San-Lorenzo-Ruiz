<?php
/**
 * Archives Module
 * Shows archived requests, announcements, and sacramental records.
 */

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

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

// Ensure Archive Column Function - Documents this helper's role in the parish management workflow.
function ensureArchiveColumn($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'deleted_at'");
    if ($result && $result->num_rows > 0) {
        return true;
    }

    return $conn->query("ALTER TABLE `$table` ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
}

// Archive Table Exists Function - Documents this helper's role in the parish management workflow.
function archiveTableExists($conn, $table) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result && $result->num_rows > 0;
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
        if ($conn->query("UPDATE announcements SET deleted_at = NULL, status = 'active' WHERE announcement_id = $announcement_id")) {
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
            $table = $meta['table'];
            $id_column = $meta['id'];

            if ($conn->query("UPDATE `$table` SET status = 'active' WHERE `$id_column` = $record_id")) {
                createAuditLog($conn, $_SESSION['user_id'], 'RESTORE_SACRAMENTAL_RECORD', $table, $record_id);
                $success = $meta['label'] . ' record restored successfully!';
                $active_tab = 'records';
            } else {
                $error = 'Error restoring record: ' . $conn->error;
            }
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
