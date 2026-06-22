<?php
/**
 * Manage Requests Page
 * Admin interface for managing user requests
 */

// Include centralized session management
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

// Require admin access
requireAdmin();
requirePermission('requests.manage');
ensureEmailNotificationSchema($conn);
ensureRequestDocumentsSchema($conn);
ensureRequestPaymentsSchema($conn);

$error = '';
$success = '';

// Ensure Request Archive Column Function - Documents this helper's role in the parish management workflow.
function ensureRequestArchiveColumn($conn) {
    $result = $conn->query("SHOW COLUMNS FROM requests LIKE 'deleted_at'");
    if ($result && $result->num_rows > 0) {
        return true;
    }

    return $conn->query("ALTER TABLE requests ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
}

if (!ensureRequestArchiveColumn($conn)) {
    $error = 'Error preparing request archive: ' . $conn->error;
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'archive_request') {
    $request_id = intval($_POST['request_id']);

    if ($conn->query("UPDATE requests SET deleted_at = NOW() WHERE request_id = $request_id")) {
        createAuditLog($conn, $_SESSION['user_id'], 'ARCHIVE_REQUEST', 'requests', $request_id);
        $success = 'Request archived successfully!';
    } else {
        $error = 'Error archiving request: ' . $conn->error;
    }
} elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_id'])) {
    $request_id = intval($_POST['request_id']);
    $status = $conn->real_escape_string($_POST['status']);
    $admin_response = $conn->real_escape_string($_POST['admin_response']);

    $approval_conflict = ['conflict' => false, 'message' => ''];
    if ($status === 'approved') {
        $approval_conflict = requestApprovalConflict($conn, $request_id);
    }

    if ($approval_conflict['conflict']) {
        $error = $approval_conflict['message'] . ' Please choose another schedule before approving.';
    } else {
        $sql = "UPDATE requests SET status = '$status', admin_response = '$admin_response'
                WHERE request_id = $request_id";

        if ($conn->query($sql)) {
        // Get user_id for notification
        $req_result = $conn->query("SELECT r.user_id, r.reference_number, r.request_type, u.email, u.fullname, COALESCE(np.email_enabled, 1) AS email_enabled
            FROM requests r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN notification_preferences np ON np.user_id = u.id AND np.category = 'requests'
            WHERE r.request_id = $request_id");
        $req_data = $req_result->fetch_assoc();
        
        // Create notification
        $status_message = "Your request status has been updated to: " . ucfirst($status);
        if ($status === 'rejected' && trim($admin_response) !== '') {
            $status_message .= ". Reason: " . $admin_response;
        }
        createNotification($conn, $req_data['user_id'], 'Request Update', $status_message);
        if ($req_data && intval($req_data['email_enabled']) === 1) {
            $subject = 'TUGON Request ' . ucfirst($status) . ' - ' . $req_data['reference_number'];
            $body = '<p>Hello ' . e($req_data['fullname']) . ',</p>'
                . '<p>Your request <strong>' . e($req_data['reference_number']) . '</strong> is now <strong>' . e(ucfirst($status)) . '</strong>.</p>'
                . ($admin_response !== '' ? '<p>Parish office note: ' . nl2br(e($admin_response)) . '</p>' : '')
                . '<p>Please open TUGON for full details and next steps.</p>';
            $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'users/my-requests.php?q=' . urlencode($req_data['reference_number']);
            sendTugonEmail($conn, $req_data['email'], $subject, tugonEmailTemplate('Request Status Update', $body, 'Track Request', $url), '', $req_data['user_id'], 'request_' . $status);
        }
        
        createAuditLog($conn, $_SESSION['user_id'], 'UPDATE_REQUEST', 'requests', $request_id);
        if ($status === 'approved') {
            $sync_result = syncApprovedRequestToCalendar($conn, $request_id, $_SESSION['user_id']);
            $success = 'Request updated successfully!';
            if ($sync_result['success'] && in_array($sync_result['message'], ['Calendar event created.', 'Calendar event updated.'], true)) {
                $success .= ' It is now synced to the calendar.';
            } elseif (!$sync_result['success']) {
                $success .= ' Calendar sync skipped: ' . $sync_result['message'];
            }
        } else {
            if (in_array($status, ['pending', 'processing', 'rejected'], true)) {
                cancelLinkedRequestCalendarEvent($conn, $request_id);
            }
            $success = 'Request updated successfully!';
        }
        } else {
            $error = 'Error updating request: ' . $conn->error;
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$count_where = "WHERE deleted_at IS NULL";
$list_where = "WHERE r.deleted_at IS NULL";
if (!empty($status_filter)) {
    $status_filter = $conn->real_escape_string($status_filter);
    $count_where .= " AND status = '$status_filter'";
    $list_where .= " AND r.status = '$status_filter'";
}
if (!empty($type_filter)) {
    $type_filter = $conn->real_escape_string($type_filter);
    $count_where .= " AND request_type = '$type_filter'";
    $list_where .= " AND r.request_type = '$type_filter'";
}

$page = intval($_GET['page'] ?? 1);
$limit = 10;

$total_result = $conn->query("SELECT COUNT(*) as count FROM requests $count_where");
$total = $total_result ? (int) $total_result->fetch_assoc()['count'] : 0;
if (!$total_result) {
    $error = 'Error loading request count: ' . $conn->error;
}
$pagination = getPaginationData($page, $limit, $total);

$sql = "SELECT r.*, u.fullname, u.email,
        COUNT(DISTINCT d.document_id) AS document_count,
        COUNT(DISTINCT p.payment_id) AS payment_count,
        COUNT(DISTINCT CASE WHEN p.status = 'verified' THEN p.payment_id END) AS verified_payment_count
        FROM requests r 
        JOIN users u ON r.user_id = u.id 
        LEFT JOIN request_documents d ON d.request_id = r.request_id AND d.document_type = 'requirement' AND d.deleted_at IS NULL
        LEFT JOIN request_payments p ON p.request_id = r.request_id
        $list_where 
        GROUP BY r.request_id
        ORDER BY r.date_requested DESC 
        LIMIT {$pagination['offset']}, {$pagination['limit']}";
$result = $conn->query($sql);
if (!$result) {
    $error = 'Error loading requests: ' . $conn->error;
}

$page_title = 'Manage Requests';

// Set breadcrumb data
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Manage Requests' => null
];
?>
<?php include '../templates/header.php'; ?>

<div class="container-fluid mt-4">
    <!-- Breadcrumb Navigation -->
    <?php include '../includes/breadcrumb.php'; ?>
    
    <!-- Back Button -->
    <?php include '../includes/back_button.php'; ?>

    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="mb-2"><i class="fas fa-tasks"></i> Manage Requests</h1>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <!-- Filter Buttons -->
            <div class="btn-group mb-3" role="group">
                <a href="?status=&type=<?php echo urlencode($type_filter); ?>" class="btn btn-outline-primary <?php echo empty($status_filter) ? 'active' : ''; ?>">
                    All
                </a>
                <a href="?status=pending&type=<?php echo urlencode($type_filter); ?>" class="btn btn-outline-warning <?php echo $status_filter == 'pending' ? 'active' : ''; ?>">
                    Pending
                </a>
                <a href="?status=approved&type=<?php echo urlencode($type_filter); ?>" class="btn btn-outline-success <?php echo $status_filter == 'approved' ? 'active' : ''; ?>">
                    Approved
                </a>
                <a href="?status=rejected&type=<?php echo urlencode($type_filter); ?>" class="btn btn-outline-danger <?php echo $status_filter == 'rejected' ? 'active' : ''; ?>">
                    Rejected
                </a>
                <a href="?status=completed&type=<?php echo urlencode($type_filter); ?>" class="btn btn-outline-info <?php echo $status_filter == 'completed' ? 'active' : ''; ?>">
                    Completed
                </a>
            </div>

            <?php if (!empty($type_filter)): ?>
                <div class="alert alert-light border d-flex justify-content-between align-items-center">
                    <span>
                        <i class="fas fa-filter"></i>
                        Showing request type: <strong><?php echo e(ucfirst(str_replace('_', ' ', $type_filter))); ?></strong>
                    </span>
                    <a href="?status=<?php echo urlencode($status_filter); ?>" class="btn btn-sm btn-outline-secondary">Clear Type Filter</a>
                </div>
            <?php endif; ?>

            <!-- Requests Table -->
            <?php if ($result && $result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Ref #</th>
                                <th>User</th>
                                <th>Type</th>
                                <th>Requirements</th>
                                <th>Payments</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($request = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo $request['reference_number']; ?></strong></td>
                                    <td><?php echo sanitize($request['fullname']); ?><br><small><?php echo $request['email']; ?></small></td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $request['request_type'])); ?></td>
                                    <td>
                                        <?php if (intval($request['document_count'] ?? 0) > 0): ?>
                                            <span class="badge bg-primary"><i class="fas fa-paperclip"></i> <?php echo intval($request['document_count']); ?> file</span>
                                        <?php else: ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (intval($request['payment_count'] ?? 0) > 0): ?>
                                            <span class="badge bg-success"><i class="fas fa-receipt"></i> <?php echo intval($request['verified_payment_count'] ?? 0); ?>/<?php echo intval($request['payment_count']); ?> verified</span>
                                        <?php else: ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo getStatusBadgeClass($request['status']); ?>">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatDate($request['date_requested']); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" 
                                                data-bs-target="#processModal" 
                                                data-request-id="<?php echo $request['request_id']; ?>"
                                                data-request-type="<?php echo $request['request_type']; ?>"
                                                data-current-status="<?php echo $request['status']; ?>">
                                            <i class="fas fa-edit"></i> Process
                                        </button>
                                        <a href="request-workflow.php?id=<?php echo intval($request['request_id']); ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-route"></i> Workflow
                                        </a>
                                        <form method="POST" action="" class="d-inline" onsubmit="return confirm('Archive this request? It will be hidden from this list but kept in the database.');">
                                            <input type="hidden" name="action" value="archive_request">
                                            <input type="hidden" name="request_id" value="<?php echo $request['request_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                <i class="fas fa-archive"></i> Archive
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($pagination['total_pages'] > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filter); ?>&type=<?php echo urlencode($type_filter); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info">No requests found</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Process Request Modal -->
<div class="modal fade" id="processModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Process Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="request_id" id="request_id">
                    
                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="processing">Processing</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="admin_response" class="form-label">Admin Response/Notes</label>
                        <textarea class="form-control" id="admin_response" name="admin_response" rows="4"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('processModal').addEventListener('show.bs.modal', function(e) {
    const button = e.relatedTarget;
    document.getElementById('request_id').value = button.getAttribute('data-request-id');
    document.getElementById('status').value = button.getAttribute('data-current-status');
});
</script>

<?php include '../templates/footer.php'; ?>
