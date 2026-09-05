<?php
/**
 * User Profile Module - Handles profile details, avatar uploads, preferences, and account settings.
 */
// Include centralized session management
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../database/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/account-management.php';

requireLogin();
ensureEmailNotificationSchema($conn);
ensureUserProfileFieldsSchema($conn);
ensureAvatarUploadDirectory();

$user_id = (int) $_SESSION['user_id'];
$user = getUserById($conn, $user_id);
if (!$user) {
    header('Location: login.php');
    exit;
}
$is_admin = isAdmin();
$error = '';
$success = '';

if (!function_exists('detectUserIdType')) {
    // Helper to detect ID type from user record
    function detectUserIdType(array $user): string {
        if (!empty($user['id_type'])) {
            return $user['id_type'];
        }
        $orig = strtolower((string)($user['valid_id_original_name'] ?? ''));
        $path = strtolower((string)($user['valid_id_path'] ?? ''));
        if (strpos($orig, 'philsys') !== false || strpos($orig, 'national') !== false) {
            return 'Philippine National ID (PhilSys)';
        }
        if (strpos($orig, 'driver') !== false) {
            return "Driver's License";
        }
        if (strpos($orig, 'umid') !== false) {
            return 'UMID Card';
        }
        if (strpos($orig, 'passport') !== false) {
            return 'Philippine Passport';
        }
        if (strpos($orig, 'sss') !== false) {
            return 'Social Security System (SSS) ID';
        }
        if (strpos($orig, 'prc') !== false) {
            return 'PRC ID';
        }
        if (strpos($orig, 'postal') !== false) {
            return 'Postal ID';
        }
        if (strpos($orig, 'voter') !== false) {
            return "Voter's ID";
        }
        if (!empty($path)) {
            return 'Philippine Government-Issued ID';
        }
        return !empty($user['role']) && $user['role'] === 'admin'
            ? 'Parish Administrator Credential'
            : 'Government-Issued ID (On Record)';
    }
}

