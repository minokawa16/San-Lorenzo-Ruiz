<?php
/**
 * Certificate Request Module - Handles sacramental certificate request forms and requirements.
 */
include '../includes/session.php';
include '../config/security.php';
include '../database/config.php';
include '../includes/helpers.php';
require_once __DIR__ . '/../services/RequestService.php';

requireLogin();
if (!hasPermission('requests.create')) {
    redirect('../auth/login.php');
}

$page_title = 'Request Certificate';
$body_extra_class = 'certificate-mobile-page';
$user_id = intval($_SESSION['user_id']);
$error = '';
$success = '';
$request_idempotency_key = bin2hex(random_bytes(32));
ensureRequestDocumentsSchema($conn);
ensureEmailNotificationSchema($conn);

$certificate_types = [
    'baptism_certification' => 'Baptismal Certification',
    'confirmation_certification' => 'Confirmation Certification',
    'first_communion_certification' => 'First Communion Certification',
    'marriage_certification' => 'Marriage Certification',
    'funeral_certification' => 'Funeral Certification',
    'baptismal_certificate' => 'Baptismal Certificate',
    'confirmation_certificate' => 'Confirmation Certificate',
    'first_communion_certificate' => 'First Communion Certificate',
];
$certificate_purposes = [
    'first_communion' => 'First Communion',
    'confirmation' => 'Confirmation',
    'marriage' => 'Marriage Preparation',
    'legal_purposes' => 'Legal / Personal Records',
    'burial_claim' => 'Burial / Estate / Benefits',
    'others' => 'Others',
];
$certificate_meta = [
    // Sacramental Certifications
    'baptism_certification' => [
        'category' => 'certification',
        'badge' => 'Registry Extract',
        'icon' => 'fa-file-signature',
        'icon_color' => '#0284c7',
        'icon_bg' => '#e0f2fe',
        'title' => 'Baptismal Certification',
        'hint' => 'Official certified extract from baptism registry records.'
    ],
    'confirmation_certification' => [
        'category' => 'certification',
        'badge' => 'Registry Extract',
        'icon' => 'fa-file-circle-check',
        'icon_color' => '#4338ca',
        'icon_bg' => '#e0e7ff',
        'title' => 'Confirmation Certification',
        'hint' => 'Official certified extract from confirmation registry records.'
    ],
    'first_communion_certification' => [
        'category' => 'certification',
        'badge' => 'Registry Extract',
        'icon' => 'fa-file-lines',
        'icon_color' => '#b45309',
        'icon_bg' => '#fef3c7',
        'title' => 'First Communion Certification',
        'hint' => 'Official certified extract from first communion records.'
    ],
    'marriage_certification' => [
        'category' => 'certification',
        'badge' => 'Registry Extract',
        'icon' => 'fa-ring',
        'icon_color' => '#dc2626',
        'icon_bg' => '#fef2f2',
        'title' => 'Marriage Certification',
        'hint' => 'Official certified extract from Holy Matrimony records.'
    ],
    'funeral_certification' => [
        'category' => 'certification',
        'badge' => 'Registry Extract',
        'icon' => 'fa-cross',
        'icon_color' => '#475569',
        'icon_bg' => '#f1f5f9',
        'title' => 'Funeral Certification',
        'hint' => 'Official certified extract from funeral/burial records.'
    ],

    // Sacramental Certificates
    'baptismal_certificate' => [
        'category' => 'certificate',
        'badge' => 'Canonical Certificate',
        'icon' => 'fa-water',
        'icon_color' => '#0284c7',
        'icon_bg' => '#e0f2fe',
        'title' => 'Baptismal Certificate',
        'hint' => 'Official canonical certificate of Holy Baptism.'
    ],
    'confirmation_certificate' => [
        'category' => 'certificate',
        'badge' => 'Canonical Certificate',
        'icon' => 'fa-cross',
        'icon_color' => '#4338ca',
        'icon_bg' => '#e0e7ff',
        'title' => 'Confirmation Certificate',
        'hint' => 'Official canonical certificate of Holy Confirmation.'
    ],
    'first_communion_certificate' => [
        'category' => 'certificate',
        'badge' => 'Canonical Certificate',
        'icon' => 'fa-wheat-awn',
        'icon_color' => '#b45309',
        'icon_bg' => '#fef3c7',
        'title' => 'First Communion Certificate',
        'hint' => 'Official canonical certificate of First Holy Communion.'
    ],
];
$certificate_required_document = 'Copy of PSA / Birth Certificate, Death Certificate, or Valid ID';
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
    $request_type = trim((string) ($_POST['request_type'] ?? $_POST['certificate_mobile_type'] ?? ''));
    $purpose = trim((string) ($_POST['purpose'] ?? ''));
    $purpose_other = trim((string) ($_POST['purpose_other'] ?? ''));

    if (!array_key_exists($request_type, $certificate_types)) {
        $error = 'Please select a certificate type.';
    } elseif (!array_key_exists($purpose, $certificate_purposes)) {
        $error = 'Please select the purpose of your certificate request.';
    } elseif ($purpose === 'others' && $purpose_other === '') {
        $error = 'Please specify the purpose of your certificate request.';
    } elseif ($purpose === 'others' && strlen($purpose_other) > 180) {
        $error = 'The custom purpose must be 180 characters or fewer.';
    } elseif (!requestUploadHasFiles($_FILES['requirement_files'] ?? null)) {
        $error = 'Please upload a copy of the required supporting document (e.g. PSA / Valid ID) before submitting your certificate request.';
    } else {
        $purpose_description = $purpose === 'others' ? $purpose_other : $certificate_purposes[$purpose];
        $description_parts = [
            'Required document: ' . $certificate_required_document,
            'Purpose: ' . $purpose_description
        ];
        $description = implode("\n", $description_parts);
        try {
            $idempotency_key = trim((string) ($_POST['idempotency_key'] ?? ''));
            if (empty($idempotency_key) || !preg_match('/^[a-f0-9]{64}$/', $idempotency_key)) {
                $idempotency_key = bin2hex(random_bytes(32));
            }
            $requestResult = (new RequestService($conn))->create(['request_type' => $request_type, 'description' => $description], $user_id, $idempotency_key);
            $request_id = (int) $requestResult['request_id'];
            $reference_number = $requestResult['reference_number'];
            $documents = saveMultipleRequirementDocuments($conn, $request_id, $user_id, $_FILES['requirement_files'] ?? null);
            if (!$documents['ok'] && empty($documents['saved'])) {
                $error = $documents['error'] . ' Your request was saved, but the files were not attached. Reference: ' . $reference_number;
            } else {
                createAuditLog($conn, $user_id, 'CREATE_REQUEST', 'requests', $request_id);
                $doc_count = intval($documents['saved'] ?? 0);
                $file_text = $doc_count === 1 ? 'file' : 'files';
                createNotification($conn, $user_id, 'Certificate Request Created', 'Your certificate request has been submitted with reference: ' . $reference_number . ' (' . $doc_count . ' ' . $file_text . ' attached)');
                $success = 'Certificate request submitted successfully! Reference: ' . $reference_number . ' (' . $doc_count . ' file' . ($doc_count === 1 ? '' : 's') . ' attached)';
            }
        } catch (Throwable $exception) {
            $error = 'Unable to save your certificate request: ' . $exception->getMessage();
        }
    }
}

