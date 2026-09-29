<?php
/**
 * Parishioner Certificate Download & Print Module
 *
 * Allows authorized parishioners to preview, print, or download official
 * sacramental certificates (e.g. Baptism) once their request status is Completed.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../services/CertificatePdfService.php';

requireLogin();

$user_id = intval($_SESSION['user_id'] ?? 0);
$request_id = intval($_GET['request_id'] ?? ($_GET['id'] ?? 0));
$record_id = intval($_GET['record_id'] ?? 0);
$cert_type = trim($_GET['type'] ?? 'baptism');
$download_pdf = isset($_GET['download']) || isset($_GET['pdf']);

$pdfService = new CertificatePdfService($conn);
$request = null;
$record = null;

if ($request_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM requests WHERE request_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$can_manage_all = hasPermission('requests.manage') 
    || isAdmin() 
    || isBackOfficeUser() 
    || (isset($_SESSION['role']) && in_array(strtolower((string)$_SESSION['role']), ['admin', 'administrator', 'staff', 'coordinator'], true));

if ($request) {
    $owns_request = ($user_id > 0 && intval($request['user_id']) === $user_id);
    if (!$can_manage_all && !$owns_request) {
        http_response_code(403);
        $_SESSION['error'] = 'Access denied. You do not own this request.';
        redirect('my-requests.php');
    }

    $req_status = strtolower($request['status'] ?? '');
    if (!in_array($req_status, ['completed', 'released', 'approved'], true)) {
        http_response_code(400);
        $_SESSION['error'] = 'Certificate generation is only available once your request is Completed by the parish office.';
        redirect('view-request.php?id=' . $request_id);
    }

    // Resolve linked baptism record
    $bap_id = intval($request['matched_record_id'] ?? 0);
    if ($bap_id > 0) {
        $b_stmt = $conn->prepare("SELECT * FROM baptism_records WHERE baptism_id = ? AND status = 'active' LIMIT 1");
        if ($b_stmt) {
            $b_stmt->bind_param('i', $bap_id);
            $b_stmt->execute();
            $record = $b_stmt->get_result()->fetch_assoc();
            $b_stmt->close();
        }
    }

    if (!$record) {
        $b_stmt2 = $conn->prepare("SELECT * FROM baptism_records WHERE request_id = ? AND status = 'active' LIMIT 1");
        if ($b_stmt2) {
            $b_stmt2->bind_param('i', $request_id);
            $b_stmt2->execute();
            $record = $b_stmt2->get_result()->fetch_assoc();
            $b_stmt2->close();
        }
    }

    if (!$record && !empty($request['record_holder_name'])) {
        $holderName = trim($request['record_holder_name']);
        $b_stmt3 = $conn->prepare("SELECT * FROM baptism_records WHERE fullname = ? AND status = 'active' ORDER BY baptism_id DESC LIMIT 1");
        if ($b_stmt3) {
            $b_stmt3->bind_param('s', $holderName);
            $b_stmt3->execute();
            $record = $b_stmt3->get_result()->fetch_assoc();
            $b_stmt3->close();
        }
    }
} elseif ($can_manage_all && $record_id > 0) {
    $b_stmt = $conn->prepare("SELECT * FROM baptism_records WHERE baptism_id = ? AND status = 'active' LIMIT 1");
    if ($b_stmt) {
        $b_stmt->bind_param('i', $record_id);
        $b_stmt->execute();
        $record = $b_stmt->get_result()->fetch_assoc();
        $b_stmt->close();
    }
}

if (!$record) {
    $_SESSION['error'] = 'Official baptism record could not be found for this request. Please contact the parish office.';
    if ($request_id > 0) {
        redirect('view-request.php?id=' . $request_id);
    } else {
        redirect('my-requests.php');
    }
}

// Pass linked request reference number if present
if ($request && !empty($request['reference_number'])) {
    $record['request_id'] = $request['request_id'];
}

// Validate completeness of record data
$missing_fields = $pdfService->validateBaptismRecord($record);

if (!empty($missing_fields)) {
    $page_title = 'Certificate Generation Incomplete';
    include __DIR__ . '/../templates/header.php';
    ?>
    <div class="container py-5" style="max-width: 680px;">
        <div class="card border-0 shadow-sm rounded-4 p-4 text-center" style="border: 1px solid #fed7aa !important; background: #fffaf0;">
            <div class="mb-3">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning-subtle text-warning-emphasis" style="width: 64px; height: 64px; font-size: 1.8rem;">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
            </div>
            <h4 class="fw-bold text-dark mb-2" style="font-family: 'Playfair Display', Georgia, serif;">Certificate Incomplete</h4>
            <p class="text-secondary mb-3" style="font-size: 0.95rem;">
                This official Certificate of Baptism cannot be released yet because certain required sacramental registry fields are incomplete in the parish records:
            </p>
            <div class="p-3 bg-white rounded-3 border mb-4 text-start" style="border-color: #fde68a !important;">
                <div class="fw-bold text-danger mb-2 small text-uppercase" style="letter-spacing: 0.5px;">Missing Required Fields:</div>
                <ul class="mb-0 ps-3 small text-secondary">
                    <?php foreach ($missing_fields as $f): ?>
                        <li class="fw-semibold text-dark"><?php echo htmlspecialchars($f); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <p class="small text-muted mb-4">
                Please contact the parish office or secretary to update these registry details so your official certificate can be generated.
            </p>
            <div class="d-flex justify-content-center gap-2">
                <?php if ($request_id > 0): ?>
                    <a href="view-request.php?id=<?php echo $request_id; ?>" class="btn btn-secondary px-4 fw-semibold rounded-3">
                        <i class="fas fa-arrow-left me-1"></i> Back to Request
                    </a>
                <?php else: ?>
                    <a href="my-requests.php" class="btn btn-secondary px-4 fw-semibold rounded-3">
                        <i class="fas fa-arrow-left me-1"></i> My Requests
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/../templates/footer.php';
    exit;
}

// If download requested: stream PDF via Dompdf
if ($download_pdf) {
    $pdfService->streamBaptismPdf($record, '', true);
    exit;
}

// Otherwise: render print-ready certificate HTML view with toolbar
$certHtml = $pdfService->renderBaptismCertificateHtml($record);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Certificate of Baptism - <?php echo htmlspecialchars($record['fullname']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #2b2b2b;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .user-cert-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 60px;
            background: rgba(20, 20, 20, 0.92);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            z-index: 1000;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.35);
        }
        .user-cert-container {
            margin-top: 76px;
            margin-bottom: 40px;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        @media print {
            .user-cert-toolbar {
                display: none !important;
            }
            .user-cert-container {
                margin: 0 !important;
                padding: 0 !important;
            }
            body {
                background: #ffffff !important;
            }
        }
    </style>
</head>
<body>
    <header class="user-cert-toolbar">
        <div class="d-flex align-items-center gap-2 text-white">
            <i class="fas fa-certificate text-warning fs-5"></i>
            <span class="fw-bold" style="font-size: 0.95rem;">Official Certificate of Baptism</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="download-certificate.php?request_id=<?php echo $request_id; ?>&download=1" class="btn btn-success btn-sm fw-bold px-3">
                <i class="fas fa-download me-1"></i> Download PDF
            </a>
            <button type="button" class="btn btn-primary btn-sm fw-bold px-3" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print Certificate
            </button>
            <?php if ($request_id > 0): ?>
                <a href="view-request.php?id=<?php echo $request_id; ?>" class="btn btn-outline-light btn-sm px-3">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
            <?php else: ?>
                <a href="my-requests.php" class="btn btn-outline-light btn-sm px-3">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
            <?php endif; ?>
        </div>
    </header>

    <main class="user-cert-container">
        <!-- Render exact certificate -->
        <?php
        // Extract inner frame from the generated HTML
        if (preg_match('/<div class="cert-outer-frame">.*?<\/div>\s*<\/body>/s', $certHtml, $m)) {
            echo str_replace('</body>', '', $m[0]);
        } else {
            echo $certHtml;
        }
        ?>
    </main>
</body>
</html>