// Handle profile update
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'profile') {
    requireValidCsrfToken();

    $first_name = trim(sanitize($_POST['first_name'] ?? ''));
    $middle_name = trim(sanitize($_POST['middle_name'] ?? ''));
    $surname = trim(sanitize($_POST['surname'] ?? ''));
    $suffix = trim(sanitize($_POST['suffix'] ?? ''));
    $email = strtolower(trim(sanitize($_POST['email'] ?? '')));
    $phone_number = trim(sanitize($_POST['phone_number'] ?? ''));
    $birthdate = trim(sanitize($_POST['birthdate'] ?? ''));
    $sex = trim(sanitize($_POST['sex'] ?? ''));
    $street_address = trim(sanitize($_POST['street_address'] ?? ''));
    $barangay = trim(sanitize($_POST['barangay'] ?? ''));
    $city = trim(sanitize($_POST['city'] ?? ''));
    $province = trim(sanitize($_POST['province'] ?? ''));

    // Validation
    if ($first_name === '') {
        $error = 'First name is required.';
    } elseif ($surname === '') {
        $error = 'Last name is required.';
    } elseif ($email === '') {
        $error = 'Email address is required.';
    } elseif (!isValidEmail($email)) {
        $error = 'Please enter a valid email address.';
    } elseif (!authenticationIdentifierAvailable($conn, 'email', $email, $user_id)) {
        $error = 'This email address is already registered to another account.';
    } elseif ($phone_number !== '' && !isValidPhilippineMobile($phone_number)) {
        $error = 'Please enter a valid Philippine mobile number (e.g., 09123456789).';
    } elseif ($phone_number !== '' && !authenticationIdentifierAvailable($conn, 'mobile', $phone_number, $user_id)) {
        $error = 'This mobile number is already registered to another account.';
    } else {
        // Normalize mobile number
        if ($phone_number !== '') {
            $phone_number = normalizePhilippineMobileForStorage($phone_number);
        }

        // Validate birthdate if provided
        $birthdate_db = null;
        if ($birthdate !== '') {
            $b_timestamp = strtotime($birthdate);
            if ($b_timestamp) {
                $birthdate_db = date('Y-m-d', $b_timestamp);
            }
        }

        // Handle Profile Picture Upload
        $new_avatar_path = null;
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['profile_photo'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = 'Error uploading photo. (Code: ' . intval($file['error']) . ')';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $error = 'The profile photo size must not exceed 5MB.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $allowed_mimes = [
                    'image/jpeg' => 'jpg',
                    'image/jpg'  => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];

                if (!isset($allowed_mimes[$mime])) {
                    $error = 'Invalid image format. Supported formats: JPG, PNG, and WEBP.';
                } elseif (!@getimagesize($file['tmp_name'])) {
                    $error = 'The uploaded file is not a valid image.';
                } else {
                    $upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';
                    if (!is_dir($upload_dir)) {
                        @mkdir($upload_dir, 0755, true);
                    }
                    $ext = $allowed_mimes[$mime];
                    $filename = 'avatar_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $target_file = $upload_dir . DIRECTORY_SEPARATOR . $filename;

                    if (move_uploaded_file($file['tmp_name'], $target_file)) {
                        $new_avatar_path = 'uploads/avatars/' . $filename;

                        // Delete old custom avatar if it was stored in uploads/avatars/
                        $old_avatar = (string)($user['profile_picture'] ?? '');
                        if (!empty($old_avatar) && strpos($old_avatar, 'uploads/avatars/') === 0) {
                            $old_file_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $old_avatar);
                            if (is_file($old_file_path)) {
                                @unlink($old_file_path);
                            }
                        }
                    } else {
                        $error = 'Unable to save the uploaded photo. Please check file permissions.';
                    }
                }
            }
        }

        // Proceed if no errors occurred during file validation
        if (!$error) {
            $middle_initial = $middle_name !== '' ? strtoupper(substr($middle_name, 0, 1)) : '';
            $fullname_parts = array_filter([
                $first_name,
                $middle_name,
                $surname,
                $suffix
            ], fn($v) => trim($v) !== '');
            $fullname = implode(' ', $fullname_parts);

            // Construct combined address string
            $address_parts = array_filter([
                $street_address,
                $barangay,
                $city,
                $province
            ], fn($v) => trim($v) !== '');
            $address = implode(', ', $address_parts);

            $profile_picture = $new_avatar_path ?? $user['profile_picture'];
            $phone_db = $phone_number !== '' ? $phone_number : null;

            $stmt = $conn->prepare("UPDATE users SET 
                fullname = ?, 
                first_name = ?, 
                middle_name = ?, 
                middle_initial = ?, 
                surname = ?, 
                suffix = ?, 
                email = ?, 
                phone_number = ?, 
                birthdate = ?, 
                sex = ?, 
                address = ?, 
                street_address = ?, 
                barangay = ?, 
                city = ?, 
                province = ?, 
                profile_picture = ? 
                WHERE id = ?");

            if (!$stmt) {
                $error = 'Unable to prepare update: ' . $conn->error;
            } else {
                $stmt->bind_param(
                    'ssssssssssssssssi',
                    $fullname,
                    $first_name,
                    $middle_name,
                    $middle_initial,
                    $surname,
                    $suffix,
                    $email,
                    $phone_db,
                    $birthdate_db,
                    $sex,
                    $address,
                    $street_address,
                    $barangay,
                    $city,
                    $province,
                    $profile_picture,
                    $user_id
                );

                if ($stmt->execute()) {
                    // Synchronize authentication identifiers
                    synchronizeAuthenticationIdentifier($conn, $user_id, 'email', $email);
                    if ($phone_db !== null) {
                        synchronizeAuthenticationIdentifier($conn, $user_id, 'mobile', $phone_db);
                    }

                    // Synchronize active session variables
                    $_SESSION['fullname'] = $fullname;
                    $_SESSION['email'] = $email;
                    $_SESSION['profile_picture'] = (string) $profile_picture;

                    // Audit logging
                    if (function_exists('createAuditLog')) {
                        createAuditLog($conn, $user_id, 'UPDATE_PROFILE', 'users', $user_id);
                    }

                    $success = 'Profile details updated successfully!';
                    $user = getUserById($conn, $user_id);
                } else {
                    $error = 'Error updating profile: ' . $conn->error;
                }
                $stmt->close();
            }
        }
    }
}