$certificate_placeholders = implode(',', array_fill(0, count($certificate_type_keys), '?'));

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
    .certificate-mobile-appbar,
    .certificate-mobile-breadcrumbs,
    .certificate-mobile-callout,
    .certificate-mobile-stepper,
    .certificate-mobile-type-field {
        display: none;
    }

    .certificate-page {
        max-width: 1440px;
        margin: 0 auto;
    }

    .certificate-purpose-other[hidden] {
        display: none !important;
    }

    .certificate-purpose-other {
        margin-top: 12px;
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

    .cert-group-block {
        margin-bottom: 22px;
    }

    .cert-group-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 1px solid rgba(23, 32, 51, 0.08);
    }

    .cert-group-info {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .cert-group-title {
        font-family: 'Playfair Display', Georgia, serif;
        font-size: 1.08rem;
        font-weight: 700;
        color: #1e293b;
        display: flex;
        align-items: center;
    }

    .cert-group-desc {
        font-size: 0.8rem;
        color: #64748b;
    }

    .cert-group-badge {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 4px 11px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        white-space: nowrap;
    }

    .cert-group-badge.certification {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fde68a;
    }

    .cert-group-badge.certificate {
        background: #e0f2fe;
        color: #0369a1;
        border: 1px solid #bae6fd;
    }

    .certificate-option-grid {
        display: grid;
        gap: 14px;
    }

    .certificate-option-grid.grid-5 {
        grid-template-columns: repeat(5, minmax(0, 1fr));
    }

    .certificate-option-grid.grid-3 {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    @media (max-width: 1200px) {
        .certificate-option-grid.grid-5 {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 768px) {
        .certificate-option-grid.grid-5,
        .certificate-option-grid.grid-3 {
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }
    }

    .certificate-option {
        position: relative;
        display: block;
        cursor: pointer;
        height: 100%;
    }

    .certificate-option input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .certificate-option .cert-card-inner {
        position: relative;
        height: 100%;
        min-height: 154px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 10px;
        padding: 16px 18px;
        border: 1.5px solid #e7e2d8;
        border-radius: 14px;
        background: #ffffff;
        box-shadow: 0 3px 12px rgba(23, 32, 51, 0.03);
        transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        overflow: hidden;
    }

    .cert-card-top-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
    }

    .cert-icon-box {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        transition: transform 0.2s ease;
    }

    .cert-mini-badge {
        font-size: 0.68rem;
        font-weight: 700;
        color: #64748b;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 2px 7px;
        letter-spacing: 0.2px;
        text-transform: uppercase;
    }

    .cert-card-title {
        color: #1e293b;
        font-size: 0.96rem;
        font-weight: 700;
        line-height: 1.25;
        margin-top: 4px;
        display: block;
    }

    .cert-card-hint {
        color: #64748b;
        font-size: 0.78rem;
        line-height: 1.4;
        display: block;
        margin-top: auto;
    }

    .certificate-option:hover .cert-card-inner {
        transform: translateY(-3px);
        border-color: #c89b3c;
        box-shadow: 0 10px 24px rgba(46, 58, 45, 0.08);
    }

    .certificate-option:hover .cert-icon-box {
        transform: scale(1.08);
    }

    .certificate-option input:focus + .cert-card-inner,
    .certificate-option input:checked + .cert-card-inner {
        transform: translateY(-2px);
        border-color: #c89b3c;
        border-width: 2px;
        background: #fffdf9;
        box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.18), 0 10px 22px rgba(46, 58, 45, 0.07);
    }

    .certificate-option input:checked + .cert-card-inner::after {
        content: "\f00c";
        font-family: "Font Awesome 6 Free";
        font-weight: 900;
        position: absolute;
        top: 10px;
        right: 10px;
        width: 24px;
        height: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        color: #ffffff;
        background: #c89b3c;
        font-size: 11px;
        box-shadow: 0 2px 6px rgba(200, 155, 60, 0.4);
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

    /* Phone certificate service: open directly on the working request form. */
    @media (max-width: 599px) {
        body.certificate-mobile-page .user-content > .no-print,
        body.certificate-mobile-page .user-content > .mb-3.no-print,
        body.certificate-mobile-page .user-content > .mb-4.no-print,
        body.certificate-mobile-page .parish-back-link,
        body.certificate-mobile-page .parish-back-button-wrap,
        body.certificate-mobile-page .parish-section-header-component,
        body.certificate-mobile-page .certificate-hero,
        body.certificate-mobile-page .certificate-status-grid,
        body.certificate-mobile-page .success-reference a {
            display: none !important;
        }

        body.certificate-mobile-page .user-content {
            padding: 10px 12px max(18px, env(safe-area-inset-bottom)) !important;
        }

        body.certificate-mobile-page .user-content > .container-fluid {
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        body.certificate-mobile-page .certificate-page {
            width: 100% !important;
            max-width: 100% !important;
            margin: 0 !important;
        }

        body.certificate-mobile-page .certificate-page > .alert {
            margin: 0 0 12px !important;
        }

        body.certificate-mobile-page .certificate-form-card {
            margin: 0 !important;
            border: 1px solid #E9E1D2 !important;
            border-radius: 14px !important;
            box-shadow: 0 4px 14px rgba(42, 36, 28, 0.055) !important;
        }

        body.certificate-mobile-page .certificate-form-header {
            min-height: 0 !important;
            display: flex !important;
            align-items: flex-start !important;
            gap: 7px !important;
            padding: 12px !important;
            border-bottom: 1px solid #E9E1D2 !important;
            background: #FFFCF6 !important;
        }

        body.certificate-mobile-page .certificate-form-header h2 {
            gap: 7px !important;
            font-size: 17px !important;
            line-height: 1.25 !important;
        }

        body.certificate-mobile-page .certificate-form-header p {
            margin: 3px 0 0 !important;
            font-size: 12px !important;
            line-height: 1.35 !important;
        }

        body.certificate-mobile-page .certificate-form-header .section-kicker {
            min-height: 24px !important;
            padding: 3px 7px !important;
            font-size: 10px !important;
            line-height: 1.2 !important;
            white-space: nowrap !important;
        }

        body.certificate-mobile-page .form-step {
            padding: 12px !important;
        }

        body.certificate-mobile-page .step-heading {
            min-height: 34px !important;
            gap: 8px !important;
            margin: 0 0 9px !important;
            padding-right: 22px !important;
        }

        body.certificate-mobile-page .step-number {
            width: 26px !important;
            height: 26px !important;
            min-width: 26px !important;
            border-radius: 7px !important;
            font-size: 11px !important;
        }

        body.certificate-mobile-page .step-heading h3 {
            font-size: 13.5px !important;
            font-weight: 700 !important;
            line-height: 1.25 !important;
        }

        body.certificate-mobile-page .step-heading p {
            margin: 1px 0 0 !important;
            font-size: 11.5px !important;
            line-height: 1.3 !important;
        }

        body.certificate-mobile-page .certificate-option-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 8px !important;
        }

        body.certificate-mobile-page .certificate-option {
            width: 100% !important;
            min-width: 0 !important;
        }

        body.certificate-mobile-page .certificate-option span {
            width: 100% !important;
            min-width: 0 !important;
            min-height: 82px !important;
            display: grid !important;
            grid-template-columns: 28px minmax(0, 1fr) !important;
            grid-template-rows: auto auto !important;
            align-content: center !important;
            align-items: center !important;
            gap: 2px 7px !important;
            padding: 9px !important;
            border-color: #E9E1D2 !important;
            border-radius: 12px !important;
            box-shadow: none !important;
            transform: none !important;
        }

        body.certificate-mobile-page .certificate-option i {
            grid-column: 1 !important;
            grid-row: 1 / 3 !important;
            width: 28px !important;
            height: 28px !important;
            border-radius: 7px !important;
            font-size: 12px !important;
        }

        body.certificate-mobile-page .certificate-option strong {
            grid-column: 2 !important;
            font-size: 11.5px !important;
            line-height: 1.2 !important;
            overflow-wrap: anywhere !important;
        }

        body.certificate-mobile-page .certificate-option small {
            grid-column: 2 !important;
            display: -webkit-box !important;
            overflow: hidden !important;
            font-size: 9.5px !important;
            line-height: 1.25 !important;
            overflow-wrap: anywhere !important;
            -webkit-box-orient: vertical !important;
            -webkit-line-clamp: 2 !important;
        }

        body.certificate-mobile-page .certificate-option input:checked + span {
            border-color: #B9863A !important;
            background: #F7ECD6 !important;
            box-shadow: 0 0 0 1px rgba(185, 134, 58, 0.12) !important;
        }

        body.certificate-mobile-page .certificate-option input:checked + span::after {
            top: 6px !important;
            right: 6px !important;
            width: 17px !important;
            height: 17px !important;
            font-size: 8px !important;
        }

        body.certificate-mobile-page .certificate-option-grid + .mt-3 {
            display: none !important;
        }

        body.certificate-mobile-page .form-step .row.g-3 {
            --bs-gutter-y: 8px !important;
        }

        body.certificate-mobile-page .form-step .row.g-3 > .col-lg-4:last-child {
            display: none !important;
        }

        body.certificate-mobile-page .form-label {
            margin-bottom: 4px !important;
            font-size: 11.5px !important;
            font-weight: 600 !important;
            line-height: 1.25 !important;
        }

        body.certificate-mobile-page .request-form-control,
        body.certificate-mobile-page .form-control,
        body.certificate-mobile-page .form-select {
            min-height: 42px !important;
            padding: 8px 10px !important;
            border-color: #E9E1D2 !important;
            border-radius: 9px !important;
            font-size: 12px !important;
            line-height: 1.3 !important;
        }

        body.certificate-mobile-page textarea.request-form-control {
            min-height: 92px !important;
        }

        body.certificate-mobile-page .form-text {
            margin-top: 5px !important;
            font-size: 10.5px !important;
            line-height: 1.3 !important;
        }

        body.certificate-mobile-page .upload-zone {
            min-height: 112px !important;
            gap: 4px !important;
            padding: 12px 9px !important;
            border-color: #D8CDBB !important;
            border-radius: 12px !important;
            background: #FFFFFF !important;
        }

        body.certificate-mobile-page .upload-zone i {
            width: 30px !important;
            height: 30px !important;
            border-radius: 7px !important;
            font-size: 13px !important;
        }

        body.certificate-mobile-page .upload-zone strong {
            font-size: 11.5px !important;
            line-height: 1.25 !important;
        }

        body.certificate-mobile-page .upload-zone small {
            font-size: 9.5px !important;
            line-height: 1.3 !important;
        }

        body.certificate-mobile-page .file-preview {
            gap: 7px !important;
            margin-top: 8px !important;
            padding: 8px !important;
            font-size: 11px !important;
        }

        body.certificate-mobile-page .form-actions {
            position: sticky !important;
            bottom: 0 !important;
            z-index: 4 !important;
            gap: 5px !important;
            padding: 11px 12px max(12px, env(safe-area-inset-bottom)) !important;
            background: linear-gradient(180deg, rgba(247, 243, 236, 0.82), #F7F3EC 28%) !important;
        }

        body.certificate-mobile-page .submit-request-btn {
            order: 1 !important;
            position: static !important;
            width: 100% !important;
            min-height: 42px !important;
            padding: 9px 12px !important;
            border-radius: 10px !important;
            background: #8C6427 !important;
            color: #FFFFFF !important;
            font-size: 12.5px !important;
            line-height: 1.2 !important;
            box-shadow: 0 7px 16px rgba(140, 100, 39, 0.23) !important;
        }

        body.certificate-mobile-page .privacy-copy {
            order: 2 !important;
            text-align: center !important;
            font-size: 9.5px !important;
            line-height: 1.25 !important;
        }

        /* Document-form mobile architecture (375px-430px target). */
        body.certificate-mobile-page .user-content {
            padding: 0 0 calc(106px + env(safe-area-inset-bottom)) !important;
            background: #F7F5F1 !important;
        }

        body.certificate-mobile-page .certificate-page {
            display: block !important;
        }

        body.certificate-mobile-page .certificate-mobile-appbar {
            position: sticky !important;
            top: 0 !important;
            z-index: 1035 !important;
            min-height: 58px !important;
            display: grid !important;
            grid-template-columns: 38px minmax(0, 1fr) 34px !important;
            align-items: center !important;
            gap: 10px !important;
            padding: calc(8px + env(safe-area-inset-top)) 14px 8px !important;
            border-bottom: 1px solid #E5E7EB !important;
            background: rgba(255, 255, 255, 0.97) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.035) !important;
            backdrop-filter: blur(12px) !important;
        }

        body.certificate-mobile-page .certificate-mobile-back {
            width: 38px !important;
            height: 38px !important;
            min-width: 38px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 0 !important;
            border: 1px solid #E5E7EB !important;
            border-radius: 10px !important;
            background: #FFFFFF !important;
            color: #4A4136 !important;
            font-size: 13px !important;
        }

        body.certificate-mobile-page .certificate-mobile-title {
            min-width: 0 !important;
            display: grid !important;
            gap: 1px !important;
        }

        body.certificate-mobile-page .certificate-mobile-title strong {
            overflow: hidden !important;
            color: #26211B !important;
            font-size: 15px !important;
            font-weight: 750 !important;
            line-height: 1.2 !important;
            text-overflow: ellipsis !important;
            white-space: nowrap !important;
        }

        body.certificate-mobile-page .certificate-mobile-title span {
            color: #7A746C !important;
            font-size: 10.5px !important;
            line-height: 1.2 !important;
        }

        body.certificate-mobile-page .certificate-mobile-profile {
            width: 34px !important;
            height: 34px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            border: 1px solid rgba(185, 134, 58, 0.28) !important;
            border-radius: 50% !important;
            background: #F7ECD6 !important;
            color: #76531F !important;
            font-size: 12px !important;
            font-weight: 800 !important;
            text-decoration: none !important;
        }

        body.certificate-mobile-page .certificate-mobile-breadcrumbs {
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
            padding: 10px 14px 7px !important;
            color: #858079 !important;
            font-size: 10.5px !important;
            line-height: 1.2 !important;
            white-space: nowrap !important;
        }

        body.certificate-mobile-page .certificate-mobile-breadcrumbs a {
            color: #8C6427 !important;
            text-decoration: none !important;
        }

        body.certificate-mobile-page .certificate-mobile-breadcrumbs i {
            color: #B8B2A9 !important;
            font-size: 7px !important;
        }

        body.certificate-mobile-page .certificate-mobile-breadcrumbs strong {
            overflow: hidden !important;
            color: #4D4740 !important;
            font-weight: 650 !important;
            text-overflow: ellipsis !important;
        }

        body.certificate-mobile-page .certificate-mobile-callout {
            display: grid !important;
            grid-template-columns: 28px minmax(0, 1fr) !important;
            gap: 9px !important;
            margin: 4px 12px 10px !important;
            padding: 10px 11px !important;
            border: 1px solid #EADDBC !important;
            border-radius: 12px !important;
            background: #FFF9EC !important;
            color: #554730 !important;
        }

        body.certificate-mobile-page .certificate-mobile-callout > i {
            width: 28px !important;
            height: 28px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 8px !important;
            background: #F4E5C4 !important;
            color: #8C6427 !important;
            font-size: 12px !important;
        }

        body.certificate-mobile-page .certificate-mobile-callout div {
            min-width: 0 !important;
            display: grid !important;
            gap: 2px !important;
        }

        body.certificate-mobile-page .certificate-mobile-callout strong {
            font-size: 12px !important;
            line-height: 1.25 !important;
        }

        body.certificate-mobile-page .certificate-mobile-callout span {
            color: #766C5B !important;
            font-size: 10.5px !important;
            line-height: 1.35 !important;
        }

        body.certificate-mobile-page .certificate-mobile-stepper {
            display: grid !important;
            grid-template-columns: auto minmax(12px, 1fr) auto minmax(12px, 1fr) auto !important;
            align-items: center !important;
            gap: 5px !important;
            margin: 0 12px 11px !important;
            padding: 8px 10px !important;
            border: 1px solid #ECE8E1 !important;
            border-radius: 12px !important;
            background: #FFFFFF !important;
        }

        body.certificate-mobile-page .certificate-mobile-stepper span {
            display: inline-flex !important;
            align-items: center !important;
            gap: 4px !important;
            color: #8C8780 !important;
            font-size: 9.5px !important;
            font-weight: 650 !important;
            white-space: nowrap !important;
        }

        body.certificate-mobile-page .certificate-mobile-stepper span b {
            width: 19px !important;
            height: 19px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 50% !important;
            background: #F1EFEA !important;
            color: #7D776F !important;
            font-size: 9px !important;
        }

        body.certificate-mobile-page .certificate-mobile-stepper span.is-active {
            color: #76531F !important;
        }

        body.certificate-mobile-page .certificate-mobile-stepper span.is-active b {
            background: #B9863A !important;
            color: #FFFFFF !important;
        }

        body.certificate-mobile-page .certificate-mobile-stepper > i {
            height: 1px !important;
            background: #E5E1DA !important;
        }

        body.certificate-mobile-page .certificate-page > .alert {
            margin: 0 12px 10px !important;
        }

        body.certificate-mobile-page .certificate-form-card {
            overflow: visible !important;
            margin: 0 !important;
            border: 0 !important;
            border-radius: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
        }

        body.certificate-mobile-page .certificate-form-header {
            display: none !important;
        }

        body.certificate-mobile-page #certificateRequestForm {
            display: grid !important;
            gap: 10px !important;
            padding: 0 12px !important;
        }

        body.certificate-mobile-page .form-step {
            padding: 13px !important;
            border: 1px solid #E5E7EB !important;
            border-radius: 12px !important;
            background: #FFFFFF !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04) !important;
        }

        body.certificate-mobile-page .step-heading {
            min-height: 0 !important;
            margin: 0 0 11px !important;
            padding: 0 !important;
            cursor: default !important;
        }

        body.certificate-mobile-page .step-heading::after,
        body.certificate-mobile-page .step-number {
            display: none !important;
        }

        body.certificate-mobile-page .step-heading h3 {
            color: #302A24 !important;
            font-size: 14px !important;
            font-weight: 700 !important;
            line-height: 1.25 !important;
        }

        body.certificate-mobile-page .step-heading p {
            margin-top: 2px !important;
            color: #77716A !important;
            font-size: 11.5px !important;
            line-height: 1.35 !important;
        }

        body.certificate-mobile-page .certificate-option-grid,
        body.certificate-mobile-page .certificate-option-grid + .mt-3,
        body.certificate-mobile-page .certificate-mobile-type-field + .mt-3 {
            display: none !important;
        }

        body.certificate-mobile-page .certificate-mobile-type-field {
            display: block !important;
        }

        body.certificate-mobile-page .form-label {
            margin-bottom: 5px !important;
            color: #4B443D !important;
            font-size: 11.5px !important;
            font-weight: 650 !important;
            line-height: 1.25 !important;
        }

        body.certificate-mobile-page .request-form-control,
        body.certificate-mobile-page .form-control,
        body.certificate-mobile-page .form-select {
            min-height: 44px !important;
            padding: 10px 12px !important;
            border: 1px solid #E5E7EB !important;
            border-radius: 10px !important;
            background: #FAFAF9 !important;
            color: #2F2A25 !important;
            font-size: 12px !important;
            line-height: 1.35 !important;
            box-shadow: none !important;
        }

        body.certificate-mobile-page textarea.request-form-control {
            min-height: 96px !important;
        }

        body.certificate-mobile-page .upload-zone {
            min-height: 110px !important;
            padding: 12px !important;
            border: 1px dashed #D6D0C7 !important;
            border-radius: 10px !important;
            background: #FAFAF9 !important;
        }

        body.certificate-mobile-page .form-actions {
            position: fixed !important;
            right: 0 !important;
            bottom: 0 !important;
            left: 0 !important;
            z-index: 1040 !important;
            width: 100% !important;
            display: block !important;
            padding: 10px 12px calc(10px + env(safe-area-inset-bottom)) !important;
            border-top: 1px solid #E5E7EB !important;
            background: rgba(255, 255, 255, 0.97) !important;
            box-shadow: 0 -4px 14px rgba(0, 0, 0, 0.055) !important;
            backdrop-filter: blur(12px) !important;
        }

        body.certificate-mobile-page .privacy-copy {
            display: none !important;
        }

        body.certificate-mobile-page .submit-request-btn {
            width: 100% !important;
            min-height: 46px !important;
            padding: 11px 14px !important;
            border-radius: 10px !important;
            background: #8C6427 !important;
            color: #FFFFFF !important;
            font-size: 13px !important;
            font-weight: 750 !important;
            box-shadow: 0 6px 14px rgba(140, 100, 39, 0.22) !important;
        }
    }
</style>

<div class="container-fluid mt-4">
    <div class="certificate-page">
        <nav class="certificate-mobile-breadcrumbs" aria-label="Breadcrumb">
            <a href="index.php">Dashboard</a>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <span>Certificates</span>
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
            <strong>Request</strong>
        </nav>

        <aside class="certificate-mobile-callout" aria-label="Certificate request requirements">
            <i class="fas fa-circle-info" aria-hidden="true"></i>
            <div>
                <strong>Before you submit</strong>
                <span>Processing takes 3–5 parish office days. A clear PSA / Birth Certificate copy is required.</span>
            </div>
        </aside>

        <?php echo mobileStepRail(['Details', 'Requirements', 'Review'], 1, 'Certificate request progress'); ?>

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
            <a class="certificate-status-card status-<?php echo e($status_name); ?>" href="my-requests.php?status=<?php echo urlencode($status_name); ?>">
                <div class="status-card-top">
                    <i class="fas <?php echo e($status_info['icon']); ?> text-<?php echo e($status_info['tone']); ?>"></i>
                    <strong><?php echo intval($count); ?></strong>
                </div>
                <span><?php echo e(certificateLabel($status_name)); ?></span>
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
            <input type="hidden" name="idempotency_key" value="<?php echo e($request_idempotency_key); ?>">
            <section class="form-step">
                <div class="step-heading">
                    <span class="step-number">1</span>
                    <div>
                        <h3>Certificate Information</h3>
                        <p>Select the sacramental certificate you need.</p>
                    </div>
                </div>

                <!-- Group 1: Sacramental Certifications -->
                <div class="cert-group-block">
                    <div class="cert-group-header">
                        <div class="cert-group-info">
                            <span class="cert-group-title"><i class="fas fa-file-signature text-warning me-2"></i> Sacramental Certifications</span>
                            <span class="cert-group-desc">Certified official extracts transcribed directly from parish canonical registry books.</span>
                        </div>
                        <span class="cert-group-badge certification"><i class="fas fa-stamp me-1"></i> Registry Extract</span>
                    </div>

                    <div class="certificate-option-grid grid-5" role="radiogroup" aria-label="Sacramental Certifications">
                        <?php foreach ($certificate_meta as $value => $meta): ?>
                            <?php if (($meta['category'] ?? '') !== 'certification') continue; ?>
                            <label class="certificate-option">
                                <input type="radio" name="request_type" value="<?php echo e($value); ?>" <?php echo (($_POST['request_type'] ?? '') === $value) ? 'checked' : ''; ?>>
                                <span class="cert-card-inner">
                                    <div class="cert-card-top-row">
                                        <span class="cert-icon-box" style="<?php echo !empty($meta['icon_color']) ? 'color: ' . $meta['icon_color'] . '; background: ' . $meta['icon_bg'] . ';' : ''; ?>">
                                            <i class="fas <?php echo e($meta['icon']); ?>"></i>
                                        </span>
                                        <span class="cert-mini-badge"><?php echo e($meta['badge']); ?></span>
                                    </div>
                                    <strong class="cert-card-title"><?php echo e($meta['title']); ?></strong>
                                    <small class="cert-card-hint"><?php echo e($meta['hint']); ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Group 2: Sacramental Certificates -->
                <div class="cert-group-block">
                    <div class="cert-group-header">
                        <div class="cert-group-info">
                            <span class="cert-group-title"><i class="fas fa-scroll text-warning me-2"></i> Sacramental Certificates</span>
                            <span class="cert-group-desc">Official canonical commemorative certificates for sacraments celebrated in this parish.</span>
                        </div>
                        <span class="cert-group-badge certificate"><i class="fas fa-certificate me-1"></i> Canonical Certificate</span>
                    </div>

                    <div class="certificate-option-grid grid-3" role="radiogroup" aria-label="Sacramental Certificates">
                        <?php foreach ($certificate_meta as $value => $meta): ?>
                            <?php if (($meta['category'] ?? '') !== 'certificate') continue; ?>
                            <label class="certificate-option">
                                <input type="radio" name="request_type" value="<?php echo e($value); ?>" <?php echo (($_POST['request_type'] ?? '') === $value) ? 'checked' : ''; ?>>
                                <span class="cert-card-inner">
                                    <div class="cert-card-top-row">
                                        <span class="cert-icon-box" style="<?php echo !empty($meta['icon_color']) ? 'color: ' . $meta['icon_color'] . '; background: ' . $meta['icon_bg'] . ';' : ''; ?>">
                                            <i class="fas <?php echo e($meta['icon']); ?>"></i>
                                        </span>
                                        <span class="cert-mini-badge"><?php echo e($meta['badge']); ?></span>
                                    </div>
                                    <strong class="cert-card-title"><?php echo e($meta['title']); ?></strong>
                                    <small class="cert-card-hint"><?php echo e($meta['hint']); ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="certificate-mobile-type-field">
                    <label for="certificateMobileSelect" class="form-label">Document type</label>
                    <select class="form-select request-form-control" id="certificateMobileSelect" name="certificate_mobile_type">
                        <option value="">Select a certificate</option>
                        <?php foreach ($certificate_types as $value => $label): ?>
                            <option value="<?php echo e($value); ?>" <?php echo (($_POST['request_type'] ?? $_POST['certificate_mobile_type'] ?? '') === $value) ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
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
                        <p>Select the purpose of your request. Staff will use this to help verify the record.</p>
                    </div>
                </div>

                <label for="purpose" class="form-label">Purpose</label>
                <select class="form-select request-form-control" id="purpose" name="purpose" required>
                    <option value="">Select purpose</option>
                    <?php foreach ($certificate_purposes as $purpose_value => $purpose_label): ?>
                        <option value="<?php echo e($purpose_value); ?>" <?php echo (($_POST['purpose'] ?? '') === $purpose_value) ? 'selected' : ''; ?>><?php echo e($purpose_label); ?></option>
                    <?php endforeach; ?>
                </select>

                <div class="certificate-purpose-other" id="purposeOtherField" <?php echo (($_POST['purpose'] ?? '') === 'others') ? '' : 'hidden'; ?>>
                    <label for="purpose_other" class="form-label">Please specify</label>
                    <input type="text" class="form-control request-form-control" id="purpose_other" name="purpose_other" maxlength="180" value="<?php echo e($_POST['purpose_other'] ?? ''); ?>" placeholder="e.g. School enrollment, employment requirement">
                </div>

                <div class="form-text"><i class="fas fa-wand-magic-sparkles"></i> TUGON tip: choose “Others” if your purpose is not listed, then briefly describe it so staff can verify your record faster.</div>
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

    </div>
</div>

<script>
    (function() {
        const select = document.getElementById('certificateSearchSelect');
        const mobileSelect = document.getElementById('certificateMobileSelect');
        const radios = document.querySelectorAll('input[name="request_type"]');
        const fileInput = document.getElementById('requirement_files');
        const uploadZone = document.getElementById('uploadZone');
        const purposeSelect = document.getElementById('purpose');
        const purposeOtherField = document.getElementById('purposeOtherField');
        const purposeOtherInput = document.getElementById('purpose_other');
        const filePreview = document.getElementById('filePreview');
        const fileName = document.getElementById('fileName');
        const fileSize = document.getElementById('fileSize');
        const form = document.getElementById('certificateRequestForm');
        const submitBtn = document.getElementById('submitRequestBtn');
        const certificateLabels = <?php echo json_encode($certificate_types); ?>;

        function updatePurposeField() {
            if (!purposeSelect || !purposeOtherField || !purposeOtherInput) return;
            const showOther = purposeSelect.value === 'others';
            purposeOtherField.hidden = !showOther;
            purposeOtherInput.required = showOther;
            purposeOtherInput.setAttribute('aria-required', showOther ? 'true' : 'false');
            if (!showOther) purposeOtherInput.value = '';
        }

        if (purposeSelect) {
            purposeSelect.addEventListener('change', updatePurposeField);
            updatePurposeField();
        }

        function setCertificateType(value) {
            if (!value) return;
            radios.forEach(function(radio) {
                radio.checked = (radio.value === value);
            });
            if (mobileSelect) {
                mobileSelect.value = value;
            }
            if (select && certificateLabels[value]) {
                select.value = certificateLabels[value];
            }
        }

        if (select) {
            function syncSearchSelection() {
                const option = Array.from(document.querySelectorAll('#certificateTypeOptions option')).find(function(item) {
                    return item.value.toLowerCase() === select.value.toLowerCase();
                });
                const value = option ? option.dataset.value : '';
                if (value) {
                    setCertificateType(value);
                }
            }
            select.addEventListener('change', syncSearchSelection);
            select.addEventListener('input', syncSearchSelection);
        }

        radios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                if (radio.checked) {
                    setCertificateType(radio.value);
                }
            });
        });

        if (mobileSelect) {
            mobileSelect.addEventListener('change', function() {
                if (mobileSelect.value) {
                    setCertificateType(mobileSelect.value);
                }
            });
        }

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

        if (form) {
            form.addEventListener('submit', function(event) {
                const checkedRadio = document.querySelector('input[name="request_type"]:checked');
                const mobileVal = mobileSelect ? mobileSelect.value : '';
                const selectedType = (checkedRadio ? checkedRadio.value : '') || mobileVal;
                
                if (!selectedType) {
                    event.preventDefault();
                    alert('Please select a certificate type.');
                    const firstOptionGrid = document.querySelector('.certificate-option-grid');
                    if (firstOptionGrid && window.getComputedStyle(firstOptionGrid).display !== 'none') {
                        const firstRadio = firstOptionGrid.querySelector('input[type="radio"]');
                        if (firstRadio) firstRadio.focus();
                    } else if (mobileSelect) {
                        mobileSelect.focus();
                    }
                    return false;
                }

                if (mobileVal && (!checkedRadio || checkedRadio.value !== mobileVal)) {
                    setCertificateType(mobileVal);
                }

                if (purposeSelect && !purposeSelect.value) {
                    event.preventDefault();
                    alert('Please select the purpose of your certificate request.');
                    purposeSelect.focus();
                    return false;
                }

                if (purposeSelect && purposeSelect.value === 'others' && purposeOtherInput && !purposeOtherInput.value.trim()) {
                    event.preventDefault();
                    alert('Please specify the purpose of your certificate request.');
                    purposeOtherInput.focus();
                    return false;
                }

                if (fileInput && (!fileInput.files || fileInput.files.length === 0)) {
                    event.preventDefault();
                    alert('Please upload a copy of the PSA / Birth Certificate before submitting.');
                    fileInput.focus();
                    return false;
                }

                if (submitBtn) {
                    submitBtn.classList.add('is-loading');
                    window.setTimeout(function() {
                        submitBtn.disabled = true;
                    }, 10);
                }
            });
        }
    })();
