<?php
/**
 * Request Detail Module - Shows the status, remarks, and timeline for a single user request.
 */
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

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
                            $released_certs = array_merge($documents_by_type['released_certificate'], $documents_by_type['admin_file']);
                            $has_released_cert = !empty($released_certs);
                            $disp_status = strtolower($request['status'] ?? 'pending');
                            if ($has_released_cert && in_array($disp_status, ['completed', 'approved', 'released'], true)) {
                                $disp_status = 'released — available for download';
                            }
                        ?>
                        <span class="badge rounded-pill border px-3 py-1.5 fw-semibold <?php echo getStatusBadgeClass($disp_status); ?>">
                            <?php echo e(ucwords($disp_status)); ?>
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

                    <?php if ($has_released_cert): ?>
                        <div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%); border: 1.5px solid #86efac !important; border-radius: 16px;">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-start gap-3 flex-wrap flex-md-nowrap">
                                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 52px; height: 52px; border-radius: 14px; background: #16a34a; color: #ffffff; font-size: 1.5rem; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3);">
                                        <i class="fas fa-certificate"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
                                            <h5 class="fw-bold text-dark mb-0" style="font-family: 'Playfair Display', Georgia, serif;">Official Certificate Ready for Download</h5>
                                            <span class="badge bg-success text-white px-2.5 py-1 rounded-pill" style="font-size: 0.78rem;">
                                                <i class="fas fa-check-circle me-1"></i> Ready for Download
                                            </span>
                                        </div>
                                        <p class="text-secondary small mb-3">
                                            The parish office has finalized and released your official certificate. You can preview or download your digital certificate directly below:
                                        </p>
                                        <div class="d-flex flex-column gap-2">
                                            <?php foreach ($released_certs as $cert): ?>
                                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 p-3 bg-white rounded-3 border shadow-sm" style="border-color: #bbf7d0 !important;">
                                                    <div class="d-flex align-items-center gap-2 text-truncate me-2">
                                                        <i class="fas fa-file-pdf text-danger fs-3"></i>
                                                        <div>
                                                            <strong class="d-block text-dark text-truncate" style="max-width: 320px; font-size: 0.95rem;"><?php echo e($cert['original_name']); ?></strong>
                                                            <span class="text-muted small"><?php echo e(formatFileSize($cert['file_size'])); ?> &bull; Issued <?php echo formatDate($cert['uploaded_at']); ?></span>
                                                        </div>
                                                    </div>
                                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                                        <button type="button" 
                                                                class="btn btn-sm btn-outline-secondary btn-preview-doc"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#documentPreviewModal"
                                                                data-doc-id="<?php echo intval($cert['document_id']); ?>"
                                                                data-doc-name="<?php echo e($cert['original_name']); ?>"
                                                                data-doc-file="<?php echo e($cert['original_name']); ?>"
                                                                data-doc-size="<?php echo formatFileSize($cert['file_size']); ?>"
                                                                data-doc-mime="<?php echo e($cert['mime_type'] ?? ''); ?>">
                                                            <i class="fas fa-eye me-1"></i> Preview
                                                        </button>
                                                        <a class="btn btn-sm btn-success fw-bold px-3 shadow-sm" href="../request-document.php?id=<?php echo intval($cert['document_id']); ?>&download=1" download>
                                                            <i class="fas fa-download me-1"></i> Download Certificate
                                                        </a>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Reference Number</h6>
                            <p class="lead"><?php echo e($request['reference_number']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Request Type</h6>
                            <?php
                                $raw_view_type = strtolower((string)($request['request_type'] ?? ''));
                                $disp_view_type = match ($raw_view_type) {
                                    'baptism_service', 'baptism' => 'Baptism',
                                    'first_communion_service', 'first_communion', 'communion' => 'First Communion',
                                    'confirmation_service', 'confirmation' => 'Confirmation',
                                    'marriage_wedding_service', 'marriage', 'wedding' => 'Marriage / Wedding',
                                    'funeral_mass', 'funeral' => 'Funeral Mass',
                                    'anointing_of_the_sick' => 'Anointing of the Sick',
                                    'patronal_fiesta' => 'Patronal Fiesta',
                                    default => ucfirst(str_replace('_', ' ', $request['request_type']))
                                };
                            ?>
                            <p class="lead"><?php echo e($disp_view_type); ?></p>
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

                    <?php 
                        $isCommOrConfView = in_array($raw_view_type, ['first_communion_service', 'first_communion', 'communion', 'confirmation_service', 'confirmation'], true);
                        $isCompletedView = strtolower((string)($request['status'] ?? '')) === 'completed';
                        $hasCeremonySched = !empty($request['ceremony_date']) && !empty($request['ceremony_time']);
                    ?>
                    <?php if ($isCommOrConfView): ?>
                        <?php if ($isCompletedView && $hasCeremonySched): ?>
                            <?php
                                $tzManila = new DateTimeZone('Asia/Manila');
                                $cDt = new DateTime($request['ceremony_date'] . ' ' . $request['ceremony_time'], $tzManila);
                                $ceremonyDateLong = $cDt->format('l, F j, Y');
                                $ceremonyTimeStr = $cDt->format('g:i A');
                                $ceremonyPlace = getParishPlaceName($conn);
                                $ceremonyMinister = trim((string)($request['ceremony_minister'] ?? 'Parish Priest'));
                            ?>
                            <div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%); border: 1.5px solid #86efac !important; border-radius: 14px;">
                                <div class="card-body p-4">
                                    <div class="d-flex align-items-start gap-3">
                                        <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 48px; height: 48px; border-radius: 12px; background: #16a34a; color: #ffffff; font-size: 1.35rem; box-shadow: 0 4px 10px rgba(22, 163, 74, 0.25);">
                                            <i class="fas fa-calendar-check"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                                <h5 class="fw-bold text-dark mb-0" style="font-family: 'Playfair Display', Georgia, serif;">Confirmed Ceremony Schedule</h5>
                                                <span class="badge bg-success text-white px-2.5 py-1 rounded-pill" style="font-size: 0.78rem;">
                                                    <i class="fas fa-check-circle me-1"></i> Confirmed by Parish Office
                                                </span>
                                            </div>
                                            <div class="row g-3">
                                                <div class="col-sm-6 col-md-3">
                                                    <span class="text-muted d-block small mb-1 text-uppercase fw-semibold" style="letter-spacing: 0.5px; font-size: 0.75rem;">Ceremony Date</span>
                                                    <strong class="text-dark d-block" style="font-size: 0.95rem;"><?php echo e($ceremonyDateLong); ?></strong>
                                                </div>
                                                <div class="col-sm-6 col-md-3">
                                                    <span class="text-muted d-block small mb-1 text-uppercase fw-semibold" style="letter-spacing: 0.5px; font-size: 0.75rem;">Time</span>
                                                    <strong class="text-dark d-block" style="font-size: 0.95rem;"><?php echo e($ceremonyTimeStr); ?></strong>
                                                </div>
                                                <div class="col-sm-6 col-md-3">
                                                    <span class="text-muted d-block small mb-1 text-uppercase fw-semibold" style="letter-spacing: 0.5px; font-size: 0.75rem;">Place</span>
                                                    <strong class="text-dark d-block" style="font-size: 0.95rem;"><?php echo e($ceremonyPlace); ?></strong>
                                                </div>
                                                <div class="col-sm-6 col-md-3">
                                                    <span class="text-muted d-block small mb-1 text-uppercase fw-semibold" style="letter-spacing: 0.5px; font-size: 0.75rem;">Minister</span>
                                                    <strong class="text-dark d-block" style="font-size: 0.95rem;"><?php echo e($ceremonyMinister); ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card border-0 mb-4" style="background: #FAF7F2; border: 1px dashed #D6C7B2 !important; border-radius: 12px;">
                                <div class="card-body p-3 d-flex align-items-center gap-2 text-muted" style="font-size: 0.9rem;">
                                    <span class="d-inline-flex align-items-center justify-content-center text-secondary" style="width: 24px; height: 24px; flex-shrink: 0;">
                                        <i class="fas fa-calendar-alt"></i>
                                    </span>
                                    <span>Schedule: To be announced by the parish office.</span>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="mb-4">
                        <h6 class="text-muted mb-2">Description</h6>
                        <?php
                            $desc_text = (string)($request['description'] ?? 'No description provided');
                            $raw_rtype = strtolower((string)($request['request_type'] ?? ''));
                            if (in_array($raw_rtype, ['first_communion_service', 'first_communion', 'communion', 'confirmation_service', 'confirmation'], true) && $desc_text !== '') {
                                $lines = explode("\n", $desc_text);
                                $filtered_lines = [];
                                foreach ($lines as $line) {
                                    $trimmed_line = trim($line);
                                    if (preg_match('/^(preferred date|requested date|requested service date|preferred time|preferred time slot|ceremony date|ceremony time|minister|officiating priest|year|confirmation year|month and day|month & day)\s*:/i', $trimmed_line)) {
                                        continue;
                                    }
                                    $filtered_lines[] = $line;
                                }
                                $desc_text = trim(implode("\n", $filtered_lines));
                                if ($desc_text === '') {
                                    $desc_text = 'No description provided';
                                }
                            }
                        ?>
                        <p class="mb-0" style="white-space: pre-line;"><?php echo sanitize($desc_text); ?></p>
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
                            <h6 class="text-muted mb-2">Submitted Requirements</                            <div class="list-group">
                                <?php foreach ($documents_by_type['requirement'] as $document): ?>
                                    <div class="list-group-item d-flex align-items-center justify-content-between p-3 request-attachment-row">
                                        <div class="d-flex align-items-center gap-3 text-truncate me-2">
                                            <span class="request-attachment-icon flex-shrink-0" aria-hidden="true"><i class="fas fa-file-lines"></i></span>
                                            <div class="text-truncate">
                                                <?php if (!empty($document['requirement_name'])): ?>
                                                    <strong class="request-attachment-requirement d-block text-truncate"><?php echo e($document['requirement_name']); ?></strong>
                                                <?php endif; ?>
                                                <span class="request-attachment-name text-truncate d-block" title="<?php echo e($document['original_name']); ?>"><?php echo e($document['original_name']); ?></span>
                                                <small class="request-attachment-size text-muted"><?php echo e(formatFileSize($document['file_size'])); ?></small>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-primary btn-preview-doc d-inline-flex align-items-center gap-1"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#documentPreviewModal"
                                                    data-doc-id="<?php echo intval($document['document_id']); ?>"
                                                    data-doc-name="<?php echo e(!empty($document['requirement_name']) ? $document['requirement_name'] : $document['original_name']); ?>"
                                                    data-doc-file="<?php echo e($document['original_name']); ?>"
                                                    data-doc-size="<?php echo formatFileSize($document['file_size']); ?>"
                                                    data-doc-mime="<?php echo e($document['mime_type'] ?? ''); ?>">
                                                <i class="fas fa-eye"></i> <span>View</span>
                                            </button>
                                            <a class="btn btn-sm btn-outline-secondary" 
                                               href="../request-document.php?id=<?php echo intval($document['document_id']); ?>&download=1" 
                                               title="Download" 
                                               download>
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>div>
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
                                            <div class="text-end d-flex align-items-center gap-1">
                                                <?php if (!empty($payment['receipt_document_id'])): ?>
                                                    <button type="button" 
                                                            class="btn btn-sm btn-outline-primary btn-preview-doc d-inline-flex align-items-center gap-1"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#documentPreviewModal"
                                                            data-doc-id="<?php echo intval($payment['receipt_document_id']); ?>"
                                                            data-doc-name="Payment Receipt - <?php echo e($payment['reference_number'] ?: 'Ref #' . $payment['payment_id']); ?>"
                                                            data-doc-file="<?php echo e($payment['original_name'] ?: 'receipt'); ?>"
                                                            data-doc-size="<?php echo !empty($payment['file_size']) ? formatFileSize($payment['file_size']) : ''; ?>"
                                                            data-doc-mime="<?php echo e($payment['mime_type'] ?? ''); ?>">
                                                        <i class="fas fa-receipt me-1"></i> <span>View Receipt</span>
                                                    </button>
                                                    <a class="btn btn-sm btn-outline-secondary" 
                                                       href="../request-document.php?id=<?php echo intval($payment['receipt_document_id']); ?>&download=1" 
                                                       title="Download Receipt" 
                                                       download>
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php
                    $is_req_completed = (strtolower(trim((string)$request['status'])) === 'completed');
                    $req_type_str = strtolower((string)($request['certificate_type'] ?? ($request['request_type'] ?? '')));
                    $is_baptism_req = (str_contains($req_type_str, 'baptism'));
                    ?>
                    <?php if ($is_req_completed && $is_baptism_req): ?>
                        <div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg, #FFFDF9 0%, #F9F5EC 100%); border: 1.5px solid #dfc27d !important; border-radius: 12px;">
                            <div class="card-body p-3 p-md-4">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <div style="width: 48px; height: 48px; min-width: 48px; border-radius: 10px; background: #5c1414; color: #ffd700; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; box-shadow: 0 4px 10px rgba(92, 20, 20, 0.25);">
                                            <i class="fas fa-certificate"></i>
                                        </div>
                                        <div>
                                            <h6 class="fw-bold mb-1" style="color: #5c1414; font-family: 'Playfair Display', Georgia, serif; font-size: 1.05rem;">
                                                Official Certificate of Baptism Ready
                                            </h6>
                                            <p class="text-muted small mb-0">
                                                Issued by San Lorenzo Ruiz Mission Station from official parish records.
                                            </p>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <a href="download-certificate.php?request_id=<?php echo $request_id; ?>" class="btn btn-outline-dark btn-sm fw-semibold px-3 py-2" target="_blank" style="border-radius: 8px;">
                                            <i class="fas fa-eye me-1"></i> Preview / Print
                                        </a>
                                        <a href="download-certificate.php?request_id=<?php echo $request_id; ?>&download=1" class="btn btn-success btn-sm fw-bold px-3 py-2" style="background: #1b7444; border-color: #1b7444; border-radius: 8px; box-shadow: 0 3px 8px rgba(27, 116, 68, 0.25);">
                                            <i class="fas fa-file-pdf me-1"></i> Download PDF
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($documents_by_type['admin_file']) || !empty($documents_by_type['released_certificate'])): ?>
                        <div class="mb-4">
                            <h6 class="text-muted mb-2">Certificates from Parish Office</h6>
                            <div class="list-group">
                                <?php foreach (array_merge($documents_by_type['released_certificate'], $documents_by_type['admin_file']) as $document): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center p-3">
                                        <div class="d-flex align-items-center gap-2 text-truncate me-2">
                                            <i class="fas fa-file-circle-check text-success flex-shrink-0"></i>
                                            <div class="text-truncate">
                                                <span class="fw-semibold text-dark d-block text-truncate"><?php echo e($document['original_name']); ?></span>
                                                <small class="text-muted"><?php echo e(formatFileSize($document['file_size'])); ?></small>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-primary btn-preview-doc d-inline-flex align-items-center gap-1"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#documentPreviewModal"
                                                    data-doc-id="<?php echo intval($document['document_id']); ?>"
                                                    data-doc-name="<?php echo e($document['original_name']); ?>"
                                                    data-doc-file="<?php echo e($document['original_name']); ?>"
                                                    data-doc-size="<?php echo formatFileSize($document['file_size']); ?>"
                                                    data-doc-mime="<?php echo e($document['mime_type'] ?? ''); ?>">
                                                <i class="fas fa-eye"></i> <span>Preview</span>
                                            </button>
                                            <a class="btn btn-sm btn-outline-secondary" 
                                               href="../request-document.php?id=<?php echo intval($document['document_id']); ?>&download=1" 
                                               title="Download" 
                                               download>
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                    </div>
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
                            <?php if ($is_baptism_req): ?>
                                <a href="download-certificate.php?request_id=<?php echo $request_id; ?>&download=1" class="btn btn-success fw-bold">
                                    <i class="fas fa-file-pdf me-1"></i> Download Certificate (PDF)
                                </a>
                            <?php else: ?>
                                <button class="btn btn-primary" onclick="window.print()">
                                    <i class="fas fa-print"></i> Print/Save as PDF
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../templates/document-preview-modal.php'; ?>

<?php include '../templates/footer.php'; ?>
