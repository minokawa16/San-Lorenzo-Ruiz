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
?>
<?php include '../templates/header.php'; ?>

<div class="container-fluid mt-4">
    <!-- Breadcrumb Navigation -->
    <?php include '../includes/breadcrumb.php'; ?>
    
    <!-- Back Button -->
    <?php include '../includes/back_button.php'; ?>
    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="mb-2"><i class="fas fa-people-roof"></i> Manage Parishioners</h1>
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
                    <input type="text" class="form-control" name="search" placeholder="Search by name, email, or address..." value="<?php echo sanitize($search); ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
            </form>

            <div class="d-flex gap-2 mb-3">
                <a class="btn btn-sm <?php echo $scope !== 'archived' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="manage-users.php">
                    Active Parishioners
                </a>
                <a class="btn btn-sm <?php echo $scope === 'archived' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="manage-users.php?scope=archived">
                    Archived Parishioners
                </a>
            </div>

            <!-- Users Table -->
            <?php if (!empty($users)): ?>
                <div class="table-responsive">
                    <table class="table table-hover keep-table">
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
                            <?php foreach ($users as $user): ?>
                                <?php $view_modal_id = 'viewUserModal' . intval($user['id']); ?>
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
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-stable-modal-open="#<?php echo e($view_modal_id); ?>">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if ($user['status'] !== 'archived'): ?>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Archive this parishioner? The account will be hidden from the active parishioner list.');">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="archive_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                    <i class="fas fa-archive"></i> Archive
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title"><?php echo e($user['fullname']); ?></h5>
                                    <button type="button" class="btn-close" data-stable-modal-close aria-label="Close parishioner details"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row g-4">
                                        <div class="col-md-6">
                                            <h6 class="fw-bold border-bottom pb-2 mb-3">Personal Information</h6>
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
                                            <h6 class="fw-bold border-bottom pb-2 mb-3">Contact Information</h6>
                                            <div class="row g-3">
                                                <div class="col-sm-6"><div class="text-muted small">Email Address</div><div class="fw-semibold"><?php echo e(userDetailValue($user['email'] ?? '')); ?></div></div>
                                                <div class="col-sm-6"><div class="text-muted small">Phone Number</div><div class="fw-semibold"><?php echo e(userDetailValue($user['phone_number'] ?? '')); ?></div></div>
                                                <div class="col-12"><div class="text-muted small">Complete Home Address</div><div class="fw-semibold"><?php echo e(userDetailValue($user['address'] ?? '')); ?></div></div>
                                                <div class="col-12"><div class="text-muted small">Chapel / District</div><div class="fw-semibold"><?php echo e(userDetailValue($user['chapel_district'] ?? '')); ?></div></div>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <h6 class="fw-bold border-bottom pb-2 mb-3">Sacramental Information</h6>
                                            <div class="row g-3">
                                                <div class="col-12"><div class="text-muted small">Registration Sacramental Fields</div><div class="fw-semibold">Not collected during account registration.</div></div>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <h6 class="fw-bold border-bottom pb-2 mb-3">Account &amp; Verification Details</h6>
                                            <div class="row g-3">
                                                <div class="col-sm-6"><div class="text-muted small">Date Registered / Joined</div><div class="fw-semibold"><?php echo e(userDetailDateTime($user['created_at'] ?? '')); ?></div></div>
                                                <div class="col-sm-6">
                                                    <div class="text-muted small">Verification Status</div>
                                                    <span class="badge bg-<?php echo e(getUserStatusBadgeClass($user['status'])); ?>"><?php echo e(getUserStatusLabel($user['status'])); ?></span>
                                                </div>
                                                <div class="col-sm-6"><div class="text-muted small">Verified By</div><div class="fw-semibold"><?php echo e(userDetailValue($user['verified_by_name'] ?? '')); ?></div></div>
                                                <div class="col-sm-6"><div class="text-muted small">Date Verified</div><div class="fw-semibold"><?php echo e(userDetailDateTime($user['verified_at'] ?? '')); ?></div></div>
                                                <div class="col-sm-6"><div class="text-muted small">Email Verified</div><div class="fw-semibold"><?php echo e(userDetailDateTime($user['email_verified_at'] ?? '')); ?></div></div>
                                                <div class="col-sm-6"><div class="text-muted small">Phone Verified</div><div class="fw-semibold"><?php echo e(userDetailDateTime($user['phone_verified_at'] ?? '')); ?></div></div>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <h6 class="fw-bold border-bottom pb-2 mb-3">Submitted Documents</h6>
                                            <?php if (!$can_view_documents): ?>
                                                <div class="alert alert-warning mb-0">Document preview requires registration verification permission.</div>
                                            <?php else: ?>
                                                <div class="row g-3">
                                                    <?php if ($front_id_url): ?>
                                                        <div class="col-md-4">
                                                            <a href="<?php echo e($front_id_url); ?>" target="_blank" class="d-block text-decoration-none">
                                                                <img src="<?php echo e($front_id_url); ?>" class="img-thumbnail mb-2" alt="Valid ID front" style="height: 150px; width: 100%; object-fit: cover;">
                                                                <span class="btn btn-sm btn-outline-primary w-100">Open Valid ID Front</span>
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($back_id_url): ?>
                                                        <div class="col-md-4">
                                                            <a href="<?php echo e($back_id_url); ?>" target="_blank" class="d-block text-decoration-none">
                                                                <img src="<?php echo e($back_id_url); ?>" class="img-thumbnail mb-2" alt="Valid ID back" style="height: 150px; width: 100%; object-fit: cover;">
                                                                <span class="btn btn-sm btn-outline-primary w-100">Open Valid ID Back</span>
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($face_url): ?>
                                                        <div class="col-md-4">
                                                            <a href="<?php echo e($face_url); ?>" target="_blank" class="d-block text-decoration-none">
                                                                <img src="<?php echo e($face_url); ?>" class="img-thumbnail mb-2" alt="Face verification image" style="height: 150px; width: 100%; object-fit: cover;">
                                                                <span class="btn btn-sm btn-outline-primary w-100">Open Face Image</span>
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
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-stable-modal-close>Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-info">No parishioners found</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