</script>

<script>
    (function() {
        const mobileQuery = window.matchMedia('(min-width: 600px) and (max-width: 767px)');
        const form = document.getElementById('certificateRequestForm');
        const steps = Array.from(document.querySelectorAll('#certificateRequestForm .form-step'));

        function setStepExpanded(step, expanded) {
            const heading = step.querySelector('.step-heading');
            step.classList.toggle('is-collapsed', !expanded);
            if (heading) {
                heading.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            }
        }

        function syncMobileSteps() {
            steps.forEach(function(step, index) {
                const heading = step.querySelector('.step-heading');
                if (!heading) return;

                if (mobileQuery.matches) {
                    heading.setAttribute('role', 'button');
                    heading.setAttribute('tabindex', '0');
                    if (!step.dataset.mobileAccordionReady) {
                        setStepExpanded(step, index === 0);
                        step.dataset.mobileAccordionReady = 'true';
                    }
                } else {
                    step.classList.remove('is-collapsed');
                    heading.removeAttribute('role');
                    heading.removeAttribute('tabindex');
                    heading.removeAttribute('aria-expanded');
                    delete step.dataset.mobileAccordionReady;
                }
            });
        }

        const mobileBack = document.querySelector('[data-certificate-mobile-back]');
        if (mobileBack) {
            mobileBack.addEventListener('click', function() {
                if (window.history.length > 1) {
                    window.history.back();
                } else {
                    window.location.href = 'index.php';
                }
            });
        }

        steps.forEach(function(step) {
            const heading = step.querySelector('.step-heading');
            if (!heading) return;

            function toggleStep() {
                if (!mobileQuery.matches) return;
                setStepExpanded(step, step.classList.contains('is-collapsed'));
            }

            heading.addEventListener('click', toggleStep);
            heading.addEventListener('keydown', function(event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    toggleStep();
                }
            });
        });

        if (form) {
            form.addEventListener('invalid', function(event) {
                const step = event.target.closest('.form-step');
                if (step && mobileQuery.matches) {
                    setStepExpanded(step, true);
                }
            }, true);
        }

        if (typeof mobileQuery.addEventListener === 'function') {
            mobileQuery.addEventListener('change', syncMobileSteps);
        } else {
            mobileQuery.addListener(syncMobileSteps);
        }
        syncMobileSteps();
    })();
</script>

<?php include '../templates/footer.php'; ?>
