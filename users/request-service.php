<?php
/**
 * Service Request Module - Handles reservation and parish service request submissions.
 */
include '../includes/session.php';
include '../config/security.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();
if (!hasPermission('requests.create')) {
    redirect('../auth/login.php');
}

$page_title = 'Sacramental Services';
$user_id = intval($_SESSION['user_id']);
$error = '';
$success = '';
ensureExpandedRequestTypeSchema($conn);
ensureRequestDocumentsSchema($conn);
ensureEmailNotificationSchema($conn);

$service_types = [
    'baptism_service' => 'Baptism',
    'marriage_wedding_service' => 'Marriage / Wedding',
    'funeral_mass' => 'Funeral Mass',
    'anointing_of_the_sick' => 'Anointing of the Sick',
    'patronal_fiesta' => 'Patronal Fiesta'
];
$service_meta = [
    'baptism_service' => ['icon' => 'fa-water', 'hint' => 'Schedule a baptism service with parish coordination.'],
    'marriage_wedding_service' => ['icon' => 'fa-ring', 'hint' => 'Request wedding or marriage service scheduling.'],
    'funeral_mass' => ['icon' => 'fa-cross', 'hint' => 'Coordinate funeral Mass details with the parish.'],
    'anointing_of_the_sick' => ['icon' => 'fa-hand-holding-medical', 'hint' => 'Request pastoral care and anointing schedule.'],
    'patronal_fiesta' => ['icon' => 'fa-church', 'hint' => 'Submit Patronal Fiesta details for parish review.']
];
$baptism_requirements = [
    'chapel_recommendation' => 'Chapel Recommendation',
    'latest_marriage_contract' => 'Latest Marriage Contract (if parents are married)',
    'marriage_certificate_receipt' => 'Latest Marriage Certificate / Marriage Contract Receipt',
    'marriage_certificate_photocopy' => 'Photocopy of Marriage Certificate (if married)',
    'live_birth_certificate' => 'Photocopy of Live Birth Certificate with Official Registry Number',
    'sponsor_white_cards' => 'Two (2) White Cards of Sponsors (Ninong and Ninang)',
    'parent_white_cards' => 'White Cards of Parents'
];
$baptism_sheet_fields = [
    'child_name' => 'Name of Child',
    'birth_day' => 'Birth Day',
    'birth_month' => 'Birth Month',
    'birth_year' => 'Birth Year',
    'birth_place' => 'Place of Birth',
    'baptism_day' => 'Baptism Day',
    'baptism_month' => 'Baptism Month',
    'baptism_year' => 'Baptism Year',
    'father_name' => 'Father',
    'father_origin' => 'Father Place of Origin',
    'mother_name' => 'Mother',
    'mother_origin' => 'Mother Place of Origin',
    'godparents' => 'Godparents',
    'father_residence' => 'Father Residence',
    'mother_residence' => 'Mother Residence',
    'authorized_signature' => 'Authorized Signature',
    'baptismal_seminar_head' => 'Head of the Baptismal Seminar'
];
$marriage_requirements = [
    'pre_cana' => 'Pre-Cana',
    'municipal_license' => 'Municipal License',
    'bec_recommendation' => 'BEC Recommendation',
    'baptismal_certificate_marriage_purpose' => 'Baptismal Certificate for Marriage Purpose',
    'confirmation_certificate' => 'Confirmation Certificate',
    'permit_to_marry' => 'Permit to Marry',
    'interview' => 'Interview',
    'confession' => 'Confession',
    'co_permit_police_army' => 'CO Permit (Police / Army)'
];
$status_meta = [
    'pending' => ['icon' => 'fa-hourglass-half', 'description' => 'Waiting for parish review', 'tone' => 'warning'],
    'approved' => ['icon' => 'fa-circle-check', 'description' => 'Approved by the office', 'tone' => 'success'],
    'processing' => ['icon' => 'fa-gears', 'description' => 'Being coordinated', 'tone' => 'primary'],
    'completed' => ['icon' => 'fa-file-circle-check', 'description' => 'Service completed', 'tone' => 'info'],
    'rejected' => ['icon' => 'fa-circle-xmark', 'description' => 'Needs correction', 'tone' => 'danger'],
    'cancelled' => ['icon' => 'fa-ban', 'description' => 'Cancelled request', 'tone' => 'secondary'],
];
$service_type_keys = array_keys($service_types);
$allowed_statuses = ['pending', 'approved', 'processing', 'completed', 'rejected', 'cancelled'];

$breadcrumbs = [
    'Dashboard' => 'index.php',
    'Sacramental Services' => null
];

// Service Label Function - Documents this helper's role in the parish management workflow.
function serviceLabel($value, $labels = []) {
    return $labels[$value] ?? ucfirst(str_replace('_', ' ', (string) $value));
}