// Request statistics
$completed_request_count = 0;
if (!$is_admin) {
    $completed_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM requests WHERE user_id = ? AND status = 'completed'");
    if ($completed_stmt) {
        $completed_stmt->bind_param('i', $user_id);
        $completed_stmt->execute();
        $completed_result = $completed_stmt->get_result();
        $completed_request_count = intval($completed_result->fetch_assoc()['total'] ?? 0);
        $completed_stmt->close();
    }
}

// Pre-fill parsed fields for legacy users
$display_first_name = (string)($user['first_name'] ?? '');
$display_middle_name = (string)($user['middle_name'] ?? '');
$display_surname = (string)($user['surname'] ?? '');
$display_suffix = (string)($user['suffix'] ?? '');

if ($display_first_name === '' && !empty($user['fullname'])) {
    $parts = preg_split('/\s+/', trim((string)$user['fullname']));
    $display_first_name = $parts[0] ?? '';
    $display_surname = count($parts) > 1 ? end($parts) : '';
    if (count($parts) > 2) {
        $display_middle_name = implode(' ', array_slice($parts, 1, -1));
    }
}

$display_street = (string)($user['street_address'] ?? '');
$display_barangay = (string)($user['barangay'] ?? '');
$display_city = (string)($user['city'] ?? '');
$display_province = (string)($user['province'] ?? '');

if ($display_street === '' && !empty($user['address'])) {
    $addr_parts = array_map('trim', explode(',', (string)$user['address']));
    if (count($addr_parts) >= 4) {
        $display_street = $addr_parts[0];
        $display_barangay = $addr_parts[1];
        $display_city = $addr_parts[2];
        $display_province = implode(', ', array_slice($addr_parts, 3));
    } elseif (count($addr_parts) === 3) {
        $display_street = $addr_parts[0];
        $display_barangay = $addr_parts[1];
        $display_city = $addr_parts[2];
    } elseif (count($addr_parts) === 2) {
        $display_street = $addr_parts[0];
        $display_barangay = $addr_parts[1];
    } else {
        $display_street = (string)$user['address'];
    }
}

$profile_display_name = trim((string) ($user['fullname'] ?? ($is_admin ? 'Administrator' : 'Parishioner')));
$profile_first_name_parts = preg_split('/\s+/', $profile_display_name);
$profile_first_name = $profile_first_name_parts[0] ?? ($is_admin ? 'Admin' : 'Parishioner');
$profile_initial = strtoupper(substr($profile_first_name, 0, 1));
$profile_district = trim((string) ($user['chapel_district'] ?? ''));
$profile_member_since = !empty($user['created_at']) ? date('M Y', strtotime($user['created_at'])) : 'N/A';
$profile_avatar_url = !empty($user['profile_picture']) ? BASE_URL . ltrim($user['profile_picture'], '/') : '';

// Identity Verification Metadata
$detected_id_type = detectUserIdType($user);
$user_status = strtolower((string)($user['status'] ?? 'active'));
$verified_date = !empty($user['verified_at']) ? date('M d, Y', strtotime($user['verified_at'])) : (!empty($user['created_at']) ? date('M d, Y', strtotime($user['created_at'])) : 'On Record');

$page_title = $is_admin ? 'Profile Settings' : 'My Profile';
?>
<?php include '../templates/header.php'; ?>

<section class="profile-mobile-hero" aria-label="Profile summary">
    <div class="profile-mobile-hero-top">
        <a href="<?php echo $is_admin ? '../admin/dashboard.php' : '../users/index.php'; ?>" class="profile-mobile-back" aria-label="Back to dashboard">
            <i class="fas fa-chevron-left" aria-hidden="true"></i>
        </a>
        <strong><?php echo $is_admin ? 'Profile Settings' : 'Profile'; ?></strong>
    </div>
    <div class="profile-mobile-identity">
        <span class="profile-mobile-avatar" style="overflow: hidden; padding: 0;">
            <?php if (!empty($profile_avatar_url)): ?>
                <img src="<?php echo e($profile_avatar_url); ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
            <?php else: ?>
                <?php echo e($profile_initial); ?>
            <?php endif; ?>
        </span>
        <h1><?php echo e($profile_first_name); ?></h1>
        <p><?php echo $is_admin ? 'Administrator' : e($profile_district !== '' ? $profile_district . ' Member' : 'Parishioner'); ?></p>
    </div>
