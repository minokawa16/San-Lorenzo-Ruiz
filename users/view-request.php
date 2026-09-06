<?php
/**
 * Request Detail Module - Shows the status, remarks, and timeline for a single user request.
 */
include '../includes/session.php';
include '../config/security.php';
include '../database/config.php';
include '../includes/helpers.php';
require_once '../services/ReservationService.php';

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

$csrf_err = csrfFailureMessage();
if ($csrf_err && empty($error)) {
    $error = $csrf_err;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'respond_schedule_proposal') {
    requireValidCsrfToken();
    try {
        (new ReservationService($conn))->respondToProposal((int)($_POST['proposal_id']??0),$user_id,($_POST['response']??'')==='accept');
        $success=($_POST['response']??'')==='accept'?'The proposed schedule was accepted.':'The proposed schedule was rejected.';
    } catch(Throwable $e){$error=$e->getMessage();}
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'submit_payment') {
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
            
            // Notify administrators and staff
            $admin_stmt = $conn->query("SELECT id FROM users WHERE role IN ('admin', 'staff') AND status = 'active'");
            if ($admin_stmt) {
                while ($admin_row = $admin_stmt->fetch_assoc()) {
                    createNotification($conn, (int)$admin_row['id'], 'Payment Receipt Submitted', 'Parishioner ' . ($request['user_name'] ?? 'A parishioner') . ' submitted a GCash receipt for request ' . $request['reference_number'] . '.');
                }
            }
            
            $success = 'Payment receipt submitted successfully for admin verification.';
        } else {
            $error = $payment['error'];
        }
    }
}

$documents = [];
$stmt = $conn->prepare("SELECT document_id, document_type, requirement_name, original_name, mime_type, file_size, uploaded_at FROM request_documents WHERE request_id = ? AND deleted_at IS NULL ORDER BY uploaded_at DESC");
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
$reservation=null;$schedule_proposals=[];
$stmt=$conn->prepare("SELECT r.*,GROUP_CONCAT(x.name ORDER BY x.name SEPARATOR ', ') resource_names FROM reservations r LEFT JOIN reservation_resources rr ON rr.reservation_id=r.reservation_id LEFT JOIN resources x ON x.resource_id=rr.resource_id WHERE r.request_id=? AND r.user_id=? GROUP BY r.reservation_id");$stmt->bind_param('ii',$request_id,$user_id);$stmt->execute();$reservation=$stmt->get_result()->fetch_assoc();$stmt->close();
if($reservation){$stmt=$conn->prepare("SELECT p.*,GROUP_CONCAT(x.name ORDER BY x.name SEPARATOR ', ') resource_names FROM schedule_proposals p LEFT JOIN schedule_proposal_resources pr ON pr.proposal_id=p.proposal_id LEFT JOIN resources x ON x.resource_id=pr.resource_id WHERE p.reservation_id=? GROUP BY p.proposal_id ORDER BY p.created_at DESC");$stmt->bind_param('i',$reservation['reservation_id']);$stmt->execute();$schedule_proposals=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();}
$gcash_recipient_name = 'Agnes Calapaan';
$gcash_recipient_number = '09977428176';
$gcash_recipient_display = '0997 742 8176';
$page_title = 'View Request';
?>
<?php include '../templates/header.php'; ?>

