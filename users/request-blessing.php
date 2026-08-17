<?php
/**
 * Blessing Request Module - Handles parish blessing request forms and submissions.
 */
include '../includes/session.php';
include '../config/security.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();
if (!hasPermission('requests.create')) {
    redirect('../auth/login.php');
}

$page_title = 'Request Blessing';
$user_id = intval($_SESSION['user_id']);
$error = '';
$success = '';
ensureExpandedRequestTypeSchema($conn);
ensureRequestDocumentsSchema($conn);
ensureEmailNotificationSchema($conn);

$blessing_types = [
    'house_blessing' => 'House Blessing',
    'vehicle_blessing' => 'Vehicle Blessing',
    'business_blessing' => 'Business Blessing',
    'office_blessing' => 'Office Blessing',
    'event_blessing' => 'Event Blessing'
];
$blessing_meta = [
    'house_blessing' => ['icon' => 'fa-house-chimney', 'hint' => 'Schedule a blessing for your home and family.'],
    'vehicle_blessing' => ['icon' => 'fa-car-side', 'hint' => 'Request a blessing for a new or existing vehicle.'],
    'business_blessing' => ['icon' => 'fa-store', 'hint' => 'For business openings, anniversaries, or milestones.'],
    'office_blessing' => ['icon' => 'fa-building', 'hint' => 'For offices, workspaces, or institutional spaces.'],
    'event_blessing' => ['icon' => 'fa-calendar-check', 'hint' => 'For gatherings, programs, and special occasions.']
];
$status_meta = [
    'pending' => ['icon' => 'fa-hourglass-half', 'description' => 'Waiting for parish review', 'tone' => 'warning'],
    'approved' => ['icon' => 'fa-circle-check', 'description' => 'Approved by the office', 'tone' => 'success'],
    'processing' => ['icon' => 'fa-gears', 'description' => 'Being coordinated', 'tone' => 'primary'],
    'completed' => ['icon' => 'fa-file-circle-check', 'description' => 'Service completed', 'tone' => 'info'],
    'rejected' => ['icon' => 'fa-circle-xmark', 'description' => 'Needs correction', 'tone' => 'danger'],
    'cancelled' => ['icon' => 'fa-ban', 'description' => 'Cancelled request', 'tone' => 'secondary'],
];
$blessing_type_keys = array_keys($blessing_types);
$allowed_statuses = ['pending', 'approved', 'processing', 'completed', 'rejected', 'cancelled'];

$breadcrumbs = [
    'Dashboard' => 'index.php',
    'Blessings' => null
];

// Blessing Label Function - Documents this helper's role in the parish management workflow.
function blessingLabel($value, $labels = []) {
    return $labels[$value] ?? ucfirst(str_replace('_', ' ', (string) $value));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $request_type = $_POST['request_type'] ?? '';
    $preferred_date = trim($_POST['preferred_date'] ?? '');
    $preferred_time = trim($_POST['preferred_time'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $details = trim($_POST['details'] ?? '');

    if (!array_key_exists($request_type, $blessing_types)) {
        $error = 'Please choose a blessing type.';
    } elseif ($preferred_date === '') {
        $error = 'Please choose a preferred blessing date.';
    } elseif ($preferred_time === '') {
        $error = 'Please choose a preferred blessing time.';
    } elseif ($location === '') {
        $error = 'Please provide the blessing location.';
    } else {
        $description_parts = [
            'Preferred date: ' . $preferred_date,
            'Preferred time: ' . $preferred_time,
            'Location: ' . $location,
            'Details: ' . ($details ?: 'None'),
        ];
        $description = implode("\n", $description_parts);
        $reference_number = generateReferenceNumber();
        $status = 'pending';

        $stmt = $conn->prepare("INSERT INTO requests (user_id, request_type, description, status, reference_number) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            $error = 'Unable to prepare your blessing request. Please contact the parish office.';
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
                    createNotification($conn, $user_id, 'Blessing Request Created', 'Your blessing request has been submitted with reference: ' . $reference_number . ' (' . $doc_count . ' ' . $file_text . ' attached)');
                    $success = 'Blessing request submitted successfully! Reference: ' . $reference_number . ' (' . $doc_count . ' file' . ($doc_count === 1 ? '' : 's') . ' attached)';
                }
            } else {
                $error = 'Error submitting blessing request: ' . $conn->error;
            }
            $stmt->close();
        }
    }
}

$blessing_placeholders = implode(',', array_fill(0, count($blessing_type_keys), '?'));

