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
    'birth_date' => 'Date of Birth',
    'birth_place' => 'Place of Birth',
    'baptism_date' => 'Date of Baptism',
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
$funeral_requirements = [
    'death_certificate' => 'Death Certificate'
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

function serviceValidDate($value) {
    $date = DateTime::createFromFormat('!Y-m-d', (string) $value);
    $errors = DateTime::getLastErrors();
    return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
}

function serviceRequirementUploadPlan($request_type, $baptism_requirements, $marriage_requirements, $funeral_requirements) {
    $plan = [];
    if ($request_type === 'baptism_service') {
        foreach ($baptism_requirements as $key => $label) {
            $plan[] = ['keys' => [$key], 'label' => $label];
        }
    }
    if ($request_type === 'marriage_wedding_service') {
        foreach (['male' => 'Male', 'female' => 'Female'] as $side_key => $side_label) {
            foreach ($marriage_requirements as $key => $label) {
                $plan[] = ['keys' => [$side_key, $key], 'label' => $side_label . ' - ' . $label];
            }
        }
    }
    if ($request_type === 'funeral_mass') {
        foreach ($funeral_requirements as $key => $label) {
            $plan[] = ['keys' => [$key], 'label' => $label];
        }
    }
    return $plan;
}

function serviceRequirementFileAt($files, $keys) {
    if (empty($files) || !is_array($files)) {
        return null;
    }

    $single_file = [];
    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $field) {
        $value = $files[$field] ?? null;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        $single_file[$field] = $value;
    }

    return $single_file;
}