<style>
    .payment-guide {
        display: grid;
        grid-template-columns: minmax(230px, 320px) minmax(0, 1fr);
        gap: 18px;
        align-items: start;
        margin-bottom: 18px;
        padding: 16px;
        border: 1px solid #eadfca;
        border-radius: 16px;
        background: #fcfaf5;
    }

    .payment-contact-card {
        display: grid;
        justify-items: center;
        padding: 20px 16px;
        border: 1px solid #ead9af;
        border-radius: 16px;
        background: linear-gradient(135deg, #fbf3df, #f7ecd6);
        text-align: center;
    }

    .payment-contact-avatar {
        width: 52px;
        height: 52px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 9px;
        border: 3px solid #fff;
        border-radius: 50%;
        color: #2a241c;
        background: #b9863a;
        box-shadow: 0 6px 14px rgba(140, 100, 39, 0.25);
        font-size: 1rem;
        font-weight: 900;
    }

    .payment-contact-role {
        color: #8c6427;
        font-size: 0.68rem;
        font-weight: 850;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }

    .payment-contact-name {
        margin-top: 3px;
        color: #2a241c;
        font-family: Georgia, "Times New Roman", serif;
        font-size: 1.05rem;
        font-weight: 800;
    }

    .payment-contact-number-row {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-top: 14px;
        padding: 10px 12px;
        border: 1px solid #ead9af;
        border-radius: 12px;
        background: #fff;
    }

    .payment-contact-number {
        color: #2a241c;
        font-size: 1rem;
        font-weight: 900;
        letter-spacing: 0.04em;
        white-space: nowrap;
    }

    .payment-copy-button {
        flex: 0 0 auto;
        min-height: 30px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 9px;
        border: 1px solid #ece4d3;
        border-radius: 999px;
        color: #8c6427;
        background: #f7ecd6;
        font-size: 0.7rem;
        font-weight: 800;
        white-space: nowrap;
    }

    .payment-copy-button.copied {
        color: #3f7448;
        background: #e8f3e9;
    }

    .payment-instructions {
        padding: 14px 16px;
        border-radius: 14px;
        background: #f7ecd6;
    }

    .payment-instructions h6 {
        margin: 0 0 9px;
        color: #2a241c;
        font-weight: 900;
    }

    .payment-guide-list {
        margin: 0;
        padding-left: 19px;
        color: #4f473d;
    }

    .payment-guide-list li + li {
        margin-top: 7px;
    }

    .payment-receipt-form {
        padding-top: 2px;
    }

    .payment-method-display[readonly] {
        color: #2a241c;
        background: #faf7f1;
        cursor: default;
    }

    .payment-receipt-form input[type="file"] {
        border-color: #b9863a;
    }

    .payment-submit-button {
        width: 100%;
        min-height: 44px;
        border-radius: 12px;
        font-weight: 800;
    }

    body.app-page-view-request .request-attachment-row {
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        gap: 8px !important;
        width: 100%;
        min-width: 0 !important;
        height: auto !important;
        padding: 9px 10px !important;
    }

    body.app-page-view-request .request-attachment-icon {
        width: 26px;
        height: 26px;
        display: inline-flex !important;
        flex: 0 0 26px !important;
        align-items: center;
        justify-content: center;
        border-radius: 7px;
        color: #8c6427;
        background: #f7ecd6;
        font-size: 0.7rem;
    }

    body.app-page-view-request .request-attachment-info {
        flex: 1 1 auto !important;
        min-width: 0 !important;
        max-width: 100% !important;
        overflow: hidden !important;
    }

    body.app-page-view-request .request-attachment-requirement,
    body.app-page-view-request .request-attachment-name {
        display: block !important;
        min-width: 0 !important;
        max-width: 100% !important;
        overflow: hidden !important;
        white-space: nowrap !important;
        word-break: normal !important;
        overflow-wrap: normal !important;
        text-overflow: ellipsis !important;
    }

    body.app-page-view-request .request-attachment-requirement {
        margin-bottom: 1px;
        color: #2a241c;
        font-size: 0.68rem;
        line-height: 1.2;
    }

    body.app-page-view-request .request-attachment-name {
        color: #8c6427;
        font-size: 0.68rem;
        font-weight: 600;
        line-height: 1.2;
    }

    body.app-page-view-request .request-attachment-size {
        display: block !important;
        margin-top: 1px;
        color: #8b8375;
        font-size: 0.62rem;
        line-height: 1.2;
        white-space: nowrap !important;
    }

    body.app-page-view-request .request-attachment-view {
        display: inline-flex !important;
        flex: 0 0 auto !important;
        flex-shrink: 0 !important;
        align-items: center;
        justify-content: center;
        gap: 3px;
        width: auto !important;
        min-width: 0 !important;
        min-height: 0 !important;
        height: auto !important;
        padding: 6px 9px !important;
        border: 1px solid #ece4d3;
        border-radius: 999px;
        color: #8c6427;
        background: #f7ecd6;
        font-size: 0.66rem !important;
        font-weight: 700;
        line-height: 1 !important;
        white-space: nowrap !important;
        word-break: normal !important;
        overflow-wrap: normal !important;
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
                        <?php 
                            $disp_status = strtolower($request['status']) === 'submitted' ? 'pending' : $request['status'];
                        ?>
                        <span class="badge bg-<?php echo getStatusBadgeClass($disp_status); ?>">
                            <?php echo e(ucfirst(str_replace('_', ' ', $disp_status))); ?>
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

                    <?php if ($reservation): ?>
                        <div class="card border mb-4"><div class="card-body"><h6><i class="fas fa-calendar-check"></i> Reservation schedule</h6><p class="mb-1"><strong><?php echo e(date('M j, Y g:i A',strtotime($reservation['start_at']))); ?></strong> to <?php echo e(date('g:i A',strtotime($reservation['end_at']))); ?> (Asia/Manila)</p><p class="text-muted mb-0">Resources: <?php echo e($reservation['resource_names']?:'Unassigned'); ?></p></div></div>
                        <?php foreach($schedule_proposals as $proposal): ?>
                            <div class="alert <?php echo $proposal['status']==='pending'?'alert-warning':'alert-secondary'; ?>">
                                <strong>Schedule proposal:</strong> <?php echo e(date('M j, Y g:i A',strtotime($proposal['proposed_start_at']))); ?>–<?php echo e(date('g:i A',strtotime($proposal['proposed_end_at']))); ?><br>
                                <span>Resources: <?php echo e($proposal['resource_names']); ?>. Reason: <?php echo e($proposal['reason']); ?></span>
                                <?php if($proposal['status']==='pending'&&(!$proposal['expires_at']||$proposal['expires_at']>=date('Y-m-d H:i:s'))): ?><form method="POST" class="mt-2 d-flex gap-2"><?php echo csrfInput(); ?><input type="hidden" name="action" value="respond_schedule_proposal"><input type="hidden" name="proposal_id" value="<?php echo intval($proposal['proposal_id']); ?>"><button class="btn btn-sm btn-success" name="response" value="accept">Accept</button><button class="btn btn-sm btn-outline-danger" name="response" value="reject">Reject</button></form><?php else: ?><div class="mt-2"><span class="badge bg-secondary"><?php echo e(ucfirst($proposal['status'])); ?></span></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($documents_by_type['requirement'])): ?>
                        <div class="mb-4">
                            <h6 class="text-muted mb-2">Submitted Requirements</h6>
                            <div class="list-group">
                                <?php foreach ($documents_by_type['requirement'] as $document): ?>
                                    <a class="list-group-item list-group-item-action request-attachment-row" href="../request-document.php?id=<?php echo intval($document['document_id']); ?>" target="_blank" rel="noopener">
                                        <span class="request-attachment-icon" aria-hidden="true"><i class="fas fa-paperclip"></i></span>
                                        <span class="request-attachment-info">
                                            <?php if (!empty($document['requirement_name'])): ?>
                                                <strong class="request-attachment-requirement"><?php echo e($document['requirement_name']); ?></strong>
                                            <?php endif; ?>
                                            <span class="request-attachment-name" title="<?php echo e($document['original_name']); ?>"><?php echo e($document['original_name']); ?></span>
                                            <small class="request-attachment-size"><?php echo e(formatFileSize($document['file_size'])); ?></small>
                                        </span>
                                        <span class="request-attachment-view"><i class="fas fa-eye" aria-hidden="true"></i> View</span>
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
                                    <div class="payment-contact-card">
                                        <div class="payment-contact-avatar" aria-hidden="true">AC</div>
                                        <div class="payment-contact-role">GCash — Parish Secretary</div>
                                        <div class="payment-contact-name"><?php echo e($gcash_recipient_name); ?></div>
                                        <div class="payment-contact-number-row">
                                            <span class="payment-contact-number"><?php echo e($gcash_recipient_display); ?></span>
                                            <button type="button" class="payment-copy-button" data-copy-gcash="<?php echo e($gcash_recipient_number); ?>" aria-label="Copy GCash number <?php echo e($gcash_recipient_display); ?>">
                                                <i class="fas fa-copy" aria-hidden="true"></i>
                                                <span>Copy</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="payment-instructions">
                                        <h6><i class="fas fa-money-bill-wave"></i> How to Pay</h6>
                                        <ul class="payment-guide-list">
                                            <li>Send your payment via GCash to the name and number above.</li>
                                            <li>Use this request reference as the payment basis: <strong><?php echo e($request['reference_number']); ?></strong>.</li>
                                            <li>After paying, upload the receipt or proof of payment below for verification.</li>
                                        </ul>
                                    </div>
                                </div>
                                <form method="POST" action="view-request.php?id=<?php echo intval($request_id); ?>" enctype="multipart/form-data" class="row g-3 payment-receipt-form">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="action" value="submit_payment">
                                    <input type="hidden" name="payment_method" value="gcash">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold" for="amount">Amount (PHP) <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="amount" name="amount" min="1" step="0.01" inputmode="decimal" placeholder="e.g. 150.00" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold" for="payment_method">Method</label>
                                        <input type="text" class="form-control payment-method-display bg-light" id="payment_method" value="GCash" readonly aria-readonly="true">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold" for="reference_number">Reference Number <span class="text-muted small fw-normal">(Optional)</span></label>
                                        <input type="text" class="form-control" id="reference_number" name="reference_number" placeholder="GCash reference number">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold" for="receipt_file">Receipt / Proof of Payment <span class="text-danger">*</span></label>
                                        <input type="file" class="form-control" id="receipt_file" name="receipt_file" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain" required>
                                        <div class="form-text">Upload a screenshot or photo of your GCash transaction receipt (JPG, PNG, PDF up to 10MB).</div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold" for="notes">Notes <span class="text-muted small fw-normal">(Optional)</span></label>
                                        <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Optional notes about this payment"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary payment-submit-button">
                                            <i class="fas fa-upload me-1"></i> Submit Receipt
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($documents_by_type['admin_file']) || !empty($documents_by_type['released_certificate'])): ?>
                        <div class="mb-4">
                            <h6 class="text-muted mb-2">Certificates from Parish Office</h6>
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

<script>
    (function () {
        var copyButton = document.querySelector('[data-copy-gcash]');
        if (!copyButton) return;

        function copyFallback(value) {
            var input = document.createElement('textarea');
            input.value = value;
            input.setAttribute('readonly', '');
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.select();
            var copied = document.execCommand('copy');
            input.remove();
            return copied;
        }

        copyButton.addEventListener('click', function () {
            var value = copyButton.getAttribute('data-copy-gcash') || '';
            var label = copyButton.querySelector('span');
            var copyTask = navigator.clipboard && window.isSecureContext
                ? navigator.clipboard.writeText(value).then(function () { return true; }).catch(function () { return copyFallback(value); })
                : Promise.resolve(copyFallback(value));

            copyTask.then(function (copied) {
                if (!copied || !label) return;
                label.textContent = 'Copied';
                copyButton.classList.add('copied');
                window.setTimeout(function () {
                    label.textContent = 'Copy';
                    copyButton.classList.remove('copied');
                }, 1600);
            });
        });
    }());
</script>

<?php include '../templates/footer.php'; ?>
