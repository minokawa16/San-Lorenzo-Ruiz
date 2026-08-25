<?php
/**
 * Manage Parishioners Page
 * Admin interface for viewing and archiving parishioner registrations.
 */

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('users.view');
ensureUserVerificationSchema($conn);

$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);

    if ($action === 'archive_parishioner' && $user_id > 0) {
        $isParishioner = $conn->prepare(
            "SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = ? AND r.role_key = 'parishioner' LIMIT 1"
        );
        $isParishioner->bind_param('i', $user_id);
        $isParishioner->execute();
        $allowed = $isParishioner->get_result()->num_rows > 0;
        $isParishioner->close();

        if ($allowed && transitionAccountStatus($conn, $user_id, 'archived', 'archived', null, (int) $_SESSION['user_id'])) {
            createAuditLog($conn, $_SESSION['user_id'], 'ARCHIVE_PARISHIONER', 'users', $user_id);
            $success = 'Parishioner archived successfully!';
        } else {
            $error = 'Error archiving parishioner: ' . $conn->error;
        }
    }
}

$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$scope = $_GET['scope'] ?? '';

$where = $scope === 'archived'
    ? "WHERE u.role = 'user' AND u.status = 'archived'"
    : "WHERE u.role = 'user' AND u.status != 'archived'";

if ($search !== '') {
    $search_escaped = $conn->real_escape_string($search);
    $where .= " AND (u.fullname LIKE '%$search_escaped%' OR u.email LIKE '%$search_escaped%' OR u.address LIKE '%$search_escaped%' OR u.chapel_district LIKE '%$search_escaped%')";
}

$total_result = $conn->query("SELECT COUNT(*) AS count FROM users u $where");
$total = $total_result ? intval($total_result->fetch_assoc()['count'] ?? 0) : 0;
$pagination = getPaginationData($page, $limit, $total);

$sql = "SELECT u.*, verifier.fullname AS verified_by_name
    FROM users u
    LEFT JOIN users verifier ON u.verified_by = verifier.id
    $where
    ORDER BY u.created_at DESC
    LIMIT {$pagination['offset']}, {$pagination['limit']}";
$result = $conn->query($sql);
$parishioners = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $parishioners[] = $row;
    }
}

function parishionerValue($value, $fallback = 'Not provided') {
    $value = trim((string) ($value ?? ''));
    return $value !== '' ? $value : $fallback;
}

function parishionerDateValue($value) {
    return !empty($value) ? formatDate($value) : 'Not provided';
}

function parishionerDateTimeValue($value) {
    return !empty($value) ? formatDateTime($value) : 'Not provided';
}

$page_title = 'Manage Parishioners';
$body_extra_class = 'stable-detail-modals';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Manage Parishioners' => null
];
?>
<?php include '../templates/header.php'; ?>