function serviceRequirementFileUploaded($files, $keys) {
    $file = serviceRequirementFileAt($files, $keys);
    return $file && trim((string) ($file['name'] ?? '')) !== '' && intval($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
}

function missingServiceRequirementUploads($files, $plan) {
    $missing = [];
    foreach ($plan as $item) {
        if (!serviceRequirementFileUploaded($files, $item['keys'])) {
            $missing[] = $item['label'];
        }
    }
    return $missing;
}

function saveServiceRequirementUploads($conn, $request_id, $uploaded_by, $files, $plan) {
    $results = ['ok' => true, 'saved' => 0, 'documents' => [], 'errors' => []];
    foreach ($plan as $item) {
        $file = serviceRequirementFileAt($files, $item['keys']);
        if (!$file || trim((string) ($file['name'] ?? '')) === '') {
            continue;
        }
        $document = saveRequestDocument($conn, $request_id, $uploaded_by, $file, 'requirement', $item['label']);
        if ($document['ok'] && !empty($document['saved'])) {
            $results['saved']++;
            $results['documents'][] = $document['document_id'];
        } else {
            $results['errors'][] = $item['label'] . ': ' . ($document['error'] ?? 'Unknown error');
        }
    }

    if ($results['saved'] === 0 && !empty($results['errors'])) {
        $results['ok'] = false;
        $results['error'] = 'No files were uploaded successfully. ' . implode(', ', $results['errors']);
    } elseif (!empty($results['errors'])) {
        $results['ok'] = false;
        $results['error'] = 'Some files were not uploaded. ' . implode(', ', $results['errors']);
    }

    return $results;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $request_type = $_POST['request_type'] ?? '';
    $preferred_date = trim($_POST['preferred_date'] ?? '');
    $preferred_time = trim($_POST['preferred_time'] ?? '');
    $patronal_fiesta_date = trim($_POST['patronal_fiesta_date'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $details = trim($_POST['details'] ?? '');
    $requirement_upload_plan = serviceRequirementUploadPlan($request_type, $baptism_requirements, $marriage_requirements, $funeral_requirements);
    $requirement_upload_files = $request_type === 'baptism_service'
        ? ($_FILES['baptism_requirement_files'] ?? null)
        : ($request_type === 'marriage_wedding_service'
            ? ($_FILES['marriage_requirement_files'] ?? null)
            : ($request_type === 'funeral_mass' ? ($_FILES['funeral_requirement_files'] ?? null) : null));
    $missing_requirement_uploads = missingServiceRequirementUploads($requirement_upload_files, $requirement_upload_plan);
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
    if ($request_type === 'patronal_fiesta' && $patronal_fiesta_date !== '') {
        $preferred_date = $patronal_fiesta_date;
    }

    if (!array_key_exists($request_type, $service_types)) {
        $error = 'Please choose a sacramental service.';
    } elseif ($request_type === 'baptism_service' && !empty($missing_baptism_sheet)) {
        $error = 'Please complete the Baptism Information Sheet before requesting Baptism. Missing: ' . implode(', ', array_slice($missing_baptism_sheet, 0, 4)) . (count($missing_baptism_sheet) > 4 ? ', and more.' : '.');
    } elseif ($request_type === 'baptism_service' && !serviceValidDate($baptism_sheet['birth_date'])) {
        $error = 'Please provide a valid date of birth.';
    } elseif ($request_type === 'baptism_service' && !serviceValidDate($baptism_sheet['baptism_date'])) {
        $error = 'Please provide a valid date of Baptism.';
    } elseif (in_array($request_type, ['baptism_service', 'marriage_wedding_service', 'funeral_mass'], true) && !empty($missing_requirement_uploads)) {
        $error = 'Please upload a file for each requirement. Missing: ' . implode(', ', array_slice($missing_requirement_uploads, 0, 4)) . (count($missing_requirement_uploads) > 4 ? ', and more.' : '.');
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
            $description_parts[] = 'Baptism requirement uploads: ' . implode(', ', array_values($baptism_requirements));
            $description_parts[] = 'Baptism Information Sheet:';
            foreach ($baptism_sheet_fields as $field_key => $field_label) {
                $description_parts[] = $field_label . ': ' . $baptism_sheet[$field_key];
            }
        }
        if ($request_type === 'marriage_wedding_service') {
            $description_parts[] = 'Marriage requirement uploads: Male and Female files submitted for each requirement.';
        }
        if ($request_type === 'funeral_mass') {
            $description_parts[] = 'Funeral Mass requirement uploads: ' . implode(', ', array_values($funeral_requirements));
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
                $documents = saveServiceRequirementUploads($conn, $request_id, $user_id, $requirement_upload_files, $requirement_upload_plan);
                if (!$documents['ok'] && empty($documents['saved'])) {
                    $error = $documents['error'] . ' Your request was saved, but the files were not attached. Reference: ' . $reference_number;
                } else {
                    createAuditLog($conn, $user_id, 'CREATE_REQUEST', 'requests', $request_id);
                    $doc_count = intval($documents['saved'] ?? 0);
                    $file_text = $doc_count === 1 ? 'file' : 'files';
                    createNotification($conn, $user_id, 'Sacramental Service Request Created', 'Your service request has been submitted with reference: ' . $reference_number . ' (' . $doc_count . ' ' . $file_text . ' attached)');
                    $success = 'Sacramental service request submitted successfully! Reference: ' . $reference_number . ' (' . $doc_count . ' file' . ($doc_count === 1 ? '' : 's') . ' attached)';
                }
            } else {
                $error = 'Error submitting service request: ' . $conn->error;
            }
            $stmt->close();
        }
    }
}

$service_placeholders = implode(',', array_fill(0, count($service_type_keys), '?'));
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
<link rel="stylesheet" href="../assets/css/request-modern.css?v=<?php echo filemtime('../assets/css/request-modern.css'); ?>">

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

    <?php echo mobileStepRail(['Details', 'Requirements', 'Review'], 1, 'Sacramental service request progress'); ?>

    <div class="request-form-card">
        <div class="request-form-header">
            <div>
                <h2><i class="fas fa-file-signature"></i> Sacramental Service Request Form</h2>
                <p>Complete the sections below so the parish office can prepare and confirm your requested service.</p>
            </div>
            <span class="request-kicker"><i class="fas fa-clock"></i> Schedule review</span>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" id="serviceRequestForm" data-modern-request-form novalidate>
            <?php echo csrfInput(); ?>
            <div class="request-validation-banner" id="serviceValidationBanner" role="alert" hidden>
                <i class="fas fa-triangle-exclamation"></i>
                <span>Please fill up the highlighted fields before continuing.</span>
            </div>

            <div id="serviceEntryPanel">
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
                            <p>Upload one clear supporting document for each requirement so the parish office can review every item separately.</p>
                        </div>
                        <div class="baptism-review-badge">
                            <i class="fas fa-clipboard-check"></i>
                            <strong>Office Review</strong>
                            <small>Incomplete documents may delay approval.</small>
                        </div>
                    </div>

                    <div class="baptism-requirements-grid">
                        <?php foreach ($baptism_requirements as $key => $label): ?>
                            <div class="baptism-requirement-item requirement-upload-item">
                                <span><i class="fas fa-file-arrow-up"></i></span>
                                <div class="requirement-upload-main">
                                    <strong><?php echo e($label); ?></strong>
                                    <small data-file-name>No file selected</small>
                                </div>
                                <div class="requirement-upload-actions">
                                    <label class="requirement-upload-btn">
                                        <i class="fas fa-folder-open"></i> <span data-upload-label>Choose File</span>
                                        <input type="file" class="requirement-file-input" name="baptism_requirement_files[<?php echo e($key); ?>]" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain" data-requirement-file data-requirement-group="baptism">
                                    </label>
                                    <a class="requirement-view-btn" href="#" target="_blank" rel="noopener" data-file-view hidden>
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="baptism-warning" id="baptismRequirementWarning">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span>Upload one file for every Baptism requirement before submitting.</span>
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
                                <label for="baptism_birth_date">Date of Birth</label>
                                <input type="date" min="1900-01-01" max="<?php echo date('Y-m-d'); ?>" class="form-control request-form-control" id="baptism_birth_date" name="baptism_sheet[birth_date]" data-baptism-sheet>
                            </div>

                            <div class="baptism-sheet-field full">
                                <label for="baptism_birth_place">Place of Birth</label>
                                <input type="text" class="form-control request-form-control" id="baptism_birth_place" name="baptism_sheet[birth_place]" data-baptism-sheet placeholder="Municipality / City / Province">
                            </div>

                            <div class="baptism-sheet-field">
                                <label for="baptism_date">Date of Baptism</label>
                                <input type="date" min="1900-01-01" max="<?php echo date('Y-m-d', strtotime('+2 years')); ?>" class="form-control request-form-control" id="baptism_date" name="baptism_sheet[baptism_date]" data-baptism-sheet>
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
                            <p>Upload one file for the Male applicant and one file for the Female applicant for each requirement.</p>
                        </div>
                        <div class="baptism-review-badge">
                            <i class="fas fa-file-shield"></i>
                            <strong>Couple Review</strong>
                            <small>Both columns must be completed.</small>
                        </div>
                    </div>

                    <div class="marriage-requirements-table" role="table" aria-label="Marriage requirements uploads">
                        <div class="marriage-requirements-row header" role="row">
                            <span role="columnheader">Requirement</span>
                            <strong role="columnheader">Male</strong>
                            <strong role="columnheader">Female</strong>
                        </div>
                        <?php foreach ($marriage_requirements as $key => $label): ?>
                            <div class="marriage-requirements-row" role="row">
                                <span role="cell"><?php echo e($label); ?></span>
                                <div role="cell" class="marriage-upload-cell">
                                    <label class="marriage-check requirement-upload-btn">
                                        <i class="fas fa-folder-open"></i> <span data-upload-label>Choose File</span>
                                        <small data-file-name>No file</small>
                                        <input type="file" class="requirement-file-input" name="marriage_requirement_files[male][<?php echo e($key); ?>]" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain" data-requirement-file data-requirement-group="marriage">
                                    </label>
                                    <a class="requirement-view-btn marriage-file-view" href="#" target="_blank" rel="noopener" data-file-view hidden>
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                                <div role="cell" class="marriage-upload-cell">
                                    <label class="marriage-check requirement-upload-btn">
                                        <i class="fas fa-folder-open"></i> <span data-upload-label>Choose File</span>
                                        <small data-file-name>No file</small>
                                        <input type="file" class="requirement-file-input" name="marriage_requirement_files[female][<?php echo e($key); ?>]" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain" data-requirement-file data-requirement-group="marriage">
                                    </label>
                                    <a class="requirement-view-btn marriage-file-view" href="#" target="_blank" rel="noopener" data-file-view hidden>
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="baptism-warning" id="marriageRequirementWarning">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span>Upload all Marriage requirement files for both Male and Female before submitting.</span>
                    </div>
                </div>

                <div class="baptism-requirements-card funeral-requirements-card" id="funeralRequirementsCard" hidden>
                    <div class="baptism-progress" aria-label="Funeral Mass request progress">
                        <span class="active"><i class="fas fa-list-check"></i> Step 1: Review Requirements</span>
                        <span><i class="fas fa-calendar-check"></i> Step 2: Fill Request Form</span>
                        <span><i class="fas fa-paper-plane"></i> Step 3: Submit Request</span>
                    </div>

                    <div class="baptism-requirements-header">
                        <div>
                            <span class="request-kicker"><i class="fas fa-cross"></i> Funeral Mass Requirements</span>
                            <h3>Requirements for Funeral Mass</h3>
                            <p>Upload a clear copy of the Death Certificate before submitting the Funeral Mass request.</p>
                        </div>
                        <div class="baptism-review-badge">
                            <i class="fas fa-file-shield"></i>
                            <strong>Office Review</strong>
                            <small>Required before scheduling.</small>
                        </div>
                    </div>

                    <div class="baptism-requirements-grid">
                        <?php foreach ($funeral_requirements as $key => $label): ?>
                            <div class="baptism-requirement-item requirement-upload-item">
                                <span><i class="fas fa-file-arrow-up"></i></span>
                                <div class="requirement-upload-main">
                                    <strong><?php echo e($label); ?></strong>
                                    <small data-file-name>No file selected</small>
                                </div>
                                <div class="requirement-upload-actions">
                                    <label class="requirement-upload-btn">
                                        <i class="fas fa-folder-open"></i> <span data-upload-label>Choose File</span>
                                        <input type="file" class="requirement-file-input" name="funeral_requirement_files[<?php echo e($key); ?>]" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain" data-requirement-file data-requirement-group="funeral">
                                    </label>
                                    <a class="requirement-view-btn" href="#" target="_blank" rel="noopener" data-file-view hidden>
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="baptism-warning" id="funeralRequirementWarning">
                        <i class="fas fa-triangle-exclamation"></i>
                        <span>Upload the Death Certificate before submitting.</span>
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

            <div class="request-form-actions">
                <div class="privacy-copy">
                    <i class="fas fa-lock"></i> Your request will not be sent until you confirm it on the next screen.
                </div>
                <button type="button" class="submit-request-btn" id="submitRequestBtn">
                    <span class="submit-label"><i class="fas fa-clipboard-check"></i> Review Before Submitting <i class="fas fa-arrow-right"></i></span>
                    <span class="submit-loading"><i class="fas fa-spinner fa-spin"></i> Preparing Review</span>
                </button>
            </div>
            </div>

            <section class="request-review-panel" id="serviceReviewPanel" hidden aria-labelledby="serviceReviewTitle">
                <div class="request-review-heading">
                    <span class="request-kicker"><i class="fas fa-magnifying-glass"></i> Final review</span>
                    <h2 id="serviceReviewTitle">Double-Check Everything Before Submitting</h2>
                    <p>Review the information below. You can go back without losing anything.</p>
                </div>

                <div class="request-review-section">
                    <h3><i class="fas fa-church"></i> Service Information</h3>
                    <dl class="request-review-grid" id="reviewServiceInfo"></dl>
                </div>

                <div class="request-review-section" id="reviewChildSection" hidden>
                    <h3><i class="fas fa-child-reaching"></i> Child Information</h3>
                    <dl class="request-review-grid" id="reviewChildInfo"></dl>
                </div>

                <div class="request-review-section" id="reviewParentsSection" hidden>
                    <h3><i class="fas fa-people-roof"></i> Parents</h3>
                    <dl class="request-review-grid" id="reviewParents"></dl>
                </div>

                <div class="request-review-section" id="reviewGodparentsSection" hidden>
                    <h3><i class="fas fa-people-group"></i> Godparents and Parish Details</h3>
                    <dl class="request-review-grid" id="reviewGodparents"></dl>
                </div>

                <div class="request-review-section">
                    <h3><i class="fas fa-calendar-check"></i> Applicant, Schedule, and Location</h3>
                    <dl class="request-review-grid" id="reviewScheduleInfo"></dl>
                </div>

                <div class="request-review-confirm-note">
                    <i class="fas fa-circle-info"></i>
                    <span>By submitting, you confirm that this information is accurate and ready for parish review.</span>
                </div>

                <div class="request-review-actions">
                    <button type="button" class="request-review-back" id="serviceReviewBack"><i class="fas fa-arrow-left"></i> Back and Edit</button>
                    <button type="submit" class="submit-request-btn" id="confirmServiceSubmit">
                        <span class="submit-label"><i class="fas fa-paper-plane"></i> Confirm &amp; Submit Request</span>
                        <span class="submit-loading"><i class="fas fa-spinner fa-spin"></i> Submitting Request</span>
                    </button>
                </div>
            </section>
        </form>
    </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const serviceForm = document.getElementById('serviceRequestForm');
    const entryPanel = document.getElementById('serviceEntryPanel');
    const reviewPanel = document.getElementById('serviceReviewPanel');
    const validationBanner = document.getElementById('serviceValidationBanner');
    const reviewBackBtn = document.getElementById('serviceReviewBack');
    const confirmSubmitBtn = document.getElementById('confirmServiceSubmit');
    const patronalGroup = document.getElementById('patronalDateGroup');
    const patronalDate = document.getElementById('patronal_fiesta_date');
    const preferredDateGroup = document.getElementById('preferredDateGroup');
    const preferredDate = document.getElementById('preferred_date');
    const baptismRequirementsCard = document.getElementById('baptismRequirementsCard');
    const baptismRequirementWarning = document.getElementById('baptismRequirementWarning');
    const baptismSheetFields = Array.from(document.querySelectorAll('[data-baptism-sheet]'));
    const baptismSheetWarning = document.getElementById('baptismSheetWarning');
    const marriageRequirementsCard = document.getElementById('marriageRequirementsCard');
    const marriageRequirementWarning = document.getElementById('marriageRequirementWarning');
    const funeralRequirementsCard = document.getElementById('funeralRequirementsCard');
    const funeralRequirementWarning = document.getElementById('funeralRequirementWarning');
    const requirementFileInputs = Array.from(document.querySelectorAll('[data-requirement-file]'));
    const submitRequestBtn = document.getElementById('submitRequestBtn');

    function requirementFilesReady(group) {
        const inputs = requirementFileInputs.filter(function(input) {
            return input.dataset.requirementGroup === group;
        });
        return inputs.length > 0 && inputs.every(function(input) {
            return input.files && input.files.length > 0;
        });
    }

    function updateRequirementFileLabel(input) {
        const button = input.closest('.requirement-upload-btn');
        const container = input.closest('.marriage-upload-cell') || input.closest('.requirement-upload-item') || button;
        const fileLabel = container ? container.querySelector('[data-file-name]') : null;
        const uploadLabel = button ? button.querySelector('[data-upload-label]') : null;
        const viewButton = container ? container.querySelector('[data-file-view]') : null;
        const hasFile = input.files && input.files.length > 0;
        if (fileLabel) {
            fileLabel.textContent = hasFile ? input.files[0].name : (input.dataset.requirementGroup === 'marriage' ? 'No file' : 'No file selected');
        }
        if (uploadLabel && hasFile) {
            uploadLabel.textContent = 'Change File';
        } else if (uploadLabel) {
            uploadLabel.textContent = 'Choose File';
        }
        if (button) {
            button.classList.toggle('has-file', hasFile);
        }
        if (container) {
            container.classList.toggle('has-file', hasFile);
        }
        if (viewButton) {
            if (viewButton.dataset.objectUrl) {
                URL.revokeObjectURL(viewButton.dataset.objectUrl);
                delete viewButton.dataset.objectUrl;
            }
            if (hasFile) {
                const objectUrl = URL.createObjectURL(input.files[0]);
                viewButton.href = objectUrl;
                viewButton.dataset.objectUrl = objectUrl;
                viewButton.hidden = false;
            } else {
                viewButton.href = '#';
                viewButton.hidden = true;
            }
        }
    }

    function isBaptismSelected() {
        const selectedType = document.querySelector('input[name="request_type"]:checked');
        return selectedType && selectedType.value === 'baptism_service';
    }

    function isMarriageSelected() {
        const selectedType = document.querySelector('input[name="request_type"]:checked');
        return selectedType && selectedType.value === 'marriage_wedding_service';
    }

    function isFuneralSelected() {
        const selectedType = document.querySelector('input[name="request_type"]:checked');
        return selectedType && selectedType.value === 'funeral_mass';
    }

    function updateSpecialRequirementsState() {
        const baptismSelected = isBaptismSelected();
        const marriageSelected = isMarriageSelected();
        const funeralSelected = isFuneralSelected();
        if (baptismRequirementsCard) {
            baptismRequirementsCard.hidden = !baptismSelected;
        }
        if (marriageRequirementsCard) {
            marriageRequirementsCard.hidden = !marriageSelected;
        }
        if (funeralRequirementsCard) {
            funeralRequirementsCard.hidden = !funeralSelected;
        }

        baptismSheetFields.forEach(function(field) {
            field.required = baptismSelected;
        });
        requirementFileInputs.forEach(function(input) {
            input.required = input.dataset.requirementGroup === 'baptism' ? baptismSelected
                : input.dataset.requirementGroup === 'marriage' ? marriageSelected
                : input.dataset.requirementGroup === 'funeral' ? funeralSelected
                : false;
            if (!input.required) {
                clearFieldError(input);
            }
        });
        baptismSheetFields.forEach(function(field) {
            if (!field.required) {
                clearFieldError(field);
            }
        });

        if (!baptismSelected && !marriageSelected && !funeralSelected) {
            return;
        }

        const sheetComplete = baptismSheetFields.every(function(field) {
            const complete = field.value.trim() !== '';
            return complete;
        });
        const baptismFilesReady = requirementFilesReady('baptism');
        const ready = baptismFilesReady && sheetComplete;

        if (baptismRequirementWarning) {
            baptismRequirementWarning.classList.toggle('is-complete', ready);
            baptismRequirementWarning.innerHTML = ready
                ? '<i class="fas fa-circle-check"></i><span>Each Baptism requirement has its own uploaded file.</span>'
                : '<i class="fas fa-triangle-exclamation"></i><span>Upload one file for every Baptism requirement before submitting.</span>';
        }

        if (baptismSheetWarning) {
            baptismSheetWarning.classList.toggle('is-complete', sheetComplete);
            baptismSheetWarning.innerHTML = sheetComplete
                ? '<i class="fas fa-circle-check"></i><span>Pre-Baptismal Investigation Sheet is complete.</span>'
                : '<i class="fas fa-pen-to-square"></i><span>Complete the Pre-Baptismal Investigation Sheet before submitting.</span>';
        }

        const marriageReady = marriageSelected && requirementFilesReady('marriage');

        if (marriageRequirementWarning) {
            marriageRequirementWarning.classList.toggle('is-complete', marriageReady);
            marriageRequirementWarning.innerHTML = marriageReady
                ? '<i class="fas fa-circle-check"></i><span>All Marriage requirement files are ready for parish review.</span>'
                : '<i class="fas fa-triangle-exclamation"></i><span>Upload all Marriage requirement files for both Male and Female before submitting.</span>';
        }

        const funeralReady = funeralSelected && requirementFilesReady('funeral');

        if (funeralRequirementWarning) {
            funeralRequirementWarning.classList.toggle('is-complete', funeralReady);
            funeralRequirementWarning.innerHTML = funeralReady
                ? '<i class="fas fa-circle-check"></i><span>Death Certificate is ready for parish review.</span>'
                : '<i class="fas fa-triangle-exclamation"></i><span>Upload the Death Certificate before submitting.</span>';
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

    function validationWrapper(field) {
        if (field.type === 'radio') {
            return document.querySelector('.request-type-grid');
        }
        return field.closest('.baptism-sheet-field')
            || field.closest('.marriage-upload-cell')
            || field.closest('.requirement-upload-item')
            || field.closest('.col-md-6')
            || field.closest('.col-12')
            || field.parentElement;
    }

    function clearFieldError(field) {
        const wrapper = validationWrapper(field);
        if (!wrapper) {
            return;
        }
        wrapper.classList.remove('request-field-error', 'is-missing');
        const error = wrapper.querySelector(':scope > .request-inline-error');
        if (error) {
            error.remove();
        }
        if (field.type === 'radio') {
            document.querySelectorAll('input[name="request_type"]').forEach(function(radio) {
                radio.removeAttribute('aria-invalid');
            });
        } else {
            field.removeAttribute('aria-invalid');
        }
    }

    function addFieldError(field) {
        const wrapper = validationWrapper(field);
        if (!wrapper || wrapper.classList.contains('request-field-error')) {
            return;
        }
        wrapper.classList.add('request-field-error');
        field.setAttribute('aria-invalid', 'true');
        const error = document.createElement('div');
        error.className = 'request-inline-error';
        error.innerHTML = '<i class="fas fa-triangle-exclamation"></i><span>You haven\'t filled up this field yet.</span>';
        wrapper.appendChild(error);
    }

    function fieldIsComplete(field) {
        if (field.type === 'radio') {
            return Boolean(serviceForm.querySelector('input[name="' + field.name + '"]:checked'));
        }
        if (field.type === 'file') {
            return Boolean(field.files && field.files.length);
        }
        return field.value.trim() !== '' && field.checkValidity();
    }

    function validateForReview() {
        updateSpecialRequirementsState();
        const invalidFields = [];
        const seenRadioGroups = new Set();
        serviceForm.querySelectorAll('[required]').forEach(function(field) {
            if (field.disabled) {
                return;
            }
            if (field.type === 'radio') {
                if (seenRadioGroups.has(field.name)) {
                    return;
                }
                seenRadioGroups.add(field.name);
            }
            clearFieldError(field);
            if (!fieldIsComplete(field)) {
                invalidFields.push(field);
                addFieldError(field);
            }
        });

        validationBanner.hidden = invalidFields.length === 0;
        if (invalidFields.length) {
            const firstWrapper = validationWrapper(invalidFields[0]);
            if (firstWrapper) {
                firstWrapper.scrollIntoView({behavior: 'smooth', block: 'center'});
            }
            window.setTimeout(function() {
                if (invalidFields[0].type !== 'file') {
                    invalidFields[0].focus({preventScroll: true});
                }
            }, 350);
            return false;
        }
        return true;
    }

    function displayDate(value) {
        if (!value) {
            return 'Not provided';
        }
        const date = new Date(value + 'T00:00:00');
        return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString('en-PH', {
            year: 'numeric', month: 'long', day: 'numeric'
        });
    }

    function displayTime(value) {
        if (!value) {
            return 'Not provided';
        }
        const parts = value.split(':');
        const date = new Date(2000, 0, 1, Number(parts[0]), Number(parts[1]));
        return date.toLocaleTimeString('en-PH', {hour: 'numeric', minute: '2-digit'});
    }

    function renderReviewItems(containerId, items) {
        const container = document.getElementById(containerId);
        container.replaceChildren();
        items.forEach(function(item) {
            const block = document.createElement('div');
            block.className = 'request-review-item';
            const term = document.createElement('dt');
            const description = document.createElement('dd');
            term.textContent = item[0];
            description.textContent = item[1] || 'Not provided';
            block.append(term, description);
            container.appendChild(block);
        });
    }

    function baptismValue(id) {
        const field = document.getElementById(id);
        return field ? field.value.trim() : '';
    }

    function populateReview() {
        const selectedType = serviceForm.querySelector('input[name="request_type"]:checked');
        const selectedLabel = selectedType ? selectedType.closest('label').querySelector('strong').textContent.trim() : '';
        const activeFileNames = requirementFileInputs.filter(function(input) {
            return input.required && input.files && input.files.length;
        }).map(function(input) {
            return input.files[0].name;
        });
        renderReviewItems('reviewServiceInfo', [
            ['Requested Service', selectedLabel],
            ['Requirements Attached', activeFileNames.length ? activeFileNames.join(', ') : 'Not required']
        ]);

        const baptismSelected = isBaptismSelected();
        document.getElementById('reviewChildSection').hidden = !baptismSelected;
        document.getElementById('reviewParentsSection').hidden = !baptismSelected;
        document.getElementById('reviewGodparentsSection').hidden = !baptismSelected;
        if (baptismSelected) {
            renderReviewItems('reviewChildInfo', [
                ['Name of Child', baptismValue('baptism_child_name')],
                ['Date of Birth', displayDate(baptismValue('baptism_birth_date'))],
                ['Place of Birth', baptismValue('baptism_birth_place')],
                ['Date of Baptism', displayDate(baptismValue('baptism_date'))]
            ]);
            renderReviewItems('reviewParents', [
                ['Father', baptismValue('baptism_father_name')],
                ['Father Place of Origin', baptismValue('baptism_father_origin')],
                ['Father Residence', baptismValue('baptism_father_residence')],
                ['Mother', baptismValue('baptism_mother_name')],
                ['Mother Place of Origin', baptismValue('baptism_mother_origin')],
                ['Mother Residence', baptismValue('baptism_mother_residence')]
            ]);
            renderReviewItems('reviewGodparents', [
                ['Godparents / Sponsors', baptismValue('baptism_godparents')],
                ['Authorized Signature', baptismValue('baptism_authorized_signature')],
                ['Head of the Baptismal Seminar', baptismValue('baptism_seminar_head')]
            ]);
        }

        const scheduleDate = patronalDate.required ? patronalDate.value : preferredDate.value;
        renderReviewItems('reviewScheduleInfo', [
            ['Applicant', <?php echo json_encode((string) ($_SESSION['fullname'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>],
            ['Email Address', <?php echo json_encode((string) ($_SESSION['email'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>],
            [patronalDate.required ? 'Date of Patronal Fiesta' : 'Preferred Date', displayDate(scheduleDate)],
            ['Preferred Time', displayTime(document.getElementById('preferred_time').value)],
            ['Location', document.getElementById('location').value.trim()],
            ['Additional Details', document.getElementById('details').value.trim() || 'None']
        ]);
    }

    function openReview() {
        if (!validateForReview()) {
            return;
        }
        populateReview();
        validationBanner.hidden = true;
        entryPanel.hidden = true;
        reviewPanel.hidden = false;
        reviewPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    const typeRadios = document.querySelectorAll('input[name="request_type"]');

    if (typeRadios.length) {
        typeRadios.forEach(function(radio) {
            radio.addEventListener('change', togglePatronalDate);
        });
        baptismSheetFields.forEach(function(field) {
            field.addEventListener('input', updateSpecialRequirementsState);
            field.addEventListener('change', updateSpecialRequirementsState);
        });
        requirementFileInputs.forEach(function(input) {
            input.addEventListener('change', function() {
                updateRequirementFileLabel(input);
                updateSpecialRequirementsState();
            });
        });
        patronalDate.addEventListener('change', function() {
            const selectedType = document.querySelector('input[name="request_type"]:checked');
            if (selectedType && selectedType.value === 'patronal_fiesta') {
                preferredDate.value = patronalDate.value;
            }
        });
        togglePatronalDate();
        updateSpecialRequirementsState();
    }

    serviceForm.addEventListener('input', function(event) {
        if (event.target.matches('[required]') && fieldIsComplete(event.target)) {
            clearFieldError(event.target);
            validationBanner.hidden = !serviceForm.querySelector('.request-field-error');
        }
    });
    serviceForm.addEventListener('change', function(event) {
        if (event.target.matches('[required]') && fieldIsComplete(event.target)) {
            clearFieldError(event.target);
            validationBanner.hidden = !serviceForm.querySelector('.request-field-error');
        }
    });
    submitRequestBtn.addEventListener('click', openReview);
    reviewBackBtn.addEventListener('click', function() {
        reviewPanel.hidden = true;
        entryPanel.hidden = false;
        entryPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
    });
    serviceForm.addEventListener('submit', function(event) {
        if (event.submitter !== confirmSubmitBtn) {
            event.preventDefault();
            openReview();
        }
    });
});
</script>

<script src="../assets/js/request-modern.js"></script>
<?php include '../templates/footer.php'; ?>
