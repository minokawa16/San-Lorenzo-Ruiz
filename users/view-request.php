<?php
/**
 * Request Detail Module - Shows the status, remarks, and timeline for a single user request.
 */
include '../includes/session.php';
include '../config/security.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();
if (!hasPermission('requests.view_own')) {
    redirect('../auth/login.php');
}

$user_id = $_SESSION['user_id'];
$request_id = intval($_GET['id'] ?? 0);

$stmt = $conn->prepare("SELECT r.*, u.fullname as user_name FROM requests r JOIN users u ON r.user_id = u.id WHERE r.request_id = ? AND r.user_id = ?");
if (!$stmt) {
    redirect('my-requests.php');
}

$stmt->bind_param('ii', $request_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows == 0) {
    $stmt->close();
    redirect('my-requests.php');
}

$request = $result->fetch_assoc();
$stmt->close();

ensureRequestDocumentsSchema($conn);
ensureRequestPaymentsSchema($conn);
$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'submit_payment') {
    requireValidCsrfToken();
    if (!in_array($request['status'], ['approved', 'processing'], true)) {
        $error = 'Payment receipts can be submitted after the parish office approves or starts processing the request.';
    } else {
        $payment = createRequestPayment(
            $conn,
            $request_id,
            $user_id,
            $_POST['amount'] ?? 0,
            $_POST['payment_method'] ?? '',
            $_POST['reference_number'] ?? '',
            $_POST['notes'] ?? '',
            $_FILES['receipt_file'] ?? null
        );

        if ($payment['ok']) {
            createNotification($conn, $user_id, 'Payment Receipt Submitted', 'Your receipt was submitted for request ' . $request['reference_number'] . '.');
            $success = 'Payment receipt submitted for admin verification.';
        } else {
            $error = $payment['error'];
        }
    }
}

$documents = [];
$stmt = $conn->prepare("SELECT document_id, document_type, original_name, mime_type, file_size, uploaded_at FROM request_documents WHERE request_id = ? AND deleted_at IS NULL ORDER BY uploaded_at DESC");
if ($stmt) {
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $doc_result = $stmt->get_result();
    while ($row = $doc_result->fetch_assoc()) {
        $documents[] = $row;
    }
    $stmt->close();
}
$documents_by_type = [
    'requirement' => [],
    'payment_receipt' => [],
    'admin_file' => [],
    'released_certificate' => [],
];
foreach ($documents as $document) {
    $type = $document['document_type'] ?: 'requirement';
    if (!isset($documents_by_type[$type])) {
        $documents_by_type[$type] = [];
    }
    $documents_by_type[$type][] = $document;
}
$payments = getRequestPayments($conn, $request_id);
$can_submit_payment = in_array($request['status'], ['approved', 'processing'], true);
$payment_qr_asset = '../assets/img/payment-qr.png';
$payment_qr_file = __DIR__ . '/../assets/img/payment-qr.png';
$payment_qr_url = file_exists($payment_qr_file) ? $payment_qr_asset . '?v=' . filemtime($payment_qr_file) : '';
$page_title = 'View Request';
?>
<?php include '../templates/header.php'; ?>

