<?php
/**
 * Registration Verification Module - Reviews pending parishioner accounts and identity submissions.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('registrations.verify');
ensureUserVerificationSchema($conn);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);

    $stmt = $conn->prepare("SELECT id, fullname, status FROM users WHERE id = ? AND role = 'user' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $target_user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $target_user = null;
    }

    if (!$target_user) {
        $error = 'User registration not found.';
    } elseif ($target_user['status'] !== 'pending_verification') {
        $error = 'Only pending registrations can be reviewed.';
    } elseif ($action === 'approve') {
        $admin_id = (int) $_SESSION['user_id'];
        if (transitionAccountStatus($conn, $user_id, 'active', 'approved', null, $admin_id)) {
            createNotification($conn, $user_id, 'Account Approved', 'Your account has been approved. You may now log in to the Parish Management System.');
            createAuditLog($conn, $admin_id, 'APPROVE_REGISTRATION', 'users', $user_id);
            $success = 'Registration approved successfully.';
        } else {
            $error = 'Unable to approve registration.';
        }
    } elseif ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if (mb_strlen($reason) < 10) {
            $error = 'Please add a meaningful rejection reason of at least 10 characters.';
        } elseif (mb_strlen($reason) > 1000) {
            $error = 'Rejection reason is too long. Please keep it under 1000 characters.';
        } else {
            $admin_id = (int) $_SESSION['user_id'];
            if (transitionAccountStatus($conn, $user_id, 'rejected', 'rejected', $reason, $admin_id)) {
                createNotification($conn, $user_id, 'Registration Not Approved', 'Your registration was not approved by the parish administrator. Reason: ' . $reason);
                createAuditLog($conn, $admin_id, 'REJECT_REGISTRATION', 'users', $user_id, null, ['reason' => $reason]);
                $success = 'Registration rejected successfully.';
            } else {
                $error = 'Unable to reject registration.';
            }
        }
    }
}

$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'pending_verification';
$allowed_statuses = ['pending_verification', 'active', 'rejected'];
if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = 'pending_verification';
}

$where = "WHERE role = 'user' AND status = ?";
$params = [$status_filter];
$types = 's';

if ($search !== '') {
    $where .= " AND (fullname LIKE ? OR email LIKE ? OR chapel_district LIKE ? OR address LIKE ?)";
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
    $types .= 'ssss';
}

$sql = "SELECT id, fullname, phone_number, email, chapel_district, address, birthdate, id_number_encrypted, status, valid_id_path, valid_id_back_path, valid_id_original_name, face_image_path, face_verification_status, rejection_reason, created_at
        FROM users
        $where
        ORDER BY created_at DESC";
$stmt = $conn->prepare($sql);
$registrations = [];
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $registrations[] = $row;
    }
    $stmt->close();
}

$pending_result = $conn->query("SELECT COUNT(*) AS count FROM users WHERE role = 'user' AND status = 'pending_verification'");
$pending_count = $pending_result ? intval($pending_result->fetch_assoc()['count'] ?? 0) : 0;

$page_title = 'Registration Verification';
?>
<?php include '../templates/header.php'; ?>

<div class="container-fluid mt-4 verification-page">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
        <div>
            <button type="button" class="btn btn-outline-secondary btn-sm mb-3" onclick="history.back()">
                <i class="fas fa-arrow-left"></i> Go Back
            </button>
            <h1 class="mb-1"><i class="fas fa-user-shield"></i> Registration Verification</h1>
            <p class="text-muted mb-0">Review uploaded IDs and approve verified Aleosan parishioners.</p>
        </div>
        <span class="badge bg-warning text-dark fs-6"><?php echo $pending_count; ?> pending</span>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-circle-exclamation"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-circle-check"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-7">
                    <label class="form-label">Search registrations</label>
                    <input type="text" class="form-control" name="search" value="<?php echo e($search); ?>" placeholder="Search by name, Gmail, chapel, or address">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="pending_verification" <?php echo $status_filter === 'pending_verification' ? 'selected' : ''; ?>>Pending Verification</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Filter</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($registrations)): ?>
        <div class="alert alert-info">No registrations found for this filter.</div>
    <?php else: ?>
        <div class="verification-grid">
            <?php foreach ($registrations as $user): ?>
                <?php
                    $id_src = !empty($user['valid_id_path']) ? 'view-valid-id.php?id=' . intval($user['id']) : '';
                    $id_back_src = !empty($user['valid_id_back_path']) ? 'view-valid-id.php?type=back&id=' . intval($user['id']) : '';
                    $face_src = !empty($user['face_image_path']) ? 'view-valid-id.php?type=face&id=' . intval($user['id']) : '';
                    $status_class = getUserStatusBadgeClass($user['status']);
                    $id_number_display = decryptSensitiveValue($user['id_number_encrypted'] ?? '');
                ?>
                <article class="verification-card">
                    <div class="verification-record">
                        <div class="verification-info-panel">
                            <div class="verification-card-header">
                                <div class="verification-avatar"><?php echo strtoupper(substr($user['fullname'], 0, 1)); ?></div>
                                <div>
                                    <span class="record-label">Parishioner Registration</span>
                                    <h3><?php echo e($user['fullname']); ?></h3>
                                    <span class="badge bg-<?php echo e($status_class); ?>"><?php echo e(getUserStatusLabel($user['status'])); ?></span>
                                </div>
                            </div>

                            <div class="verification-details">
                                <div class="detail-item">
                                    <span>Email Address</span>
                                    <strong><i class="fas fa-envelope"></i> <?php echo e($user['email']); ?></strong>
                                </div>
                                <div class="detail-item">
                                    <span>Phone Number</span>
                                    <strong><i class="fas fa-phone"></i> <?php echo e($user['phone_number']); ?></strong>
                                </div>
                                <div class="detail-item">
                                    <span>Chapel / District</span>
                                    <strong><i class="fas fa-location-dot"></i> <?php echo e($user['chapel_district'] ?: 'No chapel listed'); ?></strong>
                                </div>
                                <div class="detail-item">
                                    <span>Birthdate</span>
                                    <strong><i class="fas fa-calendar-day"></i> <?php echo e($user['birthdate'] ? formatDate($user['birthdate']) : 'No birthdate listed'); ?></strong>
                                </div>
                                <div class="detail-item">
                                    <span>ID Number</span>
                                    <strong><i class="fas fa-fingerprint"></i> <?php echo e($id_number_display ?: 'Encrypted'); ?></strong>
                                </div>
                                <div class="detail-item detail-item-wide">
                                    <span>Complete Address</span>
                                    <strong><i class="fas fa-map"></i> <?php echo e($user['address'] ?: 'No address listed'); ?></strong>
                                </div>
                                <div class="detail-item">
                                    <span>Date Registered</span>
                                    <strong><i class="fas fa-calendar"></i> <?php echo e(formatDate($user['created_at'])); ?></strong>
                                </div>
                                <div class="detail-item">
                                    <span>Verification Criteria</span>
                                    <strong><i class="fas fa-shield-halved"></i> Manual admin review</strong>
                                </div>
                                <div class="detail-item">
                                    <span>Face Verification</span>
                                    <strong><i class="fas fa-user-check"></i> <?php echo e(ucfirst(str_replace('_', ' ', $user['face_verification_status'] ?: 'pending'))); ?></strong>
                                </div>
                            </div>
                        </div>

                        <div class="verification-id-panel">
                            <div class="id-panel-header">
                                <div>
                                    <span class="record-label">Uploaded Verification ID</span>
                                    <h4>Valid ID Preview</h4>
                                </div>
                                <i class="fas fa-id-card"></i>
                            </div>

                            <div class="id-preview-pair">
                                <?php if ($id_src): ?>
                                    <button class="verification-id-preview" type="button" data-bs-toggle="modal" data-bs-target="#idPreviewModal" data-id-src="<?php echo e($id_src); ?>" data-id-name="<?php echo e($user['fullname']); ?>" data-preview-title="Front Valid ID">
                                        <img src="<?php echo e($id_src); ?>" alt="Front valid ID uploaded by <?php echo e($user['fullname']); ?>" onerror="this.onerror=null; this.src='../assets/img/document-placeholder.svg';">
                                        <span><i class="fas fa-id-card"></i> Front ID</span>
                                    </button>
                                <?php else: ?>
                                    <div class="id-preview-missing">No front ID found.</div>
                                <?php endif; ?>

                                <?php if ($id_back_src): ?>
                                    <button class="verification-id-preview" type="button" data-bs-toggle="modal" data-bs-target="#idPreviewModal" data-id-src="<?php echo e($id_back_src); ?>" data-id-name="<?php echo e($user['fullname']); ?>" data-preview-title="Back Valid ID">
                                        <img src="<?php echo e($id_back_src); ?>" alt="Back valid ID uploaded by <?php echo e($user['fullname']); ?>" onerror="this.onerror=null; this.src='../assets/img/document-placeholder.svg';">
                                        <span><i class="fas fa-address-card"></i> Back ID</span>
                                    </button>
                                <?php else: ?>
                                    <div class="id-preview-missing">No back ID found.</div>
                                <?php endif; ?>
                            </div>

                            <?php if ($face_src): ?>
                                <button class="verification-id-preview face-preview mt-3" type="button" data-bs-toggle="modal" data-bs-target="#idPreviewModal" data-id-src="<?php echo e($face_src); ?>" data-id-name="<?php echo e($user['fullname']); ?>" data-preview-title="Live Face Capture">
                                    <img src="<?php echo e($face_src); ?>" alt="Live face captured for <?php echo e($user['fullname']); ?>" onerror="this.onerror=null; this.src='../assets/img/document-placeholder.svg';">
                                    <span><i class="fas fa-user-check"></i> Live face capture</span>
                                </button>
                            <?php endif; ?>



                            </div>
                        </div>
                    </div>

                    <?php if ($user['status'] === 'rejected' && !empty($user['rejection_reason'])): ?>
                        <div class="rejection-note"><strong>Reason:</strong> <?php echo e($user['rejection_reason']); ?></div>
                    <?php endif; ?>

                    <?php if ($user['status'] === 'pending_verification'): ?>
                        <div class="verification-actions">
                            <form method="POST">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="user_id" value="<?php echo intval($user['id']); ?>">
                                <button class="btn btn-success w-100" type="submit"><i class="fas fa-check"></i> Approve</button>
                            </form>
                            <button class="btn btn-outline-danger w-100" type="button" data-bs-toggle="collapse" data-bs-target="#reject-<?php echo intval($user['id']); ?>">
                                <i class="fas fa-xmark"></i> Reject
                            </button>
                        </div>
                        <div class="collapse mt-3" id="reject-<?php echo intval($user['id']); ?>">
                            <form method="POST" class="reject-form">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="user_id" value="<?php echo intval($user['id']); ?>">
                                <label class="form-label">Rejection reason</label>
                                <textarea class="form-control" name="rejection_reason" rows="3" maxlength="1000" required placeholder="Example: ID address is not from Aleosan or image is unreadable."></textarea>
                                <button class="btn btn-danger mt-2 w-100" type="submit">Confirm Rejection</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="idPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="idPreviewModalTitle">Uploaded Valid ID</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img src="" alt="Uploaded valid ID preview" id="modalIdImage" class="img-fluid rounded shadow-sm">
            </div>
        </div>
    </div>
</div>

<style>
.verification-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 18px;
}

.verification-card {
    background: #ffffff;
    border: 1px solid rgba(15, 23, 42, 0.08);
    border-radius: 18px;
    padding: 18px;
    box-shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
}

.verification-record {
    display: grid;
    grid-template-columns: minmax(360px, 0.95fr) minmax(420px, 1.35fr);
    gap: 22px;
    align-items: start;
}

.verification-info-panel,
.verification-id-panel {
    min-width: 0;
}

.verification-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
    padding-bottom: 16px;
    border-bottom: 1px solid rgba(15, 23, 42, 0.08);
}

.verification-avatar {
    width: 48px;
    height: 48px;
    border-radius: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #161314;
    font-weight: 900;
    background: linear-gradient(135deg, #A2A7E4, #E8AB9C);
}

.verification-card h3 {
    margin: 0 0 5px;
    font-size: 1.25rem;
    font-weight: 800;
    color: #161314;
}

.record-label {
    display: block;
    color: #64748b;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    margin-bottom: 3px;
}

.verification-details {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.detail-item {
    padding: 12px 13px;
    border-radius: 14px;
    background: #f8fafc;
    border: 1px solid rgba(15, 23, 42, 0.06);
}

.detail-item-wide {
    grid-column: 1 / -1;
}

.detail-item span {
    display: block;
    margin-bottom: 5px;
    color: #64748b;
    font-size: 0.74rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.detail-item strong {
    display: flex;
    gap: 9px;
    color: #1f2937;
    font-size: 0.92rem;
    line-height: 1.4;
    font-weight: 750;
}

.verification-details i {
    width: 18px;
    color: #84A4F4;
    margin-top: 2px;
    flex: 0 0 auto;
}

.id-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}

.id-panel-header h4 {
    margin: 0;
    color: #161314;
    font-size: 1rem;
    font-weight: 850;
}

.id-panel-header > i {
    color: #84A4F4;
    font-size: 1.4rem;
}

.id-preview-pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.id-preview-missing {
    min-height: 250px;
    display: grid;
    place-items: center;
    padding: 16px;
    border-radius: 16px;
    color: #92400e;
    background: #fffbeb;
    border: 1px dashed #f59e0b;
    font-weight: 800;
    text-align: center;
}

.verification-id-preview {
    width: 100%;
    border: 0;
    padding: 0;
    border-radius: 16px;
    overflow: hidden;
    background: #f8fafc;
    color: #161314;
    text-align: left;
    border: 1px solid rgba(15, 23, 42, 0.08);
    transition: transform 0.18s ease, box-shadow 0.18s ease;
}

.verification-id-preview:hover {
    transform: translateY(-2px);
    box-shadow: 0 16px 30px rgba(15, 23, 42, 0.1);
}

.verification-id-preview img {
    width: 100%;
    height: 250px;
    object-fit: contain;
    background: linear-gradient(135deg, #f8fafc, #eef2ff);
    display: block;
}

.verification-id-preview.face-preview img {
    height: 240px;
    object-fit: contain;
    object-position: center;
}

.verification-id-preview span {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    font-weight: 800;
}

.verification-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px solid rgba(15, 23, 42, 0.08);
}

.rejection-note {
    margin-top: 12px;
    padding: 10px 12px;
    border-radius: 12px;
    color: #842029;
    background: #f8d7da;
}

#modalIdImage {
    max-height: 78vh;
    object-fit: contain;
    background: #f8fafc;
}

@media (max-width: 1100px) {
    .verification-record {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 640px) {
    .verification-details {
        grid-template-columns: 1fr;
    }

    .verification-id-preview img {
        height: 240px;
    }

    .id-preview-pair {
        grid-template-columns: 1fr;
    }

    .verification-actions {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.getElementById('idPreviewModal').addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const image = document.getElementById('modalIdImage');
    const title = document.getElementById('idPreviewModalTitle');
    image.src = button.getAttribute('data-id-src');
    image.alt = 'Uploaded valid ID for ' + button.getAttribute('data-id-name');
    title.textContent = button.getAttribute('data-preview-title') || 'Uploaded Valid ID';
});
</script>

<?php include '../templates/footer.php'; ?>
