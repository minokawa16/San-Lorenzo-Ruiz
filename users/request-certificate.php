<?php
/**
 * Certificate Request Module - Handles sacramental certificate request forms and requirements.
 */
include '../includes/session.php';
include '../config/security.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();
if (!hasPermission('requests.create')) {
    redirect('../auth/login.php');
}

$page_title = 'Request Certificate';
$user_id = intval($_SESSION['user_id']);
$error = '';
$success = '';
ensureRequestDocumentsSchema($conn);
ensureEmailNotificationSchema($conn);

$certificate_types = [
    'baptismal_certificate' => 'Baptismal Certificate',
    'confirmation_certificate' => 'Confirmation Certificate',
    'first_communion_certificate' => 'First Communion Certificate',
];
$certificate_meta = [
    'baptismal_certificate' => [
        'icon' => 'fa-water',
        'title' => 'Baptismal Certificate',
        'hint' => 'Often used for school, marriage, or personal sacramental records.'
    ],
    'confirmation_certificate' => [
        'icon' => 'fa-cross',
        'title' => 'Confirmation Certificate',
        'hint' => 'Useful for marriage preparation and parish record verification.'
    ],
    'first_communion_certificate' => [
        'icon' => 'fa-dove',
        'title' => 'First Communion Certificate',
        'hint' => 'Request a certified copy of your First Communion record.'
    ],
];
$certificate_required_document = 'Copy of PSA / Birth Certificate';
$status_meta = [
    'pending' => ['icon' => 'fa-hourglass-half', 'description' => 'Waiting for parish review', 'tone' => 'warning'],
    'approved' => ['icon' => 'fa-circle-check', 'description' => 'Approved by the office', 'tone' => 'success'],
    'processing' => ['icon' => 'fa-gears', 'description' => 'Being prepared', 'tone' => 'primary'],
    'completed' => ['icon' => 'fa-file-circle-check', 'description' => 'Ready or released', 'tone' => 'info'],
    'rejected' => ['icon' => 'fa-circle-xmark', 'description' => 'Needs correction', 'tone' => 'danger'],
    'cancelled' => ['icon' => 'fa-ban', 'description' => 'Cancelled request', 'tone' => 'secondary'],
];
$certificate_type_keys = array_keys($certificate_types);
$allowed_statuses = ['pending', 'approved', 'processing', 'completed', 'rejected', 'cancelled'];

$breadcrumbs = [
    'Dashboard' => 'index.php',
    'Certificates' => null
];

// Certificate Label Function - Documents this helper's role in the parish management workflow.
function certificateLabel($value, $labels = []) {
    return $labels[$value] ?? ucfirst(str_replace('_', ' ', (string) $value));
}

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $request_type = $_POST['request_type'] ?? '';
    $description = trim($_POST['description'] ?? '');

    if (!array_key_exists($request_type, $certificate_types)) {
        $error = 'Please select a certificate type.';
    } elseif (!requestUploadHasFiles($_FILES['requirement_files'] ?? null)) {
        $error = 'Please upload a copy of the PSA / Birth Certificate before submitting your certificate request.';
    } else {
        $description_parts = [
            'Required document: ' . $certificate_required_document,
            'Details: ' . ($description ?: 'None')
        ];
        $description = implode("\n", $description_parts);
        $reference_number = generateReferenceNumber();
        $status = 'pending';
        $stmt = $conn->prepare("INSERT INTO requests (user_id, request_type, description, status, reference_number) VALUES (?, ?, ?, ?, ?)");

        if (!$stmt) {
            $error = 'Unable to prepare your certificate request. Please contact the parish office.';
        } else {
            $stmt->bind_param('issss', $user_id, $request_type, $description, $status, $reference_number);
            if ($stmt->execute()) {
                $request_id = $conn->insert_id;
                $documents = saveMultipleRequirementDocuments($conn, $request_id, $user_id, $_FILES['requirement_files'] ?? null);
                if (!$documents['ok'] && empty($documents['saved'])) {
                    $error = $documents['error'] . ' Your request was saved, but the files were not attached. Reference: ' . $reference_number;
                } else {
                    createAuditLog($conn, $user_id, 'CREATE_REQUEST', 'requests', $request_id);
                    $doc_count = intval($documents['saved'] ?? 0);
                    $file_text = $doc_count === 1 ? 'file' : 'files';
                    createNotification($conn, $user_id, 'Certificate Request Created', 'Your certificate request has been submitted with reference: ' . $reference_number . ' (' . $doc_count . ' ' . $file_text . ' attached)');
                    sendRequestSubmittedEmail($conn, $user_id, $reference_number, 'certificate request');
                    $success = 'Certificate request submitted successfully! Reference: ' . $reference_number . ' (' . $doc_count . ' file' . ($doc_count === 1 ? '' : 's') . ' attached)';
                }
            } else {
                $error = 'Error submitting certificate request: ' . $conn->error;
            }
            $stmt->close();
        }
    }
}