<style>
    .payment-guide {
        display: grid;
        grid-template-columns: minmax(180px, 240px) 1fr;
        gap: 18px;
        align-items: start;
        margin-bottom: 18px;
        padding: 16px;
        border: 1px solid rgba(23, 32, 51, 0.1);
        border-radius: 8px;
        background: linear-gradient(135deg, #ffffff, #fff8df 58%, #eef5fb);
    }

    .payment-qr-frame {
        display: grid;
        place-items: center;
        min-height: 250px;
        padding: 14px;
        border: 1px solid rgba(23, 32, 51, 0.12);
        border-radius: 8px;
        background: #ffffff;
        text-align: center;
    }

    .payment-qr-frame img {
        width: min(100%, 210px);
        height: auto;
        display: block;
    }

    .payment-qr-empty {
        color: #64748b;
        font-size: 0.9rem;
    }

    .payment-qr-empty i {
        display: block;
        margin-bottom: 10px;
        color: #17446a;
        font-size: 2rem;
    }

    .payment-guide h6 {
        margin-bottom: 8px;
        color: #172033;
        font-weight: 900;
    }

    .payment-guide-list {
        margin: 0;
        padding-left: 18px;
        color: #475569;
    }

    .payment-account {
        display: grid;
        gap: 6px;
        margin-top: 12px;
        padding: 12px;
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.74);
        color: #172033;
    }

    .payment-account span {
        color: #64748b;
        font-size: 0.86rem;
    }

    @media (max-width: 768px) {
        .payment-guide {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-file-alt"></i> Request Details</h5>
                        <span class="badge bg-<?php echo getStatusBadgeClass($request['status']); ?>">
                            <?php echo e(ucfirst($request['status'])); ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo e($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo e($success); ?></div>
                    <?php endif; ?>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Reference Number</h6>
                            <p class="lead"><?php echo e($request['reference_number']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Request Type</h6>
                            <p class="lead"><?php echo e(ucfirst(str_replace('_', ' ', $request['request_type']))); ?></p>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Date Requested</h6>
                            <p><?php echo formatDate($request['date_requested']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Last Updated</h6>
                            <p><?php echo formatDate($request['updated_at']); ?></p>
                        </div>
                    </div>

                    <div class="mb-4">
                        <h6 class="text-muted mb-2">Description</h6>
                        <p><?php echo sanitize($request['description'] ?? 'No description provided'); ?></p>
                    </div>

                    <?php if (!empty($documents_by_type['requirement'])): ?>
                        <div class="mb-4">
                            <h6 class="text-muted mb-2">Submitted Requirements</h6>
                            <div class="list-group">
                                <?php foreach ($documents_by_type['requirement'] as $document): ?>
                                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="../request-document.php?id=<?php echo intval($document['document_id']); ?>" target="_blank">
                                        <span>
                                            <i class="fas fa-paperclip"></i>
                                            <?php echo e($document['original_name']); ?>
                                        </span>
                                        <small class="text-muted"><?php echo e(formatFileSize($document['file_size'])); ?></small>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($payments)): ?>
                        <div class="mb-4">
                            <h6 class="text-muted mb-2">Payment Receipts</h6>
                            <div class="list-group">
                                <?php foreach ($payments as $payment): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <strong><?php echo e(ucfirst($payment['payment_method'])); ?></strong>
                                                <div class="small text-muted">
                                                    Amount: PHP <?php echo number_format(floatval($payment['amount']), 2); ?>
                                                    <?php if (!empty($payment['reference_number'])): ?>
                                                        | Ref: <?php echo e($payment['reference_number']); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($payment['admin_remarks'])): ?>
                                                    <div class="small mt-1">Admin note: <?php echo e($payment['admin_remarks']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-end">
                                                <?php
                                                $payment_badge = ['pending' => 'warning', 'verified' => 'success', 'rejected' => 'danger'][$payment['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo e($payment_badge); ?>"><?php echo e(ucfirst($payment['status'])); ?></span>
                                                <?php if (!empty($payment['receipt_document_id'])): ?>
                                                    <a class="btn btn-sm btn-outline-primary d-block mt-2" href="../request-document.php?id=<?php echo intval($payment['receipt_document_id']); ?>" target="_blank">
                                                        <i class="fas fa-receipt"></i> View Receipt
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($can_submit_payment): ?>
                        <div class="card border mb-4">
                            <div class="card-body">
                                <h6 class="mb-3"><i class="fas fa-receipt"></i> Submit Payment Receipt</h6>
                                <div class="payment-guide">
                                    <div class="payment-qr-frame">
                                        <?php if ($payment_qr_url !== ''): ?>
                                            <img src="<?php echo e($payment_qr_url); ?>" alt="Payment QR code">
                                        <?php else: ?>
                                            <div class="payment-qr-empty">
                                                <i class="fas fa-qrcode"></i>
                                                Payment QR image will appear here once configured.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h6><i class="fas fa-mobile-screen-button"></i> Scan QR for Payment</h6>
                                        <ul class="payment-guide-list">
                                            <li>Scan the QR code using your banking or wallet app.</li>
                                            <li>Use this request reference as the payment basis: <strong><?php echo e($request['reference_number']); ?></strong>.</li>
                                            <li>After paying, upload the receipt or proof of payment below for admin verification.</li>
                                            <li>Transfer fees may apply.</li>
                                        </ul>
                                        <div class="payment-account">
                                            <strong>R** MA*K C.</strong>
                                            <span>Mobile No.: +63 963 586 ....</span>
                                            <span>User ID: ********ONH82Q</span>
                                        </div>
                                    </div>
                                </div>
                                <form method="POST" enctype="multipart/form-data" class="row g-3">
                                    <input type="hidden" name="action" value="submit_payment">
                                    <div class="col-md-4">
                                        <label class="form-label" for="amount">Amount</label>
                                        <input type="number" class="form-control" id="amount" name="amount" min="1" step="0.01" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="payment_method">Method</label>
                                        <select class="form-select" id="payment_method" name="payment_method" required>
                                            <option value="">Choose method</option>
                                            <option value="cash">Cash</option>
                                            <option value="gcash">GCash</option>
                                            <option value="bank_transfer">Bank Transfer</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="reference_number">Reference Number</label>
                                        <input type="text" class="form-control" id="reference_number" name="reference_number">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="receipt_file">Receipt / Proof of Payment</label>
                                        <input type="file" class="form-control" id="receipt_file" name="receipt_file" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="notes">Notes</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-upload"></i> Submit Receipt
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($documents_by_type['admin_file']) || !empty($documents_by_type['released_certificate'])): ?>
                        <div class="mb-4">
                            <h6 class="text-muted mb-2">Files from Parish Office</h6>
                            <div class="list-group">
                                <?php foreach (array_merge($documents_by_type['released_certificate'], $documents_by_type['admin_file']) as $document): ?>
                                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="../request-document.php?id=<?php echo intval($document['document_id']); ?>" target="_blank">
                                        <span>
                                            <i class="fas fa-file-circle-check"></i>
                                            <?php echo e($document['original_name']); ?>
                                        </span>
                                        <small class="text-muted"><?php echo e(formatFileSize($document['file_size'])); ?></small>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($request['admin_response'])): ?>
                        <div class="alert alert-info">
                            <h6><i class="fas fa-comment"></i> Admin Response</h6>
                            <p class="mb-0"><?php echo sanitize($request['admin_response']); ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="d-grid gap-2 d-md-flex justify-content-md-between">
                        <a href="my-requests.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Requests
                        </a>
                        <?php if ($request['status'] == 'completed'): ?>
                            <button class="btn btn-primary" onclick="window.print()">
                                <i class="fas fa-print"></i> Print/Save as PDF
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../templates/footer.php'; ?>
