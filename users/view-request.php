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
$reference_number = trim((string) ($_GET['ref'] ?? ''));

if ($request_id > 0) {
    $stmt = $conn->prepare("SELECT r.*, u.fullname as user_name FROM requests r JOIN users u ON r.user_id = u.id WHERE r.request_id = ? AND r.user_id = ?");
    if (!$stmt) {
        redirect('my-requests.php');
    }
    $stmt->bind_param('ii', $request_id, $user_id);
} elseif ($reference_number !== '') {
    $stmt = $conn->prepare("SELECT r.*, u.fullname as user_name FROM requests r JOIN users u ON r.user_id = u.id WHERE r.reference_number = ? AND r.user_id = ?");
    if (!$stmt) {
        redirect('my-requests.php');
    }
    $stmt->bind_param('si', $reference_number, $user_id);
} else {
    redirect('my-requests.php');
}

$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows == 0) {
    $stmt->close();
    redirect('my-requests.php');
}

$request = $result->fetch_assoc();
$request_id = intval($request['request_id']);
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
$reservation=null;$schedule_proposals=[];
$stmt=$conn->prepare("SELECT r.*,GROUP_CONCAT(x.name ORDER BY x.name SEPARATOR ', ') resource_names FROM reservations r LEFT JOIN reservation_resources rr ON rr.reservation_id=r.reservation_id LEFT JOIN resources x ON x.resource_id=rr.resource_id WHERE r.request_id=? AND r.user_id=? GROUP BY r.reservation_id");$stmt->bind_param('ii',$request_id,$user_id);$stmt->execute();$reservation=$stmt->get_result()->fetch_assoc();$stmt->close();
if($reservation){$stmt=$conn->prepare("SELECT p.*,GROUP_CONCAT(x.name ORDER BY x.name SEPARATOR ', ') resource_names FROM schedule_proposals p LEFT JOIN schedule_proposal_resources pr ON pr.proposal_id=p.proposal_id LEFT JOIN resources x ON x.resource_id=pr.resource_id WHERE p.reservation_id=? GROUP BY p.proposal_id ORDER BY p.created_at DESC");$stmt->bind_param('i',$reservation['reservation_id']);$stmt->execute();$schedule_proposals=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();}
$page_title = 'View Request';
?>
<?php include '../templates/header.php'; ?>

<style>

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
                            $disp_status = strtolower($request['status'] ?? 'pending');
                        ?>
                        <span class="badge rounded-pill border px-3 py-1.5 fw-semibold <?php echo getStatusBadgeClass($disp_status); ?>">
                            <?php echo e(ucfirst($disp_status)); ?>
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
                        <p class="mb-0" style="white-space: pre-line;"><?php echo sanitize($request['description'] ?? 'No description provided'); ?></p>
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
                            <h6 class="text-muted mb-2"><i class="fas fa-money-bill-wave me-1"></i> Payment Information</h6>
                            <div class="list-group">
                                <?php foreach ($payments as $payment): ?>
                                    <div class="list-group-item p-3">
                                        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                            <div>
                                                <div class="d-flex align-items-center gap-2 mb-1">
                                                    <span class="badge <?php echo strtolower($payment['payment_method']) === 'gcash' ? 'bg-primary' : 'bg-secondary'; ?>">
                                                        <i class="fas <?php echo strtolower($payment['payment_method']) === 'gcash' ? 'fa-mobile-alt' : 'fa-hand-holding-usd'; ?> me-1"></i>
                                                        <?php echo e(strtoupper($payment['payment_method']) === 'GCASH' ? 'GCash' : ucfirst($payment['payment_method'])); ?>
                                                    </span>
                                                    <?php
                                                    $payment_badge = ['pending' => 'warning text-dark', 'verified' => 'success', 'rejected' => 'danger'][$payment['status']] ?? 'secondary';
                                                    $status_label = $payment['status'] === 'pending' ? 'Pending Verification' : ucfirst($payment['status']);
                                                    ?>
                                                    <span class="badge bg-<?php echo e($payment_badge); ?>"><?php echo e($status_label); ?></span>
                                                </div>
                                                <div class="text-dark fw-bold">
                                                    <?php if (floatval($payment['amount']) > 0): ?>
                                                        PHP <?php echo number_format(floatval($payment['amount']), 2); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Pay in person (Parish Office)</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($payment['reference_number'])): ?>
                                                    <div class="small text-muted mt-1">
                                                        <i class="fas fa-hashtag me-1"></i>Ref: <strong><?php echo e($payment['reference_number']); ?></strong>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($payment['notes'])): ?>
                                                    <div class="small text-muted mt-1">
                                                        <i class="fas fa-sticky-note me-1"></i>Notes: <?php echo e($payment['notes']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($payment['admin_remarks'])): ?>
                                                    <div class="small text-danger mt-1">
                                                        <i class="fas fa-info-circle me-1"></i>Admin note: <?php echo e($payment['admin_remarks']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-end">
                                                <?php if (!empty($payment['receipt_document_id'])): ?>
                                                    <a class="btn btn-sm btn-outline-primary" href="../request-document.php?id=<?php echo intval($payment['receipt_document_id']); ?>" target="_blank" rel="noopener">
                                                        <i class="fas fa-receipt me-1"></i> View Receipt
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
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



<?php include '../templates/footer.php'; ?>
