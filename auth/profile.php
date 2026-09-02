<?php
/**
 * User Profile Module - Handles profile details, preferences, and account settings.
 */
// Include centralized session management
include '../includes/session.php';
include '../config/security.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();
ensureEmailNotificationSchema($conn);

$user_id = $_SESSION['user_id'];
$user = getUserById($conn, $user_id);
$is_admin = isAdmin();
$error = '';
$success = '';

// Handle profile update
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') == 'POST') {
    requireValidCsrfToken();
    $fullname = trim($_POST['fullname'] ?? '');

    if ($is_admin) {
        if ($fullname === '') {
            $error = 'Full name is required.';
        } else {
            $stmt = $conn->prepare("UPDATE users SET fullname = ? WHERE id = ?");
            if (!$stmt) {
                $error = 'Unable to update profile.';
            } else {
                $stmt->bind_param('si', $fullname, $user_id);
            }
        }
    } else {
        $phone_number = normalizePhilippineMobileForStorage($_POST['phone_number'] ?? '');
        $chapel_district = trim($_POST['chapel_district'] ?? '');

        if ($fullname === '' || !isValidPhilippineMobile($phone_number)) {
            $error = 'Full name and phone number are required.';
        } elseif (!authenticationIdentifierAvailable($conn, 'mobile', $phone_number, (int) $user_id)) {
            $error = 'That mobile number is not available for this account.';
        } else {
            $stmt = $conn->prepare("UPDATE users SET fullname = ?, phone_verified_at = CASE WHEN phone_number = ? THEN phone_verified_at ELSE NULL END, phone_number = ?, chapel_district = ? WHERE id = ?");
            if (!$stmt) {
                $error = 'Unable to update profile.';
            } else {
                $stmt->bind_param('ssssi', $fullname, $phone_number, $phone_number, $chapel_district, $user_id);
            }
        }
    }

    if (!$error && isset($stmt) && $stmt) {
        if ($stmt->execute()) {
            $_SESSION['fullname'] = $fullname;
            if (!$is_admin && isset($phone_number)) {
                $phoneVerifiedAt = normalizePhilippineMobileForStorage($user['phone_number'] ?? '') === $phone_number
                    ? ($user['phone_verified_at'] ?? null)
                    : null;
                synchronizeAuthenticationIdentifier($conn, (int) $user_id, 'mobile', $phone_number, $phoneVerifiedAt);
            }
            $success = 'Profile updated successfully!';
            $user = getUserById($conn, $user_id);
        } else {
            $error = 'Error updating profile: ' . $conn->error;
        }
    }

    if (isset($stmt) && $stmt) {
        $stmt->close();
    }
}

$completed_request_count = 0;
$completed_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM requests WHERE user_id = ? AND status = 'completed'");
if ($completed_stmt) {
    $completed_stmt->bind_param('i', $user_id);
    $completed_stmt->execute();
    $completed_result = $completed_stmt->get_result();
    $completed_request_count = intval($completed_result->fetch_assoc()['total'] ?? 0);
    $completed_stmt->close();
}

$profile_display_name = trim((string) ($user['fullname'] ?? ($is_admin ? 'Administrator' : 'Parishioner')));
$profile_first_name_parts = preg_split('/\s+/', $profile_display_name);
$profile_first_name = $profile_first_name_parts[0] ?? ($is_admin ? 'Admin' : 'Parishioner');
$profile_initial = strtoupper(substr($profile_first_name, 0, 1));
$profile_district = trim((string) ($user['chapel_district'] ?? ''));
$profile_member_since = !empty($user['created_at']) ? date('M Y', strtotime($user['created_at'])) : 'N/A';

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
        <span class="profile-mobile-avatar"><?php echo e($profile_initial); ?></span>
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

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card profile-details-card" id="profileDetails">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas <?php echo $is_admin ? 'fa-user-shield' : 'fa-user'; ?>"></i> <?php echo $is_admin ? 'Profile Settings' : 'My Profile'; ?></h5>
                </div>
                <div class="card-body">
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <?php echo e($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <?php echo e($success); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="profile">
                        <div class="mb-3">
                            <label for="fullname" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="fullname" name="fullname" 
                                   value="<?php echo e($user['fullname']); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label"><i class="fas fa-lock profile-email-lock" aria-hidden="true"></i> Email Address (Cannot be changed)</label>
                            <input type="email" class="form-control" id="email" value="<?php echo e($user['email']); ?>" disabled>
                            <div class="form-text profile-verification-status">
                                <span class="profile-verification-prefix">Gmail verification:</span>
                                <?php if (!empty($user['email_verified_at'])): ?>
                                    <span class="badge bg-success profile-verification-pill"><i class="fas fa-check" aria-hidden="true"></i> Verified <?php echo e(formatDateTime($user['email_verified_at'])); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark profile-verification-pill">Not verified</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!$is_admin): ?>
                        <div class="mb-3">
                            <label for="phone_number" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="phone_number" name="phone_number" 
                                   value="<?php echo e($user['phone_number'] ?? ''); ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="chapel_district" class="form-label">Chapel/District</label>
                            <input type="text" class="form-control" id="chapel_district" name="chapel_district" 
                                   value="<?php echo e($user['chapel_district'] ?? ''); ?>">
                        </div>

                        <div class="mb-3 profile-member-since-field">
                            <label class="form-label">Member Since</label>
                            <input type="text" class="form-control" value="<?php echo formatDate($user['created_at']); ?>" disabled>
                        </div>
                        <?php endif; ?>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="<?php echo $is_admin ? '../admin/dashboard.php' : '../users/index.php'; ?>" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>

                    <hr class="my-4">

                    <!-- Change Password Section -->
                    <div class="profile-change-password-section">
                        <h6><i class="fas fa-key"></i> Change Password</h6>
                        <form action="change-password.php" method="POST">
                            <?php echo csrfInput(); ?>
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="current_password" name="current_password">
                            </div>
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password">
                            </div>
                            <div class="mb-3">
                                <label for="confirm_new_password" class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password">
                            </div>
                            <button type="submit" class="btn btn-warning">Change Password</button>
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

<?php include '../templates/footer.php'; ?>