$status_counts = array_fill_keys($allowed_statuses, 0);
$count_types = 'i' . str_repeat('s', count($blessing_type_keys));
$count_params = array_merge([$user_id], $blessing_type_keys);
$stmt = $conn->prepare("SELECT status, COUNT(*) AS count FROM requests WHERE user_id = ? AND request_type IN ($blessing_placeholders) GROUP BY status");
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
                <span class="request-kicker"><i class="fas fa-hands-praying"></i> Blessing Services</span>
                <h1>New Blessing Request</h1>
                <p>Submit blessing schedules securely and efficiently. Add your preferred date, time, location, and supporting details so the parish office can coordinate the service.</p>
                <div class="request-badges">
                    <span><i class="fas fa-lock"></i> Secure Request Submission</span>
                    <span><i class="fas fa-bell"></i> Status Notifications</span>
                    <span><i class="fas fa-robot"></i> TUGON AI Assisted</span>
                </div>
            </div>
            <aside class="request-secure-note">
                <i class="fas fa-shield-halved"></i>
                <strong>Your request details are protected.</strong>
                <p>Uploaded requirements and blessing details are used only for parish scheduling, verification, and coordination.</p>
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
                <span><i class="fas fa-circle-check"></i> <?php echo e($success); ?> The parish office will review your schedule request.</span>
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
            <a class="request-status-card" href="my-requests.php?status=<?php echo urlencode($status_name); ?>">
                <div class="status-card-top">
                    <i class="fas <?php echo e($status_info['icon']); ?> text-<?php echo e($status_info['tone']); ?>"></i>
                    <strong><?php echo intval($count); ?></strong>
                </div>
                <span><?php echo e(blessingLabel($status_name)); ?></span>
                <small><?php echo e($status_info['description']); ?></small>
            </a>
        <?php endforeach; ?>
    </section>

    <?php echo mobileStepRail(['Details', 'Requirements', 'Review'], 1, 'Blessing request progress'); ?>

    <div class="request-form-card">
        <div class="request-form-header">
            <div>
                <h2><i class="fas fa-file-signature"></i> Blessing Request Form</h2>
                <p>Complete the sections below so the parish office can prepare and confirm your blessing schedule.</p>
            </div>
            <span class="request-kicker"><i class="fas fa-clock"></i> Schedule review</span>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" data-modern-request-form>
            <?php echo csrfInput(); ?>
            <section class="request-step">
                <div class="step-heading">
                    <span class="step-number">1</span>
                    <div>
                        <h3>Blessing Information</h3>
                        <p>Select the type of blessing you are requesting.</p>
                    </div>
                </div>

                <div class="request-type-grid" role="radiogroup" aria-label="Blessing type">
                    <?php foreach ($blessing_types as $value => $label): ?>
                        <?php $meta = $blessing_meta[$value] ?? ['icon' => 'fa-hands-praying', 'hint' => 'Blessing request']; ?>
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
                    <label for="requestSearchSelect" class="form-label">Searchable blessing selector</label>
                    <input class="form-control request-form-control" id="requestSearchSelect" list="requestTypeOptions" placeholder="Type to search blessing type" autocomplete="off">
                    <datalist id="requestTypeOptions">
                        <?php foreach ($blessing_types as $value => $label): ?>
                            <option value="<?php echo e($label); ?>" data-value="<?php echo e($value); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </section>

            <section class="request-step">
                <div class="step-heading">
                    <span class="step-number">2</span>
                    <div>
                        <h3>Applicant Details</h3>
                        <p>Confirm who is submitting this blessing request.</p>
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
                        <p>Provide your preferred blessing schedule and complete location.</p>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="preferred_date" class="form-label">Preferred Date</label>
                        <input type="date" class="form-control request-form-control" id="preferred_date" name="preferred_date" required>
                    </div>
                    <div class="col-md-6">
                        <label for="preferred_time" class="form-label">Preferred Time</label>
                        <input type="time" class="form-control request-form-control" id="preferred_time" name="preferred_time" required>
                    </div>
                    <div class="col-12">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" class="form-control request-form-control" id="location" name="location" placeholder="Complete address or parish location" required>
                    </div>
                    <div class="col-12">
                        <label for="details" class="form-label">Additional Details</label>
                        <textarea class="form-control request-form-control" id="details" name="details" rows="4" placeholder="Tell us anything the parish office should know."></textarea>
                        <div class="form-text"><i class="fas fa-wand-magic-sparkles"></i> TUGON tip: include landmarks, contact person, and special instructions when available.</div>
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
                    <small>Accepted formats: PDF, JPG, PNG, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT. Maximum 10MB per file. Multiple files allowed.</small>
                    <input type="file" id="requirement_files" name="requirement_files[]" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,image/jpeg,image/png,image/gif,application/pdf,text/plain" multiple>
                </label>
                <div class="file-preview" id="filePreview">
                    <div id="fileList">
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
                    <span class="submit-label"><i class="fas fa-paper-plane"></i> Submit Blessing Request</span>
                    <span class="submit-loading"><i class="fas fa-spinner fa-spin"></i> Submitting Request</span>
                </button>
            </div>
        </form>
    </div>

    </div>
</div>

<script src="../assets/js/request-modern.js"></script>
<?php include '../templates/footer.php'; ?>
