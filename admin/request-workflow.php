<?php
/**
 * Admin Request Workflow - Reviews requirements, verifies payments, and releases files.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('requests.manage');
ensureRequestDocumentsSchema($conn);
ensureRequestPaymentsSchema($conn);
ensureEmailNotificationSchema($conn);

$request_id = intval($_GET['id'] ?? $_POST['request_id'] ?? 0);
if ($request_id <= 0) {
    redirect('manage-requests.php');
}

$error = '';
$success = '';

$stmt = $conn->prepare("
    SELECT r.*, u.fullname, u.email, u.phone_number
    FROM requests r
    JOIN users u ON u.id = r.user_id
    WHERE r.request_id = ?
    LIMIT 1
");
if (!$stmt) {
    redirect('manage-requests.php');
}
$stmt->bind_param('i', $request_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    redirect('manage-requests.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $status = $_POST['status'] ?? '';
        $admin_response = trim($_POST['admin_response'] ?? '');
        $allowed_statuses = ['pending', 'approved', 'processing', 'completed', 'rejected'];

        $requirement_count = requestDocumentCount($conn, $request_id, 'requirement');
        $released_count = requestDocumentCount($conn, $request_id, 'released_certificate') + requestDocumentCount($conn, $request_id, 'admin_file');
        $current_payment_summary = getRequestPaymentSummary($conn, $request_id);

        if (!in_array($status, $allowed_statuses, true)) {
            $error = 'Invalid request status.';
        } elseif (in_array($status, ['approved', 'processing', 'completed'], true) && $requirement_count <= 0) {
            $error = 'This request cannot move forward until at least one supporting requirement is attached.';
        } elseif ($status === 'completed' && $released_count <= 0) {
            $error = 'Upload a released certificate or parish office file before marking this request completed.';
        } elseif ($status === 'completed' && intval($current_payment_summary['total']) > 0 && intval($current_payment_summary['verified']) <= 0) {
            $error = 'A submitted payment receipt must be verified before marking this request completed.';
        } elseif ($status === 'approved' && requestApprovalConflict($conn, $request_id)['conflict']) {
            $error = requestApprovalConflict($conn, $request_id)['message'] . ' Please choose another schedule before approving.';
        } else {
            $stmt = $conn->prepare("UPDATE requests SET status = ?, admin_response = ? WHERE request_id = ?");
            if ($stmt) {
                $stmt->bind_param('ssi', $status, $admin_response, $request_id);
                if ($stmt->execute()) {
                    createAuditLog($conn, $_SESSION['user_id'], 'UPDATE_REQUEST_STATUS', 'requests', $request_id);
                    createRequestStatusNotification($conn, $request, $status, $admin_response);
                    if ($status === 'approved') {
                        syncApprovedRequestToCalendar($conn, $request_id, $_SESSION['user_id']);
                    }
                    $request['status'] = $status;
                    $request['admin_response'] = $admin_response;
                    $success = 'Request status updated.';
                } else {
                    $error = 'Unable to update request status.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare request update.';
            }
        }
    } elseif ($action === 'verify_payment') {
        $payment_id = intval($_POST['payment_id'] ?? 0);
        $status = $_POST['payment_status'] ?? '';
        $admin_remarks = trim($_POST['admin_remarks'] ?? '');

        if (!in_array($status, ['verified', 'rejected', 'pending'], true)) {
            $error = 'Invalid payment status.';
        } else {
            $stmt = $conn->prepare("UPDATE request_payments SET status = ?, admin_remarks = ?, verified_by = ?, verified_at = NOW() WHERE payment_id = ? AND request_id = ?");
            if ($stmt) {
                $admin_id = intval($_SESSION['user_id']);
                $stmt->bind_param('ssiii', $status, $admin_remarks, $admin_id, $payment_id, $request_id);
                if ($stmt->execute() && $stmt->affected_rows >= 0) {
                    createAuditLog($conn, $_SESSION['user_id'], 'VERIFY_PAYMENT', 'request_payments', $payment_id);
                    createNotification($conn, $request['user_id'], 'Payment Receipt Reviewed', 'Your payment receipt for request ' . $request['reference_number'] . ' is now ' . ucfirst($status) . '.');
                    $success = 'Payment status updated.';
                } else {
                    $error = 'Unable to update payment status.';
                }
                $stmt->close();
            } else {
                $error = 'Unable to prepare payment update.';
            }
        }
    } elseif ($action === 'upload_release') {
        $document = saveRequestDocument($conn, $request_id, $_SESSION['user_id'], $_FILES['release_file'] ?? null, 'released_certificate');

        if (!$document['ok'] || empty($document['saved'])) {
            $error = $document['error'] ?? 'Please choose a file to release.';
        } else {
            createAuditLog($conn, $_SESSION['user_id'], 'UPLOAD_REQUEST_FILE', 'request_documents', $document['document_id']);
            createNotification($conn, $request['user_id'], 'Parish File Available', 'A parish office file was added to request ' . $request['reference_number'] . '.');

            if (!empty($_POST['mark_completed'])) {
                $stmt = $conn->prepare("UPDATE requests SET status = 'completed' WHERE request_id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $request_id);
                    $stmt->execute();
                    $stmt->close();
                    $request['status'] = 'completed';
                }
            }
            $success = 'File released to parishioner.';
        }
    }
}

$documents = [];
$stmt = $conn->prepare("SELECT * FROM request_documents WHERE request_id = ? AND deleted_at IS NULL ORDER BY uploaded_at DESC");
if ($stmt) {
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
    $stmt->close();
}

$documents_by_type = ['requirement' => [], 'payment_receipt' => [], 'admin_file' => [], 'released_certificate' => []];
foreach ($documents as $document) {
    $type = $document['document_type'] ?: 'requirement';
    if (!isset($documents_by_type[$type])) {
        $documents_by_type[$type] = [];
    }
    $documents_by_type[$type][] = $document;
}

if (!empty($documents_by_type['requirement'])) {
    $requirement_order = [
        'Chapel Recommendation' => 1,
        'Latest Marriage Contract (if parents are married)' => 2,
        'Latest Marriage Certificate / Marriage Contract Receipt' => 3,
        'Photocopy of Marriage Certificate (if married)' => 4,
        'Photocopy of Live Birth Certificate with Official Registry Number' => 5,
        'Two (2) White Cards of Sponsors (Ninong and Ninang)' => 6,
        'White Cards of Parents' => 7,
    ];
    usort($documents_by_type['requirement'], function ($left, $right) use ($requirement_order) {
        $left_name = trim((string) ($left['requirement_name'] ?? ''));
        $right_name = trim((string) ($right['requirement_name'] ?? ''));
        $left_order = $requirement_order[$left_name] ?? 999;
        $right_order = $requirement_order[$right_name] ?? 999;
        if ($left_order !== $right_order) {
            return $left_order <=> $right_order;
        }
        return strcmp($left_name ?: (string) $left['original_name'], $right_name ?: (string) $right['original_name']);
    });
}

$payments = getRequestPayments($conn, $request_id);
$payment_summary = getRequestPaymentSummary($conn, $request_id);
$page_title = 'Request Workflow';
$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Manage Requests' => 'manage-requests.php',
    'Request Workflow' => null
];
?>
<?php include '../templates/header.php'; ?>

<style>
.requirement-review-list .min-w-0 {
    min-width: 0;
}

.requirement-review-list strong {
    color: #172033;
    overflow-wrap: anywhere;
}

.requirement-review-list .btn {
    min-width: 82px;
}
</style>

<div class="container-fluid px-0">
    <!-- Standardized Section Header -->
    <?php
    $page_header_title = 'Request Workflow';
    $page_header_subtitle = 'Process document review, verify payment receipts, and release certificates for ' . e($request['reference_number']);
    $page_header_icon = 'fa-route';
    $show_back_button = true;
    $back_button_url = 'manage-requests.php';
    include '../includes/page_header.php';
    ?>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo e($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo e($success); ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-route"></i> Request <?php echo e($request['reference_number']); ?></h5>
                    <span class="badge bg-<?php echo getStatusBadgeClass($request['status']); ?>"><?php echo e(ucfirst($request['status'])); ?></span>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Parishioner</div>
                            <strong><?php echo e($request['fullname']); ?></strong>
                            <div class="small"><?php echo e($request['email']); ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Type</div>
                            <strong><?php echo e(ucfirst(str_replace('_', ' ', $request['request_type']))); ?></strong>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Requested</div>
                            <strong><?php echo e(formatDate($request['date_requested'])); ?></strong>
                        </div>
                    </div>
                    <div class="border rounded p-3 bg-light">
                        <?php echo nl2br(e($request['description'] ?: 'No description provided.')); ?>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-paperclip"></i> Parishioner Requirements</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($documents_by_type['requirement'])): ?>
                        <div class="text-muted">No requirement files submitted.</div>
                    <?php else: ?>
                        <div class="list-group requirement-review-list">
                            <?php foreach ($documents_by_type['requirement'] as $document): ?>
                                <?php $requirement_label = trim((string) ($document['requirement_name'] ?? '')); ?>
                                <div class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-3">
                                    <div class="min-w-0">
                                        <strong><?php echo e($requirement_label !== '' ? $requirement_label : $document['original_name']); ?></strong>
                                        <div class="text-muted small">
                                            <i class="fas fa-file"></i>
                                            <?php echo e($document['original_name']); ?>
                                            <span class="mx-1">&bull;</span>
                                            <?php echo e(formatFileSize($document['file_size'])); ?>
                                        </div>
                                    </div>
                                    <a class="btn btn-sm btn-outline-primary" href="../request-document.php?id=<?php echo intval($document['document_id']); ?>" target="_blank" rel="noopener">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-receipt"></i> Payment Receipts</h5>
                    <span class="badge bg-success">Verified PHP <?php echo number_format($payment_summary['verified_amount'], 2); ?></span>
                </div>
                <div class="card-body">
                    <?php if (empty($payments)): ?>
                        <div class="text-muted">No payment receipts submitted.</div>
                    <?php else: ?>
                        <?php foreach ($payments as $payment): ?>
                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex justify-content-between gap-3">
                                    <div>
                                        <strong>PHP <?php echo number_format(floatval($payment['amount']), 2); ?></strong>
                                        <div class="small text-muted">
                                            <?php echo e(ucfirst($payment['payment_method'])); ?>
                                            <?php if (!empty($payment['reference_number'])): ?>
                                                | Ref: <?php echo e($payment['reference_number']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($payment['receipt_document_id'])): ?>
                                            <a href="../request-document.php?id=<?php echo intval($payment['receipt_document_id']); ?>" target="_blank">View receipt</a>
                                        <?php endif; ?>
                                    </div>
                                    <?php
                                    $badge = ['pending' => 'warning', 'verified' => 'success', 'rejected' => 'danger'][$payment['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo e($badge); ?> align-self-start"><?php echo e(ucfirst($payment['status'])); ?></span>
                                </div>
                                <form method="POST" class="row g-2 mt-3">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="action" value="verify_payment">
                                    <input type="hidden" name="request_id" value="<?php echo intval($request_id); ?>">
                                    <input type="hidden" name="payment_id" value="<?php echo intval($payment['payment_id']); ?>">
                                    <div class="col-md-3">
                                        <select class="form-select" name="payment_status" required>
                                            <option value="pending" <?php echo $payment['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="verified" <?php echo $payment['status'] === 'verified' ? 'selected' : ''; ?>>Verified</option>
                                            <option value="rejected" <?php echo $payment['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        </select>
                                    </div>
                                    <div class="col-md-7">
                                        <input type="text" class="form-control" name="admin_remarks" value="<?php echo e($payment['admin_remarks'] ?? ''); ?>" placeholder="Admin remarks">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-primary w-100">Save</button>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-file-circle-check"></i> Certificates Released to Parishioner</h5>
                </div>
                <div class="card-body">
                    <?php $released_files = array_merge($documents_by_type['released_certificate'], $documents_by_type['admin_file']); ?>
                    <?php if (empty($released_files)): ?>
                        <div class="text-muted">No certificates released yet.</div>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($released_files as $document): ?>
                                <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="../request-document.php?id=<?php echo intval($document['document_id']); ?>" target="_blank">
                                    <span><i class="fas fa-file-arrow-down"></i> <?php echo e($document['original_name']); ?></span>
                                    <small><?php echo e(formatFileSize($document['file_size'])); ?></small>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-clipboard-check"></i> Review Status</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="request_id" value="<?php echo intval($request_id); ?>">
                        <div class="mb-3">
                            <label class="form-label" for="status">Status</label>
                            <select class="form-select" id="status" name="status" required>
                                <?php foreach (['pending', 'approved', 'processing', 'completed', 'rejected'] as $status): ?>
                                    <option value="<?php echo e($status); ?>" <?php echo $request['status'] === $status ? 'selected' : ''; ?>><?php echo e(ucfirst($status)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="admin_response">Admin Response</label>
                            <textarea class="form-control" id="admin_response" name="admin_response" rows="4"><?php echo e($request['admin_response'] ?? ''); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Update Request</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-upload"></i> Release Certificate</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="upload_release">
                        <input type="hidden" name="request_id" value="<?php echo intval($request_id); ?>">
                        <div class="mb-3">
                            <label class="form-label" for="release_file">File</label>
                            <input type="file" class="form-control" id="release_file" name="release_file" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain" required>
                        </div>
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="mark_completed" name="mark_completed" value="1">
                            <label class="form-check-label" for="mark_completed">Mark request completed after upload</label>
                        </div>
                        <button type="submit" class="btn btn-success w-100">Release to Parishioner</button>
                    </form>
                </div>
            
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