</section>

<?php if (!$is_admin): ?>
<div class="profile-mobile-stack">
    <section class="profile-mobile-stats" aria-label="Profile statistics">
        <div>
            <strong><?php echo intval($completed_request_count); ?></strong>
            <span>Completed</span>
        </div>
        <div>
            <strong><?php echo e($profile_member_since); ?></strong>
            <span>Member Since</span>
        </div>
    </section>
</div>
<?php endif; ?>

<div class="container my-4">
    <div class="row justify-content-center">
        <div class="col-xl-9 col-lg-10 col-md-11">
            <div class="card profile-details-card shadow-sm border-0" id="profileDetails" style="border-radius: 16px; overflow: hidden;">
                <div class="card-header bg-white py-3 px-4 border-bottom d-flex align-items-center justify-content-between" style="border-color: #f1ede5 !important;">
                    <h5 class="mb-0 fw-bold" style="color: #2E3A2D;">
                        <i class="fas <?php echo $is_admin ? 'fa-user-shield text-warning' : 'fa-user text-success'; ?> me-2"></i> 
                        <?php echo $is_admin ? 'Profile Settings' : 'My Profile'; ?>
                    </h5>
                    <span class="badge rounded-pill" style="background-color: #f6f3ee; color: #735d25; border: 1px solid #e5dccb; font-size: 0.78rem; font-weight: 600; padding: 6px 12px;">
                        <?php echo $is_admin ? 'Parish Administrator' : 'Parishioner Account'; ?>
                    </span>
                </div>
                <div class="card-body p-4 p-md-5">
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center mb-4 rounded-3" role="alert">
                            <i class="fas fa-circle-exclamation me-3 fs-5"></i>
                            <div><?php echo e($error); ?></div>
                            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-4 rounded-3" role="alert" id="profileSuccessAlert">
                            <i class="fas fa-circle-check me-3 fs-5 text-success"></i>
                            <div class="fw-medium"><?php echo e($success); ?></div>
                            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" enctype="multipart/form-data" id="profileForm">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="profile">

                        <!-- 1. Profile Picture Upload Section -->
                        <div class="profile-avatar-section text-center mb-4 pb-3 border-bottom" style="border-color: #f1ede5 !important;">
                            <div class="avatar-wrapper d-inline-block position-relative mb-2">
                                <div class="avatar-preview-box rounded-circle shadow" style="width: 124px; height: 124px; border: 3.5px solid #c89b3c; overflow: hidden; background: linear-gradient(135deg, #2E3A2D, #1d251d); display: flex; align-items: center; justify-content: center; margin: 0 auto; box-shadow: 0 8px 20px rgba(46, 58, 45, 0.15) !important;">
                                    <?php if (!empty($profile_avatar_url)): ?>
                                        <img id="avatarPreviewImg" src="<?php echo e($profile_avatar_url); ?>" alt="Profile Avatar" style="width: 100%; height: 100%; object-fit: cover;">
                                        <span id="avatarFallbackInitials" style="display: none; font-size: 42px; font-weight: 700; color: #c89b3c;"><?php echo e($profile_initial); ?></span>
                                    <?php else: ?>
                                        <img id="avatarPreviewImg" src="" alt="Profile Avatar" style="display: none; width: 100%; height: 100%; object-fit: cover;">
                                        <span id="avatarFallbackInitials" style="font-size: 44px; font-weight: 700; color: #c89b3c;"><?php echo e($profile_initial); ?></span>
                                    <?php endif; ?>
                                </div>
                                <label for="profile_photo" class="avatar-camera-btn position-absolute" title="Upload New Photo" aria-label="Upload New Photo">
                                    <i class="fas fa-camera"></i>
                                </label>
                            </div>
                            <div>
                                <input type="file" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png,image/webp" class="d-none">
                                <label for="profile_photo" class="btn btn-sm btn-outline-secondary rounded-pill px-3 py-1 fw-semibold mb-1" style="font-size: 0.82rem; cursor: pointer;">
                                    <i class="fas fa-arrow-up-from-bracket me-1"></i> Upload New Photo
                                </label>
                                <div class="text-muted small mt-1" style="font-size: 0.78rem;">
                                    JPG, PNG, or WEBP &bull; Max 5MB
                                </div>
                                <div id="avatarSelectedAlert" class="badge bg-light text-dark border px-3 py-1 rounded-pill mt-2 d-none" style="font-size: 0.78rem;">
                                    <i class="fas fa-image text-primary me-1"></i> Photo chosen: <span id="avatarFileName"></span>. Remember to click "Save Changes".
                                </div>
                            </div>
                        </div>

                        <!-- Identity Verification Badge Card (Read-only / Informational) -->
                        <div class="verification-badge-card mb-4 p-3 rounded-3" style="background: linear-gradient(135deg, #fdfbf7 0%, #f7f3ea 100%); border: 1.5px solid #e5dccb;">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="verification-icon-box d-flex align-items-center justify-content-center rounded-circle" style="width: 44px; height: 44px; background: rgba(200, 155, 60, 0.15); color: #b8860b; font-size: 20px; flex-shrink: 0;">
                                        <i class="fas fa-id-card"></i>
                                    </div>
                                    <div>
                                        <div class="text-secondary small fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 0.72rem;">Identity Verification</div>
                                        <div class="fw-bold text-dark" style="font-size: 0.95rem;"><?php echo e($detected_id_type); ?></div>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <?php if ($is_admin): ?>
                                        <span class="badge px-3 py-2 rounded-pill text-white" style="background-color: #1e3a8a; font-size: 0.82rem;">
                                            <i class="fas fa-user-shield me-1"></i> Verified Administrator
                                        </span>
                                    <?php elseif ($user_status === 'active'): ?>
                                        <span class="badge px-3 py-2 rounded-pill text-white" style="background-color: #2e7d32; font-size: 0.82rem;">
                                            <i class="fas fa-circle-check me-1"></i> Verified Parishioner
                                        </span>
                                    <?php elseif ($user_status === 'pending_verification'): ?>
                                        <span class="badge px-3 py-2 rounded-pill text-dark" style="background-color: #f59e0b; font-size: 0.82rem;">
                                            <i class="fas fa-clock me-1"></i> Pending Verification
                                        </span>
                                    <?php elseif ($user_status === 'rejected'): ?>
                                        <span class="badge px-3 py-2 rounded-pill text-white" style="background-color: #dc2626; font-size: 0.82rem;">
                                            <i class="fas fa-circle-xmark me-1"></i> Verification Rejected
                                        </span>
                                    <?php else: ?>
                                        <span class="badge px-3 py-2 rounded-pill text-secondary bg-white border" style="font-size: 0.82rem;">
                                            <?php echo e(ucfirst($user_status)); ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!empty($user['face_image_path'])): ?>
                                        <span class="badge px-2 py-2 rounded-pill bg-white text-muted border" style="font-size: 0.75rem;" title="Live camera photo matches government ID">
                                            <i class="fas fa-camera text-success me-1"></i> Live Face Captured
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Personal Details Section -->
                        <div class="form-section-group mb-4">
                            <h6 class="fw-bold mb-3 d-flex align-items-center" style="color: #2E3A2D; font-size: 0.95rem;">
                                <i class="fas fa-user-circle me-2" style="color: #c89b3c;"></i> Personal Information
                            </h6>
                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label for="first_name" class="form-label fw-semibold small text-secondary">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control parish-input" id="first_name" name="first_name" 
                                           value="<?php echo e($display_first_name); ?>" required placeholder="e.g., Juan">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label for="middle_name" class="form-label fw-semibold small text-secondary">Middle Name</label>
                                    <input type="text" class="form-control parish-input" id="middle_name" name="middle_name" 
                                           value="<?php echo e($display_middle_name); ?>" placeholder="e.g., Reyes">
                                </div>
                                <div class="col-12 col-md-3">
                                    <label for="surname" class="form-label fw-semibold small text-secondary">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control parish-input" id="surname" name="surname" 
                                           value="<?php echo e($display_surname); ?>" required placeholder="e.g., Dela Cruz">
                                </div>
                                <div class="col-12 col-md-2">
                                    <label for="suffix" class="form-label fw-semibold small text-secondary">Suffix</label>
                                    <input type="text" class="form-control parish-input" id="suffix" name="suffix" 
                                           value="<?php echo e($display_suffix); ?>" placeholder="e.g., Jr., III">
                                </div>

                                <div class="col-12 col-md-6">
                                    <label for="birthdate" class="form-label fw-semibold small text-secondary">Date of Birth</label>
                                    <input type="date" class="form-control parish-input" id="birthdate" name="birthdate" 
                                           value="<?php echo e(!empty($user['birthdate']) ? date('Y-m-d', strtotime($user['birthdate'])) : ''); ?>">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="sex" class="form-label fw-semibold small text-secondary">Gender / Sex</label>
                                    <select class="form-select parish-input" id="sex" name="sex">
                                        <option value="">Select gender</option>
                                        <option value="Male" <?php echo (strcasecmp($user['sex'] ?? '', 'Male') === 0) ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo (strcasecmp($user['sex'] ?? '', 'Female') === 0) ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Contact Details Section -->
                        <div class="form-section-group mb-4">
                            <h6 class="fw-bold mb-3 d-flex align-items-center" style="color: #2E3A2D; font-size: 0.95rem;">
                                <i class="fas fa-envelope-open-text me-2" style="color: #c89b3c;"></i> Contact &amp; Account
                            </h6>
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label for="email" class="form-label fw-semibold small text-secondary">Email Address <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0 text-muted" style="border-color: #e2d9cc; border-radius: 10px 0 0 10px;">
                                            <i class="fas fa-at"></i>
                                        </span>
                                        <input type="email" class="form-control parish-input border-start-0" id="email" name="email" 
                                               value="<?php echo e($user['email']); ?>" required placeholder="you@gmail.com" style="border-radius: 0 10px 10px 0;">
                                    </div>
                                    <div class="form-text text-muted small mt-1">Used for system notifications and certificate release updates.</div>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="phone_number" class="form-label fw-semibold small text-secondary">Mobile / Contact Number</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0 text-muted" style="border-color: #e2d9cc; border-radius: 10px 0 0 10px;">
                                            <i class="fas fa-phone"></i>
                                        </span>
                                        <input type="tel" class="form-control parish-input border-start-0" id="phone_number" name="phone_number" 
                                               value="<?php echo e($user['phone_number']); ?>" placeholder="09XX-XXX-XXXX" style="border-radius: 0 10px 10px 0;">
                                    </div>
                                    <div class="form-text text-muted small mt-1">Philippine 11-digit mobile number.</div>
                                </div>
                            </div>
                        </div>

                        <!-- 4. Residential Address Section -->
                        <div class="form-section-group mb-4">
                            <h6 class="fw-bold mb-3 d-flex align-items-center" style="color: #2E3A2D; font-size: 0.95rem;">
                                <i class="fas fa-map-location-dot me-2" style="color: #c89b3c;"></i> Complete Residential Address
                            </h6>
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label for="street_address" class="form-label fw-semibold small text-secondary">House No. / Street / Sitio</label>
                                    <input type="text" class="form-control parish-input" id="street_address" name="street_address" 
                                           value="<?php echo e($display_street); ?>" placeholder="e.g., Block 5 Lot 12, Sitio Upi">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="barangay" class="form-label fw-semibold small text-secondary">Barangay</label>
                                    <input type="text" class="form-control parish-input" id="barangay" name="barangay" 
                                           value="<?php echo e($display_barangay); ?>" placeholder="e.g., San Mateo">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="city" class="form-label fw-semibold small text-secondary">City / Municipality</label>
                                    <input type="text" class="form-control parish-input" id="city" name="city" 
                                           value="<?php echo e($display_city); ?>" placeholder="e.g., Aleosan">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="province" class="form-label fw-semibold small text-secondary">Province</label>
                                    <input type="text" class="form-control parish-input" id="province" name="province" 
                                           value="<?php echo e($display_province); ?>" placeholder="e.g., Cotabato">
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex flex-wrap gap-2 justify-content-end pt-3 border-top" style="border-color: #f1ede5 !important;">
                            <a href="<?php echo $is_admin ? '../admin/dashboard.php' : '../users/index.php'; ?>" class="btn btn-outline-secondary px-4 py-2 rounded-pill fw-medium">
                                Cancel
                            </a>
                            <button type="submit" class="btn text-white px-4 py-2 rounded-pill fw-semibold" style="background: linear-gradient(135deg, #2E3A2D, #222d21); border: 1px solid #c89b3c; box-shadow: 0 4px 12px rgba(46, 58, 45, 0.2);">
                                <i class="fas fa-check me-1"></i> Save Changes
                            </button>
                        </div>
                    </form>

                    <hr class="my-5" style="border-color: #f1ede5;">

                    <!-- Change Password Section -->
                    <div class="profile-change-password-section">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h6 class="fw-bold mb-0" style="color: #2E3A2D; font-size: 0.95rem;">
                                <i class="fas fa-shield-halved me-2" style="color: #c9a646;"></i> Change Password
                            </h6>
                            <span class="text-muted small">Keep your account secure</span>
                        </div>
                        <form action="change-password.php" method="POST">
                            <?php echo csrfInput(); ?>
                            <div class="mb-3">
                                <label for="current_password" class="form-label fw-semibold text-secondary small">Current Password</label>
                                <div class="auth-input-wrap">
                                    <i class="fas fa-lock"></i>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required placeholder="Enter current password">
                                    <button class="password-toggle auth-password-toggle" type="button" data-toggle-password="current_password" aria-label="Show password" title="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="new_password" class="form-label fw-semibold text-secondary small">New Password</label>
                                <div class="auth-input-wrap">
                                    <i class="fas fa-lock"></i>
                                    <input type="password" class="form-control" id="new_password" name="new_password" minlength="<?php echo (int) PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required placeholder="Enter new password">
                                    <button class="password-toggle auth-password-toggle" type="button" data-toggle-password="new_password" aria-label="Show password" title="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text text-muted small mt-1">
                                    Must be at least 8 characters with uppercase, lowercase, numbers, and symbols.
                                </div>
                            </div>
                            <div class="mb-4">
                                <label for="confirm_new_password" class="form-label fw-semibold text-secondary small">Confirm New Password</label>
                                <div class="auth-input-wrap">
                                    <i class="fas fa-lock"></i>
                                    <input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password" minlength="<?php echo (int) PASSWORD_MIN_LENGTH; ?>" autocomplete="new-password" required placeholder="Repeat new password">
                                    <button class="password-toggle auth-password-toggle" type="button" data-toggle-password="confirm_new_password" aria-label="Show password" title="Show password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn text-white px-4 py-2 rounded-pill fw-semibold" style="background-color: #b8860b; border: none; box-shadow: 0 4px 12px rgba(184, 134, 11, 0.25);">
                                    <i class="fas fa-key me-1"></i> Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<a href="logout.php" class="profile-mobile-inline-logout">
    <i class="fas fa-power-off" aria-hidden="true"></i>
    <span>Log Out</span>
