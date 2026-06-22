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
    $action = $_POST['action'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);
    
    if ($action == 'update_status') {
        $allowed_statuses = ['active', 'inactive', 'pending_verification', 'rejected', 'archived'];
        $status = $_POST['status'] ?? 'inactive';
        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'inactive';
        }
        $status = $conn->real_escape_string($status);
        $sql = "UPDATE users SET status = '$status' WHERE id = $user_id AND role = 'user'";
        
        if ($conn->query($sql)) {
            createAuditLog($conn, $_SESSION['user_id'], 'UPDATE_USER', 'users', $user_id);
            $success = 'User status updated successfully!';
        } else {
            $error = 'Error updating user: ' . $conn->error;
        }
    } elseif ($action == 'archive_user') {
        $sql = "UPDATE users SET status = 'archived' WHERE id = $user_id AND role = 'user'";

        if ($conn->query($sql)) {
            createAuditLog($conn, $_SESSION['user_id'], 'ARCHIVE_USER', 'users', $user_id);
            $success = 'User archived successfully!';
        } else {
            $error = 'Error archiving user: ' . $conn->error;
        }
    }
}

// Get users
$search = $_GET['search'] ?? '';
$page = intval($_GET['page'] ?? 1);
$limit = 10;

$scope = $_GET['scope'] ?? '';
$where = $scope === 'archived' ? "WHERE role = 'user' AND status = 'archived'" : "WHERE role = 'user' AND status != 'archived'";
if (!empty($search)) {
    $search_escaped = $conn->real_escape_string($search);
    $where .= " AND (fullname LIKE '%$search_escaped%' OR email LIKE '%$search_escaped%')";
}

$total_result = $conn->query("SELECT COUNT(*) as count FROM users $where");
$total = $total_result->fetch_assoc()['count'];
$pagination = getPaginationData($page, $limit, $total);

$sql = "SELECT * FROM users $where ORDER BY created_at DESC LIMIT {$pagination['offset']}, {$pagination['limit']}";
$result = $conn->query($sql);

$page_title = 'Manage Users';

// Set breadcrumb data
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Manage Users' => null
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
            <h1 class="mb-2"><i class="fas fa-users"></i> Manage Users</h1>
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
            <!-- Search -->
            <form method="GET" class="mb-3">
                <input type="hidden" name="scope" value="<?php echo e($scope); ?>">
                <div class="input-group">
                    <input type="text" class="form-control" name="search" placeholder="Search by name or email..." value="<?php echo sanitize($search); ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
            </form>

            <div class="d-flex gap-2 mb-3">
                <a class="btn btn-sm <?php echo $scope !== 'archived' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="manage-users.php">
                    Active Users
                </a>
                <a class="btn btn-sm <?php echo $scope === 'archived' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="manage-users.php?scope=archived">
                    Archived Users
                </a>
            </div>

            <!-- Users Table -->
            <?php if ($result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($user = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo sanitize($user['fullname']); ?></strong></td>
                                    <td><?php echo $user['email']; ?></td>
                                    <td><?php echo e($user['phone_number'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo e(getUserStatusBadgeClass($user['status'])); ?>">
                                            <?php echo e(getUserStatusLabel($user['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatDate($user['created_at']); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                                data-bs-target="#userModal"
                                                data-user-id="<?php echo $user['id']; ?>"
                                                data-user-status="<?php echo $user['status']; ?>">
                                            <i class="fas fa-edit"></i> Update
                                        </button>
                                        <?php if ($user['status'] !== 'archived'): ?>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Archive this user? The account will be hidden from the active user list.');">
                                                <input type="hidden" name="action" value="archive_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                    <i class="fas fa-archive"></i> Archive
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info">No users found</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- User Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="user_id" id="user_id">
                    
                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="user_status" name="status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="pending_verification">Pending Verification</option>
                            <option value="rejected">Rejected</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('userModal').addEventListener('show.bs.modal', function(e) {
    const button = e.relatedTarget;
    document.getElementById('user_id').value = button.getAttribute('data-user-id');
    document.getElementById('user_status').value = button.getAttribute('data-user-status');
});
</script>

<?php include '../templates/footer.php'; ?>
