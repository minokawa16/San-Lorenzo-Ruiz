<?php
/**
 * Admin - Process Request Page
 * Handle request review, approval, and management
 * 
 * This file was MISSING - causing the "404 Not Found" error
 * Fixed: May 8, 2026
 */

// Include security and dependencies
include __DIR__ . '/../config/security.php';
include __DIR__ . '/../includes/Security.php';
include __DIR__ . '/../includes/Logger.php';
include __DIR__ . '/../database/BaseDB.php';
include __DIR__ . '/../database/config.php';
include __DIR__ . '/../includes/session.php';
include __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/RequestService.php';
require_once __DIR__ . '/../services/SacramentalApprovalService.php';

// Check admin access
requireAdmin();
requirePermission('requests.manage');

// Initialize components
$logger = new Logger();
$db = new BaseDB($conn);
ensureRequestDocumentsSchema($conn);
ensureEmailNotificationSchema($conn);

// Get request ID from URL
$request_id = (int)($_GET['id'] ?? 0);

if ($request_id === 0) {
    header("Location: manage-requests.php");
    exit;
}

// Fetch request details
$sql = "SELECT r.*, u.id as user_id, u.fullname, u.email, u.phone_number, u.chapel_district, u.created_at as user_created_at, u.profile_picture
        FROM requests r 
        JOIN users u ON r.user_id = u.id 
        WHERE r.request_id = ? AND r.deleted_at IS NULL
        LIMIT 1";

$request = $db->selectOne($sql, 'i', [$request_id]);

if (!$request) {
    header("Location: manage-requests.php?error=Request not found");
    exit;
}

// Handle form submission (Approve/Reject/Add Remarks)
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Verify CSRF token
        $security = new Security();
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!$security->verifyCSRFToken($csrf_token)) {
            throw new Exception('CSRF token validation failed');
        }

        $action = trim($_POST['action'] ?? '');
        $admin_response = trim($_POST['admin_response'] ?? '');
        $new_status = trim($_POST['status'] ?? '');
        $officiating_priest = trim($_POST['officiating_priest'] ?? $_POST['minister'] ?? '');
        $parish_priest = trim($_POST['parish_priest'] ?? '');

        // Validate action
        if (!in_array($action, ['approve', 'reject', 'request_more', 'complete', 'remark'])) {
            throw new Exception('Invalid action');
        }

        $is_sacramental_type = SacramentalApprovalService::isSacramentalRequestType((string)($request['request_type'] ?? ''));

        // Enforce Minister and Parish Priest selection for sacramental approvals
        if ($is_sacramental_type && ($action === 'complete' || $action === 'approve' || in_array($new_status, ['approved', 'completed'], true))) {
            if ($officiating_priest === '' || strcasecmp($officiating_priest, 'Rev. Fr. Parish Priest') === 0 || strcasecmp($officiating_priest, 'N/A') === 0) {
                throw new Exception('Please assign the Minister / Officiating Priest who performed the baptism before completing or approving this record.');
            }
            if ($parish_priest === '' || strcasecmp($parish_priest, 'N/A') === 0) {
                throw new Exception('Please confirm and assign the Parish Priest for the official record before completing or approving.');
            }
        }

        if ($action === 'complete' || $action === 'approve') {
            $sacramentalService = new SacramentalApprovalService($conn);

            if ($is_sacramental_type) {
                $completionResult = $sacramentalService->completeRequest($request_id, (int)$_SESSION['user_id'], [
                    'admin_response' => $admin_response,
                    'officiating_priest' => $officiating_priest,
                    'parish_priest' => $parish_priest,
                    'target_status' => $new_status === 'approved' ? 'approved' : 'completed'
                ]);

                $success_message = 'Request completed successfully! User has been notified.';
                if (!empty($completionResult['sacramental_record']['registered'])) {
                    $recType = ucfirst($completionResult['sacramental_record']['type'] ?? 'Sacramental');
                    $regNo = $completionResult['sacramental_record']['registry_no'] ?? '';
                    $success_message .= " Official {$recType} record registered ({$regNo}) and parish calendar schedule locked.";
                } else {
                    $success_message .= ' Parish calendar schedule locked.';
                }

                $logger->info('Sacramental request completed via SacramentalApprovalService', [
                    'request_id' => $request_id,
                    'user_id' => $request['user_id'],
                    'result' => $completionResult
                ]);
            } else {
                $workflow = new RequestService($conn);
                $workflow->transition($request_id, 'completed', (int) $_SESSION['user_id'], $admin_response);
                syncApprovedRequestToCalendar($conn, $request_id, (int)$_SESSION['user_id']);
                createRequestStatusNotification($conn, $request, 'completed', $admin_response);
                $success_message = 'Request completed and schedule added to the parish calendar! User has been notified.';
            }

            // Refresh request data
            $request = $db->selectOne($sql, 'i', [$request_id]);
        } else {
            // Map actions to statuses
            $status_map = [
                'reject' => 'rejected',
                'request_more' => 'needs_information',
                'complete' => 'completed',
                'remark' => $request['status']  // Keep current status
            ];

            $new_status = $status_map[$action];

            if ($action !== 'remark') {
                $workflow = new RequestService($conn);
                $workflow->transition($request_id, $new_status, (int) $_SESSION['user_id'], $admin_response);
            }

            // Log to audit trail
            $action_type = strtoupper($action);
            $new_value = json_encode(['status' => $new_status, 'admin_response' => $admin_response]);
            if (!writeAuditLog($conn, (int) $_SESSION['user_id'], $action_type, 'requests', $request_id, null, $new_value)) {
                throw new RuntimeException('Unable to record the request audit event.');
            }

            createRequestStatusNotification($conn, $request, $new_status, $admin_response);

            $success_message = 'Request ' . $action . 'd successfully! User has been notified.';
            if (in_array($new_status, ['pending', 'processing', 'rejected'], true)) {
                cancelLinkedRequestCalendarEvent($conn, $request_id);
            }
            
            // Log the action
            $logger->info('Admin processed request', [
                'request_id' => $request_id,
                'user_id' => $request['user_id'],
                'action' => $action,
                'admin_id' => $_SESSION['user_id']
            ]);

            // Refresh request data
            $request = $db->selectOne($sql, 'i', [$request_id]);
        }

    } catch (Exception $e) {
        $error_message = $e->getMessage();
        $logger->error('Error processing request: ' . $e->getMessage());
    }
}