</a>

<style>
.parish-input {
    border-radius: 10px;
    border: 1.5px solid #e2d9cc;
    padding: 9px 14px;
    font-size: 0.93rem;
    color: #2b352a;
    background-color: #ffffff;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.parish-input:focus {
    border-color: #c89b3c;
    box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.16);
    outline: none;
}
.avatar-camera-btn {
    bottom: 2px;
    right: 4px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #c89b3c;
    color: #ffffff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    cursor: pointer;
    border: 2.5px solid #ffffff;
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.18);
    transition: transform 0.18s ease, background-color 0.18s ease;
}
.avatar-camera-btn:hover {
    transform: scale(1.08);
    background: #a97f24;
    color: #ffffff;
}
.auth-input-wrap {
    position: relative;
    width: 100%;
}
.auth-input-wrap i.fa-lock {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #b8860b;
    font-size: 15px;
    pointer-events: none;
    z-index: 2;
}
.auth-input-wrap .form-control {
    width: 100%;
    min-height: 48px;
    padding: 10px 48px 10px 44px;
    border-radius: 12px;
    border: 1.5px solid #e2d9cc;
    background: #ffffff;
    font-size: 0.95rem;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    box-sizing: border-box;
}
.auth-input-wrap .form-control:focus {
    border-color: #c89b3c;
    box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.18);
    outline: none;
}
.auth-password-toggle,
.password-toggle {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    border-radius: 8px;
    color: #77736b;
    cursor: pointer;
    z-index: 6;
    transition: color 0.18s ease, background 0.18s ease;
}
.auth-password-toggle:hover,
.password-toggle:hover {
    color: #a97f24;
    background: rgba(200, 155, 60, 0.12);
}
.auth-password-toggle:focus-visible,
.password-toggle:focus-visible {
    outline: 2px solid #c89b3c;
    outline-offset: 2px;
}
.auth-password-toggle i,
.password-toggle i {
    font-size: 15px;
    pointer-events: none;
}
input[type="password"]::-ms-reveal,
input[type="password"]::-ms-clear {
    display: none !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Password visibility toggle
    document.querySelectorAll('[data-toggle-password]').forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const targetId = toggle.dataset.togglePassword || toggle.getAttribute('data-toggle-password');
            const target = document.getElementById(targetId);
            if (!target) return;
            const icon = toggle.querySelector('i');
            const isPassword = target.type === 'password';
            target.type = isPassword ? 'text' : 'password';
            if (icon) {
                icon.classList.remove(isPassword ? 'fa-eye' : 'fa-eye-slash');
                icon.classList.add(isPassword ? 'fa-eye-slash' : 'fa-eye');
            }
            const newLabel = isPassword ? 'Hide password' : 'Show password';
            toggle.setAttribute('aria-label', newLabel);
            toggle.setAttribute('title', newLabel);
        });
    });

    // Profile Photo File Selection and Live Preview
    const photoInput = document.getElementById('profile_photo');
    const avatarImg = document.getElementById('avatarPreviewImg');
    const fallbackInitials = document.getElementById('avatarFallbackInitials');
    const selectedAlert = document.getElementById('avatarSelectedAlert');
    const fileNameSpan = document.getElementById('avatarFileName');

    if (photoInput) {
        photoInput.addEventListener('change', function() {
            const file = this.files && this.files[0];
            if (!file) return;

            // Client side size validation: 5MB
            if (file.size > 5 * 1024 * 1024) {
                alert('File size exceeds the 5MB maximum limit. Please choose a smaller image.');
                this.value = '';
                if (selectedAlert) selectedAlert.classList.add('d-none');
                return;
            }

            // Client side MIME validation
            const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
            if (!validTypes.includes(file.type.toLowerCase())) {
                alert('Unsupported file format. Please select a JPG, PNG, or WEBP image.');
                this.value = '';
                if (selectedAlert) selectedAlert.classList.add('d-none');
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                if (avatarImg) {
                    avatarImg.src = e.target.result;
                    avatarImg.style.display = 'block';
                }
                if (fallbackInitials) {
                    fallbackInitials.style.display = 'none';
                }
                if (selectedAlert && fileNameSpan) {
                    fileNameSpan.textContent = file.name;
                    selectedAlert.classList.remove('d-none');
                }
            };
            reader.readAsDataURL(file);
        });
    }

    // Auto dismiss success alert after 6 seconds
    const successAlert = document.getElementById('profileSuccessAlert');
    if (successAlert) {
        setTimeout(function() {
            try {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(successAlert);
                if (bsAlert) bsAlert.close();
            } catch (e) {
                successAlert.style.display = 'none';
            }
        }, 6000);
    }
});
</script>

<?php include '../templates/footer.php'; ?>