$page = intval($_GET['page'] ?? 1);
$limit = 10;
$search = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$status = in_array($status, $allowed_statuses, true) ? $status : '';
$search_like = '%' . $search . '%';
$certificate_placeholders = implode(',', array_fill(0, count($certificate_type_keys), '?'));

$where = ['user_id = ?', "request_type IN ($certificate_placeholders)"];
$types = 'i' . str_repeat('s', count($certificate_type_keys));
$params = array_merge([$user_id], $certificate_type_keys);

if ($status !== '') {
    $where[] = 'status = ?';
    $types .= 's';
    $params[] = $status;
}

if ($search !== '') {
    $where[] = '(reference_number LIKE ? OR request_type LIKE ? OR status LIKE ? OR description LIKE ?)';
    $types .= 'ssss';
    array_push($params, $search_like, $search_like, $search_like, $search_like);
}

$where_sql = implode(' AND ', $where);
$total = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS count FROM requests WHERE $where_sql");
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $total = intval(($result->fetch_assoc())['count'] ?? 0);
    $stmt->close();
}

$pagination = getPaginationData($page, $limit, $total);
$certificates = [];
$list_types = $types . 'ii';
$list_params = array_merge($params, [$pagination['offset'], $pagination['limit']]);

$stmt = $conn->prepare("
    SELECT request_id, reference_number, request_type, description, status, date_requested, updated_at
    FROM requests
    WHERE $where_sql
    ORDER BY date_requested DESC
    LIMIT ?, ?
");
if ($stmt) {
    $stmt->bind_param($list_types, ...$list_params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $certificates[] = $row;
    }
    $stmt->close();
}

$status_counts = array_fill_keys($allowed_statuses, 0);
$count_types = 'i' . str_repeat('s', count($certificate_type_keys));
$count_params = array_merge([$user_id], $certificate_type_keys);
$stmt = $conn->prepare("SELECT status, COUNT(*) AS count FROM requests WHERE user_id = ? AND request_type IN ($certificate_placeholders) GROUP BY status");
if ($stmt) {
    $stmt->bind_param($count_types, ...$count_params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (isset($status_counts[$row['status']])) {
            $status_counts[$row['status']] = intval($row['count']);
        }
    }
    $stmt->close();
}
?>
<?php include '../templates/header.php'; ?>

<?php include '../includes/breadcrumb.php'; ?>
<?php include '../includes/back_button.php'; ?>

<style>
    .certificate-page {
        max-width: 1440px;
        margin: 0 auto;
    }

    .certificate-hero {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(280px, 0.85fr);
        gap: 18px;
        align-items: stretch;
        margin-bottom: 18px;
    }

    .certificate-hero-main,
    .secure-note {
        border: 1px solid rgba(23, 32, 51, 0.1);
        border-radius: 8px;
        background: #ffffff;
        box-shadow: 0 14px 34px rgba(30, 41, 59, 0.08);
    }

    .certificate-hero-main {
        padding: 24px;
        border-top: 4px solid #d7ad43;
    }

    .certificate-hero-main h1 {
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 0 0 8px;
        color: #172033;
        font-size: 1.8rem;
        font-weight: 850;
    }

    .certificate-hero-main p {
        max-width: 720px;
        margin: 0;
        color: #667085;
        line-height: 1.6;
    }

    .hero-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 16px;
    }

    .hero-badges span,
    .section-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 32px;
        padding: 6px 11px;
        border-radius: 999px;
        color: #80611b;
        background: #fff8df;
        border: 1px solid rgba(215, 173, 67, 0.28);
        font-size: 0.78rem;
        font-weight: 800;
    }

    .secure-note {
        padding: 20px;
        display: grid;
        align-content: center;
        gap: 10px;
    }

    .secure-note i {
        width: 42px;
        height: 42px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        color: #17446a;
        background: #eef5fb;
    }

    .secure-note strong {
        color: #172033;
        font-size: 1rem;
    }

    .secure-note p {
        color: #667085;
        margin: 0;
        line-height: 1.55;
    }

    .certificate-status-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 18px;
    }

    .certificate-status-card {
        display: grid;
        gap: 12px;
        min-height: 142px;
        padding: 16px;
        border: 1px solid rgba(23, 32, 51, 0.1);
        border-radius: 8px;
        color: inherit;
        background: #ffffff;
        text-decoration: none;
        box-shadow: 0 10px 24px rgba(30, 41, 59, 0.06);
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
    }

    .certificate-status-card:hover,
    .certificate-status-card:focus {
        color: inherit;
        text-decoration: none;
        transform: translateY(-3px);
        border-color: rgba(215, 173, 67, 0.48);
        box-shadow: 0 16px 34px rgba(30, 41, 59, 0.1);
    }

    .status-card-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }

    .status-card-top i {
        width: 38px;
        height: 38px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        background: #f8fafc;
    }

    .certificate-status-card strong {
        color: #172033;
        font-size: 1.75rem;
        line-height: 1;
    }

    .certificate-status-card span {
        color: #172033;
        font-weight: 850;
    }

    .certificate-status-card small {
        color: #667085;
        line-height: 1.35;
    }

    .certificate-form-card,
    .filter-card,
    .history-card {
        border: 1px solid rgba(23, 32, 51, 0.1);
        border-radius: 8px;
        background: #ffffff;
        box-shadow: 0 14px 34px rgba(30, 41, 59, 0.08);
        overflow: hidden;
    }

    .certificate-form-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 22px 24px;
        color: #172033;
        background: linear-gradient(135deg, #fffdf7, #fff8df 48%, #eef5fb);
        border-bottom: 1px solid rgba(23, 32, 51, 0.08);
    }

    .certificate-form-header h2,
    .section-title {
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        color: #172033;
        font-size: 1.15rem;
        font-weight: 850;
    }

    .certificate-form-header p {
        margin: 5px 0 0;
        color: #667085;
    }

    .form-step {
        padding: 22px 24px;
        border-bottom: 1px solid rgba(23, 32, 51, 0.08);
    }

    .form-step:last-of-type {
        border-bottom: 0;
    }

    .step-heading {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }

    .step-number {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        color: #171205;
        background: linear-gradient(135deg, #fff8df, #d7ad43);
        font-weight: 900;
    }

    .step-heading h3 {
        margin: 0;
        color: #172033;
        font-size: 1rem;
        font-weight: 850;
    }

    .step-heading p {
        margin: 2px 0 0;
        color: #667085;
        font-size: 0.88rem;
    }

    .certificate-option-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }

    .certificate-option {
        position: relative;
        display: block;
        cursor: pointer;
    }

    .certificate-option input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .certificate-option span {
        position: relative;
        min-height: 142px;
        display: grid;
        gap: 8px;
        padding: 16px;
        border: 1px solid rgba(23, 32, 51, 0.1);
        border-radius: 8px;
        background: #ffffff;
        transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
    }

    .certificate-option i {
        width: 42px;
        height: 42px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        color: #17446a;
        background: #eef5fb;
    }

    .certificate-option strong {
        color: #172033;
        font-size: 0.96rem;
    }

    .certificate-option small {
        color: #667085;
        line-height: 1.4;
    }

    .certificate-option:hover span,
    .certificate-option input:focus + span,
    .certificate-option input:checked + span {
        transform: translateY(-2px);
        border-color: rgba(215, 173, 67, 0.6);
        box-shadow: 0 14px 28px rgba(30, 41, 59, 0.08);
    }

    .certificate-option input:checked + span::after {
        content: "\f00c";
        font-family: "Font Awesome 6 Free";
        font-weight: 900;
        position: absolute;
        top: 12px;
        right: 12px;
        width: 28px;
        height: 28px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        color: #171205;
        background: #d7ad43;
    }

    .request-form-control {
        min-height: 46px;
        border-radius: 8px;
        border-color: #dfe4ea;
    }

    .input-with-icon {
        position: relative;
    }

    .input-with-icon i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        z-index: 1;
    }

    .input-with-icon .form-control {
        padding-left: 42px;
    }

    .upload-zone {
        position: relative;
        display: grid;
        place-items: center;
        gap: 8px;
        min-height: 190px;
        padding: 24px;
        border: 1px dashed #b6c4d4;
        border-radius: 8px;
        background: #f8fafc;
        text-align: center;
        cursor: pointer;
        transition: border-color 0.18s ease, background 0.18s ease, transform 0.18s ease;
    }

    .upload-zone:hover,
    .upload-zone.is-dragover {
        border-color: #d7ad43;
        background: #fffdf7;
        transform: translateY(-1px);
    }

    .upload-zone input {
        position: absolute;
        inset: 0;
        opacity: 0;
        cursor: pointer;
    }

    .upload-zone i {
        width: 52px;
        height: 52px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        color: #17446a;
        background: #eef5fb;
        font-size: 1.25rem;
    }

    .upload-zone strong {
        color: #172033;
        font-size: 1rem;
    }

    .upload-zone small {
        color: #667085;
    }

    .file-preview {
        display: none;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-top: 12px;
        padding: 12px;
        border: 1px solid rgba(23, 32, 51, 0.1);
        border-radius: 8px;
        background: #ffffff;
    }

    .file-preview.is-visible {
        display: flex;
    }

    .file-preview span {
        color: #172033;
        font-weight: 800;
    }

    .upload-progress {
        height: 6px;
        width: 140px;
        border-radius: 999px;
        background: #e2e8f0;
        overflow: hidden;
    }

    .upload-progress span {
        display: block;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, #17446a, #d7ad43);
    }

    .form-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        padding: 20px 24px 24px;
    }

    .privacy-copy {
        color: #667085;
        font-size: 0.88rem;
    }

    .submit-request-btn {
        min-height: 48px;
        border: 0;
        border-radius: 8px;
        padding: 12px 20px;
        color: #171205;
        font-weight: 900;
        background: linear-gradient(135deg, #fff8df, #f7df9e 45%, #d7ad43);
        box-shadow: 0 16px 34px rgba(215, 173, 67, 0.24);
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .submit-request-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 20px 42px rgba(215, 173, 67, 0.3);
    }

    .submit-request-btn.is-loading .submit-label {
        display: none;
    }

    .submit-request-btn .submit-loading {
        display: none;
    }

    .submit-request-btn.is-loading .submit-loading {
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .filter-card {
        padding: 16px;
        margin: 18px 0;
    }

    .quick-status-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }

    .quick-status-tabs a {
        min-height: 34px;
        display: inline-flex;
        align-items: center;
        padding: 6px 11px;
        border-radius: 999px;
        border: 1px solid #dfe4ea;
        color: #334155;
        background: #ffffff;
        text-decoration: none;
        font-size: 0.82rem;
        font-weight: 800;
    }

    .quick-status-tabs a.active,
    .quick-status-tabs a:hover {
        color: #171205;
        background: #fff8df;
        border-color: rgba(215, 173, 67, 0.45);
    }

    .history-card {
        padding: 18px;
    }

    .history-table thead th {
        color: #667085;
        background: #f8fafc;
        border-bottom: 1px solid #dfe4ea;
        font-size: 0.76rem;
        font-weight: 850;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .empty-state {
        padding: 44px 18px;
        text-align: center;
        color: #667085;
    }

    .empty-state i {
        width: 64px;
        height: 64px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 14px;
        border-radius: 8px;
        color: #17446a;
        background: #eef5fb;
        font-size: 1.7rem;
    }

    .success-reference {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px;
        justify-content: space-between;
    }

    @media (max-width: 1180px) {
        .certificate-status-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .certificate-option-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .certificate-hero {
            grid-template-columns: 1fr;
        }

        .certificate-status-grid {
            grid-template-columns: 1fr;
        }

        .certificate-form-header,
        .form-actions {
            align-items: flex-start;
            flex-direction: column;
        }

        .form-actions .submit-request-btn {
            width: 100%;
            position: sticky;
            bottom: 12px;
            z-index: 3;
        }
    }
</style>

<div class="container-fluid mt-4">
    <div class="certificate-page">
        <section class="certificate-hero">
            <div class="certificate-hero-main">
                <span class="section-kicker"><i class="fas fa-certificate"></i> Certificate Services</span>
                <h1>New Certificate Request</h1>
                <p>Submit sacramental certificate requests securely and efficiently. Attach a copy of your PSA / Birth Certificate and monitor your request status in one place.</p>
                <div class="hero-badges">
                    <span><i class="fas fa-lock"></i> Secure Request Submission</span>
                    <span><i class="fas fa-bell"></i> Status Notifications</span>
                    <span><i class="fas fa-robot"></i> TUGON AI Assisted</span>
                </div>
            </div>
            <aside class="secure-note">
                <i class="fas fa-shield-halved"></i>
                <strong>Your uploaded documents are protected.</strong>
                <p>Your PSA / Birth Certificate copy is stored through the parish document workflow and used only for certificate verification and processing.</p>
            </aside>
        </section>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <?php preg_match('/Reference:\s*([A-Z0-9-]+)/', $success, $reference_match); ?>
        <div class="alert alert-success alert-dismissible fade show">
            <div class="success-reference">
                <span><i class="fas fa-circle-check"></i> <?php echo e($success); ?> Estimated processing time: 3 to 5 parish office days.</span>
                <?php if (!empty($reference_match[1])): ?>
                    <a class="btn btn-sm btn-outline-success" href="my-requests.php?q=<?php echo urlencode($reference_match[1]); ?>">
                        <i class="fas fa-receipt"></i> Track <?php echo e($reference_match[1]); ?>
                    </a>
                <?php endif; ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="certificate-status-grid">
        <?php foreach ($status_counts as $status_name => $count): ?>
            <?php $status_info = $status_meta[$status_name] ?? ['icon' => 'fa-circle', 'description' => 'Request status', 'tone' => 'secondary']; ?>
            <a class="certificate-status-card" href="?status=<?php echo urlencode($status_name); ?>">
                <div class="status-card-top">
                    <i class="fas <?php echo e($status_info['icon']); ?> text-<?php echo e($status_info['tone']); ?>"></i>
                    <strong><?php echo intval($count); ?></strong>
                </div>
                <span><?php echo e(certificateLabel($status_name)); ?></span>
                <small><?php echo e($status_info['description']); ?></small>
            </a>
        <?php endforeach; ?>
    </section>

    <div class="certificate-form-card">
        <div class="certificate-form-header">
            <div>
                <h2><i class="fas fa-file-signature"></i> Certificate Request Form</h2>
                <p>Complete the sections below so the parish office can validate and prepare your certificate.</p>
            </div>
            <span class="section-kicker"><i class="fas fa-clock"></i> 3 to 5 office days</span>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" id="certificateRequestForm">
            <?php echo csrfInput(); ?>
            <section class="form-step">
                <div class="step-heading">
                    <span class="step-number">1</span>
                    <div>
                        <h3>Certificate Information</h3>
                        <p>Select the sacramental certificate you need.</p>
                    </div>
                </div>

                <div class="certificate-option-grid" role="radiogroup" aria-label="Certificate type">
                    <?php foreach ($certificate_meta as $value => $meta): ?>
                        <label class="certificate-option">
                            <input type="radio" name="request_type" value="<?php echo e($value); ?>" required>
                            <span>
                                <i class="fas <?php echo e($meta['icon']); ?>"></i>
                                <strong><?php echo e($meta['title']); ?></strong>
                                <small><?php echo e($meta['hint']); ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="mt-3">
                    <label for="certificateSearchSelect" class="form-label">Searchable certificate selector</label>
                    <input class="form-control request-form-control" id="certificateSearchSelect" list="certificateTypeOptions" placeholder="Type to search certificate type" autocomplete="off">
                    <datalist id="certificateTypeOptions">
                        <?php foreach ($certificate_types as $value => $label): ?>
                            <option value="<?php echo e($label); ?>" data-value="<?php echo e($value); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </section>

            <section class="form-step">
                <div class="step-heading">
                    <span class="step-number">2</span>
                    <div>
                        <h3>Applicant Details</h3>
                        <p>Confirm who is submitting this certificate request.</p>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-4">
                        <label class="form-label">Full Name</label>
                        <input type="text" class="form-control request-form-control" value="<?php echo e($_SESSION['fullname'] ?? ''); ?>" readonly>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control request-form-control" value="<?php echo e($_SESSION['email'] ?? ''); ?>" readonly>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label">Notification</label>
                        <div class="form-control request-form-control d-flex align-items-center gap-2 text-muted">
                            <i class="fas fa-bell"></i> Updates appear in Notifications.
                        </div>
                    </div>
                </div>
            </section>

            <section class="form-step">
                <div class="step-heading">
                    <span class="step-number">3</span>
                    <div>
                        <h3>Certificate Details</h3>
                        <p>Add dates, parents' names, purpose, or parish details that can help staff verify the record.</p>
                    </div>
                </div>

                <label for="description" class="form-label">Purpose and supporting details</label>
                <textarea class="form-control request-form-control" id="description" name="description" rows="5" placeholder="Example: For marriage requirements. Baptized around June 2005. Parents: Juan and Maria Santos."></textarea>
                <div class="form-text"><i class="fas fa-wand-magic-sparkles"></i> TUGON tip: include approximate sacrament date, parents' names, and intended purpose when available.</div>
            </section>

            <section class="form-step">
                <div class="step-heading">
                    <span class="step-number">4</span>
                    <div>
                        <h3>Upload Requirement</h3>
                        <p>The only required document for certificate requests is a copy of the PSA / Birth Certificate.</p>
                    </div>
                </div>

                <label class="upload-zone" id="uploadZone" for="requirement_files">
                    <i class="fas fa-cloud-arrow-up"></i>
                    <strong>Upload copy of PSA / Birth Certificate.</strong>
                    <small>Accepted formats: PDF, JPG, or PNG. Maximum 10MB per file. This is required for all certificate requests.</small>
                    <input type="file" id="requirement_files" name="requirement_files[]" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>
                </label>
                <div class="file-preview" id="filePreview">
                    <div id="fileList">
                        <span id="fileName">Selected files</span>
                        <div class="text-muted small" id="fileSize">Ready to upload</div>
                    </div>
                    <div class="upload-progress" aria-hidden="true"><span></span></div>
                </div>
            </section>

            <div class="form-actions">
                <div class="privacy-copy">
                    <i class="fas fa-lock"></i> Secure parish request submission. Please ensure all details are accurate before submitting.
                </div>
                <button type="submit" class="submit-request-btn" id="submitRequestBtn">
                    <span class="submit-label"><i class="fas fa-paper-plane"></i> Submit Certificate Request</span>
                    <span class="submit-loading"><i class="fas fa-spinner fa-spin"></i> Submitting Request</span>
                </button>
            </div>
        </form>
    </div>

    <form class="filter-card" method="GET" action="">
        <div class="row g-2 align-items-center">
            <div class="col-lg-6">
                <label class="form-label">Search requests</label>
                <div class="input-with-icon">
                    <i class="fas fa-search"></i>
                    <input type="text" class="form-control request-form-control" name="q" value="<?php echo e($search); ?>" placeholder="Search certificate, status, details, or reference number">
                </div>
            </div>
            <div class="col-lg-3">
                <label class="form-label">Status filter</label>
                <select class="form-select request-form-control" name="status">
                    <option value="">All Statuses</option>
                    <?php foreach ($allowed_statuses as $status_option): ?>
                        <option value="<?php echo e($status_option); ?>" <?php echo $status === $status_option ? 'selected' : ''; ?>>
                            <?php echo e(certificateLabel($status_option)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 d-grid d-md-flex gap-2">
                <button class="btn btn-primary align-self-end" type="submit"><i class="fas fa-search"></i> Filter</button>
                <?php if ($search !== '' || $status !== ''): ?>
                    <a class="btn btn-outline-secondary align-self-end" href="request-certificate.php">Clear</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="quick-status-tabs">
            <a href="request-certificate.php" class="<?php echo $status === '' ? 'active' : ''; ?>">All</a>
            <?php foreach ($allowed_statuses as $status_option): ?>
                <a href="?status=<?php echo urlencode($status_option); ?>" class="<?php echo $status === $status_option ? 'active' : ''; ?>"><?php echo e(certificateLabel($status_option)); ?></a>
            <?php endforeach; ?>
        </div>
    </form>

    <div class="history-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="section-title"><i class="fas fa-clock-rotate-left"></i> Certificate Request History</h2>
                <span class="text-muted"><?php echo intval($total); ?> total</span>
            </div>

            <?php if (!empty($certificates)): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle history-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Certificate</th>
                                <th>Status</th>
                                <th>Details</th>
                                <th>Submitted</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($certificates as $certificate): ?>
                                <tr onclick="window.location.href='view-request.php?id=<?php echo intval($certificate['request_id']); ?>'" style="cursor:pointer;">
                                    <td><strong><?php echo e($certificate['reference_number']); ?></strong></td>
                                    <td><?php echo e(certificateLabel($certificate['request_type'], $certificate_types)); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo getStatusBadgeClass($certificate['status']); ?>">
                                            <?php echo e(certificateLabel($certificate['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo nl2br(e($certificate['description'] ?: 'No details provided')); ?></td>
                                    <td><?php echo formatDateTime($certificate['date_requested']); ?></td>
                                    <td><?php echo formatDateTime($certificate['updated_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($pagination['total_pages'] > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                                <li class="page-item <?php echo $i == $pagination['page'] ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-file-circle-plus"></i>
                    <h5>No certificate requests yet.</h5>
                    <p class="mb-3">Your submitted certificate requests and status updates will appear here.</p>
                    <a href="#certificateRequestForm" class="btn btn-primary"><i class="fas fa-plus"></i> Submit your first request</a>
                </div>
            <?php endif; ?>
    </div>
    </div>
</div>

<script>
    (function() {
        const select = document.getElementById('certificateSearchSelect');
        const radios = document.querySelectorAll('input[name="request_type"]');
        const fileInput = document.getElementById('requirement_files');
        const uploadZone = document.getElementById('uploadZone');
        const filePreview = document.getElementById('filePreview');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        const form = document.getElementById('certificateRequestForm');
        const submitBtn = document.getElementById('submitRequestBtn');
        const certificateLabels = <?php echo json_encode($certificate_types); ?>;

        if (select) {
            // Sync Search Selection Function - Documents this helper's role in the parish management workflow.
            function syncSearchSelection() {
                const option = Array.from(document.querySelectorAll('#certificateTypeOptions option')).find(function(item) {
                    return item.value.toLowerCase() === select.value.toLowerCase();
                });
                const value = option ? option.dataset.value : '';
                const match = value ? document.querySelector('input[name="request_type"][value="' + value.replace(/"/g, '\\"') + '"]') : null;
                if (match) {
                    match.checked = true;
                    match.focus();
                }
            }

            select.addEventListener('change', syncSearchSelection);
            select.addEventListener('input', syncSearchSelection);

            radios.forEach(function(radio) {
                radio.addEventListener('change', function() {
                    select.value = certificateLabels[radio.value] || '';
                });
            });
        }

        // Render Requirement File Function
        function renderFiles(files) {
            if (!files || files.length === 0 || !filePreview) {
                return;
            }
            filePreview.classList.add('is-visible');
            
            let total_size = 0;
            let file_names = [];
            for (let i = 0; i < files.length; i++) {
                total_size += files[i].size;
                file_names.push(files[i].name);
            }
            
            fileName.textContent = file_names.length === 1 ? 'PSA / Birth Certificate copy selected' : file_names.length + ' files selected';
            fileSize.textContent = (total_size / 1024 / 1024).toFixed(2) + ' MB total';
            
            // Show file list as tooltip-like info
            if (file_names.length > 1) {
                fileSize.textContent += ' • ' + file_names.join(', ');
            }
        }

        if (fileInput) {
            fileInput.addEventListener('change', function() {
                renderFiles(fileInput.files);
            });
        }

        if (uploadZone) {
            ['dragenter', 'dragover'].forEach(function(eventName) {
                uploadZone.addEventListener(eventName, function(event) {
                    event.preventDefault();
                    uploadZone.classList.add('is-dragover');
                });
            });

            ['dragleave', 'drop'].forEach(function(eventName) {
                uploadZone.addEventListener(eventName, function(event) {
                    event.preventDefault();
                    uploadZone.classList.remove('is-dragover');
                });
            });

            uploadZone.addEventListener('drop', function(event) {
                if (event.dataTransfer.files.length && fileInput) {
                    fileInput.files = event.dataTransfer.files;
                    renderFiles(fileInput.files);
                }
            });
        }

        if (form && submitBtn) {
            form.addEventListener('submit', function() {
                submitBtn.classList.add('is-loading');
                submitBtn.disabled = true;
            });
        }
    })();
</script>

<?php include '../templates/footer.php'; ?>
