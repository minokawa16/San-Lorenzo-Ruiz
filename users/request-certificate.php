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
$duplicate_notice = null;
$request_idempotency_key = bin2hex(random_bytes(32));
ensureRequestDocumentsSchema($conn);
ensureEmailNotificationSchema($conn);
ensureCertificateDuplicateGuardSchema($conn);

$gcash_recipient_name = 'Agnes Calapaan';
$gcash_recipient_number = '09977428176';
$gcash_recipient_display = '0997 742 8176';

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
    $record_holder_name = trim((string) ($_POST['record_holder_name'] ?? ''));
    if ($record_holder_name === '') {
        $record_holder_name = trim((string) ($_SESSION['fullname'] ?? ''));
    }

    $payment_method = strtolower(trim((string) ($_POST['payment_method'] ?? 'gcash')));
    if (!in_array($payment_method, ['gcash', 'cash'], true)) {
        $payment_method = 'gcash';
    }
    $payment_amount = floatval($_POST['payment_amount'] ?? 150.00);
    $payment_reference = trim((string) ($_POST['payment_reference'] ?? ''));
    $payment_notes = trim((string) ($_POST['payment_notes'] ?? ''));
    $receipt_file = $_FILES['receipt_file'] ?? null;
    $has_receipt = ($receipt_file && is_array($receipt_file) && !empty($receipt_file['tmp_name']) && ($receipt_file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK);

    $release_method = strtolower(trim((string) ($_POST['release_method'] ?? 'online')));
    if (!in_array($release_method, ['online', 'walk_in'], true)) {
        $release_method = 'online';
    }

    $is_baptism = in_array($request_type, ['baptismal_certificate', 'baptism_certification', 'baptism'], true);
    $is_communion = in_array($request_type, ['first_communion_certificate', 'first_communion_certification', 'first_communion', 'communion'], true);
    $is_confirmation = in_array($request_type, ['confirmation_certificate', 'confirmation_certification', 'confirmation'], true);
    // Cert types that require an extra supporting document
    $needs_supporting_doc = $is_baptism || $is_communion || $is_confirmation;
    // For communion, both docs are required
    $communion_baptism_file  = $_FILES['communion_baptismal_doc'] ?? null;
    $communion_seminar_file  = $_FILES['communion_seminar_doc'] ?? null;
    $has_communion_baptism   = ($communion_baptism_file && is_array($communion_baptism_file) && !empty($communion_baptism_file['tmp_name']) && ($communion_baptism_file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK);
    $has_communion_seminar   = ($communion_seminar_file && is_array($communion_seminar_file) && !empty($communion_seminar_file['tmp_name']) && ($communion_seminar_file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK);
    $supporting_doc_file = $_FILES['supporting_doc'] ?? null;
    $has_supporting_doc = ($supporting_doc_file && is_array($supporting_doc_file) && !empty($supporting_doc_file['tmp_name']) && ($supporting_doc_file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK);

    // Collect baptism sacramental fields
    $birth_place = trim((string) ($_POST['birth_place'] ?? ''));
    $birth_date = trim((string) ($_POST['birth_date'] ?? ''));
    $residence = trim((string) ($_POST['residence'] ?? ''));
    $father_name = trim((string) ($_POST['father_name'] ?? ''));
    $father_birth_place = trim((string) ($_POST['father_birth_place'] ?? ''));
    $mother_name = trim((string) ($_POST['mother_name'] ?? ''));
    $mother_birth_place = trim((string) ($_POST['mother_birth_place'] ?? ''));
    $baptism_date = trim((string) ($_POST['baptism_date'] ?? ''));

    // Collect and sanitize sponsors
    $raw_sponsors = $_POST['sponsors'] ?? [];
    if (!is_array($raw_sponsors)) {
        $raw_sponsors = preg_split('/[\r\n]+/', (string) $raw_sponsors);
    }
    $valid_sponsors = [];
    foreach ($raw_sponsors as $sp) {
        $sp = trim((string) $sp);
        if ($sp !== '' && !in_array($sp, $valid_sponsors, true)) {
            $valid_sponsors[] = $sp;
        }
    }

    $baptism_missing = [];
    if ($is_baptism) {
        if ($record_holder_name === '') $baptism_missing[] = 'Full Name of Baptized Person';
        if ($birth_place === '') $baptism_missing[] = 'Birthplace';
        if ($birth_date === '') $baptism_missing[] = 'Birthday';
        if ($residence === '') $baptism_missing[] = 'Residence / Address';
        if ($father_name === '') $baptism_missing[] = "Father's Name";
        if ($father_birth_place === '') $baptism_missing[] = "Father's Birthplace";
        if ($mother_name === '') $baptism_missing[] = "Mother's Maiden Name";
        if ($mother_birth_place === '') $baptism_missing[] = "Mother's Birthplace";
        if ($baptism_date === '') $baptism_missing[] = 'Date of Baptism';
        if (count($valid_sponsors) < 2) $baptism_missing[] = 'At least 2 Sponsors (Ninong-Ninang)';
    }

    if (!array_key_exists($request_type, $certificate_types)) {
        $error = 'Please select a certificate type.';
    } elseif ($record_holder_name === '') {
        $error = 'Please provide the full legal name of the person named on the certificate.';
    } elseif ($is_baptism && !empty($baptism_missing)) {
        $error = 'Please complete all required fields for the baptismal record: ' . implode(', ', $baptism_missing) . '.';
    } elseif (!array_key_exists($purpose, $certificate_purposes)) {
        $error = 'Please select the purpose of your certificate request.';
    } elseif ($purpose === 'others' && $purpose_other === '') {
        $error = 'Please specify the purpose of your certificate request.';
    } elseif ($purpose === 'others' && strlen($purpose_other) > 180) {
        $error = 'The custom purpose must be 180 characters or fewer.';
    } elseif (!requestUploadHasFiles($_FILES['requirement_files'] ?? null)) {
        $error = 'Please upload a copy of the required supporting document (e.g. PSA / Birth Certificate) before submitting your certificate request.';
    } elseif ($is_communion && !$has_communion_baptism) {
        $error = 'First Communion requests require a Baptismal Certificate upload. Please attach your Baptismal Certificate.';
    } elseif ($is_communion && !$has_communion_seminar) {
        $error = 'First Communion requests require a Seminar Certificate / Proof of Attendance upload. Please attach your seminar certificate or attendance proof.';
    } elseif ($payment_method === 'gcash' && $payment_amount <= 0) {
        $error = 'Please enter the amount paid via GCash.';
    } elseif ($payment_method === 'gcash' && !$has_receipt) {
        $error = 'Please upload your GCash payment confirmation receipt or screenshot.';
    } else {
        $purpose_description = $purpose === 'others' ? $purpose_other : $certificate_purposes[$purpose];
        $release_label = ($release_method === 'walk_in') ? 'Walk-in Pickup (Parish Office)' : 'Online Release (Digital Delivery)';
        $payment_label = ($payment_method === 'gcash') ? 'GCash Transfer' : 'Cash (Parish Office Settlement)';

        $description_parts = [
            'Record Holder Name: ' . $record_holder_name,
            'Required document: ' . $certificate_required_document,
            'Purpose: ' . $purpose_description,
            'Payment Method: ' . $payment_label,
            'Release Method: ' . $release_label
        ];

        if ($is_communion) {
            $commDate = trim((string)($_POST['communion_date'] ?? ''));
            $commFather = trim((string)($_POST['communion_father_name'] ?? ''));
            $commMother = trim((string)($_POST['communion_mother_name'] ?? ''));
            if ($commDate !== '') $description_parts[] = 'Date of First Communion: ' . $commDate;
            if ($commFather !== '') $description_parts[] = "Father's Name: " . $commFather;
            if ($commMother !== '') $description_parts[] = "Mother's Name: " . $commMother;
        }

        if ($is_confirmation) {
            $confDate = trim((string)($_POST['confirmation_date'] ?? ''));
            $confFather = trim((string)($_POST['conf_father_name'] ?? ''));
            $confMother = trim((string)($_POST['conf_mother_name'] ?? ''));
            $confSponsor = trim((string)($_POST['conf_sponsor_name'] ?? ''));
            if ($confDate !== '') $description_parts[] = 'Date of Confirmation: ' . $confDate;
            if ($confFather !== '') $description_parts[] = "Father's Name: " . $confFather;
            if ($confMother !== '') $description_parts[] = "Mother's Name: " . $confMother;
            if ($confSponsor !== '') $description_parts[] = 'Sponsor: ' . $confSponsor;
        }

        // If baptism, search/match against baptism_records registry by Name + Birthday + Date of Baptism
        $matched_record = null;
        if ($is_baptism) {
            $match_stmt = $conn->prepare("SELECT baptism_id, fullname, birth_date, baptism_date, book_no, page_no, entry_no, priest, godparents 
                FROM baptism_records 
                WHERE LOWER(TRIM(fullname)) = LOWER(TRIM(?)) 
                  AND birth_date = ? 
                  AND baptism_date = ? 
                LIMIT 1");
            if ($match_stmt) {
                $match_stmt->bind_param('sss', $record_holder_name, $birth_date, $baptism_date);
                $match_stmt->execute();
                $matched_record = $match_stmt->get_result()->fetch_assoc();
                $match_stmt->close();
            }

            $description_parts[] = "\n--- SACRAMENTAL RECORD OF BAPTISM ---";
            $description_parts[] = 'Full Name: ' . $record_holder_name;
            $description_parts[] = 'Birthplace: ' . $birth_place;
            $description_parts[] = 'Birthday: ' . $birth_date;
            $description_parts[] = 'Residence: ' . $residence;
            $description_parts[] = "Father's Name: " . $father_name;
            $description_parts[] = "Father's Birthplace: " . $father_birth_place;
            $description_parts[] = "Mother's Name: " . $mother_name;
            $description_parts[] = "Mother's Birthplace: " . $mother_birth_place;
            $description_parts[] = 'Date of Baptism: ' . $baptism_date;
            $description_parts[] = 'Sponsors: ' . implode('; ', $valid_sponsors);

            if ($matched_record) {
                $bookRef = "Book " . ($matched_record['book_no'] ?: 'N/A') . ", Page " . ($matched_record['page_no'] ?: 'N/A') . ", Entry " . ($matched_record['entry_no'] ?: 'N/A');
                $description_parts[] = "\n[MATCHED_SACRAMENTAL_RECORD: #" . intval($matched_record['baptism_id']) . " (" . $bookRef . ")]";
            } else {
                $description_parts[] = "\n[REGISTRY_MATCH: NO_EXACT_MATCH_ON_FILE]";
            }

            $meta_payload = [
                'is_baptism' => true,
                'fullname' => $record_holder_name,
                'birth_place' => $birth_place,
                'birth_date' => $birth_date,
                'residence' => $residence,
                'father_name' => $father_name,
                'father_birth_place' => $father_birth_place,
                'mother_name' => $mother_name,
                'mother_birth_place' => $mother_birth_place,
                'baptism_date' => $baptism_date,
                'sponsors' => $valid_sponsors,
                'matched_baptism_id' => $matched_record ? intval($matched_record['baptism_id']) : null,
                'book_no' => $matched_record['book_no'] ?? null,
                'page_no' => $matched_record['page_no'] ?? null,
                'entry_no' => $matched_record['entry_no'] ?? null,
            ];
            $description_parts[] = '<!--BAPTISM_RECORD_META:' . json_encode($meta_payload) . '-->';
        }

        $description = implode("\n", $description_parts);
        try {
            $idempotency_key = trim((string) ($_POST['idempotency_key'] ?? ''));
            if (empty($idempotency_key) || !preg_match('/^[a-f0-9]{64}$/', $idempotency_key)) {
                $idempotency_key = bin2hex(random_bytes(32));
            }
            $requestResult = (new RequestService($conn))->create([
                'request_type' => $request_type,
                'description' => $description,
                'record_holder_name' => $record_holder_name
            ], $user_id, $idempotency_key);
            $request_id = (int) $requestResult['request_id'];
            $reference_number = $requestResult['reference_number'];
            $documents = saveMultipleRequirementDocuments($conn, $request_id, $user_id, $_FILES['requirement_files'] ?? null);
            // Save communion-specific required docs if present
            if ($is_communion && $has_communion_baptism) {
                saveRequestDocument($conn, $request_id, $user_id, $communion_baptism_file, 'requirement', 'First Communion — Baptismal Certificate');
                $documents['saved'] = ($documents['saved'] ?? 0) + 1;
            }
            if ($is_communion && $has_communion_seminar) {
                saveRequestDocument($conn, $request_id, $user_id, $communion_seminar_file, 'requirement', 'First Communion — Seminar/Attendance Certificate');
                $documents['saved'] = ($documents['saved'] ?? 0) + 1;
            }
            // Save optional single supporting doc for baptism/confirmation
            if (($is_baptism || $is_confirmation) && $has_supporting_doc) {
                saveRequestDocument($conn, $request_id, $user_id, $supporting_doc_file, 'requirement', 'Supporting Document');
                $documents['saved'] = ($documents['saved'] ?? 0) + 1;
            }
            $paymentResult = createRequestPayment($conn, $request_id, $user_id, $payment_amount, $payment_method, $payment_reference, $payment_notes, $receipt_file);

            if (!$documents['ok'] && empty($documents['saved'])) {
                $error = $documents['error'] . ' Your request was saved, but the requirements were not attached. Reference: ' . $reference_number;
            } else {
                createAuditLog($conn, $user_id, 'CREATE_REQUEST', 'requests', $request_id);
                $doc_count = intval($documents['saved'] ?? 0);
                $file_text = $doc_count === 1 ? 'file' : 'files';
                createNotification($conn, $user_id, 'Certificate Request Created', 'Your certificate request has been submitted with reference: ' . $reference_number . ' (' . $doc_count . ' ' . $file_text . ' attached)', true, 'requests', 'request', $request_id, 'request.view');
                
                // Notify administrators and staff if payment was submitted via GCash
                if ($payment_method === 'gcash' && ($paymentResult['ok'] ?? false)) {
                    $admin_stmt = $conn->query("SELECT id FROM users WHERE role IN ('admin', 'staff') AND status = 'active'");
                    if ($admin_stmt) {
                        while ($admin_row = $admin_stmt->fetch_assoc()) {
                            createNotification($conn, (int)$admin_row['id'], 'Payment Receipt Submitted', 'Parishioner ' . ($record_holder_name ?: 'A parishioner') . ' submitted a GCash receipt for certificate request ' . $reference_number . '.', true, 'requests', 'request', $request_id, 'request.view');
                        }
                    }
                }

                $success = 'Certificate request submitted successfully! Reference: ' . $reference_number . ' (' . $doc_count . ' file' . ($doc_count === 1 ? '' : 's') . ' attached)';
            }
        } catch (DuplicateRequestException $exception) {
            http_response_code(409);
            $duplicate_ref = $exception->getReferenceNumber();
            $duplicate_status = $exception->getExistingStatus() ?: 'PENDING';
            if (strtolower($duplicate_status) === 'submitted') {
                $duplicate_status = 'PENDING';
            }
            $duplicate_id = $exception->getExistingRequestId();
            $duplicate_msg = str_ireplace('submitted', 'pending', $exception->getMessage());
            $duplicate_notice = [
                'reference' => $duplicate_ref,
                'status' => $duplicate_status,
                'request_id' => $duplicate_id,
                'message' => "You're not allowed to request another certificate because it will duplicate your active request.",
                'details' => $duplicate_msg,
                'track_url' => $duplicate_ref ? "my-requests.php?q=" . urlencode($duplicate_ref) : "my-requests.php",
            ];
            $error = "You're not allowed to request another certificate because it will duplicate your active request" . ($duplicate_ref ? " ({$duplicate_ref})" : "") . ".";
        } catch (Throwable $exception) {
            $error = 'Unable to save your certificate request: ' . $exception->getMessage();
        }
    }
}

$active_certificate_requests = [];
$act_stmt = $conn->prepare("SELECT request_id, reference_number, request_type, status, record_holder_name 
    FROM requests 
    WHERE user_id = ? 
      AND status NOT IN ('completed', 'rejected', 'cancelled') 
      AND deleted_at IS NULL
    ORDER BY request_id DESC");
if ($act_stmt) {
    $act_stmt->bind_param('i', $user_id);
    $act_stmt->execute();
    $act_res = $act_stmt->get_result();
    while ($act_row = $act_res->fetch_assoc()) {
        $cFamily = RequestService::certificateFamily($act_row['request_type']);
        if ($cFamily !== null) {
            $rawStatus = (string)$act_row['status'];
            $act_row['status'] = $rawStatus === 'submitted' ? 'pending' : $rawStatus;
            $act_row['certificate_family'] = $cFamily;
            $active_certificate_requests[] = $act_row;
        }
    }
    $act_stmt->close();
}

$all_certificate_types = array_values(array_unique(array_merge($certificate_type_keys, [
    'baptism_certification',
    'confirmation_certification',
    'first_communion_certification',
    'marriage_certification',
    'funeral_certification',
    'baptismal_certificate',
    'confirmation_certificate',
    'first_communion_certificate',
    'marriage_certificate',
    'funeral_certificate',
    'certificate',
    'certification',
    'baptism',
    'confirmation',
    'first_communion',
    'marriage',
    'funeral',
])));

$certificate_placeholders = implode(',', array_fill(0, count($all_certificate_types), '?'));

$status_map = [
    'submitted' => 'pending',
    'pending' => 'pending',
    'requirements_review' => 'pending',
    'under_review' => 'pending',
    'needs_information' => 'pending',
    'payment_required' => 'pending',
    'payment_review' => 'pending',
    'approved' => 'approved',
    'processing' => 'processing',
    'scheduled' => 'processing',
    'ready_for_release' => 'processing',
    'completed' => 'completed',
    'rejected' => 'rejected',
    'cancelled' => 'cancelled',
];

$status_counts = array_fill_keys($allowed_statuses, 0);
$count_types = 'i' . str_repeat('s', count($all_certificate_types));
$count_params = array_merge([$user_id], $all_certificate_types);
$stmt = $conn->prepare("SELECT status, COUNT(*) AS count 
    FROM requests 
    WHERE user_id = ? 
      AND (
          request_type IN ($certificate_placeholders) 
          OR request_type LIKE '%cert%'
      ) 
    GROUP BY status");
if ($stmt) {
    $stmt->bind_param($count_types, ...$count_params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $raw_status = strtolower(trim((string) $row['status']));
        $target_status = $status_map[$raw_status] ?? (isset($status_counts[$raw_status]) ? $raw_status : 'pending');
        if (isset($status_counts[$target_status])) {
            $status_counts[$target_status] += intval($row['count']);
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

    /* Two-Step Certificate Selection Flow Styles */
    .category-selection-container {
        margin-bottom: 8px;
    }

    .category-prompt-box {
        margin-bottom: 18px;
    }

    .category-prompt-heading {
        font-family: 'Playfair Display', Georgia, serif;
        font-size: 1.25rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0 0 6px 0;
        display: flex;
        align-items: center;
    }

    .category-prompt-subtext {
        font-size: 0.88rem;
        color: #64748b;
        margin: 0;
    }

    .category-choice-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
    }

    @media (max-width: 768px) {
        .category-choice-grid {
            grid-template-columns: 1fr;
            gap: 14px;
        }
    }

    .category-choice-card {
        position: relative;
        display: block;
        cursor: pointer;
        outline: none;
        user-select: none;
        height: 100%;
    }

    .category-choice-card .category-card-inner {
        position: relative;
        height: 100%;
        min-height: 172px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 14px;
        padding: 22px 24px;
        border: 1.5px solid #e7e2d8;
        border-radius: 14px;
        background: #ffffff;
        box-shadow: 0 3px 14px rgba(23, 32, 51, 0.04);
        transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
        overflow: hidden;
    }

    .category-choice-card:hover .category-card-inner,
    .category-choice-card:focus-visible .category-card-inner {
        transform: translateY(-3px);
        border-color: #c89b3c;
        box-shadow: 0 10px 26px rgba(46, 58, 45, 0.09);
    }

    .category-choice-card:hover .category-icon-box {
        transform: scale(1.08);
    }

    .category-choice-card.is-selected .category-card-inner {
        transform: translateY(-2px);
        border-color: #c89b3c;
        border-width: 2px;
        background: #fffdf9;
        box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.18), 0 10px 24px rgba(46, 58, 45, 0.08);
    }

    .category-choice-card.is-selected .category-card-inner::after {
        content: "\f00c";
        font-family: "Font Awesome 6 Free";
        font-weight: 900;
        position: absolute;
        top: 14px;
        right: 14px;
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

    .category-card-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
    }

    .category-icon-box {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        transition: transform 0.2s ease;
    }

    .category-icon-box.certification-color {
        color: #b45309;
        background: #fef3c7;
    }

    .category-icon-box.certificate-color {
        color: #0369a1;
        background: #e0f2fe;
    }

    .category-card-title {
        color: #1e293b;
        font-size: 1.15rem;
        font-weight: 700;
        line-height: 1.3;
        margin: 0 0 6px 0;
        display: block;
    }

    .category-card-desc {
        color: #64748b;
        font-size: 0.86rem;
        line-height: 1.45;
        margin: 0;
    }

    .category-card-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: 6px;
        padding-top: 12px;
        border-top: 1px solid #f1f5f9;
    }

    .category-count-pill {
        font-size: 0.76rem;
        font-weight: 600;
        color: #475569;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        padding: 3px 10px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .category-action-btn {
        font-size: 0.8rem;
        font-weight: 650;
        color: #c89b3c;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: transform 0.15s ease;
    }

    .category-choice-card:hover .category-action-btn {
        transform: translateX(3px);
        color: #9a6e1a;
    }

    .btn-change-category {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        font-size: 0.78rem;
        font-weight: 650;
        color: #475569;
        background: #ffffff;
        border: 1.5px solid #d1d5db;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.18s ease;
        text-decoration: none;
    }

    .btn-change-category:hover {
        background: #fdf8ed;
        color: #8c6427;
        border-color: #c89b3c;
        box-shadow: 0 2px 8px rgba(200, 155, 60, 0.15);
    }

    .category-step-fade {
        animation: categoryStepFadeIn 0.24s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    @keyframes categoryStepFadeIn {
        from {
            opacity: 0;
            transform: translateY(6px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
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

    /* Payment and Release Method Styles */
    .payment-method-card,
    .release-method-card {
        position: relative;
        display: block;
        cursor: pointer;
        margin-bottom: 0;
        height: 100%;
    }

    .payment-method-card input[type="radio"],
    .release-method-card input[type="radio"] {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .payment-card-inner,
    .release-card-inner {
        position: relative;
        height: 100%;
        min-height: 110px;
        padding: 18px 18px;
        border: 1.5px solid #e7e2d8;
        border-radius: 12px;
        background: #ffffff;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
    }

    .payment-method-card:hover .payment-card-inner,
    .release-method-card:hover .release-card-inner {
        border-color: #c89b3c;
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(46, 58, 45, 0.07);
    }

    .payment-method-card input[type="radio"]:checked + .payment-card-inner,
    .release-method-card input[type="radio"]:checked + .release-card-inner {
        border-color: #c89b3c;
        border-width: 2px;
        background: #fffdf9;
        box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.16), 0 8px 20px rgba(46, 58, 45, 0.08);
    }

    .payment-method-card input[type="radio"]:checked + .payment-card-inner::after,
    .release-method-card input[type="radio"]:checked + .release-card-inner::after {
        content: "\f00c";
        font-family: "Font Awesome 6 Free";
        font-weight: 900;
        position: absolute;
        top: 12px;
        right: 12px;
        width: 20px;
        height: 20px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        color: #ffffff;
        background: #c89b3c;
        font-size: 10px;
    }

    .payment-icon-box,
    .release-icon-box {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.05rem;
    }

    .gcash-bg {
        background: #e0f2fe;
        color: #0284c7;
    }

    .cash-bg {
        background: #dcfce7;
        color: #16a34a;
    }

    .online-bg {
        background: #e0f2fe;
        color: #0284c7;
    }

    .walkin-bg {
        background: #f1f5f9;
        color: #475569;
    }

    .payment-guide {
        display: grid;
        grid-template-columns: minmax(220px, 280px) minmax(0, 1fr);
        gap: 16px;
        align-items: start;
        padding: 16px;
        border: 1px solid #eadfca;
        border-radius: 14px;
        background: #fffdfa;
    }

    @media (max-width: 768px) {
        .payment-guide {
            grid-template-columns: 1fr;
        }
    }

    .payment-contact-card {
        display: grid;
        justify-items: center;
        padding: 18px 14px;
        border: 1px solid #ead9af;
        border-radius: 14px;
        background: linear-gradient(135deg, #fbf3df, #f7ecd6);
        text-align: center;
    }

    .payment-contact-avatar {
        width: 48px;
        height: 48px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 8px;
        border: 3px solid #fff;
        border-radius: 50%;
        color: #2a241c;
        background: #b9863a;
        box-shadow: 0 4px 12px rgba(140, 100, 39, 0.22);
        font-size: 0.95rem;
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
        margin-top: 2px;
        color: #2a241c;
        font-family: Georgia, "Times New Roman", serif;
        font-size: 1rem;
        font-weight: 800;
    }

    .payment-contact-number-row {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-top: 12px;
        padding: 8px 10px;
        border: 1px solid #ead9af;
        border-radius: 10px;
        background: #fff;
    }

    .payment-contact-number {
        font-weight: 800;
        font-size: 0.95rem;
        color: #1e293b;
        letter-spacing: 0.5px;
    }

    .payment-copy-button {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 8px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: #f8fafc;
        color: #475569;
        font-size: 0.75rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .payment-copy-button:hover {
        background: #fdf8ed;
        color: #8c6427;
        border-color: #c89b3c;
    }

    .payment-instructions h6 {
        font-size: 0.95rem;
        margin-bottom: 8px;
    }

    .payment-guide-list {
        padding-left: 18px;
        font-size: 0.85rem;
        color: #4b5563;
        line-height: 1.5;
    }

    .payment-guide-list li {
        margin-bottom: 4px;
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

        body.certificate-mobile-page .certificate-mobile-type-field,
        body.certificate-mobile-page .certificate-mobile-type-field + .mt-3,
        body.certificate-mobile-page .certificate-option-grid + .mt-3 {
            display: none !important;
        }

        body.certificate-mobile-page .category-choice-grid {
            grid-template-columns: 1fr !important;
            gap: 10px !important;
        }

        body.certificate-mobile-page .category-choice-card .category-card-inner {
            min-height: auto !important;
            padding: 14px 14px !important;
            gap: 10px !important;
        }

        body.certificate-mobile-page .category-icon-box {
            width: 38px !important;
            height: 38px !important;
            font-size: 16px !important;
        }

        body.certificate-mobile-page .category-card-title {
            font-size: 1rem !important;
        }

        body.certificate-mobile-page .category-card-desc {
            font-size: 0.78rem !important;
        }

        body.certificate-mobile-page .category-prompt-heading {
            font-size: 1.05rem !important;
        }

        body.certificate-mobile-page .btn-change-category {
            padding: 4px 10px !important;
            font-size: 0.72rem !important;
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

    <?php if (!empty($duplicate_notice)): ?>
        <div class="alert alert-warning alert-dismissible fade show border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-3 p-3 mb-4" style="background: #FFFBEB; border-left: 5px solid #F59E0B !important; border-radius: 12px;" role="alert">
            <div class="d-flex align-items-start gap-3">
                <div class="text-warning fs-3 mt-1"><i class="fas fa-triangle-exclamation"></i></div>
                <div>
                    <h6 class="mb-1 fw-bold text-dark" style="font-size: 1rem;">Duplicate Request Not Allowed</h6>
                    <p class="mb-0 text-secondary" style="font-size: 0.92rem;">
                        <?php echo e($duplicate_notice['message']); ?>
                        <?php if (!empty($duplicate_notice['reference'])): ?>
                            <span class="d-block mt-1">
                                <strong class="text-dark">Active Reference:</strong> 
                                <span class="badge bg-dark font-monospace px-2 py-1 ms-1"><?php echo e($duplicate_notice['reference']); ?></span>
                                <?php 
                                    $bannerStatus = strtoupper($duplicate_notice['status'] ?? 'PENDING');
                                    if ($bannerStatus === 'SUBMITTED') $bannerStatus = 'PENDING';
                                ?>
                                <span class="badge bg-warning text-dark text-uppercase px-2 py-1 ms-1"><?php echo e($bannerStatus); ?></span>
                            </span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-warning text-dark fw-semibold" data-bs-toggle="modal" data-bs-target="#duplicateRequestModal" style="border-radius: 8px;">
                    <i class="fas fa-eye me-1"></i> View Notice
                </button>
                <a href="<?php echo e($duplicate_notice['track_url']); ?>" class="btn btn-sm text-white fw-semibold" style="background: #C89B3C; border-color: #A97F24; border-radius: 8px;">
                    <i class="fas fa-receipt me-1"></i> Track Active Request
                </a>
                <button type="button" class="btn-close ms-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-3 p-3 mb-4" style="border-radius: 12px;">
            <i class="fas fa-circle-exclamation fs-4 text-danger"></i>
            <div class="flex-grow-1"><?php echo e($error); ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
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
            <section class="form-step" id="step1Section">
                <div class="step-heading">
                    <span class="step-number">1</span>
                    <div>
                        <h3 id="step1Title">Certificate Information</h3>
                        <p id="step1Description">Select the document category and sacramental certificate you need.</p>
                    </div>
                </div>

                <?php
                    $selected_request_type = (string)($_POST['request_type'] ?? $_POST['certificate_mobile_type'] ?? '');
                    $active_category = $certificate_meta[$selected_request_type]['category'] ?? '';
                ?>

                <!-- Step 1a: Category Selection ("What do you need?") -->
                <div class="category-selection-container" id="categorySelectionView" style="<?php echo !empty($active_category) ? 'display: none;' : ''; ?>">
                    <div class="category-prompt-box">
                        <h4 class="category-prompt-heading"><i class="fas fa-layer-group text-warning me-2"></i> What do you need?</h4>
                        <p class="category-prompt-subtext">Please choose a category below to view the available sacramental document options.</p>
                    </div>

                    <div class="category-choice-grid" role="radiogroup" aria-label="What do you need?">
                        <!-- Category 1: Certification -->
                        <div class="category-choice-card <?php echo ($active_category === 'certification') ? 'is-selected' : ''; ?>" data-category="certification" tabindex="0" role="button" aria-pressed="<?php echo ($active_category === 'certification') ? 'true' : 'false'; ?>">
                            <div class="category-card-inner">
                                <div class="category-card-top">
                                    <span class="category-icon-box certification-color">
                                        <i class="fas fa-file-signature"></i>
                                    </span>
                                    <span class="cert-group-badge certification"><i class="fas fa-stamp me-1"></i> Registry Extract</span>
                                </div>
                                <div class="category-card-body">
                                    <strong class="category-card-title">Certification</strong>
                                    <p class="category-card-desc">A certified extract transcribed directly from the parish's official canonical registry books.</p>
                                </div>
                                <div class="category-card-footer">
                                    <span class="category-count-pill"><i class="fas fa-layer-group text-warning me-1"></i> 5 types available</span>
                                    <span class="category-action-btn">Select <i class="fas fa-arrow-right ms-1"></i></span>
                                </div>
                            </div>
                        </div>

                        <!-- Category 2: Original Certificate -->
                        <div class="category-choice-card <?php echo ($active_category === 'certificate') ? 'is-selected' : ''; ?>" data-category="certificate" tabindex="0" role="button" aria-pressed="<?php echo ($active_category === 'certificate') ? 'true' : 'false'; ?>">
                            <div class="category-card-inner">
                                <div class="category-card-top">
                                    <span class="category-icon-box certificate-color">
                                        <i class="fas fa-scroll"></i>
                                    </span>
                                    <span class="cert-group-badge certificate"><i class="fas fa-certificate me-1"></i> Canonical Certificate</span>
                                </div>
                                <div class="category-card-body">
                                    <strong class="category-card-title">Original Certificate</strong>
                                    <p class="category-card-desc">An official canonical commemorative certificate for a sacrament celebrated in this parish.</p>
                                </div>
                                <div class="category-card-footer">
                                    <span class="category-count-pill"><i class="fas fa-layer-group text-info me-1"></i> 3 types available</span>
                                    <span class="category-action-btn">Select <i class="fas fa-arrow-right ms-1"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Group 1: Sacramental Certifications -->
                <div class="cert-group-block" data-category="certification" style="<?php echo ($active_category === 'certification') ? '' : 'display: none;'; ?>">
                    <div class="cert-group-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="cert-group-info">
                            <span class="cert-group-title"><i class="fas fa-file-signature text-warning me-2"></i> Sacramental Certifications</span>
                            <span class="cert-group-desc">Certified official extracts transcribed directly from parish canonical registry books.</span>
                        </div>
                        <div class="cert-group-actions d-flex align-items-center gap-2">
                            <span class="cert-group-badge certification"><i class="fas fa-stamp me-1"></i> Registry Extract</span>
                            <button type="button" class="btn-change-category" data-action="change-category" title="Choose another document category">
                                <i class="fas fa-arrow-left"></i> Change selection
                            </button>
                        </div>
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
                <div class="cert-group-block" data-category="certificate" style="<?php echo ($active_category === 'certificate') ? '' : 'display: none;'; ?>">
                    <div class="cert-group-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div class="cert-group-info">
                            <span class="cert-group-title"><i class="fas fa-scroll text-warning me-2"></i> Sacramental Certificates</span>
                            <span class="cert-group-desc">Official canonical commemorative certificates for sacraments celebrated in this parish.</span>
                        </div>
                        <div class="cert-group-actions d-flex align-items-center gap-2">
                            <span class="cert-group-badge certificate"><i class="fas fa-certificate me-1"></i> Canonical Certificate</span>
                            <button type="button" class="btn-change-category" data-action="change-category" title="Choose another document category">
                                <i class="fas fa-arrow-left"></i> Change selection
                            </button>
                        </div>
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
                        <h3>Applicant & Record Details</h3>
                        <p>Confirm who is submitting this request and whose sacramental record is needed.</p>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-4">
                        <label class="form-label">Applicant Name (You)</label>
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
                    <div class="col-12 mt-1">
                        <label for="record_holder_name" class="form-label"><strong>Record Holder's Full Name (Person on Certificate)</strong> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control request-form-control" id="record_holder_name" name="record_holder_name" value="<?php echo e($_POST['record_holder_name'] ?? $_SESSION['fullname'] ?? ''); ?>" placeholder="Full legal name as it appears in parish sacramental records" required>
                        <div class="form-text text-muted"><i class="fas fa-info-circle"></i> If requesting your own certificate, keep your name. If requesting for your child, spouse, or relative, enter their full legal name.</div>
                    </div>

                    <!-- Dedicated Baptism Sacramental Record Fields (maps 1:1 with official baptism record; priest omitted) -->
                    <div id="baptismSacramentalFields" class="col-12 mt-3 pt-3 border-top" style="<?php echo (in_array(($_POST['request_type'] ?? ''), ['baptismal_certificate', 'baptism_certification', 'baptism'], true)) ? '' : 'display: none;'; ?>">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3 rounded-3" style="background: #eef7fc; border: 1px solid #b9e2fe;">
                            <i class="fas fa-water text-primary fs-5"></i>
                            <div>
                                <strong class="text-dark">Canonical Sacramental Record Details (Baptism)</strong>
                                <div class="text-secondary">Please enter the exact details as registered in church books. All 10 fields below and at least 2 sponsors are required for record verification.</div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="birth_place" class="form-label">Place of Birth (City / Municipality, Province) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control request-form-control baptism-req-input" id="birth_place" name="birth_place" value="<?php echo e($_POST['birth_place'] ?? ''); ?>" placeholder="e.g. Quezon City, Metro Manila">
                            </div>
                            <div class="col-md-6">
                                <label for="birth_date" class="form-label">Date of Birth (Birthday) <span class="text-danger">*</span></label>
                                <input type="date" class="form-control request-form-control baptism-req-input" id="birth_date" name="birth_date" value="<?php echo e($_POST['birth_date'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="residence" class="form-label">Current Residence / Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control request-form-control baptism-req-input" id="residence" name="residence" value="<?php echo e($_POST['residence'] ?? ''); ?>" placeholder="e.g. 123 Sampaguita St., Brgy. Common, QC">
                            </div>
                            <div class="col-md-6">
                                <label for="baptism_date" class="form-label">Date of Baptism <span class="text-danger">*</span></label>
                                <input type="date" class="form-control request-form-control baptism-req-input" id="baptism_date" name="baptism_date" value="<?php echo e($_POST['baptism_date'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="father_name" class="form-label">Father's Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control request-form-control baptism-req-input" id="father_name" name="father_name" value="<?php echo e($_POST['father_name'] ?? ''); ?>" placeholder="Full legal name of father">
                            </div>
                            <div class="col-md-6">
                                <label for="father_birth_place" class="form-label">Father's Place of Birth <span class="text-danger">*</span></label>
                                <input type="text" class="form-control request-form-control baptism-req-input" id="father_birth_place" name="father_birth_place" value="<?php echo e($_POST['father_birth_place'] ?? ''); ?>" placeholder="e.g. Manila">
                            </div>
                            <div class="col-md-6">
                                <label for="mother_name" class="form-label">Mother's Maiden Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control request-form-control baptism-req-input" id="mother_name" name="mother_name" value="<?php echo e($_POST['mother_name'] ?? ''); ?>" placeholder="Complete maiden name before marriage">
                            </div>
                            <div class="col-md-6">
                                <label for="mother_birth_place" class="form-label">Mother's Place of Birth <span class="text-danger">*</span></label>
                                <input type="text" class="form-control request-form-control baptism-req-input" id="mother_birth_place" name="mother_birth_place" value="<?php echo e($_POST['mother_birth_place'] ?? ''); ?>" placeholder="e.g. Malolos, Bulacan">
                            </div>
                        </div>

                        <!-- Dynamic Multi-Sponsors Container -->
                        <div class="border rounded-3 p-3 bg-light mt-3">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                <div>
                                    <label class="form-label fw-bold mb-0">Sponsors / Ninong &amp; Ninang <span class="text-danger">*</span></label>
                                    <small class="text-muted d-block" style="font-size: 0.8rem;">List each sponsor individually. At least 2 sponsors are required.</small>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="addParishionerSponsorBtn">
                                    <i class="fas fa-plus me-1"></i> Add Sponsor
                                </button>
                            </div>
                            <div id="parishionerSponsorsList">
                                <?php 
                                    $posted_sponsors = $_POST['sponsors'] ?? ['', ''];
                                    if (!is_array($posted_sponsors) || count($posted_sponsors) < 2) {
                                        $posted_sponsors = ['', ''];
                                    }
                                    foreach ($posted_sponsors as $idx => $spVal): 
                                ?>
                                    <div class="input-group input-group-sm mb-2 parishioner-sponsor-row">
                                        <span class="input-group-text"><i class="fas fa-user-check text-secondary"></i> <span class="sponsor-num ms-1"><?php echo ($idx + 1); ?></span></span>
                                        <input type="text" name="sponsors[]" class="form-control parishioner-sponsor-input" placeholder="Full name of sponsor (e.g. Maria Santos)" value="<?php echo e($spVal); ?>">
                                        <button type="button" class="btn btn-outline-danger remove-parishioner-sponsor" title="Remove sponsor" <?php echo count($posted_sponsors) <= 2 ? 'disabled' : ''; ?>><i class="fas fa-trash"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Dedicated First Communion Supporting Details (Optional — helps auto-match) -->
                    <div id="communionSacramentalFields" class="col-12 mt-3 pt-3 border-top" style="<?php echo (in_array(($_POST['request_type'] ?? ''), ['first_communion_certificate', 'first_communion_certification', 'first_communion', 'communion'], true)) ? '' : 'display: none;'; ?>">
                        <div class="alert alert-warning py-2 px-3 small d-flex align-items-center gap-2 mb-3 rounded-3" style="background: #fefce8; border: 1px solid #fef08a;">
                            <i class="fas fa-wheat-awn text-warning fs-5"></i>
                            <div>
                                <strong class="text-dark">First Communion Verification Details (Optional)</strong>
                                <div class="text-secondary">Providing these details helps the parish system automatically find and link your sacramental record faster.</div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="communion_date" class="form-label">Date of First Communion</label>
                                <input type="date" class="form-control request-form-control" id="communion_date" name="communion_date" value="<?php echo e($_POST['communion_date'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="communion_father_name" class="form-label">Father's Full Name</label>
                                <input type="text" class="form-control request-form-control" id="communion_father_name" name="communion_father_name" value="<?php echo e($_POST['communion_father_name'] ?? ''); ?>" placeholder="Father's name">
                            </div>
                            <div class="col-md-4">
                                <label for="communion_mother_name" class="form-label">Mother's Maiden Name</label>
                                <input type="text" class="form-control request-form-control" id="communion_mother_name" name="communion_mother_name" value="<?php echo e($_POST['communion_mother_name'] ?? ''); ?>" placeholder="Mother's maiden name">
                            </div>
                        </div>
                    </div>

                    <!-- Dedicated Confirmation Supporting Details (Optional — helps auto-match) -->
                    <div id="confirmationSacramentalFields" class="col-12 mt-3 pt-3 border-top" style="<?php echo (in_array(($_POST['request_type'] ?? ''), ['confirmation_certificate', 'confirmation_certification', 'confirmation'], true)) ? '' : 'display: none;'; ?>">
                        <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3 rounded-3" style="background: #eef2ff; border: 1px solid #c7d2fe;">
                            <i class="fas fa-dove text-primary fs-5"></i>
                            <div>
                                <strong class="text-dark">Confirmation Verification Details (Optional)</strong>
                                <div class="text-secondary">Providing these details helps the parish system automatically find and link your sacramental record faster.</div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="confirmation_date" class="form-label">Date of Confirmation</label>
                                <input type="date" class="form-control request-form-control" id="confirmation_date" name="confirmation_date" value="<?php echo e($_POST['confirmation_date'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="conf_father_name" class="form-label">Father's Full Name</label>
                                <input type="text" class="form-control request-form-control" id="conf_father_name" name="conf_father_name" value="<?php echo e($_POST['conf_father_name'] ?? ''); ?>" placeholder="Father's name">
                            </div>
                            <div class="col-md-3">
                                <label for="conf_mother_name" class="form-label">Mother's Maiden Name</label>
                                <input type="text" class="form-control request-form-control" id="conf_mother_name" name="conf_mother_name" value="<?php echo e($_POST['conf_mother_name'] ?? ''); ?>" placeholder="Mother's maiden name">
                            </div>
                            <div class="col-md-3">
                                <label for="conf_sponsor_name" class="form-label">Sponsor / Godparent Name</label>
                                <input type="text" class="form-control request-form-control" id="conf_sponsor_name" name="conf_sponsor_name" value="<?php echo e($_POST['conf_sponsor_name'] ?? ''); ?>" placeholder="Sponsor's name">
                            </div>
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
                        <h3>Upload All Requirements</h3>
                        <p>Upload all required documents for your certificate request (e.g. PSA / Birth Certificate, Valid ID, or supporting records).</p>
                    </div>
                </div>

                <label class="upload-zone" id="uploadZone" for="requirement_files">
                    <i class="fas fa-cloud-arrow-up"></i>
                    <strong>Upload all requirements</strong>
                    <small>Accepted formats: PDF, JPG, or PNG. Maximum 10MB per file. You can select and upload multiple files.</small>
                    <input type="file" id="requirement_files" name="requirement_files[]" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" multiple required>
                </label>
                <div class="file-preview" id="filePreview">
                    <div id="fileList">
                        <span id="fileName">Selected files</span>
                        <div class="text-muted small" id="fileSize">Ready to upload</div>
                    </div>
                    <div class="upload-progress" aria-hidden="true"><span></span></div>
                </div>

                <!-- Supporting Document - Baptism / Confirmation (single, optional) -->
                <div id="supportingDocSection" style="display:none; margin-top: 18px;">
                    <div class="alert alert-info py-2 px-3 small d-flex align-items-center gap-2 mb-3 rounded-3" style="background:#eef6ff;border:1px solid #bfdbfe;">
                        <i class="fas fa-paperclip text-primary fs-5"></i>
                        <div>
                            <strong class="text-dark">Attach Supporting Document <span class="text-muted fw-normal">(Optional)</span></strong>
                            <div class="text-secondary">You may attach any additional document to help verify your sacramental record (e.g. a photocopy of the original certificate for re-issuance, or a registration confirmation).</div>
                        </div>
                    </div>
                    <label class="upload-zone" style="background:#f8faff;border-color:#93c5fd;" for="supporting_doc">
                        <i class="fas fa-file-arrow-up" style="color:#3b82f6;"></i>
                        <strong>Attach Supporting Document</strong>
                        <small>PDF, JPG, or PNG &mdash; max 5MB</small>
                        <input type="file" id="supporting_doc" name="supporting_doc" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
                    </label>
                    <div id="supportingDocPreview" style="display:none; margin-top:8px;" class="file-preview">
                        <div class="text-muted small" id="supportingDocName"></div>
                    </div>
                </div>

                <!-- First Communion - Two required docs -->
                <div id="communionDocsSection" style="display:none; margin-top: 18px;">
                    <div class="alert alert-warning py-2 px-3 d-flex align-items-start gap-2 mb-3 rounded-3" style="background:#fffbeb;border:1px solid #fcd34d;">
                        <i class="fas fa-circle-exclamation text-warning fs-5 mt-1"></i>
                        <div>
                            <strong class="text-dark">First Communion &mdash; Two Required Documents</strong>
                            <div class="text-secondary small mt-1">Both documents below are <strong>required</strong> before your First Communion certificate request can be submitted. These allow the parish office to verify your eligibility.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="color:#1e293b;">
                            <i class="fas fa-water text-primary me-1"></i>
                            Baptismal Certificate <span class="text-danger">*</span>
                        </label>
                        <p class="text-muted small mb-2">Upload a copy of your Baptismal Certificate &mdash; required as proof of Baptism before receiving First Communion.</p>
                        <label class="upload-zone" style="background:#f8faff;border-color:#93c5fd;" for="communion_baptismal_doc">
                            <i class="fas fa-file-arrow-up" style="color:#3b82f6;"></i>
                            <strong>Attach Baptismal Certificate</strong>
                            <small>PDF, JPG, or PNG &mdash; max 5MB</small>
                            <input type="file" id="communion_baptismal_doc" name="communion_baptismal_doc" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
                        </label>
                        <div id="communionBaptismalPreview" style="display:none; margin-top:8px;" class="file-preview">
                            <div class="text-muted small" id="communionBaptismalName"></div>
                        </div>
                    </div>

                    <div>
                        <label class="form-label fw-semibold" style="color:#1e293b;">
                            <i class="fas fa-graduation-cap" style="color:#b45309;"></i>
                            Seminar Certificate / Proof of Attendance <span class="text-danger">*</span>
                        </label>
                        <p class="text-muted small mb-2">Upload your First Communion Seminar Certificate or proof that you completed the required formation/catechism sessions.</p>
                        <label class="upload-zone" style="background:#fffdf5;border-color:#fcd34d;" for="communion_seminar_doc">
                            <i class="fas fa-file-arrow-up" style="color:#b45309;"></i>
                            <strong>Attach Seminar Certificate / Proof of Attendance</strong>
                            <small>PDF, JPG, or PNG &mdash; max 5MB</small>
                            <input type="file" id="communion_seminar_doc" name="communion_seminar_doc" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
                        </label>
                        <div id="communionSeminarPreview" style="display:none; margin-top:8px;" class="file-preview">
                            <div class="text-muted small" id="communionSeminarName"></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="form-step" id="paymentReleaseStep">
                <div class="step-heading">
                    <span class="step-number">5</span>
                    <div>
                        <h3>Payment &amp; Release</h3>
                        <p>Select your payment method and choose how you would like to receive your official certificate.</p>
                    </div>
                </div>

                <!-- Part A: Payment Method -->
                <div class="mb-4">
                    <label class="form-label fw-bold mb-1" style="font-size: 0.95rem; color: #1e293b;">
                        <i class="fas fa-wallet text-warning me-1"></i> How will you pay? <span class="text-danger">*</span>
                    </label>
                    <p class="text-muted small mb-3">Choose whether you will pay via GCash transfer now or settle in cash at the parish office.</p>

                    <div class="row g-3 mb-3" role="radiogroup" aria-label="Payment Method">
                        <!-- GCash Option -->
                        <div class="col-md-6">
                            <label class="payment-method-card" for="payment_method_gcash">
                                <input type="radio" name="payment_method" id="payment_method_gcash" value="gcash" <?php echo (($_POST['payment_method'] ?? 'gcash') === 'gcash') ? 'checked' : ''; ?> required>
                                <div class="payment-card-inner">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="payment-icon-box gcash-bg">
                                            <i class="fas fa-mobile-screen-button"></i>
                                        </span>
                                        <span class="badge bg-primary-subtle text-primary fw-semibold px-2 py-1" style="font-size: 0.75rem;">Instant Online</span>
                                    </div>
                                    <strong class="d-block text-dark mb-1" style="font-size: 1rem;">GCash Transfer</strong>
                                    <small class="text-muted d-block">Pay digitally via GCash and attach your transaction screenshot below.</small>
                                </div>
                            </label>
                        </div>

                        <!-- Cash Option -->
                        <div class="col-md-6">
                            <label class="payment-method-card" for="payment_method_cash">
                                <input type="radio" name="payment_method" id="payment_method_cash" value="cash" <?php echo (($_POST['payment_method'] ?? '') === 'cash') ? 'checked' : ''; ?> required>
                                <div class="payment-card-inner">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="payment-icon-box cash-bg">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </span>
                                        <span class="badge bg-success-subtle text-success fw-semibold px-2 py-1" style="font-size: 0.75rem;">In-Person</span>
                                    </div>
                                    <strong class="d-block text-dark mb-1" style="font-size: 1rem;">Cash (Parish Office)</strong>
                                    <small class="text-muted d-block">Settle your certificate offering in cash directly at the parish office.</small>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Conditional GCash Payment Details Section -->
                    <div id="gcashPaymentDetails" class="payment-details-panel border rounded-3 p-3 mb-3" style="<?php echo (($_POST['payment_method'] ?? 'gcash') === 'cash') ? 'display: none;' : ''; ?> background: #fdfbf7; border-color: #eadfca !important;">
                        <div class="payment-guide mb-3">
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
                                <h6 class="fw-bold text-dark"><i class="fas fa-money-bill-wave text-warning me-1"></i> How to Pay via GCash</h6>
                                <ul class="payment-guide-list mb-0">
                                    <li>Send your certificate fee via GCash to the name and number provided above.</li>
                                    <li>Standard certificate offering is <strong>PHP 150.00</strong> (or the amount advised by parish office).</li>
                                    <li>Save a screenshot or photo of your payment transaction confirmation.</li>
                                    <li>Attach the receipt below so parish staff can verify your payment immediately.</li>
                                </ul>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold" for="payment_amount">Amount (PHP) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control request-form-control" id="payment_amount" name="payment_amount" min="1" step="0.01" inputmode="decimal" placeholder="e.g. 150.00" value="<?php echo e($_POST['payment_amount'] ?? '150.00'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold" for="payment_reference">GCash Reference Number <span class="text-muted small fw-normal">(Optional)</span></label>
                                <input type="text" class="form-control request-form-control" id="payment_reference" name="payment_reference" placeholder="e.g. 1002 9384 1928" value="<?php echo e($_POST['payment_reference'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold" for="receipt_file">Receipt / Proof of Payment <span class="text-danger">*</span></label>
                                <input type="file" class="form-control request-form-control" id="receipt_file" name="receipt_file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
                                <div class="form-text text-muted">Upload your GCash confirmation receipt (JPG, PNG, PDF up to 10MB).</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold" for="payment_notes">Payment Notes <span class="text-muted small fw-normal">(Optional)</span></label>
                                <input type="text" class="form-control request-form-control" id="payment_notes" name="payment_notes" placeholder="Optional notes about your payment" value="<?php echo e($_POST['payment_notes'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Conditional Cash Notice -->
                    <div id="cashPaymentNotice" class="alert alert-warning py-3 px-3 rounded-3" style="<?php echo (($_POST['payment_method'] ?? 'gcash') === 'cash') ? '' : 'display: none;' ?> background: #fffcf0; border: 1px solid #f6e0b5;">
                        <div class="d-flex align-items-start gap-2">
                            <i class="fas fa-info-circle text-warning fs-5 mt-1"></i>
                            <div>
                                <strong class="text-dark d-block mb-1">Cash Settlement at Parish Office</strong>
                                <span class="text-secondary small">Please settle your certificate offering in cash at the parish office. Staff will confirm payment upon processing or release.</span>
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="my-4" style="border-color: #e9e1d2;">

                <!-- Part B: Release Method -->
                <div>
                    <label class="form-label fw-bold mb-1" style="font-size: 0.95rem; color: #1e293b;">
                        <i class="fas fa-hand-holding-heart text-warning me-1"></i> How would you like to receive your certificate? <span class="text-danger">*</span>
                    </label>
                    <p class="text-muted small mb-3">Choose whether you prefer digital download/delivery or physical pickup at the parish office.</p>

                    <div class="row g-3" role="radiogroup" aria-label="Release Method">
                        <!-- Online Release -->
                        <div class="col-md-6">
                            <label class="release-method-card" for="release_method_online">
                                <input type="radio" name="release_method" id="release_method_online" value="online" <?php echo (($_POST['release_method'] ?? 'online') === 'online') ? 'checked' : ''; ?> required>
                                <div class="release-card-inner">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="release-icon-box online-bg">
                                            <i class="fas fa-globe"></i>
                                        </span>
                                        <span class="badge bg-info-subtle text-info fw-semibold px-2 py-1" style="font-size: 0.75rem;">Digital Delivery</span>
                                    </div>
                                    <strong class="d-block text-dark mb-1" style="font-size: 1rem;">Online Release</strong>
                                    <small class="text-muted d-block">Delivered digitally through the system and downloadable directly from your portal once approved.</small>
                                </div>
                            </label>
                        </div>

                        <!-- Walk-in Release -->
                        <div class="col-md-6">
                            <label class="release-method-card" for="release_method_walkin">
                                <input type="radio" name="release_method" id="release_method_walkin" value="walk_in" <?php echo (($_POST['release_method'] ?? '') === 'walk_in') ? 'checked' : ''; ?> required>
                                <div class="release-card-inner">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <span class="release-icon-box walkin-bg">
                                            <i class="fas fa-building-columns"></i>
                                        </span>
                                        <span class="badge bg-secondary-subtle text-secondary fw-semibold px-2 py-1" style="font-size: 0.75rem;">Parish Pickup</span>
                                    </div>
                                    <strong class="d-block text-dark mb-1" style="font-size: 1rem;">Walk-in Release</strong>
                                    <small class="text-muted d-block">Pick up the printed and sealed official certificate in person at the parish office.</small>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Release Notes Info -->
                    <div id="onlineReleaseInfo" class="alert alert-info py-2 px-3 mt-3 rounded-3 small" style="<?php echo (($_POST['release_method'] ?? 'online') === 'walk_in') ? 'display: none;' : ''; ?> background: #f0f7ff; border: 1px solid #cce3fe;">
                        <i class="fas fa-envelope-circle-check text-primary me-1"></i> Digital certificate notification will be sent to <strong><?php echo e($_SESSION['email'] ?? 'your registered email'); ?></strong> and available in your request details once signed and ready.
                    </div>
                    <div id="walkinReleaseInfo" class="alert alert-secondary py-2 px-3 mt-3 rounded-3 small" style="<?php echo (($_POST['release_method'] ?? '') === 'walk_in') ? '' : 'display: none;'; ?> background: #f8fafc; border: 1px solid #e2e8f0;">
                        <i class="fas fa-clock text-secondary me-1"></i> Office pickup hours: <strong>Tuesday to Sunday, 8:00 AM – 5:00 PM</strong> at San Lorenzo Ruiz Parish Office. Please present a valid ID when claiming.
                    </div>
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
        const certificateMeta = <?php echo json_encode($certificate_meta); ?>;

        const categorySelectionView = document.getElementById('categorySelectionView');
        const categoryCards = document.querySelectorAll('.category-choice-card');
        const certGroupBlocks = document.querySelectorAll('.cert-group-block');
        const changeCategoryBtns = document.querySelectorAll('[data-action="change-category"]');
        let currentCategory = '<?php echo e($active_category); ?>' || (function() {
            const checked = document.querySelector('input[name="request_type"]:checked');
            if (checked && checked.value && certificateMeta[checked.value]) {
                return certificateMeta[checked.value].category || '';
            }
            return '';
        })();

        // Payment & Release Handlers
        const paymentRadios = document.querySelectorAll('input[name="payment_method"]');
        const gcashDetails = document.getElementById('gcashPaymentDetails');
        const cashNotice = document.getElementById('cashPaymentNotice');
        const receiptInput = document.getElementById('receipt_file');
        const amountInput = document.getElementById('payment_amount');

        function updatePaymentMethod() {
            const checked = document.querySelector('input[name="payment_method"]:checked');
            const method = checked ? checked.value : 'gcash';
            if (gcashDetails) gcashDetails.style.display = (method === 'gcash') ? 'block' : 'none';
            if (cashNotice) cashNotice.style.display = (method === 'cash') ? 'block' : 'none';
            if (receiptInput) receiptInput.required = (method === 'gcash');
            if (amountInput) amountInput.required = (method === 'gcash');
        }
        paymentRadios.forEach(function(r) {
            r.addEventListener('change', updatePaymentMethod);
        });
        updatePaymentMethod();

        const releaseRadios = document.querySelectorAll('input[name="release_method"]');
        const onlineInfo = document.getElementById('onlineReleaseInfo');
        const walkinInfo = document.getElementById('walkinReleaseInfo');

        function updateReleaseMethod() {
            const checked = document.querySelector('input[name="release_method"]:checked');
            const method = checked ? checked.value : 'online';
            if (onlineInfo) onlineInfo.style.display = (method === 'online') ? 'block' : 'none';
            if (walkinInfo) walkinInfo.style.display = (method === 'walk_in') ? 'block' : 'none';
        }
        releaseRadios.forEach(function(r) {
            r.addEventListener('change', updateReleaseMethod);
        });
        updateReleaseMethod();

        document.querySelectorAll('[data-copy-gcash]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const num = btn.getAttribute('data-copy-gcash') || '09977428176';
                navigator.clipboard.writeText(num).then(function() {
                    const span = btn.querySelector('span');
                    const orig = span ? span.textContent : 'Copy';
                    if (span) span.textContent = 'Copied!';
                    setTimeout(function() {
                        if (span) span.textContent = orig;
                    }, 2000);
                }).catch(function() {
                    prompt('GCash Number:', num);
                });
            });
        });

        function showCategoryFlow(cat, options) {
            options = options || {};
            const shouldAnimate = options.animate !== false;
            const isSwitchingCategory = currentCategory && cat && currentCategory !== cat;

            // If switching from one category to another, clear previous selection as per spec
            if (isSwitchingCategory) {
                radios.forEach(function(r) {
                    r.checked = false;
                });
                if (mobileSelect) mobileSelect.value = '';
                if (select) select.value = '';
                toggleBaptismFields('');
            }

            currentCategory = cat || '';

            // Update category cards visual state
            categoryCards.forEach(function(card) {
                const isMatch = (card.dataset.category === cat);
                card.classList.toggle('is-selected', isMatch);
                card.setAttribute('aria-pressed', isMatch ? 'true' : 'false');
            });

            if (cat) {
                if (categorySelectionView) {
                    categorySelectionView.style.display = 'none';
                }
                certGroupBlocks.forEach(function(block) {
                    if (block.dataset.category === cat) {
                        block.style.display = 'block';
                        if (shouldAnimate) {
                            block.classList.remove('category-step-fade');
                            void block.offsetWidth;
                            block.classList.add('category-step-fade');
                        }
                    } else {
                        block.style.display = 'none';
                    }
                });
            } else {
                // Return to category selection screen
                if (categorySelectionView) {
                    categorySelectionView.style.display = 'block';
                    if (shouldAnimate) {
                        categorySelectionView.classList.remove('category-step-fade');
                        void categorySelectionView.offsetWidth;
                        categorySelectionView.classList.add('category-step-fade');
                    }
                }
                certGroupBlocks.forEach(function(block) {
                    block.style.display = 'none';
                });
            }
        }

        categoryCards.forEach(function(card) {
            function handleCategorySelect() {
                const cat = card.dataset.category;
                categoryCards.forEach(function(c) {
                    c.classList.remove('is-selected');
                    c.setAttribute('aria-pressed', 'false');
                });
                card.classList.add('is-selected');
                card.setAttribute('aria-pressed', 'true');

                // Smooth tactile transition delay (160ms) before displaying filtered view
                setTimeout(function() {
                    showCategoryFlow(cat, { animate: true });
                }, 160);
            }

            card.addEventListener('click', handleCategorySelect);
            card.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    handleCategorySelect();
                }
            });
        });

        changeCategoryBtns.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const prevCat = currentCategory;
                showCategoryFlow('', { animate: true });
                if (prevCat) {
                    const prevCard = document.querySelector('.category-choice-card[data-category="' + prevCat + '"]');
                    if (prevCard) prevCard.focus();
                }
            });
        });

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

        function isBaptismType(type) {
            return (type === 'baptismal_certificate' || type === 'baptism_certification' || type === 'baptism');
        }

        function isCommunionType(type) {
            return (type === 'first_communion_certificate' || type === 'first_communion_certification' || type === 'first_communion' || type === 'communion');
        }

        function isConfirmationType(type) {
            return (type === 'confirmation_certificate' || type === 'confirmation_certification' || type === 'confirmation');
        }

        function toggleBaptismFields(type) {
            const isBap = isBaptismType(type);
            const isCom = isCommunionType(type);
            const isConf = isConfirmationType(type);

            const bapContainer = document.getElementById('baptismSacramentalFields');
            if (bapContainer) {
                bapContainer.style.display = isBap ? 'block' : 'none';
            }
            const comContainer = document.getElementById('communionSacramentalFields');
            if (comContainer) {
                comContainer.style.display = isCom ? 'block' : 'none';
            }
            const confContainer = document.getElementById('confirmationSacramentalFields');
            if (confContainer) {
                confContainer.style.display = isConf ? 'block' : 'none';
            }

            const inputs = document.querySelectorAll('.baptism-req-input');
            inputs.forEach(function(inp) {
                inp.required = isBap;
            });

            // Toggle supporting doc upload zones
            const communionDocsSection  = document.getElementById('communionDocsSection');
            const supportingDocSection  = document.getElementById('supportingDocSection');

            // First Communion — two required docs
            if (communionDocsSection) {
                communionDocsSection.style.display = isCom ? 'block' : 'none';
                const cbDoc = document.getElementById('communion_baptismal_doc');
                const csDoc = document.getElementById('communion_seminar_doc');
                if (cbDoc) cbDoc.required = isCom;
                if (csDoc) csDoc.required = isCom;
            }
            // Baptism / Confirmation — optional single supporting doc
            if (supportingDocSection) {
                supportingDocSection.style.display = (isBap || isConf) ? 'block' : 'none';
            }
        }

        // File preview helpers for extra upload inputs
        (function initExtraUploadPreviews() {
            function bindFilePreview(inputId, previewDivId, nameElId) {
                const inp = document.getElementById(inputId);
                const previewDiv = document.getElementById(previewDivId);
                const nameEl = document.getElementById(nameElId);
                if (!inp || !previewDiv || !nameEl) return;
                inp.addEventListener('change', function() {
                    if (inp.files && inp.files.length > 0) {
                        nameEl.textContent = inp.files[0].name + ' (' + (inp.files[0].size / 1024).toFixed(1) + ' KB)';
                        previewDiv.style.display = 'block';
                    } else {
                        previewDiv.style.display = 'none';
                    }
                });
            }
            bindFilePreview('communion_baptismal_doc', 'communionBaptismalPreview', 'communionBaptismalName');
            bindFilePreview('communion_seminar_doc',   'communionSeminarPreview',   'communionSeminarName');
            bindFilePreview('supporting_doc',          'supportingDocPreview',      'supportingDocName');
        })();

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
            toggleBaptismFields(value);

            // Auto-switch to matching category if not already active
            if (certificateMeta[value] && certificateMeta[value].category) {
                const targetCat = certificateMeta[value].category;
                if (currentCategory !== targetCat) {
                    showCategoryFlow(targetCat, { animate: false });
                }
            }
        }

        const sponsorsList = document.getElementById('parishionerSponsorsList');
        const addSponsorBtn = document.getElementById('addParishionerSponsorBtn');

        function updateParishionerSponsorButtons() {
            if (!sponsorsList) return;
            const rows = sponsorsList.querySelectorAll('.parishioner-sponsor-row');
            rows.forEach(function(row, idx) {
                const label = row.querySelector('.sponsor-num');
                if (label) label.textContent = (idx + 1);
                const btn = row.querySelector('.remove-parishioner-sponsor');
                if (btn) btn.disabled = (rows.length <= 2);
            });
        }

        function createParishionerSponsorRow(val, idx) {
            const div = document.createElement('div');
            div.className = 'input-group input-group-sm mb-2 parishioner-sponsor-row';
            div.innerHTML = `
                <span class="input-group-text"><i class="fas fa-user-check text-secondary"></i> <span class="sponsor-num ms-1">${idx + 1}</span></span>
                <input type="text" name="sponsors[]" class="form-control parishioner-sponsor-input" placeholder="Full name of sponsor (e.g. Maria Santos)" value="${val || ''}">
                <button type="button" class="btn btn-outline-danger remove-parishioner-sponsor" title="Remove sponsor"><i class="fas fa-trash"></i></button>
            `;
            return div;
        }

        if (addSponsorBtn && sponsorsList) {
            addSponsorBtn.addEventListener('click', function() {
                const count = sponsorsList.querySelectorAll('.parishioner-sponsor-row').length;
                const newRow = createParishionerSponsorRow('', count);
                sponsorsList.appendChild(newRow);
                updateParishionerSponsorButtons();
                const inp = newRow.querySelector('input');
                if (inp) inp.focus();
            });
        }

        if (sponsorsList) {
            sponsorsList.addEventListener('click', function(e) {
                const btn = e.target.closest('.remove-parishioner-sponsor');
                if (btn && !btn.disabled) {
                    const row = btn.closest('.parishioner-sponsor-row');
                    if (row) {
                        row.remove();
                        updateParishionerSponsorButtons();
                    }
                }
            });
            updateParishionerSponsorButtons();
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

        // Initialize baptism toggle based on active selection
        const initialType = (document.querySelector('input[name="request_type"]:checked') || {}).value || (mobileSelect ? mobileSelect.value : '');
        if (initialType) {
            toggleBaptismFields(initialType);
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
            
            fileName.textContent = file_names.length === 1 ? file_names[0] : file_names.length + ' files selected';
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
                if (form.dataset.submitting === 'true') {
                    event.preventDefault();
                    return false;
                }

                const checkedRadio = document.querySelector('input[name="request_type"]:checked');
                const mobileVal = mobileSelect ? mobileSelect.value : '';
                const selectedType = (checkedRadio ? checkedRadio.value : '') || mobileVal;
                
                if (!selectedType) {
                    event.preventDefault();
                    alert('Please select a certificate type.');
                    if (!currentCategory) {
                        const firstCatCard = document.querySelector('.category-choice-card');
                        if (firstCatCard) firstCatCard.focus();
                    } else {
                        const activeBlock = document.querySelector('.cert-group-block[data-category="' + currentCategory + '"]');
                        if (activeBlock && window.getComputedStyle(activeBlock).display !== 'none') {
                            const firstRadio = activeBlock.querySelector('input[type="radio"]');
                            if (firstRadio) firstRadio.focus();
                        } else if (mobileSelect) {
                            mobileSelect.focus();
                        }
                    }
                    return false;
                }

                const recordHolderInput = document.getElementById('record_holder_name');
                if (recordHolderInput && !recordHolderInput.value.trim()) {
                    event.preventDefault();
                    alert('Please provide the full legal name of the person named on the certificate.');
                    recordHolderInput.focus();
                    return false;
                }

                if (isBaptismType(selectedType)) {
                    const bapFields = [
                        { id: 'record_holder_name', label: 'Full Name of Baptized Person' },
                        { id: 'birth_place', label: 'Place of Birth' },
                        { id: 'birth_date', label: 'Date of Birth (Birthday)' },
                        { id: 'residence', label: 'Current Residence / Address' },
                        { id: 'baptism_date', label: 'Date of Baptism' },
                        { id: 'father_name', label: "Father's Full Name" },
                        { id: 'father_birth_place', label: "Father's Birthplace" },
                        { id: 'mother_name', label: "Mother's Maiden Name" },
                        { id: 'mother_birth_place', label: "Mother's Birthplace" },
                    ];

                    let missing = [];
                    let firstMissingEl = null;
                    bapFields.forEach(function(f) {
                        const el = document.getElementById(f.id);
                        if (!el || !el.value.trim()) {
                            missing.push(f.label);
                            if (!firstMissingEl && el) firstMissingEl = el;
                        }
                    });

                    const spInputs = Array.from(sponsorsList ? sponsorsList.querySelectorAll('input[name="sponsors[]"]') : [])
                        .map(i => i.value.trim())
                        .filter(v => v.length > 0);

                    if (spInputs.length < 2) {
                        missing.push('At least 2 Sponsors / Ninong-Ninang');
                        if (!firstMissingEl && sponsorsList) {
                            firstMissingEl = sponsorsList.querySelector('input');
                        }
                    }

                    if (missing.length > 0) {
                        event.preventDefault();
                        alert('Please complete all required fields for the baptismal record:\n\n• ' + missing.join('\n• '));
                        if (firstMissingEl) firstMissingEl.focus();
                        return false;
                    }
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
                    alert('Please upload a copy of the required supporting document before submitting.');
                    fileInput.focus();
                    return false;
                }

                const paymentMethod = (document.querySelector('input[name="payment_method"]:checked') || {}).value || 'gcash';
                if (paymentMethod === 'gcash') {
                    const amountInput = document.getElementById('payment_amount');
                    if (amountInput && (!amountInput.value || parseFloat(amountInput.value) <= 0)) {
                        event.preventDefault();
                        alert('Please enter the GCash payment amount.');
                        amountInput.focus();
                        return false;
                    }
                    const receiptInput = document.getElementById('receipt_file');
                    if (receiptInput && (!receiptInput.files || receiptInput.files.length === 0)) {
                        event.preventDefault();
                        alert('Please upload your GCash payment receipt or confirmation screenshot.');
                        receiptInput.focus();
                        return false;
                    }
                }

                // Immediately lock submission and disable button on first click to prevent rapid double-clicks
                form.dataset.submitting = 'true';
                if (submitBtn) {
                    submitBtn.classList.add('is-loading');
                    submitBtn.disabled = true;
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

<!-- Duplicate Request Prevention Modal (Pop-up Notification) -->
<div class="modal fade" id="duplicateRequestModal" tabindex="-1" aria-labelledby="duplicateRequestModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 530px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden; background: #FFFFFF;">
            <div class="modal-header border-0 pb-0" style="background: linear-gradient(135deg, #FEF3C7 0%, #FFFBEB 100%); padding: 24px 24px 16px;">
                <div class="d-flex align-items-center gap-3">
                    <div class="d-flex align-items-center justify-content-center" style="width: 52px; height: 52px; border-radius: 50%; background: #FDE68A; color: #B45309; font-size: 24px; box-shadow: 0 4px 14px rgba(245, 158, 11, 0.3); flex-shrink: 0;">
                        <i class="fas fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="duplicateRequestModalLabel" style="font-family: 'Playfair Display', Georgia, serif; font-size: 1.25rem;">
                            Request Not Allowed
                        </h5>
                        <span class="badge text-uppercase" style="background: rgba(180, 83, 9, 0.15); color: #B45309; font-size: 0.72rem; letter-spacing: 0.5px;">Duplicate Request Prevention</span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="font-size: 0.85rem;"></button>
            </div>
            <div class="modal-body p-4 pt-3">
                <div class="p-3 mb-3" style="background: #FFFBEB; border: 1px solid #FCD34D; border-radius: 12px; color: #92400E;">
                    <div class="d-flex align-items-start gap-2">
                        <i class="fas fa-ban mt-1" style="color: #D97706; font-size: 1.1rem;"></i>
                        <p class="mb-0 fw-semibold" id="duplicateModalMainMessage" style="font-size: 0.98rem; line-height: 1.5;">
                            <?php echo e($duplicate_notice['message'] ?? "You're not allowed to request another certificate because it will duplicate your active request."); ?>
                        </p>
                    </div>
                </div>

                <div class="card border-0 mb-3" style="background: #FAF7F2; border: 1px solid #E8E1D5 !important; border-radius: 14px;">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom" style="border-color: #E8E1D5 !important;">
                            <span class="text-secondary small fw-semibold">Active Request Reference:</span>
                            <span class="badge bg-dark font-monospace px-2 py-1" id="duplicateModalRef" style="font-size: 0.85rem; letter-spacing: 0.5px;">
                                <?php echo e($duplicate_notice['reference'] ?? 'Active Reference'); ?>
                            </span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mb-2 pb-2 border-bottom" style="border-color: #E8E1D5 !important;">
                            <span class="text-secondary small fw-semibold">Current Status:</span>
                            <span class="badge bg-warning text-dark text-uppercase px-2 py-1" id="duplicateModalStatus" style="font-size: 0.8rem; font-weight: 700;">
                                <?php 
                                    $modalStatus = strtoupper($duplicate_notice['status'] ?? 'PENDING');
                                    if ($modalStatus === 'SUBMITTED') $modalStatus = 'PENDING';
                                    echo e($modalStatus); 
                                ?>
                            </span>
                        </div>
                        <div class="text-secondary small" style="line-height: 1.5;">
                            <i class="fas fa-circle-info text-warning me-1"></i>
                            <span id="duplicateModalDetails">
                                <?php echo e(str_ireplace('submitted', 'pending', $duplicate_notice['details'] ?? 'Duplicate requests cannot be submitted until your previous request is completed, rejected, or cancelled.')); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-start gap-2 text-muted small" style="line-height: 1.45;">
                    <i class="fas fa-shield-halved text-success mt-1"></i>
                    <span>To preserve canonical record accuracy and prevent duplicate processing, the parish allows only one active certificate request per record holder.</span>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 px-4 pb-4 gap-2">
                <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal" style="border-radius: 10px; font-weight: 600;">
                    I Understand
                </button>
                <a href="<?php echo e(!empty($duplicate_notice['track_url']) ? $duplicate_notice['track_url'] : 'my-requests.php'); ?>" id="duplicateModalTrackBtn" class="btn text-white px-4 fw-semibold shadow-sm" style="background: #C89B3C; border-color: #A97F24; border-radius: 10px;">
                    <i class="fas fa-receipt me-1"></i> Track Existing Request
                </a>
            </div>
        </div>
    </div>
</div>

<script>
    (function() {
        const activeCertRequests = <?php echo json_encode($active_certificate_requests); ?>;
        const serverDuplicateNotice = <?php echo json_encode($duplicate_notice); ?>;

        const familyMap = {
            'baptismal_certificate': 'baptism', 'baptism_certification': 'baptism', 'baptism': 'baptism',
            'confirmation_certificate': 'confirmation', 'confirmation_certification': 'confirmation', 'confirmation': 'confirmation',
            'first_communion_certificate': 'first_communion', 'first_communion_certification': 'first_communion', 'first_communion': 'first_communion',
            'marriage_certificate': 'marriage', 'marriage_certification': 'marriage', 'marriage': 'marriage',
            'funeral_certificate': 'funeral', 'funeral_certification': 'funeral', 'funeral': 'funeral', 'burial': 'funeral', 'death': 'funeral',
            'certificate': 'general_certificate'
        };

        function getCertFamily(type) {
            if (!type) return '';
            const t = String(type).toLowerCase().trim();
            return familyMap[t] || t;
        }

        function findMatchingActiveRequest(selectedType, recordHolderName) {
            if (!selectedType || !Array.isArray(activeCertRequests) || activeCertRequests.length === 0) {
                return null;
            }
            const targetFamily = getCertFamily(selectedType);
            const cleanHolder = String(recordHolderName || '').toLowerCase().trim();

            return activeCertRequests.find(function(item) {
                const itemFamily = item.certificate_family || getCertFamily(item.request_type);
                if (itemFamily !== targetFamily) {
                    return false;
                }
                if (!cleanHolder) {
                    return true;
                }
                const itemHolder = String(item.record_holder_name || '').toLowerCase().trim();
                return itemHolder === cleanHolder;
            }) || null;
        }

        function openDuplicateModal(data) {
            if (!data) return;
            const ref = data.reference || data.reference_number || 'Active Request';
            const rawStatus = (data.status || 'PENDING').toUpperCase();
            const status = rawStatus === 'SUBMITTED' ? 'PENDING' : rawStatus;
            const mainMsg = data.message || "You're not allowed to request another certificate because it will duplicate your active request.";
            const friendlyType = data.request_type ? data.request_type.replace(/_/g, ' ') : 'certificate';
            let details = data.details || ("An active " + friendlyType + " request (" + ref + ") is currently in " + status + " status. Duplicate requests cannot be submitted until your previous request is completed, rejected, or cancelled.");
            details = details.replace(/submitted/gi, 'pending');
            const trackUrl = data.track_url || ("my-requests.php?q=" + encodeURIComponent(ref));

            const msgEl = document.getElementById('duplicateModalMainMessage');
            const refEl = document.getElementById('duplicateModalRef');
            const statusEl = document.getElementById('duplicateModalStatus');
            const detailsEl = document.getElementById('duplicateModalDetails');
            const trackBtn = document.getElementById('duplicateModalTrackBtn');

            if (msgEl) msgEl.textContent = mainMsg;
            if (refEl) refEl.textContent = ref;
            if (statusEl) statusEl.textContent = status;
            if (detailsEl) detailsEl.textContent = details;
            if (trackBtn) trackBtn.href = trackUrl;

            const modalEl = document.getElementById('duplicateRequestModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
                modalInstance.show();
            }

            if (typeof ParishToast !== 'undefined' && typeof ParishToast.show === 'function') {
                ParishToast.show({
                    title: "Request Not Allowed",
                    message: mainMsg,
                    type: "warning",
                    duration: 6500
                });
            }
        }

        // 1. Auto-show on page load if server detected duplicate (HTTP 409)
        window.addEventListener('load', function() {
            if (serverDuplicateNotice) {
                openDuplicateModal(serverDuplicateNotice);
            }
        });

        // 2. Client-side prevention on form submission
        const certForm = document.getElementById('certificateRequestForm');
        if (certForm) {
            certForm.addEventListener('submit', function(event) {
                const selectedRadio = certForm.querySelector('input[name="request_type"]:checked');
                const selectedSelect = certForm.querySelector('select[name="certificate_mobile_type"]');
                const chosenType = (selectedRadio && selectedRadio.value) || (selectedSelect && selectedSelect.value) || '';

                const holderInput = document.getElementById('record_holder_name');
                const holderName = holderInput ? holderInput.value.trim() : '';

                if (chosenType) {
                    const match = findMatchingActiveRequest(chosenType, holderName);
                    if (match) {
                        event.preventDefault();
                        event.stopPropagation();
                        openDuplicateModal({
                            reference: match.reference_number,
                            status: match.status,
                            request_type: match.request_type,
                            message: "You're not allowed to request another certificate because it will duplicate your active request.",
                            track_url: "my-requests.php?q=" + encodeURIComponent(match.reference_number)
                        });
                        return false;
                    }
                }
            });
        }
    })();
</script>

<?php include '../templates/footer.php'; ?>