function hasUploadedRequirementFiles($files) {
    if (empty($files) || !is_array($files) || empty($files['name'])) {
        return false;
    }

    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $errors = is_array($files['error'] ?? null) ? $files['error'] : [($files['error'] ?? UPLOAD_ERR_NO_FILE)];
    foreach ($names as $index => $name) {
        if (trim((string) $name) !== '' && intval($errors[$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            return true;
        }
    }

    return false;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $request_type = $_POST['request_type'] ?? '';
    $preferred_date = trim($_POST['preferred_date'] ?? '');
    $preferred_time = trim($_POST['preferred_time'] ?? '');
    $patronal_fiesta_date = trim($_POST['patronal_fiesta_date'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $details = trim($_POST['details'] ?? '');
    $confirmed_baptism_requirements = array_map('strval', $_POST['baptism_requirements'] ?? []);
    $baptism_acknowledged = ($_POST['baptism_requirements_ack'] ?? '') === '1';
    $missing_baptism_requirements = array_diff(array_keys($baptism_requirements), $confirmed_baptism_requirements);
    $baptism_sheet = [];
    foreach ($baptism_sheet_fields as $field_key => $field_label) {
        $baptism_sheet[$field_key] = trim((string) ($_POST['baptism_sheet'][$field_key] ?? ''));
    }
    $missing_baptism_sheet = [];
    foreach ($baptism_sheet_fields as $field_key => $field_label) {
        if ($baptism_sheet[$field_key] === '') {
            $missing_baptism_sheet[] = $field_label;
        }
    }
    $confirmed_marriage_requirements = $_POST['marriage_requirements'] ?? [];
    $marriage_acknowledged = ($_POST['marriage_requirements_ack'] ?? '') === '1';
    $missing_marriage_requirements = [];
    foreach ($marriage_requirements as $key => $label) {
        $male_confirmed = isset($confirmed_marriage_requirements['male']) && in_array($key, array_map('strval', (array) $confirmed_marriage_requirements['male']), true);
        $female_confirmed = isset($confirmed_marriage_requirements['female']) && in_array($key, array_map('strval', (array) $confirmed_marriage_requirements['female']), true);
        if (!$male_confirmed || !$female_confirmed) {
            $missing_marriage_requirements[] = $label;
        }
    }

    if ($request_type === 'patronal_fiesta' && $patronal_fiesta_date !== '') {
        $preferred_date = $patronal_fiesta_date;
    }

    if (!array_key_exists($request_type, $service_types)) {
        $error = 'Please choose a sacramental service.';
    } elseif ($request_type === 'baptism_service' && (!$baptism_acknowledged || !empty($missing_baptism_requirements))) {
        $error = 'Please review and confirm all Baptism requirements before proceeding.';
    } elseif ($request_type === 'baptism_service' && !empty($missing_baptism_sheet)) {
        $error = 'Please complete the Baptism Information Sheet before requesting Baptism. Missing: ' . implode(', ', array_slice($missing_baptism_sheet, 0, 4)) . (count($missing_baptism_sheet) > 4 ? ', and more.' : '.');
    } elseif ($request_type === 'baptism_service' && !hasUploadedRequirementFiles($_FILES['requirement_files'] ?? null)) {
        $error = 'Please upload the Baptism supporting documents before submitting your request.';
    } elseif ($request_type === 'marriage_wedding_service' && (!$marriage_acknowledged || !empty($missing_marriage_requirements))) {
        $error = 'Please review and confirm all Marriage requirements for both Male and Female before proceeding.';
    } elseif ($request_type === 'marriage_wedding_service' && !hasUploadedRequirementFiles($_FILES['requirement_files'] ?? null)) {
        $error = 'Please upload the Marriage supporting documents before submitting your request.';
    } elseif ($request_type === 'patronal_fiesta' && $patronal_fiesta_date === '') {
        $error = 'Please choose the date of the Patronal Fiesta.';
    } elseif ($preferred_date === '') {
        $error = 'Please choose a preferred date.';
    } elseif ($preferred_time === '') {
        $error = 'Please choose a preferred time.';
    } elseif ($location === '') {
        $error = 'Please provide the service location.';
    } else {
        $description_parts = [
            'Preferred date: ' . $preferred_date,
            'Preferred time: ' . $preferred_time,
            'Location: ' . $location,
        ];
        if ($request_type === 'patronal_fiesta') {
            $description_parts[] = 'Date of Patronal Fiesta: ' . $patronal_fiesta_date;
        }
        if ($request_type === 'baptism_service') {
            $description_parts[] = 'Baptism requirements reviewed: Yes';
            $description_parts[] = 'Confirmed requirements: ' . implode(', ', array_values($baptism_requirements));
            $description_parts[] = 'Baptism Information Sheet:';
            foreach ($baptism_sheet_fields as $field_key => $field_label) {
                $description_parts[] = $field_label . ': ' . $baptism_sheet[$field_key];
            }
        }
        if ($request_type === 'marriage_wedding_service') {
            $description_parts[] = 'Marriage requirements reviewed: Yes';
            $description_parts[] = 'Male confirmed requirements: ' . implode(', ', array_values($marriage_requirements));
            $description_parts[] = 'Female confirmed requirements: ' . implode(', ', array_values($marriage_requirements));
        }
        $description_parts[] = 'Details: ' . ($details ?: 'None');

        $description = implode("\n", $description_parts);
        $reference_number = generateReferenceNumber();
        $status = 'pending';

        $stmt = $conn->prepare("INSERT INTO requests (user_id, request_type, description, status, reference_number) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            $error = 'Unable to prepare your sacramental service request. Please contact the parish office.';
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
                    createNotification($conn, $user_id, 'Sacramental Service Request Created', 'Your service request has been submitted with reference: ' . $reference_number . ' (' . $doc_count . ' ' . $file_text . ' attached)');
                    sendRequestSubmittedEmail($conn, $user_id, $reference_number, 'sacramental service request');
                    $success = 'Sacramental service request submitted successfully! Reference: ' . $reference_number . ' (' . $doc_count . ' file' . ($doc_count === 1 ? '' : 's') . ' attached)';
                }
            } else {
                $error = 'Error submitting service request: ' . $conn->error;
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
$service_placeholders = implode(',', array_fill(0, count($service_type_keys), '?'));

$where = ['user_id = ?', "request_type IN ($service_placeholders)"];
$types = 'i' . str_repeat('s', count($service_type_keys));
$params = array_merge([$user_id], $service_type_keys);

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
$services = [];
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
        $services[] = $row;
    }
    $stmt->close();
}

$status_counts = array_fill_keys($allowed_statuses, 0);
$count_types = 'i' . str_repeat('s', count($service_type_keys));
$count_params = array_merge([$user_id], $service_type_keys);
$stmt = $conn->prepare("SELECT status, COUNT(*) AS count FROM requests WHERE user_id = ? AND request_type IN ($service_placeholders) GROUP BY status");
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
<link rel="stylesheet" href="../assets/css/request-modern.css">

<div class="container-fluid mt-4">
    <div class="request-modern-page">
        <section class="request-hero">
            <div class="request-hero-main">
                <span class="request-kicker"><i class="fas fa-church"></i> Sacramental Services</span>
                <h1>New Sacramental Service Request</h1>
                <p>Submit sacramental service schedules securely and efficiently. Add your preferred date, time, location, and supporting details so the parish office can coordinate the service.</p>
                <div class="request-badges">
                    <span><i class="fas fa-lock"></i> Secure Request Submission</span>
                    <span><i class="fas fa-bell"></i> Status Notifications</span>
                    <span><i class="fas fa-robot"></i> TUGON AI Assisted</span>
                </div>
            </div>
            <aside class="request-secure-note">
                <i class="fas fa-shield-halved"></i>
                <strong>Your request details are protected.</strong>
                <p>Uploaded requirements and service details are used only for parish scheduling, verification, and coordination.</p>
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
                <span><i class="fas fa-circle-check"></i> <?php echo e($success); ?> The parish office will review your service request.</span>
                <?php if (!empty($reference_match[1])): ?>
                    <a class="btn btn-sm btn-outline-success" href="my-requests.php?q=<?php echo urlencode($reference_match[1]); ?>">
                        <i class="fas fa-receipt"></i> Track <?php echo e($reference_match[1]); ?>
                    </a>
                <?php endif; ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="request-status-grid">
        <?php foreach ($status_counts as $status_name => $count): ?>
            <?php $status_info = $status_meta[$status_name] ?? ['icon' => 'fa-circle', 'description' => 'Request status', 'tone' => 'secondary']; ?>
            <a class="request-status-card" href="?status=<?php echo urlencode($status_name); ?>">
                <div class="status-card-top">
                    <i class="fas <?php echo e($status_info['icon']); ?> text-<?php echo e($status_info['tone']); ?>"></i>
                    <strong><?php echo intval($count); ?></strong>
                </div>
                <span><?php echo e(serviceLabel($status_name)); ?></span>
                <small><?php echo e($status_info['description']); ?></small>
            </a>
        <?php endforeach; ?>
    </section>

    <div class="request-form-card">
        <div class="request-form-header">
            <div>
                <h2><i class="fas fa-file-signature"></i> Sacramental Service Request Form</h2>
                <p>Complete the sections below so the parish office can prepare and confirm your requested service.</p>
            </div>
            <span class="request-kicker"><i class="fas fa-clock"></i> Schedule review</span>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" data-modern-request-form>
            <?php echo csrfInput(); ?>
            <section class="request-step">
                <div class="step-heading">
                    <span class="step-number">1</span>
                    <div>
                        <h3>Service Information</h3>
                        <p>Select the sacramental service you are requesting.</p>
                    </div>
                </div>

                <div class="request-type-grid" role="radiogroup" aria-label="Sacramental service">
                    <?php foreach ($service_types as $value => $label): ?>
                        <?php $meta = $service_meta[$value] ?? ['icon' => 'fa-church', 'hint' => 'Sacramental service request']; ?>
                        <label class="request-type-option">
                            <input type="radio" name="request_type" value="<?php echo e($value); ?>" required>
                            <span>
                                <i class="fas <?php echo e($meta['icon']); ?>"></i>
                                <strong><?php echo e($label); ?></strong>
                                <small><?php echo e($meta['hint']); ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="mt-3">
                    <label for="requestSearchSelect" class="form-label">Searchable service selector</label>
                    <input class="form-control request-form-control" id="requestSearchSelect" list="requestTypeOptions" placeholder="Type to search sacramental service" autocomplete="off">
                    <datalist id="requestTypeOptions">
                        <?php foreach ($service_types as $value => $label): ?>
                            <option value="<?php echo e($label); ?>" data-value="<?php echo e($value); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="baptism-requirements-card" id="baptismRequirementsCard" hidden>
                    <div class="baptism-progress" aria-label="Baptism request progress">
                        <span class="active"><i class="fas fa-list-check"></i> Step 1: Review Requirements</span>
                        <span><i class="fas fa-pen-to-square"></i> Step 2: Fill Request Form</span>
                        <span><i class="fas fa-paper-plane"></i> Step 3: Submit Request</span>
                    </div>

                    <div class="baptism-requirements-header">
                        <div>
                            <span class="request-kicker"><i class="fas fa-water"></i> Requirements for Baptism</span>
                            <h3>Baptism Requirements</h3>
                            <p>Please review and confirm each requirement before continuing. Upload clear supporting documents in the requirements upload section.</p>
                        </div>
                        <div class="baptism-review-badge">
                            <i class="fas fa-clipboard-check"></i>
                            <strong>Office Review</strong>
                            <small>Incomplete documents may delay approval.</small>
                        </div>
                    </div>

                    <div class="baptism-requirements-grid">
                        <?php foreach ($baptism_requirements as $key => $label): ?>
                            <label class="baptism-requirement-item">
                                <input type="checkbox" name="baptism_requirements[]" value="<?php echo e($key); ?>" data-baptism-requirement>
                                <span><i class="fas fa-check"></i></span>
                                <strong><?php echo e($label); ?></strong>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <label class="baptism-acknowledgement">
                        <input type="checkbox" name="baptism_requirements_ack" value="1" id="baptismRequirementsAck">
                        <span>I have read and understood the Baptism requirements and will upload the supporting documents requested by the parish.</span>
                    </label>

                    <div class="baptism-warning" id="baptismRequirementWarning">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span>Confirm all Baptism requirements and upload supporting documents before submitting.</span>
                    </div>

                    <div class="baptism-sheet-card" id="baptismSheetCard">
                        <div class="baptism-sheet-heading">
                            <span class="request-kicker"><i class="fas fa-file-lines"></i> Pre-Baptismal Investigation Sheet</span>
                            <h3>Fill Out This Form Before Requesting Baptism</h3>
                            <p>Enter the child, parent, godparent, and seminar details exactly as they should appear for parish review.</p>
                        </div>

                        <div class="baptism-sheet-grid">
                            <div class="baptism-sheet-field full">
                                <label for="baptism_child_name">Name of Child</label>
                                <input type="text" class="form-control request-form-control" id="baptism_child_name" name="baptism_sheet[child_name]" data-baptism-sheet placeholder="Complete name of child">
                            </div>

                            <div class="baptism-sheet-field">
                                <label for="baptism_birth_day">Birth Day</label>
                                <input type="number" min="1" max="31" class="form-control request-form-control" id="baptism_birth_day" name="baptism_sheet[birth_day]" data-baptism-sheet placeholder="Day">
                            </div>
                            <div class="baptism-sheet-field">
                                <label for="baptism_birth_month">Birth Month</label>
                                <input type="text" class="form-control request-form-control" id="baptism_birth_month" name="baptism_sheet[birth_month]" data-baptism-sheet placeholder="Month">
                            </div>
                            <div class="baptism-sheet-field">
                                <label for="baptism_birth_year">Birth Year</label>
                                <input type="number" min="1900" max="<?php echo date('Y'); ?>" class="form-control request-form-control" id="baptism_birth_year" name="baptism_sheet[birth_year]" data-baptism-sheet placeholder="Year">
                            </div>

                            <div class="baptism-sheet-field full">
                                <label for="baptism_birth_place">Place of Birth</label>
                                <input type="text" class="form-control request-form-control" id="baptism_birth_place" name="baptism_sheet[birth_place]" data-baptism-sheet placeholder="Municipality / City / Province">
                            </div>

                            <div class="baptism-sheet-field">
                                <label for="baptism_day">Baptism Day</label>
                                <input type="number" min="1" max="31" class="form-control request-form-control" id="baptism_day" name="baptism_sheet[baptism_day]" data-baptism-sheet placeholder="Day">
                            </div>
                            <div class="baptism-sheet-field">
                                <label for="baptism_month">Baptism Month</label>
                                <input type="text" class="form-control request-form-control" id="baptism_month" name="baptism_sheet[baptism_month]" data-baptism-sheet placeholder="Month">
                            </div>
                            <div class="baptism-sheet-field">
                                <label for="baptism_year">Baptism Year</label>
                                <input type="number" min="<?php echo date('Y'); ?>" max="<?php echo date('Y') + 2; ?>" class="form-control request-form-control" id="baptism_year" name="baptism_sheet[baptism_year]" data-baptism-sheet placeholder="Year">
                            </div>

                            <div class="baptism-sheet-field">
                                <label for="baptism_father_name">Father</label>
                                <input type="text" class="form-control request-form-control" id="baptism_father_name" name="baptism_sheet[father_name]" data-baptism-sheet placeholder="Father's complete name">
                            </div>
                            <div class="baptism-sheet-field">
                                <label for="baptism_father_origin">Father Place of Origin</label>
                                <input type="text" class="form-control request-form-control" id="baptism_father_origin" name="baptism_sheet[father_origin]" data-baptism-sheet placeholder="Place of origin">
                            </div>
                            <div class="baptism-sheet-field">
                                <label for="baptism_mother_name">Mother</label>
                                <input type="text" class="form-control request-form-control" id="baptism_mother_name" name="baptism_sheet[mother_name]" data-baptism-sheet placeholder="Mother's complete maiden name">
                            </div>
                            <div class="baptism-sheet-field">
                                <label for="baptism_mother_origin">Mother Place of Origin</label>
                                <input type="text" class="form-control request-form-control" id="baptism_mother_origin" name="baptism_sheet[mother_origin]" data-baptism-sheet placeholder="Place of origin">
                            </div>

                            <div class="baptism-sheet-field full">
                                <label for="baptism_godparents">Godparents</label>
                                <textarea class="form-control request-form-control" id="baptism_godparents" name="baptism_sheet[godparents]" rows="3" data-baptism-sheet placeholder="List godparents / sponsors"></textarea>
                            </div>

                            <div class="baptism-sheet-field">
                                <label for="baptism_father_residence">Father Residence</label>
                                <input type="text" class="form-control request-form-control" id="baptism_father_residence" name="baptism_sheet[father_residence]" data-baptism-sheet placeholder="Residence">
                            </div>
                            <div class="baptism-sheet-field">
                                <label for="baptism_mother_residence">Mother Residence</label>
                                <input type="text" class="form-control request-form-control" id="baptism_mother_residence" name="baptism_sheet[mother_residence]" data-baptism-sheet placeholder="Residence">
                            </div>

                            <div class="baptism-sheet-field">
                                <label for="baptism_authorized_signature">Authorized Signature</label>
                                <input type="text" class="form-control request-form-control" id="baptism_authorized_signature" name="baptism_sheet[authorized_signature]" data-baptism-sheet placeholder="Name of authorized signer">
                            </div>
                            <div class="baptism-sheet-field">
                                <label for="baptism_seminar_head">Head of the Baptismal Seminar</label>
                                <input type="text" class="form-control request-form-control" id="baptism_seminar_head" name="baptism_sheet[baptismal_seminar_head]" data-baptism-sheet placeholder="Seminar head name">
                            </div>
                        </div>

                        <div class="baptism-warning" id="baptismSheetWarning">
                            <i class="fas fa-pen-to-square"></i>
                            <span>Complete the Pre-Baptismal Investigation Sheet before submitting.</span>
                        </div>
                    </div>
                </div>

                <div class="baptism-requirements-card marriage-requirements-card" id="marriageRequirementsCard" hidden>
                    <div class="baptism-progress" aria-label="Marriage request progress">
                        <span class="active"><i class="fas fa-list-check"></i> Step 1: Review Requirements</span>
                        <span><i class="fas fa-calendar-check"></i> Step 2: Fill Request Form</span>
                        <span><i class="fas fa-paper-plane"></i> Step 3: Submit Request</span>
                    </div>

                    <div class="baptism-requirements-header">
                        <div>
                            <span class="request-kicker"><i class="fas fa-ring"></i> Marriage Requirements</span>
                            <h3>Requirements for Marriage</h3>
                            <p>Confirm the submitted requirements for both Male and Female applicants before continuing. Upload clear supporting documents in the requirements upload section.</p>
                        </div>
                        <div class="baptism-review-badge">
                            <i class="fas fa-file-shield"></i>
                            <strong>Couple Review</strong>
                            <small>Both columns must be completed.</small>
                        </div>
                    </div>

                    <div class="marriage-requirements-table" role="table" aria-label="Marriage requirements checklist">
                        <div class="marriage-requirements-row header" role="row">
                            <span role="columnheader">Requirement</span>
                            <strong role="columnheader">Male</strong>
                            <strong role="columnheader">Female</strong>
                        </div>
                        <?php foreach ($marriage_requirements as $key => $label): ?>
                            <div class="marriage-requirements-row" role="row">
                                <span role="cell"><?php echo e($label); ?></span>
                                <label role="cell" class="marriage-check">
                                    <input type="checkbox" name="marriage_requirements[male][]" value="<?php echo e($key); ?>" data-marriage-requirement="male">
                                    <i class="fas fa-check"></i>
                                </label>
                                <label role="cell" class="marriage-check">
                                    <input type="checkbox" name="marriage_requirements[female][]" value="<?php echo e($key); ?>" data-marriage-requirement="female">
                                    <i class="fas fa-check"></i>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <label class="baptism-acknowledgement">
                        <input type="checkbox" name="marriage_requirements_ack" value="1" id="marriageRequirementsAck">
                        <span>I have read and understood the Marriage requirements and will upload the supporting documents requested by the parish.</span>
                    </label>

                    <div class="baptism-warning" id="marriageRequirementWarning">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span>Confirm all Marriage requirements for both Male and Female and upload supporting documents before submitting.</span>
                    </div>
                </div>
            </section>

            <section class="request-step">
                <div class="step-heading">
                    <span class="step-number">2</span>
                    <div>
                        <h3>Applicant Details</h3>
                        <p>Confirm who is submitting this service request.</p>
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

            <section class="request-step">
                <div class="step-heading">
                    <span class="step-number">3</span>
                    <div>
                        <h3>Schedule and Location</h3>
                        <p>Provide your preferred service schedule and complete location.</p>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6" id="patronalDateGroup" style="display:none;">
                        <label for="patronal_fiesta_date" class="form-label">Date of Patronal Fiesta</label>
                        <input type="date" class="form-control request-form-control" id="patronal_fiesta_date" name="patronal_fiesta_date">
                    </div>
                    <div class="col-md-6" id="preferredDateGroup">
                        <label for="preferred_date" class="form-label">Preferred Date</label>
                        <input type="date" class="form-control request-form-control" id="preferred_date" name="preferred_date" required>
                    </div>
                    <div class="col-md-6">
                        <label for="preferred_time" class="form-label">Preferred Time</label>
                        <input type="time" class="form-control request-form-control" id="preferred_time" name="preferred_time" required>
                    </div>
                    <div class="col-12">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" class="form-control request-form-control" id="location" name="location" placeholder="Church, chapel, home, hospital, cemetery, or venue" required>
                    </div>
                    <div class="col-12">
                        <label for="details" class="form-label">Additional Details</label>
                        <textarea class="form-control request-form-control" id="details" name="details" rows="4" placeholder="Add names, family contact, special notes, or other details."></textarea>
                        <div class="form-text"><i class="fas fa-wand-magic-sparkles"></i> TUGON tip: include names, family contact, location notes, and special instructions when available.</div>
                    </div>
                </div>
            </section>

            <section class="request-step">
                <div class="step-heading">
                    <span class="step-number">4</span>
                    <div>
                        <h3>Upload Requirements</h3>
                        <p>Attach multiple supporting documents if needed.</p>
                    </div>
                </div>

                <label class="upload-zone" id="uploadZone" for="requirement_files">
                    <i class="fas fa-cloud-arrow-up"></i>
                    <strong>Drag and drop your requirements here or click to browse.</strong>
                    <small>Accepted formats: PDF, JPG, PNG, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT. Maximum 10MB per file.</small>
                    <input type="file" id="requirement_files" name="requirement_files[]" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain" multiple>
                </label>
                <div class="file-preview" id="filePreview">
                    <div>
                        <span id="fileName">Selected files</span>
                        <div class="text-muted small" id="fileSize">Ready to upload</div>
                    </div>
                    <div class="upload-progress" aria-hidden="true"><span></span></div>
                </div>
            </section>

            <div class="request-form-actions">
                <div class="privacy-copy">
                    <i class="fas fa-lock"></i> Secure parish request submission. Please review your schedule and location before submitting.
                </div>
                <button type="submit" class="submit-request-btn" id="submitRequestBtn">
                    <span class="submit-label"><i class="fas fa-paper-plane"></i> Submit Service Request</span>
                    <span class="submit-loading"><i class="fas fa-spinner fa-spin"></i> Submitting Request</span>
                </button>
            </div>
        </form>
    </div>

    <form class="request-filter-card" method="GET" action="">
        <div class="row g-2 align-items-center">
            <div class="col-lg-6">
                <label class="form-label">Search requests</label>
                <div class="input-with-icon">
                    <i class="fas fa-search"></i>
                    <input type="text" class="form-control request-form-control" name="q" value="<?php echo e($search); ?>" placeholder="Search service, status, details, or reference number">
                </div>
            </div>
            <div class="col-lg-3">
                <label class="form-label">Status filter</label>
                <select class="form-select request-form-control" name="status">
                    <option value="">All Statuses</option>
                    <?php foreach ($allowed_statuses as $status_option): ?>
                        <option value="<?php echo e($status_option); ?>" <?php echo $status === $status_option ? 'selected' : ''; ?>>
                            <?php echo e(serviceLabel($status_option)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3 d-grid d-md-flex gap-2">
                <button class="btn btn-primary align-self-end" type="submit"><i class="fas fa-search"></i> Filter</button>
                <?php if ($search !== '' || $status !== ''): ?>
                    <a class="btn btn-outline-secondary align-self-end" href="request-service.php">Clear</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="quick-status-tabs">
            <a href="request-service.php" class="<?php echo $status === '' ? 'active' : ''; ?>">All</a>
            <?php foreach ($allowed_statuses as $status_option): ?>
                <a href="?status=<?php echo urlencode($status_option); ?>" class="<?php echo $status === $status_option ? 'active' : ''; ?>"><?php echo e(serviceLabel($status_option)); ?></a>
            <?php endforeach; ?>
        </div>
    </form>

    <div class="request-history-card">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="request-section-title"><i class="fas fa-clock-rotate-left"></i> Service Request History</h2>
                <span class="text-muted"><?php echo intval($total); ?> total</span>
            </div>

            <?php if (!empty($services)): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle request-history-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Service</th>
                                <th>Schedule</th>
                                <th>Status</th>
                                <th>Details</th>
                                <th>Submitted</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($services as $service): ?>
                                <?php
                                    $service_date = requestCalendarField($service['description'], ['Date of Patronal Fiesta', 'Preferred date', 'Event date', 'Date']);
                                    $service_time = requestCalendarField($service['description'], ['Preferred time', 'Event time', 'Time']);
                                ?>
                                <tr onclick="window.location.href='view-request.php?id=<?php echo intval($service['request_id']); ?>'" style="cursor:pointer;">
                                    <td><strong><?php echo e($service['reference_number']); ?></strong></td>
                                    <td><?php echo e(serviceLabel($service['request_type'], $service_types)); ?></td>
                                    <td>
                                        <?php echo $service_date ? formatDate($service_date) : 'N/A'; ?><br>
                                        <small><?php echo $service_time ? e(formatTime($service_time)) : 'No time'; ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo getStatusBadgeClass($service['status']); ?>">
                                            <?php echo e(serviceLabel($service['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo nl2br(e($service['description'] ?: 'No details provided')); ?></td>
                                    <td><?php echo formatDateTime($service['date_requested']); ?></td>
                                    <td><?php echo formatDateTime($service['updated_at']); ?></td>
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
                    <i class="fas fa-church"></i>
                    <h5>No service requests yet.</h5>
                    <p class="mb-3">Your submitted sacramental service schedules and status updates will appear here.</p>
                    <a href="#uploadZone" class="btn btn-primary"><i class="fas fa-plus"></i> Submit your first request</a>
                </div>
            <?php endif; ?>
    </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const patronalGroup = document.getElementById('patronalDateGroup');
    const patronalDate = document.getElementById('patronal_fiesta_date');
    const preferredDateGroup = document.getElementById('preferredDateGroup');
    const preferredDate = document.getElementById('preferred_date');
    const baptismRequirementsCard = document.getElementById('baptismRequirementsCard');
    const baptismRequirementChecks = Array.from(document.querySelectorAll('[data-baptism-requirement]'));
    const baptismRequirementsAck = document.getElementById('baptismRequirementsAck');
    const baptismRequirementWarning = document.getElementById('baptismRequirementWarning');
    const baptismSheetFields = Array.from(document.querySelectorAll('[data-baptism-sheet]'));
    const baptismSheetWarning = document.getElementById('baptismSheetWarning');
    const marriageRequirementsCard = document.getElementById('marriageRequirementsCard');
    const marriageRequirementChecks = Array.from(document.querySelectorAll('[data-marriage-requirement]'));
    const marriageRequirementsAck = document.getElementById('marriageRequirementsAck');
    const marriageRequirementWarning = document.getElementById('marriageRequirementWarning');
    const requirementFiles = document.getElementById('requirement_files');
    const submitRequestBtn = document.getElementById('submitRequestBtn');

    function hasSelectedFiles() {
        return requirementFiles && requirementFiles.files && requirementFiles.files.length > 0;
    }

    function isBaptismSelected() {
        const selectedType = document.querySelector('input[name="request_type"]:checked');
        return selectedType && selectedType.value === 'baptism_service';
    }

    function isMarriageSelected() {
        const selectedType = document.querySelector('input[name="request_type"]:checked');
        return selectedType && selectedType.value === 'marriage_wedding_service';
    }

    function updateSpecialRequirementsState() {
        const baptismSelected = isBaptismSelected();
        const marriageSelected = isMarriageSelected();
        if (baptismRequirementsCard) {
            baptismRequirementsCard.hidden = !baptismSelected;
        }
        if (marriageRequirementsCard) {
            marriageRequirementsCard.hidden = !marriageSelected;
        }

        if (!baptismSelected && !marriageSelected) {
            if (submitRequestBtn) {
                submitRequestBtn.disabled = false;
            }
            return;
        }

        const allRequirementsChecked = baptismRequirementChecks.every(function(check) {
            return check.checked;
        });
        const sheetComplete = baptismSheetFields.every(function(field) {
            const complete = field.value.trim() !== '';
            const wrapper = field.closest('.baptism-sheet-field');
            if (wrapper) {
                wrapper.classList.toggle('is-missing', !complete);
            }
            return complete;
        });
        const acknowledged = baptismRequirementsAck && baptismRequirementsAck.checked;
        const filesReady = hasSelectedFiles();
        const ready = allRequirementsChecked && acknowledged && filesReady && sheetComplete;

        if (submitRequestBtn) {
            submitRequestBtn.disabled = !ready;
        }

        if (baptismRequirementWarning) {
            baptismRequirementWarning.classList.toggle('is-complete', ready);
            baptismRequirementWarning.innerHTML = ready
                ? '<i class="fas fa-circle-check"></i><span>Baptism requirements are confirmed and documents are ready for submission.</span>'
                : '<i class="fas fa-triangle-exclamation"></i><span>Confirm all Baptism requirements and upload supporting documents before submitting.</span>';
        }

        if (baptismSheetWarning) {
            baptismSheetWarning.classList.toggle('is-complete', sheetComplete);
            baptismSheetWarning.innerHTML = sheetComplete
                ? '<i class="fas fa-circle-check"></i><span>Pre-Baptismal Investigation Sheet is complete.</span>'
                : '<i class="fas fa-pen-to-square"></i><span>Complete the Pre-Baptismal Investigation Sheet before submitting.</span>';
        }

        const marriageComplete = marriageRequirementChecks.length > 0 && marriageRequirementChecks.every(function(check) {
            return check.checked;
        });
        const marriageAcknowledged = marriageRequirementsAck && marriageRequirementsAck.checked;
        const marriageReady = marriageSelected && marriageComplete && marriageAcknowledged && filesReady;

        if (marriageRequirementWarning) {
            marriageRequirementWarning.classList.toggle('is-complete', marriageReady);
            marriageRequirementWarning.innerHTML = marriageReady
                ? '<i class="fas fa-circle-check"></i><span>Marriage requirements are confirmed for both Male and Female and documents are ready.</span>'
                : '<i class="fas fa-triangle-exclamation"></i><span>Confirm all Marriage requirements for both Male and Female and upload supporting documents before submitting.</span>';
        }

        if (submitRequestBtn && marriageSelected) {
            submitRequestBtn.disabled = !marriageReady;
        }
    }

    // Toggle Patronal Date Function - Documents this helper's role in the parish management workflow.
    function togglePatronalDate() {
        const selectedType = document.querySelector('input[name="request_type"]:checked');
        const isPatronal = selectedType && selectedType.value === 'patronal_fiesta';
        patronalGroup.style.display = isPatronal ? '' : 'none';
        patronalDate.required = isPatronal;
        preferredDateGroup.style.display = isPatronal ? 'none' : '';
        preferredDate.required = !isPatronal;
        if (!isPatronal) {
            patronalDate.value = '';
        } else if (patronalDate.value) {
            preferredDate.value = patronalDate.value;
        }
        updateSpecialRequirementsState();
    }

    const typeRadios = document.querySelectorAll('input[name="request_type"]');

    if (typeRadios.length) {
        typeRadios.forEach(function(radio) {
            radio.addEventListener('change', togglePatronalDate);
        });
        baptismRequirementChecks.forEach(function(check) {
            check.addEventListener('change', updateSpecialRequirementsState);
        });
        if (baptismRequirementsAck) {
            baptismRequirementsAck.addEventListener('change', updateSpecialRequirementsState);
        }
        baptismSheetFields.forEach(function(field) {
            field.addEventListener('input', updateSpecialRequirementsState);
            field.addEventListener('change', updateSpecialRequirementsState);
        });
        marriageRequirementChecks.forEach(function(check) {
            check.addEventListener('change', updateSpecialRequirementsState);
        });
        if (marriageRequirementsAck) {
            marriageRequirementsAck.addEventListener('change', updateSpecialRequirementsState);
        }
        if (requirementFiles) {
            requirementFiles.addEventListener('change', updateSpecialRequirementsState);
        }
        patronalDate.addEventListener('change', function() {
            const selectedType = document.querySelector('input[name="request_type"]:checked');
            if (selectedType && selectedType.value === 'patronal_fiesta') {
                preferredDate.value = patronalDate.value;
            }
        });
        togglePatronalDate();
        updateSpecialRequirementsState();
    }
});
</script>

<script src="../assets/js/request-modern.js"></script>
<?php include '../templates/footer.php'; ?>