<div class="container-fluid mt-4">
    <?php include '../includes/breadcrumb.php'; ?>
    <?php include '../includes/back_button.php'; ?>

    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="mb-2"><i class="fas fa-people-roof"></i> Manage Parishioners</h1>
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
            <form method="GET" class="mb-3">
                <input type="hidden" name="scope" value="<?php echo e($scope); ?>">
                <div class="input-group">
                    <input type="text" class="form-control" name="search" placeholder="Search by name, email, or address..." value="<?php echo e($search); ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
            </form>

            <div class="d-flex gap-2 mb-3">
                <a class="btn btn-sm <?php echo $scope !== 'archived' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="manage-parishioners.php">
                    Active Parishioners
                </a>
                <a class="btn btn-sm <?php echo $scope === 'archived' ? 'btn-primary' : 'btn-outline-primary'; ?>" href="manage-parishioners.php?scope=archived">
                    Archived Parishioners
                </a>
            </div>

            <?php if (!empty($parishioners)): ?>
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
                            <?php foreach ($parishioners as $parishioner): ?>
                                <?php
                                $modal_id = 'parishionerModal' . intval($parishioner['id']);
                                $front_id_url = !empty($parishioner['valid_id_path']) ? 'view-valid-id.php?id=' . intval($parishioner['id']) . '&type=id' : '';
                                $back_id_url = !empty($parishioner['valid_id_back_path']) ? 'view-valid-id.php?id=' . intval($parishioner['id']) . '&type=back' : '';
                                $face_url = !empty($parishioner['face_image_path']) ? 'view-valid-id.php?id=' . intval($parishioner['id']) . '&type=face' : '';
                                ?>
                                <tr>
                                    <td><strong><?php echo e($parishioner['fullname']); ?></strong></td>
                                    <td><?php echo e($parishioner['email'] ?? 'N/A'); ?></td>
                                    <td><?php echo e($parishioner['phone_number'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo e(getUserStatusBadgeClass($parishioner['status'])); ?>">
                                            <?php echo e(getUserStatusLabel($parishioner['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo e(formatDate($parishioner['created_at'])); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-stable-modal-open="#<?php echo e($modal_id); ?>">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if ($parishioner['status'] !== 'archived'): ?>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Archive this parishioner? The record will be hidden from the active parishioner list.');">
                                                <input type="hidden" name="action" value="archive_parishioner">
                                                <input type="hidden" name="user_id" value="<?php echo intval($parishioner['id']); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                    <i class="fas fa-archive"></i> Archive
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <div class="modal stable-detail-modal" id="<?php echo e($modal_id); ?>" tabindex="-1" role="dialog" aria-modal="true" aria-hidden="true">
                                    <div class="modal-dialog modal-xl modal-dialog-scrollable">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Parishioner Registration Details</h5>
                                                <button type="button" class="btn-close" data-stable-modal-close aria-label="Close parishioner details"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row g-4">
                                                    <div class="col-lg-6">
                                                        <h6 class="fw-bold border-bottom pb-2 mb-3">Personal Information</h6>
                                                        <dl class="row mb-0">
                                                            <dt class="col-sm-4">Full Name</dt><dd class="col-sm-8"><?php echo e(parishionerValue($parishioner['fullname'])); ?></dd>
                                                            <dt class="col-sm-4">First Name</dt><dd class="col-sm-8"><?php echo e(parishionerValue($parishioner['first_name'] ?? '')); ?></dd>
                                                            <dt class="col-sm-4">Surname</dt><dd class="col-sm-8"><?php echo e(parishionerValue($parishioner['surname'] ?? '')); ?></dd>
                                                            <dt class="col-sm-4">Middle Initial</dt><dd class="col-sm-8"><?php echo e(parishionerValue($parishioner['middle_initial'] ?? '')); ?></dd>
                                                            <dt class="col-sm-4">Birthdate</dt><dd class="col-sm-8"><?php echo e(parishionerDateValue($parishioner['birthdate'] ?? '')); ?></dd>
                                                            <dt class="col-sm-4">Birthplace</dt><dd class="col-sm-8"><?php echo e(parishionerValue($parishioner['birth_place'] ?? '')); ?></dd>
                                                            <dt class="col-sm-4">Gender/Sex</dt><dd class="col-sm-8"><?php echo e(parishionerValue($parishioner['sex'] ?? '')); ?></dd>
                                                            <dt class="col-sm-4">Nationality</dt><dd class="col-sm-8"><?php echo e(parishionerValue($parishioner['nationality'] ?? '')); ?></dd>
                                                        </dl>
                                                    </div>

                                                    <div class="col-lg-6">
                                                        <h6 class="fw-bold border-bottom pb-2 mb-3">Contact Information</h6>
                                                        <dl class="row mb-0">
                                                            <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?php echo e(parishionerValue($parishioner['email'] ?? '')); ?></dd>
                                                            <dt class="col-sm-4">Phone</dt><dd class="col-sm-8"><?php echo e(parishionerValue($parishioner['phone_number'] ?? '')); ?></dd>
                                                            <dt class="col-sm-4">Address</dt><dd class="col-sm-8"><?php echo e(parishionerValue($parishioner['address'] ?? '')); ?></dd>
                                                            <dt class="col-sm-4">Chapel/District</dt><dd class="col-sm-8"><?php echo e(parishionerValue($parishioner['chapel_district'] ?? '')); ?></dd>
                                                        </dl>
                                                    </div>

                                                    <div class="col-lg-6">
                                                        <h6 class="fw-bold border-bottom pb-2 mb-3">Sacramental Information</h6>
                                                        <p class="text-muted mb-0">No sacramental fields are collected directly during account registration in the current users table.</p>
                                                    </div>

                                                    <div class="col-lg-6">
                                                        <h6 class="fw-bold border-bottom pb-2 mb-3">Account &amp; Verification Details</h6>
                                                        <dl class="row mb-0">
                                                            <dt class="col-sm-4">Date Registered</dt>
                                                            <dd class="col-sm-8"><?php echo e(parishionerDateTimeValue($parishioner['created_at'] ?? '')); ?></dd>

                                                            <dt class="col-sm-4">Verification Status</dt>
                                                            <dd class="col-sm-8">
                                                                <span class="badge bg-<?php echo e(getUserStatusBadgeClass($parishioner['status'])); ?>"><?php echo e(getUserStatusLabel($parishioner['status'])); ?></span>
                                                            </dd>

                                                            <dt class="col-sm-4">Date Verified</dt>
                                                            <dd class="col-sm-8">
                                                                <?php 
                                                                    if (in_array($parishioner['status'], ['active', 'approved'], true)) {
                                                                        $v_time = !empty($parishioner['verified_at']) ? $parishioner['verified_at'] : ($parishioner['updated_at'] ?? $parishioner['created_at']);
                                                                        echo '<span class="fw-semibold">' . e(date('M d, Y h:i A', strtotime($v_time))) . '</span>';
                                                                    } elseif ($parishioner['status'] === 'pending_verification') {
                                                                        echo '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pending Verification</span>';
                                                                    } elseif ($parishioner['status'] === 'rejected') {
                                                                        echo '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Rejected</span>';
                                                                    } else {
                                                                        echo e(ucfirst(str_replace('_', ' ', (string) $parishioner['status'])));
                                                                    }
                                                                ?>
                                                            </dd>

                                                            <dt class="col-sm-4"><?php echo ($parishioner['verification_method'] ?? '') === 'mobile' ? 'Registered Mobile' : 'Registered Email'; ?></dt>
                                                            <dd class="col-sm-8">
                                                                <span class="fw-semibold"><?php echo e(($parishioner['verification_method'] ?? '') === 'mobile' ? ($parishioner['phone_number'] ?: $parishioner['email'] ?: 'Not provided') : ($parishioner['email'] ?: $parishioner['phone_number'] ?: 'Not provided')); ?></span>
                                                                <?php if (in_array($parishioner['status'], ['active', 'approved'], true) || !empty($parishioner['email_verified_at']) || !empty($parishioner['phone_verified_at'])): ?>
                                                                    <span class="badge bg-success ms-1"><i class="fas fa-check-circle"></i> Verified</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-warning text-dark ms-1"><i class="fas fa-clock"></i> Pending</span>
                                                                <?php endif; ?>
                                                            </dd>

                                                            <dt class="col-sm-4">Face Status</dt>
                                                            <dd class="col-sm-8"><?php echo e(parishionerValue($parishioner['face_verification_status'] ?? '')); ?></dd>

                                                            <?php if (!empty($parishioner['rejection_reason'])): ?>
                                                                <dt class="col-sm-4">Rejection Reason</dt>
                                                                <dd class="col-sm-8 text-danger"><?php echo e($parishioner['rejection_reason']); ?></dd>
                                                            <?php endif; ?>
                                                        </dl>
                                                    </div>

                                                    <div class="col-12">
                                                        <h6 class="fw-bold border-bottom pb-2 mb-3">Submitted Documents</h6>
                                                        <div class="row g-3">
                                                            <?php if ($front_id_url): ?>
                                                                <div class="col-md-4">
                                                                    <a href="<?php echo e($front_id_url); ?>" target="_blank" class="d-block text-decoration-none">
                                                                        <img src="<?php echo e($front_id_url); ?>" class="img-thumbnail mb-2" alt="Valid ID front" style="height: 160px; width: 100%; object-fit: cover;" onerror="this.onerror=null; this.src='../assets/img/document-placeholder.svg';">
                                                                        <span class="btn btn-sm btn-outline-primary w-100">Open Valid ID Front</span>
                                                                    </a>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($back_id_url): ?>
                                                                <div class="col-md-4">
                                                                    <a href="<?php echo e($back_id_url); ?>" target="_blank" class="d-block text-decoration-none">
                                                                        <img src="<?php echo e($back_id_url); ?>" class="img-thumbnail mb-2" alt="Valid ID back" style="height: 160px; width: 100%; object-fit: cover;" onerror="this.onerror=null; this.src='../assets/img/document-placeholder.svg';">
                                                                        <span class="btn btn-sm btn-outline-primary w-100">Open Valid ID Back</span>
                                                                    </a>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($face_url): ?>
                                                                <div class="col-md-4">
                                                                    <a href="<?php echo e($face_url); ?>" target="_blank" class="d-block text-decoration-none">
                                                                        <img src="<?php echo e($face_url); ?>" class="img-thumbnail mb-2" alt="Face verification image" style="height: 160px; width: 100%; object-fit: cover;" onerror="this.onerror=null; this.src='../assets/img/document-placeholder.svg';">
                                                                        <span class="btn btn-sm btn-outline-primary w-100">Open Face Image</span>
                                                                    </a>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (!$front_id_url && !$back_id_url && !$face_url): ?>
                                                                <div class="col-12">
                                                                    <div class="alert alert-info mb-0">No submitted documents found for this parishioner.</div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
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
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info">No parishioners found</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