// Get request history
$history_sql = "SELECT * FROM audit_log 
               WHERE table_name = 'requests' AND record_id = ? 
               ORDER BY created_at DESC";
$history = $db->select($history_sql, 'i', [$request_id]);

// Get request documents if any
$docs_sql = "SELECT * FROM request_documents WHERE request_id = ? AND deleted_at IS NULL ORDER BY uploaded_at DESC";
$documents = $db->select($docs_sql, 'i', [$request_id]);

$page_title = 'Review Request - #' . $request['reference_number'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Parish Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/holy-theme.css">
    <link rel="stylesheet" href="../assets/css/premium-parish.css">
    <style>
        body {
            background:
                radial-gradient(circle at 14% 8%, rgba(247, 223, 158, 0.34), transparent 28%),
                radial-gradient(circle at 88% 18%, rgba(135, 174, 234, 0.25), transparent 26%),
                linear-gradient(180deg, #fffdf8 0%, #f7f9fc 48%, #f3f7fb 100%);
            min-height: 100vh;
            font-family: 'Poppins', 'Inter', sans-serif;
        }

        .container-main {
            width: min(100%, 1180px);
            margin: 0 auto 30px;
        }

        .card-header-holy {
            background: linear-gradient(135deg, #1E3A5F 0%, #10B981 100%);
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
            border-left: 5px solid #D4AF37;
        }

        .request-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            border-top: 4px solid #10B981;
        }

        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
        }

        .status-pending {
            background: #FFF3CD;
            color: #856404;
        }

        .status-approved {
            background: #D4EDDA;
            color: #155724;
        }

        .status-rejected {
            background: #F8D7DA;
            color: #721C24;
        }

        .status-completed {
            background: #D1ECF1;
            color: #0C5460;
        }

        .status-processing {
            background: #E2E3E5;
            color: #383D41;
        }

        .info-block {
            background: #F8F9FA;
            border-left: 4px solid #D4AF37;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .info-label {
            font-weight: 600;
            color: #1E3A5F;
            font-size: 12px;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .info-value {
            color: #333;
            font-size: 15px;
        }

        .action-btn {
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
        }

        .btn-approve {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
            color: white;
        }

        .btn-approve:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
            color: white;
        }

        .btn-reject {
            background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
            color: white;
        }

        .btn-reject:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(239, 68, 68, 0.3);
            color: white;
        }

        .btn-more-info {
            background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
            color: white;
        }

        .btn-more-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(245, 158, 11, 0.3);
            color: white;
        }

        .btn-complete {
            background: linear-gradient(135deg, #3B82F6 0%, #1D4ED8 100%);
            color: white;
        }

        .btn-complete:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(59, 130, 246, 0.3);
            color: white;
        }

        .timeline {
            position: relative;
            padding: 20px 0;
        }

        .timeline-item {
            display: flex;
            margin-bottom: 20px;
        }

        .timeline-marker {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #D4AF37 0%, #B8860B 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-right: 15px;
            flex-shrink: 0;
            font-size: 18px;
        }

        .timeline-content {
            flex-grow: 1;
        }

        .timeline-date {
            color: #666;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .timeline-title {
            font-weight: 600;
            color: #1E3A5F;
            margin-bottom: 5px;
        }

        .timeline-description {
            color: #555;
            font-size: 14px;
        }

        .modal-header-holy {
            background: linear-gradient(135deg, #1E3A5F 0%, #10B981 100%);
            color: white;
            border: none;
        }

        .form-label {
            color: #1E3A5F;
            font-weight: 600;
        }

        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #D4AF37;
            font-size: 14px;
        }

        .form-control:focus, .form-select:focus {
            border-color: #10B981;
            box-shadow: 0 0 0 0.2rem rgba(16, 185, 129, 0.1);
        }

        .back-link {
            color: white;
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 20px;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .back-link:hover {
            color: #D4AF37;
        }

        .header-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #D4AF37 0%, #B8860B 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
    </style>
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
</head>
<body class="premium-admin church-theme">
    <?php include '../includes/admin-sidebar.php'; ?>
    <main class="premium-admin-content">
    <div class="container-main">
        <!-- Back Button -->
        <a href="manage-requests.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Requests
        </a>

        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Header Section with Request Overview -->
        <div class="header-section">
            <div class="row align-items-center">
                <div class="col-auto">
                    <?php echo renderUserAvatar($request, 54, 'shadow-sm'); ?>
                </div>
                <div class="col">
                    <h2 class="mb-0" style="color: #1E3A5F;">
                        <strong><?php echo htmlspecialchars($request['fullname']); ?></strong>
                    </h2>
                    <p class="text-muted mb-0">Reference: <strong><?php echo $request['reference_number']; ?></strong></p>
                </div>
                <div class="col-auto">
                    <?php 
                        $disp_status = strtolower($request['status']) === 'submitted' ? 'pending' : $request['status'];
                    ?>
                    <span class="status-badge status-<?php echo e($disp_status); ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $disp_status)); ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Main Content (Left) -->
            <div class="col-lg-8">
                <!-- Request Details Card -->
                <div class="request-card">
                    <div class="card-header-holy">
                        <h5 class="mb-0">
                            <i class="fas fa-file-alt"></i> REQUEST DETAILS
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-block">
                                    <div class="info-label">Request Type</div>
                                    <div class="info-value">
                                        <?php echo ucfirst(str_replace('_', ' ', $request['request_type'])); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-block">
                                    <div class="info-label">Request Date</div>
                                    <div class="info-value">
                                        <?php echo date('F d, Y H:i A', strtotime($request['date_requested'])); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($request['description'])): ?>
                            <div class="info-block">
                                <div class="info-label">Description</div>
                                <div class="info-value">
                                    <?php echo nl2br(htmlspecialchars($request['description'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($request['admin_response'])): ?>
                            <div class="info-block" style="border-left-color: #EF4444; background: #FEE2E2;">
                                <div class="info-label">Previous Admin Response</div>
                                <div class="info-value">
                                    <?php echo nl2br(htmlspecialchars($request['admin_response'])); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="info-block">
                            <div class="info-label">Submitted Requirements</div>
                            <div class="info-value">
                                <?php if (!empty($documents)): ?>
                                    <div class="list-group">
                                        <?php foreach ($documents as $document): ?>
                                            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="../request-document.php?id=<?php echo intval($document['document_id']); ?>" target="_blank">
                                                <span>
                                                    <?php if (!empty($document['requirement_name'])): ?>
                                                        <strong><?php echo htmlspecialchars($document['requirement_name']); ?></strong><br>
                                                    <?php endif; ?>
                                                    <i class="fas fa-paperclip"></i>
                                                    <?php echo htmlspecialchars($document['original_name']); ?>
                                                </span>
                                                <small class="text-muted"><?php echo htmlspecialchars(formatFileSize($document['file_size'])); ?></small>
                                            </a>
                                            <?php if (isRequestImageDocument($document['mime_type'] ?? '')): ?>
                                                <a href="../request-document.php?id=<?php echo intval($document['document_id']); ?>" target="_blank">
                                                    <img src="../request-document.php?id=<?php echo intval($document['document_id']); ?>" alt="Submitted requirement preview" class="img-fluid rounded border mt-2 mb-3" style="max-height: 280px; object-fit: contain; background: #fff;">
                                                </a>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">No requirements file was submitted with this request.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- User Information Card -->
                <div class="request-card">
                    <div class="card-header-holy">
                        <h5 class="mb-0">
                            <i class="fas fa-user-circle"></i> USER INFORMATION
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
                            <?php echo renderUserAvatar($request, 44); ?>
                            <div>
                                <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($request['fullname']); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($request['email']); ?></small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-block">
                                    <div class="info-label">Full Name</div>
                                    <div class="info-value"><?php echo htmlspecialchars($request['fullname']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-block">
                                    <div class="info-label">Email Address</div>
                                    <div class="info-value">
                                        <a href="mailto:<?php echo htmlspecialchars($request['email']); ?>">
                                            <?php echo htmlspecialchars($request['email']); ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-block">
                                    <div class="info-label">Phone Number</div>
                                    <div class="info-value">
                                        <a href="tel:<?php echo htmlspecialchars($request['phone_number']); ?>">
                                            <?php echo htmlspecialchars($request['phone_number']); ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-block">
                                    <div class="info-label">Chapel District</div>
                                    <div class="info-value"><?php echo htmlspecialchars($request['chapel_district'] ?? 'N/A'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Timeline/History Card -->
                <?php if (!empty($history)): ?>
                    <div class="request-card">
                        <div class="card-header-holy">
                            <h5 class="mb-0">
                                <i class="fas fa-history"></i> REQUEST TIMELINE
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="timeline">
                                <?php foreach ($history as $item): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-marker">
                                            <i class="fas fa-check"></i>
                                        </div>
                                        <div class="timeline-content">
                                            <div class="timeline-date">
                                                <?php echo date('F d, Y H:i A', strtotime($item['created_at'])); ?>
                                            </div>
                                            <div class="timeline-title">
                                                <?php echo ucfirst(str_replace('_', ' ', $item['action'])); ?>
                                            </div>
                                            <div class="timeline-description">
                                                <?php echo htmlspecialchars($item['action']); ?> by Admin
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar (Right) - Actions -->
            <div class="col-lg-4">
                <!-- Action Panel -->
                <div class="request-card">
                    <div class="card-header-holy">
                        <h5 class="mb-0">
                            <i class="fas fa-tasks"></i> ACTIONS
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? ''); ?>">

                            <div class="mb-3">
                                <label for="status" class="form-label">Update Status</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="pending" <?php echo $request['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <?php if ($request['status'] == 'approved'): ?>
                                        <option value="approved" selected>Approved</option>
                                    <?php endif; ?>
                                    <option value="processing" <?php echo $request['status'] == 'processing' ? 'selected' : ''; ?>>Processing</option>
                                    <option value="completed" <?php echo $request['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="rejected" <?php echo $request['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="admin_response" class="form-label">Admin Remarks</label>
                                <textarea class="form-control" id="admin_response" name="admin_response" rows="4" placeholder="Add your remarks here..."></textarea>
                                <small class="text-muted">User will be notified of your remarks</small>
                            </div>

                            <?php 
                            $is_sacramental_form = SacramentalApprovalService::isSacramentalRequestType((string)($request['request_type'] ?? ''));
                            if ($is_sacramental_form): 
                                $priest_roster = getParishPriestRoster($conn);
                                $default_parish_priest = getParishPriestName($conn);
                            ?>
                                <div class="mb-3" id="ministerSelectGroup">
                                    <label for="officiating_priest" class="form-label fw-bold">
                                        Minister / Officiating Priest <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="officiating_priest" name="officiating_priest">
                                        <option value="">-- Select Minister (Who performed baptism) --</option>
                                        <?php foreach ($priest_roster as $p_opt): ?>
                                            <option value="<?php echo htmlspecialchars($p_opt); ?>">
                                                <?php echo htmlspecialchars($p_opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Select the priest who officiated the sacrament</small>
                                    <div class="invalid-feedback d-none text-danger small mt-1" id="ministerError">
                                        <i class="fas fa-circle-exclamation me-1"></i> Minister must be selected before approval or completion.
                                    </div>
                                </div>

                                <div class="mb-3" id="parishPriestSelectGroup">
                                    <label for="parish_priest" class="form-label fw-bold">
                                        Parish Priest <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="parish_priest" name="parish_priest">
                                        <option value="">-- Select Parish Priest --</option>
                                        <?php foreach ($priest_roster as $p_opt): ?>
                                            <option value="<?php echo htmlspecialchars($p_opt); ?>" <?php echo ($p_opt === $default_parish_priest) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($p_opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Confirm the Parish Priest for official registry signing</small>
                                    <div class="invalid-feedback d-none text-danger small mt-1" id="parishPriestError">
                                        <i class="fas fa-circle-exclamation me-1"></i> Parish Priest must be assigned before approval or completion.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="d-grid gap-2">
                                <button type="submit" name="action" value="complete" class="action-btn btn-complete" onclick="return validateSacramentalPriests('complete');">
                                    <i class="fas fa-circle-check"></i> Complete & Schedule
                                </button>
                                <button type="submit" name="action" value="request_more" class="action-btn btn-more-info" onclick="return confirm('Request more information?');">
                                    <i class="fas fa-question"></i> Request Info
                                </button>
                                <button type="submit" name="action" value="reject" class="action-btn btn-reject" onclick="return confirm('Reject this request?');">
                                    <i class="fas fa-times"></i> Reject Request
                                </button>
                            </div>
                        </form>

                        <script>
                        function validateSacramentalPriests(actionType) {
                            const isSacramental = <?php echo $is_sacramental_form ? 'true' : 'false'; ?>;
                            const statusSelect = document.getElementById('status');
                            const targetStatus = statusSelect ? statusSelect.value : '';

                            if (isSacramental && (actionType === 'complete' || targetStatus === 'completed' || targetStatus === 'approved')) {
                                const ministerEl = document.getElementById('officiating_priest');
                                const parishPriestEl = document.getElementById('parish_priest');
                                const ministerErr = document.getElementById('ministerError');
                                const parishPriestErr = document.getElementById('parishPriestError');

                                let hasError = false;

                                if (ministerEl && (!ministerEl.value.trim() || ministerEl.value === 'Rev. Fr. Parish Priest')) {
                                    ministerEl.classList.add('is-invalid');
                                    if (ministerErr) ministerErr.classList.remove('d-none');
                                    ministerEl.focus();
                                    hasError = true;
                                } else {
                                    if (ministerEl) ministerEl.classList.remove('is-invalid');
                                    if (ministerErr) ministerErr.classList.add('d-none');
                                }

                                if (parishPriestEl && !parishPriestEl.value.trim()) {
                                    parishPriestEl.classList.add('is-invalid');
                                    if (parishPriestErr) parishPriestErr.classList.remove('d-none');
                                    if (!hasError) parishPriestEl.focus();
                                    hasError = true;
                                } else {
                                    if (parishPriestEl) parishPriestEl.classList.remove('is-invalid');
                                    if (parishPriestErr) parishPriestErr.classList.add('d-none');
                                }

                                if (hasError) {
                                    alert('Both Minister and Parish Priest must be assigned before marking this record as Approved or Completed.');
                                    return false;
                                }
                            }

                            return confirm('Mark this request as ' + (actionType === 'complete' ? 'completed and sync schedule' : actionType) + '?');
                        }
                        </script>
                    </div>
                </div>

                <!-- Help Section -->
                <div class="request-card">
                    <div class="card-header-holy">
                        <h5 class="mb-0">
                            <i class="fas fa-info-circle"></i> GUIDE
                        </h5>
                    </div>
                    <div class="card-body">
                        <p style="font-size: 13px; color: #555;">
                            <strong style="color: #1E3A5F;">Approve:</strong> Request is validated and approved<br>
                            <strong style="color: #1E3A5F;">Reject:</strong> Request is denied<br>
                            <strong style="color: #1E3A5F;">Request Info:</strong> Need more details from user<br>
                            <strong style="color: #1E3A5F;">Complete:</strong> Final status - request fulfilled
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
